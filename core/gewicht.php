<?php
// Gewichte lesen (Nachbesserung Fable-Prüfung 3, 13.11.2026) – EINE Regel für alle Gewichts- und Wiegefelder
// (Dashboard, Lager-App, Produktions-App/Werk-App, Lieferantenportal). Ohne Abhängigkeiten, damit die Unterprogramme
// sie direkt per require_once laden können (nie core/schema.php).
//
// Regel: Bei nur einer Art Trennzeichen sind Punkt und Komma immer Dezimaltrenner
//        („6.482“ g = 6,482; „25.125“ kg = 25,125; „25,000“ = 25; „1.5“ = 1,5).
//        Kommen Punkt und Komma vor, gilt das letzte als Dezimaltrenner („1.234,5“ = 1234,5; „1,234.5“ = 1234,5).
//        Kommt dasselbe Zeichen mehrfach vor („1.234.567“), sind es Tausenderpunkte.
// Stückzahlen lesen weiter erp_menge_parse() / pr_menge_parse() / la_zahl(…, stueck) / zahl_lesen(…, true).

if (!function_exists('gewicht_lesen')) {
    function gewicht_lesen($roh): float {
        $roh = (string) $roh;
        if (function_exists('mb_convert_kana')) $roh = mb_convert_kana($roh, 'a', 'UTF-8');   // Vollbreiten-Ziffern (ZH/JA)
        $roh = str_replace(['。', '、', '，', '．', '－'], ['.', ',', ',', '.', '-'], $roh);
        $roh = str_replace(["\xc2\xa0", "\xe2\x80\xaf", ' ', "'", '_'], '', $roh);
        $s = preg_match('/-?(?:\d[\d.,]*|(?<!\p{L})[.,]\d+)/u', $roh, $m) ? rtrim($m[0], '.,') : '';
        if ($s === '' || $s === '-') return 0.0;
        $p = strrpos($s, '.'); $k = strrpos($s, ',');
        if ($p !== false && $k !== false) {
            $dez = $p > $k ? '.' : ',';
            $tsd = $dez === '.' ? ',' : '.';
            $s = str_replace($tsd, '', $s);
            if ($dez === ',') $s = str_replace(',', '.', $s);
        } elseif ($p !== false || $k !== false) {
            $z = $p !== false ? '.' : ',';
            if (substr_count($s, $z) > 1) $s = str_replace($z, '', $s);   // 1.234.567 = Tausender
            else $s = str_replace(',', '.', $s);
        }
        return (float) $s;
    }
}

// Ist die Einheit ein Gewicht/Volumen (dann gewicht_lesen) oder eine Stückzahl (dann Mengen-Lesart mit Tausenderpunkt)?
if (!function_exists('einheit_ist_gewicht')) {
    function einheit_ist_gewicht(string $einheit): bool {
        return in_array(mb_strtolower(trim($einheit)), ['kg', 'g', 'mg', 'µg', 'mcg', 't', 'l', 'ml'], true);
    }
}

// Gewicht für ein Eingabefeld vorbelegen: deutsch mit Komma, OHNE Tausenderpunkt (sonst läse gewicht_lesen „1.234“ als 1,234).
if (!function_exists('gewicht_feld')) {
    function gewicht_feld($wert, int $stellen = 3): string {
        if ($wert === null || $wert === '') return '';
        $t = number_format((float) $wert, $stellen, ',', '');
        if (strpos($t, ',') !== false) $t = rtrim(rtrim($t, '0'), ',');
        return $t;
    }
}

// Tara eines Gebindetyps (lg_tara) über die ID (13.11.2026): Formulare senden nur die ID, der Wert kommt immer aus der Datenbank –
// nie als Zahl aus dem Formular (früher las der Mengen-Parser „1.100“ als 1100 kg). Rückgabe ['id','name','kg'] oder null.
if (!function_exists('tara_aus_id')) {
    function tara_aus_id(int $id): ?array {
        if ($id <= 0 || !function_exists('one')) return null;
        try { $r = one("SELECT id, name, tara_kg FROM lg_tara WHERE id=?", [$id]); } catch (\Throwable $e) { return null; }
        return $r ? ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'kg' => (float)$r['tara_kg']] : null;
    }
}

// Mehrdeutiges Gewicht? (Fable-4 T1.3, 13.11.2026) „1.250“ (1-3 Ziffern, Punkt, genau 3 Ziffern, keine Einheit) kann 1,25 oder 1.250 sein.
// Gewichtsfelder tragen data-gewicht="kg|g"; public/assets/bx-gewicht.js fragt dann vor dem Absenden nach (gleiches Muster).
if (!function_exists('gewicht_mehrdeutig')) {
    function gewicht_mehrdeutig($roh): bool { return (bool) preg_match('/^\s*-?\d{1,3}\.\d{3}\s*$/', (string) $roh); }
}

// Menge in der Einheit eines Artikels lesen (Fable-4 T1.3, 13.11.2026): kg/g/l… = Gewicht (gewicht_lesen), sonst Stückzahl mit
// Tausenderpunkt („150.000“ Stk = 150000). Braucht zahl_lesen() (core/zahl_lesen.php) für die Stückzahl.
if (!function_exists('menge_in_einheit_lesen')) {
    function menge_in_einheit_lesen(string $roh, string $einheit): float {
        if (einheit_ist_gewicht($einheit)) return gewicht_lesen($roh);
        if (!function_exists('zahl_lesen')) require_once __DIR__ . '/zahl_lesen.php';
        return zahl_lesen($roh, true);
    }
}

// Zahl für ein Eingabefeld vorbelegen (Fable-4 T2.4, 13.11.2026): deutsch mit Komma, OHNE Tausenderpunkt – liest jeder Parser
// richtig (zahl_lesen in Preis- und Mengen-Lesart, gewicht_lesen, erp_menge_parse). Gleiche Regel wie gewicht_feld().
if (!function_exists('zahl_feld')) {
    function zahl_feld($wert, int $stellen = 3): string { return gewicht_feld($wert, $stellen); }
}
