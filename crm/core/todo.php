<?php
// To-Dos: abhakbare Aufgaben, nach Kunde/Kontakt und Kategorie sortiert.
//
// Eine Liste, zwei Quellen: die neuen strukturierten Aufgaben (crm_todo) UND die offenen
// Wiedervorlagen mit Bezug auf einen Kontakt/Kunden (crm_wiedervorlage). So "geht die Wiedervorlage
// in den To-Dos auf", ohne die bestehende "Wer wartet"-Engine umzubauen. Beide sind abhakbar.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/kontakt.php';   // kontakt(), erp_kunde() ueber erp.php
require_once __DIR__ . '/ui.php';

function todo_anlegen(array $d, int $uid = 0): int {
    $kats = crm_todo_kategorien();
    $typ  = (string)($d['bezug_typ'] ?? '');
    if (!in_array($typ, ['kontakt', 'kunde'], true)) { $typ = ''; }
    $faellig = trim((string)($d['faellig'] ?? ''));
    $quelle  = (string)($d['quelle'] ?? 'manuell');
    if (!in_array($quelle, ['manuell', 'ki', 'mail'], true)) $quelle = 'manuell';
    $kat     = (string)($d['kategorie'] ?? '');
    q("INSERT INTO crm_todo (titel, kategorie, bezug_typ, bezug_id, faellig, quelle, besitzer_id, benutzer_id, angelegt)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [mb_substr(trim((string)($d['titel'] ?? '')), 0, 255) ?: 'Aufgabe',
       array_key_exists($kat, $kats) ? $kat : 'aufgabe',
       $typ ?: null, $typ !== '' ? (int)($d['bezug_id'] ?? 0) : null,
       $faellig !== '' ? $faellig : null,
       $quelle,
       (int)($d['besitzer_id'] ?? 0) ?: null, $uid ?: null, gmdate('Y-m-d H:i:s')]);
    return insert_id();
}

