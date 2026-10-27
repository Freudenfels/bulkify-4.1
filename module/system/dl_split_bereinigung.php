<?php
// Einmal-Bereinigung (Admin): fälschlich gesplittete Material-DL-Aufträge.
// Die ALTE dl_auftrag_aus_angebot-Logik legte je Angebotsposition einen eigenen DB-Auftrag an – auch für
// reine Material-/Zubehörpositionen (Standbodenbeutel, Glas, Deckel, Etikett), die keine Leistung sind.
// Richtig (neue Logik): Material hängt am Service-Auftrag seiner Gruppe und wird über dessen DR-Rechnung
// mit abgerechnet; ein eigener Auftrag dafür ergibt keinen Sinn. Dieses Werkzeug entfernt genau diese
// Material-Split-Aufträge (gezielt per ID über auftrag_komplett_loeschen).
// Kandidat = DL-Auftrag OHNE Service-Verknüpfung (dienstleistung_id leer) UND ohne Fortschritts-Schritte
//            UND aus einem Angebot, das noch einen ECHTEN Service-Auftrag hat (also ein Split, kein Einzel-
//            auftrag). Hat ein Kandidat eine (nicht stornierte) Rechnung, wird er NICHT gelöscht, sondern
//            nur gewarnt – erst in der Buchhaltung klären.
// Route: ?p=dl_split_bereinigung — VORSCHAU per Default, Löschen nur auf ausdrücklichen Klick. Idempotent.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!function_exists('has_role') || !has_role('admin')) {
    render_header('dl_split_bereinigung', 'DL-Split-Bereinigung');
    bx_head('Kein Zugriff', 'Nur für Admins.', bx_btn('Zurück', '?p=dashboard', 'ghost'));
    render_footer(); exit;
}

