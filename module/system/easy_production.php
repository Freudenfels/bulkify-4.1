<?php
// Easy Production (Teil 3 Block J) – schnelle Erfassung einer real erfolgten Produktion.
// Beim Buchen wird Material abgebucht (Charge-Reduktion FEFO + produktion_verbrauch), die Fertigware als
// Charge eingebucht (Kategorie 'verkaufsfertig' = Fertigprodukte) und – falls „schon geliefert" – gleich wieder
// per Lieferschein ausgebucht. Digitalisiert das Papier-Produktionsbuch und erzeugt korrekten Bestand.
// Reihenfolge beim Buchen: Material raus -> Fertig rein -> (falls geliefert) Lieferschein raus.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// --- Selbst-Schema (best-effort, additiv): ein nachvollziehbarer Datensatz je Erfassung ---
try {
    db()->exec("CREATE TABLE IF NOT EXISTS easy_production (
        id INT AUTO_INCREMENT PRIMARY KEY,
        auftrag_id INT NOT NULL,
        pa_id INT NULL,
        menge_produziert INT NOT NULL DEFAULT 0,
        kundenmenge INT NOT NULL DEFAULT 0,
        rueckstell INT NOT NULL DEFAULT 0,
        prod_datum DATE NULL,
        charge_nr VARCHAR(40) NULL,
        mhd DATE NULL,
        gefaess VARCHAR(40) NULL,
        mitarbeiter_id INT NULL,
        mitarbeiter_name VARCHAR(120) NULL,
        geliefert TINYINT(1) NOT NULL DEFAULT 0,
        besonderheiten TEXT NULL,
        buchungen TEXT NULL,
        angelegt DATETIME NOT NULL,
        angelegt_von VARCHAR(120) NULL,
        KEY idx_auftrag (auftrag_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) { /* best-effort, siehe CLAUDE.md */ }

// Zahl mit Tausenderpunkt -> int (leer -> 0; MySQL-Strict: nie "" in Zahl-Spalten).
$pint = fn($s) => (int) preg_replace('/\D/', '', (string)$s);
// Datum validieren (Y-m-d) oder null.
$pdatum = fn($s) => (is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($s))) ? trim($s) : null;

$GEFAESSE = ['glas'=>'Glas', 'tuete'=>'Tüte', 'beutel'=>'Beutel', 'dose'=>'Dose', 'blister'=>'Blister', 'sonstiges'=>'Sonstiges'];

// Material raus: einen Lagerartikel FEFO um $menge reduzieren + je Teil-Entnahme produktion_verbrauch schreiben.
// Bucht höchstens, was da ist (Altbestand unvollständig -> nur warnen, nicht blockieren). Rückgabe: genommen/fehlt.
function ep_material_abbuchen(int $pa_id, int $item_id, float $menge, string $einheit): array {
    if ($item_id <= 0 || $menge <= 1e-9) return ['genommen'=>0.0, 'fehlt'=>0.0];
    $rest = $menge;
    foreach (all("SELECT id, menge_verfuegbar FROM charge WHERE item_id=? AND status='frei' AND menge_verfuegbar>0 AND fremd_kunde_id IS NULL ORDER BY (mhd IS NULL), mhd ASC, id ASC", [$item_id]) as $c) {
        if ($rest <= 1e-9) break;
        $nimm = min($rest, (float)$c['menge_verfuegbar']);
        $neu  = (float)$c['menge_verfuegbar'] - $nimm;
        q("UPDATE charge SET menge_verfuegbar=?, status=? WHERE id=?", [$neu, $neu <= 1e-9 ? 'leer' : 'frei', (int)$c['id']]);
        q("INSERT INTO produktion_verbrauch (pa_id,item_id,charge_id,menge,einheit,angelegt) VALUES (?,?,?,?,?,?)",
          [$pa_id, $item_id, (int)$c['id'], $nimm, $einheit, gmdate('Y-m-d H:i:s')]);
        $rest -= $nimm;
    }
    return ['genommen'=>$menge - $rest, 'fehlt'=>max(0.0, $rest)];
}

// Stückliste eines Auftrags skaliert auf die (gesamt) produzierte Menge. Nutzt den vorhandenen PA + auftrag_bedarf();
// skaliert die benötigten Mengen von der PA-Menge auf die tatsächlich produzierte Menge. Rückgabe: [pa_id, rows[]].
function ep_stueckliste(int $auftrag_id, int $menge_produziert): array {
    $pa = (int) scalar("SELECT id FROM produktionsauftrag WHERE auftrag_id=? AND status<>'storniert' ORDER BY id DESC LIMIT 1", [$auftrag_id]);
    if (!$pa) $pa = (int) (produktionsauftrag_aus_auftrag($auftrag_id) ?? 0);   // PA-Gerüst (kein Bestand bewegt)
    if (!$pa) return ['pa_id'=>0, 'rows'=>[]];
    $paMenge = (int) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa]);
    $factor  = $paMenge > 0 ? ($menge_produziert / $paMenge) : 1.0;
    $rows = [];
    foreach (auftrag_bedarf($pa) as $r) {
        $iid = (int)($r['item_id'] ?? 0);
        if ($iid <= 0) continue;
        $einh = (string)($r['einheit'] ?? 'Stk');
        $need = (float)$r['benoetigt'] * $factor;
        $need = $einh === 'Stk' ? round($need) : round($need, 3);
        $rows[] = ['item_id'=>$iid, 'name'=>(string)$r['name'], 'rolle'=>(string)($r['rolle'] ?? ''),
                   'einheit'=>$einh, 'benoetigt'=>$need, 'verfuegbar'=>(float)($r['verfuegbar'] ?? 0)];
    }
    return ['pa_id'=>$pa, 'rows'=>$rows];
}

