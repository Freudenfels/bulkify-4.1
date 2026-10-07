<?php
// Novel-Food-Katalog: Einlesen (JSON/CSV), Diff gegen die DB (neu / geändert / unverändert) und Übernehmen.
// Idempotent über code (bzw. name, wenn kein code). Wird vom Dashboard-Import und vom CLI genutzt.
require_once BX_ROOT . '/core/schema.php';

function novelfood_clean(?string $s): ?string {
    if ($s === null) return null;
    $s = str_replace('&nbsp;', ' ', $s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)));
    return $s === '' ? null : $s;
}

// Einen Rohdatensatz auf die DB-Felder normalisieren. Rückgabe null, wenn kein Name.
function novelfood_normalisieren(array $e): ?array {
    $name = novelfood_clean((string)($e['name'] ?? ''));
    if ($name === null) return null;
    $cut = fn($v, $n) => novelfood_clean((string)($v ?? '')) !== null ? mb_substr(novelfood_clean((string)$v), 0, $n) : null;
    return [
        'code'            => novelfood_clean((string)($e['code'] ?? '')) ?: null,
        'name'            => mb_substr($name, 0, 255),
        'trivial'         => $cut($e['trivial'] ?? '', 500),
        'syn'             => $cut($e['syn'] ?? ($e['synonyme'] ?? ''), 500),
        'status'          => $cut($e['status'] ?? '', 120),
        'status_code'     => mb_substr((string)($e['status_code'] ?? ''), 0, 50) ?: null,
        'teil'            => $cut($e['teil'] ?? ($e['part'] ?? ''), 120),
        'beschreibung_de' => novelfood_clean((string)($e['beschreibung_de'] ?? '')) ?: novelfood_clean((string)($e['beschreibung'] ?? '')),
    ];
}

// Einträge + Stand aus einer Datei lesen (JSON mit {eintraege:[…]} oder reines Array; ODER CSV mit Kopfzeile).
// Rückgabe: ['eintraege'=>[…roh…], 'stand'=>?] oder null bei unlesbar.
function novelfood_aus_datei(string $pfad): ?array {
    if (!is_file($pfad)) return null;
    $txt = file_get_contents($pfad);
    if ($txt === false || trim($txt) === '') return null;
    // JSON?
    $roh = json_decode($txt, true);
    if (is_array($roh)) {
        $e = $roh['eintraege'] ?? (array_is_list($roh) ? $roh : null);
        if (is_array($e)) return ['eintraege' => $e, 'stand' => $roh['stand'] ?? null];
    }
    // Sonst CSV (Trenner automatisch: ; , oder Tab).
    $lines = preg_split('/\r\n|\r|\n/', $txt);
    $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
    if (count($lines) < 2) return null;
    $sep = (substr_count($lines[0], ';') >= substr_count($lines[0], ',')) ? ';' : ',';
    if (substr_count($lines[0], "\t") > substr_count($lines[0], $sep)) $sep = "\t";
    $head = array_map(fn($h) => mb_strtolower(trim($h, " \"'")), str_getcsv($lines[0], $sep));
    // Kopf-Synonyme → Feldname
    $mapHead = function(string $h): ?string {
        $h = mb_strtolower($h);
        foreach ([
            'code' => ['code','nr','id','eu code','entry'],
            'name' => ['name','bezeichnung','substance','substanz','stoff','denomination'],
            'trivial' => ['trivial','trivialname','common name','gebräuchlich','common'],
            'syn' => ['syn','synonym','synonyme','synonyms'],
            'status' => ['status','einstufung','classification'],
            'status_code' => ['status_code','statuscode','code status'],
            'teil' => ['teil','part','pflanzenteil','plant part'],
            'beschreibung_de' => ['beschreibung','beschreibung_de','description','kommentar','comment','remarks','bemerkung'],
        ] as $feld => $syns) foreach ($syns as $s) if ($h === $s) return $feld;
        return null;
    };
    $cols = array_map($mapHead, $head);
    $e = [];
    for ($i = 1; $i < count($lines); $i++) {
        $row = str_getcsv($lines[$i], $sep);
        $rec = [];
        foreach ($cols as $ci => $feld) if ($feld !== null && isset($row[$ci])) $rec[$feld] = $row[$ci];
        if (!empty($rec['name'])) $e[] = $rec;
    }
    return $e ? ['eintraege' => $e, 'stand' => null] : null;
}

// Bestehenden DB-Eintrag zu einem normalisierten Datensatz finden (über code, sonst name).
function novelfood_finden(array $d): ?array {
    if (!empty($d['code'])) return one("SELECT * FROM novelfood_katalog WHERE code=?", [$d['code']]);
    return one("SELECT * FROM novelfood_katalog WHERE code IS NULL AND name=?", [$d['name']]);
}

// Diff gegen die DB: was ist neu, was hat sich geändert (bes. Status), was bleibt gleich.
// Rückgabe: ['neu'=>[…], 'geaendert'=>[['neu'=>daten,'alt'=>row,'felder'=>[…],'status_neu'=>bool]], 'gleich'=>n, 'ungueltig'=>n, 'gesamt'=>n, 'stand'=>?].
function novelfood_diff(array $eintraege): array {
    $neu = []; $geaendert = []; $gleich = 0; $ungueltig = 0;
    $felderPruef = ['name','trivial','syn','status','status_code','teil','beschreibung_de'];
    foreach ($eintraege as $roh) {
        $d = novelfood_normalisieren((array)$roh);
        if ($d === null) { $ungueltig++; continue; }
        $ex = novelfood_finden($d);
        if (!$ex) { $neu[] = $d; continue; }
        $diff = [];
        foreach ($felderPruef as $f) if ((string)($ex[$f] ?? '') !== (string)($d[$f] ?? '')) $diff[] = $f;
        if (!$diff) { $gleich++; continue; }
        $geaendert[] = ['neu' => $d, 'alt' => $ex, 'felder' => $diff, 'status_neu' => in_array('status', $diff, true) || in_array('status_code', $diff, true)];
    }
    return ['neu' => $neu, 'geaendert' => $geaendert, 'gleich' => $gleich, 'ungueltig' => $ungueltig,
            'gesamt' => count($eintraege)];
}

// Einträge in die DB übernehmen (Upsert). Rückgabe: ['neu'=>n, 'upd'=>n].
function novelfood_uebernehmen(array $eintraege): array {
    $neu = $upd = 0;
    foreach ($eintraege as $roh) {
        $d = novelfood_normalisieren((array)$roh);
        if ($d === null) continue;
        $ex = novelfood_finden($d);
        if ($ex) {
            q("UPDATE novelfood_katalog SET name=?,trivial=?,syn=?,status=?,status_code=?,teil=?,beschreibung_de=? WHERE id=?",
              [$d['name'], $d['trivial'], $d['syn'], $d['status'], $d['status_code'], $d['teil'], $d['beschreibung_de'], (int)$ex['id']]);
            $upd++;
        } else {
            q("INSERT INTO novelfood_katalog (code,name,trivial,syn,status,status_code,teil,beschreibung_de) VALUES (?,?,?,?,?,?,?,?)",
              [$d['code'], $d['name'], $d['trivial'], $d['syn'], $d['status'], $d['status_code'], $d['teil'], $d['beschreibung_de']]);
            $neu++;
        }
    }
    return ['neu' => $neu, 'upd' => $upd];
}
