<?php
// „kein Dokument nötig“ am Artikel setzen bzw. entfernen (Sofortfix 13.11.2026). Route: ?p=kein_dokument (nur POST).
// Kommt aus dem Pop-up der Listen „Fehlende Unterlagen“ (lu_kein_dokument_knopf) und vom Haken am Artikel.
// Nur Admin und QM (Rolle labor). Logik und Protokoll: lu_kein_dokument_setzen() in core/lieferant_unterlagen.php.
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/lieferant_unterlagen.php';

$zurueck = (string)($_POST['zurueck'] ?? '');
if ($zurueck === '' || $zurueck[0] !== '?') $zurueck = '?p=einkaufsliste';   // nur interne Rücksprünge
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !lu_darf_kein_dokument()) { header('Location: ' . $zurueck); exit; }
$iid = (int)($_POST['item_id'] ?? 0);
$name = (string) scalar("SELECT name FROM item WHERE id=?", [$iid]);
if ($name === '') { header('Location: ' . $zurueck); exit; }
$an = (string)($_POST['an'] ?? '1') === '1';
lu_kein_dokument_setzen($iid, $an, (string)($_POST['grund'] ?? ''), (string)(current_user()['name'] ?? 'Team'));
[$pfad, $anker] = array_pad(explode('#', $zurueck, 2), 2, '');
$msg = rawurlencode($name . ': ' . ($an ? 'kein Dokument nötig – Punkt erledigt.' : 'braucht wieder Unterlagen.'));
header('Location: ' . $pfad . (str_contains($pfad, 'ok=') ? '' : '&ok=' . $msg) . ($anker !== '' ? '#' . $anker : '')); exit;
