<?php
// Charge: Menge korrigieren (Abendpaket Block B, 11.11.2026, Fable 18.1). EINE Funktion für Dashboard-Chargenseite,
// Lager-App (Charge) und Artikel „Bestand korrigieren" – vorher zwei Wege, die sich unterschiedlich verhielten.
// Gemeinsam (nur q/one/all/scalar), darf nie core/schema.php laden.
//
// Zwei Arten:
//   'ursprung'   Die Ursprungsmenge war falsch (Einbuchfehler, Lieferschein abweichend): charge.menge = neu, die verfügbare
//                Menge verschiebt sich um dieselbe Differenz (nie unter 0). Beispiel 260908: 300.000 statt 240.000.
//   'verfuegbar' Der Bestand stimmt nicht mehr (Schwund, Bruch): nur charge.menge_verfuegbar = neu, Ursprung bleibt.
// Nichts wird überschrieben ohne Spur: Jede Korrektur ist ein Datensatz in charge_korrektur (alt, neu, Differenz, Grund,
// Notiz, Beleg, Nutzer, Zeit) und eine Bewegung in lg_bewegung (typ 'korrektur', Menge mit Vorzeichen) + Verlauf der Charge.
// Gebinde: die Differenz geht auf das letzte Gebinde (Hinweis, welches). Ist die Charge mit einer Bestellposition
// verknüpft, rechnet die gelieferte Menge sofort neu – die Bestellung kann sich dadurch schließen oder wieder öffnen.
require_once __DIR__ . '/wareneingang_bestellung.php';

