<?php
// Einkaufsliste – gemeldete Bedarfe nach Typ-Reitern. Lieferant je Zeile wählbar (vorbelegt mit Hauptlieferant),
// nichts vorausgewählt; beim Bestellen wird je Lieferant EINE Bestellung erzeugt. Bulk (Fertige Produkte) je Produkt.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Freien Bedarf hinzufügen (etwas kaufen, das nichts mit der Produktion zu tun hat)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'freibedarf_add') {
    $bez = trim($_POST['fb_bezeichnung'] ?? '');
    if ($bez !== '') {
        $kat = array_key_exists($_POST['fb_kategorie'] ?? '', betriebsmittel_kategorien()) ? $_POST['fb_kategorie'] : null;
        q("INSERT INTO freibedarf (bezeichnung,menge,einheit,kategorie,lieferant_id,elektrisch,notiz) VALUES (?,?,?,?,?,?,?)",
          [$bez,
           (float)str_replace(',', '.', $_POST['fb_menge'] ?? '1') ?: 1,
           trim($_POST['fb_einheit'] ?? '') ?: 'Stück',
           $kat,
           ($_POST['fb_lieferant'] ?? '') !== '' ? (int)$_POST['fb_lieferant'] : null,
           isset($_POST['fb_elektrisch']) ? 1 : 0,
           trim($_POST['fb_notiz'] ?? '') ?: null]);
    }
    header('Location: ?p=einkaufsliste&typ=' . ($kat ?: 'sonstiges') . '&hinzugefuegt=1'); exit;
}
// Freien Bedarf wieder entfernen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'freibedarf_del') {
    q("DELETE FROM freibedarf WHERE id=? AND status='offen'", [(int)($_POST['fb_id'] ?? 0)]);
    header('Location: ?p=einkaufsliste&typ=frei'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'bestellen') {
    $sel     = (array)($_POST['sel'] ?? []);
    $liefMap = (array)($_POST['lief'] ?? []);
    $mengeMap = (array)($_POST['menge'] ?? []);   // vom Einkauf angehobene Bestellmenge je Zeile (optional)
    $datum   = trim($_POST['datum'] ?? '') ?: null;
    // Mengen-Override aus dem Feld lesen (deutsche Schreibweise: Punkt = Tausender, Komma = Dezimal); nur positiv zählt.
    $ovMenge = function(string $key) use ($mengeMap): ?float {
        $r = preg_replace('/[^0-9.,]/', '', trim((string)($mengeMap[$key] ?? '')));
        if ($r === '') return null;
        $f = (float) str_replace(',', '.', str_replace('.', '', $r));
        return $f > 0 ? $f : null;
    };
    // Zeilen-Info je Schlüssel (Etikett: etikett:<item>:<auftrag>, sonst item:<item>)
    $info = [];
    foreach (bedarf_aggregiert(true) as $a) {
        if ($a['zu_bestellen'] <= 1e-6) continue;
        $key = !empty($a['etikett']) ? ('etikett:' . $a['item_id'] . ':' . (int)$a['auftrag_id']) : ('item:' . $a['item_id']);
        $info[$key] = $a;
    }
    $bulkIds = []; foreach (bedarf_bulk(true) as $b) $bulkIds[(int)$b['produkt_id']] = true;
    $freiIds = []; foreach (freibedarf_offen() as $f) $freiIds[(int)$f['id']] = true;
    $nachIds = []; foreach (meldebestand_bedarf() as $nb) $nachIds[(int)$nb['item_id']] = (float)$nb['zu_bestellen'];   // Meldebestand-Nachbestellung
    $groups = [];  // lieferant_id => ['pos'=>[{item_id,menge,auftrag_id}], 'bulk'=>[pid], 'frei'=>[fid]]
    $bulkMengeMap = [];  // produkt_id => angehobene Wunschmenge (global, da bestellung_erstellen nur die Gruppen-pids nutzt)
    foreach ($sel as $key) {
        $sup = (int)($liefMap[$key] ?? 0);
        if (strncmp($key, 'bulk:', 5) === 0) {
            $pid = (int)substr($key, 5);
            if (isset($bulkIds[$pid])) { $groups[$sup]['bulk'][] = $pid; $ov = $ovMenge($key); if ($ov !== null) $bulkMengeMap[$pid] = $ov; }
            continue;
        }
        if (strncmp($key, 'nach:', 5) === 0) {   // Meldebestand-Nachbestellung -> Lagerposition ohne Auftragsbezug
            $iid = (int)substr($key, 5);
            if (isset($nachIds[$iid])) $groups[$sup]['pos'][] = ['item_id'=>$iid, 'menge'=>($ovMenge($key) ?? $nachIds[$iid]), 'auftrag_id'=>0];
            continue;
        }
        if (strncmp($key, 'frei:', 5) === 0) { $fid = (int)substr($key, 5); if (isset($freiIds[$fid])) $groups[$sup]['frei'][] = $fid; continue; }
        if (!isset($info[$key])) continue;
        $a = $info[$key];
        if (!empty($a['etikett']) && empty($a['etikett_ok'])) continue;   // Sperre: ohne Etikett-Design nicht bestellen
        $menge = $ovMenge($key) ?? (float)$a['zu_bestellen'];   // Einkauf darf die Menge anheben (z. B. 1000 -> 2000)
        $groups[$sup]['pos'][] = ['item_id'=>(int)$a['item_id'], 'menge'=>$menge, 'auftrag_id'=>(int)($a['auftrag_id'] ?? 0)];
    }
    $n = 0;
    foreach ($groups as $sup => $g) {
        $bid = bestellung_erstellen($g['pos'] ?? [], $g['bulk'] ?? [], $sup ?: null, $datum, $g['frei'] ?? [], $bulkMengeMap);
        if (!$bid) continue;
        $n++;
        // Mit Bestelldatum ist die Bestellung sofort erteilt – der Lieferant bekommt die Mail (falls eingerichtet).
        if ($datum && $sup && mail_bereit()) mail_lieferant_bestellung($bid);
    }
    header('Location: ?p=einkaufsliste' . (($_GET['typ'] ?? '') ? '&typ=' . $_GET['typ'] : '') . '&bestellt=' . $n); exit;
}

