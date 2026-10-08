<?php
// Lieferantenportal – Rohstoff-Info als JSON fuer das Popup in den Rezeptur-Ansichten.
// Route: ?p=lieferant_rohstoff_info&iid=<item_id>
// Zeigt dem Lieferanten, WAS der Rohstoff ist (Identitaet + Beschaffenheit/DEV + Wirkstoff-Gehalte),
// damit er besser vergleichen/kalkulieren kann. BEWUSST OHNE Preise, Lieferanten und interne Artikelnummern.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/spec_ki.php';   // item_kennwerte_relevant (nur echte Kennwerte, kein Schwermetall/Mikro)
header('Content-Type: application/json; charset=utf-8');
if (!ist_lieferant()) { echo '{}'; exit; }

$iid = (int)($_GET['iid'] ?? 0);
$it  = $iid ? one("SELECT id, name, name_lat, form, beschaffenheit, dev, cas, synonym, ec_nr, bot_quelle, herkunftsland,
          allergene, haltbarkeit, lagerbedingungen, zusaetze, vegan, gvo_frei, bestrahlt, tse_bse_frei, zertifikate
       FROM item WHERE id=? AND kategorie='rohstoff' AND gesperrt=0", [$iid]) : null;
if (!$it) { echo '{}'; exit; }

$rows = [];
$add  = function(string $label, $val) use (&$rows) { if ($val !== null && trim((string)$val) !== '') $rows[] = [$label, (string)$val]; };
$jn   = fn($x) => ($x === null || $x === '') ? null : ((int)$x ? 'Ja' : 'Nein');

if (!empty($it['beschaffenheit'])) {
    $add('Beschaffenheit', rohstoff_beschaffenheit_label($it['beschaffenheit'])
        . (trim((string)($it['dev'] ?? '')) !== '' ? ' (' . trim((string)$it['dev']) . ')' : ''));
}
$add(lp_t('form_lbl'), rohstoff_form_label($it['form']));
$add('CAS', $it['cas']);
$add('Lateinischer Name', $it['name_lat']);
$add('Synonym', $it['synonym']);
$add('EC-Nummer', $it['ec_nr']);
$add('Botanische Quelle', $it['bot_quelle']);
$add('Herkunftsland', $it['herkunftsland']);
$add('Allergene', $it['allergene']);
$add('Vegan', $jn($it['vegan']));
$add('GVO-frei', $jn($it['gvo_frei']));
$add('Bestrahlt', $jn($it['bestrahlt']));
$add('TSE/BSE-frei', $jn($it['tse_bse_frei']));
$add('Zertifikate', $it['zertifikate']);
$add('Zusätze', $it['zusaetze']);
$add('Haltbarkeit', $it['haltbarkeit']);
$add('Lagerbedingungen', $it['lagerbedingungen']);

// Wirkstoff-Gehalte = die eigentliche Vergleichsgrundlage (z. B. „Withanolide 5 %").
$wirk = [];
foreach (all("SELECT n.name, iw.gehalt_prozent FROM item_wirkstoff iw
              JOIN naehrstoff n ON n.id = iw.naehrstoff_id
              WHERE iw.item_id = ? ORDER BY iw.sort, iw.id", [(int)$it['id']]) as $w) {
    $g = $w['gehalt_prozent'];
    $wirk[] = [(string)$w['name'], ($g === null || $g === '') ? '' : (rtrim(rtrim(number_format((float)$g, 2, ',', '.'), '0'), ',') . ' %')];
}
foreach (item_kennwerte_relevant((int)$it['id']) as $kw) $add((string)$kw['parameter'], $kw['wert']);

echo json_encode(['name' => rohstoff_anzeige_name($it), 'rows' => $rows, 'wirkstoffe' => $wirk], JSON_UNESCAPED_UNICODE);
exit;
