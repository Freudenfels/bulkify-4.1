<?php
// Lieferanten-Rechnungen direkt in die Buchhaltung (13.11.2026, Anweisung Portale Teil 4).
// Lädt ein Lieferant im Portal eine Rechnung zur Bestellung hoch (bestell_dok_upload, Typ „rechnung“) oder trägt das Team sie an der
// Bestellung ein (bestellung_team_rechnung_hochladen), entsteht hier ein ENTWURF einer Eingangsrechnung in `lieferant_rechnung`
// (status='entwurf'), verknüpft mit Bestellung, Lieferant und Dokument. Die KI liest Nummer, Datum, Beträge, Währung, Fälligkeit und
// Bankverbindung aus; unsichere Felder werden gemerkt (gelb in der Prüfliste). Die Bankverbindung wird NUR verglichen, nie übernommen
// (Betrugsschutz). Prüfen, Übernehmen und Zahlen passiert in der Buchhaltung (buchhaltung/module/buchhaltung/lief_rechnung_pruefen.php).
// Kein Zahlungsauftrag, keine Mail an den Lieferanten. Doppelte Rechnung (gleiche Nummer, gleicher Lieferant) wird nicht angelegt.
// Die Tabelle gehört der Buchhaltung (kreditor_init); hier nur der Entwurf + additive Spalten (best effort, ensure_column).

function er_schema(): bool {
    static $ok = null; if ($ok !== null) return $ok;
    try { ensure_column('dokument', 'ersatz_fuer', "INT NULL"); } catch (\Throwable $e) {}   // Fable-6 T4.2: Ersatz-Upload einer Rechnung
    if (!table_exists('lieferant_rechnung')) return $ok = false;   // Buchhaltung noch nie gestartet -> kein Entwurf
    try {
        ensure_column('lieferant_rechnung', 'dokument_id', 'INT NULL');
        ensure_column('lieferant_rechnung', 'quelle', 'VARCHAR(10) NULL');          // portal | team
        ensure_column('lieferant_rechnung', 'ki_json', 'MEDIUMTEXT NULL');
        ensure_column('lieferant_rechnung', 'ki_unsicher', 'VARCHAR(255) NULL');    // Feldnamen, Komma
        ensure_column('lieferant_rechnung', 'bank_re', 'VARCHAR(190) NULL');        // Bankverbindung laut Rechnung (nur Anzeige)
        ensure_column('lieferant_rechnung', 'bank_warnung', 'VARCHAR(255) NULL');   // „Bankverbindung weicht ab“ …
        ensure_column('lieferant_rechnung', 'geprueft_am', 'DATETIME NULL');
        ensure_column('lieferant_rechnung', 'geprueft_von', 'VARCHAR(80) NULL');
    } catch (\Throwable $e) {}
    return $ok = true;
}

// Bestellwert netto (Summe Menge × EK) – Abgleich mit der Rechnung.
function er_bestellwert(int $bestellung_id): float {
    return (float) scalar("SELECT COALESCE(SUM(menge*ek_preis),0) FROM bestellung_position WHERE bestellung_id=? AND COALESCE(pos_storno_grund,'')=''", [$bestellung_id]);
}

// IBAN/Konto vergleichbar machen
function er_bank_norm(?string $s): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$s)); }