$aggBedarf = array_values(array_filter(bedarf_aggregiert(true), fn($a) => $a['zu_bestellen'] > 1e-6));
$bulkBedarf = array_values(array_filter(bedarf_bulk(true), fn($b) => $b['zu_bestellen'] > 1e-6));
$freiBedarf = freibedarf_offen();
$nachBedarf = meldebestand_bedarf();   // Meldebestand-Nachbestellungen (Lagerartikel unter Mindestbestand)
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
$BM_KAT = betriebsmittel_kategorien();

$aktTyp = $_GET['typ'] ?? '';
// Produktions-Typen + Nachbestellung (Meldebestand) + freie Betriebsmittel-Typen als eigene Reiter.
$TYPEN = ['' => 'Alle', 'etikett' => 'Etiketten', 'verpackung' => 'Verpackung', 'rohstoff' => 'Rohstoffe', 'fertig' => 'Fertige Produkte', 'nachbestell' => 'Nachbestellung'] + $BM_KAT;
// Ordnet eine freie Bedarfszeile einem Betriebsmittel-Reiter zu (ohne Kategorie = Sonstiges).
$freiMatch = function($f, $t) {
    $k = (string)($f['kategorie'] ?? '');
    return $t === 'sonstiges' ? ($k === 'sonstiges' || $k === '') : ($k === $t);
};
$istFreiTyp = isset($BM_KAT[$aktTyp]);
$anzahlTyp = function($t) use ($aggBedarf, $bulkBedarf, $freiBedarf, $nachBedarf, $BM_KAT, $freiMatch) {
    if ($t === 'fertig') return count($bulkBedarf);
    if ($t === 'nachbestell') return count($nachBedarf);
    if ($t === '') return count($aggBedarf) + count($bulkBedarf) + count($freiBedarf) + count($nachBedarf);
    if (isset($BM_KAT[$t])) return count(array_filter($freiBedarf, fn($f) => $freiMatch($f, $t)));
    return count(array_filter($aggBedarf, fn($a) => $a['typ'] === $t));
};
$aggTab  = ($aktTyp === 'fertig' || $aktTyp === 'nachbestell' || $istFreiTyp) ? [] : array_values(array_filter($aggBedarf, fn($a) => $aktTyp === '' || $a['typ'] === $aktTyp));
$bulkTab = ($aktTyp === '' || $aktTyp === 'fertig') ? $bulkBedarf : [];
$freiTab = ($aktTyp === '') ? $freiBedarf : ($istFreiTyp ? array_values(array_filter($freiBedarf, fn($f) => $freiMatch($f, $aktTyp))) : []);
$nachTab = ($aktTyp === '' || $aktTyp === 'nachbestell') ? $nachBedarf : [];
$hatWas  = $aggTab || $bulkTab || $freiTab || $nachTab;
$mfmt = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
// Wert fürs Eingabefeld: Komma-Dezimal, aber OHNE Tausenderpunkt (sonst würde die Rückgabe falsch geparst).
$minput = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', ''), '0'), ',');
// Editierbares Mengenfeld je Zeile – der Einkauf darf die Menge über den Bedarf anheben (z. B. 1000 -> 2000).
$mengeInput = fn(string $key, float $wert, string $einheit) =>
    '<input type="text" inputmode="decimal" name="menge[' . h($key) . ']" value="' . h($minput($wert)) . '"'
  . ' style="width:88px;text-align:right" title="Bestellmenge – kann über den Bedarf angehoben werden">'
  . ' <span class="muted">' . h($einheit) . '</span>';
