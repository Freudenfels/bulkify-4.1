<?php
// Lager-Einstellungen (nur Admin), in Reitern: Drucker · Formate · Zugänge.
//  - Drucker: Etikettengröße, Drucker-Auswahl, Brücke auf dem Lager-PC, Sender/Blinker.
//  - Formate: Lieferschein-Absender, Standard-Versandart.
//  - Zugänge: API-Schlüssel für DHL (Paket) und Cargoboard (Palette/Fracht). In lg_meta (DB, nicht im Repo).
// AJAX: DHL-Zugang testen (ohne echte Sendung).
if (($_POST['aktion'] ?? '') === 'dhl_test') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../../core/versand.php';
    echo json_encode(versand_dhl_test(), JSON_UNESCAPED_UNICODE);
    exit;
}

$reiter = (string)($_GET['reiter'] ?? 'drucker');
if (!in_array($reiter, ['drucker', 'formate', 'zugaenge'], true)) $reiter = 'drucker';

// Secrets nur ueberschreiben, wenn ein neuer Wert eingegeben wurde (leer = unveraendert lassen).
$setSecret = function (string $key): void { $v = trim((string)($_POST[$key] ?? '')); if ($v !== '') lg_meta_schreiben($key, $v); };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'speichern') {   // Drucker
        lg_meta_schreiben('etikett_format', 'gross');
        lg_meta_schreiben('drucker_name', trim((string)($_POST['drucker_name'] ?? '')));
        lg_meta_schreiben('drucker_lieferschein', trim((string)($_POST['drucker_lieferschein'] ?? '')));
        lg_meta_schreiben('drucker_versandlabel', trim((string)($_POST['drucker_versandlabel'] ?? '')));
        lg_meta_schreiben('drucker_probe', trim((string)($_POST['drucker_probe'] ?? '')));
        flash('Drucker-Einstellungen gespeichert.');
        weiter('?p=einstellungen&reiter=drucker');
    }
    if ($aktion === 'token_neu') {
        lg_bruecke_token(true);
        lg_meta_schreiben('drucker_liste', '');
        lg_meta_schreiben('drucker_standard', '');
        flash('Neuer Schlüssel erzeugt. Alle alten Brücken sind abgemeldet. Jetzt die Brücke NUR auf dem Lager-PC neu herunterladen und starten.');
        weiter('?p=einstellungen&reiter=drucker');
    }
    if ($aktion === 'formate_speichern') {
        lg_meta_schreiben('versand_absender', trim((string)($_POST['versand_absender'] ?? '')));
        lg_meta_schreiben('versand_typ_standard', in_array(($_POST['versand_typ_standard'] ?? 'paket'), ['paket', 'palette'], true) ? (string)$_POST['versand_typ_standard'] : 'paket');
        foreach (['absender_name', 'absender_strasse', 'absender_hausnummer', 'absender_plz', 'absender_ort', 'absender_email', 'absender_telefon'] as $kf)
            lg_meta_schreiben($kf, trim((string)($_POST[$kf] ?? '')));
        lg_meta_schreiben('absender_land', strtoupper(trim((string)($_POST['absender_land'] ?? 'DE'))) ?: 'DE');
        flash('Formate gespeichert.');
        weiter('?p=einstellungen&reiter=formate');
    }
    if ($aktion === 'zugaenge_speichern') {
        // DHL (Paket) – Secrets nur bei Eingabe; Nummern/Format normal.
        $setSecret('dhl_api_key'); $setSecret('dhl_api_user'); $setSecret('dhl_api_secret');
        lg_meta_schreiben('dhl_ekp', preg_replace('/\D/', '', (string)($_POST['dhl_ekp'] ?? '')));
        foreach (['dhl_tn_v01pak', 'dhl_tn_v62wp', 'dhl_tn_v53wpak', 'dhl_tn_v66wpi'] as $tnf)
            lg_meta_schreiben($tnf, preg_replace('/\D/', '', (string)($_POST[$tnf] ?? '')));
        lg_meta_schreiben('dhl_abrechnungsnummer', trim((string)($_POST['dhl_abrechnungsnummer'] ?? '')));
        lg_meta_schreiben('dhl_format_gross', trim((string)($_POST['dhl_format_gross'] ?? '')) ?: '910-300-400');
        lg_meta_schreiben('dhl_format_klein', trim((string)($_POST['dhl_format_klein'] ?? '')) ?: '100x70mm');
        lg_meta_schreiben('dhl_sandbox', ($_POST['dhl_sandbox'] ?? '') === '1' ? '1' : '0');
        // Cargoboard (Palette/Fracht)
        $setSecret('cargoboard_api_key');
        lg_meta_schreiben('cargoboard_sandbox', ($_POST['cargoboard_sandbox'] ?? '') === '1' ? '1' : '0');
        flash('Zugänge gespeichert.');
        weiter('?p=einstellungen&reiter=zugaenge');
    }
}

