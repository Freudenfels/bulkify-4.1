<?php
// Lieferschein (LS) als PDF – über den gemeinsamen Beleg-Baukasten (core/pdf_beleg.php).
// Ein Lieferschein begleitet die (Teil-)Lieferung an den Kunden: er zeigt WAS und WIE VIEL geliefert
// wird (Packungen), inkl. Charge/MHD – aber KEINE Preise/USt (das steht auf der Rechnung).
// Der Beleg (typ=lieferschein) entsteht beim Versand automatisch; hier wird er nur druckbar gemacht.
require_once __DIR__ . '/pdf_beleg.php';
require_once __DIR__ . '/schema.php';

function lieferschein_pdf_bauen(int $beleg_id): ?string {
    $b = one("SELECT * FROM beleg WHERE id=? AND typ='lieferschein'", [$beleg_id]);
    if (!$b) return null;
    $a = !empty($b['auftrag_id'])
        ? one("SELECT a.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt_name
               FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [(int)$b['auftrag_id']])
        : null;
    $k = !empty($b['kunde_id']) ? one("SELECT * FROM kunden WHERE id=?", [(int)$b['kunde_id']]) : null;
    if (!$k) return null;                                   // ohne Empfänger kein Beleg

    $land = strtoupper(trim((string)($k['land'] ?? '')));
    $istInland = ($land === '' || in_array($land, ['DE', 'D', 'DEUTSCHLAND', 'GERMANY'], true));

    // Gelieferte Menge = beleg.menge (Teillieferung); Fallback auf Auftragsmenge.
    $menge = (float)($b['menge'] ?? 0);
    if ($menge <= 0 && $a) $menge = (float)($a['menge'] ?? 0);

    // Produkt-Beschreibung aus dem Auftrag (Stück je Packung + Verpackung).
    $name = $a ? ((string)($a['produkt_name'] ?? '') ?: (string)($a['produkt_bezeichnung'] ?? '')) : '';
    if ($name === '') $name = 'Produkt';
    $beschParts = [];
    if ($a) {
        $stk = (int)($a['stueck'] ?? 0);
        if ($stk > 0) $beschParts[] = $stk . ' Stück je Packung';
        if (!empty($a['verpackung_id'])) {
            $vName = (string) scalar("SELECT name FROM item WHERE id=?", [(int)$a['verpackung_id']]);
            if ($vName !== '') $beschParts[] = $vName;
        }
    }
    $positionen = [[
        'artikelnr'   => '',
        'bezeichnung' => $name,
        'beschreibung'=> implode(' · ', $beschParts),
        'menge'       => $menge,
        'einheit'     => 'Pkg.',
        'preis_cent'  => 0,
        'mwst_satz'   => 0,
    ]];

    // Charge(n) + MHD für den Hinweis (falls schon produziert/eingebucht).
    $hinweis = '';
    if ($a) {
        $chargen = all("SELECT charge_nr, mhd FROM charge WHERE auftrag_id=? AND TRIM(COALESCE(charge_nr,''))<>'' ORDER BY id", [(int)$a['id']]);
        if ($chargen) {
            $teile = [];
            foreach ($chargen as $c) $teile[] = $c['charge_nr'] . ($c['mhd'] ? ' (MHD ' . date('d.m.Y', strtotime((string)$c['mhd'])) . ')' : '');
            $hinweis = 'Charge: ' . implode(', ', $teile);
        }
    }

    // Teillieferungs-Hinweis im Kopftext, wenn der Auftrag noch nicht komplett versendet ist.
    $kopf = 'Hiermit liefern wir Ihnen die folgende Ware. Bitte prüfen Sie die Lieferung bei Erhalt.';
    if ($a) {
        $gesamt   = (int)($a['menge'] ?? 0);
        $versendet= max(0, (int)($a['versendet_menge'] ?? 0));
        if ($gesamt > 0 && $versendet > 0 && $versendet < $gesamt)
            $kopf .= "\nTeillieferung: " . number_format($menge, 0, ',', '.') . ' von ' . number_format($gesamt, 0, ',', '.')
                   . ' Packungen (gesamt geliefert ' . number_format($versendet, 0, ',', '.') . ').';
    }

    $angNr = ($a && !empty($a['angebot_id'])) ? (string) scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$a['angebot_id']]) : '';
    $bezug = $a ? ('Auftrag ' . (string)$a['nummer'] . ($angNr !== '' ? ' · Angebot ' . $angNr : '')) : '';

    $adr = trim(($k['strasse'] ?? '') . ' ' . ($k['hausnummer'] ?? '')) . "\n" . trim(($k['plz'] ?? '') . ' ' . ($k['ort'] ?? ''));
    if (!$istInland && !empty($k['land'])) $adr .= "\n" . $k['land'];

    return build_beleg_pdf([
        'belegart_label' => 'Lieferschein',
        'nummer'         => $b['nummer'],
        'empfaenger'     => $k['firma'],
        'adresse'        => $adr,
        'datum'          => $b['datum'] ?? gmdate('Y-m-d'),
        'kundennummer'   => $k['kundennummer'] ?? '',
        'bezug'          => $bezug,
        'ust_id'         => $k['ust_id'] ?? '',
        'kopf_text'      => $kopf,
        'hinweis'        => $hinweis,
        'lieferschein'   => true,     // Lieferschein-Modus: keine Preise/Summen/Zahlung/Bank – nur Mengen.
    ], $positionen);
}

// PDF ausliefern (inline im Browser). false, wenn es den Beleg/Kunden nicht gibt.
function lieferschein_pdf_ausliefern(int $beleg_id, string $nummer): bool {
    $pdf = lieferschein_pdf_bauen($beleg_id);
    if ($pdf === null) return false;
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Lieferschein_' . preg_replace('/[^A-Za-z0-9_-]/', '', $nummer) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    return true;
}
