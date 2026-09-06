<?php
// EK-Preislisten aus CSV einlesen (Staging-Tabelle ek_import).
//
// Zwei CSV-Formen:
//   ROHSTOFF/Bulk ("Fertige Produkte Final - EK Preise.csv"):
//     Spalte 0 = Name, 3 = EUR/kg ("48,00 €"), 4 = Lieferant.
//   FERTIGPRODUKT ("Neu Produktionsrechner - Fertigprodukte.csv"):
//     Spalte 1 = Produkt, 2 = Formulierung, 3 = Groesse, 4 = Kapselpreis ("0,0362 €"),
//     5 = Menge, 6 = Lieferant.
//
// Aufruf:
//   php tools/ek_import.php --roh="PFAD/EK Preise.csv" --fertig="PFAD/Fertigprodukte.csv"          # Trockenlauf
//   php tools/ek_import.php --roh="..." --fertig="..." --write                                     # schreiben
//   php tools/ek_import.php --reset --write                                                        # Staging leeren
//
// Idempotent ueber zeile_hash (typ|name|lieferant|groesse|preis). Zuordnung zu Items/Produkten
// passiert SPAETER (KI/manuell) – hier landet nur der Rohbestand.
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/schema.php';
require_once __DIR__ . '/../core/ek_ki.php';   // ek_ist_link/ek_plattform: Marktplatz-Links nicht als Lieferant
init_schema();

$WRITE = in_array('--write', $argv, true);
$RESET = in_array('--reset', $argv, true);
$argOf = function (string $name) use ($argv): ?string {
    foreach ($argv as $a) if (strpos($a, "--$name=") === 0) return substr($a, strlen($name) + 3);
    return null;
};
$rohPfad    = $argOf('roh');
$fertigPfad = $argOf('fertig');

// Deutsche Zahl -> float: "0,0362 €" -> 0.0362, "48,00 €" -> 48.0, "120.000" -> 120000, "4.536,00 €" -> 4536.0
$de = function ($s): float {
    $s = preg_replace('/[^0-9.,]/', '', (string)$s);
    if ($s === '') return 0.0;
    $s = str_replace('.', '', $s);   // Tausenderpunkt weg
    $s = str_replace(',', '.', $s);  // Dezimalkomma -> Punkt
    return (float)$s;
};
$leseCsv = function (string $pfad): array {
    $rows = [];
    if (($h = fopen($pfad, 'r')) !== false) { while (($d = fgetcsv($h, 0, ',')) !== false) $rows[] = $d; fclose($h); }
    return $rows;
};
$hash = fn(string $typ, string $name, string $lief, string $gr, float $preis) => md5($typ . '|' . mb_strtolower(trim($name)) . '|' . mb_strtolower(trim($lief)) . '|' . mb_strtolower(trim($gr)) . '|' . $preis);

echo ($WRITE ? 'SCHREIBLAUF' : 'TROCKENLAUF (nichts wird geschrieben)') . " – EK-Import\n\n";

if ($RESET) {
    $n = (int) scalar("SELECT COUNT(*) FROM ek_import");
    echo "RESET: $n Zeilen in ek_import werden geleert.\n\n";
    if ($WRITE) q("DELETE FROM ek_import");
}

$w = ['roh_neu' => 0, 'roh_dubl' => 0, 'roh_uebersprungen' => 0, 'fertig_neu' => 0, 'fertig_dubl' => 0, 'fertig_uebersprungen' => 0];