$etikettFormat = lg_meta_lesen('etikett_format', 'klein');
$drucker       = lg_meta_lesen('drucker_name', '');
$druckerLS     = lg_meta_lesen('drucker_lieferschein', '');
$druckerVL     = lg_meta_lesen('drucker_versandlabel', '');
$druckerProbe  = lg_meta_lesen('drucker_probe', '');
$druckerListe  = array_values(array_filter(array_map('trim', explode('|', lg_meta_lesen('drucker_liste', '')))));
$druckerStd    = lg_meta_lesen('drucker_standard', '');
$zuletzt       = lg_meta_lesen('bruecke_zuletzt', '');
$wach          = $zuletzt !== '' && (time() - strtotime($zuletzt . ' UTC')) < 15;
$programm      = lg_meta_lesen('bruecke_programm', '');
$brueckeIp     = lg_meta_lesen('bruecke_ip', '');
$zuletztTxt    = $zuletzt !== '' ? (function_exists('fmt_zeit') ? fmt_zeit($zuletzt) : $zuletzt) : '';
$sender        = function_exists('led_sender_alle') ? led_sender_alle() : [];
$versandAbs    = lg_meta_lesen('versand_absender', '');
$versandTyp    = lg_meta_lesen('versand_typ_standard', 'paket');
$absName       = lg_meta_lesen('absender_name', '');
$absStr        = lg_meta_lesen('absender_strasse', '');
$absHnr        = lg_meta_lesen('absender_hausnummer', '');
$absPlz        = lg_meta_lesen('absender_plz', '');
$absOrt        = lg_meta_lesen('absender_ort', '');
$absLand       = lg_meta_lesen('absender_land', 'DE');
$absMail       = lg_meta_lesen('absender_email', '');
$absTel        = lg_meta_lesen('absender_telefon', '');

$letztDruck = null;
try { $letztDruck = one("SELECT id, format, status, antwort, angelegt, erledigt FROM lg_druckjob ORDER BY id DESC LIMIT 1"); } catch (Throwable $e) {}
$druckStatusTxt = ['offen' => 'wartet auf die Brücke …', 'abgeholt' => 'von der Brücke geholt, druckt …',
    'ok' => 'gedruckt', 'fehler' => 'Fehler', 'verfallen' => 'abgelaufen (Brücke war offline)'];

// Drucker-Auswahl-Feld (wiederverwendet: Etikett, Lieferschein, Versandlabel).
$druckerSelect = function (string $name, string $wert) use ($druckerListe, $druckerStd): string {
    $h = '';
    if ($druckerListe) {
        $h .= '<select name="' . h($name) . '"><option value="">Standarddrucker' . ($druckerStd !== '' ? ' (' . h($druckerStd) . ')' : '') . '</option>';
        $gef = false;
        foreach ($druckerListe as $p) { $gef = $gef || ($p === $wert); $h .= '<option value="' . h($p) . '"' . ($wert === $p ? ' selected' : '') . '>' . h($p) . '</option>'; }
        if ($wert !== '' && !$gef) $h .= '<option value="' . h($wert) . '" selected>' . h($wert) . ' (nicht gefunden)</option>';
        $h .= '</select>';
    } else {
        $h .= '<input type="text" name="' . h($name) . '" value="' . h($wert) . '" placeholder="z. B. Zebra ZD420">';
    }
    return $h;
};

