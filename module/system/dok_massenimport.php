<?php
// Massen-Import von Spezifikationen/CoAs (PDF).
// Drei Zustaende, gesteuert ueber den aktiven Job (core/dokimport.php):
//   - kein Job      -> Upload-Formular (viele PDFs auf einmal)
//   - Job 'offen'   -> Fortschritt (KI liest Datei fuer Datei im Hintergrund), Auto-Reload
//   - Job 'bereit'  -> Match-Vorschau: Zuordnung pruefen/korrigieren, dann alle bestaetigten uebernehmen
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dokimport.php';

$ret = '?p=dok_massenimport';

// --- Aktionen (PRG) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = (string)($_POST['aktion'] ?? '');

    if ($akt === 'upload') {
        if (!ki_bereit()) {
            $_SESSION['dim_flash'] = ['fehler', 'Die KI ist nicht eingerichtet (Einstellungen → KI). Der Massen-Import braucht sie.'];
            header('Location: ' . $ret); exit;
        }
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        $erlaubt = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $namen = (array)($_FILES['dateien']['name'] ?? []);
        $dateien = [];
        foreach ($namen as $i => $orig) {
            if (($_FILES['dateien']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo((string)$orig, PATHINFO_EXTENSION)));
            if (!in_array($ext, $erlaubt, true)) continue;
            $fn = 'imp_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (move_uploaded_file($_FILES['dateien']['tmp_name'][$i], BX_UPLOADS . '/' . $fn)) {
                $dateien[] = ['orig' => (string)$orig, 'pfad' => $fn];
            }
            if (count($dateien) >= 150) break;   // Obergrenze je Lauf
        }
        if (!$dateien) {
            $_SESSION['dim_flash'] = ['fehler', 'Keine gültige Datei hochgeladen (erlaubt: PDF, JPG, PNG, WEBP).'];
        } else {
            $uid = (int)((current_user()['id'] ?? 0));
            dokimport_job_neu($dateien, $uid ?: null);
            $_SESSION['dim_flash'] = ['ok', count($dateien) . ' Datei(en) hochgeladen. Die KI liest sie jetzt nacheinander ein.'];
        }
        header('Location: ' . $ret); exit;
    }

    if ($akt === 'zuordnen') {
        $ok = dokimport_zuordnen((int)($_POST['datei_id'] ?? 0), (string)($_POST['eingabe'] ?? ''));
        $_SESSION['dim_flash'] = $ok ? ['ok', 'Rohstoff zugeordnet.'] : ['fehler', 'Kein passender Rohstoff zur Eingabe gefunden.'];
        header('Location: ' . $ret); exit;
    }
    if ($akt === 'skip')   { dokimport_ueberspringen((int)($_POST['datei_id'] ?? 0), true);  header('Location: ' . $ret); exit; }
    if ($akt === 'unskip') { dokimport_ueberspringen((int)($_POST['datei_id'] ?? 0), false); header('Location: ' . $ret); exit; }

    if ($akt === 'import') {
        $r = dokimport_import((int)($_POST['job_id'] ?? 0));
        $msg = $r['importiert'] . ' Dokument(e) übernommen und am jeweiligen Rohstoff hinterlegt (intern).';
        if ($r['offen'] > 0) $msg .= ' ' . $r['offen'] . ' Zeile(n) ohne Zuordnung sind offen geblieben.';
        $_SESSION['dim_flash'] = ['ok', $msg];
        header('Location: ' . $ret); exit;
    }
    if ($akt === 'abbrechen') {
        dokimport_abbrechen((int)($_POST['job_id'] ?? 0));
        $_SESSION['dim_flash'] = ['ok', 'Import abgebrochen. Nicht übernommene Dateien wurden verworfen.'];
        header('Location: ' . $ret); exit;
    }
}

$flash = $_SESSION['dim_flash'] ?? null; unset($_SESSION['dim_flash']);
$kiDa  = ki_bereit();
$job   = dokimport_aktiver_job();

render_header('rohstoffe', 'Specs/CoAs – Massen-Import');
bx_head('Specs/CoAs – Massen-Import', 'Viele Spezifikationen/CoAs als PDF hochladen, der Reihe nach einlesen und den Rohstoffen zuordnen',
        bx_btn('Zu den Rohstoffen', '?p=rohstoffe', 'ghost'));
if ($flash) {
    $cls = $flash[0] === 'fehler' ? 'badge-warn' : 'badge-ok';
    echo '<div class="bx-panel ' . $cls . '" style="padding:10px 14px">' . h((string)$flash[1]) . '</div>';
}