$rolleBadge = fn($r) => bx_badge($r, $r === 'Fertigware' ? 'info' : '');
// Lieferant-Dropdown je Zeile (vorbelegt)
$liefSelect = function(string $key, int $sel) use ($lieferanten): string {
    $s = '<select name="lief[' . h($key) . ']" style="max-width:170px"><option value="">– Lieferant –</option>';
    foreach ($lieferanten as $l) $s .= '<option value="' . (int)$l['id'] . '"' . ($sel === (int)$l['id'] ? ' selected' : '') . '>' . h($l['firma']) . '</option>';
    return $s . '</select>';
};

render_header('einkaufsliste', 'Einkaufsliste');
bx_head('Einkaufsliste', 'Auswählen, Lieferant je Zeile prüfen, Datum wählen, bestellen – je Lieferant entsteht eine Bestellung.',
        bx_btn('Zu den Bestellungen', '?p=einkauf', 'ghost'));
if (isset($_GET['bestellt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((int)$_GET['bestellt'] ? (int)$_GET['bestellt'] . ' Bestellung(en) angelegt (je Lieferant eine) – unter „Bestellungen" sichtbar; in den Aufträgen vermerkt.' : 'Nichts ausgewählt.') . '</div>';
if (isset($_GET['hinzugefuegt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Zum Einkauf hinzugefügt – erscheint im passenden Typ-Reiter und ist bestellbar.</div>';
?>
<form method="post" class="bx-form">
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Zu bestellen</h2>
    <label class="muted" style="font-size:13px;cursor:pointer"><input type="checkbox" id="selAll" style="vertical-align:middle"> alle im Reiter</label>
  </div>
  <div class="settabs" style="margin:8px 0 4px">
    <?php foreach ($TYPEN as $t => $lbl): $n = $anzahlTyp($t); ?>
      <a href="?p=einkaufsliste<?= $t ? '&typ=' . $t : '' ?>" class="<?= $aktTyp === $t ? 'on' : '' ?>"><?= h($lbl) ?><?= $n ? ' (' . $n . ')' : '' ?></a>
    <?php endforeach; ?>
  </div>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th style="width:34px"></th><th>Artikel / Produkt</th><th></th><th class="bx-num">zu bestellen</th><th style="width:190px">Lieferant</th><th>Aufträge</th></tr></thead>
    <tbody>
      <?php if (!$hatWas): ?><tr><td colspan="6" class="muted"><?= $istFreiTyp
          ? 'Noch nichts unter „' . h($BM_KAT[$aktTyp]) . '" eingetragen – weiter unten unter „Neuen Bedarf eintragen" hinzufügen.'
          : 'Kein gemeldeter Bedarf in diesem Typ. (Im „Einkaufsbedarf" melden.)' ?></td></tr><?php endif; ?>
      <?php foreach ($aggTab as $a):
          $istEtikett = !empty($a['etikett']);
          $key = $istEtikett ? ('etikett:' . (int)$a['item_id'] . ':' . (int)$a['auftrag_id']) : ('item:' . (int)$a['item_id']);
          $gesperrt = $istEtikett && empty($a['etikett_ok']);
          $ei = $istEtikett ? etikett_info((int)$a['auftrag_id']) : null;
      ?>
        <tr<?= $gesperrt ? ' style="opacity:.75"' : '' ?>>
          <td><?php if ($gesperrt): ?><span title="Etikett-Design fehlt – erst hochladen">&#128274;</span><?php else: ?><input type="checkbox" class="bx-sel" name="sel[]" value="<?= h($key) ?>"><?php endif; ?></td>
          <td><?= h($a['name']) ?>
            <?php if ($istEtikett): ?>
              <?php if ($gesperrt): ?> <?= bx_badge('wartet auf Etikett-Design','warn') ?>
              <?php else: ?> <?= bx_badge('Design ✓','ok') ?>
                <?php if (!empty($ei['dok'])): ?> <a href="?p=dokument&id=<?= (int)$ei['dok']['id'] ?>" target="_blank" title="Etikett-Design herunterladen" style="white-space:nowrap;text-decoration:none">&#11015;&#65039; Etikett</a><?php endif; ?>
              <?php endif; ?>
              <?php if (!empty($ei['produkt'])): ?><div class="muted" style="font-size:11px">Produkt: <?= h($ei['produkt']) ?></div><?php endif; ?>
              <?php if (!empty($ei['masse'])): ?><div class="muted" style="font-size:11px">Maße: <?= h($ei['masse']['label']) ?></div><?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?= $rolleBadge($a['rolle']) ?></td>
          <td class="bx-num"><?php if ($gesperrt): ?><strong style="color:#8f231b"><?= $mfmt($a['zu_bestellen']) ?> <?= h($a['einheit']) ?></strong><?php else: ?><?= $mengeInput($key, (float)$a['zu_bestellen'], (string)$a['einheit']) ?><?php endif; ?><?php if (!$istEtikett): ?><div class="muted" style="font-size:11px">Bedarf <?= $mfmt($a['zu_bestellen']) ?> · Lager <?= $mfmt($a['stock']) ?><?= $a['bestellt'] > 1e-6 ? ' · offen ' . $mfmt($a['bestellt']) : '' ?></div><?php else: ?><div class="muted" style="font-size:11px">kundenspezifisch</div><?php endif; ?></td>
          <td><?= $gesperrt ? '<span class="muted">–</span>' : $liefSelect($key, (int)($a['haupt_lieferant'] ?? 0)) ?></td>
          <td style="font-size:12px"><?php foreach ($a['orders'] as $o): if ($o['need'] <= 1e-6) continue; ?>
            <a href="?p=produktionsauftrag&id=<?= (int)$o['pa_id'] ?>" target="_blank" title="Produktionsauftrag im neuen Tab öffnen" style="white-space:nowrap;margin-right:10px;display:inline-block"><?= h($o['auftrag_nr'] ?: ('#'.$o['auftrag_id'])) ?> (<?= $mfmt($o['need']) ?>)&#8599;</a><?php endforeach; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($bulkTab as $b): $key = 'bulk:' . (int)$b['produkt_id']; ?>
        <tr>
          <td><input type="checkbox" class="bx-sel" name="sel[]" value="<?= h($key) ?>"></td>
          <td>Bulk: <?= h($b['produkt'] ?: '–') ?></td>
          <td><?= bx_badge('Fertiges Produkt','info') ?></td>
          <td class="bx-num"><?= $mengeInput($key, (float)$b['zu_bestellen'], 'Stück') ?><div class="muted" style="font-size:11px">Bedarf <?= $mfmt($b['zu_bestellen']) ?> Stück</div></td>
          <td><?= $liefSelect($key, 0) ?></td>
          <td style="font-size:12px"><?php foreach ($b['orders'] as $o): ?>
            <a href="?p=produktionsauftrag&id=<?= (int)$o['pa_id'] ?>" target="_blank" title="Produktionsauftrag im neuen Tab öffnen" style="white-space:nowrap;margin-right:10px;display:inline-block"><?= h($o['auftrag_nr'] ?: ('#'.$o['auftrag_id'])) ?> (<?= $mfmt($o['need']) ?>)&#8599;</a><?php endforeach; ?><span class="muted">· Fremdfertigung</span></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($nachTab as $nb): $key = 'nach:' . (int)$nb['item_id']; ?>
        <tr>
          <td><input type="checkbox" class="bx-sel" name="sel[]" value="<?= h($key) ?>"></td>
          <td><a href="?p=rohstoff&id=<?= (int)$nb['item_id'] ?>" target="_blank" style="text-decoration:none"><?= h($nb['name']) ?></a></td>
          <td><?= bx_badge('Nachbestellung','warn') ?></td>
          <td class="bx-num"><?= $mengeInput($key, (float)$nb['zu_bestellen'], (string)$nb['einheit']) ?><div class="muted" style="font-size:11px">Meldebestand <?= $mfmt($nb['mindest']) ?> · Lager <?= $mfmt($nb['stock']) ?><?= $nb['bestellt'] > 1e-6 ? ' · offen ' . $mfmt($nb['bestellt']) : '' ?></div></td>
          <td><?= $liefSelect($key, (int)($nb['haupt_lieferant'] ?? 0)) ?></td>
          <td style="font-size:12px"><span class="muted">unter Meldebestand</span></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($freiTab as $f): $key = 'frei:' . (int)$f['id']; ?>
        <tr>
          <td><input type="checkbox" class="bx-sel" name="sel[]" value="<?= h($key) ?>"></td>
          <td><?= h($f['bezeichnung']) ?>
            <?php if (!empty($f['elektrisch'])): ?> <?= bx_badge('elektr. – jährl. Prüfung','info') ?><?php endif; ?>
            <?php if (!empty($f['notiz'])): ?><div class="muted" style="font-size:11px"><?= h($f['notiz']) ?></div><?php endif; ?>
            <?php if (!empty($f['gemeldet_von'])): ?><div class="muted" style="font-size:11px">gemeldet von <?= h($f['gemeldet_von']) ?></div><?php endif; ?>
          </td>
          <td><?= $f['kategorie'] ? bx_badge($BM_KAT[$f['kategorie']] ?? $f['kategorie'], '') : bx_badge('Sonstiges','') ?></td>
          <td class="bx-num"><strong style="color:#8f231b"><?= $mfmt($f['menge']) ?> <?= h($f['einheit'] ?: 'Stück') ?></strong><div class="muted" style="font-size:11px">ohne Produktionsbezug</div></td>
          <td><?= $liefSelect($key, (int)($f['lieferant_id'] ?? 0)) ?></td>
          <td style="font-size:12px"><button class="btn btn-ghost btn-sm" type="submit" form="delfrei<?= (int)$f['id'] ?>" onclick="return confirm('Aus der Einkaufsliste entfernen?');">entfernen</button></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($hatWas): ?>
  <div class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:14px">
    <input type="hidden" name="aktion" value="bestellen">
    <div class="bx-field" style="margin:0;max-width:180px"><label>Bestellt am</label><input type="date" name="datum" value="<?= date('Y-m-d') ?>"></div>
    <button class="btn btn-primary" id="btnBestellen" type="submit" disabled>Ausgewählte bestellen</button>
  </div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Häkchen setzen bei dem, was du bestellen willst, und je Zeile den <strong>Lieferant</strong> prüfen (bei Lagerartikeln mit dem Hauptlieferant vorbelegt). Beim Bestellen wird <strong>je Lieferant eine eigene Bestellung</strong> erzeugt; mit Datum = getätigt, ohne = Entwurf.</p>
  <?php endif; ?>
</div>
</form>

<?php foreach ($freiTab as $f): ?>
<form id="delfrei<?= (int)$f['id'] ?>" method="post" style="display:none"><input type="hidden" name="aktion" value="freibedarf_del"><input type="hidden" name="fb_id" value="<?= (int)$f['id'] ?>"></form>
<?php endforeach; ?>

<form method="post" class="bx-form" style="margin-top:16px">
  <input type="hidden" name="aktion" value="freibedarf_add">
  <div class="bx-panel">
    <h2 style="margin-top:0">Neuen Bedarf eintragen</h2>
    <p class="muted" style="margin-top:0;font-size:13px">Alles, was gekauft werden soll, aber nichts mit der Produktion zu tun hat – z. B. Toilettenpapier (Verbrauchsgüter) oder ein neues iPhone (Inventar). Landet im passenden Typ-Reiter oben und lässt sich wie alles andere bestellen.</p>
    <div class="bx-grid">
      <div class="bx-field"><label>Bezeichnung</label><input type="text" name="fb_bezeichnung" required placeholder="z. B. Toilettenpapier / iPhone 15"></div>
      <div class="bx-field"><label>Menge</label><input type="number" step="0.001" name="fb_menge" value="1"></div>
      <div class="bx-field"><label>Einheit</label><input type="text" name="fb_einheit" value="Stück"></div>
      <div class="bx-field"><label>Typ / Kategorie <?= bx_hint('ordnet es einem Warenlager-Typ und dem passenden Reiter zu') ?></label>
        <select name="fb_kategorie"><option value="">– Sonstiges –</option><?php foreach ($BM_KAT as $k => $lbl): ?><option value="<?= $k ?>" <?= $aktTyp === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field"><label>Lieferant (optional)</label>
        <select name="fb_lieferant"><option value="">– offen –</option><?php foreach ($lieferanten as $l): ?><option value="<?= (int)$l['id'] ?>"><?= h($l['firma']) ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field"><label>Notiz (optional)</label><input type="text" name="fb_notiz" placeholder="z. B. wofür / Modell"></div>
    </div>
    <div class="bx-check" style="margin-top:4px">
      <input type="checkbox" name="fb_elektrisch" id="fb_elektrisch" value="1">
      <label for="fb_elektrisch" style="margin:0">Elektronische Komponente – braucht die jährliche Geräteprüfung</label>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-4)"><button class="btn btn-primary" type="submit">Hinzufügen</button></div>
  </div>
</form>
<script>(function(){
  var boxes=document.querySelectorAll('.bx-sel'), btn=document.getElementById('btnBestellen'), a=document.getElementById('selAll');
  function upd(){ if(!btn)return; var any=false; boxes.forEach(function(c){if(c.checked)any=true;}); btn.disabled=!any; }
  boxes.forEach(function(c){c.addEventListener('change',upd);});
  if(a) a.addEventListener('change',function(){boxes.forEach(function(c){c.checked=a.checked;});upd();});
  upd();
})();</script>
<?php render_footer(); ?>
