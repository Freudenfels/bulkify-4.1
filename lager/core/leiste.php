<?php
// Blinker im Chaos-Modell (grosses Lager): einen Blinker haengt an einer CHARGE, nicht an einem
// festen Platz. Hier steht die Verwaltung der Blinker und die Bindung Blinker <-> Charge.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/erp.php';
require_once __DIR__ . '/led.php';

function leiste_per_code(string $code): ?array { return one("SELECT * FROM lg_leiste WHERE code=?", [$code]); }
function leiste(int $id): ?array               { return one("SELECT * FROM lg_leiste WHERE id=?", [$id]); }

// Alle Blinker mit dem, was dranhaengt (Charge + Rohstoffname aus dem Dashboard).
function leiste_alle(): array {
    $leisten = all("SELECT l.*, s.name AS sender_name FROM lg_leiste l
                    LEFT JOIN lg_sender s ON s.id = l.sender_id ORDER BY l.charge_id IS NULL, l.code");
    foreach ($leisten as &$l) {
        $l['charge'] = $l['charge_id'] ? erp_charge((int)$l['charge_id']) : null;
    }
    return $leisten;
}

// Welche Blinker haengt an dieser Charge? (0..1)
function leiste_fuer_charge(int $charge_id): ?array {
    return one("SELECT * FROM lg_leiste WHERE charge_id=?", [$charge_id]);
}

// Blinker ins System aufnehmen, falls der Code noch unbekannt ist. Gibt die Blinker zurueck.
function leiste_sicherstellen(string $code, ?int $sender_id = null): array {
    $l = leiste_per_code($code);
    if ($l) return $l;
    q("INSERT INTO lg_leiste (code, sender_id, angelegt) VALUES (?,?,?)", [$code, $sender_id, jetzt_utc()]);
    return leiste_per_code($code);
}

// Blinker an eine Charge binden. Rueckgabe: Fehlertext oder '' bei Erfolg.
function leiste_binden(string $code, int $charge_id, ?int $sender_id = null): string {
    if (!erp_charge($charge_id)) return 'Diese Charge gibt es nicht (mehr).';
    $l = leiste_sicherstellen($code, $sender_id);

    // Haengt die Blinker schon an einer ANDEREN Charge? Nicht still umhaengen.
    if ($l['charge_id'] && (int)$l['charge_id'] !== $charge_id) {
        $alt = erp_charge((int)$l['charge_id']);
        return 'Blinker ' . $code . ' hängt schon an ' . ($alt ? charge_text($alt) : 'einer anderen Charge')
             . '. Erst dort lösen.';
    }
    // Hat die Charge schon eine ANDERE Blinker?
    $andere = leiste_fuer_charge($charge_id);
    if ($andere && (int)$andere['id'] !== (int)$l['id']) {
        return 'An dieser Charge hängt schon Blinker ' . $andere['code'] . '.';
    }
    q("UPDATE lg_leiste SET charge_id=?, gebunden_am=?, sender_id=COALESCE(?, sender_id), aktualisiert=? WHERE id=?",
      [$charge_id, jetzt_utc(), $sender_id, jetzt_utc(), (int)$l['id']]);
    return '';
}

// Blinker lösen (Charge leer/raus) -> Blinker wird frei und kann neu vergeben werden.
function leiste_loesen(int $leiste_id): void {
    q("UPDATE lg_leiste SET charge_id=NULL, gebunden_am=NULL, aktualisiert=? WHERE id=?", [jetzt_utc(), $leiste_id]);
}

// Blinker klingeln lassen, um die Palette zu finden. $piep=false = still (nur Licht).
function leiste_finden(int $leiste_id, string $farbe = 'gruen', int $sekunden = 40, bool $piep = true): array {
    $l = leiste($leiste_id);
    if (!$l) return ['ok' => false, 'meldung' => 'Blinker nicht gefunden.'];
    return led_befehl((string)$l['code'], $l['sender_id'] ? (int)$l['sender_id'] : null,
                      led_code((string)$l['code'], $farbe, $piep, $sekunden), $farbe, led_sekunden($sekunden), $piep);
}
function leiste_aus(int $leiste_id): array {
    $l = leiste($leiste_id);
    if (!$l) return ['ok' => false, 'meldung' => 'Blinker nicht gefunden.'];
    return led_befehl((string)$l['code'], $l['sender_id'] ? (int)$l['sender_id'] : null,
                      led_code_aus((string)$l['code']), 'aus', 0, false);
}

// Kurztext einer Charge fuer die Anzeige: Rohstoff + Chargennummer + Restmenge.
function charge_text(array $c): string {
    $t = (string)$c['item_name'];
    if (!empty($c['charge_nr'])) $t .= ' · Ch. ' . $c['charge_nr'];
    if ($c['menge_verfuegbar'] !== null) $t .= ' · ' . rtrim(rtrim(number_format((float)$c['menge_verfuegbar'], 3, ',', '.'), '0'), ',') . ' ' . $c['einheit'];
    return $t;
}
