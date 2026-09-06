<?php
// Gibt es den schon?
//
// Beim Erfassen soll auffallen, wenn dieselbe Firma bereits als Kontakt oder als Kunde im
// Dashboard steht. Sonst liegt derselbe Interessent nach drei Messen dreimal im System und
// niemand weiss, welcher Eintrag der richtige ist.
//
// Bewusst OHNE KI: Namen vergleichen ist Rechenarbeit, keine Denkarbeit. Das ist schneller,
// kostet nichts und ist nachvollziehbar.
require_once __DIR__ . '/erp.php';
require_once __DIR__ . '/schema.php';

// Firmennamen vergleichbar machen: Kleinschreibung, Rechtsform weg, nur Buchstaben und Zahlen.
// Aus "Sportnahrung Weber GmbH & Co. KG" wird "sportnahrungweber".
function dublette_kern(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $s);
    $s = preg_replace('/\b(gmbh|ag|ug|kg|ohg|gbr|ltd|limited|inc|llc|bv|sarl|srl|sa|co|company|und|and)\b/u', ' ', $s);
    $s = preg_replace('/[^a-z0-9]+/', '', $s);
    return (string)$s;
}

// Telefonnummern vergleichbar machen: nur Ziffern, fuehrende Null und Laendervorwahl egal.
function dublette_tel(string $s): string {
    $z = preg_replace('/\D+/', '', $s);
    if ($z === '') return '';
    if (str_starts_with($z, '00')) $z = substr($z, 2);
    if (str_starts_with($z, '49')) $z = substr($z, 2);
    return ltrim($z, '0');
}

// Treffer: [['art'=>'kontakt'|'kunde', 'id'=>, 'text'=>, 'grund'=>], ...]
// $ausser: eigene Kontakt-ID, damit sich ein Kontakt beim Bearbeiten nicht selbst findet.
function dublette_suchen(string $name, string $firma, string $email = '', string $telefon = '', int $ausser = 0): array {
    $kernF = dublette_kern($firma);
    $kernN = dublette_kern($name);
    $mail  = mb_strtolower(trim($email));
    $tel   = dublette_tel($telefon);
    if ($kernF === '' && $kernN === '' && $mail === '' && $tel === '') return [];

    $treffer = [];

    // --- eigene Kontakte ---
    foreach (all("SELECT id, name, firma, email, telefon, kunde_id FROM crm_kontakt WHERE id <> ?", [$ausser]) as $k) {
        $grund = dublette_grund($kernF, $kernN, $mail, $tel,
            (string)($k['firma'] ?? ''), (string)$k['name'], (string)($k['email'] ?? ''), (string)($k['telefon'] ?? ''));
        if ($grund === '') continue;
        $treffer[] = ['art' => 'kontakt', 'id' => (int)$k['id'],
            'text' => trim(((string)($k['firma'] ?? '') !== '' ? $k['firma'] . ' – ' : '') . $k['name']),
            'grund' => $grund];
    }

    // --- Kunden im Dashboard ---
    foreach (erp_kunden_suche('', 500) as $k) {
        $grund = dublette_grund($kernF, $kernN, $mail, $tel,
            (string)($k['firma'] ?? ''), (string)($k['ansprechpartner'] ?? ''), (string)($k['email'] ?? ''), '');
        if ($grund === '') continue;
        $treffer[] = ['art' => 'kunde', 'id' => (int)$k['id'],
            'text' => trim((string)$k['firma'] . ((string)($k['kundennummer'] ?? '') !== '' ? ' (' . $k['kundennummer'] . ')' : '')),
            'grund' => $grund];
    }

    // Der verlaesslichste Treffer zuerst: eine gleiche E-Mail ist ein Beweis, eine aehnliche
    // Firma nur ein Verdacht. Die Reihenfolge entscheidet, was beim Mail-Einlesen vorgeschlagen wird.
    $rang = ['gleiche E-Mail' => 1, 'gleiche Telefonnummer' => 2, 'gleiche Firma' => 3,
             'gleicher Name' => 4, 'ähnliche Firma' => 5];
    usort($treffer, fn($a, $b) => ($rang[$a['grund']] ?? 9) <=> ($rang[$b['grund']] ?? 9));
    return array_slice($treffer, 0, 6);
}

// Warum halten wir zwei Eintraege fuer dieselbe Sache? Der Grund wird angezeigt - sonst wirkt
// so ein Hinweis wie Zauberei, und man klickt ihn weg, ohne hinzusehen.
function dublette_grund(string $kernF, string $kernN, string $mail, string $tel,
                        string $aFirma, string $aName, string $aMail, string $aTel): string {
    if ($mail !== '' && $aMail !== '' && mb_strtolower(trim($aMail)) === $mail) return 'gleiche E-Mail';
    if ($tel !== '' && dublette_tel($aTel) !== '' && dublette_tel($aTel) === $tel) return 'gleiche Telefonnummer';

    $aF = dublette_kern($aFirma);
    if ($kernF !== '' && $aF !== '') {
        if ($aF === $kernF) return 'gleiche Firma';
        // Aehnlich ist nur, was sich WIRKLICH aehnelt. Ohne diese Schranke wuerde "Test GmbH"
        // auf "Testkunde Portal" passen - und eine Mail landete beim falschen Kunden.
        $kurz = min(strlen($kernF), strlen($aF));
        $lang = max(strlen($kernF), strlen($aF));
        if ($kurz >= 6 && $kurz / $lang >= 0.6 && (str_contains($aF, $kernF) || str_contains($kernF, $aF)))
            return 'ähnliche Firma';
    }
    $aN = dublette_kern($aName);
    if ($kernN !== '' && $aN !== '' && strlen($kernN) >= 5 && $aN === $kernN) return 'gleicher Name';
    return '';
}
