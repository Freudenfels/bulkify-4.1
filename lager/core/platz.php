<?php
// Lagerplaetze: Bereich - Regal - Ebene - Fach. Kurzform wie "A-01-2-03".
require_once __DIR__ . '/schema.php';

function platz_code(array $p): string {
    return strtoupper((string)$p['bereich']) . '-' . str_pad((string)$p['regal'], 2, '0', STR_PAD_LEFT)
         . '-' . (int)$p['ebene'] . '-' . str_pad((string)$p['fach'], 2, '0', STR_PAD_LEFT);
}

// Alle Plaetze in der Reihenfolge, in der man durchs Lager laeuft.
function platz_alle(): array {
    return all("SELECT p.*, s.name AS sender_name FROM lg_platz p
                LEFT JOIN lg_sender s ON s.id = p.sender_id
                ORDER BY p.bereich, p.regal, p.ebene, p.fach");
}
function platz(int $id): ?array { return one("SELECT * FROM lg_platz WHERE id=?", [$id]); }

// Bereich nur Buchstaben/Ziffern, gross geschrieben. Leer = 'A'.
function platz_bereich(string $b): string {
    $b = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $b));
    return $b === '' ? 'A' : substr($b, 0, 10);
}

// Viele Plaetze auf einmal anlegen: Regale von..bis, je Regal x Ebenen mit y Faechern.
// Was es schon gibt, bleibt unangetastet. Rueckgabe: [neu, schon_da]
function platz_raster_anlegen(string $bereich, int $regal_von, int $regal_bis, int $ebenen, int $faecher): array {
    $bereich = platz_bereich($bereich);
    $neu = 0; $da = 0;
    for ($r = $regal_von; $r <= $regal_bis; $r++) {
        for ($e = 1; $e <= $ebenen; $e++) {
            for ($f = 1; $f <= $faecher; $f++) {
                $st = q("INSERT IGNORE INTO lg_platz (bereich, regal, ebene, fach, angelegt) VALUES (?,?,?,?,?)",
                        [$bereich, $r, $e, $f, jetzt_utc()]);
                $st->rowCount() > 0 ? $neu++ : $da++;
            }
        }
    }
    return [$neu, $da];
}

// Blinker einem Platz zuordnen. Rueckgabe: Fehlertext oder '' bei Erfolg.
// Haengt die Blinker schon an einem anderen Platz, wird NICHT still umgehaengt.
function platz_leiste_setzen(int $platz_id, ?string $leiste): string {
    if ($leiste !== null) {
        $woanders = one("SELECT * FROM lg_platz WHERE leiste=? AND id<>?", [$leiste, $platz_id]);
        if ($woanders) return 'Die Blinker ' . $leiste . ' hängt schon an Platz ' . platz_code($woanders) . '.';
    }
    q("UPDATE lg_platz SET leiste=?, aktualisiert=? WHERE id=?", [$leiste, jetzt_utc(), $platz_id]);
    return '';
}

// Naechster Platz ohne Blinker - optional erst NACH einem bestimmten Platz (zum Ueberspringen).
function platz_naechster_ohne_leiste(?int $nach_id = null): ?array {
    $plaetze = all("SELECT * FROM lg_platz WHERE leiste IS NULL ORDER BY bereich, regal, ebene, fach");
    if (!$plaetze) return null;
    if ($nach_id === null) return $plaetze[0];
    $ref = platz($nach_id);
    if (!$ref) return $plaetze[0];
    $schluessel = fn($p) => sprintf('%-10s%06d%06d%06d', $p['bereich'], $p['regal'], $p['ebene'], $p['fach']);
    foreach ($plaetze as $p) if (strcmp($schluessel($p), $schluessel($ref)) > 0) return $p;
    return $plaetze[0];   // am Ende wieder von vorn
}
