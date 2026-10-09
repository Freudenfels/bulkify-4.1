<?php
// Beleg-/Rechnungs-/Gutschrift-Funktionen – VERBATIM aus dem Dashboard-core/schema.php übernommen
// (bewusste Doppelung, da die Sub-App die Dashboard-schema.php nicht einbinden darf: db()-Kollision).
// Abhängigkeiten liefern db.php (q/all/one/scalar/insert_id), erp.php (meta_get, naechste_nummer,
// log_aktivitaet) und ki.php (ki_bereit, ki_datei_frage). Lese-Zugriffe auf auftrag/kunden/produkt
// stehen (noch) als Roh-SQL in diesen Funktionen – beim Umbenennen dieser Spalten hier mitprüfen.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/erp.php';

// ===== Block A: Rechnungserzeugung (aus Auftrag / frei / KI-Import) =====

function rechnung_aus_auftrag(int $auftrag_id, array $opt = []): ?int {
    $a = one("SELECT * FROM auftrag WHERE id=?", [$auftrag_id]);
    if (!$a) return null;
    $ex = scalar("SELECT id FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert' ORDER BY id LIMIT 1", [$auftrag_id]);
    if ($ex) return (int)$ex;                                   // schon da -> nicht doppelt
    $menge = (int)($a['menge'] ?? 0);
    $vk    = (float)($a['vk_stueck'] ?? 0);
    $netto = round((float)($a['gesamt_netto'] ?? 0), 2);
    if ($netto <= 0) $netto = round($menge * $vk, 2);
    if ($netto <= 0) return null;                               // ohne Preis keine Rechnung
    // USt: explizit vorgegeben? sonst Kleinunternehmer/EU-Ausland 0 %, sonst Inland.
    if (isset($opt['ust_prozent']) && $opt['ust_prozent'] !== '' && $opt['ust_prozent'] !== null) {
        $ustP = max(0.0, (float)$opt['ust_prozent']);
    } else {
        $land = scalar("SELECT land FROM kunden WHERE id=?", [$a['kunde_id']]) ?: 'DE';
        $ustInland = (float) meta_get('ust_inland', 19);
        $ustP = (meta_get('kleinunternehmer', '0') === '1' || $land !== 'DE') ? 0.0 : $ustInland;
    }
    $ust = round($netto * $ustP / 100, 2); $brutto = $netto + $ust;
    // Datum / Fälligkeit / Leistungsdatum.
    $gilt = fn($d) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    $datum = $gilt($opt['datum'] ?? null) ?? gmdate('Y-m-d');
    $ziel  = (isset($opt['zahlungsziel_tage']) && $opt['zahlungsziel_tage'] !== '') ? max(0, (int)$opt['zahlungsziel_tage']) : null;
    $faellig = ($ziel !== null) ? date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days')) : null;
    $leist = $gilt($opt['leistung_datum'] ?? null);
    $text  = trim((string)($opt['text'] ?? '')) ?: null;
    $sicht = !empty($opt['freigeben']) ? 1 : 0;                  // standardmaessig NICHT fuer den Kunden freigegeben
    $bearb = (int)($opt['bearbeiter_id'] ?? 0) ?: null;
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,zahlungsziel_tage,faellig,leistung_datum,text,bearbeiter_id,kunde_sichtbar)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('RE'), 'rechnung', $auftrag_id, ($a['kunde_id'] ?: null), $netto, $ustP, $ust, $brutto, 'offen',
       $datum, $ziel, $faellig, $leist, $text, $bearb, $sicht]);
    $bid = (int) insert_id();
    // Ersteller als Bearbeiter im Verlauf festhalten.
    $ersteller = trim((string)($opt['ersteller'] ?? '')) ?: 'team';
    if (function_exists('beleg_status_log_add')) beleg_status_log_add($bid, 'offen', 'Rechnung aus Auftrag ' . (string)$a['nummer'] . ' erstellt' . ($sicht ? ', für Kunde freigegeben' : ''), $ersteller);
    // Eine Positionszeile aus dem Auftrag (Produkt × Menge × VK). Passt die Zeilensumme nicht exakt
    // zum Netto (z. B. Sub-Cent-Preise), wird eine Pauschal-Zeile (Menge 1 = Netto) gesetzt.
    $prodName = (string) (scalar("SELECT COALESCE(NULLIF(p.kundenname,''), p.name) FROM produkt p WHERE p.id=?", [(int)($a['produkt_id'] ?? 0)])
        ?: ($a['produkt_bezeichnung'] ?? '')) ?: ('Leistung laut Auftrag ' . (string)$a['nummer']);
    $preisCent = (int) round($vk * 100);
    $nettoCent = (int) round($netto * 100);
    if ($menge > 0 && $preisCent * $menge === $nettoCent) { $pMenge = $menge; $pEinheit = 'Stk.'; }
    else { $pMenge = 1; $preisCent = $nettoCent; $pEinheit = ''; }
    q("INSERT INTO beleg_position (beleg_id,sort,bezeichnung,menge,einheit,preis_cent,mwst_satz) VALUES (?,0,?,?,?,?,?)",
      [$bid, $prodName, $pMenge, $pEinheit, $preisCent, $ustP]);
    if (!empty($a['kunde_id'])) {
        $re = (string) scalar("SELECT nummer FROM beleg WHERE id=?", [$bid]);
        log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'Rechnung ' . $re . ' aus Auftrag ' . (string)$a['nummer'] . ' erstellt.', 'beleg', 'auftrag', $auftrag_id);
    }
    // Automatisch die aufgeschlüsselten Positionen aus dem Angebot übernehmen (ersetzt die Sammelposition,
    // falls das Angebot Einzelpositionen hat). Schlägt das fehl/gibt es keine, bleibt die Sammelposition stehen.
    if (function_exists('beleg_positionen_aus_angebot')) { try { beleg_positionen_aus_angebot($bid); } catch (Throwable $e) {} }
    return $bid;
}

