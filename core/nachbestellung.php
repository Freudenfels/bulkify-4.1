<?php
// Einfache Nachbestellung aus dem Kundenportal.
// Idee (Nico): der Kunde tippt am schon bestellten Produkt nur die Menge ein und bestätigt verbindlich –
// KEIN neuer Etikett-Upload, KEINE neue Preisanfrage, KEIN neues Angebot für den Kunden. Produkt/Verpackung/
// Etikett-Design und der zuletzt vereinbarte Preis je Packung werden aus dem letzten bestätigten Angebot kopiert.
// bulkify nimmt die Nachbestellung nur zur kurzen PREISPRÜFUNG an (in 98% bleibt der Preis gleich -> ein Klick
// „Bestätigen & freigeben"); nur im Ausnahmefall „Preis anpassen" -> geht als kurze Freigabe zum Kunden zurück.
// Beim Bestätigen entsteht über den vorhandenen idempotenten Weg auftrag_aus_angebot() der Auftrag + Rechnung +
// Produktionsauftrag; die Etikett-Freigabe wird aus dem Vorauftrag übernommen (keine erneute Freigabe nötig).

// Selbst-Schema (best-effort, additiv): ein nachvollziehbarer Datensatz je Nachbestellung.
function nachbestellung_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS nachbestellung (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kunde_id INT NOT NULL,
            quelle_angebot_id INT NULL,
            quelle_auftrag_id INT NULL,
            produkt_id INT NULL,
            rezeptur_id INT NULL,
            stueck INT NOT NULL DEFAULT 0,
            menge INT NOT NULL DEFAULT 0,
            vk_stueck DECIMAL(12,4) NOT NULL DEFAULT 0,
            vk_neu DECIMAL(12,4) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pruefung',   -- pruefung|preis_neu|bestaetigt|abgelehnt
            angebot_id INT NULL,
            auftrag_id INT NULL,
            grund VARCHAR(500) NULL,
            angelegt DATETIME NOT NULL,
            bearbeitet DATETIME NULL,
            angelegt_von VARCHAR(190) NULL,
            KEY idx_kunde (kunde_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* best-effort, siehe CLAUDE.md */ }
}

// Konfiguration + zuletzt vereinbarter Preis aus einem bestätigten Angebot des Kunden. Null, wenn das Angebot
// nicht zum Kunden gehört, nicht bestätigt ist oder keine bestätigte Staffel hat.
function nachbestellung_quelle(int $angebot_id, int $kunde_id): ?array {
    if ($angebot_id <= 0 || $kunde_id <= 0) return null;
    $a = one("SELECT * FROM angebot WHERE id=? AND kunde_id=? AND status='bestaetigt'", [$angebot_id, $kunde_id]);
    if (!$a) return null;
    $s = one("SELECT * FROM angebot_staffel WHERE angebot_id=? AND bestaetigt=1 ORDER BY sort LIMIT 1", [$angebot_id]);
    if (!$s) return null;
    $auftragId = (int) scalar("SELECT id FROM auftrag WHERE angebot_id=? ORDER BY id DESC LIMIT 1", [$angebot_id]);
    $rezId = (int)($a['rezeptur_id'] ?? 0);
    if (!$rezId && (int)($a['produkt_id'] ?? 0)) $rezId = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
    return [
        'angebot_id' => $angebot_id,
        'auftrag_id' => $auftragId ?: null,
        'produkt_id' => (int)($a['produkt_id'] ?? 0) ?: null,
        'rezeptur_id'=> $rezId ?: null,
        'stueck'     => (int)($s['stueck'] ?? 0),
        'menge'      => (int)($s['menge'] ?? 0),
        'vk_stueck'  => (float)$s['vk_stueck'],
    ];
}

