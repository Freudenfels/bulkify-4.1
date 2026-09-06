<?php
// KI-gestützte Zuordnung der importierten EK-Preislisten (ek_import) zu v4-Rohstoffen/Produkten.
//
// Zwei Schritte je Zeile:
//   1) Kandidaten-Vorauswahl in PHP (Namens-Ähnlichkeit) – schickt nicht alle 1.188 Rohstoffe an
//      die KI, sondern nur die ~20 wahrscheinlichsten.
//   2) Die KI wählt aus den Kandidaten den passenden aus (oder keinen) mit Zuversicht 0–100.
//
// Bestätigte Rohstoff-Zeilen werden als lieferant_preis übernommen -> füllt „Preis ab"/Lieferant
// in der Rohstoffliste. Die KI läuft nur auf beta (Schlüssel serverseitig).
require_once __DIR__ . '/ki.php';

// Name normalisieren + in Tokens zerlegen (Mengen/Einheiten raus, generische Kurzwörter bleiben).
function ek_tokens(string $s): array {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\([^)]*\)/u', ' ', $s);
    $s = preg_replace('/\b\d+([.,]\d+)?\s?(kg|g|mg|ie|iu|l|ml|mrd|kapseln?|stk|mesh|%)\b/u', ' ', $s);
    $s = preg_replace('/[^a-z0-9äöüß ]+/u', ' ', $s);
    $t = array_filter(explode(' ', preg_replace('/\s+/', ' ', trim($s))), fn($w) => mb_strlen($w) >= 3);
    return array_values(array_unique($t));
}

// Kandidaten-Index (einmal je Request aufbauen). Rückgabe: [ ['id'=>, 'label'=>, 'lat'=>, 'tok'=>[...]] ]
function ek_kandidaten_index(string $typ): array {
    static $cache = [];
    if (isset($cache[$typ])) return $cache[$typ];
    $idx = [];
    if ($typ === 'rohstoff') {
        foreach (all("SELECT id, name, name_en, name_lat, synonym FROM item WHERE kategorie='rohstoff' AND (form<>'kapselhuelle' OR form IS NULL)") as $r) {
            $tok = ek_tokens((string)$r['name'] . ' ' . (string)$r['name_en'] . ' ' . (string)$r['synonym']);
            $idx[] = ['id' => (int)$r['id'], 'label' => (string)$r['name'], 'lat' => (string)$r['name_lat'], 'tok' => $tok];
        }
    } else {
        foreach (all("SELECT id, name, kundenname FROM produkt") as $r) {
            $tok = ek_tokens((string)$r['name'] . ' ' . (string)$r['kundenname']);
            $idx[] = ['id' => (int)$r['id'], 'label' => (string)$r['name'], 'lat' => '', 'tok' => $tok];
        }
    }
    return $cache[$typ] = $idx;
}

