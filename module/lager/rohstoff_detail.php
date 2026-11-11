<?php
// Rohstoff / Item – anlegen & bearbeiten (mit leichtem Cockpit)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dokument_ui.php';
require_once BX_ROOT . '/core/anfrage_ui.php';   // Preisanfrage-Popup + Status-Badge
require_once BX_ROOT . '/core/rohstoff_moq.php';  // eigene MOQ (unsere Mindestmenge)
require_once BX_ROOT . '/core/preis_gueltig.php'; // „gültig bis" je Lieferantenpreis

// Labels aus der zentralen Quelle (Block K): fertig = Bulkware, verkaufsfertig = Fertigprodukt. Keys unverändert
// (nur die für einen Warenlager-Artikel gültigen Kategorien; leerkapsel ist ein Rohstoff mit Form kapselhuelle).
$KAT  = ['rohstoff'=>kategorie_label('rohstoff'),'verpackung'=>kategorie_label('verpackung'),'verbrauch'=>kategorie_label('verbrauch'),'fertig'=>kategorie_label('fertig'),'verkaufsfertig'=>kategorie_label('verkaufsfertig'),'maschine'=>kategorie_label('maschine')];
$FORM = ['pulver'=>'Pulver','granulat'=>'Granulat','extrakt'=>'Extrakt','fluessig'=>'Flüssig','oel'=>'Öl','paste'=>'Paste','kristallin'=>'Kristallin','kapselhuelle'=>'Kapselhülle (leer)','premix'=>'Premix (fertige Mischung einer Rezeptur)'];
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
// Als Leerkapsel einstufen (Nico 04.11.2026): Form Kapselhülle setzen, Kapselgröße aus dem Namen, falls noch keine da ist.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'als_leerkapsel' && !$neu) {
    $alt = one("SELECT form, kapselgroesse_id, name FROM item WHERE id=? AND kategorie='rohstoff'", [(int)$id]);
    if ($alt) {
        $kg = (int)($alt['kapselgroesse_id'] ?? 0) ?: kapselgroesse_aus_name((string)$alt['name']);
        q("UPDATE item SET form='kapselhuelle', kapselgroesse_id=? WHERE id=?", [$kg ?: null, (int)$id]);
        aenderung_protokollieren('item', (int)$id, ['form' => $alt['form'], 'kapselgroesse_id' => $alt['kapselgroesse_id']], ['form' => 'kapselhuelle', 'kapselgroesse_id' => $kg ?: null], 'rohstoff');
        bedarf_bump();
    }
    header('Location: ?p=rohstoff&id=' . (int)$id . '&leerkapsel=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'preis_add' && !$neu) {
    $lid = (int)($_POST['lp_lieferant'] ?? 0);
    $preis = preis_eingabe_lesen((string)($_POST['lp_preis'] ?? '0'), (string)($_POST['lp_basis'] ?? '1'));   // 6 Stellen, je 1.000 möglich (11.11.)
    $mengeab = (float)str_replace(',', '.', $_POST['lp_menge_ab'] ?? '0');
    $inco = in_array($_POST['lp_incoterm'] ?? '', array_keys(incoterm_liste()), true) ? $_POST['lp_incoterm'] : null;
    $vers = in_array($_POST['lp_versandart'] ?? '', array_keys(versandart_liste()), true) ? $_POST['lp_versandart'] : null;
    if ($lid && $preis > 0) q("INSERT INTO lieferant_preis (item_id,lieferant_id,menge_ab,preis,waehrung,stand,incoterm,versandart,gueltig_bis) VALUES (?,?,?,?, 'EUR', CURDATE(), ?, ?, ?)", [(int)$id, $lid, $mengeab, $preis, $inco, $vers, pg_lesen((string)($_POST['lp_gueltig_bis'] ?? ''))]);
    item_ek_aus_lieferantenpreis((int)$id);   // leerer/automatischer EK folgt dem günstigsten Lieferantenpreis
    header('Location: ?p=rohstoff&id=' . $id . '&preisok=1#'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'preis_del' && !$neu) {
    lp_verlauf_sichern((int)$id, null, (int)($_POST['preis_id'] ?? 0));   // in den Preisverlauf, nicht spurlos weg
    q("DELETE FROM lieferant_preis WHERE id=? AND item_id=?", [(int)($_POST['preis_id'] ?? 0), (int)$id]);
    item_ek_aus_lieferantenpreis((int)$id);
    header('Location: ?p=rohstoff&id=' . $id . '&preisok=1'); exit;
}
// Menge aus dem Warenlager (unser Bestand) ins Fremdlager eines Kunden umbuchen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'fremdlager_umbuchen' && !$neu) {
    $r = fremdlager_umbuchen((int)$id, (int)($_POST['fl_kunde'] ?? 0),
            (float) str_replace(',', '.', (string)($_POST['fl_menge'] ?? '0')),
            trim($_POST['fl_charge'] ?? '') ?: null, trim($_POST['fl_mhd'] ?? '') ?: null);
    header('Location: ?p=rohstoff&id=' . $id . ($r['ok'] ? '&flok=1' : '&flfehler=' . rawurlencode($r['msg']))); exit;
}
// Reservierung für einen Auftrag setzen/ändern (manuell, am Item). Menge mit Tausenderpunkt -> Dezimal.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'res_setzen' && !$neu) {
    $aid = (int)($_POST['auftrag_id'] ?? 0);
    $raw = str_replace(['.', ' '], '', trim((string)($_POST['menge'] ?? '')));
    $men = (float) str_replace(',', '.', $raw);
    $r = item_reservierung_setzen((int)$id, $aid, $men);
    if ($r['ok'] && $aid) {
        $anr = (string) scalar("SELECT nummer FROM auftrag WHERE id=?", [$aid]);
        log_aktivitaet('item', (int)$id, 'team', $men > 0
            ? ('Reserviert: ' . rtrim(rtrim(number_format($men, 3, ',', '.'), '0'), ',') . ' ' . (string)($it['einheit'] ?? '') . ' für Auftrag ' . $anr . '.')
            : ('Reservierung für Auftrag ' . $anr . ' aufgehoben.'), 'notiz');
    }
    header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=lager&' . ($r['ok'] ? 'resok=1' : 'resfehler=' . rawurlencode($r['msg']))); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'res_storno' && !$neu) {
    item_reservierung_storno((int)($_POST['res_id'] ?? 0), (int)$id);
    header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=lager&resok=1'); exit;
}
// Analysewerte einer Charge erfassen – daraus entsteht UNSER Analysenzertifikat (CoA).
// Die Unterlagen des Vorlieferanten sind die Quelle; weitergegeben wird das bulkify-Dokument.
// Werte aus einem hochgeladenen Lieferanten-PDF VORSCHLAGEN (nicht speichern).
// Der Vorschlag landet im Formular und muss geprueft werden – ein falscher Wert auf einem
// Analysenzertifikat, das an den Kunden geht, waere schlimmer als ein leeres Feld.
$anVorschlag = null; $anVorschlagFehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'analyse_lesen' && !$neu) {
    require_once BX_ROOT . '/core/coa_lesen.php';
    $cid = (int)($_POST['charge_id'] ?? 0);
    $did = (int)($_POST['dok_id'] ?? 0);
    $d = $did ? one("SELECT datei FROM dokument WHERE id=? AND objekt_typ='item' AND objekt_id=?", [$did, (int)$id]) : null;
    if (!$d) {
        $anVorschlagFehler = 'Dokument nicht gefunden.';
    } else {
        $r = coa_werte_lesen(BX_UPLOADS . '/' . basename((string)$d['datei']));
        if (!$r['lesbar']) {
            $anVorschlagFehler = 'Aus diesem PDF laesst sich kein Text lesen – vermutlich ein Scan. Bitte die Werte von Hand eintragen.';
        } elseif (!$r['zeilen']) {
            $anVorschlagFehler = 'Text gelesen, aber keine bekannten Parameter gefunden. Bitte von Hand eintragen.';
        } else {
            $anVorschlag = ['charge_id' => $cid, 'zeilen' => $r['zeilen'], 'kopf' => $r['kopf']];
        }
    }
}
// Einen NEUEN Rohstoff aus einer Spezifikation anlegen: Datei hochladen, auslesen, das
// Anlegeformular damit vorbelegen. Gespeichert wird erst, wenn der Mensch auf Speichern drückt –
// die Datei liegt so lange nur in data/uploads und wird beim Speichern am Rohstoff abgelegt.
$neuKiFehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'spec_neu') {
    require_once BX_ROOT . '/core/spec_ki.php';
    if (!ki_bereit()) {
        $neuKiFehler = 'Die KI ist nicht eingerichtet (Einstellungen → KI).';
    } elseif (empty($_FILES['neu_spec']['name']) || ($_FILES['neu_spec']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $neuKiFehler = 'Bitte eine Datei auswählen.';
    } else {
        if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
        $orig = (string)$_FILES['neu_spec']['name'];
        $ext  = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION)));
        $fn   = 'neu_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
        if (!move_uploaded_file($_FILES['neu_spec']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
            $neuKiFehler = 'Die Datei konnte nicht gespeichert werden.';
        } else {
            $r = spec_ki_lesen(BX_UPLOADS . '/' . $fn);
            if (!$r['ok']) { @unlink(BX_UPLOADS . '/' . $fn); $neuKiFehler = $r['fehler']; }
            else {
                $_SESSION['rohstoff_ki'] = ['datei' => $fn, 'orig' => $orig, 'ergebnis' => $r];
                header('Location: ?p=rohstoff&id=neu&ausspec=1'); exit;
            }
        }
    }
}
// Den vorbereiteten Vorschlag wieder verwerfen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'spec_neu_weg') {
    if (!empty($_SESSION['rohstoff_ki']['datei'])) @unlink(BX_UPLOADS . '/' . basename($_SESSION['rohstoff_ki']['datei']));
    unset($_SESSION['rohstoff_ki']);
    header('Location: ?p=rohstoff&id=neu'); exit;
}

