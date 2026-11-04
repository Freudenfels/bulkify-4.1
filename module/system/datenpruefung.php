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
$CHECKS = [
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
        'korrektur' => "-- je Zeile: item_zusammenfuehren(<ID>, <Ziel>): UPDATE charge/reservierung SET item_id=<Ziel> WHERE item_id=<ID>; UPDATE item SET gesperrt=1 WHERE id=<ID>;",
        'ohne_fix'  => 'Ohne eindeutigen Vorschlag oder mit anderer Einheit bitte auf der Artikelseite von Hand zusammenführen.',
        'fix'   => ['tabelle' => 'item', 'typ' => 'item',
                    'waehlbar' => fn(array $r) => !empty($r['_ziel_id']) && (string)$r['einheit'] === (string)$r['ziel_einheit'],
                    'aktion' => function (array $r) {
                        $res = item_zusammenfuehren((int)$r['_id'], (int)$r['_ziel_id']);
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
        'info'  => 'Aktive Kundenetikett-Artikel, deren gespeichertes Maß (breite_mm/hoehe_mm bzw. etikett_final) um mehr als 2 mm vom Endformat des TATSÄCHLICHEN Produkt-Behälters abweicht (Behälter = jüngster Auftrags-Behälter mit Vorrang, sonst Produkt-Behälter; Endformat = item.etikett_final am Behälter). Ursache: v1 hat früher das Maß vom falschen generischen Maß-Etikett übernommen (z. B. 200-ml-Glas „67 x 188" statt 150-ml-Glas „60 x 172"). Beheben zieht Maß und Maß-Vorlage (etikett_vorlage_id) auf das passende Maß-Etikett des Behälters. Das aufgedruckte Design (etikett_druck) bleibt unberührt.',
        'sql'   => "SELECT i.id AS _id, i.produkt_id AS _pid, CONCAT('?p=verpackung&id=', i.id) AS _link, i.artikelnummer, i.name AS etikett,
                           i.breite_mm AS _breite_mm, i.hoehe_mm AS _hoehe_mm, i.etikett_final AS _etikett_final, i.etikett_vorlage_id AS _vorlage_alt,
                           p.nummer AS produkt_nr, p.name AS produkt, CONCAT('?p=produkt&id=', p.id) AS _link_produkt, k.firma AS kunde
                    FROM item i
                    JOIN produkt p ON p.id = i.produkt_id
                    LEFT JOIN kunden k ON k.id = i.kunde_id
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
                if ($eigenN !== null && $abw <= 2.0) continue;    // passt bis 2 mm -> kein Treffer
                $vl = kundenetikett_vorlage($pid);                // Ziel-Maß/Vorlage aus dem tatsächlichen Behälter
                $zielMass = (!empty($vl['breite_mm']) && !empty($vl['hoehe_mm']))
                          ? ((float)$vl['breite_mm'] . ' x ' . (float)$vl['hoehe_mm'] . ' mm')
                          : (!empty($vl['etikett_final']) ? (string)$vl['etikett_final'] : null);
                $r['etikett_mass']        = $eigenN ? ((float)$eigenN[0] . ' x ' . (float)$eigenN[1] . ' mm') : 'kein Maß';
                $r['behaelter_endformat'] = (float)$beh[0] . ' x ' . (float)$beh[1] . ' mm';
                $r['abweichung_mm']       = $eigenN ? round((float)$abw, 1) : null;
                $r['_fixbar']             = !empty($vl['_vorlage']) && $zielMass !== null;
                $r['aenderung']           = $r['_fixbar'] ? ('Maß/Vorlage -> ' . $zielMass) : 'nicht automatisch: kein passendes Maß-Etikett zum Behälter';
                $out[] = $r;
            }
            return $out;
        },
        'korrektur' => "-- je angehaktem Etikett: Maß + Maß-Vorlage auf das passende Maß-Etikett des Behälters ziehen (etikett_druck bleibt)\nUPDATE item SET breite_mm=<B>, hoehe_mm=<H>, etikett_format=<Format>, etikett_final=<Endformat>, etikett_vorlage_id=<Maß-Etikett>\n WHERE id=<Etikett> AND verpackung_rolle='etikett' AND produkt_id IS NOT NULL;   (Maße aus kundenetikett_vorlage(Produkt) = Behälter)",
        'ohne_fix'  => 'Ändert nur die Maß-Felder des Etikett-Artikels, nicht das aufgedruckte Design und keine Aufträge. Zeilen ohne passendes Maß-Etikett zum Behälter sind nicht anwählbar – dort fehlt die Maß-Vorlage. Ist stattdessen der Behälter am Produkt/Auftrag falsch, dort korrigieren statt hier zu beheben.',
        'fix'   => ['tabelle' => 'item', 'typ' => 'item',
                    'waehlbar' => fn(array $r) => !empty($r['_fixbar']),
                    'aktion' => function (array $r): int {
                        $iid = (int)$r['_id']; $pid = (int)$r['_pid'];
                        $vl = kundenetikett_vorlage($pid);
                        if (empty($vl['_vorlage'])) return 0;
                        $vor = one("SELECT * FROM item WHERE id=?", [$iid]);
                        if (!$vor) return 0;
                        q("UPDATE item SET breite_mm=?, hoehe_mm=?, etikett_format=?, etikett_final=?, etikett_vorlage_id=?
                           WHERE id=? AND verpackung_rolle='etikett' AND produkt_id IS NOT NULL AND COALESCE(gesperrt,0)=0",
                          [$vl['breite_mm'] ?? null, $vl['hoehe_mm'] ?? null, $vl['etikett_format'] ?? null, $vl['etikett_final'] ?? null, (int)$vl['_vorlage'], $iid]);
                        $nach = one("SELECT * FROM item WHERE id=?", [$iid]);
                        return aenderung_protokollieren('item', $iid, $vor, $nach, 'datenpruefung') > 0 ? 1 : 0;
                    }],
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
    if (!$c || empty($c['fix'])) { header('Location: ?p=datenpruefung&err=' . rawurlencode('Für diese Prüfung gibt es keinen Beheben-Knopf.')); exit; }
    $fix = $c['fix'];
    // Nur IDs, die die Prüfung JETZT noch liefert und die behebbar sind.
    // ziel: Spalte mit der ID des zu ändernden Datensatzes (Standard _id = die Zeile selbst).
    $erlaubt = [];
    foreach (dp_laden($c)['rows'] as $r)
        if (!isset($fix['waehlbar']) || ($fix['waehlbar'])($r)) $erlaubt[(int)$r['_id']] = $r;
    $sel = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => isset($erlaubt[$i]))));
    if (!$sel) { header('Location: ?p=datenpruefung&err=' . rawurlencode('Keine (gültige) Zeile angehakt – nichts geändert.') . '#' . $key); exit; }
    if (!empty($fix['sortiert'])) sort($sel);   // z. B. Nummern in ID-Reihenfolge vergeben
    // Eigene Korrektur je Zeile über mehrere Tabellen (protokolliert selbst), z. B. Pulver-Auftrag + PA.
    if (isset($fix['aktion'])) {
        $pdo = db();
        $pdo->beginTransaction();
        try { $n = 0; foreach ($sel as $i) $n += (int)($fix['aktion'])($erlaubt[$i]); $pdo->commit(); }
        catch (\Throwable $e) { $pdo->rollBack(); header('Location: ?p=datenpruefung&err=' . rawurlencode('Fehler, nichts geändert: ' . $e->getMessage()) . '#' . $key); exit; }
        if (function_exists('bedarf_bump')) bedarf_bump();
        header('Location: ?p=datenpruefung&ok=' . rawurlencode($c['titel'] . ': ' . $n . ' Datensatz/Datensätze geändert, im Protokoll.') . '#' . $key); exit;
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
foreach ($CHECKS as $k => $c) $ERG[$k] = dp_laden($c);
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
function dp_kopf(string $spalte): string { return h(ucfirst(str_replace('_', ' ', preg_replace('/_id$/', ' ID', $spalte)))); }

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
        <td><?= $ERG[$k]['fehler'] !== '' ? bx_badge('Fehler', 'err') : bx_badge(number_format($n, 0, ',', '.'), $kind) ?></td>
        <td class="muted"><?= $c['fix'] ? 'Beheben-Knopf' : 'nur Anzeige' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php foreach ($CHECKS as $k => $c):
    $rows = $ERG[$k]['rows']; $n = count($rows); $fix = $c['fix']; $leerOk = $c['leer_ok'] ?? true;
    $kind = ($leerOk ? $n > 0 : $n === 0) ? 'warn' : 'ok';
    $spalten = $rows ? array_values(array_filter(array_keys($rows[0]), fn($s) => $s[0] !== '_')) : [];
    $hatLink = $rows && array_key_exists('_link', $rows[0]);
    $waehlbarN = $fix ? count(array_filter($rows, fn($r) => !isset($fix['waehlbar']) || ($fix['waehlbar'])($r))) : 0; ?>
<div class="bx-panel" id="<?= h($k) ?>">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0"><?= h($c['titel']) ?> <?= $ERG[$k]['fehler'] !== '' ? bx_badge('Fehler', 'err') : bx_badge(number_format($n, 0, ',', '.') . ' Treffer', $kind) ?></h2>
  </div>
  <p class="muted" style="margin:6px 0 12px"><?= h($c['info']) ?></p>
  <?php if ($ERG[$k]['fehler'] !== ''): ?>
    <div style="color:var(--err)">Abfrage fehlgeschlagen: <?= h($ERG[$k]['fehler']) ?></div>
  <?php elseif (!$rows): ?>
    <div class="muted"><?= $leerOk ? 'Keine Treffer.' : 'Niemand hat diese Rolle.' ?></div>
  <?php else: ?>
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
        <tr>
          <?php if ($fix): ?><td><?php if (!isset($fix['waehlbar']) || ($fix['waehlbar'])($r)): ?><input type="checkbox" name="ids[]" value="<?= (int)$r['_id'] ?>"><?php endif; ?></td><?php endif; ?>
          <?php foreach ($spalten as $s): $lz = $r['_link_' . $s] ?? null; ?><td><?= $lz && $r[$s] !== null && $r[$s] !== '' ? '<a href="' . h((string)$lz) . '">' . dp_wert($s, $r[$s]) . '</a>' : dp_wert($s, $r[$s]) ?></td><?php endforeach; ?>
          <?php if ($hatLink): ?><td><?= !empty($r['_link']) ? '<a href="' . h((string)$r['_link']) . '">Öffnen</a>' : '' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($fix && $waehlbarN): ?>
      <div style="margin-top:12px"><button class="btn btn-primary" type="submit">Angehakte beheben</button></div>
    <?php endif; ?>
  </form>
  <?php endif; ?>
  <?php if ($c['korrektur'] !== ''): ?>
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
<?php render_footer();
