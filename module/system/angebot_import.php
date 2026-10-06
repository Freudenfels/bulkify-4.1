<?php
// Angebots-Import: KI liest ein (altes) Angebots-PDF und erkennt Kunde, Produkt/Rezeptur, Datum, Preise
// (Staffeln) + Stück je Packung. Daraus kann man das PDF entweder einem BESTEHENDEN Angebot ZUORDNEN
// (anreichern: Rezeptur verknüpfen, Glas setzen, Preis als Kundenpreis erfassen, PDF anhängen) ODER ein
// NEUES Angebot (Entwurf) daraus anlegen. Die Glasgröße wird aus Kapselgröße × Stück/Packung inferiert
// (verpackung_empfehlung), wenn sie im alten Angebot nicht steht.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/ki.php';

if (!(function_exists('has_role') && (has_role('admin') || has_role('sales')))) {
    render_header('angebot_import', 'Angebots-Import'); bx_head('Angebots-Import', 'Keine Berechtigung.'); render_footer(); return;
}

$fehler  = '';
$schritt = (string)($_GET['schritt'] ?? '');

// --- Original-Datei (aus der Session) ausliefern – zum Gegenlesen im Match-Schritt ---
if ($schritt === 'datei') {
    $fn   = (string)($_SESSION['angebot_import']['datei'] ?? '');
    $path = $fn !== '' ? BX_UPLOADS . '/' . basename($fn) : '';
    if ($path === '' || !is_file($path)) { http_response_code(404); exit('Keine Datei.'); }
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $path) ?: $mime; finfo_close($fi); }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode((string)($_SESSION['angebot_import']['orig'] ?? basename($path))) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path); exit;
}

// --- Schritt 1: PDF hochladen -> KI lesen -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'scan') {
    if (empty($_FILES['datei']['name']) || (int)($_FILES['datei']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $fehler = 'Bitte ein Angebot (PDF oder Bild) hochladen.';
    } else {
        $orig = (string)$_FILES['datei']['name'];
        $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $fehler = 'Nur PDF, JPG, PNG oder WEBP.';
        } else {
            if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
            $fn = 'angimp_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['datei']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
                $fehler = 'Datei konnte nicht gespeichert werden.';
            } else {
                @set_time_limit(600);
                $r = angebotsscan_ki(BX_UPLOADS . '/' . $fn);
                if (empty($r['ok'])) {
                    $fehler = 'Das Angebot konnte nicht gelesen werden: ' . ($r['fehler'] ?? 'unbekannt');
                } else {
                    $_SESSION['angebot_import'] = ['d' => $r['daten'], 'datei' => $fn, 'orig' => mb_substr($orig, 0, 255)];
                    header('Location: ?p=angebot_import&schritt=match'); exit;
                }
            }
        }
    }
}