// Eine Lieferantenunterlage mit der KI auslesen. Das Ergebnis ist ein VORSCHLAG: es steht in
// $kiVorschlag und wird beim Rendern angezeigt, gespeichert wird erst auf Knopfdruck.
$kiVorschlag = null; $kiFehler = ''; $kiDok = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_lesen' && !$neu) {
    require_once BX_ROOT . '/core/spec_ki.php';
    $did = (int)($_POST['dok_id'] ?? 0);
    $d = $did ? one("SELECT datei FROM dokument WHERE id=? AND objekt_typ='item' AND objekt_id=?", [$did, (int)$id]) : null;
    if (!$d) {
        $kiFehler = 'Dokument nicht gefunden.';
    } else {
        $r = spec_ki_lesen(BX_UPLOADS . '/' . basename((string)$d['datei']));
        if (!$r['ok']) { $kiFehler = $r['fehler']; }
        else { spec_ki_merken($did, $r); $kiVorschlag = $r; $kiDok = $did; }
    }
}
// Einen früher gemerkten Vorschlag wieder anzeigen (z. B. den, den der Lieferant ausgelöst hat).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_zeigen' && !$neu) {
    require_once BX_ROOT . '/core/spec_ki.php';
    $did = (int)($_POST['dok_id'] ?? 0);
    $kiVorschlag = spec_ki_vorschlag($did);
    $kiDok = $did;
    if (!$kiVorschlag) $kiFehler = 'Zu dieser Datei liegt kein Vorschlag vor.';
}
// Direkt nach dem Upload (&kidok=) den frisch erzeugten Vorschlag ohne Extra-Klick anzeigen.
if (!$kiVorschlag && !$neu && ($_GET['kidok'] ?? '') !== '') {
    $gd = (int)$_GET['kidok'];
    if ($gd && scalar("SELECT id FROM dokument WHERE id=? AND objekt_typ='item' AND objekt_id=?", [$gd, (int)$id])) {
        require_once BX_ROOT . '/core/spec_ki.php';
        $kiVorschlag = spec_ki_vorschlag($gd);
        if ($kiVorschlag) $kiDok = $gd;
    }
}
// Geprüfte Felder in die Stammdaten übernehmen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_uebernehmen' && !$neu) {
    require_once BX_ROOT . '/core/spec_ki.php';
    $did = (int)($_POST['dok_id'] ?? 0);
    $vor = spec_ki_vorschlag($did);
    $n = $vor ? spec_ki_uebernehmen((int)$id, (array)($vor['stamm'] ?? []), (array)($_POST['feld'] ?? []), true) : 0;
    // Charakteristische Kennwerte (OPC-Gehalt …), Wirkstoffe und Grenzwerte gleich mitübernehmen (additiv,
    // bestehende bleiben) – so landet die vollständige Standardisierung am Rohstoff, nicht nur die Stammfelder.
    if ($vor) { spec_ki_kennwerte((int)$id, $vor); spec_ki_wirkstoffe((int)$id, $vor); spec_ki_grenzwerte((int)$id, $vor); }
    header('Location: ?p=rohstoff&id=' . (int)$id . '&kiueb=' . $n . '#spec'); exit;
}
// Analysewerte aus dem Vorschlag an einer Charge speichern.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_werte' && !$neu) {
    require_once BX_ROOT . '/core/spec_ki.php';
    $vor = spec_ki_vorschlag((int)($_POST['dok_id'] ?? 0));
    $cid = (int)($_POST['charge_id'] ?? 0);
    $n = ($vor && $cid) ? spec_ki_werte_speichern($cid, (array)($vor['werte'] ?? [])) : 0;
    header('Location: ?p=rohstoff&id=' . (int)$id . '&kiwerte=' . $n . '#lager'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'analyse_save' && !$neu) {
    $cid = (int)($_POST['charge_id'] ?? 0);
    if ($cid && scalar("SELECT id FROM charge WHERE id=? AND item_id=?", [$cid, (int)$id])) {
        q("DELETE FROM charge_analyse WHERE charge_id=?", [$cid]);
        $sort = 0;
        foreach (($_POST['a_par'] ?? []) as $i2 => $par) {
            $par = trim((string)$par); if ($par === '') continue;
            q("INSERT INTO charge_analyse (charge_id,parameter,spezifikation,ergebnis,methode,sort) VALUES (?,?,?,?,?,?)",
              [$cid, mb_substr($par, 0, 120),
               mb_substr(trim((string)($_POST['a_spec'][$i2] ?? '')), 0, 120) ?: null,
               mb_substr(trim((string)($_POST['a_erg'][$i2] ?? '')), 0, 120) ?: null,
               mb_substr(trim((string)($_POST['a_met'][$i2] ?? '')), 0, 120) ?: null, $sort++]);
        }
    }
    header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=chargen&analyse=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dok_upload' && !$neu) {
    $dokId = dokument_upload('item', (int)$id);
    // Frisch hochgeladene Spezifikationen/CoA gleich mit der KI auslesen. Kennwerte/Wirkstoffe/Grenzwerte
    // werden additiv ergaenzt; die STAMMDATEN-Vorschlaege warten im Reiter Spezifikation aufs Uebernehmen.
    $coaChargeNeu = 0; $kiAuto = 0; $kiOff = 0;
    if ($dokId) {
        require_once BX_ROOT . '/core/spec_ki.php';
        $dTyp = (string) scalar("SELECT typ FROM dokument WHERE id=?", [$dokId]);
        if (in_array($dTyp, ['spec', 'coa', 'analyse'], true)) {
            $kiOk = spec_ki_nach_upload($dokId);          // liest + legt bei CoA Vorab-Charge/Grenzwerte an
            $erg  = $kiOk ? spec_ki_vorschlag($dokId) : null;
            if ($erg) {
                $kiAuto = 1;
                if (in_array((string)($erg['typ'] ?? ''), ['coa', 'beides'], true)) $coaChargeNeu = 1;
            } else {
                $kiOff = 1;                                // KI nicht aktiv oder Datei (Scan) nicht auslesbar
            }
        }
    }
    // Bei KI-Vorschlag direkt in den Reiter Spezifikation – dort steht der Vorschlag zum Übernehmen.
    $zielTab = $kiAuto ? 'spec' : 'dok';
    header('Location: ?p=rohstoff&id=' . $id . '&tab=' . $zielTab . '&gespeichert=1'
        . ($coaChargeNeu ? '&coacharge=1' : '') . ($kiAuto ? '&kiauto=1&kidok=' . (int)$dokId : '') . ($kiOff ? '&kioff=1' : '')); exit;
}
// bulkify-Spezifikation für den Kunden freigeben bzw. Freigabe zurückziehen (separater Schritt).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'spec_freigabe' && !$neu) {
    require_once BX_ROOT . '/core/spec_ki.php';
    $an = isset($_POST['freigeben']) && $_POST['freigeben'] === '1';
    if ($an) {
        // Freigeben = aktuellen Stand als neue Version einfrieren (Snapshot). Kunde sieht ab jetzt diesen Stand.
        $wer = trim((string)(current_user()['name'] ?? 'Team'));
        spec_snapshot_setzen((int)$id, $wer, false);
        log_aktivitaet('item', (int)$id, 'team', 'bulkify-Spezifikation für den Kunden freigegeben.', 'notiz');
    } else {
        q("UPDATE item SET spec_freigegeben=0, spec_aenderung_offen=0 WHERE id=?", [(int)$id]);
        log_aktivitaet('item', (int)$id, 'team', 'Kundenfreigabe der bulkify-Spezifikation zurückgezogen.', 'notiz');
    }
    header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=spec&specfrei=' . ($an ? '1' : '0')); exit;
}
// Novel-Food-Status dieses Rohstoffs gegen den EU-Katalog prüfen + mit Datum festschreiben (Snapshot fürs PIB).
// KI-Kurzinfo erzeugen/erneuern (nur auf Knopfdruck) bzw. löschen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_info' && !$neu) {
    $r = item_ki_info_erzeugen((int)$id);
    header('Location: ?p=rohstoff&id=' . (int)$id . ($r['ok'] ? '&kiok=1' : '&kierr=' . rawurlencode($r['fehler'] ?? 'Fehler'))); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'ki_info_del' && !$neu) {
    q("UPDATE item SET ki_info=NULL, ki_info_am=NULL WHERE id=?", [(int)$id]);
    header('Location: ?p=rohstoff&id=' . (int)$id . '&kidel=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'nf_pruefen' && !$neu) {
    $st = item_novelfood_aktualisieren((int)$id);
    log_aktivitaet('item', (int)$id, 'team', 'Novel-Food-Status geprüft: ' . novelfood_status_meta($st)['label'] . '.', 'notiz');
    header('Location: ?p=rohstoff&id=' . (int)$id . '&nfok=1'); exit;
}
// bulkify-CoA einer Charge für den Kunden freigeben bzw. zurückziehen (separater Schritt, wie bei der Spec).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'coa_freigabe' && !$neu) {
    $cid = (int)($_POST['charge_id'] ?? 0);
    if ($cid && scalar("SELECT id FROM charge WHERE id=? AND item_id=?", [$cid, (int)$id])) {
        if (isset($_POST['freigeben']) && $_POST['freigeben'] === '1') {
            $wer = trim((string)(current_user()['name'] ?? 'Team'));
            q("UPDATE charge SET coa_freigegeben=1, coa_freigabe_am=?, coa_freigabe_von=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $wer, $cid]);
            log_aktivitaet('item', (int)$id, 'team', 'bulkify-CoA einer Charge für den Kunden freigegeben.', 'notiz');
        } else {
            q("UPDATE charge SET coa_freigegeben=0 WHERE id=?", [$cid]);
            log_aktivitaet('item', (int)$id, 'team', 'Kundenfreigabe eines bulkify-CoA zurückgezogen.', 'notiz');
        }
    }
    header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=lager&coafrei=1'); exit;
}
// Bestand einer Charge korrigieren (nur Admin): verfügbare Menge + Einheit setzen – z. B. eine falsch in „g"
// gebuchte Charge auf den echten kg-Wert bringen. Setzt menge + menge_verfuegbar und passt den Status an.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'charge_bestand_korr' && !$neu) {
    // Block B (11.11.2026): dieselbe Funktion wie Chargenseite und Lager-App (core/charge_korrektur.php) – Korrekturbewegung
    // statt Überschreiben der Ursprungsmenge (Fable 18.1.3/18.1.6), Grund-Auswahl, Gebinde, Bestellung rechnet neu.
    if (!has_role('admin')) { header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=lager&korrfehler=1'); exit; }
    require_once BX_ROOT . '/core/charge_korrektur.php';
    $cid = (int)($_POST['charge_id'] ?? 0);
    if ($cid && (int) scalar("SELECT COUNT(*) FROM charge WHERE id=? AND item_id=?", [$cid, (int)$id]) === 1) {
        if ((string)($_POST['korr_grund'] ?? '') === '') { header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=lager&korrfehler=grund'); exit; }
        $r = charge_menge_korrigieren($cid, (string)($_POST['korr_was'] ?? 'verfuegbar'), zahl_lesen((string)($_POST['menge'] ?? ''), true),
                                      (string)$_POST['korr_grund'], (string)($_POST['grund'] ?? ''), (string)(current_user()['name'] ?? 'Team'));
        if ($r['ok']) log_aktivitaet('item', (int)$id, 'team', 'Bestandskorrektur Charge #' . $cid . ': ' . $r['meldung'] . ($r['hinweis'] ? ' ' . $r['hinweis'] : ''), 'notiz');
        else { header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=lager&korrfehler=' . rawurlencode($r['meldung'])); exit; }
    }
    header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=lager&korrok=1'); exit;
}
// Charge-Stammdaten korrigieren: Chargennummer, MHD, Lagerort (standort). Nur Admin. Verlaufseintrag am Artikel.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'charge_daten_korr' && !$neu) {
    if (!has_role('admin')) { header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=lager&korrfehler=1'); exit; }
    $cid = (int)($_POST['charge_id'] ?? 0);
    $c   = $cid ? one("SELECT id, charge_nr, mhd, standort FROM charge WHERE id=? AND item_id=?", [$cid, (int)$id]) : null;
    if ($c) {
        $cnr  = trim((string)($_POST['charge_nr'] ?? ''));
        $mhd  = (is_string($_POST['mhd'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string)$_POST['mhd']))) ? trim((string)$_POST['mhd']) : null;
        $ort  = in_array(($_POST['standort'] ?? ''), ['lager1','lager2','produktion'], true) ? (string)$_POST['standort'] : (string)$c['standort'];
        $hatStandort = column_exists('charge', 'standort');
        if ($hatStandort) q("UPDATE charge SET charge_nr=?, mhd=?, standort=? WHERE id=? AND item_id=?", [$cnr ?: null, $mhd, $ort, $cid, (int)$id]);
        else              q("UPDATE charge SET charge_nr=?, mhd=? WHERE id=? AND item_id=?", [$cnr ?: null, $mhd, $cid, (int)$id]);
        log_aktivitaet('item', (int)$id, 'team',
            'Charge-Daten korrigiert (' . ($cnr ?: ('#' . $cid)) . '): Nr/MHD/Lagerort aktualisiert.', 'notiz');
    }
    header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=lager&korrok=1'); exit;
}
// Einheit des Artikels ändern – gilt für den Artikel UND alle seine Chargen (Einheiten nie vermischen).
// Modus 'bezeichnung' = nur die Bezeichnung war falsch (Mengen bleiben). Modus 'umrechnen' = Mengen × Faktor.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'einheit_aendern' && !$neu) {
    if (!has_role('admin')) { header('Location: ?p=rohstoff&id=' . (int)$id . '&einhfehler=1'); exit; }
    $neuE = trim((string)($_POST['neue_einheit'] ?? ''));
    $modus = ($_POST['modus'] ?? '') === 'umrechnen' ? 'umrechnen' : 'bezeichnung';
    $faktor = (float) str_replace(',', '.', (string)($_POST['faktor'] ?? '1'));
    $altE = (string) scalar("SELECT einheit FROM item WHERE id=?", [(int)$id]);
    if ($neuE === '') { header('Location: ?p=rohstoff&id=' . (int)$id . '&einhfehler=leer'); exit; }
    if ($modus === 'umrechnen' && $faktor <= 0) { header('Location: ?p=rohstoff&id=' . (int)$id . '&einhfehler=faktor'); exit; }
    q("UPDATE item SET einheit=? WHERE id=?", [$neuE, (int)$id]);
    if ($modus === 'umrechnen') {
        q("UPDATE charge SET menge=menge*?, menge_verfuegbar=menge_verfuegbar*?, einheit=? WHERE item_id=?", [$faktor, $faktor, $neuE, (int)$id]);
        q("UPDATE reservierung SET menge=menge*? WHERE item_id=?", [$faktor, (int)$id]);
        log_aktivitaet('item', (int)$id, 'team', 'Einheit umgerechnet: ' . ($altE ?: '–') . ' -> ' . $neuE . ' (Faktor ' . rtrim(rtrim(number_format($faktor, 4, ',', '.'), '0'), ',') . '); alle Chargen angepasst.', 'notiz');
    } else {
        q("UPDATE charge SET einheit=? WHERE item_id=?", [$neuE, (int)$id]);
        log_aktivitaet('item', (int)$id, 'team', 'Einheit-Bezeichnung korrigiert: ' . ($altE ?: '–') . ' -> ' . $neuE . ' (Mengen unverändert).', 'notiz');
    }
    if (function_exists('bedarf_bump')) bedarf_bump();
    header('Location: ?p=rohstoff&id=' . (int)$id . '&einhok=1'); exit;
}
// Lose Doppel-Artikel in den richtigen Artikel zusammenführen: Chargen + Reservierungen wandern auf das Ziel,
// der leere Doppel-Artikel wird DEAKTIVIERT (gesperrt + zusammengefuehrt_in), nicht gelöscht. Nur Admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'artikel_merge' && !$neu) {
    if (!has_role('admin')) { header('Location: ?p=rohstoff&id=' . (int)$id . '&mergefehler=1'); exit; }
    $ziel = (int)($_POST['ziel_item_id'] ?? 0);
    $r = item_zusammenfuehren((int)$id, $ziel);   // gemeinsame Logik (auch Datenprüfung)
    if (!$r['ok']) { header('Location: ?p=rohstoff&id=' . (int)$id . '&mergefehler=' . $r['fehler']); exit; }
    header('Location: ?p=rohstoff&id=' . $ziel . '&merged=1'); exit;
}
// Rohstoff löschen (Bereinigung, z. B. Import-Dublette). Nur Admin; abgesichert in rohstoff_loeschen().
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rohstoff_loeschen' && !$neu) {
    if (!has_role('admin')) { header('Location: ?p=rohstoff&id=' . (int)$id . '&delfehler=' . rawurlencode('Nur Admins dürfen löschen.')); exit; }
    $r = rohstoff_loeschen((int)$id);
    if ($r['ok']) { header('Location: ?p=rohstoffe&geloescht=1'); exit; }
    header('Location: ?p=rohstoff&id=' . (int)$id . '&tab=spec&delfehler=' . rawurlencode($r['fehler'] ?? 'Fehler')); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dok_frei' && !$neu) {
    dokument_freigabe_toggle('item', (int)$id, (int)($_POST['dok_id'] ?? 0));
    header('Location: ?p=rohstoff&id=' . $id . '&tab=dok'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dok_del' && !$neu) {
    dokument_delete('item', (int)$id, (int)($_POST['dok_id'] ?? 0));
    header('Location: ?p=rohstoff&id=' . $id . '&tab=dok'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === '') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    if ($f('name') === '') {
        $fehler = 'Name ist ein Pflichtfeld.';
    } elseif (isset($_POST['fremdprodukt']) && ($_POST['fremd_besitzer_id'] ?? '') === '') {
        $fehler = 'Für ein Fremdprodukt bitte den Besitzer-Kunden wählen.';
    } else {
        $felder = ['artikelnummer','name','name_en','name_lat','cas','kategorie','form','art',
                   'beschaffenheit','dev',
                   'material','farbe','kapselgroesse_id','leergewicht_mg',
                   'dichte','allergene','overage_prozent','einheit',
                   'ek_preis','preis_bezug','vk_aufschlag_prozent','haupt_lieferant_id','gesperrt','notiz',
                   // Spezifikation
                   'synonym','ec_nr','bot_quelle','herkunftsland','haltbarkeit','lagerbedingungen','zusaetze',
                   'vegan','gvo_frei','bestrahlt','tse_bse_frei','zertifikate','spec_nr','spec_version','spec_gueltig_ab',
                   // Öffentliche Rohstoff-DB (Website)
                   'website_sichtbar','web_beschreibung',
                   // Fremdprodukt (Kundenware, Lager 2)
                   'fremdprodukt','fremd_besitzer_id'];
        $vals = array_map($f, $felder);
        // Fremdprodukt: Warenart Kundenware. Dahinter ist es ein Fertigprodukt (verkaufsfertig); Besitzer-Kunde pflichtig.
        $fpOn = isset($_POST['fremdprodukt']) ? 1 : 0;
        $vals[array_search('fremdprodukt', $felder)]      = $fpOn;
        $vals[array_search('fremd_besitzer_id', $felder)] = ($fpOn && ($_POST['fremd_besitzer_id'] ?? '') !== '') ? (int)$_POST['fremd_besitzer_id'] : null;
        if ($fpOn) $vals[array_search('kategorie', $felder)] = 'verkaufsfertig';
        $vals[array_search('gesperrt', $felder)] = isset($_POST['gesperrt']) ? 1 : 0;
        $vals[array_search('website_sichtbar', $felder)] = isset($_POST['website_sichtbar']) ? 1 : 0;
        $vals[array_search('haupt_lieferant_id', $felder)] = ($_POST['haupt_lieferant_id'] ?? '') !== '' ? (int)$_POST['haupt_lieferant_id'] : null;
        $vals[array_search('kapselgroesse_id', $felder)] = ($_POST['kapselgroesse_id'] ?? '') !== '' ? (int)$_POST['kapselgroesse_id'] : null;
        if (trim($_POST['leergewicht_mg'] ?? '') === '') $vals[array_search('leergewicht_mg', $felder)] = null;
        if (trim($_POST['dichte'] ?? '') === '') $vals[array_search('dichte', $felder)] = null;
        // Ja/Nein/Unbekannt-Flags + Datum: leer -> NULL
        foreach (['vegan','gvo_frei','bestrahlt','tse_bse_frei'] as $bf) { $ix = array_search($bf, $felder); $vals[$ix] = ($vals[$ix] === '' ? null : (int)$vals[$ix]); }
        if (trim($_POST['spec_gueltig_ab'] ?? '') === '') $vals[array_search('spec_gueltig_ab', $felder)] = null;
        // VK-Aufschlag: bei Produktionsrolle ist das Feld ausgeblendet -> vorhandenen Wert behalten (nicht überschreiben)
        if (!darf_verkauf()) {
            $vals[array_search('vk_aufschlag_prozent', $felder)] = $neu ? null : scalar("SELECT vk_aufschlag_prozent FROM item WHERE id=?", [$id]);
        } elseif (trim($_POST['vk_aufschlag_prozent'] ?? '') === '') {
            $vals[array_search('vk_aufschlag_prozent', $felder)] = null;
        }
        foreach (['ek_preis','overage_prozent'] as $nf) { $ix = array_search($nf, $felder); if (trim((string)$vals[$ix]) === '') $vals[$ix] = 0; }
        // EK als Text mit Komma, optional je 1.000 (Sofortfix 11.11.2026) – 6 Nachkommastellen.
        $ixEk = array_search('ek_preis', $felder); $vals[$ixEk] = round(preis_eingabe_lesen((string)$vals[$ixEk], (string)($_POST['ek_basis'] ?? '1')), 6);
        // Einheit wird bei bestehenden Artikeln NUR über „Einheit ändern" gepflegt (nie vermischen). Das Stammdaten-
        // Feld ist dort deaktiviert und würde leer gesendet -> aktuellen Wert behalten.
        if (!$neu) $vals[array_search('einheit', $felder)] = (string) scalar("SELECT einheit FROM item WHERE id=?", [(int)$id]);
        $war_neu = $neu;
        if ($neu) {
            if (trim($vals[array_search('artikelnummer', $felder)]) === '') $vals[array_search('artikelnummer', $felder)] = naechste_nummer(item_prefix($vals[array_search('kategorie', $felder)]));
            $ph = implode(',', array_fill(0, count($felder), '?'));
            q("INSERT INTO item (" . implode(',', $felder) . ") VALUES ($ph)", $vals);
            $id = insert_id();
            log_aktivitaet('item', (int)$id, 'team', 'Rohstoff angelegt.', 'notiz');
        } else {
            $set = implode(',', array_map(fn($c) => "$c=?", $felder));
            $ekAlt = (float) scalar("SELECT ek_preis FROM item WHERE id=?", [(int)$id]);
            $vals[] = (int)$id;
            q("UPDATE item SET $set WHERE id=?", $vals);
            // EK von Hand geändert -> gilt als gepflegt, Lieferantenpreise überschreiben ihn nicht mehr. 0 = wieder automatisch.
            if (abs((float)$vals[array_search('ek_preis', $felder)] - $ekAlt) > 1e-9) { try { q("UPDATE item SET ek_auto=0 WHERE id=?", [(int)$id]); } catch (Throwable $e) {} }
        }
        item_ek_aus_lieferantenpreis((int)$id);
        // Preis schwankt (Nico 11.11.2026, z. B. Whey): Haken + Standard-Gültigkeit in Tagen - nur wenn das Feld im Formular war.
        if (isset($_POST['preis_gueltig_tage_feld'])) {
            pg_schema();
            $pgS = isset($_POST['preis_schwankt']) ? 1 : 0;
            $pgT = (int)($_POST['preis_gueltig_tage'] ?? 0);
            q("UPDATE item SET preis_schwankt=?, preis_gueltig_tage=? WHERE id=?", [$pgS, $pgS ? max(1, $pgT ?: 14) : ($pgT > 0 ? $pgT : null), (int)$id]);
        }
        // Eigene MOQ (Nico 11.11.2026) - nur wenn das Feld im Formular war (Rohstoffe).
        if (isset($_POST['moq_einheit'])) {
            moq_schema();
            $moqM = zahl_lesen((string)($_POST['moq_menge'] ?? ''), true);
            $moqE = in_array($_POST['moq_einheit'], moq_einheiten(), true) ? (string)$_POST['moq_einheit'] : 'kg';
            q("UPDATE item SET moq_menge=?, moq_einheit=?, moq_notiz=? WHERE id=?",
              [$moqM > 0 ? $moqM : null, $moqM > 0 ? $moqE : null, $moqM > 0 ? (mb_substr(trim((string)($_POST['moq_notiz'] ?? '')), 0, 190) ?: null) : null, (int)$id]);
        }
        // Fremdprodukt markiert: den vorhandenen eigenen Bestand dem Besitzer-Kunden zuschreiben (Lager 2).
        if ($fpOn && $vals[array_search('fremd_besitzer_id', $felder)]) {
            $n = fremdprodukt_bestand_zuordnen((int)$id, (int)$vals[array_search('fremd_besitzer_id', $felder)]);
            if ($n > 0) log_aktivitaet('item', (int)$id, 'team', 'Als Fremdprodukt markiert – ' . $n . ' Charge(n) dem Kunden ins Fremdlager (Lager 2) zugeordnet.', 'notiz');
        }
        // Für die Website freigegeben? Dann einen stabilen URL-Slug sicherstellen (einmalig).
        if (isset($_POST['website_sichtbar'])) {
            require_once BX_ROOT . '/core/rohstoff_public.php';
            rohstoff_web_slug_sicherstellen((int)$id);
        }
        // Wirkstoffe synchronisieren (mehrere möglich; neuer Name -> Nährstoff wird angelegt)
        q("DELETE FROM item_wirkstoff WHERE item_id=?", [(int)$id]);
        $wn = $_POST['wirk_name'] ?? []; $wg = $_POST['wirk_gehalt'] ?? []; $we = $_POST['wirk_einheit'] ?? [];
        $einhOk = ['prozent','ie_g','ie_kg','mg_g','ug_g'];
        foreach ($wn as $i => $nm) {
            $nm = trim($nm); if ($nm === '') continue;
            $nid = naehrstoff_id_by_name($nm);
            $g = str_replace(',', '.', trim($wg[$i] ?? '')); $g = $g === '' ? null : $g;
            $eh = in_array($we[$i] ?? 'prozent', $einhOk, true) ? $we[$i] : 'prozent';
            // gehalt_prozent nur bei % füllen (Rückwärtskompat.); der generische Wert steht immer in gehalt_wert
            $gp = ($eh === 'prozent') ? $g : null;
            if ($nid) q("INSERT INTO item_wirkstoff (item_id,naehrstoff_id,gehalt_prozent,gehalt_wert,gehalt_einheit,sort) VALUES (?,?,?,?,?,?)",
                        [(int)$id, $nid, $gp, $g, $eh, $i]);
        }
        // Kennwerte (Parameter · Wert) synchronisieren
        q("DELETE FROM item_kennwert WHERE item_id=?", [(int)$id]);
        $kp = $_POST['kw_param'] ?? []; $kw = $_POST['kw_wert'] ?? [];
        foreach ($kp as $i => $pn) { $pn = trim($pn); if ($pn === '') continue; q("INSERT INTO item_kennwert (item_id,parameter,wert,sort) VALUES (?,?,?,?)", [(int)$id, $pn, trim($kw[$i] ?? ''), (int)$i]); }
        // Grenzwerte (Reinheit/Mikrobiologie · Sollwert) synchronisieren
        q("DELETE FROM item_grenzwert WHERE item_id=?", [(int)$id]);
        $gp = $_POST['gw_param'] ?? []; $gw = $_POST['gw_wert'] ?? [];
        foreach ($gp as $i => $pn) { $pn = trim($pn); if ($pn === '') continue; q("INSERT INTO item_grenzwert (item_id,parameter,grenzwert,sort) VALUES (?,?,?,?)", [(int)$id, $pn, trim($gw[$i] ?? ''), (int)$i]); }
        // Freigabe-Politik: Erst-Erfassung automatisch freigeben, inhaltliche Änderung markiert (alte Version bleibt
        // beim Kunden sichtbar, bis das Team die Änderung freigibt). Unveränderte Speicherungen bleiben folgenlos.
        require_once BX_ROOT . '/core/spec_ki.php';
        spec_nach_datenaenderung((int)$id, trim((string)(current_user()['name'] ?? 'Team')));
        // Spec-PDF hochladen (in data/uploads, außerhalb public). Gehört in den Reiter „Dokumente":
        // zusätzlich zur schnellen Verknüpfung am Item als Dokument (typ spec, intern) ablegen.
        if (!empty($_FILES['spec_pdf']['name']) && is_uploaded_file($_FILES['spec_pdf']['tmp_name'] ?? '')) {
            if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
            $orig = (string)$_FILES['spec_pdf']['name'];
            $fn = 'spec_' . (int)$id . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
            if (move_uploaded_file($_FILES['spec_pdf']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
                q("UPDATE item SET spec_pdf=? WHERE id=?", [$fn, (int)$id]);
                // Als Dokument im Reiter „Dokumente" führen (intern – Lieferanten-Original, nie an den Kunden).
                q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,kunde_sichtbar) VALUES ('item',?,?,?,?,?,0)",
                  [(int)$id, 'spec', 'Spezifikation (Lieferant)', $fn, mb_substr($orig, 0, 190)]);
            }
        }
        // Kam der Rohstoff aus einer hochgeladenen Spezifikation? Dann gehört die Datei jetzt an ihn.
        $coaChargeNeu = 0;
        if ($war_neu && !empty($_SESSION['rohstoff_ki']['datei'])) {
            require_once BX_ROOT . '/core/spec_ki.php';
            $ki = $_SESSION['rohstoff_ki'];
            $typ = ((($ki['ergebnis']['typ'] ?? '') === 'coa') || (($ki['ergebnis']['typ'] ?? '') === 'beides')) ? 'coa' : 'spec';
            q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,kunde_sichtbar) VALUES ('item',?,?,?,?,?,0)",
              [(int)$id, $typ, 'Aus dem Anlegen: ' . mb_substr((string)$ki['orig'], 0, 150), $ki['datei'], $ki['orig']]);
            spec_ki_merken((int)insert_id(), (array)$ki['ergebnis']);
            // Ist es eine CoA? Dann direkt eine (Vorab-)Charge mit den gemessenen Werten anlegen –
            // die Grenzwerte am Rohstoff kommen bereits aus dem Formular (oben synchronisiert).
            $lief = ($_POST['haupt_lieferant_id'] ?? '') !== '' ? (int)$_POST['haupt_lieferant_id'] : null;
            $coaChargeNeu = (int) (spec_ki_coa_charge((int)$id, (array)$ki['ergebnis'], $lief) ?? 0);
            unset($_SESSION['rohstoff_ki']);
        }
        // Auf dem Reiter bleiben, auf dem gespeichert wurde (statt zurück auf „Stammdaten").
        $bleibTab = preg_replace('/[^a-z]/', '', (string)($_POST['active_tab'] ?? ''));
        header('Location: ?p=rohstoff&id=' . $id . '&gespeichert=1' . ($bleibTab ? '&tab=' . $bleibTab : '') . ($coaChargeNeu ? '&coacharge=' . $coaChargeNeu : '')); exit;
    }
}

$neuForm = ($_GET['form'] ?? '') === 'kapselhuelle' ? 'kapselhuelle' : 'pulver';
$neuDefault = $neuForm === 'kapselhuelle'
    ? ['kategorie'=>'rohstoff','form'=>'kapselhuelle','einheit'=>'Stk','preis_bezug'=>'Stk','gesperrt'=>0]
    : ['kategorie'=>'rohstoff','form'=>'pulver','einheit'=>'kg','preis_bezug'=>'kg','gesperrt'=>0];
