<?php
require_once __DIR__ . '/kunde_charge.php';   // Grundcharge beim Kunden (13.11.2026)
// Der Kunde sieht nie, wer wirklich produziert (Paket 12.11.2026 Teil 3, Entscheidung Nico).
// Zentrale Schutz-Stelle für ALLES, was an den Kunden geht (Kundenportal, Downloads, Mails an Kunden):
//  - kunde_dok_gesperrt(): Unterlagen des Lieferanten/Herstellers gehen nie an den Kunden (Original-CoA/Spec, alles was
//    ein Lieferant hochgeladen hat oder einem Hersteller zugeordnet ist). Der Kunde bekommt das bulkify-Dokument.
//  - kunde_dateiname(): neutraler Dateiname (CoA_<Produkt>_<Charge>.pdf) statt des Original-Dateinamens.
//  - kunde_pdf_neutral(): Autor/Ersteller/Programm/Titel in PDF-Metadaten werden geleert (gleiche Länge, Datei bleibt gültig).
//  - kunde_text_neutral(): Firmennamen von Lieferanten in Freitext werden durch „bulkify“ ersetzt.
//  - kunde_datei_ausliefern(): eine Stelle für den Download (Header, neutraler Name, Metadaten leer).
// Regel im Handbuch (Abschnitt 1, Grundprinzip 10a). Neue Kundenseiten benutzen diese Funktionen. Siehe kunde_neutral.md.

// Ist der Lieferant ein reines Labor? Externe Laborberichte (Auftraggeber Kunde) dürfen an den Kunden.
function kunde_lieferant_ist_labor(int $lieferant_id): bool {
    static $c = [];
    if ($lieferant_id <= 0) return false;
    if (!isset($c[$lieferant_id])) {
        $k = (string) scalar("SELECT COALESCE(kategorien,'') FROM lieferanten WHERE id=?", [$lieferant_id]);
        $kat = array_filter(array_map('trim', explode(',', $k)));
        $c[$lieferant_id] = $kat && !array_diff($kat, ['labor']);   // nur „labor“, sonst Hersteller/Händler
    }
    return $c[$lieferant_id];
}

// Darf dieses Dokument NICHT an den Kunden? Zeile aus `dokument` (mindestens objekt_typ, typ, lieferant_id, hochgeladen_von).
function kunde_dok_gesperrt(array $d): bool {
    $ot = (string)($d['objekt_typ'] ?? ''); $typ = (string)($d['typ'] ?? '');
    if ($ot === 'item' && in_array($typ, ['spec', 'coa', 'analyse'], true)) return true;            // Original-Unterlagen am Rohstoff
    if (in_array($ot, ['lieferant', 'bestellung', 'nachricht', 'rezeptur', 'lieferant_anfrage'], true)) return true;   // Ablagen des Lieferanten
    if ((string)($d['hochgeladen_von'] ?? '') === 'lieferant') return true;                          // vom Lieferanten hochgeladen
    $lid = (int)($d['lieferant_id'] ?? 0);
    if ($lid > 0 && !kunde_lieferant_ist_labor($lid)) return true;                                   // einem Hersteller/Händler zugeordnet
    return false;
}
// SQL-Bedingung (für Listen an den Kunden), gleichwertig zu kunde_dok_gesperrt(). $a = Tabellen-Alias.
function kunde_dok_sql(string $a = 'd'): string {
    return "NOT ($a.objekt_typ='item' AND $a.typ IN ('spec','coa','analyse'))
            AND $a.objekt_typ NOT IN ('lieferant','bestellung','nachricht','rezeptur','lieferant_anfrage')
            AND COALESCE($a.hochgeladen_von,'') <> 'lieferant'
            AND ($a.lieferant_id IS NULL OR $a.lieferant_id=0 OR EXISTS (SELECT 1 FROM lieferanten kn_l WHERE kn_l.id=$a.lieferant_id AND TRIM(COALESCE(kn_l.kategorien,''))='labor'))";
}

