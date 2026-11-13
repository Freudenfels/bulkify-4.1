<?php
// Produktion – die EINE Liste (Nico 11.11.2026, plan-produktion-gefuehrt.md): Reiter Plan · Läuft · Fertig.
// Plan = alles, was noch nicht begonnen hat (startklar, wartet auf Material, Material bestellt), in der Plan-Reihenfolge.
// Ein Klick öffnet immer die PA-Seite (?p=produktionsauftrag). Alte Reiter (bereit/wartet/bestellt) zeigen auf Plan.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/produktion_plan.php';   // Teil 2: Plan (Reihenfolge, Linie, machbar je Schritt)
require_once BX_ROOT . '/core/pa_ablauf.php';         // pa_noch_offen_text() – „Noch offen“ (13.11.2026)
produktion_plan_schema();
// Plan speichern (Reihenfolge per Ziehen, Datum/Linie/Mitarbeiter je Zeile) – AJAX, Antwort JSON.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'plan_speichern') {
    $n = produktion_plan_speichern((array)($_POST['reihenfolge'] ?? []), (array)($_POST['felder'] ?? []));
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true, 'n' => $n]); exit;
}
nach_antwort(fn() => material_komplett_pruefen());   // „Material komplett"-Meldung, höchstens alle 5 Min. (auch für Lager-App-Buchungen)

$q    = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'prio';
$dir  = $_GET['dir']  ?? 'asc';
$tab  = $_GET['tab']  ?? 'plan';
if (in_array($tab, ['bereit', 'wartet', 'bestellt'], true)) $tab = 'plan';   // alte Reiter -> Plan
if (!in_array($tab, ['plan', 'laufend', 'erledigt'], true)) $tab = 'plan';

// Teil 6 (10.11.2026): Sammel-Umstellung Eigen-/Fremdproduktion entfällt – der Weg wird je Auftrag im Pop-up „Weg festlegen“
// bzw. im Reiter Beschaffungsweg festgelegt (eine Stelle, mit Premix und Lager).

// Neuen Produktionsauftrag OHNE Kundenbezug anlegen (Lager-/Vorratsproduktion).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'neu') {
    $modus = ($_POST['modus'] ?? 'produkt') === 'bulk' ? 'bulk' : 'produkt';
    $prio  = max(1, min(3, (int)($_POST['prio'] ?? 2)));
    if ($modus === 'bulk') {
        // Nur Kapseln (Bulk) – auf Basis einer Rezeptur, Menge = Stück.
        $rid    = (int)($_POST['rezeptur_id'] ?? 0);
        $stueck = (int) str_replace(['.', ' '], '', (string)($_POST['stueck'] ?? '0'));
        if ($rid <= 0)   { $_SESSION['prod_flash'] = 'Bitte eine Rezeptur wählen.'; header('Location: ?p=produktion&neu=1'); exit; }
        $paid = produktionsauftrag_bulk_erstellen($rid, $stueck, $prio);
        if ($paid) { header('Location: ?p=produktionsauftrag&id=' . $paid . '&ok=1'); exit; }
        $_SESSION['prod_flash'] = 'Bulk-Produktionsauftrag konnte nicht angelegt werden (Rezeptur ungültig).';
        header('Location: ?p=produktion&neu=1'); exit;
    }
    $pid   = (int)($_POST['produkt_id'] ?? 0);
    $menge = (int) str_replace(['.', ' '], '', (string)($_POST['menge'] ?? '0'));
    $art   = ($_POST['produktionsart'] ?? 'eigen') === 'fremd' ? 'fremd' : 'eigen';
    if ($pid <= 0) { $_SESSION['prod_flash'] = 'Bitte ein Produkt wählen.'; header('Location: ?p=produktion&neu=1'); exit; }
    $paid = produktionsauftrag_lager_erstellen($pid, $menge, $art, $prio);
    if ($paid) { header('Location: ?p=produktionsauftrag&id=' . $paid . '&ok=1'); exit; }
    $_SESSION['prod_flash'] = 'Produktionsauftrag konnte nicht angelegt werden (Produkt ungültig).';
    header('Location: ?p=produktion&neu=1'); exit;
}

