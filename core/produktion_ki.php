<?php
// Produktion per KI melden (Nico 13.11.2026, Werk-App Teil 4). Dashboard-Datei (braucht core/schema.php, core/pa_ablauf.php, core/ki.php).
// Ablauf: Text/Diktat -> KI wertet aus (striktes JSON, nichts erfinden) -> Server sucht passende offene PAs -> Bestätigungs-Karte mit
// Rückfragen (mehrere PAs, Einheit, Rest, Charge, Etikett, Bulk) -> Mensch prüft und klickt „Buchen“ -> gebucht wird AUSSCHLIESSLICH über
// das Formular „Teilmenge nachtragen“ (pa_nachtrag_buchen). Die KI bucht nie selbst, es gibt keine automatische Bestätigung.
// Nachbesserung Fable-5 (13.11.2026): Die Karte hat eine Kennung (kid) in der Sitzung; der Server bucht nur, wenn ihre Rückfragen geklärt
// sind (pki_buchen_pruefen): Einheit gewählt, Bulk gewählt oder ausdrücklich „ohne Bulk“, bei Etikett-Sperre nur „bis vor dem Etikettieren“.
// Text und Melder kommen aus der Sitzung (pki_karte_aus_sitzung), nicht aus versteckten Feldern.
require_once __DIR__ . '/pa_ablauf.php';

// Darf diese Person per KI melden? Nur admin und production.
function pki_darf(): bool { return function_exists('has_role') && (has_role('admin') || has_role('production')); }

function pki_systemprompt(): string {
    return "Du wertest eine kurze Produktionsmeldung eines Nahrungsergänzungs-Lohnherstellers aus (getippt oder diktiert, deutsch). "
         . "Gib NUR ein JSON-Objekt mit genau diesen Schlüsseln zurück:\n"
         . '{"produkt":"","kunde":"","pa_nummer":"","menge_text":"","menge":null,"einheit":"","charge":"","datum":"","bulk_charge":"","ohne_bulk":false,"etikettiert":null,"verpackt":null,"mitarbeiter":"","notiz":""}' . "\n"
         . "Regeln: Nichts erfinden – was nicht im Text steht, bleibt leer bzw. null. "
         . "produkt = Produkt- oder Rezepturname wie genannt, OHNE die gemeldete Menge. pa_nummer = Produktionsauftrag wie „PR-2749“ (nur wenn genannt). "
         . "menge_text = die gemeldete Menge GENAU so, wie sie im Text steht (z. B. „1.000“ oder „43.092“), als Text. "
         . "menge = dieselbe Zahl ohne Tausenderpunkt (43.092 -> 43092). "
         . "einheit: „packung“ wenn Gläser/Dosen/Packungen/Beutel/Flaschen genannt sind, „kapsel“ bei Kapseln/Tabletten/Softgels, „kg“ bei kg, "
         . "sonst leer (auch bei „Stück“ – das ist mehrdeutig). charge = genannte Charge (z. B. 2766.B). bulk_charge = genannte Bulk-Charge. "
         . "datum im Format JJJJ-MM-TT, nur wenn ein Datum oder „gestern/heute“ genannt ist (heute ist " . heute_ort() . "). "
         . "etikettiert/verpackt: true wenn „mit Etikett/etikettiert“ bzw. „verpackt“ genannt, false wenn „ohne Etikett/nicht etikettiert“, sonst null. "
         . "ohne_bulk: true nur wenn ausdrücklich „ohne Bulk“ (kein Bulk-Bezug) genannt ist, sonst false.";
}

// Zahlen im Originaltext (deutsche Schreibweise, Tausenderpunkt) – für den Abgleich mit der KI-Menge.
function pki_zahlen_im_text(string $text): array {
    preg_match_all('/\d{1,3}(?:[.\x{2009}\x{202F} ]\d{3})+(?:,\d+)?|\d+(?:[.,]\d+)?/u', $text, $m);
    $out = [];
    foreach ($m[0] as $t) { $v = zahl_lesen(str_replace(["\u{2009}", "\u{202F}", ' '], '.', $t), true); if ($v > 0) $out[] = $v; }
    return $out;
}
// KI-Menge gegen den Originaltext prüfen („1.000“ darf nicht 1 werden). Rückgabe [menge|null, frage].
function pki_menge_abgleich(string $text, ?float $menge): array {
    if ($menge === null || $menge <= 0) return [$menge, ''];
    $z = pki_zahlen_im_text($text);
    foreach ($z as $v) if (abs($v - $menge) < 1e-6) return [$menge, ''];
    foreach ($z as $v) if (abs($v - $menge * 1000) < 1e-6) return [$v, ''];   // KI hat den Tausenderpunkt als Dezimalpunkt gelesen
    return [$menge, 'Die Menge ' . pa_mz($menge) . ' steht so nicht im Text – bitte prüfen und eintragen.'];
}

