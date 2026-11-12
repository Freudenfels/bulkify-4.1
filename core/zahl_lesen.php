<?php
// Zahlen lesen (Fable-3 T2.9, 13.11.2026) – EINE Datei ohne Abhängigkeiten für Dashboard, CRM und Buchhaltung.
// - zahl_lesen($roh, $mengenfeld, $sprache): Preis-Lesart (Standard) bzw. Mengen-Lesart ($mengenfeld=true, „12.000“ = 12000).
// - betrag_lesen($roh): Geldbeträge (Summen, Zahlungen, Netto/Brutto) – „21.687,75“ = „21687,75“ = „21687.75“ = 21687,75; „1.500“ = 1500.
// - Gewichte: gewicht_lesen() in core/gewicht.php.

if (!function_exists('zahl_lesen')) {
    function zahl_lesen(string $roh, bool $mengenfeld = false, string $sprache = 'de'): float {
        // Chinesische/japanische Eingabe liefert oft Vollbreiten-Zeichen („０．０５“) oder „。“ als Punkt (Nico 11.11.2026:
        // Lieferantenpreise kamen als 0 an). Vorher in normale Ziffern umwandeln, dann Währungszeichen/Einheiten
        // wie „€“, „¥“, „/粒“ entfernen – übrig bleiben nur Ziffern, Punkt, Komma und Minus.
        if (function_exists('mb_convert_kana')) $roh = mb_convert_kana($roh, 'a', 'UTF-8');
        $roh = str_replace(['。', '、', '，', '．', '－'], ['.', ',', ',', '.', '-'], $roh);
        $roh = str_replace(["\xc2\xa0", ' ', "'", '_'], '', $roh);
        $s = preg_match('/-?(?:\d[\d.,]*|(?<!\p{L})[.,]\d+)/u', $roh, $zm) ? $zm[0] : '';
        if ($s === '') return 0.0;
        $punkt = strpos($s, '.') !== false;
        $komma = strpos($s, ',') !== false;
        // Welches Zeichen trennt in dieser Sprache die Tausender? Deutsch der Punkt, sonst das Komma.
        $tausender = $sprache === 'de' ? '.' : ',';
        // Eine Tausendergruppe beginnt nie mit einer Null: „0.045" ist ein Wert, keine 45.
        $muster = '/^-?[1-9]\d{0,2}(' . preg_quote($tausender, '/') . '\d{3})+$/';
        if ($punkt && $komma) {
            $dez = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $s = str_replace($dez === ',' ? '.' : ',', '', $s);
            $s = str_replace($dez, '.', $s);
        } elseif ($mengenfeld && preg_match($muster, $s)) {
            $s = str_replace($tausender, '', $s);
        } elseif ($komma) {
            $s = str_replace(',', '.', $s);
        }
        return (float) $s;
    }

}

// Geldbetrag lesen: Tausenderpunkt wie bei Mengen, Komma (bzw. das letzte Trennzeichen) = Dezimal. Leer = 0.
if (!function_exists('betrag_lesen')) {
    function betrag_lesen($roh, string $sprache = 'de'): float { return zahl_lesen((string)$roh, true, $sprache); }
}
// Wie betrag_lesen, aber leer/ohne Ziffer = null (optionale Felder).
if (!function_exists('betrag_lesen_null')) {
    function betrag_lesen_null($roh): ?float { $r = trim((string)$roh); return ($r === '' || !preg_match('/\d/', $r)) ? null : betrag_lesen($r); }
}
