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
// Eigen/Fremd direkt im Einkauf festlegen: gibt den Vor-Produktionsauftrag zugleich ans Werk frei
// (ein Klick je Auftrag). Danach erscheint der passende Bedarf im jeweiligen Typ-Reiter.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'festlegen') {
    $paId = (int)($_POST['pa_id'] ?? 0);
    $art  = ($_POST['art'] ?? '') === 'eigen' ? 'eigen' : 'fremd';
    $wer  = trim((string)(current_user()['name'] ?? '')) ?: 'Einkauf';
    $res  = $paId ? produktionsauftrag_freigeben($paId, $art, null, $wer) : ['ok'=>false, 'fehler'=>'Auftrag fehlt.'];
    header('Location: ?p=einkaufsliste' . ($res['ok'] ? '&festgelegt=' . urlencode($art) : '&festlegfehler=1')); exit;
}
// Vorsorglich bestellen: einen VORHANDENEN Lagerartikel auf Vorrat bestellen – ohne aktuellen Bedarf, ohne
// neuen Namen. Legt eine Bestellung ohne Auftragsbezug an (auftrag_id=0) und markiert sie sofort als bestellt.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'vorsorglich') {
    $iid = (int)($_POST['item_id'] ?? 0);
    $raw = preg_replace('/[^0-9.,]/', '', trim((string)($_POST['menge'] ?? '')));
    $menge = $raw !== '' ? (float) str_replace(',', '.', str_replace('.', '', $raw)) : 0.0;
    $extern = ($_POST['modus'] ?? '') === 'extern';
    $sup = $extern ? null : ((int)($_POST['lieferant_id'] ?? 0) ?: null);
    if ($iid > 0 && $menge > 0 && scalar("SELECT id FROM item WHERE id=?", [$iid])) {
        $bid = bestellung_erstellen([['item_id'=>$iid, 'menge'=>$menge, 'auftrag_id'=>0]], [], $sup, date('Y-m-d'));
        if ($bid) {
            $hatZugang = !$extern && $sup && lieferant_hat_zugang((int)$sup);
            if ($hatZugang) {
                q("UPDATE bestellung SET status='gesendet', notiz='Vorsorglich · an den Lieferanten-Account gesendet – wartet auf Bestätigung' WHERE id=?", [$bid]);
                if (mail_bereit()) mail_lieferant_bestellung($bid);
            } else {
                q("UPDATE bestellung SET notiz=? WHERE id=?", [$extern ? 'Vorsorglich · extern bestellt' : 'Vorsorglich · erfasst (Lieferant ohne Portal-Zugang)', $bid]);
            }
            header('Location: ?p=einkauf&vorsorglich=1'); exit;
        }
    }
    header('Location: ?p=einkaufsliste&vorsorgfehler=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'bestellen') {
    $sel     = (array)($_POST['sel'] ?? []);
    $liefMap = (array)($_POST['lief'] ?? []);
    $mengeMap = (array)($_POST['menge'] ?? []);   // vom Einkauf angehobene Bestellmenge je Zeile (optional)
    $extern  = ($_POST['modus'] ?? '') === 'extern';   // „Habe ich extern bestellt" (Amazon o. ä.): Bestellung ohne Lieferant, gilt als getätigt.
    $datum   = $extern ? date('Y-m-d') : (trim($_POST['datum'] ?? '') ?: null);
    // Mengen-Override aus dem Feld lesen (deutsche Schreibweise: Punkt = Tausender, Komma = Dezimal); nur positiv zählt.
    $ovMenge = function(string $key) use ($mengeMap): ?float {
        $r = preg_replace('/[^0-9.,]/', '', trim((string)($mengeMap[$key] ?? '')));
        if ($r === '') return null;
        $f = (float) str_replace(',', '.', str_replace('.', '', $r));
        return $f > 0 ? $f : null;
    };
    // Zeilen-Info je Schlüssel (Etikett: etikett:<item>:<auftrag>, sonst item:<item>)
    $info = [];
    foreach (bedarf_aggregiert(false) as $a) {
        if ($a['zu_bestellen'] <= 1e-6) continue;
        $key = !empty($a['etikett']) ? ('etikett:' . $a['item_id'] . ':' . (int)$a['auftrag_id']) : ('item:' . $a['item_id']);
        $info[$key] = $a;
    }
    $bulkIds = []; foreach (bedarf_bulk(false) as $b) $bulkIds[(int)$b['produkt_id']] = true;
    $freiIds = []; foreach (freibedarf_offen() as $f) $freiIds[(int)$f['id']] = true;
    $nachIds = []; foreach (meldebestand_bedarf() as $nb) $nachIds[(int)$nb['item_id']] = (float)$nb['zu_bestellen'];   // Meldebestand-Nachbestellung
    $groups = [];  // lieferant_id => ['pos'=>[{item_id,menge,auftrag_id}], 'bulk'=>[pid], 'frei'=>[fid]]
    $bulkMengeMap = [];  // produkt_id => angehobene Wunschmenge (global, da bestellung_erstellen nur die Gruppen-pids nutzt)
    foreach ($sel as $key) {
        $sup = $extern ? 0 : (int)($liefMap[$key] ?? 0);   // extern: eine Bestellung ohne Lieferant
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
        // Zugang-Regel: Lieferant mit Portal-Zugang -> an seinen Account gesendet (Status 'gesendet',
        // wartet auf seine Bestätigung) + Benachrichtigung. Extern ODER Lieferant ohne Zugang -> nur erfasst.
        $hatZugang = !$extern && $sup && lieferant_hat_zugang((int)$sup);
        if ($hatZugang) {
            q("UPDATE bestellung SET status='gesendet', notiz='An den Lieferanten-Account gesendet – wartet auf Bestätigung' WHERE id=?", [$bid]);
            if (mail_bereit()) mail_lieferant_bestellung($bid);
        } else {
            q("UPDATE bestellung SET notiz=? WHERE id=?", [$extern ? 'Extern bestellt (z. B. Amazon)' : 'Erfasst – Lieferant ohne Portal-Zugang (extern)', $bid]);
        }
    }
    header('Location: ?p=einkaufsliste' . (($_GET['typ'] ?? '') ? '&typ=' . $_GET['typ'] : '') . '&bestellt=' . $n . ($extern ? '&extern=1' : '')); exit;
}

