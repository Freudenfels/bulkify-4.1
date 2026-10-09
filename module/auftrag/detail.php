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

// Produktionsauftrag direkt vom Auftrag aus zur Produktion freigeben (Vorbereitung -> offen), ohne den Umweg
// über das Modul Vor-Produktion. Admin-Weiche: offene Punkte (Etikett/Material) sind nur ein Hinweis.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'pa_freigeben') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $paid = (int) scalar("SELECT id FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$id]);
    if (!$paid) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Kein Produktionsauftrag zum Freigeben.')); exit; }
    $art = ($_POST['produktionsart'] ?? '') === 'eigen' ? 'eigen' : 'fremd';
    $mp  = ($_POST['menge_produktion'] ?? '') !== '' ? (int)$_POST['menge_produktion'] : null;
    $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
    $r = produktionsauftrag_freigeben($paid, $art, $mp, $wer);
    header('Location: ?p=auftrag&id=' . $id . (!empty($r['ok']) ? '&freigabeok=1' : '&expressfehler=' . urlencode($r['fehler'] ?? 'Freigabe fehlgeschlagen.'))); exit;
}

// MHD der Fertigware selbst festlegen (am Produktionsauftrag) – wird beim Einbuchen der Charge verwendet.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'mhd_setzen') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $paid = (int) scalar("SELECT id FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$id]);
    if (!$paid) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Kein Produktionsauftrag.')); exit; }
    $m = trim((string)($_POST['mhd'] ?? ''));
    $m = ($m !== '' && strtotime($m)) ? date('Y-m-d', strtotime($m)) : null;
    q("UPDATE produktionsauftrag SET mhd=? WHERE id=?", [$m, $paid]);
    header('Location: ?p=auftrag&id=' . $id . '&mhdok=1'); exit;
}

// Auftrag ohne Rezeptur mit einer bestehenden Rezeptur verknüpfen (z. B. v3-Import/Freitext-Auftrag).
// Setzt die direkte Verknüpfung am Auftrag; hängt ein Produkt ohne Rezeptur dran, wird sie dort auch gesetzt
// (damit Produktion/Specs/PIB die Rezeptur kennen).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'rezeptur_verknuepfen') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $rzid = (int)($_POST['rezeptur_id'] ?? 0);
    if ($rzid <= 0 || !scalar("SELECT id FROM rezeptur WHERE id=?", [$rzid])) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Bitte eine gültige Rezeptur wählen.')); exit; }
    q("UPDATE auftrag SET rezeptur_id=? WHERE id=?", [$rzid, $id]);
    $pid = (int) scalar("SELECT produkt_id FROM auftrag WHERE id=?", [$id]);
    if ($pid && !(int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [$pid])) q("UPDATE produkt SET rezeptur_id=? WHERE id=?", [$rzid, $pid]);
    header('Location: ?p=auftrag&id=' . $id . '&rezverk=1'); exit;
}

// Rezeptur für DIESEN Auftrag überarbeiten: eine eigene Kopie anlegen (Rohstoffe neu matchen + frische
// Spec/CoA), verknüpft über auftrag.rezeptur_id. Das Kunden-Original bleibt unangetastet. Danach direkt in
// die Kopie springen (Status 'entwurf' = sofort editierbar).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'rezeptur_auftrag_kopie') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $neu = rezeptur_fuer_auftrag_kopieren($id);
    if ($neu) { header('Location: ?p=rezeptur_detail&id=' . $neu . '#zutaten'); exit; }
    header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Keine Rezeptur zum Kopieren gefunden.')); exit;
}

// Rechnung dieses Auftrags neu berechnen (USt + Adresse aus dem aktuellen Kunden). Fix für Rechnungen, die
// ohne Adresse/ohne USt entstanden sind: sobald die Kundenadresse da ist, hier neu berechnen -> wird korrekt
// bepreist und (bei vorhandener Adresse) für den Kunden freigegeben.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'rechnung_neu_berechnen') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id); exit; }
    $rid = (int) scalar("SELECT id FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert' ORDER BY id DESC LIMIT 1", [$id]);
    $r = $rid ? beleg_neu_berechnen($rid) : ['ok'=>false, 'fehler'=>'Keine Rechnung vorhanden.'];
    if (!empty($r['ok']) && !empty($r['sichtbar'])) q("UPDATE beleg SET kunde_sichtbar=1 WHERE id=?", [$rid]);
    header('Location: ?p=auftrag&id=' . $id . (!empty($r['ok']) ? '&rechneu=1' : '&expressfehler=' . urlencode($r['fehler'] ?? 'Neu berechnen fehlgeschlagen.'))); exit;
}

// Fertige Ware eines Altauftrags OHNE Produktionsauftrag nachtragen und direkt ins Lager 1/2 einbuchen.
// Legt bei Bedarf einen Produktionsauftrag an (nur als Träger für die Charge), hakt ihn ab und bucht die
// volle Menge als Fertigware-Charge (Fulfillment → Lager 2 + Auftrag abgeschlossen). Für produzierte
// Aufträge, die nie durch die neue Produktion liefen und darum nirgends auftauchen (z. B. AB-3257).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'charge_nachtragen_einlagern') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $art  = ($_POST['produktionsart'] ?? '') === 'eigen' ? 'eigen' : 'fremd';
    $paid = produktionsauftrag_aus_auftrag($id, $art);
    if (!$paid) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Geht nicht – am Auftrag ist kein Produkt hinterlegt.')); exit; }
    // Der PA ist hier nur Träger der Charge: sofort als erledigt markieren + Schritte abhaken, damit er nicht
    // als neue Aufgabe in Vor-Produktion/Produktion auftaucht. Dann die Fertigware ins Lager buchen.
    q("UPDATE produktionsauftrag SET status='erledigt' WHERE id=?", [$paid]);
    q("UPDATE produktion_schritt SET erledigt=1 WHERE pa_id=? AND erledigt=0", [$paid]);
    $r = einlager_buchen($paid);
    // Altware hat ihre eigene (gedruckte) Chargennummer + MHD – optional überschreiben wir die Auto-Werte.
    $cNr  = trim((string)($_POST['charge_nr'] ?? ''));
    $cMhd = trim((string)($_POST['mhd'] ?? ''));
    $cMhd = ($cMhd !== '' && strtotime($cMhd)) ? date('Y-m-d', strtotime($cMhd)) : '';
    if (!empty($r['charge_id']) && ($cNr !== '' || $cMhd !== '')) {
        q("UPDATE charge SET charge_nr = COALESCE(?, charge_nr), mhd = COALESCE(?, mhd) WHERE id=?",
          [$cNr !== '' ? $cNr : null, $cMhd !== '' ? $cMhd : null, (int)$r['charge_id']]);
    }
    header('Location: ?p=auftrag&id=' . $id . '&einlagerok=' . urlencode((string)($r['label'] ?? 'Lager'))); exit;
}

