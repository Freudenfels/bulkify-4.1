<?php
// Werk-App, geführter Ablauf – Aktionen (Nico 11.11.2026, Teil 4/5/9). Wird aus werk.php eingebunden (Login ist geprüft).
// Erwartet: $aktion, $werkName, $werkUid. Bucht über den gemeinsamen Kern core/pa_ablauf.php. Antwort: Flash + zurück zum PA.
require_once BX_ROOT . '/core/erp_ablauf.php';
$paId = (int)($_POST['pa_id'] ?? 0);
$von  = $werkName ?: 'Mitarbeiter';
$zur  = '?p=werk&id=' . $paId;
$waLang = werk_lang(); $T = werk_texte($waLang);   // Block F (11.11.2026): Texte DE/EN/UK, Kern-Meldungen über werk_msg()
$flash = function (string $t, bool $ok) use ($waLang) { $_SESSION['werk_flash'] = werk_msg($t . (!empty($GLOBALS['pa_hinweis']) ? ' ' . $GLOBALS['pa_hinweis'] : ''), $waLang); $_SESSION['werk_flash_ok'] = $ok; };   // Gegenprobe = nur Hinweis
$zahl = fn($k) => pr_menge_parse((string)($_POST[$k] ?? '0'));   // Block D: gleiche Regel überall (135.000 = 135000, 12,5 = 12,5)
$dez  = fn($k) => gewicht_lesen((string)($_POST[$k] ?? '0'));   // 13.11.2026 (Fable-3 T1.2): Gewichte – Punkt und Komma = Dezimal („6.482“ g = 6,482)
$uc = pa_aktuelle_unter_charge($paId);

