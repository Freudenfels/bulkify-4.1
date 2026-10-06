<?php
// Lager-2 Anfangsbestände: Produkte eines Fulfillment-Kunden aus der AUFTRAGSHISTORIE rauslesen und die
// aktuellen Bestände in EINEM Rutsch ins Fremdlager (Lager 2) buchen. Drei Schritte:
//   1) Kunde wählen + Mengen erfassen  ->  2) Vorschau (bestätigen)  ->  3) eintragen (bucht Chargen + BSKU).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$ffKunden = all("SELECT id, firma FROM kunden WHERE nutzt_fulfillment=1 ORDER BY firma");
$kid = (int)($_GET['kunde'] ?? $_POST['kunde'] ?? 0);
$kundeRow = $kid ? one("SELECT id, firma, COALESCE(nutzt_fulfillment,0) AS ff FROM kunden WHERE id=?", [$kid]) : null;

// Produkte aus der Auftragshistorie des Kunden (distinct) + aktueller Lager-2-Bestand (read-only, legt NICHTS an).
function lg2imp_produkte(int $kid): array {
    if ($kid <= 0) return [];
    $rows = all("SELECT DISTINCT p.id, COALESCE(NULLIF(p.kundenname,''),p.name) AS name, p.nummer,
                        COALESCE(p.kunde_id,0) AS kunde_id, r.nummer AS rez_nr, r.name AS rez_name
                 FROM auftrag a JOIN produkt p ON p.id=a.produkt_id
                 LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                 WHERE a.kunde_id=? AND a.produkt_id IS NOT NULL
                 ORDER BY name", [$kid]);
    foreach ($rows as &$r) {
        $iid = (int) scalar("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig' LIMIT 1", [(int)$r['id']]);
        $r['item_id'] = $iid;
        $r['bestand'] = $iid ? lager2_bestand($iid) : 0.0;
        $r['hat_bsku'] = $iid ? (trim((string) scalar("SELECT bsku FROM item WHERE id=?", [$iid])) !== '') : false;
    }
    unset($r);
    return $rows;
}

// --- Eintragen (nach Bestätigung) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'eintragen' && $kundeRow && (int)$kundeRow['ff'] === 1) {
    $pids   = array_map('intval', (array)($_POST['pid'] ?? []));
    $mengen = (array)($_POST['menge'] ?? []);
    $chargen= (array)($_POST['charge'] ?? []);
    $mhds   = (array)($_POST['mhd'] ?? []);
    $n = 0;
    foreach ($pids as $i => $pid) {
        $menge = (float) str_replace(',', '.', (string)($mengen[$i] ?? '0'));
        if ($pid <= 0 || $menge <= 0) continue;
        // gehört das Produkt zum Kunden? (Historie ist nach kunde_id=kid gefiltert) – Kunde setzen, falls leer.
        if (!scalar("SELECT 1 FROM auftrag WHERE produkt_id=? AND kunde_id=? LIMIT 1", [$pid, $kid])) continue;
        if ((int) scalar("SELECT COALESCE(kunde_id,0) FROM produkt WHERE id=?", [$pid]) === 0)
            q("UPDATE produkt SET kunde_id=? WHERE id=?", [$kid, $pid]);
        $charge = trim((string)($chargen[$i] ?? '')) ?: null;
        $mhd    = trim((string)($mhds[$i] ?? '')) ?: null;
        if (lager2_einbuchen($pid, $menge, $charge, $mhd, 'Anfangsbestand-Import (Lager 2)')) $n++;
    }
    header('Location: ?p=lager2_import&kunde=' . $kid . '&ok=' . $n); exit;
}

render_header('lager2_import', 'Lager-2 Bestände importieren');
bx_head('Lager-2 Bestände importieren', 'Produkte eines Fulfillment-Kunden aus der Auftragshistorie – Bestände in einem Rutsch ins Fremdlager buchen',
        bx_btn('Zum Fremdlager', '?p=lager2', 'ghost'));

if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['ok'] . ' Produkt(e) ins Lager 2 eingebucht.</div>';

if (!$ffKunden) {
    echo '<div class="bx-panel"><div class="muted">Kein Fulfillment-Kunde vorhanden. Im <a href="?p=kunden">Kunden</a>-Datensatz „Nutzt unser Fulfillment (Fremdlager)" setzen.</div></div>';
    render_footer(); return;
}

