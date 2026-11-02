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
    // Bulk-Positionen desselben Produkts („Bulk: X (AB-..)" + alte Puffer-Zeile „Bulk: X") werden zu EINER
    // Zeile je Produkt mit Gesamtmenge zusammengefasst – wie in der Portal- und der internen Ansicht.
    $positionen = []; $bulkIdx = [];
    foreach (all("SELECT bp.*, i.name AS item_name, i.artikelnummer,
                         COALESCE(i.rezeptur_id, au.rezeptur_id, pr.rezeptur_id) AS rezeptur_id
                  FROM bestellung_position bp
                  LEFT JOIN item i     ON i.id = bp.item_id
                  LEFT JOIN auftrag au ON au.id = bp.auftrag_id
                  LEFT JOIN produkt pr ON pr.id = au.produkt_id
                  WHERE bp.bestellung_id=? ORDER BY bp.sort, bp.id", [$bestellung_id]) as $p) {
        $istBulk = empty($p['item_id']) && strncmp((string)($p['bezeichnung'] ?? ''), 'Bulk', 4) === 0;
        $bez = (string)($p['item_name'] ?? '') !== '' ? (string)$p['item_name'] : (string)($p['bezeichnung'] ?? 'Position');
        if ($istBulk) $bez = trim(preg_replace('/\s*\(.*$/', '', preg_replace('/^Bulk:\s*/', '', (string)$p['bezeichnung']))) ?: 'Bulk';
        // ek_preis-Snapshot, sonst bekannter Lieferantenpreis (Bulk/Fremdfertigung) – wie in der Portal-Ansicht.
        $ekEff = bestellposition_ek($p, (int)$b['lieferant_id']);
        if ($istBulk) {
            $key = mb_strtolower($bez);
            if (isset($bulkIdx[$key])) { $positionen[$bulkIdx[$key]]['menge'] += (float)$p['menge']; continue; }
            $bulkIdx[$key] = count($positionen);
        }
        $positionen[] = [
            'artikelnr'   => (string)($p['artikelnummer'] ?? ''),
            'bezeichnung' => $bez,
            'beschreibung'=> '',
            'menge'       => (float)$p['menge'],
            'einheit'     => (string)($p['einheit'] ?? ''),
            'preis_cent'  => (int) round($ekEff * 100),
            'ek_cent'     => (int) round($ekEff * 100),
            'mwst_satz'   => 0.0,                            // Einkauf: der Lieferant stellt die Steuer selbst
        ];
    }

    $adr = trim(($lf['strasse'] ?? '') . ' ' . ($lf['hausnummer'] ?? '')) . "\n" . trim(($lf['plz'] ?? '') . ' ' . ($lf['ort'] ?? ''));
    if (!empty($lf['land'])) $adr .= "\n" . $lf['land'];

    // Kopftext (St-21): Anfrage zur Bestätigung – NACH der Bestätigung der Stand (bestätigt am, geplanter Liefertermin). Die interne
    // Notiz der Bestellung („wartet auf Bestätigung", Auftragsbezug, Express-Vermerke) ist intern und steht NICHT auf dem PDF.
    $en   = strtolower((string)($lf['sprache'] ?? 'de')) !== 'de';
    $bestaetigt = (int)($b['bestaetigt'] ?? 0) === 1;
    $fmtD = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '';
    if ($bestaetigt) {
        $eta = $fmtD($b['eta_geplant'] ?? '');
        $kopf = $en
            ? "Thank you for confirming our purchase order" . ($eta !== '' ? " (planned delivery: $eta)" : '') . ". The order details are listed below."
            : "Vielen Dank für die Bestätigung unserer Bestellung" . ($eta !== '' ? " (geplanter Liefertermin: $eta)" : '') . ". Die Bestelldaten stehen unten.";
    } else {
        $kopf = $en
            ? "Please find our purchase order below. Kindly confirm the order and the planned delivery date."
            : "hiermit bestellen wir wie unten aufgeführt. Bitte bestätigen Sie die Bestellung und den geplanten Liefertermin.";
    }

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
        'zahlungsziel_tage'=> (int)($lf['zahlungsziel_tage'] ?? 0),   // Zahlungsziel des LIEFERANTEN (sonst keine Zahlungszeile)
        'hinweis'          => '',
        'sprache'          => $en ? 'en' : 'de',   // Beschriftungen des Belegs (Einkaufsbeleg: kein § 19-Hinweis, keine eigene Bank, kein Kundenhinweis)
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