$aggBedarf = array_values(array_filter(bedarf_aggregiert(false), fn($a) => $a['zu_bestellen'] > 1e-6));
$bulkBedarf = array_values(array_filter(bedarf_bulk(false), fn($b) => $b['zu_bestellen'] > 1e-6));
$freiBedarf = freibedarf_offen();
$nachBedarf = meldebestand_bedarf();   // Meldebestand-Nachbestellungen (Lagerartikel unter Mindestbestand)
$ohneFestlegung = auftraege_ohne_festlegung();   // Aufträge ohne Eigen/Fremd-Festlegung -> noch kein Bedarf, nur Hinweis
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
// Lieferanten MIT Portal-Zugang (aktiver Benutzer) – an die geht die Bestellung in den Account (wartet auf
// Bestätigung); alle anderen sind „extern" (nur erfasst). Einmal laden statt je Zeile zu prüfen.
$zugangIds = [];
foreach (all("SELECT DISTINCT lieferant_id FROM benutzer WHERE lieferant_id IS NOT NULL AND aktiv=1") as $z) $zugangIds[(int)$z['lieferant_id']] = true;
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
$eurShort = fn($p) => number_format((float)$p, 2, ',', '.') . ' €';
$liefSelect = function(string $key, int $sel, array $preise = []) use ($lieferanten, $eurShort, $zugangIds): string {
    // Lieferanten mit Preis zuerst (günstigste oben), dann der Rest alphabetisch; Preis im Label.
    // Marker: „· Portal" = Bestellung geht in den Account (wartet auf Bestätigung); „· extern" = nur erfasst.
    $mit = []; $ohne = [];
    foreach ($lieferanten as $l) { if (isset($preise[(int)$l['id']])) $mit[] = $l; else $ohne[] = $l; }
    usort($mit, fn($a, $b) => $preise[(int)$a['id']] <=> $preise[(int)$b['id']]);
    $s = '<select name="lief[' . h($key) . ']" style="max-width:260px"><option value="">– Lieferant –</option>';
    foreach (array_merge($mit, $ohne) as $l) {
        $lid = (int)$l['id'];
        $lbl = h($l['firma']) . (isset($preise[$lid]) ? ' · ' . $eurShort($preise[$lid]) : '') . (isset($zugangIds[$lid]) ? ' · Portal' : ' · extern');
        $s .= '<option value="' . $lid . '"' . ($sel === $lid ? ' selected' : '') . '>' . $lbl . '</option>';
    }
    return $s . '</select>';
};