// --- Kundenauswahl ---
?>
<form method="get" class="bx-panel" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap">
  <input type="hidden" name="p" value="lager2_import">
  <div class="bx-field" style="margin:0;min-width:260px"><label>Kunde (Fulfillment)</label>
    <select name="kunde" onchange="this.form.submit()">
      <option value="">– wählen –</option>
      <?php foreach ($ffKunden as $k): ?><option value="<?= (int)$k['id'] ?>" <?= $kid === (int)$k['id'] ? 'selected' : '' ?>><?= h($k['firma']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <noscript><button class="btn btn-primary" type="submit">Laden</button></noscript>
</form>

<?php
if (!$kundeRow) { render_footer(); return; }

$produkte = lg2imp_produkte($kid);

// --- Schritt 2: Vorschau (Bestätigen) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'vorschau') {
    $pids    = array_map('intval', (array)($_POST['pid'] ?? []));
    $mengenI = (array)($_POST['menge'] ?? []);
    $chargenI= (array)($_POST['charge'] ?? []);
    $mhdsI   = (array)($_POST['mhd'] ?? []);
    // nur Zeilen mit Menge > 0, Produktdaten aus der geladenen Liste anreichern
    $byId = []; foreach ($produkte as $p) $byId[(int)$p['id']] = $p;
    $zeilen = [];
    foreach ($pids as $i => $pid) {
        $menge = (float) str_replace(',', '.', (string)($mengenI[$i] ?? '0'));
        if ($pid <= 0 || $menge <= 0 || !isset($byId[$pid])) continue;
        $p = $byId[$pid];
        $zeilen[] = ['pid'=>$pid, 'name'=>$p['name'], 'nummer'=>$p['nummer'], 'rez'=>trim(($p['rez_nr'] ?? '') . ' ' . ($p['rez_name'] ?? '')),
                     'menge'=>$menge, 'charge'=>trim((string)($chargenI[$i] ?? '')), 'mhd'=>trim((string)($mhdsI[$i] ?? '')),
                     'neu_bsku'=>!$p['hat_bsku'], 'kunde_setzen'=>((int)$p['kunde_id'] === 0)];
    }
    if (!$zeilen) { echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Keine Menge eingetragen – bitte mindestens ein Produkt mit Menge erfassen.</div>'; }
    else {
        $nf = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
        ?>
  <div class="bx-panel" style="border-left:3px solid var(--warn)">
    <h2 style="margin-top:0">Bitte bestätigen – das wird eingebucht</h2>
    <p class="muted" style="margin-top:0">Kunde <strong><?= h($kundeRow['firma']) ?></strong> · <?= count($zeilen) ?> Produkt(e). Erst nach „Jetzt eintragen" werden die Bestände (Chargen) ins Lager 2 gebucht.</p>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Produkt</th><th>Rezeptur</th><th class="bx-num">Menge</th><th>Charge</th><th>MHD</th><th>Hinweis</th></tr></thead>
      <tbody>
      <?php foreach ($zeilen as $z): ?>
        <tr>
          <td><?= h($z['name']) ?><div class="muted" style="font-size:11px"><?= h((string)$z['nummer']) ?></div></td>
          <td><?= $z['rez'] !== '' ? h($z['rez']) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><strong><?= $nf($z['menge']) ?></strong> Stück</td>
          <td><?= $z['charge'] !== '' ? h($z['charge']) : '<span class="muted">– auto –</span>' ?></td>
          <td><?= $z['mhd'] !== '' ? h($z['mhd']) : '<span class="muted">–</span>' ?></td>
          <td style="font-size:12px"><?= $z['neu_bsku'] ? '<span class="bx-ok">neue BSKU</span>' : '' ?><?= $z['kunde_setzen'] ? ($z['neu_bsku'] ? ' · ' : '') . 'Kunde wird zugeordnet' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <form method="post" style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
      <input type="hidden" name="aktion" value="eintragen">
      <input type="hidden" name="kunde" value="<?= $kid ?>">
      <?php foreach ($zeilen as $z): ?>
        <input type="hidden" name="pid[]" value="<?= (int)$z['pid'] ?>">
        <input type="hidden" name="menge[]" value="<?= h((string)$z['menge']) ?>">
        <input type="hidden" name="charge[]" value="<?= h($z['charge']) ?>">
        <input type="hidden" name="mhd[]" value="<?= h($z['mhd']) ?>">
      <?php endforeach; ?>
      <button class="btn btn-primary" type="submit">Jetzt eintragen (<?= count($zeilen) ?>)</button>
      <a class="btn btn-ghost" href="?p=lager2_import&kunde=<?= $kid ?>">Zurück / ändern</a>
    </form>
  </div>
<?php
    }
    render_footer(); return;
}

// --- Schritt 1: Erfassen ---
if (!$produkte) {
    echo '<div class="bx-panel"><div class="muted">Für <strong>' . h($kundeRow['firma']) . '</strong> wurden keine Produkte in der Auftragshistorie gefunden.</div></div>';
    render_footer(); return;
}
$nf = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
?>
<form method="post">
  <input type="hidden" name="aktion" value="vorschau">
  <input type="hidden" name="kunde" value="<?= $kid ?>">
  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
      <h2 style="margin:0">Produkte von <?= h($kundeRow['firma']) ?> <span class="muted" style="font-weight:normal">· aus der Auftragshistorie (<?= count($produkte) ?>)</span></h2>
    </div>
    <p class="muted" style="margin:8px 0 12px;font-size:13px">Trage je Produkt die aktuell im Lager liegende Menge ein (Charge/MHD optional). Leer = wird übersprungen. Danach gibt es eine Vorschau zum Bestätigen – erst dann wird gebucht.</p>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Produkt</th><th>Rezeptur</th><th class="bx-num">aktuell Lager 2</th><th>Menge einbuchen</th><th>Charge (optional)</th><th>MHD (optional)</th></tr></thead>
      <tbody>
      <?php foreach ($produkte as $p): ?>
        <tr>
          <td><?= h($p['name']) ?><div class="muted" style="font-size:11px"><?= h((string)$p['nummer']) ?><?= (int)$p['kunde_id'] === 0 ? ' · <span style="color:var(--warn)">Kunde wird zugeordnet</span>' : '' ?></div>
            <input type="hidden" name="pid[]" value="<?= (int)$p['id'] ?>"></td>
          <td style="font-size:12px"><?= trim(($p['rez_nr'] ?? '') . ' ' . ($p['rez_name'] ?? '')) !== '' ? h(trim(($p['rez_nr'] ?? '') . ' ' . ($p['rez_name'] ?? ''))) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= $p['bestand'] > 0 ? '<strong>' . $nf($p['bestand']) . '</strong> Stück' : '<span class="muted">0</span>' ?></td>
          <td><input type="number" step="1" min="0" name="menge[]" style="max-width:120px" placeholder="z. B. 500"></td>
          <td><input type="text" name="charge[]" style="max-width:150px" placeholder="optional"></td>
          <td><input type="date" name="mhd[]"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Weiter zur Vorschau</button></div>
  </div>
</form>
<?php render_footer();
