<?php
// Text aus einem PDF ziehen – ohne externe Programme, nur mit PHP und zlib.
//
// Wofür: Lieferanten schicken CoA und Spezifikation als PDF. Statt alles abzutippen,
// lesen wir heraus, was maschinell lesbar ist, und schlagen es zur Prüfung vor.
//
// GRENZE, die man kennen muss: Das funktioniert nur bei PDFs, in denen der Text auch
// wirklich als Text steckt. Ist das PDF ein **Scan** (ein Bild), steht dort kein Text –
// dann kommt nichts zurück. Dafür bräuchte es Texterkennung (OCR), die hier nicht
// eingebaut ist. Deshalb liefert die Funktion lieber nichts als geratene Werte.

// Kompletten Text eines PDFs. Leerer String = nichts lesbar (z. B. Scan).
function pdf_text_extrahieren(string $pfad): string {
    if (!is_file($pfad)) return '';
    $roh = @file_get_contents($pfad);
    if ($roh === false || strncmp($roh, '%PDF', 4) !== 0) return '';

    $teile = [];
    // Alle Streams durchgehen; die mit Text sind nach dem Entpacken an BT/ET erkennbar.
    if (preg_match_all('/stream\r?\n(.*?)endstream/s', $roh, $m)) {
        foreach ($m[1] as $stream) {
            $inhalt = @gzuncompress($stream);
            if ($inhalt === false) $inhalt = @gzinflate($stream);
            if ($inhalt === false) $inhalt = $stream;              // unkomprimiert
            if (strpos($inhalt, 'BT') === false) continue;          // kein Textblock
            $teile[] = pdf_text_aus_stream($inhalt);
        }
    }
    $text = trim(implode("\n", array_filter($teile)));
    // Sicherheitsnetz: kommt fast nichts heraus, gilt das PDF als nicht lesbar.
    return mb_strlen(preg_replace('/\s+/u', '', $text)) < 20 ? '' : $text;
}