if ($aktion === 'wa_start') {
    // Bulk/Eimer aus dem Scan (Pflicht). Ältere Charge zuerst (Handbuch 4.8); falsches Material = Sperre.
    $s = erp_wa_scan((string)($_POST['bulk_code'] ?? ''));
    $rollen = erp_wa_rollen($paId);
    if (!$s) { $flash($T['wa_fl_bulk_unknown'], false); weiter($zur); }
    if ($s['item_id'] && ($rollen[$s['item_id']] ?? '') !== 'bulk') {
        pa_ereignis($paId, $uc ? (int)$uc['id'] : null, 'sperre', 'Falsches Material als Bulk gescannt: ' . $s['name'] . ' (' . $s['charge_nr'] . ')', '', '', '', $von);
        $flash(sprintf($T['wa_fl_wrong_bulk'], $s['name']), false); weiter($zur);
    }
    if ($s['prod_charge_id'] && (int)($s['pa_id'] ?? 0) !== $paId) { $flash($T['wa_fl_other_pa'], false); weiter($zur); }
    // Eimer der Reihe nach (Handbuch 4.8): der nächste noch nicht abgefüllte Eimer – ein anderer = STOPP.
    if ($s['prod_charge_id'] && !($uc && (int)$uc['prod_charge_id'] === $s['prod_charge_id'])) {
        $benutzt = array_map('intval', array_column(all("SELECT prod_charge_id FROM pa_unter_charge WHERE pa_id=? AND prod_charge_id IS NOT NULL", [$paId]), 'prod_charge_id'));
        $naechster = null; foreach (erp_gebinde_unterchargen($paId) as $e) if (!in_array((int)$e['id'], $benutzt, true)) { $naechster = $e; break; }
        if ($naechster && (int)$naechster['id'] !== $s['prod_charge_id']) {
            pa_ereignis($paId, $uc ? (int)$uc['id'] : null, 'sperre', 'Falscher Eimer gescannt: ' . $s['charge_nr'] . ' – an der Reihe ist ' . ($naechster['nummer'] ?? '') . ($naechster['sub_kennung'] ?? ''), '', '', '', $von);
            $flash(sprintf($T['wa_fl_wrong_eimer'], ($naechster['nummer'] ?? '') . ($naechster['sub_kennung'] ?? '')), false); weiter($zur);
        }
    }
    $gleicherBulk = $uc && $s['charge_id'] && (int)$uc['bulk_charge_id'] === $s['charge_id'];   // Wiederanlauf: keine erneute Prüfung
    if ($s['charge_id'] && !$gleicherBulk && ($alt = erp_wa_aeltere_bulk($s['item_id'], $s['charge_id'], $s['mhd'])) !== '') {
        // 13.11.2026: Ausnahme „jüngere Charge trotzdem“ serverseitig nur Admin MIT Grund, Ereignis im Chargenprotokoll.
        $aGrund = trim((string)($_POST['aelter_grund'] ?? ''));
        if (empty($_POST['aelter_ok']) || empty($istAdminW) || $aGrund === '') {   // 13.11.2026 (Fable-3 T1.5): PIN-Nutzer der Werk-App ($istAdminW aus werk.php), nicht die Sitzung der Produktions-App
            $flash(sprintf($T['wa_fl_older'], $alt) . (!empty($_POST['aelter_ok']) ? ' ' . $T['wa_fl_older_admin'] : ''), false);
            weiter($zur . '&bulk=' . rawurlencode((string)$_POST['bulk_code']));
        }
        pa_ereignis($paId, $uc ? (int)$uc['id'] : null, 'abweichung', 'Jüngere Bulk-Charge ' . $s['charge_nr'] . ' vor älterer ' . $alt . ' verwendet (Admin-Ausnahme). Grund: ' . $aGrund, '', '', '', $von);
    }
    $fl = pa_foto_speichern($_FILES['foto_linie'] ?? [], 'linie' . $paId);
    $fs = pa_foto_speichern($_FILES['foto_stempel'] ?? [], 'stempel' . $paId);
    $r = pa_starten($paId, ['bulk_charge_id' => $s['charge_id'], 'prod_charge_id' => $s['prod_charge_id'], 'linie' => (string)($_POST['linie'] ?? ''),
        'foto_linie' => $fl, 'foto_stempel' => $fs, 'charge_nr' => (string)($_POST['charge_nr'] ?? ''), 'abweichung_grund' => (string)($_POST['grund'] ?? '')], $von);
    if ($r['ok']) pa_scan_merken($paId, (int)$r['unter_charge_id'], $s['prod_charge_id'] ? 'eimer' : 'bulk', $s['item_id'], $s['charge_id'], $s['gebinde_id'], (string)$_POST['bulk_code'], $von, $s['prod_charge_id']);
    $flash($r['ok'] ? ($r['neu'] ? sprintf($T['wa_fl_started'], $r['charge_nr'], date('d.m.Y', strtotime($r['mhd']))) : sprintf($T['wa_fl_continue'], $r['charge_nr'])) : $r['msg'], $r['ok']);
    weiter($zur . ($r['ok'] ? '' : '&bulk=' . rawurlencode((string)($_POST['bulk_code'] ?? ''))));
}
if (!$uc && $aktion !== 'wa_abschluss') { $flash($T['wa_fl_start_first'], false); weiter($zur); }

