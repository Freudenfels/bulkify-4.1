<?php
// Bestellung anlegen & bearbeiten – Positionen + Aktionen (bestellen / Wareneingang)
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/ki.php';

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

// Stand des CoA-Auslesens fürs Prüf-Popup (JSON, fragt alle 4 s nach).
if (!$neu && isset($_GET['coa_stand'])) {
    require_once BX_ROOT . '/core/bestellung_chargen.php';
    $cd = one("SELECT * FROM dokument WHERE id=? AND objekt_typ='bestellung' AND objekt_id=? AND typ='coa'", [(int)$_GET['coa_stand'], (int)$id]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['stand' => $cd ? bch_coa_stand($cd) : 'fehler']); exit;
}
if (isset($_GET['la_pdf']) && ($laBg = one("SELECT * FROM bestellung WHERE id=?", [(int)($_GET['id'] ?? 0)]))) {   // Paket 12.11.2026 Teil 5: Aufkleber-PDF
    require_once BX_ROOT . '/core/lief_aufkleber_ui.php';
    if (la_pdf_get($laBg)) exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    $lief = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
    // Fable-6 P11 (13.11.2026): Der Server prüft den Stand der Bestellung je Aktion (nicht nur die Oberfläche).
    if (!$neu && $aktion !== '') {
        $gB = one("SELECT status, angekommen_am, bezahlt_am FROM bestellung WHERE id=?", [(int)$id]);
        $gGel = (float) scalar("SELECT COALESCE(SUM(geliefert_menge),0) FROM bestellung_position WHERE bestellung_id=?", [(int)$id]);
        $gOffenRest = (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=? AND menge>0 AND rest_storniert_am IS NULL AND COALESCE(geliefert_menge,0) < menge - 0.0001", [(int)$id]);
        $gNein = '';
        if ($gB && (string)$gB['status'] === 'storniert'
            && (in_array($aktion, ['team_rechnung', 'bezahlt', 'lief_versand', 'lief_station', 'lief_bestaetigen', 'telefonisch_bestaetigt', 'eta_setzen', 'erinnern', 'bestellt', 'schon_bestellt', 'rest_storno', 'abgl_geliefert', 'liefern', 'aufteilen'], true)
                || strncmp($aktion, 'bch_', 4) === 0 || strncmp($aktion, 'la_', 3) === 0))
            $gNein = 'Die Bestellung ist storniert – diese Aktion ist nicht mehr möglich.';
        elseif ($aktion === 'storno' && $gGel > 0.0005)
            $gNein = 'Es ist schon Ware eingegangen. Bitte „Rest stornieren“ (mit Grund) statt die ganze Bestellung zu stornieren.';
        elseif ($aktion === 'rest_storno' && !(has_role('admin') || has_role('einkauf')))
            $gNein = '„Rest stornieren“ dürfen nur Einkauf und Admin.';
        elseif ($aktion === 'rest_storno' && ($gGel <= 0.0005 || $gOffenRest === 0))
            $gNein = '„Rest stornieren“ geht nur bei einer echten Teillieferung (schon etwas geliefert, Rest offen).';
        elseif ($aktion === 'rest_storno' && trim((string)($_POST['grund'] ?? '')) === '')
            $gNein = 'Bitte einen Grund für „Rest stornieren“ angeben.';
        elseif ($aktion === 'bezahlt' && $gB && !empty($gB['bezahlt_am']))
            $gNein = 'Die Bestellung ist schon als bezahlt markiert (' . date('d.m.Y', strtotime((string)$gB['bezahlt_am'])) . ').';
        elseif ($aktion === 'bezahlt' && trim((string)($_POST['bezahlt_am'] ?? '')) !== '' && (string)$_POST['bezahlt_am'] > heute_ort())
            $gNein = '„bezahlt am“ darf nicht in der Zukunft liegen.';
        if ($gNein !== '') {
            log_aktivitaet('bestellung', (int)$id, 'team', 'Abgelehnt (' . $aktion . '): ' . $gNein, 'notiz');
            header('Location: ?p=bestellung&id=' . (int)$id . '&fehler=' . urlencode($gNein)); exit;
        }
    }
    // Einkauf gerade ziehen (Teil 1, 10.11.2026): „Wareneingang buchen" führt in den EINEN Wareneingang mit vorgewählter Position
    // (Charge, MHD, Menge echt erfasst) – kein pauschales Einbuchen „zu BE-…" mehr.
    if (strncmp($aktion, 'la_', 3) === 0 && !$neu) {   // Paket 12.11.2026 Teil 5: Gebindeaufkleber (Direkt-Bestellungen, Nachdruck)
        require_once BX_ROOT . '/core/lief_aufkleber_ui.php';
        $laB = one("SELECT * FROM bestellung WHERE id=?", [(int)$id]);
        if ($laB && ($laR = la_post($laB, 'team', 'de')) !== null) la_post_ausgeben($laB, $laR, '?p=bestellung&id=' . (int)$id);
    }
    if (in_array($aktion, ['bk_neu', 'bk_storno'], true) && !$neu && ($bkB = one("SELECT * FROM bestellung WHERE id=?", [(int)$id]))) {   // Reiter Kosten (13.11.2026)
        require_once BX_ROOT . '/core/bestellung_kosten.php';
        $rK = bk_post($bkB, (string)(current_user()['name'] ?? 'Team'));
        header('Location: ?p=bestellung&id=' . (int)$id . (!empty($rK['ok']) ? '&okmsg=' : '&fehler=') . rawurlencode((string)($rK['msg'] ?? '')) . '#kosten'); exit;
    }
    if ($aktion === 'liefern' && !$neu) {
        $bpErst = (int) scalar("SELECT id FROM bestellung_position WHERE bestellung_id=? AND menge>0 AND COALESCE(geliefert_menge,0) < menge AND rest_storniert_am IS NULL ORDER BY sort, id LIMIT 1", [(int)$id]);
        header('Location: ?p=wareneingang' . ($bpErst ? '&bp=' . $bpErst : '')); exit;
    }
    // Teil 4 (Altlasten, nur auf Klick): vorhandene Charge mit einer Position verknüpfen bzw. ohne Charge als geliefert schließen.
    if ($aktion === 'abgl_verknuepfen' && !$neu) {
        $bpA = (int)($_POST['bp_id'] ?? 0); $cA = (int)($_POST['charge_id'] ?? 0);
        $okA = (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE id=? AND bestellung_id=?", [$bpA, (int)$id]) === 1
            && (int) scalar("SELECT COUNT(*) FROM charge WHERE id=? AND bestellung_position_id IS NULL", [$cA]) === 1;
        if ($okA) {
            webe_charge_verknuepfen($cA, $bpA, false);
            webe_nachtraeglich_eingebucht_vermerken($bpA, $cA, (string)(current_user()['name'] ?? ''));   // Sofortfix 13.11.2026
            aenderung_protokollieren('charge', $cA, ['bestellung_position_id' => null], ['bestellung_position_id' => $bpA], 'bestellung_abgleich');
            log_aktivitaet('bestellung', (int)$id, 'team', 'Charge ' . (string) scalar("SELECT COALESCE(charge_nr, CONCAT('#', id)) FROM charge WHERE id=?", [$cA]) . ' mit der Bestellung verknüpft (Altlasten-Abgleich).', 'status');
        }
        header('Location: ?p=bestellung&id=' . $id . ($okA ? '&ok=1' : '&fehler=' . urlencode('Charge oder Position nicht (mehr) passend – nichts geändert.')) . '#abgleich'); exit;
    }
    // Block B (11.11.2026): Menge der Charge korrigieren und dann verknüpfen / Verknüpfung lösen (gleiche Funktion wie die Datenprüfung).
    if (in_array($aktion, ['abgl_korr_verknuepfen', 'abgl_loesen'], true) && !$neu) {
        require_once BX_ROOT . '/core/bestellung_abgleich.php';
        $rK = bestellung_abgleich_aktion($aktion === 'abgl_loesen' ? 'loesen' : 'korr_verknuepfen', (int)$id, (int)($_POST['bp_id'] ?? 0), (int)($_POST['charge_id'] ?? 0));
        if ($rK['ok'] && function_exists('bedarf_bump')) bedarf_bump();
        header('Location: ?p=bestellung&id=' . $id . ($rK['ok'] ? '&okmsg=' : '&fehler=') . rawurlencode((string)$rK['msg']) . '#' . ($aktion === 'abgl_loesen' ? 'chargenbezug' : 'abgleich')); exit;
    }
    if ($aktion === 'abgl_geliefert' && !$neu) {
        // Sofortfix 13.11.2026: nur Admin, nur mit Grund (Pop-up „Ist die Ware im Lager?“ -> „Nein, kam nie / nicht mehr nötig“).
        $grundG = trim((string)($_POST['grund'] ?? ''));
        $zurG = (string)($_POST['zurueck'] ?? '');
        if (!preg_match('/^\?p=[a-z_]+(&[A-Za-z0-9_=%#-]*)*$/', $zurG)) $zurG = '?p=bestellung&id=' . $id;
        if (!has_role('admin')) { header('Location: ' . $zurG . (str_contains($zurG, '?') ? '&' : '?') . 'fehler=' . urlencode('Ohne Einbuchen schließen darf nur ein Admin. Ist die Ware da: „Ware ist da - einbuchen“.')); exit; }
        if ($grundG === '') { header('Location: ' . $zurG . '&fehler=' . urlencode('Bitte einen Grund angeben (kam nie / nicht mehr nötig).')); exit; }
        webe_als_geliefert_schliessen((int)$id, (string)(current_user()['name'] ?? ''));
        log_aktivitaet('bestellung', (int)$id, 'team', 'Ohne Einbuchen geschlossen (Altlast, keine Charge, Rest storniert). Grund: ' . mb_substr($grundG, 0, 200), 'status');
        header('Location: ' . $zurG . '&ok=1'); exit;
    }
    // Teil 3: Rest einer Teillieferung stornieren – die Bestellung schließt, der Rest zählt nicht mehr als unterwegs.
    if ($aktion === 'rest_storno' && !$neu) {
        webe_rest_stornieren((int)$id, (string)(current_user()['name'] ?? ''));
        log_aktivitaet('bestellung', (int)$id, 'team', 'Rest storniert – offene Restmengen zählen nicht mehr als unterwegs. Grund: ' . mb_substr(trim((string)($_POST['grund'] ?? '')), 0, 200), 'status');
        header('Location: ?p=bestellung&id=' . $id . '&ok=1'); exit;
    }
    // Ablauf beim Lieferanten – dieselben Aktionen nutzt spaeter das Lieferantenportal.
    // Über das Portal gesendet, Lieferant hat aber telefonisch bestätigt: bestellt + bestätigt, ohne weitere Mail (08.11.2026).
    // Liefertermin setzen/ändern (Team) – v. a. bei „direkt bestellt", wo kein Lieferant im Portal einen Termin zusagt.
    // Muss nach dem Bestelltag liegen; leer = kein Termin.
    if ($aktion === 'eta_setzen' && !$neu) {
        $eta = trim((string)($_POST['eta'] ?? ''));
        $bd  = (string) scalar("SELECT COALESCE(bestelldatum, DATE(angelegt)) FROM bestellung WHERE id=?", [(int)$id]);
        if ($eta !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eta) || ($bd !== '' && $eta <= $bd))) {
            header('Location: ?p=bestellung&id=' . $id . '&fehler=' . urlencode('Der Termin muss nach dem Bestelltag (' . ($bd !== '' ? date('d.m.Y', strtotime($bd)) : '–') . ') liegen.')); exit;
        }
        q("UPDATE bestellung SET eta_geplant=? WHERE id=?", [$eta !== '' ? $eta : null, (int)$id]);
        log_aktivitaet('bestellung', (int)$id, 'team', $eta !== '' ? 'Liefertermin auf ' . date('d.m.Y', strtotime($eta)) . ' gesetzt.' : 'Liefertermin entfernt.', 'status');
        bedarf_bump();
        header('Location: ?p=bestellung&id=' . $id . '&ok=1'); exit;
    }
    if ($aktion === 'telefonisch_bestaetigt' && !$neu) {
        $f = bestellung_telefonisch_bestaetigen((int)$id);
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
    }
    if ($aktion === 'lief_bestaetigen' && !$neu) {
        $f = bestellung_bestaetigen((int)$id, (string)($_POST['eta_geplant'] ?? ''), trim((string)($_POST['wer'] ?? 'Team')), 'de', 'team', trim((string)($_POST['lu_grund'] ?? '')));   // Fable-3 T2.2: Sperre zentral
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
    }
    if ($aktion === 'lief_station' && !$neu) {
        $f = bestellung_station_setzen((int)$id, (string)($_POST['station'] ?? ''), null);
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
    }
    if ($aktion === 'lief_versand' && !$neu) {
        q("UPDATE bestellung SET produktion_geplant=?, versandanbieter=?, versandart=?, tracking=? WHERE id=?", [
            trim((string)($_POST['produktion_geplant'] ?? '')) ?: null,
            mb_substr(trim((string)($_POST['versandanbieter'] ?? '')), 0, 60) ?: null,
            array_key_exists((string)($_POST['versandart'] ?? ''), versandart_liste()) ? $_POST['versandart'] : null,
            mb_substr(trim((string)($_POST['tracking'] ?? '')), 0, 120) ?: null, (int)$id]);
        if (isset($_POST['pakete_angekuendigt']) && ctype_digit(trim((string)$_POST['pakete_angekuendigt'])))
            q("UPDATE bestellung SET pakete_angekuendigt=? WHERE id=?", [(int)$_POST['pakete_angekuendigt'], (int)$id]);
        header('Location: ?p=bestellung&id=' . $id . '&ok=1'); exit;
    }
    if ($aktion === 'lu_upload' && !$neu) {   // Paket 12.11.2026 Teil 4: Unterlage intern nachreichen (Liste „Unterlagen fehlen“)
        require_once BX_ROOT . '/core/lieferant_unterlagen.php';
        $lidB = (int) scalar("SELECT lieferant_id FROM bestellung WHERE id=?", [(int)$id]);
        $r = lu_upload_post($lidB, 'team');
        header('Location: ?p=bestellung&id=' . $id . ($r['ok'] ? '&ok=1' : '&fehler=' . urlencode('Datei nicht gespeichert (PDF oder Bild).')) . '#unterlagen');
        lu_upload_nachlauf((int)$r['did']);
        exit;
    }
    // Sofortfix 13.11.2026 Teil 4: alter Sperr-Entwurf (unterlagen_wartet=1) war schon beim Lieferanten bestellt – Abschluss wie
    // „heute bestellt“ (Direkt bestellt, ohne Mail) mit dem Datum aus dem Feld; das Kennzeichen fällt weg.
    if ($aktion === 'schon_bestellt' && !$neu) {
        $dS = (string)($_POST['datum'] ?? '');
        $fS = bestellung_direkt_abschliessen((int)$id, preg_match('/^\d{4}-\d{2}-\d{2}$/', $dS) ? $dS : null, null, 'Schon bestellt (alter Entwurf aus der Unterlagen-Sperre)', (string)(current_user()['name'] ?? ''));
        if ($fS === '') q("UPDATE bestellung SET unterlagen_wartet=0 WHERE id=?", [(int)$id]);
        header('Location: ?p=bestellung&id=' . $id . ($fS === '' ? '&ok=1' : '&fehler=' . urlencode($fS))); exit;
    }
    if ($aktion === 'bestellt' && !$neu) {
        // 13.11.2026 (Regel Nico): fehlende Unterlagen sperren nicht mehr – bestellung_als_bestellt_markieren() legt nur die Aufgabe
        // „Unterlagen nachfordern“ an und mailt; die Ware bleibt nach dem Eingang in Quarantäne, bis die Unterlage da ist.
        $rB = bestellung_als_bestellt_markieren((int)$id, '', (string)(current_user()['name'] ?? ''));
        if (!$rB['ok']) { header('Location: ?p=bestellung&id=' . $id . '&fehler=' . urlencode($rB['msg']) . '#unterlagen'); exit; }
        $f = (string)$rB['msg'];
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode('Bestellung gespeichert, aber E-Mail nicht verschickt: ' . $f))); exit;
    }
    // Chargen & Dokumente je Position (Handbuch 4.4) – derselbe Baustein wie im Lieferantenportal.
    if (strncmp($aktion, 'bch_', 4) === 0 && !$neu) {
        require_once BX_ROOT . '/core/bestellung_chargen.php';
        $bRow = one("SELECT * FROM bestellung WHERE id=?", [(int)$id]);
        $f = $bRow ? (bestellung_chargen_post($bRow, 'team', 'de') ?? '') : 'Bestellung nicht gefunden.';
        // Nach einem CoA-Upload bzw. „erneut auslesen" öffnet sich das Prüf-Popup dieses CoA.
        $coaPop = (int)($GLOBALS['bch_letztes_coa'] ?? 0) ?: ($aktion === 'bch_ki' ? (int)($_POST['dok_id'] ?? 0) : 0);
        $meld = (string)($GLOBALS['bch_meldung'] ?? '');
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f)) . ($coaPop && $f === '' ? '&coa=' . $coaPop : '') . ($meld !== '' ? '&aufg=' . rawurlencode($meld) : '') . '#chargen'); exit;
    }
    // Lieferantenrechnung durch das Team hochladen (kommt bei „Direkt bestellt" per Mail). Gleicher Platz wie der
    // Portal-Upload des Lieferanten (dokument typ='rechnung' an der Bestellung) – es gibt nur EINE Rechnung je Bestellung.
    if ($aktion === 'team_rechnung' && !$neu) {
        require_once BX_ROOT . '/core/lieferant_dateien.php';
        $f = bestellung_team_rechnung_hochladen((int)$id);
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
    }
    // Rückfrage an den Lieferanten – hängt an dieser Bestellung, der Lieferant sieht sie im Portal.
    if ($aktion === 'nachricht' && !$neu) {
        require_once BX_ROOT . '/core/nachricht.php';
        $lidB = (int) scalar("SELECT lieferant_id FROM bestellung WHERE id=?", [(int)$id]);
        $f = $lidB ? nachricht_post_verarbeiten($lidB, 'team', (string)(current_user()['name'] ?? 'Team'), 'bestellung', (int)$id) : 'Die Bestellung hat noch keinen Lieferanten.';
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
    }
    // Entwurf zurück in den Einkaufsbedarf: Entwurf löschen -> Bedarf erscheint wieder (kein Netting mehr).
    // Zusätzlich: eine KOMPLETT LEERE Bestellung (0 Positionen) darf immer gelöscht werden (egal welcher Status) –
    // sonst bleibt ein Geist-Datensatz im Lager als „Keine Positionen hinterlegt" stehen.
    if ($aktion === 'zurueck_bedarf' && !$neu) {
        $row = one("SELECT status, angekommen_am FROM bestellung WHERE id=?", [(int)$id]);
        $st = (string)($row['status'] ?? '');
        $posAnz = (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=?", [(int)$id]);
        // Loeschbar: Entwurf ('offen'), komplett leere Bestellung (egal welcher Status) ODER eine bereits
        // "bestellte", die noch NICHT angekommen ist (zuruecknehmen -> Bedarf erscheint wieder in "Zu bestellen").
        $loeschbar = $st === 'offen' || $posAnz === 0 || ($st === 'bestellt' && empty($row['angekommen_am']));
        // Fable-4 T3.6: nur ohne Liefermenge, Charge und Reservierung – sonst stornieren (Daten zeigen sonst ins Leere).
        if ($loeschbar && ($zgGrund = bestellung_zurueckziehen_grund((int)$id)) !== '') { header('Location: ?p=bestellung&id=' . $id . '&fehler=' . urlencode($zgGrund)); exit; }
        if ($loeschbar) {
            q("DELETE FROM bestellung_position WHERE bestellung_id=?", [(int)$id]);
            q("DELETE FROM bestellung WHERE id=?", [(int)$id]);
            bedarf_bump();   // offener Bedarf aendert sich -> Zu-bestellen-Liste sofort aktuell
            header('Location: ?p=einkauf&geloescht=1'); exit;
        }
        header('Location: ?p=bestellung&id=' . $id); exit;
    }
    // Bestellung in Einzelbestellungen je Produkt aufteilen (Alt-Bündel aufräumen). Lieferant wird informiert.
    if ($aktion === 'aufteilen' && !$neu) {
        $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
        $res = bestellung_aufteilen((int)$id, $wer);
        if (!empty($res['ok']) && !empty($res['neu'])) {
            // Auf die erste neue Bestellung springen; alte ist storniert.
            $erste = (int) scalar("SELECT id FROM bestellung WHERE nummer=?", [(string)$res['neu'][0]]);
            header('Location: ?p=bestellung&id=' . ($erste ?: $id) . '&aufg=' . rawurlencode($res['msg'])); exit;
        }
        header('Location: ?p=bestellung&id=' . $id . '&fehler=' . urlencode($res['msg'] ?? 'Aufteilen nicht möglich.')); exit;
    }
    // B4: Erinnerung an den Portal-Lieferanten (unbestätigte Bestellung).
    if ($aktion === 'erinnern' && !$neu) {
        $f = bestellung_erinnerung_senden((int)$id);
        header('Location: ?p=bestellung&id=' . $id . ($f === '' ? '&ok=1' : '&fehler=' . urlencode($f))); exit;
    }
    // E8: Lieferantenrechnung als bezahlt markieren (Datum + Zahlungsweg + optional Überweisungsbeleg).
    if ($aktion === 'bezahlt' && !$neu) {
        $res = bestellung_bezahlt_markieren((int)$id, (string)($_POST['bezahlt_am'] ?? ''), (string)($_POST['zahlungsweg'] ?? ''), 'zahlbeleg');
        header('Location: ?p=bestellung&id=' . $id . ($res['ok'] ? '&ok=1' : '&fehler=' . urlencode($res['msg']))); exit;
    }
    // B4: Bestellung stornieren.
    // Nico 12.11.2026: Grund Pflicht. Portal-Lieferanten werden immer informiert (Nachricht + Mail); „ohne Nachricht“ gibt es nur
    // intern für Datenpflege-Läufe (Parameter $lieferant_informieren), kein Formularfeld (Nachbesserung 13.11.2026 Teil 1).
    if ($aktion === 'storno' && !$neu) {
        $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : 'Team';
        $grundS = trim((string)($_POST['grund'] ?? ''));
        $informS = true;
        $res = $grundS === '' ? ['ok' => false, 'msg' => 'Bitte einen Grund für die Stornierung angeben.']
                              : bestellung_stornieren((int)$id, $wer ?: 'Team', $grundS, $informS);
        header('Location: ?p=bestellung&id=' . $id . ($res['ok'] ? '&aufg=' . rawurlencode($res['msg']) : '&fehler=' . urlencode($res['msg']))); exit;
    }
    // Einzelne Position stornieren (Admin, Nico 12.11.2026) – auch an einer schon eingegangenen Bestellung.
    if ($aktion === 'pos_storno' && !$neu && has_role('admin')) {
        $wer = (string)(current_user()['name'] ?? 'Team');
        $bpS = (int)($_POST['bp_id'] ?? 0);
        $res = (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE id=? AND bestellung_id=?", [$bpS, (int)$id]) !== 1
             ? ['ok' => false, 'msg' => 'Position gehört nicht zu dieser Bestellung.']
             : bestellung_position_stornieren($bpS, $wer ?: 'Team', trim((string)($_POST['grund'] ?? '')));
        header('Location: ?p=bestellung&id=' . $id . ($res['ok'] ? '&aufg=' . rawurlencode($res['msg']) : '&fehler=' . urlencode($res['msg']))); exit;
    }
    // KI-Erfassung: Lieferanten-Dokument (PDF/Foto/Tabelle) auslesen -> Positionen vorbelegen.
    if ($aktion === 'ki_lesen' && $neu) {
        if (!ki_bereit()) { header('Location: ?p=bestellung&id=neu&fehler=' . urlencode('KI ist nicht eingerichtet (nur auf beta).')); exit; }
        if (empty($_FILES['ki_datei']['tmp_name']) || !is_uploaded_file($_FILES['ki_datei']['tmp_name'])) {
            header('Location: ?p=bestellung&id=neu&fehler=' . urlencode('Bitte eine Datei auswählen.')); exit;
        }
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo((string)$_FILES['ki_datei']['name'], PATHINFO_EXTENSION)));
        $tmp = BX_UPLOADS . '/ki_best_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['ki_datei']['tmp_name'], $tmp)) { header('Location: ?p=bestellung&id=neu&fehler=' . urlencode('Datei konnte nicht gespeichert werden.')); exit; }
        $anw = "Dies ist ein Lieferanten-Dokument (Bestellung, Auftragsbestätigung, Angebot oder Rechnung) für einen "
             . "Nahrungsergänzungs-Lohnhersteller. Lies ALLE Positionen aus. Antworte NUR mit JSON in dieser Form:\n"
             . '{"lieferant":"<Firmenname oder leer>","waehrung":"EUR","positionen":[{"bezeichnung":"<Artikel>","menge":<Zahl>,"einheit":"kg/Stück/…","ek_preis":<Preis je Einheit oder null>}]}'
             . "\nMengen und Preise als Zahl mit Punkt als Dezimaltrennzeichen, ohne Tausenderpunkte. Preis je EINHEIT (nicht Positionssumme). Unbekannter Preis: null.";
        $r = ki_datei_frage($tmp, $anw, ['json' => true, 'zweck' => 'Bestellung erfassen', 'max_tokens' => 3000]);
        @unlink($tmp);
        if (empty($r['ok'])) { header('Location: ?p=bestellung&id=neu&fehler=' . urlencode('KI: ' . ($r['fehler'] ?? 'Dokument nicht lesbar.'))); exit; }
        $_SESSION['ki_best'] = $r['daten'] ?? [];
        header('Location: ?p=bestellung&id=neu&ki=1'); exit;
    }
    // Fable-4 T3.5: serverseitiger Statuscheck – geliefert/storniert ist nicht mehr änderbar (auch nicht aus einem alten Tab).
    if (!$neu && ($bbG = bestellung_bearbeitbar_grund((int)$id)) !== '') { header('Location: ?p=bestellung&id=' . (int)$id . '&fehler=' . urlencode($bbG)); exit; }
    // Leere Bestellung verhindern: ohne Position sieht der Lieferant im Portal nichts (BE-xxxx „–").
    // Zählt eingehende Lagerartikel-Positionen + (beim Bearbeiten) erhaltene Bulk-/Freitext-Positionen.
    $neuePosEin = 0; foreach (($_POST['p_item'] ?? []) as $iK => $iid) if ((int)$iid > 0 || (int)(($_POST['p_id'] ?? [])[$iK] ?? 0) > 0) $neuePosEin++;   // Fable-4 T3.4: vorhandene Zeile ohne Artikel in der Auswahl zählt mit
    if ($neu && $neuePosEin > 1) {   // 13.11.2026 (Paket Teil 6.2/6.3): jede Bestellung genau eine Position
        header('Location: ?p=bestellung&id=neu&fehler=' . urlencode('Eine Bestellung hat genau eine Position – bitte für jeden Artikel eine eigene Bestellung anlegen.')); exit;
    }
    $bulkDa = (!$neu && (int)$id > 0) ? (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=? AND (item_id IS NULL OR rest_storniert_am IS NOT NULL OR geliefert_menge>0)", [(int)$id]) : 0;   // + gesperrte Positionen (Fable-3 T2.6)
    if ($neuePosEin + $bulkDa === 0) {
        header('Location: ?p=bestellung&id=' . ($neu ? 'neu' : (int)$id) . '&fehler=' . urlencode('Bitte mindestens eine Position (Artikel + Menge) hinzufügen, bevor die Bestellung gespeichert wird.')); exit;
    }
    // 13.11.2026: neue Bestellung von Hand – Doppelbestell-Warnung für die Artikel der Positionen (Hinweis statt Sperre).
    $bxDoppelt = [];
    if ($neu) {
        require_once BX_ROOT . '/core/bestellung_doppelt.php';
        $bxDoppelt = bestellung_doppelt_treffer(array_map('intval', (array)($_POST['p_item'] ?? [])));
        if ($bxDoppelt && empty($_POST['doppelt_ok'])) bestellung_doppelt_stopp($bxDoppelt, '?p=bestellung&id=neu');
    }
    if ($neu) {
        q("INSERT INTO bestellung (nummer,lieferant_id,status,notiz,hinweis_lieferant) VALUES (?,?,?,?,?)",
          [naechste_nummer('BE'), $lief, 'offen', trim($_POST['notiz'] ?? ''), trim((string)($_POST['hinweis_lieferant'] ?? '')) ?: null]);
        $id = insert_id();
    } else {
        q("UPDATE bestellung SET lieferant_id=?,notiz=?,hinweis_lieferant=? WHERE id=?", [$lief, trim($_POST['notiz'] ?? ''), trim((string)($_POST['hinweis_lieferant'] ?? '')) ?: null, (int)$id]);
    }
    // Positionen synchronisieren (Fable-3 T2.6): aktualisieren statt löschen + neu anlegen – Storno, Liefermenge und Chargen-Verknüpfung
    // bleiben erhalten, gesperrte Positionen (storniert/geliefert) ändert das Formular nie. Bulk-Freitext (item_id NULL) bleibt unberührt.
    $pi = $_POST['p_item'] ?? []; $pm = $_POST['p_menge'] ?? []; $pe = $_POST['p_ek'] ?? []; $pa = $_POST['p_auftrag'] ?? []; $pid = $_POST['p_id'] ?? [];
    $zeilen = [];
    foreach ($pi as $i => $iid) $zeilen[] = ['id' => (int)($pid[$i] ?? 0), 'item_id' => (int)$iid,
        'menge' => zahl_lesen((string)($pm[$i] ?? '0'), true),   // „7.000" = 7000 (Teil 8, deutsche Eingabe)
        'ek' => zahl_lesen((string)($pe[$i] ?? '0')), 'auftrag_id' => (string)($pa[$i] ?? '')];
    $rS = bestellung_positionen_speichern((int)$id, $zeilen);
    if ($bxDoppelt) bestellung_doppelt_vermerken([(int)$id], $bxDoppelt);
    header('Location: ?p=bestellung&id=' . $id . '&ok=1' . ($rS['behalten'] ? '&fehler=' . urlencode($rS['behalten'] . ' Position(en) nicht gelöscht bzw. Artikel nicht geändert: daran hängen Chargen, Dokumente des Lieferanten oder eine Zuordnung zu einem Auftrag.') : '')); exit;
}