// --- Schritt 2: anwenden (zuordnen ODER neu) ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anwenden' && !empty($_SESSION['angebot_import'])) {
    $S = $_SESSION['angebot_import']; $d = $S['d'];
    $produktName = mb_substr(trim((string)($_POST['produkt_name'] ?? ($d['produkt_name'] ?? ''))), 0, 190);
    $stueck      = max(0, (int)($_POST['stueck'] ?? ($d['stueck_je_packung'] ?? 0)));
    $datum       = trim((string)($_POST['datum'] ?? ''));
    $datum       = preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) ? $datum : null;
    $glasItem    = (int)($_POST['verpackung_id'] ?? 0);
    // Staffeln aus dem Formular (Menge + VK je Packung).
    $staffeln = [];
    foreach ((array)($_POST['st_menge'] ?? []) as $i => $m) {
        $m = (int)$m; $v = round((float) str_replace(',', '.', (string)(($_POST['st_vk'][$i]) ?? 0)), 4);
        if ($m <= 0 && $v <= 0) continue;
        $staffeln[] = ['menge' => $m, 'vk_stueck' => $v];
    }
    if (!$staffeln && !empty($d['staffeln'])) $staffeln = (array)$d['staffeln'];

    // Kunde auflösen (bestehend / neu anlegen).
    $kModus = (string)($_POST['kunde_id'] ?? '0');
    $kid = 0;
    if ($kModus === 'neu') { $ku = kunde_finden_oder_anlegen((string)($d['kunde_name'] ?? ''), (string)($d['kunde_nr'] ?? '')); $kid = (int)$ku['id']; }
    elseif (ctype_digit($kModus) && (int)$kModus > 0) { $kid = (int)$kModus; }
    if ($kid <= 0) { $_SESSION['angimp_fehler'] = 'Bitte einen Kunden zuordnen (oder neu anlegen).'; header('Location: ?p=angebot_import&schritt=match'); exit; }

    // Rezeptur per Name auflösen (Haus-Rezeptur bevorzugt).
    $rezF = rezeptur_finden_fuzzy($produktName, $kid ?: null); $rezid = $rezF ? (int)$rezF['id'] : 0;

    $modus = ($_POST['modus'] ?? '') === 'zuordnen' ? 'zuordnen' : 'neu';
    $uid   = (int)(current_user()['id'] ?? 0);
    $vk1   = $staffeln ? (float)$staffeln[0]['vk_stueck'] : (float)($d['vk_stueck'] ?? 0);
    $mng1  = $staffeln ? (int)$staffeln[0]['menge'] : (int)($d['menge'] ?? 0);
    $ustInland = (float) meta_get('ust_inland', 19);

    // PDF als Dokument an ein Angebot hängen.
    $pdfAnhaengen = function(int $aid) use ($S, $uid) {
        if ($aid <= 0) return;
        q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,angelegt,hochgeladen_von)
           VALUES ('angebot',?,'angebot_original',?,?,?,?,?)",
          [$aid, mb_substr((string)$S['orig'], 0, 190), (string)$S['datei'], (string)$S['orig'], gmdate('Y-m-d H:i:s'), (int)$uid]);
    };
    // Kundenpreis-Historie erfassen (wenn Rezeptur bekannt).
    $kundenpreisErfassen = function(int $rezid) use ($kid, $datum, $vk1, $stueck, $mng1, $staffeln) {
        if ($rezid <= 0 || !table_exists('rezeptur_kundenpreis')) return;
        q("INSERT INTO rezeptur_kundenpreis (rezeptur_id,kunde_id,datum,vk,stueck_je_packung,menge,preise_json,quelle,angelegt)
           VALUES (?,?,?,?,?,?,?, 'angebot_import', ?)",
          [$rezid, $kid, $datum, $vk1, $stueck ?: null, $mng1 ?: null, json_encode($staffeln), gmdate('Y-m-d H:i:s')]);
    };

    if ($modus === 'zuordnen') {
        $aid = (int)($_POST['angebot_id'] ?? 0);
        if ($aid <= 0 || (int) scalar("SELECT COUNT(*) FROM angebot WHERE id=? AND kunde_id=?", [$aid, $kid]) === 0) {
            $_SESSION['angimp_fehler'] = 'Bitte ein Angebot dieses Kunden zum Zuordnen wählen.'; header('Location: ?p=angebot_import&schritt=match'); exit;
        }
        // Rezeptur am Angebot verknüpfen, falls noch keine da ist.
        if ($rezid > 0 && (int) scalar("SELECT COALESCE(rezeptur_id,0) FROM angebot WHERE id=?", [$aid]) === 0)
            q("UPDATE angebot SET rezeptur_id=? WHERE id=?", [$rezid, $aid]);
        // Glas auf die passenden Positionen setzen, wo noch keins steht.
        if ($glasItem > 0 && $rezid > 0)
            q("UPDATE angebot_position SET verpackung_id=? WHERE angebot_id=? AND rezeptur_id=? AND COALESCE(verpackung_id,0)=0", [$glasItem, $aid, $rezid]);
        // Import-Notiz anhängen.
        $notiz = 'Angebots-Import: Alt-Angebot zugeordnet' . ($datum ? ' (Datum ' . date('d.m.Y', strtotime($datum)) . ')' : '') . '.';
        q("UPDATE angebot SET notiz=TRIM(CONCAT(COALESCE(notiz,''), '\n', ?)) WHERE id=?", [$notiz, $aid]);
        $pdfAnhaengen($aid);
        $kundenpreisErfassen($rezid);
        unset($_SESSION['angebot_import']);
        header('Location: ?p=angebot&id=' . $aid . '&importok=zugeordnet'); exit;
    }

    // NEU: Entwurfs-Angebot anlegen.
    q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,rezeptur_id,notiz) VALUES (?,?,?,?,?,?)",
      [naechste_nummer('AN'), $kid, null, 'offen', $rezid ?: null,
       'Aus Angebots-Import' . ($datum ? ' (Alt-Angebot vom ' . date('d.m.Y', strtotime($datum)) . ')' : '') . ' – Positionen/Preise prüfen.']);
    $aid = (int) insert_id();
    $sort = 0;
    foreach ($staffeln as $stf) {
        q("INSERT INTO angebot_position (angebot_id,sort,bezeichnung,menge,einheit,preis_cent,mwst_satz,quelle,rezeptur_id,stueck,verpackung_id)
           VALUES (?,?,?,?, 'Packung', ?, ?, 'import', ?, ?, ?)",
          [$aid, $sort++, $produktName ?: 'Produkt', (int)$stf['menge'], (int) round(((float)$stf['vk_stueck']) * 100), $ustInland,
           $rezid ?: null, $stueck ?: null, $glasItem ?: null]);
    }
    $pdfAnhaengen($aid);
    $kundenpreisErfassen($rezid);
    unset($_SESSION['angebot_import']);
    header('Location: ?p=angebot&id=' . $aid . '&importok=neu'); exit;
}

