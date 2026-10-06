<?php
// Lager-2-Artikel anlegen/bearbeiten (Stammdaten + Etikett-Bild).
$id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$a     = $id ? lg_artikel($id) : null;
$typen = lg_artikel_typen();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'loeschen' && $a) {
        if (!empty($a['etikett_bild'])) @unlink(BX_UPLOADS . '/' . basename((string)$a['etikett_bild']));
        lg_artikel_del($id);
        flash('Artikel gelöscht.');
        weiter('?p=l2_artikel' . ((int)($a['kunde_id'] ?? 0) ? '&kunde=' . (int)$a['kunde_id'] : ''));
    }
    if ($aktion === 'speichern') {
        $d = [
            'kunde_id' => (int)($_POST['kunde_id'] ?? 0),
            'item_id'  => (int)($_POST['item_id'] ?? 0),
            'typ'      => (string)($_POST['typ'] ?? 'produkt'),
            'name'     => (string)($_POST['name'] ?? ''),
            'verkaufsartikel' => ($_POST['verkaufsartikel'] ?? '') === '1',
            'gewicht_g'  => (string)($_POST['gewicht_g'] ?? ''),
            'masse_l_mm' => (string)($_POST['masse_l_mm'] ?? ''),
            'masse_b_mm' => (string)($_POST['masse_b_mm'] ?? ''),
            'masse_h_mm' => (string)($_POST['masse_h_mm'] ?? ''),
            'ean'        => (string)($_POST['ean'] ?? ''),
            'kunden_sku' => (string)($_POST['kunden_sku'] ?? ''),
            'mindestbestand' => (string)($_POST['mindestbestand'] ?? ''),
            'produktionszeit_tage' => (string)($_POST['produktionszeit_tage'] ?? ''),
            'notiz'      => (string)($_POST['notiz'] ?? ''),
        ];
        if (trim($d['name']) === '' || $d['kunde_id'] <= 0) {
            flash('Bitte Kunde und Name angeben.', 'warn');
            weiter('?p=l2_artikel_edit' . ($id ? '&id=' . $id : ''));
        }
        if ($id) { lg_artikel_speichern($id, $d); } else { $id = lg_artikel_anlegen($d); }
        // Etikett-Bild (optional)
        if (!empty($_FILES['bild']['tmp_name']) && is_uploaded_file($_FILES['bild']['tmp_name'])) {
            $ext = strtolower(pathinfo((string)($_FILES['bild']['name'] ?? ''), PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
                $datei = 'lg_artikel_' . $id . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
                if (@move_uploaded_file($_FILES['bild']['tmp_name'], BX_UPLOADS . '/' . $datei)) {
                    if ($a && !empty($a['etikett_bild'])) @unlink(BX_UPLOADS . '/' . basename((string)$a['etikett_bild']));
                    lg_artikel_bild_set($id, $datei);
                } else {
                    flash('Bild konnte nicht gespeichert werden.', 'warn');
                }
            } else {
                flash('Bild muss JPG/PNG/GIF/WEBP sein.', 'warn');
            }
        }
        flash('Artikel gespeichert.');
        weiter('?p=l2_artikel_edit&id=' . $id);
    }
}

$kunden = function_exists('erp_fulfillment_kunden') ? erp_fulfillment_kunden() : [];
$kundeSel = (int)($a['kunde_id'] ?? ($_GET['kunde'] ?? 0));
$produkte = $kundeSel > 0 && function_exists('erp_kunde_verkaufsfertig') ? erp_kunde_verkaufsfertig($kundeSel) : [];
$nz = fn($x) => $x !== null && $x !== '' ? h(rtrim(rtrim(number_format((float)$x, 2, ',', ''), '0'), ',')) : '';

kopf($id ? 'Artikel bearbeiten' : 'Neuer Artikel', 'l2_artikel');
seitenkopf($id ? 'Artikel bearbeiten' : 'Neuer Artikel', $id ? (string)$a['name'] : 'Lager-2-Stammdaten',
    '<a class="btn btn-ghost" href="?p=l2_artikel' . ($kundeSel ? '&kunde=' . $kundeSel : '') . '">Zur Liste</a>');