// KI-Auswertung. $ki_antwort: nur für Tests (eingespielte KI-Antwort statt Aufruf). Rückgabe ['ok','felder','fehler'].
function pki_auswerten(string $text, ?array $ki_antwort = null): array {
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'fehler' => 'Bitte die Meldung eingeben.', 'felder' => []];
    if ($ki_antwort === null) {
        require_once __DIR__ . '/ki.php';
        if (!function_exists('ki_bereit') || !ki_bereit()) return ['ok' => false, 'fehler' => 'Die KI ist auf diesem Server nicht eingerichtet.', 'felder' => []];
        $r = ki_json($text, ['system' => pki_systemprompt(), 'zweck' => 'Produktion melden', 'max_tokens' => 600, 'timeout' => 30, 'versuche' => 1, 'budget' => 30]);
        if (empty($r['ok'])) return ['ok' => false, 'fehler' => 'KI: ' . (string)($r['fehler'] ?? 'keine Antwort'), 'felder' => []];
        $ki_antwort = (array)($r['daten'] ?? []);
    }
    $s = fn($k) => trim((string)($ki_antwort[$k] ?? ''));
    $b = fn($k) => isset($ki_antwort[$k]) && $ki_antwort[$k] !== null && $ki_antwort[$k] !== '' ? (bool)$ki_antwort[$k] : null;
    $e = mb_strtolower($s('einheit'));
    // Menge: bevorzugt der wörtliche Text (menge_text), sonst die Zahl – danach Abgleich mit dem Originaltext.
    $menge = $s('menge_text') !== '' ? zahl_lesen($s('menge_text'), true)
           : (isset($ki_antwort['menge']) && $ki_antwort['menge'] !== null && $ki_antwort['menge'] !== '' ? zahl_lesen((string)$ki_antwort['menge'], true) : null);
    if ($menge !== null && $menge <= 0) $menge = null;
    $f = ['produkt' => $s('produkt'), 'kunde' => $s('kunde'), 'pa_nummer' => $s('pa_nummer'),
          'menge' => $menge, 'einheit' => in_array($e, ['packung', 'kapsel', 'kg'], true) ? $e : '',
          'charge' => $s('charge'), 'datum' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s('datum')) ? $s('datum') : '',
          'bulk_charge' => $s('bulk_charge'), 'etikettiert' => $b('etikettiert'), 'verpackt' => $b('verpackt'),
          'mitarbeiter' => $s('mitarbeiter'), 'notiz' => $s('notiz'), 'menge_aus_name' => false, 'menge_frage' => '',
          // Fable-6 P9: „ohne Bulk“ und „Etikett genannt“ zusätzlich direkt am Text erkennen (nicht nur der KI überlassen).
          'ohne_bulk' => !empty($ki_antwort['ohne_bulk']) || preg_match('/\bohne\s+bulk/iu', $text) === 1,
          'etikett_genannt' => preg_match('/etikett/iu', $text) === 1];
    // „Magnesium 500“ (P3): Zahl am Ende des Produktnamens und keine Menge erkannt -> als Menge werten (Rückfrage in der Karte).
    if ($f['menge'] === null && preg_match('/^(.*\D)\s+(\d{1,3}(?:\.\d{3})+|\d+)$/u', $f['produkt'], $m)) {
        $f['menge'] = zahl_lesen($m[2], true); $f['produkt'] = trim($m[1]); $f['menge_aus_name'] = true;
    }
    [$f['menge'], $f['menge_frage']] = pki_menge_abgleich($text, $f['menge']);
    return ['ok' => true, 'felder' => $f, 'fehler' => ''];
}