// Kunde legt eine Nachbestellung an (verbindlich, wartet nur auf Preisprüfung). Rückgabe: ['id'=>..] oder ['fehler'=>..].
function nachbestellung_anlegen(int $kunde_id, int $angebot_id, int $menge): array {
    nachbestellung_schema();
    $q = nachbestellung_quelle($angebot_id, $kunde_id);
    if (!$q)            return ['fehler' => 'Nachbestellung nicht möglich – Vorbestellung nicht gefunden.'];
    if ($menge <= 0)    return ['fehler' => 'Bitte eine Menge größer 0 angeben.'];
    $cu = function_exists('current_user') ? current_user() : null;
    q("INSERT INTO nachbestellung (kunde_id,quelle_angebot_id,quelle_auftrag_id,produkt_id,rezeptur_id,stueck,menge,vk_stueck,status,angelegt,angelegt_von)
       VALUES (?,?,?,?,?,?,?,?,'pruefung',?,?)",
      [$kunde_id, $angebot_id, $q['auftrag_id'], $q['produkt_id'], $q['rezeptur_id'], $q['stueck'], $menge, $q['vk_stueck'],
       gmdate('Y-m-d H:i:s'), ($cu['name'] ?? 'Kunde')]);
    $id = (int) insert_id();
    $firma = (string) scalar("SELECT firma FROM kunden WHERE id=?", [$kunde_id]);
    if (function_exists('benachrichtigung_add'))
        benachrichtigung_add('Nachbestellung: ' . ($firma ?: ('Kunde #' . $kunde_id)) . ' · ' . number_format($menge, 0, ',', '.') . ' Pkg',
            'Zum zuletzt vereinbarten Preis – bitte Preis bestätigen & freigeben.', '?p=nachbestellungen');
    if (function_exists('log_aktivitaet'))
        log_aktivitaet('kunde', $kunde_id, 'kunde', 'Nachbestellung angelegt: ' . number_format($menge, 0, ',', '.') . ' Pkg (zum zuletzt vereinbarten Preis, wartet auf Preisprüfung).');
    return ['id' => $id];
}

// Intern: aus der Nachbestellung den echten Auftrag erzeugen (minimales bestätigtes Angebot -> auftrag_aus_angebot),
// Etikett-Freigabe aus dem Vorauftrag übernehmen. Rückgabe Auftrags-ID oder 0.
function nachbestellung_order_erstellen(array $nb, float $vk): int {
    $kundeId = (int)$nb['kunde_id'];
    $menge   = (int)$nb['menge'];
    $stueck  = (int)($nb['stueck'] ?? 0);
    $pid     = (int)($nb['produkt_id'] ?? 0);
    if ($menge <= 0 || $vk < 0) return 0;
    $netto = round($menge * $vk, 2);
    q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,notiz) VALUES (?,?,?,?,?)",
      [naechste_nummer('AN'), $kundeId, ($pid ?: null), 'bestaetigt', 'Nachbestellung #' . (int)$nb['id'] . ' – Preis bestätigt, Konfiguration aus Vorauftrag übernommen.']);
    $angId = (int) insert_id();
    q("INSERT INTO angebot_staffel (angebot_id,menge,stueck,vk_stueck,bestaetigt,sort) VALUES (?,?,?,?,1,0)",
      [$angId, $menge, $stueck, $vk]);
    $aid = (int) (auftrag_aus_angebot($angId) ?? 0);
    if ($aid <= 0) return 0;
    // Etikett-Freigabe aus dem Vorauftrag übernehmen (kein erneuter Upload / keine erneute Freigabe nötig).
    $qAuf = (int)($nb['quelle_auftrag_id'] ?? 0);
    if ($qAuf > 0 && function_exists('etikett_freigegeben') && etikett_freigegeben($qAuf)) {
        $src = one("SELECT etikett_freigabe_von, etikett_freigabe_am FROM auftrag WHERE id=?", [$qAuf]);
        q("UPDATE auftrag SET etikett_freigegeben=1, etikett_freigabe_am=COALESCE(?,UTC_TIMESTAMP()), etikett_freigabe_von=? WHERE id=?",
          [$src['etikett_freigabe_am'] ?? null,
           trim((string)($src['etikett_freigabe_von'] ?? '')) !== '' ? ($src['etikett_freigabe_von'] . ' (Nachbestellung übernommen)') : 'Nachbestellung – Freigabe übernommen',
           $aid]);
    }
    return $aid;
}

