<?php
// Lieferantenportal – „Rezeptur-Preise" (Fremdfertigung). Route: ?p=lieferant_rezepturpreise
// Der Lieferant traegt je Rezeptur seinen Fremdfertigungspreis (z. B. je Kapsel) ein. KEIN „Annehmen" –
// mehrere Lieferanten stehen nebeneinander und unterbieten sich; das Team sieht alle und nimmt den guenstigsten.
// 4-Wochen-Regel: sind die Preise aelter als das Intervall, erscheint ein Hinweis + „Alle als aktuell bestaetigen".
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/schema.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid  = aktueller_lieferant_id();
$spr  = lp_sprache();
$ziel = '?p=lieferant_rezepturpreise';

// Einheit-Label je Darreichungsform (der Preis gilt je Stueck/Kapsel …).
$formEinheit = fn($f) => in_array($f, ['kapsel','softgel'], true) ? 'Kapsel' : ($f === 'tablette' ? 'Tablette' : ($f === 'stick' ? 'Stick' : 'Stück'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'preis_save') {
        $aid   = (int)($_POST['id'] ?? 0);
        $preis = zahl_lesen((string)($_POST['preis'] ?? ''), false, $spr);
        q("UPDATE rezeptur_lief_angebot SET preis=?, stand=CURDATE() WHERE id=? AND lieferant_id=?",
          [$preis > 0 ? $preis : null, $aid, $lid]);
        header('Location: ' . $ziel . '&ok=1'); exit;
    }
    if ($aktion === 'preis_add') {
        $rid   = (int)($_POST['rezeptur_id'] ?? 0);
        $preis = zahl_lesen((string)($_POST['preis'] ?? ''), false, $spr);
        if ($rid > 0 && !scalar("SELECT id FROM rezeptur_lief_angebot WHERE rezeptur_id=? AND lieferant_id=?", [$rid, $lid])) {
            $form = (string) scalar("SELECT darreichungsform FROM rezeptur WHERE id=?", [$rid]);
            q("INSERT INTO rezeptur_lief_angebot (rezeptur_id,lieferant_id,preis,einheit,status,stand,angelegt) VALUES (?,?,?,?, 'angeboten', CURDATE(), NOW())",
              [$rid, $lid, $preis > 0 ? $preis : null, $formEinheit($form)]);
        }
        header('Location: ' . $ziel . '&ok=1'); exit;
    }
    if ($aktion === 'preis_del') {
        q("DELETE FROM rezeptur_lief_angebot WHERE id=? AND lieferant_id=?", [(int)($_POST['id'] ?? 0), $lid]);
        header('Location: ' . $ziel . '&ok=1'); exit;
    }
    if ($aktion === 'alle_bestaetigen') {
        q("UPDATE rezeptur_lief_angebot SET stand=CURDATE() WHERE lieferant_id=?", [$lid]);
        header('Location: ' . $ziel . '&best=1'); exit;
    }
    header('Location: ' . $ziel); exit;
}

$rows = all("SELECT la.id, la.preis, la.einheit, la.stand, r.id AS rezeptur_id, r.nummer, r.name, r.darreichungsform AS form
             FROM rezeptur_lief_angebot la JOIN rezeptur r ON r.id=la.rezeptur_id
             WHERE la.lieferant_id=? ORDER BY r.name", [$lid]);
// Rezepturen, die der Lieferant noch NICHT bepreist hat (für „weitere Rezeptur eintragen").
$offen = all("SELECT r.id, r.nummer, r.name, r.darreichungsform AS form FROM rezeptur r
              WHERE r.id NOT IN (SELECT rezeptur_id FROM rezeptur_lief_angebot WHERE lieferant_id=?)
              ORDER BY r.name", [$lid]);
$intervall = lieferant_preis_intervall($lid);
$standMax  = null; foreach ($rows as $r) if ($r['stand'] && (!$standMax || $r['stand'] > $standMax)) $standMax = $r['stand'];
$alter     = $standMax ? (int) floor((time() - strtotime((string)$standMax)) / 86400) : null;
$veraltet  = $rows && ($alter === null || $alter > $intervall);
$num = fn($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float)$x, 4, ',', '.'), '0'), ',');

