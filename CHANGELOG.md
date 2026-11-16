# Changelog

## Version 1.0

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
