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

// --- Reinigungspläne -------------------------------------------------------------------------
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
