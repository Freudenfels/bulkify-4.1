<?php
// Lieferantenportal – Dateiablage. Route: ?p=lieferant_dateien
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/lieferant_dateien.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $f = '';
    if ($aktion === 'dok_upload')   $f = lieferant_datei_upload($lid, 'lieferant', lp_sprache());
    elseif ($aktion === 'dok_del')  $f = lieferant_datei_loeschen($lid, (int)($_POST['dok_id'] ?? 0), 'lieferant', lp_sprache());
    header('Location: ?p=lieferant_dateien' . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
}

lp_head('bulkify – ' . lp_t('dateien_menu'));
lp_shell_start('lieferant_dateien');
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
?>
<h1 style="margin-bottom:4px"><?= h(lp_t('dateien_menu')) ?></h1>
<p class="bx-sub"><?= h(lp_t('dateien_sub')) ?></p>
<?= lieferant_dateien_panel($lid, 'lieferant', lp_sprache()) ?>
<?php lp_shell_ende(); lp_foot();
