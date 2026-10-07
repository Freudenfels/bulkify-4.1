<?php
// Novel-Food-Katalog aktualisieren: aktuelle Liste hochladen (JSON/CSV) -> Diff (neu/geändert) -> übernehmen.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/novelfood.php';

$fehler = ''; $diff = null; $token = ''; $stand = null; $ergebnis = null;
$tmpPfad = fn(string $t) => BX_UPLOADS . '/nf_import_' . preg_replace('/[^a-f0-9]/', '', $t) . '.dat';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'upload') {
    if (empty($_FILES['nfdatei']['name']) || ($_FILES['nfdatei']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $fehler = 'Bitte eine Datei (JSON oder CSV) hochladen.';
    } else {
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        $token = bin2hex(random_bytes(8));
        $ziel  = $tmpPfad($token);
        if (!move_uploaded_file($_FILES['nfdatei']['tmp_name'], $ziel)) {
            $fehler = 'Die Datei konnte nicht gespeichert werden.';
        } else {
            $parsed = novelfood_aus_datei($ziel);
            if (!$parsed) { @unlink($ziel); $fehler = 'Die Datei konnte nicht gelesen werden. Erwartet: JSON ({"eintraege":[…]}) oder CSV mit Kopfzeile (Spalten u. a. code, name, status …).'; $token = ''; }
            else { $stand = $parsed['stand']; $diff = novelfood_diff((array)$parsed['eintraege']); }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'uebernehmen') {
    $token = preg_replace('/[^a-f0-9]/', '', (string)($_POST['token'] ?? ''));
    $ziel  = $token ? $tmpPfad($token) : '';
    $parsed = $ziel ? novelfood_aus_datei($ziel) : null;
    if (!$parsed) { $fehler = 'Der Upload ist abgelaufen – bitte die Datei erneut hochladen.'; }
    else {
        $ergebnis = novelfood_uebernehmen((array)$parsed['eintraege']);
        @unlink($ziel);
        log_aktivitaet('system', 0, 'team', 'Novel-Food-Katalog aktualisiert: ' . (int)$ergebnis['neu'] . ' neu, ' . (int)$ergebnis['upd'] . ' geändert.', 'notiz');
    }
}

$gesamtDb = (int) scalar("SELECT COUNT(*) FROM novelfood_katalog");
render_header('produkte', 'Novel-Food-Katalog aktualisieren');
bx_head('Novel-Food-Katalog aktualisieren', $gesamtDb . ' Einträge aktuell in der Datenbank',
        bx_btn('Zur Novel-Food-Suche', '?p=novelfood'));

if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';

if ($ergebnis !== null): ?>
  <div class="bx-panel badge-ok" style="padding:14px 16px">
    Katalog aktualisiert: <strong><?= (int)$ergebnis['neu'] ?></strong> neu angelegt, <strong><?= (int)$ergebnis['upd'] ?></strong> aktualisiert.
    Jetzt <strong><?= (int)$gesamtDb ?></strong> Einträge in der Datenbank.
  </div>
<?php endif;

if ($diff === null): ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Aktuelle Liste hochladen</h2>
    <p class="muted" style="margin-top:0">Lade die aktuelle Novel-Food-Liste hoch. Das System vergleicht sie mit der Datenbank und zeigt dir <strong>vor dem Übernehmen</strong>, was neu ist und was sich geändert hat (besonders der Status). Format: <strong>JSON</strong> (<code>{"eintraege":[…]}</code>) oder <strong>CSV</strong> mit Kopfzeile (Spalten u. a. <em>code, name, trivial, syn, status, status_code, teil, beschreibung</em>). Abgeglichen wird je Eintrag über den <strong>code</strong> (sonst den Namen).</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="aktion" value="upload">
      <div class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap">
        <input type="file" name="nfdatei" accept=".json,.csv,.txt,application/json,text/csv" required>
        <button class="btn btn-primary" type="submit">Hochladen &amp; prüfen</button>
      </div>
    </form>
  </div>
<?php else:
  $neu = (array)$diff['neu']; $geae = (array)$diff['geaendert'];
  $statusAend = count(array_filter($geae, fn($g) => !empty($g['status_neu'])));
?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Vorschau<?= $stand ? ' <span class="muted" style="font-weight:400;font-size:13px">(Stand der Datei: ' . h((string)$stand) . ')</span>' : '' ?></h2>
    <div class="bx-cards">
      <div class="bx-card"><div class="k">Neu</div><div class="v" style="color:var(--gruen)"><?= count($neu) ?></div></div>
      <div class="bx-card"><div class="k">Geändert</div><div class="v" style="<?= $geae?'color:var(--warn)':'' ?>"><?= count($geae) ?></div></div>
      <div class="bx-card"><div class="k">davon Status-Änderung</div><div class="v" style="<?= $statusAend?'color:var(--warn)':'' ?>"><?= $statusAend ?></div></div>
      <div class="bx-card"><div class="k">Unverändert</div><div class="v muted"><?= (int)$diff['gleich'] ?></div></div>
      <div class="bx-card"><div class="k">Gesamt in Datei</div><div class="v"><?= (int)$diff['gesamt'] ?></div></div>
    </div>
    <?php if (!$neu && !$geae): ?>
      <p class="muted" style="margin:12px 0 0">Nichts zu tun – der Katalog ist bereits auf dem Stand der Datei.</p>
    <?php else: ?>
    <form method="post" style="margin-top:12px" onsubmit="return confirm('Katalog aktualisieren: <?= count($neu) ?> neu, <?= count($geae) ?> geändert?');">
      <input type="hidden" name="aktion" value="uebernehmen">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <button class="btn btn-primary" type="submit">Jetzt übernehmen (<?= count($neu) + count($geae) ?> Einträge)</button>
      <a class="btn btn-ghost" href="?p=novelfood_import" style="margin-left:8px">Abbrechen</a>
    </form>
    <?php endif; ?>
  </div>

  <?php // Status-Änderungen sind am wichtigsten – zuerst zeigen.
  if ($geae): ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Geänderte Einträge</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Name</th><th>Code</th><th>Geänderte Felder</th><th>Status alt → neu</th></tr></thead>
      <tbody>
      <?php foreach ($geae as $g): $a = $g['alt']; $n = $g['neu']; ?>
        <tr<?= !empty($g['status_neu']) ? ' style="background:var(--panel-2)"' : '' ?>>
          <td><?= h((string)$n['name']) ?></td>
          <td class="muted"><?= h((string)($n['code'] ?? '')) ?></td>
          <td><?= h(implode(', ', (array)$g['felder'])) ?></td>
          <td><?php if (!empty($g['status_neu'])): ?><span class="muted"><?= h((string)($a['status'] ?? '–')) ?></span> → <strong style="color:var(--warn)"><?= h((string)($n['status'] ?? '–')) ?></strong><?php else: ?><span class="muted">–</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <?php if ($neu): ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Neue Einträge</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Name</th><th>Code</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($neu as $n): ?>
        <tr><td><?= h((string)$n['name']) ?></td><td class="muted"><?= h((string)($n['code'] ?? '')) ?></td><td><?= h((string)($n['status'] ?? '–')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
<?php endif;
render_footer();
