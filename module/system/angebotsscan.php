<?php
// Angebotsscan (System): KI liest ein Angebot ein und erfasst Rezeptur, Kunde, Datum und die
// Mengen-Staffeln (Preise). Zweistufig: 1) Hochladen + auslesen, 2) MATCH/Vorschau (alle Infos prüfen,
// Kunde zuordnen/neu anlegen, Staffeln korrigieren) -> speichern. Der Kundenpreis landet an der Rezeptur
// (rezeptur_kundenpreis), sodass man dort die Preise aller Kunden sieht.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$fehler = '';
$schritt = (string)($_GET['schritt'] ?? '');
$detailId = (int)($_GET['id'] ?? 0);

$TYP_LABEL = ['herstellung'=>'Herstellung', 'kapsel'=>'Kapsel', 'verpackung'=>'Verpackung', 'etikett'=>'Etikett', 'zusatz'=>'Zusatz', 'gesamt'=>'Gesamt'];
$eur = fn($x) => number_format((float)$x, (abs((float)$x - round((float)$x, 2)) > 0.0001 ? 4 : 2), ',', '.') . ' €';
$vkInput = fn($x) => (float)$x > 0 ? rtrim(rtrim(number_format((float)$x, 4, ',', ''), '0'), ',') : '';
// mg/Mengen hübsch (Dashboard hat kein menge_txt wie die Sub-Apps).
$mg = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

// --- Schritt 1: Hochladen + auslesen ---------------------------------------
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
            $fn = 'angscan_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['datei']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
                $fehler = 'Datei konnte nicht gespeichert werden.';
            } else {
                @set_time_limit(600);
                $r = angebotsscan_ki(BX_UPLOADS . '/' . $fn);
                if (empty($r['ok'])) {
                    $fehler = 'Das Angebot konnte nicht gelesen werden: ' . ($r['fehler'] ?? 'unbekannt');
                } else {
                    $_SESSION['angebotsscan'] = ['d' => $r['daten'], 'datei' => $fn, 'orig' => mb_substr($orig, 0, 255), 'bemerkung' => trim((string)($_POST['bemerkung'] ?? ''))];
                    header('Location: ?p=angebotsscan&schritt=match'); exit;
                }
            }
        }
    }
}

// --- Schritt 2: Speichern (nach Match) -------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'speichern' && !empty($_SESSION['angebotsscan'])) {
    $S = $_SESSION['angebotsscan']; $d = $S['d'];
    // Korrekturen aus dem Match übernehmen.
    $d['produkt_name']      = mb_substr(trim((string)($_POST['produkt_name'] ?? $d['produkt_name'])), 0, 190);
    $d['stueck_je_packung'] = max(0, (int)($_POST['stueck'] ?? $d['stueck_je_packung']));
    $datum = trim((string)($_POST['datum'] ?? ''));
    $d['datum'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum) ? $datum : null;
    // Staffeln aus den Formularzeilen.
    $st = [];
    $stM = (array)($_POST['st_menge'] ?? []); $stV = (array)($_POST['st_vk'] ?? []);
    foreach ($stM as $i => $m) {
        $m = (int)$m; $v = round((float)str_replace(',', '.', (string)($stV[$i] ?? 0)), 4);
        if ($m <= 0 && $v <= 0) continue;
        $st[] = ['menge' => $m, 'vk_stueck' => $v];
    }
    $d['staffeln'] = $st;

    // Kunde auflösen.
    $kModus = (string)($_POST['kunde_id'] ?? '0');
    $kid = 0; $kneu = false;
    if ($kModus === 'neu') {
        $ku = kunde_finden_oder_anlegen((string)$d['kunde_name'], (string)$d['kunde_nr']);
        $kid = $ku['id']; $kneu = $ku['neu'];
    } elseif (ctype_digit($kModus) && (int)$kModus > 0) {
        $kid = (int)$kModus;
    }

    $r = angebotsscan_speichern($d, [
        'kunde_id' => $kid, 'kunde_neu' => $kneu,
        'datei' => $S['datei'], 'orig' => $S['orig'], 'bemerkung' => $S['bemerkung'],
        'benutzer_id' => (int)(current_user()['id'] ?? 0),
    ]);
    if (empty($r['ok'])) { $fehler = $r['fehler'] ?? 'Speichern fehlgeschlagen.'; }
    else { unset($_SESSION['angebotsscan']); header('Location: ?p=angebotsscan&id=' . (int)$r['scan_id'] . '&neu=1'); exit; }
}

