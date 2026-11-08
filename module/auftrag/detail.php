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
    auftrag_name_behaelter_nachziehen($id, 'auftrag');            // „· 100 ml Glas“ im Auftrags-Namen mitziehen
    if (function_exists('bedarf_bump')) bedarf_bump();            // Material-/Bedarf-Cache neu rechnen
    header('Location: ?p=auftrag&id=' . $id . '&vpok=1'); exit;
}

// Stift-Kacheln im Reiter Details (Nico 08.11.2026, „Auftragsseite entschlacken" Teil 1): EIN Speichern-Weg für Verpackung,
// Füllmenge/Stk je Packung, Kapselgröße, Tablettenform, Gewicht je Einheit. Stammdaten-Logik: Speichern schreibt ans PRODUKT
// (gilt für künftige Aufträge) bzw. an die Rezeptur; „nur für diesen Auftrag" gibt es dort, wo der Auftrag einen eigenen Wert
// führt (Verpackung: auftrag.verpackung_id; Füllmenge nur ohne Produkt-Wert, da der Produkt-Wert Vorrang hat).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'kachel_speichern') {
    if (!(has_role('admin') || has_role('sales'))) { header('Location: ?p=auftrag&id=' . $id); exit; }
    $au   = one("SELECT a.id, a.produkt_id, a.verpackung_id, a.stueck, COALESCE(a.rezeptur_id, p.rezeptur_id) AS rid, p.einheiten_pro_packung, p.verpackung_id AS p_vp
                 FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$id]) ?: [];
    $pid  = (int)($au['produkt_id'] ?? 0); $rid = (int)($au['rid'] ?? 0);
    $nurA = !empty($_POST['nur_auftrag']) || $pid <= 0;
    $feld = (string)($_POST['feld'] ?? '');
    if ($feld === 'verpackung') {
        $vp = (int)($_POST['verpackung_id'] ?? 0);
        if ($vp > 0 && !scalar("SELECT id FROM item WHERE id=? AND kategorie='verpackung'", [$vp])) $vp = 0;
        q("UPDATE auftrag SET verpackung_id=? WHERE id=?", [$vp ?: null, $id]);
        auftrag_name_behaelter_nachziehen($id, 'auftrag');
        aenderung_protokollieren('auftrag', $id, ['verpackung_id' => $au['verpackung_id'] ?? null], ['verpackung_id' => $vp ?: null], 'auftrag');
        if (!$nurA && $vp > 0 && $vp !== (int)($au['p_vp'] ?? 0)) {
            q("UPDATE produkt SET verpackung_id=? WHERE id=?", [$vp, $pid]);
            produkt_name_behaelter_nachziehen($pid, (int)($au['p_vp'] ?? 0), $vp, 'auftrag');
            aenderung_protokollieren('produkt', $pid, ['verpackung_id' => $au['p_vp'] ?? null], ['verpackung_id' => $vp], 'auftrag');
        }
    } elseif ($feld === 'fuellmenge') {
        $n = (int) round((float) zahl_lesen((string)($_POST['einheiten'] ?? '0')));
        if ($n > 0) {
            if ($pid > 0 && !($nurA && (int)($au['einheiten_pro_packung'] ?? 0) <= 0)) {
                q("UPDATE produkt SET einheiten_pro_packung=? WHERE id=?", [$n, $pid]);
                aenderung_protokollieren('produkt', $pid, ['einheiten_pro_packung' => $au['einheiten_pro_packung'] ?? null], ['einheiten_pro_packung' => $n], 'auftrag');
            } else {
                q("UPDATE auftrag SET stueck=? WHERE id=?", [$n, $id]);
                aenderung_protokollieren('auftrag', $id, ['stueck' => $au['stueck'] ?? null], ['stueck' => $n], 'auftrag');
            }
        }
    } elseif ($rid > 0 && in_array($feld, ['kapsel', 'tablettenform', 'gewicht'], true)) {
        $alt = one("SELECT kapselgroesse_id, tabletten_form, einheit_fuellmenge FROM rezeptur WHERE id=?", [$rid]) ?: [];
        if ($feld === 'kapsel') {
            $kg = ($_POST['kapselgroesse_id'] ?? '') !== '' ? (int)$_POST['kapselgroesse_id'] : null;
            q("UPDATE rezeptur SET kapselgroesse_id=? WHERE id=?", [$kg, $rid]); $neu = ['kapselgroesse_id' => $kg];
        } elseif ($feld === 'tablettenform') {
            $tf = trim((string)($_POST['tabletten_form'] ?? '')); $tf = array_key_exists($tf, tabletten_formen()) ? $tf : null;
            q("UPDATE rezeptur SET tabletten_form=? WHERE id=?", [$tf, $rid]); $neu = ['tabletten_form' => $tf];
        } else {
            $g = trim((string)($_POST['einheit_fuellmenge'] ?? '')); $g = $g !== '' ? (float) zahl_lesen($g) : null;
            q("UPDATE rezeptur SET einheit_fuellmenge=? WHERE id=?", [$g, $rid]); $neu = ['einheit_fuellmenge' => $g];
        }
        aenderung_protokollieren('rezeptur', $rid, $alt, $neu, 'auftrag');
    }
    if (function_exists('bedarf_bump')) bedarf_bump();
    header('Location: ?p=auftrag&id=' . $id . '&kok=1'); exit;
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
// Soll-Kette 4b: Anweisung „Teilmenge produzieren“ ans Werk / zurückziehen (nur Admin).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && in_array(($_POST['aktion'] ?? ''), ['teilmenge_anweisen', 'teilmenge_zurueck'], true)) {
    $tmZ = '?p=auftrag&id=' . $id . '&tab=produktion';
    if (!has_role('admin')) { header('Location: ' . $tmZ . '&tmfehler=' . urlencode('Nur Admins.')); exit; }
    $paT = auftrag_aktiver_pa($id);
    if (($_POST['aktion'] ?? '') === 'teilmenge_zurueck') {
        if ($paT) q("UPDATE aufgabe SET status='erledigt', erledigt_am=? WHERE id=? AND ref_typ='teilmenge' AND ref_id=? AND status='offen'", [gmdate('Y-m-d H:i:s'), (int)($_POST['aufgabe_id'] ?? 0), (int)$paT['id']]);
        header('Location: ' . $tmZ); exit;
    }
    $r = $paT ? pa_teilmenge_anweisen((int)$paT['id'], (int) preg_replace('/\D+/', '', (string)($_POST['tm_menge'] ?? '')), (string)($_POST['tm_umfang'] ?? ''), (int)($_POST['tm_prio'] ?? 2))
              : ['ok' => false, 'fehler' => 'Kein Produktionsauftrag.'];
    if (!empty($r['ok'])) log_aktivitaet('auftrag', $id, 'team', 'Teilmenge ans Werk angewiesen: ' . (string)($_POST['tm_menge'] ?? '') . ' Pkg (' . (teilmenge_umfaenge()[(string)($_POST['tm_umfang'] ?? '')] ?? '') . ').', 'notiz');
    header('Location: ' . $tmZ . (!empty($r['ok']) ? '&tmok=1' : '&tmfehler=' . urlencode((string)$r['fehler']))); exit;
}
// Soll-Kette Teil 1: Beschaffungsweg festlegen / an die Bedarfsliste senden / direkt bestellen (nur Admin).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && in_array(($_POST['aktion'] ?? ''), ['beschaffungsweg_setzen', 'beschaffung_bedarf', 'beschaffung_bestellen'], true)) {
    $bwZ = '?p=auftrag&id=' . $id . '&tab=beschaffung';
    if (!has_role('admin')) { header('Location: ' . $bwZ . '&bwfehler=' . urlencode('Nur Admins.')); exit; }
    $akt = (string)$_POST['aktion'];
    if ($akt === 'beschaffungsweg_setzen') {
        $r = auftrag_beschaffungsweg_setzen($id, (string)($_POST['weg'] ?? ''), (string)(current_user()['name'] ?? ''));
        header('Location: ' . $bwZ . (!empty($r['ok']) ? '&bwok=' . urlencode('Weg festgelegt.') : '&bwfehler=' . urlencode((string)$r['fehler']))); exit;
    }
    if ($akt === 'beschaffung_bedarf') {
        $paB = auftrag_aktiver_pa($id);
        if ($paB) { q("UPDATE produktionsauftrag SET bedarf_gemeldet=COALESCE(bedarf_gemeldet, ?) WHERE id=?", [gmdate('Y-m-d H:i:s'), (int)$paB['id']]); bedarf_bump();
                    log_aktivitaet('auftrag', $id, 'team', 'Bedarf an die Bedarfsliste gemeldet (' . (beschaffungswege()[pa_beschaffungsweg($paB)] ?? '') . ').', 'notiz'); }
        header('Location: ' . $bwZ . '&bwok=' . urlencode('An die Bedarfsliste gemeldet.')); exit;
    }
    $bid = auftrag_beschaffung_bestellen($id, (string)($_POST['weg'] ?? ''), (int)($_POST['lieferant_id'] ?? 0));
    // Dritter Weg „Direkt bestellt (Telefon/Mail)": Entwurf sofort als bestellt + bestätigt abschließen, ohne Mail.
    if ($bid && ($_POST['modus'] ?? '') === 'direkt') bestellung_direkt_abschliessen($bid, null, null, 'aus dem Beschaffungsweg');
    header('Location: ' . ($bid ? '?p=bestellung&id=' . $bid : $bwZ . '&bwfehler=' . urlencode('Bestellung nicht möglich (Weg nicht festgelegt oder kein Angebot).'))); exit;
}
// Beschaffungsweg: vorhandenen Bestand / offene Bestellung dem Auftrag zuordnen bzw. lösen (Nico 05.11.2026, nur Admin).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && in_array(($_POST['aktion'] ?? ''), ['deckung_koppeln', 'deckung_loesen', 'deckung_festlegen'], true)) {
    $bwZ = '?p=auftrag&id=' . $id . '&tab=beschaffung';
    if (!has_role('admin')) { header('Location: ' . $bwZ . '&bwfehler=' . urlencode('Nur Admins.')); exit; }
    // Schon gedeckt (z. B. vorher gebundene Ware oder Bestellung für den Auftrag), PA aber noch „Beschaffungsweg offen“.
    if ($_POST['aktion'] === 'deckung_festlegen') {
        $wn = auftrag_deckung_weg_festlegen($id, ($_POST['weg'] ?? '') === 'premix' ? 'premix' : 'kapsel', (string)(current_user()['name'] ?? ''));
        header('Location: ' . $bwZ . ($wn !== '' ? '&bwok=' . urlencode('Beschaffungsweg festgelegt: ' . $wn . '.') : '&bwfehler=' . urlencode('Weg nicht festgelegt (Produktion begonnen oder schon festgelegt).'))); exit;
    }
    $r = ($_POST['aktion'] === 'deckung_koppeln')
        ? auftrag_deckung_koppeln($id, (string)($_POST['quelle'] ?? ''), (int)($_POST['qid'] ?? 0), zahl_lesen((string)($_POST['menge'] ?? '0'), true), (string)(current_user()['name'] ?? ''))
        : auftrag_deckung_loesen($id, (string)($_POST['typ'] ?? ''), (int)($_POST['zid'] ?? 0));
    $msg = !empty($r['ok']) ? trim((string)($r['meldung'] ?? 'Gespeichert.') . ' ' . (string)($r['warnung'] ?? '')) : '';
    header('Location: ' . $bwZ . (!empty($r['ok']) ? '&bwok=' . urlencode($msg) : '&bwfehler=' . urlencode((string)($r['fehler'] ?? 'Nicht möglich.')))); exit;
}
// „Ware da, aber nicht zugeordnet“ (Nico 04.11.2026): Rohstoff-Charge als Bulk übernehmen bzw. freie Bulk-Charge binden.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'bulk_diag') {
    $zur = (string)($_POST['zurueck'] ?? '');
    if (!preg_match('/^\?p=[a-z_]+(&[A-Za-z0-9_=%-]*)*$/', $zur)) $zur = '?p=auftrag&id=' . $id;   // nur interne Rücksprünge
    if (!has_role('admin')) { header('Location: ' . $zur . '&diagfehler=' . urlencode('Nur Admins.')); exit; }
    $r = auftrag_bulk_diagnose_aktion($id, (string)($_POST['was'] ?? ''), (int)($_POST['charge_id'] ?? 0));
    header('Location: ' . $zur . (!empty($r['ok']) ? '&diagok=' : '&diagfehler=') . urlencode((string)$r['meldung'])); exit;
}
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