render_header('einkaufsliste', 'Bestellen');
bx_head('Bestellen', 'Was bestellt werden sollte – auswählen und bestellen. Lieferant mit Portal-Zugang: die Bestellung geht in seinen Account (wartet auf Bestätigung). Lieferant ohne Zugang oder „extern": wird nur erfasst. Danach steht alles unter „Bestellt".',
        bx_btn('Zu „Bestellt"', '?p=einkauf', 'ghost'));
if (isset($_GET['bestellt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((int)$_GET['bestellt'] ? (int)$_GET['bestellt'] . (isset($_GET['extern']) ? ' Position(en) als „extern bestellt" markiert' : ' Bestellung(en) angelegt (je Lieferant eine)') . ' – unter „Bestellt" sichtbar; in den Aufträgen vermerkt.' : 'Nichts ausgewählt.') . '</div>';
if (isset($_GET['hinzugefuegt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Zum Einkauf hinzugefügt – erscheint im passenden Typ-Reiter und ist bestellbar.</div>';
if (isset($_GET['festgelegt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ($_GET['festgelegt'] === 'eigen' ? 'Eigenproduktion' : 'Fremdproduktion') . ' festgelegt und freigegeben – der Bedarf steht jetzt im passenden Typ-Reiter.</div>';
if (isset($_GET['festlegfehler'])) echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px">Festlegung nicht möglich – für diesen Auftrag wurde evtl. schon bestellt oder ein Produktionsschritt ist erledigt.</div>';
if (isset($_GET['vorsorgfehler'])) echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px">Vorsorgliche Bestellung nicht möglich – bitte einen vorhandenen Artikel und eine Menge größer 0 angeben.</div>';
if (isset($_GET['aufgesetzt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Die fehlenden Rohstoffe des Produktionsauftrags stehen jetzt hier – Bestand und bereits Bestelltes wurden übersprungen.</div>';

// Aufträge ohne Eigen/Fremd-Festlegung: NOCH KEIN Bedarf anzeigen (Stückliste steht nicht fest).
// Festlegung passiert direkt hier im Einkauf (ein Klick je Auftrag -> gibt den Auftrag zugleich ans Werk frei).
if ($ohneFestlegung): ?>
<details class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px;margin-bottom:12px" open>
  <summary style="cursor:pointer;font-weight:600"><?= count($ohneFestlegung) ?> Auftrag/Aufträge warten auf die Festlegung „Eigen- oder Fremdproduktion"</summary>
  <div class="muted" style="font-size:13px;margin:8px 0 10px">Ein Klick legt fest und gibt den Auftrag frei – danach erscheint der passende Einkaufsbedarf (Rohstoffe bei Eigen-, Bulk-Zukauf bei Fremdproduktion). „Letzte Festlegung" zeigt, wie dasselbe Produkt zuletzt entschieden wurde.</div>
  <div class="bx-row" style="flex-direction:column;gap:8px;align-items:stretch">
    <?php foreach ($ohneFestlegung as $o):
        $letzte = produktionsart_letzte((int)$o['produkt_id'], (int)$o['kunde_id'], (int)$o['pa_id']);
        $letztArt = $letzte ? (string)$letzte['art'] : '';
    ?>
      <div class="bx-row" style="gap:12px;align-items:center;flex-wrap:wrap;border:1px solid var(--line);border-radius:10px;padding:8px 12px">
        <span style="flex:1 1 240px;min-width:0">
          <strong><?= h($o['auftrag_nr'] ?: ('#' . (int)$o['auftrag_id'])) ?></strong><?= $o['produkt'] ? ' · ' . h($o['produkt']) : '' ?><?= $o['kunde'] ? ' <span class="muted">· ' . h(firma_kurz($o['kunde'])) . '</span>' : '' ?>
          <?php if ($letzte): ?>
            <div class="muted" style="font-size:12px;margin-top:2px">Letzte Festlegung: <strong><?= $letztArt === 'fremd' ? 'Fremdproduktion' : 'Eigenproduktion' ?></strong>
              <?= $letzte['auftrag_nr'] ? '· ' . h($letzte['auftrag_nr']) . ' ' : '' ?><?= $letzte['am'] ? '· ' . h(fmt_zeit($letzte['am'], 'd.m.Y')) . ' ' : '' ?><?= ((int)$letzte['kunde_id'] === (int)$o['kunde_id'] && $o['kunde']) ? '· selber Kunde' : ($letzte['kunde'] ? '· ' . h(firma_kurz($letzte['kunde'])) : '') ?></div>
          <?php else: ?>
            <div class="muted" style="font-size:12px;margin-top:2px">Letzte Festlegung: – (erstmalig)</div>
          <?php endif; ?>
        </span>
        <form method="post" style="margin:0;display:inline-flex;gap:8px;flex-wrap:wrap">
          <input type="hidden" name="aktion" value="festlegen">
          <input type="hidden" name="pa_id" value="<?= (int)$o['pa_id'] ?>">
          <button class="btn <?= $letztArt === 'eigen' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit" name="art" value="eigen" title="<?= $letztArt === 'eigen' ? 'Wie zuletzt: ' : '' ?>Wir produzieren selbst – Rohstoffbedarf">Eigenproduktion<?= $letztArt === 'eigen' ? ' (wie zuletzt)' : '' ?></button>
          <button class="btn <?= $letztArt === 'fremd' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit" name="art" value="fremd" title="<?= $letztArt === 'fremd' ? 'Wie zuletzt: ' : '' ?>Fertiges Produkt (Bulk) zukaufen – Verpackung/Etiketten trotzdem">Fremdproduktion (zukaufen)<?= $letztArt === 'fremd' ? ' (wie zuletzt)' : '' ?></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>
<form method="post" class="bx-form">
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Zu bestellen</h2>
    <div class="bx-row" style="gap:12px;align-items:center;flex-wrap:wrap">
      <input type="text" id="bestSuche" placeholder="Tabelle durchsuchen …" autocomplete="off" style="padding:7px 12px;border:1px solid var(--line);border-radius:999px;background:var(--panel);min-width:200px">
      <label class="muted" style="font-size:13px;cursor:pointer"><input type="checkbox" id="selAll" style="vertical-align:middle"> alle im Reiter</label>
    </div>
  </div>
  <div class="settabs" style="margin:8px 0 4px">
    <?php foreach ($TYPEN as $t => $lbl): $n = $anzahlTyp($t); ?>
      <a href="?p=einkaufsliste<?= $t ? '&typ=' . $t : '' ?>" class="<?= $aktTyp === $t ? 'on' : '' ?>"><?= h($lbl) ?><?= $n ? ' (' . $n . ')' : '' ?></a>
    <?php endforeach; ?>
  </div>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th style="width:34px"></th><th>Artikel / Produkt</th><th></th><th class="bx-num">zu bestellen</th><th style="width:190px">Lieferant</th><th>Aufträge</th></tr></thead>
    <tbody id="bestTbody">
      <?php if (!$hatWas): ?><tr><td colspan="6" class="muted"><?= $istFreiTyp
          ? 'Noch nichts unter „' . h($BM_KAT[$aktTyp]) . '" eingetragen – weiter unten unter „Neuen Bedarf eintragen" hinzufügen.'
          : 'Kein offener Bedarf in diesem Typ.' ?></td></tr><?php endif; ?>
      <?php foreach ($aggTab as $a):
          $istEtikett = !empty($a['etikett']);
          $key = $istEtikett ? ('etikett:' . (int)$a['item_id'] . ':' . (int)$a['auftrag_id']) : ('item:' . (int)$a['item_id']);
          $gesperrt = $istEtikett && empty($a['etikett_ok']);
          $ei = $istEtikett ? etikett_info((int)$a['auftrag_id']) : null;
      ?>
        <tr<?= $gesperrt ? ' style="opacity:.75"' : '' ?>>
          <td><?php if ($gesperrt): ?><span title="Etikett noch nicht vom Kunden freigegeben">&#128274;</span><?php else: ?><input type="checkbox" class="bx-sel" name="sel[]" value="<?= h($key) ?>"><?php endif; ?></td>
          <td><?= h($a['name']) ?>
            <?php if ($istEtikett): ?>
              <?php if ($gesperrt): ?> <?= bx_badge('wartet auf Kunden-Freigabe','warn') ?>
              <?php else: ?> <?= bx_badge('freigegeben ✓','ok') ?>
                <?php if (!empty($ei['dok'])): ?> <a href="?p=dokument&id=<?= (int)$ei['dok']['id'] ?>" target="_blank" title="Etikett-Design herunterladen" style="white-space:nowrap;text-decoration:none">&#11015;&#65039; Etikett</a><?php endif; ?>
              <?php endif; ?>
              <?php if (!empty($ei['produkt'])): ?><div class="muted" style="font-size:11px">Produkt: <?= h($ei['produkt']) ?></div><?php endif; ?>
              <?php if (!empty($ei['masse'])): ?><div class="muted" style="font-size:11px">Maße: <?= h($ei['masse']['label']) ?></div><?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?= $rolleBadge($a['rolle']) ?></td>
          <td class="bx-num"><?php if ($gesperrt): ?><strong style="color:#8f231b"><?= $mfmt($a['zu_bestellen']) ?> <?= h($a['einheit']) ?></strong><?php else: ?><?= $mengeInput($key, (float)$a['zu_bestellen'], (string)$a['einheit']) ?><?php endif; ?><?php if (!$istEtikett): ?><div class="muted" style="font-size:11px">Bedarf <?= $mfmt($a['zu_bestellen']) ?> · Lager <?= $mfmt($a['stock']) ?><?= $a['bestellt'] > 1e-6 ? ' · offen ' . $mfmt($a['bestellt']) : '' ?></div><?php else: ?><div class="muted" style="font-size:11px">kundenspezifisch</div><?php endif; ?></td>
          <td><?= $gesperrt ? '<span class="muted">–</span>' : $liefSelect($key, (int)($a['haupt_lieferant'] ?? 0), item_lieferant_preise((int)$a['item_id'], (float)$a['zu_bestellen'])) ?></td>
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
          <td><?= $liefSelect($key, (int)($nb['haupt_lieferant'] ?? 0), item_lieferant_preise((int)$nb['item_id'], (float)$nb['zu_bestellen'])) ?></td>
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
    <button class="btn btn-primary" id="btnBestellen" type="submit" name="modus" value="lieferant" disabled>Beim Lieferanten bestellen</button>
    <button class="btn btn-ghost" id="btnExtern" type="submit" name="modus" value="extern" disabled title="Für Sachen, die du selbst extern kaufst (z. B. Amazon) – legt eine Bestellung ohne Lieferant an und verschiebt die Position nach „Bestellt".">Habe ich extern bestellt</button>
  </div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Häkchen setzen. <strong>Beim Lieferanten bestellen</strong> = je Lieferant eine Bestellung (Lieferant je Zeile prüfen; mit Datum = getätigt). <strong>Habe ich extern bestellt</strong> = du hast es selbst gekauft (Amazon o. ä.) – es wird ohne Lieferant als „bestellt" verbucht. In beiden Fällen wandert die Position nach „Bestellt".</p>
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

<form method="post" class="bx-form" style="margin-top:16px">
  <input type="hidden" name="aktion" value="vorsorglich">
  <div class="bx-panel">
    <h2 style="margin-top:0">Vorsorglich bestellen <span class="muted" style="font-weight:400;font-size:13px">– vorhandener Artikel auf Vorrat</span></h2>
    <p class="muted" style="margin-top:0;font-size:13px">Einen <strong>bereits vorhandenen</strong> Lagerartikel (Rohstoff, Verpackung, Verbrauch …) auf Vorrat bestellen – auch ohne aktuellen Bedarf und ohne einen neuen Namen einzutragen. Die Bestellung landet direkt unter „Bestellt".</p>
    <div class="bx-grid">
      <div class="bx-field"><label>Artikel (vorhanden)</label>
        <select name="item_id" class="rscombo" required>
          <option value="">– Artikel wählen –</option>
          <?php foreach (all("SELECT id, name, kategorie FROM item WHERE kategorie IN ('rohstoff','verpackung','verbrauch','fertig') AND COALESCE(gesperrt,0)=0 ORDER BY name") as $it): ?>
            <option value="<?= (int)$it['id'] ?>"><?= h($it['name']) ?><?= $it['kategorie'] ? ' · ' . h($it['kategorie']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Menge</label><input type="text" name="menge" placeholder="z. B. 2.520" required></div>
      <div class="bx-field"><label>Lieferant (optional)</label>
        <select name="lieferant_id"><option value="">– offen / extern –</option><?php foreach ($lieferanten as $l): ?><option value="<?= (int)$l['id'] ?>"><?= h($l['firma']) ?><?= isset($zugangIds[(int)$l['id']]) ? ' · Portal' : ' · extern' ?></option><?php endforeach; ?></select>
      </div>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-4);gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary" type="submit" name="modus" value="lieferant">Beim Lieferanten bestellen</button>
      <button class="btn btn-ghost" type="submit" name="modus" value="extern">Habe ich extern bestellt</button>
    </div>
  </div>
</form>
<script>(function(){
  var boxes=document.querySelectorAll('.bx-sel'), btn=document.getElementById('btnBestellen'), bex=document.getElementById('btnExtern'), a=document.getElementById('selAll');
  function upd(){ var any=false; boxes.forEach(function(c){if(c.checked)any=true;}); if(btn)btn.disabled=!any; if(bex)bex.disabled=!any; }
  boxes.forEach(function(c){c.addEventListener('change',upd);});
  if(a) a.addEventListener('change',function(){boxes.forEach(function(c){ if(c.closest('tr').style.display!=='none') c.checked=a.checked; });upd();});
  // Suche: Zeilen der "Zu bestellen"-Tabelle filtern; versteckte Zeilen werden abgewählt.
  var q=document.getElementById('bestSuche'), tb=document.getElementById('bestTbody');
  if(q && tb){
    q.addEventListener('input', function(){
      var s=q.value.trim().toLowerCase();
      tb.querySelectorAll('tr').forEach(function(tr){
        var hit = s==='' || tr.textContent.toLowerCase().indexOf(s)!==-1;
        tr.style.display = hit ? '' : 'none';
        if(!hit){ var cb=tr.querySelector('.bx-sel'); if(cb && cb.checked) cb.checked=false; }
      });
      upd();
    });
  }
  upd();
})();</script>
<?php render_footer(); ?>
