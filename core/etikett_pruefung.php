<?php
// Etikett hochladen, prüfen und freigeben in einem Schritt (Anweisung 13.11.2026, plan-etikett-upload.md).
// EINE Stelle für Kundenportal und Dashboard:
// - Band:      etp_band_liste() / etp_band_html() – offene Aufträge ohne Etikett bzw. ohne Freigabe (rotes Band im Portal).
// - Pop-up:    etp_ajax() beantwortet alle Schritte (Status, Hochladen, Druck-Check aus dem Browser, KI-Etikettencheck,
//              Freigabe, Später); die Oberfläche baut public/assets/bx-etikett.js.
// - Daten:     Tabelle etikett_pruefung (je hochgeladener Version eine Zeile: Datei, Maß, Technik, KI, Freigabe).
// - Beutel:    Druckvorlage je Beutel-Typ in item.beutel_vorlage (JSON, Flächen + Siegel/Zipper), Pflege an der Verpackung.
// Braucht core/schema.php (one/all/q, etikett_*), wird von dort eingebunden. Kein Ghostscript: der PDF-Leser ist reines PHP,
// Bildauflösung und Textlage misst der Browser mit pdf.js und schickt sie mit.

function etp_schema(): void {
    static $ok = false; if ($ok) return; $ok = true;
    try {
        q("CREATE TABLE IF NOT EXISTS etikett_pruefung (
            id INT AUTO_INCREMENT PRIMARY KEY,
            auftrag_id INT NOT NULL,
            dokument_id INT NULL,
            version INT NOT NULL DEFAULT 1,
            art VARCHAR(10) NOT NULL DEFAULT 'etikett',
            datei VARCHAR(255) NULL,
            datei_orig VARCHAR(255) NULL,
            soll_w DECIMAL(7,1) NULL, soll_h DECIMAL(7,1) NULL,
            ist_w DECIMAL(7,1) NULL, ist_h DECIMAL(7,1) NULL,
            mass_ampel VARCHAR(6) NULL,
            technik MEDIUMTEXT NULL,
            ki MEDIUMTEXT NULL,
            ki_status VARCHAR(16) NULL,
            ki_zusammenfassung VARCHAR(255) NULL,
            ki_am DATETIME NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'wartet',
            trotzdem TINYINT(1) NOT NULL DEFAULT 0,
            freigabe_name VARCHAR(190) NULL,
            freigabe_am DATETIME NULL,
            freigabe_nutzer VARCHAR(190) NULL,
            freigabe_ip VARCHAR(64) NULL,
            freigabe_grund TEXT NULL,
            freigabe_akteur VARCHAR(10) NULL,
            hochgeladen_von VARCHAR(10) NULL,
            hochgeladen_nutzer VARCHAR(190) NULL,
            angelegt DATETIME NOT NULL,
            KEY idx_etp_auftrag (auftrag_id)
        ) DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    try { ensure_column('item', 'beutel_vorlage', 'MEDIUMTEXT NULL'); } catch (Throwable $e) {}
}

// ---------------------------------------------------------------------------------------------------------------
// Sprache und Texte. Das Portal spricht den Kunden in seiner Sprache an: Deutsch für DE/AT/CH/LI/LU, sonst Englisch.
function etp_sprache(int $kunde_id): string {
    $land = strtoupper((string) scalar("SELECT COALESCE(NULLIF(land,''),'DE') FROM kunden WHERE id=?", [$kunde_id]));
    return in_array($land, ['', 'DE', 'AT', 'CH', 'LI', 'LU'], true) ? 'de' : 'en';
}
function etp_t(string $sp): callable { return fn(string $de, string $en) => $sp === 'en' ? $en : $de; }
function etp_mm($x): string { return rtrim(rtrim(number_format((float)$x, 1, ',', ''), '0'), ','); }
function etp_mm_label($w, $h): string { return etp_mm($w) . ' × ' . etp_mm($h) . ' mm'; }

// Auftrag mit Produktname, Behälter und Kunde.
function etp_auftrag(int $aid): ?array {
    $a = one("SELECT a.id, a.nummer, a.kunde_id, a.produkt_id, a.status, a.etikett_freigegeben, a.etikett_freigabe_am, a.etikett_freigabe_von,
                     COALESCE(NULLIF(a.verpackung_id,0), p.verpackung_id, 0) AS vp_id,
                     COALESCE(NULLIF(a.produkt_bezeichnung,''), NULLIF(p.kundenname,''), p.name) AS produkt
              FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$aid]);
    if (!$a) return null;
    if (!empty($a['produkt_id']) && function_exists('produkt_kundenname')) {
        $kn = produkt_kundenname((int)$a['produkt_id'], (int)$a['kunde_id']);
        if ($kn !== '') $a['produkt'] = $kn;
    }
    return $a;
}

// ---------------------------------------------------------------------------------------------------------------
// Beutel-Vorlage (Teil 2b): JSON am Verpackungs-Artikel. Maße in mm, Lage von oben links des Endformats.
// {"w":134,"h":486,"beschnitt":2,"sicher":3,
//  "flaechen":[{"name":"Vorderseite","x":0,"y":0,"w":134,"h":228,"drehung":0}, …],
//  "zonen":[{"art":"siegel|zipper","name":"Siegelnaht oben","x":0,"y":0,"w":134,"h":10}, …],
//  "stanzkontur_dok":123}
function etp_vorlage_lesen(?string $json): ?array {
    $v = json_decode((string)$json, true);
    if (!is_array($v) || (float)($v['w'] ?? 0) <= 0 || (float)($v['h'] ?? 0) <= 0) return null;
    $W = (float)$v['w']; $H = (float)$v['h'];
    $rect = function (array $r) use ($W, $H): array {
        $x = max(0.0, (float)($r['x'] ?? 0)); $y = max(0.0, (float)($r['y'] ?? 0));
        $w = (float)($r['w'] ?? 0); $h = (float)($r['h'] ?? 0);
        if ($w <= 0) $w = $W - $x;
        if ($h <= 0) $h = $H - $y;
        return ['x' => $x, 'y' => $y, 'w' => min($w, $W - $x), 'h' => min($h, $H - $y)];
    };
    $fl = [];
    foreach ((array)($v['flaechen'] ?? []) as $f) {
        if (!is_array($f) || trim((string)($f['name'] ?? '')) === '') continue;
        $fl[] = ['name' => mb_substr(trim((string)$f['name']), 0, 60), 'drehung' => ((int)($f['drehung'] ?? 0)) === 180 ? 180 : 0] + $rect($f);
    }
    $zo = [];
    foreach ((array)($v['zonen'] ?? []) as $z) {
        if (!is_array($z)) continue;
        $art = in_array(($z['art'] ?? ''), ['siegel', 'zipper'], true) ? $z['art'] : 'siegel';
        $zo[] = ['art' => $art, 'name' => mb_substr(trim((string)($z['name'] ?? '')) ?: ($art === 'zipper' ? 'Zipper' : 'Siegelnaht'), 0, 60)] + $rect($z);
    }
    return ['w' => $W, 'h' => $H, 'beschnitt' => (float)($v['beschnitt'] ?? 2) ?: 2.0, 'sicher' => (float)($v['sicher'] ?? 3) ?: 3.0,
            'flaechen' => $fl, 'zonen' => $zo, 'stanzkontur_dok' => (int)($v['stanzkontur_dok'] ?? 0) ?: null];
}
// Vorlage aus dem Formular der Verpackungsseite (module/lager/verpackung_detail.php) speichern. Leeres Gesamtformat = Vorlage entfernen.
function etp_vorlage_aus_post(int $item_id, array $post): string {
    etp_schema();
    $num = fn($x) => (float) str_replace(',', '.', trim((string)$x));
    $W = $num($post['bv_w'] ?? 0); $H = $num($post['bv_h'] ?? 0);
    $alt = etp_vorlage_lesen((string) scalar("SELECT beutel_vorlage FROM item WHERE id=?", [$item_id]));
    if ($W <= 0 || $H <= 0) { q("UPDATE item SET beutel_vorlage=NULL WHERE id=?", [$item_id]); return 'Druckvorlage entfernt.'; }
    $v = ['w' => $W, 'h' => $H, 'beschnitt' => $num($post['bv_beschnitt'] ?? 2) ?: 2, 'sicher' => $num($post['bv_sicher'] ?? 3) ?: 3, 'flaechen' => [], 'zonen' => []];
    foreach ((array)($post['bv_f_name'] ?? []) as $i => $n) {
        if (trim((string)$n) === '') continue;
        $v['flaechen'][] = ['name' => trim((string)$n), 'x' => $num($post['bv_f_x'][$i] ?? 0), 'y' => $num($post['bv_f_y'][$i] ?? 0),
                            'w' => $num($post['bv_f_w'][$i] ?? 0), 'h' => $num($post['bv_f_h'][$i] ?? 0), 'drehung' => !empty($post['bv_f_dreh'][$i]) ? 180 : 0];
    }
    foreach ((array)($post['bv_z_art'] ?? []) as $i => $art) {
        if ($num($post['bv_z_h'][$i] ?? 0) <= 0 && $num($post['bv_z_w'][$i] ?? 0) <= 0) continue;
        $v['zonen'][] = ['art' => $art === 'zipper' ? 'zipper' : 'siegel', 'name' => trim((string)($post['bv_z_name'][$i] ?? '')),
                         'x' => $num($post['bv_z_x'][$i] ?? 0), 'y' => $num($post['bv_z_y'][$i] ?? 0), 'w' => $num($post['bv_z_w'][$i] ?? 0), 'h' => $num($post['bv_z_h'][$i] ?? 0)];
    }
    // Stanzkontur (optional, PDF) als Dokument am Verpackungs-Artikel.
    $v['stanzkontur_dok'] = $alt['stanzkontur_dok'] ?? null;
    if (!empty($post['bv_stanz_weg'])) $v['stanzkontur_dok'] = null;
    if (!empty($_FILES['bv_stanz']['name']) && ($_FILES['bv_stanz']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $kopf = (string) @file_get_contents($_FILES['bv_stanz']['tmp_name'], false, null, 0, 5);
        if ($kopf === '%PDF-') {
            if (!is_dir(BX_UPLOADS)) @mkdir(BX_UPLOADS, 0775, true);
            $fn = 'item_' . $item_id . '_stanzkontur_' . bin2hex(random_bytes(6)) . '.pdf';
            if (move_uploaded_file($_FILES['bv_stanz']['tmp_name'], BX_UPLOADS . '/' . $fn)) {
                q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig) VALUES ('item',?,?,?,?,?)",
                  [$item_id, 'stanzkontur', 'Stanzkontur', $fn, (string)$_FILES['bv_stanz']['name']]);
                $v['stanzkontur_dok'] = (int) insert_id();
            }
        }
    }
    $norm = etp_vorlage_lesen(json_encode($v, JSON_INVALID_UTF8_SUBSTITUTE));
    if (!$norm) return 'Druckvorlage nicht gespeichert – bitte Gesamtformat prüfen.';
    q("UPDATE item SET beutel_vorlage=? WHERE id=?", [json_encode($norm, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $item_id]);
    // offene Aufgabe „Vorlage fehlt“ erledigen
    try { q("UPDATE aufgabe SET status='erledigt' WHERE ref_typ='beutel_vorlage_fehlt' AND ref_id=? AND status='offen'", [$item_id]); } catch (Throwable $e) {}
    return 'Druckvorlage gespeichert.';
}

// ---------------------------------------------------------------------------------------------------------------
// Soll-Format eines Auftrags: Etikett (Maß des Kundenetiketts bzw. des Etiketts zum Behälter) oder Beutel (Vorlage).
function etp_soll(int $aid): array {
    etp_schema();
    $a = etp_auftrag($aid) ?: [];
    $besch  = (float) str_replace(',', '.', (string) meta_get('etikett_beschnitt_mm', '2')) ?: 2.0;
    $sicher = (float) str_replace(',', '.', (string) meta_get('etikett_sicherheit_mm', '2')) ?: 2.0;
    if ($aid > 0 && auftrag_vollbedruckt($aid)) {
        $it = one("SELECT id, name, beutel_vorlage FROM item WHERE id=?", [(int)($a['vp_id'] ?? 0)]) ?: [];
        $v = etp_vorlage_lesen($it['beutel_vorlage'] ?? '');
        return ['art' => 'beutel', 'item_id' => (int)($it['id'] ?? 0), 'item_name' => (string)($it['name'] ?? ''),
                'w' => $v['w'] ?? null, 'h' => $v['h'] ?? null, 'beschnitt' => $v['beschnitt'] ?? $besch, 'sicher' => $v['sicher'] ?? 3.0, 'vorlage' => $v];
    }
    $eid = 0;
    if (!empty($a['produkt_id'])) $eid = etikett_item_fuer_auftrag_kunde($aid, (int)$a['produkt_id']);
    if ($eid <= 0) $eid = etikett_item_fuer_auftrag($aid);
    $it = $eid > 0 ? one("SELECT id, name, breite_mm, hoehe_mm, etikett_final, etikett_druck, etikett_format FROM item WHERE id=?", [$eid]) : null;
    $w = (float)($it['breite_mm'] ?? 0); $h = (float)($it['hoehe_mm'] ?? 0);
    if (($w <= 0 || $h <= 0) && $it) { $m = etikett_masse($it['etikett_final'] ?: $it['etikett_format']); if ($m) [$w, $h] = $m; }
    // Sofortfix 13.11.2026 (AB-3223): Steht am Etikett-Artikel „Glas-Endformat + 2 × Beschnitt“ (Maß wurde aus einer TrimBox übernommen,
    // die den Beschnitt enthält), gilt das Endformat des Glases; der Unterschied ist der Beschnitt.
    $glas = etikett_glas_endformat((int)($a['vp_id'] ?? 0));
    $bGlas = ($glas && $w > 0 && $h > 0) ? etikett_plus_beschnitt([$w, $h], $glas) : null;
    if ($bGlas !== null) { [$w, $h] = $glas; }
    // Etiketten liegen quer (Breite = lange Seite um das Glas) – so zeigen wir das Maß auch an: 120 × 45 mm.
    if ($w > 0 && $h > $w) [$w, $h] = [$h, $w];
    if ($bGlas !== null) $besch = $bGlas;
    elseif ($it && $w > 0 && ($d = etikett_masse((string)($it['etikett_druck'] ?? '')))) {
        $diff = (max($d) - $w) / 2;
        if ($diff >= 0.5 && $diff <= 6) $besch = round($diff, 1);
    }
    return ['art' => 'etikett', 'item_id' => (int)($it['id'] ?? 0), 'item_name' => (string)($it['name'] ?? ''),
            'w' => $w > 0 ? $w : null, 'h' => $h > 0 ? $h : null, 'beschnitt' => $besch, 'sicher' => $sicher, 'vorlage' => null];
}

