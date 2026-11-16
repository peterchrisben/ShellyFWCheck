# Changelog

## Version 1.0

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
