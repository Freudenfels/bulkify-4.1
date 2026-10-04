<?php
// Massen-Import von Spezifikationen/CoAs (PDF).
//
// Ablauf:
//  1) Viele PDFs auf einmal hochladen  -> dokimport_job_neu() legt einen Job + je Datei eine Zeile an
//     und stoesst den ersten Hintergrund-Lauf an (art='dokimport', id=datei_id, ueber core/ki_job.php).
//  2) Die KI liest JEDE Datei NACHEINANDER im Hintergrund (dokimport_datei_lesen): Typ (spec/coa),
//     Stammdaten, Charge, Wirkstoffe, Kennwerte. Danach wird der passende VORHANDENE Rohstoff gesucht
//     (spec_ki_match_item). Jede Datei kettet die naechste an; ist keine mehr offen -> Job 'bereit'.
//  3) Der Mensch prueft die Zuordnung (Match-Vorschau, module/system/dok_massenimport.php) und korrigiert
//     bei Bedarf. Kein Treffer bleibt offen stehen (kein automatisches Neuanlegen).
//  4) dokimport_import() uebernimmt alle bestaetigten Zeilen: Original als INTERNES Dokument am Rohstoff
//     (nie an den Kunden) + KI-Daten (Charge/Grenzwerte/Kennwerte/Wirkstoffe) ueber spec_ki_anwenden().
//
// Die KI-Ergebnisse werden pro Datei als JSON gespeichert und beim Import WIEDERVERWENDET – es laeuft
// also keine zweite (teure) KI-Analyse.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/spec_ki.php';
require_once __DIR__ . '/ki_job.php';

// Neuen Job aus bereits in data/uploads abgelegten Dateien anlegen. $dateien = [['orig'=>Anzeigename,'pfad'=>Dateiname], ...].
// Rueckgabe: job_id (0 = nichts angelegt).
const DOKIMPORT_WORKER    = 4;    // so viele Dateien werden parallel im Hintergrund gelesen
const DOKIMPORT_STALE_MIN = 10;   // nach so vielen Minuten gilt ein haengendes 'liest' als abgestuerzt -> zurueck auf offen

function dokimport_job_neu(array $dateien, ?int $user): int {
    $dateien = array_values(array_filter($dateien, fn($d) => !empty($d['pfad'])));
    if (!$dateien) return 0;
    q("INSERT INTO dok_import_job (status, anzahl, gelesen, erstellt_von, erstellt_am) VALUES ('offen', 0, 0, ?, ?)",
      [$user ?: null, gmdate('Y-m-d H:i:s')]);
    $job_id = (int) insert_id();
    dokimport_dateien_hinzufuegen($job_id, $dateien);
    return $job_id;
}

// Weitere Dateien an einen bestehenden Job anhaengen – fuer Upload in Schueben (PHP begrenzt die Zahl je
// Upload, Standard max_file_uploads=20). Setzt den Job wieder auf 'offen' und stoesst die Worker an.
function dokimport_dateien_hinzufuegen(int $job_id, array $dateien): int {
    $dateien = array_values(array_filter($dateien, fn($d) => !empty($d['pfad'])));
    if ($job_id <= 0 || !$dateien) return 0;
    foreach ($dateien as $d) {
        $hash = $d['hash'] ?? (@hash_file('md5', BX_UPLOADS . '/' . basename((string)$d['pfad'])) ?: null);
        q("INSERT INTO dok_import_datei (job_id, dateiname, pfad, status, dok_hash, erstellt_am) VALUES (?,?,?, 'offen', ?, ?)",
          [$job_id, mb_substr((string)$d['orig'], 0, 255), mb_substr((string)$d['pfad'], 0, 255), $hash, gmdate('Y-m-d H:i:s')]);
    }
    q("UPDATE dok_import_job SET anzahl=(SELECT COUNT(*) FROM dok_import_datei WHERE job_id=?), status='offen' WHERE id=?", [$job_id, $job_id]);
    dokimport_worker_starten($job_id);
    return count($dateien);
}

// Mehrere parallele Worker anstossen. Jeder Worker liest je Aufruf EINE Datei (kurzer Request) und ruft
// sich danach selbst erneut – so laufen DOKIMPORT_WORKER Ketten gleichzeitig, ohne lange Einzel-Requests.
function dokimport_worker_starten(int $job_id): void {
    for ($i = 0; $i < DOKIMPORT_WORKER; $i++) ki_job_starten('dokimport', $job_id);
}

