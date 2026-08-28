<?php
// Einstellungen – nach Kategorien gegliedert (Reiter). Datenquellen: app_meta (k/v), kapselgroesse, nummernkreis.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_kapselgroesse_if_empty();
seed_behaelter_kapazitaet();   // Standard-Behälter + Kapsel-Fassung (Herstellerwerte, einmalig)
seed_etikett_preise();         // Etiketten-EK je Gebinde (Labelisten, Stand Juni 2026), einmalig

$TABS = [
    'firma'      => 'Firma',
    'steuer'     => 'Steuer & Finanzen',
    'preise'     => 'Preise & Margen',
    'produktion' => 'Produktion & Rezeptur',
    'nummern'    => 'Nummernkreise',
    'fulfillment'=> 'Fulfillment-Schnittstelle',
    'werkzeuge'  => 'Werkzeuge',
];
$DFORM_M = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$tab = $_GET['tab'] ?? 'firma';
if (!isset($TABS[$tab])) $tab = 'firma';

$aktion = $_POST['aktion'] ?? '';

// --- Firma speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'firma_save') {
    foreach (['firma_name','firma_strasse','firma_hausnr','firma_plz','firma_ort','firma_land','firma_ustid','firma_eori','firma_email','firma_tel','firma_gf','firma_web',
              'bank_de_name','bank_de_iban','bank_de_bic','bank_int_name','bank_int_iban','bank_int_bic'] as $k)
        meta_set($k, trim($_POST[$k] ?? ''));
    header('Location: ?p=einstellungen&tab=firma&ok=1'); exit;
}
// --- Demo-Testdaten einspielen (nicht löschend, idempotent) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'demo_seed') {
    $r = demo_testset_einspielen();
    header('Location: ?p=einstellungen&tab=werkzeuge&demo=' . (int)($r['neu'] ?? 0)); exit;
}
// --- Steuer & Finanzen speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'steuer_save') {
    meta_set('ust_inland', (string)(float)str_replace(',', '.', $_POST['ust_inland'] ?? '19'));
    meta_set('zahlungsziel_tage', (string)(int)($_POST['zahlungsziel_tage'] ?? 14));
    meta_set('kleinunternehmer', isset($_POST['kleinunternehmer']) ? '1' : '0');
    header('Location: ?p=einstellungen&tab=steuer&ok=1'); exit;
}
// --- Kapselgrößen speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'kapsel_save') {
    $ids = $_POST['k_id'] ?? []; $names = $_POST['k_name'] ?? []; $mengen = $_POST['k_mg'] ?? [];
    foreach ($ids as $i => $kid) {
        $name = trim($names[$i] ?? ''); $mg = (int)($mengen[$i] ?? 0);
        if ($kid === 'neu') { if ($name !== '') q("INSERT INTO kapselgroesse (name,fuellmenge_mg,sort) VALUES (?,?,?)", [$name, $mg, 99]); }
        elseif ($name === '') q("DELETE FROM kapselgroesse WHERE id=?", [(int)$kid]);
        else q("UPDATE kapselgroesse SET name=?, fuellmenge_mg=? WHERE id=?", [$name, $mg, (int)$kid]);
    }
    header('Location: ?p=einstellungen&tab=produktion&ok=1'); exit;
}
// --- Preise & Margen speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'preise_save') {
    meta_set('marge_min', (string)(float)str_replace(',', '.', $_POST['marge_min'] ?? '30'));
    foreach ($DFORM_M as $key => $lbl) meta_set('marge_typ_' . $key, (string)(float)str_replace(',', '.', $_POST['marge_typ'][$key] ?? ''));
    $clean = fn($s) => implode(',', array_filter(array_map('intval', array_map('trim', explode(',', (string)$s)))));
    meta_set('std_stueck', $clean($_POST['std_stueck'] ?? '') ?: '30,60,90,120,180');
    meta_set('std_bestellmenge', $clean($_POST['std_bestellmenge'] ?? '') ?: '1000,2500,5000,10000');
    meta_set('aufschlag_rohstoff', (string)(float)str_replace(',', '.', $_POST['aufschlag_rohstoff'] ?? '30'));
    meta_set('aufschlag_verpackung', (string)(float)str_replace(',', '.', $_POST['aufschlag_verpackung'] ?? '30'));
    header('Location: ?p=einstellungen&tab=preise&ok=1'); exit;
}
// --- Behälter-Fassung speichern (Matrix: Kapseln je Größe + Pulver-Gramm) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'behaelter_save') {
    $kap = $_POST['kap'] ?? []; $gram = $_POST['gram'] ?? [];
    foreach (($_POST['bh_id'] ?? []) as $iid) {
        $iid = (int)$iid; if (!$iid) continue;
        q("DELETE FROM pack_kapazitaet WHERE item_id=?", [$iid]);
        foreach (($kap[$iid] ?? []) as $kgid => $stk) {
            $stk = (int)$stk;
            if ($stk > 0) q("INSERT INTO pack_kapazitaet (item_id,kapselgroesse_id,stueck) VALUES (?,?,?)", [$iid, (int)$kgid, $stk]);
        }
        $g = trim($gram[$iid] ?? '');
        q("UPDATE item SET max_fuellgewicht_g=? WHERE id=?", [$g === '' ? null : (float)str_replace(',', '.', $g), $iid]);
    }
    header('Location: ?p=einstellungen&tab=produktion&ok=1'); exit;
}
// --- Nummernkreise speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'nummern_save') {
    foreach (($_POST['nk_prefix'] ?? []) as $i => $prefix) {
        $prefix = strtoupper(trim($prefix)); if ($prefix === '') continue;
        $naechste = (int)($_POST['nk_naechste'][$i] ?? 0);
        $stellen  = max(1, (int)($_POST['nk_stellen'][$i] ?? 4));
        q("UPDATE nummernkreis SET naechste=?, stellen=? WHERE prefix=?", [$naechste, $stellen, $prefix]);
    }
    header('Location: ?p=einstellungen&tab=nummern&ok=1'); exit;
}

