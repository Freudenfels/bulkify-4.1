<?php
// Eigene MOQ je Rohstoff (Nico 11.11.2026): „unsere Mindestmenge" für den Verkauf an Kunden, z. B. 25 kg wegen Gebinde.
// Unabhängig von der MOQ des Lieferanten (die bleibt am Lieferantenpreis, lieferant_angebot.mindestmenge).
// Intern (Dashboard) ist die MOQ nur ein Hinweis („unter MOQ"), Nico kann abweichen. Im Kundenportal sperrt sie:
// eine kleinere Rohstoffanfrage lässt sich nicht absenden. Ohne MOQ keine Sperre.
require_once __DIR__ . '/schema.php';

function moq_schema(): void {
    static $fertig = false; if ($fertig) return; $fertig = true;
    try {
        ensure_column('item', 'moq_menge', "DECIMAL(12,3) NULL");
        ensure_column('item', 'moq_einheit', "VARCHAR(10) NULL");
        ensure_column('item', 'moq_notiz', "VARCHAR(190) NULL");
    } catch (Throwable $e) {}
}

function moq_einheiten(): array { return ['kg', 'g', 't', 'L', 'Stk']; }

// Menge von einer Einheit in eine andere umrechnen. Nur Gewicht (g/kg/t) untereinander, sonst nur gleiche Einheit.
// null = nicht vergleichbar (z. B. kg gegen Stk).
function moq_umrechnen(float $menge, string $von, string $nach): ?float {
    $norm = fn($e) => ['stück' => 'Stk', 'stueck' => 'Stk', 'stk' => 'Stk', 'stk.' => 'Stk', 'l' => 'L', 'liter' => 'L', 'kg' => 'kg', 'g' => 'g', 't' => 't'][mb_strtolower(trim($e))] ?? trim($e);
    $von = $norm($von); $nach = $norm($nach);
    if ($von === $nach) return $menge;
    $g = ['g' => 1.0, 'kg' => 1000.0, 't' => 1000000.0];
    if (isset($g[$von], $g[$nach])) return $menge * $g[$von] / $g[$nach];
    return null;
}

// MOQ eines Rohstoffs oder null (keine gesetzt).
function rohstoff_moq(int $item_id): ?array {
    static $cache = [];
    if ($item_id <= 0) return null;
    if (array_key_exists($item_id, $cache)) return $cache[$item_id];
    moq_schema();
    $r = null;
    try { $r = one("SELECT moq_menge, moq_einheit, moq_notiz FROM item WHERE id=?", [$item_id]); } catch (Throwable $e) {}
    if (!$r || $r['moq_menge'] === null || (float)$r['moq_menge'] <= 0) return $cache[$item_id] = null;
    return $cache[$item_id] = ['menge' => (float)$r['moq_menge'], 'einheit' => (string)($r['moq_einheit'] ?: 'kg'), 'notiz' => trim((string)($r['moq_notiz'] ?? ''))];
}

function moq_zahl(float $x): string { return rtrim(rtrim(number_format($x, 3, ',', '.'), '0'), ','); }

// „25 kg"
function moq_wert(array $moq): string { return moq_zahl((float)$moq['menge']) . ' ' . $moq['einheit']; }

// Prüfen, ob eine Menge unter der MOQ liegt. null = keine MOQ. Sonst:
//   ['moq'=>[...], 'vergleichbar'=>bool, 'unter'=>bool, 'menge_moq'=>Menge in MOQ-Einheit|null]
function moq_pruefen(int $item_id, ?float $menge, ?string $einheit): ?array {
    $moq = rohstoff_moq($item_id);
    if (!$moq) return null;
    if ($menge === null || $menge <= 0) return ['moq' => $moq, 'vergleichbar' => false, 'unter' => false, 'menge_moq' => null];
    $m = moq_umrechnen($menge, (string)($einheit ?: $moq['einheit']), $moq['einheit']);
    if ($m === null) return ['moq' => $moq, 'vergleichbar' => false, 'unter' => false, 'menge_moq' => null];
    return ['moq' => $moq, 'vergleichbar' => true, 'unter' => $m + 1e-9 < $moq['menge'], 'menge_moq' => $m];
}

