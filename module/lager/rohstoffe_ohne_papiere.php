<?php
// Rohstoffe in (dem Kunden angebotenen) Rezepturen, für die wir noch KEINE Papiere/Informationen haben.
// Zweck: Wir geben dem Kunden unsere ausgefüllten Infos (Spezifikation/Deklaration) – die können wir nur
// liefern, wenn für den Rohstoff überhaupt Unterlagen vorliegen. Fehlen sie, hier direkt beim Lieferanten
// anfragen (CoA/Spezifikation). „Papiere vorhanden" = mindestens ein Dokument am Rohstoff (dokument-Tabelle).
// Route: ?p=rohstoffe_papiere
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/anfrage_ui.php';

$ANGEBOTEN = ['vorschlag','freigegeben','eingefroren'];   // = dem Kunden angeboten (kein reiner Entwurf)
$inStatus  = "'" . implode("','", $ANGEBOTEN) . "'";

// Rohstoffe aus angebotenen Rezepturen OHNE jegliches Dokument.
$rows = all("SELECT DISTINCT i.id, i.artikelnummer, i.name
             FROM item i
             JOIN rezeptur_zutat z ON z.item_id = i.id
             JOIN rezeptur r       ON r.id = z.rezeptur_id
             WHERE i.kategorie='rohstoff' AND r.status IN ($inStatus)
               AND NOT EXISTS (SELECT 1 FROM dokument d WHERE d.objekt_typ='item' AND d.objekt_id=i.id)
             ORDER BY i.name");

// Je Rohstoff: in welchen angebotenen Rezepturen (mit Kunde) steckt er + offene Anfrage schon gestellt?
$verwendung = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    foreach (all("SELECT DISTINCT z.item_id, r.id AS rez_id, r.nummer AS rez_nr, r.name AS rez_name, k.firma AS kunde
                  FROM rezeptur_zutat z JOIN rezeptur r ON r.id=z.rezeptur_id LEFT JOIN kunden k ON k.id=r.kunde_id
                  WHERE z.item_id IN ($ids) AND r.status IN ($inStatus) ORDER BY r.name") as $v)
        $verwendung[(int)$v['item_id']][] = $v;
}

// Schon eine (offene) Lieferanten-Anfrage je Rohstoff? EINMAL bulk holen (nicht pro Zeile – Remote-DB-Last).
$angefragt = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    foreach (all("SELECT DISTINCT item_id FROM lieferant_anfrage WHERE item_id IN ($ids) AND status='offen'") as $x)
        $angefragt[(int)$x['item_id']] = true;
}

// Freitext-Zutaten (noch keinem Rohstoff zugeordnet) in angebotenen Rezepturen – die haben per se keine Papiere
// und müssen zuerst im Rezeptur-Editor einem Rohstoff zugeordnet werden.
$freitext = all("SELECT DISTINCT z.bezeichnung, r.id AS rez_id, r.nummer AS rez_nr, r.name AS rez_name, k.firma AS kunde
                 FROM rezeptur_zutat z JOIN rezeptur r ON r.id=z.rezeptur_id LEFT JOIN kunden k ON k.id=r.kunde_id
                 WHERE z.item_id IS NULL AND TRIM(COALESCE(z.bezeichnung,''))<>'' AND r.status IN ($inStatus)
                 ORDER BY z.bezeichnung");

render_header('rohstoffe_papiere', 'Rohstoffe ohne Papiere');
bx_head('Rohstoffe ohne Papiere', 'Rohstoffe aus angebotenen Rezepturen, für die noch keine Unterlagen (CoA/Spezifikation) vorliegen');
if (isset($_GET['angefragt'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)$_GET['angefragt'] . ' Anfrage(n) an Lieferanten verschickt' . (isset($_GET['gemailt']) && (int)$_GET['gemailt'] > 0 ? ', davon ' . (int)$_GET['gemailt'] . ' per E-Mail' : '') . '.</div>';
?>
<p class="muted" style="margin-top:0;max-width:820px">Wir geben dem Kunden unsere <strong>ausgefüllten Informationen</strong> zum Rohstoff (Spezifikation/Deklaration) – nicht die Lieferanten-CoA. Dafür brauchen wir überhaupt erst Unterlagen. Hier stehen die Rohstoffe aus angebotenen Rezepturen, für die noch <strong>kein einziges Dokument</strong> hinterlegt ist. Direkt beim Lieferanten anfragen (CoA/Spezifikation) – sobald etwas vorliegt, verschwindet der Rohstoff aus der Liste.</p>

<div class="bx-cards" style="margin-bottom:16px">
  <div class="bx-card"><div class="k">Rohstoffe ohne Papiere</div><div class="v"><?= count($rows) ?></div></div>
  <div class="bx-card"><div class="k">Zutaten ohne Rohstoff-Zuordnung</div><div class="v"><?= count($freitext) ?></div></div>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Rohstoffe ohne Unterlagen</h2>
  <?php if (!$rows): ?>
    <div class="muted">Alles versorgt – zu jedem Rohstoff in angebotenen Rezepturen liegt mindestens ein Dokument vor.</div>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Rohstoff</th><th>Artikelnr.</th><th>Verwendet in (Rezeptur · Kunde)</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $iid = (int)$r['id']; ?>
      <tr>
        <td><a href="?p=rohstoff&id=<?= $iid ?>&tab=dok"><?= h($r['name']) ?></a></td>
        <td class="muted"><?= h($r['artikelnummer'] ?: '–') ?></td>
        <td style="max-width:360px;white-space:normal">
          <?php foreach (($verwendung[$iid] ?? []) as $v): ?>
            <div style="font-size:13px"><a href="?p=rezeptur_detail&id=<?= (int)$v['rez_id'] ?>"><?= h($v['rez_nr'] ?: $v['rez_name']) ?></a><?= $v['kunde'] ? ' <span class="muted">· ' . h($v['kunde']) . '</span>' : '' ?></div>
          <?php endforeach; ?>
        </td>
        <td><?= !empty($angefragt[$iid]) ? bx_badge('angefragt','info') : '<span class="muted">offen</span>' ?></td>
        <td class="bx-num"><button type="button" class="btn btn-primary btn-sm" data-name="<?= h($r['name']) ?>" onclick="bxAnfrageOeffnen(<?= $iid ?>,this)">Beim Lieferanten anfragen</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($freitext): ?>
<div class="bx-panel">
  <h2 style="margin-top:0">Zutaten ohne Rohstoff-Zuordnung</h2>
  <p class="muted" style="margin-top:0;font-size:13px">Diese Zutaten in angebotenen Rezepturen sind noch keinem Rohstoff zugeordnet. Erst im Rezeptur-Editor einem Rohstoff zuordnen – dann lassen sich Papiere dazu erfassen/anfragen.</p>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Zutat (Freitext)</th><th>Rezeptur · Kunde</th></tr></thead>
    <tbody>
    <?php foreach ($freitext as $f): ?>
      <tr>
        <td><?= h($f['bezeichnung']) ?></td>
        <td><a href="?p=rezeptur_detail&id=<?= (int)$f['rez_id'] ?>"><?= h($f['rez_nr'] ?: $f['rez_name']) ?></a><?= $f['kunde'] ? ' <span class="muted">· ' . h($f['kunde']) . '</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php anfrage_modal(lieferanten_fuer_anfrage('rohstoff'), '?p=rohstoffe_papiere'); ?>
<?php render_footer();
