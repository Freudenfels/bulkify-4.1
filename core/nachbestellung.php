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

// Alle je angebotenen Mengenstaffeln dieses Kunden für dieses Produkt (gesendete + bestätigte Angebote),
// auf die Packungsgröße (stueck) eingeschränkt, wenn bekannt. Rückgabe: Liste [['menge'=>int,'vk'=>float], …] aufsteigend.
// Quellen (S-19): (1) Matrix-/Staffel-Angebote (angebot_staffel), (2) ORIGINAL-Positionsangebote über angebot_optionen() – dort steht
// je Option der vollständige Preis je Packung (Herstellung + Glas + Deckel + Etikett) bei der angebotenen Menge. Die kleinen
// NACHBESTELL-Angebote (notiz „Nachbestellung #…") zählen NICHT: sie sind Folge, nicht Quelle der Staffel.
function nachbestellung_staffel(int $kunde_id, int $produkt_id, int $stueck): array {
    if ($kunde_id <= 0 || $produkt_id <= 0) return [];
    $rows = all("SELECT s.menge, s.vk_stueck, s.stueck
                 FROM angebot_staffel s JOIN angebot a ON a.id=s.angebot_id
                 WHERE a.kunde_id=? AND a.produkt_id=? AND a.status IN ('gesendet','bestaetigt') AND s.vk_stueck>0 AND s.menge>0
                   AND COALESCE(a.notiz,'') NOT LIKE 'Nachbestellung #%'",
                [$kunde_id, $produkt_id]);
    if ($stueck > 0) { $g = array_values(array_filter($rows, fn($r) => (int)$r['stueck'] === $stueck)); if ($g) $rows = $g; }
    $out = array_map(fn($r) => ['menge' => (int)$r['menge'], 'vk' => (float)$r['vk_stueck']], $rows);
    // Positionsangebote dieses Kunden, aus denen das Produkt entstanden ist (angebot_produkt) – Preis je Option.
    if (function_exists('angebot_optionen')) {
        $opt = [];
        foreach (all("SELECT DISTINCT ap.angebot_id FROM angebot_produkt ap JOIN angebot a ON a.id=ap.angebot_id
                      WHERE a.kunde_id=? AND ap.produkt_id=? AND a.status IN ('gesendet','bestaetigt')
                        AND COALESCE(a.notiz,'') NOT LIKE 'Nachbestellung #%'
                        AND EXISTS (SELECT 1 FROM angebot_position p WHERE p.angebot_id=a.id)", [$kunde_id, $produkt_id]) as $ang) {
            foreach ((array)(angebot_optionen((int)$ang['angebot_id'])['optionen'] ?? []) as $o) {
                if (empty($o['waehlbar']) || (float)$o['pakete'] <= 0 || (float)$o['pro_pkg'] <= 0) continue;
                if ($stueck > 0 && (int)$o['stueck'] !== $stueck) continue;
                $opt[] = ['menge' => (int) round((float)$o['pakete']), 'vk' => round((float)$o['pro_pkg'], 4)];
            }
        }
        if ($opt) $out = array_merge($out, $opt);
    }
    usort($out, fn($a, $b) => $a['menge'] <=> $b['menge']);
    return $out;
}

// Mengenabhängiger Preis aus den früheren Angeboten (nur Preise, die der Kunde schon gesehen hat – nie erfunden).
// Regel (Entscheidung Nico): echte Staffel in BEIDE Richtungen (kleinere Menge kann teurer sein). Liegt die Menge
// ÜBER jeder je angebotenen Staffel, gilt der beste angebotene Preis + Kennzeichnung „prüfen". Rückgabe:
// ['vk'=>float, 'quelle'=>'staffel'|'maximum'|'letzter', 'ueber_max'=>bool, 'hinweis'=>string].
function nachbestellung_preis_fuer(int $kunde_id, int $produkt_id, int $stueck, int $menge, float $fallback_vk): array {
    $st = nachbestellung_staffel($kunde_id, $produkt_id, $stueck);
    if (!$st || $menge <= 0) return ['vk' => $fallback_vk, 'quelle' => 'letzter', 'ueber_max' => false, 'hinweis' => ''];
    $maxMenge = $st[count($st) - 1]['menge'];
    $bestVk   = min(array_map(fn($r) => $r['vk'], $st));   // bester (niedrigster) je angebotener Preis
    if ($menge > $maxMenge) {
        return ['vk' => $bestVk, 'quelle' => 'maximum', 'ueber_max' => true,
                'hinweis' => 'Menge über bisher angebotene Staffel (' . number_format($maxMenge, 0, ',', '.') . ' Pkg) – Preis bitte prüfen.'];
    }
    // größte Staffel mit menge <= Bestellmenge = der Tarif, in den die Menge fällt; sonst kleinste (teuerste) Staffel.
    $tierVk = null;
    foreach ($st as $r) if ($r['menge'] <= $menge) $tierVk = $r['vk'];
    if ($tierVk === null) $tierVk = $st[0]['vk'];
    return ['vk' => $tierVk, 'quelle' => 'staffel', 'ueber_max' => false, 'hinweis' => ''];
}

// Preis-Fallback (09.11.2026): Hat der Vorauftrag keinen Preis (z. B. importierte Altaufträge mit 0 €), gilt der zentrale
// Kundenpreis (?p=kundenpreise: Herstellung je Pkg, passende Packungsgröße, Staffel nach Bestellmenge) plus Verpackung,
// Deckel und Etikett bei dieser Menge – dieselbe Rechnung wie die Kundenpreis-Liste. Kein Kundenpreis = 0 („Preis folgt").
function nachbestellung_kundenpreis_vk(int $kunde_id, int $produkt_id, int $stueck, int $menge, ?int $verpackung_id = null): float {
    return (float)(nachbestellung_kundenpreis_teile($kunde_id, $produkt_id, $stueck, $menge, $verpackung_id)['gesamt'] ?? 0);
}
// Kundenpreis aufgeteilt: ['herstellung'=>€, 'verpackung'=>€, 'gesamt'=>€, 'staffel'=>Bestellmenge der Kundenpreis-Zeile] oder [].
function nachbestellung_kundenpreis_teile(int $kunde_id, int $produkt_id, int $stueck, int $menge, ?int $verpackung_id = null): array {
    if ($kunde_id <= 0 || $produkt_id <= 0 || !table_exists('produkt_kundenpreis')) return [];
    $rows = all("SELECT COALESCE(menge_pro_vpe,0) AS stk, COALESCE(anzahl_vpe,0) AS bm, preis FROM produkt_kundenpreis
                 WHERE produkt_id=? AND kunde_id=? AND preis>0", [$produkt_id, $kunde_id]);
    if ($stueck > 0) $rows = array_values(array_filter($rows, fn($r) => (int)$r['stk'] === $stueck || (int)$r['stk'] === 0));
    if (!$rows) return [];
    $preis = null; $best = -1;
    foreach ($rows as $r) { $bm = (int)$r['bm']; if ($bm > 0 && $bm <= $menge && $bm > $best) { $best = $bm; $preis = (float)$r['preis']; } }
    if ($preis === null) foreach ($rows as $r) if ((int)$r['bm'] === 0) { $preis = (float)$r['preis']; $best = 0; break; }
    if ($preis === null) { usort($rows, fn($a, $b) => (int)$a['bm'] <=> (int)$b['bm']); $preis = (float)$rows[0]['preis']; $best = (int)$rows[0]['bm']; }
    if ($preis <= 0) return [];
    $verp = function_exists('verpackung_cent_je_pack') ? verpackung_cent_je_pack($produkt_id, max(1, $menge), $kunde_id, $verpackung_id ?: null) / 100 : 0.0;
    return ['herstellung' => round($preis, 4), 'verpackung' => round($verp, 4), 'gesamt' => round($preis + $verp, 4), 'staffel' => max(0, $best)];
}

// Entscheidungsgrundlage für die Preisprüfung (Team, Nico 09.11.2026): was genau nachbestellt wird (Produkt, Packungsgröße,
// Behälter/Deckel/Etikett des Vorauftrags) und woher ein Preis kommen kann – Angebotsstaffel, Vorauftrag, letzte Rechnung,
// Kundenpreis, EK. Nur lesen, keine Änderung.
function nachbestellung_entscheidung(array $nb): array {
    $kid = (int)$nb['kunde_id']; $pid = (int)($nb['produkt_id'] ?? 0); $stk = (int)($nb['stueck'] ?? 0); $menge = (int)$nb['menge'];
    $qa = (int)($nb['quelle_auftrag_id'] ?? 0)
        ? one("SELECT id, nummer, angelegt, menge, stueck, vk_stueck, gesamt_netto, verpackung_id, status, etikett_freigegeben FROM auftrag WHERE id=?", [(int)$nb['quelle_auftrag_id']])
        : null;
    $verpId = (int)($qa['verpackung_id'] ?? 0) ?: null;
    $items = ($pid > 0 && function_exists('produkt_verpackung_items')) ? produkt_verpackung_items($pid, $verpId) : [];
    // Frühere Aufträge dieses Kunden für dieses Produkt, je mit Preis/Pkg und dem, was dafür berechnet wurde (Rechnungen ohne Storno).
    $hist = [];
    if ($pid > 0) {
        foreach (all("SELECT a.id, a.nummer, a.angelegt, a.menge, a.stueck, a.vk_stueck, a.gesamt_netto, a.status, a.verpackung_id, i.name AS verp_name
                      FROM auftrag a LEFT JOIN item i ON i.id=a.verpackung_id
                      WHERE a.kunde_id=? AND a.produkt_id=? AND a.status<>'storniert' ORDER BY a.id DESC LIMIT 6", [$kid, $pid]) as $a) {
            $re = one("SELECT GROUP_CONCAT(nummer ORDER BY id SEPARATOR ', ') AS nr, COALESCE(SUM(netto),0) AS netto
                       FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert'", [(int)$a['id']]) ?: [];
            $reNetto = (float)($re['netto'] ?? 0);
            $hist[] = $a + ['preis' => auftrag_vk_gesamt($a), 're_nr' => (string)($re['nr'] ?? ''),
                            're_preis' => ($reNetto > 0 && (int)$a['menge'] > 0) ? round($reNetto / (int)$a['menge'], 4) : 0.0];
        }
    }
    $letzteRe = null;
    foreach ($hist as $h) if ($h['re_preis'] > 0) { $letzteRe = $h; break; }
    // EK je Packung wie in der Preismatrix: Füllung je Form (Pulver nach Gramm usw., Staffel = Bestellmenge) + Behälter/Deckel/Etikett.
    $ekF = ($pid > 0 && $stk > 0 && function_exists('produkt_variante_ek')) ? round(produkt_variante_ek($pid, $stk, (int)$verpId, $menge), 4) : 0.0;
    $ekV = 0.0;
    if (function_exists('pack_ek_bei_menge')) foreach ($items as $it) $ekV += pack_ek_bei_menge((int)$it['id'], max(1, $menge));
    $ekV = round($ekV, 4);
    return [
        'quelle'      => $qa,
        'quelle_preis'=> $qa ? auftrag_vk_gesamt($qa) : 0.0,
        'items'       => $items,
        'staffel'     => $pid > 0 ? nachbestellung_staffel($kid, $pid, $stk) : [],
        'historie'    => $hist,
        'letzte_re'   => $letzteRe,
        'kundenpreis' => nachbestellung_kundenpreis_teile($kid, $pid, $stk, $menge, $verpId),
        'ek_fuellung' => $ekF,
        'ek_verpackung' => $ekV,
        'ek'          => $ekF > 0 ? round($ekF + $ekV, 4) : 0.0,   // ohne Füllungs-EK kein EK (sonst wäre nur die Verpackung „EK")
    ];
}

// Offene Nachbestellungen eines Kunden (Portal „Bestellungen": eingegangen, aber noch kein Auftrag).
function nachbestellung_offen_kunde(int $kunde_id): array {
    nachbestellung_schema();
    return all("SELECT * FROM nachbestellung WHERE kunde_id=? AND status IN ('pruefung','preis_neu') ORDER BY id DESC", [$kunde_id]);
}

// Kunde legt eine Nachbestellung an (verbindlich, wartet nur auf Preisprüfung). Rückgabe: ['id'=>..] oder ['fehler'=>..].
function nachbestellung_anlegen(int $kunde_id, int $angebot_id, int $menge): array {
    nachbestellung_schema();
    $q = nachbestellung_quelle($angebot_id, $kunde_id);
    if (!$q)            return ['fehler' => 'Nachbestellung nicht möglich – Vorbestellung nicht gefunden.'];
    if ($menge <= 0)    return ['fehler' => 'Bitte eine Menge größer 0 angeben.'];
    // Preis nach Menge aus den früheren Angeboten (Fallback: zuletzt vereinbarter Preis).
    $preis = nachbestellung_preis_fuer($kunde_id, (int)$q['produkt_id'], (int)$q['stueck'], $menge, (float)$q['vk_stueck']);
    if ((float)$preis['vk'] <= 0) $preis['vk'] = nachbestellung_kundenpreis_vk($kunde_id, (int)$q['produkt_id'], (int)$q['stueck'], $menge);
    if ((float)$preis['vk'] <= 0 && $preis['hinweis'] === '') $preis['hinweis'] = 'Kein Preis hinterlegt – bitte Preis anpassen.';
    $cu = function_exists('current_user') ? current_user() : null;
    q("INSERT INTO nachbestellung (kunde_id,quelle_angebot_id,quelle_auftrag_id,produkt_id,rezeptur_id,stueck,menge,vk_stueck,grund,status,angelegt,angelegt_von)
       VALUES (?,?,?,?,?,?,?,?,?,'pruefung',?,?)",
      [$kunde_id, $angebot_id, $q['auftrag_id'], $q['produkt_id'], $q['rezeptur_id'], $q['stueck'], $menge, $preis['vk'],
       ($preis['hinweis'] !== '' ? $preis['hinweis'] : null), gmdate('Y-m-d H:i:s'), ($cu['name'] ?? 'Kunde')]);
    $id = (int) insert_id();
    $firma = (string) scalar("SELECT firma FROM kunden WHERE id=?", [$kunde_id]);
    if (function_exists('benachrichtigung_add'))
        benachrichtigung_add('Nachbestellung: ' . ($firma ?: ('Kunde #' . $kunde_id)) . ' · ' . number_format($menge, 0, ',', '.') . ' Pkg',
            'Zum zuletzt vereinbarten Preis – bitte Preis bestätigen & freigeben.', '?p=nachbestellungen');
    if (function_exists('log_aktivitaet'))
        log_aktivitaet('kunde', $kunde_id, 'kunde', 'Nachbestellung angelegt: ' . number_format($menge, 0, ',', '.') . ' Pkg (zum zuletzt vereinbarten Preis, wartet auf Preisprüfung).');
    if (function_exists('mail_nachbestellung') && mail_bereit()) nach_antwort(fn() => mail_nachbestellung($id, 'eingang'));   // Kunde + Team
    return ['id' => $id];
}

// Quelle DIREKT aus einem bestehenden Auftrag (robust – funktioniert auch für importierte/gebündelte Aufträge
// ohne klassisches bestätigtes Angebot mit Staffel). Nimmt Produkt/Packungsgröße/Menge/Preis vom Auftrag.
function nachbestellung_quelle_auftrag(int $auftrag_id, int $kunde_id): ?array {
    if ($auftrag_id <= 0 || $kunde_id <= 0) return null;
    $a = one("SELECT id, angebot_id, produkt_id, menge, stueck, vk_stueck, gesamt_netto, verpackung_id
              FROM auftrag WHERE id=? AND kunde_id=? AND produkt_id IS NOT NULL AND COALESCE(kategorie,'produkt')<>'dienstleistung'
                AND status<>'storniert'", [$auftrag_id, $kunde_id]);
    if (!$a) return null;
    $rezId = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
    return [
        'angebot_id' => (int)($a['angebot_id'] ?? 0) ?: null,
        'auftrag_id' => (int)$a['id'],
        'produkt_id' => (int)$a['produkt_id'],
        'rezeptur_id'=> $rezId ?: null,
        'stueck'     => (int)($a['stueck'] ?? 0),
        'menge'      => (int)($a['menge'] ?? 0),
        'verpackung_id' => (int)($a['verpackung_id'] ?? 0) ?: null,
        'vk_stueck'  => auftrag_vk_gesamt($a),   // GESAMTpreis je Packung (inkl. Glas/Deckel/Etikett), nicht nur die Herstellung (S-19)
    ];
}
// Nachbestellung aus einem Auftrag anlegen (gleiche Verpackung/Etikett, Preis aus Staffel oder zuletzt vom Auftrag).
function nachbestellung_anlegen_auftrag(int $kunde_id, int $auftrag_id, int $menge): array {
    nachbestellung_schema();
    $q = nachbestellung_quelle_auftrag($auftrag_id, $kunde_id);
    if (!$q)         return ['fehler' => 'Nachbestellung nicht möglich – Bestellung nicht gefunden.'];
    if ($menge <= 0) return ['fehler' => 'Bitte eine Menge größer 0 angeben.'];
    if ($menge > 100000000) return ['fehler' => 'Die Menge ist zu groß – bitte prüfen Sie Ihre Eingabe.'];   // INT-Spalte nicht sprengen
    // Doppelklick-/Doppelbestellschutz: für dasselbe Produkt+Packungsgröße läuft schon eine offene Nachbestellung.
    if (scalar("SELECT id FROM nachbestellung WHERE kunde_id=? AND produkt_id=? AND stueck=? AND status IN ('pruefung','preis_neu') LIMIT 1",
               [$kunde_id, (int)$q['produkt_id'], (int)$q['stueck']]))
        return ['fehler' => 'Für dieses Produkt ist bereits eine Nachbestellung eingegangen – wir bestätigen den Preis kurz.'];
    $preis = nachbestellung_preis_fuer($kunde_id, (int)$q['produkt_id'], (int)$q['stueck'], $menge, (float)$q['vk_stueck']);
    $vk = (float)($preis['vk'] ?? 0); if ($vk <= 0) $vk = (float)$q['vk_stueck'];
    if ($vk <= 0) $vk = nachbestellung_kundenpreis_vk($kunde_id, (int)$q['produkt_id'], (int)$q['stueck'], $menge, $q['verpackung_id']);   // Altauftrag ohne Preis
    if ($vk <= 0 && $preis['hinweis'] === '') $preis['hinweis'] = 'Kein Preis hinterlegt – bitte Preis anpassen.';
    $cu = function_exists('current_user') ? current_user() : null;
    q("INSERT INTO nachbestellung (kunde_id,quelle_angebot_id,quelle_auftrag_id,produkt_id,rezeptur_id,stueck,menge,vk_stueck,grund,status,angelegt,angelegt_von)
       VALUES (?,?,?,?,?,?,?,?,?,'pruefung',?,?)",
      [$kunde_id, $q['angebot_id'], $q['auftrag_id'], $q['produkt_id'], $q['rezeptur_id'], $q['stueck'], $menge, $vk,
       ($preis['hinweis'] !== '' ? $preis['hinweis'] : null), gmdate('Y-m-d H:i:s'), ($cu['name'] ?? 'Kunde')]);
    $id = (int) insert_id();
    $firma = (string) scalar("SELECT firma FROM kunden WHERE id=?", [$kunde_id]);
    if (function_exists('benachrichtigung_add'))
        benachrichtigung_add('Nachbestellung: ' . ($firma ?: ('Kunde #' . $kunde_id)) . ' · ' . number_format($menge, 0, ',', '.') . ' Pkg',
            'Zum zuletzt vereinbarten Preis – bitte Preis bestätigen & freigeben.', '?p=nachbestellungen');
    if (function_exists('log_aktivitaet'))
        log_aktivitaet('kunde', $kunde_id, 'kunde', 'Nachbestellung angelegt (aus Auftrag #' . $auftrag_id . '): ' . number_format($menge, 0, ',', '.') . ' Pkg.');
    if (function_exists('mail_nachbestellung') && mail_bereit()) nach_antwort(fn() => mail_nachbestellung($id, 'eingang'));   // Kunde + Team
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
    // GLEICHE Verpackung wie im Vorauftrag: auftrag_aus_angebot() nimmt sonst nur die Produkt-Standardverpackung
    // (das neue Minimal-Angebot hat keine Positionen) – eine kundenspezifische/geänderte Verpackung ginge verloren.
    $qAuf = (int)($nb['quelle_auftrag_id'] ?? 0);
    if ($qAuf > 0) {
        $srcVp = (int) scalar("SELECT COALESCE(verpackung_id,0) FROM auftrag WHERE id=?", [$qAuf]);
        if ($srcVp > 0) {
            q("UPDATE auftrag SET verpackung_id=? WHERE id=?", [$srcVp, $aid]);
            if (function_exists('bedarf_bump')) bedarf_bump();   // Material-/Bedarf-Cache neu rechnen (Behälter kann abweichen)
        }
    }
    // Etikett-Freigabe aus dem Vorauftrag übernehmen (kein erneuter Upload / keine erneute Freigabe nötig).
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
    // Ohne Preis kein Auftrag (sonst Auftrag + Rechnung über 0 €). Erst Preis anpassen oder Kundenpreis eintragen.
    if ($vk <= 0) return ['fehler' => 'Kein Preis – bitte zuerst den Kundenpreis übernehmen oder „Preis anpassen".'];
    $aid = nachbestellung_order_erstellen($nb, $vk);
    if ($aid <= 0)                             return ['fehler' => 'Auftrag konnte nicht erzeugt werden.'];
    q("UPDATE nachbestellung SET status='bestaetigt', angebot_id=(SELECT angebot_id FROM auftrag WHERE id=?), auftrag_id=?, bearbeitet=? WHERE id=?",
      [$aid, $aid, gmdate('Y-m-d H:i:s'), $id]);
    if (function_exists('log_aktivitaet'))
        log_aktivitaet('kunde', (int)$nb['kunde_id'], 'team', 'Nachbestellung bestätigt & freigegeben – Auftrag erzeugt.', 'auftrag', 'auftrag', $aid);
    if (function_exists('mail_nachbestellung') && mail_bereit()) nach_antwort(fn() => mail_nachbestellung($id, 'bestaetigt'));   // Kunde: AB
    return ['auftrag_id' => $aid];
}

// Nachbestellung ohne Preis: zentralen Kundenpreis übernehmen und freigeben (Team, 09.11.2026).
function nachbestellung_kundenpreis_uebernehmen(int $id): array {
    nachbestellung_schema();
    $nb = $id ? one("SELECT * FROM nachbestellung WHERE id=?", [$id]) : null;
    if (!$nb || (string)$nb['status'] !== 'pruefung') return ['fehler' => 'Nachbestellung nicht mehr in Prüfung.'];
    $verp = (int)($nb['quelle_auftrag_id'] ?? 0) ? (int) scalar("SELECT COALESCE(verpackung_id,0) FROM auftrag WHERE id=?", [(int)$nb['quelle_auftrag_id']]) : 0;
    $vk = nachbestellung_kundenpreis_vk((int)$nb['kunde_id'], (int)$nb['produkt_id'], (int)$nb['stueck'], (int)$nb['menge'], $verp ?: null);
    if ($vk <= 0) return ['fehler' => 'Für diesen Kunden und dieses Produkt ist kein Kundenpreis hinterlegt.'];
    q("UPDATE nachbestellung SET vk_stueck=?, grund=NULL WHERE id=?", [$vk, $id]);
    return nachbestellung_bestaetigen($id);
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
    if (function_exists('mail_nachbestellung') && mail_bereit()) nach_antwort(fn() => mail_nachbestellung($id, 'preis_neu'));   // Kunde: neuen Preis freigeben
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
    if (function_exists('mail_nachbestellung') && mail_bereit()) nach_antwort(fn() => mail_nachbestellung($id, 'bestaetigt'));   // Kunde: AB
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