$kiBereit = (function(){ require_once BX_ROOT . '/core/ki.php'; return ki_bereit(); })();

render_header('angebotsscan', 'Angebotsscan');

// ===================== MATCH / VORSCHAU =====================
if ($schritt === 'match' && !empty($_SESSION['angebotsscan'])) {
    $S = $_SESSION['angebotsscan']; $d = $S['d'];
    $zutaten = (array)$d['zutaten']; $preise = (array)$d['preise'];
    $staffeln = (array)($d['staffeln'] ?? []); if (!$staffeln) $staffeln = [['menge'=>$d['menge'], 'vk_stueck'=>$d['vk_stueck']]];
    $sumMg = 0.0; foreach ($zutaten as $z) $sumMg += (float)($z['menge_mg'] ?? 0);
    // Rezeptur-Dedup (Anzeige) + Kunden-Match.
    $rezExist = one("SELECT id, nummer FROM rezeptur WHERE name=? ORDER BY (kunde_id IS NULL) DESC, id LIMIT 1", [$d['produkt_name']]);
    $kMatch = null;
    if ($d['kunde_nr'] !== '') $kMatch = one("SELECT id, firma, kundennummer FROM kunden WHERE kundennummer=? LIMIT 1", [$d['kunde_nr']]);
    if (!$kMatch && $d['kunde_name'] !== '') $kMatch = one("SELECT id, firma, kundennummer FROM kunden WHERE LOWER(firma)=LOWER(?) LIMIT 1", [$d['kunde_name']]);
    $kunden = all("SELECT id, firma, kundennummer FROM kunden ORDER BY firma");

    bx_head('Angebotsscan – prüfen & zuordnen', 'KI-Ergebnis kontrollieren, Kunde zuordnen, Staffeln korrigieren – dann speichern', bx_btn('Abbrechen', '?p=angebotsscan', 'ghost'));
    if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
    ?>
    <form method="post">
      <input type="hidden" name="aktion" value="speichern">
      <div class="bx-panel">
        <h2 style="margin-top:0">Produkt &amp; Kunde</h2>
        <div class="bx-grid">
          <div class="bx-field"><label>Produkt / Rezeptur</label><input type="text" name="produkt_name" value="<?= h($d['produkt_name']) ?>"></div>
          <div class="bx-field"><label>Form</label><div><?= h($d['darreichungsform']) ?></div></div>
          <div class="bx-field"><label>Stück je Packung</label><input type="number" name="stueck" value="<?= (int)$d['stueck_je_packung'] ?>" min="0" style="max-width:140px"></div>
          <div class="bx-field"><label>Angebotsdatum</label><input type="date" name="datum" value="<?= h((string)($d['datum'] ?? '')) ?>" style="max-width:180px"></div>
          <div class="bx-field"><label>Rezeptur</label><div><?= $rezExist ? '<span class="badge badge-ok">vorhanden: ' . h($rezExist['nummer']) . '</span>' : '<span class="badge badge-warn">wird neu angelegt</span>' ?></div></div>
          <div class="bx-field"><label>Kunde <span class="muted" style="font-weight:400">(gelesen: <?= $d['kunde_name'] !== '' ? h($d['kunde_name']) . ($d['kunde_nr'] !== '' ? ' / ' . h($d['kunde_nr']) : '') : '–' ?>)</span></label>
            <select name="kunde_id">
              <?php if ($d['kunde_name'] !== ''): ?><option value="neu" <?= $kMatch ? '' : 'selected' ?>>+ Neu anlegen: <?= h($d['kunde_name']) ?><?= $d['kunde_nr'] !== '' ? ' (' . h($d['kunde_nr']) . ')' : '' ?></option><?php endif; ?>
              <option value="0">– kein Kunde –</option>
              <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>" <?= $kMatch && (int)$kMatch['id'] === (int)$k['id'] ? 'selected' : '' ?>><?= h($k['firma']) ?><?= $k['kundennummer'] ? ' · ' . h($k['kundennummer']) : '' ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="bx-panel">
        <h2 style="margin-top:0">Mengen-Staffeln <?= bx_hint('Je Zeile: Menge (Packungen) und VK je Packung (netto). Leere Zeilen werden ignoriert.') ?></h2>
        <div class="bx-tablewrap"><table class="bx-table" id="staffelTab">
          <thead><tr><th style="width:40%">Menge (Packungen)</th><th style="width:40%">VK je Packung (€)</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($staffeln as $s): ?>
            <tr>
              <td><input type="number" name="st_menge[]" value="<?= (int)($s['menge'] ?? 0) ?>" min="0" style="width:100%"></td>
              <td><input type="text" name="st_vk[]" value="<?= h($vkInput($s['vk_stueck'] ?? 0)) ?>" style="width:100%"></td>
              <td style="text-align:right"><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('tr').remove()">entfernen</button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="bx-row" style="margin-top:8px"><button type="button" class="btn btn-ghost btn-sm" onclick="staffelAdd()">+ Staffel</button></div>
      </div>

      <div class="bx-panel">
        <h2 style="margin-top:0">Wirkstoffe <span class="muted" style="font-weight:400;font-size:13px">(aus dem Angebot – für die Rezeptur)</span></h2>
        <?php if ($zutaten): ?>
        <div class="bx-tablewrap"><table class="bx-table">
          <thead><tr><th>Wirkstoff</th><th class="bx-num">mg je Einheit</th></tr></thead>
          <tbody>
            <?php foreach ($zutaten as $z): ?><tr><td><?= h((string)($z['name'] ?? '')) ?></td><td class="bx-num"><?= (float)($z['menge_mg'] ?? 0) > 0 ? $mg($z['menge_mg']) . ' mg' : '<span class="muted">–</span>' ?></td></tr><?php endforeach; ?>
            <tr><td class="muted">Füllgewicht je Einheit</td><td class="bx-num"><?= $mg($sumMg) ?> mg</td></tr>
          </tbody>
        </table></div>
        <?php else: ?><div class="muted">Keine Wirkstoffe erkannt – die Rezeptur wird (falls neu) ohne Zutaten angelegt.</div><?php endif; ?>
      </div>

      <?php if ($preise): ?>
      <div class="bx-panel">
        <h2 style="margin-top:0">Preis-Aufschlüsselung <span class="muted" style="font-weight:400;font-size:13px">(wie im Angebot)</span></h2>
        <div class="bx-tablewrap"><table class="bx-table">
          <thead><tr><th>Typ</th><th>Bezeichnung</th><th class="bx-num">Einzelpreis</th><th class="bx-num">Menge</th><th>Einheit</th></tr></thead>
          <tbody>
            <?php foreach ($preise as $p): ?>
            <tr>
              <td><?= h($TYP_LABEL[$p['typ'] ?? ''] ?? ($p['typ'] ?? '')) ?></td>
              <td><?= h((string)($p['bezeichnung'] ?? '')) ?></td>
              <td class="bx-num"><?= (float)($p['einzelpreis'] ?? 0) > 0 ? $eur($p['einzelpreis']) : '<span class="muted">–</span>' ?></td>
              <td class="bx-num"><?= (float)($p['menge'] ?? 0) > 0 ? h($mg($p['menge'])) : '<span class="muted">–</span>' ?></td>
              <td><?= h((string)($p['einheit'] ?? '')) ?: '<span class="muted">–</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
      <?php endif; ?>

      <div class="bx-row" style="margin-top:var(--sp-4)">
        <button class="btn btn-primary" type="submit">Speichern</button>
        <a class="btn btn-ghost" href="?p=angebotsscan">Abbrechen</a>
      </div>
      <p class="muted" style="font-size:12px;margin-top:8px">KI-Werte bitte gegenprüfen. Die Rezeptur wird kundenunabhängig geführt; der Preis (je Staffel) wird dem gewählten Kunden an der Rezeptur hinterlegt.</p>
    </form>
    <script>
    function staffelAdd(){
      var tb=document.querySelector('#staffelTab tbody');
      var tr=document.createElement('tr');
      tr.innerHTML='<td><input type="number" name="st_menge[]" value="" min="0" style="width:100%"></td>'
        +'<td><input type="text" name="st_vk[]" value="" style="width:100%"></td>'
        +'<td style="text-align:right"><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest(\'tr\').remove()">entfernen</button></td>';
      tb.appendChild(tr);
    }
    </script>
    <?php
    render_footer();
    return;
}

