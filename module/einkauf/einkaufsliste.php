<?php
// Einkaufsliste – gemeldete Bedarfe nach Typ-Reitern. Lieferant je Zeile wählbar (vorbelegt mit Hauptlieferant),
// nichts vorausgewählt; beim Bestellen wird je Lieferant EINE Bestellung erzeugt. Bulk (Fertige Produkte) je Produkt.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/bestellung_doppelt.php';
require_once BX_ROOT . '/core/lieferant_unterlagen.php';   // Paket 12.11.2026 Teil 4: Bestellen erst mit Unterlagen

// Warnung bei Doppelbestellung (Sofortfix 11.11.2026): vor „Vorsorglich" und „Bestellen" (alle drei Modi) die offenen
// Bestellungen der gewählten Artikel bzw. Bulk-Produkte prüfen. Treffer ohne Bestätigung -> Pop-up, nichts wird angelegt.
$bxDoppelt = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['vorsorglich', 'bestellen'], true)) {
    $dItems = []; $dProds = [];
    if ($_POST['aktion'] === 'vorsorglich') $dItems[] = (int)($_POST['item_id'] ?? 0);
    else foreach ((array)($_POST['sel'] ?? []) as $k) {
        $teile = explode(':', (string)$k);
        if (in_array($teile[0], ['item', 'nach', 'etikett'], true)) $dItems[] = (int)($teile[1] ?? 0);
        elseif ($teile[0] === 'bulk') $dProds[] = (int)($teile[1] ?? 0);
    }
    $bxDoppelt = bestellung_doppelt_treffer($dItems, $dProds);
    if ($bxDoppelt && empty($_POST['doppelt_ok'])) bestellung_doppelt_stopp($bxDoppelt, '?p=einkaufsliste' . (($_GET['typ'] ?? '') !== '' ? '&typ=' . rawurlencode((string)$_GET['typ']) : ''));
}