// Die wahrscheinlichsten Kandidaten für einen EK-Namen (Token-Überlappung). Max $max Treffer.
function ek_kandidaten(string $name, string $formulierung, string $typ, int $max = 20): array {
    $qtok = ek_tokens($name . ' ' . $formulierung);
    if (!$qtok) return [];
    $qset = array_flip($qtok);
    $treffer = [];
    foreach (ek_kandidaten_index($typ) as $c) {
        if (!$c['tok']) continue;
        $gemeinsam = 0;
        foreach ($c['tok'] as $t) if (isset($qset[$t])) $gemeinsam++;
        if ($gemeinsam === 0) continue;
        // Score: Anteil abgedeckter Query-Tokens + kleiner Bonus für Deckung der Kandidaten-Tokens
        $score = $gemeinsam / max(1, count($qtok)) + 0.25 * ($gemeinsam / max(1, count($c['tok'])));
        $treffer[] = ['id' => $c['id'], 'label' => $c['label'], 'lat' => $c['lat'], 'score' => $score];
    }
    usort($treffer, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($treffer, 0, $max);
}

// Eine EK-Zeile per KI zuordnen. Rückgabe: ['ok'=>bool,'id'=>?int,'score'=>int,'grund'=>str] / ['ok'=>false,'fehler'=>...]
function ek_ki_match(array $ek): array {
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'Die KI ist nur auf beta verfügbar (Schlüssel serverseitig).'];
    $typ = (string)$ek['typ'];
    $kand = ek_kandidaten((string)$ek['name'], (string)($ek['formulierung'] ?? ''), $typ);
    if (!$kand) return ['ok' => true, 'id' => null, 'score' => 0, 'grund' => 'Kein ähnlicher Eintrag im Bestand gefunden.'];

    $liste = '';
    foreach ($kand as $c) $liste .= '  ' . $c['id'] . ': ' . $c['label'] . ($c['lat'] ? ' [' . $c['lat'] . ']' : '') . "\n";
    $wasIst = $typ === 'rohstoff' ? 'Rohstoff' : 'Fertigprodukt';
    $prompt = "Ein Einkaufs-Listeneintrag soll dem richtigen $wasIst im System zugeordnet werden.\n\n"
        . "Listeneintrag:\n  Name: " . $ek['name'] . "\n"
        . (($ek['formulierung'] ?? '') !== '' ? "  Formulierung: " . mb_substr((string)$ek['formulierung'], 0, 400) . "\n" : '')
        . (($ek['groesse'] ?? '') !== '' ? "  Größe/Form: " . $ek['groesse'] . "\n" : '')
        . "\nKandidaten (id: Name [lat.]):\n" . $liste
        . "\nWelcher Kandidat ist gemeint? Wähle NUR aus der Liste. Wenn keiner sicher passt, gib null.\n"
        . "Antworte als JSON: {\"id\": <id oder null>, \"score\": <0-100 wie sicher>, \"grund\": \"kurz\"}";

    $r = ki_json($prompt, ['max_tokens' => 400, 'zweck' => 'ek-zuordnung']);
    if (!$r['ok']) return $r;
    $d = $r['daten'];
    $id = isset($d['id']) && $d['id'] !== null ? (int)$d['id'] : null;
    // Nur akzeptieren, wenn die id wirklich in den Kandidaten war (kein Halluzinieren).
    if ($id !== null && !in_array($id, array_column($kand, 'id'), true)) $id = null;
    return ['ok' => true, 'id' => $id, 'score' => max(0, min(100, (int)($d['score'] ?? 0))), 'grund' => mb_substr((string)($d['grund'] ?? ''), 0, 240)];
}

// Batch: die nächsten $limit noch offenen Zeilen eines Typs per KI vorschlagen.
// Setzt item_id/produkt_id als VORSCHLAG (status 'vorschlag'); ohne Treffer status 'kein_treffer'.
function ek_ki_batch(string $typ, int $limit = 25): array {
    $w = ['verarbeitet' => 0, 'vorschlag' => 0, 'kein_treffer' => 0, 'fehler' => 0, 'meldung' => ''];
    $rows = all("SELECT * FROM ek_import WHERE typ=? AND status='offen' ORDER BY id LIMIT ?", [$typ, $limit]);
    foreach ($rows as $ek) {
        $r = ek_ki_match($ek);
        if (!$r['ok']) { $w['fehler']++; $w['meldung'] = $r['fehler'] ?? 'Fehler'; break; }   // z. B. lokal keine KI -> abbrechen
        $w['verarbeitet']++;
        if ($r['id']) {
            $spalte = $typ === 'rohstoff' ? 'item_id' : 'produkt_id';
            q("UPDATE ek_import SET $spalte=?, ki_score=?, ki_hinweis=?, status='vorschlag' WHERE id=?",
              [$r['id'], $r['score'], $r['grund'], (int)$ek['id']]);
            $w['vorschlag']++;
        } else {
            q("UPDATE ek_import SET ki_score=0, ki_hinweis=?, status='kein_treffer' WHERE id=?", [$r['grund'] ?: 'kein Treffer', (int)$ek['id']]);
            $w['kein_treffer']++;
        }
    }
    return $w;
}

