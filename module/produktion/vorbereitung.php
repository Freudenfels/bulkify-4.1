<?php
// Vor-Produktion / Freigabe (PreProduktionsauftrag) – Dashboard-Seite.
// HIER wird ALLES angepasst, was die Produktion braucht: Glas/Behälter (mit Auto-Empfehlung), Kapselgröße,
// Etikett (hochladen + freigeben), Menge/Überproduktion, Eigen/Fremd. Mit „Freigeben" wird aus dem Vor-PA ein
// echter, startbarer Produktionsauftrag (Status offen) und wandert ins Produktionsmodul.
// Harte Weiche: der Admin kann IMMER freigeben – rote Checks sind dann nur Warnung. Rolle: admin.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) { header('Location: ?p=produktion'); exit; }

// pa -> zugehörige IDs (Auftrag/Produkt/Rezeptur) auflösen.
function vp_ids(int $pa_id): array {
    $r = one("SELECT auftrag_id, produkt_id FROM produktionsauftrag WHERE id=?", [$pa_id]) ?: [];
    $aid = (int)($r['auftrag_id'] ?? 0); $pid = (int)($r['produkt_id'] ?? 0);
    $rid = $pid ? (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pid]) : 0;
    return [$aid, $pid, $rid];
}

$R = fn($extra = '') => header('Location: ?p=produktion_vorbereitung' . $extra);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    $pid_pa = (int)($_POST['pa_id'] ?? 0);

    // Alle nicht gestarteten Aufträge in die Vor-Produktion holen.
    if ($aktion === 'alle_vorbereitung') {
        $n = vorbereitung_alle_holen();
        $R('&geholt=' . $n); exit;
    }
    // Glas/Behälter setzen (nur dieser Auftrag ODER als Produkt-Standard). Wirkt auf Produktion/Einkauf/PIB + Etikett.
    if ($aktion === 'glas_setzen' && $pid_pa) {
        [$aid, $pid] = vp_ids($pid_pa);
        $vid = ($_POST['verpackung_id'] ?? '') !== '' ? (int)$_POST['verpackung_id'] : null;
        if ($aid) q("UPDATE auftrag SET verpackung_id=? WHERE id=?", [$vid, $aid]);
        if (($_POST['verp_scope'] ?? '') === 'standard' && $vid && $pid) q("UPDATE produkt SET verpackung_id=? WHERE id=?", [$vid, $pid]);
        bedarf_bump();
        $R('&saved=1'); exit;
    }
    // Kapselgröße der Rezeptur setzen.
    if ($aktion === 'kapsel_setzen' && $pid_pa) {
        [, , $rid] = vp_ids($pid_pa);
        if ($rid) { $kg = ($_POST['kapselgroesse_id'] ?? '') !== '' ? (int)$_POST['kapselgroesse_id'] : null;
                    q("UPDATE rezeptur SET kapselgroesse_id=? WHERE id=?", [$kg, $rid]); bedarf_bump(); }
        $R('&saved=1'); exit;
    }
    // Etikett vom Team hochladen (für den Kunden).
    if ($aktion === 'etikett_upload' && $pid_pa) {
        [$aid] = vp_ids($pid_pa);
        if ($aid && etikett_upload($aid)) log_aktivitaet('kunde', (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid]), 'team', 'Etikett vom Team hochgeladen.', 'auftrag', 'auftrag', $aid);
        $R('&saved=1'); exit;
    }
    // Etikett-Freigabe im Namen des Kunden bestätigen.
    if ($aktion === 'etikett_freigeben' && $pid_pa) {
        [$aid] = vp_ids($pid_pa);
        $name = trim((string)($_POST['freigabe_name'] ?? ''));
        if ($aid && $name !== '') { $r = etikett_freigabe_setzen($aid, $name, 'team'); $R(!empty($r['ok']) ? '&saved=1' : '&fehler=' . urlencode($r['fehler'] ?? 'Freigabe nicht möglich.')); }
        else $R('&fehler=' . urlencode('Bitte einen Namen für die Freigabe angeben.'));
        exit;
    }
    // Freigeben: Eigen/Fremd + optionale Produktionsmenge setzen, Status -> offen.
    if ($aktion === 'freigeben' && $pid_pa) {
        $art = ($_POST['produktionsart'] ?? 'fremd') === 'eigen' ? 'eigen' : 'fremd';
        $mp  = trim((string)($_POST['menge_produktion'] ?? ''));
        $mp  = ($mp !== '' && ctype_digit($mp)) ? (int)$mp : null;
        $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
        $r = produktionsauftrag_freigeben($pid_pa, $art, $mp, $wer);
        $R(!empty($r['ok']) ? '&frei=1' : '&fehler=' . urlencode($r['fehler'] ?? 'Freigabe fehlgeschlagen.')); exit;
    }
    $R(); exit;
}

