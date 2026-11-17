# Changelog

## Version 1.0

### Build 20 (17.11.2026)

#### Behoben
- Mehrere Batteriegeräte, die kurz nacheinander aufwachen (z. B. per Taste im Abstand von 1–2 Sekunden geweckt): Das nächste Gerät wartet jetzt, bis die Abfrage des vorigen fertig ist, statt erst 2 Sekunden später einen neuen Versuch zu starten – da schlief es meist schon wieder. Läuft gerade die große Abfrage aller Geräte, wird bis zu 10 Sekunden nach dem Aufwachen im Sekundentakt nachgefasst; danach bleibt das Gerät vorgemerkt.
- Ein Wach-Signal konnte verloren gehen, wenn mehrere Geräte in ungünstiger Reihenfolge aufwachten.

#### Dokumentation
- README: Tipp für mehrere Batteriegeräte (erst alle vormerken, dann nacheinander wecken).

### Build 19 (17.11.2026)

#### Behoben
- Nach dem Vormerken bzw. Start eines Batteriegeräts blieb der Haken nach dem Update stehen. Vorgemerkte Geräte werden jetzt aus der Auswahl genommen – ihr Haken zeigt nur noch die Vormerkung.
- Der Titel „Update-Verlauf (n)“ zählte neue Einträge erst nach dem Neuöffnen des Formulars mit.

#### Dokumentation
- Shelly Flood Gen4 bestätigt: von Hand eingetragen, noch nie wach gewesen, vorgemerkt – per Taste geweckt, in einer Sekunde erkannt, geprüft und aktualisiert (1.7.99 → 2.0.3).

### Build 18 (17.11.2026)

#### Geändert
- Ein Batteriegerät, das noch nie wach war (z. B. gerade von Hand eingetragen), lässt sich in der Übersicht auswählen und vormerken. Beim ersten Aufwachen erkennt das Modul das Gerät, fragt nach einem Update und startet es gleich. Stellt sich ein Gen1-Gerät heraus, wird die Vormerkung verworfen.
- Vorgemerkte Batteriegeräte behalten in der Übersicht ihren Haken (blau). Haken herausnehmen hebt die Vormerkung sofort auf – ohne „Änderungen übernehmen“. Status-Text und Hinweis unter der Übersicht sagen das.

### Build 17 (16.11.2026)

#### Geändert
- Batteriegeräte werden beim Aufwachen direkt nach einem Update gefragt – an echten Shelly H&T beantwortet das Gerät die Abfrage in unter einer Sekunde. Damit ist der Update-Stand verlässlich; vorgemerkte Updates starten gleich danach, ohne Fehlversuch (der H&T lehnt den Update-Befehl ohne vorherige Abfrage ab).
- Ein aktualisiertes Batteriegerät zeigt weiter „Aktualisiert (vorher …) – schläft“ (grün), auch wenn es wieder eingeschlafen ist.

#### Dokumentation
- Batteriegeräte an drei echten Shelly H&T Gen3 bestätigt: Wach-Signal, Vormerken und Update beim Aufwachen (1.7.1 → 2.0.3).

### Build 16 (16.11.2026)

#### Geändert
- Vorgemerkte Batteriegeräte bekommen beim Aufwachen sofort den Update-Befehl (vorher erst eine Abfrage). Lehnt das Gerät ab, fragt das Modul nach und startet ein vorhandenes Update gleich danach.
- Update für Batteriegeräte wird auch aus Geräten gleichen Modells abgeleitet (z. B. ein anderer H&T läuft schon mit neuerer Firmware).

#### Behoben
- Batteriegerät wurde nach dem Aufwachen als „Aktuell“ gezeigt, obwohl ein Update existiert: Das Gerät meldet direkt nach dem Aufwachen keine Updates. Jetzt „Kein Update gemeldet (Batteriegerät – Angabe unsicher)“, wenn nichts bekannt ist.

### Build 15 (16.11.2026)

#### Geändert
- Schlafendes Batteriegerät mit Haken + „Ausgewählte aktualisieren“ wird jetzt auch vorgemerkt, wenn das Modul noch kein Update kennt: Beim Aufwachen sieht es nach und startet ein vorhandenes Update sofort; ist das Gerät aktuell, ist die Vormerkung erledigt. Bisher kam „kein Update verfügbar“. „Alle Updates starten“ merkt weiterhin nur Geräte mit bekanntem Update vor.

### Build 14 (16.11.2026)

#### Neu
- Batteriegeräte (z. B. Shelly H&T): Spalte „Wach-Signal“ in „Geräte verwalten“. Wird die gewählte Variable aktualisiert (z. B. die Temperatur aus dem Shelly-Modul), fragt das Modul sofort nur dieses Gerät ab.
- Updates für schlafende Batteriegeräte werden vorgemerkt und starten beim nächsten Aufwachen von selbst.

