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
            $kid = scalar("SELECT kunde_id FROM rezeptur WHERE id=?", [(int)$id]);
            $lbl = ['vorschlag'=>'als Vorschlag gesendet','eingefroren'=>'freigegeben & eingefroren (verbindlich)','freigegeben'=>'freigegeben','entwurf'=>'wieder zur Bearbeitung geöffnet'][$ziel] ?? $ziel;
            if ($kid) log_aktivitaet('kunde', (int)$kid, 'team', 'Rezeptur ' . scalar("SELECT nummer FROM rezeptur WHERE id=?", [(int)$id]) . ' ' . $lbl . '.', 'rezeptur', 'rezeptur', (int)$id);
        }
        header('Location: ?p=rezeptur_detail&id=' . $id); exit;
    }
    // Neue Version (Kopie als Entwurf)
    if (!$neu && $aktion === 'neue_version') {
        $o = one("SELECT * FROM rezeptur WHERE id=?", [(int)$id]);
        q("INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,status,notiz) VALUES (?,?,?,?,'entwurf',?)",
          [naechste_nummer('RZ'), $o['name'] . ' (Kopie)', $o['kunde_id'], $o['darreichungsform'], $o['notiz']]);
        $nid = insert_id();
        foreach (all("SELECT * FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort,id", [(int)$id]) as $z)
            q("INSERT INTO rezeptur_zutat (rezeptur_id,item_id,bezeichnung,menge_mg,sort) VALUES (?,?,?,?,?)", [$nid, $z['item_id'], $z['bezeichnung'], $z['menge_mg'], $z['sort']]);
        header('Location: ?p=rezeptur_detail&id=' . $nid . '&gespeichert=1'); exit;
    }
    // Bearbeitung gesperrt, wenn eingefroren/freigegeben
    if (!$neu && in_array((string) scalar("SELECT status FROM rezeptur WHERE id=?", [(int)$id]), ['freigegeben','eingefroren'], true)) {
        header('Location: ?p=rezeptur_detail&id=' . $id); exit;
    }
    if ($f('name') === '') {
        $fehler = 'Name ist ein Pflichtfeld.';
    } else {
        $kunde_id = ($_POST['kunde_id'] ?? '') !== '' ? (int)$_POST['kunde_id'] : null;
        // Kapselgröße nur bei Kapsel/Softgel speichern, sonst leeren
        $kapsGr = in_array($f('darreichungsform'), ['kapsel','softgel'], true) && ($_POST['kapselgroesse_id'] ?? '') !== ''
                  ? (int)$_POST['kapselgroesse_id'] : null;
        if ($neu) {
            q("INSERT INTO rezeptur (nummer,name,kunde_id,darreichungsform,kapselgroesse_id,status,notiz) VALUES (?,?,?,?,?,?,?)",
              [naechste_nummer('RZ'), $f('name'), $kunde_id, $f('darreichungsform'), $kapsGr, $f('status') ?: 'entwurf', $f('notiz')]);
            $id = insert_id();
        } else {
            q("UPDATE rezeptur SET name=?,kunde_id=?,darreichungsform=?,kapselgroesse_id=?,status=?,notiz=? WHERE id=?",
              [$f('name'), $kunde_id, $f('darreichungsform'), $kapsGr, $f('status'), $f('notiz'), (int)$id]);
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
        header('Location: ?p=rezeptur_detail&id=' . $id . '&gespeichert=1'); exit;
    }
}

$r = $neu ? ['darreichungsform'=>'kapsel','status'=>'entwurf']
          : one("SELECT * FROM rezeptur WHERE id=?", [(int)$id]);
if (!$r) { $neu = true; $r = ['darreichungsform'=>'kapsel','status'=>'entwurf']; }
$v = fn($k) => h((string)($r[$k] ?? ''));
$df = $r['darreichungsform'] ?? 'kapsel';
$status = $r['status'] ?? 'entwurf';
$locked = !$neu && in_array($status, ['freigegeben','eingefroren'], true);

