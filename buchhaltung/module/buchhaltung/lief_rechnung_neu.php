<?php
// Eingangsrechnung (Lieferanten-Rechnung) erfassen. Route: lief_rechnung_neu (Rolle finance).
// Optional vorbefüllt aus einer Bestellung (?bestellung=ID) oder für einen Lieferanten (?lieferant=ID).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/kreditor.php';
require_once BX_ROOT . '/core/belegeingang.php';   // be_datei_speichern(), be_ki_auslesen()
kreditor_init();

// Datei aus dem Request: neue Hochladung (beleg_datei) ODER bereits gespeicherte Datei (staged_* aus KI-Schritt).
function er_datei_aus_request(): ?array {
    if (!empty($_FILES['beleg_datei']['name'])) {
        $g = be_datei_speichern($_FILES['beleg_datei']);
        if ($g) return $g; // ['datei','orig','mime','pfad']
    }
    $sd = trim((string)($_POST['staged_datei'] ?? ''));
    if ($sd !== '' && strpos($sd, '..') === false) {
        $pfad = BX_UPLOADS . '/' . ltrim($sd, '/');
        if (is_file($pfad)) return ['datei' => $sd, 'orig' => (string)($_POST['staged_orig'] ?? ''), 'mime' => (string)($_POST['staged_mime'] ?? ''), 'pfad' => $pfad];
    }
    return null;
}

$fehler = ''; $kiInfo = ''; $kiFehler = '';
$staged = null;                 // bereits gespeicherte Datei (über Schritte hinweg)
$kiDaten = null;                // KI-ausgelesene Felder für die Vorbefüllung
$aktion = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['aktion'] ?? '') : '';

if ($aktion === 'ki_auslesen') {
    $f = er_datei_aus_request();
    if (!$f) { $kiFehler = 'Bitte zuerst eine Datei (PDF oder Foto) auswählen.'; }
    else {
        $staged = $f;
        $ki = be_ki_auslesen($f['pfad']);
        if ($ki['ok']) {
            $kiDaten = $ki['daten'];
            $kiInfo = 'Aus der Datei gelesen – bitte prüfen: ' . trim(($kiDaten['lieferant_name'] ?: '?') . ' · ' . ($kiDaten['beleg_nummer'] ?: 'ohne Nr.') . ' · '
                    . ($kiDaten['brutto'] ? number_format((float)$kiDaten['brutto'], 2, ',', '.') . ' ' . $kiDaten['waehrung'] . ' brutto' : ''));
        } else { $kiFehler = (string)$ki['fehler']; }
    }
}

if ($aktion === 'er_save') {
    $lid = (int)($_POST['lieferant_id'] ?? 0);
    $netto = (float) str_replace(',', '.', (string)($_POST['netto'] ?? '0'));
    if (!$lid)           $fehler = 'Bitte einen Lieferanten wählen.';
    elseif ($netto <= 0) $fehler = 'Bitte einen Netto-Betrag größer 0 eingeben.';
    else {
        $u = current_user();
        $f = er_datei_aus_request();   // optionale Original-Rechnung (Upload oder aus KI-Schritt)
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
            'datei'             => $f['datei'] ?? null,
            'orig_name'         => $f['orig'] ?? null,
            'mime'              => $f['mime'] ?? null,
        ]);
        header('Location: ?p=lief_rechnung&id=' . $id . '&erfasst=1'); exit;
    }
    $staged = er_datei_aus_request();   // bei Fehler: angehängte Datei fürs Re-Rendern behalten
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
    $lf = one("SELECT * FROM lieferanten WHERE id=?", [$vorLieferant]);
    if ($lf) {
        $preZiel = (int)($lf['zahlungsziel_tage'] ?? 0);
        $preWaehrung = strtoupper((string)($lf['waehrung'] ?? 'EUR')) ?: 'EUR';
        $preLand = strtoupper((string)($lf['land'] ?? 'DE')) ?: 'DE';
    }
}