// Bankverbindung laut Rechnung gegen die hinterlegte vergleichen. Rückgabe ['ampel'=>ok|warn|info|'', 'text'=>…]. Nie übernehmen.
function er_bank_vergleich(array $lf, array $ki): array {
    $reIban = er_bank_norm($ki['iban'] ?? ''); $reKonto = er_bank_norm($ki['kontonummer'] ?? ''); $reSwift = er_bank_norm($ki['bic'] ?? '');
    if ($reIban === '' && $reKonto === '') return ['ampel' => '', 'text' => ''];
    $lfIban = er_bank_norm($lf['bank_iban'] ?? ''); $lfKonto = er_bank_norm($lf['bank_konto'] ?? '');
    if ($lfIban === '' && $lfKonto === '') return ['ampel' => 'info', 'text' => 'Beim Lieferanten ist keine Bankverbindung hinterlegt – Bankverbindung der Rechnung nicht übernommen, bitte beim Lieferanten bestätigen lassen.'];
    $passt = ($reIban !== '' && $lfIban !== '' && $reIban === $lfIban) || ($reKonto !== '' && $lfKonto !== '' && ($reKonto === $lfKonto || str_ends_with($lfIban, $reKonto)));
    if ($passt) return ['ampel' => 'ok', 'text' => 'Bankverbindung stimmt mit der hinterlegten überein.'];
    return ['ampel' => 'warn', 'text' => 'Bankverbindung weicht ab: Rechnung ' . ($reIban ?: ($reSwift . ' ' . $reKonto)) . ', hinterlegt ' . ($lfIban ?: $lfKonto) . '. Nicht zahlen, bevor der Lieferant die Änderung auf einem zweiten Weg bestätigt hat.'];
}

// KI liest die Rechnung. Rückgabe ['ok'=>bool, 'daten'=>[…], 'unsicher'=>[feld…], 'test'=>bool]
function er_ki_lesen(string $pfad): array {
    if (!function_exists('ki_bereit')) require_once __DIR__ . '/ki.php';
    $felder = ['rechnung_nr', 'datum', 'netto', 'ust_prozent', 'ust_betrag', 'brutto', 'waehrung', 'faellig', 'zahlungsziel_tage', 'iban', 'bic', 'kontonummer', 'bank_name'];
    $test = defined('BX_DATA') ? BX_DATA . '/er_ki_test.json' : '';
    if (!ki_bereit()) {
        // Testkopie ohne KI-Schlüssel: eingespielte Antwort (nur dort vorhanden, nie im Repo und nie live)
        if ($test !== '' && is_file($test) && is_array($d = json_decode((string) file_get_contents($test), true))) $r = ['ok' => true, 'daten' => $d, 'test' => true];
        else return ['ok' => false, 'daten' => [], 'unsicher' => $felder, 'fehler' => 'KI nicht eingerichtet'];
    } else {
        $anw = "Lies diese Lieferantenrechnung aus. Antworte als JSON mit genau diesen Schlüsseln: "
             . "rechnung_nr, datum (JJJJ-MM-TT), netto, ust_prozent, ust_betrag, brutto (Zahlen mit Punkt als Dezimaltrenner, ohne Tausenderpunkt), "
             . "waehrung (ISO, z. B. EUR, USD, CNY), faellig (JJJJ-MM-TT oder null), zahlungsziel_tage (Zahl oder null), "
             . "iban, bic, kontonummer, bank_name (so wie auf der Rechnung, sonst null), "
             . "und sicherheit: ein Objekt mit je Schlüssel 'hoch' oder 'niedrig' (niedrig = schlecht lesbar, geraten oder nicht gefunden). Erfinde nichts.";
        $r = ki_datei_frage($pfad, $anw, ['json' => true, 'max_tokens' => 1500, 'timeout' => 90, 'versuche' => 2, 'budget' => 150, 'zweck' => 'eingangsrechnung']);
        if (empty($r['ok'])) return ['ok' => false, 'daten' => [], 'unsicher' => $felder, 'fehler' => (string)($r['fehler'] ?? 'KI-Fehler')];
    }
    $d = (array)($r['daten'] ?? []);
    $sich = (array)($d['sicherheit'] ?? []);
    $unsicher = [];
    foreach ($felder as $f) if (!in_array($f, ['iban', 'bic', 'kontonummer', 'bank_name', 'zahlungsziel_tage', 'faellig'], true)
                                && (($d[$f] ?? null) === null || $d[$f] === '' || ($sich[$f] ?? 'hoch') === 'niedrig')) $unsicher[] = $f;
    return ['ok' => true, 'daten' => $d, 'unsicher' => $unsicher, 'test' => !empty($r['test'])];
}

