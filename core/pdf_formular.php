<?php
// Beschreibbare PDF-Formulare (AcroForm) aus PHP befüllen – ohne Zusatzprogramm (kein pdftk/qpdf auf dem Server).
// Arbeitet per inkrementellem Update: die Vorlage bleibt Byte für Byte erhalten, geänderte Feld-Objekte, neue
// Darstellungen (Appearance-Streams) und eine neue Querverweistabelle werden hinten angehängt. Jeder PDF-Leser
// nimmt die letzte Fassung eines Objekts.
// Voraussetzung an die Vorlage: klassische xref-Tabelle, keine Objekt-Streams, nicht verschlüsselt (so wie das
// GBA-Formular, PDF 1.3). Andere Vorlagen meldet pdf_formular_pruefen() als nicht geeignet.
//
// $werte: Feldname (UTF-8, wie im PDF, z. B. 'Text5', 'Kontrollkästchen1') => string (Text) | bool (Kontrollkästchen).
// Textfelder bekommen /V UND eine eigene Darstellung (Helvetica, mehrzeilig umbrochen, Schrift passt sich an),
// damit der Ausdruck in jedem Viewer stimmt; dazu /NeedAppearances true als Rückfall.

function pdf_formular_pruefen(string $pdf): ?string {
    if (strncmp($pdf, '%PDF-', 5) !== 0) return 'Keine PDF-Datei.';
    if (strpos($pdf, '/Encrypt') !== false) return 'Die Vorlage ist verschlüsselt.';
    if (strpos($pdf, '/ObjStm') !== false) return 'Die Vorlage nutzt komprimierte Objekt-Streams (nicht unterstützt).';
    if (strpos($pdf, '/AcroForm') === false) return 'Die Vorlage hat keine Formularfelder.';
    if (!preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $pdf)) return 'Querverweistabelle nicht gefunden.';
    return null;
}

