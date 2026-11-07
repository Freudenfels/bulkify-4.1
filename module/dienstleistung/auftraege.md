# module/dienstleistung/auftraege.php - DL-Aufträge (Liste)

Route `?p=dl_auftraege`. Liste der Dienstleistungs-Aufträge (auftrag.kategorie='dienstleistung', Nummernkreis DB-), entstehen aus bestätigten DL-Angeboten. Zeigt je Auftrag die zugehörige DL-Rechnung (verlinkt in die Buchhaltung).

**Bezug (07.11.2026):** Unter der Leistung steht statt der immer gleichen Katalog-Beschreibung das Produkt (plus Bestellung und Charge, wenn zugeordnet). Quelle: `bezug_produkt_id` aus `dl_auftraege_alle()` (Auftrag → Bezugs-Bestellung → DL-Anfrage). Laboranalysen ohne Produkt zeigen rot „Produkt nicht zugeordnet".