// --- Fulfillment-Token neu erzeugen ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'fftoken_neu') {
    meta_set('ds_api_token', bin2hex(random_bytes(24)));
    header('Location: ?p=einstellungen&tab=fulfillment&ok=1'); exit;
}
// --- Fulfillment-Basis-URL speichern (für den Artikel-Abruf Richtung B) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'ffurl_save') {
    meta_set('ff_base_url', rtrim(trim($_POST['ff_base_url'] ?? ''), '/'));
    header('Location: ?p=einstellungen&tab=fulfillment&ok=1'); exit;
}

$m = fn($k, $d = '') => h((string) meta_get($k, $d));

render_header('einstellungen', 'Einstellungen');
bx_head('Einstellungen', 'System – nach Kategorien');
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
?>
<div class="settabs">
  <?php foreach ($TABS as $key => $lbl): ?>
    <a href="?p=einstellungen&tab=<?= $key ?>" class="<?= $tab===$key?'on':'' ?>"><?= h($lbl) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'firma'): ?>
<div class="bx-panel">
  <h2>Firmendaten <?= bx_hint('erscheinen später auf Angeboten, Auftragsbestätigungen und Rechnungen') ?></h2>
  <form method="post">
    <input type="hidden" name="aktion" value="firma_save">
    <div class="bx-grid">
      <div class="bx-field"><label>Firmenname</label><input type="text" name="firma_name" value="<?= $m('firma_name') ?>" placeholder="z. B. Maniso GmbH"></div>
      <div class="bx-field"><label>Geschäftsführer</label><input type="text" name="firma_gf" value="<?= $m('firma_gf') ?>"></div>
      <div class="bx-field"><label>Straße</label><input type="text" name="firma_strasse" value="<?= $m('firma_strasse') ?>"></div>
      <div class="bx-field"><label>Hausnummer</label><input type="text" name="firma_hausnr" value="<?= $m('firma_hausnr') ?>"></div>
      <div class="bx-field"><label>PLZ</label><input type="text" name="firma_plz" value="<?= $m('firma_plz') ?>"></div>
      <div class="bx-field"><label>Ort</label><input type="text" name="firma_ort" value="<?= $m('firma_ort') ?>"></div>
      <div class="bx-field"><label>Land</label><input type="text" name="firma_land" value="<?= $m('firma_land', 'DE') ?>"></div>
      <div class="bx-field"><label>USt-IdNr.</label><input type="text" name="firma_ustid" value="<?= $m('firma_ustid') ?>" placeholder="DE…"></div>
      <div class="bx-field"><label>Eori-Nr. <?= bx_hint('für Zoll/Export; erscheint in der Beleg-Fußzeile') ?></label><input type="text" name="firma_eori" value="<?= $m('firma_eori') ?>"></div>
      <div class="bx-field"><label>E-Mail</label><input type="email" name="firma_email" value="<?= $m('firma_email') ?>"></div>
      <div class="bx-field"><label>Telefon</label><input type="text" name="firma_tel" value="<?= $m('firma_tel') ?>"></div>
      <div class="bx-field"><label>Webseite</label><input type="text" name="firma_web" value="<?= $m('firma_web') ?>"></div>
    </div>

    <div style="font-weight:600;margin:16px 0 6px">Bankverbindungen <?= bx_hint('erscheinen auf Angeboten/Rechnungen. Zwei Konten möglich (Deutschland + International).') ?></div>
    <div style="font-weight:600;font-size:13px;color:var(--muted);margin:6px 0 4px">Konto Deutschland</div>
    <div class="bx-grid">
      <div class="bx-field"><label>Bank</label><input type="text" name="bank_de_name" value="<?= $m('bank_de_name') ?>" placeholder="z. B. Sparkasse …"></div>
      <div class="bx-field"><label>IBAN</label><input type="text" name="bank_de_iban" value="<?= $m('bank_de_iban') ?>" placeholder="DE.."></div>
      <div class="bx-field"><label>BIC</label><input type="text" name="bank_de_bic" value="<?= $m('bank_de_bic') ?>"></div>
    </div>
    <div style="font-weight:600;font-size:13px;color:var(--muted);margin:10px 0 4px">Konto International (optional)</div>
    <div class="bx-grid">
      <div class="bx-field"><label>Bank</label><input type="text" name="bank_int_name" value="<?= $m('bank_int_name') ?>" placeholder="z. B. Wise / Revolut …"></div>
      <div class="bx-field"><label>IBAN</label><input type="text" name="bank_int_iban" value="<?= $m('bank_int_iban') ?>"></div>
      <div class="bx-field"><label>BIC</label><input type="text" name="bank_int_bic" value="<?= $m('bank_int_bic') ?>"></div>
    </div>
    <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</div>

