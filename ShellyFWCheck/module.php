<?php

declare(strict_types=1);

/*
 * ShellyFWCheck – Firmware-Übersicht und Updates für Shelly-Geräte (Gen2 und neuer)
 * Autor: Peter Chrisben · Lizenz: CC BY-NC-SA 4.0
 *
 * Alle Abfragen laufen lokal über die HTTP-Schnittstelle der Geräte (/shelly und /rpc).
 * Passwortgeschützte Geräte werden per Digest-Anmeldung (SHA-256, Benutzer „admin“) angesprochen.
 */
class ShellyFWCheck extends IPSModule
{
    private const DNSSD_GUID = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';
    private const VERIFY_SECONDS = 60;      // Abstand der Kontrollabfragen nach einem Update
    private const VERIFY_MAX = 900;         // so lange (Sekunden) wird auf die neue Version gewartet

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('MasterPassword', '');
        $this->RegisterPropertyString('Devices', '[]');
        $this->RegisterPropertyInteger('CheckInterval', 24);
        $this->RegisterPropertyInteger('UpdateGap', 20);
        $this->RegisterPropertyBoolean('ShowTable', true);

        $this->RegisterAttributeString('State', '{}');
        $this->RegisterAttributeInteger('LastCheck', 0);

        $this->RegisterTimer('Check', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TimerCheck\', \'\');');
        $this->RegisterTimer('Queue', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TimerQueue\', \'\');');
        $this->RegisterTimer('Verify', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TimerVerify\', \'\');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $plain = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $this->RegisterVariableInteger('UpdatesAvailable', 'Updates verfügbar', $plain, 10);
        $this->RegisterVariableInteger('DevicesTotal', 'Geräte', $plain, 20);
        $this->RegisterVariableInteger('DevicesOffline', 'Nicht erreichbar', $plain, 30);
        $this->RegisterVariableString('UpdateNames', 'Geräte mit Update', $plain, 40);
        $this->RegisterVariableString('LastCheck', 'Letzte Prüfung', $plain, 50);
        $this->MaintainVariable('Table', 'Übersicht', VARIABLETYPE_STRING, '~HTMLBox', 60, $this->ReadPropertyBoolean('ShowTable'));

        // Merker von Geräten entfernen, die nicht mehr in der Liste stehen
        $state = $this->ReadState();
        $keys = [];
        foreach ($this->DeviceRows() as $row) {
            $keys[$this->HostKey($row['Host'])] = true;
        }
        $pruned = array_intersect_key($state, $keys);
        if (count($pruned) !== count($state)) {
            $this->WriteState($pruned);
        }
        $this->SetBuffer('Queue', '[]');
        $this->SetBuffer('Selected', '[]');
        $this->SetTimerInterval('Queue', 0);

        $active = $this->ActiveRows();
        if (count($active) === 0) {
            $this->SetTimerInterval('Check', 0);
            $this->SetTimerInterval('Verify', 0);
            $this->PublishValues();
            $this->SetStatusIfChanged(104);
            return;
        }
        $hours = max(0, $this->ReadPropertyInteger('CheckInterval'));
        $this->SetTimerInterval('Check', $hours * 3600 * 1000);
        $this->SetTimerInterval('Verify', 3000);   // gleich einmal abfragen (läuft außerhalb von ApplyChanges)
        $this->PublishValues();
        $this->SetStatusIfChanged(102);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'TimerCheck':
            case 'CheckAll':
                $this->CheckAll();
                break;
            case 'TimerQueue':
                $this->WorkQueue();
                break;
            case 'TimerVerify':
                $this->CheckAll();
                $this->SetTimerInterval('Verify', $this->UpdatesRunning() ? self::VERIFY_SECONDS * 1000 : 0);
                break;
            case 'UpdateAll':
                $this->UpdateAll();
                break;
            case 'Select':
                // Haken in der Übersicht merken, damit sie eine Auffrischung der Liste überstehen
                $hosts = json_decode((string) $Value, true);
                $this->SetBuffer('Selected', json_encode(is_array($hosts) ? array_values(array_map([$this, 'HostKey'], array_map('strval', $hosts))) : []));
                break;
            case 'UpdateDevice':
                $this->UpdateDevice((string) $Value);
                break;
            case 'Discover':
                $this->Discover();
                break;
            default:
                throw new Exception('Ungültiger Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $rows = $this->DeviceRows();
        $noSetup = count($rows) === 0 && $this->ReadPropertyString('MasterPassword') === '';
        $fill = [
            'Overview'      => ['caption' => $this->OverviewText()],
            'Devices'       => ['values' => $this->ConfigRows($rows)],
            'Status'        => ['values' => $this->StatusRows($rows)],
            'PanelDevices'  => ['caption' => 'Geräte verwalten (' . count($rows) . ')', 'expanded' => count($rows) === 0],
            'PanelPassword' => ['expanded' => $noSetup,
                'caption' => 'Passwort' . ($this->ReadPropertyString('MasterPassword') !== '' ? ' (Master-Passwort hinterlegt)' : '')]
        ];
        $this->FillForm($form['elements'], $fill);
        $this->FillForm($form['actions'], $fill);
        return json_encode($form);
    }

    // ------------------------------------------------------------ öffentlich

    /** Alle aktiven Geräte abfragen: installierte Version und verfügbares Update. */
    public function CheckAll(): bool
    {
        $key = 'ShellyFWCheck' . $this->InstanceID . '_Check';
        if (!IPS_SemaphoreEnter($key, 100)) {
            return false;   // läuft schon
        }
        try {
            $rows = $this->ActiveRows();
            $state = $this->ReadState();
            $now = time();

            // 1. Schritt: /shelly – geht ohne Anmeldung und liefert Name, Modell, Version
            $requests = [];
            foreach ($rows as $row) {
                $requests[$this->HostKey($row['Host'])] = ['url' => 'http://' . $row['Host'] . '/shelly', 'timeout' => 5];
            }
            $infos = $this->HttpBatch($requests);

            $calls = [];
            foreach ($rows as $row) {
                $k = $this->HostKey($row['Host']);
                $e = $state[$k] ?? $this->EmptyEntry();
                $info = $infos[$k] ?? ['code' => 0, 'body' => ''];
                $data = $info['code'] === 200 ? $this->DecodeJson($info['body']) : null;
                $e['checked'] = $now;
                if (!is_array($data)) {
                    $e['online'] = false;
                    $e['err'] = 'offline';
                    $state[$k] = $e;
                    continue;
                }
                $e['online'] = true;
                $e['seen'] = $now;
                $e['err'] = '';
                $e['msg'] = '';
                if ((int) ($data['gen'] ?? 1) < 2) {
                    $e['name'] = (string) ($data['type'] ?? 'Shelly Gen1');
                    $e['ver'] = (string) ($data['fw'] ?? '');
                    $e['gen'] = 1;
                    $e['err'] = 'gen1';
                    $state[$k] = $e;
                    continue;
                }
                $e['id'] = (string) ($data['id'] ?? '');
                $name = trim((string) ($data['name'] ?? ''));
                $e['name'] = $name !== '' ? $name : $e['id'];
                $e['model'] = (string) ($data['app'] ?? ($data['model'] ?? ''));
                $e['gen'] = (int) $data['gen'];
                $e['ver'] = (string) ($data['ver'] ?? '');
                $e['auth'] = (bool) ($data['auth_en'] ?? false);

                if ($e['upd_start'] > 0) {
                    // Update wurde gestartet: nur auf die neue Version warten
                    if ($e['ver'] !== $e['upd_from']) {
                        $this->LogMessage($e['name'] . ': Firmware aktualisiert von ' . $e['upd_from'] . ' auf ' . $e['ver'], KL_NOTIFY);
                        $e['upd_start'] = 0;
                        $e['avail'] = '';
                        $e['note'] = 'Aktualisiert (vorher ' . $e['upd_from'] . ')';
                    } elseif ($now - $e['upd_start'] > self::VERIFY_MAX) {
                        $this->LogMessage($e['name'] . ': Update nicht bestätigt – Version ist weiter ' . $e['ver'], KL_WARNING);
                        $e['upd_start'] = 0;
                        $e['note'] = 'Update nicht bestätigt – Version unverändert';
                    }
                    $state[$k] = $e;
                    continue;
                }
                $state[$k] = $e;
                $calls[$k] = ['host' => $row['Host'], 'method' => 'Shelly.CheckForUpdate', 'params' => [],
                    'timeout' => 20, 'password' => $this->PasswordFor($row)];
            }

            // Update läuft und Gerät startet gerade neu → nicht als Fehler zeigen
            foreach ($state as $k => $e) {
                if (($e['upd_start'] ?? 0) > 0 && !$e['online'] && $now - $e['upd_start'] > self::VERIFY_MAX) {
                    $state[$k]['upd_start'] = 0;
                    $state[$k]['note'] = 'Update nicht bestätigt – Gerät nicht erreichbar';
                }
            }

            // 2. Schritt: nach Updates fragen (das Gerät fragt dafür selbst beim Shelly-Server nach)
            foreach ($this->RpcBatch($calls) as $k => $res) {
                $e = $state[$k];
                if (!$res['ok']) {
                    $e['err'] = $res['err'];
                    $e['msg'] = $res['msg'];
                } else {
                    $avail = (string) ($res['result']['stable']['version'] ?? '');
                    if ($avail === $e['ver']) {
                        $avail = '';
                    }
                    if ($avail !== '' && $e['avail'] !== $avail) {
                        $this->LogMessage($e['name'] . ': Firmware ' . $avail . ' verfügbar (installiert ' . $e['ver'] . ')', KL_NOTIFY);
                        $e['note'] = '';
                    }
                    $e['avail'] = $avail;
                }
                $state[$k] = $e;
            }

            $this->WriteState($state);
            $this->WriteAttributeInteger('LastCheck', $now);
            $this->PublishValues();
            $this->RefreshForm();
            return true;
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }

    /** Update für ein Gerät starten (Host = IP-Adresse wie in der Liste). */
    public function UpdateDevice(string $Host): bool
    {
        $Host = $this->CleanHost($Host);
        if ($Host === '') {
            $this->ShowResult('Kein Gerät angegeben.');
            return false;
        }
        $ok = $this->StartUpdate($Host, $message);
        $this->ShowResult($message);
        $this->RefreshForm();
        return $ok;
    }

    /** Alle verfügbaren Updates nacheinander starten. Rückgabe: Anzahl der Geräte. */
    public function UpdateAll(): int
    {
        return $this->QueueUpdates(null);
    }

    /** Updates für ausgewählte Geräte starten. Hosts = JSON-Liste der IP-Adressen, z. B. '["192.168.1.50","192.168.1.51"]'. */
    public function UpdateSelected(string $Hosts): int
    {
        $list = json_decode($Hosts, true);
        if (!is_array($list) || count($list) === 0) {
            $this->ShowResult('Nichts ausgewählt – in der Übersicht in der Spalte „Auswahl“ Haken setzen.');
            return 0;
        }
        return $this->QueueUpdates(array_map([$this, 'HostKey'], array_map('strval', $list)));
    }

    /** @param string[]|null $only Host-Schlüssel, null = alle */
    private function QueueUpdates(?array $only): int
    {
        $state = $this->ReadState();
        $queue = [];
        foreach ($this->ActiveRows() as $row) {
            $k = $this->HostKey($row['Host']);
            if ($only !== null && !in_array($k, $only, true)) {
                continue;
            }
            if ($this->CanUpdate($state[$k] ?? null)) {
                $queue[] = $row['Host'];
            }
        }
        if (count($queue) === 0) {
            $this->ShowResult($only === null
                ? 'Kein Gerät mit verfügbarem Update – ggf. erst „Jetzt prüfen“.'
                : 'Für die Auswahl ist kein Update verfügbar (oder es läuft schon).');
            return 0;
        }
        $count = count($queue);
        $gap = max(5, $this->ReadPropertyInteger('UpdateGap'));
        $this->SetBuffer('Selected', '[]');
        $this->SetBuffer('Queue', json_encode($queue));
        $this->WorkQueue();
        $this->ShowResult($count === 1
            ? '1 Update gestartet. Der Status in der Übersicht zeigt den Fortschritt.'
            : $count . ' Updates eingeplant – ein Gerät alle ' . $gap . ' Sekunden. Der Status in der Übersicht zeigt den Fortschritt.');
        return $count;
    }

    private function CanUpdate(?array $e): bool
    {
        return $e !== null && $e['online'] && $e['err'] === '' && $e['avail'] !== '' && $e['upd_start'] === 0;
    }

    /** Shellys per mDNS suchen und in die Liste eintragen (wirksam erst nach „Änderungen übernehmen“). */
    public function Discover(): int
    {
        $this->ShowResult('Suche läuft …');
        $found = $this->QueryMdns();
        if ($found === null) {
            $this->ShowResult('Die Suche braucht die Symcon-Instanz „DNS-SD Control“ – sie wurde nicht gefunden. Geräte bitte von Hand eintragen.');
            return 0;
        }
        $rows = $this->DeviceRows();
        $state = $this->ReadState();
        $byHost = [];
        $byId = [];
        foreach ($rows as $i => $row) {
            $k = $this->HostKey($row['Host']);
            $byHost[$k] = $i;
            $id = strtolower((string) ($state[$k]['id'] ?? ''));
            if ($id !== '') {
                $byId[$id] = $i;
            }
        }
        $list = $this->ConfigRows($rows);
        $new = 0;
        $moved = 0;
        foreach ($found as $name => $ip) {
            $k = $this->HostKey($ip);
            if (isset($byHost[$k])) {
                continue;
            }
            $id = strtolower($name);
            if (isset($byId[$id])) {
                // bekanntes Gerät mit neuer IP-Adresse
                $list[$byId[$id]]['Host'] = $ip;
                $list[$byId[$id]]['rowColor'] = '#C0C0FF';
                $moved++;
                continue;
            }
            $byHost[$k] = count($list);
            $list[] = ['Active' => true, 'Name' => $name, 'Host' => $ip, 'OwnPassword' => false, 'Password' => '', 'rowColor' => '#C0C0FF'];
            $new++;
        }
        if ($new + $moved > 0) {
            $this->UpdateFormField('Devices', 'values', json_encode($list));
            $this->UpdateFormField('PanelDevices', 'expanded', true);
            $text = count($found) . ' Shelly(s) im Netzwerk gefunden: ' . $new . ' neu' . ($moved > 0 ? ', ' . $moved . ' mit neuer IP-Adresse' : '')
                . ' – oben unter „Geräte verwalten“ blau markiert. Jetzt „Änderungen übernehmen“ drücken.';
        } else {
            $text = count($found) . ' Shelly(s) im Netzwerk gefunden – alle stehen schon in der Liste.';
        }
        $this->ShowResult($text);
        return $new;
    }

    /** Stand aller Geräte als JSON (für eigene Skripte, z. B. Benachrichtigungen). */
    public function GetDevices(): string
    {
        $state = $this->ReadState();
        $out = [];
        foreach ($this->DeviceRows() as $row) {
            $e = $state[$this->HostKey($row['Host'])] ?? $this->EmptyEntry();
            $out[] = [
                'host'      => $row['Host'],
                'active'    => $row['Active'],
                'name'      => $e['name'],
                'id'        => $e['id'],
                'model'     => $e['model'],
                'gen'       => $e['gen'],
                'online'    => $e['online'],
                'installed' => $e['ver'],
                'available' => $e['avail'],
                'updating'  => $e['upd_start'] > 0,
                'status'    => $this->StateText($e)
            ];
        }
        return json_encode($out);
    }

    // ---------------------------------------------------------------- Updates

    private function StartUpdate(string $host, ?string &$message): bool
    {
        $k = $this->HostKey($host);
        $row = null;
        foreach ($this->DeviceRows() as $r) {
            if ($this->HostKey($r['Host']) === $k) {
                $row = $r;
                break;
            }
        }
        $state = $this->ReadState();
        $e = $state[$k] ?? null;
        if ($row === null || $e === null) {
            $message = $host . ': Gerät ist nicht gespeichert oder wurde noch nicht geprüft.';
            return false;
        }
        if ($e['upd_start'] > 0) {
            $message = $e['name'] . ': Update läuft bereits.';
            return false;
        }
        if ($e['avail'] === '') {
            $message = $e['name'] . ': kein Update bekannt – ggf. erst „Jetzt prüfen“.';
            return false;
        }
        $res = $this->RpcBatch([$k => ['host' => $row['Host'], 'method' => 'Shelly.Update', 'params' => ['stage' => 'stable'],
            'timeout' => 15, 'password' => $this->PasswordFor($row)]])[$k];
        if (!$res['ok']) {
            $e['err'] = $res['err'];
            $e['msg'] = $res['msg'];
            $state[$k] = $e;
            $this->WriteState($state);
            $message = $e['name'] . ': Update nicht gestartet – ' . $this->StateText($e);
            $this->LogMessage($message, KL_WARNING);
            return false;
        }
        $e['upd_start'] = time();
        $e['upd_from'] = $e['ver'];
        $e['upd_target'] = $e['avail'];
        $e['note'] = '';
        $state[$k] = $e;
        $this->WriteState($state);
        $this->SetTimerInterval('Verify', self::VERIFY_SECONDS * 1000);
        $message = $e['name'] . ': Update auf ' . $e['avail'] . ' gestartet. Das Gerät startet gleich neu.';
        $this->LogMessage($message, KL_NOTIFY);
        return true;
    }

    private function WorkQueue(): void
    {
        $queue = json_decode($this->GetBuffer('Queue'), true);
        if (!is_array($queue) || count($queue) === 0) {
            $this->SetTimerInterval('Queue', 0);
            return;
        }
        $host = (string) array_shift($queue);
        $this->SetBuffer('Queue', json_encode($queue));
        $this->SetTimerInterval('Queue', count($queue) > 0 ? max(5, $this->ReadPropertyInteger('UpdateGap')) * 1000 : 0);
        $this->StartUpdate($host, $message);
        $this->SendDebug('Update', (string) $message, 0);
        $this->RefreshForm();
    }

    private function UpdatesRunning(): bool
    {
        $queue = json_decode($this->GetBuffer('Queue'), true);
        if (is_array($queue) && count($queue) > 0) {
            return true;
        }
        foreach ($this->ReadState() as $e) {
            if ($e['upd_start'] > 0) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------- mDNS

    /** @return array<string,string>|null Name => IPv4, null = DNS-SD nicht vorhanden */
    protected function QueryMdns(): ?array
    {
        $ids = IPS_GetInstanceListByModuleID(self::DNSSD_GUID);
        if (count($ids) === 0 || !function_exists('ZC_QueryServiceType')) {
            return null;
        }
        $dns = $ids[0];
        $found = [];
        foreach (['_shelly._tcp' => false, '_http._tcp' => true] as $type => $filter) {
            $services = @ZC_QueryServiceType($dns, $type, '');
            if (!is_array($services)) {
                continue;
            }
            foreach ($services as $service) {
                $name = (string) ($service['Name'] ?? '');
                if ($name === '' || ($filter && stripos($name, 'shelly') !== 0) || isset($found[$name])) {
                    continue;
                }
                $details = @ZC_QueryService($dns, $name, (string) ($service['Type'] ?? $type), (string) ($service['Domain'] ?? 'local.'));
                $ip = (string) ($details[0]['IPv4'][0] ?? '');
                if ($ip !== '') {
                    $found[$name] = $ip;
                }
            }
            if (count($found) > 0) {
                break;  // _http._tcp nur als Ersatz, wenn _shelly._tcp nichts liefert
            }
        }
        $this->SendDebug('mDNS', json_encode($found), 0);
        ksort($found);
        return $found;
    }

    // ------------------------------------------------------------------- HTTP

    /**
     * Mehrere RPC-Aufrufe gleichzeitig. Verlangt ein Gerät eine Anmeldung (401), wird der Aufruf
     * mit Digest-Anmeldung (SHA-256) wiederholt.
     * @return array<string,array{ok:bool,result:mixed,err:string,msg:string}>
     */
    private function RpcBatch(array $calls): array
    {
        $out = [];
        if (count($calls) === 0) {
            return $out;
        }
        $requests = [];
        foreach ($calls as $k => $call) {
            $requests[$k] = $this->RpcRequest($call, '');
        }
        $retry = [];
        foreach ($this->HttpBatch($requests) as $k => $res) {
            if ($res['code'] === 401) {
                $password = (string) $calls[$k]['password'];
                $header = $password !== '' ? $this->DigestHeader($res['auth'], $password) : '';
                if ($password === '') {
                    $out[$k] = ['ok' => false, 'result' => null, 'err' => 'auth_missing', 'msg' => ''];
                } elseif ($header === '') {
                    $out[$k] = ['ok' => false, 'result' => null, 'err' => 'rpc', 'msg' => 'Anmeldung: unbekanntes Verfahren'];
                } else {
                    $retry[$k] = $this->RpcRequest($calls[$k], $header);
                }
                continue;
            }
            $out[$k] = $this->RpcResult($res);
        }
        if (count($retry) > 0) {
            foreach ($this->HttpBatch($retry) as $k => $res) {
                $out[$k] = $res['code'] === 401
                    ? ['ok' => false, 'result' => null, 'err' => 'auth_wrong', 'msg' => '']
                    : $this->RpcResult($res);
            }
        }
        return $out;
    }

    private function RpcRequest(array $call, string $authHeader): array
    {
        $frame = ['id' => 1, 'method' => $call['method']];
        if (count($call['params']) > 0) {
            $frame['params'] = $call['params'];
        }
        return [
            'url'     => 'http://' . $call['host'] . '/rpc',
            'post'    => json_encode($frame),
            'headers' => $authHeader !== '' ? ['Authorization: ' . $authHeader] : [],
            'timeout' => $call['timeout']
        ];
    }

    private function RpcResult(array $res): array
    {
        if ($res['code'] === 0) {
            return ['ok' => false, 'result' => null, 'err' => 'offline', 'msg' => ''];
        }
        $data = $this->DecodeJson($res['body']);
        if (!is_array($data)) {
            return ['ok' => false, 'result' => null, 'err' => 'rpc', 'msg' => 'HTTP ' . $res['code']];
        }
        if (isset($data['error'])) {
            $msg = is_array($data['error']) ? (string) ($data['error']['message'] ?? json_encode($data['error'])) : (string) $data['error'];
            return ['ok' => false, 'result' => null, 'err' => 'rpc', 'msg' => $msg];
        }
        return ['ok' => true, 'result' => $data['result'] ?? null, 'err' => '', 'msg' => ''];
    }

    /** Authorization-Kopfzeile nach RFC 7616 (Digest, SHA-256, qop=auth) aus der 401-Antwort des Geräts. */
    private function DigestHeader(string $challenge, string $password): string
    {
        if (stripos($challenge, 'Digest') === false
            || !preg_match('/realm="?([^",]+)"?/i', $challenge, $realm)
            || !preg_match('/nonce="?([^",]+)"?/i', $challenge, $nonce)) {
            return '';
        }
        if (preg_match('/algorithm="?([^",\s]+)"?/i', $challenge, $algo) && strtoupper($algo[1]) !== 'SHA-256') {
            return '';
        }
        $user = 'admin';
        $nc = '00000001';
        $cnonce = bin2hex(random_bytes(8));
        $ha1 = hash('sha256', $user . ':' . $realm[1] . ':' . $password);
        $ha2 = hash('sha256', 'POST:/rpc');
        $response = hash('sha256', $ha1 . ':' . $nonce[1] . ':' . $nc . ':' . $cnonce . ':auth:' . $ha2);
        return 'Digest username="' . $user . '", realm="' . $realm[1] . '", nonce="' . $nonce[1] . '", uri="/rpc", '
            . 'cnonce="' . $cnonce . '", nc=' . $nc . ', qop=auth, response="' . $response . '", algorithm=SHA-256';
    }

    /**
     * Mehrere HTTP-Abfragen gleichzeitig (damit viele Geräte nicht nacheinander abgewartet werden).
     * @param array<string,array{url:string,post?:string,headers?:array,timeout?:int}> $requests
     * @return array<string,array{code:int,body:string,auth:string}>
     */
    protected function HttpBatch(array $requests): array
    {
        $out = [];
        if (count($requests) === 0) {
            return $out;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($requests as $k => $r) {
            $ch = curl_init($r['url']);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_NOSIGNAL       => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => (int) ($r['timeout'] ?? 5)
            ];
            if (isset($r['post'])) {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = $r['post'];
                $opts[CURLOPT_HTTPHEADER] = array_merge(['Content-Type: application/json'], $r['headers'] ?? []);
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$k] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $k => $ch) {
            $raw = (string) curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($raw, 0, $headerSize);
            $body = (string) substr($raw, $headerSize);
            $auth = preg_match('/^WWW-Authenticate:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';
            $out[(string) $k] = ['code' => $code, 'body' => $body, 'auth' => $auth];
            $what = isset($requests[$k]['post']) ? ' ' . $requests[$k]['post'] : '';
            $this->SendDebug($requests[$k]['url'] . $what, 'HTTP ' . $code . ' ' . ($code === 0 ? curl_error($ch) : $body), 0);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private function DecodeJson(string $body): ?array
    {
        $json = json_decode($body, true);
        if ($json === null && $body !== '') {
            $json = json_decode(mb_convert_encoding($body, 'UTF-8', 'Windows-1252'), true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return is_array($json) ? $json : null;
    }

    // ------------------------------------------------------- Liste und Merker

    /** Geräte aus der Einstellung, bereinigt und ohne doppelte Adressen. */
    private function DeviceRows(): array
    {
        $raw = json_decode($this->ReadPropertyString('Devices'), true);
        $rows = [];
        if (!is_array($raw)) {
            return $rows;
        }
        foreach ($raw as $r) {
            $host = $this->CleanHost((string) ($r['Host'] ?? ''));
            $k = $this->HostKey($host);
            if ($host === '' || isset($rows[$k])) {
                continue;
            }
            $rows[$k] = [
                'Active'      => (bool) ($r['Active'] ?? true),
                'Host'        => $host,
                'OwnPassword' => (bool) ($r['OwnPassword'] ?? false),
                'Password'    => (string) ($r['Password'] ?? '')
            ];
        }
        return array_values($rows);
    }

    private function ActiveRows(): array
    {
        return array_values(array_filter($this->DeviceRows(), static fn (array $r): bool => $r['Active']));
    }

    private function CleanHost(string $host): string
    {
        $host = trim($host);
        $host = (string) preg_replace('#^https?://#i', '', $host);
        return rtrim($host, '/');
    }

    private function HostKey(string $host): string
    {
        return strtolower($this->CleanHost($host));
    }

    private function PasswordFor(array $row): string
    {
        return $row['OwnPassword'] ? $row['Password'] : $this->ReadPropertyString('MasterPassword');
    }

    private function EmptyEntry(): array
    {
        return ['id' => '', 'name' => '', 'model' => '', 'gen' => 0, 'ver' => '', 'avail' => '', 'auth' => false,
            'online' => false, 'seen' => 0, 'checked' => 0, 'err' => '', 'msg' => '', 'note' => '',
            'upd_start' => 0, 'upd_from' => '', 'upd_target' => ''];
    }

    private function ReadState(): array
    {
        $state = json_decode($this->ReadAttributeString('State'), true);
        if (!is_array($state)) {
            return [];
        }
        foreach ($state as $k => $e) {
            $state[$k] = array_merge($this->EmptyEntry(), is_array($e) ? $e : []);
        }
        return $state;
    }

    private function WriteState(array $state): void
    {
        $this->WriteAttributeString('State', json_encode($state));
    }

    private function StateText(array $e): string
    {
        if ($e['upd_start'] > 0) {
            return 'Update auf ' . $e['upd_target'] . ' läuft … (gestartet ' . date('H:i', $e['upd_start']) . ')';
        }
        switch ($e['err']) {
            case 'offline':
                return 'Nicht erreichbar' . ($e['seen'] > 0 ? ' (zuletzt ' . date('d.m. H:i', $e['seen']) . ')' : '');
            case 'gen1':
                return 'Gen1-Gerät – wird nicht unterstützt';
            case 'auth_missing':
                return 'Passwort fehlt';
            case 'auth_wrong':
                return 'Passwort falsch';
            case 'rpc':
                return 'Fehler: ' . $e['msg'];
        }
        if ($e['checked'] === 0) {
            return 'Noch nicht geprüft';
        }
        if ($e['note'] !== '' && $e['avail'] === '') {
            return $e['note'];
        }
        if ($e['avail'] !== '') {
            return 'Update verfügbar' . ($e['note'] !== '' ? ' (' . $e['note'] . ')' : '');
        }
        return 'Aktuell';
    }

    /** 0 normal, 1 Update verfügbar, 2 Update läuft, 3 Fehler, 4 gerade aktualisiert */
    private function StateKind(array $e): int
    {
        if ($e['upd_start'] > 0) {
            return 2;
        }
        if ($e['err'] !== '') {
            return 3;
        }
        if ($e['avail'] !== '') {
            return 1;
        }
        return strpos($e['note'], 'Aktualisiert') === 0 ? 4 : 0;
    }

    /** Zeilen der Einstell-Liste „Geräte verwalten“: nur gespeicherte Spalten + Name (ändert sich durch Abfragen nie). */
    private function ConfigRows(array $rows): array
    {
        $state = $this->ReadState();
        $list = [];
        foreach ($rows as $row) {
            $e = $state[$this->HostKey($row['Host'])] ?? $this->EmptyEntry();
            $list[] = [
                'Active'      => $row['Active'],
                'Name'        => $e['name'],
                'Host'        => $row['Host'],
                'OwnPassword' => $row['OwnPassword'],
                'Password'    => $row['Password'],
                'rowColor'    => ''
            ];
        }
        return $list;
    }

    /** Zeilen der Übersicht (reine Anzeige, gehört nicht zu den Einstellungen). */
    private function StatusRows(array $rows): array
    {
        $state = $this->ReadState();
        $colors = [0 => '', 1 => '#FFFFC0', 2 => '#C0C0FF', 3 => '#FFC0C0', 4 => '#C0FFC0'];
        $selected = json_decode($this->GetBuffer('Selected'), true);
        $selected = is_array($selected) ? $selected : [];
        $list = [];
        foreach ($rows as $row) {
            if (!$row['Active']) {
                continue;
            }
            $k = $this->HostKey($row['Host']);
            $e = $state[$k] ?? $this->EmptyEntry();
            $can = $this->CanUpdate($e);
            $list[] = [
                'Select'    => $can && in_array($k, $selected, true),
                'editable'  => $can,   // Haken nur dort, wo ein Update verfügbar ist
                'Name'      => $e['name'],
                'Host'      => $row['Host'],
                'Model'     => $e['model'],
                'Installed' => $e['ver'],
                'Available' => $e['avail'],
                'State'     => $this->StateText($e),
                'rowColor'  => $colors[$this->StateKind($e)]
            ];
        }
        return $list;
    }

    private function Counts(): array
    {
        $state = $this->ReadState();
        $c = ['total' => 0, 'updates' => 0, 'offline' => 0, 'running' => 0, 'ok' => 0, 'names' => []];
        foreach ($this->ActiveRows() as $row) {
            $c['total']++;
            $e = $state[$this->HostKey($row['Host'])] ?? null;
            if ($e === null) {
                continue;
            }
            if ($e['upd_start'] > 0) {
                $c['running']++;
            } elseif ($e['err'] === 'offline') {
                $c['offline']++;
            } elseif ($e['err'] === '' && $e['avail'] !== '') {
                $c['updates']++;
                $c['names'][] = $e['name'];
            } elseif ($e['err'] === '' && $e['checked'] > 0) {
                $c['ok']++;
            }
        }
        sort($c['names'], SORT_NATURAL | SORT_FLAG_CASE);
        return $c;
    }

    private function OverviewText(): string
    {
        $c = $this->Counts();
        if ($c['total'] === 0) {
            return 'Noch keine Geräte – deshalb steht oben „Instanz ist inaktiv“. Unten „Geräte im Netzwerk suchen“ drücken (oder unter „Geräte verwalten“ von Hand hinzufügen), dann „Änderungen übernehmen“ – danach ist die Instanz aktiv.';
        }
        $last = $this->ReadAttributeInteger('LastCheck');
        $parts = ['● ' . $c['total'] . ' Geräte'];
        if ($last === 0) {
            return $parts[0] . '  ·  noch nicht geprüft – die erste Abfrage läuft gleich von selbst';
        }
        $problems = $c['total'] - $c['updates'] - $c['running'] - $c['offline'] - $c['ok'];
        $parts[] = $c['updates'] > 0 ? $c['updates'] . ' mit Update' : ($c['ok'] === $c['total'] ? 'alle aktuell' : 'kein Update');
        if ($problems > 0) {
            $parts[] = $problems . ' mit Fehler';
        }
        if ($c['running'] > 0) {
            $parts[] = $c['running'] . ' Update(s) laufen';
        }
        if ($c['offline'] > 0) {
            $parts[] = $c['offline'] . ' nicht erreichbar';
        }
        $parts[] = 'letzte Prüfung ' . date('d.m. H:i', $last);
        return implode('  ·  ', $parts);
    }

    // ---------------------------------------------------------------- Ausgabe

    private function PublishValues(): void
    {
        $c = $this->Counts();
        $last = $this->ReadAttributeInteger('LastCheck');
        $this->SetValueIfChanged('UpdatesAvailable', $c['updates']);
        $this->SetValueIfChanged('DevicesTotal', $c['total']);
        $this->SetValueIfChanged('DevicesOffline', $c['offline']);
        $this->SetValueIfChanged('UpdateNames', implode(', ', $c['names']));
        $this->SetValueIfChanged('LastCheck', $last > 0 ? date('d.m.Y H:i', $last) : '');
        if (@$this->GetIDForIdent('Table')) {
            $this->SetValueIfChanged('Table', $this->BuildTable());
        }
    }

    private function BuildTable(): string
    {
        $state = $this->ReadState();
        $items = [];
        foreach ($this->ActiveRows() as $row) {
            $e = $state[$this->HostKey($row['Host'])] ?? $this->EmptyEntry();
            $items[] = [$this->StateKind($e), $e['name'] !== '' ? $e['name'] : $row['Host'], $e];
        }
        // Reihenfolge: Update läuft, Update verfügbar, Fehler, Rest – jeweils nach Name
        $rank = [2 => 0, 1 => 1, 3 => 2, 4 => 3, 0 => 4];
        usort($items, static fn (array $a, array $b): int => [$rank[$a[0]], strtolower($a[1])] <=> [$rank[$b[0]], strtolower($b[1])]);
        $colors = [0 => '', 1 => '#E0A800', 2 => '#4A90D9', 3 => '#D9534F', 4 => '#3C9A3C'];
        $cell = 'padding:4px 10px;text-align:left;border-bottom:1px solid rgba(128,128,128,0.3);';
        $html = '<table style="width:100%;border-collapse:collapse;font-size:0.9em;"><tr>';
        foreach (['Gerät', 'Modell', 'Installiert', 'Verfügbar', 'Status'] as $head) {
            $html .= '<th style="' . $cell . '">' . $head . '</th>';
        }
        $html .= '</tr>';
        foreach ($items as [$kind, $name, $e]) {
            $style = $colors[$kind] !== '' ? 'color:' . $colors[$kind] . ';font-weight:bold;' : '';
            $html .= '<tr>';
            foreach ([$name, $e['model'], $e['ver'], $e['avail'] !== '' ? $e['avail'] : '–'] as $text) {
                $html .= '<td style="' . $cell . '">' . htmlspecialchars((string) $text) . '</td>';
            }
            $html .= '<td style="' . $cell . $style . '">' . htmlspecialchars($this->StateText($e)) . '</td></tr>';
        }
        return $html . '</table>';
    }

    private function RefreshForm(): void
    {
        $this->UpdateFormField('Overview', 'caption', $this->OverviewText());
        // Nur die Übersicht auffrischen – die Einstell-Liste „Devices“ bleibt unberührt,
        // sonst meldet Symcon nach jeder Abfrage „ungespeicherte Änderungen“.
        $this->UpdateFormField('Status', 'values', json_encode($this->StatusRows($this->DeviceRows())));
    }

    private function ShowResult(string $text): void
    {
        $this->UpdateFormField('Result', 'caption', date('H:i:s') . '  ' . $text);
    }

    private function FillForm(array &$items, array $fill): void
    {
        foreach ($items as &$item) {
            if (isset($item['name'], $fill[$item['name']])) {
                foreach ($fill[$item['name']] as $key => $value) {
                    $item[$key] = $value;
                }
            }
            if (isset($item['items']) && is_array($item['items'])) {
                $this->FillForm($item['items'], $fill);
            }
        }
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if (!$id) {
            return;
        }
        if ($this->GetValue($ident) !== $value || IPS_GetVariable($id)['VariableUpdated'] === 0) {
            $this->SetValue($ident, $value);
        }
    }

    private function SetStatusIfChanged(int $status): void
    {
        if ($this->GetStatus() !== $status) {
            $this->SetStatus($status);
        }
    }
}