$fehler = ''; $flash = ''; $vorschau = null;

// ---------------------------------------------------------------- BUCHEN ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    $auftragId = (int)($_POST['auftrag_id'] ?? 0);
    $a = $auftragId ? one("SELECT * FROM auftrag WHERE id=?", [$auftragId]) : null;
    $produziert = $pint($_POST['menge_produziert'] ?? 0);
    $rueckstell = $pint($_POST['rueckstell'] ?? 0);
    $kundenmenge = max(0, $produziert - $rueckstell);
    $datum   = $pdatum($_POST['prod_datum'] ?? null) ?? gmdate('Y-m-d');
    $chargeNr= trim((string)($_POST['charge_nr'] ?? ''));
    $mhd     = $pdatum($_POST['mhd'] ?? null);
    $gefaess = (string)($_POST['gefaess'] ?? '');
    $maId    = (int)($_POST['mitarbeiter_id'] ?? 0);
    $maName  = (string) scalar("SELECT name FROM benutzer WHERE id=?", [$maId]);
    $geliefert = !empty($_POST['geliefert']);
    $bes     = trim((string)($_POST['besonderheiten'] ?? ''));
    $paId    = (int)($_POST['pa_id'] ?? 0);

    if (!$a)                 $fehler = 'Bitte einen gültigen Auftrag wählen.';
    elseif ($produziert <= 0) $fehler = 'Bitte die produzierte Menge angeben.';
    elseif ($chargeNr === '') $fehler = 'Bitte eine Chargennummer angeben.';
    elseif (!$mhd)           $fehler = 'Bitte ein MHD angeben.';
    elseif ($maId <= 0)      $fehler = 'Bitte den Mitarbeiter wählen.';

    if ($fehler === '') {
        // PA sicherstellen (Gerüst, falls noch keiner) – Charge/Verbrauch hängen daran.
        if ($paId <= 0) { $st = ep_stueckliste($auftragId, $produziert); $paId = (int)$st['pa_id']; }
        $produktId = (int)$a['produkt_id'];
        $kundeId   = (int)($a['kunde_id'] ?? 0);
        $protokoll = [];   // menschenlesbare Zusammenfassung der Buchungen

        // 1) MATERIAL RAUS – aus den (editierbaren) Vorschau-Zeilen.
        $items    = (array)($_POST['mat_item'] ?? []);
        $mengen   = (array)($_POST['mat_menge'] ?? []);
        $einh     = (array)($_POST['mat_einheit'] ?? []);
        $namen    = (array)($_POST['mat_name'] ?? []);
        $fehlteil = [];
        foreach ($items as $k => $iid) {
            $iid = (int)$iid; if ($iid <= 0) continue;
            $m = (float) str_replace(['.', ','], ['', '.'], (string)($mengen[$k] ?? '0'));
            if ($m <= 1e-9) continue;
            $e = (string)($einh[$k] ?? 'Stk');
            $r = ep_material_abbuchen($paId, $iid, $m, $e);
            $nm = (string)($namen[$k] ?? ('Artikel ' . $iid));
            $protokoll[] = 'Material: ' . $nm . ' -' . rtrim(rtrim(number_format($r['genommen'], 3, ',', '.'), '0'), ',') . ' ' . $e
                         . ($r['fehlt'] > 1e-6 ? ' (Fehlbestand ' . rtrim(rtrim(number_format($r['fehlt'], 3, ',', '.'), '0'), ',') . ' ' . $e . ' – nicht gebucht)' : '');
            if ($r['fehlt'] > 1e-6) $fehlteil[] = $nm;
        }

        // 2) FERTIG REIN – Kundenmenge als verkaufsfertige Charge (= Fertigprodukte); Rückstellmuster separat gesperrt.
        $kundenChargeId = 0;
        if ($produktId > 0 && ($vf = produkt_lageritem($produktId))) {
            $notiz = 'Easy Production ' . $datum . ($bes !== '' ? ' – ' . mb_substr($bes, 0, 180) : '');
            if ($kundenmenge > 0) {
                q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,notiz,pa_id,angelegt)
                   VALUES (?,?,?,?, 'Stk', ?, ?, 'frei', ?, ?, ?)",
                  [$chargeNr, $vf, $kundenmenge, $kundenmenge, $mhd, $datum, $notiz, $paId, gmdate('Y-m-d H:i:s')]);
                $kundenChargeId = (int) insert_id();
                $protokoll[] = 'Fertigware (Fertigprodukte): +' . number_format($kundenmenge, 0, ',', '.') . ' Stk, Charge ' . $chargeNr . ', MHD ' . $mhd;
            }
            if ($rueckstell > 0) {
                q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,notiz,pa_id,angelegt)
                   VALUES (?,?,?,?, 'Stk', ?, ?, 'gesperrt', ?, ?, ?)",
                  [$chargeNr . '-RM', $vf, $rueckstell, $rueckstell, $mhd, $datum, 'Rückstellmuster – nicht verkäuflich', $paId, gmdate('Y-m-d H:i:s')]);
                $protokoll[] = 'Rückstellmuster (gesperrt): +' . number_format($rueckstell, 0, ',', '.') . ' Stk';
            }
        } else {
            $protokoll[] = 'Hinweis: Kein Produkt/Lagerartikel am Auftrag – keine Fertigware eingebucht.';
        }

        // 3) LIEFERSCHEIN RAUS – falls schon geliefert: die Kundenmenge sofort wieder ausbuchen (kein Phantombestand).
        if ($geliefert && $kundenChargeId > 0) {
            q("UPDATE charge SET menge_verfuegbar=0, status='leer' WHERE id=?", [$kundenChargeId]);
            $gesamt = (int)$a['menge'];
            $netto  = $gesamt > 0 ? round((float)$a['gesamt_netto'] * $kundenmenge / $gesamt, 2) : (float)$a['gesamt_netto'];
            q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,menge,netto,ust_prozent,ust_betrag,brutto,status,datum)
               VALUES (?,'lieferschein',?,?,?,?,0,0,?,'offen',?)",
              [naechste_nummer('LS'), $auftragId, ($kundeId ?: null), $kundenmenge, $netto, $netto, $datum]);
            $protokoll[] = 'Schon geliefert: Kundenmenge per Lieferschein wieder ausgebucht.';
        }

        // Nachvollziehbarer Datensatz + Verlaufseintrag am Auftrag.
        $cu = function_exists('current_user') ? current_user() : null;
        q("INSERT INTO easy_production (auftrag_id,pa_id,menge_produziert,kundenmenge,rueckstell,prod_datum,charge_nr,mhd,gefaess,mitarbeiter_id,mitarbeiter_name,geliefert,besonderheiten,buchungen,angelegt,angelegt_von)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$auftragId, ($paId ?: null), $produziert, $kundenmenge, $rueckstell, $datum, $chargeNr, $mhd,
           ($gefaess ?: null), ($maId ?: null), ($maName ?: null), $geliefert ? 1 : 0, ($bes ?: null),
           implode("\n", $protokoll), gmdate('Y-m-d H:i:s'), ($cu['name'] ?? null)]);

        $logText = 'Easy Production: ' . number_format($produziert, 0, ',', '.') . ' Stk produziert (' . $maName . '), Charge ' . $chargeNr
                 . ($rueckstell > 0 ? ', ' . number_format($rueckstell, 0, ',', '.') . ' Rückstellmuster' : '')
                 . ($geliefert ? ', bereits geliefert' : '') . '.';
        if ($kundeId) log_aktivitaet('kunde', $kundeId, 'team', $logText, 'auftrag', 'auftrag', $auftragId);
        else          log_aktivitaet('auftrag', $auftragId, 'team', $logText, 'auftrag', 'auftrag', $auftragId);
        if (function_exists('bedarf_bump')) bedarf_bump();

        $warn = $fehlteil ? ('&warn=' . rawurlencode('Teilweise Fehlbestand (nur verfügbare Menge gebucht): ' . implode(', ', $fehlteil))) : '';
        header('Location: ?p=easy_production&ok=1&auftrag=' . $auftragId . $warn); exit;
    }
}