// Alle Produktionsaufträge laden (inkl. Fortschritt + nächste Station), Bereitschaft je Auftrag bestimmen.
$alle = all("SELECT pa.*, k.firma AS kunde_firma, a.etikett_freigegeben AS etikett_freigegeben,
             COALESCE(NULLIF(a.produkt_bezeichnung,''), NULLIF(p.name,''), CONCAT(rz.name, ' · Bulk')) AS produkt_name,
             (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id) AS n_total,
             (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS n_done,
             (SELECT station FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=0 ORDER BY s.sort LIMIT 1) AS naechste_station
             FROM produktionsauftrag pa
             LEFT JOIN kunden k ON k.id=pa.kunde_id LEFT JOIN produkt p ON p.id=pa.produkt_id
             LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
             LEFT JOIN auftrag a ON a.id=pa.auftrag_id
             WHERE " . pa_aktiv_sql());   // stornierte PAs und PAs stornierter Aufträge gehören nicht in die Produktion
// PreProduktionsauftrag: Vor-PAs (Status 'vorbereitung') gehören NICHT in die Produktionsliste – sie werden
// zuerst im Dashboard unter „Vor-Produktion" geprüft und freigegeben. Hier nur ein Hinweis-Banner mit Link.
$vorbereitungN = count(array_filter($alle, fn($r) => ($r['status'] ?? '') === 'vorbereitung'));
$alle = array_values(array_filter($alle, fn($r) => ($r['status'] ?? '') !== 'vorbereitung'));
// Bereitschaft (Material da?) sparsam bestimmen – die volle Prüfung (produktion_bereitschaft ->
// auftrag_bedarf) macht mehrere Abfragen je Auftrag. Sie ist NUR für noch nicht begonnene Aufträge
// nötig: 'erledigt' wird nie ausgewertet, und wer schon einen Schritt erledigt hat, „läuft" bereits
// (das weiß die Liste aus n_done, ohne weitere Abfrage). So bleibt die teure Prüfung den offenen vorbehalten.
// Diese Liste ist schreibfrei -> Bestandsabfragen dürfen request-lokal gecacht werden. Viele Aufträge
// teilen dieselben Artikel (Verpackung/Leerkapsel/Rohstoffe); so wird jeder Bestand nur EINMAL geholt.
$GLOBALS['bx_stock_cache'] = [];
// Vorladen in EINER Sammelabfrage statt je Auftrag einzeln: alle Auftrag-/Produktzeilen der noch nicht
// begonnenen Aufträge auf einmal holen und in den Cache legen (pa_row_cached/produkt_row_cached finden sie dann).
$vorPa = []; $vorProd = []; $vorAuf = [];
foreach ($alle as $r) {
    if ($r['status'] !== 'erledigt' && (int)$r['n_done'] === 0) {
        $vorPa[] = (int)$r['id'];
        if (!empty($r['produkt_id']))  $vorProd[] = (int)$r['produkt_id'];
        if (!empty($r['auftrag_id']))  $vorAuf[]  = (int)$r['auftrag_id'];
    }
}
$vorPa = array_values(array_unique($vorPa));
$vorProd = array_values(array_unique($vorProd));
$vorAuf = array_values(array_unique($vorAuf));
$sc = &$GLOBALS['bx_stock_cache'];
if ($vorPa) { $in = implode(',', array_fill(0, count($vorPa), '?'));
    foreach (all("SELECT * FROM produktionsauftrag WHERE id IN ($in)", $vorPa) as $row) $sc['pa:' . (int)$row['id']] = $row;
    // Erledigte Schritte je Auftrag gebündelt (default 0, dann aus der Gruppierung überschreiben).
    foreach ($vorPa as $id) $sc['schr:' . $id] = 0;
    foreach (all("SELECT pa_id, COUNT(*) AS n FROM produktion_schritt WHERE pa_id IN ($in) AND erledigt=1 GROUP BY pa_id", $vorPa) as $row) $sc['schr:' . (int)$row['pa_id']] = (int)$row['n'];
}
if ($vorProd) { $in = implode(',', array_fill(0, count($vorProd), '?'));
    foreach (all("SELECT * FROM produkt WHERE id IN ($in)", $vorProd) as $row) $sc['prod:' . (int)$row['id']] = $row;
    foreach (all("SELECT p.id, p.name, COALESCE(r.darreichungsform,'') AS form FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id IN ($in)", $vorProd) as $row)
        $sc['pbulk:' . (int)$row['id']] = ['name'=>$row['name'], 'form'=>$row['form']]; }
if ($vorAuf) { $in = implode(',', array_fill(0, count($vorAuf), '?'));
    foreach (all("SELECT * FROM auftrag WHERE id IN ($in)", $vorAuf) as $row) $sc['auf:' . (int)$row['id']] = $row;
    // Fertigware-Zukauf je Auftrag gebündelt: Anzahl fertig-Chargen + freie Summe (default 0).
    foreach ($vorAuf as $id) $sc['fw:' . $id] = ['n'=>0, 'frei'=>0.0, 'quar'=>0.0];
    foreach (all("SELECT c.auftrag_id, COUNT(*) AS n, COALESCE(SUM(CASE WHEN c.status='frei' THEN c.menge_verfuegbar ELSE 0 END),0) AS frei,
                         COALESCE(SUM(CASE WHEN c.status='quarantaene' THEN c.menge_verfuegbar ELSE 0 END),0) AS quar
                  FROM charge c JOIN item i ON i.id=c.item_id WHERE c.auftrag_id IN ($in) AND i.kategorie='fertig' GROUP BY c.auftrag_id", $vorAuf) as $row)
        $sc['fw:' . (int)$row['auftrag_id']] = ['n'=>(int)$row['n'], 'frei'=>(float)$row['frei'], 'quar'=>(float)$row['quar']]; }
unset($sc);
foreach ($alle as &$r) {
    if ($r['status'] === 'erledigt')        $r['_bereit'] = 'erledigt';
    elseif (empty($r['art_festgelegt_am'])) $r['_bereit'] = 'art_offen';   // Eigen/Fremd noch nicht festgelegt -> NICHT in Produktion
    elseif ((int)$r['n_done'] > 0)          $r['_bereit'] = 'laeuft';      // begonnen -> Material war da
    else {
        $ber = produktion_bereitschaft((int)$r['id']);
        $r['_bereit'] = $ber['status'];
        // Fehlt nur noch Material, das schon bestellt ist -> eigener Reiter „Material bestellt“ mit Termin.
        if ($r['_bereit'] === 'wartet') {
            $mb = produktion_material_bestellt($ber['fehlend'] ?? [], (int)($r['auftrag_id'] ?? 0));
            if ($mb['ja']) { $r['_bereit'] = 'bestellt'; $r['_eta'] = $mb['eta']; }
        }
    }
}
unset($r);
// Cache bleibt für die (schreibfreie) Anzeige aktiv (Spalte „Kapsel/Tablette"); am Dateiende wieder aus.

// Einteilung in die Reiter – LAUFEND (in Produktion, mind. ein Schritt erledigt) ist jetzt EIGEN,
// damit „Produktionsbereit" nur noch die wirklich startklaren (Material da, noch nicht begonnen) zeigt.
$istErledigt = fn($r) => $r['status'] === 'erledigt';
$istLaufend  = fn($r) => !$istErledigt($r) && ($r['_bereit'] ?? '') === 'laeuft';
$istWartet   = fn($r) => !$istErledigt($r) && in_array(($r['_bereit'] ?? ''), ['wartet', 'art_offen'], true);   // wartet auf Material ODER Eigen/Fremd-Festlegung
$istBestellt = fn($r) => !$istErledigt($r) && ($r['_bereit'] ?? '') === 'bestellt';   // fehlendes Material ist komplett bestellt
$istBereit   = fn($r) => !$istErledigt($r) && !$istLaufend($r) && !$istWartet($r) && !$istBestellt($r);   // startklar: Material da, noch nicht begonnen

$anzLaufend  = count(array_filter($alle, $istLaufend));
$anzBereit   = count(array_filter($alle, $istBereit));
$anzWartet   = count(array_filter($alle, $istWartet));
$anzBestellt = count(array_filter($alle, $istBestellt));
$anzErledigt = count(array_filter($alle, $istErledigt));

$istPlan = fn($r) => $istBereit($r) || $istWartet($r) || $istBestellt($r);
$anzPlan = count(array_filter($alle, $istPlan));
if ($tab === 'erledigt')      $rows = array_filter($alle, $istErledigt);
elseif ($tab === 'laufend')   $rows = array_filter($alle, $istLaufend);
else                          $rows = array_filter($alle, $istPlan);

// Suche innerhalb des Reiters
if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function ($r) use ($needle) {
        foreach (['nummer', 'kunde_firma', 'produkt_name'] as $f)
            if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        return false;
    });
}
$rows = bx_sort_rows($rows, $sort, $dir);

// Spalte „Etikett": je Auftrag prüfen, ob eine Etikett-Datei hinterlegt ist – in EINER Abfrage
// für alle angezeigten Aufträge (statt je Zeile etikett_vorhanden() -> N+1).
$etikettDa = [];
$aufIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['auftrag_id'] ?? 0), $rows))));
if ($aufIds) { $in = implode(',', array_fill(0, count($aufIds), '?'));
    foreach (all("SELECT DISTINCT objekt_id FROM dokument WHERE objekt_typ='auftrag' AND typ='etikett' AND objekt_id IN ($in)", $aufIds) as $row)
        $etikettDa[(int)$row['objekt_id']] = true;
}