// Lieferant-Rohname -> lieferanten.id (unscharf), sonst null. Legt KEINEN Lieferanten an.
function ek_lieferant_id(?string $name): ?int {
    $name = trim((string)$name); if ($name === '') return null;
    $id = scalar("SELECT id FROM lieferanten WHERE firma=? LIMIT 1", [$name])
       ?: scalar("SELECT id FROM lieferanten WHERE firma LIKE ? ORDER BY CHAR_LENGTH(firma) LIMIT 1", ['%' . $name . '%']);
    return $id ? (int)$id : null;
}

// Bestätigte Rohstoff-Zeile als lieferant_preis übernehmen (idempotent über ek_import_id).
function ek_lieferant_preis_schreiben(array $ek): void {
    if ((string)$ek['typ'] !== 'rohstoff' || empty($ek['item_id'])) return;
    if (scalar("SELECT id FROM lieferant_preis WHERE ek_import_id=?", [(int)$ek['id']])) return;   // schon da
    $lid = ek_lieferant_id($ek['lieferant']);
    q("INSERT INTO lieferant_preis (item_id,lieferant_id,lieferant_name,menge_ab,preis,waehrung,stand,quelle,ek_import_id)
       VALUES (?,?,?,0,?, 'EUR', CURDATE(), 'ek_import', ?)",
      [(int)$ek['item_id'], $lid, mb_substr((string)$ek['lieferant'], 0, 120) ?: null, (float)$ek['preis'], (int)$ek['id']]);
}

// Eine Zeile bestätigen (Vorschlag oder manuelle Zuordnung annehmen).
function ek_bestaetigen(int $id): bool {
    $ek = one("SELECT * FROM ek_import WHERE id=?", [$id]);
    if (!$ek) return false;
    if ((string)$ek['typ'] === 'rohstoff' && empty($ek['item_id'])) return false;
    if ((string)$ek['typ'] === 'fertigprodukt' && empty($ek['produkt_id'])) return false;
    q("UPDATE ek_import SET status='bestaetigt' WHERE id=?", [$id]);
    ek_lieferant_preis_schreiben($ek);
    return true;
}

// Manuelle Zuordnung: freie Namens-/Nummerneingabe -> item/produkt auflösen und (optional) bestätigen.
function ek_manuell_zuordnen(int $id, string $eingabe, bool $bestaetigen = true): bool {
    $ek = one("SELECT * FROM ek_import WHERE id=?", [$id]);
    if (!$ek) return false;
    $eingabe = trim($eingabe); if ($eingabe === '') return false;
    if ((string)$ek['typ'] === 'rohstoff') {
        $ziel = scalar("SELECT id FROM item WHERE kategorie='rohstoff' AND (artikelnummer=? OR name=?) LIMIT 1", [$eingabe, $eingabe])
             ?: scalar("SELECT id FROM item WHERE kategorie='rohstoff' AND name LIKE ? ORDER BY CHAR_LENGTH(name) LIMIT 1", ['%' . $eingabe . '%']);
        if (!$ziel) return false;
        q("UPDATE ek_import SET item_id=?, ki_hinweis='manuell zugeordnet', status='vorschlag' WHERE id=?", [(int)$ziel, $id]);
    } else {
        $ziel = scalar("SELECT id FROM produkt WHERE nummer=? OR name=? LIMIT 1", [$eingabe, $eingabe])
             ?: scalar("SELECT id FROM produkt WHERE name LIKE ? ORDER BY CHAR_LENGTH(name) LIMIT 1", ['%' . $eingabe . '%']);
        if (!$ziel) return false;
        q("UPDATE ek_import SET produkt_id=?, ki_hinweis='manuell zugeordnet', status='vorschlag' WHERE id=?", [(int)$ziel, $id]);
    }
    return $bestaetigen ? ek_bestaetigen($id) : true;
}

// Vorschlag verwerfen (Zuordnung löschen, Zeile bleibt zum späteren Neu-Zuordnen).
function ek_verwerfen(int $id): void {
    q("UPDATE ek_import SET item_id=NULL, produkt_id=NULL, ki_score=NULL, ki_hinweis=NULL, status='verworfen' WHERE id=?", [$id]);
}