// ---------------------------------------------------------------- VORSCHAU ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'vorschau' && $fehler === '') {
    $auftragId = (int)($_POST['auftrag_id'] ?? 0);
    $a = $auftragId ? one("SELECT a.*, k.firma AS kunde_firma, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt_name, p.verpackung_id AS p_verpackung
                           FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$auftragId]) : null;
    $produziert = $pint($_POST['menge_produziert'] ?? 0);
    $rueckstell = $pint($_POST['rueckstell'] ?? 0);
    if (!$a)                  $fehler = 'Bitte einen gültigen Auftrag wählen.';
    elseif ($produziert <= 0) $fehler = 'Bitte die produzierte Menge (Stk) angeben.';
    else {
        $st = ep_stueckliste($auftragId, $produziert);
        // Optional gewählte Bulkware (item.kategorie='fertig') als editierbare Materialzeile voranstellen.
        // Wird beim Buchen wie jede andere Position FEFO ausgebucht (kein eigener Pfad). Menge kommt aus dem Buch.
        $rows = $st['rows'];
        $bulkItemId = (int)($_POST['bulk_item_id'] ?? 0);
        if ($bulkItemId > 0) {
            $bi = one("SELECT id, name, COALESCE(NULLIF(einheit,''),'kg') AS einheit FROM item WHERE id=? AND kategorie='fertig'", [$bulkItemId]);
            if ($bi) {
                $schonDrin = false;
                foreach ($rows as $r) { if ((int)$r['item_id'] === $bulkItemId) { $schonDrin = true; break; } }
                if (!$schonDrin) {
                    array_unshift($rows, ['item_id'=>(int)$bi['id'], 'name'=>(string)$bi['name'], 'rolle'=>'Bulkware',
                                          'einheit'=>(string)$bi['einheit'], 'benoetigt'=>0.0, 'verfuegbar'=>item_bestand((int)$bi['id'])]);
                }
            }
        }
        // Defaults für Charge/MHD/Gefäß aus dem Auftrag/PA, wenn nicht eingegeben.
        $chargeNr = trim((string)($_POST['charge_nr'] ?? ''));
        if ($chargeNr === '' && $st['pa_id']) $chargeNr = charge_naechste_nr((int)$st['pa_id']);
        $mhd = $pdatum($_POST['mhd'] ?? null);
        if (!$mhd) { $paMhd = $st['pa_id'] ? (string) scalar("SELECT mhd FROM produktionsauftrag WHERE id=?", [(int)$st['pa_id']]) : ''; $mhd = $paMhd ?: mhd_standard(); }
        $gefaess = (string)($_POST['gefaess'] ?? '');
        $vorschau = [
            'a'=>$a, 'pa_id'=>(int)$st['pa_id'], 'rows'=>$rows, 'bulk_item_id'=>$bulkItemId,
            'produziert'=>$produziert, 'rueckstell'=>$rueckstell, 'kundenmenge'=>max(0, $produziert - $rueckstell),
            'datum'=>$pdatum($_POST['prod_datum'] ?? null) ?? gmdate('Y-m-d'),
            'charge_nr'=>$chargeNr, 'mhd'=>$mhd, 'gefaess'=>$gefaess,
            'mitarbeiter_id'=>(int)($_POST['mitarbeiter_id'] ?? 0),
            'geliefert'=>!empty($_POST['geliefert']), 'besonderheiten'=>trim((string)($_POST['besonderheiten'] ?? '')),
            'gebucht'=>$st['pa_id'] ? produktion_gebucht((int)$st['pa_id']) : 0.0,
            'frueher'=>(int) scalar("SELECT COUNT(*) FROM easy_production WHERE auftrag_id=?", [$auftragId]),
        ];
    }
}

