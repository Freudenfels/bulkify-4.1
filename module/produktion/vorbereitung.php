<?php
// Vor-Produktion / Freigabe (PreProduktionsauftrag) – Dashboard.
// Ohne ?id: TABELLE aller Aufträge in Vorbereitung (anklickbar). Mit ?id: Detail-/Edit-Seite EINES Auftrags,
// wo alles festgelegt wird: Glas/Behälter (mit Auto-Empfehlung), Kapselgröße, Etikett (hochladen + freigeben),
// Menge/Überproduktion, Eigen/Fremd. Mit „Freigeben" wird daraus ein echter, startbarer Produktionsauftrag.
// Harte Weiche: Admin kann IMMER freigeben. Rolle: admin.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) { header('Location: ?p=produktion'); exit; }

// pa -> zugehörige IDs (Auftrag/Produkt/Rezeptur).
function vp_ids(int $pa_id): array {
    $r = one("SELECT auftrag_id, produkt_id FROM produktionsauftrag WHERE id=?", [$pa_id]) ?: [];
    $aid = (int)($r['auftrag_id'] ?? 0); $pid = (int)($r['produkt_id'] ?? 0);
    $rid = $pid ? (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pid]) : 0;
    return [$aid, $pid, $rid];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $paId   = (int)($_POST['pa_id'] ?? 0);
    $back   = '?p=produktion_vorbereitung' . ($paId ? '&id=' . $paId : '');

    if ($aktion === 'alle_vorbereitung') { $n = vorbereitung_alle_holen(); header('Location: ?p=produktion_vorbereitung&geholt=' . $n); exit; }

    if ($aktion === 'glas_setzen' && $paId) {
        [$aid, $pid] = vp_ids($paId);
        $vid = ($_POST['verpackung_id'] ?? '') !== '' ? (int)$_POST['verpackung_id'] : null;
        if ($aid) q("UPDATE auftrag SET verpackung_id=? WHERE id=?", [$vid, $aid]);
        if (($_POST['verp_scope'] ?? '') === 'standard' && $vid && $pid) q("UPDATE produkt SET verpackung_id=? WHERE id=?", [$vid, $pid]);
        bedarf_bump(); header('Location: ' . $back . '&saved=1#glas'); exit;
    }
    if ($aktion === 'kapsel_setzen' && $paId) {
        [, , $rid] = vp_ids($paId);
        if ($rid) { $kg = ($_POST['kapselgroesse_id'] ?? '') !== '' ? (int)$_POST['kapselgroesse_id'] : null;
                    q("UPDATE rezeptur SET kapselgroesse_id=? WHERE id=?", [$kg, $rid]); bedarf_bump(); }
        header('Location: ' . $back . '&saved=1#glas'); exit;
    }
    if ($aktion === 'etikett_upload' && $paId) {
        [$aid] = vp_ids($paId);
        if ($aid && etikett_upload($aid)) log_aktivitaet('kunde', (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid]), 'team', 'Etikett vom Team hochgeladen.', 'auftrag', 'auftrag', $aid);
        header('Location: ' . $back . '&saved=1#etikett'); exit;
    }
    if ($aktion === 'etikett_freigeben' && $paId) {
        [$aid] = vp_ids($paId);
        $name = trim((string)($_POST['freigabe_name'] ?? ''));
        if ($aid && $name !== '') { $r = etikett_freigabe_setzen($aid, $name, 'team'); header('Location: ' . $back . (!empty($r['ok']) ? '&saved=1#etikett' : '&fehler=' . urlencode($r['fehler'] ?? 'Freigabe nicht möglich.') . '#etikett')); }
        else header('Location: ' . $back . '&fehler=' . urlencode('Bitte einen Namen für die Freigabe angeben.') . '#etikett');
        exit;
    }
    if ($aktion === 'freigeben' && $paId) {
        $art = ($_POST['produktionsart'] ?? 'fremd') === 'eigen' ? 'eigen' : 'fremd';
        $mp  = trim((string)($_POST['menge_produktion'] ?? ''));
        $mp  = ($mp !== '' && ctype_digit($mp)) ? (int)$mp : null;
        $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
        $r = produktionsauftrag_freigeben($paId, $art, $mp, $wer);
        header('Location: ' . (!empty($r['ok']) ? '?p=produktion_vorbereitung&frei=1' : $back . '&fehler=' . urlencode($r['fehler'] ?? 'Freigabe fehlgeschlagen.'))); exit;
    }
    header('Location: ?p=produktion_vorbereitung'); exit;
}

$id = (int)($_GET['id'] ?? 0);
$pa = $id ? one("SELECT * FROM produktionsauftrag WHERE id=? AND status='vorbereitung'", [$id]) : null;

