<?php
// Zentrale Route für Preisanfragen bei Lieferanten (POST). Ein Rohstoff, mehrere Lieferanten.
// Angesteuert vom Popup aus core/anfrage_ui.php – von der Rohstoffseite, der Rezeptur oder dem Angebot.
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/mail.php';

$back = (string)($_POST['back'] ?? $_GET['back'] ?? '?p=dashboard');
// Nur interne relative Ziele zulassen (kein Open-Redirect).
if (!preg_match('/^\?p=[a-z0-9_&=.-]+$/i', $back)) $back = '?p=dashboard';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $back); exit; }

$item_id = (int)($_POST['item_id'] ?? 0);
$rez_id  = (int)($_POST['rezeptur_id'] ?? 0);
$art     = (string)($_POST['art'] ?? '');
$lids  = array_values(array_filter(array_map('intval', (array)($_POST['anf_lieferant'] ?? [])), fn($x) => $x > 0));
$menge = (float) str_replace(',', '.', (string)($_POST['anf_menge'] ?? '0'));
$notiz = trim((string)($_POST['anf_notiz'] ?? ''));
$coa   = isset($_POST['anf_coa']);

if (!$lids) { header('Location: ' . $back . '&anffehler=1'); exit; }

// Zwei Wege: einzelner Rohstoff (item_id) ODER ganzes Fertigprodukt (rezeptur_id).
if ($art === 'fertigprodukt' && $rez_id > 0) {
    $rez = one("SELECT name, darreichungsform FROM rezeptur WHERE id=?", [$rez_id]);
    if (!$rez) { header('Location: ' . $back . '&anffehler=1'); exit; }
    $form    = (string)($rez['darreichungsform'] ?: 'kapsel');
    $betreff = 'Fertigprodukt (Bulk): ' . (string)$rez['name'];
    $opt     = ['art' => 'fertigprodukt', 'form' => $form, 'rezeptur_id' => $rez_id];
    $einh    = anfrage_einheit_fuer_form($form);
    $n = 0; $gemailt = 0;
    foreach ($lids as $lid) {
        if (!scalar("SELECT id FROM lieferanten WHERE id=? AND gesperrt=0", [$lid])) continue;
        $af = lieferant_anfrage_stellen($lid, null, $betreff, $menge > 0 ? $menge : null, $einh, $notiz, $coa, $opt);
        $n++;
        if (mail_bereit() && function_exists('mail_lieferant_anfrage') && mail_lieferant_anfrage((int)$af) === '') $gemailt++;
    }
    $sep = strpos($back, '?') !== false ? '&' : '?';
    header('Location: ' . $back . $sep . 'angefragt=' . $n . '&gemailt=' . $gemailt); exit;
}

if ($item_id <= 0) { header('Location: ' . $back . '&anffehler=1'); exit; }
$einh = (string) (scalar("SELECT preis_bezug FROM item WHERE id=?", [$item_id]) ?: scalar("SELECT einheit FROM item WHERE id=?", [$item_id]));

$n = 0; $gemailt = 0;
foreach ($lids as $lid) {
    if (!scalar("SELECT id FROM lieferanten WHERE id=? AND gesperrt=0", [$lid])) continue;
    $af = lieferant_anfrage_stellen($lid, $item_id, '', $menge > 0 ? $menge : null, $einh, $notiz, $coa);
    $n++;
    if (mail_bereit() && function_exists('mail_lieferant_anfrage') && mail_lieferant_anfrage((int)$af) === '') $gemailt++;
}
$sep = strpos($back, '?') !== false ? '&' : '?';
header('Location: ' . $back . $sep . 'angefragt=' . $n . '&gemailt=' . $gemailt); exit;
