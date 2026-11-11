<?php
// Werk-App, geführter Ablauf – Aktionen (Nico 11.11.2026, Teil 4/5/9). Wird aus werk.php eingebunden (Login ist geprüft).
// Erwartet: $aktion, $werkName, $werkUid. Bucht über den gemeinsamen Kern core/pa_ablauf.php. Antwort: Flash + zurück zum PA.
require_once BX_ROOT . '/core/erp_ablauf.php';
$paId = (int)($_POST['pa_id'] ?? 0);
$von  = $werkName ?: 'Mitarbeiter';
$zur  = '?p=werk&id=' . $paId;
$flash = function (string $t, bool $ok) { $_SESSION['werk_flash'] = $t; $_SESSION['werk_flash_ok'] = $ok; };
$zahl = fn($k) => (float) str_replace(',', '.', str_replace('.', '', (string)($_POST[$k] ?? '0')));
$dez  = fn($k) => (float) str_replace(',', '.', (string)($_POST[$k] ?? '0'));
$uc = pa_aktuelle_unter_charge($paId);

if ($aktion === 'wa_start') {
    // Bulk/Eimer aus dem Scan (Pflicht). Ältere Charge zuerst (Handbuch 4.8); falsches Material = Sperre.
    $s = erp_wa_scan((string)($_POST['bulk_code'] ?? ''));
    $rollen = erp_wa_rollen($paId);
    if (!$s) { $flash('Bulk nicht erkannt – bitte das Etikett am Sack/Eimer scannen.', false); weiter($zur); }
    if ($s['item_id'] && ($rollen[$s['item_id']] ?? '') !== 'bulk') {
        pa_ereignis($paId, $uc ? (int)$uc['id'] : null, 'sperre', 'Falsches Material als Bulk gescannt: ' . $s['name'] . ' (' . $s['charge_nr'] . ')', '', '', '', $von);
        $flash('SPERRE: ' . $s['name'] . ' gehört nicht zu diesem Auftrag. Bitte den richtigen Bulk scannen.', false); weiter($zur);
    }
    if ($s['prod_charge_id'] && (int)($s['pa_id'] ?? 0) !== $paId) { $flash('SPERRE: Dieser Eimer gehört zu einem anderen Produktionsauftrag.', false); weiter($zur); }
    $gleicherBulk = $uc && $s['charge_id'] && (int)$uc['bulk_charge_id'] === $s['charge_id'];   // Wiederanlauf: keine erneute Prüfung
    if ($s['charge_id'] && !$gleicherBulk && ($alt = erp_wa_aeltere_bulk($s['item_id'], $s['charge_id'], $s['mhd'])) !== '' && empty($_POST['aelter_ok'])) {
        $flash('STOPP: Es gibt eine ältere Bulk-Charge (' . $alt . ') – bitte zuerst die ältere verbrauchen.', false); weiter($zur . '&bulk=' . rawurlencode((string)$_POST['bulk_code']));
    }
    $fl = pa_foto_speichern($_FILES['foto_linie'] ?? [], 'linie' . $paId);
    $fs = pa_foto_speichern($_FILES['foto_stempel'] ?? [], 'stempel' . $paId);
    $r = pa_starten($paId, ['bulk_charge_id' => $s['charge_id'], 'prod_charge_id' => $s['prod_charge_id'], 'linie' => (string)($_POST['linie'] ?? ''),
        'foto_linie' => $fl, 'foto_stempel' => $fs, 'charge_nr' => (string)($_POST['charge_nr'] ?? ''), 'abweichung_grund' => (string)($_POST['grund'] ?? '')], $von);
    if ($r['ok']) pa_scan_merken($paId, (int)$r['unter_charge_id'], $s['prod_charge_id'] ? 'eimer' : 'bulk', $s['item_id'], $s['charge_id'], $s['gebinde_id'], (string)$_POST['bulk_code'], $von, $s['prod_charge_id']);
    $flash($r['ok'] ? ($r['neu'] ? 'Gestartet: Charge ' . $r['charge_nr'] . ' · MHD ' . date('d.m.Y', strtotime($r['mhd'])) . ' – Stempel so einstellen.' : 'Weiter mit Charge ' . $r['charge_nr'] . '.') : $r['msg'], $r['ok']);
    weiter($zur . ($r['ok'] ? '' : '&bulk=' . rawurlencode((string)($_POST['bulk_code'] ?? ''))));
}
if (!$uc && $aktion !== 'wa_abschluss') { $flash('Bitte zuerst starten (Bulk scannen, Fotos Linie und Stempel).', false); weiter($zur); }

