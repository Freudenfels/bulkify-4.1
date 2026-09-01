<?php
// Kundenportal – eigene, kundenfreundliche Ansicht per Magic-Link (Token). Keine internen Zahlen (EK/Marge).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
$k = $token ? one("SELECT * FROM kunden WHERE portal_token=?", [$token]) : null;

// Angebot bestätigen (Kundenaktion) -> löst Auftrag + Rechnung aus
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'bestaetigen') {
    $aid = (int)($_POST['angebot_id'] ?? 0); $sid = (int)($_POST['staffel'] ?? 0);
    $ang = $aid ? one("SELECT * FROM angebot WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]) : null;
    if ($ang && in_array($ang['status'], ['offen','gesendet'], true) && $sid > 0) {
        q("UPDATE angebot_staffel SET bestaetigt=0 WHERE angebot_id=?", [$aid]);
        q("UPDATE angebot_staffel SET bestaetigt=1 WHERE id=? AND angebot_id=?", [$sid, $aid]);
        q("UPDATE angebot SET status='bestaetigt' WHERE id=?", [$aid]);
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Angebot ' . $ang['nummer'] . ' im Portal bestätigt.', 'angebot', 'angebot', $aid);
        auftrag_aus_angebot($aid);
    }
    header('Location: ?p=portal&token=' . $token . '&v=bestellungen&ok=1'); exit;
}

// Etikett-Design zum Auftrag hochladen (Kunde)
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'etikett_upload') {
    $aid = (int)($_POST['auftrag_id'] ?? 0);
    if ($aid && (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid]) === (int)$k['id']) etikett_upload($aid);
    header('Location: ?p=portal&token=' . $token . '&v=bestellung&aid=' . $aid . '&etikett=1'); exit;
}
// Etikett-Design herunterladen (nur eigener Auftrag)
if ($k && ($_GET['v'] ?? '') === 'etikett_datei') {
    $aid = (int)($_GET['aid'] ?? 0);
    $ok = $aid && (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid]) === (int)$k['id'];
    $d = $ok ? etikett_datei($aid) : null;
    $pf = $d ? BX_UPLOADS . '/' . basename((string)$d['datei']) : '';
    if (!$d || !is_file($pf)) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($d['datei_orig'] ?: 'etikett')) . '"');
    header('Content-Length: ' . filesize($pf));
    readfile($pf); exit;
}

// Angebot ablehnen (mit Begründung) -> Status zurück, Team überarbeitet
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'angebot_ablehnen') {
    $aid = (int)($_POST['angebot_id'] ?? 0);
    $ang = $aid ? one("SELECT * FROM angebot WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]) : null;
    if ($ang && in_array($ang['status'], ['offen','gesendet'], true)) {
        q("UPDATE angebot SET status='abgelehnt', ablehnung_grund=? WHERE id=?", [trim($_POST['grund'] ?? ''), $aid]);
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Angebot ' . $ang['nummer'] . ' im Portal abgelehnt' . (trim($_POST['grund'] ?? '') !== '' ? ': ' . trim($_POST['grund']) : '.'), 'angebot', 'angebot', $aid);
    }
    header('Location: ?p=portal&token=' . $token . '&v=angebote&abgelehnt=1'); exit;
}

// Angebot: eine Matrix-Zelle (Stückzahl × Bestellmenge × Verpackung) verbindlich annehmen -> Auto-Kette
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'zelle_annehmen') {
    $aid = (int)($_POST['angebot_id'] ?? 0);
    $ang = $aid ? one("SELECT * FROM angebot WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]) : null;
    if ($ang && in_array($ang['status'], ['offen','gesendet'], true)) {
        $auf = auftrag_aus_zelle($aid, (int)($_POST['stueck'] ?? 0), (int)($_POST['verpackung_id'] ?? 0), (int)($_POST['bestellmenge'] ?? 0));
        if ($auf) {
            q("UPDATE angebot SET status='bestaetigt' WHERE id=?", [$aid]);
            log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Angebot ' . $ang['nummer'] . ' im Portal bestätigt.', 'angebot', 'angebot', $aid);
            header('Location: ?p=portal&token=' . $token . '&v=bestellungen&ok=1'); exit;
        }
    }
    header('Location: ?p=portal&token=' . $token . '&v=angebote'); exit;
}
// Angebot aus der Kundenliste entfernen (Löschen = ausblenden)
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'angebot_loeschen') {
    $aid = (int)($_POST['angebot_id'] ?? 0);
    if ($aid) q("UPDATE angebot SET kunde_ausgeblendet=1 WHERE id=? AND kunde_id=?", [$aid, (int)$k['id']]);
    header('Location: ?p=portal&token=' . $token . '&v=angebote&geloescht=1'); exit;
}

// Neue Rezepturanfrage vom Kunden entgegennehmen
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anfrage_senden') {
    $form = in_array($_POST['form'] ?? '', ['kapsel','tablette','softgel','stick','pulver','fluessig'], true) ? $_POST['form'] : 'kapsel';
    q("INSERT INTO rezeptur_anfrage (nummer,kunde_id,darreichungsform,produktname,notiz,status) VALUES (?,?,?,?,?,'neu')",
      [naechste_nummer('RZA'), (int)$k['id'], $form, trim($_POST['produktname'] ?? '') ?: null, trim($_POST['notiz'] ?? '')]);
    $aid = insert_id();
    $bez = $_POST['w_bez'] ?? []; $wm = $_POST['w_menge'] ?? []; $we = $_POST['w_einheit'] ?? [];
    foreach ($bez as $i => $b) {
        $b = trim($b); if ($b === '') continue;
        q("INSERT INTO rezeptur_anfrage_wunsch (anfrage_id,bezeichnung,wunsch_menge,einheit,sort) VALUES (?,?,?,?,?)",
          [$aid, $b, trim($wm[$i] ?? ''), trim($we[$i] ?? 'mg'), $i]);
    }
    log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Neue Rezepturanfrage im Portal eingereicht.', 'anfrage', 'anfrage', $aid);
    header('Location: ?p=portal&token=' . $token . '&v=anfrage&anfrage=1'); exit;
}

// Rezepturanfrage bearbeiten – nur solange noch nicht in Bearbeitung (status='neu') und Eigentum des Kunden.
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'anfrage_bearbeiten') {
    $aid = (int)($_POST['anfrage_id'] ?? 0);
    $an  = $aid ? one("SELECT * FROM rezeptur_anfrage WHERE id=? AND kunde_id=? AND status='neu'", [$aid, (int)$k['id']]) : null;
    if ($an) {
        $form = in_array($_POST['form'] ?? '', ['kapsel','tablette','softgel','stick','pulver','fluessig'], true) ? $_POST['form'] : 'kapsel';
        q("UPDATE rezeptur_anfrage SET darreichungsform=?, produktname=?, notiz=? WHERE id=?",
          [$form, trim($_POST['produktname'] ?? '') ?: null, trim($_POST['notiz'] ?? ''), $aid]);
        q("DELETE FROM rezeptur_anfrage_wunsch WHERE anfrage_id=?", [$aid]);   // Wunsch-Zeilen ersetzen (eigene Anfrage)
        $bez = $_POST['w_bez'] ?? []; $wm = $_POST['w_menge'] ?? []; $we = $_POST['w_einheit'] ?? [];
        foreach ($bez as $i => $b) {
            $b = trim($b); if ($b === '') continue;
            q("INSERT INTO rezeptur_anfrage_wunsch (anfrage_id,bezeichnung,wunsch_menge,einheit,sort) VALUES (?,?,?,?,?)",
              [$aid, $b, trim($wm[$i] ?? ''), trim($we[$i] ?? 'mg'), $i]);
        }
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Rezepturanfrage ' . $an['nummer'] . ' im Portal bearbeitet.', 'anfrage', 'anfrage', $aid);
    }
    header('Location: ?p=portal&token=' . $token . '&v=anfrage&geaendert=1'); exit;
}

// Rezeptur-Vorschlag annehmen -> eingefroren (verbindlich)
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rezeptur_annehmen') {
    $rid = (int)($_POST['rezeptur_id'] ?? 0);
    $rez = $rid ? one("SELECT * FROM rezeptur WHERE id=? AND kunde_id=? AND status='vorschlag'", [$rid, (int)$k['id']]) : null;
    if ($rez) {
        q("UPDATE rezeptur SET status='eingefroren' WHERE id=?", [$rid]);
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Rezeptur ' . $rez['nummer'] . ' angenommen (verbindlich).', 'rezeptur', 'rezeptur', $rid);
    }
    header('Location: ?p=portal&token=' . $token . '&v=start&angenommen=1'); exit;
}

// Rezeptur-Vorschlag ablehnen (Pflicht-Grund) -> Status abgelehnt, Team überarbeitet
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rezeptur_ablehnen') {
    $rid   = (int)($_POST['rezeptur_id'] ?? 0);
    $grund = trim($_POST['grund'] ?? '');
    $rez   = $rid ? one("SELECT * FROM rezeptur WHERE id=? AND kunde_id=? AND status='vorschlag'", [$rid, (int)$k['id']]) : null;
    if ($rez && $grund !== '') {
        q("UPDATE rezeptur SET status='abgelehnt', ablehnung_grund=? WHERE id=?", [$grund, $rid]);
        // Verknüpfte Anfrage nachziehen, damit das Team in der Anfragen-Liste sofort sieht: Vorschlag abgelehnt → überarbeiten.
        q("UPDATE rezeptur_anfrage SET status='ueberarbeiten' WHERE rezeptur_id=? AND kunde_id=?", [$rid, (int)$k['id']]);
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Rezeptur-Vorschlag ' . $rez['nummer'] . ' im Portal abgelehnt: ' . $grund, 'rezeptur', 'rezeptur', $rid);
        header('Location: ?p=portal&token=' . $token . '&v=rezeptur&rid=' . $rid . '&abgelehnt=1'); exit;
    }
    header('Location: ?p=portal&token=' . $token . '&v=rezeptur&rid=' . $rid . '&ablehngrund=1'); exit;
}

// Produktanfrage (aus dem Katalog): Stück/Verpackung/Menge
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'produkt_anfrage') {
    $pid = (int)($_POST['produkt_id'] ?? 0);
    if ($pid && $k['portal_produkte']) {
        $fg = (float) str_replace(',', '.', $_POST['fuellmenge_g'] ?? '0');
        $stueck = (int)($_POST['stueck'] ?? 0) ?: null;
        $vtyp = trim($_POST['verpackung_typ'] ?? '') ?: null;
        // mehrere Mengen (Staffeln) kommagetrennt möglich, z. B. „1000, 2500, 5000"
        $mengen = array_values(array_filter(array_map('intval', preg_split('/[,;\s]+/', (string)($_POST['menge'] ?? ''))), fn($m) => $m > 0));
        $first = $mengen[0] ?? null;
        q("INSERT INTO portal_anfrage (nummer,kunde_id,typ,produkt_id,stueck,fuellmenge_g,verpackung_typ,menge,notiz,status) VALUES (?,?,?,?,?,?,?,?,?,'neu')",
          [naechste_nummer('PAF'), (int)$k['id'], 'produkt', $pid, $stueck, $fg > 0 ? $fg : null, $vtyp, $first, trim($_POST['notiz'] ?? '')]);
        $paf = insert_id();
        $sort = 0;
        foreach (($mengen ?: [0]) as $m) {
            q("INSERT INTO portal_anfrage_pos (anfrage_id,produkt_id,stueck,fuellmenge_g,verpackung_typ,menge,sort) VALUES (?,?,?,?,?,?,?)",
              [$paf, $pid, $stueck, $fg > 0 ? $fg : null, $vtyp, $m ?: null, $sort++]);
        }
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', 'Produktanfrage im Portal gestellt' . (count($mengen) > 1 ? ' (' . count($mengen) . ' Staffeln)' : '') . '.', 'anfrage');
    }
    header('Location: ?p=portal&token=' . $token . '&v=produkte&gesendet=1'); exit;
}
// Rohstoff- / Dienstleistungsanfrage (Freitext)
if ($k && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['aktion'] ?? '', ['rohstoff_anfrage','dienstleistung_anfrage'], true)) {
    $typ = $_POST['aktion'] === 'rohstoff_anfrage' ? 'rohstoff' : 'dienstleistung';
    $erlaubt = $typ === 'rohstoff' ? $k['portal_rohstoffe'] : $k['portal_dienstleistung'];
    if ($erlaubt && trim($_POST['betreff'] ?? '') !== '') {
        $wm = (float) str_replace(',', '.', $_POST['wunsch_menge'] ?? '0');
        $we = in_array($_POST['wunsch_einheit'] ?? '', ['kg','g','t','Stück','L'], true) ? $_POST['wunsch_einheit'] : null;
        // Bei Anfrage aus dem Rohstoff-Katalog: konkreter Rohstoff (item) für die spätere Preisberechnung
        $rid = ($typ === 'rohstoff' && ($_POST['rohstoff_id'] ?? '') !== '') ? (int)$_POST['rohstoff_id'] : null;
        if ($rid && !one("SELECT id FROM item WHERE id=? AND kategorie='rohstoff'", [$rid])) $rid = null;
        q("INSERT INTO portal_anfrage (nummer,kunde_id,typ,betreff,notiz,wunsch_menge,wunsch_einheit,rohstoff_id,status) VALUES (?,?,?,?,?,?,?,?,'neu')",
          [naechste_nummer('PAF'), (int)$k['id'], $typ, trim($_POST['betreff'] ?? ''), trim($_POST['notiz'] ?? ''), $wm > 0 ? $wm : null, $wm > 0 ? $we : null, $rid]);
        log_aktivitaet('kunde', (int)$k['id'], 'kunde', ($typ === 'rohstoff' ? 'Rohstoffanfrage' : 'Dienstleistungsanfrage') . ' im Portal gestellt.', 'anfrage');
    }
    header('Location: ?p=portal&token=' . $token . '&v=' . ($typ === 'rohstoff' ? 'rohstoffe' : 'dienstleistung') . '&gesendet=1'); exit;
}

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';

// Kopf-/Fußzeile des Portals (eigene, ohne internes Menü)
function portal_head(string $titel): void {
    echo "<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\">";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<title>" . h($titel) . "</title><link rel=\"stylesheet\" href=\"assets/app.css\">";
    echo "<style>"
       . ".pt-badge{display:inline-block;background:var(--lime);color:#10210f;border-radius:10px;padding:0 7px;font-size:12px;font-weight:600}"
       . ".pt-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:16px 0;max-width:760px}"
       . ".pt-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:14px 16px}"
       . ".pt-card .k{font-size:12px;color:var(--muted)}.pt-card .val{font-size:22px;font-weight:600;margin-top:4px}</style>";
    echo "<script>(function(){try{var t=localStorage.getItem('bx-theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>";
    echo "</head><body>";
}
function portal_foot(): void { echo (function_exists('bx_theme_script') ? bx_theme_script() : '') . (function_exists('bx_side_scroll_script') ? bx_side_scroll_script() : '') . "</body></html>"; }

if (!$k) {
    portal_head('Kundenportal');
    echo '<div class="bx-shell"><aside class="bx-side"><div class="bx-brand">bulkify <span class="bx-ver">Portal</span></div></aside>'
       . '<main class="bx-main"><div class="bx-panel"><h2 style="margin-top:0">Zugang ungültig</h2><p class="muted">Dieser Portal-Link ist nicht gültig. Bitte wenden Sie sich an bulkify.</p></div></main></div>';
    portal_foot(); exit;
}