$prioDot = function($p) {
    $p = (int)($p ?: 2);
    $f = $p === 1 ? '#d64545' : ($p === 3 ? '#9aa0a6' : '#2b6cd4');
    $t = $p === 1 ? 'Hoch' : ($p === 3 ? 'Niedrig' : 'Normal');
    return '<span title="Priorität: ' . $t . '" style="display:inline-block;width:11px;height:11px;border-radius:50%;background:' . $f . '"></span>';
};
$flagOk  = fn($ok) => $ok ? '<span style="color:#1D9E75">&#10003;</span>' : '<span style="color:#d64545">&#10007;</span>';

/* ============================== DETAIL (ein Auftrag) ============================== */
if ($pa):
    $paId = (int)$pa['id'];
    [$aid, $pid, $rid] = vp_ids($paId);
    $info   = one("SELECT a.nummer AS auftrag_nr, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, rz.name) AS produkt, k.firma AS kunde
                   FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id LEFT JOIN produkt p ON p.id=pa.produkt_id
                   LEFT JOIN rezeptur rz ON rz.id=COALESCE(pa.rezeptur_id,p.rezeptur_id) LEFT JOIN kunden k ON k.id=pa.kunde_id WHERE pa.id=?", [$paId]) ?: [];
    $c      = pa_vorbereitung_checks($paId);
    $bedarf = (int)$c['einheiten_bedarf'];
    $art    = ($pa['produktionsart'] ?? 'fremd') === 'eigen' ? 'eigen' : 'fremd';
    $verpAkt = $aid ? (int) scalar("SELECT verpackung_id FROM auftrag WHERE id=?", [$aid]) : 0;
    if (!$verpAkt && $pid) $verpAkt = (int) scalar("SELECT verpackung_id FROM produkt WHERE id=?", [$pid]);
    $empf   = $verpAkt ? 0 : (int) (verpackung_empfehlung_fuer_pa($paId) ?? 0);
    $rez    = $rid ? one("SELECT darreichungsform, kapselgroesse_id FROM rezeptur WHERE id=?", [$rid]) : null;
    $istKapsel = $rez && in_array($rez['darreichungsform'] ?? '', ['kapsel','softgel'], true);
    $etDok  = $aid ? etikett_datei($aid) : null;
    $etFrei = $aid ? etikett_freigegeben($aid) : false;
    $brauchtEt = auftrag_braucht_etikett((int)$aid);
    $verpOpt = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND COALESCE(gesperrt,0)=0 ORDER BY name");
    $kapsOpt = $istKapsel ? all("SELECT id, name FROM kapselgroesse ORDER BY fuellmenge_mg") : [];

    render_header('produktion_vorbereitung', 'Vor-Produktion');
    bx_head(h($pa['nummer'] ?: ('PR#' . $paId)) . ' · ' . h($info['produkt'] ?? '–'),
            trim(($info['kunde'] ? h($info['kunde']) . ' · ' : '') . (int)$pa['menge'] . ' Packungen' . ($info['auftrag_nr'] ? ' · ' . h($info['auftrag_nr']) : '')),
            bx_btn('← Zur Übersicht', '?p=produktion_vorbereitung', 'ghost'));
    if (isset($_GET['saved']))  echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
    if (isset($_GET['fehler'])) echo '<div class="bx-panel badge-err" style="padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
    ?>
    <!-- Checkliste -->
    <div class="bx-panel" style="padding:14px 16px;margin-bottom:14px">
      <div style="font-weight:600;margin-bottom:8px">Bereitschaft <?= $c['bereit'] ? bx_badge('alles bereit','ok') : bx_badge('noch offen','warn') ?></div>
      <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($c['checks'] as $ck): ?>
          <div class="bx-row" style="justify-content:space-between;gap:10px;font-size:13px">
            <span><?= $flagOk($ck['ok']) ?> <?= h($ck['label']) ?><?php if (!empty($ck['kritisch']) && !$ck['ok']): ?> <span class="muted">(wichtig)</span><?php endif; ?></span>
            <span class="muted"><?= h($ck['wert']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bx-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px">
      <!-- Verpackung / Glas -->
      <div class="bx-panel" id="glas" style="padding:16px;scroll-margin-top:80px">
        <div style="font-weight:600;margin-bottom:8px">Verpackung / Glas</div>
        <form method="post">
          <input type="hidden" name="aktion" value="glas_setzen"><input type="hidden" name="pa_id" value="<?= $paId ?>">
          <select name="verpackung_id" class="rscombo" style="width:100%">
            <option value="">– keine –</option>
            <?php foreach ($verpOpt as $vp): ?>
              <option value="<?= (int)$vp['id'] ?>" <?= ($verpAkt === (int)$vp['id'] || $empf === (int)$vp['id']) ? 'selected' : '' ?>><?= h($vp['name']) ?><?= $empf === (int)$vp['id'] ? ' (Empfehlung)' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($empf && !$verpAkt): ?><div class="muted" style="font-size:12px;margin-top:4px">Vorschlag passend zu Kapselgröße/Menge – prüfen und speichern.</div><?php endif; ?>
          <div style="font-size:13px;margin-top:8px">
            <label style="margin-right:14px"><input type="radio" name="verp_scope" value="auftrag" checked style="width:auto"> nur dieser Auftrag</label>
            <label><input type="radio" name="verp_scope" value="standard" style="width:auto"> Produkt-Standard</label>
          </div>
          <div style="margin-top:10px"><button class="btn btn-primary btn-sm" type="submit">Glas speichern</button></div>
        </form>
        <?php if ($istKapsel): ?>
        <form method="post" style="margin-top:14px;border-top:1px solid var(--line,#e6e6e6);padding-top:12px">
          <input type="hidden" name="aktion" value="kapsel_setzen"><input type="hidden" name="pa_id" value="<?= $paId ?>">
          <label class="muted" style="font-size:12px">Kapselgröße</label>
          <select name="kapselgroesse_id" style="width:100%">
            <option value="">– automatisch –</option>
            <?php foreach ($kapsOpt as $kg): ?><option value="<?= (int)$kg['id'] ?>" <?= (int)($rez['kapselgroesse_id'] ?? 0) === (int)$kg['id'] ? 'selected' : '' ?>><?= h($kg['name']) ?></option><?php endforeach; ?>
          </select>
          <div style="margin-top:10px"><button class="btn btn-ghost btn-sm" type="submit">Kapselgröße speichern</button></div>
        </form>
        <?php endif; ?>
      </div>

      <!-- Etikett -->
      <div class="bx-panel" id="etikett" style="padding:16px;scroll-margin-top:80px">
        <div style="font-weight:600;margin-bottom:8px">Etikett</div>
        <div style="font-size:13px;margin-bottom:10px">
          <?php if (!$brauchtEt): ?><span class="muted">Kein Etikett nötig (kein Glas gesetzt).</span>
          <?php elseif ($etFrei): ?><?= bx_badge('freigegeben', 'ok') ?><?php if ($etDok): ?> · <?= h((string)($etDok['datei_orig'] ?: 'Design')) ?><?php endif; ?>
          <?php elseif ($etDok): ?><?= bx_badge('hinterlegt, nicht freigegeben', 'warn') ?> · <?= h((string)($etDok['datei_orig'] ?: 'Design')) ?>
          <?php else: ?><?= bx_badge('fehlt', 'err') ?><?php endif; ?>
        </div>
        <?php if ($brauchtEt): ?>
        <form method="post" enctype="multipart/form-data" style="margin-bottom:10px">
          <input type="hidden" name="aktion" value="etikett_upload"><input type="hidden" name="pa_id" value="<?= $paId ?>">
          <input type="file" name="etikett" required accept="application/pdf,image/*" style="font-size:13px;max-width:100%">
          <div style="margin-top:8px"><button class="btn btn-ghost btn-sm" type="submit"><?= $etDok ? 'Etikett ersetzen' : 'Etikett hochladen' ?></button></div>
        </form>
        <?php if ($etDok && !$etFrei): ?>
        <form method="post" class="bx-row" style="gap:8px;align-items:center;margin:0">
          <input type="hidden" name="aktion" value="etikett_freigeben"><input type="hidden" name="pa_id" value="<?= $paId ?>">
          <input type="text" name="freigabe_name" required placeholder="Name (Freigabe im Namen des Kunden)" style="padding:7px 10px;border:1px solid var(--line);border-radius:8px;min-width:200px">
          <button class="btn btn-primary btn-sm" type="submit">Freigabe bestätigen</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Freigabe -->
    <div class="bx-panel" style="padding:16px;margin-top:14px">
      <div style="font-weight:600;margin-bottom:10px">Zur Produktion freigeben</div>
      <form method="post" class="bx-row" style="gap:14px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="freigeben"><input type="hidden" name="pa_id" value="<?= $paId ?>">
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px">
          <span class="muted">Produktion</span>
          <select name="produktionsart">
            <option value="fremd" <?= $art === 'fremd' ? 'selected' : '' ?>>Fremd (Zukauf)</option>
            <option value="eigen" <?= $art === 'eigen' ? 'selected' : '' ?>>Eigen</option>
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px">
          <span class="muted">Produktionsmenge (Einheiten)</span>
          <input type="number" name="menge_produktion" min="<?= $bedarf ?>" step="1" placeholder="<?= $bedarf ?>" value="<?= $bedarf ?>"
                 oninput="var s=document.getElementById('ueb');var d=<?= $bedarf ?>;var v=parseInt(this.value||d);s.textContent=(v>d?('+'+(v-d)+' Überschuss -> Bulk'):'');">
        </label>
        <span id="ueb" class="muted" style="font-size:11px;color:#8a6d00"></span>
        <div style="flex:1"></div>
        <button class="btn btn-primary" type="submit">Freigeben</button>
      </form>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Du kannst immer freigeben – offene Punkte oben sind dann nur ein Hinweis (die Produktion wartet ggf. auf Material).</p>
    </div>
    <?php render_footer(); return; ?>
<?php endif; /* Detail */ ?>

<?php
/* ============================== TABELLE (Übersicht) ============================== */
$rows = vorbereitung_liste();
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $rows = array_values(array_filter($rows, function($r) use ($q) {
        foreach (['produkt','rezeptur','kunde','nummer','auftrag_nr'] as $f)
            if (mb_stripos((string)($r[$f] ?? ''), $q) !== false) return true;
        return false;
    }));
}
render_header('produktion_vorbereitung', 'Vor-Produktion');
bx_head('Vor-Produktion / Freigabe', count($rows) . ' Auftrag(e) in Vorbereitung' . ($q !== '' ? ' (gefiltert)' : '') . ' – zum Bearbeiten anklicken.',
        '<form method="post" style="display:inline" onsubmit="return confirm(\'Alle noch nicht gestarteten Aufträge in die Vor-Produktion holen?\');"><input type="hidden" name="aktion" value="alle_vorbereitung"><button class="btn btn-ghost" type="submit">Alle offenen Aufträge holen</button></form> '
        . bx_btn('Zu den Produktionsaufträgen', '?p=produktion', 'ghost'));
if (isset($_GET['frei']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Auftrag zur Produktion freigegeben – jetzt im Produktionsmodul startbar.</div>';
if (isset($_GET['geholt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['geholt'] . ' Auftrag(e) in die Vor-Produktion geholt.</div>';
?>
<form method="get" class="bx-row" style="gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
  <input type="hidden" name="p" value="produktion_vorbereitung">
  <input type="text" name="q" value="<?= h($q) ?>" placeholder="Suche: Produkt, Rezeptur, Kunde, Nummer …" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;min-width:300px;flex:1;max-width:460px">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=produktion_vorbereitung">×</a><?php endif; ?>
</form>
<?php if (!$rows): ?>
  <div class="bx-panel"><div class="muted"><?= $q !== '' ? 'Keine Treffer für „' . h($q) . '".' : 'Aktuell nichts in Vorbereitung.' ?></div></div>
<?php else: ?>
<div class="bx-panel" style="padding:0;overflow:hidden">
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr>
      <th>Prio</th><th>Nummer</th><th>Kunde</th><th>Produkt</th><th class="bx-num">Pack.</th>
      <th>Glas</th><th>Etikett</th><th>Eigen/Fremd</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $paId = (int)$r['pa_id'];
        [$aid, $pid] = vp_ids($paId);
        $verpAkt = $aid ? (int) scalar("SELECT verpackung_id FROM auftrag WHERE id=?", [$aid]) : 0;
        if (!$verpAkt && $pid) $verpAkt = (int) scalar("SELECT verpackung_id FROM produkt WHERE id=?", [$pid]);
        $glasOk = $verpAkt > 0;
        $brauchtEt = auftrag_braucht_etikett((int)$aid);
        $etFrei = $brauchtEt && $aid ? etikett_freigegeben($aid) : false;
        $href = '?p=produktion_vorbereitung&id=' . $paId;
    ?>
      <tr onclick="location.href='<?= $href ?>'" style="cursor:pointer">
        <td><?= $prioDot($r['prio']) ?></td>
        <td><a href="<?= $href ?>"><?= h($r['nummer'] ?: ('PR#' . $paId)) ?></a><?php if ($r['auftrag_nr']): ?><br><span class="muted" style="font-size:12px"><?= h($r['auftrag_nr']) ?></span><?php endif; ?></td>
        <td><?= $r['kunde'] ? h(firma_kurz($r['kunde'])) : '<span class="muted">–</span>' ?></td>
        <td><?= h($r['produkt'] ?: '–') ?></td>
        <td class="bx-num"><?= (int)$r['menge'] ?></td>
        <td><?= $glasOk ? $flagOk(true) : '<span style="color:#d64545">fehlt</span>' ?></td>
        <td><?php if (!$brauchtEt): ?><span class="muted">–</span><?php else: ?><?= $etFrei ? $flagOk(true) : '<span style="color:#d64545">fehlt</span>' ?><?php endif; ?></td>
        <td><?= ($r['produktionsart'] ?? 'fremd') === 'eigen' ? bx_badge('Eigen','ok') : bx_badge('Fremd','info') ?></td>
        <td style="text-align:right"><span class="muted" style="font-size:18px">&#8250;</span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php render_footer(); ?>
