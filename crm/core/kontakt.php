<?php
// Kontakte: Leute, die noch KEIN Kundenkonto im Dashboard haben. Alles hier laeuft ausschliesslich
// in crm_-Tabellen. Die einzige Ausnahme ist kontakt_zu_kunde() - der Weg ins Dashboard, und der
// geht ueber core/erp.php.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/erp.php';
require_once __DIR__ . '/ui.php';

// Eine getippte Zahl einlesen, egal ob "4800", "4.800" oder "4800,50".
function zahl_lesen(string $roh): ?float {
    $roh = trim($roh);
    if ($roh === '') return null;
    $roh = preg_replace('/[^0-9,.\-]/', '', $roh);
    if (str_contains($roh, ',') && str_contains($roh, '.')) $roh = str_replace('.', '', $roh);
    $roh = str_replace(',', '.', $roh);
    return is_numeric($roh) ? (float)$roh : null;
}

function kontakt_anlegen(array $d, int $uid = 0): int {
    $jetzt = gmdate('Y-m-d H:i:s');
    $quellen = crm_quellen();
    q("INSERT INTO crm_kontakt (name, firma, email, telefon, whatsapp, quelle, phase, wert_eur, notiz, besitzer_id, angelegt)
       VALUES (?,?,?,?,?,?,'neu',?,?,?,?)",
      [mb_substr(trim((string)$d['name']), 0, 190),
       mb_substr(trim((string)($d['firma'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($d['email'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($d['telefon'] ?? '')), 0, 60) ?: null,
       mb_substr(trim((string)($d['whatsapp'] ?? '')), 0, 60) ?: null,
       array_key_exists((string)($d['quelle'] ?? ''), $quellen) ? $d['quelle'] : 'sonstiges',
       zahl_lesen((string)($d['wert_eur'] ?? '')),
       trim((string)($d['notiz'] ?? '')) ?: null,
       $uid ?: null, $jetzt]);
    $id = insert_id();
    if (trim((string)($d['notiz'] ?? '')) !== '') {
        kontakt_verlauf($id, 'notiz', (string)$d['notiz'], $uid);
    }
    return $id;
}

function kontakt(int $id): ?array { return one("SELECT * FROM crm_kontakt WHERE id=?", [$id]); }

function kontakt_speichern(int $id, array $d): void {
    $quellen = crm_quellen(); $phasen = crm_phasen();
    q("UPDATE crm_kontakt SET name=?, firma=?, email=?, telefon=?, whatsapp=?, quelle=?, phase=?,
              wert_eur=?, notiz=?, aktualisiert=? WHERE id=?",
      [mb_substr(trim((string)$d['name']), 0, 190) ?: 'Ohne Namen',
       mb_substr(trim((string)($d['firma'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($d['email'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($d['telefon'] ?? '')), 0, 60) ?: null,
       mb_substr(trim((string)($d['whatsapp'] ?? '')), 0, 60) ?: null,
       array_key_exists((string)($d['quelle'] ?? ''), $quellen) ? $d['quelle'] : 'sonstiges',
       array_key_exists((string)($d['phase'] ?? ''), $phasen) ? $d['phase'] : 'neu',
       zahl_lesen((string)($d['wert_eur'] ?? '')),
       trim((string)($d['notiz'] ?? '')) ?: null,
       gmdate('Y-m-d H:i:s'), $id]);
}

function kontakt_liste(string $suche = '', bool $archiv = false): array {
    $suche = trim($suche);
    if ($suche === '') {
        return all("SELECT * FROM crm_kontakt WHERE archiviert = ? ORDER BY angelegt DESC LIMIT 300", [$archiv ? 1 : 0]);
    }
    $w = '%' . $suche . '%';
    return all("SELECT * FROM crm_kontakt
                WHERE archiviert = ? AND (name LIKE ? OR firma LIKE ? OR email LIKE ? OR telefon LIKE ? OR notiz LIKE ?)
                ORDER BY angelegt DESC LIMIT 300", [$archiv ? 1 : 0, $w, $w, $w, $w, $w]);
}

// --- Verlauf -----------------------------------------------------------------------------------
function kontakt_verlauf(int $kontakt_id, string $typ, string $text, int $uid = 0): void {
    if (trim($text) === '') return;
    $typen = crm_verlauf_typen();
    q("INSERT INTO crm_verlauf (kontakt_id, typ, text, benutzer_id, angelegt) VALUES (?,?,?,?,?)",
      [$kontakt_id, array_key_exists($typ, $typen) ? $typ : 'notiz', trim($text), $uid ?: null, gmdate('Y-m-d H:i:s')]);
}
function kontakt_verlauf_liste(int $kontakt_id): array {
    return all("SELECT v.*, b.name AS wer FROM crm_verlauf v
                LEFT JOIN benutzer b ON b.id = v.benutzer_id
                WHERE v.kontakt_id = ? ORDER BY v.angelegt DESC", [$kontakt_id]);
}

// --- Wiedervorlage -----------------------------------------------------------------------------
function kontakt_wiedervorlage(int $kontakt_id, string $titel, int $tage, int $uid = 0, string $notiz = ''): void {
    q("INSERT INTO crm_wiedervorlage (titel, notiz, faellig, bezug_typ, bezug_id, benutzer_id, angelegt)
       VALUES (?,?,?,'kontakt',?,?,?)",
      [mb_substr($titel, 0, 200), trim($notiz) ?: null, in_tagen($tage), $kontakt_id, $uid ?: null, gmdate('Y-m-d H:i:s')]);
}
function kontakt_wiedervorlagen(int $kontakt_id): array {
    return all("SELECT * FROM crm_wiedervorlage WHERE bezug_typ='kontakt' AND bezug_id=? AND erledigt_am IS NULL
                ORDER BY faellig ASC", [$kontakt_id]);
}

// --- Der Weg ins Dashboard ---------------------------------------------------------------------
// Aus dem Kontakt wird ein Kunde. Der Kontakt bleibt bestehen und zeigt danach dorthin, damit der
// Verlauf nicht verloren geht.
function kontakt_zu_kunde(int $kontakt_id, int $uid = 0): int {
    $k = kontakt($kontakt_id);
    if (!$k || $k['kunde_id']) return (int)($k['kunde_id'] ?? 0);
    $kid = erp_kunde_anlegen([
        'firma'           => (string)($k['firma'] ?: $k['name']),
        'ansprechpartner' => (string)$k['name'],
        'email'           => (string)($k['email'] ?? ''),
        'telefon'         => (string)($k['telefon'] ?? ''),
        'notiz'           => 'Aus dem CRM übernommen (Quelle: ' . (crm_quellen()[$k['quelle']] ?? $k['quelle']) . ').',
    ]);
    if ($kid > 0) {
        q("UPDATE crm_kontakt SET kunde_id=?, phase='gewonnen', aktualisiert=? WHERE id=?",
          [$kid, gmdate('Y-m-d H:i:s'), $kontakt_id]);
        kontakt_verlauf($kontakt_id, 'notiz', 'Als Kunde im Dashboard angelegt.', $uid);
    }
    return $kid;
}

// --- Verlauf an einem bestehenden Kunden -------------------------------------------------------
// Derselbe Verlauf, nur haengt er statt an einem Kontakt an einem Kunden des Dashboards. Damit
// steht auch bei langjaehrigen Kunden, was zuletzt besprochen wurde - im Dashboard gibt es das nicht.
function kunde_verlauf(int $kunde_id, string $typ, string $text, int $uid = 0): void {
    if (trim($text) === '' || $kunde_id <= 0) return;
    $typen = crm_verlauf_typen();
    q("INSERT INTO crm_verlauf (kunde_id, typ, text, benutzer_id, angelegt) VALUES (?,?,?,?,?)",
      [$kunde_id, array_key_exists($typ, $typen) ? $typ : 'notiz', trim($text), $uid ?: null, gmdate('Y-m-d H:i:s')]);
}
function kunde_verlauf_liste(int $kunde_id): array {
    return all("SELECT v.*, b.name AS wer FROM crm_verlauf v
                LEFT JOIN benutzer b ON b.id = v.benutzer_id
                WHERE v.kunde_id = ? ORDER BY v.angelegt DESC", [$kunde_id]);
}
function kunde_wiedervorlage(int $kunde_id, string $titel, int $tage, int $uid = 0, string $notiz = ''): void {
    q("INSERT INTO crm_wiedervorlage (titel, notiz, faellig, bezug_typ, bezug_id, benutzer_id, angelegt)
       VALUES (?,?,?,'kunde',?,?,?)",
      [mb_substr($titel, 0, 200), trim($notiz) ?: null, in_tagen($tage), $kunde_id, $uid ?: null, gmdate('Y-m-d H:i:s')]);
}
function kunde_wiedervorlagen(int $kunde_id): array {
    return all("SELECT * FROM crm_wiedervorlage WHERE bezug_typ='kunde' AND bezug_id=? AND erledigt_am IS NULL
                ORDER BY faellig ASC", [$kunde_id]);
}
