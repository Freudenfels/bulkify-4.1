# module/dienstleistung/auftrag.php - DL-Auftrag (Detail)

Route `?p=dl_auftrag&id=<ID>`. Zeigt Kopf, Positionen (aus dem zugehörigen DL-Angebot) und Status (offen/in Arbeit/erledigt).

- "DL-Rechnung erstellen" (`dl_rechnung_aus_auftrag`): erzeugt eine Rechnung mit eigenem Nummernkreis DR-, übernimmt die Positionen, optional Zahlungsziel + Portal-Freigabe. Die Rechnung ist ein normaler `beleg` (kategorie='dienstleistung') und erscheint auch im zentralen Kassenbuch der Buchhaltung; Bezahlung/Mahnung laufen dort.
