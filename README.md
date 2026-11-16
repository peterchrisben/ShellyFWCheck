# ShellyFWCheck

🇬🇧 [English documentation](README.en.md)

Firmware-Übersicht und Updates für Shelly-Geräte in Symcon: **eine Instanz zeigt für alle Shellys die installierte und die verfügbare Firmware** und startet Updates auf Knopfdruck – einzeln, für eine Auswahl oder für alle. Alles läuft lokal über die Geräte selbst, ohne Shelly-App und ohne Cloud-Konto.

Aktuelle Version: 1.0 (Build 12)

**Prüfen verändert nichts. Updates starten nur auf Knopfdruck mit Rückfrage, nie automatisch.**

## Voraussetzungen

- Symcon ab Version 8.0
- Shelly-Geräte ab **Gen2** (Plus, Pro, Mini, Gen3, Gen4). Gen1-Geräte werden erkannt, aber nicht unterstützt.
- Die Geräte müssen von Symcon aus per IP erreichbar sein und selbst ins Internet dürfen (sie laden die Firmware direkt vom Hersteller).

## Installation

- **Module Store:** nach „Shelly Firmware-Check“ suchen und installieren. Das Modul steht derzeit im **Beta-Kanal** – Beta-Module müssen dafür in den Store-Einstellungen zugelassen sein.
- **oder Modulverwaltung:** Hinzufügen → `https://github.com/peterchrisben/ShellyFWCheck`

## Einrichtung

1. Instanz hinzufügen → **Shelly Firmware-Check**.
2. Sind die Geräte passwortgeschützt: unter **Passwort** das **Master-Passwort** eintragen.
3. **Geräte im Netzwerk suchen** drücken – gefundene Shellys erscheinen blau markiert unter **Geräte verwalten**. Geräte, die nicht gefunden werden (anderes Netz/VLAN), dort über „Hinzufügen“ mit ihrer IP-Adresse eintragen.
4. **Änderungen übernehmen**. Kurz danach stehen Name, Modell, installierte und verfügbare Version unten in der **Übersicht**.

Solange kein Gerät eingetragen ist, zeigt Symcon „Instanz ist inaktiv“ – das ist normal.

**Shellys in einem anderen Netz** (Gäste-, IoT-Netz, Gartenhaus): Die normale Suche findet nur Geräte im Netz von Symcon. Unter „Geräte verwalten“ lassen sich bei „Weitere Netze durchsuchen“ zusätzliche Adressbereiche eintragen, z. B. Von `192.168.20.1`, Bis `192.168.20.254`. Bleibt „Bis“ leer, wird das ganze Netz der Adresse durchsucht; auch die Kurzform `192.168.20.0/24` geht. Höchstens 1024 Adressen je Zeile. Symcon muss das andere Netz erreichen können. Gesucht wird nur beim Knopf „Geräte im Netzwerk suchen“.

**Batteriegeräte:** Haken „Batterie“ in der Geräteliste setzen. Das Gerät wird dann als „Schläft“ gezeigt statt als Fehler und zählt nicht bei „Nicht erreichbar“ mit.

Hat ein Gerät ein anderes Passwort: unter „Geräte verwalten“ in seiner Zeile den Haken **Eigenes PW** setzen und das Passwort dort eintragen. Der Benutzername ist bei Shelly immer „admin“.

## Bedienung

| Knopf | Was passiert |
|---|---|
| Jetzt prüfen | fragt alle aktiven Geräte ab |
| Geräte im Netzwerk suchen | sucht Shellys per mDNS (und in den zusätzlichen Adressbereichen); neue Geräte und geänderte IP-Adressen werden unter „Geräte verwalten“ eingetragen (wirksam nach „Änderungen übernehmen“) |
| Ausgewählte aktualisieren | startet die Updates für alle Geräte mit Haken in der Spalte „Auswahl“ der Übersicht (gestartet werden nur Geräte mit verfügbarem Update) |
| Alle Updates starten | startet alle verfügbaren Updates nacheinander, mit Pause dazwischen (Standard 20 Sekunden) |

Nach dem Start eines Updates fragt das Modul jede Minute nach, bis das Gerät die neue Version meldet („Aktualisiert“). Manche Updates laufen im Gerät in mehreren Schritten: Nach dem ersten Anstoß startet das Gerät neu, meldet aber weiter die alte Version. Das Modul erkennt diesen Zwischenschritt und stößt das Update nach etwa einer Minute selbst erneut an (Status „Schritt 2“). Bleibt die Version 15 Minuten unverändert, wird das Gerät wieder freigegeben („Update verfügbar – nicht bestätigt …“) und kann erneut gestartet werden. Schließt das Gerät das Update später von selbst ab, zeigt die nächste Abfrage „Aktuell“.

Ein Klick auf die **IP-Adresse** in der Übersicht öffnet die Weboberfläche des Geräts in einem neuen Fenster.

