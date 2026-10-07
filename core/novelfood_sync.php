<?php
// Automatischer Abgleich des EU-Novel-Food-Katalogs.
// Zieht den Katalog direkt aus der offenen JSON-API der EU-Kommission (keine Anmeldung),
// übersetzt neue/geänderte Beschreibungen per KI ins Deutsche (nur das Delta – bestehende
// Übersetzungen werden wiederverwendet), protokolliert jeden Lauf (novelfood_lauf/_change)
// und übernimmt die Daten in novelfood_katalog (Upsert). Derselbe Job läuft per Admin-Button
// und per CLI (tools/novelfood_sync.php) für die Monatsroutine.
require_once BX_ROOT . '/core/novelfood.php';
require_once BX_ROOT . '/core/ki.php';

// Offizielle, offene EU-API (überschreibbar via app_meta 'novelfood_api_url').
function novelfood_api_url(): string {
    $u = function_exists('meta_get') ? trim((string) meta_get('novelfood_api_url', '')) : '';
    return $u !== '' ? $u
        : 'https://ec.europa.eu/food/food-feed-portal/backend/api/policy-items?foodDomain=nf&authorisationType=nfc_auth';
}

// Statuscode -> deutsches Label. MUSS zur Farbkodierung/Ampel des bestehenden Moduls passen.
function novelfood_status_de(string $code): string {
    return [
        'NOT_YET_AUTHORISED_NOVEL_FOOD'     => 'Novel Food (noch nicht zugelassen)',
        'NOT_NOVEL_IN_FOOD'                 => 'Kein Novel Food',
        'NOT_NOVEL_IN_FOOD_SUPPLEMENTS'     => 'Kein Novel Food in Nahrungsergänzungsmitteln',
        'AUTHORISED_NOVEL_FOOD'             => 'Novel Food (zugelassen)',
        'SUBJECT_TO_A_CONSULTATION_REQUEST' => 'Unter Prüfung (Konsultationsantrag)',
    ][$code] ?? '';
}

// Den verschachtelten policyItemObject-Baum der API zu einem flachen {valueIdentifier: value} machen.
// Nur Blätter mit Wert; interne Felder (internalComments, createdBy …) werden später NICHT gemappt.
function novelfood_flatten(array $item): array {
    $out = [];
    $walk = function ($nodes) use (&$walk, &$out) {
        foreach ((array)$nodes as $n) {
            $vi   = $n['valueIdentifier'] ?? null;
            $kids = $n['childrenValues'] ?? [];
            if ($vi !== null && empty($kids) && ($n['value'] ?? null) !== null) $out[$vi] = $n['value'];
            if (!empty($kids)) $walk($kids);
        }
    };
    $walk($item['childrenValues'] ?? []);
    return $out;
}

// Einen geflachten EU-Eintrag auf unser Rohformat abbilden (bewusst NUR die fachlichen Felder –
// internalComments/createdBy/lastModifiedBy/displayName werden absichtlich weggelassen).
function novelfood_api_map(array $f): array {
    $code = (string)($f['NFCStatusCode'] ?? '');
    return [
        'code'            => $f['policyItemCode'] ?? '',
        'name'            => $f['identifyingName'] ?? '',
        'trivial'         => $f['commonNames'] ?? '',
        'syn'             => $f['synonyms'] ?? '',
        'status_code'     => $code,
        'status'          => novelfood_status_de($code),
        'teil'            => $f['NFCItemPart'] ?? '',
        'beschreibung'    => $f['description'] ?? '',   // englisches Original (HTML wird in novelfood_clean entfernt)
        'beschreibung_de' => '',                         // wird per Delta/KI gefüllt
        'pub'             => $f['policyItemStatus'] ?? '',
        'erstellt'        => substr((string)($f['creationDate'] ?? ''), 0, 10),
        'geaendert'       => substr((string)($f['lastModificationDate'] ?? ''), 0, 10),
    ];
}

