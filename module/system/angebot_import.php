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
    $datum = trim((string)($_POST['datum'] ?? ''));
    $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) ? $datum : null;

    // Produkte aus dem Formular (je Produkt: Name, Stück/Packung, Glas, Staffeln). Mehrere Produkte je Angebot.
    $produkte = [];
    foreach ((array)($_POST['produkt_name'] ?? []) as $pi => $pn) {
        $name   = mb_substr(trim((string)$pn), 0, 190);
        $glas   = (int)($_POST['verpackung_id'][$pi] ?? 0);
        // „Stück je Packung" gilt nur bei Verpackung; bei Bulk (keine Verpackung) kein Packungsinhalt.
        $stueck = $glas > 0 ? max(0, (int)($_POST['stueck'][$pi] ?? 0)) : 0;
        $besch  = mb_substr(trim((string)($_POST['beschreibung'][$pi] ?? '')), 0, 500);
        $einheit= mb_substr(trim((string)($_POST['einheit'][$pi] ?? '')), 0, 20) ?: 'Stk.';
        $staffeln = [];
        foreach ((array)($_POST['st_menge'][$pi] ?? []) as $j => $m) {
            $m = (int)$m; $v = round((float) str_replace(',', '.', (string)(($_POST['st_vk'][$pi][$j]) ?? 0)), 4);
            if ($m <= 0 && $v <= 0) continue;
            $staffeln[] = ['menge' => $m, 'vk_stueck' => $v];
        }
        if ($name === '' && !$staffeln) continue;
        $produkte[] = ['name' => $name ?: 'Produkt', 'stueck' => $stueck, 'glas' => $glas, 'beschreibung' => $besch, 'einheit' => $einheit, 'staffeln' => $staffeln];
    }
    if (!$produkte) { $_SESSION['angimp_fehler'] = 'Kein Produkt erkannt – bitte mindestens ein Produkt mit Preis angeben.'; header('Location: ?p=angebot_import&schritt=match'); exit; }

    // Kunde auflösen (bestehend / neu anlegen) – gilt fürs ganze Angebot.
    $kModus = (string)($_POST['kunde_id'] ?? '0');
    $kid = 0;
    if ($kModus === 'neu') { $ku = kunde_finden_oder_anlegen((string)($d['kunde_name'] ?? ''), (string)($d['kunde_nr'] ?? '')); $kid = (int)$ku['id']; }
    elseif (ctype_digit($kModus) && (int)$kModus > 0) { $kid = (int)$kModus; }
    if ($kid <= 0) { $_SESSION['angimp_fehler'] = 'Bitte einen Kunden zuordnen (oder neu anlegen).'; header('Location: ?p=angebot_import&schritt=match'); exit; }

    // Rezeptur je Produkt per Name auflösen (Haus-Rezeptur bevorzugt).
    foreach ($produkte as &$pp) { $rezF = rezeptur_finden_fuzzy($pp['name'], $kid ?: null); $pp['rezid'] = $rezF ? (int)$rezF['id'] : 0; } unset($pp);

    // Zuordnen nur sinnvoll bei GENAU EINEM Produkt; mehrere Produkte -> immer neues Angebot.
    $modus = ((($_POST['modus'] ?? '') === 'zuordnen') && count($produkte) === 1) ? 'zuordnen' : 'neu';
    $uid   = (int)(current_user()['id'] ?? 0);
    $ustInland = (float) meta_get('ust_inland', 19);

    $pdfAnhaengen = function(int $aid) use ($S, $uid) {
        if ($aid <= 0) return;
        q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,angelegt,hochgeladen_von)
           VALUES ('angebot',?,'angebot_original',?,?,?,?,?)",
          [$aid, mb_substr((string)$S['orig'], 0, 190), (string)$S['datei'], (string)$S['orig'], gmdate('Y-m-d H:i:s'), (int)$uid]);
    };
    // Kundenpreis-Historie je Produkt (wenn Rezeptur bekannt).
    $kundenpreisErfassen = function(array $pp) use ($kid, $datum) {
        if ((int)$pp['rezid'] <= 0 || !table_exists('rezeptur_kundenpreis')) return;
        $vk1 = $pp['staffeln'] ? (float)$pp['staffeln'][0]['vk_stueck'] : 0.0;
        $mng1 = $pp['staffeln'] ? (int)$pp['staffeln'][0]['menge'] : 0;
        q("INSERT INTO rezeptur_kundenpreis (rezeptur_id,kunde_id,datum,vk,stueck_je_packung,menge,preise_json,quelle,angelegt)
           VALUES (?,?,?,?,?,?,?, 'angebot_import', ?)",
          [(int)$pp['rezid'], $kid, $datum, $vk1, $pp['stueck'] ?: null, $mng1 ?: null, json_encode($pp['staffeln']), gmdate('Y-m-d H:i:s')]);
    };

    if ($modus === 'zuordnen') {
        $pp  = $produkte[0];
        $aid = (int)($_POST['angebot_id'] ?? 0);
        if ($aid <= 0 || (int) scalar("SELECT COUNT(*) FROM angebot WHERE id=? AND kunde_id=?", [$aid, $kid]) === 0) {
            $_SESSION['angimp_fehler'] = 'Bitte ein Angebot dieses Kunden zum Zuordnen wählen.'; header('Location: ?p=angebot_import&schritt=match'); exit;
        }
        if ($pp['rezid'] > 0 && (int) scalar("SELECT COALESCE(rezeptur_id,0) FROM angebot WHERE id=?", [$aid]) === 0)
            q("UPDATE angebot SET rezeptur_id=? WHERE id=?", [$pp['rezid'], $aid]);
        if ($pp['glas'] > 0 && $pp['rezid'] > 0)
            q("UPDATE angebot_position SET verpackung_id=? WHERE angebot_id=? AND rezeptur_id=? AND COALESCE(verpackung_id,0)=0", [$pp['glas'], $aid, $pp['rezid']]);
        $notiz = 'Angebots-Import: Alt-Angebot zugeordnet' . ($datum ? ' (Datum ' . date('d.m.Y', strtotime($datum)) . ')' : '') . '.';
        q("UPDATE angebot SET notiz=TRIM(CONCAT(COALESCE(notiz,''), '\n', ?)) WHERE id=?", [$notiz, $aid]);
        $pdfAnhaengen($aid);
        $kundenpreisErfassen($pp);
        unset($_SESSION['angebot_import']);
        header('Location: ?p=angebot&id=' . $aid . '&importok=zugeordnet'); exit;
    }

    // NEU: EIN Entwurfs-Angebot mit Positionen je Produkt (jedes Produkt eine Gruppe).
    $rezErst = (int)$produkte[0]['rezid'];
    q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,rezeptur_id,notiz) VALUES (?,?,?,?,?,?)",
      [naechste_nummer('AN'), $kid, null, 'offen', $rezErst ?: null,
       'Aus Angebots-Import' . ($datum ? ' (Alt-Angebot vom ' . date('d.m.Y', strtotime($datum)) . ')' : '')
       . (count($produkte) > 1 ? ' – ' . count($produkte) . ' Produkte' : '') . ' – Positionen/Preise prüfen.']);
    $aid = (int) insert_id();
    $sort = 0; $gi = 0;
    foreach ($produkte as $pp) {
        $gruppe = count($produkte) > 1 ? chr(65 + $gi) : null; $gi++;
        foreach ($pp['staffeln'] as $stf) {
            $vk = (float)$stf['vk_stueck'];
            q("INSERT INTO angebot_position (angebot_id,sort,bezeichnung,beschreibung,menge,einheit,preis_cent,preis_e4,mwst_satz,quelle,rezeptur_id,stueck,verpackung_id,gruppe)
               VALUES (?,?,?,?,?,?,?,?,?, 'import', ?, ?, ?, ?)",
              [$aid, $sort++, $pp['name'], (string)($pp['beschreibung'] ?? ''), (int)$stf['menge'], (string)($pp['einheit'] ?? 'Stk.'),
               (int) round($vk * 100), (int) round($vk * 10000), $ustInland,
               $pp['rezid'] ?: null, $pp['stueck'] ?: null, $pp['glas'] ?: null, $gruppe]);
        }
        $kundenpreisErfassen($pp);
    }
    $pdfAnhaengen($aid);
    unset($_SESSION['angebot_import']);
    header('Location: ?p=angebot&id=' . $aid . '&importok=neu'); exit;
}

