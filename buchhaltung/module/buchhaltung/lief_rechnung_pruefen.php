<?php
// Eingangsrechnungen zu prüfen (13.11.2026, Anweisung Portale Teil 4). Route: lief_rechnung_pruefen (Rolle finance/admin).
// Entwürfe (lieferant_rechnung.status='entwurf') entstehen automatisch, wenn ein Lieferant im Portal eine Rechnung zur Bestellung hochlädt
// oder das Team sie an der Bestellung einträgt (Dashboard core/eingangsrechnung.php, KI liest aus). Hier: Pop-up mit PDF links und Feldern
// rechts, unsichere Felder gelb, abweichende Bankverbindung rot (nie übernommen), Abgleich mit dem Bestellwert (Hinweis über 2 %).
// „Prüfen und übernehmen“ macht daraus eine offene Eingangsrechnung. Keine Zahlung wird ausgelöst, keine Mail an den Lieferanten.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';
require_once BX_ROOT . '/core/kreditor.php';
kreditor_init();

$u = current_user();
$von = (string)($u['name'] ?? 'Buchhaltung');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $akt = (string)($_POST['aktion'] ?? '');
    if ($akt === 'uebernehmen') {
        $fehler = kr_entwurf_uebernehmen($id, [
            'lief_nummer'       => $_POST['lief_nummer'] ?? '',
            'datum'             => ($_POST['datum'] ?? '') ?: null,
            'waehrung'          => $_POST['waehrung'] ?? 'EUR',
            'fx_kurs'           => $_POST['fx_kurs'] ?? null,
            'netto'             => $_POST['netto'] ?? '0',   // in Rechnungswährung
            'ust_prozent'       => $_POST['ust_prozent'] ?? '0',
            'zahlungsziel_tage' => (int)($_POST['zahlungsziel_tage'] ?? 0),
            'notiz'             => $_POST['notiz'] ?? '',
        ], $von);
        if ($fehler !== '') { header('Location: ?p=lief_rechnung_pruefen&id=' . $id . '&fehler=' . urlencode($fehler)); exit; }
        header('Location: ?p=lief_rechnung&id=' . $id . '&gespeichert=1'); exit;
    }
    if ($akt === 'verwerfen') {
        kr_entwurf_verwerfen($id, (string)($_POST['grund'] ?? ''), $von);
        header('Location: ?p=lief_rechnung_pruefen&verworfen=1'); exit;
    }
}

$liste = kr_entwuerfe();
$eur = fn($x, $w = 'EUR') => number_format((float)$x, 2, ',', '.') . ' ' . ($w === 'EUR' ? '€' : $w);
$fd = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '–';
$offenId = (int)($_GET['id'] ?? 0);

