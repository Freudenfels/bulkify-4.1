<?php
// Lagerplaetze: Uebersicht mit Leuchten-Knopf je Platz und Raster-Anlage fuer viele Plaetze auf einmal.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'raster') {
    $von = max(1, (int)($_POST['regal_von'] ?? 1));
    $bis = max($von, (int)($_POST['regal_bis'] ?? $von));
    $ebenen  = max(1, (int)($_POST['ebenen'] ?? 1));
    $faecher = max(1, (int)($_POST['faecher'] ?? 1));
    $anzahl = ($bis - $von + 1) * $ebenen * $faecher;
    if ($anzahl > 500) {
        flash('Das wären ' . $anzahl . ' Plätze auf einmal. Bitte höchstens 500 je Durchgang.', 'warn');
    } else {
        [$neu, $da] = platz_raster_anlegen((string)($_POST['bereich'] ?? 'A'), $von, $bis, $ebenen, $faecher);
        flash($neu . ' Plätze angelegt' . ($da ? ', ' . $da . ' gab es schon' : '') . '.');
    }
    weiter('?p=plaetze');
}

$plaetze = platz_alle();
$mit = count(array_filter($plaetze, fn($p) => $p['leiste']));
$sender = led_sender_standard();

kopf('Lagerplätze', 'plaetze');
seitenkopf('Lagerplätze',
    count($plaetze) . ' Plätze, davon ' . $mit . ' mit Blinker',
    $plaetze ? '<a class="btn btn-primary" href="?p=zuordnen">Blinker zuordnen</a>' : '');
flash_zeigen();

if (!$sender) {
    hinweis('Es ist noch kein Sender eingerichtet. Ohne Sender leuchtet nichts'
        . (lg_ist_admin() ? ' (System → Sender und Brücke).' : ' - bitte einen Admin fragen.'), 'warn');
}
?>

<?php if ($plaetze): ?>
<div class="bx-listbar">
  <input type="search" class="bx-search" placeholder="Suchen: Platz, Bezeichnung, Blinker" data-filter="lg-plaetze" autofocus>
</div>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table" id="lg-plaetze">
    <thead><tr><th>Platz</th><th>Bezeichnung</th><th>Blinker</th><th>Sender</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($plaetze as $p): ?>
      <tr>
        <td><a class="lg-code" href="?p=platz&id=<?= (int)$p['id'] ?>"><?= h(platz_code($p)) ?></a></td>
        <td><?= h((string)$p['bezeichnung']) ?></td>
        <td class="lg-code"><?= $p['leiste'] ? h((string)$p['leiste']) : '<span class="muted">keine</span>' ?></td>
        <td class="muted"><?= h((string)($p['sender_name'] ?? '')) ?: 'Standard' ?></td>
        <td style="text-align:right"><?= $p['leiste'] ? leucht_knopf((int)$p['id']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<details class="bx-panel" <?= $plaetze ? '' : 'open' ?>>
  <summary style="cursor:pointer"><span style="font-size:var(--fs-md)">Plätze anlegen</span>
    <span class="muted">– ein ganzes Regal auf einmal. Was es schon gibt, bleibt unverändert.</span></summary>
  <form method="post" style="margin-top:var(--sp-4)">
    <input type="hidden" name="aktion" value="raster">
    <div class="bx-grid">
      <div class="bx-field"><label>Bereich</label><input name="bereich" value="A" maxlength="10"></div>
      <div class="bx-field"><label>Regal von</label><input type="number" name="regal_von" value="1" min="1"></div>
      <div class="bx-field"><label>Regal bis</label><input type="number" name="regal_bis" value="1" min="1"></div>
      <div class="bx-field"><label>Ebenen je Regal</label><input type="number" name="ebenen" value="4" min="1"></div>
      <div class="bx-field"><label>Fächer je Ebene</label><input type="number" name="faecher" value="5" min="1"></div>
    </div>
    <p class="muted" style="margin:0 0 var(--sp-4)">Ebene 1 ist die unterste, Fach 1 das linke. Der Platz heißt dann z. B. A-01-2-03 (Bereich A, Regal 1, Ebene 2, Fach 3).</p>
    <button class="btn btn-primary" type="submit" data-busy="Lege an …">Anlegen</button>
  </form>
</details>
<?php
fuss();
