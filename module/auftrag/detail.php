<?php
// Auftrag (Auftragsbestätigung) – Ansicht + Status
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/anfrage_ui.php';

$id = (int)($_GET['id'] ?? 0);

// Express-Bestellung (nur Admin): überspringt Einkaufsbedarf/-liste und bestellt direkt beim Lieferanten.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'express_bestellung') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $bid = auftrag_express_bestellung($id, (int)($_POST['lieferant_id'] ?? 0));
    if ($bid) { header('Location: ?p=bestellung&id=' . $bid . '&ok=1'); exit; }
    header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nichts zu bestellen – alles auf Lager oder der Lieferant bietet die fehlenden Rohstoffe nicht.')); exit;
}
// Express-Zukauf des FERTIGPRODUKTS (Bulk) – nur Admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && ($_POST['aktion'] ?? '') === 'express_bulk') {
    if (!has_role('admin')) { header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Nur Admins.')); exit; }
    $bid = auftrag_express_bulk_bestellung($id, (int)($_POST['lieferant_id'] ?? 0));
    if ($bid) { header('Location: ?p=bestellung&id=' . $bid . '&ok=1'); exit; }
    header('Location: ?p=auftrag&id=' . $id . '&expressfehler=' . urlencode('Kein Zukaufpreis für dieses Produkt bei dem Lieferanten hinterlegt.')); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    // Preis nachpflegen: VK je Packung + Menge editierbar, Netto = Menge × VK automatisch.
    $menge = max(0, (int)($_POST['menge'] ?? 0));
    $vk    = round(zahl_lesen((string)($_POST['vk_stueck'] ?? '0')), 4);
    $netto = round($menge * $vk, 2);
    q("UPDATE auftrag SET status=?, menge=?, vk_stueck=?, gesamt_netto=? WHERE id=?",
      [trim($_POST['status'] ?? 'offen'), $menge, $vk, $netto, $id]);
    header('Location: ?p=auftrag&id=' . $id . '&gespeichert=1'); exit;
}

