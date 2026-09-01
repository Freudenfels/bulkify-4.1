<?php
// Angebots-PDF – eine Quelle fuer beide Seiten: Kundenportal und interner Bereich.
// Vorher steckte der Aufbau nur im Portal; das Team kam nur ueber den Kundenlink daran
// und ohne Kunde am Angebot gar nicht. Hier wird alles aus dem Angebot selbst hergeleitet.
require_once __DIR__ . '/pdf_beleg.php';

// Baut das PDF zu einem Angebot. null = Angebot (oder Kunde) nicht vorhanden.
function angebot_pdf_bauen(int $angebot_id): ?string {
    $a = one("SELECT a.*, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt_name, p.rezeptur_id,
                     p.verpackung_id AS p_verp, p.verschluss_id, p.etikett_id, r.darreichungsform
              FROM angebot a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
              WHERE a.id=?", [$angebot_id]);
    if (!$a) return null;
    $k = $a['kunde_id'] ? one("SELECT * FROM kunden WHERE id=?", [(int)$a['kunde_id']]) : null;
    if (!$k) return null;                                   // ohne Empfaenger kein Beleg
    $kid  = (int)$a['kunde_id'];
    $form = (string)($a['darreichungsform'] ?? '') ?: 'kapsel';
    $mo   = ($a['marge_override'] ?? '') !== '' && $a['marge_override'] !== null ? (float)$a['marge_override'] : null;

    // Preismatrix des Produkts (guenstigste Zelle je Groesse/Bestellmenge) – nur wenn es ein Produkt gibt.
    $matrix = [];
    foreach (all("SELECT stueck, bestellmenge, verpackung_id, ek_preis, vk_preis FROM produkt_preis WHERE produkt_id=? ORDER BY vk_preis ASC",
                 [(int)($a['produkt_id'] ?? 0)]) as $mr) {
        $s = (int)$mr['stueck']; $bm = (int)$mr['bestellmenge'];
        $vk = $mo !== null ? (float)$mr['ek_preis'] * (1 + $mo/100) : (float)$mr['vk_preis'];
        if (!isset($matrix[$s][$bm])) $matrix[$s][$bm] = ['vk'=>$vk, 'verp'=>(int)$mr['verpackung_id']];
    }

    $anf = $a['anfrage_id'] ? one("SELECT nummer FROM portal_anfrage WHERE id=?", [(int)$a['anfrage_id']]) : null;

    // USt: Inland -> Satz aus den Einstellungen, sonst 0 % (EU-/Export-Lieferung)
    $land = strtoupper(trim((string)($k['land'] ?? '')));
    $istInland = ($land === '' || in_array($land, ['DE','D','DEUTSCHLAND','GERMANY'], true));

    // Positionen: gespeicherte haben Vorrang, sonst die automatische Kalkulation.
    $positionen = angebot_positionen($angebot_id);
    // Staffel „Preis je fertiges Produkt" – bei Matrix-Angeboten aus dem Produkt, sonst aus den Optionen.
    $produktStaffel = angebot_hat_positionen($angebot_id) ? [] : angebot_staffel_gruppen($a);
    // Angebot aus Optionen (aus einer Rezeptur gebaut): jede Gruppe ist eine WAHL, keine Bestellzeile.
    // Sonst stuende unter dem PDF eine Summe ueber alle Varianten – die bestellt der Kunde nie.
    $opt = angebot_optionen($angebot_id);
    if ($opt['optionen']) {
        $produktStaffel = angebot_staffel_aus_optionen($opt['optionen']);
        if (count($opt['optionen']) > 1) {
            $ersteG = $opt['optionen'][0]['gruppe'];
            $positionen = array_values(array_filter($positionen,
                fn($pp) => trim((string)($pp['gruppe'] ?? '')) === $ersteG || trim((string)($pp['gruppe'] ?? '')) === ''));
        }
    }

    // Begleittext: Hinweis aus der Notiz („Aus Anfrage X — <Hinweis>") plus Produktionszeit.
    $teamNote = '';
    if (preg_match('/—\s*(.+)$/u', (string)$a['notiz'], $mm)) $teamNote = trim($mm[1]);
    $pz = ($a['produktionszeit_wochen'] ?? '') !== '' && $a['produktionszeit_wochen'] !== null
        ? (float)$a['produktionszeit_wochen'] : (float) meta_get('produktionszeit_wochen', 7);
    $kopf = 'Vielen Dank für Ihre Anfrage. Gerne bieten wir Ihnen an:'
          . ($teamNote !== '' ? "\n" . $teamNote : '')
          . "\nProduktionszeit: ca. " . rtrim(rtrim(number_format($pz, 1, ',', '.'), '0'), ',') . ' Wochen (unverbindlich).';

    $adr = trim(($k['strasse'] ?? '') . ' ' . ($k['hausnummer'] ?? '')) . "\n" . trim(($k['plz'] ?? '') . ' ' . ($k['ort'] ?? ''));
    if (!$istInland && !empty($k['land'])) $adr .= "\n" . $k['land'];
    $zaMap = ['vorkasse'=>'Vorkasse','rechnung'=>'Rechnung','lastschrift'=>'Lastschrift','paypal'=>'PayPal'];

    return build_beleg_pdf([
        'belegart_label'   => 'Angebot',
        'nummer'           => $a['nummer'],
        'empfaenger'       => $k['firma'],
        'adresse'          => $adr,
        'datum'            => $a['angelegt'],
        'gueltig_bis'      => $a['gueltig_bis'] ?: date('Y-m-d', strtotime($a['angelegt'] . ' +' . ((int) meta_get('angebot_gueltig_tage', 14)) . ' days')),
        'kundennummer'     => $k['kundennummer'] ?? '',
        'version'          => 1,
        'bezug'            => $anf ? ('Anfrage ' . $anf['nummer']) : '',
        'bearbeiter'       => '',
        'bearbeiter_email' => '',
        'ust_id'           => $k['ust_id'] ?? '',
        'kopf_text'        => $kopf,
        'zahlungsart_label'=> $zaMap[$k['zahlungsart'] ?? 'vorkasse'] ?? ucfirst((string)($k['zahlungsart'] ?? 'Vorkasse')),
        'hinweis'          => '',
    ], $positionen, $produktStaffel);
}

// PDF ausliefern (inline im Browser). Gibt false zurueck, wenn es das Angebot nicht gibt.
function angebot_pdf_ausliefern(int $angebot_id, string $nummer): bool {
    $pdf = angebot_pdf_bauen($angebot_id);
    if ($pdf === null) return false;
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="Angebot_' . preg_replace('/[^A-Za-z0-9_-]/', '', $nummer) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    return true;
}
