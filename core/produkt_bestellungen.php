<?php
// Reiter „Bestellungen“ auf der Produktseite (Nico 10.11.2026): Wer hat dieses Produkt gekauft – je Auftrag Kunde, Menge,
// Status und die gelieferten bzw. gebundenen Fertigware-Chargen mit MHD. Nur lesen.
// Chargen je Auftrag wie auftrag_fertigware_chargen(): Fertigprodukt-Charge (item.kategorie='verkaufsfertig', nicht Lager 2)
// mit charge.auftrag_id = Auftrag ODER aus einem Produktionsauftrag dieses Auftrags (charge.pa_id). Für alle Aufträge in einer Abfrage.

function produkt_bestellungen(int $produkt_id, bool $mitStorno = false): array {
    if ($produkt_id <= 0) return [];
    $auf = all("SELECT a.id, a.nummer, a.kunde_id, a.menge, a.versendet_menge, a.status, a.angelegt, a.status_datum, a.gesamt_netto,
                       a.produkt_bezeichnung, k.firma
                FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id
                WHERE a.produkt_id=? AND COALESCE(a.kategorie,'produkt')<>'dienstleistung'"
               . ($mitStorno ? "" : " AND a.status<>'storniert'") . "
                ORDER BY a.angelegt DESC, a.id DESC", [$produkt_id]);
    if (!$auf) return [];
    $ids = array_map(fn($a) => (int)$a['id'], $auf);
    $in = implode(',', $ids);   // nur Ganzzahlen
    $ch = [];
    foreach (all("SELECT c.id, c.charge_nr, c.mhd, c.menge, c.menge_verfuegbar, c.status, c.einheit,
                         COALESCE(c.auftrag_id, pa.auftrag_id) AS aid
                  FROM charge c JOIN item i ON i.id=c.item_id
                  LEFT JOIN produktionsauftrag pa ON pa.id=c.pa_id AND pa.status<>'storniert'
                  WHERE i.kategorie='verkaufsfertig' AND c.fremd_kunde_id IS NULL
                    AND (c.auftrag_id IN ($in) OR pa.auftrag_id IN ($in))
                  ORDER BY (c.mhd IS NULL), c.mhd ASC, c.id ASC") as $c) {
        $ch[(int)$c['aid']][(int)$c['id']] = $c;
    }
    foreach ($auf as &$a) $a['chargen'] = array_values($ch[(int)$a['id']] ?? []);
    unset($a);
    return $auf;
}