// Freie Rechnung (ohne Auftrag) anlegen – Kopf + eigene Positionen. Für die KI-gestützte und die
// manuelle Rechnungserstellung im Rechnungen-Menü. Positionen: [{artikelnr,bezeichnung,beschreibung,
// menge,einheit,preis (€, positiv),mwst_satz}]. $opt: kunde_id, datum, zahlungsziel_tage,
// leistung_datum, text, freigeben (bool → im Kundenportal sichtbar), ersteller. Gibt Beleg-ID oder null.
function rechnung_frei_erstellen(array $positionen, array $opt = []): ?int {
    $pos = [];
    foreach ($positionen as $p) {
        if (trim((string)($p['bezeichnung'] ?? '')) === '') continue;
        $menge = (float) str_replace(',', '.', (string)($p['menge'] ?? 1)); if ($menge <= 0) $menge = 1;
        $pos[] = [
            'artikelnr'   => trim((string)($p['artikelnr'] ?? '')),
            'bezeichnung' => (string)$p['bezeichnung'],
            'beschreibung'=> trim((string)($p['beschreibung'] ?? '')),
            'menge'       => $menge,
            'einheit'     => trim((string)($p['einheit'] ?? '')),
            'preis_cent'  => abs((int) round((float) str_replace(',', '.', (string)($p['preis'] ?? 0)) * 100)),
            'mwst_satz'   => (float) str_replace(',', '.', (string)($p['mwst_satz'] ?? $p['ust'] ?? 0)),
        ];
    }
    if (!$pos) return null;
    $s = beleg_summen_aus_positionen($pos);
    if ($s['netto'] <= 0) return null;                           // ohne Betrag keine Rechnung
    $ustP = 0.0;
    foreach ($pos as $p) if ((float)$p['mwst_satz'] > 0) { $ustP = (float)$p['mwst_satz']; break; }
    $gilt  = fn($d) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    $datum = $gilt($opt['datum'] ?? null) ?? gmdate('Y-m-d');
    $ziel  = (isset($opt['zahlungsziel_tage']) && $opt['zahlungsziel_tage'] !== '') ? max(0, (int)$opt['zahlungsziel_tage']) : null;
    $faellig = ($ziel !== null) ? date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days')) : null;
    $leist = $gilt($opt['leistung_datum'] ?? null);
    $text  = trim((string)($opt['text'] ?? '')) ?: null;
    $kid   = (int)($opt['kunde_id'] ?? 0) ?: null;
    $bearb = (int)($opt['bearbeiter_id'] ?? 0) ?: null;
    $sicht = !empty($opt['freigeben']) ? 1 : 0;                  // standardmäßig NICHT für den Kunden freigegeben
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,zahlungsziel_tage,faellig,leistung_datum,text,bearbeiter_id,kunde_sichtbar)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('RE'), 'rechnung', null, $kid, $s['netto'], $ustP, $s['ust'], $s['brutto'], 'offen',
       $datum, $ziel, $faellig, $leist, $text, $bearb, $sicht]);
    $bid = (int) insert_id();
    $sort = 0;
    foreach ($pos as $p)
        q("INSERT INTO beleg_position (beleg_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,mwst_satz) VALUES (?,?,?,?,?,?,?,?,?)",
          [$bid, $sort++, $p['artikelnr'] ?: null, $p['bezeichnung'], $p['beschreibung'] ?: null,
           $p['menge'], $p['einheit'] ?: null, $p['preis_cent'], $p['mwst_satz']]);
    $ersteller = trim((string)($opt['ersteller'] ?? '')) ?: 'team';
    if (function_exists('beleg_status_log_add')) beleg_status_log_add($bid, 'offen', 'Rechnung manuell erstellt' . ($sicht ? ', für Kunde freigegeben' : ''), $ersteller);
    if ($kid) log_aktivitaet('kunde', $kid, 'team', 'Rechnung ' . scalar("SELECT nummer FROM beleg WHERE id=?", [$bid]) . ' erstellt.', 'beleg', 'beleg', $bid);
    return $bid;
}

