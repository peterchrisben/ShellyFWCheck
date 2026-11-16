# ShellyFWCheck

Firmware-Übersicht und Updates für Shelly-Geräte in Symcon: **eine Instanz zeigt für alle Shellys die installierte und die verfügbare Firmware** und startet Updates auf Knopfdruck – einzeln oder alle nacheinander. Alles läuft lokal über die Geräte selbst, ohne Shelly-App und ohne Cloud-Konto.

Aktuelle Version: 1.0 (Build 2)

**Prüfen verändert nichts. Updates starten nur auf Knopfdruck mit Rückfrage, nie automatisch.**

## Voraussetzungen

- Symcon ab Version 8.0
- Shelly-Geräte ab **Gen2** (Plus, Pro, Mini, Gen3, Gen4). Gen1-Geräte werden erkannt, aber nicht unterstützt.
- Die Geräte müssen von Symcon aus per IP erreichbar sein und selbst ins Internet dürfen (sie laden die Firmware direkt vom Hersteller).

## Einrichtung

1. Instanz hinzufügen → **Shelly Firmware-Check**.
2. Sind die Geräte passwortgeschützt: unter **Passwort** das **Master-Passwort** eintragen.
3. **Geräte im Netzwerk suchen** drücken – gefundene Shellys erscheinen blau markiert unter **Geräte verwalten**. Geräte, die nicht gefunden werden (anderes Netz/VLAN), dort über „Hinzufügen“ mit ihrer IP-Adresse eintragen.
4. **Änderungen übernehmen**. Kurz danach stehen Name, Modell, installierte und verfügbare Version unten in der **Übersicht**.

Solange kein Gerät eingetragen ist, zeigt Symcon „Instanz ist inaktiv“ – das ist normal.

Hat ein Gerät ein anderes Passwort: unter „Geräte verwalten“ in seiner Zeile den Haken **Eigenes PW** setzen und das Passwort dort eintragen. Der Benutzername ist bei Shelly immer „admin“.

## Bedienung

| Knopf | Was passiert |
|---|---|
| Jetzt prüfen | fragt alle aktiven Geräte ab |
| Geräte im Netzwerk suchen | sucht Shellys per mDNS; neue Geräte und geänderte IP-Adressen werden unter „Geräte verwalten“ eingetragen (wirksam nach „Änderungen übernehmen“) |
| Markiertes Gerät aktualisieren | startet das Update für die in der Übersicht markierte Zeile |
| Alle Updates starten | startet alle verfügbaren Updates nacheinander, mit Pause dazwischen (Standard 20 Sekunden) |

Nach dem Start eines Updates fragt das Modul jede Minute nach, bis das Gerät die neue Version meldet („Aktualisiert“). Bleibt die Version 15 Minuten unverändert, steht „Update nicht bestätigt“ in der Zeile.

Status in der Übersicht: Aktuell · Update verfügbar (gelb) · Update läuft (blau) · Aktualisiert (grün) · Nicht erreichbar / Passwort fehlt / Passwort falsch / Fehler (rot).

Batteriegeräte schlafen meist und zeigen dann „Nicht erreichbar“. Wer sie nicht in der Übersicht haben möchte, nimmt den Haken **Aktiv** heraus.

## Variablen

| Variable | Inhalt |
|---|---|
| Updates verfügbar | Anzahl der Geräte mit Update – z. B. als Auslöser für eine Benachrichtigung |
| Geräte | Anzahl der aktiven Geräte |
| Nicht erreichbar | Anzahl der Geräte, die bei der letzten Prüfung nicht geantwortet haben |
| Geräte mit Update | Namen der Geräte mit Update, durch Komma getrennt |
| Letzte Prüfung | Zeitpunkt der letzten Abfrage |
| Übersicht | Tabelle aller Geräte für die Visualisierung (abschaltbar) |

## Befehle für Skripte

```php
SFW_CheckAll($id);                      // alle Geräte prüfen
SFW_UpdateDevice($id, '192.168.1.50');  // Update für ein Gerät starten
SFW_UpdateAll($id);                     // alle verfügbaren Updates nacheinander starten, liefert die Anzahl
SFW_Discover($id);                      // Gerätesuche (trägt nur ins offene Formular ein)
SFW_GetDevices($id);                    // Stand aller Geräte als JSON
```

## Hinweise

- Während eines Updates startet das Gerät neu; ob ein Relais dabei seinen Zustand behält, hängt von der Einstellung im Gerät ab.
- Es wird nur die stabile Firmware angeboten, keine Beta-Versionen.
- Die Passwörter liegen wie alle Einstellungen in der Symcon-Konfiguration.

## Lizenz

CC BY-NC-SA 4.0 – Peter Chrisben

Shelly ist eine Marke ihres jeweiligen Inhabers. ShellyFWCheck ist ein privates Projekt und steht in keiner Verbindung zum Hersteller.
