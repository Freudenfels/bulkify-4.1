<?php
// Laboranalysen (Labortests / CoA fertiger Produkte). Genutzt vom Admin-Reiter (module/system/laboranalysen.php),
// vom Auftrag/Charge-Upload und vom Kundenportal (Reiter „Labortest").
//
// Ablage: die generische Tabelle `dokument` (typ='analyse'). Verknuepfung entweder mit einem PRODUKT
// (objekt_typ='produkt' -> gilt fuer alle Bestellungen des Kunden mit diesem Produkt) oder mit einer
// BESTELLUNG (objekt_typ='auftrag' -> genau diese Charge). Sichtbar fuer den Kunden nur mit kunde_sichtbar=1.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/dokument_ui.php';

// KI-VORSCHLAG (kein Beschluss): aus dem hochgeladenen Laborbericht das passende Produkt + Analysendatum lesen.
// Rueckgabe: ['ok'=>bool, 'produkt_id'=>?int, 'produkt_name'=>string, 'datum'=>?string(YYYY-MM-DD), 'charge'=>?string, ...]
function laboranalyse_ki_vorschlag(string $pfad): array {
    require_once __DIR__ . '/ki.php';
    if (!ki_bereit()) return ['ok' => false, 'fehler' => 'KI-Vorschlag läuft nur auf beta (Schlüssel serverseitig).'];
    // Produkt-Kandidaten kompakt mitgeben (id: Name). Begrenzt, damit die Anfrage nicht ausufert.
    $prods = all("SELECT id, COALESCE(NULLIF(kundenname,''), name) AS name FROM produkt ORDER BY name LIMIT 400");
    $liste = implode("\n", array_map(fn($p) => (int)$p['id'] . ': ' . $p['name'], $prods));
    $anw = "Die Datei ist ein Laborbericht bzw. Analysenzertifikat (CoA) eines Nahrungsergänzungsmittels.\n"
         . "Ordne ihn dem passenden Produkt aus der folgenden Liste zu – NUR wenn die Zuordnung eindeutig ist, sonst produkt_id=null.\n"
         . "Lies ausserdem das Analysendatum (Datum des Berichts) und – falls vorhanden – die Chargennummer.\n"
         . "Beurteile NUR die im Bericht TATSÄCHLICH geprüften Parameter: Liegen alle geprüften Werte innerhalb ihrer\n"
         . "Spezifikation/Grenzwerte bzw. steht sinngemäss „entspricht/konform/bestanden/passed/complies\"? Dann befund=\"bestanden\".\n"
         . "Liegt mindestens ein geprüfter Wert AUSSERHALB der Spezifikation (out of specification/failed/nicht konform)? Dann befund=\"auffaellig\".\n"
         . "Lässt sich das nicht sicher erkennen: befund=\"unklar\". Beurteile NICHT, ob genug getestet wurde – nur das Ergebnis der vorhandenen Prüfungen.\n\n"
         . "Produkte (id: Name):\n" . $liste . "\n\n"
         . 'Antworte als JSON: {"produkt_id": <id oder null>, "produkt_name": "<erkannter Produktname>", "datum": "<YYYY-MM-DD oder null>", "charge": "<Charge oder null>", "befund": "bestanden|auffaellig|unklar", "begruendung": "<kurz>"}';
    $r = ki_datei_frage($pfad, $anw, ['json' => true]);
    if (!$r['ok']) return $r;
    $d = (array) ($r['daten'] ?? []);
    $pid = isset($d['produkt_id']) && $d['produkt_id'] !== null && $d['produkt_id'] !== '' ? (int) $d['produkt_id'] : null;
    // Sicherheitsnetz: nur eine wirklich existierende Produkt-id uebernehmen.
    if ($pid && !scalar("SELECT id FROM produkt WHERE id=?", [$pid])) $pid = null;
    $datum = trim((string) ($d['datum'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) $datum = null;
    $befund = strtolower(trim((string) ($d['befund'] ?? '')));
    if (!in_array($befund, ['bestanden', 'auffaellig', 'unklar'], true)) $befund = 'unklar';
    return ['ok' => true, 'produkt_id' => $pid, 'produkt_name' => trim((string) ($d['produkt_name'] ?? '')),
            'datum' => $datum, 'charge' => trim((string) ($d['charge'] ?? '')) ?: null, 'befund' => $befund,
            'begruendung' => trim((string) ($d['begruendung'] ?? ''))];
}

// Befund -> Label + Ampelfarbe fuer bx_badge (ok=grün, err=rot, ''=neutral). Ohne Belehrung.
function laboranalyse_befund_label(?string $b): array {
    return match ($b) {
        'bestanden'  => ['bestanden', 'ok'],
        'auffaellig' => ['auffällig', 'err'],
        default      => ['', ''],   // unklar/leer: kein Badge
    };
}

// Charge -> konkrete Bestellung(en). Die Chargennummer ist eindeutig und zeigt ueber den Produktionsauftrag
// auf den Kundenauftrag – so wird die Analyse mit der RICHTIGEN Bestellung/dem richtigen Kunden gekoppelt
// (wichtig, wenn dasselbe Produkt an mehrere Kunden geht). Tolerant (Bindestrich/Leerzeichen/Gross-Klein egal).
function laboranalyse_auftraege_zu_charge(string $charge): array {
    $charge = trim($charge);
    if ($charge === '') return [];
    return all("SELECT DISTINCT a.id, a.nummer, a.kunde_id, k.firma AS kunde,
                       COALESCE(NULLIF(p.kundenname,''), p.name, a.produkt_bezeichnung) AS produkt, c.charge_nr
                FROM charge c
                JOIN produktionsauftrag pa ON pa.id=c.pa_id
                JOIN auftrag a ON a.id=pa.auftrag_id
                LEFT JOIN produkt p ON p.id=a.produkt_id
                LEFT JOIN kunden k ON k.id=a.kunde_id
               WHERE REPLACE(REPLACE(LOWER(c.charge_nr),'-',''),' ','') = REPLACE(REPLACE(LOWER(?),'-',''),' ','')
               ORDER BY a.id DESC", [$charge]);
}

// Abgleich der vom Bericht gelesenen Daten mit dem System – gibt Hinweise (kein Blocker) zurueck.
// Prueft: (1) wurde ein Produkt eindeutig erkannt, (2) existiert die Chargennummer im System und
// gehoert sie zum erkannten Produkt, (3) passt der gelesene Produktname zum System-Namen.
// $pid_gewaehlt (optional) = manuell gewaehltes Produkt, sonst wird das KI-Produkt genommen.
function laboranalyse_hinweise(array $ki, int $pid_gewaehlt = 0): array {
    $h = [];
    $pid = $pid_gewaehlt ?: (int)($ki['produkt_id'] ?? 0);
    $readName = trim((string)($ki['produkt_name'] ?? ''));
    $charge = trim((string)($ki['charge'] ?? ''));

    if (!$pid) {
        if ($readName !== '') $h[] = 'Kein eindeutiges Produkt erkannt (Bericht nennt „' . $readName . '"). Bitte manuell wählen.';
    } else {
        // Namensabgleich (kompakt, ohne Sonderzeichen/Gross-Klein).
        $sysName = (string) scalar("SELECT COALESCE(NULLIF(kundenname,''), name) FROM produkt WHERE id=?", [$pid]);
        $norm = fn($s) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower((string)$s));
        if ($readName !== '' && $sysName !== '' && $norm($readName) !== '' &&
            strpos($norm($sysName), $norm($readName)) === false && strpos($norm($readName), $norm($sysName)) === false)
            $h[] = 'Produktname weicht ab: Bericht „' . $readName . '" ↔ System „' . $sysName . '".';
    }

    if ($charge !== '') {
        // Charge kompakt vergleichen (Bindestrich/Leerzeichen egal).
        $treffer = all("SELECT DISTINCT COALESCE(pa.produkt_id, a.produkt_id) AS produkt_id
                        FROM charge c LEFT JOIN produktionsauftrag pa ON pa.id=c.pa_id LEFT JOIN auftrag a ON a.id=pa.auftrag_id
                        WHERE REPLACE(REPLACE(LOWER(c.charge_nr),'-',''),' ','') = REPLACE(REPLACE(LOWER(?),'-',''),' ','')", [$charge]);
        $cPids = array_values(array_filter(array_map(fn($r) => (int)$r['produkt_id'], $treffer)));
        if (!$treffer) {
            $h[] = 'Charge „' . $charge . '" wurde im System nicht gefunden – bitte prüfen.';
        } elseif ($pid && $cPids && !in_array($pid, $cPids, true)) {
            $andere = (string) scalar("SELECT COALESCE(NULLIF(kundenname,''), name) FROM produkt WHERE id=?", [$cPids[0]]);
            $h[] = 'Charge „' . $charge . '" gehört laut System zu einem anderen Produkt' . ($andere ? ' („' . $andere . '")' : '') . '.';
        }
    }
    return $h;
}

// Alle fuer EINEN Kunden sichtbaren Laboranalysen – Produkt-Ebene (fuer gekaufte Produkte) und Bestell-Ebene.
// Rueckgabe je Zeile: id, datei_orig, titel, datum, produkt, produkt_id, auftrag_nr, charge_nr.
function laboranalysen_fuer_kunde(int $kunde_id): array {
    // Produkt-Ebene: gekaufte Produkte (aus auftrag) ODER dem Kunden gehoerende Produkte (produkt.kunde_id –
    // z. B. externe Fremdlager-/Fulfillment-Ware ohne eigenen Auftrag). Bestell-Ebene: Analysen einzelner Auftraege.
    return all("
        SELECT d.id, d.datei_orig, d.titel, d.befund, COALESCE(d.dok_datum, DATE(d.angelegt)) AS datum,
               COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, p.id AS produkt_id,
               NULL AS auftrag_nr, d.charge_nr AS charge_nr
          FROM dokument d JOIN produkt p ON p.id=d.objekt_id
         WHERE d.objekt_typ='produkt' AND d.typ='analyse' AND d.kunde_sichtbar=1
           AND (p.kunde_id=? OR p.id IN (SELECT DISTINCT produkt_id FROM auftrag WHERE kunde_id=? AND produkt_id IS NOT NULL))
        UNION ALL
        SELECT d.id, d.datei_orig, d.titel, d.befund, COALESCE(d.dok_datum, DATE(d.angelegt)) AS datum,
               COALESCE(NULLIF(p.kundenname,''), p.name, a.produkt_bezeichnung) AS produkt, p.id AS produkt_id,
               a.nummer AS auftrag_nr,
               COALESCE(NULLIF(d.charge_nr,''),
                        (SELECT c.charge_nr FROM charge c JOIN produktionsauftrag pa ON pa.id=c.pa_id
                          WHERE pa.auftrag_id=a.id AND c.charge_nr IS NOT NULL AND c.charge_nr<>'' ORDER BY c.id LIMIT 1)) AS charge_nr
          FROM dokument d JOIN auftrag a ON a.id=d.objekt_id LEFT JOIN produkt p ON p.id=a.produkt_id
         WHERE d.objekt_typ='auftrag' AND d.typ='analyse' AND d.kunde_sichtbar=1 AND a.kunde_id=?
        ORDER BY datum DESC, produkt ASC, id DESC", [$kunde_id, $kunde_id, $kunde_id]);
}

// Alle Laboranalysen fuer den Admin-Ueberblick (mit Ziel-Objekt aufgeloest).
function laboranalysen_alle(string $suche = ''): array {
    $like = '%' . $suche . '%';
    return all("
        SELECT d.id, d.objekt_typ, d.objekt_id, d.datei_orig, d.titel, d.kunde_sichtbar, d.charge_nr, d.befund,
               COALESCE(d.dok_datum, DATE(d.angelegt)) AS datum, d.angelegt,
               CASE WHEN d.objekt_typ='produkt' THEN COALESCE(NULLIF(pp.kundenname,''), pp.name)
                    WHEN d.objekt_typ='auftrag' THEN COALESCE(NULLIF(pa.kundenname,''), pa.name, a.produkt_bezeichnung)
                    ELSE NULL END AS produkt,
               CASE WHEN d.objekt_typ='auftrag' THEN a.nummer ELSE NULL END AS auftrag_nr,
               CASE WHEN d.objekt_typ='produkt' THEN kp.firma
                    WHEN d.objekt_typ='auftrag' THEN ka.firma ELSE NULL END AS kunde
          FROM dokument d
          LEFT JOIN produkt pp ON d.objekt_typ='produkt' AND pp.id=d.objekt_id
          LEFT JOIN kunden  kp ON kp.id=pp.kunde_id
          LEFT JOIN auftrag a  ON d.objekt_typ='auftrag' AND a.id=d.objekt_id
          LEFT JOIN produkt pa ON pa.id=a.produkt_id
          LEFT JOIN kunden  ka ON ka.id=a.kunde_id
         WHERE d.typ='analyse'
           AND (? = '' OR COALESCE(pp.name,'') LIKE ? OR COALESCE(pp.kundenname,'') LIKE ?
                       OR COALESCE(pa.name,'') LIKE ? OR COALESCE(a.nummer,'') LIKE ?
                       OR COALESCE(d.datei_orig,'') LIKE ?)
         ORDER BY datum DESC, d.id DESC", [$suche, $like, $like, $like, $like, $like]);
}