// --- Kandidaten einsammeln (reine Lese-Abfrage) ----------------------------------------------------
$cands = all("SELECT a.id, a.nummer, a.kunde_id, a.angebot_id, a.gesamt_netto, a.status, a.vk_stueck, a.menge,
                     k.firma AS kunde, ang.nummer AS angebot_nr,
                     (SELECT b.nummer FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' AND b.status<>'storniert' ORDER BY b.id DESC LIMIT 1) AS rechnung_nr,
                     (SELECT b.status FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' AND b.status<>'storniert' ORDER BY b.id DESC LIMIT 1) AS rechnung_status
              FROM auftrag a
              LEFT JOIN kunden k ON k.id=a.kunde_id
              LEFT JOIN angebot ang ON ang.id=a.angebot_id
              WHERE a.kategorie='dienstleistung' AND a.status<>'storniert'
                AND COALESCE(a.dienstleistung_id,0)=0
                AND NOT EXISTS (SELECT 1 FROM dl_auftrag_schritt s WHERE s.auftrag_id=a.id)
                AND a.angebot_id IS NOT NULL
                AND EXISTS (SELECT 1 FROM auftrag a2 WHERE a2.angebot_id=a.angebot_id AND a2.id<>a.id AND COALESCE(a2.dienstleistung_id,0)>0)
              ORDER BY a.angebot_id, a.id");

// Bezeichnung (= Material) je Kandidat aus der best passenden Angebotsposition (Preis+Menge), nur zur Anzeige.
foreach ($cands as &$c) {
    $bez = '';
    if (!empty($c['angebot_id'])) {
        $vkCent = (int) round(((float)$c['vk_stueck']) * 100);
        foreach (all("SELECT bezeichnung, menge, preis_cent FROM angebot_position WHERE angebot_id=?", [(int)$c['angebot_id']]) as $p)
            if ((int)$p['preis_cent'] === $vkCent && abs(((float)$p['menge']) - ((float)$c['menge'])) < 0.001) { $bez = (string)$p['bezeichnung']; break; }
    }
    $c['material']  = $bez;
    $c['hatRechng'] = trim((string)($c['rechnung_nr'] ?? '')) !== '';
    $c['handeln']   = !$c['hatRechng'];   // nur ohne eigene Rechnung löschen
}
unset($c);

$loeschbar = count(array_filter($cands, fn($c) => $c['handeln']));
$blockiert = count($cands) - $loeschbar;

// --- Ausführen (nur auf ausdrücklichen Klick) ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'bereinigen') {
    $weg = 0;
    foreach ($cands as $c) {
        if (!$c['handeln']) continue;
        if ((int)$c['kunde_id'] > 0)
            log_aktivitaet('kunde', (int)$c['kunde_id'], 'team',
                'Material-Split-Auftrag ' . (string)$c['nummer'] . ' entfernt (' . ($c['material'] ?: 'Materialposition') .
                ') – Material läuft über den Service-Auftrag aus Angebot ' . (string)($c['angebot_nr'] ?: '–') . '.',
                'auftrag', 'angebot', (int)($c['angebot_id'] ?: 0));
        if (auftrag_komplett_loeschen((int)$c['id'])) $weg++;
    }
    if (function_exists('bedarf_bump')) bedarf_bump();
    header('Location: ?p=dl_split_bereinigung&fertig=1&w=' . $weg); exit;
}

render_header('dl_split_bereinigung', 'DL-Split-Bereinigung');
bx_head('Material-Split-Bereinigung',
        'Fälschlich als eigener DL-Auftrag entstandene Material-/Zubehörpositionen (Beutel, Glas, Deckel, Etikett). Sie gehören zum Service-Auftrag ihrer Gruppe und werden über dessen Rechnung abgerechnet – hier entfernen.',
        bx_btn('Zurück', '?p=dl_auftraege', 'ghost'));

if (isset($_GET['fertig']))
    echo '<div class="bx-panel badge-ok" style="padding:12px 16px">' . (int)($_GET['w'] ?? 0) . ' Material-Split-Auftrag/-Aufträge entfernt.</div>';
?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Vorschau <?= $loeschbar ? bx_badge($loeschbar . ' zu entfernen','warn') : bx_badge('nichts zu entfernen','ok') ?><?= $blockiert ? ' ' . bx_badge($blockiert . ' mit Rechnung – bleiben','err') : '' ?></h2>
    <?php if ($loeschbar): ?>
    <form method="post" style="margin:0" onsubmit="return confirm('<?= $loeschbar ?> Material-Split-Auftrag/-Aufträge endgültig löschen? Das Material bleibt als Position am Angebot und wird über den Service-Auftrag abgerechnet. Aufträge mit eigener Rechnung werden NICHT gelöscht.');">
      <input type="hidden" name="aktion" value="bereinigen">
      <button class="btn btn-primary" type="submit">Jetzt entfernen (<?= $loeschbar ?>)</button>
    </form>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:8px 0 0">Erfasst werden nur DL-Aufträge ohne Service-Verknüpfung UND ohne Schritte, die aus einem Angebot mit einem echten Service-Auftrag stammen (also ein Split). Service-Aufträge bleiben unberührt. Löschen erfolgt gezielt je Auftrag (inkl. evtl. anhängender Produktion/Verlauf) – gemeinsame Daten wie Lieferantenbestellungen bleiben erhalten. Idempotent.</p>

  <?php if (!$cands): ?>
    <div class="muted" style="margin-top:12px">Keine Material-Split-Aufträge gefunden.</div>
  <?php else: ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th>Auftrag</th><th>Kunde</th><th>Material</th><th>aus Angebot</th><th class="bx-num">Netto</th><th>Rechnung</th><th>Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($cands as $c): ?>
      <tr<?= $c['handeln'] ? '' : ' class="muted"' ?>>
        <td><a href="?p=dl_auftrag&id=<?= (int)$c['id'] ?>"><?= h((string)$c['nummer']) ?></a></td>
        <td><?= h((string)($c['kunde'] ?: '–')) ?></td>
        <td><?= h((string)($c['material'] ?: '–')) ?></td>
        <td class="muted"><?= h((string)($c['angebot_nr'] ?: '–')) ?></td>
        <td class="bx-num"><?= number_format((float)$c['gesamt_netto'],2,',','.') ?> €</td>
        <td><?= $c['hatRechng'] ? h((string)$c['rechnung_nr']) . ' <span class="muted">(' . h((string)$c['rechnung_status']) . ')</span>' : '<span class="muted">–</span>' ?></td>
        <td><?= $c['handeln'] ? '<span style="color:#8a5a12">entfernen</span>' : bx_badge('hat Rechnung – bleibt','err') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer();
