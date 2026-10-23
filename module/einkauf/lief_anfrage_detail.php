<?php
// Detailseite EINER Lieferanten-Anfrage (RFQ, Nummernkreis „LA") – Team-Sicht. Zeigt, WAS bei WELCHEM
// Lieferanten angefragt wurde, fuer welche Rezeptur/welchen Rohstoff und welchen Kunden, das abgegebene
// Angebot (Preis/Staffel) und die Rueckfragen (Chat Team <-> Lieferant) zu genau diesem Vorgang.
// Route: ?p=lief_anfrage_detail&id=<anfrage_id>  (auch &nr=LA-123). Ziel aus globaler Suche, Liste und Chat.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/nachricht.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id && trim((string)($_GET['nr'] ?? '')) !== '')   // Sprung per Nummer (LA-123) aus der Suche/dem Chat
    $id = (int) scalar("SELECT id FROM lieferant_anfrage WHERE nummer=? ORDER BY id DESC LIMIT 1", [trim((string)$_GET['nr'])]);

$a = $id ? one("SELECT af.*, l.firma AS lief_firma FROM lieferant_anfrage af
                LEFT JOIN lieferanten l ON l.id=af.lieferant_id WHERE af.id=?", [$id]) : null;

// POST: Rueckfrage beantworten / Angebot annehmen.
if ($a && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $akt = (string)($_POST['aktion'] ?? '');
    if ($akt === 'nachricht') {
        $f = nachricht_post_verarbeiten((int)$a['lieferant_id'], 'team', (string)(current_user()['name'] ?? 'Team'), 'lieferant_anfrage', (int)$a['id']);
        header('Location: ?p=lief_anfrage_detail&id=' . $id . ($f === '' ? '&nachricht=1' : '&fehler=' . urlencode($f)) . '#rueckfragen'); exit;
    }
    if ($akt === 'angebot_annehmen' && (has_role('admin') || has_role('einkauf'))) {
        $f = lieferant_angebot_annehmen((int)($_POST['angebot_id'] ?? 0));
        header('Location: ?p=lief_anfrage_detail&id=' . $id . ($f === '' ? '&angenommen=1' : '&fehler=' . urlencode($f))); exit;
    }
    header('Location: ?p=lief_anfrage_detail&id=' . $id); exit;
}

render_header('lief_anfragen', 'Lieferanten-Anfrage');
if (!$a) { bx_head('Anfrage nicht gefunden', '', bx_btn('Zurück', '?p=lief_anfragen', 'ghost')); render_footer(); return; }

$ARTLBL = ['rohstoff'=>'Rohstoff','fertigprodukt'=>'Fremdfertigung','verpackung'=>'Verpackung','verbrauch'=>'Verbrauch','sonstiges'=>'Sonstiges'];
$VERSL  = function_exists('versandart_liste') ? versandart_liste() : [];
$zahl = fn($x, $n2) => $x === null || $x === '' ? '–'
    : ($n2 > 0 ? rtrim(rtrim(number_format((float)$x, $n2, ',', '.'), '0'), ',') : number_format((float)$x, 0, ',', '.'));

// Worum geht es: Rezeptur (Fremdfertigung) mit Kunde, Rohstoff oder Freitext.
$wasLink = null; $wasText = $a['betreff'] ?: '–'; $kundeName = '';
if ((int)$a['rezeptur_id'] > 0) {
    $rz = one("SELECT rz.name, rz.nummer, rz.darreichungsform, k.firma AS kunde FROM rezeptur rz
               LEFT JOIN kunden k ON k.id=rz.kunde_id WHERE rz.id=?", [(int)$a['rezeptur_id']]);
    $wasLink = '?p=rezeptur_detail&id=' . (int)$a['rezeptur_id'];
    // Rezepturnummer immer mit zeigen (RZ-… · Name).
    $wasText = 'Fremdfertigung: ' . trim((((string)($rz['nummer'] ?? '')) !== '' ? $rz['nummer'] . ' · ' : '') . ($rz['name'] ?? 'Rezeptur'));
    $kundeName = (string)($rz['kunde'] ?? '');
} elseif ((int)$a['item_id'] > 0) {
    $it = one("SELECT name, artikelnummer FROM item WHERE id=?", [(int)$a['item_id']]);
    $wasLink = '?p=rohstoff&id=' . (int)$a['item_id'];
    $wasText = (($it['artikelnummer'] ?? '') ? $it['artikelnummer'] . ' · ' : '') . ($it['name'] ?? 'Rohstoff');
}

// Abgegebenes Angebot des Lieferanten (falls vorhanden) + Staffel.
$ang = one("SELECT * FROM lieferant_angebot WHERE anfrage_id=? ORDER BY id DESC LIMIT 1", [$id]);
$staffel = $ang ? all("SELECT menge_ab, preis FROM lieferant_angebot_staffel WHERE angebot_id=? ORDER BY menge_ab", [(int)$ang['id']]) : [];

bx_head('Lieferanten-Anfrage ' . h((string)($a['nummer'] ?: ('LA-' . $a['id']))),
        'An ' . h((string)($a['lief_firma'] ?: '–')) . ' · ' . h($ARTLBL[$a['art']] ?? (string)$a['art']),
        bx_btn('Zurück zur Liste', '?p=lief_anfragen', 'ghost'));

if (isset($_GET['nachricht'])):  ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin-bottom:12px;display:inline-block">Nachricht gesendet.</div><?php endif;
if (isset($_GET['angenommen'])): ?><div class="badge-ok" style="padding:6px 10px;border-radius:8px;margin-bottom:12px;display:inline-block">Angebot angenommen – Preise übernommen.</div><?php endif;
if (!empty($_GET['fehler'])):    ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:10px 14px;margin-bottom:12px"><?= h((string)$_GET['fehler']) ?></div><?php endif; ?>

<div class="bx-panel">
  <h2 style="margin-top:0">Anfrage</h2>
  <div class="bx-grid">
    <div><div class="k muted">Lieferant</div><div><a href="?p=lieferant&id=<?= (int)$a['lieferant_id'] ?>"><?= h((string)($a['lief_firma'] ?: '–')) ?></a></div></div>
    <div><div class="k muted">Worum geht es</div><div><?php if ($wasLink): ?><a href="<?= h($wasLink) ?>"><?= h($wasText) ?></a><?php else: ?><?= h($wasText) ?><?php endif; ?></div></div>
    <?php if ($kundeName !== ''): ?><div><div class="k muted">Für Kunde</div><div><?= h($kundeName) ?></div></div><?php endif; ?>
    <div><div class="k muted">Angefragte Menge</div><div><?= $a['menge'] ? $zahl($a['menge'], 3) . ' ' . h(einheit_wort((string)($a['einheit'] ?? ''), (float)$a['menge'])) : '–' ?></div></div>
    <?php if ($a['stueck_je_packung']): ?><div><div class="k muted">Je Packung</div><div><?= (int)$a['stueck_je_packung'] ?> <?= h(einheit_wort((string)($a['einheit'] ?? ''), (float)$a['stueck_je_packung'])) ?><?= $a['kapselgroesse_id'] ? ' · ' . h((string) scalar("SELECT name FROM kapselgroesse WHERE id=?", [(int)$a['kapselgroesse_id']])) : '' ?></div></div><?php endif; ?>
    <?php if (trim((string)($a['betreff'] ?? '')) !== '' && $wasLink): ?><div><div class="k muted">Betreff</div><div><?= h((string)$a['betreff']) ?></div></div><?php endif; ?>
    <div><div class="k muted">Angelegt</div><div><?= $a['angelegt'] ? h(fmt_zeit($a['angelegt'], 'd.m.Y H:i')) : '–' ?></div></div>
    <div><div class="k muted">Status</div><div><?= $a['status'] === 'offen' ? bx_badge('offen', 'info') : ($a['status'] === 'beantwortet' ? bx_badge('beantwortet', 'warn') : bx_badge((string)$a['status'], 'ok')) ?></div></div>
  </div>
  <?php if (trim((string)($a['notiz'] ?? '')) !== ''): ?><div style="margin-top:12px"><div class="k muted">Notiz</div><div style="white-space:pre-line"><?= h((string)$a['notiz']) ?></div></div><?php endif; ?>
</div>

<?php
// CoA / Spezifikation: Status (angefragt?) + die vom Lieferanten hochgeladenen Unterlagen. Diese landen beim
// Hochladen am ROHSTOFF (objekt_typ='item') bzw. am Lieferanten (objekt_typ='lieferant'); hier gebündelt sichtbar.
$coaReq = (int)($a['coa_gewuenscht'] ?? 0) === 1;
$lidA = (int)$a['lieferant_id']; $iidA = (int)($a['item_id'] ?? 0);
$coaDocs = all("SELECT id, typ, titel, datei_orig, hochgeladen_von, COALESCE(dok_datum, DATE(angelegt)) AS datum
                FROM dokument
                WHERE typ IN ('coa','spec','beides','analyse')
                  AND ((objekt_typ='lieferant' AND lieferant_id=?) OR (? > 0 AND objekt_typ='item' AND objekt_id=?))
                ORDER BY id DESC", [$lidA, $iidA, $iidA]);
$coaTypLbl = ['coa'=>'CoA', 'spec'=>'Spezifikation', 'beides'=>'CoA + Spezifikation', 'analyse'=>'Analyse'];
?>
<div class="bx-panel"<?= $coaReq ? ' style="border-color:var(--gruen)"' : '' ?>>
  <h2 style="margin-top:0">CoA / Spezifikation <?= $coaReq ? bx_badge('angefragt', 'ok') : bx_badge('nicht angefragt') ?></h2>
  <p class="muted" style="margin-top:0;font-size:13px"><?= $coaReq
      ? 'Der Lieferant sieht in seinem Portal den Hinweis „CoA / Spezifikation angefordert" und kann sie beim Preisangebot direkt hochladen.'
      : 'Für diese Anfrage wurde keine CoA/Spezifikation angefordert.' ?></p>
  <?php if ($coaDocs): ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Art</th><th>Datei</th><th>Datum</th><th>Quelle</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($coaDocs as $d): ?>
      <tr>
        <td><?= bx_badge($coaTypLbl[$d['typ']] ?? (string)$d['typ'], 'info') ?></td>
        <td><?= h((string)($d['datei_orig'] ?: $d['titel'] ?: ('Dokument #' . $d['id']))) ?></td>
        <td><?= $d['datum'] ? h(date('d.m.Y', strtotime((string)$d['datum']))) : '<span class="muted">–</span>' ?></td>
        <td><?= ($d['hochgeladen_von'] ?? '') === 'lieferant' ? '<span style="color:var(--gruen)">Lieferant</span>' : '<span class="muted">Team</span>' ?></td>
        <td class="bx-num"><a class="btn btn-ghost btn-sm" target="_blank" href="?p=dokument&id=<?= (int)$d['id'] ?>">&#8681; Ansehen</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?>
    <div class="muted"><?= $coaReq ? 'Noch keine CoA/Spezifikation vom Lieferanten hochgeladen.' : 'Keine CoA/Spezifikation hinterlegt.' ?></div>
  <?php endif; ?>
</div>

<div class="bx-panel">
  <h2 style="margin-top:0">Angebot des Lieferanten</h2>
  <?php if (!$ang): ?>
    <div class="muted">Noch kein Preis abgegeben.</div>
  <?php else: $basis = (int)($ang['preis_basis'] ?? 1) === 1000 ? 1000 : 1; $cur = h((string)($ang['waehrung'] ?: 'EUR')); ?>
    <div class="bx-grid">
      <div><div class="k muted">Preis</div><div><strong><?= $zahl($ang['preis'], 4) ?> <?= $cur ?></strong> / <?= $basis === 1000 ? '1.000 ' : '' ?><?= h($ang['einheit'] ? einheit_wort((string)$ang['einheit'], $basis) : '–') ?></div></div>
      <?php if ($ang['mindestmenge']): ?><div><div class="k muted">Mindestmenge (MOQ)</div><div><?= $zahl($ang['mindestmenge'], 3) ?></div></div><?php endif; ?>
      <?php if ($ang['lieferzeit_tage']): ?><div><div class="k muted">Lieferzeit</div><div><?= (int)$ang['lieferzeit_tage'] ?> Tage</div></div><?php endif; ?>
      <?php $terms = array_filter([(string)($ang['incoterm'] ?? ''), !empty($ang['versandart']) ? ($VERSL[$ang['versandart']] ?? $ang['versandart']) : '']);
            if ($terms): ?><div><div class="k muted">Lieferbedingung</div><div style="color:var(--gruen)"><?= h(implode(' · ', $terms)) ?></div></div><?php endif; ?>
    </div>
    <?php if ($staffel): ?><div style="margin-top:10px"><div class="k muted">Mengenstaffel</div><div><?php $tx = []; foreach ($staffel as $s) $tx[] = 'ab ' . $zahl($s['menge_ab'], 0) . ': ' . $zahl($s['preis'], 4) . ' ' . $cur; echo h(implode(' · ', $tx)); ?></div></div><?php endif; ?>
    <?php if (trim((string)($ang['notiz'] ?? '')) !== ''): ?><div style="margin-top:10px"><div class="k muted">Notiz des Lieferanten</div><div style="white-space:pre-line"><?= h((string)$ang['notiz']) ?></div></div><?php endif; ?>
    <?php if (($ang['status'] ?? '') !== 'angenommen' && (has_role('admin') || has_role('einkauf'))): ?>
    <form method="post" style="margin-top:14px" onsubmit="return confirm('Angebot annehmen? Die Preise werden als Zukauf-/EK-Preise übernommen.');">
      <input type="hidden" name="aktion" value="angebot_annehmen"><input type="hidden" name="angebot_id" value="<?= (int)$ang['id'] ?>">
      <button class="btn btn-primary" type="submit">Preise übernehmen</button>
    </form>
    <?php elseif (($ang['status'] ?? '') === 'angenommen'): ?><div style="margin-top:12px"><?= bx_badge('übernommen', 'ok') ?></div><?php endif; ?>
  <?php endif; ?>
</div>

<div id="rueckfragen">
  <?= nachricht_panel((int)$a['lieferant_id'], 'team', 'de', 'lieferant_anfrage', (int)$a['id']) ?>
</div>
<?php render_footer();
