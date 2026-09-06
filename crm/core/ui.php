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
    return ['notiz' => 'Notiz', 'anruf' => 'Anruf', 'whatsapp' => 'WhatsApp', 'mail' => 'E-Mail',
            'treffen' => 'Treffen', 'angebot' => 'Angebot'];
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
