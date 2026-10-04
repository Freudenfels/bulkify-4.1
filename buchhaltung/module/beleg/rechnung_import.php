<?php
// Alt-Rechnungen importieren: mehrere Original-Rechnungen (PDF/Bild) für EINEN Kunden hochladen.
// Die KI liest je Datei Nummer, Datum, Betrag und (wenn erkennbar) den Bezahlstatus aus; daraus wird
// je Rechnung ein Beleg angelegt (im Kundenportal sichtbar, mit Original-Download). Prüf-/Korrekturweg
// danach über die normale Rechnung (Kopf bearbeiten / Zahlung erfassen).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$ergebnisse = []; $fehler = '';
$vorKid = (int)($_GET['kunde_id'] ?? ($_POST['kunde_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'import') {
    $kid = (int)($_POST['kunde_id'] ?? 0);
    $alleBezahlt = !empty($_POST['alle_bezahlt']);
    $files = $_FILES['rechnungen'] ?? null;
    if (!$kid) {
        $fehler = 'Bitte zuerst einen Kunden wählen.';
    } elseif (!$files || !is_array($files['name']) || trim(implode('', $files['name'])) === '') {
        $fehler = 'Bitte mindestens eine Rechnung (PDF oder Bild) hochladen.';
    } else {
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        @set_time_limit(600);   // KI je Datei kann dauern
        for ($i = 0; $i < count($files['name']); $i++) {
            if ((int)($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK || ($files['name'][$i] ?? '') === '') continue;
            $orig = (string)$files['name'][$i];
            $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
                $ergebnisse[] = ['datei' => $orig, 'ok' => false, 'msg' => 'Dateityp nicht erlaubt (PDF/JPG/PNG).'];
                continue;
            }
            $fn = 'altre_' . $kid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($files['tmp_name'][$i], BX_UPLOADS . '/' . $fn)) {
                $ergebnisse[] = ['datei' => $orig, 'ok' => false, 'msg' => 'Datei konnte nicht gespeichert werden.'];
                continue;
            }
            $ki = rechnung_import_ki(BX_UPLOADS . '/' . $fn);
            if (empty($ki['ok'])) {
                // Datei behalten + Beleg trotzdem anlegen (ohne KI-Werte), damit sie nicht verloren geht.
                $bid = rechnung_alt_anlegen($kid, ['bezahlt' => $alleBezahlt], $fn, $orig);
                $ergebnisse[] = ['datei' => $orig, 'ok' => (bool)$bid, 'beleg_id' => $bid,
                    'msg' => 'KI konnte nichts lesen (' . ($ki['fehler'] ?? '') . ') – Beleg ohne Betrag angelegt, bitte Kopf bearbeiten.'];
                continue;
            }
            if ($alleBezahlt) $ki['bezahlt'] = true;
            $bid = rechnung_alt_anlegen($kid, $ki, $fn, $orig);
            $ergebnisse[] = ['datei' => $orig, 'ok' => (bool)$bid, 'beleg_id' => $bid,
                'nummer' => $ki['nummer'], 'datum' => $ki['datum'], 'brutto' => $ki['brutto'], 'bezahlt' => !empty($ki['bezahlt']),
                'msg' => $bid ? '' : 'Kein Betrag erkannt – nicht angelegt.'];
        }
        $vorKid = $kid;
    }
}

$kunden = all("SELECT id, firma, kundennummer FROM kunden ORDER BY firma");
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

render_header('rechnungen', 'Alt-Rechnungen importieren');
bx_head('Alt-Rechnungen importieren', 'Original-Rechnungen hochladen – die KI liest Nummer, Datum, Betrag und Bezahlstatus aus', bx_btn('Zurück zu Rechnungen', '?p=rechnungen', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';
$kiBereit = (function(){ require_once BX_ROOT . '/core/ki.php'; return ki_bereit(); })();
if (!$kiBereit) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">KI ist nicht eingerichtet – die Belege werden dann ohne Betrag angelegt (du kannst sie je Rechnung über „Kopf bearbeiten" nachtragen).</div>';
?>
<?php if ($ergebnisse): ?>
<div class="bx-panel badge-ok" style="padding:12px 16px"><?= count(array_filter($ergebnisse, fn($r) => $r['ok'])) ?> Rechnung(en) importiert – im Kundenportal sichtbar.</div>
<div class="bx-panel">
  <h2 style="margin-top:0">Ergebnis</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Datei</th><th>Nummer</th><th>Datum</th><th class="bx-num">Betrag</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($ergebnisse as $r): ?>
      <tr>
        <td><?= h($r['datei']) ?><?php if (!empty($r['msg'])): ?><div class="muted" style="font-size:12px;white-space:normal"><?= h($r['msg']) ?></div><?php endif; ?></td>
        <td><?= h((string)($r['nummer'] ?? '')) ?: '<span class="muted">–</span>' ?></td>
        <td><?= !empty($r['datum']) ? h(date('d.m.Y', strtotime((string)$r['datum']))) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= isset($r['brutto']) && $r['brutto'] > 0 ? $eur($r['brutto']) : '<span class="muted">–</span>' ?></td>
        <td><?= $r['ok'] ? (!empty($r['bezahlt']) ? bx_badge('bezahlt','ok') : bx_badge('offen','warn')) : bx_badge('Fehler','err') ?></td>
        <td class="bx-num"><?php if (!empty($r['beleg_id'])): ?><a class="btn btn-ghost btn-sm" href="?p=rechnung&id=<?= (int)$r['beleg_id'] ?>">öffnen</a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin-top:10px">Stimmt etwas nicht (Betrag/Datum/Status)? Rechnung öffnen → „Rechnungskopf bearbeiten" bzw. Zahlung erfassen. Nummer/Betrag der KI immer kurz gegenprüfen.</p>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="bx-form">
  <input type="hidden" name="aktion" value="import">
  <div class="bx-panel">
    <div class="bx-grid">
      <div class="bx-field"><label>Kunde</label>
        <select name="kunde_id" required>
          <option value="">– Kunde wählen –</option>
          <?php foreach ($kunden as $k): ?>
            <option value="<?= (int)$k['id'] ?>" <?= $vorKid === (int)$k['id'] ? 'selected' : '' ?>><?= h($k['firma']) ?><?= $k['kundennummer'] ? ' · ' . h($k['kundennummer']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Rechnungen (PDF / Bild, mehrere möglich)</label>
        <input type="file" name="rechnungen[]" accept="application/pdf,image/*" multiple required></div>
    </div>
    <label style="display:flex;gap:10px;align-items:flex-start;cursor:pointer;margin-top:4px">
      <input type="checkbox" name="alle_bezahlt" value="1" style="margin-top:3px">
      <span>Alle als <strong>bezahlt</strong> markieren (wenn es sich um bereits beglichene Alt-Rechnungen handelt). Sonst übernimmt die KI den Status, soweit erkennbar – sonst „offen".</span>
    </label>
  </div>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit">Hochladen &amp; auslesen</button>
    <a class="btn btn-ghost" href="?p=rechnungen">Abbrechen</a>
  </div>
  <p class="muted" style="font-size:12px;margin-top:8px">Je Datei entsteht eine Rechnung beim gewählten Kunden (im Portal sichtbar, Original als Download). Bei vielen Dateien kann das Auslesen etwas dauern.</p>
</form>
<?php render_footer();
