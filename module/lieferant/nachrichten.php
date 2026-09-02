<?php
// Lieferantenportal – Rückfragen (das ganze Gespräch mit bulkify). Route: ?p=lieferant_nachrichten
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/nachricht.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'nachricht') {
    $f = nachricht_post_verarbeiten($lid, 'lieferant', (string)(current_user()['name'] ?? 'Lieferant'), null, null, lp_sprache());
    header('Location: ?p=lieferant_nachrichten' . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
}

lp_head('bulkify – ' . lp_t('rueckfragen'));
lp_shell_start('lieferant_nachrichten');
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gesendet')) . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
?>
<h1 style="margin-bottom:4px"><?= h(lp_t('rueckfragen')) ?></h1>
<p class="bx-sub"><?= h(lp_t('rueckfragen_sub')) ?></p>
<?= nachricht_panel($lid, 'lieferant', lp_sprache(), null, null, true) ?>
<?php lp_shell_ende(); lp_foot();