// ---------------------------------------------------------------------------------------------------------------
// PDF-Leser (reines PHP): Objekte inkl. Objekt-Streams, Seitenbaum, Boxen der ersten Seite, Bilder, Farbräume, Schriften.
// Kein vollständiger PDF-Parser – er liest genau das, was der Druck-Check braucht, und gibt im Zweifel „unbekannt“ zurück.
function etp_pdf_wert(string $s, int &$i) {
    $n = strlen($s);
    while ($i < $n) {                                   // Leerraum und Kommentare überspringen
        $c = $s[$i];
        if ($c === '%') { while ($i < $n && $s[$i] !== "\n" && $s[$i] !== "\r") $i++; continue; }
        if (strpos(" \t\r\n\f\0", $c) !== false) { $i++; continue; }
        break;
    }
    if ($i >= $n) return null;
    $c = $s[$i];
    if ($c === '<' && ($s[$i + 1] ?? '') === '<') {
        $i += 2; $d = ['__d' => 1];
        while ($i < $n) {
            while ($i < $n && strpos(" \t\r\n\f\0", $s[$i]) !== false) $i++;
            if ($i >= $n) break;
            if ($s[$i] === '>' && ($s[$i + 1] ?? '') === '>') { $i += 2; break; }
            $k = etp_pdf_wert($s, $i);
            if (!is_string($k) || ($k[0] ?? '') !== '/') { if ($k === null) break; continue; }
            $d[$k] = etp_pdf_wert($s, $i);
        }
        return $d;
    }
    if ($c === '[') {
        $i++; $a = [];
        while ($i < $n) {
            while ($i < $n && strpos(" \t\r\n\f\0", $s[$i]) !== false) $i++;
            if ($i >= $n) break;
            if ($s[$i] === ']') { $i++; break; }
            $v = etp_pdf_wert($s, $i);
            if ($v === null && $i >= $n) break;
            $a[] = $v;
        }
        return $a;
    }
    if ($c === '/') {
        $j = $i + 1;
        while ($j < $n && strpos(" \t\r\n\f\0/[]<>(){}%", $s[$j]) === false) $j++;
        $name = substr($s, $i, $j - $i); $i = $j;
        return preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn($m) => chr(hexdec($m[1])), $name);
    }
    if ($c === '(') {
        $tiefe = 0; $j = $i; $out = '';
        for (; $j < $n; $j++) {
            $ch = $s[$j];
            if ($ch === '\\') { $out .= $s[$j + 1] ?? ''; $j++; continue; }
            if ($ch === '(') { if ($tiefe++ > 0) $out .= $ch; continue; }
            if ($ch === ')') { if (--$tiefe === 0) { $j++; break; } $out .= $ch; continue; }
            $out .= $ch;
        }
        $i = $j;
        return ['S' => $out];
    }
    if ($c === '<') { $j = strpos($s, '>', $i); $j = $j === false ? $n : $j; $hex = substr($s, $i + 1, $j - $i - 1); $i = $j + 1; return ['S' => @hex2bin(preg_replace('/\s+/', '', $hex) . (strlen(preg_replace('/\s+/', '', $hex)) % 2 ? '0' : '')) ?: '']; }
    if (preg_match('/\G([+-]?(?:\d+\.?\d*|\.\d+))/A', $s, $m, 0, $i)) {
        $i += strlen($m[1]);
        // Referenz „12 0 R“?
        if (ctype_digit($m[1]) && preg_match('/\G\s+(\d+)\s+R(?![A-Za-z])/A', $s, $r, 0, $i)) { $i += strlen($r[0]); return ['R' => (int)$m[1]]; }
        return strpos($m[1], '.') !== false ? (float)$m[1] : (int)$m[1];
    }
    if (preg_match('/\G(true|false|null)/A', $s, $m, 0, $i)) { $i += strlen($m[1]); return $m[1] === 'true' ? true : ($m[1] === 'false' ? false : null); }
    $i++;                                                 // unbekanntes Zeichen überspringen
    return null;
}
function etp_pdf_a85(string $s): ?string {
    $s = preg_replace('/\s+/', '', $s);
    if (str_starts_with($s, '<~')) $s = substr($s, 2);
    if (($e = strpos($s, '~>')) !== false) $s = substr($s, 0, $e);
    $out = ''; $g = [];
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        if ($s[$i] === 'z' && !$g) { $out .= "\0\0\0\0"; continue; }
        $o = ord($s[$i]) - 33; if ($o < 0 || $o > 84) return null;
        $g[] = $o;
        if (count($g) === 5) { $v = 0; foreach ($g as $x) $v = $v * 85 + $x; $out .= pack('N', $v & 0xFFFFFFFF); $g = []; }
    }
    if ($g) { $k = count($g); while (count($g) < 5) $g[] = 84; $v = 0; foreach ($g as $x) $v = $v * 85 + $x; $out .= substr(pack('N', $v & 0xFFFFFFFF), 0, $k - 1); }
    return $out;
}
function etp_pdf_stream_daten(string $roh, array $dict): ?string {
    $f = $dict['/Filter'] ?? null;
    $filter = is_array($f) && !isset($f['__d']) ? $f : ($f !== null ? [$f] : []);
    $d = $roh;
    foreach ($filter as $fn) {
        if ($fn === '/FlateDecode' || $fn === '/Fl') {
            $x = @gzuncompress($d);
            if ($x === false) $x = @gzinflate(substr($d, 2));
            if ($x === false) return null;
            $d = $x;
        } elseif ($fn === '/ASCII85Decode' || $fn === '/A85') {
            $d = etp_pdf_a85($d); if ($d === null) return null;
        } elseif ($fn === '/ASCIIHexDecode' || $fn === '/AHx') {
            $h = preg_replace('/[^0-9A-Fa-f]/', '', explode('>', $d)[0]); $d = (string) @hex2bin($h . (strlen($h) % 2 ? '0' : ''));
        } else return null;                              // andere Filter (JPEG, LZW …) brauchen wir nicht
    }
    return $d;
}
function etp_pdf_objekte(string $data): array {
    $obj = []; $stream = [];
    if (!preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $data, $m, PREG_OFFSET_CAPTURE)) return [[], []];
    $weiter = 0;
    foreach ($m[0] as $k => $t) {
        if ($t[1] < $weiter) continue;                    // Treffer mitten in einem Stream
        $num = (int)$m[1][$k][0]; $start = $t[1] + strlen($t[0]);
        $i = $start; $wert = etp_pdf_wert($data, $i);
        $ende = $i;
        if (is_array($wert) && isset($wert['__d']) && preg_match('/\G\s*stream\r?\n/A', $data, $sm, 0, $i)) {
            $sStart = $i + strlen($sm[0]);
            $len = $wert['/Length'] ?? null;
            $sEnde = (is_int($len) && $len > 0 && substr($data, $sStart + $len, 30) !== '' && preg_match('/^\s*endstream/', substr($data, $sStart + $len, 30)))
                     ? $sStart + $len : strpos($data, 'endstream', $sStart);
            if ($sEnde === false) $sEnde = strlen($data);
            $stream[$num] = [$sStart, $sEnde];
            $ende = $sEnde;
        }
        $obj[$num] = $wert;                               // spätere Definition (Änderung) gewinnt
        $e2 = strpos($data, 'endobj', $ende);
        $weiter = $e2 === false ? $ende : $e2;
    }
    // Objekt-Streams entpacken
    foreach ($obj as $num => $d) {
        if (!is_array($d) || ($d['/Type'] ?? '') !== '/ObjStm' || !isset($stream[$num])) continue;
        [$a, $b] = $stream[$num];
        $roh = etp_pdf_stream_daten(substr($data, $a, $b - $a), $d);
        if ($roh === null) continue;
        $first = (int)($d['/First'] ?? 0); $anz = (int)($d['/N'] ?? 0);
        $kopf = preg_split('/\s+/', trim(substr($roh, 0, $first)));
        for ($q = 0; $q + 1 < count($kopf) && $q / 2 < $anz; $q += 2) {
            $on = (int)$kopf[$q]; $off = $first + (int)$kopf[$q + 1];
            if (isset($obj[$on])) continue;
            $i = $off; $obj[$on] = etp_pdf_wert($roh, $i);
        }
    }
    return [$obj, $stream];
}
function etp_pdf_lesen(string $pfad): array {
    $leer = ['ok' => false, 'seiten' => 0, 'boxen' => [], 'rotate' => 0, 'bilder' => [], 'farben' => ['rgb' => false, 'cmyk' => false, 'grau' => false, 'sonder' => [], 'lab' => false],
             'schriften' => [], 'text' => false];
    if (!is_file($pfad)) return $leer;
    $data = @file_get_contents($pfad, false, null, 0, 60000000);
    if ($data === false || strncmp($data, '%PDF-', 5) !== 0) return $leer;
    [$obj, $stream] = etp_pdf_objekte($data);
    if (!$obj) return $leer;
    $res = function ($v, int $tiefe = 0) use (&$res, $obj) {
        while (is_array($v) && isset($v['R']) && count($v) === 1 && $tiefe++ < 20) $v = $obj[$v['R']] ?? null;
        return $v;
    };
    $out = $leer; $out['ok'] = true;
    // Katalog -> Seitenbaum
    $kat = null;
    foreach ($obj as $d) if (is_array($d) && ($d['/Type'] ?? '') === '/Catalog') $kat = $d;
    $seite = null; $erbe = [];
    if ($kat) {
        $knoten = $res($kat['/Pages'] ?? null);
        $out['seiten'] = (int)($knoten['/Count'] ?? 0);
        for ($tiefe = 0; $tiefe < 30 && is_array($knoten); $tiefe++) {
            foreach (['/MediaBox', '/CropBox', '/Rotate', '/Resources'] as $k) if (isset($knoten[$k])) $erbe[$k] = $knoten[$k];
            if (($knoten['/Type'] ?? '') === '/Page' || !isset($knoten['/Kids'])) { $seite = $knoten; break; }
            $kids = $res($knoten['/Kids']);
            $knoten = is_array($kids) && $kids ? $res($kids[0]) : null;
        }
    }
    if (!$seite) foreach ($obj as $d) if (is_array($d) && ($d['/Type'] ?? '') === '/Page') { $seite = $d; break; }
    if (!$out['seiten']) foreach ($obj as $d) if (is_array($d) && ($d['/Type'] ?? '') === '/Page') $out['seiten']++;
    if ($seite) {
        $seite += $erbe;
        foreach (['/MediaBox', '/CropBox', '/BleedBox', '/TrimBox', '/ArtBox'] as $k) {
            $b = $res($seite[$k] ?? null);
            if (is_array($b) && count($b) >= 4) {
                $b = array_map(fn($x) => (float)$res($x), array_slice($b, 0, 4));
                $out['boxen'][substr($k, 1)] = [min($b[0], $b[2]), min($b[1], $b[3]), max($b[0], $b[2]), max($b[1], $b[3])];
            }
        }
        $out['rotate'] = ((int)$res($seite['/Rotate'] ?? 0) % 360 + 360) % 360;
    }
    // Farbraum einordnen
    $farbe = function ($cs, int $tiefe = 0) use (&$farbe, $res, $obj, $data, $stream): array {
        $cs = $res($cs);
        if (is_string($cs)) return match ($cs) {
            '/DeviceRGB', '/RGB', '/CalRGB' => ['rgb'], '/DeviceCMYK', '/CMYK' => ['cmyk'], '/DeviceGray', '/G', '/CalGray' => ['grau'], '/Pattern' => [], default => ['?'] };
        if (!is_array($cs) || !$cs || $tiefe > 5) return ['?'];
        $art = $cs[0] ?? '';
        if ($art === '/ICCBased') { $d = $res($cs[1] ?? null); $n = (int)($d['/N'] ?? 0); return $n === 4 ? ['cmyk'] : ($n === 3 ? ['rgb'] : ($n === 1 ? ['grau'] : ['?'])); }
        if ($art === '/Separation') { $nm = ltrim((string)($cs[1] ?? ''), '/'); return in_array($nm, ['All', 'None'], true) ? [] : ['sonder:' . $nm]; }
        if ($art === '/DeviceN') { $o = []; foreach ((array)$res($cs[1] ?? []) as $nm) { $nm = ltrim((string)$nm, '/'); if (in_array($nm, ['Cyan', 'Magenta', 'Yellow', 'Black'], true)) $o[] = 'cmyk'; elseif (!in_array($nm, ['All', 'None'], true)) $o[] = 'sonder:' . $nm; } return $o ?: ['cmyk']; }
        if ($art === '/Indexed' || $art === '/I') return $farbe($cs[1] ?? null, $tiefe + 1);
        if ($art === '/Lab') return ['lab'];
        if ($art === '/CalRGB') return ['rgb'];
        if ($art === '/CalGray') return ['grau'];
        if ($art === '/Pattern') return isset($cs[1]) ? $farbe($cs[1], $tiefe + 1) : [];
        return ['?'];
    };
    $merke = function (array $arten) use (&$out) {
        foreach ($arten as $f) {
            if ($f === 'rgb') $out['farben']['rgb'] = true;
            elseif ($f === 'cmyk') $out['farben']['cmyk'] = true;
            elseif ($f === 'grau') $out['farben']['grau'] = true;
            elseif ($f === 'lab') $out['farben']['lab'] = true;
            elseif (str_starts_with($f, 'sonder:')) $out['farben']['sonder'][substr($f, 7)] = true;
        }
    };
    // Bilder (ohne Masken), Schriften, Farbraum-Ressourcen, Inhalte
    $masken = [];
    foreach ($obj as $d) if (is_array($d) && isset($d['/SMask']['R'])) $masken[$d['/SMask']['R']] = true;
    $fonts = []; $fontRes = []; $fontBenutzt = []; $tfGefunden = false;
    foreach ($obj as $num => $d) {
        if (!is_array($d) || !isset($d['__d'])) continue;
        if (($d['/Subtype'] ?? '') === '/Image') {
            if (!empty($masken[$num]) || $res($d['/ImageMask'] ?? false) === true) continue;
            $arten = isset($d['/ColorSpace']) ? $farbe($d['/ColorSpace']) : (($d['/Filter'] ?? '') === '/JPXDecode' ? ['?'] : ['?']);
            $merke($arten);
            $f0 = $arten[0] ?? '?';
            $out['bilder'][] = ['w' => (int)$res($d['/Width'] ?? 0), 'h' => (int)$res($d['/Height'] ?? 0),
                                'farbe' => $f0 === 'rgb' ? 'RGB' : ($f0 === 'cmyk' ? 'CMYK' : ($f0 === 'grau' ? 'Grau' : (str_starts_with($f0, 'sonder:') ? 'Sonderfarbe' : ($f0 === 'lab' ? 'Lab' : '?'))))];
            continue;
        }
        if (($d['/Type'] ?? '') === '/Font' && isset($d['/Subtype']) && !in_array($d['/Subtype'], ['/CIDFontType0', '/CIDFontType2'], true)) {
            $sub = $d['/Subtype'];
            $name = preg_replace('/^[A-Z]{6}\+/', '', ltrim((string)$res($d['/BaseFont'] ?? ('/' . $sub)), '/'));
            $fd = null;
            if ($sub === '/Type0') { $dfs = $res($d['/DescendantFonts'] ?? null); $df = is_array($dfs) && $dfs ? $res($dfs[0]) : null; $fd = is_array($df) ? $res($df['/FontDescriptor'] ?? null) : null; }
            else $fd = $res($d['/FontDescriptor'] ?? null);
            $ein = $sub === '/Type3' || (is_array($fd) && (isset($fd['/FontFile']) || isset($fd['/FontFile2']) || isset($fd['/FontFile3'])));
            $fonts[$num] = ['name' => $name, 'ein' => $ein];
            continue;
        }
        // Schrift-Ressourcen: Name (/F1) -> Schrift-Objekt
        $rr = isset($d['/Resources']) ? $res($d['/Resources']) : null;
        if (is_array($rr) && isset($rr['/Font']) && is_array($fd2 = $res($rr['/Font'])))
            foreach ($fd2 as $k => $ref) if ($k !== '__d' && is_array($ref) && isset($ref['R'])) $fontRes[$k][$ref['R']] = true;
        if (isset($d['/ColorSpace']) && is_array($cd = $res($d['/ColorSpace'])) && isset($cd['__d']))
            foreach ($cd as $k => $cs) if ($k !== '__d') $merke($farbe($cs));
        if (isset($d['/Resources']) && is_array($rr = $res($d['/Resources'])) && isset($rr['/ColorSpace']) && is_array($cd = $res($rr['/ColorSpace'])) && isset($cd['__d']))
            foreach ($cd as $k => $cs) if ($k !== '__d') $merke($farbe($cs));
    }
    // Inhalts-Streams (Seiten und Formulare): Farb-Operatoren und Text
    $gelesen = 0;
    foreach ($stream as $num => [$a, $b]) {
        $d = $obj[$num] ?? null;
        if (!is_array($d) || in_array(($d['/Subtype'] ?? ''), ['/Image'], true) || in_array(($d['/Type'] ?? ''), ['/ObjStm', '/XRef', '/Metadata', '/EmbeddedFile'], true)) continue;
        if (isset($d['/N']) || isset($d['/Length1']) || isset($d['/Length2']) || ($d['/Subtype'] ?? '') === '/Type1C' || ($d['/Subtype'] ?? '') === '/CIDFontType0C' || ($d['/Subtype'] ?? '') === '/OpenType') continue;   // ICC-Profile, Schriftdateien
        if ($b - $a > 20000000 || $gelesen > 80000000) continue;
        $c = etp_pdf_stream_daten(substr($data, $a, $b - $a), $d);
        if ($c === null) continue;
        $gelesen += strlen($c);
        if (preg_match('/(?:^|[\s\]])(?:[-\d.]+\s+){3}(?:rg|RG)(?=[\s\/\[<(]|$)/m', $c)) $out['farben']['rgb'] = true;
        if (preg_match('/(?:^|[\s\]])(?:[-\d.]+\s+){4}(?:k|K)(?=[\s\/\[<(]|$)/m', $c)) $out['farben']['cmyk'] = true;
        if (preg_match('/(?:^|\s)[-\d.]+\s+(?:g|G)(?=[\s\/\[<(]|$)/m', $c)) $out['farben']['grau'] = true;
        // Welche Schrift zeigt wirklich Text? (Tf setzt die Schrift, Tj/TJ/'/" zeigen Text)
        if (preg_match_all('/(\/[^\s\/\[\]<>(){}%]+)\s+[-\d.]+\s+Tf\b|(?:\)|\]|>)\s*(?:Tj|TJ|\'|")(?=[\s\/\[<(]|$)/', $c, $tm, PREG_SET_ORDER)) {
            $akt = null;
            foreach ($tm as $x) {
                if (!empty($x[1])) { $akt = $x[1]; $tfGefunden = true; continue; }
                $out['text'] = true;
                if ($akt !== null) $fontBenutzt[$akt] = true;
            }
        }
    }
    // Schriften: nur die, die Text zeigen (Ressourcen-Name -> Objekt). Ohne lesbare Inhalte: alle.
    $zaehlt = [];
    if ($tfGefunden) { foreach ($fontBenutzt as $rn => $_) foreach (array_keys($fontRes[$rn] ?? []) as $on) $zaehlt[$on] = true; }
    $namen = [];
    foreach ($fonts as $on => $f) {
        if ($tfGefunden && empty($zaehlt[$on])) continue;
        $namen[$f['name']] = ($namen[$f['name']] ?? false) || $f['ein'];
    }
    foreach ($namen as $n => $ein) $out['schriften'][] = ['name' => $n, 'eingebettet' => (bool)$ein];
    $out['farben']['sonder'] = array_keys($out['farben']['sonder']);
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------
// Maß-Ampel (Sofortfix 13.11.2026): Vergleich in dieser Reihenfolge –
// 1) hat das PDF eine eigene TrimBox (bzw. ArtBox), die kleiner als die Seite ist: TrimBox gegen das Endformat (dazu der gemessene Beschnitt);
// 2) sonst die Seite (BleedBox bzw. MediaBox): passt sie direkt, gut; ist sie Endformat + 2 × b mit b zwischen Soll-Beschnitt und 5 mm (±1 mm),
//    passt das Format ebenfalls (grün, „+ b mm Beschnitt“) – kein Gelb nur wegen fehlender TrimBox.
// Größerer Beschnitt als das Soll ist in Ordnung. grün ≤ 1 mm, gelb 1–3 mm, rot > 3 mm („trotzdem verwenden“).
// $pdf = etp_pdf_lesen() (Seitenboxen); $ist = Rückfall aus pdf_etikett_mass(), wenn keine Boxen gelesen wurden.
// Rückgabe zusätzlich 'beschnitt' (gemessen bzw. abgeleitet, mm) für die Beschnitt-Zeile.
function etp_mass_ampel(array $soll, ?array $ist, string $sp, array $pdf = []): array {
    $t = etp_t($sp);
    $bx = (array)($pdf['boxen'] ?? []);
    $mmB = fn(array $b) => [($b[2] - $b[0]) * 25.4 / 72, ($b[3] - $b[1]) * 25.4 / 72];
    $trim = $bx['TrimBox'] ?? ($bx['ArtBox'] ?? null);
    $aussen = $bx['BleedBox'] ?? ($bx['MediaBox'] ?? null);
    $rand = null;
    if ($trim && $aussen) $rand = min($trim[0] - $aussen[0], $trim[1] - $aussen[1], $aussen[2] - $trim[2], $aussen[3] - $trim[3]) * 25.4 / 72;
    $eigeneTrim = $trim && $rand !== null && $rand >= 0.3;
    if ($eigeneTrim) [$iw, $ih] = $mmB($trim);
    elseif ($aussen) [$iw, $ih] = $mmB($aussen);
    elseif ($trim) [$iw, $ih] = $mmB($trim);
    elseif ($ist) [$iw, $ih] = [(float)$ist['w'], (float)$ist['h']];
    else return ['ampel' => 'rot', 'text' => $t('Das Format der Datei ist nicht lesbar.', 'The format of the file cannot be read.'), 'ist' => null, 'beschnitt' => null];
    $beschnitt = $eigeneTrim ? round($rand, 1) : null;
    $sw = (float)($soll['w'] ?? 0); $sh = (float)($soll['h'] ?? 0);
    if ($sw <= 0 || $sh <= 0) {
        return ['ampel' => 'info', 'ist' => [$iw, $ih], 'beschnitt' => $beschnitt, 'text' => $soll['art'] === 'beutel'
            ? $t('Gesamtformat ' . etp_mm_label($iw, $ih) . ' (für diesen Beutel ist noch keine Vorlage hinterlegt)', 'Overall format ' . etp_mm_label($iw, $ih) . ' (no template stored for this pouch yet)')
            : $t('Format ' . etp_mm_label($iw, $ih) . ' (kein Soll-Format hinterlegt)', 'Format ' . etp_mm_label($iw, $ih) . ' (no target format stored)')];
    }
    if (($iw >= $ih) !== ($sw >= $sh)) [$iw, $ih] = [$ih, $iw];   // Ausrichtung wie das Soll
    $sollL = etp_mm_label($sw, $sh);
    $mitB = fn($b) => $b !== null && $b >= 0.3 ? $t(' + ' . etp_mm($b) . ' mm Beschnitt', ' + ' . etp_mm($b) . ' mm bleed') : '';
    $diff = max(abs($iw - $sw), abs($ih - $sh));
    if ($diff <= 1.0) return ['ampel' => 'gruen', 'ist' => [$iw, $ih], 'diff' => $diff, 'beschnitt' => $beschnitt, 'text' => $t('Format passt (', 'Format fits (') . $sollL . $mitB($beschnitt) . ')'];
    // Seite (ohne eigene TrimBox) bzw. TrimBox = Endformat + 2 × b, b zwischen Soll-Beschnitt und 5 mm, ±1 mm (AB-3223: TrimBox enthält den Beschnitt)
    {
        $b = (($iw - $sw) + ($ih - $sh)) / 4;
        $sollB = min(2.0, (float)($soll['beschnitt'] ?? 2));
        if ($b >= $sollB - 1 && $b <= 6 && max(abs($iw - 2 * $b - $sw), abs($ih - 2 * $b - $sh)) <= 1) {
            $b = round($b * 2) / 2;   // auf 0,5 mm
            return ['ampel' => 'gruen', 'ist' => [$iw, $ih], 'diff' => 0, 'beschnitt' => $b, 'text' => $t('Format passt (', 'Format fits (') . $sollL . $mitB($b) . ')'];
        }
    }
    $istL = etp_mm_label($iw, $ih);
    if ($diff <= 3.0) return ['ampel' => 'gelb', 'ist' => [$iw, $ih], 'diff' => $diff, 'beschnitt' => $beschnitt, 'text' => $t('Format weicht um ' . etp_mm($diff) . ' mm ab (' . $istL . ' statt ' . $sollL . ')', 'Format differs by ' . etp_mm($diff) . ' mm (' . $istL . ' instead of ' . $sollL . ')')];
    return ['ampel' => 'rot', 'ist' => [$iw, $ih], 'diff' => $diff, 'beschnitt' => $beschnitt, 'text' => $t('Format passt nicht (' . $istL . ' statt ' . $sollL . ')', 'Format does not fit (' . $istL . ' instead of ' . $sollL . ')')];
}

// Technischer Druck-Check (ohne KI). $pdf = etp_pdf_lesen(), $client = Messung aus dem Browser (Bilder-dpi, Text in Zonen)
// oder null (noch nicht gemessen). Rückgabe: Zeilen ['k','ampel','titel','text'].
function etp_technik_zeilen(array $soll, array $pdf, array $mass, ?array $client, string $sp): array {
    $t = etp_t($sp); $z = [];
    $z[] = ['k' => 'format', 'ampel' => $mass['ampel'], 'titel' => $t('Format', 'Format'), 'text' => $mass['text']];
    // Beschnitt
    $bx = $pdf['boxen'] ?? []; $need = (float)($soll['beschnitt'] ?? 2);
    $trim = $bx['TrimBox'] ?? ($bx['ArtBox'] ?? null); $aussen = $bx['BleedBox'] ?? ($bx['MediaBox'] ?? null);
    $rand = null;
    if ($trim && $aussen) $rand = min($trim[0] - $aussen[0], $trim[1] - $aussen[1], $aussen[2] - $trim[2], $aussen[3] - $trim[3]) * 25.4 / 72;
    if (($rand === null || $rand < 0.3) && !empty($mass['beschnitt'])) $rand = (float)$mass['beschnitt'];   // Sofortfix 13.11.: Seite = Endformat + 2 × b
    if ($rand === null || $rand < 0.3) {
        // MediaBox = Endformat + Beschnitt ohne TrimBox (siehe Maß-Ampel) zählt als „Beschnitt nicht erkennbar“
        $z[] = ['k' => 'beschnitt', 'ampel' => 'gelb', 'titel' => $t('Beschnitt', 'Bleed'),
                'text' => $t('Kein Beschnitt im PDF erkennbar – bitte ' . etp_mm($need) . ' mm Beschnitt anlegen', 'No bleed detectable in the PDF – please add ' . etp_mm($need) . ' mm bleed')];
    } elseif ($rand + 0.25 < $need) {
        $z[] = ['k' => 'beschnitt', 'ampel' => 'gelb', 'titel' => $t('Beschnitt', 'Bleed'),
                'text' => $t('Beschnitt nur ' . etp_mm($rand) . ' mm – bitte ' . etp_mm($need) . ' mm anlegen', 'Bleed only ' . etp_mm($rand) . ' mm – please add ' . etp_mm($need) . ' mm')];
    } else {
        $z[] = ['k' => 'beschnitt', 'ampel' => 'gruen', 'titel' => $t('Beschnitt', 'Bleed'), 'text' => $t('Beschnitt ' . etp_mm($rand) . ' mm vorhanden', 'Bleed of ' . etp_mm($rand) . ' mm present')];
    }
    // Bildauflösung (gemessen im Browser in Druckgröße)
    if ($client === null) {
        $z[] = ['k' => 'bilder', 'ampel' => 'info', 'titel' => $t('Bildauflösung', 'Image resolution'), 'text' => $t('wird geprüft …', 'checking …')];
    } elseif (empty($client['pdfjs'])) {
        $z[] = ['k' => 'bilder', 'ampel' => 'info', 'titel' => $t('Bildauflösung', 'Image resolution'), 'text' => $t('Bildauflösung konnte nicht gemessen werden.', 'Image resolution could not be measured.')];
    } else {
        $bilder = array_values(array_filter((array)($client['bilder'] ?? []), fn($b) => (float)($b['b_mm'] ?? 0) >= 3 && (float)($b['h_mm'] ?? 0) >= 3 && (float)($b['dpi'] ?? 0) > 0));
        if (!$bilder) {
            $z[] = ['k' => 'bilder', 'ampel' => 'gruen', 'titel' => $t('Bildauflösung', 'Image resolution'), 'text' => $t('Keine Bilder – nur Vektorgrafik und Text', 'No images – vector graphics and text only')];
        } else {
            $niedrig = array_values(array_filter($bilder, fn($b) => (float)$b['dpi'] < 300));
            usort($niedrig, fn($a, $b) => $a['dpi'] <=> $b['dpi']);
            $beschr = function (array $b) use ($t): string {
                return $t('Bild ', 'Image ') . (int)$b['n'] . ' (' . (int)$b['w'] . ' × ' . (int)$b['h'] . ' px, ' . etp_mm($b['b_mm']) . ' × ' . etp_mm($b['h_mm']) . ' mm, '
                     . $t('bei ', 'at ') . etp_mm($b['x_mm']) . ' / ' . etp_mm($b['y_mm']) . ' mm): ' . (int)round((float)$b['dpi']) . ' dpi';
            };
            if (!$niedrig) {
                $z[] = ['k' => 'bilder', 'ampel' => 'gruen', 'titel' => $t('Bildauflösung', 'Image resolution'),
                        'text' => $t('Alle Bilder mindestens 300 dpi (' . count($bilder) . ' Bild' . (count($bilder) === 1 ? '' : 'er') . ')', 'All images at least 300 dpi (' . count($bilder) . ' image' . (count($bilder) === 1 ? '' : 's') . ')')];
            } else {
                // Sofortfix 13.11.2026 (Nico): Auflösung nie rot – unter 300 dpi immer gelb (Hinweis), kurzer Text ohne Positionsangaben,
                // die Liste je Bild nur aufklappbar unter „Details“ ('details'). Zählt weder für die Freigabe noch für „x Abweichungen“.
                $unter150 = array_values(array_filter($niedrig, fn($b) => (float)$b['dpi'] < 150));
                $grenze = $unter150 ? 150 : 300; $n = $unter150 ? count($unter150) : count($niedrig);
                $min = (int)round((float)$niedrig[0]['dpi']);
                $z[] = ['k' => 'bilder', 'ampel' => 'gelb', 'titel' => $t('Bildauflösung', 'Image resolution'),
                        'text' => $t('Hinweis: ' . $n . ' Bild' . ($n === 1 ? '' : 'er') . ' unter ' . $grenze . ' dpi (' . $min . ' dpi). Im Druck evtl. leicht unscharf. Große Hintergrundbilder sind meist unkritisch.',
                                     'Note: ' . $n . ' image' . ($n === 1 ? '' : 's') . ' below ' . $grenze . ' dpi (' . $min . ' dpi). May print slightly blurred. Large background images are usually not critical.'),
                        'details' => array_map($beschr, $niedrig)];
            }
        }
    }
    // Farbraum
    $f = $pdf['farben'] ?? [];
    $rgbBilder = count(array_filter((array)($pdf['bilder'] ?? []), fn($b) => ($b['farbe'] ?? '') === 'RGB'));
    $sonder = (array)($f['sonder'] ?? []);
    if (!empty($f['rgb'])) {
        $z[] = ['k' => 'farben', 'ampel' => 'gelb', 'titel' => $t('Farben', 'Colours'),
                'text' => $t('RGB-Farben gefunden' . ($rgbBilder ? ' (' . $rgbBilder . ' Bild' . ($rgbBilder === 1 ? '' : 'er') . ')' : '') . ' – für den Druck CMYK empfohlen', 'RGB colours found' . ($rgbBilder ? ' (' . $rgbBilder . ' image' . ($rgbBilder === 1 ? '' : 's') . ')' : '') . ' – CMYK recommended for print')
                        . ($sonder ? $t('. Sonderfarben: ', '. Spot colours: ') . implode(', ', $sonder) : '')];
    } elseif (!empty($f['cmyk']) || $sonder || !empty($f['grau'])) {
        $teile = [];
        if (!empty($f['cmyk'])) $teile[] = 'CMYK';
        if (!empty($f['grau']) && empty($f['cmyk'])) $teile[] = $t('Graustufen', 'greyscale');
        if ($sonder) $teile[] = $t('Sonderfarben: ', 'spot colours: ') . implode(', ', $sonder);
        $z[] = ['k' => 'farben', 'ampel' => 'gruen', 'titel' => $t('Farben', 'Colours'), 'text' => $t('Farben: ', 'Colours: ') . implode(' · ', $teile)];
    } else {
        $z[] = ['k' => 'farben', 'ampel' => 'info', 'titel' => $t('Farben', 'Colours'), 'text' => $t('Farbraum nicht erkennbar', 'Colour space not detectable')];
    }
    // Schriften
    $fehlt = array_values(array_map(fn($s) => $s['name'], array_filter((array)($pdf['schriften'] ?? []), fn($s) => empty($s['eingebettet']))));
    $alle = count((array)($pdf['schriften'] ?? []));
    if ($fehlt) $z[] = ['k' => 'schriften', 'ampel' => 'rot', 'titel' => $t('Schriften', 'Fonts'),
                        'text' => $t('Schrift nicht eingebettet: ' . implode(', ', array_slice($fehlt, 0, 5)) . ' – bitte einbetten oder in Pfade umwandeln', 'Font not embedded: ' . implode(', ', array_slice($fehlt, 0, 5)) . ' – please embed or convert to outlines')];
    elseif ($alle > 0) $z[] = ['k' => 'schriften', 'ampel' => 'gruen', 'titel' => $t('Schriften', 'Fonts'), 'text' => $t('Schriften eingebettet (' . $alle . ')', 'Fonts embedded (' . $alle . ')')];
    else $z[] = ['k' => 'schriften', 'ampel' => 'gruen', 'titel' => $t('Schriften', 'Fonts'), 'text' => $t('Text in Pfade umgewandelt (keine Schriften in der Datei)', 'Text converted to outlines (no fonts in the file)')];
    // Seiten
    if ((int)($pdf['seiten'] ?? 1) > 1)
        $z[] = ['k' => 'seiten', 'ampel' => 'gelb', 'titel' => $t('Seiten', 'Pages'),
                'text' => $t('Die Datei hat ' . (int)$pdf['seiten'] . ' Seiten – gedruckt wird nur Seite 1.', 'The file has ' . (int)$pdf['seiten'] . ' pages – only page 1 will be printed.')];
    // Beutel: Text in Siegelnaht/Zipper (aus der Textlage im Browser)
    if (($soll['art'] ?? '') === 'beutel') {
        if (empty($soll['vorlage'])) {
            $z[] = ['k' => 'vorlage', 'ampel' => 'info', 'titel' => $t('Vorlage', 'Template'),
                    'text' => $t('Für diesen Beutel ist noch keine Druckvorlage hinterlegt – geprüft wird nur das Gesamtformat. Wir ergänzen die Vorlage.', 'No print template stored for this pouch yet – only the overall format is checked. We will add the template.')];
        } elseif ($client !== null && !empty($client['pdfjs']) && !empty($soll['vorlage']['zonen'])) {
            $treffer = (array)($client['zonen'] ?? []);
            if ($treffer) {
                $tx = array_map(fn($r) => (string)$r['zone'] . ': „' . mb_substr((string)$r['text'], 0, 40) . '“', array_slice($treffer, 0, 3));
                $z[] = ['k' => 'zonen', 'ampel' => 'gelb', 'titel' => $t('Siegel/Zipper', 'Seal/zipper'),
                        'text' => $t('Text in einer Zone ohne Text: ', 'Text in a no-text zone: ') . implode('; ', $tx)];
            } else {
                $z[] = ['k' => 'zonen', 'ampel' => 'gruen', 'titel' => $t('Siegel/Zipper', 'Seal/zipper'), 'text' => $t('Kein Text in Siegelnähten und Zipper', 'No text in seals and zipper')];
            }
        }
    }
    return $z;
}

// ---------------------------------------------------------------------------------------------------------------
// Prüfungen (Versionen) eines Auftrags
function etp_pruefung(int $id): ?array { etp_schema(); return one("SELECT * FROM etikett_pruefung WHERE id=?", [$id]) ?: null; }
function etp_verlauf(int $aid): array { etp_schema(); return all("SELECT * FROM etikett_pruefung WHERE auftrag_id=? ORDER BY id DESC", [$aid]); }
// Aktuelle Prüfung zur geltenden Etikett-Datei (eigene oder geerbte). Fehlt sie (Datei aus der Zeit vor dem Pop-up oder geerbt),
// wird sie jetzt angelegt – so hat jede Datei ihren Druck-Check.
function etp_aktuell(int $aid, bool $anlegen = true): ?array {
    etp_schema();
    $dok = etikett_datei_fuer_auftrag($aid);
    if (!$dok || empty($dok['datei_da'])) return null;
    $p = one("SELECT * FROM etikett_pruefung WHERE auftrag_id=? AND dokument_id=? ORDER BY id DESC LIMIT 1", [$aid, (int)$dok['id']]);
    if ($p) return $p;
    // nach der Freigabe einer geerbten Datei zeigt etikett_datei() die Kopie – die Prüfung hängt dann noch am Original
    $p = one("SELECT * FROM etikett_pruefung WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$aid]);
    if ($p && $p['status'] === 'freigegeben' && etikett_freigegeben($aid)) { q("UPDATE etikett_pruefung SET dokument_id=? WHERE id=?", [(int)$dok['id'], (int)$p['id']]); return etp_pruefung((int)$p['id']); }
    if (!$anlegen) return null;
    $pid = etp_anlegen($aid, $dok, 'kunde', !empty($dok['alt']) ? 'aus früherem Auftrag' : '');
    if (etikett_freigegeben($aid)) {
        $a = one("SELECT etikett_freigabe_am, etikett_freigabe_von FROM auftrag WHERE id=?", [$aid]);
        q("UPDATE etikett_pruefung SET status='freigegeben', freigabe_name=?, freigabe_am=? WHERE id=?", [$a['etikett_freigabe_von'] ?? null, $a['etikett_freigabe_am'] ?? null, $pid]);
    }
    return etp_pruefung($pid);
}
function etp_anlegen(int $aid, array $dok, string $akteur, string $nutzer): int {
    etp_schema();
    $pfad = BX_UPLOADS . '/' . basename((string)$dok['datei']);
    $soll = etp_soll($aid);
    $sp = etp_sprache((int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid]));
    $istPdf = strtolower(pathinfo($pfad, PATHINFO_EXTENSION)) === 'pdf';
    $pdf = $istPdf ? etp_pdf_lesen($pfad) : ['ok' => false];
    $ist = $istPdf ? pdf_etikett_mass($pfad) : null;
    $mass = etp_mass_ampel($soll, $ist, $sp, (array)$pdf);
    $version = 1 + (int) scalar("SELECT COUNT(*) FROM etikett_pruefung WHERE auftrag_id=?", [$aid]);
    q("INSERT INTO etikett_pruefung (auftrag_id, dokument_id, version, art, datei, datei_orig, soll_w, soll_h, ist_w, ist_h, mass_ampel, technik, status, hochgeladen_von, hochgeladen_nutzer, angelegt)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'wartet', ?,?, UTC_TIMESTAMP())",
      [$aid, (int)$dok['id'], $version, $soll['art'], (string)$dok['datei'], (string)($dok['datei_orig'] ?: $dok['datei']),
       $soll['w'], $soll['h'], $mass['ist'][0] ?? null, $mass['ist'][1] ?? null, $mass['ampel'],
       json_encode(['pdf' => $pdf, 'client' => null, 'mass_quelle' => $ist['quelle'] ?? null], JSON_UNESCAPED_UNICODE), $akteur, mb_substr($nutzer, 0, 190)]);
    return (int) insert_id();
}
// Hochladen (Kunde oder Team) – nur PDF. Rückgabe ['ok'=>bool,'fehler'=>?,'pid'=>?].
function etp_hochladen(int $aid, string $feld, string $akteur, string $nutzer, string $sp): array {
    etp_schema();
    $t = etp_t($sp);
    if (etikett_bestellt_fuer_auftrag($aid)) return ['ok' => false, 'fehler' => $t('Das Etikett ist schon bestellt und kann nicht mehr geändert werden. Bitte sprechen Sie uns an.', 'The label has already been ordered and can no longer be changed. Please contact us.')];
    $f = $_FILES[$feld] ?? null;
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if (!$f || $err === UPLOAD_ERR_NO_FILE) return ['ok' => false, 'fehler' => $t('Bitte eine Datei wählen.', 'Please choose a file.')];
    if (in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) return ['ok' => false, 'fehler' => $t('Die Datei ist zu groß.', 'The file is too large.')];
    if ($err !== UPLOAD_ERR_OK) return ['ok' => false, 'fehler' => $t('Hochladen fehlgeschlagen – bitte erneut versuchen.', 'Upload failed – please try again.')];
    $kopf = (string) @file_get_contents((string)$f['tmp_name'], false, null, 0, 1024);
    if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'pdf' || strpos($kopf, '%PDF-') === false)
        return ['ok' => false, 'fehler' => $t('Bitte die Druckdatei als PDF hochladen.', 'Please upload the print file as a PDF.')];
    if (!etikett_upload($aid, $feld)) return ['ok' => false, 'fehler' => $t('Hochladen fehlgeschlagen – bitte erneut versuchen.', 'Upload failed – please try again.')];
    q("UPDATE etikett_pruefung SET status='ersetzt' WHERE auftrag_id=? AND status='wartet'", [$aid]);
    $dok = etikett_datei($aid);
    $pid = etp_anlegen($aid, $dok, $akteur, $nutzer);
    $kid = (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid]);
    $nr = (string) scalar("SELECT nummer FROM auftrag WHERE id=?", [$aid]);
    log_aktivitaet('kunde', $kid, $akteur === 'team' ? 'team' : 'kunde', auftrag_design_label($aid) . ' hochgeladen (Auftrag ' . $nr . ', Version ' . (int) scalar("SELECT version FROM etikett_pruefung WHERE id=?", [$pid]) . ')' . ($akteur === 'team' ? ' vom Team' : '') . ' – wartet auf Freigabe.', 'etikett', 'auftrag', $aid);
    return ['ok' => true, 'pid' => $pid];
}
// „Erneut prüfen“ (Sofortfix 13.11.2026): Druck-Check (PDF-Leser, Maß-Ampel) und KI für die aktuelle Datei neu – ohne neuen Upload.
// Die Browser-Messung (dpi) und der KI-Check starten danach im Pop-up automatisch neu (client/ki leer).
function etp_neu_pruefen(int $pid): void {
    $p = etp_pruefung($pid); if (!$p) return;
    $aid = (int)$p['auftrag_id'];
    $pfad = BX_UPLOADS . '/' . basename((string)$p['datei']);
    $istPdf = strtolower(pathinfo($pfad, PATHINFO_EXTENSION)) === 'pdf';
    $pdf = $istPdf ? etp_pdf_lesen($pfad) : ['ok' => false];
    $ist = $istPdf ? pdf_etikett_mass($pfad) : null;
    $soll = etp_soll($aid);
    $mass = etp_mass_ampel($soll, $ist, etp_sprache((int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid])), (array)$pdf);
    q("UPDATE etikett_pruefung SET soll_w=?, soll_h=?, ist_w=?, ist_h=?, mass_ampel=?, technik=?, ki=NULL, ki_status=NULL, ki_zusammenfassung=NULL, ki_am=NULL WHERE id=?",
      [$soll['w'], $soll['h'], $mass['ist'][0] ?? null, $mass['ist'][1] ?? null, $mass['ampel'], json_encode(['pdf' => $pdf, 'client' => null, 'mass_quelle' => $ist['quelle'] ?? null], JSON_UNESCAPED_UNICODE), $pid]);
    log_aktivitaet('auftrag', $aid, 'system', 'Etikett-Check Version ' . (int)$p['version'] . ' erneut geprüft.', 'etikett', 'auftrag', $aid);
}
// Messung aus dem Browser (pdf.js) übernehmen: Bilder mit dpi, Text in Siegel-/Zipper-Zonen.
function etp_client_speichern(int $pid, array $c): void {
    $p = etp_pruefung($pid); if (!$p) return;
    $tech = json_decode((string)$p['technik'], true) ?: [];
    $bilder = [];
    foreach (array_slice((array)($c['bilder'] ?? []), 0, 200) as $b) {
        if (!is_array($b)) continue;
        $bilder[] = ['n' => (int)($b['n'] ?? 0), 'w' => (int)($b['w'] ?? 0), 'h' => (int)($b['h'] ?? 0), 'dpi' => round((float)($b['dpi'] ?? 0), 1),
                     'x_mm' => round((float)($b['x_mm'] ?? 0), 1), 'y_mm' => round((float)($b['y_mm'] ?? 0), 1), 'b_mm' => round((float)($b['b_mm'] ?? 0), 1), 'h_mm' => round((float)($b['h_mm'] ?? 0), 1)];
    }
    $zonen = [];
    foreach (array_slice((array)($c['zonen'] ?? []), 0, 50) as $zz) if (is_array($zz)) $zonen[] = ['zone' => mb_substr((string)($zz['zone'] ?? ''), 0, 60), 'text' => mb_substr((string)($zz['text'] ?? ''), 0, 120)];
    $tech['client'] = ['pdfjs' => !empty($c['pdfjs']), 'bilder' => $bilder, 'zonen' => $zonen];
    q("UPDATE etikett_pruefung SET technik=? WHERE id=?", [json_encode($tech, JSON_UNESCAPED_UNICODE), $pid]);
}

