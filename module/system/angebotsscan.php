<?php
// Angebotsscan (System): KI liest ein Angebot (eigenes oder fremdes) ein und erfasst Rezeptur + Preise.
// Der Kunde ist bewusst uninteressant und wird nicht ausgewertet. Pro Scan entsteht (falls neu) eine
// kundenunabhängige Rezeptur (Status eingefroren); die Preis-Aufschlüsselung wird am Scan festgehalten.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$fehler = '';
$hinweis = '';
$detailId = (int)($_GET['id'] ?? 0);

$TYP_LABEL = ['herstellung'=>'Herstellung', 'kapsel'=>'Kapsel', 'verpackung'=>'Verpackung', 'etikett'=>'Etikett', 'zusatz'=>'Zusatz', 'gesamt'=>'Gesamt'];
$eur = fn($x) => number_format((float)$x, (abs((float)$x - round((float)$x, 2)) > 0.0001 ? 4 : 2), ',', '.') . ' €';

// --- Upload + auslesen ------------------------------------------------------
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
                $r = angebotsscan_verarbeiten(BX_UPLOADS . '/' . $fn, $orig, (int)(current_user()['id'] ?? 0), trim((string)($_POST['bemerkung'] ?? '')));
                if (empty($r['ok'])) {
                    $fehler = 'Das Angebot konnte nicht gelesen werden: ' . ($r['fehler'] ?? 'unbekannt');
                } else {
                    header('Location: ?p=angebotsscan&id=' . (int)$r['scan_id'] . '&neu=1'); exit;
                }
            }
        }
    }
}

$kiBereit = (function(){ require_once BX_ROOT . '/core/ki.php'; return ki_bereit(); })();

render_header('angebotsscan', 'Angebotsscan');

// ===================== DETAIL =====================
if ($detailId) {
    $s = one("SELECT * FROM angebot_scan WHERE id=?", [$detailId]);
    if (!$s) { echo '<div class="bx-panel">Scan nicht gefunden.</div>'; render_footer(); return; }
    $preise  = json_decode((string)$s['preise_json'], true) ?: [];
    $zutaten = json_decode((string)$s['zutaten_json'], true) ?: [];
    $rez = $s['rezeptur_id'] ? one("SELECT id, nummer, name FROM rezeptur WHERE id=?", [(int)$s['rezeptur_id']]) : null;
    $kunde = $s['kunde_id'] ? one("SELECT id, firma, kundennummer FROM kunden WHERE id=?", [(int)$s['kunde_id']]) : null;
    $sumMg = 0.0; foreach ($zutaten as $z) $sumMg += (float)($z['menge_mg'] ?? 0);

    bx_head('Angebotsscan: ' . ($s['produkt_name'] ?: 'ohne Namen'), 'Erfasst ' . h(fmt_zeit($s['angelegt'], 'd.m.Y H:i')) . ($s['original_orig'] ? ' · ' . h($s['original_orig']) : ''), bx_btn('Zur Übersicht', '?p=angebotsscan', 'ghost'));
    if (!empty($_GET['neu'])) echo '<div class="bx-panel" style="border-color:#bfe3cf;color:#1a6c3f;padding:12px 16px">Angebot gescannt. Rezeptur ' . ($s['rezeptur_neu'] ? 'neu angelegt' : 'vorhanden zugeordnet') . ($kunde ? ', Kunde <strong>' . h($kunde['firma']) . '</strong> ' . ($s['kunde_neu'] ? 'neu angelegt' : 'zugeordnet') . ', Preis hinterlegt' : ', kein Kunde erkannt') . '.</div>';
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
          <?php else: ?><span class="muted">nicht erkannt</span><?php endif; ?>
        </div></div>
        <div class="bx-field"><label>Angebotsdatum</label><div><?= $s['angebot_datum'] ? h(date('d.m.Y', strtotime((string)$s['angebot_datum']))) : '<span class="muted">–</span>' ?></div></div>
        <div class="bx-field"><label>VK je Packung (für Kunde)</label><div><?= (float)$s['vk'] > 0 ? '<strong>' . $eur($s['vk']) . '</strong>' : '<span class="muted">–</span>' ?></div></div>
      </div>
    </div>

    <div class="bx-panel">
      <h2 style="margin-top:0">Wirkstoffe</h2>
      <?php if ($zutaten): ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Wirkstoff</th><th class="bx-num">mg je Einheit</th></tr></thead>
        <tbody>
          <?php foreach ($zutaten as $z): ?><tr><td><?= h((string)($z['name'] ?? '')) ?></td><td class="bx-num"><?= (float)($z['menge_mg'] ?? 0) > 0 ? menge_txt($z['menge_mg']) . ' mg' : '<span class="muted">–</span>' ?></td></tr><?php endforeach; ?>
          <tr><td class="muted">Füllgewicht je Einheit</td><td class="bx-num"><?= menge_txt($sumMg) ?> mg</td></tr>
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
              <td class="bx-num"><?= (float)($p['menge'] ?? 0) > 0 ? h(menge_txt($p['menge'])) : '<span class="muted">–</span>' ?></td>
              <td><?= h((string)($p['einheit'] ?? '')) ?: '<span class="muted">–</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><div class="muted">Keine Preiszeilen erkannt.</div><?php endif; ?>
    </div>
    <?php if ($s['bemerkung']): ?><div class="bx-panel"><label class="muted">Bemerkung</label><div><?= nl2br(h($s['bemerkung'])) ?></div></div><?php endif; ?>
    <p class="muted" style="font-size:12px">Die KI-Werte bitte gegenprüfen. Die Rezeptur ist kundenunabhängig (Status eingefroren) und kann in den Rezepturen weiterverwendet werden.</p>
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
    <p class="muted" style="font-size:12px;margin-top:8px">Die KI liest Produkt, Rezeptur (Wirkstoffe/mg) und die Preis-Aufschlüsselung. Der Kunde wird nicht ausgewertet. Das Auslesen kann einen Moment dauern.</p>
  </div>
</form>

<?php
$cols = [
    'produkt_name' => ['label'=>'Produkt', 'render'=>fn($r)=> $r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">ohne Namen</span>'],
    'kunde_firma' => ['label'=>'Kunde', 'render'=>fn($r)=> $r['kunde_firma'] ? h($r['kunde_firma']) . ($r['kunde_neu'] ? ' <span class="badge badge-warn">neu</span>' : '') : '<span class="muted">–</span>'],
    'vk'         => ['label'=>'VK/Packung', 'num'=>true, 'render'=>fn($r)=> (float)$r['vk'] > 0 ? $eur($r['vk']) : '<span class="muted">–</span>'],
    'rez_nummer' => ['label'=>'Rezeptur', 'render'=>fn($r)=> $r['rez_nummer'] ? h($r['rez_nummer']) . ($r['rezeptur_neu'] ? ' <span class="badge badge-warn">neu</span>' : '') : '<span class="muted">–</span>'],
    'angelegt'   => ['label'=>'Gescannt', 'render'=>fn($r)=> h(fmt_zeit($r['angelegt'], 'd.m.Y H:i'))],
];
bx_table($cols, $rows, [
    'rowUrl' => fn($r) => '?p=angebotsscan&id=' . $r['id'],
    'empty'  => 'Noch kein Angebot gescannt – oben eins hochladen.',
]);
render_footer();
