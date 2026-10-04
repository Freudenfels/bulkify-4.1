<?php
// Auftrag aus Angebot/Auftragsbestätigung importieren (KI). Zweistufig:
//   1. Angebot/AB-PDF (Pflicht) + optional Rechnung-PDF hochladen -> KI liest Produkt, Rezeptur,
//      Menge, Verpackung, Preis (und aus der Rechnung Betrag + bezahlt).
//   2. Vorschau mit Dedup-Prüfung -> „Anlegen": Rezeptur/Produkt (falls neu) + Auftrag anlegen,
//      Dokumente anhängen, optional die Rechnung als bezahlten Beleg verknüpfen. Landet unter den
//      Bestellungen des Kunden.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$fehler = '';
$schritt = (string)($_GET['schritt'] ?? '');

// Hilfsfunktion: eine hochgeladene Datei aus $_FILES[$key] in die Ablage legen; gibt [datei,orig] oder null.
$upload = function (string $key, int $kid): ?array {
    if (empty($_FILES[$key]['name']) || (int)($_FILES[$key]['error'] ?? 1) !== UPLOAD_ERR_OK) return null;
    $orig = (string)$_FILES[$key]['name'];
    $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) return null;
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $fn = 'import_' . $kid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$key]['tmp_name'], BX_UPLOADS . '/' . $fn)) return null;
    return ['datei' => $fn, 'orig' => mb_substr($orig, 0, 255)];
};

// --- Schritt 1: Hochladen + auslesen ---------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'lesen') {
    $kid = (int)($_POST['kunde_id'] ?? 0);
    if (!$kid) {
        $fehler = 'Bitte einen Kunden wählen.';
    } else {
        @set_time_limit(600);
        $ang = $upload('angebot', $kid);
        $re  = $upload('rechnung', $kid);
        if (!$ang) {
            $fehler = 'Bitte ein Angebot / eine Auftragsbestätigung (PDF oder Bild) hochladen.';
        } else {
            $ki = auftrag_import_ki(BX_UPLOADS . '/' . $ang['datei']);
            if (empty($ki['ok'])) {
                $fehler = 'Das Angebot konnte nicht gelesen werden: ' . ($ki['fehler'] ?? 'unbekannt');
            } else {
                $reKi = $re ? rechnung_import_ki(BX_UPLOADS . '/' . $re['datei']) : ['ok' => false];
                $_SESSION['auftrag_import'] = [
                    'kid' => $kid, 'd' => $ki['daten'],
                    'angebot' => $ang, 'rechnung' => $re,
                    're' => !empty($reKi['ok']) ? $reKi : null,
                ];
                header('Location: ?p=auftrag_import&schritt=vorschau'); exit;
            }
        }
    }
}

// --- Schritt 2 (Anlegen) ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anlegen' && !empty($_SESSION['auftrag_import'])) {
    $S = $_SESSION['auftrag_import'];
    $kid = (int)$S['kid'];
    $d = $S['d'];
    // Korrekturen aus der Vorschau übernehmen.
    $d['produkt_name']       = mb_substr(trim((string)($_POST['produkt_name'] ?? $d['produkt_name'])), 0, 190);
    $d['menge']              = max(0, (int)($_POST['menge'] ?? $d['menge']));
    $d['stueck_je_packung']  = max(0, (int)($_POST['stueck'] ?? $d['stueck_je_packung']));
    $d['vk_stueck']          = round((float)str_replace(',', '.', (string)($_POST['vk_stueck'] ?? $d['vk_stueck'])), 4);
    $d['gesamt_netto']       = round((float)str_replace(',', '.', (string)($_POST['gesamt_netto'] ?? $d['gesamt_netto'])), 2);
    $status = in_array(($_POST['status'] ?? ''), ['offen','in_produktion','erledigt'], true) ? $_POST['status'] : 'erledigt';

    $r = auftrag_aus_import($kid, $d, $status);
    if (empty($r['ok'])) { $fehler = $r['fehler'] ?? 'Anlegen fehlgeschlagen.'; }
    else {
        $aid = (int)$r['auftrag_id'];
        // Dokumente anhängen (im Portal sichtbar, Bestell-Detail „Dokumente").
        if (!$r['schon_da'] ?? true) { /* no-op */ }
        foreach ([['angebot','ab'], ['rechnung','rechnung']] as [$slot, $typ]) {
            if (!empty($S[$slot]['datei'])) {
                q("INSERT INTO dokument (objekt_typ,objekt_id,typ,datei,datei_orig,dok_datum,kunde_sichtbar,hochgeladen_von)
                   VALUES ('auftrag',?,?,?,?,?,1,'team')",
                  [$aid, $typ, $S[$slot]['datei'], $S[$slot]['orig'], ($d['datum'] ?? null)]);
            }
        }
        // Rechnung als bezahlten Beleg verknüpfen (wenn Rechnung hochgeladen + gewünscht).
        if (!empty($S['rechnung']['datei']) && !empty($_POST['rechnung_verknuepfen']) && !($r['schon_da'] ?? false)) {
            $re = $S['re'] ?: [];
            rechnung_alt_anlegen($kid, [
                'nummer'      => (string)($re['nummer'] ?? ''),
                'datum'       => $re['datum'] ?? ($d['datum'] ?? null),
                'brutto'      => (float)($re['brutto'] ?? 0),
                'netto'       => (float)($re['netto'] ?? 0),
                'ust_prozent' => (float)($re['ust_prozent'] ?? ($d['ust_prozent'] ?? 0)),
                'bezahlt'     => true,
            ], $S['rechnung']['datei'], $S['rechnung']['orig'], $aid);
        }
        unset($_SESSION['auftrag_import']);
        header('Location: ?p=auftrag&id=' . $aid . '&importiert=1'); exit;
    }
}

