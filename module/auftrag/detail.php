<?php
// Auftrag (Auftragsbestätigung) – Ansicht + Status
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/anfrage_ui.php';

$id = (int)($_GET['id'] ?? 0);

// Pulver/Granulat: Mengen werden in KILOGRAMM eingegeben, intern aber in GRAMM geführt (wie Charge,
// Reservierung, Produktion, Wareneingang). Ist der Auftrag eine Gewichtsform, wird die kg-Eingabe × 1000
// gerechnet. Stück-Formen (Kapsel/Tablette …) bleiben 1:1.
$auftragIstGewicht = function(int $aid): bool {
    if ($aid <= 0 || !function_exists('form_groessen_einheit')) return false;
    $f = (string) scalar("SELECT COALESCE(rz.darreichungsform,'') FROM auftrag a
                          LEFT JOIN produkt p ON p.id=a.produkt_id
                          LEFT JOIN rezeptur rz ON rz.id=COALESCE(NULLIF(a.rezeptur_id,0), p.rezeptur_id)
                          WHERE a.id=?", [$aid]);
    return form_groessen_einheit($f) === 'g';
};
// de-formatierte Eingabe („1.860" / „588,69") -> Basiszahl; bei Gewicht kg -> g.
$mengeInBasis = function(string $raw, bool $gewicht): float {
    $v = (float) str_replace(['.', ' ', ','], ['', '', '.'], trim($raw));
    return $gewicht ? $v * 1000.0 : $v;
};

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

// Zukauf-Art am Produktionsauftrag setzen (bulk = Bulk/Pulver selbst abfüllen | fertig = fertige Ware). Nur Admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'zukauf_art_setzen') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id); exit; }
    $art = ($_POST['zukauf_art'] ?? '') === 'fertig' ? 'fertig' : 'bulk';
    q("UPDATE produktionsauftrag SET zukauf_art=? WHERE auftrag_id=? AND status<>'storniert'", [$art, $id]);
    // Nacht 2a: fertige Ware -> kurzer Weg (Bereitstellen -> QS -> Freigaben), Bulk -> mit Abfüllen/Etikettieren.
    foreach (all("SELECT id FROM produktionsauftrag WHERE auftrag_id=? AND status NOT IN ('storniert','erledigt')", [$id]) as $zp) produktion_zukauf_weg_anpassen((int)$zp['id']);
    if (function_exists('bedarf_bump')) bedarf_bump();   // Material-/Bedarf-Cache neu rechnen (Verpackung zählt/zählt nicht)
    header('Location: ?p=auftrag&id=' . $id . '&zkartok=1'); exit;
}

// Kundenspezifischen Produktnamen direkt am Auftrag setzen (je Produkt+Kunde dieses Auftrags).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'kundenname_setzen') {
    $au = one("SELECT produkt_id, kunde_id FROM auftrag WHERE id=?", [$id]);
    if ($au && (int)($au['produkt_id'] ?? 0) > 0 && (int)($au['kunde_id'] ?? 0) > 0)
        produkt_kundenname_setzen((int)$au['produkt_id'], (int)$au['kunde_id'], trim((string)($_POST['kn_name'] ?? '')));
    header('Location: ?p=auftrag&id=' . $id . '&knok=1'); exit;
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
    $mpRaw = trim((string)($_POST['menge_produktion'] ?? ''));
    $mp  = $mpRaw !== '' ? (int) round($mengeInBasis($mpRaw, $auftragIstGewicht($id))) : null;   // Pulver: kg -> g
    $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
    $r = produktionsauftrag_freigeben($paid, $art, $mp, $wer);
    header('Location: ?p=auftrag&id=' . $id . (!empty($r['ok']) ? '&freigabeok=1' : '&expressfehler=' . urlencode($r['fehler'] ?? 'Freigabe fehlgeschlagen.'))); exit;
}
// Rückabwicklung Fremd -> Eigen: Lieferant kann/will doch nicht. Storniert die Bulk-Bestellung (+ informiert
// den Lieferanten) und stellt auf Eigenproduktion um. Ergebnis zeigt die Produktionsauftrag-Seite.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'rueckabwicklung') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $paid = (int) scalar("SELECT id FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$id]);
    if (!$paid) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Kein Produktionsauftrag zum Rückabwickeln.')); exit; }
    $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
    $res = produktion_rueckabwicklung_eigen($paid, $wer);
    header('Location: ?p=produktionsauftrag&id=' . $paid . '&' . ($res['ok'] ? 'rueck=' : 'rueckfehler=') . rawurlencode($res['msg'])); exit;
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

// Nachricht an den Kunden zu diesem Auftrag (Antwort auf eine Portal-Rückfrage, Report 5): speichern, Mail an den Kunden, im Portal sichtbar.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'kunde_nachricht') {
    $au = one("SELECT nummer, kunde_id FROM auftrag WHERE id=?", [$id]);
    $txt = trim((string)($_POST['text'] ?? ''));
    if ($au && (int)$au['kunde_id'] > 0 && $txt !== '') {
        $cu = function_exists('current_user') ? current_user() : null;
        kunde_nachricht_senden((int)$au['kunde_id'], $id, 0, 'team', $txt, (string)($cu['name'] ?? ''));
        kunde_nachricht_gelesen((int)$au['kunde_id'], $id, 0, 'team');
        if (mail_bereit()) { $kidM = (int)$au['kunde_id']; $nrM = (string)$au['nummer'];
            nach_antwort(fn() => mail_kunde_einfach($kidM, 'Antwort zu Ihrer Bestellung ' . $nrM, "Wir haben Ihnen zu Ihrer Bestellung " . $nrM . " geantwortet:\n\n" . $txt, 'bestellung&aid=' . $id)); }
    }
    header('Location: ?p=auftrag&id=' . $id . '&knok=1#kundennachricht'); exit;
}

// Voraussichtlicher Liefertermin (Zeitraum) – der Kunde sieht ihn im Portal-Fortschritt (Report 4, 03.11.2026).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'liefertermin_setzen') {
    if (!(has_role('admin') || has_role('sales') || has_role('production'))) { header('Location: ?p=auftrag&id=' . $id); exit; }
    $dt = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? (string)$_POST[$k] : null;
    $von = $dt('liefertermin_von'); $bis = $dt('liefertermin_bis');
    if ($von && $bis && $bis < $von) [$von, $bis] = [$bis, $von];
    $vor = one("SELECT liefertermin_von, liefertermin_bis FROM auftrag WHERE id=?", [$id]) ?: [];
    q("UPDATE auftrag SET liefertermin_von=?, liefertermin_bis=? WHERE id=?", [$von, $bis, $id]);
    aenderung_protokollieren('auftrag', $id, $vor, ['liefertermin_von' => $von, 'liefertermin_bis' => $bis], 'auftrag');
    header('Location: ?p=auftrag&id=' . $id . '&ltok=1'); exit;
}

// Verpackung (Behälter) am AUFTRAG setzen – auftrag.verpackung_id hat Vorrang vor dem Produkt (auftrag_braucht_etikett,
// PIB, Etikettmaß). Wichtig für alte v3-Aufträge ohne Produkt-Verpackung: hier lässt sich der Behälter auswählen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'verpackung_setzen') {
    if (!(has_role('admin') || has_role('sales'))) { header('Location: ?p=auftrag&id=' . $id); exit; }
    $vp = (int)($_POST['verpackung_id'] ?? 0);
    if ($vp > 0 && !scalar("SELECT id FROM item WHERE id=? AND kategorie='verpackung'", [$vp])) $vp = 0;
    q("UPDATE auftrag SET verpackung_id=? WHERE id=?", [$vp ?: null, $id]);
    if (function_exists('bedarf_bump')) bedarf_bump();            // Material-/Bedarf-Cache neu rechnen
    header('Location: ?p=auftrag&id=' . $id . '&vpok=1'); exit;
}