// Einlagern direkt am Auftrag anstoßen (an Lager übergeben / nachholen): bucht die fertige Ware ins Lager 1/2.
// Für fertig produzierte Aufträge, die noch nicht eingelagert sind (z. B. Altaufträge) – ohne Modulwechsel.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'einlagern_nachholen') {
    $paid = (int) scalar("SELECT id FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$id]);
    if ($paid) { $r = einlager_buchen($paid); header('Location: ?p=auftrag&id=' . $id . '&einlagerok=' . urlencode((string)($r['label'] ?? 'Lager'))); exit; }
    header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Kein Produktionsauftrag zum Einlagern vorhanden.')); exit;
}

// Schnelle Status-Änderung direkt am Auftrag (Statusleiste oben, auf jedem Reiter sichtbar, unabhängig von
// einem Produktionsauftrag). Ändert NUR den Status + Status-Datum (Kundensicht) – Menge/VK/Verpackung bleiben
// unberührt (anders als das große Details-Formular). Stornieren läuft bewusst weiter über Details (Gutschrift-Logik).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'status_schnell') {
    if (!(has_role('admin') || has_role('sales') || has_role('production'))) { header('Location: ?p=auftrag&id=' . $id); exit; }
    $neu = (string)($_POST['status'] ?? '');
    if (!in_array($neu, ['offen','in_produktion','erledigt','versendet'], true)) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Ungültiger Status.')); exit; }
    $alt = (string) scalar("SELECT status FROM auftrag WHERE id=?", [$id]);
    if ($neu !== $alt) {
        q("UPDATE auftrag SET status=?, status_datum=CURDATE() WHERE id=?", [$neu, $id]);
        log_aktivitaet('auftrag', $id, 'team', 'Status geändert: ' . $alt . ' -> ' . $neu, 'status', 'auftrag', $id);
    }
    header('Location: ?p=auftrag&id=' . $id . '&statusok=1'); exit;
}

