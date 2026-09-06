<?php
// Zu lange Rohstoffnamen (mehrere Varianten in einem Datensatz) in einzelne Rohstoffe aufschlüsseln.
//
// Ablauf: KI schlägt je langem Namen eine Variantenliste vor (nur Vorbefüllung, läuft auf beta);
// der Mensch prüft/editiert und übernimmt. Beim Übernehmen wird der Original-Datensatz zur 1.
// Variante (umbenannt, Original in die Notiz), für jede weitere Variante entsteht ein neuer Rohstoff.
require_once __DIR__ . '/ki.php';

// Schwelle: ab wie vielen Zeichen ein Name als „zu lang" gilt.
const ROHSTOFF_NAME_LANG = 70;

// KI-Vorschlag für EINEN Rohstoff. Rückgabe ['ok'=>bool,'basis'=>str,'varianten'=>[...]] / ['ok'=>false,'fehler'=>..]
function rohstoff_split_ki_one(array $item): array {
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'Die KI ist nur auf beta verfügbar.'];
    $prompt = "Dieser Rohstoff-Name enthält mehrere Varianten in EINEM Feld (z. B. verschiedene "
        . "Molekulargewichte, Trägeröle, Qualitäten, Konzentrationen oder Formen), getrennt durch \"/\" oder \"[...]\".\n"
        . "Schlüssle ihn in einzelne, saubere DEUTSCHE Rohstoffnamen auf – je echte Variante ein Name, der die "
        . "Substanz PLUS den unterscheidenden Zusatz enthält (z. B. Molekulargewicht, Trägeröl, Qualität, Form). "
        . "Kürze überflüssiges Beiwerk, aber wirf keinen wichtigen Zusatz weg. Wenn es in Wahrheit nur EIN Rohstoff "
        . "ist (die Zusätze sind keine echten Varianten), gib genau EINEN Namen zurück.\n\n"
        . "Name: " . $item['name'] . "\n"
        . (($item['name_en'] ?? '') !== '' ? "Englisch: " . $item['name_en'] . "\n" : '')
        . "\nAntworte als JSON: {\"basis\": \"<kurzer Substanzname>\", \"varianten\": [\"Name Variante 1\", \"Name Variante 2\", ...]}";
    $r = ki_json($prompt, ['max_tokens' => 700, 'zweck' => 'rohstoff-split']);
    if (!$r['ok']) return $r;
    $d = $r['daten'];
    $varianten = [];
    foreach ((array)($d['varianten'] ?? []) as $v) { $v = trim((string)$v); if ($v !== '') $varianten[] = mb_substr($v, 0, 190); }
    if (!$varianten) $varianten = [mb_substr((string)$item['name'], 0, 190)];
    return ['ok' => true, 'basis' => mb_substr(trim((string)($d['basis'] ?? '')), 0, 190), 'varianten' => array_slice($varianten, 0, 10)];
}

// Batch: für die nächsten $limit langen Rohstoffe ohne Vorschlag einen KI-Vorschlag erzeugen.
function rohstoff_split_ki_batch(int $limit = 15): array {
    $w = ['verarbeitet' => 0, 'fehler' => 0, 'meldung' => ''];
    $rows = all("SELECT i.* FROM item i
                 LEFT JOIN rohstoff_variante_vorschlag v ON v.item_id=i.id
                 WHERE i.kategorie='rohstoff' AND CHAR_LENGTH(i.name) > ? AND v.id IS NULL
                 ORDER BY CHAR_LENGTH(i.name) DESC LIMIT ?", [ROHSTOFF_NAME_LANG, $limit]);
    foreach ($rows as $it) {
        $r = rohstoff_split_ki_one($it);
        if (!$r['ok']) { $w['fehler']++; $w['meldung'] = $r['fehler'] ?? 'Fehler'; break; }
        q("INSERT INTO rohstoff_variante_vorschlag (item_id,original_name,basis,varianten_json,ki_stand,status)
           VALUES (?,?,?,?,?, 'offen')
           ON DUPLICATE KEY UPDATE basis=VALUES(basis), varianten_json=VALUES(varianten_json), ki_stand=VALUES(ki_stand), status='offen'",
          [(int)$it['id'], mb_substr((string)$it['name'], 0, 255), $r['basis'], json_encode($r['varianten'], JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]);
        $w['verarbeitet']++;
    }
    return $w;
}

// Aufschlüsseln übernehmen. $varianten = vom Menschen geprüfte Liste (eine pro Zeile/Element).
// Original-Datensatz wird zur 1. Variante (umbenannt, Original in Notiz), Rest als neue Rohstoffe.
function rohstoff_split_anwenden(int $item_id, array $varianten): array {
    $it = one("SELECT * FROM item WHERE id=? AND kategorie='rohstoff'", [$item_id]);
    if (!$it) return ['ok' => false, 'fehler' => 'Rohstoff nicht gefunden.'];
    $varianten = array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 190), $varianten), fn($v) => $v !== ''));
    if (!$varianten) return ['ok' => false, 'fehler' => 'Keine Varianten angegeben.'];
    $original = (string)$it['name'];
    $notiz = trim(((string)$it['notiz'] !== '' ? $it['notiz'] . "\n" : '') . 'Original-v3-Name (aufgeschlüsselt): ' . $original);

    // Original -> 1. Variante
    q("UPDATE item SET name=?, notiz=? WHERE id=?", [$varianten[0], $notiz, $item_id]);
    $neu = 0;
    for ($i = 1; $i < count($varianten); $i++) {
        q("INSERT INTO item (artikelnummer,name,name_lat,kategorie,form,einheit,preis_bezug,ek_preis,herkunft,notiz)
           VALUES (?,?,?, 'rohstoff', ?, ?, ?, 0, ?, ?)",
          [naechste_nummer('R'), $varianten[$i], $it['name_lat'] ?: null, $it['form'] ?: null,
           $it['einheit'] ?: 'kg', $it['preis_bezug'] ?: 'kg', $it['herkunft'] ?: null,
           'Variante, aufgeschlüsselt aus: ' . $original]);
        $neu++;
    }
    // Verlaufseintrag (auch wenn es vorher keinen KI-Vorschlag gab) – für die „übernommen"-Ansicht.
    q("INSERT INTO rohstoff_variante_vorschlag (item_id,original_name,varianten_json,status)
       VALUES (?,?,?, 'uebernommen')
       ON DUPLICATE KEY UPDATE varianten_json=VALUES(varianten_json), original_name=VALUES(original_name), status='uebernommen'",
      [$item_id, mb_substr($original, 0, 255), json_encode($varianten, JSON_UNESCAPED_UNICODE)]);
    log_aktivitaet('item', $item_id, 'team', 'Rohstoff in ' . count($varianten) . ' Varianten aufgeschlüsselt (' . $neu . ' neu angelegt).', 'item', 'item', $item_id);
    return ['ok' => true, 'neu' => $neu, 'gesamt' => count($varianten)];
}

// Überspringen: als verworfen markieren (bzw. Vorschlag anlegen, falls noch keiner da).
function rohstoff_split_verwerfen(int $item_id): void {
    $orig = (string) scalar("SELECT name FROM item WHERE id=?", [$item_id]);
    q("INSERT INTO rohstoff_variante_vorschlag (item_id,original_name,status) VALUES (?,?, 'verworfen')
       ON DUPLICATE KEY UPDATE status='verworfen'", [$item_id, mb_substr($orig, 0, 255)]);
}