// Entwurf aus einem Rechnungs-Dokument an einer Bestellung. Rückgabe ['ok'=>bool, 'id'=>?, 'doppelt'=>?, 'msg'=>…].
// Läuft nach der Antwort an den Browser (KI braucht bis zu einer Minute).
function er_entwurf_aus_dokument(int $dok_id, string $quelle = 'portal'): array {
    if (!er_schema()) return ['ok' => false, 'msg' => 'Buchhaltung (lieferant_rechnung) noch nicht eingerichtet.'];
    $d = one("SELECT * FROM dokument WHERE id=? AND typ='rechnung' AND objekt_typ='bestellung'", [$dok_id]);
    if (!$d) return ['ok' => false, 'msg' => 'Kein Rechnungs-Dokument.'];
    if ((int) scalar("SELECT COUNT(*) FROM lieferant_rechnung WHERE dokument_id=?", [$dok_id]) > 0) return ['ok' => false, 'msg' => 'Entwurf zu diesem Dokument gibt es schon.'];
    $b = one("SELECT id, nummer, lieferant_id FROM bestellung WHERE id=?", [(int)$d['objekt_id']]);
    if (!$b) return ['ok' => false, 'msg' => 'Bestellung fehlt.'];
    // Fable-6 T4.3 (13.11.2026): Ersatz-Upload -> KEIN zweiter Entwurf, sondern an die vorhandene Eingangsrechnung hängen.
    if (!empty($d['ersatz_fuer'])) {
        $alt = one("SELECT * FROM lieferant_rechnung WHERE dokument_id=? AND status<>'storniert' ORDER BY id DESC LIMIT 1", [(int)$d['ersatz_fuer']]);
        if (!$alt) $alt = one("SELECT * FROM lieferant_rechnung WHERE bestellung_id=? AND status<>'storniert' ORDER BY id DESC LIMIT 1", [(int)$b['id']]);
        if ($alt) return er_ersatz_anhaengen($alt, $d, $b);
    }
    $lid = (int)($b['lieferant_id'] ?? $d['lieferant_id'] ?? 0);
    $lf = $lid ? (one("SELECT * FROM lieferanten WHERE id=?", [$lid]) ?: []) : [];
    $ki = er_ki_lesen(BX_UPLOADS . '/' . basename((string)$d['datei']));
    $k = $ki['daten'];
    // Vom Team eingetippte Werte haben Vorrang vor der KI
    $nr  = trim((string)($d['rechnung_nr'] ?? '')) ?: trim((string)($k['rechnung_nr'] ?? ''));
    $dat = !empty($d['dok_datum']) ? (string)$d['dok_datum'] : (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($k['datum'] ?? '')) ? (string)$k['datum'] : null);
    $wae = strtoupper(trim((string)($d['waehrung'] ?? '') ?: (string)($k['waehrung'] ?? '') ?: (string)($lf['waehrung'] ?? '') ?: 'EUR'));
    $num = fn($x) => is_numeric($x) ? round((float)$x, 2) : (function_exists('betrag_lesen') && $x !== null && $x !== '' ? round(betrag_lesen((string)$x), 2) : 0.0);
    $brutto = $d['betrag'] !== null && $d['betrag'] !== '' ? round((float)$d['betrag'], 2) : $num($k['brutto'] ?? 0);
    $netto = $num($k['netto'] ?? 0);
    $ustP = is_numeric($k['ust_prozent'] ?? null) ? (float)$k['ust_prozent'] : 0.0;
    if ($netto <= 0 && $brutto > 0) $netto = $ustP > 0 ? round($brutto / (1 + $ustP / 100), 2) : $brutto;
    $ust = round($netto * $ustP / 100, 2);
    if ($brutto <= 0) $brutto = round($netto + $ust, 2);
    // Doppelt? gleiche Rechnungsnummer beim gleichen Lieferanten (nicht storniert) -> nicht anlegen
    if ($nr !== '' && $lid > 0) {
        // Fable-6 P35: Nummer normalisiert vergleichen („inv 2026-0815“ = „INV-2026-0815“)
        $dup = null;
        foreach (all("SELECT id, nummer, status, lief_nummer FROM lieferant_rechnung WHERE lieferant_id=? AND status<>'storniert' AND lief_nummer IS NOT NULL", [$lid]) as $x)
            if (er_nr_norm((string)$x['lief_nummer']) === er_nr_norm($nr)) { $dup = $x; break; }
        if ($dup && (string)$dup['status'] === 'entwurf') {   // noch Entwurf: die neue Datei ersetzt die alte (kein zweiter Entwurf)
            $altE = one("SELECT * FROM lieferant_rechnung WHERE id=?", [(int)$dup['id']]);
            if ($altE) return er_ersatz_anhaengen($altE, $d, $b);
        }
        if ($dup) {
            log_aktivitaet('bestellung', (int)$b['id'], 'system', 'Lieferantenrechnung ' . $nr . ' liegt schon als ' . $dup['nummer'] . ' in der Buchhaltung – kein zweiter Entwurf.', 'dokument', 'dokument', $dok_id);
            try { q("UPDATE dokument SET befund=? WHERE id=?", ['doppelt: ' . $dup['nummer'], $dok_id]); } catch (\Throwable $e) {}
            return ['ok' => false, 'doppelt' => (int)$dup['id'], 'msg' => 'Rechnung schon erfasst (' . $dup['nummer'] . ').'];
        }
    }
    $faellig = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($k['faellig'] ?? '')) ? (string)$k['faellig'] : null;
    $ziel = is_numeric($k['zahlungsziel_tage'] ?? null) ? (int)$k['zahlungsziel_tage'] : (isset($lf['zahlungsziel_tage']) && $lf['zahlungsziel_tage'] !== null ? (int)$lf['zahlungsziel_tage'] : null);
    if (!$faellig && $dat && $ziel !== null) $faellig = date('Y-m-d', strtotime($dat . ' +' . $ziel . ' days'));
    $bank = er_bank_vergleich($lf, $k);
    $bankRe = trim(implode(' ', array_filter([(string)($k['bank_name'] ?? ''), (string)($k['iban'] ?? ''), (string)($k['bic'] ?? ''), (string)($k['kontonummer'] ?? '')])));
    $erNr = function_exists('naechste_nummer') ? naechste_nummer('ER') : null;
    q("INSERT INTO lieferant_rechnung (nummer, lieferant_id, bestellung_id, lief_nummer, datum, eingang_am, waehrung, fx_kurs, fw_netto, netto, ust_prozent, ust_betrag, brutto,
                                       status, zahlungsziel_tage, faellig, notiz, datei, orig_name, mime, dokument_id, quelle, ki_json, ki_unsicher, bank_re, bank_warnung)
       VALUES (?,?,?,?,?, '" . heute_ort() . "', ?, ?, ?, ?, ?, ?, ?, 'entwurf', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
      [$erNr, $lid ?: null, (int)$b['id'], $nr !== '' ? mb_substr($nr, 0, 80) : null, $dat, $wae, $wae === 'EUR' ? 1 : null, $netto, $wae === 'EUR' ? $netto : 0, $ustP, $wae === 'EUR' ? $ust : 0, $wae === 'EUR' ? $brutto : 0,
       $ziel, $faellig, 'Entwurf aus ' . ($quelle === 'team' ? 'Team-Upload' : 'Lieferantenportal') . ' (Bestellung ' . $b['nummer'] . ')' . (!empty($ki['test']) ? ' – Testumgebung: eingespielte KI-Antwort' : ($ki['ok'] ? '' : ' – KI-Auslesen nicht möglich: ' . ($ki['fehler'] ?? ''))) . ($wae !== 'EUR' ? ' – Betrag in ' . $wae . ': ' . number_format($brutto, 2, ',', '.') . ' brutto, Kurs bitte prüfen' : ''),
       basename((string)$d['datei']), (string)($d['datei_orig'] ?: $d['datei']), strtolower(pathinfo((string)$d['datei'], PATHINFO_EXTENSION)) === 'pdf' ? 'application/pdf' : 'image/' . strtolower(pathinfo((string)$d['datei'], PATHINFO_EXTENSION)),
       $dok_id, $quelle, json_encode($k, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), implode(',', $ki['unsicher']), $bankRe !== '' ? mb_substr($bankRe, 0, 190) : null,
       $bank['ampel'] === 'warn' || $bank['ampel'] === 'info' ? mb_substr($bank['text'], 0, 255) : null]);
    $id = (int) insert_id();
    log_aktivitaet('bestellung', (int)$b['id'], 'system', 'Eingangsrechnung ' . ($nr ?: '(ohne Nummer)') . ' als Entwurf ' . $erNr . ' in der Buchhaltung angelegt – wartet auf Prüfung.', 'dokument', 'dokument', $dok_id);
    // Aufgabe an die Buchhaltung: normal, dringend wenn in unter 7 Tagen fällig
    if (function_exists('aufgabe_neu')) {
        try {
            $dringend = $faellig !== null && strtotime($faellig) < strtotime('+7 days');
            $betragTxt = number_format($brutto, 2, ',', '.') . ' ' . $wae;
            aufgabe_neu('Eingangsrechnung prüfen: ' . ((string)($lf['lieferantennummer'] ?? '') ?: 'Lieferant') . ', ' . ($nr ?: 'ohne Nr.') . ', ' . $betragTxt,
                        'Entwurf ' . $erNr . ' zu Bestellung ' . $b['nummer'] . ($faellig ? ', fällig am ' . date('d.m.Y', strtotime($faellig)) : '') . ($bank['ampel'] === 'warn' ? '. ACHTUNG: ' . $bank['text'] : '') . '. In der Buchhaltung unter „Eingangsrechnungen zu prüfen“.',
                        $dringend ? 1 : 2, null, null, null, 'eingangsrechnung', $id, '/buchhaltung/?p=lief_rechnung_pruefen&id=' . $id);
        } catch (\Throwable $e) {}
    }
    return ['ok' => true, 'id' => $id, 'msg' => 'Entwurf ' . $erNr . ' angelegt.'];
}

