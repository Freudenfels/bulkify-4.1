<?php
// Export-Endpunkt der Buchhaltung: liefert CSV- bzw. DATEV-Dateien als Download.
// Route: beleg_export (Rolle finance). ?art=op|belege|datev [&von=Y-m-d&bis=Y-m-d]
require_once BX_ROOT . '/core/buchhaltung.php';
require_once BX_ROOT . '/core/kreditor.php';

$art = preg_replace('/[^a-z_]/', '', $_GET['art'] ?? '');
$von = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['von'] ?? '') ? $_GET['von'] : '';
$bis = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['bis'] ?? '') ? $_GET['bis'] : '';
$heute = date('Y-m-d');

switch ($art) {
    case 'op':
        $data = bh_export_op_csv();
        $name = "offene-posten_$heute.csv";
        $ct = 'text/csv; charset=utf-8';
        break;
    case 'belege':
        $data = bh_export_belege_csv($von, $bis);
        $name = "belege_" . ($von ?: 'alle') . "_" . ($bis ?: $heute) . ".csv";
        $ct = 'text/csv; charset=utf-8';
        break;
    case 'datev':
        $data = bh_export_datev($von, $bis);
        // DATEV-Konvention: EXTF_<Mandant>_<Zeitstempel>.csv
        $name = "EXTF_Rechnungsausgang_" . date('Ymd_His') . ".csv";
        $ct = 'text/csv; charset=windows-1252';
        break;
    case 'vop': // offene Verbindlichkeiten (Kreditoren-OP)
        $data = kr_export_vop_csv();
        $name = "verbindlichkeiten_$heute.csv";
        $ct = 'text/csv; charset=utf-8';
        break;
    case 'lief_belege': // erfasste Eingangsrechnungen
        $data = kr_export_belege_csv($von, $bis);
        $name = "eingangsrechnungen_" . ($von ?: 'alle') . "_" . ($bis ?: $heute) . ".csv";
        $ct = 'text/csv; charset=utf-8';
        break;
    case 'datev_ek': // DATEV Rechnungseingang (Kreditoren)
        $data = kr_export_datev($von, $bis);
        $name = "EXTF_Rechnungseingang_" . date('Ymd_His') . ".csv";
        $ct = 'text/csv; charset=windows-1252';
        break;
    default:
        http_response_code(400);
        echo 'Unbekannte Export-Art.';
        exit;
}

header('Content-Type: ' . $ct);
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . strlen($data));
header('X-Content-Type-Options: nosniff');
echo $data;
exit;