kopf('Einstellungen', 'einstellungen');
seitenkopf('Einstellungen', 'Drucker, Formate und Zugänge');
flash_zeigen();
$tab = fn(string $k, string $label): string => '<a href="?p=einstellungen&reiter=' . $k . '" class="' . ($reiter === $k ? 'on' : '') . '">' . h($label) . '</a>';
?>
<div class="settabs">
  <?= $tab('drucker', 'Drucker') ?>
  <?= $tab('formate', 'Formate') ?>
  <?= $tab('zugaenge', 'Zugänge') ?>
</div>

<?php if ($reiter === 'drucker'): ?>
<form method="post">
  <input type="hidden" name="aktion" value="speichern">
  <div class="bx-panel">
    <h2 style="margin-top:0">Etikett &amp; Drucker</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Etikettengröße</label>
        <input type="text" value="100 × 150 mm (hoch)" readonly>
        <div class="muted" style="font-size:12px;margin-top:4px">Feste Größe für alle Karton-Etiketten.</div>
      </div>
      <div class="bx-field"><label>Drucker – Karton-Etikett</label>
        <?= $druckerSelect('drucker_name', $drucker) ?>
        <div class="muted" style="font-size:12px;margin-top:4px">Liste von der Brücke auf dem Lager-PC. Leer = Standarddrucker.</div>
      </div>
      <div class="bx-field"><label>Drucker – Lieferschein</label>
        <?= $druckerSelect('drucker_lieferschein', $druckerLS) ?>
        <div class="muted" style="font-size:12px;margin-top:4px">A4-Drucker. Leer = Standarddrucker.</div>
      </div>
      <div class="bx-field"><label>Drucker – Versand-Label</label>
        <?= $druckerSelect('drucker_versandlabel', $druckerVL) ?>
        <div class="muted" style="font-size:12px;margin-top:4px">Label-Drucker (ab Phase 2, DHL/Cargoboard). Leer = Standarddrucker.</div>
      </div>
      <div class="bx-field"><label>Drucker – Proben-Etikett</label>
        <?= $druckerSelect('drucker_probe', $druckerProbe) ?>
        <div class="muted" style="font-size:12px;margin-top:4px">Für Rückstellmuster/Chargenproben aus der Produktion (100 × 70 mm). Leer = Standarddrucker.</div>
      </div>
    </div>
    <div style="margin-top:var(--sp-3)"><button type="submit" class="btn btn-primary">Speichern</button></div>
  </div>
</form>

