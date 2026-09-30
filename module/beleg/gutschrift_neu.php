<?php
// Storno-Rechnung / Gutschrift manuell erstellen: Kunde + Datum + Positionen (wie beim Angebot).
// Für alte Bestellungen, aus denen etwas herausstorniert werden soll.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'gutschrift_save') {
    $kid   = (int)($_POST['kunde_id'] ?? 0);
    $datum = trim($_POST['datum'] ?? '') !== '' ? $_POST['datum'] : date('Y-m-d');
    $grund = trim($_POST['grund'] ?? '');
    $positionen = [];
    foreach (($_POST['p_bez'] ?? []) as $i => $bez) {
        $bez = trim((string)$bez);
        if ($bez === '') continue;
        $menge = (float) str_replace(',', '.', $_POST['p_menge'][$i] ?? '0');
        $preis = (float) str_replace(',', '.', $_POST['p_preis'][$i] ?? '0');
        $mwst  = (float) str_replace(',', '.', $_POST['p_mwst'][$i] ?? '0');
        if ($menge <= 0) $menge = 1;
        $positionen[] = [
            'artikelnr'   => trim((string)($_POST['p_artikelnr'][$i] ?? '')),
            'bezeichnung' => $bez,
            'beschreibung'=> trim((string)($_POST['p_besch'][$i] ?? '')),
            'menge'       => $menge,
            'einheit'     => trim((string)($_POST['p_einheit'][$i] ?? '')) ?: 'Stk.',
            'preis_cent'  => -abs((int) round($preis * 100)),   // Gutschrift = negativer Betrag
            'mwst_satz'   => $mwst,
        ];
    }
    if (!$kid)            $fehler = 'Bitte einen Kunden wählen.';
    elseif (!$positionen) $fehler = 'Bitte mindestens eine Position mit Bezeichnung angeben.';
    else {
        $bid = gutschrift_erstellen($kid, $datum, $positionen, $grund);
        header('Location: ?p=rechnung&id=' . $bid . '&gespeichert=1'); exit;
    }
}

$kunden  = all("SELECT id, firma, kundennummer FROM kunden ORDER BY firma");
$ustStd  = rtrim(rtrim(number_format((float) meta_get('ust_inland', 19), 2, '.', ''), '0'), '.');
$vorKid  = (int)($_GET['kunde_id'] ?? ($_POST['kunde_id'] ?? 0));

render_header('rechnungen', 'Storno-Rechnung');
bx_head('Storno-Rechnung / Gutschrift', 'Kunde, Datum und Positionen angeben – wie beim Angebot', bx_btn('Zurück zu Rechnungen', '?p=rechnungen', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" value="gutschrift_save">
  <div class="bx-panel">
    <div class="bx-grid">
      <div class="bx-field"><label>Kunde</label>
        <select name="kunde_id" required>
          <option value="">– Kunde wählen –</option>
          <?php foreach ($kunden as $k): ?>
            <option value="<?= (int)$k['id'] ?>" <?= $vorKid===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?><?= $k['kundennummer'] ? ' · '.h($k['kundennummer']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Datum</label><input type="date" name="datum" value="<?= h(date('Y-m-d')) ?>"></div>
      <div class="bx-field"><label>Grund / Bezug <?= bx_hint('z. B. „Storno zu Bestellung/Rechnung X" – erscheint auf dem Beleg') ?></label><input type="text" name="grund" placeholder="z. B. Teilstorno Bestellung März 2026"></div>
    </div>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0">Positionen</h2>
    <p class="muted" style="margin-top:0">Preise als normale (positive) Beträge eintragen – der Beleg weist sie als Gutschrift (negativ) aus.</p>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr>
        <th>Artikel-Nr.</th><th>Bezeichnung / Beschreibung</th>
        <th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Einzelpreis (€)</th><th class="bx-num">USt %</th>
      </tr></thead>
      <tbody id="gsrows">
        <?php for ($i = 0; $i < 3; $i++): ?>
        <tr>
          <td><input type="text" name="p_artikelnr[]" style="max-width:110px"></td>
          <td>
            <input type="text" name="p_bez[]" placeholder="Bezeichnung" style="width:100%">
            <textarea name="p_besch[]" rows="2" placeholder="Beschreibung (optional, mehrzeilig)" style="width:100%;margin-top:4px"></textarea>
          </td>
          <td class="bx-num"><input type="text" inputmode="decimal" name="p_menge[]" value="1" style="max-width:90px;text-align:right"></td>
          <td><input type="text" name="p_einheit[]" value="Stk." style="max-width:80px"></td>
          <td class="bx-num"><input type="text" inputmode="decimal" name="p_preis[]" placeholder="0,00" style="max-width:120px;text-align:right"></td>
          <td class="bx-num"><input type="text" inputmode="decimal" name="p_mwst[]" value="<?= h($ustStd) ?>" style="max-width:70px;text-align:right"></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table></div>
    <div class="bx-row" style="margin-top:var(--sp-4)">
      <button type="button" class="btn btn-ghost btn-sm" id="gsAdd">+ Position</button>
    </div>
  </div>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit">Storno-Rechnung erstellen</button>
    <a class="btn btn-ghost" href="?p=rechnungen">Abbrechen</a>
  </div>
</form>

<script>
document.getElementById('gsAdd').addEventListener('click', function(){
  var tr = document.createElement('tr');
  tr.innerHTML = '<td><input type="text" name="p_artikelnr[]" style="max-width:110px"></td>'
    + '<td><input type="text" name="p_bez[]" placeholder="Bezeichnung" style="width:100%">'
    + '<textarea name="p_besch[]" rows="2" placeholder="Beschreibung (optional, mehrzeilig)" style="width:100%;margin-top:4px"></textarea></td>'
    + '<td class="bx-num"><input type="text" inputmode="decimal" name="p_menge[]" value="1" style="max-width:90px;text-align:right"></td>'
    + '<td><input type="text" name="p_einheit[]" value="Stk." style="max-width:80px"></td>'
    + '<td class="bx-num"><input type="text" inputmode="decimal" name="p_preis[]" placeholder="0,00" style="max-width:120px;text-align:right"></td>'
    + '<td class="bx-num"><input type="text" inputmode="decimal" name="p_mwst[]" value="<?= h($ustStd) ?>" style="max-width:70px;text-align:right"></td>';
  document.getElementById('gsrows').appendChild(tr);
});
</script>
<?php render_footer(); ?>
