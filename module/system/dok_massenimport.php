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

// Vorschau: die hochgeladene Datei einer Zeile INLINE ausliefern (fuer das Popup). Route ist bereits
// rollengeschuetzt (public/index.php), daher kein weiterer Login-Check noetig.
if (($_GET['vorschau'] ?? '') !== '') {
    $d = one("SELECT pfad, dateiname FROM dok_import_datei WHERE id=?", [(int)$_GET['vorschau']]);
    if ($d) {
        $pfad = BX_UPLOADS . '/' . basename((string)$d['pfad']);
        if (is_file($pfad)) {
            $ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
            $ct  = $ext === 'pdf' ? 'application/pdf'
                 : ($ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg'));
            header('Content-Type: ' . $ct);
            header('Content-Disposition: inline; filename="' . rawurlencode((string)$d['dateiname']) . '"');
            header('Content-Length: ' . filesize($pfad));
            header('X-Content-Type-Options: nosniff');
            readfile($pfad);
            exit;
        }
    }
    http_response_code(404); echo 'nicht gefunden'; exit;
}

// Hochgeladene Dateien (Feld dateien[]) nach data/uploads schieben. Rueckgabe: [angekommen, [dateien]].
$dim_dateien_einlesen = function (): array {
    if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
    $erlaubt = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    $namen = (array)($_FILES['dateien']['name'] ?? []);
    $angekommen = 0; $dateien = [];
    foreach ($namen as $i => $orig) {
        if (($_FILES['dateien']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $angekommen++;
        if (($_FILES['dateien']['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo((string)$orig, PATHINFO_EXTENSION)));
        if (!in_array($ext, $erlaubt, true)) continue;
        $fn = 'imp_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (move_uploaded_file($_FILES['dateien']['tmp_name'][$i], BX_UPLOADS . '/' . $fn)) {
            $dateien[] = ['orig' => (string)$orig, 'pfad' => $fn];
        }
    }
    return [$angekommen, $dateien];
};

// --- Aktionen (PRG) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = (string)($_POST['aktion'] ?? '');

    // POST kam leer an, obwohl Daten geschickt wurden = post_max_size ueberschritten (zu viele/zu grosse Dateien).
    if ($akt === '' && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $max = ini_get('post_max_size');
        $_SESSION['dim_flash'] = ['fehler', 'Der Upload war zu groß (Server-Grenze post_max_size=' . $max . '). Bitte in kleineren Schüben hochladen (z. B. 20 Dateien).'];
        header('Location: ' . $ret); exit;
    }

    if ($akt === 'upload' || $akt === 'append') {
        if (!ki_bereit()) {
            $_SESSION['dim_flash'] = ['fehler', 'Die KI ist nicht eingerichtet (Einstellungen → KI). Der Massen-Import braucht sie.'];
            header('Location: ' . $ret); exit;
        }
        [$angekommen, $dateien] = $dim_dateien_einlesen();
        $maxUp = (int) ini_get('max_file_uploads');
        if (!$dateien) {
            $_SESSION['dim_flash'] = ['fehler', 'Keine gültige Datei angekommen (erlaubt: PDF, JPG, PNG, WEBP).'];
        } else {
            $uid = (int)((current_user()['id'] ?? 0));
            $jobAktiv = dokimport_aktiver_job();
            if ($akt === 'append' && $jobAktiv) dokimport_dateien_hinzufuegen((int)$jobAktiv['id'], $dateien);
            else                                dokimport_job_neu($dateien, $uid ?: null);
            $msg = count($dateien) . ' Datei(en) übernommen. Die KI liest sie jetzt im Hintergrund ein.';
            // Hinweis, wenn der Browser offenbar mehr schicken wollte, als der Server je Upload annimmt.
            if ($maxUp > 0 && $angekommen >= $maxUp) {
                $msg .= ' Hinweis: Der Server nimmt pro Upload höchstens ' . $maxUp . ' Dateien an – lade weitere einfach mit „Weitere Dateien hinzufügen" nach.';
            }
            $_SESSION['dim_flash'] = ['ok', $msg];
        }
        header('Location: ' . $ret); exit;
    }

    if ($akt === 'kick') {
        dokimport_worker_starten((int)($_POST['job_id'] ?? 0));
        $_SESSION['dim_flash'] = ['ok', 'Verarbeitung angestoßen – die Seite aktualisiert sich gleich.'];
        header('Location: ' . $ret); exit;
    }

    if ($akt === 'zuordnen') {
        $ok = dokimport_zuordnen((int)($_POST['datei_id'] ?? 0), (string)($_POST['eingabe'] ?? ''));
        $_SESSION['dim_flash'] = $ok ? ['ok', 'Rohstoff zugeordnet.'] : ['fehler', 'Kein passender Rohstoff zur Eingabe gefunden.'];
        header('Location: ' . $ret); exit;
    }
    if ($akt === 'skip')   { dokimport_ueberspringen((int)($_POST['datei_id'] ?? 0), true);  header('Location: ' . $ret); exit; }
    if ($akt === 'unskip') { dokimport_ueberspringen((int)($_POST['datei_id'] ?? 0), false); header('Location: ' . $ret); exit; }

    if ($akt === 'neu_anlegen') {
        $iid = dokimport_neu_anlegen((int)($_POST['datei_id'] ?? 0));
        $_SESSION['dim_flash'] = $iid ? ['ok', 'Neuer Rohstoff angelegt und zugeordnet.'] : ['fehler', 'Konnte keinen Rohstoff anlegen.'];
        header('Location: ' . $ret); exit;
    }
    if ($akt === 'neu_alle') {
        $n = dokimport_neu_anlegen_alle((int)($_POST['job_id'] ?? 0));
        $_SESSION['dim_flash'] = ['ok', $n . ' neue(r) Rohstoff(e) aus den Zeilen ohne Treffer angelegt und zugeordnet.'];
        header('Location: ' . $ret); exit;
    }

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
$quelleLbl = ['cas' => 'über CAS', 'name' => 'über Name', 'fuzzy' => 'namensähnlich', 'manuell' => 'manuell', 'neu' => 'neu angelegt', '' => 'kein Treffer'];
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
      <div class="muted" style="font-size:12px">
        Das Einlesen läuft im Hintergrund (mehrere Dateien parallel) – du kannst die Seite offen lassen.
        <?php $maxUp = (int) ini_get('max_file_uploads'); if ($maxUp > 0): ?>
          Der Server nimmt pro Upload höchstens <strong><?= $maxUp ?></strong> Dateien an; weitere lädst du danach einfach nach.
        <?php endif; ?>
      </div>
    </form>
  </div>

<?php else:
    $jobId = (int)$job['id'];
    $fort  = dokimport_fortschritt($jobId);
?>

  <?php if ($fort['status'] === 'offen'): ?>
    <?php /* ---------- Zustand 2: Fortschritt ---------- */
      // Auto-Weiterlaufen: reagiert niemand mehr (nichts in Arbeit, aber noch wartend), Worker neu anstossen.
      if ($fort['liest'] === 0 && $fort['offen'] > 0) dokimport_worker_starten($jobId);
    ?>
    <div class="bx-panel">
      <h3 style="margin:0 0 8px;font-weight:600">Die KI liest die Dateien ein …</h3>
      <?php $proz = $fort['anzahl'] > 0 ? round($fort['gelesen'] / $fort['anzahl'] * 100) : 0; ?>
      <div style="background:#eee;border-radius:6px;height:14px;overflow:hidden;max-width:560px">
        <div style="background:var(--gruen,#1D9E75);height:100%;width:<?= $proz ?>%"></div>
      </div>
      <p class="muted" style="margin:8px 0 2px">
        <strong style="font-weight:600"><?= (int)$fort['gelesen'] ?> von <?= (int)$fort['anzahl'] ?></strong> gelesen (<?= $proz ?> %)
        · gerade in Arbeit: <?= (int)$fort['liest'] ?> · wartend: <?= (int)$fort['offen'] ?><?= $fort['fehler'] > 0 ? ' · Fehler: ' . (int)$fort['fehler'] : '' ?>
      </p>
      <p class="muted" style="font-size:12px;margin:0">Läuft im Hintergrund (bis zu <?= DOKIMPORT_WORKER ?> Dateien gleichzeitig). Jede PDF dauert ~1–4 Min – die Seite lädt sich selbst neu.</p>
      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
        <?php if ($fort['liest'] === 0 && $fort['offen'] > 0): ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="kick"><input type="hidden" name="job_id" value="<?= $jobId ?>">
            <button class="btn btn-primary btn-sm" type="submit" data-busy="…">Verarbeitung anstoßen</button></form>
        <?php endif; ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Import wirklich abbrechen? Noch nicht übernommene Dateien werden verworfen.');">
          <input type="hidden" name="aktion" value="abbrechen"><input type="hidden" name="job_id" value="<?= $jobId ?>">
          <button class="btn btn-ghost btn-sm" type="submit">Abbrechen</button>
        </form>
      </div>
    </div>
    <div class="bx-panel">
      <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="append">
        <div class="bx-field" style="margin:0;max-width:420px"><label>Weitere Dateien hinzufügen</label>
          <input type="file" name="dateien[]" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple required></div>
        <button class="btn btn-ghost btn-sm" type="submit" data-busy="lade hoch…">Hinzufügen</button>
      </form>
    </div>
    <script>setTimeout(function(){ location.reload(); }, 5000);</script>

  <?php else:
    /* ---------- Zustand 3: Match-Vorschau ---------- */
    $zeilen = dokimport_zeilen($jobId);
    $importierbar = 0; $ohneTreffer = 0;
    foreach ($zeilen as $z) {
        if ($z['status'] === 'gelesen' && (int)$z['item_id'] > 0) $importierbar++;
        if ($z['status'] === 'gelesen' && (int)$z['item_id'] <= 0) $ohneTreffer++;
    }
  ?>
    <div class="bx-panel" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
      <div class="muted" style="font-size:13px">
        <?= count($zeilen) ?> Datei(en) gelesen · <strong style="font-weight:600"><?= $importierbar ?></strong> bereit zum Übernehmen<?= $ohneTreffer > 0 ? ' · ' . $ohneTreffer . ' ohne Treffer' : '' ?>.
        Prüfe die Zuordnung – ohne Treffer kannst du zuordnen, überspringen oder einen neuen Rohstoff anlegen.
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($ohneTreffer > 0): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('Für alle <?= $ohneTreffer ?> Zeile(n) ohne Treffer je einen neuen Rohstoff aus den KI-Stammdaten anlegen?');">
            <input type="hidden" name="aktion" value="neu_alle"><input type="hidden" name="job_id" value="<?= $jobId ?>">
            <button class="btn btn-ghost" type="submit" data-busy="lege an…">Alle <?= $ohneTreffer ?> ohne Treffer neu anlegen</button>
          </form>
        <?php endif; ?>
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
            <td style="width:280px;max-width:280px">
              <div title="<?= h((string)$z['dateiname']) ?>" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px"><?= h((string)$z['dateiname']) ?></div>
              <div><a href="#" style="font-size:11px" onclick="dimView(<?= (int)$z['id'] ?>, this.getAttribute('data-n')); return false;" data-n="<?= h((string)$z['dateiname']) ?>">Ansehen</a></div>
            </td>
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
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:6px">
                  <form method="post" style="margin:0;display:flex;gap:6px;align-items:center">
                    <input type="hidden" name="aktion" value="zuordnen"><input type="hidden" name="datei_id" value="<?= (int)$z['id'] ?>">
                    <input type="text" name="eingabe" list="roh_dl" placeholder="Rohstoff-Name / R-Nr." style="min-width:170px;font-size:12px">
                    <button class="btn btn-ghost btn-sm" type="submit" data-busy="…"><?= $iid ? 'ändern' : 'zuordnen' ?></button>
                  </form>
                  <?php if (!$iid): ?>
                    <form method="post" style="margin:0" onsubmit="return confirm('Neuen Rohstoff aus den KI-Stammdaten dieser Datei anlegen?');">
                      <input type="hidden" name="aktion" value="neu_anlegen"><input type="hidden" name="datei_id" value="<?= (int)$z['id'] ?>">
                      <button class="btn btn-ghost btn-sm" type="submit" data-busy="…" title="Neuen Rohstoff aus den erkannten Stammdaten anlegen">+ Neuer Rohstoff</button>
                    </form>
                  <?php endif; ?>
                </div>
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

    <div class="bx-panel">
      <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="append">
        <div class="bx-field" style="margin:0;max-width:420px"><label>Weitere Dateien nachladen</label>
          <input type="file" name="dateien[]" accept=".pdf,.jpg,.jpeg,.png,.webp" multiple required></div>
        <button class="btn btn-ghost btn-sm" type="submit" data-busy="lade hoch…">Hinzufügen</button>
        <span class="muted" style="font-size:12px">Die neuen Dateien werden eingelesen; danach landest du wieder hier in der Vorschau.</span>
      </form>
    </div>

    <?php
    // Datalist fuer die manuelle Zuordnung (Autovervollstaendigung ueber Rohstoff-Namen).
    echo '<datalist id="roh_dl">';
    foreach (all("SELECT name FROM item WHERE kategorie='rohstoff' ORDER BY name LIMIT 1500") as $r) echo '<option value="' . h((string)$r['name']) . '">';
    echo '</datalist>';
    ?>

    <!-- Popup-Vorschau des Dokuments -->
    <div id="dimOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;align-items:center;justify-content:center" onclick="if(event.target===this)dimClose()">
      <div style="background:#fff;width:92%;max-width:900px;height:88%;border-radius:8px;display:flex;flex-direction:column;overflow:hidden">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border-bottom:1px solid #e5e5e5">
          <strong id="dimTitle" style="font-weight:600">Vorschau</strong>
          <button type="button" class="btn btn-ghost btn-sm" onclick="dimClose()">Schließen</button>
        </div>
        <iframe id="dimFrame" title="Dokumentvorschau" style="flex:1;border:0;width:100%"></iframe>
      </div>
    </div>
    <script>
      function dimView(id, name){ var o=document.getElementById('dimOverlay');
        document.getElementById('dimTitle').textContent = name || 'Vorschau';
        document.getElementById('dimFrame').src = '?p=dok_massenimport&vorschau=' + id;
        o.style.display = 'flex'; }
      function dimClose(){ document.getElementById('dimOverlay').style.display='none';
        document.getElementById('dimFrame').src = 'about:blank'; }
      document.addEventListener('keydown', function(e){ if(e.key==='Escape') dimClose(); });
    </script>
  <?php endif; ?>

<?php endif; ?>

<?php render_footer(); ?>
