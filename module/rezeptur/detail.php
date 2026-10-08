<?php
// Rezeptur anlegen & bearbeiten – Kopf + Zutaten + Live-Deklaration (mg, % NRV) + Kosten
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/anfrage_ui.php';   // Preisanfrage-Popup + Status je Zutat

$DFORM = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','gummi'=>'Fruchtgummi','gel'=>'Gel','pulver'=>'Pulver','fluessig'=>'Flüssig'];
$FORMLBL = ['pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig','oel'=>'Öl','paste'=>'Paste','kristallin'=>'Kristallin'];
$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    $aktion = $_POST['aktion'] ?? '';
    // Lebenszyklus setzen
    if (!$neu && $aktion === 'status_setzen') {
        $ziel = $_POST['ziel'] ?? '';
        if (in_array($ziel, ['entwurf','vorschlag','freigegeben','eingefroren'], true)) {
            // Beim Weiterschalten (z. B. erneut als Vorschlag) den alten Ablehnungsgrund entfernen.
            q("UPDATE rezeptur SET status=?, ablehnung_grund=NULL WHERE id=?", [$ziel, (int)$id]);
            // Verknüpfte Anfrage nachziehen: erneut als Vorschlag/freigeben → wieder „beantwortet".
            if (in_array($ziel, ['vorschlag','freigegeben','eingefroren'], true))
                q("UPDATE rezeptur_anfrage SET status='beantwortet' WHERE rezeptur_id=?", [(int)$id]);
            // Beim Freigeben/Einfrieren die Nährwert-Deklaration festschreiben (Snapshot), damit sie sich
            // später nicht verschiebt, wenn Rohstoffdaten wechseln. Überschreibt keine manuelle Pflege.
            if (in_array($ziel, ['freigegeben','eingefroren'], true)) rezeptur_naehrwerte_snapshot((int)$id);
            $kid = scalar("SELECT kunde_id FROM rezeptur WHERE id=?", [(int)$id]);
            $lbl = ['vorschlag'=>'als Vorschlag gesendet','eingefroren'=>'freigegeben & eingefroren (verbindlich)','freigegeben'=>'freigegeben','entwurf'=>'wieder zur Bearbeitung geöffnet'][$ziel] ?? $ziel;
            if ($kid) log_aktivitaet('kunde', (int)$kid, 'team', 'Rezeptur ' . scalar("SELECT nummer FROM rezeptur WHERE id=?", [(int)$id]) . ' ' . $lbl . '.', 'rezeptur', 'rezeptur', (int)$id);
        }
        header('Location: ?p=rezeptur_detail&id=' . $id); exit;
    }
    // Überarbeiten starten: eine eingefrorene/freigegebene Rezeptur temporär zum Bearbeiten entsperren
    // (nur Admin). Der gespeicherte Status bleibt unverändert – der Kunde sieht währenddessen nichts.
    if (!$neu && $aktion === 'ueberarbeiten_start') {
        $st = (string) scalar("SELECT status FROM rezeptur WHERE id=?", [(int)$id]);
        if (has_role('admin') && in_array($st, ['freigegeben','eingefroren'], true)) $_SESSION['rez_unlock'][(int)$id] = $st;
        header('Location: ?p=rezeptur_detail&id=' . $id . '#zutaten'); exit;
    }
    // Überarbeiten abbrechen (ohne Speichern): Entsperrung verwerfen, nichts ändern.
    if (!$neu && $aktion === 'ueberarbeiten_abbrechen') {
        unset($_SESSION['rez_unlock'][(int)$id]);
        header('Location: ?p=rezeptur_detail&id=' . $id); exit;
    }
    // Nährwert-Deklaration: manuell speichern (Override) – erlaubt AUCH im gesperrten Zustand, denn genau
    // dafür ist der Override da (eine festgeschriebene Deklaration korrigieren).
    if (!$neu && $aktion === 'naehrwerte_speichern') {
        $rows = [];
        $nn = $_POST['n_name'] ?? []; $nm = $_POST['n_mg'] ?? []; $ne = $_POST['n_einheit'] ?? []; $nv = $_POST['n_nrv'] ?? [];
        foreach ($nn as $i => $nm0) $rows[] = ['name'=>$nm0, 'mg'=>$nm[$i] ?? 0, 'einheit'=>$ne[$i] ?? 'mg', 'nrv'=>$nv[$i] ?? ''];
        rezeptur_naehrwerte_speichern((int)$id, $rows);
        header('Location: ?p=rezeptur_detail&id=' . $id . '&nwok=1'); exit;
    }
    // Jetzt festschreiben (Snapshot der aktuell abgeleiteten Werte), ohne Statuswechsel.
    if (!$neu && $aktion === 'naehrwerte_fixieren') {
        rezeptur_naehrwerte_snapshot((int)$id);
        header('Location: ?p=rezeptur_detail&id=' . $id . '&nwok=1'); exit;
    }
    // Zurück auf automatisch (Live-Ableitung aus den Rohstoffen).
    if (!$neu && $aktion === 'naehrwerte_auto') {
        rezeptur_naehrwerte_zuruecksetzen((int)$id);
        header('Location: ?p=rezeptur_detail&id=' . $id . '&nwauto=1'); exit;
    }
    // Neue Version (Kopie als Entwurf)
    if (!$neu && $aktion === 'neue_version') {
        $o = one("SELECT * FROM rezeptur WHERE id=?", [(int)$id]);
        q("INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,status,notiz) VALUES (?,?,?,?,'entwurf',?)",
          [naechste_nummer('RZ'), $o['name'] . ' (Kopie)', $o['kunde_id'], $o['darreichungsform'], $o['notiz']]);
        $nid = insert_id();
        foreach (all("SELECT * FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort,id", [(int)$id]) as $z)
            q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)", [$nid, $z['item_id'], $z['bezeichnung'], $z['menge_mg'], $z['sort']]);
        rezeptur_bulkitem((int)$nid);   // koppelbares Lager-Bulk-Item sofort anlegen
        header('Location: ?p=rezeptur_detail&id=' . $nid . '&gespeichert=1'); exit;
    }
    // Lieferanten-Preisanfrage (Fremdfertigung) zurückziehen – nur solange kein Preis abgegeben wurde.
    if (!$neu && $aktion === 'anfrage_zurueck') {
        $aid = (int)($_POST['anfrage_id'] ?? 0);
        $gehoert = $aid && (int) scalar("SELECT COUNT(*) FROM lieferant_anfrage WHERE id=? AND rezeptur_id=? AND art='fertigprodukt'", [$aid, (int)$id]) > 0;
        $ok = $gehoert && lieferant_anfrage_zuruckziehen($aid);
        header('Location: ?p=rezeptur_detail&id=' . $id . ($ok ? '&anfzurueck=1' : '&anffehler=1')); exit;
    }
    // Rezeptur löschen (nur Admin) – geht in jedem Status, solange sie nicht verwendet wird.
    if (!$neu && $aktion === 'loeschen') {
        if (!has_role('admin')) { header('Location: ?p=rezeptur_detail&id=' . $id); exit; }
        $r = rezeptur_loeschen((int)$id);
        if (!empty($r['ok'])) { header('Location: ?p=rezeptur&geloescht=1'); exit; }
        $_SESSION['rez_del_fehler'] = $r['fehler'] ?? 'Löschen fehlgeschlagen.';
        header('Location: ?p=rezeptur_detail&id=' . $id); exit;
    }
    // Bearbeitung gesperrt, wenn eingefroren/freigegeben – außer im (Admin-)Überarbeitungsmodus.
    $gesperrtPost = !$neu && in_array((string) scalar("SELECT status FROM rezeptur WHERE id=?", [(int)$id]), ['freigegeben','eingefroren'], true);
    $umbauPost    = !$neu && isset($_SESSION['rez_unlock'][(int)$id]) && has_role('admin');
    if ($gesperrtPost && !$umbauPost) {
        header('Location: ?p=rezeptur_detail&id=' . $id); exit;
    }
    if ($f('name') === '') {
        $fehler = 'Name ist ein Pflichtfeld.';
    } else {
        $kunde_id = ($_POST['kunde_id'] ?? '') !== '' ? (int)$_POST['kunde_id'] : null;
        // Kunde gewählt = die eigene Rezeptur DIESES Kunden (exklusiv, nicht für alle sichtbar);
        // kein Kunde = Hausrezeptur (Katalog, für alle).
        $exkl = $kunde_id ? 1 : 0;
        // Kapselgröße nur bei Kapsel/Softgel speichern, sonst leeren
        $kapsGr = in_array($f('darreichungsform'), ['kapsel','softgel'], true) && ($_POST['kapselgroesse_id'] ?? '') !== ''
                  ? (int)$_POST['kapselgroesse_id'] : null;
        $synonyme = $f('synonyme');
        if ($neu) {
            q("INSERT INTO rezeptur (nummer,name,synonyme,kunde_id,darreichungsform,kapselgroesse_id,exklusiv,status,notiz) VALUES (?,?,?,?,?,?,?,?,?)",
              [naechste_nummer('RZ'), $f('name'), $synonyme ?: null, $kunde_id, $f('darreichungsform'), $kapsGr, $exkl, $f('status') ?: 'entwurf', $f('notiz')]);
            $id = insert_id();
        } else {
            // Umbenennung: den bisherigen Namen automatisch als Synonym merken (bleibt überall suchbar).
            $altName = (string) scalar("SELECT name FROM rezeptur WHERE id=?", [(int)$id]);
            if ($altName !== '' && $altName !== $f('name') && mb_stripos($synonyme, $altName) === false) {
                $synonyme = trim(($synonyme !== '' ? $synonyme . ', ' : '') . $altName);
            }
            q("UPDATE rezeptur SET name=?,synonyme=?,kunde_id=?,darreichungsform=?,kapselgroesse_id=?,exklusiv=?,status=?,notiz=? WHERE id=?",
              [$f('name'), $synonyme ?: null, $kunde_id, $f('darreichungsform'), $kapsGr, $exkl, $f('status'), $f('notiz'), (int)$id]);
        }
        // Zutaten synchronisieren
        q("DELETE FROM rezeptur_zutat WHERE rezeptur_id=?", [(int)$id]);
        $zi = $_POST['z_item'] ?? []; $zm = $_POST['z_menge'] ?? [];
        foreach ($zi as $i => $iid) {
            $iid = (int)$iid; if ($iid <= 0) continue;
            $mg = trim($zm[$i] ?? ''); $mg = $mg === '' ? 0 : $mg;
            $bez = scalar("SELECT name FROM item WHERE id=?", [$iid]);
            q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)",
              [(int)$id, $iid, $bez, $mg, $i]);
        }
        rezeptur_bulkitem((int)$id);   // jede Rezeptur hat ein koppelbares Lager-Bulk-Item
        // Überarbeitungsmodus abschließen: ursprünglichen (gesperrten) Status wiederherstellen und die
        // Nährwerte aus den jetzt korrigierten Zutaten frisch festschreiben; Entsperrung beenden.
        if (!$neu && isset($_SESSION['rez_unlock'][(int)$id]) && has_role('admin')) {
            $stBack = (string) $_SESSION['rez_unlock'][(int)$id];
            q("UPDATE rezeptur SET status=? WHERE id=?", [$stBack, (int)$id]);
            rezeptur_naehrwerte_zuruecksetzen((int)$id);
            rezeptur_naehrwerte_snapshot((int)$id);
            unset($_SESSION['rez_unlock'][(int)$id]);
        }
        header('Location: ?p=rezeptur_detail&id=' . $id . '&gespeichert=1'); exit;
    }
}

