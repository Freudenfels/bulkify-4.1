<?php
// Lieferantenportal – „Mein Katalog". Route: ?p=lieferant_katalog
// Der Lieferant zeigt, was er anbietet: Liste hochladen (die KI liest sie) oder Zeilen von Hand
// pflegen. Daraus entsteht NICHT automatisch ein Artikel bei uns – das Team entscheidet.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/lieferant_katalog.php';
require_once BX_ROOT . '/core/lieferant_dateien.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid = aktueller_lieferant_id();
$spr = lp_sprache();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $ziel   = '?p=lieferant_katalog';
    if ($aktion === 'liste_hoch') {
        // Die Liste landet zuerst in der Dateiablage (dann ist sie nachvollziehbar da),
        // danach liest die KI sie aus.
        $f = lieferant_datei_upload($lid, 'lieferant', $spr);
        if ($f !== '') { header('Location: ' . $ziel . '&fehler=' . urlencode($f)); exit; }
        $d = one("SELECT id, datei FROM dokument WHERE objekt_typ='lieferant' AND objekt_id=? ORDER BY id DESC LIMIT 1", [$lid]);
        $r = $d ? katalog_einlesen($lid, BX_UPLOADS . '/' . basename((string)$d['datei']), (int)$d['id']) : ['ok'=>false, 'fehler'=>'Datei nicht gefunden.'];
        header('Location: ' . $ziel . ($r['ok'] ? '&gelesen=' . (int)$r['anzahl'] : '&fehler=' . urlencode($r['fehler']))); exit;
    }
    if ($aktion === 'zeile_neu') {
        q("INSERT INTO lieferant_katalog (lieferant_id,name,art,form,spezifikation,herkunft,preis,waehrung,einheit,menge_ab,notiz,status,angelegt)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,'neu',?)",
          [$lid, mb_substr(trim((string)($_POST['name'] ?? '')), 0, 190) ?: 'ohne Namen',
           in_array(($_POST['art'] ?? ''), ['rohstoff','fertigprodukt'], true) ? $_POST['art'] : 'rohstoff',
           array_key_exists((string)($_POST['form'] ?? ''), katalog_formen()) ? $_POST['form'] : null,
           mb_substr(trim((string)($_POST['spezifikation'] ?? '')), 0, 190) ?: null,
           mb_substr(trim((string)($_POST['herkunft'] ?? '')), 0, 120) ?: null,
           trim((string)($_POST['preis'] ?? '')) !== '' ? zahl_lesen((string)$_POST['preis'], false, $spr) : null,
           mb_substr(trim((string)($_POST['waehrung'] ?? 'EUR')), 0, 3) ?: 'EUR',
           mb_substr(trim((string)($_POST['einheit'] ?? '')), 0, 20) ?: null,
           trim((string)($_POST['menge_ab'] ?? '')) !== '' ? zahl_lesen((string)$_POST['menge_ab'], true, $spr) : null,
           mb_substr(trim((string)($_POST['notiz'] ?? '')), 0, 500) ?: null, gmdate('Y-m-d H:i:s')]);
        header('Location: ' . $ziel . '&ok=1'); exit;
    }
    if ($aktion === 'zeile_save') { katalog_speichern((int)($_POST['zeile_id'] ?? 0), $_POST, $lid); header('Location: ' . $ziel . '&ok=1'); exit; }
    if ($aktion === 'zeile_weg')  { katalog_loeschen((int)($_POST['zeile_id'] ?? 0), $lid); header('Location: ' . $ziel . '&ok=1'); exit; }
    header('Location: ' . $ziel); exit;
}

$zeilen = katalog_zeilen($lid);
$offen  = 0; foreach ($zeilen as $z) if ($z['status'] === 'neu') $offen++;

lp_head('bulkify – ' . lp_t('katalog'));
lp_shell_start('lieferant_katalog');
if (isset($_GET['ok']))      echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if (isset($_GET['gelesen'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('katalog_gelesen')) . ' ' . (int)$_GET['gelesen'] . '</div>';
if (isset($_GET['fehler']))  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
$zahl = fn($x, $n) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float)$x, $n, '.', ''), '0'), '.');
?>
<h1 style="margin-bottom:4px"><?= h(lp_t('katalog')) ?></h1>
<p class="bx-sub"><?= h(lp_t('katalog_sub')) ?></p>

<div class="bx-panel">
  <h2 style="margin-top:0"><?= h(lp_t('katalog_hoch')) ?></h2>
  <p class="muted" style="margin-top:0"><?= h(lp_t('katalog_hoch_sub')) ?></p>
  <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="liste_hoch">
    <input type="hidden" name="dok_typ" value="sonstiges">
    <input type="hidden" name="dok_titel" value="Katalog">
    <div class="bx-field" style="margin:0"><label><?= h(lp_t('datei')) ?></label>
      <input type="file" name="dok" required accept=".pdf,.png,.jpg,.jpeg,.webp,.csv,.txt"></div>
    <button class="btn btn-primary" type="submit"><?= h(lp_t('katalog_lesen')) ?></button>
    <span class="muted" style="font-size:12px;align-self:center"><?= h(lp_t('dauert')) ?></span>
  </form>
