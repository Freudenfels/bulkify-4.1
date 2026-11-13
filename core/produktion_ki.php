<?php
// Produktion per KI melden (Nico 13.11.2026, Werk-App Teil 4). Dashboard-Datei (braucht core/schema.php, core/pa_ablauf.php, core/ki.php).
// Ablauf: Text/Diktat -> KI wertet aus (striktes JSON, nichts erfinden) -> Server sucht passende offene PAs -> Bestätigungs-Karte mit
// Rückfragen (mehrere PAs, Einheit, Rest, Charge, Etikett, Bulk) -> Mensch prüft und klickt „Buchen“ -> gebucht wird AUSSCHLIESSLICH über
// das Formular „Teilmenge nachtragen“ (pa_nachtrag_buchen). Die KI bucht nie selbst, es gibt keine automatische Bestätigung.
require_once __DIR__ . '/pa_ablauf.php';

// Darf diese Person per KI melden? Nur admin und production.
function pki_darf(): bool { return function_exists('has_role') && (has_role('admin') || has_role('production')); }

function pki_systemprompt(): string {
    return "Du wertest eine kurze Produktionsmeldung eines Nahrungsergänzungs-Lohnherstellers aus (getippt oder diktiert, deutsch). "
         . "Gib NUR ein JSON-Objekt mit genau diesen Schlüsseln zurück:\n"
         . '{"produkt":"","kunde":"","pa_nummer":"","menge":null,"einheit":"","charge":"","datum":"","bulk_charge":"","etikettiert":null,"verpackt":null,"mitarbeiter":"","notiz":""}' . "\n"
         . "Regeln: Nichts erfinden – was nicht im Text steht, bleibt leer bzw. null. "
         . "produkt = Produkt- oder Rezepturname wie genannt. pa_nummer = Produktionsauftrag wie „PR-2749“ (nur wenn genannt). "
         . "menge = Zahl ohne Tausenderpunkt (43.092 -> 43092). "
         . "einheit: „packung“ wenn Gläser/Dosen/Packungen/Beutel/Flaschen genannt sind, „kapsel“ bei Kapseln/Tabletten/Softgels, „kg“ bei kg, "
         . "sonst leer (auch bei „Stück“ – das ist mehrdeutig). charge = genannte Charge (z. B. 2766.B). bulk_charge = genannte Bulk-Charge. "
         . "datum im Format JJJJ-MM-TT, nur wenn ein Datum oder „gestern/heute“ genannt ist (heute ist " . heute_ort() . "). "
         . "etikettiert/verpackt: true wenn „mit Etikett/etikettiert“ bzw. „verpackt“ genannt, false wenn „ohne Etikett/nicht etikettiert“, sonst null.";
}

// KI-Auswertung. $ki_antwort: nur für Tests (eingespielte KI-Antwort statt Aufruf). Rückgabe ['ok','felder','fehler'].
function pki_auswerten(string $text, ?array $ki_antwort = null): array {
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'fehler' => 'Bitte die Meldung eingeben.', 'felder' => []];
    if ($ki_antwort === null) {
        require_once __DIR__ . '/ki.php';
        if (!function_exists('ki_bereit') || !ki_bereit()) return ['ok' => false, 'fehler' => 'Die KI ist auf diesem Server nicht eingerichtet.', 'felder' => []];
        $r = ki_json($text, ['system' => pki_systemprompt(), 'zweck' => 'Produktion melden', 'max_tokens' => 600]);
        if (empty($r['ok'])) return ['ok' => false, 'fehler' => 'KI: ' . (string)($r['fehler'] ?? 'keine Antwort'), 'felder' => []];
        $ki_antwort = (array)($r['daten'] ?? []);
    }
    $s = fn($k) => trim((string)($ki_antwort[$k] ?? ''));
    $b = fn($k) => isset($ki_antwort[$k]) && $ki_antwort[$k] !== null && $ki_antwort[$k] !== '' ? (bool)$ki_antwort[$k] : null;
    $e = mb_strtolower($s('einheit'));
    $f = ['produkt' => $s('produkt'), 'kunde' => $s('kunde'), 'pa_nummer' => $s('pa_nummer'),
          'menge' => isset($ki_antwort['menge']) && $ki_antwort['menge'] !== null && $ki_antwort['menge'] !== '' ? zahl_lesen((string)$ki_antwort['menge'], true) : null,
          'einheit' => in_array($e, ['packung', 'kapsel', 'kg'], true) ? $e : '',
          'charge' => $s('charge'), 'datum' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s('datum')) ? $s('datum') : '',
          'bulk_charge' => $s('bulk_charge'), 'etikettiert' => $b('etikettiert'), 'verpackt' => $b('verpackt'),
          'mitarbeiter' => $s('mitarbeiter'), 'notiz' => $s('notiz')];
    return ['ok' => true, 'felder' => $f, 'fehler' => ''];
}

