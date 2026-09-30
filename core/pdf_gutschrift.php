<?php
// Gutschrift / Storno-Rechnung als PDF – gleiche Vorlage wie Angebot und Rechnung (build_beleg_pdf).
require_once __DIR__ . '/pdf_beleg.php';

// Baut das PDF zu einer Gutschrift (Beleg typ=gutschrift). null = Beleg fehlt.
function gutschrift_pdf_bauen(int $beleg_id): ?string {
    $b = one("SELECT * FROM beleg WHERE id=? AND typ='gutschrift'", [$beleg_id]);
    if (!$b) return null;
    $k = $b['kunde_id'] ? one("SELECT * FROM kunden WHERE id=?", [(int)$b['kunde_id']]) : null;
    $positionen = beleg_positionen($beleg_id);

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

    $bezug = $b['storno_von_id'] ? ('Storno zu Rechnung ' . (string)(scalar("SELECT nummer FROM beleg WHERE id=?", [(int)$b['storno_von_id']]) ?: '')) : '';
    $kopf = 'Hiermit erteilen wir Ihnen folgende Gutschrift / Storno:' . (!empty($b['grund']) ? "\n" . $b['grund'] : '');
    $klein = $istInland ? (meta_get('kleinunternehmer', '0') === '1' ? 1 : 0) : 1;

    return build_beleg_pdf([
        'belegart_label'   => 'Storno-Rechnung / Gutschrift',
        'nummer'           => (string)$b['nummer'],
        'empfaenger'       => (string)$firma,
        'adresse'          => $adr,
        'datum'            => $b['datum'] ?: $b['angelegt'],
        'gueltig_bis'      => '',
        'kundennummer'     => (string)($k['kundennummer'] ?? ''),
        'version'          => 1,
        'bezug'            => $bezug,
        'bearbeiter'       => '',
        'bearbeiter_email' => '',
        'ust_id'           => (string)($k['ust_id'] ?? ''),
        'kopf_text'        => $kopf,
        'zahlungsart_label'=> '',
        'hinweis'          => 'Der ausgewiesene Betrag wird Ihnen gutgeschrieben bzw. mit offenen Posten verrechnet.',
        'kleinunternehmer' => $klein,
        'sprache'          => 'de',
    ], $positionen, []);
}

// PDF ausliefern (inline im Browser). false = nichts zu liefern.
function gutschrift_pdf_ausliefern(int $beleg_id, string $nummer): bool {
    $pdf = gutschrift_pdf_bauen($beleg_id);
    if ($pdf === null) return false;
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Gutschrift_' . preg_replace('/[^A-Za-z0-9_-]/', '', $nummer) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    return true;
}
