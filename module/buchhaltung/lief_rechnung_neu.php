<?php
// Eingangsrechnung (Lieferanten-Rechnung) erfassen. Route: lief_rechnung_neu (Rolle finance).
// Optional vorbefüllt aus einer Bestellung (?bestellung=ID) oder für einen Lieferanten (?lieferant=ID).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/kreditor.php';
kreditor_init();

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'er_save') {
    $lid = (int)($_POST['lieferant_id'] ?? 0);
    $netto = (float) str_replace(',', '.', (string)($_POST['netto'] ?? '0'));
    if (!$lid)           $fehler = 'Bitte einen Lieferanten wählen.';
    elseif ($netto <= 0) $fehler = 'Bitte einen Netto-Betrag größer 0 eingeben.';
    else {
        $u = current_user();
        $id = kr_rechnung_anlegen([
            'lieferant_id'      => $lid,
            'bestellung_id'     => (int)($_POST['bestellung_id'] ?? 0) ?: null,
            'lief_nummer'       => $_POST['lief_nummer'] ?? '',
            'datum'             => $_POST['datum'] ?? date('Y-m-d'),
            'eingang_am'        => $_POST['eingang_am'] ?? date('Y-m-d'),
            'waehrung'          => $_POST['waehrung'] ?? 'EUR',
            'fx_kurs'           => $_POST['fx_kurs'] ?? null,
            'netto'             => $_POST['netto'] ?? '0', // in Rechnungswährung
            'ust_prozent'       => $_POST['ust_prozent'] ?? '0',
            'zahlungsziel_tage' => (int)($_POST['zahlungsziel_tage'] ?? 0),
            'notiz'             => $_POST['notiz'] ?? '',
            'erfasst_von'       => $u['id'] ?? null,
        ]);
        header('Location: ?p=lief_rechnung&id=' . $id . '&erfasst=1'); exit;
    }
}

// Vorbefüllung
$vorBestellung = (int)($_GET['bestellung'] ?? 0);
$vorLieferant  = (int)($_GET['lieferant'] ?? 0);
$preNetto = ''; $preZiel = 0; $preWaehrung = 'EUR'; $preLand = 'DE';
if ($vorBestellung) {
    $bst = one("SELECT lieferant_id FROM bestellung WHERE id=?", [$vorBestellung]);
    if ($bst) { $vorLieferant = (int)$bst['lieferant_id']; $preNetto = number_format(kr_bestellung_netto($vorBestellung), 2, '.', ''); }
}
if ($vorLieferant) {
    $lf = one("SELECT zahlungsziel_lief, waehrung, land FROM lieferanten WHERE id=?", [$vorLieferant]);
    if ($lf) { $preZiel = (int)$lf['zahlungsziel_lief']; $preWaehrung = strtoupper((string)($lf['waehrung'] ?: 'EUR')); $preLand = strtoupper((string)($lf['land'] ?: 'DE')); }
}
// Vorsteuer: Inland EUR -> ust_inland; Ausland/Fremdwährung -> 0 (China-Import hat keine dt. USt auf der Rechnung).
$ustDefault = ($preWaehrung !== 'EUR' || $preLand !== 'DE') ? 0.0 : (float) meta_get('ust_inland', 19);
$preKurs = $preWaehrung === 'EUR' ? '' : number_format(kr_kurs_default($preWaehrung), 6, ',', '');

$waehrungen = kr_waehrungen();
$lieferanten = all("SELECT id, firma, waehrung, land FROM lieferanten ORDER BY firma");
$bestellungen = all("SELECT b.id, b.nummer, l.firma FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id ORDER BY b.id DESC LIMIT 100");
// JS-Karten: Lieferant -> Währung und Währung -> Standardkurs
$mapWaehrung = [];
foreach ($lieferanten as $l) $mapWaehrung[(int)$l['id']] = strtoupper((string)($l['waehrung'] ?: 'EUR'));
$mapKurs = [];
foreach (array_keys($waehrungen) as $cur) $mapKurs[$cur] = $cur === 'EUR' ? 1 : kr_kurs_default($cur);

