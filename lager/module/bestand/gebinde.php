<?php
// Eigene Gebinde-/Karton-Aufkleber (Spec 5.6). Zwei Modi in einer Seite:
//   ?p=gebinde&nr=GB-00012   -> Scan-Auflösung: Nummer -> Charge -> Produkt/Wareneingang/Lieferant.
//   ?p=gebinde&charge=<id>   -> Verwaltung: vorhandene Gebinde einer Charge + N neue erzeugen + Etiketten.
// Zweck: Material ohne Hersteller-Charge (Gläser, Deckel) wird je Gebinde rückverfolgbar ("Ersatz-Charge").

// --- Neue Gebinde erzeugen --------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'erzeugen') {
    $cid    = (int)($_POST['charge_id'] ?? 0);
    $anzahl = max(1, min(500, (int)($_POST['anzahl'] ?? 1)));
    $c = erp_charge_voll($cid);
    if (!$c) { flash('Charge nicht gefunden.', 'warn'); weiter('?p=bestand'); }
    $ref = trim(((string)($c['charge_nr'] ?? '')) . ($c['wareneingang'] ? ' · ' . date('d.m.Y', strtotime((string)$c['wareneingang'])) : ''));
    $neu = lg_gebinde_anlegen($cid, $anzahl, (int)($c['lieferant_id'] ?? 0) ?: null, $ref);
    if (function_exists('lg_charge_log_add')) lg_charge_log_add($cid, 'Gebinde-Aufkleber', '', '+' . count($neu) . ' (GB)');
    flash(count($neu) . ' Gebinde-Aufkleber erzeugt.', 'ok');
    weiter('?p=gebinde&charge=' . $cid . '&neu=' . count($neu));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'loeschen') {
    $gid = (int)($_POST['gebinde_id'] ?? 0);
    $cid = (int)($_POST['charge_id'] ?? 0);
    if ($gid > 0) lg_gebinde_del($gid);
    flash('Gebinde-Aufkleber entfernt.', 'ok');
    weiter('?p=gebinde&charge=' . $cid);
}

// --- Modus 1: Scan-Auflösung (?nr=) -----------------------------------------------------------
$nr = strtoupper(trim((string)($_GET['nr'] ?? '')));
if ($nr !== '') {
    $g = lg_gebinde_per_nummer($nr);
    $c = $g ? erp_charge_voll((int)$g['charge_id']) : null;

    kopf('Gebinde ' . $nr, 'bestand');
    seitenkopf('Gebinde ' . $nr, 'Gescannter Gebinde-Aufkleber – Rückschluss auf Produkt und Wareneingang.');
    flash_zeigen();
    if (!$g):
        hinweis('Keine Gebinde-Nummer „' . $nr . '" gefunden. Vielleicht gehört der Aufkleber zu einer gelöschten Charge.', 'warn');
    else:
        $liefTxt = (string)($c['lieferant'] ?? '') . (($c['lieferant_nr'] ?? '') ? ' · ' . (string)$c['lieferant_nr'] : '');
    ?>
    <div class="bx-panel" style="margin-bottom:var(--sp-5)">
      <h2 style="margin-top:0">Zuordnung</h2>
      <table class="bx-table"><tbody>
        <tr><td class="muted" style="width:200px">Gebinde-Nummer</td><td class="lg-code"><?= h($nr) ?> <span class="muted">(Gebinde <?= (int)$g['laufnr'] ?>)</span></td></tr>
        <?php if ($c): ?>
        <tr><td class="muted">Produkt / Material</td><td><a class="lg-namelink" href="?p=charge&id=<?= (int)$g['charge_id'] ?>"><?= h((string)($c['item_name'] ?? '')) ?></a></td></tr>
        <tr><td class="muted">Charge (Lieferant)</td><td class="lg-code"><?= h((string)($c['charge_nr'] ?? '')) ?: '–' ?></td></tr>
        <tr><td class="muted">Lieferant</td><td><?= h($liefTxt) ?: '–' ?></td></tr>
        <tr><td class="muted">Wareneingang</td><td><?= $c['wareneingang'] ? h(date('d.m.Y', strtotime((string)$c['wareneingang']))) : '–' ?></td></tr>
        <tr><td class="muted">Erfasst am</td><td><?= h(fmt_zeit((string)$g['angelegt'])) ?></td></tr>
        <?php else: ?>
        <tr><td class="muted">Charge</td><td class="muted">Die zugehörige Charge gibt es nicht mehr (Ref.: <?= h((string)($g['wareneingang_ref'] ?? '')) ?>).</td></tr>
        <?php endif; ?>
      </tbody></table>
      <?php if ($c): ?>
      <div class="bx-row" style="gap:var(--sp-2);margin-top:var(--sp-3)">
        <a class="btn btn-primary" href="?p=charge&id=<?= (int)$g['charge_id'] ?>">Zur Charge</a>
        <a class="btn btn-ghost" href="?p=gebinde&charge=<?= (int)$g['charge_id'] ?>">Alle Gebinde dieser Charge</a>
      </div>
      <?php endif; ?>
    </div>
    <?php
    endif;
    fuss();
    return;
}

