<?php
// Rechnungspositionen aus dem Angebot übernehmen (aufgeschlüsselt). Liest das verknüpfte Angebot über die
// Naht (erp_auftrag/erp_angebot_positionen), findet die passende Konfigurations-Gruppe (deren Packungspreis
// × Auftragsmenge = Auftrags-Netto) und schreibt sie als beleg_position. Ändert NICHT die Kopfsummen des
// Belegs (GoBD) – die Positionen sind die Aufschlüsselung des bestehenden Betrags; Abweichungen zeigt die
// Detailseite als Hinweis. Mirror der Dashboard-Logik beleg_positionen_aus_auftrag(), aber schreibend.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/erp.php';

// Rückgabe: ['ok'=>bool, 'anzahl'=>int, 'grund'=>string]
function beleg_positionen_aus_angebot(int $beleg_id): array {
    $b = one("SELECT id, typ, status, auftrag_id, netto, ust_prozent FROM beleg WHERE id=?", [$beleg_id]);
    if (!$b)                              return ['ok' => false, 'anzahl' => 0, 'grund' => 'Beleg nicht gefunden.'];
    if ($b['typ'] !== 'rechnung')         return ['ok' => false, 'anzahl' => 0, 'grund' => 'Nur für Rechnungen möglich.'];
    if ($b['status'] === 'storniert')     return ['ok' => false, 'anzahl' => 0, 'grund' => 'Rechnung ist storniert.'];
    if (empty($b['auftrag_id']))          return ['ok' => false, 'anzahl' => 0, 'grund' => 'Die Rechnung ist mit keinem Auftrag verknüpft (freie Rechnung) – bitte Positionen manuell erfassen.'];

    $auf = erp_auftrag((int)$b['auftrag_id']);
    if (!$auf)                            return ['ok' => false, 'anzahl' => 0, 'grund' => 'Auftrag nicht gefunden.'];

    $menge = max(1, (int)($auf['menge'] ?? 0));
    $zielCent = (int) round((float)($auf['gesamt_netto'] ?? 0) * 100);
    if ($zielCent <= 0) $zielCent = (int) round((float)$b['netto'] * 100);   // Fallback: Beleg-Netto
    $ustSatz = (float) $b['ust_prozent'];

    // 1) Bevorzugt: aufgeschlüsselt aus dem Angebot (wenn ein Angebot mit echten Positionen hängt).
    //    Nach Konfigurations-Gruppe (A/B/C …; leer = einzige) bündeln und die zum Betrag passende Gruppe wählen.
    $gewaehlt = null;
    if (!empty($auf['angebot_id'])) {
        $pos = erp_angebot_positionen((int)$auf['angebot_id']);
        if ($pos) {
            $grp = [];
            foreach ($pos as $p) $grp[trim((string)($p['gruppe'] ?? ''))][] = $p;
            foreach ($grp as $rows) {
                $sumPack = 0; foreach ($rows as $r) $sumPack += (int)$r['preis_cent'];
                if ($sumPack * $menge === $zielCent) { $gewaehlt = $rows; break; }
            }
            if ($gewaehlt === null && count($grp) === 1) $gewaehlt = reset($grp);   // nur eine Konfiguration -> die nehmen
        }
    }

    q("DELETE FROM beleg_position WHERE beleg_id=?", [$beleg_id]);
    $sort = 0;
    if ($gewaehlt !== null) {
        // Positionen ersetzen (Aufschlüsselung des bestehenden Betrags; Kopfsummen bleiben).
        foreach ($gewaehlt as $r) {
            $bez = preg_replace('/^[A-Z]\)\s*/', '', (string)$r['bezeichnung']);   // Gruppen-Buchstabe raus
            q("INSERT INTO beleg_position (beleg_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,mwst_satz)
               VALUES (?,?,?,?,?,?,?,?,?)",
              [$beleg_id, $sort++, trim((string)($r['artikelnr'] ?? '')) ?: null, $bez,
               trim((string)($r['beschreibung'] ?? '')) ?: null, $menge, ($r['einheit'] ?: 'Stk.'),
               (int)$r['preis_cent'], $ustSatz]);
        }
        return ['ok' => true, 'anzahl' => $sort, 'grund' => ''];
    }

    // 2) Keine Angebots-Aufschlüsselung (z. B. Jahresvertrag-Abruf ohne Angebot, oder nur Staffelpreis):
    //    eine Sammelposition aus dem Auftrag – Produktname, Auftragsmenge, Netto/Menge als Einzelpreis
    //    (reproduziert das Rechnungs-Netto; Kopfsummen bleiben). Spiegelt den Fallback des Dashboard-Materializers.
    $bez   = trim((string)($auf['produkt_name'] ?? '')) ?: 'Produkt';
    $rezId = erp_auftrag_rezeptur_id((int)$auf['id']);
    $rezNr = $rezId ? (string)(erp_rezeptur($rezId)['nummer'] ?? '') : '';
    $einzelCent = (int) round($menge > 0 ? $zielCent / $menge : $zielCent);
    // Beschreibung macht den Festpreis je Packung transparent (Verpackung + Etikett sind im Packungspreis enthalten).
    $teile = [];
    $stk = (int)($auf['stueck'] ?? 0);
    if ($stk > 0) $teile[] = $stk . ' Stück je Packung';
    $teile[] = 'fertig abgefüllt & etikettiert';
    q("INSERT INTO beleg_position (beleg_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,mwst_satz)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [$beleg_id, 0, $rezNr ?: null, $bez, implode(' · ', $teile), $menge, 'Pkg.', $einzelCent, $ustSatz]);
    return ['ok' => true, 'anzahl' => 1, 'grund' => ''];
}

// Positionen manuell setzen (ersetzt alle). $zeilen: [['artikelnr','bezeichnung','beschreibung','menge','einheit','preis'(€),'ust'], …].
// Kopfsummen bleiben unberührt; leere Zeilen werden übersprungen. Rückgabe Anzahl geschriebener Positionen.
function beleg_positionen_manuell_setzen(int $beleg_id, array $zeilen): int {
    $b = one("SELECT typ, status FROM beleg WHERE id=?", [$beleg_id]);
    if (!$b || $b['status'] === 'storniert') return 0;
    $clean = [];
    foreach ($zeilen as $z) {
        $bez = trim((string)($z['bezeichnung'] ?? ''));
        if ($bez === '') continue;
        $menge = (float) str_replace(',', '.', (string)($z['menge'] ?? '1')); if ($menge <= 0) $menge = 1;
        $preis = (float) str_replace(',', '.', (string)($z['preis'] ?? '0'));
        $ust   = (float) str_replace(',', '.', (string)($z['ust'] ?? '0'));
        $clean[] = [trim((string)($z['artikelnr'] ?? '')) ?: null, $bez, trim((string)($z['beschreibung'] ?? '')) ?: null,
                    $menge, trim((string)($z['einheit'] ?? '')) ?: null, (int) round($preis * 100), $ust];
    }
    q("DELETE FROM beleg_position WHERE beleg_id=?", [$beleg_id]);
    $sort = 0;
    foreach ($clean as $c) {
        q("INSERT INTO beleg_position (beleg_id,sort,artikelnr,bezeichnung,beschreibung,menge,einheit,preis_cent,mwst_satz)
           VALUES (?,?,?,?,?,?,?,?,?)",
          [$beleg_id, $sort++, $c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6]]);
    }
    return $sort;
}