<?php elseif ($tab === 'steuer'): ?>
<div class="bx-panel">
  <h2>Steuer & Finanzen <?= bx_hint('Standardwerte für Rechnungen und Zahlungsziele') ?></h2>
  <form method="post">
    <input type="hidden" name="aktion" value="steuer_save">
    <div class="bx-grid">
      <div class="bx-field"><label>USt-Satz Inland (%) <?= bx_hint('Standard 19. Wird bei deutschen Kunden auf Rechnungen angewandt; EU-Ausland automatisch 0 %') ?></label><input type="number" step="0.1" name="ust_inland" value="<?= $m('ust_inland', '19') ?>"></div>
      <div class="bx-field"><label>Zahlungsziel (Tage)</label><input type="number" name="zahlungsziel_tage" value="<?= $m('zahlungsziel_tage', '14') ?>"></div>
    </div>
    <div class="bx-field"><label>Kleinunternehmer (§19 UStG)</label>
      <div class="bx-check" style="padding-top:8px">
        <input type="checkbox" name="kleinunternehmer" id="f_klu" value="1" <?= meta_get('kleinunternehmer','0')==='1'?'checked':'' ?>>
        <label for="f_klu" style="margin:0">keine USt ausweisen</label>
      </div>
    </div>
    <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</div>

