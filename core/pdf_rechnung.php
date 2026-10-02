<?php
// Rechnungs-PDF fürs Team – aus einem Beleg (typ='rechnung') und seinen eigenen Positionen.
// Funktioniert für freie Rechnungen (rechnung_frei) UND für aus einem Auftrag erzeugte Rechnungen,
// weil beide echte beleg_position-Zeilen haben. Nutzt denselben Builder wie Angebot/Portal (pdf_beleg.php).
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/pdf_beleg.php';

function rechnung_pdf_bauen(int $beleg_id): ?string {
    $b = one("SELECT * FROM beleg WHERE id=? AND typ='rechnung'", [$beleg_id]);
    if (!$b) return null;
    $positionen = beleg_positionen($beleg_id);
    if (!$positionen) return null;   // ohne Positionen kein sinnvolles PDF
    $k = $b['kunde_id'] ? one("SELECT * FROM kunden WHERE id=?", [(int)$b['kunde_id']]) : null;

    // Empfänger: Rechnungsadresse bevorzugt, sonst Hauptadresse.
    $firma = $k ? (trim((string)($k['rechnung_firma'] ?? '')) ?: (string)$k['firma']) : 'Kunde';
    $str = $k ? (trim((string)($k['rechnung_strasse'] ?? '')) ?: (string)($k['strasse'] ?? '')) : '';
    $hnr = $k ? (trim((string)($k['rechnung_hausnummer'] ?? '')) ?: (string)($k['hausnummer'] ?? '')) : '';
    $plz = $k ? (trim((string)($k['rechnung_plz'] ?? '')) ?: (string)($k['plz'] ?? '')) : '';
    $ort = $k ? (trim((string)($k['rechnung_ort'] ?? '')) ?: (string)($k['ort'] ?? '')) : '';
    $land = $k ? (trim((string)($k['rechnung_land'] ?? '')) ?: (string)($k['land'] ?? '')) : '';
    $adr = trim($str . ' ' . $hnr) . "\n" . trim($plz . ' ' . $ort);
    $landU = strtoupper(trim($land));
    $istInland = ($landU === '' || in_array($landU, ['DE', 'D', 'DEUTSCHLAND', 'GERMANY'], true));
    if (!$istInland && $land !== '') $adr .= "\n" . $land;

    // Datum / Fälligkeit / Zahlungsbedingung
    $datum = $b['datum'] ?: $b['angelegt'];
    $faellig = trim((string)($b['faellig'] ?? ''));
    $ziel = ($b['zahlungsziel_tage'] !== null && $b['zahlungsziel_tage'] !== '') ? (int)$b['zahlungsziel_tage'] : null;
    if ($faellig === '' && $ziel !== null) $faellig = date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days'));
    if ($ziel !== null) $zahlbed = 'Zahlbar innerhalb von ' . $ziel . ' Tagen ohne Abzug.';
    else                $zahlbed = (string) meta_get('bh_zahlungsbedingung', 'Sofort zahlbar ohne Abzug.');

    // Bezug + Kopf-/Rechnungstext
    $bezug = '';
    if (!empty($b['auftrag_id'])) {
        $an = scalar("SELECT nummer FROM auftrag WHERE id=?", [(int)$b['auftrag_id']]);
        if ($an) $bezug = 'Auftrag ' . (string)$an;
    }
    $kopf = 'Wir berechnen Ihnen wie folgt:';
    // Leistungs-/Lieferdatum gehört auf die Rechnung (GoBD) – als eigene Zeile in den Kopftext,
    // wenn gesetzt und abweichend vom Rechnungsdatum.
    $leist = trim((string)($b['leistung_datum'] ?? ''));
    if ($leist !== '' && $leist !== substr((string)$datum, 0, 10)) $kopf .= "\nLeistungs-/Lieferdatum: " . date('d.m.Y', strtotime($leist));
    $freitext = trim((string)($b['text'] ?? ''));
    if ($freitext !== '') $kopf .= "\n" . $freitext;

    $klein = $istInland ? (meta_get('kleinunternehmer', '0') === '1' ? 1 : 0) : 1;
    $zaMap = ['vorkasse'=>'Vorkasse','rechnung'=>'Rechnung','lastschrift'=>'Lastschrift','paypal'=>'PayPal'];
    $zaKey = (string)($k['zahlungsart'] ?? 'vorkasse');
    $za = $zaMap[$zaKey] ?? ucfirst($zaKey);
    return build_beleg_pdf([
        'belegart_label'    => 'Rechnung',
        'nummer'            => (string)$b['nummer'],
        'empfaenger'        => (string)$firma,
        'adresse'           => $adr,
        'datum'             => $datum,
        'faellig_bis'       => $faellig,
        'kundennummer'      => (string)($k['kundennummer'] ?? ''),
        'bezug'             => $bezug,
        'bearbeiter'        => '',
        'bearbeiter_email'  => '',
        'ust_id'            => (string)($k['ust_id'] ?? ''),
        'kopf_text'         => $kopf,
        'zahlungsbedingung' => $zahlbed,
        'zahlungsart_label' => $za,
        'kleinunternehmer'  => $klein,
        'sprache'           => 'de',
    ], $positionen, []);
}

// PDF ausliefern (inline im Browser). false = nichts zu liefern.
function rechnung_pdf_ausliefern(int $beleg_id, string $nummer): bool {
    $pdf = rechnung_pdf_bauen($beleg_id);
    if ($pdf === null) return false;
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Rechnung_' . preg_replace('/[^A-Za-z0-9_-]/', '', $nummer) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    return true;
}