function er_nr_norm(string $nr): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $nr)); }
// Ersatz-Datei an eine vorhandene Eingangsrechnung hängen: neue Datei gilt, die alte bleibt gespeichert (Vermerk in der Notiz).
function er_ersatz_anhaengen(array $er, array $d, array $b): array {
    $altDatei = (string)($er['orig_name'] ?? $er['datei'] ?? '');
    $vermerk = 'Ersatz-Upload am ' . (function_exists('fmt_zeit') ? fmt_zeit(gmdate('Y-m-d H:i:s'), 'd.m.Y H:i') : gmdate('d.m.Y H:i')) . ' (' . ((string)($d['hochgeladen_von'] ?? '') === 'team' ? 'Team' : 'Lieferant') . '); vorherige Datei bleibt gespeichert: ' . $altDatei;
    q("UPDATE lieferant_rechnung SET dokument_id=?, datei=?, orig_name=?, mime=?, notiz=TRIM(CONCAT(COALESCE(notiz,''), ' | ', ?)) WHERE id=?",
      [(int)$d['id'], basename((string)$d['datei']), (string)($d['datei_orig'] ?: $d['datei']),
       strtolower(pathinfo((string)$d['datei'], PATHINFO_EXTENSION)) === 'pdf' ? 'application/pdf' : 'image/' . strtolower(pathinfo((string)$d['datei'], PATHINFO_EXTENSION)),
       $vermerk, (int)$er['id']]);
    log_aktivitaet('bestellung', (int)$b['id'], 'system', 'Ersatz-Rechnung an ' . ($er['nummer'] ?: 'Eingangsrechnung') . ' gehängt (kein zweiter Entwurf). ' . $vermerk, 'dokument', 'dokument', (int)$d['id']);
    if (function_exists('aufgabe_neu') && (string)$er['status'] !== 'entwurf') {
        try { aufgabe_neu('Ersatz-Rechnung prüfen: ' . ($er['nummer'] ?: '') . ' (' . $b['nummer'] . ')', 'Zur schon erfassten Eingangsrechnung ' . ($er['nummer'] ?: '') . ' kam eine Ersatz-Datei. ' . $vermerk . '. Bitte Betrag und Daten vergleichen.',
                              2, null, null, null, 'eingangsrechnung', (int)$er['id'], '/buchhaltung/?p=lief_rechnung&id=' . (int)$er['id']); } catch (\Throwable $e) {}
    }
    return ['ok' => true, 'id' => (int)$er['id'], 'ersatz' => true, 'msg' => 'Ersatz an ' . ($er['nummer'] ?: 'Eingangsrechnung') . ' gehängt.'];
}
// Link „in der Buchhaltung bezahlen“ (Fable-6 T4.1): Entwurf -> prüfen, sonst die Eingangsrechnung. '' = es gibt keine.
function er_link_bestellung(int $bestellung_id): string {
    if (!table_exists('lieferant_rechnung')) return '';
    $r = one("SELECT id, status FROM lieferant_rechnung WHERE bestellung_id=? AND status<>'storniert' ORDER BY (status='bezahlt'), id DESC LIMIT 1", [$bestellung_id]);
    if (!$r) return '';
    return (string)$r['status'] === 'entwurf' ? '/buchhaltung/?p=lief_rechnung_pruefen&id=' . (int)$r['id'] : '/buchhaltung/?p=lief_rechnung&id=' . (int)$r['id'];
}