// Spalte „Kapsel/Tablette": Grunddaten aller ANGEZEIGTEN Produkte in EINER Abfrage vorladen
// (statt je Zeile eine) -> produktion_groesse_label() findet sie dann im Cache.
$grlIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['produkt_id'] ?? 0), $rows))));
if ($grlIds) { $in = implode(',', array_fill(0, count($grlIds), '?'));
    foreach (all("SELECT p.id, r.darreichungsform AS form, kg.name AS kapsel_name,
                         (SELECT COALESCE(SUM(z.menge_mg),0) FROM rezeptur_zutat z WHERE z.rezeptur_id=r.id) AS fg
                  FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                  LEFT JOIN kapselgroesse kg ON kg.id=r.kapselgroesse_id WHERE p.id IN ($in)", $grlIds) as $row)
        $GLOBALS['bx_stock_cache']['grl:' . (int)$row['id']] = $row;
}

// D1: bereits produzierte (als Fertigware gebuchte) Menge je PA – in EINER Abfrage gebündelt (sonst N+1).
$prodMap = [];
$paIdsP = array_values(array_unique(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $rows))));
if ($paIdsP) { $in = implode(',', array_fill(0, count($paIdsP), '?'));
    foreach (all("SELECT pa_id, COALESCE(SUM(menge),0) AS g FROM charge WHERE pa_id IN ($in) GROUP BY pa_id", $paIdsP) as $row)
        $prodMap[(int)$row['pa_id']] = (float)$row['g'];
}
$nfPkg = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

