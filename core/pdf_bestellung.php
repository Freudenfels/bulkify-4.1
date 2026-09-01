<?php
// Bestellung als PDF – der Beleg, den der Lieferant per Mail bekommt.
// Gleiche Vorlage wie Angebot und Rechnung; Empfaenger ist hier der Lieferant.
require_once __DIR__ . '/pdf_beleg.php';

// Baut das PDF zu einer Bestellung. null = Bestellung oder Lieferant fehlt.
function bestellung_pdf_bauen(int $bestellung_id): ?string {
    $b = one("SELECT * FROM bestellung WHERE id=?", [$bestellung_id]);
    if (!$b) return null;
    $lf = $b['lieferant_id'] ? one("SELECT * FROM lieferanten WHERE id=?", [(int)$b['lieferant_id']]) : null;
    if (!$lf) return null;                                  // ohne Empfaenger kein Beleg

    // Positionen: Lagerartikel mit Namen, Freitext-Zeilen (item_id NULL) mit ihrer Bezeichnung.
    $positionen = [];
    foreach (all("SELECT bp.*, i.name AS item_name, i.artikelnummer
                  FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id
                  WHERE bp.bestellung_id=? ORDER BY bp.sort, bp.id", [$bestellung_id]) as $p) {
        $bez = (string)($p['item_name'] ?? '') !== '' ? (string)$p['item_name'] : (string)($p['bezeichnung'] ?? 'Position');
        $positionen[] = [
            'artikelnr'   => (string)($p['artikelnummer'] ?? ''),
            'bezeichnung' => $bez,
            'beschreibung'=> '',
            'menge'       => (float)$p['menge'],
            'einheit'     => (string)($p['einheit'] ?? ''),
            'preis_cent'  => (int) round((float)$p['ek_preis'] * 100),
            'ek_cent'     => (int) round((float)$p['ek_preis'] * 100),
            'mwst_satz'   => 0.0,                            // Einkauf: der Lieferant stellt die Steuer selbst
        ];
    }

    $adr = trim(($lf['strasse'] ?? '') . ' ' . ($lf['hausnummer'] ?? '')) . "\n" . trim(($lf['plz'] ?? '') . ' ' . ($lf['ort'] ?? ''));
    if (!empty($lf['land'])) $adr .= "\n" . $lf['land'];

    // Kopftext: was wir erwarten. Bewusst knapp und in der Sprache des Lieferanten.
    $en   = strtolower((string)($lf['sprache'] ?? 'de')) !== 'de';
    $kopf = $en
        ? "Please find our purchase order below. Kindly confirm the order and the planned delivery date."
          . ($b['notiz'] ? "\n" . $b['notiz'] : '')
        : "hiermit bestellen wir wie unten aufgeführt. Bitte bestätigen Sie die Bestellung und den geplanten Liefertermin."
          . ($b['notiz'] ? "\n" . $b['notiz'] : '');

    return build_beleg_pdf([
        'belegart_label'   => $en ? 'Purchase Order' : 'Bestellung',
        'nummer'           => (string)$b['nummer'],
        'empfaenger'       => (string)$lf['firma'],
        'adresse'          => $adr,
        'datum'            => $b['bestelldatum'] ?: $b['angelegt'],
        'gueltig_bis'      => '',
        'kundennummer'     => (string)($lf['lieferantennummer'] ?? ''),
        'version'          => 1,
        'bezug'            => '',
        'bearbeiter'       => '',
        'bearbeiter_email' => '',
        'ust_id'           => (string)($lf['ust_id'] ?? ''),
        'kopf_text'        => $kopf,
        'zahlungsart_label'=> (string)($lf['zahlungsart'] ?? ''),
        'hinweis'          => '',
        'kleinunternehmer' => 1,                             // keine USt auf dem Einkaufsbeleg ausweisen
        'sprache'          => $en ? 'en' : 'de',   // Beschriftungen des Belegs
    ], $positionen, []);
}

// PDF ausliefern (inline im Browser). false = nichts zu liefern.
function bestellung_pdf_ausliefern(int $bestellung_id, string $nummer): bool {
    $pdf = bestellung_pdf_bauen($bestellung_id);
    if ($pdf === null) return false;
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Bestellung_' . preg_replace('/[^A-Za-z0-9_-]/', '', $nummer) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    return true;
}
