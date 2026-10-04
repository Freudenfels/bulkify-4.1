<?php
// Vertriebs-Pipeline: alle Kontakte als Board nach Phase. Route: ?p=pipeline
// Der zentrale Verkaeufer-Blick - Karten per Drag & Drop (Desktop) oder Dropdown (Handy) zwischen
// den Phasen verschieben. Alles laeuft auf crm_kontakt; geschrieben wird nur die Phase.
require_once BX_ROOT . '/core/kontakt.php';

$phasen = crm_phasen();

// --- Phase verschieben (Drag & Drop per fetch, oder Dropdown per Formular) ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tun'] ?? '') === 'phase') {
    $kid   = (int)($_POST['id'] ?? 0);
    $phase = (string)($_POST['phase'] ?? '');
    if ($kid > 0) kontakt_phase_setzen($kid, $phase, crm_uid());
    if (($_POST['ajax'] ?? '') === '1') { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
    header('Location: ?p=pipeline' . pipeline_query()); exit;
}

// Filter aus der URL als Query-String zusammenbauen (fuer Redirects/Links).
function pipeline_query(): string {
    $q = [];
    if (trim((string)($_GET['quelle'] ?? '')) !== '') $q['quelle'] = (string)$_GET['quelle'];
    if (trim((string)($_GET['sort'] ?? '')) !== '')   $q['sort']   = (string)$_GET['sort'];
    if (!empty($_GET['archiv'])) $q['archiv'] = '1';
    return $q ? '&' . http_build_query($q) : '';
}

$sort = (string)($_GET['sort'] ?? 'neu');
$order = match ($sort) {
    'az'  => "COALESCE(NULLIF(firma,''), name) ASC",
    'za'  => "COALESCE(NULLIF(firma,''), name) DESC",
    'alt' => "angelegt ASC",
    default => "angelegt DESC",
};
$fQuelle = trim((string)($_GET['quelle'] ?? ''));
$archiv  = !empty($_GET['archiv']);

$sql  = "SELECT * FROM crm_kontakt WHERE archiviert = ?";
$args = [$archiv ? 1 : 0];
if ($fQuelle !== '' && array_key_exists($fQuelle, crm_quellen())) { $sql .= " AND quelle = ?"; $args[] = $fQuelle; }
$sql .= " ORDER BY $order LIMIT 500";
$liste = all($sql, $args);

// Nach Phase gruppieren.
$byPhase = [];
foreach ($phasen as $k => $_) $byPhase[$k] = [];
foreach ($liste as $l) {
    $p = array_key_exists($l['phase'], $phasen) ? $l['phase'] : array_key_first($phasen);
    $byPhase[$p][] = $l;
}
$gesamt = count($liste);
$archivN = (int) scalar("SELECT COUNT(*) FROM crm_kontakt WHERE archiviert = 1");

// Mitarbeiter-Namen fuer den "Zustaendig"-Tag.
$mitarbeiter = [];
foreach (erp_mitarbeiter() as $u) $mitarbeiter[(int)$u['id']] = (string)$u['name'];

kopf('Pipeline', 'pipeline');
seitenkopf('Vertriebs-Pipeline', $gesamt . ($gesamt === 1 ? ' Kontakt' : ' Kontakte') . ($archiv ? ' im Archiv' : ''),
    '<a class="btn btn-ghost" href="?p=erfassen">Neu erfassen</a> <a class="btn btn-primary" href="?p=mail">Anfrage einlesen</a>');