// Liegt ein ausgelesener Vorschlag bereit, füllt er das Anlegeformular – Feld für Feld sichtbar,
// änderbar, und gespeichert wird erst beim Klick auf Speichern.
$neuKi = $neu ? ($_SESSION['rohstoff_ki']['ergebnis'] ?? null) : null;
if ($neuKi && !empty($neuKi['stamm'])) $neuDefault = array_merge($neuDefault, (array)$neuKi['stamm']);
// CAS-Vorschlag der KI (aus Fachwissen) einsetzen, wenn im Dokument keine CAS stand.
$casVorschlag = '';
if ($neuKi) {
    $casVorschlag = trim((string)($neuKi['cas_vorschlag'] ?? ''));
    if ($casVorschlag !== '' && trim((string)($neuDefault['cas'] ?? '')) === '') $neuDefault['cas'] = $casVorschlag;
}
// Leerer EK, aber Lieferantenpreise vorhanden (z. B. vor dem 06.11.2026 erfasst) -> beim Öffnen nachziehen.
if (!$neu) item_ek_aus_lieferantenpreis((int)$id);
$it = $neu ? $neuDefault : one("SELECT * FROM item WHERE id=?", [(int)$id]);
if (!$it) { $neu = true; $it = $neuDefault; }
$v = fn($key) => h((string)($it[$key] ?? ''));
$gesperrt = (int)($it['gesperrt'] ?? 0) === 1;
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
seed_kapselgroesse_if_empty();
$KAPSELN = all("SELECT id, name, fuellmenge_mg FROM kapselgroesse ORDER BY fuellmenge_mg ASC");
seed_naehrstoff_if_empty();
$naehrstoffe = all("SELECT * FROM naehrstoff ORDER BY ist_nrv DESC, kategorie, sort, name");
$wirkstoffe = $neu ? [] : all("SELECT iw.*, n.name AS n_name, n.nrv_wert, n.einheit AS n_einheit, n.ist_nrv
                               FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id
                               WHERE iw.item_id=? ORDER BY iw.sort, iw.id", [(int)$id]);
require_once BX_ROOT . '/core/spec_ki.php';   // spec_kennwert_relevant / item_kennwerte_relevant
// Nur echte charakteristische Kennwerte zeigen (Schwermetalle/Mikro/Mineralien raus); beim Speichern
// werden die ausgefilterten Alt-Zeilen dann automatisch mit entfernt – Bestand heilt sich ohne Neu-Upload.
$kennwerte = $neu ? [] : item_kennwerte_relevant((int)$id);
$grenzwerte = $neu ? [] : all("SELECT * FROM item_grenzwert WHERE item_id=? ORDER BY sort, id", [(int)$id]);
// Neuanlage aus einer Spezifikation: Wirkstoffe (Assay), Kennwerte und Grenzwerte aus dem KI-Vorschlag
// vorbelegen, damit z. B. Eisen-Gehalt und Keim-/Metallgrenzwerte nicht verloren gehen. Werden im
// Formular angezeigt, geprüft, dann gespeichert.
if ($neu && $neuKi) {
    foreach ((array)($neuKi['wirkstoffe'] ?? []) as $w) {
        $nm = trim((string)($w['name'] ?? '')); if ($nm === '') continue;
        $g = $w['gehalt_prozent'] ?? null;
        $wirkstoffe[] = ['n_name' => $nm, 'gehalt_prozent' => ($g === null || $g === '') ? '' : (string)$g];
    }
    foreach ((array)($neuKi['kennwerte'] ?? []) as $kw) {
        $p = trim((string)($kw['parameter'] ?? '')); if ($p === '') continue;
        $kennwerte[] = ['parameter' => $p, 'wert' => trim((string)($kw['wert'] ?? ''))];
    }
    // Grenzwerte = die Sollwerte (spezifikation) aus den Analysewerten (Schwermetalle, Mikrobiologie ...).
    $gwSeen = [];
    foreach ((array)($neuKi['werte'] ?? []) as $z) {
        $p = trim((string)($z['parameter'] ?? '')); $g = trim((string)($z['spezifikation'] ?? ''));
        if ($p === '' || $g === '' || isset($gwSeen[$p])) continue;
        $gwSeen[$p] = true;
        $grenzwerte[] = ['parameter' => $p, 'grenzwert' => $g];
    }
}
if (!$neu) { seed_aktivitaet_if_empty(); $verlauf = verlauf_fuer('item', (int)$id); } else { $verlauf = []; }
// A2: echte Bewegungen (Wareneingänge + Produktionsverbrauch) und Verwendung in Produktionsaufträgen.
$bewegungen = $neu ? [] : item_bewegungen((int)$id);
$verwendung = $neu ? [] : item_verwendung((int)$id);
$charges = $neu ? [] : all("SELECT c.*, l.firma AS lieferant_firma FROM charge c LEFT JOIN lieferanten l ON l.id=c.lieferant_id WHERE c.item_id=? ORDER BY c.status, c.mhd", [(int)$id]);
$bestand_frei = $neu ? 0 : item_bestand((int)$id, true);
$bestand_qua  = $neu ? 0 : (item_bestand((int)$id, false) - $bestand_frei);
// A1: Bestand je EINHEIT (Stück/Kilo nicht vermischen). Bei gemischten Einheiten getrennt anzeigen.
$bestand_frei_je = $neu ? [] : charge_bestand_je_einheit((int)$id, ['frei']);
$bestand_qua_je  = $neu ? [] : charge_bestand_je_einheit((int)$id, ['quarantaene']);
$einheiten_gemischt = count(array_unique(array_merge(array_keys($bestand_frei_je), array_keys($bestand_qua_je)))) > 1;
$bestandWert = function(array $je): string {
    if (!$je) return '<span class="muted">0</span>';
    $t = []; foreach ($je as $eh => $m) $t[] = h(rtrim(rtrim(number_format((float)$m, 3, ',', '.'), '0'), ',')) . ' ' . h($eh);
    return implode(' + ', $t);
};
$reserviert_gesamt = $neu ? 0 : item_reserviert_gesamt((int)$id);
$reservierungen    = $neu ? [] : item_reservierungen_liste((int)$id);
// Aufträge, auf die reserviert werden kann (laufend – nicht versendet/storniert), neueste zuerst.
// Welche Aufträge brauchen DIESEN Artikel – und wie viel? Der Bedarf entsteht MIT dem Auftrag aus der
// Rezeptur, unabhängig von Eigen/Fremd oder Produktionsstatus. Zwei Fälle:
//  (a) Bulk/Fertigware (item.rezeptur_id gesetzt): Aufträge, deren (Auftrags-/Produkt-)Rezeptur = diese
//      Bulk-Rezeptur; Benötigt = Einheiten (Packungen × Stück je Packung).
//  (b) Rohstoff: Aufträge, deren Rezeptur diesen Rohstoff als Zutat enthält; Benötigt via
//      produktion_materialbedarf() (reine Rezeptur-Rechnung, auch Pulver nach Gewicht).
$resEinheit = (string)($it['einheit'] ?? '') ?: 'Stk';
$itemRezepturId = (int)($it['rezeptur_id'] ?? 0);   // >0 => Bulk-Item einer Rezeptur
$needJeAuftrag = [];
$res_auftraege = [];
if (!$neu && $itemRezepturId > 0) {
    foreach (all("SELECT a.id AS auftrag_id, a.nummer, k.firma AS kunde_firma,
                         COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt_name,
                         a.menge AS menge, COALESCE(NULLIF(a.stueck,0), NULLIF(p.einheiten_pro_packung,0), 1) AS stk
                  FROM auftrag a
                  JOIN produkt p ON p.id=a.produkt_id
                  LEFT JOIN kunden k ON k.id=a.kunde_id
                  WHERE a.status NOT IN ('versendet','storniert')
                    AND COALESCE(a.rezeptur_id, p.rezeptur_id) = ?
                  ORDER BY a.angelegt DESC", [$itemRezepturId]) as $ka) {
        $needJeAuftrag[(int)$ka['auftrag_id']] = (float)$ka['menge'] * (float)$ka['stk'];
        $res_auftraege[] = ['id'=>(int)$ka['auftrag_id'], 'nummer'=>$ka['nummer'], 'kunde_firma'=>$ka['kunde_firma'], 'produkt_name'=>$ka['produkt_name']];
    }
} elseif (!$neu) {
    $kand = all("SELECT a.id AS auftrag_id, a.nummer, k.firma AS kunde_firma,
                        COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt_name, MAX(pa.id) AS pa_id
                 FROM auftrag a
                 JOIN produkt p ON p.id=a.produkt_id
                 JOIN rezeptur_zutat z ON z.rezeptur_id = COALESCE(a.rezeptur_id, p.rezeptur_id) AND z.item_id = ?
                 LEFT JOIN produktionsauftrag pa ON pa.auftrag_id = a.id
                 LEFT JOIN kunden k ON k.id = a.kunde_id
                 WHERE a.status NOT IN ('versendet','storniert')
                 GROUP BY a.id, a.nummer, k.firma, produkt_name, a.angelegt
                 ORDER BY a.angelegt DESC", [(int)$id]);
    $GLOBALS['bx_stock_cache'] = [];   // Rezeptur/PA-Zeilen im Loop nur einmal holen
    foreach ($kand as $ka) {
        $need = 0.0;
        if ($ka['pa_id']) foreach (produktion_materialbedarf((int)$ka['pa_id']) as $m)
            if ((int)$m['item_id'] === (int)$id) $need += (float)$m['benoetigt'];
        $needJeAuftrag[(int)$ka['auftrag_id']] = $need;
        $res_auftraege[] = ['id'=>(int)$ka['auftrag_id'], 'nummer'=>$ka['nummer'], 'kunde_firma'=>$ka['kunde_firma'], 'produkt_name'=>$ka['produkt_name']];
    }
    unset($GLOBALS['bx_stock_cache']);
}
usort($res_auftraege, fn($x, $y) => ($needJeAuftrag[(int)$y['id']] ?? 0) <=> ($needJeAuftrag[(int)$x['id']] ?? 0));
$resNurBedarf = !empty($res_auftraege);
// Fallback: braucht kein offener Auftrag diesen Artikel -> alle Produktaufträge zeigen.
if (!$neu && !$resNurBedarf) {
    $res_auftraege = all("SELECT a.id, a.nummer, k.firma AS kunde_firma,
                          COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt_name
                          FROM auftrag a LEFT JOIN kunden k ON k.id=a.kunde_id
                          LEFT JOIN produkt p ON p.id=a.produkt_id
                          WHERE a.status NOT IN ('versendet','storniert')
                            AND a.produkt_id IS NOT NULL AND a.dienstleistung_id IS NULL
                          ORDER BY a.angelegt DESC");
}
$resNum = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
$fremd_gesamt = $neu ? 0 : item_fremdbestand((int)$id);
$fremdJeKunde = $neu ? [] : item_fremdbestand_je_kunde((int)$id);
$fremdJeEinheit = $neu ? [] : item_fremdbestand_je_einheit((int)$id);
$fremdKunden  = all("SELECT id, firma FROM kunden ORDER BY firma");
// Ins Fremdlager (Lager 2) nur für Fulfillment-Kunden umbuchen (bulkify-4-1-lager2, S-16).
$flKunden     = all("SELECT id, firma FROM kunden WHERE nutzt_fulfillment=1 ORDER BY firma");
if (!$neu) seed_lieferant_preis_if_empty();
$preise = $neu ? [] : all("SELECT lp.*, COALESCE(l.firma, lp.lieferant_name) AS firma, l.kuerzel AS lief_kuerzel FROM lieferant_preis lp LEFT JOIN lieferanten l ON l.id=lp.lieferant_id WHERE lp.item_id=? ORDER BY lp.preis ASC, lp.menge_ab ASC", [(int)$id]);
$preis_lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");

function bx_bald(string $modul): void {
    echo '<div class="bx-tablewrap"><table class="bx-table"><tbody><tr><td class="muted">'
       . 'Sobald das Modul <strong>' . h($modul) . '</strong> steht, erscheint hier automatisch, wo dieser Rohstoff verwendet wird.'
       . '</td></tr></tbody></table></div>';
}

render_header('rohstoffe', $neu ? 'Neuer Rohstoff' : $it['name']);
bx_head($neu ? 'Neuer Rohstoff' : $v('name'),
        $neu ? 'Item anlegen' : trim(($v('artikelnummer') ? $v('artikelnummer').' · ' : '') . item_warenart_label($it) . ($v('name_lat') ? ' · '.$v('name_lat') : '')),
        bx_btn('Zurück zur Liste', '?p=rohstoffe', 'ghost'));

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.'
    . (isset($_GET['coacharge']) ? ' Aus der CoA wurde eine Charge angelegt (noch ohne Ware) – bei der Warenannahme wird sie über die Chargennummer abgeglichen und eingebucht.' : '')
    . (isset($_GET['kiauto']) ? ' Die KI hat die Datei ausgelesen: Kennwerte, Wirkstoffe und Grenzwerte wurden ergänzt. Die <strong>Stammdaten-Vorschläge</strong> (Name, CAS, Herkunft, Allergene …) stehen unten im Reiter <strong>Spezifikation</strong> – dort prüfen und auf <strong>„Übernehmen"</strong> klicken.' : '')
    . '</div>';
if (isset($_GET['kioff'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Datei gespeichert, aber die KI konnte sie nicht auslesen (KI nicht aktiv oder ein Scan ohne Textebene). Werte bitte von Hand erfassen.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';
if (isset($_GET['flok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Ins Fremdlager umgebucht – die Menge zählt jetzt zum Kundenbestand, nicht mehr zu unserem.</div>';
if (isset($_GET['merged'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Zusammengeführt – Chargen und Reservierungen des Doppel-Artikels sind jetzt hier. Der Doppel-Artikel wurde deaktiviert.</div>';
if (isset($_GET['flfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['flfehler']) . '</div>';
if (isset($_GET['leerkapsel'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Als Leerkapsel eingestuft. Kapselgröße, Material, Farbe und Leergewicht stehen unten in den Stammdaten.</div>';
// Leerkapsel-Check: sieht nach Kapselhüllen aus (Name oder gespeicherte Kapselgröße), Form ist aber nicht Kapselhülle -> Felder sind ausgeblendet.
if (!$neu && ($it['kategorie'] ?? '') === 'rohstoff' && ($it['form'] ?? '') !== 'kapselhuelle'
    && ((int)($it['kapselgroesse_id'] ?? 0) > 0 || name_ist_leerkapsel((string)$it['name']))) {
    $kgVor = (int)($it['kapselgroesse_id'] ?? 0) ?: kapselgroesse_aus_name((string)$it['name']);
    echo '<div class="bx-panel" style="border-left:3px solid var(--warn);padding:12px 16px"><div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">'
       . '<div>Dieser Rohstoff sieht nach <strong>Leerkapseln</strong> aus, ist aber nicht als Leerkapsel eingestuft. Deshalb fehlen Kapselgröße, Material und Leergewicht.'
       . ($kgVor ? ' Erkannte Größe: <strong>' . h((string) scalar("SELECT name FROM kapselgroesse WHERE id=?", [$kgVor])) . '</strong>.' : '') . '</div>'
       . '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="als_leerkapsel"><button class="btn btn-primary btn-sm" type="submit">Als Leerkapsel einstufen</button></form></div></div>';
}
if (!$neu && ($it['form'] ?? '') === 'kapselhuelle' && empty($it['kapselgroesse_id']))
    echo '<div class="bx-panel" style="border-left:3px solid var(--warn);padding:12px 16px">Leerkapsel ohne <strong>Kapselgröße</strong> – bitte unten in den Stammdaten wählen. Ohne Größe ordnet das System die Kapsel keinem Produkt zu.</div>';

if (!$neu) {
    $pr = (float)$it['ek_preis'] > 0 && (float)$it['ek_preis'] < 0.01 ? rtrim(rtrim(number_format((float)$it['ek_preis'], 6, ',', '.'), '0'), ',') : number_format((float)$it['ek_preis'], (float)$it['ek_preis'] < 1 ? 4 : 2, ',', '.');
    echo '<div class="bx-cards">';
    echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . ($gesperrt ? bx_badge('gesperrt','err') : bx_badge('aktiv','ok')) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Warenart</div><div class="v">' . h(($it['form'] ?? '') === 'kapselhuelle' ? 'Leerkapsel' : item_warenart_label($it)) . '</div></div>';
    if (($it['form'] ?? '') === 'kapselhuelle')
        echo '<div class="bx-card"><div class="k">Kapselgröße</div><div class="v">' . (!empty($it['kapselgroesse_id']) ? h((string) scalar("SELECT name FROM kapselgroesse WHERE id=?", [(int)$it['kapselgroesse_id']])) : '<span style="color:var(--warn)">fehlt</span>') . '</div></div>';
    if (!empty($it['fremdprodukt']) && (int)($it['fremd_besitzer_id'] ?? 0) > 0)
        echo '<div class="bx-card"><div class="k">Besitzer (Fremdprodukt)</div><div class="v">' . h((string) scalar("SELECT firma FROM kunden WHERE id=?", [(int)$it['fremd_besitzer_id']])) . '</div></div>';
    echo '<div class="bx-card"><div class="k">EK-Preis</div><div class="v">' . $pr . ' €/' . h((string)($it['preis_bezug'] ?: ($it['einheit'] ?: 'Stk'))) . '</div></div>';
    if (($it['kategorie'] ?? '') === 'rohstoff') {
        if (!empty($it['preis_schwankt'])) echo '<div class="bx-card"><div class="k">Preis schwankt</div><div class="v" style="font-size:16px;color:#8a6d1f">' . (int)($it['preis_gueltig_tage'] ?: 14) . ' Tage gültig</div></div>';
        $moqK = rohstoff_moq((int)$id);
        echo '<div class="bx-card"' . ($moqK && $moqK['notiz'] !== '' ? ' title="' . h($moqK['notiz']) . '"' : '') . '><div class="k">MOQ (unsere)</div><div class="v">'
           . ($moqK ? h(moq_wert($moqK)) : '<span class="muted">keine</span>') . '</div></div>';
    }
    echo '<div class="bx-card"><div class="k">Bestand (frei)</div><div class="v">' . $bestandWert($bestand_frei_je) . '</div></div>';
    if ($einheiten_gemischt && $bestand_qua_je) echo '<div class="bx-card"><div class="k">Quarantäne</div><div class="v" style="color:var(--warn)">' . $bestandWert($bestand_qua_je) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Reserviert</div><div class="v"' . ($reserviert_gesamt > $bestand_frei + 1e-9 ? ' style="color:var(--warn)"' : '') . '>' . ($reserviert_gesamt>0 ? h(rtrim(rtrim(number_format($reserviert_gesamt,3,',','.'),'0'),',')).' '.h($it['einheit']) : '<span class="muted">0</span>') . '</div></div>';
    if ($fremd_gesamt > 0) echo '<div class="bx-card"><div class="k">Fremdlager (Kunden)</div><div class="v">' . $bestandWert($fremdJeEinheit) . '</div></div>';
    if (($it['kategorie'] ?? '') === 'rohstoff') {
        $nfm = novelfood_status_meta($it['novelfood_status'] ?? null);
        echo '<div class="bx-card"><div class="k">Novel Food</div><div class="v" style="font-size:15px;color:' . $nfm['farbe'] . '">' . h($nfm['label']) . '</div></div>';
    }
    echo '</div>';
}
?>
<?php // Novel-Food-Status (automatisch aus dem EU-Katalog) – mit Prüfdatum als Snapshot fürs PIB.
if (!$neu && ($it['kategorie'] ?? '') === 'rohstoff'):
    if (isset($_GET['nfok'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">Novel-Food-Status neu geprüft.</div>';
    $nfm = novelfood_status_meta($it['novelfood_status'] ?? null);
    $nfTreffer = json_decode((string)($it['novelfood_treffer'] ?? ''), true) ?: [];
    $nfDatum = $it['novelfood_geprueft_am'] ?? null;
?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:10px">
    <h2 style="margin:0">Novel-Food-Status <?= bx_hint('Automatischer Abgleich des Rohstoffs mit dem EU-Novel-Food-Katalog. Keine Rechtsberatung – eine Einschätzung. Der Status wird mit Datum gespeichert, damit auch später nachvollziehbar ist, was zum Prüfzeitpunkt galt (z. B. für das PIB).') ?></h2>
    <form method="post" style="margin:0"><input type="hidden" name="aktion" value="nf_pruefen"><button class="btn btn-ghost btn-sm" type="submit">Jetzt prüfen / aktualisieren</button></form>
  </div>
  <div style="margin-top:8px;font-size:16px;color:<?= $nfm['farbe'] ?>"><strong><?= h($nfm['label']) ?></strong></div>
  <div class="muted" style="font-size:12px;margin-top:2px">
    <?= $nfDatum ? 'Geprüft am ' . h(fmt_zeit($nfDatum, 'd.m.Y')) . ' gegen den damaligen EU-Katalog.' : 'Noch nicht geprüft – auf „Jetzt prüfen" klicken.' ?>
  </div>
  <?php if ($nfTreffer): ?>
    <div class="muted" style="font-size:13px;margin-top:10px">Katalog-Treffer:</div>
    <ul style="margin:4px 0 0;padding-left:18px">
      <?php foreach ($nfTreffer as $t): ?><li><strong><?= h((string)($t['stoff'] ?? '')) ?></strong> – <?= h((string)($t['status'] ?? '')) ?></li><?php endforeach; ?>
    </ul>
  <?php elseif (($it['novelfood_status'] ?? '') === 'konform'): ?>
    <div class="muted" style="font-size:13px;margin-top:8px">Kein Treffer im Novel-Food-Katalog – gilt als nicht-neuartig (konform).</div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php // KI-Info: Kurzinfo zum Rohstoff, NUR auf Knopfdruck erzeugt. Wird Team + Kunde (Portal) als „KI-Info" gezeigt.
if (!$neu && ($it['kategorie'] ?? '') === 'rohstoff'):
    if (isset($_GET['kiok']))  echo '<div class="bx-panel badge-ok" style="padding:10px 14px">KI-Kurzinfo erzeugt und gespeichert.</div>';
    if (isset($_GET['kidel'])) echo '<div class="bx-panel badge-ok" style="padding:10px 14px">KI-Kurzinfo gelöscht.</div>';
    if (isset($_GET['kierr'])) echo '<div class="bx-panel" style="border-color:var(--warn);border-left:3px solid var(--warn);padding:10px 14px">KI-Kurzinfo nicht möglich: ' . h((string)$_GET['kierr']) . '</div>';
    $kiInfo = trim((string)($it['ki_info'] ?? ''));
?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:10px">
    <h2 style="margin:0">KI-Info <?= bx_hint('Von der KI erzeugte, neutrale Kurzinfo zum Rohstoff (was ist das, wofür wird es eingesetzt). Nur auf Knopfdruck. Wird dir und dem Kunden im Portal als „KI-Info" angezeigt. Keine gesundheitsbezogenen Wirkversprechen – bitte vor der Kundenfreigabe kurz prüfen.') ?></h2>
    <div class="bx-row" style="gap:8px;margin:0">
      <form method="post" style="margin:0" onsubmit="return confirm('KI-Kurzinfo <?= $kiInfo !== '' ? 'neu ' : '' ?>erzeugen? Fragt die KI (kann ein paar Sekunden dauern).');">
        <input type="hidden" name="aktion" value="ki_info">
        <button class="btn <?= $kiInfo === '' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit" data-busy="KI schreibt&#8230;"><?= $kiInfo === '' ? 'KI-Info erzeugen' : 'neu erzeugen' ?></button>
      </form>
      <?php if ($kiInfo !== ''): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('KI-Info löschen? Dann wird sie dir und dem Kunden nicht mehr angezeigt.');">
        <input type="hidden" name="aktion" value="ki_info_del"><button class="btn btn-ghost btn-sm" type="submit">löschen</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($kiInfo !== ''): ?>
    <div style="margin-top:10px;white-space:pre-line;line-height:1.5"><?= h($kiInfo) ?></div>
    <div class="muted" style="font-size:12px;margin-top:8px">Erzeugt am <?= $it['ki_info_am'] ? h(fmt_zeit($it['ki_info_am'], 'd.m.Y H:i')) : '–' ?> · wird dem Kunden im Portal als „KI-Info" angezeigt.</div>
  <?php elseif (!ki_bereit()): ?>
    <div class="muted" style="margin-top:8px">Die KI ist nicht eingerichtet (Einstellungen &rarr; KI).</div>
  <?php else: ?>
    <div class="muted" style="margin-top:8px">Noch keine KI-Info. Auf „KI-Info erzeugen" klicken – die KI schreibt eine kurze, neutrale Beschreibung, die gespeichert und dem Kunden gezeigt wird.</div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php // KI-Steckbrief (Nico 11.11.2026): ausführlich, nur intern - nie im Portal.
if (!$neu && ($it['kategorie'] ?? '') === 'rohstoff') {
    require_once BX_ROOT . '/core/rohstoff_steckbrief.php';
    rohstoff_steckbrief_block((int)$id, '?p=rohstoff&id=' . (int)$id);
} ?>
<?php // Beim Anlegen sofort sichtbar – und ausserhalb des Formulars, weil es ein eigenes hat. ?>
  <?php // Beim Anlegen: aus einer Spezifikation heraus starten. Spart das Abtippen und ist der
        // Weg, über den jeder Rohstoff von Anfang an Papiere hat.
        if ($neu): require_once BX_ROOT . '/core/spec_ki.php'; ?>
  <div class="bx-panel" style="border-color:var(--gruen)"><h2 style="margin-top:0">Rohstoff aus Spezifikation oder CoA anlegen <span class="muted" style="font-weight:400;font-size:13px">– jede Sprache (z. B. Chinesisch), wird automatisch ins Deutsche übersetzt</span></h2>
    <?php if ($neuKiFehler !== ''): ?><div style="border:1px solid #e6c4c0;color:#8f231b;padding:8px 12px;margin-bottom:10px;border-radius:8px"><?= h($neuKiFehler) ?></div><?php endif; ?>
    <?php if ($neuKi): $anz = count((array)($neuKi['stamm'] ?? []));
          $anzW = count((array)($neuKi['wirkstoffe'] ?? [])); $anzK = count((array)($neuKi['kennwerte'] ?? [])); ?>
      <div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">
        <strong><?= h((string)($_SESSION['rohstoff_ki']['orig'] ?? '')) ?></strong> ausgelesen –
        <?= (int)$anz ?> Stammfeld(er)<?php
          if ($anzW) echo ', ' . (int)$anzW . ' Wirkstoff(e)';
          if ($anzK) echo ', ' . (int)$anzK . ' Kennwert(e)';
          if ($neuKi['werte'] ?? []) echo ', ' . count($neuKi['werte']) . ' Analysenwerte';
        ?> unten eingetragen. Bitte prüfen (Reiter „Wirkstoff &amp; Qualität" und „Spezifikation") und dann speichern.
      </div>
      <?php if ($casVorschlag !== ''): ?>
        <div class="bx-panel" style="border-color:#d8c48a;background:#fbf6e6;color:#6b571e;padding:8px 12px;margin-bottom:10px">
          CAS-Nummer <strong><?= h($casVorschlag) ?></strong> stand nicht im Dokument – von der KI aus Fachwissen vorgeschlagen. Bitte vor dem Speichern prüfen (Reiter „Stammdaten").
        </div>
      <?php endif; ?>
      <div class="bx-row" style="gap:10px;align-items:center">
        <?= bx_badge('erkannt als ' . ['spec'=>'Spezifikation','coa'=>'Analysenzertifikat','beides'=>'Spezifikation + CoA','unklar'=>'unklar'][$neuKi['typ']], $neuKi['typ'] === 'unklar' ? 'warn' : 'info') ?>
        <?= bx_badge('Sicherheit ' . $neuKi['sicherheit'], $neuKi['sicherheit'] === 'hoch' ? 'ok' : ($neuKi['sicherheit'] === 'niedrig' ? 'warn' : 'info')) ?>
        <form method="post" style="margin:0"><input type="hidden" name="aktion" value="spec_neu_weg">
          <button class="btn btn-ghost btn-sm" type="submit">Vorschlag verwerfen</button></form>
      </div>
      <?php foreach ((array)($neuKi['hinweise'] ?? []) as $hw): ?><div class="muted" style="font-size:12px;margin-top:6px">Hinweis: <?= h($hw) ?></div><?php endforeach; ?>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Die Datei wird beim Speichern am Rohstoff abgelegt. Erkannte Analysenwerte kannst du danach im Reiter Spezifikation an eine Charge übernehmen.</p>
    <?php elseif (!ki_bereit()): ?>
      <div class="muted">Die KI ist nicht eingerichtet (Einstellungen &rarr; KI). Lege den Rohstoff von Hand an.</div>
    <?php else: ?>
      <p class="muted" style="margin-top:0">Lade die Spezifikation oder das CoA des Lieferanten hoch – auch als Scan. Die Felder unten werden daraus vorbelegt, du prüfst sie und speicherst.</p>
      <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="spec_neu">
        <div class="bx-field" style="margin:0"><label>Unterlage</label>
          <input type="file" name="neu_spec" required accept="application/pdf,image/*"></div>
        <button class="btn btn-primary" type="submit" data-busy="Wird ausgelesen&#8230;">Auslesen und Felder füllen</button>
        <span class="muted" style="font-size:12px;align-self:center">dauert 10–60 Sekunden</span>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>


<form method="post" class="bx-form" enctype="multipart/form-data">
  <input type="hidden" name="active_tab" id="activeTab" value="<?= h(preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? 'stamm')) ?: 'stamm') ?>">
  <div class="settabs" id="itabs">
    <a href="#" class="on" data-tab="stamm">Stammdaten</a>
    <a href="#" data-tab="quali">Wirkstoff &amp; Qualität</a>
    <a href="#" data-tab="spec">Spezifikation</a>
    <a href="#" data-tab="dok">Dokumente (CoA/Spec)</a>
    <a href="#" data-tab="web">Website</a>
    <a href="#" data-tab="ek">Einkauf</a>
    <?php if (!$neu):
        // Reiter „Bestellt“ (Nico 05.11.2026): alle Bestellpositionen dieses Artikels, offene zuerst – „wurde schon bestellt?“
        $bstRows = all("SELECT bp.id, bp.menge, bp.einheit, COALESCE(bp.geliefert_menge,0) AS geliefert, bp.ek_preis, bp.auftrag_id,
                               b.id AS bid, b.nummer, b.status, b.bestelldatum, b.angelegt, b.eta_geplant, b.angekommen_am, b.bestellart, b.online_haendler,
                               COALESCE(l.firma,'') AS lieferant, a.nummer AS auftrag_nr,
                               (b.status NOT IN ('geliefert','storniert') AND b.angekommen_am IS NULL) AS offen
                        FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                        LEFT JOIN lieferanten l ON l.id=b.lieferant_id LEFT JOIN auftrag a ON a.id=bp.auftrag_id
                        WHERE bp.item_id=? ORDER BY offen DESC, COALESCE(b.bestelldatum, DATE(b.angelegt)) DESC, b.id DESC LIMIT 200", [(int)$id]);
        $bstOffen = count(array_filter($bstRows, fn($r) => (int)$r['offen'] === 1 && (string)$r['status'] !== 'offen')); ?>
    <a href="#" data-tab="bestellt">Bestellt<?= $bstOffen ? ' (' . $bstOffen . ')' : '' ?></a>
    <a href="#" data-tab="lager">Lager</a>
    <a href="#" data-tab="verw">Verwendung</a>
    <a href="#" data-tab="verlauf">Verlauf</a>
    <?php endif; ?>
  </div>

  <section data-panel="stamm">
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Artikelnummer <?= bx_hint('leer lassen = wird automatisch vergeben (R-/VP-/FP-… je Kategorie)') ?></label><input type="text" name="artikelnummer" value="<?= $v('artikelnummer') ?>" placeholder="<?= $neu ? 'automatisch' : '' ?>"></div>
      <?php if (!$neu && ($it['kategorie'] ?? '') === 'rohstoff'):
          $hauptKuerzel = (int)($it['haupt_lieferant_id'] ?? 0) ? (string) scalar("SELECT kuerzel FROM lieferanten WHERE id=?", [(int)$it['haupt_lieferant_id']]) : '';
          $liefKennung = rohstoff_lief_kennung($it['artikelnummer'] ?? '', $hauptKuerzel); ?>
      <div class="bx-field"><label>Lieferanten-Kennung (Hauptlieferant) <?= bx_hint('Artikelnummer (R-Nummer) + Kürzel des Hauptlieferanten (z. B. R-12345BX). So lässt sich derselbe Rohstoff je Lieferant unterscheiden. Das Kürzel wird am Lieferanten gepflegt. Weitere Lieferanten mit Kennung siehst du im Reiter „Einkauf".') ?></label><input type="text" value="<?= h($liefKennung ?: '–') ?>" readonly style="background:var(--panel-2)"></div>
      <?php endif; ?>
      <div class="bx-field"><label>Name (deutsch)</label><input type="text" name="name" value="<?= $v('name') ?>" required></div>
      <?php if (!$neu): $anzN = rohstoff_anzeige_name($it); if ($anzN !== '' && $anzN !== trim((string)($it['name'] ?? ''))): ?>
      <div class="bx-field"><label>Anzeigename <?= bx_hint('So erscheint der Rohstoff im Angebot, Portal und auf der Spezifikation – aus Name + Beschaffenheit + Verhältnis zusammengesetzt.') ?></label><input type="text" value="<?= h($anzN) ?>" readonly style="background:var(--panel-2)"></div>
      <?php endif; endif; ?>
      <div class="bx-field"><label>Name (englisch)</label><input type="text" name="name_en" value="<?= $v('name_en') ?>"></div>
      <div class="bx-field"><label>Lateinischer Name <?= bx_hint('botanischer/pharmazeutischer Name, z. B. Withania somnifera') ?></label><input type="text" name="name_lat" value="<?= $v('name_lat') ?>"></div>
      <div class="bx-field"><label>CAS-Nummer <?= bx_hint('eindeutige Stoff-Nummer, z. B. Ascorbinsäure 50-81-7') ?></label><input type="text" name="cas" value="<?= $v('cas') ?>" placeholder="z. B. 50-81-7"></div>
      <?php if ($neu || ($it['kategorie'] ?? '') === 'rohstoff'): $moqIt = $neu ? null : rohstoff_moq((int)$id); ?>
      <div class="bx-field"><label>MOQ (unsere Mindestmenge) <?= bx_hint('Kleinste Menge, die wir von diesem Rohstoff an Kunden verkaufen, z. B. 25 kg wegen Gebinde. Unabhängig von der Mindestmenge des Lieferanten. Im Kundenportal kann der Kunde darunter nicht anfragen; im Dashboard ist es nur ein Hinweis. Leer = keine MOQ.') ?></label>
        <div class="bx-row" style="gap:6px;flex-wrap:nowrap;margin:0">
          <input type="text" name="moq_menge" inputmode="decimal" value="<?= $moqIt ? h(moq_zahl($moqIt['menge'])) : '' ?>" placeholder="z. B. 25" style="width:110px">
          <select name="moq_einheit" style="width:auto"><?php foreach (moq_einheiten() as $me): ?><option value="<?= $me ?>"<?= ($moqIt['einheit'] ?? 'kg') === $me ? ' selected' : '' ?>><?= $me ?></option><?php endforeach; ?></select>
        </div></div>
      <div class="bx-field"><label>Preis schwankt <?= bx_hint('Für Rohstoffe mit kurzlebigen Preisen (z. B. Whey). Dann wird „gültig bis" beim Eintragen mit heute + Standard-Gültigkeit vorbelegt, ist im Lieferantenportal Pflicht, und die Preisanfrage zeigt den Hinweis „Preis schwankt - bitte Gültigkeit angeben".') ?></label>
        <input type="hidden" name="preis_gueltig_tage_feld" value="1">
        <div class="bx-row" style="gap:8px;align-items:center;flex-wrap:nowrap;margin:0">
          <label class="bx-check" style="margin:0"><input type="checkbox" name="preis_schwankt" value="1"<?= !empty($it['preis_schwankt']) ? ' checked' : '' ?>> ja</label>
          <input type="number" name="preis_gueltig_tage" min="1" max="365" value="<?= h((string)($it['preis_gueltig_tage'] ?? '')) ?>" placeholder="14" style="width:80px"> <span class="muted">Tage gültig</span>
        </div></div>
      <div class="bx-field"><label>MOQ-Notiz <span class="muted">(optional)</span></label><input type="text" name="moq_notiz" maxlength="190" value="<?= h((string)($moqIt['notiz'] ?? '')) ?>" placeholder="z. B. wegen Gebinde 25 kg"></div>
      <?php endif; ?>
      <div class="bx-field"><label>Kategorie</label>
        <select name="kategorie">
          <?php foreach ($KAT as $key=>$lbl): ?>
            <option value="<?= $key ?>" <?= ($it['kategorie']??'')===$key?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php
        // Fremdprodukt: Kundenware (fertiges Produkt von außerhalb), wir lagern & versenden nur -> Fremdlager (Lager 2).
        $fpChecked = $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['fremdprodukt']) : !empty($it['fremdprodukt']);
        $fbSel     = (int)($_POST['fremd_besitzer_id'] ?? ($it['fremd_besitzer_id'] ?? 0)); ?>
      <div class="bx-field" style="grid-column:1/-1">
        <label style="display:flex;gap:8px;align-items:center;margin:0 0 6px;font-weight:600">
          <input type="checkbox" name="fremdprodukt" id="fp_toggle" value="1" <?= $fpChecked ? 'checked' : '' ?>>
          Fremdprodukt – Kundenware (kommt von außerhalb, wir lagern &amp; versenden nur)
        </label>
        <div id="fp_owner" style="<?= $fpChecked ? '' : 'display:none' ?>">
          <label>Besitzer-Kunde</label>
          <select name="fremd_besitzer_id" class="rscombo">
            <option value="">– Kunde wählen –</option>
            <?php foreach ($fremdKunden as $kk): ?><option value="<?= (int)$kk['id'] ?>" <?= $fbSel === (int)$kk['id'] ? 'selected' : '' ?>><?= h($kk['firma']) ?></option><?php endforeach; ?>
          </select>
          <p class="muted" style="font-size:12px;margin:6px 0 0">Gilt als <strong>Fertigprodukt</strong> und gehört dem Kunden: der Bestand wird ins <strong>Fremdlager (Lager 2)</strong> des Kunden gebucht – zählt nicht zu unserem Bestand und nicht für Produktion/Angebote/Einkauf. Beim Speichern wird vorhandener Bestand dieses Artikels dem Kunden zugeordnet.</p>
        </div>
      </div>
      <script>(function(){var t=document.getElementById('fp_toggle'),o=document.getElementById('fp_owner');if(t&&o)t.addEventListener('change',function(){o.style.display=t.checked?'':'none';});})();</script>
      <div class="bx-field"><label>Art <?= bx_hint('Stoffklasse für die Filterung (intern + Kundenportal): Vitamine, Mineralstoffe, Pflanzenstoffe, Aminosäuren …') ?></label>
        <select name="art">
          <option value="">– keine –</option>
          <?php foreach (rohstoff_art_optionen() as $key=>$lbl): ?>
            <option value="<?= h($key) ?>" <?= ($it['art']??'')===$key?'selected':'' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Form <?= bx_hint('physikalische Form – steuert später die Rohstoffauswahl je Rezeptur (flüssiges Rezept → flüssige Rohstoffe zuerst). „Kapselhülle" = leere Kapseln vom Lieferanten.') ?></label>
        <select name="form" id="f_form">
          <?php if (($it['form'] ?? '') === ''): ?><option value="" selected>– nicht festgelegt –</option><?php endif; ?>
          <?php foreach ($FORM as $key=>$lbl): ?>
            <option value="<?= $key ?>" <?= ($it['form']??'')===$key?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Beschaffenheit <?= bx_hint('Was der Rohstoff IST: Extrakt, reines/natives Pulver, Isolat … Getrennt von der physischen Form und der Spezifikation. Fließt in den Anzeigenamen ein (z. B. „… Extrakt 10:1“).') ?></label>
        <select name="beschaffenheit">
          <option value="">– keine –</option>
          <?php foreach (rohstoff_beschaffenheit_optionen() as $key=>$lbl): ?>
            <option value="<?= h($key) ?>" <?= ($it['beschaffenheit']??'')===$key?'selected':'' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Extraktverhältnis (DEV) <?= bx_hint('Droge-Extrakt-Verhältnis, nur bei Extrakten – z. B. 1:10, 10:1, 4:1. Erscheint im Anzeigenamen.') ?></label>
        <input type="text" name="dev" value="<?= $v('dev') ?>" placeholder="z. B. 10:1"></div>
      <div class="bx-field" data-only="kapselhuelle"><label>Kapselgröße</label>
        <select name="kapselgroesse_id">
          <option value="">– wählen –</option>
          <?php foreach ($KAPSELN as $kg): ?>
            <option value="<?= (int)$kg['id'] ?>" <?= (int)($it['kapselgroesse_id']??0)===(int)$kg['id']?'selected':'' ?>><?= h($kg['name']) ?> (bis <?= (int)$kg['fuellmenge_mg'] ?> mg)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" data-only="kapselhuelle"><label>Material <?= bx_hint('Hülle: HPMC (pflanzlich), Gelatine (tierisch), Pullulan') ?></label>
        <input type="text" name="material" value="<?= $v('material') ?>" list="kapselmaterial" placeholder="z. B. HPMC">
        <datalist id="kapselmaterial"><option value="HPMC"><option value="Gelatine"><option value="Pullulan"></datalist>
      </div>
      <div class="bx-field" data-only="kapselhuelle"><label>Farbe</label><input type="text" name="farbe" value="<?= $v('farbe') ?>" placeholder="z. B. transparent, weiß"></div>
      <div class="bx-field" data-only="kapselhuelle"><label>Leergewicht (mg) <?= bx_hint('Gewicht der leeren Hülle – zählt zum Bruttogewicht, nicht zum Wirkstoff') ?></label><input type="number" step="0.01" name="leergewicht_mg" value="<?= $v('leergewicht_mg') ?>"></div>
      <div class="bx-field"><label>Basiseinheit</label>
        <select name="einheit" <?= $neu ? '' : 'disabled' ?>>
          <?php foreach (['kg'=>'kg','g'=>'g','Stk'=>'Stk','L'=>'Liter','ml'=>'ml'] as $key=>$lbl): ?>
            <option value="<?= $key ?>" <?= ($it['einheit']??'')===$key?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$neu): ?><div class="muted" style="font-size:12px;margin-top:4px">Einheit nur über „Einheit ändern" (unten im Reiter Lager) – damit Artikel und Chargen nie vermischt werden.</div><?php endif; ?>
      </div>
      <div class="bx-field"><label>Rohstoff sperren <?= bx_hint('gesperrte Rohstoffe können nicht in neue Rezepturen aufgenommen werden') ?></label>
        <div class="bx-check" style="padding-top:8px">
          <input type="checkbox" name="gesperrt" id="f_gesperrt" value="1" <?= $gesperrt?'checked':'' ?>>
          <label for="f_gesperrt" style="margin:0">Rohstoff ist gesperrt</label>
        </div>
      </div>
    </div>
    <div class="bx-field"><label>Notiz (intern)</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
    </div>
  </section>

  <section data-panel="quali" hidden>
    <div class="bx-panel">
      <h2>Wirkstoffe <?= bx_hint('was im Rohstoff steckt – Basis für die Deklaration (davon … mg, % NRV). Auswahl aus der Nährstoffliste oder neuen Namen eintippen. Mehrere möglich.') ?></h2>
      <?php
        // Gehalt-Einheiten (zukunftssicher): % (m/m) + potenzbasierte Formen (I.E./g usw.) für Vitamine.
        $GEINH = ['prozent'=>'% (m/m)','ie_g'=>'I.E./g','ie_kg'=>'I.E./kg','mg_g'=>'mg/g','ug_g'=>'µg/g'];
        $einhSelect = function($sel) use ($GEINH) {
            $o = '';
            foreach ($GEINH as $k=>$lbl) $o .= '<option value="'.$k.'"'.($sel===$k?' selected':'').'>'.$lbl.'</option>';
            return $o;
        };
      ?>
      <div id="wirkrows">
        <?php $wrows = $wirkstoffe ?: [['n_name'=>'','gehalt_wert'=>'','gehalt_einheit'=>'prozent']];
              foreach ($wrows as $w):
                $gwert = $w['gehalt_wert'] ?? ($w['gehalt_prozent'] ?? '');
                $geh   = $w['gehalt_einheit'] ?? 'prozent'; ?>
        <div class="bx-row wirkrow" style="flex-wrap:nowrap;margin-bottom:8px">
          <input type="text" name="wirk_name[]" value="<?= h($w['n_name']) ?>" list="naehrstoffliste" placeholder="Wirkstoff, z. B. Magnesium" style="flex:1">
          <input type="number" step="any" name="wirk_gehalt[]" value="<?= h((string)$gwert) ?>" placeholder="Gehalt" style="width:110px">
          <select name="wirk_einheit[]" style="width:110px"><?= $einhSelect($geh) ?></select>
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.wirkrow').remove()">entfernen</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="addWirk">+ Wirkstoff</button>
      <datalist id="naehrstoffliste">
        <?php foreach ($naehrstoffe as $n): ?><option value="<?= h($n['name']) ?>"><?php endforeach; ?>
      </datalist>
      <div class="muted" style="margin-top:8px">Gehalt = Anteil bzw. Wirkstärke im Rohstoff. Meist <strong>% (m/m)</strong> (z. B. Magnesiumcitrat = 16 % Magnesium). Bei Vitaminen, die in Internationalen Einheiten geliefert werden, stattdessen <strong>I.E./g</strong> wählen (z. B. Vitamin D3 100.000 I.E./g) – das System rechnet die I.E. über den am Nährstoff hinterlegten Faktor automatisch in µg/mg für die Deklaration um.</div>
    </div>

    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Dichte (g/ml) <?= bx_hint('Schüttdichte – bestimmt, wie viel in eine Kapsel passt') ?></label><input type="number" step="0.001" name="dichte" value="<?= $v('dichte') ?>"></div>
      <div class="bx-field"><label>Standard-Overage / Verlust (%) <?= bx_hint('Zuschlag/Schwund, der beim Einsatz einkalkuliert wird') ?></label><input type="number" step="0.01" name="overage_prozent" value="<?= $v('overage_prozent') ?>"></div>
    </div>
    <div class="bx-field"><label>Allergene <?= bx_hint('z. B. Gluten, Soja, Laktose – erscheint später auf Etikett/Deklaration') ?></label><input type="text" name="allergene" value="<?= $v('allergene') ?>" placeholder="kommagetrennt, oder „keine"></div>
    </div>
  </section>

  <section data-panel="spec" hidden>
    <div class="bx-panel"><h2>Identität</h2><div class="bx-grid">
      <div class="bx-field"><label>Synonym / RM-Nr</label><input type="text" name="synonym" value="<?= $v('synonym') ?>" placeholder="z. B. RM940"></div>
      <div class="bx-field"><label>EC-Nummer</label><input type="text" name="ec_nr" value="<?= $v('ec_nr') ?>"></div>
      <div class="bx-field"><label>Botanische Quelle / Pflanzenteil</label><input type="text" name="bot_quelle" value="<?= $v('bot_quelle') ?>" placeholder="z. B. Theobroma cacao – Bohne"></div>
      <div class="bx-field"><label>Herkunftsland / Herkunft <?= bx_hint('Land oder Region (EU) des Rohstoffs – erscheint in der Spezifikation') ?></label>
        <?php
        $LAENDER = ['EU','Deutschland','Österreich','Schweiz','Frankreich','Italien','Spanien','Niederlande','Belgien','Polen','Vereinigtes Königreich','Dänemark','Schweden','USA','Kanada','China','Indien','Japan','Südkorea','Vietnam','Thailand','Indonesien','Malaysia','Türkei','Brasilien','Marokko','Ägypten'];
        $hl = (string)($it['herkunftsland'] ?? '');
        ?>
        <select name="herkunftsland">
          <option value="">– bitte wählen –</option>
          <?php if ($hl !== '' && !in_array($hl, $LAENDER, true)): ?><option value="<?= h($hl) ?>" selected><?= h($hl) ?> (bestehend)</option><?php endif; ?>
          <?php foreach ($LAENDER as $ld): ?><option value="<?= h($ld) ?>" <?= $hl === $ld ? 'selected' : '' ?>><?= h($ld) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="muted">Name (lat.) und CAS-Nr. stehen im Reiter „Stammdaten".</div>
    </div>

    <div class="bx-panel">
      <h2>Charakteristische Kennwerte <?= bx_hint('das Unterscheidende – z. B. Fett 10–12 %, pH 7,5, Wirkstoff ≥ 95 %. Die Reinheits-Grenzwerte (Schwermetalle/Mikro/Mykotoxine) bleiben im PDF.') ?></h2>
      <div id="kwrows">
        <?php $krows = $kennwerte ?: [['parameter'=>'','wert'=>'']]; foreach ($krows as $kwr): ?>
        <div class="bx-row kwrow" style="flex-wrap:nowrap;margin-bottom:8px">
          <input type="text" name="kw_param[]" value="<?= h($kwr['parameter']) ?>" placeholder="Parameter, z. B. Fettgehalt" style="flex:1">
          <input type="text" name="kw_wert[]" value="<?= h($kwr['wert']) ?>" placeholder="Wert, z. B. 10–12 %" style="flex:1">
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.kwrow').remove()">entfernen</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="addKw">+ Kennwert</button>
    </div>

    <div class="bx-panel">
      <h2>Grenzwerte (Reinheit / Mikrobiologie) <?= bx_hint('dauerhafte Sollwerte am Rohstoff: Schwermetalle, Keimbelastung, Mykotoxine … aus der Spezifikation. Jede neue Charge (CoA) wird dagegen abgeglichen. Aus einer CoA werden diese automatisch vorbelegt.') ?></h2>
      <div id="gwrows">
        <?php $grows = $grenzwerte ?: [['parameter'=>'','grenzwert'=>'']]; foreach ($grows as $gwr): ?>
        <div class="bx-row gwrow" style="flex-wrap:nowrap;margin-bottom:8px">
          <input type="text" name="gw_param[]" value="<?= h($gwr['parameter'] ?? '') ?>" placeholder="Parameter, z. B. Blei (Pb)" style="flex:1">
          <input type="text" name="gw_wert[]" value="<?= h($gwr['grenzwert'] ?? '') ?>" placeholder="Grenzwert, z. B. ≤ 3 ppm" style="flex:1">
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.gwrow').remove()">entfernen</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="addGw">+ Grenzwert</button>
    </div>

    <div class="bx-panel"><h2>Deklaration &amp; Status</h2>
      <?php $jn = function ($name, $cur) { $o = [''=>'– unbekannt –','1'=>'ja','0'=>'nein']; $s = '<select name="'.$name.'">'; foreach ($o as $val=>$lbl) { $s .= '<option value="'.$val.'" '.(((string)($cur ?? ''))===(string)$val?'selected':'').'>'.$lbl.'</option>'; } return $s.'</select>'; }; ?>
      <div class="bx-grid">
        <div class="bx-field"><label>Vegan / vegetarisch</label><?= $jn('vegan', $it['vegan'] ?? null) ?></div>
        <div class="bx-field"><label>GVO-frei</label><?= $jn('gvo_frei', $it['gvo_frei'] ?? null) ?></div>
        <div class="bx-field"><label>Bestrahlt / ETO</label><?= $jn('bestrahlt', $it['bestrahlt'] ?? null) ?></div>
        <div class="bx-field"><label>TSE/BSE-frei</label><?= $jn('tse_bse_frei', $it['tse_bse_frei'] ?? null) ?></div>
        <div class="bx-field"><label>Zertifikate</label><input type="text" name="zertifikate" value="<?= $v('zertifikate') ?>" placeholder="z. B. Bio, Fair Trade"></div>
        <div class="bx-field"><label>Zusätze / Verarbeitungshilfsstoffe</label><input type="text" name="zusaetze" value="<?= $v('zusaetze') ?>" placeholder="z. B. E501i, E330"></div>
      </div>
    </div>

    <div class="bx-panel"><h2>Handling</h2>
      <div class="bx-field" style="max-width:280px"><label>Haltbarkeit</label><input type="text" name="haltbarkeit" value="<?= $v('haltbarkeit') ?>" placeholder="z. B. 24 Monate"></div>
      <div class="bx-field"><label>Lagerbedingungen</label><textarea name="lagerbedingungen" placeholder="kühl & trocken, 18–22 °C, 50–60 % rF"><?= $v('lagerbedingungen') ?></textarea></div>
    </div>

    <div class="bx-panel"><h2>Spec-Dokument</h2><div class="bx-grid">
      <div class="bx-field"><label>Spez-Nr</label><input type="text" name="spec_nr" value="<?= $v('spec_nr') ?>"></div>
      <div class="bx-field"><label>Version</label><input type="text" name="spec_version" value="<?= $v('spec_version') ?>"></div>
      <div class="bx-field"><label>Gültig ab</label><input type="date" name="spec_gueltig_ab" value="<?= $v('spec_gueltig_ab') ?>"></div>
      <div class="bx-field"><label>Spec-PDF <?= bx_hint('das vollständige Lieferanten-Spec – Reinheit/Grenzwerte etc.') ?></label><input type="file" name="spec_pdf" accept="application/pdf"></div>
    </div>
    <?php if (!$neu && !empty($it['spec_pdf'])): ?>
      <div style="margin-top:8px"><?= bx_btn('Spec-PDF herunterladen', '?p=spec_pdf&id=' . (int)$id, 'primary') ?> <span class="muted"><?= h($it['spec_pdf']) ?></span></div>
    <?php endif; ?>
    <?php if (!$neu): ?>
      <p class="muted" style="margin:14px 0 6px;font-size:13px">Aus den Feldern oben entsteht <strong>unsere eigene</strong> Spezifikation im bulkify-Layout. Sie geht an den Kunden – das Dokument des Vorlieferanten bleibt intern.</p>
      <div><a class="btn btn-ghost" target="_blank" href="?p=spec_bulkify&id=<?= (int)$id ?>">bulkify-Spezifikation ansehen</a></div>
    <?php endif; ?>
    </div>
  </section>

  <section data-panel="web" hidden>
    <div class="bx-panel">
      <h2 style="margin-top:0">Öffentliche Rohstoff-Datenbank (Website) <?= bx_hint('Wenn aktiv, erscheint dieser Rohstoff auf bulkify.pro (SEO). Es gehen NUR öffentliche Felder online: Name, CAS, botanische Quelle, charakteristische Kennwerte, Wirkstoffe, Beschreibung – NIE Preise, Lieferanten, Bestand oder Originaldokumente.') ?></h2>
      <p class="muted" style="margin-top:0;font-size:13px">Freigabe für viele Rohstoffe auf einmal: <a href="?p=rohstoff_website">Website-Freigabe</a>.</p>
      <label class="bx-check" style="display:flex;gap:8px;align-items:center;margin-bottom:10px">
        <input type="checkbox" name="website_sichtbar" value="1" <?= (int)($it['website_sichtbar'] ?? 0) === 1 ? 'checked' : '' ?>>
        <span>Für die öffentliche Rohstoff-Datenbank auf bulkify.pro freigeben</span>
      </label>
      <div class="bx-field" style="margin:0"><label>Öffentliche Beschreibung <?= bx_hint('neutral/sachlich – KEINE gesundheitsbezogenen Aussagen (EU-VO 1924/2006). Botanische Herkunft, Form, Standardisierung, allgemeines Einsatzgebiet.') ?></label>
        <textarea name="web_beschreibung" rows="3" placeholder="z. B. Wässriger Wurzelextrakt aus Withania somnifera, standardisiert auf 5 % Withanolide, hellbraunes feines Pulver."><?= $v('web_beschreibung') ?></textarea></div>
      <?php if (!$neu && (int)($it['website_sichtbar'] ?? 0) === 1 && trim((string)($it['web_slug'] ?? '')) !== ''): ?>
        <p class="muted" style="font-size:12px;margin:10px 0 0">Öffentliche URL (sobald die Website-Rubrik steht): <strong>bulkify.pro/rohstoffe/<?= h((string)$it['web_slug']) ?>/</strong></p>
      <?php endif; ?>
    </div>
  </section>

  <section data-panel="ek" hidden>
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>EK-Preis <?= bx_hint('Einkaufspreis je Bezugseinheit. Leer oder 0: wird automatisch aus dem günstigsten Lieferantenpreis (kleinste Staffel) übernommen. Von Hand geändert: bleibt so, bis du wieder 0 einträgst.') ?></label><div class="bx-row" style="gap:6px;flex-wrap:nowrap"><input type="text" inputmode="decimal" name="ek_preis" value="<?= h(rtrim(rtrim(number_format((float)($it['ek_preis'] ?? 0), 6, ',', ''), '0'), ',')) ?>" style="width:110px"><select name="ek_basis" style="width:auto"><option value="1">je Bezugseinheit</option><option value="1000">je 1.000</option></select></div>
        <?php if (!$neu && (int)($it['ek_auto'] ?? 0) === 1): ?><div class="muted" style="font-size:12px;margin-top:4px">aus Lieferantenpreis übernommen</div><?php endif; ?></div>
      <?php if (darf_verkauf()): ?><div class="bx-field"><label>VK-Aufschlag (%) <?= bx_hint('Nur für Rohstoff-Weiterverkauf an Kunden. Leer = globaler Aufschlag aus den Einstellungen.') ?></label><input type="number" step="0.1" name="vk_aufschlag_prozent" value="<?= $v('vk_aufschlag_prozent') ?>" placeholder="<?= h((string)(float)meta_get('aufschlag_rohstoff','30')) ?> (Standard)"></div><?php endif; ?>
      <div class="bx-field"><label>Preis je</label>
        <select name="preis_bezug">
          <?php foreach (['kg'=>'kg','g'=>'g','Stk'=>'Stk','L'=>'Liter'] as $key=>$lbl): ?>
            <option value="<?= $key ?>" <?= ($it['preis_bezug']??'')===$key?'selected':'' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Haupt-Lieferant</label>
        <select name="haupt_lieferant_id">
          <option value="">– keiner –</option>
          <?php foreach ($lieferanten as $lf): ?>
            <option value="<?= $lf['id'] ?>" <?= (int)($it['haupt_lieferant_id']??0)===(int)$lf['id']?'selected':'' ?>><?= h($lf['firma']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div></div>
  </section>

  <?php if (!$neu): ?>
  <section data-panel="verw" hidden><div class="bx-panel"><h2>Verwendung</h2>
    <?php if (!$verwendung): ?>
      <div class="muted">Dieser Artikel wurde noch in keiner Produktion verbraucht.</div>
    <?php else: $mv = fn($x)=> rtrim(rtrim(number_format((float)$x,3,',','.'),'0'),','); ?>
      <p class="muted" style="margin:0 0 10px">In welchen Produktionsaufträgen dieser Artikel verbraucht wurde.</p>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Produktionsauftrag</th><th>Auftrag</th><th>Produkt</th><th class="bx-num">Verbraucht</th><th>zuletzt</th></tr></thead>
        <tbody>
        <?php foreach ($verwendung as $w): ?>
          <tr>
            <td><a href="?p=produktionsauftrag&id=<?= (int)$w['pa_id'] ?>"><?= h((string)$w['pa_nr']) ?></a></td>
            <td><?= $w['auftrag_id'] ? '<a href="?p=auftrag&id=' . (int)$w['auftrag_id'] . '">' . h((string)$w['auftrag_nr']) . '</a>' : '<span class="muted">–</span>' ?></td>
            <td><?= $w['produkt'] ? h((string)$w['produkt']) : '<span class="muted">–</span>' ?></td>
            <td class="bx-num"><?= $mv($w['menge']) ?> <?= h((string)$w['einheit']) ?></td>
            <td class="muted"><?= !empty($w['zuletzt']) ? h(fmt_zeit($w['zuletzt'], 'd.m.Y')) : '–' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div></section>
  <section data-panel="verlauf" hidden>
    <div class="bx-panel"><h2>Verlauf</h2>
    <?php if (!$bewegungen): ?>
      <div class="muted">Noch keine Bewegungen (Wareneingänge/Verbrauch) erfasst.</div>
    <?php else: $mv = fn($x)=> rtrim(rtrim(number_format(abs((float)$x),3,',','.'),'0'),','); ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Datum</th><th>Vorgang</th><th class="bx-num">Menge</th><th>Herkunft / Ziel</th></tr></thead>
        <tbody>
        <?php foreach ($bewegungen as $bw): $plus = ($bw['typ'] === 'eingang'); ?>
          <tr>
            <td class="muted"><?= !empty($bw['datum']) ? h(date('d.m.Y', strtotime((string)$bw['datum']))) : '–' ?></td>
            <td><?= h((string)$bw['text']) ?></td>
            <td class="bx-num" style="color:<?= $plus ? 'var(--ok,#0f6b4d)' : 'var(--err,#8f231b)' ?>"><?= $plus ? '+' : '−' ?><?= $mv($bw['menge']) ?> <?= h((string)$bw['einheit']) ?></td>
            <td class="muted"><?= h((string)$bw['herkunft']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit" data-busy="<?= $neu ? 'Wird angelegt&#8230;' : 'Wird gespeichert&#8230;' ?>"><?= $neu ? 'Rohstoff anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=rohstoffe">Abbrechen</a>
  </div>
</form>

<?php // Diese beiden Abschnitte haben eigene Formulare und stehen deshalb ausserhalb
      // des Stammdaten-Formulars. Die Reiter-Logik blendet sie trotzdem korrekt ein. ?>
<section data-panel="spec" hidden>
  <?php // bulkify-Spezifikation, die der Kunde sieht – als Vorschau prüfen und SEPARAT freigeben.
        // Erst-Upload gibt automatisch frei. Ändern sich die Daten später, bleibt die alte Version beim Kunden
        // sichtbar, bis das Team die Änderung (mit Diff) erneut freigibt.
        if (!$neu):
          $specFrei = (int)($it['spec_freigegeben'] ?? 0) === 1;
          $specAend = (int)($it['spec_aenderung_offen'] ?? 0) === 1;
          $specDiff = $specAend ? spec_diff((int)$id) : null;
          // Ein Diff-Block je Kategorie: neu (ergänzt), geändert (alt -> neu), weg (entfallen).
          $diffBlock = function (string $titel, array $d): void {
              if (!$d['neu'] && !$d['weg'] && !$d['geaendert']) return; ?>
            <div style="margin-top:10px">
              <div style="font-size:13px;margin-bottom:4px"><?= h($titel) ?></div>
              <div class="bx-tablewrap"><table class="bx-table"><tbody>
                <?php foreach ($d['geaendert'] as $r): ?>
                  <tr><td><?= h($r['name']) ?></td><td class="bx-num"><span class="muted" style="text-decoration:line-through"><?= h($r['alt'] !== '' ? $r['alt'] : '–') ?></span> &rarr; <strong><?= h($r['neu'] !== '' ? $r['neu'] : '–') ?></strong></td></tr>
                <?php endforeach; ?>
                <?php foreach ($d['neu'] as $r): ?>
                  <tr><td><?= h($r['name']) ?> <span style="color:var(--ok)">neu</span></td><td class="bx-num"><strong><?= h($r['neu'] !== '' ? $r['neu'] : '–') ?></strong></td></tr>
                <?php endforeach; ?>
                <?php foreach ($d['weg'] as $r): ?>
                  <tr><td style="text-decoration:line-through"><?= h($r['name']) ?></td><td class="bx-num"><span class="muted">entfällt (war <?= h($r['alt'] !== '' ? $r['alt'] : '–') ?>)</span></td></tr>
                <?php endforeach; ?>
              </tbody></table></div>
            </div>
          <?php }; ?>
  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h2 style="margin:0">bulkify-Spezifikation &ndash; Kundenfreigabe</h2>
      <?= $specAend ? bx_badge('Änderung – erneute Freigabe nötig','warn') : ($specFrei ? bx_badge('für Kunden freigegeben','ok') : bx_badge('nicht freigegeben','warn')) ?>
    </div>
    <p class="muted" style="margin-top:4px">Das ist das Dokument, das der Kunde sieht &ndash; automatisch aus den Rohstoffdaten erzeugt. Beim <strong>Erst-Upload</strong> wird es automatisch freigegeben. Ändern sich die Daten später, sieht der Kunde <strong>weiterhin die zuletzt freigegebene Version</strong>, bis ihr die Änderung unten freigebt.</p>
    <?php if (isset($_GET['specfrei'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px"><?= $_GET['specfrei'] === '1' ? 'Spezifikation für den Kunden freigegeben.' : 'Freigabe zurückgezogen.' ?></div><?php endif; ?>
    <?php if ($specFrei && !$specAend && !empty($it['spec_freigabe_am'])): ?><div class="muted" style="font-size:12px;margin-bottom:8px">Freigegeben am <?= h(fmt_zeit($it['spec_freigabe_am'])) ?> Uhr<?= !empty($it['spec_freigabe_von']) ? ' von ' . h($it['spec_freigabe_von']) : '' ?>.</div><?php endif; ?>
    <?php if ($specAend && $specDiff): ?>
    <div class="bx-panel" style="border-left:3px solid var(--warn);background:var(--bg-soft,transparent);margin:4px 0 12px">
      <strong>Die Spezifikation hat sich seit der letzten Freigabe geändert.</strong>
      <div class="muted" style="font-size:12px;margin-top:2px">Der Kunde sieht aktuell noch den Stand vom <?= !empty($it['spec_freigabe_am']) ? h(fmt_zeit($it['spec_freigabe_am'], 'd.m.Y')) : '–' ?>. Prüfe die Unterschiede und gib die neue Version frei.</div>
      <?php if (!$specDiff['hat']): ?>
        <div class="muted" style="font-size:12px;margin-top:8px">Keine inhaltliche Abweichung erkennbar – du kannst die aktuelle Version einfach erneut freigeben.</div>
      <?php else:
        $diffBlock('Wirkstoffe / Gehalt', $specDiff['wirkstoffe']);
        $diffBlock('Charakteristische Kennwerte', $specDiff['kennwerte']);
        $diffBlock('Reinheit / Grenzwerte', $specDiff['grenzwerte']);
      endif; ?>
    </div>
    <?php endif; ?>
    <div style="border:1px solid var(--line);border-radius:8px;overflow:hidden;margin-bottom:12px">
      <iframe src="?p=spec_bulkify&id=<?= (int)$id ?>" style="width:100%;height:520px;border:0;display:block" title="bulkify-Spezifikation Vorschau (aktueller Stand)"></iframe>
    </div>
    <div class="muted" style="font-size:12px;margin:-6px 0 10px">Vorschau zeigt den <strong>aktuellen</strong> Stand (das, was nach einer Freigabe beim Kunden ankommt).</div>
    <div class="bx-row" style="gap:10px;align-items:center;flex-wrap:wrap">
      <a class="btn btn-ghost" target="_blank" href="?p=spec_bulkify&id=<?= (int)$id ?>">In neuem Tab öffnen</a>
      <?php if ($specAend): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="aktion" value="spec_freigabe"><input type="hidden" name="freigeben" value="1">
          <button class="btn btn-primary" type="submit">Änderung freigeben</button>
        </form>
        <form method="post" style="margin:0">
          <input type="hidden" name="aktion" value="spec_freigabe"><input type="hidden" name="freigeben" value="0">
          <button class="btn btn-ghost" type="submit">Freigabe zurückziehen</button>
        </form>
      <?php else: ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="aktion" value="spec_freigabe">
          <input type="hidden" name="freigeben" value="<?= $specFrei ? '0' : '1' ?>">
          <button class="btn <?= $specFrei ? 'btn-ghost' : 'btn-primary' ?>" type="submit"><?= $specFrei ? 'Freigabe zurückziehen' : 'Für den Kunden freigeben' ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php // Unterlagen des Lieferanten mit der KI auslesen. Nichts wird ungefragt gespeichert:
        // jedes Feld hat einen Haken, und übernommen wird nur, was angehakt ist.
        if (!$neu):
          require_once BX_ROOT . '/core/spec_ki.php';
          $kiDocs = all("SELECT id, typ, titel, datei_orig, ki_stand FROM dokument WHERE objekt_typ='item' AND objekt_id=? ORDER BY id DESC", [(int)$id]);
          $kiFelder = spec_ki_felder();
  ?>
  <div class="bx-panel" id="kispec"><h2>Unterlage auslesen (KI)</h2>
    <p class="muted" style="margin-top:0">Liest eine Spezifikation oder ein Analysenzertifikat des Lieferanten – auch eingescannte – und schlägt die Felder vor. <strong>Gespeichert wird nur, was du anhakst.</strong></p>
    <?php if (isset($_GET['kiueb'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px"><?= (int)$_GET['kiueb'] ?> Feld(er) übernommen.</div><?php endif; ?>
    <?php if ($kiFehler !== ''): ?><div style="border:1px solid #e6c4c0;color:#8f231b;padding:8px 12px;margin-bottom:10px;border-radius:8px"><?= h($kiFehler) ?></div><?php endif; ?>
    <?php if (!$kiDocs): ?>
      <div class="muted">Noch keine Unterlagen hinterlegt. Lade im Reiter <strong>Dokumente</strong> eine Spezifikation oder ein CoA hoch – oder der Lieferant tut es in seinem Portal.</div>
    <?php elseif (!ki_bereit()): ?>
      <div class="muted">Die KI ist nicht eingerichtet (Einstellungen &rarr; KI).</div>
    <?php else: ?>
      <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="aktion" value="ki_lesen">
        <div class="bx-field" style="margin:0;min-width:320px"><label>Unterlage</label>
          <select name="dok_id">
            <?php foreach ($kiDocs as $d): ?>
              <option value="<?= (int)$d['id'] ?>"><?= h(strtoupper((string)$d['typ'])) ?> · <?= h($d['titel'] ?: $d['datei_orig']) ?><?= $d['ki_stand'] ? ' (schon gelesen)' : '' ?></option>
            <?php endforeach; ?>
          </select></div>
        <button class="btn btn-primary" type="submit" data-busy="Wird ausgelesen&#8230;">Auslesen</button>
        <span class="muted" style="font-size:12px;align-self:center">dauert je nach Umfang 10–60 Sekunden</span>
      </form>
    <?php endif; ?>

    <?php if ($kiVorschlag && !empty($kiVorschlag['ok'])): $st = (array)($kiVorschlag['stamm'] ?? []); ?>
      <div style="margin-top:16px">
        <div class="bx-row" style="gap:10px;align-items:center;margin-bottom:8px">
          <strong>Vorschlag</strong>
          <?= bx_badge('erkannt als ' . ['spec'=>'Spezifikation','coa'=>'Analysenzertifikat','beides'=>'Spezifikation + CoA','unklar'=>'unklar'][$kiVorschlag['typ']], $kiVorschlag['typ'] === 'unklar' ? 'warn' : 'info') ?>
          <?= bx_badge('Sicherheit ' . $kiVorschlag['sicherheit'], $kiVorschlag['sicherheit'] === 'hoch' ? 'ok' : ($kiVorschlag['sicherheit'] === 'niedrig' ? 'warn' : 'info')) ?>
        </div>
        <?php foreach ((array)($kiVorschlag['hinweise'] ?? []) as $hw): ?>
          <div class="muted" style="font-size:12px">Hinweis: <?= h($hw) ?></div>
        <?php endforeach; ?>
        <?php if (!$st): ?>
          <div class="muted" style="margin-top:8px">Keine Stammdatenfelder gefunden.</div>
        <?php else: ?>
        <form method="post" style="margin-top:10px">
          <input type="hidden" name="aktion" value="ki_uebernehmen">
          <input type="hidden" name="dok_id" value="<?= (int)$kiDok ?>">
          <div class="bx-tablewrap"><table class="bx-table">
            <thead><tr><th style="width:34px"></th><th>Feld</th><th>Bisher</th><th>Vorschlag</th></tr></thead>
            <tbody>
            <?php foreach ($st as $k => $wert):
                    $alt = trim((string)($it[$k] ?? ''));
                    $jn  = $kiFelder[$k][1] === 'janein';
                    $zeig = fn($w) => $w === '' || $w === null ? '–' : ($jn ? ((int)$w === 1 ? 'ja' : 'nein') : (string)$w);
                    $gleich = (string)$wert === $alt; ?>
              <tr>
                <td><input type="checkbox" name="feld[]" value="<?= h($k) ?>"<?= $gleich ? '' : ' checked' ?><?= $gleich ? ' disabled' : '' ?>></td>
                <td><?= h($kiFelder[$k][0]) ?></td>
                <td class="muted"><?= h($zeig($alt)) ?></td>
                <td><strong><?= h($zeig($wert)) ?></strong><?= $gleich ? ' <span class="muted">(unverändert)</span>' : '' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table></div>
          <div class="bx-row" style="margin-top:10px"><button class="btn btn-primary" type="submit">Angehakte Felder übernehmen</button></div>
        </form>
        <?php endif; ?>

        <?php $kw = (array)($kiVorschlag['werte'] ?? []); if ($kw): $chg = all("SELECT id, charge_nr FROM charge WHERE item_id=? ORDER BY id DESC", [(int)$id]); ?>
          <div style="margin-top:18px"><strong>Analysenwerte</strong> <span class="muted">(<?= count($kw) ?> Zeilen)</span>
            <?php if (($kiVorschlag['charge']['charge_nr'] ?? '') !== ''): ?><span class="muted"> · Charge laut Dokument: <?= h($kiVorschlag['charge']['charge_nr']) ?></span><?php endif; ?>
          </div>
          <div class="bx-tablewrap" style="margin-top:6px"><table class="bx-table">
            <thead><tr><th>Parameter</th><th>Spezifikation</th><th>Ergebnis</th><th>Methode</th></tr></thead>
            <tbody><?php foreach ($kw as $z): ?>
              <tr><td><?= h($z['parameter']) ?></td><td><?= h($z['spezifikation']) ?></td><td><?= h($z['ergebnis']) ?></td><td><?= h($z['methode']) ?></td></tr>
            <?php endforeach; ?></tbody>
          </table></div>
          <?php if ($chg): ?>
          <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;margin-top:10px">
            <input type="hidden" name="aktion" value="ki_werte">
            <input type="hidden" name="dok_id" value="<?= (int)$kiDok ?>">
            <div class="bx-field" style="margin:0;max-width:260px"><label>An welche Charge?</label>
              <select name="charge_id"><?php foreach ($chg as $c): ?><option value="<?= (int)$c['id'] ?>"<?= ($kiVorschlag['charge']['charge_nr'] ?? '') === $c['charge_nr'] ? ' selected' : '' ?>><?= h($c['charge_nr']) ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-ghost" type="submit" onclick="return confirm('Die bisherigen Analysenwerte dieser Charge werden ersetzt.');">Werte an die Charge speichern</button>
          </form>
          <?php else: ?><div class="muted" style="margin-top:8px">Für diesen Rohstoff gibt es noch keine Charge, an die die Werte gehören könnten.</div><?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</section>

<section data-panel="lager" hidden>
  <div class="bx-cards">
    <div class="bx-card"><div class="k">Bestand (frei)</div><div class="v"><?= $bestandWert($bestand_frei_je) ?></div></div>
    <div class="bx-card"><div class="k">Quarantäne</div><div class="v" style="<?= $bestand_qua_je?'color:var(--warn)':'' ?>"><?= $bestand_qua_je ? $bestandWert($bestand_qua_je) : '<span class="muted">0</span>' ?></div></div>
    <div class="bx-card"><div class="k">Reserviert</div><div class="v" style="<?= $reserviert_gesamt>$bestand_frei+1e-9?'color:var(--warn)':'' ?>"><?= $reserviert_gesamt>0 ? h(rtrim(rtrim(number_format($reserviert_gesamt,3,',','.'),'0'),',')).' '.$v('einheit') : '<span class="muted">0</span>' ?></div></div>
    <div class="bx-card"><div class="k">Chargen</div><div class="v"><?= count($charges) ?></div></div>
  </div>
  <div class="bx-panel">
    <h2>Chargen <?= bx_hint('Wareneingang bucht neue Chargen; Rohstoffe starten in Quarantäne') ?></h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Charge</th><th class="bx-num">Verfügbar</th><th>MHD</th><th>Lieferant</th><th>Wareneingang</th><th>Status</th><th>CoA-Freigabe</th><th></th></tr></thead>
      <tbody>
      <?php if (!$charges): ?><tr><td colspan="8" class="muted">Noch keine Chargen. Über „Wareneingang" buchen.</td></tr><?php endif; ?>
      <?php if (isset($_GET['coafrei'])): ?><tr><td colspan="8" class="badge-ok" style="padding:8px 12px">CoA-Freigabe aktualisiert.</td></tr><?php endif; ?>
      <?php if (isset($_GET['korrok'])): ?><tr><td colspan="8" class="badge-ok" style="padding:8px 12px">Charge-Bestand korrigiert. Der Bedarf/die Einkaufsliste rechnet neu.</td></tr><?php endif; ?>
      <?php if (($_GET['korrfehler'] ?? '') === 'grund'): ?><tr><td colspan="8" style="padding:8px 12px;color:#8f231b">Bitte einen Grund für die Bestandskorrektur angeben.</td></tr>
      <?php elseif (isset($_GET['korrfehler'])): ?><tr><td colspan="8" style="padding:8px 12px;color:#8f231b"><?= ($_GET['korrfehler'] ?? '') === '1' ? 'Nur Admins dürfen Bestand/Charge ändern.' : h((string)$_GET['korrfehler']) ?></td></tr><?php endif; ?>
      <?php foreach ($charges as $c): $coaFrei = (int)($c['coa_freigegeben'] ?? 0) === 1; ?>
        <tr>
          <td><?= h($c['charge_nr'] ?: '–') ?></td>
          <td class="bx-num"><?= h(rtrim(rtrim(number_format((float)$c['menge_verfuegbar'],3,',','.'),'0'),',')).' '.h($c['einheit']) ?></td>
          <td><?= $c['mhd'] ? h(date('d.m.Y',strtotime($c['mhd']))) : '<span class="muted">–</span>' ?></td>
          <td><?= $c['lieferant_firma'] ? h($c['lieferant_firma']) : '<span class="muted">–</span>' ?></td>
          <td><?= $c['wareneingang'] ? h(date('d.m.Y',strtotime($c['wareneingang']))) : '' ?></td>
          <td><?= match($c['status']){'frei'=>bx_badge('frei','ok'),'quarantaene'=>bx_badge('Quarantäne','warn'),'gesperrt'=>bx_badge('gesperrt','err'),default=>bx_badge(status_text($c['status']))} ?></td>
          <td>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
              <?= $coaFrei ? bx_badge('freigegeben','ok') : bx_badge('nicht freigegeben','warn') ?>
              <form method="post" style="margin:0">
                <input type="hidden" name="aktion" value="coa_freigabe">
                <input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>">
                <input type="hidden" name="freigeben" value="<?= $coaFrei ? '0' : '1' ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= $coaFrei ? 'zurückziehen' : 'freigeben' ?></button>
              </form>
            </div>
            <?php if ($coaFrei && !empty($c['coa_freigabe_am'])): ?><div class="muted" style="font-size:11px;margin-top:2px"><?= h(date('d.m.Y',strtotime((string)$c['coa_freigabe_am']))) ?><?= !empty($c['coa_freigabe_von']) ? ' · ' . h($c['coa_freigabe_von']) : '' ?></div><?php endif; ?>
          </td>
          <td class="bx-num">
            <a class="btn btn-ghost btn-sm" target="_blank" href="?p=coa_bulkify&id=<?= (int)$c['id'] ?>" title="Analysenzertifikat im bulkify-Layout – das geht an den Kunden">&#8681; CoA</a>
            <?php if (has_role('admin')): ?>
            <details style="margin-top:6px;text-align:left">
              <summary class="muted" style="cursor:pointer;font-size:11px">Bestand korrigieren</summary>
              <form method="post" style="margin:6px 0 0;display:flex;flex-direction:column;gap:4px;align-items:flex-end">
                <input type="hidden" name="aktion" value="charge_bestand_korr">
                <input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>">
                <div style="display:flex;gap:4px;align-items:center">
                  <input type="text" name="menge" value="<?= h(rtrim(rtrim(number_format((float)$c['menge_verfuegbar'],3,',','.'),'0'),',')) ?>" style="width:90px;padding:4px 6px;text-align:right" inputmode="decimal" title="Neue verfügbare Menge">
                  <span class="muted" style="font-size:11px"><?= h((string)$c['einheit']) ?></span>
                </div>
                <select name="korr_was" data-norscombo style="width:180px;padding:4px 6px"><option value="verfuegbar">nur verfügbar (Schwund, Bruch)</option><option value="ursprung">Ursprung falsch eingebucht</option></select>
                <select name="korr_grund" required data-norscombo style="width:180px;padding:4px 6px"><option value="">Grund wählen</option><option value="einbuchfehler">Einbuchfehler</option><option value="lieferschein">Lieferschein abweichend</option><option value="schwund">Schwund</option><option value="bruch">Bruch</option><option value="sonstiges">Sonstiges</option></select>
                <input type="text" name="grund" placeholder="Notiz" style="width:180px;padding:4px 6px" title="Notiz zur Korrektur (bei Sonstiges Pflicht)">
                <button class="btn btn-ghost btn-sm" type="submit">setzen</button>
              </form>
            </details>
            <details style="margin-top:4px;text-align:left">
              <summary class="muted" style="cursor:pointer;font-size:11px">Charge bearbeiten</summary>
              <form method="post" style="margin:6px 0 0;display:flex;flex-direction:column;gap:4px;align-items:flex-end">
                <input type="hidden" name="aktion" value="charge_daten_korr">
                <input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>">
                <input type="text" name="charge_nr" value="<?= h((string)($c['charge_nr'] ?? '')) ?>" placeholder="Chargennummer" style="width:180px;padding:4px 6px" title="Chargennummer">
                <input type="date" name="mhd" value="<?= h((string)($c['mhd'] ?? '')) ?>" style="width:150px;padding:4px 6px" title="MHD">
                <select name="standort" style="width:150px;padding:4px 6px" title="Lagerort">
                  <?php $cs = (string)($c['standort'] ?? 'lager1'); foreach (['lager1'=>'Lager 1','lager2'=>'Fremdlager','produktion'=>'Produktion'] as $sk=>$sl): ?>
                    <option value="<?= $sk ?>" <?= $cs===$sk?'selected':'' ?>><?= h($sl) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-ghost btn-sm" type="submit">speichern</button>
              </form>
            </details>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div style="margin-top:12px"><?= bx_btn('Wareneingang buchen', '?p=wareneingang', 'primary') ?></div>
  </div>

  <?php if (has_role('admin')): ?>
  <?php // Admin: Einheit des Artikels ändern – gilt für Artikel UND alle Chargen (Einheiten nie vermischen). ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Einheit ändern <span class="muted" style="font-weight:400;font-size:13px">(Artikel + alle Chargen)</span></h2>
    <?php if (isset($_GET['einhok'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Einheit geändert – Artikel und alle Chargen angepasst.</div><?php endif; ?>
    <?php if (($_GET['einhfehler'] ?? '') === 'faktor'): ?><div style="padding:8px 12px;margin-bottom:10px;color:#8f231b">Bitte einen gültigen Umrechnungsfaktor (> 0) angeben.</div>
    <?php elseif (($_GET['einhfehler'] ?? '') === 'leer'): ?><div style="padding:8px 12px;margin-bottom:10px;color:#8f231b">Bitte die neue Einheit angeben.</div>
    <?php elseif (isset($_GET['einhfehler'])): ?><div style="padding:8px 12px;margin-bottom:10px;color:#8f231b">Nur Admins dürfen die Einheit ändern.</div><?php endif; ?>
    <form method="post" onsubmit="return confirm('Einheit für den Artikel und ALLE seine Chargen ändern?');">
      <input type="hidden" name="aktion" value="einheit_aendern">
      <div class="bx-row" style="gap:14px;align-items:flex-end;flex-wrap:wrap">
        <div class="bx-field" style="margin:0"><label>Aktuelle Einheit</label><input type="text" value="<?= h($v('einheit')) ?>" disabled style="width:120px"></div>
        <div class="bx-field" style="margin:0"><label>Neue Einheit</label><input type="text" name="neue_einheit" value="<?= h($v('einheit')) ?>" style="width:120px" required></div>
        <div class="bx-field" style="margin:0">
          <label>Art der Änderung</label>
          <select name="modus" id="einhModus" style="width:260px">
            <option value="bezeichnung">Nur Bezeichnung war falsch (Mengen bleiben)</option>
            <option value="umrechnen">Umrechnen (Menge × Faktor)</option>
          </select>
        </div>
        <div class="bx-field" id="einhFaktorWrap" style="margin:0;display:none"><label>Umrechnungsfaktor</label><input type="text" name="faktor" value="1" style="width:120px" inputmode="decimal" placeholder="z. B. 1000"></div>
        <button class="btn btn-primary" type="submit">Einheit ändern</button>
      </div>
      <p class="muted" style="font-size:12px;margin:8px 0 0">Beispiel Umrechnen: von kg auf g → Faktor 1000 (aus 2 kg werden 2.000 g). „Nur Bezeichnung" lässt die Zahlen unverändert.</p>
    </form>
  </div>

  <?php // Admin: losen Doppel-Artikel in den richtigen Artikel zusammenführen (Chargen + Reservierungen wandern mit).
    $mergeZiele = all("SELECT id, name, artikelnummer, einheit FROM item WHERE id<>? AND COALESCE(zusammengefuehrt_in,0)=0 ORDER BY name", [(int)$id]); ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Mit anderem Artikel zusammenführen <span class="muted" style="font-weight:400;font-size:13px">(dieser Artikel wird danach deaktiviert)</span></h2>
    <?php if (($_GET['mergefehler'] ?? '') === 'ziel'): ?><div style="padding:8px 12px;margin-bottom:10px;color:#8f231b">Bitte einen gültigen Ziel-Artikel wählen (nicht denselben).</div>
    <?php elseif (($_GET['mergefehler'] ?? '') === 'einheit'): ?><div style="padding:8px 12px;margin-bottom:10px;color:#8f231b">Ziel-Artikel hat eine andere Einheit. Bitte erst die Einheit angleichen, damit der Bestand nicht vermischt wird.</div>
    <?php elseif (isset($_GET['mergefehler'])): ?><div style="padding:8px 12px;margin-bottom:10px;color:#8f231b">Nur Admins dürfen Artikel zusammenführen.</div><?php endif; ?>
    <form method="post" onsubmit="return confirm('Diesen Artikel in den gewählten Ziel-Artikel zusammenführen? Chargen und Reservierungen wandern, dieser Artikel wird deaktiviert (nicht gelöscht).');">
      <input type="hidden" name="aktion" value="artikel_merge">
      <div class="bx-row" style="gap:14px;align-items:flex-end;flex-wrap:wrap">
        <div class="bx-field" style="margin:0;min-width:320px">
          <label>Ziel-Artikel (bleibt bestehen)</label>
          <select name="ziel_item_id" class="rscombo" required>
            <option value="">– Ziel-Artikel wählen –</option>
            <?php foreach ($mergeZiele as $z): ?><option value="<?= (int)$z['id'] ?>"><?= h($z['name']) ?><?= $z['artikelnummer'] ? ' · ' . h($z['artikelnummer']) : '' ?><?= $z['einheit'] ? ' · ' . h($z['einheit']) : '' ?></option><?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary" type="submit">Zusammenführen</button>
      </div>
      <p class="muted" style="font-size:12px;margin:8px 0 0">Alle Chargen und Reservierungen dieses Artikels wandern auf den Ziel-Artikel. Dieser Artikel wird danach deaktiviert (gesperrt), nicht gelöscht – Historie bleibt.</p>
    </form>
  </div>
  <?php // Admin: Rohstoff endgültig löschen (z. B. Import-Dublette). Abgesichert; blockiert bei echter Verwendung. ?>
  <div class="bx-panel" style="border-left:3px solid var(--err,#c0392b)">
    <h2 style="margin-top:0">Rohstoff löschen <span class="muted" style="font-weight:400;font-size:13px">(endgültig – z. B. versehentliche Dublette)</span></h2>
    <?php if (isset($_GET['delfehler'])): ?><div style="padding:8px 12px;margin-bottom:10px;color:#8f231b"><?= h((string)$_GET['delfehler']) ?></div><?php endif; ?>
    <p class="muted" style="font-size:13px;margin-top:0">Löscht diesen Rohstoff samt Wirkstoffen/Kennwerten/Grenzwerten, leeren Chargen und internen Dokumenten. <strong>Blockiert</strong>, wenn er in einer Rezeptur, Bestellung, einem Angebot, in Produktion oder mit Bestand verwendet wird – dann stattdessen oben „zusammenführen". Nicht umkehrbar.</p>
    <form method="post" onsubmit="return confirm('Diesen Rohstoff endgültig löschen? Das kann nicht rückgängig gemacht werden.');">
      <input type="hidden" name="aktion" value="rohstoff_loeschen">
      <button class="btn btn-ghost" type="submit" style="color:#c0392b;border-color:#c0392b">Rohstoff löschen</button>
    </form>
  </div>
  <script>
  (function(){ var m=document.getElementById('einhModus'), w=document.getElementById('einhFaktorWrap');
    if(m&&w){ function u(){ w.style.display = m.value==='umrechnen' ? '' : 'none'; } m.addEventListener('change',u); u(); } })();
  </script>
  <?php endif; ?>

  <?php // Teil 4: Schwund-Übersicht (Differenz rechnerisch vs. gewogen beim Rückgabe-Wiegen). Für Steuer/Inventur.
    $schwundRows = item_schwund_liste((int)$id);
    if ($schwundRows):
      $schwundSumme = item_schwund_summe((int)$id);
      $nfk = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ','); ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Schwund-Übersicht <span class="muted" style="font-weight:400;font-size:13px">(gewogen vs. rechnerisch – für Inventur/Steuer)</span></h2>
    <div class="muted" style="font-size:13px;margin-bottom:10px">Summe Schwund: <strong><?= $nfk($schwundSumme) ?></strong> <?= h($v('einheit') ?: 'kg') ?></div>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Datum</th><th>Charge</th><th>Auftrag</th><th class="bx-num">rechnerisch</th><th class="bx-num">gewogen</th><th class="bx-num">Differenz</th><th class="bx-num">%</th><th>Mitarbeiter</th></tr></thead>
      <tbody>
      <?php foreach ($schwundRows as $s): $rech = (float)$s['rechnerisch']; $gew = (float)$s['gewogen']; $dif = (float)$s['differenz'];
            $proz = $rech > 1e-9 ? (-$dif / $rech * 100) : 0; $mehr = $dif < 0; ?>
        <tr>
          <td><?= h(fmt_zeit((string)$s['angelegt'], 'd.m.Y H:i')) ?></td>
          <td><?php if (!empty($s['charge_id'])): ?><a href="?p=chargen&id=<?= (int)$s['charge_id'] ?>"><?= h((string)($s['charge_nr'] ?: ('#' . $s['charge_id']))) ?></a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <td><?php if (!empty($s['auftrag_id'])): ?><a href="?p=auftrag&id=<?= (int)$s['auftrag_id'] ?>"><?= h((string)($s['auftrag_nr'] ?: ('#' . $s['auftrag_id']))) ?></a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <td class="bx-num"><?= $nfk($rech) ?> <?= h((string)$s['einheit']) ?></td>
          <td class="bx-num"><?= $nfk($gew) ?> <?= h((string)$s['einheit']) ?></td>
          <td class="bx-num" style="<?= $mehr ? 'color:var(--warn)' : '' ?>"><?= ($mehr ? '+' : '') . $nfk(-$dif) ?> <?= h((string)$s['einheit']) ?></td>
          <td class="bx-num"><?= number_format($proz, 1, ',', '.') ?> %</td>
          <td><?= h((string)($s['mitarbeiter'] ?: '–')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted" style="font-size:12px;margin:8px 0 0">„Differenz" positiv = mehr gewogen als rechnerisch (grün/Warnung – Mischung/Rezeptur prüfen), negativ = Schwund. Der Lagerbestand ist immer der gewogene Wert.</p>
  </div>
  <?php endif; ?>

  <?php // Reservierungen: Bestand einem Auftrag fest zuteilen. Reduziert die Netto-Verfügbarkeit für
        // andere Aufträge (item_verfuegbar_fuer). Menge frei anpassbar; 0 hebt die Reservierung auf.
        $einheitLbl = $v('einheit') ?: 'Stk'; ?>
  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:10px">
      <h2 style="margin:0">Reservierungen <?= bx_hint('Teile freien Bestand gezielt einem Auftrag zu. Reservierte Menge zählt für andere Aufträge nicht mehr als verfügbar. Die Menge kannst du jederzeit ändern; 0 hebt die Reservierung auf. Reservieren über den Bestand hinaus ist möglich (z. B. gegen eine erwartete Lieferung).') ?></h2>
      <div class="muted" style="font-size:13px">frei: <strong><?= h(rtrim(rtrim(number_format($bestand_frei,3,',','.'),'0'),',')) ?></strong> · reserviert: <strong style="<?= $reserviert_gesamt>$bestand_frei+1e-9?'color:var(--warn)':'' ?>"><?= h(rtrim(rtrim(number_format($reserviert_gesamt,3,',','.'),'0'),',')) ?></strong> <?= h($einheitLbl) ?></div>
    </div>
    <?php if (isset($_GET['resok'])): ?><div class="badge-ok" style="padding:8px 12px;margin-top:10px">Reservierung aktualisiert. Die Netto-Verfügbarkeit für andere Aufträge rechnet neu.</div><?php endif; ?>
    <?php if (isset($_GET['resfehler'])): ?><div style="padding:8px 12px;margin-top:10px;color:#8f231b"><?= h((string)$_GET['resfehler']) ?></div><?php endif; ?>

    <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
      <thead><tr><th>Auftrag</th><th>Kunde</th><th class="bx-num">Reserviert</th><th class="bx-num">Benötigt</th><th>Seit</th><th>Herkunft</th><th></th></tr></thead>
      <tbody>
      <?php if (!$reservierungen): ?><tr><td colspan="7" class="muted">Noch nichts reserviert. Unten einem Auftrag eine Menge zuteilen.</td></tr><?php endif; ?>
      <?php foreach ($reservierungen as $r): $ausProd = !empty($r['pa_id']);
          $mStr = rtrim(rtrim(number_format((float)$r['menge'],3,',','.'),'0'),','); ?>
        <tr>
          <td><?php if (!empty($r['auftrag_id'])): ?><a href="?p=auftrag&id=<?= (int)$r['auftrag_id'] ?>"><?= h((string)($r['auftrag_nr'] ?: '–')) ?></a><?php else: ?><span class="muted">–</span><?php endif; ?></td>
          <td style="max-width:220px;overflow-wrap:anywhere"><?= kunde_link($r['kunde_id'] ?? 0, $r['kunde_firma']) ?></td>
          <td class="bx-num">
            <?php if ($ausProd): ?>
              <?= h($mStr) ?> <?= h($einheitLbl) ?>
            <?php else: ?>
              <form method="post" style="margin:0;display:flex;gap:4px;align-items:center;justify-content:flex-end">
                <input type="hidden" name="aktion" value="res_setzen">
                <input type="hidden" name="auftrag_id" value="<?= (int)$r['auftrag_id'] ?>">
                <input type="text" name="menge" value="<?= h($mStr) ?>" inputmode="decimal" style="width:100px;padding:5px 8px;text-align:right" title="Reservierte Menge ändern (0 = aufheben)">
                <span class="muted" style="font-size:12px"><?= h($einheitLbl) ?></span>
                <button class="btn btn-ghost btn-sm" type="submit">ändern</button>
              </form>
            <?php endif; ?>
          </td>
          <td class="bx-num"><?php $nd = $needJeAuftrag[(int)$r['auftrag_id']] ?? null; echo $nd !== null ? h($resNum($nd)) . ' ' . h($einheitLbl) : '<span class="muted">–</span>'; ?></td>
          <td style="white-space:nowrap"><?= $r['angelegt'] ? h(fmt_zeit((string)$r['angelegt'], 'd.m.Y')) : '–' ?></td>
          <td><?= $ausProd ? bx_badge('aus Produktion' . ($r['pa_nr'] ? ' ' . h((string)$r['pa_nr']) : ''), 'info') : bx_badge('manuell') ?></td>
          <td style="text-align:right">
            <?php if (!$ausProd): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Reservierung aufheben?');">
              <input type="hidden" name="aktion" value="res_storno"><input type="hidden" name="res_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-ghost btn-sm" type="submit">aufheben</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>

    <?php if ($resNurBedarf): ?>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Gezeigt werden nur Aufträge, die diesen Artikel laut Rezeptur <strong>brauchen</strong> (mit benötigter Menge, größter Bedarf zuerst). Beim Auswählen wird die Menge vorbelegt.</p>
    <?php else: ?>
      <p class="muted" style="font-size:12px;margin:10px 0 0">Kein offener Auftrag braucht diesen Artikel laut Rezeptur – daher werden alle laufenden Produktaufträge gezeigt.</p>
    <?php endif; ?>
    <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:10px">
      <input type="hidden" name="aktion" value="res_setzen">
      <div class="bx-field" style="margin:0;min-width:260px">
        <label>Auftrag</label>
        <select name="auftrag_id" id="resAuftrag" class="rscombo" required style="min-width:260px">
          <option value="">– Auftrag wählen –</option>
          <?php foreach ($res_auftraege as $a): $nd = $needJeAuftrag[(int)$a['id']] ?? null; ?>
            <option value="<?= (int)$a['id'] ?>"<?= $nd !== null ? ' data-need="' . h((string)(float)$nd) . '"' : '' ?>><?= h((string)$a['nummer']) ?><?= !empty($a['produkt_name']) ? ' · ' . h((string)$a['produkt_name']) : '' ?><?= $a['kunde_firma'] ? ' · ' . h((string)$a['kunde_firma']) : '' ?><?= $nd !== null ? ' · benötigt ' . $resNum($nd) . ' ' . $einheitLbl : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" style="margin:0">
        <label>Menge (<?= h($einheitLbl) ?>)</label>
        <input type="text" name="menge" id="resMenge" inputmode="decimal" placeholder="z. B. 25" style="width:120px;text-align:right">
      </div>
      <button class="btn btn-primary" type="submit">Reservieren</button>
      <span class="muted" style="font-size:12px;align-self:center">Reserviert die Menge für den gewählten Auftrag. Ein zweites Mal für denselben Auftrag ändert die Menge.</span>
    </form>
    <script>(function(){
      var sel=document.getElementById('resAuftrag'), m=document.getElementById('resMenge');
      if(!sel||!m) return;
      sel.addEventListener('change', function(){
        var o=sel.options[sel.selectedIndex], nd=o&&o.getAttribute('data-need');
        if(nd && (m.value===''||m.dataset.auto==='1')){ m.value=String(nd).replace('.',','); m.dataset.auto='1'; }
      });
      m.addEventListener('input', function(){ m.dataset.auto=''; });
    })();</script>
  </div>

  <?php // Fremdlager: Ware, die einem Kunden gehoert (Fulfillment). Liegt physisch bei uns, zaehlt aber NICHT
        // zu unserem Bestand und geht nicht in Berechnungen/Angebote/Produktion ein. Umbuchen = Menge aus
        // unserem Warenlager dem Kunden zuschreiben. ?>
  <?php if (!$neu): ?>
  <div class="bx-panel">
    <h2 style="margin:0">Fremdlager <span class="muted" style="font-weight:400;font-size:13px">Ware, die einem Kunden gehört – nicht unser Bestand, nicht für Produktion/Angebote</span></h2>
    <?php if ($fremdJeKunde): ?>
    <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
      <thead><tr><th>Kunde</th><th class="bx-num">Bestand</th></tr></thead>
      <tbody>
      <?php foreach ($fremdJeKunde as $fk): ?>
        <tr><td><?= kunde_link($fk['kunde_id'], $fk['firma'] ?: ('Kunde #' . (int)$fk['kunde_id'])) ?></td>
            <td class="bx-num"><?= h(rtrim(rtrim(number_format((float)$fk['menge'], 3, ',', '.'), '0'), ',')) ?> <?= h($fk['einheit'] ?? $it['einheit']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?><p class="muted" style="margin:10px 0 0;font-size:13px">Für diesen Artikel liegt noch keine Kundenware im Fremdlager.</p><?php endif; ?>

    <details style="margin-top:12px">
      <summary class="btn btn-ghost btn-sm" style="list-style:none;display:inline-block">Ins Fremdlager umbuchen</summary>
      <form method="post" onsubmit="return confirm('Menge aus dem Warenlager ins Fremdlager des Kunden umbuchen? Diese Menge zählt danach nicht mehr zu unserem Bestand.');">
        <input type="hidden" name="aktion" value="fremdlager_umbuchen">
        <div class="bx-grid" style="margin-top:10px">
          <div class="bx-field"><label>Kunde (Eigentümer)</label>
            <select name="fl_kunde" required><option value="">– wählen –</option>
              <?php foreach ($flKunden as $kk): ?><option value="<?= (int)$kk['id'] ?>"><?= h($kk['firma']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="bx-field"><label>Menge (<?= h($it['einheit']) ?>)</label><input type="number" step="0.001" min="0.001" name="fl_menge" required placeholder="max. <?= h(rtrim(rtrim(number_format($bestand_frei, 3, ',', '.'), '0'), ',')) ?>"></div>
          <div class="bx-field"><label>Charge (optional)</label><input type="text" name="fl_charge" placeholder="z. B. BF26801"></div>
          <div class="bx-field"><label>MHD (optional)</label><input type="date" name="fl_mhd"></div>
        </div>
        <div class="bx-row" style="margin-top:10px;align-items:center;gap:10px">
          <button class="btn btn-primary" type="submit">Umbuchen</button>
          <span class="muted" style="font-size:12px">Verfügbar im Warenlager: <?= h(rtrim(rtrim(number_format($bestand_frei, 3, ',', '.'), '0'), ',')) ?> <?= h($it['einheit']) ?></span>
        </div>
      </form>
    </details>
  </div>
  <?php endif; ?>
    <?php // Analysewerte je Charge – die Grundlage für unser CoA. Der Lieferant schickt sein
          // Zertifikat; die Werte tragen wir hier ein und geben sie im bulkify-Layout weiter. ?>
    <?php if ($charges): ?>
    <div class="bx-panel">
      <h2 style="margin-top:0">Analysenwerte je Charge <?= bx_hint('Aus dem CoA des Lieferanten übertragen. Daraus entsteht unser Analysenzertifikat im bulkify-Layout – das Original des Lieferanten geht nicht an den Kunden.') ?></h2>
      <?php if (isset($_GET['analyse'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Analysenwerte gespeichert.</div><?php endif; ?>
      <?php if ($anVorschlagFehler): ?><div style="border:1px solid #e6c4c0;color:#8f231b;padding:8px 12px;margin-bottom:10px;border-radius:8px"><?= h($anVorschlagFehler) ?></div><?php endif; ?>
      <?php if ($anVorschlag): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Vorschlag aus dem PDF eingetragen – bitte prüfen und dann speichern.<?= !empty($anVorschlag['kopf']) ? ' Gelesen: ' . h(implode(' · ', array_map(fn($kk, $vv) => $kk . ' ' . $vv, array_keys($anVorschlag['kopf']), $anVorschlag['kopf']))) : '' ?></div><?php endif; ?>
      <?php $liefDoks = all("SELECT id, typ, datei_orig FROM dokument WHERE objekt_typ='item' AND objekt_id=? ORDER BY id DESC", [(int)$id]);
            if ($liefDoks): ?>
      <form method="post" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
        <input type="hidden" name="aktion" value="analyse_lesen">
        <input type="hidden" name="charge_id" id="an_charge_lesen" value="<?= (int)($charges[0]['id'] ?? 0) ?>">
        <div class="bx-field" style="margin:0;min-width:280px"><label>Werte aus Lieferanten-PDF vorschlagen
          <?= bx_hint('Liest das hochgeladene CoA/Spec aus und trägt gefundene Werte ins Formular ein. Nur bei PDFs mit echtem Text – ein Scan lässt sich nicht lesen. Geprüft wird immer von Hand.') ?></label>
          <select name="dok_id"><?php foreach ($liefDoks as $d): ?><option value="<?= (int)$d['id'] ?>"><?= h(strtoupper($d['typ'])) ?> · <?= h($d['datei_orig']) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-ghost" type="submit">Werte vorschlagen</button>
      </form>
      <?php endif; ?>
      <div class="bx-field" style="max-width:320px"><label>Charge</label>
        <select id="an_charge" onchange="anZeige()">
          <?php foreach ($charges as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['charge_nr'] ?: ('Charge ' . (int)$c['id'])) ?></option><?php endforeach; ?>
        </select></div>
      <?php foreach ($charges as $c):
          $werte = all("SELECT * FROM charge_analyse WHERE charge_id=? ORDER BY sort, id", [(int)$c['id']]);
          // Vorschlagszeilen, damit man nicht vor einem leeren Blatt sitzt.
          // Ein Vorschlag aus dem Lieferanten-PDF ersetzt die Standardzeilen.
          if ($anVorschlag && (int)$anVorschlag['charge_id'] === (int)$c['id']) {
              $werte = array_map(fn($v) => ['parameter'=>$v[0], 'spezifikation'=>$v[1], 'ergebnis'=>$v[2], 'methode'=>$v[3]], $anVorschlag['zeilen']);
          } elseif (!$werte) $werte = [
              ['parameter'=>'Aussehen', 'spezifikation'=>'', 'ergebnis'=>'', 'methode'=>'visuell'],
              ['parameter'=>'Identität', 'spezifikation'=>'entspricht', 'ergebnis'=>'', 'methode'=>'FT-IR'],
              ['parameter'=>'Gehalt', 'spezifikation'=>'', 'ergebnis'=>'', 'methode'=>'HPLC'],
              ['parameter'=>'Schwermetalle', 'spezifikation'=>'', 'ergebnis'=>'', 'methode'=>'ICP-MS'],
              ['parameter'=>'Gesamtkeimzahl', 'spezifikation'=>'', 'ergebnis'=>'', 'methode'=>'Ph. Eur.'],
          ];
          while (count($werte) < count($werte) + 2) { $werte[] = ['parameter'=>'', 'spezifikation'=>'', 'ergebnis'=>'', 'methode'=>'']; if (count($werte) > 12) break; }
      ?>
      <form method="post" class="an_form" data-charge="<?= (int)$c['id'] ?>" style="display:none">
        <input type="hidden" name="aktion" value="analyse_save">
        <input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>">
        <div class="bx-tablewrap"><table class="bx-table">
          <thead><tr><th>Parameter</th><th>Spezifikation</th><th>Ergebnis</th><th>Methode</th></tr></thead>
          <tbody><?php foreach ($werte as $w): ?>
            <tr><td><input type="text" name="a_par[]" value="<?= h($w['parameter']) ?>" style="width:100%"></td>
                <td><input type="text" name="a_spec[]" value="<?= h($w['spezifikation'] ?? '') ?>" style="width:100%"></td>
                <td><input type="text" name="a_erg[]" value="<?= h($w['ergebnis'] ?? '') ?>" style="width:100%"></td>
                <td><input type="text" name="a_met[]" value="<?= h($w['methode'] ?? '') ?>" style="width:100%"></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <div class="bx-row" style="gap:10px;margin-top:10px">
          <button class="btn btn-primary" type="submit">Analysenwerte speichern</button>
          <a class="btn btn-ghost" target="_blank" href="?p=coa_bulkify&id=<?= (int)$c['id'] ?>">&#8681; CoA ansehen</a>
        </div>
      </form>
      <?php endforeach; ?>
      <script>
      function anZeige(){
        var s = document.getElementById('an_charge'), v = s.value;
        var hid = document.getElementById('an_charge_lesen'); if (hid) hid.value = v;
        document.querySelectorAll('.an_form').forEach(function(f){ f.style.display = (f.getAttribute('data-charge') === v) ? '' : 'none'; });
      }
      anZeige();
      </script>
    </div>
    <?php endif; ?>
</section>


<?php if (!$neu): ?>
<?php if (isset($_GET['preisok'])) echo '<div class="bx-panel badge-ok" data-panel="ek" hidden style="padding:12px 16px">Preis gespeichert.</div>'; ?>
<section data-panel="ek" hidden>
  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:center">
      <h2 style="margin:0">Lieferantenpreise (Staffel) <?= bx_hint('Angebote der Lieferanten je Menge – günstigster ist markiert. Basis für den Einkauf.') ?></h2>
      <div class="bx-row" style="gap:10px;align-items:center">
        <?= anfrage_badge((int)$id) ?>
        <?php if (!$neu && (string)($it['name'] ?? '') !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=lief_nachrichten&produkt=<?= rawurlencode((string)$it['name']) ?>">Lieferanten-Nachrichten</a><?php endif; ?>
        <button type="button" class="btn btn-primary btn-sm" data-name="<?= $v('name') ?>" onclick="bxAnfrageOeffnen(<?= (int)$id ?>,this)">Preis anfragen</button>
      </div>
    </div>
    <?php if (isset($_GET['angefragt'])): ?><div class="badge-ok" style="padding:8px 12px;margin:10px 0"><?= (int)$_GET['angefragt'] ?> Preisanfrage(n) verschickt<?= isset($_GET['gemailt']) && (int)$_GET['gemailt'] > 0 ? ', davon ' . (int)$_GET['gemailt'] . ' per E-Mail' : '' ?>.</div><?php endif; ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <?php $VERS = versandart_liste(); ?>
      <thead><tr><th>Lieferant</th><th>Kennung</th><th class="bx-num">ab Menge</th><th class="bx-num">Preis</th><th>Gültig</th><th>Incoterm</th><th>Versand</th><th>Stand</th><th></th></tr></thead>
      <tbody>
      <?php if (!$preise): ?><tr><td colspan="9" class="muted">Noch keine Preise. Unten eintragen oder per Preisanfrage einholen.</td></tr><?php endif; ?>
      <?php // „günstigster" nur unter den GÜLTIGEN Preisen (abgelaufene grau, nie still bevorzugt).
            $pgGueltig = array_filter($preise, fn($x) => pg_status($x['gueltig_bis'] ?? null) !== 'abgelaufen');
            $best = $pgGueltig ? min(array_map(fn($x) => (float)$x['preis'], $pgGueltig)) : ($preise ? (float)$preise[0]['preis'] : null);
            foreach ($preise as $pz): $pzAbg = pg_status($pz['gueltig_bis'] ?? null) === 'abgelaufen'; $ist_best = !$pzAbg && $best !== null && abs((float)$pz['preis'] - $best) < 0.0001; ?>
        <tr<?= $pzAbg ? ' style="opacity:.55"' : ($ist_best ? ' style="font-weight:600"' : '') ?>>
          <td><?= h($pz['firma'] ?: '–') ?> <?= $ist_best ? bx_badge('günstigster','ok') : '' ?>
            <?php if ($pzAbg && (int)$pz['lieferant_id'] > 0): ?><button type="button" class="btn btn-ghost btn-sm" style="margin-left:6px" data-name="<?= $v('name') ?>" data-lieferant="<?= (int)$pz['lieferant_id'] ?>"
              data-staffel="<?= h(implode(', ', array_map(fn($x) => rtrim(rtrim(number_format((float)$x['menge_ab'], 3, '.', ''), '0'), '.'), array_filter($preise, fn($x) => (int)$x['lieferant_id'] === (int)$pz['lieferant_id'] && (float)$x['menge_ab'] > 0)))) ?>"
              onclick="bxAnfrageOeffnen(<?= (int)$id ?>,this)">neu anfragen</button><?php endif; ?></td>
          <td class="muted"><?= h(rohstoff_lief_kennung($it['artikelnummer'] ?? '', $pz['lief_kuerzel'] ?? '') ?: '–') ?></td>
          <td class="bx-num"><?= rtrim(rtrim(number_format((float)$pz['menge_ab'],3,',','.'),'0'),',') ?> <?= h($it['einheit']) ?></td>
          <td class="bx-num"><?= h(preis_anzeige((float)$pz['preis'], (string)$pz['waehrung'], (string)$it['preis_bezug'])) ?></td>
          <td><?= pg_anzeige($pz['gueltig_bis'] ?? null) ?></td>
          <td><?= $pz['incoterm'] ? h($pz['incoterm']) : '<span class="muted">–</span>' ?></td>
          <td><?= !empty($pz['versandart']) ? h($VERS[$pz['versandart']] ?? $pz['versandart']) : '<span class="muted">–</span>' ?></td>
          <td><?= $pz['stand'] ? h(date('d.m.Y', strtotime($pz['stand']))) : '' ?></td>
          <td style="text-align:right"><form method="post" style="display:inline"><input type="hidden" name="aktion" value="preis_del"><input type="hidden" name="preis_id" value="<?= (int)$pz['id'] ?>"><button class="btn btn-ghost btn-sm" type="submit">×</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <form method="post" class="bx-row" style="margin-top:12px;align-items:flex-end;gap:10px">
      <input type="hidden" name="aktion" value="preis_add">
      <div class="bx-field" style="margin:0"><label>Lieferant</label>
        <select name="lp_lieferant" required><option value="">– wählen –</option>
          <?php foreach ($preis_lieferanten as $l): ?><option value="<?= $l['id'] ?>"><?= h($l['firma']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field" style="margin:0;width:120px"><label>ab Menge</label><input type="number" step="0.001" name="lp_menge_ab" value="0"></div>
      <div class="bx-field" style="margin:0;width:200px"><label>Preis <?= bx_hint('Bei Kleinstpreisen (z. B. Leerkapseln) lieber je 1.000 Stk eingeben: 2,95 je 1.000 wird als 0,002950 je Stk gespeichert.') ?></label>
        <div class="bx-row" style="gap:6px;flex-wrap:nowrap"><input type="text" inputmode="decimal" name="lp_preis" required style="width:90px">
        <select name="lp_basis" style="width:auto"><option value="1">je <?= h($it['preis_bezug'] ?? 'Stk') ?></option><option value="1000">je 1.000 <?= h($it['preis_bezug'] ?? 'Stk') ?></option></select></div></div>
      <div class="bx-field" style="margin:0;width:150px"><label>Incoterm <?= bx_hint('Lieferbedingung: EXW = ab Werk (du zahlst Fracht+Zoll), DDP = frei Haus alles inkl. Preise nur mit gleichem Incoterm vergleichbar.') ?></label>
        <select name="lp_incoterm"><option value="">– offen –</option><?php foreach (incoterm_liste() as $k=>$lbl): ?><option value="<?= $k ?>"><?= h($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="bx-field" style="margin:0;width:130px"><label>Versandart</label>
        <select name="lp_versandart"><option value="">– offen –</option><?php foreach (versandart_liste() as $k=>$lbl): ?><option value="<?= $k ?>"><?= h($lbl) ?></option><?php endforeach; ?></select></div>
      <?= pg_eingabe('lp_gueltig_bis', pg_vorbelegung((int)$id)) ?>
      <button class="btn btn-ghost btn-sm" type="submit">Preis hinzufügen</button>
    </form>
    <?php // Preisverlauf (Teil 3): ersetzte/gelöschte Preise bleiben als Historie sichtbar - z. B. wie sich Whey entwickelt.
      $pvRows = [];
      try { $pvRows = all("SELECT v.*, COALESCE(l.firma, v.lieferant_name, '') AS firma FROM lieferant_preis_verlauf v LEFT JOIN lieferanten l ON l.id=v.lieferant_id
                           WHERE v.item_id=? ORDER BY COALESCE(v.stand, DATE(v.archiviert_am)) DESC, v.id DESC LIMIT 200", [(int)$id]); } catch (Throwable $e) {}
      $pvAlle = array_merge(array_map(fn($x) => $x + ['_aktuell' => 1], $preise), array_map(fn($x) => $x + ['_aktuell' => 0], $pvRows));
      usort($pvAlle, fn($a, $b) => strcmp((string)($b['stand'] ?? ''), (string)($a['stand'] ?? '')));
      if ($pvAlle): ?>
    <details style="margin-top:14px"><summary style="cursor:pointer">Preisverlauf (<?= count($pvAlle) ?>)</summary>
      <div class="bx-tablewrap" style="margin-top:8px"><table class="bx-table" style="font-size:13px">
        <thead><tr><th>Datum</th><th>Lieferant</th><th class="bx-num">ab Menge</th><th class="bx-num">Preis</th><th>Gültig bis</th><th></th></tr></thead>
        <tbody><?php foreach ($pvAlle as $pv): ?>
          <tr<?= !$pv['_aktuell'] ? ' style="opacity:.7"' : '' ?>><td><?= $pv['stand'] ? h(date('d.m.Y', strtotime((string)$pv['stand']))) : '–' ?></td><td><?= h((string)($pv['firma'] ?: '–')) ?></td>
            <td class="bx-num"><?= rtrim(rtrim(number_format((float)$pv['menge_ab'], 3, ',', '.'), '0'), ',') ?></td>
            <td class="bx-num"><?= number_format((float)$pv['preis'], (float)$pv['preis'] < 1 ? 4 : 2, ',', '.') ?> <?= h((string)($pv['waehrung'] ?: 'EUR')) ?></td>
            <td><?= pg_anzeige($pv['gueltig_bis'] ?? null) ?></td><td class="muted" style="font-size:12px"><?= $pv['_aktuell'] ? 'aktuell' : 'ersetzt ' . h(fmt_zeit($pv['archiviert_am'], 'd.m.Y')) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </details>
    <?php endif; ?>
  </div>
</section>
<?php if (!$neu):
    $bstN = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $bstD = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '–';
    $bstSumme = []; foreach ($bstRows as $r) if ((int)$r['offen'] === 1 && (string)$r['status'] !== 'offen') { $e = (string)($r['einheit'] ?: ($it['einheit'] ?? '')); $bstSumme[$e] = ($bstSumme[$e] ?? 0) + max(0.0, (float)$r['menge'] - (float)$r['geliefert']); } ?>
<section data-panel="bestellt" hidden>
  <div class="bx-panel">
    <h2 style="margin-top:0">Bestellt</h2>
    <p class="muted" style="margin-top:0">Alle Bestellungen dieses Artikels, offene zuerst.
      <?php if ($bstSumme): ?>Noch unterwegs: <strong><?= h(implode(' · ', array_map(fn($e, $m) => $bstN($m) . ' ' . $e, array_keys($bstSumme), $bstSumme))) ?></strong><?php else: ?>Zurzeit ist nichts unterwegs.<?php endif; ?></p>
    <?php if (!$bstRows): ?><div class="muted">Dieser Artikel wurde noch nie bestellt.</div>
    <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Bestellung</th><th>Lieferant</th><th>Status</th><th>Bestellt am</th><th>Erwartet / angekommen</th><th class="bx-num">Menge</th><th class="bx-num">Geliefert</th><th class="bx-num">Offen</th><th>Für Auftrag</th></tr></thead>
      <tbody>
      <?php foreach ($bstRows as $r): $e = (string)($r['einheit'] ?: ($it['einheit'] ?? '')); $offenM = max(0.0, (float)$r['menge'] - (float)$r['geliefert']); $istOffen = (int)$r['offen'] === 1; ?>
        <tr<?= $istOffen ? '' : ' class="muted"' ?>>
          <td><a href="?p=bestellung&id=<?= (int)$r['bid'] ?>"><?= h((string)$r['nummer']) ?></a></td>
          <td style="white-space:normal"><?= h($r['lieferant'] !== '' ? $r['lieferant'] : ((string)$r['bestellart'] === 'online' ? 'Online: ' . (string)$r['online_haendler'] : '–')) ?></td>
          <td><?= $istOffen ? ((string)$r['status'] === 'offen' ? bx_badge('Entwurf', '') : bx_badge(status_text((string)$r['status']), 'warn')) : bx_badge(status_text((string)$r['status']), (string)$r['status'] === 'storniert' ? '' : 'ok') ?></td>
          <td><?= h($bstD($r['bestelldatum'] ?: substr((string)$r['angelegt'], 0, 10))) ?></td>
          <td><?= $r['angekommen_am'] ? 'angekommen ' . h($bstD($r['angekommen_am'])) : ($r['eta_geplant'] ? 'erwartet ' . h($bstD($r['eta_geplant'])) : '–') ?></td>
          <td class="bx-num"><?= h($bstN($r['menge']) . ' ' . $e) ?></td>
          <td class="bx-num"><?= (float)$r['geliefert'] > 0 ? h($bstN($r['geliefert']) . ' ' . $e) : '–' ?></td>
          <td class="bx-num"><?= $istOffen && $offenM > 0 ? '<strong>' . h($bstN($offenM) . ' ' . $e) . '</strong>' : '–' ?></td>
          <td><?= $r['auftrag_nr'] ? '<a href="?p=auftrag&id=' . (int)$r['auftrag_id'] . '">' . h((string)$r['auftrag_nr']) . '</a>' : '<span class="muted">Lager</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="muted" style="font-size:12px;margin-top:8px">Entwurf = angelegt, aber noch nicht an den Lieferanten geschickt (zählt nicht als unterwegs).</div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<section data-panel="dok" hidden><?php dokument_panel('item', (int)$id, $lieferanten); ?>
  <?php // Unsere bulkify-Dokumente: Spezifikation (immer, allgemein) + CoA je Charge mit Analysewerten.
        // Liegt bewusst AUCH hier im Dokumente-Reiter. Das Original des Lieferanten geht nie an den Kunden.
    $coaCharges = $neu ? [] : all("SELECT c.id, c.charge_nr, c.mhd, c.coa_freigegeben
        FROM charge c WHERE c.item_id=? AND EXISTS (SELECT 1 FROM charge_analyse a WHERE a.charge_id=c.id)
        ORDER BY c.id DESC", [(int)$id]);
    if (!$neu): $specFreiDok = (int)($it['spec_freigegeben'] ?? 0) === 1; $su = '?p=spec_bulkify&id=' . (int)$id; ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Unsere Dokumente (bulkify-Layout) <?= bx_hint('Unsere eigenen Kundendokumente aus den erfassten Daten: Spezifikation (allgemein, immer verfügbar) und – je Charge mit Analysewerten – das CoA. Das Original des Lieferanten bleibt intern.') ?></h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Dokument</th><th>Charge</th><th>MHD</th><th>Kundenfreigabe</th><th></th></tr></thead>
      <tbody>
        <tr>
          <td><strong>Spezifikation</strong></td>
          <td class="muted">allgemein</td>
          <td class="muted">–</td>
          <td><?= $specFreiDok ? bx_badge('freigegeben','ok') : bx_badge('intern','warn') ?></td>
          <td class="bx-num" style="white-space:nowrap">
            <a class="btn btn-ghost btn-sm" target="_blank" href="<?= h($su) ?>">Ansehen</a>
            <a class="btn btn-ghost btn-sm" href="<?= h($su) ?>" download>Download</a>
          </td>
        </tr>
        <?php foreach ($coaCharges as $cc): $u = '?p=coa_bulkify&id=' . (int)$cc['id']; ?>
          <tr>
            <td><strong>CoA</strong></td>
            <td><?= h($cc['charge_nr'] ?: ('Charge ' . (int)$cc['id'])) ?></td>
            <td><?= $cc['mhd'] ? h(date('d.m.Y', strtotime((string)$cc['mhd']))) : '<span class="muted">–</span>' ?></td>
            <td><?= (int)$cc['coa_freigegeben'] === 1 ? bx_badge('freigegeben','ok') : bx_badge('intern','warn') ?></td>
            <td class="bx-num" style="white-space:nowrap">
              <a class="btn btn-ghost btn-sm" target="_blank" href="<?= h($u) ?>">Ansehen</a>
              <a class="btn btn-ghost btn-sm" href="<?= h($u) ?>" download>Download</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$coaCharges): ?><tr><td><span class="muted">CoA</span></td><td colspan="4" class="muted" style="font-size:12px">Noch kein CoA – entsteht je Charge, sobald Analysenwerte erfasst sind (Reiter „Chargen").</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <p class="muted" style="font-size:12px;margin-top:8px">Spezifikation-Freigabe im Reiter „Spezifikation", CoA-Freigabe im Reiter „Chargen". Lieferanten-Originale bleiben intern.</p>
  </div>
  <?php // CoA/Spezifikationen, die an Bestellungen dieses Rohstoffs hochgeladen wurden (Lieferanten-Originale, intern).
    require_once BX_ROOT . '/core/bestellung_chargen.php';
    $bestDoks = bch_item_dokumente((int)$id);
    if ($bestDoks): ?>
  <div class="bx-panel">
    <h2 style="margin-top:0">Unterlagen aus Bestellungen <?= bx_hint('CoA und Spezifikationen des Lieferanten, die an einer Bestellung hochgeladen wurden. Sie ändern die Spezifikation des Rohstoffs nicht – ergänzt wird beim Prüfen nur, was hier noch fehlt.') ?></h2>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Dokument</th><th>Charge</th><th>Bestellung</th><th>Hochgeladen</th><th>Prüfung</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($bestDoks as $bd): $stBd = (string)$bd['typ'] === 'coa' ? bch_coa_stand($bd) : ''; ?>
        <tr>
          <td><strong><?= (string)$bd['typ'] === 'coa' ? 'CoA' : 'Spezifikation' ?></strong> <span class="muted" style="font-size:12px"><?= h((string)($bd['datei_orig'] ?? '')) ?></span></td>
          <td><?= ($bd['charge_nr'] ?? '') !== '' && $bd['charge_nr'] !== null ? h((string)$bd['charge_nr']) : '<span class="muted">–</span>' ?></td>
          <td><a href="?p=bestellung&id=<?= (int)$bd['bestellung_id'] ?>#chargen"><?= h((string)$bd['bestellung_nr']) ?></a><?= !empty($bd['lieferant']) ? ' <span class="muted" style="font-size:12px">· ' . h((string)$bd['lieferant']) . '</span>' : '' ?></td>
          <td class="muted"><?= h(date('d.m.Y', strtotime((string)$bd['angelegt']))) ?></td>
          <td><?= $stBd === 'geprueft' ? bx_badge((string)($bd['befund'] ?? '') === 'auffaellig' ? 'geprüft · Abweichung' : 'geprüft', (string)($bd['befund'] ?? '') === 'auffaellig' ? 'err' : 'ok') : ($stBd !== '' ? bx_badge('noch nicht geprüft', 'warn') : '<span class="muted">–</span>') ?></td>
          <td class="bx-num"><a class="btn btn-ghost btn-sm" target="_blank" href="?p=dokument&id=<?= (int)$bd['id'] ?>">öffnen</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</section>
<?php anfrage_modal(lieferanten_fuer_anfrage(($it['form'] ?? '') === 'kapselhuelle' ? 'leerkapsel' : 'rohstoff'), '?p=rohstoff&id=' . (int)$id . '&tab=ek'); ?>
<?php endif; ?>

<script>
(function(){
  var tabs = document.querySelectorAll('#itabs a');
  tabs.forEach(function(t){
    t.addEventListener('click', function(e){
      e.preventDefault();
      tabs.forEach(function(x){ x.classList.remove('on'); });
      t.classList.add('on');
      document.querySelectorAll('[data-panel]').forEach(function(p){
        p.hidden = (p.getAttribute('data-panel') !== t.getAttribute('data-tab'));
      });
      var at = document.getElementById('activeTab'); if (at) at.value = t.getAttribute('data-tab');
    });
  });
  var urlTab = new URLSearchParams(location.search).get('tab');
  if (urlTab) { var t0 = document.querySelector('#itabs a[data-tab="' + urlTab + '"]'); if (t0) t0.click(); }
  // Kapselhülle: eigene Felder nur bei Form=kapselhuelle zeigen
  var formSel = document.getElementById('f_form');
  function applyForm(fromUser){
    var isKap = formSel && formSel.value === 'kapselhuelle';
    document.querySelectorAll('[data-only="kapselhuelle"]').forEach(function(el){ el.style.display = isKap ? '' : 'none'; });
    if (isKap && fromUser){
      var eh=document.querySelector('select[name="einheit"]'); if (eh && eh.value==='kg') eh.value='Stk';
      var pb=document.querySelector('select[name="preis_bezug"]'); if (pb && pb.value==='kg') pb.value='Stk';
    }
  }
  if (formSel) formSel.addEventListener('change', function(){ applyForm(true); });
  applyForm(false);
  var addW = document.getElementById('addWirk');
  if (addW) addW.addEventListener('click', function(){
    var row = document.createElement('div');
    row.className = 'bx-row wirkrow';
    row.style.cssText = 'flex-wrap:nowrap;margin-bottom:8px';
    row.innerHTML = '<input type="text" name="wirk_name[]" list="naehrstoffliste" placeholder="Wirkstoff, z. B. Magnesium" style="flex:1">'
      + '<input type="number" step="any" name="wirk_gehalt[]" placeholder="Gehalt" style="width:110px">'
      + '<select name="wirk_einheit[]" style="width:110px"><option value="prozent">% (m/m)</option><option value="ie_g">I.E./g</option><option value="ie_kg">I.E./kg</option><option value="mg_g">mg/g</option><option value="ug_g">µg/g</option></select>'
      + '<button type="button" class="btn btn-ghost btn-sm">entfernen</button>';
    row.querySelector('button').addEventListener('click', function(){ row.remove(); });
    document.getElementById('wirkrows').appendChild(row);
  });
  var addK = document.getElementById('addKw');
  if (addK) addK.addEventListener('click', function(){
    var row = document.createElement('div');
    row.className = 'bx-row kwrow';
    row.style.cssText = 'flex-wrap:nowrap;margin-bottom:8px';
    row.innerHTML = '<input type="text" name="kw_param[]" placeholder="Parameter, z. B. Fettgehalt" style="flex:1">'
      + '<input type="text" name="kw_wert[]" placeholder="Wert, z. B. 10–12 %" style="flex:1">'
      + '<button type="button" class="btn btn-ghost btn-sm">entfernen</button>';
    row.querySelector('button').addEventListener('click', function(){ row.remove(); });
    document.getElementById('kwrows').appendChild(row);
  });
  var addG = document.getElementById('addGw');
  if (addG) addG.addEventListener('click', function(){
    var row = document.createElement('div');
    row.className = 'bx-row gwrow';
    row.style.cssText = 'flex-wrap:nowrap;margin-bottom:8px';
    row.innerHTML = '<input type="text" name="gw_param[]" placeholder="Parameter, z. B. Blei (Pb)" style="flex:1">'
      + '<input type="text" name="gw_wert[]" placeholder="Grenzwert, z. B. ≤ 3 ppm" style="flex:1">'
      + '<button type="button" class="btn btn-ghost btn-sm">entfernen</button>';
    row.querySelector('button').addEventListener('click', function(){ row.remove(); });
    document.getElementById('gwrows').appendChild(row);
  });
})();
</script>
<?php if ($neu): // Teil 5 (10.11.2026): Dublettenwarnung „Meintest du …?“ beim Anlegen ?>
<script>
(function(){
  var f=document.querySelector('input[name="name"]'); if(!f) return;
  var box=document.createElement('div'); box.className='muted'; box.style.cssText='font-size:12px;margin-top:6px'; f.parentNode.appendChild(box);
  var t=null, kat=document.querySelector('select[name="kategorie"]');
  function esc(x){ return String(x==null?'':x).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function pruefen(){
    var v=(f.value||'').trim(); if(v.length<4){ box.innerHTML=''; return; }
    fetch('?p=artikel_zusammenfuehren&treffer='+encodeURIComponent(v)+'&kat='+encodeURIComponent(kat?kat.value:'')).then(function(r){return r.json();}).then(function(j){
      var tr=(j&&j.treffer)||[];
      box.innerHTML = tr.length ? '<span style="color:#8f231b">Meintest du …?</span> ' + tr.map(function(x){ return '<a href="?p=rohstoff&id='+x.id+'" target="_blank" rel="noopener">'+esc(x.name)+'</a> <span class="muted">('+esc(x.kat)+(x.kapsel?' · '+esc(x.kapsel):'')+', '+esc(x.einheit)+')</span>'; }).join(' · ')
        + '<div>Gleiche Qualität von einem anderen Lieferanten bitte als Lieferantenpreis am vorhandenen Artikel erfassen – neu anlegen nur bei anderer Spezifikation, Kapselgröße oder Füllgewicht.</div>' : '';
    }).catch(function(){});
  }
  f.addEventListener('input', function(){ clearTimeout(t); t=setTimeout(pruefen, 350); });
  if(kat) kat.addEventListener('change', pruefen);
})();
</script>
<?php endif; ?>
<?php
render_footer();
