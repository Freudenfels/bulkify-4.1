<?php
// Warenlager – Bestandsübersicht mit Typ-Reitern.
// Charge-Typen (Rohstoff/Verpackung/Fertigware): Bestand aus Chargen. Betriebsmittel (Karton/Verbrauch/Inventar/Maschine/Sonstiges): einfacher Bestand.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

seed_charge_if_empty();

// Labels aus der zentralen Quelle (Block K: Bulkware / Fertigprodukt); Keys unverändert – sie steuern das WHERE.
$CHARGE_KAT = ['rohstoff'=>kategorie_label('rohstoff'), 'verpackung'=>kategorie_label('verpackung'), 'fertig'=>kategorie_label('fertig'), 'verkaufsfertig'=>kategorie_label('verkaufsfertig')];
$BM_KAT     = betriebsmittel_kategorien();
$ALLE_KAT   = $CHARGE_KAT + $BM_KAT;

// Reiter (Reihenfolge)
$TABS = ['alle'=>'Alle', 'rohstoff'=>'Rohstoffe', 'verpackung'=>'Verpackung', 'karton'=>'Kartons',
         'verbrauch'=>'Verbrauchsgüter', 'inventar'=>'Inventar', 'maschine'=>'Maschinen', 'sonstiges'=>'Sonstiges',
         'fertig'=>'Bulkware', 'verkaufsfertig'=>'Fertigprodukte'];

$q    = trim($_GET['q'] ?? '');
$kat  = $_GET['kat'] ?? 'alle';
if ($kat !== 'alle' && !array_key_exists($kat, $ALLE_KAT)) $kat = 'alle';
$sort = $_GET['sort'] ?? 'name';
$dir  = $_GET['dir']  ?? 'asc';
$istBM = ist_betriebsmittel_kat($kat);
$zeigeLeer = isset($_GET['leer']);   // Standard: nur Artikel mit Bestand > 0. ?leer=1 zeigt auch leere.

// Lager-Sicht: Warenlager = totale Übersicht (unser Lager 1 + Fremdlager Lager 2); Lager 1 = unser Bestand,
// Lager 2 = Ware, die Kunden gehört (charge.fremd_kunde_id). So ist direkt sichtbar, wo was liegt – alle
// Steuerungen bleiben im Warenlager. Das Fragment wird in die Charge-Summen eingesetzt (kein User-Input).
$ORTE = ['alle' => 'Alle Lager', 'lager1' => 'Lager 1', 'lager2' => 'Lager 2 (Fremdlager)'];
$ort  = $_GET['ort'] ?? 'alle';
if (!array_key_exists($ort, $ORTE)) $ort = 'alle';
$ortSql = $ort === 'lager1' ? " AND c.fremd_kunde_id IS NULL"
        : ($ort === 'lager2' ? " AND c.fremd_kunde_id IS NOT NULL" : "");

if ($kat === 'alle') { $where = "i.kategorie IN ('" . implode("','", array_keys($ALLE_KAT)) . "')"; $params = []; }
else                 { $where = "i.kategorie=?"; $params = [$kat]; }