// Letzte Fassung eines Objekts (Inhalt zwischen "n 0 obj" und "endobj").
function _pf_obj(string $pdf, int $n): ?string {
    if (!preg_match_all('/(?<![0-9])' . $n . ' 0 obj(.*?)endobj/s', $pdf, $m)) return null;
    return end($m[1]);
}
// Wert eines Schlüssels im Dictionary entfernen (Name, Zahl, Referenz, String oder verschachteltes << >> / [ ]).
function _pf_key_weg(string $dict, string $key): string {
    $pos = 0;
    while (($i = strpos($dict, '/' . $key, $pos)) !== false) {
        $j = $i + strlen($key) + 1;
        if ($j < strlen($dict) && preg_match('/[A-Za-z0-9_]/', $dict[$j])) { $pos = $j; continue; }   // nur ganzer Schlüssel
        while ($j < strlen($dict) && ctype_space($dict[$j])) $j++;
        $c = $dict[$j] ?? '';
        if (substr($dict, $j, 2) === '<<' || $c === '[') {
            $open = $c === '[' ? '[' : '<<'; $close = $c === '[' ? ']' : '>>'; $tiefe = 0; $k = $j;
            while ($k < strlen($dict)) {
                if (substr($dict, $k, strlen($open)) === $open) { $tiefe++; $k += strlen($open); continue; }
                if (substr($dict, $k, strlen($close)) === $close) { $tiefe--; $k += strlen($close); if ($tiefe === 0) break; continue; }
                $k++;
            }
            $ende = $k;
        } elseif ($c === '(') {
            $k = $j + 1; $tiefe = 1;
            while ($k < strlen($dict) && $tiefe > 0) { if ($dict[$k] === '\\') { $k += 2; continue; } if ($dict[$k] === '(') $tiefe++; if ($dict[$k] === ')') $tiefe--; $k++; }
            $ende = $k;
        } elseif ($c === '<') {
            $ende = strpos($dict, '>', $j) + 1;
        } elseif (preg_match('/\G\d+\s+\d+\s+R/', $dict, $mm, 0, $j)) {
            $ende = $j + strlen($mm[0]);
        } else {
            preg_match('/\G\/?[^\s\/<>\[\]()]*/', $dict, $mm, 0, $j); $ende = $j + strlen($mm[0]);
        }
        $dict = substr($dict, 0, $i) . substr($dict, $ende);
        $pos = $i;
    }
    return $dict;
}
// Neue Einträge vor das schließende >> des äußersten Dictionarys setzen.
function _pf_dict_dazu(string $obj, string $eintraege): string {
    $p = strrpos($obj, '>>');
    return substr($obj, 0, $p) . $eintraege . substr($obj, $p);
}
function _pf_utf16(string $s): string { return '<FEFF' . strtoupper(bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8'))) . '>'; }
function _pf_ansi(string $s): string {
    $a = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
    if ($a === false) $a = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    return strtr($a, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => '']);
}
// Helvetica-Breiten (1/1000 em) für CP1252 – für Umbruch und Schriftgröße.
function _pf_breite(string $ansi, float $size): float {
    static $w = null;
    if ($w === null) {
        $w = array_fill(0, 256, 556);
        $ascii = [32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
            48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,64=>1015,
            65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,80=>667,81=>778,
            82=>722,83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,92=>278,93=>278,94=>469,95=>556,96=>333,
            97=>556,98=>556,99=>500,100=>556,101=>556,102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,111=>556,
            112=>556,113=>556,114=>333,115=>500,116=>278,117=>556,118=>500,119=>722,120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584,
            196=>667,214=>778,220=>722,223=>611,228=>556,246=>556,252=>556,233=>556,232=>556,224=>556,150=>556,151=>1000,183=>278,176=>400];
        foreach ($ascii as $k => $v) $w[$k] = $v;
    }
    $sum = 0; $l = strlen($ansi);
    for ($i = 0; $i < $l; $i++) $sum += $w[ord($ansi[$i])];
    return $sum * $size / 1000;
}
// Text in Zeilen umbrechen (UTF-8 rein, CP1252-Zeilen raus, noch nicht escaped).
function _pf_zeilen(string $text, float $breite, float $size): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $absatz) {
        $wort = preg_split('/\s+/', trim($absatz)); $zeile = '';
        foreach ($wort as $w) {
            $test = $zeile === '' ? $w : $zeile . ' ' . $w;
            $ta = @iconv('UTF-8', 'CP1252//TRANSLIT', $test) ?: $test;
            if ($zeile !== '' && _pf_breite($ta, $size) > $breite) { $out[] = $zeile; $zeile = $w; } else $zeile = $test;
        }
        $out[] = $zeile;
    }
    return $out;
}
// Darstellung eines Textfelds: Rect -> BBox, Text links oben (mehrzeilig) bzw. vertikal mittig (einzeilig).
function _pf_ap_text(string $text, array $rect, bool $mehrzeilig, int $fontObj): string {
    $w = abs($rect[2] - $rect[0]); $h = abs($rect[3] - $rect[1]); $pad = 2.0;
    $size = $mehrzeilig ? 9.0 : min(10.0, max(6.0, $h - 6));
    if ($mehrzeilig) {
        // so groß wie möglich (max. 10 pt), aber alle Zeilen müssen in die Höhe passen
        for ($s = 10.0; $s >= 5.5; $s -= 0.5) { $z = _pf_zeilen($text, $w - 2 * $pad, $s); if (count($z) * $s * 1.15 <= $h - 2 * $pad) { $size = $s; break; } $size = $s; }
        $zeilen = _pf_zeilen($text, $w - 2 * $pad, $size);
    } else {
        $t1 = str_replace(["\r\n", "\n", "\r"], ' ', $text);
        while ($size > 5.5 && _pf_breite(@iconv('UTF-8', 'CP1252//TRANSLIT', $t1) ?: $t1, $size) > $w - 2 * $pad) $size -= 0.5;
        $zeilen = [$t1];
    }
    $lh = $size * 1.15;
    $ops = "/Tx BMC\nq\n1 1 " . sprintf('%.2F %.2F', $w - 2, $h - 2) . " re W n\nBT\n/HelvA " . sprintf('%.1F', $size) . " Tf 0 g\n";
    $y = $mehrzeilig ? $h - $pad - $size : ($h - $size) / 2 + $size * 0.22;
    $first = true;
    foreach ($zeilen as $z) {
        $ops .= $first ? sprintf('%.2F %.2F Td', $pad, $y) : sprintf('0 %.2F Td', -$lh);
        $ops .= ' (' . _pf_ansi($z) . ") Tj\n"; $first = false;
    }
    $ops .= "ET\nQ\nEMC";
    return '<</Type/XObject/Subtype/Form/BBox[0 0 ' . sprintf('%.2F %.2F', $w, $h) . ']/Resources<</Font<</HelvA ' . $fontObj . ' 0 R>>>>/Length '
        . strlen($ops) . ">>\nstream\n" . $ops . "\nendstream";
}

