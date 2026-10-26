<?php
// Einmal-Bereinigung (Admin): Laboranalyse-Altlasten aus der ALTEN Upsell-Logik.
// Bis Commit 337b058 (23.10.2026) legte der Labortest-Haken bei der Bestellannahme eine
// Dienstleistungs-Anfrage (betreff='Laboranalyse', produkt_id gesetzt) an -> daraus wurde ein DA-Angebot,
// das beim Kunden als separates Angebot erschien. Richtig ist: die Laboranalyse gehört AUF DEN AUFTRAG
// (auftrag.laboranalyse_gebucht -> Rechnungsposition + Labor-Verlaufspunkt), kein separates Angebot.
// Dieses Werkzeug bucht die Analyse nach auf dem passenden Auftrag und zieht das irrtümliche DA-Angebot zurück.
// Route: ?p=labor_bereinigung — VORSCHAU per Default, Änderung nur auf ausdrücklichen Klick. Idempotent.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

if (!function_exists('has_role') || !has_role('admin')) {
    render_header('labor_bereinigung', 'Laboranalyse-Bereinigung');
    bx_head('Kein Zugriff', 'Nur für Admins.', bx_btn('Zurück', '?p=dashboard', 'ghost'));
    render_footer(); exit;
}

// --- Alt-Upsell-Kandidaten einsammeln (reine Lese-Abfrage) -----------------------------------------
// Signatur der alten Upsell-Anfrage: typ=dienstleistung, dienstleistung_typ=labortest, betreff='Laboranalyse',
// produkt_id am KOPF gesetzt. Echte manuelle Labortest-Anfragen haben betreff 'Labortest / Analyse' (oder
// Freitext) und KEIN produkt_id am Kopf (Produkte nur in portal_anfrage_pos) -> werden hier NICHT erfasst.
$cands = all("SELECT pa.id AS anfrage_id, pa.nummer AS anfrage_nr, pa.kunde_id, pa.produkt_id,
                     pa.status AS anfrage_status, pa.angelegt,
                     k.firma AS kunde, p.name AS produkt_name
              FROM portal_anfrage pa
              LEFT JOIN kunden k ON k.id=pa.kunde_id
              LEFT JOIN produkt p ON p.id=pa.produkt_id
              WHERE pa.typ='dienstleistung' AND pa.dienstleistung_typ='labortest'
                AND pa.betreff='Laboranalyse' AND pa.produkt_id IS NOT NULL
              ORDER BY pa.kunde_id, pa.angelegt DESC");

// Je Kandidat: zugehöriges DA-Angebot (neuestes) + bester Auftrags-Treffer (gleicher Kunde+Produkt, zeitnah).
foreach ($cands as &$c) {
    $c['angebot'] = one("SELECT id, nummer, status, preise_kunde FROM angebot
                         WHERE anfrage_id=? AND kategorie='dienstleistung' ORDER BY id DESC LIMIT 1", [(int)$c['anfrage_id']]);
    $c['auftrag'] = one("SELECT a.id, a.nummer, a.status, a.laboranalyse_gebucht,
                                (SELECT b.nummer FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' AND b.status<>'storniert' ORDER BY b.id DESC LIMIT 1) AS rechnung_nr,
                                (SELECT b.status FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' AND b.status<>'storniert' ORDER BY b.id DESC LIMIT 1) AS rechnung_status
                         FROM auftrag a
                         WHERE a.kunde_id=? AND a.produkt_id=? AND a.status<>'storniert'
                         ORDER BY ABS(TIMESTAMPDIFF(SECOND, a.angelegt, ?)) ASC, a.id DESC LIMIT 1",
                        [(int)$c['kunde_id'], (int)$c['produkt_id'], (string)$c['angelegt']]);
    // Schon erledigt? (idempotent) – Anfrage geschlossen ODER Angebot bereits vom Kunden angenommen.
    $angSt = (string)($c['angebot']['status'] ?? '');
    $c['erledigt']  = in_array((string)$c['anfrage_status'], ['geschlossen','erledigt'], true);
    $c['angenommen'] = in_array($angSt, ['bestaetigt','angenommen'], true);   // vom Kunden angenommen -> NICHT anfassen
    $c['handeln']   = !$c['erledigt'] && !$c['angenommen'];
}
unset($c);

$offenN = count(array_filter($cands, fn($c) => $c['handeln']));

// --- Ausführen (nur auf ausdrücklichen Klick) ------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'bereinigen') {
    $gebucht = 0; $geloescht = 0; $geschlossen = 0; $ohneAuftrag = 0;
    foreach ($cands as $c) {
        if (!$c['handeln']) continue;
        // 1) Laboranalyse auf den passenden Auftrag buchen (idempotent; bezahlte/stornierte Rechnungen unberührt).
        if ($c['auftrag'] && (int)($c['auftrag']['laboranalyse_gebucht'] ?? 0) === 0) {
            auftrag_laboranalyse_buchen((int)$c['auftrag']['id']); $gebucht++;
        } elseif (!$c['auftrag']) {
            $ohneAuftrag++;   // kein eindeutiger Auftrag -> nur Angebot löschen/Anfrage schließen
        }
        // 2) Irrtümliches, NICHT angenommenes DA-Angebot LÖSCHEN (ohne Auftrag): erst zurück in 'offen', dann
        //    spurlos verwerfen (Positionen/Staffeln weg, Nummer zurück, Log) – angebot_entwurf_verwerfen().
        $agId = (int)($c['angebot']['id'] ?? 0);
        if ($agId && !in_array((string)($c['angebot']['status'] ?? ''), ['bestaetigt','angenommen'], true)
            && !scalar("SELECT id FROM auftrag WHERE angebot_id=?", [$agId])) {
            if ((string)$c['angebot']['status'] !== 'offen') q("UPDATE angebot SET status='offen', preise_kunde=0 WHERE id=?", [$agId]);
            if (angebot_entwurf_verwerfen($agId)) $geloescht++;
        }
        // 3) Anfrage schließen.
        q("UPDATE portal_anfrage SET status='geschlossen' WHERE id=?", [(int)$c['anfrage_id']]);
        $geschlossen++;
        if ((int)$c['kunde_id'] > 0)
            log_aktivitaet('kunde', (int)$c['kunde_id'], 'team',
                'Laboranalyse-Altlast bereinigt: ' . ($c['auftrag'] ? 'auf Auftrag ' . $c['auftrag']['nummer'] . ' gebucht, ' : '') .
                'Alt-Angebot ' . ($c['angebot']['nummer'] ?? '–') . ' gelöscht, Anfrage ' . $c['anfrage_nr'] . ' geschlossen.',
                'anfrage', $c['auftrag'] ? 'auftrag' : 'anfrage', (int)($c['auftrag']['id'] ?? (int)$c['anfrage_id']));
    }
    bedarf_bump();
    header('Location: ?p=labor_bereinigung&fertig=1&g=' . $gebucht . '&z=' . $geloescht . '&c=' . $geschlossen . '&na=' . $ohneAuftrag); exit;
}

render_header('labor_bereinigung', 'Laboranalyse-Bereinigung');
bx_head('Laboranalyse-Bereinigung (Alt-Upsell)',
        'Laboranalysen, die durch die alte Bestell-Logik irrtümlich als separates Angebot entstanden sind – hier auf den Auftrag nachbuchen und das Alt-Angebot zurückziehen.',
        bx_btn('Zurück', '?p=dashboard', 'ghost'));

if (isset($_GET['fertig'])) {
    echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Bereinigt: '
       . (int)($_GET['g'] ?? 0) . '× auf Auftrag gebucht · ' . (int)($_GET['z'] ?? 0) . '× Angebot gelöscht · '
       . (int)($_GET['c'] ?? 0) . '× Anfrage geschlossen'
       . ((int)($_GET['na'] ?? 0) > 0 ? ' · ' . (int)$_GET['na'] . '× ohne eindeutigen Auftrag (nur Angebot zurückgezogen)' : '') . '.</div>';
}

$mapAngStatus = fn($s) => match ((string)$s) {
    'gesendet' => bx_badge('beim Kunden (wird gelöscht)','warn'),
    'offen'    => bx_badge('Entwurf (wird gelöscht)'),
    'bestaetigt','angenommen' => bx_badge('angenommen – bleibt','ok'),
    default    => $s ? bx_badge((string)$s) : '<span class="muted">kein Angebot</span>',
};
?>
<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Vorschau <?= $offenN ? bx_badge($offenN . ' zu bereinigen','warn') : bx_badge('nichts offen','ok') ?></h2>
    <?php if ($offenN): ?>
    <form method="post" style="margin:0" onsubmit="return confirm('<?= $offenN ?> Laboranalyse-Altlasten bereinigen? Analyse wird auf den jeweiligen Auftrag gebucht (offene Rechnungen bekommen die 250-€-Position; bezahlte/stornierte bleiben unberührt) und das nicht angenommene Alt-Angebot gelöscht.');">
      <input type="hidden" name="aktion" value="bereinigen">
      <button class="btn btn-primary" type="submit">Jetzt bereinigen (<?= $offenN ?>)</button>
    </form>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:8px 0 0">Erfasst werden nur Anfragen der alten Upsell-Signatur (Betreff „Laboranalyse" + Produkt am Kopf). Echte, manuell gestellte Labortest-Anfragen bleiben unberührt. Bereits vom Kunden <strong>angenommene</strong> Angebote werden nicht angefasst. Idempotent – mehrfaches Ausführen schadet nicht.</p>

  <?php if (!$cands): ?>
    <div class="muted" style="margin-top:12px">Keine Laboranalyse-Altlasten gefunden.</div>
  <?php else: ?>
  <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
    <thead><tr><th>Kunde</th><th>Anfrage</th><th>Produkt</th><th>Alt-Angebot</th><th>Passender Auftrag</th><th>Rechnung</th><th>Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($cands as $c): ?>
      <tr<?= $c['handeln'] ? '' : ' class="muted"' ?>>
        <td><?= h((string)($c['kunde'] ?: '–')) ?></td>
        <td><?= h((string)$c['anfrage_nr']) ?></td>
        <td><?= h((string)($c['produkt_name'] ?: '–')) ?></td>
        <td><?= $c['angebot'] ? h((string)$c['angebot']['nummer']) . ' ' . $mapAngStatus($c['angebot']['status']) : '<span class="muted">kein Angebot</span>' ?></td>
        <td><?= $c['auftrag'] ? '<a href="?p=auftrag&id=' . (int)$c['auftrag']['id'] . '">' . h((string)$c['auftrag']['nummer']) . '</a> ' . ((int)($c['auftrag']['laboranalyse_gebucht'] ?? 0) === 1 ? bx_badge('Labor schon gebucht','ok') : '') : '<span style="color:#8f231b">kein eindeutiger Auftrag</span>' ?></td>
        <td><?= $c['auftrag'] && $c['auftrag']['rechnung_nr'] ? h((string)$c['auftrag']['rechnung_nr']) . ' <span class="muted">(' . h((string)$c['auftrag']['rechnung_status']) . ')</span>' : '<span class="muted">–</span>' ?></td>
        <td><?php
            if ($c['erledigt']) echo bx_badge('bereits bereinigt','ok');
            elseif ($c['angenommen']) echo bx_badge('angenommen – bleibt','ok');
            else {
                $teile = [];
                if ($c['auftrag'] && (int)($c['auftrag']['laboranalyse_gebucht'] ?? 0) === 0) $teile[] = 'auf Auftrag buchen';
                if ($c['angebot']) $teile[] = 'Angebot löschen';
                $teile[] = 'Anfrage schließen';
                echo '<span style="color:#8a5a12">' . h(implode(' · ', $teile)) . '</span>';
            }
        ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php render_footer();