$rows = all("SELECT i.id,i.artikelnummer,i.name,i.kategorie,i.einheit,i.bestand_menge,i.mindestbestand,
             i.elektrisch,i.pruef_intervall_monate,i.letzte_pruefung,i.fremdprodukt,
             (SELECT GROUP_CONCAT(DISTINCT k.firma ORDER BY k.firma SEPARATOR ', ')
                FROM charge c JOIN kunden k ON k.id=c.fremd_kunde_id
                WHERE c.item_id=i.id AND c.fremd_kunde_id IS NOT NULL AND c.status IN ('frei','quarantaene','gesperrt') AND c.menge_verfuegbar>0) AS besitzer,
             (SELECT firma FROM kunden WHERE id=i.fremd_besitzer_id) AS besitzer_fix,
             (SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge c WHERE c.item_id=i.id AND c.status='frei'$ortSql) AS frei_charge,
             (SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge c WHERE c.item_id=i.id AND c.status='quarantaene'$ortSql) AS quarantaene,
             (SELECT COALESCE(SUM(menge_verfuegbar),0) FROM charge c WHERE c.item_id=i.id AND c.status='gesperrt'$ortSql) AS gesperrt,
             (SELECT COUNT(*) FROM charge c WHERE c.item_id=i.id AND c.status IN ('frei','quarantaene','gesperrt')$ortSql) AS n_chargen,
             (SELECT COUNT(DISTINCT COALESCE(NULLIF(c.einheit,''),'Stk')) FROM charge c WHERE c.item_id=i.id AND c.status IN ('frei','quarantaene','gesperrt') AND c.menge_verfuegbar>0$ortSql) AS n_einheit,
             (SELECT COALESCE(NULLIF(c.einheit,''),'Stk') FROM charge c WHERE c.item_id=i.id AND c.status IN ('frei','quarantaene','gesperrt') AND c.menge_verfuegbar>0$ortSql ORDER BY c.menge_verfuegbar DESC LIMIT 1) AS charge_einheit,
             (SELECT COALESCE(SUM(bp.menge),0) FROM bestellung_position bp JOIN bestellung b ON b.id=bp.bestellung_id
                WHERE bp.item_id=i.id AND b.status='bestellt' AND b.angekommen_am IS NULL) AS unterwegs
             FROM item i WHERE $where", $params);

// Bestand vereinheitlichen: Betriebsmittel = manueller Bestand, sonst Chargen-Bestand.
// 'frei' = frei verfügbar (Anzeige), 'gesamt' = physisch vorhanden (frei + Quarantäne + gesperrt).
foreach ($rows as &$r) {
    $bm = ist_betriebsmittel_kat($r['kategorie']);
    // Betriebsmittel (manueller Bestand) gehören uns – in der Lager-2-Sicht (Kundenware) zählen sie nicht.
    $bmBestand = ($bm && $ort === 'lager2') ? 0.0 : (float)$r['bestand_menge'];
    $r['frei']   = $bm ? $bmBestand : (float)$r['frei_charge'];
    $r['gesamt'] = $bm ? $bmBestand : ((float)$r['frei_charge'] + (float)$r['quarantaene'] + (float)$r['gesperrt']);
    if ($ort === 'lager2') $r['unterwegs'] = 0;   // „unterwegs" (unsere Bestellungen) ist im Fremdlager nicht relevant
}
unset($r);

// Nullbestände ausblenden (Standard): „leer" = physisch NICHTS da (auch keine Quarantäne/gesperrt).
// Ware in Quarantäne/gesperrt ist vorhanden (und wartet auf Freigabe) → bleibt sichtbar, auch wenn frei=0.
// Betriebsmittel (Maschinen/Inventar) sind Anlagegüter – immer sichtbar. ?leer=1 zeigt alles.
// „leer" = physisch nichts da UND nichts unterwegs. Bestellte, noch nicht eingegangene Ware (unterwegs > 0)
// bleibt sichtbar, auch wenn aktuell kein Bestand da ist – sonst sieht man nicht, dass etwas kommt.
$anzahlLeer = 0;
foreach ($rows as $r) if (!ist_betriebsmittel_kat($r['kategorie']) && (float)$r['gesamt'] <= 0 && (float)($r['unterwegs'] ?? 0) <= 0) $anzahlLeer++;
if (!$zeigeLeer) $rows = array_values(array_filter($rows, fn($r) => ist_betriebsmittel_kat($r['kategorie']) || (float)$r['gesamt'] > 0 || (float)($r['unterwegs'] ?? 0) > 0));

if ($q !== '') {
    $needle = mb_strtolower($q);
    $rows = array_filter($rows, function($r) use ($needle) {
        foreach (['name','artikelnummer'] as $f) if (mb_strpos(mb_strtolower((string)$r[$f]), $needle) !== false) return true;
        return false;
    });
}
$rows = bx_sort_rows(array_values($rows), $sort, $dir);

$mng = fn($x,$e) => $x > 0 ? rtrim(rtrim(number_format((float)$x,3,',','.'),'0'),',') . ' ' . h($e) : '<span class="muted">0</span>';
// Bestand-Zelle: frei verfügbar + Hinweis-Badges für Quarantäne/gesperrt (damit Ware, die nur in
// Quarantäne liegt, nicht fälschlich als „0/leer" wirkt).
$bestandCell = function($r) use ($mng, $ort) {
    // Einheit kommt aus der Charge (Regel „Einheit je Charge"); nur Betriebsmittel nutzen die Artikel-Einheit.
    $e = (!ist_betriebsmittel_kat($r['kategorie']) && !empty($r['charge_einheit'])) ? $r['charge_einheit'] : ($r['einheit'] ?: 'Stk');
    // A1: Chargen verschiedener Einheiten (z. B. kg + Stk) NICHT zu einer Summe addieren – je Einheit getrennt zeigen.
    if (!ist_betriebsmittel_kat($r['kategorie']) && (int)($r['n_einheit'] ?? 1) > 1) {
        $frei = charge_bestand_je_einheit((int)$r['id'], ['frei'], $ort);
        $qua  = charge_bestand_je_einheit((int)$r['id'], ['quarantaene'], $ort);
        $teile = [];
        foreach ($frei as $eh => $m) $teile[] = $mng($m, $eh);
        $out = $teile ? implode(' + ', $teile) : '<span class="muted">0</span>';
        foreach ($qua as $eh => $m) $out .= ' <span class="badge badge-warn">' . $mng($m, $eh) . ' Quar.</span>';
        return $out;
    }
    $out = $mng($r['frei'], $e);
    if ((float)($r['quarantaene'] ?? 0) > 0) $out .= ' <span class="badge badge-warn">' . $mng($r['quarantaene'], $e) . ' Quar.</span>';
    if ((float)($r['gesperrt'] ?? 0) > 0)    $out .= ' <span class="badge">' . $mng($r['gesperrt'], $e) . ' gesperrt</span>';
    return $out;
};
// „Unterwegs": bestellte, noch nicht eingegangene Menge (status='bestellt', ohne angekommen_am).
$unterwegsCell = fn($r) => (float)($r['unterwegs'] ?? 0) > 0
    ? bx_badge($mng($r['unterwegs'], $r['einheit'] ?: 'Stk') . ' unterwegs', 'info')
    : '<span class="muted">–</span>';
$detailUrl = function($r) {
    if (ist_betriebsmittel_kat($r['kategorie'])) return '?p=betriebsmittel&id=' . $r['id'];
    return $r['kategorie'] === 'verpackung' ? '?p=verpackung&id=' . $r['id'] : '?p=rohstoff&id=' . $r['id'];
};
$pruefRender = function($r) {
    if (empty($r['elektrisch'])) return '<span class="muted">–</span>';
    $s = pruefung_status($r);
    if (!$s) return '<span class="muted">–</span>';
    $kind = $s['stufe'] === 'faellig' ? 'err' : ($s['stufe'] === 'bald' || $s['stufe'] === 'offen' ? 'warn' : 'ok');
    $txt  = $s['datum'] ? date('d.m.Y', strtotime($s['datum'])) : $s['label'];
    return bx_badge($txt . ($s['stufe'] === 'faellig' ? ' · überfällig' : ($s['stufe'] === 'offen' ? ' · offen' : '')), $kind);
};

// Spalten je Reiter
if ($istBM) {
    $zeigePruef = in_array($kat, ['maschine','inventar','sonstiges'], true);
    $cols = [
        'artikelnummer' => ['label'=>'Art.-Nr.', 'sort'=>true],
        'name'          => ['label'=>'Name', 'sort'=>true],
        'frei'          => ['label'=>'Bestand', 'sort'=>true, 'num'=>true, 'render'=>$bestandCell],
    ];
    if ($zeigePruef) $cols['pruefung'] = ['label'=>'Geräteprüfung', 'render'=>$pruefRender];
} elseif ($kat === 'alle') {
    $cols = [
        'artikelnummer' => ['label'=>'Art.-Nr.', 'sort'=>true],
        'name'          => ['label'=>'Name', 'sort'=>true],
        'kategorie'     => ['label'=>'Typ', 'sort'=>true, 'render'=>fn($r)=> h($ALLE_KAT[$r['kategorie']] ?? $r['kategorie'])],
        'frei'          => ['label'=>'Bestand', 'sort'=>true, 'num'=>true, 'render'=>$bestandCell],
        'unterwegs'     => ['label'=>'Unterwegs', 'sort'=>true, 'num'=>true, 'render'=>$unterwegsCell],
        'pruefung'      => ['label'=>'Prüfung', 'render'=>$pruefRender],
    ];
} else {
    $cols = [
        'artikelnummer' => ['label'=>'Art.-Nr.', 'sort'=>true],
        'name'          => ['label'=>'Name', 'sort'=>true],
        'kategorie'     => ['label'=>'Kategorie', 'sort'=>true, 'render'=>fn($r)=> h($ALLE_KAT[$r['kategorie']] ?? $r['kategorie'])],
        'n_chargen'     => ['label'=>'Chargen', 'sort'=>true, 'num'=>true],
        'frei'          => ['label'=>'Bestand (frei)', 'sort'=>true, 'num'=>true, 'render'=>function($r) use ($mng, $ort) {
            if ((int)($r['n_einheit'] ?? 1) > 1) { $t = []; foreach (charge_bestand_je_einheit((int)$r['id'], ['frei'], $ort) as $eh=>$m) $t[] = $mng($m,$eh); return $t ? implode(' + ', $t) : '<span class="muted">0</span>'; }
            $e = (!ist_betriebsmittel_kat($r['kategorie']) && !empty($r['charge_einheit'])) ? $r['charge_einheit'] : $r['einheit'];
            return $mng($r['frei'],$e); }],
        'quarantaene'   => ['label'=>'Quarantäne', 'sort'=>true, 'num'=>true, 'render'=>function($r) use ($mng, $ort) {
            if ((int)($r['n_einheit'] ?? 1) > 1) { $t = []; foreach (charge_bestand_je_einheit((int)$r['id'], ['quarantaene'], $ort) as $eh=>$m) $t[] = '<span class="badge badge-warn">'.$mng($m,$eh).'</span>'; return $t ? implode(' ', $t) : '<span class="muted">–</span>'; }
            $e = (!ist_betriebsmittel_kat($r['kategorie']) && !empty($r['charge_einheit'])) ? $r['charge_einheit'] : $r['einheit'];
            return (float)$r['quarantaene']>0 ? '<span class="badge badge-warn">'.$mng($r['quarantaene'],$e).'</span>' : '<span class="muted">–</span>'; }],
        'unterwegs'     => ['label'=>'Unterwegs', 'sort'=>true, 'num'=>true, 'render'=>$unterwegsCell],
    ];
}
// Lager-2-Sicht (Fremdlager): Besitzer/Zuordnung zeigen – wem die Kundenware gehört (aus den Fremd-Chargen,
// Fallback: der am Fremdprodukt hinterlegte Besitzer).
if ($ort === 'lager2' && !$istBM) {
    $besitzerCell = fn($r) => h(($r['besitzer'] ?? '') !== '' ? (string)$r['besitzer'] : (($r['besitzer_fix'] ?? '') !== '' ? (string)$r['besitzer_fix'] : '–'));
    $cols['besitzer'] = ['label' => 'Besitzer', 'render' => $besitzerCell];
}

// Header-Aktion: auf Betriebsmittel-Reitern „Neu", sonst Wareneingang
$aktion = $istBM
    ? bx_btn('Neu: ' . $BM_KAT[$kat], '?p=betriebsmittel&id=neu&kat=' . h($kat), 'primary')
    : bx_btn('Wareneingang', '?p=wareneingang', 'primary');

render_header('lager', 'Warenlager');
bx_head('Warenlager', count($rows) . ' Artikel' . ($ort !== 'alle' ? ' · ' . $ORTE[$ort] : ''), $aktion);
$ortQ = $ort !== 'alle' ? '&ort=' . $ort : '';
?>
<?php // Lager-Sicht: Alle (totale Übersicht) · Lager 1 (unser Bestand) · Lager 2 (Fremdlager/Kundenware). ?>
<div class="settabs" style="margin:0 0 8px">
  <?php foreach ($ORTE as $ok => $olbl): ?>
    <a href="?p=lager&kat=<?= h($kat) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?><?= $zeigeLeer ? '&leer=1' : '' ?><?= $ok !== 'alle' ? '&ort=' . $ok : '' ?>" class="<?= $ort === $ok ? 'on' : '' ?>"><?= h($olbl) ?></a>
  <?php endforeach; ?>
</div>
<div class="settabs" style="margin:0 0 14px">
  <?php foreach ($TABS as $k => $lbl): ?>
    <a href="?p=lager&kat=<?= $k ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?><?= $zeigeLeer ? '&leer=1' : '' ?><?= $ortQ ?>" class="<?= $kat === $k ? 'on' : '' ?>"><?= h($lbl) ?></a>
  <?php endforeach; ?>
</div>
<form class="bx-listbar" method="get">
  <input type="hidden" name="p" value="lager">
  <input type="hidden" name="kat" value="<?= h($kat) ?>">
  <?php if ($ort !== 'alle'): ?><input type="hidden" name="ort" value="<?= h($ort) ?>"><?php endif; ?>
  <?php if ($zeigeLeer): ?><input type="hidden" name="leer" value="1"><?php endif; ?>
  <input class="bx-search" type="text" name="q" value="<?= h($q) ?>" placeholder="Suchen: Name, Art.-Nr …">
  <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
  <span style="flex:1"></span>
  <?php $umschaltUrl = '?p=lager&kat=' . h($kat) . ($q !== '' ? '&q=' . urlencode($q) : '') . ($zeigeLeer ? '' : '&leer=1') . $ortQ; ?>
  <a class="btn btn-ghost btn-sm" href="<?= $umschaltUrl ?>"><?= $zeigeLeer ? 'nur mit Bestand' : 'auch leere anzeigen' . ($anzahlLeer > 0 ? ' (' . $anzahlLeer . ')' : '') ?></a>
  <?php if (has_role('admin')): ?><a class="btn btn-ghost btn-sm" href="?p=warenart_pruefung" title="Bulkware vs. Fertigprodukt prüfen und korrigieren">Warenarten prüfen</a><?php endif; ?>
</form>
<?php
if ($istBM && !$rows && $q === '') {
    echo '<div class="bx-panel"><div class="muted">Noch keine ' . h($BM_KAT[$kat]) . ' angelegt. Über „Neu: ' . h($BM_KAT[$kat]) . '" oben rechts hinzufügen.</div></div>';
} else {
    bx_table($cols, $rows, [
        'baseUrl' => '?p=lager&kat=' . h($kat) . ($q !== '' ? '&q=' . urlencode($q) : '') . ($zeigeLeer ? '&leer=1' : '') . $ortQ,
        'sort'    => $sort,
        'dir'     => $dir,
        'rowUrl'  => $detailUrl,
        'empty'   => (!$zeigeLeer && $anzahlLeer > 0 && $q === '') ? 'Nichts auf Lager. ' . $anzahlLeer . ' Artikel ohne Bestand sind ausgeblendet – „auch leere anzeigen".' : 'Keine Artikel gefunden.',
    ]);
}
render_footer();
