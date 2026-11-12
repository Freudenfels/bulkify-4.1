<?php
// Vollwertiger Wareneingang (Warenlager-Manager). Ein Bereich fuer ALLES, was reinkommt:
//   Schritt 0  Ziel: Lager 1 (eigener Bestand) ODER Lager 2 (Kundenware -> Kunde).
//   Schritt 1  Lieferschein scannen (Webcam-Foto oder Datei/PDF) -> KI liest Lieferant + Positionen.
//   Schritt 2  Positionen pruefen/ergaenzen (Artikel, Warenart, Menge, Charge, MHD, Blinker, Pakete).
//              Pflichtfelder je Warenart (erp_warenart_regeln): Rohstoff/Fertig/Kapsel = MHD+Charge
//              Pflicht + Quarantaene; Verpackung/Verbrauch = kein MHD, sofort frei.
//   Schritt 3  Alle buchen -> je Position eine Charge + Blinker + Karton-Etiketten.
//
// KI ist im Lager self-contained (lager/core/ki.php). Tabellen-Zugriffe nur ueber erp.php (Naht).
require_once __DIR__ . '/../../core/ki.php';

// --- AJAX: Lieferschein auslesen -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'scan') {
    header('Content-Type: application/json; charset=utf-8');
    if (!lg_ki_bereit()) { echo json_encode(['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (kein Anthropic-Schluessel in secrets.php).']); exit; }
    $pfade = [];
    $tmpdir = sys_get_temp_dir();
    if (!empty($_FILES['dateien']['tmp_name']) && is_array($_FILES['dateien']['tmp_name'])) {
        foreach ($_FILES['dateien']['tmp_name'] as $i => $tmp) {
            if (!is_uploaded_file($tmp)) continue;
            $name = (string)($_FILES['dateien']['name'][$i] ?? 'bild.jpg');
            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'jpg';
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true)) $ext = 'jpg';
            $ziel = $tmpdir . '/lsin_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (@move_uploaded_file($tmp, $ziel)) $pfade[] = $ziel;
        }
    }
    if (!$pfade) { echo json_encode(['ok' => false, 'fehler' => 'Keine Datei empfangen.']); exit; }
    $r = lg_lieferschein_lesen($pfade);
    foreach ($pfade as $p) @unlink($p);
    if (!$r['ok']) { echo json_encode($r); exit; }
    $pos = [];
    // Lieferant direkt finden oder anlegen -> wird im Formular vorausgewählt und beim Buchen verknüpft.
    $lid = erp_lieferant_finden_oder_anlegen((string)($r['lieferant'] ?? ''));
    // Einkauf gerade ziehen (Teil 1): jede erkannte Position der offenen Bestellung zuordnen (gleicher Artikel, bevorzugt Lieferant).
    foreach ($r['positionen'] as $p) { $z = erp_position_zuordnen($p); if ((int)($z['item_id'] ?? 0) > 0 && ($bp = erp_offene_bp_fuer_item((int)$z['item_id'], (int)$lid))) $z += $bp; $pos[] = $z; }
    echo json_encode([
        'ok'  => true,
        'kopf' => ['lieferant' => $r['lieferant'], 'lieferant_id' => $lid, 'ls_nr' => $r['ls_nr'], 'auftrag_nr' => ($r['auftrag_nr'] ?? ''), 'datum' => $r['datum']],
        'positionen' => $pos,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- AJAX: Versandlabel/Tracking scannen -> passende Lieferung + Positionen ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'tracking') {
    header('Content-Type: application/json; charset=utf-8');
    $r = erp_lieferung_per_tracking((string)($_POST['code'] ?? ''));
    if (!$r['ok']) { echo json_encode(['ok' => false, 'fehler' => 'Keine erwartete Lieferung zu dieser Sendungsnummer gefunden.']); exit; }
    $pos = [];
    foreach ($r['positionen'] as $p) $pos[] = erp_position_zuordnen($p);
    echo json_encode([
        'ok'  => true,
        'kopf' => ['id' => (int)($r['id'] ?? 0), 'lieferant' => $r['lieferant'], 'lieferant_id' => $r['lieferant_id'], 'nummer' => $r['nummer']],
        'positionen' => $pos,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- AJAX: eine erwartete Lieferung aus der Liste wählen -> Positionen -----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'lieferung') {
    header('Content-Type: application/json; charset=utf-8');
    $r = erp_lieferung_positionen((int)($_POST['id'] ?? 0));
    if (!$r['ok']) { echo json_encode(['ok' => false, 'fehler' => 'Lieferung nicht gefunden.']); exit; }
    $pos = [];
    foreach ($r['positionen'] as $p) $pos[] = erp_position_zuordnen($p);
    echo json_encode([
        'ok'  => true,
        'kopf' => ['id' => (int)($r['id'] ?? 0), 'lieferant' => $r['lieferant'], 'lieferant_id' => $r['lieferant_id'], 'nummer' => $r['nummer']],
        'positionen' => $pos,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- AJAX: Rezepturnummer-Aufkleber (R…) scannen -> Rezeptur/Produkt vorausgefüllt (Spec 5.2) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rsticker') {
    header('Content-Type: application/json; charset=utf-8');
    $r = function_exists('erp_rezeptur_per_nummer') ? erp_rezeptur_per_nummer((string)($_POST['code'] ?? '')) : null;
    if (!$r) { echo json_encode(['ok' => false, 'fehler' => 'Keine Rezeptur zu dieser Nummer gefunden.']); exit; }
    $pos = erp_position_zuordnen($r);
    if ((int)($pos['item_id'] ?? 0) > 0 && ($bp = erp_offene_bp_fuer_item((int)$pos['item_id']))) $pos += $bp;   // Teil 1: offene Bestellung dazu
    echo json_encode(['ok' => true, 'position' => $pos], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- AJAX: Lieferanten-Gebindeaufkleber (BX…) scannen / Abschluss (Paket 12.11.2026 Teil 5) ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['aktion'] ?? ''), ['la_scan', 'la_abschluss'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $wer = (string)(lg_benutzer()['name'] ?? '');
    $r = $_POST['aktion'] === 'la_scan' ? erp_la_scan((string)($_POST['code'] ?? ''), $wer) : erp_la_abschluss((int)($_POST['bestellung_id'] ?? 0), $wer);
    if (isset($r['stand'])) $r['stand'] = ['erfasst' => (int)$r['stand']['erfasst'], 'gesamt' => (int)$r['stand']['gesamt'],
                                          'fehlt' => array_map(fn($x) => la_code((int)$x['id']) . ' · ' . rtrim(rtrim(number_format((float)$x['netto_kg'], 3, ',', '.'), '0'), ',') . ' kg', $r['stand']['fehlt'] ?? [])];
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- AJAX: offene Bestellposition zu einem Artikel (Etiketten einbuchen, Teil 1) ------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'bp_fuer_item') {
    header('Content-Type: application/json; charset=utf-8');
    $bp = erp_offene_bp_fuer_item((int)($_POST['item_id'] ?? 0));
    echo json_encode(['ok' => (bool)$bp, 'bp' => $bp], JSON_UNESCAPED_UNICODE);
    exit;
}
// --- AJAX: Etiketten einbuchen – Produkt gewählt -> Kundenetiketten (Kunde/Version, Design, Maße) -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'etikett_produkt') {
    header('Content-Type: application/json; charset=utf-8');
    $iid = (int)($_POST['item_id'] ?? 0);   // Etikett ohne Produkt (Eintrag direkt am Artikel)
    $pid = (int)($_POST['produkt_id'] ?? 0);
    echo json_encode([
        'ok' => true,
        'etiketten' => $iid ? erp_etikett_artikel($iid) : erp_etikett_zu_produkt($pid),
        'glas' => $pid > 0 ? erp_produkt_etikettmass($pid) : null,   // maßgebliches Endformat aus dem Behälter
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
// --- AJAX: Anderes Etikett von Hand anlegen (Kunde/Produkt optional) -> direkt zur Sichtprüfung --------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'etikett_anlegen') {
    header('Content-Type: application/json; charset=utf-8');
    $r = erp_etikett_anlegen($_POST);
    if (!empty($r['ok'])) {
        $r['etiketten'] = erp_etikett_artikel((int)$r['id']);
        $pid = (int)($_POST['produkt_id'] ?? 0);
        $r['glas'] = $pid > 0 ? erp_produkt_etikettmass($pid) : null;
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Buchen: alle Positionen ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'buchen') {
    $ziel     = ($_POST['ziel'] ?? 'l1') === 'l2' ? 'l2' : 'l1';
    $kunde_id = (int)($_POST['kunde_id'] ?? 0);
    $lief     = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
    // Kein Lieferant gewählt, aber beim Scan einer erkannt -> finden oder neu anlegen (Nachverfolgbarkeit).
    if (!$lief && trim((string)($_POST['lieferant_name'] ?? '')) !== '') {
        $lief = erp_lieferant_finden_oder_anlegen((string)$_POST['lieferant_name']) ?: null;
    }
    if ($ziel === 'l2' && $kunde_id <= 0) { flash('Lager 2: bitte den Kunden wählen, dem die Ware gehört.', 'warn'); weiter('?p=we'); }
    $auftragNr = trim((string)($_POST['auftrag_nr'] ?? ''));
    $kisteId   = (int)($_POST['kiste_id'] ?? 0);   // optional: alle Positionen in diese Kiste
    $fachG     = trim((string)($_POST['fach'] ?? ''));

    $names   = (array)($_POST['p_name'] ?? []);
    $gebucht = [];
    $fehler  = [];
    // Einkauf gerade ziehen (Teil 1/2): Ware hängt an einer Bestellposition. Ohne Bestellung nur mit Grund (Übergangsphase).
    require_once __DIR__ . '/../../../core/wareneingang_bestellung.php';
    webe_spalten();
    $weGrund = (string)($_POST['we_grund'] ?? '');
    $weGrundNotiz = trim((string)($_POST['we_grund_notiz'] ?? ''));
    if ($ziel === 'l2') $weGrund = 'kundenware';
    $bpMap = [];        // charge_id => bestellung_position_id
    $ohneBest = [];     // charge_ids ohne Bestellung
    $lgU = function_exists('lg_benutzer') ? (string)((lg_benutzer()['name'] ?? '')) : '';
    foreach ($names as $i => $nm) {
        $name     = trim((string)$nm);
        $key      = (string)($_POST['p_key'][$i] ?? (string)$i);   // stabiler Zeilenschlüssel (für 2D-Rollen-Felder)
        $item_id  = (int)($_POST['p_item'][$i] ?? 0);
        $warenart = (string)($_POST['p_warenart'][$i] ?? 'rohstoff');
        $menge    = erp_menge_parse((string)($_POST['p_menge'][$i] ?? '0'));
        $einheit  = trim((string)($_POST['p_einheit'][$i] ?? ''));
        $charge   = trim((string)($_POST['p_charge'][$i] ?? ''));
        $artnr    = trim((string)($_POST['p_artnr'][$i] ?? ''));
        $mhd      = trim((string)($_POST['p_mhd'][$i] ?? ''));
        $blinker  = led_leiste_normalisieren((string)($_POST['p_blinker'][$i] ?? ''));
        $pakete   = max(1, (int)($_POST['p_pakete'][$i] ?? 1));
        // Paket-/Sendungsnummern je Karton (eine je Zeile) – nur zur Rueckverfolgung/Vollstaendigkeit.
        $paketNrn = array_values(array_filter(array_map('trim',
            preg_split('/[\r\n]+/', (string)($_POST['p_paketnummern'][$i] ?? ''))), fn($x) => $x !== ''));
        if ($paketNrn) $pakete = max($pakete, count($paketNrn));   // so viele Kartons wie Nummern
        // Soll-Kette 2a (04.11.2026): Haken „Freigegeben“ je Position ist Standard; abgehakt = Problem -> Quarantäne.
        $statusP  = (string)($_POST['p_frei'][$i] ?? '1') === '0' ? 'quarantaene' : 'auto';
        $rezeptur_id = (int)($_POST['p_rezeptur'][$i] ?? 0);   // nur bei Warenart 'fertig' relevant
        if ($name === '' && $menge <= 0 && $rezeptur_id <= 0) continue;   // leere Zeile
        // Teil 1: Bestellposition der Zeile. Artikel kommt fest aus der Position (ein Bulk-Artikel bleibt über die Rezeptur gekoppelt).
        $bpZ = (int)($_POST['p_bp'][$i] ?? 0);
        $bpRow = $bpZ > 0 ? one("SELECT bp.id, bp.item_id, bp.bestellung_id, i.kategorie FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id LEFT JOIN item i ON i.id=bp.item_id
                                  WHERE bp.id=? AND b.status<>'storniert'", [$bpZ]) : null;
        if ($bpZ > 0 && !$bpRow) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Die Bestellposition gibt es nicht mehr – bitte neu wählen.'; continue; }
        if ($bpRow && (int)$bpRow['item_id'] > 0 && (string)$bpRow['kategorie'] !== 'fertig') $item_id = (int)$bpRow['item_id'];
        if (!$bpRow) {
            if (!isset(webe_gruende()[$weGrund])) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Keine Bestellung gewählt – bitte die Bestellung wählen oder den Grund „ohne Bestellung" angeben. Nicht gebucht.'; continue; }
            if ($weGrund === 'alt_bestellung' && !$lief) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Alte Bestellung: bitte den Lieferanten angeben. Nicht gebucht.'; continue; }
            if (in_array($weGrund, ['muster', 'sonstiges'], true) && $weGrundNotiz === '') { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Bei „' . webe_gruende()[$weGrund] . '" ist eine Notiz Pflicht. Nicht gebucht.'; continue; }
        }

        // Warenart -> echte Zuordnung (kategorie + form). Leerkapseln=rohstoff/kapselhuelle, fertige Kapseln=fertig.
        $defs = function_exists('erp_warenart_defs') ? erp_warenart_defs() : [];
        $def  = $defs[$warenart] ?? ['kategorie' => 'rohstoff', 'form' => ''];

        // Schutz (S-21): ein Rezeptur-/Bulk-Artikel (fertig + Rezeptur) wird nie zu Verpackung/Etikett/Rohstoff
        // umgewidmet – weder durch eine falsch gewählte Warenart noch durch eine falsch zugeordnete Position.
        if ($item_id > 0 && $warenart !== 'fertig') {
            $bulkChk = one("SELECT kategorie, rezeptur_id FROM item WHERE id=?", [$item_id]);
            if ($bulkChk && (string)$bulkChk['kategorie'] === 'fertig' && !empty($bulkChk['rezeptur_id'])) {
                $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Das ist ein Bulk-Artikel einer Rezeptur – er kann nicht als „' . h((string)($defs[$warenart]['label'] ?? $warenart)) . '“ eingebucht werden. Bitte den richtigen Artikel bzw. die Warenart „Bulk / lose“ wählen.';
                continue;
            }
        }

        // --- Etikett (Rolle): kein Blinker – je Rolle ein Inventaraufkleber (8 Ziffern) ---------------
        if ($warenart === 'etikett') {
            if (!$item_id && $name !== '') $item_id = (int) erp_item_anlegen($name, 'verpackung', $einheit ?: 'Stk', 'etikett');
            if (!$item_id) { $fehler[] = 'Zeile ' . ($i + 1) . ': kein Artikel.'; continue; }
            if (function_exists('erp_item_warenart_setzen')) erp_item_warenart_setzen($item_id, 'etikett');
            if ($menge <= 0) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Menge fehlt.'; continue; }
            // Nur noch: Gesamtmenge + Anzahl Rollen. Menge wird gleichmäßig verteilt (Rest auf die
            // letzte Rolle), Inventarnummern optional gescannt – leer = automatisch. Keine Summenprüfung.
            $anzahl  = max(1, (int)($_POST['p_rollen_zahl'][$key] ?? 1));
            $nummern = array_values((array)($_POST['p_rolle_nr'][$key] ?? []));
            $notiz = 'Wareneingang' . ($ziel === 'l2' ? ' (Kundenware)' : '') . ' · Etikettenrollen';
            if ($artnr !== '')     $notiz .= ' · Art.-Nr. ' . $artnr;
            if ($auftragNr !== '') $notiz .= ' · Auftrag ' . $auftragNr;
            $cid = $ziel === 'l2'
                ? erp_wareneingang_buchen_fremd($item_id, $menge, '', null, $kunde_id, $notiz, $einheit)
                : erp_wareneingang_buchen($item_id, $menge, '', null, $lief, $notiz, 'auto', $einheit);
            if (!$cid) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Buchen fehlgeschlagen.'; continue; }
            $pAuftragId = (int)($_POST['p_auftrag_id'][$i] ?? 0);
            if ($ziel !== 'l2' && $pAuftragId > 0 && function_exists('erp_charge_auftrag_setzen')) erp_charge_auftrag_setzen((int)$cid, $pAuftragId);
            $rr = erp_etikett_rollen_verteilen((int)$cid, $menge, $anzahl, $nummern);
            lg_pakete_set((int)$cid, max(1, (int)($rr['angelegt'] ?? $anzahl)));
            $cc = erp_charge((int)$cid);
            lg_bewegung_log((int)$cid, 'ein', $menge, $cc['einheit'] ?? null, (string)($cc['item_name'] ?? ''), $notiz . ' (' . (int)$rr['angelegt'] . ' Rolle(n))');
            if (!empty($rr['fehler'])) $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): ' . implode(' · ', $rr['fehler']);
            $gebucht[] = (int)$cid;
            if ($bpRow) $bpMap[(int)$cid] = (int)$bpRow['id']; else $ohneBest[] = (int)$cid;
            continue;
        }

        // Fertige Kapseln (Bulk): aufs kanonische Bulk-Item der gewählten Rezeptur buchen
        // (statt einen losen Artikel per Name anzulegen). Fehlt das Bulk-Item -> sauber abbrechen.
        if ($warenart === 'fertig' && $rezeptur_id > 0) {
            $bulk = function_exists('erp_rezeptur_bulkitem') ? erp_rezeptur_bulkitem($rezeptur_id) : null;
            if (!$bulk) { $fehler[] = 'Zeile ' . ($i + 1) . ': Für diese Rezeptur gibt es noch kein Bulk-Lagerartikel – bitte erst im Dashboard anlegen.'; continue; }
            $item_id = (int)$bulk;
        }
        // Bulk/Fertigware MUSS einer Rezeptur zugeordnet werden (sonst entsteht ein loser Ad-hoc-Artikel,
        // der am Rezeptur-/Produkt-Bulk-Artikel nicht als Bestand erfasst wird). Ausnahme: die Position kommt
        // schon mit einem bestehenden Artikel (aus einer Bestellung/„aus Liste") -> $item_id ist dann gesetzt.
        // Soll-Kette 2c (04.11.2026): Bulkware NUR auf den Bulk-Artikel einer Rezeptur. Ein gewählter loser Bulk-Artikel
        // (ohne Rezeptur, z. B. „… – Bulk #0“) wird über den Auftrag der Position auf die Rezeptur umgelenkt, sonst abgelehnt.
        if ($warenart === 'fertig' && $rezeptur_id <= 0 && $item_id) {
            $chk = one("SELECT rezeptur_id FROM item WHERE id=?", [$item_id]);
            if ($chk && empty($chk['rezeptur_id'])) {
                $paufR = (int)($_POST['p_auftrag_id'][$i] ?? 0);
                $rzA = $paufR > 0 ? (int) scalar("SELECT COALESCE(a.rezeptur_id, p.rezeptur_id) FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$paufR]) : 0;
                $biA = $rzA > 0 ? erp_rezeptur_bulkitem($rzA) : null;
                if (!$biA) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Bulkware nur auf den Bulk-Artikel einer Rezeptur – bitte die Rezeptur wählen (dieser Artikel hat keine).'; continue; }
                $item_id = (int)$biA;
            }
        }
        if ($warenart === 'fertig' && $rezeptur_id <= 0 && !$item_id) {
            $fehler[] = 'Zeile ' . ($i + 1) . ': Für Bulk/Fertigware bitte die Rezeptur wählen – die Ware wird dem Rezeptur-Artikel zugeordnet (kein loser Artikel).';
            continue;
        }
        // Artikel bestimmen. Soll-Kette 2b (04.11.2026): Standard = vorhandener Artikel (auch per Name gefunden);
        // ein komplett neuer Artikel nur über den Knopf „Neuer Artikel“ (p_neu=1), Rohstoff dann mit Beschaffenheit.
        $neuAngelegt = false;
        if (!$item_id && $name !== '') {
            $vorhanden = (int) scalar("SELECT id FROM item WHERE name=? ORDER BY (kategorie=?) DESC, id LIMIT 1", [$name, $def['kategorie']]);
            if ($vorhanden) $item_id = $vorhanden;
            else {
                if ((string)($_POST['p_neu'][$i] ?? '0') !== '1') { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Diesen Artikel gibt es nicht – bitte aus der Liste wählen oder über „Neuer Artikel“ anlegen.'; continue; }
                $formNeu = (string)($_POST['p_form'][$i] ?? '');
                $formen = ['pulver', 'granulat', 'extrakt', 'fluessig', 'oel', 'paste', 'kristallin'];
                if ($warenart === 'rohstoff' && !in_array($formNeu, $formen, true)) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Beschaffenheit des neuen Rohstoffs fehlt (Pulver, Granulat …).'; continue; }
                $item_id = (int) erp_item_anlegen($name, $def['kategorie'], $einheit);
                if ($item_id && $warenart === 'rohstoff') q("UPDATE item SET form=? WHERE id=?", [$formNeu, $item_id]);
                $neuAngelegt = $item_id > 0;
            }
        }
        if (!$item_id) { $fehler[] = 'Zeile ' . ($i + 1) . ': kein Artikel.'; continue; }

        // Eingegebene Warenart hat VORRANG: ans Item schreiben (Kategorie + ggf. Form).
        if (isset($defs[$warenart]) && function_exists('erp_item_warenart_setzen')) erp_item_warenart_setzen($item_id, $warenart);
        $basis = erp_item_basis($item_id);
        $regeln = erp_warenart_regeln($def['kategorie'], $def['form'] ?: (string)($basis['form'] ?? ''));
        // Leerkapseln: Kapselgröße ist Pflicht und gehört an den Artikel (Nico 04.11.2026) – gewählt, sonst aus dem Namen.
        if ($warenart === 'leerkapsel') {
            $kgr = (int)($_POST['p_kapselgroesse'][$i] ?? 0) ?: erp_kapselgroesse_aus_name($name);
            if ($kgr <= 0) $kgr = (int) scalar("SELECT COALESCE(kapselgroesse_id,0) FROM item WHERE id=?", [$item_id]);
            if ($kgr <= 0) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Kapselgröße fehlt.'; continue; }
            q("UPDATE item SET kapselgroesse_id=? WHERE id=?", [$kgr, $item_id]);
        }
        if ($menge <= 0)                           { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Menge fehlt.'; continue; }
        if ($kisteId <= 0 && $blinker === null)    { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Blinker oder Kiste wählen.'; continue; }
        if ($regeln['mhd_pflicht'] && $mhd === '')    { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): MHD ist Pflicht.'; continue; }
        if ($regeln['charge_pflicht'] && $charge === '') { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Charge-Nr. ist Pflicht.'; continue; }

        // Gewicht & Bestand pro Gebinde (04.11.2026): je Karton/Sack die echte Menge (Vorschlag gleichmäßig, Abweichler
        // geändert). Die Summe muss zur Gesamtmenge passen – sonst wird die Position NICHT gebucht.
        $gebM = $pakete > 1 ? array_values(array_map(fn($v) => erp_menge_parse((string)$v), (array)($_POST['p_geb'][$key] ?? []))) : [$menge];
        $fmtM = fn(float $v): string => rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ',');
        if (count($gebM) !== $pakete) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Menge je Karton fehlt (' . count($gebM) . ' von ' . $pakete . ' Kartons) – nicht gebucht.'; continue; }
        if (min($gebM) <= 0) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Jeder Karton braucht eine Menge größer 0 – nicht gebucht.'; continue; }
        if (abs(array_sum($gebM) - $menge) > 0.0005) {
            $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Summe der Kartons ' . $fmtM(array_sum($gebM)) . ' ' . h($einheit) . ' passt nicht zur Gesamtmenge ' . $fmtM($menge) . ' ' . h($einheit)
                      . ' (Differenz ' . $fmtM(abs(array_sum($gebM) - $menge)) . ') – nicht gebucht.';
            continue;
        }

        $notiz = 'Wareneingang' . ($ziel === 'l2' ? ' (Kundenware)' : '');
        $zusatz = [];
        if ($artnr !== '')    $zusatz[] = 'Art.-Nr. ' . $artnr;
        if ($auftragNr !== '') $zusatz[] = 'Auftrag ' . $auftragNr;
        if ($zusatz) $notiz .= ' · ' . implode(' · ', $zusatz);
        $cid = $ziel === 'l2'
            ? erp_wareneingang_buchen_fremd($item_id, $menge, $charge, $mhd ?: null, $kunde_id, $notiz, $einheit)
            : erp_wareneingang_buchen($item_id, $menge, $charge, $mhd ?: null, $lief, $notiz, $statusP, $einheit);
        if (!$cid) { $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): Buchen fehlgeschlagen.'; continue; }

        // Charge mit dem Auftrag verknüpfen (nur Lager 1) -> Produktion erkennt „Ware da" UND die
        // Kunden-Statusleiste springt auf „Rohstoff angekommen". Quelle: die gewählte Lieferung.
        $pAuftragId = (int)($_POST['p_auftrag_id'][$i] ?? 0);
        // Absicherung: manuell gebucht (keine Lieferung gewählt)? Dann eindeutig offenem Auftrag zuordnen.
        if ($ziel !== 'l2' && $pAuftragId <= 0 && function_exists('erp_auftrag_fuer_bulkitem'))
            $pAuftragId = erp_auftrag_fuer_bulkitem($item_id);
        if ($ziel !== 'l2' && $pAuftragId > 0 && function_exists('erp_charge_auftrag_setzen'))
            erp_charge_auftrag_setzen((int)$cid, $pAuftragId);
        // Soll-Kette 2d: Bulk ohne ableitbare Kapselgröße -> Auswahl aus dem Wareneingang an die Charge (sonst bleibt sie leer
        // und erscheint in der Datenprüfung „Bulk-Charge ohne Kapselgröße“).
        $kgManuell = (int)($_POST['p_kapselgroesse'][$i] ?? 0);
        if ($warenart === 'fertig' && $kgManuell > 0 && function_exists('erp_charge_kapselgroesse_setzen')
            && (int) scalar("SELECT COALESCE(kapselgroesse_id,0) FROM charge WHERE id=?", [(int)$cid]) === 0)
            erp_charge_kapselgroesse_setzen((int)$cid, $kgManuell);

        lg_pakete_set((int)$cid, $pakete);
        // Je Karton ein Gebinde mit seiner echten Menge (in der Einheit der Charge – g-Eingaben wurden ggf. in kg umgerechnet).
        // Das Etikett druckt dann je Karton sein Gewicht, der QR führt auf genau dieses Gebinde.
        $cMenge = (float) scalar("SELECT menge_verfuegbar FROM charge WHERE id=?", [(int)$cid]);
        $fak = $menge > 0 ? $cMenge / $menge : 1.0;
        $gebC = array_map(fn($v) => round($v * $fak, 3), $gebM);
        $gebC[count($gebC) - 1] = round($cMenge - array_sum(array_slice($gebC, 0, -1)), 3);
        if (erp_charge_gebinde_mit_mengen((int)$cid, $gebC) === 0 && $pakete > 1)
            $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): gebucht, aber die Kartons konnten nicht einzeln angelegt werden (Charge hatte schon Gebinde).';
        lg_aufteilen_set((int)$cid, false);   // Aufteilen ersetzt durch Menge je Karton
        // Paketnummern je Position bevorzugt (eine je Karton), sonst das formweite Paketlabel.
        lg_tracking_set((int)$cid, $paketNrn ? implode("\n", $paketNrn) : (string)($_POST['tracking'] ?? ''));
        $c = erp_charge((int)$cid);
        lg_bewegung_log((int)$cid, 'ein', $menge, $c['einheit'] ?? null, (string)($c['item_name'] ?? ''), $notiz);
        if ($kisteId > 0) {
            // In die Kiste legen (deren Blinker dient zum Finden). Kein Einzel-Blinker binden,
            // sonst verweigert kiste_charge_zuordnen die Zuordnung.
            $kf = kiste_charge_zuordnen($kisteId, (int)$cid, $fachG);
            if ($kf !== '') $fehler[] = 'Zeile ' . ($i + 1) . ' (' . h($name) . '): ' . $kf;
        } elseif ($blinker !== null && leiste_binden($blinker, (int)$cid) === '') {
            $lr = leiste_per_code($blinker);
            if ($lr) leiste_finden((int)$lr['id'], 'gruen', 3, false);
        }
        $gebucht[] = (int)$cid;
        if ($bpRow) $bpMap[(int)$cid] = (int)$bpRow['id']; else $ohneBest[] = (int)$cid;
        if ($neuAngelegt && function_exists('erp_hinweis_neuer_artikel'))
            erp_hinweis_neuer_artikel((int)$item_id, $name, (string)($def['label'] ?? $warenart), (int)$cid, rtrim(rtrim(number_format($menge, 3, ',', '.'), '0'), ',') . ' ' . $einheit);
    }

    // Kam der Wareneingang aus einer gewählten Lieferung? Dann die Bestellung als „angekommen" markieren
    // -> die Kunden-Statusleiste springt automatisch auf „Rohstoff angekommen" (mit Datum).
    $beId = (int)($_POST['we_bestellung_id'] ?? 0);
    // Teil 1-3: je Bestellung die gebuchten Chargen an ihre Positionen; die Bestellung schließt sich, wenn alles da ist.
    // Teillieferung bleibt offen (Rest = unterwegs), Mehrlieferung nur Hinweis.
    $jeBest = [];
    foreach ($bpMap as $cidB => $bpB) $jeBest[(int) scalar("SELECT bestellung_id FROM bestellung_position WHERE id=?", [$bpB])][] = $cidB;
    $beRes = null;
    foreach ($jeBest as $bidB => $cids) {
        $rB = erp_bestellung_lieferung_erfassen($bidB, $cids, $bpMap);
        $nrB = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$bidB]);
        if (!$rB['komplett']) flash('Teillieferung ' . $nrB . ': ' . (int)$rB['offen'] . ' Position(en) noch offen – der Rest zählt weiter als unterwegs.', 'warn');
        else flash('Bestellung ' . $nrB . ' ist vollständig eingegangen und geschlossen.');
        if (!empty($rB['mehr'])) flash('Mehrlieferung bei ' . $nrB . ': mehr als bestellt eingegangen – der Überschuss ist freier Bestand.', 'warn');
        $beRes = $rB;
    }
    // Teil 2: ohne Bestellung. Alte Bestellung -> nachträgliche Bestellung (direkt bestellt, geliefert) + Prüfaufgabe; sonst Grund vermerken.
    foreach ($ohneBest as $cidO) {
        if ($weGrund === 'alt_bestellung' && $lief) {
            $nb = webe_nachtraegliche_bestellung($cidO, (int)$lief, $weGrundNotiz, $lgU);
            if ($nb) flash('Nachträgliche Bestellung ' . h((string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$nb])) . ' angelegt und verknüpft – Nico prüft Preis und Daten.');
        } else webe_ohne_bestellung_vermerken($cidO, $weGrund, $weGrundNotiz, $lgU);
    }
    // CoA kommt über die Bestellung an die Charge (Handbuch 4.4). Fehlt es noch: nur Hinweis, blockiert nichts.
    if ($beRes) {
        $ohneCoa = 0;
        foreach ($gebucht as $gcid) { $hat = false; foreach (erp_charge_bestell_dokumente((int)$gcid) as $dd) if ((string)$dd['typ'] === 'coa') { $hat = true; break; } if (!$hat) $ohneCoa++; }
        if ($ohneCoa) flash('Hinweis: Für ' . $ohneCoa . ' Charge(n) liegt noch kein CoA an der Bestellung. Wird es später dort hochgeladen, hängt es automatisch an der Charge.', 'warn');
    }

    if ($gebucht) flash(count($gebucht) . ' Position(en) eingebucht' . ($kisteId > 0 ? ' (in Kiste gelegt).' : '.') . ($fehler ? ' ' . count($fehler) . ' Hinweis(e).' : ''));
    if ($fehler)  flash(implode(' · ', $fehler), $gebucht ? 'warn' : 'warn');
    if (!$gebucht && !$fehler) flash('Nichts zu buchen – keine Position erfasst.', 'warn');
    weiter('?p=we' . ($gebucht ? '&gebucht=' . implode(',', $gebucht) : ''));
}

// --- Anzeige ----------------------------------------------------------------------------------
$kunden  = erp_fulfillment_kunden();
$liefers = erp_lieferanten();
$kisten  = function_exists('kiste_alle') ? kiste_alle() : [];
$items   = erp_items_eingang();
$rezepturen = function_exists('erp_rezeptur_liste') ? erp_rezeptur_liste() : [];   // für Fertigware/Bulk: Rezeptur-Picker
$ki      = lg_ki_bereit();
$erwartet = erp_erwartete_lieferungen();   // für Kachel "aus Liste wählen"
$etProdukte = erp_etikett_produkte();       // für Kachel "Etiketten einbuchen" (je Produkt + Kunde)
$etKunden   = erp_kunden_liste();            // „Anderes Etikett anlegen“
$etAlleProd = erp_produkt_auswahl();
$heute    = date('Y-m-d');

// Nach dem Buchen: Erfolgspanel mit Sammel-Etikett.
$gebucht = [];
if (isset($_GET['gebucht'])) foreach (explode(',', (string)$_GET['gebucht']) as $x) { $x = (int)$x; if ($x > 0) $gebucht[] = $x; }

kopf('Einbuchen', 'we');
seitenkopf('Einbuchen', 'Wähle, wie du einbuchen möchtest.',
    ($gebucht ? '<a class="btn btn-primary" href="?p=we">Weiteren Artikel einbuchen</a> ' : '')
    . '<a class="btn btn-ghost lg-nurdesktop" href="?p=bestand">Zum Bestand</a>');
flash_zeigen();

if ($gebucht):
    $ids = implode(',', $gebucht);
?>
<div class="bx-panel" style="border:1px solid var(--gruen);margin-bottom:var(--sp-5)">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--sp-3)">
    <h2 style="margin:0"><?= count($gebucht) ?> Position(en) eingebucht – Etiketten für die Kartons</h2>
    <span class="bx-row" style="gap:var(--sp-2);flex-wrap:wrap;align-items:center">
      <button type="button" class="btn btn-primary btn-sm" id="etkDruck">Direkt drucken</button>
      <a class="btn btn-ghost btn-sm" href="?p=etikett&ids=<?= h($ids) ?>" target="_blank">Öffnen</a>
      <a class="btn btn-ghost btn-sm" href="?p=we">Nächstes Einbuchen</a>
      <span id="etkDruckInfo" class="muted"></span>
    </span>
  </div>
  <embed id="etkEmbed" src="?p=etikett&ids=<?= h($ids) ?>" type="application/pdf" style="width:100%;height:340px;border:1px solid var(--line);border-radius:8px;background:#fff;margin-top:var(--sp-3)">
  <script>
  (function(){
    var druck=document.getElementById('etkDruck'), dinfo=document.getElementById('etkDruckInfo'), ids='<?= h($ids) ?>';
    druck.addEventListener('click',function(){
      dinfo.textContent='…';
      var fd=new FormData(); fd.append('ids',ids);
      fetch('?p=druck_job',{method:'POST',body:fd}).then(function(r){return r.json();})
        .then(function(j){ dinfo.textContent=j.ok?(j.meldung||'An den Drucker geschickt.'):('Fehler: '+(j.fehler||'')); })
        .catch(function(){ dinfo.textContent='Serverfehler.'; });
    });
  })();
  </script>
</div>
<?php endif; ?>

<form method="post" id="weForm" class="bx-form">
  <input type="hidden" name="aktion" value="buchen">
  <input type="hidden" name="we_bestellung_id" id="weBestellungId" value=""><?php /* gewählte Lieferung: markiert die Bestellung als angekommen bzw. teilgeliefert */ ?>

  <!-- Start: 4 Kacheln – wie einbuchen? -->
  <div id="weStart" class="we-kacheln">
    <button type="button" class="we-kachel we-kachel-haupt" data-weg="liste">
      <div class="we-kachel-t">Bestellung wählen<?= count($erwartet) ? ' <span class="lg-kachel-z">' . count($erwartet) . '</span>' : '' ?></div>
      <div class="we-kachel-s"><?= count($erwartet) ?> erwartete Lieferung(en) – suchen nach BE-Nr., Lieferant, Artikel oder scannen</div>
    </button>
    <button type="button" class="we-kachel" data-weg="schein">
      <div class="we-kachel-t">Lieferschein scannen</div>
      <div class="we-kachel-s">Foto oder PDF – die KI liest alles aus</div>
    </button>
    <button type="button" class="we-kachel" data-weg="tracking">
      <div class="we-kachel-t">Sendungsnummer</div>
      <div class="we-kachel-s">Paketlabel scannen – Lieferung wird geladen</div>
    </button>

    <button type="button" class="we-kachel" data-weg="aufkleber">
      <div class="we-kachel-t">Lieferanten-Aufkleber scannen</div>
      <div class="we-kachel-s">BX-Code je Gebinde scannen – Charge, MHD und Gewicht kommen vom Aufkleber</div>
    </button>
    <button type="button" class="we-kachel" data-weg="rsticker">
      <div class="we-kachel-t">Rezepturnummer-Aufkleber</div>
      <div class="we-kachel-s">R-Code scannen – Rezeptur/Produkt wird erkannt</div>
    </button>
    <button type="button" class="we-kachel" data-weg="etikett">
      <div class="we-kachel-t">Etiketten einbuchen</div>
      <div class="we-kachel-s">Produkt wählen – Design prüfen, dann Rollen buchen</div>
    </button>
    <a class="we-kachel" href="?p=l2_eingang" style="text-decoration:none">
      <div class="we-kachel-t">Kundenware (Lager 2)</div>
      <div class="we-kachel-s">Ware, die einem Kunden gehört, ins Fremdlager buchen</div>
    </a>
    <button type="button" class="we-kachel" data-weg="regulaer">
      <div class="we-kachel-t">Ohne Bestellung</div>
      <div class="we-kachel-s">Nur mit Grund: alte Bestellung, Muster, Sonstiges</div>
    </button>
  </div>

  <!-- Arbeitsbereich nach Kachel-Wahl -->
  <div id="weArbeit" hidden>
    <div style="margin-bottom:var(--sp-3)">
      <button type="button" class="btn btn-ghost btn-sm" id="weBack">← Andere Methode</button>
    </div>

    <!-- Methode: Lieferschein scannen/fotografieren -->
    <div class="bx-panel we-weg" data-w="schein" hidden>
      <h2 style="margin-top:0">Lieferschein scannen</h2>
      <?php if (!$ki): ?>
        <div class="bx-panel warn" style="margin:0 0 var(--sp-3)">KI-Scan ist nicht eingerichtet (kein Anthropic-Schlüssel). Du kannst Positionen von Hand erfassen.</div>
      <?php endif; ?>
      <div class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap">
        <button type="button" class="btn btn-ghost" id="weCamStart" <?= $ki ? '' : 'disabled' ?>>Kamera / Foto</button>
        <label class="btn btn-ghost" style="margin:0">Datei / PDF wählen
          <input type="file" id="weFile" accept="image/*,application/pdf" capture="environment" multiple hidden <?= $ki ? '' : 'disabled' ?>>
        </label>
      </div>
      <div class="we-scan-grid">
        <div class="we-scan-cam" id="weCamBox" style="display:none">
          <video id="weVideo" playsinline style="width:100%;border-radius:10px;background:#000"></video>
          <div class="bx-row" style="gap:var(--sp-2);margin-top:var(--sp-2)">
            <button type="button" class="btn btn-primary btn-sm" id="weShot">Foto aufnehmen</button>
            <button type="button" class="btn btn-ghost btn-sm" id="weCamStop">Kamera aus</button>
          </div>
        </div>
        <div class="we-scan-side">
          <div class="muted" id="weScanHint">Noch keine Seiten. Kamera öffnen und fotografieren, oder Datei/PDF wählen.</div>
          <div id="weThumbs" class="bx-row" style="gap:var(--sp-2);flex-wrap:wrap"></div>
          <div style="margin-top:var(--sp-3)">
            <button type="button" class="btn btn-primary" id="weScan" disabled>Lieferschein auslesen</button>
            <span id="weScanInfo" class="muted" style="margin-left:var(--sp-2)"></span>
          </div>
        </div>
      </div>
      <canvas id="weCanvas" hidden></canvas>
    </div>

    <!-- Methode: Sendungsnummer scannen -->
    <div class="bx-panel we-weg" data-w="tracking" hidden>
      <h2 style="margin-top:0">Sendungsnummer scannen</h2>
      <div class="bx-field" style="margin:0;max-width:460px">
        <label>Sendungsnummer vom Paketlabel</label>
        <input type="text" id="weTrack" class="lg-code" autocomplete="off" placeholder="Barcode scannen – die Lieferung wird geladen">
      </div>
      <div id="weTrackInfo" class="muted" style="margin-top:var(--sp-2)"></div>
    </div>

    <!-- Methode: Lieferanten-Gebindeaufkleber (BX…) scannen – ein Scan = ein Gebinde (Paket 12.11.2026 Teil 5) -->
    <div class="bx-panel we-weg" data-w="aufkleber" hidden>
      <h2 style="margin-top:0">Lieferanten-Aufkleber scannen</h2>
      <div class="bx-field" style="margin:0;max-width:460px">
        <label>QR vom Gebinde scannen (BX…)</label>
        <input type="text" id="weLa" class="lg-code" autocomplete="off" placeholder="BX-Code scannen – jedes Gebinde einmal">
      </div>
      <div id="weLaInfo" style="margin-top:var(--sp-3);font-size:15px"></div>
      <div id="weLaStand" style="margin-top:var(--sp-2);font-size:22px;font-weight:600"></div>
      <div id="weLaFehlt" class="muted" style="margin-top:var(--sp-1);font-size:13px"></div>
      <div style="margin-top:var(--sp-3)"><button type="button" class="btn btn-ghost" id="weLaAbschluss" hidden>Wareneingang abschließen</button></div>
      <div class="muted" style="margin-top:var(--sp-2);font-size:13px">Gleicher Code zweimal = „schon erfasst“. Fehlen am Ende Gebinde, kann trotzdem abgeschlossen werden – Nico wird informiert, Dustin bekommt die Aufgabe „Gebinde nachzählen“.</div>
    </div>

    <!-- Methode: Rezepturnummer-Aufkleber (R…) scannen -->
    <div class="bx-panel we-weg" data-w="rsticker" hidden>
      <h2 style="margin-top:0">Rezepturnummer-Aufkleber scannen</h2>
      <div class="bx-field" style="margin:0;max-width:460px">
        <label>Rezepturnummer vom Aufkleber (z. B. R12345)</label>
        <input type="text" id="weRsticker" class="lg-code" autocomplete="off" placeholder="R-Code scannen – Rezeptur/Produkt wird erkannt">
      </div>
      <div id="weRstickerInfo" class="muted" style="margin-top:var(--sp-2)">Kein Tippen von Chargen-/Rezepturnummern – nur scannen (Spec 5.2).</div>
    </div>

    <!-- Methode: Etiketten einbuchen – Produkt wählen, Design + Maße prüfen, dann Rollen buchen -->
    <div class="bx-panel we-weg" data-w="etikett" hidden>
      <h2 style="margin-top:0">Etiketten einbuchen</h2>
      <div class="bx-field" style="margin:0;max-width:560px">
        <label>Produkt oder Kunde</label>
        <input type="text" id="weEtProdukt" list="weEtProdList" autocomplete="off" placeholder="Produkt oder Kunde tippen, z. B. Magnesium">
        <datalist id="weEtProdList">
          <?php foreach ($etProdukte as $ep): ?><option value="<?= h($ep['n']) ?>"><?= $ep['kundenname'] !== '' ? h($ep['kundenname']) : '' ?></option><?php endforeach; ?>
        </datalist>
      </div>
      <div id="weEtInfo" class="muted" style="margin-top:var(--sp-2)"><?= $etProdukte ? 'Etikett wählen – danach erscheinen das Etikett-Design und die Maße zum Abgleich mit der gelieferten Rolle.' : 'Es gibt noch kein Etikett.' ?></div>
      <button type="button" class="btn btn-ghost btn-sm" id="weEtNeuAuf" style="margin-top:var(--sp-3)">Anderes Etikett anlegen</button>
      <!-- Von Hand anlegen: keine name-Attribute (liegt im Buchungsformular, wird per fetch gesendet) -->
      <div id="weEtNeu" class="we-et-neu" hidden>
        <h3 style="margin:0 0 var(--sp-2)">Anderes Etikett anlegen</h3>
        <div class="muted" style="font-size:13px;margin-bottom:var(--sp-3)">Kunde und Produkt sind optional. Gibt es für Produkt und Kunde schon ein Etikett, wird dieses genommen statt ein zweites anzulegen. Das Design lädt das Büro am Produkt hoch.</div>
        <div class="we-et-neu-raster">
          <div class="bx-field" style="margin:0"><label>Kunde</label>
            <input type="text" id="weEtNKunde" list="weEtNKundeList" autocomplete="off" placeholder="Kunde tippen (leer = ohne Kunde)">
            <datalist id="weEtNKundeList"><?php foreach ($etKunden as $k): ?><option value="<?= h($k['firma']) ?>"></option><?php endforeach; ?></datalist></div>
          <div class="bx-field" style="margin:0"><label>Produkt</label>
            <input type="text" id="weEtNProd" list="weEtNProdList" autocomplete="off" placeholder="Produkt tippen (leer = ohne Produkt)">
            <datalist id="weEtNProdList"><?php foreach ($etAlleProd as $pp): ?><option value="<?= h(trim(($pp['nummer'] ? $pp['nummer'] . ' · ' : '') . $pp['name'])) ?>"></option><?php endforeach; ?></datalist></div>
          <div class="bx-field" style="margin:0;grid-column:1/-1"><label>Bezeichnung</label>
            <input type="text" id="weEtNBez" maxlength="120" autocomplete="off" placeholder="z. B. Rückenetikett, Siegeletikett (ohne Produkt Pflicht)"></div>
          <div class="bx-field" style="margin:0"><label>Breite (mm)</label><input type="text" id="weEtNB" inputmode="decimal" autocomplete="off" placeholder="leer = vom Produkt"></div>
          <div class="bx-field" style="margin:0"><label>Höhe (mm)</label><input type="text" id="weEtNH" inputmode="decimal" autocomplete="off" placeholder="leer = vom Produkt"></div>
          <div class="bx-field" style="margin:0;grid-column:1/-1"><label>Material</label><input type="text" id="weEtNMat" maxlength="100" autocomplete="off" placeholder="z. B. PP weiß, Papier"></div>
        </div>
        <div id="weEtNFehler" class="we-et-warn" hidden></div>
        <div class="bx-row" style="gap:8px;margin-top:var(--sp-3);flex-wrap:wrap">
          <button type="button" class="btn btn-primary" id="weEtNAnlegen">Anlegen und weiter</button>
          <button type="button" class="btn btn-ghost" id="weEtNAbbr">Abbrechen</button>
        </div>
      </div>
      <div id="weEtWahl" class="we-et-wahl"></div>
      <div id="weEtPruef" class="we-et-pruef" hidden>
        <div class="we-et-vorschau" id="weEtVorschau"></div>
        <div class="we-et-daten">
          <div class="muted" style="font-size:13px">Größe <span id="weEtMassQuelle" class="muted" style="font-weight:normal"></span></div>
          <div class="we-et-mass" id="weEtMass">–</div>
          <div class="we-et-zeile"><span class="muted">Glas / Behälter</span> <span id="weEtGlas">–</span></div>
          <div class="we-et-zeile"><span class="muted">Material</span> <span id="weEtMaterial">–</span></div>
          <div class="we-et-zeile"><span class="muted">Version</span> <span id="weEtVersion">–</span></div>
          <div class="we-et-zeile"><span class="muted">Kunde</span> <span id="weEtKunde">–</span></div>
          <div class="we-et-zeile"><span class="muted">Artikel</span> <span id="weEtArtikel">–</span></div>
          <div id="weEtWarn" class="we-et-warn" hidden></div>
          <div id="weEtMassHinweis" class="we-et-info" hidden></div>
          <div class="muted" style="font-size:13px;margin-top:var(--sp-3)">Design mit der gelieferten Rolle vergleichen. Etiketten sind oft ein paar Millimeter größer als das Glas (Sicherheitsmaß) – das ist in Ordnung.</div>
          <button type="button" class="btn btn-primary" id="weEtOk" style="margin-top:var(--sp-3)">Menge erfassen</button>
        </div>
      </div>
    </div>

    <!-- Methode: aus Liste wählen -->
    <div class="bx-panel we-weg" data-w="liste" hidden>
      <h2 style="margin-top:0">Ankommende Sendung wählen</h2>
      <div id="weListeInfo" class="muted"></div>
      <?php if (!$erwartet): ?>
        <p class="muted" style="margin:var(--sp-2) 0 0">Aktuell keine Lieferungen unterwegs.</p>
      <?php else: ?>
      <div class="bx-row" id="weListeSuchZeile" style="gap:8px;align-items:center;margin-top:var(--sp-2)">
        <input type="search" id="weListeSuche" placeholder="Suchen: Lieferant, Bestellnr., Artikel …" autocomplete="off" style="flex:1;min-width:0">
        <button type="button" class="btn btn-ghost btn-sm" id="weListeAndere" hidden>Andere Lieferung wählen</button>
      </div>
      <div class="we-liste" id="weListe">
        <?php foreach ($erwartet as $l): $eta = (string)($l['eta_geplant'] ?? ''); $posis = $l['positionen'] ?? []; $anz = count($posis);
          // Produkt-/Rezepturnamen der Lieferung – damit nicht nur "Wellgreen BE-2867" dasteht.
          $namen = array_values(array_filter(array_map(fn($p) => trim((string)($p['name'] ?? '')), $posis), fn($s) => $s !== ''));
          $namenKurz = implode(' · ', array_slice($namen, 0, 4)) . (count($namen) > 4 ? ' +' . (count($namen) - 4) : '');
        ?>
        <?php // Termin am Bestelltag oder davor = Eingabefehler -> nicht als Termin zeigen (wie „Erwartete Lieferungen")
              if ($eta !== '' && !empty($l['bestelldatum']) && $eta <= (string)$l['bestelldatum']) $eta = ''; ?>
        <button type="button" class="we-listitem" data-id="<?= (int)$l['id'] ?>" data-such="<?= h(mb_strtolower(trim(($l['lieferant'] ?? '') . ' ' . ($l['nummer'] ?? '') . ' ' . implode(' ', $namen)))) ?>">
          <span class="we-li-art"><?= $namenKurz !== '' ? h($namenKurz) : '<span class="muted">ohne Artikel</span>' ?></span>
          <span class="we-li-lief"><?= h((string)($l['lieferant'] ?: 'Ohne Lieferant')) ?><?= !empty($l['nummer']) ? ' · ' . h((string)$l['nummer']) : '' ?></span>
          <span class="we-li-eta"><?= $eta !== '' ? h(date('d.m.', strtotime($eta))) : '' ?><?= $anz > 1 ? ' · ' . $anz . ' Pos.' : '' ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Ziel (Default Lager 1) + Lieferant, kompakt nebeneinander -->
    <div class="bx-panel" id="weKopf">
      <div class="bx-row" style="gap:var(--sp-4);flex-wrap:wrap;align-items:flex-end">
        <div class="bx-field" style="margin:0">
          <label>Ziel-Lager</label>
          <div class="we-seg">
            <label class="we-ziel on"><input type="radio" name="ziel" value="l1" checked> Lager 1</label>
            <label class="we-ziel"><input type="radio" name="ziel" value="l2"> Lager 2 <span class="muted">(Kunde)</span></label>
          </div>
        </div>
        <div class="bx-field" id="weKundeWrap" style="margin:0;min-width:220px;display:none"><label>Kunde (Fremdlager)</label>
          <select name="kunde_id" id="weKunde">
            <option value="">– Kunde wählen –</option>
            <?php foreach ($kunden as $k): ?><option value="<?= (int)$k['id'] ?>"><?= h((string)$k['firma']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="bx-field" style="margin:0;min-width:220px"><label>Lieferant <span class="muted">(optional – tippen, neue werden angelegt)</span></label>
          <input type="text" name="lieferant_name" id="weLiefName" list="weLiefList" autocomplete="off" placeholder="Lieferant suchen oder neu eingeben">
          <input type="hidden" name="lieferant_id" id="weLief" value="">
          <input type="hidden" name="auftrag_nr" id="weAuftragNr" value="">
        </div>
        <datalist id="weLiefList">
          <?php foreach ($liefers as $lf): ?><option value="<?= h((string)$lf['firma']) ?>"></option><?php endforeach; ?>
        </datalist>
        <?php if ($kisten): ?>
        <div class="bx-field" style="margin:0;min-width:240px;flex:1 1 240px"><label>Kiste <span class="muted">(optional – tippen oder Barcode scannen)</span></label>
          <input type="text" id="weKisteSuche" list="weKisteList" autocomplete="off" placeholder="Kiste suchen oder Barcode scannen">
          <input type="hidden" name="kiste_id" id="weKiste" value="">
          <div id="weKisteInfo" class="muted" style="font-size:12px;margin-top:4px"></div>
        </div>
        <datalist id="weKisteList">
          <?php foreach ($kisten as $kk): ?><option value="<?= h((string)$kk['name']) ?>"><?= $kk['blinker'] ? 'Blinker ' . h((string)$kk['blinker']) : 'kein Blinker' ?></option><?php endforeach; ?>
        </datalist>
        <?php endif; ?>
        <div class="bx-field" id="weGrundWrap" style="margin:0;min-width:260px;flex:1 1 100%;display:none">
          <label>Ohne Bestellung – Grund *</label>
          <div class="bx-row" style="gap:var(--sp-3);flex-wrap:wrap;align-items:flex-start">
            <select name="we_grund" id="weGrund" style="flex:0 1 280px">
              <option value="">– Grund wählen –</option>
              <option value="alt_bestellung">Alte Bestellung, nicht im System</option>
              <option value="muster">Muster</option>
              <option value="sonstiges">Sonstiges</option>
            </select>
            <input type="text" name="we_grund_notiz" id="weGrundNotiz" maxlength="250" placeholder="Notiz (bei Muster/Sonstiges Pflicht)" style="flex:1 1 260px">
          </div>
          <div class="muted" id="weGrundInfo" style="font-size:12px;margin-top:4px">Mindestens eine Position hängt an keiner Bestellung. Bei „Alte Bestellung“ wird mit dem Lieferanten oben eine nachträgliche Bestellung angelegt (direkt bestellt, geliefert) – Nico prüft Preis und Daten. Kundenware bitte über Lager 2 buchen.</div>
        </div>
        <div class="bx-field" style="margin:0;min-width:240px;flex:1 1 240px"><label>Sendungs-/Paketnummer <span class="muted">(optional, scannen)</span></label>
          <input type="text" name="tracking" class="lg-code" autocomplete="off" placeholder="Paketlabel scannen – welches Paket ist gekommen">
        </div>
      </div>
    </div>

    <!-- Positionen -->
    <div class="bx-panel" id="wePosPanel">
      <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:var(--sp-2)">
        <h2 style="margin:0">Positionen</h2>
        <button type="button" class="btn btn-ghost btn-sm" id="weAdd">+ Zeile</button>
      </div>
      <div id="weRows" style="margin-top:var(--sp-3)"></div>
      <div class="muted" style="margin-top:var(--sp-2)">Pflicht je Warenart: Rohstoff/Leerkapseln/Bulk/Fertige Produkte → MHD + Charge; Verpackung/Verbrauch → frei. Blinker ist Pflicht – außer bei <strong>Etikett (Rolle)</strong>: dort nur Gesamtmenge + Anzahl Rollen angeben; die Menge wird gleichmäßig auf die Rollen verteilt (Rest auf die letzte), Inventaraufkleber je Rolle sind optional (leer = automatisch vergeben). Mehrere Pakete: je Karton eine Zeile mit der Menge, gleichmäßig vorbelegt – nur abweichende Kartons ändern; die Summe muss zur Gesamtmenge passen, sonst wird nicht gebucht. Jeder Karton bekommt sein Etikett mit seinem Gewicht.</div>
      <div style="margin-top:var(--sp-4)"><button type="submit" class="btn btn-primary" id="weBuchen">Alle buchen &amp; Blinker anhängen</button></div>
    </div>
  </div>
</form>

<style>
  .we-kacheln{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:var(--sp-4);margin-bottom:var(--sp-5)}
  .we-kachel{text-align:left;border:1px solid var(--line);border-radius:14px;padding:22px 20px;background:var(--panel);
    color:var(--text);cursor:pointer;min-height:120px;display:flex;flex-direction:column;gap:6px;justify-content:center}
  .we-kachel:hover{border-color:var(--gruen);box-shadow:inset 0 0 0 1px var(--gruen);text-decoration:none}
  .we-kachel-t{font-size:var(--fs-lg);font-weight:600;line-height:1.2;display:flex;align-items:center;gap:10px}
  .we-kachel-s{color:var(--muted)}
  .we-seg{display:inline-flex;gap:8px;flex-wrap:wrap}
  /* Kompakte Liste (08.11.2026): eine Zeile je Lieferung, begrenzte Höhe mit eigenem Scroll, Suche darüber. */
  .we-liste{display:flex;flex-direction:column;gap:4px;margin-top:var(--sp-2);max-height:46vh;overflow:auto;padding-right:2px}
  .we-listitem{display:flex;gap:10px;align-items:baseline;text-align:left;border:1px solid var(--line);border-radius:8px;padding:8px 12px;background:var(--panel-2);color:var(--text);cursor:pointer;line-height:1.3;font-size:14px}
  .we-listitem:hover{border-color:var(--gruen);text-decoration:none}
  .we-listitem.gewaehlt{border-color:var(--gruen);background:rgba(29,158,117,.10)}
  .we-li-art{flex:1 1 auto;min-width:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .we-li-lief{flex:0 1 auto;color:var(--muted);font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:45%}
  .we-li-eta{flex:0 0 auto;color:var(--muted);font-size:12px;white-space:nowrap}
  .we-liste.zu{max-height:none;overflow:visible}
  .we-listitem[hidden],#weListeAndere[hidden],#weListeSuche[hidden]{display:none}
  .we-bpinfo{flex:1 1 100%;font-size:13px;margin-top:6px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .we-fest{background:var(--line-2,#f2f4f0) !important;color:var(--muted) !important;pointer-events:none}   /* Theme-Farbe statt festem Hellgrau (Dark Mode, 12.11.2026) */
  .we-kachel-haupt{border-color:var(--gruen,#1D9E75) !important}
  @media (max-width:640px){.we-listitem{flex-wrap:wrap}.we-li-lief{max-width:none}}
  .we-listnamen{font-size:13px;margin-top:2px}
  .we-ziel{border:1px solid var(--line);border-radius:var(--r-sm);padding:10px 14px;cursor:pointer;line-height:1.3}
  .we-ziel.on{border-color:var(--gruen);box-shadow:inset 0 0 0 1px var(--gruen)}
  .we-pos{position:relative;border:1px solid var(--line);border-radius:var(--r-sm);padding:var(--sp-4);padding-top:var(--sp-5);margin-bottom:var(--sp-3);background:var(--panel-2)}
  .we-pos .we-row{display:flex;flex-wrap:wrap;gap:var(--sp-3) var(--sp-4)}
  .we-pos .bx-field{margin-bottom:0;flex:1 1 150px;min-width:0}
  .we-pos .f-art{flex:2 1 240px}
  .we-pos .f-rez{flex:2 1 240px}
  .we-pos .we-rez-info{font-size:12px;margin-top:4px}
  .we-pos .f-menge{flex:0 1 110px}
  .we-pos .f-einheit{flex:0 1 90px}
  .we-pos .f-artnr{flex:0 1 120px}
  .we-pos .f-pakete{flex:0 1 80px}
  .we-pos .f-paketnrn{flex:1 1 100%}
  .we-pos .we-pakscan{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px}
  .we-pos .we-pak{flex:0 1 170px;min-width:140px}
  .we-pos .f-frei{flex:0 1 100px}
  .we-pos .f-frei input[type=checkbox]{width:22px;height:22px;margin-top:6px}
  .we-pos .f-geb{flex:1 1 100%}
  .we-pos .we-geb-liste{display:flex;flex-wrap:wrap;gap:6px 10px;margin-bottom:6px}
  .we-pos .we-geb-zeile{display:flex;align-items:center;gap:6px;flex:0 1 170px;font-weight:400}
  .we-pos .we-geb-zeile .muted{white-space:nowrap;font-size:12px;min-width:56px}
  .we-pos .we-geb{width:100%;min-width:70px;text-align:right}
  .we-pos .we-geb-sum{font-size:13px}
  .we-pos .we-geb-ok{color:var(--ok,#1D9E75)}
  .we-pos .we-geb-bad{color:var(--danger,#c0392b);font-weight:600}
  .we-pos[data-gebfehler] .f-geb{outline:2px solid var(--danger,#c0392b);outline-offset:4px;border-radius:8px}
  .we-aehnlich{margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;align-items:center}
  .we-aehnlich:empty{display:none}
  .we-ae-t{font-size:12px;color:var(--muted)}
  .we-ae-chip{font-size:12px;border:1px solid var(--gold,#c7a24a);background:var(--panel);color:var(--text);border-radius:999px;padding:3px 10px;cursor:pointer}
  .we-ae-chip:hover{border-color:var(--gruen);background:var(--panel-2)}
  .we-pos .f-blinker{flex:1 1 160px}
  .we-pos .we-del{position:absolute;top:var(--sp-2);right:var(--sp-2)}
  .we-thumb{position:relative;width:84px;height:84px;border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--panel-2)}
  .we-thumb img{width:100%;height:100%;object-fit:cover}
  .we-thumb .x{position:absolute;top:2px;right:2px;background:#000a;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;line-height:1}
  .we-thumb .pdf{display:flex;align-items:center;justify-content:center;height:100%;font-weight:600;color:var(--muted)}
  .we-scan-grid{display:flex;gap:var(--sp-4);flex-wrap:wrap;align-items:flex-start;margin-top:var(--sp-3)}
  .we-scan-cam{flex:1 1 340px;max-width:560px}
  .we-scan-side{flex:1 1 300px;min-width:240px}
  .we-et-wahl{display:flex;flex-wrap:wrap;gap:8px;margin-top:var(--sp-3)}
  .we-et-wahl:empty{display:none}
  .we-et-neu{border:1px solid var(--line);border-radius:12px;padding:var(--sp-4);margin-top:var(--sp-3);background:var(--panel-2);max-width:560px}
  .we-et-neu[hidden]{display:none}
  .we-et-neu-raster{display:grid;grid-template-columns:1fr 1fr;gap:var(--sp-3)}
  @media (max-width:560px){ .we-et-neu-raster{grid-template-columns:1fr} }
  .we-et-opt{text-align:left;border:1px solid var(--line);border-radius:10px;padding:10px 14px;background:var(--panel-2);color:var(--text);cursor:pointer;line-height:1.35}
  .we-et-opt.on{border-color:var(--gruen);box-shadow:inset 0 0 0 1px var(--gruen)}
  .we-et-pruef{display:flex;flex-wrap:wrap;gap:var(--sp-4);margin-top:var(--sp-4);align-items:flex-start}
  .we-et-vorschau{flex:1 1 300px;min-width:0;max-width:560px}
  .we-et-daten{flex:1 1 240px;min-width:0}
  .we-et-mass{font-size:30px;font-weight:600;line-height:1.15;margin:2px 0 var(--sp-3)}
  .we-et-zeile{margin:4px 0;overflow-wrap:anywhere}
  .we-et-daten .btn{white-space:normal;max-width:100%;text-align:center}
  .we-et-warn{margin-top:var(--sp-3);padding:10px 12px;border-radius:8px;border:1px solid var(--warn);color:var(--text)}
  .we-et-info{margin-top:var(--sp-3);padding:10px 12px;border-radius:8px;background:rgba(127,127,127,.08);border:1px solid rgba(127,127,127,.25);color:var(--text)}
  .we-kacheln[hidden],.we-et-pruef[hidden],.we-et-warn[hidden],.we-et-info[hidden],#weKopf[hidden],#wePosPanel[hidden]{display:none}
</style>
<script src="/assets/etikett_vorschau.js"></script>

<script>
(function(){
  var ITEMS = <?= json_encode(array_map(fn($it)=>['id'=>(int)$it['id'],'n'=>(string)$it['name'],'e'=>(string)$it['einheit'],'k'=>(string)$it['kategorie'],'f'=>(string)($it['form']??''),'r'=>(string)($it['verpackung_rolle']??''),'g'=>(int)($it['kapselgroesse_id']??0),'kl'=>(string)($it['kapsel']??''),'s'=>(string)($it['syn']??'')], $items), JSON_UNESCAPED_UNICODE) ?>;
  var KGR = <?= json_encode(array_map(fn($k)=>['id'=>(int)$k['id'],'n'=>(string)$k['name']], function_exists('erp_kapselgroessen') ? erp_kapselgroessen() : []), JSON_UNESCAPED_UNICODE) ?>;
  // Leerkapseln am Namen erkennen + Größe lesen (gleiche Regeln wie kapselgroesse_aus_name()/name_ist_leerkapsel() im Dashboard).
  function kgrAusName(s){ var m=String(s||'').match(/(?:gr(?:ö|oe|o)?(?:ß|ss)?e?\.?|size|#)\s*(000|00|0|1|2|3|4|5)(?!\d)/i); if(!m) return 0; var k=KGR.filter(function(x){return x.n==='Größe '+m[1];})[0]; return k?k.id:0; }
  function istLeerkapselName(s){ s=String(s||''); return /leerkapsel|kapselh(?:ü|ue)lle|empty\s*caps|(?:hpmc|gelatine|pullulan)[^,;]*kapsel|kapsel[^,;]*(?:hpmc|gelatine|pullulan)/i.test(s) || (/kapsel|capsul/i.test(s) && kgrAusName(s)>0); }
  var KISTEN = <?= json_encode(array_map(fn($k)=>['id'=>(int)$k['id'],'n'=>(string)$k['name'],'b'=>(string)($k['barcode']??''),'bl'=>(string)($k['blinker']??'')], $kisten), JSON_UNESCAPED_UNICODE) ?>;
  var REZ = <?= json_encode(array_map(fn($r)=>[
        'id'=>(int)$r['id'],
        'n'=>trim((($r['nummer']??'')!==''? $r['nummer'].' · ':'').$r['name']),
        'bi'=>(int)($r['bulk_item_id']??0),
        'e'=>(string)($r['bulk_einheit']??''),
        // Soll-Kette 2d: Kapsel-Rezeptur? Und ist die Kapselgröße des Bulk-Artikels eindeutig (sonst Auswahl im Wareneingang)?
        'kap'=>in_array((string)($r['darreichungsform']??''), ['kapsel','softgel'], true) ? 1 : 0,
        'kg'=>in_array((string)($r['darreichungsform']??''), ['kapsel','softgel'], true) && function_exists('erp_bulk_kapselgroesse') ? erp_bulk_kapselgroesse((int)($r['stamm_id']??$r['id'])) : 0
      ], $rezepturen), JSON_UNESCAPED_UNICODE) ?>;
  var WEQ = <?= (function_exists('erp_we_quarantaene') && erp_we_quarantaene()) ? 1 : 0 ?>;   // Quarantäne nur, wenn in den Einstellungen an (Standard aus, 04.11.2026)
  var MATRIX = {rohstoff:{mhd:1,charge:1,q:WEQ},leerkapsel:{mhd:1,charge:1,q:WEQ},fertig:{mhd:1,charge:1,q:WEQ},verkaufsfertig:{mhd:1,charge:1,q:WEQ},etikett:{mhd:0,charge:0,inventar:1},verpackung:{mhd:0,charge:0},verbrauch:{mhd:0,charge:0}};
  var ARTEN = [['rohstoff','Rohstoff'],['leerkapsel','Leerkapseln'],['fertig','Bulk / lose (Kapseln/Tabletten)'],['verkaufsfertig','Fertiges Produkt (verpackt)'],['etikett','Etikett (Rolle)'],['verpackung','Verpackung'],['verbrauch','Verbrauch']];
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  var KEYC=0;   // fortlaufender Zeilenschlüssel (für die 2D-Rollen-Felder je Etikett-Position)
  function nf(n){ return (Math.round(n*1000)/1000).toString().replace('.',','); }   // Menge hübsch (deutsch)

  // --- Ziel L1/L2 ---
  var kundeWrap=document.getElementById('weKundeWrap'), kunde=document.getElementById('weKunde');
  document.querySelectorAll('input[name="ziel"]').forEach(function(r){
    r.addEventListener('change',function(){
      document.querySelectorAll('.we-ziel').forEach(function(l){l.classList.toggle('on', l.querySelector('input').checked);});
      var l2=document.querySelector('input[name="ziel"][value="l2"]').checked;
      kundeWrap.style.display=l2?'':'none'; kunde.required=l2;
    });
  });

  // Lieferant: tippt man selbst, gilt der Name (frühere Auswahl-ID verwerfen).
  (function(){ var ln=document.getElementById('weLiefName'), lh=document.getElementById('weLief');
    if(ln&&lh) ln.addEventListener('input', function(){ lh.value=''; }); })();

  // --- Positionen-Tabelle ---
  var rows=document.getElementById('weRows');
  // Gewicht & Bestand pro Gebinde: passt bei einer Position die Summe der Kartons nicht zur Gesamtmenge, wird nicht gebucht.
  (function(){ var f=document.getElementById('weForm'); if(!f) return;
    // An window (Capture) hängen: läuft vor dem Busy-Spinner (document, Capture) – sonst bliebe der Knopf gesperrt.
    window.addEventListener('submit', function(e){
      if(e.target!==f) return;
      var bad=[].filter.call(document.querySelectorAll('.we-pos[data-gebfehler]'), function(c){ var b=c.querySelector('.f-geb'); return b && b.style.display!=='none'; });
      if(!bad.length) return;
      e.preventDefault(); e.stopImmediatePropagation();
      bad[0].scrollIntoView({behavior:'smooth', block:'center'});
      alert('Die Menge je Karton passt bei ' + bad.length + ' Position(en) nicht zur Gesamtmenge. Bitte korrigieren – erst dann wird gebucht.');
    }, true); })();
  // Einkauf gerade ziehen (Teil 1): Zeile an eine Bestellposition binden. Artikel und Einheit sind dann fest (aus der Bestellung),
  // Kapselgröße/Füllgewicht sichtbar. „Nicht aus dieser Bestellung" löst die Bindung (dann gilt der Grund ohne Bestellung).
  function bpSetzen(card, bp){
    var h=card.querySelector('.we-bp'), inf=card.querySelector('.we-bpinfo'), nm=card.querySelector('.we-name');
    var fest=card.querySelectorAll('.we-name,.we-art,.f-einheit select,.f-einheit input');
    if(h) h.value = bp && bp.bp_id ? String(bp.bp_id) : '0';
    fest.forEach(function(el){ el.classList.toggle('we-fest', !!(bp && bp.bp_id)); if(el.tagName==='INPUT') el.readOnly=!!(bp && bp.bp_id); });
    if(inf){
      if(bp && bp.bp_id){
        inf.innerHTML='<span>Bestellung <strong>'+esc(bp.bp_nummer||'')+'</strong> · offen '+nf(bp.bp_rest||0)+' '+esc(bp.bp_einheit||bp.einheit||'')+(bp.kapsel?(' · '+esc(bp.kapsel)):'')+' · Artikel und Einheit aus der Bestellung</span> <button type="button" class="btn btn-ghost btn-sm we-bp-los">Nicht aus dieser Bestellung</button>';
        inf.querySelector('.we-bp-los').addEventListener('click', function(){ bpSetzen(card, null); grundSync(); });
      } else inf.innerHTML='<span class="muted">Ohne Bestellung</span>';
    }
    grundSync();
  }
  function grundSync(){
    var w=document.getElementById('weGrundWrap'); if(!w) return;
    var l2=(document.querySelector('input[name="ziel"]:checked')||{}).value==='l2';
    var ohne=[].some.call(document.querySelectorAll('#weRows .we-pos'), function(c){ var b=c.querySelector('.we-bp'), n=c.querySelector('.we-name'); return (!b || b.value==='0') && n && n.value.trim()!==''; });
    w.style.display = (ohne && !l2) ? '' : 'none';
    var g=document.getElementById('weGrund'); if(g) g.required = ohne && !l2;
  }
  document.addEventListener('input', function(e){ if(e.target && e.target.closest && e.target.closest('#weRows')) grundSync(); });
  document.addEventListener('change', function(e){ if(e.target && e.target.name==='ziel') grundSync(); });
  function artOptions(sel){ return ARTEN.map(function(a){return '<option value="'+a[0]+'"'+(a[0]===sel?' selected':'')+'>'+a[1]+'</option>';}).join(''); }
  function katLabel(it){
    if(it.f==='kapselhuelle') return 'Leerkapseln';
    if(it.k==='verpackung' && it.r==='etikett') return 'Etikett';
    var m={rohstoff:'Rohstoff',fertig:'Bulk / lose',verkaufsfertig:'Fertiges Produkt',verpackung:'Verpackung',verbrauch:'Verbrauch'};
    return m[it.k]||it.k||'';
  }
  function itemArt(it){ return (it.k==='verpackung' && it.r==='etikett') ? 'etikett' : (it.f==='kapselhuelle'?'leerkapsel':it.k); }
  function datalistItems(){ return ITEMS.map(function(it){ var l=[katLabel(it), it.kl, it.s].filter(function(x){return x;}).join(' · '); return '<option value="'+esc(it.n)+'">'+(l?esc(l):'')+'</option>'; }).join(''); }
  var dl=document.createElement('datalist'); dl.id='weItemList'; dl.innerHTML=datalistItems(); document.body.appendChild(dl);
  var dlR=document.createElement('datalist'); dlR.id='weRezList';
  dlR.innerHTML=REZ.map(function(r){ return '<option value="'+esc(r.n)+'">'+(r.bi?'':'noch kein Bulk-Artikel')+'</option>'; }).join('');
  document.body.appendChild(dlR);

  // Punkt 8: Einheit ist ein festes Auswahlfeld (Stk, g, kg, ml, L) statt Freitext. Bestehende
  // Freitext-/Artikel-Einheiten werden auf diese fuenf abgebildet (z. B. „Stück"->Stk, „Liter"->L, „t"->kg).
  var EINH_OPTS=['Stk','g','kg','ml','L'];
  function einhCanon(raw){
    var s=String(raw==null?'':raw).trim().toLowerCase().replace(/\.$/,'');
    if(s==='') return '';
    if(['stk','st','stck','stück','stueck','pcs','packung','pkg','beutel','dose','glas','kapsel','kapseln'].indexOf(s)>=0) return 'Stk';
    if(['g','gr','gramm','gramms'].indexOf(s)>=0) return 'g';
    if(['kg','kilo','kilogramm','kgs'].indexOf(s)>=0) return 'kg';
    if(['ml','milliliter'].indexOf(s)>=0) return 'ml';
    if(['l','lt','ltr','liter','litre'].indexOf(s)>=0) return 'L';
    if(['t','to','tonne','tonnen'].indexOf(s)>=0) return 'kg';
    return '';
  }
  function einhSelectHtml(raw){
    var sel=einhCanon(raw)||'Stk';
    return '<select name="p_einheit[]" class="we-einh">'+EINH_OPTS.map(function(o){
      return '<option value="'+o+'"'+(o===sel?' selected':'')+'>'+o+'</option>';
    }).join('')+'</select>';
  }
  function addRow(p){
    p=p||{};
    var art=(p.verpackung_rolle==='etikett')?'etikett':((p.form==='kapselhuelle')?'leerkapsel':(p.kategorie||p.warenart||'rohstoff')); if(!MATRIX[art])art='rohstoff';
    var rowKey=String(++KEYC);
    var card=document.createElement('div'); card.className='we-pos'; card.setAttribute('data-key',rowKey);
    card.innerHTML=
      '<button type="button" class="btn btn-ghost btn-sm we-del" title="Zeile entfernen">×</button>'+
      '<input type="hidden" name="p_key[]" value="'+rowKey+'">'+
      '<div class="we-row">'+
        '<div class="bx-field f-art"><label>Artikel</label><input type="text" class="we-name" name="p_name[]" list="weItemList" autocomplete="off" value="'+esc(p.item_name||p.name||'')+'" title="'+esc(p.item_name||p.name||'')+'" placeholder="Artikel suchen oder neuen Namen eingeben"><input type="hidden" name="p_item[]" value="'+(p.item_id||0)+'"><input type="hidden" name="p_auftrag_id[]" value="'+(p.auftrag_id||0)+'"><input type="hidden" name="p_bp[]" class="we-bp" value="'+(p.bp_id||0)+'"><div class="we-aehnlich"></div><div class="we-neu"></div><input type="hidden" name="p_neu[]" class="we-neu-h" value="0"></div>'+
        '<div class="bx-field f-warenart"><label>Warenart</label><select name="p_warenart[]" class="we-art">'+artOptions(art)+'</select></div>'+
        '<div class="bx-field f-form" style="display:none"><label>Beschaffenheit *</label><select name="p_form[]" class="we-form"><option value="">– wählen –</option><option value="pulver">Pulver</option><option value="granulat">Granulat</option><option value="extrakt">Extrakt</option><option value="fluessig">Flüssig</option><option value="oel">Öl</option><option value="paste">Paste</option><option value="kristallin">Kristallin</option></select></div>'+
        '<div class="bx-field f-kgr" style="display:none"><label>Kapselgröße *</label><select name="p_kapselgroesse[]" class="we-kgr"><option value="">– wählen –</option>'+KGR.map(function(k){return '<option value="'+k.id+'">'+esc(k.n)+'</option>';}).join('')+'</select></div>'+
        '<div class="bx-field f-rez" style="display:none"><label>Rezeptur <span class="muted">(Bulk)</span></label><input type="text" class="we-rez" name="p_rezeptur_name[]" list="weRezList" autocomplete="off" value="" placeholder="Rezeptur wählen"><input type="hidden" name="p_rezeptur[]" class="we-rez-id" value="0"><div class="we-rez-info muted"></div></div>'+
        '<div class="bx-field f-menge"><label>Menge</label><input type="text" name="p_menge[]" inputmode="decimal" value="'+(p.menge&&p.menge>0?p.menge:'')+'" placeholder="0"></div>'+
        '<div class="bx-field f-einheit"><label>Einheit</label>'+einhSelectHtml(p.einheit||'')+'</div>'+
        '<div class="bx-field f-artnr"><label>Art.-Nr. <span class="muted">(Lief.)</span></label><input type="text" name="p_artnr[]" value="'+esc(p.artikelnummer||'')+'" placeholder="Art.-Nr."></div>'+
        '<div class="bx-field f-charge"><label class="lbl-charge">Charge-Nr.</label><input type="text" name="p_charge[]" class="we-charge" value="'+esc(p.charge_nr||'')+'"></div>'+
        '<div class="bx-field f-mhd"><label class="lbl-mhd">MHD</label><input type="date" name="p_mhd[]" class="we-mhd" value="'+esc(p.mhd||'')+'"></div>'+
        '<div class="bx-field f-pakete"><label>Pakete</label><input type="number" name="p_pakete[]" class="we-pakete" min="1" step="1" value="1"></div>'+
        '<div class="bx-field f-paketnrn"><label>Paketnummern <span class="muted">(ein Feld je Paket – Anzahl folgt „Pakete")</span></label>'+
          '<input type="hidden" name="p_paketnummern[]" class="we-pak-h">'+
          '<div class="we-pakscan"></div>'+
        '</div>'+
        // Gewicht & Bestand pro Gebinde: eine Zeile je Karton, gleichmäßig vorbelegt; Summe muss zur Gesamtmenge passen.
        '<div class="bx-field f-geb" style="display:none"><label>Menge je Karton <span class="muted">(gleichmäßig vorbelegt – nur abweichende Kartons ändern)</span></label>'+
          '<div class="we-geb-liste"></div><div class="we-geb-sum"></div>'+
        '</div>'+
        '<div class="bx-field f-frei"><label>Freigegeben</label><input type="checkbox" class="we-frei" checked title="Haken raus bei Problem (Beschädigung, Abweichung) – dann Quarantäne"><input type="hidden" name="p_frei[]" class="we-frei-h" value="1"></div>'+
        '<div class="bx-field f-blinker"><label>Blinker *</label><input type="text" name="p_blinker[]" class="we-blinker" value="" placeholder="Code scannen" required></div>'+
        // Etikettenrollen: nur Gesamtmenge + Anzahl Rollen. Menge wird gleichmäßig verteilt; Inventaraufkleber optional.
        '<div class="we-rollen" style="display:none;flex:1 1 100%">'+
          '<div class="bx-row" style="gap:var(--sp-3);align-items:flex-end;flex-wrap:wrap">'+
            '<div class="bx-field" style="margin:0;flex:0 1 140px"><label>Anzahl Rollen</label><input type="number" class="we-rollenzahl" name="p_rollen_zahl['+rowKey+']" min="1" step="1" value="1"></div>'+
            '<div class="muted we-rollen-info" style="padding-bottom:10px"></div>'+
          '</div>'+
          '<div class="we-rollen-liste" style="display:flex;flex-direction:column;gap:8px;margin-top:6px"></div>'+
          '<div class="muted" style="font-size:12px;margin-top:6px">Die Gesamtmenge wird gleichmäßig auf die Rollen verteilt (Rest auf die letzte). Inventaraufkleber je Rolle optional scannen (Enter springt weiter) – leer lassen = Nummer wird automatisch vergeben.</div>'+
        '</div>'+
      '</div>'+
      '<div class="we-bpinfo"></div>';
    rows.appendChild(card);
    bpSetzen(card, p.bp_id ? p : null);
    // Paketnummern: die ANZAHL der Scan-Felder folgt der Zahl im Feld "Pakete".
    // Tippst du 11, erscheinen sofort 11 Felder (kein Zählen/Nachklicken). Scan+Enter springt zum nächsten.
    var pakWrap=card.querySelector('.we-pakscan'), pakH=card.querySelector('.we-pak-h'),
        pkt=card.querySelector('.we-pakete');
    function pakSync(){
      if(!pakH) return;
      var vals=[].map.call(pakWrap.querySelectorAll('.we-pak'), function(i){return i.value.trim();})
                 .filter(function(v){return v!=='';});
      pakH.value=vals.join('\n');   // nur die Nummern sammeln; die Anzahl steuert "Pakete"
    }
    function wirePak(inp){
      inp.addEventListener('input', pakSync);
      inp.addEventListener('keydown', function(e){
        if(e.key!=='Enter') return;
        e.preventDefault(); pakSync();
        var next=inp.nextElementSibling;
        if(next && next.classList.contains('we-pak')){ next.focus(); return; }
        if(inp.value.trim()!==''){                 // mehr Pakete als angegeben -> Anzahl wächst mit
          var n=(parseInt(pkt&&pkt.value,10)||0)+1;
          if(pkt) pkt.value=n;
          renderPakFields(n, n-1);
        }
      });
    }
    function renderPakFields(n, fokusIdx){
      if(!pakWrap) return;
      n=Math.max(1, n|0);
      var cur=[].map.call(pakWrap.querySelectorAll('.we-pak'), function(i){return i.value;});
      pakWrap.innerHTML='';
      for(var k=0;k<n;k++){
        var i=document.createElement('input');
        i.type='text'; i.className='we-pak lg-code'; i.autocomplete='off';
        i.placeholder='Paket '+(k+1)+' scannen + Enter';
        if(cur[k]!==undefined) i.value=cur[k];
        pakWrap.appendChild(i); wirePak(i);
      }
      pakSync();
      gebRender(n);
      if(fokusIdx!=null){ var f=pakWrap.querySelectorAll('.we-pak')[fokusIdx]; if(f) f.focus(); }
    }
    // --- Menge je Karton: Vorschlag = Gesamtmenge gleichmäßig (Stk ganzzahlig, sonst 3 Stellen; Rest auf den letzten). ---
    var gebBox=card.querySelector('.f-geb'), gebListe=card.querySelector('.we-geb-liste'), gebSumEl=card.querySelector('.we-geb-sum');
    function gebZahl(v){ v=String(v==null?'':v).trim(); if(v.indexOf(',')>=0) v=v.replace(/\./g,'').replace(',','.'); else if(/^\d{1,3}(\.\d{3})+$/.test(v)) v=v.replace(/\./g,''); return parseFloat(v)||0; }
    function gebTxt(n){ var r=Math.round(n*1000)/1000, t=Math.abs(r).toFixed(3).replace(/0+$/,'').replace(/\.$/,''); var g=t.split('.'); g[0]=g[0].replace(/\B(?=(\d{3})+(?!\d))/g,'.'); return (r<0?'-':'')+g.join(','); }
    function gebEinh(){ var e=card.querySelector('[name="p_einheit[]"]'); return e?e.value:''; }
    function gebStk(){ return /st(ü|u)?ck|^stk|tabl|kaps/i.test(gebEinh()); }
    function gebVorschlag(){
      if(!gebListe) return;
      var ins=gebListe.querySelectorAll('.we-geb'), n=ins.length; if(!n) return;
      var ges=gebZahl(card.querySelector('input[name="p_menge[]"]').value);
      var basis=gebStk()?Math.floor(ges/n):Math.floor(ges/n*1000)/1000;
      [].forEach.call(ins,function(inp,k){ inp.value = ges>0 ? gebTxt(k<n-1?basis:ges-basis*(n-1)) : ''; });
      gebSumme();
    }
    function gebSumme(){
      if(!gebSumEl) return;
      var ins=gebListe.querySelectorAll('.we-geb'), n=ins.length;
      if(n<2){ gebSumEl.textContent=''; card.removeAttribute('data-gebfehler'); return; }
      var ges=gebZahl(card.querySelector('input[name="p_menge[]"]').value), sum=0, leer=0;
      [].forEach.call(ins,function(inp){ var v=gebZahl(inp.value); if(v<=0) leer++; sum+=v; });
      var diff=Math.round((sum-ges)*1000)/1000, e=gebEinh();
      var ok=Math.abs(diff)<=0.0005 && leer===0 && ges>0;
      gebSumEl.className='we-geb-sum '+(ok?'we-geb-ok':'we-geb-bad');
      gebSumEl.textContent = 'Summe '+gebTxt(sum)+' '+e+(ok ? ' – passt zur Gesamtmenge.' :
        (leer ? ' – '+leer+' Karton(s) ohne Menge. So nicht buchbar.' : ' – Differenz '+gebTxt(Math.abs(diff))+' '+e+' zur Gesamtmenge '+gebTxt(ges)+' '+e+'. So nicht buchbar.'));
      if(ok) card.removeAttribute('data-gebfehler'); else card.setAttribute('data-gebfehler','1');
    }
    function gebRender(n){
      if(!gebListe) return;
      n=Math.max(1,n|0);
      var etk=(card.querySelector('.we-art')||{}).value==='etikett';
      if(gebBox) gebBox.style.display=(n>1 && !etk)?'':'none';
      if(n<2 || etk){ gebListe.innerHTML=''; gebSumme(); return; }
      if(gebListe.querySelectorAll('.we-geb').length===n){ gebSumme(); return; }
      gebListe.innerHTML='';
      for(var k=0;k<n;k++){
        var w=document.createElement('label'); w.className='we-geb-zeile';
        w.innerHTML='<span class="muted">Karton '+(k+1)+'</span><input type="text" inputmode="decimal" class="we-geb" name="p_geb['+rowKey+'][]" autocomplete="off">';
        gebListe.appendChild(w);
        w.querySelector('input').addEventListener('input', gebSumme);
      }
      gebVorschlag();
    }
    if(pkt) pkt.addEventListener('input', function(){ renderPakFields(parseInt(pkt.value,10)||1); });
    renderPakFields(parseInt(pkt&&pkt.value,10)||1);
    // Artikel-Name -> item_id, Einheit, Warenart aus Treffer uebernehmen.
    var name=card.querySelector('.we-name'), hid=card.querySelector('input[name="p_item[]"]'),
        art2=card.querySelector('.we-art'), einh=card.querySelector('[name="p_einheit[]"]');
    name.addEventListener('input',function(){
      name.title=name.value;   // voller Text als Tooltip beim Drüberfahren
      var m=ITEMS.filter(function(it){return it.n.toLowerCase()===name.value.trim().toLowerCase();})[0];
      if(m){ hid.value=m.id; if(einh) einh.value=einhCanon(m.e)||'Stk'; var a=itemArt(m); if(MATRIX[a]){art2.value=a;} pflicht(card); etikettToggle(); }
      else { hid.value=0; if(art2.value==='rohstoff' && MATRIX.leerkapsel && istLeerkapselName(name.value)){ art2.value='leerkapsel'; if(einh) einh.value='Stk'; pflicht(card); } }
      kgrSync(m); neuSync(card);
      zeigeAehnlich(card);
    });
    // Rezeptur-Picker (nur bei Warenart "Fertigware / Bulk"): fertige Kapseln aufs Bulk-Item der Rezeptur buchen.
    var rez=card.querySelector('.we-rez'), rezId=card.querySelector('.we-rez-id'),
        rezWrap=card.querySelector('.f-rez'), rezInfo=card.querySelector('.we-rez-info');
    function toggleRez(){
      var an = art2.value==='fertig';
      if(rezWrap) rezWrap.style.display = an ? '' : 'none';
      if(!an && rez){ rez.value=''; rezId.value='0'; if(rezInfo){rezInfo.textContent='';} }
    }
    function pickRez(){
      if(!rez) return;
      var v=(rez.value||'').trim().toLowerCase();
      var m=REZ.filter(function(r){return r.n.toLowerCase()===v;})[0];
      if(m){
        rezId.value=m.id;
        if(hid) hid.value=m.bi||0;                 // bekanntes Bulk-Item direkt übernehmen
        if(name && name.value.trim()==='') name.value=m.n;
        if(einh) einh.value=einhCanon(m.e)||'Stk';
        if(rezInfo){ rezInfo.textContent = m.bi ? '' : 'Noch kein Bulk-Artikel – bitte erst im Dashboard anlegen.'; rezInfo.style.color = m.bi ? '' : 'var(--err)'; }
        var box=card.querySelector('.we-aehnlich'); if(box) box.innerHTML='';
      } else { rezId.value='0'; if(rezInfo){rezInfo.textContent='';} }
    }
    if(rez){ rez.addEventListener('input',pickRez); rez.addEventListener('change',pickRez);
             rez.addEventListener('change',function(){ kgrSync(); }); rez.addEventListener('input',function(){ kgrSync(); }); }
    // Kapselgröße (nur Leerkapseln, Pflicht): aus dem Artikel, sonst aus dem Namen vorbelegen; eine Wahl des Nutzers bleibt.
    var kgrWrap=card.querySelector('.f-kgr'), kgrSel=card.querySelector('.we-kgr');
    function kgrSync(m){
      // Leerkapseln immer; Bulk nur, wenn die Größe NICHT ableitbar ist (Kapsel-Rezeptur in mehreren Größen, kein Auftrag).
      var rzSel = (art2.value==='fertig' && rezId) ? REZ.filter(function(r){return String(r.id)===String(rezId.value);})[0] : null;
      var aufH = card.querySelector('input[name="p_auftrag_id[]"]');
      var an = art2.value==='leerkapsel' || !!(rzSel && rzSel.kap && !rzSel.kg && !(aufH && parseInt(aufH.value,10)>0));
      if(kgrWrap) kgrWrap.style.display = an ? '' : 'none';
      if(!kgrSel) return;
      kgrSel.required = an;
      if(!an){ kgrSel.value=''; return; }
      if(kgrSel.value==='' || kgrSel.dataset.auto==='1'){
        var g=(m&&m.g)||kgrAusName(name.value);
        if(g){ kgrSel.value=String(g); kgrSel.dataset.auto='1'; }
      }
    }
    if(kgrSel) kgrSel.addEventListener('change',function(){ kgrSel.dataset.auto='0'; });
    art2.addEventListener('change',function(){
      // Leerkapseln zählt man in Stück, nicht in kg -> Einheit vorschlagen (nur wenn leer/Gewicht).
      if(art2.value==='leerkapsel' && einh){ einh.value='Stk'; }   // Leerkapseln zählt man in Stück
      kgrSync(ITEMS.filter(function(it){return String(it.id)===String(hid.value);})[0]);
      neuSync(card);
      toggleRez();
      pflicht(card);
      etikettToggle();
    });
    toggleRez();
    kgrSync(ITEMS.filter(function(it){return String(it.id)===String(hid.value);})[0]);
    neuSync(card);

    // --- Etikettenrollen: nur Gesamtmenge + Anzahl Rollen. Menge gleichmäßig verteilt, Aufkleber optional ---
    var rollenBox=card.querySelector('.we-rollen'), rollenZahl=card.querySelector('.we-rollenzahl'),
        rollenListe=card.querySelector('.we-rollen-liste'), rollenInfo=card.querySelector('.we-rollen-info'),
        mengeF=card.querySelector('input[name="p_menge[]"]'), blinkerF=card.querySelector('.we-blinker'),
        feldBlinker=card.querySelector('.f-blinker'), feldPak=card.querySelector('.f-pakete'),
        feldPaknr=card.querySelector('.f-paketnrn'),
        feldCharge=card.querySelector('.f-charge'), feldMhd=card.querySelector('.f-mhd');
    function mengeWert(v){ return parseFloat(String(v==null?'':v).replace(/\./g,'').replace(',','.'))||0; }
    function gesamtMenge(){ return mengeWert(mengeF&&mengeF.value); }
    function rollenInfoSync(){
      if(!rollenInfo) return;
      var ges=gesamtMenge(), n=Math.max(1,(parseInt(rollenZahl&&rollenZahl.value,10)||1));
      if(ges>0){ var basis=Math.floor(ges/n), rest=ges-basis*(n-1);
        rollenInfo.textContent = (n>1 && rest!==basis)
          ? ('= '+nf(basis)+' je Rolle, letzte '+nf(rest))
          : ('= '+nf(basis)+' je Rolle'); }
      else rollenInfo.textContent='';
    }
    function renderRollen(n, fokusIdx){
      if(!rollenListe) return;
      n=Math.max(1,n|0);
      var cur=[].map.call(rollenListe.querySelectorAll('.we-rolle-nr'),function(i){return i.value;});
      rollenListe.innerHTML='';
      for(var k=0;k<n;k++){
        var row=document.createElement('div'); row.className='bx-row'; row.style.gap='8px'; row.style.flexWrap='wrap';
        row.innerHTML='<input type="text" class="we-rolle-nr lg-code" name="p_rolle_nr['+rowKey+'][]" autocomplete="off" inputmode="numeric" maxlength="8" placeholder="Rolle '+(k+1)+': Aufkleber scannen (8 Ziffern) – leer = System legt Nummer + Aufkleber an" value="'+esc(cur[k]!==undefined?cur[k]:'')+'" style="flex:1 1 260px">';
        rollenListe.appendChild(row);
      }
      var nrs=rollenListe.querySelectorAll('.we-rolle-nr');
      nrs.forEach(function(inp,idx){ inp.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); var nx=nrs[idx+1]; if(nx) nx.focus(); } }); });
      rollenInfoSync();
      if(fokusIdx!=null){ var f=nrs[fokusIdx]; if(f) f.focus(); }
    }
    if(rollenZahl) rollenZahl.addEventListener('input',function(){ renderRollen(parseInt(rollenZahl.value,10)||1); });
    if(mengeF) mengeF.addEventListener('input',function(){ if(art2.value==='etikett') rollenInfoSync(); else gebVorschlag(); });
    var einhG=card.querySelector('[name="p_einheit[]"]'); if(einhG) einhG.addEventListener('change', gebVorschlag);
    function etikettToggle(){
      var an=art2.value==='etikett';
      if(rollenBox) rollenBox.style.display=an?'':'none';
      if(feldBlinker) feldBlinker.style.display=an?'none':'';
      [feldPak,feldPaknr,feldCharge,feldMhd].forEach(function(el){ if(el) el.style.display=an?'none':''; });
      gebRender(parseInt(pkt&&pkt.value,10)||1);
      if(blinkerF) blinkerF.required = an?false:blinkerF.required;
      if(an){ if(blinkerF) blinkerF.required=false; renderRollen(parseInt(rollenZahl&&rollenZahl.value,10)||1); }
      else { blinkerPflicht(); }
    }
    // Aus einer ankommenden Lieferung abgeleitete Rezeptur direkt als "Fertigware/Bulk" vorauswählen.
    if(p.rezeptur_name && art2){
      art2.value='fertig'; toggleRez();
      if(rez){ rez.value=p.rezeptur_name; pickRez(); }
      pflicht(card);
    }
    card.querySelector('.we-del').addEventListener('click',function(){card.remove(); if(!rows.children.length)addRow();});
    // Freigegeben-Haken je Position -> verstecktes Feld (angehakt = frei, sonst Quarantäne).
    var fb=card.querySelector('.we-frei'), fbh=card.querySelector('.we-frei-h');
    if(fb&&fbh) fb.addEventListener('change',function(){ fbh.value=fb.checked?'1':'0'; });
    pflicht(card);
    blinkerPflicht();
    // Ist eine Kiste mit Blinker gewählt, den Blinker-Code gleich in die neue Zeile eintragen.
    var bk=kisteBlinker(); if(bk){ var bf=card.querySelector('.we-blinker'); if(bf&&bf.value==='') bf.value=bk; }
    zeigeAehnlich(card);
    etikettToggle();   // Etikett-Position: Rollenblock zeigen, Blinker/Charge/MHD ausblenden
    return card;
  }
  // Ähnlichkeits-Check: warnt vor Fast-Dubletten (z. B. "Gummi Arabicum 25 kg" vs "… - 25 kg").
  // Vergleicht per Wort-Überschneidung gegen vorhandene Artikel; ein Klick übernimmt den bestehenden.
  function wtoks(s){ return String(s||'').toLowerCase().split(/[^a-zäöüß0-9]+/).filter(function(w){return w.length>=3 && !/^\d+$/.test(w);}); }
  function aehnlichItems(nm){
    var nt=wtoks(nm); if(!nt.length) return [];
    var min = nt.length===1 ? 1 : 2;
    return ITEMS.map(function(it){ var itt=wtoks(it.n+' '+(it.s||''));
        var sh=nt.filter(function(w){return itt.indexOf(w)!==-1;}).length; return {it:it,s:sh}; })
      .filter(function(x){ return x.s>=min && x.it.n.toLowerCase()!==nm.trim().toLowerCase(); })
      .sort(function(a,b){return b.s-a.s;}).slice(0,3).map(function(x){return x.it;});
  }
  // Soll-Kette 2b: unbekannter Name -> Hinweis + Knopf „Neuer Artikel“ (bewusste Neuanlage, Hinweis ans Team).
  // Bulk (Rezeptur) und Etiketten haben eigene Wege und brauchen das nicht.
  function neuSync(card){
    var nm=card.querySelector('.we-name'), hid=card.querySelector('input[name="p_item[]"]'), art2=card.querySelector('.we-art'),
        box=card.querySelector('.we-neu'), nh=card.querySelector('.we-neu-h'), ff=card.querySelector('.f-form'), fs=card.querySelector('.we-form');
    if(!box || !nh) return;
    var name=(nm.value||'').trim().toLowerCase();
    var bekannt=(hid.value && hid.value!=='0') || ITEMS.some(function(it){ return it.n.toLowerCase()===name; });
    var noetig = name!=='' && !bekannt && art2.value!=='fertig' && art2.value!=='etikett';
    if(!noetig){ nh.value='0'; box.innerHTML=''; if(ff){ ff.style.display='none'; fs.required=false; } return; }
    var neu = nh.value==='1';
    box.innerHTML = neu
      ? '<span class="muted">Wird als neuer Artikel angelegt, das Team bekommt einen Hinweis.</span> <button type="button" class="btn btn-ghost btn-sm">zurück</button>'
      : '<span style="color:var(--warn)">Nicht in der Artikelliste.</span> <button type="button" class="btn btn-ghost btn-sm">Neuer Artikel</button>';
    box.querySelector('button').addEventListener('click', function(){ nh.value = neu ? '0' : '1'; neuSync(card); });
    var formNoetig = neu && art2.value==='rohstoff';
    if(ff){ ff.style.display = formNoetig ? '' : 'none'; fs.required = formNoetig; }
  }
  function zeigeAehnlich(card){
    var box=card.querySelector('.we-aehnlich'), nm=card.querySelector('.we-name'),
        hid=card.querySelector('input[name="p_item[]"]'), einh=card.querySelector('[name="p_einheit[]"]'),
        art2=card.querySelector('.we-art');
    if(!box) return;
    if(hid.value && hid.value!=='0'){ box.innerHTML=''; return; }   // schon ein bestehender Artikel gewählt
    var tr=aehnlichItems(nm.value);
    if(!tr.length){ box.innerHTML=''; return; }
    box.innerHTML='<span class="we-ae-t">Das meintest du?</span> '+tr.map(function(it){
      return '<button type="button" class="we-ae-chip" data-id="'+it.id+'" data-n="'+esc(it.n)+'" data-e="'+esc(it.e||'')+'" data-k="'+esc(it.f==='kapselhuelle'?'kapsel':it.k)+'">'+esc(it.n)+'</button>';
    }).join(' ');
    box.querySelectorAll('.we-ae-chip').forEach(function(b){ b.addEventListener('click',function(){
      nm.value=b.getAttribute('data-n'); hid.value=b.getAttribute('data-id');
      if(einh) einh.value=einhCanon(b.getAttribute('data-e'))||'Stk';
      var a=b.getAttribute('data-k'); if(art2 && MATRIX[a]){art2.value=a; pflicht(card);}
      box.innerHTML=''; neuSync(card);
    }); });
  }
  // Kiste gewählt -> Blinker je Position optional (die Kiste blinkt beim Finden).
  function blinkerPflicht(){
    var sel=document.getElementById('weKiste'), frei = sel && sel.value!=='';
    document.querySelectorAll('.we-blinker').forEach(function(b){
      var card=b.closest('.we-pos'), art=card&&card.querySelector('.we-art');
      if(art && art.value==='etikett'){ b.required=false; return; }   // Etikett: kein Blinker, Inventaraufkleber je Rolle
      b.required=!frei;
    });
    document.querySelectorAll('.f-blinker label').forEach(function(l){ l.innerHTML = frei ? 'Blinker <span class="muted">(optional – von der Kiste)</span>' : 'Blinker *'; });
  }
  // Blinker-Code der aktuell gewählten Kiste (leer, wenn keine Kiste / kein Blinker).
  var vorKisteBl='';
  function kisteBlinker(){
    var hid=document.getElementById('weKiste'); if(!hid||!hid.value) return '';
    var m=KISTEN.filter(function(k){return String(k.id)===String(hid.value);})[0];
    return m&&m.bl?m.bl:'';
  }
  // Blinker-Felder an die Kiste anpassen: leere ODER den alten Kisten-Code ersetzen (manuelle bleiben).
  function kisteBlinkerUebernehmen(neu){
    document.querySelectorAll('.we-blinker').forEach(function(b){ if(b.value===''||b.value===vorKisteBl) b.value=neu; });
    vorKisteBl=neu;
  }
  // Kiste: EIN Feld – tippen (Vorschläge via datalist) ODER Barcode scannen. Setzt die versteckte kiste_id.
  (function(){
    var inp=document.getElementById('weKisteSuche'), hid=document.getElementById('weKiste'),
        info=document.getElementById('weKisteInfo');
    if(!inp||!hid) return;
    function pick(){
      var v=(inp.value||'').trim();
      if(v===''){ hid.value=''; info.textContent=''; kisteBlinkerUebernehmen(''); blinkerPflicht(); return; }
      var m=KISTEN.filter(function(k){return k.n.toLowerCase()===v.toLowerCase();})[0]            // exakt per Name
          || KISTEN.filter(function(k){return k.b && k.b.toLowerCase()===v.toLowerCase();})[0];   // oder per Barcode
      if(m){ hid.value=m.id; inp.value=m.n;
        info.textContent='Kiste: '+m.n+(m.bl?(' · Blinker '+m.bl):' · kein Blinker'); info.style.color='var(--gruen)';
        kisteBlinkerUebernehmen(m.bl||'');   // Blinker der Kiste in die Positionen eintragen
      }
      else { hid.value=''; info.textContent='Keine Kiste erkannt – weiter tippen oder Barcode scannen.'; info.style.color=''; kisteBlinkerUebernehmen(''); }
      blinkerPflicht();
    }
    inp.addEventListener('input', pick);
    inp.addEventListener('change', pick);
    inp.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); pick(); } });
  })();
  function pflicht(tr){
    var art=tr.querySelector('.we-art').value, reg=MATRIX[art]||{mhd:0,charge:0};
    var mhd=tr.querySelector('.we-mhd'), ch=tr.querySelector('.we-charge');
    mhd.required=!!reg.mhd; ch.required=!!reg.charge;
    tr.querySelector('.lbl-mhd').textContent    = reg.mhd    ? 'MHD *' : 'MHD';
    tr.querySelector('.lbl-charge').textContent = reg.charge ? 'Charge-Nr. *' : 'Charge-Nr.';
    // Status ist Regel, keine Wahl: Rohstoffe/Bulk -> Quarantäne (Freigabe nach Prüfung), Verpackung/Etikett -> frei.
    var fb=tr.querySelector('.we-frei'), fbh=tr.querySelector('.we-frei-h');
    // Vorbelegung nur beim Wechsel der Warenart; danach gilt die Wahl des Mitarbeiters (Haken raus = Problem/Quarantäne).
    if(fb && fb.dataset.art!==art){ fb.dataset.art=art; fb.checked=!reg.q; }
    if(fb && !fb.dataset.hooked){ fb.dataset.hooked='1'; fb.addEventListener('change',function(){ if(fbh) fbh.value=fb.checked?'1':'0'; }); }
    if(fbh && fb) fbh.value=fb.checked?'1':'0';
  }
  document.getElementById('weAdd').addEventListener('click',function(){addRow();});
  rows.addEventListener('input',function(e){ var tr=e.target.closest('.we-pos'); if(tr)pflicht(tr); });
  addRow(); // Startzeile

  // --- Scan: Dateien/Kamera sammeln + senden ---
  var dateien=[]; // {blob, name, isPdf}
  var thumbs=document.getElementById('weThumbs'), scanBtn=document.getElementById('weScan'), info=document.getElementById('weScanInfo');
  function renderThumbs(){
    thumbs.innerHTML='';
    dateien.forEach(function(d,i){
      var div=document.createElement('div'); div.className='we-thumb';
      if(d.isPdf){ div.innerHTML='<div class="pdf">PDF</div>'; }
      else { var img=document.createElement('img'); img.src=URL.createObjectURL(d.blob); div.appendChild(img); }
      var x=document.createElement('button'); x.className='x'; x.textContent='×'; x.type='button';
      x.onclick=function(){dateien.splice(i,1);renderThumbs();}; div.appendChild(x);
      thumbs.appendChild(div);
    });
    scanBtn.disabled = dateien.length===0;
    info.textContent = dateien.length ? dateien.length+' Seite(n) bereit' : '';
    var hint=document.getElementById('weScanHint'); if(hint) hint.style.display = dateien.length ? 'none' : '';
  }
  document.getElementById('weFile').addEventListener('change',function(e){
    [].forEach.call(e.target.files,function(f){ dateien.push({blob:f,name:f.name,isPdf:/pdf$/i.test(f.type)||/\.pdf$/i.test(f.name)}); });
    e.target.value=''; renderThumbs();
  });

  // Kamera
  var stream=null, camBox=document.getElementById('weCamBox'), video=document.getElementById('weVideo'), canvas=document.getElementById('weCanvas');
  document.getElementById('weCamStart').addEventListener('click',function(){
    navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}}).then(function(s){
      stream=s; video.srcObject=s; video.play(); camBox.style.display='';
    }).catch(function(){ info.textContent='Kamera nicht verfügbar – bitte Datei wählen.'; });
  });
  function camStop(){ if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;} camBox.style.display='none'; }
  document.getElementById('weCamStop').addEventListener('click',camStop);
  document.getElementById('weShot').addEventListener('click',function(){
    canvas.width=video.videoWidth; canvas.height=video.videoHeight;
    canvas.getContext('2d').drawImage(video,0,0);
    canvas.toBlob(function(b){ dateien.push({blob:b,name:'foto'+(dateien.length+1)+'.jpg',isPdf:false}); renderThumbs(); },'image/jpeg',0.85);
  });

  // Auslesen
  scanBtn.addEventListener('click',function(){
    if(!dateien.length)return;
    scanBtn.disabled=true; info.textContent='Lese Lieferschein …';
    var fd=new FormData(); fd.append('aktion','scan');
    dateien.forEach(function(d){ fd.append('dateien[]', d.blob, d.name); });
    fetch('?p=we',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
      scanBtn.disabled=false;
      if(!j.ok){ info.textContent='Fehler: '+(j.fehler||'unbekannt'); return; }
      // Lieferant vorbelegen: Namen ins Feld (wird beim Buchen gefunden oder neu angelegt).
      if(j.kopf.lieferant){ var lname=document.getElementById('weLiefName'); if(lname) lname.value=j.kopf.lieferant; }
      var an=document.getElementById('weAuftragNr'); if(an) an.value=j.kopf.auftrag_nr||'';
      info.textContent = (j.kopf.lieferant?('Lieferant: '+j.kopf.lieferant+'  '):'') + (j.positionen.length+' Position(en) erkannt');
      rows.innerHTML='';
      if(!j.positionen.length){ addRow(); }
      else j.positionen.forEach(function(p){ addRow(p); });
      // Sichtbar machen, dass etwas passiert ist: sanft runter zu den Positionen.
      setTimeout(function(){ var ziel=(rows.closest('.bx-panel')||rows); ziel.scrollIntoView({behavior:'smooth',block:'start'}); }, 60);
    }).catch(function(){ scanBtn.disabled=false; info.textContent='Netzwerk-/Serverfehler beim Auslesen.'; });
  });
  // Versandlabel/Tracking scannen (Handscanner tippt Nummer + Enter) -> passende Lieferung laden.
  var track=document.getElementById('weTrack'), trackInfo=document.getElementById('weTrackInfo');
  function trackSuchen(){
    var code=(track.value||'').trim(); if(code==='')return;
    if(/^BX\d{1,9}-([0-9A-F]{8}|[0-9A-F]{5})$/i.test(code)){ track.value=''; zeigeWeg('aufkleber'); laIn.value=code; laScan(); return; }   // Gebindeaufkleber erkannt (Paket 12.11.2026 Teil 5)
    trackInfo.textContent='Suche Lieferung …';
    var fd=new FormData(); fd.append('aktion','tracking'); fd.append('code',code);
    fetch('?p=we',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
      if(!j.ok){ trackInfo.textContent=(j.fehler||'Keine Lieferung gefunden.'); return; }
      trackInfo.textContent='Lieferung geladen: '+(j.kopf.lieferant||'')+(j.kopf.nummer?(' · '+j.kopf.nummer):'')+' ('+j.positionen.length+' Position(en))';
      var bidF=document.getElementById('weBestellungId'); if(bidF) bidF.value=(j.kopf.id||'');   // Lieferung merken -> beim Buchen als angekommen markieren
      if(j.kopf.lieferant_id){ var ls=document.getElementById('weLief'); if(ls) ls.value=String(j.kopf.lieferant_id); }
      var ln=document.getElementById('weLiefName'); if(ln && j.kopf.lieferant) ln.value=j.kopf.lieferant;
      rows.innerHTML='';
      if(!j.positionen.length){ addRow(); } else j.positionen.forEach(function(p){ addRow(p); });
      var b=rows.querySelector('.we-blinker'); if(b) b.focus();   // direkt weiter scannen (Blinker)
    }).catch(function(){ trackInfo.textContent='Netzwerk-/Serverfehler.'; });
  }
  if(track){
    track.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); trackSuchen(); } });
    track.addEventListener('change',trackSuchen);
  }

  // Lieferanten-Gebindeaufkleber (BX…) scannen: ein Scan = ein Gebinde; Stand „8 von 10 erfasst“; Abschluss auch unvollständig.
  var laIn=document.getElementById('weLa'), laInfo=document.getElementById('weLaInfo'), laStand=document.getElementById('weLaStand'),
      laFehlt=document.getElementById('weLaFehlt'), laAbs=document.getElementById('weLaAbschluss'), laBid=0;
  function laZeigen(j){
    if(j.stand){ laStand.textContent=j.stand.erfasst+' von '+j.stand.gesamt+' erfasst'; laFehlt.textContent=j.stand.fehlt.length?('Noch offen: '+j.stand.fehlt.join(', ')):''; }
    if(j.bestellung_id){ laBid=j.bestellung_id; laAbs.hidden=false; }
  }
  function laScan(){
    var code=(laIn.value||'').trim(); if(code==='')return;
    laIn.value=''; laInfo.textContent='Buche …'; laInfo.style.color='';
    var fd=new FormData(); fd.append('aktion','la_scan'); fd.append('code',code);
    fetch('?p=we',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
      var i=j.info||{};
      laInfo.textContent=(j.art==='gebucht'?'✓ ':'')+(j.meldung||'')+(i.artikel?' · '+i.artikel:'')+(i.charge?' · Charge '+i.charge:'')+(i.bestellung?' · '+i.bestellung:'');
      laInfo.style.color = j.art==='gebucht' ? (j.quarantaene?'#8a5a00':'#1D9E75') : '#8f231b';
      laZeigen(j); laIn.focus();
    }).catch(function(){ laInfo.textContent='Netzwerk-/Serverfehler.'; laInfo.style.color='#8f231b'; });
  }
  if(laIn){ laIn.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); laScan(); } }); laIn.addEventListener('change',laScan); }
  if(laAbs) laAbs.addEventListener('click',function(){
    if(!laBid) return;
    if(!confirm('Wareneingang dieser Bestellung abschließen?')) return;
    var fd=new FormData(); fd.append('aktion','la_abschluss'); fd.append('bestellung_id',laBid);
    fetch('?p=we',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){ laInfo.textContent=j.meldung||''; laInfo.style.color=j.ok?'#1D9E75':'#8f231b'; laZeigen(j); });
  });
  // Direktaufruf ?p=we&weg=aufkleber&code=BX… (aus der Suche): gleich buchen.
  (function(){ var u=new URLSearchParams(location.search); if(u.get('weg')==='aufkleber' && u.get('code') && laIn){ setTimeout(function(){ laIn.value=u.get('code'); laScan(); },200); } })();

  // Rezepturnummer-Aufkleber (R…) scannen -> Rezeptur/Produkt als Fertigware-Position vorausfüllen.
  var rst=document.getElementById('weRsticker'), rstInfo=document.getElementById('weRstickerInfo');
  function rstSuchen(){
    var code=(rst.value||'').trim(); if(code==='')return;
    if(/^BX\d{1,9}-([0-9A-F]{8}|[0-9A-F]{5})$/i.test(code)){ rst.value=''; zeigeWeg('aufkleber'); laIn.value=code; laScan(); return; }   // Gebindeaufkleber erkannt (Paket 12.11.2026 Teil 5)
    rstInfo.textContent='Suche Rezeptur …';
    var fd=new FormData(); fd.append('aktion','rsticker'); fd.append('code',code);
    fetch('?p=we',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
      if(!j.ok){ rstInfo.textContent=(j.fehler||'Keine Rezeptur gefunden.'); return; }
      var p=j.position||{};
      rstInfo.textContent='Erkannt: '+(p.rezeptur_name||p.item_name||'Rezeptur')+(p.item_id?'':' (noch kein Bulk-Artikel – im Dashboard anlegen)');
      rows.innerHTML=''; addRow(p);
      rst.value='';
      var b=rows.querySelector('.we-blinker'); if(b) b.focus();   // weiter scannen
    }).catch(function(){ rstInfo.textContent='Netzwerk-/Serverfehler.'; });
  }
  if(rst){
    rst.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); rstSuchen(); } });
    rst.addEventListener('change',rstSuchen);
  }

  // --- Etiketten einbuchen: Produkt -> Kunde/Version -> Design + Maße prüfen -> erst dann Menge/Rollen ---
  var ET_PROD = <?= json_encode(array_map(fn($ep)=>['id'=>$ep['id'],'k'=>$ep['kunde_id'],'i'=>$ep['item_id'],'n'=>$ep['n']], $etProdukte), JSON_UNESCAPED_UNICODE) ?>;
  var ET_KUNDEN = <?= json_encode(array_map(fn($k)=>['id'=>(int)$k['id'],'n'=>(string)$k['firma']], $etKunden), JSON_UNESCAPED_UNICODE) ?>;
  var ET_ALLE = <?= json_encode(array_map(fn($pp)=>['id'=>(int)$pp['id'],'n'=>trim(($pp['nummer'] ? $pp['nummer'] . ' · ' : '') . $pp['name'])], $etAlleProd), JSON_UNESCAPED_UNICODE) ?>;
  var kopfP=document.getElementById('weKopf'), posP=document.getElementById('wePosPanel'), buchBtn=document.getElementById('weBuchen'),
      etProd=document.getElementById('weEtProdukt'), etInfo=document.getElementById('weEtInfo'), etWahl=document.getElementById('weEtWahl'),
      etPruef=document.getElementById('weEtPruef'), etOk=document.getElementById('weEtOk'), etGewaehlt=null, etLetzt='', etGlas=null;
  var BUCH_TXT=buchBtn?buchBtn.textContent:'';
  function zmm(x){ return (Math.round(Number(x)*10)/10).toString().replace('.',','); }
  function etMasse(e){
    if(e.breite && e.hoehe) return zmm(e.breite)+' × '+zmm(e.hoehe)+' mm';
    return e.format||'';
  }
  function glasMasse(g){ return (g && g.breite && g.hoehe) ? (zmm(g.breite)+' × '+zmm(g.hoehe)+' mm') : ''; }
  function etPanels(sichtbar){ if(kopfP) kopfP.hidden=!sichtbar; if(posP) posP.hidden=!sichtbar; }
  function etZeigen(e){
    etGewaehlt=e;
    etWahl.querySelectorAll('.we-et-opt').forEach(function(b){ b.classList.toggle('on', +b.getAttribute('data-id')===e.id); });
    var em=etMasse(e), gm=glasMasse(etGlas);
    // Maßgeblich ist das Endformat AUS DEM GLAS/BEHÄLTER (aus dem Auftrag/Produkt), nicht das am
    // Etikett-Artikel gespeicherte Maß. Ist ein Glas da, zeigen wir dessen Endformat als „Größe".
    document.getElementById('weEtMass').textContent = gm || em || 'Maße nicht hinterlegt';
    document.getElementById('weEtMassQuelle').textContent = gm ? '(aus dem Glas im Auftrag)' : (em ? '(am Etikett hinterlegt – kein Glas gefunden)' : '');
    document.getElementById('weEtGlas').textContent = etGlas && etGlas.name
      ? (etGlas.name + (etGlas.format ? ' · ' + etGlas.format + ' mm' : '')) : '– (kein Behälter am Auftrag/Produkt)';
    document.getElementById('weEtMaterial').textContent = e.material || '–';
    document.getElementById('weEtVersion').textContent = e.kundenetikett ? ('v'+e.version) : 'Standard-Etikett';
    document.getElementById('weEtKunde').textContent = e.kunde || '–';
    document.getElementById('weEtArtikel').textContent = e.name;
    var warn=document.getElementById('weEtWarn'), hinw=[];
    var vz=document.getElementById('weEtVorschau');
    if(e.dok){ bxEtikettVorschau(vz, '?p=dok&id='+e.dok, e.pdf); }
    else { vz.innerHTML='<div class="we-et-warn" style="margin:0">Kein freigegebenes Etikett-Design hinterlegt.</div>'; hinw.push('Ohne freigegebenes Design lässt sich nur die Größe prüfen.'); }
    if(!gm && !em) hinw.push('Für dieses Etikett sind keine Maße hinterlegt.');
    // Abweichung Etikett-Maß vs. Glas-Endformat (Toleranz 2 mm): NUR neutraler Hinweis, nie Sperre
    // (Soll-Handbuch 4.5, Entscheidung Nico 08.11.2026: Etiketten sind oft 2-5 mm größer = Sicherheitsmaß).
    var mh=document.getElementById('weEtMassHinweis'), mhTxt='';
    var eG=Math.max(+e.breite||0,+e.hoehe||0), eK=Math.min(+e.breite||0,+e.hoehe||0),   // Ausrichtung egal (60 x 172 = 172 x 60)
        gG=etGlas?Math.max(+etGlas.breite||0,+etGlas.hoehe||0):0, gK=etGlas?Math.min(+etGlas.breite||0,+etGlas.hoehe||0):0;
    if(eK>0 && gK>0 && (Math.abs(eG-gG)>2 || Math.abs(eK-gK)>2)){
      mhTxt='Hinweis: Etikett '+em+', Glas-Endformat '+gm+(etGlas.name?' ('+etGlas.name+')':'')+' – Maße weichen ab. Etiketten sind oft etwas größer, einbuchen ist möglich.';
    }
    if(mh){ mh.hidden=!mhTxt; mh.textContent=mhTxt; }
    warn.hidden=!hinw.length; warn.textContent=hinw.join(' ');
    etPruef.hidden=false; etPanels(false);
    if(etOk) etOk.disabled=false;
  }
  function etWaehle(liste, kunde){
    // Eintrag mit Kunde gewählt -> nur dessen Etikett(en) zeigen (sonst alle des Produkts).
    if(kunde!=null){ var nurK=liste.filter(function(e){ return (e.kunde_id||0)===kunde; }); if(nurK.length) liste=nurK; }
    etWahl.innerHTML=''; etPruef.hidden=true; etGewaehlt=null; etPanels(false);
    if(!liste.length){ etInfo.textContent='Zu diesem Produkt gibt es keinen Etikett-Artikel.'; return; }
    etInfo.textContent = liste.length>1 ? 'Mehrere Etiketten – bitte Kunde/Version wählen:' : 'Etikett gefunden:';
    liste.forEach(function(e){
      var b=document.createElement('button'); b.type='button'; b.className='we-et-opt'; b.setAttribute('data-id',e.id);
      b.innerHTML='<strong>'+esc(e.kunde||'ohne Kunde')+'</strong> · '+(e.kundenetikett?('v'+e.version):'Standard')+'<br><span class="muted" style="font-size:12px">'+esc(etMasse(e)||'Maße fehlen')+(e.dok?'':' · kein Design')+'</span>';
      b.addEventListener('click', function(){ etZeigen(e); });
      etWahl.appendChild(b);
    });
    if(liste.length===1) etZeigen(liste[0]);
  }
  function etProduktSuchen(){
    var v=(etProd.value||'').trim().toLowerCase(); if(v===etLetzt) return;
    var m=ET_PROD.filter(function(p){ return p.n.toLowerCase()===v; })[0];
    if(!m){ etLetzt=''; return; }
    etLetzt=v; etInfo.textContent='Lade Etiketten …';
    var fd=new FormData(); fd.append('aktion','etikett_produkt'); fd.append('produkt_id', m.id); fd.append('item_id', m.i||0);
    fetch('?p=we',{method:'POST',body:fd}).then(function(r){return r.json();})
      .then(function(j){ etGlas=(j&&j.glas)||null; etWaehle((j&&j.etiketten)||[], m.i?null:m.k); })
      .catch(function(){ etInfo.textContent='Netzwerk-/Serverfehler.'; etLetzt=''; });
  }
  if(etProd){ etProd.addEventListener('input', etProduktSuchen); etProd.addEventListener('change', etProduktSuchen); }
  // --- Anderes Etikett von Hand anlegen ---
  var etNeu=document.getElementById('weEtNeu'), etNeuAuf=document.getElementById('weEtNeuAuf'), etNF=document.getElementById('weEtNFehler');
  function etFeld(id){ return document.getElementById(id); }
  function etTreffer(liste, v){ v=(v||'').trim().toLowerCase(); return v ? liste.filter(function(x){ return x.n.toLowerCase()===v; })[0] : null; }
  if(etNeuAuf) etNeuAuf.addEventListener('click', function(){
    etNeu.hidden=false; etNeuAuf.hidden=true; etWahl.innerHTML=''; etPruef.hidden=true; etGewaehlt=null; etPanels(false); etNF.hidden=true;
    ['weEtNKunde','weEtNProd','weEtNBez','weEtNB','weEtNH','weEtNMat'].forEach(function(id){ etFeld(id).value=''; });   // frisch beginnen
    etInfo.textContent='Neues Etikett: Angaben eintragen, danach folgen Prüfung und Menge.';
    var k=etTreffer(ET_PROD, etProd.value);   // aus dem oberen Feld vorbelegen, falls schon etwas gewählt war
    if(k){ var kk=ET_KUNDEN.filter(function(x){ return x.id===k.k; })[0]; if(kk) etFeld('weEtNKunde').value=kk.n; var pp=ET_ALLE.filter(function(x){ return x.id===k.id; })[0]; if(pp) etFeld('weEtNProd').value=pp.n; }
    setTimeout(function(){ etFeld('weEtNKunde').focus(); }, 60);
  });
  if(etFeld('weEtNAbbr')) etFeld('weEtNAbbr').addEventListener('click', function(){ etNeu.hidden=true; etNeuAuf.hidden=false; });
  if(etFeld('weEtNAnlegen')) etFeld('weEtNAnlegen').addEventListener('click', function(){
    var kv=etFeld('weEtNKunde').value.trim(), pv=etFeld('weEtNProd').value.trim(), k=etTreffer(ET_KUNDEN, kv), p=etTreffer(ET_ALLE, pv), bez=etFeld('weEtNBez').value.trim();
    var f=[];
    if(kv && !k) f.push('Kunde „'+kv+'“ nicht gefunden – bitte aus der Liste wählen oder leer lassen.');
    if(pv && !p) f.push('Produkt „'+pv+'“ nicht gefunden – bitte aus der Liste wählen oder leer lassen.');
    if(!p && !bez) f.push('Ohne Produkt bitte eine Bezeichnung eingeben.');
    etNF.hidden=!f.length; etNF.textContent=f.join(' '); if(f.length) return;
    var btn=this; btn.disabled=true;
    var fd=new FormData(); fd.append('aktion','etikett_anlegen'); fd.append('kunde_id', k?k.id:0); fd.append('produkt_id', p?p.id:0);
    fd.append('bezeichnung', bez); fd.append('breite', etFeld('weEtNB').value); fd.append('hoehe', etFeld('weEtNH').value); fd.append('material', etFeld('weEtNMat').value);
    fetch('?p=we',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
      btn.disabled=false;
      if(!j||!j.ok){ etNF.hidden=false; etNF.textContent=(j&&j.fehler)||'Anlegen fehlgeschlagen.'; return; }
      etNeu.hidden=true; etNeuAuf.hidden=false;
      etGlas=j.glas||null;
      etWaehle(j.etiketten||[]);
      etInfo.textContent = j.vorhanden ? 'Für Produkt und Kunde gibt es dieses Etikett schon – es wird verwendet:' : 'Etikett angelegt:';
    }).catch(function(){ btn.disabled=false; etNF.hidden=false; etNF.textContent='Netzwerk-/Serverfehler.'; });
  });
  if(etOk) etOk.addEventListener('click', function(){
    if(!etGewaehlt) return;
    rows.innerHTML='';
    var card=addRow({item_id:etGewaehlt.id, item_name:etGewaehlt.name, verpackung_rolle:'etikett', einheit:'Stk'});
    etPanels(true); etOk.disabled=true;
    // Teil 1: gibt es eine offene Bestellung über dieses Etikett? Dann die Zeile daran binden.
    (function(c, iid){ var fdb=new FormData(); fdb.append('aktion','bp_fuer_item'); fdb.append('item_id', iid);
      fetch('?p=we',{method:'POST',body:fdb}).then(function(r){return r.json();}).then(function(j){ if(j.ok && j.bp && c) bpSetzen(c, j.bp); }).catch(function(){}); })(card, etGewaehlt.id);
    var mg=card&&card.querySelector('input[name="p_menge[]"]');
    if(posP) posP.scrollIntoView({behavior:'smooth',block:'start'});
    if(mg) setTimeout(function(){ mg.focus(); }, 300);
  });

  // --- Kacheln: Methode wählen -> passender Weg öffnet sich ---
  var startBox=document.getElementById('weStart'), arbeit=document.getElementById('weArbeit');
  function zeigeWeg(w){
    if(w==='liste' && typeof liAndere!=='undefined' && liAndere && liAndere.dataset.gewaehlt==='1') liAndere.click();   // frisch: ganze Liste zeigen
    startBox.hidden=true; arbeit.hidden=false;
    document.querySelectorAll('.we-weg').forEach(function(p){ p.hidden = p.getAttribute('data-w')!==w; });
    // Etiketten einbuchen: Ziel/Positionen erst nach der Sichtprüfung (Design + Größe) zeigen.
    etPanels(w!=='etikett' && w!=='aufkleber');   // Aufkleber-Scan bucht direkt, ohne Positionsliste
    if(buchBtn) buchBtn.textContent = w==='etikett' ? 'Etiketten buchen' : BUCH_TXT;
    if(w==='etikett'){ etProd.value=''; etLetzt=''; etWahl.innerHTML=''; etPruef.hidden=true; etGewaehlt=null; if(etNeu){ etNeu.hidden=true; etNeuAuf.hidden=false; } setTimeout(function(){ etProd.focus(); },60); }
    rows.innerHTML=''; addRow();   // frische, leere Position
    var bidF=document.getElementById('weBestellungId'); if(bidF) bidF.value='';   // kein Lieferungsbezug bei manuellem/Scan-Weg
    if(w==='tracking'){ var t=document.getElementById('weTrack'); if(t) setTimeout(function(){t.focus();},60); }
    if(w==='rsticker'){ var rs=document.getElementById('weRsticker'); if(rs) setTimeout(function(){rs.focus();},60); }
    if(w==='aufkleber'){ var la=document.getElementById('weLa'); if(la) setTimeout(function(){la.focus();},60); }
    arbeit.scrollIntoView({behavior:'smooth',block:'start'});
  }
  document.querySelectorAll('.we-kachel[data-weg]').forEach(function(b){ b.addEventListener('click',function(){ zeigeWeg(b.getAttribute('data-weg')); }); });
  // Zurück-Knopf oben: Ist eine Lieferung aus der Liste gewählt, geht er „← Zurück zur Liste", sonst wie bisher zur Methodenwahl.
  var weBack=document.getElementById('weBack');
  weBack.addEventListener('click',function(){
    if(typeof liAndere!=='undefined' && liAndere && liAndere.dataset.gewaehlt==='1'){ liAndere.click(); window.scrollTo({top:0,behavior:'smooth'}); return; }
    arbeit.hidden=true; startBox.hidden=false; window.scrollTo({top:0,behavior:'smooth'}); });

  // Liste: ankommende Sendung wählen -> Positionen laden
  var liSuche=document.getElementById('weListeSuche'), liAndere=document.getElementById('weListeAndere'), liListe=document.getElementById('weListe');
  function liFilter(){ var q=(liSuche&&liSuche.value||'').trim().toLowerCase(); document.querySelectorAll('.we-listitem').forEach(function(x){ x.hidden = q!=='' && (x.getAttribute('data-such')||'').indexOf(q)<0; }); }
  if(liSuche) liSuche.addEventListener('input', liFilter);
  function liZuklappen(gew){ document.querySelectorAll('.we-listitem').forEach(function(x){ x.hidden = x!==gew; x.classList.toggle('gewaehlt', x===gew); });
    if(liSuche) liSuche.hidden=true; if(liAndere) liAndere.dataset.gewaehlt='1'; if(liListe) liListe.classList.add('zu');
    var wb=document.getElementById('weBack'); if(wb) wb.textContent='← Zurück zur Liste'; }
  if(liAndere) liAndere.addEventListener('click', function(){ document.querySelectorAll('.we-listitem').forEach(function(x){ x.classList.remove('gewaehlt'); });
    liAndere.dataset.gewaehlt='0'; var wb=document.getElementById('weBack'); if(wb) wb.textContent='← Andere Methode';
    if(liSuche){ liSuche.hidden=false; liFilter(); liSuche.focus(); } if(liListe) liListe.classList.remove('zu');
    var bidF=document.getElementById('weBestellungId'); if(bidF) bidF.value=''; rows.innerHTML=''; addRow(); var li=document.getElementById('weListeInfo'); if(li) li.textContent=''; });
  document.querySelectorAll('.we-listitem').forEach(function(b){
    b.addEventListener('click',function(){
      liZuklappen(b);
      var pz=document.querySelector('.we-weg[data-w="liste"]'); if(pz) pz.scrollIntoView({behavior:'smooth',block:'start'});
      var id=b.getAttribute('data-id'), linfo=document.getElementById('weListeInfo');
      if(linfo) linfo.textContent='Lade Lieferung …';
      var bidF=document.getElementById('weBestellungId'); if(bidF) bidF.value=id;   // Lieferung merken -> beim Buchen als angekommen markieren
      var fd=new FormData(); fd.append('aktion','lieferung'); fd.append('id',id);
      fetch('?p=we',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(j){
        if(!j.ok){ if(linfo) linfo.textContent=(j.fehler||'Nicht gefunden.'); return; }
        if(linfo) linfo.textContent='Geladen: '+(j.kopf.lieferant||'')+' ('+j.positionen.length+' Position(en))';
        if(j.kopf.lieferant_id){ var ls=document.getElementById('weLief'); if(ls) ls.value=String(j.kopf.lieferant_id); }
        var ln=document.getElementById('weLiefName'); if(ln && j.kopf.lieferant) ln.value=j.kopf.lieferant;
        rows.innerHTML=''; if(!j.positionen.length){ addRow(); } else j.positionen.forEach(function(p){ addRow(p); });
        var blk=rows.querySelector('.we-blinker'); if(blk) blk.focus();
      }).catch(function(){ if(linfo) linfo.textContent='Serverfehler.'; });
    });
  });

  // Direkter Einstieg (z. B. Startseite „Etiketten einbuchen"): ?p=we&weg=etikett öffnet den Etiketten-Weg.
  (function(){ var m=/[?&]weg=([a-z]+)/.exec(location.search); if(m && (m[1]==='regulaer' || document.querySelector('.we-weg[data-w="'+m[1]+'"]'))) zeigeWeg(m[1]); })();

  // Aus „Erwartete Lieferungen -> Einbuchen": ?lieferung=ID öffnet „Aus Liste wählen" und lädt die Lieferung sofort.
  (function(){
    var m=/[?&]lieferung=(\d+)/.exec(location.search); if(!m) return;
    var btn=document.querySelector('.we-listitem[data-id="'+m[1]+'"]'); if(!btn) return;
    zeigeWeg('liste'); btn.click();
  })();

  window.addEventListener('beforeunload',camStop);
})();
</script>
<?php
fuss();