flash_zeigen();
?>
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="aktion" value="speichern"><input type="hidden" name="id" value="<?= $id ?>">

  <div class="bx-panel" style="margin-bottom:var(--sp-5)">
    <h2 style="margin-top:0">Artikel</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Kunde *</label>
        <select name="kunde_id" required>
          <option value="">– Kunde wählen –</option>
          <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>" <?= $kundeSel === (int)$k['id'] ? 'selected' : '' ?>><?= h((string)$k['firma']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Typ</label>
        <select name="typ">
          <?php foreach ($typen as $tk => $tl): ?><option value="<?= h($tk) ?>" <?= (string)($a['typ'] ?? 'produkt') === $tk ? 'selected' : '' ?>><?= h($tl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" style="flex:2 1 260px"><label>Name / Bezeichnung *</label>
        <input type="text" name="name" value="<?= h((string)($a['name'] ?? '')) ?>" required></div>
      <div class="bx-field"><label>Verkaufsartikel</label>
        <label class="bx-check" style="margin-top:8px"><input type="checkbox" name="verkaufsartikel" value="1" <?= (int)($a['verkaufsartikel'] ?? 0) === 1 ? 'checked' : '' ?>> Ja, ist ein Verkaufsartikel</label></div>
      <div class="bx-field"><label>Bestand-Artikel verknüpfen <span class="muted">(optional)</span></label>
        <select name="item_id">
          <option value="">– keiner –</option>
          <?php foreach ($produkte as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= (int)($a['item_id'] ?? 0) === (int)$pr['id'] ? 'selected' : '' ?>><?= h((string)$pr['name']) ?><?= $pr['bsku'] ? ' (BSKU ' . h((string)$pr['bsku']) . ')' : '' ?></option><?php endforeach; ?>
        </select>
        <div class="muted" style="font-size:12px;margin-top:4px">Nur Verkaufsfertig-Artikel des gewählten Kunden. Bei neuem Artikel erst Kunde speichern, dann verknüpfen.</div>
      </div>
    </div>
  </div>

  <div class="bx-panel" style="margin-bottom:var(--sp-5)">
    <h2 style="margin-top:0">Maße &amp; Codes</h2>
    <div class="bx-grid">
      <div class="bx-field" style="max-width:160px"><label>Gewicht (g)</label><input type="text" name="gewicht_g" inputmode="decimal" value="<?= $nz($a['gewicht_g'] ?? null) ?>"></div>
      <div class="bx-field" style="max-width:120px"><label>Länge (mm)</label><input type="text" name="masse_l_mm" inputmode="decimal" value="<?= $nz($a['masse_l_mm'] ?? null) ?>"></div>
      <div class="bx-field" style="max-width:120px"><label>Breite (mm)</label><input type="text" name="masse_b_mm" inputmode="decimal" value="<?= $nz($a['masse_b_mm'] ?? null) ?>"></div>
      <div class="bx-field" style="max-width:120px"><label>Höhe (mm)</label><input type="text" name="masse_h_mm" inputmode="decimal" value="<?= $nz($a['masse_h_mm'] ?? null) ?>"></div>
      <div class="bx-field"><label>EAN / Barcode</label><input type="text" name="ean" value="<?= h((string)($a['ean'] ?? '')) ?>"></div>
      <div class="bx-field"><label>Kunden-SKU / Art.-Nr.</label><input type="text" name="kunden_sku" value="<?= h((string)($a['kunden_sku'] ?? '')) ?>"></div>
      <div class="bx-field" style="max-width:160px"><label>Mindestbestand</label><input type="text" name="mindestbestand" inputmode="decimal" value="<?= $nz($a['mindestbestand'] ?? null) ?>"></div>
      <div class="bx-field" style="max-width:200px"><label>Produktionszeit (Tage) <span class="muted">falls abweichend</span></label><input type="text" name="produktionszeit_tage" inputmode="numeric" value="<?= ($a['produktionszeit_tage'] ?? null) !== null && (string)$a['produktionszeit_tage'] !== '' ? (int)$a['produktionszeit_tage'] : '' ?>" placeholder="leer = System"></div>
    </div>
  </div>

  <div class="bx-panel" style="margin-bottom:var(--sp-5)">
    <h2 style="margin-top:0">Etikett-Bild &amp; Notiz</h2>
    <div class="bx-row" style="gap:var(--sp-5);flex-wrap:wrap;align-items:flex-start">
      <div class="bx-field" style="margin:0;min-width:240px"><label>Etikett-Bild (bei fertigem Produkt)</label>
        <input type="file" name="bild" accept="image/*">
        <?php if (!empty($a['etikett_bild'])): ?>
          <div style="margin-top:var(--sp-2)"><img src="?p=bild&f=<?= h(rawurlencode((string)$a['etikett_bild'])) ?>" alt="Etikett" style="max-width:220px;max-height:220px;border:1px solid var(--line);border-radius:8px"></div>
        <?php endif; ?>
      </div>
      <div class="bx-field" style="margin:0;flex:1;min-width:260px"><label>Notiz</label>
        <textarea name="notiz" rows="4"><?= h((string)($a['notiz'] ?? '')) ?></textarea></div>
    </div>
  </div>

  <div class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap">
    <button class="btn btn-primary" type="submit">Speichern</button>
    <a class="btn btn-ghost" href="?p=l2_artikel<?= $kundeSel ? '&kunde=' . $kundeSel : '' ?>">Abbrechen</a>
  </div>
</form>
<?php if ($id): ?>
<div class="bx-panel" style="margin-top:var(--sp-6)">
  <h2 style="margin-top:0">Artikel löschen</h2>
  <form method="post" onsubmit="return confirm('Diesen Artikel endgültig löschen?')">
    <input type="hidden" name="aktion" value="loeschen"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn-ghost" type="submit" style="color:#8f231b;border-color:#e6c4c0">Löschen</button>
  </form>
</div>
<?php endif; ?>
<?php fuss();