lp_head('bulkify – ' . lp_t('rez_preise_menu'));
lp_shell_start('lieferant_rezepturpreise');
if (isset($_GET['ok']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if (isset($_GET['best'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('preise_bestaetigt')) . '</div>';
?>
<h1 style="margin-bottom:4px"><?= h(lp_t('rez_preise_titel')) ?></h1>
<p class="bx-sub"><?= h(lp_t('rez_preise_sub')) ?></p>

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
  <h2 style="margin-top:0"><?= h(lp_t('rez_preise_titel')) ?> <span class="muted" style="font-weight:normal">(<?= count($rows) ?>)</span></h2>
  <?php if (!$rows): ?>
    <div class="muted"><?= h(lp_t('rez_preise_leer')) ?></div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th><?= h(lp_t('rezeptur') ?: 'Rezeptur') ?></th><th><?= h(lp_t('form_lbl')) ?></th><th class="bx-num" style="width:250px"><?= h(lp_t('ihr_preis')) ?></th><th style="width:120px"><?= h(lp_t('stand')) ?></th><th style="width:50px"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $alt = $r['stand'] ? (int) floor((time() - strtotime((string)$r['stand'])) / 86400) : null; ?>
      <tr>
        <td><?= h($r['name']) ?> <span class="muted" style="font-size:12px"><?= h($r['nummer']) ?></span></td>
        <td><?= h(anfrage_art_label('rohstoff', (string)$r['form'], $spr) ?: $formEinheit($r['form'])) ?></td>
        <td class="bx-num">
          <form method="post" class="bx-row" style="gap:6px;justify-content:flex-end;align-items:center;margin:0">
            <input type="hidden" name="aktion" value="preis_save"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="text" name="preis" value="<?= h($num($r['preis'])) ?>" style="width:110px;text-align:right" inputmode="decimal">
            <span class="muted">€ / <?= h($r['einheit'] ?: $formEinheit($r['form'])) ?></span>
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
    <div class="bx-field" style="margin:0;flex:1 1 300px"><label><?= h(lp_t('rez_preise_add')) ?></label>
      <input type="text" id="rezSuche" list="rezDL" autocomplete="off" placeholder="<?= h(lp_t('rezeptur') ?: 'Rezeptur') ?> …">
      <input type="hidden" name="rezeptur_id" id="rezId">
      <datalist id="rezDL"><?php foreach ($offen as $o): $lbl = trim($o['name'] . ($o['nummer'] ? ' · ' . $o['nummer'] : '')); ?><option value="<?= h($lbl) ?>"></option><?php endforeach; ?></datalist>
    </div>
    <div class="bx-field" style="margin:0;width:140px"><label><?= h(lp_t('ihr_preis')) ?> (€)</label><input type="text" name="preis" inputmode="decimal"></div>
    <button class="btn btn-primary" type="submit"><?= h(lp_t('hinzufuegen')) ?></button>
  </form>
</div>
<script>
(function(){
  var map = {};
  <?php foreach ($offen as $o): $lbl = trim($o['name'] . ($o['nummer'] ? ' · ' . $o['nummer'] : '')); ?>map[<?= json_encode($lbl, JSON_UNESCAPED_UNICODE) ?>]=<?= (int)$o['id'] ?>;<?php endforeach; ?>
  var t=document.getElementById('rezSuche'), h=document.getElementById('rezId');
  function s(){ h.value = map[(t.value||'').trim()] || ''; }
  if(t){ t.addEventListener('input', s); t.addEventListener('change', s); }
})();
</script>
<?php lp_shell_ende(); lp_foot();
