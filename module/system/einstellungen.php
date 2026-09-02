<?php
agb_seed_wenn_leer();   // AGB-Entwurf anlegen, solange keine Fassung existiert
// Einstellungen – nach Kategorien gegliedert (Reiter). Datenquellen: app_meta (k/v), kapselgroesse, nummernkreis.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_kapselgroesse_if_empty();
seed_behaelter_kapazitaet();   // Standard-Behälter + Kapsel-Fassung (Herstellerwerte, einmalig)
seed_etikett_preise();         // Etiketten-EK je Gebinde (Labelisten, Stand Juni 2026), einmalig
seed_standbodenbeutel();   // Standbodenbeutel (Labelisten) + EK-Staffeln, einmalig
seed_packari_behaelter();  // Packari-EK fuer PET-Dosen/Weithalsglaeser + Deckel mit Pressure Seal, einmalig

$TABS = [
    'firma'      => 'Firma',
    'steuer'     => 'Steuer & Finanzen',
    'preise'     => 'Preise & Margen',
    'produktion' => 'Produktion & Rezeptur',
    'nummern'    => 'Nummernkreise',
    'fulfillment'=> 'Fulfillment-Schnittstelle',
    'mail'       => 'E-Mail',
    'agb'        => 'AGB',
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
// --- Chargen/MHD-Standard speichern ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'charge_std_save') {
    meta_set('mhd_monate_standard', (string)max(1, (int)($_POST['mhd_monate_standard'] ?? 18)));
    header('Location: ?p=einstellungen&tab=produktion&ok=1'); exit;
}
// --- E-Mail: Zugangsdaten speichern. Das Passwort bleibt stehen, wenn das Feld leer bleibt. ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'mail_save') {
    meta_set('mail_aktiv', isset($_POST['mail_aktiv']) ? '1' : '0');
    foreach (['smtp_host', 'smtp_user', 'smtp_helo', 'mail_from', 'mail_from_name', 'portal_url'] as $f)
        meta_set($f, trim((string)($_POST[$f] ?? '')));
    meta_set('smtp_port', (string) max(1, (int)($_POST['smtp_port'] ?? 587)));
    meta_set('smtp_secure', in_array($_POST['smtp_secure'] ?? '', ['tls', 'ssl', ''], true) ? (string)$_POST['smtp_secure'] : 'tls');
    if (trim((string)($_POST['smtp_pass'] ?? '')) !== '') meta_set('smtp_pass', (string)$_POST['smtp_pass']);
    header('Location: ?p=einstellungen&tab=mail&ok=1'); exit;
}
// --- E-Mail: Testversand ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'mail_test') {
    $fa = beleg_firma();
    $f = mail_senden(trim((string)($_POST['test_an'] ?? '')), 'Testmail aus bulkify',
        "Diese Testmail kommt aus dem bulkify-Dashboard.\n\nWenn sie ankommt, ist der Versand richtig eingerichtet:\n"
        . "Lieferanten-Einladungen und Benachrichtigungen gehen dann denselben Weg.\n\n" . $fa['name']);
    header('Location: ?p=einstellungen&tab=mail&' . ($f === '' ? 'mailok=1' : 'mailfehler=' . urlencode($f))); exit;
}
// --- AGB: neue Fassung speichern (die bisherige bleibt als Beleg erhalten) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'agb_save') {
    $inhalt = (string)($_POST['agb_inhalt'] ?? '');
    if (trim(strip_tags($inhalt)) !== '') agb_speichern((string)($_POST['agb_version'] ?? ''), $inhalt);
    header('Location: ?p=einstellungen&tab=agb&ok=1'); exit;
}
// --- Betriebsmodus: System live schalten (schützt die löschenden Werkzeuge) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'live_save') {
    meta_set('system_live', isset($_POST['system_live']) ? '1' : '0');
    header('Location: ?p=einstellungen&tab=werkzeuge&ok=1'); exit;
}
// --- Daten zurücksetzen (löschend!) – nur mit ausdrücklicher Bestätigung ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'daten_reset') {
    if (($_POST['sicher'] ?? '') !== '1') { header('Location: ?p=einstellungen&tab=werkzeuge&resetfehler=1'); exit; }
    if (!loeschen_erlaubt($_POST['loeschwort'] ?? null)) { header('Location: ?p=einstellungen&tab=werkzeuge&livesperre=1'); exit; }
    $r = daten_zuruecksetzen(($_POST['mit_rezepturen'] ?? '') === '1', ($_POST['mit_kunden'] ?? '') === '1');
    header('Location: ?p=einstellungen&tab=werkzeuge&reset=' . array_sum($r)); exit;
}
// --- Demo-Testdaten gezielt entfernen (löscht nur, was als DEMO-TESTSET markiert ist) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'demo_weg') {
    if (!loeschen_erlaubt($_POST['loeschwort'] ?? null)) { header('Location: ?p=einstellungen&tab=werkzeuge&livesperre=1'); exit; }
    $r = demo_testset_entfernen();
    $_SESSION['demo_weg_behalten'] = $r['behalten'];
    header('Location: ?p=einstellungen&tab=werkzeuge&demoweg=' . ((int)$r['angebote'] + (int)$r['auftraege'] + (int)$r['produkte'] + (int)$r['rezepturen'] + (int)$r['kunden'])); exit;
}
// --- Startset anlegen: Rezepturen + Produkte nach dem Modell (nicht löschend, idempotent) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'startset') {
    $r = seed_startset();
    header('Location: ?p=einstellungen&tab=werkzeuge&start=' . (int)$r['rezepturen'] . '&startp=' . (int)$r['produkte']); exit;
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
    meta_set('std_fuellgewicht_g', $clean($_POST['std_fuellgewicht_g'] ?? '') ?: '150,300,500,1000');
    meta_set('std_fuellvolumen_ml', $clean($_POST['std_fuellvolumen_ml'] ?? '') ?: '50,100,250,500');
    meta_set('std_bestellmenge', $clean($_POST['std_bestellmenge'] ?? '') ?: '1000,2500,5000,10000');
    meta_set('aufschlag_rohstoff', (string)(float)str_replace(',', '.', $_POST['aufschlag_rohstoff'] ?? '30'));
    meta_set('aufschlag_verpackung', (string)(float)str_replace(',', '.', $_POST['aufschlag_verpackung'] ?? '30'));
    meta_set('tablette_hilfsstoff_prozent', (string)(float)str_replace(',', '.', $_POST['tablette_hilfsstoff_prozent'] ?? '20'));
    meta_set('tablette_hilfsstoff_ek_kg', (string)(float)str_replace(',', '.', $_POST['tablette_hilfsstoff_ek_kg'] ?? '8'));
    meta_set('fluessig_portion_ml', (string)(float)str_replace(',', '.', $_POST['fluessig_portion_ml'] ?? '10'));
    meta_set('fluessig_basis_ek_l', (string)(float)str_replace(',', '.', $_POST['fluessig_basis_ek_l'] ?? '3'));
    header('Location: ?p=einstellungen&tab=preise&ok=1'); exit;
}
// --- Behälter-Fassung speichern (Matrix: Kapseln je Größe + Pulver-Gramm + Flüssig-ml) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'behaelter_save') {
    $kap = $_POST['kap'] ?? []; $gram = $_POST['gram'] ?? []; $vol = $_POST['vol'] ?? [];
    foreach (($_POST['bh_id'] ?? []) as $iid) {
        $iid = (int)$iid; if (!$iid) continue;
        q("DELETE FROM pack_kapazitaet WHERE item_id=?", [$iid]);
        foreach (($kap[$iid] ?? []) as $kgid => $stk) {
            $stk = (int)$stk;
            if ($stk > 0) q("INSERT INTO pack_kapazitaet (item_id,kapselgroesse_id,stueck) VALUES (?,?,?)", [$iid, (int)$kgid, $stk]);
        }
        $g = trim($gram[$iid] ?? ''); $v = trim($vol[$iid] ?? '');
        q("UPDATE item SET max_fuellgewicht_g=?, volumen_ml=? WHERE id=?",
          [$g === '' ? null : (float)str_replace(',', '.', $g), $v === '' ? null : (float)str_replace(',', '.', $v), $iid]);
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
      <div class="bx-field"><label>Stückzahlen je Packung <?= bx_hint('für Kapseln/Tabletten/Softgel/Sticks; kommagetrennt, z. B. 30,60,90,120,180') ?></label><input type="text" name="std_stueck" value="<?= $m('std_stueck','30,60,90,120,180') ?>"></div>
      <div class="bx-field"><label>Pulver-Füllgewichte (g) <?= bx_hint('für Pulver/Granulat wird nach Gewicht angeboten (z. B. 300 g), kommagetrennt') ?></label><input type="text" name="std_fuellgewicht_g" value="<?= $m('std_fuellgewicht_g','150,300,500,1000') ?>"></div>
      <div class="bx-field"><label>Flüssig-Füllvolumen (ml) <?= bx_hint('für Flüssiges wird nach Volumen angeboten (z. B. 250 ml), kommagetrennt') ?></label><input type="text" name="std_fuellvolumen_ml" value="<?= $m('std_fuellvolumen_ml','50,100,250,500') ?>"></div>
      <div class="bx-field"><label>Bestellmengen-Staffeln <?= bx_hint('kommagetrennt, z. B. 1000,2500,5000,10000') ?></label><input type="text" name="std_bestellmenge" value="<?= $m('std_bestellmenge','1000,2500,5000,10000') ?>"></div>
    </div>
    <div style="font-weight:600;margin:14px 0 6px">Tablette &amp; Flüssig – Kalkulationsgrundlagen</div>
    <div class="bx-grid">
      <div class="bx-field"><label>Presshilfsstoffe Tablette (%) <?= bx_hint('Füllstoff, Trennmittel und Überzug kommen zum Wirkstoffgewicht der Rezeptur dazu – bestimmt Tablettengewicht und Behälter-Auswahl') ?></label><input type="number" step="0.1" name="tablette_hilfsstoff_prozent" value="<?= $m('tablette_hilfsstoff_prozent','20') ?>"></div>
      <div class="bx-field"><label>EK Presshilfsstoffe (EUR/kg) <?= bx_hint('Einkaufspreis der Presshilfsstoffe – geht in den EK je Tablette ein') ?></label><input type="number" step="0.01" name="tablette_hilfsstoff_ek_kg" value="<?= $m('tablette_hilfsstoff_ek_kg','8') ?>"></div>
      <div class="bx-field"><label>Portionsvolumen Flüssig (ml) <?= bx_hint('wie viel ml eine Portion laut Rezeptur ist – daraus ergibt sich, wie viele Portionen in eine Flasche gehen') ?></label><input type="number" step="0.1" name="fluessig_portion_ml" value="<?= $m('fluessig_portion_ml','10') ?>"></div>
      <div class="bx-field"><label>EK Trägerflüssigkeit (EUR/L) <?= bx_hint('Wasser, Öl oder Glycerin als Basis – kommt je ml Füllvolumen zum EK dazu') ?></label><input type="number" step="0.01" name="fluessig_basis_ek_l" value="<?= $m('fluessig_basis_ek_l','3') ?>"></div>
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
  <h2>Chargennummer &amp; MHD (Standard)</h2>
  <p class="muted" style="margin-top:0">Beim Abschluss einer Produktion wird automatisch eine Chargennummer vergeben – Basis = Produktionsauftrags-Nummer, Teilproduktionen am selben/weiteren Tag als <strong>.A / .B / .C</strong> – und ein MHD gesetzt. Hier legst du die Standard-Haltbarkeit fest.</p>
  <form method="post">
    <input type="hidden" name="aktion" value="charge_std_save">
    <div class="bx-field" style="max-width:300px"><label>MHD-Standard (Monate ab Produktion)</label><input type="number" min="1" name="mhd_monate_standard" value="<?= h($m('mhd_monate_standard','18')) ?>"></div>
    <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</div>

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
  <h2>Behälter-Fassung <?= bx_hint('wie viele Kapseln je Größe, wie viel Pulver (g) und wie viel Flüssiges (ml) in jeden Behälter passen – Basis für die automatische Verpackungs-Zuordnung im Produkt') ?></h2>
  <form method="post">
    <input type="hidden" name="aktion" value="behaelter_save">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr>
        <th>Behälter</th>
        <?php foreach ($kapsizes as $ks): ?><th class="bx-num"><?= h($kurz($ks['name'])) ?></th><?php endforeach; ?>
        <th class="bx-num">Pulver (g)</th>
        <th class="bx-num">Flüssig (ml)</th>
      </tr></thead>
      <tbody>
        <?php if (!$behaelter): ?><tr><td colspan="<?= count($kapsizes)+3 ?>" class="muted">Keine Primärverpackungen angelegt.</td></tr><?php endif; ?>
        <?php foreach ($behaelter as $b): ?>
        <tr>
          <td><input type="hidden" name="bh_id[]" value="<?= (int)$b['id'] ?>"><?= h($b['name']) ?></td>
          <?php foreach ($kapsizes as $ks): $val = $capmap[(int)$b['id']][(int)$ks['id']] ?? ''; ?>
            <td class="bx-num"><input type="number" min="0" step="1" name="kap[<?= (int)$b['id'] ?>][<?= (int)$ks['id'] ?>]" value="<?= $val !== '' ? (int)$val : '' ?>" placeholder="–" style="max-width:64px;text-align:right"></td>
          <?php endforeach; ?>
          <td class="bx-num"><input type="number" min="0" step="0.1" name="gram[<?= (int)$b['id'] ?>]" value="<?= $b['max_fuellgewicht_g']!==null ? (float)$b['max_fuellgewicht_g'] : '' ?>" placeholder="–" style="max-width:74px;text-align:right"></td>
          <td class="bx-num"><input type="number" min="0" step="1" name="vol[<?= (int)$b['id'] ?>]" value="<?= $b['volumen_ml']!==null ? (float)$b['volumen_ml'] : '' ?>" placeholder="–" style="max-width:74px;text-align:right"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="muted" style="margin:8px 0">Leer = passt nicht / unbekannt. #00 = größte Kapsel. Die Pulver-Spalte ist das max. Füllgewicht je Behälter (Pulver, Granulat, Sticks, Tabletten), die Flüssig-Spalte das Fassungsvermögen für Flüssigprodukte.</div>
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

<?php if ($tab === 'werkzeuge'): $live = system_ist_live(); ?>
<div class="bx-panel">
  <h2>Betriebsmodus <?= bx_hint('Solange das System nicht live ist, darf frei aufgeräumt werden. Im Live-Betrieb verlangen alle löschenden Werkzeuge zusätzlich das eingetippte Wort LÖSCHEN.') ?></h2>
  <?php if (isset($_GET['livesperre'])): ?>
    <div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Nicht ausgeführt – das System ist live. Zum Löschen muss zusätzlich <strong>LÖSCHEN</strong> eingetippt werden.</div>
  <?php endif; ?>
  <p class="muted" style="margin-top:0">
    Aktuell: <?= $live ? bx_badge('live – Löschen nur mit Bestätigung','warn') : bx_badge('noch nicht live – Löschen frei möglich','info') ?>
  </p>
  <form method="post">
    <input type="hidden" name="aktion" value="live_save">
    <div class="bx-row" style="gap:8px;align-items:center;margin-bottom:10px">
      <input type="checkbox" name="system_live" id="f_live" value="1" <?= $live ? 'checked' : '' ?>>
      <label for="f_live" style="margin:0">System ist live – Daten sind echt, Löschen nur nach Bestätigung</label>
    </div>
    <button class="btn btn-primary" type="submit">Speichern</button>
  </form>
</div>

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
  <?php if (isset($_GET['demoweg'])): ?>
    <div class="bx-panel badge-ok" style="padding:12px 16px"><?= (int)$_GET['demoweg'] ?> Demo-Datensätze entfernt.
      <?php $beh = $_SESSION['demo_weg_behalten'] ?? []; unset($_SESSION['demo_weg_behalten']); ?>
      <?php if ($beh): ?><div style="margin-top:6px;font-size:13px">Behalten, weil noch in Verwendung: <?= h(implode(' · ', $beh)) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="bx-row" style="gap:10px;margin-top:8px">
    <form method="post" style="margin:0">
      <input type="hidden" name="aktion" value="demo_seed">
      <button class="btn btn-primary" type="submit">Demo-Testdaten einspielen</button>
    </form>
    <form method="post" style="margin:0" onsubmit="return confirm('Alle als DEMO-TESTSET markierten Daten entfernen?');">
      <input type="hidden" name="aktion" value="demo_weg">
      <?php if ($live): ?><input type="text" name="loeschwort" placeholder="LÖSCHEN eintippen" required style="max-width:170px;margin-right:8px"><?php endif; ?>
      <button class="btn btn-ghost" type="submit">Demo-Testdaten entfernen</button>
    </form>
  </div>
  <p class="muted" style="margin-top:10px;font-size:13px">„Entfernen" löscht <strong>nur</strong> die Demo-Kette (Notiz <code>DEMO-TESTSET</code>): Angebote samt Aufträgen, Rechnungen, Produktion und Chargen, dazu die Demo-Produkte, -Rezepturen und -Kunden. Echte Daten bleiben unangetastet – und ein Demo-Produkt, das inzwischen in einem echten Angebot steckt, bleibt ebenfalls stehen.</p>
</div>

<div class="bx-panel">
  <h2>Startset anlegen <?= bx_hint('saubere Rezepturen und Produkte nach dem Modell – zum Weiterarbeiten nach einem Reset') ?></h2>
  <?php if (isset($_GET['start'])): ?>
    <div class="bx-panel badge-ok" style="padding:12px 16px"><?= (int)$_GET['start'] ?> Rezepturen und <?= (int)($_GET['startp'] ?? 0) ?> Produkte angelegt.</div>
  <?php endif; ?>
  <p class="muted" style="margin-top:0">Legt fünf Rezepturen über alle Darreichungsformen an (Kapsel, Tablette, Pulver, Flüssig) und dazu je zwei Produkte in unterschiedlichen Packungsgrößen – nach der Regel <strong>Rezeptur = Rohstoff × Menge + Form</strong> und <strong>Produkt = Rezeptur × Menge + Verpackung</strong>.</p>
  <ul class="muted" style="margin-top:0;font-size:13px;line-height:1.7">
    <li>Behälter und Deckel bestimmt das System selbst – über Kapsel-Fassung, Füllgewicht bzw. Fassungsvermögen.</li>
    <li>Die zweite Packungsgröße entsteht über denselben Weg wie im Angebot (<code>produkt_variante_id</code>).</li>
    <li>Es wird <strong>nichts gelöscht</strong>; eine Rezeptur, die es schon gibt, wird übersprungen.</li>
  </ul>
  <form method="post" style="margin-top:8px">
    <input type="hidden" name="aktion" value="startset">
    <button class="btn btn-primary" type="submit">Startset anlegen</button>
  </form>
</div>

<div class="bx-panel">
  <h2>Daten zurücksetzen <?= bx_hint('räumt die Vorgänge ab, damit man mit einem sauberen Stand weiterarbeiten kann. Stammdaten wie Verpackungen, Rohstoffe und Preise bleiben erhalten.') ?></h2>
  <?php if (isset($_GET['reset'])): ?>
    <div class="bx-panel badge-ok" style="padding:12px 16px"><?= (int)$_GET['reset'] ?> Zeilen gelöscht.</div>
  <?php endif; ?>
  <?php if (isset($_GET['resetfehler'])): ?>
    <div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Nicht ausgeführt – die Sicherheitsabfrage war nicht bestätigt.</div>
  <?php endif; ?>
  <p class="muted" style="margin-top:0">Löscht <strong>Vorgänge</strong>: Angebote, Aufträge, Rechnungen und Belege, Produktionsaufträge, Chargen und Bestand, Bestellungen, Anfragen, Aufgaben und den Verlauf.</p>
  <ul class="muted" style="margin-top:0;font-size:13px;line-height:1.7">
    <li><strong>Nie</strong> gelöscht werden: Rohstoffe und Verpackungen samt EK-Staffeln, Kapsel-Fassung und Etikettenpreisen, Nährstoffe, Kapselgrößen, Benutzer und Nummernkreise.</li>
    <li>Das Demo-Seeding wird danach dauerhaft abgeschaltet, damit sich die Daten nicht beim nächsten Seitenaufruf neu anlegen.</li>
    <li><strong>Lässt sich nicht rückgängig machen.</strong> Vorher eine Sicherung der Datenbank ziehen.</li>
  </ul>
  <form method="post" style="margin-top:8px" onsubmit="return confirm('Wirklich löschen? Das lässt sich nicht rückgängig machen.');">
    <input type="hidden" name="aktion" value="daten_reset">
    <div style="margin-bottom:10px">
      <div class="bx-row" style="gap:8px;align-items:center;margin-bottom:6px">
        <input type="checkbox" name="mit_rezepturen" id="r_rez" value="1">
        <label for="r_rez" style="margin:0">zusätzlich Rezepturen, Produkte und deren Preismatrix löschen</label>
      </div>
      <div class="bx-row" style="gap:8px;align-items:center">
        <input type="checkbox" name="mit_kunden" id="r_kd" value="1">
        <label for="r_kd" style="margin:0">zusätzlich Kunden, Marken und Partner-Subkunden löschen</label>
      </div>
    </div>
    <div class="bx-row" style="gap:8px;align-items:center;margin-bottom:10px">
      <input type="checkbox" name="sicher" id="r_ok" value="1" required>
      <label for="r_ok" style="margin:0">Ja, ich habe eine Sicherung und will die Daten löschen</label>
    </div>
    <?php if ($live): ?>
    <div class="bx-field" style="max-width:260px">
      <label>Zur Bestätigung <strong>LÖSCHEN</strong> eintippen <?= bx_hint('Das System ist live – deshalb reicht das Häkchen allein nicht.') ?></label>
      <input type="text" name="loeschwort" required autocomplete="off">
    </div>
    <?php endif; ?>
    <button class="btn btn-danger" type="submit">Daten zurücksetzen</button>
  </form>
</div>
<?php endif; ?>
<?php if ($tab === 'agb'): $agbAkt = agb_aktuell(); $agbAlle = all("SELECT id,version,aktiv,angelegt FROM agb ORDER BY id DESC"); ?>
<div class="bx-panel">
  <h2>AGB</h2>
  <p class="muted" style="margin-top:0">Die Fassung, die hier aktiv ist, sieht der Kunde im Portal und muss sie beim verbindlichen Annehmen von Rezeptur oder Angebot bestätigen. Die dabei geltende Versionsbezeichnung wird am Vorgang gespeichert. Speichern legt eine <strong>neue Fassung</strong> an – die alte bleibt als Beleg erhalten.</p>
  <p class="muted" style="font-size:13px">Der mitgelieferte Text ist ein <strong>Entwurf</strong> und muss anwaltlich geprüft werden. HTML ist erlaubt (z. B. &lt;h3&gt; und &lt;p&gt;).</p>
  <form method="post">
    <input type="hidden" name="aktion" value="agb_save">
    <div class="bx-field" style="max-width:280px"><label>Versionsbezeichnung</label>
      <input type="text" name="agb_version" maxlength="40" placeholder="<?= h(date('Y-m-d')) ?>" value=""></div>
    <div class="bx-field"><label>Inhalt</label>
      <textarea name="agb_inhalt" rows="22" style="width:100%;font-family:ui-monospace,Consolas,monospace;font-size:13px"><?= h($agbAkt['inhalt'] ?? agb_entwurf_text()) ?></textarea></div>
    <button class="btn btn-primary" type="submit">Als neue Fassung speichern</button>
  </form>
</div>
<?php if (count($agbAlle) > 1): ?>
<div class="bx-panel">
  <h2>Fassungen</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Fassung</th><th>Angelegt</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($agbAlle as $a): ?>
      <tr><td><?= h($a['version']) ?></td><td><?= h(fmt_zeit($a['angelegt'])) ?> Uhr</td>
          <td><?= (int)$a['aktiv'] === 1 ? bx_badge('aktiv','ok') : '<span class="muted">frühere Fassung</span>' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>
<?php endif; ?>
<?php if ($tab === 'mail'): $mc = mail_config(); ?>
<div class="bx-panel">
  <h2>E-Mail-Versand</h2>
  <p class="muted" style="margin-top:0">Zugangsdaten des Postfachs eintragen, aus dem bulkify verschickt (z. B. United Domains). Verschickt wird über SMTP – ohne Zusatzsoftware. Jede Mail wird zusätzlich in <code>data/mail.log</code> mitgeschrieben, damit nachvollziehbar bleibt, was rausging.</p>
  <?php if (isset($_GET['mailok'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Testmail verschickt – bitte im Posteingang nachsehen.</div><?php endif; ?>
  <?php if (isset($_GET['mailfehler'])): ?><div style="border:1px solid #e6c4c0;color:#8f231b;padding:8px 12px;margin-bottom:10px;border-radius:8px">Testmail nicht verschickt: <?= h((string)$_GET['mailfehler']) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="aktion" value="mail_save">
    <div class="bx-field">
      <div class="bx-check"><input type="checkbox" name="mail_aktiv" id="mail_aktiv" value="1" <?= $mc['aktiv'] ? 'checked' : '' ?>>
        <label for="mail_aktiv" style="margin:0">E-Mail-Versand eingeschaltet</label></div>
      <div class="muted" style="font-size:12px">Ausgeschaltet wird nichts verschickt – Vorgänge laufen trotzdem normal weiter.</div>
    </div>
    <div class="bx-grid">
      <div class="bx-field"><label>SMTP-Server <?= bx_hint('Bei United Domains meist smtp.udag.de') ?></label>
        <input type="text" name="smtp_host" value="<?= h($mc['host']) ?>" placeholder="smtp.udag.de"></div>
      <div class="bx-field"><label>Port <?= bx_hint('587 mit STARTTLS oder 465 mit SSL') ?></label>
        <input type="number" name="smtp_port" value="<?= (int)$mc['port'] ?>"></div>
      <div class="bx-field"><label>Verschlüsselung</label>
        <select name="smtp_secure">
          <option value="tls"<?= $mc['secure'] === 'tls' ? ' selected' : '' ?>>STARTTLS (Port 587)</option>
          <option value="ssl"<?= $mc['secure'] === 'ssl' ? ' selected' : '' ?>>SSL/TLS (Port 465)</option>
          <option value=""<?= $mc['secure'] === '' ? ' selected' : '' ?>>ohne (nicht empfohlen)</option>
        </select></div>
      <div class="bx-field"><label>Benutzername</label>
        <input type="text" name="smtp_user" value="<?= h($mc['user']) ?>" autocomplete="off"></div>
      <div class="bx-field"><label>Passwort <?= bx_hint('Wird gespeichert. Leer lassen, um das vorhandene Passwort zu behalten.') ?></label>
        <input type="password" name="smtp_pass" value="" autocomplete="new-password" placeholder="<?= $mc['pass'] !== '' ? '•••••••• (gespeichert)' : '' ?>"></div>
      <div class="bx-field"><label>HELO-Name <?= bx_hint('Optional. Leer = Domain der Absenderadresse.') ?></label>
        <input type="text" name="smtp_helo" value="<?= h($mc['helo']) ?>"></div>
      <div class="bx-field"><label>Absenderadresse</label>
        <input type="email" name="mail_from" value="<?= h($mc['from']) ?>" placeholder="info@bulkify.pro"></div>
      <div class="bx-field"><label>Absendername</label>
        <input type="text" name="mail_from_name" value="<?= h($mc['from_name']) ?>" placeholder="bulkify"></div>
      <div class="bx-field" style="grid-column:1/-1"><label>Portal-Adresse <?= bx_hint('Kommt in die Mails an Lieferanten und Kunden, z. B. https://beta.bulkify.pro') ?></label>
        <input type="text" name="portal_url" value="<?= h((string) meta_get('portal_url', '')) ?>" placeholder="https://beta.bulkify.pro"></div>
    </div>
    <button class="btn btn-primary" type="submit">Speichern</button>
  </form>
</div>

<div class="bx-panel">
  <h2>Testmail</h2>
  <p class="muted" style="margin-top:0">Verschickt eine kurze Nachricht mit den oben gespeicherten Daten. Klappt das, funktioniert auch die Lieferanten-Einladung.</p>
  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="mail_test">
    <div class="bx-field" style="margin:0;min-width:280px"><label>An</label>
      <input type="email" name="test_an" required value="<?= h((string)(current_user()['email'] ?? '')) ?>"></div>
    <button class="btn btn-ghost" type="submit">Testmail senden</button>
  </form>
</div>
<?php endif; ?>
<?php render_footer(); ?>