// Label-Helfer
$typLbl = ['spec' => 'Spezifikation', 'coa' => 'CoA', 'beides' => 'Spec + CoA', 'unklar' => 'unklar'];
$quelleLbl = ['cas' => 'über CAS', 'name' => 'über Name', 'fuzzy' => 'namensähnlich', 'manuell' => 'manuell', '' => 'kein Treffer'];
$sichKind  = ['hoch' => 'ok', 'mittel' => '', 'niedrig' => 'warn'];
?>

<?php if (!$job): ?>
  <?php /* ---------- Zustand 1: Upload ---------- */ ?>
  <div class="bx-panel">
    <p class="muted" style="margin-top:0">
      Zieh hier <strong>mehrere PDF-Dateien</strong> rein (Spezifikationen und/oder CoAs). Die KI liest jede Datei einzeln
      nacheinander, erkennt ob es eine Spec oder ein CoA ist, und schlägt den passenden <strong>vorhandenen</strong> Rohstoff vor.
      Danach prüfst du die Zuordnung und übernimmst alles mit einem Klick. Die Originale werden immer nur <strong>intern</strong>
      abgelegt – sie gehen nie an einen Kunden.
    </p>
    <?php if (!$kiDa): ?>
      <div class="bx-panel badge-warn" style="padding:10px 14px">Die KI ist nicht eingerichtet (Einstellungen → KI). Der Massen-Import braucht sie – das läuft nur auf dem Server (beta/live).</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px;align-items:flex-start">
      <input type="hidden" name="aktion" value="upload">
      <div class="bx-field" style="margin:0;width:100%;max-width:560px">
        <label>PDF-Dateien (mehrere möglich)</label>
        <input type="file" name="dateien[]" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple required>
      </div>
      <button class="btn btn-primary" type="submit" data-busy="lade hoch…" <?= $kiDa ? '' : 'disabled' ?>>Hochladen &amp; einlesen</button>
      <div class="muted" style="font-size:12px">Bis zu 150 Dateien je Durchlauf. Das Einlesen läuft im Hintergrund – du kannst die Seite offen lassen.</div>
    </form>
  </div>

<?php else:
    $jobId = (int)$job['id'];
    $fort  = dokimport_fortschritt($jobId);