// Katalog aus der EU-API laden. Rückgabe: ['ok'=>bool,'eintraege'=>[…roh…],'stand'=>?,'fehler'=>?].
function novelfood_api_laden(?string $url = null): array {
    $url = $url ?: novelfood_api_url();
    if (!function_exists('curl_init')) return ['ok' => false, 'fehler' => 'Die PHP-Erweiterung curl fehlt.'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'bulkify-novelfood-sync/1.0 (+https://bulkify.pro)',
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'fehler' => 'Verbindungsfehler: ' . $err];
    if ($http !== 200)   return ['ok' => false, 'fehler' => 'EU-API antwortete mit HTTP ' . $http . '.'];
    $arr = json_decode((string)$resp, true);
    if (!is_array($arr)) return ['ok' => false, 'fehler' => 'Die Antwort der EU-API war kein gültiges JSON.'];
    $out = [];
    foreach ($arr as $item) {
        if (!is_array($item)) continue;
        $f = novelfood_flatten($item);
        if (trim((string)($f['identifyingName'] ?? '')) === '') continue;
        $out[] = novelfood_api_map($f);
    }
    if (!$out) return ['ok' => false, 'fehler' => 'Die EU-API lieferte keine verwertbaren Einträge.'];
    return ['ok' => true, 'eintraege' => $out, 'stand' => gmdate('Y-m-d')];
}

// Nur die fehlenden Beschreibungen übersetzen (Delta). $norm wird in-place ergänzt.
// Rückgabe: ['uebersetzt'=>n,'tokens'=>n,'meldung'=>…].
function novelfood_delta_uebersetzen(array &$norm, array $indices): array {
    $uebersetzt = 0; $tokens = 0; $fehler = 0;
    $chunks = array_chunk($indices, 12);
    foreach ($chunks as $chunk) {
        $texte = [];
        foreach ($chunk as $i) $texte[(string)$i] = (string)$norm[$i]['beschreibung'];
        $system = 'Du bist Fachübersetzer für EU-Lebensmittelrecht (Novel Food). Übersetze die englischen '
                . 'Beschreibungen präzise und sachlich ins Deutsche. Lateinische Artnamen, Verordnungsnummern '
                . '(z. B. „Regulation (EU) 2015/2283"), Eigennamen und URLs bleiben unverändert. Keine Zusätze, '
                . 'keine Erklärungen. Gib ein JSON-Objekt zurück, das jeden Schlüssel aus der Eingabe auf seine '
                . 'deutsche Übersetzung abbildet.';
        $r = ki_json(json_encode($texte, JSON_UNESCAPED_UNICODE), [
            'modell' => KI_MODELL_SCHNELL, 'aufwand' => 'low', 'max_tokens' => 8000,
            'system' => $system, 'zweck' => 'novelfood-uebersetzung',
        ]);
        if (!empty($r['usage'])) $tokens += (int)$r['usage']['ein'] + (int)$r['usage']['aus'];
        if (!$r['ok'] || !is_array($r['daten'] ?? null)) { $fehler += count($chunk); continue; }
        foreach ($chunk as $i) {
            $de = novelfood_clean((string)($r['daten'][(string)$i] ?? ''));
            if ($de !== null && $de !== '') { $norm[$i]['beschreibung_de'] = $de; $uebersetzt++; }
        }
    }
    $meldung = $uebersetzt . ' Beschreibung(en) neu übersetzt' . ($fehler ? ', ' . $fehler . ' fehlgeschlagen' : '') . '.';
    return ['uebersetzt' => $uebersetzt, 'tokens' => $tokens, 'meldung' => $meldung];
}