// Abhaken / wieder öffnen. $quelle = 'todo' (crm_todo) oder 'wv' (crm_wiedervorlage).
function todo_erledigen(string $quelle, int $id, int $uid = 0, bool $zurueck = false): void {
    if ($quelle === 'wv') {
        if ($zurueck) q("UPDATE crm_wiedervorlage SET erledigt_am=NULL, erledigt_von=NULL WHERE id=?", [$id]);
        else          q("UPDATE crm_wiedervorlage SET erledigt_am=?, erledigt_von=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $uid ?: null, $id]);
        return;
    }
    if ($zurueck) q("UPDATE crm_todo SET erledigt_am=NULL, erledigt_von=NULL WHERE id=?", [$id]);
    else          q("UPDATE crm_todo SET erledigt_am=?, erledigt_von=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $uid ?: null, $id]);
}

// Offene To-Dos (nur crm_todo) zu einem Bezug - fuer die Kontakt-/Kundenseite.
function todo_fuer_bezug(string $typ, int $id): array {
    return all("SELECT * FROM crm_todo WHERE bezug_typ=? AND bezug_id=? AND erledigt_am IS NULL ORDER BY (faellig IS NULL), faellig ASC, id DESC", [$typ, $id]);
}

// Wie viele offene Aufgaben insgesamt (To-Dos + Wiedervorlagen mit Kontakt/Kunde-Bezug)? Fuer das Menue.
function todo_zahl_offen(): int {
    try {
        $a = (int) scalar("SELECT COUNT(*) FROM crm_todo WHERE erledigt_am IS NULL");
        $b = (int) scalar("SELECT COUNT(*) FROM crm_wiedervorlage WHERE erledigt_am IS NULL AND bezug_typ IN ('kontakt','kunde')");
        return $a + $b;
    } catch (Throwable $e) { return 0; }
}

// Vereinte Liste. $modus = 'offen' | 'erledigt'. $kat filtert auf eine Kategorie (inkl. 'wiedervorlage').
// Rueckgabe je Zeile: quelle(todo|wv), id, titel, kategorie, faellig, bezug_typ, bezug_id,
//                     bezug_name, bezug_link, angelegt, erledigt_am.
function todo_liste(string $modus = 'offen', string $kat = ''): array {
    $offen = $modus !== 'erledigt';
    $zeilen = [];

    // crm_todo
    if ($kat === '' || $kat !== 'wiedervorlage') {
        $sql = "SELECT * FROM crm_todo WHERE erledigt_am IS " . ($offen ? "NULL" : "NOT NULL");
        $args = [];
        if ($kat !== '' && $kat !== 'wiedervorlage') { $sql .= " AND kategorie=?"; $args[] = $kat; }
        $sql .= $offen ? " ORDER BY id DESC LIMIT 500" : " ORDER BY erledigt_am DESC LIMIT 300";
        foreach (all($sql, $args) as $r) {
            $zeilen[] = todo_zeile('todo', $r, (string)$r['kategorie']);
        }
    }

    // offene/erledigte Wiedervorlagen mit Kontakt/Kunde-Bezug (als Kategorie 'wiedervorlage')
    if ($kat === '' || $kat === 'wiedervorlage') {
        $sql = "SELECT * FROM crm_wiedervorlage WHERE bezug_typ IN ('kontakt','kunde') AND erledigt_am IS " . ($offen ? "NULL" : "NOT NULL");
        $sql .= $offen ? " ORDER BY (faellig IS NULL), faellig ASC, id DESC LIMIT 500" : " ORDER BY erledigt_am DESC LIMIT 300";
        foreach (all($sql) as $r) {
            $zeilen[] = todo_zeile('wv', $r, 'wiedervorlage');
        }
    }

    // Offen: nach Faelligkeit (ohne Datum zuletzt), dann neueste. Erledigt: nach Erledigt-Zeit.
    usort($zeilen, function ($a, $b) use ($offen) {
        if (!$offen) return strcmp((string)$b['erledigt_am'], (string)$a['erledigt_am']);
        $fa = $a['faellig'] ?: '9999-12-31'; $fb = $b['faellig'] ?: '9999-12-31';
        if ($fa !== $fb) return strcmp($fa, $fb);
        return strcmp((string)$b['angelegt'], (string)$a['angelegt']);
    });
    return $zeilen;
}

// Eine Rohzeile in die gemeinsame Form bringen (inkl. Bezug-Name/-Link).
function todo_zeile(string $quelle, array $r, string $kategorie): array {
    $typ = (string)($r['bezug_typ'] ?? '');
    $bid = (int)($r['bezug_id'] ?? 0);
    return [
        'quelle'     => $quelle,
        'id'         => (int)$r['id'],
        'titel'      => (string)$r['titel'],
        'kategorie'  => $kategorie,
        'faellig'    => $r['faellig'] ?? null,
        'bezug_typ'  => $typ,
        'bezug_id'   => $bid,
        'bezug_name' => $typ !== '' ? todo_bezug_name($typ, $bid) : '',
        'bezug_link' => $typ === 'kontakt' ? ('?p=kontakt&id=' . $bid) : ($typ === 'kunde' ? ('?p=kunde&id=' . $bid) : ''),
        'angelegt'   => (string)($r['angelegt'] ?? ''),
        'erledigt_am'=> $r['erledigt_am'] ?? null,
    ];
}

// Anzeigename eines Bezugs (mit kleinem Cache).
function todo_bezug_name(string $typ, int $id): string {
    static $cache = [];
    $key = $typ . ':' . $id;
    if (isset($cache[$key])) return $cache[$key];
    $name = '';
    try {
        if ($typ === 'kontakt') {
            $k = kontakt($id);
            if ($k) $name = trim(((string)($k['firma'] ?? '') !== '' ? $k['firma'] . ' – ' : '') . (string)$k['name']);
        } elseif ($typ === 'kunde') {
            $k = erp_kunde($id);
            if ($k) $name = (string)($k['firma'] ?? ('Kunde #' . $id));
        }
    } catch (Throwable $e) { $name = ''; }
    return $cache[$key] = ($name !== '' ? $name : ucfirst($typ) . ' #' . $id);
}