// KI-Vorbefüllung (aus hochgeladener Rechnung): überschreibt die Vorgaben, Lieferant per Namensabgleich.
$preLiefNummer = ''; $preDatum = date('Y-m-d'); $preUstVal = null;
if ($kiDaten) {
    $preLiefNummer = (string)($kiDaten['beleg_nummer'] ?? '');
    if (!empty($kiDaten['datum'])) $preDatum = $kiDaten['datum'];
    if ((float)($kiDaten['netto'] ?? 0) > 0) $preNetto = number_format((float)$kiDaten['netto'], 2, '.', '');
    if (!empty($kiDaten['waehrung'])) $preWaehrung = strtoupper((string)$kiDaten['waehrung']);
    $preUstVal = (float)($kiDaten['ust_prozent'] ?? 0);
    if (!$vorLieferant && !empty($kiDaten['lieferant_name'])) {
        $m = scalar("SELECT id FROM lieferanten WHERE firma LIKE ? ORDER BY id LIMIT 1", ['%' . $kiDaten['lieferant_name'] . '%']);
        if ($m) {
            $vorLieferant = (int)$m;
            $lf2 = one("SELECT zahlungsziel_tage, waehrung, land FROM lieferanten WHERE id=?", [$vorLieferant]);
            if ($lf2) { $preZiel = (int)($lf2['zahlungsziel_tage'] ?? 0); if (empty($kiDaten['waehrung'])) $preWaehrung = strtoupper((string)($lf2['waehrung'] ?? 'EUR')) ?: 'EUR'; $preLand = strtoupper((string)($lf2['land'] ?? 'DE')) ?: 'DE'; }
        }
    }
}

// Vorsteuer: Inland EUR -> ust_inland; Ausland/Fremdwährung -> 0 (China-Import hat keine dt. USt auf der Rechnung).
$ustDefault = ($preWaehrung !== 'EUR' || $preLand !== 'DE') ? 0.0 : (float) meta_get('ust_inland', 19);
if ($preUstVal !== null) $ustDefault = $preUstVal;   // KI-USt hat Vorrang
// Vorschlags-Kurs für die Lieferanten-Währung live ziehen (30-Tage-Ø, gecacht); Stand für den Hinweis.
$preKurs = ''; $kursStand = ''; $kursQuelle = '';
if ($preWaehrung !== 'EUR') {
    $preKurs = number_format(kr_kurs_aktuell($preWaehrung), 6, ',', '');
    $ci = kr_kurs_cached($preWaehrung); $kursStand = $ci['stand']; $kursQuelle = $ci['quelle'];
}

$waehrungen = kr_waehrungen();
$lieferanten = all("SELECT id, firma, waehrung, land FROM lieferanten ORDER BY firma");
$bestellungen = all("SELECT b.id, b.nummer, l.firma FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id ORDER BY b.id DESC LIMIT 100");
// JS-Karten: Lieferant -> Währung und Währung -> Vorschlagskurs (aus Cache, ohne Netz).
$mapWaehrung = [];
foreach ($lieferanten as $l) $mapWaehrung[(int)$l['id']] = strtoupper((string)($l['waehrung'] ?: 'EUR'));
$mapKurs = [];
foreach (array_keys($waehrungen) as $cur) $mapKurs[$cur] = $cur === 'EUR' ? 1 : round(kr_kurs_cached($cur)['wert'], 6);

render_header('buchhaltung', 'Eingangsrechnung erfassen');
bx_head('Eingangsrechnung erfassen', 'Alte/neue Lieferantenrechnung hochladen – die KI liest sie aus – oder manuell erfassen',
        bx_btn('Zurück', '?p=buchhaltung&tab=verbindlichkeiten', 'ghost'));
