<?php
// Beleg hochladen (nach Login): eine oder mehrere Dateien wählen/fotografieren → speichern → KI liest aus
// → als „neu" im Posteingang. Route: beleg_upload.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/belegeingang.php';
be_init();

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = current_user();
    $n = 0;
    $files = $_FILES['belege'] ?? null;
    if ($files && is_array($files['name'])) {
        $anz = count($files['name']);
        for ($i = 0; $i < $anz; $i++) {
            if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
            $one = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            $gespeichert = be_datei_speichern($one);
            if (!$gespeichert) continue;
            $ki = be_ki_auslesen($gespeichert['pfad']);
            $d = ($ki['ok'] ? $ki['daten'] : []) + [
                'quelle' => 'upload', 'datei' => $gespeichert['datei'], 'orig_name' => $gespeichert['orig'],
                'mime' => $gespeichert['mime'], 'status' => 'neu', 'ki_ok' => $ki['ok'], 'ki_json' => $ki['roh'] ?? null,
                'erfasst_von' => $u['id'] ?? null,
            ];
            be_anlegen($d);
            $n++;
        }
    }
    if ($n === 0) { $fehler = 'Keine gültige Datei hochgeladen (erlaubt: PDF, JPG, PNG, WEBP, HEIC – max. 25 MB).'; }
    else { header('Location: ?p=beleg_eingang&hochgeladen=' . $n); exit; }
}

render_header('beleg_eingang', 'Beleg hochladen');
bx_head('Beleg hochladen', 'PDF oder Foto – die KI liest Lieferant, Datum, Betrag und USt aus',
        bx_btn('Zurück', '?p=beleg_eingang', 'ghost'));
if ($fehler) echo '<div class="bx-panel" style="padding:12px 16px;border-color:#e6c4c0;color:var(--err)">' . h($fehler) . '</div>';
if (!ki_bereit()) echo '<div class="bx-panel" style="padding:12px 16px">Hinweis: Die KI ist nicht eingerichtet – Belege werden gespeichert, die Felder trägst du dann manuell ein.</div>';
?>
<form method="post" enctype="multipart/form-data" class="bx-form bx-panel" style="max-width:620px">
  <div class="bx-field">
    <label>Beleg(e) auswählen oder fotografieren</label>
    <input type="file" name="belege[]" accept="image/*,application/pdf" capture="environment" multiple required>
  </div>
  <p class="muted" style="margin:4px 0 0">Mehrere Belege gleichzeitig möglich. Am Handy öffnet „Auswählen" direkt die Kamera. Erlaubt: PDF, JPG, PNG, WEBP, HEIC (max. 25 MB je Datei).</p>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit" data-busy="Beleg wird gelesen …">Hochladen &amp; auslesen</button>
    <a class="btn btn-ghost" href="?p=beleg_eingang">Abbrechen</a>
  </div>
</form>
<?php
render_footer();
