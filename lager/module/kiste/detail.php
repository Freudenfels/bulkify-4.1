<?php
// Eine Kiste: Blinker binden, Inhalt (Chargen mit Fach) verwalten, blinken lassen.
$id = (int)($_GET['id'] ?? 0);
$k = kiste($id);
if (!$k) { flash('Diese Kiste gibt es nicht.', 'warn'); weiter('?p=kisten'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');

    if ($aktion === 'speichern') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') flash('Bitte einen Namen angeben.', 'warn');
        else { kiste_speichern($id, $name, trim((string)($_POST['notiz'] ?? '')), trim((string)($_POST['barcode'] ?? ''))); flash('Gespeichert.'); }
        weiter('?p=kiste&id=' . $id);
    }
    if ($aktion === 'blinker_binden') {
        $code = led_leiste_normalisieren(trim((string)($_POST['code'] ?? '')));
        if ($code === null) flash('Kein gültiger Blinker-Code (z. B. CF64B6XD).', 'warn');
        else {
            $fehler = leiste_binden_kiste($code, $id);
            if ($fehler !== '') flash($fehler, 'warn');
            else { leiste_finden((int)leiste_per_code($code)['id'], 'blau', 3, false); flash('Blinker ' . $code . ' an die Kiste gebunden.'); }
        }
        weiter('?p=kiste&id=' . $id);
    }
    if ($aktion === 'blinker_loesen') {
        leiste_loesen((int)($_POST['leiste_id'] ?? 0));
        flash('Blinker von der Kiste gelöst.');
        weiter('?p=kiste&id=' . $id);
    }
    if ($aktion === 'inhalt_zu') {
        $fehler = kiste_charge_zuordnen($id, (int)($_POST['charge_id'] ?? 0), trim((string)($_POST['fach'] ?? '')));
        flash($fehler ?: 'Charge in die Kiste gelegt.', $fehler ? 'warn' : 'ok');
        weiter('?p=kiste&id=' . $id);
    }
    if ($aktion === 'inhalt_weg') {
        kiste_charge_entfernen((int)($_POST['charge_id'] ?? 0));
        flash('Charge aus der Kiste genommen.');
        weiter('?p=kiste&id=' . $id);
    }
    if ($aktion === 'loeschen') {
        kiste_loeschen($id);
        flash('Kiste gelöscht. Die Chargen bleiben, nur die Zuordnung ist weg.');
        weiter('?p=kisten');
    }
}

$blinker = kiste_blinker($id);
$inhalt = kiste_inhalt($id);

kopf('Kiste ' . (string)$k['name'], 'kisten');
seitenkopf('Kiste ' . (string)$k['name'], (string)($k['notiz'] ?? ''), '<a class="btn btn-ghost" href="?p=kisten">Zu den Kisten</a>');
flash_zeigen();
?>
<div class="bx-panel">
  <h2 style="margin-top:0">Kiste</h2>
  <form method="post" class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="aktion" value="speichern">
    <div class="bx-field" style="margin:0;min-width:180px"><label>Name</label><input name="name" value="<?= h((string)$k['name']) ?>"></div>
    <div class="bx-field" style="margin:0;min-width:220px"><label>Barcode <span class="muted">(aufgeklebt, scannen)</span></label>
      <input name="barcode" class="lg-code" value="<?= h((string)($k['barcode'] ?? '')) ?>" placeholder="Barcode scannen oder eingeben" autocomplete="off">
    </div>
    <div class="bx-field" style="margin:0;flex:1;min-width:180px"><label>Notiz</label><input name="notiz" value="<?= h((string)($k['notiz'] ?? '')) ?>"></div>
    <button class="btn btn-primary" type="submit">Speichern</button>
  </form>
</div>