// Freien Bedarf hinzufügen (etwas kaufen, das nichts mit der Produktion zu tun hat)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'freibedarf_add') {
    $bez = trim($_POST['fb_bezeichnung'] ?? '');
    if ($bez !== '') {
        $kat = array_key_exists($_POST['fb_kategorie'] ?? '', betriebsmittel_kategorien()) ? $_POST['fb_kategorie'] : null;
        q("INSERT INTO freibedarf (bezeichnung,menge,einheit,kategorie,lieferant_id,elektrisch,notiz) VALUES (?,?,?,?,?,?,?)",
          [$bez,
           zahl_lesen((string)($_POST['fb_menge'] ?? '1'), true) ?: 1,
           trim($_POST['fb_einheit'] ?? '') ?: 'Stk',
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
// Teil 6 (10.11.2026): Die Knöpfe „Eigenproduktion / Fremdproduktion (zukaufen)“ entfallen (kannten weder Premix noch Lager).
// Festgelegt wird im Pop-up „Weg festlegen“ (core/weg_festlegen.php) – dieselben Karten wie im Reiter Beschaffungsweg am Auftrag.
// Vorsorglich bestellen: einen VORHANDENEN Lagerartikel auf Vorrat bestellen – ohne aktuellen Bedarf, ohne
// neuen Namen. Legt eine Bestellung ohne Auftragsbezug an (auftrag_id=0) und markiert sie sofort als bestellt.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'vorsorglich') {
    $iid = (int)($_POST['item_id'] ?? 0);
    $raw = preg_replace('/[^0-9.,]/', '', trim((string)($_POST['menge'] ?? '')));
    $menge = $raw !== '' ? (float) str_replace(',', '.', str_replace('.', '', $raw)) : 0.0;
    $online = ($_POST['modus'] ?? '') === 'online';
    $direkt = ($_POST['modus'] ?? '') === 'direkt';   // „Direkt bestellt (Telefon/Mail)": mit Lieferant, ohne Mail, gilt als bestätigt
    $sup = $online ? null : ((int)($_POST['lieferant_id'] ?? 0) ?: null);
    $onHaendler = trim((string)($_POST['on_haendler'] ?? ''));
    $onZahlart  = array_key_exists((string)($_POST['on_zahlart'] ?? ''), bestellung_zahlarten()) ? (string)$_POST['on_zahlart'] : '';
    $onWaehr    = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_POST['on_waehrung'] ?? 'EUR'))) ?: 'EUR';
    $onPreisRaw = preg_replace('/[^0-9.,]/', '', trim((string)($_POST['on_preis'] ?? '')));
    $onPreis    = $onPreisRaw !== '' ? (float) str_replace(',', '.', str_replace('.', '', $onPreisRaw)) : 0.0;
    if ($online && $onHaendler === '') { header('Location: ?p=einkaufsliste&onlinefehler=1'); exit; }   // Preis optional (netto, kommt sonst mit der Rechnung)
    if ($direkt && !$sup) { header('Location: ?p=einkaufsliste&direktfehler=1'); exit; }
    if (!$online && !$direkt && ($pgGrund = lieferant_portal_grund((int)$sup, $iid, null)) !== '') { header('Location: ?p=einkaufsliste&vorsorgportal=' . urlencode($pgGrund)); exit; }
    if ($iid > 0 && $menge > 0 && scalar("SELECT id FROM item WHERE id=?", [$iid])) {
        $bid = bestellung_erstellen([['item_id'=>$iid, 'menge'=>$menge, 'auftrag_id'=>0]], [], $sup, date('Y-m-d'));
        if ($bid) bestellung_doppelt_vermerken([$bid], $bxDoppelt);
        if ($bid && !$online && ($luS = lu_bestellen_sperre($bid)) !== '') {   // Unterlagen fehlen: nur Entwurf, nicht senden
            // Wirklich als Entwurf (offen, ohne Bestelldatum) – bestellung_erstellen() mit Datum legt sonst „bestellt" an.
            q("UPDATE bestellung SET bestellart='lieferant', status='offen', bestelldatum=NULL, notiz=CONCAT_WS(' · ', NULLIF(TRIM(notiz),''), 'Vorsorglich') WHERE id=?", [$bid]);
            header('Location: ?p=bestellung&id=' . $bid . '&fehler=' . urlencode('Als Entwurf angelegt, nicht gesendet. ' . $luS) . '#unterlagen'); exit;
        }
        if ($bid) {
            if ($direkt) {
                bestellung_direkt_abschliessen($bid, (string)($_POST['di_datum'] ?? ''), (string)($_POST['di_eta'] ?? ''), trim('Vorsorglich · ' . trim((string)($_POST['di_notiz'] ?? '')), ' ·'));
            } elseif ($online) {
                $notiz = 'Vorsorglich · online bestellt bei ' . $onHaendler
                       . ($onZahlart !== '' ? ' · ' . (bestellung_zahlarten()[$onZahlart] ?? $onZahlart) : '')
                       . ' · ' . ($onPreis > 0 ? number_format($onPreis, 2, ',', '.') . ' ' . $onWaehr . ' netto' : 'Preis folgt mit der Rechnung');
                q("UPDATE bestellung SET bestellart='online', status='bestellt', online_haendler=?, online_zahlart=?, online_preis=?, online_waehrung=?, notiz=? WHERE id=?",
                  [$onHaendler, $onZahlart ?: null, $onPreis > 0 ? $onPreis : null, $onWaehr, $notiz, $bid]);
            } else {
                q("UPDATE bestellung SET bestellart='lieferant', status='gesendet', notiz=CONCAT_WS(' · ', NULLIF(TRIM(notiz),''), 'Vorsorglich') WHERE id=?", [$bid]);   // Stand zeigt die Statuskette, nicht die Notiz
                if (mail_bereit()) mail_lieferant_bestellung($bid);
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
    $online  = ($_POST['modus'] ?? '') === 'online';   // „Online bestellt": Mitarbeiter hat selbst online gekauft – EINE Bestellung ohne Lieferant, gilt als getätigt.
    $direkt  = ($_POST['modus'] ?? '') === 'direkt';   // „Direkt bestellt (Telefon/Mail)": je Position eine Bestellung beim gewählten Lieferanten, ohne Mail, bestätigt
    $datum   = $online ? date('Y-m-d') : (trim($_POST['datum'] ?? '') ?: null);
    // Online-Einkauf: Händler/Quelle (wo), Zahlungsart (wie), gezahlter Preis (+ Währung) – fürs spätere Gegenrechnen.
    $onHaendler = trim((string)($_POST['on_haendler'] ?? ''));
    $onZahlart  = array_key_exists((string)($_POST['on_zahlart'] ?? ''), bestellung_zahlarten()) ? (string)$_POST['on_zahlart'] : '';
    $onWaehr    = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)($_POST['on_waehrung'] ?? 'EUR'))) ?: 'EUR';
    $onPreisRaw = preg_replace('/[^0-9.,]/', '', trim((string)($_POST['on_preis'] ?? '')));
    $onPreis    = $onPreisRaw !== '' ? (float) str_replace(',', '.', str_replace('.', '', $onPreisRaw)) : 0.0;
    if ($online && $onHaendler === '') {   // Online-Pflicht: nur wo (Händler). Preis optional – netto, sonst später über die Rechnung (Buchhaltung)
        header('Location: ?p=einkaufsliste' . (($_GET['typ'] ?? '') ? '&typ=' . $_GET['typ'] : '') . '&onlinefehler=1'); exit;
    }
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
    // B1: Lieferanten-Weg = JEDE Position eine EIGENE, einzelne Bestellung (keine Bündelung, auch nicht je
    // Lieferant) – so kann der Lieferant jede Position einzeln annehmen/ablehnen und Teilmengen liefern.
    // Bulk-Produkte sind ohnehin je Produkt ein eigener Beleg. Online-Weg = EINE reale Einkaufsquittung (ein
    // Beleg, eine Rechnung) – darum dort weiter gesammelt.
    $groups = [];       // NUR Online-Weg: alles in EINEM Beleg (sup=0): ['pos'=>[...], 'frei'=>[fid]]
    $einzel = [];       // Lieferanten-Weg: je Position ein Beleg: ['sup'=>, 'pos'=>?[one], 'frei'=>?fid]
    $bulkUnits = [];    // Lieferanten-Weg: je Bulk-Produkt ein eigener Beleg: ['sup'=>, 'pid'=>, 'ov'=>]
    $onlineBulk = [];   // Online-Weg: Bulk bleibt im EINEN Beleg (eine reale Einkaufsquittung)
    $bulkMengeMap = [];  // produkt_id => angehobene Wunschmenge
    $skipPortal = 0;     // Lieferanten-Weg: Zeilen ohne portalfähigen Lieferanten (kein Account/Preis) -> übersprungen
    $skipGruende = [];   // echter Grund je übersprungener Zeile (lieferant_portal_grund), höchstens 3 für die Meldung
    foreach ($sel as $key) {
        // Beim Online-Kauf landet alles in EINER Bestellung ohne Lieferant (sup=0). Beim Lieferanten-Weg
        // zählt der je Zeile gewählte Lieferant – und nur, wenn er portalfähig ist (Account + Preis).
        $sup = $online ? 0 : (int)($liefMap[$key] ?? 0);
        $itemIdKey = null; $produktIdKey = null;
        if (strncmp($key, 'bulk:', 5) === 0)      $produktIdKey = (int)substr($key, 5);
        elseif (strncmp($key, 'nach:', 5) === 0)  $itemIdKey = (int)substr($key, 5);
        elseif (strncmp($key, 'item:', 5) === 0 && isset($info[$key])) $itemIdKey = (int)$info[$key]['item_id'];
        elseif (strncmp($key, 'etikett:', 8) === 0 && isset($info[$key])) $itemIdKey = (int)$info[$key]['item_id'];
        if ($direkt ? $sup <= 0 : (!$online && ($pgGrund = lieferant_portal_grund((int)$sup, $itemIdKey, $produktIdKey)) !== '')) {
            $skipPortal++; if (!$direkt && count($skipGruende) < 3) $skipGruende[$pgGrund] = true; continue;
        }
        if (strncmp($key, 'bulk:', 5) === 0) {
            $pid = (int)substr($key, 5);
            if (isset($bulkIds[$pid])) {
                $ov = $ovMenge($key); if ($ov !== null) $bulkMengeMap[$pid] = $ov;
                if ($online) $onlineBulk[] = $pid;                     // eine Quittung -> alles zusammen
                else         $bulkUnits[] = ['sup'=>$sup, 'pid'=>$pid, 'ov'=>$ov];   // je Produkt ein Beleg
            }
            continue;
        }
        if (strncmp($key, 'nach:', 5) === 0) {   // Meldebestand-Nachbestellung -> Lagerposition ohne Auftragsbezug
            $iid = (int)substr($key, 5);
            if (!isset($nachIds[$iid])) continue;
            $pos = ['item_id'=>$iid, 'menge'=>($ovMenge($key) ?? $nachIds[$iid]), 'auftrag_id'=>0];
            if ($online) $groups[0]['pos'][] = $pos; else $einzel[] = ['sup'=>$sup, 'pos'=>$pos];
            continue;
        }
        if (strncmp($key, 'frei:', 5) === 0) {
            $fid = (int)substr($key, 5); if (!isset($freiIds[$fid])) continue;
            if ($online) $groups[0]['frei'][] = $fid; else $einzel[] = ['sup'=>$sup, 'frei'=>$fid];
            continue;
        }
        if (!isset($info[$key])) continue;
        $a = $info[$key];
        if ((!empty($a['etikett']) || !empty($a['druck'])) && empty($a['etikett_ok'])) continue;   // Sperre: ohne freigegebenes Etikett-/Beutel-Design nicht bestellen
        $menge = $ovMenge($key) ?? (float)$a['zu_bestellen'];   // Einkauf darf die Menge anheben (z. B. 1000 -> 2000)
        $pos = ['item_id'=>(int)$a['item_id'], 'menge'=>$menge, 'auftrag_id'=>(int)($a['auftrag_id'] ?? 0)];
        if ($online) $groups[0]['pos'][] = $pos; else $einzel[] = ['sup'=>$sup, 'pos'=>$pos];
    }
    // Online gekauft: sofort „bestellt", mit Händler/Zahlart/Preis für die Abrechnung.
    $finalOnline = function(int $bid) use ($onHaendler, $onZahlart, $onPreis, $onWaehr) {
        $notiz = 'Online bestellt bei ' . $onHaendler
               . ($onZahlart !== '' ? ' · ' . (bestellung_zahlarten()[$onZahlart] ?? $onZahlart) : '')
               . ' · ' . ($onPreis > 0 ? number_format($onPreis, 2, ',', '.') . ' ' . $onWaehr . ' netto' : 'Preis folgt mit der Rechnung');
        q("UPDATE bestellung SET bestellart='online', status='bestellt', online_haendler=?, online_zahlart=?, online_preis=?, online_waehrung=?, notiz=? WHERE id=?",
          [$onHaendler, $onZahlart ?: null, $onPreis > 0 ? $onPreis : null, $onWaehr, $notiz, $bid]);
    };
    // Lieferanten-Weg: an den Account gesendet (wartet auf Bestätigung) + Mail. Direkt-Weg: bestellt + bestätigt, KEINE Mail.
    $luEntwurf = [];
    $finalLief = function(int $bid) use ($direkt, $datum, &$luEntwurf) {
        // Paket 12.11.2026 Teil 4: fehlen Unterlagen des Lieferanten, bleibt die Bestellung Entwurf (nicht gesendet).
        if (lu_bestellen_sperre($bid) !== '') { $luEntwurf[] = $bid; return; }
        if ($direkt) { bestellung_direkt_abschliessen($bid, (string)$datum, (string)($_POST['di_eta'] ?? ''), trim((string)($_POST['di_notiz'] ?? ''))); return; }
        q("UPDATE bestellung SET bestellart='lieferant', status='gesendet' WHERE id=?", [$bid]);   // Notiz bleibt; „wartet auf Bestätigung" zeigt die Statuskette
        if (mail_bereit()) mail_lieferant_bestellung($bid);
    };
    $n = 0;
    if ($online) {
        // Eine reale Einkaufsquittung = EIN Beleg (Rohstoffe + ggf. Bulk zusammen).
        $g = $groups[0] ?? [];
        $bid = bestellung_erstellen($g['pos'] ?? [], $onlineBulk, null, $datum, $g['frei'] ?? [], $bulkMengeMap);
        if ($bid) { $n++; $finalOnline($bid); bestellung_doppelt_vermerken([$bid], $bxDoppelt); einkauf_rechnung_anhaengen($bid, 'on_rechnung'); }   // B3: Rechnung zum Beleg ablegen
    } else {
        // B1: je Position ein eigener Beleg (Rohstoff/Verpackung/frei/Nachbestellung).
        foreach ($einzel as $e) {
            $bid = isset($e['frei'])
                ? bestellung_erstellen([], [], $e['sup'] ?: null, $datum, [(int)$e['frei']])
                : bestellung_erstellen([$e['pos']], [], $e['sup'] ?: null, $datum, []);
            if (!$bid) continue;
            $n++; $finalLief($bid); bestellung_doppelt_vermerken([$bid], $bxDoppelt);
        }
        // Bulk/Fremdfertigung: je Produkt ein EIGENER Beleg.
        foreach ($bulkUnits as $u) {
            $bmap = $u['ov'] !== null ? [$u['pid'] => $u['ov']] : [];
            $bid = bestellung_erstellen([], [$u['pid']], $u['sup'] ?: null, $datum, [], $bmap);
            if (!$bid) continue;
            $n++; $finalLief($bid); bestellung_doppelt_vermerken([$bid], $bxDoppelt);
        }
    }
    header('Location: ?p=einkaufsliste' . (($_GET['typ'] ?? '') ? '&typ=' . $_GET['typ'] : '') . '&bestellt=' . $n . ($online ? '&online=1' : '') . ($direkt ? '&direkt=1' : '') . ($skipPortal ? '&skip=' . $skipPortal . ($skipGruende ? '&skipgr=' . urlencode(implode(' ', array_keys($skipGruende))) : '') : '') . ($luEntwurf ? '&lu_entwurf=' . implode(',', $luEntwurf) : '')); exit;
}

