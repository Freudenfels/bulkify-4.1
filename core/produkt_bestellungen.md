# Bestellungen eines Produkts (`core/produkt_bestellungen.php`)

Stand 10.11.2026, Nico: Auf der Produktseite soll ein Reiter „Bestellungen“ zeigen, wer das Produkt gekauft hat, mit MHD und Charge.

## Funktion
`produkt_bestellungen($produkt_id, $mitStorno = false)` gibt alle Aufträge mit diesem Produkt zurück, die neuesten zuerst. Dienstleistungs-Aufträge sind nicht dabei. Stornierte Aufträge kommen nur mit `$mitStorno = true`.

Zu jedem Auftrag gehören:
- Nummer, Kunde (`firma`), Menge, versendete Menge, Status, Datum, Netto
- `chargen`: die Fertigprodukt-Chargen des Auftrags mit Charge-Nr., MHD, Menge und Status, früheste MHD zuerst

## Welche Chargen zählen
Gleiche Regel wie `auftrag_fertigware_chargen()` in `core/schema.php`. Eine Charge gehört zum Auftrag, wenn alle drei Punkte zutreffen:
- Der Artikel ist ein Fertigprodukt (`item.kategorie = 'verkaufsfertig'`).
- Die Charge liegt nicht in Lager 2, also `fremd_kunde_id` ist leer.
- Die Charge ist dem Auftrag zugeordnet (`charge.auftrag_id`) oder stammt aus einem nicht stornierten Produktionsauftrag dieses Auftrags (`charge.pa_id`).

Ware, die ohne feste Zuordnung aus dem allgemeinen Lager versendet wurde (alte Handelsware), hat keine Charge. Beim Versand wird nicht gespeichert, welche Charge an wen ging.

Alle Chargen werden in einer einzigen Abfrage für alle Aufträge geholt. Die Funktion liest nur und schreibt nichts.