// ---------------------------------------------------------------- DATEN FÜR DIE MASKE ----------------------------------------------------------------
$auftraege = all("SELECT a.id, a.nummer, a.menge, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt, k.firma AS kunde
                  FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN produkt p ON p.id=a.produkt_id
                  WHERE a.status<>'storniert' AND COALESCE(a.kategorie,'produkt')<>'dienstleistung'
                  ORDER BY a.angelegt DESC, a.id DESC");
$mitarbeiter = all("SELECT id, name FROM benutzer WHERE aktiv=1 ORDER BY name");
// Bulkware zur optionalen Auswahl: fertige Bulk-Zwischenprodukte (item.kategorie='fertig').
$bulkItems = all("SELECT id, name, COALESCE(NULLIF(einheit,''),'kg') AS einheit FROM item WHERE kategorie='fertig' ORDER BY name");
$letzte = all("SELECT e.*, a.nummer AS auftrag_nr FROM easy_production e LEFT JOIN auftrag a ON a.id=e.auftrag_id ORDER BY e.id DESC LIMIT 12");

// Vorbelegung der Felder (nach Vorschau erhalten bleiben).
$V = fn($k, $d = '') => h((string)($_POST[$k] ?? $d));
$selAuftrag = (int)($_POST['auftrag_id'] ?? ($_GET['auftrag'] ?? 0));

render_header('easy_production', 'Easy Production');
bx_head('Easy Production', 'Produktion schnell erfassen – Material wird abgebucht, Fertigware eingebucht',
    bx_hint('Für das digitalisierte Produktionsbuch (auch Altaufträge) und die schnelle Direkterfassung. Erst Vorschau, dann Buchen.'));
if (isset($_GET['ok']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Produktion gebucht – Material abgebucht, Fertigware eingebucht' . (isset($_GET['warn']) ? '. <strong>' . h((string)$_GET['warn']) . '</strong>' : '.') . '</div>';
if ($fehler !== '') echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($fehler) . '</div>';

$nf = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
?>
<form method="post">
  <input type="hidden" name="aktion" value="vorschau">
  <div class="bx-panel">
    <h2 style="margin-top:0">Produktion erfassen</h2>
    <div class="bx-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
      <div class="bx-field">
        <label>Auftrag</label>
        <select name="auftrag_id" class="rscombo" required>
          <option value="">– Auftrag wählen –</option>
          <?php foreach ($auftraege as $o): ?>
          <option value="<?= (int)$o['id'] ?>" <?= $selAuftrag === (int)$o['id'] ? 'selected' : '' ?>>
            <?= h($o['nummer']) ?> · <?= h($o['produkt'] ?: '–') ?><?= $o['kunde'] ? ' · ' . h($o['kunde']) : '' ?> (<?= number_format((int)$o['menge'],0,',','.') ?> Stk)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Produzierte Menge (Stk)</label><input type="text" name="menge_produziert" data-tausender inputmode="numeric" value="<?= $V('menge_produziert') ?>" required></div>
      <div class="bx-field"><label>Produktionsdatum</label><input type="date" name="prod_datum" value="<?= $V('prod_datum', gmdate('Y-m-d')) ?>" required></div>
      <div class="bx-field"><label>Chargennummer</label><input type="text" name="charge_nr" value="<?= $V('charge_nr') ?>" placeholder="aus Auftrag – überschreibbar"></div>
      <div class="bx-field"><label>MHD</label><input type="date" name="mhd" value="<?= $V('mhd') ?>"></div>
      <div class="bx-field">
        <label>Gefäß</label>
        <select name="gefaess">
          <option value="">– wählen –</option>
          <?php foreach ($GEFAESSE as $k => $lbl): ?><option value="<?= h($k) ?>" <?= ($_POST['gefaess'] ?? '') === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field">
        <label>Mitarbeiter</label>
        <select name="mitarbeiter_id" class="rscombo" required>
          <option value="">– wer hat produziert –</option>
          <?php foreach ($mitarbeiter as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)($_POST['mitarbeiter_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Rückstellmuster (Stk)</label><input type="text" name="rueckstell" data-tausender inputmode="numeric" value="<?= $V('rueckstell') ?>" placeholder="0"></div>
      <div class="bx-field">
        <label>Bulkware (optional)</label>
        <select name="bulk_item_id" class="rscombo">
          <option value="">– keine (Material aus Stückliste) –</option>
          <?php foreach ($bulkItems as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)($_POST['bulk_item_id'] ?? 0) === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?> (<?= h($b['einheit']) ?>, Bestand <?= $nf(item_bestand((int)$b['id'])) ?>)</option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <p class="muted" style="font-size:12px;margin:8px 0 0">Bulkware wählen, wenn aus vorhandener/zugekaufter Bulkware abgefüllt wurde – sie wird als editierbare Materialzeile ausgebucht (Menge lt. Buch). Ohne Auswahl wird das Material aus der Stückliste abgebucht.</p>
    <div class="bx-row" style="gap:18px;align-items:center;margin-top:12px;flex-wrap:wrap">
      <div class="bx-check"><input type="checkbox" name="geliefert" id="ep_gel" value="1" <?= !empty($_POST['geliefert']) ? 'checked' : '' ?>><label for="ep_gel" style="margin:0">Schon geliefert (Kundenmenge sofort wieder ausbuchen)</label></div>
    </div>
    <div class="bx-field" style="margin-top:12px"><label>Besonderheiten (optional)</label><textarea name="besonderheiten" rows="2" placeholder="z. B. Ausschuss, Auffälligkeiten aus dem Buch"><?= $V('besonderheiten') ?></textarea></div>
    <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Vorschau</button></div>
  </div>
</form>

<?php if ($vorschau): $v = $vorschau; ?>
<form method="post">
  <input type="hidden" name="aktion" value="buchen">
  <input type="hidden" name="auftrag_id" value="<?= (int)$v['a']['id'] ?>">
  <input type="hidden" name="pa_id" value="<?= (int)$v['pa_id'] ?>">
  <input type="hidden" name="menge_produziert" value="<?= (int)$v['produziert'] ?>">
  <input type="hidden" name="rueckstell" value="<?= (int)$v['rueckstell'] ?>">
  <input type="hidden" name="prod_datum" value="<?= h($v['datum']) ?>">
  <input type="hidden" name="charge_nr" value="<?= h($v['charge_nr']) ?>">
  <input type="hidden" name="mhd" value="<?= h((string)$v['mhd']) ?>">
  <input type="hidden" name="gefaess" value="<?= h($v['gefaess']) ?>">
  <input type="hidden" name="mitarbeiter_id" value="<?= (int)$v['mitarbeiter_id'] ?>">
  <?php if ($v['geliefert']): ?><input type="hidden" name="geliefert" value="1"><?php endif; ?>
  <input type="hidden" name="besonderheiten" value="<?= h($v['besonderheiten']) ?>">
  <div class="bx-panel">
    <h2 style="margin-top:0">Vorschau – das wird gebucht</h2>
    <div class="muted" style="font-size:13px;margin-bottom:10px">
      Auftrag <?= h($v['a']['nummer']) ?> · <?= h($v['a']['produkt_name'] ?: '–') ?><?= $v['a']['kunde_firma'] ? ' · ' . h($v['a']['kunde_firma']) : '' ?> ·
      Charge <?= h($v['charge_nr']) ?> · MHD <?= h((string)$v['mhd']) ?>
      <?php if ($v['pa_id'] && (float)$v['gebucht'] > 0): ?> · <strong>Achtung:</strong> an diesem Auftrag sind bereits <?= number_format((float)$v['gebucht'],0,',','.') ?> Stk gebucht<?php endif; ?>
      <?php if ((int)$v['frueher'] > 0): ?> · <?= (int)$v['frueher'] ?> frühere Easy-Production-Erfassung(en)<?php endif; ?>
    </div>

    <h3 style="margin:14px 0 6px;font-weight:400">Materialabgang <span class="muted" style="font-size:12px">(<?= $v['bulk_item_id'] ? 'gewählte Bulkware + ' : '' ?>Stückliste × produzierter Menge; Mengen editierbar)</span></h3>
    <?php if ($v['rows']): ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Position</th><th>Rolle</th><th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">verfügbar</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($v['rows'] as $r): $reicht = (float)$r['verfuegbar'] + 1e-6 >= (float)$r['benoetigt']; ?>
        <tr>
          <td><?= h($r['name']) ?><input type="hidden" name="mat_item[]" value="<?= (int)$r['item_id'] ?>"><input type="hidden" name="mat_name[]" value="<?= h($r['name']) ?>"><input type="hidden" name="mat_einheit[]" value="<?= h($r['einheit']) ?>"></td>
          <td class="muted"><?= h($r['rolle'] ?: '–') ?></td>
          <td class="bx-num"><input type="text" name="mat_menge[]" value="<?= (float)$r['benoetigt'] > 1e-9 ? h($nf($r['benoetigt'])) : '' ?>" placeholder="<?= ($r['rolle'] ?? '') === 'Bulkware' ? 'Menge lt. Buch' : '0' ?>" style="width:110px;text-align:right" inputmode="decimal"></td>
          <td><?= h($r['einheit']) ?></td>
          <td class="bx-num"><?= h($nf($r['verfuegbar'])) ?></td>
          <td><?= $reicht ? bx_badge('reicht','ok') : bx_badge('Fehlbestand','warn') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted" style="font-size:12px;margin:6px 0 0">Reicht der Bestand nicht, wird nur die verfügbare Menge gebucht (kein harter Stopp – Altbestand ist unvollständig).</p>
    <?php else: ?><div class="muted">Keine Stückliste ermittelbar (Rezeptur/Produkt fehlt). Es wird nur Fertigware gebucht.</div><?php endif; ?>

    <h3 style="margin:16px 0 6px;font-weight:400">Fertigware-Zugang</h3>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Posten</th><th class="bx-num">Menge (Stk)</th><th>Kategorie</th></tr></thead>
      <tbody>
        <tr><td>Kundenmenge (produziert − Rückstellmuster)</td><td class="bx-num"><?= number_format((int)$v['kundenmenge'],0,',','.') ?></td><td>Fertigprodukte</td></tr>
        <?php if ((int)$v['rueckstell'] > 0): ?><tr><td>Rückstellmuster (separat, gesperrt)</td><td class="bx-num"><?= number_format((int)$v['rueckstell'],0,',','.') ?></td><td>Fertigprodukte (nicht verkäuflich)</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <?php if ($v['geliefert']): ?><p class="muted" style="font-size:13px;margin:8px 0 0">„Schon geliefert" ist gesetzt: die Kundenmenge (<?= number_format((int)$v['kundenmenge'],0,',','.') ?> Stk) wird direkt nach dem Einbuchen per Lieferschein wieder ausgebucht.</p><?php endif; ?>

    <div class="bx-row" style="gap:10px;margin-top:16px">
      <button class="btn btn-primary" type="submit" data-busy="Buche…">Buchen</button>
      <span class="muted" style="font-size:12px;align-self:center">Reihenfolge: Material raus → Fertigware rein<?= $v['geliefert'] ? ' → Lieferschein raus' : '' ?>.</span>
    </div>
  </div>
</form>
<?php endif; ?>

<?php if ($letzte && !$vorschau): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Zuletzt erfasst</h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Datum</th><th>Auftrag</th><th class="bx-num">produziert</th><th class="bx-num">Kunde</th><th class="bx-num">Rückstell</th><th>Charge</th><th>Mitarbeiter</th><th>geliefert</th></tr></thead>
    <tbody>
    <?php foreach ($letzte as $e): ?>
      <tr>
        <td><?= h((string)($e['prod_datum'] ?: fmt_zeit((string)$e['angelegt'], 'd.m.Y'))) ?></td>
        <td><?= $e['auftrag_id'] ? '<a href="?p=auftrag&id=' . (int)$e['auftrag_id'] . '">' . h((string)($e['auftrag_nr'] ?: ('#'.$e['auftrag_id']))) . '</a>' : '–' ?></td>
        <td class="bx-num"><?= number_format((int)$e['menge_produziert'],0,',','.') ?></td>
        <td class="bx-num"><?= number_format((int)$e['kundenmenge'],0,',','.') ?></td>
        <td class="bx-num"><?= (int)$e['rueckstell'] > 0 ? number_format((int)$e['rueckstell'],0,',','.') : '–' ?></td>
        <td><?= h((string)($e['charge_nr'] ?: '–')) ?></td>
        <td><?= h((string)($e['mitarbeiter_name'] ?: '–')) ?></td>
        <td><?= (int)$e['geliefert'] === 1 ? bx_badge('geliefert','ok') : '<span class="muted">–</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<script>
// Ganzzahl-Felder mit Tausenderpunkt (produzierte Menge, Rückstellmuster): beim Laden + bei Blur formatieren.
(function(){
  function fmt(el){ var d=(el.value||'').replace(/\D/g,''); el.value = d ? d.replace(/\B(?=(\d{3})+(?!\d))/g,'.') : ''; }
  document.querySelectorAll('input[data-tausender]').forEach(function(el){ fmt(el); el.addEventListener('blur', function(){ fmt(el); }); });
})();
</script>
<?php render_footer();