if ($fehler)   echo '<div class="bx-panel" style="padding:12px 16px;border-color:#e6c4c0;color:var(--err)">' . h($fehler) . '</div>';
if ($kiFehler) echo '<div class="bx-panel" style="padding:12px 16px;border-color:#e6c4c0">' . h($kiFehler) . '</div>';
if ($kiInfo)   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h($kiInfo) . '</div>';
if (!ki_bereit()) echo '<div class="bx-panel" style="padding:12px 16px">Hinweis: Die KI ist nicht eingerichtet – die Datei wird trotzdem gespeichert, die Felder trägst du manuell ein.</div>';
?>
<form method="post" enctype="multipart/form-data" class="bx-form bx-panel" style="max-width:720px">
  <div class="bx-field" style="border:1px dashed var(--line);border-radius:var(--r-sm);padding:12px">
    <label>Original-Rechnung (PDF/Foto) – optional, für den Steuerberater &amp; zur KI-Auslesung</label>
    <?php if ($staged): ?>
      <p class="muted" style="margin:4px 0">Angehängt: <strong><?= h($staged['orig'] ?: basename($staged['datei'])) ?></strong> <span class="muted">(wird beim Speichern übernommen)</span></p>
      <input type="hidden" name="staged_datei" value="<?= h($staged['datei']) ?>">
      <input type="hidden" name="staged_orig" value="<?= h($staged['orig']) ?>">
      <input type="hidden" name="staged_mime" value="<?= h($staged['mime']) ?>">
    <?php endif; ?>
    <div class="bx-row" style="align-items:center;gap:10px">
      <input type="file" name="beleg_datei" accept="image/*,application/pdf" capture="environment">
      <button class="btn btn-ghost btn-sm" type="submit" name="aktion" value="ki_auslesen" formnovalidate data-busy="KI liest …">KI auslesen &amp; vorbefüllen</button>
    </div>
  </div>
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
      <input type="text" name="lief_nummer" value="<?= h($preLiefNummer) ?>" placeholder="z. B. 2026-00123">
    </label>
    <label>Rechnungsdatum
      <input type="date" name="datum" value="<?= h($preDatum) ?>">
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
      <span class="muted" id="erKursHint" style="font-size:12px"><?= $kursQuelle === 'auto' ? 'Ø 30 Tage (EZB)' . ($kursStand ? ', Stand ' . h($kursStand) : '') : ($kursQuelle === 'manuell' ? 'fester Kurs (Einstellungen)' : ($preWaehrung !== 'EUR' ? 'Standardwert – Live-Kurs nicht erreichbar' : '')) ?></span>
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
  <p class="muted" style="margin:4px 0 0">Währung kommt vom Lieferanten. Bei Fremdwährung wird der Kurs automatisch als 30-Tage-Durchschnitt (EZB) vorgeschlagen und in EUR umgerechnet – pro Rechnung überschreibbar. Buchhaltung, offene Posten und DATEV laufen in EUR. USt/Brutto = Netto × Vorsteuer, Fälligkeit = Rechnungsdatum + Zahlungsziel.</p>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit" name="aktion" value="er_save">Eingangsrechnung speichern</button>
    <a class="btn btn-ghost" href="?p=buchhaltung&tab=verbindlichkeiten">Abbrechen</a>
  </div>
</form>
<script>
(function () {
  var mapW = <?= json_encode($mapWaehrung) ?>, mapK = <?= json_encode($mapKurs) ?>;
  var lief = document.getElementById('erLieferant'), cur = document.getElementById('erWaehrung');
  var kursRow = document.getElementById('erKursRow'), kurs = document.getElementById('erKurs');
  var lab = document.getElementById('erCurLabel'), lab2 = document.getElementById('erCurLabel2'), ust = document.getElementById('erUst');
  var hint = document.getElementById('erKursHint');
  function applyCur(setKurs) {
    var c = cur.value || 'EUR';
    lab.textContent = c; lab2.textContent = c;
    if (c === 'EUR') { kursRow.style.display = 'none'; }
    else {
      kursRow.style.display = '';
      if (setKurs) {
        kurs.value = (mapK[c] || '').toString().replace('.', ',');
        if (hint) hint.textContent = 'Vorschlag (EZB-Ø) – bei Bedarf anpassen';
      }
    }
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
