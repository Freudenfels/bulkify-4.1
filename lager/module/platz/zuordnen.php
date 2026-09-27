<?php
// Blinker zuordnen - fuer die Erstmontage der 100 Blinker.
// Ablauf am Regal: Das Programm zeigt den naechsten Platz ohne Blinker. Man klebt einen Blinker dort
// an, scannt ihren Barcode, sie leuchtet zur Bestaetigung kurz gruen - und der naechste Platz kommt.
$aktuell_id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $platz_id = (int)($_POST['platz_id'] ?? 0);
    $p = platz($platz_id);
    $scan = trim((string)($_POST['leiste'] ?? ''));
    $leiste = led_leiste_normalisieren($scan);
    if (!$p) {
        flash('Diesen Platz gibt es nicht mehr.', 'warn');
        weiter('?p=zuordnen');
    }
    if ($leiste === null) {
        flash('"' . $scan . '" ist kein Blinker-Code. Bitte den Barcode auf dem Blinker scannen (z. B. D73CE3XD).', 'warn');
        weiter('?p=zuordnen&id=' . $platz_id);
    }
    $fehler = platz_leiste_setzen($platz_id, $leiste);
    if ($fehler !== '') { flash($fehler, 'warn'); weiter('?p=zuordnen&id=' . $platz_id); }

    $r = led_platz_an($platz_id, 'gruen', 6, true);
    flash(platz_code($p) . ' hat jetzt Blinker ' . $leiste . '. '
        . ($r['ok'] ? 'Er sollte jetzt grün leuchten.' : $r['meldung']), $r['ok'] ? 'ok' : 'warn');
    $n = platz_naechster_ohne_leiste($platz_id);
    weiter('?p=zuordnen' . ($n ? '&id=' . (int)$n['id'] : ''));
}

$p = $aktuell_id ? platz($aktuell_id) : null;
if ($p && $p['leiste']) $p = null;                // schon erledigt -> naechsten nehmen
if (!$p) $p = platz_naechster_ohne_leiste();
$offen = (int)scalar("SELECT COUNT(*) FROM lg_platz WHERE leiste IS NULL");
$gesamt = (int)scalar("SELECT COUNT(*) FROM lg_platz");

kopf('Blinker zuordnen', 'zuordnen');
seitenkopf('Blinker zuordnen', ($gesamt - $offen) . ' von ' . $gesamt . ' Plätzen haben einen Blinker');
flash_zeigen();

if ($gesamt === 0) {
    hinweis('Es gibt noch keine Lagerplätze. Lege sie zuerst unter Lagerplätze an.', 'warn');
} elseif (!$p) {
    hinweis('Alle Plätze haben einen Blinker.');
} else {
    $n = platz_naechster_ohne_leiste((int)$p['id']);
?>
<div class="bx-panel">
  <div class="muted">Blinker anbringen an</div>
  <div class="lg-gross lg-code"><?= h(platz_code($p)) ?></div>
  <div class="muted" style="margin-bottom:var(--sp-5)">
    Bereich <?= h((string)$p['bereich']) ?>, Regal <?= (int)$p['regal'] ?>, Ebene <?= (int)$p['ebene'] ?>, Fach <?= (int)$p['fach'] ?>
    <?= $p['bezeichnung'] ? ' · ' . h((string)$p['bezeichnung']) : '' ?>
  </div>
  <form method="post">
    <input type="hidden" name="platz_id" value="<?= (int)$p['id'] ?>">
    <div class="bx-field">
      <label for="leiste">Barcode des Blinkers scannen</label>
      <input id="leiste" name="leiste" class="lg-scan" autocomplete="off" autofocus required placeholder="D73CE3XD">
    </div>
    <div class="bx-row" style="gap:var(--sp-3)">
      <button class="btn btn-primary" type="submit" data-busy="Speichere …">Zuordnen</button>
      <?php if ($n && (int)$n['id'] !== (int)$p['id']): ?>
        <a class="btn btn-ghost" href="?p=zuordnen&id=<?= (int)$n['id'] ?>">Überspringen (weiter mit <?= h(platz_code($n)) ?>)</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<p class="muted">Die meisten Handscanner schicken nach dem Scan ein Enter - dann wird sofort gespeichert. Noch <?= $offen ?> Plätze ohne Blinker.</p>
<?php
}
fuss();
