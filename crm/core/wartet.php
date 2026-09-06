<?php
// "Wer wartet auf mich" - das Herzstueck.
//
// Die Liste ist ein ZUSAMMENZUG, kein eigener Speicher: Sie holt sich die Vorgaenge aus dem
// Dashboard (core/erp.php) und mischt die eigenen Sachen dazu (Wiedervorlagen, Termine, Kontakte).
// Deshalb fuellt sie sich vom ersten Tag an von selbst - auch wenn niemand etwas eintraegt.
require_once __DIR__ . '/erp.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/ui.php';

// Alle Zeilen, schon sortiert (laengste Wartezeit zuerst).
// $richtung: 'sie' = die warten auf mich, 'wir' = ich warte auf andere, '' = alles.
function wartet_zeilen(string $richtung = 'sie'): array {
    wartet_automatik();
    $zeilen = array_merge(erp_offene_vorgaenge(), wartet_eigene());

    // Weggeklicktes ausblenden - aber nur, solange sich am Vorgang nichts geaendert hat.
    $weg = [];
    foreach (all("SELECT bezug_typ, bezug_id, stand FROM crm_erledigt") as $r) {
        $weg[$r['bezug_typ'] . '#' . $r['bezug_id']] = (string)($r['stand'] ?? '');
    }

    // Vorgaenge mit einer offenen Wiedervorlage tauchen NICHT doppelt auf: Die Wiedervorlage
    // vertritt sie. Solange sie noch nicht faellig ist, ist der Vorgang damit aus der Liste;
    // ab dem Faelligkeitstag steht er als Wiedervorlage wieder da.
    $vertreten = [];
    foreach (all("SELECT bezug_typ, bezug_id FROM crm_wiedervorlage
                  WHERE erledigt_am IS NULL AND bezug_typ IS NOT NULL AND bezug_typ <> 'kontakt'") as $r) {
        $vertreten[$r['bezug_typ'] . '#' . $r['bezug_id']] = true;
    }

    $raus = [];
    foreach ($zeilen as $z) {
        if ($richtung !== '' && ($z['richtung'] ?? 'sie') !== $richtung) continue;
        $schluessel = $z['typ'] . '#' . $z['id'];
        if (array_key_exists($schluessel, $weg) && $weg[$schluessel] === (string)$z['seit']) continue;
        if ($z['typ'] !== 'wiedervorlage' && isset($vertreten[$schluessel])) continue;
        $z['tage']  = tage_seit($z['seit']);
        $z['stufe'] = warte_stufe($z['tage']);
        $raus[] = $z;
    }
    usort($raus, fn($a, $b) => strcmp((string)$a['seit'], (string)$b['seit']));
    return $raus;
}

// Was von selbst passieren soll, ohne dass jemand daran denkt.
//
// Der Fall, der das ganze Programm rechtfertigt: Ein Angebot geht raus und niemand hakt nach.
// Sobald das CRM ein Angebot zum ersten Mal im Status "gesendet" sieht, legt es eine Wiedervorlage
// an - Standard fuenf Tage (CRM_ANGEBOT_NACHFASSEN). Das Dashboard merkt davon nichts.
//
// Angelegt wird je Angebot genau EINMAL: Gepruft wird, ob es dazu ueberhaupt schon eine
// Wiedervorlage gibt - auch eine erledigte. Wer sie einmal abgehakt hat, bekommt sie nicht wieder.
function wartet_automatik(): void {
    static $gelaufen = false;
    if ($gelaufen) return;
    $gelaufen = true;
    if (!tabelle_da('angebot')) return;

    $offen = all("SELECT a.id, a.nummer, COALESCE(a.aktualisiert, a.angelegt) AS stand, k.firma
                  FROM angebot a
                  LEFT JOIN kunden k ON k.id = a.kunde_id
                  WHERE a.status = 'gesendet'
                    AND NOT EXISTS (SELECT 1 FROM crm_wiedervorlage w
                                    WHERE w.bezug_typ = 'angebot' AND w.bezug_id = a.id)");
    if (!$offen) return;

    $jetzt = gmdate('Y-m-d H:i:s');
    foreach ($offen as $a) {
        // Faellig ist sie ab dem Versand plus X Tagen - nicht ab heute. Ein Angebot, das schon
        // zwei Wochen liegt, steht damit sofort in der Liste statt erst in fuenf Tagen.
        $faellig = date('Y-m-d', strtotime((string)$a['stand'] . ' +' . CRM_ANGEBOT_NACHFASSEN . ' days'));
        q("INSERT INTO crm_wiedervorlage (titel, notiz, faellig, bezug_typ, bezug_id, angelegt)
           VALUES (?,?,?,'angebot',?,?)",
          [mb_substr('Nachfassen: ' . trim(((string)($a['firma'] ?? '') !== '' ? $a['firma'] . ' – ' : '')
              . 'Angebot ' . $a['nummer']), 0, 200),
           'Automatisch angelegt, als das Angebot als gesendet erschien.',
           $faellig, (int)$a['id'], $jetzt]);
    }
}

// Wohin fuehrt eine Wiedervorlage oder ein Termin? Kontakte und Kunden haben eine eigene Seite
// im CRM, Dashboard-Vorgaenge nicht - dorthin verlinkt spaeter die Liste selbst.
function wartet_bezug_link(?string $typ, int $id): string {
    if ($typ === 'kontakt' && $id > 0) return '?p=kontakt&id=' . $id;
    if ($typ === 'kunde'   && $id > 0) return '?p=kunde&id=' . $id;
    return '';
}

// Die eigenen Zeilen des CRM: faellige Wiedervorlagen, Termine von heute, Kontakte ohne Termin.
function wartet_eigene(): array {
    $z = [];
    $heute = in_tagen(0);

    foreach (all("SELECT w.*, k.name AS kontakt_name, k.firma AS kontakt_firma
                  FROM crm_wiedervorlage w
                  LEFT JOIN crm_kontakt k ON (w.bezug_typ='kontakt' AND k.id = w.bezug_id)
                  WHERE w.erledigt_am IS NULL AND w.faellig <= ?
                  ORDER BY w.faellig ASC", [$heute]) as $r) {
        $wer = trim((string)($r['kontakt_firma'] ?: $r['kontakt_name'] ?? ''));
        $z[] = ['typ' => 'wiedervorlage', 'id' => (int)$r['id'],
            'titel' => (string)$r['titel'],
            'unter' => trim((string)($r['notiz'] ?? '')) ?: ($wer !== '' ? $wer : 'Wiedervorlage'),
            'seit'  => $r['faellig'] . ' 00:00:00',
            'link'  => wartet_bezug_link((string)$r['bezug_typ'], (int)$r['bezug_id']),
            'betrag' => null, 'richtung' => 'sie'];
    }

    foreach (all("SELECT * FROM crm_termin WHERE erledigt = 0 AND start_at <= ?
                  ORDER BY start_at ASC", [gmdate('Y-m-d H:i:s', strtotime('+1 day'))]) as $r) {
        $z[] = ['typ' => 'termin', 'id' => (int)$r['id'],
            'titel' => (string)$r['titel'],
            'unter' => 'Termin ' . fmt_zeit((string)$r['start_at'], 'd.m. H:i') . (($r['ort'] ?? '') !== '' ? ' · ' . $r['ort'] : ''),
            'seit'  => (string)$r['start_at'],
            'link'  => wartet_bezug_link((string)$r['bezug_typ'], (int)$r['bezug_id']),
            'betrag' => null, 'richtung' => 'sie'];
    }

    // Neue Kontakte, um die sich noch niemand gekuemmert hat: keine Wiedervorlage, kein Verlauf.
    foreach (all("SELECT k.* FROM crm_kontakt k
                  WHERE k.archiviert = 0 AND k.phase IN ('neu','gespraech')
                    AND NOT EXISTS (SELECT 1 FROM crm_wiedervorlage w
                                    WHERE w.bezug_typ='kontakt' AND w.bezug_id=k.id AND w.erledigt_am IS NULL)
                  ORDER BY k.angelegt ASC") as $r) {
        $z[] = ['typ' => 'kontakt', 'id' => (int)$r['id'],
            'titel' => trim(((string)($r['firma'] ?? '') !== '' ? $r['firma'] . ' – ' : '') . $r['name']),
            'unter' => (crm_quellen()[$r['quelle']] ?? 'Kontakt') . ' · ' . (crm_phasen()[$r['phase']] ?? $r['phase'])
                     . (trim((string)($r['notiz'] ?? '')) !== '' ? ' · ' . mb_substr(trim((string)$r['notiz']), 0, 80) : ''),
            'seit'  => (string)$r['angelegt'],
            'link'  => '?p=kontakt&id=' . (int)$r['id'],
            'betrag' => $r['wert_eur'] !== null ? (float)$r['wert_eur'] : null, 'richtung' => 'sie'];
    }

    return $z;
}

// Zeile wegklicken. Eigene Sachen werden wirklich erledigt, Dashboard-Vorgaenge nur ausgeblendet -
// im Dashboard aendert das CRM nichts.
function wartet_erledigen(string $typ, int $id, string $stand, int $uid): void {
    $jetzt = gmdate('Y-m-d H:i:s');
    if ($typ === 'wiedervorlage') {
        $w = one("SELECT bezug_typ, bezug_id FROM crm_wiedervorlage WHERE id=?", [$id]);
        q("UPDATE crm_wiedervorlage SET erledigt_am=?, erledigt_von=? WHERE id=?", [$jetzt, $uid ?: null, $id]);
        // Haengt die Wiedervorlage an einem Dashboard-Vorgang, ist mit ihr auch der Vorgang
        // erledigt - sonst stuende das Angebot in der naechsten Sekunde wieder in der Liste.
        if ($w && $w['bezug_typ'] && $w['bezug_typ'] !== 'kontakt') {
            wartet_erledigen((string)$w['bezug_typ'], (int)$w['bezug_id'],
                             erp_stand((string)$w['bezug_typ'], (int)$w['bezug_id']), $uid);
        }
        return;
    }
    if ($typ === 'termin') {
        q("UPDATE crm_termin SET erledigt=1 WHERE id=?", [$id]);
        return;
    }
    q("INSERT INTO crm_erledigt (bezug_typ, bezug_id, stand, benutzer_id, angelegt) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE stand=VALUES(stand), benutzer_id=VALUES(benutzer_id), angelegt=VALUES(angelegt)",
      [$typ, $id, $stand, $uid ?: null, $jetzt]);
}

// "Nicht jetzt, in X Tagen wieder": legt eine Wiedervorlage an und blendet die Zeile bis dahin aus.
function wartet_spaeter(string $typ, int $id, string $titel, string $stand, int $tage, int $uid): void {
    $jetzt = gmdate('Y-m-d H:i:s');
    if ($typ === 'wiedervorlage') {
        q("UPDATE crm_wiedervorlage SET faellig=? WHERE id=?", [in_tagen($tage), $id]);
        return;
    }
    if ($typ === 'termin') {
        q("UPDATE crm_termin SET start_at=? WHERE id=?",
          [gmdate('Y-m-d H:i:s', strtotime('+' . $tage . ' days')), $id]);
        return;
    }
    // Die offene Wiedervorlage vertritt den Vorgang - er verschwindet dadurch von selbst aus
    // der Liste und steht am Faelligkeitstag wieder da. Kein zweiter Eintrag noetig.
    q("INSERT INTO crm_wiedervorlage (titel, faellig, bezug_typ, bezug_id, benutzer_id, angelegt)
       VALUES (?,?,?,?,?,?)",
      [mb_substr($titel, 0, 200), in_tagen($tage), $typ, $id, $uid ?: null, $jetzt]);
}

// Zahl fuers Abzeichen: wie viele warten auf mich?
function wartet_anzahl(): int { return count(wartet_zeilen('sie')); }
