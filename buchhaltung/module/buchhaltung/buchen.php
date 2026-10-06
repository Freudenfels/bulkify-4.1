<?php
// Kontoauszug buchen: CSV hochladen/einfügen -> Spalten zuordnen -> importieren -> je Zeile einer offenen
// Rechnung zuordnen und buchen (Eingang=Debitoren, Ausgang=Kreditoren, getrennt). Route: buchen.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/bank_import.php';
bank_init();

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$u = function_exists('current_user') ? current_user() : null;
$akteur = $u['name'] ?? 'team';

// Inhalt aus Upload oder Textarea holen + nach UTF-8 wandeln.
function buchen_content(): string {
    $c = '';
    if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $c = (string) file_get_contents($_FILES['csv']['tmp_name']);
    } elseif (isset($_POST['content'])) {
        $c = (string) $_POST['content'];
    }
    if ($c !== '' && !mb_check_encoding($c, 'UTF-8')) {
        $conv = @mb_convert_encoding($c, 'UTF-8', 'Windows-1252');
        if ($conv !== false) $c = $conv;
    }
    return $c;
}

$aktion = $_POST['aktion'] ?? '';

// --- Import ausführen ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'importieren') {
    $content = (string)($_POST['content'] ?? '');
    $map = [
        'datum'  => (int)($_POST['map_datum']  ?? -1),
        'betrag' => (int)($_POST['map_betrag'] ?? -1),
        'zweck'  => (int)($_POST['map_zweck']  ?? -1),
        'name'   => (int)($_POST['map_name']   ?? -1),
        'iban'   => (int)($_POST['map_iban']   ?? -1),
    ];
    $hatKopf = !empty($_POST['hatkopf']);
    $delim = (string)($_POST['delim'] ?? '');
    $posten = bank_parse($content, $map, $hatKopf, $delim);
    if (!$posten) { header('Location: ?p=buchen&fehler=leer'); exit; }
    $res = bank_import_speichern($posten, $akteur);
    header('Location: ?p=buchen&batch=' . $res['batch'] . '&imp=' . $res['anzahl']); exit;
}

// --- Buchen / Ignorieren eines Batches (ein Richtungs-Formular) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'buchen_batch') {
    $batch = preg_replace('/[^0-9]/', '', (string)($_POST['batch'] ?? ''));
    $ignore = array_map('intval', $_POST['ignore'] ?? []);
    $gebucht = 0;
    foreach (($_POST['ziel'] ?? []) as $zid => $ziel) {
        $zid = (int)$zid; $ziel = (int)$ziel;
        if (in_array($zid, $ignore, true)) { bank_ignorieren($zid, true); continue; }
        if ($ziel <= 0) continue;
        $z = bank_zeile($zid);
        if (!$z) continue;
        if ($z['richtung'] === 'eingang') { if (bank_buchen_eingang($zid, $ziel, $akteur)) $gebucht++; }
        else                              { if (bank_buchen_ausgang($zid, $ziel, $akteur)) $gebucht++; }
    }
    // zusätzlich reine Ignorieren-Markierungen (ohne ziel-Eintrag)
    foreach ($ignore as $zid) bank_ignorieren($zid, true);
    header('Location: ?p=buchen&batch=' . $batch . '&tab=' . ($_POST['tab'] ?? 'eingang') . '&gebucht=' . $gebucht); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'batch_loeschen') {
    $batch = preg_replace('/[^0-9]/', '', (string)($_POST['batch'] ?? ''));
    bank_batch_loeschen($batch);
    header('Location: ?p=buchen&geloescht=1'); exit;
}

$batch = preg_replace('/[^0-9]/', '', (string)($_GET['batch'] ?? ''));

