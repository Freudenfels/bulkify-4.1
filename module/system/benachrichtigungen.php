<?php
// Benachrichtigungen (Team-Postfach): zeigt alle Team-Ereignisse (Rückfragen, beantwortete Preisanfragen,
// angenommene Angebote, neue Bestellungen, Etikett-Uploads …), die über mail_team()/benachrichtigung_add()
// entstehen. Jeder Benutzer markiert sie selbst als gelesen. Route: ?p=benachrichtigungen (eingeloggt).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$u = function_exists('current_user') ? current_user() : null;
if (!$u) { header('Location: ?p=login'); exit; }
$uid = (int)$u['id'];

// Als gelesen markieren (eine oder alle). Aktuelle Filter (nur/art/q/sort) stehen im Query-String
// des Formular-POSTs – wir haengen sie an den Redirect, damit die Ansicht erhalten bleibt.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = (string)($_POST['aktion'] ?? '');
    if ($akt === 'gelesen')      benachrichtigung_gelesen_setzen($uid, (int)($_POST['id'] ?? 0));
    elseif ($akt === 'alle_gelesen') benachrichtigung_gelesen_setzen($uid, null);
    $keep = '';
    foreach (['nur', 'art', 'q', 'sort'] as $k) { $v = trim((string)($_GET[$k] ?? '')); if ($v !== '') $keep .= '&' . $k . '=' . urlencode($v); }
    header('Location: ?p=benachrichtigungen' . $keep); exit;
}

$nurOffen = ($_GET['nur'] ?? '') === 'offen';
$arten    = function_exists('benachrichtigung_arten') ? benachrichtigung_arten() : [];
$artFilter = (string)($_GET['art'] ?? '');
if ($artFilter !== '' && !isset($arten[$artFilter])) $artFilter = '';
$suche = trim((string)($_GET['q'] ?? ''));
$sort  = ($_GET['sort'] ?? '') === 'datum' ? 'datum' : 'relevanz';
$rows = benachrichtigungen_liste($uid, $nurOffen, 300, $suche, $sort);
// Art-Filter clientnah (Liste <= 300). Nur die Arten zeigen, die auch wirklich vorkommen.
$vorhandeneArten = [];
foreach ($rows as $r) { $a = (string)($r['art'] ?? 'sonstiges'); $vorhandeneArten[$a] = true; }
if ($artFilter !== '') $rows = array_values(array_filter($rows, fn($r) => (string)($r['art'] ?? 'sonstiges') === $artFilter));
$offen = benachrichtigungen_ungelesen_count($uid);
// URL-Baukasten: haelt die aktuellen Filter, einzelne per $ov ueberschreibbar ('' = entfernen).
$bxUrl = function (array $ov = []) use ($nurOffen, $artFilter, $suche, $sort) {
    $p = ['nur' => $nurOffen ? 'offen' : '', 'art' => $artFilter, 'q' => $suche, 'sort' => $sort === 'datum' ? 'datum' : ''];
    foreach ($ov as $k => $v) $p[$k] = $v;
    $qs = '';
    foreach ($p as $k => $v) if ((string)$v !== '') $qs .= '&' . $k . '=' . urlencode((string)$v);
    return '?p=benachrichtigungen' . $qs;
};
$artLink = fn($a) => $bxUrl(['art' => $a]);

render_header('benachrichtigungen', 'Benachrichtigungen');
bx_head('Benachrichtigungen', $offen > 0 ? $offen . ' ungelesen' : 'Alles gelesen',
    $offen > 0 ? '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="alle_gelesen"><button class="btn btn-primary btn-sm" type="submit">Alle als gelesen markieren</button></form>' : '');