// --- Modus 2: Verwaltung je Charge (?charge=) -------------------------------------------------
$cid = (int)($_GET['charge'] ?? 0);
$c = erp_charge_voll($cid);
if (!$c) { flash('Bitte eine Charge wählen.', 'warn'); weiter('?p=bestand'); }
$geb   = lg_gebinde_liste($cid);
$pakete = function_exists('lg_pakete') ? lg_pakete($cid) : 1;
$vorschlag = max(1, count($geb) > 0 ? 1 : $pakete);

kopf('Gebinde – ' . (string)$c['charge_nr'], 'bestand');
seitenkopf((string)$c['item_name'], 'Eigene Gebinde-Aufkleber (QR + Nummer) – für Verpackung ohne Hersteller-Charge.',
    '<a class="btn btn-ghost" href="?p=charge&id=' . $cid . '">Zur Charge</a>');
flash_zeigen();
?>
<div class="bx-panel" style="margin-bottom:var(--sp-5)">
  <h2 style="margin-top:0">Neue Gebinde-Aufkleber erzeugen</h2>
  <p class="muted" style="margin-top:0">Jedes Gebinde (z. B. jeder Karton) bekommt einen eigenen Aufkleber mit eigenem QR-Code und eigener Nummer. Beim Scan in der Produktion lässt sich darüber der Wareneingang/Lieferant zuordnen (Regress).</p>
  <form method="post" class="bx-row" style="gap:var(--sp-3);align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="erzeugen">
    <input type="hidden" name="charge_id" value="<?= $cid ?>">
    <div class="bx-field" style="margin:0;max-width:160px">
      <label>Anzahl Gebinde</label>
      <input type="number" name="anzahl" min="1" max="500" step="1" value="<?= (int)$vorschlag ?>">
    </div>
    <button type="submit" class="btn btn-primary">Aufkleber erzeugen</button>
    <?php if ($geb): ?><a class="btn btn-ghost" href="?p=gebinde_etikett&charge=<?= $cid ?>" target="_blank">Alle Etiketten öffnen (PDF)</a><?php endif; ?>
  </form>
  <?php if ($pakete > 1): ?><div class="muted" style="font-size:12px;margin-top:var(--sp-2)">Diese Lieferung ist als <?= (int)$pakete ?> Karton(s) erfasst.</div><?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Vorhandene Gebinde (<?= count($geb) ?>)</h2>
  <?php if (!$geb): ?>
    <div class="muted">Für diese Charge gibt es noch keine Gebinde-Aufkleber.</div>
  <?php else: ?>
  <div class="bx-tablewrap">
    <table class="bx-table lg-karten">
      <thead><tr><th>Nummer</th><th>Gebinde</th><th>Erfasst</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($geb as $g): ?>
        <tr>
          <td data-label="Nummer" class="lg-code"><?= h((string)$g['nummer']) ?></td>
          <td data-label="Gebinde"><?= (int)$g['laufnr'] ?> / <?= count($geb) ?></td>
          <td data-label="Erfasst" class="muted"><?= h(fmt_zeit((string)$g['angelegt'])) ?></td>
          <td data-label="" style="text-align:right">
            <a class="btn btn-ghost btn-sm" href="?p=gebinde&nr=<?= rawurlencode((string)$g['nummer']) ?>">Auflösen</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Diesen Gebinde-Aufkleber entfernen?')">
              <input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="gebinde_id" value="<?= (int)$g['id'] ?>"><input type="hidden" name="charge_id" value="<?= $cid ?>">
              <button class="btn btn-ghost btn-sm lg-x" type="submit" title="Entfernen">×</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php
fuss();