$cols = [
    // Prio kompakt: nur ein farbiger Punkt (Tooltip = Klartext), damit die Spalte schmal bleibt.
    'prio'         => ['label' => 'Prio', 'sort' => true, 'render' => function($r){
                        $p = (int)($r['prio'] ?? 2);
                        $farbe = $p === 1 ? '#d64545' : ($p === 3 ? '#9aa0a6' : '#2b6cd4');
                        $txt   = $p === 1 ? 'Hoch' : ($p === 3 ? 'Niedrig' : 'Normal');
                        return '<span title="Priorität: ' . $txt . '" aria-label="Priorität: ' . $txt . '" style="display:inline-block;width:11px;height:11px;border-radius:50%;background:' . $farbe . '"></span>';
                     }],
    'bereit'       => ['label' => 'Bereit', 'render' => fn($r)=> ($r['_bereit'] ?? '') === 'art_offen' ? bx_badge('Eigen/Fremd offen','warn')
                          : bereitschaft_badge($r['_bereit'] ?? '') . (!empty($r['_eta']) ? ' <span class="muted" style="font-size:12px">bis ' . h(date('d.m.Y', strtotime((string)$r['_eta']))) . '</span>' : '')],
    'nummer'       => ['label' => 'Nummer', 'sort' => true],
    'kunde_firma'  => ['label' => 'Kunde', 'sort' => true, 'render' => fn($r)=> kunde_link($r['kunde_id'] ?? null, firma_kurz($r['kunde_firma']))],
    'produkt_name' => ['label' => 'Produkt', 'render' => function($r) use ($tab) {
                        $t = $r['produkt_name'] ? h($r['produkt_name']) : '<span class="muted">–</span>';
                        if ($tab !== 'laufend') return $t;
                        // 13.11.2026 (Werk-App Teil 2): gleiche Zeile wie Werk-App und Plan.
                        $m = function_exists('produktion_machbar_schritte') ? produktion_machbar_schritte((int)$r['id'], false) : [];
                        $o = pa_noch_offen_text((int)$r['id'], !empty($m['etikett_gesperrt']));
                        return $t . ($o !== '' ? '<div class="muted" style="font-size:12px;white-space:normal">' . h($o) . '</div>' : '');
                     }],
    'etikett'      => ['label' => 'Etikett', 'render' => function($r) use ($etikettDa) {
                        $aid = (int)($r['auftrag_id'] ?? 0);
                        // Bulk-/Lagerproduktion ohne Kundenauftrag -> kein Kunden-Etikett nötig.
                        if ($aid <= 0) return '<span class="muted" title="Bulk-/Lagerproduktion – kein Kunden-Etikett">–</span>';
                        if ((int)($r['etikett_freigegeben'] ?? 0) === 1) return bx_badge('freigegeben', 'ok');
                        if (!empty($etikettDa[$aid])) return '<span title="Etikett hinterlegt, Kundenfreigabe fehlt noch">' . bx_badge('nicht freigegeben', 'warn') . '</span>';
                        return '<span title="Kunde hat noch kein Etikett hinterlegt">' . bx_badge('fehlt', 'err') . '</span>';
                     }],
    'groesse'      => ['label' => 'Kapsel/Tablette', 'render' => function($r){ $g = produktion_groesse_label((int)($r['produkt_id'] ?? 0), true); return $g !== '' ? h($g) : '<span class="muted">–</span>'; }],
    'produktionsart' => ['label' => 'Art', 'render' => fn($r)=> empty($r['art_festgelegt_am'])
                        ? '<span title="Eigen/Fremd ist noch nicht festgelegt – erst danach geht der Auftrag in die Produktion">' . bx_badge('festlegen','err') . '</span>'
                        : (($r['produktionsart'] ?? 'fremd')==='eigen' ? bx_badge('Eigen','ok') : bx_badge('Fremd','info'))],
    'menge'        => ['label' => 'Menge', 'sort' => true, 'num' => true],
    // D1: produzierte Menge direkt in der Tabelle (gebucht / gesamt).
    'produziert'   => ['label' => 'Produziert', 'num' => true, 'render' => function($r) use ($prodMap, $nfPkg) {
                        $g = (float)($prodMap[(int)$r['id']] ?? 0); $z = (float)($r['menge'] ?? 0);
                        $farbe = $g <= 0.0001 ? 'var(--muted)' : ($g + 0.001 >= $z && $z > 0 ? 'var(--gruen,#1D9E75)' : 'inherit');
                        return '<span style="color:' . $farbe . '">' . $nfPkg($g) . '</span> <span class="muted">/ ' . $nfPkg($z) . '</span>';
                     }],
    'station'      => ['label' => 'Aktuelle Station', 'render' => function($r){
                        if (($r['status'] ?? '') === 'erledigt') return '<span class="bx-ok">fertig</span>';
                        $st = trim((string)($r['naechste_station'] ?? ''));
                        if ($st === '') return '<span class="muted">–</span>';
                        // Begonnen = „läuft: <Station>", noch nicht begonnen = „als Nächstes: <Station>".
                        return (int)($r['n_done'] ?? 0) > 0
                            ? '<strong>' . h($st) . '</strong>'
                            : '<span class="muted">als Nächstes: </span>' . h($st);
                     }],
    'fortschritt'  => ['label' => 'Fortschritt', 'render' => fn($r)=> (int)$r['n_done'].' / '.(int)$r['n_total']],
];
unset($cols['_sel']);