// Punkt 6 / Blocker 1: Bedruckten Behälter als eigenen Lagerartikel je (Produkt + Kunde des Auftrags) anlegen
// (wie das Kundenetikett) und diesem Auftrag zuweisen. Ein Katalogprodukt behält den neutralen Behälter;
// getrennt vom Standard-Beutel, einkaufbar, bei Nachbestellung wiederverwendet.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'verpackung_kundenspezifisch') {
    if (!(has_role('admin') || has_role('sales'))) { header('Location: ?p=auftrag&id=' . $id); exit; }
    // Gleiche Logik wie bei der Annahme: bedruckten Behälter anlegen (Name einmalig, Artikelnummer, Lieferant, EK-Staffel)
    // und an Auftrag UND Produktionsauftrag binden.
    if (function_exists('auftrag_verpackung_kundenspezifisch_binden')) auftrag_verpackung_kundenspezifisch_binden($id);
    if (function_exists('bedarf_bump')) bedarf_bump();
    header('Location: ?p=auftrag&id=' . $id . '&vpok=1'); exit;
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
    if (!empty($r['ok']) && !empty($r['sichtbar'])) {
        $warSichtbar = (int) scalar("SELECT kunde_sichtbar FROM beleg WHERE id=?", [$rid]) === 1;
        q("UPDATE beleg SET kunde_sichtbar=1 WHERE id=?", [$rid]);
        if (!$warSichtbar && mail_bereit()) nach_antwort(fn() => mail_kunde_rechnung_freigegeben($rid));   // Kunde: Rechnung liegt im Portal
    }
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
        // Fertig/versendet -> Produktion mit abschließen, damit kein Rohstoffbedarf mehr entsteht (ohne Lagerbuchung).
        if (in_array($neu, ['erledigt','versendet'], true)) {
            $closed = auftrag_produktion_schliessen($id);
            if ($closed > 0) log_aktivitaet('auftrag', $id, 'team', 'Produktion mit abgeschlossen (' . $closed . ' Produktionsauftrag/-aufträge) – Rohstoffbedarf gestoppt.', 'status', 'auftrag', $id);
        }
    }
    header('Location: ?p=auftrag&id=' . $id . '&statusok=1'); exit;
}
// Fortschritt direkt auf EINE Stufe setzen (alle Phasen, nicht nur die groben Status). Setzt den Phasen-Override
// und fuehrt den groben Auftragsstatus passend mit (offen/in_produktion/erledigt/versendet), damit die uebrige
// Logik (Listen, Kundenportal) konsistent bleibt. „Versendet"/letzte Stufe schliesst den Auftrag ab.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'phase_setzen') {
    if (!(has_role('admin') || has_role('sales') || has_role('production'))) { header('Location: ?p=auftrag&id=' . $id); exit; }
    $ff     = auftrag_ist_fulfillment($id);
    $phasen = auftrag_phasen($ff);
    $idx    = (int)($_POST['phase'] ?? -1);
    if ($idx < 0 || $idx >= count($phasen)) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Ungültige Stufe.')); exit; }
    $last   = count($phasen) - 1;                       // Eingelagert (FF) bzw. Versendet (sonst)
    // Groben Auftragsstatus passend zur gewaehlten Stufe mitfuehren (erlaubt auch das Zurueckstufen, wie die
    // alte Status-Auswahl). Override als „mindestens diese Stufe" (0 = Basis -> kein Override, NULL).
    $neuStatus = $idx >= $last ? 'versendet' : ($idx >= 6 ? 'erledigt' : ($idx >= 3 ? 'in_produktion' : 'offen'));
    $ovVal = $idx > 0 ? $idx : null;
    $alt = one("SELECT status, phase_override FROM auftrag WHERE id=?", [$id]);
    q("UPDATE auftrag SET phase_override=?, status=?, status_datum=CURDATE() WHERE id=?", [$ovVal, $neuStatus, $id]);
    if ((int)($alt['phase_override'] ?? -1) !== (int)($ovVal ?? -1) || (string)($alt['status'] ?? '') !== $neuStatus)
        log_aktivitaet('auftrag', $id, 'team', 'Fortschritt gesetzt: ' . ($phasen[$idx] ?? ('Stufe ' . $idx)) . ' (Status ' . $neuStatus . ')', 'status', 'auftrag', $id);
    // Fertig/versendet -> Produktion mit abschließen (Rohstoffbedarf stoppen, ohne Lagerbuchung).
    if (in_array($neuStatus, ['erledigt','versendet'], true) && (string)($alt['status'] ?? '') !== $neuStatus) {
        $closed = auftrag_produktion_schliessen($id);
        if ($closed > 0) log_aktivitaet('auftrag', $id, 'team', 'Produktion mit abgeschlossen (' . $closed . ') – Rohstoffbedarf gestoppt.', 'status', 'auftrag', $id);
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
        $befund = in_array($_POST['befund'] ?? '', ['bestanden','auffaellig','unklar'], true) ? (string)($_POST['befund'] ?? '') : 'unklar';
        if (move_uploaded_file($_FILES['dok']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,dok_datum,charge_nr,befund,kunde_sichtbar,hochgeladen_von)
               VALUES ('auftrag',?,'analyse',?,?,?,?,?,?,?,'team')",
              [$id, trim((string)($_POST['titel'] ?? '')) ?: null, $fn, $orig, $datum, $charge, $befund, isset($_POST['kunde_sichtbar']) ? 1 : 0]);
            log_aktivitaet('auftrag', $id, 'team', 'Laboranalyse/CoA hochgeladen' . ($charge ? ' (Charge ' . $charge . ')' : '') . ': ' . mb_substr($orig, 0, 100), 'coa', 'auftrag', $id);
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

// Stornierten Auftrag ENDGÜLTIG löschen (Aufräumen) – nur Admin, nur wenn storniert. Entfernt den Auftrag
// samt Produktionsauftrag/Schritten/Chargen und (stornierter) Rechnung sauber (auftrag_komplett_loeschen);
// Bestellungen bleiben erhalten (nur entkoppelt). Das Angebot bleibt UNVERÄNDERT (anders als „zurück zur
// Anfrage"). Geblockt bei bezahlter Rechnung (erst in der Buchhaltung klären).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'auftrag_hart_loeschen') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $st = (string) scalar("SELECT status FROM auftrag WHERE id=?", [$id]);
    if ($st !== 'storniert') { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur stornierte Aufträge können hier endgültig gelöscht werden.')); exit; }
    if ((int) scalar("SELECT COUNT(*) FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status='bezahlt'", [$id]) > 0) {
        header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Zu diesem Auftrag gibt es eine bezahlte Rechnung – bitte erst in der Buchhaltung klären.')); exit;
    }
    $nr = (string) scalar("SELECT nummer FROM auftrag WHERE id=?", [$id]);
    auftrag_komplett_loeschen($id);
    header('Location: ?p=auftraege&geloescht=1&nr=' . rawurlencode($nr)); exit;
}

// Fertige Bulkware aus einer konkreten Bestellung (auch noch nicht geliefert) oder Charge für diesen Auftrag
// reservieren – dritter Weg neben Eigenproduktion/Fremdbestellung. Keine neue Lieferantenbestellung.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'bulk_reservieren') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    [$qTyp, $qId] = array_pad(explode(':', (string)($_POST['quelle'] ?? ''), 2), 2, '');
    $menge = $mengeInBasis((string)($_POST['menge'] ?? '0'), $auftragIstGewicht($id));   // Pulver: kg -> g
    $r = bulk_reservieren($id, (string)$qTyp, (int)$qId, $menge);
    header('Location: ?p=auftrag&id=' . $id . (!empty($r['ok'])
        ? '&bulkres=1' . (!empty($r['warnung']) ? '&bulkwarn=' . rawurlencode((string)$r['warnung']) : '')
        : '&expressfehler=' . urlencode($r['fehler'] ?? 'Reservierung nicht möglich.'))); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'bulk_res_storno') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id); exit; }
    bulk_reservierung_storno((int)($_POST['res_id'] ?? 0), $id);
    header('Location: ?p=auftrag&id=' . $id . '&bulkres=1'); exit;
}
// Lagerware (konkrete Charge) FEST diesem Auftrag zuweisen – der Kunde bekommt genau diese Kapseln. Teilmenge
// erlaubt (Charge wird gesplittet); dieselbe Charge kann so mehreren Aufträgen zugeteilt werden.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'charge_zuweisen') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $menge = $mengeInBasis((string)($_POST['menge'] ?? '0'), $auftragIstGewicht($id));   // Pulver: kg -> g
    $r = charge_auftrag_zuweisen((int)($_POST['charge_id'] ?? 0), $id, $menge);
    header('Location: ?p=auftrag&id=' . $id . (!empty($r['ok'])
        ? '&lwzu=1' . (!empty($r['warnung']) ? '&bulkwarn=' . rawurlencode((string)$r['warnung']) : '')
        : '&expressfehler=' . urlencode($r['fehler'] ?? 'Zuweisung nicht möglich.'))); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'charge_loesen') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id); exit; }
    charge_auftrag_loesen((int)($_POST['charge_id'] ?? 0), $id);
    header('Location: ?p=auftrag&id=' . $id . '&lwzu=1'); exit;
}
// Punkt 1: „Reservieren" und „Lagerware zuweisen" in EINEM Schritt. Eine Quelle-Auswahl, eine Mengeneingabe.
// Quelle "bestellung:<bp_id>" -> reservieren (auch unterwegs), "charge:<id>" -> konkrete Charge fest zuweisen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'material_binden') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    [$qTyp, $qId] = array_pad(explode(':', (string)($_POST['quelle'] ?? ''), 2), 2, '');
    $menge = $mengeInBasis((string)($_POST['menge'] ?? '0'), $auftragIstGewicht($id));   // Pulver: kg -> g
    if ($qTyp === 'bestellung') {
        $r = bulk_reservieren($id, 'bestellung', (int)$qId, $menge);
        header('Location: ?p=auftrag&id=' . $id . (!empty($r['ok'])
            ? '&bulkres=1' . (!empty($r['warnung']) ? '&bulkwarn=' . rawurlencode((string)$r['warnung']) : '')
            : '&expressfehler=' . urlencode($r['fehler'] ?? 'Reservierung nicht möglich.'))); exit;
    }
    if ($qTyp === 'charge') {
        $r = charge_auftrag_zuweisen((int)$qId, $id, $menge);
        header('Location: ?p=auftrag&id=' . $id . (!empty($r['ok'])
            ? '&lwzu=1' . (!empty($r['warnung']) ? '&bulkwarn=' . rawurlencode((string)$r['warnung']) : '')
            : '&expressfehler=' . urlencode($r['fehler'] ?? 'Zuweisung nicht möglich.'))); exit;
    }
    header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Keine Quelle gewählt.')); exit;
}

