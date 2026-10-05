<?php
// To-Do-Liste: offene Aufgaben nach Kunde/Kontakt gruppiert, nach Kategorie filterbar, abhakbar.
// Zeigt To-Dos (crm_todo) und offene Wiedervorlagen gemeinsam. Route: ?p=todos
require_once BX_ROOT . '/core/todo.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tun = (string)($_POST['tun'] ?? '');
    if ($tun === 'add') {
        todo_anlegen([
            'titel' => (string)($_POST['titel'] ?? ''), 'kategorie' => (string)($_POST['kategorie'] ?? 'aufgabe'),
            'faellig' => (string)($_POST['faellig'] ?? ''),
        ], crm_uid());
        header('Location: ?p=todos'); exit;
    }
    if ($tun === 'erledigt') { todo_erledigen((string)($_POST['quelle'] ?? 'todo'), (int)($_POST['id'] ?? 0), crm_uid()); header('Location: ?p=todos' . todo_q()); exit; }
    if ($tun === 'offen')    { todo_erledigen((string)($_POST['quelle'] ?? 'todo'), (int)($_POST['id'] ?? 0), crm_uid(), true); header('Location: ?p=todos' . todo_q(true)); exit; }
}

function todo_q(bool $erledigt = false): string {
    $q = [];
    if (($_GET['kat'] ?? '') !== '') $q['kat'] = (string)$_GET['kat'];
    if ($erledigt || ($_GET['modus'] ?? '') === 'erledigt') $q['modus'] = 'erledigt';
    return $q ? '&' . http_build_query($q) : '';
}

$modus = ($_GET['modus'] ?? '') === 'erledigt' ? 'erledigt' : 'offen';
$kat   = (string)($_GET['kat'] ?? '');
$zeilen = todo_liste($modus, $kat);
$heute  = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');

// Nach Bezug gruppieren (Reihenfolge: nach erstem Auftreten).
$gruppen = [];
foreach ($zeilen as $z) {
    $gk = $z['bezug_typ'] !== '' ? ($z['bezug_typ'] . ':' . $z['bezug_id']) : 'ohne';
    if (!isset($gruppen[$gk])) $gruppen[$gk] = ['name' => $z['bezug_name'] ?: 'Ohne Bezug', 'link' => $z['bezug_link'], 'zeilen' => []];
    $gruppen[$gk]['zeilen'][] = $z;
}

kopf('To-Dos', 'todos');
seitenkopf('To-Dos', count($zeilen) . ($modus === 'erledigt' ? ' erledigt' : ' offen'));
?>
<div class="crm-reiter" style="margin-bottom:12px">
  <a href="?p=todos"<?= $modus === 'offen' ? ' class="an"' : '' ?>>Offen</a>
  <a href="?p=todos&modus=erledigt"<?= $modus === 'erledigt' ? ' class="an"' : '' ?>>Erledigt</a>
</div>

<form method="get" class="bx-row" style="gap:8px;flex-wrap:wrap;margin-bottom:14px">
  <input type="hidden" name="p" value="todos">
  <?php if ($modus === 'erledigt'): ?><input type="hidden" name="modus" value="erledigt"><?php endif; ?>
  <select name="kat" onchange="this.form.submit()" style="width:auto;min-width:160px">
    <option value="">Alle Kategorien</option>
    <?php foreach (crm_todo_kategorien() as $kk => $kv): ?><option value="<?= h($kk) ?>"<?= $kk === $kat ? ' selected' : '' ?>><?= h($kv) ?></option><?php endforeach; ?>
    <option value="wiedervorlage"<?= $kat === 'wiedervorlage' ? ' selected' : '' ?>>Wiedervorlage</option>
  </select>
</form>

<?php if ($modus === 'offen'): ?>
<div class="karte"><div class="rumpf">
  <h2 style="margin-top:0">Neue Aufgabe</h2>
  <form method="post">
    <input type="hidden" name="tun" value="add">
    <div class="bx-field"><input type="text" name="titel" required placeholder="z. B. Muster an Müller GmbH schicken"></div>
    <div class="bx-grid">
      <div class="bx-field"><label for="kategorie">Kategorie</label>
        <select id="kategorie" name="kategorie">
          <?php foreach (crm_todo_kategorien() as $kk => $kv): ?><option value="<?= h($kk) ?>"><?= h($kv) ?></option><?php endforeach; ?>
        </select></div>
      <div class="bx-field"><label for="faellig">Fällig (optional)</label>
        <input type="date" id="faellig" name="faellig"></div>
    </div>
    <button class="btn btn-primary" type="submit">Anlegen</button>
    <span class="muted" style="margin-left:10px;font-size:var(--fs-sm)">To-Dos am Kunden/Kontakt legst du direkt dort an.</span>
  </form>
</div></div>
<?php endif; ?>

<?php if (!$zeilen): ?>
  <div class="karte"><div class="crm-leer"><strong><?= $modus === 'erledigt' ? 'Nichts erledigt.' : 'Keine offenen To-Dos.' ?></strong><?= $modus === 'offen' ? 'Oben legst du eine Aufgabe an.' : '' ?></div></div>
<?php else: foreach ($gruppen as $g): ?>
  <div class="karte">
    <div class="rumpf" style="padding-bottom:4px">
      <h2 style="margin:0;font-size:1.05rem">
        <?php if ($g['link'] !== ''): ?><a href="<?= h($g['link']) ?>" style="text-decoration:none"><?= h($g['name']) ?></a><?php else: ?><?= h($g['name']) ?><?php endif; ?>
      </h2>
    </div>
    <?php foreach ($g['zeilen'] as $z):
      $ueber = $modus === 'offen' && $z['faellig'] && $z['faellig'] < $heute; ?>
      <div class="crm-zeile" style="align-items:center<?= $z['erledigt_am'] ? ';opacity:.6' : '' ?>">
        <div class="crm-mitte">
          <span class="titel" style="font-weight:400<?= $z['erledigt_am'] ? ';text-decoration:line-through' : '' ?>"><?= h($z['titel']) ?></span>
          <span class="unter">
            <span class="crm-tag" style="display:inline-block"><?= h(crm_todo_kategorie_label($z['kategorie'])) ?></span>
            <?php if ($z['faellig']): ?> · <span<?= $ueber ? ' style="color:#c0392b;font-weight:600"' : '' ?>>fällig <?= h(fmt_zeit($z['faellig'] . ' 00:00:00', 'd.m.Y')) ?><?= $ueber ? ' · überfällig' : '' ?></span><?php endif; ?>
          </span>
        </div>
        <form method="post" style="margin:0">
          <input type="hidden" name="tun" value="<?= $modus === 'offen' ? 'erledigt' : 'offen' ?>">
          <input type="hidden" name="quelle" value="<?= h($z['quelle']) ?>"><input type="hidden" name="id" value="<?= (int)$z['id'] ?>">
          <button class="btn <?= $modus === 'offen' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit" title="<?= $modus === 'offen' ? 'Erledigt' : 'Wieder offen' ?>"><?= $modus === 'offen' ? 'Erledigt' : '↺' ?></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; endif; ?>
<?php fuss('todos');