// Ein Worker-Durchlauf: genau EINE Datei beanspruchen, lesen, zuordnen; danach sich selbst weiterreichen.
// Laeuft im Hintergrund (core/ki_job.php, art='dokimport', id=job_id).
function dokimport_worker(int $job_id): void {
    @set_time_limit(0);
    if ($job_id <= 0) return;

    // Haengengebliebene 'liest' (abgestuerzter Worker) wieder freigeben.
    q("UPDATE dok_import_datei SET status='offen' WHERE job_id=? AND status='liest' AND liest_seit < ?",
      [$job_id, gmdate('Y-m-d H:i:s', time() - DOKIMPORT_STALE_MIN * 60)]);

    // Eine offene Datei ATOMAR beanspruchen (damit nicht zwei Worker dieselbe nehmen).
    $datei_id = 0;
    try {
        db()->beginTransaction();
        $row = one("SELECT id FROM dok_import_datei WHERE job_id=? AND status='offen' ORDER BY id LIMIT 1 FOR UPDATE", [$job_id]);
        if ($row) {
            $datei_id = (int)$row['id'];
            q("UPDATE dok_import_datei SET status='liest', liest_seit=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $datei_id]);
        }
        db()->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return;
    }

    if (!$datei_id) {
        // Nichts mehr offen: wenn auch nichts mehr 'liest', ist der Job fertig -> Vorschau.
        $rest = (int) scalar("SELECT COUNT(*) FROM dok_import_datei WHERE job_id=? AND status IN ('offen','liest')", [$job_id]);
        if ($rest === 0) q("UPDATE dok_import_job SET status='bereit' WHERE id=? AND status='offen'", [$job_id]);
        return;
    }

    // Lesen + zuordnen.
    $d    = one("SELECT * FROM dok_import_datei WHERE id=?", [$datei_id]);
    $pfad = BX_UPLOADS . '/' . basename((string)$d['pfad']);
    if (!is_file($pfad)) {
        q("UPDATE dok_import_datei SET status='fehler', fehler=? WHERE id=?", ['Datei nicht gefunden', $datei_id]);
    } else {
        $r = spec_ki_lesen($pfad);
        if (empty($r['ok'])) {
            q("UPDATE dok_import_datei SET status='fehler', fehler=? WHERE id=?",
              [mb_substr((string)($r['fehler'] ?? 'KI-Fehler'), 0, 255), $datei_id]);
        } else {
            $m = spec_ki_match_item($r);
            q("UPDATE dok_import_datei SET status='gelesen', typ=?, sicherheit=?, item_id=?, quelle=?, ki_json=?, fehler=NULL WHERE id=?",
              [(string)($r['typ'] ?? 'unklar'), (string)($r['sicherheit'] ?? 'mittel'),
               $m['item_id'] ?: null, $m['quelle'], json_encode($r, JSON_UNESCAPED_UNICODE), $datei_id]);
        }
    }
    // Fortschritt zaehlen.
    q("UPDATE dok_import_job SET gelesen=(SELECT COUNT(*) FROM dok_import_datei WHERE job_id=? AND status NOT IN ('offen','liest')) WHERE id=?", [$job_id, $job_id]);
    // Diesen Worker fortsetzen (naechste Datei, neuer kurzer Request).
    ki_job_starten('dokimport', $job_id);
}

// Fortschritt/Status eines Jobs fuer die Anzeige (inkl. Aufschluesselung).
function dokimport_fortschritt(int $job_id): array {
    $j = one("SELECT * FROM dok_import_job WHERE id=?", [$job_id]);
    if (!$j) return ['status' => 'weg', 'anzahl' => 0, 'gelesen' => 0, 'offen' => 0, 'liest' => 0, 'fehler' => 0];
    $offen  = (int) scalar("SELECT COUNT(*) FROM dok_import_datei WHERE job_id=? AND status='offen'", [$job_id]);
    $liest  = (int) scalar("SELECT COUNT(*) FROM dok_import_datei WHERE job_id=? AND status='liest'", [$job_id]);
    $fehler = (int) scalar("SELECT COUNT(*) FROM dok_import_datei WHERE job_id=? AND status='fehler'", [$job_id]);
    return ['status' => (string)$j['status'], 'anzahl' => (int)$j['anzahl'], 'gelesen' => (int)$j['gelesen'],
            'offen' => $offen, 'liest' => $liest, 'fehler' => $fehler];
}

// Neuesten offenen/bereiten Job holen (laufende Session). Fertige/abgebrochene zeigen wir nicht mehr.
function dokimport_aktiver_job(): ?array {
    return one("SELECT * FROM dok_import_job WHERE status IN ('offen','bereit') ORDER BY id DESC LIMIT 1");
}

// Zeilen eines Jobs (fuer die Vorschau) inkl. zugeordnetem Rohstoff-Namen und Duplikat-Kennzeichen:
//  dup_dok   > 0: Datei-Inhalt liegt schon als Dokument an einem Rohstoff (frueher importiert / anderer Stapel)
//  dup_vorher> 0: dieselbe Datei kam in DIESEM Stapel bereits frueher vor (Dublette im Upload)
function dokimport_zeilen(int $job_id): array {
    return all(
        "SELECT di.*, it.name AS item_name, it.artikelnummer AS item_nr,
                (SELECT COUNT(*) FROM dokument dk
                   WHERE di.dok_hash IS NOT NULL AND dk.datei_hash = di.dok_hash) AS dup_dok,
                (SELECT COUNT(*) FROM dok_import_datei d2
                   WHERE di.dok_hash IS NOT NULL AND d2.dok_hash = di.dok_hash
                     AND d2.job_id = di.job_id AND d2.id < di.id) AS dup_vorher
         FROM dok_import_datei di
         LEFT JOIN item it ON it.id = di.item_id
         WHERE di.job_id=? ORDER BY di.id",
        [$job_id]
    );
}

// Fehlende Inhalts-Hashes nachtragen (fuer Jobs, die vor der Duplikat-Erkennung angelegt wurden).
function dokimport_hashes_nachtragen(int $job_id): void {
    foreach (all("SELECT id, pfad FROM dok_import_datei WHERE job_id=? AND (dok_hash IS NULL OR dok_hash='')", [$job_id]) as $z) {
        $pfad = BX_UPLOADS . '/' . basename((string)$z['pfad']);
        if (is_file($pfad)) { $h = @hash_file('md5', $pfad); if ($h) q("UPDATE dok_import_datei SET dok_hash=? WHERE id=?", [$h, (int)$z['id']]); }
    }
}

// Alle gelesenen Zeilen ueberspringen, die Duplikate sind (Inhalt schon als Dokument vorhanden ODER
// im Stapel schon frueher vorgekommen). Rueckgabe: Anzahl uebersprungener Zeilen.
function dokimport_duplikate_ueberspringen(int $job_id): int {
    $n = 0;
    foreach (dokimport_zeilen($job_id) as $z) {
        if ((string)$z['status'] !== 'gelesen') continue;
        if ((int)$z['dup_dok'] > 0 || (int)$z['dup_vorher'] > 0) {
            q("UPDATE dok_import_datei SET status='uebersprungen' WHERE id=? AND status='gelesen'", [(int)$z['id']]);
            $n++;
        }
    }
    return $n;
}

// Eine Zeile manuell einem Rohstoff zuordnen (Name oder Artikelnummer). Rueckgabe: true bei Treffer.
function dokimport_zuordnen(int $datei_id, string $eingabe): bool {
    $eingabe = trim($eingabe);
    if ($datei_id <= 0 || $eingabe === '') return false;
    $ziel = (int) (scalar("SELECT id FROM item WHERE kategorie='rohstoff' AND (artikelnummer=? OR name=?) LIMIT 1", [$eingabe, $eingabe])
         ?: scalar("SELECT id FROM item WHERE kategorie='rohstoff' AND name LIKE ? ORDER BY CHAR_LENGTH(name) LIMIT 1", ['%' . $eingabe . '%']));
    if (!$ziel) return false;
    q("UPDATE dok_import_datei SET item_id=?, quelle='manuell' WHERE id=? AND status='gelesen'", [$ziel, $datei_id]);
    return true;
}

// Aus einer Zeile OHNE Treffer einen NEUEN Rohstoff aus den KI-Stammdaten anlegen und zuordnen.
// Rueckgabe: neue item_id (0 = nichts angelegt). Stammdaten (CAS, Synonyme, botan. Quelle …) werden
// uebernommen; Charge/Grenzwerte/Kennwerte/Wirkstoffe kommen dann beim eigentlichen Import dazu.
// Namen fuer einen neu anzulegenden Rohstoff moeglichst UNTERSCHEIDBAR machen. Gleichnamige Extrakte
// (z. B. "Hagebuttenextrakt") unterscheiden sich durch die Standardisierung – die haengen wir an:
//  1) hoechster Wirkstoff-Gehalt (z. B. "50% Vitamin C"), sonst
//  2) Extraktverhaeltnis/DEV aus den Kennwerten (z. B. "10:1"), sonst
//  3) der Dateiname (ohne Endung/COA-/Spec-Floskeln) – denn dort steht der Unterschied oft schon.
function dokimport_name_spezifisch(array $ki, string $dateiname): string {
    $base = trim((string)($ki['stamm']['name'] ?? ''));
    if ($base === '') {
        $base = (string) preg_replace('/\.(pdf|jpe?g|png|webp)$/i', '', $dateiname);
        $base = (string) preg_replace('/\b(coa|certificate of analysis|spec(ification)?|spezifikation|datenblatt|tds)\b/i', ' ', $base);
        $base = trim((string) preg_replace('/\s{2,}/', ' ', $base));
    }
    if ($base === '') return 'Neuer Rohstoff (Import)';

    $suffix = '';
    // 1) staerkster standardisierter Wirkstoff
    $best = null;
    foreach ((array)($ki['wirkstoffe'] ?? []) as $w) {
        $g = $w['gehalt_prozent'] ?? null;
        if ($g === null || $g === '') continue;
        if ($best === null || (float)$g > (float)($best['gehalt_prozent'] ?? 0)) $best = $w;
    }
    if ($best) {
        $pct = rtrim(rtrim(number_format((float)$best['gehalt_prozent'], 2, ',', '.'), '0'), ',');
        $suffix = trim($pct . '% ' . trim((string)($best['name'] ?? '')));
    }
    // 2) Extraktverhaeltnis / DEV aus den Kennwerten
    if ($suffix === '') {
        foreach ((array)($ki['kennwerte'] ?? []) as $k) {
            $p = mb_strtolower((string)($k['parameter'] ?? ''));
            if (preg_match('/verh[\x{00e4}a]ltnis|dev|ratio|extrakt/u', $p)) { $suffix = trim((string)($k['wert'] ?? '')); break; }
        }
    }
    // 3) Dateiname als Unterscheidung (Endung + COA/Spec-Floskeln raus)
    if ($suffix === '') {
        $fn = preg_replace('/\.(pdf|jpe?g|png|webp)$/i', '', $dateiname);
        $fn = preg_replace('/\b(coa|certificate of analysis|spec(ification)?|spezifikation|datenblatt|tds)\b/i', ' ', (string)$fn);
        $suffix = trim((string) preg_replace('/\s{2,}/', ' ', (string)$fn));
    }

    $suffix = mb_substr($suffix, 0, 70);
    if ($suffix !== '' && mb_stripos($base, $suffix) === false) $base .= ' ' . $suffix;
    return mb_substr($base, 0, 190);
}

function dokimport_neu_anlegen(int $datei_id): int {
    $d = one("SELECT * FROM dok_import_datei WHERE id=? AND status='gelesen'", [$datei_id]);
    if (!$d || (int)$d['item_id'] > 0) return 0;   // nur gelesene Zeilen ohne Treffer
    $ki    = json_decode((string)($d['ki_json'] ?? ''), true);
    $stamm = is_array($ki) ? (array)($ki['stamm'] ?? []) : [];
    $name  = dokimport_name_spezifisch(is_array($ki) ? $ki : [], (string)$d['dateiname']);
    q("INSERT INTO item (artikelnummer,name,kategorie,einheit,preis_bezug,ek_preis,notiz) VALUES (?,?, 'rohstoff','kg','kg',0,?)",
      [naechste_nummer(item_prefix('rohstoff')), mb_substr($name, 0, 190), 'Aus Spec/CoA-Massenimport angelegt']);
    $iid = (int) insert_id();
    if ($stamm) spec_ki_uebernehmen($iid, $stamm, array_keys($stamm));   // alle Felder leer -> werden gefuellt
    q("UPDATE dok_import_datei SET item_id=?, quelle='neu' WHERE id=?", [$iid, $datei_id]);
    return $iid;
}

// Fuer ALLE Zeilen ohne Treffer je einen neuen Rohstoff anlegen. Rueckgabe: Anzahl.
function dokimport_neu_anlegen_alle(int $job_id): int {
    $n = 0;
    foreach (all("SELECT id FROM dok_import_datei WHERE job_id=? AND status='gelesen' AND (item_id IS NULL OR item_id=0)", [$job_id]) as $z) {
        if (dokimport_neu_anlegen((int)$z['id'])) $n++;
    }
    return $n;
}

// Eine Zeile vom Import ausnehmen / wieder aufnehmen.
function dokimport_ueberspringen(int $datei_id, bool $skip = true): void {
    if ($skip) {
        q("UPDATE dok_import_datei SET status='uebersprungen' WHERE id=? AND status='gelesen'", [$datei_id]);
    } else {
        q("UPDATE dok_import_datei SET status='gelesen' WHERE id=? AND status='uebersprungen'", [$datei_id]);
    }
}

// Alle bestaetigten Zeilen uebernehmen: Original als internes Dokument am Rohstoff + KI-Daten verwerten.
// Nur Zeilen mit status='gelesen' und zugeordnetem Rohstoff. Rueckgabe: ['importiert'=>n, 'offen'=>n].
function dokimport_import(int $job_id): array {
    $zeilen = all("SELECT * FROM dok_import_datei WHERE job_id=? AND status='gelesen'", [$job_id]);
    $importiert = 0; $offen = 0;
    foreach ($zeilen as $z) {
        $item_id = (int) $z['item_id'];
        if (!$item_id) { $offen++; continue; }   // kein Treffer -> bleibt offen stehen
        $ki = json_decode((string)($z['ki_json'] ?? ''), true);
        if (!is_array($ki)) { $offen++; continue; }

        // Typ fuer die Dokument-Ablage: CoA (mit Chargenwerten) oder Spezifikation.
        $typ = in_array((string)$z['typ'], ['coa', 'beides'], true) ? 'coa' : 'spec';
        $datei = basename((string)$z['pfad']);               // liegt bereits in data/uploads
        $titel = ($typ === 'coa' ? 'CoA' : 'Spezifikation') . ' (Import)';
        $hash  = (string)($z['dok_hash'] ?? '') ?: null;   // damit der Inhalt kuenftig als Duplikat erkannt wird
        q("INSERT INTO dokument (objekt_typ,objekt_id,typ,titel,datei,datei_orig,kunde_sichtbar,datei_hash) VALUES ('item',?,?,?,?,?,0,?)",
          [$item_id, $typ, $titel, $datei, mb_substr((string)$z['dateiname'], 0, 255), $hash]);
        $dok_id = (int) insert_id();

        spec_ki_merken($dok_id, $ki);          // Rohvorschlag am Dokument (nachvollziehbar)
        spec_ki_anwenden($item_id, $ki);       // Charge/Grenzwerte/Kennwerte/Wirkstoffe am Rohstoff (additiv)

        q("UPDATE dok_import_datei SET status='importiert' WHERE id=?", [(int)$z['id']]);
        $importiert++;
    }
    // Job abschliessen, wenn nichts mehr zu tun ist (keine 'gelesen'-Zeilen ohne Treffer mehr).
    if (!$offen) q("UPDATE dok_import_job SET status='fertig' WHERE id=?", [$job_id]);
    return ['importiert' => $importiert, 'offen' => $offen];
}

// Job abbrechen: nicht importierte Dateien aus data/uploads entfernen, Job schliessen.
function dokimport_abbrechen(int $job_id): void {
    foreach (all("SELECT pfad FROM dok_import_datei WHERE job_id=? AND status<>'importiert'", [$job_id]) as $z) {
        @unlink(BX_UPLOADS . '/' . basename((string)$z['pfad']));
    }
    q("UPDATE dok_import_job SET status='abgebrochen' WHERE id=?", [$job_id]);
}