<div class="bx-panel">
  <h2>Blinker</h2>
  <?php if ($blinker): ?>
    <p>An der Kiste hängt Blinker <span class="lg-code"><strong><?= h((string)$blinker['code']) ?></strong></span>.</p>
    <div class="bx-row" style="gap:var(--sp-3)">
      <button type="button" class="btn btn-primary" data-klingeln="<?= (int)$blinker['id'] ?>">Finden</button>
      <button type="button" class="btn btn-ghost" data-klingeln="<?= (int)$blinker['id'] ?>" data-aktion="aus">Aus</button>
      <form method="post" style="display:inline" onsubmit="return confirm('Blinker von der Kiste lösen?')">
        <input type="hidden" name="aktion" value="blinker_loesen"><input type="hidden" name="leiste_id" value="<?= (int)$blinker['id'] ?>">
        <button class="btn btn-ghost lg-x" type="submit" title="Blinker lösen">×</button>
      </form>
    </div>
  <?php else: ?>
    <p class="muted">Noch kein Blinker an der Kiste.</p>
    <form method="post" class="bx-row" style="gap:6px" data-no-busy>
      <input type="hidden" name="aktion" value="blinker_binden">
      <input name="code" class="lg-code" style="max-width:200px" placeholder="Blinker scannen (CF64B6XD)" autocomplete="off">
      <button class="btn btn-primary" type="submit">Binden</button>
    </form>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2>Inhalt <span class="muted" style="font-weight:400">(<?= count($inhalt) ?>)</span></h2>
  <?php if ($inhalt): ?>
  <div class="bx-tablewrap" style="margin-bottom:var(--sp-4)">
    <table class="bx-table">
      <thead><tr><th>Rohstoff / Produkt</th><th>Charge</th><th>Fach</th><th>Bestand</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($inhalt as $r): $c = $r['charge']; ?>
        <tr>
          <td><?= $c ? '<a class="lg-namelink" href="?p=charge&id=' . (int)$c['id'] . '">' . h((string)$c['item_name']) . '</a>' : '<span class="muted">Charge #' . (int)$r['charge_id'] . '</span>' ?></td>
          <td class="lg-code"><?= $c ? h((string)$c['charge_nr']) : '' ?></td>
          <td><?= h((string)$r['fach']) ?: '<span class="muted">–</span>' ?></td>
          <td><?= $c ? h(menge_txt($c['menge_verfuegbar']) . ' ' . $c['einheit']) : '' ?></td>
          <td style="text-align:right">
            <form method="post" style="display:inline" onsubmit="return confirm('Aus der Kiste nehmen?')">
              <input type="hidden" name="aktion" value="inhalt_weg"><input type="hidden" name="charge_id" value="<?= (int)$r['charge_id'] ?>">
              <button class="btn btn-ghost lg-x" type="submit" title="Aus der Kiste nehmen">×</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
    <p class="muted">Noch nichts in der Kiste.</p>
  <?php endif; ?>

  <h3 style="margin:var(--sp-2) 0 var(--sp-2)">Charge hinzufügen</h3>
  <form method="post" class="bx-row" style="gap:8px;flex-wrap:wrap;align-items:flex-end" data-no-busy>
    <input type="hidden" name="aktion" value="inhalt_zu">
    <div class="bx-field" style="margin:0;flex:1;min-width:240px">
      <label>Charge suchen</label>
      <input type="text" name="_suche" class="lg-kiste-suche" placeholder="Rohstoff, Artikelnummer oder Charge" autocomplete="off"
             data-ziel="lg-charge-id" data-anzeige="lg-charge-anzeige">
      <input type="hidden" name="charge_id" id="lg-charge-id">
      <div id="lg-charge-anzeige" class="muted" style="margin-top:4px"></div>
    </div>
    <div class="bx-field" style="margin:0"><label>Fach (optional)</label><input name="fach" placeholder="z. B. vorne links" style="max-width:160px"></div>
    <button class="btn btn-primary" type="submit">In die Kiste</button>
  </form>
</div>

<form method="post" onsubmit="return confirm('Kiste <?= h((string)$k['name']) ?> löschen? Der Inhalt wird nur aus der Kiste gelöst, die Chargen bleiben.')">
  <input type="hidden" name="aktion" value="loeschen">
  <button class="btn btn-danger btn-sm" type="submit">Kiste löschen</button>
</form>
<?php
fuss();
