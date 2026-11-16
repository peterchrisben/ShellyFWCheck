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
| Auto-Update umstellen | Aus / Stable an Plug M #2, Kontrolle in der Weboberfläche | Build 8 | ok (16.11., Weboberfläche zeigt die Einstellung) |
| Push-Nachricht | Kachel-Visualisierung wählen, übernehmen, „Test-Nachricht senden“ – kommt sie auf dem Handy an? | Build 9 | offen |
| Folge-Updates | Gerät mit Zwischenversion: läuft es nach dem ersten Update von selbst weiter („Folge-Update“ im Status)? | Build 9, beim nächsten solchen Update | offen |
| Suche in weiteren Netzen | Unter „Geräte verwalten“ einen Bereich eintragen (z. B. das eigene Netz .1–.254), übernehmen, suchen – Dauer? Funde wie bei der normalen Suche? | Build 11 | offen |
| Batteriegerät | Haken „Batterie“ setzen – Status „Schläft“, nicht rot | Build 11 | offen |
| Update-Verlauf | nach dem nächsten Update: Eintrag im Abschnitt „Update-Verlauf“ | Build 11 | offen |
| Befehl aus der Visu | Freigabe setzen, Variable „Befehl“ in der Kachel-Visu: wird sie als Auswahl gezeigt, löst sie aus? Auch in der App ansehen | Build 11 | offen |
| Nach Build 12 alles wie vorher | Formular öffnen, „Jetzt prüfen“: Texte unverändert deutsch, Status-Spalte und Update-Verlauf stimmen (auch alte Einträge) | Build 12 | offen |
| Englisch | nur wer mag: Symcon-Konsole auf Englisch stellen – Formular und Meldungen englisch? | Build 12 | offen |
| Befehl aus der Visu (Build 13) | Haken „Bedienung aus der Visualisierung freigeben“ setzen, übernehmen: keine Fehlermeldung, Variable „Befehl“ da, in Kachel-Visu und App auswählbar | Build 13 | offen |
| H&T: Wach-Signal (Build 14) | Beim H&T in „Geräte verwalten“ Wach-Signal = Temperatur-Variable wählen, übernehmen. Taste drücken: Debug „Wach-Signal“, Zeile zeigt Version und ggf. „Update verfügbar“ | Build 14 | ok (16.11., drei H&T) |
| H&T: vorgemerktes Update (Build 14) | Bei schlafendem H&T mit Update: Haken + „Ausgewählte aktualisieren“ → „vorgemerkt“ (blau). Beim nächsten Aufwachen startet es (Display „OTA“), danach „Aktualisiert“ | nächstes H&T-Update | ok (16.11., Build 16, 1.7.1 → 2.0.3) |