$aggAlle = bedarf_aggregiert(false);
$aggBedarf = array_values(array_filter($aggAlle, fn($a) => $a['zu_bestellen'] > 1e-6));
// Gesperrt = Etikett bzw. bedruckter Beutel ohne Kunden-Freigabe: nicht bestellbar -> nicht unter „Zu bestellen",
// sondern im eigenen Reiter „Wartet auf Freigabe" (Nico 08.11.2026). Gleiche Regel wie die Sperre je Zeile unten.
$istGesperrt = fn($a) => (!empty($a['etikett']) || !empty($a['druck'])) && empty($a['etikett_ok']);
$aggWartet = array_values(array_filter($aggBedarf, $istGesperrt));
$aggBedarf = array_values(array_filter($aggBedarf, fn($a) => !$istGesperrt($a)));
// Ware in Quarantäne, die den offenen Bedarf (teilweise) deckt: NICHT nachbestellen, sondern freigeben (St-07).
$quarListe = array_values(array_filter($aggAlle, fn($a) => empty($a['etikett']) && (float)($a['quarantaene'] ?? 0) > 1e-6 && (float)$a['need'] - (float)$a['stock'] > 1e-6));
$bulkBedarf = array_values(array_filter(bedarf_bulk(false), fn($b) => $b['zu_bestellen'] > 1e-6));
$freiBedarf = freibedarf_offen();
$nachBedarf = meldebestand_bedarf();   // Meldebestand-Nachbestellungen (Lagerartikel unter Mindestbestand)
$ohneFestlegung = auftraege_ohne_festlegung();   // Aufträge ohne Eigen/Fremd-Festlegung -> noch kein Bedarf, nur Hinweis
$lieferanten = all("SELECT id, firma, waehrung FROM lieferanten WHERE COALESCE(gesperrt,0)=0 ORDER BY firma");   // Block C: gesperrte nicht auswählbar
// Währung je Lieferant (viele China-Lieferanten in USD, nicht EUR). Default aus dem Lieferanten-Stamm.
$liefWaehr = [];
foreach ($lieferanten as $l) $liefWaehr[(int)$l['id']] = (string)($l['waehrung'] ?: 'EUR');
// Lieferanten MIT Portal-Zugang (aktiver Benutzer) – an die geht die Bestellung in den Account (wartet auf
// Bestätigung); alle anderen sind „extern" (nur erfasst). Einmal laden statt je Zeile zu prüfen.
$zugangIds = [];
foreach (all("SELECT DISTINCT lieferant_id FROM benutzer WHERE lieferant_id IS NOT NULL AND aktiv=1") as $z) $zugangIds[(int)$z['lieferant_id']] = true;
$BM_KAT = betriebsmittel_kategorien();
// Standard-Lieferant für Etiketten (Nico 08.11.2026: „bei Etiketten immer Labelisten"): vorbelegt, wenn am Artikel kein
// Hauptlieferant steht – im Direkt-Weg je Zeile, im Online-Weg als Händler. Bleibt änderbar.
$etLief = one("SELECT id, firma FROM lieferanten WHERE firma LIKE '%labelist%' ORDER BY id LIMIT 1") ?: ['id' => 0, 'firma' => 'Labelisten'];

$aktTyp = $_GET['typ'] ?? '';
// Produktions-Typen + Nachbestellung (Meldebestand) + freie Betriebsmittel-Typen als eigene Reiter.
$TYPEN = ['' => 'Alle', 'etikett' => 'Etiketten / Tüten', 'verpackung' => 'Verpackung', 'rohstoff' => 'Rohstoffe', 'fertig' => 'Fertige Produkte', 'nachbestell' => 'Nachbestellung'] + $BM_KAT + ['wartet' => 'Wartet'];
// Ordnet eine freie Bedarfszeile einem Betriebsmittel-Reiter zu (ohne Kategorie = Sonstiges).
$freiMatch = function($f, $t) {
    $k = (string)($f['kategorie'] ?? '');
    return $t === 'sonstiges' ? ($k === 'sonstiges' || $k === '') : ($k === $t);
};
$istFreiTyp = isset($BM_KAT[$aktTyp]);
$anzahlTyp = function($t) use ($aggBedarf, $aggWartet, $bulkBedarf, $freiBedarf, $nachBedarf, $BM_KAT, $freiMatch) {
    if ($t === 'wartet') return count($aggWartet);
    if ($t === 'fertig') return count($bulkBedarf);
    if ($t === 'nachbestell') return count($nachBedarf);
    if ($t === '') return count($aggBedarf) + count($bulkBedarf) + count($freiBedarf) + count($nachBedarf);
    if (isset($BM_KAT[$t])) return count(array_filter($freiBedarf, fn($f) => $freiMatch($f, $t)));
    return count(array_filter($aggBedarf, fn($a) => $a['typ'] === $t));
};
$aggTab  = $aktTyp === 'wartet' ? $aggWartet
         : (($aktTyp === 'fertig' || $aktTyp === 'nachbestell' || $istFreiTyp) ? [] : array_values(array_filter($aggBedarf, fn($a) => $aktTyp === '' || $a['typ'] === $aktTyp)));
$bulkTab = ($aktTyp === '' || $aktTyp === 'fertig') ? $bulkBedarf : [];
$freiTab = ($aktTyp === '') ? $freiBedarf : ($istFreiTyp ? array_values(array_filter($freiBedarf, fn($f) => $freiMatch($f, $aktTyp))) : []);
$nachTab = ($aktTyp === '' || $aktTyp === 'nachbestell') ? $nachBedarf : [];
$hatWas  = $aggTab || $bulkTab || $freiTab || $nachTab;
$mfmt = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
// Wert fürs Eingabefeld: deutsche Schreibweise MIT Tausenderpunkt (z. B. 135.000) – übersichtlicher.
// Gefahrlos, weil $ovMenge die Tausenderpunkte beim Absenden wieder entfernt (Punkt = Tausender, Komma = Dezimal).
$minput = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
// Editierbares Mengenfeld je Zeile – der Einkauf darf die Menge über den Bedarf anheben (z. B. 1000 -> 2000).
$mengeInput = fn(string $key, float $wert, string $einheit) =>
    '<input type="text" inputmode="decimal" name="menge[' . h($key) . ']" value="' . h($minput($wert)) . '"'
  . ' style="width:100px;text-align:right" title="Bestellmenge – kann über den Bedarf angehoben werden (Punkt = Tausender)">'
  . ' <span class="muted">' . h($einheit) . '</span>';