<?php elseif ($tab === 'preise'): ?>
<div class="bx-panel">
  <h2>Preise & Margen <?= bx_hint('Basis der automatischen VK-Berechnung: EK (Rezeptur + Kapsel + Behälter) × Marge, Boden = Mindestmarge. Kundenrabatt kommt beim Angebot dazu.') ?></h2>
  <form method="post">
    <input type="hidden" name="aktion" value="preise_save">
    <div class="bx-grid">
      <div class="bx-field"><label>Mindestmarge (%) <?= bx_hint('VK darf nie unter diese Marge fallen') ?></label><input type="number" step="0.1" name="marge_min" value="<?= $m('marge_min','30') ?>"></div>
    </div>
    <div style="font-weight:600;margin:14px 0 6px">VK-Marge je Darreichungsform (%)</div>
    <div class="bx-grid">
      <?php foreach ($DFORM_M as $key => $lbl): ?>
        <div class="bx-field"><label><?= h($lbl) ?></label><input type="number" step="0.1" name="marge_typ[<?= $key ?>]" value="<?= $m('marge_typ_'.$key, $m('marge_min','30')) ?>"></div>
      <?php endforeach; ?>
    </div>
    <div style="font-weight:600;margin:14px 0 6px">Standard-Raster für die Preismatrix</div>
    <div class="bx-grid">
      <div class="bx-field"><label>Stückzahlen je Packung <?= bx_hint('kommagetrennt, z. B. 30,60,90,120,180') ?></label><input type="text" name="std_stueck" value="<?= $m('std_stueck','30,60,90,120,180') ?>"></div>
      <div class="bx-field"><label>Bestellmengen-Staffeln <?= bx_hint('kommagetrennt, z. B. 1000,2500,5000,10000') ?></label><input type="text" name="std_bestellmenge" value="<?= $m('std_bestellmenge','1000,2500,5000,10000') ?>"></div>
    </div>
    <div style="font-weight:600;margin:14px 0 6px">Rohstoff- & Verpackungs-Weiterverkauf</div>
    <div class="bx-grid">
      <div class="bx-field"><label>Aufschlag auf Rohstoffe (%) <?= bx_hint('VK = günstigster Lieferanten-EK (gestaffelt) × (1 + Aufschlag). Je Rohstoff überschreibbar.') ?></label><input type="number" step="0.1" name="aufschlag_rohstoff" value="<?= $m('aufschlag_rohstoff','30') ?>"></div>
      <div class="bx-field"><label>Aufschlag auf Verpackung (%) <?= bx_hint('Dose/Deckel/Etikett kommen extra: VK = EK-Staffel × (1 + Aufschlag). Je Verpackungsartikel überschreibbar.') ?></label><input type="number" step="0.1" name="aufschlag_verpackung" value="<?= $m('aufschlag_verpackung','30') ?>"></div>
    </div>
    <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</div>

<?php elseif ($tab === 'produktion'):
    $kapseln = all("SELECT * FROM kapselgroesse ORDER BY sort, fuellmenge_mg"); ?>
<div class="bx-panel">
  <h2>Kapselgrößen &amp; Füllmengen <?= bx_hint('nominelle Füllmenge je Kapselgröße – Basis für die Kapsel-Auswahl in Rezeptur und Produkt (welche Größe passt, welche Leerkapsel)') ?></h2>
  <form method="post">
    <input type="hidden" name="aktion" value="kapsel_save">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th style="width:50%">Größe</th><th>Füllmenge (mg)</th></tr></thead>
      <tbody>
      <?php foreach ($kapseln as $k): ?>
        <tr>
          <td><input type="hidden" name="k_id[]" value="<?= (int)$k['id'] ?>"><input type="text" name="k_name[]" value="<?= h($k['name']) ?>"></td>
          <td><input type="number" name="k_mg[]" value="<?= (int)$k['fuellmenge_mg'] ?>"></td>
        </tr>
      <?php endforeach; ?>
        <tr>
          <td><input type="hidden" name="k_id[]" value="neu"><input type="text" name="k_name[]" placeholder="neue Größe …"></td>
          <td><input type="number" name="k_mg[]" placeholder="mg"></td>
        </tr>
      </tbody>
    </table></div>
    <div class="muted" style="margin:8px 0">Name leeren = Größe löschen. Werte sind Richtwerte für Pulver mittlerer Dichte.</div>
    <button class="btn btn-primary" type="submit">Speichern</button>
  </form>
</div>

<?php
    $behaelter = all("SELECT id, name, volumen_ml, max_fuellgewicht_g FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0 ORDER BY name");
    $kapsizes  = all("SELECT id, name FROM kapselgroesse ORDER BY fuellmenge_mg DESC");
    $capmap = [];
    foreach (all("SELECT item_id,kapselgroesse_id,stueck FROM pack_kapazitaet") as $c) $capmap[(int)$c['item_id']][(int)$c['kapselgroesse_id']] = (int)$c['stueck'];
    $kurz = fn($n) => str_replace('Größe ', '#', $n);
