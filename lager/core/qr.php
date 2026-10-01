<?php
// Abhaengigkeitsfreier QR-Encoder (Byte-Modus, Fehlerkorrektur-Level M, Versionen 1–6).
// Keine GD, keine externen Bibliotheken: liefert eine Modul-Matrix (0/1), die der PDF-Generator
// als schwarze Rechtecke zeichnet. Reicht locker fuer URLs/Chargen-Links (bis 106 Bytes).
//
// qr_matrix("https://…"): array von Zeilen (je Zeile array aus 0/1) oder null, wenn zu lang.

// --- GF(256) Tabellen (primitiv 0x11d) ---
function qr_gf(): array {
    static $t = null;
    if ($t !== null) return $t;
    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) { $exp[$i] = $x; $log[$x] = $i; $x <<= 1; if ($x & 0x100) $x ^= 0x11d; }
    for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    return $t = ['exp' => $exp, 'log' => $log];
}
function qr_mul(int $a, int $b): int {
    if ($a === 0 || $b === 0) return 0;
    $g = qr_gf();
    return $g['exp'][($g['log'][$a] + $g['log'][$b]) % 255];
}
// Generatorpolynom fuer n EC-Codewoerter.
function qr_gen_poly(int $n): array {
    $g = [1];
    for ($i = 0; $i < $n; $i++) {
        $alpha = qr_gf()['exp'][$i];
        $ng = array_fill(0, count($g) + 1, 0);
        foreach ($g as $j => $c) {
            $ng[$j]     ^= $c;                       // * x
            $ng[$j + 1] ^= qr_mul($c, $alpha);       // * alpha^i
        }
        $g = $ng;
    }
    return $g;
}
// Reed-Solomon: n EC-Codewoerter fuer $data (Array von Bytes).
function qr_rs(array $data, int $n): array {
    $gen = qr_gen_poly($n);                 // Laenge n+1, fuehrend 1
    $res = array_merge($data, array_fill(0, $n, 0));
    $k = count($data);
    for ($i = 0; $i < $k; $i++) {
        $factor = $res[$i];
        if ($factor === 0) continue;
        for ($j = 0; $j <= $n; $j++) $res[$i + $j] ^= qr_mul($gen[$j], $factor);
    }
    return array_slice($res, $k, $n);
}

// Level-M-Parameter je Version: [gesamt-Daten-Codewoerter, EC je Block, Blockzahl].
function qr_params(): array {
    return [
        1 => [16, 10, 1],
        2 => [28, 16, 1],
        3 => [44, 26, 1],
        4 => [64, 18, 2],
        5 => [86, 24, 2],
        6 => [108, 16, 4],
    ];
}

function qr_bit(int $byte, int $i): int { return ($byte >> $i) & 1; }