// ---------------------------------------------------------------------------------------------------------------
// KI-Etikettencheck. Der Browser rendert die Druckdatei hochauflösend (je Fläche ein oder zwei Ausschnitte, gedrehte Flächen richtig
// herum) und schickt die Bilder; hier gehen sie mit dem PIB des Auftrags (PDF) und den Rezepturdaten an Claude.
function etp_ki_punkte(string $sp): array {
    $t = etp_t($sp);
    return [
        'produktname'      => [$t('Produktname und „Nahrungsergänzungsmittel“', 'Product name and “food supplement”'), 'Produktname wie im PIB und die Verkehrsbezeichnung „Nahrungsergänzungsmittel“ (bzw. in der Etikettsprache) sind vorhanden.'],
        'zutaten'          => [$t('Zutatenliste (Reihenfolge, Allergene)', 'Ingredients (order, allergens)'), 'Zutatenliste vollständig, absteigend nach Gewicht wie im PIB, Allergene hervorgehoben (fett o. ä.), Kapselhülle genannt.'],
        'naehrstoffe'      => [$t('Nährstoffe je Tagesdosis und %NRV', 'Nutrients per daily dose and %NRV'), 'Die Tagesdosis des Etiketts gilt (der Kunde darf eine andere Tagesdosis als der PIB-Standard wählen). PIB-Werte je 1 Einheit × Anzahl Einheiten laut Etikett mit der Tabelle vergleichen. Erlaubt: Rundung wie auf dem Etikett, Mengen ±5 %, %NRV ±2 Prozentpunkte. Rot nur bei Abweichung außerhalb dieser Grenzen.'],
        'verzehr'          => [$t('Verzehrempfehlung / Tagesdosis', 'Recommended daily intake'), 'Grün, wenn die Verzehrempfehlung eine Anzahl Einheiten je Tag nennt und die Tabelle dieselbe Tagesdosis nutzt – auch wenn sie vom PIB-Standard abweicht (dann Zusatz „Tagesdosis laut Etikett …, Werte umgerechnet“). Rot nur, wenn Verzehrempfehlung und Tabelle auf dem Etikett nicht zusammenpassen.'],
        'warnhinweise'     => [$t('Pflicht-Warnhinweise', 'Mandatory warnings'), 'Pflichthinweise NemV: empfohlene Tagesdosis nicht überschreiten; kein Ersatz für eine ausgewogene, abwechslungsreiche Ernährung und gesunde Lebensweise; außerhalb der Reichweite von kleinen Kindern lagern. Plus produktspezifische Warnhinweise aus dem PIB.'],
        'fuellmenge'       => [$t('Füllmenge / Anzahl Kapseln', 'Net quantity / number of capsules'), 'Füllmenge bzw. Anzahl Einheiten und ggf. Nettogewicht passen zum PIB.'],
        'inverkehrbringer' => [$t('Inverkehrbringer mit Anschrift', 'Food business operator with address'), 'Name und vollständige Anschrift des Inverkehrbringers stehen auf dem Etikett.'],
        'inverkehrbringer_eu' => [$t('Inverkehrbringer in der EU / passt zum Kunden', 'Operator in the EU / matches the customer'), 'Liegt die Anschrift außerhalb der EU (z. B. Schweiz) oder passt der Inverkehrbringer nicht zum Kunden (Firma/Anschrift unten)? Dann gelb mit Hinweis.'],
        'mhd_charge'       => [$t('Feld für MHD und Charge', 'Space for best-before date and batch'), 'Es gibt ein erkennbares Feld bzw. einen freien Platz für Mindesthaltbarkeitsdatum und Chargennummer (wird beim Abfüllen aufgedruckt).'],
        'abgeschnitten'    => [$t('Vollständige Sätze', 'Complete sentences'), 'Kein Satz bricht mitten ab oder wird vom Rand bzw. von anderen Elementen abgeschnitten; kein Text ragt in den Beschnitt.'],
        'aussagen'         => [$t('Gesundheitsaussagen / Superlative', 'Health claims / superlatives'), 'Gesundheitsbezogene Aussagen (z. B. „unterstützt die Muskelkraft“) und Superlative („das reinste“, „das beste“). NUR Hinweis: höchstens gelb, nie rot.'],
    ];
}
function etp_rezeptur_text(int $aid): string {
    $a = one("SELECT a.produkt_id, a.menge, COALESCE(a.rezeptur_id, p.rezeptur_id) AS rid, p.einheiten_pro_packung, p.einnahme_pro_tag, p.allergene, r.darreichungsform
              FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN rezeptur r ON r.id=COALESCE(a.rezeptur_id, p.rezeptur_id) WHERE a.id=?", [$aid]);
    if (!$a) return '';
    $pib = etp_pib_je_einheit($aid);
    $z = ['Darreichungsform: ' . ($a['darreichungsform'] ?: '-'), 'Füllmenge laut Auftrag (unabhängig von der Tagesdosis prüfen): ' . (float)($a['einheiten_pro_packung'] ?? 0) . ' ' . $pib['einheit_mz'] . ' je Packung',
          'PIB-Standard-Tagesdosis (nur Vergleich, keine Vorgabe): ' . $pib['standard'] . ' ' . ($pib['standard'] == 1 ? $pib['einheit'] : $pib['einheit_mz'])];
    if (trim((string)($a['allergene'] ?? '')) !== '') $z[] = 'Allergene laut Produkt: ' . $a['allergene'];
    if ($pib['werte']) {
        $z[] = 'PIB-Werte je 1 ' . $pib['einheit'] . ':';
        foreach ($pib['werte'] as $w) $z[] = '- ' . $w['name'] . ': ' . rtrim(rtrim(number_format($w['mg'] * ($w['einheit'] === 'µg' ? 1000 : 1), 4, ',', ''), '0'), ',') . ' ' . $w['einheit'] . ($w['nrv_pct'] !== null ? ' (' . number_format($w['nrv_pct'], 1, ',', '') . ' % NRV)' : '');
    }
    if (!empty($a['rid'])) {
        $z[] = 'Zutaten je ' . $pib['einheit'] . ' (absteigend):';
        foreach (all("SELECT COALESCE(i.name, rz.bezeichnung) AS name, rz.menge_mg FROM rezeptur_zutat rz LEFT JOIN item i ON i.id=rz.item_id WHERE rz.rezeptur_id=? ORDER BY rz.menge_mg DESC", [(int)$a['rid']]) as $r)
            $z[] = '- ' . $r['name'] . ': ' . rtrim(rtrim(number_format((float)$r['menge_mg'], 3, ',', ''), '0'), ',') . ' mg';
    }
    return implode("\n", $z);
}
// PIB-Werte je 1 Einheit (Kapsel, Tablette, Portion) und die PIB-Standard-Tagesdosis eines Auftrags (Sofortfix 13.11.2026).
// Pulver/Granulat: die Rezeptur ist eine Portion (Tagesdosis), Standard 1.
function etp_pib_je_einheit(int $aid): array {
    $a = one("SELECT COALESCE(a.rezeptur_id, p.rezeptur_id) AS rid, p.einnahme_pro_tag, r.darreichungsform FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id
              LEFT JOIN rezeptur r ON r.id=COALESCE(a.rezeptur_id, p.rezeptur_id) WHERE a.id=?", [$aid]) ?: [];
    $form = (string)($a['darreichungsform'] ?? '');
    $td = function_exists('form_ist_tagesdosis') && form_ist_tagesdosis($form);
    [$e, $mz] = match (true) { in_array($form, ['kapsel', 'softgel'], true) => ['Kapsel', 'Kapseln'], $form === 'tablette' => ['Tablette', 'Tabletten'], $td => ['Portion', 'Portionen'], default => ['Einheit', 'Einheiten'] };
    $werte = [];
    if (!empty($a['rid'])) {
        if (!function_exists('pib_naehr')) require_once __DIR__ . '/pdf_pib.php';
        foreach (pib_naehr((int)$a['rid']) as $n) {
            $nrvMg = ($n['nrv'] === null || $n['nrv'] === '') ? 0 : ((string)$n['einheit'] === 'µg' ? (float)$n['nrv'] / 1000 : (float)$n['nrv']);
            $werte[] = ['name' => (string)$n['name'], 'mg' => (float)$n['mg'], 'einheit' => (string)$n['einheit'], 'nrv_mg' => $nrvMg, 'nrv_pct' => $nrvMg > 0 ? (float)$n['mg'] / $nrvMg * 100 : null];
        }
    }
    $std = $td ? 1 : max(1, (int) round((float)($a['einnahme_pro_tag'] ?? 1)));
    return ['werte' => $werte, 'standard' => $std, 'einheit' => $e, 'einheit_mz' => $mz];
}
// Nachrechnung im Server (Sofortfix 13.11.2026): Die KI liest Tagesdosis und Tabellenwerte vom Etikett ('tagesdosis', 'naehrwerte');
// der Server rechnet PIB je Einheit × Tagesdosis des Etiketts und bewertet mit Toleranz (Rundung wie gezeigt, Mengen ±5 %,
// %NRV ±2 Prozentpunkte). Überschreibt die Punkte 'verzehr' und 'naehrstoffe', wenn die KI die Angaben geliefert hat.
// Höchstmengen/UL sind im System nicht hinterlegt – dieser Punkt entfällt.
function etp_ki_nachpruefen(array $daten, int $aid, string $sp): array {
    $t = etp_t($sp);
    $td = (array)($daten['tagesdosis'] ?? []);
    $nText = isset($td['verzehr_einheiten']) && is_numeric($td['verzehr_einheiten']) ? (float)$td['verzehr_einheiten'] : null;
    $nTab  = isset($td['tabelle_einheiten']) && is_numeric($td['tabelle_einheiten']) ? (float)$td['tabelle_einheiten'] : null;
    if ($nText === null && $nTab === null) return $daten;
    $pib = etp_pib_je_einheit($aid);
    $n = $nTab ?? $nText;
    $eLbl = fn($x) => etp_mm($x) . ' ' . ($x == 1 ? $pib['einheit'] : $pib['einheit_mz']);
    $set = function (string $key, string $ampel, string $text) use (&$daten) {
        $gefunden = false;
        foreach ((array)($daten['punkte'] ?? []) as $i => $p) if (($p['key'] ?? '') === $key) { $daten['punkte'][$i]['ampel'] = $ampel; $daten['punkte'][$i]['begruendung'] = $text; $gefunden = true; }
        if (!$gefunden) $daten['punkte'][] = ['key' => $key, 'ampel' => $ampel, 'begruendung' => $text];
    };
    // Verzehrempfehlung / Tagesdosis
    if ($nText !== null && $nTab !== null && abs($nText - $nTab) > 0.01) {
        $set('verzehr', 'rot', $t('Verzehrempfehlung nennt ' . $eLbl($nText) . ', die Tabelle ist auf ' . $eLbl($nTab) . ' bezogen – das passt auf dem Etikett nicht zusammen.',
                                  'The recommended intake says ' . etp_mm($nText) . ', the table refers to ' . etp_mm($nTab) . ' – these do not match on the label.'));
    } else {
        $txt = $t('Verzehrempfehlung nennt ' . $eLbl($n) . ' je Tag, die Tabelle nutzt dieselbe Tagesdosis.', 'The recommended intake states ' . etp_mm($n) . ' per day, the table uses the same daily dose.');
        if (abs($n - $pib['standard']) > 0.01)
            $txt .= ' ' . $t('Tagesdosis laut Etikett ' . $eLbl($n) . ' (PIB-Standard ' . $eLbl($pib['standard']) . '), Werte auf ' . $eLbl($n) . ' umgerechnet und geprüft.',
                             'Daily dose on the label ' . etp_mm($n) . ' (PIB standard ' . etp_mm($pib['standard']) . '), values converted to ' . etp_mm($n) . ' and checked.');
        $set('verzehr', 'gruen', $txt);
    }
    // Nährstoffe: Etikett gegen PIB je Einheit × n
    $tab = array_values(array_filter((array)($daten['naehrwerte'] ?? []), 'is_array'));
    if ($tab && $pib['werte']) {
        $norm = fn($s) => preg_replace('/[^a-z0-9]/', '', strtolower(strtr((string)$s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'])));
        $inMg = function ($wert, string $e): ?float {
            $e = strtolower(trim($e)); $v = (float) str_replace(',', '.', (string)$wert);
            return match ($e) { 'mg' => $v, 'g' => $v * 1000, 'µg', 'ug', 'mcg' => $v / 1000, default => null };
        };
        $stellen = fn($wert) => ($p = strpos(str_replace(',', '.', (string)$wert), '.')) === false ? 0 : strlen(str_replace(',', '.', (string)$wert)) - $p - 1;
        $fehler = []; $ok = 0;
        foreach ($pib['werte'] as $w) {
            $treffer = null;
            foreach ($tab as $z) { $a = $norm($z['name'] ?? ''); $b = $norm($w['name']); if ($a !== '' && (str_contains($a, $b) || str_contains($b, $a))) { $treffer = $z; break; } }
            if (!$treffer) continue;
            $soll = $w['mg'] * $n;
            $istMg = $inMg($treffer['menge'] ?? null, (string)($treffer['einheit'] ?? 'mg'));
            if ($istMg !== null && $soll > 0) {
                $faktor = $istMg > 0 ? $istMg / (float) str_replace(',', '.', (string)$treffer['menge']) : 1;   // mg je angezeigter Einheit
                $rundTol = 0.5 * pow(10, -$stellen($treffer['menge'])) * $faktor;
                if (abs($istMg - $soll) > max(0.05 * $soll, $rundTol + 1e-9))
                    $fehler[] = $w['name'] . ': ' . $t('Etikett ', 'label ') . $treffer['menge'] . ' ' . $treffer['einheit'] . ', ' . $t('erwartet ', 'expected ') . rtrim(rtrim(number_format($soll * ($w['einheit'] === 'µg' ? 1000 : 1), 3, ',', ''), '0'), ',') . ' ' . $w['einheit'];
            }
            if (isset($treffer['nrv_prozent']) && is_numeric($treffer['nrv_prozent']) && $w['nrv_pct'] !== null && abs((float)$treffer['nrv_prozent'] - $w['nrv_pct'] * $n) > 2)
                $fehler[] = $w['name'] . ': ' . $t('%NRV Etikett ', '%NRV label ') . $treffer['nrv_prozent'] . ' %, ' . $t('erwartet ', 'expected ') . number_format($w['nrv_pct'] * $n, 0, ',', '') . ' %';
            $ok++;
        }
        if ($fehler) $set('naehrstoffe', 'rot', $t('Abweichung zum PIB (auf ' . $eLbl($n) . ' umgerechnet): ', 'Deviation from the PIB (converted to ' . etp_mm($n) . '): ') . implode('; ', $fehler) . '.');
        elseif ($ok > 0) $set('naehrstoffe', 'gruen', $t('Mengen und %NRV passen zur Tagesdosis von ' . $eLbl($n) . ' (PIB je ' . $pib['einheit'] . ' × ' . etp_mm($n) . ', Rundung wie auf dem Etikett).',
                                                        'Amounts and %NRV match the daily dose of ' . etp_mm($n) . ' (PIB per unit × ' . etp_mm($n) . ', rounding as shown).'));
    }
    return $daten;
}
function etp_ki_auswerten(array $daten, string $sp): array {
    $t = etp_t($sp);
    $def = etp_ki_punkte($sp);
    $roh = [];
    foreach ((array)($daten['punkte'] ?? []) as $p) if (is_array($p) && isset($p['key'])) $roh[(string)$p['key']] = $p;
    $punkte = [];
    foreach ($def as $k => [$titel]) {
        $p = $roh[$k] ?? null;
        $amp = in_array(($p['ampel'] ?? ''), ['gruen', 'gelb', 'rot'], true) ? $p['ampel'] : 'info';
        if ($k === 'aussagen' && $amp === 'rot') $amp = 'gelb';        // nur Hinweis
        $punkte[] = ['key' => $k, 'titel' => $titel, 'ampel' => $amp, 'text' => mb_substr(trim((string)($p['begruendung'] ?? ($p ? '' : $t('nicht bewertet', 'not assessed')))), 0, 600)];
    }
    $z = etp_ki_zus($punkte, $sp);
    return ['punkte' => $punkte, 'zusammenfassung' => $z['text'], 'rot' => $z['rot'], 'gelb' => $z['gelb'], 'gruen' => $z['gruen']];
}
// „9 von 11 Punkten passen · 1 Hinweis · 1 Abweichung“ in der gewünschten Sprache
function etp_ki_zus(array $punkte, string $sp): array {
    $t = etp_t($sp);
    $n = count($punkte);
    $gruen = count(array_filter($punkte, fn($p) => ($p['ampel'] ?? '') === 'gruen'));
    $gelb  = count(array_filter($punkte, fn($p) => ($p['ampel'] ?? '') === 'gelb'));
    $rot   = count(array_filter($punkte, fn($p) => ($p['ampel'] ?? '') === 'rot'));
    $zus = $t($gruen . ' von ' . $n . ' Punkten passen', $gruen . ' of ' . $n . ' points fit');
    if ($gelb) $zus .= ' · ' . $gelb . ' ' . $t($gelb === 1 ? 'Hinweis' : 'Hinweise', $gelb === 1 ? 'note' : 'notes');
    if ($rot)  $zus .= ' · ' . $rot . ' ' . $t($rot === 1 ? 'Abweichung' : 'Abweichungen', $rot === 1 ? 'deviation' : 'deviations');
    return ['text' => $zus, 'gruen' => $gruen, 'gelb' => $gelb, 'rot' => $rot];
}
// Bilder: [['titel'=>'Vorderseite (1/2)','daten'=>'data:image/jpeg;base64,…'], …]. Rückgabe = gespeicherter KI-Stand.
function etp_ki_pruefen(int $pid, array $bilder): array {
    $p = etp_pruefung($pid);
    if (!$p) return ['status' => 'nicht_moeglich'];
    $aid = (int)$p['auftrag_id'];
    $a = etp_auftrag($aid) ?: [];
    $kid = (int)($a['kunde_id'] ?? 0);
    $sp = etp_sprache($kid); $t = etp_t($sp);
    q("UPDATE etikett_pruefung SET ki_status='laeuft', ki_am=UTC_TIMESTAMP() WHERE id=?", [$pid]);
    $fehler = function (string $grund) use ($pid, $t): array {
        $ki = ['fehler' => $grund];
        q("UPDATE etikett_pruefung SET ki_status='nicht_moeglich', ki=?, ki_zusammenfassung=?, ki_am=UTC_TIMESTAMP() WHERE id=?",
          [json_encode($ki, JSON_UNESCAPED_UNICODE), $t('Prüfung nicht möglich', 'Check not possible'), $pid]);
        return etp_ki_stand(etp_pruefung($pid));
    };
    // Bilder prüfen (nur JPEG/PNG, je höchstens 5 MB, höchstens 12)
    $inhalt = [];
    $nr = 0;
    foreach (array_slice($bilder, 0, 12) as $b) {
        if (!is_array($b) || !preg_match('#^data:image/(jpeg|png);base64,(.+)$#s', (string)($b['daten'] ?? ''), $m)) continue;
        $roh = base64_decode($m[2], true);
        if ($roh === false || strlen($roh) > 5 * 1024 * 1024) continue;
        if (!(str_starts_with($roh, "\xFF\xD8") || str_starts_with($roh, "\x89PNG"))) continue;
        $nr++;
        $inhalt[] = ['type' => 'text', 'text' => 'Bild ' . $nr . ': ' . mb_substr(trim((string)($b['titel'] ?? 'Etikett')), 0, 80)];
        $inhalt[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/' . $m[1], 'data' => base64_encode($roh)]];
    }
    if ($nr === 0) return $fehler('Keine Bilder der Druckdatei erhalten.');
    // Testkopie ohne KI-Schlüssel: eingespielte Antwort aus data/etp_ki_test.json (nur dort vorhanden, nie im Repo und nie live).
    $test = defined('BX_DATA') ? BX_DATA . '/etp_ki_test.json' : '';
    if (!function_exists('ki_bereit')) require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) {
        if ($test !== '' && is_file($test) && is_array($daten = json_decode((string) file_get_contents($test), true))) {
            $r = ['ok' => true, 'daten' => $daten, 'test' => true];
            // Testkopie: die Bilder, die an die KI gingen, zum Nachsehen ablegen (data/etp_ki_bilder/)
            @mkdir(BX_DATA . '/etp_ki_bilder', 0775, true);
            foreach ($inhalt as $i => $blk) if (($blk['type'] ?? '') === 'image') @file_put_contents(BX_DATA . '/etp_ki_bilder/p' . $pid . '_' . $i . '.jpg', base64_decode($blk['source']['data']));
        } else return $fehler('KI nicht eingerichtet.');
    } else {
        // PIB des Auftrags (PDF) mitgeben
        if (!empty($a['produkt_id'])) {
            if (!function_exists('pib_pdf_bauen')) require_once __DIR__ . '/pdf_pib.php';
            try { $pib = pib_pdf_bauen((int)$a['produkt_id'], null, null, $kid ?: null); } catch (Throwable $e) { $pib = null; }
            if ($pib) array_unshift($inhalt, ['type' => 'text', 'text' => 'PIB (Produktinformationsblatt) des Auftrags – Grundlage für den Abgleich:'],
                                             ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($pib)]]);
        }
        $k = one("SELECT firma, strasse, plz, ort, land FROM kunden WHERE id=?", [$kid]) ?: [];
        $punkte = '';
        foreach (etp_ki_punkte('de') as $key => [$titel, $was]) $punkte .= '- ' . $key . ' (' . $titel . '): ' . $was . "\n";
        $art = ($p['art'] ?? '') === 'beutel' ? 'die Druckdatei eines bedruckten Standbodenbeutels (Flächen einzeln als Bilder)' : 'die Druckdatei eines Etiketts';
        $anw = "Du prüfst " . $art . " für ein Nahrungsergänzungsmittel (EU, NemV, LMIV) – Produkt: " . ($a['produkt'] ?? '') . ", Auftrag " . ($a['nummer'] ?? '') . ".\n"
             . "Vergleiche den Etikett-Text auf den Bildern mit dem PIB (PDF) und diesen Rezepturdaten:\n" . etp_rezeptur_text($aid) . "\n\n"
             . "Kunde (Auftraggeber): " . trim(($k['firma'] ?? '') . ', ' . ($k['strasse'] ?? '') . ', ' . ($k['plz'] ?? '') . ' ' . ($k['ort'] ?? '') . ', ' . ($k['land'] ?? '')) . "\n\n"
             . "Prüfpunkte (jeden genau einmal bewerten):\n" . $punkte . "\n"
             . "Ampel: gruen = passt; gelb = Hinweis/unklar/fehlt teilweise; rot = klare Abweichung zum PIB oder Pflichtangabe fehlt. "
             . "Die Tagesdosis des Etiketts gilt, nicht der PIB-Standard: lies sie aus der Verzehrempfehlung und aus dem Tabellenkopf (z. B. „pro Tagesdosis (2 Kapseln)“) und rechne die PIB-Werte je Einheit auf diese Dosis um. "
             . "Toleranz: Rundung wie auf dem Etikett, Mengen ±5 %, %NRV ±2 Prozentpunkte. Füllmenge immer gegen den Auftrag prüfen. "
             . "Begründung kurz und konkret (max. 2 Sätze), zitiere die betroffene Stelle in Anführungszeichen. Erfinde nichts: was auf den Bildern nicht lesbar ist, ist gelb mit „nicht lesbar“. "
             . "Schreibe die Begründungen auf " . ($sp === 'en' ? 'Englisch' : 'Deutsch') . ".\n"
             . 'Antwortformat: {"punkte":[{"key":"produktname","ampel":"gruen|gelb|rot","begruendung":"…"}, …], '
             . '"tagesdosis":{"verzehr_einheiten":Zahl aus der Verzehrempfehlung oder null,"tabelle_einheiten":Zahl aus dem Tabellenkopf oder null}, '
             . '"naehrwerte":[{"name":"…","menge":Zahl wie auf dem Etikett,"einheit":"mg|µg|g","nrv_prozent":Zahl oder null}, …]}';
        $inhalt[] = ['type' => 'text', 'text' => $anw];
        $r = ki_json($inhalt, ['system' => 'Du bist eine sorgfältige Lebensmittelrecht-Assistenz für Etiketten von Nahrungsergänzungsmitteln. Du gibst Hinweise, keine Rechtsberatung.',
                               'max_tokens' => 4000, 'timeout' => 75, 'versuche' => 1, 'budget' => 75, 'zweck' => 'etikettcheck']);
        if (empty($r['ok'])) return $fehler((string)($r['fehler'] ?? 'KI-Fehler'));
    }
    $ausw = etp_ki_auswerten(etp_ki_nachpruefen((array)$r['daten'], $aid, $sp), $sp);   // Sofortfix 13.11.: Tagesdosis des Etiketts, Nachrechnung
    $ausw['test'] = !empty($r['test']);
    q("UPDATE etikett_pruefung SET ki_status='fertig', ki=?, ki_zusammenfassung=?, ki_am=UTC_TIMESTAMP() WHERE id=?",
      [json_encode($ausw, JSON_UNESCAPED_UNICODE), mb_substr(etp_ki_zus($ausw['punkte'], 'de')['text'], 0, 250), $pid]);
    // Rote Punkte -> Aufgabe ans Team (normal, einmal je Version)
    if ($ausw['rot'] > 0 && function_exists('aufgabe_neu')) {
        try {
            if ((int) scalar("SELECT COUNT(*) FROM aufgabe WHERE ref_typ='etikett_ki' AND ref_id=? AND status='offen'", [$aid]) === 0) {   // eine offene Aufgabe je Auftrag
                $rote = array_map(fn($x) => $x['titel'] . ': ' . $x['text'], array_filter($ausw['punkte'], fn($x) => $x['ampel'] === 'rot'));
                aufgabe_neu('Etikett ' . ($a['nummer'] ?? '') . ': KI meldet Abweichung zum PIB',
                            'KI-Etikettencheck (Version ' . (int)$p['version'] . '): ' . implode(' | ', $rote), 2, null, null, null, 'etikett_ki', $aid, '?p=auftrag&id=' . $aid . '&etikett=' . $aid);
            }
        } catch (Throwable $e) {}
    }
    return etp_ki_stand(etp_pruefung($pid));
}
function etp_ki_stand(?array $p, string $sp = ''): array {
    if (!$p) return ['status' => null];
    $ki = json_decode((string)($p['ki'] ?? ''), true) ?: [];
    // Titel in der Sprache des Betrachters (Team immer Deutsch), Begründung bleibt wie von der KI geschrieben
    if ($sp !== '' && !empty($ki['punkte'])) { $def = etp_ki_punkte($sp); foreach ($ki['punkte'] as &$pp) if (isset($def[$pp['key'] ?? ''])) $pp['titel'] = $def[$pp['key']][0]; unset($pp); }
    $st = (string)($p['ki_status'] ?? '');
    // hängengeblieben (z. B. Seite geschlossen): nach 3 Minuten gilt „nicht möglich“
    if ($st === 'laeuft' && !empty($p['ki_am']) && strtotime((string)$p['ki_am'] . ' UTC') < time() - 180) $st = 'nicht_moeglich';
    $zus = ($sp !== '' && !empty($ki['punkte'])) ? etp_ki_zus($ki['punkte'], $sp)['text'] : (string)($p['ki_zusammenfassung'] ?? '');
    if ($st === 'nicht_moeglich' && $sp !== '') $zus = etp_t($sp)('Prüfung nicht möglich', 'Check not possible');
    return ['status' => $st ?: null, 'punkte' => $ki['punkte'] ?? [], 'zusammenfassung' => $zus, 'rot' => (int)($ki['rot'] ?? 0), 'test' => !empty($ki['test'])];
}

// ---------------------------------------------------------------------------------------------------------------
// Freigabe (Kunde oder Team im Namen des Kunden). $o: trotzdem (bool), grund (Team Pflicht), nutzer, ip.
function etp_freigeben(int $aid, string $name, string $akteur, array $o, string $sp): array {
    etp_schema();
    $t = etp_t($sp);
    $name = trim($name);
    if ($name === '') return ['ok' => false, 'fehler' => $t('Bitte Ihren Namen angeben.', 'Please enter your name.')];
    if ($akteur === 'team' && trim((string)($o['grund'] ?? '')) === '') return ['ok' => false, 'fehler' => 'Bitte den Grund angeben (z. B. „Kunde hat am Telefon freigegeben“).'];
    $p = etp_aktuell($aid);
    if ($p) { $techF = json_decode((string)$p['technik'], true) ?: [];
              $p['mass_ampel'] = etp_mass_ampel(etp_soll($aid), $p['ist_w'] !== null ? ['w' => (float)$p['ist_w'], 'h' => (float)$p['ist_h']] : null, $sp, (array)($techF['pdf'] ?? []))['ampel']; }
    if ($p && $p['mass_ampel'] === 'rot' && empty($o['trotzdem']))
        return ['ok' => false, 'fehler' => $t('Das Format passt nicht. Bitte bestätigen Sie „trotzdem verwenden“ oder laden Sie eine passende Datei hoch.', 'The format does not fit. Please confirm “use anyway” or upload a matching file.')];
    $r = etikett_freigabe_setzen($aid, $name, $akteur === 'team' ? 'team' : 'kunde');
    if (empty($r['ok'])) return $r;
    $dok = etikett_datei($aid);
    if ($p) q("UPDATE etikett_pruefung SET status='freigegeben', dokument_id=?, trotzdem=?, freigabe_name=?, freigabe_am=UTC_TIMESTAMP(), freigabe_nutzer=?, freigabe_ip=?, freigabe_grund=?, freigabe_akteur=? WHERE id=?",
              [(int)($dok['id'] ?? $p['dokument_id']), !empty($o['trotzdem']) ? 1 : 0, mb_substr($name, 0, 190), mb_substr((string)($o['nutzer'] ?? ''), 0, 190), mb_substr((string)($o['ip'] ?? ''), 0, 64),
               trim((string)($o['grund'] ?? '')) ?: null, $akteur, (int)$p['id']]);
    $kid = (int) scalar("SELECT kunde_id FROM auftrag WHERE id=?", [$aid]);
    log_aktivitaet('kunde', $kid, $akteur === 'team' ? 'team' : 'kunde', 'Etikett-Haftungshinweis bei der Freigabe bestätigt'
        . ($akteur === 'team' ? ' (im Namen des Kunden, Grund: ' . trim((string)$o['grund']) . ')' : '') . (!empty($o['trotzdem']) ? ' – Format passt nicht, „trotzdem verwenden“ bestätigt' : '') . '.', 'auftrag', 'auftrag', $aid);
    return ['ok' => true];
}

// ---------------------------------------------------------------------------------------------------------------
// Rotes Band im Kundenportal: offene Aufträge, die ein Etikett brauchen, das fehlt oder noch nicht freigegeben ist.
// Gleiche Regeln wie bisher (Fable-4 T5.1–T5.4): geerbtes, schon freigegebenes Design gilt als freigegeben; fehlt die Datei auf dem
// Server, bekommt das Team eine Aufgabe und der Kunde kein Pop-up.
function etp_band_liste(int $kid): array {
    $liste = [];
    foreach (all("SELECT a.id, a.nummer FROM auftrag a WHERE a.kunde_id=? AND a.status NOT IN ('versendet','storniert') AND COALESCE(a.etikett_freigegeben,0)=0 ORDER BY a.id", [$kid]) as $r) {
        $aid = (int)$r['id'];
        if (!auftrag_braucht_etikett($aid)) continue;
        if (etikett_geerbt_freigabe_pruefen($aid)) continue;
        $dok = etikett_datei_fuer_auftrag($aid);
        if ($dok && empty($dok['datei_da'])) { etikett_datei_fehlt_melden($aid); continue; }
        $a = etp_auftrag($aid);
        $liste[] = ['id' => $aid, 'nummer' => (string)$r['nummer'], 'produkt' => (string)($a['produkt'] ?? ''), 'zustand' => $dok ? 'wartet' : 'fehlt',
                    'beutel' => auftrag_vollbedruckt($aid), 'datei' => $dok ? (string)($dok['datei_orig'] ?: 'Etikett-Design') : ''];
    }
    return $liste;
}
function etp_band_html(array $liste, string $sp): string {
    if (!$liste) return '<div id="bxEtBand" hidden></div>';
    $t = etp_t($sp);
    $n = count($liste);
    if ($n === 1) {
        $e = $liste[0];
        $wen = $e['nummer'] . ($e['produkt'] !== '' ? ' (' . $e['produkt'] . ')' : '');
        if ($e['zustand'] === 'fehlt') {
            $txt = $e['beutel'] ? $t('Für ' . $wen . ' fehlt Ihre Druckdatei für die Verpackung. Ohne Druckdatei können wir nicht fertig produzieren.', 'The print file for the packaging of ' . $wen . ' is missing. Without it we cannot complete production.')
                                : $t('Für ' . $wen . ' fehlt Ihr Etikett. Ohne Etikett können wir nicht fertig produzieren.', 'The label for ' . $wen . ' is missing. Without a label we cannot complete production.');
            $knopf = $t('Jetzt hochladen', 'Upload now');
        } else {
            $txt = $t('Für ' . $wen . ': ' . ($e['beutel'] ? 'Druckdatei' : 'Etikett') . ' hochgeladen – bitte noch freigeben.', 'For ' . $wen . ': ' . ($e['beutel'] ? 'print file' : 'label') . ' uploaded – please approve it.');
            $knopf = $t('Jetzt freigeben', 'Approve now');
        }
        return '<div id="bxEtBand" class="bx-dringend bx-et-band" role="alert">' . h($txt) . ' <a href="#" data-etp-oeffnen="' . (int)$e['id'] . '">' . h($knopf) . '</a></div>';
    }
    $fehlt = count(array_filter($liste, fn($e) => $e['zustand'] === 'fehlt'));
    if ($fehlt === $n) $txt = $t('Für ' . $n . ' Aufträge fehlt Ihr Etikett. Ohne Etikett können wir nicht fertig produzieren.', 'Labels are missing for ' . $n . ' orders. Without a label we cannot complete production.');
    elseif ($fehlt === 0) $txt = $t($n . ' Etiketten sind hochgeladen und warten auf Ihre Freigabe.', $n . ' labels are uploaded and waiting for your approval.');
    else $txt = $t('Für ' . $n . ' Aufträge fehlt Ihr Etikett oder Ihre Freigabe. Ohne Etikett können wir nicht fertig produzieren.', 'For ' . $n . ' orders the label or your approval is missing. Without a label we cannot complete production.');
    $wahl = array_map(fn($e) => ['id' => $e['id'], 'nummer' => $e['nummer'], 'produkt' => $e['produkt'], 'zustand' => $e['zustand']], $liste);
    return '<div id="bxEtBand" class="bx-dringend bx-et-band" role="alert">' . h($txt) . ' <a href="#" data-etp-liste="' . h(json_encode($wahl, JSON_UNESCAPED_UNICODE)) . '">' . h($t('Ansehen', 'View')) . '</a></div>';
}

// ---------------------------------------------------------------------------------------------------------------
// Texte für die Oberfläche (bx-etikett.js) in der Sprache des Kunden.
function etp_texte(string $sp, bool $beutel, string $wer): array {
    $t = etp_t($sp);
    $d = $beutel ? $t('Druckdatei', 'print file') : $t('Etikett', 'label');
    return [
        'titel'        => $beutel ? $t('Druckdatei Verpackung', 'Packaging print file') : $t('Etikett', 'Label'),
        'schritt1'     => $t('Hochladen', 'Upload'), 'schritt2' => $t('Prüfen', 'Check'), 'schritt3' => $t('Freigeben', 'Approve'),
        'ablage'       => $t('Druckdatei hierher ziehen oder', 'Drag the print file here or'), 'waehlen' => $t('Datei wählen', 'choose file'),
        'nurpdf'       => $t('nur PDF', 'PDF only'), 'keinpdf' => $t('Bitte die Druckdatei als PDF hochladen.', 'Please upload the print file as a PDF.'),
        'ersetzen'     => $t('Neue Version hochladen', 'Upload new version'),
        'soll'         => $beutel ? $t('Ihr Beutel', 'Your pouch') : $t('Ihr Etikett', 'Your label'), 'beschnitt' => $t('Beschnitt', 'bleed'),
        'lade'         => $t('Wird hochgeladen …', 'Uploading …'), 'vorschau_lade' => $t('Vorschau wird geladen …', 'Loading preview …'),
        'vorschau_fehler' => $t('Vorschau konnte nicht geladen werden.', 'Preview could not be loaded.'), 'oeffnen' => $t('Datei öffnen', 'Open file'),
        'zoom'         => $t('Zum Vergrößern klicken', 'Click to zoom'),
        'leg_end'      => $t('Endformat', 'final size'), 'leg_besch' => $t('Beschnitt', 'bleed'), 'leg_sicher' => $t('Sicherheitsabstand', 'safety margin'),
        'leg_zone'     => $t('hier kein Text', 'no text here'),
        'drehen'       => $t('gedreht ansehen', 'view rotated'),
        'technik'      => $t('Druck-Check', 'Print check'),
        'neu_pruefen'  => $t('Erneut prüfen', 'Check again'),
        'ki'           => $t('KI-Etikettencheck', 'AI label check'),
        'ki_laeuft'    => $t('KI-Etikettencheck läuft … (20–60 Sekunden)', 'AI label check running … (20–60 seconds)'),
        'ki_hinweis'   => $t('Dies ist eine automatische KI-Prüfung. Die KI kann Fehler machen und Dinge übersehen. Sie ersetzt keine fachliche oder rechtliche Prüfung. Bitte prüfen Sie Ihr Etikett selbst sorgfältig.',
                             'This is an automatic AI check. The AI can make mistakes and miss things. It does not replace a professional or legal review. Please check your label carefully yourself.'),
        'ki_nicht'     => $t('Prüfung nicht möglich – die KI ist gerade nicht erreichbar. Sie können trotzdem freigeben.', 'Check not possible – the AI is not available right now. You can still approve.'),
        'ki_nochmal'   => $t('Erneut prüfen', 'Check again'),
        'ki_test'      => $t('Testumgebung: eingespielte KI-Antwort', 'Test environment: replayed AI answer'),
        'verantwortlich' => $t('Verantwortlich für das Etikett ist der Inverkehrbringer.', 'The food business operator is responsible for the label.'),
        'haken'        => $t('Ich habe das ' . ($beutel ? 'Design' : 'Etikett') . ' geprüft (Text, Rechtschreibung, Pflichtangaben, Layout) und gebe es verbindlich für Druck und Produktion frei.',
                             'I have checked the ' . ($beutel ? 'design' : 'label') . ' (text, spelling, mandatory information, layout) and approve it bindingly for printing and production.'),
        'haftung_mehr' => $t('Haftungshinweis', 'Liability note'),
        'name'         => $wer === 'team' ? 'Name der freigebenden Person beim Kunden' : $t('Ihr Name', 'Your name'),
        'grund'        => 'Grund (Pflicht, z. B. „Kunde hat am Telefon freigegeben“)',
        'trotzdem'     => $t('Format passt nicht – trotzdem verwenden', 'Format does not fit – use anyway'),
        'freigeben'    => $wer === 'team' ? 'Im Namen des Kunden freigeben' : ($beutel ? $t('Druckdatei freigeben', 'Approve print file') : $t('Etikett freigeben', 'Approve label')),
        'spaeter'      => $t('Später freigeben', 'Approve later'),
        'danke'        => $t('Freigegeben – danke!', 'Approved – thank you!'),
        'spaeter_ok'   => $t($d . ' hochgeladen – bitte noch freigeben.', ucfirst($d) . ' uploaded – please approve it.'),
        'freigegeben'  => $t('Freigegeben', 'Approved'), 'am' => $t('am', 'on'), 'von' => $t('von', 'by'),
        'verlauf'      => $t('Versionen', 'Versions'), 'version' => $t('Version', 'Version'), 'ersetzt' => $t('ersetzt', 'replaced'), 'wartet' => $t('wartet auf Freigabe', 'waiting for approval'),
        'gesperrt'     => $t('Das Etikett ist schon bestellt und kann nicht mehr geändert werden.', 'The label has already been ordered and can no longer be changed.'),
        'liste_titel'  => $t('Etiketten für Ihre Aufträge', 'Labels for your orders'),
        'fehlt'        => $t('Etikett fehlt', 'label missing'), 'freigabe_fehlt' => $t('Freigabe fehlt', 'approval missing'),
        'fehler'       => $t('Das hat nicht geklappt – bitte erneut versuchen.', 'That did not work – please try again.'),
        'schliessen'   => $t('Schließen', 'Close'),
        'pflicht'      => $t('Bitte den Haken setzen.', 'Please tick the box.'),
    ];
}

// Status für das Pop-up (JSON). $wer = 'kunde' | 'team'. $urls = ['datei' => fn(dokument_id, pid) => url].
function etp_status(int $aid, string $wer, callable $dateiUrl, string $nameVor): array {
    etp_schema();
    $a = etp_auftrag($aid);
    if (!$a) return ['ok' => false];
    $sp = $wer === 'team' ? 'de' : etp_sprache((int)$a['kunde_id']);
    $t = etp_t($sp);
    $soll = etp_soll($aid);
    $beutel = $soll['art'] === 'beutel';
    // Beutel ohne Vorlage: Aufgabe ans Team (normal, einmal je Beutel)
    if ($beutel && empty($soll['vorlage']) && $soll['item_id'] > 0 && function_exists('aufgabe_neu')) {
        try {
            if ((int) scalar("SELECT COUNT(*) FROM aufgabe WHERE ref_typ='beutel_vorlage_fehlt' AND ref_id=? AND status='offen'", [$soll['item_id']]) === 0)
                aufgabe_neu('Vorlage für ' . $soll['item_name'] . ' fehlt', 'Für den bedruckten Beutel fehlt die Druckvorlage (Gesamtformat, Flächen, Siegelnähte, Zipper). Ohne Vorlage prüft das Etikett-Pop-up nur das Gesamtformat. Anlass: Auftrag ' . $a['nummer'] . '.',
                            2, null, null, null, 'beutel_vorlage_fehlt', $soll['item_id'], '?p=verpackung&id=' . $soll['item_id'] . '&tab=druckvorlage');
        } catch (Throwable $e) {}
    }
    $p = etp_aktuell($aid);
    $akt = null;
    if ($p) {
        $tech = json_decode((string)$p['technik'], true) ?: [];
        $mass = etp_mass_ampel($soll, $p['ist_w'] !== null ? ['w' => (float)$p['ist_w'], 'h' => (float)$p['ist_h'], 'quelle' => (string)($tech['mass_quelle'] ?? 'TrimBox')] : null, $sp, (array)($tech['pdf'] ?? []));
        $akt = ['id' => (int)$p['id'], 'version' => (int)$p['version'], 'datei_orig' => (string)$p['datei_orig'], 'url' => $dateiUrl((int)$p['dokument_id'], (int)$p['id']),
                'ist_pdf' => strtolower(pathinfo((string)$p['datei'], PATHINFO_EXTENSION)) === 'pdf',
                'mass' => $mass, 'boxen' => $tech['pdf']['boxen'] ?? [], 'rotate' => (int)($tech['pdf']['rotate'] ?? 0), 'seiten' => (int)($tech['pdf']['seiten'] ?? 1),
                'client_da' => !empty($tech['client']),
                'technik' => etp_technik_zeilen($soll, (array)($tech['pdf'] ?? []), $mass, $tech['client'] ?? null, $sp),
                'ki' => etp_ki_stand($p, $sp), 'status' => (string)$p['status'],
                'freigabe' => $p['status'] === 'freigegeben' ? ['name' => (string)$p['freigabe_name'], 'am' => $p['freigabe_am'] ? fmt_zeit((string)$p['freigabe_am'], 'd.m.Y H:i') : '',
                                                                'akteur' => (string)$p['freigabe_akteur'], 'grund' => (string)$p['freigabe_grund'], 'trotzdem' => (int)$p['trotzdem'] === 1] : null];
    }
    $verlauf = [];
    foreach (etp_verlauf($aid) as $v) {
        $verlauf[] = ['id' => (int)$v['id'], 'version' => (int)$v['version'], 'datei_orig' => (string)$v['datei_orig'], 'status' => (string)$v['status'],
                      'angelegt' => fmt_zeit((string)$v['angelegt'], 'd.m.Y H:i'), 'von' => (string)$v['hochgeladen_von'], 'url' => $dateiUrl((int)$v['dokument_id'], (int)$v['id']),
                      'mass' => (string)$v['mass_ampel'], 'ki' => (string)$v['ki_zusammenfassung'],
                      'freigabe' => $v['freigabe_am'] ? trim((string)$v['freigabe_name'] . ', ' . fmt_zeit((string)$v['freigabe_am'], 'd.m.Y H:i') . ((string)$v['freigabe_akteur'] === 'team' ? ' (Team: ' . (string)$v['freigabe_grund'] . ')' : '')) : ''];
    }
    $frei = etikett_freigegeben($aid);
    return ['ok' => true, 'aid' => $aid, 'nummer' => (string)$a['nummer'], 'produkt' => (string)$a['produkt'], 'wer' => $wer, 'sprache' => $sp,
            'art' => $soll['art'], 'soll' => ['w' => $soll['w'], 'h' => $soll['h'], 'beschnitt' => $soll['beschnitt'], 'sicher' => $soll['sicher'],
                                              'label' => ($soll['w'] && $soll['h']) ? etp_mm_label($soll['w'], $soll['h']) . ', ' . $t('Beschnitt', 'bleed') . ' ' . etp_mm($soll['beschnitt']) . ' mm' : '',
                                              'vorlage' => $soll['vorlage'], 'item_name' => $soll['item_name']],
            'aktuell' => $akt, 'freigegeben' => $frei,
            'freigabe_info' => $frei ? trim((string)$a['etikett_freigabe_von'] . ($a['etikett_freigabe_am'] ? ', ' . fmt_zeit((string)$a['etikett_freigabe_am'], 'd.m.Y H:i') : '')) : '',
            'gesperrt' => etikett_bestellt_fuer_auftrag($aid), 'name_vor' => $nameVor, 'haftung' => etikett_haftung_text(),
            'verlauf' => $verlauf, 'txt' => etp_texte($sp, $soll['art'] === 'beutel', $wer)];
}

// AJAX-Router für beide Seiten. $ctx: wer ('kunde'|'team'), kunde_id (Portal: nur eigene Aufträge), datei_url (callable),
// name_vor, nutzer (wer klickt), nach_freigabe (callable|null: Mails), nach_upload (callable|null).
function etp_ajax(array $ctx): void {
    etp_schema();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $a = (string)($_GET['a'] ?? $_POST['a'] ?? 'status');
    $aid = (int)($_GET['aid'] ?? $_POST['aid'] ?? 0);
    $wer = $ctx['wer'] === 'team' ? 'team' : 'kunde';
    $raus = function (array $d) { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
    if ($a === 'band' && $wer === 'kunde') {
        $sp = etp_sprache((int)$ctx['kunde_id']);
        $raus(['ok' => true, 'html' => etp_band_html(etp_band_liste((int)$ctx['kunde_id']), $sp)]);
    }
    $auf = $aid > 0 ? one("SELECT id, kunde_id FROM auftrag WHERE id=?", [$aid]) : null;
    if (!$auf || ($wer === 'kunde' && (int)$auf['kunde_id'] !== (int)$ctx['kunde_id'])) { http_response_code(404); $raus(['ok' => false, 'fehler' => 'Auftrag nicht gefunden.']); }
    if (!auftrag_braucht_etikett($aid)) $raus(['ok' => false, 'fehler' => 'Für diesen Auftrag ist kein Etikett nötig.']);
    $sp = $wer === 'team' ? 'de' : etp_sprache((int)$auf['kunde_id']);
    $status = fn() => etp_status($aid, $wer, $ctx['datei_url'], (string)($ctx['name_vor'] ?? ''));
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($a === 'status') $raus($status());
    if (!$post) $raus(['ok' => false, 'fehler' => 'POST erwartet.']);
    if ($a === 'upload') {
        $r = etp_hochladen($aid, 'datei', $wer, (string)($ctx['nutzer'] ?? ''), $sp);
        if (!empty($r['ok']) && !empty($ctx['nach_upload'])) ($ctx['nach_upload'])($aid);
        $raus($r['ok'] ? ['ok' => true] + $status() : $r);
    }
    $json = json_decode((string)($_POST['daten'] ?? ''), true);
    if (!is_array($json)) $json = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($json)) $json = $_POST;
    $pid = (int)($json['pid'] ?? 0);
    $p = $pid > 0 ? etp_pruefung($pid) : null;
    if ($pid > 0 && (!$p || (int)$p['auftrag_id'] !== $aid)) $raus(['ok' => false, 'fehler' => 'Version nicht gefunden.']);
    if ($a === 'technik' && $p) { etp_client_speichern($pid, (array)($json['client'] ?? [])); $raus($status()); }
    if ($a === 'neu_pruefen' && $p) { etp_neu_pruefen($pid); $raus($status()); }   // Sofortfix 13.11.: Druck-Check und KI für die aktuelle Datei neu
    if ($a === 'ki' && $p) {
        if (function_exists('session_write_close')) @session_write_close();   // die Sitzung nicht 60 s sperren
        etp_ki_pruefen($pid, (array)($json['bilder'] ?? []));
        $raus($status());
    }
    if ($a === 'freigeben') {
        if (empty($json['haken'])) $raus(['ok' => false, 'fehler' => etp_texte($sp, false, $wer)['pflicht']]);
        $r = etp_freigeben($aid, (string)($json['name'] ?? ''), $wer, ['trotzdem' => !empty($json['trotzdem']), 'grund' => (string)($json['grund'] ?? ''),
                           'nutzer' => (string)($ctx['nutzer'] ?? ''), 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')], $sp);
        if (!empty($r['ok']) && !empty($ctx['nach_freigabe'])) ($ctx['nach_freigabe'])($aid);
        $raus(!empty($r['ok']) ? ['ok' => true] + $status() : $r);
    }
    // 13.11.2026 (Nico: keine rechtliche Beratung): „Ausführlichen Etikett-Check durch bulkify buchen“ entfernt (Aktion buchen samt Anfrage).
    // Der Dienstleistungs-Baustein 'etikettcheck' bleibt im Code (core/dienstleistung.php), wird aber nicht angeboten.
    $raus(['ok' => false, 'fehler' => 'Unbekannte Aktion.']);
}

// Einbinden auf einer Seite: Skript + Startwerte. $basis = URL des AJAX-Endpunkts (ohne &a=), $auto = Auftrag, der sofort aufgeht.
function etp_einbinden(string $basis, int $auto = 0, string $sp = 'de'): string {
    $v = (int) @filemtime(BX_ROOT . '/public/assets/bx-etikett.js');
    $tx = etp_texte($sp, false, 'kunde');
    return '<script>window.bxEtikettStart=' . json_encode(['basis' => $basis, 'auto' => $auto, 'txt' => ['fehlt' => $tx['fehlt'], 'freigabe_fehlt' => $tx['freigabe_fehlt'], 'liste_titel' => $tx['liste_titel']]], JSON_UNESCAPED_UNICODE) . ';</script>'
         . '<script src="/assets/bx-etikett.js?v=' . $v . '" defer></script>';
}
