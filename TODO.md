# Test-Liste

| Was | Wie prüfen | Wann | Status |
|---|---|---|---|
| Gerätesuche (mDNS) | „Geräte im Netzwerk suchen“ – werden alle Shellys gefunden? | sofort | ok (16.11., 11 Geräte) |
| Passwort-Spalte in der Liste | Wird das Passwort in der Zeile verdeckt angezeigt? | sofort | ok (16.11.) |
| Kein orangener Balken nach Abfrage | „Änderungen übernehmen“, 10 Sekunden warten, „Jetzt prüfen“ – Balken darf nicht erscheinen | Build 2 | ok (16.11.) |
| Suche klappt „Geräte verwalten“ auf | bei neuem Gerät: Panel öffnet sich, Zeile blau | Build 2 | offen |
| Master-Passwort / eigenes Passwort | Gerät mit Master-PW, eines mit eigenem PW, eines mit falschem PW („Passwort falsch“) | sofort | offen |
| „Ausgewählte aktualisieren“ | Haken bei einem/mehreren Geräten, Knopf drücken – starten genau diese? | Build 3 | ok (16.11.) |
| Haken bleiben bei „Jetzt prüfen“ | Haken setzen, „Jetzt prüfen“ – Haken noch da? | Build 3 | offen |
| Update-Kontrolle | Status wechselt von „Update läuft“ auf „Aktualisiert“ (nach 1–3 Minuten) | sobald ein Update verfügbar ist | ok (16.11., 2 Geräte) |
| „Alle Updates starten“ | Geräte starten nacheinander im eingestellten Abstand | sobald mehrere Updates verfügbar sind | offen |
| Variable „Übersicht“ | Tabelle in der Kachel-Visu und in der App lesbar (hell/dunkel)? | sofort | offen |
| Batteriegerät | Zeigt „Nicht erreichbar“, stört den Rest nicht | sofort | offen |
| Link auf die Weboberfläche | Klick auf eine IP-Adresse in der Übersicht – öffnet sich das richtige Gerät (auch bei nicht markierter Zeile)? | Build 4 | offen |
| Meldung bei „Jetzt prüfen“ | Erscheint „Abfrage läuft …“ sofort und danach „Abfrage fertig: …“? | Build 5 | ok (16.11., Meldung erscheint während der Abfrage) |
| Update in mehreren Schritten | Gerät mit Zwischenschritt (wie S2PMG3 2.1.1 → 2.2.0): stößt das Modul nach ca. 1–2 Minuten selbst „Schritt 2“ an? | Build 6, beim nächsten solchen Update | offen (von Hand bestätigt 16.11.: zweiter Anstoß bringt 2.2.0 in 2 Minuten) |
| Spalte „Auto-Update“ | zeigt den Stand der Geräte | Build 7 | ok (16.11.); Plug M #2 muss mit Build 8 „Beta“ zeigen |
| Auto-Update umstellen | Plug M #2 anhaken → „Aus“: Zeile zeigt „Aus“, Weboberfläche zeigt „Disable auto update“. Ein Gerät → „Stable“: Weboberfläche zeigt „Enable update to stable version“ | Build 8 | offen |
