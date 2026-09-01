<?php
// Lieferantenportal – eigene Kontaktdaten pflegen. Route: ?p=lieferant_profil
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bewusst nur Kontaktdaten und Sprache – Firmierung und Konditionen bleiben beim Team.
    $spr = in_array((string)($_POST['sprache'] ?? ''), ['de','en'], true) ? $_POST['sprache'] : 'de';
    q("UPDATE lieferanten SET ansprechpartner=?, email=?, telefon=?, sprache=? WHERE id=?", [
        mb_substr(trim((string)($_POST['ansprechpartner'] ?? '')), 0, 190),
        mb_substr(trim((string)($_POST['email'] ?? '')), 0, 190),
        mb_substr(trim((string)($_POST['telefon'] ?? '')), 0, 60), $spr, $lid]);
    header('Location: ?p=lieferant_profil&ok=1'); exit;
}
$lf = aktueller_lieferant();

lp_head('bulkify – ' . lp_t('profil'));
lp_shell_start('lieferant_profil');
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
?>
<h1 style="margin-bottom:4px"><?= h(lp_t('profil')) ?></h1>
<p class="bx-sub"><?= h($lf['firma']) ?><?= $lf['lieferantennummer'] ? ' · ' . h($lf['lieferantennummer']) : '' ?></p>
<div class="bx-panel" style="max-width:520px">
  <form method="post">
    <div class="bx-field"><label><?= h(lp_t('ansprechpartner')) ?></label>
      <input type="text" name="ansprechpartner" value="<?= h($lf['ansprechpartner'] ?? '') ?>"></div>
    <div class="bx-field"><label><?= h(lp_t('email')) ?></label>
      <input type="email" name="email" value="<?= h($lf['email'] ?? '') ?>"></div>
    <div class="bx-field"><label><?= h(lp_t('telefon')) ?></label>
      <input type="text" name="telefon" value="<?= h($lf['telefon'] ?? '') ?>"></div>
    <div class="bx-field"><label><?= h(lp_t('sprache')) ?></label>
      <select name="sprache">
        <option value="de"<?= strtolower((string)$lf['sprache']) === 'de' ? ' selected' : '' ?>>Deutsch</option>
        <option value="en"<?= strtolower((string)$lf['sprache']) !== 'de' ? ' selected' : '' ?>>English</option>
      </select></div>
    <button class="btn btn-primary" type="submit"><?= h(lp_t('speichern')) ?></button>
  </form>
</div>
<?php lp_shell_ende(); lp_foot();
