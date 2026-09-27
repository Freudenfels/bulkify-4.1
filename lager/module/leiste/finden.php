<?php
// Finden im grossen Lager (Chaos-Modell): Rohstoff/Charge suchen, an der gefundenen Charge haengt
// einen Blinker -> "Finden" laesst sie klingeln. Neue Ware: Blinker-Code scannen und binden.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');

    if ($aktion === 'binden') {
        $charge_id = (int)($_POST['charge_id'] ?? 0);
        $scan = trim((string)($_POST['code'] ?? ''));
        $code = led_leiste_normalisieren($scan);
        if ($code === null) {
            flash('"' . $scan . '" ist kein Blinker-Code. Bitte den Barcode des Blinkers scannen (z. B. CF64B6XD).', 'warn');
        } else {
            $fehler = leiste_binden($code, $charge_id);
            if ($fehler !== '') { flash($fehler, 'warn'); }
            else {
                $r = leiste_finden((int)leiste_per_code($code)['id'], 'gruen', 6);
                $c = erp_charge($charge_id);
                flash('Blinker ' . $code . ' hängt jetzt an ' . ($c ? charge_text($c) : 'der Charge') . '. '
                    . ($r['ok'] ? 'Er leuchtet kurz grün.' : $r['meldung']), $r['ok'] ? 'ok' : 'warn');
            }
        }
        weiter('?p=finden&q=' . urlencode((string)($_POST['q'] ?? '')));
    }

    if ($aktion === 'loesen') {
        leiste_loesen((int)($_POST['leiste_id'] ?? 0));
        flash('Blinker gelöst, er ist wieder frei.');
        weiter('?p=finden&q=' . urlencode((string)($_POST['q'] ?? '')));
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$treffer = erp_chargen_suche($q);
$hat_charge = tabelle_da('charge');

kopf('Finden', 'finden');
seitenkopf('Finden im großen Lager', 'Rohstoff oder Charge suchen, die Blinker an der Palette klingelt');
flash_zeigen();

if (!$hat_charge) {
    hinweis('Es sind noch keine Chargen im Dashboard vorhanden.', 'warn');
    fuss(); return;
}
?>
<form method="get" class="bx-listbar" data-no-busy>
  <input type="hidden" name="p" value="finden">
  <input type="search" name="q" class="bx-search" value="<?= h($q) ?>" placeholder="Rohstoff, Artikelnummer oder Chargennummer" autofocus>
  <button class="btn btn-primary" type="submit">Suchen</button>
  <button class="btn btn-ghost" type="button" data-mic title="Per Sprache suchen und blinken lassen">
    <svg class="lg-mic-icon" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"></rect><path d="M5 11a7 7 0 0 0 14 0"></path><line x1="12" y1="18" x2="12" y2="22"></line></svg>
    Sprache
  </button>
</form>

<?php if (!$treffer): ?>
  <div class="bx-panel muted"><?= $q === '' ? 'Tippe oben ein, wonach du suchst.' : 'Nichts gefunden für „' . h($q) . '".' ?></div>
<?php else: ?>
<div class="bx-tablewrap" style="margin-bottom:var(--sp-6)">
  <table class="bx-table">
    <thead><tr><th>Rohstoff</th><th>Charge</th><th>Bestand</th><th>MHD</th><th>Blinker</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($treffer as $c): $l = leiste_fuer_charge((int)$c['id']); ?>
      <tr>
        <td><?= h((string)$c['item_name']) ?><?= $c['artikelnummer'] ? ' <span class="muted">' . h((string)$c['artikelnummer']) . '</span>' : '' ?></td>
        <td class="lg-code"><?= h((string)$c['charge_nr']) ?></td>
        <td><?= h(rtrim(rtrim(number_format((float)$c['menge_verfuegbar'], 3, ',', '.'), '0'), ',')) ?> <?= h((string)$c['einheit']) ?></td>
        <td class="muted"><?= $c['mhd'] ? h(fmt_zeit((string)$c['mhd'] . ' 00:00:00', 'd.m.Y')) : '' ?></td>
        <td class="lg-code"><?= $l ? h((string)$l['code']) : '<span class="muted">keine</span>' ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($l): ?>
            <button type="button" class="btn btn-primary btn-sm" data-klingeln="<?= (int)$l['id'] ?>">Finden</button>
            <button type="button" class="btn btn-ghost btn-sm" data-klingeln="<?= (int)$l['id'] ?>" data-aktion="aus">Aus</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Blinker <?= h((string)$l['code']) ?> lösen? Sie wird wieder frei.')">
              <input type="hidden" name="aktion" value="loesen"><input type="hidden" name="leiste_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
              <button class="btn btn-ghost btn-sm" type="submit">Lösen</button>
            </form>
          <?php else: ?>
            <form method="post" class="bx-row" style="gap:6px;justify-content:flex-end" data-no-busy>
              <input type="hidden" name="aktion" value="binden"><input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
              <input name="code" class="lg-code" style="width:130px" placeholder="Blinker scannen" autocomplete="off">
              <button class="btn btn-primary btn-sm" type="submit">Binden</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php
fuss();
