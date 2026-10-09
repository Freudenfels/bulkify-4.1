<?php
// Eigene Tabellen des Produktions-Programms (pr_*). KEINE Dashboard-Tabellen hier (die nur über erp.php).
// Aktuell: pr_pa_daten = erfasste Werte je Produktionsauftrag (Mischmenge, Kontrollgewichte, Muster …).
require_once __DIR__ . '/db.php';

function pr_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS pr_pa_daten (
        pa_id        INT          NOT NULL,
        feld         VARCHAR(40)  NOT NULL,
        wert         VARCHAR(190) NULL,
        von          VARCHAR(190) NULL,
        aktualisiert DATETIME     NULL,
        PRIMARY KEY (pa_id, feld)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Reinigungspläne (Werk): wiederkehrende Reinigungen je Bereich/Maschine.
    db()->exec("CREATE TABLE IF NOT EXISTS pr_reinigung (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        titel            VARCHAR(190) NOT NULL,
        bereich          VARCHAR(120) NULL,
        intervall        VARCHAR(60)  NULL,
        notiz            TEXT         NULL,
        letzte_reinigung DATE         NULL,
        letzte_von       VARCHAR(190) NULL,
        aktiv            TINYINT(1)   NOT NULL DEFAULT 1,
        angelegt         DATETIME     NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Eine Spalte additiv ergänzen (best-effort, wie ensure_column im Dashboard – aber nur für pr_*-Tabellen).
// Fehler (Spalte existiert bereits o. Ä.) werden geschluckt, damit kein 500 beim Seitenaufruf entsteht.
function pr_ensure_column(string $tabelle, string $spalte, string $definition): void {
    try {
        $da = (bool) scalar("SELECT COUNT(*) FROM information_schema.columns
                             WHERE table_schema=DATABASE() AND table_name=? AND column_name=?", [$tabelle, $spalte]);
        if (!$da) db()->exec("ALTER TABLE `$tabelle` ADD COLUMN `$spalte` $definition");
    } catch (\Throwable $e) { /* best-effort */ }
}

// --- Betriebsmittel: Räume & Maschinen (mit Reinigungsintervall) -----------------------------
function pr_stamm_schema(): void {
    static $done = false; if ($done) return; $done = true;
    foreach (['pr_raum', 'pr_maschine'] as $t) {
        $extra = $t === 'pr_maschine' ? 'raum_id INT NULL,' : '';
        db()->exec("CREATE TABLE IF NOT EXISTS $t (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            $extra
            reinigung_intervall VARCHAR(20) NULL,
            notiz TEXT NULL,
            letzte_reinigung DATE NULL,
            letzte_von VARCHAR(190) NULL,
            aktiv TINYINT(1) NOT NULL DEFAULT 1,
            angelegt DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    // Maschinenfuhrpark (Spec 9.3): Typ (an Produktionsschritt gekoppelt) + eigener QR-Code zum Scannen je Step.
    pr_ensure_column('pr_maschine', 'typ', "VARCHAR(40) NULL");
    pr_ensure_column('pr_maschine', 'qr_code', "VARCHAR(60) NULL");
}

// --- Maschinentypen (Spec 9.3) ----------------------------------------------------------------
// Feste Startliste laut Lastenheft; Code => Anzeigename. Nico kann später erweitern (dann hier ergänzen).
function pr_maschinen_typen(): array {
    return [
        'mischer'        => 'Mischer',
        'kapselmaschine' => 'Kapselmaschine',
        'tablettenpresse'=> 'Tablettenpresse',
        'abfuelllinie'   => 'Abfülllinie',
        'stickmaschine'  => 'Stickmaschine',
        'pulver_auto'    => 'Pulver-Abfüllmaschine (automatisch)',
        'pulver_manuell' => 'Pulver-Abfüllmaschine (manuell)',
        'blister'        => 'Blistermaschine',
        'fluessig'       => 'Flüssig-Flaschenabfüllung',
        'pouchbag'       => 'Pouchbag-/Standbodenbeutel-Füllmaschine',
    ];
}
function pr_maschinentyp_label(string $code): string { return pr_maschinen_typen()[$code] ?? ($code ?: '–'); }

// Welche Maschinentypen gehören zu einem Produktionsschritt (Station)? (Spec 9.1 – Scan je Step.)
// Leere Liste = Schritt braucht keine Maschine (z. B. Freigaben, Etikettieren, Bereitstellen).
function pr_station_maschinentypen(string $station): array {
    return match ($station) {
        'Mischen'             => ['mischer'],
        'Verkapselung'        => ['kapselmaschine'],
        'Tablettierung'       => ['tablettenpresse'],
        'Stick-Abfüllung'     => ['stickmaschine'],
        'Pulver-Abfüllung'    => ['pulver_auto', 'pulver_manuell', 'pouchbag'],
        'Abfüllung'           => ['fluessig'],
        'Verpacken'           => ['abfuelllinie', 'blister'],
        default               => [],
    };
}
// Aktive Maschinen, die zu den Typen einer Station passen (für die Auswahl/Scan im Produktionsmodus).
function pr_maschinen_fuer_station(string $station): array {
    pr_stamm_schema();
    $typen = pr_station_maschinentypen($station);
    if (!$typen) return [];
    $in = implode(',', array_fill(0, count($typen), '?'));
    return all("SELECT * FROM pr_maschine WHERE aktiv=1 AND typ IN ($in) ORDER BY name", $typen);
}
// Eine Maschine zu einem gescannten/eingegebenen QR-Code oder Namen finden (Spec 9.1).
function pr_maschine_per_qr(string $code): ?array {
    pr_stamm_schema();
    $code = trim($code);
    if ($code === '') return null;
    return one("SELECT * FROM pr_maschine WHERE aktiv=1 AND (qr_code=? OR name=?) ORDER BY id LIMIT 1", [$code, $code]);
}
function pr_maschine(int $id): ?array {
    if ($id <= 0) return null; pr_stamm_schema();
    return one("SELECT * FROM pr_maschine WHERE id=?", [$id]);
}

// --- Reinigung: ereignisgesteuert + harte Sperre (Spec 9.4) -----------------------------------
// Dokumentiert jede Reinigung/Start-Prüfung je Maschine: war sie beim Start sauber (ja/nein),
// wurde nach der Nutzung gereinigt (+ Unterschrift, optional Bild), von wem, wann, zu welchem Auftrag.
function pr_reinigung_log_schema(): void {
    static $done = false; if ($done) return; $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS pr_maschine_reinigung (
        id INT AUTO_INCREMENT PRIMARY KEY,
        maschine_id INT NOT NULL,
        pa_id INT NULL,
        schritt_id INT NULL,
        sauber_bei_start TINYINT(1) NULL,
        gereinigt TINYINT(1) NOT NULL DEFAULT 0,
        unterschrift VARCHAR(190) NULL,
        bild VARCHAR(255) NULL,
        von VARCHAR(190) NULL,
        angelegt DATETIME NOT NULL,
        KEY idx_maschine (maschine_id), KEY idx_pa (pa_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
// Ein Reinigungs-/Start-Prüf-Ereignis erfassen. Bei bestätigter Reinigung zusätzlich das
// letzte_reinigung/letzte_von der Maschine fortschreiben (Basis der Reinigungspläne).
function pr_maschine_reinigung_erfassen(array $d): int {
    pr_reinigung_log_schema();
    $mid = (int)($d['maschine_id'] ?? 0);
    if ($mid <= 0) return 0;
    $gereinigt = !empty($d['gereinigt']) ? 1 : 0;
    q("INSERT INTO pr_maschine_reinigung (maschine_id,pa_id,schritt_id,sauber_bei_start,gereinigt,unterschrift,bild,von,angelegt)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [$mid, ($d['pa_id'] ?? null) ?: null, ($d['schritt_id'] ?? null) ?: null,
       array_key_exists('sauber_bei_start', $d) && $d['sauber_bei_start'] !== null ? (int)$d['sauber_bei_start'] : null,
       $gereinigt, mb_substr(trim((string)($d['unterschrift'] ?? '')), 0, 190) ?: null,
       mb_substr(trim((string)($d['bild'] ?? '')), 0, 255) ?: null,
       mb_substr(trim((string)($d['von'] ?? '')), 0, 190) ?: null, gmdate('Y-m-d H:i:s')]);
    $neu = insert_id();
    if ($gereinigt) {
        pr_stamm_schema();
        $wer = trim((string)($d['unterschrift'] ?? '')) ?: trim((string)($d['von'] ?? ''));
        q("UPDATE pr_maschine SET letzte_reinigung=CURDATE(), letzte_von=? WHERE id=?", [$wer !== '' ? $wer : null, $mid]);
    }
    return $neu;
}
// Optionales Reinigungs-Bild aus einem Datei-Upload speichern (best-effort). Rückgabe = relativer
// Pfad unter BX_UPLOADS (produktion/reinigung/…) oder '' (kein/ungültiges Bild). Keine 500er bei Fehlern.
function pr_reinigung_bild_speichern(string $feld): string {
    try {
        if (empty($_FILES[$feld]) || ($_FILES[$feld]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';
        $tmp = (string)$_FILES[$feld]['tmp_name'];
        if (!is_uploaded_file($tmp)) return '';
        if ((int)$_FILES[$feld]['size'] > 12 * 1024 * 1024) return '';   // max 12 MB
        $typ = function_exists('mime_content_type') ? (string) mime_content_type($tmp) : '';
        $ext = match ($typ) { 'image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', 'image/heic'=>'heic', default=>'' };
        if ($ext === '') return '';
        $dir = (defined('BX_UPLOADS') ? BX_UPLOADS : (__DIR__ . '/../../data/uploads')) . '/produktion/reinigung';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return '';
        $name = 'reinigung_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $dir . '/' . $name)) return '';
        return 'produktion/reinigung/' . $name;
    } catch (\Throwable $e) { return ''; }
}

// Reinigungs-/Prüf-Ereignisse zu einem Auftrag (für den Produktionsbericht).
function pr_maschine_reinigung_log_pa(int $pa_id): array {
    pr_reinigung_log_schema();
    if ($pa_id <= 0) return [];
    return all("SELECT r.*, m.name AS maschine_name FROM pr_maschine_reinigung r
                LEFT JOIN pr_maschine m ON m.id=r.maschine_id WHERE r.pa_id=? ORDER BY r.id", [$pa_id]);
}
// Reinigungsintervalle: Code => [Label, Tage (null = kein Datumsrhythmus, z. B. vor jeder Produktion)].
function pr_intervalle(): array {
    return ['je_charge'=>['Vor jeder Produktion', null], 'taeglich'=>['Täglich', 1], 'woechentlich'=>['Wöchentlich', 7],
            'monatlich'=>['Monatlich', 30], 'quartal'=>['Vierteljährlich', 90]];
}
function pr_intervall_label(string $code): string { return pr_intervalle()[$code][0] ?? ($code ?: '–'); }
function pr_intervall_tage(string $code): ?int { return pr_intervalle()[$code][1] ?? null; }

function pr_raum_alle(): array { pr_stamm_schema(); return all("SELECT * FROM pr_raum WHERE aktiv=1 ORDER BY name"); }
function pr_raum_neu(string $name, string $intervall, string $notiz): void {
    pr_stamm_schema();
    q("INSERT INTO pr_raum (name,reinigung_intervall,notiz,angelegt) VALUES (?,?,?,?)",
      [trim($name), trim($intervall) ?: null, trim($notiz) ?: null, gmdate('Y-m-d H:i:s')]);
}
function pr_raum_loeschen(int $id): void { pr_stamm_schema(); q("UPDATE pr_raum SET aktiv=0 WHERE id=?", [$id]); }

function pr_maschine_alle(): array {
    pr_stamm_schema();
    return all("SELECT m.*, r.name AS raum_name FROM pr_maschine m LEFT JOIN pr_raum r ON r.id=m.raum_id WHERE m.aktiv=1 ORDER BY m.name");
}
function pr_maschine_neu(string $name, ?int $raum_id, string $intervall, string $notiz, string $typ = '', string $qr = ''): int {
    pr_stamm_schema();
    $typ = in_array($typ, array_keys(pr_maschinen_typen()), true) ? $typ : '';
    q("INSERT INTO pr_maschine (name,raum_id,reinigung_intervall,notiz,typ,qr_code,angelegt) VALUES (?,?,?,?,?,?,?)",
      [trim($name), $raum_id ?: null, trim($intervall) ?: null, trim($notiz) ?: null, $typ ?: null, trim($qr) ?: null, gmdate('Y-m-d H:i:s')]);
    $id = insert_id();
    // Ohne vorgegebenen QR-Code einen eindeutigen vergeben (MA-<id>), damit jede Maschine scanbar ist.
    if (trim($qr) === '') q("UPDATE pr_maschine SET qr_code=? WHERE id=?", ['MA-' . $id, $id]);
    return $id;
}
// Typ/QR einer bestehenden Maschine ändern (additiv, ohne Löschen/Neuanlage).
function pr_maschine_setzen(int $id, string $typ, string $qr): void {
    pr_stamm_schema();
    if ($id <= 0) return;
    $typ = in_array($typ, array_keys(pr_maschinen_typen()), true) ? $typ : '';
    q("UPDATE pr_maschine SET typ=?, qr_code=? WHERE id=?", [$typ ?: null, trim($qr) ?: ('MA-' . $id), $id]);
}
function pr_maschine_loeschen(int $id): void { pr_stamm_schema(); q("UPDATE pr_maschine SET aktiv=0 WHERE id=?", [$id]); }

// Als gereinigt markieren (typ 'raum'|'maschine').
function pr_gereinigt_setzen(string $typ, int $id, string $von): void {
    pr_stamm_schema();
    $t = $typ === 'maschine' ? 'pr_maschine' : 'pr_raum';
    q("UPDATE $t SET letzte_reinigung=CURDATE(), letzte_von=? WHERE id=?", [$von !== '' ? $von : null, $id]);
}

// Generierte Reinigungspläne aus Räumen + Maschinen, die ein Intervall haben. Mit Fälligkeit.
function pr_reinigungsplaene(): array {
    $heute = date('Y-m-d');
    $zeile = function (string $typ, array $r, string $bereich) use ($heute): array {
        $code = (string)($r['reinigung_intervall'] ?? '');
        $tage = pr_intervall_tage($code);
        $letzte = (string)($r['letzte_reinigung'] ?? '');
        $naechste = ($tage !== null && $letzte !== '') ? date('Y-m-d', strtotime($letzte . ' +' . $tage . ' days')) : null;
        $faellig = $tage !== null && ($letzte === '' || $naechste <= $heute);
        return ['typ'=>$typ, 'id'=>(int)$r['id'], 'name'=>(string)$r['name'], 'bereich'=>$bereich,
                'intervall'=>$code, 'intervall_label'=>pr_intervall_label($code),
                'letzte_reinigung'=>$letzte, 'letzte_von'=>(string)($r['letzte_von'] ?? ''),
                'naechste'=>$naechste, 'faellig'=>$faellig];
    };
    $out = [];
    foreach (pr_maschine_alle() as $m) if (!empty($m['reinigung_intervall'])) $out[] = $zeile('maschine', $m, 'Maschine' . (!empty($m['raum_name']) ? ' · ' . $m['raum_name'] : ''));
    foreach (pr_raum_alle() as $r)     if (!empty($r['reinigung_intervall'])) $out[] = $zeile('raum', $r, 'Raum');
    // Fällige zuerst.
    usort($out, fn($a, $b) => ($b['faellig'] <=> $a['faellig']) ?: strcmp($a['name'], $b['name']));
    return $out;
}

// --- Reinigungspläne (alt, manuell – durch Maschinen/Räume ersetzt; Tabelle bleibt, UI nutzt pr_reinigungsplaene) ---
function pr_reinigung_alle(): array { pr_schema(); return all("SELECT * FROM pr_reinigung WHERE aktiv=1 ORDER BY bereich, titel"); }
function pr_reinigung_neu(string $titel, string $bereich, string $intervall, string $notiz): int {
    pr_schema();
    q("INSERT INTO pr_reinigung (titel,bereich,intervall,notiz,angelegt) VALUES (?,?,?,?,?)",
      [trim($titel), trim($bereich) ?: null, trim($intervall) ?: null, trim($notiz) ?: null, gmdate('Y-m-d H:i:s')]);
    return insert_id();
}
function pr_reinigung_gereinigt(int $id, string $von): void {
    pr_schema();
    q("UPDATE pr_reinigung SET letzte_reinigung=CURDATE(), letzte_von=? WHERE id=?", [$von !== '' ? $von : null, $id]);
}
function pr_reinigung_loeschen(int $id): void { pr_schema(); q("UPDATE pr_reinigung SET aktiv=0 WHERE id=?", [$id]); }

// Einen erfassten Wert setzen (überschreibt denselben feld-Eintrag).
function pr_daten_setzen(int $pa_id, string $feld, string $wert, string $von = ''): void {
    if ($pa_id <= 0 || $feld === '') return;
    pr_schema();
    q("INSERT INTO pr_pa_daten (pa_id, feld, wert, von, aktualisiert) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE wert=VALUES(wert), von=VALUES(von), aktualisiert=VALUES(aktualisiert)",
      [$pa_id, $feld, $wert, $von !== '' ? $von : null, gmdate('Y-m-d H:i:s')]);
}
// Alle erfassten Werte eines Auftrags als feld => [wert,von,aktualisiert].
function pr_daten(int $pa_id): array {
    pr_schema();
    $o = [];
    foreach (all("SELECT feld, wert, von, aktualisiert FROM pr_pa_daten WHERE pa_id=?", [$pa_id]) as $r)
        $o[$r['feld']] = $r;
    return $o;
}
