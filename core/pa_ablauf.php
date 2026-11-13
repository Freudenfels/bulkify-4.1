<?php
require_once __DIR__ . '/zeit_ort.php';   // heute_ort() (13.11.2026)
// Produktion – EIN geführter Ablauf (Nico 11.11.2026, plan-produktion-gefuehrt.md, Teil 3 + 5).
// GEMEINSAMER Kern für Dashboard (PA-Seite, Nachtragen) UND Werk-App (Sub-App /produktion/): nutzt nur q/one/all/scalar/insert_id
// und ruft fachliche Funktionen über pa_fn() auf (Dashboard-Name, sonst der erp_-Spiegel der Produktions-App). Darf deshalb NICHT
// core/schema.php laden (Sub-App-DB-Kollision). Tabellen legt pa_schema() selbst an (best-effort, Version in app_meta).
//
// Begriffe: Unter-Charge = Kunden-Charge mit Buchstabe (2785.A). Regel (Nico 12.11.2026, ersetzt 11.11.): ein NEUER Buchstabe bei
// einer anderen Bulk-Charge bzw. einem anderen Eimer UND an einem neuen Produktionstag („einen Tag später: dieselbe Nummer mit dem
// nächsten Buchstaben“). Gleicher Bulk am selben Tag = gleicher Buchstabe.
// Im Lager gibt es je Unter-Charge GENAU EINE Fertigware-Charge; jede Teilmenge erhöht deren Menge.

const PA_SCHEMA_VERSION = '5';