$kunden = all("SELECT id, firma FROM kunden ORDER BY firma");
$zutaten = $neu ? [] : all("SELECT * FROM rezeptur_zutat WHERE rezeptur_id=? ORDER BY sort, id", [(int)$id]);
// Lieferanten-Angebote für die Fremdfertigung dieser Rezeptur (u. a. aus dem v3-Import).
$liefAngebote = $neu ? [] : all("SELECT la.*, l.firma FROM rezeptur_lief_angebot la LEFT JOIN lieferanten l ON l.id=la.lieferant_id
                                 WHERE la.rezeptur_id=? ORDER BY (la.preis IS NULL), la.preis", [(int)$id]);

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
foreach (all("SELECT iw.item_id, n.name, n.nrv_wert, n.einheit, iw.gehalt_prozent
              FROM item_wirkstoff iw JOIN naehrstoff n ON n.id=iw.naehrstoff_id") as $w) {
    $wmap[$w['item_id']][] = ['name'=>$w['name'], 'nrv'=>$w['nrv_wert'], 'einheit'=>$w['einheit'], 'gehalt'=>$w['gehalt_prozent']];
}
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
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['gesendet'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Vorschlag an den Kunden gesendet – er sieht ihn jetzt in seinem Portal. Änderungen hier speichern und ggf. „Erneut als Vorschlag senden".</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';
?>
<?php if (!$neu): ?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center">
    <div>Status: <?= match($status){'entwurf'=>bx_badge('Entwurf'),'vorschlag'=>bx_badge('Vorschlag','info'),'freigegeben'=>bx_badge('freigegeben','ok'),'eingefroren'=>bx_badge('eingefroren · verbindlich','warn'),'abgelehnt'=>bx_badge('vom Kunden abgelehnt','err'),default=>bx_badge($status)} ?></div>
    <div class="bx-row">
      <?php if ($status==='entwurf'): ?>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="vorschlag"><button class="btn btn-ghost btn-sm" type="submit">Als Vorschlag senden</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="eingefroren"><button class="btn btn-primary btn-sm" type="submit">Freigeben &amp; einfrieren</button></form>
      <?php elseif ($status==='vorschlag'): ?>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="entwurf"><button class="btn btn-ghost btn-sm" type="submit">zurück zu Entwurf</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="eingefroren"><button class="btn btn-primary btn-sm" type="submit">Freigeben &amp; einfrieren</button></form>
      <?php elseif ($status==='abgelehnt'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Überarbeiteten Vorschlag erneut an den Kunden senden?');"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="vorschlag"><button class="btn btn-primary btn-sm" type="submit">Erneut als Vorschlag senden</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="entwurf"><button class="btn btn-ghost btn-sm" type="submit">zurück zu Entwurf</button></form>
      <?php else: ?>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="neue_version"><button class="btn btn-ghost btn-sm" type="submit">Neue Version</button></form>
        <form method="post" style="display:inline"><input type="hidden" name="aktion" value="status_setzen"><input type="hidden" name="ziel" value="entwurf"><button class="btn btn-danger btn-sm" type="submit">Bearbeitung öffnen</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($status==='abgelehnt' && !empty($r['ablehnung_grund'])): ?>
    <div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;margin-top:10px;padding:10px 14px"><strong>Vom Kunden abgelehnt.</strong> Grund: <?= h($r['ablehnung_grund']) ?><div class="muted" style="margin-top:4px;font-size:12px">Zutaten unten anpassen und dann „Erneut als Vorschlag senden" – der Kunde sieht den überarbeiteten Vorschlag wieder im Portal.</div></div>
  <?php endif; ?>
  <?php if ($locked): ?><div class="muted" style="margin-top:8px">Diese Rezeptur ist <strong>schreibgeschützt</strong> (verbindlich). Für Änderungen „Neue Version" erstellen oder „Bearbeitung öffnen".</div><?php endif; ?>
</div>
<?php endif; ?>

<form method="post" class="bx-form">
  <fieldset <?= $locked ? 'disabled' : '' ?> style="border:0;padding:0;margin:0;min-width:0">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required></div>
    <div class="bx-field"><label>Kunde <?= bx_hint('leer = Hausrezeptur (im Rezepturkatalog, für alle sichtbar)') ?></label>
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

  <div class="bx-panel">
    <h2>Zutaten <?= bx_hint('Rohstoffe je Einheit (Kapsel/Portion) in mg. Auswahl nach Form vorsortiert.') ?></h2>
    <table class="bx-table" style="margin-bottom:10px">
      <thead><tr><th style="width:55%">Rohstoff</th><th style="width:160px">Menge (mg)</th><th></th></tr></thead>
      <tbody id="zutatrows">
        <?php $zr = $zutaten ?: [['item_id'=>'','menge_mg'=>'']]; foreach ($zr as $z): ?>
        <tr class="zutatrow">
          <td>
            <select name="z_item[]" class="zitem">
              <option value="">– Rohstoff wählen –</option>
              <?php foreach ($items as $it): ?>
                <option value="<?= $it['id'] ?>" <?= (int)($z['item_id']??0)===(int)$it['id']?'selected':'' ?>><?= h($it['name']) ?> · <?= h($FORMLBL[$it['form']] ?? $it['form']) ?><?= $it['artikelnummer'] ? ' · '.h($it['artikelnummer']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" step="0.001" name="z_menge[]" class="zmenge" value="<?= h($z['menge_mg']!==''&&$z['menge_mg']!==null ? rtrim(rtrim(number_format((float)$z['menge_mg'],3,'.',''),'0'),'.') : '') ?>"></td>
          <td><button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.zutatrow').remove();recalc()">entfernen</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
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

<?php // Rohstoffpreise je Zutat – damit man schon an der Rezeptur sieht, was der Einkauf kostet
      // und wo noch ein Preis fehlt. Anfrage per Popup (Lieferanten auswählen). Nur für gespeicherte
      // Rezepturen mit Zutaten, die einen Lagerartikel haben. ?>
<?php $rzZutaten = $neu ? [] : all("SELECT DISTINCT z.item_id, i.name, i.preis_bezug, i.einheit
        FROM rezeptur_zutat z JOIN item i ON i.id=z.item_id WHERE z.rezeptur_id=? AND z.item_id IS NOT NULL ORDER BY i.name", [(int)$id]);
   if ($rzZutaten): $mitPreis = 0; foreach ($rzZutaten as $rz) if (anfrage_status((int)$rz['item_id']) === 'preise') $mitPreis++; ?>
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
    <thead><tr><th>Rohstoff</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rzZutaten as $rz): ?>
      <tr>
        <td><a href="?p=rohstoff&id=<?= (int)$rz['item_id'] ?>"><?= h($rz['name']) ?></a></td>
        <td><?= anfrage_badge((int)$rz['item_id']) ?></td>
        <td style="text-align:right"><button type="button" class="btn btn-ghost btn-sm" data-name="<?= h($rz['name']) ?>" onclick="bxAnfrageOeffnen(<?= (int)$rz['item_id'] ?>,this)">Preis anfragen</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php anfrage_modal(all("SELECT id, firma, land FROM lieferanten WHERE gesperrt=0 ORDER BY firma"), '?p=rezeptur_detail&id=' . (int)$id); ?>
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
function nf(x, d){ return x.toLocaleString('de-DE', {minimumFractionDigits:d, maximumFractionDigits:d}); }
function betragEinheit(mg, einheit){ return einheit === 'µg' ? nf(mg*1000,1)+' µg' : nf(mg,1)+' mg'; }
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
      if (w.gehalt === null || w.gehalt === undefined || w.gehalt === '') return;
      var mgN = mg * parseFloat(w.gehalt) / 100;
      // „– davon"-Zeile
      etikett += '<tr><td style="padding-left:28px;color:var(--muted)">– davon '+w.name+'</td>'
               + '<td class="bx-num">'+betragEinheit(mgN, w.einheit)+'</td>'
               + '<td class="bx-num">'+(nrvProzent(mgN, w.nrv, w.einheit) || '<span class="muted">–</span>')+'</td></tr>';
      // Summe je Nährstoff
      if (!nutr[w.name]) { nutr[w.name] = {mg:0, nrv:w.nrv, einheit:w.einheit}; order.push(w.name); }
      nutr[w.name].mg += mgN;
    });
  });
  document.getElementById('k_gewicht').textContent = totalW ? nf(totalW,0)+' mg' : '–';
  var kk = document.getElementById('k_kapsel');
  if (kk) {
    if (!totalW) { kk.textContent = '–'; kk.style.color=''; }
    else {
      var passend = null;
      for (var i=0;i<KAPSELN.length;i++){ if (totalW <= KAPSELN[i].fuellmenge_mg) { passend = KAPSELN[i]; break; } }
      if (passend) { kk.textContent = passend.name; kk.style.color='var(--gruen)'; }
      else { var groesste = KAPSELN.length ? KAPSELN[KAPSELN.length-1] : null;
             kk.innerHTML = 'passt in keine <span style="font-size:12px">(aufteilen)</span>'; kk.style.color='var(--err)'; }
    }
  }
  document.getElementById('k_kosten').textContent = cost ? nf(cost,4)+' €' : '–';
  document.getElementById('k_kosten1000').textContent = cost ? nf(cost*1000,2)+' €' : '–';
  document.getElementById('etikett').innerHTML = etikett || '<tr><td colspan="3" class="muted">Zutaten wählen …</td></tr>';
  var tb = document.getElementById('deklaration');
  tb.innerHTML = order.length ? order.map(function(name){
    var n = nutr[name];
    var pct = nrvProzent(n.mg, n.nrv, n.einheit) || '<span class="muted">keine NRV</span>';
    return '<tr><td>'+name+'</td><td class="bx-num">'+betragEinheit(n.mg, n.einheit)+'</td><td class="bx-num">'+pct+'</td></tr>';
  }).join('') : '<tr><td colspan="3" class="muted">Zutaten wählen …</td></tr>';
}
(function(){
  var add = document.getElementById('addZutat');
  var optionsHTML = document.querySelector('.zitem') ? document.querySelector('.zitem').innerHTML : '';
  add.addEventListener('click', function(){
    var tr = document.createElement('tr');
    tr.className = 'zutatrow';
    tr.innerHTML = '<td><select name="z_item[]" class="zitem">'+optionsHTML+'</select></td>'
      + '<td><input type="number" step="0.001" name="z_menge[]" class="zmenge"></td>'
      + '<td><button type="button" class="btn btn-ghost btn-sm">entfernen</button></td>';
    tr.querySelector('button').addEventListener('click', function(){ tr.remove(); recalc(); });
    tr.querySelector('.zitem').addEventListener('change', recalc);
    tr.querySelector('.zmenge').addEventListener('input', recalc);
    document.getElementById('zutatrows').appendChild(tr);
  });
  document.querySelectorAll('.zitem').forEach(function(s){ s.addEventListener('change', recalc); });
  document.querySelectorAll('.zmenge').forEach(function(i){ i.addEventListener('input', recalc); });
  recalc();
})();
</script>
<?php
render_footer();