?>
<style>
.crm-board{display:flex;gap:12px;align-items:flex-start;overflow-x:auto;padding-bottom:10px}
.crm-col{flex:1 1 0;min-width:160px;background:var(--panel-2,rgba(127,127,114,.06));border:1px solid var(--linie,var(--linie-fein,#e5e5e0));border-radius:14px;padding:8px;display:flex;flex-direction:column;gap:6px}
.crm-colhead{display:flex;align-items:center;gap:8px;font-size:.95rem;padding:2px 4px 8px;border-bottom:2px solid var(--pcol,#7a7a72)}
.crm-colhead .n{margin-left:auto;background:var(--pcol,#7a7a72);color:#fff;border-radius:999px;padding:1px 9px;font-size:.8rem}
.crm-card{display:block;background:var(--panel,#fff);border:1px solid var(--linie-fein,#e5e5e0);border-radius:9px;padding:7px 10px;text-decoration:none;color:inherit}
.crm-card:hover{border-color:var(--pcol,#7a7a72)}
.crm-card .cn{display:flex;justify-content:space-between;gap:8px;align-items:baseline;line-height:1.3}
.crm-card .cn b{font-weight:600}
.crm-card .cs{font-size:.8rem;color:var(--text-leise,#777);display:block;margin-top:1px;word-break:break-word}
.crm-wert{font-size:.78rem;font-weight:600;color:var(--gruen,#1D9E75);white-space:nowrap;flex:none}
.crm-tag{font-size:.72rem;background:rgba(127,127,114,.14);color:var(--text-leise,#777);border-radius:6px;padding:1px 7px;display:inline-block;margin-top:4px}
.crm-prio-hoch{border-left:3px solid #c0392b}
.crm-age{font-size:.72rem;color:var(--text-leise,#777);display:block;margin-top:3px}
.crm-age.warn{color:#c0392b;font-weight:600}
.crm-empty{font-size:.85rem;color:var(--text-leise,#777);padding:8px 4px;text-align:center}
.crm-lead{cursor:grab}
.crm-lead.dragging{opacity:.4}
.crm-col.drop-hover{outline:2px dashed var(--pcol,#7a7a72);outline-offset:-4px}
.crm-move{display:none;margin-top:6px}
.crm-move select{font-size:.8rem;width:100%}
.crm-pfilter{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px}
.crm-pfilter input,.crm-pfilter select{width:auto;min-width:160px}
@media(max-width:860px){
  .crm-board{flex-direction:column;overflow-x:visible}
  .crm-col{width:100%}
  .crm-move{display:block}
  .crm-pfilter input,.crm-pfilter select{width:100%;min-width:0}
}
</style>

<div class="crm-pfilter">
  <input type="search" id="crmSearch" placeholder="Suchen (Name, Firma, E-Mail, Telefon …)" autocomplete="off">
  <select onchange="location.href=crmPUrl('quelle', this.value)">
    <option value="">Alle Quellen</option>
    <?php foreach (crm_quellen() as $qk => $qv): ?><option value="<?= h($qk) ?>"<?= $qk === $fQuelle ? ' selected' : '' ?>><?= h($qv) ?></option><?php endforeach; ?>
  </select>
  <select onchange="location.href=crmPUrl('sort', this.value)">
    <?php foreach (['neu' => 'Neuste zuerst', 'alt' => 'Älteste zuerst', 'az' => 'A–Z', 'za' => 'Z–A'] as $sk => $sl): ?>
      <option value="<?= $sk ?>"<?= $sk === $sort ? ' selected' : '' ?>><?= h($sl) ?></option>
    <?php endforeach; ?>
  </select>
  <a class="btn btn-ghost btn-sm" style="margin-left:auto" href="?p=pipeline<?= $archiv ? '' : '&archiv=1' ?>"><?= $archiv ? 'Aktive anzeigen' : 'Archiv (' . $archivN . ')' ?></a>
</div>

<div class="crm-board">
  <?php foreach ($phasen as $pk => $plabel): $col = $byPhase[$pk] ?? []; $farbe = crm_phase_farbe($pk); ?>
    <div class="crm-col" data-phase="<?= h($pk) ?>" style="--pcol:<?= h($farbe) ?>">
      <div class="crm-colhead"><span><?= h($plabel) ?></span><span class="n"><?= count($col) ?></span></div>
      <?php if (!$col): ?>
        <div class="crm-empty">–</div>
      <?php else: foreach ($col as $l):
            $titel = trim((string)$l['firma']) !== '' ? $l['firma'] : ($l['name'] ?: 'Kontakt #' . $l['id']);
            $sub = (trim((string)$l['firma']) !== '' && trim((string)$l['name']) !== '') ? $l['name'] : '';
            $srch = strtolower(($l['name'] ?? '') . ' ' . ($l['firma'] ?? '') . ' ' . ($l['email'] ?? '') . ' ' . ($l['telefon'] ?? ''));
            $tage = tage_seit((string)($l['phase_at'] ?? $l['angelegt']));
            $warn = ($pk === 'angebot' && $tage >= CRM_ANGEBOT_NACHFASSEN);
            $bid = (int)($l['besitzer_id'] ?? 0); ?>
        <div class="crm-lead" draggable="true" data-id="<?= (int)$l['id'] ?>" data-search="<?= h($srch) ?>">
          <a class="crm-card<?= ($l['prioritaet'] ?? '') === 'hoch' ? ' crm-prio-hoch' : '' ?>" draggable="false" href="?p=kontakt&id=<?= (int)$l['id'] ?>">
            <span class="cn"><b><?= h($titel) ?></b><?php if ($l['wert_eur'] !== null): ?> <span class="crm-wert"><?= h(eur((float)$l['wert_eur'])) ?></span><?php endif; ?></span>
            <?php if ($sub !== ''): ?><span class="cs"><?= h($sub) ?></span><?php endif; ?>
            <?php if ($bid > 0 && isset($mitarbeiter[$bid])): ?><span class="crm-tag"><?= h($mitarbeiter[$bid]) ?></span><?php endif; ?>
            <span class="crm-age<?= $warn ? ' warn' : '' ?>">seit <?= $tage ?> Tg<?= $warn ? ' · nachfassen' : '' ?></span>
          </a>
          <div class="crm-move">
            <form method="post" style="margin:0">
              <input type="hidden" name="tun" value="phase"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <select name="phase" onchange="this.form.submit()" title="Phase ändern">
                <?php foreach ($phasen as $mk => $mlab): ?><option value="<?= h($mk) ?>"<?= $mk === $l['phase'] ? ' selected' : '' ?>><?= h($mlab) ?></option><?php endforeach; ?>
              </select>
            </form>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<p class="muted" id="crmNoMatch" style="display:none;margin-top:12px">Keine Treffer.</p>

<script>
function crmPUrl(key, val){
  var u = new URL(location.href);
  u.searchParams.set('p','pipeline');
  if(val==='' || val==null) u.searchParams.delete(key); else u.searchParams.set(key, val);
  return u.pathname + u.search;
}
(function(){
  var s = document.getElementById('crmSearch'); if(!s) return;
  var cards = document.querySelectorAll('.crm-lead'), nm = document.getElementById('crmNoMatch');
  s.addEventListener('input', function(){
    var q = s.value.trim().toLowerCase(), n = 0;
    cards.forEach(function(c){ var hit = !q || c.getAttribute('data-search').indexOf(q) >= 0; c.style.display = hit ? '' : 'none'; if(hit) n++; });
    if(nm) nm.style.display = (cards.length && !n) ? 'block' : 'none';
  });
})();
// Drag & Drop: Karte in eine andere Phasen-Spalte ziehen -> Phase wird gespeichert (Dropdown bleibt Fallback fuers Handy).
(function(){
  var board = document.querySelector('.crm-board'); if(!board || !('draggable' in document.createElement('div'))) return;
  var dragged = null;
  board.querySelectorAll('.crm-lead').forEach(function(el){
    el.addEventListener('dragstart', function(){ dragged = el; el.classList.add('dragging'); });
    el.addEventListener('dragend', function(){ el.classList.remove('dragging'); board.querySelectorAll('.crm-col.drop-hover').forEach(function(c){ c.classList.remove('drop-hover'); }); });
  });
  board.querySelectorAll('.crm-col').forEach(function(col){
    col.addEventListener('dragover', function(e){ e.preventDefault(); col.classList.add('drop-hover'); });
    col.addEventListener('dragleave', function(e){ if(!col.contains(e.relatedTarget)) col.classList.remove('drop-hover'); });
    col.addEventListener('drop', function(e){
      e.preventDefault(); col.classList.remove('drop-hover');
      if(!dragged) return;
      var newPhase = col.getAttribute('data-phase'), id = dragged.getAttribute('data-id');
      var sel = dragged.querySelector('select[name=phase]');
      if(sel && sel.value === newPhase && dragged.parentElement === col) return;
      var em = col.querySelector('.crm-empty'); if(em) em.remove();
      col.appendChild(dragged); if(sel) sel.value = newPhase; recount();
      var body = new URLSearchParams(); body.set('tun','phase'); body.set('id',id); body.set('phase',newPhase); body.set('ajax','1');
      fetch('?p=pipeline', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString(), credentials:'same-origin' }).catch(function(){});
    });
  });
  function recount(){
    board.querySelectorAll('.crm-col').forEach(function(c){
      var n = c.querySelectorAll('.crm-lead').length;
      var b = c.querySelector('.crm-colhead .n'); if(b) b.textContent = n;
      if(n === 0 && !c.querySelector('.crm-empty')){ var d = document.createElement('div'); d.className='crm-empty'; d.textContent='–'; c.appendChild(d); }
    });
  }
})();
</script>
<?php fuss('pipeline');