$rolleBadge = fn($r) => bx_badge($r, $r === 'Fertigware' ? 'info' : '');
// Lieferantenpreise bis zu 4 Nachkommastellen + Währung (viele China-Lieferanten in USD, nicht EUR).
// Nachlaufende Nullen werden gekürzt (mind. 2 Nachkommastellen): 96,5000 -> 96,50; Sub-Cent wie 0,0345 bleibt.
$preis4 = function($p, $cur = 'EUR') {
    $s = number_format((float)$p, 4, ',', '.');
    $s = rtrim($s, '0');                               // nachlaufende Nullen weg (Komma bleibt stehen)
    [$g, $d] = array_pad(explode(',', $s), 2, '');
    return $g . ',' . str_pad($d, 2, '0') . ' ' . h((string)($cur ?: 'EUR'));   // mind. 2 Nachkommastellen
};
$sum2   = fn($p, $cur = 'EUR') => number_format((float)$p, 2, ',', '.') . ' ' . h((string)($cur ?: 'EUR'));   // Gesamtsumme: 2 Nachkommastellen
$eurShort = $preis4;   // Rückwärtskompatibel: alte Aufrufe ohne Währung -> EUR
// $abg = [lieferant_id => Ablaufdatum]: dieser Lieferant hat nur abgelaufene Preise -> im Label „abgelaufen", ans Ende sortiert.
$liefSelect = function(string $key, int $sel, array $preise = [], bool $nurMit = false, array $extraSelAttr = [], array $abg = []) use ($lieferanten, $preis4, $zugangIds, $liefWaehr): string {
    // Lieferanten mit Preis zuerst (günstigste oben), dann der Rest alphabetisch; Preis (4 Nachkommastellen) + Währung im Label.
    // Marker: „· Portal" = portalfähig (Account UND Preis) -> Portal-Weg möglich; „· direkt" = nur „Direkt bestellt (Telefon/Mail)".
    // $nurMit=true: nur Lieferanten anzeigen, die für diese Position wirklich einen Preis abgegeben haben.
    // Jede Option trägt data-preis/data-cur, damit JS die Summe live auf den gewählten Lieferanten umrechnen kann.
    $mit = []; $ohne = [];
    foreach ($lieferanten as $l) { if (isset($preise[(int)$l['id']])) $mit[] = $l; else $ohne[] = $l; }
    usort($mit, fn($a, $b) => [isset($abg[(int)$a['id']]), $preise[(int)$a['id']]] <=> [isset($abg[(int)$b['id']]), $preise[(int)$b['id']]]);
    if ($nurMit && !$mit) return '<span class="muted" title="Kein Lieferant hat für dieses Produkt einen Zukaufpreis abgegeben">– kein Zukaufpreis –</span>';
    $attr = '';
    foreach ($extraSelAttr as $k => $v) $attr .= ' ' . $k . '="' . h((string)$v) . '"';
    $s = '<select name="lief[' . h($key) . ']" style="max-width:260px"' . $attr . '><option value="">– Lieferant –</option>';
    foreach ($nurMit ? $mit : array_merge($mit, $ohne) as $l) {
        $lid = (int)$l['id'];
        $cur = $liefWaehr[$lid] ?? 'EUR';
        $data = isset($preise[$lid]) ? ' data-preis="' . h((string)(float)$preise[$lid]) . '" data-cur="' . h((string)$cur) . '"' : '';
        $portalfaehig = isset($zugangIds[$lid]) && isset($preise[$lid]);   // Account UND Preis -> Portal-Weg möglich
        $lbl = h($l['firma']) . (isset($preise[$lid]) ? ' · ' . $preis4($preise[$lid], $cur) : '') . (isset($abg[$lid]) ? ' · abgelaufen seit ' . date('d.m.Y', strtotime((string)$abg[$lid])) : '') . ($portalfaehig ? ' · Portal' : ' · direkt');
        $s .= '<option value="' . $lid . '"' . ($sel === $lid ? ' selected' : '') . $data . '>' . $lbl . '</option>';
    }
    return $s . '</select>';
};

render_header('einkaufsliste', 'Bestellen');
bx_head('Bestellen', 'Was bestellt werden sollte – auswählen und auf einem von drei Wegen bestellen: „Über Lieferant (Portal)" (nur bei aktivem Account UND hinterlegtem Preis – geht in seinen Account, wartet auf Bestätigung), „Direkt bestellt" (Telefon/Mail – sofort bestellt und bestätigt, keine Mail) oder „Online bestellt" (du hast selbst online gekauft – mit Händler, Zahlungsart und Preis). Alles landet unter „Bestellt".',
        bx_btn('Zu „Bestellt"', '?p=einkauf', 'ghost'));
