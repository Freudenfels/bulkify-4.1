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
// Paketdienste/Spediteure nennen wir dem Kunden offen (Versand per DHL) – nie ersetzen (Fable-3 T3.4).
function kunde_filter_paketdienst(string $name): bool {
    return (bool) preg_match('/(?<![\p{L}])(dhl|ups|dpd|gls|fedex|tnt|hermes|deutsche post|post ag|schenker|dachser|kühne|kuehne|rhenus|trans-o-flex|go!\s*express|db schenker|raben|geodis)(?![\p{L}])/iu', $name);
}
function kunde_lieferanten_namen(): array {
    static $n = null;
    if ($n !== null) return $n;
    $n = [];
    if (!function_exists('table_exists') || !table_exists('lieferanten')) return $n;
    $rechtsform = '/[\s,]+(gmbh(\s*&\s*co\.?\s*kg)?|ag|kg|ohg|ug|gbr|e\.?\s?k\.?|ltd\.?|limited|llc|inc\.?|co\.?,?\s*ltd\.?|s\.?r\.?l\.?|b\.?v\.?|sp\.?\s*z\s*o\.?\s*o\.?|s\.?a\.?|corp\.?|company)\.?$/iu';
    // Lieferanten, die zugleich Kunde sind (Partner, mit Kunde verknüpft oder gleicher Firmenname), nie ersetzen –
    // sonst stünde im Portal dieses Kunden „bulkify“ statt seines eigenen Namens.
    $kundenNamen = [];
    try { foreach (all("SELECT LOWER(TRIM(firma)) AS f FROM kunden WHERE firma IS NOT NULL AND firma<>''") as $kk) $kundenNamen[(string)$kk['f']] = true; } catch (\Throwable $e) {}
    $partnerSql = (function_exists('column_exists') && column_exists('lieferanten', 'ist_partner') ? " AND COALESCE(ist_partner,0)=0" : '')
                . (function_exists('column_exists') && column_exists('lieferanten', 'kunde_id') ? " AND kunde_id IS NULL" : '');
    $aliasSql = (function_exists('column_exists') && column_exists('lieferanten', 'kunden_alias')) ? "COALESCE(kunden_alias,'')" : "''";
    foreach (all("SELECT firma, lieferantennummer, COALESCE(kategorien,'') AS kat, $aliasSql AS alias FROM lieferanten WHERE firma IS NOT NULL AND firma<>''" . $partnerSql) as $l) {
        if (isset($kundenNamen[mb_strtolower(trim((string)$l['firma']))])) continue;
        $kat = array_filter(array_map('trim', explode(',', (string)$l['kat'])));
        if ($kat && !array_diff($kat, ['labor'])) continue;   // Labore dürfen genannt werden
        $f = trim((string)$l['firma']);
        if (preg_match('/bulkify|maniso/i', $f)) continue;
        // Fable-3 T3.4 (13.11.2026): nur der VOLLE Firmenname oder ein ausdrücklich gepflegter Alias (lieferanten.kunden_alias),
        // nie ein einzelnes Wort daraus („Vita GmbH“ ersetzt „Vita GmbH“, nicht „Vita“). Paketdienste werden nie ersetzt.
        if (kunde_filter_paketdienst($f)) continue;
        $ohneRf = trim((string) preg_replace($rechtsform, '', $f));
        $mitRechtsform = $ohneRf !== $f;
        if (mb_strlen($f) >= 4 && ($mitRechtsform || preg_match('/\S\s+\S/u', $f)) && !preg_match('/^(extern|externer lieferant|unbekannt|test|lieferant)$/i', $f)) $n[] = $f;
        foreach (array_filter(array_map('trim', explode(',', (string)($l['alias'] ?? '')))) as $al)
            if (mb_strlen($al) >= 3 && !kunde_filter_paketdienst($al)) $n[] = $al;
    }
    $n = array_values(array_unique($n));
    usort($n, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return $n;
}
// Lieferantennamen in Freitext ersetzen (ganze Wörter, ohne Groß-/Kleinschreibung).
function kunde_text_neutral(?string $t, string $ersatz = 'bulkify'): string {
    $t = (string)$t;
    if ($t === '') return $t;
    foreach (kunde_lieferanten_namen() as $name) {
        if (mb_stripos($t, $name) === false) continue;
        $t = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu', $ersatz, $t);
    }
    return kunde_charge_text($t);   // Fable-3 T3.2: eigene Chargen nur als Grundcharge
}
// Positions-/Belegtext für den Kunden: Lieferantennamen ersetzen und interne Hinweise auf Zukauf entfernen
// („Handelsware“ verrät, dass bulkify nicht selbst produziert).
function kunde_position_text(?string $t): string {
    $t = kunde_text_neutral($t);
    $t = preg_replace('/\s*·\s*Handelsware\b|\bHandelsware\s*·\s*|\bHandelsware\b/u', '', $t);
    return trim($t);
}
// Ist die Mail-Adresse ein Kunde? Dann geht die Mail durch kunde_text_neutral. Seit 13.11.2026 JEDE Adresse, die zu einem Kunden
// gehört: Firmen-Adresse, Portal-Benutzer (benutzer.kunde_id und kunde_portal_user), CRM-Kontakte eines Kunden (crm_kontakt.kunde_id).
// Mehrere Adressen in einem Feld („a@x.de; b@x.de“) und „Name <mail>“ werden zerlegt – trifft eine, gilt die ganze Mail als Kundenmail.
function kunde_ist_mailadresse(string $email): bool {
    if (!function_exists('table_exists')) return false;
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
            if (!table_exists($t) || !column_exists($t, $c) || ($wo !== '' && !column_exists($t, 'kunde_id'))) continue;
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