// Hauptfunktion: Text -> Modul-Matrix (0/1) oder null.
function qr_matrix(string $text): ?array {
    $bytes = array_values(unpack('C*', $text) ?: []);
    $len = count($bytes);

    // Kleinste passende Version (Level M) waehlen.
    $version = 0; $dcw = 0; $ecpb = 0; $blocks = 0;
    foreach (qr_params() as $v => $p) {
        if ($len <= $p[0] - 2) { $version = $v; [$dcw, $ecpb, $blocks] = $p; break; }
    }
    if (!$version) return null;   // zu lang fuer v1–6

    // --- Bitstrom: Modus (0100) + 8-Bit-Laenge + Daten ---
    $bitstr = '0100' . str_pad(decbin($len), 8, '0', STR_PAD_LEFT);
    foreach ($bytes as $b) $bitstr .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
    // Terminator (max 4 Null-Bits), dann auf Byte auffuellen.
    $cap = $dcw * 8;
    $bitstr .= str_repeat('0', min(4, $cap - strlen($bitstr)));
    if (strlen($bitstr) % 8) $bitstr .= str_repeat('0', 8 - (strlen($bitstr) % 8));
    // Padbytes 0xEC / 0x11.
    $pad = [0xEC, 0x11]; $pi = 0;
    while (strlen($bitstr) < $cap) { $bitstr .= str_pad(decbin($pad[$pi % 2]), 8, '0', STR_PAD_LEFT); $pi++; }
    // In Daten-Codewoerter.
    $dataCW = [];
    for ($i = 0; $i < $cap; $i += 8) $dataCW[] = bindec(substr($bitstr, $i, 8));

    // --- In Bloecke teilen (v1–6: alle Bloecke gleich gross), EC je Block, interleaven ---
    $perBlock = intdiv($dcw, $blocks);
    $dBlocks = []; $eBlocks = [];
    for ($b = 0; $b < $blocks; $b++) {
        $db = array_slice($dataCW, $b * $perBlock, $perBlock);
        $dBlocks[] = $db;
        $eBlocks[] = qr_rs($db, $ecpb);
    }
    $final = [];
    for ($i = 0; $i < $perBlock; $i++) foreach ($dBlocks as $db) $final[] = $db[$i];
    for ($i = 0; $i < $ecpb; $i++)     foreach ($eBlocks as $eb) $final[] = $eb[$i];
    // Bitfolge der finalen Codewoerter (MSB zuerst).
    $databits = '';
    foreach ($final as $cw) $databits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);

    // --- Matrix aufbauen ---
    $size = $version * 4 + 17;
    $m = []; $fn = [];
    for ($y = 0; $y < $size; $y++) { $m[$y] = array_fill(0, $size, 0); $fn[$y] = array_fill(0, $size, false); }
    $set = function (int $x, int $y, int $v) use (&$m, &$fn, $size) {
        if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) return;
        $m[$y][$x] = $v; $fn[$y][$x] = true;
    };

    // Finder + Separatoren (9x9-Bereich je Ecke).
    $finder = function (int $cx, int $cy) use ($set) {
        for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
            $d = max(abs($dx), abs($dy));
            $set($cx + $dx, $cy + $dy, ($d !== 2 && $d !== 4) ? 1 : 0);
        }
    };
    $finder(3, 3); $finder($size - 4, 3); $finder(3, $size - 4);

    // Timing-Linien.
    for ($i = 0; $i < $size; $i++) {
        if (!$fn[6][$i]) $set($i, 6, $i % 2 === 0 ? 1 : 0);
        if (!$fn[$i][6]) $set(6, $i, $i % 2 === 0 ? 1 : 0);
    }

    // Alignment (nur eine je Version v2–6, Mitte bei size-7).
    if ($version >= 2) {
        $c = $size - 7;
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) {
            $set($c + $dx, $c + $dy, max(abs($dx), abs($dy)) !== 1 ? 1 : 0);
        }
    }

    // Dunkelmodul.
    $set(8, $size - 8, 1);

    // Formatbereiche reservieren (Werte spaeter), damit die Datenplatzierung sie ueberspringt.
    for ($i = 0; $i < 9; $i++) { if (!$fn[8][$i]) $set($i, 8, 0); if (!$fn[$i][8]) $set(8, $i, 0); }
    for ($i = 0; $i < 8; $i++) { if (!$fn[8][$size - 1 - $i]) $set($size - 1 - $i, 8, 0); if (!$fn[$size - 8 + $i][8]) $set(8, $size - 8 + $i, 0); }

    // --- Daten im Zickzack platzieren ---
    $bi = 0; $n = strlen($databits);
    for ($right = $size - 1; $right > 0; $right -= 2) {
        if ($right === 6) $right = 5;                       // Timing-Spalte ueberspringen
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $upward = ((($right + 1) & 2) === 0);
                $y = $upward ? ($size - 1 - $vert) : $vert;
                if ($fn[$y][$x]) continue;
                $m[$y][$x] = ($bi < $n) ? (int)$databits[$bi] : 0;
                $bi++;
            }
        }
    }

    // --- Maske waehlen (beste von 8 per Strafpunkten) ---
    $cond = function (int $mask, int $x, int $y): bool {
        switch ($mask) {
            case 0: return ($x + $y) % 2 === 0;
            case 1: return $y % 2 === 0;
            case 2: return $x % 3 === 0;
            case 3: return ($x + $y) % 3 === 0;
            case 4: return (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0;
            case 5: return (($x * $y) % 2) + (($x * $y) % 3) === 0;
            case 6: return ((($x * $y) % 2) + (($x * $y) % 3)) % 2 === 0;
            default: return ((($x + $y) % 2) + (($x * $y) % 3)) % 2 === 0;
        }
    };
    $best = null; $bestMask = 0; $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $t = $m;
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++)
            if (!$fn[$y][$x] && $cond($mask, $x, $y)) $t[$y][$x] ^= 1;
        $sc = qr_penalty($t, $size);
        if ($sc < $bestScore) { $bestScore = $sc; $bestMask = $mask; $best = $t; }
    }
    $m = $best;

    // --- Formatinfo (Level M = 00) + Maske, BCH(15,5), XOR 0x5412 ---
    $data = (0 << 3) | $bestMask;    // M=00
    $rem = $data;
    for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ ((($rem >> 9) & 1) * 0x537);
    $bits = (($data << 10) | $rem) ^ 0x5412;   // 15 Bit
    $gb = fn($i) => ($bits >> $i) & 1;
    // Erste Kopie.
    for ($i = 0; $i <= 5; $i++) $m[$i][8] = $gb($i);
    $m[7][8] = $gb(6); $m[8][8] = $gb(7); $m[8][7] = $gb(8);
    for ($i = 9; $i < 15; $i++) $m[8][14 - $i] = $gb($i);
    // Zweite Kopie.
    for ($i = 0; $i < 8; $i++) $m[8][$size - 1 - $i] = $gb($i);
    for ($i = 8; $i < 15; $i++) $m[$size - 15 + $i][8] = $gb($i);
    $m[$size - 8][8] = 1;   // Dunkelmodul bleibt dunkel

    return $m;
}