// bulkify (Team) bestätigt den Preis und gibt frei -> Auftrag entsteht. Rückgabe: ['auftrag_id'=>..] oder ['fehler'=>..].
function nachbestellung_bestaetigen(int $id): array {
    nachbestellung_schema();
    $nb = $id ? one("SELECT * FROM nachbestellung WHERE id=?", [$id]) : null;
    if (!$nb)                                  return ['fehler' => 'Nachbestellung nicht gefunden.'];
    if (!in_array((string)$nb['status'], ['pruefung','preis_neu'], true)) return ['fehler' => 'Schon bearbeitet.'];
    $vk  = (float)$nb['vk_stueck'];
    $aid = nachbestellung_order_erstellen($nb, $vk);
    if ($aid <= 0)                             return ['fehler' => 'Auftrag konnte nicht erzeugt werden.'];
    q("UPDATE nachbestellung SET status='bestaetigt', angebot_id=(SELECT angebot_id FROM auftrag WHERE id=?), auftrag_id=?, bearbeitet=? WHERE id=?",
      [$aid, $aid, gmdate('Y-m-d H:i:s'), $id]);
    if (function_exists('log_aktivitaet'))
        log_aktivitaet('kunde', (int)$nb['kunde_id'], 'team', 'Nachbestellung bestätigt & freigegeben – Auftrag erzeugt.', 'auftrag', 'auftrag', $aid);
    return ['auftrag_id' => $aid];
}

// Ausnahmefall (2%): Preis weicht ab -> zurück an den Kunden zur kurzen Freigabe des neuen Preises.
function nachbestellung_preis_anpassen(int $id, float $vk_neu, string $grund = ''): array {
    nachbestellung_schema();
    $nb = $id ? one("SELECT * FROM nachbestellung WHERE id=?", [$id]) : null;
    if (!$nb)                              return ['fehler' => 'Nachbestellung nicht gefunden.'];
    if ((string)$nb['status'] !== 'pruefung') return ['fehler' => 'Nicht mehr in Prüfung.'];
    if ($vk_neu < 0)                       return ['fehler' => 'Ungültiger Preis.'];
    q("UPDATE nachbestellung SET status='preis_neu', vk_neu=?, grund=?, bearbeitet=? WHERE id=?",
      [$vk_neu, ($grund !== '' ? mb_substr($grund, 0, 500) : null), gmdate('Y-m-d H:i:s'), $id]);
    if (function_exists('log_aktivitaet'))
        log_aktivitaet('kunde', (int)$nb['kunde_id'], 'team',
            'Nachbestellung: neuer Preis ' . number_format($vk_neu, 2, ',', '.') . ' €/Pkg – wartet auf Freigabe durch den Kunden.');
    return ['ok' => true];
}

// Kunde gibt den neuen Preis frei -> Auftrag entsteht zum neuen Preis. Rückgabe: ['auftrag_id'=>..] oder ['fehler'=>..].
function nachbestellung_kunde_preis_ok(int $id, int $kunde_id): array {
    nachbestellung_schema();
    $nb = $id ? one("SELECT * FROM nachbestellung WHERE id=? AND kunde_id=?", [$id, $kunde_id]) : null;
    if (!$nb)                               return ['fehler' => 'Nachbestellung nicht gefunden.'];
    if ((string)$nb['status'] !== 'preis_neu' || $nb['vk_neu'] === null) return ['fehler' => 'Kein neuer Preis zur Freigabe.'];
    $aid = nachbestellung_order_erstellen($nb, (float)$nb['vk_neu']);
    if ($aid <= 0)                          return ['fehler' => 'Auftrag konnte nicht erzeugt werden.'];
    q("UPDATE nachbestellung SET status='bestaetigt', vk_stueck=vk_neu, angebot_id=(SELECT angebot_id FROM auftrag WHERE id=?), auftrag_id=?, bearbeitet=? WHERE id=?",
      [$aid, $aid, gmdate('Y-m-d H:i:s'), $id]);
    if (function_exists('log_aktivitaet'))
        log_aktivitaet('kunde', (int)$nb['kunde_id'], 'kunde', 'Neuer Preis freigegeben – Nachbestellung läuft, Auftrag erzeugt.', 'auftrag', 'auftrag', $aid);
    return ['auftrag_id' => $aid];
}

// Offene (zu prüfende) Nachbestellungen – für Badge/Übersicht.
function nachbestellung_offen_count(): int {
    nachbestellung_schema();
    return (int) scalar("SELECT COUNT(*) FROM nachbestellung WHERE status IN ('pruefung','preis_neu')");
}

// Offene Nachbestellung eines Kunden zu einem Quell-Angebot (für die Portal-Anzeige). Null, wenn keine offen.
function nachbestellung_offen_fuer(int $kunde_id, int $angebot_id): ?array {
    nachbestellung_schema();
    return one("SELECT * FROM nachbestellung WHERE kunde_id=? AND quelle_angebot_id=? AND status IN ('pruefung','preis_neu') ORDER BY id DESC LIMIT 1",
               [$kunde_id, $angebot_id]) ?: null;
}
