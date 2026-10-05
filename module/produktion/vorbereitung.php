<?php
// Vor-Produktion / Freigabe (PreProduktionsauftrag) – Dashboard-Seite.
// HIER wird final entschieden, ob die Produktion durchgeführt werden kann: passt das Glas? Eigen/Fremd?
// Etikett da? Kartons/Gläser/Rohstoffe/Bulk da? Menge (ggf. Überproduktion)? Mit „Freigeben" wird aus dem
// Vor-PA ein echter, startbarer Produktionsauftrag (Status offen) und wandert ins Produktionsmodul.
// Harte Weiche: der Admin kann IMMER freigeben – rote Checks sind dann nur Warnung. Rolle: admin.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Freigeben: Eigen/Fremd + optionale Produktionsmenge setzen, Status -> offen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'freigeben') {
    if (!has_role('admin')) { header('Location: ?p=produktion_vorbereitung&fehler=' . urlencode('Nur Admins dürfen freigeben.')); exit; }
    $pid  = (int)($_POST['pa_id'] ?? 0);
    $art  = ($_POST['produktionsart'] ?? 'fremd') === 'eigen' ? 'eigen' : 'fremd';
    $mp   = trim((string)($_POST['menge_produktion'] ?? ''));
    $mp   = ($mp !== '' && ctype_digit($mp)) ? (int)$mp : null;
    $wer  = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
    $r = produktionsauftrag_freigeben($pid, $art, $mp, $wer);
    header('Location: ?p=produktion_vorbereitung&' . (!empty($r['ok']) ? 'frei=1' : 'fehler=' . urlencode($r['fehler'] ?? 'Freigabe fehlgeschlagen.'))); exit;
}

$rows = vorbereitung_liste();

$prioDot = function($p) {
    $p = (int)($p ?: 2);
    $f = $p === 1 ? '#d64545' : ($p === 3 ? '#9aa0a6' : '#2b6cd4');
    $t = $p === 1 ? 'Hoch' : ($p === 3 ? 'Niedrig' : 'Normal');
    return '<span title="Priorität: ' . $t . '" style="display:inline-block;width:11px;height:11px;border-radius:50%;background:' . $f . '"></span>';
};

render_header('produktion_vorbereitung', 'Vor-Produktion');
bx_head('Vor-Produktion / Freigabe',
        count($rows) . ' Auftrag(e) in Vorbereitung – prüfen und zur Produktion freigeben.',
        bx_btn('Zu den Produktionsaufträgen', '?p=produktion', 'ghost'));
if (isset($_GET['frei']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Auftrag zur Produktion freigegeben – er ist jetzt im Produktionsmodul startbar.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel badge-err" style="padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
?>
<div class="bx-panel bx-keepinfo" style="padding:12px 16px;margin-bottom:14px">
  <strong>So funktioniert die Weiche:</strong> Unten steht je Auftrag die Checkliste. Freigeben darfst du <em>immer</em> –
  rote Punkte sind dann nur eine Warnung (z.&nbsp;B. „Etikett fehlt"). Mit der Freigabe wird der Auftrag ein echter
  Produktionsauftrag und ist im Werk startbar. Vorher ist er dort nur sichtbar, aber gesperrt.
</div>

<?php if (!$rows): ?>
  <div class="bx-panel"><div class="muted">Aktuell nichts in Vorbereitung.</div></div>
<?php else: ?>
  <div class="bx-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px">
  <?php foreach ($rows as $r):
        $paId = (int)$r['pa_id'];
        $c    = pa_vorbereitung_checks($paId);
        $art  = ($r['produktionsart'] ?? 'fremd') === 'eigen' ? 'eigen' : 'fremd';
        $bedarf = (int)$c['einheiten_bedarf'];
  ?>
    <div class="bx-panel" style="padding:16px;display:flex;flex-direction:column;gap:12px">
      <div class="bx-row" style="justify-content:space-between;align-items:flex-start;gap:10px">
        <div>
          <div style="font-size:15px;font-weight:600"><?= h($r['produkt'] ?: '–') ?></div>
          <div class="muted" style="font-size:12px">
            <?= $prioDot($r['prio']) ?>
            <a href="?p=produktionsauftrag&id=<?= $paId ?>"><?= h($r['nummer'] ?: ('PR#' . $paId)) ?></a>
            <?php if ($r['auftrag_id']): ?> · <a href="?p=auftrag&id=<?= (int)$r['auftrag_id'] ?>"><?= h($r['auftrag_nr'] ?: ('AB#' . (int)$r['auftrag_id'])) ?></a><?php endif; ?>
            <?php if ($r['kunde']): ?> · <?= h(firma_kurz($r['kunde'])) ?><?php endif; ?>
          </div>
        </div>
        <?= $c['bereit'] ? bx_badge('alles bereit', 'ok') : bx_badge('noch offen', 'warn') ?>
      </div>

      <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($c['checks'] as $ck): ?>
          <div class="bx-row" style="justify-content:space-between;gap:10px;font-size:13px">
            <span>
              <?= $ck['ok'] ? '<span style="color:#1D9E75">&#10003;</span>' : '<span style="color:#d64545">&#10007;</span>' ?>
              <?= h($ck['label']) ?><?php if (!empty($ck['kritisch']) && !$ck['ok']): ?> <span class="muted">(wichtig)</span><?php endif; ?>
            </span>
            <span class="muted"><?= h($ck['wert']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (empty($c['checks'])): ?><div class="muted" style="font-size:13px">Keine Prüfpunkte (kein Produkt/Bedarf am Auftrag).</div><?php endif; ?>
      </div>

      <?php if (!$c['checks'] || !$c['checks'][0]['ok']): // Glas nicht gewählt -> direkter Weg ?>
        <div class="muted" style="font-size:12px">Verpackung/Glas am Auftrag festlegen:
          <?php if ($r['auftrag_id']): ?><a href="?p=auftrag&id=<?= (int)$r['auftrag_id'] ?>">Auftrag öffnen</a><?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;border-top:1px solid var(--line,#e6e6e6);padding-top:12px">
        <input type="hidden" name="aktion" value="freigeben">
        <input type="hidden" name="pa_id" value="<?= $paId ?>">
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px">
          <span class="muted">Produktion</span>
          <select name="produktionsart">
            <option value="fremd" <?= $art === 'fremd' ? 'selected' : '' ?>>Fremd (Zukauf)</option>
            <option value="eigen" <?= $art === 'eigen' ? 'selected' : '' ?>>Eigen</option>
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px">
          <span class="muted">Produktionsmenge (Einheiten)</span>
          <input type="number" name="menge_produktion" min="<?= $bedarf ?>" step="1"
                 placeholder="<?= $bedarf ?>" value="<?= $bedarf ?>"
                 oninput="var s=this.closest('form').querySelector('.bx-ueber');var d=<?= $bedarf ?>;var v=parseInt(this.value||d);s.textContent=(v>d?('+'+(v-d)+' Überschuss -> Bulk'):'');">
        </label>
        <div style="flex:1;min-width:120px">
          <span class="bx-ueber muted" style="font-size:11px;color:#8a6d00"></span>
        </div>
        <?php if (has_role('admin')): ?>
          <button class="btn btn-primary" type="submit">Zur Produktion freigeben</button>
        <?php else: ?>
          <span class="muted" style="font-size:12px">Freigabe nur durch Admin.</span>
        <?php endif; ?>
      </form>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php render_footer(); ?>
