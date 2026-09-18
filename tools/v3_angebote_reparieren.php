<?php
// TEMPORÄR (v3-Migration): fehlende Preise/Stück je Packung an v3-importierten Angeboten
// aus produkt_kundenpreis nachtragen. Logik in core/schema.php: v3_angebote_reparieren().
// Auf beta gibt es dafür die Admin-Seite ?p=v3_reparatur (Button). NACH Abschluss alles löschen.
//
// CLI-Aufruf (lokal):  php tools/v3_angebote_reparieren.php [kundenId] [--write]

require_once dirname(__DIR__) . '/core/config.php';   // definiert BX_ROOT
require_once BX_ROOT . '/core/schema.php';
init_schema();

$WRITE   = in_array('--write', $argv, true);
$kFilter = 0;
foreach (array_slice($argv, 1) as $arg) { if ($arg !== '--write' && ctype_digit($arg)) { $kFilter = (int)$arg; break; } }

$r = v3_angebote_reparieren($kFilter ?: null, $WRITE);

echo "Angebote (v3-Import" . ($kFilter ? ", Kunde $kFilter" : "") . "): " . $r['angebote'] . "\n";
echo ($WRITE ? "GESCHRIEBEN:\n" : "WÜRDE ändern (Trockenlauf):\n");
printf("  Staffel-Preise nachgetragen:   %d\n", $r['preis_staffel']);
printf("  Positions-Preise nachgetragen: %d\n", $r['preis_position']);
printf("  Staffel Stück/Packung ergänzt: %d\n", $r['stueck_staffel']);
$offen = [];
foreach ($r['offen'] as $o) $offen[] = sprintf('%s (%s): Staffel Menge %d – kein v3-Preis', $o['nummer'], $o['firma'], $o['menge']);
$offen = array_values(array_unique($offen));
echo "\nOhne v3-Preis (manuell bepreisen, " . count($offen) . "):\n";
foreach (array_slice($offen, 0, 40) as $o) echo "  - $o\n";
echo "\n" . ($WRITE ? "Fertig.\n" : ">> Trockenlauf. Mit --write schreiben.\n");