render_header('lief_rechnung_pruefen', 'Eingangsrechnungen zu prüfen');
bx_head('Eingangsrechnungen zu prüfen', 'Rechnungen aus dem Lieferantenportal und vom Team, von der KI ausgelesen. Bitte prüfen und übernehmen – erst dann sind sie offene Posten.');
if (isset($_GET['fehler']))    echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">' . h((string)$_GET['fehler']) . '</div>';
if (isset($_GET['verworfen'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Entwurf verworfen.</div>';
?>
<style>
  .erp-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:18px}
  .erp-pdf{width:100%;height:70vh;border:1px solid var(--line);border-radius:10px;background:#fff}
  .erp-unsicher input,.erp-unsicher select{background:rgba(201,133,43,.16) !important;border-color:#C9852B !important}
  .erp-hinweis{border-radius:10px;padding:10px 12px;margin:0 0 10px;font-size:13.5px;line-height:1.45}
  .erp-rot{background:rgba(192,57,43,.10);border:1px solid rgba(192,57,43,.5);color:var(--err)}
  .erp-gelb{background:rgba(201,133,43,.10);border:1px solid rgba(201,133,43,.5)}
  .erp-gruen{background:rgba(29,158,117,.08);border:1px solid rgba(29,158,117,.4)}
  @media (max-width:860px){.erp-grid{grid-template-columns:1fr}.erp-pdf{height:50vh}}
</style>
<?php if (!$liste): ?>
  <div class="bx-panel"><div class="muted">Keine Eingangsrechnungen zu prüfen.</div></div>
<?php else: ?>
<div class="bx-panel">
  <div class="bx-tablewrap"><table class="bx-table bx-karten-handy">
    <thead><tr><th>Entwurf</th><th>Lieferant</th><th>Bestellung</th><th>Rechnungs-Nr.</th><th>Datum</th><th class="bx-num">Betrag</th><th>Fällig</th><th>Hinweise</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($liste as $r):
        $uns = array_filter(explode(',', (string)$r['ki_unsicher']));
        $abg = kr_bestell_abgleich($r);
        $betrag = $r['waehrung'] === 'EUR' ? $eur($r['brutto']) : $eur($r['fw_netto'], $r['waehrung']) . ' netto';
        $bald = $r['faellig'] && strtotime((string)$r['faellig']) < strtotime('+7 days'); ?>
      <tr>
        <td><?= h((string)$r['nummer']) ?><div class="muted" style="font-size:12px"><?= $r['quelle'] === 'team' ? 'Team' : 'Portal' ?></div></td>
        <td><?= h((string)($r['lieferantennummer'] ?? '')) ?> <span class="muted"><?= h((string)($r['firma'] ?? '')) ?></span></td>
        <td><?= h((string)($r['bestell_nummer'] ?? '–')) ?></td>
        <td><?= h((string)($r['lief_nummer'] ?: '–')) ?></td>
        <td><?= h($fd($r['datum'])) ?></td>
        <td class="bx-num"><?= h($betrag) ?></td>
        <td><?= $r['faellig'] ? ($bald ? bx_badge($fd($r['faellig']), 'err') : h($fd($r['faellig']))) : '–' ?></td>
        <td><?= ($r['bank_warnung'] && str_starts_with((string)$r['bank_warnung'], 'Bankverbindung weicht ab') ? bx_badge('Bank weicht ab', 'err') . ' ' : '')
              . ($uns ? bx_badge(count($uns) . ' unsicher', 'warn') . ' ' : '') . ($abg['ampel'] === 'warn' ? bx_badge('Betrag ≠ Bestellung', 'warn') : '') ?></td>
        <td class="bx-num"><button type="button" class="btn btn-primary btn-sm" onclick="bxPopup.open('erp<?= (int)$r['id'] ?>')">Prüfen</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php foreach ($liste as $r):
    $uns = array_flip(array_filter(explode(',', (string)$r['ki_unsicher'])));
    $cls = fn($f) => isset($uns[$f]) ? ' erp-unsicher' : '';
    $abg = kr_bestell_abgleich($r);
    $fw = $r['waehrung'] === 'EUR' ? (float)$r['netto'] : (float)$r['fw_netto'];
    $bankWarn = (string)($r['bank_warnung'] ?? ''); ?>
<dialog id="erp<?= (int)$r['id'] ?>" class="bx-dialog" style="max-width:1180px"<?= $offenId === (int)$r['id'] ? ' open' : '' ?>>
  <h2><?= h((string)$r['nummer']) ?> · <?= h((string)($r['lieferantennummer'] ?? '')) ?> <?= h((string)($r['firma'] ?? '')) ?> · Bestellung <?= h((string)($r['bestell_nummer'] ?? '–')) ?></h2>
  <div class="erp-grid">
    <div><?php if (!empty($r['datei'])): ?><iframe class="erp-pdf" src="?p=lief_rechnung&id=<?= (int)$r['id'] ?>&datei=1#view=FitH" title="Rechnung"></iframe>
         <div style="margin-top:6px"><a class="btn btn-ghost btn-sm" target="_blank" rel="noopener" href="?p=lief_rechnung&id=<?= (int)$r['id'] ?>&datei=1">In neuem Tab öffnen</a></div>
         <?php else: ?><div class="muted">Keine Datei.</div><?php endif; ?></div>
    <div>
      <?php if ($bankWarn !== ''): ?><div class="erp-hinweis <?= str_starts_with($bankWarn, 'Bankverbindung weicht ab') ? 'erp-rot' : 'erp-gelb' ?>"><?= h($bankWarn) ?></div><?php endif; ?>
      <?php if (!empty($r['bank_re'])): ?><div class="muted" style="font-size:12.5px;margin:-4px 0 10px">Bank laut Rechnung (nur Anzeige, wird nicht übernommen): <?= h((string)$r['bank_re']) ?></div><?php endif; ?>
      <?php if ($abg['text'] !== ''): ?><div class="erp-hinweis <?= $abg['ampel'] === 'warn' ? 'erp-gelb' : ($abg['ampel'] === 'ok' ? 'erp-gruen' : 'erp-gelb') ?>"><?= h($abg['text']) ?></div><?php endif; ?>
      <?php if ($uns): ?><div class="erp-hinweis erp-gelb">Gelb markierte Felder hat die KI nicht sicher gelesen – bitte mit der Rechnung vergleichen.</div><?php endif; ?>
      <form method="post" data-pflicht>
        <input type="hidden" name="aktion" value="uebernehmen"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <div class="bx-grid">
          <div class="bx-field<?= $cls('rechnung_nr') ?>"><label>Rechnungs-Nr. des Lieferanten</label><input type="text" name="lief_nummer" value="<?= h((string)$r['lief_nummer']) ?>" data-pflicht></div>
          <div class="bx-field<?= $cls('datum') ?>"><label>Rechnungsdatum</label><input type="date" name="datum" value="<?= h((string)$r['datum']) ?>"></div>
          <div class="bx-field<?= $cls('waehrung') ?>"><label>Währung</label><select name="waehrung" data-norscombo><?php foreach (kr_waehrungen() as $c => $sym): ?><option value="<?= h($c) ?>"<?= $c === $r['waehrung'] ? ' selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></div>
          <div class="bx-field"><label>Kurs (1 Fremdwährung = x EUR)</label><input type="text" name="fx_kurs" inputmode="decimal" value="<?= $r['waehrung'] === 'EUR' ? '1' : h(number_format((float)($r['fx_kurs'] ?: kr_kurs_cached((string)$r['waehrung'])['wert']), 6, ',', '')) ?>"></div>
          <div class="bx-field<?= $cls('netto') ?>"><label>Netto (in Rechnungswährung)</label><input type="text" name="netto" inputmode="decimal" value="<?= h(number_format($fw, 2, ',', '.')) ?>"></div>
          <div class="bx-field<?= $cls('ust_prozent') ?>"><label>USt %</label><input type="text" name="ust_prozent" inputmode="decimal" value="<?= h(rtrim(rtrim(number_format((float)$r['ust_prozent'], 2, ',', ''), '0'), ',')) ?>"></div>
          <div class="bx-field"><label>Zahlungsziel (Tage)</label><input type="text" name="zahlungsziel_tage" inputmode="numeric" value="<?= h((string)($r['zahlungsziel_tage'] ?? '')) ?>"></div>
          <div class="bx-field"><label>Fällig laut Rechnung</label><input type="text" value="<?= h($fd($r['faellig'])) ?>" disabled></div>
        </div>
        <div class="bx-field" style="margin-top:8px"><label>Notiz</label><input type="text" name="notiz" value="<?= h((string)$r['notiz']) ?>"></div>
        <div class="bx-pop-fuss"><button class="btn btn-primary" type="submit">Prüfen und übernehmen</button></div>
      </form>
      <details style="margin-top:12px"><summary class="muted" style="cursor:pointer">Entwurf verwerfen (z. B. doppelt oder keine Rechnung)</summary>
        <form method="post" class="bx-row" style="gap:8px;margin-top:8px;flex-wrap:wrap"><input type="hidden" name="aktion" value="verwerfen"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="grund" placeholder="Grund" required style="flex:1;min-width:200px"><button class="btn btn-ghost btn-sm" type="submit">Verwerfen</button></form></details>
    </div>
  </div>
</dialog>
<?php endforeach; ?>
<?php endif; ?>
<?php render_footer();