<div class="bx-panel">
  <h2 style="margin-top:0">Brücke auf dem Lager-PC <span class="badge <?= $wach ? 'badge-ok' : 'badge-warn' ?>" style="margin-left:6px"><?= $wach ? 'läuft' : 'nicht aktiv' ?></span></h2>
  <p class="muted" style="margin:0 0 var(--sp-3)">Ein Programm auf einem PC im Lager. Es lässt die <strong>Blinker</strong> leuchten und <strong>druckt Etiketten</strong> lautlos (SumatraPDF).</p>
  <div class="bx-panel" style="background:var(--panel-2);margin:0 0 var(--sp-4)">
    <div style="font-weight:600;margin-bottom:6px">Diagnose</div>
    <div class="bx-grid" style="gap:var(--sp-2)">
      <div><span class="muted" style="font-size:12px">Letzter Kontakt</span><br><?= $zuletztTxt !== '' ? h($zuletztTxt) . ($wach ? ' (gerade eben)' : '') : '<span class="muted">noch nie</span>' ?></div>
      <div><span class="muted" style="font-size:12px">Gemeldetes Programm</span><br><?= $programm !== '' ? h($programm) : '<span class="muted">–</span>' ?><?= $brueckeIp !== '' ? ' <span class="muted" style="font-size:12px">· von ' . h($brueckeIp) . '</span>' : '' ?></div>
    </div>
    <div style="margin-top:var(--sp-2)"><span class="muted" style="font-size:12px">Gemeldete Drucker</span><br>
      <?= $druckerListe ? h(implode(', ', $druckerListe)) : '<span class="muted">keine gemeldet</span>' ?>
    </div>
    <div style="margin-top:var(--sp-2)"><span class="muted" style="font-size:12px">Letzter Druckauftrag</span><br>
      <?php if ($letztDruck): ?>
        #<?= (int)$letztDruck['id'] ?> (<?= h((string)$letztDruck['format']) ?>) · <strong><?= h($druckStatusTxt[$letztDruck['status']] ?? (string)$letztDruck['status']) ?></strong>
        <?= !empty($letztDruck['antwort']) ? '· ' . h((string)$letztDruck['antwort']) : '' ?>
        <?= !empty($letztDruck['angelegt']) ? '<span class="muted" style="font-size:12px"> · ' . h(function_exists('fmt_zeit') ? fmt_zeit((string)$letztDruck['angelegt']) : (string)$letztDruck['angelegt']) . '</span>' : '' ?>
      <?php else: ?><span class="muted">noch kein Druckauftrag</span><?php endif; ?>
    </div>
  </div>
  <div class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap">
    <a class="btn btn-primary" href="?p=bruecke_skript&art=hintergrund" download>Brücke einrichten (Hintergrund)</a>
    <a class="btn btn-ghost" href="?p=bruecke_skript" download>mit Fenster (zum Testen)</a>
    <a class="btn btn-ghost" href="https://www.sumatrapdfreader.org/download-free-pdf-viewer" target="_blank" rel="noopener">SumatraPDF herunterladen</a>
    <a class="btn btn-ghost" href="?p=sender">Sender &amp; Blinker einrichten</a>
    <a class="btn btn-ghost" href="?p=bruecke_skript&art=reset" download>Brücke entfernen (Reset)</a>
  </div>
  <div style="margin-top:var(--sp-4);padding-top:var(--sp-3);border-top:1px solid var(--line)">
    <form method="post" onsubmit="return confirm('Neuen Schlüssel erzeugen? Damit werden ALLE laufenden Brücken abgemeldet. Danach die Brücke nur auf dem Lager-PC neu herunterladen und starten.');" style="display:inline">
      <input type="hidden" name="aktion" value="token_neu">
      <button type="submit" class="btn btn-ghost">Schlüssel neu erzeugen</button>
    </form>
    <span class="muted" style="font-size:12px;margin-left:var(--sp-2)">Meldet alle alten/versteckten Brücken ab.</span>
  </div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Blinker / Sender</h2>
  <p class="muted" style="margin:0 0 var(--sp-3)"><?= $sender ? count($sender) . ' Sender angelegt.' : 'Noch kein Sender angelegt.' ?></p>
  <a class="btn btn-ghost" href="?p=sender">Sender &amp; Brücke verwalten</a>
  <a class="btn btn-ghost" href="?p=leisten">Blinker testen</a>
</div>

<?php elseif ($reiter === 'formate'): ?>
<form method="post">
  <input type="hidden" name="aktion" value="formate_speichern">
  <div class="bx-panel">
    <h2 style="margin-top:0">Lieferschein</h2>
    <div class="bx-field"><label>Absenderadresse (erscheint auf dem Lieferschein)</label>
      <textarea name="versand_absender" rows="4" placeholder="bulkify / Maniso GmbH&#10;Musterstraße 1&#10;00000 Musterstadt&#10;Deutschland"><?= h($versandAbs) ?></textarea>
      <div class="muted" style="font-size:12px;margin-top:4px">Eine Zeile je Zeile. Leer = Standardtext.</div>
    </div>
  </div>
  <div class="bx-panel">
    <h2 style="margin-top:0">Absender (für DHL / Cargoboard)</h2>
    <p class="muted" style="margin:0 0 var(--sp-3)">Strukturierte Absenderadresse für die Versand-Labels. Pflicht, bevor ein Label erzeugt wird.</p>
    <div class="bx-grid">
      <div class="bx-field"><label>Firma / Name</label><input type="text" name="absender_name" value="<?= h($absName) ?>"></div>
      <div class="bx-field"><label>Straße</label><input type="text" name="absender_strasse" value="<?= h($absStr) ?>"></div>
      <div class="bx-field" style="max-width:120px"><label>Hausnr.</label><input type="text" name="absender_hausnummer" value="<?= h($absHnr) ?>"></div>
      <div class="bx-field" style="max-width:130px"><label>PLZ</label><input type="text" name="absender_plz" value="<?= h($absPlz) ?>"></div>
      <div class="bx-field"><label>Ort</label><input type="text" name="absender_ort" value="<?= h($absOrt) ?>"></div>
      <div class="bx-field" style="max-width:120px"><label>Land</label><input type="text" name="absender_land" maxlength="2" style="text-transform:uppercase" value="<?= h($absLand) ?>"></div>
      <div class="bx-field"><label>E-Mail</label><input type="text" name="absender_email" value="<?= h($absMail) ?>"></div>
      <div class="bx-field"><label>Telefon</label><input type="text" name="absender_telefon" value="<?= h($absTel) ?>"></div>
    </div>
  </div>
  <div class="bx-panel">
    <h2 style="margin-top:0">Lieferschein &amp; Versand</h2>
    <div class="bx-field" style="max-width:260px"><label>Standard-Versandart für neue Sendungen</label>
      <select name="versand_typ_standard">
        <option value="paket" <?= $versandTyp === 'paket' ? 'selected' : '' ?>>Paket (klein)</option>
        <option value="palette" <?= $versandTyp === 'palette' ? 'selected' : '' ?>>Palette / Fracht</option>
      </select>
    </div>
    <div class="bx-field"><label>Etikettengröße (Karton)</label>
      <input type="text" value="100 × 150 mm (hoch)" readonly>
    </div>
    <div style="margin-top:var(--sp-3)"><button type="submit" class="btn btn-primary">Speichern</button></div>
  </div>