// Website-Export (gleiche Daten, Website-Schema) als Artefakt ablegen. Dieselbe novelfood.json,
// die die öffentliche Seite novel-food.html lädt. Rückgabe: Pfad oder null.
function novelfood_export_schreiben(array $norm, ?string $stand): ?string {
    try {
        $e = [];
        foreach ($norm as $d) {
            $e[] = [
                'name' => (string)$d['name'], 'code' => (string)($d['code'] ?? ''),
                'pub' => (string)($d['pub'] ?? ''), 'teil' => (string)($d['teil'] ?? ''),
                'status' => (string)($d['status'] ?? ''), 'status_code' => (string)($d['status_code'] ?? ''),
                'trivial' => (string)($d['trivial'] ?? ''), 'syn' => (string)($d['syn'] ?? ''),
                'beschreibung' => (string)($d['beschreibung'] ?? ''), 'beschreibung_de' => (string)($d['beschreibung_de'] ?? ''),
                'erstellt' => (string)($d['erstellt'] ?? ''), 'geaendert' => (string)($d['geaendert'] ?? ''),
            ];
        }
        usort($e, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $doc = ['stand' => $stand ?: gmdate('Y-m-d'), 'anzahl' => count($e),
                'quelle' => 'EU Novel Food Katalog (Kommission)', 'sprachen' => 'de+en', 'eintraege' => $e];
        if (!is_dir(BX_DATA)) @mkdir(BX_DATA, 0775, true);
        $pfad = BX_DATA . '/novelfood_export.json';
        file_put_contents($pfad, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $pfad;
    } catch (\Throwable $e) { return null; }
}

// DER JOB: ein kompletter Abgleich. $ausgeloest_von: manuell | auto | cli.
// Rückgabe-Array mit allen Zählern; wirft nicht (Fehler landen im Lauf-Kopf).
function novelfood_sync_lauf(string $ausgeloest_von = 'manuell', ?string $benutzer = null): array {
    @set_time_limit(600);
    $start = microtime(true);
    $url   = novelfood_api_url();

    q("INSERT INTO novelfood_lauf (gestartet_at, ausgeloest_von, benutzer, status, quelle) VALUES (?,?,?,?,?)",
      [gmdate('Y-m-d H:i:s'), $ausgeloest_von, $benutzer, 'laufend', $url]);
    $lauf_id = (int) db()->lastInsertId();

    $api = novelfood_api_laden($url);
    if (!$api['ok']) {
        q("UPDATE novelfood_lauf SET beendet_at=?, status='fehler', meldung=? WHERE id=?",
          [gmdate('Y-m-d H:i:s'), 'Abruf fehlgeschlagen: ' . $api['fehler'], $lauf_id]);
        return ['ok' => false, 'lauf_id' => $lauf_id, 'fehler' => $api['fehler']];
    }

    // 1) Normalisieren + vorhandene Übersetzungen wiederverwenden, Übersetzungs-Delta bestimmen.
    $norm = []; $zuUebersetzen = [];
    foreach ($api['eintraege'] as $roh) {
        $d = novelfood_normalisieren($roh);
        if ($d === null) continue;
        $idx = count($norm);
        $ex  = novelfood_finden($d);
        if ($ex) {
            $altDe = (string)($ex['beschreibung_de'] ?? '');
            $altEn = (string)($ex['beschreibung'] ?? '');
            if ($altDe !== '') {
                $d['beschreibung_de'] = $altDe;   // vorhandene Übersetzung behalten
                // Nur neu übersetzen, wenn es ein FRÜHERES englisches Original gab UND es sich geändert hat.
                // Beim ersten EU-Lauf ist die EN-Spalte noch leer -> KEIN Massen-Retranslate (spart Kosten),
                // der alte deutsche Text bleibt und die EN-Spalte wird einfach nachgezogen.
                if ($altEn !== '' && $altEn !== (string)($d['beschreibung'] ?? '') && $d['beschreibung'] !== null)
                    $zuUebersetzen[] = $idx;
            } elseif ($d['beschreibung'] !== null) {
                $zuUebersetzen[] = $idx;           // Eintrag vorhanden, aber ohne Übersetzung
            }
        } elseif ($d['beschreibung'] !== null) {
            $zuUebersetzen[] = $idx;              // neuer Eintrag
        }
        $norm[] = $d;
    }

    // 2) Delta übersetzen (nur falls KI eingerichtet).
    $uebersetzt = 0; $tokens = 0; $kiMeldung = '';
    if ($zuUebersetzen && ki_bereit()) {
        $u = novelfood_delta_uebersetzen($norm, $zuUebersetzen);
        $uebersetzt = $u['uebersetzt']; $tokens = $u['tokens']; $kiMeldung = $u['meldung'];
    }
    // Nur die wirklich ohne Deutsch verbliebenen Einträge melden (Retranslation-Kandidaten haben einen DE-Text).
    $ohneDe = 0;
    foreach ($norm as $d) if ($d['beschreibung'] !== null && (string)$d['beschreibung_de'] === '') $ohneDe++;
    if ($ohneDe > 0)
        $kiMeldung = trim($kiMeldung . ' ' . $ohneDe . ' Eintrag/Einträge noch ohne deutsche Übersetzung'
                   . (ki_bereit() ? '' : ' (KI nicht eingerichtet – später nachholbar)') . '.');

    // 3) Diff gegen die DB (VOR dem Übernehmen) + entfernte Einträge.
    $diff = novelfood_diff($norm);
    $apiCodes = array_values(array_filter(array_map(fn($d) => $d['code'], $norm)));
    $entfernt = [];
    if ($apiCodes) {
        $place = implode(',', array_fill(0, count($apiCodes), '?'));
        $entfernt = all("SELECT code, name, status, status_code FROM novelfood_katalog WHERE code IS NOT NULL AND code NOT IN ($place)", $apiCodes);
    }

    // 4) Änderungen protokollieren (Positionen je Lauf).
    foreach ($diff['neu'] as $n)
        q("INSERT INTO novelfood_change (lauf_id, art, code, name, status_neu, status_code) VALUES (?,?,?,?,?,?)",
          [$lauf_id, 'neu', $n['code'], $n['name'], $n['status'], $n['status_code']]);
    $statusAnz = 0;
    foreach ($diff['geaendert'] as $g) {
        $sw = !empty($g['status_neu']) ? 1 : 0; $statusAnz += $sw;
        q("INSERT INTO novelfood_change (lauf_id, art, code, name, felder, status_wechsel, status_alt, status_neu, status_code) VALUES (?,?,?,?,?,?,?,?,?)",
          [$lauf_id, 'geaendert', $g['neu']['code'], $g['neu']['name'], implode(',', (array)$g['felder']), $sw,
           $g['alt']['status'] ?? null, $g['neu']['status'] ?? null, $g['neu']['status_code']]);
    }
    foreach ($entfernt as $e)
        q("INSERT INTO novelfood_change (lauf_id, art, code, name, status_alt, status_code) VALUES (?,?,?,?,?,?)",
          [$lauf_id, 'entfernt', $e['code'], $e['name'], $e['status'], $e['status_code']]);

    // 5) In die DB übernehmen (Upsert).
    $erg = novelfood_uebernehmen($norm);

    // 6) Website-Export ablegen (gleiche Daten).
    $export = novelfood_export_schreiben($norm, $api['stand']);

    // 7) Lauf abschließen + Marker setzen.
    $dauer   = (int) round((microtime(true) - $start) * 1000);
    $meldung = trim($kiMeldung . ($export ? ' Website-Export: ' . basename($export) . '.' : ''));
    q("UPDATE novelfood_lauf SET beendet_at=?, status='fertig', katalog_stand=?, anzahl_gesamt=?, anzahl_neu=?,
         anzahl_geaendert=?, anzahl_status=?, anzahl_entfernt=?, anzahl_uebersetzt=?, ki_tokens=?, dauer_ms=?, meldung=? WHERE id=?",
      [gmdate('Y-m-d H:i:s'), $api['stand'], count($norm), count($diff['neu']), count($diff['geaendert']),
       $statusAnz, count($entfernt), $uebersetzt, $tokens, $dauer, ($meldung ?: null), $lauf_id]);

    meta_set('novelfood_last_run', gmdate('Y-m-d H:i:s'));
    meta_set('novelfood_last_lauf_id', (string)$lauf_id);

    if (function_exists('log_aktivitaet'))
        log_aktivitaet('system', 0, 'team',
            'Novel-Food-Katalog aus EU abgeglichen: ' . count($diff['neu']) . ' neu, ' . count($diff['geaendert'])
            . ' geändert (' . $statusAnz . ' Statuswechsel), ' . count($entfernt) . ' entfernt.', 'notiz');

    return ['ok' => true, 'lauf_id' => $lauf_id, 'gesamt' => count($norm), 'neu' => count($diff['neu']),
            'geaendert' => count($diff['geaendert']), 'status' => $statusAnz, 'entfernt' => count($entfernt),
            'uebersetzt' => $uebersetzt, 'tokens' => $tokens, 'db_neu' => $erg['neu'], 'db_upd' => $erg['upd'],
            'dauer_ms' => $dauer, 'meldung' => $meldung];
}