// Etikett (Team/Admin): Datei hochladen/ersetzen (z. B. Last-Minute-Änderung des Kunden), Freigabe im
// Namen des Kunden bestätigen, Datei entfernen. Nur Admin/Vertrieb.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && in_array(($_POST['aktion'] ?? ''), ['etikett_upload_team','etikett_freigeben_team','etikett_del_team'], true)) {
    if (!(has_role('admin') || has_role('sales'))) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Keine Berechtigung.')); exit; }
    $akt = (string)$_POST['aktion'];
    if ($akt === 'etikett_upload_team') {
        if (etikett_upload($id)) log_aktivitaet('kunde', (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$id]), 'team', 'Etikett vom Team hochgeladen.', 'auftrag', 'auftrag', $id);
        header('Location: ?p=auftrag&id=' . $id . '&etikettok=1'); exit;
    }
    if ($akt === 'etikett_del_team') { etikett_del($id); header('Location: ?p=auftrag&id=' . $id . '&etikettok=1'); exit; }
    // Freigabe im Namen des Kunden (extern erteilt) – mit Name, als Akteur 'team'.
    $name = trim((string)($_POST['freigabe_name'] ?? ''));
    if ($name === '') { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Bitte einen Namen für die Freigabe angeben.')); exit; }
    $r = etikett_freigabe_setzen($id, $name, 'team');
    header('Location: ?p=auftrag&id=' . $id . (!empty($r['ok']) ? '&etikettok=1' : '&expressfehler=' . urlencode($r['fehler'] ?? 'Freigabe nicht möglich.'))); exit;
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
    // Reiner Helfer: nur SETZEN (kein Rückgängig über diese Eingabe) – einmal angekommen bleibt angekommen.
    q("UPDATE auftrag SET rohstoff_angekommen_am=? WHERE id=? AND rohstoff_angekommen_am IS NULL", [gmdate('Y-m-d H:i:s'), $id]);
    q("UPDATE bestellung b JOIN bestellung_position bp ON bp.bestellung_id=b.id
       SET b.angekommen_am=COALESCE(b.angekommen_am, CURDATE()) WHERE bp.auftrag_id=?", [$id]);
    header('Location: ?p=auftrag&id=' . $id . '&rohok=1'); exit;
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

// Auftrag stornieren: Der Auftrag ist „gesetzt" – Menge/Preis/Verpackung werden hier NICHT mehr bearbeitet
// (Rezeptur-Änderungen laufen über die Rezeptur, Flaschen/Verpackung über die Vor-Produktion). Einzige
// Werte-Änderung am Auftrag selbst ist das Stornieren: Status 'storniert' + offene Rechnung(en) per
// Gutschrift stornieren. Nur Admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'auftrag_stornieren') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $altStatus = (string) scalar("SELECT status FROM auftrag WHERE id=?", [$id]);
    $stn = 0;
    if ($altStatus !== 'storniert') {
        q("UPDATE auftrag SET status='storniert', status_datum=CURDATE() WHERE id=?", [$id]);
        $akteur = (function_exists('current_user') && ($u = current_user())) ? $u['name'] : 'team';
        $stn = auftrag_rechnungen_stornieren($id, 'Auftrag ' . ((string) scalar("SELECT nummer FROM auftrag WHERE id=?", [$id])) . ' storniert', $akteur);
        log_aktivitaet('auftrag', $id, 'team', 'Auftrag storniert.', 'status', 'auftrag', $id);
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
// Rezeptur = direkter Override am Auftrag (falls verknüpft) ODER die des Produkts.
$rezEffId = (int)($a['rezeptur_id'] ?? 0);
if (!$rezEffId && !empty($a['produkt_id'])) $rezEffId = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
$rezeptur = $rezEffId ? one("SELECT id, nummer, name, darreichungsform, kapselgroesse_id FROM rezeptur WHERE id=?", [$rezEffId]) : null;
// Nutzt der Auftrag schon eine eigene (vom Produkt abweichende) Rezeptur? Dann ist es bereits die Auftrags-Kopie.
$prodRezId   = !empty($a['produkt_id']) ? (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]) : 0;
$rezIstKopie = $rezEffId && $rezEffId !== $prodRezId;
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
// „erledigt" = versandbereit (Ware in Lager 1, für alle gleich). Bei Fulfillment heißt der Abschluss
// „abgeschlossen" (Ware ging ins Fremdlager/Lager 2), sonst „versendet". Zentraler Label-Helfer.
$ffAuftrag = $id ? auftrag_ist_fulfillment($id) : false;
$stLbl = fn($s) => match ((string)$s) {
    'offen'         => 'offen',
    'in_produktion' => 'in Produktion',
    'erledigt'      => 'versandbereit',
    'versendet'     => $ffAuftrag ? 'abgeschlossen' : 'versendet',
    'storniert'     => 'storniert',
    default         => status_text((string)$s),
};
$statusBadge = match ($a['status']) {
    'offen'         => bx_badge('offen','info'),
    'in_produktion' => bx_badge('in Produktion','warn'),
    'erledigt'      => bx_badge($stLbl('erledigt'),'info'),
    'versendet'     => bx_badge($stLbl('versendet'),'ok'),
    default         => bx_badge(status_text($a['status'])),
};

// Produktion + Beschaffung zu diesem Auftrag
$pa = one("SELECT * FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$id]);
$istFremd = $pa && ($pa['produktionsart'] ?? '') === 'fremd';
// Einlagern: fertig produziert (PA erledigt), aber noch nicht vollständig ans Lager übergeben.
//  (a) offene, noch nicht gebuchte Menge (produktion_rest) – oder
//  (b) eine Einlager-Aufgabe liegt noch offen beim Lager – oder
//  (c) Fulfillment-Altauftrag: Ware bereits gebucht (rest=0), aber der Auftrag ist noch nicht auf
//      'versendet' finalisiert (Leberkomplex-Fall) → erst das Finalisieren schiebt ihn ins Kunden-Archiv.
$paRest        = $pa ? produktion_rest((int)$pa['id']) : 0.0;
$einlagerZiel  = $pa ? einlager_ziel_fuer_pa((int)$pa['id']) : ['ziel'=>'', 'label'=>''];   // immer Lager 1
$einlagerOffeneAufgabe = $pa ? (int) scalar("SELECT COUNT(*) FROM aufgabe WHERE ref_typ='einlagern' AND ref_id=? AND status='offen'", [(int)$pa['id']]) : 0;
$einlagerErledigt      = $pa && ($pa['status'] ?? '') === 'erledigt';
// Einlagern nötig, solange fertig produziert ist und noch nicht alles in Lager 1 gebucht wurde
// (offene Menge) ODER noch eine Einlager-Aufgabe offen liegt. Danach entscheidet das Lager beim Versand.
$einlagerNoetig = $einlagerErledigt && ($paRest > 0.0001 || $einlagerOffeneAufgabe > 0);
$paStatusBadge = $pa ? match ($pa['status']) {
    'vorbereitung'=>bx_badge('Vorbereitung','warn'),
    'offen'=>bx_badge('offen','info'),'laufend'=>bx_badge('läuft','warn'),'erledigt'=>bx_badge('fertig','ok'),
    default=>bx_badge(status_text((string)$pa['status'])),
} : '';
$ber = $pa ? produktion_bereitschaft((int)$pa['id']) : ['status'=>''];
// Vorbereitung -> am Auftrag freigebbar. Charge/MHD der Fertigware: bereits gebuchte Chargen des PA, sonst
// die systemseitig geplante nächste Charge (charge_naechste_nr) + Standard-MHD (+18 Monate).
$paVorbereitung = $pa && ($pa['status'] ?? '') === 'vorbereitung';
$paChargen = $pa ? all("SELECT charge_nr, mhd, menge_verfuegbar FROM charge WHERE pa_id=? ORDER BY id", [(int)$pa['id']]) : [];
$chargePlan = ($pa && !$paChargen) ? charge_naechste_nr((int)$pa['id']) : '';
$mhdGesetzt = $pa ? trim((string)($pa['mhd'] ?? '')) : '';          // selbst festgelegtes MHD am PA
$mhdPlan    = ($pa && !$paChargen) ? ($mhdGesetzt !== '' ? $mhdGesetzt : mhd_standard()) : '';
$freigabeBedarf = $pa ? max(0, (int) produktion_stueck_je_packung($pa)) * max(0, (int)$pa['menge']) : 0;
$einhProP  = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
if ($einhProP <= 0) $einhProP = (int)($a['stueck'] ?? 0);   // Fallback: Stück je Packung liegt am Auftrag (v3-Import)
$gesamtStk = $einhProP > 0 ? (int)$a['menge'] * $einhProP : 0;
// Namens-Snapshot zuerst: der zur Auftragszeit festgehaltene Name gilt, damit eine spätere Produkt-Umbenennung den Auftrag nicht ändert.
$produktName = (string)($a['produkt_bezeichnung'] ?? '') ?: (string)($a['produkt_name'] ?? '');
// Erstauftrag vs. Nachbestellung (neue Rezeptur / neues Produkt / Nachbestellung).
$artKey = auftrag_art((int)$a['id']);
[$artLabel, $artStil, $artHint] = auftrag_art_meta($artKey);
// Etikett-Status je Auftrag: freigegeben (Kunde) / hinterlegt bzw. aus Vorbestellung vorhanden / fehlt.
// Ein Produkt im Glas HAT ein Etikett (Typ/Maß aus dem Behälter abgeleitet). "fehlt" (rot) nur, wenn gar
// kein Design existiert – weder an diesem Auftrag noch aus einer früheren Bestellung desselben Produkts.
$etikettFrei = (int)($a['etikett_freigegeben'] ?? 0) === 1;
$etEigen     = etikett_vorhanden((int)$a['id']);
$etQuelle    = (!$etikettFrei && !$etEigen) ? etikett_quelle((int)$a['id']) : null; // Nachbestellung: Design aus Vorauftrag
if ($etikettFrei) {
    $etikettBadge = '<span title="Kunde hat das Etikett freigegeben">' . bx_badge('freigegeben', 'ok') . '</span>';
} elseif ($etEigen) {
    $etikettBadge = '<span title="Etikett-Datei hinterlegt, Kundenfreigabe fehlt noch">' . bx_badge('nicht freigegeben', 'warn') . '</span>';
} elseif ($etQuelle) {
    $etikettBadge = '<span title="Etikett aus einer früheren Bestellung vorhanden – Freigabe für diesen Auftrag fehlt noch">' . bx_badge('nicht freigegeben', 'warn') . '</span>';
} else {
    $etikettBadge = '<span title="Kunde hat noch kein Etikett-Design hinterlegt">' . bx_badge('fehlt', 'err') . '</span>';
}
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
$kopfProdukt = trim((string)($a['produkt_bezeichnung'] ?? '')) ?: (string)($a['produkt_name'] ?? '');
bx_head($a['nummer'] . ($kopfProdukt !== '' ? ' · ' . $kopfProdukt : ''), 'Auftragsbestätigung',
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
$stText = $stLbl($a['status']);
// Effektives Glas (Behälter): Auftrags-Override oder Produkt. Für die Anzeige „welches Glas".
$glasId   = (int)($a['verpackung_id'] ?? 0) ?: (int) scalar("SELECT verpackung_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
$glasName = $glasId ? (string) scalar("SELECT name FROM item WHERE id=?", [$glasId]) : '';
$fehltCard = fn($txt) => '<span style="color:var(--warn)">' . h($txt) . '</span>';
echo '<div class="bx-cards">';
echo '<div class="bx-card bx-card-status" title="Status" style="background:' . $stBg . ';color:' . $stFg . ';border:1px solid rgba(0,0,0,.15)"><div class="v" style="color:' . $stFg . '">' . h($stText) . '</div></div>';
echo '<div class="bx-card"><div class="k">Menge (Packungen)</div><div class="v">' . (int)$a['menge'] . '</div></div>';
// Glas + Kapselzahl IMMER zeigen (auch wenn leer) – sonst sieht man bei alten Nachbestellungen nicht, dass es fehlt.
echo '<div class="bx-card"><div class="k">Verpackung (Glas)</div><div class="v" style="font-size:16px">' . ($glasName !== '' ? h($glasName) : $fehltCard('nicht gesetzt')) . '</div></div>';
echo '<div class="bx-card"><div class="k">Stück je Packung</div><div class="v">' . ($einhProP > 0 ? number_format($einhProP, 0, ',', '.') : $fehltCard('nicht gesetzt')) . '</div></div>';
if ($gesamtStk > 0) echo '<div class="bx-card"><div class="k">Gesamtstückzahl</div><div class="v">' . number_format($gesamtStk, 0, ',', '.') . '</div></div>';
if ($groesseLbl !== '') echo '<div class="bx-card"><div class="k">Kapsel/Tablette</div><div class="v">' . h($groesseLbl) . '</div></div>';
echo '<div class="bx-card"><div class="k">Herstellung</div><div class="v">' . ($istFremd ? bx_badge('Zukauf','info') : bx_badge('Eigenproduktion','ok')) . '</div></div>';
echo '<div class="bx-card"><div class="k">Etikett</div><div class="v">' . $etikettBadge . '</div></div>';
echo '<div class="bx-card"><div class="k">VK / Stück</div><div class="v">' . $eur($a['vk_stueck']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Netto gesamt</div><div class="v">' . $eur($a['gesamt_netto']) . '</div></div>';
if (!empty($a['angelegt'])) echo '<div class="bx-card"><div class="k">Erstellt</div><div class="v">' . h(fmt_zeit($a['angelegt'], 'd.m.Y H:i')) . '</div></div>';
echo '</div>';

// (Etikett-Verwaltung ist in den Reiter „Verpackung" verschoben – siehe unten, data-panel="verpackung".)
// Admin-Override „Rohstoff/Bulk angekommen" – damit die Kunden-Statusleiste auch bei Alt-Aufträgen /
// Zukauf ohne verknüpfte Charge auf „Rohstoff angekommen" springt.
if (isset($_GET['rohok'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">' . ($_GET['rohok']==='1' ? 'Als „Rohstoff angekommen" markiert – der Kunde sieht es sofort.' : 'Markierung zurückgesetzt.') . '</div>';
if (isset($_GET['einlagerok'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">An das Lager übergeben und in ' . h((string)$_GET['einlagerok']) . ' eingebucht.</div>';
if (isset($_GET['rechneu'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">Rechnung neu berechnet (USt &amp; Adresse) – bei vorhandener Adresse ist sie jetzt für den Kunden freigegeben.</div>';
// „Rohstoff/Bulk angekommen" ist ein reiner Helfer – er steht jetzt IM Reiter „Produktion" (Block
// Produktion & Beschaffung), nicht mehr als eigene Leiste über den Reitern.
$rohAngDa = (kunde_auftrag_phase($a)['dates'][2] ?? null);   // Datum „Rohstoff angekommen" (oder null)
// Warnung: sehr grosse Einzelmenge, die kein Kontingent ist -> besser als Jahresvertrag/Kontingent fuehren,
// sonst wuerde die Produktion die komplette Jahresmenge auf einmal ziehen (z. B. 45.000 Glaeser/Etiketten).
$jvSchwelle = (int) meta_get('jahresmenge_warnschwelle', 10000);
$jvWarnung  = has_role('admin') && empty($a['kontingent_id']) && (string)$a['status'] !== 'storniert'
           && $jvSchwelle > 0 && (int)$a['menge'] >= $jvSchwelle && (float)$a['vk_stueck'] > 0;
?>
<?php if ($jvWarnung): ?>
<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:12px 16px;margin-bottom:16px">
  <strong>Große Menge als Einzelauftrag: <?= number_format((int)$a['menge'],0,',','.') ?> Stück.</strong>
  <div class="muted" style="font-size:13px;margin:4px 0 10px">Der Kunde ruft so große Mengen meist nicht auf einmal ab. Als <strong>Jahresvertrag/Kontingent</strong> geführt, entsteht Bedarf (Glas, Etiketten, Rohstoffe) nur je Abruf – nicht <?= number_format((int)$a['menge'],0,',','.') ?> Stück auf einmal.</div>
  <form method="post" style="margin:0" onsubmit="return confirm('Auftrag <?= h($a['nummer']) ?> in ein Kontingent umwandeln? Der Auftrag wird storniert; produziert wird über die Abrufe.');">
    <input type="hidden" name="aktion" value="zu_kontingent">
    <button class="btn btn-primary btn-sm" type="submit" data-busy="Wandle um…">Zu Kontingent (Jahresvertrag) machen</button>
  </form>
</div>
<?php endif; ?>
<div class="settabs" id="auftabs" style="margin-bottom:16px">
  <a href="#" class="on" data-tab="details">Details</a>
  <a href="#" data-tab="verpackung">Verpackung</a>
  <a href="#" data-tab="produktion">Produktion</a>
  <a href="#" data-tab="preise">Preise &amp; Rechnung</a>
  <a href="#" data-tab="dokumente">Dokumente</a>
</div>

<?php // Reiter „Verpackung": Etikett-Verwaltung (Team/Admin) – hochladen/ersetzen (Last-Minute), Freigabe im Namen des Kunden, entfernen.
if (auftrag_braucht_etikett($id) && (has_role('admin') || has_role('sales'))): $etDokA = etikett_datei($id); ?>
<div class="bx-panel" data-panel="verpackung">
  <h2 style="margin-top:0">Etikett</h2>
  <?php if (isset($_GET['etikettok'])): ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin-bottom:10px;display:inline-block">Etikett aktualisiert.</div><?php endif; ?>
  <p class="muted" style="margin-top:0">Admin/Vertrieb kann hier ein Etikett für den Kunden hochladen (z.&nbsp;B. Last-Minute-Änderung) und die Freigabe im Namen des Kunden bestätigen.</p>
  <p style="margin:0 0 10px">
    <?php if ($etDokA): ?>Hinterlegt: <strong><?= h((string)($etDokA['datei_orig'] ?: 'Etikett-Design')) ?></strong>
      <?= $etikettFrei ? bx_badge('freigegeben', 'ok') : bx_badge('nicht freigegeben', 'warn') ?>
      <a class="btn btn-ghost btn-sm" style="margin-left:8px" href="?p=dokument&id=<?= (int)$etDokA['id'] ?>" download target="_blank" rel="noopener">&#8681; Herunterladen (für die Druckerei)</a>
    <?php else: ?><span class="muted">Noch kein Etikett hinterlegt.</span><?php endif; ?>
  </p>
  <div class="bx-row" style="gap:16px;flex-wrap:wrap;align-items:flex-end">
    <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:0">
      <input type="hidden" name="aktion" value="etikett_upload_team">
      <input type="file" name="etikett" required accept="application/pdf,image/*">
      <button class="btn btn-ghost btn-sm" type="submit"><?= $etDokA ? 'Etikett ersetzen' : 'Etikett hochladen' ?></button>
    </form>
    <?php if ($etDokA && !$etikettFrei): ?>
    <form method="post" class="bx-row" style="gap:8px;align-items:center;margin:0">
      <input type="hidden" name="aktion" value="etikett_freigeben_team">
      <input type="text" name="freigabe_name" required placeholder="Name (Freigabe im Namen des Kunden)" style="padding:7px 10px;border:1px solid var(--line);border-radius:8px;min-width:230px">
      <button class="btn btn-primary btn-sm" type="submit">Freigabe bestätigen</button>
    </form>
    <?php endif; ?>
    <?php if ($etDokA): ?>
    <form method="post" style="margin:0" onsubmit="return confirm('Etikett wirklich entfernen? Die Freigabe wird zurückgesetzt.');">
      <input type="hidden" name="aktion" value="etikett_del_team">
      <button class="btn btn-ghost btn-sm" type="submit">Entfernen</button>
    </form>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:12px 0 0">Verpackung/Glas und Kapselgröße werden im Reiter „Details" gesetzt (sie gehören zur Produktkonfiguration).</p>
</div>
<?php else: ?>
<div class="bx-panel" data-panel="verpackung"><div class="muted">Für diesen Auftrag ist kein Etikett nötig (kein Behälter/Produkt hinterlegt) – oder du hast keine Berechtigung.</div></div>
<?php endif; ?>

<div class="bx-panel" data-panel="details">
  <h2>Details</h2>
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= kunde_link($a['kunde_id'] ?? null, $a['kunde_firma']) ?></div></div>
    <div><div class="k muted">Produkt</div><div><?php if (!empty($a['produkt_id']) && $produktName): ?><a href="?p=produkt&id=<?= (int)$a['produkt_id'] ?>"><?= h($produktName) ?></a><?php elseif ($produktName): ?><?= h($produktName) ?> <span class="muted" style="font-size:12px">(aus v3)</span><?php else: ?>–<?php endif; ?><?php if ($artKey !== 'none'): ?> <span title="<?= h($artHint) ?>"><?= bx_badge($artLabel, $artStil) ?></span><?php endif; ?></div></div>
    <div><div class="k muted">Rezeptur</div><div>
      <?php if ($rezeptur): ?>
        <a href="?p=rezeptur_detail&id=<?= (int)$rezeptur['id'] ?>"><?= h($rezeptur['nummer']) ?></a><?= $rezeptur['name'] ? ' · ' . h($rezeptur['name']) : '' ?>
        <?php if ($rezIstKopie): ?> <?= bx_badge('Auftrags-Kopie','info') ?><?php endif; ?>
        <?php if (has_role('admin')): ?>
          <form method="post" style="display:inline;margin-left:6px" <?= $rezIstKopie ? '' : 'onsubmit="return confirm(\'Eine eigene Rezeptur-Kopie NUR für diesen Auftrag anlegen? Das Kunden-Original bleibt unverändert. Danach kannst du die Rohstoffe neu zuordnen (frische Spec/CoA).\')"' ?>>
            <input type="hidden" name="aktion" value="rezeptur_auftrag_kopie">
            <button class="btn btn-ghost btn-sm" type="submit"><?= $rezIstKopie ? 'Auftrags-Rezeptur bearbeiten' : 'Für diesen Auftrag überarbeiten' ?></button>
          </form>
        <?php endif; ?>
      <?php elseif (has_role('admin')): // keine Rezeptur verknüpft -> Picker zum Verknüpfen ?>
        <?php if (isset($_GET['rezverk'])): ?><span class="badge-ok" style="padding:3px 8px;border-radius:6px;margin-right:6px">Rezeptur verknüpft.</span><?php endif; ?>
        <form method="post" class="bx-row" style="gap:6px;align-items:center;margin:0;flex-wrap:wrap">
          <input type="hidden" name="aktion" value="rezeptur_verknuepfen">
          <select name="rezeptur_id" class="rscombo" style="min-width:240px" required>
            <option value="">– Rezeptur wählen –</option>
            <?php foreach (all("SELECT id, nummer, name FROM rezeptur ORDER BY nummer DESC") as $rz): ?>
              <option value="<?= (int)$rz['id'] ?>"><?= h($rz['nummer']) ?><?= $rz['name'] ? ' · ' . h($rz['name']) : '' ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-ghost btn-sm" type="submit">Rezeptur verknüpfen</button>
        </form>
      <?php else: ?>–<?php endif; ?>
    </div></div>
    <div><div class="k muted">Aus Angebot</div><div><?php if ($a['angebot_id']): ?><a href="?p=angebot&id=<?= (int)$a['angebot_id'] ?>"><?= h($a['angebot_nr']) ?></a><?php else: ?>–<?php endif; ?></div></div>
    <?php if (!empty($a['kontingent_id'])): ?><div><div class="k muted">Herkunft</div><div><a href="?p=kontingente" title="Abruf aus einem Jahresabnahmevertrag"><?= bx_badge('aus Jahresvertrag','info') ?></a></div></div><?php endif; ?>
    <div><div class="k muted">Rechnung</div><div><?php if ($rechnung): ?><a href="/buchhaltung/?p=rechnung&id=<?= (int)$rechnung['id'] ?>"><?= h($rechnung['nummer']) ?></a> · <?= $eur($rechnung['brutto']) ?> · <?php
        $rst = $rechnungZs['status'] ?? $rechnung['status'];
        echo match ($rst) { 'bezahlt'=>bx_badge('bezahlt','ok'), 'teilbezahlt'=>bx_badge('teilbezahlt','info'), 'storniert'=>bx_badge('storniert','err'), default=>bx_badge('offen','warn') };
        if ($rst === 'teilbezahlt') echo ' <span class="muted" style="font-size:12px">offen ' . $eur($rechnungZs['rest']) . '</span>';
      ?> <span class="muted" style="font-size:12px">· in der Buchhaltung verwalten</span>
      <?php else: ?><a class="btn btn-ghost btn-sm" href="/buchhaltung/?p=rechnung_neu&auftrag=<?= (int)$id ?>">Rechnung in Buchhaltung erstellen</a><?php endif; ?></div></div>
  </div>
  <?php // Der Auftrag ist gesetzt (keine Bearbeitung von Menge/Preis/Verpackung hier). Änderungen laufen über
        // die jeweils zuständige Stelle: Rezeptur -> Rezeptur-Modul, Flaschen/Verpackung/Kapselgröße -> Vor-Produktion.
        if (has_role('admin') || has_role('sales') || has_role('production')): ?>
  <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line);display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <span class="muted" style="font-size:13px">Der Auftrag ist gesetzt. Änderungen laufen über:</span>
    <?php if ($rezeptur): ?><a class="btn btn-ghost btn-sm" href="?p=rezeptur_detail&id=<?= (int)$rezeptur['id'] ?>">Rezeptur bearbeiten</a><?php endif; ?>
    <?php if ($paVorbereitung): ?>
      <a class="btn btn-primary btn-sm" href="?p=produktion_vorbereitung&id=<?= (int)$pa['id'] ?>">Vor-Produktion öffnen (Flaschen &amp; Verpackung)</a>
    <?php else: ?>
      <a class="btn btn-ghost btn-sm" href="?p=produktion_vorbereitung">Vor-Produktion</a>
    <?php endif; ?>
    <span class="muted" style="font-size:12px">Menge und Preis sind fixiert; Flaschen, Verpackung &amp; Kapselgröße setzt du in der Vor-Produktion.</span>
  </div>
  <?php endif; ?>
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
  <?php // Status direkt hier am Fortschritt setzen – kein Reiter-Wechsel, funktioniert auch ohne Produktionsauftrag.
  if (has_role('admin') || has_role('sales') || has_role('production')):
      $stKurz = ['offen'=>$stLbl('offen'), 'in_produktion'=>$stLbl('in_produktion'), 'erledigt'=>$stLbl('erledigt'), 'versendet'=>$stLbl('versendet')]; ?>
  <?php if (isset($_GET['statusok'])): ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin:14px 0 0;display:inline-block">Status aktualisiert – der Kunde sieht es sofort.</div><?php endif; ?>
  <div class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">
    <span class="k muted">Status setzen</span>
    <form method="post" style="display:flex;flex-wrap:nowrap;gap:8px;align-items:center;margin:0">
      <input type="hidden" name="aktion" value="status_schnell">
      <select name="status" style="min-width:170px">
        <?php foreach ($stKurz as $k => $l): ?><option value="<?= $k ?>" <?= (string)$a['status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm" type="submit">Setzen</button>
    </form>
    <span class="muted" style="font-size:12px">„versendet" schließt den Auftrag ab (Kunden-Archiv). Menge und Preis sind fixiert; Stornieren im Reiter „Details".</span>
  </div>
  <?php endif; ?>
</div>

<div class="bx-panel" data-panel="produktion">
  <h2>Produktion &amp; Beschaffung</h2>
  <?php if (produkt_ist_handelsware((int)($a['produkt_id'] ?? 0))): ?>
  <div class="bx-panel" style="border-color:var(--gruen,#1D9E75);background:var(--panel-2);padding:10px 14px;margin:0 0 12px">
    <strong>Handelsware</strong> – dieses Produkt wird als Fertigware zugekauft &amp; weiterverkauft. Es wird <strong>kein Produktionsauftrag</strong> erzeugt; versendet wird aus dem Bestand.
  </div>
  <?php endif; ?>
  <div class="bx-grid">
    <div><div class="k muted">Herstellung</div><div>
      <?= $istFremd ? bx_badge('Fremdproduktion · fertige Bulkware zukaufen','info') : bx_badge('Eigenproduktion · aus Rohstoffen','ok') ?>
    </div></div>
    <?php if (has_role('admin') || has_role('production') || has_role('einkauf')): ?>
    <div><div class="k muted">Rohstoff/Bulk angekommen</div><div>
      <?php if ($rohAngDa): ?>
        <?= bx_badge('angekommen · ' . h(fmt_zeit((string)$rohAngDa, 'd.m.Y')), 'ok') ?>
      <?php else: ?>
        <form method="post" style="margin:0" title="Nutze das, wenn Ware (Zukauf/Bulk) da ist, der Kunde es aber noch nicht sieht.">
          <input type="hidden" name="aktion" value="rohstoff_angekommen"><input type="hidden" name="set" value="1">
          <button class="btn btn-ghost btn-sm" type="submit">Als angekommen markieren</button>
        </form>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
    <?php if ($pa): ?>
    <div><div class="k muted">Produktionsauftrag</div><div><a href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>"><?= h($pa['nummer']) ?></a> · <?= $paStatusBadge ?></div></div>
    <?php $mf = auftrag_mengenfortschritt((int)$a['id']); if (!empty($mf['hat']) && $mf['ziel'] > 0): ?>
    <div style="grid-column:1/-1"><div class="k muted">Produziert (Live)</div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:2px">
        <div style="flex:1;min-width:160px;height:10px;background:var(--panel-2,#eceef0);border-radius:6px;overflow:hidden">
          <div style="height:100%;width:<?= (int)$mf['prozent'] ?>%;background:var(--gruen)"></div>
        </div>
        <span style="white-space:nowrap"><strong><?= number_format((int)$mf['gebucht'],0,',','.') ?></strong> von <?= number_format((int)$mf['ziel'],0,',','.') ?> Packungen · <?= (int)$mf['prozent'] ?>%</span>
      </div>
    </div>
    <?php endif; ?>
    <div><div class="k muted">Material</div><div>
      <?= bereitschaft_badge($ber['status'] ?? '') ?>
      <?php if (($ber['status'] ?? '') === 'wartet'): ?> <a href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>" style="font-size:12px">was fehlt?</a><?php endif; ?>
    </div></div>
    <div><div class="k muted">Charge (Fertigware)</div><div>
      <?php if ($paChargen): ?>
        <?php foreach ($paChargen as $ch): ?><div><?= h((string)$ch['charge_nr']) ?><?php if (!empty($ch['mhd'])): ?> <span class="muted">· MHD <?= h(date('d.m.Y', strtotime((string)$ch['mhd']))) ?></span><?php endif; ?></div><?php endforeach; ?>
      <?php else: ?>
        <?= h($chargePlan) ?> <span class="muted">(geplant)</span>
      <?php endif; ?>
    </div></div>
    <div><div class="k muted">MHD (Fertigware)</div><div>
      <?php if ($paChargen && !empty($paChargen[0]['mhd'])): ?><?= h(date('d.m.Y', strtotime((string)$paChargen[0]['mhd']))) ?>
      <?php elseif (!$paChargen): ?>
        <?php if (isset($_GET['mhdok'])): ?><span class="badge-ok" style="padding:3px 8px;border-radius:6px;margin-right:6px">MHD gespeichert.</span><?php endif; ?>
        <form method="post" class="bx-row" style="gap:6px;align-items:center;margin:0">
          <input type="hidden" name="aktion" value="mhd_setzen">
          <input type="date" name="mhd" value="<?= h($mhdGesetzt) ?>" style="padding:5px 8px;border:1px solid var(--line);border-radius:7px">
          <button class="btn btn-ghost btn-sm" type="submit">MHD setzen</button>
          <span class="muted" style="font-size:12px"><?= $mhdGesetzt !== '' ? 'selbst festgelegt' : 'leer = Standard +18 M.' ?></span>
        </form>
      <?php else: ?><span class="muted">–</span><?php endif; ?>
    </div></div>
    <?php if ($paVorbereitung): ?>
    <div style="grid-column:1/-1"><div class="k muted">Zur Produktion freigeben</div>
      <?php if (isset($_GET['freigabeok'])): ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin:4px 0 8px;display:inline-block">Zur Produktion freigegeben.</div><?php endif; ?>
      <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;margin:0;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="pa_freigeben">
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Herstellung
          <select name="produktionsart" style="max-width:190px">
            <option value="fremd" <?= $istFremd ? 'selected' : '' ?>>Fremd (Zukauf)</option>
            <option value="eigen" <?= $istFremd ? '' : 'selected' ?>>Eigen</option>
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Produktionsmenge (Einheiten)
          <input type="number" name="menge_produktion" min="<?= (int)$freigabeBedarf ?>" step="1" value="<?= (int)$freigabeBedarf ?>" style="min-width:150px">
        </label>
        <button class="btn btn-primary btn-sm" type="submit">Zur Produktion freigeben</button>
      </form>
      <div class="muted" style="font-size:12px;margin-top:4px">Du kannst immer freigeben – offene Punkte (Etikett/Material) sind nur ein Hinweis; die Produktion wartet ggf. auf Material. Mehrmenge über den Bedarf wird als Bulk gebucht.</div>
    </div>
    <?php endif; ?>
    <?php if ($einlagerNoetig): ?>
    <div style="grid-column:1/-1"><div class="k muted">Einlagern</div><div>
      <form method="post" style="margin:0" onsubmit="return confirm('Fertige Ware in Lager 1 (Warenlager) buchen? Danach ist der Auftrag versandbereit; das Lager entscheidet beim Versand zwischen Kunde und Lager 2.');">
        <input type="hidden" name="aktion" value="einlagern_nachholen">
        <button class="btn btn-primary btn-sm" type="submit">In Lager 1 buchen</button>
      </form>
      <div class="muted" style="font-size:12px;margin-top:4px">Produktion ist fertig. Die Ware wird in <strong>Lager 1</strong> gebucht und der Auftrag wird <strong>versandbereit</strong>. Ob sie an den Kunden geht oder an Lager 2 (Fremdlager), entscheidet das Lager anschließend beim Versand.</div>
    </div></div>
    <?php endif; ?>
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
      <?php elseif ((string)$a['status'] !== 'storniert'): $nachtragLabel = auftrag_ist_fulfillment($id) ? 'Lager 2 (Fremdlager)' : 'Lager 1 (Warenlager)'; ?>
      <div class="muted" style="margin-bottom:6px">Kein Produktionsauftrag – der Auftrag ist bereits <strong><?= h($stText) ?></strong> (Altauftrag, der nie durch die Produktion lief).</div>
      <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;margin:0;flex-wrap:wrap" onsubmit="return confirm('Fertige Ware (<?= (int)$a['menge'] ?> Packungen) als Charge nachtragen und in <?= h($nachtragLabel) ?> einbuchen?');">
        <input type="hidden" name="aktion" value="charge_nachtragen_einlagern">
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Herstellung
          <select name="produktionsart" style="max-width:190px">
            <option value="fremd">Zukauf (Fremdproduktion)</option>
            <option value="eigen">Eigenproduktion</option>
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Chargennummer <span style="font-weight:400">(leer = automatisch)</span>
          <input type="text" name="charge_nr" placeholder="z. B. 2024-0815" style="min-width:160px">
        </label>
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">MHD <span style="font-weight:400">(leer = Standard +18 M.)</span>
          <input type="date" name="mhd">
        </label>
        <button class="btn btn-primary btn-sm" type="submit">Nachtragen &amp; in <?= h($nachtragLabel) ?> einbuchen</button>
      </form>
      <div class="muted" style="font-size:12px;margin-top:6px">Legt die fertige Ware als Charge an und bucht sie ins Lager (Fulfillment → Lager 2, Auftrag wird abgeschlossen). Chargennummer und MHD der Altware kannst du hier direkt eintragen (sonst Auto-Charge). Für Altaufträge ohne Produktionsauftrag.</div>
      <?php else: ?>
      <div class="muted">Auftrag ist storniert – nichts einzulagern.</div>
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
  <?php elseif (!empty($lt['versendet_am'])): ?>
    <div style="margin-bottom:10px"><?= bx_badge('Probe beim Labor', 'warn') ?> <span class="muted">versendet am <?= h(date('d.m.Y', strtotime((string)$lt['versendet_am']))) ?> – wird automatisch „abgeschlossen", sobald ein freigegebener Laborbericht vorliegt.</span></div>
  <?php else: ?>
    <div style="margin-bottom:10px"><?= bx_badge('läuft', 'warn') ?> <span class="muted">Probe beim Drittlabor – wird automatisch „abgeschlossen", sobald ein freigegebener Laborbericht vorliegt.</span></div>
  <?php endif; ?>
  <div class="muted" style="font-size:12px">Laborbericht hochladen &amp; für den Kunden freigeben unter <a href="?p=laboranalysen">Labortests</a> (oder direkt an diesem Auftrag). Der Kunde sieht den Punkt „Externer Labortest" im Bestell-Verlauf.</div>
</div>
<?php endif; ?>

<?php // Zahlung / Alt-Rechnung (Altsystem) ist im Reiter „Preise & Rechnung" ausgeblendet – Rechnungen und
      // Zahlungen laufen über die Buchhaltung. Markup bleibt (if(false)) für Alt-Aufträge erhalten, falls nötig.
      $hatV4Rechnung = (bool)$rechnung; ?>
<?php if (false): ?>
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
<?php endif; // Ende ausgeblendetes Alt-Rechnung/Zahlung-Panel ?>

<?php if ($istAdmin):
  // Marge auf Basis der günstigsten hinterlegten Rohstoff-EK-Preise (nur Material). Fremdwährungen werden
  // nicht automatisch summiert (kein Kurs hinterlegt); fehlt bei einem Rohstoff der EK, ist die Marge unvollständig.
  $matKostenEur = 0.0; $matHatNichtEur = false; $matVollstaendig = (bool)$ekBedarf;
  foreach ($ekBedarf as $bd) {
      $ao = $bd['angebote'][0] ?? null;
      if (!$ao) { $matVollstaendig = false; continue; }
      if (strtoupper((string)($ao['waehrung'] ?: 'EUR')) !== 'EUR') { $matHatNichtEur = true; $matVollstaendig = false; continue; }
      $matKostenEur += (float)$ao['preis'] * (float)$bd['benoetigt'];
  }
  $mMenge = (int)$a['menge']; $mNetto = (float)$a['gesamt_netto']; $mVk = (float)$a['vk_stueck'];
  $mEkProP = $mMenge > 0 ? $matKostenEur / $mMenge : 0.0;
  $mMarge  = $mNetto - $matKostenEur; $mMargeProz = $mNetto > 0 ? ($mMarge / $mNetto) * 100 : 0.0;
?>
<?php if (isset($_GET['expressfehler'])): ?><div class="bx-panel" data-panel="preise" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)$_GET['expressfehler']) ?></div><?php endif; ?>
<div class="bx-panel" data-panel="preise">
  <h2 style="margin-top:0">Kalkulation (Marge) <span class="muted" style="font-weight:normal;font-size:13px">· nur intern (Admin)</span></h2>
  <div class="bx-grid">
    <div><div class="k muted">VK je Packung (netto)</div><div><?= $eur($mVk) ?></div></div>
    <div><div class="k muted">Menge</div><div><?= number_format($mMenge,0,',','.') ?> Packungen</div></div>
    <div><div class="k muted">Umsatz netto</div><div><?= $eur($mNetto) ?></div></div>
    <?php if ($matVollstaendig): ?>
    <div><div class="k muted">Materialkosten (EK, günstigste)</div><div><?= $eur($matKostenEur) ?> <span class="muted" style="font-size:12px">· <?= $eur($mEkProP) ?>/Packung</span></div></div>
    <div><div class="k muted">Marge (nach Material)</div><div><strong><?= $eur($mMarge) ?></strong> <span class="muted" style="font-size:12px">· <?= number_format($mMargeProz,1,',','.') ?>%</span></div></div>
    <?php else: ?>
    <div style="grid-column:1/-1"><div class="k muted">Marge</div><div class="muted"><?= $matHatNichtEur ? 'Teils Fremdwährungs-EK hinterlegt – Materialkosten lassen sich nicht automatisch summieren.' : (!$ekBedarf ? 'Keine Rohstoff-EK-Preise (Zukauf oder Rezeptur ohne Zutaten) – Materialkosten nicht ermittelbar.' : 'Für nicht alle Rohstoffe ist ein EK-Preis hinterlegt – Marge unvollständig.') ?></div></div>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Richtwert auf Basis der günstigsten hinterlegten Rohstoff-EK-Preise – nur Material, ohne Lohn, Verpackung und Gemeinkosten.</p>
</div>

<div class="bx-panel" data-panel="preise">
  <h2 style="margin-top:0">Lieferanten-Preise zu diesem Auftrag <span class="muted" style="font-weight:normal;font-size:13px">· nur intern (Admin)</span></h2>
  <?php if (!$pa): ?>
    <div class="muted">Kein Produktionsauftrag – Rohstoffbedarf nicht berechenbar.</div>
  <?php elseif (!$ekBedarf): ?>
    <div class="muted">Keine Rohstoffe im Bedarf (Zukauf oder Rezeptur ohne Zutaten).</div>
  <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th class="bx-num">benötigt</th><th>Lieferant – EK je Einheit (günstigste zuerst)</th></tr></thead>
      <tbody>
      <?php foreach ($ekBedarf as $bd): $nz = fn($x,$n=3)=>rtrim(rtrim(number_format((float)$x,$n,',','.'),'0'),','); ?>
        <tr>
          <td><a class="kundenlink" href="?p=rohstoff&id=<?= (int)$bd['item_id'] ?>&tab=ek"><?= h($bd['name']) ?></a></td>
          <td class="bx-num"><?= $nz($bd['benoetigt']) ?> <?= h($bd['einheit']) ?><?php if ($bd['fehlt'] > 0.0001): ?><br><span style="color:#8f231b;font-size:12px">fehlt <?= $nz($bd['fehlt']) ?></span><?php else: ?><br><span class="bx-ok" style="font-size:12px">auf Lager</span><?php endif; ?></td>
          <td><?php if (!$bd['angebote']): ?><span class="muted">kein EK-Preis hinterlegt</span>
              <?php else: $bi = 0; foreach ($bd['angebote'] as $ao): ?>
                <div style="<?= $bi === 0 ? 'font-weight:600' : '' ?>"><?= h($ao['firma'] ?: '–') ?>: <?= $nz($ao['preis'], 4) ?> <?= h($ao['waehrung'] ?: 'EUR') ?><?= (float)$ao['menge_ab'] > 0 ? ' <span class="muted">(ab ' . $nz($ao['menge_ab']) . ')</span>' : '' ?><?= $bi === 0 ? ' <span class="muted">· günstigste</span>' : '' ?></div>
              <?php $bi++; endforeach; endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>

  <?php if ($zukaufPreise): $nz = fn($x,$n=3)=>rtrim(rtrim(number_format((float)$x,$n,',','.'),'0'),','); $VZ = versandart_liste(); ?>
  <h3 style="margin:20px 0 8px;font-size:14px;font-weight:600">Fertigprodukt (Bulk) – Zukaufpreise</h3>
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
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Reine Preisübersicht (intern). Bestellungen laufen über den Einkauf, nicht von hier.</p>
</div>
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
  <h2 style="margin-top:0">Dokumente &amp; Downloads</h2>
  <div class="bx-row" style="gap:10px;flex-wrap:wrap">
    <a class="btn btn-ghost btn-sm" href="?p=auftrag_pdf&id=<?= (int)$a['id'] ?>" target="_blank" rel="noopener">Auftragsbestätigung (PDF)</a>
    <?php if (!empty($a['produkt_id'])): ?>
      <a class="btn btn-ghost btn-sm" href="?p=produkt_pib&id=<?= (int)$a['produkt_id'] ?>" target="_blank" rel="noopener">Produktinformationsblatt (PIB)</a>
    <?php endif; ?>
    <?php if ($pa): ?>
      <a class="btn btn-ghost btn-sm" href="?p=produktionsauftrag_pdf&id=<?= (int)$pa['id'] ?>" target="_blank" rel="noopener">Laufzettel (Produktionsauftrag)</a>
      <a class="btn btn-ghost btn-sm" href="?p=produktion_bericht&id=<?= (int)$pa['id'] ?>" target="_blank" rel="noopener">Produktionsbericht</a>
    <?php endif; ?>
  </div>
  <?php if (!$pa): ?><p class="muted" style="font-size:12px;margin:10px 0 0">Laufzettel und Produktionsbericht erscheinen, sobald ein Produktionsauftrag existiert.</p><?php endif; ?>
</div>

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

<?php // Der Auftrag ist gesetzt: Menge/Preis/Verpackung/Kapselgröße werden hier NICHT mehr bearbeitet.
      // Rezeptur -> Rezeptur-Modul, Flaschen/Verpackung/Kapsel -> Vor-Produktion (Links im Reiter „Details").
      // Am Auftrag selbst sind nur noch Stornieren und Löschen möglich (Admin). ?>
<?php if (has_role('admin') && (string)$a['status'] !== 'versendet'): ?>
<div class="bx-panel" data-panel="details" style="border-color:#e6c4c0">
  <h2 style="margin-top:0">Auftrag stornieren / löschen</h2>
  <?php if ((string)$a['status'] !== 'storniert'): ?>
  <p class="muted" style="margin-top:0">Stornieren setzt den Auftrag auf <strong>storniert</strong> und storniert eine offene Rechnung per Gutschrift. Menge und Preis bleiben zur Nachvollziehbarkeit erhalten.</p>
  <form method="post" style="margin:0 0 16px" onsubmit="return confirm('Auftrag <?= h($a['nummer']) ?> stornieren? Eine offene Rechnung wird per Gutschrift storniert.');">
    <input type="hidden" name="aktion" value="auftrag_stornieren">
    <button class="btn btn-ghost btn-sm" type="submit" data-busy="Storniere…">Auftrag stornieren</button>
  </form>
  <?php endif; ?>
  <p class="muted" style="margin-top:0">Löscht diese Auftragsbestätigung samt Produktionsauftrag und (unbezahlter) Rechnung. Das zugehörige Angebot wird wieder <strong>offen</strong>, und Sie springen zurück zur Anfrage, um es anzupassen oder neu zu senden.</p>
  <form method="post" style="margin:0" onsubmit="return confirm('Auftragsbestätigung <?= h($a['nummer']) ?> löschen? Produktionsauftrag und unbezahlte Rechnung werden entfernt; das Angebot wird wieder offen.');">
    <input type="hidden" name="aktion" value="auftrag_zurueck">
    <button class="btn btn-danger" type="submit" data-busy="Lösche…">Löschen &amp; zurück zur Anfrage</button>
  </form>
</div>
<?php endif; ?>
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