</form>

<?php else: /* zugaenge */
  $dhlUserSet   = lg_meta_lesen('dhl_api_user', '') !== '';
  $dhlKeySet    = lg_meta_lesen('dhl_api_key', '') !== '';
  $dhlSecretSet = lg_meta_lesen('dhl_api_secret', '') !== '';
  $dhlAbr       = lg_meta_lesen('dhl_abrechnungsnummer', '');
  $dhlSandbox   = lg_meta_lesen('dhl_sandbox', '1') === '1';
  $cbKeySet     = lg_meta_lesen('cargoboard_api_key', '') !== '';
  $cbSandbox    = lg_meta_lesen('cargoboard_sandbox', '1') === '1';
  $setHint = fn(bool $set): string => $set ? '●●●●● gesetzt – leer lassen = unverändert' : 'noch nicht gesetzt';
?>
<form method="post" autocomplete="off">
  <input type="hidden" name="aktion" value="zugaenge_speichern">
  <?php
    $dhlEkp = lg_meta_lesen('dhl_ekp', '');
    $tnV01 = lg_meta_lesen('dhl_tn_v01pak', ''); $tnV62 = lg_meta_lesen('dhl_tn_v62wp', '');
    $tnV53 = lg_meta_lesen('dhl_tn_v53wpak', ''); $tnV66 = lg_meta_lesen('dhl_tn_v66wpi', '');
    $fmtGross = lg_meta_lesen('dhl_format_gross', '910-300-400'); $fmtKlein = lg_meta_lesen('dhl_format_klein', '100x70mm');
  ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">DHL – Paket DE „Versenden" v2</h2>
    <p class="muted" style="margin:0 0 var(--sp-3)">Geschäftskunden (GKP). Produkt automatisch: Paket (groß) oder Kleinpaket/Warenpost (klein), national &amp; international. API-Key aus dem DHL Developer Portal (je Umgebung eigener Key).</p>
    <div class="bx-grid">
      <div class="bx-field"><label>API-Key (dhl-api-key)</label>
        <input type="password" name="dhl_api_key" value="" placeholder="<?= h($setHint($dhlKeySet)) ?>"></div>
      <div class="bx-field"><label>GK-Benutzer</label>
        <input type="password" name="dhl_api_user" value="" placeholder="<?= h($setHint($dhlUserSet)) ?>"></div>
      <div class="bx-field"><label>GK-Passwort</label>
        <input type="password" name="dhl_api_secret" value="" placeholder="<?= h($setHint($dhlSecretSet)) ?>"></div>
      <div class="bx-field"><label>EKP (10-stellige Kundennummer)</label>
        <input type="text" name="dhl_ekp" value="<?= h($dhlEkp) ?>" placeholder="10 Ziffern"></div>
    </div>
    <div style="font-weight:600;margin:var(--sp-3) 0 6px">Teilnahmenummern (je 4 Ziffern, je Produkt)</div>
    <div class="bx-grid">
      <div class="bx-field"><label>V01PAK – Paket national (groß)</label><input type="text" name="dhl_tn_v01pak" value="<?= h($tnV01) ?>" placeholder="z. B. 0101"></div>
      <div class="bx-field"><label>V62KP – Kleinpaket/Warenpost (klein)</label><input type="text" name="dhl_tn_v62wp" value="<?= h($tnV62) ?>"></div>
      <div class="bx-field"><label>V53WPAK – Paket International (groß)</label><input type="text" name="dhl_tn_v53wpak" value="<?= h($tnV53) ?>"></div>
      <div class="bx-field"><label>V66WPI – Warenpost International (klein)</label><input type="text" name="dhl_tn_v66wpi" value="<?= h($tnV66) ?>"></div>
    </div>
    <div class="bx-grid" style="margin-top:var(--sp-3)">
      <div class="bx-field"><label>Label-Format groß</label><input type="text" name="dhl_format_gross" value="<?= h($fmtGross) ?>" placeholder="910-300-400"></div>
      <div class="bx-field"><label>Label-Format klein</label><input type="text" name="dhl_format_klein" value="<?= h($fmtKlein) ?>" placeholder="100x70mm"></div>
      <div class="bx-field"><label>Alt-Abrechnungsnummer (optional, 14-stellig)</label><input type="text" name="dhl_abrechnungsnummer" value="<?= h($dhlAbr) ?>" placeholder="Fallback, wenn EKP/TN leer"></div>
      <div class="bx-field"><label>Umgebung</label>
        <label class="bx-check" style="margin-top:8px"><input type="checkbox" name="dhl_sandbox" value="1" <?= $dhlSandbox ? 'checked' : '' ?>> Sandbox (Test) verwenden</label></div>
    </div>
    <div class="bx-row" style="gap:var(--sp-3);align-items:center;margin-top:var(--sp-3)">
      <button type="button" class="btn btn-ghost" id="dhlTest">Verbindung testen</button>
      <span id="dhlTestInfo" class="muted" style="font-size:12px"></span>
    </div>
    <div class="muted" style="font-size:12px;margin-top:var(--sp-2)">„Verbindung testen" schickt eine Prüf-Sendung an DHL (erzeugt kein echtes Label). Vorher Absender unter „Formate" eintragen und speichern.</div>
  </div>
  <div class="bx-panel">
    <h2 style="margin-top:0">Cargoboard – Palette / Fracht</h2>
    <p class="muted" style="margin:0 0 var(--sp-3)">REST-API (Angebot → Buchen → Label/Frachtbrief → Tracking). Auth per <code>x-api-key</code>. Wird ab Phase 3 genutzt.</p>
    <div class="bx-grid">
      <div class="bx-field"><label>API-Key (x-api-key)</label>
        <input type="password" name="cargoboard_api_key" value="" placeholder="<?= h($setHint($cbKeySet)) ?>"></div>
      <div class="bx-field"><label>Umgebung</label>
        <label class="bx-check" style="margin-top:8px"><input type="checkbox" name="cargoboard_sandbox" value="1" <?= $cbSandbox ? 'checked' : '' ?>> Sandbox (Test) verwenden</label></div>
    </div>
  </div>
  <div class="bx-panel" style="background:var(--panel-2)">
    <div class="muted" style="font-size:12px">Hinweis: Schlüssel liegen in der Lager-Datenbank (nicht im Code-Repo). Für den scharfen Betrieb später besser in <code>secrets.php</code> auslagern. Eingetragene Schlüssel werden hier nie wieder im Klartext angezeigt.</div>
  </div>
  <div style="margin-top:var(--sp-4)"><button type="submit" class="btn btn-primary">Zugänge speichern</button></div>
</form>
<script>
(function(){
  var b=document.getElementById('dhlTest'), info=document.getElementById('dhlTestInfo');
  if(!b) return;
  b.addEventListener('click',function(){
    info.style.color=''; info.textContent='Teste Verbindung … (gespeicherte Zugänge)';
    var fd=new FormData(); fd.append('aktion','dhl_test');
    fetch('?p=einstellungen',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){ info.style.color=j.ok?'var(--gruen)':'var(--err)'; info.textContent=(j.ok?'OK – ':'')+(j.meldung||(j.ok?'Zugang ok.':'Fehler')); })
      .catch(function(){ info.style.color='var(--err)'; info.textContent='Serverfehler beim Test.'; });
  });
})();
</script>
<?php endif; ?>
<?php
fuss();