// Offene PAs mit Rest (nicht inaktiv, nicht storniert). $pa_vorgabe: PA-Seite (nur dieser). Treffer über PA-Nummer, Charge (Grundcharge =
// PA-Nummer), sonst Produkt-/Rezepturname (alle Wörter) und optional Kunde.
function pki_kandidaten(array $f, int $pa_vorgabe = 0): array {
    $sql = "SELECT pa.id, pa.nummer, pa.menge, pa.status, pa.produkt_id, pa.rezeptur_id, pa.auftrag_id,
                   COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name, r.name) AS produkt, k.firma AS kunde,
                   COALESCE(NULLIF(p.einheiten_pro_packung,0), NULLIF(a.stueck,0), 0) AS je_packung, COALESCE(rp.darreichungsform, r.darreichungsform) AS form,
                   pa.menge - (SELECT COALESCE(SUM(c.menge),0) FROM charge c WHERE c.pa_id=pa.id) AS rest
            FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id=pa.auftrag_id LEFT JOIN produkt p ON p.id=pa.produkt_id
            LEFT JOIN rezeptur r ON r.id=pa.rezeptur_id LEFT JOIN rezeptur rp ON rp.id=p.rezeptur_id LEFT JOIN kunden k ON k.id=COALESCE(a.kunde_id, pa.kunde_id)
            WHERE pa.status IN ('offen','laufend') AND (a.id IS NULL OR a.status<>'storniert')
              AND COALESCE(p.status,'')<>'inaktiv' AND COALESCE(r.status,'')<>'inaktiv'
            HAVING rest > 0";
    $alle = all($sql . " ORDER BY pa.id");
    if ($pa_vorgabe > 0) return array_values(array_filter($alle, fn($r) => (int)$r['id'] === $pa_vorgabe));
    $nr = preg_replace('/\D/', '', $f['pa_nummer'] ?? '');
    if ($nr === '' && preg_match('/^(\d{3,6})\.[A-Za-z]{1,2}$/', (string)($f['charge'] ?? ''), $m)) $nr = $m[1];   // Grundcharge = PA-Nummer
    if ($nr !== '') {
        $t = array_values(array_filter($alle, fn($r) => preg_replace('/\D/', '', (string)$r['nummer']) === $nr));
        if ($t) return $t;
    }
    $woerter = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)($f['produkt'] ?? ''))), fn($w) => mb_strlen($w) >= 3));
    if (!$woerter) return [];
    $t = array_values(array_filter($alle, function ($r) use ($woerter) {
        $hay = mb_strtolower((string)$r['produkt']);
        foreach ($woerter as $w) if (mb_strpos($hay, $w) === false) return false;
        return true;
    }));
    $ku = mb_strtolower(trim((string)($f['kunde'] ?? '')));
    if ($ku !== '' && count($t) > 1) { $tk = array_values(array_filter($t, fn($r) => mb_strpos(mb_strtolower((string)$r['kunde']), $ku) !== false)); if ($tk) $t = $tk; }
    return $t;
}