function charge_korr_gruende(): array {
    return ['einbuchfehler' => 'Einbuchfehler', 'lieferschein' => 'Lieferschein abweichend', 'schwund' => 'Schwund', 'bruch' => 'Bruch', 'sonstiges' => 'Sonstiges'];
}
function charge_korr_schema(): void {
    static $ok = false; if ($ok) return; $ok = true;
    try {
        q("CREATE TABLE IF NOT EXISTS charge_korrektur (
            id INT AUTO_INCREMENT PRIMARY KEY, charge_id INT NOT NULL, art VARCHAR(12) NOT NULL,
            menge_alt DECIMAL(14,3) NULL, menge_neu DECIMAL(14,3) NULL, verf_alt DECIMAL(14,3) NULL, verf_neu DECIMAL(14,3) NULL,
            differenz DECIMAL(14,3) NOT NULL DEFAULT 0, einheit VARCHAR(20) NULL, grund VARCHAR(20) NOT NULL, notiz VARCHAR(500) NULL,
            beleg VARCHAR(190) NULL, gebinde VARCHAR(190) NULL, bestellung VARCHAR(190) NULL, von VARCHAR(120) NULL, angelegt DATETIME NOT NULL,
            KEY idx_charge (charge_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {}
    // 13.11.2026: Status vor „leer“ merken – kommt wieder Menge dazu, geht die Charge dorthin zurück (nicht automatisch „frei“).
    try {
        if ((int) scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='charge' AND COLUMN_NAME='status_vor_leer'") === 0)
            q("ALTER TABLE charge ADD COLUMN status_vor_leer VARCHAR(20) NULL");
    } catch (\Throwable $e) {}
}
function charge_korr_mz(float $x): string { return rtrim(rtrim(number_format($x, 3, ',', '.'), '0'), ','); }

// Korrektur buchen. $was 'ursprung'|'verfuegbar', $neu = neue Menge in der Einheit der Charge.
// Rückgabe ['ok','meldung','hinweis'(Gebinde/Bestellung),'differenz'].
// Eingabe der neuen Menge lesen (Nachbesserung 13.11.2026): leer oder keine Zahl = null (abgelehnt), sonst deutsche Lesart wie alle
// Mengenfelder („12.500“ = 12500, „12,5“ = 12,5, „12.5“ = 12,5). Dashboard: zahl_lesen(); Lager-App ohne schema.php: erp_menge_parse().
function charge_korr_menge_lesen(string $roh): ?float {
    $t = trim($roh);
    if ($t === '' || !preg_match('/^-?\s*\d[\d.,\s]*$/u', $t)) return null;
    if (function_exists('zahl_lesen')) return (float) zahl_lesen($t, true);
    if (function_exists('erp_menge_parse')) return (float) erp_menge_parse($t);
    $t = str_replace(' ', '', $t);
    if (strpos($t, ',') !== false) $t = str_replace(',', '.', str_replace('.', '', $t));
    elseif (preg_match('/^-?[1-9]\d{0,2}(\.\d{3})+$/', $t)) $t = str_replace('.', '', $t);
    return (float) $t;
}
// $neu: Zahl ODER die rohe Eingabe aus dem Formular (empfohlen) - dann lehnt die Funktion leere/ungültige Eingaben selbst ab,
// statt sie als 0 zu buchen (vorher: leer = 0 = Charge „leer“).
function charge_menge_korrigieren(int $charge_id, string $was, float|string $neu, string $grund, string $notiz, string $von, string $beleg = ''): array {
    charge_korr_schema();
    if (is_string($neu)) {
        $neuL = charge_korr_menge_lesen($neu);
        if ($neuL === null) return ['ok' => false, 'meldung' => 'Bitte eine gültige Menge eingeben - leer oder Text wird nicht gebucht.'];
        $neu = $neuL;
    }
    if (!in_array($was, ['ursprung', 'verfuegbar'], true)) return ['ok' => false, 'meldung' => 'Bitte wählen: Ursprungsmenge oder verfügbare Menge.'];
    if (!isset(charge_korr_gruende()[$grund])) return ['ok' => false, 'meldung' => 'Bitte einen Grund wählen.'];
    $notiz = mb_substr(trim($notiz), 0, 500);
    if ($grund === 'sonstiges' && $notiz === '') return ['ok' => false, 'meldung' => 'Bei „Sonstiges“ bitte eine Notiz schreiben.'];
    if ($neu < 0) return ['ok' => false, 'meldung' => 'Die Menge darf nicht negativ sein.'];
    $c = one("SELECT c.*, i.name AS item_name FROM charge c LEFT JOIN item i ON i.id=c.item_id WHERE c.id=?", [$charge_id]);
    if (!$c) return ['ok' => false, 'meldung' => 'Charge nicht gefunden.'];
    $mAlt = (float)$c['menge']; $vAlt = (float)$c['menge_verfuegbar'];
    if ($was === 'ursprung') { $mNeu = round($neu, 3); $vNeu = max(0.0, round($vAlt + ($mNeu - $mAlt), 3)); }
    else                     { $mNeu = $mAlt; $vNeu = round($neu, 3); }
    if (abs($mNeu - $mAlt) < 0.0005 && abs($vNeu - $vAlt) < 0.0005) return ['ok' => false, 'meldung' => 'Keine Änderung - die Menge ist schon so.'];
    $dv = round($vNeu - $vAlt, 3); $dm = round($mNeu - $mAlt, 3);
    // Status (13.11.2026): 0 setzt „leer“ nur aus „frei“ – Quarantäne/gesperrt bleiben. Aus „leer“ mit neuer Menge zurück auf den
    // Status vor „leer“ (gemerkt in status_vor_leer), nicht automatisch „frei“.
    $status = (string)$c['status']; $statusVorLeer = $c['status_vor_leer'] ?? null;
    if ($vNeu <= 0.0005) { if ($status === 'frei') { $statusVorLeer = 'frei'; $status = 'leer'; } }
    elseif ($status === 'leer') { $status = (string)($c['status_vor_leer'] ?? '') !== '' ? (string)$c['status_vor_leer'] : 'frei'; $statusVorLeer = null; }
    // Hinweise (13.11.2026): Reservierung und Verbrauch.
    $hinweise = [];
    $reserviert = function_exists('charge_bulk_reserviert') ? charge_bulk_reserviert($charge_id)
                : (float) (scalar("SELECT COALESCE(SUM(menge),0) FROM reservierung WHERE charge_id=? AND status='aktiv'", [$charge_id]) ?? 0);
    if ($reserviert > 0.0005 && $vNeu + 0.0005 < $reserviert) $hinweise[] = 'Achtung: für Aufträge reserviert sind ' . charge_korr_mz($reserviert) . ' ' . $c['einheit'] . ', verfügbar ist jetzt nur ' . charge_korr_mz($vNeu) . ' - Reservierungen prüfen.';
    $verbraucht = max(0.0, $mAlt - $vAlt);
    if ($was === 'ursprung' && $verbraucht > 0.0005 && $mNeu + 0.0005 < $verbraucht) $hinweise[] = 'Achtung: die neue Ursprungsmenge liegt unter der schon verbrauchten Menge (' . charge_korr_mz($verbraucht) . ' ' . $c['einheit'] . ') - verfügbar bleibt 0.';

    // Erst die Korrektur-Zeile, dann die Charge – beides in EINER Transaktion (13.11.2026): nie eine geänderte Charge ohne Spur.
    $gtxt = charge_korr_gruende()[$grund];
    $einh = (string)$c['einheit'];
    $pdo = db(); $eigeneTx = !$pdo->inTransaction();
    try {
        if ($eigeneTx) $pdo->beginTransaction();
        q("INSERT INTO charge_korrektur (charge_id, art, menge_alt, menge_neu, verf_alt, verf_neu, differenz, einheit, grund, notiz, beleg, von, angelegt)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [$charge_id, $was, $mAlt, $mNeu, $vAlt, $vNeu, $was === 'ursprung' ? $dm : $dv, $einh ?: null, $grund, $notiz ?: null, $beleg ?: null,
           mb_substr($von, 0, 120) ?: null, gmdate('Y-m-d H:i:s')]);
        $korrId = (int) $pdo->lastInsertId();
        try { q("UPDATE charge SET menge=?, menge_verfuegbar=?, status=?, status_vor_leer=? WHERE id=?", [$mNeu, $vNeu, $status, $statusVorLeer, $charge_id]); }
        catch (\Throwable $e0) { q("UPDATE charge SET menge=?, menge_verfuegbar=?, status=? WHERE id=?", [$mNeu, $vNeu, $status, $charge_id]); }
        if ($eigeneTx) $pdo->commit();
    } catch (\Throwable $eTx) {
        if ($eigeneTx && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'meldung' => 'Korrektur nicht gebucht (Datenbankfehler) - nichts geändert.'];
    }

    // Gebinde: Differenz auf das letzte (nicht leere) Gebinde, bei Abgang ggf. weiter nach vorne. Hinweis, welche betroffen sind.
    $gebHinweis = '';
    try {
        $geb = all("SELECT id, nummer, anzahl_gesamt, menge, menge_verfuegbar FROM charge_gebinde WHERE charge_id=? AND inventar_nr IS NULL ORDER BY nummer DESC, id DESC", [$charge_id]);
        if ($geb) {
            $betr = []; $rest = $dv;
            if ($rest > 0) {
                $g = $geb[0]; $nv = round((float)$g['menge_verfuegbar'] + $rest, 3); $ng = $was === 'ursprung' ? round((float)$g['menge'] + $dm, 3) : (float)$g['menge'];
                q("UPDATE charge_gebinde SET menge_verfuegbar=?, menge=GREATEST(?, menge_verfuegbar), status=? WHERE id=?", [$nv, $ng, $nv >= $ng - 0.0005 ? 'voll' : 'angebrochen', $g['id']]);
                $betr[] = 'Gebinde ' . $g['nummer'] . ': ' . charge_korr_mz((float)$g['menge_verfuegbar']) . ' -> ' . charge_korr_mz($nv);
            } else {
                foreach ($geb as $g) {
                    if ($rest >= -0.0005) break;
                    $v = (float)$g['menge_verfuegbar']; if ($v <= 0.0005) continue;
                    $nimm = min(-$rest, $v); $nv = round($v - $nimm, 3);
                    $ng = $was === 'ursprung' ? max($nv, round((float)$g['menge'] - $nimm, 3)) : (float)$g['menge'];
                    q("UPDATE charge_gebinde SET menge_verfuegbar=?, menge=?, status=? WHERE id=?", [$nv, $ng, $nv <= 0.0005 ? 'leer' : 'angebrochen', $g['id']]);
                    $betr[] = 'Gebinde ' . $g['nummer'] . ': ' . charge_korr_mz($v) . ' -> ' . charge_korr_mz($nv);
                    $rest = round($rest + $nimm, 3);
                }
            }
            if ($betr) $gebHinweis = 'Betroffen: ' . implode(', ', $betr) . ' (von ' . count($geb) . ' Gebinden). Einzelne Gebinde lassen sich in der Lager-App nachwiegen.';
        }
    } catch (\Throwable $e) {}

    // Bestellung neu rechnen (schließen oder wieder öffnen).
    $bestHinweis = '';
    if (!empty($c['bestellung_position_id'])) {
        $bpId = (int)$c['bestellung_position_id'];
        $bid = (int) scalar("SELECT bestellung_id FROM bestellung_position WHERE id=?", [$bpId]);
        webe_position_neu_rechnen($bpId);
        if ($bid) {
            $st = webe_bestellung_stand($bid, false, '', true);
            $bnr = (string) scalar("SELECT nummer FROM bestellung WHERE id=?", [$bid]);
            $bestHinweis = 'Bestellung ' . $bnr . ' neu gerechnet: ' . ($st['komplett'] ? 'vollständig geliefert.' : (!empty($st['wieder_offen']) ? 'wieder offen.' : 'noch offen.'));
            foreach (($st['mehr'] ?? []) as $mh) if ((int)$mh['id'] === $bpId) $hinweise[] = 'Mehrlieferung: ' . charge_korr_mz((float)$mh['mehr']) . ' ' . $mh['einheit'] . ' mehr geliefert als bestellt.';   // 13.11.2026
        }
    }
    // Gebinde-/Bestellungs-Hinweis an die schon geschriebene Korrektur-Zeile hängen.
    try { q("UPDATE charge_korrektur SET gebinde=?, bestellung=? WHERE id=?", [mb_substr($gebHinweis, 0, 190) ?: null, mb_substr($bestHinweis, 0, 190) ?: null, $korrId]); } catch (\Throwable $e) {}
    $text = 'Korrektur ' . ($was === 'ursprung' ? 'Ursprungsmenge ' . charge_korr_mz($mAlt) . ' -> ' . charge_korr_mz($mNeu) : 'verfügbar ' . charge_korr_mz($vAlt) . ' -> ' . charge_korr_mz($vNeu))
          . ' ' . $einh . ' · ' . $gtxt . ($notiz !== '' ? ': ' . $notiz : '') . ' · ' . $von;
    // Bewegung (Lager-App „Bewegungen") + Verlauf der Charge. Beides Lager-Tabellen in derselben DB – best effort.
    try { q("INSERT INTO lg_bewegung (charge_id,item_name,typ,menge,einheit,grund,benutzer_id,angelegt) VALUES (?,?,?,?,?,?,?,?)",
            [$charge_id, (string)$c['item_name'] ?: null, 'korrektur', $dv, $einh ?: null, mb_substr($text, 0, 190), null, gmdate('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
    try { q("INSERT INTO lg_charge_log (charge_id,feld,alt,neu,benutzer_id,benutzer_name,angelegt) VALUES (?,?,?,?,?,?,?)",
            [$charge_id, $was === 'ursprung' ? 'Ursprungsmenge' : 'Bestand', charge_korr_mz($was === 'ursprung' ? $mAlt : $vAlt) . ' ' . $einh,
             charge_korr_mz($was === 'ursprung' ? $mNeu : $vNeu) . ' ' . $einh . ' (' . $gtxt . ')', null, mb_substr($von, 0, 120), gmdate('Y-m-d H:i:s')]); } catch (\Throwable $e) {}
    if (function_exists('log_aktivitaet')) {
        try { log_aktivitaet('charge', $charge_id, 'team', $text, 'notiz'); } catch (\Throwable $e) {}
        if (!empty($c['bestellung_position_id']) && !empty($bid)) try { log_aktivitaet('bestellung', $bid, 'team', 'Charge ' . ($c['charge_nr'] ?: '#' . $charge_id) . ': ' . $text, 'notiz'); } catch (\Throwable $e) {}
    }
    if (function_exists('webe_bedarf_bump')) webe_bedarf_bump();
    return ['ok' => true, 'differenz' => $was === 'ursprung' ? $dm : $dv,
            'meldung' => 'Korrigiert: ' . ($was === 'ursprung' ? 'Ursprung ' . charge_korr_mz($mNeu) . ', verfügbar ' . charge_korr_mz($vNeu) : 'verfügbar ' . charge_korr_mz($vNeu)) . ' ' . $einh . '.',
            'hinweis' => trim(implode(' ', $hinweise) . ' ' . $gebHinweis . ' ' . $bestHinweis)];
}
function charge_korr_liste(int $charge_id): array {
    charge_korr_schema();
    try { return all("SELECT * FROM charge_korrektur WHERE charge_id=? ORDER BY id DESC", [$charge_id]); } catch (\Throwable $e) { return []; }
}
// Beleg (Foto/PDF vom Lieferschein) speichern. $f = $_FILES-Eintrag. Rückgabe Dateiname oder ''.
function charge_korr_beleg_speichern(array $f, int $charge_id): string {
    if (empty($f['tmp_name']) || (int)($f['error'] ?? 1) !== 0 || !defined('BX_UPLOADS')) return '';
    $ext = strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'], true)) return '';
    $name = 'charge_korr_' . $charge_id . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!@move_uploaded_file((string)$f['tmp_name'], BX_UPLOADS . '/' . $name)) return '';
    // Als Dokument an der Charge ablegen (Download über ?p=dokument&id=…); in charge_korrektur.beleg steht die Dokument-ID.
    try {
        q("INSERT INTO dokument (objekt_typ, objekt_id, typ, titel, datei, datei_orig) VALUES ('charge', ?, 'sonstiges', 'Beleg Mengenkorrektur', ?, ?)",
          [$charge_id, $name, mb_substr((string)($f['name'] ?? ''), 0, 250) ?: null]);
        return (string) scalar("SELECT LAST_INSERT_ID()");
    } catch (\Throwable $e) { return ''; }
}
// Pop-up „Menge korrigieren" (gleiches Formular überall). $hidden = zusätzliche versteckte Felder (z. B. Aktion, bp_id).
// $vorbelegt = vorgeschlagene neue Menge (z. B. offene Bestellmenge), $was_vor = vorgewählte Art.
function charge_korr_dialog(array $c, string $dlg_id, string $hidden, ?float $vorbelegt = null, string $was_vor = 'ursprung', string $titel = 'Menge korrigieren', string $knopf = 'Korrektur buchen'): string {
    $mz = fn($x) => charge_korr_mz((float)$x);
    $opt = ''; foreach (charge_korr_gruende() as $k => $l) $opt .= '<option value="' . $k . '">' . htmlspecialchars($l) . '</option>';
    $e = htmlspecialchars((string)$c['einheit']);
    return '<dialog id="' . htmlspecialchars($dlg_id) . '" class="bx-dialog" style="max-width:520px">'
        . '<h2 style="margin-top:0">' . htmlspecialchars($titel) . '</h2>'
        . '<p class="muted" style="margin-top:0">Charge ' . htmlspecialchars((string)($c['charge_nr'] ?: '#' . $c['id'])) . ' · Ursprung ' . $mz($c['menge']) . ' ' . $e . ' · verfügbar ' . $mz($c['menge_verfuegbar']) . ' ' . $e . '</p>'
        . '<form method="post" enctype="multipart/form-data" style="margin:0">' . $hidden
        . '<div class="bx-field"><label>Was ist falsch?</label><select name="korr_was" data-norscombo>'
        . '<option value="ursprung"' . ($was_vor === 'ursprung' ? ' selected' : '') . '>Ursprungsmenge (falsch eingebucht) - verfügbar verschiebt sich mit</option>'
        . '<option value="verfuegbar"' . ($was_vor === 'verfuegbar' ? ' selected' : '') . '>nur verfügbare Menge (Schwund, Bruch)</option></select></div>'
        . '<div class="bx-field"><label>Neue Menge (' . $e . ', Einheit fest aus dem Artikel)</label><input type="text" inputmode="decimal" name="korr_menge" required value="' . ($vorbelegt !== null ? $mz($vorbelegt) : '') . '"></div>'
        . '<div class="bx-field"><label>Grund (Pflicht)</label><select name="korr_grund" required data-norscombo><option value="">- wählen -</option>' . $opt . '</select></div>'
        . '<div class="bx-field"><label>Notiz</label><input type="text" name="korr_notiz" maxlength="500" placeholder="z. B. laut Lieferschein 240.000"></div>'
        . '<div class="bx-field"><label>Beleg (optional, Foto oder PDF)</label><input type="file" name="korr_beleg" accept=".pdf,image/*"></div>'
        . '<div class="bx-row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button type="button" class="btn btn-ghost" onclick="this.closest(\'dialog\').close()">Abbrechen</button>'
        . '<button class="btn btn-primary" type="submit">' . htmlspecialchars($knopf) . '</button></div></form></dialog>';
}