$kiBereit = ki_bereit();
render_header('angebot_import', 'Angebots-Import');

/* ============================ Schritt 2: Prüfen & Zuordnen ============================ */
if ($schritt === 'match' && !empty($_SESSION['angebot_import'])) {
    $S = $_SESSION['angebot_import']; $d = $S['d'];
    $produktName = (string)($d['produkt_name'] ?? '');
    $stueck      = (int)($d['stueck_je_packung'] ?? 0);
    $staffeln    = (array)($d['staffeln'] ?? []); if (!$staffeln) $staffeln = [['menge' => (int)($d['menge'] ?? 0), 'vk_stueck' => (float)($d['vk_stueck'] ?? 0)]];

    // Kunde-Match.
    $kMatch = kunde_finden_fuzzy((string)($d['kunde_name'] ?? ''), (string)($d['kunde_nr'] ?? ''));
    $kunden = all("SELECT id, firma FROM kunden ORDER BY firma");

    // Rezeptur-Match + Glas-Vorschlag.
    $rezF = rezeptur_finden_fuzzy($produktName, $kMatch ? (int)$kMatch['id'] : null);
    $rez  = $rezF ? one("SELECT id, nummer, name, kapselgroesse_id FROM rezeptur WHERE id=?", [(int)$rezF['id']]) : null;
    $glasVorschlag = ($rez && (int)($rez['kapselgroesse_id'] ?? 0) > 0 && $stueck > 0) ? (int) verpackung_empfehlung((int)$rez['kapselgroesse_id'], $stueck) : 0;
    $verpOpt = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND COALESCE(gesperrt,0)=0 ORDER BY name");

    // Kandidaten-Angebote des gematchten Kunden.
    $kandidaten = $kMatch ? all("SELECT id, nummer, status, angelegt FROM angebot WHERE kunde_id=? ORDER BY id DESC LIMIT 30", [(int)$kMatch['id']]) : [];
    $stLbl = fn($s) => match ($s) { 'offen'=>'Entwurf','gesendet'=>'gesendet','bestaetigt'=>'bestätigt','abgelehnt'=>'abgelehnt', default=>$s };

    bx_head('Angebots-Import – prüfen & zuordnen', 'KI-Ergebnis kontrollieren, Kunde + Glas bestätigen, dann einem bestehenden Angebot zuordnen ODER neu anlegen.', bx_btn('Abbrechen', '?p=angebot_import', 'ghost'));
    if (!empty($_SESSION['angimp_fehler'])) { echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($_SESSION['angimp_fehler']) . '</div>'; unset($_SESSION['angimp_fehler']); }
    if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
    $origName = (string)($S['orig'] ?? '');
    $istPdf   = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'pdf';
    $zutatenR = (array)($d['zutaten'] ?? []);
    ?>
    <div class="bx-panel">
      <h2 style="margin-top:0">Original <span class="muted" style="font-weight:400;font-size:13px"><?= h($origName) ?></span></h2>
      <?php if ($istPdf): ?>
        <iframe src="?p=angebot_import&schritt=datei" style="width:100%;height:70vh;border:1px solid var(--line);border-radius:8px;background:#fff"></iframe>
      <?php else: ?>
        <img src="?p=angebot_import&schritt=datei" alt="Original" style="max-width:100%;border:1px solid var(--line);border-radius:8px">
      <?php endif; ?>
      <div style="margin-top:6px"><a class="btn btn-ghost btn-sm" href="?p=angebot_import&schritt=datei" target="_blank" rel="noopener">In neuem Tab öffnen</a></div>
    </div>
    <?php if ($zutatenR): ?>
    <div class="bx-panel">
      <h2 style="margin-top:0">Gelesene Zutaten <span class="muted" style="font-weight:400;font-size:13px">(nur Info)</span></h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Wirkstoff</th><th class="bx-num">mg je Einheit</th></tr></thead>
        <tbody><?php foreach ($zutatenR as $z): ?><tr><td><?= h((string)($z['name'] ?? '')) ?></td><td class="bx-num"><?= (float)($z['menge_mg'] ?? 0) > 0 ? h(rtrim(rtrim(number_format((float)$z['menge_mg'], 3, ',', '.'), '0'), ',')) . ' mg' : '<span class="muted">–</span>' ?></td></tr><?php endforeach; ?></tbody>
      </table></div>
      <p class="muted" style="font-size:12px;margin:8px 0 0">Hier wird die Rezeptur nur über den <strong>Namen</strong> zugeordnet – diese Zutaten werden <strong>nicht</strong> als neue Rezeptur angelegt. Zum Importieren der Rezeptur mit Zutaten den <a href="?p=angebotsscan">Angebotsscan</a> nutzen.</p>
    </div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="aktion" value="anwenden">
      <div class="bx-panel">
        <h2 style="margin-top:0">Erkannt</h2>
        <div class="bx-grid">
          <div class="bx-field"><label>Produkt / Rezeptur</label>
            <input type="text" name="produkt_name" value="<?= h($produktName) ?>">
            <div class="muted" style="font-size:12px;margin-top:4px"><?= $rez ? 'Rezeptur erkannt: <strong>' . h($rez['nummer'] . ' · ' . $rez['name']) . '</strong>' : 'Keine passende Rezeptur gefunden (Glas-Vorschlag dann nicht möglich).' ?></div>
          </div>
          <div class="bx-field"><label>Stück je Packung</label><input type="number" name="stueck" value="<?= $stueck ?>" min="0" style="max-width:140px"></div>
          <div class="bx-field"><label>Datum (Alt-Angebot)</label><input type="date" name="datum" value="<?= h((string)($d['datum'] ?? '')) ?>"></div>
          <div class="bx-field"><label>Kunde <span class="muted" style="font-weight:400">(gelesen: <?= h((string)($d['kunde_name'] ?? '–')) . (trim((string)($d['kunde_nr'] ?? '')) !== '' ? ' / ' . h((string)$d['kunde_nr']) : '') ?>)</span></label>
            <select name="kunde_id" class="rscombo">
              <?php if (trim((string)($d['kunde_name'] ?? '')) !== ''): ?><option value="neu" <?= $kMatch ? '' : 'selected' ?>>+ Neu anlegen: <?= h((string)$d['kunde_name']) ?></option><?php endif; ?>
              <?php foreach ($kunden as $kk): ?><option value="<?= (int)$kk['id'] ?>" <?= ($kMatch && (int)$kMatch['id'] === (int)$kk['id']) ? 'selected' : '' ?>><?= h($kk['firma']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="bx-field"><label>Glas / Verpackung <span class="muted" style="font-weight:400"><?= $glasVorschlag ? '(Vorschlag aus Kapselgröße × Stück)' : '(kein Vorschlag – bitte wählen)' ?></span></label>
            <select name="verpackung_id" class="rscombo">
              <option value="">– keins –</option>
              <?php foreach ($verpOpt as $vp): ?><option value="<?= (int)$vp['id'] ?>" <?= $glasVorschlag === (int)$vp['id'] ? 'selected' : '' ?>><?= h($vp['name']) ?><?= $glasVorschlag === (int)$vp['id'] ? ' (Vorschlag)' : '' ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="bx-panel">
        <h2 style="margin-top:0">Preise / Staffeln</h2>
        <div class="bx-tablewrap"><table class="bx-table">
          <thead><tr><th>Menge (Packungen)</th><th>VK je Packung (netto)</th></tr></thead>
          <tbody>
          <?php foreach ($staffeln as $i => $stf): ?>
            <tr>
              <td><input type="number" name="st_menge[<?= $i ?>]" value="<?= (int)$stf['menge'] ?>" min="0" style="max-width:140px"></td>
              <td><input type="text" name="st_vk[<?= $i ?>]" value="<?= h(number_format((float)$stf['vk_stueck'], 4, ',', '')) ?>" style="max-width:140px"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>

      <div class="bx-panel">
        <h2 style="margin-top:0">Zuordnen oder neu anlegen?</h2>
        <div class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap">
          <label style="display:flex;gap:6px;align-items:center"><input type="radio" name="modus" value="zuordnen" <?= $kandidaten ? 'checked' : 'disabled' ?> style="width:auto"> einem bestehenden Angebot zuordnen:</label>
          <select name="angebot_id" style="min-width:260px" <?= $kandidaten ? '' : 'disabled' ?>>
            <?php foreach ($kandidaten as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['nummer']) ?> · <?= h($stLbl($c['status'])) ?><?= !empty($c['angelegt']) ? ' · ' . h(fmt_zeit($c['angelegt'], 'd.m.Y')) : '' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="bx-row" style="gap:10px;align-items:center;margin-top:8px">
          <label style="display:flex;gap:6px;align-items:center"><input type="radio" name="modus" value="neu" <?= $kandidaten ? '' : 'checked' ?> style="width:auto"> neues Angebot (Entwurf) daraus anlegen</label>
        </div>
        <?php if (!$kandidaten): ?><div class="muted" style="font-size:12px;margin-top:6px">Für den gewählten Kunden gibt es (noch) keine Angebote zum Zuordnen – es wird ein neues angelegt.</div><?php endif; ?>
      </div>

      <div class="bx-row" style="margin-top:var(--sp-4)">
        <button class="btn btn-primary" type="submit">Übernehmen</button>
        <a class="btn btn-ghost" href="?p=angebot_import">Abbrechen</a>
      </div>
    </form>
    <?php
    render_footer();
    return;
}

/* ============================ Schritt 1: Upload ============================ */
bx_head('Angebots-Import', 'Altes Angebots-PDF hochladen – die KI erkennt Kunde, Rezeptur, Datum und Preise. Danach zuordnen oder neu anlegen.',
        bx_btn('Angebotsscan (Rezepturen/Preise)', '?p=angebotsscan', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
if (!empty($_SESSION['angimp_fehler'])) { echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($_SESSION['angimp_fehler']) . '</div>'; unset($_SESSION['angimp_fehler']); }
if (!$kiBereit) echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px">KI ist nicht eingerichtet (Einstellungen → KI). Der Import funktioniert erst mit hinterlegtem Schlüssel (läuft auf beta/live).</div>';
?>
<form method="post" enctype="multipart/form-data" class="bx-form">
  <input type="hidden" name="aktion" value="scan">
  <div class="bx-panel">
    <div class="bx-field"><label>Angebot (PDF/Bild)</label><input type="file" name="datei" accept="application/pdf,image/*" required></div>
    <div class="bx-row" style="margin-top:var(--sp-3)"><button class="btn btn-primary" type="submit" data-busy="Liest Angebot…" <?= $kiBereit ? '' : 'disabled' ?>>Einlesen</button><span class="muted" style="font-size:12px;align-self:center;margin-left:8px">dauert 10–60 Sekunden</span></div>
  </div>
</form>
<?php
render_footer();
