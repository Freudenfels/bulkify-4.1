<?php
// Benachrichtigungen (Team-Postfach): zeigt alle Team-Ereignisse (Rückfragen, beantwortete Preisanfragen,
// angenommene Angebote, neue Bestellungen, Etikett-Uploads …), die über mail_team()/benachrichtigung_add()
// entstehen. Jeder Benutzer markiert sie selbst als gelesen. Route: ?p=benachrichtigungen (eingeloggt).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$u = function_exists('current_user') ? current_user() : null;
if (!$u) { header('Location: ?p=login'); exit; }
$uid = (int)$u['id'];

// Als gelesen markieren (eine oder alle).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = (string)($_POST['aktion'] ?? '');
    if ($akt === 'gelesen')      benachrichtigung_gelesen_setzen($uid, (int)($_POST['id'] ?? 0));
    elseif ($akt === 'alle_gelesen') benachrichtigung_gelesen_setzen($uid, null);
    header('Location: ?p=benachrichtigungen' . (($_GET['nur'] ?? '') === 'offen' ? '&nur=offen' : '')); exit;
}

$nurOffen = ($_GET['nur'] ?? '') === 'offen';
$rows = benachrichtigungen_liste($uid, $nurOffen, 300);
$offen = benachrichtigungen_ungelesen_count($uid);

render_header('benachrichtigungen', 'Benachrichtigungen');
bx_head('Benachrichtigungen', $offen > 0 ? $offen . ' ungelesen' : 'Alles gelesen',
    $offen > 0 ? '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="alle_gelesen"><button class="btn btn-primary btn-sm" type="submit">Alle als gelesen markieren</button></form>' : '');
?>
<div class="bx-listbar">
  <div>
    <a href="?p=benachrichtigungen" <?= !$nurOffen ? 'class="on"' : '' ?> style="margin-right:12px">Alle</a>
    <a href="?p=benachrichtigungen&nur=offen" <?= $nurOffen ? 'class="on"' : '' ?>>Nur ungelesen (<?= (int)$offen ?>)</a>
  </div>
</div>

<div class="bx-panel">
<?php if (!$rows): ?>
  <div class="muted"><?= $nurOffen ? 'Keine ungelesenen Benachrichtigungen.' : 'Noch keine Benachrichtigungen.' ?></div>
<?php else: ?>
  <div class="bx-searchhits" style="display:flex;flex-direction:column;gap:2px">
  <?php foreach ($rows as $r): $gel = (int)($r['gelesen'] ?? 0) === 1; ?>
    <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 8px;border-bottom:1px solid var(--line);<?= $gel ? 'opacity:.6' : '' ?>">
      <div style="flex:0 0 8px;margin-top:6px"><?php if (!$gel): ?><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--gruen,#1D9E75)"></span><?php endif; ?></div>
      <div style="flex:1;min-width:0">
        <div style="font-weight:<?= $gel ? '500' : '600' ?>"><?= h((string)$r['betreff']) ?></div>
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