$TABS = ['plan' => 'Plan', 'laufend' => 'Läuft', 'erledigt' => 'Fertig'];
$TABCOUNT = ['plan' => $anzPlan, 'laufend' => $anzLaufend, 'erledigt' => $anzErledigt];
$sub = ['plan' => 'Aufträge im Plan (noch nicht begonnen) – startklare oben, nicht machbare grau mit Grund', 'laufend' => 'laufende Produktionen (schon begonnen)', 'erledigt' => 'abgeschlossene Produktionsaufträge'];

$flash = $_SESSION['prod_flash'] ?? null; unset($_SESSION['prod_flash']);
$zeigeNeu = isset($_GET['neu']);
render_header('produktion', 'Produktion');
bx_head('Produktion', count($rows) . ' ' . $sub[$tab], bx_btn('+ Neuer Produktionsauftrag', '?p=produktion&neu=1', 'primary'));
if ($flash) echo '<div class="bx-panel badge-ok" style="padding:8px 12px">' . h($flash) . '</div>';
// Übersicht auf einen Blick (anklickbar): echter Stand der Produktion nach Status.
$kpi = function(string $label, int $wert, string $href, string $farbe = '', bool $aktiv = false) {
    echo '<a href="' . h($href) . '" class="bx-card" style="margin:0;text-decoration:none;color:inherit;border:1px solid ' . ($aktiv ? 'var(--gruen)' : 'var(--line)') . ($aktiv ? ';background:var(--panel-2)' : '') . '">'
       . '<div class="k muted" style="font-size:12px">' . h($label) . '</div>'
       . '<div style="font-size:24px;line-height:1.2;margin-top:2px' . ($farbe && $wert > 0 ? ';color:' . $farbe : '') . '">' . $wert . '</div></a>';
};
echo '<div class="bx-cards" style="margin-bottom:16px">';
if (has_role('admin')) $kpi('Beschaffungsweg offen', (int)$vorbereitungN, '?p=produktion_vorbereitung', '#f59e0b');
$kpi('Startklar', $anzBereit, '?p=produktion&tab=plan', 'var(--gruen)', false);
$kpi('Wartet auf Material', $anzWartet + $anzBestellt, '?p=produktion&tab=plan', '#f59e0b', false);
$kpi('Läuft', $anzLaufend, '?p=produktion&tab=laufend', '', $tab === 'laufend');
$kpi('Fertig', $anzErledigt, '?p=produktion&tab=erledigt', '', $tab === 'erledigt');
echo '</div>';
if (!empty($vorbereitungN) && has_role('admin'))
    echo '<div class="bx-panel badge-warn" style="padding:10px 14px">' . (int)$vorbereitungN
       . ' Auftrag(e) warten auf <strong>Freigabe</strong> – am Auftrag Quelle festlegen und freigeben: '
       . bx_btn('Beschaffungsweg offen', '?p=produktion_vorbereitung', 'ghost') . '</div>';

