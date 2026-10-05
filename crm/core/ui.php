<?php
// Kleine Helfer fuer die Anzeige. Bewusst dieselben Namen wie im Dashboard (h, fmt_zeit),
// damit man beim Wechsel nicht umdenken muss.

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Zeit wird immer in UTC gespeichert und in Berliner Zeit angezeigt.
function fmt_zeit(?string $utc, string $fmt = 'd.m.Y H:i'): string {
    if (!$utc) return '';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $dt->format($fmt);
    } catch (Exception $e) { return $utc; }
}

// Wie lange wartet das schon? Gibt Tage zurueck (0 = heute).
function tage_seit(?string $utc): int {
    if (!$utc) return 0;
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $jetzt = new DateTime('now', new DateTimeZone('UTC'));
        return max(0, (int)$dt->diff($jetzt)->days);
    } catch (Exception $e) { return 0; }
}

// "heute", "gestern", "3 Tage" - kurz genug fuers Handy.
function warte_text(int $tage): string {
    if ($tage <= 0) return 'heute';
    if ($tage === 1) return 'gestern';
    return $tage . ' Tage';
}

// Ab wann faellt die Zeile ins Auge? Rein zeitlich, nicht nach Wichtigkeit.
function warte_stufe(int $tage): string {
    if ($tage >= CRM_HEISS) return 'heiss';
    if ($tage >= CRM_WARM)  return 'warm';
    return 'ruhig';
}

function eur(?float $b): string {
    return $b === null ? '' : number_format($b, 2, ',', '.') . ' €';
}

// Datum fuer <input type="date"> in Berliner Zeit, x Tage in der Zukunft.
function in_tagen(int $tage): string {
    $dt = new DateTime('now', new DateTimeZone('Europe/Berlin'));
    if ($tage !== 0) $dt->modify(($tage > 0 ? '+' : '') . $tage . ' days');
    return $dt->format('Y-m-d');
}

function crm_quellen(): array {
    return ['whatsapp' => 'WhatsApp', 'messe' => 'Messe', 'mail' => 'E-Mail', 'telefon' => 'Telefon',
            'empfehlung' => 'Empfehlung', 'website' => 'Website', 'sonstiges' => 'Sonstiges'];
}
function crm_phasen(): array {
    return ['neu' => 'neu', 'gespraech' => 'im Gespräch', 'angebot' => 'Angebot draußen',
            'gewonnen' => 'gewonnen', 'verloren' => 'verloren'];
}
function crm_verlauf_typen(): array {
    return ['notiz' => 'Notiz', 'anruf' => 'Telefonat', 'kontaktversuch' => 'Kontaktversuch',
            'whatsapp' => 'WhatsApp', 'mail' => 'E-Mail', 'treffen' => 'Besprechung', 'angebot' => 'Angebot'];
}

// Kategorien fuer To-Dos. Die Wiedervorlage ist keine eigene Kategorie zum Anlegen - offene
// Wiedervorlagen erscheinen in der To-Do-Liste unter der Kennung 'wiedervorlage' (siehe todo.php).
function crm_todo_kategorien(): array {
    return ['rueckruf' => 'Rückruf', 'angebot' => 'Angebot', 'muster' => 'Muster',
            'rechnung' => 'Rechnung', 'rueckfrage' => 'Rückfrage', 'aufgabe' => 'Aufgabe',
            'sonstiges' => 'Sonstiges'];
}
function crm_todo_kategorie_label(string $k): string {
    if ($k === 'wiedervorlage') return 'Wiedervorlage';
    return crm_todo_kategorien()[$k] ?? ucfirst($k);
}

// Farbe je Phase - fuer die Spaltenkoepfe und Karten-Badges der Pipeline. Aus dem v3-CRM uebernommen,
// an die bulkify-Farben angelehnt. Faellt eine unbekannte Phase an, kommt ein ruhiges Grau.
function crm_phase_farbe(string $phase): string {
    return [
        'neu'       => '#7a7a72',   // grau - frisch, noch nichts passiert
        'gespraech' => '#2d7dd2',   // blau - im Austausch
        'angebot'   => '#e08a1e',   // gold/orange - Angebot draussen
        'gewonnen'  => '#1D9E75',   // bulkify-gruen - gewonnen
        'verloren'  => '#c0392b',   // rot - verloren
    ][$phase] ?? '#7a7a72';
}

// Segmentierungs-/Qualifizierungsfelder am Kontakt (fuer Verkaeufer-Workflow, aus v3 uebernommen).
// Feldname => [Label, Optionen]. Reine Dropdown-Felder; Freitextfelder (land, website, ...) stehen
// direkt im Formular. Bewusst ohne Emojis (UI-Regel).
function crm_segfelder(): array {
    return [
        'kontaktart' => ['Bevorzugte Kontaktart', ['whatsapp' => 'WhatsApp', 'email' => 'E-Mail', 'telefon' => 'Telefon', 'persoenlich' => 'Persönlich', 'social' => 'Social Media', 'sonstiges' => 'Sonstiges']],
        'erfahrung'  => ['Erfahrung', ['neu' => 'Neueinsteiger', 'etwas' => 'Etwas Erfahrung', 'erfahren' => 'Erfahren', 'profi' => 'Profi / etablierte Marke']],
        'zielmarkt'  => ['Zielmarkt / Vertriebskanal', ['amazon' => 'Amazon', 'shop' => 'Eigener Onlineshop', 'einzelhandel' => 'Einzelhandel / Apotheke', 'grosshandel' => 'Großhandel / B2B', 'social' => 'Social Media / Influencer', 'export' => 'Export / International', 'sonstiges' => 'Sonstiges']],
        'nische'     => ['Nische / Produktbereich', ['sport' => 'Sport & Fitness', 'beauty' => 'Beauty & Anti-Aging', 'gesundheit' => 'Gesundheit & Immun', 'abnehmen' => 'Abnehmen / Diät', 'vegan' => 'Vegan & Bio', 'longevity' => 'Longevity', 'tier' => 'Tiergesundheit', 'sonstiges' => 'Sonstiges']],
        'firmentyp'  => ['Firmentyp', ['einzel' => 'Einzelperson / Startup', 'kmu' => 'KMU', 'marke' => 'Etablierte Marke', 'agentur' => 'Agentur / Reseller']],
        'volumen'    => ['Geschätztes Volumen', ['klein' => 'Klein (< 1.000 Stk.)', 'mittel' => 'Mittel (1.000–10.000)', 'gross' => 'Groß (> 10.000)']],
        'prioritaet' => ['Priorität', ['hoch' => 'Hoch', 'mittel' => 'Mittel', 'niedrig' => 'Niedrig']],
    ];
}

// Kurzes Wort fuer die Art einer Wartezeile - steht klein unter der Wartezeit.
function zeilen_art(string $typ): string {
    return [
        'rezeptur_anfrage'  => 'Anfrage',
        'portal_anfrage'    => 'Anfrage',
        'angebot'           => 'Angebot',
        'angebot_entwurf'   => 'Entwurf',
        'nachricht'         => 'Rückfrage',
        'lieferant_anfrage' => 'Lieferant',
        'aufgabe'           => 'Aufgabe',
        'wiedervorlage'     => 'Wiedervorlage',
        'termin'            => 'Termin',
        'kontakt'           => 'Kontakt',
    ][$typ] ?? $typ;
}
