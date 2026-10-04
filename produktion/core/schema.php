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
}

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
