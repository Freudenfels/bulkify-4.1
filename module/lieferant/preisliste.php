<?php
// Lieferantenportal – „Meine Preisliste". Route: ?p=lieferant_preisliste
// Der Lieferant sieht seine Rohstoffpreise und aktualisiert sie. 4-Wochen-Regel: sind die Preise
// aelter als das Intervall (Standard 28 Tage), erscheint ein Hinweis mit „Alle als aktuell bestaetigen".
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/schema.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid  = aktueller_lieferant_id();
$spr  = lp_sprache();
$ziel = '?p=lieferant_preisliste';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'preis_save') {
        $rid   = (int)($_POST['id'] ?? 0);
        $preis = zahl_lesen((string)($_POST['eur_kg'] ?? ''), false, $spr);
        q("UPDATE lieferant_preisliste SET eur_kg=?, stand=CURDATE() WHERE id=? AND lieferant_id=?",
          [$preis > 0 ? $preis : null, $rid, $lid]);
        header('Location: ' . $ziel . '&ok=1'); exit;
    }
    if ($aktion === 'preis_add') {
        $name = trim((string)($_POST['rohstoff_name'] ?? ''));
        if ($name !== '') {
            $preis = zahl_lesen((string)($_POST['eur_kg'] ?? ''), false, $spr);
            q("INSERT INTO lieferant_preisliste (rohstoff_name,lieferant,lieferant_id,eur_kg,einheit,stand,angelegt) VALUES (?,?,?,?,?,CURDATE(),NOW())",
              [mb_substr($name, 0, 190), (string) scalar("SELECT firma FROM lieferanten WHERE id=?", [$lid]), $lid,
               $preis > 0 ? $preis : null, mb_substr(trim((string)($_POST['einheit'] ?? '')), 0, 20) ?: 'kg']);
        }
        header('Location: ' . $ziel . '&ok=1'); exit;
    }
    if ($aktion === 'preis_del') {
        q("DELETE FROM lieferant_preisliste WHERE id=? AND lieferant_id=?", [(int)($_POST['id'] ?? 0), $lid]);
        header('Location: ' . $ziel . '&ok=1'); exit;
    }
    if ($aktion === 'alle_bestaetigen') {
        $n = lieferant_preise_bestaetigen($lid);
        log_aktivitaet('lieferant', $lid, 'lieferant', $n . ' Preise als aktuell bestätigt.', 'preisliste');
        header('Location: ' . $ziel . '&best=1'); exit;
    }
    header('Location: ' . $ziel); exit;
}

$rows      = lieferant_preisliste_fuer($lid);
$veraltet  = lieferant_preise_veraltet($lid);
$alter     = lieferant_preise_alter_tage($lid);
$intervall = lieferant_preis_intervall($lid);
$num = fn($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float)$x, 4, ',', '.'), '0'), ',');

lp_head('bulkify – ' . lp_t('preisliste'));
lp_shell_start('lieferant_preisliste');
if (isset($_GET['ok']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if (isset($_GET['best'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('preise_bestaetigt')) . '</div>';
?>
<h1 style="margin-bottom:4px"><?= h(lp_t('preisliste')) ?></h1>
<p class="bx-sub"><?= h(lp_t('preisliste_sub')) ?></p>

<?php if ($veraltet): ?>
<div class="bx-panel" style="border-color:#e6c4c0;background:rgba(230,196,192,.12)">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <div><strong style="color:#8f231b"><?= h(lp_t('preis_veraltet')) ?></strong>
      <div class="muted" style="font-size:13px"><?= h(lp_t('preis_intervall_hint')) ?> <?= (int)$intervall ?> <?= h(lp_t('tage')) ?><?= $alter !== null ? ' · ' . h(lp_t('zuletzt_vor')) . ' ' . (int)$alter . ' ' . h(lp_t('tage')) : '' ?>.</div>
    </div>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="alle_bestaetigen">
      <button class="btn btn-primary" type="submit"><?= h(lp_t('alle_aktuell')) ?></button></form>
  </div>
</div>
<?php elseif ($rows): ?>
<div class="bx-panel badge-ok" style="padding:10px 14px"><?= h(lp_t('preis_aktuell')) ?><?= $alter !== null ? ' · ' . h(lp_t('zuletzt_vor')) . ' ' . (int)$alter . ' ' . h(lp_t('tage')) : '' ?></div>
<?php endif; ?>

<div class="bx-panel">
  <h2 style="margin-top:0"><?= h(lp_t('preisliste')) ?> <span class="muted" style="font-weight:normal">(<?= count($rows) ?>)</span></h2>
  <?php if (!$rows): ?>
    <div class="muted"><?= h(lp_t('preisliste_leer')) ?></div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th><?= h(lp_t('rohstoff')) ?></th><th class="bx-num" style="width:300px;white-space:nowrap"><?= h(lp_t('preis')) ?></th><th style="width:120px"><?= h(lp_t('stand')) ?></th><th style="width:60px"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $alt = $r['stand'] ? (int) floor((time() - strtotime((string)$r['stand'])) / 86400) : null; ?>
      <tr>
        <td><?= h($r['rohstoff_name']) ?></td>
        <td class="bx-num">
          <form method="post" class="bx-row" style="gap:6px;justify-content:flex-end;align-items:center;flex-wrap:nowrap;margin:0">
            <input type="hidden" name="aktion" value="preis_save"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="text" name="eur_kg" value="<?= h($num($r['eur_kg'])) ?>" style="width:90px;text-align:right" inputmode="decimal">
            <span class="muted">€ / <?= h($r['einheit'] ?: 'kg') ?></span>
            <button class="btn btn-ghost btn-sm" type="submit"><?= h(lp_t('aktualisieren')) ?></button>
          </form>
        </td>
        <td><?= $r['stand'] ? h(date('d.m.Y', strtotime((string)$r['stand']))) . ($alt !== null && $alt > $intervall ? ' <span style="color:#8f231b">!</span>' : '') : '<span class="muted">–</span>' ?></td>
        <td><form method="post" style="margin:0" onsubmit="return confirm('<?= h(lp_t('loeschen')) ?>?');"><input type="hidden" name="aktion" value="preis_del"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">&times;</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:16px">
    <input type="hidden" name="aktion" value="preis_add">
    <div class="bx-field" style="margin:0;flex:1 1 260px"><label><?= h(lp_t('rohstoff')) ?></label><input type="text" name="rohstoff_name" required maxlength="190"></div>
    <div class="bx-field" style="margin:0;width:130px"><label><?= h(lp_t('preis')) ?> (€)</label><input type="text" name="eur_kg" inputmode="decimal"></div>
    <div class="bx-field" style="margin:0;width:90px"><label><?= h(lp_t('einheit')) ?></label><input type="text" name="einheit" value="kg" maxlength="20"></div>
    <button class="btn btn-primary" type="submit"><?= h(lp_t('hinzufuegen')) ?></button>
  </form>
</div>
<?php lp_shell_ende(); lp_foot();