// Text je Status für das Lieferantenportal (DE/EN/中文).
function er_status_text(array $r, string $sp): string {
    $t = fn($de, $en, $zh) => $sp === 'zh' ? $zh : ($sp === 'en' ? $en : $de);
    $d = fn($x) => $x ? date('d.m.Y', strtotime((string)$x)) : '';
    $eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
    return match ($r['status']) {
        'bezahlt'     => $t('bezahlt am ', 'paid on ', '已于 ') . $d($r['bezahlt_am']) . $t('', '', ' 付款'),
        'teilbezahlt' => $t('teilbezahlt, offen ', 'partly paid, open ', '部分付款，剩余 ') . $eur($r['rest']),
        'offen'       => $t('erfasst, noch nicht bezahlt', 'recorded, not paid yet', '已登记，尚未付款') . ($r['faellig'] ? $t(' (fällig ', ' (due ', '（到期 ') . $d($r['faellig']) . $t(')', ')', '）') : ''),
        default       => $t('eingegangen, wird geprüft', 'received, being checked', '已收到，审核中'),
    };
}
// Für das Lieferantenportal: Eingangsrechnungen einer Bestellung mit Zahlstatus (nur lesen).
// Rückgabe je Rechnung: lief_nummer, status (entwurf|offen|teilbezahlt|bezahlt|storniert), bezahlt_am, rest, waehrung.
function er_status_bestellung(int $bestellung_id): array {
    if (!table_exists('lieferant_rechnung')) return [];
    $out = [];
    $mitDok = er_schema();
    foreach (all("SELECT id, lief_nummer, status, brutto, waehrung, fw_netto, faellig" . ($mitDok ? ", dokument_id" : "") . " FROM lieferant_rechnung WHERE bestellung_id=? AND status<>'storniert' ORDER BY id", [$bestellung_id]) as $r) {
        $bez = table_exists('lieferant_zahlung') ? (float) scalar("SELECT COALESCE(SUM(betrag),0) FROM lieferant_zahlung WHERE lief_rechnung_id=?", [(int)$r['id']]) : 0.0;
        $am  = table_exists('lieferant_zahlung') ? (string) scalar("SELECT MAX(datum) FROM lieferant_zahlung WHERE lief_rechnung_id=?", [(int)$r['id']]) : '';
        $out[] = ['lief_nummer' => (string)$r['lief_nummer'], 'status' => (string)$r['status'], 'bezahlt_am' => $am ?: null,
                  'rest' => max(0, round((float)$r['brutto'] - $bez, 2)), 'faellig' => $r['faellig'], 'dokument_id' => (int)($r['dokument_id'] ?? 0)];
    }
    return $out;
}
