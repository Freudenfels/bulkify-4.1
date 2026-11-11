<?php
// Datenprüfung (nur Admin): die read-only Prüfungen aus der SELECT-Liste „Nicos manuelle Live-DB-Aufgaben"
// direkt im Dashboard. Je Prüfung: Überschrift, Trefferzahl, Treffer-Tabelle und die dokumentierte Korrektur als
// Text. Es wird NICHTS automatisch geändert. Wo eine Korrektur dokumentiert ist, gibt es EINEN „Beheben"-Knopf, der
// genau diese UPDATE nur für die angehakten Zeilen ausführt (Bestätigung, Transaktion, Eintrag ins Feld-Änderungs-
// protokoll aenderung_log). S-16 Chargen ohne Besitzer: Beheben freigegeben (03.11.); S-16 Aufträge bleiben reine Anzeige.
// Route: ?p=datenpruefung
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) {
    render_header('datenpruefung', 'Datenprüfung');
    bx_head('Kein Zugriff', 'Nur für Admins.', bx_btn('Zurück', '?p=dashboard', 'ghost'));
    render_footer(); exit;
}

$gelMenge = column_exists('bestellung_position', 'geliefert_menge');
// Blocker 1 A/B: Beheben-Knopf erst nach Nicos Freigabe der Trefferlisten (bis dahin nur Anzeige). Auf true = Knopf an.
$bl1Frei = false;