Die Spalte **Auto-Update** zeigt, ob im Gerät selbst das automatische Update eingeschaltet ist (Aus / Stable / Beta). Das ist ein Zeitplan im Gerät; ist er an, aktualisiert sich das Gerät von selbst, unabhängig von diesem Modul.

Umstellen: Haken bei den Geräten setzen und in der Zeile „Auto-Update im Gerät für die Ausgewählten“ **Aus** oder **Stable (täglich 0 Uhr)** drücken. Das Modul entfernt dabei alle bisherigen Update-Zeitpläne des Geräts (auch abgeschaltete Reste) und legt bei „Stable“ genau einen neuen an, im selben Aufbau wie die Shelly-Weboberfläche. Andere Zeitpläne (z. B. Schaltzeiten) bleiben unberührt. Beta lässt sich nur per Skript einschalten.

**Folge-Updates:** Bietet ein Gerät direkt nach einem Update schon das nächste an (z. B. 2.0.0 → 2.0.3 → 2.2.0), macht das Modul von selbst weiter, bis die neueste Version installiert ist (höchstens fünf Folge-Updates). Abschaltbar unter „Updates“.

**Push-Nachricht:** Unter „Prüfung, Nachricht und Anzeige“ lässt sich eine Kachel-Visualisierung auswählen. Findet eine Prüfung ein neues Update, bekommt sie eine Nachricht („Update verfügbar: Name (Version)“), je Update nur einmal. Ohne Auswahl gibt es keine Nachricht. Mit „Test-Nachricht senden“ lässt sich das ausprobieren.

**Update-Verlauf:** Ganz unten im Formular stehen die letzten 50 Versionswechsel mit Zeitpunkt, Gerät, alter und neuer Version und Auslöser (Modul, Folge-Update oder „Gerät selbst / von außen“ für Auto-Update, Weboberfläche und App).

**Bedienung aus der Visualisierung:** Mit dem Haken „Bedienung aus der Visualisierung freigeben“ unter „Updates“ legt das Modul die Variable „Befehl“ an (Jetzt prüfen / Alle Updates starten). Ohne Freigabe gibt es die Variable nicht. Achtung: „Alle Updates starten“ läuft aus der Visualisierung ohne Rückfrage.

Status in der Übersicht: Aktuell · Update verfügbar (gelb) · Update läuft (blau) · Aktualisiert (grün) · Nicht erreichbar / Passwort fehlt / Passwort falsch / Fehler (rot) · Schläft (Batteriegeräte, ohne Farbe).

## Variablen

| Variable | Inhalt |
|---|---|
| Updates verfügbar | Anzahl der Geräte mit Update – z. B. als Auslöser für eine Benachrichtigung |
| Geräte | Anzahl der aktiven Geräte |
| Nicht erreichbar | Anzahl der Geräte, die bei der letzten Prüfung nicht geantwortet haben |
| Geräte mit Update | Namen der Geräte mit Update, durch Komma getrennt |
| Letzte Prüfung | Zeitpunkt der letzten Abfrage |
| Übersicht | Tabelle aller Geräte für die Visualisierung (abschaltbar) |
| Befehl | nur mit Freigabe: Jetzt prüfen / Alle Updates starten |

## Befehle für Skripte

```php
SFW_CheckAll($id);                      // alle Geräte prüfen
SFW_UpdateDevice($id, '192.168.1.50');  // Update für ein Gerät starten
SFW_UpdateSelected($id, '["192.168.1.50","192.168.1.51"]'); // Updates für mehrere Geräte nacheinander starten
SFW_UpdateAll($id);                     // alle verfügbaren Updates nacheinander starten, liefert die Anzahl
SFW_Discover($id);                      // Gerätesuche (trägt nur ins offene Formular ein)
SFW_SetAutoUpdate($id, '["192.168.1.50"]', 'stable'); // Auto-Update im Gerät: 'off', 'stable' oder 'beta'
SFW_GetDevices($id);                    // Stand aller Geräte als JSON
SFW_GetHistory($id);                    // Update-Verlauf als JSON (neueste zuerst); by: module, chain (Folge-Update), external (Gerät selbst / von außen)
```

## Hinweise

- Während eines Updates startet das Gerät neu; ob ein Relais dabei seinen Zustand behält, hängt von der Einstellung im Gerät ab.
- Es wird nur die stabile Firmware angeboten, keine Beta-Versionen.
- Die Passwörter liegen wie alle Einstellungen in der Symcon-Konfiguration.

## Lizenz

CC BY-NC-SA 4.0 – Peter Chrisben

Shelly ist eine Marke ihres jeweiligen Inhabers. ShellyFWCheck ist ein privates Projekt und steht in keiner Verbindung zum Hersteller.

## Sprache / Language

Das Modul gibt es auf Deutsch und Englisch – Symcon wählt die Sprache von selbst. Variablen behalten den Namen aus der Sprache, in der die Instanz angelegt wurde.

English documentation: [README.en.md](README.en.md)