// Teil 2 „Auftragsseite entschlacken": Zuordnen je Materialzeile (Rohstoff, Glas, Deckel, Etikett, Leerkapsel, Premix) –
// Reservierung auf eine Lagercharge oder offene Bestellposition. Bulk/Fertigware weiter über material_binden.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && in_array(($_POST['aktion'] ?? ''), ['material_zuordnen', 'material_loesen'], true)) {
    if (!(has_role('admin') || has_role('production'))) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Keine Berechtigung.')); exit; }
    if ($_POST['aktion'] === 'material_loesen') {
        auftrag_material_loesen($id, (int)($_POST['res_id'] ?? 0));
        header('Location: ?p=auftrag&id=' . $id . '&mzok=' . rawurlencode('Zuordnung aufgehoben.')); exit;
    }
    [$qTyp, $qId] = array_pad(explode(':', (string)($_POST['quelle'] ?? ''), 2), 2, '');
    $r = auftrag_material_zuordnen($id, (int)($_POST['item_id'] ?? 0), $qTyp === 'charge' ? 'charge' : 'bestellung', (int)$qId,
                                   (float) zahl_lesen((string)($_POST['menge'] ?? '0')), (string)(current_user()['name'] ?? ''));
    header('Location: ?p=auftrag&id=' . $id . (!empty($r['ok']) ? '&mzok=' . rawurlencode((string)$r['meldung']) : '&expressfehler=' . urlencode((string)($r['fehler'] ?? 'Zuordnen nicht möglich.')))); exit;
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
        // Staffel nach MENGE (Nico 08.11.2026, wie Beschaffungsweg/Einkaufsliste): je Lieferant die größte Staffel, deren
        // Mindestmenge der Bedarf erreicht – liegt der Bedarf unter seiner kleinsten Staffel, gilt diese (Mindestmenge).
        // Vorher: alle Zeilen nur nach Preis sortiert -> „ab 300 kg" wurde bei 225 kg Bedarf als günstigste gerechnet.
        $rows = all("SELECT lp.lieferant_id, lp.menge_ab, lp.preis, lp.waehrung, COALESCE(l.firma, lp.lieferant_name, '') AS firma
                     FROM lieferant_preis lp LEFT JOIN lieferanten l ON l.id=lp.lieferant_id
                     WHERE lp.item_id=? AND lp.preis>0 ORDER BY lp.menge_ab ASC", [(int)$bd['item_id']]);
        $jeLief = [];
        foreach ($rows as $ao) {
            $lk = (int)$ao['lieferant_id'] . '|' . $ao['firma'] . '|' . strtoupper((string)($ao['waehrung'] ?: 'EUR'));
            if (!isset($jeLief[$lk]) || (float)$ao['menge_ab'] <= (float)$bd['benoetigt'] + 1e-9) $jeLief[$lk] = $ao;
        }
        $angebote = array_values($jeLief);
        usort($angebote, fn($x, $y) => (float)$x['preis'] <=> (float)$y['preis']);
        // Gibt es schon eine echte Bestellung zu diesem Auftrag für den Rohstoff, gilt deren Preis.
        $echtB = one("SELECT bp.ek_preis AS preis, b.nummer, COALESCE(l.firma,'') AS firma FROM bestellung_position bp
                      JOIN bestellung b ON b.id=bp.bestellung_id LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                      WHERE bp.auftrag_id=? AND bp.item_id=? AND bp.ek_preis>0 AND (b.status IN ('gesendet','bestellt','geliefert') OR b.angekommen_am IS NOT NULL)
                      ORDER BY b.id DESC LIMIT 1", [$id, (int)$bd['item_id']]);
        if ($echtB) array_unshift($angebote, ['lieferant_id' => 0, 'menge_ab' => 0, 'preis' => $echtB['preis'], 'waehrung' => 'EUR',
                                              'firma' => trim($echtB['firma'] . ' (Bestellung ' . $echtB['nummer'] . ')'), 'bestellt' => true]);
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
// Stift je änderbarer Kachel (öffnet ein kleines Fenster mit genau diesem Feld). Herstellung springt in den Reiter Beschaffungsweg.
$darfStift = has_role('admin') || has_role('sales');
$stiftIco  = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
$stift = fn(string $dlg) => $darfStift ? '<button type="button" class="bx-stift" title="Bearbeiten" onclick="document.getElementById(\'' . $dlg . '\').showModal()">' . $stiftIco . '</button>' : '';
$kachelK = fn(string $label, string $knopf = '') => '<div class="k" style="display:flex;justify-content:space-between;align-items:center;gap:8px"><span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . $label . '</span>' . $knopf . '</div>';
$knEff = trim((string)($a['produkt_kundenname'] ?? ''));
$kachelnRender = function() use ($stBg, $stFg, $stText, $a, $glasName, $fehltCard, $einhProP, $gesamtStk, $groesseLbl, $istFremd, $etikettBadge, $eur, $istFuellG, $designLbl, $stift, $kachelK, $darfStift, $stiftIco, $knEff, $rezeptur) {
    echo '<div class="bx-cards" style="margin-bottom:16px">';
    echo '<div class="bx-card bx-card-status" title="Status" style="background:' . $stBg . ';color:' . $stFg . ';border:1px solid rgba(0,0,0,.15)"><div class="v" style="color:' . $stFg . '">' . h($stText) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Menge (Packungen)</div><div class="v">' . number_format((int)$a['menge'], 0, ',', '.') . '</div></div>';
    // Glas + Kapselzahl IMMER zeigen (auch wenn leer) – sonst sieht man bei alten Nachbestellungen nicht, dass es fehlt.
    echo '<div class="bx-card">' . $kachelK('Verpackung (Glas)', $stift('dlgVerp')) . '<div class="v" style="font-size:16px">' . ($glasName !== '' ? h($glasName) : $fehltCard('nicht gesetzt')) . '</div></div>';
    if ($istFuellG) {
        echo '<div class="bx-card">' . $kachelK('Füllmenge je Packung', $stift('dlgMenge')) . '<div class="v">' . ($einhProP > 0 ? number_format($einhProP, 0, ',', '.') . ' g' : $fehltCard('nicht gesetzt')) . '</div></div>';
        if ($gesamtStk > 0) echo '<div class="bx-card"><div class="k">Gesamt-Füllmenge</div><div class="v">' . ($gesamtStk >= 1000 ? rtrim(rtrim(number_format($gesamtStk / 1000, 3, ',', '.'), '0'), ',') . ' kg' : number_format($gesamtStk, 0, ',', '.') . ' g') . '</div></div>';
    } else {
        echo '<div class="bx-card">' . $kachelK('Stk je Packung', $stift('dlgMenge')) . '<div class="v">' . ($einhProP > 0 ? number_format($einhProP, 0, ',', '.') : $fehltCard('nicht gesetzt')) . '</div></div>';
        if ($gesamtStk > 0) echo '<div class="bx-card"><div class="k">Gesamtstückzahl</div><div class="v">' . number_format($gesamtStk, 0, ',', '.') . '</div></div>';
    }
    if ($groesseLbl !== '') { $grK = match((string)($rezeptur['darreichungsform'] ?? '')) { 'kapsel','softgel'=>'Kapselgröße', 'tablette'=>'Tablettenform', 'gummi','stick','pulver','gel','fluessig','oel'=>'Größe je Einheit', default=>'Größe/Form' };
        echo '<div class="bx-card">' . $kachelK($grK, $stift('dlgMenge')) . '<div class="v">' . h($groesseLbl) . '</div></div>'; }
    echo '<div class="bx-card">' . $kachelK('Herstellung', $darfStift ? '<a class="bx-stift" href="#" data-goto="beschaffung" title="Im Reiter Beschaffungsweg festlegen">' . $stiftIco . '</a>' : '') . '<div class="v">' . ($istFremd ? bx_badge('Zukauf','info') : bx_badge('Eigenproduktion','ok')) . '</div></div>';
    echo '<div class="bx-card">' . $kachelK(h($designLbl), auftrag_braucht_etikett((int)$a['id']) ? $stift('dlgEtikett') : '') . '<div class="v">' . $etikettBadge . '</div></div>';
    if (!empty($a['produkt_id']) && !empty($a['kunde_id']))
        echo '<div class="bx-card">' . $kachelK('Name beim Kunden', $stift('dlgName')) . '<div class="v" style="font-size:15px">' . ($knEff !== '' ? h($knEff) : '<span class="muted">wie intern</span>') . '</div></div>';
    if (!in_array((string)$a['status'], ['versendet', 'storniert'], true)) {
        $ltK = trim((string) preg_replace('/^Voraussichtliche Lieferung:\s*/u', '', auftrag_liefertermin_text($a)));
        echo '<div class="bx-card">' . $kachelK('Lieferung', $stift('dlgLiefer')) . '<div class="v" style="font-size:15px">' . ($ltK !== '' ? h($ltK) : '<span class="muted">nicht gesetzt</span>') . '</div></div>';
    }
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
        // Soll-Kette 4c: „Quelle festlegen“ + „Freigeben“ sind EIN Schritt – der Beschaffungsweg (keine eigene Freigabe mehr).
        ['n'=>2,'label'=>'Beschaffungsweg',       'state'=>$S($freigabeOk, !$freigabeOk),                     'goto'=>'beschaffung'],
        ['n'=>3,'label'=>'Bestellen',             'state'=>$S($matOk, $freigabeOk && $hatBest && !$matOk),    'href'=>'?p=einkaufsliste'],
        ['n'=>4,'label'=>'Wareneingang',          'state'=>$S($matOk && $hatWE, $freigabeOk && ($hatBest || $hatWE) && !$matOk), 'goto'=>'lager'],
        ['n'=>5,'label'=>'Produktion & Versand',  'state'=>$S($versendet, $inGang && !$versendet),            'goto'=>'produktion'],
    ];
    // Markenfarben: erledigt = Grün, läuft = Lime (hervorgehoben), offen = ruhig.
    $farbe = ['erledigt'=>['#1D9E75','#ffffff'], 'laeuft'=>['#C0F24E','#10210f'], 'offen'=>['var(--panel-2)','var(--muted)']];
?>
<div class="bx-panel" style="margin-bottom:16px;padding:14px 16px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <span style="color:var(--muted);font-size:13px">Ablauf dieses Auftrags</span>
    <?php if ($pa && ($pa['status'] ?? '') === 'vorbereitung'): ?><span><?= bx_badge('Beschaffungsweg offen','warn') ?></span><?php endif; ?>
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
<?php // Schnell-Banner „Beschaffungsweg festlegen“ (Herstellen/Einkaufen) entfernt (Nico 04.11.2026) – der Weg wird im Reiter Beschaffungsweg gewählt.
if (isset($_GET['freigabeok'])): ?>
<div class="bx-panel badge-ok" style="padding:10px 14px;margin:0 0 16px">Beschaffungsweg festgelegt – der Auftrag ist in Produktion und Einkauf.</div>
<?php endif; ?>
<div class="settabs" id="auftabs" style="margin-bottom:16px">
  <a href="#" class="on" data-tab="details">Details</a>
  <a href="#" data-tab="rezeptur">Rezeptur</a>
  <a href="#" data-tab="material">Material</a>
  <a href="#" data-tab="beschaffung">Beschaffungsweg</a>
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
<?php endif; ?>

<?php if (!empty($a['kunde_id'])) { echo kunde_nachricht_panel((int)$a['kunde_id'], (int)$a['id'], 0, 'details'); kunde_nachricht_gelesen((int)$a['kunde_id'], (int)$a['id'], 0, 'team'); } ?>
<div class="bx-panel" data-panel="details">
  <?php if (isset($_GET['kok']) || isset($_GET['vpok']) || isset($_GET['knok']) || isset($_GET['ltok']) || isset($_GET['etikettok'])): ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin-bottom:10px;display:inline-block">Gespeichert.</div><?php endif; ?>
  <?php $kachelnRender(); // Kennzahl-Kacheln; änderbare Werte per Stift (Fenster unten) ?>
  <?php // Stift-Fenster (Teil 1): je änderbarer Kachel genau dieses Feld. Liegen außerhalb jeder Tabelle/jedes Formulars.
  if ($darfStift):
      $dlgRez = !empty($rezeptur['id']) ? one("SELECT id, darreichungsform, kapselgroesse_id, tabletten_form, einheit_fuellmenge FROM rezeptur WHERE id=?", [(int)$rezeptur['id']]) : null;
      $dlgDarr = (string)($dlgRez['darreichungsform'] ?? '');
      $dlgAttr = in_array($dlgDarr, ['kapsel', 'softgel'], true) ? 'kapsel' : ($dlgDarr === 'tablette' ? 'tablette' : (in_array($dlgDarr, ['gummi', 'stick', 'pulver', 'gel', 'fluessig', 'oel'], true) ? 'gewicht' : ''));
      $dlgPEinh = !empty($a['produkt_id']) ? (int) scalar("SELECT COALESCE(einheiten_pro_packung,0) FROM produkt WHERE id=?", [(int)$a['produkt_id']]) : 0;
      $dlgKopf = fn(string $t, string $dlg) => '<div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;margin-bottom:12px"><h2 style="margin:0;font-size:17px">' . $t . '</h2><button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById(\'' . $dlg . '\').close()" aria-label="schließen">&#10005;</button></div>';
      $nurAuftragHaken = fn(bool $an = true, string $hinweis = '') => !empty($a['produkt_id'])
          ? '<label class="bx-check" style="margin:10px 0 0;font-weight:400"><input type="checkbox" name="nur_auftrag" value="1"' . ($an ? '' : ' disabled') . '> nur für diesen Auftrag</label>'
            . '<div class="muted" style="font-size:12px;margin-top:4px">' . ($hinweis !== '' ? $hinweis : 'Ohne Haken gilt der Wert am Produkt – auch für künftige Aufträge.') . '</div>'
          : '<div class="muted" style="font-size:12px;margin-top:8px">Kein Produkt verknüpft – gilt nur für diesen Auftrag.</div>'; ?>
  <dialog id="dlgVerp" class="bx-dialog">
    <?= $dlgKopf('Verpackung (Glas/Behälter)', 'dlgVerp') ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="aktion" value="kachel_speichern"><input type="hidden" name="feld" value="verpackung">
      <select name="verpackung_id"<?= count($vpListe ?? []) > 10 ? ' class="rscombo"' : '' ?> style="width:100%">
        <option value="">– kein Behälter (reine Bulkware) –</option>
        <?php foreach (($vpListe ?? []) as $vpi): ?><option value="<?= (int)$vpi['id'] ?>" <?= (int)($effVp ?? 0) === (int)$vpi['id'] ? 'selected' : '' ?>><?= h($vpi['name']) ?></option><?php endforeach; ?>
      </select>
      <?= $nurAuftragHaken() ?>
      <div style="margin-top:14px"><button class="btn btn-primary btn-sm" type="submit">Speichern</button></div>
    </form>
    <?php if (!empty($a['produkt_id']) && empty($effKspez)): ?>
    <form method="post" style="margin:16px 0 0;padding-top:12px;border-top:1px solid var(--line)" onsubmit="return confirm('Aus dem aktuellen Standard-Behälter einen bedruckten Behälter für diesen Kunden anlegen und diesem Auftrag zuweisen?');">
      <input type="hidden" name="aktion" value="verpackung_kundenspezifisch">
      <button class="btn btn-ghost btn-sm" type="submit">Bedruckten, kundenspezifischen Behälter anlegen</button>
      <div class="muted" style="font-size:12px;margin-top:4px">Eigener Lagerartikel je Produkt + Kunde (wie das Kundenetikett) – im Einkauf bestellbar, getrennt vom Standard-Behälter.</div>
    </form>
    <?php elseif (!empty($effKspez)): ?><div style="margin-top:12px;font-size:12px"><?= bx_badge('bedruckt · kundenspezifisch', 'info') ?></div><?php endif; ?>
  </dialog>

  <dialog id="dlgMenge" class="bx-dialog">
    <?= $dlgKopf($istFuellG ? 'Füllmenge je Packung' : 'Menge je Packung & Größe', 'dlgMenge') ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="aktion" value="kachel_speichern"><input type="hidden" name="feld" value="fuellmenge">
      <div class="bx-field" style="margin:0"><label><?= $istFuellG ? 'Füllmenge je Packung (g)' : 'Stk je Packung' ?></label>
        <input type="text" inputmode="numeric" name="einheiten" value="<?= $einhProP > 0 ? h(number_format($einhProP, 0, ',', '.')) : '' ?>" required></div>
      <?= $nurAuftragHaken($dlgPEinh <= 0, $dlgPEinh > 0 ? 'Gilt am Produkt (auch künftige Aufträge). Der Produktwert hat Vorrang – eine Abweichung nur für diesen Auftrag geht über „Für diesen Auftrag überarbeiten“ (Rezeptur).' : '') ?>
      <div style="margin-top:14px"><button class="btn btn-primary btn-sm" type="submit">Speichern</button></div>
    </form>
    <?php if ($dlgRez && $dlgAttr !== ''): ?>
    <form method="post" style="margin:16px 0 0;padding-top:12px;border-top:1px solid var(--line)">
      <input type="hidden" name="aktion" value="kachel_speichern">
      <?php if ($dlgAttr === 'kapsel'): $vSet = (int)($dlgRez['kapselgroesse_id'] ?? 0); $vAuto = $vSet <= 0 ? rezeptur_kapselgroesse((int)$dlgRez['id']) : null; ?>
        <input type="hidden" name="feld" value="kapsel">
        <div class="bx-field" style="margin:0"><label>Kapselgröße</label>
          <select name="kapselgroesse_id" style="width:100%"><option value="">– automatisch<?= $vAuto ? ' (' . h((string)$vAuto['name']) . ')' : '' ?> –</option>
            <?php foreach (all("SELECT id, name FROM kapselgroesse ORDER BY fuellmenge_mg") as $kg): ?><option value="<?= (int)$kg['id'] ?>" <?= $vSet === (int)$kg['id'] ? 'selected' : '' ?>><?= h($kg['name']) ?></option><?php endforeach; ?>
          </select></div>
      <?php elseif ($dlgAttr === 'tablette'): ?>
        <input type="hidden" name="feld" value="tablettenform">
        <div class="bx-field" style="margin:0"><label>Tablettenform</label>
          <select name="tabletten_form" style="width:100%"><option value="">– wählen –</option>
            <?php foreach (tabletten_formen() as $tk => $tl): ?><option value="<?= h($tk) ?>" <?= (string)($dlgRez['tabletten_form'] ?? '') === $tk ? 'selected' : '' ?>><?= h($tl) ?></option><?php endforeach; ?>
          </select></div>
      <?php else: [$vGl, $vGe] = form_gewicht_feld($dlgDarr); $vGv = (float)($dlgRez['einheit_fuellmenge'] ?? 0); ?>
        <input type="hidden" name="feld" value="gewicht">
        <div class="bx-field" style="margin:0"><label><?= h($vGl) ?> (<?= h($vGe) ?>)</label>
          <input type="text" inputmode="decimal" name="einheit_fuellmenge" value="<?= $vGv > 0 ? h(rtrim(rtrim(number_format($vGv, 3, ',', ''), '0'), ',')) : '' ?>" placeholder="z. B. 2,5"></div>
      <?php endif; ?>
      <div class="muted" style="font-size:12px;margin-top:4px">Gilt für die Rezeptur<?= $rezIstKopie ? ' (Auftrags-Kopie – nur dieser Auftrag)' : ' – Abweichung nur für diesen Auftrag über „Für diesen Auftrag überarbeiten“' ?>.</div>
      <div style="margin-top:14px"><button class="btn btn-primary btn-sm" type="submit">Speichern</button></div>
    </form>
    <?php endif; ?>
  </dialog>

  <?php if (auftrag_braucht_etikett($id)):
      $etDokA = etikett_datei($id); $etPrev = $etDokA ?: etikett_quelle($id);
      $etExt  = $etPrev ? strtolower(pathinfo((string)($etPrev['datei_orig'] ?: $etPrev['datei']), PATHINFO_EXTENSION)) : '';
      $etIstBild = in_array($etExt, ['jpg','jpeg','png','gif','webp','avif','bmp','svg'], true); $etIstPdf = $etExt === 'pdf';
      $etVers = !empty($a['produkt_id']) ? (int) scalar("SELECT etikett_version FROM item WHERE id=?", [etikett_item_fuer_auftrag_kunde($id, (int)$a['produkt_id'])]) : 0; ?>
  <dialog id="dlgEtikett" class="bx-dialog" style="max-width:820px">
    <?= $dlgKopf(h($designLbl), 'dlgEtikett') ?>
    <div class="bx-row" style="gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
      <?= $etikettBadge ?><?php if ($etVers > 0): ?> <span class="muted" style="font-size:13px">Version v<?= $etVers ?></span><?php endif; ?>
      <?php if ($etPrev): ?><span class="muted" style="font-size:13px">· <?= h((string)($etPrev['datei_orig'] ?: 'Design')) ?><?= !empty($etPrev['alt']) ? ' · aus einer Vorbestellung' : '' ?></span><?php endif; ?>
    </div>
    <?php if ($etPrev): ?>
    <div style="border:1px solid var(--line);border-radius:10px;background:var(--panel-2,#f6f7f8);padding:10px;display:flex;align-items:center;justify-content:center;min-height:180px">
      <?php if ($etIstBild): ?><img src="?p=dokument&id=<?= (int)$etPrev['id'] ?>" alt="Etikett-Vorschau" style="max-width:100%;max-height:360px;border-radius:6px">
      <?php elseif ($etIstPdf): ?><iframe src="?p=dokument&id=<?= (int)$etPrev['id'] ?>#toolbar=0&view=FitH" title="Etikett-Vorschau" style="width:100%;height:380px;border:0;border-radius:6px;background:#fff"></iframe>
      <?php else: ?><a class="btn btn-ghost btn-sm" href="?p=dokument&id=<?= (int)$etPrev['id'] ?>" target="_blank" rel="noopener">Datei öffnen</a><?php endif; ?>
    </div>
    <div style="margin-top:8px"><a class="btn btn-ghost btn-sm" href="?p=dokument&id=<?= (int)$etPrev['id'] ?>" download target="_blank" rel="noopener">Herunterladen (für die Druckerei)</a></div>
    <?php else: ?><div class="muted" style="padding:16px;border:1px dashed var(--line);border-radius:10px;text-align:center">Noch kein Design hinterlegt.</div><?php endif; ?>
    <div style="display:flex;flex-direction:column;gap:10px;margin-top:14px;padding-top:12px;border-top:1px solid var(--line)">
      <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center;margin:0;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="etikett_upload_team">
        <input type="file" name="etikett" required accept="application/pdf,image/*">
        <button class="btn btn-ghost btn-sm" type="submit"><?= $etDokA ? 'Design ersetzen' : 'Design hochladen' ?></button>
      </form>
      <?php if ($etDokA && !$etikettFrei): ?>
      <form method="post" class="bx-row" style="gap:8px;align-items:center;margin:0;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="etikett_freigeben_team">
        <input type="text" name="freigabe_name" required placeholder="Name (Freigabe im Namen des Kunden)" style="min-width:230px">
        <button class="btn btn-primary btn-sm" type="submit">Freigabe bestätigen</button>
      </form>
      <?php endif; ?>
      <?php if ($etDokA): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Design wirklich entfernen? Die Freigabe wird zurückgesetzt.');">
        <input type="hidden" name="aktion" value="etikett_del_team">
        <button class="btn btn-ghost btn-sm" type="submit">Entfernen</button>
      </form>
      <?php endif; ?>
      <div class="muted" style="font-size:12px">Das Design gehört zum Produkt (Kundenetikett, neue Version bei Freigabe eines neuen Designs); die Freigabe gilt für diesen Auftrag.</div>
    </div>
  </dialog>
  <?php endif; ?>

  <?php if (!empty($a['produkt_id']) && !empty($a['kunde_id'])): ?>
  <dialog id="dlgName" class="bx-dialog" style="max-width:520px">
    <?= $dlgKopf('Name beim Kunden', 'dlgName') ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="aktion" value="kundenname_setzen">
      <input type="text" name="kn_name" value="<?= h($knEff) ?>" placeholder="<?= h($produktName ?: 'wie intern') ?>" style="width:100%">
      <div class="muted" style="font-size:12px;margin-top:6px">So heißt das Produkt bei diesem Kunden (Portal, Angebot, AB, Rechnung, Lieferschein, Etikett, PIB). Leer = interner Name.</div>
      <div style="margin-top:14px"><button class="btn btn-primary btn-sm" type="submit">Speichern</button></div>
    </form>
  </dialog>
  <?php endif; ?>

  <?php if (!in_array((string)$a['status'], ['versendet', 'storniert'], true)): ?>
  <dialog id="dlgLiefer" class="bx-dialog" style="max-width:520px">
    <?= $dlgKopf('Voraussichtliche Lieferung', 'dlgLiefer') ?>
    <form method="post" style="margin:0">
      <input type="hidden" name="aktion" value="liefertermin_setzen">
      <div class="bx-row" style="gap:8px;align-items:center;flex-wrap:wrap">
        <input type="date" name="liefertermin_von" value="<?= h((string)($a['liefertermin_von'] ?? '')) ?>"> <span class="muted">bis</span>
        <input type="date" name="liefertermin_bis" value="<?= h((string)($a['liefertermin_bis'] ?? '')) ?>">
      </div>
      <div class="muted" style="font-size:12px;margin-top:6px">Zeitraum, den der Kunde im Portal sieht. Leer = es gilt der geplante Produktionstermin.</div>
      <div style="margin-top:14px"><button class="btn btn-primary btn-sm" type="submit">Speichern</button></div>
    </form>
  </dialog>
  <?php endif; ?>
  <script>document.querySelectorAll('.bx-dialog').forEach(function(d){ d.addEventListener('click', function(e){ if (e.target === d) d.close(); }); });</script>
  <?php endif; ?>
  <?php if (!portal_liefertermin_aktiv() && !in_array((string)$a['status'], ['versendet', 'storniert'], true)): ?><div class="muted" style="font-size:12px;margin:-8px 0 12px">Lieferzeitraum ist im Kundenportal zentral ausgeblendet (Einstellungen) – nur intern.</div><?php endif; ?>
  <h2>Details</h2>
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= kunde_link($a['kunde_id'] ?? null, $a['kunde_firma'], true) ?></div></div>
    <div><div class="k muted">Produkt <span class="muted" style="font-size:11px">(intern)</span></div><div><?php if (!empty($a['produkt_id']) && $produktName): ?><a href="?p=produkt&id=<?= (int)$a['produkt_id'] ?>"><?= h($produktName) ?></a><?php elseif ($produktName): ?><?= h($produktName) ?> <span class="muted" style="font-size:12px">(aus v3)</span><?php else: ?>–<?php endif; ?><?php if ($artKey !== 'none'): ?> <span title="<?= h($artHint) ?>"><?= bx_badge($artLabel, $artStil) ?></span><?php endif; ?></div></div>
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
    <span class="muted" style="font-size:12px">Menge und Preis sind fixiert. Verpackung, Füllmenge, Kapselgröße, Etikett, Name und Lieferung über den Stift an der Kachel oben.</span>
  </div>
  <?php endif; ?>
</div>



<?php // Fortschritts-Kette: auftrag_fortschritt() direkt an der Leiste unten ?>
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
  elseif ($paVorbereitung)                     { $nsText = 'Beschaffungsweg noch offen – im Reiter Beschaffungsweg festlegen.'; $nsTon = 'warn'; }
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
    <div style="font-size:14px"><strong>Nächster Schritt:</strong> <?= h(rtrim($nsText, ':')) ?><?= $fehlDetail ? ' (' . count($fehlDetail) . ' ' . (count($fehlDetail) === 1 ? 'Position' : 'Positionen') . ')' : '' ?><?php if ($nsLager): ?> <a href="#" data-goto="lager" style="font-size:13px">zum Reiter Lager</a><?php endif; ?><?php if ($fehlDetail): ?> · <a href="#" data-goto="material" style="font-size:13px">zum Reiter Material</a><?php endif; ?></div>
    <?php // Teil 4: die Liste steht im Reiter Material – hier nur eine Zeile. ?>
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
  <?php // Fortschritt NEU (08.11.2026, intern): Status solange offen, Abschluss-Text wenn erledigt; Etiketten-Stand,
        // Teilmengen, Labor statt QC, Energetisierung separat. Kundenportal zeigt vorerst noch die bisherige Kette.
  $fsA = auftrag_fortschritt($a); ?>
  <?php if ($fsA['energ']): $eA = $fsA['energ']; ?>
  <div style="display:flex;justify-content:flex-end;margin:0 0 4px">
    <span class="bx-hstep-energ <?= h($eA['state']) ?>" title="Energetisierung – läuft unabhängig vom Fortschritt"><span class="dot"><?= auftrag_track_icon($eA['state']) ?></span><?= h($eA['status']) ?> <span class="muted"><?= h($eA['sub']) ?></span></span>
  </div>
  <?php endif; ?>
  <div style="overflow-x:auto">
    <ul class="bx-htrack">
      <?php foreach ($fsA['steps'] as [$fsStatus, $fsFertig, $fsState, $fsDatum, $fsSub]): ?>
        <li class="bx-hstep <?= h($fsState) ?>">
          <span class="dot"><?= auftrag_track_icon($fsState) ?></span>
          <span class="lbl"><?= h($fsState === 'done' ? $fsFertig : $fsStatus) ?></span>
          <span class="date"><?= $fsDatum ? h(fmt_zeit((string)$fsDatum, 'd.m.Y')) : h((string)($fsSub ?? '')) ?></span>
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
    <?php // Schritt-Karten aus der NEUEN Kette (gleiche Schritte wie die Leiste oben, inkl. Etiketten-Stand und Teilmengen).
          // „Schritt abschließen" setzt die passende bisherige Stufe (phase_setzen) – Logik dahinter unverändert.
          $fsSteps = $fsA['steps']; $fsN = count($fsSteps); $fsErster = null;
          foreach ($fsSteps as $fi => $fx) if ($fx[2] !== 'done') { $fsErster = $fi; break; } ?>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php foreach ($fsSteps as $fi => $fx):
          [$fStatus, $fFertig, $fState, $fDatum, $fSub] = $fx; $fStufe = $fx[5] ?? null;
          $fDone = $fState === 'done';
          $fBlock = !$fDone && $fFertig === 'Produziert' && $berWartet && $fi === $fsErster;   // Material-Gate
          $farbe = $fDone ? 'var(--gruen,#1D9E75)' : (($fState === 'err' || $fBlock) ? '#b23b2f' : (in_array($fState, ['current', 'teil'], true) ? '#C9A227' : 'var(--line)'));
          $stTxt = $fDone ? 'fertig' : ($fState === 'err' ? 'fehlt' : ($fBlock ? 'blockiert' : ($fState === 'teil' ? 'teilweise' : ($fState === 'current' ? 'läuft' : 'wartet'))));
          $ikon  = $fDone ? '&#10003;' : ($fState === 'err' ? '!' : ($fi === $fsErster ? '&#9679;' : '&#9675;'));
          $actor = ($istFremd && in_array($fFertig, ['Produziert', 'Etikettiert', 'Verpackt', 'Qualitätsprüfung abgeschlossen'], true)) ? 'Lieferant' : 'wir';
      ?>
      <div class="bx-panel" style="padding:10px 14px;margin:0;border-left:4px solid <?= $farbe ?>;<?= $fi === $fsErster ? 'background:var(--panel-2)' : '' ?>">
        <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:10px">
            <span style="color:<?= $farbe ?>;font-size:16px;line-height:1;font-weight:700"><?= $ikon ?></span>
            <div>
              <div style="font-weight:600;font-size:14px">Schritt <?= $fi + 1 ?>/<?= $fsN ?>: <?= h($fDone ? $fFertig : $fStatus) ?></div>
              <div class="muted" style="font-size:12px"><?= h($stTxt) ?> · <?= $actor ?><?= $fDatum ? ' · ' . h(fmt_zeit((string)$fDatum, 'd.m.Y')) : '' ?><?= $fSub ? ' · ' . h((string)$fSub) : '' ?></div>
            </div>
          </div>
          <?php if ($fi === $fsErster && $fStufe !== null && $fStufe > $curIdxA): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="aktion" value="phase_setzen">
            <input type="hidden" name="phase" value="<?= (int)$fStufe ?>">
            <button class="btn btn-primary btn-sm" type="submit"><?= $fStufe >= $nPh - 1 ? 'Auftrag abschließen' : 'Schritt abschließen' ?> &rarr;</button>
          </form>
          <?php endif; ?>
        </div>
        <?php if ($fBlock): ?>
        <div style="margin-top:8px;font-size:12px"><span style="color:#b23b2f">Wartet auf Material.</span> <a href="#" data-goto="material">Was fehlt? &rarr; Reiter Material</a></div>
        <?php elseif ($fState === 'err'): ?>
        <div style="margin-top:8px;font-size:12px"><span style="color:#b23b2f">Der Kunde hat noch keine Druckdatei hochgeladen.</span> <a href="#" data-goto="material">Selbst hochladen &rarr; Reiter Material</a></div>
        <?php elseif (!$fDone && $fStatus === 'Etiketten nicht bestellt'): ?>
        <div style="margin-top:8px;font-size:12px"><a href="?p=einkaufsliste">Etiketten bestellen &rarr; Einkauf</a></div>
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

<?php // Soll-Kette Teil 1: Reiter „Beschaffungsweg“ – drei Wege nebeneinander, Kosten für DIESEN Auftrag.
if ($pa): $bw = auftrag_beschaffungswege((int)$a['id']); $bwWahl = $bw['gewaehlt']; $bwAdmin = has_role('admin');
    $bwN = fn($x, $d = 0) => number_format((float)$x, $d, ',', '.');
    $bwM = fn($x, $e) => in_array($e, ['Stk'], true) ? $bwN(round((float)$x)) . ' Stk' : rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',') . ' ' . $e;
    $bwE = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
    // Rohstoff anklicken -> Rohstoffseite, Reiter Einkauf (dort „Preis anfragen“, Lieferantenpreise).
    // Leerkapsel ohne eindeutigen Artikel: Link dorthin, wo sie festgelegt wird (Produkt/Rezeptur/Leerkapsel-Liste) + Grund.
    $bwLink = fn(array $r) => !empty($r['item_id']) ? '<a href="?p=rohstoff&id=' . (int)$r['item_id'] . '&tab=ek" title="Rohstoff öffnen (Preise, Preis anfragen)">' . h((string)$r['name']) . '</a>'
        : (!empty($r['link']) ? '<a href="' . h((string)$r['link']) . '">' . h((string)$r['name']) . '</a>' . (!empty($r['hinweis']) ? ' <span class="muted" style="font-size:12px">(' . h((string)$r['hinweis']) . ')</span>' : '') : h((string)$r['name']));
    $bwPreis = fn($x) => (float)$x < 1 ? number_format((float)$x, 4, ',', '.') . ' €' : number_format((float)$x, 2, ',', '.') . ' €';
    // günstigster Weg mit vollständigem Preis (Rohstoffe nur ohne Lücke)
    $bwKost = [];
    foreach ($bw['wege'] as $k => $w) {
        if ($k === 'rohstoffe') { if (!$w['luecke'] && $w['gesamt'] > 0) $bwKost[$k] = $w['gesamt']; }
        elseif (!empty($w['angebote'])) $bwKost[$k] = $w['angebote'][0]['gesamt'] + (($w['zusatz']['gesamt'] ?? 0) ?: 0);
    }
    $bwBest = $bwKost ? array_search(min($bwKost), $bwKost, true) : ''; ?>
<div class="bx-panel" data-panel="beschaffung">
  <h2 style="margin:0 0 4px;font-size:16px">Beschaffungsweg</h2>
  <div class="muted" style="font-size:12px;margin-bottom:12px">Wie kommt das Material für diesen Auftrag ins Haus? Kosten aus den vorhandenen Lieferantenpreisen für <?= h($bwN((int)$pa['menge'])) ?> Pkg; wo kein Preis vorliegt, steht nur die benötigte Menge.</div>
  <?php if (isset($_GET['bwok'])): ?><div class="badge-ok" style="padding:8px 12px;border-radius:8px;margin-bottom:10px"><?= h((string)$_GET['bwok']) ?></div><?php endif; ?>
  <?php if (isset($_GET['bwfehler'])): ?><div style="color:#8f231b;margin-bottom:10px"><?= h((string)$_GET['bwfehler']) ?></div><?php endif; ?>
  <?php if (!$bw['rezeptur_id']): ?><div class="muted">Zum Produkt gibt es keine Rezeptur – kein Beschaffungsweg nötig.</div>
  <?php else: $dk = $bw['deckung'] ?? []; if (!empty($dk['ok'])): $dkE = (string)$dk['einheit'];
      $dkM = fn($x) => $dkE === 'Stk' ? $bwM($x, 'Stk') : $bwM((float)$x / 1000, $dkE === 'g' ? 'kg' : 'L');   // Bedarfs-Einheiten lesbar (g -> kg)
      $dkFrei = $bwAdmin && (int)$dk['schritte_erledigt'] === 0;
      $gedeckt = $dk['rest'] <= 0.0001; ?>
  <?php // Teil 3 (08.11.2026): Zuordnen steht nur noch in der Material-Übersicht – hier nur der Stand in einer Zeile. ?>
  <div class="muted" style="font-size:13px;margin:0 0 14px">Bereits zugeordnet: <strong><?= h($dkM($dk['summe_gekoppelt'])) ?></strong>
    · im Lager frei <?= h($dkM($dk['summe_lager'])) ?> · unterwegs <?= h($dkM($dk['summe_bestellt'])) ?> · <strong>Rest <?= h($dkM($dk['rest'])) ?></strong>
    · <a href="#" data-goto="material">zuordnen im Reiter Material</a></div>
  <?php if ($gedeckt): ?>
  <div class="badge-ok" style="padding:10px 14px;border-radius:8px;margin-bottom:14px">Vollständig über Lager und Bestellung gedeckt – kein weiterer Bedarf, keine neue Bestellung nötig.</div>
  <?php endif; ?>
  <?php if ($dk['summe_gekoppelt'] > 0 && $dk['pa_status'] === 'vorbereitung' && $dkFrei): $dkWeg = in_array('premix', array_column($dk['gekoppelt'], 'art'), true) && !in_array('bulk', array_column($dk['gekoppelt'], 'art'), true) ? 'premix' : 'kapsel'; ?>
  <form method="post" class="bx-panel" style="border-left:3px solid var(--warn);padding:10px 14px;margin:0 0 14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="deckung_festlegen"><input type="hidden" name="weg" value="<?= $dkWeg ?>">
    <span>Ware ist zugeordnet, der Beschaffungsweg steht aber noch offen.</span>
    <button class="btn btn-primary btn-sm" type="submit">Weg festlegen: <?= h(beschaffungswege()[$bwWahl !== '' ? $bwWahl : $dkWeg]) ?></button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px">
  <?php foreach ($bw['wege'] as $k => $w): $ist = $bwWahl === $k; ?>
    <div style="border:1px solid <?= $ist ? 'var(--gruen,#1D9E75)' : 'var(--line)' ?>;border-radius:10px;padding:14px 16px;<?= $ist ? 'box-shadow:0 0 0 1px var(--gruen,#1D9E75) inset;' : '' ?>">
      <div class="bx-row" style="justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px">
        <div style="font-weight:600"><?= h($w['label']) ?></div>
        <div><?= $ist ? bx_badge('festgelegt', 'ok') : '' ?> <?= $bwBest === $k ? bx_badge('günstigster', 'info') : '' ?></div>
      </div>
      <div class="muted" style="font-size:12px;margin-bottom:8px"><?= !empty($dk['ok']) && $dk['summe_gekoppelt'] > 0 ? 'Noch zu beschaffen (Rest)' : 'Bedarf für diesen Auftrag' ?>: <span style="color:var(--text)"><?= h($bwM($w['menge'], $w['einheit'])) ?></span></div>
      <?php if ($k === 'rohstoffe'): ?>
        <table class="bx-table" style="font-size:13px"><tbody>
        <?php foreach ($w['positionen'] as $ps): ?>
          <tr><td style="white-space:normal"><?= $bwLink($ps) ?></td><td class="bx-num"><?= h($bwM($ps['menge'], $ps['einheit'])) ?></td>
              <td class="bx-num"><?= $ps['gesamt'] !== null ? h($bwE($ps['gesamt'])) : '<span class="muted">kein Preis</span>' ?><?php if (!empty($ps['mindest'])): ?><div class="muted" style="font-size:11px">Mindestmenge <?= h($bwM($ps['mindest'], $ps['bezug'] ?? $ps['einheit'])) ?></div><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <div style="margin-top:8px"><?= $w['gesamt'] > 0 ? 'Summe ' . h($bwE($w['gesamt'])) : '' ?><?= $w['luecke'] ? ' <span class="muted" style="font-size:12px">(nicht vollständig: Preise fehlen)</span>' : '' ?></div>
        <div class="muted" style="font-size:12px;margin-top:4px">Beschaffung über die Bedarfsliste (meist mehrere Lieferanten).</div>
      <?php else: ?>
        <?php if (!$w['angebote']): ?>
          <div class="muted">Kein Lieferantenpreis. <a href="?p=rezeptur_detail&id=<?= (int)$bw['rezeptur_id'] ?>">Angebot anfragen</a></div>
        <?php else: ?>
          <table class="bx-table" style="font-size:13px"><tbody>
          <?php foreach ($w['angebote'] as $ag): ?>
            <tr><td style="white-space:normal"><?= h($ag['lieferant']) ?></td><td class="bx-num"><?= h($bwPreis($ag['preis'])) ?>/<?= h($w['einheit']) ?></td><td class="bx-num"><?= h($bwE($ag['gesamt'])) ?></td>
              <td style="text-align:right"><?php if ($ist && $bwAdmin && $w['menge'] > 0.0001): ?>
                <form method="post" style="margin:0" onsubmit="return confirm('Bestellung bei <?= h(addslashes($ag['lieferant'])) ?> als Entwurf anlegen (<?= h($bwM($w['menge'], $w['einheit'])) ?>)?')">
                  <input type="hidden" name="aktion" value="beschaffung_bestellen"><input type="hidden" name="weg" value="<?= h($k) ?>"><input type="hidden" name="lieferant_id" value="<?= (int)$ag['lieferant_id'] ?>">
                  <button class="btn btn-primary btn-sm" type="submit" title="Als Entwurf anlegen – auf der Bestellseite über das Portal senden">Bestellen</button>
                  <button class="btn btn-ghost btn-sm" type="submit" name="modus" value="direkt" title="Schon per Telefon/Mail bestellt: sofort bestellt + bestätigt, keine Mail">Direkt bestellt</button></form>
              <?php endif; ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
        <?php if (!empty($w['zusatz'])): $zs = $w['zusatz']; ?>
          <div class="muted" style="font-size:12px;margin-top:6px">dazu <?= $bwLink($zs) ?> <?= h($bwM($zs['menge'], $zs['einheit'])) ?>: <?= $zs['gesamt'] !== null ? h($bwE($zs['gesamt'])) : 'kein Preis' ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="bx-row" style="gap:8px;margin-top:12px;flex-wrap:wrap">
        <?php if ($bwAdmin && !$ist && $bw['schritte_erledigt'] === 0): ?>
          <form method="post" style="margin:0"><input type="hidden" name="aktion" value="beschaffungsweg_setzen"><input type="hidden" name="weg" value="<?= h($k) ?>">
            <button class="btn <?= $bwWahl === '' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit">Diesen Weg festlegen</button></form>
        <?php endif; ?>
        <?php if ($ist && $bwAdmin && !(!empty($dk['ok']) && $dk['rest'] <= 0.0001)): ?>
          <?php if (empty($bw['pa']['bedarf_gemeldet'])): ?>
            <form method="post" style="margin:0"><input type="hidden" name="aktion" value="beschaffung_bedarf">
              <button class="btn btn-ghost btn-sm" type="submit">An Bedarfsliste senden</button></form>
          <?php else: ?><span class="muted" style="font-size:12px">an die Bedarfsliste gemeldet am <?= h(fmt_zeit((string)$bw['pa']['bedarf_gemeldet'], 'd.m.Y')) ?> · <a href="?p=einkaufsliste">zur Bedarfsliste</a></span><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if ($bw['schritte_erledigt'] > 0): ?><div class="muted" style="font-size:12px;margin-top:10px">Die Produktion hat begonnen – der Weg lässt sich nicht mehr ändern.</div><?php endif; ?>
  <?php if (($bw['pa']['status'] ?? '') === 'vorbereitung' && $bwAdmin): ?><div style="margin-top:10px;font-size:12px"><a href="?p=produktion_vorbereitung&id=<?= (int)$bw['pa']['id'] ?>&voll=1">Auf Quellen aufteilen (Lager / Fremd / Eigen)</a> <span class="muted">– Mischung aus Bestand, Zukauf und Eigenproduktion</span></div><?php endif; ?>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php // Teil 3: Handelsware hat keinen Produktionsauftrag – ihr Weg ist der Zukauf der fertigen Ware (Bestellung über den Einkauf).
if (!$pa && !empty($a['produkt_id']) && produkt_ist_handelsware((int)$a['produkt_id'])): ?>
<div class="bx-panel" data-panel="beschaffung">
  <h2 style="margin:0 0 4px;font-size:16px">Beschaffungsweg</h2>
  <div class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px"><?= bx_badge('Zukauf · Handelsware', 'info') ?> <span class="muted" style="font-size:13px">Fertige Ware wird zugekauft und weiterverkauft – kein Produktionsauftrag. Versandbereit, sobald die Ware frei im Lager liegt.</span></div>
  <?php if ($zukaufPreise): $nzH = fn($x, $n = 4) => rtrim(rtrim(number_format((float)$x, $n, ',', '.'), '0'), ','); ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Lieferant</th><th class="bx-num">ab Menge</th><th class="bx-num">EK je Einheit</th></tr></thead>
    <tbody><?php foreach (array_slice($zukaufPreise, 0, 6) as $z): ?><tr><td><?= h($z['firma'] ?: '–') ?></td><td class="bx-num"><?= (float)$z['menge_ab'] > 0 ? $nzH($z['menge_ab'], 3) : '–' ?></td><td class="bx-num"><?= $nzH($z['preis']) ?> <?= h($z['waehrung'] ?: 'EUR') ?><?= $z['einheit'] ? ' / ' . h($z['einheit']) : '' ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php else: ?><div class="muted" style="font-size:13px">Noch kein Zukaufpreis hinterlegt (Produkt → Lieferanten-Preise).</div><?php endif; ?>
  <div style="margin-top:10px"><a class="btn btn-ghost btn-sm" href="?p=einkaufsliste">Bestellen über den Einkauf</a></div>
</div>
<?php endif; ?>
<?php // Soll-Kette 3: Bereitschaft je Material (grün = da, rot = fehlt) + Kapselgröße/Form/Füllmenge – vorher eigene Seite „Vor-Produktion“.
if ($pa): $vc = pa_vorbereitung_checks((int)$pa['id']);
    $vRez  = $rezEffId ? one("SELECT id, darreichungsform, kapselgroesse_id, einheit_fuellmenge, tabletten_form FROM rezeptur WHERE id=?", [$rezEffId]) : null;
    $vDarr = (string)($vRez['darreichungsform'] ?? '');
    $vAttr = $vRez ? form_attribut($vDarr, (int)($vRez['kapselgroesse_id'] ?? 0) > 0) : '';
    $vZur  = '?p=auftrag&id=' . (int)$a['id'] . '&tab=material';
    $vPunkt = fn(bool $ok) => '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . ($ok ? '#1D9E75' : '#d64545') . ';margin-right:8px;vertical-align:0"></span>'; ?>
<div class="bx-panel" data-panel="material">
  <div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:8px">
    <h2 style="margin:0;font-size:16px">Bereitschaft</h2>
    <?= $vc['bereit'] ? bx_badge('alles da', 'ok') : bx_badge('noch offen', 'warn') ?>
  </div>
  <?php if (isset($_GET['saved'])): ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin-bottom:8px;display:inline-block">Gespeichert.</div><?php endif; ?>
  <div style="display:flex;flex-direction:column;gap:6px">
    <?php foreach ($vc['checks'] as $ck): ?>
      <div class="bx-row" style="justify-content:space-between;gap:10px;font-size:13px">
        <span><?= $vPunkt((bool)$ck['ok']) ?><?= h($ck['label']) ?></span>
        <span class="muted"><?= h($ck['wert']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (has_role('admin') || has_role('sales')): ?><div class="muted" style="font-size:12px;margin-top:10px">Verpackung, Kapselgröße/Gewicht und Etikett ändern: Stift an der Kachel im Reiter Details.</div><?php endif; ?>
</div>
<?php endif; ?>
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
  <div class="muted" style="font-size:12px;margin-bottom:10px">Was dieser Auftrag braucht, was frei im Lager liegt, was bestellt ist und bei wem.</div>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Position</th><th>Rolle</th><th class="bx-num"><?= $ueProd > 0 ? 'noch benötigt' : 'benötigt' ?></th><th>zugeordnet</th><th class="bx-num">frei</th><th class="bx-num">in Quarantäne</th><th class="bx-num">unterwegs</th><th class="bx-num">Rest</th><th>Deckung</th><th>Bestellt bei</th><th></th></tr></thead>
    <tbody>
    <?php $mzZu = auftrag_material_zugeordnet((int)$a['id']); $mzDlg = []; $mzDarf = has_role('admin') || has_role('production');
          $mzStart = $pa && (int) scalar("SELECT COUNT(*) FROM produktion_schritt WHERE pa_id=? AND erledigt=1", [(int)$pa['id']]) === 0;
    foreach ($bedarfRows as $mzI => $r):
        $iid = (int)($r['item_id'] ?? 0);
        $ben = (float)($r['benoetigt'] ?? 0);   // schon auf den offenen Rest skaliert (auftrag_bedarf_rest)
        $frei = (float)($r['verfuegbar'] ?? 0);
        $unterwegs = $iid > 0 ? item_unterwegs($iid) : 0.0;
        $einh = (string)($r['einheit'] ?? 'Stk');
        $deckt = $frei + 1e-6 >= $ben;
        $quar = (float)($r['quarantaene'] ?? 0);
        $decktMitQuar = !$deckt && $quar > 1e-6 && ($frei + $quar + 1e-6 >= $ben);
        $decktMitUnterwegs = !$deckt && !$decktMitQuar && ($frei + $quar + $unterwegs + 1e-6 >= $ben);
        $bestellungen = item_bestellungen($iid, (int)$a['id'], (string)($r['rolle'] ?? '') === 'Fertigware');
        // Bulk-Zukauf: Bestellung eingegangen, aber keine Bulk-Charge am Auftrag gebucht (z. B. früher über „liefern“
        // geschlossen oder nur „als angekommen markiert“) -> klar sagen statt nur „Fehlbestand“ (Nico 04.11.2026, AB 542).
        $nichtEingebucht = !$deckt && $iid === 0 && (string)($r['rolle'] ?? '') === 'Fertigware' && $quar <= 1e-9
            && array_filter($bestellungen, fn($b) => $b['eingang']) && auftrag_fertigware_cached((int)$a['id'])['n'] === 0; ?>
      <tr>
        <td style="white-space:normal;min-width:200px;max-width:320px"><?= h((string)($r['name'] ?? '–')) ?></td>
        <td class="muted"><?= h((string)($r['rolle'] ?? '–')) ?></td>
        <td class="bx-num"><?= $mng($ben, $einh) ?></td>
        <td style="font-size:12px;white-space:normal;min-width:130px"><?php
          $mzListe = $iid > 0 ? ($mzZu[$iid] ?? []) : [];
          if ($iid === 0 && (string)($r['rolle'] ?? '') === 'Fertigware') {   // Bulk: Bindungen aus Bulk-Reservierung + fest zugewiesenen Chargen
              foreach ($bulkRes as $rv) echo '<div>' . ($rv['bestellung_nr'] ? 'Bestellung ' . h((string)$rv['bestellung_nr']) : 'Charge ' . h((string)$rv['charge_nr'])) . ' · ' . h(rtrim(rtrim(number_format((float)$rv['menge'], 3, ',', '.'), '0'), ',')) . '</div>';
              foreach ($lwZu as $lz) echo '<div>Charge ' . h((string)($lz['charge_nr'] ?: $lz['id'])) . ' (fest)</div>';
              if (!$bulkRes && !$lwZu) echo '<span class="muted">–</span>';
          } elseif ($mzListe) {
              foreach ($mzListe as $zr) echo '<div>' . ($zr['charge_nr'] ? 'Charge ' . h((string)$zr['charge_nr']) : ($zr['bestellung_nr'] ? 'Bestellung ' . h((string)$zr['bestellung_nr']) : 'Lager')) . ' · ' . $mng((float)$zr['menge'], $einh) . '</div>';
          } else echo '<span class="muted">–</span>'; ?></td>
        <td class="bx-num"><?= $mng($frei, $einh) ?><?php if (isset($r['zugeordnet']) && $frei - (float)$r['zugeordnet'] > 1e-6): ?><br><span class="muted" style="font-size:11px"><?= (float)$r['zugeordnet'] > 1e-6 ? 'davon zugeordnet ' . $mng((float)$r['zugeordnet'], $einh) : 'noch nicht zugeordnet' ?></span><?php endif; ?></td>
        <td class="bx-num"><?= $quar > 1e-9 ? '<a href="' . h(quarantaene_freigabe_url($iid)) . '">' . $mng($quar, $einh) . '</a>' : '<span class="muted">–</span>' ?></td>
        <td class="bx-num"><?= $unterwegs > 1e-9 ? $mng($unterwegs, $einh) : '<span class="muted">–</span>' ?></td>
        <?php $mzRest = max(0.0, $ben - $frei - $quar - $unterwegs); ?>
        <td class="bx-num"><?= $mzRest > 1e-6 ? '<span style="color:var(--err)">' . $mng($mzRest, $einh) . '</span>' : '<span class="muted">–</span>' ?></td>
        <td><?= $deckt ? bx_badge('da','ok') : ($decktMitQuar ? '<a href="' . h(quarantaene_freigabe_url($iid)) . '" style="text-decoration:none">' . bx_badge('in Quarantäne – freigeben','warn') . '</a>'
                 : ($nichtEingebucht ? '<span title="Die Bestellung ist als eingegangen markiert, im Lager ist aber keine Bulk-Charge für diesen Auftrag gebucht. Bitte in der Lager-App unter Wareneingang einbuchen oder unten bei „Bulkware binden“ eine vorhandene Charge zuweisen.">' . bx_badge('nicht eingebucht','err') . '</span>'
                 : ($decktMitUnterwegs ? bx_badge('mit Lieferung','info') : bx_badge('fehlt','err')))) ?></td>
        <td style="white-space:normal;min-width:240px;font-size:12px">
          <?php if (!$bestellungen): ?><span class="muted">nicht bestellt</span>
          <?php else: foreach (array_slice($bestellungen, 0, 4) as $bs): ?>
            <div style="margin:0 0 4px"><a href="?p=bestellung&id=<?= $bs['id'] ?>"><?= h($bs['nummer']) ?></a> · <?= h($bs['bei']) ?>
              <span class="muted">· <?= $mng($bs['menge'], $bs['einheit'] !== '' ? $bs['einheit'] : $einh) ?></span>
              <?= bx_badge($bs['stufe']['label'], $bs['stufe']['kind']) ?>
              <?php if ($bs['datum'] !== ''): ?><span class="muted">· <?= $bs['eingang'] ? 'am' : 'Termin' ?> <?= h(date('d.m.Y', strtotime($bs['datum']))) ?></span><?php endif; ?>
              <?php if ($bs['fuer_auftrag']): ?><span class="muted">· für diesen Auftrag</span><?php endif; ?>
            </div>
          <?php endforeach; if (count($bestellungen) > 4): ?><div class="muted">+<?= count($bestellungen) - 4 ?> weitere</div><?php endif; endif; ?>
        </td>
        <td style="text-align:right;white-space:nowrap"><?php if ($mzDarf && $mzStart && ($iid > 0 || ((string)($r['rolle'] ?? '') === 'Fertigware' && has_role('admin')))): $mzDlg[] = [$mzI, $r]; ?><button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('mzDlg<?= $mzI ?>').showModal()">Zuordnen</button><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (isset($_GET['mzok'])): ?><div class="badge-ok" style="padding:8px 12px;border-radius:8px;margin-top:10px"><?= h((string)$_GET['mzok']) ?></div><?php endif; ?>
  <?php if (isset($_GET['bulkres']) || isset($_GET['lwzu'])): ?><div class="badge-ok" style="padding:8px 12px;border-radius:8px;margin-top:10px">Bindung aktualisiert.</div><?php endif; ?>
  <?php if (isset($_GET['bulkwarn'])): ?><div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:10px 14px;margin-top:10px"><?= h((string)$_GET['bulkwarn']) ?></div><?php endif; ?>
  <?php foreach ($mzDlg as [$mzI, $r]): $iid = (int)($r['item_id'] ?? 0); $einh = (string)($r['einheit'] ?? 'Stk');
        $mzBedarf = max(0.0, (float)($r['benoetigt'] ?? 0) - (float)($r['verfuegbar'] ?? 0)); ?>
  <dialog id="mzDlg<?= $mzI ?>" class="bx-dialog">
    <div class="bx-row" style="justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px"><h2 style="margin:0;font-size:17px">Zuordnen: <?= h((string)($r['name'] ?? '')) ?></h2><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('dialog').close()" aria-label="schließen">&#10005;</button></div>
    <div class="muted" style="font-size:12px;margin-bottom:12px">Benötigt <?= $mng((float)$r['benoetigt'], $einh) ?> · frei <?= $mng((float)($r['verfuegbar'] ?? 0), $einh) ?>. Zugeordnete Ware/Bestellung gilt fest für diesen Auftrag (andere Aufträge rechnen sie nicht mehr ein).</div>
    <?php if ($iid > 0): $mzQ = auftrag_material_quellen($iid); ?>
      <?php foreach (($mzZu[$iid] ?? []) as $zr): ?>
      <form method="post" class="bx-row" style="justify-content:space-between;gap:8px;align-items:center;margin:0 0 6px">
        <input type="hidden" name="aktion" value="material_loesen"><input type="hidden" name="res_id" value="<?= (int)$zr['id'] ?>">
        <span><?= $zr['charge_nr'] ? 'Charge ' . h((string)$zr['charge_nr']) : 'Bestellung ' . h((string)$zr['bestellung_nr']) ?> · <?= $mng((float)$zr['menge'], $einh) ?></span>
        <button class="btn btn-ghost btn-sm" type="submit">Lösen</button>
      </form>
      <?php endforeach; ?>
      <?php foreach (['lager' => 'Aus Lager zuordnen', 'bestellt' => 'Bestellung verknüpfen'] as $mzArt => $mzTitel): ?>
      <form method="post" style="margin:12px 0 0;padding-top:10px;border-top:1px solid var(--line)">
        <input type="hidden" name="aktion" value="material_zuordnen"><input type="hidden" name="item_id" value="<?= $iid ?>">
        <div style="font-weight:600;margin-bottom:6px"><?= $mzTitel ?></div>
        <?php if (!$mzQ[$mzArt]): ?><div class="muted" style="font-size:12px"><?= $mzArt === 'lager' ? 'Keine freie Charge im Lager.' : 'Keine offene Bestellung zu diesem Artikel.' ?></div>
        <?php else: ?>
        <div class="bx-row" style="gap:8px;align-items:flex-end;flex-wrap:wrap">
          <select name="quelle" required style="min-width:280px;flex:1">
            <?php foreach ($mzQ[$mzArt] as $q): ?>
            <option value="<?= $mzArt === 'lager' ? 'charge' : 'bestellung' ?>:<?= (int)$q['id'] ?>"><?= $mzArt === 'lager'
                ? 'Charge ' . h((string)($q['charge_nr'] ?: $q['id'])) . ($q['mhd'] ? ' · MHD ' . h(date('d.m.Y', strtotime((string)$q['mhd']))) : '')
                : h((string)$q['nummer']) . ($q['lieferant'] !== '' ? ' · ' . h((string)$q['lieferant']) : '') . ' · ' . h(status_text((string)$q['status'])) . (!empty($q['eta_geplant']) ? ' · Termin ' . h(date('d.m.Y', strtotime((string)$q['eta_geplant']))) : '') ?> · frei <?= $mng((float)$q['frei'], $einh) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" inputmode="decimal" name="menge" value="<?= h(rtrim(rtrim(number_format(min($mzBedarf > 0 ? $mzBedarf : (float)$r['benoetigt'], (float)$mzQ[$mzArt][0]['frei']), 3, ',', ''), '0'), ',')) ?>" style="width:120px" title="Menge in <?= h($einh) ?>">
          <button class="btn btn-primary btn-sm" type="submit">Zuordnen</button>
        </div>
        <?php endif; ?>
      </form>
      <?php endforeach; ?>
    <?php else: /* Bulk / Fertigware: bestehende Bindung (Reservierung aus Bestellung bzw. feste Charge) */ ?>
      <?php foreach ($bulkRes as $rv): ?>
      <form method="post" class="bx-row" style="justify-content:space-between;gap:8px;align-items:center;margin:0 0 6px" onsubmit="return confirm('Reservierung aufheben?');">
        <input type="hidden" name="aktion" value="bulk_res_storno"><input type="hidden" name="res_id" value="<?= (int)$rv['id'] ?>">
        <span><?= $rv['bestellung_nr'] ? 'Bestellung ' . h((string)$rv['bestellung_nr']) . ($rv['lieferant'] ? ' · ' . h((string)$rv['lieferant']) : '') : 'Charge ' . h((string)$rv['charge_nr']) ?> · <?= h(rtrim(rtrim(number_format((float)$rv['menge'], 3, ',', '.'), '0'), ',')) ?> <?= h((string)($rv['einheit'] ?: '')) ?></span>
        <button class="btn btn-ghost btn-sm" type="submit">Aufheben</button>
      </form>
      <?php endforeach; ?>
      <?php foreach ($lwZu as $lz): ?>
      <form method="post" class="bx-row" style="justify-content:space-between;gap:8px;align-items:center;margin:0 0 6px" onsubmit="return confirm('Zuweisung dieser Charge aufheben? Die Ware wird wieder freie Lagerware.');">
        <input type="hidden" name="aktion" value="charge_loesen"><input type="hidden" name="charge_id" value="<?= (int)$lz['id'] ?>">
        <span>Charge <?= h((string)($lz['charge_nr'] ?: $lz['id'])) ?> (fest) · <?= h(rtrim(rtrim(number_format((float)$lz['menge_verfuegbar'], 3, ',', '.'), '0'), ',')) ?> <?= h((string)($lz['einheit'] ?? '')) ?></span>
        <button class="btn btn-ghost btn-sm" type="submit">Lösen</button>
      </form>
      <?php endforeach; ?>
      <?php $mzHatQ = ($bulkQ['bestellungen'] ?? []) || ($lwQ['chargen'] ?? []) || ($lwQ['weitere'] ?? []); if ($mzHatQ):
            $mzGeb = 0.0; foreach ($bulkRes as $rv) $mzGeb += (float)$rv['menge']; foreach ($lwZu as $lz) $mzGeb += (float)$lz['menge_verfuegbar'];
            $mbOffen = max(0, (int)$restStk - (int)round($mzGeb)); ?>
      <form method="post" style="margin:12px 0 0;padding-top:10px;border-top:1px solid var(--line)">
        <input type="hidden" name="aktion" value="material_binden">
        <div style="font-weight:600;margin-bottom:6px">Aus Lager zuordnen oder Bestellung verknüpfen</div>
        <div class="bx-row" style="gap:8px;align-items:flex-end;flex-wrap:wrap">
          <select name="quelle" required style="min-width:280px;flex:1">
            <option value="">– wählen –</option>
            <?php if ($bulkQ['bestellungen']): ?><optgroup label="Bestellung (auch unterwegs) – wird reserviert">
              <?php foreach ($bulkQ['bestellungen'] as $bq): if ($bq['frei'] <= 0.0001) continue; ?>
                <option value="bestellung:<?= (int)$bq['bp_id'] ?>">Bestellung <?= h((string)$bq['nummer']) ?><?= $bq['lieferant'] ? ' · ' . h((string)$bq['lieferant']) : '' ?> · <?= !empty($bq['angekommen_am']) ? 'geliefert' : 'unterwegs' ?> · frei <?= h(rtrim(rtrim(number_format((float)$bq['frei'], 3, ',', '.'), '0'), ',')) ?></option>
              <?php endforeach; ?>
            </optgroup><?php endif; ?>
            <?php foreach (['chargen' => 'Passende Charge im Lager – wird fest zugewiesen', 'weitere' => 'Andere Fertigware im Lager – wird fest zugewiesen'] as $lwK => $lwL): if (empty($lwQ[$lwK])) continue; ?><optgroup label="<?= h($lwL) ?>">
              <?php foreach ($lwQ[$lwK] as $cq): ?>
              <option value="charge:<?= (int)$cq['id'] ?>"><?= $cq['passt'] === false ? 'ANDERE GRÖSSE · ' : '' ?><?= $lwK === 'weitere' ? h((string)$cq['item_name']) . ' · ' : '' ?>Charge <?= h((string)($cq['charge_nr'] ?: $cq['id'])) ?><?= $cq['kapselgroesse'] !== '' ? ' · ' . h($cq['kapselgroesse']) : '' ?><?= $cq['mhd'] ? ' · MHD ' . h(date('d.m.Y', strtotime((string)$cq['mhd']))) : '' ?> · frei <?= h(rtrim(rtrim(number_format((float)$cq['menge_verfuegbar'], 3, ',', '.'), '0'), ',')) ?></option>
              <?php endforeach; ?>
            </optgroup><?php endforeach; ?>
          </select>
          <input type="text" inputmode="<?= $bulkInM ?>" name="menge" <?= $bulkTaus ?> value="<?= $bulkInput($mbOffen) ?>" style="width:120px" title="Menge (<?= $bulkEinh ?>)">
          <button class="btn btn-primary btn-sm" type="submit">Binden</button>
        </div>
      </form>
      <?php else: ?><div class="muted" style="font-size:12px;margin-top:8px">Keine Bulkware verfügbar: weder eine Bulk-Bestellung des Produkts noch eine freie Fertigware-Charge im Lager.</div><?php endif; ?>
    <?php endif; ?>
  </dialog>
  <?php endforeach; ?>
  <script>document.querySelectorAll('dialog[id^=mzDlg]').forEach(function(d){ d.addEventListener('click', function(e){ if (e.target === d) d.close(); }); });</script>
  <?php if (isset($_GET['diagok'])): ?><div class="badge-ok" style="padding:8px 12px;border-radius:8px;margin-top:10px"><?= h((string)$_GET['diagok']) ?></div><?php endif; ?>
  <?php if (isset($_GET['diagfehler'])): ?><div style="color:#8f231b;margin-top:10px"><?= h((string)$_GET['diagfehler']) ?></div><?php endif; ?>
  <?= auftrag_bulk_diagnose_block((int)$a['id'], '?p=auftrag&id=' . (int)$a['id']) ?>
  <p class="muted" style="font-size:12px;margin:6px 0 0"><?php if ($ueProd > 0): ?>„noch benötigt" = Restbedarf, die bereits <strong><?= number_format($ueProd, 0, ',', '.') ?></strong> produzierten Packungen sind anteilig abgezogen · <?php endif; ?>„zugeordnet" = fest für diesen Auftrag (Charge oder Bestellung, über „Zuordnen") · „frei" = im Lager verfügbar (Bulkware: zugeordnet + freier Lagerbestand) · „Rest" = weder frei, in Quarantäne noch unterwegs · „unterwegs" = bestellt, noch nicht eingegangen · „mit Lieferung" = frei + unterwegs reicht aus · „Bestellt bei" = laufende Bestellungen (auch Entwürfe) und schon eingegangene für diesen Auftrag.</p>
</div>
<?php endif; endif; ?>
<?php // Soll-Kette 4b: Anweisung „Teilmenge produzieren“ ans Werk (auch „Schritt vorziehen“: bis vor dem Etikettieren).
if ($pa && in_array((string)($pa['status'] ?? ''), ['offen', 'laufend'], true) && has_role('admin')):
    $tmA = pa_teilmenge_anweisungen((int)$pa['id']); $tmR = (int) floor(produktion_rest((int)$pa['id']));
    ?>
<div class="bx-panel" data-panel="produktion">
  <h2 style="margin:0 0 4px;font-size:16px">Teilmenge produzieren</h2>
  <div class="muted" style="font-size:12px;margin-bottom:10px">Anweisung ans Werk: erscheint in der Werk-App als Aufgabe mit Priorität. Noch offen: <?= number_format($tmR, 0, ',', '.') ?> Pkg. Was jetzt machbar ist, zeigt die Werk-App am Auftrag.</div>
  <?php if (isset($_GET['tmok'])): ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin-bottom:8px;display:inline-block">Anweisung ans Werk geschickt.</div><?php endif; ?>
  <?php if (isset($_GET['tmfehler'])): ?><div style="color:#8f231b;margin-bottom:8px"><?= h((string)$_GET['tmfehler']) ?></div><?php endif; ?>
  <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="teilmenge_anweisen">
    <div class="bx-field" style="margin:0;width:140px"><label>Packungen</label><input type="text" inputmode="numeric" name="tm_menge" required placeholder="z. B. 100"></div>
    <div class="bx-field" style="margin:0;min-width:280px"><label>Umfang</label><select name="tm_umfang"><?php foreach (teilmenge_umfaenge() as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="bx-field" style="margin:0;width:140px"><label>Priorität</label><select name="tm_prio"><option value="1">Hoch</option><option value="2" selected>Normal</option><option value="3">Niedrig</option></select></div>
    <button class="btn btn-primary btn-sm" type="submit">An das Werk schicken</button>
  </form>
  <?php if ($tmA): ?>
  <table class="bx-table" style="margin-top:12px"><thead><tr><th>Anweisung</th><th class="bx-num">Noch offen</th><th>Priorität</th><th>Seit</th><th></th></tr></thead><tbody>
    <?php foreach ($tmA as $ta): ?>
      <tr><td><?= h(teilmenge_umfaenge()[(string)$ta['umfang']] ?? (string)$ta['umfang']) ?></td><td class="bx-num"><?= number_format((int)$ta['menge'], 0, ',', '.') ?> Pkg</td>
        <td><?= ['1' => 'Hoch', '2' => 'Normal', '3' => 'Niedrig'][(string)$ta['prio']] ?? '' ?></td><td class="muted"><?= h(fmt_zeit((string)$ta['angelegt'], 'd.m.Y H:i')) ?></td>
        <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Anweisung zurückziehen?')"><input type="hidden" name="aktion" value="teilmenge_zurueck"><input type="hidden" name="aufgabe_id" value="<?= (int)$ta['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Zurückziehen</button></form></td></tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php endif; ?>
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
      <?php // Teil 4: kein manueller Haken mehr – „angekommen" kommt aus dem Wareneingang (Charge/Bestellung). ?>
      <?php if ($rohAngDa): ?>
        <?= bx_badge('angekommen · ' . h(fmt_zeit((string)$rohAngDa, 'd.m.Y')), 'ok') ?>
      <?php else: ?>
        <span class="muted" style="font-size:13px">noch nicht – kommt aus dem Wareneingang</span>
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
    <div style="grid-column:1/-1"><div class="k muted">Beschaffungsweg</div>
      <div>Noch nicht festgelegt – im Reiter Beschaffungsweg.</div>
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

<?php // Labor an EINEM Ort (Teil 4, 08.11.2026): Status des externen Labortests (für freigeschaltete Kunden bzw. dazugebuchte
      // Laboranalyse), „Ans Labor senden", Laborberichte dieser Bestellung (freigeben/löschen) und Hochladen. Der Reiter
      // Dokumente zeigt nur noch die Downloads. Status kommt automatisch aus dem freigegebenen Bericht (dokument typ='analyse').
if (!empty($a['produkt_id']) || kunde_will_labortest((int)($a['kunde_id'] ?? 0))):
    $labFlag = kunde_will_labortest((int)($a['kunde_id'] ?? 0)) || !empty($a['laboranalyse_gebucht']);
    $lt = auftrag_labortest_status((int)$a['id'], isset($a['produkt_id']) ? (int)$a['produkt_id'] : null);
    $labDocs = all("SELECT id, titel, datei_orig, dok_datum, charge_nr, befund, kunde_sichtbar, angelegt FROM dokument
                    WHERE objekt_typ='auftrag' AND objekt_id=? AND typ='analyse' ORDER BY COALESCE(dok_datum, DATE(angelegt)) DESC, id DESC", [$id]);
    $labBefund = fn($b) => $b === 'bestanden' ? bx_badge('bestanden','ok') : ($b === 'auffaellig' ? bx_badge('auffällig','err') : '<span class="muted">–</span>');
    $labCharge = (string) scalar("SELECT c.charge_nr FROM charge c JOIN produktionsauftrag pa ON pa.id=c.pa_id
                                  WHERE pa.auftrag_id=? AND c.charge_nr IS NOT NULL AND c.charge_nr<>'' ORDER BY c.id LIMIT 1", [$id]); ?>
<div class="bx-panel" data-panel="produktion" id="labor">
  <h2 style="margin-top:0"><?= $labFlag ? 'Externer Labortest' : 'Laboranalyse' ?><?= $labCharge ? ' <span class="muted" style="font-weight:normal;font-size:13px">· Charge ' . h($labCharge) . '</span>' : '' ?></h2>
  <?php if ($labFlag): ?>
    <?php if ($lt['status'] === 'abgeschlossen'): ?>
      <div style="margin-bottom:10px"><?= bx_badge('abgeschlossen', 'ok') ?> <span class="muted"><?= $lt['datum'] ? 'Bericht vom ' . h(date('d.m.Y', strtotime((string)$lt['datum']))) : 'Bericht liegt vor' ?></span></div>
    <?php elseif (!empty($lt['versendet_am'])): ?>
      <div style="margin-bottom:10px"><?= bx_badge('Probe beim Labor', 'warn') ?> <span class="muted">versendet am <?= h(date('d.m.Y', strtotime((string)$lt['versendet_am']))) ?> – „abgeschlossen", sobald ein freigegebener Bericht vorliegt.</span></div>
    <?php else: ?>
      <div style="margin-bottom:10px"><?= bx_badge('geplant', 'info') ?> <span class="muted">Probe nach der Produktion ans Labor – „abgeschlossen", sobald ein freigegebener Bericht vorliegt.</span></div>
    <?php endif; ?>
    <?php if ($lt['status'] !== 'abgeschlossen'): ?><div style="margin-bottom:12px"><a class="btn btn-ghost btn-sm" href="?p=labor_senden&auftrag=<?= (int)$a['id'] ?>">Ans Labor senden (Auftragsformular)</a></div><?php endif; ?>
  <?php endif; ?>
  <?php if ($labDocs): ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Datum</th><th>Charge</th><th>Befund</th><th>Datei</th><th>Kundenportal</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($labDocs as $d): ?>
      <tr>
        <td><?= $d['dok_datum'] ? h(fmt_zeit($d['dok_datum'] . ' 00:00:00', 'd.m.Y')) : h(fmt_zeit($d['angelegt'], 'd.m.Y')) ?></td>
        <td><?= $d['charge_nr'] ? h($d['charge_nr']) : '<span class="muted">–</span>' ?></td>
        <td><?= $labBefund($d['befund'] ?? null) ?></td>
        <td><a href="?p=dokument&id=<?= (int)$d['id'] ?>" target="_blank"><?= h($d['titel'] ?: ($d['datei_orig'] ?: 'Analyse')) ?></a></td>
        <td><form method="post" style="margin:0"><input type="hidden" name="aktion" value="analyse_toggle"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= (int)$d['kunde_sichtbar'] === 1 ? bx_badge('freigegeben','ok') : bx_badge('intern') ?></button></form></td>
        <td style="text-align:right"><form method="post" style="margin:0" onsubmit="return confirm('Analyse löschen?');"><input type="hidden" name="aktion" value="analyse_del"><input type="hidden" name="dok_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">Löschen</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <details class="bx-sek"<?= ($labFlag && !$labDocs) ? ' open' : '' ?> style="margin-top:8px">
    <summary>Laborbericht hochladen</summary>
    <form method="post" enctype="multipart/form-data" style="margin-top:8px" data-busy="Lade hoch…">
      <input type="hidden" name="aktion" value="analyse_upload">
      <div class="bx-grid">
        <div class="bx-field"><label>Datei (PDF/Bild)</label><input type="file" name="dok" required accept="application/pdf,image/*"></div>
        <div class="bx-field"><label>Chargennummer</label><input type="text" name="charge_nr" value="<?= h($labCharge ?: '') ?>" placeholder="z. B. JN26P8"></div>
        <div class="bx-field"><label>Befund</label>
          <select name="befund"><option value="bestanden">bestanden</option><option value="auffaellig">auffällig</option><option value="unklar" selected>unklar / kein Befund</option></select></div>
        <div class="bx-field"><label>Analysendatum</label><input type="date" name="datum"></div>
        <div class="bx-field"><label>Titel (optional)</label><input type="text" name="titel" placeholder="z. B. Labortest"></div>
      </div>
      <div class="bx-row" style="gap:8px;align-items:center;margin-top:var(--sp-3)">
        <input type="checkbox" name="kunde_sichtbar" id="ak_sicht" value="1" checked><label for="ak_sicht" style="margin:0">im Kundenportal sichtbar (freigegeben)</label>
      </div>
      <div class="bx-row" style="margin-top:var(--sp-3)"><button class="btn btn-primary btn-sm" type="submit">Bericht hochladen</button></div>
    </form>
  </details>
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
  // VK je Packung wie die Details-Kachel: GESAMT (Umsatz netto / Menge, inkl. Glas/Etikett) – Umsatz und Marge rechnen
  // mit demselben Wert. vk_stueck ist nur der Herstellungs-Anteil und steht klein darunter, wenn er abweicht.
  $mMenge = (int)$a['menge']; $mNetto = (float)$a['gesamt_netto']; $mVk = auftrag_vk_gesamt($a); $mVkHerst = (float)$a['vk_stueck'];
  $mEkProP = $mMenge > 0 ? $matKostenEur / $mMenge : 0.0;
  $mMarge  = $mNetto - $matKostenEur; $mMargeProz = $mNetto > 0 ? ($mMarge / $mNetto) * 100 : 0.0;
?>
<?php if (isset($_GET['expressfehler'])): ?><div class="bx-panel" data-panel="preise" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)$_GET['expressfehler']) ?></div><?php endif; ?>
<div class="bx-panel" data-panel="preise">
  <h2 style="margin-top:0">Kalkulation (Marge) <span class="muted" style="font-weight:normal;font-size:13px">· nur intern (Admin)</span></h2>
  <div class="bx-grid">
    <div><div class="k muted">VK / Packung (gesamt, netto)</div><div><?= $eur($mVk) ?><?php if ($mVkHerst > 0 && abs($mVkHerst - $mVk) > 0.005): ?> <span class="muted" style="font-size:12px">· davon Herstellung <?= $eur($mVkHerst) ?></span><?php endif; ?></div></div>
    <div><div class="k muted">Menge</div><div><?= number_format($mMenge,0,',','.') ?> Packungen</div></div>
    <div><div class="k muted">Umsatz netto</div><div><?= $eur($mNetto) ?></div></div>
    <?php if ($matVollstaendig): ?>
    <div><div class="k muted">Materialkosten (EK nach Bedarfsmenge)</div><div><?= $eur($matKostenEur) ?> <span class="muted" style="font-size:12px">· <?= $eur($mEkProP) ?>/Packung</span></div></div>
    <div><div class="k muted">Marge (nach Material)</div><div><strong><?= $eur($mMarge) ?></strong> <span class="muted" style="font-size:12px">· <?= number_format($mMargeProz,1,',','.') ?>%</span></div></div>
    <?php else: ?>
    <div style="grid-column:1/-1"><div class="k muted">Marge</div><div class="muted"><?= $matHatNichtEur ? 'Teils Fremdwährungs-EK hinterlegt – Materialkosten lassen sich nicht automatisch summieren.' : (!$ekBedarf ? 'Keine Rohstoff-EK-Preise (Zukauf oder Rezeptur ohne Zutaten) – Materialkosten nicht ermittelbar.' : 'Für nicht alle Rohstoffe ist ein EK-Preis hinterlegt – Marge unvollständig.') ?></div></div>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:10px 0 0">Richtwert: je Rohstoff der Preis einer echten Bestellung zum Auftrag, sonst der günstigste Lieferant in der Staffel, die der Bedarf erreicht – nur Material, ohne Lohn, Verpackung und Gemeinkosten.</p>
</div>

<?php // Teil 5 (08.11.2026): „Lieferanten-Preise zu diesem Auftrag" entfernt – die Preise je Weg stehen im Reiter Beschaffungsweg,
      // die Kalkulation oben nutzt sie (Staffel nach Menge, echte Bestellung zuerst). ?>
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

<?php if ($analyseDocs): // Teil 4: nur Downloads – Hochladen/Freigeben im Reiter Produktion (Block Labor). ?>
<div class="bx-panel" data-panel="dokumente">
  <h2 style="margin-top:0">Laborberichte</h2>
  <div class="bx-row" style="gap:10px;flex-wrap:wrap">
    <?php foreach ($analyseDocs as $d): ?>
      <a class="btn btn-ghost btn-sm" href="?p=dokument&id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener"><?= h($d['titel'] ?: ($d['datei_orig'] ?: 'Laborbericht')) ?><?= $d['charge_nr'] ? ' · ' . h($d['charge_nr']) : '' ?><?= (int)$d['kunde_sichtbar'] === 1 ? '' : ' (intern)' ?></a>
    <?php endforeach; ?>
  </div>
  <div class="muted" style="font-size:12px;margin-top:8px">Hochladen und Freigeben im Reiter Produktion, Block Labor.</div>
</div>
<?php endif; ?>

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
  <?php if (isset($_GET['tab'])): ?>start = <?= json_encode((string)$_GET['tab'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;<?php endif; ?>
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
