<?php
// Produktionsplan (Nico 11.11.2026, plan-produktion-gefuehrt.md, Teil 2). Dashboard-Datei (braucht core/schema.php).
// - Reihenfolge per Ziehen (produktionsauftrag.plan_sort), Datum (geplant_am), Linie (linie), Mitarbeiter (mitarbeiter_id).
// - „Machbar je Schritt": EINE Rechnung (produktion_machbar_schritte). Fehlt nur das Etikett, ist nur das Etikettieren gesperrt.
//   Das Ergebnis steht zusätzlich in pa_machbar, damit die Werk-App (Sub-App ohne core/schema.php) dieselbe Antwort liest.

function produktion_plan_schema(): void {
    static $ok = false; if ($ok) return; $ok = true;
    if ((string) meta_get('produktion_plan_schema', '') === '2') return;
    try {
        ensure_column('produktionsauftrag', 'plan_sort', 'INT NULL');
        ensure_column('produktionsauftrag', 'linie', 'VARCHAR(40) NULL');
        q("CREATE TABLE IF NOT EXISTS pa_machbar (
            pa_id INT PRIMARY KEY,
            machbar TINYINT NOT NULL DEFAULT 0,          -- 1 = kann starten (ggf. nur bis vor dem Etikettieren)
            stufe VARCHAR(16) NOT NULL DEFAULT '',       -- startklar | bis_etikett | gesperrt | laeuft | fertig
            grund VARCHAR(255) NULL,                     -- Klartext: „Etikett fehlt“, „Bulk unterwegs bis 14.11.“
            etikett_gesperrt TINYINT NOT NULL DEFAULT 0,
            berechnet DATETIME NOT NULL
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        meta_set('produktion_plan_schema', '2');
    } catch (\Throwable $e) { error_log('produktion_plan_schema: ' . $e->getMessage()); }
}
function produktion_linien(): array {
    $l = array_values(array_filter(array_map('trim', explode(',', (string) meta_get('produktion_linien', 'Linie 1,Linie 2')))));
    return $l ?: ['Linie 1'];
}
// Machbar je Schritt für einen PA. Rückgabe ['machbar','stufe','grund','etikett_gesperrt','fehlend'] und Ablage in pa_machbar.
function produktion_machbar_schritte(int $pa_id, bool $speichern = true): array {
    produktion_plan_schema();
    $ber = produktion_bereitschaft($pa_id);
    $st = (string)($ber['status'] ?? '');
    $etSperre = produktion_etikett_sperre($pa_id);
    $etFehlt = []; $sonst = [];
    foreach (($ber['fehlend'] ?? []) as $f) {
        if (stripos((string)($f['rolle'] ?? ''), 'etikett') !== false) $etFehlt[] = $f; else $sonst[] = $f;
    }
    $mz = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    if ($st === 'fertig') $r = ['machbar' => 0, 'stufe' => 'fertig', 'grund' => ''];
    elseif ($st === 'laeuft') $r = ['machbar' => 1, 'stufe' => 'laeuft', 'grund' => ''];
    elseif ($sonst) {
        $mb = produktion_material_bestellt($sonst, (int) scalar("SELECT COALESCE(auftrag_id,0) FROM produktionsauftrag WHERE id=?", [$pa_id]));
        $namen = array_map(fn($f) => (string)($f['rolle'] ?: 'Material') . ' ' . mb_strimwidth((string)$f['name'], 0, 40, '…') . ' (fehlt ' . $mz($f['fehlt']) . ')', array_slice($sonst, 0, 2));
        $r = ['machbar' => 0, 'stufe' => 'gesperrt',
              'grund' => ($mb['ja'] ? 'unterwegs' . ($mb['eta'] ? ' bis ' . date('d.m.', strtotime($mb['eta'])) : '') . ': ' : '') . implode(', ', $namen) . (count($sonst) > 2 ? ' + ' . (count($sonst) - 2) . ' weitere' : '')];
    } elseif ($etFehlt || $etSperre !== '') {
        $r = ['machbar' => 1, 'stufe' => 'bis_etikett', 'grund' => $etSperre !== '' ? $etSperre : 'Etikett fehlt (' . $mz($etFehlt[0]['fehlt']) . ' Stk) – Abfüllen geht, Etikettieren gesperrt.'];
    } else $r = ['machbar' => 1, 'stufe' => 'startklar', 'grund' => ''];
    $r['etikett_gesperrt'] = ($etFehlt || $etSperre !== '') ? 1 : 0;
    $r['fehlend'] = $ber['fehlend'] ?? [];
    if ($speichern) {
        try {
            q("INSERT INTO pa_machbar (pa_id, machbar, stufe, grund, etikett_gesperrt, berechnet) VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE machbar=VALUES(machbar), stufe=VALUES(stufe), grund=VALUES(grund), etikett_gesperrt=VALUES(etikett_gesperrt), berechnet=VALUES(berechnet)",
              [$pa_id, $r['machbar'], $r['stufe'], mb_substr((string)$r['grund'], 0, 255) ?: null, $r['etikett_gesperrt'], gmdate('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {}
    }
    return $r;
}
// Reihenfolge + Felder speichern. $reihenfolge = [pa_id, …] (oben zuerst); $felder = [pa_id => ['datum','linie','mitarbeiter']].
function produktion_plan_speichern(array $reihenfolge, array $felder): int {
    produktion_plan_schema();
    $n = 0;
    foreach (array_values(array_unique(array_map('intval', $reihenfolge))) as $i => $pid) {
        if ($pid <= 0) continue;
        $n += q("UPDATE produktionsauftrag SET plan_sort=? WHERE id=?", [($i + 1) * 10, $pid])->rowCount();
    }
    foreach ($felder as $pid => $f) {
        $pid = (int)$pid; if ($pid <= 0 || !is_array($f)) continue;
        $d = trim((string)($f['datum'] ?? ''));
        $alt = one("SELECT geplant_am, linie, mitarbeiter_id FROM produktionsauftrag WHERE id=?", [$pid]);
        if (!$alt) continue;
        $neu = ['geplant_am' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null,
                'linie' => mb_substr(trim((string)($f['linie'] ?? '')), 0, 40) ?: null,
                'mitarbeiter_id' => (int)($f['mitarbeiter'] ?? 0) ?: null];
        foreach ($neu as $k => $v) if ((string)($alt[$k] ?? '') !== (string)($v ?? '')) {
            q("UPDATE produktionsauftrag SET $k=? WHERE id=?", [$v, $pid]);
            if (function_exists('aenderung_protokollieren')) aenderung_protokollieren('produktionsauftrag', $pid, [$k => $alt[$k]], [$k => $v], 'produktion_plan');
            $n++;
        }
    }
    return $n;
}
// Sortierung der Plan-Liste: plan_sort (gesetzt) zuerst, dann Datum, Priorität, älteste zuerst.
function produktion_plan_sortieren(array $rows): array {
    usort($rows, fn($a, $b) => [$a['plan_sort'] === null, (int)$a['plan_sort'], (string)($a['geplant_am'] ?? '9999'), (int)($a['prio'] ?? 2), (int)$a['id']]
                          <=> [$b['plan_sort'] === null, (int)$b['plan_sort'], (string)($b['geplant_am'] ?? '9999'), (int)($b['prio'] ?? 2), (int)$b['id']]);
    return $rows;
}