$rows = vorbereitung_liste();
// Behälter- und Kapselgrößen-Optionen EINMAL laden (nicht je Karte).
$verpOpt = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND COALESCE(gesperrt,0)=0 ORDER BY name");
$kapsOpt = all("SELECT id, name FROM kapselgroesse ORDER BY fuellmenge_mg");

$prioDot = function($p) {
    $p = (int)($p ?: 2);
    $f = $p === 1 ? '#d64545' : ($p === 3 ? '#9aa0a6' : '#2b6cd4');
    $t = $p === 1 ? 'Hoch' : ($p === 3 ? 'Niedrig' : 'Normal');
    return '<span title="Priorität: ' . $t . '" style="display:inline-block;width:11px;height:11px;border-radius:50%;background:' . $f . '"></span>';
};

render_header('produktion_vorbereitung', 'Vor-Produktion');
bx_head('Vor-Produktion / Freigabe',
        count($rows) . ' Auftrag(e) in Vorbereitung – hier alles festlegen und freigeben.',
        '<form method="post" style="display:inline" onsubmit="return confirm(\'Alle noch nicht gestarteten Aufträge in die Vor-Produktion holen?\');"><input type="hidden" name="aktion" value="alle_vorbereitung"><button class="btn btn-ghost" type="submit">Alle offenen Aufträge holen</button></form> '
        . bx_btn('Zu den Produktionsaufträgen', '?p=produktion', 'ghost'));
