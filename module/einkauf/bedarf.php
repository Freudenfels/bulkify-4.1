<?php
// Einkaufsbedarf – Prüfen & Melden: je Kundenauftrag Produktionsart (eigen/fremd) festlegen und den Bedarf ans Einkauf melden.
// Danach arbeitet der Einkäufer die „Einkaufsliste" (?p=einkaufsliste) ab.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $paId = (int)($_POST['pa_id'] ?? 0);
    $tabRedir = ($_POST['tab'] ?? '') === 'uebergeben' ? '&tab=uebergeben' : '';
    if ($aktion === 'produktionsart' && $paId) {
        $art = ($_POST['produktionsart'] ?? 'eigen') === 'fremd' ? 'fremd' : 'eigen';
        q("UPDATE produktionsauftrag SET produktionsart=? WHERE id=?", [$art, $paId]);
        header('Location: ?p=bedarf' . $tabRedir); exit;   // frisch laden -> Seite startet oben
    } elseif ($aktion === 'melden' && $paId) {
        q("UPDATE produktionsauftrag SET bedarf_gemeldet=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $paId]);
        $nr = scalar("SELECT a.nummer FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id WHERE pa.id=?", [$paId]);
        header('Location: ?p=bedarf&gemeldet=' . urlencode((string)$nr)); exit;
    } elseif ($aktion === 'melden_zurueck' && $paId) {
        q("UPDATE produktionsauftrag SET bedarf_gemeldet=NULL WHERE id=?", [$paId]);
        header('Location: ?p=bedarf&zurueckgenommen=1'); exit;
    } elseif ($aktion === 'artikel_melden') {
        // Mitarbeiter meldet einen Artikel/Betriebsmittel ohne Produktionsbezug -> landet direkt auf der Einkaufsliste (freibedarf).
        $bez = trim($_POST['bezeichnung'] ?? '');
        if ($bez !== '') {
            $kat = array_key_exists($_POST['kategorie'] ?? '', betriebsmittel_kategorien()) ? $_POST['kategorie'] : null;
            $von = trim((string)(current_user()['name'] ?? '')) ?: null;
            q("INSERT INTO freibedarf (bezeichnung,menge,einheit,kategorie,notiz,gemeldet_von) VALUES (?,?,?,?,?,?)",
              [$bez,
               (float)str_replace(',', '.', $_POST['menge'] ?? '1') ?: 1,
               trim($_POST['einheit'] ?? '') ?: 'Stück',
               $kat,
               trim($_POST['notiz'] ?? '') ?: null,
               $von]);
            header('Location: ?p=bedarf&gemeldet_artikel=1'); exit;
        }
        header('Location: ?p=bedarf&meldefehler=1'); exit;
    }
    header('Location: ?p=bedarf'); exit;
}

