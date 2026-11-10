<?php
// Ziel des Pop-ups „Preis & Unterlagen“ (core/rohstoff_unterlagen.php): Lieferantenpreis ab Menge speichern und/oder CoA/Spec
// am Rohstoff ablegen; die KI liest die Unterlage aus und füllt Kennwerte, Grenzwerte, Wirkstoffe und leere Stammdaten.
// Nur POST, danach zurück auf die aufrufende Seite (nur interne ?p=-Links).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/dokument_ui.php';

$zurueck = (string)($_POST['zurueck'] ?? '');
if (!preg_match('/^\?p=[a-z0-9_]+[^\s<>"]*$/i', $zurueck)) $zurueck = '?p=rohstoff_unterlagen';
$zurueck = preg_replace('/&re_[a-z]+=[^&]*/', '', $zurueck);   // alte Rückmeldung nicht mitschleppen
$iid = (int)($_POST['item_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !one("SELECT id FROM item WHERE id=? AND kategorie='rohstoff'", [$iid])) { header('Location: ' . $zurueck); exit; }

$lid   = (int)($_POST['lieferant_id'] ?? 0);
$lid   = $lid && one("SELECT id FROM lieferanten WHERE id=?", [$lid]) ? $lid : 0;
$preis = zahl_lesen((string)($_POST['preis'] ?? ''));
$ab    = max(0.0, zahl_lesen((string)($_POST['menge_ab'] ?? ''), true));
$r = ['re_ok' => $iid];
if ($preis > 0 && !$lid) $r['re_ohnelief'] = 1;
if ($lid && $preis > 0) {
    q("INSERT INTO lieferant_preis (item_id,lieferant_id,menge_ab,preis,waehrung,stand,quelle) VALUES (?,?,?,?, 'EUR', CURDATE(), 'manuell')", [$iid, $lid, $ab, $preis]);
    item_ek_aus_lieferantenpreis($iid);
    log_aktivitaet('item', $iid, 'team', 'Lieferantenpreis erfasst: ' . number_format($preis, 2, ',', '.') . ' EUR/kg ab ' . number_format($ab, 0, ',', '.') . ' kg ('
        . scalar("SELECT firma FROM lieferanten WHERE id=?", [$lid]) . ')', 'preis', 'item', $iid);
    $r['re_preis'] = 1;
}
if (!empty($_FILES['dok']['name'])) {
    $_POST['dok_typ'] = in_array($_POST['dok_typ'] ?? '', ['spec', 'coa', 'analyse'], true) ? $_POST['dok_typ'] : 'spec';
    $_POST['dok_lieferant'] = $lid ?: '';
    $did = dokument_upload('item', $iid);
    if ($did) {
        $r['re_dok'] = 1;
        if (!empty($_POST['ki_fuellen'])) {
            require_once BX_ROOT . '/core/spec_ki.php';
            // spec_ki_nach_upload: liest aus, legt bei CoA eine Vorab-Charge an, ergänzt Grenzwerte, Kennwerte und Wirkstoffe.
            if (spec_ki_nach_upload($did) && ($erg = spec_ki_vorschlag($did))) {
                // Leere Stammdaten gleich füllen – vorhandene Werte bleiben unangetastet.
                $r['re_ki'] = 1;
                $r['re_kin'] = spec_ki_uebernehmen($iid, (array)($erg['stamm'] ?? []), array_keys(spec_ki_felder()), false);
            } else $r['re_kioff'] = 1;
        }
    } else $r['re_dokfehler'] = 1;
}
header('Location: ' . $zurueck . (str_contains($zurueck, '?') ? '&' : '?') . http_build_query($r)); exit;