// Bestätigungs-Karte für EINEN PA: Vorbelegung des Nachtrag-Formulars + Rückfragen. Rückgabe-Felder siehe unten.
function pki_karte(array $f, array $pa): array {
    $pid = (int)$pa['id'];
    $rest = (float)$pa['rest']; $je = (int)$pa['je_packung'];
    $stueckForm = in_array((string)$pa['form'], ['kapsel', 'softgel', 'tablette'], true);
    $fragen = []; $hinweise = [];
    // Einheit: PA-Menge = Packungen (Gläser). Kapseln -> Gläser umrechnen. Unklar -> beide Lesarten zeigen, Wahl Pflicht.
    $menge = (float)($f['menge'] ?? 0);
    $lesarten = [];
    if ($menge > 0) {
        if ($f['einheit'] === 'packung' || !$stueckForm || $je <= 0) {
            $lesarten[] = ['wert' => round($menge), 'text' => pa_mz($menge) . ' Gläser/Packungen', 'zu_viel' => $menge > $rest + 1e-6];
            if ($f['einheit'] === '' && $stueckForm && $je <= 0) $hinweise[] = 'Einheit unklar und „Kapseln je Glas“ fehlt am Produkt – Menge bitte prüfen.';
        } else {
            $glas = floor($menge / $je);
            $lK = ['wert' => $glas, 'text' => pa_mz($menge) . ' Kapseln = ' . pa_mz($glas) . ' Gläser à ' . $je, 'zu_viel' => $glas > $rest + 1e-6];
            $lG = ['wert' => round($menge), 'text' => pa_mz($menge) . ' Gläser', 'zu_viel' => $menge > $rest + 1e-6];
            if ($f['einheit'] === 'kapsel') $lesarten[] = $lK;
            else { $lesarten = [$lK, $lG]; $fragen[] = 'Einheit unklar: Kapseln oder Gläser? Bitte eine Lesart wählen.'; }
        }
    } else $fragen[] = 'Keine Menge erkannt – bitte eintragen.';
    if (count($lesarten) === 1 && $lesarten[0]['zu_viel']) $fragen[] = 'Menge größer als der offene Rest (' . pa_mz($rest) . ' Stk).';
    // Bulk-Charge: genannte suchen, sonst ältere zuerst vorschlagen (änderbar).
    $rid = (int)($pa['rezeptur_id'] ?: scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$pa['produkt_id']]));
    $bulkWahl = all("SELECT c.id, c.charge_nr, c.menge_verfuegbar, c.einheit, c.mhd FROM charge c JOIN item i ON i.id=c.item_id
                     WHERE i.kategorie='fertig' AND c.status='frei' AND c.menge_verfuegbar>0 AND c.fremd_kunde_id IS NULL
                       AND (c.auftrag_id=? OR (c.auftrag_id IS NULL AND i.rezeptur_id=?)) ORDER BY (c.mhd IS NULL), c.mhd, c.id", [(int)($pa['auftrag_id'] ?? 0), $rid]);
    $bulkId = 0;
    if (($f['bulk_charge'] ?? '') !== '') {
        foreach ($bulkWahl as $bw) if (function_exists('charge_gleich') ? charge_gleich((string)$bw['charge_nr'], $f['bulk_charge']) : strcasecmp((string)$bw['charge_nr'], $f['bulk_charge']) === 0) { $bulkId = (int)$bw['id']; break; }
        if (!$bulkId) $fragen[] = 'Bulk-Charge „' . $f['bulk_charge'] . '“ nicht als freie Charge gefunden – bitte wählen.';
    } elseif ($bulkWahl) {
        $bulkId = (int)$bulkWahl[0]['id'];
        $hinweise[] = 'Bulk-Charge nicht genannt – Vorschlag: ältere Charge zuerst (' . $bulkWahl[0]['charge_nr'] . '), änderbar.';
    }
    // Charge: Vorschlag des Systems (Buchstabe je Tag/Bulk); Abweichung nur mit Grund.
    $datum = ($f['datum'] ?? '') !== '' ? $f['datum'] : heute_ort();
    $v = pa_vorgabe($pid, $bulkId, 0, $datum);
    $charge = ($f['charge'] ?? '') !== '' ? $f['charge'] : (string)$v['charge_nr'];
    $chargeAbw = strcasecmp($charge, (string)$v['charge_nr']) !== 0;
    if ($chargeAbw) $fragen[] = 'Charge ' . $charge . ' passt nicht zum Vorschlag ' . $v['charge_nr'] . ' – nur mit Grund.';
    // Etikett: fehlt/nicht freigegeben -> nur bis vor dem Etikettieren.
    $sperre = (string) (pa_fn('produktion_etikett_sperre', 'erp_etikett_sperre', $pid) ?? '');
    $vorEtikett = $sperre !== '' || ($f['etikettiert'] ?? null) === false;
    if ($sperre !== '' && ($f['etikettiert'] ?? null) !== false) $hinweise[] = 'Gemeldet „mit Etikett“, aber: ' . $sperre . ' Gebucht wird nur bis vor dem Etikettieren.';
    // Mitarbeiter: genannter Name, sonst die meldende Person.
    $ma = '';
    if (($f['mitarbeiter'] ?? '') !== '') { $ma = (string) scalar("SELECT name FROM benutzer WHERE aktiv=1 AND name LIKE ? ESCAPE '=' ORDER BY id LIMIT 1", ['%' . str_replace(['=', '%', '_'], ['==', '=%', '=_'], $f['mitarbeiter']) . '%']); }
    if ($ma === '' && function_exists('current_user')) $ma = (string)(current_user()['name'] ?? '');
    return ['pa' => $pa, 'lesarten' => $lesarten, 'bulk_wahl' => $bulkWahl, 'bulk_id' => $bulkId, 'datum' => $datum, 'charge' => $charge,
            'charge_vorschlag' => (string)$v['charge_nr'], 'charge_abw' => $chargeAbw, 'mhd' => (string)$v['mhd'], 'vor_etikett' => $vorEtikett,
            'etikett_sperre' => $sperre, 'mitarbeiter' => $ma, 'rest' => $rest, 'fragen' => $fragen, 'hinweise' => $hinweise];
}

// Ganze Meldung: Auswertung + PAs + (bei genau einem PA) Karte. $pa_wahl: vom Menschen gewählter PA (bei mehreren Treffern).
function pki_meldung(string $text, int $pa_vorgabe = 0, int $pa_wahl = 0, ?array $ki_antwort = null): array {
    $a = pki_auswerten($text, $ki_antwort);
    if (empty($a['ok'])) return ['ok' => false, 'fehler' => $a['fehler'], 'text' => $text, 'pa_vorgabe' => $pa_vorgabe];
    $kand = pki_kandidaten($a['felder'], $pa_vorgabe);
    if ($pa_wahl > 0) $kand = array_values(array_filter($kand, fn($r) => (int)$r['id'] === $pa_wahl));
    $out = ['ok' => true, 'text' => $text, 'felder' => $a['felder'], 'kandidaten' => $kand, 'karte' => null, 'pa_vorgabe' => $pa_vorgabe,
            'gemeldet_von' => function_exists('current_user') ? (string)(current_user()['name'] ?? '') : ''];
    if (count($kand) === 1) $out['karte'] = pki_karte($a['felder'], $kand[0]);
    return $out;
}