// Eine hochgeladene (ältere) Original-Rechnung per KI auslesen. Rückgabe (immer, wirft nie):
//   ['ok'=>true,'nummer','datum'(Y-m-d|null),'netto','ust_prozent','brutto','bezahlt'(bool),'bezahlt_am']
//   ['ok'=>false,'fehler'=>'…']
function rechnung_import_ki(string $pfad): array {
    require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (Einstellungen → KI).'];
    $prompt = "Dies ist eine (ältere) Ausgangsrechnung. Lies die Kopfdaten aus und gib NUR JSON zurück:\n"
        . '{"nummer":"","datum":"","netto":0,"ust_prozent":19,"brutto":0,"bezahlt":false,"bezahlt_am":""}' . "\n"
        . "datum und bezahlt_am im Format YYYY-MM-DD. brutto = Gesamt-/Rechnungsbetrag inkl. USt (Endsumme). "
        . "netto = Nettosumme, ust_prozent = USt-Satz in Prozent (0 wenn keiner ausgewiesen). "
        . "bezahlt = true NUR wenn die Rechnung klar als bezahlt gekennzeichnet ist (z. B. 'bezahlt', "
        . "'Betrag erhalten', 'Zahlungseingang', Quittung), sonst false. Zahlen mit Punkt als Dezimaltrennzeichen, "
        . "keine Tausenderpunkte. Nichts erfinden – unbekannte Felder leer/0.";
    $r = ki_datei_frage($pfad, $prompt, ['json' => true, 'max_tokens' => 1500, 'zweck' => 'rechnung-import']);
    if (empty($r['ok'])) return ['ok' => false, 'fehler' => (string)($r['fehler'] ?? 'Die Rechnung konnte nicht gelesen werden.')];
    $d = is_array($r['daten'] ?? null) ? $r['daten'] : [];
    $num  = fn($x) => (float) str_replace(',', '.', (string)$x);
    $gilt = fn($s) => (is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) ? $s : null;
    $brutto = $num($d['brutto'] ?? 0); $netto = $num($d['netto'] ?? 0); $ustP = $num($d['ust_prozent'] ?? 0);
    if ($brutto <= 0 && $netto > 0) $brutto = round($netto * (1 + $ustP / 100), 2);
    if ($netto <= 0 && $brutto > 0) $netto = $ustP > 0 ? round($brutto / (1 + $ustP / 100), 2) : $brutto;
    return ['ok' => true,
        'nummer'      => trim((string)($d['nummer'] ?? '')),
        'datum'       => $gilt($d['datum'] ?? null),
        'netto'       => round($netto, 2), 'ust_prozent' => $ustP, 'brutto' => round($brutto, 2),
        'bezahlt'     => !empty($d['bezahlt']),
        'bezahlt_am'  => $gilt($d['bezahlt_am'] ?? null)];
}

// Eine Alt-Rechnung als Beleg anlegen (ohne Auftrag), mit hochgeladenem Original-PDF. Für den
// Rechnungs-Import: erscheint danach im Kundenportal (Liste + Original-Download) mit Betrag + Status.
// $f: nummer, datum, netto, ust_prozent, brutto, bezahlt(bool). Gibt die Beleg-ID zurück.
function rechnung_alt_anlegen(int $kunde_id, array $f, string $datei, string $orig, ?int $auftrag_id = null): ?int {
    if ($kunde_id <= 0) return null;
    $brutto = round((float)($f['brutto'] ?? 0), 2);
    $netto  = round((float)($f['netto'] ?? 0), 2);
    $ustP   = (float)($f['ust_prozent'] ?? 0);
    if ($netto <= 0 && $brutto > 0) $netto = $ustP > 0 ? round($brutto / (1 + $ustP / 100), 2) : $brutto;
    $ust = round($netto * $ustP / 100, 2);
    if ($brutto <= 0) $brutto = round($netto + $ust, 2);
    if ($brutto <= 0) return null;   // ohne Betrag keine sinnvolle Rechnung
    $nummer = trim((string)($f['nummer'] ?? '')) ?: naechste_nummer('RE');
    $datum  = (is_string($f['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['datum'])) ? $f['datum'] : gmdate('Y-m-d');
    $status = !empty($f['bezahlt']) ? 'bezahlt' : 'offen';
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,original_datei,original_orig,kunde_sichtbar)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)",
      [$nummer, 'rechnung', ($auftrag_id ?: null), $kunde_id, $netto, $ustP, $ust, $brutto, $status, $datum, $datei, mb_substr($orig, 0, 255)]);
    $bid = (int) insert_id();
    if (function_exists('beleg_status_log_add')) beleg_status_log_add($bid, $status, 'Alt-Rechnung importiert (Original hochgeladen)' . ($status === 'bezahlt' ? ', als bezahlt übernommen' : ''), 'team');
    log_aktivitaet('kunde', $kunde_id, 'team', 'Alt-Rechnung ' . $nummer . ' importiert (' . number_format($brutto, 2, ',', '.') . ' €).', 'beleg', 'beleg', $bid);
    return $bid;
}