function kunde_dok_typname(string $typ): string {
    return ['coa' => 'CoA', 'spec' => 'Spezifikation', 'beides' => 'Spezifikation', 'analyse' => 'Laboranalyse', 'zertifikat' => 'Zertifikat',
            'rechnung' => 'Rechnung', 'druckvorlage' => 'Druckvorlage', 'pib' => 'Produktinformation'][$typ] ?? 'Dokument';
}
function kunde_dateiname_teil(string $s): string {
    $s = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'])), '-.');
    return mb_substr($s, 0, 60);
}
// Neutraler Dateiname: <Typ>_<Produkt/Auftrag>_<Charge>.<ext> – nie der Original-Dateiname (kann den Lieferanten nennen).
function kunde_dateiname(array $d, string $bezug = ''): string {
    $ext = strtolower(pathinfo((string)($d['datei'] ?? $d['datei_orig'] ?? ''), PATHINFO_EXTENSION)) ?: 'pdf';
    if ($bezug === '') {
        $ot = (string)($d['objekt_typ'] ?? ''); $oid = (int)($d['objekt_id'] ?? 0);
        if ($ot === 'auftrag')      $bezug = (string) scalar("SELECT COALESCE(NULLIF(p.kundenname,''), p.name, a.nummer) FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$oid]);
        elseif ($ot === 'produkt')  $bezug = (string) scalar("SELECT COALESCE(NULLIF(kundenname,''), name) FROM produkt WHERE id=?", [$oid]);
        elseif ($ot === 'item')     $bezug = (string) scalar("SELECT name FROM item WHERE id=?", [$oid]);
    }
    $teile = array_filter([kunde_dok_typname((string)($d['typ'] ?? '')), kunde_dateiname_teil(kunde_text_neutral($bezug)), kunde_dateiname_teil(kunde_charge_anzeige((string)($d['charge_nr'] ?? '')))]);
    return implode('_', $teile) . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
}
// Anzeige-Titel für den Kunden (statt Original-Dateiname).
function kunde_dok_titel(array $d): string {
    $t = trim((string)($d['titel'] ?? ''));
    return $t !== '' ? kunde_text_neutral($t) : pathinfo(kunde_dateiname($d), PATHINFO_FILENAME);
}

// PDF-Metadaten leeren (Info-Wörterbuch und XMP), ohne Längen zu ändern – Querverweise bleiben gültig.
function kunde_pdf_neutral(string $pdf): string {
    if (strncmp($pdf, '%PDF', 4) !== 0) return $pdf;
    $leer = fn(array $m) => $m[1] . str_repeat(' ', strlen($m[2])) . $m[3];
    foreach (['Author', 'Creator', 'Producer', 'Title', 'Subject', 'Keywords', 'Company', 'SourceModified'] as $k) {
        $pdf = preg_replace_callback('#(/' . $k . '\s*\()((?:\\\\.|[^\\\\)])*)(\))#s', $leer, $pdf);
        $pdf = preg_replace_callback('#(/' . $k . '\s*<)([0-9A-Fa-f\s]*)(>)#s', fn($m) => $m[1] . preg_replace('/[0-9A-Fa-f]/', '0', preg_replace('/[0-9A-Fa-f]{2}/', '20', $m[2])) . $m[3], $pdf);
    }
    foreach (['dc:creator', 'dc:title', 'dc:description', 'dc:rights', 'dc:publisher', 'pdf:Producer', 'pdf:Author', 'xmp:CreatorTool', 'pdf:Keywords', 'photoshop:AuthorsPosition', 'xmpRights:Marked'] as $tag) {
        $q = preg_quote($tag, '#');
        $pdf = preg_replace_callback('#(<' . $q . '(?:\s[^>]*)?>)(.*?)(</' . $q . '>)#s', $leer, $pdf);
        $pdf = preg_replace_callback('#(\s' . $q . '=")([^"]*)(")#', $leer, $pdf);
    }
    return $pdf;
}

// Firmennamen der Lieferanten (ohne bulkify/Maniso, ohne Labore), lange zuerst, je Anfrage einmal.
// Tabelle/Spalte vorhanden? Dashboard-Helfer, sonst information_schema – damit auch das CRM den Filter laden kann (Fable-3 T3.5).
function kn_tabelle_da(string $t): bool {
    if (function_exists('table_exists')) return table_exists($t);
    static $c = []; if (isset($c[$t])) return $c[$t];
    try { return $c[$t] = (int) scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$t]) > 0; } catch (\Throwable $e) { return $c[$t] = false; }
}
function kn_spalte_da(string $t, string $col): bool {
    if (function_exists('column_exists')) return column_exists($t, $col);
    static $c = []; $k = $t . '.' . $col; if (isset($c[$k])) return $c[$k];
    try { return $c[$k] = (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", [$t, $col]) > 0; } catch (\Throwable $e) { return $c[$k] = false; }
}
// Paketdienste nennen wir dem Kunden offen (Versand per DHL) – nie ersetzen (Fable-3 T3.4). Fable-4 T4.1 (13.11.2026): nur ECHTE
// Paketdienste (DHL, UPS, DPD, GLS, Hermes, FedEx, TNT): der Name beginnt mit einem davon und enthält sonst nur Versand-Wörter
// („DHL Express Germany GmbH“, „Hermes Germany“) – „Hermes Arzneimittel GmbH“ ist kein Paketdienst und wird ersetzt.
function kunde_filter_paketdienst(string $name): bool {
    $n = mb_strtolower(trim((string) preg_replace(kunde_filter_rechtsform_muster(), '', trim($name))));
    if (!preg_match('/^(dhl|ups|dpd|gls|hermes|fedex|tnt)(?![\p{L}])/u', $n)) return false;
    $rest = preg_split('/[\s,.\-\/&]+/u', $n, -1, PREG_SPLIT_NO_EMPTY);
    array_shift($rest);
    $erlaubt = ['express', 'paket', 'pakete', 'parcel', 'parcels', 'germany', 'deutschland', 'de', 'logistics', 'logistik', 'international',
                'worldwide', 'service', 'services', 'supply', 'chain', 'freight', 'ecommerce', 'e', 'commerce', 'versand', 'shipping', 'austria', 'schweiz', 'co'];
    foreach ($rest as $w) if (!in_array($w, $erlaubt, true)) return false;
    return true;
}
function kunde_filter_rechtsform_muster(): string {
    return '/[\s,]+(gmbh(\s*&\s*co\.?\s*kg)?|ag|kg|ohg|ug|gbr|e\.?\s?k\.?|ltd\.?|limited|llc|inc\.?|co\.?,?\s*ltd\.?|s\.?r\.?l\.?|b\.?v\.?|sp\.?\s*z\s*o\.?\s*o\.?|s\.?a\.?|corp\.?|company)\.?$/iu';
}
// Allgemeine Wörter (Fable-4 T4.1): ein Lieferant, der nur so heißt, wird NICHT ersetzt (sonst stünde „bulkify“ statt „Natur“ im Text).
// Pflegbar in den Einstellungen (Reiter Firma, app_meta kunde_filter_woerter, Komma getrennt).
function kunde_filter_woerter_standard(): string {
    return 'Natur, Natural, Nature, Vital, Vitality, Pharma, Health, Healthy, Nutrition, Organic, Organics, Bio, Paket, Express, Logistik, Logistics, '
         . 'Vitamin, Vitamine, Supplement, Supplements, Premium, Quality, Service, Services, Trading, Handel, International, Global, Sport, Sports, '
         . 'Beauty, Wellness, Herbal, Herbs, Kapsel, Kapseln, Pulver, Lieferant, Extern, Unbekannt, Test';
}
function kunde_filter_woerter(): array {
    static $w = null;
    if ($w !== null) return $w;
    $roh = '';
    try { $roh = (string) scalar("SELECT v FROM app_meta WHERE k='kunde_filter_woerter'"); } catch (\Throwable $e) {}
    if (trim($roh) === '') $roh = kunde_filter_woerter_standard();
    $w = [];
    foreach (explode(',', $roh) as $x) { $x = mb_strtolower(trim($x)); if ($x !== '') $w[$x] = true; }
    return $w;
}
// EINE Regel (Fable-4 T4.1): wird der Firmenname dieses Lieferanten für Kunden ersetzt? '' = ja, sonst der Grund, warum nicht.
// Ersetzt wird der volle Firmenname ab 4 Zeichen, wenn er eine Rechtsform hat oder aus mindestens zwei Wörtern besteht, ODER ein
// Ein-Wort-Name ab 6 Zeichen, der kein allgemeines Wort ist („Wellgreen“ ja, „Natur“ nein).
function kunde_filter_name_grund(array $l, array $kundenNamen = []): string {
    $f = trim((string)($l['firma'] ?? ''));
    if ($f === '') return 'kein Name';
    if (isset($kundenNamen[mb_strtolower($f)])) return 'heißt wie ein Kunde';
    $kat = array_filter(array_map('trim', explode(',', (string)($l['kat'] ?? $l['kategorien'] ?? ''))));
    if ($kat && !array_diff($kat, ['labor'])) return 'Labor (darf genannt werden)';
    if (preg_match('/bulkify|maniso/i', $f)) return 'eigene Firma';
    if (preg_match('/^L-\d+$/i', $f)) return 'Lieferantennummer';
    if (kunde_filter_paketdienst($f)) return 'Paketdienst';
    if (preg_match('/^(extern|externer lieferant|unbekannt|test|lieferant)$/i', $f)) return 'Platzhalter';
    $ohneRf = trim((string) preg_replace(kunde_filter_rechtsform_muster(), '', $f));
    if (mb_strlen($f) >= 4 && ($ohneRf !== $f || preg_match('/\S\s+\S/u', $f))) return '';
    if (mb_strlen($f) < 6) return 'ein Wort, kürzer als 6 Zeichen';
    if (isset(kunde_filter_woerter()[mb_strtolower($f)])) return 'allgemeines Wort';
    return '';
}
function kunde_lieferanten_namen(): array {
    static $n = null;
    if ($n !== null) return $n;
    $n = [];
    if (!function_exists('scalar') || !kn_tabelle_da('lieferanten')) return $n;
    // Lieferanten, die zugleich Kunde sind (Partner, mit Kunde verknüpft oder gleicher Firmenname), nie ersetzen –
    // sonst stünde im Portal dieses Kunden „bulkify“ statt seines eigenen Namens.
    $kundenNamen = [];
    try { foreach (all("SELECT LOWER(TRIM(firma)) AS f FROM kunden WHERE firma IS NOT NULL AND firma<>''") as $kk) $kundenNamen[(string)$kk['f']] = true; } catch (\Throwable $e) {}
    $partnerSql = (kn_spalte_da('lieferanten', 'ist_partner') ? " AND COALESCE(ist_partner,0)=0" : '')
                . (kn_spalte_da('lieferanten', 'kunde_id') ? " AND kunde_id IS NULL" : '');
    $aliasSql = kn_spalte_da('lieferanten', 'kunden_alias') ? "COALESCE(kunden_alias,'')" : "''";
    foreach (all("SELECT firma, lieferantennummer, COALESCE(kategorien,'') AS kat, $aliasSql AS alias FROM lieferanten WHERE firma IS NOT NULL AND firma<>''" . $partnerSql) as $l) {
        // Fable-3 T3.4 / Fable-4 T4.1: der volle Firmenname (Regel kunde_filter_name_grund) oder ein ausdrücklich gepflegter Alias.
        $grund = kunde_filter_name_grund($l, $kundenNamen);
        if ($grund === '') $n[] = trim((string)$l['firma']);
        if (in_array($grund, ['heißt wie ein Kunde', 'Labor (darf genannt werden)', 'eigene Firma'], true)) continue;
        foreach (array_filter(array_map('trim', explode(',', (string)($l['alias'] ?? '')))) as $al)
            if (mb_strlen($al) >= 3 && !kunde_filter_paketdienst($al)) $n[] = $al;
    }
    $n = array_values(array_unique($n));
    usort($n, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return $n;
}
// Bericht für die Einstellungen (Fable-4 T4.1): je Lieferant, ob der Name für Kunden ersetzt wird, und warum nicht.
function kunde_filter_bericht(): array {
    $out = [];
    if (!function_exists('all') || !kn_tabelle_da('lieferanten')) return $out;
    $kundenNamen = [];
    try { foreach (all("SELECT LOWER(TRIM(firma)) AS f FROM kunden WHERE firma IS NOT NULL AND firma<>''") as $kk) $kundenNamen[(string)$kk['f']] = true; } catch (\Throwable $e) {}
    $partner = kn_spalte_da('lieferanten', 'ist_partner') ? "COALESCE(ist_partner,0)" : "0";
    $kid = kn_spalte_da('lieferanten', 'kunde_id') ? "kunde_id" : "NULL";
    $aliasSql = kn_spalte_da('lieferanten', 'kunden_alias') ? "COALESCE(kunden_alias,'')" : "''";
    foreach (all("SELECT id, firma, lieferantennummer, COALESCE(kategorien,'') AS kat, $aliasSql AS alias, $partner AS partner, $kid AS kid FROM lieferanten WHERE firma IS NOT NULL AND firma<>'' ORDER BY firma") as $l) {
        $g = ((int)$l['partner'] === 1 || !empty($l['kid'])) ? 'Partner / zugleich Kunde' : kunde_filter_name_grund($l, $kundenNamen);
        $out[] = ['id' => (int)$l['id'], 'nr' => (string)$l['lieferantennummer'], 'firma' => (string)$l['firma'], 'alias' => (string)$l['alias'],
                  'ersetzt' => $g === '', 'grund' => $g, 'ein_wort' => !preg_match('/\S\s+\S/u', trim((string)$l['firma']))];
    }
    return $out;
}
// Lieferantennamen in Freitext ersetzen (ganze Wörter, ohne Groß-/Kleinschreibung).
function kunde_text_neutral(?string $t, string $ersatz = 'bulkify'): string {
    $t = (string)$t;
    if ($t === '') return $t;
    // Regel Nico 13.11.2026: Lieferantennummern (L-2841) stehen bewusst auf Dokumenten – nie ersetzen. Vor dem Ersetzen maskieren.
    // Fable-4 T4.2 (13.11.2026): ungültiges UTF-8 (abgeschnittener Umlaut, Latin-1 aus Import/KI) lässt preg_* mit /u scheitern (null).
    // Dann den Text unverändert weitergeben – nie leer (vorher leerer Betreff/Text in Kundenmails, leere Namen im CoA).
    $orig = $t;
    $lnr = [];
    $m1 = preg_replace_callback('/(?<![\p{L}\p{N}])L-\d{3,6}(?![\p{N}])/u', function ($m) use (&$lnr) { $lnr[] = $m[0]; return "\x01" . (count($lnr) - 1) . "\x01"; }, $t);
    if ($m1 === null) return $orig;
    $t = $m1;
    foreach (kunde_lieferanten_namen() as $name) {
        if (mb_stripos($t, $name) === false) continue;
        $r = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $ersatz, $t);
        if ($r !== null) $t = $r;
    }
    if ($lnr) { $r = preg_replace_callback('/\x01(\d+)\x01/', fn($m) => $lnr[(int)$m[1]] ?? $m[0], $t); if ($r !== null) $t = $r; }
    return kunde_charge_text($t);   // Fable-3 T3.2: eigene Chargen nur als Grundcharge
}
// Positions-/Belegtext für den Kunden: Lieferantennamen ersetzen und interne Hinweise auf Zukauf entfernen
// („Handelsware“ verrät, dass bulkify nicht selbst produziert).
function kunde_position_text(?string $t): string {
    $t = kunde_text_neutral($t);
    $t = preg_replace('/\s*·\s*Handelsware\b|\bHandelsware\s*·\s*|\bHandelsware\b/u', '', $t) ?? $t;   // Fable-4 T4.2: nie leer
    return trim($t);
}
// Ist die Mail-Adresse ein Kunde? Dann geht die Mail durch kunde_text_neutral. Seit 13.11.2026 JEDE Adresse, die zu einem Kunden
// gehört: Firmen-Adresse, Portal-Benutzer (benutzer.kunde_id und kunde_portal_user), CRM-Kontakte eines Kunden (crm_kontakt.kunde_id).
// Mehrere Adressen in einem Feld („a@x.de; b@x.de“) und „Name <mail>“ werden zerlegt – trifft eine, gilt die ganze Mail als Kundenmail.
function kunde_ist_mailadresse(string $email): bool {
    if (!function_exists('scalar')) return false;
    preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $email, $m);
    $liste = array_values(array_unique(array_map(fn($x) => mb_strtolower(trim($x)), $m[0] ?? [])));
    if (!$liste) return false;
    $quellen = [
        ['kunden', 'email', ''],
        ['benutzer', 'email', ' AND kunde_id IS NOT NULL AND kunde_id>0'],
        ['kunde_portal_user', 'email', ''],
        ['crm_kontakt', 'email', ' AND kunde_id IS NOT NULL AND kunde_id>0'],
    ];
    foreach ($quellen as [$t, $c, $wo]) {
        try {
            if (!kn_tabelle_da($t) || !kn_spalte_da($t, $c) || ($wo !== '' && !kn_spalte_da($t, 'kunde_id'))) continue;
            // Feld kann mehrere Adressen enthalten: LIKE je Adresse, plus genauer Treffer.
            foreach ($liste as $e) {
                $like = '%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $e) . '%';
                if ((int) scalar("SELECT COUNT(*) FROM `$t` WHERE (LOWER(`$c`)=? OR LOWER(`$c`) LIKE ? ESCAPE '=')$wo", [$e, $like]) > 0) return true;
            }
        } catch (\Throwable $e2) {}
    }
    return false;
}

// Datei an den Kunden ausliefern: neutraler Name, PDF-Metadaten leer, kein Sniffing. Beendet die Anfrage.
function kunde_datei_ausliefern(string $pfad, string $name, bool $inline = true): void {
    $ext = strtolower(pathinfo($pfad, PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'application/octet-stream';
    $daten = (string) file_get_contents($pfad);
    if ($mime === 'application/pdf') $daten = kunde_pdf_neutral($daten);
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($daten));
    echo $daten;
    exit;
}