$tab = ($_GET['tab'] ?? '') === 'uebergeben' ? 'uebergeben' : 'offen';   // Standard: noch nicht gemeldet
$alle = all("SELECT pa.*, a.nummer AS auftrag_nr, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma AS kunde
             FROM produktionsauftrag pa
             LEFT JOIN auftrag a ON a.id=pa.auftrag_id
             LEFT JOIN produkt p ON p.id=pa.produkt_id
             LEFT JOIN kunden k ON k.id=pa.kunde_id
             WHERE pa.status IN ('offen','laufend') AND pa.auftrag_id IS NOT NULL
             ORDER BY pa.prio, pa.angelegt");
// Diese Seite ist schreibfrei (POST-Handler oben leiten weiter) -> Bestands-/Zeilen-Cache aktivieren und
// Auftrag/Produkt/Fertigware/Schritte aller Auftraege gebuendelt vorladen, damit die Material-Rechnung
// (auftrag_bedarf je Auftrag) nicht je Auftrag einzeln abfragt. Gleiche Bündelung wie in der Produktionsliste.
$GLOBALS['bx_stock_cache'] = [];
$vorPa = []; $vorProd = []; $vorAuf = [];
foreach ($alle as $pa) {
    $vorPa[] = (int)$pa['id'];
    if (!empty($pa['produkt_id'])) $vorProd[] = (int)$pa['produkt_id'];
    if (!empty($pa['auftrag_id'])) $vorAuf[]  = (int)$pa['auftrag_id'];
}
$vorPa = array_values(array_unique($vorPa));
$vorProd = array_values(array_unique($vorProd));
$vorAuf = array_values(array_unique($vorAuf));
$sc = &$GLOBALS['bx_stock_cache'];
if ($vorPa) { $in = implode(',', array_fill(0, count($vorPa), '?'));
    foreach (all("SELECT * FROM produktionsauftrag WHERE id IN ($in)", $vorPa) as $row) $sc['pa:' . (int)$row['id']] = $row; }
if ($vorProd) { $in = implode(',', array_fill(0, count($vorProd), '?'));
    foreach (all("SELECT * FROM produkt WHERE id IN ($in)", $vorProd) as $row) $sc['prod:' . (int)$row['id']] = $row;
    // Anzeige-Helfer vorwaermen: Groessen-Label (produktion_groesse_label) + Bulk-Info (produkt_bulk_info).
    foreach (all("SELECT p.id, r.darreichungsform AS form, kg.name AS kapsel_name,
                         (SELECT COALESCE(SUM(z.menge_mg),0) FROM rezeptur_zutat z WHERE z.rezeptur_id=r.id) AS fg
                  FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                  LEFT JOIN kapselgroesse kg ON kg.id=r.kapselgroesse_id WHERE p.id IN ($in)", $vorProd) as $row)
        $sc['grl:' . (int)$row['id']] = ['form'=>$row['form'], 'kapsel_name'=>$row['kapsel_name'], 'fg'=>$row['fg']];
    foreach (all("SELECT p.id, p.name, COALESCE(r.darreichungsform,'') AS form FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id IN ($in)", $vorProd) as $row)
        $sc['pbulk:' . (int)$row['id']] = ['name'=>$row['name'], 'form'=>$row['form']]; }
if ($vorAuf) { $in = implode(',', array_fill(0, count($vorAuf), '?'));
    foreach (all("SELECT * FROM auftrag WHERE id IN ($in)", $vorAuf) as $row) $sc['auf:' . (int)$row['id']] = $row;
    foreach ($vorAuf as $id) $sc['fw:' . $id] = ['n'=>0, 'frei'=>0.0];
    foreach (all("SELECT c.auftrag_id, COUNT(*) AS n, COALESCE(SUM(CASE WHEN c.status='frei' THEN c.menge_verfuegbar ELSE 0 END),0) AS frei
                  FROM charge c JOIN item i ON i.id=c.item_id WHERE c.auftrag_id IN ($in) AND i.kategorie='fertig' GROUP BY c.auftrag_id", $vorAuf) as $row)
        $sc['fw:' . (int)$row['auftrag_id']] = ['n'=>(int)$row['n'], 'frei'=>(float)$row['frei']]; }
unset($sc);

// Reiter aufteilen: „offen" = noch nicht gemeldet; „übergeben" = gemeldet & noch nicht komplett bestellt
$offenPas = []; $uebergebenPas = [];
foreach ($alle as $pa) {
    if (empty($pa['bedarf_gemeldet'])) $offenPas[] = $pa;
    elseif (auftrag_offener_bedarf((int)$pa['id'])) $uebergebenPas[] = $pa;
}
$pas = $tab === 'uebergeben' ? $uebergebenPas : $offenPas;

// Rezeptur-ID eines Produktionsauftrags (Bulk: direkt, sonst über das Produkt).
$rezIdFuerPa = function (array $pa): int {
    if (!empty($pa['rezeptur_id'])) return (int)$pa['rezeptur_id'];
    if (!empty($pa['produkt_id'])) return (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]);
    return 0;
};
// Zutaten OHNE verknüpften Lagerartikel (Freitext) – die tauchen im Materialbedarf NICHT auf und sind so
// auch nicht bestellbar. Hier sichtbar machen, damit nichts stillschweigend verschwindet (z. B. SRI-81).
$unverknuepftFuer = function (array $pa) use ($rezIdFuerPa): array {
    $rid = $rezIdFuerPa($pa);
    return $rid ? all("SELECT bezeichnung FROM rezeptur_zutat WHERE rezeptur_id=? AND (item_id IS NULL OR item_id=0) AND COALESCE(bezeichnung,'')<>'' ORDER BY sort, id", [$rid]) : [];
};

// Suche: nach Auftrag/Produkt/Kunde ODER Komponenten-/Rohstoffname (auch unverknüpfte Zutaten). Mit Suchbegriff
// werden BEIDE Reiter durchsucht (offen + übergeben), damit man einen Rohstoff findet, egal in welchem Zustand.
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $needle = mb_strtolower($q);
    $pas = array_values(array_filter($alle, function ($pa) use ($needle, $unverknuepftFuer) {
        $hay = mb_strtolower(($pa['auftrag_nr'] ?? '') . ' ' . ($pa['nummer'] ?? '') . ' ' . ($pa['produkt'] ?? '') . ' ' . ($pa['kunde'] ?? ''));
        if (mb_strpos($hay, $needle) !== false) return true;
        foreach (auftrag_bedarf_cached((int)$pa['id']) as $b)
            if (mb_strpos(mb_strtolower((string)($b['name'] ?? '')), $needle) !== false) return true;
        foreach ($unverknuepftFuer($pa) as $u)
            if (mb_strpos(mb_strtolower((string)($u['bezeichnung'] ?? '')), $needle) !== false) return true;
        return false;
    }));
}

render_header('bedarf', 'Einkaufsbedarf');
// Nach dem Umschalten Eigen-/Fremdproduktion (POST -> Redirect auf dieselbe URL) stellt der Browser
// sonst die alte Scroll-Position wieder her. Manuell abschalten -> die Seite startet oben.
echo '<script>if("scrollRestoration" in history)history.scrollRestoration="manual";</script>';
bx_head('Einkaufsbedarf', 'Prüfen (Eigen-/Fremdproduktion) und an den Einkauf melden.',
        bx_btn('Zur Einkaufsliste' . (count($uebergebenPas) ? ' (' . count($uebergebenPas) . ')' : ''), '?p=einkaufsliste', 'ghost'));
?>
<div class="settabs" style="margin:0 0 12px">
  <a href="?p=bedarf" class="<?= $tab === 'offen' && $q === '' ? 'on' : '' ?>">Noch nicht gemeldet<?= $offenPas ? ' (' . count($offenPas) . ')' : '' ?></a>
  <a href="?p=bedarf&tab=uebergeben" class="<?= $tab === 'uebergeben' && $q === '' ? 'on' : '' ?>">Übergeben<?= $uebergebenPas ? ' (' . count($uebergebenPas) . ')' : '' ?></a>
</div>
<form class="bx-listbar" method="get" style="margin:0 0 16px">
  <input type="hidden" name="p" value="bedarf">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Auftrag, Produkt, Kunde oder Rohstoff (z. B. SRI) …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=bedarf">zurücksetzen</a><?php endif; ?>
</form>
<?php if ($q !== ''): ?><p class="bx-sub" style="margin:0 0 12px"><?= count($pas) ?> Treffer für „<?= h($q) ?>" (in beiden Reitern)</p><?php endif; ?>
<?php
if (isset($_GET['zurueck'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Entwurf verworfen – der Bedarf steht wieder hier.</div>';
if (isset($_GET['gemeldet'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Bedarf für <strong>' . h($_GET['gemeldet']) . '</strong> an den Einkauf gemeldet – er erscheint jetzt in der <a href="?p=einkaufsliste">Einkaufsliste</a>.</div>';
if (isset($_GET['zurueckgenommen'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Meldung zurückgenommen.</div>';
if (isset($_GET['gemeldet_artikel'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Danke – der Artikel wurde gemeldet und steht jetzt direkt auf der <a href="?p=einkaufsliste">Einkaufsliste</a>.</div>';
if (isset($_GET['meldefehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Bitte eine Bezeichnung angeben.</div>';

$mfmt = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
$BM_KAT = betriebsmittel_kategorien();
?>
<details class="bx-panel" style="margin-bottom:16px">
  <summary style="cursor:pointer;font-weight:600;list-style:none">+ Artikel / Betriebsmittel melden <span class="muted" style="font-weight:400;font-size:13px">– etwas, das gekauft werden soll (z. B. Handschuhe, Kartons, Werkzeug); geht direkt auf die Einkaufsliste</span></summary>
  <form method="post" class="bx-form" style="margin:14px 0 0">
    <input type="hidden" name="aktion" value="artikel_melden">
    <div class="bx-grid">
      <div class="bx-field"><label>Bezeichnung</label><input type="text" name="bezeichnung" required placeholder="z. B. Nitril-Handschuhe Gr. L"></div>
      <div class="bx-field"><label>Menge</label><input type="number" step="0.001" name="menge" value="1"></div>
      <div class="bx-field"><label>Einheit</label><input type="text" name="einheit" value="Stück"></div>
      <div class="bx-field"><label>Typ / Kategorie</label>
        <select name="kategorie"><option value="">– Sonstiges –</option><?php foreach ($BM_KAT as $k => $lbl): ?><option value="<?= $k ?>"><?= h($lbl) ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field"><label>Notiz (optional)</label><input type="text" name="notiz" placeholder="z. B. wofür / Marke"></div>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-2)"><button class="btn btn-primary" type="submit">Melden</button></div>
  </form>
</details>
<?php if (!$pas): ?>
  <div class="bx-panel"><div class="muted"><?= $q !== '' ? 'Keine Treffer für „' . h($q) . '". Tipp: Steht der Rohstoff nicht drin, ist er evtl. nicht mit einem Lagerartikel verknüpft, oder zum Auftrag gibt es noch keinen Produktionsauftrag.' : ($tab === 'uebergeben' ? 'Nichts an den Einkauf übergeben (bzw. schon alles bestellt).' : 'Kein offener Bedarf – alles gemeldet.') ?></div></div>
<?php else: foreach ($pas as $pa):
    $gemeldet = !empty($pa['bedarf_gemeldet']);
    $fremd    = ($pa['produktionsart'] ?? 'eigen') === 'fremd';
    $bedarf   = auftrag_bedarf_cached((int)$pa['id']);                 // komplette Stückliste (gecacht; bei Fremd: Bulk-Zukauf + Verpackung/Etiketten)
    $hatFehl  = false; foreach ($bedarf as $bb) if ((float)$bb['fehlt'] > 1e-6) { $hatFehl = true; break; }
?>
  <div class="bx-panel"<?= $gemeldet ? ' style="border-color:var(--gruen)"' : '' ?>>
    <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:10px">
      <div>
        <a href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>"><strong><?= h($pa['auftrag_nr'] ?: $pa['nummer']) ?></strong></a>
        · <?= h($pa['produkt'] ?: '–') ?><?= $pa['kunde'] ? ' · ' . h($pa['kunde']) : '' ?>
        <span class="muted">· <?= (int)$pa['menge'] ?> Packungen</span>
        <?php $groesse = produktion_groesse_label((int)$pa['produkt_id']); if ($groesse !== ''): ?><span class="muted">· <?= h($groesse) ?></span><?php endif; ?>
        <?= prio_badge((int)($pa['prio'] ?? 2)) ?>
        <?= $gemeldet ? bx_badge('an Einkauf gemeldet','ok') : bx_badge('noch nicht gemeldet','warn') ?>
      </div>
      <div class="bx-row" style="gap:8px;align-items:center">
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="produktionsart"><input type="hidden" name="pa_id" value="<?= (int)$pa['id'] ?>"><input type="hidden" name="tab" value="<?= h($tab) ?>">
          <select name="produktionsart" onchange="this.form.submit()" title="Machen wir es selbst oder kaufen wir das fertige Produkt zu?">
            <option value="eigen" <?= !$fremd ? 'selected' : '' ?>>Eigenproduktion</option>
            <option value="fremd" <?= $fremd ? 'selected' : '' ?>>Fremdproduktion (zukaufen)</option>
          </select>
        </form>
        <?php if (!$gemeldet): ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="melden"><input type="hidden" name="pa_id" value="<?= (int)$pa['id'] ?>">
            <button class="btn btn-primary btn-sm" type="submit">An Einkauf melden</button></form>
        <?php else: ?>
          <span class="muted" style="font-size:12px">gemeldet <?= h(fmt_zeit($pa['bedarf_gemeldet'], 'd.m.Y')) ?></span>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="melden_zurueck"><input type="hidden" name="pa_id" value="<?= (int)$pa['id'] ?>">
            <button class="btn btn-ghost btn-sm" type="submit">zurücknehmen</button></form>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($fremd): $bi = produkt_bulk_info((int)$pa['produkt_id']); ?>
      <div class="muted" style="margin-top:8px">Fremdproduktion: der <strong><?= h($bi['form_wort'] !== '' ? $bi['name'] . ' als ' . $bi['form_wort'] : $bi['name'] . ' (Bulk)') ?></strong> wird zugekauft – <strong>Verpackung und Etiketten werden trotzdem gebraucht</strong> und sind hier gelistet.</div>
    <?php endif; ?>
    <?php if ($bedarf): ?>
      <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
        <thead><tr><th>Komponente (Stückliste)</th><th></th><th class="bx-num">Benötigt</th><th class="bx-num">Auf Lager</th><th class="bx-num">Fehlt</th></tr></thead>
        <tbody>
          <?php foreach ($bedarf as $f): $fehlt = (float)$f['fehlt']; ?>
            <tr>
              <td><?= h($f['name']) ?></td>
              <td><?= bx_badge($f['rolle']) ?></td>
              <td class="bx-num"><?= $mfmt($f['benoetigt']) ?> <?= h($f['einheit']) ?></td>
              <td class="bx-num"><?= $mfmt($f['verfuegbar']) ?> <?= h($f['einheit']) ?></td>
              <td class="bx-num"><?= $fehlt > 1e-6 ? '<strong style="color:#8f231b">' . $mfmt($fehlt) . ' ' . h($f['einheit']) . '</strong>' : '<span class="bx-ok">✓ auf Lager</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if (!$hatFehl): ?><div class="muted" style="margin-top:8px"><span class="bx-ok">Material vollständig auf Lager</span> – nichts zu bestellen.</div><?php endif; ?>
    <?php endif; ?>
    <?php $unv = $unverknuepftFuer($pa); if ($unv): ?>
      <div class="bx-panel" style="border-color:#e6c4c0;background:rgba(230,196,192,.12);padding:10px 14px;margin:10px 0 0">
        <strong style="color:#8f231b">Nicht bestellbar – kein Lagerartikel verknüpft:</strong>
        <?= h(implode(', ', array_map(fn($u) => (string)$u['bezeichnung'], $unv))) ?>.
        <div class="muted" style="font-size:12px;margin-top:4px">Diese Zutat(en) tauchen nicht im Materialbedarf auf. Erst als <a href="?p=rohstoffe">Rohstoff anlegen</a> und der Rezeptur zuordnen – dann wird der Bedarf berechnet und der Rohstoff ist bestellbar.</div>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>
<?php unset($GLOBALS['bx_stock_cache']); render_footer(); ?>