#### Geändert
- Batteriegeräte werden nur noch kurz nach dem im Gerät gemerkten Update gefragt (statt der langen Abfrage beim Hersteller) – so reicht die kurze Wachzeit.
- Ein schlafendes Batteriegerät mit bekanntem Update wird gelb mit „Schläft – Update auf … verfügbar“ gezeigt und zählt bei „Updates verfügbar“ mit.
- Nach einem Update wartet das Modul bei Batteriegeräten bis zu 24 Stunden auf die Bestätigung beim Aufwachen.
- `SFW_GetDevices` liefert zusätzlich `battery` und `pending`.

#### Behoben
- Batteriegerät, das nach der ersten Antwort sofort wieder einschlief, wurde rot als „Nicht erreichbar“ gezeigt.

### Build 13 (16.11.2026)

#### Behoben
- Haken „Bedienung aus der Visualisierung freigeben“: „Änderungen übernehmen“ brach mit „Der Eintrag im Parameter OPTIONS enthält unbekannte Unterparameter“ ab, die Variable „Befehl“ wurde nicht angelegt. Die Auswahl-Optionen enthalten jetzt nur noch die Angaben, die Symcon für eine Aufzählung kennt. (Gemeldet im Forum, danke!)

### Build 12 (16.11.2026)

#### Neu
- Englische Übersetzung: Formular, Meldungen, Status-Texte, Tabelle, Push-Nachricht und Variablennamen erscheinen auf Englisch, wenn Symcon auf Englisch läuft. Auf Deutsch ändert sich nichts.

#### Geändert
- `SFW_GetHistory` liefert den Auslöser (`by`) jetzt als festes Kürzel: `module`, `chain` (Folge-Update) oder `external` (Gerät selbst / von außen). Im Formular steht weiterhin der Text. Ältere Einträge behalten ihren Text.

### Build 11 (16.11.2026)

#### Neu
- Suche in weiteren Netzen: Liste „Weitere Netze durchsuchen“ unter „Geräte verwalten“ (Von / Bis / Bezeichnung, höchstens 1024 Adressen je Zeile). „Geräte im Netzwerk suchen“ durchsucht damit zusätzlich diese Adressbereiche.
- Batteriegeräte: Haken „Batterie“ je Gerät – Anzeige „Schläft“ statt „Nicht erreichbar“, zählt nicht als Fehler.
- Update-Verlauf: die letzten 50 Versionswechsel im Formular, auch solche, die das Gerät selbst oder die Weboberfläche ausgelöst hat. Für Skripte: `SFW_GetHistory`.
- Bedienung aus der Visualisierung: Variable „Befehl“ (Jetzt prüfen / Alle Updates starten), nur mit Freigabe im Formular.

### Build 10 (16.11.2026)

#### Geändert
- Einstellungen im Formular nach Thema getrennt: „Prüfung, Nachricht und Anzeige“ (Abstand der Prüfung, Push-Nachricht, Tabelle) und „Updates“ (Folge-Updates, Pause zwischen den Geräten). Die Titel zeigen den aktuellen Stand.

### Build 9 (16.11.2026)

#### Neu
- Folge-Updates: Bietet ein Gerät direkt nach einem Update das nächste an, macht das Modul von selbst weiter bis zur neuesten Version (höchstens fünf hintereinander). Einstellung „Nach einem Update gleich weiter …“ unter „Abfrage und Anzeige“, Standard an.
- Push-Nachricht bei neu gefundenem Update an eine wählbare Kachel-Visualisierung; ohne Auswahl keine Nachricht. Knopf „Test-Nachricht senden“.

### Build 8 (16.11.2026)

#### Neu
- Auto-Update im Gerät umstellen: Haken setzen, dann „Aus“ oder „Stable (täglich 0 Uhr)“. Bisherige Update-Zeitpläne des Geräts werden dabei entfernt, andere Zeitpläne bleiben unberührt. Für Skripte: `SFW_SetAutoUpdate($id, $Hosts, $Mode)`.

#### Geändert
- Haken in der Spalte „Auswahl“ lassen sich jetzt bei allen erreichbaren Geräten setzen (für das Auto-Update); „Ausgewählte aktualisieren“ startet weiterhin nur Geräte mit verfügbarem Update.

#### Behoben
- Spalte „Auto-Update“ zeigte „Beta (pausiert)“, obwohl im Gerät ein eingeschalteter Beta-Zeitplan lag: In manchen Geräten liegen abgeschaltete alte Einträge, ausgewertet wurde der erste statt des eingeschalteten.