if ($zeigeNeu):
    // Produkte für die Auswahl (tippbar mit Live-Filter). Mit Rezeptur zuerst (dort ist die Darreichungsform bekannt).
    $produkteNeu = all("SELECT p.id, p.name, p.nummer, p.einheiten_pro_packung AS epp, r.darreichungsform AS form
                        FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                        WHERE COALESCE(p.status,'')<>'inaktiv'   -- 13.11.2026
                        ORDER BY (p.rezeptur_id IS NULL), p.name");
    $DFORMN = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','gummi'=>'Fruchtgummi','gel'=>'Gel','pulver'=>'Pulver','fluessig'=>'Flüssig'];
    // Einheit-Wort + Label je Produkt einmal bauen (datalist + JS-Map nutzen dasselbe -> müssen identisch sein).
    $ehlWort = fn($form) => in_array($form, ['kapsel','softgel'], true) ? 'Kapseln' : ($form === 'tablette' ? 'Tabletten' : 'Stk');
    foreach ($produkteNeu as &$pn) {
        $epp = (int)$pn['epp']; $ehl = $ehlWort($pn['form'] ?? '');
        $mengeTeil = $epp > 0 ? number_format($epp, 0, ',', '.') . ' ' . $ehl : ($DFORMN[$pn['form'] ?? ''] ?? ($pn['form'] ?? ''));
        $pn['_ehl'] = $ehl;
        $pn['_lbl'] = trim($pn['name'] . ($pn['nummer'] ? ' · ' . $pn['nummer'] : '') . ($mengeTeil !== '' ? ' · ' . $mengeTeil : ''));
    }
    unset($pn);
    // Rezepturen für den Bulk-Modus (tippbar). Nur Kapsel/Softgel/Tablette sind als Bulk sinnvoll herstellbar,
    // wir zeigen aber alle (die Form steht im Label).
    $rezepteNeu = all("SELECT id, name, nummer, darreichungsform AS form FROM rezeptur WHERE COALESCE(status,'')<>'inaktiv' ORDER BY name");   // 13.11.2026
    foreach ($rezepteNeu as &$rn) {
        $rn['_ehl'] = $ehlWort($rn['form'] ?? '');
        $rn['_lbl'] = trim($rn['name'] . ($rn['nummer'] ? ' · ' . $rn['nummer'] : '') . (($rn['form'] ?? '') ? ' · ' . ($DFORMN[$rn['form']] ?? $rn['form']) : ''));
    }
    unset($rn);
?>
<div class="bx-panel" style="margin-bottom:16px">
  <h2 style="margin-top:0">Neuer Produktionsauftrag <span class="muted" style="font-weight:400;font-size:13px">ohne Kundenbezug – z. B. Lager-/Vorratsproduktion</span></h2>
  <div class="bx-row" style="gap:24px;margin-bottom:var(--sp-4)">
    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:8px;margin:0"><input type="radio" name="modus_ui" value="produkt" checked style="width:auto"> Fertiges Produkt (mit Verpackung)</label>
    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:8px;margin:0"><input type="radio" name="modus_ui" value="bulk" style="width:auto"> Nur Kapseln (Bulk, ohne Verpackung)</label>
  </div>
  <form method="post" class="bx-form" style="margin-bottom:0" data-busy="Lege an …">
    <input type="hidden" name="aktion" value="neu">
    <input type="hidden" name="modus" id="modusField" value="produkt">
    <div class="bx-grid">
      <!-- Modus: Fertiges Produkt -->
      <div class="bx-field modus-produkt">
        <label>Produkt</label>
        <input type="text" class="prodpick-txt" list="prod_dl" autocomplete="off" placeholder="Produkt tippen …">
        <input type="hidden" name="produkt_id" class="prodpick-id">
        <datalist id="prod_dl"><?php foreach ($produkteNeu as $p): ?><option value="<?= h($p['_lbl']) ?>"></option><?php endforeach; ?></datalist>
      </div>
      <div class="bx-field modus-produkt"><label>Menge (Packungen)</label><input type="number" name="menge" id="pmenge" min="1" step="1" value="1"></div>
      <div class="bx-field modus-produkt"><label>Produktionsart</label>
        <select name="produktionsart">
          <option value="eigen" selected>Eigenproduktion</option>
          <option value="fremd">Fremdproduktion (Zukauf)</option>
        </select>
      </div>
      <!-- Modus: Nur Kapseln (Bulk) -->
      <div class="bx-field modus-bulk" style="display:none">
        <label>Rezeptur</label>
        <input type="text" class="rezpick-txt" list="rez_dl" autocomplete="off" placeholder="Rezeptur tippen …">
        <input type="hidden" name="rezeptur_id" class="rezpick-id">
        <datalist id="rez_dl"><?php foreach ($rezepteNeu as $rz): ?><option value="<?= h($rz['_lbl']) ?>"></option><?php endforeach; ?></datalist>
      </div>
      <div class="bx-field modus-bulk" style="display:none"><label>Stückzahl (Kapseln)</label><input type="number" name="stueck" min="1" step="1" value="1000"></div>
      <!-- gemeinsam -->
      <div class="bx-field"><label>Priorität</label>
        <select name="prio">
          <option value="2" selected>Normal</option>
          <option value="1">Hoch</option>
          <option value="3">Niedrig</option>
        </select>
      </div>
    </div>
    <div class="muted" style="font-size:12px;margin:2px 0 14px" id="modusHinweis">&nbsp;</div>
    <div class="bx-row" style="gap:8px;margin:0">
      <button class="btn btn-primary" type="submit">Anlegen</button>
      <a class="btn btn-ghost" href="?p=produktion&tab=<?= h($tab) ?>">Abbrechen</a>
    </div>
  </form>
  <script>
  (function(){
    var pmap = {};   // Produkt-Label -> {id, epp, ehl}
    <?php foreach ($produkteNeu as $p): ?>
    pmap[<?= json_encode($p['_lbl'], JSON_UNESCAPED_UNICODE) ?>] = {id:<?= (int)$p['id'] ?>, epp:<?= (int)$p['epp'] ?>, ehl:<?= json_encode($p['_ehl'], JSON_UNESCAPED_UNICODE) ?>};
    <?php endforeach; ?>
    var rmap = {};   // Rezeptur-Label -> {id, ehl}
    <?php foreach ($rezepteNeu as $rz): ?>
    rmap[<?= json_encode($rz['_lbl'], JSON_UNESCAPED_UNICODE) ?>] = {id:<?= (int)$rz['id'] ?>, ehl:<?= json_encode($rz['_ehl'], JSON_UNESCAPED_UNICODE) ?>};
    <?php endforeach; ?>
    var pt = document.querySelector('.prodpick-txt'), ph = document.querySelector('.prodpick-id'), pm = document.getElementById('pmenge');
    var rt = document.querySelector('.rezpick-txt'), rh = document.querySelector('.rezpick-id');
    var mf = document.getElementById('modusField'), hinweis = document.getElementById('modusHinweis');
    var BASIS_P = 'Menge = Anzahl Packungen; der Materialbedarf skaliert über die Einheiten je Packung des Produkts. Fertigware geht als eigener Lagerbestand ein.';
    var BASIS_B = 'Bulk = nur Kapseln herstellen (Rohstoffe + Leerkapseln), ohne Verpacken/Etikettieren. Die fertigen Kapseln gehen als eigene Bulk-Charge ins Lager und können später verpackt werden.';
    function pupd(){
      var p = pmap[(pt.value||'').trim()] || null; ph.value = p ? p.id : '';
      if (mf.value !== 'produkt') return;
      if (!p) { hinweis.textContent = BASIS_P; hinweis.style.color=''; return; }
      if (!p.epp) { hinweis.textContent = 'Einheiten je Packung am Produkt nicht gepflegt – bitte am Produkt ergänzen.'; hinweis.style.color='var(--err)'; return; }
      var ges = (parseInt((pm.value||'0'),10)||0) * p.epp;
      hinweis.textContent = p.epp.toLocaleString('de-DE') + ' ' + p.ehl + ' je Packung · Gesamt: ' + ges.toLocaleString('de-DE') + ' ' + p.ehl;
      hinweis.style.color='';
    }
    function rsync(){ var r = rmap[(rt.value||'').trim()]; rh.value = r ? r.id : ''; }
    if (pt){ pt.addEventListener('input', pupd); pt.addEventListener('change', pupd); }
    if (pm) pm.addEventListener('input', pupd);
    if (rt){ rt.addEventListener('input', rsync); rt.addEventListener('change', rsync); }
    function setModus(mo){
      mf.value = mo;
      document.querySelectorAll('.modus-produkt').forEach(function(e){ e.style.display = mo==='produkt' ? '' : 'none'; });
      document.querySelectorAll('.modus-bulk').forEach(function(e){ e.style.display = mo==='bulk' ? '' : 'none'; });
      if (mo==='bulk') { hinweis.textContent = BASIS_B; hinweis.style.color=''; rt.focus(); }
      else { pt.focus(); pupd(); }
    }
    document.querySelectorAll('input[name="modus_ui"]').forEach(function(r){ r.addEventListener('change', function(){ setModus(this.value); }); });
    setModus('produkt');
  })();
  </script>
</div>
<?php endif; ?>
<div class="settabs">
  <?php foreach ($TABS as $key => $lbl): ?>
    <a href="?p=produktion&tab=<?= $key ?>" class="<?= $tab===$key?'on':'' ?>"><?= h($lbl) ?><?= $TABCOUNT[$key] ? ' (' . $TABCOUNT[$key] . ')' : '' ?></a>
  <?php endforeach; ?>
</div>

<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="produktion">
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Nummer, Kunde, Produkt …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <?php if ($q !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=produktion&tab=<?= h($tab) ?>">zurücksetzen</a><?php endif; ?>
</form>
<?php

if ($tab === 'plan') { require __DIR__ . '/_plan.php'; unset($GLOBALS['bx_stock_cache']); render_footer(); return; }
bx_table($cols, array_values($rows), [
    'baseUrl' => '?p=produktion&tab=' . $tab . ($q !== '' ? '&q=' . urlencode($q) : ''),
    'sort'    => $sort,
    'dir'     => $dir,
    // D2: Klick führt IMMER auf die Produktionsauftrag-Übersicht (schneller Überblick: Status, produzierte
    // Menge, Charge, fehlendes Material, Fortschritt). Die geführte Produktion ist von dort per Button erreichbar.
    'rowUrl'  => fn($r) => '?p=produktionsauftrag&id=' . $r['id'],
    'empty'   => match ($tab) {
        'laufend'  => 'Aktuell läuft keine Produktion.',
        'erledigt' => 'Noch keine abgeschlossenen Produktionsaufträge.',
        default    => 'Nichts im Plan.',
    },
]);
?>

<?php
unset($GLOBALS['bx_stock_cache']);   // Anzeige fertig -> Cache aus (falls danach noch etwas rechnet)
render_footer();