$a = $id ? one("SELECT a.*, k.firma AS kunde_firma, p.name AS produkt_name, ang.nummer AS angebot_nr
                FROM auftrag a
                LEFT JOIN kunden k ON k.id=a.kunde_id
                LEFT JOIN produkt p ON p.id=a.produkt_id
                LEFT JOIN angebot ang ON ang.id=a.angebot_id
                WHERE a.id=?", [$id]) : null;
if (!$a) { render_header('auftraege','Auftrag'); bx_head('Auftrag nicht gefunden','', bx_btn('Zurück','?p=auftraege','ghost')); render_footer(); exit; }

$rechnung = one("SELECT id, nummer, brutto, status FROM beleg WHERE auftrag_id=? AND typ='rechnung' LIMIT 1", [$id]);
$rezeptur = !empty($a['produkt_id'])
    ? one("SELECT r.id, r.nummer, r.name FROM produkt p JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.id=?", [(int)$a['produkt_id']])
    : null;
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$statusBadge = match ($a['status']) {
    'offen'         => bx_badge('offen','info'),
    'in_produktion' => bx_badge('in Produktion','warn'),
    'erledigt'      => bx_badge('versandbereit','info'),
    'versendet'     => bx_badge('versendet','ok'),
    default         => bx_badge(status_text($a['status'])),
};

// Produktion + Beschaffung zu diesem Auftrag
$pa = one("SELECT * FROM produktionsauftrag WHERE auftrag_id=? ORDER BY id DESC LIMIT 1", [$id]);
$istFremd = $pa && ($pa['produktionsart'] ?? '') === 'fremd';
$paStatusBadge = $pa ? match ($pa['status']) {
    'offen'=>bx_badge('offen','info'),'laufend'=>bx_badge('läuft','warn'),'erledigt'=>bx_badge('fertig','ok'),
    default=>bx_badge(status_text((string)$pa['status'])),
} : '';
$ber = $pa ? produktion_bereitschaft((int)$pa['id']) : ['status'=>''];
$einhProP  = (int) scalar("SELECT einheiten_pro_packung FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
if ($einhProP <= 0) $einhProP = (int)($a['stueck'] ?? 0);   // Fallback: Stück je Packung liegt am Auftrag (v3-Import)
$gesamtStk = $einhProP > 0 ? (int)$a['menge'] * $einhProP : 0;
$produktName = (string)($a['produkt_name'] ?? '') ?: (string)($a['produkt_bezeichnung'] ?? '');
$groesseLbl = produktion_groesse_label((int)$a['produkt_id']);
// Bestellungen (bei welchem Lieferanten, welcher Status) – verknüpft über die Position.
$best = all("SELECT DISTINCT b.id, b.nummer, b.status, b.bestaetigt, b.angekommen_am, l.firma AS lieferant
             FROM bestellung b JOIN bestellung_position bp ON bp.bestellung_id=b.id
             LEFT JOIN lieferanten l ON l.id=b.lieferant_id
             WHERE bp.auftrag_id=? ORDER BY b.angelegt DESC", [$id]);
$bStatus = function($b) {
    if (!empty($b['angekommen_am']))        return bx_badge('angekommen','ok');
    if ((int)($b['bestaetigt'] ?? 0) === 1) return bx_badge('bestätigt','warn');
    return match ((string)$b['status']) {
        'offen'=>bx_badge('offen','info'),'bestellt'=>bx_badge('bestellt','warn'),'geliefert'=>bx_badge('geliefert','ok'),
        default=>bx_badge((string)$b['status']),
    };
};

// EK-Preise je benötigtem Rohstoff (nur Admin) – „wo kann ich bestellen" + Express-Bestellung.
$istAdmin = function_exists('has_role') && has_role('admin');
$ekBedarf = []; $ekLieferanten = [];
$zukaufPreise = []; $zukaufLief = []; $prodRezId = 0;
if ($istAdmin && $pa) {
    foreach (produktion_materialbedarf((int)$pa['id']) as $bd) {
        $angebote = all("SELECT lp.lieferant_id, lp.menge_ab, lp.preis, lp.waehrung, COALESCE(l.firma, lp.lieferant_name, '') AS firma
                         FROM lieferant_preis lp LEFT JOIN lieferanten l ON l.id=lp.lieferant_id
                         WHERE lp.item_id=? ORDER BY lp.preis", [(int)$bd['item_id']]);
        foreach ($angebote as $ao) if (!empty($ao['lieferant_id'])) $ekLieferanten[(int)$ao['lieferant_id']] = (string)$ao['firma'];
        $ekBedarf[] = $bd + ['angebote' => $angebote];
    }
}
// Fertigprodukt-Zukauf (Bulk): Zukaufpreise + Anfrage-Möglichkeit für das Produkt des Auftrags.
if ($istAdmin && !empty($a['produkt_id'])) {
    $prodRezId = (int) scalar("SELECT rezeptur_id FROM produkt WHERE id=?", [(int)$a['produkt_id']]);
    $zukaufPreise = all("SELECT z.lieferant_id, z.menge_ab, z.preis, z.waehrung, z.einheit, z.groesse, z.incoterm, z.versandart,
                                COALESCE(l.firma, z.lieferant_name, '') AS firma
                         FROM produkt_lieferant_preis z LEFT JOIN lieferanten l ON l.id=z.lieferant_id
                         WHERE z.produkt_id=? ORDER BY z.preis, z.menge_ab", [(int)$a['produkt_id']]);
    foreach ($zukaufPreise as $z) if (!empty($z['lieferant_id'])) $zukaufLief[(int)$z['lieferant_id']] = (string)$z['firma'];
}
$anfrageLieferanten = $istAdmin ? all("SELECT id, firma, land FROM lieferanten WHERE gesperrt=0 AND COALESCE(keine_anfragen,0)=0 ORDER BY firma") : [];

render_header('auftraege', $a['nummer']);
bx_head($a['nummer'], 'Auftragsbestätigung', bx_btn('Zurück zur Liste', '?p=auftraege', 'ghost'));
if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';

// Einheitliche, ruhige Wertgröße für alle Kennzahl-Karten (Badges behalten ihre eigene Größe).
echo '<style>.bx-cards .v{font-size:15px;line-height:1.4}</style>';
echo '<div class="bx-cards">';
echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . $statusBadge . '</div></div>';
echo '<div class="bx-card"><div class="k">Menge (Packungen)</div><div class="v">' . (int)$a['menge'] . '</div></div>';
if ($einhProP > 0) echo '<div class="bx-card"><div class="k">Stück je Packung</div><div class="v">' . number_format($einhProP, 0, ',', '.') . '</div></div>';
if ($gesamtStk > 0) echo '<div class="bx-card"><div class="k">Gesamtstückzahl</div><div class="v">' . number_format($gesamtStk, 0, ',', '.') . '</div></div>';
if ($groesseLbl !== '') echo '<div class="bx-card"><div class="k">Kapsel/Tablette</div><div class="v">' . h($groesseLbl) . '</div></div>';
echo '<div class="bx-card"><div class="k">Herstellung</div><div class="v">' . ($istFremd ? bx_badge('Zukauf','info') : bx_badge('Eigenproduktion','ok')) . '</div></div>';
echo '<div class="bx-card"><div class="k">VK / Stück</div><div class="v">' . $eur($a['vk_stueck']) . '</div></div>';
echo '<div class="bx-card"><div class="k">Netto gesamt</div><div class="v">' . $eur($a['gesamt_netto']) . '</div></div>';
if (!empty($a['angelegt'])) echo '<div class="bx-card"><div class="k">Erstellt</div><div class="v">' . h(fmt_zeit($a['angelegt'], 'd.m.Y H:i')) . '</div></div>';
echo '</div>';
?>
<div class="bx-panel">
  <h2>Details</h2>
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= kunde_link($a['kunde_id'] ?? null, $a['kunde_firma']) ?></div></div>
    <div><div class="k muted">Produkt</div><div><?= $produktName ? h($produktName) . (empty($a['produkt_id']) ? ' <span class="muted" style="font-size:12px">(aus v3)</span>' : '') : '–' ?></div></div>
    <div><div class="k muted">Rezeptur</div><div><?php if ($rezeptur): ?><a href="?p=rezeptur_detail&id=<?= (int)$rezeptur['id'] ?>"><?= h($rezeptur['nummer']) ?></a><?= $rezeptur['name'] ? ' · ' . h($rezeptur['name']) : '' ?><?php else: ?>–<?php endif; ?></div></div>
    <div><div class="k muted">Aus Angebot</div><div><?php if ($a['angebot_id']): ?><a href="?p=angebot&id=<?= (int)$a['angebot_id'] ?>"><?= h($a['angebot_nr']) ?></a><?php else: ?>–<?php endif; ?></div></div>
    <?php if (!empty($a['kontingent_id'])): ?><div><div class="k muted">Herkunft</div><div><a href="?p=kontingente" title="Abruf aus einem Jahresabnahmevertrag"><?= bx_badge('aus Jahresvertrag','info') ?></a></div></div><?php endif; ?>
    <div><div class="k muted">Rechnung</div><div><?php if ($rechnung): ?><a href="?p=rechnung&id=<?= (int)$rechnung['id'] ?>"><?= h($rechnung['nummer']) ?></a> · <?= $eur($rechnung['brutto']) ?> · <?= $rechnung['status']==='bezahlt'?bx_badge('bezahlt','ok'):bx_badge('offen','warn') ?><?php else: ?>–<?php endif; ?></div></div>
  </div>
</div>

<div class="bx-panel">
  <h2>Produktion &amp; Beschaffung</h2>
  <div class="bx-grid">
    <div><div class="k muted">Herstellung</div><div>
      <?= $istFremd ? bx_badge('Fremdproduktion · fertige Bulkware zukaufen','info') : bx_badge('Eigenproduktion · aus Rohstoffen','ok') ?>
    </div></div>
    <?php if ($pa): ?>
    <div><div class="k muted">Produktionsauftrag</div><div><a href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>"><?= h($pa['nummer']) ?></a> · <?= $paStatusBadge ?></div></div>
    <div><div class="k muted">Material</div><div>
      <?= bereitschaft_badge($ber['status'] ?? '') ?>
      <?php if (($ber['status'] ?? '') === 'wartet'): ?> <a href="?p=produktionsauftrag&id=<?= (int)$pa['id'] ?>" style="font-size:12px">was fehlt?</a><?php endif; ?>
    </div></div>
    <?php else: ?>
    <div><div class="k muted">Produktionsauftrag</div><div class="muted">noch keiner angelegt</div></div>
    <?php endif; ?>
  </div>

  <h3 style="margin:18px 0 6px;font-size:14px;font-weight:600">Bestellungen zu diesem Auftrag</h3>
  <?php if (!$best): ?>
    <div class="muted"><?= $istFremd ? 'Noch keine Bestellung erfasst – fertige Bulkware ist noch nicht bestellt.' : 'Noch keine Bestellung erfasst – Rohstoffe sind noch offen.' ?>
      <?php if ($pa): ?> <a href="?p=bedarf" style="font-size:12px">zum Einkaufsbedarf</a><?php endif; ?></div>
  <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Bestellung</th><th>Lieferant</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($best as $b): ?>
        <tr><td><a href="?p=bestellung&id=<?= (int)$b['id'] ?>"><?= h($b['nummer']) ?></a></td>
            <td><?= $b['lieferant'] ? h($b['lieferant']) : '<span class="muted">–</span>' ?></td>
            <td><?= $bStatus($b) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<?php if ($istAdmin): ?>
<?php if (isset($_GET['expressfehler'])): ?><div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px"><?= h((string)$_GET['expressfehler']) ?></div><?php endif; ?>
<div class="bx-panel">
  <h2 style="margin-top:0">EK-Preise &amp; Express-Bestellung <span class="muted" style="font-weight:normal;font-size:13px">· nur intern (Admin)</span></h2>
  <?php if (!$pa): ?>
    <div class="muted">Kein Produktionsauftrag – Materialbedarf nicht berechenbar.</div>
  <?php elseif (!$ekBedarf): ?>
    <div class="muted">Keine Rohstoffe im Bedarf (Zukauf oder Rezeptur ohne Zutaten).</div>
  <?php else: ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Rohstoff</th><th class="bx-num">benötigt</th><th>Wo bestellbar – EK je Einheit (günstigste zuerst)</th></tr></thead>
      <tbody>
      <?php foreach ($ekBedarf as $bd): $nz = fn($x,$n=3)=>rtrim(rtrim(number_format((float)$x,$n,',','.'),'0'),','); ?>
        <tr>
          <td><a class="kundenlink" href="?p=rohstoff&id=<?= (int)$bd['item_id'] ?>&tab=ek"><?= h($bd['name']) ?></a></td>
          <td class="bx-num"><?= $nz($bd['benoetigt']) ?> <?= h($bd['einheit']) ?><?php if ($bd['fehlt'] > 0.0001): ?><br><span style="color:#8f231b;font-size:12px">fehlt <?= $nz($bd['fehlt']) ?></span><?php else: ?><br><span class="bx-ok" style="font-size:12px">auf Lager</span><?php endif; ?></td>
          <td><?php if (!$bd['angebote']): ?><span class="muted">kein EK-Preis hinterlegt</span> · <a href="?p=rohstoff&id=<?= (int)$bd['item_id'] ?>&tab=ek" style="font-size:12px">anfragen</a>
              <?php else: $bi = 0; foreach ($bd['angebote'] as $ao): ?>
                <div style="<?= $bi === 0 ? 'font-weight:600' : '' ?>"><?= h($ao['firma'] ?: '–') ?>: <?= $nz($ao['preis'], 4) ?> <?= h($ao['waehrung'] ?: 'EUR') ?><?= (float)$ao['menge_ab'] > 0 ? ' <span class="muted">(ab ' . $nz($ao['menge_ab']) . ')</span>' : '' ?><?= $bi === 0 ? ' <span class="muted">· günstigste</span>' : '' ?></div>
              <?php $bi++; endforeach; endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($ekLieferanten): ?>
    <div class="bx-row" style="gap:10px;margin-top:14px;flex-wrap:wrap;align-items:center">
      <span class="muted" style="font-size:13px">Express-Bestellung (überspringt den Einkauf – bestellt die fehlenden Mengen direkt beim Lieferanten):</span>
      <?php foreach ($ekLieferanten as $lid => $firma): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Express-Bestellung anlegen? Bestellt die fehlenden Rohstoffe dieses Auftrags direkt bei diesem Lieferanten.');">
        <input type="hidden" name="aktion" value="express_bestellung"><input type="hidden" name="lieferant_id" value="<?= (int)$lid ?>">
        <button class="btn btn-primary btn-sm" type="submit" data-busy="Bestellt…">Express bei <?= h($firma ?: 'Lieferant') ?></button>
      </form>
      <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:12px;margin:8px 0 0">Legt sofort eine Bestellung (Entwurf) mit den fehlenden Rohstoffen an und öffnet sie – ohne den Umweg über Einkaufsbedarf/-liste. Absenden an den Lieferanten dann wie gewohnt in der Bestellung.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($zukaufPreise || $prodRezId): $nz = fn($x,$n=3)=>rtrim(rtrim(number_format((float)$x,$n,',','.'),'0'),','); ?>
  <h3 style="margin:20px 0 8px;font-size:14px;font-weight:600">Fertigprodukt zukaufen (Bulk)</h3>
  <?php if ($zukaufPreise): $VZ = versandart_liste(); ?>
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr><th>Lieferant</th><th>Größe</th><th class="bx-num">ab Menge</th><th class="bx-num">EK je Einheit</th><th>Lieferbedingung</th></tr></thead>
      <tbody>
      <?php $bi = 0; foreach ($zukaufPreise as $z): $terms = array_filter([(string)$z['incoterm'], $z['versandart'] ? ($VZ[$z['versandart']] ?? $z['versandart']) : '']); ?>
        <tr<?= $bi === 0 ? ' style="font-weight:600"' : '' ?>>
          <td><?= h($z['firma'] ?: '–') ?><?= $bi === 0 ? ' <span class="muted" style="font-weight:normal">· günstigste</span>' : '' ?></td>
          <td><?= $z['groesse'] ? h($z['groesse']) : '<span class="muted">–</span>' ?></td>
          <td class="bx-num"><?= (float)$z['menge_ab'] > 0 ? $nz($z['menge_ab']) : '–' ?></td>
          <td class="bx-num"><?= $nz($z['preis'], 4) ?> <?= h($z['waehrung'] ?: 'EUR') ?><?= $z['einheit'] ? ' / ' . h($z['einheit']) : '' ?></td>
          <td class="muted"><?= $terms ? h(implode(' · ', $terms)) : '–' ?></td>
        </tr>
      <?php $bi++; endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <div class="muted">Noch keine Zukaufpreise für dieses Produkt hinterlegt – per „Fertigprodukt anfragen" bei Lieferanten einholen.</div>
  <?php endif; ?>
  <div class="bx-row" style="gap:10px;margin-top:12px;flex-wrap:wrap;align-items:center">
    <?php if ($prodRezId) echo anfrage_produkt_button($prodRezId, (string)($a['produkt_name'] ?? ''), '', 'Fertigprodukt anfragen'); ?>
    <?php if ($zukaufLief): ?><span class="muted" style="font-size:13px">· Express-Zukauf (Bulk, überspringt den Einkauf):</span>
      <?php foreach ($zukaufLief as $lid => $firma): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Fertigprodukt als Bulk direkt bei diesem Lieferanten bestellen?');">
        <input type="hidden" name="aktion" value="express_bulk"><input type="hidden" name="lieferant_id" value="<?= (int)$lid ?>">
        <button class="btn btn-primary btn-sm" type="submit" data-busy="Bestellt…">Express bei <?= h($firma ?: 'Lieferant') ?></button>
      </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <p class="muted" style="font-size:12px;margin:8px 0 0">Zukauf des fertigen Produkts als Bulk (Kunde sieht das nie). „Anfragen" holt Preise bei Lieferanten ein; „Express" legt direkt eine Bulk-Bestellung an.</p>
  <?php endif; ?>
</div>
<?php anfrage_modal($anfrageLieferanten, '?p=auftrag&id=' . $id); ?>
<?php endif; ?>

<form method="post" class="bx-form">
  <div class="bx-panel"><div class="bx-grid">
    <div class="bx-field"><label>Status</label>
      <select name="status">
        <?php foreach (['offen'=>'offen','in_produktion'=>'in Produktion','erledigt'=>'versandbereit','versendet'=>'versendet'] as $key=>$lbl): ?>
          <option value="<?= $key ?>" <?= $a['status']===$key?'selected':'' ?>><?= $lbl ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="bx-field"><label>Menge (Packungen)</label>
      <input type="number" name="menge" min="0" value="<?= (int)$a['menge'] ?>"></div>
    <div class="bx-field"><label>VK je Packung (netto)</label>
      <input type="text" name="vk_stueck" id="vkFeld" value="<?= h((float)$a['vk_stueck'] > 0 ? rtrim(rtrim(number_format((float)$a['vk_stueck'], 4, ',', ''), '0'), ',') : '') ?>" placeholder="z. B. 0,84"></div>
  </div>
  <div class="muted" style="font-size:12px;margin-top:2px">Netto gesamt = Menge × VK je Packung – wird beim Speichern automatisch berechnet<span id="vkVorschau"></span>.</div>
  </div>
  <button class="btn btn-primary" type="submit" data-busy="Speichert…">Speichern</button>
</form>
<script>
(function(){
  var m = document.querySelector('input[name="menge"]'), v = document.getElementById('vkFeld'), out = document.getElementById('vkVorschau');
  if (!m || !v || !out) return;
  function rechne(){
    var mv = parseFloat((m.value||'').replace(',','.'))||0, vv = parseFloat((v.value||'').replace(/\./g,'').replace(',','.'))||0;
    out.textContent = (mv>0 && vv>0) ? ' → ' + (mv*vv).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' €' : '';
  }
  m.addEventListener('input', rechne); v.addEventListener('input', rechne); rechne();
})();
</script>
<?php render_footer(); ?>