// ===================== DETAIL =====================
if ($detailId) {
    $s = one("SELECT * FROM angebot_scan WHERE id=?", [$detailId]);
    if (!$s) { echo '<div class="bx-panel">Scan nicht gefunden.</div>'; render_footer(); return; }
    $preise   = json_decode((string)$s['preise_json'], true) ?: [];
    $zutaten  = json_decode((string)$s['zutaten_json'], true) ?: [];
    $staffeln = json_decode((string)($s['staffeln_json'] ?? ''), true) ?: [];
    $rez = $s['rezeptur_id'] ? one("SELECT id, nummer, name FROM rezeptur WHERE id=?", [(int)$s['rezeptur_id']]) : null;
    $kunde = $s['kunde_id'] ? one("SELECT id, firma, kundennummer FROM kunden WHERE id=?", [(int)$s['kunde_id']]) : null;
    $sumMg = 0.0; foreach ($zutaten as $z) $sumMg += (float)($z['menge_mg'] ?? 0);

    bx_head('Angebotsscan: ' . ($s['produkt_name'] ?: 'ohne Namen'), 'Erfasst ' . h(fmt_zeit($s['angelegt'], 'd.m.Y H:i')) . ($s['original_orig'] ? ' · ' . h($s['original_orig']) : ''), bx_btn('Zur Übersicht', '?p=angebotsscan', 'ghost'));
    if (!empty($_GET['neu'])) echo '<div class="bx-panel" style="border-color:#bfe3cf;color:#1a6c3f;padding:12px 16px">Angebot gespeichert. Rezeptur ' . ($s['rezeptur_neu'] ? 'neu angelegt' : 'vorhanden zugeordnet') . ($kunde ? ', Kunde <strong>' . h($kunde['firma']) . '</strong> ' . ($s['kunde_neu'] ? 'neu angelegt' : 'zugeordnet') . ', Preise hinterlegt' : ', kein Kunde') . '.</div>';
    ?>
    <div class="bx-panel">
      <h2 style="margin-top:0">Produkt &amp; Rezeptur</h2>
      <div class="bx-grid">
        <div class="bx-field"><label>Produkt</label><div><strong><?= h($s['produkt_name'] ?: '–') ?></strong></div></div>
        <div class="bx-field"><label>Form</label><div><?= h($s['darreichungsform']) ?></div></div>
        <div class="bx-field"><label>Stück je Packung</label><div><?= (int)$s['stueck_je_packung'] > 0 ? (int)$s['stueck_je_packung'] : '<span class="muted">–</span>' ?></div></div>
        <div class="bx-field"><label>Rezeptur</label><div>
          <?php if ($rez): ?><a href="?p=rezeptur_detail&id=<?= (int)$rez['id'] ?>"><?= h($rez['nummer']) ?> · <?= h($rez['name']) ?></a> <?= $s['rezeptur_neu'] ? '<span class="badge badge-warn">neu angelegt</span>' : '<span class="badge badge-ok">zugeordnet</span>' ?>
          <?php else: ?><span class="muted">–</span><?php endif; ?>
        </div></div>
        <div class="bx-field"><label>Kunde</label><div>
          <?php if ($kunde): ?><a href="?p=kunde&id=<?= (int)$kunde['id'] ?>"><?= h($kunde['firma']) ?></a><?= $kunde['kundennummer'] ? ' · ' . h($kunde['kundennummer']) : '' ?> <?= $s['kunde_neu'] ? '<span class="badge badge-warn">neu angelegt</span>' : '' ?>
          <?php else: ?><span class="muted">nicht zugeordnet</span><?php endif; ?>
        </div></div>
        <div class="bx-field"><label>Angebotsdatum</label><div><?= $s['angebot_datum'] ? h(date('d.m.Y', strtotime((string)$s['angebot_datum']))) : '<span class="muted">–</span>' ?></div></div>
      </div>
    </div>

    <div class="bx-panel">
      <h2 style="margin-top:0">Mengen-Staffeln</h2>
      <?php if ($staffeln): ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th class="bx-num">Menge (Packungen)</th><th class="bx-num">VK je Packung</th></tr></thead>
        <tbody>
          <?php foreach ($staffeln as $st): ?><tr><td class="bx-num"><?= (int)($st['menge'] ?? 0) > 0 ? number_format((int)$st['menge'], 0, ',', '.') : '<span class="muted">–</span>' ?></td><td class="bx-num"><?= (float)($st['vk_stueck'] ?? 0) > 0 ? '<strong>' . $eur($st['vk_stueck']) . '</strong>' : '<span class="muted">–</span>' ?></td></tr><?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="muted">Keine Staffeln erfasst.</div><?php endif; ?>
    </div>

    <div class="bx-panel">
      <h2 style="margin-top:0">Wirkstoffe</h2>
      <?php if ($zutaten): ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Wirkstoff</th><th class="bx-num">mg je Einheit</th></tr></thead>
        <tbody>
          <?php foreach ($zutaten as $z): ?><tr><td><?= h((string)($z['name'] ?? '')) ?></td><td class="bx-num"><?= (float)($z['menge_mg'] ?? 0) > 0 ? $mg($z['menge_mg']) . ' mg' : '<span class="muted">–</span>' ?></td></tr><?php endforeach; ?>
          <tr><td class="muted">Füllgewicht je Einheit</td><td class="bx-num"><?= $mg($sumMg) ?> mg</td></tr>
        </tbody>
      </table></div>
      <?php else: ?><div class="muted">Keine Wirkstoffe erkannt.</div><?php endif; ?>
    </div>

    <div class="bx-panel">
      <h2 style="margin-top:0">Preise (Aufschlüsselung)</h2>
      <?php if ($preise): ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Typ</th><th>Bezeichnung</th><th class="bx-num">Einzelpreis</th><th class="bx-num">Menge</th><th>Einheit</th></tr></thead>
        <tbody>
          <?php foreach ($preise as $p): ?>
            <tr>
              <td><?= h($TYP_LABEL[$p['typ'] ?? ''] ?? ($p['typ'] ?? '')) ?></td>
              <td><?= h((string)($p['bezeichnung'] ?? '')) ?></td>
              <td class="bx-num"><?= (float)($p['einzelpreis'] ?? 0) > 0 ? $eur($p['einzelpreis']) : '<span class="muted">–</span>' ?></td>
              <td class="bx-num"><?= (float)($p['menge'] ?? 0) > 0 ? h($mg($p['menge'])) : '<span class="muted">–</span>' ?></td>
              <td><?= h((string)($p['einheit'] ?? '')) ?: '<span class="muted">–</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="muted">Keine Preiszeilen erkannt.</div><?php endif; ?>
    </div>
    <?php if ($s['bemerkung']): ?><div class="bx-panel"><label class="muted">Bemerkung</label><div><?= nl2br(h($s['bemerkung'])) ?></div></div><?php endif; ?>
    <p class="muted" style="font-size:12px">Die Rezeptur ist kundenunabhängig; die Staffelpreise liegen beim Kunden an der Rezeptur (Block „Kundenpreise").</p>
    <?php
    render_footer();
    return;
}

// ===================== ÜBERSICHT =====================
$rows = all("SELECT s.*, r.nummer AS rez_nummer, k.firma AS kunde_firma
             FROM angebot_scan s
             LEFT JOIN rezeptur r ON r.id=s.rezeptur_id
             LEFT JOIN kunden k ON k.id=s.kunde_id
             ORDER BY s.angelegt DESC, s.id DESC");

bx_head('Angebotsscan', count($rows) . ' gescannte Angebote', '');
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
if (!$kiBereit) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">KI ist nicht eingerichtet – der Angebotsscan braucht die KI (Einstellungen → KI).</div>';
?>
<form method="post" enctype="multipart/form-data" class="bx-form">
  <input type="hidden" name="aktion" value="scan">
  <div class="bx-panel">
    <h2 style="margin-top:0">Angebot scannen</h2>
    <div class="bx-grid">
      <div class="bx-field"><label>Angebot (PDF/Bild)</label><input type="file" name="datei" accept="application/pdf,image/*" required></div>
      <div class="bx-field"><label>Bemerkung (optional)</label><input type="text" name="bemerkung" placeholder="z. B. Quelle / Wettbewerber"></div>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-4)">
      <button class="btn btn-primary" type="submit" <?= $kiBereit ? '' : 'disabled' ?>>Hochladen &amp; auslesen</button>
    </div>
    <p class="muted" style="font-size:12px;margin-top:8px">Die KI liest Produkt, Rezeptur (Wirkstoffe/mg), Kunde, Datum und die Mengen-Staffeln. Danach kommt ein Match-Schritt zum Prüfen und Zuordnen, bevor gespeichert wird. Das Auslesen kann einen Moment dauern.</p>
  </div>
</form>

<?php
$cols = [
    'produkt_name' => ['label'=>'Produkt', 'render'=>fn($r)=> $r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">ohne Namen</span>'],
    'kunde_firma' => ['label'=>'Kunde', 'render'=>fn($r)=> $r['kunde_firma'] ? h($r['kunde_firma']) . ($r['kunde_neu'] ? ' <span class="badge badge-warn">neu</span>' : '') : '<span class="muted">–</span>'],
    'vk'         => ['label'=>'ab VK/Packung', 'num'=>true, 'render'=>fn($r)=> (float)$r['vk'] > 0 ? $eur($r['vk']) : '<span class="muted">–</span>'],
    'rez_nummer' => ['label'=>'Rezeptur', 'render'=>fn($r)=> $r['rez_nummer'] ? h($r['rez_nummer']) . ($r['rezeptur_neu'] ? ' <span class="badge badge-warn">neu</span>' : '') : '<span class="muted">–</span>'],
    'angelegt'   => ['label'=>'Gescannt', 'render'=>fn($r)=> h(fmt_zeit($r['angelegt'], 'd.m.Y H:i'))],
];
bx_table($cols, $rows, [
    'rowUrl' => fn($r) => '?p=angebotsscan&id=' . $r['id'],
    'empty'  => 'Noch kein Angebot gescannt – oben eins hochladen.',
]);
render_footer();