function pdf_formular_fuellen(string $pdf, array $werte): string {
    if (($f = pdf_formular_pruefen($pdf)) !== null) throw new RuntimeException($f);
    preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', $pdf, $m); $prevXref = (int)$m[1];
    if (!preg_match('/\/Root\s+(\d+)\s+0\s+R/', $pdf, $mr)) throw new RuntimeException('Root fehlt.');
    $root = (int)$mr[1];
    preg_match_all('/\/Size\s+(\d+)/', $pdf, $ms); $size = max(array_map('intval', $ms[1]));
    preg_match_all('/(?<![0-9])(\d+) 0 obj/', $pdf, $mo); $size = max($size, max(array_map('intval', $mo[1])) + 1);
    $info = preg_match('/\/Info\s+(\d+)\s+0\s+R/', $pdf, $mi) ? (int)$mi[1] : 0;
    $idArr = preg_match('/\/ID\s*\[\s*(<[0-9A-Fa-f]+>)\s*(<[0-9A-Fa-f]+>)\s*\]/', $pdf, $mid) ? '[' . $mid[1] . $mid[2] . ']' : '';

    $rootObj = _pf_obj($pdf, $root);
    if (!preg_match('/\/AcroForm\s+(\d+)\s+0\s+R/', $rootObj, $ma)) throw new RuntimeException('AcroForm ist kein Verweis (nicht unterstützt).');
    $afNr = (int)$ma[1]; $af = _pf_obj($pdf, $afNr);
    preg_match('/\/Fields\s*\[([^\]]*)\]/', $af, $mf);
    // Feldname (PDFDocEncoding/Latin-1 im PDF) -> [Feld-Objekt, Widget-Objekte]
    $felder = [];
    $sammeln = function (int $n, string $prefix) use (&$sammeln, &$felder, $pdf) {
        $o = _pf_obj($pdf, $n); if ($o === null) return;
        $t = preg_match('/\/T\s*\(((?:\\\\.|[^\\\\)])*)\)/s', $o, $mt) ? $mt[1] : null;
        $name = $t !== null ? ($prefix !== '' ? $prefix . '.' : '') . mb_convert_encoding(stripcslashes($t), 'UTF-8', 'ISO-8859-1') : $prefix;
        if (preg_match('/\/Kids\s*\[([^\]]*)\]/', $o, $mk)) {
            $kids = array_map('intval', preg_match_all('/(\d+)\s+0\s+R/', $mk[1], $kk) ? $kk[1] : []);
            $nurWidgets = true;
            foreach ($kids as $k) if (preg_match('/\/T\s*\(/', (string)_pf_obj($pdf, $k))) $nurWidgets = false;
            if ($nurWidgets) { $felder[$name] = ['feld' => $n, 'widgets' => $kids, 'dict' => $o]; return; }
            foreach ($kids as $k) $sammeln($k, $name);
            return;
        }
        $felder[$name] = ['feld' => $n, 'widgets' => [$n], 'dict' => $o];
    };
    foreach (array_map('intval', preg_match_all('/(\d+)\s+0\s+R/', $mf[1], $ff) ? $ff[1] : []) as $n) $sammeln($n, '');

    $neu = [];   // Objektnummer => Inhalt (ohne "n 0 obj"/"endobj")
    $fontObj = $size++;
    $neu[$fontObj] = '<</Type/Font/Subtype/Type1/BaseFont/Helvetica/Encoding/WinAnsiEncoding>>';
    foreach ($werte as $name => $wert) {
        if (!isset($felder[$name])) continue;
        $fd = $felder[$name];
        $istBtn = (bool) preg_match('/\/FT\s*\/Btn/', $fd['dict']);
        if ($istBtn) {
            $an = (bool)$wert;
            foreach ($fd['widgets'] as $wn) {
                $wd = $neu[$wn] ?? _pf_obj($pdf, $wn);
                $onName = preg_match('/\/N\s*<<\s*\/(\w+)\s+\d+\s+0\s+R/', $wd, $mn) && $mn[1] !== 'Off' ? $mn[1]
                        : (preg_match('/\/N\s*<<.*?\/(?!Off)(\w+)\s+\d+\s+0\s+R/s', $wd, $mn2) ? $mn2[1] : 'Ja');
                $wd = _pf_dict_dazu(_pf_key_weg(_pf_key_weg($wd, 'AS'), 'V'), '/AS/' . ($an ? $onName : 'Off') . '/V/' . ($an ? $onName : 'Off'));
                $neu[$wn] = $wd;
            }
            if (!in_array($fd['feld'], $fd['widgets'], true)) {
                $neu[$fd['feld']] = _pf_dict_dazu(_pf_key_weg($neu[$fd['feld']] ?? $fd['dict'], 'V'), '/V/' . ($an ? 'Ja' : 'Off'));
            }
            continue;
        }
        $text = (string)$wert;
        $ffl = preg_match('/\/Ff\s+(\d+)/', $fd['dict'], $mff) ? (int)$mff[1] : 0;
        $mehr = ($ffl & 4096) === 4096;
        $fdict = _pf_dict_dazu(_pf_key_weg($neu[$fd['feld']] ?? $fd['dict'], 'V'), '/V' . _pf_utf16($text));
        $neu[$fd['feld']] = $fdict;
        foreach ($fd['widgets'] as $wn) {
            $wd = $neu[$wn] ?? _pf_obj($pdf, $wn);
            if (!preg_match('/\/Rect\s*\[\s*([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s*\]/', $wd, $mrc)) continue;
            $apNr = $size++;
            $neu[$apNr] = _pf_ap_text($text, [(float)$mrc[1], (float)$mrc[2], (float)$mrc[3], (float)$mrc[4]], $mehr, $fontObj);
            $neu[$wn] = _pf_dict_dazu(_pf_key_weg($wd, 'AP'), '/AP<</N ' . $apNr . ' 0 R>>');
        }
    }
    // Rückfall: Viewer dürfen Darstellungen selbst neu erzeugen.
    $neu[$afNr] = _pf_dict_dazu(_pf_key_weg($af, 'NeedAppearances'), '/NeedAppearances true');

    $out = $pdf; if (substr($out, -1) !== "\n") $out .= "\n";
    $offs = [];
    ksort($neu);
    foreach ($neu as $n => $body) { $offs[$n] = strlen($out); $out .= $n . " 0 obj\n" . trim($body) . "\nendobj\n"; }
    $xref = strlen($out);
    $out .= "xref\n";
    // Abschnitte aus zusammenhängenden Nummern
    $nums = array_keys($offs); $i = 0;
    while ($i < count($nums)) {
        $start = $nums[$i]; $j = $i;
        while ($j + 1 < count($nums) && $nums[$j + 1] === $nums[$j] + 1) $j++;
        $out .= $start . ' ' . ($j - $i + 1) . "\n";
        for ($k = $i; $k <= $j; $k++) $out .= sprintf("%010d 00000 n \n", $offs[$nums[$k]]);
        $i = $j + 1;
    }
    $out .= 'trailer' . "\n" . '<</Size ' . $size . '/Root ' . $root . ' 0 R' . ($info ? '/Info ' . $info . ' 0 R' : '') . ($idArr !== '' ? '/ID' . $idArr : '')
          . '/Prev ' . $prevXref . ">>\nstartxref\n" . $xref . "\n%%EOF\n";
    return $out;
}

// Alle Feldnamen einer Vorlage (zum Prüfen einer Zuordnung).
function pdf_formular_felder(string $pdf): array {
    $out = [];
    if (preg_match_all('/\/T\s*\(((?:\\\\.|[^\\\\)])*)\)/s', $pdf, $m))
        foreach ($m[1] as $t) $out[] = mb_convert_encoding(stripcslashes($t), 'UTF-8', 'ISO-8859-1');
    return array_values(array_unique($out));
}