render_header('buchhaltung', 'Eingangsrechnung erfassen');
bx_head('Eingangsrechnung erfassen', 'Rechnung eines Lieferanten als Verbindlichkeit erfassen',
        bx_btn('Zurück', '?p=buchhaltung&tab=verbindlichkeiten', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="padding:12px 16px;border-color:#e6c4c0;color:var(--err)">' . h($fehler) . '</div>';
?>
<form method="post" class="bx-form bx-panel" style="max-width:720px">
  <input type="hidden" name="aktion" value="er_save">
  <div class="bx-row">
    <label>Lieferant
      <select name="lieferant_id" id="erLieferant" required>
        <option value="">– wählen –</option>
        <?php foreach ($lieferanten as $l): ?>
          <option value="<?= (int)$l['id'] ?>" <?= $vorLieferant === (int)$l['id'] ? 'selected' : '' ?>><?= h($l['firma']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Bestellung (optional)
      <select name="bestellung_id">
        <option value="">– keine –</option>
        <?php foreach ($bestellungen as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $vorBestellung === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['nummer'] . ' · ' . $b['firma']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <div class="bx-row">
    <label>Rechnungsnummer des Lieferanten
      <input type="text" name="lief_nummer" placeholder="z. B. 2026-00123">
    </label>
    <label>Rechnungsdatum
      <input type="date" name="datum" value="<?= date('Y-m-d') ?>">
    </label>
    <label>Eingang bei uns
      <input type="date" name="eingang_am" value="<?= date('Y-m-d') ?>">
    </label>
  </div>
  <div class="bx-row">
    <label>Währung
      <select name="waehrung" id="erWaehrung">
        <?php foreach ($waehrungen as $cur => $sym): ?>
          <option value="<?= h($cur) ?>" <?= $preWaehrung === $cur ? 'selected' : '' ?>><?= h($cur) ?><?= $cur !== 'EUR' ? ' (' . h($sym) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Netto (<span id="erCurLabel"><?= h($preWaehrung) ?></span>)
      <input type="text" inputmode="decimal" name="netto" value="<?= h($preNetto) ?>" placeholder="0,00" required>
    </label>
    <label id="erKursRow" style="<?= $preWaehrung === 'EUR' ? 'display:none' : '' ?>">Kurs (1&nbsp;<span id="erCurLabel2"><?= h($preWaehrung) ?></span> = ? EUR)
      <input type="text" inputmode="decimal" name="fx_kurs" id="erKurs" value="<?= h($preKurs) ?>" placeholder="z. B. 0,92">
    </label>
  </div>
  <div class="bx-row">
    <label>Vorsteuer %
      <input type="text" inputmode="decimal" name="ust_prozent" id="erUst" value="<?= h(number_format($ustDefault, 0)) ?>">
    </label>
    <label>Zahlungsziel (Tage)
      <input type="number" name="zahlungsziel_tage" value="<?= (int)$preZiel ?>" min="0">
    </label>
  </div>
  <label>Notiz
    <textarea name="notiz" rows="2" placeholder="optional"></textarea>
  </label>
  <p class="muted" style="margin:4px 0 0">Bei Fremdwährung (z. B. USD) wird mit dem Kurs in EUR umgerechnet; Buchhaltung, offene Posten und DATEV laufen in EUR. USt/Brutto = Netto × Vorsteuer, Fälligkeit = Rechnungsdatum + Zahlungsziel.</p>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit">Eingangsrechnung speichern</button>
    <a class="btn btn-ghost" href="?p=buchhaltung&tab=verbindlichkeiten">Abbrechen</a>
  </div>
</form>
<script>
(function () {
  var mapW = <?= json_encode($mapWaehrung) ?>, mapK = <?= json_encode($mapKurs) ?>;
  var lief = document.getElementById('erLieferant'), cur = document.getElementById('erWaehrung');
  var kursRow = document.getElementById('erKursRow'), kurs = document.getElementById('erKurs');
  var lab = document.getElementById('erCurLabel'), lab2 = document.getElementById('erCurLabel2'), ust = document.getElementById('erUst');
  function applyCur(setKurs) {
    var c = cur.value || 'EUR';
    lab.textContent = c; lab2.textContent = c;
    if (c === 'EUR') { kursRow.style.display = 'none'; }
    else { kursRow.style.display = ''; if (setKurs && (!kurs.value || kurs.value === '1')) kurs.value = (mapK[c] || '').toString().replace('.', ','); }
  }
  lief.addEventListener('change', function () {
    var w = mapW[this.value]; if (w) { cur.value = w; applyCur(true); if (w !== 'EUR') ust.value = '0'; }
  });
  cur.addEventListener('change', function () { applyCur(true); if (cur.value !== 'EUR') ust.value = '0'; });
  applyCur(false);
})();
</script>
<?php
render_footer();