// Offene PAs mit Rest (nicht inaktiv, nicht storniert). $pa_vorgabe: PA-Seite (nur dieser). Treffer über PA-Nummer, Charge (Grundcharge =
// PA-Nummer), sonst Produkt-/Rezepturname (alle Wörter, über Auftragsbezeichnung, Produkt und Rezeptur) und optional Kunde.
// $info: 'pa_nicht_offen' (genannte PR-Nummer ohne offenen Treffer), 'pa_anders' (PA-Seite, Text nennt eine andere PR-Nummer),
// 'ohne_zahl' (Treffer erst ohne Zahl-Wörter gefunden – Rückfrage).
function pki_kandidaten(array $f, int $pa_vorgabe = 0, ?array &$info = null): array {
    $info = [];
    $sql = "SELECT pa.id, pa.nummer, pa.menge, pa.status, pa.produkt_id, pa.rezeptur_id, pa.auftrag_id,
                   COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, r.name) AS produkt, k.firma AS kunde,
                   CONCAT_WS(' ', a.produkt_bezeichnung, p.name, r.name, rp.name) AS suchname,
                   COALESCE(NULLIF(p.einheiten_pro_packung,0), NULLIF(a.stueck,0), 0) AS je_packung, COALESCE(rp.darreichungsform, r.darreichungsform) AS form,
                   COALESCE(NULLIF(pa.stueck,0), NULLIF(a.stueck,0), 0) AS fuell_g,
                   pa.menge - " . pa_produziert_sql('pa.id') . " AS rest
            FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id LEFT JOIN produkt p ON p.id=pa.produkt_id
            LEFT JOIN rezeptur r ON r.id=pa.rezeptur_id LEFT JOIN rezeptur rp ON rp.id=p.rezeptur_id LEFT JOIN kunden k ON k.id=COALESCE(a.kunde_id, pa.kunde_id)
            WHERE pa.status IN ('offen','laufend') AND (a.id IS NULL OR a.status<>'storniert')
              AND COALESCE(p.status,'')<>'inaktiv' AND COALESCE(r.status,'')<>'inaktiv' AND COALESCE(rp.status,'')<>'inaktiv'
            HAVING rest > 0";
    $alle = all($sql . " ORDER BY pa.id");
    $nrGenannt = preg_replace('/\D/', '', $f['pa_nummer'] ?? '');
    if ($pa_vorgabe > 0) {
        $t = array_values(array_filter($alle, fn($r) => (int)$r['id'] === $pa_vorgabe));
        $eigen = preg_replace('/\D/', '', (string) scalar("SELECT nummer FROM produktionsauftrag WHERE id=?", [$pa_vorgabe]));
        if ($nrGenannt !== '' && $nrGenannt !== $eigen) { $info['pa_anders'] = 'PR-' . $nrGenannt; return []; }
        return $t;
    }
    if ($nrGenannt !== '') {
        $t = array_values(array_filter($alle, fn($r) => preg_replace('/\D/', '', (string)$r['nummer']) === $nrGenannt));
        if ($t) return $t;
        $info['pa_nicht_offen'] = 'PR-' . $nrGenannt;   // M5: genannte Nummer ohne offenen Treffer -> Meldung statt stiller Namenssuche
        return [];
    }
    if (preg_match('/^(\d{3,6})[.\s]?[A-Za-z]{1,2}$/', (string)($f['charge'] ?? ''), $m)) {   // Grundcharge = PA-Nummer
        $t = array_values(array_filter($alle, fn($r) => preg_replace('/\D/', '', (string)$r['nummer']) === $m[1]));
        if ($t) return $t;
    }
    $suche = function (array $woerter) use ($alle) {
        if (!$woerter) return [];
        return array_values(array_filter($alle, function ($r) use ($woerter) {
            $hay = mb_strtolower((string)$r['suchname']);
            foreach ($woerter as $w) if (mb_strpos($hay, $w) === false) return false;
            return true;
        }));
    };
    $woerter = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)($f['produkt'] ?? ''))), fn($w) => mb_strlen($w) >= 3));
    $t = $suche($woerter);
    if (!$t) {   // P3: ohne Zahl-Wörter erneut suchen, dann Auswahl plus Rückfrage
        $ohne = array_values(array_filter($woerter, fn($w) => !preg_match('/\d/u', $w)));
        if ($ohne && count($ohne) < count($woerter)) { $t = $suche($ohne); if ($t) $info['ohne_zahl'] = true; }
    }
    $ku = mb_strtolower(trim((string)($f['kunde'] ?? '')));
    if ($ku !== '' && count($t) > 1) { $tk = array_values(array_filter($t, fn($r) => mb_strpos(mb_strtolower((string)$r['kunde']), $ku) !== false)); if ($tk) $t = $tk; }
    return $t;
}