?>
<div class="bx-panel">
  <h2>Behälter-Fassung <?= bx_hint('wie viele Kapseln je Größe bzw. wie viel Pulver (g) in jeden Behälter passen – Basis für die automatische Verpackungs-Zuordnung im Produkt') ?></h2>
  <form method="post">
    <input type="hidden" name="aktion" value="behaelter_save">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr>
        <th>Behälter</th>
        <?php foreach ($kapsizes as $ks): ?><th class="bx-num"><?= h($kurz($ks['name'])) ?></th><?php endforeach; ?>
        <th class="bx-num">Pulver (g)</th>
      </tr></thead>
      <tbody>
        <?php if (!$behaelter): ?><tr><td colspan="<?= count($kapsizes)+2 ?>" class="muted">Keine Primärverpackungen angelegt.</td></tr><?php endif; ?>
        <?php foreach ($behaelter as $b): ?>
        <tr>
          <td><input type="hidden" name="bh_id[]" value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?><?= $b['volumen_ml']!==null ? ' <span class="muted">('.rtrim(rtrim(number_format((float)$b['volumen_ml'],1,',','.'),'0'),',').' ml)</span>' : '' ?></td>
          <?php foreach ($kapsizes as $ks): $val = $capmap[(int)$b['id']][(int)$ks['id']] ?? ''; ?>
            <td class="bx-num"><input type="number" min="0" step="1" name="kap[<?= (int)$b['id'] ?>][<?= (int)$ks['id'] ?>]" value="<?= $val !== '' ? (int)$val : '' ?>" placeholder="–" style="max-width:64px;text-align:right"></td>
          <?php endforeach; ?>
          <td class="bx-num"><input type="number" min="0" step="0.1" name="gram[<?= (int)$b['id'] ?>]" value="<?= $b['max_fuellgewicht_g']!==null ? (float)$b['max_fuellgewicht_g'] : '' ?>" placeholder="–" style="max-width:74px;text-align:right"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="muted" style="margin:8px 0">Leer = passt nicht / unbekannt. #00 = größte Kapsel. Die Pulver-Spalte ist das max. Füllgewicht je Behälter (für Pulverprodukte).</div>
    <button class="btn btn-primary" type="submit">Speichern</button>
  </form>
</div>

<?php elseif ($tab === 'nummern'):
    $NK_LABEL = ['K'=>'Kunden','L'=>'Lieferanten','PA'=>'Partner','R'=>'Rohstoffe','RZ'=>'Rezepturen','P'=>'Produkte',
                 'AN'=>'Angebote','AB'=>'Aufträge','RE'=>'Rechnungen','PR'=>'Produktionsaufträge','BE'=>'Bestellungen',
                 'LS'=>'Lieferscheine','VP'=>'Verpackung','FP'=>'Fertigprodukte','VF'=>'Verkaufsfertig','RZA'=>'Rezepturanfragen'];
    $nks = all("SELECT * FROM nummernkreis ORDER BY prefix"); ?>
<div class="bx-panel">
  <h2>Nummernkreise <?= bx_hint('laufende Zähler je Präfix. „nächste Nummer" ist die als Nächstes vergebene – vorsichtig ändern, nie unter bereits vergebene Nummern setzen.') ?></h2>
  <form method="post">
    <input type="hidden" name="aktion" value="nummern_save">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Präfix</th><th>Bereich</th><th>nächste Nummer</th><th>Stellen</th><th>Beispiel</th></tr></thead>
      <tbody>
      <?php if (!$nks): ?><tr><td colspan="5" class="muted">Noch keine Nummernkreise – entstehen automatisch, sobald der erste Datensatz je Typ angelegt wird.</td></tr><?php endif; ?>
      <?php foreach ($nks as $nk): $bsp = $nk['prefix'].'-'.str_pad((string)$nk['naechste'], (int)$nk['stellen'], '0', STR_PAD_LEFT); ?>
        <tr>
          <td><strong><?= h($nk['prefix']) ?></strong><input type="hidden" name="nk_prefix[]" value="<?= h($nk['prefix']) ?>"></td>
          <td class="muted"><?= h($NK_LABEL[$nk['prefix']] ?? '–') ?></td>
          <td><input type="number" name="nk_naechste[]" value="<?= (int)$nk['naechste'] ?>" style="max-width:130px"></td>
          <td><input type="number" name="nk_stellen[]" value="<?= (int)$nk['stellen'] ?>" style="max-width:90px"></td>
          <td class="muted"><?= h($bsp) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($nks): ?><div style="margin-top:12px"><button class="btn btn-primary" type="submit">Speichern</button></div><?php endif; ?>
  </form>
</div>

<?php elseif ($tab === 'fulfillment'):
    $ffToken = ds_api_token();
    $ffUrl   = (($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'DEINE-DOMAIN')) . '/ds_api.php';