$b = $neu ? ['status'=>'offen'] : one("SELECT * FROM bestellung WHERE id=?", [(int)$id]);
if (!$b) { $neu = true; $b = ['status'=>'offen']; }
$v = fn($k) => h((string)($b[$k] ?? ''));
$geliefert = ($b['status'] ?? '') === 'geliefert';

$lieferanten = all("SELECT id, firma FROM lieferanten WHERE COALESCE(gesperrt,0)=0 OR id=? ORDER BY firma", [(int)($b['lieferant_id'] ?? 0)]);   // Block C: gesperrte nicht auswählbar (der schon gewählte bleibt sichtbar)
// 13.11.2026 (Paket Teil 6.3): neue Bestellung = erst die Art, dann sucht die Artikelsuche NUR in dieser Art (Etikett: erst der Kunde).
// Eine Bestellung = eine Position. Beim Bearbeiten einer vorhandenen Bestellung bleibt die volle Liste (Art ergibt sich aus der Position).
$BE_ARTEN = ['rohstoff' => 'Rohstoff', 'verpackung' => 'Verpackung', 'etikett' => 'Etikett', 'bulk' => 'Bulkware', 'fertigprodukt' => 'Fertigprodukt'];
$beArt = $neu ? (string)($_GET['art'] ?? '') : '';
if (!isset($BE_ARTEN[$beArt])) $beArt = '';
$beKunde = (int)($_GET['kunde'] ?? 0);
$artWhere = match ($beArt) {
    'rohstoff'      => "i.kategorie IN ('rohstoff','leerkapsel')",
    'verpackung'    => "(i.kategorie='verbrauch' OR (i.kategorie='verpackung' AND COALESCE(i.verpackung_rolle,'')<>'etikett'))",
    'etikett'       => "i.kategorie='verpackung' AND i.verpackung_rolle='etikett' AND i.kunde_id=" . $beKunde,
    'bulk'          => "i.kategorie='fertig'",
    'fertigprodukt' => "i.kategorie='verkaufsfertig'",
    default         => "i.kategorie IN ('rohstoff','leerkapsel','verpackung','verbrauch','fertig','verkaufsfertig')",
};
$items = all("SELECT i.id, i.name, i.einheit, i.ek_preis, i.kategorie, CONCAT_WS(' ', NULLIF(i.synonym,''), NULLIF(i.synonyme,''), NULLIF(i.artikelnummer,'')) AS syn,
                     COALESCE(kgi.name, kgr.name) AS kg_name, IF(r.darreichungsform IN ('kapsel','softgel','tablette'), r.einheit_fuellmenge, NULL) AS fuell_mg
              FROM item i LEFT JOIN kapselgroesse kgi ON kgi.id=i.kapselgroesse_id LEFT JOIN rezeptur r ON r.id=i.rezeptur_id LEFT JOIN kapselgroesse kgr ON kgr.id=r.kapselgroesse_id
              WHERE $artWhere AND i.gesperrt=0 ORDER BY i.name");
$positionen = $neu ? [] : all("SELECT * FROM bestellung_position WHERE bestellung_id=? AND item_id IS NOT NULL ORDER BY sort,id", [(int)$id]);
$posGesamt  = $neu ? 0 : (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=?", [(int)$id]);   // inkl. Bulk-/Freitext-Positionen (item_id NULL)
$bulkPositionen = $neu ? [] : all("SELECT * FROM bestellung_position WHERE bestellung_id=? AND item_id IS NULL ORDER BY sort,id", [(int)$id]);
$EK = []; foreach ($items as $it) $EK[$it['id']] = (float)$it['ek_preis'];

// KI-Vorbelegung: Ergebnis der Dokument-Auslesung auf Lieferant + Lagerartikel abbilden.
$kiRaw = null; $kiTreffer = [];
if ($neu && isset($_GET['ki']) && !empty($_SESSION['ki_best'])) {
    $kiRaw = (array) $_SESSION['ki_best']; unset($_SESSION['ki_best']);
    $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string)$s)));
    // Lieferant zuordnen (exakt normiert, sonst enthält-Treffer)
    $ln = $norm($kiRaw['lieferant'] ?? '');
    if ($ln !== '') foreach ($lieferanten as $l) {
        $fn = $norm($l['firma']); if ($fn === '') continue;
        if ($fn === $ln || str_contains($ln, $fn) || str_contains($fn, $ln)) { $b['lieferant_id'] = (int)$l['id']; break; }
    }
    // Positionen auf Artikel abbilden; längster Namensüberlapp gewinnt (sonst passt „Zink" auf alles)
    $kiPos = [];
    foreach ((array)($kiRaw['positionen'] ?? []) as $p) {
        $bez = trim((string)($p['bezeichnung'] ?? '')); if ($bez === '') continue;
        $bn = $norm($bez); $mid = 0; $best = 0;
        foreach ($items as $it) {
            $inn = $norm($it['name']); if ($inn === '') continue;
            if ($inn === $bn) { $mid = (int)$it['id']; break; }
            if (str_contains($bn, $inn) || str_contains($inn, $bn)) { $len = min(strlen($inn), strlen($bn)); if ($len > $best) { $best = $len; $mid = (int)$it['id']; } }
        }
        $menge = (float) str_replace(',', '.', (string)($p['menge'] ?? 0));
        $ek    = ($p['ek_preis'] ?? null) !== null && $p['ek_preis'] !== '' ? (float) str_replace(',', '.', (string)$p['ek_preis']) : '';
        $kiPos[]     = ['item_id' => $mid, 'menge' => $menge > 0 ? $menge : '', 'ek_preis' => $ek, 'auftrag_id' => ''];
        $kiTreffer[] = ['bez' => $bez, 'item_id' => $mid, 'einheit' => trim((string)($p['einheit'] ?? '')), 'menge' => $menge, 'ek' => $ek];
    }
    if ($kiPos) $positionen = $kiPos;   // Formular unten mit den erkannten Zeilen vorbelegen
}
// Fable-3 T2.10: kommt die Seite von der Doppel-Warnung zurück, steht das Formular wieder mit den Eingaben da („Abbrechen“ leert nichts).
if ($neu) {
    require_once BX_ROOT . '/core/bestellung_doppelt.php';
    if (($vb = bestellung_doppelt_vorbelegung()) !== null) {
        $b['lieferant_id'] = (int)($vb['lieferant_id'] ?? 0); $b['notiz'] = (string)($vb['notiz'] ?? ''); $b['hinweis_lieferant'] = (string)($vb['hinweis_lieferant'] ?? '');
        $positionen = [];
        foreach ((array)($vb['p_item'] ?? []) as $i => $iid) {
            if ((int)$iid <= 0) continue;
            $positionen[] = ['id' => 0, 'item_id' => (int)$iid, 'menge' => zahl_lesen((string)($vb['p_menge'][$i] ?? ''), true) ?: '',
                             'ek_preis' => ($vb['p_ek'][$i] ?? '') !== '' ? zahl_lesen((string)$vb['p_ek'][$i]) : '', 'auftrag_id' => (string)($vb['p_auftrag'][$i] ?? '')];
        }
    }
}