// Bestätigungs-Karte für EINEN PA: Vorbelegung des Nachtrag-Formulars + Rückfragen. Rückgabe-Felder siehe unten.
function pki_karte(array $f, array $pa): array {
    $pid = (int)$pa['id'];
    $rest = (float)$pa['rest']; $je = (int)$pa['je_packung'];
    $form = (string)$pa['form'];
    $stueckForm = in_array($form, ['kapsel', 'softgel', 'tablette'], true);
    $fragen = []; $hinweise = [];
    $lesartPflicht = false; $mengeVorbelegen = true;
    // Einheit: PA-Menge = Packungen (Gläser). Kapseln -> Gläser umrechnen, kg -> über das Füllgewicht. Unklar -> Lesarten, Wahl Pflicht.
    $menge = (float)($f['menge'] ?? 0);
    $lesarten = [];
    if ($menge > 0 && ($f['einheit'] ?? '') === 'kg') {   // P4: kg über das Füllgewicht je Packung (Pulver: Packungsgröße in g)
        $fg = function_exists('form_groessen_einheit') && form_groessen_einheit($form) === 'g' ? (float)$pa['fuell_g'] : 0.0;
        if ($fg > 0) {
            $pk = floor($menge * 1000 / $fg + 1e-9);
            $lesarten[] = ['wert' => $pk, 'text' => pa_mz($menge) . ' kg = ' . pa_mz($pk) . ' Dosen à ' . pa_mz($fg) . ' g', 'zu_viel' => $pk > $rest + 1e-6];
        } else { $fragen[] = 'kg kann ich nicht umrechnen - bitte Packungen eintragen.'; $mengeVorbelegen = false; }
    } elseif ($menge > 0) {
        if ($f['einheit'] === 'packung' || !$stueckForm || $je <= 0) {
            $lesarten[] = ['wert' => round($menge), 'text' => pa_mz($menge) . ' Gläser/Packungen', 'zu_viel' => $menge > $rest + 1e-6];
            if ($f['einheit'] === '' && $stueckForm && $je <= 0) $hinweise[] = 'Einheit unklar und „Kapseln je Glas“ fehlt am Produkt – Menge bitte prüfen.';
        } else {
            $glas = floor($menge / $je);
            $restK = $menge - $glas * $je;
            $lK = ['wert' => $glas, 'text' => pa_mz($menge) . ' Kapseln = ' . pa_mz($glas) . ' Gläser à ' . $je . ($restK > 0 ? ' (' . pa_mz($restK) . ' Kapseln Rest)' : ''), 'zu_viel' => $glas > $rest + 1e-6];
            $lG = ['wert' => round($menge), 'text' => pa_mz($menge) . ' Gläser', 'zu_viel' => $menge > $rest + 1e-6];
            if ($f['einheit'] === 'kapsel') $lesarten[] = $lK;
            else { $lesarten = [$lK, $lG]; $fragen[] = 'Einheit unklar: Kapseln oder Gläser? Bitte eine Lesart wählen.'; }
        }
        if (!empty($f['menge_aus_name'])) { $lesartPflicht = true; $fragen[] = 'Die Zahl ' . pa_mz($menge) . ' stand im Produktnamen – ist das die Menge? Bitte die Lesart wählen.'; }
    } elseif (($f['einheit'] ?? '') !== 'kg') $fragen[] = 'Keine Menge erkannt – bitte eintragen.';
    if (($f['menge_frage'] ?? '') !== '') { $fragen[] = $f['menge_frage']; $mengeVorbelegen = false; }
    if (count($lesarten) > 1) $lesartPflicht = true;
    if (count($lesarten) === 1 && $lesarten[0]['zu_viel']) $fragen[] = 'Menge größer als der offene Rest (' . pa_mz($rest) . ' Stk).';
    // Bulk-Charge: genannte suchen, sonst ältere zuerst vorschlagen (änderbar).
    $rid = (int)($pa['rezeptur_id'] ?: scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]));
    $bulkWahl = all("SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.einheit, c.mhd FROM charge c JOIN item i ON i.id=c.item_id
                     WHERE i.kategorie='fertig' AND c.status='frei' AND c.menge_verfuegbar>0 AND c.fremd_kunde_id IS NULL
                       AND (c.auftrag_id=? OR (c.auftrag_id IS NULL AND i.rezeptur_id=?)) ORDER BY (c.mhd IS NULL), c.mhd, c.id", [(int)($pa['auftrag_id'] ?? 0), $rid]);
    $bulkId = 0; $bulkFrage = false;
    $datum = ($f['datum'] ?? '') !== '' ? $f['datum'] : heute_ort();
    // P6: genannte Charge zuerst unter den vorhandenen Unter-Chargen des Auftrags suchen – dann deren Tag und Bulk übernehmen.
    $ucTreffer = null;
    if (($f['charge'] ?? '') !== '') {
        foreach (pa_unter_chargen($pid) as $u)
            if (in_array((string)$u['status'], ['laeuft', 'fertig'], true) && pa_charge_gleich((string)$u['charge_nr'], $f['charge'])) { $ucTreffer = $u; break; }
    }
    if ($ucTreffer) {
        $bulkId = (int)($ucTreffer['bulk_charge_id'] ?? 0);
        $datum = pa_uc_tag($ucTreffer);
        if ($bulkId > 0 && !array_filter($bulkWahl, fn($b) => (int)$b['id'] === $bulkId))   // Bulk der Unter-Charge auch zeigen, wenn nicht mehr frei
            if ($bz = one("SELECT id, charge_nr, menge_verfuegbar, einheit, mhd FROM charge WHERE id=?", [$bulkId])) array_unshift($bulkWahl, $bz);
        $hinweise[] = 'Charge ' . $ucTreffer['charge_nr'] . ' gibt es schon (Tag ' . date('d.m.Y', strtotime($datum)) . ') – es wird auf diese Unter-Charge nachgetragen.';
    } elseif (!empty($f['ohne_bulk'])) {   // Fable-6 P9: „ohne Bulk“ im Text -> Haken gesetzt, kein Vorschlag
        $bulkId = 0;
        $hinweise[] = 'Gemeldet „ohne Bulk“ – der Haken „ohne Bulk buchen“ ist gesetzt.';
    } elseif (($f['bulk_charge'] ?? '') !== '') {
        foreach ($bulkWahl as $bw) if (pa_charge_gleich((string)$bw['charge_nr'], $f['bulk_charge'])) { $bulkId = (int)$bw['id']; break; }
        if (!$bulkId) { $fragen[] = 'Bulk-Charge „' . $f['bulk_charge'] . '“ nicht als freie Charge gefunden – bitte wählen oder ausdrücklich „ohne Bulk“ anhaken.'; $bulkFrage = true; }
    } elseif ($bulkWahl) {
        $bulkId = (int)$bulkWahl[0]['id'];
        $hinweise[] = 'Bulk-Charge nicht genannt – Vorschlag: ältere Charge zuerst (' . $bulkWahl[0]['charge_nr'] . '), änderbar.';
    } else {   // P5: keine freie Bulk-Charge – nicht still „ohne“
        $fragen[] = 'Keine freie Bulk-Charge zu diesem Auftrag – erst Bulk einbuchen bzw. freigeben, oder ausdrücklich „ohne Bulk“ anhaken.';
        $bulkFrage = true;
    }
    if (function_exists('produktion_teilmenge_machbar')) {   // P5: Hinweis wie in der Werk-App
        try {
            $tm = produktion_teilmenge_machbar($pid);
            if ((float)($tm['komplett'] ?? 1) <= 0)
                $hinweise[] = 'Jetzt machbar: 0' . (trim((string)($tm['limit_komplett'] ?? '')) !== '' ? ' (begrenzt durch ' . $tm['limit_komplett'] . ')' : '') . ' – laut Bestand fehlt Material.';
        } catch (\Throwable $e) {}
    }
    // Charge: Vorschlag des Systems (Buchstabe je Tag/Bulk); Abweichung nur mit Grund.
    $v = pa_vorgabe($pid, $bulkId, 0, $datum);
    if ($ucTreffer) $v = ['charge_nr' => (string)$ucTreffer['charge_nr'], 'mhd' => (string)$ucTreffer['mhd']];
    $charge = ($f['charge'] ?? '') !== '' ? ($ucTreffer ? (string)$ucTreffer['charge_nr'] : $f['charge']) : (string)$v['charge_nr'];
    $chargeAbw = pa_charge_abweichend($charge, (string)$v['charge_nr']);   // eine Regel mit pa_starten (13.11.2026)
    $chargeFremd = pki_charge_fremd($charge, $pid);
    if ($chargeFremd !== '') $fragen[] = $chargeFremd;
    elseif ($chargeAbw) $fragen[] = 'Charge ' . $charge . ' passt nicht zum Vorschlag ' . $v['charge_nr'] . ' – nur mit Grund.';
    // Etikett: fehlt/nicht freigegeben -> nur bis vor dem Etikettieren (der Server erzwingt das ebenfalls).
    $sperre = (string) (pa_fn('produktion_etikett_sperre', 'erp_etikett_sperre', $pid) ?? '');
    $vorEtikett = $sperre !== '' || ($f['etikettiert'] ?? null) === false;
    if ($sperre !== '' && ($f['etikettiert'] ?? null) === true && !empty($f['etikett_genannt'])) $hinweise[] = 'Gemeldet „mit Etikett“, aber: ' . $sperre . ' Gebucht wird nur bis vor dem Etikettieren.';
    elseif ($sperre !== '') $hinweise[] = $sperre . ' Gebucht wird nur bis vor dem Etikettieren.';
    // Mitarbeiter: genannter Name, sonst die meldende Person.
    $ma = '';
    if (($f['mitarbeiter'] ?? '') !== '') { $ma = (string) scalar("SELECT name FROM benutzer WHERE aktiv=1 AND lieferant_id IS NULL AND name LIKE ? ESCAPE '=' ORDER BY id LIMIT 1", ['%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $f['mitarbeiter']) . '%']); }
    if ($ma === '' && function_exists('current_user')) $ma = (string)(current_user()['name'] ?? '');
    return ['kid' => bin2hex(random_bytes(8)), 'pa' => $pa, 'lesarten' => $lesarten, 'lesart_pflicht' => $lesartPflicht,
            'lesart_werte' => array_map(fn($l) => (int)$l['wert'], $lesarten), 'menge_vorbelegen' => $mengeVorbelegen,
            'bulk_wahl' => $bulkWahl, 'bulk_id' => $bulkId, 'bulk_frage' => $bulkFrage, 'datum' => $datum, 'charge' => $charge,
            'charge_vorschlag' => (string)$v['charge_nr'], 'charge_abw' => $chargeAbw, 'mhd' => (string)$v['mhd'], 'vor_etikett' => $vorEtikett,
            'etikett_sperre' => $sperre, 'mitarbeiter' => $ma, 'rest' => $rest, 'fragen' => $fragen, 'hinweise' => $hinweise,
            'ohne_bulk' => !empty($f['ohne_bulk']), 'charge_fremd' => $chargeFremd,
            'uc_treffer' => $ucTreffer ? (int)$ucTreffer['id'] : 0];
}

// Ganze Meldung: Auswertung + PAs + (bei genau einem PA) Karte. $pa_wahl: vom Menschen gewählter PA (bei mehreren Treffern).
function pki_meldung(string $text, int $pa_vorgabe = 0, int $pa_wahl = 0, ?array $ki_antwort = null): array {
    $a = pki_auswerten($text, $ki_antwort);
    if (empty($a['ok'])) return ['ok' => false, 'fehler' => $a['fehler'], 'text' => $text, 'pa_vorgabe' => $pa_vorgabe];
    $info = [];
    $kand = pki_kandidaten($a['felder'], $pa_vorgabe, $info);
    if ($pa_wahl > 0) $kand = array_values(array_filter($kand, fn($r) => (int)$r['id'] === $pa_wahl));
    $out = ['ok' => true, 'text' => $text, 'felder' => $a['felder'], 'kandidaten' => $kand, 'karte' => null, 'pa_vorgabe' => $pa_vorgabe, 'info' => $info,
            'gemeldet_von' => function_exists('current_user') ? (string)(current_user()['name'] ?? '') : ''];
    // Eindeutiger Treffer -> Karte. Bei „ohne Zahl-Wörter gefunden“ (P3) immer erst Auswahl plus Rückfrage.
    if (count($kand) === 1 && empty($info['ohne_zahl'])) $out['karte'] = pki_karte($a['felder'], $kand[0]);
    return $out;
}

// Die Karte zu einer Kennung aus der Sitzung holen: ['schluessel' => 'pki_…', 'meldung' => […]] oder null (abgelaufen/unbekannt).
function pki_karte_aus_sitzung(string $kid): ?array {
    if ($kid === '' || empty($_SESSION) || !is_array($_SESSION)) return null;
    foreach ($_SESSION as $k => $m)
        if (is_string($k) && strncmp($k, 'pki_', 4) === 0 && is_array($m) && !empty($m['karte']['kid']) && hash_equals((string)$m['karte']['kid'], $kid))
            return ['schluessel' => $k, 'meldung' => $m];
    return null;
}
// Fable-6 P9: gehört die Grundnummer der Charge zu einem ANDEREN Produktionsauftrag (2766.B an PR-2775)? Dann hart ablehnen – auch mit
// Grund. '' = in Ordnung, sonst der Text der Rückfrage.
function pki_charge_fremd(string $charge, int $pa_id): string {
    if (!preg_match('/^\s*(\d{3,6})\s*[.\s]?\s*[A-Za-z]{0,2}\s*$/', $charge, $m)) return '';
    $basis = pa_basis($pa_id);
    if ($m[1] === $basis) return '';
    $andere = (string) scalar("SELECT nummer FROM produktionsauftrag WHERE id<>? AND (nummer=? OR nummer=?) LIMIT 1", [$pa_id, 'PR-' . $m[1], $m[1]]);
    return $andere !== '' ? 'Charge ' . trim($charge) . ' gehört zu ' . $andere . ', nicht zu diesem Auftrag (PR-' . $basis . ') – so wird nicht gebucht. Bitte richtigen Auftrag wählen oder Charge korrigieren.' : '';
}
// Serverseitige Prüfung beim Buchen einer KI-Karte (P2). '' = darf buchen, sonst der Grund. $p = POST.
function pki_buchen_pruefen(array $karte, array $p, int $pa_id): string {
    if (!pki_darf()) return 'Per KI melden dürfen nur Produktion und Admin.';
    if ((int)($karte['pa']['id'] ?? 0) !== $pa_id) return 'Die KI-Karte gehört zu einem anderen Produktionsauftrag – bitte neu auswerten.';
    if (!empty($karte['lesart_pflicht'])) {
        $l = (string)($p['ki_lesart'] ?? '');
        if ($l === '' || !in_array((int)$l, (array)$karte['lesart_werte'], true)) return 'Bitte zuerst die Einheit wählen (Kapseln oder Packungen).';
    }
    // Fable-6 P9: fremde Charge hart ablehnen; rote Rückfragen müssen ausdrücklich als geklärt bestätigt sein.
    if (($fr = pki_charge_fremd((string)($p['charge_nr'] ?? ''), $pa_id)) !== '') return $fr;
    if (!empty($karte['fragen']) && empty($p['fragen_geklaert'])) return 'Bitte die roten Rückfragen prüfen und „Rückfragen geklärt“ anhaken.';
    $bw = (string)($p['bulk_charge_id'] ?? '0');
    if (($bw === '' || $bw === '0') && empty($p['ohne_bulk'])) return 'Bitte die Bulk-Charge wählen oder ausdrücklich „ohne Bulk“ anhaken.';
    if ($bw !== '' && $bw !== '0' && !in_array((int)$bw, array_map(fn($b) => (int)$b['id'], (array)$karte['bulk_wahl']), true))
        return 'Diese Bulk-Charge stand nicht zur Wahl – bitte neu auswerten.';
    $sperre = (string) (pa_fn('produktion_etikett_sperre', 'erp_etikett_sperre', $pa_id) ?? '');
    if (($sperre !== '' || (string)($karte['etikett_sperre'] ?? '') !== '') && (string)($p['umfang'] ?? '') !== 'vor_etikett')
        return 'Etikett gesperrt – gebucht werden kann nur „bis vor dem Etikettieren“.';
    return '';
}
