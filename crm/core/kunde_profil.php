<?php
// Kundenprofil: die CRM-Sicht auf einen Dashboard-Kunden - Qualifizierung (wie beim Kontakt) und
// freie Infos. Steht in crm_kunde_profil (die Dashboard-kunden-Tabelle wird nicht angefasst).
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/ui.php';

function kunde_profil_lesen(int $kunde_id): array {
    $r = $kunde_id > 0 ? one("SELECT * FROM crm_kunde_profil WHERE kunde_id=?", [$kunde_id]) : null;
    return $r ?: [];
}

function kunde_profil_speichern(int $kunde_id, array $p): void {
    if ($kunde_id <= 0) return;
    $seg = [];
    foreach (crm_segfelder() as $f => $def) { $v = (string)($p[$f] ?? ''); $seg[$f] = array_key_exists($v, $def[1]) ? $v : null; }
    q("INSERT INTO crm_kunde_profil (kunde_id, kontaktart, erfahrung, zielmarkt, nische, firmentyp, volumen, prioritaet, land, website, moeglichkeiten, besonderheiten, infos, aktualisiert)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE kontaktart=VALUES(kontaktart), erfahrung=VALUES(erfahrung), zielmarkt=VALUES(zielmarkt),
         nische=VALUES(nische), firmentyp=VALUES(firmentyp), volumen=VALUES(volumen), prioritaet=VALUES(prioritaet),
         land=VALUES(land), website=VALUES(website), moeglichkeiten=VALUES(moeglichkeiten),
         besonderheiten=VALUES(besonderheiten), infos=VALUES(infos), aktualisiert=VALUES(aktualisiert)",
      [$kunde_id, $seg['kontaktart'], $seg['erfahrung'], $seg['zielmarkt'], $seg['nische'], $seg['firmentyp'], $seg['volumen'], $seg['prioritaet'],
       mb_substr(trim((string)($p['land'] ?? '')), 0, 120) ?: null,
       mb_substr(trim((string)($p['website'] ?? '')), 0, 190) ?: null,
       trim((string)($p['moeglichkeiten'] ?? '')) ?: null,
       trim((string)($p['besonderheiten'] ?? '')) ?: null,
       trim((string)($p['infos'] ?? '')) ?: null,
       gmdate('Y-m-d H:i:s')]);
}

// Vorgaenge eines Kunden als EINE zeitliche Liste (Timeline): Angebote, Auftraege, Rechnungen,
// Rezepturen. Je Eintrag: datum, art, titel, status, link (ins Dashboard), betrag.
// Liest ueber die erp.php-Naht. Neueste zuerst.
function kunde_timeline(int $kunde_id): array {
    require_once __DIR__ . '/erp.php';
    $dash = erp_dashboard_url();
    $lnk = fn(string $route) => ($dash !== '' ? $dash : '') . '/?p=' . $route;
    $ev = [];

    foreach (erp_angebote_fuer_kunde($kunde_id) as $a) {
        $ev[] = ['datum' => (string)($a['aktualisiert'] ?? $a['angelegt'] ?? ''), 'art' => 'Angebot',
                 'titel' => (string)($a['nummer'] ?: ('Angebot #' . $a['id'])), 'status' => (string)$a['status'],
                 'betrag' => $a['summe'] !== null ? (float)$a['summe'] : null, 'link' => $lnk('angebot&id=' . (int)$a['id'])];
    }
    foreach (erp_auftraege_fuer_kunde($kunde_id) as $a) {
        $ev[] = ['datum' => (string)($a['angelegt'] ?? ''), 'art' => 'Auftrag',
                 'titel' => (string)($a['nummer'] ?: ('Auftrag #' . $a['id'])) . (trim((string)($a['produkt_bezeichnung'] ?? '')) !== '' ? ' · ' . $a['produkt_bezeichnung'] : ''),
                 'status' => (string)$a['status'], 'betrag' => $a['gesamt_netto'] !== null ? (float)$a['gesamt_netto'] : null,
                 'link' => $lnk('auftrag&id=' . (int)$a['id'])];
    }
    foreach (erp_rechnungen_fuer_kunde($kunde_id) as $r) {
        $art = (string)$r['typ'] === 'gutschrift' ? 'Gutschrift' : 'Rechnung';
        $ev[] = ['datum' => (string)($r['datum'] ? $r['datum'] . ' 00:00:00' : ($r['angelegt'] ?? '')), 'art' => $art,
                 'titel' => (string)($r['nummer'] ?: ($art . ' #' . $r['id'])), 'status' => (string)$r['status'],
                 'betrag' => $r['brutto'] !== null ? (float)$r['brutto'] : null,
                 'link' => (int)($r['auftrag_id'] ?? 0) > 0 ? $lnk('auftrag&id=' . (int)$r['auftrag_id']) : $lnk('rechnungen_ansicht')];
    }
    foreach (erp_rezepturen_fuer_kunde($kunde_id) as $r) {
        $ev[] = ['datum' => '', 'art' => 'Rezeptur',
                 'titel' => trim((string)($r['nummer'] ?? '') . ' ' . (string)($r['name'] ?? '')) ?: ('Rezeptur #' . $r['id']),
                 'status' => (string)$r['status'], 'betrag' => null, 'link' => $lnk('rezeptur_detail&id=' . (int)$r['id'])];
    }

    usort($ev, fn($a, $b) => strcmp((string)$b['datum'], (string)$a['datum']));
    return $ev;
}