</div>

<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0"><?= h(lp_t('katalog_liste')) ?> <span class="muted" style="font-weight:normal">(<?= count($zeilen) ?>)</span></h2>
    <?php if ($offen > 0): ?><span class="muted"><?= h(lp_t('katalog_wartet')) ?>: <?= $offen ?></span><?php endif; ?>
  </div>
  <?php if (!$zeilen): ?>
    <div class="muted" style="margin-top:10px"><?= h(lp_t('katalog_leer')) ?></div>
  <?php else: ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr>
      <th><?= h(lp_t('artikel')) ?></th><th><?= h(lp_t('produkttyp')) ?></th><th><?= h(lp_t('spezifikation')) ?></th>
      <th class="bx-num"><?= h(lp_t('preis')) ?></th><th class="bx-num"><?= h(lp_t('ab_menge')) ?></th>
      <th><?= h(lp_t('status')) ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($zeilen as $z): $neu = $z['status'] === 'neu'; ?>
      <tr>
        <td><?= h($z['name']) ?><?php if ($z['herkunft']): ?><div class="muted" style="font-size:12px"><?= h($z['herkunft']) ?></div><?php endif; ?></td>
        <td><?= h(anfrage_art_label($z['art'] === 'fertigprodukt' ? 'fertigprodukt' : 'rohstoff', (string)$z['form'], $spr)) ?></td>
        <td><?= h((string)$z['spezifikation']) ?></td>
        <td class="bx-num"><?= $z['preis'] !== null ? h($zahl($z['preis'], 4) . ' ' . $z['waehrung'] . ($z['einheit'] ? ' / ' . $z['einheit'] : '')) : '–' ?></td>
        <td class="bx-num"><?= $z['menge_ab'] !== null ? h(lp_num($z['menge_ab'], 3)) : '–' ?></td>
        <td><?= $neu ? h(lp_t('katalog_geprueft_nein')) : ($z['status'] === 'uebernommen' ? h(lp_t('katalog_uebernommen')) : h(lp_t('katalog_abgelehnt'))) ?></td>
        <td class="bx-num"><?php if ($neu): ?>
          <form method="post" style="display:inline" onsubmit="return confirm('<?= h(lp_t('loeschen')) ?>?');">
            <input type="hidden" name="aktion" value="zeile_weg"><input type="hidden" name="zeile_id" value="<?= (int)$z['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit">&times;</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0"><?= h(lp_t('katalog_zeile_neu')) ?></h2>
  <form method="post">
    <input type="hidden" name="aktion" value="zeile_neu">
    <div class="bx-grid">
      <div class="bx-field"><label><?= h(lp_t('artikel')) ?></label><input type="text" name="name" required maxlength="190"></div>
      <div class="bx-field" style="max-width:190px"><label><?= h(lp_t('produkttyp')) ?></label>
        <select name="art"><option value="rohstoff"><?= h(anfrage_art_label('rohstoff', '', $spr)) ?></option><option value="fertigprodukt"><?= h(anfrage_art_label('fertigprodukt', '', $spr)) ?></option></select></div>
      <div class="bx-field" style="max-width:170px"><label><?= h(lp_t('form_lbl')) ?></label>
        <select name="form"><option value="">–</option><?php foreach (katalog_formen() as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field"><label><?= h(lp_t('spezifikation')) ?></label><input type="text" name="spezifikation" maxlength="190" placeholder="95 % Curcumin"></div>
      <div class="bx-field" style="max-width:170px"><label><?= h(lp_t('herkunft')) ?></label><input type="text" name="herkunft" maxlength="120"></div>
      <div class="bx-field" style="max-width:130px"><label><?= h(lp_t('preis')) ?></label><input type="text" name="preis"></div>
      <div class="bx-field" style="max-width:90px"><label><?= h(lp_t('waehrung')) ?></label><input type="text" name="waehrung" value="EUR" maxlength="3"></div>
      <div class="bx-field" style="max-width:110px"><label><?= h(lp_t('einheit')) ?></label><input type="text" name="einheit" placeholder="kg" maxlength="20"></div>
      <div class="bx-field" style="max-width:130px"><label><?= h(lp_t('ab_menge')) ?></label><input type="text" name="menge_ab"></div>
    </div>
    <div class="bx-field"><label><?= h(lp_t('notiz')) ?></label><input type="text" name="notiz" maxlength="500"></div>
    <button class="btn btn-primary" type="submit"><?= h(lp_t('katalog_zeile_add')) ?></button>
  </form>
</div>
<?php lp_shell_ende(); lp_foot();