$kid = (int)$k['id'];
// Nährwerte je Einheit aus einer Rezeptur (aggregiert über die Wirkstoffe der Rohstoffe)
if (!function_exists('pt_naehr')) {
    function pt_naehr(int $rid): array {
        $n = [];
        foreach (all("SELECT z.menge_mg, iw.gehalt_prozent, na.name, na.nrv_wert, na.einheit
                      FROM rezeptur_zutat z JOIN item_wirkstoff iw ON iw.item_id=z.item_id
                      JOIN naehrstoff na ON na.id=iw.naehrstoff_id
                      WHERE z.rezeptur_id=? AND iw.gehalt_prozent IS NOT NULL", [$rid]) as $w) {
            $mgN = (float)$w['menge_mg'] * (float)$w['gehalt_prozent'] / 100;
            if (!isset($n[$w['name']])) $n[$w['name']] = ['name'=>$w['name'], 'mg'=>0.0, 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit']];
            $n[$w['name']]['mg'] += $mgN;
        }
        return array_values($n);
    }
}
// Angebote (offen zum Bestätigen + bereits bestätigte) – mit Produkt- & Verpackungsinfos wie in v3
$angebote = all("SELECT a.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt_name, p.rezeptur_id, p.einheiten_pro_packung,
                        p.verpackung_id, p.verschluss_id, p.etikett_id, r.darreichungsform
                 FROM angebot a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                 WHERE a.kunde_id=? AND a.kunde_ausgeblendet=0 ORDER BY a.angelegt DESC", [$kid]);
$staffelMap = [];
$angInfo = [];
$produktionszeit = (float) meta_get('produktionszeit_wochen', 7);   // globaler Standard
$itemName = fn($id) => $id ? (string) scalar("SELECT name FROM item WHERE id=?", [(int)$id]) : '';
foreach ($angebote as $a) {
    $staffelMap[$a['id']] = all("SELECT * FROM angebot_staffel WHERE angebot_id=? ORDER BY sort, id", [$a['id']]);
    $rid = (int)($a['rezeptur_id'] ?? 0);
    // je Angebot gesetzte Marge (überschreibt die Marge-je-Typ; VK wird dann aus EK gerechnet)
    $mo = ($a['marge_override'] ?? '') !== '' && $a['marge_override'] !== null ? (float)$a['marge_override'] : null;
    // Preismatrix des Produkts: [stueck][bestellmenge] = günstigste Zelle (vk + verpackung)
    $matrix = [];
    foreach (all("SELECT stueck, bestellmenge, verpackung_id, ek_preis, vk_preis FROM produkt_preis WHERE produkt_id=? ORDER BY vk_preis ASC", [(int)($a['produkt_id'] ?? 0)]) as $mr) {
        $s = (int)$mr['stueck']; $bm = (int)$mr['bestellmenge'];
        $vk = $mo !== null ? (float)$mr['ek_preis'] * (1 + $mo/100) : (float)$mr['vk_preis'];
        if (!isset($matrix[$s][$bm])) $matrix[$s][$bm] = ['vk'=>$vk, 'verp'=>(int)$mr['verpackung_id']];
    }
    $angInfo[$a['id']] = [
        'verp'    => $itemName($a['verpackung_id']),
        'deckel'  => $itemName($a['verschluss_id']),
        'etikett' => $itemName($a['etikett_id']),
        'form'    => $a['darreichungsform'] ?? '',
        'istPulver' => in_array($a['darreichungsform'] ?? '', ['pulver','stick','granulat'], true),   // Rezeptur beschreibt eine Portion (g je Packung anzeigen)
        'istFuell'  => form_ist_fuellmenge($a['darreichungsform'] ?? ''),                              // Packungsgröße ist eine Füllmenge (g/ml) statt einer Stückzahl
        'portionG' => $rid ? (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=?", [$rid]) / 1000 : 0,
        'zutaten' => $rid ? all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rid]) : [],
        'nutr'    => $rid ? pt_naehr($rid) : [],
        'matrix'  => $matrix,
        'prodzeit'=> ($a['produktionszeit_wochen'] ?? '') !== '' && $a['produktionszeit_wochen'] !== null ? (float)$a['produktionszeit_wochen'] : $produktionszeit,
    ];
}
$std_stueck_ang = std_stueckzahlen();
$std_menge_ang  = std_bestellmengen();
// USt-Satz dieses Kunden (EU-Ausland/Kleinunternehmer 0 %, sonst Inland)
$land = $k['land'] ?? 'DE';
$ustP = (meta_get('kleinunternehmer','0') === '1' || $land !== 'DE') ? 0.0 : (float) meta_get('ust_inland', 19);
$auftraege = all("SELECT a.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt_name FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id
                  WHERE a.kunde_id=? ORDER BY a.angelegt DESC", [$kid]);
$rechnungen = all("SELECT * FROM beleg WHERE kunde_id=? AND typ='rechnung' ORDER BY angelegt DESC", [$kid]);
$anfragen = all("SELECT a.*, r.name AS rezeptur_name, r.status AS rezeptur_status,
                 (SELECT COUNT(*) FROM rezeptur_anfrage_wunsch w WHERE w.anfrage_id=a.id) AS wunsch_anzahl
                 FROM rezeptur_anfrage a LEFT JOIN rezeptur r ON r.id=a.rezeptur_id WHERE a.kunde_id=? ORDER BY a.angelegt DESC", [$kid]);
// „Zu prüfen": ein Vorschlag liegt zur Freigabe bereit (verknüpfte Rezeptur im Status „vorschlag").
$anfPruef  = array_values(array_filter($anfragen, fn($a) => ($a['rezeptur_status'] ?? '') === 'vorschlag'));
$anfRest   = array_values(array_filter($anfragen, fn($a) => ($a['rezeptur_status'] ?? '') !== 'vorschlag'));
$DFORM_P = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$anfBadge = fn($s) => match ($s) { 'neu'=>bx_badge('eingereicht','info'),'in_bearbeitung'=>bx_badge('in Prüfung','warn'),'beantwortet'=>bx_badge('Vorschlag erhalten','ok'),'ueberarbeiten'=>bx_badge('wird überarbeitet','warn'),'abgelehnt'=>bx_badge('abgelehnt','err'),default=>bx_badge($s) };
$vorschlaege = all("SELECT * FROM rezeptur WHERE kunde_id=? AND status='vorschlag' ORDER BY aktualisiert DESC", [$kid]);
// Kundenseitiger Status – bewusst nur vier Zustände (richtet sich nach dem ECHTEN Rezeptur-Stand):
//  in Prüfung  = liegt bei uns (nichts gesendet) · Vorschlag erhalten = wir haben einen Vorschlag gesendet
//  abgelehnt   = Vorschlag abgelehnt (vom Kunden oder von uns) · Rezeptur angelegt = final bestätigt (eingefroren)
$anfStatus = function($an) {
    $rs = $an['rezeptur_status'] ?? '';
    $as = $an['status'] ?? '';
    if ($rs === 'eingefroren') return bx_badge('Rezeptur angelegt','ok');
    if ($rs === 'vorschlag')   return bx_badge('Vorschlag erhalten','ok');
    if ($rs === 'abgelehnt' || $as === 'ueberarbeiten' || $as === 'abgelehnt') return bx_badge('abgelehnt','err');
    return bx_badge('in Prüfung','warn');   // neu / in Bearbeitung / Entwurf
};
$vorschlagZutaten = [];
foreach ($vorschlaege as $vs) $vorschlagZutaten[$vs['id']] = all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort,id", [$vs['id']]);

$aufBadge = fn($s) => match ($s) { 'offen'=>bx_badge('in Bearbeitung','info'),'in_produktion'=>bx_badge('in Produktion','warn'),'erledigt'=>bx_badge('versandbereit','info'),'versendet'=>bx_badge('versendet','ok'),default=>bx_badge($s) };
$reBadge  = fn($s) => match ($s) { 'bezahlt'=>bx_badge('bezahlt','ok'),'teilbezahlt'=>bx_badge('teilbezahlt','info'),'offen'=>bx_badge('offen','warn'),'storniert'=>bx_badge('storniert','err'),default=>bx_badge($s) };
$AUFSTEPS = ['Bestätigt', 'In Produktion', 'Versandbereit', 'Versendet'];
$aufStep  = fn($s) => ['offen'=>0, 'in_produktion'=>1, 'erledigt'=>2, 'versendet'=>3][$s] ?? 0;

// Menüpunkte (nur freigeschaltete) + Gruppierung
$L = ['start' => 'Übersicht'];
if ($k['portal_rezeptur'])     { $L['rezepturen'] = 'Rezepturen';  $L['meine_anfragen'] = 'Meine Anfragen'; $L['anfrage'] = 'Rezeptur anfragen'; }
if ($k['portal_produkte'])     { $L['produkte']   = 'Produkte';    $L['prodanfrage'] = 'Produkt anfragen'; }
if ($k['portal_rohstoffe'])    { $L['rohstoffe']  = 'Rohstoffe';   $L['rohanfrage'] = 'Rohstoff anfragen'; }
if ($k['portal_dienstleistung']) $L['dienstleistung'] = 'Dienstleistung anfragen';
$L += ['angebote' => 'Angebote', 'bestellungen' => 'Bestellungen', 'rechnungen' => 'Rechnungen'];
$NAVGROUPS = [
    ''          => ['start'],
    'Katalog'   => ['rezepturen', 'produkte', 'rohstoffe'],
    'Anfragen'  => ['meine_anfragen', 'anfrage', 'prodanfrage', 'rohanfrage', 'dienstleistung'],
    'Vorgänge'  => ['angebote', 'bestellungen', 'rechnungen'],
];
// Detailansichten (kein Menüpunkt) – gültig je nach Freischaltung; hebt den Katalog-Punkt hervor
$detailParent = [];
if ($k['portal_rezeptur']) $detailParent['rezeptur'] = 'rezepturen';
if ($k['portal_produkte']) $detailParent['produkt']  = 'produkte';
if ($k['portal_rohstoffe']) $detailParent['rohstoff'] = 'rohstoffe';
$detailParent['bestellung'] = 'bestellungen';   // Bestell-Detail (eigene Bestellung)
$view = $_GET['v'] ?? 'start';
if (!isset($L[$view]) && !isset($detailParent[$view])) $view = 'start';
$activeItem = $detailParent[$view] ?? $view;

// Katalog-Produkte (nicht exklusiv oder exklusiv für diesen Kunden), aktiv
$katalog = $k['portal_produkte'] ? all("SELECT p.id, COALESCE(NULLIF(p.kundenname,''), p.name) AS name, p.nummer, p.rezeptur_id, r.darreichungsform
    FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
    WHERE p.status='aktiv' AND (p.exklusiv=0 OR p.kunde_id=?) ORDER BY name", [$kid]) : [];
$primVerp  = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0 ORDER BY name");
$stdStueck = std_stueckzahlen();
// Kundenseitige Verpackungstypen – wir wählen intern den perfekt passenden Behälter
$VTYPEN = ['glas'=>'Glas', 'pet'=>'PET-Dose', 'pla'=>'PLA-Becher', 'beutel'=>'Standbodenbeutel', 'stick'=>'Stick', 'blister'=>'Blister'];
// „ab"-Preise je Produkt (günstigste VK aus der Preismatrix, mit Kundenrabatt).
// Preise sind KUNDENSPEZIFISCH: sichtbar nur, wenn diesem Kunden das Produkt schon angeboten wurde.
// Alle anderen sehen „auf Anfrage" – sonst wandert die Kalkulation eines Kunden zum nächsten.
$preisFrei = kunde_produkt_preise($kid);
$abMap = [];
foreach (all("SELECT produkt_id, MIN(vk_preis) AS mn FROM produkt_preis GROUP BY produkt_id") as $r) $abMap[(int)$r['produkt_id']] = (float)$r['mn'];
$abPreis = fn($pid) => (isset($abMap[(int)$pid]) && isset($preisFrei[(int)$pid])) ? vk_fuer_kunde($abMap[(int)$pid], $kid) : null;
// Nährwerte je Einheit aus einer Rezeptur (aggregiert über die Wirkstoffe der Rohstoffe)
if (!function_exists('pt_naehr')) {
    function pt_naehr(int $rid): array {
        $n = [];
        foreach (all("SELECT z.menge_mg, iw.gehalt_prozent, na.name, na.nrv_wert, na.einheit
                      FROM rezeptur_zutat z JOIN item_wirkstoff iw ON iw.item_id=z.item_id
                      JOIN naehrstoff na ON na.id=iw.naehrstoff_id
                      WHERE z.rezeptur_id=? AND iw.gehalt_prozent IS NOT NULL", [$rid]) as $w) {
            $mgN = (float)$w['menge_mg'] * (float)$w['gehalt_prozent'] / 100;
            if (!isset($n[$w['name']])) $n[$w['name']] = ['name'=>$w['name'], 'mg'=>0.0, 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit']];
            $n[$w['name']]['mg'] += $mgN;
        }
        return array_values($n);
    }
}
// Katalog je Produkt anreichern: Rezeptur (Zutaten + Nährwerte) + Preistabelle
foreach ($katalog as &$pk) {
    $rid = (int)$pk['rezeptur_id'];
    $pk['zutaten']  = $rid ? all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rid]) : [];
    $pk['nutr']     = $rid ? pt_naehr($rid) : [];
    $pk['istPulver'] = in_array($pk['darreichungsform'] ?? '', ['pulver','stick','granulat'], true);
    $pk['portionG'] = $rid ? (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=?", [$rid]) / 1000 : 0;
    $pr = [];
    foreach (all("SELECT stueck, MIN(vk_preis) AS mn FROM produkt_preis WHERE produkt_id=? GROUP BY stueck ORDER BY stueck", [(int)$pk['id']]) as $r)
        $pr[] = ['stueck'=>(int)$r['stueck'], 'ab'=>vk_fuer_kunde((float)$r['mn'], $kid)];
    $pk['preise'] = $pr;
}
unset($pk);
seed_kapselgroesse_if_empty();
$portalKapseln = all("SELECT id, name, fuellmenge_mg FROM kapselgroesse ORDER BY fuellmenge_mg ASC");
// Kleinste Kapselgröße, in die das Gesamt-Füllgewicht (mg) passt; null = größer als jede Standardkapsel.
$kapselFuer = function(float $totalMg) use ($portalKapseln): ?array {
    if ($totalMg <= 0) return null;
    foreach ($portalKapseln as $kg) if ((float)$kg['fuellmenge_mg'] >= $totalMg) return $kg;
    return null;
};
// Anzeige-Kapselgröße: am Rezept gespeicherte Größe hat Vorrang, sonst Richtwert nach Füllgewicht.
$kapselAnzeige = function(array $rezRow, float $totalMg) use ($portalKapseln, $kapselFuer): ?array {
    $gid = (int)($rezRow['kapselgroesse_id'] ?? 0);
    if ($gid > 0) foreach ($portalKapseln as $kg) if ((int)$kg['id'] === $gid) return $kg;
    return $kapselFuer($totalMg);
};
$portalAnfragen = all("SELECT pa.*, p.name AS produkt_name, i.name AS verp_name,
    (SELECT a.id FROM angebot a WHERE a.anfrage_id=pa.id AND a.kunde_id=pa.kunde_id AND a.kunde_ausgeblendet=0 ORDER BY a.id DESC LIMIT 1) AS angebot_id
    FROM portal_anfrage pa
    LEFT JOIN produkt p ON p.id=pa.produkt_id LEFT JOIN item i ON i.id=pa.verpackung_id
    WHERE pa.kunde_id=? ORDER BY pa.angelegt DESC", [$kid]);
$pafBadge = fn($s) => match ($s) { 'neu'=>bx_badge('eingegangen','info'),'in_bearbeitung'=>bx_badge('in Bearbeitung','warn'),'beantwortet'=>bx_badge('Angebot abgegeben','ok'),'abgelehnt'=>bx_badge('abgelehnt','err'),default=>bx_badge($s) };
// Rezeptur-Katalog: eigene Rezepturen (ab Vorschlag) + freigegebene Hausrezepturen (allen Kunden verfügbar)
// „Meine Rezepturen" = nur ANGENOMMENE eigene (eingefroren) + freigegebene Katalog-Rezepturen.
// Vorschläge sind noch keine Rezeptur → erscheinen über die Übersicht („Vorschlag erhalten"), nicht hier.
$meineRezepturen = $k['portal_rezeptur'] ? all("SELECT * FROM rezeptur
    WHERE (kunde_id=? AND status='eingefroren')
       OR (kunde_id IS NULL AND status='freigegeben')
    ORDER BY (kunde_id IS NULL), name", [$kid]) : [];
$rezBadge = fn($s) => match ($s) { 'vorschlag'=>bx_badge('Vorschlag','info'),'eingefroren'=>bx_badge('angenommen','ok'),'freigegeben'=>bx_badge('freigegeben','ok'),'abgelehnt'=>bx_badge('abgelehnt','err'),default=>bx_badge($s) };
$rid = (int)($_GET['rid'] ?? 0);
$rezDetail = ($rid && $k['portal_rezeptur']) ? one("SELECT * FROM rezeptur WHERE id=?
    AND ((kunde_id=? AND status IN ('vorschlag','eingefroren','freigegeben','abgelehnt')) OR (kunde_id IS NULL AND status='freigegeben'))", [$rid, $kid]) : null;
$rezZutaten = $rezDetail ? all("SELECT bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rid]) : [];

// Rohstoff-Katalog (Preis auf Anfrage) – ohne Leerkapseln
$rohkatalog = $k['portal_rohstoffe'] ? all("SELECT id, name, form, cas FROM item
    WHERE kategorie='rohstoff' AND gesperrt=0 AND (form<>'kapselhuelle' OR form IS NULL) ORDER BY name") : [];
// Produkt-Detail (aus dem Katalog)
$pid = (int)($_GET['pid'] ?? 0);
$prodDetail = ($pid && $k['portal_produkte']) ? one("SELECT p.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS anzeige_name, r.darreichungsform, r.name AS rez_name
    FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
    WHERE p.id=? AND p.status='aktiv' AND (p.exklusiv=0 OR p.kunde_id=?)", [$pid, $kid]) : null;
$prodZutaten = ($prodDetail && $prodDetail['rezeptur_id']) ? all("SELECT z.item_id, z.bezeichnung, z.menge_mg, i.allergene, i.vegan, i.gvo_frei
    FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [(int)$prodDetail['rezeptur_id']]) : [];
// Aus den verknüpften Rohstoffen: Wirkstoffe je Zutat + Nährwert-Aggregation je Einheit + Deklaration
$prodWirk = []; $prodNaehr = []; $prodAllergene = []; $veganFlags = []; $gvoFlags = [];
foreach ($prodZutaten as $z) {
    if (!$z['item_id']) continue;
    $al = trim((string)$z['allergene']);
    if ($al !== '' && mb_stripos($al, 'keine') === false) $prodAllergene[] = $al;
    $veganFlags[] = $z['vegan']; $gvoFlags[] = $z['gvo_frei'];
    foreach (all("SELECT n.name, n.nrv_wert, n.einheit, iw.gehalt_prozent
                  FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id WHERE iw.item_id=?", [(int)$z['item_id']]) as $w) {
        $prodWirk[$z['item_id']][] = ($w['gehalt_prozent'] !== null ? rtrim(rtrim(number_format((float)$w['gehalt_prozent'],2,',','.'),'0'),',') . ' % ' : '') . $w['name'];
        if ($w['gehalt_prozent'] === null) continue;
        $mgN = (float)$z['menge_mg'] * (float)$w['gehalt_prozent'] / 100;
        if (!isset($prodNaehr[$w['name']])) $prodNaehr[$w['name']] = ['name'=>$w['name'], 'mg'=>0.0, 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit']];
        $prodNaehr[$w['name']]['mg'] += $mgN;
    }
}
// Deklaration nur behaupten, wenn ALLE Rohstoffe bekannt & konform sind (sonst „–")
$aggFlag = function ($flags) { $known = array_filter($flags, fn($x) => $x !== null && $x !== ''); if (!$known || count($known) < count($flags)) return null; foreach ($known as $f) if ((int)$f === 0) return false; return true; };
$prodVegan = $prodDetail ? $aggFlag($veganFlags) : null;
$prodGvo   = $prodDetail ? $aggFlag($gvoFlags) : null;
$prodAllergene = array_values(array_unique($prodAllergene));
// Größen & Preise des Produkts (je Stückzahl der günstigste VK, mit Kundenrabatt).
// Nur für Produkte, die diesem Kunden angeboten wurden – sonst bleibt die Tabelle leer und es steht „auf Anfrage".
$prodPreise = [];
if ($prodDetail && isset($preisFrei[(int)$prodDetail['id']]))
    foreach (all("SELECT stueck, MIN(vk_preis) AS mn FROM produkt_preis WHERE produkt_id=? GROUP BY stueck ORDER BY stueck", [(int)$prodDetail['id']]) as $r)
        $prodPreise[] = ['stueck'=>(int)$r['stueck'], 'ab'=>vk_fuer_kunde((float)$r['mn'], $kid)];
$prodForm = $prodDetail['darreichungsform'] ?? '';
$prodIstFuell  = form_ist_fuellmenge($prodForm);   // Anfrage nach Füllmenge (Pulver g / Flüssig ml) statt Stückzahl
$prodFuellEinheit = form_groessen_einheit($prodForm) ?: 'g';
$prodPortionG = ($prodDetail && $prodDetail['rezeptur_id']) ? (float) scalar("SELECT COALESCE(SUM(menge_mg),0) FROM rezeptur_zutat WHERE rezeptur_id=?", [(int)$prodDetail['rezeptur_id']]) / 1000 : 0;
// Rohstoff-Detail (kundenfreundlich: Kennwerte + Deklaration, keine internen Daten)
$iid = (int)($_GET['iid'] ?? 0);
$rohDetail = ($iid && $k['portal_rohstoffe']) ? one("SELECT id, name, name_lat, form, cas, herkunft, synonym, bot_quelle, herkunftsland,
    haltbarkeit, lagerbedingungen, zusaetze, allergene, vegan, gvo_frei, bestrahlt, tse_bse_frei, zertifikate
    FROM item WHERE id=? AND kategorie='rohstoff' AND gesperrt=0", [$iid]) : null;
$rohKennwerte = $rohDetail ? all("SELECT parameter, wert FROM item_kennwert WHERE item_id=? ORDER BY sort, id", [$iid]) : [];
$jaNein = fn($v) => $v === null || $v === '' ? null : ((int)$v === 1);
$FORMLBL_P = ['pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','oel'=>'Öl','paste'=>'Paste','kristallin'=>'Kristallin','kapselhuelle'=>'Kapselhülle'];
$offenAngebote = count(array_filter($angebote, fn($a) => in_array($a['status'], ['offen','gesendet'], true)));
$offenRechnungen = array_values(array_filter($rechnungen, fn($r) => ($r['status'] ?? '') === 'offen'));
$offenBetrag = array_sum(array_map(fn($r) => (float)$r['brutto'], $offenRechnungen));
$inArbeit = count(array_filter($auftraege, fn($a) => $a['status'] !== 'versendet'));
$portalLink = fn($v) => '?p=portal&token=' . $token . '&v=' . $v;
// Einheitliche Liste „Meine Anfragen" über ALLE Typen (Reiter nach Typ + Alle). Nach $portalLink, da dieser genutzt wird.
$typLabelP = ['rezeptur'=>'Rezeptur','produkt'=>'Produkt','rohstoff'=>'Rohstoff','dienstleistung'=>'Dienstleistung'];
$meineAnfRows = [];
foreach ($anfragen as $a) {
    $akt = null;
    if (($a['rezeptur_status'] ?? '') === 'vorschlag' && $a['rezeptur_id']) $akt = ['label'=>'Prüfen & entscheiden','href'=>$portalLink('rezeptur').'&rid='.(int)$a['rezeptur_id'],'primary'=>true];
    elseif (($a['status'] ?? '') === 'neu') $akt = ['label'=>'Bearbeiten','href'=>$portalLink('anfrage').'&edit='.(int)$a['id'],'primary'=>false];
    $meineAnfRows[] = ['typ'=>'rezeptur','nummer'=>$a['nummer'],'bez'=>($a['produktname'] ?: '(Rezeptur)'),'datum'=>$a['angelegt'],'status'=>$anfStatus($a),'aktion'=>$akt];
}
foreach ($portalAnfragen as $p) {
    $bez = $p['typ']==='produkt' ? ($p['produkt_name'] ?: 'Produkt') : ($p['betreff'] ?: ($typLabelP[$p['typ']] ?? 'Anfrage'));
    $st  = ($p['status']==='beantwortet') ? bx_badge('Angebot erhalten','ok') : (($p['status']==='abgelehnt') ? bx_badge('abgelehnt','err') : bx_badge('in Prüfung','warn'));
    $akt = !empty($p['angebot_id']) ? ['label'=>'Zum Angebot','href'=>$portalLink('angebote').'#a'.(int)$p['angebot_id'],'primary'=>true] : null;
    $meineAnfRows[] = ['typ'=>$p['typ'],'nummer'=>$p['nummer'],'bez'=>$bez,'datum'=>$p['angelegt'],'status'=>$st,'aktion'=>$akt];
}
usort($meineAnfRows, fn($x,$y) => strcmp((string)$y['datum'], (string)$x['datum']));
$anfTabs = ['alle'=>'Alle'];
if ($k['portal_rezeptur'])       $anfTabs['rezeptur']='Rezepturen';
if ($k['portal_produkte'])       $anfTabs['produkt']='Produkte';
if ($k['portal_rohstoffe'])      $anfTabs['rohstoff']='Rohstoffe';
if ($k['portal_dienstleistung']) $anfTabs['dienstleistung']='Dienstleistung';
$atab = $_GET['atab'] ?? 'alle'; if (!isset($anfTabs[$atab])) $atab = 'alle';

// --- Angebot als PDF (bulkify-Belegvorlage, positionsbasiert) ---
if (($_GET['v'] ?? '') === 'angebot_pdf') {
    $aid = (int)($_GET['aid'] ?? 0);
    $a = null; foreach ($angebote as $x) if ((int)$x['id'] === $aid) { $a = $x; break; }
    if (!$a) { http_response_code(404); echo 'Angebot nicht gefunden.'; exit; }
    require_once BX_ROOT . '/core/pdf_beleg.php';
    $inf = $angInfo[$a['id']];
    $istFuell = $inf['istFuell'];

    // Angefragte Konfiguration (aus der Anfrage) bestimmen
    $anf = $a['anfrage_id'] ? one("SELECT nummer, stueck, fuellmenge_g, verpackung_typ, menge FROM portal_anfrage WHERE id=?", [(int)$a['anfrage_id']]) : null;
    $featStk = $istFuell ? (float)($anf['fuellmenge_g'] ?? 0) : (int)($anf['stueck'] ?? 0);
    if (!$featStk || !isset($inf['matrix'][$featStk])) { foreach (std_groessen_fuer($inf['form']) as $s2) { if (isset($inf['matrix'][$s2])) { $featStk = $s2; break; } } }
    $featMenge = (int)($anf['menge'] ?? 0);
    if (!$featMenge || !isset($inf['matrix'][$featStk][$featMenge])) {
        $featMenge = 0; foreach ($std_menge_ang as $bm2) { if (isset($inf['matrix'][$featStk][$bm2])) { $featMenge = $bm2; break; } }
    }
    $preisPkg = ($featStk && $featMenge && isset($inf['matrix'][$featStk][$featMenge])) ? vk_fuer_kunde($inf['matrix'][$featStk][$featMenge]['vk'], $kid) : 0.0;
    $stkLabel = form_groessen_label($inf['form'], (float)$featStk);
    $verpText = $inf['verp'] ?: ($anf && $anf['verpackung_typ'] ? ['glas'=>'Glas','pet'=>'PET-Dose','pla'=>'PLA-Becher','beutel'=>'Standbodenbeutel','stick'=>'Stick','blister'=>'Blister'][$anf['verpackung_typ']] ?? $anf['verpackung_typ'] : '');

    // USt: Inland -> Satz aus Einstellungen, sonst 0 % (EU-/Export-Lieferung)
    $land = strtoupper(trim((string)($k['land'] ?? '')));
    $istInland = ($land === '' || in_array($land, ['DE','D','DEUTSCHLAND','GERMANY'], true));
    $ustSatz = $istInland ? (float) meta_get('ust_inland', 19) : 0.0;

    // Positionen (Hybrid): gespeicherte Overrides haben Vorrang, sonst automatisch (Herstellung + Verpackung extra)
    $positionen = angebot_positionen($aid);

    // Staffel „Preis je fertiges Produkt" nur bei automatischer (nicht manuell zusammengestellter) Kalkulation
    $produktStaffel = angebot_hat_positionen($aid) ? [] : angebot_staffel_gruppen($a);

    // Begleittext
    $teamNote = '';
    if (preg_match('/—\s*(.+)$/u', (string)$a['notiz'], $mm)) $teamNote = trim($mm[1]);
    $pz = rtrim(rtrim(number_format((float)$inf['prodzeit'],1,',','.'),'0'),',');
    $kopf = 'Vielen Dank für Ihre Anfrage. Gerne bieten wir Ihnen an:'
          . ($teamNote !== '' ? "\n" . $teamNote : '')
          . "\nProduktionszeit: ca. " . $pz . ' Wochen (unverbindlich).';

    $adr = trim(($k['strasse'] ?? '') . ' ' . ($k['hausnummer'] ?? '')) . "\n" . trim(($k['plz'] ?? '') . ' ' . ($k['ort'] ?? ''));
    if (!$istInland && !empty($k['land'])) $adr .= "\n" . $k['land'];
    $zaMap = ['vorkasse'=>'Vorkasse','rechnung'=>'Rechnung','lastschrift'=>'Lastschrift','paypal'=>'PayPal'];

    $pdf = build_beleg_pdf([
        'belegart_label'   => 'Angebot',
        'nummer'           => $a['nummer'],
        'empfaenger'       => $k['firma'],
        'adresse'          => $adr,
        'datum'            => $a['angelegt'],
        'gueltig_bis'      => $a['gueltig_bis'] ?: date('Y-m-d', strtotime($a['angelegt'] . ' +' . ((int)meta_get('angebot_gueltig_tage',14)) . ' days')),
        'kundennummer'     => $k['kundennummer'] ?? '',
        'version'          => 1,
        'bezug'            => $anf ? ('Anfrage ' . $anf['nummer']) : '',
        'bearbeiter'       => '',
        'bearbeiter_email' => '',
        'ust_id'           => $k['ust_id'] ?? '',
        'kopf_text'        => $kopf,
        'zahlungsart_label'=> $zaMap[$k['zahlungsart'] ?? 'vorkasse'] ?? ucfirst((string)($k['zahlungsart'] ?? 'Vorkasse')),
        'hinweis'          => '',
    ], $positionen, $produktStaffel);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Angebot_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$a['nummer']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf; exit;
}

// --- Verpackungs-Konformität (PPWR) je Bestellung als PDF ---
if (($_GET['v'] ?? '') === 'ppwr_pdf') {
    $aid = (int)($_GET['aid'] ?? 0);
    $auf = null; foreach ($auftraege as $x) if ((int)$x['id'] === $aid) { $auf = $x; break; }
    if (!$auf) { http_response_code(404); echo 'Bestellung nicht gefunden.'; exit; }
    require_once BX_ROOT . '/core/pdf_ppwr.php';
    $komp = [];
    foreach (produkt_verpackung_items((int)$auf['produkt_id']) as $vp) {
        $it = one("SELECT name, material, gewicht_g, volumen_ml, breite_mm, hoehe_mm, durchmesser_mm, tiefe_mm FROM item WHERE id=?", [$vp['id']]);
        if (!$it) continue;
        $komp[] = [
            'rolle'=>$vp['rolle'], 'name'=>$it['name'], 'material'=>$it['material'],
            'gewicht_g'=>$it['gewicht_g'], 'volumen_ml'=>$it['volumen_ml'], 'masse'=>ppwr_masse($it),
        ];
    }
    $adr = trim(($k['strasse'] ?? '') . ' ' . ($k['hausnummer'] ?? '')) . "\n" . trim(($k['plz'] ?? '') . ' ' . ($k['ort'] ?? ''));
    $pdf = build_ppwr_pdf([
        'nummer'=>$auf['nummer'], 'datum'=>$auf['angelegt'], 'empfaenger'=>$k['firma'], 'adresse'=>$adr,
        'produkt'=>$auf['produkt_name'] ?: '–', 'kundennummer'=>$k['kundennummer'] ?? '', 'ust_id'=>$k['ust_id'] ?? '',
    ], $komp);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Verpackungs-Konformitaet_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$auf['nummer']) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf; exit;
}

// --- Rechnung (RE) / Auftragsbestätigung (AB) je Bestellung als PDF ---
if (in_array(($_GET['v'] ?? ''), ['rechnung_pdf', 'ab_pdf'], true)) {
    $art = ($_GET['v'] === 'rechnung_pdf') ? 're' : 'ab';
    $aid = (int)($_GET['aid'] ?? 0);
    $auf = null; foreach ($auftraege as $x) if ((int)$x['id'] === $aid) { $auf = $x; break; }
    if (!$auf) { http_response_code(404); echo 'Bestellung nicht gefunden.'; exit; }
    $re = one("SELECT * FROM beleg WHERE auftrag_id=? AND typ='rechnung' ORDER BY id DESC LIMIT 1", [(int)$auf['id']]);
    if ($art === 're' && !$re) { http_response_code(404); echo 'Für diese Bestellung liegt noch keine Rechnung vor.'; exit; }
    require_once BX_ROOT . '/core/pdf_beleg.php';

    // USt: aus Rechnung, sonst Inland/Export
    $land = strtoupper(trim((string)($k['land'] ?? '')));
    $istInland = ($land === '' || in_array($land, ['DE','D','DEUTSCHLAND','GERMANY'], true));
    $ustSatz = $re ? (float)$re['ust_prozent'] : ($istInland ? (float) meta_get('ust_inland', 19) : 0.0);

    // Eine Sammelposition aus der bestellten Konfiguration (Netto exakt aus Beleg bzw. Auftrag)
    $nettoGesamt = $re ? (float)$re['netto'] : (float)$auf['gesamt_netto'];
    $menge = max(1, (int)$auf['menge']);
    $preisCent = (int) round($nettoGesamt * 100 / $menge);
    $vName = $auf['verpackung_id'] ? scalar("SELECT name FROM item WHERE id=?", [(int)$auf['verpackung_id']]) : '';
    $besch = trim(((int)$auf['stueck'] ? (int)$auf['stueck'] . ' Stück je Packung' : '') . ($vName ? ' · ' . $vName : ''), ' ·');
    $positionen = [[
        'bezeichnung'  => $auf['produkt_name'] ?: 'Produkt',
        'beschreibung' => $besch,
        'menge'        => $menge,
        'einheit'      => 'Pkg.',
        'preis_cent'   => $preisCent,
        'ust_satz'     => $ustSatz,
    ]];

    $adr = trim(($k['strasse'] ?? '') . ' ' . ($k['hausnummer'] ?? '')) . "\n" . trim(($k['plz'] ?? '') . ' ' . ($k['ort'] ?? ''));
    if (!$istInland && !empty($k['land'])) $adr .= "\n" . $k['land'];
    $zaMap = ['vorkasse'=>'Vorkasse','rechnung'=>'Rechnung','lastschrift'=>'Lastschrift','paypal'=>'PayPal'];
    $za = $zaMap[$k['zahlungsart'] ?? 'vorkasse'] ?? ucfirst((string)($k['zahlungsart'] ?? 'Vorkasse'));

    $angNr = $auf['angebot_id'] ? scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$auf['angebot_id']]) : '';
    if ($art === 're') {
        $datum = $re['datum'] ?: $re['angelegt'];
        $ziel  = (int) meta_get('zahlungsziel_tage', 14);
        $b = [
            'belegart_label'    => 'Rechnung',
            'nummer'            => $re['nummer'],
            'datum'             => $datum,
            'faellig_bis'       => date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days')),
            'bezug'             => 'Auftrag ' . $auf['nummer'],
            'kopf_text'         => 'Wir berechnen Ihnen wie folgt:',
            'hinweis'           => 'Zahlbar innerhalb von ' . $ziel . ' Tagen ohne Abzug.',
        ];
        $fname = 'Rechnung_' . $re['nummer'];
    } else {
        $b = [
            'belegart_label'    => 'Auftragsbestätigung',
            'nummer'            => $auf['nummer'],
            'datum'             => $auf['angelegt'],
            'bezug'             => $angNr ? ('Angebot ' . $angNr) : '',
            'kopf_text'         => 'Vielen Dank für Ihren Auftrag. Wir bestätigen Ihnen die folgende Bestellung:',
            'hinweis'           => '',
        ];
        $fname = 'Auftragsbestaetigung_' . $auf['nummer'];
    }
    $b += [
        'empfaenger'       => $k['firma'],
        'adresse'          => $adr,
        'kundennummer'     => $k['kundennummer'] ?? '',
        'ust_id'           => $k['ust_id'] ?? '',
        'zahlungsart_label'=> $za,
        'bearbeiter'       => '',
        'bearbeiter_email' => '',
    ];

    $pdf = build_beleg_pdf($b, $positionen);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', $fname) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf; exit;
}

portal_head('Kundenportal · ' . $k['firma']);
?>
<div class="bx-shell">
  <aside class="bx-side">
    <div class="bx-brand">bulkify <span class="bx-ver">Portal</span></div>
    <nav>
      <div class="bx-navgroup"><?= h($k['firma']) ?></div>
      <?php foreach ($NAVGROUPS as $gruppe => $keys):
          $sichtbar = array_values(array_filter($keys, fn($key) => isset($L[$key])));
          if (!$sichtbar) continue;
          if ($gruppe !== ''): ?><div class="bx-navgroup"><?= h($gruppe) ?></div><?php endif;
          foreach ($sichtbar as $key): ?>
            <a href="<?= $portalLink($key) ?>"<?= $activeItem===$key ? ' class="on"' : '' ?>><?= h($L[$key]) ?><?php
              if ($key === 'angebote' && $offenAngebote): ?> <span class="pt-badge" style="float:right"><?= $offenAngebote ?></span><?php
              elseif ($key === 'meine_anfragen' && $anfPruef): $nv = count($anfPruef); ?> <span class="pt-badge" style="float:right" title="<?= $nv ?> <?= $nv === 1 ? 'Vorschlag wartet' : 'Vorschläge warten' ?> auf Ihre Freigabe"><?= $nv ?></span><?php endif; ?></a>
          <?php endforeach;
      endforeach; ?>
      <div class="bx-userbox"><button type="button" class="bx-themebtn">Dunkler Modus</button></div>
    </nav>
  </aside>
  <main class="bx-main">
  <?php if (isset($_GET['ok'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Vielen Dank – Ihre Bestätigung ist eingegangen. Wir starten die Bearbeitung.</div><?php endif; ?>
  <?php if (isset($_GET['anfrage'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Ihre Rezepturanfrage ist eingegangen – wir prüfen sie und melden uns.</div><?php endif; ?>
  <?php if (isset($_GET['angenommen'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Vielen Dank – die Rezeptur ist angenommen. Sie ist jetzt verbindlich festgelegt.</div><?php endif; ?>
  <?php if (isset($_GET['gesendet'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Ihre Anfrage ist eingegangen – wir prüfen sie und melden uns mit einem Angebot.</div><?php endif; ?>

<?php if ($view === 'start'): ?>
  <h1 style="margin-bottom:4px">Willkommen, <?= h($k['ansprechpartner'] ?: $k['firma']) ?></h1>
  <p class="bx-sub">Ihr Überblick – wählen Sie links einen Bereich für Details.</p>

  <?php if ($vorschlaege): $nV = count($vorschlaege); ?>
  <a href="#vorschlaege" class="bx-panel" style="display:flex;justify-content:space-between;align-items:center;gap:12px;text-decoration:none;color:inherit;border-color:var(--gruen);background:var(--panel-2)">
    <div><strong><?= $nV ?> <?= $nV === 1 ? 'Anfrage wurde beantwortet' : 'Anfragen wurden beantwortet' ?></strong> – Ihr <?= $nV === 1 ? 'Rezeptur-Vorschlag liegt' : 'Rezeptur-Vorschläge liegen' ?> zur Freigabe bereit.</div>
    <span class="btn btn-primary">Ansehen</span>
  </a>
  <?php endif; ?>

  <div class="pt-cards">
    <a class="pt-card" href="<?= $portalLink('angebote') ?>" style="text-decoration:none;color:inherit"><div class="k">Offene Angebote</div><div class="val"><?= $offenAngebote ?></div></a>
    <a class="pt-card" href="<?= $portalLink('bestellungen') ?>" style="text-decoration:none;color:inherit"><div class="k">Bestellungen in Arbeit</div><div class="val"><?= $inArbeit ?></div></a>
    <a class="pt-card" href="<?= $portalLink('rechnungen') ?>" style="text-decoration:none;color:inherit"><div class="k">Offene Rechnungen</div><div class="val"><?= $eur($offenBetrag) ?></div></a>
  </div>

  <?php if ($vorschlaege): ?>
  <div class="bx-panel" id="vorschlaege" style="border-color:var(--gruen);background:var(--panel-2)">
    <h2>Rezeptur-Vorschläge für Sie</h2>
    <p class="muted" style="margin-top:0">Wir haben Ihre Anfrage geprüft. Bitte schauen Sie sich den Vorschlag an und nehmen Sie ihn an, damit wir starten können.</p>
    <?php foreach ($vorschlaege as $vs): ?>
      <div class="bx-panel" style="background:var(--panel)">
        <div class="bx-row" style="justify-content:space-between"><div><strong><?= h($vs['name']) ?></strong> · <?= h($vs['nummer']) ?></div><div><?= bx_badge('Vorschlag','info') ?></div></div>
        <table class="bx-table" style="margin:10px 0"><thead><tr><th>Zutat</th><th class="bx-num">Menge je Einheit</th></tr></thead><tbody>
          <?php $vsum = 0; foreach ($vorschlagZutaten[$vs['id']] as $z): $vsum += (float)$z['menge_mg']; ?>
            <tr><td><?= h($z['bezeichnung']) ?></td><td class="bx-num"><?= rtrim(rtrim(number_format((float)$z['menge_mg'],2,',','.'),'0'),',') ?> mg</td></tr>
          <?php endforeach; ?>
          <tr style="font-weight:600"><td>Gesamt je Einheit</td><td class="bx-num"><?= rtrim(rtrim(number_format($vsum,2,',','.'),'0'),',') ?> mg</td></tr>
        </tbody></table>
        <?php if ($vs['darreichungsform'] === 'kapsel'): $vkg = $kapselAnzeige($vs, $vsum); ?>
          <div class="muted" style="margin:-4px 0 10px;font-size:13px">Kapselgröße: <strong><?= $vkg ? h($vkg['name']) : 'individuell (Füllgewicht > Standardkapsel)' ?></strong></div>
        <?php endif; ?>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="rezeptur_annehmen"><input type="hidden" name="rezeptur_id" value="<?= (int)$vs['id'] ?>"><button class="btn btn-primary" type="submit">Rezeptur verbindlich annehmen</button></form>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($offenAngebote): ?>
  <div class="bx-panel" style="border-color:#cfe0cb">
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <div>Sie haben <strong><?= $offenAngebote ?></strong> offene<?= $offenAngebote===1?'s':'' ?> Angebot<?= $offenAngebote===1?'':'e'?> zur Bestätigung.</div>
      <a class="btn btn-primary" href="<?= $portalLink('angebote') ?>">Angebote ansehen</a>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($view === 'anfrage'):
    // Bearbeiten-Modus: nur eigene Anfrage mit Status „neu" (noch nicht in Bearbeitung durch uns).
    $editAnf = null; $editWunsch = [];
    if (!empty($_GET['edit']) && $k) {
        $editAnf = one("SELECT * FROM rezeptur_anfrage WHERE id=? AND kunde_id=? AND status='neu'", [(int)$_GET['edit'], (int)$k['id']]);
        if ($editAnf) $editWunsch = all("SELECT * FROM rezeptur_anfrage_wunsch WHERE anfrage_id=? ORDER BY sort, id", [(int)$editAnf['id']]);
    }
    $ea = fn($key, $d = '') => h((string)($editAnf[$key] ?? $d));
    $wzeilen = $editAnf ? $editWunsch : [];   // im Bearbeiten-Modus vorhandene Zeilen, sonst leer (→ 3 Leerzeilen)
?>
  <h1 style="margin-bottom:4px"><?= $editAnf ? 'Anfrage bearbeiten' : 'Rezeptur anfragen' ?></h1>
  <div class="bx-panel">
    <?php if (isset($_GET['geaendert'])): ?><div class="bx-panel badge-ok" style="padding:10px 14px;margin-bottom:12px">Ihre Anfrage wurde aktualisiert.</div><?php endif; ?>
    <p class="muted" style="margin-top:0"><?= $editAnf ? 'Sie können Ihre Anfrage <strong>' . h($editAnf['nummer']) . '</strong> ändern, solange wir sie noch nicht bearbeiten.' : 'Sagen Sie uns, was rein soll – wir prüfen die Machbarkeit und melden uns mit einem Vorschlag.' ?></p>
    <form method="post">
      <input type="hidden" name="aktion" value="<?= $editAnf ? 'anfrage_bearbeiten' : 'anfrage_senden' ?>">
      <?php if ($editAnf): ?><input type="hidden" name="anfrage_id" value="<?= (int)$editAnf['id'] ?>"><?php endif; ?>
      <div class="bx-grid">
        <div class="bx-field"><label>Wunsch-Produktname <?= bx_hint('Wie soll das Produkt heißen? Arbeitstitel – Sie können ihn später ändern.') ?></label><input type="text" name="produktname" value="<?= $ea('produktname') ?>" placeholder="z. B. Immun-Komplex Forte"></div>
        <div class="bx-field"><label>Darreichungsform</label>
          <select name="form" id="pf_form"><?php foreach ($DFORM_P as $key=>$lbl): ?><option value="<?= $key ?>" <?= ($editAnf['darreichungsform'] ?? '')===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?></select>
        </div>
      </div>
      <table class="bx-table" style="margin-bottom:10px">
        <thead><tr><th>Wirkstoff / Zutat</th><th style="width:120px">Menge je Kapsel</th><th style="width:90px">Einheit</th><th></th></tr></thead>
        <tbody id="pwrows">
          <?php
          $rows = $wzeilen ?: array_fill(0, 3, ['bezeichnung'=>'','wunsch_menge'=>'','einheit'=>'mg']);
          foreach ($rows as $wz): ?>
          <tr class="pwrow">
            <td><input type="text" name="w_bez[]" value="<?= h((string)($wz['bezeichnung'] ?? '')) ?>" placeholder="z. B. Vitamin C"></td>
            <td><input type="text" name="w_menge[]" value="<?= h((string)($wz['wunsch_menge'] ?? '')) ?>"></td>
            <td><select name="w_einheit[]" style="width:80px"><?php foreach (['mg','g','µg','IE','ml'] as $eh): ?><option value="<?= $eh ?>" <?= ($wz['einheit'] ?? 'mg')===$eh?'selected':'' ?>><?= $eh ?></option><?php endforeach; ?></select></td>
            <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.pwrow').remove()">×</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button type="button" class="btn btn-ghost btn-sm" id="paddW">+ Zutat</button>
      <div id="pf_kapselcheck" style="display:none;margin-top:14px;border-radius:var(--r);padding:12px 14px;font-size:14px"></div>
      <div class="bx-field" style="margin-top:12px"><label>Notiz (optional)</label><textarea name="notiz" placeholder="z. B. Zielgruppe, Wünsche, gut verträglich …"><?= $ea('notiz') ?></textarea></div>
      <button class="btn btn-primary" type="submit"><?= $editAnf ? 'Änderungen speichern' : 'Anfrage senden' ?></button>
      <?php if ($editAnf): ?><a class="btn btn-ghost" href="<?= $portalLink('anfrage') ?>&anfrage=1">Abbrechen</a><?php endif; ?>
    </form>
  </div>

  <div class="bx-panel"><div class="muted">Alle Ihre Anfragen und deren Stand finden Sie unter <a href="<?= $portalLink('meine_anfragen') ?>">Meine Anfragen</a>.</div></div>

<?php elseif ($view === 'meine_anfragen'): ?>
  <h1 style="margin-bottom:4px">Meine Anfragen</h1>
  <p class="bx-sub">Ihre Rezepturanfragen und deren Stand. Oben steht, was auf Ihre Freigabe wartet.</p>

  <?php if ($anfPruef): ?>
  <div class="bx-panel" style="border-color:var(--gruen);background:var(--panel-2)">
    <h2 style="margin-top:0">Wartet auf Sie (<?= count($anfPruef) ?>)</h2>
    <p class="muted" style="margin-top:0">Zu diesen Anfragen liegt ein Vorschlag bereit – bitte prüfen und annehmen oder ablehnen.</p>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nummer</th><th>Wunschname</th><th>Form</th><th>Vorschlag</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($anfPruef as $an): ?>
        <tr>
          <td><?= h($an['nummer']) ?></td>
          <td><?= $an['produktname'] ? h($an['produktname']) : '<span class="muted">–</span>' ?></td>
          <td><?= h($DFORM_P[$an['darreichungsform']] ?? $an['darreichungsform']) ?></td>
          <td><?= $an['rezeptur_name'] ? h($an['rezeptur_name']) : '<span class="muted">–</span>' ?></td>
          <td style="text-align:right"><a class="btn btn-primary btn-sm" href="<?= $portalLink('rezeptur') ?>&rid=<?= (int)$an['rezeptur_id'] ?>">Prüfen &amp; entscheiden</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

  <div class="bx-panel">
    <div class="settabs" style="margin:0 0 12px">
      <?php foreach ($anfTabs as $tk => $tl): $n = $tk === 'alle' ? count($meineAnfRows) : count(array_filter($meineAnfRows, fn($r) => $r['typ'] === $tk)); ?>
        <a href="<?= $portalLink('meine_anfragen') ?>&atab=<?= $tk ?>" class="<?= $atab === $tk ? 'on' : '' ?>"><?= h($tl) ?><?= $n ? ' (' . $n . ')' : '' ?></a>
      <?php endforeach; ?>
    </div>
    <?php $rowsTab = array_values(array_filter($meineAnfRows, fn($r) => $atab === 'alle' || $r['typ'] === $atab)); ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nummer</th><?php if ($atab === 'alle'): ?><th>Typ</th><?php endif; ?><th>Bezeichnung</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rowsTab): ?><tr><td colspan="5" class="muted">Keine Anfragen in diesem Bereich.</td></tr><?php endif; ?>
      <?php foreach ($rowsTab as $r): ?>
        <tr>
          <td><?= h($r['nummer']) ?></td>
          <?php if ($atab === 'alle'): ?><td><?= h($typLabelP[$r['typ']] ?? $r['typ']) ?></td><?php endif; ?>
          <td><?= $r['bez'] ? h($r['bez']) : '<span class="muted">–</span>' ?></td>
          <td><?= $r['status'] ?></td>
          <td style="text-align:right"><?php if ($r['aktion']): ?><a class="btn <?= $r['aktion']['primary'] ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="<?= h($r['aktion']['href']) ?>"><?= h($r['aktion']['label']) ?></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="muted" style="font-size:12px;margin-top:10px;line-height:1.7">
      <strong>Was die Status bedeuten:</strong><br>
      <?= bx_badge('in Prüfung','warn') ?> Ihre Anfrage liegt bei uns – wir prüfen sie.<br>
      <?= bx_badge('Vorschlag erhalten','ok') ?> Wir haben Ihnen einen Rezeptur-Vorschlag gesendet – bitte prüfen und annehmen oder ablehnen.<br>
      <?= bx_badge('Angebot erhalten','ok') ?> Zu Ihrer Produkt-/Rohstoff-/Dienstleistungsanfrage liegt ein Angebot vor – Details unter „Angebote".<br>
      <?= bx_badge('abgelehnt','err') ?> Der Vorschlag wurde abgelehnt (von Ihnen oder von uns) – wir überarbeiten ihn.<br>
      <?= bx_badge('Rezeptur angelegt','ok') ?> Der Vorschlag ist final bestätigt – die Rezeptur ist angelegt.
    </div>
  </div>

<?php elseif ($view === 'rezepturen'):
    $eigeneRez  = array_values(array_filter($meineRezepturen, fn($r) => !empty($r['kunde_id'])));
    $katalogRez = array_values(array_filter($meineRezepturen, fn($r) => empty($r['kunde_id'])));
    $rezTabelle = function(array $liste) use ($DFORM_P, $rezBadge, $portalLink) { ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Nummer</th><th>Name</th><th>Form</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($liste as $rz): ?>
          <tr>
            <td><?= h($rz['nummer']) ?></td>
            <td><?= h($rz['name']) ?></td>
            <td><?= h($DFORM_P[$rz['darreichungsform']] ?? $rz['darreichungsform']) ?></td>
            <td><?= $rezBadge($rz['status']) ?></td>
            <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="<?= $portalLink('rezeptur') ?>&rid=<?= (int)$rz['id'] ?>">ansehen</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php }; ?>
  <h1 style="margin-bottom:4px">Rezepturen</h1>
  <p class="bx-sub">Ihre eigenen Rezepturen und unsere freigegebenen Katalog-Rezepturen. Wählen Sie eine für Details – oder stellen Sie eine neue Rezepturanfrage.</p>

  <div class="bx-panel">
    <h2 style="margin-top:0">Eigene Rezepturen</h2>
    <?php if ($eigeneRez) { $rezTabelle($eigeneRez); } else { ?>
      <div class="muted">Noch keine eigenen Rezepturen. Stellen Sie eine Rezepturanfrage – nach unserer Prüfung erscheint hier Ihr Vorschlag.</div>
    <?php } ?>
  </div>

  <div class="bx-panel">
    <h2 style="margin-top:0">Katalog-Rezepturen</h2>
    <?php if ($katalogRez) { $rezTabelle($katalogRez); } else { ?>
      <div class="muted">Aktuell keine freigegebenen Katalog-Rezepturen verfügbar.</div>
    <?php } ?>
  </div>

<?php elseif ($view === 'rezeptur'): ?>
  <?php if (!$rezDetail): ?>
    <h1 style="margin-bottom:4px">Rezeptur</h1>
    <div class="bx-panel"><div class="muted">Rezeptur nicht gefunden.</div><div style="margin-top:12px"><a class="btn btn-ghost" href="<?= $portalLink('rezepturen') ?>">Zurück zur Liste</a></div></div>
  <?php else: $dfP = in_array($rezDetail['darreichungsform'], ['pulver','stick','granulat'], true) ? 'Portion' : 'Einheit'; ?>
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h1 style="margin:0"><?= h($rezDetail['name']) ?></h1>
      <a class="btn btn-ghost btn-sm" href="<?= $portalLink('rezepturen') ?>">Zurück zur Liste</a>
    </div>
    <p class="bx-sub"><?= h($rezDetail['nummer']) ?> · <?= h($DFORM_P[$rezDetail['darreichungsform']] ?? $rezDetail['darreichungsform']) ?> · <?= $rezBadge($rezDetail['status']) ?></p>
    <div class="bx-panel">
      <h2>Zutaten je <?= $dfP ?></h2>
      <?php if (!$rezZutaten): ?><div class="muted">Noch keine Zutaten hinterlegt.</div>
      <?php else: ?>
      <table class="bx-table"><thead><tr><th>Zutat</th><th class="bx-num">Menge je <?= $dfP ?></th></tr></thead><tbody>
        <?php $sum = 0; foreach ($rezZutaten as $z): $sum += (float)$z['menge_mg']; ?>
          <tr><td><?= h($z['bezeichnung']) ?></td><td class="bx-num"><?= rtrim(rtrim(number_format((float)$z['menge_mg'],2,',','.'),'0'),',') ?> mg</td></tr>
        <?php endforeach; ?>
        <tr style="font-weight:600"><td>Gesamt je <?= $dfP ?></td><td class="bx-num"><?= rtrim(rtrim(number_format($sum,2,',','.'),'0'),',') ?> mg</td></tr>
      </tbody></table>
      <?php if ($rezDetail['darreichungsform'] === 'kapsel'): $kg = $kapselAnzeige($rezDetail, $sum); $fix = !empty($rezDetail['kapselgroesse_id']); ?>
        <div style="margin-top:10px;font-size:14px"><strong>Kapselgröße:</strong>
          <?= $kg ? h($kg['name']) . ' <span class="muted">(fasst bis ' . (int)$kg['fuellmenge_mg'] . ' mg)</span>' : '<span class="muted">Füllgewicht zu groß für eine Standardkapsel – wir schlagen eine andere Form oder Aufteilung vor.</span>' ?>
          <?php if (!$fix): ?><div class="muted" style="font-size:12px">Richtwert aus dem Wirkstoffgewicht – die endgültige Größe legen wir mit der finalen Rezeptur (inkl. Hilfsstoffe) fest.</div><?php endif; ?>
        </div>
      <?php endif; ?>
      <?php endif; ?>
      <?php if ($rezDetail['status'] === 'vorschlag'): ?>
        <?php if (isset($_GET['ablehngrund'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px;margin-top:12px">Bitte geben Sie einen Grund an, dann können wir den Vorschlag gezielt überarbeiten.</div><?php endif; ?>
        <div class="muted" style="margin-top:14px;font-size:13px">Das ist unser <strong>Vorschlag</strong> zu Ihrer Anfrage. Sind Sie einverstanden, nehmen Sie ihn hier verbindlich an – danach erstellen wir Angebot &amp; Produktion. Passt etwas nicht, lehnen Sie ihn mit einem kurzen Grund ab – dann überarbeiten wir ihn.</div>
        <div class="bx-row" style="margin-top:10px;gap:8px">
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="rezeptur_annehmen"><input type="hidden" name="rezeptur_id" value="<?= (int)$rezDetail['id'] ?>"><button class="btn btn-primary" type="submit">Rezeptur verbindlich annehmen</button></form>
          <button type="button" class="btn btn-ghost" onclick="document.getElementById('rezRejModal').style.display='flex'">Ablehnen</button>
        </div>
        <div id="rezRejModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);align-items:center;justify-content:center;z-index:1000;padding:16px">
          <div class="bx-panel" style="max-width:460px;width:100%;margin:0">
            <h2 style="margin-top:0">Vorschlag ablehnen</h2>
            <p class="muted" style="margin-top:0;font-size:13px">Was passt nicht bzw. was sollen wir ändern? Ihr Grund hilft uns, den Vorschlag gezielt zu überarbeiten.</p>
            <form method="post">
              <input type="hidden" name="aktion" value="rezeptur_ablehnen">
              <input type="hidden" name="rezeptur_id" value="<?= (int)$rezDetail['id'] ?>">
              <div class="bx-field"><label>Grund (Pflicht)</label><textarea name="grund" required placeholder="z. B. bitte ohne Magnesiumstearat, Dosierung Vitamin C höher …"></textarea></div>
              <div class="bx-row" style="margin-top:12px;gap:8px">
                <button class="btn btn-primary" type="submit">Ablehnung senden</button>
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('rezRejModal').style.display='none'">Abbrechen</button>
              </div>
            </form>
          </div>
        </div>
      <?php elseif ($rezDetail['status'] === 'abgelehnt'): ?>
        <div class="bx-panel" style="margin-top:12px;padding:12px 14px"><strong>Sie haben diesen Vorschlag abgelehnt.</strong><?php if (!empty($rezDetail['ablehnung_grund'])): ?><div style="margin-top:4px">Grund: <?= h($rezDetail['ablehnung_grund']) ?></div><?php endif; ?><div class="muted" style="margin-top:6px;font-size:13px">Wir überarbeiten ihn und melden uns mit einem neuen Vorschlag.</div></div>
      <?php elseif ($rezDetail['status'] === 'eingefroren'): ?>
        <div class="muted" style="margin-top:12px">Diese Rezeptur haben Sie angenommen – sie ist verbindlich festgelegt.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php elseif ($view === 'produkte'): ?>
  <h1 style="margin-bottom:4px">Produkte</h1>
  <p class="bx-sub">Unser Produktkatalog. Wählen Sie ein Produkt für Details und eine Anfrage.</p>
  <div class="bx-panel">
    <?php if (!$katalog): ?><div class="muted">Aktuell sind keine Produkte im Katalog verfügbar.</div>
    <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Produkt</th><th>Form</th><th class="bx-num">Preis</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($katalog as $pk): $ab = $abPreis($pk['id']); ?>
        <tr><td><?= h($pk['name']) ?></td>
          <td><?= $pk['darreichungsform'] ? h($DFORM_P[$pk['darreichungsform']] ?? $pk['darreichungsform']) : '–' ?></td>
          <td class="bx-num"><?= $ab !== null ? 'ab '.$eur($ab).' *' : '<span class="muted">auf Anfrage</span>' ?></td>
          <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="<?= $portalLink('produkt') ?>&pid=<?= (int)$pk['id'] ?>">ansehen</a></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="muted" style="font-size:12px;margin-top:8px">* zzgl. Verpackung und Etikett</div>
    <?php endif; ?>
  </div>

<?php elseif ($view === 'produkt'): ?>
  <?php if (!$prodDetail): ?>
    <h1 style="margin-bottom:4px">Produkt</h1>
    <div class="bx-panel"><div class="muted">Produkt nicht gefunden.</div><div style="margin-top:12px"><a class="btn btn-ghost" href="<?= $portalLink('produkte') ?>">Zurück zum Katalog</a></div></div>
  <?php else: ?>
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h1 style="margin:0"><?= h($prodDetail['anzeige_name'] ?? $prodDetail['name']) ?></h1>
      <a class="btn btn-ghost btn-sm" href="<?= $portalLink('produkte') ?>">Zurück zum Katalog</a>
    </div>
    <p class="bx-sub"><?= h($prodDetail['nummer']) ?><?= $prodDetail['darreichungsform'] ? ' · '.h($DFORM_P[$prodDetail['darreichungsform']] ?? $prodDetail['darreichungsform']) : '' ?></p>
    <?php $dfE = in_array($prodDetail['darreichungsform'] ?? '', ['pulver','stick','granulat'], true) ? 'Portion' : 'Einheit'; ?>
    <?php if ($prodZutaten): ?>
    <div class="bx-panel"><h2>Zusammensetzung</h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Zutat (aus unseren Rohstoffen)</th><th>Wirkstoffe</th><th class="bx-num">Menge je <?= $dfE ?></th></tr></thead>
        <tbody>
        <?php foreach ($prodZutaten as $z):
            $wl = $z['item_id'] && isset($prodWirk[$z['item_id']]) ? implode(' · ', $prodWirk[$z['item_id']]) : ''; ?>
          <tr>
            <td><?php if ($z['item_id'] && $k['portal_rohstoffe']): ?><a href="<?= $portalLink('rohstoff') ?>&iid=<?= (int)$z['item_id'] ?>"><?= h($z['bezeichnung']) ?></a><?php else: ?><?= h($z['bezeichnung']) ?><?php endif; ?></td>
            <td class="muted"><?= $wl !== '' ? h($wl) : '–' ?></td>
            <td class="bx-num"><?= rtrim(rtrim(number_format((float)$z['menge_mg'],2,',','.'),'0'),',') ?> mg</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

    <?php if ($prodNaehr): ?>
    <div class="bx-panel"><h2>Nährwerte je <?= $dfE ?></h2>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Nährstoff</th><th class="bx-num">Menge</th><th class="bx-num">% NRV*</th></tr></thead>
        <tbody>
        <?php foreach ($prodNaehr as $n):
            $mg = $n['mg']; $betrag = $n['einheit'] === 'µg' ? rtrim(rtrim(number_format($mg*1000,1,',','.'),'0'),',').' µg' : rtrim(rtrim(number_format($mg,1,',','.'),'0'),',').' mg';
            $pct = '–'; if ($n['nrv'] !== null && $n['nrv'] !== '') { $nrvMg = $n['einheit']==='µg' ? (float)$n['nrv']/1000 : (float)$n['nrv']; if ($nrvMg > 0) $pct = number_format($mg/$nrvMg*100, 0, ',', '.') . ' %'; } ?>
          <tr><td><?= h($n['name']) ?></td><td class="bx-num"><?= $betrag ?></td><td class="bx-num"><?= $pct ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="muted" style="margin-top:6px;font-size:12px">* NRV = Nährstoffbezugswert je <?= $dfE ?>. Die Verzehrempfehlung (Einheiten/Tag) legen Sie fest.</div>
    </div>
    <?php endif; ?>

    <?php if ($prodAllergene || $prodVegan !== null || $prodGvo !== null): ?>
    <div class="bx-panel"><h2>Deklaration</h2>
      <div class="bx-tablewrap"><table class="bx-table"><tbody>
        <tr><td style="width:220px">Allergene</td><td><?= $prodAllergene ? h(implode(', ', $prodAllergene)) : 'keine deklarationspflichtigen Allergene' ?></td></tr>
        <?php if ($prodVegan !== null): ?><tr><td>Vegan / vegetarisch</td><td><?= $prodVegan ? 'ja' : 'nein' ?></td></tr><?php endif; ?>
        <?php if ($prodGvo !== null): ?><tr><td>GVO-frei</td><td><?= $prodGvo ? 'ja' : 'nein' ?></td></tr><?php endif; ?>
      </tbody></table></div>
      <div class="muted" style="margin-top:6px;font-size:12px">Automatisch aus den eingesetzten Rohstoffen abgeleitet.</div>
    </div>
    <?php endif; ?>
    <?php if ($prodPreise): ?>
    <div class="bx-panel"><h2>Größen &amp; Preise</h2>
      <p class="muted" style="margin-top:0">Richtpreise je Packung – der genaue Preis hängt von Verpackung und Bestellmenge ab (mehr = günstiger).</p>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Größe</th><th class="bx-num">Preis je Packung</th></tr></thead>
        <tbody>
        <?php foreach ($prodPreise as $pp): ?>
          <tr><td><?= $prodForm === 'stick' && $prodPortionG > 0
                      ? rtrim(rtrim(number_format($pp['stueck'] * $prodPortionG, 1, ',', '.'), '0'), ',') . ' g je Packung'
                      : h(form_groessen_label($prodForm, (float)$pp['stueck'])) . ' je Packung' ?></td>
              <td class="bx-num">ab <?= $eur($pp['ab']) ?> *</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div class="muted" style="font-size:12px;margin-top:8px">* zzgl. Verpackung und Etikett</div>
    </div>
    <?php else: ?>
    <div class="bx-panel"><h2>Preis</h2>
      <p class="muted" style="margin-top:0">Für dieses Produkt liegt Ihnen noch kein Preis vor. Stellen Sie unten eine Anfrage – wir kalkulieren Ihre Mengen und melden uns mit einem Angebot.</p>
    </div>
    <?php endif; ?>
    <div class="bx-panel"><h2>Anfrage stellen</h2>
      <form method="post">
        <input type="hidden" name="aktion" value="produkt_anfrage">
        <input type="hidden" name="produkt_id" value="<?= (int)$prodDetail['id'] ?>">
        <div class="bx-grid">
          <?php if ($prodIstFuell): ?>
          <div class="bx-field"><label>Füllmenge je Packung (<?= h($prodFuellEinheit) ?>) <?= bx_hint('wie viel pro Dose/Flasche/Beutel – wir wählen die passende Verpackung dazu') ?></label><input type="number" name="fuellmenge_g" min="1" step="1" placeholder="<?= $prodFuellEinheit === 'ml' ? 'z. B. 250' : 'z. B. 200' ?>"></div>
          <?php else: ?>
          <div class="bx-field"><label>Stück je Packung</label><select name="stueck"><?php foreach ($stdStueck as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?></select></div>
          <?php endif; ?>
          <div class="bx-field"><label>Verpackungstyp <?= bx_hint('Sie wählen nur die Art – wir bestimmen das perfekt passende Gebinde in der richtigen Größe.') ?></label>
            <select name="verpackung_typ"><option value="">– egal / bitte empfehlen –</option><?php foreach ($VTYPEN as $tk=>$tl): ?><option value="<?= $tk ?>"><?= h($tl) ?></option><?php endforeach; ?></select>
          </div>
          <div class="bx-field"><label>Anzahl (Packungen) <?= bx_hint('Mehrere Mengen mit Komma für Staffelpreise, z. B. 1000, 2500, 5000') ?></label><input type="text" name="menge" placeholder="z. B. 1000, 2500, 5000"></div>
        </div>
        <div class="bx-field"><label>Notiz (optional)</label><textarea name="notiz" placeholder="Wünsche, Zieltermin …"></textarea></div>
        <button class="btn btn-primary" type="submit">Anfrage senden</button>
      </form>
    </div>
  <?php endif; ?>

<?php elseif ($view === 'prodanfrage'): ?>
  <h1 style="margin-bottom:4px">Produkt anfragen</h1>
  <div class="bx-panel">
    <p class="muted" style="margin-top:0">Wählen Sie ein Produkt aus dem Katalog + gewünschte Stückzahl, Verpackung und Bestellmenge – wir melden uns mit einem Preis.</p>
    <?php if (!$katalog): ?><div class="muted">Aktuell sind keine Produkte im Katalog verfügbar.</div>
    <?php else: ?>
    <form method="post">
      <input type="hidden" name="aktion" value="produkt_anfrage">
      <div class="bx-grid">
        <div class="bx-field"><label>Produkt</label>
          <select name="produkt_id" id="pa_produkt" required><option value="">– wählen –</option>
            <?php foreach ($katalog as $pk): ?><option value="<?= (int)$pk['id'] ?>" data-form="<?= h($pk['darreichungsform']) ?>"><?= h($pk['name']) ?><?= $pk['darreichungsform'] ? ' · '.h($DFORM_P[$pk['darreichungsform']] ?? $pk['darreichungsform']) : '' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="bx-field" id="pa_stueck_wrap"><label>Stück je Packung</label><select name="stueck"><?php foreach ($stdStueck as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?></select></div>
        <div class="bx-field" id="pa_fuell_wrap" style="display:none"><label>Füllmenge je Packung <span id="pa_fuell_einheit">(g)</span> <?= bx_hint('wie viel pro Dose/Flasche/Beutel – wir wählen die passende Verpackung dazu') ?></label><input type="number" name="fuellmenge_g" min="1" step="1" placeholder="z. B. 200"></div>
        <div class="bx-field"><label>Verpackungstyp <?= bx_hint('Sie wählen nur die Art – wir bestimmen das perfekt passende Gebinde in der richtigen Größe.') ?></label>
          <select name="verpackung_typ"><option value="">– egal / bitte empfehlen –</option><?php foreach ($VTYPEN as $tk=>$tl): ?><option value="<?= $tk ?>"><?= h($tl) ?></option><?php endforeach; ?></select>
        </div>
        <div class="bx-field"><label>Bestellmenge (Packungen) <?= bx_hint('Mehrere Mengen mit Komma für Staffelpreise, z. B. 1000, 2500, 5000') ?></label><input type="text" name="menge" placeholder="z. B. 1000, 2500, 5000"></div>
      </div>
      <div class="bx-field"><label>Notiz (optional)</label><textarea name="notiz" placeholder="Wünsche, Zieltermin …"></textarea></div>
      <button class="btn btn-primary" type="submit">Anfrage senden</button>
    </form>
    <script>(function(){
      var sel=document.getElementById('pa_produkt'); if(!sel) return;
      function upd(){
        var o=sel.options[sel.selectedIndex], f=o?o.getAttribute('data-form'):'';
        // Pulver/Granulat werden nach Gramm angefragt, Flüssiges nach Milliliter, alles andere nach Stückzahl
        var fuell=(f==='pulver'||f==='granulat'||f==='fluessig');
        document.getElementById('pa_stueck_wrap').style.display=fuell?'none':'';
        document.getElementById('pa_fuell_wrap').style.display=fuell?'':'none';
        document.getElementById('pa_fuell_einheit').textContent=(f==='fluessig')?'(ml)':'(g)';
      }
      sel.addEventListener('change',upd); upd();
    })();</script>
    <?php endif; ?>
  </div>
  <?php $meineProd = array_filter($portalAnfragen, fn($a) => $a['typ']==='produkt'); if ($meineProd): ?>
  <div class="bx-panel"><h2>Meine Produktanfragen</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nr.</th><th>Produkt</th><th class="bx-num">Größe je Packung</th><th>Verpackungstyp</th><th class="bx-num">Anzahl</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($meineProd as $a): ?>
        <tr><td><?= h($a['nummer']) ?></td><td><?= h($a['produkt_name'] ?: '–') ?></td>
          <td class="bx-num"><?= $a['fuellmenge_g'] ? rtrim(rtrim(number_format((float)$a['fuellmenge_g'],1,',','.'),'0'),',').' g' : ($a['stueck'] ? (int)$a['stueck'].' Stk' : '–') ?></td>
          <td><?= h($a['verpackung_typ'] ? ($VTYPEN[$a['verpackung_typ']] ?? $a['verpackung_typ']) : '–') ?></td><td class="bx-num"><?= $a['menge'] ? (int)$a['menge'] : '–' ?></td><td><?= $pafBadge($a['status']) ?></td>
          <td style="text-align:right"><?php if (!empty($a['angebot_id'])): ?><a class="btn btn-primary btn-sm" href="<?= $portalLink('angebote') ?>#a<?= (int)$a['angebot_id'] ?>">Zum Angebot</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

<?php elseif ($view === 'rohstoffe'): ?>
  <h1 style="margin-bottom:4px">Rohstoffe</h1>
  <p class="bx-sub">Unser Rohstoff-Katalog. Preise auf Anfrage.</p>
  <div class="bx-panel">
    <?php if (!$rohkatalog): ?><div class="muted">Aktuell keine Rohstoffe verfügbar.</div>
    <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Name</th><th>Form</th><th>CAS</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rohkatalog as $ro): ?>
        <tr><td><?= h($ro['name']) ?></td><td><?= h($FORMLBL_P[$ro['form']] ?? $ro['form']) ?></td><td><?= h($ro['cas'] ?: '–') ?></td>
          <td style="text-align:right"><a class="btn btn-ghost btn-sm" href="<?= $portalLink('rohstoff') ?>&iid=<?= (int)$ro['id'] ?>">ansehen</a></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($view === 'rohstoff'): ?>
  <?php if (!$rohDetail): ?>
    <h1 style="margin-bottom:4px">Rohstoff</h1>
    <div class="bx-panel"><div class="muted">Rohstoff nicht gefunden.</div><div style="margin-top:12px"><a class="btn btn-ghost" href="<?= $portalLink('rohstoffe') ?>">Zurück zum Katalog</a></div></div>
  <?php else: ?>
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h1 style="margin:0"><?= h($rohDetail['name']) ?></h1>
      <a class="btn btn-ghost btn-sm" href="<?= $portalLink('rohstoffe') ?>">Zurück zum Katalog</a>
    </div>
    <p class="bx-sub"><?= h($FORMLBL_P[$rohDetail['form']] ?? $rohDetail['form']) ?><?= $rohDetail['name_lat'] ? ' · '.h($rohDetail['name_lat']) : '' ?><?= $rohDetail['cas'] ? ' · CAS '.h($rohDetail['cas']) : '' ?></p>

    <?php if ($rohKennwerte): ?>
    <div class="bx-panel"><h2>Kennwerte</h2>
      <div class="bx-tablewrap"><table class="bx-table"><tbody>
        <?php foreach ($rohKennwerte as $kw): ?><tr><td><?= h($kw['parameter']) ?></td><td class="bx-num"><?= h($kw['wert']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
    <?php endif; ?>

    <?php
      // Eigenschaften nur zeigen, wenn befüllt
      $eig = [];
      if ($rohDetail['bot_quelle'])    $eig['Botanische Quelle'] = $rohDetail['bot_quelle'];
      if ($rohDetail['herkunftsland']) $eig['Herkunftsland'] = $rohDetail['herkunftsland'];
      if ($rohDetail['allergene'])     $eig['Allergene'] = $rohDetail['allergene'];
      if ($jaNein($rohDetail['vegan']) !== null)        $eig['Vegan / vegetarisch'] = $jaNein($rohDetail['vegan']) ? 'ja' : 'nein';
      if ($jaNein($rohDetail['gvo_frei']) !== null)     $eig['GVO-frei'] = $jaNein($rohDetail['gvo_frei']) ? 'ja' : 'nein';
      if ($jaNein($rohDetail['bestrahlt']) !== null)    $eig['Bestrahlt / ETO'] = $jaNein($rohDetail['bestrahlt']) ? 'ja' : 'nein';
      if ($jaNein($rohDetail['tse_bse_frei']) !== null) $eig['TSE/BSE-frei'] = $jaNein($rohDetail['tse_bse_frei']) ? 'ja' : 'nein';
      if ($rohDetail['zertifikate'])   $eig['Zertifikate'] = $rohDetail['zertifikate'];
      if ($rohDetail['zusaetze'])      $eig['Zusätze'] = $rohDetail['zusaetze'];
      if ($rohDetail['haltbarkeit'])   $eig['Haltbarkeit'] = $rohDetail['haltbarkeit'];
      if ($rohDetail['lagerbedingungen']) $eig['Lagerung'] = $rohDetail['lagerbedingungen'];
    ?>
    <?php if ($eig): ?>
    <div class="bx-panel"><h2>Eigenschaften &amp; Deklaration</h2>
      <div class="bx-tablewrap"><table class="bx-table"><tbody>
        <?php foreach ($eig as $lbl => $wert): ?><tr><td style="width:220px"><?= h($lbl) ?></td><td><?= h($wert) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </div>
    <?php endif; ?>

    <div class="bx-panel"><h2>Anfrage stellen</h2>
      <p class="muted" style="margin-top:0">Preis auf Anfrage – nennen Sie uns die gewünschte Menge.</p>
      <form method="post">
        <input type="hidden" name="aktion" value="rohstoff_anfrage">
        <input type="hidden" name="betreff" value="<?= h($rohDetail['name']) ?>">
        <input type="hidden" name="rohstoff_id" value="<?= (int)$rohDetail['id'] ?>">
        <div class="bx-grid">
          <div class="bx-field"><label>Gewünschte Menge</label><input type="number" name="wunsch_menge" min="0" step="0.001" placeholder="z. B. 25"></div>
          <div class="bx-field"><label>Einheit</label><select name="wunsch_einheit"><?php foreach (['kg','g','t','Stück','L'] as $e): ?><option value="<?= $e ?>"><?= $e ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="bx-field"><label>Details (optional)</label><textarea name="notiz" placeholder="z. B. vegan, Zieltermin, Spezifikation"></textarea></div>
        <button class="btn btn-primary" type="submit">Anfrage senden</button>
      </form>
    </div>
  <?php endif; ?>

<?php elseif ($view === 'rohanfrage' || $view === 'dienstleistung'):
    $istRoh = $view === 'rohanfrage';
    $titel  = $istRoh ? 'Rohstoff anfragen' : 'Dienstleistung anfragen';
    $akt    = $istRoh ? 'rohstoff_anfrage' : 'dienstleistung_anfrage';
    $ph     = $istRoh ? 'z. B. Magnesiumcitrat, 25 kg' : 'z. B. Laboranalyse, Etikettengestaltung';
    $meine  = array_filter($portalAnfragen, fn($a) => $a['typ'] === ($istRoh ? 'rohstoff' : 'dienstleistung')); ?>
  <h1 style="margin-bottom:4px"><?= h($titel) ?></h1>
  <div class="bx-panel">
    <p class="muted" style="margin-top:0">Sagen Sie uns kurz, worum es geht – wir melden uns mit einem Angebot.</p>
    <form method="post">
      <input type="hidden" name="aktion" value="<?= $akt ?>">
      <div class="bx-field"><label><?= $istRoh ? 'Rohstoff' : 'Betreff' ?></label><input type="text" name="betreff" required placeholder="<?= h($ph) ?>"></div>
      <?php if ($istRoh): ?>
      <div class="bx-grid">
        <div class="bx-field"><label>Gewünschte Menge</label><input type="number" name="wunsch_menge" min="0" step="0.001" placeholder="z. B. 25"></div>
        <div class="bx-field"><label>Einheit</label><select name="wunsch_einheit"><?php foreach (['kg','g','t','Stück','L'] as $e): ?><option value="<?= $e ?>"><?= $e ?></option><?php endforeach; ?></select></div>
      </div>
      <?php endif; ?>
      <div class="bx-field"><label>Details (optional)</label><textarea name="notiz" placeholder="Spezifikation, Termin …"></textarea></div>
      <button class="btn btn-primary" type="submit">Anfrage senden</button>
    </form>
  </div>
  <?php if ($meine): ?>
  <div class="bx-panel"><h2>Meine Anfragen</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nr.</th><th><?= $istRoh ? 'Rohstoff' : 'Betreff' ?></th><?php if ($istRoh): ?><th class="bx-num">Menge</th><?php endif; ?><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($meine as $a): ?>
        <tr><td><?= h($a['nummer']) ?></td><td><?= h($a['betreff'] ?: '–') ?></td>
          <?php if ($istRoh): ?><td class="bx-num"><?= $a['wunsch_menge'] ? rtrim(rtrim(number_format((float)$a['wunsch_menge'],3,',','.'),'0'),',').' '.h($a['wunsch_einheit'] ?: '') : '–' ?></td><?php endif; ?>
          <td><?= $pafBadge($a['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>

<?php elseif ($view === 'angebote'):
    $mg = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', '.'), '0'), ','); ?>
  <h1 style="margin-bottom:4px">Ihre Angebote</h1>
  <?php if (isset($_GET['abgelehnt'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Ihre Rückmeldung ist eingegangen – wir überarbeiten das Angebot.</div><?php endif; ?>
  <?php if (isset($_GET['geloescht'])): ?><div class="bx-panel badge-ok" style="padding:12px 16px">Angebot aus Ihrer Liste entfernt.</div><?php endif; ?>
  <?php if (!$angebote): ?><div class="bx-panel"><div class="muted">Aktuell liegen keine Angebote vor.</div></div><?php endif; ?>
  <?php foreach ($angebote as $a):
      $st = $staffelMap[$a['id']]; $inf = $angInfo[$a['id']];
      $offen = in_array($a['status'], ['offen','gesendet'], true);
      $einh = (int)$a['einheiten_pro_packung'];
      $formPl = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks','pulver'=>'Portionen','granulat'=>'Portionen','fluessig'=>'ml'];
      $mengeLbl = $einh . ' ' . ($formPl[$inf['form']] ?? 'Stück');
      $gPack = ($inf['istPulver'] && $inf['portionG'] > 0) ? $mg($einh * $inf['portionG']) . ' g pro Packung' : '';
  ?>
  <details class="bx-panel pt-ang" id="a<?= (int)$a['id'] ?>" style="scroll-margin-top:16px">
    <summary>
      <span style="font-size:var(--fs-md)"><span style="color:var(--gold)"><?= h($a['nummer']) ?></span> <strong><?= h($a['produkt_name'] ?: '–') ?></strong></span>
      <span class="bx-row" style="gap:10px;align-items:center">
        <?= $offen ? bx_badge('Angebot liegt vor – bitte wählen','info') : ($a['status']==='bestaetigt' ? bx_badge('bestätigt','ok') : bx_badge('abgelehnt','err')) ?>
        <a href="<?= $portalLink('angebot_pdf') ?>&aid=<?= (int)$a['id'] ?>" target="_blank" title="Angebot als PDF herunterladen" onclick="event.stopPropagation()" style="font-size:18px;line-height:1">&#8681;</a>
      </span>
    </summary>
    <div class="muted" style="margin-top:10px;font-size:13px">Eingegangen: <?= h(fmt_zeit($a['angelegt'])) ?> Uhr<?= $a['aktualisiert'] ? ' · Angebot vom ' . h(fmt_zeit($a['aktualisiert'])) . ' Uhr' : '' ?></div>
    <?php if ($inf['verp'] || $inf['deckel'] || $inf['etikett']): ?>
    <div class="muted" style="font-size:13px"><?= $inf['verp'] ? 'Verpackung: ' . h($inf['verp']) : '' ?><?= $inf['deckel'] ? ' · Deckel: ' . h($inf['deckel']) : '' ?><?= $inf['etikett'] ? ' · Etikett: ' . h($inf['etikett']) : '' ?></div>
    <?php endif; ?>
    <div class="muted" style="font-size:13px">Produktionszeit: <strong><?= 'ca. ' . rtrim(rtrim(number_format($inf['prodzeit'],1,',','.'),'0'),',') . ' Wochen' ?></strong> (unverbindlicher Schätzwert)</div>

    <?php if ($inf['zutaten']): ?>
    <details style="margin-top:10px">
      <summary style="cursor:pointer;color:var(--gruen)">Rezeptur ansehen</summary>
      <div class="bx-tablewrap" style="margin-top:8px"><table class="bx-table">
        <thead><tr><th>Zutat</th><th class="bx-num">Menge je Einheit</th></tr></thead>
        <tbody><?php foreach ($inf['zutaten'] as $z): ?><tr><td><?= h($z['bezeichnung']) ?></td><td class="bx-num"><?= $mg($z['menge_mg']) ?> mg</td></tr><?php endforeach; ?></tbody>
      </table></div>
      <?php if ($inf['nutr']): ?>
      <div class="bx-tablewrap" style="margin-top:8px"><table class="bx-table">
        <thead><tr><th>Nährstoff</th><th class="bx-num">je Einheit</th><th class="bx-num">% NRV</th></tr></thead>
        <tbody><?php foreach ($inf['nutr'] as $n): $betr = $n['einheit']==='µg' ? $mg($n['mg']*1000).' µg' : $mg($n['mg']).' mg'; $pct='–'; if($n['nrv']!==null&&$n['nrv']!==''){ $nrvMg=$n['einheit']==='µg'?(float)$n['nrv']/1000:(float)$n['nrv']; if($nrvMg>0) $pct=number_format($n['mg']/$nrvMg*100,0,',','.').' %'; } ?><tr><td><?= h($n['name']) ?></td><td class="bx-num"><?= $betr ?></td><td class="bx-num"><?= $pct ?></td></tr><?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </details>
    <?php endif; ?>

    <?php if ($offen && $inf['matrix']): ?>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
      <thead><tr><th>Menge / Verpackung</th><th class="bx-num">Anzahl Verpackungen</th><th>Preis</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($std_menge_ang as $bm): foreach (std_groessen_fuer($inf['form']) as $stk):
          $cell = $inf['matrix'][$stk][$bm] ?? null;
          $lbl = form_groessen_label($inf['form'], (float)$stk);
          $gp = ($inf['form'] === 'stick' && $inf['portionG'] > 0) ? $mg($stk * $inf['portionG']) . ' g pro Packung' : ''; ?>
        <tr>
          <td><?= h($lbl) ?><?= $gp ? '<div class="muted" style="font-size:12px">' . h($gp) . '</div>' : '' ?></td>
          <td class="bx-num"><?= number_format($bm, 0, ',', '.') ?></td>
          <?php if ($cell):
              $hCent = (int) round(vk_fuer_kunde($cell['vk'], $kid) * 100);
              $pCent = verpackung_cent_je_pack((int)$a['produkt_id'], $bm, $kid, (int)$cell['verp']);   // Behälter DIESER Zelle bepreisen
              $vk = ($hCent + $pCent) / 100; $netto = ($hCent + $pCent) * $bm / 100; $brutto = $netto * (1 + $ustP/100); ?>
            <td><strong><?= $eur($vk) ?> / Pkg.</strong><div class="muted" style="font-size:12px"><?= $pCent > 0 ? 'Herstellung ' . $eur($hCent/100) . ' + Verpackung ' . $eur($pCent/100) . ' · ' : '' ?>Gesamt <?= $eur($netto) ?> netto<?= $ustP > 0 ? ' · ' . $eur($brutto) . ' brutto (inkl. ' . $mg($ustP) . ' % MwSt)' : '' ?></div></td>
            <td class="bx-num"><form method="post" style="margin:0"><input type="hidden" name="aktion" value="zelle_annehmen"><input type="hidden" name="angebot_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="stueck" value="<?= $stk ?>"><input type="hidden" name="verpackung_id" value="<?= (int)$cell['verp'] ?>"><input type="hidden" name="bestellmenge" value="<?= $bm ?>"><button class="btn btn-primary btn-sm" type="submit">Diese Menge annehmen</button></form></td>
          <?php else: ?>
            <td><?= bx_badge('Nicht machbar','err') ?><div class="muted" style="font-size:12px">Diese Menge ist so nicht produzierbar</div></td>
            <td></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endforeach; ?>
      </tbody>
    </table></div>
    <?php elseif ($offen): ?>
    <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
      <thead><tr><th>Menge</th><th class="bx-num">Preis / Pkg.</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($st as $s): $vk = vk_fuer_kunde((float)$s['vk_stueck'], $kid); $netto = $vk * (int)$s['menge']; $brutto = $netto * (1 + $ustP/100); ?>
        <tr><td><?= number_format((int)$s['menge'],0,',','.') ?> × <?= h($mengeLbl) ?></td>
          <td><strong><?= $eur($vk) ?></strong><div class="muted" style="font-size:12px">Gesamt <?= $eur($netto) ?> netto<?= $ustP>0?' · '.$eur($brutto).' brutto':'' ?></div></td>
          <td class="bx-num"><form method="post" style="margin:0"><input type="hidden" name="aktion" value="bestaetigen"><input type="hidden" name="angebot_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="staffel" value="<?= (int)$s['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Diese Menge annehmen</button></form></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?>
    <div class="muted" style="margin-top:12px"><?= $a['status'] === 'bestaetigt' ? 'Angebot bestätigt – Details unter „Bestellungen".' : ($a['ablehnung_grund'] ? 'Abgelehnt: ' . h($a['ablehnung_grund']) : 'Abgelehnt.') ?></div>
    <?php endif; ?>

    <div class="bx-row" style="justify-content:flex-end;margin-top:12px;gap:8px">
      <?php if ($offen): ?>
      <details>
        <summary class="btn btn-ghost btn-sm" style="list-style:none">Ablehnen</summary>
        <form method="post" style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end;align-items:center;flex-wrap:wrap">
          <input type="hidden" name="aktion" value="angebot_ablehnen"><input type="hidden" name="angebot_id" value="<?= (int)$a['id'] ?>">
          <input type="text" name="grund" placeholder="Grund (optional)" style="max-width:280px">
          <button class="btn btn-danger btn-sm" type="submit">Angebot ablehnen</button>
        </form>
      </details>
      <?php else: ?>
      <form method="post" style="margin:0"><input type="hidden" name="aktion" value="angebot_loeschen"><input type="hidden" name="angebot_id" value="<?= (int)$a['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form>
      <?php endif; ?>
    </div>
  </details>
  <?php endforeach; ?>
  <script>(function(){
    var h = location.hash; if (h && /^#a\d+$/.test(h)) { var d = document.querySelector(h); if (d && d.tagName === 'DETAILS') { d.open = true; d.scrollIntoView(); } }
  })();</script>

<?php elseif ($view === 'bestellungen'): ?>
  <h1 style="margin-bottom:4px">Ihre Bestellungen</h1>
  <p class="muted" style="margin:0 0 16px">Klicken Sie auf eine Bestellung, um alle Schritte, Rechnung und Details zu sehen.</p>
  <?php if (!$auftraege): ?><div class="bx-panel"><div class="muted">Noch keine Bestellungen.</div></div><?php endif; ?>
  <?php foreach ($auftraege as $a): $cur = $aufStep($a['status']); $complete = $a['status'] === 'versendet'; ?>
  <a class="bx-panel bx-order-row" href="<?= $portalLink('bestellung') ?>&aid=<?= (int)$a['id'] ?>" style="display:block;text-decoration:none;color:inherit">
    <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
      <div><strong><?= h($a['nummer']) ?></strong> · <?= h($a['produkt_name'] ?: '–') ?> <span class="muted">· <?= (int)$a['menge'] ?> Packungen</span></div>
      <div class="bx-row" style="gap:10px;align-items:center"><?= $aufBadge($a['status']) ?><span class="muted" style="font-size:18px;line-height:1">&#8250;</span></div>
    </div>
    <ul class="bx-steps" style="margin-top:12px">
      <?php foreach ($AUFSTEPS as $i => $lbl):
          $cls = $i < $cur ? 'done' : ($i === $cur ? ($complete ? 'done' : 'current') : ''); ?>
        <li class="bx-step <?= $cls ?>">
          <span class="dot"><?= ($cls === 'done' || $cls === 'current') ? '&#10003;' : '' ?></span>
          <span class="lbl"><?= h($lbl) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </a>
  <?php endforeach; ?>

<?php elseif ($view === 'bestellung'):
    $aid = (int)($_GET['aid'] ?? 0);
    $a = null; foreach ($auftraege as $x) if ((int)$x['id'] === $aid) { $a = $x; break; }
    if (!$a): ?>
      <div class="bx-panel"><div class="muted">Bestellung nicht gefunden.</div>
        <div style="margin-top:12px"><a class="btn btn-ghost btn-sm" href="<?= $portalLink('bestellungen') ?>">Zurück zu den Bestellungen</a></div></div>
    <?php else:
      $cur = $aufStep($a['status']); $complete = $a['status'] === 'versendet';
      $re  = one("SELECT * FROM beleg WHERE auftrag_id=? AND typ='rechnung' ORDER BY id DESC LIMIT 1", [(int)$a['id']]);
      $pa  = one("SELECT * FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [(int)$a['id']]);
      $schritte = $pa ? all("SELECT * FROM produktion_schritt WHERE pa_id=? ORDER BY sort,id", [(int)$pa['id']]) : [];
      $vName = $a['verpackung_id'] ? scalar("SELECT name FROM item WHERE id=?", [(int)$a['verpackung_id']]) : '';
      // EINE Zeitleiste = unsere echten Produktionsschritte (interne Freigabe-Gates ausgeblendet),
      // eingerahmt von Bestätigt (Anfang) und Versendet (Ende). Datum aus echten Zeitstempeln.
      $versendet = ($a['status'] === 'versendet');
      $sichtbar = array_values(array_filter($schritte, fn($s) => stripos((string)$s['station'], 'Freigabe') === false));
      $track = [['label'=>'Bestätigt', 'done'=>true, 'current'=>false, 'date'=>$a['angelegt']]];
      $curSet = false;
      foreach ($sichtbar as $s) {
          $done  = ((int)$s['erledigt'] === 1);
          $isCur = (!$done && !$curSet && !$versendet);
          if ($isCur) $curSet = true;
          $track[] = ['label'=>$s['station'], 'done'=>$done, 'current'=>$isCur,
                      'date'=>($done && !empty($s['erledigt_at'])) ? $s['erledigt_at'] : null];
      }
      $track[] = ['label'=>'Versendet', 'done'=>$versendet, 'current'=>(!$versendet && !$curSet), 'date'=>$versendet ? $a['aktualisiert'] : null];
    ?>
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px;margin-bottom:4px">
    <h1 style="margin:0"><?= h($a['nummer']) ?></h1>
    <a class="btn btn-ghost btn-sm" href="<?= $portalLink('bestellungen') ?>">&#8592; Alle Bestellungen</a>
  </div>
  <p class="muted" style="margin:0 0 16px"><?= h($a['produkt_name'] ?: '–') ?></p>

  <!-- Status-Kacheln -->
  <div class="bx-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px">
    <div class="bx-panel" style="margin:0"><div class="muted">Status</div><div style="margin-top:6px"><?= $aufBadge($a['status']) ?></div></div>
    <div class="bx-panel" style="margin:0"><div class="muted">Menge</div><div style="margin-top:6px"><?= (int)$a['menge'] ?> Packungen<?php if ((int)$a['stueck']): ?> &middot; <?= (int)$a['stueck'] ?> je Packung<?php endif; ?></div></div>
    <div class="bx-panel" style="margin:0"><div class="muted">Gesamtbetrag</div><div style="margin-top:6px"><strong><?= $eur($re ? $re['brutto'] : $a['gesamt_netto']) ?></strong><?php if ($re): ?> <span class="muted">brutto</span><?php endif; ?></div></div>
    <div class="bx-panel" style="margin:0"><div class="muted">Zahlung</div><div style="margin-top:6px"><?= $re ? $reBadge($re['status']) : '<span class="muted">–</span>' ?></div></div>
  </div>

  <?php
  $etDok  = etikett_datei((int)$a['id']);
  $etSlot = (int) scalar("SELECT etikett_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]) > 0;
  if ($etSlot):
  ?>
  <div class="bx-panel" style="border-color:var(--gruen)">
    <h2 style="margin:0 0 8px;font-size:16px">Ihr Etikett-Design</h2>
    <?php if (isset($_GET['etikett'])): ?><div class="muted" style="margin-bottom:8px"><span class="bx-ok">Gespeichert.</span> Danke!</div><?php endif; ?>
    <?php if ($etDok): ?>
      <p style="margin-top:0">Hochgeladen: <a href="<?= $portalLink('etikett_datei') ?>&aid=<?= (int)$a['id'] ?>" target="_blank"><?= h($etDok['datei_orig'] ?: 'Etikett-Design') ?></a> <span class="muted">· <?= h(fmt_zeit($etDok['angelegt'], 'd.m.Y')) ?></span></p>
      <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:0"><input type="hidden" name="aktion" value="etikett_upload"><input type="hidden" name="auftrag_id" value="<?= (int)$a['id'] ?>"><input type="file" name="etikett" required accept="application/pdf,image/*"><button class="btn btn-ghost btn-sm" type="submit">Neues Design hochladen</button></form>
    <?php else: ?>
      <p style="margin-top:0">Für dieses Produkt brauchen wir Ihr <strong>Etikett-Design</strong>. Bitte laden Sie die Druckdatei (PDF oder Bild) hoch – erst dann können wir die Etiketten bestellen und in Produktion gehen.</p>
      <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:0"><input type="hidden" name="aktion" value="etikett_upload"><input type="hidden" name="auftrag_id" value="<?= (int)$a['id'] ?>"><input type="file" name="etikett" required accept="application/pdf,image/*"><button class="btn btn-primary btn-sm" type="submit">Etikett-Design hochladen</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Fortschritt mit Datum (horizontal, wie Ladebalken) -->
  <div class="bx-panel">
    <h2 style="margin:0 0 18px;font-size:16px">Fortschritt</h2>
    <div style="overflow-x:auto">
      <ul class="bx-htrack">
        <?php foreach ($track as $t): $cls = $t['done'] ? 'done' : ($t['current'] ? 'current' : ''); ?>
          <li class="bx-hstep <?= $cls ?>">
            <span class="dot"><?= ($t['done'] || $t['current']) ? '&#10003;' : '' ?></span>
            <span class="lbl"><?= h($t['label']) ?></span>
            <span class="date"><?= $t['date'] ? h(fmt_zeit($t['date'], 'd.m.Y')) : '' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <!-- Bestelldetails + Rechnung nebeneinander -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;align-items:start;margin-bottom:var(--sp-5)">
  <div class="bx-panel" style="margin:0">
    <h2 style="margin:0 0 14px;font-size:16px">Bestelldetails</h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <tbody>
        <tr><td class="muted" style="width:150px">Produkt</td><td><?= h($a['produkt_name'] ?: '–') ?></td></tr>
        <?php if ((int)$a['stueck']): ?><tr><td class="muted">Stück je Packung</td><td><?= (int)$a['stueck'] ?></td></tr><?php endif; ?>
        <tr><td class="muted">Anzahl Packungen</td><td><?= (int)$a['menge'] ?></td></tr>
        <?php if ($vName): ?><tr><td class="muted">Verpackung</td><td><?= h($vName) ?></td></tr><?php endif; ?>
        <?php if ((float)$a['vk_stueck']): ?><tr><td class="muted">Preis je Packung</td><td><?= $eur($a['vk_stueck']) ?></td></tr><?php endif; ?>
        <tr><td class="muted">Bestellt am</td><td><?= h(fmt_zeit($a['angelegt'], 'd.m.Y')) ?></td></tr>
      </tbody>
    </table></div>
    <div class="bx-row" style="justify-content:flex-end;margin-top:14px">
      <a class="btn btn-ghost btn-sm" target="_blank" href="<?= $portalLink('ppwr_pdf') ?>&aid=<?= (int)$a['id'] ?>">Verpackungs-Konformität (PDF)</a>
    </div>
  </div>

  <div class="bx-panel" style="margin:0">
    <h2 style="margin:0 0 14px;font-size:16px">Rechnung</h2>
    <?php if (!$re): ?>
      <div class="muted">Für diese Bestellung liegt noch keine Rechnung vor.</div>
    <?php else: ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <tbody>
          <tr><td class="muted" style="width:150px">Rechnungsnummer</td><td><?= h($re['nummer']) ?></td></tr>
          <tr><td class="muted">Rechnungsdatum</td><td><?= $re['datum'] ? h(fmt_zeit($re['datum'], 'd.m.Y')) : h(fmt_zeit($re['angelegt'], 'd.m.Y')) ?></td></tr>
          <tr><td class="muted">Netto</td><td class="bx-num"><?= $eur($re['netto']) ?></td></tr>
          <tr><td class="muted">USt (<?= rtrim(rtrim(number_format((float)$re['ust_prozent'],2,',','.'),'0'),',') ?> %)</td><td class="bx-num"><?= $eur($re['ust_betrag']) ?></td></tr>
          <tr><td class="muted">Brutto</td><td class="bx-num"><strong><?= $eur($re['brutto']) ?></strong></td></tr>
          <tr><td class="muted">Zahlungsstatus</td><td><?= $reBadge($re['status']) ?></td></tr>
        </tbody>
      </table></div>
    <?php endif; ?>
    <?php
      $angVorhanden = false;
      if (!empty($a['angebot_id'])) foreach ($angebote as $x) if ((int)$x['id'] === (int)$a['angebot_id']) { $angVorhanden = true; break; }
    ?>
    <div class="bx-row" style="justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:14px">
      <?php if ($angVorhanden): ?><a class="btn btn-ghost btn-sm" target="_blank" href="<?= $portalLink('angebot_pdf') ?>&aid=<?= (int)$a['angebot_id'] ?>">Angebot (AN)</a><?php endif; ?>
      <a class="btn btn-ghost btn-sm" target="_blank" href="<?= $portalLink('ab_pdf') ?>&aid=<?= (int)$a['id'] ?>">Auftragsbestätigung (AB)</a>
      <?php if ($re): ?><a class="btn btn-ghost btn-sm" target="_blank" href="<?= $portalLink('rechnung_pdf') ?>&aid=<?= (int)$a['id'] ?>">Rechnung (RE)</a><?php endif; ?>
    </div>
  </div>
  </div>

  <?php endif; ?>

<?php elseif ($view === 'rechnungen'): ?>
  <h1 style="margin-bottom:4px">Ihre Rechnungen</h1>
  <div class="bx-panel">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Nummer</th><th>Datum</th><th class="bx-num">Betrag</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$rechnungen): ?><tr><td colspan="4" class="muted">Noch keine Rechnungen.</td></tr><?php endif; ?>
      <?php foreach ($rechnungen as $r): ?>
        <tr><td><?= h($r['nummer']) ?></td><td><?= $r['datum']?h(date('d.m.Y',strtotime($r['datum']))):'' ?></td><td class="bx-num"><?= $eur($r['brutto']) ?></td><td><?= $reBadge($r['status']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>
  </main>
</div>
<script>
(function(){
  var add=document.getElementById('paddW');
  if(add) add.addEventListener('click',function(){
    var tr=document.createElement('tr'); tr.className='pwrow';
    tr.innerHTML='<td><input type="text" name="w_bez[]" placeholder="z. B. Magnesium"></td>'
      +'<td><input type="text" name="w_menge[]"></td>'
      +'<td><select name="w_einheit[]" style="width:80px"><option>mg</option><option>g</option><option>µg</option><option>IE</option><option>ml</option></select></td>'
      +'<td><button type="button" class="btn btn-ghost btn-sm">×</button></td>';
    tr.querySelector('button').addEventListener('click',function(){tr.remove();kapUpdate();});
    document.getElementById('pwrows').appendChild(tr);
  });

  // Live-Kapsel-Check im Anfrageformular – erklärt dem Kunden freundlich, ob die Menge je Kapsel passt.
  var KAPS = <?= json_encode($portalKapseln ?? [], JSON_UNESCAPED_UNICODE) ?>;
  var maxKap = KAPS.length ? +KAPS[KAPS.length-1].fuellmenge_mg : 0;
  var formSel = document.getElementById('pf_form');
  var box = document.getElementById('pf_kapselcheck');
  function nf(x){ return Math.round(x).toLocaleString('de-DE'); }
  function istKapsel(){ var v=formSel?formSel.value:''; return v==='kapsel'||v==='softgel'; }
  function summeMg(){
    var mg=0, mengen=document.querySelectorAll('input[name="w_menge[]"]'), einh=document.querySelectorAll('[name="w_einheit[]"]');
    mengen.forEach(function(inp,i){
      var val=parseFloat((inp.value||'').replace(',','.'))||0;
      var u=(einh[i]?einh[i].value:'mg').toLowerCase().trim();
      if(u==='g') mg+=val*1000;
      else if(u==='µg'||u==='mcg'||u==='ug') mg+=val/1000;
      else if(u==='mg') mg+=val;
      // IE / ml: kein Gewicht -> nicht in die mg-Summe
    });
    return mg;
  }
  function pick(mg){ for(var i=0;i<KAPS.length;i++){ if(+KAPS[i].fuellmenge_mg>=mg) return KAPS[i]; } return null; }
  window.kapUpdate=function(){
    if(!box) return;
    if(!istKapsel()){ box.style.display='none'; return; }
    var mg=summeMg();
    if(mg<=0){ box.style.display='none'; return; }
    box.style.display='';
    var kap=pick(mg);
    if(kap){
      var gross = KAPS.indexOf(kap) >= KAPS.length-2;   // eine der zwei größten Kapseln
      if(!gross){
        box.style.background='#f3faf6'; box.style.border='1px solid #c3e8d7'; box.style.color='#14532d';
        box.innerHTML='<strong>Passt gut.</strong> Ihre Summe je Kapsel ist '+nf(mg)+' mg – das passt bequem in eine Kapsel der <strong>'+kap.name+'</strong>. '
          +'Je nach Pulverdichte kann sich das noch leicht verschieben; das prüfen wir final für Sie.';
      } else {
        box.style.background='#fff8ec'; box.style.border='1px solid #e8d6a8'; box.style.color='#6b4e12';
        box.innerHTML='<strong>Passt – aber knapp.</strong> Ihre Summe je Kapsel ist '+nf(mg)+' mg. Das passt nur in eine <strong>sehr große Kapsel ('+kap.name+')</strong>, die viele Menschen schwerer schlucken. '
          +'Wenn Sie mögen, reduzieren Sie die Menge je Kapsel oder teilen die Einnahme auf <strong>2 Kapseln pro Tag</strong> auf (dann ca. '+nf(mg/2)+' mg je Kapsel). Das stimmen wir gern mit Ihnen ab.';
      }
    } else {
      var proTag=Math.max(2, Math.ceil(mg/maxKap));
      box.style.background='#fbeceb'; box.style.border='1px solid #e6c4c0'; box.style.color='#7a231b';
      box.innerHTML='<strong>Bitte kurz anpassen.</strong> Ihre Summe je Kapsel ist '+nf(mg)+' mg. In eine Kapsel passen aber nur etwa <strong>'+nf(maxKap)+' mg</strong> Pulver (größte Kapsel). '
        +'Bitte verringern Sie die Menge je Kapsel – oder verteilen Sie die Wirkstoffe auf <strong>'+proTag+' Kapseln pro Tag</strong> (dann rund '+nf(mg/proTag)+' mg je Kapsel). '
        +'Das ist ein Richtwert; die genaue Füllmenge hängt von der Dichte der Rohstoffe ab und wird final mit Ihnen abgestimmt.';
    }
  };
  if(formSel) formSel.addEventListener('change',kapUpdate);
  document.addEventListener('input',function(e){ if(e.target && e.target.name==='w_menge[]') kapUpdate(); });
  document.addEventListener('change',function(e){ if(e.target && e.target.name==='w_einheit[]') kapUpdate(); });
  kapUpdate();
})();
</script>
<?php portal_foot();