if (isset($_GET['bestellt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . ((int)$_GET['bestellt'] ? (int)$_GET['bestellt'] . (isset($_GET['online']) ? ' Position(en) als „online bestellt" erfasst (Händler gespeichert, Preis falls angegeben)' : (isset($_GET['direkt']) ? ' Bestellung(en) als „direkt bestellt" erfasst (ohne Mail, gilt als bestätigt)' : ' Bestellung(en) an den/die Lieferanten gesendet (je Position eine eigene Bestellung)')) . ' – unter „Bestellt" sichtbar; in den Aufträgen vermerkt.' : 'Nichts ausgewählt.') . (isset($_GET['skip']) ? ' <strong>' . (int)$_GET['skip'] . ' Zeile(n) übersprungen</strong> – ' . (isset($_GET['direkt']) ? 'kein Lieferant gewählt.' : (trim((string)($_GET['skipgr'] ?? '')) !== '' ? h(trim((string)$_GET['skipgr'])) : 'Lieferant ohne aktiven Account/Preis.') . ' Diese bitte über „Direkt bestellt" oder „Online bestellt" erfassen.') : '') . '</div>';
if (!empty($_GET['lu_entwurf'])) {   // Paket 12.11.2026 Teil 4: nicht gesendet, weil Unterlagen des Lieferanten fehlen
    $luL = [];
    foreach (array_filter(array_map('intval', explode(',', (string)$_GET['lu_entwurf']))) as $luB) {
        $luNr = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$luB]);
        if ($luNr !== '') $luL[] = '<a href="?p=bestellung&id=' . $luB . '#unterlagen">' . h($luNr) . '</a>';
    }
    if ($luL) echo '<div class="bx-panel" style="border-left:3px solid var(--warn);padding:12px 16px">Nicht gesendet, nur als Entwurf angelegt – Unterlagen des Lieferanten fehlen: ' . implode(', ', $luL) . '. Die Liste der fehlenden Unterlagen steht an der Bestellung; der Lieferant sieht sie im Portal.</div>';
}
if (isset($_GET['onlinefehler'])) echo '<div class="bx-panel" style="border-color:var(--err);border-left:3px solid var(--err);padding:12px 16px;color:var(--err)"><strong>Nicht bestellt – nichts gespeichert.</strong> Für „Online bestellt" fehlt Händler/Quelle. Bitte die Positionen erneut anhaken und den Händler angeben.</div>';
if (isset($_GET['vorsorgportal'])) {   // echter Grund (Account ODER Preis) aus lieferant_portal_grund()
    $vpGrund = trim((string)$_GET['vorsorgportal']); if ($vpGrund === '' || $vpGrund === '1') $vpGrund = 'Dieser Lieferant hat keinen Portal-Zugang oder keinen Preis für den Artikel.';
    echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px">Nicht bestellt – nichts gespeichert. ' . h($vpGrund)
       . (str_contains($vpGrund, 'Preis') ? ' Über das Portal geht es erst mit Preis (am Artikel oder an der Rezeptur). Sonst „Direkt bestellt (Telefon/Mail)" nutzen.'
          : (str_contains($vpGrund, 'Zugang') ? ' Sonst „Direkt bestellt (Telefon/Mail)" nutzen.' : ' Den Lieferanten im Suchfeld aus der Liste anklicken.')) . '</div>';
}
if (isset($_GET['direktfehler'])) echo '<div class="bx-panel" style="border-color:var(--err);border-left:3px solid var(--err);padding:12px 16px;color:var(--err)"><strong>Nicht bestellt – nichts gespeichert.</strong> Für „Direkt bestellt" bitte einen Lieferanten wählen.</div>';
if (isset($_GET['hinzugefuegt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Zum Einkauf hinzugefügt – erscheint im passenden Typ-Reiter und ist bestellbar.</div>';
if (isset($_GET['bwok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['bwok']) . ' Der Bedarf steht jetzt im passenden Reiter.</div>';
if (isset($_GET['bwfehler'])) echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px">' . h((string)$_GET['bwfehler']) . '</div>';
if (isset($_GET['festlegfehler'])) echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px">Festlegung nicht möglich – für diesen Auftrag wurde evtl. schon bestellt oder ein Produktionsschritt ist erledigt.</div>';
if (isset($_GET['vorsorgfehler'])) echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px">Vorsorgliche Bestellung nicht möglich – bitte einen vorhandenen Artikel und eine Menge größer 0 angeben.</div>';
if (isset($_GET['aufgesetzt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Die fehlenden Rohstoffe des Produktionsauftrags stehen jetzt hier – Bestand und bereits Bestelltes wurden übersprungen.</div>';

// Aufträge ohne Eigen/Fremd-Festlegung: NOCH KEIN Bedarf anzeigen (Stückliste steht nicht fest).
// Festlegung passiert direkt hier im Einkauf (ein Klick je Auftrag -> gibt den Auftrag zugleich ans Werk frei).
if ($ohneFestlegung): ?>
<details class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px;margin-bottom:12px">
  <summary style="cursor:pointer;font-weight:600"><?= count($ohneFestlegung) ?> Auftrag/Aufträge warten auf die Festlegung des Wegs <span class="muted" style="font-weight:400;font-size:12px">(zum Aufklappen klicken)</span></summary>
  <div class="muted" style="font-size:13px;margin:8px 0 10px">„Weg festlegen“ zeigt die Wege mit Kosten für genau diesen Auftrag (im Lager, Zukauf fertig, Premix, Einzelrohstoffe) und die Rezeptur mit den Mengen – danach erscheint der passende Einkaufsbedarf. „Letzte Festlegung“ zeigt, wie dasselbe Produkt zuletzt entschieden wurde.</div>
  <div class="bx-row" style="flex-direction:column;gap:8px;align-items:stretch">
    <?php foreach ($ohneFestlegung as $o):
        $letzte = produktionsart_letzte((int)$o['produkt_id'], (int)$o['kunde_id'], (int)$o['pa_id']);
        $letztArt = $letzte ? (string)$letzte['art'] : '';
    ?>
      <div class="bx-row" style="gap:12px;align-items:center;flex-wrap:wrap;border:1px solid var(--line);border-radius:10px;padding:8px 12px">
        <span style="flex:1 1 240px;min-width:0">
          <a href="?p=auftrag&id=<?= (int)$o['auftrag_id'] ?>" target="_blank" rel="noopener" style="font-weight:600"><?= h($o['auftrag_nr'] ?: ('#' . (int)$o['auftrag_id'])) ?></a><?= $o['produkt'] ? ' · ' . h($o['produkt']) : '' ?><?= $o['kunde'] ? ' <span class="muted">· ' . kunde_anzeige($o['kunde_id'] ?? 0, $o['kunde'], true) . '</span>' : '' ?>
          <?php if ($letzte): ?>
            <div class="muted" style="font-size:12px;margin-top:2px">Letzte Festlegung: <strong><?= $letztArt === 'fremd' ? 'Fremdproduktion' : 'Eigenproduktion' ?></strong>
              <?= $letzte['auftrag_nr'] ? '· ' . h($letzte['auftrag_nr']) . ' ' : '' ?><?= $letzte['am'] ? '· ' . h(fmt_zeit($letzte['am'], 'd.m.Y')) . ' ' : '' ?><?= ((int)$letzte['kunde_id'] === (int)$o['kunde_id'] && $o['kunde']) ? '· selber Kunde' : ($letzte['kunde'] ? '· ' . kunde_anzeige($letzte['kunde_id'] ?? 0, $letzte['kunde'], true) : '') ?></div>
          <?php else: ?>
            <div class="muted" style="font-size:12px;margin-top:2px">Letzte Festlegung: – (erstmalig)</div>
          <?php endif; ?>
        </span>
        <span class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap;font-size:13px">
          <a href="?p=auftrag&id=<?= (int)$o['auftrag_id'] ?>" target="_blank" rel="noopener">Auftrag</a>
          <?php if (!empty($o['angebot_id'])): ?><a href="?p=angebot&id=<?= (int)$o['angebot_id'] ?>" target="_blank" rel="noopener">Angebot<?= !empty($o['angebot_nr']) ? ' ' . h((string)$o['angebot_nr']) : '' ?></a><?php endif; ?>
          <?php if (!empty($o['rezeptur_id'])): ?><a href="?p=rezeptur_detail&id=<?= (int)$o['rezeptur_id'] ?>" target="_blank" rel="noopener">Rezeptur<?= !empty($o['rezeptur_nr']) ? ' ' . h((string)$o['rezeptur_nr']) : '' ?></a><?php endif; ?>
          <button type="button" class="btn btn-primary btn-sm" onclick="bxWegFestlegen(<?= (int)$o['auftrag_id'] ?>)">Weg festlegen</button>
        </span>
      </div>
    <?php endforeach; ?>
  </div>
</details>
<?php require_once BX_ROOT . '/core/weg_festlegen.php'; echo weg_festlegen_dialog(); ?>
<?php endif; ?>
<form method="post" class="bx-form" enctype="multipart/form-data">
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Zu bestellen</h2>
    <div class="bx-row" style="gap:12px;align-items:center;flex-wrap:wrap">
      <input type="text" id="bestSuche" placeholder="Tabelle durchsuchen …" autocomplete="off" style="padding:7px 12px;border:1px solid var(--line);border-radius:999px;background:var(--panel);min-width:200px">
      <label class="muted" style="font-size:13px;cursor:pointer"><input type="checkbox" id="selAll" style="vertical-align:middle"> alle im Reiter</label>
    </div>
  </div>
  <?php if ($quarListe): ?>
  <div class="bx-panel" style="margin:12px 0 4px;border-color:#C9A227;background:rgba(201,162,39,.08);padding:12px 16px">
    <div style="font-weight:600;margin-bottom:6px">In Quarantäne – bitte freigeben, nicht nachbestellen</div>
    <div class="muted" style="font-size:13px;margin-bottom:8px">Diese Ware ist schon im Lager, aber noch nicht freigegeben. Sie ist unten bereits vom Bedarf abgezogen.</div>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Artikel</th><th class="bx-num">Bedarf</th><th class="bx-num">frei</th><th class="bx-num">in Quarantäne</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($quarListe as $qa): ?>
        <tr><td><?= h($qa['name']) ?></td>
          <td class="bx-num"><?= $mfmt($qa['need']) ?> <?= h($qa['einheit']) ?></td>
          <td class="bx-num"><?= $mfmt($qa['stock']) ?> <?= h($qa['einheit']) ?></td>
          <td class="bx-num"><strong><?= $mfmt($qa['quarantaene']) ?> <?= h($qa['einheit']) ?></strong></td>
          <td style="text-align:right"><a class="btn btn-primary btn-sm" href="<?= h(quarantaene_freigabe_url((int)$qa['item_id'])) ?>">Freigeben</a></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
  <div class="settabs" style="margin:8px 0 4px">
    <?php foreach ($TYPEN as $t => $lbl): $n = $anzahlTyp($t); ?>
      <a href="?p=einkaufsliste<?= $t ? '&typ=' . $t : '' ?>" class="<?= $aktTyp === $t ? 'on' : '' ?>"><?= h($lbl) ?><?= $n ? ' (' . $n . ')' : '' ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($aktTyp === 'wartet'): ?><p class="muted" style="font-size:13px;margin:10px 0 0">Etiketten und bedruckte Beutel, deren Design der Kunde noch nicht freigegeben hat. Sie sind noch nicht bestellbar und stehen deshalb nicht unter „Zu bestellen". Nach der Freigabe erscheinen sie dort automatisch (Menge bereits netto gegen Lager und unterwegs).</p><?php endif; ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th style="width:34px"></th><th>Artikel / Produkt</th><th></th><th class="bx-num">zu bestellen</th><th style="width:190px">Lieferant</th><th>Aufträge</th></tr></thead>
    <tbody id="bestTbody">
      <?php if (!$hatWas): ?><tr><td colspan="6" class="muted"><?= $istFreiTyp
          ? 'Noch nichts unter „' . h($BM_KAT[$aktTyp]) . '" eingetragen – weiter unten unter „Neuen Bedarf eintragen" hinzufügen.'
          : ($aktTyp === 'wartet' ? 'Nichts wartet auf eine Kunden-Freigabe.' : 'Kein offener Bedarf in diesem Typ.') ?></td></tr><?php endif; ?>
      <?php foreach ($aggTab as $a):
          $istEtikett = !empty($a['etikett']);
          $key = $istEtikett ? ('etikett:' . (int)$a['item_id'] . ':' . (int)$a['auftrag_id']) : ('item:' . (int)$a['item_id']);
          $gesperrt = ($istEtikett || !empty($a['druck'])) && empty($a['etikett_ok']);   // Etikett bzw. vollbedruckter Beutel ohne Design-Freigabe
          $ei = $istEtikett ? etikett_info((int)$a['auftrag_id']) : null;
      ?>
        <tr<?= $gesperrt ? ' style="opacity:.75"' : '' ?>>
          <td><?php if ($gesperrt): ?><span title="<?= !empty($a['druck']) ? 'Beutel-Design noch nicht vom Kunden freigegeben' : 'Etikett noch nicht vom Kunden freigegeben' ?>">gesperrt</span><?php else: ?><input type="checkbox" class="bx-sel" name="sel[]" value="<?= h($key) ?>"<?= $istEtikett ? ' data-etikett="1"' : '' ?>><?php endif; ?></td>
          <td><?= h($a['name']) ?>
            <?php if ($istEtikett): ?>
              <?php if ($gesperrt): ?> <?= bx_badge('wartet auf Kunden-Freigabe','warn') ?>
              <?php else: ?> <?= bx_badge('freigegeben ✓','ok') ?>
                <?php if (!empty($ei['dok'])): ?> <a href="?p=dokument&id=<?= (int)$ei['dok']['id'] ?>" target="_blank" title="Etikett-Design herunterladen" style="white-space:nowrap;text-decoration:none">Etikett-Datei</a><?php endif; ?>
              <?php endif; ?>
              <?php if (!empty($ei['produkt'])): ?><div class="muted" style="font-size:11px">Produkt: <?= h($ei['produkt']) ?></div><?php endif; ?>
              <?php if (!empty($ei['masse'])): ?><div class="muted" style="font-size:11px">Maße: <?= h($ei['masse']['label']) ?></div><?php endif; ?>
            <?php endif; ?>
          </td>
          <td><?= $rolleBadge($a['rolle']) ?></td>
          <td class="bx-num"><?php if ($gesperrt): ?><strong style="color:#8f231b"><?= $mfmt($a['zu_bestellen']) ?> <?= h($a['einheit']) ?></strong><?php else: ?><?= $mengeInput($key, (float)$a['zu_bestellen'], (string)$a['einheit']) ?><?php endif; ?><div class="muted" style="font-size:11px">Bedarf <?= $mfmt($a['need']) ?> · Lager <?= $mfmt($a['stock']) ?><?= (float)($a['quarantaene'] ?? 0) > 1e-6 ? ' · Quarantäne ' . $mfmt($a['quarantaene']) : '' ?><?= $a['bestellt'] > 1e-6 ? ' · unterwegs ' . $mfmt($a['bestellt']) : '' ?> · zu bestellen <?= $mfmt($a['zu_bestellen']) ?><?= $istEtikett ? ' · kundenspezifisch' : '' ?></div></td>
          <?php $aAbg = []; ?><td><?= $gesperrt ? '<span class="muted">–</span>' : $liefSelect($key, (int)($a['haupt_lieferant'] ?? 0) ?: ($istEtikett ? (int)$etLief['id'] : 0), item_lieferant_preise((int)$a['item_id'], (float)$a['zu_bestellen'], $aAbg), false, [], $aAbg ?? []) ?><?php if (!empty($aAbg) && isset($aAbg[(int)($a['haupt_lieferant'] ?? 0)])): ?><div style="font-size:11px;color:#8f231b">Preis des Hauptlieferanten abgelaufen - <a href="?p=rohstoff&id=<?= (int)$a['item_id'] ?>&tab=ek" target="_blank" rel="noopener">neu anfragen</a></div><?php endif; $aAbg = null; ?></td>
          <?php // Kunde·Produkt je Auftrag nur bei bedruckten Tüten/Beuteln (kundenspezifisch) – bei Gläsern & Co. nicht nötig.
                $zeigKdPr = (($a['verpackungsart'] ?? '') === 'beutel'); ?>
          <td style="font-size:12px"><?php if ($zeigKdPr): // Beutel: je Auftrag eine Kunde·Produkt-Unterzeile -> gestapelt lassen ?>
            <?php foreach ($a['orders'] as $o): if ($o['need'] <= 1e-6) continue; ?>
            <div style="margin-bottom:5px">
              <a href="?p=produktionsauftrag&id=<?= (int)$o['pa_id'] ?>" target="_blank" title="Produktionsauftrag im neuen Tab öffnen" style="white-space:nowrap;display:inline-block"><?= h($o['auftrag_nr'] ?: ('#'.$o['auftrag_id'])) ?> (<?= $mfmt($o['need']) ?>)&#8599;</a>
              <?php $oKd = (string)($o['kunde'] ?? '') !== '' ? kunde_anzeige($o['kunde_id'] ?? 0, (string)$o['kunde'], true) : ''; $oPr = (string)($o['produkt'] ?? ''); if ($oKd !== '' || $oPr !== ''): ?><div class="muted" style="font-size:11px"><?= $oKd ?><?= $oKd !== '' && $oPr !== '' ? ' · ' : '' ?><?= h($oPr) ?></div><?php endif; ?>
            </div><?php endforeach; ?>
          <?php else: // Gläser u. a.: keine Unterzeile -> Auftrags-Links nebeneinander, umbrechend ?>
            <div style="display:flex;flex-wrap:wrap;gap:4px 12px"><?php foreach ($a['orders'] as $o): if ($o['need'] <= 1e-6) continue; ?>
              <a href="?p=produktionsauftrag&id=<?= (int)$o['pa_id'] ?>" target="_blank" title="Produktionsauftrag im neuen Tab öffnen" style="white-space:nowrap"><?= h($o['auftrag_nr'] ?: ('#'.$o['auftrag_id'])) ?> (<?= $mfmt($o['need']) ?>)&#8599;</a>
            <?php endforeach; ?></div>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($bulkTab as $b): $key = 'bulk:' . (int)$b['produkt_id'];
          // Zukaufpreise (produkt_lieferant_preis) UND Fremdfertigungspreise (rezeptur_lief_angebot der Rezeptur,
          // z. B. Wellgreen/Rainwood) zusammenführen – Zukauf gewinnt bei gleichem Lieferanten, Fremd füllt auf.
          // Fertigware (Handelsware / Zukauf „fertig"): Preis je PACKUNG aus den Zukaufpreisen (S-15/St-24).
          $bFertig = ($b['art'] ?? 'bulk') === 'fertig';
          $bAbg = [];
          $bPreise = $bFertig ? produkt_lieferant_preise_pkg((int)$b['produkt_id'], (float)$b['zu_bestellen'])
                   : produkt_lieferant_preise((int)$b['produkt_id'], (float)$b['zu_bestellen'])
                     + produkt_fremd_lieferant_preise((int)$b['produkt_id'], (float)$b['zu_bestellen'], $bAbg);
          // Günstigster Zukaufpreis je Stück + dessen Währung (Lieferanten-Stamm) – als Vorgabe („ab"), solange
          // kein Lieferant gewählt ist. Danach rechnet JS die Summe auf den GEWÄHLTEN Lieferanten/Preis um.
          $bAb = null; $bAbLid = null;
          // Vorgabe „ab": günstigster GÜLTIGER Preis; nur wenn es keinen gibt, ein abgelaufener (dann markiert).
          $bGueltig = array_diff_key($bPreise, $bAbg) ?: $bPreise;
          foreach ($bGueltig as $__lid => $__p) { if ($bAb === null || $__p < $bAb) { $bAb = $__p; $bAbLid = (int)$__lid; } }
          $bAbCur = $bAbLid !== null ? ($liefWaehr[$bAbLid] ?? 'EUR') : 'EUR';
          $bSumme = $bAb !== null ? $bAb * (float)$b['zu_bestellen'] : null;
      ?>
        <tr>
          <td><input type="checkbox" class="bx-sel" name="sel[]" value="<?= h($key) ?>"></td>
          <td><?= $bFertig ? 'Fertigware' : 'Bulk' ?>: <?= h($b['produkt'] ?: '–') ?></td>
          <td><?= bx_badge('Fertiges Produkt','info') ?></td>
          <?php $bEinh = (string)($b['einheit'] ?? 'Stk'); $bGew = !empty($b['gewicht']);
                $bLes = fn($v) => $bGew ? gewicht_lesbar((float)$v, $bEinh) : $mfmt($v) . ($bFertig ? ' Pkg' : ' Stk'); ?>
          <td class="bx-num"><?= $mengeInput($key, (float)$b['zu_bestellen'], $bEinh) ?><div class="muted" style="font-size:11px">Bedarf <?= $bLes($b['need'] ?? $b['zu_bestellen']) ?> · Lager <?= $bLes($b['stock'] ?? 0) ?><?= ($b['bestellt'] ?? 0) > 1e-6 ? ' · offen ' . $bLes($b['bestellt']) : '' ?></div></td>
          <td><?= $liefSelect($key, 0, $bPreise, true, ['class'=>'bx-bulk-lief', 'data-ab'=>($bAb !== null ? (float)$bAb : ''), 'data-abcur'=>$bAbCur, 'data-einheit'=>$bEinh], $bAbg) ?><?php if ($bAb !== null): ?><div class="muted bx-bulk-sum" style="font-size:11px">ab <?= $preis4($bAb, $bAbCur) ?>/<?= h($bEinh) ?> · Summe <?= $sum2($bSumme, $bAbCur) ?></div><?php if ($bAbLid !== null && isset($bAbg[$bAbLid])): ?><div style="font-size:11px;color:#8f231b">nur abgelaufene Preise - Summe unsicher, neu anfragen</div><?php endif; ?><?php endif; ?></td>
          <td style="font-size:12px"><?php $bHw = false; foreach ($b['orders'] as $o): $bHw = $bHw || !empty($o['handelsware']);
              // Handelsware hat keinen Produktionsauftrag -> Link auf den Auftrag.
              $oHref = (int)$o['pa_id'] > 0 ? '?p=produktionsauftrag&id=' . (int)$o['pa_id'] : '?p=auftrag&id=' . (int)$o['auftrag_id']; ?>
            <a href="<?= h($oHref) ?>" target="_blank" title="<?= (int)$o['pa_id'] > 0 ? 'Produktionsauftrag' : 'Auftrag' ?> im neuen Tab öffnen" style="white-space:nowrap;margin-right:10px;display:inline-block"><?= h($o['auftrag_nr'] ?: ('#'.$o['auftrag_id'])) ?> (<?= $bLes($o['need']) ?>)&#8599;</a><?php endforeach; ?><span class="muted">· <?= $bHw ? 'Handelsware' : ($bFertig ? 'Fertigware-Zukauf' : 'Fremdfertigung') ?></span></td>
        </tr>
      <?php endforeach; ?>
      <?php foreach ($nachTab as $nb): $key = 'nach:' . (int)$nb['item_id']; ?>
        <tr>
          <td><input type="checkbox" class="bx-sel" name="sel[]" value="<?= h($key) ?>"></td>
          <td><a href="?p=rohstoff&id=<?= (int)$nb['item_id'] ?>" target="_blank" style="text-decoration:none"><?= h($nb['name']) ?></a></td>
          <td><?= bx_badge('Nachbestellung','warn') ?></td>
          <td class="bx-num"><?= $mengeInput($key, (float)$nb['zu_bestellen'], (string)$nb['einheit']) ?><div class="muted" style="font-size:11px">Meldebestand <?= $mfmt($nb['mindest']) ?> · Lager <?= $mfmt($nb['stock']) ?><?= $nb['bestellt'] > 1e-6 ? ' · offen ' . $mfmt($nb['bestellt']) : '' ?></div></td>
          <td><?php $nbAbg = []; $nbPr = item_lieferant_preise((int)$nb['item_id'], (float)$nb['zu_bestellen'], $nbAbg); ?><?= $liefSelect($key, (int)($nb['haupt_lieferant'] ?? 0), $nbPr, false, [], $nbAbg) ?></td>
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
          <td class="bx-num"><strong style="color:#8f231b"><?= $mfmt($f['menge']) ?> <?= h($f['einheit'] ?: 'Stk') ?></strong><div class="muted" style="font-size:11px">ohne Produktionsbezug</div></td>
          <td><?= $liefSelect($key, (int)($f['lieferant_id'] ?? 0)) ?></td>
          <td style="font-size:12px"><button class="btn btn-ghost btn-sm" type="submit" form="delfrei<?= (int)$f['id'] ?>" onclick="return confirm('Aus der Einkaufsliste entfernen?');">entfernen</button></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($hatWas && $aktTyp !== 'wartet'): ?>
  <div class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:14px">
    <input type="hidden" name="aktion" value="bestellen">
    <div class="bx-field" style="margin:0;max-width:180px"><label>Bestellt am</label><input type="date" name="datum" value="<?= date('Y-m-d') ?>"></div>
    <button class="btn btn-primary" id="btnBestellen" type="submit" name="modus" value="lieferant" disabled title="Nur portalfähige Lieferanten (aktiver Account UND hinterlegter Preis) – die Bestellung geht in deren Account und wartet auf Bestätigung.">Über Lieferant (Portal)</button>
    <button class="btn btn-ghost" id="btnDirektSchnell" type="submit" name="modus" value="direkt" disabled title="Schon beim Lieferanten bestellt (Sammelorder, Telefon, Mail): als bestellt erfassen – keine Mail, kein Preis nötig, Lieferant je Zeile (Etiketten: Labelisten vorbelegt).">Als bestellt erfassen (direkt)</button>
  </div>
  <details id="direktBox" class="bx-panel" style="margin-top:12px;padding:12px 16px">
    <summary style="cursor:pointer;font-weight:600">Direkt bestellt (Telefon/Mail) <span class="muted" style="font-weight:400;font-size:12px">– beim gewählten Lieferanten selbst bestellt, auch mit Portal-Account: keine Mail, keine Bestätigung nötig</span></summary>
    <div class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:10px">
      <div class="bx-field" style="margin:0;max-width:180px"><label>Erwartet am (optional)</label><input type="date" name="di_eta"></div>
      <div class="bx-field" style="margin:0;min-width:260px;flex:1"><label>Notiz (optional)</label><input type="text" name="di_notiz" placeholder="z. B. telefonisch mit Herrn X"></div>
      <button class="btn btn-primary" id="btnDirekt" type="submit" name="modus" value="direkt" disabled>Direkt bestellt erfassen</button>
    </div>
    <div id="direktHinweis" class="bx-fehlhinweis" hidden></div>
    <p class="muted" style="font-size:12px;margin:10px 0 0">Je Position eine Bestellung beim gewählten Lieferanten (Preis aus dem Lieferantenpreis), Datum aus „Bestellt am“. Status sofort „bestellt“ und bestätigt; der Lieferant sieht sie im Portal (Tracking, Rechnung), muss aber nichts bestätigen.</p>
  </details>
  <details id="onlineBox" class="bx-panel" style="margin-top:12px;padding:12px 16px">
    <summary style="cursor:pointer;font-weight:600">Online bestellt <span class="muted" style="font-weight:400;font-size:12px">– selbst online gekauft (Amazon, Shop, Druckerei …): wo, wie und zu welchem Preis</span></summary>
    <div class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:10px">
      <div class="bx-field" style="margin:0;min-width:220px"><label>Händler / Quelle (Pflicht)</label><input type="text" name="on_haendler" id="onHaendler" placeholder="z. B. Amazon, Etikettendruckerei XY" data-etikett-lief="<?= h((string)$etLief['firma']) ?>"></div>
      <div class="bx-field" style="margin:0"><label>Zahlungsart</label>
        <select name="on_zahlart"><option value="">– wählen –</option><?php foreach (bestellung_zahlarten() as $zk => $zl): ?><option value="<?= h($zk) ?>"><?= h($zl) ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field" style="margin:0;max-width:170px"><label>Preis gesamt netto (optional)</label><input type="text" name="on_preis" id="onPreis" inputmode="decimal" placeholder="leer = folgt mit Rechnung"></div>
      <div class="bx-field" style="margin:0;max-width:90px"><label>Währung</label><input type="text" name="on_waehrung" value="EUR" maxlength="8"></div>
      <div class="bx-field" style="margin:0;min-width:220px"><label>Rechnung (optional)</label><input type="file" name="on_rechnung" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"></div>
      <button class="btn btn-primary" id="btnOnline" type="submit" name="modus" value="online" disabled>Online bestellt erfassen</button>
    </div>
    <div id="onlineHinweis" class="bx-fehlhinweis" hidden></div>
    <p class="muted" style="font-size:12px;margin:10px 0 0">Häkchen oben setzen; die <strong>Menge je Zeile</strong> steht passend zur Warenart (Pulver in kg, Verpackung/Leerkapseln in Stk). Händler angeben (bei Etiketten mit Labelisten vorbelegt). Preis <strong>netto</strong> und Rechnung sind optional – sonst kommen sie später mit der Rechnung über die Buchhaltung. Legt EINE Bestellung ohne Lieferant an (Status „bestellt"); Händler, Zahlungsart, Preis und Rechnung werden für die spätere Abrechnung gespeichert.</p>
  </details>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Häkchen setzen und einen Weg wählen. <strong>Über Lieferant (Portal)</strong> = je Position eine eigene Bestellung, geht in den Account des gewählten Lieferanten (nur mit aktivem Account und Preis). So kann er jede Position einzeln annehmen/ablehnen. <strong>Direkt bestellt</strong> = per Telefon/Mail beim Lieferanten bestellt, ohne Mail und Bestätigung. <strong>Online bestellt</strong> = du hast selbst online gekauft. In allen Fällen wandert die Position nach „Bestellt".</p>
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
      <div class="bx-field"><label>Menge</label><input type="text" inputmode="decimal" name="fb_menge" value="1"></div>
      <div class="bx-field"><label>Einheit</label><input type="text" name="fb_einheit" value="Stk"></div>
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
      <div class="bx-field"><label>Lieferant (Portal- oder Direkt-Weg)</label>
        <select name="lieferant_id"><option value="">– keiner –</option><?php foreach ($lieferanten as $l): ?><option value="<?= (int)$l['id'] ?>"><?= h($l['firma']) ?><?= isset($zugangIds[(int)$l['id']]) ? ' · Portal' : ' · direkt' ?></option><?php endforeach; ?></select>
      </div>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-4);gap:8px;flex-wrap:wrap;align-items:flex-end">
      <div class="bx-field" style="margin:0;min-width:200px"><label>Online-Händler / Quelle</label><input type="text" name="on_haendler" placeholder="z. B. Amazon (nur bei „Online bestellt")"></div>
      <div class="bx-field" style="margin:0"><label>Zahlungsart</label>
        <select name="on_zahlart"><option value="">– wählen –</option><?php foreach (bestellung_zahlarten() as $zk => $zl): ?><option value="<?= h($zk) ?>"><?= h($zl) ?></option><?php endforeach; ?></select>
      </div>
      <div class="bx-field" style="margin:0;max-width:150px"><label>Preis gesamt netto (optional)</label><input type="text" name="on_preis" inputmode="decimal" placeholder="leer = folgt mit Rechnung"></div>
      <div class="bx-field" style="margin:0;max-width:90px"><label>Währung</label><input type="text" name="on_waehrung" value="EUR" maxlength="8"></div>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-4);gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary" type="submit" name="modus" value="lieferant" title="Nur bei Lieferant mit aktivem Account und Preis">Über Lieferant (Portal)</button>
      <button class="btn btn-ghost" type="submit" name="modus" value="direkt" title="Beim Lieferanten per Telefon/Mail bestellt – keine Mail, keine Bestätigung">Direkt bestellt (Telefon/Mail)</button>
      <button class="btn btn-ghost" type="submit" name="modus" value="online">Online bestellt</button>
    </div>
    <div class="bx-fehlhinweis vs-hinweis" hidden></div>
    <div class="bx-row" style="margin-top:10px;gap:8px;flex-wrap:wrap;align-items:flex-end">
      <div class="bx-field" style="margin:0;max-width:170px"><label>Direkt: bestellt am</label><input type="date" name="di_datum" value="<?= date('Y-m-d') ?>"></div>
      <div class="bx-field" style="margin:0;max-width:170px"><label>Direkt: erwartet am</label><input type="date" name="di_eta"></div>
      <div class="bx-field" style="margin:0;min-width:240px;flex:1"><label>Direkt: Notiz</label><input type="text" name="di_notiz" placeholder="z. B. telefonisch mit Herrn X"></div>
    </div>
    <p class="muted" style="font-size:12px;margin:10px 0 0">„Über Lieferant (Portal)" braucht einen Lieferanten mit aktivem Account und Preis. „Direkt bestellt" braucht nur den Lieferanten (Preis aus dem Lieferantenpreis, keine Mail). „Online bestellt" braucht den Händler; Preis (netto) und Zahlungsart sind optional.</p>
  </div>
</form>
<script>(function(){
  var boxes=document.querySelectorAll('.bx-sel'), btn=document.getElementById('btnBestellen'), bex=document.getElementById('btnOnline'), bdi=document.getElementById('btnDirekt'), a=document.getElementById('selAll');
  var onH=document.getElementById('onHaendler'), onP=document.getElementById('onPreis'), onHw=document.getElementById('onlineHinweis'), diHw=document.getElementById('direktHinweis');
  // Gleiche Regeln wie der Server (Nico 08.11.2026: Fehlendes gar nicht erst abschicken lassen, statt danach einen
  // leicht zu übersehenden Hinweis zu zeigen). Online: Händler + Preis > 0. Direkt: jede angehakte Zeile braucht einen Lieferanten.
  function preisOk(v){ var r=(v||'').replace(/[^0-9.,]/g,'').replace(/\./g,'').replace(',', '.'); var f=parseFloat(r); return isFinite(f) && f>0; }
  function onlineFehlt(){ var f=[]; if(!onH || onH.value.trim()==='') f.push('Händler / Quelle'); return f; }   // Preis optional
  var bds=document.getElementById('btnDirektSchnell'), onHAuto=false;
  function etikettVorbelegen(){   // nur Etiketten angehakt + Händler leer (oder von uns vorbelegt) -> Labelisten
    if(!onH) return; var any=false, nurEt=true;
    boxes.forEach(function(c){ if(!c.checked) return; any=true; if(!c.dataset.etikett) nurEt=false; });
    var v=onH.getAttribute('data-etikett-lief')||'';
    if(any && nurEt && v && (onH.value.trim()==='' || onHAuto)){ onH.value=v; onHAuto=true; }
    else if(onHAuto && (!any || !nurEt)){ onH.value=''; onHAuto=false; }
  }
  if(onH) onH.addEventListener('input', function(){ onHAuto=false; });
  function direktFehlt(){ var n=0; boxes.forEach(function(c){ if(!c.checked) return; var s=document.querySelector('select[name="lief['+c.value+']"]'); if(!s || !s.value) n++; }); return n; }
  function hinweis(el, txt){ if(!el) return; el.hidden=!txt; el.textContent=txt||''; }
  function upd(){
    var any=false; boxes.forEach(function(c){if(c.checked)any=true;});
    etikettVorbelegen();
    if(btn)btn.disabled=!any;
    var of=onlineFehlt(); if(bex) bex.disabled=!any || of.length>0;
    hinweis(onHw, any && of.length ? 'Noch nicht bestellbar – es fehlt: ' + of.join(' und ') + '.' : '');
    var dn=direktFehlt(); if(bdi) bdi.disabled=!any || dn>0; if(bds) bds.disabled=!any || dn>0;
    hinweis(diHw, any && dn ? 'Noch nicht bestellbar – bei ' + dn + ' angehakten Zeile(n) fehlt der Lieferant.' : '');
  }
  boxes.forEach(function(c){c.addEventListener('change',upd);});
  [onH,onP].forEach(function(el){ if(el){ el.addEventListener('input',upd); el.addEventListener('change',upd); } });
  document.querySelectorAll('select[name^="lief["]').forEach(function(s){ s.addEventListener('change',upd); });
  // Auch Enter im Feld oder ein anderer Weg darf nicht unvollständig absenden.
  // Capture auf document und VOR dem allgemeinen Lade-Anzeiger (render_footer) registriert: gestoppt wird ganz, sonst
  // bliebe der Knopf mit Spinner stehen.
  var bf = bex ? bex.form : null;
  if(bf) document.addEventListener('submit', function(ev){
    if(ev.target!==bf) return;
    var m = ev.submitter ? ev.submitter.value : '', stopp=false;
    if(m==='online' && onlineFehlt().length){ stopp=true; var d=document.getElementById('onlineBox'); if(d) d.open=true; }
    if(m==='direkt' && direktFehlt()) stopp=true;
    if(stopp){ ev.preventDefault(); ev.stopImmediatePropagation(); upd(); var h=m==='online'?onHw:diHw; if(h) h.scrollIntoView({block:'center'}); }
  }, true);
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
<script>(function(){
  // Bulk-Zeilen: die Summe folgt dem GEWÄHLTEN Lieferanten. Ohne Auswahl: günstigster Preis („ab").
  // Preis immer 4 Nachkommastellen + Währung (USD/EUR/CNY je Lieferant), Summe 2 Nachkommastellen.
  function parseMenge(v){ var r=(v||'').replace(/[^0-9.,]/g,'').replace(/\./g,'').replace(',', '.'); var f=parseFloat(r); return isFinite(f)?f:0; }
  function fmt(n, dez){ try { return n.toLocaleString('de-DE', {minimumFractionDigits:dez, maximumFractionDigits:dez}); } catch(e){ return n.toFixed(dez).replace('.', ','); } }
  document.querySelectorAll('select.bx-bulk-lief').forEach(function(sel){
    var row = sel.closest('tr'); if(!row) return;
    var menge = row.querySelector('input[name^="menge["]');
    var sum   = row.querySelector('.bx-bulk-sum'); if(!sum) return;
    function upd(){
      var m = menge ? parseMenge(menge.value) : 0;
      var opt = sel.options[sel.selectedIndex], preis, cur, praefix;
      if (sel.value && opt && opt.dataset.preis){ preis=parseFloat(opt.dataset.preis); cur=opt.dataset.cur||'EUR'; praefix=''; }
      else { preis=parseFloat(sel.dataset.ab||''); cur=sel.dataset.abcur||'EUR'; praefix='ab '; }
      if (!isFinite(preis)){ sum.textContent=''; return; }
      var einh = sel.dataset.einheit || 'Stk';
      sum.textContent = praefix + fmt(preis,4) + ' ' + cur + '/' + einh + ' · Summe ' + fmt(preis*m,2) + ' ' + cur;
    }
    sel.addEventListener('change', upd);
    if (menge){ menge.addEventListener('input', upd); menge.addEventListener('change', upd); }
    upd();
  });
})();</script>
<style>.bx-fehlhinweis{margin-top:8px;color:var(--err);font-size:13px;font-weight:600}.bx-fehlhinweis[hidden]{display:none}</style>
<script>(function(){
  // „Vorsorglich bestellen": Online braucht Händler + Preis, Direkt einen Lieferanten – sonst gar nicht absenden.
  var f=document.querySelector('form input[name="aktion"][value="vorsorglich"]'); f=f?f.form:null; if(!f) return;
  var hw=f.querySelector('.vs-hinweis');
  function preisOk(v){ var r=(v||'').replace(/[^0-9.,]/g,'').replace(/\./g,'').replace(',', '.'); var x=parseFloat(r); return isFinite(x) && x>0; }
  document.addEventListener('submit', function(ev){
    if(ev.target!==f) return;
    var m = ev.submitter ? ev.submitter.value : '', fehlt=[];
    if(m==='online'){ if(f.on_haendler.value.trim()==='') fehlt.push('Online-Händler / Quelle'); }
    if(m==='direkt' && !f.lieferant_id.value) fehlt.push('Lieferant');
    if(fehlt.length){ ev.preventDefault(); ev.stopImmediatePropagation(); hw.hidden=false; hw.textContent='Nicht bestellt – es fehlt: ' + fehlt.join(' und ') + '.'; hw.scrollIntoView({block:'center'}); }
    else hw.hidden=true;
  }, true);
})();</script>
<?php render_footer(); ?>