// Textoperatoren eines Content-Streams auswerten (Tj, TJ, ' und ") – MIT Position.
// Warum die Position: In einem PDF ist eine Tabellenzeile kein Text, sondern mehrere
// Textstuecke an verschiedenen Stellen. Ohne die y-Koordinate zerfaellt „Blei | max. 0,5 | 0,05 | ICP-MS"
// in vier einzelne Zeilen und die Werte lassen sich dem Parameter nicht mehr zuordnen.
// Deshalb sammeln wir Stuecke mit x/y und setzen daraus Zeilen zusammen (gleiche Hoehe = eine Zeile).
function pdf_text_aus_stream(string $s): string {
    $len = strlen($s); $i = 0;
    $zahlen = [];                 // zuletzt gesehene Operanden
    $x = 0.0; $y = 0.0; $leading = 12.0;
    $stuecke = [];                // [y, x, text]
    $puffer = '';
    $ablegen = function () use (&$stuecke, &$puffer, &$x, &$y) {
        $t = trim($puffer);
        if ($t !== '') $stuecke[] = [$y, $x, $t];
        $puffer = '';
    };
    while ($i < $len) {
        $c = $s[$i];
        if ($c === '(') { [$txt, $i] = pdf_string_lesen($s, $i); $puffer .= $txt; continue; }
        if ($c === '<' && isset($s[$i + 1]) && $s[$i + 1] !== '<') {
            $e = strpos($s, '>', $i);
            if ($e === false) break;
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, $i + 1, $e - $i - 1));
            if (strlen($hex) % 2 === 1) $hex .= '0';
            $puffer .= pdf_zeichen(hex2bin($hex) ?: '');
            $i = $e + 1; continue;
        }
        // Zahlen merken – sie sind die Operanden der folgenden Positionsbefehle
        if ($c === '-' || $c === '.' || ctype_digit($c)) {
            $j = $i; while ($j < $len && (ctype_digit($s[$j]) || $s[$j] === '.' || $s[$j] === '-')) $j++;
            $zahlen[] = (float)substr($s, $i, $j - $i);
            if (count($zahlen) > 6) array_shift($zahlen);
            $i = $j; continue;
        }
        if ($c === 'T' && isset($s[$i + 1])) {
            $op = $s[$i + 1];
            if ($op === 'd' || $op === 'D') {                     // relativ zur Zeile
                $ablegen();
                $n = count($zahlen);
                if ($n >= 2) { $x += $zahlen[$n - 2]; $y += $zahlen[$n - 1]; if ($op === 'D') $leading = -$zahlen[$n - 1]; }
                $zahlen = []; $i += 2; continue;
            }
            if ($op === 'm') {                                    // absolute Textmatrix
                $ablegen();
                $n = count($zahlen);
                if ($n >= 6) { $x = $zahlen[$n - 2]; $y = $zahlen[$n - 1]; }
                $zahlen = []; $i += 2; continue;
            }
            if ($op === '*') { $ablegen(); $y -= $leading; $zahlen = []; $i += 2; continue; }
            if ($op === 'L') { $n = count($zahlen); if ($n >= 1) $leading = $zahlen[$n - 1]; $zahlen = []; $i += 2; continue; }
        }
        if ($c === "'" || $c === '"') { $ablegen(); $y -= $leading; $zahlen = []; $i++; continue; }
        if ($c === 'B' && substr($s, $i, 2) === 'BT') { $ablegen(); $x = 0.0; $y = 0.0; $zahlen = []; $i += 2; continue; }
        if ($c === 'E' && substr($s, $i, 2) === 'ET') { $ablegen(); $zahlen = []; $i += 2; continue; }
        $i++;
    }
    $ablegen();
    if (!$stuecke) return '';
    // Nach Hoehe gruppieren (2 Punkte Toleranz), innerhalb der Zeile nach x sortieren.
    usort($stuecke, fn($a, $b) => ($b[0] <=> $a[0]) ?: ($a[1] <=> $b[1]));
    $zeilen = []; $aktY = null; $aktuell = [];
    foreach ($stuecke as [$sy, $sx, $st]) {
        if ($aktY === null || abs($sy - $aktY) > 2.0) {
            if ($aktuell) $zeilen[] = implode('   ', $aktuell);
            $aktuell = []; $aktY = $sy;
        }
        $aktuell[] = $st;
    }
    if ($aktuell) $zeilen[] = implode('   ', $aktuell);
    return implode("\n", $zeilen);
}

// Einen PDF-Literal-String ab Position $i lesen (inkl. Escapes und Klammer-Verschachtelung).
function pdf_string_lesen(string $s, int $i): array {
    $len = strlen($s); $tiefe = 0; $roh = '';
    for (; $i < $len; $i++) {
        $c = $s[$i];
        if ($c === '\\') {
            $n = $s[$i + 1] ?? '';
            if ($n === '') break;
            if (ctype_digit($n)) {                          // oktale Escape-Sequenz
                $okt = '';
                for ($k = 1; $k <= 3 && ctype_digit($s[$i + $k] ?? ''); $k++) $okt .= $s[$i + $k];
                $roh .= chr(octdec($okt)); $i += strlen($okt);
            } else {
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c"];
                $roh .= $map[$n] ?? $n; $i++;
            }
            continue;
        }
        if ($c === '(') { $tiefe++; if ($tiefe === 1) continue; }
        if ($c === ')') { $tiefe--; if ($tiefe === 0) { $i++; break; } }
        if ($tiefe >= 1) $roh .= $c;
    }
    return [pdf_zeichen($roh), $i];
}

// PDF-Strings sind meist WinAnsi (cp1252) oder UTF-16BE. In UTF-8 überführen.
function pdf_zeichen(string $roh): string {
    if ($roh === '') return '';
    if (strncmp($roh, "\xFE\xFF", 2) === 0) {
        $u = @mb_convert_encoding(substr($roh, 2), 'UTF-8', 'UTF-16BE');
        return $u === false ? '' : $u;
    }
    $u = @mb_convert_encoding($roh, 'UTF-8', 'Windows-1252');
    return $u === false ? $roh : $u;
}