?>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="benachrichtigungen">
  <?php if ($artFilter !== ''): ?><input type="hidden" name="art" value="<?= h($artFilter) ?>"><?php endif; ?>
  <input class="bx-search" type="text" name="q" value="<?= h($suche) ?>" placeholder="Suchen: Betreff oder Text …">
  <select name="nur" onchange="this.form.submit()">
    <option value="">Alle</option>
    <option value="offen" <?= $nurOffen ? 'selected' : '' ?>>Nur ungelesen (<?= (int)$offen ?>)</option>
  </select>
  <select name="sort" onchange="this.form.submit()">
    <option value="">Wichtig &amp; ungelesen zuerst</option>
    <option value="datum" <?= $sort === 'datum' ? 'selected' : '' ?>>Neueste zuerst</option>
  </select>
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($suche !== '' || $nurOffen || $artFilter !== '' || $sort === 'datum'): ?><a class="btn btn-ghost btn-sm" href="?p=benachrichtigungen">zurücksetzen</a><?php endif; ?>
</form>
<?php if ($suche !== ''): ?>
<div class="muted" style="margin:8px 0 4px;font-size:13px"><?= count($rows) ?> Treffer für „<?= h($suche) ?>"<?= count($rows) >= 300 ? ' (erste 300)' : '' ?></div>
<?php endif; ?>
<?php if (count($vorhandeneArten) > 1): ?>
<div class="bx-row" style="gap:8px;flex-wrap:wrap;margin:8px 0 12px">
  <a class="btn btn-sm <?= $artFilter === '' ? 'btn-primary' : 'btn-ghost' ?>" href="<?= $artLink('') ?>">Alle Arten</a>
  <?php foreach ($arten as $ak => $al): if (empty($vorhandeneArten[$ak])) continue; ?>
    <a class="btn btn-sm <?= $artFilter === $ak ? 'btn-primary' : 'btn-ghost' ?>" href="<?= $artLink($ak) ?>"><?= h($al) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bx-panel">
<?php if (!$rows): ?>
  <div class="muted"><?= $suche !== '' ? 'Keine Benachrichtigung passt zur Suche.' : ($nurOffen ? 'Keine ungelesenen Benachrichtigungen.' : ($artFilter !== '' ? 'Keine Benachrichtigung dieser Art.' : 'Noch keine Benachrichtigungen.')) ?></div>
<?php else: ?>
  <div class="bx-searchhits" style="display:flex;flex-direction:column;gap:2px">
  <?php foreach ($rows as $r): $gel = (int)($r['gelesen'] ?? 0) === 1; $wichtig = (int)($r['wichtig'] ?? 0) === 1;
        $art = (string)($r['art'] ?? 'sonstiges'); $artLbl = $arten[$art] ?? ucfirst($art); ?>
    <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 8px;border-bottom:1px solid var(--line);<?= $gel ? 'opacity:.6' : '' ?><?= $wichtig ? ';background:rgba(214,69,69,.07);border-left:3px solid var(--danger,#d64545);padding-left:10px' : '' ?>">
      <div style="flex:0 0 8px;margin-top:6px"><?php if (!$gel): ?><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $wichtig ? 'var(--danger,#d64545)' : 'var(--gruen,#1D9E75)' ?>"></span><?php endif; ?></div>
      <div style="flex:1;min-width:0">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span style="font-weight:<?= $gel ? '500' : '600' ?>"><?= h((string)$r['betreff']) ?></span>
          <span class="muted" style="font-size:11px;border:1px solid var(--line);border-radius:999px;padding:1px 8px"><?= h($artLbl) ?></span>
          <?php if ($wichtig): ?><span style="font-size:11px;color:var(--danger,#d64545);font-weight:600">wichtig</span><?php endif; ?>
        </div>
        <?php if (trim((string)($r['text'] ?? '')) !== ''): ?><div class="muted" style="font-size:13px;white-space:pre-line;margin-top:2px"><?= h(mb_substr(trim((string)$r['text']), 0, 400)) ?></div><?php endif; ?>
        <div class="muted" style="font-size:12px;margin-top:4px"><?= h(fmt_zeit($r['erstellt'], 'd.m.Y H:i')) ?> Uhr<?php if (trim((string)($r['link'] ?? '')) !== ''): ?> · <a href="<?= h((string)$r['link']) ?>">öffnen</a><?php endif; ?></div>
      </div>
      <div style="flex:0 0 auto">
        <?php if (!$gel): ?>
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="gelesen"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit">gelesen</button></form>
        <?php else: ?><span class="muted" style="font-size:12px">gelesen</span><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>
<?php render_footer();
