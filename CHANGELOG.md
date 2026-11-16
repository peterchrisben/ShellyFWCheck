# Changelog

## Version 1.0

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
