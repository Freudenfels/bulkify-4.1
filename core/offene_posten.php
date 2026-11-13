<?php
// Offene Posten (Debitoren) – EINE Rechnung für Dashboard, Kundenseite, Kundenportal und Buchhaltung (13.11.2026).
// Offen = Rechnung mit Status „offen“ oder „teilbezahlt“, je Rechnung Brutto minus Summe der Zahlungen (= offener Rest).
// Die Buchhaltung (bh_op_rechnungen, /buchhaltung/?p=op_debitoren, Rechnungsliste) ruft genau diese Funktion; nirgends ein zweites SQL.
// Bewusst ohne weitere Abhängigkeiten (nur all()), damit auch das eigene Programm Buchhaltung sie einbinden kann.
// Nachbesserung Fable-5 (13.11.2026):
//  - Summe = nur positive Reste (op_summe); negative Reste (Überzahlung) sind „Guthaben“ (op_guthaben) – eigene Zeile, nie abziehen.
//  - Läuft auch auf einer Datenbank ohne die Spalten der Buchhaltung (mahnstufe, letzte_mahnung, kategorie, kunde_sichtbar):
//    fehlende Spalten werden als Standardwert geliefert statt die Seite abzubrechen.

// Vorhandene Spalten der Tabelle beleg (einmal je Aufruf).
function op_beleg_spalten(): array {
    static $sp = null;
    if ($sp !== null) return $sp;
    $sp = [];
    try { foreach (all("SHOW COLUMNS FROM beleg") as $c) $sp[(string)$c['Field']] = true; } catch (\Throwable $e) {}
    return $sp;
}

// Offene Rechnungen. $filter 'ueberfaellig' = nur fällige vor heute; 'sichtbar' = nur für den Kunden freigegebene (Kundenportal).
// $kunde_id 0 = alle Kunden. Sortierung wie in der Buchhaltung: nach Fälligkeit (ohne Fälligkeit zuletzt), dann id.
function op_rechnungen(string $filter = '', int $kunde_id = 0): array {
    $sp = op_beleg_spalten();
    $opt = fn(string $spalte, string $ersatz) => isset($sp[$spalte]) ? "b.$spalte" : $ersatz;
    $where = "b.typ='rechnung' AND b.status IN ('offen','teilbezahlt')";
    $args = [];
    if ($kunde_id) { $where .= " AND b.kunde_id=?"; $args[] = $kunde_id; }
    if ($filter === 'ueberfaellig') $where .= " AND b.faellig IS NOT NULL AND b.faellig < CURDATE()";
    if ($filter === 'sichtbar' && isset($sp['kunde_sichtbar'])) $where .= " AND b.kunde_sichtbar=1";
    return all(
        "SELECT b.id, b.nummer, b.datum, b.faellig, b.brutto, b.status, b.kunde_id,
                COALESCE(" . $opt('mahnstufe', '0') . ",0) AS mahnstufe, " . $opt('letzte_mahnung', 'NULL') . " AS letzte_mahnung,
                " . $opt('kategorie', "'produkt'") . " AS kategorie, " . $opt('kunde_sichtbar', '1') . " AS kunde_sichtbar,
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

// Summe der offenen Reste – nur positive Reste (gleiche Zeilen wie op_rechnungen). Überzahlungen zählen als Guthaben (op_guthaben).
// $ids: optional nur diese Belege (z. B. gefilterte Rechnungsliste).
function op_summe(int $kunde_id = 0, string $filter = '', ?array $ids = null): float {
    $s = 0.0;
    $nur = $ids !== null ? array_flip(array_map('intval', $ids)) : null;
    foreach (op_rechnungen($filter, $kunde_id) as $r)
        if (($nur === null || isset($nur[(int)$r['id']])) && (float)$r['rest'] > 0) $s += (float)$r['rest'];
    return round($s, 2);
}
// Guthaben = Summe der negativen Reste (Überzahlung), als positiver Betrag. Eigene Zeile, nie von den offenen Posten abziehen.
function op_guthaben(int $kunde_id = 0, string $filter = '', ?array $ids = null): float {
    $s = 0.0;
    $nur = $ids !== null ? array_flip(array_map('intval', $ids)) : null;
    foreach (op_rechnungen($filter, $kunde_id) as $r)
        if (($nur === null || isset($nur[(int)$r['id']])) && (float)$r['rest'] < 0) $s -= (float)$r['rest'];
    return round($s, 2);
}