$r = $neu ? ['darreichungsform'=>'kapsel','status'=>'entwurf']
          : one("SELECT * FROM rezeptur WHERE id=?", [(int)$id]);
if (!$r) { $neu = true; $r = ['darreichungsform'=>'kapsel','status'=>'entwurf']; }
$v = fn($k) => h((string)($r[$k] ?? ''));
$df = $r['darreichungsform'] ?? 'kapsel';
$status = $r['status'] ?? 'entwurf';
// Überarbeitungsmodus (Admin): entsperrt eine eingefrorene/freigegebene Rezeptur temporär zum Umbau,
// ohne den gespeicherten Status zu ändern. Beim Speichern wird automatisch wieder gesichert.
$imUmbau = !$neu && isset($_SESSION['rez_unlock'][(int)$id]) && has_role('admin');
$gesperrt = !$neu && in_array($status, ['freigegeben','eingefroren'], true);
$locked = $gesperrt && !$imUmbau;

$kunden = all("SELECT id, firma FROM kunden ORDER BY firma");
$zutaten = $neu ? [] : all("SELECT * FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [(int)$id]);
// Lieferanten-Angebote für die Fremdfertigung dieser Rezeptur (u. a. aus dem v3-Import).
$liefAngebote = $neu ? [] : all("SELECT la.*, l.firma FROM rezeptur_lief_angebot la LEFT JOIN lieferanten l ON l.id=la.lieferant_id
                                 WHERE la.rezeptur_id=? ORDER BY (la.preis IS NULL), la.preis", [(int)$id]);
// Kundenpreise (u. a. aus dem Angebotsscan): welcher Kunde zu welchem Datum welchen VK hatte.
$kundenpreise = ($neu || !table_exists('rezeptur_kundenpreis')) ? [] : all(
    "SELECT kp.*, k.firma, k.kundennummer FROM rezeptur_kundenpreis kp LEFT JOIN kunden k ON k.id=kp.kunde_id
     WHERE kp.rezeptur_id=? ORDER BY k.firma, (kp.datum IS NULL), kp.datum DESC, kp.menge", [(int)$id]);

// Rohstoffe für die Auswahl – nach passender Form für die Darreichungsform sortiert (flüssig zuerst bei flüssig)
$prefForms = in_array($df, ['fluessig','softgel'], true) ? ['fluessig','oel'] : ['pulver','granulat','kristallin'];
$items = all("SELECT id,artikelnummer,name,form,ek_preis,preis_bezug,dichte FROM item WHERE kategorie='rohstoff' AND gesperrt=0");
usort($items, function($a,$b) use ($prefForms) {
    $pa = in_array($a['form'],$prefForms,true) ? 0 : 1;
    $pb = in_array($b['form'],$prefForms,true) ? 0 : 1;
    return $pa <=> $pb ?: strcasecmp($a['name'],$b['name']);
});

// Wirkstoffe je Item -> für JS-Berechnung
$wmap = [];
foreach (all("SELECT iw.item_id, n.name, n.nrv_wert, n.einheit, n.ie_mg, n.einheit_anzeige,
                     COALESCE(iw.gehalt_wert, iw.gehalt_prozent) AS gehalt_wert, COALESCE(iw.gehalt_einheit,'prozent') AS gehalt_einheit
              FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id") as $w) {
    // basePerMg = mg Nährstoff je 1 mg Rohstoff (zentrale Umrechnung, deckt %, I.E./g, I.E./kg, mg/g, µg/g ab)
    $wmap[$w['item_id']][] = ['name'=>$w['name'], 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit'],
        'anzeige'=>$w['einheit_anzeige'], 'ie_mg'=>$w['ie_mg']!==null?(float)$w['ie_mg']:null,
        'basePerMg'=>wirkstoff_mg_je_mg($w['gehalt_wert'], $w['gehalt_einheit'], $w['ie_mg'])];
}
// Rohstoffe mit nutzbarem Wirkstoffgehalt (für das Dropdown markieren): mind. ein Wirkstoff mit basePerMg>0.
$gehaltSet = [];
foreach ($wmap as $iid => $ws) foreach ($ws as $w) if ((float)($w['basePerMg'] ?? 0) > 0) { $gehaltSet[(int)$iid] = true; break; }
$ITEMS = [];
foreach ($items as $it) {
    $ITEMS[$it['id']] = [
        'name'=>$it['name'], 'form'=>$it['form'],
        'ek_preis'=>(float)$it['ek_preis'], 'preis_bezug'=>$it['preis_bezug'],
        'dichte'=>$it['dichte']!==null ? (float)$it['dichte'] : null,
        'wirkstoffe'=>$wmap[$it['id']] ?? [],
    ];
}

seed_kapselgroesse_if_empty();
$KAPSELN = all("SELECT id, name, fuellmenge_mg FROM kapselgroesse ORDER BY fuellmenge_mg ASC");
$istKapselForm = in_array($df, ['kapsel','softgel'], true);

render_header('rezeptur', $neu ? 'Neue Rezeptur' : $r['name']);
bx_head($neu ? 'Neue Rezeptur' : $v('name'),
        $neu ? 'Formulierung anlegen' : trim($v('nummer') . ' · ' . ($DFORM[$df] ?? $df)),
        bx_btn('Zurück zur Liste', '?p=rezeptur', 'ghost'));
if (!$neu && !empty($r['angelegt'])) echo '<div class="muted" style="font-size:12px;margin:-6px 0 10px">Angelegt am ' . h(fmt_zeit($r['angelegt'], 'd.m.Y H:i')) . (!empty($r['aktualisiert']) && $r['aktualisiert'] !== $r['angelegt'] ? ' · zuletzt geändert ' . h(fmt_zeit($r['aktualisiert'], 'd.m.Y H:i')) : '') . ' Uhr</div>';
// Dubletten-Hinweis bei der Prüfung: hat DERSELBE Kunde schon eine Rezeptur mit demselben Namen?
if (!$neu && !empty($r['kunde_id'])) {
    $dupN = all("SELECT id, nummer FROM rezeptur WHERE kunde_id=? AND id<>? AND LOWER(TRIM(name))=LOWER(TRIM(?)) ORDER BY id",
                [(int)$r['kunde_id'], (int)$id, (string)$r['name']]);
    if ($dupN) {
        $links = implode(', ', array_map(fn($d) => '<a href="?p=rezeptur&id=' . (int)$d['id'] . '">' . h($d['nummer']) . '</a>', $dupN));
        echo '<div class="bx-panel badge-warn" style="padding:10px 14px"><strong style="font-weight:600">Mögliche Dublette:</strong> Dieser Kunde hat bereits ' . count($dupN) . ' Rezeptur(en) mit dem Namen „' . h($r['name']) . '": ' . $links . '. Bitte Namen eindeutig machen, damit der Kunde nicht zwei gleichnamige Rezepturen hat.</div>';
    }
}
// Vom Kunden aus einer Katalog-/Haus-Rezeptur weiterentwickelt: interne Basis-Herkunft anzeigen.
if (!$neu && !empty($r['basis_rezeptur_id'])) {
    $bn = one("SELECT id, nummer, name FROM rezeptur WHERE id=?", [(int)$r['basis_rezeptur_id']]);
    if ($bn) echo '<div class="bx-panel" style="padding:10px 14px;background:var(--panel-2);border-color:var(--gruen)"><strong>Vom Kunden weiterentwickelt</strong> aus Basis <a href="?p=rezeptur&id=' . (int)$bn['id'] . '">' . h($bn['nummer'] . ' ' . $bn['name']) . '</a>.</div>';
}
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['fa_ges'])) { $fm = (int)($_GET['fa_match'] ?? 0); $fg = (int)$_GET['fa_ges'];
  echo '<div class="bx-panel" style="padding:12px 16px;border-color:var(--gruen)">Aus Fastaction angelegt: <strong>' . $fm . ' von ' . $fg . '</strong> Zutaten automatisch einem Rohstoff zugeordnet. <strong>Bitte jede Zeile prüfen</strong> (Rohstoff, Menge, Kapselgröße), dann speichern. Nicht zugeordnete Zeilen brauchen noch die Rohstoff-Auswahl.</div>'; }
if (isset($_GET['gesendet'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Vorschlag an den Kunden gesendet – er sieht ihn jetzt in seinem Portal. Änderungen hier speichern und ggf. „Erneut als Vorschlag senden".</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';
$rezDelFehler = (string)($_SESSION['rez_del_fehler'] ?? ''); if ($rezDelFehler !== '') { echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h($rezDelFehler) . '</div>'; unset($_SESSION['rez_del_fehler']); }
?>
<?php // Verwendungs-Übersicht NUR nach einem fehlgeschlagenen Löschversuch zeigen (sonst überflüssig).
if (!$neu && $rezDelFehler !== ''): $rezVerw = rezeptur_verwendung((int)$id); if ($rezVerw): ?>
<div class="bx-panel" style="border-left:3px solid var(--warn)">
  <h2 style="margin-top:0;font-size:16px">Wo wird diese Rezeptur verwendet? <span class="muted" style="font-weight:400;font-size:13px">(<?= count($rezVerw) ?>)</span></h2>
  <p class="muted" style="margin-top:0;font-size:13px">Zum Löschen zuerst die mit <strong>Blocker</strong> markierten Verweise entfernen/ersetzen. Ein Klick öffnet die jeweilige Stelle.</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Typ</th><th>Eintrag</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rezVerw as $vv): ?>
      <tr>
        <td><?= h($vv['typ']) ?><?= !empty($vv['blocker']) ? ' ' . bx_badge('Blocker','warn') : '' ?></td>
        <td><?= $vv['url'] ? '<a href="' . h((string)$vv['url']) . '">' . h((string)$vv['label']) . '</a>' : h((string)$vv['label']) ?></td>
        <td style="text-align:right"><?= $vv['url'] ? '<a class="btn btn-ghost btn-sm" href="' . h((string)$vv['url']) . '">öffnen</a>' : '<span class="muted" style="font-size:12px">kein eigener Link</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; endif; ?>
<?php if (!$neu): ?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <div>Status: <?= match($status){'entwurf'=>bx_badge('Entwurf'),'vorschlag'=>bx_badge('Vorschlag','info'),'freigegeben'=>bx_badge('freigegeben','ok'),'eingefroren'=>bx_badge('eingefroren · verbindlich','warn'),'abgelehnt'=>bx_badge('vom Kunden abgelehnt','err'),default=>bx_badge($status)} ?></div>
    <div class="bx-row">
      <?php if ($status==='entwurf'): ?>
        <?php if (!empty($r['kunde_id'])): // Kundenspezifisch: der KUNDE nimmt den Vorschlag im Portal selbst an. ?>
          <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="vorschlag"><button class="btn btn-primary btn-sm" type="submit">Als Vorschlag an den Kunden senden</button></form>
          <?php if (has_role('admin')): ?><form method="post" style="display:inline" onsubmit="return confirm('Wirklich OHNE Kundenannahme verbindlich setzen? Normalerweise nimmt der Kunde den Vorschlag im Portal selbst an.');"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="eingefroren"><button class="btn btn-ghost btn-sm" type="submit" title="Nur für Ausnahmen (z. B. offline schon vereinbart). Normalerweise nimmt der Kunde selbst an.">Ohne Kundenannahme verbindlich setzen</button></form><?php endif; ?>
        <?php else: // Hausrezeptur (Katalog) – kein Kunde, der annimmt. Verhalten unverändert. ?>
          <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="vorschlag"><button class="btn btn-ghost btn-sm" type="submit">Als Vorschlag senden</button></form>
          <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="eingefroren"><button class="btn btn-primary btn-sm" type="submit">Freigeben &amp; einfrieren</button></form>
        <?php endif; ?>
      <?php elseif ($status==='vorschlag'): ?>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="entwurf"><button class="btn btn-ghost btn-sm" type="submit">zurück zu Entwurf</button></form>
        <?php if (!empty($r['kunde_id'])): ?>
          <span class="muted" style="font-size:12px;align-self:center">wartet auf Annahme durch den Kunden im Portal</span>
          <?php if (has_role('admin')): ?><form method="post" style="display:inline" onsubmit="return confirm('Wirklich OHNE Kundenannahme verbindlich setzen? Normalerweise nimmt der Kunde den Vorschlag im Portal selbst an.');"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="eingefroren"><button class="btn btn-ghost btn-sm" type="submit" title="Nur für Ausnahmen (z. B. offline schon vereinbart). Normalerweise nimmt der Kunde selbst an.">Ohne Kundenannahme verbindlich setzen</button></form><?php endif; ?>
        <?php else: ?>
          <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="eingefroren"><button class="btn btn-primary btn-sm" type="submit">Freigeben &amp; einfrieren</button></form>
        <?php endif; ?>
      <?php elseif ($status==='abgelehnt'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Überarbeiteten Vorschlag erneut an den Kunden senden?');"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="vorschlag"><button class="btn btn-primary btn-sm" type="submit">Erneut als Vorschlag senden</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="entwurf"><button class="btn btn-ghost btn-sm" type="submit">zurück zu Entwurf</button></form>
      <?php else: ?>
        <?php if (has_role('admin') && !$imUmbau): ?>
          <form method="post" style="display:inline"><input type="hidden" name="aktion" value="ueberarbeiten_start"><button class="btn btn-primary btn-sm" type="submit" title="Rohstoffe neu zuordnen, ohne den Status zu ändern – danach automatisch wieder gesichert">Überarbeiten</button></form>
        <?php endif; ?>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="neue_version"><button class="btn btn-ghost btn-sm" type="submit">Neue Version</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="entwurf"><button class="btn btn-danger btn-sm" type="submit">Bearbeitung öffnen</button></form>
      <?php endif; ?>
      <?php if (has_role('admin')): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Diese Rezeptur endgültig löschen? Das geht nur, wenn sie nicht mehr verwendet wird (Produkt/Angebot/Produktion).');"><input type="hidden" name="aktion" value="loeschen"><button class="btn btn-ghost btn-sm" type="submit" style="color:#8f231b">Löschen</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($status==='abgelehnt' && !empty($r['ablehnung_grund'])): ?>
    <div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;margin-top:10px;padding:10px 14px"><strong>Vom Kunden abgelehnt.</strong> Grund: <?= h($r['ablehnung_grund']) ?><div class="muted" style="margin-top:4px;font-size:12px">Zutaten unten anpassen und dann „Erneut als Vorschlag senden" – der Kunde sieht den überarbeiteten Vorschlag wieder im Portal.</div></div>
  <?php endif; ?>
  <?php if ($imUmbau): ?>
    <div class="bx-panel badge-warn" style="margin-top:8px;padding:10px 14px">
      <strong>Überarbeitungsmodus aktiv.</strong> Ordne die Zutaten unten den echten Lager-Rohstoffen zu und <strong>speichere</strong> – danach wird die Rezeptur automatisch wieder gesichert (Status bleibt „<?= h($status) ?>", die Nährwerte werden aus den korrigierten Zutaten neu festgeschrieben). Der Kunde sieht währenddessen nichts.
      <form method="post" style="display:inline;margin-left:8px"><input type="hidden" name="aktion" value="ueberarbeiten_abbrechen"><button class="btn btn-ghost btn-sm" type="submit">Abbrechen (ohne Speichern)</button></form>
    </div>
  <?php elseif ($locked): ?><div class="muted" style="margin-top:8px">Diese Rezeptur ist <strong>schreibgeschützt</strong> (verbindlich). Zum Rohstoff-Matchen „Überarbeiten", sonst „Neue Version" oder „Bearbeitung öffnen".</div><?php endif; ?>
</div>
<?php endif; ?>

<form method="post" class="bx-form">
  <fieldset <?= $locked ? 'disabled' : '' ?> style="border:0;padding:0;margin:0;min-width:0">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required></div>
    <div class="bx-field"><label>Synonyme / frühere Namen <?= bx_hint('Alternative oder alte Namen (z. B. wenn der Kunde umbenennt). Intern bekannt und überall mitsuchbar. Beim Umbenennen wird der alte Name automatisch hier ergänzt. Mehrere durch Komma trennen.') ?></label><input type="text" name="synonyme" placeholder="z. B. alter Produktname, Kunden-Kürzel" value="<?= $v('synonyme') ?>"></div>
    <div class="bx-field"><label>Kunde <?= bx_hint('Kunde gewählt = eigene Rezeptur DIESES Kunden (erscheint bei ihm, nur für ihn sichtbar). Leer = Hausrezeptur im Katalog (für alle).') ?></label>
      <select name="kunde_id">
        <option value="">– keiner (Hausrezeptur) –</option>
        <?php foreach ($kunden as $k): ?><option value="<?= $k['id'] ?>" <?= (int)($r['kunde_id']??0)===(int)$k['id']?'selected':'' ?>><?= h($k['firma']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Darreichungsform</label>
      <select name="darreichungsform" id="df" onchange="this.form.submit()">
        <?php foreach ($DFORM as $key=>$lbl): ?><option value="<?= $key ?>" <?= $df===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if ($istKapselForm): ?>
    <div class="bx-field" id="f_kapsel"><label>Kapselgröße <?= bx_hint('Vorschlag = kleinste passende Größe. Wird ins Produkt vererbt und bestimmt, wie viele Kapseln in ein Gebinde passen (Packungsgröße).') ?></label>
      <select name="kapselgroesse_id" id="kapselgroesse_id">
        <option value="">– automatisch (nach Füllgewicht) –</option>
        <?php foreach ($KAPSELN as $kg): ?><option value="<?= (int)$kg['id'] ?>" data-mg="<?= (int)$kg['fuellmenge_mg'] ?>" <?= (int)($r['kapselgroesse_id']??0)===(int)$kg['id']?'selected':'' ?>><?= h($kg['name']) ?> (bis <?= (int)$kg['fuellmenge_mg'] ?> mg)</option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="bx-field"><label>Status</label>
      <select name="status">
        <?php foreach (['entwurf'=>'Entwurf','vorschlag'=>'Vorschlag','freigegeben'=>'freigegeben','eingefroren'=>'eingefroren'] as $key=>$lbl): ?>
          <option value="<?= $key ?>" <?= ($r['status']??'')===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="bx-field"><label>Notiz</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
  </div>

  <div class="bx-panel" id="zutaten">
    <h2>Zutaten <?= bx_hint('Rohstoffe je Einheit (Kapsel/Portion) in mg. Auswahl nach Form vorsortiert.') ?></h2>
    <table class="bx-table" style="margin-bottom:10px">
      <thead><tr><th style="width:55%">Rohstoff</th><th style="width:160px">Menge (mg)</th><th></th></tr></thead>
      <tbody id="zutatrows">
        <?php
        // Rohstoff-Feld: tippbar mit Filter (datalist). Anzeige = Label, gespeichert wird die id (verstecktes Feld).
        $zlabel = fn($it) => implode(' · ', array_filter([$it['name'], ($FORMLBL[$it['form']] ?? $it['form']), $it['artikelnummer'] ?? '']))
            . (!empty($gehaltSet[(int)$it['id']]) ? ' · Gehalt ✓' : '');
        $itemById = []; foreach ($items as $it) $itemById[(int)$it['id']] = $it;
        // R-Nummer + CoA/Spec je Rohstoff – als Link/Popup direkt an der Zutat (auch bei festgesetzter, nicht editierbarer Rezeptur: Anchor-Links wirken trotz disabled fieldset).
        $ZNR = []; foreach (all("SELECT id, artikelnummer FROM item WHERE kategorie='rohstoff'") as $it) $ZNR[(int)$it['id']] = (string)($it['artikelnummer'] ?? '');
        $ITEMDOCS = [];
        foreach (all("SELECT objekt_id AS item_id, id, typ FROM dokument WHERE objekt_typ='item' AND typ IN ('spec','coa','analyse') ORDER BY id DESC") as $d)
            $ITEMDOCS[(int)$d['item_id']][] = ['id'=>(int)$d['id'], 'typ'=>(string)$d['typ']];
        $docTypLblZ = ['spec'=>'Spec','coa'=>'CoA','analyse'=>'Analyse'];
        $zActionsHtml = function($iid) use ($ZNR, $ITEMDOCS, $docTypLblZ) {
            $iid = (int)$iid; if (!$iid) return '';
            $nr = $ZNR[$iid] ?? '';
            $out = '<a href="?p=rohstoff&id=' . $iid . '" style="font-size:12px">↗ Rohstoff' . ($nr !== '' ? ' ' . h($nr) : '') . '</a>';
            // Unsere bulkify-Spezifikation (kundentauglich, allgemein – immer verfügbar).
            $out .= ' <span class="muted">·</span> <a href="#" style="font-size:12px" onclick="bxDocOeffnen(\'?p=spec_bulkify&id=' . $iid . '\',\'bulkify-Spezifikation\');return false">Spez. (bulkify)</a>';
            // Original-Unterlagen des Lieferanten (teamintern) zur Kontrolle.
            foreach ($ITEMDOCS[$iid] ?? [] as $d) {
                $lbl = ($docTypLblZ[$d['typ']] ?? $d['typ']) . ' (Lief.)';
                $out .= ' <span class="muted">·</span> <a href="#" style="font-size:12px" onclick="bxDocOeffnen(\'?p=dokument&id=' . (int)$d['id'] . '\',\'' . h(addslashes($lbl)) . '\');return false">' . h($lbl) . '</a>';
            }
            return $out;
        };
        $zr = $zutaten ?: [['item_id'=>'','menge_mg'=>'']]; foreach ($zr as $z):
          $zMatch = !empty($zutaten) ? rezeptur_zutat_match(!empty($z['item_id']) ? (int)$z['item_id'] : null) : 'ok';
          // Startwert fürs Rohstoff-Feld: gematchtes Label, sonst der ursprüngliche (Freitext-)Name als Suchhilfe.
          $zTxt = !empty($z['item_id']) && isset($itemById[(int)$z['item_id']]) ? $zlabel($itemById[(int)$z['item_id']]) : (string)($z['bezeichnung'] ?? '');
          $zBadge = ['frei'=>'nicht zugeordnet','tot'=>'Rohstoff fehlt','ohne_wirkstoff'=>'ohne Wirkstoffdaten'][$zMatch] ?? '';
        ?>
        <tr class="zutatrow">
          <td>
            <input type="text" class="zitem-txt" list="zutat_dl" autocomplete="off" placeholder="Rohstoff tippen …" style="width:100%<?= $zMatch!=='ok' ? ';border-color:var(--warn)' : '' ?>" value="<?= h($zTxt) ?>">
            <input type="hidden" name="z_item[]" class="zitem" value="<?= (int)($z['item_id'] ?? 0) ?: '' ?>">
            <div class="zactions" style="margin-top:4px"><?php if ($zBadge !== ''): ?><span class="muted" style="font-size:12px;color:var(--warn)">&#9888; <?= h($zBadge) ?></span> <?php endif; ?><?= $zActionsHtml($z['item_id'] ?? 0) ?></div>
          </td>
          <td><input type="number" step="0.001" name="z_menge[]" class="zmenge" value="<?= h($z['menge_mg']!==''&&$z['menge_mg']!==null ? rtrim(rtrim(number_format((float)$z['menge_mg'],3,'.',''),'0'),'.') : '') ?>"></td>
          <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.zutatrow').remove();recalc()">entfernen</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <datalist id="zutat_dl"><?php foreach ($items as $it): ?><option value="<?= h($zlabel($it)) ?>"></option><?php endforeach; ?></datalist>
    <button type="button" class="btn btn-ghost btn-sm" id="addZutat">+ Zutat</button>
  </div>

  <div class="bx-panel" id="ergebnis">
    <h2>Inhaltsstoffe &amp; Kalkulation <span class="muted" style="font-weight:400;font-size:13px">(pro Einheit)</span></h2>
    <div class="bx-cards" style="margin-bottom:16px">
      <div class="bx-card"><div class="k">Gesamtgewicht</div><div class="v" id="k_gewicht">–</div></div>
      <?php if ($istKapselForm): ?><div class="bx-card"><div class="k">Kapselgröße</div><div class="v" id="k_kapsel" style="font-size:16px">–</div></div><?php endif; ?>
      <div class="bx-card"><div class="k">Kosten / Einheit</div><div class="v" id="k_kosten">–</div></div>
      <div class="bx-card"><div class="k">Kosten / 1.000 Stück</div><div class="v" id="k_kosten1000">–</div></div>
    </div>

    <div style="font-weight:600;margin-bottom:8px">Inhaltsstoffe (wie auf dem Etikett)</div>
    <table class="bx-table"><thead><tr><th>Inhaltsstoff</th><th class="bx-num">Menge je Einheit</th><th class="bx-num">% NRV*</th></tr></thead>
      <tbody id="etikett"><tr><td colspan="3" class="muted">Zutaten wählen …</td></tr></tbody>
    </table>
    <div class="muted" style="margin-top:6px">* NRV = Prozent des Nährstoffbezugswerts pro Tag.</div>

    <div style="font-weight:600;margin:20px 0 8px">Summe je Nährstoff <span class="muted" style="font-weight:400">(gleiche Nährstoffe addiert)</span></div>
    <table class="bx-table"><thead><tr><th>Nährstoff</th><th class="bx-num">Gesamt je Einheit</th><th class="bx-num">% NRV</th></tr></thead>
      <tbody id="deklaration"><tr><td colspan="3" class="muted">Zutaten wählen …</td></tr></tbody>
    </table>
  </div>

  </fieldset>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <?php if (!$locked): ?><button class="btn btn-primary" type="submit"><?= $neu ? 'Rezeptur anlegen' : 'Speichern' ?></button><?php endif; ?>
    <a class="btn btn-ghost" href="?p=rezeptur">Zurück</a>
  </div>
</form>

<?php if (!$neu):
    // Nährwert-Deklaration der Rezeptur: normal live aus den Rohstoffen abgeleitet; festgeschrieben
    // (Snapshot beim Einfrieren) oder manuell korrigiert, wenn die Rohstoffdaten fehlen/nicht matchen.
    $nwFixiert   = rezeptur_naehrwerte_fixiert((int)$id);
    $nwEffektiv  = rezeptur_naehrwerte((int)$id);
    $nwManuell   = $nwFixiert && (int) scalar("SELECT COUNT(*) FROM rezeptur_naehrwert WHERE rezeptur_id=? AND quelle='manuell'", [(int)$id]) > 0;
    $nwBadge     = $nwFixiert ? ($nwManuell ? bx_badge('manuell gepflegt','info') : bx_badge('festgeschrieben (Snapshot)','ok')) : bx_badge('automatisch (aus Rohstoffen)');
    // Editor-Zeilen: effektive Werte in der jeweiligen Einheit (µg-Nährstoffe in µg anzeigen).
    $nwRows = [];
    foreach ($nwEffektiv as $n) {
        $einh = ($n['einheit'] ?? 'mg') === 'µg' ? 'µg' : 'mg';
        $anz  = $einh === 'µg' ? (float)$n['mg'] * 1000 : (float)$n['mg'];
        $nwRows[] = ['name'=>$n['name'], 'anz'=>$anz, 'einheit'=>$einh, 'nrv'=>$n['nrv']];
    }
    $nwFmt = fn($x) => rtrim(rtrim(number_format((float)$x, 4, ',', '.'), '0'), ',');
?>
<div class="bx-panel" id="naehrwerte">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0">Nährwerte der Rezeptur <span class="muted" style="font-weight:400;font-size:13px">(je Einheit)</span></h2>
    <div><?= $nwBadge ?></div>
  </div>
  <p class="muted" style="margin-top:6px">
    Normalerweise werden die Nährwerte automatisch aus den Wirkstoffdaten der Rohstoffe berechnet. Wenn ein
    Rohstoff keine Wirkstoffdaten hat oder nicht zum Lagerartikel passt, bleiben Werte leer – dann hier die
    korrekten Werte eintragen und festschreiben. Beim Einfrieren/Freigeben wird automatisch ein Snapshot gesetzt.
  </p>
  <?php if (!$nwFixiert && !$nwRows): ?>
    <div class="muted" style="margin:8px 0">Keine Wirkstoffdaten an den Rohstoffen hinterlegt – Deklaration ist aktuell leer. Trage sie unten ein.</div>
  <?php endif; ?>
  <form method="post" style="margin-top:8px">
    <input type="hidden" name="aktion" value="naehrwerte_speichern">
    <table class="bx-table" id="nwTab">
      <thead><tr><th>Nährstoff</th><th class="bx-num" style="width:160px">Menge je Einheit</th><th style="width:90px">Einheit</th><th class="bx-num" style="width:140px">NRV-Bezug</th><th style="width:40px"></th></tr></thead>
      <tbody>
      <?php $nwRender = $nwRows ?: [['name'=>'','anz'=>'','einheit'=>'mg','nrv'=>'']]; foreach ($nwRender as $row): ?>
        <tr>
          <td><input type="text" name="n_name[]" value="<?= h((string)$row['name']) ?>" placeholder="z. B. Magnesium" style="width:100%"></td>
          <td class="bx-num"><input type="text" name="n_mg[]" value="<?= $row['anz'] === '' ? '' : h($nwFmt($row['anz'])) ?>" style="width:100%;text-align:right"></td>
          <td><select name="n_einheit[]"><option value="mg"<?= ($row['einheit'] ?? 'mg')==='mg'?' selected':'' ?>>mg</option><option value="µg"<?= ($row['einheit'] ?? '')==='µg'?' selected':'' ?>>µg</option></select></td>
          <td class="bx-num"><input type="text" name="n_nrv[]" value="<?= ($row['nrv'] ?? '') !== '' && $row['nrv'] !== null ? h($nwFmt($row['nrv'])) : '' ?>" placeholder="optional" style="width:100%;text-align:right"></td>
          <td style="text-align:center"><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('tr').remove()">×</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="bx-row" style="margin-top:10px;gap:8px">
      <button type="button" class="btn btn-ghost btn-sm" onclick="nwAddRow()">+ Nährstoff</button>
      <span style="flex:1"></span>
      <?php if ($nwFixiert): ?>
        <button type="submit" formaction="?p=rezeptur_detail&id=<?= (int)$id ?>" class="btn btn-ghost" name="aktion" value="naehrwerte_auto" onclick="return confirm('Zurück auf automatische Berechnung? Die festgeschriebenen Werte werden verworfen.')">Zurück auf automatisch</button>
      <?php elseif ($nwRows): ?>
        <button type="submit" class="btn btn-ghost" name="aktion" value="naehrwerte_fixieren" title="Die aktuell abgeleiteten Werte unverändert festschreiben">Werte festschreiben</button>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">Speichern &amp; festschreiben</button>
    </div>
    <div class="muted" style="font-size:12px;margin-top:6px">NRV-Bezug = Nährstoffbezugswert (für die %-Spalte), in derselben Einheit wie oben. Leer lassen, wenn kein NRV existiert.</div>
  </form>
</div>
<script>
function nwAddRow(){
  var tb = document.querySelector('#nwTab tbody');
  var tr = document.createElement('tr');
  tr.innerHTML = '<td><input type="text" name="n_name[]" placeholder="z. B. Magnesium" style="width:100%"></td>'
    + '<td class="bx-num"><input type="text" name="n_mg[]" style="width:100%;text-align:right"></td>'
    + '<td><select name="n_einheit[]"><option value="mg">mg</option><option value="µg">µg</option></select></td>'
    + '<td class="bx-num"><input type="text" name="n_nrv[]" placeholder="optional" style="width:100%;text-align:right"></td>'
    + '<td style="text-align:center"><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest(\'tr\').remove()">×</button></td>';
  tb.appendChild(tr);
}
</script>
<?php endif; ?>

<?php // Rohstoffpreise je Zutat – damit man schon an der Rezeptur sieht, was der Einkauf kostet
      // und wo noch ein Preis fehlt. Anfrage per Popup (Lieferanten auswählen). Nur für gespeicherte
      // Rezepturen mit Zutaten, die einen Lagerartikel haben. ?>
<?php $rzZutaten = $neu ? [] : all("SELECT DISTINCT z.item_id, i.name, i.artikelnummer, i.preis_bezug, i.einheit
        FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? AND z.item_id IS NOT NULL ORDER BY i.name", [(int)$id]);
   if ($rzZutaten): $mitPreis = 0; foreach ($rzZutaten as $rz) if (anfrage_status((int)$rz['item_id']) === 'preise') $mitPreis++;
   // CoA/Spec-Unterlagen je Rohstoff (Original, nur fürs Team) – für Vorschau + Download.
   $rzDocs = []; $rzItemIds = array_values(array_unique(array_map(fn($z) => (int)$z['item_id'], $rzZutaten)));
   if ($rzItemIds) { $inItems = implode(',', $rzItemIds);
       foreach (all("SELECT objekt_id AS item_id, id, typ, titel, datei_orig FROM dokument
                     WHERE objekt_typ='item' AND objekt_id IN ($inItems) AND typ IN ('spec','coa','analyse') ORDER BY id DESC") as $d)
           $rzDocs[(int)$d['item_id']][] = $d;
   }
   $docTypLbl = ['spec' => 'Spec', 'coa' => 'CoA', 'analyse' => 'Analyse']; ?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <h2 style="margin:0">Rohstoffpreise</h2>
    <?php if ($mitPreis > 0): ?><span><?= bx_badge('Preise liegen vor', 'ok') ?> <span class="muted" style="font-size:12px"><?= $mitPreis ?>/<?= count($rzZutaten) ?> Rohstoffe</span></span><?php endif; ?>
  </div>
  <p class="muted" style="margin-top:4px">Was kostet uns die Rezeptur beim Lieferanten? Wo kein Preis steht, per „Preis anfragen" bei den Lieferanten einholen.</p>
  <?php if (isset($_GET['angefragt'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px"><?= (int)$_GET['angefragt'] ?> Preisanfrage(n) verschickt<?= isset($_GET['gemailt']) && (int)$_GET['gemailt'] > 0 ? ', davon ' . (int)$_GET['gemailt'] . ' per E-Mail' : '' ?>.</div><?php endif; ?>
  <?php // Alternative zum Einzel-Rohstoff: das ganze Produkt fremdfertigen lassen (Kapsel/Tablette/Premix). ?>
  <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin-bottom:12px">
    <div>
      <div>Ganzes Produkt fremdfertigen lassen <span class="muted" style="font-size:12px">· <?= h(anfrage_formen()[$df] ?? $df) ?></span></div>
      <div class="muted" style="font-size:12px;margin-top:2px">Statt einzelner Rohstoffe direkt das fertige Produkt (Bulk) beim Lohnhersteller anfragen.</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <?= anfrage_produkt_badge((int)$id) ?>
      <?= anfrage_produkt_button((int)$id, (string)($r['name'] ?? 'Produkt'), (string)(anfrage_formen()[$df] ?? $df), 'Fertigprodukt anfragen', 'btn btn-primary btn-sm') ?>
    </div>
  </div>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Rohstoff</th><th>Status</th><th>CoA / Spec</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rzZutaten as $rz): ?>
      <tr>
        <td>
          <a href="?p=rohstoff&id=<?= (int)$rz['item_id'] ?>"><?= h($rz['name']) ?></a>
          <?php if (!empty($rz['artikelnummer'])): ?><a href="?p=rohstoff&id=<?= (int)$rz['item_id'] ?>" class="muted" style="display:block;font-size:12px;text-decoration:none"><?= h($rz['artikelnummer']) ?></a><?php endif; ?>
        </td>
        <td><?= anfrage_badge((int)$rz['item_id']) ?></td>
        <td>
          <?php $docs = $rzDocs[(int)$rz['item_id']] ?? []; if ($docs): foreach ($docs as $d): $u = '?p=dokument&id=' . (int)$d['id']; ?>
            <button type="button" class="btn btn-ghost btn-sm" style="margin:0 4px 4px 0" onclick="bxDocOeffnen('<?= h($u) ?>', '<?= h(addslashes(($docTypLbl[$d['typ']] ?? $d['typ']) . ' · ' . $rz['name'])) ?>')"><?= h($docTypLbl[$d['typ']] ?? $d['typ']) ?></button>
          <?php endforeach; else: ?><span class="muted" style="font-size:12px">–</span><?php endif; ?>
        </td>
        <td style="text-align:right"><button type="button" class="btn btn-ghost btn-sm" data-name="<?= h($rz['name']) ?>" onclick="bxAnfrageOeffnen(<?= (int)$rz['item_id'] ?>,this)">Preis anfragen</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php anfrage_modal(all("SELECT id, firma, land FROM lieferanten WHERE gesperrt=0 AND COALESCE(keine_anfragen,0)=0 ORDER BY firma"), '?p=rezeptur_detail&id=' . (int)$id); ?>

<?php endif; ?>

<?php // Dokument-Vorschau (CoA/Spec) als Popup: Inline-Ansicht im iframe + Download.
      // IMMER rendern (auch bei neuer Rezeptur) – die Zutatenliste nutzt bxDocOeffnen() für die
      // Spec/CoA-Links; früher lag das Overlay im „Rohstoffpreise"-Block (nur bei gespeicherten
      // Rezepturen), daher öffnete sich bei einer neuen Rezeptur nichts. ?>
<div id="bxDocOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9998;align-items:center;justify-content:center;padding:16px">
  <div class="bx-panel" style="max-width:920px;width:100%;height:90vh;max-height:92vh;display:flex;flex-direction:column;margin:0">
    <div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:10px">
      <strong id="bxDocTitel">Dokument</strong>
      <div class="bx-row" style="gap:8px">
        <a id="bxDocDl" class="btn btn-ghost btn-sm" href="#" download>Download</a>
        <button type="button" class="btn btn-ghost btn-sm" onclick="bxDocZu()">Schließen</button>
      </div>
    </div>
    <iframe id="bxDocFrame" src="" style="flex:1;min-height:0;width:100%;border:1px solid var(--line);border-radius:8px;background:#fff"></iframe>
  </div>
</div>
<script>
function bxDocOeffnen(url, titel){ var o=document.getElementById('bxDocOverlay'); document.getElementById('bxDocFrame').src=url; document.getElementById('bxDocDl').href=url; document.getElementById('bxDocTitel').textContent=titel||'Dokument'; o.style.display='flex'; }
function bxDocZu(){ var o=document.getElementById('bxDocOverlay'); o.style.display='none'; document.getElementById('bxDocFrame').src=''; }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') bxDocZu(); });
document.getElementById('bxDocOverlay').addEventListener('click', function(e){ if(e.target===this) bxDocZu(); });
</script>

<?php // Wo wurde diese Rezeptur als Fertigprodukt (Fremdfertigung) angefragt? Schnell sehen, ob schon angefragt.
  $prodAnfragen = $neu ? [] : anfrage_produkt_anfragen((int)$id);
  if ($prodAnfragen): $eurRz = fn($x) => rtrim(rtrim(number_format((float)$x, 4, ',', '.'), '0'), ',') . ' €'; ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Fremdfertigung angefragt bei <?= bx_hint('Bei welchen Lohnherstellern diese Rezeptur als Fertigprodukt angefragt wurde – mit Status und (falls vorhanden) dem angebotenen Preis. Solange noch kein Preis abgegeben wurde, kannst du die Anfrage hier zurückziehen.') ?></h2>
  <?php if (isset($_GET['anfzurueck'])): ?><div class="badge-ok" style="padding:8px 12px;margin-bottom:10px">Anfrage zurückgezogen.</div><?php endif; ?>
  <?php if (isset($_GET['anffehler'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:8px 12px;margin-bottom:10px">Anfrage konnte nicht zurückgezogen werden (evtl. schon beantwortet).</div><?php endif; ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Lieferant</th><th>Status</th><th class="bx-num">Angebot</th><th>Angefragt</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($prodAnfragen as $pa): ?>
        <tr>
          <td><?= h($pa['firma']) ?></td>
          <td><?= $pa['status'] === 'beantwortet' ? bx_badge('beantwortet', 'ok') : bx_badge('offen', 'warn') ?></td>
          <td class="bx-num"><?= ($pa['ang_preis'] !== null && $pa['ang_preis'] !== '') ? $eurRz($pa['ang_preis']) . ($pa['ang_einheit'] ? ' / ' . h($pa['ang_einheit']) : '') : '<span class="muted">–</span>' ?></td>
          <td class="muted" style="font-size:12px"><?= $pa['angelegt'] ? h(fmt_zeit($pa['angelegt'], 'd.m.Y')) : '–' ?></td>
          <td style="text-align:right">
            <?php if ($pa['status'] === 'offen'): ?>
              <form method="post" style="margin:0" onsubmit="return confirm('Anfrage bei <?= h(addslashes($pa['firma'])) ?> zurückziehen? Sie verschwindet dann auch aus dem Lieferantenportal.');">
                <input type="hidden" name="aktion" value="anfrage_zurueck"><input type="hidden" name="anfrage_id" value="<?= (int)$pa['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Zurückziehen</button>
              </form>
            <?php else: ?><span class="muted" style="font-size:12px">–</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if (!$neu && $kundenpreise): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Kundenpreise <?= bx_hint('Welcher Kunde zu welchem Datum welchen VK je Packung für diese Rezeptur hatte – u. a. aus dem Angebotsscan (System → Angebotsscan).') ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Kunde</th><th class="bx-num">VK je Packung</th><th class="bx-num">Stück/Pck.</th><th class="bx-num">Menge</th><th>Datum</th><th>Quelle</th></tr></thead>
    <tbody>
      <?php foreach ($kundenpreise as $kp): ?>
        <tr>
          <td><?= $kp['firma'] ? kunde_link((int)$kp['kunde_id'], $kp['firma']) : '<span class="muted">–</span>' ?><?= $kp['kundennummer'] ? ' <span class="muted" style="font-size:11px">' . h($kp['kundennummer']) . '</span>' : '' ?></td>
          <td class="bx-num"><?= (float)$kp['vk'] > 0 ? '<strong>' . number_format((float)$kp['vk'], 4, ',', '.') . ' &euro;</strong>' : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= (int)$kp['stueck_je_packung'] > 0 ? (int)$kp['stueck_je_packung'] : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= (int)$kp['menge'] > 0 ? number_format((int)$kp['menge'], 0, ',', '.') : '<span class="muted">–</span>' ?></td>
          <td><?= $kp['datum'] ? h(date('d.m.Y', strtotime((string)$kp['datum']))) : '<span class="muted">–</span>' ?></td>
          <td><span class="muted" style="font-size:11px"><?= h($kp['quelle']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if (!$neu && $liefAngebote): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Lieferanten-Angebote (Fremdfertigung) <?= bx_hint('Was Lieferanten für die Herstellung dieser Rezeptur angeboten haben – Preis je Einheit. U. a. aus v3 übernommen.') ?></h2>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Lieferant</th><th class="bx-num">Preis je Einheit</th><th>Einheit</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($liefAngebote as $la): ?>
        <tr>
          <td><?= $la['firma'] ? h($la['firma']) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= $la['preis'] !== null && (float)$la['preis'] > 0 ? '<strong>' . number_format((float)$la['preis'], 4, ',', '.') . ' &euro;</strong>' : '<span class="muted">–</span>' ?></td>
          <td><?= $la['einheit'] ? h($la['einheit']) : '<span class="muted">–</span>' ?></td>
          <td><?= ($la['status'] ?? '') === 'angenommen' ? bx_badge('angenommen', 'ok') : bx_badge($la['status'] ?: 'offen', 'info') ?><?= !empty($la['angenommen_am']) ? ' <span class="muted" style="font-size:11px">' . h(date('d.m.Y', strtotime((string)$la['angenommen_am']))) . '</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<script>
var ITEMS = <?= json_encode($ITEMS, JSON_UNESCAPED_UNICODE) ?>;
var KAPSELN = <?= json_encode($KAPSELN, JSON_UNESCAPED_UNICODE) ?>;
// Rohstoff-Label -> id (für das tippbare Zutatenfeld mit datalist)
var ZMAP = <?= json_encode((function($items,$FORMLBL,$gehaltSet){ $m=[]; foreach($items as $it){ $lbl=implode(' · ', array_filter([$it['name'], ($FORMLBL[$it['form']]??$it['form']), $it['artikelnummer']??''])) . (!empty($gehaltSet[(int)$it['id']]) ? ' · Gehalt ✓' : ''); $m[$lbl]=(int)$it['id']; } return $m; })($items,$FORMLBL,$gehaltSet), JSON_UNESCAPED_UNICODE) ?>;
function zsync(row){ var t=row.querySelector('.zitem-txt'), h=row.querySelector('.zitem'); if(!t||!h) return; var id=ZMAP[(t.value||'').trim()]; h.value = id ? id : ''; }
// R-Nummer-Link + CoA/Spec je Zutat (Anchor -> funktioniert auch bei festgesetzter/disabled Rezeptur).
var ZNR = <?= json_encode($ZNR) ?>;
var ITEMDOCS = <?= json_encode($ITEMDOCS, JSON_UNESCAPED_UNICODE) ?>;
var DOCLBLZ = {spec:'Spec', coa:'CoA', analyse:'Analyse'};
function zactions(row){
  var box = row.querySelector('.zactions'); if(!box) return;
  var id = row.querySelector('.zitem').value;
  if(!id){ box.innerHTML=''; return; }
  var html = '<a href="?p=rohstoff&id='+id+'" style="font-size:12px">↗ Rohstoff'+(ZNR[id]?' '+ZNR[id]:'')+'</a>';
  html += ' <span class="muted">·</span> <a href="#" style="font-size:12px" onclick="bxDocOeffnen(\'?p=spec_bulkify&id='+id+'\',\'bulkify-Spezifikation\');return false">Spez. (bulkify)</a>';
  (ITEMDOCS[id]||[]).forEach(function(d){ var l=(DOCLBLZ[d.typ]||d.typ)+' (Lief.)'; html+=' <span class="muted">·</span> <a href="#" style="font-size:12px" onclick="bxDocOeffnen(\'?p=dokument&id='+d.id+'\',\''+l+'\');return false">'+l+'</a>'; });
  box.innerHTML = html;
}
function nf(x, d){ return x.toLocaleString('de-DE', {minimumFractionDigits:d, maximumFractionDigits:d}); }
function betragEinheit(mg, einheit, anzeige, ie_mg){
  var lbl = (anzeige && anzeige!=='') ? anzeige : einheit;
  var s = einheit === 'µg' ? nf(mg*1000,1)+' '+lbl : nf(mg,1)+' '+lbl;
  if (ie_mg && ie_mg > 0) s += ' ('+nf(mg/ie_mg,0)+' I.E.)';   // zusätzlich I.E. (Vitamin D/A/E …)
  return s;
}
function nrvProzent(mg, nrv, einheit){
  if (nrv === null || nrv === undefined) return '';
  var nrvMg = einheit === 'µg' ? parseFloat(nrv)/1000 : parseFloat(nrv);
  return nrvMg > 0 ? nf(mg / nrvMg * 100, 0)+' %' : '';
}
function recalc(){
  var rows = document.querySelectorAll('.zutatrow');
  var totalW = 0, cost = 0, nutr = {}, order = [], etikett = '';
  rows.forEach(function(row){
    var iid = row.querySelector('.zitem').value;
    var mg = parseFloat((row.querySelector('.zmenge').value || '').replace(',','.')) || 0;
    var it = ITEMS[iid]; if (!it || !mg) return;
    totalW += mg;
    // Kosten: EK je Bezug -> je mg
    var perMg = 0;
    if (it.preis_bezug === 'kg') perMg = it.ek_preis / 1e6;
    else if (it.preis_bezug === 'g') perMg = it.ek_preis / 1e3;
    else if (it.preis_bezug === 'L' && it.dichte) perMg = (it.ek_preis / (1000*it.dichte)) / 1e3; // L->g über Dichte
    cost += mg * perMg;
    // Etikett-Zeile: Inhaltsstoff
    etikett += '<tr><td><strong>'+it.name+'</strong></td><td class="bx-num">'+nf(mg,0)+' mg</td><td></td></tr>';
    (it.wirkstoffe||[]).forEach(function(w){
      if (!w.basePerMg) return;
      var mgN = mg * w.basePerMg;   // mg Nährstoff = mg Rohstoff × (mg Nährstoff je mg Rohstoff)
      // „– davon"-Zeile
      etikett += '<tr><td style="padding-left:28px;color:var(--muted)">– davon '+w.name+'</td>'
               + '<td class="bx-num">'+betragEinheit(mgN, w.einheit, w.anzeige, w.ie_mg)+'</td>'
               + '<td class="bx-num">'+(nrvProzent(mgN, w.nrv, w.einheit) || '<span class="muted">–</span>')+'</td></tr>';
      // Summe je Nährstoff
      if (!nutr[w.name]) { nutr[w.name] = {mg:0, nrv:w.nrv, einheit:w.einheit, anzeige:w.anzeige, ie_mg:w.ie_mg}; order.push(w.name); }
      nutr[w.name].mg += mgN;
    });
  });
  document.getElementById('k_gewicht').textContent = totalW ? nf(totalW,0)+' mg' : '–';
  var kk = document.getElementById('k_kapsel');
  if (kk) {
    var sel = document.getElementById('kapselgroesse_id');
    var fixId = sel ? sel.value : '';
    if (fixId) {
      // Feste Kapselgröße gewählt -> diese anzeigen (nicht selbst berechnen), nur auf Passung prüfen.
      var gew = null;
      for (var i=0;i<KAPSELN.length;i++){ if (String(KAPSELN[i].id) === String(fixId)) { gew = KAPSELN[i]; break; } }
      if (gew) {
        if (!totalW)                              { kk.textContent = gew.name; kk.style.color=''; }
        else if (totalW <= gew.fuellmenge_mg)     { kk.textContent = gew.name; kk.style.color='var(--gruen)'; }
        else { kk.innerHTML = gew.name + ' <span style="font-size:12px">(Inhalt zu groß)</span>'; kk.style.color='var(--err)'; }
      } else { kk.textContent = '–'; kk.style.color=''; }
    } else if (!totalW) { kk.textContent = '–'; kk.style.color=''; }
    else {
      // Automatisch: kleinste passende Größe vorschlagen.
      var passend = null;
      for (var i=0;i<KAPSELN.length;i++){ if (totalW <= KAPSELN[i].fuellmenge_mg) { passend = KAPSELN[i]; break; } }
      if (passend) { kk.innerHTML = passend.name + ' <span style="font-size:12px">(automatisch)</span>'; kk.style.color='var(--gruen)'; }
      else { kk.innerHTML = 'passt in keine <span style="font-size:12px">(aufteilen)</span>'; kk.style.color='var(--err)'; }
    }
  }
  document.getElementById('k_kosten').textContent = cost ? nf(cost,4)+' €' : '–';
  document.getElementById('k_kosten1000').textContent = cost ? nf(cost*1000,2)+' €' : '–';
  document.getElementById('etikett').innerHTML = etikett || '<tr><td colspan="3" class="muted">Zutaten wählen …</td></tr>';
  var tb = document.getElementById('deklaration');
  tb.innerHTML = order.length ? order.map(function(name){
    var n = nutr[name];
    var pct = nrvProzent(n.mg, n.nrv, n.einheit) || '<span class="muted">keine NRV</span>';
    return '<tr><td>'+name+'</td><td class="bx-num">'+betragEinheit(n.mg, n.einheit, n.anzeige, n.ie_mg)+'</td><td class="bx-num">'+pct+'</td></tr>';
  }).join('') : '<tr><td colspan="3" class="muted">Zutaten wählen …</td></tr>';
}
(function(){
  var add = document.getElementById('addZutat');
  function bind(tr){
    var t = tr.querySelector('.zitem-txt');
    if (t){ var on=function(){ zsync(tr); zactions(tr); recalc(); }; t.addEventListener('input', on); t.addEventListener('change', on); }
    var m = tr.querySelector('.zmenge'); if (m) m.addEventListener('input', recalc);
    var b = tr.querySelector('button'); if (b) b.addEventListener('click', function(){ tr.remove(); recalc(); });
    zactions(tr);
  }
  if (add) add.addEventListener('click', function(){
    var tr = document.createElement('tr');
    tr.className = 'zutatrow';
    tr.innerHTML = '<td><input type="text" class="zitem-txt" list="zutat_dl" autocomplete="off" placeholder="Rohstoff tippen …" style="width:100%"><input type="hidden" name="z_item[]" class="zitem"><div class="zactions" style="margin-top:4px"></div></td>'
      + '<td><input type="number" step="0.001" name="z_menge[]" class="zmenge"></td>'
      + '<td><button type="button" class="btn btn-ghost btn-sm">entfernen</button></td>';
    document.getElementById('zutatrows').appendChild(tr);
    bind(tr);
    tr.querySelector('.zitem-txt').focus();
  });
  document.querySelectorAll('.zutatrow').forEach(bind);
  var kgSel = document.getElementById('kapselgroesse_id');
  if (kgSel) kgSel.addEventListener('change', recalc);   // gewählte Kapselgröße -> Karte sofort aktualisieren
  recalc();
})();
</script>
<?php
render_footer();
