<?php
// Offene Posten (Debitoren) – EINE Rechnung für Dashboard, Kundenseite und Buchhaltung (13.11.2026).
// Offen = Rechnung mit Status „offen“ oder „teilbezahlt“, je Rechnung Brutto minus Summe der Zahlungen (= offener Rest).
// Die Buchhaltung (bh_op_rechnungen, /buchhaltung/?p=op_debitoren) ruft genau diese Funktion; nirgends ein zweites SQL.
// Bewusst ohne weitere Abhängigkeiten (nur all()), damit auch das eigene Programm Buchhaltung sie einbinden kann.

// Offene Rechnungen. $filter 'ueberfaellig' = nur fällige vor heute. $kunde_id 0 = alle Kunden.
// Sortierung wie in der Buchhaltung: nach Fälligkeit (ohne Fälligkeit zuletzt), dann id.
function op_rechnungen(string $filter = '', int $kunde_id = 0): array {
    $where = "b.typ='rechnung' AND b.status IN ('offen','teilbezahlt')";
    $args = [];
    if ($kunde_id) { $where .= " AND b.kunde_id=?"; $args[] = $kunde_id; }
    if ($filter === 'ueberfaellig') $where .= " AND b.faellig IS NOT NULL AND b.faellig < CURDATE()";
    return all(
        "SELECT b.id, b.nummer, b.datum, b.faellig, b.brutto, b.status, b.kunde_id,
                COALESCE(b.mahnstufe,0) AS mahnstufe, b.letzte_mahnung, b.kategorie,
                k.firma AS kunde_firma,
                COALESCE(z.bez,0) AS bezahlt,
                (b.brutto - COALESCE(z.bez,0)) AS rest,
                CASE WHEN b.faellig IS NOT NULL AND b.faellig < CURDATE()
                     THEN DATEDIFF(CURDATE(), b.faellig) ELSE 0 END AS tage_ueberfaellig
           FROM beleg b
           LEFT JOIN kunden k ON k.id=b.kunde_id
           LEFT JOIN (SELECT beleg_id, SUM(betrag) bez FROM zahlung GROUP BY beleg_id) z ON z.beleg_id=b.id
          WHERE $where
          ORDER BY (b.faellig IS NULL), b.faellig ASC, b.id ASC", $args);
}

// Summe der offenen Reste (gleiche Zeilen wie op_rechnungen, gleiche Summe wie „Offen gesamt“ in der Buchhaltung).
function op_summe(int $kunde_id = 0, string $filter = ''): float {
    $s = 0.0;
    foreach (op_rechnungen($filter, $kunde_id) as $r) $s += (float)$r['rest'];
    return round($s, 2);
}