$kunden = all("SELECT id, firma, kundennummer FROM kunden ORDER BY firma");
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$mg  = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

render_header('rechnungen', 'Auftrag aus Angebot importieren');
bx_head('Auftrag aus Angebot importieren', 'Angebot/AB (und optional Rechnung) hochladen – die KI liest Rezeptur, Mengen und Preise', bx_btn('Zurück zu Rechnungen', '?p=rechnungen', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
$kiBereit = (function(){ require_once BX_ROOT . '/core/ki.php'; return ki_bereit(); })();
if (!$kiBereit) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">KI ist nicht eingerichtet – dieser Import braucht die KI (Einstellungen → KI).</div>';

// ===================== VORSCHAU =====================
if ($schritt === 'vorschau' && !empty($_SESSION['auftrag_import'])):
    $S = $_SESSION['auftrag_import']; $d = $S['d']; $re = $S['re'];
    $kid = (int)$S['kid'];
    $kunde = one("SELECT firma, kundennummer FROM kunden WHERE id=?", [$kid]);
    // Dedup
    $rezExist = one("SELECT id, nummer FROM rezeptur WHERE name=? ORDER BY (kunde_id<=>?) DESC, id LIMIT 1", [$d['produkt_name'], $kid]);
    $verpId = !empty($d['verpackung']) ? verpackung_finden((string)$d['verpackung']) : null;
    $verpName = $verpId ? (string) scalar("SELECT name FROM item WHERE id=?", [$verpId]) : '';
    $aufExist = !empty($d['ab_nummer']) ? one("SELECT id, nummer FROM auftrag WHERE kunde_id=? AND import_ref=? LIMIT 1", [$kid, $d['ab_nummer']]) : null;
    $sumMg = 0.0; foreach ((array)$d['zutaten'] as $z) $sumMg += (float)$z['menge_mg'];
?>
  <?php if ($aufExist): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Zu dieser AB-Nummer <strong><?= h($d['ab_nummer']) ?></strong> gibt es bereits einen Auftrag (<a href="?p=auftrag&id=<?= (int)$aufExist['id'] ?>"><?= h($aufExist['nummer']) ?></a>). Ein erneutes Anlegen wird übersprungen.</div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="aktion" value="anlegen">
    <div class="bx-panel">
      <h2 style="margin-top:0">Vorschau</h2>
      <div class="bx-grid">
        <div class="bx-field"><label>Kunde</label><div class="bx-ovv"><strong><?= h($kunde['firma'] ?? '–') ?></strong><?php if (!empty($d['kunde_nr'])): ?> <span class="muted">· gelesen: Kdnr <?= h($d['kunde_nr']) ?><?= $d['kunde_name'] ? ' / ' . h($d['kunde_name']) : '' ?></span><?php endif; ?></div></div>
        <div class="bx-field"><label>AB-/Angebotsnummer (gelesen)</label><div><?= $d['ab_nummer'] ? h($d['ab_nummer']) : '<span class="muted">–</span>' ?><?= $d['datum'] ? ' · ' . h(date('d.m.Y', strtotime($d['datum']))) : '' ?></div></div>
        <div class="bx-field"><label>Produkt / Rezeptur</label><input type="text" name="produkt_name" value="<?= h($d['produkt_name']) ?>"></div>
        <div class="bx-field"><label>Form</label><div><?= h($d['darreichungsform']) ?></div></div>
        <div class="bx-field"><label>Verpackung (gelesen)</label><div><?= $d['verpackung'] ? h($d['verpackung']) : '<span class="muted">–</span>' ?><?php if ($verpName): ?> <span class="muted">→ <?= h($verpName) ?></span><?php elseif ($d['verpackung']): ?> <span class="muted">(kein Treffer – ohne Verpackung)</span><?php endif; ?></div></div>
        <div class="bx-field"><label>Stück je Packung</label><input type="number" name="stueck" value="<?= (int)$d['stueck_je_packung'] ?>" min="0" style="max-width:140px"></div>
        <div class="bx-field"><label>Menge (Packungen)</label><input type="number" name="menge" value="<?= (int)$d['menge'] ?>" min="0" style="max-width:140px"></div>
        <div class="bx-field"><label>VK je Packung (€)</label><input type="text" name="vk_stueck" value="<?= h(rtrim(rtrim(number_format((float)$d['vk_stueck'], 4, ',', ''), '0'), ',')) ?>" style="max-width:140px"></div>
        <div class="bx-field"><label>Gesamt netto (€)</label><input type="text" name="gesamt_netto" value="<?= h(number_format((float)$d['gesamt_netto'], 2, ',', '')) ?>" style="max-width:140px"></div>
        <div class="bx-field"><label>Status des Auftrags</label>
          <select name="status">
            <option value="erledigt" selected>Abgeschlossen (versendet)</option>
            <option value="in_produktion">In Produktion</option>
            <option value="offen">Offen</option>
          </select></div>
      </div>
    </div>

    <div class="bx-panel">
      <h2 style="margin-top:0">Rezeptur<?php if ($rezExist): ?> <span class="badge badge-ok">bereits vorhanden: <?= h($rezExist['nummer']) ?></span><?php else: ?> <span class="badge badge-warn">wird neu angelegt</span><?php endif; ?></h2>
      <?php if ($d['zutaten']): ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Zutat</th><th class="bx-num">mg je Einheit</th></tr></thead>
        <tbody>
          <?php foreach ($d['zutaten'] as $z): ?><tr><td><?= h((string)$z['name']) ?></td><td class="bx-num"><?= (float)$z['menge_mg'] > 0 ? $mg($z['menge_mg']) . ' mg' : '<span class="muted">–</span>' ?></td></tr><?php endforeach; ?>
          <tr><td class="muted">Füllgewicht je Einheit</td><td class="bx-num"><?= $mg($sumMg) ?> mg</td></tr>
        </tbody>
      </table></div>
      <?php else: ?><div class="muted">Keine Zutaten erkannt – die Rezeptur wird (falls neu) ohne Zutaten angelegt; bitte später ergänzen.</div><?php endif; ?>
    </div>

    <div class="bx-panel">
      <h2 style="margin-top:0">Rechnung</h2>
      <?php if (!empty($S['rechnung']['datei'])): ?>
        <?php if ($re): ?>
        <p style="margin-top:0">Gelesen: <strong><?= $eur($re['brutto']) ?></strong> brutto<?= !empty($re['nummer']) ? ' · Nr. ' . h($re['nummer']) : '' ?><?= !empty($re['datum']) ? ' · ' . h(date('d.m.Y', strtotime($re['datum']))) : '' ?>.</p>
        <?php else: ?><p class="muted" style="margin-top:0">Rechnung hochgeladen, aber kein Betrag erkannt – wird als Dokument angehängt.</p><?php endif; ?>
        <label style="display:flex;gap:10px;align-items:center;cursor:pointer"><input type="checkbox" name="rechnung_verknuepfen" value="1" <?= $re ? 'checked' : '' ?>> Als <strong>bezahlte Rechnung</strong> mit dem Auftrag verknüpfen (Original als Download im Portal).</label>
      <?php else: ?><div class="muted">Keine Rechnung hochgeladen.</div><?php endif; ?>
    </div>

    <div class="bx-row" style="margin-top:var(--sp-4)">
      <button class="btn btn-primary" type="submit" <?= $aufExist ? 'disabled' : '' ?>>Auftrag anlegen</button>
      <a class="btn btn-ghost" href="?p=auftrag_import">Neu beginnen</a>
    </div>
    <p class="muted" style="font-size:12px;margin-top:8px">Hinweis: KI-Werte bitte gegenprüfen. Für importierte Alt-Aufträge wird kein Produktionsauftrag angelegt (historisch) – bei „Offen/In Produktion" kannst du die Produktion danach im Auftrag starten.</p>
  </form>

<?php else: // ===================== SCHRITT 1 ===================== ?>
  <form method="post" enctype="multipart/form-data" class="bx-form">
    <input type="hidden" name="aktion" value="lesen">
    <div class="bx-panel">
      <div class="bx-grid">
        <div class="bx-field"><label>Kunde</label>
          <select name="kunde_id" required>
            <option value="">– Kunde wählen –</option>
            <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h($k['firma']) ?><?= $k['kundennummer'] ? ' · ' . h($k['kundennummer']) : '' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="bx-field"><label>Angebot / Auftragsbestätigung (PDF/Bild)</label><input type="file" name="angebot" accept="application/pdf,image/*" required></div>
        <div class="bx-field"><label>Rechnung (optional, PDF/Bild)</label><input type="file" name="rechnung" accept="application/pdf,image/*"></div>
      </div>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-4)">
      <button class="btn btn-primary" type="submit">Hochladen &amp; auslesen</button>
      <a class="btn btn-ghost" href="?p=rechnungen">Abbrechen</a>
    </div>
    <p class="muted" style="font-size:12px;margin-top:8px">Die KI liest Produkt, Rezeptur (Zutaten/mg), Menge, Verpackung und Preis. Danach kommt eine Vorschau mit Dedup-Prüfung, bevor etwas angelegt wird. Das Auslesen kann einen Moment dauern.</p>
  </form>
<?php endif; ?>
<?php render_footer();
