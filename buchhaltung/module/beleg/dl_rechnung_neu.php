<?php
// Dienstleistungs-Rechnung (DR-) aus DL-Auftrag: VORSCHAU, dann verbindlich erstellen. Erst „Verbindlich
// erstellen" zieht die DR-Nummer (lückenlos, keine verbrannten Nummern). Route: dl_rechnung_neu (finance).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';   // dl_rechnung_aus_auftrag(), beleg_summen_aus_positionen()
require_once BX_ROOT . '/core/erp.php';      // erp_auftrag, erp_dl_positionen, erp_kunde

$auftragId = (int)($_GET['auftrag'] ?? ($_POST['auftrag'] ?? 0));
$a = $auftragId ? erp_auftrag($auftragId) : null;
$istDL = $a && ($a['kategorie'] ?? '') === 'dienstleistung';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'erstellen' && $istDL) {
    $u = current_user();
    $bid = dl_rechnung_aus_auftrag($auftragId, [
        'datum'             => $_POST['datum'] ?? null,
        'zahlungsziel_tage' => $_POST['zahlungsziel_tage'] ?? '',
        'text'              => $_POST['text'] ?? '',
        'freigeben'         => !empty($_POST['freigeben']),
        'ersteller'         => $u['name'] ?? 'team',
    ]);
    if ($bid) { header('Location: ?p=rechnung&id=' . $bid . '&erstellt=1'); exit; }
}

render_header('rechnungen', 'DL-Rechnung erstellen');
if (!$istDL) {
    bx_head('DL-Rechnung erstellen', '', bx_btn('Zurück', '?p=rechnungen', 'ghost'));
    echo '<div class="bx-panel"><p class="muted" style="margin:0">Kein Dienstleistungs-Auftrag gefunden (Parameter ?auftrag=DB-Auftrag-ID, Kategorie „dienstleistung").</p></div>';
    render_footer(); exit;
}

$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
// Schon eine nicht-stornierte Rechnung zum Auftrag?
$vorhanden = (int) scalar("SELECT id FROM beleg WHERE auftrag_id=? AND typ='rechnung' AND status<>'storniert' ORDER BY id LIMIT 1", [$auftragId]);
$kunde = !empty($a['kunde_id']) ? erp_kunde((int)$a['kunde_id']) : null;

// Positionen (1:1) + Summen für die Vorschau
$quell = erp_dl_positionen((int)($a['angebot_id'] ?? 0));
$pos = [];
foreach ($quell as $p) $pos[] = ['bezeichnung'=>(string)$p['bezeichnung'],'beschreibung'=>(string)($p['beschreibung'] ?? ''),'menge'=>(float)$p['menge'],'einheit'=>(string)($p['einheit'] ?? ''),'artikelnr'=>(string)($p['artikelnr'] ?? ''),'preis_cent'=>(int)$p['preis_cent'],'mwst_satz'=>(float)$p['mwst_satz']];
$s = beleg_summen_aus_positionen($pos);
$ustP = 0.0; foreach ($pos as $p) if ((float)$p['mwst_satz'] > 0) { $ustP = (float)$p['mwst_satz']; break; }
$zielDefault = (int) meta_get('zahlungsziel_tage', '14');

bx_head('DL-Rechnung erstellen · ' . h((string)$a['nummer']), 'Vorschau – die DR-Nummer wird erst bei „Verbindlich erstellen" vergeben',
        bx_btn('Zurück', '?p=rechnungen', 'ghost'));
