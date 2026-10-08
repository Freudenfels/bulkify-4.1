<?php
// Lieferantenportal – „Meine Preisliste" = Rohstoff-Preise. Route: ?p=lieferant_preisliste
// Hauptinhalt: die von bulkify bei DIESEM Lieferanten gefuehrten Rohstoffe (item + lieferant_preis) mit
// Staffel/Waehrung. Der Lieferant schlaegt neue Preise vor -> Pruef-Zeile beim Team (Katalog-Freigaben),
// nie direkt live. „Mein Katalog" ist davon getrennt (reines Portfolio, was er anbietet).
// Zusatz unten: evtl. vorhandene freie Preisangaben im Alt-Format (lieferant_preisliste, ohne Staffel) –
// bleiben pflegbar, damit nichts verloren geht. 4-Wochen-Regel gilt (wie bisher) fuer diese Alt-Liste.
require_once BX_ROOT . '/module/lieferant/portal_layout.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/lieferant_katalog.php';
if (!ist_lieferant()) { header('Location: ?p=lieferant_login'); exit; }

$lid  = aktueller_lieferant_id();
$spr  = lp_sprache();
$ziel = '?p=lieferant_preisliste';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    // Gefuehrter Rohstoff: neuer Preis als Vorschlag ans Team (Staffel moeglich). Nie direkt live.
    if ($aktion === 'preis_vorschlag') {
        $r = katalog_preis_vorschlag($lid, (int)($_POST['item_id'] ?? 0),
            trim((string)($_POST['preis'] ?? '')) !== '' ? zahl_lesen((string)$_POST['preis'], false, $spr) : 0.0,
            trim((string)($_POST['menge_ab'] ?? '')) !== '' ? zahl_lesen((string)$_POST['menge_ab'], true, $spr) : null,
            (string)($_POST['einheit'] ?? ''), (string)($_POST['waehrung'] ?? 'EUR'));
        header('Location: ' . $ziel . ($r['ok'] ? '&preis_ok=1' : '&fehler=' . urlencode($r['msg']))); exit;
    }
    // Alt-Format (freie Zeilen ohne Staffel) – wie bisher direkt pflegbar.
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

$gefuehrt  = lieferant_gefuehrte_artikel($lid);
$rows      = lieferant_preisliste_fuer($lid);
$veraltet  = lieferant_preise_veraltet($lid);
$alter     = lieferant_preise_alter_tage($lid);
$intervall = lieferant_preis_intervall($lid);
$num = fn($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float)$x, 4, ',', '.'), '0'), ',');
// Gehalt lesbar (wie in „Mein Katalog"): Wert + Einheit.
$gehEinh = ['prozent'=>'%','ie_g'=>'I.E./g','ie_kg'=>'I.E./kg','mg_g'=>'mg/g','ug_g'=>'µg/g'];
$gehaltTxt = function($w) use ($gehEinh): string {
    $v = $w['gehalt_wert'] !== null ? $w['gehalt_wert'] : $w['gehalt_prozent'];
    if ($v === null || $v === '') return '';
    $e = $gehEinh[(string)($w['gehalt_einheit'] ?? 'prozent')] ?? '%';
    return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.') . ' ' . $e;
};

lp_head('bulkify – ' . lp_t('preisliste'));
lp_shell_start('lieferant_preisliste');
if (isset($_GET['ok']))      echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('gespeichert')) . '</div>';
if (isset($_GET['best']))    echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('preise_bestaetigt')) . '</div>';
if (isset($_GET['preis_ok']))echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h(lp_t('preis_akt_ok')) . '</div>';
if (isset($_GET['fehler']))  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
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
<?php endif; ?>

