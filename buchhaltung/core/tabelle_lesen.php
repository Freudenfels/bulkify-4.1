<?php
// Tabellen als Text lesen: CSV, TXT und Excel (.xlsx).
//
// Warum eigen gebaut: Das Projekt läuft ohne Composer, also ohne PhpSpreadsheet. Eine .xlsx-Datei
// ist aber nur ein ZIP mit XML darin – das kann PHP mit Bordmitteln (zip + simplexml).
// Das Ergebnis ist schlichter Text mit Tabulatoren; genau so kann die KI ihn lesen.
//
// Nicht unterstützt: das alte Binärformat .xls (vor Excel 2007) und .ods. Beides müsste der
// Absender vorher als .xlsx oder .csv speichern – die Oberfläche sagt das auch.

// Welche Endungen hier gelesen werden können.
function tabelle_endungen(): array { return ['csv', 'txt', 'tsv', 'xlsx', 'xlsm']; }
function ist_tabelle(string $pfad): bool {
    return in_array(strtolower(pathinfo($pfad, PATHINFO_EXTENSION)), tabelle_endungen(), true);
}

// Eine Tabelle als Text. null = nicht lesbar. $max_zeilen begrenzt sehr lange Listen.
function tabelle_text(string $pfad, int $max_zeilen = 2000): ?string {
    if (!is_file($pfad)) return null;
    $ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
    if (in_array($ext, ['xlsx', 'xlsm'], true)) return xlsx_text($pfad, $max_zeilen);
    if (in_array($ext, ['csv', 'txt', 'tsv'], true)) return csv_text($pfad, $max_zeilen);
    return null;
}

// CSV/TXT: Zeichensatz erkennen und das Trennzeichen aus der Kopfzeile ableiten.
function csv_text(string $pfad, int $max_zeilen = 2000): ?string {
    $roh = @file_get_contents($pfad);
    if ($roh === false || trim($roh) === '') return null;
    // Excel schreibt CSV gern in Windows-1252; ohne Umwandlung werden Umlaute zu Fragezeichen.
    if (!mb_check_encoding($roh, 'UTF-8')) $roh = mb_convert_encoding($roh, 'UTF-8', 'Windows-1252');
    $roh = preg_replace('/^\xEF\xBB\xBF/', '', $roh);          // BOM weg
    $zeilen = preg_split('/\r\n|\r|\n/', $roh);
    // Trennzeichen: das häufigste der drei üblichen in den ersten Zeilen.
    $probe = implode("\n", array_slice($zeilen, 0, 5));
    $trenn = ';';
    foreach ([';' => substr_count($probe, ';'), ',' => substr_count($probe, ','), "\t" => substr_count($probe, "\t")] as $z => $n)
        if ($n > substr_count($probe, $trenn)) $trenn = $z;
    $out = []; $n = 0;
    foreach ($zeilen as $z) {
        if (trim($z) === '') continue;
        $sp = str_getcsv($z, $trenn === "\t" ? "\t" : $trenn);
        $out[] = implode("\t", array_map(fn($s) => trim((string)$s), $sp));
        if (++$n >= $max_zeilen) { $out[] = '… (weitere Zeilen abgeschnitten)'; break; }
    }
    return $out ? implode("\n", $out) : null;
}

// Excel (.xlsx/.xlsm): ZIP öffnen, Texttabelle und Blätter lesen.
function xlsx_text(string $pfad, int $max_zeilen = 2000): ?string {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($pfad) !== true) return null;

    // 1) Die gemeinsame Texttabelle – Excel legt jeden Text nur einmal ab und verweist darauf.
    $texte = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $x = @simplexml_load_string($ss);
        if ($x !== false) foreach ($x->si as $si) {
            // Ein Eintrag ist entweder ein <t> oder mehrere <r><t> (unterschiedlich formatierte Teile).
            $t = '';
            if (isset($si->t)) $t = (string)$si->t;
            elseif (isset($si->r)) foreach ($si->r as $r) $t .= (string)$r->t;
            $texte[] = $t;
        }
    }

    // 2) Blattnamen in der Reihenfolge der Datei.
    $namen = [];
    $wb = $zip->getFromName('xl/workbook.xml');
    if ($wb !== false) {
        $x = @simplexml_load_string($wb);
        if ($x !== false && isset($x->sheets->sheet)) foreach ($x->sheets->sheet as $s) $namen[] = (string)$s['name'];
    }

    $out = []; $zeilenGesamt = 0; $blatt = 0;
    while (($sheet = $zip->getFromName('xl/worksheets/sheet' . ($blatt + 1) . '.xml')) !== false) {
        $x = @simplexml_load_string($sheet);
        $blatt++;
        if ($x === false || !isset($x->sheetData->row)) continue;
        if (count($namen) > 1) $out[] = "\n=== Blatt: " . ($namen[$blatt - 1] ?? ('Blatt ' . $blatt)) . " ===";
        foreach ($x->sheetData->row as $row) {
            $zellen = []; $maxSpalte = 0;
            foreach ($row->c as $c) {
                $sp = xlsx_spalte((string)$c['r']);
                $typ = (string)$c['t'];
                if ($typ === 's')            $w = $texte[(int)$c->v] ?? '';        // Verweis in die Texttabelle
                elseif ($typ === 'inlineStr') $w = (string)($c->is->t ?? '');
                elseif ($typ === 'b')         $w = ((string)$c->v === '1') ? 'ja' : 'nein';
                else                          $w = isset($c->v) ? (string)$c->v : '';
                $zellen[$sp] = trim($w);
                if ($sp > $maxSpalte) $maxSpalte = $sp;
            }
            if (!$zellen) continue;
            $zeile = [];
            for ($i = 0; $i <= $maxSpalte; $i++) $zeile[] = $zellen[$i] ?? '';
            if (trim(implode('', $zeile)) === '') continue;      // ganz leere Zeilen weglassen
            $out[] = implode("\t", $zeile);
            if (++$zeilenGesamt >= $max_zeilen) { $out[] = '… (weitere Zeilen abgeschnitten)'; break 2; }
        }
    }
    $zip->close();
    return $out ? trim(implode("\n", $out)) : null;
}

// Zellbezug wie „AB12" in den Spaltenindex (0-basiert) umrechnen.
function xlsx_spalte(string $ref): int {
    $b = strtoupper(preg_replace('/[^A-Za-z]/', '', $ref));
    if ($b === '') return 0;
    $n = 0;
    for ($i = 0; $i < strlen($b); $i++) $n = $n * 26 + (ord($b[$i]) - 64);
    return max(0, $n - 1);
}