if ($vorhanden) echo '<div class="bx-panel" style="padding:12px 16px;border-color:#e6c4c0">Zu diesem Auftrag gibt es bereits eine Rechnung – <a href="?p=rechnung&id=' . $vorhanden . '">öffnen</a>. „Verbindlich erstellen" gibt diese zurück (keine Doppel-Rechnung).</div>';
?>
<div class="bx-panel">
  <div class="bx-grid">
    <div><div class="k muted">Kunde</div><div><?= $kunde ? kunde_link((int)$a['kunde_id'], $kunde['firma']) : '<span class="muted">–</span>' ?></div></div>
    <div><div class="k muted">DL-Auftrag</div><div><a href="/?p=auftrag&id=<?= (int)$a['id'] ?>" target="_blank"><?= h((string)$a['nummer']) ?></a></div></div>
    <div><div class="k muted">Netto</div><div><?= $eur($s['netto']) ?></div></div>
    <div><div class="k muted">USt (<?= number_format($ustP,0) ?> %)</div><div><?= $eur($s['ust']) ?></div></div>
    <div><div class="k muted">Brutto</div><div><strong><?= $eur($s['brutto']) ?></strong></div></div>
  </div>
</div>

<div class="bx-panel">
  <h2>Positionen</h2>
  <?php if (!$pos): ?><p class="muted" style="margin:0">Das verknüpfte Angebot hat keine Positionen – bitte im Dienstleistungen-Modul prüfen.</p><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table">
    <thead><tr><th>Pos.</th><th>Artikel-Nr.</th><th>Bezeichnung</th><th class="bx-num">Menge</th><th>Einheit</th><th class="bx-num">Einzelpreis</th><th class="bx-num">USt</th><th class="bx-num">Gesamt</th></tr></thead>
    <tbody>
      <?php $i=0; foreach ($pos as $p): $ep=$p['preis_cent']/100; $ge=$ep*$p['menge']; $i++; ?>
      <tr><td><?= $i ?></td><td><?= h($p['artikelnr'] ?: '–') ?></td>
        <td><?= h($p['bezeichnung']) ?><?php if ($p['beschreibung']): ?><div class="muted" style="font-size:12px;white-space:pre-line"><?= h($p['beschreibung']) ?></div><?php endif; ?></td>
        <td class="bx-num"><?= rtrim(rtrim(number_format($p['menge'],2,',','.'),'0'),',') ?></td><td><?= h($p['einheit']) ?></td>
        <td class="bx-num"><?= $eur($ep) ?></td><td class="bx-num"><?= number_format($p['mwst_satz'],0) ?> %</td><td class="bx-num"><?= $eur($ge) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="7" class="bx-num">Netto</td><td class="bx-num"><?= $eur($s['netto']) ?></td></tr>
      <tr><td colspan="7" class="bx-num">zzgl. USt</td><td class="bx-num"><?= $eur($s['ust']) ?></td></tr>
      <tr><td colspan="7" class="bx-num"><strong>Brutto</strong></td><td class="bx-num"><strong><?= $eur($s['brutto']) ?></strong></td></tr>
    </tfoot>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($pos): ?>
<form method="post" class="bx-form bx-panel" style="max-width:620px">
  <input type="hidden" name="aktion" value="erstellen"><input type="hidden" name="auftrag" value="<?= (int)$a['id'] ?>">
  <div class="bx-row">
    <label>Rechnungsdatum<input type="date" name="datum" value="<?= date('Y-m-d') ?>"></label>
    <label>Zahlungsziel (Tage)<input type="number" name="zahlungsziel_tage" value="<?= $zielDefault ?>" min="0"></label>
  </div>
  <label>Rechnungstext / Hinweis<textarea name="text" rows="2" placeholder="optional"></textarea></label>
  <label class="bx-check" style="display:flex;align-items:center;gap:8px;margin-top:6px"><input type="checkbox" name="freigeben" value="1"> Direkt für den Kunden freigeben (im Portal sichtbar)</label>
  <p class="muted" style="margin:4px 0 0">„Verbindlich erstellen" vergibt die DR-Nummer und legt den Beleg an. Danach geht es zur Rechnungs-Detailseite (bearbeiten/stornieren/PDF/E-Rechnung).</p>
  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $vorhanden ? 'Bestehende Rechnung öffnen' : 'Verbindlich erstellen' ?></button>
    <a class="btn btn-ghost" href="?p=rechnungen">Abbrechen</a>
  </div>
</form>
<?php endif; ?>
<?php
render_footer();