### Build 7 (16.11.2026)

#### Neu
- Spalte „Auto-Update“ in der Übersicht und in der Tabelle: zeigt, ob im Gerät das automatische Firmware-Update eingeschaltet ist (Aus / Stable / Beta). Nur Anzeige; `SFW_GetDevices` liefert zusätzlich `autoUpdate` und `autoUpdateSchedule`.

### Build 6 (16.11.2026)

#### Neu
- Mehrstufige Updates: Erledigt ein Gerät nach dem Start nur einen Zwischenschritt (Neustart, meldet aber weiter die alte Version), erkennt das Modul das und stößt das Update nach etwa einer Minute selbst erneut an (höchstens 4 Anstöße). Im Status steht dann „Schritt 2“.

#### Geändert
- Status während eines Updates zeigt nach 3 Minuten, dass das Gerät noch die alte Version meldet (Updates in mehreren Schritten dauern länger).
- Wird ein Update nach 15 Minuten nicht bestätigt, steht in der Zeile klar, was zu tun ist (erneut starten oder in der Weboberfläche nachsehen).

### Build 5 (16.11.2026)

#### Neu
- „Jetzt prüfen“ meldet unter den Knöpfen „Abfrage läuft …“ und danach das Ergebnis (Anzahl Geräte, Updates, nicht erreichbar).

#### Geändert
- Abfrage schneller: Geräte mit und ohne Passwort werden jetzt in einer gemeinsamen Runde geprüft (vorher nacheinander, an 11 Geräten ca. 23 Sekunden).
- Nach einem bestätigten Update wird sofort geprüft, ob das Gerät ein weiteres Update anbietet (Shelly aktualisiert teils in Stufen) – Status „Weiteres Update verfügbar“.
- Wartezeit je Gerät bei der Update-Abfrage von 20 auf 45 Sekunden erhöht (die Geräte fragen selbst beim Hersteller nach und brauchen teils über 15 Sekunden).

### Build 4 (16.11.2026)

#### Neu
- Klick auf die IP-Adresse in der Übersicht öffnet die Weboberfläche des Geräts.

### Build 3 (16.11.2026)

#### Neu
- Übersicht mit Spalte „Auswahl“: Haken bei den Geräten setzen, die aktualisiert werden sollen, dann „Ausgewählte aktualisieren“. Haken gibt es nur bei Geräten mit verfügbarem Update.
- `SFW_UpdateSelected($id, $Hosts)` für Skripte.

#### Geändert
- Knopf „Markiertes Gerät aktualisieren“ entfällt (ersetzt durch die Auswahl mit Haken).

### Build 2 (16.11.2026)

#### Geändert
- Formular aufgeteilt: Einstellungen unter „Geräte verwalten“ (Aktiv, IP-Adresse, Passwort), der aktuelle Stand in einer eigenen „Übersicht“ bei den Knöpfen.
- Gerätesuche zeigt sofort „Suche läuft …“ und klappt „Geräte verwalten“ mit den Funden auf.
- Überblickszeile erklärt bei leerer Liste, warum die Instanz inaktiv ist.

#### Behoben
- Nach jeder Abfrage erschien „Die Instanz hat noch ungespeicherte Änderungen“, obwohl nichts geändert wurde.
- „Markiertes Gerät aktualisieren“ brach mit „Undefined variable $Devices“ ab: Knöpfe können nur auf Listen im selben Formularbereich zugreifen – die Übersicht liegt jetzt bei den Knöpfen.
- Überblickszeile zeigte vor der ersten Abfrage „alle aktuell“; Geräte mit Fehler (z. B. Passwort falsch) werden jetzt mitgezählt.

### Build 1 (16.11.2026)

#### Neu
- Erste Version: Firmware-Übersicht für Shelly-Geräte ab Gen2 (Plus, Pro, Mini, Gen3, Gen4) – installierte und verfügbare Version je Gerät, lokal ohne Cloud-Konto.
- Updates auf Knopfdruck: einzeln (markierte Zeile) oder alle nacheinander mit einstellbarer Pause; danach wird geprüft, ob die neue Version läuft.
- Gerätesuche im Netzwerk (mDNS) und Eintrag von Hand per IP-Adresse.
- Passwortschutz: Master-Passwort für alle Geräte, je Gerät wahlweise ein eigenes Passwort (Digest-Anmeldung SHA-256).
- Variablen: Updates verfügbar, Geräte, Nicht erreichbar, Geräte mit Update, Letzte Prüfung, Übersicht (Tabelle).
- Automatische Prüfung im einstellbaren Abstand (Standard 24 Stunden). Updates starten nie von selbst.
