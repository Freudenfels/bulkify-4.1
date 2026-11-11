<?php
// KI-Steckbrief am Rohstoff erstellen oder bearbeiten (nur intern). Route: ?p=rohstoff_steckbrief (nur POST).
// Der Block selbst steht in core/rohstoff_steckbrief.php und wird auf Rohstoffseite und in den Rohstoff-Anfragen eingebunden.
require_once BX_ROOT . '/core/rohstoff_steckbrief.php';

$iid  = (int)($_POST['item_id'] ?? 0);
$back = (string)($_POST['back'] ?? '');
// Nur interne Rücksprünge im Dashboard zulassen.
if (!preg_match('/^\?p=[a-z_]+[A-Za-z0-9_=&%.\-]*$/', $back)) $back = $iid ? '?p=rohstoff&id=' . $iid : '?p=rohstoffe';
$ziel = fn(string $zusatz) => $back . '&sb=' . $iid . $zusatz . '#steckbrief-' . $iid;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $iid <= 0 || !scalar("SELECT id FROM item WHERE id=?", [$iid])) {
    header('Location: ' . $back); exit;
}
$aktion = (string)($_POST['aktion'] ?? '');
if ($aktion === 'erstellen') {
    $r = rohstoff_steckbrief_erzeugen($iid);
    header('Location: ' . $ziel($r['ok'] ? '&sbok=1' : '&sberr=' . rawurlencode((string)($r['fehler'] ?? 'Fehler')))); exit;
}
if ($aktion === 'speichern') {
    rohstoff_steckbrief_speichern($iid, (string)($_POST['steckbrief'] ?? ''));
    header('Location: ' . $ziel('&sbok=1')); exit;
}
header('Location: ' . $back); exit;
