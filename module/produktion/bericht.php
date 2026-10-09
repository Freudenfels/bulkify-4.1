<?php
// Produktionsbericht (Herstellprotokoll) – druckbare Gesamtuebersicht eines Produktionsauftrags.
// Interne Version: alle Chargen, Lieferanten, Mitarbeiter, Datum. Mit ?fuer=kunde sieht das Team die
// bereinigte Kundenversion (ohne Lieferanten/Mitarbeiter/Rohstoff-Chargen, kein Zukauf erkennbar) – die
// GLEICHE Ansicht bekommt der Kunde im Portal. Rendern uebernimmt der gemeinsame Include _bericht_inhalt.php.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);

// Bemerkung speichern / Freigabe fuer den Kunden setzen bzw. zuruecknehmen.
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = (string)($_POST['aktion'] ?? '');
    if ($akt === 'bericht_notiz') {
        q("UPDATE produktionsauftrag SET bericht_notiz=? WHERE id=?", [trim((string)($_POST['notiz'] ?? '')) ?: null, $id]);
        header('Location: ?p=produktion_bericht&id=' . $id . '&gespeichert=1'); exit;
    }
    if ($akt === 'bericht_freigeben') {
        $wer = (function_exists('current_user') && ($cu = current_user())) ? (string)($cu['name'] ?? '') : '';
        q("UPDATE produktionsauftrag SET bericht_freigegeben_am=NOW(), bericht_freigegeben_von=? WHERE id=?", [$wer ?: null, $id]);
        $pa = one("SELECT nummer, kunde_id, auftrag_id FROM produktionsauftrag WHERE id=?", [$id]);
        if ($pa && $pa['kunde_id']) log_aktivitaet('kunde', (int)$pa['kunde_id'], 'team', 'Produktionsbericht ' . $pa['nummer'] . ' für den Kunden freigegeben.', 'auftrag', 'auftrag', (int)$pa['auftrag_id']);
        header('Location: ?p=produktion_bericht&id=' . $id . '&freigegeben=1'); exit;
    }
    if ($akt === 'bericht_freigabe_zurueck') {
        q("UPDATE produktionsauftrag SET bericht_freigegeben_am=NULL, bericht_freigegeben_von=NULL WHERE id=?", [$id]);
        header('Location: ?p=produktion_bericht&id=' . $id . '&zurueck=1'); exit;
    }
    if ($akt === 'foto_upload') {   // Abschlussfotos (Spec 13.1) hochladen
        $n = abschlussfoto_upload($id, 'foto');
        header('Location: ?p=produktion_bericht&id=' . $id . ($n > 0 ? '&foto=' . $n : '&fotofehler=1')); exit;
    }
    if ($akt === 'foto_del') {
        abschlussfoto_del((int)($_POST['dok_id'] ?? 0), $id);
        header('Location: ?p=produktion_bericht&id=' . $id . '&fotodel=1'); exit;
    }
    header('Location: ?p=produktion_bericht&id=' . $id); exit;
}

$D = $id ? produktion_bericht_daten($id) : null;
if (!$D) { render_header('produktion','Produktionsbericht'); bx_head('Produktionsauftrag nicht gefunden','', bx_btn('Zurück','?p=produktion','ghost')); render_footer(); exit; }
$pa = $D['pa'];
$fuerKunde = ($_GET['fuer'] ?? '') === 'kunde';   // Team-Vorschau der Kundenversion
$freigegeben = !empty($pa['bericht_freigegeben_am']);