$kiBereit = ki_bereit();
render_header('angebot_import', 'Angebots-Import');

/* ============================ Schritt 2: Prüfen & Zuordnen ============================ */
if ($schritt === 'match' && !empty($_SESSION['angebot_import'])) {
    $S = $_SESSION['angebot_import']; $d = $S['d'];

    // Produkte: neues Format (produkte[]) oder Altformat (Produktfelder direkt in $d).
    $produkte = (isset($d['produkte']) && is_array($d['produkte']) && $d['produkte']) ? array_values($d['produkte']) : [[
        'produkt_name' => (string)($d['produkt_name'] ?? ''), 'darreichungsform' => (string)($d['darreichungsform'] ?? 'kapsel'),
        'stueck_je_packung' => (int)($d['stueck_je_packung'] ?? 0), 'verpackung' => (string)($d['verpackung'] ?? ''),
        'staffeln' => (array)($d['staffeln'] ?? []), 'zutaten' => (array)($d['zutaten'] ?? []),
        'vk_stueck' => (float)($d['vk_stueck'] ?? 0), 'menge' => (int)($d['menge'] ?? 0),
    ]];
    $mehr = count($produkte) > 1;

    // Kunde-Match (gilt fürs ganze Angebot).
    $kMatch = kunde_finden_fuzzy((string)($d['kunde_name'] ?? ''), (string)($d['kunde_nr'] ?? ''));
    $kunden = all("SELECT id, firma FROM kunden ORDER BY firma");
    $verpOpt = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND COALESCE(gesperrt,0)=0 ORDER BY name");

    // Je Produkt: Rezeptur-Match + Glas-Vorschlag + Staffeln aufbereiten.
    foreach ($produkte as $pi => &$pp) {
        $pn  = (string)($pp['produkt_name'] ?? '');
        $stk = (int)($pp['stueck_je_packung'] ?? 0);
        $rezF = rezeptur_finden_fuzzy($pn, $kMatch ? (int)$kMatch['id'] : null);
        $rez  = $rezF ? one("SELECT id, nummer, name, kapselgroesse_id FROM rezeptur WHERE id=?", [(int)$rezF['id']]) : null;
        $pp['_rez']  = $rez;
        $pp['_glas'] = ($rez && (int)($rez['kapselgroesse_id'] ?? 0) > 0 && $stk > 0) ? (int) verpackung_empfehlung((int)$rez['kapselgroesse_id'], $stk) : 0;
        $st = (array)($pp['staffeln'] ?? []); if (!$st) $st = [['menge' => (int)($pp['menge'] ?? 0), 'vk_stueck' => (float)($pp['vk_stueck'] ?? 0)]];
        $pp['_staffeln'] = $st;
    }
    unset($pp);

    // Kandidaten-Angebote des gematchten Kunden (Zuordnen nur bei EINEM Produkt sinnvoll).
    $kandidaten = ($kMatch && !$mehr) ? all("SELECT id, nummer, status, angelegt FROM angebot WHERE kunde_id=? ORDER BY id DESC LIMIT 30", [(int)$kMatch['id']]) : [];
    $stLbl = fn($s) => match ($s) { 'offen'=>'Entwurf','gesendet'=>'gesendet','bestaetigt'=>'bestätigt','abgelehnt'=>'abgelehnt', default=>$s };

    bx_head('Angebots-Import – prüfen & zuordnen', 'KI-Ergebnis kontrollieren, Kunde + Glas bestätigen, dann einem bestehenden Angebot zuordnen ODER neu anlegen.', bx_btn('Abbrechen', '?p=angebot_import', 'ghost'));
    if (!empty($_SESSION['angimp_fehler'])) { echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($_SESSION['angimp_fehler']) . '</div>'; unset($_SESSION['angimp_fehler']); }
    if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
    $origName = (string)($S['orig'] ?? '');
    $istPdf   = strtolower(pathinfo($origName, PATHINFO_EXTENSION)) === 'pdf';
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
    <datalist id="einhListe"><option value="Stk."><option value="Packung"><option value="kg"><option value="g"><option value="L"><option value="Beutel"></datalist>
    <form method="post">
      <input type="hidden" name="aktion" value="anwenden">
      <div class="bx-panel">
        <h2 style="margin-top:0">Kunde &amp; Datum</h2>
        <div class="bx-grid">
          <div class="bx-field"><label>Kunde <span class="muted" style="font-weight:400">(gelesen: <?= h((string)($d['kunde_name'] ?? '–')) . (trim((string)($d['kunde_nr'] ?? '')) !== '' ? ' / ' . h((string)$d['kunde_nr']) : '') ?>)</span></label>
            <select name="kunde_id" class="rscombo">
              <?php if (trim((string)($d['kunde_name'] ?? '')) !== ''): ?><option value="neu" <?= $kMatch ? '' : 'selected' ?>>+ Neu anlegen: <?= h((string)$d['kunde_name']) ?></option><?php endif; ?>
              <?php foreach ($kunden as $kk): ?><option value="<?= (int)$kk['id'] ?>" <?= ($kMatch && (int)$kMatch['id'] === (int)$kk['id']) ? 'selected' : '' ?>><?= h($kk['firma']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="bx-field"><label>Datum (Alt-Angebot)</label><input type="date" name="datum" value="<?= h((string)($d['datum'] ?? '')) ?>"></div>
        </div>
      </div>

      <?php if ($mehr): ?><div class="bx-panel badge-ok" style="padding:10px 14px"><?= count($produkte) ?> Produkte im Angebot erkannt – alle werden als Positionen (Gruppen A, B, …) in ein neues Angebot übernommen. Bitte je Produkt prüfen.</div><?php endif; ?>

      <?php foreach ($produkte as $pi => $pp): $rez = $pp['_rez'];
          // Beschreibung = wortgetreue Zusatzzeilen der KI; falls leer, aus Zutaten (+ Kapselgröße) bauen.
          $beschVor = trim((string)($pp['beschreibung'] ?? ''));
          if ($beschVor === '' && !empty($pp['zutaten'])) {
              $zl = [];
              foreach ((array)$pp['zutaten'] as $z) { $zn = trim((string)($z['name'] ?? '')); if ($zn === '') continue; $zm = (float)($z['menge_mg'] ?? 0);
                  $zl[] = $zn . ($zm > 0 ? ' ' . rtrim(rtrim(number_format($zm, 3, ',', '.'), '0'), ',') . 'mg' : ''); }
              if (trim((string)($pp['kapselgroesse'] ?? '')) !== '') $zl[] = 'Kapselgröße ' . trim((string)$pp['kapselgroesse']);
              $beschVor = implode("\n", $zl);
          } elseif ($beschVor !== '' && trim((string)($pp['kapselgroesse'] ?? '')) !== '' && mb_stripos($beschVor, trim((string)$pp['kapselgroesse'])) === false) {
              $beschVor .= "\nKapselgröße " . trim((string)$pp['kapselgroesse']);
          }
          $einhVor = trim((string)($pp['einheit'] ?? '')) ?: 'Stk.';
      ?>
      <div class="bx-panel js-prodblock">
        <h2 style="margin-top:0"><?= $mehr ? 'Produkt ' . chr(65 + $pi) : 'Erkannt' ?></h2>
        <div class="bx-grid">
          <div class="bx-field"><label>Produkt / Rezeptur</label>
            <input type="text" name="produkt_name[<?= $pi ?>]" value="<?= h((string)$pp['produkt_name']) ?>">
            <div class="muted" style="font-size:12px;margin-top:4px"><?= $rez ? 'Rezeptur erkannt: <strong>' . h($rez['nummer'] . ' · ' . $rez['name']) . '</strong>' : 'Keine passende Rezeptur gefunden (Glas-Vorschlag dann nicht möglich).' ?></div>
          </div>
          <div class="bx-field"><label>Einheit <?= bx_hint('Verkaufseinheit: Stk. (z. B. lose Kapseln = Bulk), Packung, kg, g, L … Für Bulk-Ware z. B. „Stk." oder „kg". Die Staffel-Spalten richten sich danach.') ?></label>
            <input type="text" name="einheit[<?= $pi ?>]" value="<?= h($einhVor) ?>" list="einhListe" class="js-einh" style="max-width:140px"></div>
          <div class="bx-field"><label>Glas / Verpackung <span class="muted" style="font-weight:400"><?= $pp['_glas'] ? '(Vorschlag)' : '(leer = Bulk/ohne Verpackung)' ?></span></label>
            <select name="verpackung_id[<?= $pi ?>]" class="rscombo js-verp">
              <option value="">– keins (Bulk) –</option>
              <?php foreach ($verpOpt as $vp): ?><option value="<?= (int)$vp['id'] ?>" <?= (int)$pp['_glas'] === (int)$vp['id'] ? 'selected' : '' ?>><?= h($vp['name']) ?><?= (int)$pp['_glas'] === (int)$vp['id'] ? ' (Vorschlag)' : '' ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="bx-field js-stueckwrap"><label>Stück je Packung <?= $pp['kapselgroesse'] ? '<span class="muted" style="font-weight:400">· Kapselgröße ' . h((string)$pp['kapselgroesse']) . '</span>' : '' ?> <?= bx_hint('Nur bei Verpackung/Dose – wie viele Stück in eine Packung. Bei Bulk (keine Verpackung) leer.') ?></label><input type="number" name="stueck[<?= $pi ?>]" value="<?= (int)$pp['stueck_je_packung'] ?>" min="0" style="max-width:140px"></div>
        </div>
        <div class="bx-field" style="margin-top:4px"><label>Beschreibung <span class="muted" style="font-weight:400">(erscheint unter der Position im Angebot)</span></label>
          <textarea name="beschreibung[<?= $pi ?>]" rows="<?= max(2, substr_count($beschVor, "\n") + 1) ?>" style="width:100%"><?= h($beschVor) ?></textarea></div>
        <div style="margin-top:12px;font-weight:600">Preise / Staffeln</div>
        <div class="bx-tablewrap"><table class="bx-table">
          <thead><tr><th class="js-menge-head">Menge (<?= h($einhVor) ?>)</th><th class="js-vk-head">VK je <?= h($einhVor) ?> (netto)</th></tr></thead>
          <tbody>
          <?php foreach ($pp['_staffeln'] as $j => $stf): ?>
            <tr>
              <td><input type="number" name="st_menge[<?= $pi ?>][<?= $j ?>]" value="<?= (int)$stf['menge'] ?>" min="0" style="max-width:140px"></td>
              <td><input type="text" name="st_vk[<?= $pi ?>][<?= $j ?>]" value="<?= h(number_format((float)$stf['vk_stueck'], 4, ',', '')) ?>" style="max-width:140px"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php $zutatenR = (array)($pp['zutaten'] ?? []); if ($zutatenR): ?>
        <details style="margin-top:10px"><summary class="muted" style="font-size:13px;cursor:pointer">Gelesene Zutaten (nur Info, <?= count($zutatenR) ?>)</summary>
          <div class="bx-tablewrap" style="margin-top:8px"><table class="bx-table">
            <thead><tr><th>Wirkstoff</th><th class="bx-num">mg je Einheit</th></tr></thead>
            <tbody><?php foreach ($zutatenR as $z): ?><tr><td><?= h((string)($z['name'] ?? '')) ?></td><td class="bx-num"><?= (float)($z['menge_mg'] ?? 0) > 0 ? h(rtrim(rtrim(number_format((float)$z['menge_mg'], 3, ',', '.'), '0'), ',')) . ' mg' : '<span class="muted">–</span>' ?></td></tr><?php endforeach; ?></tbody>
          </table></div>
          <p class="muted" style="font-size:12px;margin:8px 0 0">Rezeptur wird nur über den <strong>Namen</strong> zugeordnet – diese Zutaten werden <strong>nicht</strong> als neue Rezeptur angelegt. Zum Importieren der Rezeptur mit Zutaten den <a href="?p=angebotsscan">Angebotsscan</a> nutzen.</p>
        </details>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <div class="bx-panel">
        <h2 style="margin-top:0">Zuordnen oder neu anlegen?</h2>
        <?php if ($mehr): ?>
          <input type="hidden" name="modus" value="neu">
          <div class="muted" style="font-size:13px">Bei mehreren Produkten wird immer ein <strong>neues Angebot</strong> (Entwurf) mit allen Produkten als Positionen angelegt.</div>
        <?php else: ?>
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
        <?php endif; ?>
      </div>

      <div class="bx-row" style="margin-top:var(--sp-4)">
        <button class="btn btn-primary" type="submit">Übernehmen</button>
        <a class="btn btn-ghost" href="?p=angebot_import">Abbrechen</a>
      </div>
    </form>
    <script>
    // Je Produktblock: Staffel-Spalten folgen der Einheit; „Stück je Packung" nur bei gewählter Verpackung.
    document.querySelectorAll('.js-prodblock').forEach(function(block){
      var einh = block.querySelector('.js-einh'), verp = block.querySelector('.js-verp'),
          stwrap = block.querySelector('.js-stueckwrap'),
          mh = block.querySelector('.js-menge-head'), vh = block.querySelector('.js-vk-head');
      function upd(){
        var e = (einh && einh.value.trim()) ? einh.value.trim() : 'Einheit';
        if (mh) mh.textContent = 'Menge (' + e + ')';
        if (vh) vh.textContent = 'VK je ' + e + ' (netto)';
        var packaged = verp && verp.value !== '';
        if (stwrap) stwrap.style.display = packaged ? '' : 'none';
      }
      if (einh) einh.addEventListener('input', upd);
      if (verp) verp.addEventListener('change', upd);
      upd();
    });
    </script>
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