?>
<div class="bx-panel">
  <h2>Fulfillment-Schnittstelle <?= bx_hint('verbindet das Versandsystem (fulfillment-web) mit dem Fremdlager: Bestands-Feed + Ab-/Rückbuchung') ?></h2>
  <p class="muted" style="margin-top:0">Trage diese beiden Werte im Versandsystem unter <strong>Werkzeuge → bulkify-Dashboard (Lager-Bestand)</strong> ein. Der Token muss auf beiden Seiten identisch sein.</p>
  <div class="bx-grid">
    <div class="bx-field"><label>API-URL (bulkify_dash_url)</label><input type="text" value="<?= h($ffUrl) ?>" readonly onclick="this.select()"></div>
    <div class="bx-field"><label>Token (bulkify_dash_token)</label><input type="text" value="<?= h($ffToken) ?>" readonly onclick="this.select()"></div>
  </div>
  <p class="muted" style="font-size:12px;margin-top:6px">Aktionen: <code>?action=lager2</code> (Bestands-Feed) · <code>verbrauch_sku</code> (Versand bucht ab) · <code>retoure_sku</code> (wiederverkäuflich zurück) · <code>retoure_defekt</code> (Abschreibung). Verknüpfung je Produkt über die Shopify-<code>inventory_item_id</code> (führend) bzw. BSKU – im <a href="?p=lager2">Fremdlager</a> zu pflegen.</p>
  <form method="post" style="margin-top:12px" onsubmit="return confirm('Neuen Token erzeugen? Der alte gilt dann nicht mehr – im Versandsystem neu eintragen.');">
    <input type="hidden" name="aktion" value="fftoken_neu">
    <button class="btn btn-ghost btn-sm" type="submit">Neuen Token erzeugen</button>
  </form>
</div>
<div class="bx-panel">
  <h2>Fulfillment-Artikel abrufen <?= bx_hint('damit das Dashboard die Artikel des Versandsystems direkt zieht und du sie im Fremdlager per Auswahl verknüpfst') ?></h2>
  <p class="muted" style="margin-top:0;font-size:13px">Basis-URL des Versandsystems (ohne Pfad), z. B. <code>https://fulfillment.bulkify.pro</code>. Der Feed <code>/bulkify_feed.php</code> wird automatisch angehängt.</p>
  <form method="post">
    <input type="hidden" name="aktion" value="ffurl_save">
    <div class="bx-field" style="max-width:420px"><label>Fulfillment-Basis-URL</label><input type="text" name="ff_base_url" value="<?= $m('ff_base_url') ?>" placeholder="https://fulfillment.bulkify.pro"></div>
    <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
  <p class="muted" style="font-size:12px;margin-top:8px">Abgerufen und verknüpft wird dann direkt im <a href="?p=lager2">Fremdlager</a> („Fulfillment-Artikel abrufen").</p>
</div>
<?php endif; ?>

<?php if ($tab === 'werkzeuge'): ?>
<div class="bx-panel">
  <h2>Demo-Testdaten einspielen</h2>
  <?php if (isset($_GET['demo'])): $n = (int)$_GET['demo']; ?>
    <div class="bx-panel badge-ok" style="padding:12px 16px"><?= $n > 0 ? ($n . ' neue Demo-Einträge angelegt.') : 'Demodaten sind bereits vorhanden – nichts Neues angelegt.' ?></div>
  <?php endif; ?>
  <p class="muted" style="margin-top:0">Legt einen zusammenhängenden Test-Datensatz an: Beispiel-Kunden, Rezepturen, Produkte, Angebote und Aufträge in den Zuständen <strong>offen</strong>, <strong>in Produktion</strong> und <strong>erledigt</strong> – zum Durchklicken aller Bereiche (Verkauf, Produktion, Rechnungen, Lager).</p>
  <ul class="muted" style="margin-top:0;font-size:13px;line-height:1.7">
    <li>Es wird <strong>nichts gelöscht</strong> – vorhandene Daten bleiben unangetastet.</li>
    <li>Mehrfaches Klicken erzeugt <strong>keine Dubletten</strong> (idempotent).</li>
    <li>Demo-Angebote sind an der Notiz <code>DEMO-TESTSET</code> erkennbar.</li>
  </ul>
  <form method="post" style="margin-top:8px">
    <input type="hidden" name="aktion" value="demo_seed">
    <button class="btn btn-primary" type="submit">Demo-Testdaten einspielen</button>
  </form>
</div>
<?php endif; ?>
<?php render_footer(); ?>
