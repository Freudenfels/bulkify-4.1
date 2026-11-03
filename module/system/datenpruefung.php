<?php
// Datenprüfung (nur Admin): die read-only Prüfungen aus der SELECT-Liste „Nicos manuelle Live-DB-Aufgaben"
// direkt im Dashboard. Je Prüfung: Überschrift, Trefferzahl, Treffer-Tabelle und die dokumentierte Korrektur als
// Text. Es wird NICHTS automatisch geändert. Wo eine Korrektur dokumentiert ist, gibt es EINEN „Beheben"-Knopf, der
// genau diese UPDATE nur für die angehakten Zeilen ausführt (Bestätigung, Transaktion, Eintrag ins Feld-Änderungs-
// protokoll aenderung_log). Ausnahme: Fremdlager-Altbestand (S-16) bleibt reine Anzeige (Migration nur nach Freigabe).
// Route: ?p=datenpruefung
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!has_role('admin')) {
    render_header('datenpruefung', 'Datenprüfung');
    bx_head('Kein Zugriff', 'Nur für Admins.', bx_btn('Zurück', '?p=dashboard', 'ghost'));
    render_footer(); exit;
}

$gelMenge = column_exists('bestellung_position', 'geliefert_menge');

// --- Prüfungen ---------------------------------------------------------------------------------------------
// sql:     reine Lese-Abfrage; _id = Zeilen-ID für die Auswahl (wird nicht angezeigt), _link = Öffnen-Link.
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
        'sql'   => "SELECT v.id AS _id, CONCAT('?p=produktionsauftrag&id=', v.pa_id) AS _link, v.pa_id, v.menge, v.einheit, c.charge_nr, c.einheit AS charge_einheit, i.name
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
        'sql'   => "SELECT c.id AS _id, IF(pa.auftrag_id IS NULL, NULL, CONCAT('?p=auftrag&id=', pa.auftrag_id)) AS _link, c.charge_nr, i.name, c.menge, c.menge_verfuegbar, c.status,
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
        'sql'   => "SELECT c.id AS _id, c.charge_nr, i.name, c.menge_verfuegbar, c.standort, p.kunde_id, k.firma
                    FROM charge c JOIN item i ON i.id = c.item_id AND i.kategorie = 'verkaufsfertig'
                    JOIN produkt p ON p.id = i.produkt_id JOIN kunden k ON k.id = p.kunde_id AND k.nutzt_fulfillment = 1
                    WHERE c.fremd_kunde_id IS NULL AND c.status IN ('frei','quarantaene') AND c.menge_verfuegbar > 0
                    ORDER BY p.kunde_id, c.id",
        'korrektur' => "-- Nach Freigabe: UPDATE charge SET fremd_kunde_id=<kunde>, standort='lager2' WHERE id IN (...);",
        'ohne_fix'  => 'Migration nur nach separater Freigabe – bewusst kein Beheben-Knopf.',
        'fix'   => null,
    ],
    's16_auftraege' => [
        'titel' => 'S-16: Aufträge schon „ins Fremdlager" übergeben',
        'info'  => 'Versendete Aufträge von Fulfillment-Kunden ohne Lieferschein: Die Ware blieb unser Bestand.',
        'sql'   => "SELECT a.id AS _id, CONCAT('?p=auftrag&id=', a.id) AS _link, a.nummer, a.kunde_id, k.firma, a.menge, i.id AS item_id
                    FROM auftrag a JOIN kunden k ON k.id = a.kunde_id AND k.nutzt_fulfillment = 1
                    JOIN item i ON i.produkt_id = a.produkt_id AND i.kategorie = 'verkaufsfertig'
                    WHERE a.status = 'versendet' AND NOT EXISTS (SELECT 1 FROM beleg b WHERE b.auftrag_id = a.id AND b.typ = 'lieferschein')
                    ORDER BY a.id DESC",
        'korrektur' => '',
        'ohne_fix'  => 'Teil der Fremdlager-Migration, nur nach separater Freigabe – bewusst kein Beheben-Knopf.',
        'fix'   => null,
    ],
    'zukauf_fertig' => [
        'titel' => 'Zukauf „fertig" mit alten Schritten',
        'info'  => 'Offene Produktionsaufträge mit Zukauf-Art „fertig", bei denen Verpacken oder Etikettieren noch offen ist. Neue PAs bekommen automatisch den kurzen Weg.',
        'sql'   => "SELECT pa.id AS _id, IF(a.id IS NULL, CONCAT('?p=produktionsauftrag&id=', pa.id), CONCAT('?p=auftrag&id=', a.id)) AS _link, pa.nummer, a.nummer AS auftrag,
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
        'korrektur' => '',
        'ohne_fix'  => 'Keine Korrektur dokumentiert, nur Anzeige.',
        'fix'   => null,
    ],
];

// Prüfung ausführen (Fehler, z. B. fehlende Spalte, wird angezeigt statt die Seite zu sprengen).
function dp_laden(array $c): array {
    try { return ['rows' => all($c['sql']), 'fehler' => '']; }
    catch (\Throwable $e) { return ['rows' => [], 'fehler' => $e->getMessage()]; }
}

// --- Beheben (nur auf ausdrücklichen Klick, nur angehakte Zeilen) -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'beheben') {
    $key = (string)($_POST['pruefung'] ?? '');
    $c = $CHECKS[$key] ?? null;
    if (!$c || empty($c['fix'])) { header('Location: ?p=datenpruefung&err=' . rawurlencode('Für diese Prüfung gibt es keinen Beheben-Knopf.')); exit; }
    $fix = $c['fix'];
    // Nur IDs, die die Prüfung JETZT noch liefert und die behebbar sind.
    $erlaubt = [];
    foreach (dp_laden($c)['rows'] as $r)
        if (!isset($fix['waehlbar']) || ($fix['waehlbar'])($r)) $erlaubt[(int)$r['_id']] = true;
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($i) => isset($erlaubt[$i]))));
    if (!$ids) { header('Location: ?p=datenpruefung&err=' . rawurlencode('Keine (gültige) Zeile angehakt – nichts geändert.') . '#' . $key); exit; }
    $tab = $fix['tabelle'];
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $vorher = [];
        foreach (all("SELECT * FROM `$tab` WHERE id IN ($ph)", $ids) as $r) $vorher[(int)$r['id']] = $r;
        q(str_replace('{IDS}', $ph, $fix['update']), $ids);
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
          <?php foreach ($spalten as $s): ?><td><?= dp_wert($s, $r[$s]) ?></td><?php endforeach; ?>
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
