<?php
// Termine. Klein gehalten: Titel, Zeitpunkt, Ort, optional ein Kontakt dahinter.
//
// Eingegeben wird in Berliner Zeit (so denkt der Mensch), gespeichert wird UTC (so denkt die
// Datenbank). Das Umrechnen passiert genau hier, damit es niemand vergisst.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/ui.php';

// Aus "2026-09-08T14:30" (Berliner Zeit) wird "2026-09-08 12:30:00" (UTC).
function termin_zeit_lesen(string $roh): ?string {
    $roh = trim(str_replace('T', ' ', $roh));
    if ($roh === '') return null;
    try {
        $dt = new DateTime($roh, new DateTimeZone('Europe/Berlin'));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) { return null; }
}

// Vorschlag fuers Formular: morgen um 10 Uhr Berliner Zeit.
function termin_vorschlag(): string {
    $dt = new DateTime('tomorrow 10:00', new DateTimeZone('Europe/Berlin'));
    return $dt->format('Y-m-d\TH:i');
}

function termin_anlegen(array $d, int $uid = 0): int {
    $titel = mb_substr(trim((string)($d['titel'] ?? '')), 0, 200);
    $start = termin_zeit_lesen((string)($d['start'] ?? ''));
    if ($titel === '' || $start === null) return 0;
    $kid = (int)($d['kontakt_id'] ?? 0);
    q("INSERT INTO crm_termin (titel, start_at, ort, notiz, bezug_typ, bezug_id, benutzer_id, angelegt)
       VALUES (?,?,?,?,?,?,?,?)",
      [$titel, $start,
       mb_substr(trim((string)($d['ort'] ?? '')), 0, 190) ?: null,
       trim((string)($d['notiz'] ?? '')) ?: null,
       $kid > 0 ? 'kontakt' : null, $kid > 0 ? $kid : null,
       $uid ?: null, gmdate('Y-m-d H:i:s')]);
    return insert_id();
}

function termin_erledigen(int $id): void {
    if ($id > 0) q("UPDATE crm_termin SET erledigt = 1 WHERE id = ?", [$id]);
}

function termin_liste(bool $erledigt = false): array {
    return all("SELECT t.*, k.name AS kontakt_name
                FROM crm_termin t
                LEFT JOIN crm_kontakt k ON (t.bezug_typ='kontakt' AND k.id = t.bezug_id)
                WHERE t.erledigt = ?
                ORDER BY t.start_at " . ($erledigt ? 'DESC' : 'ASC') . " LIMIT 200", [$erledigt ? 1 : 0]);
}

// Farbe fuer den Termin: heute rot, morgen gelb, spaeter ruhig. Anders als bei der Wartezeit
// zaehlt hier die Zukunft, nicht die Vergangenheit.
function termin_stufe(string $start_utc): string {
    try {
        $dt = new DateTime($start_utc, new DateTimeZone('UTC'));
        $jetzt = new DateTime('now', new DateTimeZone('UTC'));
        $stunden = ($dt->getTimestamp() - $jetzt->getTimestamp()) / 3600;
        if ($stunden < 24)  return 'heiss';
        if ($stunden < 72)  return 'warm';
        return 'ruhig';
    } catch (Exception $e) { return 'ruhig'; }
}