$a = $id ? one("SELECT a.*, k.firma AS kunde_firma, p.name AS produkt_name, pkn.name AS produkt_kundenname, ang.nummer AS angebot_nr
                FROM auftrag a
                LEFT JOIN kunden k ON k.id=a.kunde_id
                LEFT JOIN produkt p ON p.id=a.produkt_id
                LEFT JOIN produkt_kunde_name pkn ON pkn.produkt_id=p.id AND pkn.kunde_id=a.kunde_id
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
$einhProP  = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
if ($einhProP <= 0) $einhProP = (int)($a['stueck'] ?? 0);   // Fallback: Stück je Packung liegt am Auftrag (v3-Import)
$gesamtStk = $einhProP > 0 ? (int)$a['menge'] * $einhProP : 0;
// Schon als Fertigware produzierte Packungen abziehen -> die Vorbelegungen (Freigeben/Reservieren/Zuweisen)
// rechnen mit dem RESTbedarf, nicht der vollen Auftragsmenge. Bsp. AB-3286: 4.101 von 6.000 fertig ->
// Rest 1.899 Pkg = 588 kg Bulk, der ggf. schon als Lagerware zugewiesen/reserviert ist (-> Zuweisen 0).
$produziertPkg = $pa ? (int) round(produktion_gebucht((int)$pa['id'])) : 0;
$restPkg   = max(0, (int)$a['menge'] - $produziertPkg);
$restStk   = $einhProP > 0 ? $restPkg * $einhProP : $restPkg;   // Restbedarf in Bulk-Einheit (g bei Pulver)
$freigabeBedarf = $pa ? max(0, (int) produktion_stueck_je_packung($pa)) * $restPkg : 0;
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
// Pulver/Granulat: „Stück je Packung" ist in Wahrheit die Füllmenge in Gramm je Packung -> nach Gewicht anzeigen.
$istFuellG = function_exists('form_groessen_einheit') && form_groessen_einheit((string)($rezeptur['darreichungsform'] ?? '')) === 'g';
// Bulk-Mengen im Reiter Produktion (Freigeben/Reservieren/Zuweisen): Pulver wird in GRAMM geführt (wie Charge
// und Reservierung), nicht in „Stück/Einheiten". Label = „g" + lesbarer kg/t-Hinweis; Stück-Formen bleiben „Stück".
$bulkEinh = $istFuellG ? 'kg' : 'Stk';                       // Eingabe-Einheit der Bulk-Felder (Pulver = kg)
$bulkLes  = fn($v) => $istFuellG && function_exists('gewicht_lesbar') ? ' <span style="font-weight:normal">(' . gewicht_lesbar((float)$v, 'g') . ')</span>' : '';
$bulkMng  = fn($v) => $istFuellG && function_exists('gewicht_lesbar') ? gewicht_lesbar((float)$v, 'g') : number_format((int)$v, 0, ',', '.') . ' Stk';
// Prefill-Wert eines Bulk-Mengenfelds: Pulver in kg (g/1000, lesbar), sonst Stück (ganzzahlig mit Tausenderpunkt).
$bulkInput = fn($g) => $istFuellG ? rtrim(rtrim(number_format((float)$g / 1000, 3, ',', '.'), '0'), ',') : number_format((int)$g, 0, ',', '.');
$bulkTaus  = $istFuellG ? '' : 'data-tausender';               // Tausender-JS ist ganzzahlig -> bei kg (Dezimal) weglassen
$bulkInM   = $istFuellG ? 'decimal' : 'numeric';
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
// Bulk-Reservierung (dritter Weg): mögliche Quellen (Bestellungen/Chargen des Bulk-Artikels) + bestehende Reservierungen.
$bulkQ   = $istAdmin ? bulk_quellen_fuer_auftrag($id) : ['item'=>0, 'bestellungen'=>[], 'chargen'=>[]];
$bulkRes = $istAdmin ? auftrag_bulk_reservierungen($id) : [];
// Lagerware fest zuweisen (Charge -> Auftrag): freie Quell-Chargen + bereits zugewiesene Chargen.
$lwQ  = $istAdmin ? auftrag_lagerware_quellen($id) : ['item'=>0, 'chargen'=>[]];
$lwZu = $istAdmin ? auftrag_lagerware_zugewiesen($id) : [];

render_header('auftraege', $a['nummer']);
$kopfProdukt = trim((string)($a['produkt_bezeichnung'] ?? '')) ?: (string)($a['produkt_name'] ?? '');
bx_head($a['nummer'] . ($kopfProdukt !== '' ? ' · ' . $kopfProdukt : ''), 'Auftragsbestätigung',
    pdf_btn('?p=auftrag_pdf&id=' . (int)$a['id'], 'PDF / Drucken', false, 'Auftragsbestätigung als PDF öffnen/drucken')
    . ($pa ? ' ' . bx_btn('Zum Produktionsauftrag ' . (string)$pa['nummer'], '?p=produktionsauftrag&id=' . (int)$pa['id'], 'ghost') : '')
    . ' ' . bx_btn('Zurück zur Liste', '?p=auftraege', 'ghost'));
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['importiert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Auftrag aus Angebot importiert – Rezeptur/Produkt angelegt (falls neu), Angebot/Rechnung angehängt. Bitte Werte gegenprüfen.</div>';

// Kacheln bleiben in EINER Reihe und werden bei Enge KLEINER (kein Umbruch, kein Scroll).
// Kennzahl-Kacheln als responsives Raster (Aufräumen 12): nebeneinander, umbrechen in neue ZEILEN statt Beschriftungen
// abzuschneiden („versen…", „Menge (P…"); Labels bleiben einzeilig und vollständig.
echo '<style>.bx-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px}'
   . '.bx-cards .bx-card{min-width:0;padding:10px 12px}'
   . '.bx-cards .k{white-space:nowrap}'
   . '.bx-cards .v{font-size:15px;line-height:1.4;overflow-wrap:anywhere}'
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
// Die Kennzahl-Kacheln stehen jetzt IM Reiter „Details" (siehe unten, data-panel="details"), nicht mehr
// über den Reitern. Als Closure vorbereitet, damit sie dort an der richtigen Stelle gerendert werden.
$designLbl = auftrag_design_label((int)$a['id']);   // „Etikett" bzw. „Beutel-Design" (vollbedruckter Beutel)
$kachelnRender = function() use ($stBg, $stFg, $stText, $a, $glasName, $fehltCard, $einhProP, $gesamtStk, $groesseLbl, $istFremd, $etikettBadge, $eur, $istFuellG, $designLbl) {
    echo '<div class="bx-cards" style="margin-bottom:16px">';
    echo '<div class="bx-card bx-card-status" title="Status" style="background:' . $stBg . ';color:' . $stFg . ';border:1px solid rgba(0,0,0,.15)"><div class="v" style="color:' . $stFg . '">' . h($stText) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Menge (Packungen)</div><div class="v">' . number_format((int)$a['menge'], 0, ',', '.') . '</div></div>';
    // Glas + Kapselzahl IMMER zeigen (auch wenn leer) – sonst sieht man bei alten Nachbestellungen nicht, dass es fehlt.
    echo '<div class="bx-card"><div class="k">Verpackung (Glas)</div><div class="v" style="font-size:16px">' . ($glasName !== '' ? h($glasName) : $fehltCard('nicht gesetzt')) . '</div></div>';
    if ($istFuellG) {
        echo '<div class="bx-card"><div class="k">Füllmenge je Packung</div><div class="v">' . ($einhProP > 0 ? number_format($einhProP, 0, ',', '.') . ' g' : $fehltCard('nicht gesetzt')) . '</div></div>';
        if ($gesamtStk > 0) echo '<div class="bx-card"><div class="k">Gesamt-Füllmenge</div><div class="v">' . ($gesamtStk >= 1000 ? rtrim(rtrim(number_format($gesamtStk / 1000, 3, ',', '.'), '0'), ',') . ' kg' : number_format($gesamtStk, 0, ',', '.') . ' g') . '</div></div>';
    } else {
        echo '<div class="bx-card"><div class="k">Stk je Packung</div><div class="v">' . ($einhProP > 0 ? number_format($einhProP, 0, ',', '.') : $fehltCard('nicht gesetzt')) . '</div></div>';
        if ($gesamtStk > 0) echo '<div class="bx-card"><div class="k">Gesamtstückzahl</div><div class="v">' . number_format($gesamtStk, 0, ',', '.') . '</div></div>';
    }
    if ($groesseLbl !== '') { $grK = match((string)($rezeptur['darreichungsform'] ?? '')) { 'kapsel','softgel'=>'Kapselgröße', 'tablette'=>'Tablettenform', 'gummi','stick','pulver','gel','fluessig','oel'=>'Größe je Einheit', default=>'Größe/Form' };
        echo '<div class="bx-card"><div class="k">' . $grK . '</div><div class="v">' . h($groesseLbl) . '</div></div>'; }
    echo '<div class="bx-card"><div class="k">Herstellung</div><div class="v">' . ($istFremd ? bx_badge('Zukauf','info') : bx_badge('Eigenproduktion','ok')) . '</div></div>';
    echo '<div class="bx-card"><div class="k">' . h($designLbl) . '</div><div class="v">' . $etikettBadge . '</div></div>';
    echo '<div class="bx-card"><div class="k">VK / Packung (gesamt)</div><div class="v">' . $eur(auftrag_vk_gesamt($a)) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Netto gesamt</div><div class="v">' . $eur($a['gesamt_netto']) . '</div></div>';
    if (!empty($a['angelegt'])) echo '<div class="bx-card"><div class="k">Erstellt</div><div class="v">' . h(fmt_zeit($a['angelegt'], 'd.m.Y H:i')) . '</div></div>';
    echo '</div>';
};

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
  <strong>Große Menge als Einzelauftrag: <?= number_format((int)$a['menge'],0,',','.') ?> Stk.</strong>
  <div class="muted" style="font-size:13px;margin:4px 0 10px">Der Kunde ruft so große Mengen meist nicht auf einmal ab. Als <strong>Jahresvertrag/Kontingent</strong> geführt, entsteht Bedarf (Glas, Etiketten, Rohstoffe) nur je Abruf – nicht <?= number_format((int)$a['menge'],0,',','.') ?> Stk auf einmal.</div>
  <form method="post" style="margin:0" onsubmit="return confirm('Auftrag <?= h($a['nummer']) ?> in ein Kontingent umwandeln? Der Auftrag wird storniert; produziert wird über die Abrufe.');">
    <input type="hidden" name="aktion" value="zu_kontingent">
    <button class="btn btn-primary btn-sm" type="submit" data-busy="Wandle um…">Zu Kontingent (Jahresvertrag) machen</button>
  </form>
</div>
<?php endif; ?>
<?php
// ===== H0 (Teil 3): Schrittleiste – der Auftrag als zentrale Steuerseite. Sechs feste Schritte in fester
// Reihenfolge mit Zustand (offen / läuft / erledigt), auf die vorhandenen Routen/Funktionen verlinkt (nichts
// neu erfunden, nur zusammengeführt). Reine Übersicht + Sprung zur passenden Stelle (Reiter bzw. Einkauf).
if (($a['status'] ?? '') !== 'storniert'):
    $hasPA      = (bool)$pa;
    $quelleOk   = $pa && !empty($pa['art_festgelegt_am']);                 // Eigen/Fremd/Lager entschieden
    $freigabeOk = $pa && ($pa['status'] ?? '') !== 'vorbereitung';         // vorbereitung = noch nicht freigegeben
    $matOk      = in_array(($ber['status'] ?? ''), ['bereit','laeuft','fertig'], true);  // Material gedeckt
    $hatBest    = count($best) > 0;
    $hatWE      = count($wareneingaenge) > 0;
    $stat       = (string)($a['status'] ?? '');
    $versendet  = $stat === 'versendet';
    $inGang     = in_array($stat, ['in_produktion','erledigt'], true) || ($pa && in_array(($pa['status'] ?? ''), ['laufend','erledigt'], true));
    $S = fn(bool $done, bool $active) => $done ? 'erledigt' : ($active ? 'laeuft' : 'offen');
    $steps = [
        ['n'=>1,'label'=>'Material prüfen',       'state'=>$S($matOk, $hasPA && !$matOk),                     'goto'=>'material'],
        ['n'=>2,'label'=>'Quelle festlegen',      'state'=>$S($quelleOk || $freigabeOk, !($quelleOk || $freigabeOk)), 'goto'=>'produktion'],
        ['n'=>3,'label'=>'Freigeben',             'state'=>$S($freigabeOk, $quelleOk && !$freigabeOk),        'goto'=>'produktion'],
        ['n'=>4,'label'=>'Bestellen',             'state'=>$S($matOk, $freigabeOk && $hatBest && !$matOk),    'href'=>'?p=einkaufsliste'],
        ['n'=>5,'label'=>'Wareneingang',          'state'=>$S($matOk && $hatWE, $freigabeOk && ($hatBest || $hatWE) && !$matOk), 'goto'=>'lager'],
        ['n'=>6,'label'=>'Produktion & Versand',  'state'=>$S($versendet, $inGang && !$versendet),            'goto'=>'produktion'],
    ];
    // Markenfarben: erledigt = Grün, läuft = Lime (hervorgehoben), offen = ruhig.
    $farbe = ['erledigt'=>['#1D9E75','#ffffff'], 'laeuft'=>['#C0F24E','#10210f'], 'offen'=>['var(--panel-2)','var(--muted)']];
?>
<div class="bx-panel" style="margin-bottom:16px;padding:14px 16px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <span style="color:var(--muted);font-size:13px">Ablauf dieses Auftrags</span>
    <?php if ($pa && ($pa['status'] ?? '') === 'vorbereitung'): ?><span><?= bx_badge('wartet auf Freigabe','warn') ?></span><?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($steps as $st): [$bg,$fg] = $farbe[$st['state']];
      $kreis = '<span style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:' . ($st['state']==='erledigt'?'rgba(255,255,255,.25)':'rgba(0,0,0,.08)') . ';font-size:12px;font-weight:600">' . ($st['state']==='erledigt'?'&#10003;':(string)$st['n']) . '</span>';
      $inner = $kreis . '<span>' . h($st['label']) . '</span>';
      $style = 'display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:' . $bg . ';color:' . $fg . ';font-size:13px;text-decoration:none;border:1px solid var(--line)' . ($st['state']==='laeuft'?';box-shadow:0 0 0 2px rgba(192,242,78,.55)':'');
      $titel = $st['label'] . ' – ' . ($st['state']==='erledigt'?'erledigt':($st['state']==='laeuft'?'läuft':'offen'));
      if (!empty($st['href'])): ?>
        <a href="<?= h($st['href']) ?>" style="<?= $style ?>" title="<?= h($titel) ?>"><?= $inner ?></a>
      <?php else: ?>
        <a href="#" data-goto="<?= h($st['goto']) ?>" style="<?= $style ?>" title="<?= h($titel) ?>"><?= $inner ?></a>
      <?php endif; endforeach; ?>
  </div>
</div>
<?php endif; ?>
<div class="settabs" id="auftabs" style="margin-bottom:16px">
  <a href="#" class="on" data-tab="details">Details</a>
  <a href="#" data-tab="rezeptur">Rezeptur</a>
  <a href="#" data-tab="material">Material</a>
  <a href="#" data-tab="produktion">Produktion</a>
  <a href="#" data-tab="lager">Lager</a>
  <a href="#" data-tab="preise">Preise &amp; Rechnung</a>
  <a href="#" data-tab="dokumente">Dokumente</a>
</div>

<?php // Reiter „Rezeptur": zeigt die (effektive) Rezeptur des Auftrags zum Nachsehen (auftrag.rezeptur_id, sonst Produkt-Rezeptur). ?>
<div class="bx-panel" data-panel="rezeptur">
  <?php if ($rezeptur):
      $rzZut = all("SELECT item_id, bezeichnung, menge_mg FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [$rezEffId]);
      $rzSum = 0.0; foreach ($rzZut as $z) $rzSum += (float)$z['menge_mg'];
      // CoA/Spec je zugeordnetem Rohstoff (dokument am item) – für die anklickbare Liste mit Papier-Status.
      $coaMap = [];
      $zutIds = array_values(array_unique(array_filter(array_map(fn($z) => (int)$z['item_id'], $rzZut))));
      if ($zutIds) {
          $ph = implode(',', array_fill(0, count($zutIds), '?'));
          foreach (all("SELECT objekt_id, typ FROM dokument WHERE objekt_typ='item' AND typ IN ('coa','spec','beides','analyse') AND objekt_id IN ($ph)", $zutIds) as $dr) {
              $iid = (int)$dr['objekt_id']; $t = (string)$dr['typ'];
              if (!isset($coaMap[$iid])) $coaMap[$iid] = ['coa' => false, 'spec' => false];
              if ($t === 'coa' || $t === 'beides' || $t === 'analyse') $coaMap[$iid]['coa'] = true;
              if ($t === 'spec' || $t === 'beides') $coaMap[$iid]['spec'] = true;
          }
      }
      $kgName = (int)($rezeptur['kapselgroesse_id'] ?? 0) ? (string) scalar("SELECT name FROM kapselgroesse WHERE id=?", [(int)$rezeptur['kapselgroesse_id']]) : '';
      $mgF = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
  ?>
    <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <h2 style="margin:0"><?= h((string)$rezeptur['nummer']) ?> · <?= h((string)$rezeptur['name']) ?></h2>
      <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="?p=rezeptur_detail&id=<?= (int)$rezEffId ?>">Rezeptur öffnen &#8599;</a>
    </div>
    <div class="muted" style="font-size:13px;margin:4px 0 12px"><?= h((string)$rezeptur['darreichungsform']) ?><?= $kgName !== '' ? ' · Kapselgröße ' . h($kgName) : '' ?><?= $rzSum > 0 ? ' · Gesamt ' . $mgF($rzSum) . ' mg je Einheit' : '' ?></div>
    <?php if ($rzZut): ?>
    <div class="muted" style="font-size:12px;margin:0 0 8px">Zutat anklicken, um den Rohstoff zu öffnen. „Unterlagen" zeigt, ob am Rohstoff ein CoA bzw. eine Spezifikation hinterlegt ist.</div>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Zutat</th><th>Unterlagen</th><th class="bx-num">Menge je Einheit</th></tr></thead>
      <tbody>
        <?php foreach ($rzZut as $z): $iid = (int)$z['item_id']; $papier = $coaMap[$iid] ?? null; ?>
        <tr>
          <td><?php if ($iid): ?><a href="?p=rohstoff&id=<?= $iid ?>" target="_blank" rel="noopener"><?= h((string)$z['bezeichnung']) ?></a><?php else: ?><?= h((string)$z['bezeichnung']) ?><?php endif; ?></td>
          <td><?php
              if (!$iid)                              echo '<span class="muted" title="Diesem Zutat-Text ist kein Rohstoff zugeordnet">nicht zugeordnet</span>';
              elseif ($papier && $papier['coa'])      echo bx_badge('CoA' . ($papier['spec'] ? ' + Spec' : ''), 'ok');
              elseif ($papier && $papier['spec'])      echo bx_badge('nur Spec – CoA fehlt', 'warn');
              else                                     echo bx_badge('keine Unterlagen', 'warn');
          ?></td>
          <td class="bx-num"><?= $mgF($z['menge_mg']) ?> mg</td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:600"><td>Gesamt je Einheit</td><td></td><td class="bx-num"><?= $mgF($rzSum) ?> mg</td></tr>
      </tbody>
    </table></div>
    <?php else: ?><div class="muted">Diese Rezeptur hat noch keine Zutaten.</div><?php endif; ?>
  <?php else: ?>
    <div class="muted">Diesem Auftrag ist noch keine Rezeptur zugeordnet. Im Reiter „Details" kannst du eine Rezeptur verknüpfen.</div>
  <?php endif; ?>
</div>

<?php // Behälter/Verpackung am Auftrag wählen (setzt auftrag.verpackung_id). Vor allem für alte v3-Aufträge ohne Produkt-Verpackung.
if (has_role('admin') || has_role('sales')):
    $aVp      = (int)($a['verpackung_id'] ?? 0);
    // Bedruckte Behälter nur die dieses Kunden (Blocker 1) – nie den Beutel eines anderen Kunden anbieten.
    $vpListe  = all("SELECT id, name FROM item WHERE kategorie='verpackung' AND COALESCE(verpackung_rolle,'primaer')='primaer' AND gesperrt=0
                     AND (COALESCE(kundenspezifisch,0)=0 OR kunde_id=? OR id=?) ORDER BY name", [(int)($a['kunde_id'] ?? 0), $aVp]);
    $pVp      = !empty($a['produkt_id']) ? (int) scalar("SELECT verpackung_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]) : 0;
    $effVp    = $aVp ?: $pVp;
    $effFinal = $effVp ? (string) scalar("SELECT etikett_final FROM item WHERE id=?", [$effVp]) : '';
    $effName  = $effVp ? (string) scalar("SELECT name FROM item WHERE id=?", [$effVp]) : '';
    $effKspez = $effVp ? (int) scalar("SELECT COALESCE(kundenspezifisch,0) FROM item WHERE id=?", [$effVp]) : 0;
?>
<div class="bx-panel" data-panel="material">
  <h2 style="margin-top:0">Verpackung (Behälter)</h2>
  <?php if (isset($_GET['vpok'])): ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin-bottom:10px;display:inline-block">Verpackung gespeichert.</div><?php endif; ?>
  <p class="muted" style="margin-top:0">Behälter/Glas für diesen Auftrag – bestimmt Etikett und Etikettmaß (fürs PIB). Der Auftrag hat Vorrang vor dem Produkt; nötig z. B. bei alten Aufträgen ohne Produkt-Verpackung.</p>
  <form method="post" class="bx-row" style="gap:8px;align-items:center;flex-wrap:wrap;margin:0">
    <input type="hidden" name="aktion" value="verpackung_setzen">
    <select name="verpackung_id" class="rscombo" style="min-width:260px">
      <option value="">– kein Behälter (reine Bulkware) –</option>
      <?php foreach ($vpListe as $vpi): ?><option value="<?= (int)$vpi['id'] ?>" <?= $aVp === (int)$vpi['id'] ? 'selected' : '' ?>><?= h($vpi['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-primary btn-sm" type="submit">Verpackung speichern</button>
  </form>
  <?php if ($effVp): ?>
    <div class="muted" style="font-size:12px;margin-top:8px">Aktueller Behälter: <strong><?= h($effName) ?></strong><?= $aVp ? '' : ' (vom Produkt übernommen)' ?> · Etikett-Endformat: <?= trim($effFinal) !== '' ? '<strong>' . h($effFinal) . '</strong>' : '<span style="color:var(--warn)">nicht hinterlegt – am Behälter unter Lager → Verpackungen pflegen</span>' ?></div>
    <?php if ($effKspez): ?>
      <div style="font-size:12px;margin-top:6px"><?= bx_badge('bedruckt · kundenspezifisch','info') ?> <span class="muted">Eigener Behälter-Artikel dieses Kunden (je Produkt + Kunde) – getrennt vom Standard-Beutel, im Einkauf bestellbar, bei Nachbestellung wiederverwendet.</span></div>
    <?php elseif (!empty($a['produkt_id'])): ?>
      <form method="post" style="margin:10px 0 0" onsubmit="return confirm('Aus dem aktuellen Standard-Behälter einen bedruckten Behälter für diesen Kunden anlegen und diesem Auftrag zuweisen? Er gehört dem Kunden (je Produkt + Kunde), wird getrennt vom Standard geführt und bei Nachbestellungen wiederverwendet.');">
        <input type="hidden" name="aktion" value="verpackung_kundenspezifisch">
        <button class="btn btn-ghost btn-sm" type="submit">Bedruckten, kundenspezifischen Behälter anlegen</button>
        <span class="muted" style="font-size:12px;display:block;margin-top:4px">Für einen bedruckten Kundenbeutel, der kein Standard-Basic ist: legt einen eigenen Lagerartikel je Produkt + Kunde an (wie das Kundenetikett), das Produkt behält den neutralen Behälter – im Einkauf bestellbar, im Lager getrennt vom Standard-Beutel.</span>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <div class="muted" style="font-size:12px;margin-top:8px">Noch kein Behälter gewählt.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php // Reiter „Verpackung": Etikett-Verwaltung (Team/Admin) – hochladen/ersetzen (Last-Minute), Freigabe im Namen des Kunden, entfernen.
if (auftrag_braucht_etikett($id) && (has_role('admin') || has_role('sales'))): $etDokA = etikett_datei($id); ?>
<div class="bx-panel" data-panel="material">
  <h2 style="margin-top:0"><?= auftrag_vollbedruckt($id) ? 'Beutel-Design (vollbedruckt, statt Etikett)' : 'Etikett' ?></h2>
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
  <p class="muted" style="font-size:12px;margin:12px 0 0">Kapselgröße wird im Reiter „Details" gesetzt (gehört zur Produktkonfiguration). Der Behälter wird oben gewählt.</p>
</div>
<?php endif; ?>

<?php if (!empty($a['kunde_id'])) { echo kunde_nachricht_panel((int)$a['kunde_id'], (int)$a['id'], 0, 'details'); kunde_nachricht_gelesen((int)$a['kunde_id'], (int)$a['id'], 0, 'team'); } ?>
<div class="bx-panel" data-panel="details">
  <?php $kachelnRender(); // Kennzahl-Kacheln (aus dem Seitenkopf in den Details-Reiter verschoben) ?>
  <?php if (!in_array((string)$a['status'], ['versendet', 'storniert'], true)): $ltTxt = auftrag_liefertermin_text($a); ?>
  <form method="post" class="bx-row" style="gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 14px">
    <input type="hidden" name="aktion" value="liefertermin_setzen">
    <span class="muted" style="white-space:nowrap">Voraussichtliche Lieferung <?= bx_hint('Zeitraum, den der Kunde im Portal im Fortschritt sieht. Leer = es gilt der geplante Produktionstermin (falls gesetzt).') ?></span>
    <input type="date" name="liefertermin_von" value="<?= h((string)($a['liefertermin_von'] ?? '')) ?>"> <span class="muted">bis</span>
    <input type="date" name="liefertermin_bis" value="<?= h((string)($a['liefertermin_bis'] ?? '')) ?>">
    <button class="btn btn-ghost btn-sm" type="submit">Speichern</button>
    <?php if (isset($_GET['ltok'])): ?><span class="muted" style="font-size:12px">gespeichert</span><?php endif; ?>
    <?php if (!portal_liefertermin_aktiv()): ?><span class="muted" style="font-size:12px">Im Kundenportal zentral ausgeblendet (Einstellungen) – nur intern.</span>
    <?php elseif ($ltTxt !== ''): ?><span class="muted" style="font-size:12px">Kunde sieht: <?= h($ltTxt) ?></span><?php endif; ?>
  </form>
  <?php endif; ?>
  <h2>Details</h2>
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= kunde_link($a['kunde_id'] ?? null, $a['kunde_firma']) ?></div></div>
    <div><div class="k muted">Produkt <span class="muted" style="font-size:11px">(intern)</span></div><div><?php if (!empty($a['produkt_id']) && $produktName): ?><a href="?p=produkt&id=<?= (int)$a['produkt_id'] ?>"><?= h($produktName) ?></a><?php elseif ($produktName): ?><?= h($produktName) ?> <span class="muted" style="font-size:12px">(aus v3)</span><?php else: ?>–<?php endif; ?><?php if ($artKey !== 'none'): ?> <span title="<?= h($artHint) ?>"><?= bx_badge($artLabel, $artStil) ?></span><?php endif; ?></div></div>
    <?php // Kundenspezifischer Name: so sieht der Kunde das Produkt (Portal/Belege). Intern beide Namen sichtbar.
          if (!empty($a['produkt_id']) && !empty($a['kunde_id'])): $effKn = trim((string)($a['produkt_kundenname'] ?? '')); ?>
    <div><div class="k muted">Name beim Kunden <?= bx_hint('So heißt dieses Produkt bei DIESEM Kunden (Portal, Angebot, AB, Rechnung, Lieferschein, Etikett, PIB). Leer = Standard-/interner Name. Pflege auch am Produkt möglich.') ?></div>
      <?php if (isset($_GET['knok'])): ?><span class="muted" style="font-size:11px;color:var(--gruen)">gespeichert</span><?php endif; ?>
      <form method="post" class="bx-row" style="gap:6px;align-items:center;margin:2px 0 0">
        <input type="hidden" name="aktion" value="kundenname_setzen">
        <input type="text" name="kn_name" value="<?= h($effKn) ?>" placeholder="<?= h($produktName ?: 'wie intern') ?>" style="min-width:180px">
        <button class="btn btn-ghost btn-sm" type="submit">Speichern</button>
      </form>
    </div>
    <?php endif; ?>
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
    <?php if ($pa): // Pro-PA-Seite für Flaschen/Verpackung/Kapselgröße bleibt; die Freigabe läuft jetzt direkt am Auftrag (Reiter Produktion). ?>
      <a class="btn <?= $paVorbereitung ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="?p=produktion_vorbereitung&id=<?= (int)$pa['id'] ?>">Flaschen, Verpackung &amp; Kapselgröße setzen</a>
    <?php endif; ?>
    <span class="muted" style="font-size:12px">Menge und Preis sind fixiert; die Freigabe läuft über den Reiter „Produktion", Flaschen/Verpackung/Kapselgröße hier.</span>
  </div>
  <?php endif; ?>
</div>

<?php // Etikett-Vorschau-Fenster im Reiter „Details": zeigt das hinterlegte Etikett-Design (eigenes oder aus
      // einer Vorbestellung desselben Produkts/Kunden, via etikett_quelle()). Bild wird direkt angezeigt, PDF im
      // Rahmen eingebettet. Verwaltet (hochladen/ersetzen/freigeben) wird das Etikett im Reiter „Verpackung".
if (auftrag_braucht_etikett($id)):
    $etPrev = etikett_quelle($id);
    $etExt  = $etPrev ? strtolower(pathinfo((string)($etPrev['datei_orig'] ?: $etPrev['datei']), PATHINFO_EXTENSION)) : '';
    $etIstBild = in_array($etExt, ['jpg','jpeg','png','gif','webp','avif','bmp','svg'], true);
    $etIstPdf  = $etExt === 'pdf'; ?>
<div class="bx-panel" data-panel="details">
  <h2 style="margin-top:0">Etikett-Vorschau</h2>
  <?php if ($etPrev): ?>
    <div style="margin:0 0 10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?= $etikettFrei ? bx_badge('freigegeben','ok') : bx_badge('nicht freigegeben','warn') ?>
      <span class="muted" style="font-size:13px"><?= h((string)($etPrev['datei_orig'] ?: 'Etikett-Design')) ?></span>
      <?php if (!empty($etPrev['alt'])): ?><span class="muted" style="font-size:12px">· aus einer Vorbestellung übernommen</span><?php endif; ?>
    </div>
    <div style="border:1px solid var(--line);border-radius:10px;background:var(--panel-2,#f6f7f8);padding:12px;display:flex;align-items:center;justify-content:center;min-height:220px">
      <?php if ($etIstBild): ?>
        <img src="?p=dokument&id=<?= (int)$etPrev['id'] ?>" alt="Etikett-Vorschau" style="max-width:100%;max-height:440px;border-radius:6px;box-shadow:0 1px 6px rgba(0,0,0,.12)">
      <?php elseif ($etIstPdf): ?>
        <iframe src="?p=dokument&id=<?= (int)$etPrev['id'] ?>#toolbar=0&view=FitH" title="Etikett-Vorschau" style="width:100%;height:460px;border:0;border-radius:6px;background:#fff"></iframe>
      <?php else: ?>
        <div class="muted" style="text-align:center">Vorschau für <strong><?= h($etExt ?: 'diese Datei') ?></strong> nicht möglich.<br><a class="btn btn-ghost btn-sm" style="margin-top:8px" href="?p=dokument&id=<?= (int)$etPrev['id'] ?>" target="_blank" rel="noopener">Etikett öffnen</a></div>
      <?php endif; ?>
    </div>
    <div class="bx-row" style="gap:10px;margin-top:10px;flex-wrap:wrap">
      <a class="btn btn-ghost btn-sm" href="?p=dokument&id=<?= (int)$etPrev['id'] ?>" target="_blank" rel="noopener">In neuem Tab öffnen</a>
      <?php if (has_role('admin') || has_role('sales')): ?><a class="btn btn-ghost btn-sm" href="#" onclick="var t=document.querySelector(String.fromCharCode(35)+'auftabs [data-tab=material]');if(t)t.click();return false;">Etikett verwalten (Reiter Material)</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div style="border:1px dashed var(--line);border-radius:10px;background:var(--panel-2,#f6f7f8);padding:28px 16px;text-align:center;color:var(--muted,#667085)">
      Noch kein Etikett-Design hinterlegt.
      <?php if (has_role('admin') || has_role('sales')): ?><br><a class="btn btn-ghost btn-sm" style="margin-top:10px" href="#" onclick="var t=document.querySelector(String.fromCharCode(35)+'auftabs [data-tab=material]');if(t)t.click();return false;">Etikett hochladen (Reiter Material)</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php $track = kunde_auftrag_track($a); ?>
<div class="bx-panel" data-panel="produktion">
  <h2 style="margin:0 0 14px;font-size:16px">Fortschritt</h2>
  <?php
  // Punkt 2+3: „Nächster Schritt"-Satz + konkret, WAS fehlt und WOHIN. Quelle: produktion_bereitschaft().
  $fmtM = function($x, $e) { $e = (string)$e; if (function_exists('gewicht_lesbar') && in_array($e, ['g','ml'], true)) return gewicht_lesbar((float)$x, $e); return number_format(round((float)$x), 0, ',', '.') . ' ' . ($e ?: 'Stk'); };
  $berSt = (string)($ber['status'] ?? '');
  $fehlDetail = [];
  if ($pa && $berSt === 'wartet') {
      foreach (($ber['fehlend'] ?? []) as $fr) {
          $iid = (int)($fr['item_id'] ?? 0);
          $bst = $iid > 0 ? one("SELECT b.id, b.nummer, b.eta_geplant FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                                  WHERE bp.item_id=? AND b.status='bestellt' AND b.angekommen_am IS NULL ORDER BY b.id DESC LIMIT 1", [$iid]) : null;
          $fehlDetail[] = ['name'=>(string)($fr['name'] ?? '–'), 'fehlt'=>(float)($fr['fehlt'] ?? 0), 'einheit'=>(string)($fr['einheit'] ?? 'Stk'), 'best'=>$bst, 'quar'=>(float)($fr['quarantaene'] ?? 0), 'iid'=>$iid];
      }
  }
  $nsTon = 'info'; $nsLager = false;
  if (!$pa)                                    { $nsText = 'Beschaffung wählen: Herstellen (aus Rohstoffen) oder Einkaufen (fertige Bulkware) – unten.'; }
  elseif ($paVorbereitung)                     { $nsText = 'Produktionsauftrag ist in Vorbereitung – unten „Zur Produktion freigeben".'; $nsTon = 'warn'; }
  elseif ($berSt === 'wartet' && $fehlDetail && !array_filter($fehlDetail, fn($fd) => $fd['quar'] + 1e-6 < $fd['fehlt']))
                                               { $nsText = 'Produktion wartet auf die QM-Freigabe – das Material liegt in Quarantäne:'; $nsTon = 'warn'; }
  elseif ($berSt === 'wartet')                 { $nsText = 'Produktion noch nicht möglich – es fehlt Material:'; $nsTon = 'err'; }
  elseif ($berSt === 'bereit')                 { $nsText = 'Material ist vollständig – die Produktion kann starten.'; $nsTon = 'ok'; }
  elseif ($berSt === 'laeuft')                 { $nsText = 'Produktion läuft – fertige Menge unten melden.'; }
  elseif (($stat ?? '') === 'erledigt')        { $nsText = 'Versandbereit – im Reiter „Lager" einlagern bzw. versenden.'; $nsTon = 'ok'; $nsLager = true; }
  elseif (($stat ?? '') === 'versendet')       { $nsText = $ffAuftrag ? 'Abgeschlossen.' : 'Versendet.'; $nsTon = 'ok'; }
  else                                         { $nsText = 'In Arbeit.'; }
  $nsBorder = $nsTon === 'err' ? '#b23b2f' : ($nsTon === 'warn' ? '#C9A227' : ($nsTon === 'ok' ? 'var(--gruen,#1D9E75)' : 'var(--line)'));
  ?>
  <div class="bx-panel" style="background:var(--panel-2);padding:12px 14px;margin:0 0 18px;border-left:3px solid <?= $nsBorder ?>">
    <div style="font-size:14px"><strong>Nächster Schritt:</strong> <?= h($nsText) ?><?php if ($nsLager): ?> <a href="#" data-goto="lager" style="font-size:13px">zum Reiter Lager</a><?php endif; ?></div>
    <?php if ($fehlDetail): ?>
    <ul style="margin:10px 0 0;padding-left:18px;font-size:13px">
      <?php foreach ($fehlDetail as $fd): ?>
      <?php $fdQ = $fd['quar'] > 1e-6 && $fd['quar'] + 1e-6 >= $fd['fehlt']; ?>
      <li style="margin:3px 0"><strong><?= h($fmtM($fd['fehlt'], $fd['einheit'])) ?></strong> <?= h($fd['name']) ?> <?= $fdQ ? 'in Quarantäne' : 'fehlt' ?> –
        <?php if ($fdQ): ?><span style="color:#8a6d00">wartet auf die QM-Freigabe (<?= h($fmtM($fd['quar'], $fd['einheit'])) ?> in Quarantäne)</span> – <a href="<?= h(quarantaene_freigabe_url((int)$fd['iid'])) ?>">freigeben</a>
        <?php elseif ($fd['best']): ?>bestellt mit <a href="?p=bestellung&id=<?= (int)$fd['best']['id'] ?>"><?= h((string)$fd['best']['nummer']) ?></a><?= !empty($fd['best']['eta_geplant']) ? ', erwartet ' . h(date('d.m.Y', strtotime((string)$fd['best']['eta_geplant']))) : '' ?>
        <?php else: ?><span style="color:#8f231b">noch nicht bestellt</span> – <a href="?p=einkaufsliste">zum Einkauf</a><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <div style="margin-top:8px"><a href="#" data-goto="material" class="btn btn-ghost btn-sm">Zum Reiter Material</a></div>
    <?php endif; ?>
  </div>
  <?php
  // Mengen-Fortschritt (Teilproduktion/Teilversand) als segmentierte Leiste – macht sichtbar, WIE VIEL schon
  // produziert bzw. versendet ist und wie viel noch offen ist (die Phasen-Kette unten zeigt nur das WO).
  $mfA   = auftrag_mengenfortschritt((int)$a['id']);
  $zielA = max((int)($a['menge'] ?? 0), (int)($mfA['ziel'] ?? 0));
  $prodA = max(0, (int)($mfA['gebucht'] ?? 0));                                        // produzierte Packungen
  $versA = max(0, min((int)($a['versendet_menge'] ?? 0), $prodA));                      // versendet (<= produziert)
  $bereitA = max(0, $prodA - $versA);          // produziert, aber noch nicht versendet (im Lager)
  $offenA  = max(0, $zielA - $prodA);          // noch nicht produziert
  if ($zielA > 0 && ($prodA > 0 || $versA > 0)):
      $teilweise = ($offenA > 0) || ($versA > 0 && $versA < $zielA);
      $denom = max($zielA, $prodA);            // bei Überproduktion volle Leiste
      $nfA   = fn($x) => number_format((int)$x, 0, ',', '.');
      $pctV  = $denom > 0 ? round($versA   * 100 / $denom, 2) : 0;
      $pctB  = $denom > 0 ? round($bereitA * 100 / $denom, 2) : 0;
      $dot   = fn($c) => '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' . $c . ';border:1px solid rgba(0,0,0,.08);vertical-align:middle;margin-right:5px"></span>';
  ?>
  <div style="margin:0 0 20px">
    <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px;margin-bottom:8px">
      <span class="k muted">Mengen-Fortschritt<?= $teilweise ? ' · Teilmengen' : '' ?></span>
      <span style="font-size:13px"><strong><?= $nfA($prodA) ?></strong> von <strong><?= $nfA($zielA) ?></strong> Packungen produziert<?= $offenA > 0 ? ' · ' . $nfA($offenA) . ' offen' : '' ?><?= $versA > 0 ? ' · ' . $nfA($versA) . ' versendet' : '' ?></span>
    </div>
    <div style="display:flex;height:12px;border-radius:7px;overflow:hidden;background:var(--panel-2,#eceef0)" title="<?= $nfA($versA) ?> versendet · <?= $nfA($bereitA) ?> produziert · <?= $nfA($offenA) ?> offen">
      <?php if ($pctV > 0): ?><div style="width:<?= $pctV ?>%;background:var(--gruen)"></div><?php endif; ?>
      <?php if ($pctB > 0): ?><div style="width:<?= $pctB ?>%;background:var(--lime)"></div><?php endif; ?>
    </div>
    <div class="bx-row muted" style="gap:18px;flex-wrap:wrap;margin-top:9px;font-size:12px">
      <?php if ($versA > 0): ?><span><?= $dot('var(--gruen)') ?>versendet <?= $nfA($versA) ?></span><?php endif; ?>
      <span><?= $dot('var(--lime)') ?><?= $versA > 0 ? 'im Lager (produziert)' : 'produziert' ?> <?= $nfA($bereitA) ?></span>
      <?php if ($offenA > 0): ?><span><?= $dot('var(--panel-2,#d7dadd)') ?>noch offen <?= $nfA($offenA) ?></span><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
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
  <?php // Punkt 2: Fortschritt als Schritt-Karten (eine je Phase) – Farbe je Status (grün fertig / gelb läuft /
        // grau wartet / rot blockiert), je Karte wer (wir/Lieferant) und für den aktuellen Schritt genau EIN
        // Hauptknopf. Die Phasenleiste oben bleibt als Überblick. Manuelles Setzen jeder Stufe bleibt (eingeklappt).
  if (has_role('admin') || has_role('sales') || has_role('production')):
      $ffA     = auftrag_ist_fulfillment((int)$a['id']);
      $phasenA = auftrag_phasen($ffA);
      $phInfoA = kunde_auftrag_phase($a);
      $curIdxA = (int)$phInfoA['idx'];
      $phDates = $phInfoA['dates'] ?? [];
      $nPh     = count($phasenA);
      $berWartet = (($berSt ?? '') === 'wartet'); ?>
  <?php if (isset($_GET['statusok'])): ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin:14px 0 0;display:inline-block">Status aktualisiert – der Kunde sieht es sofort.</div><?php endif; ?>
  <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line)">
    <div class="k muted" style="margin-bottom:10px">Schritte</div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php foreach ($phasenA as $pi => $plbl):
          $done    = $pi < $curIdxA;
          $current = $pi === $curIdxA;
          $blocked = $current && $berWartet && $pi <= 3;          // Material-Gate nur bis „In Produktion"
          $laeuft  = $current && !$blocked;
          $farbe = $done ? 'var(--gruen,#1D9E75)' : ($blocked ? '#b23b2f' : ($laeuft ? '#C9A227' : 'var(--line)'));
          $stTxt = $done ? 'fertig' : ($blocked ? 'blockiert' : ($laeuft ? 'läuft' : 'wartet'));
          $ikon  = $done ? '&#10003;' : ($current ? '&#9679;' : '&#9675;');
          $actor = ($istFremd && in_array($pi, [3, 4, 5], true)) ? 'Lieferant' : 'wir';
          $datum = $phDates[$pi] ?? null;
      ?>
      <div class="bx-panel" style="padding:10px 14px;margin:0;border-left:4px solid <?= $farbe ?>;<?= $current ? 'background:var(--panel-2)' : '' ?>">
        <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="color:<?= $farbe ?>;font-size:16px;line-height:1"><?= $ikon ?></span>
            <div>
              <div style="font-weight:600;font-size:14px">Schritt <?= $pi + 1 ?>/<?= $nPh ?>: <?= h($plbl) ?></div>
              <div class="muted" style="font-size:12px"><?= h($stTxt) ?> · <?= $actor === 'Lieferant' ? 'Lieferant' : 'wir' ?><?= $datum ? ' · ' . h(fmt_zeit((string)$datum, 'd.m.Y')) : '' ?></div>
            </div>
          </div>
          <?php if ($current && $pi + 1 < $nPh): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="aktion" value="phase_setzen">
            <input type="hidden" name="phase" value="<?= $pi + 1 ?>">
            <button class="btn btn-primary btn-sm" type="submit"><?= $pi + 1 === $nPh - 1 ? 'Auftrag abschließen' : 'Schritt abschließen' ?> &rarr;</button>
          </form>
          <?php elseif ($current): ?>
          <span class="muted" style="font-size:12px">letzter Schritt</span>
          <?php endif; ?>
        </div>
        <?php if ($blocked): ?>
        <div style="margin-top:8px;font-size:12px"><span style="color:#b23b2f">Wartet auf Material.</span> <a href="#" data-goto="material">Was fehlt? &rarr; Reiter Material</a></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <details class="bx-sek" style="margin-top:12px">
      <summary style="cursor:pointer;font-size:13px" class="muted">Fortschritt manuell auf eine Stufe setzen</summary>
      <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:10px 0 0">
        <input type="hidden" name="aktion" value="phase_setzen">
        <select name="phase" style="min-width:190px">
          <?php foreach ($phasenA as $pi => $plbl): ?><option value="<?= (int)$pi ?>" <?= (int)$pi === $curIdxA ? 'selected' : '' ?>><?= h($plbl) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-ghost btn-sm" type="submit">Setzen</button>
        <span class="muted" style="font-size:12px">Jede Stufe wählbar (auch zurück, z. B. „Rohstoff bestellt"). Die letzte Stufe schließt den Auftrag ab; Stornieren im Reiter „Details".</span>
      </form>
    </details>
  </div>
  <?php endif; ?>
</div>

<?php // H1 (Teil 3): Material-Übersicht – je Position benötigt / frei / unterwegs mit Deckungs-Ampel.
// Nutzt den vorhandenen auftrag_bedarf() + item_unterwegs(); nur Übersicht, keine Buchung.
if ($pa): $bedarfRows = auftrag_bedarf_rest((int)$pa['id']); if ($bedarfRows):
    // auftrag_bedarf_rest() liefert den Bedarf bereits auf den NOCH offenen Rest skaliert (schon produzierte
    // Packungen abgezogen) – EINE Quelle (core/schema.php), identisch zu produktion_bereitschaft() und der
    // PA-Material-Tabelle. Hier wird nur noch angezeigt. $ueProd dient nur dem Hinweistext.
    $ueZiel  = max(0, (int)($pa['menge'] ?? 0));
    $ueProd  = min($ueZiel, (int) round(produktion_gebucht((int)$pa['id'])));
    // Einheitengerechte Anzeige: g/ml lesbar als kg/t bzw. L, Packungen/Stück ganzzahlig (keine 2.096,774 Pkg),
    // alles andere (z. B. kg am Rohstoff) mit bis zu 3 Nachkommastellen. Einheit immer mit dran, auch bei „frei".
    $mng = function($x, $e = '') {
        $e = (string)$e;
        if (function_exists('gewicht_lesbar') && in_array($e, ['g','ml'], true)) return gewicht_lesbar((float)$x, $e); // bringt Einheit selbst mit
        if (in_array($e, ['Pkg','Stk','Stück','Stueck'], true)) return number_format(round((float)$x), 0, ',', '.') . ' ' . $e;
        $n = rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
        return $n . ($e !== '' ? ' ' . $e : '');
    }; ?>
<div class="bx-panel" data-panel="material">
  <h2 style="margin:0 0 4px;font-size:16px">Material-Übersicht</h2>
  <div class="muted" style="font-size:12px;margin-bottom:10px">Was dieser Auftrag braucht, was frei im Lager liegt und was bestellt, aber noch unterwegs ist.</div>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Position</th><th>Rolle</th><th class="bx-num"><?= $ueProd > 0 ? 'noch benötigt' : 'benötigt' ?></th><th class="bx-num">frei</th><th class="bx-num">in Quarantäne</th><th class="bx-num">unterwegs</th><th>Deckung</th></tr></thead>
    <tbody>
    <?php foreach ($bedarfRows as $r):
        $iid = (int)($r['item_id'] ?? 0);
        $ben = (float)($r['benoetigt'] ?? 0);   // schon auf den offenen Rest skaliert (auftrag_bedarf_rest)
        $frei = (float)($r['verfuegbar'] ?? 0);
        $unterwegs = $iid > 0 ? item_unterwegs($iid) : 0.0;
        $einh = (string)($r['einheit'] ?? 'Stk');
        $deckt = $frei + 1e-6 >= $ben;
        $quar = (float)($r['quarantaene'] ?? 0);
        $decktMitQuar = !$deckt && $quar > 1e-6 && ($frei + $quar + 1e-6 >= $ben);
        $decktMitUnterwegs = !$deckt && !$decktMitQuar && ($frei + $quar + $unterwegs + 1e-6 >= $ben); ?>
      <tr>
        <td><?= h((string)($r['name'] ?? '–')) ?></td>
        <td class="muted"><?= h((string)($r['rolle'] ?? '–')) ?></td>
        <td class="bx-num"><?= $mng($ben, $einh) ?></td>
        <td class="bx-num"><?= $mng($frei, $einh) ?></td>
        <td class="bx-num"><?= $quar > 1e-9 ? '<a href="' . h(quarantaene_freigabe_url($iid)) . '">' . $mng($quar, $einh) . '</a>' : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $unterwegs > 1e-9 ? $mng($unterwegs, $einh) : '<span class="muted">–</span>' ?></td>
        <td><?= $deckt ? bx_badge('gedeckt','ok') : ($decktMitQuar ? '<a href="' . h(quarantaene_freigabe_url($iid)) . '" style="text-decoration:none">' . bx_badge('in Quarantäne – freigeben','warn') . '</a>' : ($decktMitUnterwegs ? bx_badge('mit Lieferung','info') : bx_badge('Fehlbestand','warn'))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:12px;margin:6px 0 0"><?php if ($ueProd > 0): ?>„noch benötigt" = Restbedarf, die bereits <strong><?= number_format($ueProd, 0, ',', '.') ?></strong> produzierten Packungen sind anteilig abgezogen · <?php endif; ?>„frei" = im Lager verfügbar · „unterwegs" = bestellt, noch nicht eingegangen · „mit Lieferung" = frei + unterwegs reicht aus.</p>
</div>
<?php endif; endif; ?>
<div class="bx-panel" data-panel="produktion">
  <h2>Produktion</h2>
  <?php $istHW = produkt_ist_handelsware((int)($a['produkt_id'] ?? 0)); if ($istHW): ?>
  <div class="bx-panel" style="border-color:var(--gruen,#1D9E75);background:var(--panel-2);padding:10px 14px;margin:0 0 12px">
    <?php if ($pa): ?>
    <strong>Handelsware</strong> – dieser Auftrag hat (aus einer älteren Annahme) noch einen Produktionsauftrag. Neue Handelsware-Aufträge entstehen ohne Produktion.
    <?php else: ?>
    <strong>Handelsware</strong> – dieses Produkt wird als Fertigware zugekauft &amp; weiterverkauft. Es gibt <strong>keinen Produktionsauftrag</strong>: Beschaffung über die Einkaufsliste (Fertige Produkte), Wareneingang „Fertigware zum Auftrag", versendet wird aus dem Bestand.
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="bx-grid">
    <div><div class="k muted">Herstellung</div><div>
      <?php if ($istFremd): $zukaufArt = (string)($pa['zukauf_art'] ?? 'bulk'); ?>
        <?= bx_badge($zukaufArt === 'fertig' ? 'Einkaufen · fertige Ware zukaufen' : 'Einkaufen · Bulk/Pulver zukaufen (selbst abfüllen)', 'info') ?>
        <?php if (isset($_GET['zkartok'])): ?><span class="badge-ok" style="padding:3px 8px;border-radius:6px;margin-left:6px">Zukauf-Art gespeichert.</span><?php endif; ?>
        <?php if (has_role('admin')): ?>
        <form method="post" class="bx-row" style="gap:6px;align-items:center;margin:6px 0 0">
          <input type="hidden" name="aktion" value="zukauf_art_setzen">
          <select name="zukauf_art" style="max-width:260px">
            <option value="bulk" <?= $zukaufArt !== 'fertig' ? 'selected' : '' ?>>Bulk/Pulver zukaufen – wir füllen selbst ab (Verpackung zählt)</option>
            <option value="fertig" <?= $zukaufArt === 'fertig' ? 'selected' : '' ?>>Fertige Ware zukaufen – abgefüllt (Verpackung enthalten)</option>
          </select>
          <button class="btn btn-ghost btn-sm" type="submit">Setzen</button>
        </form>
        <div class="muted" style="font-size:12px;margin-top:4px">Bei „Bulk/Pulver" bleibt die Verpackung (z. B. Beutel) eigener Materialbedarf und wird separat bestellt; bei „fertige Ware" ist sie im Zukauf enthalten.</div>
        <?php endif; ?>
      <?php else: ?>
        <?= bx_badge('Herstellen · aus Rohstoffen','ok') ?>
      <?php endif; ?>
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
    <?php // Der Mengen-/Teilmengen-Fortschritt steht jetzt prominent oben im Panel „Fortschritt“ (segmentierte Leiste). ?>
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
            <option value="fremd" <?= $istFremd ? 'selected' : '' ?>>Einkaufen (Zukauf)</option>
            <option value="eigen" <?= $istFremd ? '' : 'selected' ?>>Herstellen</option>
          </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Produktionsmenge (<?= $bulkEinh ?>)<?= $bulkLes($freigabeBedarf) ?>
          <input type="text" inputmode="<?= $bulkInM ?>" name="menge_produktion" value="<?= $bulkInput($freigabeBedarf) ?>" <?= $bulkTaus ?> style="min-width:150px">
        </label>
        <button class="btn btn-primary btn-sm" type="submit">Zur Produktion freigeben</button>
      </form>
      <div class="muted" style="font-size:12px;margin-top:4px">Du kannst immer freigeben – offene Punkte (Etikett/Material) sind nur ein Hinweis; die Produktion wartet ggf. auf Material. Mehrmenge über den Bedarf wird als Bulk gebucht.</div>
    </div>
    <?php endif; ?>
    <?php // „Einlagern" (In Lager 1 buchen) steht jetzt im Reiter „Lager". ?>
    <?php else: $aktivPA = in_array((string)$a['status'], ['offen', 'in_produktion'], true); ?>
    <?php if ($istHW && $aktivPA):
        // Handelsware ohne PA (S-15): Beschaffung statt „Herstellen/Einkaufen" – Bestand, unterwegs, noch zu beschaffen.
        $hwItem  = (int) (scalar("SELECT id FROM item WHERE produkt_id=? AND kategorie='verkaufsfertig' LIMIT 1", [(int)$a['produkt_id']]) ?: 0);
        $hwFrei  = $hwItem ? item_bestand($hwItem, true) : 0.0;
        $hwQuar  = $hwItem ? item_bestand($hwItem, false) - $hwFrei : 0.0;
        $hwUnt   = (float) scalar("SELECT COALESCE(SUM(GREATEST(bp.menge - COALESCE(bp.geliefert_menge,0), 0)),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                                   WHERE bp.auftrag_id=? AND b.status NOT IN ('geliefert','storniert') AND b.angekommen_am IS NULL", [$id]);
        $hwOffen = max(0, (int)$a['menge'] - (int)($a['versendet_menge'] ?? 0));
        $hwZu    = max(0.0, $hwOffen - $hwFrei - $hwQuar - $hwUnt); ?>
    <div style="grid-column:1/-1"><div class="k muted">Beschaffung (Handelsware)</div>
      <div class="bx-row" style="gap:18px;flex-wrap:wrap;margin:4px 0 8px">
        <div>Offen: <strong><?= number_format($hwOffen, 0, ',', '.') ?> Pkg</strong></div>
        <div>Frei im Lager: <strong><?= number_format($hwFrei, 0, ',', '.') ?></strong></div>
        <?php if ($hwQuar > 0): ?><div>in Quarantäne: <strong><?= number_format($hwQuar, 0, ',', '.') ?></strong> <a href="<?= h(quarantaene_freigabe_url($hwItem)) ?>">freigeben</a></div><?php endif; ?>
        <div>Bestellt/unterwegs: <strong><?= number_format($hwUnt, 0, ',', '.') ?></strong></div>
        <div>Noch zu beschaffen: <strong><?= number_format($hwZu, 0, ',', '.') ?></strong></div>
      </div>
      <div class="bx-row" style="gap:8px;flex-wrap:wrap">
        <?php if ($hwZu > 0): ?><a class="btn btn-primary btn-sm" href="?p=einkaufsliste">In der Einkaufsliste bestellen</a><?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="?p=wareneingang">Wareneingang buchen</a>
        <?php if ($hwFrei > 0): ?><a class="btn btn-ghost btn-sm" href="?p=versand">Zum Versand</a><?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div><div class="k muted">Produktionsauftrag</div>
      <?php if ($aktivPA): ?>
      <div class="muted" style="margin-bottom:6px">Wie soll dieser Auftrag beschafft werden?</div>
      <div class="bx-row" style="gap:8px;align-items:center;flex-wrap:wrap">
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="pa_anlegen"><input type="hidden" name="produktionsart" value="eigen">
          <button class="btn btn-primary btn-sm" type="submit" title="Selbst aus Rohstoffen produzieren">Herstellen</button></form>
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="pa_anlegen"><input type="hidden" name="produktionsart" value="fremd">
          <button class="btn btn-ghost btn-sm" type="submit" title="Fertige Bulkware beim Lieferanten einkaufen">Einkaufen</button></form>
        <?php if ($istAdmin): $bulkDa = (($bulkQ['item'] ?? 0) && ($bulkQ['bestellungen'] || $bulkQ['chargen'])); ?>
          <?php if ($bulkDa): ?>
          <a class="btn btn-ghost btn-sm" href="#bulkreserve" onclick="var t=document.querySelector(String.fromCharCode(35)+'auftabs [data-tab=material]');if(t)t.click();var e=document.getElementById('bulkreserve');if(e)e.scrollIntoView({behavior:'smooth'});return false;" title="Aus bereits bestellter oder vorhandener Bulkware binden">Bulkware binden</a>
          <?php else: ?>
          <button class="btn btn-ghost btn-sm" type="button" disabled style="opacity:.5;cursor:not-allowed" title="Nur möglich, wenn Bulk des gleichen Produkts unterwegs oder vorhanden ist">Reservieren</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="muted" style="font-size:12px;margin-top:6px"><strong>Herstellen</strong> = selbst aus Rohstoffen · <strong>Einkaufen</strong> = fertige Bulkware beim Lieferanten bestellen · <strong>Reservieren</strong> = aus schon bestellter/vorhandener Bulkware des gleichen Produkts (nur wenn welche unterwegs oder vorhanden ist). Herstellen/Einkaufen legen den Produktionsauftrag an.</div>
      <?php elseif ((string)$a['status'] !== 'storniert'): $nachtragLabel = auftrag_ist_fulfillment($id) ? 'Lager 2 (Fremdlager)' : 'Lager 1 (Warenlager)'; ?>
      <div class="muted" style="margin-bottom:6px">Kein Produktionsauftrag – der Auftrag ist bereits <strong><?= h($stText) ?></strong> (Altauftrag, der nie durch die Produktion lief).</div>
      <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;margin:0;flex-wrap:wrap" onsubmit="return confirm('Fertige Ware (<?= number_format((int)$a['menge'], 0, ',', '.') ?> Packungen) als Charge nachtragen und in <?= h($nachtragLabel) ?> einbuchen?');">
        <input type="hidden" name="aktion" value="charge_nachtragen_einlagern">
        <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Herstellung
          <select name="produktionsart" style="max-width:190px">
            <option value="fremd">Einkaufen (Zukauf)</option>
            <option value="eigen">Herstellen</option>
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
    <?php endif; // Handelsware-Beschaffung ?>
    <?php endif; ?>
  </div>
</div>

<?php // Reiter „Material": Beschaffung – fertige Bulkware aus Bestellung/Charge an den Auftrag binden
      // (Reservieren + Lagerware zuweisen in EINEM Schritt) sowie die Bestellungen zu diesem Auftrag. ?>
<div class="bx-panel" data-panel="material">
  <h2 style="margin-top:0">Material beschaffen &amp; zuweisen</h2>
  <div class="muted" style="font-size:12px;margin:-4px 0 14px">Fertige Bulkware aus einer Bestellung (auch noch unterwegs) oder einer vorhandenen Charge an diesen Auftrag binden – in einem Schritt. Rohstoffe und Verpackung werden über die Material-Übersicht oben und den Einkauf beschafft.</div>

  <?php if ($istAdmin):
        $nzB = fn($x)=>rtrim(rtrim(number_format((float)$x,3,',','.'),'0'),',');
        // Charge-/Bulk-Menge lesbar: bei Gewicht/Volumen (g/ml) ab 1.000 kg/L, ab 1.000.000 t (via gewicht_lesbar),
        // sonst Zahl + Einheit. $einh = Einheit der Charge/Bestellung (null = aus Form).
        $mengeLes = function($x, $einh = null) use ($nzB, $istFuellG) {
            $e = $einh !== null ? (string)$einh : ($istFuellG ? 'g' : '');
            if (function_exists('gewicht_lesbar') && in_array($e, ['g','ml'], true)) return gewicht_lesbar((float)$x, $e);
            return $nzB($x) . ($e !== '' ? ' ' . $e : '');
        };
        $bulkResSumme = 0.0; foreach ($bulkRes as $rv) $bulkResSumme += (float)$rv['menge'];
        $lwSumme = 0.0; foreach ($lwZu as $lz) $lwSumme += (float)$lz['menge_verfuegbar'];
        $gebundenSumme = $bulkResSumme + $lwSumme;
        $mbOffen = max(0, (int)$restStk - (int)round($gebundenSumme));
        $hatQuellen = ($bulkQ['bestellungen'] ?? []) || ($lwQ['chargen'] ?? []) || ($lwQ['weitere'] ?? []); ?>
  <h3 id="bulkreserve" style="margin:4px 0 6px;font-size:14px;font-weight:600;scroll-margin-top:80px">Bulkware binden <span class="muted" style="font-weight:normal">· reservieren oder feste Charge zuweisen</span></h3>
  <?php if (isset($_GET['bulkres']) || isset($_GET['lwzu'])): ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin-bottom:10px;display:inline-block">Bindung aktualisiert.</div><?php endif; ?>
  <?php if (isset($_GET['bulkwarn'])): ?><div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:10px 14px;margin-bottom:10px"><?= h((string)$_GET['bulkwarn']) ?></div><?php endif; ?>
  <?php // Aktuell gebunden: Reservierungen (aus Bestellung/Charge) + fest zugewiesene Chargen in EINER Tabelle. ?>
  <?php if ($bulkRes || $lwZu): ?>
  <div class="bx-tablewrap" style="margin-bottom:10px"><table class="bx-table">
    <thead><tr><th>Quelle</th><th class="bx-num">Menge</th><th>Art</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($bulkRes as $rv): ?>
      <tr>
        <td><?php if ($rv['bestellung_nr']): ?>Bestellung <?= h((string)$rv['bestellung_nr']) ?><?= $rv['lieferant'] ? ' · ' . h((string)$rv['lieferant']) : '' ?><?php elseif ($rv['charge_nr']): ?>Charge <?= h((string)$rv['charge_nr']) ?><?php else: ?>–<?php endif; ?></td>
        <td class="bx-num"><?= $nzB($rv['menge']) ?> <?= h((string)($rv['einheit'] ?: '')) ?></td>
        <td><?= bx_badge('reserviert','info') ?></td>
        <td><?php if ($rv['charge_nr']): ?><?= bx_badge('aus Bestand','ok') ?><?php elseif (!empty($rv['angekommen_am'])): ?><?= bx_badge('geliefert','ok') ?><?php else: ?><?= bx_badge('unterwegs','warn') ?><?php endif; ?></td>
        <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Reservierung aufheben?');"><input type="hidden" name="aktion" value="bulk_res_storno"><input type="hidden" name="res_id" value="<?= (int)$rv['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Aufheben</button></form></td>
      </tr>
      <?php endforeach; ?>
      <?php foreach ($lwZu as $lz): ?>
      <tr>
        <td><?= $lz['item_name'] ? h((string)$lz['item_name']) . ' · ' : '' ?>Charge <?= h((string)($lz['charge_nr'] ?: $lz['id'])) ?><?= $lz['mhd'] ? ' · MHD ' . h(date('d.m.Y', strtotime((string)$lz['mhd']))) : '' ?></td>
        <td class="bx-num"><?= $mengeLes($lz['menge_verfuegbar'], ($lz['einheit'] ?? '') !== '' ? $lz['einheit'] : null) ?></td>
        <td><?= bx_badge('fest zugewiesen','ok') ?></td>
        <td><?= ($lz['status'] ?? '') === 'frei' ? bx_badge('im Lager','ok') : bx_badge((string)$lz['status'],'warn') ?></td>
        <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Zuweisung dieser Charge aufheben? Die Ware wird wieder freie Lagerware.');"><input type="hidden" name="aktion" value="charge_loesen"><input type="hidden" name="charge_id" value="<?= (int)$lz['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Lösen</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <?php if ($hatQuellen): ?>
  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;margin:0;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="material_binden">
    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Quelle (Bestellung oder Charge aus dem Lager)
      <select name="quelle" class="rscombo" style="min-width:360px" required>
        <option value="">– wählen –</option>
        <?php if ($bulkQ['bestellungen']): ?><optgroup label="Bestellung (auch unterwegs) – wird reserviert">
          <?php foreach ($bulkQ['bestellungen'] as $bq): if ($bq['frei'] <= 0.0001) continue; ?>
            <option value="bestellung:<?= (int)$bq['bp_id'] ?>">Bestellung <?= h((string)$bq['nummer']) ?><?= $bq['lieferant'] ? ' · ' . h((string)$bq['lieferant']) : '' ?> · <?= !empty($bq['angekommen_am']) ? 'geliefert' : 'unterwegs' ?> · frei <?= $mengeLes($bq['frei'], $bq['einheit'] ?? null) ?></option>
          <?php endforeach; ?>
        </optgroup><?php endif; ?>
        <?php if ($lwQ['chargen']): ?><optgroup label="Passende Charge im Lager – wird fest zugewiesen">
          <?php foreach ($lwQ['chargen'] as $cq): ?>
          <option value="charge:<?= (int)$cq['id'] ?>">Charge <?= h((string)($cq['charge_nr'] ?: $cq['id'])) ?><?= $cq['mhd'] ? ' · MHD ' . h(date('d.m.Y', strtotime((string)$cq['mhd']))) : '' ?> · frei <?= $mengeLes($cq['menge_verfuegbar'], ($cq['einheit'] ?? '') !== '' ? $cq['einheit'] : null) ?></option>
          <?php endforeach; ?>
        </optgroup><?php endif; ?>
        <?php if ($lwQ['weitere']): ?><optgroup label="Andere Fertigware im Lager – wird fest zugewiesen">
          <?php foreach ($lwQ['weitere'] as $cq): ?>
          <option value="charge:<?= (int)$cq['id'] ?>"><?= h((string)$cq['item_name']) ?><?= $cq['artikelnummer'] ? ' (' . h((string)$cq['artikelnummer']) . ')' : '' ?> · Charge <?= h((string)($cq['charge_nr'] ?: $cq['id'])) ?><?= $cq['mhd'] ? ' · MHD ' . h(date('d.m.Y', strtotime((string)$cq['mhd']))) : '' ?> · frei <?= $mengeLes($cq['menge_verfuegbar'], ($cq['einheit'] ?? '') !== '' ? $cq['einheit'] : null) ?></option>
          <?php endforeach; ?>
        </optgroup><?php endif; ?>
      </select>
    </label>
    <label style="display:flex;flex-direction:column;gap:3px;font-size:12px" class="muted">Menge (<?= $bulkEinh ?> Bulk)<?= $bulkLes($mbOffen) ?>
      <input type="text" inputmode="<?= $bulkInM ?>" name="menge" <?= $bulkTaus ?> value="<?= $bulkInput($mbOffen) ?>" style="min-width:130px">
    </label>
    <button class="btn btn-primary btn-sm" type="submit" data-busy="Binde…">Binden</button>
  </form>
  <div class="muted" style="font-size:12px;margin-top:6px"><strong>Bestellung</strong> = reserviert die Menge aus einer (auch noch unterwegs) Bulk-Bestellung · <strong>Charge</strong> = weist eine konkrete Lagercharge fest zu (der Kunde bekommt genau diese). Teilmengen werden abgespalten, der freie Rest bleibt verfügbar und kann weiteren Aufträgen dienen. <?= $produziertPkg>0 ? 'Noch benötigt (Rest)' : 'Benötigt' ?>: <strong><?= $bulkMng($restStk) ?></strong><?= $gebundenSumme>0 ? ', bereits gebunden ' . $bulkMng($gebundenSumme) : '' ?>.</div>
  <?php else: ?>
  <div class="muted" style="font-size:12px">Keine Bulkware verfügbar: weder eine (auch unterwegs) Bulk-Bestellung des Produkts noch eine freie Fertigware-Charge im Lager. Erst eine Lieferantenbestellung anlegen oder Bestand einbuchen, dann hier binden.</div>
  <?php endif; ?>
  <?php endif; /* Material binden (Admin) */ ?>

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

</div>

<?php // Reiter „Lager": Fertigware-Chargen (physischer Bestand), Einlagern (an Lager 1 übergeben) und die
      // Wareneingänge zu diesem Auftrag – zuvor im Reiter „Produktion", hierher ausgelagert. ?>
<div class="bx-panel" data-panel="lager">
  <h2 style="margin-top:0">Lager</h2>

  <?php if ($einlagerNoetig): ?>
  <div style="margin-bottom:16px">
    <div class="k muted" style="margin-bottom:4px">Einlagern</div>
    <form method="post" style="margin:0" onsubmit="return confirm('Fertige Ware in Lager 1 (Warenlager) buchen? Danach ist der Auftrag versandbereit; das Lager entscheidet beim Versand zwischen Kunde und Lager 2.');">
      <input type="hidden" name="aktion" value="einlagern_nachholen">
      <button class="btn btn-primary btn-sm" type="submit">In Lager 1 buchen</button>
    </form>
    <div class="muted" style="font-size:12px;margin-top:4px">Produktion ist fertig. Die Ware wird in <strong>Lager 1</strong> gebucht und der Auftrag wird <strong>versandbereit</strong>. Ob sie an den Kunden geht oder an Lager 2 (Fremdlager), entscheidet das Lager anschließend beim Versand.</div>
  </div>
  <?php endif; ?>

  <h3 style="margin:0 0 6px;font-size:14px;font-weight:600">Fertigware (Charge)</h3>
  <?php if ($paChargen): ?>
  <div class="bx-tablewrap" style="margin-bottom:8px"><table class="bx-table">
    <thead><tr><th>Charge</th><th>MHD</th><th class="bx-num">verfügbar</th></tr></thead>
    <tbody>
      <?php foreach ($paChargen as $ch): ?>
      <tr>
        <td><?= h((string)$ch['charge_nr']) ?></td>
        <td><?= !empty($ch['mhd']) ? h(date('d.m.Y', strtotime((string)$ch['mhd']))) : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= rtrim(rtrim(number_format((float)($ch['menge_verfuegbar'] ?? 0),3,',','.'),'0'),',') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php elseif ($pa && $chargePlan !== ''): ?>
    <div class="muted" style="margin-bottom:8px">Noch nicht eingelagert · geplante Charge <strong><?= h($chargePlan) ?></strong>.</div>
  <?php else: ?>
    <div class="muted" style="margin-bottom:8px">Noch keine Fertigware-Charge im Lager.</div>
  <?php endif; ?>

  <h3 style="margin:18px 0 6px;font-size:14px;font-weight:600">Wareneingänge zu diesem Auftrag</h3>
  <?php if ($wareneingaenge): ?>
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
  <?php else: ?>
    <div class="muted">Keine Wareneingänge erfasst.</div>
  <?php endif; ?>
</div>

<?php // Rückabwicklung: Fremd beim Lieferanten bestellt, soll aber doch selbst produziert werden. Nur Admin, nur Fremd.
if ($istFremd && $istAdmin): ?>
<div class="bx-panel" data-panel="produktion" style="border-color:#e6c4c0;background:rgba(230,196,192,.10)">
  <h2 style="margin-top:0">Doch selbst produzieren (Rückabwicklung)</h2>
  <p style="margin-top:0">Der Lieferant kann oder will den Auftrag <strong>nicht</strong> fremdfertigen? Der Knopf storniert die <strong>Bulk-Bestellung beim Lieferanten</strong> (er wird per Nachricht und Mail informiert) und stellt auf <strong>Eigenproduktion</strong> um – danach steht der Rohstoffbedarf im Einkauf. Verpackungs-/Etikett-Bestellungen bleiben erhalten. Nur möglich, solange nichts produziert und keine Bulkware eingebucht ist.</p>
  <form method="post" style="margin:0" onsubmit="return confirm('Bulk-Bestellung stornieren, Lieferant informieren und auf Eigenproduktion umstellen?');">
    <input type="hidden" name="aktion" value="rueckabwicklung">
    <button class="btn btn-primary" type="submit">Doch selbst produzieren</button>
  </form>
</div>
<?php endif; ?>

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
          <td class="bx-num"><?= $nz($bd['benoetigt']) ?> <?= h($bd['einheit']) ?><?php if (($bd['zu_bestellen'] ?? $bd['fehlt']) > 0.0001): ?><br><span style="color:#8f231b;font-size:12px">fehlt <?= $nz($bd['zu_bestellen'] ?? $bd['fehlt']) ?></span><?php elseif ($bd['fehlt'] > 0.0001): ?><br><span style="color:#8a6d00;font-size:12px">in Quarantäne – <a href="<?= h(quarantaene_freigabe_url((int)$bd['item_id'])) ?>">freigeben</a></span><?php else: ?><br><span class="bx-ok" style="font-size:12px">auf Lager</span><?php endif; ?></td>
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
    <?php foreach (all("SELECT id, nummer FROM beleg WHERE auftrag_id=? AND typ='lieferschein' ORDER BY id", [(int)$a['id']]) as $lsDok): ?>
      <a class="btn btn-ghost btn-sm" href="?p=lieferschein_pdf&id=<?= (int)$lsDok['id'] ?>" target="_blank" rel="noopener">Lieferschein <?= h((string)$lsDok['nummer']) ?> (PDF)</a>
    <?php endforeach; ?>
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
  <?php if ((string)$a['status'] === 'storniert'): ?>
  <p class="muted" style="margin-top:0">Dieser Auftrag ist <strong>storniert</strong>. Du kannst ihn endgültig löschen – das räumt Produktionsauftrag, Chargen und die stornierte Rechnung mit auf (Bestellungen bleiben erhalten). Das zugehörige <strong>Angebot bleibt unverändert</strong> (wird nicht wieder geöffnet).</p>
  <form method="post" style="margin:0" onsubmit="return confirm('Stornierten Auftrag <?= h($a['nummer']) ?> endgültig löschen? Das kann nicht rückgängig gemacht werden.');">
    <input type="hidden" name="aktion" value="auftrag_hart_loeschen">
    <button class="btn btn-danger" type="submit" data-busy="Lösche…">Stornierten Auftrag endgültig löschen</button>
  </form>
  <?php else: ?>
  <p class="muted" style="margin-top:0">Stornieren setzt den Auftrag auf <strong>storniert</strong> und storniert eine offene Rechnung per Gutschrift. Menge und Preis bleiben zur Nachvollziehbarkeit erhalten.</p>
  <form method="post" style="margin:0 0 16px" onsubmit="return confirm('Auftrag <?= h($a['nummer']) ?> stornieren? Eine offene Rechnung wird per Gutschrift storniert.');">
    <input type="hidden" name="aktion" value="auftrag_stornieren">
    <button class="btn btn-ghost btn-sm" type="submit" data-busy="Storniere…">Auftrag stornieren</button>
  </form>
  <p class="muted" style="margin-top:0">Löscht diese Auftragsbestätigung samt Produktionsauftrag und (unbezahlter) Rechnung. Das zugehörige Angebot wird wieder <strong>offen</strong>, und Sie springen zurück zur Anfrage, um es anzupassen oder neu zu senden.</p>
  <form method="post" style="margin:0" onsubmit="return confirm('Auftragsbestätigung <?= h($a['nummer']) ?> löschen? Produktionsauftrag und unbezahlte Rechnung werden entfernt; das Angebot wird wieder offen.');">
    <input type="hidden" name="aktion" value="auftrag_zurueck">
    <button class="btn btn-danger" type="submit" data-busy="Lösche…">Löschen &amp; zurück zur Anfrage</button>
  </form>
  <?php endif; ?>
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
  // H0-Schrittleiste: ein Schritt mit data-goto öffnet den zugehörigen Reiter + scrollt nach oben.
  document.querySelectorAll('[data-goto]').forEach(function(g){ g.addEventListener('click', function(e){ e.preventDefault(); activate(g.getAttribute('data-goto')); window.scrollTo({top:0,behavior:'smooth'}); }); });
  var start = 'details';
  try { var s = sessionStorage.getItem(KEY); if (s) start = s; } catch(e){}
  <?php if (isset($_GET['analyse'])): ?>start = 'dokumente';<?php endif; ?>
  // Direktlink mit Anker (z. B. #bulkreserve aus der Produktionsseite): passenden Reiter öffnen + hinscrollen.
  var hash = (location.hash || '').replace('#','');
  if (hash === 'bulkreserve') start = 'material';
  activate(start);
  if (hash) { var el = document.getElementById(hash); if (el) setTimeout(function(){ el.scrollIntoView({behavior:'smooth'}); }, 60); }
})();
</script>
<script>
// Ganzzahl-Felder mit Tausenderpunkt (z. B. Produktionsmenge): beim Laden + bei Blur formatieren (1.080.000).
(function(){
  function fmt(el){ var d=(el.value||'').replace(/\D/g,''); el.value = d ? d.replace(/\B(?=(\d{3})+(?!\d))/g,'.') : ''; }
  document.querySelectorAll('input[data-tausender]').forEach(function(el){ fmt(el); el.addEventListener('blur', function(){ fmt(el); }); });
})();
</script>
<?php render_footer(); ?>