// Strafpunkte einer Matrix (4 Regeln der QR-Norm).
function qr_penalty(array $m, int $size): int {
    $score = 0;
    // Regel 1: Serien gleicher Farbe (>=5) in Zeilen und Spalten.
    for ($y = 0; $y < $size; $y++) {
        $runC = 1; $runR = 1;
        for ($x = 1; $x < $size; $x++) {
            if ($m[$y][$x] === $m[$y][$x - 1]) { $runC++; } else { if ($runC >= 5) $score += 3 + ($runC - 5); $runC = 1; }
            if ($m[$x][$y] === $m[$x - 1][$y]) { $runR++; } else { if ($runR >= 5) $score += 3 + ($runR - 5); $runR = 1; }
        }
        if ($runC >= 5) $score += 3 + ($runC - 5);
        if ($runR >= 5) $score += 3 + ($runR - 5);
    }
    // Regel 2: 2x2-Bloecke gleicher Farbe.
    for ($y = 0; $y < $size - 1; $y++) for ($x = 0; $x < $size - 1; $x++) {
        $v = $m[$y][$x];
        if ($v === $m[$y][$x + 1] && $v === $m[$y + 1][$x] && $v === $m[$y + 1][$x + 1]) $score += 3;
    }
    // Regel 3: Finder-aehnliches Muster 1:1:3:1:1 (+ 4 hell) in Zeilen/Spalten.
    $pat1 = [1,0,1,1,1,0,1,0,0,0,0];
    $pat2 = [0,0,0,0,1,0,1,1,1,0,1];
    for ($y = 0; $y < $size; $y++) for ($x = 0; $x <= $size - 11; $x++) {
        $okR = true; $okC = true;
        for ($k = 0; $k < 11; $k++) {
            if ($m[$y][$x + $k] !== $pat1[$k] && $m[$y][$x + $k] !== $pat2[$k]) { /* beide pruefen einzeln unten */ }
        }
        // einzeln pruefen (Zeile)
        $r1 = true; $r2 = true;
        for ($k = 0; $k < 11; $k++) { if ($m[$y][$x + $k] !== $pat1[$k]) $r1 = false; if ($m[$y][$x + $k] !== $pat2[$k]) $r2 = false; }
        if ($r1 || $r2) $score += 40;
        // Spalte
        $c1 = true; $c2 = true;
        for ($k = 0; $k < 11; $k++) { if ($m[$x + $k][$y] !== $pat1[$k]) $c1 = false; if ($m[$x + $k][$y] !== $pat2[$k]) $c2 = false; }
        if ($c1 || $c2) $score += 40;
    }
    // Regel 4: Abweichung vom 50:50-Verhaeltnis dunkel/hell.
    $dark = 0;
    for ($y = 0; $y < $size; $y++) $dark += array_sum($m[$y]);
    $total = $size * $size;
    $percent = $dark * 100 / $total;
    $k = (int)(abs($percent - 50) / 5);
    $score += $k * 10;
    return $score;
}
