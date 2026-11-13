<?php
// Einmalige Datenpflege live, Paket 13.11.2026 Teil 6.6 – ausdrücklich freigegeben von Nico (Arbeitsanweisung 13.11.2026):
// BE-2890 (Bündel aus 9 Bulk-Positionen) in Einzelbestellungen aufteilen, eine je Position.
// Läuft genau einmal (app_meta dpf_1311_erledigt) aus dem Schema-Abgleich, über bestellung_aufteilen_je_position():
// Positionen werden verschoben (Liefermengen, stornierte Positionen, Chargen-/Dokument-Verknüpfungen bleiben), kein Lieferant
// wird gesetzt, keine Nachricht an irgendwen. Verlauf „aufgeteilt, Freigabe Nico 13.11.2026“. Ergebnis: app_meta dpf_1311_ergebnis.
function dpf_1311_einmal(): void {
    try {
        if (meta_get('dpf_1311_erledigt', '') === '1' || !table_exists('bestellung')) return;
        meta_set('dpf_1311_erledigt', '1');   // zuerst setzen: lieber nie als doppelt
        require_once __DIR__ . '/bestellung_einzeln.php';
        $bid = (int) scalar("SELECT id FROM bestellung WHERE nummer='BE-2890' AND status<>'storniert'");
        $n = $bid ? (int) scalar("SELECT COUNT(*) FROM bestellung_position WHERE bestellung_id=?", [$bid]) : 0;
        $erg = ($bid && $n >= 2) ? bestellung_aufteilen_je_position($bid, 'aufgeteilt, Freigabe Nico 13.11.2026', 'Datenpflege')
                                 : ['ok' => false, 'msg' => 'BE-2890 nicht gefunden oder nur eine Position – nichts geändert.', 'neu' => []];
        meta_set('dpf_1311_ergebnis', json_encode($erg, JSON_UNESCAPED_UNICODE));
    } catch (\Throwable $e) {
        try { meta_set('dpf_1311_ergebnis', 'Fehler: ' . $e->getMessage()); } catch (\Throwable $e2) {}
    }
}