<?php // ===== Von bulkify gefuehrte Rohstoffe (Hauptinhalt: Staffel/Waehrung, Update als Vorschlag) ===== ?>
<div class="bx-panel">
  <h2 style="margin-top:0"><?= h(lp_t('gefuehrt_titel')) ?> <span class="muted" style="font-weight:normal">(<?= count($gefuehrt) ?>)</span></h2>
  <p class="muted" style="margin:6px 0 0;font-size:13px"><?= h(lp_t('gefuehrt_sub')) ?></p>
  <?php if (!$gefuehrt): ?>
    <div class="muted" style="margin-top:12px"><?= h(lp_t('gefuehrt_leer')) ?></div>
  <?php else: ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr>
      <th><?= h(lp_t('artikel')) ?></th><th><?= h(lp_t('produkttyp')) ?></th>
      <th><?= h(lp_t('unsere_spec')) ?></th><th><?= h(lp_t('ihr_preis')) ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($gefuehrt as $g): ?>
      <tr>
        <td><?= h((string)$g['name']) ?>
          <?php if (!empty($g['ist_haupt'])): ?> <span class="bx-badge" style="font-size:11px"><?= h(lp_t('haupt_lief')) ?></span><?php endif; ?>
          <div class="muted" style="font-size:12px"><?= h((string)$g['artikelnummer']) ?><?php if ($g['name_lat']): ?> · <em><?= h((string)$g['name_lat']) ?></em><?php endif; ?><?php if ($g['cas']): ?> · CAS <?= h((string)$g['cas']) ?><?php endif; ?></div>
        </td>
        <td><?= h(anfrage_art_label('rohstoff', (string)$g['form'], $spr)) ?></td>
        <td style="font-size:13px">
          <?php $specParts = [];
            foreach ($g['wirkstoffe'] as $w) { $t = trim((string)$w['name'] . ' ' . $gehaltTxt($w)); if ($t !== '') $specParts[] = h($t); }
            foreach ($g['kennwerte'] as $k) { $p = trim((string)$k['parameter']); if ($p !== '') $specParts[] = h($p . ($k['wert'] !== null && $k['wert'] !== '' ? ': ' . (string)$k['wert'] : '')); }
            echo $specParts ? implode('<br>', $specParts) : '<span class="muted">–</span>';
          ?>
        </td>
        <td style="font-size:13px">
          <?php if (!$g['preise']): ?><span class="muted"><?= h(lp_t('kein_preis')) ?></span>
          <?php else: foreach ($g['preise'] as $p): ?>
            <div><?= h(lp_num($p['preis'], 4)) ?> <?= h((string)($p['waehrung'] ?: 'EUR')) ?><?= $g['einheit'] ? ' / ' . h((string)$g['einheit']) : '' ?><?php if ((float)$p['menge_ab'] > 0): ?> <span class="muted">· <?= h(lp_t('ab_menge')) ?> <?= h(lp_num($p['menge_ab'], 3)) ?></span><?php endif; ?></div>
          <?php endforeach; endif; ?>
        </td>
        <td class="bx-num" style="white-space:nowrap">
          <button type="button" class="btn btn-ghost btn-sm bx-preis-akt"
                  data-id="<?= (int)$g['id'] ?>" data-name="<?= h((string)$g['name']) ?>"
                  data-einheit="<?= h((string)$g['einheit']) ?>"><?= h(lp_t('preis_akt')) ?></button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<dialog id="dlgPreis" class="bx-dialog">
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px">
    <h2 style="margin:0"><?= h(lp_t('preis_akt_titel')) ?></h2>
    <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('dlgPreis').close()" aria-label="schließen">&#10005;</button>
  </div>
  <p class="muted" style="margin:8px 0 10px;font-size:13px" id="preisArtikel"></p>
  <p class="muted" style="margin:0 0 12px;font-size:12px"><?= h(lp_t('preis_akt_sub')) ?></p>
  <form method="post">
    <input type="hidden" name="aktion" value="preis_vorschlag">
    <input type="hidden" name="item_id" id="pf_item" value="">
    <div class="bx-grid">
      <div class="bx-field" style="max-width:150px"><label><?= h(lp_t('preis')) ?></label><input type="text" name="preis" id="pf_preis" required></div>
      <div class="bx-field" style="max-width:90px"><label><?= h(lp_t('waehrung')) ?></label><input type="text" name="waehrung" value="EUR" maxlength="3"></div>
      <div class="bx-field" style="max-width:120px"><label><?= h(lp_t('einheit')) ?></label><input type="text" name="einheit" id="pf_einheit" placeholder="kg" maxlength="20"></div>
      <div class="bx-field" style="max-width:140px"><label><?= h(lp_t('ab_menge')) ?></label><input type="text" name="menge_ab" class="lp-menge"></div>
    </div>
    <div class="bx-row" style="gap:8px;margin-top:6px">
      <button class="btn btn-primary" type="submit"><?= h(lp_t('senden')) ?></button>
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('dlgPreis').close()"><?= h(lp_t('abbrechen')) ?></button>
    </div>
  </form>
</dialog>
<style>
  .bx-dialog{border:1px solid var(--line);border-radius:14px;max-width:560px;width:calc(100% - 32px);padding:22px 24px;
             background:var(--panel);color:var(--text);box-shadow:0 24px 70px rgba(0,0,0,.45);color-scheme:light dark}
  .bx-dialog h2{color:var(--text)}
  .bx-dialog::backdrop{background:rgba(0,0,0,.55)}
</style>
<script>
(function(){
  var dlg=document.getElementById('dlgPreis'); if(!dlg) return;
  dlg.addEventListener('click', function(e){ if(e.target===dlg) dlg.close(); });
  document.querySelectorAll('.bx-preis-akt').forEach(function(b){
    b.addEventListener('click',function(){
      var d=b.dataset;
      document.getElementById('pf_item').value=d.id;
      document.getElementById('pf_einheit').value=d.einheit||'';
      document.getElementById('pf_preis').value='';
      var a=document.getElementById('preisArtikel'); if(a) a.textContent=d.name||'';
      dlg.showModal(); document.getElementById('pf_preis').focus();
    });
  });
})();
</script>

<?php // ===== Weitere Preisangaben im Alt-Format (ohne Staffel) – nur falls vorhanden ===== ?>
<?php if ($rows): ?>
<div class="bx-panel">
  <h2 style="margin-top:0"><?= h(lp_t('preis_weitere')) ?> <span class="muted" style="font-weight:normal">(<?= count($rows) ?>)</span></h2>
  <p class="muted" style="margin:6px 0 0;font-size:13px"><?= h(lp_t('preis_weitere_sub')) ?></p>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
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

  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:16px">
    <input type="hidden" name="aktion" value="preis_add">
    <div class="bx-field" style="margin:0;flex:1 1 260px"><label><?= h(lp_t('rohstoff')) ?></label><input type="text" name="rohstoff_name" required maxlength="190"></div>
    <div class="bx-field" style="margin:0;width:130px"><label><?= h(lp_t('preis')) ?> (€)</label><input type="text" name="eur_kg" inputmode="decimal"></div>
    <div class="bx-field" style="margin:0;width:90px"><label><?= h(lp_t('einheit')) ?></label><input type="text" name="einheit" value="kg" maxlength="20"></div>
    <button class="btn btn-ghost" type="submit"><?= h(lp_t('hinzufuegen')) ?></button>
  </form>
</div>
<?php endif; ?>
<?php lp_shell_ende(); lp_foot();