// --- Prüfungen ---------------------------------------------------------------------------------------------
// sql:     reine Lese-Abfrage; _id = Zeilen-ID für die Auswahl (wird nicht angezeigt), _link = Öffnen-Link auf das Objekt der
//          Zeile (Charge -> ?p=chargen, Produkt -> ?p=produkt …), _link_<spalte> = macht den Wert dieser Spalte zum Link.
// fix:     null = nur Anzeige. Sonst: tabelle/typ fürs Protokoll, update mit {IDS} (die Bedingungen der Prüfung stehen
//          IMMER mit in der WHERE, damit eine veraltete Auswahl nichts Falsches ändert), waehlbar = Zeile behebbar?
// leer_ok: false = keine Treffer ist das Problem (z. B. niemand hat die QM-Rolle).
require_once BX_ROOT . '/core/bestellung_abgleich.php';
// Einkauf-Altlasten in der Zeile (11.11.2026): Zeilen neu aufbauen (Inhalt, Vorschläge, Knöpfe) und HTML-Spalten.
function dp_abgleich_zeilen(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
        $pos = bestellung_abgleich_positionen((int)$r['_id']);
        $n = count(array_filter($pos, fn($p) => $p['vorschlaege']));
        $neu = ['_id' => $r['_id'], '_link' => $r['_link'], '_pos' => $pos, 'nummer' => $r['nummer'], 'lieferant' => $r['lieferant'], 'inhalt' => ''];
        foreach ($r as $k => $v) if (!isset($neu[$k]) && !in_array($k, ['positionen'], true)) $neu[$k] = $v;
        $neu['vorschlaege'] = $n; $neu['aktionen'] = '';
        $out[] = $neu;
    }
    return $out;
}
function dp_abgleich_html(): array {
    return [
        'lieferant'   => fn($r) => trim((string)$r['lieferant']) !== '' ? h((string)$r['lieferant']) : bx_badge('Lieferant fehlt', 'err'),
        'inhalt'      => fn($r) => bestellung_abgleich_inhalt((int)$r['_id']),
        'vorschlaege' => fn($r) => bestellung_abgleich_zelle($r, $GLOBALS['dp_konflikte'] ?? []),
        'aktionen'    => fn($r) => bestellung_abgleich_knoepfe($r),
    ];
}
// AJAX: eine Aktion aus der Zeile (verknüpfen / als geliefert schließen / Rest storniert / stornieren) - Antwort JSON.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dp_abgl') {
    $res = bestellung_abgleich_aktion((string)($_POST['was'] ?? ''), (int)($_POST['bestellung'] ?? 0), (int)($_POST['bp'] ?? 0), (int)($_POST['charge'] ?? 0));
    if ($res['ok'] && function_exists('bedarf_bump')) bedarf_bump();
    header('Content-Type: application/json; charset=utf-8'); echo json_encode($res, JSON_UNESCAPED_UNICODE); exit;
}
// Sammel-Knopf „Alle eindeutigen verknüpfen": je Paar erneut geprüft (eindeutig, Charge noch frei), dann verknüpft.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'dp_abgl_sammel') {
    $ok = 0; $fehl = 0;
    foreach ((array)($_POST['paar'] ?? []) as $pr) {
        [$pb, $pp, $pc] = array_map('intval', array_pad(explode(':', (string)$pr), 3, 0));
        $pos = array_values(array_filter(bestellung_abgleich_positionen($pb), fn($x) => (int)$x['id'] === $pp));
        $ein = $pos ? bestellung_abgleich_eindeutig($pos[0], []) : null;
        if (!$ein || (int)$ein['id'] !== $pc) { $fehl++; continue; }
        $r = bestellung_abgleich_aktion('verknuepfen', $pb, $pp, $pc);
        $r['ok'] ? $ok++ : $fehl++;
    }
    if ($ok && function_exists('bedarf_bump')) bedarf_bump();
    $k = preg_replace('/[^a-z_]/', '', (string)($_POST['pruefung'] ?? ''));
    header('Location: ?p=datenpruefung&ok=' . rawurlencode($ok . ' Verknüpfung(en) ausgeführt' . ($fehl ? ', ' . $fehl . ' übersprungen (nicht mehr eindeutig)' : '') . '.') . '#' . $k); exit;
}
$CHECKS = [
    // Block C Teil 3 (Abendpaket 11.11.2026): Testdaten auf live. Nur auf Klick. Aktive Einträge mit „test", „zztest", „durchlauf",
    // „dummy" (auch E2E) im Namen oder in der Nummer und E-Mails @example.com/.org. Dazu Reste gelöschter Lieferanten (z. B. id 6,
    // Shandong Health Ingredients: Preise, Anfragen LA-…, Bestellungen BE-TEST ohne Lieferant) – die nur zeigen, nichts löschen.
    // _id = Typ * 10.000.000 + id (eindeutig über die Tabellen). Knopf je Zeile „inaktiv setzen" (gesperrt bzw. Status inaktiv).
    'testdaten_live' => [
        'titel' => 'Testdaten auf live (aktive Einträge mit test/zztest/durchlauf/dummy, @example.com, Reste gelöschter Lieferanten)',
        'info'  => 'Findet aktive Lieferanten, Kunden, Produkte, Artikel und Rezepturen mit „test", „zztest", „durchlauf", „dummy" oder „E2E" im Namen bzw. in der Nummer (LF-TEST, BE-TEST …) sowie E-Mails @example.com/.org. '
                 . 'Achtung, „test" trifft auch echte Namen (z. B. Testosteron) – je Zeile entscheiden. „inaktiv setzen" sperrt Lieferant/Kunde/Artikel bzw. setzt Produkt/Rezeptur auf inaktiv (Rezeptur mit Bulk/Premix). Nichts wird gelöscht. '
                 . 'Unten: Reste gelöschter Lieferanten (Preise, Anfragen, Bestellungen ohne vorhandenen Lieferanten) und Bestellungen mit TEST in der Nummer – nur zur Info, kein Knopf.',
        'auf_klick' => true,
        'sql'   => "SELECT 10000000 + l.id AS _id, CONCAT('?p=lieferant&id=', l.id) AS _link, 'Lieferant' AS art, l.lieferantennummer AS nummer, l.firma AS name, COALESCE(l.email,'') AS email
                    FROM lieferanten l WHERE COALESCE(l.gesperrt,0)=0 AND (LOWER(CONCAT_WS(' ', l.firma, l.lieferantennummer)) REGEXP '(^|[^a-z])(zztest|test|durchlauf|dummy)|e2e' OR LOWER(COALESCE(l.email,'')) REGEXP '@example[.](com|org)')
                    UNION ALL
                    SELECT 20000000 + k.id, CONCAT('?p=kunde&id=', k.id), 'Kunde', k.kundennummer, k.firma, COALESCE(k.email,'')
                    FROM kunden k WHERE COALESCE(k.gesperrt,0)=0 AND (LOWER(CONCAT_WS(' ', k.firma, k.kundennummer)) REGEXP '(^|[^a-z])(zztest|test|durchlauf|dummy)|e2e' OR LOWER(COALESCE(k.email,'')) REGEXP '@example[.](com|org)')
                    UNION ALL
                    SELECT 30000000 + p.id, CONCAT('?p=produkt&id=', p.id), 'Produkt', p.nummer, p.name, ''
                    FROM produkt p WHERE COALESCE(p.status,'') <> 'inaktiv' AND LOWER(CONCAT_WS(' ', p.name, p.nummer)) REGEXP '(^|[^a-z])(zztest|test|durchlauf|dummy)|e2e'
                    UNION ALL
                    SELECT 40000000 + i.id, CONCAT('?p=', IF(i.kategorie='verpackung','verpackung','rohstoff'), '&id=', i.id), CONCAT('Artikel (', i.kategorie, ')'), i.artikelnummer, i.name, ''
                    FROM item i WHERE COALESCE(i.gesperrt,0)=0 AND LOWER(CONCAT_WS(' ', i.name, i.artikelnummer)) REGEXP '(^|[^a-z])(zztest|test|durchlauf|dummy)|e2e'
                    UNION ALL
                    SELECT 50000000 + r.id, CONCAT('?p=rezeptur_detail&id=', r.id), 'Rezeptur', r.nummer, r.name, ''
                    FROM rezeptur r WHERE COALESCE(r.status,'') <> 'inaktiv' AND LOWER(CONCAT_WS(' ', r.name, r.nummer)) REGEXP '(^|[^a-z])(zztest|test|durchlauf|dummy)|e2e'
                    UNION ALL
                    SELECT 60000000 + b.id, CONCAT('?p=bestellung&id=', b.id), 'Bestellung (nur Info)', b.nummer, CONCAT('Status ', b.status, IF(b.lieferant_id IS NOT NULL AND l2.id IS NULL, CONCAT(' · Lieferant gelöscht (id ', b.lieferant_id, ')'), '')), ''
                    FROM bestellung b LEFT JOIN lieferanten l2 ON l2.id=b.lieferant_id
                    WHERE b.status <> 'storniert' AND (UPPER(b.nummer) LIKE '%TEST%' OR UPPER(b.nummer) LIKE '%E2E%' OR (b.lieferant_id IS NOT NULL AND l2.id IS NULL))
                    UNION ALL
                    SELECT 70000000 + lp.id, CONCAT('?p=rohstoff&id=', lp.item_id), 'Preis ohne Lieferant (nur Info)', CONCAT('Lieferant id ', lp.lieferant_id), CONCAT(COALESCE(i2.name, '?'), ' · ', REPLACE(FORMAT(lp.preis, 4), '.', ','), ' ', COALESCE(lp.waehrung,'EUR')), ''
                    FROM lieferant_preis lp LEFT JOIN lieferanten l3 ON l3.id=lp.lieferant_id LEFT JOIN item i2 ON i2.id=lp.item_id
                    WHERE lp.lieferant_id IS NOT NULL AND l3.id IS NULL
                    UNION ALL
                    SELECT 80000000 + la.id, CONCAT('?p=lief_anfrage_detail&id=', la.id), 'Anfrage ohne Lieferant (nur Info)', la.nummer, CONCAT(COALESCE(la.betreff, ''), ' · Lieferant id ', la.lieferant_id), ''
                    FROM lieferant_anfrage la LEFT JOIN lieferanten l4 ON l4.id=la.lieferant_id
                    WHERE la.lieferant_id IS NOT NULL AND l4.id IS NULL
                    ORDER BY 3, 4",
        'korrektur' => "inaktiv setzen: Lieferant/Kunde/Artikel -> UPDATE <tabelle> SET gesperrt=1 WHERE id=<ID>; Produkt -> status='inaktiv'; Rezeptur -> rezeptur_inaktiv_setzen() (sperrt Bulk/Premix mit). Bestellungen, Preise und Anfragen ohne Lieferant: nur anzeigen.",
        'fix'   => ['waehlbar' => fn(array $r) => (int)$r['_id'] < 60000000,
                    'aktion' => function (array $r): int {
                        $typ = intdiv((int)$r['_id'], 10000000); $id = (int)$r['_id'] % 10000000;
                        if ($typ === 5) return rezeptur_inaktiv_setzen($id, true, 'Datenprüfung Testdaten')['ok'] ? 1 : 0;
                        [$tab, $feld, $wert] = [1 => ['lieferanten', 'gesperrt', 1], 2 => ['kunden', 'gesperrt', 1], 3 => ['produkt', 'status', 'inaktiv'], 4 => ['item', 'gesperrt', 1]][$typ] ?? [null, null, null];
                        if (!$tab) return 0;
                        $vor = one("SELECT `$feld` FROM `$tab` WHERE id=?", [$id]);
                        if (!$vor) return 0;
                        q("UPDATE `$tab` SET `$feld`=? WHERE id=?", [$wert, $id]);
                        aenderung_protokollieren($tab === 'lieferanten' ? 'lieferant' : ($tab === 'kunden' ? 'kunde' : $tab), $id, $vor, [$feld => $wert], 'datenpruefung_testdaten');
                        return 1;
                    }],
    ],
    // Einkauf gerade ziehen, Teil 4 (10.11.2026): Altlasten. Verknüpfen/Schließen passiert an der Bestellung (Block „Wareneingang zuordnen").
    'be_ohne_we' => [
        'titel' => 'Einkauf: offene Bestellung, Position ohne Wareneingang (mit Vorschlägen)',
        'info'  => 'Bestellt bzw. gesendet, älter als 14 Tage, mindestens eine offene Position ohne eingebuchte Charge (je Position, nicht je Bestellung - Bündel-Bestellungen bleiben drin, bis jede Position erledigt ist). Spalte „Vorschläge“ = Positionen, zu denen es eine passende vorhandene Charge gibt (gleicher Lieferant, gleicher oder ähnlicher Artikel, passende Menge, Eingang nach dem Bestelldatum). Öffnen führt zur Bestellung, Block „Wareneingang zuordnen“: verknüpfen, als geliefert schließen oder stornieren – je Position, auch bei Bündel-Bestellungen.',
        'sql'   => "SELECT b.id AS _id, CONCAT('?p=bestellung&id=', b.id, '#abgleich') AS _link, b.nummer, COALESCE(l.firma, b.online_haendler) AS lieferant, b.status, b.station,
                           COALESCE(b.bestelldatum, DATE(b.angelegt)) AS bestellt_am,
                           (SELECT COUNT(*) FROM bestellung_position bp WHERE bp.bestellung_id=b.id AND bp.menge>0) AS positionen
                    FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                    WHERE b.status IN ('gesendet','bestellt') AND b.angekommen_am IS NULL
                      AND COALESCE(b.bestelldatum, DATE(b.angelegt)) < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                      AND EXISTS (SELECT 1 FROM bestellung_position bp WHERE bp.bestellung_id=b.id AND " . BA_POS_OFFEN . ")
                    ORDER BY COALESCE(b.bestelldatum, DATE(b.angelegt))",
        // Alles in der Zeile (Nico 11.11.2026): Inhalt, Vorschläge mit „verknüpfen", Knöpfe je Bestellung (AJAX, ohne Neuladen).
        'zeilen' => fn(array $rows): array => dp_abgleich_zeilen($rows),
        'html'   => dp_abgleich_html(),
        'abgleich' => true,
        'ohne_fix' => 'Je Vorschlag „verknüpfen“, je Bestellung „als geliefert schließen“, „Rest storniert“ oder „stornieren“ – direkt hier, die Zeile verschwindet danach. „Öffnen“ führt weiter zur Bestellung.',
    ],
    'be_eingegangen_ohne_charge' => [
        'titel' => 'Einkauf: Bestellung „eingegangen“, Position ohne verknüpfte Charge',
        'info'  => 'Status geliefert bzw. angekommen, aber mindestens eine offene Position ohne eingebuchte Charge (je Position: die Bestellung bleibt drin, bis jede Position verknüpft, als geliefert geschlossen oder storniert ist) (z. B. „Als angekommen markieren“ ohne Einbuchen). Die Ware zählt dann weder als unterwegs noch sicher als Bestand dieser Bestellung. Spalte „Vorschläge“ wie oben. Öffnen → „Wareneingang zuordnen“.',
        'sql'   => "SELECT b.id AS _id, CONCAT('?p=bestellung&id=', b.id, '#abgleich') AS _link, b.nummer, COALESCE(l.firma, b.online_haendler) AS lieferant, b.status, b.angekommen_am,
                           (SELECT COUNT(*) FROM bestellung_position bp WHERE bp.bestellung_id=b.id AND bp.menge>0) AS positionen
                    FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                    WHERE (b.status='geliefert' OR b.angekommen_am IS NOT NULL) AND b.status<>'storniert' AND b.nachtraeglich_grund IS NULL
                      AND EXISTS (SELECT 1 FROM bestellung_position bp WHERE bp.bestellung_id=b.id AND " . BA_POS_OFFEN . ")
                    ORDER BY b.id DESC",
        // Alles in der Zeile (Nico 11.11.2026): Inhalt, Vorschläge mit „verknüpfen", Knöpfe je Bestellung (AJAX, ohne Neuladen).
        'zeilen' => fn(array $rows): array => dp_abgleich_zeilen($rows),
        'html'   => dp_abgleich_html(),
        'abgleich' => true,
        'ohne_fix' => 'Je Vorschlag „verknüpfen“ oder je Bestellung „als geliefert schließen“ – direkt hier. „Öffnen“ führt weiter zur Bestellung.',
    ],
    'be_nachtraeglich' => [
        'titel' => 'Einkauf: nachträglich angelegte Bestellung prüfen (Wareneingang ohne Bestellung)',
        'info'  => 'Beim Wareneingang ohne Bestellung mit Grund „alte Bestellung“ automatisch angelegt (direkt bestellt, geliefert, Menge = Eingang). Bitte Preis und Daten an der Bestellung prüfen. Beheben = als geprüft markieren.',
        'sql'   => "SELECT b.id AS _id, CONCAT('?p=bestellung&id=', b.id) AS _link, b.nummer, l.firma AS lieferant, DATE(b.angelegt) AS angelegt,
                           (SELECT GROUP_CONCAT(COALESCE(i.name, bp.bezeichnung) SEPARATOR ', ') FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id WHERE bp.bestellung_id=b.id) AS artikel,
                           (SELECT SUM(bp.menge * COALESCE(bp.ek_preis,0)) FROM bestellung_position bp WHERE bp.bestellung_id=b.id) AS wert
                    FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                    WHERE b.nachtraeglich_grund IS NOT NULL AND b.nachtraeglich_geprueft_am IS NULL ORDER BY b.id DESC",
        'korrektur' => "UPDATE bestellung SET nachtraeglich_geprueft_am=UTC_TIMESTAMP() WHERE nachtraeglich_grund IS NOT NULL AND id IN (...);",
        'fix'   => ['tabelle' => 'bestellung', 'typ' => 'bestellung',
                    'update' => "UPDATE bestellung SET nachtraeglich_geprueft_am=UTC_TIMESTAMP() WHERE nachtraeglich_grund IS NOT NULL AND nachtraeglich_geprueft_am IS NULL AND id IN ({IDS})"],
    ],
    'be_entwurf_alt' => [
        'titel' => 'Einkauf: alte Bestell-Entwürfe (älter als 14 Tage)',
        'info'  => 'Entwürfe zählen in der Bestellliste als „unterwegs“ und senken damit den Bedarf, obwohl nichts bestellt ist. Beheben = stornieren (nicht löschen, bleibt in der Liste der stornierten).',
        'sql'   => "SELECT b.id AS _id, CONCAT('?p=bestellung&id=', b.id) AS _link, b.nummer, COALESCE(l.firma, b.online_haendler) AS lieferant, DATE(b.angelegt) AS angelegt,
                           (SELECT GROUP_CONCAT(COALESCE(i.name, bp.bezeichnung) SEPARATOR ', ') FROM bestellung_position bp LEFT JOIN item i ON i.id=bp.item_id WHERE bp.bestellung_id=b.id) AS artikel
                    FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                    WHERE b.status='offen' AND b.angelegt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY) ORDER BY b.angelegt",
        'korrektur' => "UPDATE bestellung SET status='storniert' WHERE status='offen' AND id IN (...);",
        'fix'   => ['tabelle' => 'bestellung', 'typ' => 'bestellung',
                    'update' => "UPDATE bestellung SET status='storniert' WHERE status='offen' AND angelegt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY) AND id IN ({IDS})"],
    ],
    'be_ohne_lieferant' => [
        'titel' => 'Einkauf: Bestellung ohne Lieferant oder mit Lieferant „extern“',
        'info'  => 'Offene oder gelieferte Bestellungen ohne echten Lieferanten (Online-Bestellungen mit Händler ausgenommen). Ohne Lieferant fehlen Preise, CoA-Zuordnung und der Vorschlag beim Wareneingang. Bitte an der Bestellung den Lieferanten setzen.',
        'sql'   => "SELECT b.id AS _id, CONCAT('?p=bestellung&id=', b.id) AS _link, b.nummer, COALESCE(l.firma, '–') AS lieferant, b.status, b.bestellart, DATE(b.angelegt) AS angelegt
                    FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                    WHERE b.status<>'storniert' AND NOT (b.bestellart='online' AND COALESCE(b.online_haendler,'')<>'')
                      AND (b.lieferant_id IS NULL OR l.id IS NULL OR LOWER(TRIM(l.firma)) IN ('extern','externer lieferant','unbekannt'))
                    ORDER BY b.id DESC",
        'ohne_fix' => 'Nur Anzeige: den richtigen Lieferanten an der Bestellung wählen.',
    ],
    'be_ohne_position' => [
        'titel' => 'Einkauf: Bestellung ohne Position',
        'info'  => 'Bestellungen ohne eine einzige Position (Menge > 0). Sie tauchen in Listen auf, bringen aber nichts. Beheben = stornieren.',
        'sql'   => "SELECT b.id AS _id, CONCAT('?p=bestellung&id=', b.id) AS _link, b.nummer, COALESCE(l.firma, b.online_haendler) AS lieferant, b.status, DATE(b.angelegt) AS angelegt
                    FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                    WHERE b.status<>'storniert' AND NOT EXISTS (SELECT 1 FROM bestellung_position bp WHERE bp.bestellung_id=b.id AND bp.menge>0)
                    ORDER BY b.id DESC",
        'korrektur' => "UPDATE bestellung SET status='storniert' WHERE id IN (...) AND keine Position;",
        'fix'   => ['tabelle' => 'bestellung', 'typ' => 'bestellung',
                    'update' => "UPDATE bestellung b SET b.status='storniert' WHERE b.status<>'storniert' AND NOT EXISTS (SELECT 1 FROM bestellung_position bp WHERE bp.bestellung_id=b.id AND bp.menge>0) AND b.id IN ({IDS})"],
    ],
    // Vorbereitung Inventur (11.11.2026): Bestand mit verdächtiger Einheit/Menge. Nur Anzeige – geklärt wird in der Inventur
    // (Ursache „Einheitenfehler": Faktor/Einheit der Charge korrigieren) bzw. an der Charge.
    'lager_einheit_verdaechtig' => [
        'titel' => 'Lager: Einheit oder Menge verdächtig (vor der Inventur prüfen)',
        'info'  => 'Chargen mit Bestand, deren Einheit nicht zur Einheit des Artikels passt (z. B. Kapsel-Bulk in kg statt Stk), Stück-Ware mit Nachkommastellen (z. B. „6,254 Stk“) oder Rohstoffe ab 1.000 kg je Charge (z. B. 12.000 kg statt 12 kg). In der Inventur zählen und dort mit Ursache „Einheitenfehler“ korrigieren.',
        'sql'   => "SELECT c.id AS _id, CONCAT('?p=chargen&id=', c.id) AS _link, i.name AS artikel, c.charge_nr, c.menge_verfuegbar AS menge, c.einheit, i.einheit AS artikel_einheit,
                           CASE WHEN LOWER(TRIM(c.einheit)) <> LOWER(TRIM(i.einheit)) THEN 'Einheit Charge ≠ Artikel'
                                WHEN LOWER(c.einheit) IN ('stk','stück') AND ABS(c.menge_verfuegbar - ROUND(c.menge_verfuegbar)) > 0.0005 THEN 'Stück mit Nachkommastellen'
                                ELSE 'sehr große Menge' END AS grund
                    FROM charge c JOIN item i ON i.id=c.item_id
                    WHERE c.menge_verfuegbar > 0 AND COALESCE(c.status,'') <> 'leer'
                      AND ( LOWER(TRIM(c.einheit)) <> LOWER(TRIM(i.einheit))
                         OR (LOWER(c.einheit) IN ('stk','stück') AND ABS(c.menge_verfuegbar - ROUND(c.menge_verfuegbar)) > 0.0005)
                         OR (i.kategorie='rohstoff' AND LOWER(c.einheit)='kg' AND c.menge_verfuegbar >= 1000) )
                    ORDER BY i.name",
        'ohne_fix' => 'In der Lager-App unter Inventur zählen; die Auswertung schlägt „Einheitenfehler“ vor (Faktor/Einheit der Charge korrigieren, nichts gelöscht).',
    ],
    'artikel_dubletten' => [
        'titel' => 'Artikel: ähnliche Artikel (Dubletten-Gruppen)',
        'info'  => 'Gruppen aktiver Artikel mit gleicher Normalform des Namens (mit Synonymen, ohne Gehalt/Bio). Gleiche Qualität = ein Artikel mit mehreren Lieferanten; andere Spezifikation, Kapselgröße, Füllgewicht oder Material bleibt getrennt. Zusammenführen auf der Seite „Artikel zusammenführen“ (je Gruppe, Nico wählt den Hauptartikel).',
        'sql'   => "SELECT 1 AS x",
        'zeilen' => function (array $rows): array {
            require_once BX_ROOT . '/core/artikel_dubletten.php';
            $out = [];
            foreach (artikel_dubletten_gruppen() as $ids) {
                $n = all("SELECT id, name, kategorie FROM item WHERE id IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY name");
                $out[] = ['_id' => (int)$n[0]['id'], '_link' => '?p=artikel_zusammenfuehren&kat=alle&q=' . rawurlencode((string)$n[0]['name']) . '#g' . (int)$n[0]['id'],
                          'gruppe' => (string)$n[0]['name'], 'warenart' => kategorie_label((string)$n[0]['kategorie']), 'artikel' => count($ids),
                          'namen' => mb_substr(implode(' · ', array_column($n, 'name')), 0, 300)];
            }
            return $out;
        },
        'ohne_fix' => 'Bearbeiten auf der Seite „Artikel zusammenführen“ (Öffnen).',
    ],
    'rohstoff_angefragt_ohne_dok' => [
        'titel' => 'Rohstoff: beim Lieferanten angefragt, aber keine Dokumente (Spezifikation/CoA)',
        'info'  => 'Rohstoffe mit mindestens einer Preisanfrage an einen Lieferanten, an denen weder Spezifikation noch CoA noch Analyse hängt (Nico 10.11.2026). „Antworten“ = abgegebene Angebote; „CoA an Bestellung“ = es liegt zumindest an einer Bestellung ein CoA. Sortiert: in Rezepturen verwendete und beantwortete zuerst.',
        'sql'   => "SELECT i.id AS _id, CONCAT('?p=rohstoff&id=', i.id) AS _link, i.artikelnummer, i.name,
                           COUNT(DISTINCT la.id) AS anfragen,
                           GROUP_CONCAT(DISTINCT l.firma ORDER BY l.firma SEPARATOR ', ') AS lieferanten,
                           COUNT(DISTINCT ag.id) AS antworten,
                           DATE(MAX(la.angelegt)) AS letzte_anfrage,
                           MAX(la.coa_gewuenscht) AS coa_angefordert,
                           (SELECT COUNT(DISTINCT z.rezeptur_id) FROM rezeptur_zutat z WHERE z.item_id=i.id) AS rezepturen,
                           (SELECT COUNT(*) FROM dokument d JOIN bestellung_position bp ON bp.id=d.bestellung_position_id
                             WHERE d.objekt_typ='bestellung' AND d.typ='coa' AND bp.item_id=i.id) AS coa_an_bestellung
                    FROM item i
                    JOIN lieferant_anfrage la ON la.item_id=i.id
                    LEFT JOIN lieferanten l ON l.id=la.lieferant_id
                    LEFT JOIN lieferant_angebot ag ON ag.anfrage_id=la.id
                    WHERE i.kategorie='rohstoff' AND COALESCE(i.form,'') NOT IN ('kapselhuelle','premix')
                      AND NOT EXISTS (SELECT 1 FROM dokument d WHERE d.objekt_typ='item' AND d.objekt_id=i.id AND d.typ IN ('spec','coa','beides','analyse'))
                    GROUP BY i.id, i.artikelnummer, i.name
                    ORDER BY ((SELECT COUNT(*) FROM rezeptur_zutat z2 WHERE z2.item_id=i.id) > 0) DESC, (COUNT(DISTINCT ag.id) > 0) DESC, MAX(la.angelegt) DESC, i.name",
        'ohne_fix' => 'Nur Anzeige: beim Lieferanten nachfordern oder am Rohstoff hochladen (Öffnen).',
    ],
    'rohstoff_kundenanfrage_ohne_dok' => [
        'titel' => 'Rohstoff: in Kunden-Anfragen bzw. offenen Rezepturen, aber ohne Lieferanten-Dokumente',
        'info'  => 'Rohstoffe aus Rezeptur-Anfragen (Wunschzeilen), Rohstoff-Anfragen im Portal sowie Rezepturen im Entwurf oder Vorschlag, an denen weder Spezifikation noch CoA noch Analyse des Lieferanten hängt (Nico 10.11.2026, z. B. RZ-4166). „Preisanfrage“ = schon beim Lieferanten angefragt (dann kommen Spec/CoA mit der Antwort). Sortiert nach Anzahl offener Vorgänge.',
        'sql'   => "SELECT i.id AS _id, CONCAT('?p=rohstoff&id=', i.id) AS _link, i.artikelnummer, i.name,
                           (SELECT GROUP_CONCAT(DISTINCT r.nummer ORDER BY r.id DESC SEPARATOR ', ') FROM rezeptur_zutat z JOIN rezeptur r ON r.id=z.rezeptur_id
                             WHERE z.item_id=i.id AND r.status IN ('entwurf','vorschlag')) AS offene_rezepturen,
                           (SELECT COUNT(DISTINCT w.anfrage_id) FROM rezeptur_anfrage_wunsch w JOIN rezeptur_anfrage ra ON ra.id=w.anfrage_id
                             WHERE w.item_id=i.id AND ra.status NOT IN ('abgelehnt')) AS rezeptur_anfragen,
                           (SELECT COUNT(*) FROM portal_anfrage pa WHERE pa.rohstoff_id=i.id) AS rohstoff_anfragen,
                           (SELECT COUNT(*) FROM lieferant_anfrage la WHERE la.item_id=i.id) AS preisanfrage
                    FROM item i
                    WHERE i.kategorie='rohstoff' AND COALESCE(i.form,'') NOT IN ('kapselhuelle','premix')
                      AND NOT EXISTS (SELECT 1 FROM dokument d WHERE d.objekt_typ='item' AND d.objekt_id=i.id AND d.typ IN ('spec','coa','beides','analyse'))
                      AND ( EXISTS (SELECT 1 FROM rezeptur_zutat z JOIN rezeptur r ON r.id=z.rezeptur_id WHERE z.item_id=i.id AND r.status IN ('entwurf','vorschlag'))
                         OR EXISTS (SELECT 1 FROM rezeptur_anfrage_wunsch w JOIN rezeptur_anfrage ra ON ra.id=w.anfrage_id WHERE w.item_id=i.id AND ra.status NOT IN ('abgelehnt'))
                         OR EXISTS (SELECT 1 FROM portal_anfrage pa WHERE pa.rohstoff_id=i.id) )
                    ORDER BY ((SELECT COUNT(DISTINCT z.rezeptur_id) FROM rezeptur_zutat z JOIN rezeptur r ON r.id=z.rezeptur_id WHERE z.item_id=i.id AND r.status IN ('entwurf','vorschlag'))
                              + (SELECT COUNT(*) FROM rezeptur_anfrage_wunsch w WHERE w.item_id=i.id)) DESC, i.name",
        'ohne_fix' => 'Nur Anzeige: am Rohstoff „Preis anfragen“ (mit CoA/Spezifikation) oder vorhandene Unterlagen hochladen (Öffnen).',
    ],
    'rohstoff_ohne_wirkstoff' => [
        'titel' => 'Rohstoff: klingt nach Mineral/Vitamin, aber ohne Wirkstoffdaten (in Rezepturen verwendet)',
        'info'  => 'Ohne Wirkstoffdaten (z. B. „Kalium 38 %“ an Kaliumcitrat) fehlen in Rezeptur, PIB und Kundenportal die elementaren Werte und die % NRV – der Kunde sieht dann nur die Salz-Menge. Bitte am Rohstoff unter Wirkstoffe den Gehalt eintragen (Nico 10.11.2026, RZ-4166 Basen-Modul: Kalium, Zink, Natrium fehlten).',
        'sql'   => "SELECT i.id AS _id, CONCAT('?p=rohstoff&id=', i.id) AS _link, i.artikelnummer, i.name,
                           (SELECT COUNT(DISTINCT z.rezeptur_id) FROM rezeptur_zutat z WHERE z.item_id=i.id) AS rezepturen,
                           (SELECT GROUP_CONCAT(DISTINCT r.nummer ORDER BY r.id DESC SEPARATOR ', ') FROM rezeptur_zutat z JOIN rezeptur r ON r.id=z.rezeptur_id WHERE z.item_id=i.id AND r.status IN ('entwurf','vorschlag')) AS offene_vorschlaege
                    FROM item i
                    WHERE i.kategorie='rohstoff' AND COALESCE(i.gesperrt,0)=0 AND COALESCE(i.form,'') NOT IN ('kapselhuelle','premix')
                      AND EXISTS (SELECT 1 FROM rezeptur_zutat z WHERE z.item_id=i.id)
                      AND NOT EXISTS (SELECT 1 FROM item_wirkstoff iw WHERE iw.item_id=i.id)
                      AND (LOWER(i.name) REGEXP 'citrat|bisglycinat|glycinat|gluconat|oxid|carbonat|chlorid|sulfat|picolinat|malat|orotat|lactat|aspartat|taurat|threonat|phosphat|fumarat'
                           OR LOWER(i.name) REGEXP '(^|[^a-z])(kalium|natrium|zink|zinc|magnesium|calcium|kalzium|eisen|selen|jod|chrom|kupfer|mangan|molybdän|vitamin|folsäure|biotin|niacin|riboflavin|thiamin|cobalamin)')
                    ORDER BY rezepturen DESC, i.name",
        'ohne_fix' => 'Nur Anzeige: Gehalt am Rohstoff unter Wirkstoffe eintragen (Öffnen).',
    ],
    'we_ohne_bestellung' => [
        'titel' => 'Lager: Wareneingang ohne Bestellung (Muster, Sonstiges, Kundenware)',
        'info'  => 'Seit 10.11.2026 nur mit Grund möglich. Zur Kontrolle: Grund, Notiz und Charge. „Alte Bestellung“ steht nicht hier, sondern bei den nachträglichen Bestellungen.',
        'sql'   => "SELECT c.id AS _id, CONCAT('?p=chargen&id=', c.id) AS _link, c.charge_nr, i.name AS artikel, c.menge, c.einheit, c.wareneingang, c.we_grund AS grund, c.we_grund_notiz AS notiz
                    FROM charge c JOIN item i ON i.id=c.item_id WHERE c.we_grund IS NOT NULL AND c.we_grund<>'alt_bestellung' ORDER BY c.id DESC LIMIT 300",
        'ohne_fix' => 'Nur Anzeige.',
    ],
    's02' => [
        'titel' => 'S-02: exklusive Produkte im Katalog sichtbar',
        'info'  => 'Produkte aus einer exklusiven Kunden-Rezeptur, die selbst noch nicht exklusiv sind und damit im Portal-Katalog für alle sichtbar.',
        'sql'   => "SELECT p.id AS _id, CONCAT('?p=produkt&id=', p.id) AS _link, p.nummer, p.name, p.status, p.kunde_id, p.exklusiv,
                           r.nummer AS rezeptur, r.name AS rezeptur_name, r.kunde_id AS rezeptur_kunde_id, k.firma
                    FROM produkt p JOIN rezeptur r ON r.id = p.rezeptur_id LEFT JOIN kunden k ON k.id = r.kunde_id
                    WHERE r.exklusiv = 1 AND r.kunde_id IS NOT NULL AND p.exklusiv = 0
                    ORDER BY r.kunde_id, p.id",
        'korrektur' => "UPDATE produkt p JOIN rezeptur r ON r.id=p.rezeptur_id SET p.exklusiv=1, p.kunde_id=r.kunde_id\n WHERE r.exklusiv=1 AND r.kunde_id IS NOT NULL AND p.exklusiv=0 AND p.id IN (...);",
        'fix'   => ['tabelle' => 'produkt', 'typ' => 'produkt',
                    'update' => "UPDATE produkt p JOIN rezeptur r ON r.id=p.rezeptur_id SET p.exklusiv=1, p.kunde_id=r.kunde_id
                                 WHERE r.exklusiv=1 AND r.kunde_id IS NOT NULL AND p.exklusiv=0 AND p.id IN ({IDS})"],
    ],
    'rohstoff_bestellt_ohne' => [
        'titel' => 'Fortschritt „Rohstoff bestellt“ ohne echte Bestellung',
        'info'  => 'Aufträge, deren Fortschritt von Hand auf „Rohstoff bestellt“ gesetzt ist, ohne dass es eine echte Bestellung zum Auftrag gibt '
                 . '(an den Lieferanten gesendet, bestätigt oder geliefert). Kunde sieht im Portal „Rohstoff bestellt“. Korrektur: die manuell gesetzte Stufe entfernen – '
                 . 'danach zeigt der Fortschritt wieder den echten Stand (Bestätigt bzw. sobald bestellt: Rohstoff bestellt).',
        'sql'   => "SELECT a.id AS _id, CONCAT('?p=auftrag&id=', a.id) AS _link, a.nummer, k.firma AS kunde, a.status, a.phase_override, a.status_datum
                    FROM auftrag a LEFT JOIN kunden k ON k.id = a.kunde_id
                    WHERE a.phase_override = 1 AND a.status NOT IN ('storniert','versendet','erledigt')
                      AND NOT EXISTS (SELECT 1 FROM bestellung_position bp JOIN bestellung b ON b.id = bp.bestellung_id
                                      WHERE bp.auftrag_id = a.id AND (b.status IN ('gesendet','bestellt','geliefert') OR b.angekommen_am IS NOT NULL))
                      AND NOT EXISTS (SELECT 1 FROM aktivitaet ak JOIN bestellung b ON b.id = ak.ref_id
                                      WHERE ak.objekt_typ = 'auftrag' AND ak.objekt_id = a.id AND ak.ref_typ = 'bestellung'
                                        AND (b.status IN ('gesendet','bestellt','geliefert') OR b.angekommen_am IS NOT NULL))
                    ORDER BY a.id DESC",
        'korrektur' => "UPDATE auftrag SET phase_override=NULL WHERE phase_override=1 AND id IN (...);",
        'fix'   => ['tabelle' => 'auftrag', 'typ' => 'auftrag',
                    'update' => "UPDATE auftrag SET phase_override=NULL WHERE phase_override=1 AND status NOT IN ('storniert','versendet','erledigt') AND id IN ({IDS})"],
    ],
    // Handbuch 10.2 (08.11.2026): von Hand eine HÖHERE Stufe gesetzt (phase_override >= 2) – dann gilt „Rohstoff bestellt" ebenfalls als
    // erledigt, ohne echte Bestellung. Nur ANZEIGEN, nicht automatisch zurücksetzen (Ware kann z. B. aus dem Lager gekommen sein).
    'rohstoff_bestellt_ohne_hoeher' => [
        'titel' => 'Fortschritt von Hand höher gesetzt, ohne echte Bestellung (nur prüfen)',
        'info'  => 'Aufträge, deren Fortschritt von Hand auf eine Stufe ab „Rohstoff angekommen" gesetzt ist (phase_override ab 2), ohne dass es eine echte Bestellung '
                 . 'zum Auftrag gibt. Damit sieht der Kunde auch „Rohstoff bestellt" als erledigt. Kein automatisches Beheben: bitte je Auftrag prüfen '
                 . '(z. B. Ware aus dem Lager genommen = in Ordnung) und die Stufe bei Bedarf am Auftrag zurücksetzen.',
        'sql'   => "SELECT a.id AS _id, CONCAT('?p=auftrag&id=', a.id) AS _link, a.nummer, k.firma AS kunde, a.status, a.phase_override, a.status_datum
                    FROM auftrag a LEFT JOIN kunden k ON k.id = a.kunde_id
                    WHERE a.phase_override >= 2 AND a.status NOT IN ('storniert','versendet','erledigt')
                      AND NOT EXISTS (SELECT 1 FROM bestellung_position bp JOIN bestellung b ON b.id = bp.bestellung_id
                                      WHERE bp.auftrag_id = a.id AND (b.status IN ('gesendet','bestellt','geliefert') OR b.angekommen_am IS NOT NULL))
                      AND NOT EXISTS (SELECT 1 FROM aktivitaet ak JOIN bestellung b ON b.id = ak.ref_id
                                      WHERE ak.objekt_typ = 'auftrag' AND ak.objekt_id = a.id AND ak.ref_typ = 'bestellung'
                                        AND (b.status IN ('gesendet','bestellt','geliefert') OR b.angekommen_am IS NOT NULL))
                    ORDER BY a.id DESC",
        'korrektur' => '– (nur anzeigen; Stufe am Auftrag unter „Fortschritt manuell auf eine Stufe setzen" anpassen)',
    ],
    // Fortschritt (Nico 11.11.2026): von Hand (phase_override/Status) weiter als echte Mengen bzw. Labor - z. B. AB-3237, AB-3245, AB-3244.
    // Nur auf Klick (rechnet je Auftrag den Fortschritt). Drei Knöpfe: zurücksetzen (aus den Daten) / Rest storniert / so lassen.
    'fortschritt_hand_weiter' => [
        'titel' => 'Fortschritt von Hand weiter als die echten Mengen bzw. das Labor',
        'info'  => 'Aufträge, bei denen ein Schritt von Hand oder per manueller Stufe weiter steht als die eingegangene/produzierte Menge oder die Laboranalyse. '
                 . 'Kunde und Dashboard zeigen inzwischen den echten Stand (teilweise / Labor offen); hier wird der Widerspruch in den Daten aufgeräumt. '
                 . '„Zurücksetzen" entfernt die manuelle Stufe (Status „versandbereit" wird wieder „in Produktion"), „Rest storniert" rechnet den Fortschritt mit der gelieferten Menge (Hinweis Rechnung/Gutschrift prüfen), „So lassen" blendet die Zeile aus.',
        'auf_klick' => true,
        'sql'   => "SELECT a.id AS _id, CONCAT('?p=auftrag&id=', a.id) AS _link, a.nummer, k.firma AS kunde, a.status, a.phase_override
                    FROM auftrag a LEFT JOIN kunden k ON k.id = a.kunde_id
                    WHERE a.status <> 'storniert' AND COALESCE(a.kategorie,'') <> 'dienstleistung'
                      AND (a.phase_override >= 4 OR a.status IN ('erledigt','versendet')) AND COALESCE(a.fortschritt_geprueft,0) = 0
                    ORDER BY a.id DESC",
        'zeilen' => function (array $rows): array {
            $out = []; $ph = auftrag_phasen(false);
            foreach ($rows as $r) {
                $a = one("SELECT * FROM auftrag WHERE id=?", [(int)$r['_id']]); if (!$a) continue;
                $mo = auftrag_menge_offen($a);
                $mf = auftrag_mengenfortschritt((int)$a['id']);
                $labor = kunde_will_labortest((int)$a['kunde_id']) || !empty($a['laboranalyse_gebucht']);
                $lt = $labor ? auftrag_labortest_status((int)$a['id'], isset($a['produkt_id']) ? (int)$a['produkt_id'] : null) : null;
                $handHoch = (int)($a['phase_override'] ?? 0);
                $laborOffen = $labor && ($lt['status'] ?? '') !== 'abgeschlossen' && (in_array($a['status'], ['erledigt', 'versendet'], true) || $handHoch >= 6);   // versendet nicht mehr ausgeschlossen (11.11.)
                if (!$mo && !$laborOffen) continue;
                $zielV = max((int)$a['menge'], (int)$mf['ziel']);
                $out[] = ['_id' => (int)$a['id'], '_link' => $r['_link'], '_geb' => (int)$mf['gebucht'], '_mo' => $mo ? 1 : 0,
                          'nummer' => $r['nummer'], 'kunde' => $r['kunde'],
                          'schritt' => $handHoch > 0 ? ($ph[$handHoch] ?? ('Stufe ' . $handHoch)) . ' (von Hand)' : ($a['status'] === 'erledigt' ? 'Status versandbereit' : ($a['status'] === 'versendet' ? 'Status versendet' : '–')),
                          'ist_soll' => number_format((int)$mf['gebucht'], 0, ',', '.') . ' / ' . number_format($zielV, 0, ',', '.') . ' Stk',
                          'labor' => $labor ? (['abgeschlossen' => 'abgeschlossen', 'laeuft' => 'läuft', 'auffaellig' => 'auffällig'][$lt['status'] ?? ''] ?? 'offen') : 'kein Labor'];
            }
            return $out;
        },
        'korrektur' => "zurücksetzen:  UPDATE auftrag SET phase_override=NULL, status=IF(status='erledigt','in_produktion',status) WHERE id=<ID>;\n"
                     . "Rest storniert: UPDATE auftrag SET fortschritt_ziel=<gelieferte Menge>, rest_status='storniert' WHERE id=<ID>;  (+ Benachrichtigung Rechnung/Gutschrift)\n"
                     . "so lassen:      UPDATE auftrag SET fortschritt_geprueft=1 WHERE id=<ID>;",
        'fixes' => [
            'zurueck' => ['label' => 'Zurücksetzen (aus den Daten berechnen)', 'aktion' => function (array $r): int {
                $alt = one("SELECT phase_override, status FROM auftrag WHERE id=?", [(int)$r['_id']]); if (!$alt) return 0;
                $neuSt = $alt['status'] === 'erledigt' ? 'in_produktion' : $alt['status'];
                q("UPDATE auftrag SET phase_override=NULL, status=? WHERE id=?", [$neuSt, (int)$r['_id']]);
                aenderung_protokollieren('auftrag', (int)$r['_id'], $alt, ['phase_override' => null, 'status' => $neuSt], 'datenpruefung');
                log_aktivitaet('auftrag', (int)$r['_id'], 'team', 'Fortschritt zurückgesetzt (Datenprüfung): manuelle Stufe entfernt, Stand aus den Daten.', 'status', 'auftrag', (int)$r['_id']);
                return 1; }],
            'storniert' => ['label' => 'Rest storniert', 'waehlbar' => fn(array $r) => !empty($r['_mo']), 'aktion' => function (array $r): int {
                q("UPDATE auftrag SET fortschritt_ziel=?, rest_status='storniert' WHERE id=?", [(int)$r['_geb'], (int)$r['_id']]);
                aenderung_protokollieren('auftrag', (int)$r['_id'], ['fortschritt_ziel' => null], ['fortschritt_ziel' => (int)$r['_geb']], 'datenpruefung');
                log_aktivitaet('auftrag', (int)$r['_id'], 'team', 'Rest storniert (Datenprüfung): Fortschritt rechnet mit ' . (int)$r['_geb'] . ' Stk. Rechnung/Gutschrift prüfen.', 'status', 'auftrag', (int)$r['_id']);
                if (function_exists('benachrichtigung_add')) benachrichtigung_add('Rest storniert: ' . $r['nummer'] . ' - Rechnung/Gutschrift prüfen', 'Über die Datenprüfung. An der Rechnung wurde nichts geändert.', (string)$r['_link'], 'sonstiges', true);
                return 1; }],
            'lassen' => ['label' => 'So lassen', 'aktion' => function (array $r): int {
                q("UPDATE auftrag SET fortschritt_geprueft=1 WHERE id=?", [(int)$r['_id']]);
                aenderung_protokollieren('auftrag', (int)$r['_id'], ['fortschritt_geprueft' => 0], ['fortschritt_geprueft' => 1], 'datenpruefung');
                return 1; }],
        ],
    ],
    's10_bulk' => [
        'titel' => 'S-10: Bulk-Bestellungen in g/ml mit €/kg-Preis',
        'info'  => 'Bulk-Positionen in g oder ml mit einem Preis, der nach €/kg aussieht (Summe dadurch 1.000-fach). Korrektur: Menge in kg bzw. l umrechnen'
                 . ($gelMenge ? ', die bereits gelieferte Menge wird mit umgerechnet.' : '.'),
        'sql'   => "SELECT bp.id AS _id, CONCAT('?p=bestellung&id=', b.id) AS _link, b.nummer, b.status, bp.bezeichnung, bp.menge, bp.einheit, bp.ek_preis,
                           " . ($gelMenge ? 'bp.geliefert_menge, ' : '') . "ROUND(bp.menge*bp.ek_preis,2) AS summe_falsch, ROUND(bp.menge/1000*bp.ek_preis,2) AS summe_bei_kg
                    FROM bestellung_position bp JOIN bestellung b ON b.id = bp.bestellung_id
                    WHERE bp.item_id IS NULL AND LOWER(bp.einheit) IN ('g','ml') AND bp.ek_preis > 0.5
                    ORDER BY b.id DESC",
        'korrektur' => "UPDATE bestellung_position SET menge=menge/1000, " . ($gelMenge ? "geliefert_menge=geliefert_menge/1000, " : '') . "einheit=IF(LOWER(einheit)='g','kg','l')\n WHERE id IN (...);",
        'fix'   => ['tabelle' => 'bestellung_position', 'typ' => 'bestellung_position',
                    'update' => "UPDATE bestellung_position SET menge=menge/1000, " . ($gelMenge ? "geliefert_menge=geliefert_menge/1000, " : '') . "einheit=IF(LOWER(einheit)='g','kg','l')
                                 WHERE item_id IS NULL AND LOWER(einheit) IN ('g','ml') AND ek_preis > 0.5 AND id IN ({IDS})"],
    ],
    's10_verbrauch' => [
        'titel' => 'S-10: Pulver-Verbrauch als „Stk" gebucht',
        'info'  => 'Produktions-Verbrauch mit Einheit „Stk", obwohl die Charge in g oder ml geführt wird.',
        'sql'   => "SELECT v.id AS _id, CONCAT('?p=produktionsauftrag&id=', v.pa_id) AS _link, CONCAT('?p=chargen&id=', v.charge_id) AS _link_charge_nr, v.pa_id, v.menge, v.einheit, c.charge_nr, c.einheit AS charge_einheit, i.name
                    FROM produktion_verbrauch v JOIN charge c ON c.id = v.charge_id JOIN item i ON i.id = v.item_id
                    WHERE v.einheit = 'Stk' AND c.einheit IN ('g','ml')
                    ORDER BY v.id DESC",
        'korrektur' => '',
        'ohne_fix'  => 'Keine Korrektur dokumentiert: Ob die Menge schon in g gebucht ist oder umgerechnet werden muss, ist je Buchung zu prüfen. Daher nur Anzeige.',
        'fix'   => null,
    ],
    's10_fremdpreis' => [
        'titel' => 'S-10: Pulver-Fremdpreise mit Einheit „Stk"',
        'info'  => 'Lieferanten-Preise für Pulver, Granulat, Flüssiges und Gel ohne Einheit oder mit „Stk". Das Angebot rechnet sie bereits als €/kg, die Einheit zu korrigieren ist trotzdem sauberer.',
        'sql'   => "SELECT la.id AS _id, CONCAT('?p=rezeptur_detail&id=', r.id) AS _link, r.nummer, r.name, r.darreichungsform, l.firma, la.preis, la.einheit, la.menge
                    FROM rezeptur_lief_angebot la JOIN rezeptur r ON r.id = la.rezeptur_id LEFT JOIN lieferanten l ON l.id = la.lieferant_id
                    WHERE r.darreichungsform IN ('pulver','granulat','fluessig','gel') AND (la.einheit IS NULL OR la.einheit IN ('Stk',''))
                    ORDER BY la.id DESC",
        'korrektur' => "UPDATE rezeptur_lief_angebot SET einheit='kg' WHERE id IN (...);",
        'fix'   => ['tabelle' => 'rezeptur_lief_angebot', 'typ' => 'rezeptur_lief_angebot',
                    'update' => "UPDATE rezeptur_lief_angebot SET einheit='kg'
                                 WHERE (einheit IS NULL OR einheit IN ('Stk','')) AND id IN ({IDS})
                                   AND rezeptur_id IN (SELECT id FROM rezeptur WHERE darreichungsform IN ('pulver','granulat','fluessig','gel'))"],
    ],
    'fertig_auftrag' => [
        'titel' => 'Fertigware-Altchargen ohne Auftrag',
        'info'  => 'Optional. Die Chargen funktionieren weiter über den Produktionsauftrag, die Zuordnung zum Auftrag dient nur Übersicht und Auswertung. Behebbar sind nur Zeilen mit Auftrags-Vorschlag.',
        'sql'   => "SELECT c.id AS _id, CONCAT('?p=chargen&id=', c.id) AS _link, IF(pa.auftrag_id IS NULL, NULL, CONCAT('?p=auftrag&id=', pa.auftrag_id)) AS _link_auftrag_nr,
                           IF(c.pa_id IS NULL, NULL, CONCAT('?p=produktionsauftrag&id=', c.pa_id)) AS _link_pa_id, c.charge_nr, i.name, c.menge, c.menge_verfuegbar, c.status,
                           c.pa_id, pa.auftrag_id AS auftrag_vorschlag_id, a.nummer AS auftrag_nr, a.kunde_id
                    FROM charge c JOIN item i ON i.id = c.item_id
                    LEFT JOIN produktionsauftrag pa ON pa.id = c.pa_id LEFT JOIN auftrag a ON a.id = pa.auftrag_id
                    WHERE i.kategorie = 'verkaufsfertig' AND c.auftrag_id IS NULL
                    ORDER BY c.id DESC",
        'korrektur' => "UPDATE charge c JOIN item i ON i.id=c.item_id JOIN produktionsauftrag pa ON pa.id=c.pa_id\n   SET c.auftrag_id = pa.auftrag_id\n WHERE i.kategorie='verkaufsfertig' AND c.auftrag_id IS NULL AND pa.auftrag_id IS NOT NULL AND c.id IN (...);",
        'fix'   => ['tabelle' => 'charge', 'typ' => 'charge',
                    'waehlbar' => fn(array $r) => !empty($r['auftrag_vorschlag_id']),
                    'update' => "UPDATE charge c JOIN item i ON i.id=c.item_id JOIN produktionsauftrag pa ON pa.id=c.pa_id
                                    SET c.auftrag_id = pa.auftrag_id
                                 WHERE i.kategorie='verkaufsfertig' AND c.auftrag_id IS NULL AND pa.auftrag_id IS NOT NULL AND c.id IN ({IDS})"],
    ],
    's16_bestand' => [
        'titel' => 'S-16: Fremdlager-Altbestand – freie Fertigware ohne Besitzer',
        'info'  => 'Fertigware auf Artikeln von Fulfillment-Kunden (Produkt gehört dem Kunden), noch ohne Besitzer. Bis zur Migration gilt sie im FEFO als Rückfall.',
        'sql'   => "SELECT c.id AS _id, CONCAT('?p=chargen&id=', c.id) AS _link, c.charge_nr, i.name, c.menge_verfuegbar, c.status, c.standort, p.kunde_id, k.firma
                    FROM charge c JOIN item i ON i.id = c.item_id AND i.kategorie = 'verkaufsfertig'
                    JOIN produkt p ON p.id = i.produkt_id JOIN kunden k ON k.id = p.kunde_id AND k.nutzt_fulfillment = 1
                    WHERE c.fremd_kunde_id IS NULL AND c.status IN ('frei','quarantaene') AND c.menge_verfuegbar > 0
                    ORDER BY p.kunde_id, c.id",
        'korrektur' => "UPDATE charge c JOIN item i ON i.id=c.item_id AND i.kategorie='verkaufsfertig'
  JOIN produkt p ON p.id=i.produkt_id JOIN kunden k ON k.id=p.kunde_id AND k.nutzt_fulfillment=1
   SET c.fremd_kunde_id = p.kunde_id, c.standort = 'lager2'
 WHERE c.fremd_kunde_id IS NULL AND c.status IN ('frei','quarantaene') AND c.menge_verfuegbar>0 AND c.id IN (...);",
        'ohne_fix'  => 'Freigegeben von Nico am 03.11.2026. Die Charge gehört danach dem Kunden der Zeile und zählt nicht mehr als unser Bestand (Lager 1).',
        // Freigabe Nico 03.11.2026: Besitzer = Kunde des Produkts, Standort Fremdlager.
        'fix'   => ['tabelle' => 'charge', 'typ' => 'charge',
                    'update' => "UPDATE charge c JOIN item i ON i.id=c.item_id AND i.kategorie='verkaufsfertig'
                                   JOIN produkt p ON p.id=i.produkt_id JOIN kunden k ON k.id=p.kunde_id AND k.nutzt_fulfillment=1
                                    SET c.fremd_kunde_id = p.kunde_id, c.standort = 'lager2'
                                 WHERE c.fremd_kunde_id IS NULL AND c.status IN ('frei','quarantaene') AND c.menge_verfuegbar>0 AND c.id IN ({IDS})"],
    ],
    's16_auftraege' => [
        'titel' => 'S-16: Aufträge schon „ins Fremdlager" übergeben',
        'info'  => 'Versendete Aufträge von Fulfillment-Kunden ohne Lieferschein: Die Ware blieb unser Bestand. Beheben ordnet die Fertigware-Charge des Auftrags dem Kunden zu (Fremdlager). '
                 . 'Es wird nichts gebucht und kein Lieferschein erzeugt. Aufträge, deren Charge schon dem Kunden gehört, erscheinen nicht mehr. Ohne eindeutige Charge bitte von Hand prüfen.',
        // Chargen am Auftrag = verkaufsfertige Charge desselben Produkts, direkt (charge.auftrag_id) oder über den PA des Auftrags.
        'sql'   => "SELECT x._id, x._link, x.nummer, x.kunde_id, x.firma, x.menge, x.chargen,
                           IF(x._n = 1 AND x._n_offen = 1, x._kand, NULL) AS _charge_id,
                           IF(x._n = 1, CONCAT('?p=chargen&id=', x._kand_alle), NULL) AS _link_chargen,
                           CASE WHEN x._n = 1 AND x._n_offen = 1 THEN 'behebbar'
                                WHEN x._n = 0 THEN 'nicht automatisch behebbar: keine Fertigware-Charge am Auftrag'
                                WHEN x._n > 1 THEN 'nicht automatisch behebbar: mehrere Fertigware-Chargen'
                                ELSE 'nicht automatisch behebbar: Charge gehört einem anderen Kunden' END AS behebbar
                    FROM (
                      SELECT a.id AS _id, CONCAT('?p=auftrag&id=', a.id) AS _link, a.nummer, a.kunde_id, k.firma, a.menge,
                             (SELECT GROUP_CONCAT(c.charge_nr ORDER BY c.id SEPARATOR ', ') FROM charge c JOIN item ci ON ci.id = c.item_id
                               WHERE ci.kategorie = 'verkaufsfertig' AND ci.produkt_id = a.produkt_id
                                 AND (c.auftrag_id = a.id OR c.pa_id IN (SELECT pa.id FROM produktionsauftrag pa WHERE pa.auftrag_id = a.id))) AS chargen,
                             (SELECT COUNT(*) FROM charge c JOIN item ci ON ci.id = c.item_id
                               WHERE ci.kategorie = 'verkaufsfertig' AND ci.produkt_id = a.produkt_id
                                 AND (c.auftrag_id = a.id OR c.pa_id IN (SELECT pa.id FROM produktionsauftrag pa WHERE pa.auftrag_id = a.id))) AS _n,
                             (SELECT COUNT(*) FROM charge c JOIN item ci ON ci.id = c.item_id
                               WHERE ci.kategorie = 'verkaufsfertig' AND ci.produkt_id = a.produkt_id AND c.fremd_kunde_id IS NULL
                                 AND (c.auftrag_id = a.id OR c.pa_id IN (SELECT pa.id FROM produktionsauftrag pa WHERE pa.auftrag_id = a.id))) AS _n_offen,
                             (SELECT COUNT(*) FROM charge c JOIN item ci ON ci.id = c.item_id
                               WHERE ci.kategorie = 'verkaufsfertig' AND ci.produkt_id = a.produkt_id AND c.fremd_kunde_id = a.kunde_id
                                 AND (c.auftrag_id = a.id OR c.pa_id IN (SELECT pa.id FROM produktionsauftrag pa WHERE pa.auftrag_id = a.id))) AS _n_markiert,
                             (SELECT MIN(c.id) FROM charge c JOIN item ci ON ci.id = c.item_id
                               WHERE ci.kategorie = 'verkaufsfertig' AND ci.produkt_id = a.produkt_id AND c.fremd_kunde_id IS NULL
                                 AND (c.auftrag_id = a.id OR c.pa_id IN (SELECT pa.id FROM produktionsauftrag pa WHERE pa.auftrag_id = a.id))) AS _kand,
                             (SELECT MIN(c.id) FROM charge c JOIN item ci ON ci.id = c.item_id
                               WHERE ci.kategorie = 'verkaufsfertig' AND ci.produkt_id = a.produkt_id
                                 AND (c.auftrag_id = a.id OR c.pa_id IN (SELECT pa.id FROM produktionsauftrag pa WHERE pa.auftrag_id = a.id))) AS _kand_alle
                      FROM auftrag a JOIN kunden k ON k.id = a.kunde_id AND k.nutzt_fulfillment = 1
                      WHERE a.status = 'versendet' AND NOT EXISTS (SELECT 1 FROM beleg b WHERE b.auftrag_id = a.id AND b.typ = 'lieferschein')
                        AND EXISTS (SELECT 1 FROM item i WHERE i.produkt_id = a.produkt_id AND i.kategorie = 'verkaufsfertig')
                    ) x
                    WHERE NOT (x._n > 0 AND x._n_markiert = x._n)
                    ORDER BY x._id DESC",
        'korrektur' => "-- je angehaktem Auftrag, nur bei genau EINER Fertigware-Charge am Auftrag, die noch keinen Besitzer hat:\nUPDATE charge SET fremd_kunde_id = <Kunde des Auftrags>, standort = 'lager2'\n WHERE id = <Fertigware-Charge des Auftrags> AND fremd_kunde_id IS NULL;",
        'ohne_fix'  => 'Freigegeben von Nico am 03.11.2026. Keine Bestandsbuchung, kein Lieferschein. Zeilen „nicht automatisch behebbar" bitte von Hand prüfen.',
        // Zeile = Auftrag, geändert wird dessen Charge (ziel) mit dem Kunden der Zeile (params je Zeile).
        'fix'   => ['tabelle' => 'charge', 'typ' => 'charge', 'ziel' => '_charge_id',
                    'waehlbar' => fn(array $r) => !empty($r['_charge_id']),
                    'update' => "UPDATE charge SET fremd_kunde_id = ?, standort = 'lager2' WHERE id = ? AND fremd_kunde_id IS NULL",
                    'params' => fn(array $r) => [(int)$r['kunde_id'], (int)$r['_charge_id']]],
    ],
    'zukauf_fertig' => [
        'titel' => 'Zukauf „fertig" mit alten Schritten',
        'info'  => 'Offene Produktionsaufträge mit Zukauf-Art „fertig", bei denen Verpacken oder Etikettieren noch offen ist. Neue PAs bekommen automatisch den kurzen Weg.',
        'sql'   => "SELECT pa.id AS _id, CONCAT('?p=produktionsauftrag&id=', pa.id) AS _link, IF(a.id IS NULL, NULL, CONCAT('?p=auftrag&id=', a.id)) AS _link_auftrag, pa.nummer, a.nummer AS auftrag,
                           GROUP_CONCAT(s.station ORDER BY s.sort SEPARATOR ' > ') AS offene_schritte
                    FROM produktionsauftrag pa LEFT JOIN auftrag a ON a.id = pa.auftrag_id
                    JOIN produktion_schritt s ON s.pa_id = pa.id AND s.erledigt = 0
                    WHERE pa.zukauf_art = 'fertig' AND pa.status NOT IN ('erledigt','storniert')
                      AND EXISTS (SELECT 1 FROM produktion_schritt x WHERE x.pa_id = pa.id AND x.erledigt = 0 AND x.station IN ('Verpacken','Etikettieren'))
                    GROUP BY pa.id, a.id, pa.nummer, a.nummer",
        'korrektur' => '',
        'ohne_fix'  => 'Korrektur am Auftrag: die Zukauf-Art einmal neu speichern, dann werden die Schritte angepasst.',
        'fix'   => null,
    ],
    'qm_rolle' => [
        'titel' => 'Wer hat die Rolle QM (labor)?',
        'info'  => 'Ohne diese Rolle gibt nur der Admin Quarantäne, Fertigware und Qualitätsprüfung frei.',
        'sql'   => "SELECT id AS _id, CONCAT('?p=benutzer_detail&id=', id) AS _link, name, email, rollen FROM benutzer WHERE aktiv = 1 AND rollen LIKE '%labor%' ORDER BY name",
        'korrektur' => '',
        'ohne_fix'  => 'Rolle unter System → Benutzer vergeben.',
        'leer_ok' => false,
        'fix'   => null,
    ],
    'dubletten' => [
        'titel' => 'Doppelte Nummern',
        'info'  => 'Vor dem Fix der Nummernvergabe konnten zwei gleichzeitige Vorgänge dieselbe Nummer bekommen. Bei Rechnungen ist das wegen GoBD wichtig.',
        'sql'   => "SELECT 'beleg' AS tabelle, typ, nummer, COUNT(*) AS anzahl FROM beleg GROUP BY typ, nummer HAVING COUNT(*) > 1
                    UNION ALL SELECT 'auftrag', '', nummer, COUNT(*) FROM auftrag GROUP BY nummer HAVING COUNT(*) > 1
                    UNION ALL SELECT 'angebot', '', nummer, COUNT(*) FROM angebot GROUP BY nummer HAVING COUNT(*) > 1
                    UNION ALL SELECT 'bestellung', '', nummer, COUNT(*) FROM bestellung GROUP BY nummer HAVING COUNT(*) > 1
                    UNION ALL SELECT 'produktionsauftrag', '', nummer, COUNT(*) FROM produktionsauftrag GROUP BY nummer HAVING COUNT(*) > 1
                    UNION ALL SELECT 'item', kategorie, artikelnummer, COUNT(*) FROM item WHERE artikelnummer IS NOT NULL AND artikelnummer <> '' GROUP BY kategorie, artikelnummer HAVING COUNT(*) > 1",
        'korrektur' => '',
        'ohne_fix'  => 'Keine automatische Korrektur: Welche Nummer neu vergeben wird, ist eine Einzelentscheidung (freigegebene Rechnungen nie umnummerieren).',
        'fix'   => null,
    ],
    'etikett_nr' => [
        'titel' => 'Kundenetiketten ohne Artikelnummer',
        'info'  => 'Neue Kundenetiketten bekommen seit Aufräumen 17 automatisch eine Artikelnummer.',
        'sql'   => "SELECT id AS _id, CONCAT('?p=verpackung&id=', id) AS _link, id, name, produkt_id, kunde_id FROM item
                    WHERE verpackung_rolle = 'etikett' AND produkt_id IS NOT NULL AND (artikelnummer IS NULL OR artikelnummer = '')
                    ORDER BY id",
        'korrektur' => "-- je angehaktem Etikett, in ID-Reihenfolge, über die atomare Nummernvergabe wie bei neuen Kundenetiketten:\nUPDATE item SET artikelnummer = naechste_nummer(item_prefix('verpackung'))   -- VP-…, schon vergebene Nummern werden übersprungen\n WHERE id = <Etikett> AND verpackung_rolle='etikett' AND produkt_id IS NOT NULL AND (artikelnummer IS NULL OR artikelnummer='');",
        'ohne_fix'  => 'Freigegeben von Nico am 03.11.2026. Schon nummerierte Etiketten fallen aus der Liste und werden nie neu nummeriert.',
        'fix'   => ['tabelle' => 'item', 'typ' => 'item', 'sortiert' => true,
                    'update' => "UPDATE item SET artikelnummer = ? WHERE id = ? AND verpackung_rolle = 'etikett' AND produkt_id IS NOT NULL AND (artikelnummer IS NULL OR artikelnummer = '')",
                    'params' => fn(array $r) => [dp_neue_vp_nummer(), (int)$r['_id']]],
    ],
    'bl1_katalog_beutel' => [
        'titel' => 'Blocker 1 A: Katalogprodukt mit bedrucktem Kundenbeutel im Behälter-Slot',
        'info'  => 'Vor dem Blocker-1-Fix schrieb die Annahme den bedruckten Beutel eines Kunden in den Behälter-Slot des kundenneutralen Katalogprodukts – alle Kunden sahen ihn im Katalog (z. B. P-2701 mit VP-2747). Das Katalogprodukt soll den neutralen Basis-Behälter tragen; der bedruckte Beutel bleibt am Auftrag des Kunden.',
        'sql'   => "SELECT p.id AS _id, CONCAT('?p=produkt&id=', p.id) AS _link, p.nummer, p.name, p.verpackung_id AS _beutel_id,
                           CONCAT('?p=verpackung&id=', i.id) AS _link_beutel, i.artikelnummer AS beutel, i.name AS beutel_name, i.kunde_id AS beutel_kunde_id
                    FROM produkt p JOIN item i ON i.id = p.verpackung_id
                    WHERE p.exklusiv = 0 AND p.kunde_id IS NULL AND COALESCE(i.kundenspezifisch, 0) = 1
                    ORDER BY p.id",
        'zeilen' => function (array $rows): array {
            foreach ($rows as &$r) {
                [$nid, $quelle] = dp_neutral_herleitung((int)$r['_beutel_id']);
                $n = $nid ? one("SELECT artikelnummer, name FROM item WHERE id=?", [$nid]) : null;
                $r['_neutral_id'] = $nid;
                $r['_link_neutral'] = $nid ? '?p=verpackung&id=' . $nid : null;
                $r['neutral'] = $n ? trim(($n['artikelnummer'] ?? '') . ' ' . $n['name']) : null;
                $r['herleitung'] = $nid ? $quelle : 'nicht automatisch behebbar: neutraler Basis-Behälter nicht eindeutig';
                $r['aenderung'] = $nid ? 'verpackung_id ' . $r['_beutel_id'] . ' (' . ($r['beutel'] ?: '#' . $r['_beutel_id']) . ') -> ' . $nid . ' (' . (($n['artikelnummer'] ?? '') ?: '#' . $nid) . ')' : null;
            }
            return $rows;
        },
        'korrektur' => "-- je angehaktem Produkt: Behälter-Slot zurück auf den neutralen Basis-Behälter (Spalte „neutral“)\nUPDATE produkt SET verpackung_id = <neutral>\n WHERE id = <Produkt> AND exklusiv = 0 AND kunde_id IS NULL AND verpackung_id = <bedruckter Beutel>;",
        'ohne_fix'  => ($bl1Frei ? '' : 'Nur Anzeige – Beheben-Knopf erst nach Freigabe der Trefferliste. ') . 'Nur der Behälter-Slot ändert sich. Der bedruckte Beutel bleibt bestehen und am Auftrag/PA des Kunden. Zeilen ohne eindeutigen neutralen Behälter sind nicht anwählbar (nicht raten).',
        'fix'   => !$bl1Frei ? null : ['tabelle' => 'produkt', 'typ' => 'produkt',
                    'waehlbar' => fn(array $r) => !empty($r['_neutral_id']),
                    'update' => "UPDATE produkt SET verpackung_id = ? WHERE id = ? AND exklusiv = 0 AND kunde_id IS NULL AND verpackung_id = ?",
                    'params' => fn(array $r) => [(int)$r['_neutral_id'], (int)$r['_id'], (int)$r['_beutel_id']]],
    ],
    'bl1_beutel_besitzer' => [
        'titel' => 'Blocker 1 B: bedruckter Beutel ohne Besitzer (Kunde)',
        'info'  => 'Der bedruckte Beutel gehört dem Kunden (je Produkt + Kunde, wie das Kundenetikett). Ältere Beutel haben nur die Produkt-Bindung. Besitzer = Kunde der Aufträge, die den Beutel nutzen.',
        'sql'   => "SELECT x.*,
                           CASE WHEN x._nk = 1 THEN x.kunde_aus_auftrag
                                WHEN x._nk = 0 THEN 'nicht automatisch behebbar: kein Auftrag nutzt den Beutel'
                                ELSE 'nicht automatisch behebbar: Aufträge mehrerer Kunden' END AS neuer_besitzer
                    FROM (
                      SELECT i.id AS _id, CONCAT('?p=verpackung&id=', i.id) AS _link, i.artikelnummer, i.name, i.produkt_id,
                             CONCAT('?p=produkt&id=', i.produkt_id) AS _link_produkt_id, i.kunde_id,
                             GROUP_CONCAT(DISTINCT a.nummer ORDER BY a.nummer SEPARATOR ', ') AS auftraege,
                             GROUP_CONCAT(DISTINCT CONCAT(k.firma, ' (', a.kunde_id, ')') ORDER BY a.kunde_id SEPARATOR ', ') AS kunde_aus_auftrag,
                             COUNT(DISTINCT a.kunde_id) AS _nk, MIN(a.kunde_id) AS _kunde_neu
                      FROM item i
                      LEFT JOIN auftrag a ON a.verpackung_id = i.id
                      LEFT JOIN kunden k ON k.id = a.kunde_id
                      WHERE COALESCE(i.kundenspezifisch, 0) = 1 AND i.kategorie = 'verpackung' AND i.kunde_id IS NULL
                      GROUP BY i.id, i.artikelnummer, i.name, i.produkt_id, i.kunde_id
                    ) x ORDER BY x._id",
        'korrektur' => "-- je angehaktem Beutel: Besitzer = Kunde der Aufträge mit diesem Beutel (nur wenn genau EIN Kunde)\nUPDATE item SET kunde_id = <Kunde>\n WHERE id = <Beutel> AND kundenspezifisch = 1 AND kunde_id IS NULL;",
        'ohne_fix'  => ($bl1Frei ? '' : 'Nur Anzeige – Beheben-Knopf erst nach Freigabe der Trefferliste. ') . 'Mehrdeutige Beutel (mehrere Kunden) oder Beutel ohne Auftrag sind nicht anwählbar – bitte einzeln klären.',
        'fix'   => !$bl1Frei ? null : ['tabelle' => 'item', 'typ' => 'item',
                    'waehlbar' => fn(array $r) => (int)$r['_nk'] === 1 && (int)$r['_kunde_neu'] > 0,
                    'update' => "UPDATE item SET kunde_id = ? WHERE id = ? AND COALESCE(kundenspezifisch, 0) = 1 AND kunde_id IS NULL",
                    'params' => fn(array $r) => [(int)$r['_kunde_neu'], (int)$r['_id']]],
    ],
    'bl1_katalog_etikett' => [
        'titel' => 'Blocker 1 C: Katalogprodukt mit Kundenetikett im Etikett-Slot (Kontrolle)',
        'info'  => 'Nebenbefund: Vor dem Paket Etiketten wurde das Kundenetikett teils in den Slot des Katalogprodukts geschrieben. Kunden-Sichten lösen das Etikett je Kunde auf (nie das eines anderen Kunden), der Slot gehört trotzdem nicht ans Katalogprodukt.',
        'sql'   => "SELECT p.id AS _id, CONCAT('?p=produkt&id=', p.id) AS _link, p.nummer, p.name, p.etikett_id,
                           CONCAT('?p=verpackung&id=', e.id) AS _link_etikett, e.name AS etikett, e.kunde_id AS etikett_kunde_id, e.produkt_id AS etikett_produkt_id
                    FROM produkt p JOIN item e ON e.id = p.etikett_id
                    WHERE p.exklusiv = 0 AND p.kunde_id IS NULL
                      AND (e.kunde_id IS NOT NULL OR (e.verpackung_rolle = 'etikett' AND e.produkt_id IS NOT NULL))
                    ORDER BY p.id",
        'korrektur' => "-- Vorschlag (noch nicht freigegeben): Etikett-Slot des Katalogprodukts leeren, das Kundenetikett bleibt beim Kunden\nUPDATE produkt SET etikett_id = NULL WHERE id = <Produkt> AND exklusiv = 0 AND kunde_id IS NULL AND etikett_id = <Kundenetikett>;",
        'ohne_fix'  => 'Nur Anzeige – Beheben-Knopf erst nach Freigabe.',
        'fix'   => null,
    ],
    'kundenetikett_fehlt' => [
        'titel' => 'Etikett: Auftrag ohne Kundenetikett-Artikel',
        'info'  => 'Alt-/v3-/Import-Aufträge mit Etikett, für deren Produkt + Kunde es noch keinen Kundenetikett-Artikel gibt (er entsteht sonst bei der Annahme). Ohne ihn lassen sich gelieferte Etiketten nicht kundenrein einbuchen (Lager „Etiketten einbuchen" findet das Produkt nicht), Bedarf und Bestand laufen über das allgemeine Maß-Etikett aus dem Glas.',
        'sql'   => "SELECT a.id AS _id, CONCAT('?p=auftrag&id=', a.id) AS _link, a.nummer, a.status, p.nummer AS produkt_nr, p.name AS produkt,
                           CONCAT('?p=produkt&id=', p.id) AS _link_produkt, k.firma AS kunde, a.etikett_freigegeben AS design_freigegeben,
                           (SELECT COUNT(*) FROM auftrag a2 WHERE a2.produkt_id=a.produkt_id AND a2.kunde_id=a.kunde_id AND a2.status<>'storniert') AS auftraege_gesamt
                    FROM auftrag a
                    JOIN produkt p ON p.id = a.produkt_id
                    LEFT JOIN kunden k ON k.id = a.kunde_id
                    WHERE a.status <> 'storniert' AND a.kunde_id IS NOT NULL AND COALESCE(p.handelsware,0) = 0
                      AND COALESCE(NULLIF(a.verpackung_id,0), p.verpackung_id, p.etikett_id) IS NOT NULL
                      AND NOT EXISTS (SELECT 1 FROM item i WHERE i.verpackung_rolle='etikett' AND i.produkt_id=a.produkt_id AND i.kunde_id=a.kunde_id AND COALESCE(i.gesperrt,0)=0)
                      AND a.id = (SELECT MAX(a3.id) FROM auftrag a3 WHERE a3.produkt_id=a.produkt_id AND a3.kunde_id=a.kunde_id AND a3.status<>'storniert')
                    ORDER BY a.id DESC",
        'zeilen' => function (array $rows): array {
            // Vollbedruckter Beutel braucht kein Etikett (der Druck ersetzt es) -> nicht anbieten.
            $rows = array_values(array_filter($rows, fn($r) => auftrag_braucht_etikett((int)$r['_id']) && !auftrag_vollbedruckt((int)$r['_id'])));
            foreach ($rows as &$r) {
                $vl = kundenetikett_vorlage((int) scalar("SELECT produkt_id FROM auftrag WHERE id=?", [(int)$r['_id']]));
                $r['masse_aus_glas'] = !empty($vl['etikett_format']) ? (string)$vl['etikett_format'] : ((!empty($vl['breite_mm']) && !empty($vl['hoehe_mm'])) ? (float)$vl['breite_mm'] . ' x ' . (float)$vl['hoehe_mm'] . ' mm' : null);
                $r['aenderung'] = 'Kundenetikett v1 anlegen' . ((int)$r['design_freigegeben'] === 1 ? ' mit freigegebenem Design' : ' (Design folgt mit der Freigabe)') . ', offene Aufträge binden';
            }
            return $rows;
        },
        'korrektur' => "-- je angehaktem Produkt + Kunde (Zeile = jüngster Auftrag): wie bei der Annahme\nauftrag_kundenetikett_sicherstellen(<Auftrag>)   -- je Auftrag dieses Produkts + Kunden\n  -> kundenetikett_sicherstellen(Produkt, Kunde, Behälter)   -- v1, Maße aus dem Glas\n  -> freigegebenes Design: kundenetikett_nach_freigabe(Auftrag)\n  -> offene Aufträge: UPDATE auftrag SET etikett_item_id = <v1>",
        'ohne_fix'  => 'Legt nur an und bindet, löscht nichts. Bereits gebuchter Bestand auf dem allgemeinen Maß-Etikett bleibt dort.',
        'fix'   => ['tabelle' => 'auftrag', 'typ' => 'auftrag',
                    'aktion' => function (array $r): int {
                        $n = 0;
                        $a = one("SELECT produkt_id, kunde_id FROM auftrag WHERE id=?", [(int)$r['_id']]);
                        if (!$a) return 0;
                        foreach (all("SELECT id FROM auftrag WHERE produkt_id=? AND kunde_id=? AND status<>'storniert' ORDER BY id DESC", [(int)$a['produkt_id'], (int)$a['kunde_id']]) as $x)
                            if (auftrag_kundenetikett_sicherstellen((int)$x['id']) > 0) $n++;
                        return $n;
                    }],
    ],
    'produkt_name_behaelter' => [
        'titel' => 'Produkt: Name nennt einen anderen Behälter als hinterlegt',
        'info'  => 'Der Produktname bekommt den Behälter beim Anlegen („… · 120 Kapseln · 100 ml Glas“). Wurde der Behälter später geändert (Produktseite, Vorbereitung „als Standard“), blieb der Name stehen – oben 100 ml, unten 150 ml. Beheben zieht den Behälter-Teil im Namen auf den hinterlegten Behälter; der alte Name wird Synonym. Ist stattdessen der Behälter falsch, am Produkt den Behälter korrigieren.',
        'sql'   => "SELECT p.id AS _id, CONCAT('?p=produkt&id=', p.id) AS _link, p.nummer, p.name, vi.id AS _alt_vp, vi.name AS behaelter_im_namen,
                           p.verpackung_id AS _neu_vp, vc.name AS behaelter_am_produkt, CHAR_LENGTH(vi.name) AS _len
                    FROM produkt p
                    JOIN item vc ON vc.id = p.verpackung_id
                    JOIN item vi ON vi.kategorie = 'verpackung' AND COALESCE(vi.verpackung_rolle,'primaer') = 'primaer' AND vi.id <> p.verpackung_id
                                AND CHAR_LENGTH(vi.name) >= 3 AND INSTR(p.name, CONCAT(' · ', vi.name)) > 0
                    WHERE INSTR(p.name, CONCAT(' · ', vc.name)) = 0 AND COALESCE(vc.kundenspezifisch,0) = 0
                    ORDER BY p.id, CHAR_LENGTH(vi.name) DESC",
        'zeilen' => function (array $rows): array {
            // Je Produkt nur den längsten passenden Behälternamen (z. B. „100 ml Glas braun“ statt „100 ml Glas“).
            $out = [];
            foreach ($rows as $r) if (!isset($out[(int)$r['_id']])) $out[(int)$r['_id']] = $r;
            foreach ($out as &$r) {
                $neu = preg_replace('/\s+v\d+$/i', '', preg_replace('/ · ' . preg_quote((string)$r['behaelter_im_namen'], '/') . '(?=$|\s+v\d+$)/u', ' · ' . $r['behaelter_am_produkt'], (string)$r['name'], 1));
                $r['neuer_name'] = $neu !== preg_replace('/\s+v\d+$/i', '', (string)$r['name']) ? $neu : null;
                $r['aenderung'] = $r['neuer_name'] ? 'Name -> ' . $r['neuer_name'] : 'nicht automatisch: Behälter steht nicht am Namensende';
            }
            return array_values($out);
        },
        'korrektur' => "-- je angehaktem Produkt: Behälter-Teil im Namen auf den hinterlegten Behälter ziehen (alter Name -> Synonyme)\nproduktname: ' · <Behälter im Namen>' -> ' · <Behälter am Produkt>'   (produkt_name_behaelter_nachziehen)",
        'ohne_fix'  => 'Der Name ändert sich, dazu die Auftrags-Namen dieses Produkts, die im neuen Behälter laufen. Ist der Behälter am Produkt falsch, bitte dort korrigieren statt hier zu beheben.',
        'fix'   => ['tabelle' => 'produkt', 'typ' => 'produkt',
                    'waehlbar' => fn(array $r) => !empty($r['neuer_name']),
                    'aktion' => fn(array $r) => produkt_name_behaelter_nachziehen((int)$r['_id'], (int)$r['_alt_vp'], (int)$r['_neu_vp'], 'datenpruefung') !== '' ? 1 : 0],
    ],
    'pa_auftrag_storniert' => [
        'titel' => 'Produktion: aktiver Produktionsauftrag zu storniertem Auftrag',
        'info'  => 'Der Auftrag ist storniert (z. B. in ein Kontingent umgewandelt), sein Produktionsauftrag läuft aber weiter. Produktion und Einkauf blenden ihn seit 03.11.2026 aus; Beheben storniert ihn auch in den Daten und gibt Reservierungen frei. Bestellungen beim Lieferanten bleiben bestehen – Spalte „Bestellungen“ prüfen und ggf. im Einkauf stornieren.',
        'sql'   => "SELECT pa.id AS _id, CONCAT('?p=produktionsauftrag&id=', pa.id) AS _link, pa.nummer, pa.status AS pa_status, pa.menge,
                           a.nummer AS auftrag, CONCAT('?p=auftrag&id=', a.id) AS _link_auftrag, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt, k.firma AS kunde,
                           IF(a.kontingent_id IS NOT NULL, 'ja', '') AS kontingent,
                           (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS schritte_erledigt,
                           (SELECT GROUP_CONCAT(DISTINCT b.nummer SEPARATOR ', ') FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                             WHERE bp.auftrag_id=a.id AND b.status NOT IN ('storniert','geliefert') AND b.angekommen_am IS NULL) AS bestellungen
                    FROM produktionsauftrag pa
                    JOIN auftrag a ON a.id = pa.auftrag_id
                    LEFT JOIN produkt p ON p.id = a.produkt_id
                    LEFT JOIN kunden k ON k.id = a.kunde_id
                    WHERE a.status = 'storniert' AND pa.status IN ('vorbereitung','offen','laufend')
                    ORDER BY pa.id DESC",
        'korrektur' => "-- je angehaktem Produktionsauftrag (nur ohne erledigten Schritt):\nUPDATE reservierung SET status='storniert' WHERE pa_id = <PA> AND status='aktiv';\nUPDATE produktionsauftrag SET status='storniert' WHERE id = <PA>;   (pa_storno_nachziehen)",
        'ohne_fix'  => 'Produktionsaufträge mit erledigtem Schritt werden nicht automatisch storniert (bitte ansehen). Bestellungen beim Lieferanten ändert Beheben nicht.',
        'fix'   => ['tabelle' => 'produktionsauftrag', 'typ' => 'produktionsauftrag',
                    'waehlbar' => fn(array $r) => (int)$r['schritte_erledigt'] === 0,
                    'aktion' => fn(array $r) => pa_storno_nachziehen((int)$r['_id'], 'datenpruefung')],
    ],
    'bulk_eingegangen_ohne_charge' => [
        'titel' => 'Einkauf: Bulk-Bestellung eingegangen, aber keine Bulk-Charge am Auftrag',
        'info'  => 'Die Bestellung enthält Bulkware als Freitext (ohne Lagerartikel) für einen Auftrag und steht auf eingegangen/geliefert, am Auftrag hängt aber keine Bulk-Charge. Die Material-Übersicht zeigt dann „nicht eingebucht“. Entstanden über „liefern“ im Dashboard (bis 04.11.2026) oder „Als angekommen markieren“. Lösung: die Ware in der Lager-App einbuchen oder am Auftrag unter „Bulkware binden“ eine vorhandene Charge zuweisen.',
        'sql'   => "SELECT a.id AS _id, CONCAT('?p=auftrag&id=', a.id) AS _link, a.nummer AS auftrag, COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt, k.firma AS kunde,
                           b.nummer AS bestellung, CONCAT('?p=bestellung&id=', b.id) AS _link_bestellung, bp.bezeichnung AS position, bp.menge, bp.einheit,
                           b.status AS bestell_status, b.angekommen_am
                    FROM bestellung_position bp
                    JOIN bestellung b ON b.id = bp.bestellung_id
                    JOIN auftrag a ON a.id = bp.auftrag_id
                    LEFT JOIN produkt p ON p.id = a.produkt_id
                    LEFT JOIN kunden k ON k.id = a.kunde_id
                    WHERE bp.item_id IS NULL AND bp.menge > 0 AND a.status <> 'storniert'
                      AND (b.status = 'geliefert' OR b.angekommen_am IS NOT NULL)
                      AND NOT EXISTS (SELECT 1 FROM charge c JOIN item i ON i.id = c.item_id WHERE c.auftrag_id = a.id AND i.kategorie = 'fertig')
                    ORDER BY b.id DESC",
        'ohne_fix'  => 'Nur Anzeige: Einbuchen braucht Charge, MHD und Menge der echten Lieferung – bitte in der Lager-App buchen oder am Auftrag eine Charge binden.',
    ],
    'fertigware_quarantaene' => [
        'titel' => 'Produktion: eigene Fertigware steht noch in Quarantäne',
        'info'  => 'Seit der Soll-Kette (Teil 5, 04.11.2026) ist eigene Fertigware nach der Produktion sofort frei – Bericht und CoA entstehen beim Produktionsabschluss. Diese Chargen kamen noch nach der alten Regel (QM-Gate) in Quarantäne. Beheben gibt sie frei; dabei entstehen wie bisher Produktionsbericht und CoA für den Kunden.',
        'sql'   => "SELECT c.id AS _id, CONCAT('?p=chargen&id=', c.id) AS _link, i.name AS artikel, c.charge_nr, c.menge_verfuegbar AS menge, c.einheit,
                           pa.nummer AS produktion, a.nummer AS auftrag, IF(c.auftrag_id IS NULL, NULL, CONCAT('?p=auftrag&id=', c.auftrag_id)) AS _link_auftrag, DATE(c.angelegt) AS produziert
                    FROM charge c JOIN item i ON i.id = c.item_id JOIN produktionsauftrag pa ON pa.id = c.pa_id LEFT JOIN auftrag a ON a.id = c.auftrag_id
                    WHERE c.status = 'quarantaene' AND c.fremd_kunde_id IS NULL AND c.menge_verfuegbar > 0
                    ORDER BY c.id",
        'korrektur' => "UPDATE charge SET status='frei' WHERE id = <ID> AND status='quarantaene';   (charge_quarantaene_freigeben: + Bericht/CoA)",
        'fix'   => ['tabelle' => 'charge', 'typ' => 'charge',
                    'aktion' => function (array $r) {
                        if (!charge_quarantaene_freigeben((int)$r['_id'])) return 0;
                        aenderung_protokollieren('charge', (int)$r['_id'], ['status' => 'quarantaene'], ['status' => 'frei'], 'datenpruefung');
                        bedarf_bump();
                        return 1;
                    }],
    ],
    'quarantaene_wareneingang' => [
        'titel' => 'Lager: Ware aus dem Wareneingang steht noch in Quarantäne',
        'info'  => 'Seit der Soll-Kette (04.11.2026) ist eingebuchte Ware frei; Quarantäne nur noch bei bewusst gewähltem Problem. Diese Chargen kamen noch nach der alten Regel in Quarantäne (Altbestand). Eigene Fertigware aus der Produktion ist hier nicht dabei. Beheben gibt die Charge frei (wie Lager → Freigaben), protokolliert.',
        'sql'   => "SELECT c.id AS _id, CONCAT('?p=chargen&id=', c.id) AS _link, i.name AS artikel, c.charge_nr, c.menge_verfuegbar AS menge, c.einheit,
                           COALESCE(c.wareneingang, DATE(c.angelegt)) AS eingang,
                           (SELECT b.name FROM lg_bewegung lb JOIN benutzer b ON b.id = lb.benutzer_id WHERE lb.charge_id = c.id ORDER BY lb.id LIMIT 1) AS eingebucht_von,
                           l.firma AS lieferant
                    FROM charge c JOIN item i ON i.id = c.item_id LEFT JOIN lieferanten l ON l.id = c.lieferant_id
                    WHERE c.status = 'quarantaene' AND c.fremd_kunde_id IS NULL AND c.menge_verfuegbar > 0 AND c.pa_id IS NULL
                    ORDER BY COALESCE(c.wareneingang, DATE(c.angelegt)), i.name",
        'korrektur' => "UPDATE charge SET status='frei' WHERE id = <ID> AND status='quarantaene';   (charge_quarantaene_freigeben)",
        'fix'   => ['tabelle' => 'charge', 'typ' => 'charge',
                    'aktion' => function (array $r) {
                        if (!charge_quarantaene_freigeben((int)$r['_id'])) return 0;
                        aenderung_protokollieren('charge', (int)$r['_id'], ['status' => 'quarantaene'], ['status' => 'frei'], 'datenpruefung');
                        bedarf_bump();
                        return 1;
                    }],
    ],
    'charge_ohne_kapselgroesse' => [
        'titel' => 'Lager: Kapsel-Charge ohne Kapselgröße',
        'info'  => 'Bulkware aus Kapsel-/Softgel-Rezepturen und Leerkapseln sollen die Kapselgröße an der Charge tragen (Soll-Kette 2d), damit niemand Kisten öffnen muss. Ableitbar = aus dem Auftrag der Charge, sonst aus der Rezeptur (eine Größe für alle Rezepturen am Bulk-Artikel) bzw. vom Leerkapsel-Artikel. Beheben trägt die abgeleitete Größe nach. Nicht anwählbare Zeilen sind wirklich unklar (Rezeptur in mehreren Größen, kein Auftrag) – nur diese Kisten muss das Lager prüfen und die Größe auf der Charge eintragen.',
        'sql'   => "SELECT c.id AS _id, CONCAT('?p=chargen&id=', c.id) AS _link, i.name AS artikel, c.charge_nr, c.menge_verfuegbar AS menge, c.einheit,
                           a.nummer AS auftrag, IF(c.auftrag_id IS NULL, NULL, CONCAT('?p=auftrag&id=', c.auftrag_id)) AS _link_auftrag
                    FROM charge c JOIN item i ON i.id = c.item_id LEFT JOIN auftrag a ON a.id = c.auftrag_id
                    WHERE c.kapselgroesse_id IS NULL AND c.menge_verfuegbar > 0 AND c.fremd_kunde_id IS NULL
                      AND ( i.form = 'kapselhuelle'
                         OR (i.kategorie = 'fertig' AND EXISTS (SELECT 1 FROM rezeptur r WHERE (r.id = i.rezeptur_id OR r.stamm_rezeptur_id = i.rezeptur_id)
                                                               AND r.darreichungsform IN ('kapsel','softgel'))) )
                    ORDER BY i.name, c.id",
        'korrektur' => "UPDATE charge SET kapselgroesse_id = <abgeleitete Größe> WHERE id = <ID> AND kapselgroesse_id IS NULL;   (charge_kapselgroesse_setzen)",
        'ohne_fix'  => 'Nicht anwählbar = Größe nicht eindeutig ableitbar: im Lager prüfen.',
        'fix'   => ['tabelle' => 'charge', 'typ' => 'charge',
                    'waehlbar' => fn(array $r) => charge_kapselgroesse_ermitteln((int) scalar("SELECT item_id FROM charge WHERE id=?", [(int)$r['_id']]), (int) scalar("SELECT COALESCE(auftrag_id,0) FROM charge WHERE id=?", [(int)$r['_id']])) > 0,
                    'aktion' => function (array $r) {
                        $g = charge_kapselgroesse_setzen((int)$r['_id']);
                        if ($g <= 0) return 0;
                        aenderung_protokollieren('charge', (int)$r['_id'], ['kapselgroesse_id' => null], ['kapselgroesse_id' => $g], 'datenpruefung');
                        return 1;
                    }],
    ],
    'bulk_artikel_lose' => [
        'titel' => 'Lager: loser Bulk-Artikel ohne Rezeptur (z. B. „… – Bulk #0“)',
        'info'  => 'Bulkware gehört auf den Bulk-Artikel ihrer Rezeptur (Soll-Kette 2c) – sonst sieht der Auftrag die Ware nicht. Vorschlag aus dem Auftrag der Chargen, sonst über den Rezepturnamen. Beheben führt den losen Artikel in den Rezeptur-Artikel zusammen (Chargen + Reservierungen wandern, der lose Artikel wird deaktiviert, nicht gelöscht). Nur bei eindeutigem Vorschlag und gleicher Einheit anwählbar.',
        'sql'   => "SELECT x.*, z.name AS ziel, z.einheit AS ziel_einheit, CONCAT('?p=rohstoff&id=', x._ziel_id) AS _link_ziel
                    FROM (SELECT i.id AS _id, CONCAT('?p=rohstoff&id=', i.id) AS _link, i.name AS artikel, i.einheit,
                                 (SELECT COALESCE(SUM(c.menge_verfuegbar),0) FROM charge c WHERE c.item_id = i.id) AS bestand,
                                 COALESCE(
                                   (SELECT IF(COUNT(DISTINCT bi.id) = 1, MIN(bi.id), NULL) FROM charge c JOIN auftrag a ON a.id = c.auftrag_id LEFT JOIN produkt p ON p.id = a.produkt_id
                                      JOIN rezeptur r ON r.id = COALESCE(a.rezeptur_id, p.rezeptur_id)
                                      JOIN item bi ON bi.rezeptur_id = COALESCE(NULLIF(r.stamm_rezeptur_id,0), r.id) AND bi.kategorie = 'fertig'
                                     WHERE c.item_id = i.id),
                                   (SELECT IF(COUNT(DISTINCT bi.id) = 1, MIN(bi.id), NULL) FROM rezeptur r
                                      JOIN item bi ON bi.rezeptur_id = COALESCE(NULLIF(r.stamm_rezeptur_id,0), r.id) AND bi.kategorie = 'fertig'
                                     WHERE r.name <> '' AND i.name LIKE CONCAT(r.name, '%'))
                                 ) AS _ziel_id
                          FROM item i
                          WHERE i.kategorie = 'fertig' AND i.rezeptur_id IS NULL AND COALESCE(i.gesperrt,0) = 0
                            AND EXISTS (SELECT 1 FROM charge c WHERE c.item_id = i.id)) x
                    LEFT JOIN item z ON z.id = x._ziel_id
                    ORDER BY x.artikel",
        'korrektur' => "-- je Zeile: artikel_zusammenfuehren_voll(<ID>, <Ziel>) - alle Bezüge (Chargen, Reservierungen, Bestellungen, Preise …) auf das Ziel, Doppel-Artikel gesperrt; gesperrt bei anderer Kapselgröße/Füllgewicht/Material/Einheit",
        'ohne_fix'  => 'Ohne eindeutigen Vorschlag oder mit anderer Einheit bitte auf der Artikelseite von Hand zusammenführen.',
        'fix'   => ['tabelle' => 'item', 'typ' => 'item',
                    'waehlbar' => fn(array $r) => !empty($r['_ziel_id']) && (string)$r['einheit'] === (string)$r['ziel_einheit'],
                    'aktion' => function (array $r) {
                        // Block D (11.11.2026): derselbe Weg wie die Seite „Artikel zusammenführen" (mit Sperre), nicht mehr der alte.
                        require_once BX_ROOT . '/core/artikel_dubletten.php';
                        $res = artikel_zusammenfuehren_voll((int)$r['_id'], (int)$r['_ziel_id'], (string)(current_user()['name'] ?? 'Datenprüfung'));
                        if (!$res['ok']) return 0;
                        aenderung_protokollieren('item', (int)$r['_id'], ['zusammengefuehrt_in' => null], ['zusammengefuehrt_in' => (int)$r['_ziel_id']], 'datenpruefung');
                        return 1;
                    }],
    ],
    'pa_schritt_produktionsfreigabe' => [
        'titel' => 'Produktion: offener Schritt „Produktions-Freigabe“ (entfällt)',
        'info'  => 'Seit der Soll-Kette (Nico/Dustin 04.11.2026) gibt es keine eigene Produktions-Freigabe mehr: Material da = startbar, gesteuert über den Auftrag. Neue Produktionsaufträge bekommen den Schritt nicht mehr. Bestehende haben ihn noch offen und würden daran hängen bleiben. Beheben hakt den Schritt als „entfällt“ ab (nichts wird gelöscht, nichts gebucht).',
        'sql'   => "SELECT s.id AS _id, CONCAT('?p=produktionsauftrag&id=', pa.id) AS _link, pa.nummer, pa.status, a.nummer AS auftrag,
                           (SELECT s2.station FROM produktion_schritt s2 WHERE s2.pa_id=pa.id AND s2.erledigt=0 ORDER BY s2.sort, s2.id LIMIT 1) AS aktueller_schritt
                    FROM produktion_schritt s
                    JOIN produktionsauftrag pa ON pa.id = s.pa_id
                    LEFT JOIN auftrag a ON a.id = pa.auftrag_id
                    WHERE s.station = 'Produktions-Freigabe' AND s.erledigt = 0 AND pa.status NOT IN ('erledigt','storniert')
                    ORDER BY pa.id DESC",
        'korrektur' => "UPDATE produktion_schritt SET erledigt=1, erledigt_at=UTC_TIMESTAMP(), erledigt_von='entfällt (Soll-Kette 2e)' WHERE id = <ID> AND station='Produktions-Freigabe' AND erledigt=0;",
        'fix'   => ['tabelle' => 'produktion_schritt', 'typ' => 'produktion_schritt',
                    'aktion' => function (array $r) {
                        $n = q("UPDATE produktion_schritt SET erledigt=1, erledigt_at=UTC_TIMESTAMP(), erledigt_von='entfällt (Soll-Kette 2e)'
                                WHERE id=? AND station='Produktions-Freigabe' AND erledigt=0", [(int)$r['_id']])->rowCount();
                        if ($n) aenderung_protokollieren('produktion_schritt', (int)$r['_id'], ['erledigt' => 0], ['erledigt' => 1], 'datenpruefung');
                        return $n;
                    }],
    ],
    'rohstoff_leerkapsel_form' => [
        'titel' => 'Rohstoff: sieht nach Leerkapseln aus, ist aber nicht so eingestuft',
        'info'  => 'Name (Leerkapsel, Kapselhülle, Kapsel mit HPMC/Gelatine/Pullulan oder mit Größe „Gr. 0“) oder eine gespeicherte Kapselgröße zeigen Leerkapseln, die Form ist aber nicht „Kapselhülle“. Dann blendet die Rohstoff-Seite Kapselgröße und Material aus, und das System ordnet die Kapsel keinem Produkt zu. Häufige Ursache: in der Lager-App mit Warenart „Rohstoff“ eingebucht (bis 04.11.2026 löschte das die Form). Beheben setzt die Form auf Kapselhülle und die Kapselgröße aus dem Namen, wenn noch keine gespeichert ist.',
        'sql'   => "SELECT i.id AS _id, CONCAT('?p=rohstoff&id=', i.id) AS _link, i.artikelnummer, i.name, NULLIF(i.form,'') AS form, kg.name AS kapselgroesse, i.einheit
                    FROM item i LEFT JOIN kapselgroesse kg ON kg.id = i.kapselgroesse_id
                    WHERE i.kategorie = 'rohstoff' AND COALESCE(i.form,'') <> 'kapselhuelle' AND COALESCE(i.gesperrt,0) = 0
                      AND (i.kapselgroesse_id IS NOT NULL OR i.name LIKE '%leerkapsel%' OR i.name LIKE '%kapselhülle%' OR i.name LIKE '%kapselhuelle%'
                           OR (i.name LIKE '%kapsel%' AND (i.name LIKE '%hpmc%' OR i.name LIKE '%gelatine%' OR i.name LIKE '%pullulan%'
                               OR i.name REGEXP '(gr|größe|grösse|groesse|size|#)[. ]*(000|00|0|1|2|3|4|5)([^0-9]|$)')))
                    ORDER BY i.name",
        'korrektur' => "UPDATE item SET form='kapselhuelle', kapselgroesse_id=COALESCE(kapselgroesse_id, <Größe aus Name>) WHERE id = <ID>;",
        'fix'   => ['tabelle' => 'item', 'typ' => 'item',
                    'aktion' => function (array $r) {
                        $alt = one("SELECT form, kapselgroesse_id, name FROM item WHERE id=?", [(int)$r['_id']]);
                        if (!$alt) return 0;
                        $kg = (int)($alt['kapselgroesse_id'] ?? 0) ?: kapselgroesse_aus_name((string)$alt['name']);
                        q("UPDATE item SET form='kapselhuelle', kapselgroesse_id=? WHERE id=?", [$kg ?: null, (int)$r['_id']]);
                        aenderung_protokollieren('item', (int)$r['_id'], ['form' => $alt['form'], 'kapselgroesse_id' => $alt['kapselgroesse_id']], ['form' => 'kapselhuelle', 'kapselgroesse_id' => $kg ?: null], 'datenpruefung');
                        return 1;
                    }],
    ],
    'auftrag_name_behaelter' => [
        'titel' => 'Auftrag: Name nennt einen anderen Behälter als der Auftrag',
        'info'  => 'Der Auftrag speichert den Produktnamen zur Auftragszeit (Kopf, AB-PDF). Wurde der Behälter danach geändert, steht oben z. B. „100 ml Glas“, der Auftrag läuft aber im 150 ml Glas. Maßgeblich ist der Behälter am Auftrag, sonst der am Produkt. Beheben tauscht nur den Behälter-Teil im Auftrags-Namen. Bedruckte Kundenbehälter sind ausgenommen.',
        'sql'   => "SELECT a.id AS _id, CONCAT('?p=auftrag&id=', a.id) AS _link, a.nummer, a.status, kd.firma AS kunde, a.produkt_bezeichnung AS name_im_auftrag,
                           vi.name AS behaelter_im_namen, vc.name AS behaelter_des_auftrags
                    FROM auftrag a
                    LEFT JOIN produkt p ON p.id = a.produkt_id
                    LEFT JOIN kunden kd ON kd.id = a.kunde_id
                    JOIN item vc ON vc.id = COALESCE(NULLIF(a.verpackung_id,0), p.verpackung_id)
                    JOIN item vi ON vi.kategorie = 'verpackung' AND COALESCE(vi.verpackung_rolle,'primaer') = 'primaer' AND vi.id <> vc.id
                                AND CHAR_LENGTH(vi.name) >= 3 AND INSTR(a.produkt_bezeichnung, CONCAT(' · ', vi.name)) > 0
                    WHERE a.status <> 'storniert' AND INSTR(a.produkt_bezeichnung, CONCAT(' · ', vc.name)) = 0 AND COALESCE(vc.kundenspezifisch,0) = 0
                    ORDER BY a.id DESC, CHAR_LENGTH(vi.name) DESC",
        'zeilen' => function (array $rows): array {
            $out = [];
            foreach ($rows as $r) if (!isset($out[(int)$r['_id']])) $out[(int)$r['_id']] = $r;
            foreach ($out as &$r) {
                $neu = preg_replace('/ · ' . preg_quote((string)$r['behaelter_im_namen'], '/') . '(?=$|\s+v\d+$)/u', ' · ' . $r['behaelter_des_auftrags'], (string)$r['name_im_auftrag'], 1);
                $r['neuer_name'] = $neu !== (string)$r['name_im_auftrag'] ? $neu : null;
                $r['aenderung'] = $r['neuer_name'] ? 'Name -> ' . $r['neuer_name'] : 'nicht automatisch: Behälter steht nicht am Namensende';
            }
            return array_values($out);
        },
        'korrektur' => "-- je angehaktem Auftrag: Behälter-Teil im Auftrags-Namen auf den Behälter des Auftrags ziehen\nUPDATE auftrag SET produkt_bezeichnung = <neuer_name> WHERE id = <Auftrag>;   (auftrag_name_behaelter_nachziehen)",
        'ohne_fix'  => 'Nur der gespeicherte Auftrags-Name ändert sich (Kopf, Listen, AB-PDF beim nächsten Öffnen). Ist der Behälter am Auftrag falsch, im Auftrag unter Verpackung korrigieren statt hier zu beheben.',
        'fix'   => ['tabelle' => 'auftrag', 'typ' => 'auftrag',
                    'waehlbar' => fn(array $r) => !empty($r['neuer_name']),
                    'aktion' => fn(array $r) => auftrag_name_behaelter_nachziehen((int)$r['_id'], 'datenpruefung') !== '' ? 1 : 0],
    ],
    'pulver_kundenpreis' => [
        'titel' => 'Pulver: Kundenpreis mit Portionen statt g je Packung',
        'info'  => 'Bei Pulver/Granulat ist die Menge je Packung die Füllmenge in g. Alte Zeilen (v3) tragen die Portionszahl (z. B. 31 statt 275 g). Treffer: Menge kleiner als die Füllmenge des Produkts UND Menge × Portionsgröße liegt höchstens 25 % neben der Füllmenge (dann sind es Portionen). Korrektur: Menge = Füllmenge des Produkts.',
        'sql'   => "SELECT k.id AS _id, CONCAT('?p=produkt&id=', p.id) AS _link, p.nummer, p.name AS produkt, kd.firma AS kunde,
                           k.menge_pro_vpe AS menge_jetzt, ROUND(x.mg/1000, 2) AS portion_g, ROUND(k.menge_pro_vpe*x.mg/1000) AS als_portionen_g,
                           p.einheiten_pro_packung AS fuellmenge_g, k.preis, k.notiz,
                           (SELECT k2.id FROM produkt_kundenpreis k2 WHERE k2.produkt_id=k.produkt_id AND k2.kunde_id=k.kunde_id
                              AND k2.menge_pro_vpe=p.einheiten_pro_packung AND COALESCE(k2.anzahl_vpe,0)=COALESCE(k.anzahl_vpe,0) AND k2.id<>k.id LIMIT 1) AS _konflikt
                    FROM produkt_kundenpreis k
                    JOIN produkt p ON p.id = k.produkt_id
                    JOIN rezeptur r ON r.id = p.rezeptur_id
                    JOIN (SELECT rezeptur_id, SUM(menge_mg) AS mg FROM rezeptur_zutat GROUP BY rezeptur_id) x ON x.rezeptur_id = r.id
                    LEFT JOIN kunden kd ON kd.id = k.kunde_id
                    WHERE r.darreichungsform IN ('pulver','granulat') AND x.mg > 0 AND k.menge_pro_vpe > 0
                      AND p.einheiten_pro_packung > k.menge_pro_vpe
                      AND ABS(k.menge_pro_vpe*x.mg/1000 - p.einheiten_pro_packung) <= 0.25*p.einheiten_pro_packung
                    ORDER BY p.id, k.id",
        'zeilen' => function (array $rows): array {
            foreach ($rows as &$r) $r['aenderung'] = $r['_konflikt'] ? 'nicht automatisch: Zeile mit ' . $r['fuellmenge_g'] . ' g gibt es schon (#' . $r['_konflikt'] . ')' : 'menge ' . $r['menge_jetzt'] . ' -> ' . $r['fuellmenge_g'] . ' g';
            return $rows;
        },
        'korrektur' => "-- je angehakter Zeile: Menge je Packung = Füllmenge des Produkts (g), nur wenn die Menge noch die alte ist\nUPDATE produkt_kundenpreis SET menge_pro_vpe = <fuellmenge_g> WHERE id = <Zeile> AND menge_pro_vpe = <menge_jetzt>;",
        'ohne_fix'  => 'Zeilen, deren Zielgröße es für Produkt + Kunde schon gibt, sind nicht anwählbar (nichts zusammenlegen, nichts löschen) – dort die alte Zeile auf der Produktseite entfernen.',
        'fix'   => ['tabelle' => 'produkt_kundenpreis', 'typ' => 'produkt_kundenpreis',
                    'waehlbar' => fn(array $r) => empty($r['_konflikt']),
                    'update' => "UPDATE produkt_kundenpreis SET menge_pro_vpe = ? WHERE id = ? AND menge_pro_vpe = ?",
                    'params' => fn(array $r) => [(int)$r['fuellmenge_g'], (int)$r['_id'], (int)$r['menge_jetzt']]],
    ],
    'pulver_auftrag' => [
        'titel' => 'Pulver: Auftrag mit Portionen statt g je Packung',
        'info'  => 'Gleiche Regel für auftrag.stueck: v3-Pulveraufträge tragen teils die Portionszahl. Dann rechnet der Bedarf zu niedrig und die Kundenpreis-Liste zeigt z. B. „31 g". Korrigieren über den Produktionsauftrag, Panel „Füllmenge je Packung (Pulver)“. Es setzt Produkt, Auftrag und PA zusammen.',
        'sql'   => "SELECT a.id AS _id, CONCAT('?p=auftrag&id=', a.id) AS _link, a.nummer, a.status, p.nummer AS produkt_nr, p.name AS produkt, kd.firma AS kunde,
                           a.stueck AS stueck_jetzt, ROUND(x.mg/1000, 2) AS portion_g, ROUND(a.stueck*x.mg/1000) AS als_portionen_g, p.einheiten_pro_packung AS fuellmenge_g,
                           (SELECT pa.id FROM produktionsauftrag pa WHERE pa.auftrag_id=a.id ORDER BY pa.id LIMIT 1) AS pa_id,
                           (SELECT CONCAT('?p=produktionsauftrag&id=', pa.id) FROM produktionsauftrag pa WHERE pa.auftrag_id=a.id ORDER BY pa.id LIMIT 1) AS _link_pa_id
                    FROM auftrag a
                    JOIN produkt p ON p.id = a.produkt_id
                    JOIN rezeptur r ON r.id = p.rezeptur_id
                    JOIN (SELECT rezeptur_id, SUM(menge_mg) AS mg FROM rezeptur_zutat GROUP BY rezeptur_id) x ON x.rezeptur_id = r.id
                    LEFT JOIN kunden kd ON kd.id = a.kunde_id
                    WHERE r.darreichungsform IN ('pulver','granulat') AND x.mg > 0 AND a.stueck > 0 AND a.status <> 'storniert'
                      AND p.einheiten_pro_packung > a.stueck
                      AND ABS(a.stueck*x.mg/1000 - p.einheiten_pro_packung) <= 0.25*p.einheiten_pro_packung
                    ORDER BY a.id DESC",
        'korrektur' => "-- je angehaktem Auftrag: Füllmenge = Füllmenge des Produkts (g), nur wo noch die alte Zahl steht
UPDATE auftrag SET stueck = <fuellmenge_g> WHERE id = <Auftrag> AND stueck = <stueck_jetzt>;
UPDATE produktionsauftrag SET stueck = <fuellmenge_g> WHERE auftrag_id = <Auftrag> AND stueck = <stueck_jetzt>;",
        'ohne_fix'  => 'Auftrag und seine Produktionsaufträge werden zusammen umgestellt (pulver_auftrag_fuellmenge_korrigieren), jede Feld-Änderung landet im Protokoll; danach wird der Bedarf neu gerechnet.',
        'fix'   => ['tabelle' => 'auftrag', 'typ' => 'auftrag',
                    'aktion' => fn(array $r) => pulver_auftrag_fuellmenge_korrigieren((int)$r['_id'], (int)$r['stueck_jetzt'], (int)$r['fuellmenge_g'], 'datenpruefung')],
    ],
    'etikett_mass_abweichung' => [
        'titel' => 'Etikett: Maß weicht vom Behälter-Endformat ab',
        'info'  => 'Aktive Kundenetikett-Artikel, deren gespeichertes Maß (breite_mm/hoehe_mm bzw. etikett_final) um MEHR ALS 5 mm vom Endformat des TATSÄCHLICHEN Produkt-Behälters abweicht (Behälter = jüngster Auftrags-Behälter mit Vorrang, sonst Produkt-Behälter). 2 bis 5 mm sind normal (Sicherheitsmaß, Nico 11.11.2026) und erscheinen nicht. '
                 . 'Beheben setzt das Maß aus der TrimBox (sonst ArtBox/MediaBox) des hinterlegten Etikett-Designs – also das echte Etikett-Endformat, nicht das Glasmaß (Sofortfix 11.11.2026). Ohne Design-PDF gibt es keinen Knopf.',
        'sql'   => "SELECT i.id AS _id, i.produkt_id AS _pid, CONCAT('?p=verpackung&id=', i.id) AS _link, i.artikelnummer, i.name AS etikett,
                           i.breite_mm AS _breite_mm, i.hoehe_mm AS _hoehe_mm, i.etikett_final AS _etikett_final, i.etikett_vorlage_id AS _vorlage_alt,
                           p.nummer AS produkt_nr, p.name AS produkt, CONCAT('?p=produkt&id=', p.id) AS _link_produkt, k.firma AS kunde,
                           d.id AS _dok_id, d.datei AS _datei
                    FROM item i
                    JOIN produkt p ON p.id = i.produkt_id
                    LEFT JOIN kunden k ON k.id = i.kunde_id
                    LEFT JOIN dokument d ON d.id = i.etikett_dokument_id
                    WHERE i.verpackung_rolle = 'etikett' AND i.produkt_id IS NOT NULL AND COALESCE(i.gesperrt,0) = 0
                    ORDER BY i.id DESC",
        'zeilen' => function (array $rows): array {
            // Je Zeile: Endformat des tatsächlichen Behälters gegen das eigene Maß des Etikett-Artikels halten.
            // Maße zum Vergleich auf [groß, klein] normieren (Orientierung B x H / H x B egal), Toleranz 2 mm.
            $out = [];
            foreach ($rows as $r) {
                $pid = (int)$r['_pid'];
                $beh = produkt_etikettmass($pid);                 // [groß, klein] Endformat aus dem Behälter, sonst null
                if (!$beh) continue;                              // ohne Behälter-Endformat gibt es nichts zu vergleichen
                $eigen = (!empty($r['_breite_mm']) && !empty($r['_hoehe_mm']))
                       ? [(float)$r['_breite_mm'], (float)$r['_hoehe_mm']]
                       : etikett_masse((string)($r['_etikett_final'] ?? ''));
                $eigenN = $eigen ? [max($eigen[0], $eigen[1]), min($eigen[0], $eigen[1])] : null;
                $abw = $eigenN ? max(abs($eigenN[0] - $beh[0]), abs($eigenN[1] - $beh[1])) : null;
                if ($eigenN !== null && $abw <= 5.0) continue;    // bis 5 mm = Sicherheitsmaß, normal (Nico 11.11.2026)
                // Ziel = Etikett-Endformat aus dem Design (TrimBox), nie das Glasmaß (Sofortfix 11.11.2026, Fable 3.6).
                $pfad = !empty($r['_datei']) ? BX_UPLOADS . '/' . basename((string)$r['_datei']) : '';
                $em = $pfad !== '' && is_file($pfad) ? pdf_etikett_mass($pfad) : null;
                $r['etikett_mass']        = $eigenN ? ((float)$eigenN[0] . ' x ' . (float)$eigenN[1] . ' mm') : 'kein Maß';
                $r['behaelter_endformat'] = (float)$beh[0] . ' x ' . (float)$beh[1] . ' mm';
                $r['abweichung_mm']       = $eigenN ? round((float)$abw, 1) : null;
                $r['_fixbar']             = $em !== null;
                $r['aenderung']           = $em ? ('Maß -> ' . $em['label'] . ' (' . $em['quelle'] . ' des Designs)') : 'kein Design-PDF: Maß von Hand prüfen (kein Knopf)';
                $out[] = $r;
            }
            return $out;
        },
        'korrektur' => "-- je angehaktem Etikett: Maß aus der TrimBox des Designs (etikett_mass_aus_pdf_setzen)\nUPDATE item SET breite_mm=<B>, hoehe_mm=<H>, etikett_format='<B> x <H> mm', etikett_final='<B> x <H>', etikett_druck=<MediaBox> WHERE id=<Etikett>;",
        'ohne_fix'  => 'Ändert nur die Maß-Felder des Etikett-Artikels (aus dem eigenen Design-PDF), nicht das Design und keine Aufträge. Zeilen ohne Design-PDF sind nicht anwählbar. Weicht das Design-Maß trotzdem stark vom Glas ab, ist eher der Behälter am Produkt/Auftrag falsch – dort korrigieren.',
        'fix'   => ['tabelle' => 'item', 'typ' => 'item',
                    'waehlbar' => fn(array $r) => !empty($r['_fixbar']),
                    'aktion' => function (array $r): int {
                        // Sofortfix 11.11.2026: Maß aus der TrimBox des eigenen Designs (gleiche Funktion wie bei der Freigabe).
                        $dok = !empty($r['_dok_id']) ? one("SELECT * FROM dokument WHERE id=?", [(int)$r['_dok_id']]) : null;
                        return $dok && etikett_mass_aus_pdf_setzen((int)$r['_id'], $dok) ? 1 : 0;
                    }],
    ],
    // Sofort-Fix Etikett-Format (08.11.2026): Maß laut Druckdatei (TrimBox, sonst ArtBox, sonst MediaBox) gegen das am
    // Etikett-Artikel gespeicherte Maß. Nur zur Info – Nico passt die Maße mit der Zeit selbst an, kein automatisches Beheben.
    'etikett_mass_trimbox' => [
        'titel' => 'Etikett: Maß laut Druckdatei (TrimBox) anders als gespeichert (nur Info)',
        'info'  => 'Aktive Etikett-Artikel mit hinterlegtem Design (PDF). Verglichen wird das gespeicherte Maß (breite_mm/hoehe_mm) mit dem Endformat der Datei: '
                 . 'TrimBox, ohne TrimBox die ArtBox, sonst die MediaBox (Seite inkl. Beschnitt). Ausrichtung egal, Abweichung ab 1 mm. '
                 . 'Neue Designs übernehmen das Maß seit 08.11.2026 bei der Freigabe automatisch; bestehende Etiketten werden nicht geändert. '
                 . 'Abweichungen sperren nichts (Soll-Handbuch 4.5).',
        'sql'   => "SELECT i.id AS _id, CONCAT('?p=verpackung&id=', i.id) AS _link, i.artikelnummer, i.name AS etikett, k.firma AS kunde,
                           i.breite_mm AS _b, i.hoehe_mm AS _h, d.datei AS _datei
                    FROM item i
                    JOIN dokument d ON d.id = i.etikett_dokument_id
                    LEFT JOIN kunden k ON k.id = i.kunde_id
                    WHERE i.verpackung_rolle = 'etikett' AND COALESCE(i.gesperrt,0) = 0
                    ORDER BY i.id DESC",
        'zeilen' => function (array $rows): array {
            $out = [];
            $z = fn($x) => rtrim(rtrim(number_format((float)$x, 1, ',', ''), '0'), ',');
            foreach ($rows as $r) {
                $pfad = BX_UPLOADS . '/' . basename((string)$r['_datei']);
                $em = is_file($pfad) ? pdf_etikett_mass($pfad) : null;
                if (!$em) continue;                                   // kein PDF / keine Box lesbar
                $pg = max($em['w'], $em['h']); $pk = min($em['w'], $em['h']);
                $b = (float)($r['_b'] ?? 0); $h = (float)($r['_h'] ?? 0);
                $abw = ($b > 0 && $h > 0) ? max(abs(max($b, $h) - $pg), abs(min($b, $h) - $pk)) : null;
                if ($abw !== null && $abw < 1.0) continue;
                $mb = pdf_boxen($pfad)['MediaBox'] ?? null;
                $r['gespeichert']    = ($b > 0 && $h > 0) ? ($z($b) . ' x ' . $z($h) . ' mm') : 'kein Maß';
                $r['laut_pdf']       = $z($pk) . ' x ' . $z($pg) . ' mm';
                $r['quelle']         = $em['quelle'];
                $r['druckdatei']     = $mb ? ($z(min($mb)) . ' x ' . $z(max($mb)) . ' mm') : '';
                $r['abweichung_mm']  = $abw !== null ? round($abw, 1) : null;
                $out[] = $r;
            }
            return $out;
        },
        'korrektur' => '– (nur anzeigen; Maß bei Bedarf am Etikett-Artikel unter Verpackung anpassen)',
    ],
    // Gewicht & Bestand pro Gebinde (04.11.2026): Altbestand mit gleichmäßig verteiltem Gewicht je Karton.
    'gebinde_gleich_verteilt' => [
        'titel' => 'Lager: Gebinde mit gleichmäßig verteiltem Gewicht (nachwiegen)',
        'info'  => 'Bis 04.11.2026 hat das System die Menge einer Charge gleichmäßig auf die Kartons verteilt. Diese Gebinde tragen also kein echtes Gewicht. Beim nächsten Anfassen den Karton scannen und im Lager unter „Nachwiegen“ das Ist-Gewicht eintragen (Öffnen führt auf das Gebinde) – das korrigiert Gebinde und Charge und steht im Änderungslog. Beheben hier bestätigt nur, dass das angezeigte Gewicht stimmt (ohne Änderung der Menge). Etikettenrollen sind ausgenommen.',
        'sql'   => "SELECT g.id AS _id, CONCAT('/lager/?p=gebinde&bg=', g.id) AS _link, i.name AS artikel, c.charge_nr,
                           CONCAT(g.nummer, ' / ', g.anzahl_gesamt) AS gebinde, g.menge AS verteilt, g.menge_verfuegbar AS aktuell, g.einheit,
                           CONCAT('?p=chargen&id=', c.id) AS _link_charge_nr
                    FROM charge_gebinde g JOIN charge c ON c.id = g.charge_id JOIN item i ON i.id = c.item_id
                    WHERE COALESCE(g.gewogen, 0) = 0 AND g.inventar_nr IS NULL AND g.status <> 'leer' AND g.anzahl_gesamt > 1
                    ORDER BY i.name, c.id, g.nummer",
        'korrektur' => "-- Nachwiegen im Lager (?p=gebinde&bg=<ID>): UPDATE charge_gebinde SET menge_verfuegbar=<gewogen>, gewogen=1 …; Charge um die Differenz. Beheben hier: UPDATE charge_gebinde SET gewogen=1 WHERE id=<ID>;",
        'fix'   => ['tabelle' => 'charge_gebinde', 'typ' => 'charge_gebinde',
                    'aktion' => function (array $r) {
                        q("UPDATE charge_gebinde SET gewogen=1, gewogen_am=? WHERE id=? AND COALESCE(gewogen,0)=0", [gmdate('Y-m-d H:i:s'), (int)$r['_id']]);
                        aenderung_protokollieren('charge_gebinde', (int)$r['_id'], ['gewogen' => 0], ['gewogen' => 1], 'datenpruefung');
                        return 1;
                    }],
    ],
    'gebinde_summe_abweichung' => [
        'titel' => 'Lager: Chargenbestand passt nicht zur Summe der Gebinde',
        'info'  => 'Die Charge ist die Summe ihrer Gebinde. Wurde der Bestand früher nur an der Charge geändert (Entnahme, Korrektur), stehen die Gebinde noch auf altem Stand. Beheben zieht die Gebinde an die Charge an: weniger Bestand zuerst von den Gebinden in der Produktion und den angebrochenen, mehr Bestand aufs erste davon. Danach betroffene Kartons beim nächsten Anfassen nachwiegen.',
        'sql'   => "SELECT c.id AS _id, CONCAT('?p=chargen&id=', c.id) AS _link, i.name AS artikel, c.charge_nr, c.menge_verfuegbar AS charge, x.summe AS gebinde_summe,
                           ROUND(x.summe - c.menge_verfuegbar, 3) AS differenz, c.einheit, x.n AS gebinde
                    FROM charge c JOIN item i ON i.id = c.item_id
                    JOIN (SELECT charge_id, SUM(menge_verfuegbar) AS summe, COUNT(*) AS n FROM charge_gebinde GROUP BY charge_id) x ON x.charge_id = c.id
                    WHERE ABS(x.summe - c.menge_verfuegbar) > 0.0005
                    ORDER BY i.name, c.id",
        'korrektur' => "-- je Zeile: charge_gebinde_abgleich(<ID>) – Gebinde-Restmengen an charge.menge_verfuegbar angleichen.",
        'fix'   => ['tabelle' => 'charge', 'typ' => 'charge',
                    'aktion' => function (array $r) {
                        $vor = all("SELECT id, menge_verfuegbar, status FROM charge_gebinde WHERE charge_id=? ORDER BY id", [(int)$r['_id']]);
                        charge_gebinde_abgleich((int)$r['_id']);
                        $nach = all("SELECT id, menge_verfuegbar, status FROM charge_gebinde WHERE charge_id=? ORDER BY id", [(int)$r['_id']]);
                        $n = 0;
                        foreach ($vor as $k => $v) $n += aenderung_protokollieren('charge_gebinde', (int)$v['id'], $v, $nach[$k] ?? $v, 'datenpruefung') > 0 ? 1 : 0;
                        return $n > 0 ? 1 : 0;
                    }],
    ],
    // ---- Produktseite (Nico 09.11.2026, Arbeitsanweisung Produktseite Teil 8): Widersprüche am Produkt ----
    'prod_entwurf_verwendet' => [
        'titel' => 'Produkt im Status „Entwurf", obwohl es in Angeboten/Aufträgen/Rechnungen steckt',
        'info'  => 'Beispiel P-3170: Entwurf, aber in 4 Angeboten und einer Rechnung. Beheben setzt das Produkt auf „aktiv". Nichts sonst wird geändert.',
        'sql'   => "SELECT p.id AS _id, CONCAT('?p=produkt&id=', p.id) AS _link, p.nummer, p.name, p.status,
                           (SELECT COUNT(*) FROM angebot a WHERE a.produkt_id = p.id OR a.id IN (SELECT angebot_id FROM angebot_produkt WHERE produkt_id = p.id)) AS angebote,
                           (SELECT COUNT(*) FROM auftrag au WHERE au.produkt_id = p.id AND au.status <> 'storniert') AS auftraege,
                           (SELECT COUNT(*) FROM beleg b JOIN auftrag au ON au.id = b.auftrag_id WHERE au.produkt_id = p.id AND b.typ = 'rechnung' AND b.status <> 'storniert') AS rechnungen
                    FROM produkt p
                    WHERE p.status = 'entwurf'
                      AND (EXISTS (SELECT 1 FROM angebot a WHERE a.produkt_id = p.id) OR EXISTS (SELECT 1 FROM angebot_produkt ap WHERE ap.produkt_id = p.id)
                           OR EXISTS (SELECT 1 FROM auftrag au WHERE au.produkt_id = p.id AND au.status <> 'storniert'))
                    ORDER BY p.id DESC",
        'korrektur' => "UPDATE produkt SET status='aktiv' WHERE status='entwurf' AND id IN (...);",
        'fix'   => ['tabelle' => 'produkt', 'typ' => 'produkt',
                    'update' => "UPDATE produkt SET status='aktiv' WHERE status='entwurf' AND id IN ({IDS})"],
    ],
    'prod_glas_ohne_deckel' => [
        'titel' => 'Glas/Dose/Flasche ohne Deckel am Produkt',
        'info'  => 'Produkte mit Behälter, aber ohne Verschluss (Beutel, Sachets, Sticks und Blister ausgenommen). Beispiel P-3170: 150 ml Weithalsglas, Deckel „keine". Den Deckel am Produkt per Stift (Kachel Deckel) setzen.',
        'sql'   => "SELECT p.id AS _id, CONCAT('?p=produkt&id=', p.id) AS _link, p.nummer, p.name, p.status, CONCAT('?p=verpackung&id=', i.id) AS _link_behaelter, i.name AS behaelter
                    FROM produkt p JOIN item i ON i.id = p.verpackung_id
                    WHERE p.verschluss_id IS NULL AND p.status <> 'inaktiv' AND COALESCE(p.extern, 0) = 0
                      AND LOWER(i.name) NOT LIKE '%beutel%' AND LOWER(i.name) NOT LIKE '%sachet%' AND LOWER(i.name) NOT LIKE '%stick%' AND LOWER(i.name) NOT LIKE '%blister%'
                    ORDER BY p.id DESC",
        'korrektur' => '',
        'ohne_fix'  => 'Kein automatisches Beheben: welcher Deckel passt, entscheidet das Team je Behälter.',
        'fix'   => null,
    ],
    'kundenpreis_packgroesse' => [
        'titel' => 'Kundenpreis mit anderer Menge je Packung als das Produkt',
        'info'  => 'Beispiel Ikigai: Kundenpreis für 60 Kapseln an einem 90-Kapsel-Produkt. Laut Handbuch ist eine andere Packungsgröße ein eigenes Produkt – Nico entscheidet je Fall. Nur Anzeige.',
        'sql'   => "SELECT kp.id AS _id, CONCAT('?p=produkt&id=', p.id, '&tab=kunden') AS _link, p.nummer, p.name, p.einheiten_pro_packung AS produkt_menge,
                           kp.menge_pro_vpe AS kundenpreis_menge, k.firma AS kunde, kp.anzahl_vpe AS ab_pkg, kp.preis
                    FROM produkt_kundenpreis kp JOIN produkt p ON p.id = kp.produkt_id JOIN kunden k ON k.id = kp.kunde_id
                    WHERE kp.menge_pro_vpe IS NOT NULL AND kp.menge_pro_vpe > 0 AND p.einheiten_pro_packung > 0 AND kp.menge_pro_vpe <> p.einheiten_pro_packung
                    ORDER BY p.id DESC, k.firma",
        'korrektur' => '',
        'ohne_fix'  => 'Nicht automatisch ändern (Entscheidung Nico je Fall: eigenes Produkt anlegen oder Kundenpreis korrigieren).',
        'fix'   => null,
    ],
    'kundenpreis_abweichung' => [
        'titel' => 'Kundenpreis weicht stark von der letzten Rechnung ab (nur Info)',
        'info'  => 'Vergleicht den Kundenpreis (je Packung, ohne Verpackung) mit dem Betrag je Packung der letzten Rechnung dieses Kunden für dieses Produkt (inkl. Verpackung). Ein Abstand in Höhe der Verpackungskosten ist normal; gelistet wird ab 25 % Abweichung. Beispiel FuturaYou: 5,23 € Kundenpreis, 5,75 € auf RE-2766.',
        'sql'   => "SELECT x._id, x._link, x.nummer, x.name, x.kunde, x.kundenpreis, x.rechnung, ROUND(x.re_je_pkg, 2) AS rechnung_je_pkg,
                           ROUND((x.re_je_pkg - x.kundenpreis) / x.kundenpreis * 100) AS abweichung_prozent
                    FROM (SELECT kp.id AS _id, CONCAT('?p=produkt&id=', p.id, '&tab=kunden') AS _link, p.nummer, p.name, k.firma AS kunde, kp.preis AS kundenpreis,
                                 b.nummer AS rechnung, b.netto / NULLIF(a.menge, 0) AS re_je_pkg
                          FROM produkt_kundenpreis kp JOIN produkt p ON p.id = kp.produkt_id JOIN kunden k ON k.id = kp.kunde_id
                          JOIN beleg b ON b.id = (SELECT b2.id FROM beleg b2 JOIN auftrag a2 ON a2.id = b2.auftrag_id
                                                  WHERE a2.produkt_id = kp.produkt_id AND a2.kunde_id = kp.kunde_id AND b2.typ = 'rechnung' AND b2.status <> 'storniert' AND a2.menge > 0
                                                  ORDER BY b2.id DESC LIMIT 1)
                          JOIN auftrag a ON a.id = b.auftrag_id
                          WHERE kp.preis > 0) x
                    WHERE x.re_je_pkg > 0 AND ABS(x.re_je_pkg - x.kundenpreis) / x.kundenpreis > 0.25
                    ORDER BY ABS(x.re_je_pkg - x.kundenpreis) / x.kundenpreis DESC",
        'korrektur' => '',
        'ohne_fix'  => 'Nur Information – kein Beheben.',
        'fix'   => null,
    ],
];

// Artikelnummer für ein Kundenetikett: dieselbe Logik wie kundenetikett_version_anlegen() (Aufräumen 17) –
// naechste_nummer(item_prefix('verpackung')) unter der Nummern-Sperre. Liegt der Nummernkreis hinter Altbestand, wird
// eine schon vergebene Nummer übersprungen, damit keine Doppelnummer entsteht.
function dp_neue_vp_nummer(): string {
    for ($i = 0; $i < 100; $i++) {
        $nr = naechste_nummer(item_prefix('verpackung'));
        if (!scalar("SELECT 1 FROM item WHERE artikelnummer = ? LIMIT 1", [$nr])) return $nr;
    }
    throw new \RuntimeException('Keine freie VP-Nummer gefunden (Nummernkreis prüfen).');
}

// Prüfung ausführen (Fehler, z. B. fehlende Spalte, wird angezeigt statt die Seite zu sprengen).
// zeilen (optional): fn(array $rows): array – ergänzt Spalten, die sich nicht in SQL rechnen lassen (z. B. Herleitung).
function dp_laden(array $c): array {
    try { $rows = all($c['sql']); return ['rows' => isset($c['zeilen']) ? ($c['zeilen'])($rows) : $rows, 'fehler' => '']; }
    catch (\Throwable $e) { return ['rows' => [], 'fehler' => $e->getMessage()]; }
}

// Blocker 1: neutraler Basis-Behälter zu einem bedruckten Beutel + woher er kommt (nicht raten: 0 = nicht eindeutig).
function dp_neutral_herleitung(int $beutel_id): array {
    $nid = verpackung_neutrale_basis($beutel_id);
    if ($nid <= 0) return [0, ''];
    $b = one("SELECT basis_item_id, notiz FROM item WHERE id=?", [$beutel_id]) ?: [];
    $n = one("SELECT artikelnummer FROM item WHERE id=?", [$nid]) ?: [];
    if ((int)($b['basis_item_id'] ?? 0) === $nid) return [$nid, 'basis_item_id'];
    $notiz = (string)($b['notiz'] ?? '');
    if ((($n['artikelnummer'] ?? '') !== '' && str_contains($notiz, 'vom neutralen Behälter ' . $n['artikelnummer'] . ' '))
        || str_contains($notiz, 'vom neutralen Behälter #' . $nid . ' ')) return [$nid, 'Notiz des Beutels'];
    return [$nid, 'Angebot der Aufträge mit diesem Beutel'];
}

// --- Beheben (nur auf ausdrücklichen Klick, nur angehakte Zeilen) -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'beheben') {
    $key = (string)($_POST['pruefung'] ?? '');
    $c = $CHECKS[$key] ?? null;
    // Mehrere Knöpfe je Prüfung (fixes): gewählter Knopf kommt als „fix".
    if ($c && !empty($c['fixes'])) $c['fix'] = $c['fixes'][(string)($_POST['fix'] ?? '')] ?? null;
    if (!$c || empty($c['fix'])) { header('Location: ?p=datenpruefung&err=' . rawurlencode('Für diese Prüfung gibt es keinen Beheben-Knopf.')); exit; }
    $fix = $c['fix'];
    // Nur IDs, die die Prüfung JETZT noch liefert und die behebbar sind.
    // ziel: Spalte mit der ID des zu ändernden Datensatzes (Standard _id = die Zeile selbst).
    $erlaubt = [];
    foreach (dp_laden($c)['rows'] as $r)
        if (!isset($fix['waehlbar']) || ($fix['waehlbar'])($r)) $erlaubt[(int)$r['_id']] = $r;
    $sel = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => isset($erlaubt[$i]))));
    $dpL = !empty($c['auf_klick']) ? '&laden=' . rawurlencode($key) : '';   // „auf Klick"-Prüfung nach dem Beheben wieder geladen zeigen
    if (!$sel) { header('Location: ?p=datenpruefung' . $dpL . '&err=' . rawurlencode('Keine (gültige) Zeile angehakt – nichts geändert.') . '#' . $key); exit; }
    if (!empty($fix['sortiert'])) sort($sel);   // z. B. Nummern in ID-Reihenfolge vergeben
    // Eigene Korrektur je Zeile über mehrere Tabellen (protokolliert selbst), z. B. Pulver-Auftrag + PA.
    if (isset($fix['aktion'])) {
        $pdo = db();
        $pdo->beginTransaction();
        try { $n = 0; foreach ($sel as $i) $n += (int)($fix['aktion'])($erlaubt[$i]); $pdo->commit(); }
        catch (\Throwable $e) { $pdo->rollBack(); header('Location: ?p=datenpruefung&err=' . rawurlencode('Fehler, nichts geändert: ' . $e->getMessage()) . '#' . $key); exit; }
        if (function_exists('bedarf_bump')) bedarf_bump();
        header('Location: ?p=datenpruefung' . $dpL . '&ok=' . rawurlencode($c['titel'] . ': ' . $n . ' Datensatz/Datensätze geändert, im Protokoll.') . '#' . $key); exit;
    }
    $zielSp = $fix['ziel'] ?? '_id';
    $ids = array_values(array_unique(array_map(fn($i) => (int)$erlaubt[$i][$zielSp], $sel)));
    $tab = $fix['tabelle'];
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $vorher = [];
        foreach (all("SELECT * FROM `$tab` WHERE id IN ($ph)", $ids) as $r) $vorher[(int)$r['id']] = $r;
        if (isset($fix['params'])) { foreach ($sel as $i) q($fix['update'], ($fix['params'])($erlaubt[$i])); }   // je Zeile eigene Werte
        else q(str_replace('{IDS}', $ph, $fix['update']), $ids);
        $felder = 0; $zeilen = 0;
        foreach (all("SELECT * FROM `$tab` WHERE id IN ($ph)", $ids) as $r) {
            if (!isset($vorher[(int)$r['id']])) continue;
            $n = aenderung_protokollieren($fix['typ'], (int)$r['id'], $vorher[(int)$r['id']], $r, 'datenpruefung');
            if ($n > 0) { $zeilen++; $felder += $n; }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        header('Location: ?p=datenpruefung&err=' . rawurlencode('Fehler, nichts geändert: ' . $e->getMessage()) . '#' . $key); exit;
    }
    if (function_exists('bedarf_bump')) bedarf_bump();
    header('Location: ?p=datenpruefung&ok=' . rawurlencode($c['titel'] . ': ' . $zeilen . ' Zeile(n) geändert, ' . $felder . ' Feld-Änderung(en) protokolliert.') . '#' . $key); exit;
}

// --- Anzeige -------------------------------------------------------------------------------------------------
$ERG = [];
$dpLaden = (string)($_GET['laden'] ?? '');
foreach ($CHECKS as $k => $c) $ERG[$k] = (!empty($c['auf_klick']) && $dpLaden !== $k) ? ['rows' => [], 'fehler' => '', 'nicht_geladen' => true] : dp_laden($c);
// Dieselbe Charge bei mehreren Bestellungen vorgeschlagen? Über beide Einkauf-Prüfungen hinweg.
$dpAbglRows = [];
foreach ($CHECKS as $k => $c) if (!empty($c['abgleich'])) $dpAbglRows = array_merge($dpAbglRows, $ERG[$k]['rows']);
$GLOBALS['dp_konflikte'] = bestellung_abgleich_konflikte($dpAbglRows);
$offen = 0;
foreach ($CHECKS as $k => $c) {
    $n = count($ERG[$k]['rows']);
    if ($ERG[$k]['fehler'] !== '' || (($c['leer_ok'] ?? true) ? $n > 0 : $n === 0)) $offen++;
}

// Zahlen mit Tausenderpunkt; IDs und Nummern roh.
function dp_wert(string $spalte, $v): string {
    if ($v === null || $v === '') return '<span class="muted">–</span>';
    $isId = $spalte === 'id' || str_ends_with($spalte, '_id');
    if (!$isId && is_numeric($v) && !preg_match('/^0\d/', (string)$v)) {
        $f = (float)$v;
        $dez = abs($f - round($f)) < 1e-9 ? 0 : min(4, strlen(rtrim(substr(strrchr((string)$v, '.') ?: '', 1), '0')));
        return h(number_format($f, $dez, ',', '.'));
    }
    return h((string)$v);
}
function dp_kopf(string $spalte): string {
    $fest = ['vorschlaege' => 'Vorschläge', 'aktionen' => '', 'inhalt' => 'Inhalt'];
    if (array_key_exists($spalte, $fest)) return h($fest[$spalte]);
    return h(ucfirst(str_replace('_', ' ', preg_replace('/_id$/', ' ID', $spalte))));
}

$migr = trim((string)(function_exists('meta_get') ? meta_get('migr_kundenetikett_protokoll', '') : ''));

render_header('datenpruefung', 'Datenprüfung');
bx_head('Datenprüfung',
        'Prüfungen auf Altdaten und Unstimmigkeiten. Die Seite liest nur. Geändert wird ausschließlich über „Beheben" für die angehakten Zeilen, mit Eintrag im Feld-Änderungsprotokoll.',
        bx_btn('Feld-Änderungen', '?p=protokoll&ansicht=felder', 'ghost'));

if (isset($_GET['ok']))  echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . h((string)$_GET['ok']) . '</div>';
if (isset($_GET['err'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['err']) . '</div>';
?>
<div class="bx-panel">
  <h2 style="margin:0 0 10px">Übersicht <?= $offen ? bx_badge($offen . ' mit Treffern', 'warn') : bx_badge('alles unauffällig', 'ok') ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Prüfung</th><th>Treffer</th><th>Korrektur</th></tr></thead>
    <tbody>
    <?php foreach ($CHECKS as $k => $c): $n = count($ERG[$k]['rows']); $leerOk = $c['leer_ok'] ?? true;
        $kind = $ERG[$k]['fehler'] !== '' ? 'err' : (($leerOk ? $n > 0 : $n === 0) ? 'warn' : 'ok'); ?>
      <tr>
        <td><a href="#<?= h($k) ?>"><?= h($c['titel']) ?></a></td>
        <td><?= !empty($ERG[$k]['nicht_geladen']) ? '<a href="?p=datenpruefung&laden=' . h($k) . '#' . h($k) . '">auf Klick prüfen</a>' : ($ERG[$k]['fehler'] !== '' ? bx_badge('Fehler', 'err') : bx_badge(number_format($n, 0, ',', '.'), $kind)) ?></td>
        <td class="muted"><?= !empty($c['fixes']) ? count($c['fixes']) . ' Knöpfe' : (!empty($c['fix']) ? 'Beheben-Knopf' : 'nur Anzeige') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php foreach ($CHECKS as $k => $c):
    $rows = $ERG[$k]['rows']; $n = count($rows); $fixes = $c['fixes'] ?? null; $fix = $c['fix'] ?? ($fixes ? ['waehlbar' => fn($r) => true] : null); $leerOk = $c['leer_ok'] ?? true;
    $kind = ($leerOk ? $n > 0 : $n === 0) ? 'warn' : 'ok';
    $spalten = $rows ? array_values(array_filter(array_keys($rows[0]), fn($s) => $s[0] !== '_')) : [];
    $hatLink = $rows && array_key_exists('_link', $rows[0]);
    $waehlbarN = $fix ? count(array_filter($rows, fn($r) => !isset($fix['waehlbar']) || ($fix['waehlbar'])($r))) : 0; ?>
<div class="bx-panel" id="<?= h($k) ?>">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0"><?= h($c['titel']) ?> <?= $ERG[$k]['fehler'] !== '' ? bx_badge('Fehler', 'err') : bx_badge(number_format($n, 0, ',', '.') . ' Treffer', $kind) ?></h2>
  </div>
  <p class="muted" style="margin:6px 0 12px"><?= h($c['info']) ?></p>
  <?php if (!empty($ERG[$k]['nicht_geladen'])): ?>
    <a class="btn btn-ghost btn-sm" href="?p=datenpruefung&laden=<?= h($k) ?>#<?= h($k) ?>">Prüfung starten</a> <span class="muted" style="font-size:12px">rechnet je Auftrag den Fortschritt, daher nur auf Klick</span>
  <?php elseif ($ERG[$k]['fehler'] !== ''): ?>
    <div style="color:var(--err)">Abfrage fehlgeschlagen: <?= h($ERG[$k]['fehler']) ?></div>
  <?php elseif (!$rows): ?>
    <div class="muted"><?= $leerOk ? 'Keine Treffer.' : 'Niemand hat diese Rolle.' ?></div>
  <?php else: ?>
  <?php if (!empty($c['abgleich'])):
      // Eindeutige Paare (genau ein Vorschlag, gleicher Artikel, Menge passt, kein Konflikt) für den Sammel-Knopf.
      $dpPaare = [];
      foreach ($rows as $r) foreach ($r['_pos'] as $pp) if ($ev = bestellung_abgleich_eindeutig($pp, $GLOBALS['dp_konflikte'] ?? []))
          $dpPaare[] = ['v' => (int)$r['_id'] . ':' . (int)$pp['id'] . ':' . (int)$ev['id'], 't' => $r['nummer'] . ': ' . ($ev['charge_nr'] ?: '#' . $ev['id']) . ' → ' . ($pp['artikel'] ?: $pp['bezeichnung'])]; ?>
  <div class="bx-row" style="gap:10px;align-items:center;margin:0 0 10px;flex-wrap:wrap">
    <?php if ($dpPaare): ?>
    <form method="post" style="margin:0" onsubmit="return confirm(<?= h(json_encode(count($dpPaare) . ' Verknüpfungen - ausführen?' . "\n\n" . implode("\n", array_map(fn($x) => $x['t'], array_slice($dpPaare, 0, 40))) . (count($dpPaare) > 40 ? "\n…" : ''), JSON_UNESCAPED_UNICODE)) ?>);">
      <input type="hidden" name="aktion" value="dp_abgl_sammel"><input type="hidden" name="pruefung" value="<?= h($k) ?>">
      <?php foreach ($dpPaare as $x): ?><input type="hidden" name="paar[]" value="<?= h($x['v']) ?>"><?php endforeach; ?>
      <button class="btn btn-primary btn-sm" type="submit">Alle eindeutigen verknüpfen (<?= count($dpPaare) ?>)</button>
    </form>
    <span class="muted" style="font-size:12px">nur Positionen mit genau einem Vorschlag, gleicher Artikel, Menge passt, ohne Konflikt</span>
    <?php else: ?><span class="muted" style="font-size:12px">Keine eindeutigen Verknüpfungen - bitte einzeln entscheiden.</span><?php endif; ?>
    <span id="dpMsg-<?= h($k) ?>" style="font-size:13px"></span>
  </div>
  <?php endif; ?>
  <form method="post" style="margin:0" onsubmit="var n=this.querySelectorAll('input[name=&quot;ids[]&quot;]:checked').length; if(!n){alert('Bitte zuerst Zeilen anhaken.');return false;} return confirm(n+' Zeile(n) beheben? Ausgeführt wird genau die unten angezeigte Korrektur, nur für die angehakten Zeilen. Jede Feld-Änderung landet im Protokoll.');">
    <input type="hidden" name="aktion" value="beheben"><input type="hidden" name="pruefung" value="<?= h($k) ?>">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr>
        <?php if ($fix): ?><th style="width:28px"><?php if ($waehlbarN): ?><input type="checkbox" title="alle" onclick="this.closest('table').querySelectorAll('input[name=&quot;ids[]&quot;]').forEach(x=>x.checked=this.checked)"><?php endif; ?></th><?php endif; ?>
        <?php foreach ($spalten as $s): ?><th><?= dp_kopf($s) ?></th><?php endforeach; ?>
        <?php if ($hatLink): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-dp-zeile="<?= h($k) ?>-<?= (int)($r['_id'] ?? 0) ?>" style="vertical-align:top">
          <?php if ($fix): ?><td><?php if (!isset($fix['waehlbar']) || ($fix['waehlbar'])($r)): ?><input type="checkbox" name="ids[]" value="<?= (int)$r['_id'] ?>"><?php endif; ?></td><?php endif; ?>
          <?php foreach ($spalten as $s): $lz = $r['_link_' . $s] ?? null; ?><td<?= isset($c['html'][$s]) ? ' style="white-space:normal;min-width:' . (['vorschlaege' => 360, 'inhalt' => 220, 'aktionen' => 170][$s] ?? 0) . 'px"' : '' ?>><?= isset($c['html'][$s]) ? ($c['html'][$s])($r) : ($lz && $r[$s] !== null && $r[$s] !== '' ? '<a href="' . h((string)$lz) . '">' . dp_wert($s, $r[$s]) . '</a>' : dp_wert($s, $r[$s])) ?></td><?php endforeach; ?>
          <?php if ($hatLink): ?><td><?= !empty($r['_link']) ? '<a href="' . h((string)$r['_link']) . '">Öffnen</a>' : '' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($fixes && $waehlbarN): ?>
      <div class="bx-row" style="margin-top:12px;gap:8px;flex-wrap:wrap"><?php foreach ($fixes as $fk => $fd): ?><button class="btn <?= $fk === array_key_first($fixes) ? 'btn-primary' : 'btn-ghost' ?>" type="submit" name="fix" value="<?= h($fk) ?>"><?= h($fd['label']) ?></button><?php endforeach; ?></div>
      <div class="muted" style="font-size:12px;margin-top:6px">Gilt für die angehakten Zeilen. „Rest storniert" nur bei Zeilen mit offener Menge.</div>
    <?php elseif ($fix && $waehlbarN): ?>
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Angehakte beheben</button></div>
    <?php endif; ?>
  </form>
  <?php endif; ?>
  <?php if (($c['korrektur'] ?? '') !== ''): ?>
    <div style="margin-top:14px">
      <div class="muted" style="font-size:12px;margin-bottom:4px"><?= $fix ? 'Korrektur, die „Beheben" für die angehakten Zeilen ausführt' : 'Dokumentierte Korrektur (wird hier nicht ausgeführt)' ?></div>
      <pre style="margin:0;padding:10px 12px;background:var(--panel-2);border:1px solid var(--line);color:var(--text);font-family:var(--mono);border-radius:8px;white-space:pre-wrap;font-size:12px"><?= h($c['korrektur']) ?></pre>
    </div>
  <?php endif; ?>
  <?php if (!empty($c['ohne_fix'])): ?><p class="muted" style="font-size:12px;margin:10px 0 0"><?= h($c['ohne_fix']) ?></p><?php endif; ?>
</div>
<?php endforeach; ?>

<div class="bx-panel" id="migr">
  <h2 style="margin:0 0 8px">Protokoll der Kundenetikett-Migration</h2>
  <p class="muted" style="margin:0 0 12px">Etiketten Teil 2, geschrieben beim ersten Aufruf nach dem Deploy.</p>
  <?php if ($migr === ''): ?><div class="muted">Kein Protokoll vorhanden.</div>
  <?php else: ?><pre style="margin:0;padding:10px 12px;background:var(--panel-2);border:1px solid var(--line);color:var(--text);font-family:var(--mono);border-radius:8px;white-space:pre-wrap;font-size:12px"><?= h($migr) ?></pre><?php endif; ?>
</div>
<?php ?>
<script>
// Datenprüfung, Einkauf-Altlasten: Knöpfe in der Zeile ohne Neuladen (11.11.2026).
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-dpabgl]'); if (!b) return;
  e.preventDefault();
  if (b.dataset.frage && !confirm(b.dataset.frage)) return;
  var tr = b.closest('tr'), fd = new FormData();
  fd.append('aktion', 'dp_abgl'); fd.append('was', b.dataset.dpabgl); fd.append('bestellung', b.dataset.bestellung || 0);
  fd.append('bp', b.dataset.bp || 0); fd.append('charge', b.dataset.charge || 0);
  b.disabled = true;
  fetch('?p=datenpruefung', {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
    var pan = tr ? tr.closest('.bx-panel') : null, msg = pan ? pan.querySelector('[id^="dpMsg-"]') : null;
    if (msg) { msg.textContent = j.msg || ''; msg.style.color = j.ok ? 'var(--gruen)' : '#8f231b'; }
    if (j.ok && tr) {
      var pd = b.closest('[data-dp-pos]');
      if (b.dataset.dpabgl === 'loesen') { b.closest('div').innerHTML = '<span style="font-size:12px;color:var(--gruen)">' + (j.msg || 'gelöst') + ' Seite neu laden für neue Vorschläge.</span>'; return; }
      if ((b.dataset.dpabgl === 'verknuepfen' || b.dataset.dpabgl === 'korr_verknuepfen') && pd) {
        // Block B Teil 3/4 (11.11.2026): verknüpfte Zeile bleibt sichtbar mit „Verknüpfung lösen"; weitere Vorschläge der Position
        // bleiben aktiv, solange sie offen ist – ist sie voll, werden sie grau mit „Position schon gedeckt".
        var zeile = b.closest('.bx-row');
        if (zeile) zeile.innerHTML = '<span style="font-size:12px;color:var(--gruen)">' + (j.msg || 'verknüpft') + '</span>'
          + '<button type="button" class="btn btn-ghost btn-sm" data-dpabgl="loesen" data-bestellung="' + (b.dataset.bestellung || 0) + '" data-charge="' + (b.dataset.charge || 0) + '" data-frage="Verknüpfung lösen? Die Charge ist danach frei, die Bestellung rechnet neu.">Verknüpfung lösen</button>';
        if (j.pos_voll) pd.querySelectorAll('[data-dpabgl="verknuepfen"],[data-dpkorr]').forEach(function (x) {
          var r = x.closest('.bx-row'); if (!r || r === zeile) return;
          r.style.opacity = '.5'; r.querySelectorAll('button').forEach(function (bb) { bb.disabled = true; });
          if (!r.querySelector('.dp-gedeckt')) r.insertAdjacentHTML('beforeend', '<span class="dp-gedeckt muted">Position schon gedeckt</span>');
        });
        // Charge ist jetzt vergeben: in anderen Zeilen nicht mehr anbieten
        document.querySelectorAll('[data-dpabgl="verknuepfen"][data-charge="' + b.dataset.charge + '"]').forEach(function (x) { if (x !== b) { x.disabled = true; x.title = 'Charge schon verknüpft'; } });
        return;
      }
      tr.remove();
    } else { b.disabled = false; if (!msg) alert(j.msg || 'Fehler'); }
  }).catch(function () { b.disabled = false; alert('Fehler beim Speichern - bitte Seite neu laden.'); });
});
// Block B Teil 2 (11.11.2026): „Menge korrigieren und verknüpfen" - Pop-up wie auf der Chargenseite, vorbelegt mit der offenen Bestellmenge.
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-dpkorr]'); if (!b) return;
  e.preventDefault();
  var d = document.getElementById('dpKorrDlg');
  if (!d) {
    document.body.insertAdjacentHTML('beforeend', '<dialog id="dpKorrDlg" class="bx-dialog" style="max-width:520px"><h2 style="margin-top:0">Menge korrigieren und verknüpfen</h2><p class="muted" id="dpKorrInfo" style="margin-top:0"></p>'
      + '<form id="dpKorrForm" style="margin:0"><div class="bx-field"><label>Was ist falsch?</label><select name="korr_was" data-norscombo><option value="ursprung">Ursprungsmenge (falsch eingebucht) - verfügbar verschiebt sich mit</option><option value="verfuegbar">nur verfügbare Menge (Schwund, Bruch)</option></select></div>'
      + '<div class="bx-field"><label id="dpKorrMengeLbl">Neue Menge</label><input type="text" inputmode="decimal" name="korr_menge" required></div>'
      + '<div class="bx-field"><label>Grund (Pflicht)</label><select name="korr_grund" required data-norscombo><option value="">- wählen -</option><option value="einbuchfehler">Einbuchfehler</option><option value="lieferschein">Lieferschein abweichend</option><option value="schwund">Schwund</option><option value="bruch">Bruch</option><option value="sonstiges">Sonstiges</option></select></div>'
      + '<div class="bx-field"><label>Notiz</label><input type="text" name="korr_notiz" maxlength="500"></div>'
      + '<div class="bx-field"><label>Beleg (optional, Foto oder PDF)</label><input type="file" name="korr_beleg" accept=".pdf,image/*"></div>'
      + '<div class="bx-row" style="justify-content:flex-end;gap:8px;margin-top:12px"><button type="button" class="btn btn-ghost" onclick="this.closest(\'dialog\').close()">Abbrechen</button><button class="btn btn-primary" type="submit">Korrigieren und verknüpfen</button></div></form></dialog>');
    d = document.getElementById('dpKorrDlg');
    d.querySelector('form').addEventListener('submit', function (ev) {
      ev.preventDefault();
      var src = d._src, fd = new FormData(this);
      fd.append('aktion', 'dp_abgl'); fd.append('was', 'korr_verknuepfen'); fd.append('bestellung', src.dataset.bestellung); fd.append('bp', src.dataset.bp); fd.append('charge', src.dataset.charge);
      fetch('?p=datenpruefung', {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.ok) { alert(j.msg || 'Fehler'); return; }
        d.close();
        var v = src.parentNode.querySelector('[data-dpabgl="verknuepfen"]');
        if (v) { v.dataset.dpabgl = 'korr_verknuepfen'; v.dataset.frage = ''; }
        // Ergebnis wie beim normalen Verknüpfen darstellen
        var pd = src.closest('[data-dp-pos]'), zeile = src.closest('.bx-row');
        if (zeile) zeile.innerHTML = '<span style="font-size:12px;color:var(--gruen)">' + (j.msg || 'verknüpft') + '</span>'
          + '<button type="button" class="btn btn-ghost btn-sm" data-dpabgl="loesen" data-bestellung="' + src.dataset.bestellung + '" data-charge="' + src.dataset.charge + '" data-frage="Verknüpfung lösen? Die Charge ist danach frei, die Bestellung rechnet neu.">Verknüpfung lösen</button>';
        if (j.pos_voll && pd) pd.querySelectorAll('[data-dpabgl="verknuepfen"],[data-dpkorr]').forEach(function (x) {
          var r = x.closest('.bx-row'); if (!r || r === zeile) return;
          r.style.opacity = '.5'; r.querySelectorAll('button').forEach(function (bb) { bb.disabled = true; });
          if (!r.querySelector('.dp-gedeckt')) r.insertAdjacentHTML('beforeend', '<span class="dp-gedeckt muted">Position schon gedeckt</span>');
        });
      }).catch(function () { alert('Fehler beim Speichern - bitte Seite neu laden.'); });
    });
  }
  d._src = b;
  d.querySelector('#dpKorrInfo').textContent = 'Charge ' + b.dataset.chargenr + ': ' + b.dataset.menge + ' ' + b.dataset.einheit + ' - offen in der Bestellung: ' + b.dataset.offen + ' ' + b.dataset.einheit;
  d.querySelector('#dpKorrMengeLbl').textContent = 'Neue Menge (' + b.dataset.einheit + ', Einheit fest aus dem Artikel)';
  d.querySelector('[name=korr_menge]').value = b.dataset.offen;
  d.showModal();
});
</script>
<?php render_footer();
