<?php
// Leichtgewichtiger Performance-Profiler für die Diagnose-Seite (Einstellungen → Diagnose).
// - Zählt je Seitenaufruf Dauer, Anzahl DB-Abfragen und DB-Zeit (Zähler stehen in core/db.php).
// - Wenn die Messung eingeschaltet ist (app_meta.perf_log=1), wird je Aufruf EINE Zeile in
//   data/perf.log geschrieben. So sieht man in der Oberfläche, welche Seiten wie lange brauchen.
// - Die Live-Messung (perf_selftest) misst die reine DB-Latenz und zeigt Umgebungsinfos.
require_once __DIR__ . '/db.php';

// Pfad der Logdatei (liegt in data/, wird NICHT committet).
function perf_logdatei(): string {
    return dirname(BX_UPLOADS) . '/perf.log';
}

// Ist die Aufzeichnung eingeschaltet?
function perf_aktiv(): bool {
    return (string) meta_get('perf_log', '0') === '1';
}

// Am Ende eines Requests aufrufen (register_shutdown_function). $startT = microtime(true) vom Request-Start.
function perf_aufzeichnen(float $startT): void {
    if (!perf_aktiv()) return;
    $s = db_stats();
    $route = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['p'] ?? '')) ?: 'start';
    $view  = preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['v'] ?? ''));
    if ($view !== '') $route .= ':' . $view;
    // Top-Abfragemuster dieses Requests (nur wenn erfasst): "anzahl×ms|muster" ~ getrennt.
    $shapes = '';
    if (!empty($GLOBALS['bx_q_shapes'])) {
        $arr = $GLOBALS['bx_q_shapes'];
        uasort($arr, fn($x, $y) => ($y['n'] * 1000 + $y['ms']) <=> ($x['n'] * 1000 + $x['ms']));
        $top = array_slice($arr, 0, 6, true);
        $teile = [];
        foreach ($top as $muster => $d) $teile[] = $d['n'] . 'x/' . round($d['ms']) . 'ms ' . str_replace(['~', '|'], ' ', $muster);
        $shapes = implode(' ~ ', $teile);
    }
    $zeile = implode("\t", [
        gmdate('Y-m-d H:i:s'),
        $route,
        (string) round((microtime(true) - $startT) * 1000),      // Gesamtdauer ms
        (string) $s['anzahl'],                                    // Anzahl Abfragen
        (string) round($s['db_ms']),                             // DB-Zeit ms
        (string) round($s['connect_ms']),                        // Verbindungsaufbau ms
        (string) round(memory_get_peak_usage() / 1048576, 1),    // Spitzen-RAM MB
        $shapes,                                                  // Top-Abfragemuster (optional)
    ]);
    $f = perf_logdatei();
    @file_put_contents($f, $zeile . "\n", FILE_APPEND | LOCK_EX);
    // Datei begrenzen: bei > ~200 KB auf die letzten 400 Zeilen kürzen (selten, nicht bei jedem Schreiben).
    if (@filesize($f) > 200000) {
        $zeilen = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        @file_put_contents($f, implode("\n", array_slice($zeilen, -400)) . "\n", LOCK_EX);
    }
}

// Die letzten N aufgezeichneten Aufrufe als Array (neueste zuerst).
function perf_letzte(int $n = 60): array {
    $f = perf_logdatei();
    if (!is_file($f)) return [];
    $zeilen = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $zeilen = array_slice($zeilen, -$n);
    $out = [];
    foreach (array_reverse($zeilen) as $z) {
        $t = explode("\t", $z);
        if (count($t) < 7) continue;
        $out[] = ['zeit'=>$t[0], 'route'=>$t[1], 'dauer'=>(int)$t[2], 'abfragen'=>(int)$t[3],
                  'db_ms'=>(int)$t[4], 'connect_ms'=>(int)$t[5], 'ram'=>(float)$t[6],
                  'muster'=>$t[7] ?? ''];
    }
    return $out;
}

// Kennzahlen je Route (Ø/max Dauer, Ø Abfragen) – zeigt die langsamsten Seiten.
function perf_je_route(): array {
    $agg = [];
    foreach (perf_letzte(400) as $r) {
        $k = $r['route'];
        if (!isset($agg[$k])) $agg[$k] = ['route'=>$k, 'n'=>0, 'sum'=>0, 'max'=>0, 'sumq'=>0];
        $agg[$k]['n']++; $agg[$k]['sum'] += $r['dauer']; $agg[$k]['sumq'] += $r['abfragen'];
        if ($r['dauer'] > $agg[$k]['max']) $agg[$k]['max'] = $r['dauer'];
    }
    foreach ($agg as &$a) { $a['avg'] = $a['n'] ? (int) round($a['sum'] / $a['n']) : 0; $a['avgq'] = $a['n'] ? (int) round($a['sumq'] / $a['n']) : 0; }
    usort($agg, fn($x, $y) => $y['avg'] <=> $x['avg']);   // langsamste zuerst
    return $agg;
}

function perf_logdatei_leeren(): void { @file_put_contents(perf_logdatei(), '', LOCK_EX); }

// Live-Messung: reine DB-Latenz + Umgebungsinfos. Läuft synchron beim Öffnen der Seite.
function perf_selftest(): array {
    // Latenz je Abfrage: viele triviale Roundtrips messen (SELECT 1) + eine information_schema-Abfrage,
    // die auf beta bisher teuer war.
    $n = 50; $t0 = microtime(true);
    for ($i = 0; $i < $n; $i++) scalar("SELECT 1");
    $pingMs = (microtime(true) - $t0) * 1000 / $n;

    $t1 = microtime(true);
    scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ?", [DB_NAME]);
    $isMs = (microtime(true) - $t1) * 1000;

    // OPcache-Status (beschleunigt PHP enorm, wenn an).
    $op = ['aktiv' => false, 'hits' => null];
    if (function_exists('opcache_get_status')) {
        $st = @opcache_get_status(false);
        if (is_array($st)) {
            $op['aktiv'] = !empty($st['opcache_enabled']);
            if (isset($st['opcache_statistics']['opcache_hit_rate'])) $op['hits'] = round((float)$st['opcache_statistics']['opcache_hit_rate'], 1);
        }
    }

    $dbVer = '';
    try { $dbVer = (string) db()->getAttribute(PDO::ATTR_SERVER_VERSION); } catch (\Throwable $e) {}
    $host = defined('DB_HOST') ? DB_HOST : '';

    return [
        'ping_ms'      => round($pingMs, 2),
        'is_ms'        => round($isMs, 1),
        'connect_ms'   => round((float)($GLOBALS['bx_db_connect_ms'] ?? 0), 1),
        'php_version'  => PHP_VERSION,
        'opcache'      => $op,
        'mem_limit'    => ini_get('memory_limit'),
        'db_version'   => $dbVer,
        'db_host'      => $host,
        'db_lokal'     => in_array($host, ['127.0.0.1', 'localhost', '::1'], true),
        'emulate_prep' => true,   // seit dem Performance-Umbau (core/db.php)
        'schema_guard' => (string) meta_get('schema_build', '') !== '',
    ];
}