render_header('produktion', 'Bericht ' . $pa['nummer']);
?>
<style>
@media print {
  .bx-side, .bx-mobilbar, .bx-sideauf, .bx-sidegriff, .no-print { display:none !important; }
  .bx-shell, .bx-main { display:block !important; margin:0 !important; }
  .bx-panel { break-inside:avoid; box-shadow:none; }
  body { background:#fff; }
}
.pb-sec h2 { margin:0 0 8px; font-size:15px }
.pb-kv td:first-child { color:var(--muted); width:220px; white-space:nowrap }
</style>

<div class="bx-row no-print" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
  <div>
    <h1 style="margin:0 0 2px"><?= $fuerKunde ? 'Kundenansicht' : 'Produktionsbericht' ?> <?= h($pa['nummer']) ?></h1>
    <div class="muted" style="font-size:13px"><?= $D['fertig'] ? 'Abgeschlossen' : 'Fortschritt ' . $D['done'] . '/' . count($D['schritte']) . ' – noch nicht abgeschlossen' ?><?= $freigegeben ? ' · für Kunden freigegeben' : '' ?></div>
  </div>
  <div class="bx-row" style="gap:8px">
    <button type="button" class="btn btn-primary" onclick="window.print()">Drucken / PDF</button>
    <?php if ($fuerKunde): ?>
      <a class="btn btn-ghost" href="?p=produktion_bericht&id=<?= $id ?>">Interne Ansicht</a>
    <?php else: ?>
      <a class="btn btn-ghost" href="?p=produktion_bericht&id=<?= $id ?>&fuer=kunde">Kundenansicht</a>
    <?php endif; ?>
    <a class="btn btn-ghost" href="?p=produktionsauftrag&id=<?= $id ?>">Zum Auftrag</a>
  </div>
</div>

<?php if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok no-print" style="padding:12px 16px">Bemerkung gespeichert.</div>';
if (isset($_GET['freigegeben'])) echo '<div class="bx-panel badge-ok no-print" style="padding:12px 16px">Bericht für den Kunden freigegeben – er sieht ihn im Portal bei der Bestellung.</div>';
if (isset($_GET['zurueck'])) echo '<div class="bx-panel badge-ok no-print" style="padding:12px 16px">Freigabe zurückgenommen.</div>';
if (isset($_GET['foto'])) echo '<div class="bx-panel badge-ok no-print" style="padding:12px 16px">' . (int)$_GET['foto'] . ' Abschlussfoto(s) hochgeladen.</div>';
if (isset($_GET['fotodel'])) echo '<div class="bx-panel badge-ok no-print" style="padding:12px 16px">Foto gelöscht.</div>';
if (isset($_GET['fotofehler'])) echo '<div class="bx-panel no-print" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Kein Foto hochgeladen (nur Bilddateien, max. Upload-Größe beachten).</div>';
if (!$D['fertig']) echo '<div class="bx-panel no-print" style="border-color:#e6c4c0;padding:10px 14px;font-size:13px">Hinweis: Der Auftrag ist noch <strong>nicht abgeschlossen</strong> – der Bericht zeigt den aktuellen Zwischenstand.</div>';
?>

<?php // Team-Steuerung: Bemerkung fuer den Kunden + Freigabe (nicht in der Kundenansicht/Druck). ?>
<?php if (!$fuerKunde): ?>
<div class="bx-panel no-print" style="border-color:var(--gruen)">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div>
      <h2 style="margin:0 0 4px;font-size:15px">Freigabe für den Kunden</h2>
      <div class="muted" style="font-size:13px"><?= $freigegeben ? 'Freigegeben am ' . h(date('d.m.Y', strtotime((string)$pa['bericht_freigegeben_am']))) . ($pa['bericht_freigegeben_von'] ? ' von ' . h((string)$pa['bericht_freigegeben_von']) : '') . ' – der Kunde sieht die Kundenansicht im Portal.' : 'Prüfe die Kundenansicht, ergänze ggf. eine Bemerkung und gib den Bericht dann frei.' ?></div>
    </div>
    <div class="bx-row" style="gap:8px">
      <?php if (!$freigegeben): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Bericht für den Kunden freigeben? Er erscheint dann im Kundenportal.');">
          <input type="hidden" name="aktion" value="bericht_freigeben"><button class="btn btn-primary" type="submit"<?= $D['fertig'] ? '' : ' disabled title="Erst abschließen"' ?>>Für Kunden freigeben</button></form>
      <?php else: ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Freigabe zurücknehmen? Der Kunde sieht den Bericht dann nicht mehr.');">
          <input type="hidden" name="aktion" value="bericht_freigabe_zurueck"><button class="btn btn-ghost" type="submit">Freigabe zurücknehmen</button></form>
      <?php endif; ?>
    </div>
  </div>
  <form method="post" style="margin-top:12px">
    <input type="hidden" name="aktion" value="bericht_notiz">
    <label class="muted" style="font-size:13px">Bemerkung für den Kunden (erscheint im Bericht)</label>
    <textarea name="notiz" rows="2" style="width:100%;margin-top:4px" placeholder="z. B. Produziert nach GMP-Grundsätzen, alle Prüfungen bestanden."><?= h((string)($pa['bericht_notiz'] ?? '')) ?></textarea>
    <div style="margin-top:6px"><button class="btn btn-ghost btn-sm" type="submit">Bemerkung speichern</button></div>
  </form>
</div>
<?php endif; ?>

<?php if (!$fuerKunde): $fotos = $D['abschlussfotos'] ?? []; ?>
<div class="bx-panel no-print">
  <h2 style="margin:0 0 4px;font-size:15px">Abschlussfotos</h2>
  <div class="muted" style="font-size:13px;margin-bottom:10px">Fotos der fertigen Charge (z. B. abgefüllte Gebinde, Etikett, Palette) – erscheinen im gedruckten Bericht.</div>
  <?php if ($fotos): ?>
  <div class="bx-row" style="gap:10px;flex-wrap:wrap;margin-bottom:10px">
    <?php foreach ($fotos as $f): ?>
      <div style="position:relative">
        <a href="?p=dokument&id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><img src="?p=dokument&id=<?= (int)$f['id'] ?>" alt="Abschlussfoto" style="height:90px;width:auto;border-radius:8px;border:1px solid var(--line);object-fit:cover"></a>
        <form method="post" style="position:absolute;top:2px;right:2px;margin:0" onsubmit="return confirm('Foto löschen?');"><input type="hidden" name="aktion" value="foto_del"><input type="hidden" name="dok_id" value="<?= (int)$f['id'] ?>"><button type="submit" class="btn btn-ghost btn-sm" style="padding:0 7px;background:rgba(255,255,255,.85)" title="löschen">×</button></form>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="bx-row" style="gap:8px;align-items:center">
    <input type="hidden" name="aktion" value="foto_upload">
    <input type="file" name="foto[]" accept="image/*" multiple required>
    <button class="btn btn-ghost btn-sm" type="submit" data-busy="Lädt …">Fotos hochladen</button>
  </form>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_bericht_inhalt.php'; ?>

<p class="muted" style="font-size:12px;margin-top:10px">Erstellt am <?= h(fmt_zeit(gmdate('Y-m-d H:i:s'))) ?> Uhr<?= (!$fuerKunde && function_exists('current_user') && ($cu = current_user())) ? ' von ' . h((string)($cu['name'] ?? '')) : '' ?> · bulkify Produktionsbericht</p>

<?php render_footer(); ?>
