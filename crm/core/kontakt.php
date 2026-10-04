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
    $quellen = crm_quellen(); $phasen = crm_phasen(); $segfelder = crm_segfelder();
    $alt = kontakt($id);
    $phase = array_key_exists((string)($d['phase'] ?? ''), $phasen) ? (string)$d['phase'] : (string)($alt['phase'] ?? 'neu');
    $phaseWechsel = $alt && (string)$alt['phase'] !== $phase;

    // Zustaendiger: nur eine gueltige Mitarbeiter-ID, sonst niemand (NULL).
    $besitzer = (int)($d['besitzer_id'] ?? 0);
    $gueltig = array_map(fn($u) => (int)$u['id'], erp_mitarbeiter());
    if ($besitzer > 0 && !in_array($besitzer, $gueltig, true)) $besitzer = 0;

    // Segmentierungs-Dropdowns: nur gueltige Schluessel uebernehmen.
    $seg = [];
    foreach ($segfelder as $f => $def) { $v = (string)($d[$f] ?? ''); $seg[$f] = array_key_exists($v, $def[1]) ? $v : null; }

    q("UPDATE crm_kontakt SET name=?, firma=?, email=?, telefon=?, whatsapp=?, quelle=?, phase=?,
              wert_eur=?, notiz=?, besitzer_id=?,
              kontaktart=?, erfahrung=?, zielmarkt=?, nische=?, firmentyp=?, volumen=?, prioritaet=?,
              land=?, website=?, moeglichkeiten=?, besonderheiten=?,
              anfrage_rezeptur=?, anfrage_form=?, anfrage_inhalt=?, anfrage_vorhaben=?,
              aktualisiert=?" . ($phaseWechsel ? ", phase_at=?" : "") . " WHERE id=?",
      array_merge([
       mb_substr(trim((string)$d['name']), 0, 190) ?: 'Ohne Namen',
       mb_substr(trim((string)($d['firma'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($d['email'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($d['telefon'] ?? '')), 0, 60) ?: null,
       mb_substr(trim((string)($d['whatsapp'] ?? '')), 0, 60) ?: null,
       array_key_exists((string)($d['quelle'] ?? ''), $quellen) ? $d['quelle'] : 'sonstiges',
       $phase,
       zahl_lesen((string)($d['wert_eur'] ?? '')),
       trim((string)($d['notiz'] ?? '')) ?: null,
       $besitzer ?: null,
       $seg['kontaktart'], $seg['erfahrung'], $seg['zielmarkt'], $seg['nische'], $seg['firmentyp'], $seg['volumen'], $seg['prioritaet'],
       mb_substr(trim((string)($d['land'] ?? '')), 0, 120) ?: null,
       mb_substr(trim((string)($d['website'] ?? '')), 0, 190) ?: null,
       trim((string)($d['moeglichkeiten'] ?? '')) ?: null,
       trim((string)($d['besonderheiten'] ?? '')) ?: null,
       mb_substr(trim((string)($d['anfrage_rezeptur'] ?? '')), 0, 255) ?: null,
       mb_substr(trim((string)($d['anfrage_form'] ?? '')), 0, 120) ?: null,
       mb_substr(trim((string)($d['anfrage_inhalt'] ?? '')), 0, 120) ?: null,
       mb_substr(trim((string)($d['anfrage_vorhaben'] ?? '')), 0, 190) ?: null,
       gmdate('Y-m-d H:i:s'),
      ], $phaseWechsel ? [gmdate('Y-m-d H:i:s')] : [], [$id]));
}

// Nur die Phase setzen (fuer das Pipeline-Board: Drag & Drop / Dropdown). Setzt phase_at bei Wechsel
// und schreibt eine Verlaufszeile, damit der Phasenwechsel nachvollziehbar bleibt.
function kontakt_phase_setzen(int $id, string $phase, int $uid = 0): void {
    $phasen = crm_phasen();
    if (!array_key_exists($phase, $phasen)) return;
    $alt = kontakt($id);
    if (!$alt || (string)$alt['phase'] === $phase) return;
    q("UPDATE crm_kontakt SET phase=?, phase_at=?, aktualisiert=? WHERE id=?",
      [$phase, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $id]);
    kontakt_verlauf($id, 'notiz', 'Phase: „' . ($phasen[$alt['phase']] ?? $alt['phase']) . '" → „' . $phasen[$phase] . '"', $uid);
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

// --- Dokumente am Kontakt (Angebot/Abschluss/Rechnung/Sonstiges) -------------------------------
// Dateien liegen in data/kontakt_datei (gitignored). Heruntergeladen wird ueber
// public/crm/kontakt_doc.php (mit Login). Es wird nur in crm_-Tabellen geschrieben.
function kontakt_datei_dir(): string {
    $d = BX_DATA . '/kontakt_datei';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
function kontakt_datei_kategorien(): array {
    return ['angebot' => 'Angebot', 'abschluss' => 'Abschluss', 'rechnung' => 'Rechnung', 'sonstiges' => 'Sonstiges'];
}
function kontakt_dateien(int $kontakt_id): array {
    return all("SELECT * FROM crm_kontakt_datei WHERE kontakt_id=? ORDER BY angelegt DESC, id DESC", [$kontakt_id]);
}
function kontakt_datei(int $id): ?array {
    return one("SELECT * FROM crm_kontakt_datei WHERE id=?", [$id]);
}
// Rueckgabe: ['ok'=>bool, 'fehler'=>string]
function kontakt_datei_speichern(int $kontakt_id, array $f, string $kategorie, int $uid = 0): array {
    if (empty($f['name']) || (int)($f['error'] ?? 1) !== UPLOAD_ERR_OK) return ['ok' => false, 'fehler' => 'Bitte eine Datei auswählen.'];
    if ((int)($f['size'] ?? 0) > 25 * 1024 * 1024) return ['ok' => false, 'fehler' => 'Die Datei ist größer als 25 MB.'];
    if (!array_key_exists($kategorie, kontakt_datei_kategorien())) $kategorie = 'sonstiges';
    $orig  = (string)$f['name'];
    $ext   = preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($orig, PATHINFO_EXTENSION));
    $store = 'k' . $kontakt_id . '_' . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
    if (!move_uploaded_file($f['tmp_name'], kontakt_datei_dir() . '/' . $store))
        return ['ok' => false, 'fehler' => 'Upload fehlgeschlagen.'];
    q("INSERT INTO crm_kontakt_datei (kontakt_id, kategorie, original, stored, groesse, benutzer_id, angelegt)
       VALUES (?,?,?,?,?,?,?)",
      [$kontakt_id, $kategorie, mb_substr($orig, 0, 255), $store, (int)($f['size'] ?? 0), $uid ?: null, gmdate('Y-m-d H:i:s')]);
    return ['ok' => true, 'fehler' => ''];
}
function kontakt_datei_loeschen(int $id, int $kontakt_id): void {
    $d = one("SELECT * FROM crm_kontakt_datei WHERE id=? AND kontakt_id=?", [$id, $kontakt_id]);
    if (!$d) return;
    @unlink(kontakt_datei_dir() . '/' . $d['stored']);
    q("DELETE FROM crm_kontakt_datei WHERE id=?", [$id]);
}