if (isset($_GET['frei']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Auftrag zur Produktion freigegeben – jetzt im Produktionsmodul startbar.</div>';
if (isset($_GET['saved']))  echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['geholt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['geholt'] . ' Auftrag(e) in die Vor-Produktion geholt.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel badge-err" style="padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
?>
<div class="bx-panel bx-keepinfo" style="padding:12px 16px;margin-bottom:14px">
  <strong>So funktioniert die Weiche:</strong> Je Auftrag Glas, Etikett, Kapselgröße, Menge und Eigen/Fremd festlegen.
  Fehlt das Glas, schlägt das System eins vor. Freigeben darfst du <em>immer</em> – rote Punkte sind nur Warnung.
  Mit der Freigabe wird der Auftrag ein echter Produktionsauftrag und ist im Werk startbar.
</div>

<?php if (!$rows): ?>
  <div class="bx-panel"><div class="muted">Aktuell nichts in Vorbereitung.</div></div>
<?php else: ?>
  <div style="display:flex;flex-direction:column;gap:14px">
  <?php foreach ($rows as $r):
        $paId = (int)$r['pa_id'];
        [$aid, $pid, $rid] = vp_ids($paId);
        $c    = pa_vorbereitung_checks($paId);
        $art  = ($r['produktionsart'] ?? 'fremd') === 'eigen' ? 'eigen' : 'fremd';
        $bedarf = (int)$c['einheiten_bedarf'];
        $verpAkt = $aid ? (int) scalar("SELECT verpackung_id FROM auftrag WHERE id=?", [$aid]) : 0;
        if (!$verpAkt && $pid) $verpAkt = (int) scalar("SELECT verpackung_id FROM produkt WHERE id=?", [$pid]);
        $empf = $verpAkt ? 0 : (int) (verpackung_empfehlung_fuer_pa($paId) ?? 0);
        $rez  = $rid ? one("SELECT darreichungsform, kapselgroesse_id FROM rezeptur WHERE id=?", [$rid]) : null;
        $istKapsel = $rez && in_array($rez['darreichungsform'] ?? '', ['kapsel','softgel'], true);
        $etDok = $aid ? etikett_datei($aid) : null;
        $etFrei = $aid ? etikett_freigegeben($aid) : false;
  ?>
    <div class="bx-panel" style="padding:16px">
      <div class="bx-row" style="justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px">
        <div>
          <div style="font-size:15px;font-weight:600"><?= h($r['produkt'] ?: '–') ?></div>
          <div class="muted" style="font-size:12px">
            <?= $prioDot($r['prio']) ?>
            <a href="?p=produktionsauftrag&id=<?= $paId ?>"><?= h($r['nummer'] ?: ('PR#' . $paId)) ?></a>
            <?php if ($aid): ?> · <a href="?p=auftrag&id=<?= $aid ?>"><?= h($r['auftrag_nr'] ?: ('AB#' . $aid)) ?></a><?php endif; ?>
            <?php if ($r['kunde']): ?> · <?= h(firma_kurz($r['kunde'])) ?><?php endif; ?>
            · <?= (int)$r['menge'] ?> Packungen
          </div>
        </div>
        <?= $c['bereit'] ? bx_badge('alles bereit', 'ok') : bx_badge('noch offen', 'warn') ?>
      </div>

      <!-- Checkliste -->
      <div style="display:flex;flex-wrap:wrap;gap:6px 18px;margin-bottom:12px">
        <?php foreach ($c['checks'] as $ck): ?>
          <span style="font-size:13px">
            <?= $ck['ok'] ? '<span style="color:#1D9E75">&#10003;</span>' : '<span style="color:#d64545">&#10007;</span>' ?>
            <?= h($ck['label']) ?> <span class="muted">(<?= h($ck['wert']) ?>)</span>
          </span>
        <?php endforeach; ?>
      </div>

      <!-- Edit-Raster: Glas, (Kapselgröße), Etikett -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;border-top:1px solid var(--line,#e6e6e6);padding-top:12px">
        <!-- Glas / Behälter -->
        <form method="post">
          <input type="hidden" name="aktion" value="glas_setzen"><input type="hidden" name="pa_id" value="<?= $paId ?>">
          <label style="font-size:12px" class="muted">Verpackung / Glas</label>
          <select name="verpackung_id" class="rscombo" style="width:100%">
            <option value="">– keine –</option>
            <?php foreach ($verpOpt as $vp): ?>
              <option value="<?= (int)$vp['id'] ?>" <?= $verpAkt === (int)$vp['id'] ? 'selected' : ($empf === (int)$vp['id'] ? 'selected' : '') ?>>
                <?= h($vp['name']) ?><?= $empf === (int)$vp['id'] ? ' (Empfehlung)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if ($empf && !$verpAkt): ?><div class="muted" style="font-size:11px;margin-top:3px">Vorschlag passend zu Kapselgröße/Menge – prüfen und speichern.</div><?php endif; ?>
          <div style="font-size:12px;margin-top:5px">
            <label style="margin-right:12px"><input type="radio" name="verp_scope" value="auftrag" checked style="width:auto"> nur dieser Auftrag</label>
            <label><input type="radio" name="verp_scope" value="standard" style="width:auto"> Produkt-Standard</label>
          </div>
          <button class="btn btn-ghost btn-sm" type="submit" style="margin-top:8px">Glas speichern</button>
        </form>

        <!-- Kapselgröße -->
        <?php if ($istKapsel): ?>
        <form method="post">
          <input type="hidden" name="aktion" value="kapsel_setzen"><input type="hidden" name="pa_id" value="<?= $paId ?>">
          <label style="font-size:12px" class="muted">Kapselgröße</label>
          <select name="kapselgroesse_id" style="width:100%">
            <option value="">– automatisch –</option>
            <?php foreach ($kapsOpt as $kg): ?>
              <option value="<?= (int)$kg['id'] ?>" <?= (int)($rez['kapselgroesse_id'] ?? 0) === (int)$kg['id'] ? 'selected' : '' ?>><?= h($kg['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-ghost btn-sm" type="submit" style="margin-top:8px">Kapselgröße speichern</button>
        </form>
        <?php endif; ?>

        <!-- Etikett -->
        <div>
          <label style="font-size:12px" class="muted">Etikett</label>
          <div style="font-size:13px;margin:2px 0 6px">
            <?php if (!auftrag_braucht_etikett((int)$aid)): ?><span class="muted">kein Etikett nötig (kein Glas)</span>
            <?php elseif ($etFrei): ?><?= bx_badge('freigegeben', 'ok') ?>
            <?php elseif ($etDok): ?><?= bx_badge('hinterlegt, nicht freigegeben', 'warn') ?>
            <?php else: ?><?= bx_badge('fehlt', 'err') ?><?php endif; ?>
          </div>
          <?php if (auftrag_braucht_etikett((int)$aid)): ?>
          <form method="post" enctype="multipart/form-data" style="margin-bottom:6px">
            <input type="hidden" name="aktion" value="etikett_upload"><input type="hidden" name="pa_id" value="<?= $paId ?>">
            <input type="file" name="etikett" required accept="application/pdf,image/*" style="font-size:12px;max-width:100%">
            <button class="btn btn-ghost btn-sm" type="submit"><?= $etDok ? 'ersetzen' : 'hochladen' ?></button>
          </form>
          <?php if ($etDok && !$etFrei): ?>
          <form method="post" class="bx-row" style="gap:6px;align-items:center;margin:0">
            <input type="hidden" name="aktion" value="etikett_freigeben"><input type="hidden" name="pa_id" value="<?= $paId ?>">
            <input type="text" name="freigabe_name" required placeholder="Name (Freigabe)" style="padding:6px 8px;border:1px solid var(--line);border-radius:8px;min-width:150px;font-size:12px">
            <button class="btn btn-primary btn-sm" type="submit">freigeben</button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Freigabe -->
      <form method="post" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap;border-top:1px solid var(--line,#e6e6e6);margin-top:12px;padding-top:12px">
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
                 oninput="var s=this.closest('form').querySelector('.bx-ueber');var d=<?= $bedarf ?>;var v=parseInt(this.value||d);s.textContent=(v>d?('+'+(v-d)+' Überschuss -> Bulk'):'');">
        </label>
        <span class="bx-ueber muted" style="font-size:11px;color:#8a6d00"></span>
        <div style="flex:1"></div>
        <button class="btn btn-primary" type="submit">Zur Produktion freigeben</button>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php render_footer(); ?>