function pos_options(array $items, $sel): string {
    $s = '<option value="">– Artikel wählen –</option>';
    // Fable-4 T3.4 (13.11.2026): der Artikel einer vorhandenen Position steht immer in der Auswahl – auch Fertig-/Handelsware und
    // gesperrte Artikel, die sonst nicht angeboten werden. Sonst käme die Zeile leer an und würde beim Speichern entfernt.
    if ((int)$sel > 0 && !in_array((int)$sel, array_map('intval', array_column($items, 'id')), true)) {
        $nm = (string) scalar("SELECT name FROM item WHERE id=?", [(int)$sel]);
        if ($nm !== '') $s .= '<option value="' . (int)$sel . '" selected>' . h($nm) . ' (vorhandener Artikel)</option>';
    }
    // Teil 5/8 (10.11.2026): Suchfeld (rscombo) mit Synonymen; Kapselgröße/Füllgewicht steht im Text (Bulk/Premix/Leerkapsel).
    foreach ($items as $it) { $kl = implode(' · ', array_filter([(string)($it['kg_name'] ?? '') !== '' ? 'Größe ' . preg_replace('/^(Größe|Gr\.?)\s*/iu', '', (string)$it['kg_name']) : '',
                                                    (float)($it['fuell_mg'] ?? 0) > 0 ? rtrim(rtrim(number_format((float)$it['fuell_mg'], 1, ',', '.'), '0'), ',') . ' mg' : '']));
        $s .= '<option value="' . (int)$it['id'] . '"' . ((int)$sel === (int)$it['id'] ? ' selected' : '') . ' data-such="' . h((string)($it['syn'] ?? '')) . '">' . h($it['name']) . ($kl !== '' ? ' · ' . h($kl) : '') . ' (' . h($it['einheit']) . ')</option>'; }
    return $s;
}
// Offene Kundenaufträge, damit eine Bestellposition „für Auftrag X" markiert werden kann (Baustein 4)
$auftraege = all("SELECT a.id, a.nummer, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma
                  FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                  WHERE a.status <> 'versendet' ORDER BY a.angelegt DESC");
function auftrag_options(array $auftraege, $sel): string {
    $s = '<option value="">Lager / allgemein</option>';
    foreach ($auftraege as $a) {
        $lbl = $a['nummer'] . ($a['produkt'] ? ' · ' . $a['produkt'] : '') . ($a['firma'] ? ' · ' . $a['firma'] : '');
        $s .= '<option value="' . (int)$a['id'] . '"' . ((int)$sel === (int)$a['id'] ? ' selected' : '') . '>' . h($lbl) . '</option>';
    }
    return $s;
}

render_header('einkauf', $neu ? 'Neue Bestellung' : ($b['nummer'] ?? 'Bestellung'));
bx_head($neu ? 'Neue Bestellung' : $v('nummer'), $neu ? 'Beim Lieferanten bestellen' : 'Bestellung bearbeiten',
        bx_btn('Zurück zur Liste', '?p=einkauf', 'ghost'));
if (isset($_GET['ok']))        echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['okmsg']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['okmsg']) . '</div>';   // Block B: Korrektur/Lösen
if (isset($_GET['geliefert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Als geliefert verbucht – Chargen im Wareneingang (Quarantäne) angelegt.</div>';
if (isset($_GET['fehler']))  echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
if (isset($_GET['aufg']))      echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['aufg']) . '</div>';
// 13.11.2026 (Paket Teil 6.3): Schritt 1 – Art wählen (bei Etikett zusätzlich den Kunden). Ohne Art kein Formular (außer KI-Vorbelegung/Rückkehr).
$beVorbelegt = $neu && ($kiRaw !== null || !empty($positionen));
if ($neu && !$beVorbelegt && ($beArt === '' || ($beArt === 'etikett' && $beKunde <= 0))) {
    echo '<div class="bx-panel"><h2 style="margin-top:0">Was wird bestellt?</h2><p class="muted" style="margin-top:-4px">Erst die Art – die Artikelsuche zeigt danach nur Artikel dieser Art. Jede Bestellung hat genau eine Position.</p><div class="bx-cards">';
    foreach ($BE_ARTEN as $ak => $al) echo '<a class="bx-card" style="text-decoration:none;min-width:150px' . ($beArt === $ak ? ';border-color:var(--gruen)' : '') . '" href="?p=bestellung&id=neu&art=' . $ak . '"><div class="v" style="font-size:18px">' . h($al) . '</div></a>';
    echo '</div>';
    if ($beArt === 'etikett') {
        $kListe = all("SELECT DISTINCT k.id, k.firma FROM kunden k JOIN item i ON i.kunde_id=k.id AND i.kategorie='verpackung' AND i.verpackung_rolle='etikett' AND i.gesperrt=0 ORDER BY k.firma");
        echo '<form method="get" style="margin-top:14px"><input type="hidden" name="p" value="bestellung"><input type="hidden" name="id" value="neu"><input type="hidden" name="art" value="etikett">'
           . '<div class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap"><div class="bx-field" style="min-width:280px;margin:0"><label>Kunde (Etiketten sind kundenspezifisch)</label><select name="kunde" required class="rscombo"><option value="">– Kunde wählen –</option>';
        foreach ($kListe as $kk) echo '<option value="' . (int)$kk['id'] . '">' . h((string)$kk['firma']) . '</option>';
        echo '</select></div><button class="btn btn-primary" type="submit">Weiter</button></div>' . (!$kListe ? '<p class="muted">Noch keine Kundenetiketten angelegt.</p>' : '') . '</form>';
    }
    echo '</div>';
    render_footer(); exit;
}
if ($neu && $beArt !== '') echo '<div class="muted" style="margin:-4px 0 10px">Art: <b style="font-weight:600">' . h($BE_ARTEN[$beArt]) . '</b>' . ($beArt === 'etikett' ? ' · Kunde: ' . h((string) scalar("SELECT firma FROM kunden WHERE id=?", [$beKunde])) : '') . ' · <a href="?p=bestellung&id=neu">andere Art wählen</a></div>';
?>
<?php if ($neu && ki_bereit()): ?>
<div class="bx-panel" style="border-color:var(--gruen)">
  <h2 style="margin-top:0">KI-Erfassung aus Dokument</h2>
  <p class="muted" style="margin-top:0">Bestellbestätigung, Angebot oder Rechnung des Lieferanten hochladen (PDF, Foto, CSV/Excel). Die KI liest Lieferant und alle Positionen aus und füllt das Formular unten – zum Prüfen, nicht blind speichern.</p>
  <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="ki_lesen">
    <div class="bx-field" style="margin:0"><label>Dokument</label>
      <input type="file" name="ki_datei" required accept=".pdf,.png,.jpg,.jpeg,.webp,.csv,.tsv,.txt,.xlsx"></div>
    <button class="btn btn-primary" type="submit" data-busy="Liest Dokument…">Auslesen</button>
    <span class="muted" style="font-size:12px;align-self:center">dauert 10–60 Sekunden</span>
  </form>
</div>
<?php endif; ?>
<?php if ($kiTreffer): ?>
<div class="bx-panel badge-ok" style="padding:14px 16px">
  <strong><?= count($kiTreffer) ?> Position(en) erkannt</strong> – bitte prüfen, Zuordnung/Preise korrigieren und unten speichern.
  <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
    <thead><tr><th>Erkannt (Dokument)</th><th>Zugeordnet</th><th class="bx-num">Menge</th><th class="bx-num">EK</th></tr></thead>
    <tbody>
    <?php foreach ($kiTreffer as $t): $itName = $t['item_id'] ? (string) scalar("SELECT name FROM item WHERE id=?", [$t['item_id']]) : ''; ?>
      <tr><td><?= h($t['bez']) ?><?= $t['einheit'] !== '' ? ' <span class="muted">(' . h($t['einheit']) . ')</span>' : '' ?></td>
          <td><?= $itName !== '' ? h($itName) : '<span style="color:#8f231b">nicht gefunden – unten Artikel wählen</span>' ?></td>
          <td class="bx-num"><?= $t['menge'] > 0 ? h(rtrim(rtrim(number_format($t['menge'],3,',','.'),'0'),',')) : '–' ?></td>
          <td class="bx-num"><?= $t['ek'] !== '' ? h(preis_zahl($t['ek'])) . ' &euro;' : '–' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php
if (!$neu) {
    // ===== Bestellseite aufgeräumt (13.11.2026, Anweisung „Bestellseite aufräumen“): Kopf mit EINEM Hauptknopf und Menü „⋯“,
    // Unterlagen-Zeile, eine Positions-Karte (Bearbeiten im Pop-up), darunter Reiter statt elf Blöcke. Funktionen unverändert.
    // Bausteine gemeinsam mit Pop-up „Bestellt“ und Einkauf mobil: core/bestellung_seite.php.
    require_once BX_ROOT . '/core/bestellung_seite.php';
    require_once BX_ROOT . '/core/bestellung_abgleich.php';
    require_once BX_ROOT . '/core/bestellung_chargen.php';
    require_once BX_ROOT . '/core/lief_aufkleber_ui.php';
    require_once BX_ROOT . '/core/bestellung_ui.php';
    require_once BX_ROOT . '/core/lieferant_dateien.php';
    $mz = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $bd = bs_daten($b);
    $st = $bd['st']; $offenBest = $bd['offen']; $istPortal = $bd['portal']; $luF = $bd['luF']; $rechnDocs = $bd['rechnungen']; $istBezahlt = $bd['bezahlt'];
    $hp = bs_haupt($b, $bd);

    // ---- Hauptknopf je Stand
    $haupt = match ($hp['art']) {
        'bestellen' => '<form method="post" style="margin:0"><input type="hidden" name="aktion" value="bestellt"><button class="btn btn-primary bs-haupt" type="submit">Bestellen</button></form>',
        'einbuchen' => '<a class="btn btn-primary bs-haupt" href="' . h($bd['einbuchen_url']) . '">Ware ist da - einbuchen</a>',
        'rechnung'  => '<button type="button" class="btn btn-primary bs-haupt" onclick="bxPopup.open(\'dlgRechnung\')">Rechnung erfassen</button>',
        'bezahlt'   => '<button type="button" class="btn btn-primary bs-haupt" onclick="bxPopup.open(\'dlgBezahlt\')">Als bezahlt markieren</button>',
        default     => '',
    };

    // ---- Menü „⋯“: alle übrigen Aktionen
    $menu = [];
    $menu[] = '<button type="button" class="bs-mi" onclick="bxPopup.open(\'dlgBearbeiten\')">Bearbeiten</button>';
    $menu[] = '<a class="bs-mi" target="_blank" href="?p=bestellung_pdf&id=' . (int)$id . '">PDF für den Lieferanten</a>';
    if ($hp['art'] !== 'rechnung' && !empty($b['lieferant_id']) && !$rechnDocs && $st !== 'storniert') $menu[] = '<button type="button" class="bs-mi" onclick="bxPopup.open(\'dlgRechnung\')">Rechnung erfassen</button>';
    if ($hp['art'] !== 'bezahlt' && !empty($b['lieferant_id']) && !$istBezahlt && $st !== 'storniert') $menu[] = '<button type="button" class="bs-mi" onclick="bxPopup.open(\'dlgBezahlt\')">Als bezahlt markieren</button>';
    if ($st === 'offen') $menu[] = '<form method="post"><input type="hidden" name="aktion" value="zurueck_bedarf"><button class="bs-mi" type="submit" title="Entwurf verwerfen – der Bedarf erscheint wieder im Einkaufsbedarf">Zurück in den Einkaufsbedarf</button></form>';
    if ($st !== 'offen' && $posGesamt === 0) $menu[] = '<form method="post" onsubmit="return confirm(\'Diese leere Bestellung löschen?\');"><input type="hidden" name="aktion" value="zurueck_bedarf"><button class="bs-mi bs-rot" type="submit">Leere Bestellung löschen</button></form>';
    if ($st === 'bestellt' && $posGesamt > 0 && empty($b['angekommen_am'])) {
        $zgSperre = bestellung_zurueckziehen_grund((int)$id);
        $menu[] = $zgSperre !== '' ? '<button class="bs-mi" type="button" disabled title="' . h($zgSperre) . '">Zurückziehen</button>'
                : '<form method="post" onsubmit="return confirm(\'Bestellung zurückziehen und löschen? Der Bedarf erscheint wieder unter „Zu bestellen“.\');"><input type="hidden" name="aktion" value="zurueck_bedarf"><button class="bs-mi bs-rot" type="submit">Zurückziehen</button></form>';
    }
    if ($st === 'offen' && (int)($b['unterlagen_wartet'] ?? 0) === 1)
        $menu[] = '<form method="post" class="bs-mi-form"><input type="hidden" name="aktion" value="schon_bestellt"><input type="date" name="datum" value="' . h(heute_ort()) . '" title="Bestelldatum beim Lieferanten"><button class="btn btn-ghost btn-sm" type="submit">Schon bestellt am …</button></form>';
    if ($st === 'gesendet' && (int)($b['bestaetigt'] ?? 0) !== 1)
        $menu[] = '<form method="post" onsubmit="return confirm(\'Bestellung als telefonisch bestätigt markieren? Status bestellt, keine Mail.\');"><input type="hidden" name="aktion" value="telefonisch_bestaetigt"><button class="bs-mi" type="submit">Telefonisch bestätigt</button></form>';
    if ($istPortal && (int)($b['bestaetigt'] ?? 0) !== 1 && !in_array($st, ['geliefert', 'storniert', 'offen'], true))
        $menu[] = '<form method="post"><input type="hidden" name="aktion" value="erinnern"><button class="bs-mi" type="submit">Lieferanten erinnern' . (!empty($b['erinnert_am']) ? ' (erneut)' : '') . '</button></form>';
    $bulkProdGrp = (int) scalar("SELECT COUNT(DISTINCT TRIM(SUBSTRING_INDEX(SUBSTRING(bezeichnung,7),' (',1))) FROM bestellung_position WHERE bestellung_id=? AND item_id IS NULL AND bezeichnung LIKE 'Bulk%'", [(int)$id]);
    if ($bulkProdGrp >= 2 && $offenBest)
        $menu[] = '<form method="post" onsubmit="return confirm(\'Diese Bestellung in ' . $bulkProdGrp . ' Einzelbestellungen je Produkt aufteilen? Die alte wird storniert und der Lieferant informiert.\');"><input type="hidden" name="aktion" value="aufteilen"><button class="bs-mi" type="submit">In Einzelbestellungen aufteilen</button></form>';
    // Fable-6 P11: „Rest stornieren“ nur bei echter Teillieferung, mit Grund, nur Einkauf/Admin; „Stornieren“ nicht mehr nach Wareneingang.
    $schonGeliefert = (float) scalar("SELECT COALESCE(SUM(geliefert_menge),0) FROM bestellung_position WHERE bestellung_id=?", [(int)$id]) > 0.0005;
    if ($bd['teil'] > 0 && $offenBest && $schonGeliefert && (has_role('admin') || has_role('einkauf')))
        $menu[] = '<button type="button" class="bs-mi" onclick="bxPopup.open(\'dlgRestStorno\')">Rest stornieren</button>';
    if ($offenBest && !$schonGeliefert) $menu[] = '<button type="button" class="bs-mi bs-rot" onclick="bxPopup.open(\'dlgStorno\')">Stornieren</button>';
    $altlast = has_role('admin') && !in_array($st, ['storniert', 'offen'], true) && bestellung_abgleich_panel($b) !== '';
    if ($altlast) $menu[] = '<button type="button" class="bs-mi" onclick="document.getElementById(\'dlgAltlast' . (int)$id . '\').showModal()">Altlasten: ohne Einbuchen schließen</button>';
    $menuHtml = '<details class="bs-menu"><summary class="btn btn-ghost" aria-label="Weitere Aktionen" title="Weitere Aktionen">&#8943;</summary><div class="bs-menu-liste">' . implode('', $menu) . '</div></details>';

    echo bs_kopf_html($b, $bd, $haupt . $menuHtml);

    // ---- Unterlagen-Zeile direkt unter dem Kopf (Hochladen im Pop-up)
    if ($luF) {
        echo '<div class="bs-hinweis" id="unterlagen"><span>' . h(lu_hinweis_text($luF)) . '</span>'
           . '<button type="button" class="btn btn-ghost btn-sm" onclick="bxPopup.open(\'dlgUnterlagen\')">Hochladen</button></div>';
        echo '<dialog id="dlgUnterlagen" class="bx-dialog" style="max-width:760px"><h2>Unterlagen des Lieferanten</h2>'
           . '<div>' . lu_panel((int)$b['lieferant_id'], '?p=bestellung&id=' . (int)$id, 'de', ['liste' => $luF], true) . '</div></dialog>';
    }

    // ---- Position (eine Karte), darunter eingeklappt: Wofür wird bestellt, Etiketten-Druckdateien
    $posHtml = bs_position_html($b, 'dlgBearbeiten');
    $kontext = all("SELECT DISTINCT a.id, a.nummer, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma AS kunde, a.kunde_id, pa.id AS pa_id, pa.prio, pa.geplant_am
                    FROM bestellung_position bp JOIN auftrag a ON a.id=bp.auftrag_id LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                    LEFT JOIN produktionsauftrag pa ON pa.auftrag_id=a.id WHERE bp.bestellung_id=? ORDER BY pa.prio, a.nummer", [(int)$id]);
    $zusatz = '';
    if ($kontext) {
        $zusatz .= '<details class="bs-details"><summary>Wofür wird bestellt (Priorität, Produktionstermin)</summary><div class="bx-tablewrap"><table class="bx-table bx-karten-handy"><thead><tr><th>Auftrag</th><th>Produkt</th><th>Kunde</th><th>Priorität</th><th>Geplant am</th><th>Bereitschaft</th></tr></thead><tbody>';
        foreach ($kontext as $c)
            $zusatz .= '<tr><td><a href="' . ($c['pa_id'] ? '?p=produktionsauftrag&id=' . (int)$c['pa_id'] : '?p=auftrag&id=' . (int)$c['id']) . '">' . h($c['nummer']) . '</a></td><td>' . h($c['produkt'] ?: '–') . '</td><td>' . kunde_link($c['kunde_id'], $c['kunde']) . '</td>'
                     . '<td>' . prio_badge((int)($c['prio'] ?? 2)) . '</td><td>' . ($c['geplant_am'] ? h(date('d.m.Y', strtotime($c['geplant_am']))) : '–') . '</td>'
                     . '<td>' . ($c['pa_id'] ? bereitschaft_badge(produktion_bereitschaft((int)$c['pa_id'])['status']) : '–') . '</td></tr>';
        $zusatz .= '</tbody></table></div></details>';
    }
    $etikettPos = all("SELECT bp.auftrag_id, i.name FROM bestellung_position bp JOIN item i ON i.id=bp.item_id WHERE bp.bestellung_id=? AND i.verpackung_rolle='etikett' AND bp.auftrag_id IS NOT NULL GROUP BY bp.auftrag_id, i.name", [(int)$id]);
    if ($etikettPos) {
        $zusatz .= '<details class="bs-details"><summary>Etiketten-Druckdateien</summary><div class="bx-tablewrap"><table class="bx-table bx-karten-handy"><thead><tr><th>Etikett</th><th>Produkt</th><th>Maße</th><th>Druckdatei</th></tr></thead><tbody>';
        foreach ($etikettPos as $ep) {
            $ei = etikett_info((int)$ep['auftrag_id']);
            $zusatz .= '<tr><td>' . h($ep['name']) . '</td><td>' . ($ei['produkt'] ? h($ei['produkt']) : '–') . '</td><td>' . ($ei['masse'] ? h($ei['masse']['label']) : '–') . '</td><td>'
                     . (!empty($ei['dok']) ? '<a href="?p=dokument&id=' . (int)$ei['dok']['id'] . '" target="_blank">' . h($ei['dok']['datei_orig'] ?: 'Etikett') . '</a>' : bx_badge('kein Design hochgeladen', 'warn')) . '</td></tr>';
        }
        $zusatz .= '</tbody></table></div></details>';
    }
    if ($posHtml !== '' && $zusatz !== '') $posHtml = substr($posHtml, 0, -6) . $zusatz . '</div>';
    echo $posHtml;

    // ---- Reiter 1: Wareneingang & Chargen
    ob_start();
    echo bestellung_abgleich_panel($b, true);   // nur „schon im Lager? verknüpfen“ – Einbuchen = Hauptknopf, Stornieren/Altlasten im Menü
    echo bestellung_chargen_panel($b, 'team', 'de');
    if ($bd['chargen'] > 0) echo la_panel($b, '?p=bestellung&id=' . (int)$id, 'team', 'de');   // Aufkleber nur, wenn eine Charge da ist
    $vkCh = all("SELECT c.id, c.charge_nr, c.menge, c.einheit, c.wareneingang FROM charge c JOIN bestellung_position bp ON bp.id=c.bestellung_position_id WHERE bp.bestellung_id=? ORDER BY c.id", [(int)$id]);
    if ($vkCh) {
        echo '<div class="bx-panel" id="chargenbezug"><h2 style="margin-top:0">Eingebuchte Chargen</h2><div class="bx-tablewrap"><table class="bx-table bx-karten-handy"><thead><tr><th>Charge</th><th class="bx-num">Menge</th><th>Eingang</th><th></th></tr></thead><tbody>';
        foreach ($vkCh as $vc)
            echo '<tr><td><a href="?p=chargen&id=' . (int)$vc['id'] . '">' . h((string)($vc['charge_nr'] ?: '#' . $vc['id'])) . '</a></td><td class="bx-num">' . $mz($vc['menge']) . ' ' . h((string)$vc['einheit']) . '</td>'
               . '<td>' . ($vc['wareneingang'] ? h(date('d.m.Y', strtotime((string)$vc['wareneingang']))) : '–') . '</td><td style="text-align:right">'
               . (has_role('admin') ? '<a class="btn btn-ghost btn-sm" href="?p=chargen&id=' . (int)$vc['id'] . '">Menge korrigieren</a> <form method="post" style="display:inline" onsubmit="return confirm(\'Verknüpfung lösen? Die Charge ist danach frei, die Bestellung rechnet neu.\');"><input type="hidden" name="aktion" value="abgl_loesen"><input type="hidden" name="charge_id" value="' . (int)$vc['id'] . '"><button class="btn btn-ghost btn-sm" type="submit">Verknüpfung lösen</button></form>' : '') . '</td></tr>';
        echo '</tbody></table></div></div>';
    }
    $bdoks = bestell_dokumente((int)$id, true);
    $BDTYP = ['coa' => 'CoA', 'rechnung' => 'Rechnung', 'lieferschein' => 'Lieferschein', 'spec' => 'Spezifikation', 'analyse' => 'Laboranalyse', 'sonstiges' => 'Sonstiges'];
    if ($bdoks) {
        echo '<div class="bx-panel"><h2 style="margin-top:0">Weitere Dokumente</h2><div class="bs-doks">';
        foreach ($bdoks as $d) echo '<a class="btn btn-ghost btn-sm" target="_blank" href="?p=dokument&id=' . (int)$d['id'] . '">' . h(($BDTYP[$d['typ']] ?? $d['typ']) . ' · ' . ($d['titel'] ?: ($d['datei_orig'] ?: 'Dokument'))) . '</a>';
        echo '</div></div>';
    }
    $tabWE = ob_get_clean();

    // ---- Reiter 2: Versand (Stationen-Leiste nur bei Portal-Lieferanten – in bestellung_ablauf_panel)
    ob_start();
    echo bestellung_ablauf_panel($b, 'team', 'de');
    require_once BX_ROOT . '/core/bestellung_einzeln.php';
    $pak = bestellung_tracking_liste((int)$id);
    $sendTxt = bestellung_sendung_text((int)$id);
    echo '<div class="bs-zeile"><span>' . ($pak ? 'Sendung: ' . h(implode(', ', array_map(fn($pk) => trim((string)($pk['spediteur'] ?? '') . ' ' . $pk['tracking']), $pak))) : '<span class="muted">Noch keine Sendungsnummer.</span>')
       . ($sendTxt !== '' ? ' · ' . h($sendTxt) : '') . '</span>'
       . '<button type="button" class="btn btn-ghost btn-sm" data-pop-url="?p=bestellung_pop&id=' . (int)$id . '&nur=versand" data-pop-titel="Versand und Sendung">Versand / Sendung erfassen</button></div>';
    $tabVersand = ob_get_clean();

    // ---- Reiter 3: Zahlung (eine Statuszeile, Formulare nur im Pop-up)
    ob_start();
    if (($b['bestellart'] ?? '') === 'online') {
        $za = bestellung_zahlarten()[(string)($b['online_zahlart'] ?? '')] ?? '';
        echo '<div class="bs-zeile"><span>Online-Einkauf · ' . h((string)($b['online_haendler'] ?? '') ?: 'Händler –') . ' · ' . h($za ?: 'Zahlart –') . ' · '
           . (($b['online_preis'] ?? null) !== null ? h(number_format((float)$b['online_preis'], 2, ',', '.') . ' ' . ((string)($b['online_waehrung'] ?? '') ?: 'EUR')) : 'Preis –') . '</span>'
           . '<span class="bs-zeile-knoepfe">' . implode('', array_map(fn($rd) => '<a class="btn btn-ghost btn-sm" target="_blank" href="?p=dokument&id=' . (int)$rd['id'] . '">Rechnung öffnen</a>', $rechnDocs)) . '</span></div>';
    }
    if (!empty($b['lieferant_id'])) {
        $re = $rechnDocs[0] ?? null; $er0 = $bd['er'][0] ?? null;
        if (!$re) $zeile = 'offen · keine Rechnung';
        else $zeile = 'Rechnung' . ((string)($re['rechnung_nr'] ?? '') !== '' ? ' ' . $re['rechnung_nr'] : '')
                    . (($re['betrag'] ?? null) !== null ? ' · ' . number_format((float)$re['betrag'], 2, ',', '.') . ' ' . (((string)($re['waehrung'] ?? '') ?: 'EUR') === 'EUR' ? '€' : $re['waehrung']) : '')
                    . ($er0 && !empty($er0['faellig']) && !$istBezahlt ? ' · fällig ' . date('d.m.', strtotime((string)$er0['faellig'])) : '');
        if ($istBezahlt && $bd['bezahlt_am'] !== '') $zeile .= ' · bezahlt am ' . date('d.m.Y', strtotime($bd['bezahlt_am'])) . ((string)($b['zahlungsweg'] ?? '') !== '' ? ' · ' . $b['zahlungsweg'] : '');
        elseif ($er0) $zeile .= ' · Buchhaltung: ' . er_status_text($er0, 'de');
        echo '<div class="bs-zeile"><span>' . h($zeile) . '</span><span class="bs-zeile-knoepfe">';
        foreach ($rechnDocs as $rd) echo '<a class="btn btn-ghost btn-sm" target="_blank" href="?p=dokument&id=' . (int)$rd['id'] . '">Rechnung öffnen</a>';
        foreach (all("SELECT id FROM dokument WHERE objekt_typ='bestellung' AND objekt_id=? AND typ='zahlungsbeleg' ORDER BY id DESC", [(int)$id]) as $zd)
            echo '<a class="btn btn-ghost btn-sm" target="_blank" href="?p=dokument&id=' . (int)$zd['id'] . '">Zahlungsbeleg</a>';
        if (!$rechnDocs && $st !== 'storniert') echo '<button type="button" class="btn btn-primary btn-sm" onclick="bxPopup.open(\'dlgRechnung\')">Rechnung erfassen</button>';
        elseif ($rechnDocs && !$istBezahlt && $st !== 'storniert') echo '<button type="button" class="btn btn-primary btn-sm" onclick="bxPopup.open(\'dlgBezahlt\')">Als bezahlt markieren</button>';
        echo '</span></div>';
    }
    $tabZahlung = ob_get_clean();

    // ---- Reiter 4: Nachrichten (Rückfragen mit dem Lieferanten)
    ob_start();
    if (!empty($b['lieferant_id'])) { require_once BX_ROOT . '/core/nachricht.php'; echo nachricht_panel((int)$b['lieferant_id'], 'team', 'de', 'bestellung', (int)$id); }
    else echo '<p class="muted">Ohne Lieferant keine Rückfragen.</p>';
    $tabNachr = ob_get_clean();

    // ---- Reiter 5: Verlauf und interne Notiz
    ob_start();
    echo '<div class="bs-zeile"><span><span class="muted">Interne Notiz:</span> ' . (trim((string)($b['notiz'] ?? '')) !== '' ? h((string)$b['notiz']) : '–') . '</span>'
       . (trim((string)($b['hinweis_lieferant'] ?? '')) !== '' ? '<span><span class="muted">Hinweis an den Lieferanten:</span> ' . h((string)$b['hinweis_lieferant']) . '</span>' : '')
       . '<button type="button" class="btn btn-ghost btn-sm" onclick="bxPopup.open(\'dlgBearbeiten\')">Ändern</button></div>';
    $vb = function_exists('verlauf_fuer') ? verlauf_fuer('bestellung', (int)$id) : [];
    if ($vb) { echo '<div class="bx-panel">'; bx_chat($vb, (string)$b['nummer']); echo '</div>'; }
    $tabVerlauf = ob_get_clean();

    // ---- Reiter Kosten: Warenwert, Rechnung, Nebenkosten, Gesamtkosten (core/bestellung_kosten.php, 13.11.2026)
    require_once BX_ROOT . '/core/bestellung_kosten.php';
    $tabKosten = bk_panel_html($b);

    // ---- Reiter (Standard = wo der nächste Schritt liegt)
    $weOffen = $offenBest && in_array($st, ['bestellt', 'gesendet'], true);
    $zahlOffen = in_array($hp['art'], ['rechnung', 'bezahlt'], true);
    $standard = ($weOffen || $st === 'offen') ? 'wareneingang' : ($zahlOffen ? 'zahlung' : 'wareneingang');
    echo bs_reiter_html([
        'wareneingang' => ['Wareneingang & Chargen', ($weOffen || $luF) ? '•' : '', $tabWE],
        'versand'      => ['Versand', '', $tabVersand],
        'zahlung'      => ['Zahlung', $zahlOffen ? '•' : '', $tabZahlung],
        'kosten'       => ['Kosten', '', $tabKosten],
        'nachrichten'  => ['Nachrichten', $bd['ungelesen'] > 0 ? (string)$bd['ungelesen'] : '', $tabNachr],
        'verlauf'      => ['Verlauf', '', $tabVerlauf],
    ], $standard);

    // ---- Pop-ups: Rechnung erfassen, Als bezahlt markieren, Stornieren, Altlasten
    if (!empty($b['lieferant_id'])) {
        echo '<dialog id="dlgRechnung" class="bx-dialog"><h2>Rechnung erfassen</h2><form method="post" enctype="multipart/form-data">'
           . '<input type="hidden" name="aktion" value="team_rechnung">'
           . '<div class="bx-field"><label>Rechnung (PDF oder Foto)</label><input type="file" name="re_datei" required accept=".pdf,.jpg,.jpeg,.png,.webp"></div>'
           . '<div class="bx-grid"><div class="bx-field"><label>Rechnungsnummer</label><input type="text" name="re_nr" maxlength="80"></div>'
           . '<div class="bx-field"><label>Rechnungsdatum</label><input type="date" name="re_datum"></div>'
           . '<div class="bx-field"><label>Betrag</label><input type="text" name="re_betrag" inputmode="decimal" placeholder="z. B. 1.250,00"></div>'
           . '<div class="bx-field"><label>Währung</label><input type="text" name="re_waehrung" maxlength="8" value="' . h((string) scalar("SELECT COALESCE(NULLIF(waehrung,''),'EUR') FROM lieferanten WHERE id=?", [(int)$b['lieferant_id']]) ?: 'EUR') . '"></div></div>'
           . '<div class="bx-pop-fuss"><button class="btn btn-primary" type="submit">Rechnung speichern</button></div></form></dialog>';
        echo '<dialog id="dlgBezahlt" class="bx-dialog"><h2>Als bezahlt markieren</h2><form method="post" enctype="multipart/form-data">'
           . '<input type="hidden" name="aktion" value="bezahlt">'
           . '<div class="bx-grid"><div class="bx-field"><label>Bezahlt am</label><input type="date" name="bezahlt_am" value="' . date('Y-m-d') . '"></div>'
           . '<div class="bx-field"><label>Zahlungsweg</label><input type="text" name="zahlungsweg" placeholder="z. B. Konto XY / Alipay"></div></div>'
           . '<div class="bx-field"><label>Überweisungsbestätigung (optional)</label><input type="file" name="zahlbeleg" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>'
           . '<div class="bx-pop-fuss"><button class="btn btn-primary" type="submit">Als bezahlt markieren</button></div></form></dialog>';
    }
    if ($offenBest)
        echo '<dialog id="dlgStorno" class="bx-dialog"><h2>Bestellung stornieren</h2><form method="post" onsubmit="return confirm(\'Diese Bestellung stornieren?\');">'
           . '<input type="hidden" name="aktion" value="storno"><div class="bx-field"><label>Grund</label><input type="text" name="grund" required data-pflicht maxlength="180"></div>'
           . ($istPortal ? '<p class="muted" style="font-size:13px;margin:0">Der Lieferant wird informiert.</p>' : '')
           . '<div class="bx-pop-fuss"><button class="btn btn-primary" type="submit" style="background:#b3261e;border-color:#b3261e;color:#fff">Jetzt stornieren</button></div></form></dialog>';
    if ($offenBest && $schonGeliefert && (has_role('admin') || has_role('einkauf')))   // Fable-6 P11
        echo '<dialog id="dlgRestStorno" class="bx-dialog"><h2>Rest stornieren</h2><form method="post">'
           . '<input type="hidden" name="aktion" value="rest_storno"><p class="muted" style="margin-top:0">Die Bestellung wird geschlossen, die noch offene Menge zählt nicht mehr als unterwegs. Die gelieferte Ware bleibt.</p>'
           . '<div class="bx-field"><label>Grund</label><input type="text" name="grund" required data-pflicht data-pflicht-text="Grund fehlt" maxlength="180" placeholder="z. B. Lieferant liefert den Rest nicht mehr"></div>'
           . '<div class="bx-pop-fuss"><button class="btn btn-primary" type="submit">Rest stornieren</button></div></form></dialog>';
    if ($altlast) echo bestellung_altlast_dialog((int)$id);
}
if (!$neu) echo '<dialog id="dlgBearbeiten" class="bx-dialog" style="max-width:980px"><h2>Bestellung bearbeiten</h2><div>';
?>
<form method="post" class="bx-form">
  <fieldset <?= $geliefert ? 'disabled' : '' ?> style="border:0;padding:0;margin:0;min-width:0">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Lieferant</label>
      <select name="lieferant_id"><option value="">– keiner –</option>
        <?php foreach ($lieferanten as $l): ?><option value="<?= $l['id'] ?>" <?= (int)($b['lieferant_id']??0)===(int)$l['id']?'selected':'' ?>><?= h($l['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Interne Notiz</label><input type="text" name="notiz" value="<?= $v('notiz') ?>" title="Nur intern – der Lieferant sieht sie nie."></div>
    <div class="bx-field"><label>Hinweis an den Lieferanten</label><input type="text" name="hinweis_lieferant" value="<?= $v('hinweis_lieferant') ?>" placeholder="leer = kein Hinweis" title="Steht im Lieferantenportal an der Bestellung. Nur bewusst füllen."></div>
  </div></div>

  <div class="bx-panel">
    <h2>Positionen</h2>
    <table class="bx-table bx-karten-handy">
      <thead><tr><th style="width:34%">Artikel</th><th>Für Auftrag <?= bx_hint('Wofür wird das bestellt? Beim Wareneingang wird die Charge automatisch diesem Auftrag zugeordnet. „Lager / allgemein" = Vorrat.') ?></th><th style="width:120px">Menge</th><th style="width:120px">EK / Einheit</th><th class="bx-num">Summe</th><th></th></tr></thead>
      <tbody id="prows">
        <?php $pr = $positionen ?: [['id'=>0,'item_id'=>'','menge'=>'','ek_preis'=>'','auftrag_id'=>'']]; foreach ($pr as $p):
          $pSperre = !empty($p['id']) ? bestellung_position_gesperrt($p) : '';
          if ($pSperre !== ''):   // Fable-3 T2.6: storniert/geliefert = nur Anzeige, wird beim Speichern nicht verändert ?>
        <tr class="prow-fest" title="Gesperrt: <?= h($pSperre) ?>">
          <td><?= h((string)(scalar("SELECT name FROM item WHERE id=?", [(int)$p['item_id']]) ?: '–')) ?> <span class="muted" style="font-size:12px">(<?= h($pSperre) ?>)</span></td>
          <td class="muted"><?= !empty($p['auftrag_id']) ? h((string)(scalar("SELECT nummer FROM auftrag WHERE id=?", [(int)$p['auftrag_id']]) ?: '')) : 'Lager / allgemein' ?></td>
          <td class="bx-num"><?= h(rtrim(rtrim(number_format((float)$p['menge'],3,',','.'),'0'),',')) ?></td>
          <td class="bx-num"><?= h(preis_anzeige((float)$p['ek_preis'])) ?></td>
          <td class="bx-num">–</td><td></td>
        </tr>
        <?php continue; endif; ?>
        <tr class="prow">
          <td><input type="hidden" name="p_id[]" value="<?= (int)($p['id'] ?? 0) ?>"><select name="p_item[]" class="pitem rscombo" data-ph="Artikel suchen (Name, Synonym) …"><?= pos_options($items, $p['item_id']) ?></select></td>
          <td><select name="p_auftrag[]" class="pauf"><?= auftrag_options($auftraege, $p['auftrag_id'] ?? '') ?></select></td>
          <td><input type="text" inputmode="decimal" class="pmenge" name="p_menge[]" value="<?= h($p['menge']!==''&&$p['menge']!==null ? rtrim(rtrim(number_format((float)$p['menge'],3,',','.'),'0'),',') : '') ?>"></td>
          <td><input type="text" inputmode="decimal" class="pek" name="p_ek[]" value="<?= h($p['ek_preis']!==''&&$p['ek_preis']!==null ? rtrim(rtrim(number_format((float)$p['ek_preis'],6,',',''),'0'),',') : '') ?>"></td>
          <td class="bx-num psum">–</td>
          <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.prow').remove();psum()">×</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th colspan="4" style="text-align:right">Gesamt</th><th class="bx-num" id="pgesamt">–</th><th></th></tr></tfoot>
    </table>
    <?php if (!$neu && count($positionen) > 1): ?><button type="button" class="btn btn-ghost btn-sm" id="addP">+ Position</button><?php else: ?><span class="muted" style="font-size:12px">Eine Bestellung = eine Position (Regel 13.11.2026). Für einen weiteren Artikel eine neue Bestellung anlegen.</span><?php endif; ?>
  </div>
  </fieldset>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <?php if (!$geliefert): ?><button class="btn btn-primary" type="submit">Speichern</button><?php endif; ?>
    <?php if ($neu): ?><a class="btn btn-ghost" href="?p=einkauf">Zurück</a><?php endif; ?>
  </div>
</form>
<?php if (!$neu) echo '</div></dialog>'; ?>

<script>
var EK = <?= json_encode($EK, JSON_UNESCAPED_UNICODE) ?>;
var OPT = <?= json_encode(pos_options($items, 0), JSON_UNESCAPED_UNICODE) ?>;
var OPTA = <?= json_encode(auftrag_options($auftraege, 0), JSON_UNESCAPED_UNICODE) ?>;
function eur(x){ return x.toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})+' €'; }
function psum(){
  var g=0;
  document.querySelectorAll('.prow').forEach(function(r){
    var dz=function(v){ v=String(v||'').trim(); if(v.indexOf(',')>=0) v=v.replace(/\./g,'').replace(',','.'); else if(/^\d{1,3}(\.\d{3})+$/.test(v)) v=v.replace(/\./g,''); return parseFloat(v)||0; };
    var m=dz(r.querySelector('.pmenge').value);
    var e=dz(r.querySelector('.pek').value);
    var s=m*e; g+=s;
    r.querySelector('.psum').textContent = (m&&e)?eur(s):'–';
  });
  document.getElementById('pgesamt').textContent = g?eur(g):'–';
}
(function(){
  var addP=document.getElementById('addP'); if(addP) addP.addEventListener('click',function(){
    var tr=document.createElement('tr'); tr.className='prow';
    tr.innerHTML='<td><input type="hidden" name="p_id[]" value="0"><select name="p_item[]" class="pitem rscombo" data-ph="Artikel suchen (Name, Synonym) …">'+OPT+'</select></td>'
      +'<td><select name="p_auftrag[]" class="pauf">'+OPTA+'</select></td>'
      +'<td><input type="text" inputmode="decimal" class="pmenge" name="p_menge[]"></td>'
      +'<td><input type="text" inputmode="decimal" class="pek" name="p_ek[]"></td>'
      +'<td class="bx-num psum">–</td><td><button type="button" class="btn btn-ghost btn-sm">×</button></td>';
    tr.querySelector('button').addEventListener('click',function(){tr.remove();psum();});
    wire(tr); document.getElementById('prows').appendChild(tr); if(window.bxRsComboInit) window.bxRsComboInit(tr);
  });
  function wire(row){
    row.querySelector('.pitem').addEventListener('change',function(){ var ek=EK[this.value]; if(ek!==undefined && !row.querySelector('.pek').value) row.querySelector('.pek').value=ek; psum(); });
    row.querySelector('.pmenge').addEventListener('input',psum);
    row.querySelector('.pek').addEventListener('input',psum);
  }
  document.querySelectorAll('.prow').forEach(wire);
  psum();
})();
</script>
<?php // Bestellseite 13.11.2026: „Weitere Dokumente“ steht im Reiter Wareneingang & Chargen, Aktionen im Menü „⋯“, Verlauf im Reiter Verlauf.
render_footer();   // Fable-6 P3: Seitenfuß (Handy-Menü, Hell/Dunkel, Doppelklick-Schutz) fehlte
