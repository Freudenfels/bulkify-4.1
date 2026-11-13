<?php
// Aufgabe – Einzelansicht. Voller Text + Aktionen (Erledigt/Übernehmen), und bei Fastaction-Aufgaben der
// direkte Sprung ins Notepad. Aus der Aufgabenliste per Klick auf den Titel erreichbar.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$u   = function_exists('current_user') ? current_user() : null;
$uid = $u ? (int)$u['id'] : null;
$id  = (int)($_GET['id'] ?? 0);
// Herkunft (13.11.2026): „Zurück“ führt immer zur offenen Liste, nur wer aus dem Archiv kommt, geht ins Archiv zurück.
$vonArchiv = ($_GET['von'] ?? '') === 'archiv';
$zurueck   = $vonArchiv ? '?p=aufgaben&zeige=archiv' : '?p=aufgaben';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $aktion = $_POST['aktion'] ?? '';
    // Erledigt -> direkt zurück zur Liste der offenen Aufgaben mit Meldung. „Wieder öffnen“ gibt es nicht mehr (Aktion „offen“ bucht nichts).
    if ($aktion === 'erledigt' && (string) scalar("SELECT status FROM aufgabe WHERE id=?", [$id]) === 'offen') { aufgabe_erledigen($id, $uid); header('Location: ?p=aufgaben&erledigt=1'); exit; }
    if ($aktion === 'uebernehmen' && $uid) aufgabe_uebernehmen($id, $uid);
    header('Location: ?p=aufgabe&id=' . $id . ($vonArchiv ? '&von=archiv' : '')); exit;
}

$a = $id ? one("SELECT a.*, u.name AS zuw_name, e.name AS ersteller_name, x.name AS erledigt_name
                FROM aufgabe a
                LEFT JOIN benutzer u ON u.id=a.zugewiesen_an
                LEFT JOIN benutzer e ON e.id=a.erstellt_von
                LEFT JOIN benutzer x ON x.id=a.erledigt_von
                WHERE a.id=?", [$id]) : null;

render_header('aufgaben', 'Aufgabe');
if (!$a) { bx_head('Aufgabe nicht gefunden', '', bx_btn('Zurück zu Aufgaben', $zurueck, 'ghost')); render_footer(); exit; }

$erle = (string)$a['status'] === 'erledigt';
$ueberfaellig = !$erle && $a['faellig'] && $a['faellig'] < gmdate('Y-m-d');
bx_head($a['titel'], '', bx_btn('Zurück zu Aufgaben', $zurueck, 'ghost'));
$bezug = aufgabe_bezug((string)($a['ref_typ'] ?? ''), (int)($a['ref_id'] ?? 0));
?>
<?php if ($erle): ?>
<div class="bx-panel" style="border-left:4px solid var(--gruen);padding:12px 16px">erledigt am <?= $a['erledigt_am'] ? h(fmt_zeit($a['erledigt_am'], 'd.m.Y H:i')) : '–' ?> von <?= h($a['erledigt_name'] ?: '–') ?></div>
<?php endif; ?>
<div class="bx-panel">
  <div class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
    <?= prio_badge((int)$a['prio']) ?>
    <?= $erle ? bx_badge('erledigt','ok') : bx_badge('offen','info') ?>
    <?php if ($ueberfaellig): ?><?= bx_badge('überfällig','err') ?><?php endif; ?>
  </div>

  <div class="bx-tablewrap"><table class="bx-table"><tbody>
    <?php if ($bezug): ?><tr><td class="muted" style="width:160px">Bezug</td><td><a href="<?= h($bezug['href']) ?>"><?= h($bezug['text']) ?></a></td></tr><?php endif; ?>
    <tr><td class="muted" style="width:160px">Zugewiesen</td><td><?= $a['zuw_name'] ? h($a['zuw_name']) : bx_badge('Team','info') ?></td></tr>
    <tr><td class="muted">Fällig</td><td><?= $a['faellig'] ? '<span'.($ueberfaellig?' class="bx-err"':'').'>'.h(date('d.m.Y', strtotime($a['faellig']))).'</span>' : '<span class="muted">–</span>' ?></td></tr>
    <tr><td class="muted">Erstellt</td><td><?= h($a['ersteller_name'] ?: 'System') ?><?= $a['angelegt'] ? ' · ' . h(fmt_zeit($a['angelegt'], 'd.m.Y H:i')) : '' ?></td></tr>
  </tbody></table></div>

  <?php if (trim((string)$a['beschreibung']) !== ''): ?>
    <h2 style="font-size:15px;margin:18px 0 6px">Details</h2>
    <div style="white-space:pre-line;line-height:1.5"><?= h((string)$a['beschreibung']) ?></div>
  <?php endif; ?>

  <?php if (!$erle): // erledigte Aufgabe: nur lesen, keine Knöpfe (13.11.2026) ?>
  <div class="bx-row" style="gap:8px;margin-top:18px;flex-wrap:wrap">
    <?php if (($a['ref_typ'] ?? '') === 'fastaction' && (int)($a['ref_id'] ?? 0) > 0): ?>
      <a class="btn btn-primary" href="?p=fastaction&notiz=<?= (int)$a['ref_id'] ?>">Im Notepad ansehen &amp; abarbeiten</a>
    <?php endif; ?>
    <?php if ($a['zugewiesen_an'] === null && $uid): ?>
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="uebernehmen"><button class="btn btn-ghost" type="submit">Übernehmen</button></form>
    <?php endif; ?>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="erledigt"><button class="btn btn-primary" type="submit">Erledigt</button></form>
  </div>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
