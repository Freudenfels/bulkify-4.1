<?php
// Monatlicher Novel-Food-Abgleich (für den Server-Scheduler / Cron).
// Zieht den EU-Katalog, übersetzt das Delta, protokolliert den Lauf und übernimmt die Daten.
// Aufruf:  php tools/novelfood_sync.php
// Derselbe Job steckt hinter dem Admin-Button (module/produkt/novelfood_import.php).
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/schema.php';
require_once __DIR__ . '/../core/novelfood_sync.php';
init_schema();

$r = novelfood_sync_lauf('cli');

if (!($r['ok'] ?? false)) {
    fwrite(STDERR, "Novel-Food-Abgleich FEHLGESCHLAGEN: " . ($r['fehler'] ?? 'unbekannt') . "\n");
    // nächsten planmäßigen Lauf trotzdem vermerken (grober Monatsrhythmus)
    if (function_exists('meta_set')) meta_set('novelfood_next_run', gmdate('Y-m-d', strtotime('+1 month')));
    exit(1);
}

echo "Novel-Food-Abgleich fertig (Lauf #{$r['lauf_id']}):\n";
echo "  Gesamt im EU-Katalog: {$r['gesamt']}\n";
echo "  neu: {$r['neu']}  |  geändert: {$r['geaendert']}  (davon Statuswechsel: {$r['status']})  |  entfernt: {$r['entfernt']}\n";
echo "  übersetzt: {$r['uebersetzt']}  |  KI-Tokens: {$r['tokens']}\n";
echo "  DB: {$r['db_neu']} neu angelegt, {$r['db_upd']} aktualisiert.  Dauer: {$r['dauer_ms']} ms\n";
if (!empty($r['meldung'])) echo "  Hinweis: {$r['meldung']}\n";

if (function_exists('meta_set')) meta_set('novelfood_next_run', gmdate('Y-m-d', strtotime('+1 month')));
$g = (int) scalar("SELECT COUNT(*) FROM novelfood_katalog");
echo "Gesamt in der DB: $g\n";