if ($aktion === 'wa_scan') {
    $code = (string)($_POST['code'] ?? '');
    $s = erp_wa_scan($code);
    if (!$s) { $flash('Code nicht erkannt: ' . $code, false); weiter($zur); }
    $rolle = $s['prod_charge_id'] ? 'eimer' : (erp_wa_rollen($paId)[$s['item_id']] ?? '');
    if ($rolle === '') {
        pa_ereignis($paId, (int)$uc['id'], 'sperre', 'Falsches Material gescannt: ' . $s['name'] . ' (' . $s['charge_nr'] . ')', '', '', '', $von);
        $flash('SPERRE: ' . $s['name'] . ' gehört nicht zu diesem Auftrag.', false); weiter($zur);
    }
    // Falscher Eimer/Sack (Handbuch 4.8): ein Bulk/Eimer einer ANDEREN Unter-Charge, solange die aktuelle nicht fertig ist -> Stopp.
    if (in_array($rolle, ['bulk', 'eimer'], true)) {
        $istAkt = ($s['charge_id'] && (int)$uc['bulk_charge_id'] === $s['charge_id']) || ($s['prod_charge_id'] && (int)$uc['prod_charge_id'] === $s['prod_charge_id']);
        if (!$istAkt) {
            pa_ereignis($paId, (int)$uc['id'], 'sperre', 'Falscher Eimer/Sack gescannt: ' . $s['charge_nr'] . ' während ' . $uc['charge_nr'] . ' läuft.', '', '', '', $von);
            $flash('STOPP: Falsche Charge (' . $s['charge_nr'] . ') – erst den angefangenen Bulk von ' . $uc['charge_nr'] . ' komplett abfüllen. Ist er leer: „Chargenwechsel“.', false); weiter($zur);
        }
    }
    if (($s['status'] ?? '') === 'quarantaene') { $flash('SPERRE: ' . $s['name'] . ' (' . $s['charge_nr'] . ') steht in Quarantäne.', false); weiter($zur); }
    pa_scan_merken($paId, (int)$uc['id'], $rolle, $s['item_id'], $s['charge_id'], $s['gebinde_id'], $code, $von, $s['prod_charge_id']);
    $flash('Gescannt: ' . $s['name'] . ' · ' . $s['charge_nr'], true); weiter($zur);
}
if ($aktion === 'wa_karton') {
    $r = pa_karton_anlegen($paId, (int)$uc['id'], $zahl('menge'), $von);
    if ($r['ok']) { $d = pr_lager_blink_call('p=api_druck&typ=pakarton&id=' . (int)$r['karton_id']);
        $flash('Karton ' . $r['nummer'] . ' (' . menge_txt($zahl('menge')) . ' Stk, ' . $r['code'] . ') ' . (!empty($d['ok']) ? 'wird gedruckt.' : 'angelegt – Druck: ' . (string)($d['meldung'] ?? 'Brücke prüfen')), true); }
    else $flash($r['msg'], false);
    weiter($zur);
}
if ($aktion === 'wa_teilmenge') {
    $foto = pa_foto_speichern($_FILES['foto_ware'] ?? [], 'ware' . $paId);
    if ($foto === '') { $flash('Bitte ein Foto der fertigen Ware machen.', false); weiter($zur); }
    $kart = array_column(pa_kartons_offen((int)$uc['id']), 'id');
    $r = pa_teilmenge_buchen($paId, (int)$uc['id'], $zahl('menge'), ['rueckstell' => (int)$zahl('rueckstell'), 'foto' => $foto, 'von' => $von, 'mitarbeiter' => $von, 'kartons' => $kart]);
    $flash($r['msg'], $r['ok']); weiter($zur);
}
if ($aktion === 'wa_kapselprobe' && function_exists('pa_kapselprobe')) {
    $r = pa_kapselprobe((int)$uc['id'], $dez('gramm'), max(1, (int)$zahl('anzahl') ?: 10), $von);
    $flash($r['msg'], $r['ok']); weiter($zur);
}
if ($aktion === 'wa_sack' && function_exists('pa_sackgewicht')) {
    $r = pa_sackgewicht((int)$uc['id'], $dez('brutto'), $dez('tara'), (string)($_POST['tara_name'] ?? ''), $von);
    $flash($r['msg'], $r['ok']); weiter($zur);
}
if ($aktion === 'wa_abschluss') {
    $r = pa_abschliessen($paId, (string)($_POST['rest'] ?? 'kommt'), $von);
    $flash($r['msg'], $r['ok']); weiter(!empty($r['abgeschlossen']) ? '?p=werk' : $zur);
}
weiter($zur);
