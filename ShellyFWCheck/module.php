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
    private const MAX_STEPS = 4;            // höchstens so viele Anstöße je Update (mehrstufige Updates)
    private const MAX_CHAIN = 5;            // höchstens so viele Folge-Updates hintereinander
    private const SCAN_MAX = 1024;          // höchstens so viele Adressen je Suchbereich
    private const HISTORY_MAX = 50;         // so viele Einträge behält der Update-Verlauf
    private const BATTERY_VERIFY_MAX = 86400; // Batteriegeräte: so lange wird auf die Bestätigung beim Aufwachen gewartet

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('MasterPassword', '');
        $this->RegisterPropertyString('Devices', '[]');
        $this->RegisterPropertyInteger('CheckInterval', 24);
        $this->RegisterPropertyInteger('UpdateGap', 20);
        $this->RegisterPropertyBoolean('ShowTable', true);
        $this->RegisterPropertyBoolean('ContinueUpdates', true);
        $this->RegisterPropertyInteger('NotifyVisu', 0);
        $this->RegisterPropertyString('ScanRanges', '[]');
        $this->RegisterPropertyBoolean('VisuCommands', false);
        $this->RegisterAttributeString('History', '[]');

        $this->RegisterAttributeString('State', '{}');
        $this->RegisterAttributeInteger('LastCheck', 0);

        $this->RegisterTimer('Check', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TimerCheck\', \'\');');
        $this->RegisterTimer('Queue', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TimerQueue\', \'\');');
        $this->RegisterTimer('Verify', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TimerVerify\', \'\');');
        $this->RegisterTimer('Wake', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'TimerWake\', \'\');');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $plain = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $this->RegisterVariableInteger('UpdatesAvailable', $this->Translate('Updates verfügbar'), $plain, 10);
        $this->RegisterVariableInteger('DevicesTotal', $this->Translate('Geräte'), $plain, 20);
        $this->RegisterVariableInteger('DevicesOffline', $this->Translate('Nicht erreichbar'), $plain, 30);
        $this->RegisterVariableString('UpdateNames', $this->Translate('Geräte mit Update'), $plain, 40);
        $this->RegisterVariableString('LastCheck', $this->Translate('Letzte Prüfung'), $plain, 50);
        $this->MaintainVariable('Table', $this->Translate('Übersicht'), VARIABLETYPE_STRING, '~HTMLBox', 60, $this->ReadPropertyBoolean('ShowTable'));

        // Bedienung aus der Visualisierung – nur mit ausdrücklicher Freigabe im Formular
        // Aufzählung kennt je Option nur Value, Caption, IconActive, IconValue, Color – weitere Schlüssel lösen beim Übernehmen eine Warnung aus
        $option = static fn (int $value, string $caption): array => ['Value' => $value, 'Caption' => $caption, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        $commands = $this->ReadPropertyBoolean('VisuCommands');
        $this->MaintainVariable('Command', $this->Translate('Befehl'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode([$option(1, $this->Translate('Jetzt prüfen')), $option(2, $this->Translate('Alle Updates starten'))])
        ], 5, $commands);
        if ($commands) {
            $this->EnableAction('Command');
        }

        // Fremde Objekte als Referenz anmelden (Symcon warnt dann, bevor sie gelöscht werden):
        // gewählte Visualisierung und die Wach-Signale der Batteriegeräte
        $visu = $this->ReadPropertyInteger('NotifyVisu');
        $wanted = $visu >= 10000 ? [$visu] : [];
        $wake = [];
        foreach ($this->DeviceRows() as $row) {
            if ($row['Active'] && $row['Battery'] && $row['WakeVar'] >= 10000) {
                $wanted[] = $row['WakeVar'];
                $wake[] = $row['WakeVar'];
            }
        }
        $wanted = array_values(array_unique($wanted));
        $known = $this->GetReferenceList();
        foreach ($known as $ref) {
            if (!in_array($ref, $wanted, true)) {
                $this->UnregisterReference($ref);
            }
        }
        foreach ($wanted as $ref) {
            if (!in_array($ref, $known, true)) {
                $this->RegisterReference($ref);
            }
        }
        // Wach-Signal: wird die Variable aktualisiert, ist das Batteriegerät gerade wach
        foreach ($this->GetMessageList() as $sender => $messages) {
            if (in_array(VM_UPDATE, $messages, true) && !in_array((int) $sender, $wake, true)) {
                $this->UnregisterMessage((int) $sender, VM_UPDATE);
            }
        }
        foreach (array_unique($wake) as $var) {
            if (IPS_VariableExists($var)) {
                $this->RegisterMessage($var, VM_UPDATE);
            }
        }
        $this->SetBuffer('WakeHosts', '[]');

        // Merker von Geräten entfernen, die nicht mehr in der Liste stehen
        $state = $this->ReadState();
        $keys = [];
        foreach ($this->DeviceRows() as $row) {
            $keys[$this->HostKey($row['Host'])] = true;
        }
        $pruned = array_intersect_key($state, $keys);
        foreach ($this->DeviceRows() as $row) {
            $k = $this->HostKey($row['Host']);
            if (isset($pruned[$k]) && $pruned[$k]['pending'] && (!$row['Battery'] || !$row['Active'])) {
                $pruned[$k]['pending'] = false;
            }
        }
        if ($pruned !== $state) {
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
        } elseif ($Message === VM_UPDATE) {
            $this->WakeUp((int) $SenderID);
        }
    }

    /**
     * Wach-Signal eines Batteriegeräts: Abfrage nur dieses Geräts kurz danach (nicht hier im Thread des Absenders).
     * Mehrere Werte kommen beim Aufwachen fast gleichzeitig – je Gerät höchstens eine Abfrage pro WakeGap().
     */
    private function WakeUp(int $varID): void
    {
        $seen = json_decode($this->GetBuffer('WakeSeen'), true);
        $seen = is_array($seen) ? $seen : [];
        $hosts = json_decode($this->GetBuffer('WakeHosts'), true);
        $hosts = is_array($hosts) ? $hosts : [];
        $now = time();
        foreach ($this->ActiveRows() as $row) {
            if (!$row['Battery'] || $row['WakeVar'] !== $varID) {
                continue;
            }
            $k = $this->HostKey($row['Host']);
            if ($now - (int) ($seen[$k] ?? 0) < $this->WakeGap() || in_array($k, $hosts, true)) {
                continue;
            }
            $seen[$k] = $now;
            $hosts[] = $k;
            $this->SendDebug('Wach-Signal', $row['Host'] . ' (Variable ' . $varID . ')', 0);
        }
        $this->SetBuffer('WakeSeen', json_encode($seen));
        $this->SetBuffer('WakeHosts', json_encode($hosts));
        if (count($hosts) > 0) {
            $this->SetTimerInterval('Wake', 200);
        }
    }

    /** Mindestabstand (Sekunden) zwischen zwei Abfragen desselben Batteriegeräts durch das Wach-Signal. */
    protected function WakeGap(): int
    {
        return 30;
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
            case 'TimerWake':
                $this->SetTimerInterval('Wake', 0);
                $hosts = json_decode($this->GetBuffer('WakeHosts'), true);
                $this->SetBuffer('WakeHosts', '[]');
                if (is_array($hosts) && count($hosts) > 0 && !$this->RunCheck($hosts, true)) {
                    // gerade läuft eine volle Abfrage – gleich noch einmal versuchen
                    $this->SetBuffer('WakeHosts', json_encode($hosts));
                    $this->SetTimerInterval('Wake', 2000);
                }
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
            case 'Command':
                // Variable „Befehl“ aus der Visualisierung; bleibt auf dem letzten Befehl stehen
                if (!$this->ReadPropertyBoolean('VisuCommands')) {
                    throw new Exception($this->T('Die Bedienung aus der Visualisierung ist im Formular nicht freigegeben.'));
                }
                $this->SetValue('Command', (int) $Value);
                if ((int) $Value === 1) {
                    $this->CheckAll();
                } elseif ((int) $Value === 2) {
                    $this->UpdateAll();
                }
                break;
            case 'TestNotify':
                $ok = $this->Notify($this->T('Shelly-Firmware'), $this->T('Test: So sieht die Meldung bei einem neuen Update aus.'));
                $this->ShowResult($ok ? $this->T('Test-Nachricht gesendet.') : ($this->ReadPropertyInteger('NotifyVisu') < 10000
                    ? $this->T('Keine Visualisierung ausgewählt (unter „Prüfung, Nachricht und Anzeige“) – erst auswählen und übernehmen.')
                    : $this->T('Test-Nachricht konnte nicht gesendet werden – ist eine Kachel-Visualisierung ausgewählt? Details im Meldungsfenster.')));
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
            'History'       => ['values' => $this->HistoryRows()],
            'PanelHistory'  => ['caption' => $this->T('Update-Verlauf (%d)', count($this->HistoryRows()))],
            'Status'        => ['values' => $this->StatusRows($rows)],
            'PanelDevices'  => ['caption' => $this->T('Geräte verwalten (%d)', count($rows)), 'expanded' => count($rows) === 0],
            'PanelSettings' => ['caption' => $this->T('Prüfung, Nachricht und Anzeige (%s)', $this->SettingsSummary())],
            'PanelUpdates'  => ['caption' => $this->ReadPropertyBoolean('ContinueUpdates') ? $this->T('Updates (Folge-Updates an)') : $this->T('Updates (Folge-Updates aus)')],
            'PanelPassword' => ['expanded' => $noSetup,
                'caption' => $this->ReadPropertyString('MasterPassword') !== '' ? $this->T('Passwort (Master-Passwort hinterlegt)') : $this->T('Passwort')]
        ];
        $this->FillForm($form['elements'], $fill);
        $this->FillForm($form['actions'], $fill);
        return json_encode($form);
    }

    // ------------------------------------------------------------ öffentlich

    /** Alle aktiven Geräte abfragen: installierte Version und verfügbares Update. */
    public function CheckAll(): bool
    {
        return $this->RunCheck(null, false);
    }

    /**
     * Abfrage aller aktiven Geräte ($only = null) oder nur der genannten (Host-Schlüssel, z. B. beim Wach-Signal).
     * $quiet: keine Meldungen im Formular (Abfrage im Hintergrund). false = gerade läuft schon eine Abfrage.
     */
    private function RunCheck(?array $only, bool $quiet): bool
    {
        $key = 'ShellyFWCheck' . $this->InstanceID . '_Check';
        if (!IPS_SemaphoreEnter($key, 100)) {
            if (!$quiet) {
                $this->ShowResult($this->T('Abfrage läuft bereits …'));
            }
            return false;
        }
        try {
            $rows = $this->ActiveRows();
            if ($only !== null) {
                $rows = array_values(array_filter($rows, fn (array $r): bool => in_array($this->HostKey($r['Host']), $only, true)));
            }
            if (!$quiet) {
                $this->ShowResult($this->T('Abfrage läuft … (%d Geräte, kann bis zu einer halben Minute dauern)', count($rows)));
            }
            $state = $this->ReadState();
            $now = time();
            $fast = [];       // Batteriegeräte: nur die schnelle Abfrage, solange sie wach sind

            // 1. Schritt: /shelly – geht ohne Anmeldung und liefert Name, Modell, Version
            $requests = [];
            foreach ($rows as $row) {
                $requests[$this->HostKey($row['Host'])] = ['url' => 'http://' . $row['Host'] . '/shelly', 'timeout' => 5];
            }
            $infos = $this->HttpBatch($requests);

            $calls = [];
            $again = [];
            $justDone = [];   // Geräte, die in dieser Runde ein Update abgeschlossen haben
            $found = [];      // neu gefundene Updates (für die Benachrichtigung)
            $history = [];    // abgeschlossene Versionswechsel dieser Runde
            foreach ($rows as $row) {
                $k = $this->HostKey($row['Host']);
                $e = $state[$k] ?? $this->EmptyEntry();
                $info = $infos[$k] ?? ['code' => 0, 'body' => ''];
                $data = $info['code'] === 200 ? $this->DecodeJson($info['body']) : null;
                $e['checked'] = $now;
                $e['battery'] = $row['Battery'];
                if (!is_array($data)) {
                    $e['online'] = false;
                    $e['err'] = $row['Battery'] ? 'sleep' : 'offline';   // Batteriegeräte schlafen meist – kein Fehler
                    $state[$k] = $e;
                    continue;
                }
                $oldVer = $e['ver'];
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
                $e['slot'] = isset($data['slot']) ? (int) $data['slot'] : -1;

                if ($e['upd_start'] === 0 && $oldVer !== '' && $e['ver'] !== $oldVer) {
                    // Version hat sich geändert, ohne dass das Modul ein Update gestartet hat (Auto-Update, Weboberfläche, App)
                    $history[] = ['ts' => $now, 'name' => $e['name'], 'from' => $oldVer, 'to' => $e['ver'], 'by' => 'external'];
                    $this->LogMessage($this->T('%s: Firmware wurde außerhalb des Moduls von %s auf %s geändert', $e['name'], $oldVer, $e['ver']), KL_NOTIFY);
                    $e['note'] = '';
                }
                if ($e['upd_start'] > 0) {
                    // Update wurde gestartet: nur auf die neue Version warten
                    if ($e['ver'] !== $e['upd_from']) {
                        $history[] = ['ts' => $now, 'name' => $e['name'], 'from' => $e['upd_from'], 'to' => $e['ver'],
                            'by' => $e['upd_chain'] > 0 ? 'chain' : 'module'];
                        $this->LogMessage($this->T('%s: Firmware aktualisiert von %s auf %s', $e['name'], $e['upd_from'], $e['ver']), KL_NOTIFY);
                        $e['upd_start'] = 0;
                        $e['avail'] = '';
                        $e['note'] = 'done|' . $e['upd_from'];
                        $justDone[$k] = $row;
                    } elseif ($e['slot'] >= 0 && $e['upd_slot'] >= 0 && $e['slot'] !== $e['upd_slot'] && $e['upd_tries'] < self::MAX_STEPS) {
                        // Gerät hat neu gestartet (anderer Speicherplatz), meldet aber die alte Version:
                        // Zwischenschritt eines mehrstufigen Updates. Eine Runde abwarten, dann erneut anstoßen.
                        if ($e['upd_step'] === 0) {
                            $e['upd_step'] = $now;
                        } elseif ($now - $e['upd_step'] >= $this->StepWait()) {
                            $again[$k] = $row;
                        }
                    } elseif ($now - $e['upd_start'] > ($row['Battery'] ? self::BATTERY_VERIFY_MAX : self::VERIFY_MAX)) {
                        $this->LogMessage($this->T('%s: Update nicht bestätigt – Version ist weiter %s', $e['name'], $e['ver']), KL_WARNING);
                        $e['upd_start'] = 0;
                        $e['note'] = 'unconfirmed|' . $e['ver'];
                    }
                    if ($e['upd_start'] > 0) {
                        $state[$k] = $e;
                        continue;   // läuft noch: Gerät in Ruhe lassen
                    }
                    // fertig: gleich nachsehen, ob es ein weiteres Update gibt (Shelly aktualisiert teils in Stufen)
                }
                $state[$k] = $e;
                if ($row['Battery']) {
                    // Batteriegeräte sind nur rund 2 Sekunden wach. Vorgemerkt: sofort Shelly.Update – das Gerät holt sich
                    // die Update-Angaben selbst. Sonst nur Sys.GetStatus (antwortet sofort; nach dem Aufwachen ist die
                    // Update-Liste darin allerdings meist leer, an Chris' H&T beobachtet).
                    $fast[$k] = ['host' => $row['Host'], 'method' => ($e['pending'] && $e['upd_start'] === 0) ? 'Shelly.Update' : 'Sys.GetStatus',
                        'params' => ($e['pending'] && $e['upd_start'] === 0) ? ['stage' => 'stable'] : [],
                        'timeout' => ($e['pending'] && $e['upd_start'] === 0) ? 15 : 5, 'password' => $this->PasswordFor($row), 'auth' => $e['auth']];
                    continue;
                }
                $calls[$k] = ['host' => $row['Host'], 'method' => 'Shelly.CheckForUpdate', 'params' => [],
                    'timeout' => 45, 'password' => $this->PasswordFor($row), 'auth' => $e['auth']];
                // Zeitpläne lesen: das automatische Update des Geräts ist ein Zeitplan, der Shelly.Update aufruft
                $calls[$k . '#sched'] = ['host' => $row['Host'], 'method' => 'Schedule.List', 'params' => [],
                    'timeout' => 15, 'password' => $this->PasswordFor($row), 'auth' => $e['auth']];
            }

            // Update läuft und Gerät startet gerade neu → nicht als Fehler zeigen
            foreach ($state as $k => $e) {
                if (($e['upd_start'] ?? 0) > 0 && !$e['online'] && $now - $e['upd_start'] > ($e['battery'] ? self::BATTERY_VERIFY_MAX : self::VERIFY_MAX)) {
                    $state[$k]['upd_start'] = 0;
                    $state[$k]['note'] = 'unreachable|';
                }
            }

            // Batteriegeräte zuerst – sie schlafen bald wieder ein
            $hosts = [];
            foreach ($rows as $row) {
                $hosts[$this->HostKey($row['Host'])] = $row;
            }
            $fallback = [];
            foreach ($this->RpcBatch($fast) as $k => $res) {
                $e = $state[$k];
                $isUpdate = $fast[$k]['method'] === 'Shelly.Update';
                if (!$res['ok'] && $res['err'] === 'offline') {
                    $e['online'] = false;
                    $e['err'] = 'sleep';   // schon wieder eingeschlafen – kein Fehler; Vormerkung bleibt
                    $state[$k] = $e;
                    continue;
                }
                if ($isUpdate) {
                    if ($res['ok']) {
                        $e = $this->MarkStarted($e, $now);
                        unset($found[$k]);
                        $this->LogMessage($this->T('%s: Batteriegerät ist wach – vorgemerktes Update gestartet', $e['name']), KL_NOTIFY);
                        $this->SetTimerInterval('Verify', self::VERIFY_SECONDS * 1000);
                    } else {
                        // Gerät lehnt ab (z. B. noch keine Update-Angaben): beim Hersteller nachfragen, solange es wach ist
                        $this->SendDebug('Batteriegerät', $e['name'] . ': Shelly.Update abgelehnt (' . $res['msg'] . ') – frage nach Update', 0);
                        $fallback[$k] = ['host' => $hosts[$k]['Host'], 'method' => 'Shelly.CheckForUpdate', 'params' => [],
                            'timeout' => 20, 'password' => $this->PasswordFor($hosts[$k]), 'auth' => $e['auth']];
                    }
                    $state[$k] = $e;
                    continue;
                }
                if (!$res['ok']) {
                    $e['err'] = $res['err'];
                    $e['msg'] = $res['msg'];
                    $state[$k] = $e;
                    continue;
                }
                // Sys.GetStatus: gemeldetes Update, sonst Ableitung aus Geräten gleichen Modells
                $reported = (string) ($res['result']['available_updates']['stable']['version'] ?? '');
                $e['sure'] = $reported !== '';
                $e = $this->ApplyAvail($e, $reported !== '' ? $reported : $this->SiblingVersion($state, $e), $k, $found);
                $state[$k] = $e;
            }
            // Ausweichweg für vorgemerkte Geräte: Update-Abfrage, bei Erfolg gleich starten
            $retry = [];
            foreach ($this->RpcBatch($fallback) as $k => $res) {
                $e = $state[$k];
                if (!$res['ok']) {
                    if ($res['err'] === 'offline') {
                        $e['online'] = false;
                        $e['err'] = 'sleep';
                    }
                    $this->LogMessage($this->T('%s: vorgemerktes Update nicht gestartet – %s', $e['name'], $this->ErrorText($res)), KL_WARNING);
                    $state[$k] = $e;
                    continue;
                }
                $e['sure'] = true;
                $e = $this->ApplyAvail($e, (string) ($res['result']['stable']['version'] ?? ''), $k, $found);
                if ($e['avail'] === '') {
                    $e['pending'] = false;
                    $this->LogMessage($this->T('%s: Batteriegerät ist wach – kein Update verfügbar, Vormerkung erledigt', $e['name']), KL_NOTIFY);
                } else {
                    $retry[$k] = ['host' => $hosts[$k]['Host'], 'method' => 'Shelly.Update', 'params' => ['stage' => 'stable'],
                        'timeout' => 15, 'password' => $this->PasswordFor($hosts[$k]), 'auth' => $e['auth']];
                }
                $state[$k] = $e;
            }
            foreach ($this->RpcBatch($retry) as $k => $res) {
                $e = $state[$k];
                if ($res['ok']) {
                    $e = $this->MarkStarted($e, $now);
                    unset($found[$k]);
                    $this->LogMessage($this->T('%s: Batteriegerät ist wach – vorgemerktes Update gestartet', $e['name']), KL_NOTIFY);
                    $this->SetTimerInterval('Verify', self::VERIFY_SECONDS * 1000);
                } else {
                    $this->LogMessage($this->T('%s: vorgemerktes Update nicht gestartet – %s', $e['name'], $this->ErrorText($res)), KL_WARNING);
                }
                $state[$k] = $e;
            }
            // schlafende Batteriegeräte: Update aus Geräten gleichen Modells ableiten
            foreach ($rows as $row) {
                $k = $this->HostKey($row['Host']);
                $e = $state[$k];
                if ($row['Battery'] && $e['err'] === 'sleep' && $e['avail'] === '' && $e['ver'] !== '' && $e['upd_start'] === 0) {
                    $sibling = $this->SiblingVersion($state, $e);
                    if ($sibling !== '') {
                        $state[$k] = $this->ApplyAvail($e, $sibling, $k, $found);
                    }
                }
            }

            // Mehrstufige Updates: nächsten Schritt anstoßen
            $steps = [];
            foreach ($again as $k => $row) {
                $steps[$k] = ['host' => $row['Host'], 'method' => 'Shelly.Update', 'params' => ['stage' => 'stable'],
                    'timeout' => 20, 'password' => $this->PasswordFor($row), 'auth' => $state[$k]['auth']];
            }
            foreach ($this->RpcBatch($steps) as $k => $res) {
                $e = $state[$k];
                if ($res['ok']) {
                    $e['upd_tries']++;
                    $e['upd_start'] = $now;
                    $e['upd_slot'] = $e['slot'];
                    $e['upd_step'] = 0;
                    $this->LogMessage($this->T('%s: Zwischenschritt erledigt (weiter %s) – Update auf %s erneut angestoßen (Schritt %d)',
                        $e['name'], $e['ver'], $e['upd_target'], $e['upd_tries']), KL_NOTIFY);
                } else {
                    $e['upd_start'] = 0;
                    $e['note'] = 'stepfail|' . $res['msg'];
                    $this->LogMessage($e['name'] . ': ' . $this->NoteText($e['note']), KL_WARNING);
                }
                $state[$k] = $e;
            }

            // 2. Schritt: nach Updates fragen (das Gerät fragt dafür selbst beim Shelly-Server nach)
            foreach ($this->RpcBatch($calls) as $k => $res) {
                if (substr((string) $k, -6) === '#sched') {
                    $k = substr((string) $k, 0, -6);
                    $state[$k] = array_merge($state[$k], $this->ReadAutoUpdate($res));
                    continue;
                }
                $e = $state[$k];
                if (!$res['ok']) {
                    $e['err'] = $res['err'];
                    $e['msg'] = $res['msg'];
                } else {
                    $e['sure'] = true;
                    $e = $this->ApplyAvail($e, (string) ($res['result']['stable']['version'] ?? ''), $k, $found);
                }
                $state[$k] = $e;
            }

            // Folge-Updates: Gerät hat gerade ein Update abgeschlossen und bietet schon das nächste an
            // (z. B. 2.0.0 → 2.0.3 → 2.2.0). Auf Wunsch gleich weiter bis zur neuesten Version.
            if ($this->ReadPropertyBoolean('ContinueUpdates')) {
                $next = [];
                foreach ($justDone as $k => $row) {
                    $e = $state[$k];
                    if ($e['err'] === '' && $e['avail'] !== '' && $e['upd_chain'] < self::MAX_CHAIN) {
                        $next[$k] = ['host' => $row['Host'], 'method' => 'Shelly.Update', 'params' => ['stage' => 'stable'],
                            'timeout' => 20, 'password' => $this->PasswordFor($row), 'auth' => $e['auth']];
                    }
                }
                foreach ($this->RpcBatch($next) as $k => $res) {
                    $e = $state[$k];
                    if ($res['ok']) {
                        $chain = $e['upd_chain'] + 1;
                        $e = $this->MarkStarted($e, $now);
                        $e['upd_chain'] = $chain;
                        unset($found[$k]);   // läuft von selbst weiter – keine Benachrichtigung nötig
                        $this->LogMessage($this->T('%s: Folge-Update von %s auf %s gestartet', $e['name'], $e['ver'], $e['avail']), KL_NOTIFY);
                    } else {
                        $this->LogMessage($this->T('%s: Folge-Update auf %s nicht gestartet – %s', $e['name'], $e['avail'], $this->ErrorText($res)), KL_WARNING);
                    }
                    $state[$k] = $e;
                }
                if (count($next) > 0) {
                    $this->SetTimerInterval('Verify', self::VERIFY_SECONDS * 1000);
                }
            }

            $this->WriteState($state);
            if ($only === null) {
                $this->WriteAttributeInteger('LastCheck', $now);
            }
            $this->AddHistory($history);
            $this->PublishValues();
            $this->RefreshForm();
            if (count($found) > 0) {
                $this->Notify($this->T('Shelly-Firmware'), count($found) === 1 ? $this->T('Update verfügbar: %s', implode(', ', $found)) : $this->T('%d Updates verfügbar: %s', count($found), implode(', ', $found)));
            }
            if ($quiet) {
                return true;
            }
            $c = $this->Counts();
            $this->ShowResult($this->T('Abfrage fertig: %d Geräte geprüft', $c['total']) . ', '
                . ($c['updates'] > 0 ? $this->T('%d mit Update', $c['updates']) : $this->T('kein Update verfügbar'))
                . ($c['running'] > 0 ? ', ' . $this->T('%d Update(s) laufen', $c['running']) : '')
                . ($c['offline'] > 0 ? ', ' . $this->T('%d nicht erreichbar', $c['offline']) : '') . '.');
            return true;
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }

    /** Gemeldete Version eintragen; neu gefundene Updates landen in $found (Benachrichtigung). */
    private function ApplyAvail(array $e, string $avail, string $k, array &$found): array
    {
        if ($avail === $e['ver']) {
            $avail = '';
        }
        if ($avail !== '' && $e['avail'] !== $avail) {
            $this->LogMessage($this->T('%s: Firmware %s verfügbar (installiert %s)', $e['name'], $avail, $e['ver']), KL_NOTIFY);
            if (!$this->NoteIsDone($e['note'])) {
                $e['note'] = '';
            }
            $found[$k] = $e['name'] . ' (' . $avail . ')';
        }
        $e['avail'] = $avail;
        return $e;
    }

    /**
     * Neueste stabile Version, die ein anderes Gerät gleichen Modells installiert hat oder angeboten bekommt –
     * nur wenn sie neuer ist als die des Geräts. Batteriegeräte melden ihr Update nach dem Aufwachen meist nicht.
     */
    private function SiblingVersion(array $state, array $e): string
    {
        $best = '';
        foreach ($state as $other) {
            if (!is_array($other) || ($other['model'] ?? '') !== $e['model'] || $e['model'] === '' || ($other['id'] ?? '') === $e['id']) {
                continue;
            }
            foreach ([(string) ($other['ver'] ?? ''), (string) ($other['avail'] ?? '')] as $v) {
                if ($v !== '' && strpos($v, '-') === false && ($best === '' || version_compare($v, $best, '>'))) {
                    $best = $v;
                }
            }
        }
        return ($best !== '' && $e['ver'] !== '' && version_compare($best, $e['ver'], '>')) ? $best : '';
    }

    /** Merker setzen: Update auf die verfügbare Version wurde gerade angestoßen. */
    private function MarkStarted(array $e, int $now): array
    {
        $e['upd_start'] = $now;
        $e['upd_from'] = $e['ver'];
        $e['upd_target'] = $e['avail'];
        $e['upd_slot'] = $e['slot'];
        $e['upd_tries'] = 1;
        $e['upd_step'] = 0;
        $e['upd_chain'] = 0;
        $e['pending'] = false;
        $e['note'] = '';
        return $e;
    }

    /** Update für ein Gerät starten (Host = IP-Adresse wie in der Liste). */
    public function UpdateDevice(string $Host): bool
    {
        $Host = $this->CleanHost($Host);
        if ($Host === '') {
            $this->ShowResult($this->T('Kein Gerät angegeben.'));
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
            $this->ShowResult($this->T('Nichts ausgewählt – in der Übersicht in der Spalte „Auswahl“ Haken setzen.'));
            return 0;
        }
        return $this->QueueUpdates(array_map([$this, 'HostKey'], array_map('strval', $list)));
    }

    /** @param string[]|null $only Host-Schlüssel, null = alle */
    private function QueueUpdates(?array $only): int
    {
        $state = $this->ReadState();
        $queue = [];
        $marked = [];
        foreach ($this->ActiveRows() as $row) {
            $k = $this->HostKey($row['Host']);
            if ($only !== null && !in_array($k, $only, true)) {
                continue;
            }
            if (!$this->CanUpdate($state[$k] ?? null, $only !== null)) {
                continue;
            }
            if ($state[$k]['err'] === 'sleep') {
                $marked[] = $row['Host'];   // schläft: nur vormerken, kein Abstand nötig
            } else {
                $queue[] = $row['Host'];
            }
        }
        foreach ($marked as $host) {
            $this->StartUpdate($host, $message);
        }
        if (count($queue) === 0 && count($marked) > 0) {
            $this->ShowResult(count($marked) === 1 ? (string) $message
                : $this->T('%d Batteriegeräte schlafen – ihre Updates sind vorgemerkt und starten beim nächsten Aufwachen.', count($marked)));
            $this->RefreshForm();
            return count($marked);
        }
        if (count($queue) === 0) {
            $this->ShowResult($only === null
                ? $this->T('Kein Gerät mit verfügbarem Update – ggf. erst „Jetzt prüfen“.')
                : $this->T('Für die ausgewählten Geräte ist kein Update verfügbar (oder es läuft schon).'));
            return 0;
        }
        $count = count($queue) + count($marked);
        $gap = max(5, $this->ReadPropertyInteger('UpdateGap'));
        $this->SetBuffer('Selected', '[]');
        $this->SetBuffer('Queue', json_encode($queue));
        $this->WorkQueue();
        $this->ShowResult($count === 1
            ? $this->T('1 Update gestartet. Der Status in der Übersicht zeigt den Fortschritt.')
            : $this->T('%d Updates eingeplant – ein Gerät alle %d Sekunden. Der Status in der Übersicht zeigt den Fortschritt.', $count, $gap));
        return $count;
    }

    /** $picked: ausdrücklich ausgewählt – ein schlafendes Batteriegerät wird dann auch ohne bekanntes Update vorgemerkt. */
    private function CanUpdate(?array $e, bool $picked = false): bool
    {
        if ($e === null || $e['upd_start'] > 0 || $e['pending']) {
            return false;
        }
        if ($e['battery'] && $e['err'] === 'sleep') {
            return $e['avail'] !== '' || ($picked && $e['gen'] >= 2);
        }
        return $e['avail'] !== '' && $e['online'] && $e['err'] === '';
    }

    /** Shellys per mDNS suchen und in die Liste eintragen (wirksam erst nach „Änderungen übernehmen“). */
    public function Discover(): int
    {
        $ranges = $this->ScanRanges($rangeErrors);
        $addresses = 0;
        foreach ($ranges as $r) {
            $addresses += $r[1] - $r[0] + 1;
        }
        $this->ShowResult(count($ranges) > 0 ? $this->T('Suche läuft … (eigenes Netz und %d weitere Bereiche mit %d Adressen)', count($ranges), $addresses) : $this->T('Suche läuft … (eigenes Netz)'));
        $mdns = $this->QueryMdns();
        if ($mdns === null && count($ranges) === 0) {
            $this->ShowResult($this->T('Die Suche im eigenen Netz braucht die Symcon-Instanz „DNS-SD Control“ – sie wurde nicht gefunden. Geräte bitte von Hand eintragen oder unter „Geräte verwalten“ einen Adressbereich angeben.')
                . ($rangeErrors !== '' ? ' ' . $rangeErrors : ''));
            return 0;
        }
        // Funde aus den Adressbereichen dazunehmen; Schlüssel ist die Geräte-ID, dadurch zählt kein Gerät doppelt
        $found = [];
        foreach (($mdns ?? []) as $name => $ip) {
            $found[strtolower((string) $name)] = [$name, $ip];
        }
        foreach ($this->ScanAddresses($ranges) as $name => $ip) {
            $found[strtolower((string) $name)] = $found[strtolower((string) $name)] ?? [$name, $ip];
        }
        $found = array_column($found, 1, 0);
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
            $list[] = ['Active' => true, 'Name' => $name, 'Host' => $ip, 'OwnPassword' => false, 'Password' => '', 'Battery' => false, 'WakeVar' => 0, 'rowColor' => '#C0C0FF'];
            $new++;
        }
        if ($new + $moved > 0) {
            $this->UpdateFormField('Devices', 'values', json_encode($list));
            $this->UpdateFormField('PanelDevices', 'expanded', true);
            $text = $this->T('%d Shelly(s) im Netzwerk gefunden: %d neu', count($found), $new) . ($moved > 0 ? ', ' . $this->T('%d mit neuer IP-Adresse', $moved) : '')
                . ' – ' . $this->T('oben unter „Geräte verwalten“ blau markiert. Jetzt „Änderungen übernehmen“ drücken.');
        } else {
            $text = $this->T('%d Shelly(s) im Netzwerk gefunden – alle stehen schon in der Liste.', count($found));
        }
        $this->ShowResult($text . ($rangeErrors !== '' ? ' ' . $rangeErrors : ''));
        return $new;
    }

    // ------------------------------------------------ Suche in Adressbereichen

    /**
     * Adressbereiche aus der Einstellung als [erste, letzte] (Zahlen). Erlaubt: „Von“ + „Bis“,
     * nur „Von“ (dann das ganze Netz dieser Adresse, .1 bis .254) oder Kurzform 192.168.20.0/24 in „Von“.
     * @return array<int,array{0:int,1:int}>
     */
    private function ScanRanges(?string &$errors): array
    {
        $errors = '';
        $out = [];
        $raw = json_decode($this->ReadPropertyString('ScanRanges'), true);
        foreach (is_array($raw) ? $raw : [] as $r) {
            $from = trim((string) ($r['From'] ?? ''));
            $to = trim((string) ($r['To'] ?? ''));
            if ($from === '') {
                continue;
            }
            $first = false;
            $last = false;
            if (preg_match('#^(\\d+\\.\\d+\\.\\d+\\.\\d+)/(\\d+)$#', $from, $m) && (int) $m[2] >= 16 && (int) $m[2] <= 30) {
                $base = ip2long($m[1]);
                if ($base !== false) {
                    $size = 1 << (32 - (int) $m[2]);
                    $net = $base & ~($size - 1);
                    $first = $net + 1;
                    $last = $net + $size - 2;
                }
            } else {
                $first = ip2long($from);
                if ($first !== false && $to === '') {
                    $net = $first & ~255;      // nur „Von“: das ganze Netz dieser Adresse
                    $first = $net + 1;
                    $last = $net + 254;
                } elseif (preg_match('/^\\d+$/', $to) && $first !== false) {
                    $last = ($first & ~255) + (int) $to;   // „Bis“ nur als letzte Zahl, z. B. 254
                } else {
                    $last = ip2long($to);
                }
            }
            if ($first === false || $last === false || $last < $first) {
                $errors .= $this->T('Bereich „%s“ ist keine gültige Angabe und wurde ausgelassen.', $from . ($to !== '' ? ' – ' . $to : '')) . ' ';
                continue;
            }
            if ($last - $first + 1 > self::SCAN_MAX) {
                $errors .= $this->T('Bereich „%s“ ist größer als %d Adressen und wurde ausgelassen.', $from . ($to !== '' ? ' – ' . $to : ''), self::SCAN_MAX) . ' ';
                continue;
            }
            $out[] = [$first, $last];
        }
        return $out;
    }

    /**
     * Jede Adresse der Bereiche kurz nach /shelly fragen; Antwort eines Shelly ab Gen2 = Fund.
     * @return array<string,string> Geräte-ID => IP-Adresse
     */
    private function ScanAddresses(array $ranges): array
    {
        $ips = [];
        foreach ($ranges as [$first, $last]) {
            for ($n = $first; $n <= $last; $n++) {
                $ips[long2ip($n)] = true;
            }
        }
        $found = [];
        foreach (array_chunk(array_keys($ips), 128) as $chunk) {
            $requests = [];
            foreach ($chunk as $ip) {
                $requests[$ip] = ['url' => 'http://' . $ip . '/shelly', 'timeout' => 3, 'connect_ms' => 1500, 'quiet' => true];
            }
            foreach ($this->HttpBatch($requests) as $ip => $res) {
                $data = $res['code'] === 200 ? $this->DecodeJson($res['body']) : null;
                if (is_array($data) && (int) ($data['gen'] ?? 0) >= 2 && (string) ($data['id'] ?? '') !== '') {
                    $found[(string) $data['id']] = (string) $ip;
                }
            }
        }
        $this->SendDebug('Suche in Adressbereichen', count($ips) . ' Adressen, Funde: ' . json_encode($found), 0);
        return $found;
    }

    // ----------------------------------------------------------------- Verlauf

    private function AddHistory(array $entries): void
    {
        if (count($entries) === 0) {
            return;
        }
        $history = json_decode($this->ReadAttributeString('History'), true);
        $history = array_merge($entries, is_array($history) ? $history : []);   // neueste zuerst
        $this->WriteAttributeString('History', json_encode(array_slice($history, 0, self::HISTORY_MAX)));
        $this->UpdateFormField('History', 'values', json_encode($this->HistoryRows()));
    }

    private function HistoryRows(): array
    {
        $history = json_decode($this->ReadAttributeString('History'), true);
        $by = ['module' => $this->T('Modul'), 'chain' => $this->T('Modul (Folge-Update)'), 'external' => $this->T('Gerät selbst / von außen')];
        $rows = [];
        foreach (is_array($history) ? $history : [] as $h) {
            $rows[] = ['Time' => date('d.m.Y H:i', (int) ($h['ts'] ?? 0)), 'Name' => (string) ($h['name'] ?? ''),
                'From' => (string) ($h['from'] ?? ''), 'To' => (string) ($h['to'] ?? ''), 'By' => $by[(string) ($h['by'] ?? '')] ?? (string) ($h['by'] ?? '')];
        }
        return $rows;
    }

    /** Update-Verlauf als JSON (neueste zuerst): ts, name, from, to, by (module = dieses Modul, chain = Folge-Update, external = Gerät selbst / von außen). */
    public function GetHistory(): string
    {
        $history = json_decode($this->ReadAttributeString('History'), true);
        return json_encode(is_array($history) ? $history : []);
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
                'battery'   => $row['Battery'],
                'pending'   => $e['pending'],
                'autoUpdate' => $e['auto'] === '' ? 'unknown' : $e['auto'],
                'autoUpdateSchedule' => $e['auto_spec'],
                'status'    => $this->StateText($e)
            ];
        }
        return json_encode($out);
    }

    // ------------------------------------------------------- Auto-Update lesen

    /** Aus der Antwort von Schedule.List: gibt es einen Zeitplan, der Shelly.Update aufruft? */
    private function ReadAutoUpdate(array $res): array
    {
        // Die Weboberfläche lässt beim Umschalten abgeschaltete Einträge liegen (an Plug M #2: 6 abgeschaltete + 1 eingeschalteter).
        // Maßgeblich ist deshalb der EINGESCHALTETE Eintrag, nicht der erste.
        if (!$res['ok'] || !is_array($res['result'])) {
            return ['auto' => '', 'auto_spec' => '', 'auto_jobs' => []];
        }
        $ids = [];
        $active = '';
        $spec = '';
        foreach ((array) ($res['result']['jobs'] ?? []) as $job) {
            $calls = (array) ($job['calls'] ?? []);
            if (count($calls) !== 1 || strtolower((string) ($calls[0]['method'] ?? '')) !== 'shelly.update') {
                continue;   // fremder Zeitplan (z. B. Schaltzeiten) – nie anfassen
            }
            $ids[] = (int) ($job['id'] ?? -1);
            if (!empty($job['enable'])) {
                $stage = strtolower((string) ($calls[0]['params']['stage'] ?? 'stable')) === 'beta' ? 'beta' : 'stable';
                if ($active === '' || $stage === 'beta') {
                    $active = $stage;
                    $spec = (string) ($job['timespec'] ?? '');
                }
            }
        }
        return ['auto' => $active !== '' ? $active : 'off', 'auto_spec' => $spec, 'auto_jobs' => $ids];
    }

    private function AutoText(array $e): string
    {
        $labels = ['off' => $this->T('Aus'), 'stable' => 'Stable', 'beta' => 'Beta'];
        return $labels[$e['auto']] ?? '';
    }

    /**
     * Automatisches Firmware-Update in den Geräten umstellen.
     * Hosts = JSON-Liste der IP-Adressen, Mode = 'off', 'stable' oder 'beta'. Rückgabe: Anzahl umgestellter Geräte.
     */
    public function SetAutoUpdate(string $Hosts, string $Mode): int
    {
        $Mode = strtolower($Mode);
        $list = json_decode($Hosts, true);
        if (!in_array($Mode, ['off', 'stable', 'beta'], true)) {
            $this->ShowResult($this->T('Auto-Update: unbekannte Einstellung „%s“.', $Mode));
            return 0;
        }
        if (!is_array($list) || count($list) === 0) {
            $this->ShowResult($this->T('Nichts ausgewählt – in der Übersicht in der Spalte „Auswahl“ Haken setzen.'));
            return 0;
        }
        $key = 'ShellyFWCheck' . $this->InstanceID . '_Check';
        if (!IPS_SemaphoreEnter($key, 60000)) {
            $this->ShowResult($this->T('Gerade läuft eine Abfrage – bitte gleich noch einmal versuchen.'));
            return 0;
        }
        try {
            $this->ShowResult($this->T('Auto-Update wird umgestellt …'));
            $only = array_map([$this, 'HostKey'], array_map('strval', $list));
            $state = $this->ReadState();
            $base = [];
            foreach ($this->ActiveRows() as $row) {
                $k = $this->HostKey($row['Host']);
                if (in_array($k, $only, true) && isset($state[$k]) && $state[$k]['gen'] >= 2) {
                    $base[$k] = ['host' => $row['Host'], 'timeout' => 15, 'password' => $this->PasswordFor($row), 'auth' => $state[$k]['auth']];
                }
            }
            if (count($base) === 0) {
                $this->ShowResult($this->T('Keines der ausgewählten Geräte ist bekannt – ggf. erst „Jetzt prüfen“.'));
                return 0;
            }
            $failed = [];
            // 1. aktuellen Stand frisch lesen
            $todo = [];
            foreach ($this->RpcBatch($this->AutoCalls($base, 'Schedule.List', [])) as $k => $res) {
                if (!$res['ok']) {
                    $failed[$k] = $this->ErrorText($res);
                    continue;
                }
                $todo[$k] = $this->ReadAutoUpdate($res)['auto_jobs'];
            }
            // 2. alle bisherigen Update-Zeitpläne entfernen (auch abgeschaltete Reste) – je Gerät einer pro Runde
            while (true) {
                $calls = [];
                foreach ($todo as $k => $ids) {
                    if (count($ids) > 0 && !isset($failed[$k])) {
                        $calls[$k] = $base[$k] + ['method' => 'Schedule.Delete', 'params' => ['id' => array_shift($todo[$k])]];
                    }
                }
                if (count($calls) === 0) {
                    break;
                }
                foreach ($this->RpcBatch($calls) as $k => $res) {
                    if (!$res['ok']) {
                        $failed[$k] = $this->ErrorText($res);
                    }
                }
            }
            // 3. neuen Zeitplan anlegen – gleicher Aufbau wie von der Weboberfläche (täglich 0 Uhr)
            if ($Mode !== 'off') {
                $job = ['enable' => true, 'timespec' => '0 0 0 * * 0,1,2,3,4,5,6',
                    'calls' => [['method' => 'Shelly.Update', 'params' => ['stage' => $Mode], 'origin' => 'shelly_service']]];
                $retry = [];
                foreach ($this->RpcBatch($this->AutoCalls(array_diff_key(array_intersect_key($base, $todo), $failed), 'Schedule.Create', $job)) as $k => $res) {
                    if (!$res['ok']) {
                        $retry[$k] = $base[$k];
                    }
                }
                // Ersatz für Geräte, die das Feld „origin“ nicht annehmen
                unset($job['calls'][0]['origin']);
                foreach ($this->RpcBatch($this->AutoCalls($retry, 'Schedule.Create', $job)) as $k => $res) {
                    if (!$res['ok']) {
                        $failed[$k] = $this->ErrorText($res);
                    }
                }
            }
            // 4. Ergebnis zurücklesen
            $done = 0;
            foreach ($this->RpcBatch($this->AutoCalls($base, 'Schedule.List', [])) as $k => $res) {
                $state[$k] = array_merge($state[$k], $this->ReadAutoUpdate($res));
                if (!isset($failed[$k]) && $state[$k]['auto'] === $Mode) {
                    $done++;
                    $this->LogMessage($this->T('%s: Auto-Update im Gerät auf „%s“ gestellt', $state[$k]['name'], $this->AutoText($state[$k])), KL_NOTIFY);
                } elseif (!isset($failed[$k])) {
                    $failed[$k] = $this->T('Gerät meldet danach „%s“', $this->AutoText($state[$k]));
                }
            }
            $this->WriteState($state);
            $this->SetBuffer('Selected', '[]');
            $this->PublishValues();
            $this->RefreshForm();
            $labels = ['off' => $this->T('Aus'), 'stable' => 'Stable', 'beta' => 'Beta'];
            $text = $this->T('Auto-Update bei %d Gerät(en) auf „%s“ gestellt.', $done, $labels[$Mode]);
            foreach ($failed as $k => $why) {
                $text .= ' ' . $this->T('Nicht geklappt: %s (%s).', $state[$k]['name'] ?? $k, $why);
                $this->LogMessage($this->T('%s: Auto-Update nicht umgestellt – %s', $state[$k]['name'] ?? $k, $why), KL_WARNING);
            }
            $this->ShowResult($text);
            return $done;
        } finally {
            IPS_SemaphoreLeave($key);
        }
    }

    private function AutoCalls(array $base, string $method, array $params): array
    {
        $calls = [];
        foreach ($base as $k => $b) {
            $calls[$k] = $b + ['method' => $method, 'params' => $params];
        }
        return $calls;
    }

    private function ErrorText(array $res): string
    {
        $map = ['offline' => $this->T('nicht erreichbar'), 'auth_missing' => $this->T('Passwort fehlt'), 'auth_wrong' => $this->T('Passwort falsch')];
        return $map[$res['err']] ?? ($res['msg'] !== '' ? $res['msg'] : $this->T('Fehler'));
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
            $message = $this->T('%s: Gerät ist nicht gespeichert oder wurde noch nicht geprüft.', $host);
            return false;
        }
        if ($e['upd_start'] > 0) {
            $message = $this->T('%s: Update läuft bereits.', $e['name']);
            return false;
        }
        if ($e['pending']) {
            $message = $this->T('%s: Update ist schon vorgemerkt und startet beim nächsten Aufwachen.', $e['name']);
            return true;
        }
        if ($e['avail'] === '' && $row['Battery'] && $e['err'] === 'sleep') {
            // Batteriegerät schläft, Update noch unbekannt: beim nächsten Aufwachen nachsehen und gleich starten
            $e['pending'] = true;
            $state[$k] = $e;
            $this->WriteState($state);
            $message = $this->T('%s: Batteriegerät schläft – beim nächsten Aufwachen wird nach einem Update gesehen und es gleich gestartet.', $e['name']);
            $this->LogMessage($message, KL_NOTIFY);
            return true;
        }
        if ($e['avail'] === '') {
            $message = $this->T('%s: kein Update bekannt – ggf. erst „Jetzt prüfen“.', $e['name']);
            return false;
        }
        $res = ['ok' => false, 'err' => 'offline', 'msg' => ''];
        if (!$row['Battery'] || $e['err'] !== 'sleep') {
            $res = $this->RpcBatch([$k => ['host' => $row['Host'], 'method' => 'Shelly.Update', 'params' => ['stage' => 'stable'],
                'timeout' => 20, 'password' => $this->PasswordFor($row), 'auth' => $e['auth']]])[$k];
        }
        if (!$res['ok'] && $row['Battery'] && $res['err'] === 'offline') {
            // Batteriegerät schläft: Update vormerken, es startet beim nächsten Aufwachen
            $e['pending'] = true;
            $e['online'] = false;
            $e['err'] = 'sleep';
            $state[$k] = $e;
            $this->WriteState($state);
            $message = $this->T('%s: Batteriegerät schläft – Update auf %s ist vorgemerkt und startet beim nächsten Aufwachen.', $e['name'], $e['avail']);
            $this->LogMessage($message, KL_NOTIFY);
            return true;
        }
        if (!$res['ok']) {
            $e['err'] = $res['err'];
            $e['msg'] = $res['msg'];
            $state[$k] = $e;
            $this->WriteState($state);
            $message = $this->T('%s: Update nicht gestartet – %s', $e['name'], $this->StateText($e));
            $this->LogMessage($message, KL_WARNING);
            return false;
        }
        $e = $this->MarkStarted($e, time());
        $state[$k] = $e;
        $this->WriteState($state);
        $this->SetTimerInterval('Verify', self::VERIFY_SECONDS * 1000);
        $message = $this->T('%s: Update auf %s gestartet. Das Gerät startet gleich neu.', $e['name'], $e['avail']);
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

    /**
     * Push-Nachricht an die im Formular gewählte Kachel-Visualisierung (0 = abgeschaltet).
     * Titel höchstens 32, Text höchstens 256 Zeichen (Vorgabe von VISU_PostNotification).
     */
    protected function Notify(string $title, string $text): bool
    {
        $visu = $this->ReadPropertyInteger('NotifyVisu');
        if ($visu < 10000) {
            return false;
        }
        if (mb_strlen($text) > 256) {
            $text = mb_substr($text, 0, 253) . ' …';
        }
        try {
            if (!IPS_InstanceExists($visu) || !function_exists('VISU_PostNotification')) {
                throw new Exception($this->T('gewählte Visualisierung nicht gefunden'));
            }
            $result = @VISU_PostNotification($visu, mb_substr($title, 0, 32), $text, 'Info', 0);
            if ($result === false) {
                throw new Exception($this->T('Visualisierung hat die Nachricht abgelehnt'));
            }
            return true;
        } catch (Throwable $t) {
            $this->LogMessage($this->T('Benachrichtigung nicht gesendet: %s', $t->getMessage()), KL_WARNING);
            return false;
        }
    }

    /** Wartezeit (Sekunden) zwischen erkanntem Zwischenschritt und erneutem Anstoß. */
    protected function StepWait(): int
    {
        return 50;
    }

    private function UpdatesRunning(): bool
    {
        $queue = json_decode($this->GetBuffer('Queue'), true);
        if (is_array($queue) && count($queue) > 0) {
            return true;
        }
        foreach ($this->ReadState() as $e) {
            // Batteriegeräte schlafen nach dem Update meist gleich wieder: danach bestätigt das Wach-Signal oder die nächste Prüfung
            if ($e['upd_start'] > 0 && (!$e['battery'] || time() - $e['upd_start'] <= self::VERIFY_MAX)) {
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
        $fail = static fn (string $err, string $msg = ''): array => ['ok' => false, 'result' => null, 'err' => $err, 'msg' => $msg];

        // Runde 0: Geräte mit Passwortschutz antworten ohne Anmeldung sofort mit 401 und der Aufgabe (nonce).
        // So laufen danach alle eigentlichen Abfragen in EINER gemeinsamen Runde statt nacheinander.
        $headers = [];
        $probe = [];
        foreach ($calls as $k => $call) {
            $headers[$k] = '';
            if (!empty($call['auth'])) {
                $probe[$k] = $this->RpcRequest(['timeout' => 5] + $call, '');
            }
        }
        foreach ($this->HttpBatch($probe) as $k => $res) {
            if ($res['code'] === 401) {
                $r = $this->AnswerChallenge($res['auth'], (string) $calls[$k]['password']);
                if ($r['err'] !== '') {
                    $out[$k] = $fail($r['err'], $r['msg']);
                } else {
                    $headers[$k] = $r['header'];
                }
            } elseif ($res['code'] === 200) {
                $out[$k] = $this->RpcResult($res);   // Schutz inzwischen abgeschaltet: Antwort ist schon da
            }
            // sonst (Zeitüberschreitung o. Ä.): in Runde 1 normal versuchen
        }

        // Runde 1: alle Abfragen gleichzeitig
        $requests = [];
        foreach ($calls as $k => $call) {
            if (!isset($out[$k])) {
                $requests[$k] = $this->RpcRequest($call, $headers[$k]);
            }
        }
        $retry = [];
        foreach ($this->HttpBatch($requests) as $k => $res) {
            if ($res['code'] !== 401) {
                $out[$k] = $this->RpcResult($res);
            } elseif ($headers[$k] !== '') {
                $out[$k] = $fail('auth_wrong');
            } else {
                // Gerät verlangt doch eine Anmeldung (z. B. Schutz neu eingeschaltet)
                $r = $this->AnswerChallenge($res['auth'], (string) $calls[$k]['password']);
                if ($r['err'] !== '') {
                    $out[$k] = $fail($r['err'], $r['msg']);
                } else {
                    $retry[$k] = $this->RpcRequest($calls[$k], $r['header']);
                }
            }
        }
        foreach ($this->HttpBatch($retry) as $k => $res) {
            $out[$k] = $res['code'] === 401 ? $fail('auth_wrong') : $this->RpcResult($res);
        }
        return $out;
    }

    /** @return array{header:string,err:string,msg:string} */
    private function AnswerChallenge(string $challenge, string $password): array
    {
        if ($password === '') {
            return ['header' => '', 'err' => 'auth_missing', 'msg' => ''];
        }
        $header = $this->DigestHeader($challenge, $password);
        if ($header === '') {
            return ['header' => '', 'err' => 'rpc', 'msg' => $this->T('Anmeldung: unbekanntes Verfahren')];
        }
        return ['header' => $header, 'err' => '', 'msg' => ''];
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
                CURLOPT_CONNECTTIMEOUT_MS => (int) ($r['connect_ms'] ?? 3000),
                CURLOPT_TIMEOUT           => (int) ($r['timeout'] ?? 5)
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
            if (empty($requests[$k]['quiet']) || $code === 200) {   // bei der Bereichssuche nur Treffer ins Debug
                $this->SendDebug($requests[$k]['url'] . $what, 'HTTP ' . $code . ' ' . ($code === 0 ? curl_error($ch) : $body), 0);
            }
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
                'Password'    => (string) ($r['Password'] ?? ''),
                'Battery'     => (bool) ($r['Battery'] ?? false),
                'WakeVar'     => (int) ($r['WakeVar'] ?? 0)
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
            'auto' => '', 'auto_spec' => '', 'auto_jobs' => [],
            'slot' => -1, 'upd_start' => 0, 'upd_from' => '', 'upd_target' => '', 'upd_slot' => -1, 'upd_tries' => 0, 'upd_step' => 0,
            'upd_chain' => 0, 'battery' => false, 'pending' => false, 'sure' => false];
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

    /** Text in der Sprache von Symcon (Übersetzungen in locale.json), Platzhalter wie bei sprintf. */
    private function T(string $text, ...$args): string
    {
        $text = $this->Translate($text);
        return count($args) > 0 ? vsprintf($text, $args) : $text;
    }

    /** Merker „gerade aktualisiert“? (zweite Form: Merker aus Build 11 und älter) */
    private function NoteIsDone(string $note): bool
    {
        return strpos($note, 'done|') === 0 || strpos($note, 'Aktualisiert') === 0;
    }

    /** Gespeicherter Merker (Kürzel|Angabe) als lesbarer Text. */
    private function NoteText(string $note): string
    {
        [$code, $arg] = array_pad(explode('|', $note, 2), 2, '');
        switch ($code) {
            case 'done':
                return $this->T('Aktualisiert (vorher %s)', $arg);
            case 'unconfirmed':
                return $this->T('nach %d Minuten weiter %s – erneut starten oder in der Weboberfläche nachsehen', (int) (self::VERIFY_MAX / 60), $arg);
            case 'unreachable':
                return $this->T('Update nicht bestätigt – Gerät seit dem Start nicht erreichbar');
            case 'stepfail':
                return $this->T('Zwischenschritt erledigt, nächster Schritt nicht gestartet') . ($arg !== '' ? ': ' . $arg : '');
        }
        return $note;   // Merker aus Build 11 und älter stehen schon als Text da
    }

    private function StateText(array $e): string
    {
        if ($e['upd_start'] > 0) {
            $minutes = (int) floor((time() - $e['upd_start']) / 60);
            $parts = [];
            if ($e['upd_chain'] > 0) {
                $parts[] = $this->T('Folge-Update');
            }
            if ($e['upd_tries'] > 1) {
                $parts[] = $this->T('Schritt %d', $e['upd_tries']);
            }
            $parts[] = $this->T('gestartet %s', date('H:i', $e['upd_start']));
            if ($minutes >= 3 && $e['online']) {
                $parts[] = $this->T('Gerät meldet noch %s – Updates in mehreren Schritten dauern länger', $e['ver']);
            }
            if ($e['battery'] && !$e['online']) {
                $parts[] = $this->T('Bestätigung beim nächsten Aufwachen');
            }
            return $this->T('Update auf %s läuft …', $e['upd_target'] !== '' ? $e['upd_target'] : $this->T('neueste Version')) . ' (' . implode(', ', $parts) . ')';
        }
        if ($e['pending']) {
            return $e['avail'] !== '' ? $this->T('Update auf %s vorgemerkt – startet beim nächsten Aufwachen', $e['avail'])
                : $this->T('Vorgemerkt – beim nächsten Aufwachen nach Update sehen und starten');
        }
        if ($e['err'] === 'sleep' && $e['avail'] !== '') {
            return $this->T('Schläft – Update auf %s verfügbar (zuletzt wach %s)', $e['avail'], date('d.m. H:i', $e['seen']));
        }
        switch ($e['err']) {
            case 'offline':
                return $e['seen'] > 0 ? $this->T('Nicht erreichbar (zuletzt %s)', date('d.m. H:i', $e['seen'])) : $this->T('Nicht erreichbar');
            case 'sleep':
                return $e['seen'] > 0 ? $this->T('Schläft (Batteriegerät, zuletzt wach %s)', date('d.m. H:i', $e['seen']))
                    : $this->T('Schläft (Batteriegerät, bisher nie wach erreicht)');
            case 'gen1':
                return $this->T('Gen1-Gerät – wird nicht unterstützt');
            case 'auth_missing':
                return $this->T('Passwort fehlt');
            case 'auth_wrong':
                return $this->T('Passwort falsch');
            case 'rpc':
                return $this->T('Fehler: %s', $e['msg']);
        }
        if ($e['checked'] === 0) {
            return $this->T('Noch nicht geprüft');
        }
        if ($e['note'] !== '' && $e['avail'] === '') {
            return $this->NoteText($e['note']);
        }
        if ($e['battery'] && $e['avail'] === '' && !$e['sure']) {
            return $this->T('Kein Update gemeldet (Batteriegerät – Angabe unsicher)');
        }
        if ($e['avail'] !== '') {
            if ($this->NoteIsDone($e['note'])) {
                return $this->T('Weiteres Update verfügbar (gerade aktualisiert)');
            }
            return $e['note'] !== '' ? $this->T('Update verfügbar – nicht bestätigt: %s', $this->NoteText($e['note'])) : $this->T('Update verfügbar');
        }
        return $this->T('Aktuell');
    }

    /** 0 normal, 1 Update verfügbar, 2 Update läuft, 3 Fehler, 4 gerade aktualisiert */
    private function StateKind(array $e): int
    {
        if ($e['upd_start'] > 0 || $e['pending']) {
            return 2;
        }
        if ($e['err'] === 'sleep') {
            return $e['avail'] !== '' ? 1 : 0;
        }
        if ($e['err'] !== '') {
            return 3;
        }
        if ($e['avail'] !== '') {
            return 1;
        }
        return $this->NoteIsDone($e['note']) ? 4 : 0;
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
                'Battery'     => $row['Battery'],
                'WakeVar'     => $row['WakeVar'],
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
            // Haken bei erreichbaren Geräten – und bei schlafenden Batteriegeräten (Update wird dann vorgemerkt)
            $can = (($e['online'] && $e['err'] === '') || ($row['Battery'] && $e['err'] === 'sleep')) && $e['gen'] >= 2 && !$e['pending'];
            $list[] = [
                'Select'    => $can && in_array($k, $selected, true),
                'editable'  => $can,
                'Name'      => $e['name'],
                'Host'      => $row['Host'],
                'Model'     => $e['model'],
                'Installed' => $e['ver'],
                'Available' => $e['avail'],
                'Auto'      => $this->AutoText($e),
                'State'     => $this->StateText($e),
                'rowColor'  => $colors[$this->StateKind($e)]
            ];
        }
        return $list;
    }

    private function Counts(): array
    {
        $state = $this->ReadState();
        $c = ['total' => 0, 'updates' => 0, 'offline' => 0, 'running' => 0, 'ok' => 0, 'sleeping' => 0, 'names' => []];
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
            } elseif (($e['err'] === '' || $e['err'] === 'sleep') && $e['avail'] !== '') {
                $c['updates']++;
                $c['names'][] = $e['name'];
            } elseif ($e['err'] === 'sleep') {
                $c['sleeping']++;
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
            return $this->T('Noch keine Geräte – deshalb steht oben „Instanz ist inaktiv“. Unten „Geräte im Netzwerk suchen“ drücken (oder unter „Geräte verwalten“ von Hand hinzufügen), dann „Änderungen übernehmen“ – danach ist die Instanz aktiv.');
        }
        $last = $this->ReadAttributeInteger('LastCheck');
        $parts = ['● ' . $this->T('%d Geräte', $c['total'])];
        if ($last === 0) {
            return $parts[0] . '  ·  ' . $this->T('noch nicht geprüft – die erste Abfrage läuft gleich von selbst');
        }
        $problems = $c['total'] - $c['updates'] - $c['running'] - $c['offline'] - $c['ok'] - $c['sleeping'];
        $parts[] = $c['updates'] > 0 ? $this->T('%d mit Update', $c['updates'])
            : ($c['ok'] + $c['sleeping'] === $c['total'] ? $this->T('alle aktuell') : $this->T('kein Update'));
        if ($problems > 0) {
            $parts[] = $this->T('%d mit Fehler', $problems);
        }
        if ($c['sleeping'] > 0) {
            $parts[] = $this->T('%d schlafen', $c['sleeping']);
        }
        if ($c['running'] > 0) {
            $parts[] = $this->T('%d Update(s) laufen', $c['running']);
        }
        if ($c['offline'] > 0) {
            $parts[] = $this->T('%d nicht erreichbar', $c['offline']);
        }
        $parts[] = $this->T('letzte Prüfung %s', date('d.m. H:i', $last));
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
        foreach ([$this->T('Gerät'), $this->T('Modell'), $this->T('Installiert'), $this->T('Verfügbar'), $this->T('Auto-Update'), $this->T('Status')] as $head) {
            $html .= '<th style="' . $cell . '">' . $head . '</th>';
        }
        $html .= '</tr>';
        foreach ($items as [$kind, $name, $e]) {
            $style = $colors[$kind] !== '' ? 'color:' . $colors[$kind] . ';font-weight:bold;' : '';
            $html .= '<tr>';
            foreach ([$name, $e['model'], $e['ver'], $e['avail'] !== '' ? $e['avail'] : '–', $this->AutoText($e)] as $text) {
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

    private function SettingsSummary(): string
    {
        $hours = max(0, $this->ReadPropertyInteger('CheckInterval'));
        $parts = [$hours === 0 ? $this->T('keine automatische Prüfung') : ($hours === 24 ? $this->T('täglich') : $this->T('alle %d Stunden', $hours))];
        $parts[] = $this->ReadPropertyInteger('NotifyVisu') >= 10000 ? $this->T('Push-Nachricht an') : $this->T('Push-Nachricht aus');
        return implode(', ', $parts);
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