$einfuegen = function (array $d) use ($WRITE, &$w, $hash) {
    $key = $d['typ'] === 'rohstoff' ? 'roh' : 'fertig';
    // Marktplatz-Link ist kein Lieferant: Link -> Notiz, Lieferant -> Plattformname.
    $notiz = null;
    if (ek_ist_link((string)$d['lieferant'])) { $notiz = 'Quelle-Link: ' . trim((string)$d['lieferant']); $d['lieferant'] = ek_plattform((string)$d['lieferant']); }
    $zh = $hash($d['typ'], $d['name'], (string)$d['lieferant'], (string)$d['groesse'], (float)$d['preis']);
    if (scalar("SELECT id FROM ek_import WHERE zeile_hash=?", [$zh])) { $w[$key . '_dubl']++; return; }
    if ($WRITE) {
        q("INSERT INTO ek_import (typ,name,formulierung,groesse,lieferant,preis,einheit,menge,quelle,notiz,zeile_hash)
           VALUES (?,?,?,?,?,?,?,?,?,?,?)",
          [$d['typ'], mb_substr($d['name'], 0, 255), $d['formulierung'] ?: null, mb_substr((string)$d['groesse'], 0, 60) ?: null,
           mb_substr((string)$d['lieferant'], 0, 120) ?: null, $d['preis'], mb_substr((string)$d['einheit'], 0, 10), $d['menge'] ?: null,
           mb_substr((string)$d['quelle'], 0, 80), $notiz ? mb_substr($notiz, 0, 500) : null, $zh]);
    }
    $w[$key . '_neu']++;
};

// ---- ROHSTOFF/Bulk-CSV ----
if ($rohPfad && is_file($rohPfad)) {
    $q = basename($rohPfad);
    foreach ($leseCsv($rohPfad) as $r) {
        $name = trim((string)($r[0] ?? ''));
        if ($name === '' || $name === ',' || is_numeric($name)) { continue; }
        $preis = $de($r[3] ?? '');           // EUR/kg
        if ($preis <= 0) { $w['roh_uebersprungen']++; continue; }
        $einfuegen(['typ'=>'rohstoff','name'=>$name,'formulierung'=>'','groesse'=>'','lieferant'=>trim((string)($r[4] ?? '')),'preis'=>$preis,'einheit'=>'kg','menge'=>null,'quelle'=>$q]);
    }
} elseif ($rohPfad) { fwrite(STDERR, "Rohstoff-CSV nicht gefunden: $rohPfad\n"); }

// ---- FERTIGPRODUKT-CSV ----
if ($fertigPfad && is_file($fertigPfad)) {
    $q = basename($fertigPfad);
    foreach ($leseCsv($fertigPfad) as $r) {
        $name = trim((string)($r[1] ?? ''));
        if ($name === '' || is_numeric($name) || mb_strtolower($name) === 'produkt') { continue; }
        $kp = $de($r[4] ?? '');              // EUR/Kapsel
        if ($kp <= 0) { $w['fertig_uebersprungen']++; continue; }
        $groesse = trim((string)($r[3] ?? ''));
        $einheit = in_array(mb_strtolower($groesse), ['tablette','softgel','oval','kg'], true) ? mb_strtolower($groesse) : 'kapsel';
        $einfuegen(['typ'=>'fertigprodukt','name'=>$name,'formulierung'=>trim((string)($r[2] ?? '')),'groesse'=>$groesse,
                    'lieferant'=>trim((string)($r[6] ?? '')),'preis'=>$kp,'einheit'=>$einheit,'menge'=>$de($r[5] ?? '') ?: null,'quelle'=>$q]);
    }
} elseif ($fertigPfad) { fwrite(STDERR, "Fertigprodukt-CSV nicht gefunden: $fertigPfad\n"); }

echo "ERGEBNIS:\n";
printf("  Rohstoff/Bulk:   neu %d, schon vorhanden %d, ohne Preis uebersprungen %d\n", $w['roh_neu'], $w['roh_dubl'], $w['roh_uebersprungen']);
printf("  Fertigprodukte:  neu %d, schon vorhanden %d, ohne Preis uebersprungen %d\n", $w['fertig_neu'], $w['fertig_dubl'], $w['fertig_uebersprungen']);
$g = (int) scalar("SELECT COUNT(*) FROM ek_import");
printf("  Gesamt jetzt in ek_import: %d\n", $g);
if (!$WRITE) echo "\nHinweis: Trockenlauf. Mit --write wird geschrieben.\n";