?>

  <?php if ($fort['status'] === 'offen'): ?>
    <?php /* ---------- Zustand 2: Fortschritt ---------- */ ?>
    <div class="bx-panel">
      <h3 style="margin:0 0 8px;font-weight:600">Die KI liest die Dateien ein …</h3>
      <?php $proz = $fort['anzahl'] > 0 ? round($fort['gelesen'] / $fort['anzahl'] * 100) : 0; ?>
      <div style="background:#eee;border-radius:6px;height:14px;overflow:hidden;max-width:560px">
        <div style="background:var(--gruen,#1D9E75);height:100%;width:<?= $proz ?>%"></div>
      </div>
      <p class="muted" style="margin:8px 0 0"><?= (int)$fort['gelesen'] ?> von <?= (int)$fort['anzahl'] ?> gelesen (<?= $proz ?> %). Jede PDF dauert bis zu ~1–4 Minuten – die Seite lädt sich selbst neu.</p>
      <form method="post" style="margin-top:12px" onsubmit="return confirm('Import wirklich abbrechen? Noch nicht übernommene Dateien werden verworfen.');">
        <input type="hidden" name="aktion" value="abbrechen"><input type="hidden" name="job_id" value="<?= $jobId ?>">
        <button class="btn btn-ghost btn-sm" type="submit">Abbrechen</button>
      </form>
    </div>
    <script>setTimeout(function(){ location.reload(); }, 5000);</script>

  <?php else:
    /* ---------- Zustand 3: Match-Vorschau ---------- */
    $zeilen = dokimport_zeilen($jobId);
    $importierbar = 0;
    foreach ($zeilen as $z) { if ($z['status'] === 'gelesen' && (int)$z['item_id'] > 0) $importierbar++; }
  ?>
    <div class="bx-panel" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
      <div class="muted" style="font-size:13px">
        <?= count($zeilen) ?> Datei(en) gelesen · <strong style="font-weight:600"><?= $importierbar ?></strong> bereit zum Übernehmen.
        Prüfe die Zuordnung – ohne Treffer bleibt eine Zeile offen (kein automatisches Neuanlegen).
      </div>
      <div style="display:flex;gap:8px">
        <form method="post" style="margin:0" onsubmit="return confirm('<?= $importierbar ?> Dokument(e) übernehmen und an den zugeordneten Rohstoffen hinterlegen?');">
          <input type="hidden" name="aktion" value="import"><input type="hidden" name="job_id" value="<?= $jobId ?>">
          <button class="btn btn-primary" type="submit" data-busy="übernehme…" <?= $importierbar > 0 ? '' : 'disabled' ?>>Alle <?= $importierbar ?> übernehmen</button>
        </form>
        <form method="post" style="margin:0" onsubmit="return confirm('Import abbrechen? Nicht übernommene Dateien werden verworfen.');">
          <input type="hidden" name="aktion" value="abbrechen"><input type="hidden" name="job_id" value="<?= $jobId ?>">
          <button class="btn btn-ghost" type="submit">Abbrechen</button>
        </form>
      </div>
    </div>

    <div class="bx-panel">
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr>
          <th>Datei</th><th>Typ</th><th>KI-Sicherheit</th><th>Zuordnung (Rohstoff)</th><th>Aktion</th>
        </tr></thead>
        <tbody>
        <?php foreach ($zeilen as $z):
            $st = (string)$z['status'];
            $iid = (int)$z['item_id'];
        ?>
          <tr<?= in_array($st, ['uebersprungen', 'importiert'], true) ? ' style="opacity:.55"' : '' ?>>
            <td style="max-width:260px;overflow-wrap:anywhere"><?= h((string)$z['dateiname']) ?></td>
            <td><?php
                if ($st === 'fehler') { echo bx_badge('Fehler', 'warn'); }
                else { echo h($typLbl[(string)$z['typ']] ?? (string)$z['typ']); }
            ?></td>
            <td><?php
                if ($st === 'fehler') { echo '<span class="muted" style="font-size:12px">' . h((string)$z['fehler']) . '</span>'; }
                elseif ($z['sicherheit']) { echo bx_badge((string)$z['sicherheit'], $sichKind[(string)$z['sicherheit']] ?? ''); }
                else { echo '–'; }
            ?></td>
            <td style="max-width:320px;overflow-wrap:anywhere">
              <?php if ($iid): ?>
                <a href="?p=rohstoff&id=<?= $iid ?>" target="_blank"><?= h((string)($z['item_nr'] ?: ('R-' . $iid))) ?></a>
                <?= h((string)$z['item_name']) ?>
                <div class="muted" style="font-size:11px"><?= h($quelleLbl[(string)$z['quelle']] ?? (string)$z['quelle']) ?></div>
              <?php elseif ($st === 'fehler'): ?>
                <span class="muted">–</span>
              <?php else: ?>
                <span class="badge-warn" style="padding:1px 7px;border-radius:9px;font-size:12px"><?= $quelleLbl[''] ?></span>
              <?php endif; ?>
              <?php if (in_array($st, ['gelesen', 'uebersprungen'], true)): ?>
                <form method="post" style="margin:6px 0 0;display:flex;gap:6px;align-items:center">
                  <input type="hidden" name="aktion" value="zuordnen"><input type="hidden" name="datei_id" value="<?= (int)$z['id'] ?>">
                  <input type="text" name="eingabe" list="roh_dl" placeholder="Rohstoff-Name / R-Nr." style="min-width:170px;font-size:12px">
                  <button class="btn btn-ghost btn-sm" type="submit" data-busy="…"><?= $iid ? 'ändern' : 'zuordnen' ?></button>
                </form>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap">
              <?php if ($st === 'importiert'): ?>
                <span class="muted" style="font-size:12px">übernommen</span>
              <?php elseif ($st === 'uebersprungen'): ?>
                <form method="post" style="margin:0"><input type="hidden" name="aktion" value="unskip"><input type="hidden" name="datei_id" value="<?= (int)$z['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">wieder aufnehmen</button></form>
              <?php elseif ($st === 'gelesen'): ?>
                <form method="post" style="margin:0"><input type="hidden" name="aktion" value="skip"><input type="hidden" name="datei_id" value="<?= (int)$z['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">überspringen</button></form>
              <?php else: ?>
                <span class="muted" style="font-size:12px">–</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

    <?php
    // Datalist fuer die manuelle Zuordnung (Autovervollstaendigung ueber Rohstoff-Namen).
    echo '<datalist id="roh_dl">';
    foreach (all("SELECT name FROM item WHERE kategorie='rohstoff' ORDER BY name LIMIT 1500") as $r) echo '<option value="' . h((string)$r['name']) . '">';
    echo '</datalist>';
    ?>
  <?php endif; ?>

<?php endif; ?>

<?php render_footer(); ?>
