<?php
// Auftragsbestätigung (AB) als PDF – über den gemeinsamen Beleg-Baukasten (core/pdf_beleg.php).
// Alle Infos zum Auftrag: Kunde, Produkt, Menge, Stück je Packung, Verpackung, Preis, Charge/MHD, Datum.
require_once __DIR__ . '/pdf_beleg.php';
require_once __DIR__ . '/schema.php';

function auftrag_pdf_bauen(int $auftrag_id): ?string {
    $a = one("SELECT a.*, COALESCE(NULLIF(pkn.name,''), NULLIF(p.kundenname,''), p.name) AS produkt_name, r.darreichungsform
              FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                     LEFT JOIN produkt_kunde_name pkn ON pkn.produkt_id=p.id AND pkn.kunde_id=a.kunde_id
              WHERE a.id=?", [$auftrag_id]);
    if (!$a) return null;
    $k = $a['kunde_id'] ? one("SELECT * FROM kunden WHERE id=?", [(int)$a['kunde_id']]) : null;
    if (!$k) return null;                                   // ohne Empfänger kein Beleg

    $land = strtoupper(trim((string)($k['land'] ?? '')));
    $istInland = ($land === '' || in_array($land, ['DE', 'D', 'DEUTSCHLAND', 'GERMANY'], true));
    $ustP = (meta_get('kleinunternehmer', '0') === '1' || !$istInland) ? 0.0 : (float) meta_get('ust_inland', 19);

    // Produkt-Name (Fallback auf die am Auftrag gespeicherte Bezeichnung) + Konfiguration.
    $name  = (string)($a['produkt_name'] ?? '') ?: (string)($a['produkt_bezeichnung'] ?? '') ?: 'Produkt';
    $vName = $a['verpackung_id'] ? (string) scalar("SELECT name FROM item WHERE id=?", [(int)$a['verpackung_id']]) : '';
    $stk   = (int)($a['stueck'] ?? 0);
    $menge = (int)($a['menge'] ?? 0);
    $beschParts = [];
    if ($stk > 0)      $beschParts[] = inhalt_je_packung_txt(auftrag_darreichungsform($a), $stk);   // Pulver: 300 g je Packung statt Stueck
    if ($vName !== '') $beschParts[] = $vName;
    // Positionen: die VOLLSTÄNDIGEN Angebotspositionen des Auftrags (Herstellung + Glas + Deckel + Etikett …) wie auf der Kunden-AB und der
    // Rechnung (S-04 intern) – nicht nur die Herstellungszeile. Passt die Aufschlüsselung nicht exakt zum Auftrags-Netto, steht EINE
    // Sammelposition mit dem Gesamtpreis je Packung (gesamt_netto / menge).
    $positionen = beleg_positionen_aus_auftrag(['angebot_id'=>$a['angebot_id'] ?? null, 'menge'=>$menge, 'gesamt_netto'=>(float)($a['gesamt_netto'] ?? 0)], $ustP);
    foreach ($positionen as &$pp) $pp['mwst_satz'] = $ustP;   // build_beleg_pdf liest mwst_satz (nicht ust_satz)
    unset($pp);
    if (!$positionen) $positionen = [[
        'artikelnr'   => '',
        'bezeichnung' => $name,
        'beschreibung'=> implode(' · ', $beschParts),
        'menge'       => $menge,
        'einheit'     => 'Pkg.',
        'preis_cent'  => (int) round(auftrag_vk_gesamt($a) * 100),
        'mwst_satz'   => $ustP,
    ]];

    // Bezug: verknüpftes Angebot (sonst leer).
    $angNr = !empty($a['angebot_id']) ? (string) scalar("SELECT nummer FROM angebot WHERE id=?", [(int)$a['angebot_id']]) : '';

    // Charge(n) + MHD für den Hinweis (falls schon produziert/eingebucht).
    // Nur die FERTIGWARE-Charge(n) des Auftrags (St-06): keine Lieferanten-/Rohstoff-Chargen auf Kundendokumenten.
    $chargen = array_values(array_filter(auftrag_fertigware_chargen($auftrag_id), fn($c) => trim((string)($c['charge_nr'] ?? '')) !== ''));
    $hinweis = '';
    if ($chargen) {
        $teile = [];
        // 13.11.2026: nur die Grundcharge (2775.A + 2775.B = 2775), frühestes MHD (kunde_charge_anzeige).
        $grund = [];
        foreach ($chargen as $c) {
            $gk = kunde_charge_anzeige((string)$c['charge_nr']);
            if (!isset($grund[$gk]) || ($c['mhd'] && (!$grund[$gk] || (string)$c['mhd'] < (string)$grund[$gk]))) $grund[$gk] = $c['mhd'] ?: ($grund[$gk] ?? null);
        }
        foreach ($grund as $gk => $mhd) $teile[] = $gk . ($mhd ? ' (MHD ' . date('d.m.Y', strtotime((string)$mhd)) . ')' : '');
        $hinweis = 'Charge: ' . implode(', ', $teile);
    }

    $statusLbl = match (auftrag_status_kunde($a)) {   // 13.11.2026: zentrale Regel „versandbereit“
        'in_produktion' => 'in Produktion', 'erledigt' => 'versandbereit', 'versendet' => 'versendet',
        'offen' => 'in Bearbeitung', default => 'bestätigt',
    };
    $kopf = 'Vielen Dank für Ihren Auftrag. Hiermit bestätigen wir die folgende Bestellung:'
          . "\nStatus: " . $statusLbl;

    $adr = trim(($k['strasse'] ?? '') . ' ' . ($k['hausnummer'] ?? '')) . "\n" . trim(($k['plz'] ?? '') . ' ' . ($k['ort'] ?? ''));
    if (!$istInland && !empty($k['land'])) $adr .= "\n" . $k['land'];
    $zaMap = ['vorkasse'=>'Vorkasse', 'rechnung'=>'Rechnung', 'lastschrift'=>'Lastschrift', 'paypal'=>'PayPal'];

    return build_beleg_pdf([
        'belegart_label'   => 'Auftragsbestätigung',
        'nummer'           => $a['nummer'],
        'empfaenger'       => $k['firma'],
        'adresse'          => $adr,
        'datum'            => $a['angelegt'],
        'kundennummer'     => $k['kundennummer'] ?? '',
        'bezug'            => $angNr !== '' ? ('Angebot ' . $angNr) : '',
        'ust_id'           => $k['ust_id'] ?? '',
        'kopf_text'        => $kopf,
        'zahlungsart_label'=> $zaMap[$k['zahlungsart'] ?? 'vorkasse'] ?? ucfirst((string)($k['zahlungsart'] ?? 'Vorkasse')),
        'zahlungsziel_tage'=> (int)($k['zahlungsziel_tage'] ?? 0),   // Zahlungsziel des Kunden auf der AB (St-05)
        'hinweis'          => $hinweis,
    ], $positionen);
}

// PDF ausliefern (inline im Browser). false, wenn es den Auftrag/Kunden nicht gibt.
function auftrag_pdf_ausliefern(int $auftrag_id, string $nummer): bool {
    $pdf = auftrag_pdf_bauen($auftrag_id);
    if ($pdf === null) return false;
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Auftragsbestaetigung_' . preg_replace('/[^A-Za-z0-9_-]/', '', $nummer) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    return true;
}
