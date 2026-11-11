<?php
// Lieferantenportal – „Fehlende Unterlagen“ (Paket 12.11.2026 Teil 4). Route: ?p=lieferant_unterlagen
// Aufgabenliste je Anfrage bzw. Preis: je Rohstoff CoA oder Spezifikation, bei Fremdfertigung/Premix/Bulk dazu die
// Spezifikation des Gesamtprodukts. Upload direkt in der Zeile; erledigte Punkte verschwinden. Logik: core/lieferant_unterlagen.php
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/lieferant_unterlagen.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['aktion'] ?? '') === 'lu_upload') {
    $r = lu_upload_post($lid);
    header('Location: ?p=lieferant_unterlagen' . ($r['ok'] ? '&ok=1' : '&fehler=' . urlencode(lp_t('datei_fehler'))));
    lu_upload_nachlauf((int)$r['did']);
    exit;
}

lp_head('bulkify – ' . lp_t('unterlagen_menu'));
lp_shell_start('lieferant_unterlagen');
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('coa_empfangen')) . '</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
?>
<h1 style="margin-bottom:4px"><?= h(lp_t('unterlagen_menu')) ?></h1>
<?php $panel = lu_panel($lid, '?p=lieferant_unterlagen', lp_sprache());
echo $panel !== '' ? $panel : '<div class="bx-panel"><div class="muted">' . h(['de' => 'Es fehlen keine Unterlagen. Danke!', 'en' => 'No documents are missing. Thank you!', 'zh' => '没有缺失的文件。谢谢！'][lp_sprache()] ?? 'No documents are missing.') . '</div></div>';
lp_shell_ende(); lp_foot();
