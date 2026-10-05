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
function pr_maschine_neu(string $name, ?int $raum_id, string $intervall, string $notiz): void {
    pr_stamm_schema();
    q("INSERT INTO pr_maschine (name,raum_id,reinigung_intervall,notiz,angelegt) VALUES (?,?,?,?,?)",
      [trim($name), $raum_id ?: null, trim($intervall) ?: null, trim($notiz) ?: null, gmdate('Y-m-d H:i:s')]);
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