if ($aktion === 'wa_scan') {
    $code = (string)($_POST['code'] ?? '');
    $s = erp_wa_scan($code);
    if (!$s) { $flash(sprintf($T['wa_fl_code_unknown'], $code), false); weiter($zur); }
    $rolle = $s['prod_charge_id'] ? 'eimer' : (erp_wa_rollen($paId)[$s['item_id']] ?? '');
    if ($rolle === '') {
        pa_ereignis($paId, (int)$uc['id'], 'sperre', 'Falsches Material gescannt: ' . $s['name'] . ' (' . $s['charge_nr'] . ')', '', '', '', $von);
        $flash(sprintf($T['wa_fl_wrong_mat'], $s['name']), false); weiter($zur);
    }
    // Falscher Eimer/Sack (Handbuch 4.8): ein Bulk/Eimer einer ANDEREN Unter-Charge, solange die aktuelle nicht fertig ist -> Stopp.
    if (in_array($rolle, ['bulk', 'eimer'], true)) {
        $istAkt = ($s['charge_id'] && (int)$uc['bulk_charge_id'] === $s['charge_id']) || ($s['prod_charge_id'] && (int)$uc['prod_charge_id'] === $s['prod_charge_id']);
        if (!$istAkt) {
            pa_ereignis($paId, (int)$uc['id'], 'sperre', 'Falscher Eimer/Sack gescannt: ' . $s['charge_nr'] . ' während ' . $uc['charge_nr'] . ' läuft.', '', '', '', $von);
            $flash(sprintf($T['wa_fl_wrong_batch'], $s['charge_nr'], $uc['charge_nr']), false); weiter($zur);
        }
    }
    if (($s['status'] ?? '') === 'quarantaene') { $flash(sprintf($T['wa_fl_quarantine'], $s['name'], $s['charge_nr']), false); weiter($zur); }
    if (($tf = pa_uc_tag_pruefen($uc)) !== '') { $flash($tf, false); weiter($zur); }   // 13.11.2026: Tagesregel serverseitig
    pa_scan_merken($paId, (int)$uc['id'], $rolle, $s['item_id'], $s['charge_id'], $s['gebinde_id'], $code, $von, $s['prod_charge_id']);
    $flash(sprintf($T['wa_fl_scanned'], $s['name'], $s['charge_nr']), true); weiter($zur);
}
if ($aktion === 'wa_karton') {
    $r = pa_karton_anlegen($paId, (int)$uc['id'], $zahl('menge'), $von);
    if ($r['ok']) { $d = pr_lager_blink_call('p=api_druck&typ=pakarton&id=' . (int)$r['karton_id']);
        $flash(sprintf($T['wa_fl_carton'], $r['nummer'], menge_txt($zahl('menge')), $r['code']) . (!empty($d['ok']) ? $T['wa_fl_printing'] : sprintf($T['wa_fl_created_print'], (string)($d['meldung'] ?? $T['wa_fl_bridge']))), true); }
    else $flash($r['msg'], false);
    weiter($zur);
}
if ($aktion === 'wa_teilmenge') {
    $foto = pa_foto_speichern($_FILES['foto_ware'] ?? [], 'ware' . $paId);
    if ($foto === '') { $flash($T['wa_fl_photo_goods'], false); weiter($zur); }
    $kart = array_column(pa_kartons_offen((int)$uc['id']), 'id');
    $r = pa_teilmenge_buchen($paId, (int)$uc['id'], $zahl('menge'), ['rueckstell' => (int)$zahl('rueckstell'), 'foto' => $foto, 'von' => $von, 'mitarbeiter' => $von, 'kartons' => $kart]);
    $flash($r['msg'], $r['ok']); weiter($zur);
}
if ($aktion === 'wa_kapselprobe' && function_exists('pa_kapselprobe')) {
    $r = pa_kapselprobe((int)$uc['id'], $dez('gramm'), max(1, (int)$zahl('anzahl') ?: 10), $von);
    $flash($r['msg'], $r['ok']); weiter($zur);
}
if ($aktion === 'wa_sack' && function_exists('pa_sackgewicht')) {
    $r = pa_sackgewicht((int)$uc['id'], $dez('brutto'), (int)($_POST['tara_id'] ?? 0), $von);   // 13.11.2026: Tara nur als ID
    $flash($r['msg'], $r['ok']); weiter($zur);
}
if ($aktion === 'wa_abschluss') {
    $r = pa_abschliessen($paId, (string)($_POST['rest'] ?? 'kommt'), $von);
    $flash($r['msg'], $r['ok']); weiter(!empty($r['abgeschlossen']) ? '?p=werk' : $zur);
}
weiter($zur);