// Eine hochgeladene Rechnung/AB per KI in EINZELNE POSITIONEN auslesen (Herstellung/Kapseln, Glas/Dose,
// Etiketten …) – für die aufgeschlüsselte Preisübernahme am Auftrag. Rückgabe (wirft nie):
//   ['ok'=>true,'nummer','datum','bezahlt'(bool),'positionen'=>[{bezeichnung,menge,einheit,einzelpreis,ust}]]
function rechnung_import_positionen_ki(string $pfad): array {
    require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'KI ist nicht eingerichtet (Einstellungen → KI).'];
    $prompt = "Dies ist eine Rechnung oder Auftragsbestätigung eines Lohnherstellers für Nahrungsergänzung. "
        . "Lies den Kopf und ALLE Positionszeilen aus und gib NUR JSON zurück:\n"
        . '{"nummer":"","datum":"","bezahlt":false,"positionen":[{"bezeichnung":"","menge":0,"einheit":"","einzelpreis":0,"ust":19}]}' . "\n"
        . "einzelpreis = NETTO-Einzelpreis je Einheit (NICHT die Zeilensumme). Typische Zeilen: die Herstellung "
        . "(Kapseln/Tabletten je Packung), die Verpackung (Dose/Glas), die Etiketten – jede als eigene Position "
        . "mit ihrem Preis. bezeichnung kurz (z. B. 'Herstellung 120 Kapseln', 'Weithalsglas 150 ml', 'Etiketten'). "
        . "datum im Format YYYY-MM-DD. bezahlt nur true, wenn klar als bezahlt gekennzeichnet. "
        . "Zahlen mit Punkt als Dezimaltrennzeichen, keine Tausenderpunkte. Nichts erfinden.";
    $r = ki_datei_frage($pfad, $prompt, ['json' => true, 'denken' => true, 'max_tokens' => 4000, 'timeout' => 240, 'zweck' => 'rechnung-positionen']);
    if (empty($r['ok'])) return ['ok' => false, 'fehler' => (string)($r['fehler'] ?? 'Die Rechnung konnte nicht gelesen werden.')];
    $d = is_array($r['daten'] ?? null) ? $r['daten'] : [];
    $num = fn($x) => (float) str_replace(',', '.', (string)$x);
    $list = (isset($d['positionen']) && is_array($d['positionen'])) ? $d['positionen'] : [];
    $pos = [];
    foreach ($list as $p) {
        if (!is_array($p)) continue;
        $bez = trim((string)($p['bezeichnung'] ?? '')); if ($bez === '') continue;
        $menge = $num($p['menge'] ?? 1); if ($menge <= 0) $menge = 1;
        $pos[] = ['bezeichnung' => mb_substr($bez, 0, 255), 'menge' => $menge,
                  'einheit' => mb_substr(trim((string)($p['einheit'] ?? '')), 0, 20),
                  'einzelpreis' => $num($p['einzelpreis'] ?? $p['preis'] ?? 0),
                  'ust' => $num($p['ust'] ?? 0)];
    }
    return ['ok' => true,
        'nummer'  => trim((string)($d['nummer'] ?? '')),
        'datum'   => (is_string($d['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['datum'])) ? $d['datum'] : null,
        'bezahlt' => !empty($d['bezahlt']),
        'positionen' => $pos];
}

// ===== Block B: Status / Zahlungen / Gutschrift =====

function beleg_status_log_add(int $beleg_id, string $status, string $notiz = '', string $akteur = 'System'): void {
    q("INSERT INTO beleg_status_log (beleg_id,status,notiz,akteur,angelegt) VALUES (?,?,?,?,?)",
      [$beleg_id, $status, $notiz ?: null, $akteur ?: null, gmdate('Y-m-d H:i:s')]);
}

// Hinterlegte Bankkonten (aus Einstellungen). Liefert nur befüllte Konten: [['key'=>'de','label'=>'…'], …].
// Frei angelegte Zusatzkonten (app_meta bank_konten_extra, JSON [{id,name,iban,bic}]). Vom Dashboard gepflegt
// (Einstellungen → Firma); die Buchhaltung liest sie nur. Verbatim wie im Dashboard-core/schema.php.
function bank_konten_extra(): array {
    $d = json_decode((string) meta_get('bank_konten_extra', ''), true);
    return is_array($d) ? array_values(array_filter($d, fn($b) => is_array($b) && !empty($b['id']))) : [];
}
function bank_konten(): array {
    $out = [];
    foreach ([['de','bank_de_name','bank_de_iban'], ['int','bank_int_name','bank_int_iban']] as $kf) {
        [$key, $nk, $ik] = $kf;
        $name = trim((string) meta_get($nk, '')); $iban = trim((string) meta_get($ik, ''));
        if ($name === '' && $iban === '') continue;
        $tail = $iban !== '' ? ' · …' . substr(preg_replace('/\s+/', '', $iban), -4) : '';
        $out[] = ['key' => $key, 'label' => ($name ?: strtoupper($key)) . $tail];
    }
    // Weitere, frei angelegte Konten (beliebig viele) – wichtig: wohin hat der Kunde überwiesen.
    foreach (bank_konten_extra() as $b) {
        $name = trim((string)($b['name'] ?? '')); $iban = trim((string)($b['iban'] ?? ''));
        if ($name === '' && $iban === '') continue;
        $tail = $iban !== '' ? ' · …' . substr(preg_replace('/\s+/', '', $iban), -4) : '';
        $out[] = ['key' => (string)$b['id'], 'label' => ($name ?: 'Bank') . $tail];
    }
    return $out;
}
// Volle Bankdaten zu einem Konto-Key (de/int oder Extra-id): ['name','iban','bic'] oder null.
function bank_konto_details(?string $key): ?array {
    if (!$key) return null;
    if ($key === 'de')  return ['name'=>trim((string)meta_get('bank_de_name','')),  'iban'=>trim((string)meta_get('bank_de_iban','')),  'bic'=>trim((string)meta_get('bank_de_bic',''))];
    if ($key === 'int') return ['name'=>trim((string)meta_get('bank_int_name','')), 'iban'=>trim((string)meta_get('bank_int_iban','')), 'bic'=>trim((string)meta_get('bank_int_bic',''))];
    foreach (bank_konten_extra() as $b) if ((string)($b['id'] ?? '') === $key)
        return ['name'=>trim((string)($b['name'] ?? '')), 'iban'=>trim((string)($b['iban'] ?? '')), 'bic'=>trim((string)($b['bic'] ?? ''))];
    return null;
}
// Anzeigename eines gespeicherten Kontos anhand des key/Werts (Fallback: der gespeicherte Wert selbst).
function bank_konto_label(?string $konto): string {
    if (!$konto) return '';
    foreach (bank_konten() as $bk) if ($bk['key'] === $konto) return $bk['label'];
    return $konto;
}

function zahlungen_fuer(int $beleg_id): array {
    return all("SELECT * FROM zahlung WHERE beleg_id=? ORDER BY datum ASC, id ASC", [$beleg_id]);
}
function zahlung_summe(int $beleg_id): float {
    return (float) scalar("SELECT COALESCE(SUM(betrag),0) FROM zahlung WHERE beleg_id=?", [$beleg_id]);
}
// Abgeleiteter Zahlstatus aus Summe der Eingänge vs. Brutto. 'storniert' bleibt erhalten.
// Rückgabe: ['status'=>offen|teilbezahlt|bezahlt|storniert, 'bezahlt'=>float, 'rest'=>float, 'brutto'=>float]
function beleg_zahlstatus(array $beleg): array {
    $brutto = (float) $beleg['brutto'];
    $bezahlt = zahlung_summe((int) $beleg['id']);
    $rest = round($brutto - $bezahlt, 2);
    if (($beleg['status'] ?? '') === 'storniert') $status = 'storniert';
    elseif ($bezahlt <= 0.005)                    $status = 'offen';
    elseif ($rest > 0.005)                        $status = 'teilbezahlt';
    else                                          $status = 'bezahlt';
    return ['status' => $status, 'bezahlt' => $bezahlt, 'rest' => max(0, $rest), 'brutto' => $brutto];
}
// Zahlungseingang erfassen + Status automatisch nachziehen + Statusverlauf schreiben.
function zahlung_erfassen(int $beleg_id, float $betrag, ?string $datum, ?string $konto, ?string $art, string $notiz = '', string $akteur = 'System'): void {
    q("INSERT INTO zahlung (beleg_id,betrag,datum,konto,art,notiz,akteur,angelegt) VALUES (?,?,?,?,?,?,?,?)",
      [$beleg_id, $betrag, $datum ?: null, $konto ?: null, $art ?: null, $notiz ?: null, $akteur ?: null, gmdate('Y-m-d H:i:s')]);
    $b = one("SELECT * FROM beleg WHERE id=?", [$beleg_id]);
    if (!$b) return;
    $zs = beleg_zahlstatus($b);
    beleg_status_verlauf($beleg_id);   // Backfill „erstellt" sicherstellen
    $euro = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
    $wann = $datum ? date('d.m.Y', strtotime($datum)) : 'ohne Datum';
    $kt = bank_konto_label($konto);
    $txt = 'Zahlung ' . $euro($betrag) . ' (Valuta ' . $wann . ($kt ? ', ' . $kt : '') . ')'
         . ($zs['status'] === 'teilbezahlt' ? ' – Rest ' . $euro($zs['rest']) : '');
    if ($zs['status'] !== ($b['status'] ?? '')) q("UPDATE beleg SET status=? WHERE id=?", [$zs['status'], $beleg_id]);
    beleg_status_log_add($beleg_id, $zs['status'], $txt, $akteur);
    if ($zs['status'] === 'bezahlt' && $b['kunde_id']) log_aktivitaet('kunde', (int)$b['kunde_id'], 'team', 'Rechnung ' . $b['nummer'] . ' vollständig bezahlt.', 'beleg', 'beleg', $beleg_id);
}

// Deutschen/englischen Geldbetrag ROBUST lesen: Tausenderpunkte entfernen, Dezimal-Komma -> Punkt.
// Wichtig: (float) str_replace(',', '.', "21.687,75") bricht beim 2. Punkt ab -> 21.687 (Fehlbuchung!).
// "21.687,75" -> 21687.75 | "21687,75" -> 21687.75 | "21,687.75" -> 21687.75 | "21.50" -> 21.50.
function be_betrag_lesen(string $s): float {
    $s = preg_replace('/[^0-9,.\-]/', '', trim($s));
    if ($s === '' || $s === '-') return 0.0;
    $k = strrpos($s, ','); $p = strrpos($s, '.');
    if ($k !== false && $p !== false) {
        if ($k > $p) $s = str_replace(',', '.', str_replace('.', '', $s));   // DE: Punkt=Tausender, Komma=Dezimal
        else         $s = str_replace(',', '', $s);                          // EN: Komma=Tausender, Punkt=Dezimal
    } elseif ($k !== false) {
        $s = str_replace(',', '.', $s);                                      // nur Komma -> Dezimal
    }
    return (float) $s;
}

// Eine einzelne Zahlung loeschen + Belegstatus neu ziehen (Korrektur von Fehlbuchungen).
function zahlung_loeschen(int $zahlung_id, int $beleg_id, string $akteur = 'System'): bool {
    $z = one("SELECT betrag FROM zahlung WHERE id=? AND beleg_id=?", [$zahlung_id, $beleg_id]);
    if (!$z) return false;
    q("DELETE FROM zahlung WHERE id=? AND beleg_id=?", [$zahlung_id, $beleg_id]);
    $b = one("SELECT * FROM beleg WHERE id=?", [$beleg_id]);
    if ($b) {
        $zs = beleg_zahlstatus($b);
        if ($zs['status'] !== ($b['status'] ?? '')) q("UPDATE beleg SET status=? WHERE id=?", [$zs['status'], $beleg_id]);
        $euro = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
        beleg_status_log_add($beleg_id, $zs['status'], 'Zahlung ' . $euro($z['betrag']) . ' gelöscht (Korrektur)', $akteur);
    }
    return true;
}

// Statusverlauf lesen; legt bei fehlendem Verlauf einmalig einen „erstellt"-Eintrag aus beleg.angelegt an (Backfill für Altbelege).
function beleg_status_verlauf(int $beleg_id): array {
    $rows = all("SELECT * FROM beleg_status_log WHERE beleg_id=? ORDER BY angelegt ASC, id ASC", [$beleg_id]);
    if (!$rows) {
        $b = one("SELECT angelegt FROM beleg WHERE id=?", [$beleg_id]);
        if ($b) {
            q("INSERT INTO beleg_status_log (beleg_id,status,notiz,akteur,angelegt) VALUES (?,?,?,?,?)",
              [$beleg_id, 'erstellt', 'Beleg erstellt', 'System', $b['angelegt']]);
            $rows = all("SELECT * FROM beleg_status_log WHERE beleg_id=? ORDER BY angelegt ASC, id ASC", [$beleg_id]);
        }
    }
    return $rows;
}

// ===== Gutschrift / Storno-Rechnung =====
// Positionen eines Belegs im build_beleg_pdf-Format.
function beleg_positionen(int $beleg_id): array {
    $out = [];
    foreach (all("SELECT * FROM beleg_position WHERE beleg_id=? ORDER BY sort, id", [$beleg_id]) as $p) {
        $out[] = [
            'artikelnr'   => (string)($p['artikelnr'] ?? ''),
            'bezeichnung' => (string)$p['bezeichnung'],
            'beschreibung'=> (string)($p['beschreibung'] ?? ''),
            'menge'       => (float)$p['menge'],
            'einheit'     => (string)($p['einheit'] ?? ''),
            'preis_cent'  => (int)$p['preis_cent'],
            'ek_cent'     => 0,
            'mwst_satz'   => (float)$p['mwst_satz'],
        ];
    }
    return $out;
}
// Summen (netto/ust/brutto in EUR) aus Positionen – je Position belegkonform auf Cent gerundet.
function beleg_summen_aus_positionen(array $positionen): array {
    $nettoCent = 0; $ustCent = 0;
    foreach ($positionen as $p) {
        $zeileCent = (int) round((float)$p['menge'] * (int)$p['preis_cent']);
        $nettoCent += $zeileCent;
        $ustCent   += (int) round($zeileCent * (float)($p['mwst_satz'] ?? 0) / 100);
    }
    return ['netto'=>$nettoCent/100, 'ust'=>$ustCent/100, 'brutto'=>($nettoCent+$ustCent)/100];
}
// Generische Gutschrift/Storno-Rechnung anlegen. Positionen wie beim Angebot (preis_cent bei Gutschrift negativ). Gibt Beleg-ID.
function gutschrift_erstellen(int $kunde_id, ?string $datum, array $positionen, string $grund = '', ?int $storno_von = null, ?int $auftrag_id = null): int {
    $s = beleg_summen_aus_positionen($positionen);
    $ustP = 0.0;
    foreach ($positionen as $p) if ((float)($p['mwst_satz'] ?? 0) > 0) { $ustP = (float)$p['mwst_satz']; break; }
    q("INSERT INTO beleg (nummer,typ,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,storno_von_id,grund)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('GS'), 'gutschrift', $auftrag_id ?: null, $kunde_id ?: null,
       $s['netto'], $ustP, $s['ust'], $s['brutto'], 'erstellt', $datum ?: date('Y-m-d'), $storno_von ?: null, ($grund !== '' ? $grund : null)]);
    $bid = insert_id();
    $sort = 0;
    foreach ($positionen as $p) {
        if (trim((string)($p['bezeichnung'] ?? '')) === '') continue;
        q("INSERT INTO beleg_position (beleg_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,mwst_satz) VALUES (?,?,?,?,?,?,?,?,?)",
          [$bid, $sort++, trim((string)($p['artikelnr'] ?? '')) ?: null, (string)$p['bezeichnung'], trim((string)($p['beschreibung'] ?? '')) ?: null,
           (float)$p['menge'], trim((string)($p['einheit'] ?? '')) ?: null, (int)$p['preis_cent'], (float)($p['mwst_satz'] ?? 0)]);
    }
    beleg_status_log_add($bid, 'erstellt', $grund !== '' ? $grund : 'Gutschrift erstellt', 'team');
    if ($kunde_id) log_aktivitaet('kunde', $kunde_id, 'team', 'Gutschrift ' . scalar("SELECT nummer FROM beleg WHERE id=?", [$bid]) . ' erstellt.', 'beleg', 'beleg', $bid);
    return $bid;
}
// Bestehende Rechnung stornieren: Gutschrift (negativ) erzeugen + Original auf 'storniert'. Idempotent.
function gutschrift_aus_rechnung(int $rechnung_id, string $grund = '', string $akteur = 'team'): ?int {
    $b = one("SELECT * FROM beleg WHERE id=? AND typ='rechnung'", [$rechnung_id]);
    if (!$b) return null;
    if (($v = scalar("SELECT id FROM beleg WHERE storno_von_id=? AND typ='gutschrift'", [$rechnung_id]))) return (int)$v;   // schon storniert
    $orig = beleg_positionen($rechnung_id);
    $pos = [];
    if ($orig) {
        foreach ($orig as $p) { $p['preis_cent'] = -abs((int)$p['preis_cent']); $pos[] = $p; }
    } else {
        $nettoCent = (int) round((float)$b['netto'] * 100);
        $pos[] = ['artikelnr'=>'', 'bezeichnung'=>'Storno der Rechnung ' . $b['nummer'], 'beschreibung'=>$grund,
                  'menge'=>1, 'einheit'=>'', 'preis_cent'=>-abs($nettoCent), 'mwst_satz'=>(float)$b['ust_prozent']];
    }
    $gid = gutschrift_erstellen((int)$b['kunde_id'], date('Y-m-d'), $pos, $grund !== '' ? $grund : ('Storno zu ' . $b['nummer']), $rechnung_id, $b['auftrag_id'] ? (int)$b['auftrag_id'] : null);
    q("UPDATE beleg SET status='storniert' WHERE id=?", [$rechnung_id]);
    beleg_status_log_add($rechnung_id, 'storniert', 'Storniert per Gutschrift ' . scalar("SELECT nummer FROM beleg WHERE id=?", [$gid]) . ($grund !== '' ? ' – ' . $grund : ''), $akteur);
    return $gid;
}

// ===== Block C: Kundenguthaben (aus Gutschriften) =====

function kunde_guthaben(int $kunde_id): float {
    if (!$kunde_id) return 0.0;
    $gut = (float) scalar("SELECT COALESCE(SUM(ABS(brutto)),0) FROM beleg WHERE kunde_id=? AND typ='gutschrift'", [$kunde_id]);
    $ver = (float) scalar("SELECT COALESCE(SUM(betrag),0) FROM guthaben_bewegung WHERE kunde_id=?", [$kunde_id]);
    return round($gut - $ver, 2);
}
function guthaben_bewegung_add(int $kunde_id, float $betrag, string $typ, ?int $ref_beleg_id = null, string $notiz = '', ?int $gutschrift_id = null): void {
    q("INSERT INTO guthaben_bewegung (kunde_id,gutschrift_id,betrag,typ,ref_beleg_id,notiz,datum) VALUES (?,?,?,?,?,?,CURDATE())",
      [$kunde_id, $gutschrift_id ?: null, round($betrag, 2), $typ, $ref_beleg_id ?: null, $notiz ?: null]);
}
// Guthaben auf eine Rechnung anrechnen: als Zahlung (art=guthaben) verbuchen + Verbrauch protokollieren. Gibt den angerechneten Betrag.
function guthaben_anrechnen(int $rechnung_id, float $wunsch, string $akteur = 'team'): float {
    $b = one("SELECT * FROM beleg WHERE id=? AND typ='rechnung' AND status<>'storniert'", [$rechnung_id]);
    if (!$b || !$b['kunde_id']) return 0.0;
    $rest = (float) beleg_zahlstatus($b)['rest'];
    $frei = kunde_guthaben((int)$b['kunde_id']);
    $betrag = round(min($wunsch > 0 ? $wunsch : $frei, $frei, $rest), 2);
    if ($betrag <= 0.005) return 0.0;
    zahlung_erfassen($rechnung_id, $betrag, date('Y-m-d'), 'guthaben', 'guthaben', 'Guthaben angerechnet', $akteur);
    guthaben_bewegung_add((int)$b['kunde_id'], $betrag, 'anrechnung', $rechnung_id, 'Angerechnet auf ' . $b['nummer']);
    return $betrag;
}
// Guthaben auszahlen (Erstattung): protokolliert den Verbrauch. Gibt den ausgezahlten Betrag.
function guthaben_auszahlen(int $kunde_id, float $wunsch, string $notiz = '', string $akteur = 'team'): float {
    $frei = kunde_guthaben($kunde_id);
    $betrag = round(min($wunsch > 0 ? $wunsch : $frei, $frei), 2);
    if ($betrag <= 0.005) return 0.0;
    guthaben_bewegung_add($kunde_id, $betrag, 'auszahlung', null, $notiz ?: 'Guthaben ausgezahlt');
    return $betrag;
}

// ===== Dienstleistungs-Rechnung (DR-) aus DL-Auftrag =====
// Sub-App-Variante von dl_rechnung_aus_auftrag() (Referenz: Dashboard core/dienstleistung.php). Liest
// Auftrag + Angebotspositionen über die Naht (erp.php), schreibt beleg/beleg_position. Nur für
// auftrag.kategorie='dienstleistung'; Positionen 1:1 aus dem DA-Angebot; Beleg mit kategorie='dienstleistung'
// und Nummernkreis DR-. Idempotent: existiert schon eine nicht stornierte Rechnung zum Auftrag -> deren ID.
function dl_rechnung_aus_auftrag(int $auftrag_id, array $opt = []): ?int {
    $a = erp_auftrag($auftrag_id);
    if (!$a || ($a['kategorie'] ?? '') !== 'dienstleistung') return null;
    $ex = scalar("SELECT id FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert' ORDER BY id LIMIT 1", [$auftrag_id]);
    if ($ex) return (int)$ex;
    $pos = [];
    foreach (erp_dl_positionen((int)($a['angebot_id'] ?? 0)) as $p) {
        $pos[] = [
            'artikelnr'   => $p['artikelnr'] ?? null,
            'bezeichnung' => (string)$p['bezeichnung'],
            'beschreibung'=> $p['beschreibung'] ?? null,
            'menge'       => (float)$p['menge'],
            'einheit'     => $p['einheit'] ?? null,
            'preis_cent'  => (int)$p['preis_cent'],
            'mwst_satz'   => (float)$p['mwst_satz'],
        ];
    }
    if (!$pos) return null;
    $s = beleg_summen_aus_positionen($pos);
    if ($s['netto'] <= 0) return null;
    $ustP = 0.0;
    foreach ($pos as $p) if ((float)$p['mwst_satz'] > 0) { $ustP = (float)$p['mwst_satz']; break; }
    $gilt  = fn($d) => (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) ? $d : null;
    $datum = $gilt($opt['datum'] ?? null) ?? gmdate('Y-m-d');
    $ziel  = (isset($opt['zahlungsziel_tage']) && $opt['zahlungsziel_tage'] !== '') ? max(0, (int)$opt['zahlungsziel_tage']) : null;
    $faellig = ($ziel !== null) ? date('Y-m-d', strtotime($datum . ' +' . $ziel . ' days')) : null;
    $text  = trim((string)($opt['text'] ?? '')) ?: null;
    $sicht = !empty($opt['freigeben']) ? 1 : 0;
    q("INSERT INTO beleg (nummer,typ,kategorie,auftrag_id,kunde_id,netto,ust_prozent,ust_betrag,brutto,status,datum,zahlungsziel_tage,faellig,text,kunde_sichtbar)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
      [naechste_nummer('DR'), 'rechnung', 'dienstleistung', $auftrag_id, ($a['kunde_id'] ?: null),
       $s['netto'], $ustP, $s['ust'], $s['brutto'], 'offen', $datum, $ziel, $faellig, $text, $sicht]);
    $bid = (int) insert_id();
    $sort = 0;
    foreach ($pos as $p)
        q("INSERT INTO beleg_position (beleg_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,mwst_satz) VALUES (?,?,?,?,?,?,?,?,?)",
          [$bid, $sort++, $p['artikelnr'] ?: null, $p['bezeichnung'], $p['beschreibung'] ?: null,
           $p['menge'], $p['einheit'] ?: null, $p['preis_cent'], $p['mwst_satz']]);
    if (function_exists('beleg_status_log_add')) beleg_status_log_add($bid, 'offen', 'DL-Rechnung aus Auftrag ' . (string)$a['nummer'] . ' erstellt' . ($sicht ? ', für Kunde freigegeben' : ''), trim((string)($opt['ersteller'] ?? '')) ?: 'team');
    if (!empty($a['kunde_id'])) log_aktivitaet('kunde', (int)$a['kunde_id'], 'team', 'DL-Rechnung ' . (string) scalar("SELECT nummer FROM beleg WHERE id=?", [$bid]) . ' erstellt.', 'beleg', 'auftrag', $auftrag_id);
    return $bid;
}
