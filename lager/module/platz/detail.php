<?php
// Ein Lagerplatz: Lage, Bezeichnung, Blinker (per Scan), Sender - dazu Leucht-Test mit Farbe und Dauer.
$id = (int)($_GET['id'] ?? 0);
$p = platz($id);
if (!$p) { flash('Diesen Lagerplatz gibt es nicht.', 'warn'); weiter('?p=plaetze'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');

    if ($aktion === 'loeschen') {
        // Genau diese eine Zeile - nie mehrere.
        q("DELETE FROM lg_platz WHERE id=? LIMIT 1", [$id]);
        flash('Platz ' . platz_code($p) . ' gelöscht.');
        weiter('?p=plaetze');
    }

    if ($aktion === 'speichern') {
        $neu = ['bereich' => platz_bereich((string)($_POST['bereich'] ?? 'A')),
                'regal' => max(1, (int)($_POST['regal'] ?? 1)), 'ebene' => max(1, (int)($_POST['ebene'] ?? 1)),
                'fach' => max(1, (int)($_POST['fach'] ?? 1))];
        $gleich = one("SELECT * FROM lg_platz WHERE bereich=? AND regal=? AND ebene=? AND fach=? AND id<>?",
                      [$neu['bereich'], $neu['regal'], $neu['ebene'], $neu['fach'], $id]);
        $scan = trim((string)($_POST['leiste'] ?? ''));
        $leiste = $scan === '' ? null : led_leiste_normalisieren($scan);
        $sender_id = (int)($_POST['sender_id'] ?? 0) ?: null;

        if ($gleich) {
            flash('Den Platz ' . platz_code($neu) . ' gibt es schon.', 'warn');
        } elseif ($scan !== '' && $leiste === null) {
            flash('"' . $scan . '" ist kein Blinker-Code. Erwartet wird der Barcode des Blinkers, z. B. D73CE3XD.', 'warn');
        } elseif (($f = platz_leiste_setzen($id, $leiste)) !== '') {
            flash($f, 'warn');
        } else {
            q("UPDATE lg_platz SET bereich=?, regal=?, ebene=?, fach=?, bezeichnung=?, sender_id=?, notiz=?, aktualisiert=? WHERE id=?",
              [$neu['bereich'], $neu['regal'], $neu['ebene'], $neu['fach'],
               trim((string)($_POST['bezeichnung'] ?? '')) ?: null, $sender_id,
               trim((string)($_POST['notiz'] ?? '')) ?: null, jetzt_utc(), $id]);
            flash('Gespeichert.');
        }
        weiter('?p=platz&id=' . $id);
    }
}

$sender = led_sender_alle();
$befehle = all("SELECT * FROM lg_befehl WHERE platz_id=? ORDER BY id DESC LIMIT 10", [$id]);

kopf(platz_code($p), 'plaetze');
seitenkopf('Platz ' . platz_code($p), (string)($p['bezeichnung'] ?? ''),
    '<a class="btn btn-ghost" href="?p=plaetze">Zur Übersicht</a>');
flash_zeigen();
?>

<?php if ($p['leiste']): ?>
<div class="bx-panel">
  <h2>Leuchten</h2>
  <form id="lg-test" data-no-busy onsubmit="return false" class="bx-row" style="gap:var(--sp-4);flex-wrap:wrap;align-items:flex-end">
    <div class="bx-field" style="margin:0"><label>Farbe</label>
      <select name="farbe">
        <?php foreach (led_farben() as $k => $f): ?><option value="<?= h($k) ?>"><?= h($f[2]) ?></option><?php endforeach; ?>
      </select></div>
    <div class="bx-field" style="margin:0"><label>Dauer</label>
      <select name="sek">
        <?php foreach (array_keys(led_dauern()) as $s): ?><option value="<?= $s ?>" <?= $s === 20 ? 'selected' : '' ?>><?= $s ?> Sekunden</option><?php endforeach; ?>
      </select></div>
    <label class="bx-check" style="margin:0 0 8px"><input type="checkbox" name="piep" checked> mit Piepton</label>
    <div style="margin-bottom:2px">
      <button type="button" class="btn btn-primary" data-leuchten="<?= $id ?>" data-aus-feldern="lg-test">Leuchten</button>
      <button type="button" class="btn btn-ghost" data-leuchten="<?= $id ?>" data-aktion="aus">Aus</button>
    </div>
  </form>
</div>
<?php endif; ?>

<form method="post" class="bx-panel">
  <input type="hidden" name="aktion" value="speichern">
  <h2>Angaben</h2>
  <div class="bx-grid">
    <div class="bx-field"><label>Bereich</label><input name="bereich" value="<?= h((string)$p['bereich']) ?>" maxlength="10"></div>
    <div class="bx-field"><label>Regal</label><input type="number" name="regal" min="1" value="<?= (int)$p['regal'] ?>"></div>
    <div class="bx-field"><label>Ebene</label><input type="number" name="ebene" min="1" value="<?= (int)$p['ebene'] ?>"></div>
    <div class="bx-field"><label>Fach</label><input type="number" name="fach" min="1" value="<?= (int)$p['fach'] ?>"></div>
  </div>
  <div class="bx-grid">
    <div class="bx-field"><label>Bezeichnung</label><input name="bezeichnung" value="<?= h((string)$p['bezeichnung']) ?>" placeholder="z. B. Kapseln Größe 0"></div>
    <div class="bx-field"><label>Blinker (Barcode scannen)</label>
      <input name="leiste" class="lg-code" value="<?= h((string)$p['leiste']) ?>" placeholder="z. B. D73CE3XD" autocomplete="off"></div>
    <div class="bx-field"><label>Sender</label>
      <select name="sender_id">
        <option value="">Standard</option>
        <?php foreach ($sender as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (int)$p['sender_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= h((string)$s['name']) ?></option>
        <?php endforeach; ?>
      </select></div>
  </div>
  <div class="bx-field"><label>Notiz</label><textarea name="notiz" rows="2"><?= h((string)$p['notiz']) ?></textarea></div>
  <button class="btn btn-primary" type="submit">Speichern</button>
</form>

<?php if ($befehle): ?>
<h2>Letzte Leuchtbefehle</h2>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table">
    <thead><tr><th>Zeit</th><th>Farbe</th><th>Dauer</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($befehle as $b): $f = led_farben()[$b['farbe']] ?? null; ?>
      <tr>
        <td><?= h(fmt_zeit((string)$b['angelegt'], 'd.m.Y H:i:s')) ?></td>
        <td><?= $f ? '<span class="lg-punkt" style="background:' . h($f[3]) . '"></span>' . h($f[2]) : 'aus' ?></td>
        <td><?= (int)$b['sekunden'] ? (int)$b['sekunden'] . ' s' : '' ?></td>
        <td><?= h((string)$b['status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<form method="post" onsubmit="return confirm('Platz <?= h(platz_code($p)) ?> wirklich löschen?')">
  <input type="hidden" name="aktion" value="loeschen">
  <button class="btn btn-danger" type="submit">Platz löschen</button>
</form>
<?php
fuss();
