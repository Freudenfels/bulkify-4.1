<?php
// Auftrag (Auftragsbestätigung) – Ansicht + Status
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/anfrage_ui.php';

$id = (int)($_GET['id'] ?? 0);

// Express-Bestellung (nur Admin): überspringt Einkaufsbedarf/-liste und bestellt direkt beim Lieferanten.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'express_bestellung') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $bid = auftrag_express_bestellung($id, (int)($_POST['lieferant_id'] ?? 0));
    if ($bid) { header('Location: ?p=bestellung&id=' . $bid . '&ok=1'); exit; }
    header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nichts zu bestellen – alles auf Lager oder der Lieferant bietet die fehlenden Rohstoffe nicht.')); exit;
}
// Express-Zukauf des FERTIGPRODUKTS (Bulk) – nur Admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'express_bulk') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $bid = auftrag_express_bulk_bestellung($id, (int)($_POST['lieferant_id'] ?? 0));
    if ($bid) { header('Location: ?p=bestellung&id=' . $bid . '&ok=1'); exit; }
    header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Kein Zukaufpreis für dieses Produkt bei dem Lieferanten hinterlegt.')); exit;
}

// Auftrag in ein Kontingent (Rahmen/Abruf) umwandeln – nur Admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'zu_kontingent') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $r = kontingent_aus_auftrag($id);
    if (!empty($r['ok'])) { header('Location: ?p=kontingente&neu=' . (int)$r['kontingent_id']); exit; }
    header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode($r['fehler'] ?? 'Umwandlung nicht möglich.')); exit;
}

// Produktionsauftrag nachtraeglich anlegen (Reparatur), wenn zum Auftrag noch keiner existiert.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'pa_anlegen') {
    $art = ($_POST['produktionsart'] ?? 'eigen') === 'fremd' ? 'fremd' : 'eigen';
    $paid = produktionsauftrag_aus_auftrag($id, $art);
    if ($paid) { header('Location: ?p=produktionsauftrag&id=' . $paid . '&angelegt=1'); exit; }
    header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Produktionsauftrag konnte nicht angelegt werden (kein Produkt am Auftrag?).')); exit;
}

// Energetisierung-Startdatum setzen (nur wenn der Kunde dafuer freigeschaltet ist). Status laeuft/abgeschlossen
// wird daraus abgeleitet (energ_status/energ_rest_tage) – kein manuelles Klicken.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'energ_start') {
    $kid = (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$id]);
    if (kunde_zeigt_energetisierung($kid)) {
        $d = trim((string)($_POST['energ_start'] ?? ''));
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
        q("UPDATE auftrag SET energ_start=? WHERE id=?", [$d, $id]);
    }
    header('Location: ?p=auftrag&id=' . $id . '&energok=1'); exit;
}

// Alt-Auftrag: einfach „bezahlt am" (+ optional Betrag) setzen – für alles aus dem alten System,
// das noch keine echte v4-Rechnung hat. Der Kunde sieht den Zahlstatus im Portal.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'bezahlt_setzen') {
    $d = trim((string)($_POST['bezahlt_am'] ?? '')); $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d');
    $betr = trim((string)($_POST['bezahlt_betrag'] ?? '')); $betr = $betr !== '' ? (float) str_replace(['.', ','], ['', '.'], $betr) : null;
    q("UPDATE auftrag SET bezahlt_am=?, bezahlt_betrag=? WHERE id=?", [$d, $betr, $id]);
    header('Location: ?p=auftrag&id=' . $id . '&bezahltok=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'bezahlt_reset') {
    q("UPDATE auftrag SET bezahlt_am=NULL, bezahlt_betrag=NULL WHERE id=?", [$id]);
    header('Location: ?p=auftrag&id=' . $id . '&bezahltreset=1'); exit;
}
// Admin-Override: Rohstoff/Bulk als angekommen markieren (für Alt-Aufträge / Zukauf ohne verknüpfte
// Charge). Setzt das Timeline-Signal; zusätzlich – falls vorhanden – die verknüpfte Bestellung.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'rohstoff_angekommen') {
    if (!has_role('admin') && !has_role('production') && !has_role('einkauf')) { header('Location: ?p=auftrag&id=' . $id); exit; }
    $set = ($_POST['set'] ?? '1') === '1';
    q("UPDATE auftrag SET rohstoff_angekommen_am=? WHERE id=?", [$set ? gmdate('Y-m-d H:i:s') : null, $id]);
    if ($set) q("UPDATE bestellung b JOIN bestellung_position bp ON bp.bestellung_id=b.id
                 SET b.angekommen_am=COALESCE(b.angekommen_am, CURDATE()) WHERE bp.auftrag_id=?", [$id]);
    header('Location: ?p=auftrag&id=' . $id . '&rohok=' . ($set ? '1' : '0')); exit;
}
// Alte Rechnung (PDF) am Auftrag hochladen -> als Dokument (typ='rechnung', kunde_sichtbar) -> Portal-Download.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'altrechnung_upload') {
    if (!empty($_FILES['dok']['name']) && (int)($_FILES['dok']['error'] ?? 1) === UPLOAD_ERR_OK) {
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        $orig = (string)$_FILES['dok']['name'];
        $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
        $fn   = 'altrechnung_' . $id . '_' . bin2hex(random_bytes(5)) . ($ext ? '.' . $ext : '');
        $datum = trim((string)($_POST['dok_datum'] ?? '')); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = null;
        if (move_uploaded_file($_FILES['dok']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,dok_datum,kunde_sichtbar,hochgeladen_von)
               VALUES ('auftrag',?, 'rechnung', ?,?,?,?,1,'team')",
              [$id, 'Rechnung (Altsystem)', $fn, $orig, $datum]);
            // Optional: Betrag per KI auslesen und den (fehlenden) Auftragspreis übernehmen.
            if (!empty($_POST['betrag_uebernehmen'])) {
                @set_time_limit(240);
                $af = one("SELECT menge, gesamt_netto, vk_stueck FROM auftrag WHERE id=?", [$id]);
                $ki = rechnung_import_ki(BX_UPLOADS . '/' . $fn);
                if (!empty($ki['ok']) && (float)($af['gesamt_netto'] ?? 0) <= 0 && (float)($ki['netto'] ?? 0) > 0) {
                    $netto = round((float)$ki['netto'], 2);
                    $menge = (int)($af['menge'] ?? 0);
                    $vk = $menge > 0 ? round($netto / $menge, 4) : (float)($af['vk_stueck'] ?? 0);
                    q("UPDATE auftrag SET gesamt_netto=?, vk_stueck=? WHERE id=?", [$netto, $vk, $id]);
                    header('Location: ?p=auftrag&id=' . $id . '&altre=1&betrag=' . rawurlencode(number_format($netto, 2, ',', '.'))); exit;
                }
                header('Location: ?p=auftrag&id=' . $id . '&altre=1&betragn=1'); exit;
            }
        }
    }
    header('Location: ?p=auftrag&id=' . $id . '&altre=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'altrechnung_del') {
    $did = (int)($_POST['dok_id'] ?? 0);
    $d = one("SELECT datei FROM dokument WHERE id=? AND objekt_typ='auftrag' AND objekt_id=? AND typ='rechnung'", [$did, $id]);
    if ($d) { @unlink(BX_UPLOADS . '/' . basename((string)$d['datei'])); q("DELETE FROM dokument WHERE id=?", [$did]); }
    header('Location: ?p=auftrag&id=' . $id . '&altredel=1'); exit;
}

// Auftragsbestaetigung loeschen und zurueck zur Anfrage (Angebot wird wieder offen) – nur Admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'auftrag_zurueck') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $r = auftrag_zurueck_und_loeschen($id);
    if (empty($r['ok'])) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode($r['fehler'] ?? 'Löschen nicht möglich.')); exit; }
    if (!empty($r['anfrage_id'])) { header('Location: ?p=portal_anfrage&id=' . (int)$r['anfrage_id'] . '&auftrag_geloescht=1'); exit; }
    if (!empty($r['angebot_id'])) { header('Location: ?p=angebot&id=' . (int)$r['angebot_id'] . '&auftrag_geloescht=1'); exit; }
    header('Location: ?p=auftraege&geloescht=1'); exit;
}