function pa_meta(string $k, ?string $set = null): string {
    if ($set !== null) { q("INSERT INTO app_meta (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)", [$k, $set]); return $set; }
    return (string) (scalar("SELECT v FROM app_meta WHERE k=?", [$k]) ?? '');
}
// Fachfunktion aufrufen: Dashboard-Version, sonst der erp_-Spiegel der Produktions-App. null = keine vorhanden.
function pa_fn(string $dash, string $erp, ...$a) {
    if (function_exists($dash)) return $dash(...$a);
    if (function_exists($erp)) return $erp(...$a);
    return null;
}
function pa_spalte_da(string $t, string $c): bool {
    static $c2 = [];
    return $c2[$t . '.' . $c] ??= (int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?", [$t, $c]) > 0;
}
function pa_schema(): void {
    static $ok = false; if ($ok) return; $ok = true;
    try { if (pa_meta('pa_ablauf_schema') === PA_SCHEMA_VERSION) return; } catch (\Throwable $e) { return; }
    try {
        q("CREATE TABLE IF NOT EXISTS pa_unter_charge (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pa_id INT NOT NULL,
            buchstabe VARCHAR(4) NOT NULL,
            charge_nr VARCHAR(60) NOT NULL,            -- Kunden-Charge, z. B. 2785.A (Abweichung nur mit Grund)
            charge_vorgabe VARCHAR(60) NULL,           -- was das System vorgegeben hat (bei Abweichung)
            mhd DATE NULL,
            bulk_charge_id INT NULL,                   -- genau EINE Bulk-Charge (Lager-Charge) …
            prod_charge_id INT NULL,                   -- … oder EIN Eimer/Sack (prod_charge) bei Eigenproduktion
            linie VARCHAR(40) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'laeuft',   -- laeuft | fertig (| nachtrag: nur während der Nachtrag-Buchung)
            tag DATE NULL,                             -- Produktionstag (Europe/Berlin), 13.11.2026
            fertig_charge_id INT NULL,                 -- Fertigware-Charge im Lager (eine je Unter-Charge)
            kapsel_mg DECIMAL(10,2) NULL,              -- Ø-Kapselgewicht aus den Stichproben
            kapseln_bulk DECIMAL(14,0) NULL,           -- Kapselzahl der Unter-Charge aus dem Sackgewicht (Gegenprobe)
            abweichung_grund VARCHAR(255) NULL,
            angelegt DATETIME NOT NULL, angelegt_von VARCHAR(120) NULL,
            abgeschlossen_am DATETIME NULL,
            protokoll_dokument_id INT NULL,
            KEY pa (pa_id, status)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        q("CREATE TABLE IF NOT EXISTS pa_teilmenge (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pa_id INT NOT NULL, unter_charge_id INT NOT NULL,
            menge DECIMAL(14,0) NOT NULL,
            umfang VARCHAR(12) NOT NULL DEFAULT 'komplett',    -- komplett | vor_etikett
            rueckstell INT NOT NULL DEFAULT 0,
            foto VARCHAR(190) NULL,
            art VARCHAR(10) NOT NULL DEFAULT 'werk',            -- werk | nachtrag
            datum DATE NULL,                                    -- Produktionsdatum (Nachtrag)
            mitarbeiter VARCHAR(120) NULL,
            geliefert TINYINT NOT NULL DEFAULT 0,               -- Nachtrag: schon geliefert
            notiz VARCHAR(255) NULL,
            angelegt DATETIME NOT NULL,
            KEY pa (pa_id), KEY uc (unter_charge_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        q("CREATE TABLE IF NOT EXISTS pa_karton (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pa_id INT NOT NULL, unter_charge_id INT NOT NULL,
            nummer INT NOT NULL,                                -- fortlaufend je PA, kein von-n
            menge DECIMAL(14,0) NOT NULL,
            teilmenge_id INT NULL,                              -- mit welcher Teilmenge gebucht
            palette VARCHAR(40) NULL,                           -- später: Palettieren per Scan
            gedruckt DATETIME NOT NULL, von VARCHAR(120) NULL,
            UNIQUE KEY nr (pa_id, nummer), KEY uc (unter_charge_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        q("CREATE TABLE IF NOT EXISTS pa_scan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pa_id INT NOT NULL, unter_charge_id INT NULL,
            rolle VARCHAR(20) NOT NULL,                         -- bulk | eimer | glas | deckel | etikett | leerkapsel | karton | sonst
            item_id INT NULL, charge_id INT NULL, gebinde_id INT NULL, prod_charge_id INT NULL,
            code VARCHAR(120) NULL,
            zeit DATETIME NOT NULL, von VARCHAR(120) NULL,
            KEY pa (pa_id, unter_charge_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        q("CREATE TABLE IF NOT EXISTS pa_ereignis (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pa_id INT NOT NULL, unter_charge_id INT NULL,
            typ VARCHAR(24) NOT NULL,     -- start | wiederanlauf | chargenwechsel | kapselprobe | sackgewicht | teilmenge | karton | abweichung | abschluss | sperre
            text VARCHAR(500) NULL,
            wert VARCHAR(255) NULL,
            foto VARCHAR(190) NULL, foto2 VARCHAR(190) NULL,
            von VARCHAR(120) NULL, zeit DATETIME NOT NULL,
            KEY pa (pa_id, unter_charge_id)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if (!pa_spalte_da('produktion_verbrauch', 'teilmenge_id')) { try { q("ALTER TABLE produktion_verbrauch ADD COLUMN teilmenge_id INT NULL"); } catch (\Throwable $e) {} }
        // 13.11.2026 (Fable-3 T1.4): Produktionstag je Unter-Charge – ein Nachtrag für gestern gehört zu gestern, nicht zum Anlagezeitpunkt.
        if (!pa_spalte_da('pa_unter_charge', 'tag')) { try { q("ALTER TABLE pa_unter_charge ADD COLUMN tag DATE NULL AFTER status"); } catch (\Throwable $e) {} }
        pa_meta('pa_ablauf_schema', PA_SCHEMA_VERSION);
    } catch (\Throwable $e) { error_log('pa_schema: ' . $e->getMessage()); }
}
function pa_uploads(): string {
    if (defined('BX_UPLOADS')) return BX_UPLOADS;
    if (defined('BX_DATA')) return BX_DATA . '/uploads';
    return dirname(__DIR__) . '/data/uploads';
}
// Foto aus $_FILES speichern. Rückgabe Dateiname oder ''.
function pa_foto_speichern(array $f, string $prefix): string {
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return '';
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true)) $ext = 'jpg';
    $dir = pa_uploads(); if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = 'pa_' . preg_replace('/[^a-z0-9_]/', '', $prefix) . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    return @move_uploaded_file($f['tmp_name'], $dir . '/' . $name) ? $name : '';
}
function pa_ereignis(int $pa_id, ?int $uc_id, string $typ, string $text, string $wert = '', string $foto = '', string $foto2 = '', string $von = ''): int {
    pa_schema();
    q("INSERT INTO pa_ereignis (pa_id, unter_charge_id, typ, text, wert, foto, foto2, von, zeit) VALUES (?,?,?,?,?,?,?,?,?)",
      [$pa_id, $uc_id ?: null, $typ, mb_substr($text, 0, 500), $wert !== '' ? mb_substr($wert, 0, 255) : null, $foto ?: null, $foto2 ?: null, $von ?: null, gmdate('Y-m-d H:i:s')]);
    return insert_id();
}
// Aufgabe ans Büro (Abweichung): Mitarbeiter bekommt nur einen Hinweis, die Klärung liegt bei Nico/Tabea.
function pa_aufgabe_buero(int $pa_id, string $titel, string $text, string $von = ''): void {
    try {
        q("INSERT INTO aufgabe (titel, beschreibung, prio, status, ref_typ, ref_id, angelegt) VALUES (?,?,?,?,?,?,?)",
          [mb_substr($titel, 0, 190), mb_substr($text . ($von !== '' ? ' (gemeldet: ' . $von . ')' : ''), 0, 2000), 2, 'offen', 'produktion', $pa_id, gmdate('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {}
}

// ---- Charge und MHD vom System (Teil 3) ---------------------------------------------------------------------------------
function pa_basis(int $pa_id): string {
    $nr = (string) scalar("SELECT nummer FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $b = trim((string) preg_replace('/^PR[-\s]*/i', '', $nr));
    return $b !== '' ? $b : 'PR' . $pa_id;
}
// Schon benutzte Buchstaben eines PA (aus Unter-Chargen und aus vorhandenen Fertigware-Chargen 2785.A …).
function pa_buchstaben_benutzt(int $pa_id): array {
    pa_schema();
    $b = array_map('strval', array_column(all("SELECT buchstabe FROM pa_unter_charge WHERE pa_id=?", [$pa_id]), 'buchstabe'));
    $basis = pa_basis($pa_id);
    foreach (all("SELECT charge_nr FROM charge WHERE pa_id=?", [$pa_id]) as $c)
        if (preg_match('/\.([A-Z]{1,2})$/', (string)$c['charge_nr'], $m)) $b[] = $m[1];
    return array_values(array_unique($b));
}
function pa_naechster_buchstabe(int $pa_id): string {
    $max = -1;
    foreach (pa_buchstaben_benutzt($pa_id) as $x) { $n = strlen($x) === 1 ? ord($x) - 65 : 26 + (ord($x[1]) - 65); $max = max($max, $n); }
    $n = $max + 1;
    return $n < 26 ? chr(65 + $n) : 'Z' . chr(65 + min(25, $n - 26));
}
// MHD-Vorgabe: das frühere aus Bulk-MHD und heute + Haltbarkeit des Produkts (sonst Standard 18 Monate).
function pa_mhd_vorgabe(int $pa_id, int $bulk_charge_id = 0): string {
    $pa = one("SELECT pa.mhd, p.haltbarkeit FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id WHERE pa.id=?", [$pa_id]) ?: [];
    $mon = (int) preg_replace('/\D+/', '', (string)($pa['haltbarkeit'] ?? ''));
    if ($mon <= 0) $mon = (int) (pa_meta('mhd_monate_standard') ?: 18);
    $mhd = !empty($pa['mhd']) ? (string)$pa['mhd'] : heute_ort_plus('+' . $mon . ' months');   // 13.11.2026: ab heute in Berlin
    if ($bulk_charge_id > 0) { $bm = (string) scalar("SELECT mhd FROM charge WHERE id=?", [$bulk_charge_id]); if ($bm !== '' && $bm < $mhd) $mhd = $bm; }
    return $mhd;
}
function pa_unter_chargen(int $pa_id): array {
    pa_schema();
    return all("SELECT * FROM pa_unter_charge WHERE pa_id=? ORDER BY id", [$pa_id]);
}
// Wurde der PA mit „Rest storniert“ abgeschlossen (Ereignis abschluss/storniert)? Dann gibt es keinen offenen Rest mehr –
// kein Phantombestand über den alten Einlager-Weg (Fable-3 T4.2, 13.11.2026). Eine Regel für Dashboard und Produktions-App.
// QM-Stationen (gehören nicht zur Produktion im Werk). Gleiche Liste wie produktion_qm_stationen() im Dashboard.
function pa_qm_stationen(): array { return ['Qualitätsprüfung', 'Produktions-Freigabe', 'Versand-Freigabe']; }
// Was ist an einem PA noch zu tun? (13.11.2026, Werk-App Teil 2) – EINE Rechnung für Werk-App, Plan und „Läuft“:
// offene Produktionsschritte (ohne QM-Stationen, in Reihenfolge) und offene Menge (Soll minus gebuchte Fertigware).
function pa_noch_offen(int $pa_id): array {
    $qm = pa_qm_stationen();
    $schritte = [];
    foreach (all("SELECT station FROM produktion_schritt WHERE pa_id=? AND erledigt=0 ORDER BY sort, id", [$pa_id]) as $s)
        if (!in_array((string)$s['station'], $qm, true) && !in_array((string)$s['station'], $schritte, true)) $schritte[] = (string)$s['station'];
    $soll = (float) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $ist = (float) scalar("SELECT COALESCE(SUM(menge),0) FROM charge WHERE pa_id=?", [$pa_id]);
    return ['schritte' => $schritte, 'menge' => max(0.0, $soll - $ist)];
}
// Zeile „Noch offen: Verpacken, Etikettieren · 825 Stk“ (+ „Etikett fehlt - nur bis vor dem Etikettieren“). $label übersetzt Stationen
// (Werk-App DE/EN/UK), $vorlage/$etikettTxt kommen aus den Sprachtexten. '' = nichts mehr offen.
function pa_noch_offen_text(int $pa_id, bool $etikett_gesperrt = false, string $vorlage = 'Noch offen: %1$s · %2$s Stk',
                            ?callable $label = null, string $etikettTxt = 'Etikett fehlt - nur bis vor dem Etikettieren'): string {
    $o = pa_noch_offen($pa_id);
    if (!$o['schritte'] && $o['menge'] <= 0) return '';
    $st = $o['schritte'] ? implode(', ', array_map(fn($s) => $label ? $label($s) : $s, $o['schritte'])) : '–';
    return sprintf($vorlage, $st, pa_mz($o['menge'])) . ($etikett_gesperrt ? ' · ' . $etikettTxt : '');
}
// Voll produziert = Produktionsschritte erledigt (13.11.2026, Werk-App Teil 3). Erreicht die gebuchte Fertigware das Soll, werden alle
// offenen Produktionsschritte (vor den QM-Stationen) erledigt, die laufende Unter-Charge abgeschlossen, Vermerk im Verlauf und im
// Chargenprotokoll; der PA geht in die Qualitätsprüfung. Ausnahme: fehlt das Etikett bzw. ist es nicht freigegeben, bleibt
// „Etikettieren“ offen. $grund: bei der Datenprüfung (Altbestand, Admin) Pflicht. Rückgabe: Anzahl erledigter Schritte.
function pa_voll_produziert_erledigen(int $pa_id, int $uc_id = 0, string $von = '', string $grund = ''): int {
    $soll = (float) scalar("SELECT menge FROM produktionsauftrag WHERE id=?", [$pa_id]);
    $ist = (float) scalar("SELECT COALESCE(SUM(menge),0) FROM charge WHERE pa_id=?", [$pa_id]);
    if ($soll <= 0 || $ist + 1e-6 < $soll) return 0;
    $etSperre = (string) (pa_fn('produktion_etikett_sperre', 'erp_etikett_sperre', $pa_id) ?? '');
    $qm = pa_qm_stationen(); $n = 0; $namen = [];
    foreach (all("SELECT id, station FROM produktion_schritt WHERE pa_id=? AND erledigt=0 ORDER BY sort, id", [$pa_id]) as $s) {
        if (in_array((string)$s['station'], $qm, true)) continue;
        if ($etSperre !== '' && (string)$s['station'] === 'Etikettieren') continue;   // ohne freigegebenes Etikett bleibt Etikettieren offen
        $n += q("UPDATE produktion_schritt SET erledigt=1, erledigt_at=?, erledigt_von=? WHERE id=? AND erledigt=0", [gmdate('Y-m-d H:i:s'), mb_substr($von ?: 'System', 0, 100), (int)$s['id']])->rowCount();
        $namen[] = (string)$s['station'];
    }
    if ($n === 0) return 0;
    $akt = pa_aktuelle_unter_charge($pa_id);
    if ($uc_id <= 0 && $akt) $uc_id = (int)$akt['id'];
    if ($uc_id <= 0) $uc_id = (int) scalar("SELECT id FROM pa_unter_charge WHERE pa_id=? ORDER BY id DESC LIMIT 1", [$pa_id]);
    $txt = 'voll produziert (' . pa_mz($ist) . ' von ' . pa_mz($soll) . ') - automatisch erledigt: ' . implode(', ', array_unique($namen))
         . ($etSperre !== '' ? '. Etikettieren bleibt offen (Etikett fehlt/nicht freigegeben).' : '.') . ($grund !== '' ? ' Grund: ' . $grund : '');
    pa_ereignis($pa_id, $uc_id ?: null, 'auto_erledigt', $txt, '', '', '', $von ?: 'System');
    $aid = (int) scalar("SELECT COALESCE(auftrag_id,0) FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if ($aid > 0) pa_fn('log_aktivitaet', 'erp_log_aktivitaet', 'auftrag', $aid, 'team', 'Produktion ' . (string) scalar("SELECT nummer FROM produktionsauftrag WHERE id=?", [$pa_id]) . ': ' . $txt, 'status');
    if ($akt) pa_unter_charge_schliessen((int)$akt['id'], $von, 'voll produziert');
    return $n;
}
function pa_rest_storniert(int $pa_id): bool {
    try { return (int) scalar("SELECT COUNT(*) FROM pa_ereignis WHERE pa_id=? AND typ='abschluss' AND wert='storniert'", [$pa_id]) > 0; }
    catch (\Throwable $e) { return false; }
}
// Alter Einlager-Weg (Rest als eigene Charge ohne Unter-Charge): nicht, solange eine Unter-Charge läuft. '' = darf, sonst Grund.
function pa_einlagern_alt_sperre(int $pa_id): string {
    $uc = pa_aktuelle_unter_charge($pa_id);
    return $uc ? 'Es läuft noch die Unter-Charge ' . $uc['charge_nr'] . ' – erst in der Werk-App abschließen, dann einlagern.' : '';
}
function pa_unter_charge(int $id): ?array { pa_schema(); return $id > 0 ? one("SELECT * FROM pa_unter_charge WHERE id=?", [$id]) : null; }
function pa_aktuelle_unter_charge(int $pa_id): ?array {
    pa_schema();
    return one("SELECT * FROM pa_unter_charge WHERE pa_id=? AND status='laeuft' ORDER BY id DESC LIMIT 1", [$pa_id]);
}
// Vorschlag für Charge + MHD bei gegebenem Bulk (Werk-App zeigt ihn groß an): gleicher Bulk wie die laufende Unter-Charge =
// gleicher Buchstabe, sonst der nächste.
// Produktionstag (Europe/Berlin) – heute bzw. der Tag einer Unter-Charge: letzte Teilmenge (datum), sonst Anlage (UTC -> Berlin).
function pa_tag_heute(): string { return heute_ort(); }
function pa_uc_tag(array $uc): string {
    if (!empty($uc['tag'])) return substr((string)$uc['tag'], 0, 10);   // 13.11.2026: eigene Spalte (Nachtrag = gewähltes Datum)
    $d = (string) scalar("SELECT MAX(datum) FROM pa_teilmenge WHERE unter_charge_id=?", [(int)$uc['id']]);
    if ($d !== '') return substr($d, 0, 10);
    try { return (new DateTime((string)$uc['angelegt'] . ' UTC'))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d'); }
    catch (\Throwable $e) { return substr((string)$uc['angelegt'], 0, 10); }
}
// Tagesregel serverseitig (13.11.2026): eine Unter-Charge gehört zu GENAU einem Produktionstag (Berlin). Läuft sie von einem anderen
// Tag, wird nichts gebucht (Teilmenge, Karton, Scan) – an einem neuen Tag gibt es einen neuen Buchstaben, also neu starten.
// '' = gleicher Tag, sonst die Meldung. $tag: Buchungstag (Nachtrag: das gewählte Datum), sonst heute.
function pa_uc_tag_pruefen(array $uc, ?string $tag = null): string {
    $tag = $tag ?: pa_tag_heute();
    $ucTag = pa_uc_tag($uc);
    if ($ucTag === '' || $ucTag === $tag) return '';
    return 'Neuer Tag - bitte neu starten. Die laufende Unter-Charge ' . (string)$uc['charge_nr'] . ' ist vom ' . date('d.m.Y', strtotime($ucTag)) . '; an einem neuen Produktionstag gibt es einen neuen Buchstaben.';
}
function pa_vorgabe(int $pa_id, int $bulk_charge_id = 0, int $prod_charge_id = 0, ?string $tag = null): array {
    $akt = pa_aktuelle_unter_charge($pa_id);
    $tag = ($tag !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tag)) ? $tag : pa_tag_heute();
    if ($akt && pa_uc_tag($akt) === $tag   // neuer Produktionstag = nächster Buchstabe (Nico 12.11.2026)
        && ((int)$akt['bulk_charge_id'] === $bulk_charge_id && (int)$akt['prod_charge_id'] === $prod_charge_id || ($bulk_charge_id === 0 && $prod_charge_id === 0)))
        return ['neu' => false, 'buchstabe' => (string)$akt['buchstabe'], 'charge_nr' => (string)$akt['charge_nr'], 'mhd' => (string)$akt['mhd'], 'unter_charge_id' => (int)$akt['id']];
    $bs = pa_naechster_buchstabe($pa_id);
    return ['neu' => true, 'buchstabe' => $bs, 'charge_nr' => pa_basis($pa_id) . '.' . $bs, 'mhd' => pa_mhd_vorgabe($pa_id, $bulk_charge_id), 'unter_charge_id' => 0];
}
// Unter-Charge starten bzw. am nächsten Tag wieder anlaufen lassen. Fotos Linie leer + Stempel sind Pflicht ($fotos['linie'],
// $fotos['stempel'] = gespeicherte Dateinamen). Anderer Bulk/Eimer als die laufende Unter-Charge = Chargenwechsel (die alte wird fertig,
// neuer Buchstabe). Charge abweichend von der Vorgabe nur mit Grund -> Aufgabe ans Büro.
function pa_starten(int $pa_id, array $d, string $von): array {
    pa_schema();
    $bulk = (int)($d['bulk_charge_id'] ?? 0); $eimer = (int)($d['prod_charge_id'] ?? 0);
    $nachtrag = !empty($d['nachtrag']);   // Nachtrag im Dashboard (Produktion ist schon gelaufen): keine Fotos möglich
    // 13.11.2026 (Teil 3.4, Entscheidung Nico): „Linie leer“ ist ein Pflicht-Haken (kein Foto mehr), das Stempelfoto bleibt Pflicht.
    if (!$nachtrag && (empty($d['linie_leer']) || ($d['foto_stempel'] ?? '') === '')) return ['ok' => false, 'msg' => 'Bitte „Linie leer“ abhaken und das Foto vom Stempelbild machen.'];
    $leerTxt = 'Linie leer, keine Reste der vorigen Charge – bestätigt von ' . ($von ?: 'Mitarbeiter') . ' um ' . (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y H:i');
    // 13.11.2026: Bulk-Charge in Quarantäne oder gesperrt startet keine Unter-Charge (auch kein Wiederanlauf/Nachtrag).
    if ($bulk > 0) {
        $bSt = one("SELECT COALESCE(charge_nr, CONCAT('#', id)) AS nr, status FROM charge WHERE id=?", [$bulk]);
        if ($bSt && in_array((string)$bSt['status'], ['quarantaene', 'gesperrt'], true))
            return ['ok' => false, 'msg' => 'Bulk-Charge ' . $bSt['nr'] . ' ist ' . ((string)$bSt['status'] === 'gesperrt' ? 'gesperrt' : 'in Quarantäne') . ' – erst nach der QM-Freigabe produzieren.'];
    }
    $tag = ($nachtrag && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['datum'] ?? ''))) ? (string)$d['datum'] : pa_tag_heute();
    $v = pa_vorgabe($pa_id, $bulk, $eimer, $tag);
    $akt = pa_aktuelle_unter_charge($pa_id);
    if (!$v['neu'] && $akt) {   // gleiche Bulk-Charge am selben Tag: gleicher Buchstabe, Wiederanlauf (z. B. nach der Pause)
        pa_ereignis($pa_id, (int)$akt['id'], 'wiederanlauf', 'Wiederanlauf ' . $akt['charge_nr'] . ': ' . $leerTxt . '. Stempel auf Charge ' . $akt['charge_nr'] . ' / MHD ' . date('d.m.Y', strtotime((string)$akt['mhd'])) . ' eingestellt.', (string)($d['linie'] ?? ''), '', (string)$d['foto_stempel'], $von);
        return ['ok' => true, 'unter_charge_id' => (int)$akt['id'], 'charge_nr' => (string)$akt['charge_nr'], 'mhd' => (string)$akt['mhd'], 'neu' => false];
    }
    $charge = trim((string)($d['charge_nr'] ?? '')) ?: $v['charge_nr'];
    $grund = trim((string)($d['abweichung_grund'] ?? ''));
    if ($charge !== $v['charge_nr'] && $grund === '') return ['ok' => false, 'msg' => 'Andere Charge als vorgegeben (' . $v['charge_nr'] . ') nur mit Grund.'];
    // 13.11.2026 (Fable-3 T1.4): erst prüfen, dann schließen. Ein Nachtrag schließt die laufende Unter-Charge nie (eigene Unter-Charge
    // mit Status „nachtrag“, pa_nachtrag_buchen() schließt oder verwirft sie).
    if ($akt && !$nachtrag) pa_unter_charge_schliessen((int)$akt['id'], $von, ((int)$akt['bulk_charge_id'] === $bulk && (int)$akt['prod_charge_id'] === $eimer) ? 'neuer Produktionstag' : 'Chargenwechsel');
    if ($nachtrag) $akt = null;
    $mhd = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['mhd'] ?? '')) ? (string)$d['mhd'] : $v['mhd'];
    q("INSERT INTO pa_unter_charge (pa_id, buchstabe, charge_nr, charge_vorgabe, mhd, bulk_charge_id, prod_charge_id, linie, status, abweichung_grund, angelegt, angelegt_von)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      [$pa_id, $v['buchstabe'], mb_substr($charge, 0, 60), $charge !== $v['charge_nr'] ? $v['charge_nr'] : null, $mhd, $bulk ?: null, $eimer ?: null,
       mb_substr((string)($d['linie'] ?? ''), 0, 40) ?: null, $nachtrag ? 'nachtrag' : 'laeuft', $grund ?: null, gmdate('Y-m-d H:i:s'), $von ?: null]);
    $uc = insert_id();
    if (pa_spalte_da('pa_unter_charge', 'tag')) q("UPDATE pa_unter_charge SET tag=? WHERE id=?", [$tag, $uc]);   // Produktionstag (13.11.2026)
    $bulkTxt = $bulk ? 'Bulk-Charge ' . (string) scalar("SELECT COALESCE(charge_nr, CONCAT('#',id)) FROM charge WHERE id=?", [$bulk]) : ($eimer ? 'Eimer ' . (string) scalar("SELECT CONCAT(nummer, COALESCE(sub_kennung,'')) FROM prod_charge WHERE id=?", [$eimer]) : 'ohne Bulk-Scan');
    pa_ereignis($pa_id, $uc, $akt ? 'chargenwechsel' : 'start', ($akt ? 'Chargenwechsel: ' : 'Start: ') . 'Unter-Charge ' . $charge . ' (' . $bulkTxt . '), MHD ' . date('d.m.Y', strtotime($mhd)) . ($nachtrag ? '. Nachgetragen im Dashboard (ohne Fotos).' : '. ' . $leerTxt . '. Stempel eingestellt.'), (string)($d['linie'] ?? ''), '', (string)($d['foto_stempel'] ?? ''), $von);
    if ($grund !== '') { pa_ereignis($pa_id, $uc, 'abweichung', 'Charge abweichend von der Vorgabe ' . $v['charge_nr'] . ': ' . $grund, '', '', '', $von);
        if (!$nachtrag) pa_aufgabe_buero($pa_id, 'Produktion: Charge abweichend (' . $charge . ' statt ' . $v['charge_nr'] . ')', 'Grund: ' . $grund . ' · ' . $von, $von); }
    if (!$nachtrag && (string) scalar("SELECT status FROM produktionsauftrag WHERE id=?", [$pa_id]) === 'offen') q("UPDATE produktionsauftrag SET status='laufend' WHERE id=?", [$pa_id]);
    return ['ok' => true, 'unter_charge_id' => $uc, 'charge_nr' => $charge, 'mhd' => $mhd, 'neu' => true, 'vorgabe' => $v['charge_nr'], 'grund' => $grund];
}

// Teilmenge nachtragen (Dashboard), EIN Weg für jedes Datum (13.11.2026, Fable-3 T1.4).
// - Gleicher Bulk am gewählten Tag = dieselbe Unter-Charge (laufend oder schon fertig), sonst eine neue mit dem gewählten Tag (Spalte tag).
// - Die laufende Unter-Charge eines anderen Tages bleibt unberührt.
// - Schlägt die Buchung fehl, wird die eben angelegte Unter-Charge samt Start-Ereignis verworfen: kein Buchstabe verbraucht.
// - Gelingt sie: Nachtrag für heute ohne laufende Unter-Charge läuft weiter (wie bisher), sonst wird die Nachtrag-Unter-Charge abgeschlossen.
// $d: bulk_charge_id, datum, charge_nr ('' = Vorgabe), grund, mhd. $o: Optionen für pa_teilmenge_buchen (rueckstell, foto, mitarbeiter, geliefert).
function pa_nachtrag_buchen(int $pa_id, float $menge, array $d, array $o, string $von): array {
    pa_schema();
    $tag = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['datum'] ?? '')) ? (string)$d['datum'] : pa_tag_heute();
    if ($tag > pa_tag_heute()) return ['ok' => false, 'msg' => 'Ein Nachtrag geht nicht in die Zukunft.'];
    $bulk = (int)($d['bulk_charge_id'] ?? 0);
    $charge = trim((string)($d['charge_nr'] ?? ''));
    $uc = null;
    foreach (all("SELECT * FROM pa_unter_charge WHERE pa_id=? AND COALESCE(bulk_charge_id,0)=? AND COALESCE(prod_charge_id,0)=0 AND status IN ('laeuft','fertig') ORDER BY status='laeuft' DESC, id DESC", [$pa_id, $bulk]) as $u)
        if (pa_uc_tag($u) === $tag) { $uc = $u; break; }
    if ($uc && $charge !== '' && $charge !== (string)$uc['charge_nr'])
        return ['ok' => false, 'msg' => 'Gleicher Bulk am selben Tag = gleiche Charge ' . $uc['charge_nr'] . '. Ein neuer Buchstabe kommt nur bei anderem Bulk oder anderem Tag.'];
    $neu = null;
    if (!$uc) {
        $neu = pa_starten($pa_id, ['bulk_charge_id' => $bulk, 'nachtrag' => true, 'datum' => $tag, 'charge_nr' => $charge,
                                   'abweichung_grund' => (string)($d['grund'] ?? ''), 'mhd' => (string)($d['mhd'] ?? '')], $von);
        if (empty($neu['ok'])) return $neu;
        $uc = pa_unter_charge((int)$neu['unter_charge_id']);
    }
    $r = pa_teilmenge_buchen($pa_id, (int)$uc['id'], $menge, ['art' => 'nachtrag', 'datum' => $tag, 'von' => $von, 'nachtrag_komplett' => true] + $o);
    $r['unter_charge_id'] = (int)$uc['id'];
    if ($neu) {
        if (empty($r['ok'])) { pa_nachtrag_verwerfen((int)$uc['id']); $r['unter_charge_id'] = 0; return $r; }
        $laeuft = (int) scalar("SELECT COUNT(*) FROM pa_unter_charge WHERE pa_id=? AND status='laeuft'", [$pa_id]);
        if ($tag === pa_tag_heute() && $laeuft === 0) q("UPDATE pa_unter_charge SET status='laeuft' WHERE id=?", [(int)$uc['id']]);
        else pa_unter_charge_schliessen((int)$uc['id'], $von, 'Nachtrag ' . date('d.m.Y', strtotime($tag)));
        if (($neu['grund'] ?? '') !== '') pa_aufgabe_buero($pa_id, 'Produktion: Charge abweichend (' . $neu['charge_nr'] . ' statt ' . $neu['vorgabe'] . ')', 'Grund: ' . $neu['grund'] . ' · ' . $von, $von);
        if ((string) scalar("SELECT status FROM produktionsauftrag WHERE id=?", [$pa_id]) === 'offen') q("UPDATE produktionsauftrag SET status='laufend' WHERE id=?", [$pa_id]);
    } elseif (!empty($r['ok']) && (string)$uc['status'] === 'fertig' && function_exists('pa_protokoll_ablegen')) {
        pa_protokoll_ablegen((int)$uc['id']);   // Chargenprotokoll der schon abgeschlossenen Unter-Charge neu ablegen
    }
    return $r;
}
// Eine eben für einen Nachtrag angelegte Unter-Charge wieder entfernen (nur Status „nachtrag“ und ohne gebuchte Teilmenge).
function pa_nachtrag_verwerfen(int $uc_id): void {
    $uc = pa_unter_charge($uc_id);
    if (!$uc || (string)$uc['status'] !== 'nachtrag') return;
    if ((int) scalar("SELECT COUNT(*) FROM pa_teilmenge WHERE unter_charge_id=?", [$uc_id]) > 0) { q("UPDATE pa_unter_charge SET status='fertig', abgeschlossen_am=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $uc_id]); return; }
    q("DELETE FROM pa_ereignis WHERE unter_charge_id=?", [$uc_id]);
    q("DELETE FROM pa_unter_charge WHERE id=? AND status='nachtrag'", [$uc_id]);
}
function pa_unter_charge_schliessen(int $uc_id, string $von = '', string $anlass = ''): void {
    $uc = pa_unter_charge($uc_id);
    if (!$uc || $uc['status'] === 'fertig') return;
    q("UPDATE pa_unter_charge SET status='fertig', abgeschlossen_am=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $uc_id]);
    if (function_exists('pa_gegenprobe')) pa_gegenprobe($uc_id, $von);
    pa_ereignis((int)$uc['pa_id'], $uc_id, 'abschluss', 'Unter-Charge ' . $uc['charge_nr'] . ' abgeschlossen' . ($anlass !== '' ? ' (' . $anlass . ')' : '') . ': ' . pa_mz(pa_uc_menge($uc_id)) . ' Stk.', '', '', '', $von);
    if (function_exists('pa_protokoll_ablegen')) pa_protokoll_ablegen($uc_id);
}
// Dashboard: Schritt ohne Menge abhaken nur Admin – Rückfrage mit Grund (füllt das versteckte Feld „grund“).
function pa_admin_grund_js(): string {
    return 'onsubmit="var g=prompt(\'Schritt ohne Menge abhaken (nur Admin). Grund:\');if(!g)return false;this.grund.value=g;return true;"';
}
function pa_mz($x): string { return rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ','); }
function pa_uc_menge(int $uc_id): float { return (float) scalar("SELECT COALESCE(SUM(menge),0) FROM pa_teilmenge WHERE unter_charge_id=? AND umfang='komplett'", [$uc_id]); }

// ---- Material scannen (Teil 4) -------------------------------------------------------------------------------------------
// Gescanntes Material zur laufenden Unter-Charge merken. Der Verbrauch der Teilmenge bevorzugt diese Chargen (statt reinem FEFO).
function pa_scan_merken(int $pa_id, int $uc_id, string $rolle, int $item_id, int $charge_id, int $gebinde_id, string $code, string $von, int $prod_charge_id = 0): void {
    pa_schema();
    q("INSERT INTO pa_scan (pa_id, unter_charge_id, rolle, item_id, charge_id, gebinde_id, prod_charge_id, code, zeit, von) VALUES (?,?,?,?,?,?,?,?,?,?)",
      [$pa_id, $uc_id ?: null, $rolle, $item_id ?: null, $charge_id ?: null, $gebinde_id ?: null, $prod_charge_id ?: null, mb_substr($code, 0, 120), gmdate('Y-m-d H:i:s'), $von ?: null]);
}
function pa_gescannte_chargen(int $pa_id, int $uc_id): array {
    return array_values(array_unique(array_map('intval', array_column(all("SELECT DISTINCT charge_id FROM pa_scan WHERE pa_id=? AND (unter_charge_id=? OR unter_charge_id IS NULL) AND charge_id IS NOT NULL", [$pa_id, $uc_id]), 'charge_id'))));
}

// ---- Teilmenge buchen (Teil 5) -------------------------------------------------------------------------------------------
// Bucht SOFORT: Verbrauch raus (alle Stationen anteilig, gescannte Chargen zuerst, Rest FEFO) und Fertigware rein (die Fertigware-
// Charge der Unter-Charge wächst). Fehlt das Etikett (Sperre), nur „bis vor dem Etikettieren" (keine Fertigware, Stand am PA).
// $o: rueckstell, foto, art (werk|nachtrag), datum, mitarbeiter, notiz, geliefert, kartons (IDs), von.
function pa_teilmenge_buchen(int $pa_id, int $uc_id, float $menge, array $o = []): array {
    pa_schema();
    $uc = pa_unter_charge($uc_id);
    if (!$uc || (int)$uc['pa_id'] !== $pa_id) return ['ok' => false, 'msg' => 'Bitte zuerst starten (Unter-Charge fehlt).'];
    $pa = one("SELECT * FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok' => false, 'msg' => 'Produktionsauftrag nicht gefunden.'];
    if ((string)$pa['status'] === 'vorbereitung') return ['ok' => false, 'msg' => 'Der Auftrag ist noch nicht freigegeben (Beschaffungsweg offen).'];
    $tagB = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($o['datum'] ?? '')) ? (string)$o['datum'] : pa_tag_heute();
    if (($tf = pa_uc_tag_pruefen($uc, $tagB)) !== '') return ['ok' => false, 'msg' => $tf];   // 13.11.2026: Tagesregel serverseitig
    $menge = round($menge);
    if ($menge <= 0) return ['ok' => false, 'msg' => 'Bitte eine Menge größer 0 angeben.'];
    $gebucht = (float) scalar("SELECT COALESCE(SUM(menge),0) FROM charge WHERE pa_id=?", [$pa_id]);
    $rest = max(0.0, (float)$pa['menge'] - $gebucht);
    if ($menge > $rest + 1e-6) return ['ok' => false, 'msg' => 'Es sind nur noch ' . pa_mz($rest) . ' Stk offen.'];
    $von = (string)($o['von'] ?? '');
    $sperre = (string) (pa_fn('produktion_etikett_sperre', 'erp_etikett_sperre', $pa_id) ?? '');
    $umfang = ($sperre !== '' && empty($o['nachtrag_komplett'])) ? 'vor_etikett' : 'komplett';
    $stationen = array_values(array_unique(array_map(fn($r) => (string)$r['station'], all("SELECT station FROM produktion_schritt WHERE pa_id=? ORDER BY sort", [$pa_id]))));
    if ($umfang === 'vor_etikett') $stationen = array_values(array_filter($stationen, fn($s) => $s !== 'Etikettieren'));
    $basis = $umfang === 'vor_etikett' ? max($gebucht, (float)($pa['menge_vor_etikett'] ?? 0)) : $gebucht;
    $bis = $basis + $menge;
    // Regel: eine Unter-Charge stammt aus GENAU EINER Bulk-Charge. Reicht deren Rest nicht, wird nichts gebucht (Chargenwechsel nötig).
    if ((int)$uc['bulk_charge_id'] > 0 && empty($o['ohne_material'])) {
        $je = (float) scalar("SELECT COALESCE(NULLIF(p.einheiten_pro_packung,0), NULLIF(a.stueck,0), 0) FROM produktionsauftrag pa LEFT JOIN produkt p ON p.id=pa.produkt_id LEFT JOIN auftrag a ON a.id=pa.auftrag_id WHERE pa.id=?", [$pa_id]);
        $bv = (float) scalar("SELECT menge_verfuegbar FROM charge WHERE id=?", [(int)$uc['bulk_charge_id']]);
        if ($je > 0 && $menge * $je > $bv * 1.005 + 0.5) {
            $max = (int) floor($bv / $je);
            return ['ok' => false, 'fehler' => 'bulk', 'msg' => 'Die Bulk-Charge dieser Unter-Charge reicht nur noch für ' . pa_mz($max) . ' Stk (' . pa_mz($bv) . ' übrig). Bitte höchstens ' . pa_mz($max) . ' Stk buchen und für den Rest „Chargenwechsel“ (neuer Bulk = neuer Buchstabe).'];
        }
    }
    // 1) Material prüfen (alles oder nichts) …
    $GLOBALS['pa_bevorzugt'] = pa_gescannte_chargen($pa_id, $uc_id);
    $fehlt = [];
    foreach ($stationen as $st) { $r = pa_fn('produktion_station_entnahme', 'erp_station_entnahme', $pa_id, $st, $bis, true) ?? ['ok' => true];
        if (!($r['ok'] ?? true)) foreach (($r['fehlt'] ?? []) as $f) $fehlt[] = $f; }
    if ($fehlt && empty($o['ohne_material'])) { unset($GLOBALS['pa_bevorzugt']);
        return ['ok' => false, 'fehler' => 'mangel', 'fehlt' => $fehlt, 'msg' => 'Nicht genug Material für ' . pa_mz($menge) . ' Stk: ' . implode(', ', array_map(fn($f) => (string)$f['name'] . ' (fehlt ' . pa_mz($f['fehlt']) . ' ' . ($f['einheit'] ?? '') . ')', $fehlt)) . '.']; }
    // 2) … Teilmenge anlegen, dann buchen (Verbrauch trägt die Teilmenge)
    $datum = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($o['datum'] ?? '')) ? (string)$o['datum'] : pa_tag_heute();   // Produktionstag Berlin (Buchstabe je Tag)
    q("INSERT INTO pa_teilmenge (pa_id, unter_charge_id, menge, umfang, rueckstell, foto, art, datum, mitarbeiter, geliefert, notiz, angelegt) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
      [$pa_id, $uc_id, $menge, $umfang, max(0, (int)($o['rueckstell'] ?? 0)), ($o['foto'] ?? '') ?: null, ($o['art'] ?? 'werk') === 'nachtrag' ? 'nachtrag' : 'werk', $datum,
       mb_substr((string)($o['mitarbeiter'] ?? $von), 0, 120) ?: null, !empty($o['geliefert']) ? 1 : 0, mb_substr(trim((string)($o['notiz'] ?? '')), 0, 255) ?: null, gmdate('Y-m-d H:i:s')]);
    $tm = insert_id();
    $GLOBALS['pa_teilmenge_id'] = $tm;
    if (!$fehlt) foreach ($stationen as $st) pa_fn('produktion_station_entnahme', 'erp_station_entnahme', $pa_id, $st, $bis, false);
    unset($GLOBALS['pa_bevorzugt'], $GLOBALS['pa_teilmenge_id']);
    if ($fehlt) pa_ereignis($pa_id, $uc_id, 'abweichung', 'Teilmenge ohne vollständige Materialbuchung nachgetragen (fehlt: ' . implode(', ', array_map(fn($f) => (string)$f['name'], $fehlt)) . ').', '', '', '', $von);
    if (!empty($o['kartons'])) foreach ((array)$o['kartons'] as $k) q("UPDATE pa_karton SET teilmenge_id=? WHERE id=? AND pa_id=? AND teilmenge_id IS NULL", [$tm, (int)$k, $pa_id]);

    if ($umfang === 'vor_etikett') {
        if (pa_spalte_da('produktionsauftrag', 'menge_vor_etikett')) q("UPDATE produktionsauftrag SET menge_vor_etikett=?, status=IF(status='offen','laufend',status) WHERE id=?", [(int) round($bis), $pa_id]);
        pa_ereignis($pa_id, $uc_id, 'teilmenge', pa_mz($menge) . ' Stk bis vor dem Etikettieren (' . $sperre . ')', (string)$menge, (string)($o['foto'] ?? ''), '', $von);
        pa_fn('teilmenge_anweisung_verrechnen', 'erp_teilmenge_anweisung_verrechnen', $pa_id, $menge, 'vor_etikett');
        return ['ok' => true, 'msg' => pa_mz($menge) . ' Stk bis vor dem Etikettieren gebucht – Etikettieren ist gesperrt: ' . $sperre, 'teilmenge_id' => $tm, 'umfang' => 'vor_etikett'];
    }
    // 3) Fertigware: die Charge der Unter-Charge wächst (eine Charge je Unter-Charge)
    $cid = (int)($uc['fertig_charge_id'] ?? 0);
    if ($cid > 0 && one("SELECT id FROM charge WHERE id=?", [$cid])) {
        q("UPDATE charge SET menge=menge+?, menge_verfuegbar=menge_verfuegbar+?, status=IF(status='leer','frei',status) WHERE id=?", [$menge, $menge, $cid]);
        $neu = false;
    } else {
        $istBulk = empty($pa['produkt_id']) && !empty($pa['rezeptur_id']);
        $item = $istBulk ? (int) pa_fn('rezeptur_bulkitem', 'erp_rezeptur_bulkitem', (int)$pa['rezeptur_id']) : (int) pa_fn('produkt_lageritem', 'erp_produkt_lageritem', (int)$pa['produkt_id']);
        if ($item <= 0) return ['ok' => false, 'msg' => 'Lagerartikel zum Produkt fehlt – Teilmenge nicht eingebucht (Verbrauch ist gebucht, bitte Büro).', 'teilmenge_id' => $tm];
        if (!$istBulk && (pa_fn('auftrag_ist_fulfillment', 'erp_auftrag_ist_fulfillment', (int)($pa['auftrag_id'] ?? 0)))) pa_fn('bsku_ensure', 'erp_bsku_ensure', $item);
        q("INSERT INTO charge (charge_nr,item_id,menge,menge_verfuegbar,einheit,mhd,wareneingang,status,notiz,pa_id,auftrag_id,angelegt) VALUES (?,?,?,?, 'Stk', ?, ?, 'frei', ?, ?, ?, ?)",   /* Wareneingang = Produktionstag (Berlin), 13.11.2026 */
          [(string)$uc['charge_nr'], $item, $menge, $menge, (string)$uc['mhd'], $datum, 'Aus Produktion ' . $pa['nummer'] . ' (Unter-Charge ' . $uc['charge_nr'] . ')', $pa_id, ((int)($pa['auftrag_id'] ?? 0) ?: null), gmdate('Y-m-d H:i:s')]);
        $cid = insert_id();
        q("UPDATE pa_unter_charge SET fertig_charge_id=? WHERE id=?", [$cid, $uc_id]);
        $neu = true;
    }
    $rueck = max(0, (int)($o['rueckstell'] ?? 0));
    if ($rueck > 0) { try { q("INSERT INTO prod_probe (pa_id, item_id, charge_id, ebene, batch_nr, anzahl, bezeichnung, angelegt, erfasst_von) VALUES (?,?,?,?,?,?,?,?,?)",
        [$pa_id, (int) scalar("SELECT item_id FROM charge WHERE id=?", [$cid]), $cid, 'endprodukt', (string)$uc['charge_nr'], $rueck, 'Rückstellmuster ' . $uc['charge_nr'], gmdate('Y-m-d H:i:s'), $von ?: null]); } catch (\Throwable $e) {} }
    if ((string)$pa['status'] === 'offen') q("UPDATE produktionsauftrag SET status='laufend' WHERE id=?", [$pa_id]);
    pa_fn('reservierung_abgleichen', 'erp_reservierung_abgleichen', $pa_id);
    pa_fn('teilmenge_anweisung_verrechnen', 'erp_teilmenge_anweisung_verrechnen', $pa_id, $menge, 'komplett');
    $fertig = ((float)$pa['menge'] - $gebucht - $menge) <= 0.0001;
    if (!empty($pa['kunde_id']))
        pa_fn('log_aktivitaet', 'erp_log_aktivitaet', 'kunde', (int)$pa['kunde_id'], 'team', 'Teilmenge ' . pa_mz($menge) . ' Stk produziert (Charge ' . $uc['charge_nr'] . ', Produktion ' . $pa['nummer'] . ').', 'auftrag', 'auftrag', (int)($pa['auftrag_id'] ?? 0));
    pa_ereignis($pa_id, $uc_id, 'teilmenge', pa_mz($menge) . ' Stk gebucht' . ($rueck ? ', ' . $rueck . ' Rückstellmuster' : '') . (($o['art'] ?? '') === 'nachtrag' ? ' (nachgetragen, Produktionsdatum ' . date('d.m.Y', strtotime($datum)) . ')' : '') . '.', (string)$menge, (string)($o['foto'] ?? ''), '', $von);
    if ($fertig) pa_voll_produziert_erledigen($pa_id, $uc_id, $von);   // 13.11.2026 (Teil 3): voll produziert = Produktionsschritte erledigt
    // CoA je neuer Fertigcharge bzw. Bericht bei voller Menge – zuletzt (in der Werk-App ein Aufruf ans Dashboard, darf die Buchung nicht aufhalten).
    if ($neu || $fertig) pa_fn('fertigware_qm_freigabe_nach', 'erp_fertigware_dokumente_melden', $cid, ...(function_exists('fertigware_qm_freigabe_nach') ? ['', $fertig, 'Produktionsabschluss'] : [$fertig]));
    return ['ok' => true, 'msg' => pa_mz($menge) . ' Stk gebucht – Charge ' . $uc['charge_nr'] . ' (MHD ' . date('d.m.Y', strtotime((string)$uc['mhd'])) . ').', 'teilmenge_id' => $tm, 'charge_id' => $cid, 'fertig' => $fertig, 'umfang' => 'komplett'];
}

// ---- Kartons (Ergänzung 6) -----------------------------------------------------------------------------------------------
// Je vollem Karton ein Datensatz mit fortlaufender Nummer je PA und genau einer Unter-Charge. Die Summe der Kartons ist die Menge.
function pa_karton_anlegen(int $pa_id, int $uc_id, float $menge, string $von): array {
    pa_schema();
    $uc = pa_unter_charge($uc_id);
    if (!$uc || (int)$uc['pa_id'] !== $pa_id) return ['ok' => false, 'msg' => 'Keine laufende Unter-Charge.'];
    if (($tf = pa_uc_tag_pruefen($uc)) !== '') return ['ok' => false, 'msg' => $tf];   // 13.11.2026: Tagesregel serverseitig
    $menge = round($menge);
    if ($menge <= 0) return ['ok' => false, 'msg' => 'Bitte die Menge im Karton angeben.'];
    $nr = (int) scalar("SELECT COALESCE(MAX(nummer),0)+1 FROM pa_karton WHERE pa_id=?", [$pa_id]);
    q("INSERT INTO pa_karton (pa_id, unter_charge_id, nummer, menge, gedruckt, von) VALUES (?,?,?,?,?,?)", [$pa_id, $uc_id, $nr, $menge, gmdate('Y-m-d H:i:s'), $von ?: null]);
    $id = insert_id();
    q("UPDATE produktionsauftrag SET karton_stueck=COALESCE(NULLIF(karton_stueck,0), ?) WHERE id=?", [(int)$menge, $pa_id]);
    return ['ok' => true, 'karton_id' => $id, 'nummer' => $nr, 'code' => (string)$uc['charge_nr'] . '-K' . str_pad((string)$nr, 3, '0', STR_PAD_LEFT)];
}
function pa_kartons(int $pa_id, int $uc_id = 0): array {
    pa_schema();
    return all("SELECT * FROM pa_karton WHERE pa_id=?" . ($uc_id ? " AND unter_charge_id=" . (int)$uc_id : "") . " ORDER BY nummer", [$pa_id]);
}
// Noch keiner Teilmenge zugeordnete Kartons der Unter-Charge (Vorbelegung „Teilmenge fertig").
function pa_kartons_offen(int $uc_id): array {
    return all("SELECT * FROM pa_karton WHERE unter_charge_id=? AND teilmenge_id IS NULL ORDER BY nummer", [$uc_id]);
}
// Vorbelegung Karton-Menge: zuletzt gedruckter Karton bzw. Stück je Karton am PA.
function pa_karton_vorschlag(int $pa_id): int {
    $l = (int) scalar("SELECT menge FROM pa_karton WHERE pa_id=? ORDER BY id DESC LIMIT 1", [$pa_id]);
    return $l ?: (int) scalar("SELECT COALESCE(karton_stueck,0) FROM produktionsauftrag WHERE id=?", [$pa_id]);
}

// ---- Abschluss -----------------------------------------------------------------------------------------------------------
// Ist gegen Soll. Fehlt Menge: Rest kommt noch / Rest storniert. Abweichungen = Aufgabe ans Büro, kein Stopp.
function pa_abschliessen(int $pa_id, string $rest_wahl, string $von): array {
    pa_schema();
    $pa = one("SELECT * FROM produktionsauftrag WHERE id=?", [$pa_id]);
    if (!$pa) return ['ok' => false, 'msg' => 'Produktionsauftrag nicht gefunden.'];
    if ($akt = pa_aktuelle_unter_charge($pa_id)) pa_unter_charge_schliessen((int)$akt['id'], $von, 'Abschluss');
    $ist = (float) scalar("SELECT COALESCE(SUM(menge),0) FROM charge WHERE pa_id=?", [$pa_id]);
    $soll = (float)$pa['menge'];
    $txt = 'Abschluss: ' . pa_mz($ist) . ' von ' . pa_mz($soll) . ' Stk.';
    if ($ist + 0.0001 < $soll) {
        if ($rest_wahl === 'storniert') { $txt .= ' Rest ' . pa_mz($soll - $ist) . ' Stk storniert.'; pa_aufgabe_buero($pa_id, 'Produktion ' . $pa['nummer'] . ': Rest storniert', $txt . ' Bitte Rechnung/Gutschrift prüfen.', $von); }
        else { $txt .= ' Rest ' . pa_mz($soll - $ist) . ' Stk kommt noch.'; pa_ereignis($pa_id, null, 'abschluss', $txt, 'rest_kommt', '', '', $von); return ['ok' => true, 'msg' => $txt . ' Der Auftrag bleibt offen.']; }
    }
    // Produktion fertig: alle Produktionsschritte (ohne QM-Stationen) erledigt – weiter geht es mit Qualitätsprüfung/Freigaben.
    q("UPDATE produktion_schritt SET erledigt=1, erledigt_at=?, erledigt_von=? WHERE pa_id=? AND erledigt=0 AND station NOT IN ('Qualitätsprüfung','Produktions-Freigabe','Versand-Freigabe')",
      [gmdate('Y-m-d H:i:s'), mb_substr($von ?: 'Produktion', 0, 100), $pa_id]);
    if ($rest_wahl === 'storniert' && !empty($pa['auftrag_id']) && pa_spalte_da('auftrag', 'fortschritt_ziel'))
        q("UPDATE auftrag SET fortschritt_ziel=?, rest_status='storniert' WHERE id=?", [(int) round($ist), (int)$pa['auftrag_id']]);
    pa_ereignis($pa_id, null, 'abschluss', $txt, $rest_wahl, '', '', $von);
    return ['ok' => true, 'msg' => $txt, 'abgeschlossen' => true];
}

// Chargenprotokoll je Unter-Charge (Teil 7)
require_once __DIR__ . '/pa_protokoll.php';
require_once __DIR__ . '/pa_eimer.php';   // Eimerplanung (Teil 8)
require_once __DIR__ . '/pa_kapsel.php';   // Kapselzahl aus Gewicht + Gegenprobe (Teil 9)
