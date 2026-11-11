# lager/core/inventur_protokoll.php – Abschluss und Protokoll

Seit 11.11.2026.

## `inv_abschliessen()`
Abschließen geht nur unter diesen Bedingungen:
- Die Inventur steht auf „auswerten“, die Zählung ist also beendet.
- Alle Plätze sind gezählt.
- Keine Abweichung ist mehr offen oder steht auf Nachzählen. „Bewusst offen lassen“ mit Notiz zählt als entschieden.

Danach passiert:
1. Jedes gezählte Gebinde bekommt den Platz, auf dem es gezählt wurde (`inv_platz_setzen`, Protokoll in `lg_charge_log`). Kisten bleiben Kisten.
2. Der Status wird „abgeschlossen“, erst danach werden die Sperren der Plätze freigegeben.
3. Das Protokoll wird als PDF und CSV in `BX_UPLOADS` abgelegt (`protokoll_pdf` / `protokoll_csv`).

## Protokoll
Inhalt:
- Kopf: Inventur, Stichtag, Umfang, Zähler, abgeschlossen von/am
- Summe der Wertkorrekturen
- je Artikel/Charge: Soll, Ist, Differenz, Wert, Art, Ursache/Ergebnis
- Umlagerungen
- Liste der neu etikettierten Gebinde (Inventarnummer, Charge, MHD, Menge, gebucht als Charge)

Weitere Punkte:
- Das PDF trägt den Pflicht-Hinweis „maschinell erstellt …“.
- Die CSV ist mit `;` getrennt, UTF-8 mit BOM, für Excel, Buchhaltung und Steuerberater.
- Überholte Zeilen erscheinen nicht. Gemeint ist „nicht gefunden“, das später doch gezählt wurde.