// ===========================================================================
// Ansicht 1: Batch vorhanden -> Zuordnen/Buchen (Eingang/Ausgang)
// ===========================================================================
if ($batch) {
    $tab = ($_GET['tab'] ?? 'eingang') === 'ausgang' ? 'ausgang' : 'eingang';
    $zeilen = bank_zeilen($batch, $tab);
    $deb = bank_open_debitoren();
    $kred = bank_open_kreditoren();
    $offenDeb = count(bank_zeilen($batch, 'eingang'));
    $offenAus = count(bank_zeilen($batch, 'ausgang'));

    render_header('buchen', 'Kontoauszug buchen');
    bx_head('Kontoauszug buchen · Import ' . h(date('d.m.Y H:i', strtotime(substr($batch,0,4).'-'.substr($batch,4,2).'-'.substr($batch,6,2).' '.substr($batch,8,2).':'.substr($batch,10,2)))),
            $offenDeb . ' Eingänge · ' . $offenAus . ' Ausgänge', bx_btn('Neuer Import', '?p=buchen', 'ghost'));
    if (isset($_GET['imp'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['imp'] . ' Buchungen eingelesen. Ordne sie unten den offenen Rechnungen zu.</div>';
    if (isset($_GET['gebucht'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['gebucht'] . ' Zahlung(en) gebucht.</div>';
    bx_tabs(['eingang' => 'Eingang (Kundenzahlungen) · ' . $offenDeb, 'ausgang' => 'Ausgang (an Lieferanten) · ' . $offenAus], $tab, '?p=buchen&batch=' . $batch);

    $liste = $tab === 'eingang' ? $deb : $kred;
    $nummerfelder = $tab === 'eingang' ? ['nummer'] : ['nummer', 'lief_nummer'];
    $namefeld = $tab === 'eingang' ? 'kunde_firma' : 'firma';
    // Options-HTML einmal bauen (eine Zeile -> best vorselektiert)
    $optionen = function(int $best) use ($liste, $tab, $eur) {
        $h = '<option value="0">— nicht buchen —</option>';
        foreach ($liste as $r) {
            $lbl = $r['nummer'] . ' · ' . ($tab === 'eingang' ? ($r['kunde_firma'] ?: '–') : ($r['firma'] ?: '–'))
                 . ' · offen ' . number_format((float)$r['rest'], 2, ',', '.') . ' €';
            $h .= '<option value="' . (int)$r['id'] . '"' . ((int)$r['id'] === $best ? ' selected' : '') . '>' . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        return $h;
    };
    ?>
    <form method="post">
      <input type="hidden" name="aktion" value="buchen_batch">
      <input type="hidden" name="batch" value="<?= h($batch) ?>">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Datum</th><th class="bx-num">Betrag</th><th>Verwendungszweck / Gegenseite</th><th>Zuordnen zu</th><th>Ignorieren</th></tr></thead>
        <tbody>
        <?php
        $offenRows = array_values(array_filter($zeilen, fn($z) => $z['status'] === 'offen'));
        if (!$offenRows): ?><tr><td colspan="5" class="muted">Keine offenen <?= $tab==='eingang'?'Eingänge':'Ausgänge' ?> in diesem Import.</td></tr><?php endif;
        foreach ($offenRows as $z):
            $m = bank_match(['zweck'=>$z['verwendungszweck'],'name'=>$z['gegenname'],'betrag'=>$z['betrag']], $liste, $nummerfelder, $namefeld);
        ?>
          <tr>
            <td><?= $z['datum'] ? h(date('d.m.Y', strtotime((string)$z['datum']))) : '<span class="muted">–</span>' ?></td>
            <td class="bx-num"><strong><?= $eur($z['betrag']) ?></strong></td>
            <td><?= h((string)$z['verwendungszweck']) ?><?php if ($z['gegenname']): ?><div class="muted" style="font-size:12px"><?= h((string)$z['gegenname']) ?></div><?php endif; ?></td>
            <td><select name="ziel[<?= (int)$z['id'] ?>]" class="rscombo" style="min-width:280px"><?= $optionen((int)$m['best']) ?></select><?= $m['best'] ? ' <span class="muted" style="font-size:12px">Vorschlag</span>' : '' ?></td>
            <td style="text-align:center"><input type="checkbox" name="ignore[]" value="<?= (int)$z['id'] ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if ($offenRows): ?>
      <div class="bx-row" style="margin-top:12px">
        <button class="btn btn-primary" type="submit">Zuordnen &amp; buchen</button>
        <span class="muted" style="align-self:center;font-size:12px">Nur Zeilen mit ausgewählter Rechnung werden gebucht. Angehakte werden ignoriert.</span>
      </div>
      <?php endif; ?>
    </form>

    <?php
    // Bereits gebuchte/ignorierte dieses Richtungs-Tabs anzeigen
    $erledigt = array_values(array_filter($zeilen, fn($z) => $z['status'] !== 'offen'));
    if ($erledigt): ?>
    <div class="bx-panel">
      <h2>Erledigt</h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Datum</th><th class="bx-num">Betrag</th><th>Verwendungszweck</th><th>Status</th><th>Rechnung</th></tr></thead>
        <tbody>
        <?php foreach ($erledigt as $z): ?>
          <tr>
            <td><?= $z['datum'] ? h(date('d.m.Y', strtotime((string)$z['datum']))) : '' ?></td>
            <td class="bx-num"><?= $eur($z['betrag']) ?></td>
            <td><?= h((string)$z['verwendungszweck']) ?></td>
            <td><?= $z['status'] === 'gebucht' ? bx_badge('gebucht','ok') : bx_badge('ignoriert') ?></td>
            <td><?php if ($z['status']==='gebucht' && $z['richtung']==='eingang' && $z['beleg_id']): ?><a href="?p=rechnung&id=<?= (int)$z['beleg_id'] ?>">öffnen</a><?php elseif ($z['status']==='gebucht' && $z['lief_rechnung_id']): ?><a href="?p=lief_rechnung&id=<?= (int)$z['lief_rechnung_id'] ?>">öffnen</a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif;
    render_footer(); exit;
}

// ===========================================================================
// Ansicht 2a: Vorschau + Spaltenzuordnung
// ===========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aktion === 'vorschau') {
    $content = buchen_content();
    if (trim($content) === '') { header('Location: ?p=buchen&fehler=leer'); exit; }
    $delim = bank_delimiter($content);
    $roh = bank_rohzeilen($content, $delim);
    $kopf = $roh[0] ?? [];
    $map = bank_spalten_erkennen($kopf);
    $spaltenAnzahl = 0; foreach ($roh as $r) $spaltenAnzahl = max($spaltenAnzahl, count($r));
    $vorschau = array_slice($roh, 0, 8);

    render_header('buchen', 'Kontoauszug buchen');
    bx_head('Spalten zuordnen', 'Prüfe die erkannten Spalten und starte den Import', bx_btn('Abbrechen', '?p=buchen', 'ghost'));
    ?>
    <form method="post" class="bx-panel">
      <input type="hidden" name="aktion" value="importieren">
      <input type="hidden" name="content" value="<?= h($content) ?>">
      <input type="hidden" name="delim" value="<?= h($delim) ?>">
      <label class="bx-check" style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><input type="checkbox" name="hatkopf" value="1" checked> Erste Zeile ist eine Überschrift</label>
      <div class="bx-grid">
        <?php
        $feldLabel = ['datum'=>'Datum','betrag'=>'Betrag (mit Vorzeichen)','zweck'=>'Verwendungszweck','name'=>'Name Gegenseite','iban'=>'IBAN/Konto (optional)'];
        foreach ($feldLabel as $feld => $lbl):
        ?>
        <div class="bx-field"><label><?= h($lbl) ?></label>
          <select name="map_<?= $feld ?>">
            <option value="-1">– keine –</option>
            <?php for ($i=0; $i<$spaltenAnzahl; $i++): $hd = trim((string)($kopf[$i] ?? '')); ?>
              <option value="<?= $i ?>" <?= (int)$map[$feld]===$i?'selected':'' ?>>Spalte <?= $i+1 ?><?= $hd!==''?' ('.h(mb_substr($hd,0,24)).')':'' ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <?php endforeach; ?>
      </div>
      <h3 style="margin:16px 0 6px">Vorschau</h3>
      <div class="bx-tablewrap"><table class="bx-table" style="font-size:12px">
        <tbody>
          <?php foreach ($vorschau as $ri => $r): ?>
          <tr<?= $ri===0?' style="font-weight:600"':'' ?>>
            <?php for ($i=0; $i<$spaltenAnzahl; $i++): ?><td><?= h(mb_substr((string)($r[$i] ?? ''), 0, 40)) ?></td><?php endfor; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="bx-row" style="margin-top:14px"><button class="btn btn-primary" type="submit">Importieren</button></div>
      <p class="muted" style="margin:8px 0 0;font-size:12px">Positive Beträge werden als Eingang (Kundenzahlung), negative als Ausgang (an Lieferanten) eingeordnet.</p>
    </form>
    <?php
    render_footer(); exit;
}

// ===========================================================================
// Ansicht 2b: Startseite (Upload/Einfügen) + bisherige Importe
// ===========================================================================
$batches = bank_batches();
render_header('buchen', 'Kontoauszug buchen');
bx_head('Kontoauszug buchen', 'CSV der Bank hochladen oder einfügen, dann Zahlungen den offenen Rechnungen zuordnen');
if (($_GET['fehler'] ?? '') === 'leer') echo '<div class="bx-panel" style="padding:12px 16px;border-color:#e6c4c0">Keine verwertbaren Zeilen gefunden (Betragsspalte prüfen).</div>';
if (isset($_GET['geloescht'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Nicht gebuchte Posten des Imports entfernt.</div>';
?>
<div class="bx-panel" style="max-width:820px">
  <h2 style="margin-top:0">Neuer Import</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="aktion" value="vorschau">
    <div class="bx-field"><label>CSV-Datei</label><input type="file" name="csv" accept=".csv,text/csv,text/plain"></div>
    <p class="muted" style="margin:6px 0">oder Zeilen direkt einfügen:</p>
    <div class="bx-field"><textarea name="content" rows="6" style="width:100%;box-sizing:border-box;font-family:monospace;font-size:12px" placeholder="Datum;Betrag;Verwendungszweck;Name&#10;05.10.2026;1190,00;RE-2703 Zahlung;Muster GmbH"></textarea></div>
    <div class="bx-row" style="margin-top:12px"><button class="btn btn-primary" type="submit">Weiter zur Zuordnung</button></div>
    <p class="muted" style="margin:8px 0 0;font-size:12px">Unterstützt Semikolon-, Komma- und Tab-getrennte CSV. Spalten werden erkannt und können im nächsten Schritt angepasst werden.</p>
  </form>
</div>

<div class="bx-panel">
  <h2>Bisherige Importe</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Import</th><th class="bx-num">Zeilen</th><th class="bx-num">Offen</th><th class="bx-num">Gebucht</th><th class="bx-num">Ignoriert</th><th></th></tr></thead>
    <tbody>
      <?php if (!$batches): ?><tr><td colspan="6" class="muted">Noch keine Importe.</td></tr><?php endif; ?>
      <?php foreach ($batches as $b): ?>
        <tr style="cursor:pointer" onclick="location.href='?p=buchen&batch=<?= h($b['batch']) ?>'">
          <td><?= h(date('d.m.Y H:i', strtotime((string)$b['angelegt']))) ?></td>
          <td class="bx-num"><?= (int)$b['anz'] ?></td>
          <td class="bx-num"><?= (int)$b['offen'] ?></td>
          <td class="bx-num"><?= (int)$b['gebucht'] ?></td>
          <td class="bx-num"><?= (int)$b['ignoriert'] ?></td>
          <td><form method="post" style="margin:0" onsubmit="return confirm('Nicht gebuchte Posten dieses Imports entfernen?');"><input type="hidden" name="aktion" value="batch_loeschen"><input type="hidden" name="batch" value="<?= h($b['batch']) ?>"><button class="btn btn-ghost btn-sm" type="submit" onclick="event.stopPropagation()">Entfernen</button></form></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php
render_footer();
