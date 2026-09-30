<?php
// Fast Track – schnelles Nachtragen: Auftrag wählen, Status + Datum setzen (leer = heute), kurze Notiz (auch per Sprache).
// Für den Rückstand: viele Aufträge zügig auf den richtigen Stand bringen. Der Kunde sieht Status + Datum.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$LABELS = ['offen'=>'offen','in_produktion'=>'in Produktion','erledigt'=>'versandbereit','versendet'=>'versendet','storniert'=>'storniert'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'set') {
    $aid    = (int)($_POST['auftrag_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $datum  = trim($_POST['datum'] ?? '');
    $notiz  = trim($_POST['notiz'] ?? '');
    $akteur = (function_exists('current_user') && ($u = current_user())) ? $u['name'] : 'team';
    $q      = trim($_POST['q'] ?? '');
    if ($aid && $status) auftrag_status_setzen($aid, $status, $datum ?: null, $notiz, $akteur);
    header('Location: ?p=fasttrack&auftrag=' . $aid . ($q !== '' ? '&q=' . urlencode($q) : '') . '&ok=1'); exit;
}

$q     = trim($_GET['q'] ?? '');
$aufId = (int)($_GET['auftrag'] ?? 0);

// Trefferliste (nach Nummer / Kunde / Produkt), neueste zuerst.
$where = ''; $par = [];
if ($q !== '') {
    $like = '%' . str_replace('%', '', $q) . '%';
    $where = "WHERE (a.nummer LIKE ? OR k.firma LIKE ? OR p.name LIKE ? OR p.kundenname LIKE ?)";
    $par = [$like, $like, $like, $like];
}
$treffer = all("SELECT a.id, a.nummer, a.status, a.status_datum, k.firma,
                       COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt
                FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id
                $where ORDER BY a.id DESC LIMIT 60", $par);

$auf = $aufId ? one("SELECT a.*, k.firma, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt
                     FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id
                     WHERE a.id=?", [$aufId]) : null;

$badge = fn($s) => match ($s) {
    'offen'=>bx_badge('offen','info'), 'in_produktion'=>bx_badge('in Produktion','warn'),
    'erledigt'=>bx_badge('versandbereit','info'), 'versendet'=>bx_badge('versendet','ok'),
    'storniert'=>bx_badge('storniert','err'), default=>bx_badge((string)$s),
};

render_header('auftraege', 'Fast Track');
bx_head('Fast Track', 'Auftrag wählen · Status + Datum setzen · kurze Notiz (auch per Sprache). Der Kunde sieht Status und Datum.');
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
?>
<form method="get" class="bx-listbar" style="margin-bottom:12px">
  <input type="hidden" name="p" value="fasttrack">
  <input class="bx-search" type="search" name="q" value="<?= h($q) ?>" placeholder="Auftrag suchen: Nummer, Kunde, Produkt …" autofocus>
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=fasttrack">zurücksetzen</a><?php endif; ?>
</form>

<?php if ($auf): ?>
<div class="bx-panel" style="border-color:var(--gruen)">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:6px">
    <h2 style="margin:0"><?= h($auf['nummer']) ?> · <?= h($auf['produkt'] ?: '–') ?></h2>
    <div><?= $badge($auf['status']) ?><?= $auf['status_datum'] ? ' <span class="muted">am ' . h(date('d.m.Y', strtotime($auf['status_datum']))) . '</span>' : '' ?></div>
  </div>
  <div class="muted" style="margin-top:2px"><?= h($auf['firma'] ?: '–') ?> · Menge <?= number_format((int)$auf['menge'],0,',','.') ?></div>

  <form method="post" style="margin-top:14px">
    <input type="hidden" name="aktion" value="set">
    <input type="hidden" name="auftrag_id" value="<?= (int)$auf['id'] ?>">
    <input type="hidden" name="q" value="<?= h($q) ?>">
    <div class="bx-grid">
      <div class="bx-field"><label>Datum <?= bx_hint('leer = heute; erscheint beim Kunden') ?></label><input type="date" name="datum" value="<?= h((string)($auf['status_datum'] ?: date('Y-m-d'))) ?>"></div>
    </div>
    <div class="bx-field"><label>Notiz (optional)</label>
      <div class="bx-row" style="gap:6px;align-items:flex-start">
        <textarea name="notiz" id="ftNotiz" rows="2" style="flex:1" placeholder="z. B. 500 Dosen erhalten, Rest folgt … (Strg+D für Sprache)"></textarea>
        <button type="button" class="btn btn-ghost btn-sm" id="ftMic" title="Sprache (Strg+D)" style="white-space:nowrap">🎤 Sprache</button>
      </div>
      <div class="muted" id="ftMicInfo" style="font-size:12px"></div>
    </div>
    <div style="font-weight:600;margin:10px 0 6px">Status setzen (ein Klick speichert):</div>
    <div class="bx-row" style="gap:8px;flex-wrap:wrap">
      <?php foreach ($LABELS as $key=>$lbl): ?>
        <button class="btn <?= $auf['status']===$key ? 'btn-primary' : 'btn-ghost' ?>" type="submit" name="status" value="<?= $key ?>"><?= h($lbl) ?></button>
      <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:12px;margin-top:8px">„versandbereit"/„versendet" direkt setzen = Produktionsschritte überspringen (zum Aufholen). Danach läuft alles wie gewohnt weiter.</p>
  </form>
</div>
<?php endif; ?>

<div class="bx-panel">
  <h2 style="margin-top:0"><?= $q !== '' ? 'Treffer' : 'Neueste Aufträge' ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Nummer</th><th>Kunde</th><th>Produkt</th><th>Status</th><th>Datum</th></tr></thead>
    <tbody>
      <?php if (!$treffer): ?><tr><td colspan="5" class="muted">Kein Auftrag gefunden.</td></tr><?php endif; ?>
      <?php foreach ($treffer as $t): ?>
        <tr style="cursor:pointer" onclick="location.href='?p=fasttrack&auftrag=<?= (int)$t['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>'">
          <td><?= h($t['nummer']) ?></td>
          <td><?= $t['firma'] ? h($t['firma']) : '<span class="muted">–</span>' ?></td>
          <td><?= $t['produkt'] ? h($t['produkt']) : '<span class="muted">–</span>' ?></td>
          <td><?= $badge($t['status']) ?></td>
          <td class="muted"><?= $t['status_datum'] ? h(date('d.m.Y', strtotime($t['status_datum']))) : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<script>
(function(){
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  var btn = document.getElementById('ftMic'), feld = document.getElementById('ftNotiz'), info = document.getElementById('ftMicInfo');
  if (!btn || !feld) return;
  if (!SR) { btn.disabled = true; btn.title = 'Spracheingabe in diesem Browser nicht verfügbar'; return; }
  var erk, laeuft = false;
  function start(){
    if (laeuft) { try { erk.stop(); } catch(e){} return; }
    erk = new SR(); erk.lang = 'de-DE'; erk.interimResults = false; erk.maxAlternatives = 1;
    erk.onresult = function(ev){ var t = ev.results[0][0].transcript; feld.value = (feld.value ? feld.value.replace(/\s*$/,'') + ' ' : '') + t; };
    erk.onerror = function(ev){ info.textContent = 'Sprachfehler: ' + (ev.error || ''); };
    erk.onstart = function(){ laeuft = true; btn.textContent = '● hört zu …'; info.textContent = 'Sprich jetzt …'; };
    erk.onend   = function(){ laeuft = false; btn.textContent = '🎤 Sprache'; };
    try { erk.start(); } catch(e){}
  }
  btn.addEventListener('click', start);
  document.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 'd' || e.key === 'D')) { e.preventDefault(); start(); }
  });
})();
</script>
<?php render_footer(); ?>