// Laboranalyse (Labortest / CoA) fuer GENAU diese Bestellung/Charge hochladen -> dokument(objekt_typ='auftrag').
// Mit kunde_sichtbar=1 erscheint sie im Kundenportal-Reiter „Labortest" bei dieser Bestellung.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'analyse_upload') {
    require_once BX_ROOT . '/core/schema.php';
    if (!empty($_FILES['dok']['name']) && (int)($_FILES['dok']['error'] ?? 1) === UPLOAD_ERR_OK) {
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        $orig = (string)$_FILES['dok']['name'];
        $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
        $fn   = 'analyse_' . $id . '_' . bin2hex(random_bytes(5)) . ($ext ? '.' . $ext : '');
        $datum = trim((string)($_POST['datum'] ?? '')); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = null;
        $charge = trim((string)($_POST['charge_nr'] ?? '')) ?: null;
        $befund = in_array($_POST['befund'] ?? '', ['bestanden','auffaellig','unklar'], true) ? $_POST['befund'] : 'unklar';
        if (move_uploaded_file($_FILES['dok']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,dok_datum,charge_nr,befund,kunde_sichtbar,hochgeladen_von)
               VALUES ('auftrag',?,'analyse',?,?,?,?,?,?,?,'team')",
              [$id, trim((string)($_POST['titel'] ?? '')) ?: null, $fn, $orig, $datum, $charge, $befund, isset($_POST['kunde_sichtbar']) ? 1 : 0]);
        }
    }
    header('Location: ?p=auftrag&id=' . $id . '&analyse=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'analyse_del' && ($did = (int)($_POST['dok_id'] ?? 0))) {
    $d = one("SELECT datei FROM dokument WHERE id=? AND objekt_typ='auftrag' AND objekt_id=? AND typ='analyse'", [$did, $id]);
    if ($d) { @unlink(BX_UPLOADS . '/' . basename((string)$d['datei'])); q("DELETE FROM dokument WHERE id=?", [$did]); }
    header('Location: ?p=auftrag&id=' . $id . '&analyse=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'analyse_toggle' && ($did = (int)($_POST['dok_id'] ?? 0))) {
    q("UPDATE dokument SET kunde_sichtbar = 1 - kunde_sichtbar WHERE id=? AND objekt_typ='auftrag' AND objekt_id=? AND typ='analyse'", [$did, $id]);
    header('Location: ?p=auftrag&id=' . $id . '&analyse=1'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    // Preis nachpflegen: VK je Packung + Menge editierbar, Netto = Menge × VK automatisch.
    $menge = max(0, (int)($_POST['menge'] ?? 0));
    $vk    = round(zahl_lesen((string)($_POST['vk_stueck'] ?? '0')), 4);
    $netto = round($menge * $vk, 2);
    $neuStatus = trim($_POST['status'] ?? 'offen');
    $altStatus = (string) scalar("SELECT status FROM auftrag WHERE id=?", [$id]);
    q("UPDATE auftrag SET status=?, menge=?, vk_stueck=?, gesamt_netto=? WHERE id=?",
      [$neuStatus, $menge, $vk, $netto, $id]);
    if ($neuStatus !== $altStatus) q("UPDATE auftrag SET status_datum=CURDATE() WHERE id=?", [$id]);   // Datum für Kundensicht
    // Verpackung (Behälter): „nur dieser Auftrag" (auftrag.verpackung_id) ODER „Standard fürs Produkt"
    // (zusätzlich produkt.verpackung_id). Wirkt auf Produktion/Einkauf/PIB; Kunde sieht es ohne Bestätigung.
    if (array_key_exists('verpackung_id', $_POST)) {
        $verpId = $_POST['verpackung_id'] !== '' ? (int)$_POST['verpackung_id'] : null;
        q("UPDATE auftrag SET verpackung_id=? WHERE id=?", [$verpId, $id]);
        if (($_POST['verp_scope'] ?? '') === 'standard' && $verpId) {
            $pidA = (int) scalar("SELECT produkt_id FROM auftrag WHERE id=?", [$id]);
            if ($pidA) q("UPDATE produkt SET verpackung_id=? WHERE id=?", [$verpId, $pidA]);
        }
    }
    // Kapselgröße = Rezeptur-Eigenschaft -> als Standard an der Rezeptur dieses Produkts setzen
    // (gilt für alle Aufträge dieses Produkts). Nur wenn gesendet.
    if (array_key_exists('kapselgroesse_id', $_POST)) {
        $ridA = (int) scalar("SELECT p.rezeptur_id FROM auftrag a JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$id]);
        if ($ridA) { $kapsId = $_POST['kapselgroesse_id'] !== '' ? (int)$_POST['kapselgroesse_id'] : null;
            q("UPDATE rezeptur SET kapselgroesse_id=? WHERE id=?", [$kapsId, $ridA]); }
    }
    // Auftrag storniert -> offene Rechnung(en) automatisch per Gutschrift stornieren
    $stn = 0;
    if ($neuStatus === 'storniert' && $altStatus !== 'storniert') {
        $akteur = (function_exists('current_user') && ($u = current_user())) ? $u['name'] : 'team';
        $stn = auftrag_rechnungen_stornieren($id, 'Auftrag ' . ((string) scalar("SELECT nummer FROM auftrag WHERE id=?", [$id])) . ' storniert', $akteur);
    }
    header('Location: ?p=auftrag&id=' . $id . '&gespeichert=1' . ($stn ? '&storno=' . $stn : '')); exit;
}

$a = $id ? one("SELECT a.*, k.firma AS kunde_firma, p.name AS produkt_name, ang.nummer AS angebot_nr
                FROM auftrag a
                LEFT JOIN kunden k ON k.id=a.kunde_id
                LEFT JOIN produkt p ON p.id=a.produkt_id
                LEFT JOIN angebot ang ON ang.id=a.angebot_id
                WHERE a.id=?", [$id]) : null;
if (!$a) { render_header('auftraege','Auftrag'); bx_head('Auftrag nicht gefunden','', bx_btn('Zurück','?p=auftraege','ghost')); render_footer(); exit; }

$rechnung = one("SELECT id, nummer, brutto, status FROM beleg WHERE auftrag_id=? AND typ='rechnung' LIMIT 1", [$id]);
$rechnungZs = $rechnung ? beleg_zahlstatus($rechnung) : null;   // abgeleiteter Zahlstatus (bezahlt/teilbezahlt/offen + Rest)
// Hochgeladene Alt-Rechnungen (Altsystem) zu diesem Auftrag.
$altRechnungen = all("SELECT id, datei, datei_orig, dok_datum FROM dokument WHERE objekt_typ='auftrag' AND objekt_id=? AND typ='rechnung' ORDER BY id DESC", [$id]);
$rezeptur = !empty($a['produkt_id'])
    ? one("SELECT r.id, r.nummer, r.name, r.darreichungsform, r.kapselgroesse_id FROM produkt p JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [(int)$a['produkt_id']])
    : null;
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$statusBadge = match ($a['status']) {
    'offen'         => bx_badge('offen','info'),
    'in_produktion' => bx_badge('in Produktion','warn'),
    'erledigt'      => bx_badge('versandbereit','info'),
    'versendet'     => bx_badge('versendet','ok'),
    default         => bx_badge(status_text($a['status'])),
};

// Produktion + Beschaffung zu diesem Auftrag
$pa = one("SELECT * FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$id]);
$istFremd = $pa && ($pa['produktionsart'] ?? '') === 'fremd';
$paStatusBadge = $pa ? match ($pa['status']) {
    'offen'=>bx_badge('offen','info'),'laufend'=>bx_badge('läuft','warn'),'erledigt'=>bx_badge('fertig','ok'),
    default=>bx_badge(status_text((string)$pa['status'])),
} : '';
$ber = $pa ? produktion_bereitschaft((int)$pa['id']) : ['status'=>''];
$einhProP  = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
if ($einhProP <= 0) $einhProP = (int)($a['stueck'] ?? 0);   // Fallback: Stück je Packung liegt am Auftrag (v3-Import)
$gesamtStk = $einhProP > 0 ? (int)$a['menge'] * $einhProP : 0;
// Namens-Snapshot zuerst: der zur Auftragszeit festgehaltene Name gilt, damit eine spätere Produkt-Umbenennung den Auftrag nicht ändert.
$produktName = (string)($a['produkt_bezeichnung'] ?? '') ?: (string)($a['produkt_name'] ?? '');
// Erstauftrag vs. Nachbestellung (neue Rezeptur / neues Produkt / Nachbestellung).
$artKey = auftrag_art((int)$a['id']);
[$artLabel, $artStil, $artHint] = auftrag_art_meta($artKey);
// Etikett-Status je Auftrag: freigegeben (Kunde) / hinterlegt (Datei da) / fehlt.
$etikettFrei = (int)($a['etikett_freigegeben'] ?? 0) === 1;
$etikettBadge = $etikettFrei
    ? '<span title="Kunde hat das Etikett freigegeben">' . bx_badge('freigegeben', 'ok') . '</span>'
    : (etikett_vorhanden((int)$a['id'])
        ? '<span title="Etikett-Datei hinterlegt, Kundenfreigabe fehlt noch">' . bx_badge('nicht freigegeben', 'warn') . '</span>'
        : '<span title="Kunde hat noch kein Etikett hinterlegt">' . bx_badge('fehlt', 'err') . '</span>');
$groesseLbl = produktion_groesse_label((int)$a['produkt_id']);
// Bestellungen (bei welchem Lieferanten, welcher Status) – verknüpft über die Position.
$best = all("SELECT DISTINCT b.id, b.nummer, b.status, b.bestaetigt, b.angekommen_am, l.firma AS lieferant
             FROM bestellung b JOIN bestellung_position bp ON bp.bestellung_id=b.id
             LEFT JOIN lieferanten l ON l.id=b.lieferant_id
             WHERE bp.auftrag_id=? ORDER BY b.angelegt DESC", [$id]);
// Direkt gebuchte Wareneingänge (Chargen) zu diesem Auftrag – z. B. extern bestellte, direkt
// eingebuchte Fremdproduktions-Bulkware ohne System-Bestellung. Zeigt „angekommen" auch ohne Bestellung.
$wareneingaenge = all("SELECT c.charge_nr, c.menge_verfuegbar, c.status, c.wareneingang, c.mhd, i.name AS item_name, i.einheit
                       FROM charge c LEFT JOIN item i ON i.id=c.item_id
                       WHERE c.auftrag_id=? ORDER BY c.angelegt DESC", [$id]);
$bStatus = function($b) {
    if (!empty($b['angekommen_am']))        return bx_badge('angekommen','ok');
    if ((int)($b['bestaetigt'] ?? 0) === 1) return bx_badge('bestätigt','warn');
    return match ((string)$b['status']) {
        'offen'=>bx_badge('offen','info'),'bestellt'=>bx_badge('bestellt','warn'),'geliefert'=>bx_badge('geliefert','ok'),
        default=>bx_badge((string)$b['status']),
    };
};

// EK-Preise je benötigtem Rohstoff (nur Admin) – „wo kann ich bestellen" + Express-Bestellung.
$istAdmin = function_exists('has_role') && has_role('admin');
$ekBedarf = []; $ekLieferanten = [];
$zukaufPreise = []; $zukaufLief = []; $prodRezId = 0;
if ($istAdmin && $pa) {
    foreach (produktion_materialbedarf((int)$pa['id']) as $bd) {
        $angebote = all("SELECT lp.lieferant_id, lp.menge_ab, lp.preis, lp.waehrung, COALESCE(l.firma, lp.lieferant_name, '') AS firma
                         FROM lieferant_preis lp LEFT JOIN lieferanten l ON l.id=lp.lieferant_id
                         WHERE lp.item_id=? ORDER BY lp.preis", [(int)$bd['item_id']]);
        foreach ($angebote as $ao) if (!empty($ao['lieferant_id'])) $ekLieferanten[(int)$ao['lieferant_id']] = (string)$ao['firma'];
        $ekBedarf[] = $bd + ['angebote' => $angebote];
    }
}
// Fertigprodukt-Zukauf (Bulk): Zukaufpreise + Anfrage-Möglichkeit für das Produkt des Auftrags.
if ($istAdmin && !empty($a['produkt_id'])) {
    $prodRezId = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
    $zukaufPreise = all("SELECT z.lieferant_id, z.menge_ab, z.preis, z.waehrung, z.einheit, z.groesse, z.incoterm, z.versandart,
                                COALESCE(l.firma, z.lieferant_name, '') AS firma
                         FROM produkt_lieferant_preis z LEFT JOIN lieferanten l ON l.id=z.lieferant_id
                         WHERE z.produkt_id=? ORDER BY z.preis, z.menge_ab", [(int)$a['produkt_id']]);
    foreach ($zukaufPreise as $z) if (!empty($z['lieferant_id'])) $zukaufLief[(int)$z['lieferant_id']] = (string)$z['firma'];
}
$anfrageLieferanten = $istAdmin ? all("SELECT id, firma, land FROM lieferanten WHERE gesperrt=0 AND COALESCE(keine_anfragen,0)=0 ORDER BY firma") : [];

render_header('auftraege', $a['nummer']);
bx_head($a['nummer'], 'Auftragsbestätigung',
    pdf_btn('?p=auftrag_pdf&id=' . (int)$a['id'], 'PDF / Drucken', false, 'Auftragsbestätigung als PDF öffnen/drucken')
    . ' ' . bx_btn('Zurück zur Liste', '?p=auftraege', 'ghost'));
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['importiert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Auftrag aus Angebot importiert – Rezeptur/Produkt angelegt (falls neu), Angebot/Rechnung angehängt. Bitte Werte gegenprüfen.</div>';

// Kacheln bleiben in EINER Reihe und werden bei Enge KLEINER (kein Umbruch, kein Scroll).
echo '<style>.bx-cards{flex-wrap:nowrap;gap:8px}'
   . '.bx-cards .bx-card{flex:1 1 0;min-width:0;padding:10px 12px;overflow:hidden}'
   . '.bx-cards .k{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
   . '.bx-cards .v{font-size:15px;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
   . '.bx-card-status .v{font-weight:600;font-size:16px}'
   . 'details.bx-sek>summary{cursor:pointer;color:var(--gruen);font-size:13px;padding:4px 0}</style>';
// Status-Ampel: weiß = Start (offen) -> rot -> gelb -> grün = fertig (versendet). Storniert = grau.
[$stBg, $stFg] = match ((string)$a['status']) {
    'offen'         => ['#ffffff', '#111827'],   // Start
    'in_produktion' => ['#dc2626', '#ffffff'],   // rot
    'erledigt'      => ['#f59e0b', '#111827'],   // gelb (versandbereit)
    'versendet'     => ['#16a34a', '#ffffff'],   // grün (fertig)
    'storniert'     => ['#6b7280', '#ffffff'],   // grau
    default         => ['#ffffff', '#111827'],
};
$stText = match ((string)$a['status']) {
    'offen'=>'offen','in_produktion'=>'in Produktion','erledigt'=>'versandbereit','versendet'=>'versendet','storniert'=>'storniert', default=>(string)$a['status']
};
echo '<div class="bx-cards">';
echo '<div class="bx-card bx-card-status" title="Status" style="background:' . $stBg . ';color:' . $stFg . ';border:1px solid rgba(0,0,0,.15)"><div class="v" style="color:' . $stFg . '">' . h($stText) . '</div></div>';
echo '<div class="bx-card"><div class="k">Menge (Packungen)</div><div class="v">' . (int)$a['menge'] . '</div></div>';
if ($einhProP > 0) echo '<div class="bx-card"><div class="k">Stück je Packung</div><div class="v">' . number_format($einhProP, 0, ',', '.') . '</div></div>';
if ($gesamtStk > 0) echo '<div class="bx-card"><div class="k">Gesamtstückzahl</div><div class="v">' . number_format($gesamtStk, 0, ',', '.') . '</div></div>';
if ($groesseLbl !== '') echo '<div class="bx-card"><div class="k">Kapsel/Tablette</div><div class="v">' . h($groesseLbl) . '</div></div>';
echo '<div class="bx-card"><div class="k">Herstellung</div><div class="v">' . ($istFremd ? bx_badge('Zukauf','info') : bx_badge('Eigenproduktion','ok')) . '</div></div>';
echo '<div class="bx-card"><div class="k">Etikett</div><div class="v">' . $etikettBadge . '</div></div>';
echo '<div class="bx-card"><div class="k">VK / Stück</div><div class="v">' . $eur($a['vk_stueck']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Netto gesamt</div><div class="v">' . $eur($a['gesamt_netto']) . '</div></div>';
if (!empty($a['angelegt'])) echo '<div class="bx-card"><div class="k">Erstellt</div><div class="v">' . h(fmt_zeit($a['angelegt'], 'd.m.Y H:i')) . '</div></div>';
echo '</div>';
?>
<?php
// Admin-Override „Rohstoff/Bulk angekommen" – damit die Kunden-Statusleiste auch bei Alt-Aufträgen /
// Zukauf ohne verknüpfte Charge auf „Rohstoff angekommen" springt.
if (isset($_GET['rohok'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . ($_GET['rohok']==='1' ? 'Als „Rohstoff angekommen" markiert – der Kunde sieht es sofort.' : 'Markierung zurückgesetzt.') . '</div>';
if (has_role('admin') || has_role('production') || has_role('einkauf')):
    $_ph = kunde_auftrag_phase($a); $_angDa = $_ph['dates'][2] ?? null; $_override = !empty($a['rohstoff_angekommen_am']);
?>
<div class="bx-panel" style="padding:10px 14px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:10px;align-items:center">
  <span class="muted" style="font-size:13px">Kunden-Status „Rohstoff angekommen":</span>
  <?php if ($_angDa): ?>
    <?= bx_badge('angekommen · ' . h(fmt_zeit((string)$_angDa, 'd.m.Y')), 'ok') ?>
    <?php if ($_override): ?>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="rohstoff_angekommen"><input type="hidden" name="set" value="0">
      <button class="btn btn-ghost btn-sm" type="submit">Markierung zurücknehmen</button></form>
    <?php endif; ?>
  <?php else: ?>
    <?= bx_badge('noch nicht', 'warn') ?>
    <form method="post" style="margin:0" title="Nutze das, wenn Ware (Zukauf/Bulk) da ist, der Kunde es aber noch nicht sieht.">
      <input type="hidden" name="aktion" value="rohstoff_angekommen"><input type="hidden" name="set" value="1">
      <button class="btn btn-primary btn-sm" type="submit">Rohstoff/Bulk als angekommen markieren</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="settabs" id="auftabs" style="margin-bottom:16px">
  <a href="#" class="on" data-tab="details">Details</a>
  <a href="#" data-tab="produktion">Produktion</a>
  <a href="#" data-tab="preise">Preise &amp; Rechnung</a>
  <a href="#" data-tab="dokumente">Dokumente</a>
</div>

<div class="bx-panel" data-panel="details">
  <h2>Details</h2>
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= kunde_link($a['kunde_id'] ?? null, $a['kunde_firma']) ?></div></div>
    <div><div class="k muted">Produkt</div><div><?php if (!empty($a['produkt_id']) && $produktName): ?><a href="?p=produkt&id=<?= (int)$a['produkt_id'] ?>"><?= h($produktName) ?></a><?php elseif ($produktName): ?><?= h($produktName) ?> <span class="muted" style="font-size:12px">(aus v3)</span><?php else: ?>–<?php endif; ?><?php if ($artKey !== 'none'): ?> <span title="<?= h($artHint) ?>"><?= bx_badge($artLabel, $artStil) ?></span><?php endif; ?></div></div>
    <div><div class="k muted">Rezeptur</div><div><?php if ($rezeptur): ?><a href="?p=rezeptur_detail&id=<?= (int)$rezeptur['id'] ?>"><?= h($rezeptur['nummer']) ?></a><?= $rezeptur['name'] ? ' · ' . h($rezeptur['name']) : '' ?><?php else: ?>–<?php endif; ?></div></div>
    <div><div class="k muted">Aus Angebot</div><div><?php if ($a['angebot_id']): ?><a href="?p=angebot&id=<?= (int)$a['angebot_id'] ?>"><?= h($a['angebot_nr']) ?></a><?php else: ?>–<?php endif; ?></div></div>
    <?php if (!empty($a['kontingent_id'])): ?><div><div class="k muted">Herkunft</div><div><a href="?p=kontingente" title="Abruf aus einem Jahresabnahmevertrag"><?= bx_badge('aus Jahresvertrag','info') ?></a></div></div><?php endif; ?>
    <div><div class="k muted">Rechnung</div><div><?php if ($rechnung): ?><a href="/buchhaltung/?p=rechnung&id=<?= (int)$rechnung['id'] ?>"><?= h($rechnung['nummer']) ?></a> · <?= $eur($rechnung['brutto']) ?> · <?php
        $rst = $rechnungZs['status'] ?? $rechnung['status'];
        echo match ($rst) { 'bezahlt'=>bx_badge('bezahlt','ok'), 'teilbezahlt'=>bx_badge('teilbezahlt','info'), 'storniert'=>bx_badge('storniert','err'), default=>bx_badge('offen','warn') };
        if ($rst === 'teilbezahlt') echo ' <span class="muted" style="font-size:12px">offen ' . $eur($rechnungZs['rest']) . '</span>';
      ?> · <a href="/buchhaltung/?p=rechnung&id=<?= (int)$rechnung['id'] ?>" style="font-size:12px">Zahlung erfassen</a><?php else: ?><a class="btn btn-primary btn-sm" href="/buchhaltung/?p=rechnung_neu&auftrag=<?= (int)$id ?>">Rechnung erstellen</a><?php endif; ?></div></div>
  </div>
</div>

<?php $track = kunde_auftrag_track($a); ?>
<div class="bx-panel" data-panel="produktion">
  <h2 style="margin:0 0 18px;font-size:16px">Fortschritt</h2>
  <div style="overflow-x:auto">
    <ul class="bx-htrack">
      <?php foreach ($track as $t): $cls = $t['done'] ? 'done' : ($t['current'] ? 'current' : ''); ?>
        <li class="bx-hstep <?= $cls ?>">
          <span class="dot"><?= auftrag_track_icon($cls) ?></span>
          <span class="lbl"><?= h($t['label']) ?></span>
          <span class="date"><?= $t['date'] ? h(fmt_zeit($t['date'], 'd.m.Y')) : h($t['sub'] ?? '') ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<div class="bx-panel" data-panel="produktion">
  <h2>Produktion &amp; Beschaffung</h2>
  <div class="bx-grid">
    <div><div class="k muted">Herstellung</div><div>
      <?= $istFremd ? bx_badge('Fremdproduktion · fertige Bulkware zukaufen','info') : bx_badge('Eigenproduktion · aus Rohstoffen','ok') ?>
    </div></div>
    <?php if ($pa): ?>
    <div><div class="k muted">Produktionsauftrag</div><div><a href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>"><?= h($pa['nummer']) ?></a> · <?= $paStatusBadge ?></div></div>
    <div><div class="k muted">Material</div><div>
      <?= bereitschaft_badge($ber['status'] ?? '') ?>
      <?php if (($ber['status'] ?? '') === 'wartet'): ?> <a href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>" style="font-size:12px">was fehlt?</a><?php endif; ?>
    </div></div>
    <?php else: $aktivPA = in_array((string)$a['status'], ['offen', 'in_produktion'], true); ?>
    <div><div class="k muted">Produktionsauftrag</div>
      <?php if ($aktivPA): ?>
      <div class="muted" style="margin-bottom:6px">noch keiner angelegt</div>
      <form method="post" class="bx-row" style="gap:6px;align-items:center;margin:0;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="pa_anlegen">
        <select name="produktionsart" style="max-width:190px">
          <option value="eigen">Eigenproduktion</option>
          <option value="fremd">Fremdproduktion (zukaufen)</option>
        </select>
        <button class="btn btn-primary btn-sm" type="submit">Produktionsauftrag anlegen</button>
      </form>
      <div class="muted" style="font-size:12px;margin-top:4px">Danach erscheint der Materialbedarf (Rohstoffe) und der Auftrag ist produzierbar.</div>
      <?php else: ?>
      <div class="muted">Kein Produktionsauftrag – der Auftrag ist bereits <strong><?= h($stText) ?></strong> (z. B. Altauftrag ohne eigenen Produktionsauftrag). Nichts mehr anzulegen.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <h3 style="margin:18px 0 6px;font-size:14px;font-weight:600">Bestellungen zu diesem Auftrag</h3>
  <?php if (!$best): ?>
    <div class="muted"><?= $istFremd ? 'Noch keine Bestellung erfasst – fertige Bulkware ist noch nicht bestellt.' : 'Noch keine Bestellung erfasst – Rohstoffe sind noch offen.' ?>
      <?php if ($pa): ?> <a href="?p=bedarf" style="font-size:12px">zum Einkaufsbedarf</a><?php endif; ?></div>
  <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Bestellung</th><th>Lieferant</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($best as $b): ?>
        <tr><td><a href="?p=bestellung&id=<?= (int)$b['id'] ?>"><?= h($b['nummer']) ?></a></td>
            <td><?= $b['lieferant'] ? h($b['lieferant']) : '<span class="muted">–</span>' ?></td>
            <td><?= $bStatus($b) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <?php if ($wareneingaenge): ?>
  <h3 style="margin:18px 0 6px;font-size:14px;font-weight:600">Wareneingänge zu diesem Auftrag</h3>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th class="bx-num">Menge</th><th>MHD</th><th>Eingang</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($wareneingaenge as $w): ?>
        <tr>
          <td><?= h($w['charge_nr'] ?: '–') ?></td>
          <td><?= h($w['item_name'] ?: '–') ?></td>
          <td class="bx-num"><?= rtrim(rtrim(number_format((float)$w['menge_verfuegbar'],3,',','.'),'0'),',') ?> <?= h($w['einheit'] ?: '') ?></td>
          <td><?= $w['mhd'] ? h(date('d.m.Y', strtotime((string)$w['mhd']))) : '<span class="muted">–</span>' ?></td>
          <td><?= $w['wareneingang'] ? h(date('d.m.Y', strtotime((string)$w['wareneingang']))) : '<span class="muted">–</span>' ?></td>
          <td><?= match ((string)$w['status']) { 'frei'=>bx_badge('angekommen · frei','ok'), 'quarantaene'=>bx_badge('angekommen · Quarantäne','warn'), 'gesperrt'=>bx_badge('gesperrt','err'), default=>bx_badge((string)$w['status']) } ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php // Energetisierung – nur fuer freigeschaltete Kunden (z. B. Annapurna/Pure Health). Startdatum setzen;
      // Status laeuft/abgeschlossen + Fertig-Datum werden daraus abgeleitet. Der Kunde sieht es im Portal.
if (kunde_zeigt_energetisierung((int)($a['kunde_id'] ?? 0))):
    $eStart = (string)($a['energ_start'] ?? '');
    $eStat  = energ_status($eStart); $eRest = energ_rest_tage($eStart); $eFertig = energ_fertig_am($eStart); ?>
<div class="bx-panel" data-panel="produktion">
  <h2 style="margin-top:0">Energetisierung</h2>
  <?php if (isset($_GET['energok'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Gespeichert.</div><?php endif; ?>
  <?php if ($eStat === 'laeuft'): ?>
    <div style="margin-bottom:10px"><?= bx_badge('läuft', 'warn') ?> <span class="muted">noch <?= max(0, (int)$eRest) ?> Tage · fertig am <?= h(date('d.m.Y', strtotime((string)$eFertig))) ?></span></div>
  <?php elseif ($eStat === 'abgeschlossen'): ?>
    <div style="margin-bottom:10px"><?= bx_badge('abgeschlossen', 'ok') ?> <span class="muted">seit <?= h(date('d.m.Y', strtotime((string)$eFertig))) ?></span></div>
  <?php else: ?>
    <div class="muted" style="margin-bottom:10px">Noch kein Startdatum gesetzt. Nach dem Setzen läuft die Energetisierung <?= energ_tage() ?> Tage, dann „abgeschlossen".</div>
  <?php endif; ?>
  <?php if ($eStat === 'abgeschlossen'): ?><details class="bx-sek"><summary>Startdatum korrigieren</summary><?php endif; ?>
  <div class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin:<?= $eStat === 'abgeschlossen' ? '10px 0 0' : '0' ?>">
    <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;margin:0">
      <input type="hidden" name="aktion" value="energ_start">
      <div class="bx-field" style="margin:0"><label>Startdatum</label><input type="date" name="energ_start" value="<?= h($eStart) ?>"></div>
      <button class="btn btn-primary btn-sm" type="submit">Speichern</button>
    </form>
    <?php if ($eStart !== ''): ?>
    <form method="post" style="margin:0" onsubmit="return confirm('Energetisierungs-Startdatum löschen?');">
      <input type="hidden" name="aktion" value="energ_start"><input type="hidden" name="energ_start" value="">
      <button class="btn btn-ghost btn-sm" type="submit">Startdatum löschen</button>
    </form>
    <?php endif; ?>
  </div>
  <?php if ($eStat === 'abgeschlossen'): ?></details><?php endif; ?>
  <div class="muted" style="font-size:12px;margin-top:8px">Der Kunde sieht im Portal „Energetisierung läuft · noch X Tage" bzw. „abgeschlossen". Dauer global einstellbar (<?= energ_tage() ?> Tage).</div>
</div>
<?php endif; ?>

<?php // Externer Labortest (Drittlabor) – nur fuer freigeschaltete Kunden. Status kommt automatisch aus dem
      // freigegebenen Laborbericht (dokument typ='analyse', kunde_sichtbar=1) zum Auftrag/Produkt. Kein manuelles Setzen.
if (kunde_will_labortest((int)($a['kunde_id'] ?? 0))):
    $lt = auftrag_labortest_status((int)$a['id'], isset($a['produkt_id']) ? (int)$a['produkt_id'] : null); ?>
<div class="bx-panel" data-panel="produktion">
  <h2 style="margin-top:0">Externer Labortest</h2>
  <?php if ($lt['status'] === 'abgeschlossen'): ?>
    <div style="margin-bottom:10px"><?= bx_badge('abgeschlossen', 'ok') ?> <span class="muted"><?= $lt['datum'] ? 'Bericht vom ' . h(date('d.m.Y', strtotime((string)$lt['datum']))) : 'Bericht liegt vor' ?></span>
      <?php if (!empty($lt['dok_id'])): ?> · <a href="?p=dokument&id=<?= (int)$lt['dok_id'] ?>" target="_blank">Bericht ansehen</a><?php endif; ?></div>
  <?php else: ?>
    <div style="margin-bottom:10px"><?= bx_badge('läuft', 'warn') ?> <span class="muted">Probe beim Drittlabor – wird automatisch „abgeschlossen", sobald ein freigegebener Laborbericht vorliegt.</span></div>
  <?php endif; ?>
  <div class="muted" style="font-size:12px">Laborbericht hochladen &amp; für den Kunden freigeben unter <a href="?p=laboranalysen">Labortests</a> (oder direkt an diesem Auftrag). Der Kunde sieht den Punkt „Externer Labortest" im Bestell-Verlauf.</div>
</div>
<?php endif; ?>

<?php // Zahlung / Alt-Rechnung – für alles aus dem alten System (noch keine echte v4-Rechnung).
      $hatV4Rechnung = (bool)$rechnung; ?>
<div class="bx-panel" data-panel="preise">
  <h2 style="margin-top:0">Zahlung / Alt-Rechnung <span class="muted" style="font-weight:normal;font-size:13px">· Altsystem</span></h2>
  <?php if (isset($_GET['bezahltok'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Gespeichert.</div><?php endif; ?>
  <?php if (isset($_GET['bezahltreset'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Zurückgesetzt.</div><?php endif; ?>
  <?php if (isset($_GET['altre'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Rechnung hochgeladen.<?php if (isset($_GET['betrag'])): ?> Betrag <strong><?= h((string)$_GET['betrag']) ?> €</strong> aus der Rechnung übernommen.<?php elseif (isset($_GET['betragn'])): ?> <span class="muted">Kein Betrag erkannt oder Preis bereits gesetzt – Preis ggf. unten von Hand eintragen.</span><?php endif; ?></div><?php endif; ?>
  <?php if (isset($_GET['altredel'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Rechnung gelöscht.</div><?php endif; ?>
  <?php if ($hatV4Rechnung): ?>
    <p class="muted" style="margin-top:0">Für diesen Auftrag gibt es bereits eine v4-Rechnung (<a href="/buchhaltung/?p=rechnung&id=<?= (int)$rechnung['id'] ?>"><?= h($rechnung['nummer']) ?></a>) – Zahlungen bitte dort erfassen. Das manuelle „bezahlt am" unten ist nur für Alt-Aufträge ohne echte Rechnung gedacht.</p>
  <?php endif; ?>
  <?php if (!empty($a['bezahlt_am'])): ?>
    <div style="margin-bottom:10px"><?= bx_badge('bezahlt','ok') ?> <span class="muted">am <?= h(date('d.m.Y', strtotime((string)$a['bezahlt_am']))) ?><?= ($a['bezahlt_betrag'] ?? null) !== null ? ' · ' . $eur($a['bezahlt_betrag']) : '' ?></span></div>
  <?php endif; ?>
  <div class="bx-row" style="gap:16px;flex-wrap:wrap;align-items:flex-start">
    <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;margin:0;flex-wrap:wrap">
      <input type="hidden" name="aktion" value="bezahlt_setzen">
      <div class="bx-field" style="margin:0"><label>Bezahlt am</label><input type="date" name="bezahlt_am" value="<?= h((string)($a['bezahlt_am'] ?? date('Y-m-d'))) ?>"></div>
      <div class="bx-field" style="margin:0"><label>Betrag (optional)</label><input type="text" inputmode="decimal" name="bezahlt_betrag" value="<?= ($a['bezahlt_betrag'] ?? null) !== null ? h(number_format((float)$a['bezahlt_betrag'],2,',','')) : '' ?>" placeholder="z. B. 5.560,00" style="width:120px"></div>
      <button class="btn btn-primary btn-sm" type="submit">Als bezahlt speichern</button>
      <?php if (!empty($a['bezahlt_am'])): ?>
      <button class="btn btn-ghost btn-sm" type="submit" form="bezReset">zurücksetzen</button>
      <?php endif; ?>
    </form>
    <?php if (!empty($a['bezahlt_am'])): ?><form id="bezReset" method="post" style="display:none"><input type="hidden" name="aktion" value="bezahlt_reset"></form><?php endif; ?>
  </div>
  <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line,#e5e5e5)">
    <div style="font-weight:600;margin-bottom:6px">Alte Rechnung (PDF) hochladen</div>
    <p class="muted" style="font-size:12px;margin-top:0">Die Original-Rechnung aus dem alten System – sie erscheint beim Kunden im Portal als Rechnung zum Download.</p>
    <?php if ($altRechnungen): ?>
    <div class="bx-tablewrap" style="margin-bottom:10px"><table class="bx-table"><tbody>
      <?php foreach ($altRechnungen as $d): ?>
      <tr>
        <td><a href="?p=dokument&id=<?= (int)$d['id'] ?>" target="_blank"><?= h($d['datei_orig'] ?: 'Rechnung.pdf') ?></a></td>
        <td class="bx-num"><?= $d['dok_datum'] ? h(date('d.m.Y', strtotime((string)$d['dok_datum']))) : '' ?></td>
        <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Rechnung löschen?');"><input type="hidden" name="aktion" value="altrechnung_del"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;margin:0;flex-wrap:wrap" data-busy="Lade hoch…">
      <input type="hidden" name="aktion" value="altrechnung_upload">
      <div class="bx-field" style="margin:0"><label>Datei (PDF)</label><input type="file" name="dok" required accept="application/pdf,image/*"></div>
      <div class="bx-field" style="margin:0"><label>Rechnungsdatum</label><input type="date" name="dok_datum"></div>
      <button class="btn btn-ghost btn-sm" type="submit">Hochladen</button>
      <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin:0 0 6px"><input type="checkbox" name="betrag_uebernehmen" value="1" <?= (float)$a['gesamt_netto'] <= 0 ? 'checked' : '' ?>> Betrag per KI auslesen und Preis übernehmen <?= bx_hint('Liest den Rechnungsbetrag und trägt ihn als Netto + VK/Stück ein – nur wenn der Auftrag noch keinen Preis hat. Kann einen Moment dauern.') ?></label>
    </form>
  </div>
</div>

<?php if ($istAdmin): ?>
<?php if (isset($_GET['expressfehler'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)$_GET['expressfehler']) ?></div><?php endif; ?>
<div class="bx-panel" data-panel="preise">
  <h2 style="margin-top:0">EK-Preise &amp; Express-Bestellung <span class="muted" style="font-weight:normal;font-size:13px">· nur intern (Admin)</span></h2>
  <?php if (!$pa): ?>
    <div class="muted">Kein Produktionsauftrag – Materialbedarf nicht berechenbar.</div>
  <?php elseif (!$ekBedarf): ?>
    <div class="muted">Keine Rohstoffe im Bedarf (Zukauf oder Rezeptur ohne Zutaten).</div>
  <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th class="bx-num">benötigt</th><th>Wo bestellbar – EK je Einheit (günstigste zuerst)</th></tr></thead>
      <tbody>
      <?php foreach ($ekBedarf as $bd): $nz = fn($x,$n=3)=>rtrim(rtrim(number_format((float)$x,$n,',','.'),'0'),','); ?>
        <tr>
          <td><a class="kundenlink" href="?p=rohstoff&id=<?= (int)$bd['item_id'] ?>&tab=ek"><?= h($bd['name']) ?></a></td>
          <td class="bx-num"><?= $nz($bd['benoetigt']) ?> <?= h($bd['einheit']) ?><?php if ($bd['fehlt'] > 0.0001): ?><br><span style="color:#8f231b;font-size:12px">fehlt <?= $nz($bd['fehlt']) ?></span><?php else: ?><br><span class="bx-ok" style="font-size:12px">auf Lager</span><?php endif; ?></td>
          <td><?php if (!$bd['angebote']): ?><span class="muted">kein EK-Preis hinterlegt</span> · <a href="?p=rohstoff&id=<?= (int)$bd['item_id'] ?>&tab=ek" style="font-size:12px">anfragen</a>
              <?php else: $bi = 0; foreach ($bd['angebote'] as $ao): ?>
                <div style="<?= $bi === 0 ? 'font-weight:600' : '' ?>"><?= h($ao['firma'] ?: '–') ?>: <?= $nz($ao['preis'], 4) ?> <?= h($ao['waehrung'] ?: 'EUR') ?><?= (float)$ao['menge_ab'] > 0 ? ' <span class="muted">(ab ' . $nz($ao['menge_ab']) . ')</span>' : '' ?><?= $bi === 0 ? ' <span class="muted">· günstigste</span>' : '' ?></div>
              <?php $bi++; endforeach; endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($ekLieferanten): ?>
    <div class="bx-row" style="gap:10px;margin-top:14px;flex-wrap:wrap;align-items:center">
      <span class="muted" style="font-size:13px">Express-Bestellung (überspringt den Einkauf – bestellt die fehlenden Mengen direkt beim Lieferanten):</span>
      <?php foreach ($ekLieferanten as $lid => $firma): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Express-Bestellung anlegen? Bestellt die fehlenden Rohstoffe dieses Auftrags direkt bei diesem Lieferanten.');">
        <input type="hidden" name="aktion" value="express_bestellung"><input type="hidden" name="lieferant_id" value="<?= (int)$lid ?>">
        <button class="btn btn-primary btn-sm" type="submit" data-busy="Bestellt…">Express bei <?= h($firma ?: 'Lieferant') ?></button>
      </form>
      <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:12px;margin:8px 0 0">Legt sofort eine Bestellung (Entwurf) mit den fehlenden Rohstoffen an und öffnet sie – ohne den Umweg über Einkaufsbedarf/-liste. Absenden an den Lieferanten dann wie gewohnt in der Bestellung.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($zukaufPreise || $prodRezId): $nz = fn($x,$n=3)=>rtrim(rtrim(number_format((float)$x,$n,',','.'),'0'),','); ?>
  <h3 style="margin:20px 0 8px;font-size:14px;font-weight:600">Fertigprodukt zukaufen (Bulk)</h3>
  <?php if ($zukaufPreise): $VZ = versandart_liste(); ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Lieferant</th><th>Größe</th><th class="bx-num">ab Menge</th><th class="bx-num">EK je Einheit</th><th>Lieferbedingung</th></tr></thead>
      <tbody>
      <?php $bi = 0; foreach ($zukaufPreise as $z): $terms = array_filter([(string)$z['incoterm'], $z['versandart'] ? ($VZ[$z['versandart']] ?? $z['versandart']) : '']); ?>
        <tr<?= $bi === 0 ? ' style="font-weight:600"' : '' ?>>
          <td><?= h($z['firma'] ?: '–') ?><?= $bi === 0 ? ' <span class="muted" style="font-weight:normal">· günstigste</span>' : '' ?></td>
          <td><?= $z['groesse'] ? h($z['groesse']) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= (float)$z['menge_ab'] > 0 ? $nz($z['menge_ab']) : '–' ?></td>
          <td class="bx-num"><?= $nz($z['preis'], 4) ?> <?= h($z['waehrung'] ?: 'EUR') ?><?= $z['einheit'] ? ' / ' . h($z['einheit']) : '' ?></td>
          <td class="muted"><?= $terms ? h(implode(' · ', $terms)) : '–' ?></td>
        </tr>
      <?php $bi++; endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <div class="muted">Noch keine Zukaufpreise für dieses Produkt hinterlegt – per „Fertigprodukt anfragen" bei Lieferanten einholen.</div>
  <?php endif; ?>
  <div class="bx-row" style="gap:10px;margin-top:12px;flex-wrap:wrap;align-items:center">
    <?php if ($prodRezId) echo anfrage_produkt_button($prodRezId, (string)($a['produkt_name'] ?? ''), '', 'Fertigprodukt anfragen'); ?>
    <?php if ($zukaufLief): ?><span class="muted" style="font-size:13px">· Express-Zukauf (Bulk, überspringt den Einkauf):</span>
      <?php foreach ($zukaufLief as $lid => $firma): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Fertigprodukt als Bulk direkt bei diesem Lieferanten bestellen?');">
        <input type="hidden" name="aktion" value="express_bulk"><input type="hidden" name="lieferant_id" value="<?= (int)$lid ?>">
        <button class="btn btn-primary btn-sm" type="submit" data-busy="Bestellt…">Express bei <?= h($firma ?: 'Lieferant') ?></button>
      </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:8px 0 0">Zukauf des fertigen Produkts als Bulk (Kunde sieht das nie). „Anfragen" holt Preise bei Lieferanten ein; „Express" legt direkt eine Bulk-Bestellung an.</p>
  <?php endif; ?>
</div>
<?php anfrage_modal($anfrageLieferanten, '?p=auftrag&id=' . $id); ?>
<?php endif; ?>

<?php if (has_role('admin') && empty($a['kontingent_id']) && (string)$a['status'] !== 'storniert' && (int)$a['menge'] > 0 && (float)$a['vk_stueck'] > 0): ?>
<div class="bx-panel" data-panel="preise">
  <h2 style="margin-top:0">Zu Kontingent machen</h2>
  <p class="muted" style="margin-top:0">Wandelt die angenommene Menge (<?= number_format((int)$a['menge'],0,',','.') ?> × <?= $eur((float)$a['vk_stueck']) ?>) in ein <strong>Kontingent</strong> (Rahmen/Abruf) um: Der Kunde ruft daraus Teilmengen zum Festpreis ab – je Abruf entsteht ein Auftrag. Der jetzige Auftrag wird dabei storniert (produziert wird über die Abrufe).</p>
  <form method="post" style="margin:0" onsubmit="return confirm('Auftrag <?= h($a['nummer']) ?> in ein Kontingent umwandeln? Der Auftrag wird storniert; produziert wird über die Abrufe.');">
    <input type="hidden" name="aktion" value="zu_kontingent">
    <button class="btn btn-primary" type="submit" data-busy="Wandle um…">Zu Kontingent machen</button>
  </form>
</div>
<?php endif; ?>

<?php
// Laboranalysen dieser Bestellung (Charge). Admin laedt hier den Labortest/das CoA fuer genau diese Bestellung hoch.
$analyseDocs = all("SELECT id, titel, datei_orig, dok_datum, charge_nr, befund, kunde_sichtbar, angelegt FROM dokument
                    WHERE objekt_typ='auftrag' AND objekt_id=? AND typ='analyse' ORDER BY COALESCE(dok_datum, DATE(angelegt)) DESC, id DESC", [$id]);
$befundBadge = fn($b) => $b === 'bestanden' ? bx_badge('bestanden','ok') : ($b === 'auffaellig' ? bx_badge('auffällig','err') : '<span class="muted">–</span>');
$chargeNr = (string) scalar("SELECT c.charge_nr FROM charge c JOIN produktionsauftrag pa ON pa.id=c.pa_id
                             WHERE pa.auftrag_id=? AND c.charge_nr IS NOT NULL AND c.charge_nr<>'' ORDER BY c.id LIMIT 1", [$id]);
?>
<div class="bx-panel" data-panel="dokumente">
  <h2 style="margin-top:0">Laboranalyse / Labortest<?= $chargeNr ? ' <span class="muted" style="font-weight:normal;font-size:13px">· Charge ' . h($chargeNr) . '</span>' : '' ?></h2>
  <p class="muted" style="margin-top:0">Labortest bzw. Analysenzertifikat (CoA) für <strong>diese Bestellung</strong>. Als „freigegeben" erscheint es im Kundenportal-Reiter „Labortest".</p>
  <?php if ($analyseDocs): ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Datum</th><th>Charge</th><th>Befund</th><th>Datei</th><th>Kundenportal</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($analyseDocs as $d): ?>
      <tr>
        <td><?= $d['dok_datum'] ? h(fmt_zeit($d['dok_datum'] . ' 00:00:00', 'd.m.Y')) : h(fmt_zeit($d['angelegt'], 'd.m.Y')) ?></td>
        <td><?= $d['charge_nr'] ? h($d['charge_nr']) : '<span class="muted">–</span>' ?></td>
        <td><?= $befundBadge($d['befund'] ?? null) ?></td>
        <td><a href="?p=dokument&id=<?= (int)$d['id'] ?>" target="_blank"><?= h($d['titel'] ?: ($d['datei_orig'] ?: 'Analyse')) ?></a></td>
        <td><form method="post" style="margin:0"><input type="hidden" name="aktion" value="analyse_toggle"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= (int)$d['kunde_sichtbar'] === 1 ? bx_badge('freigegeben','ok') : bx_badge('intern') ?></button></form></td>
        <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Analyse löschen?');"><input type="hidden" name="aktion" value="analyse_del"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" style="margin-top:12px" data-busy="Lade hoch…">
    <input type="hidden" name="aktion" value="analyse_upload">
    <div class="bx-grid">
      <div class="bx-field"><label>Datei (PDF/Bild)</label><input type="file" name="dok" required accept="application/pdf,image/*"></div>
      <div class="bx-field"><label>Chargennummer</label><input type="text" name="charge_nr" value="<?= h($chargeNr ?: '') ?>" placeholder="z. B. JN26P8"></div>
      <div class="bx-field"><label>Befund</label>
        <select name="befund">
          <option value="bestanden">bestanden</option>
          <option value="auffaellig">auffällig</option>
          <option value="unklar" selected>unklar / kein Befund</option>
        </select>
      </div>
      <div class="bx-field"><label>Analysendatum</label><input type="date" name="datum"></div>
      <div class="bx-field"><label>Titel (optional)</label><input type="text" name="titel" placeholder="z. B. Labortest"></div>
    </div>
    <div class="bx-row" style="gap:8px;align-items:center;margin-top:var(--sp-3)">
      <input type="checkbox" name="kunde_sichtbar" id="ak_sicht" value="1" checked>
      <label for="ak_sicht" style="margin:0">im Kundenportal sichtbar</label>
    </div>
    <div class="bx-row" style="margin-top:var(--sp-3)"><button class="btn btn-primary" type="submit">Analyse hochladen</button></div>
  </form>
</div>

<form method="post" class="bx-form" data-panel="details">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Status</label>
      <select name="status">
        <?php foreach (['offen'=>'offen','in_produktion'=>'in Produktion','erledigt'=>'versandbereit','versendet'=>'versendet','storniert'=>'storniert'] as $key=>$lbl): ?>
          <option value="<?= $key ?>" <?= $a['status']===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Menge (Packungen)</label>
      <input type="number" name="menge" min="0" value="<?= (int)$a['menge'] ?>"></div>
    <div class="bx-field"><label>VK je Packung (netto)</label>
      <input type="text" name="vk_stueck" id="vkFeld" value="<?= h((float)$a['vk_stueck'] > 0 ? rtrim(rtrim(number_format((float)$a['vk_stueck'], 4, ',', ''), '0'), ',') : '') ?>" placeholder="z. B. 0,84"></div>
    <div class="bx-field"><label>Verpackung (Behälter) <?= bx_hint('Primärverpackung dieses Auftrags. Fehlt sie (z. B. durch die Systemumstellung), hier setzen. Anderes Glas → anderes Etikett → wirkt auf Produktion, Einkauf & PIB. Kunde sieht es ohne Bestätigung.') ?></label>
      <select name="verpackung_id" class="rscombo">
        <option value="">– keine –</option>
        <?php foreach (all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0 ORDER BY name") as $vp): ?>
          <option value="<?= (int)$vp['id'] ?>" <?= (int)($a['verpackung_id'] ?? 0) === (int)$vp['id'] ? 'selected' : '' ?>><?= h($vp['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div style="margin-top:6px;font-size:13px">
        <label style="margin-right:14px"><input type="radio" name="verp_scope" value="auftrag" checked style="width:auto"> nur dieser Auftrag</label>
        <label><input type="radio" name="verp_scope" value="standard" style="width:auto"> als Standard für dieses Produkt</label>
      </div>
    </div>
    <?php if ($rezeptur && in_array($rezeptur['darreichungsform'] ?? '', ['kapsel','softgel'], true)): ?>
    <div class="bx-field"><label>Kapselgröße <?= bx_hint('Gilt für die Rezeptur dieses Produkts (Standard für alle Aufträge). Wirkt auf Leerkapsel-Bedarf und Packungsrechnung.') ?></label>
      <select name="kapselgroesse_id">
        <option value="">– automatisch –</option>
        <?php foreach (all("SELECT id, name FROM kapselgroesse ORDER BY fuellmenge_mg") as $kg): ?>
          <option value="<?= (int)$kg['id'] ?>" <?= (int)($rezeptur['kapselgroesse_id'] ?? 0) === (int)$kg['id'] ? 'selected' : '' ?>><?= h($kg['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
  </div>
  <div class="muted" style="font-size:12px;margin-top:2px">Netto gesamt = Menge × VK je Packung – wird beim Speichern automatisch berechnet<span id="vkVorschau"></span>.</div>
  </div>
  <button class="btn btn-primary" type="submit" data-busy="Speichert…">Speichern</button>
</form>

<?php if (has_role('admin') && (string)$a['status'] !== 'versendet'): ?>
<div class="bx-panel" data-panel="details" style="border-color:#e6c4c0">
  <h2 style="margin-top:0">Löschen &amp; zurück zur Anfrage</h2>
  <p class="muted" style="margin-top:0">Löscht diese Auftragsbestätigung samt Produktionsauftrag und (unbezahlter) Rechnung. Das zugehörige Angebot wird wieder <strong>offen</strong>, und Sie springen zurück zur Anfrage, um es anzupassen oder neu zu senden.</p>
  <form method="post" style="margin:0" onsubmit="return confirm('Auftragsbestätigung <?= h($a['nummer']) ?> löschen? Produktionsauftrag und unbezahlte Rechnung werden entfernt; das Angebot wird wieder offen.');">
    <input type="hidden" name="aktion" value="auftrag_zurueck">
    <button class="btn btn-danger" type="submit" data-busy="Lösche…">Löschen &amp; zurück zur Anfrage</button>
  </form>
</div>
<?php endif; ?>
<script>
(function(){
  var m = document.querySelector('input[name="menge"]'), v = document.getElementById('vkFeld'), out = document.getElementById('vkVorschau');
  if (!m || !v || !out) return;
  function rechne(){
    var mv = parseFloat((m.value||'').replace(',','.'))||0, vv = parseFloat((v.value||'').replace(/\./g,'').replace(',','.'))||0;
    out.textContent = (mv>0 && vv>0) ? ' → ' + (mv*vv).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' €' : '';
  }
  m.addEventListener('input', rechne); v.addEventListener('input', rechne); rechne();
})();
</script>
<script>
(function(){
  // Reiter wie auf der Kundenseite: oben Kennzahlen, darunter Tabs. Panels sind per data-panel getaggt;
  // der aktive Tab wird je Auftrag gemerkt (sessionStorage), bleibt also nach dem Speichern erhalten.
  var nav = document.getElementById('auftabs'); if (!nav) return;
  var tabs = nav.querySelectorAll('a[data-tab]');
  var panes = document.querySelectorAll('[data-panel]');
  var KEY = 'auftrag_tab_<?= (int)$id ?>';
  function hat(name){ for (var i=0;i<tabs.length;i++) if (tabs[i].getAttribute('data-tab')===name) return true; return false; }
  function activate(name){
    if (!hat(name)) name = 'details';
    panes.forEach(function(p){ p.style.display = (p.getAttribute('data-panel')===name) ? '' : 'none'; });
    tabs.forEach(function(t){ t.classList.toggle('on', t.getAttribute('data-tab')===name); });
    try { sessionStorage.setItem(KEY, name); } catch(e){}
  }
  tabs.forEach(function(t){ t.addEventListener('click', function(e){ e.preventDefault(); activate(t.getAttribute('data-tab')); }); });
  var start = 'details';
  try { var s = sessionStorage.getItem(KEY); if (s) start = s; } catch(e){}
  <?php if (isset($_GET['analyse'])): ?>start = 'dokumente';<?php endif; ?>
  activate(start);
})();
</script>
<?php render_footer(); ?>