// Interner Hinweis: „MOQ 25 kg" und bei zu kleiner Menge „unter MOQ". Leer, wenn keine MOQ gesetzt ist.
function moq_hinweis_html(int $item_id, ?float $menge = null, ?string $einheit = null): string {
    $p = moq_pruefen($item_id, $menge, $einheit);
    if (!$p) return '';
    $titel = $p['moq']['notiz'] !== '' ? ' title="' . h($p['moq']['notiz']) . '"' : '';
    $o = '<span' . $titel . ' style="white-space:nowrap">' . bx_badge('MOQ ' . moq_wert($p['moq']), 'info') . '</span>';
    if ($p['unter']) $o .= ' <span style="white-space:nowrap">' . bx_badge('unter MOQ', 'warn') . '</span>';
    elseif (!$p['vergleichbar'] && $menge !== null && $menge > 0) $o .= ' <span class="muted" style="font-size:12px">Einheit nicht vergleichbar</span>';
    return $o;
}

// MOQ-Prüfung für ein Angebot (nur Hinweis, keine Sperre). Je Rohstoff mit MOQ:
//  - Rohstoff-Weiterverkauf (Position quelle='rohstoff'): angebotene Menge gegen MOQ.
//  - Rezeptur-Positionen (Herstellung): benötigte Rohstoffmenge für die Auftragsmenge der Option
//    (Rezeptur-Einheiten x mg je Einheit) gegen MOQ. Bei mehreren Optionen zählt die kleinste und die größte.
// Rückgabe: Liste ['item_id','name','quelle'=>'Rohstoff'|'Rezeptur','min'=>Menge in MOQ-Einheit,'max'=>…,'moq'=>[…],'unter'=>bool]
function angebot_moq_pruefung(int $angebot_id): array {
    $out = [];
    $merke = function (int $iid, string $name, string $quelle, float $menge) use (&$out) {
        $moq = rohstoff_moq($iid); if (!$moq) return;
        $k = $iid . '|' . $quelle;
        if (!isset($out[$k])) $out[$k] = ['item_id' => $iid, 'name' => $name, 'quelle' => $quelle, 'min' => $menge, 'max' => $menge, 'moq' => $moq];
        else { $out[$k]['min'] = min($out[$k]['min'], $menge); $out[$k]['max'] = max($out[$k]['max'], $menge); }
    };
    $a = one("SELECT id, anfrage_id FROM angebot WHERE id=?", [$angebot_id]);
    if (!$a) return [];
    // Rohstoff-Weiterverkauf: Artikel über die Artikelnummer, sonst über die Kundenanfrage.
    $anfRoh = !empty($a['anfrage_id']) ? (int) scalar("SELECT rohstoff_id FROM portal_anfrage WHERE id=? AND typ='rohstoff'", [(int)$a['anfrage_id']]) : 0;
    foreach (all("SELECT artikelnr, bezeichnung, menge, einheit FROM angebot_position WHERE angebot_id=? AND quelle='rohstoff'", [$angebot_id]) as $p) {
        $iid = trim((string)$p['artikelnr']) !== '' ? (int) scalar("SELECT id FROM item WHERE artikelnummer=? AND kategorie='rohstoff' LIMIT 1", [trim((string)$p['artikelnr'])]) : 0;
        if (!$iid) $iid = $anfRoh;
        if (!$iid || !($moq = rohstoff_moq($iid))) continue;
        $m = moq_umrechnen((float)$p['menge'], (string)($p['einheit'] ?: $moq['einheit']), $moq['einheit']);
        if ($m !== null) $merke($iid, (string)$p['bezeichnung'], 'Rohstoff', $m);
    }
    // Rezeptur-Positionen: benötigte Menge je Rohstoff für die Auftragsmenge der Option.
    foreach (all("SELECT rezeptur_id, stueck, menge FROM angebot_position
                  WHERE angebot_id=? AND quelle='herstellung' AND rezeptur_id IS NOT NULL AND stueck>0", [$angebot_id]) as $p) {
        $rid = (int)$p['rezeptur_id'];
        $einheiten = function_exists('rezeptur_einheiten_gesamt') ? rezeptur_einheiten_gesamt($rid, (float)$p['stueck'], (float) round((float)$p['menge'])) : 0.0;
        if ($einheiten <= 0) continue;
        foreach (all("SELECT z.item_id, z.menge_mg, i.name FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? AND z.item_id IS NOT NULL", [$rid]) as $z) {
            $moq = rohstoff_moq((int)$z['item_id']); if (!$moq) continue;
            $m = moq_umrechnen((float)$z['menge_mg'] * $einheiten / 1000.0, 'g', $moq['einheit']);   // mg -> g -> MOQ-Einheit
            if ($m !== null) $merke((int)$z['item_id'], (string)$z['name'], 'Rezeptur', $m);
        }
    }
    foreach ($out as &$r) $r['unter'] = $r['min'] + 1e-9 < $r['moq']['menge'];
    unset($r);
    return array_values($out);
}
