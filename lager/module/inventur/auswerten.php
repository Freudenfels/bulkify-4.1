<?php
// Inventur auswerten (nur Admin, 11.11.2026): alle Abweichungen, größter Wert zuerst, filterbar nach Art. Je Zeile Ursache
// wählen -> Aktion wird gebucht (Bewegung mit Grund „Inventur …"). Nichts automatisch. Sammel-Knopf für kleine Differenzen.
$inv = inv_get((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$inv) { flash('Inventur nicht gefunden.', 'warn'); weiter('?p=inventur'); }
$id = (int)$inv['id'];
$filter = preg_replace('/[^a-z_]/', '', (string)($_GET['art'] ?? ''));
$zurueck = '?p=inventur_auswerten&id=' . $id . ($filter !== '' ? '&art=' . $filter : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string)($_POST['aktion'] ?? '');
    if ($a === 'entscheiden') { $r = inv_entscheiden($inv, (int)$_POST['diff'], (string)$_POST['ursache'], $_POST); flash($r['msg'], $r['ok'] ? 'ok' : 'warn'); }
    elseif ($a === 'klein') { $r = inv_kleine_uebernehmen($inv, (array)($_POST['ids'] ?? [])); flash($r['msg'], $r['ok'] ? 'ok' : 'warn'); }
    weiter($zurueck . '#d' . (int)($_POST['diff'] ?? 0));
}

$arten = inv_diff_arten(); $U = inv_ursachen();
$alle = all("SELECT d.*, b.name AS bereich FROM lg_inventur_diff d LEFT JOIN lg_inventur_bereich b ON b.id=d.bereich_id WHERE d.inventur_id=? AND d.status<>'passt'", [$id]);
usort($alle, fn($x, $y) => [(int)in_array($x['status'], ['gebucht', 'offen_gelassen'], true), -abs((float)($x['wert_eur'] ?? 0)), $x['wert_eur'] === null] <=> [(int)in_array($y['status'], ['gebucht', 'offen_gelassen'], true), -abs((float)($y['wert_eur'] ?? 0)), $y['wert_eur'] === null]);
$zahl = []; foreach ($alle as $d) $zahl[$d['art']] = ($zahl[$d['art']] ?? 0) + 1;
$rows = $filter !== '' ? array_values(array_filter($alle, fn($d) => $d['art'] === $filter)) : $alle;
$passt = (int) scalar("SELECT COUNT(*) FROM lg_inventur_diff WHERE inventur_id=? AND status='passt'", [$id]);
$klein = inv_kleine_differenzen($inv);
$summe = array_sum(array_map(fn($d) => (float)($d['wert_eur'] ?? 0), array_filter($alle, fn($d) => $d['status'] === 'gebucht')));
$offenSumme = array_sum(array_map(fn($d) => (float)($d['wert_eur'] ?? 0), array_filter($alle, fn($d) => in_array($d['status'], ['offen', 'nachzaehlen'], true))));
$eur = fn($v) => $v === null ? '–' : number_format((float)$v, 2, ',', '.') . ' €';

kopf('Auswerten', 'inventur');
seitenkopf('Auswerten: ' . (string)$inv['name'], inv_umfang_text($inv) . ' · ' . inv_status_label((string)$inv['status']) . '. Nichts wird automatisch gebucht – je Zeile die Ursache wählen.',
    '<a class="btn btn-ghost" href="?p=inventur_lauf&id=' . $id . '">Übersicht</a>');
flash_zeigen();
?>
<style>
  .ia-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
  .ia-tabs a{padding:6px 12px;border:1px solid var(--line);border-radius:999px;font-size:14px;color:var(--text)}
  .ia-tabs a.on{border-color:var(--gruen);box-shadow:inset 0 0 0 1px var(--gruen)}
  .ia-form{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
  .ia-form select,.ia-form input{font-size:14px;padding:6px 8px;min-height:36px}
  .ia-p{display:none} .ia-p.an{display:inline-flex;gap:6px;align-items:center}
  td.ia-n{text-align:right;white-space:nowrap}
</style>
<div class="lg-kacheln">
  <div class="lg-kachel"><div class="lg-kachel-t"><?= count(array_filter($alle, fn($d) => in_array($d['status'], ['offen', 'nachzaehlen'], true))) ?></div><div class="lg-kachel-s">offen (Wert <?= h($eur($offenSumme)) ?>)</div></div>
  <div class="lg-kachel"><div class="lg-kachel-t"><?= count(array_filter($alle, fn($d) => in_array($d['status'], ['gebucht', 'offen_gelassen'], true))) ?></div><div class="lg-kachel-s">entschieden · gebucht <?= h($eur($summe)) ?></div></div>
  <div class="lg-kachel"><div class="lg-kachel-t"><?= $passt ?></div><div class="lg-kachel-s">gefunden, passt</div></div>
</div>
<?php if ($klein): ?>
<form method="post" class="bx-panel" onsubmit="return confirm(<?= h(json_encode(count($klein) . ' kleine Differenzen als Schwund übernehmen (Korrekturbuchung)?' . "\n\n" . implode("\n", array_map(fn($d) => (string)(erp_inv_item((int)$d['item_id'])['name'] ?? '') . ': ' . inv_mz($d['diff']) . ' ' . $d['soll_einheit'] . ' (' . $eur($d['wert_eur']) . ')', array_slice($klein, 0, 40))), JSON_UNESCAPED_UNICODE)) ?>)">
  <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="klein">
  <?php foreach ($klein as $d): ?><input type="hidden" name="ids[]" value="<?= (int)$d['id'] ?>"><?php endforeach; ?>
  <div class="bx-row" style="gap:12px;align-items:center;flex-wrap:wrap"><button class="btn btn-primary" type="submit">Kleine Differenzen übernehmen (<?= count($klein) ?>)</button>
  <span class="muted">Mengenabweichungen unter <?= h(inv_mz($inv['klein_eur'])) ?> € und unter <?= h(inv_mz($inv['klein_prozent'])) ?> % – mit Bestätigungsliste.</span></div>
</form>
<?php endif; ?>
<div class="bx-panel">
  <div class="ia-tabs"><a href="?p=inventur_auswerten&id=<?= $id ?>"<?= $filter === '' ? ' class="on"' : '' ?>>Alle (<?= count($alle) ?>)</a>
  <?php foreach ($arten as $k => $l): if ($k === 'passt' || empty($zahl[$k])) continue; ?><a href="?p=inventur_auswerten&id=<?= $id ?>&art=<?= h($k) ?>"<?= $filter === $k ? ' class="on"' : '' ?>><?= h($l) ?> (<?= (int)$zahl[$k] ?>)</a><?php endforeach; ?></div>
  <?php if (!$rows): ?><p class="muted"><?= (string)$inv['status'] === 'zaehlen' ? 'Abweichungen erscheinen, sobald ein Platz fertig gezählt ist.' : 'Keine Abweichungen.' ?></p><?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Artikel</th><th>Art</th><th class="bx-num">Soll</th><th class="bx-num">Ist</th><th class="bx-num">Differenz</th><th class="bx-num">Wert</th><th>Ort</th><th>Entscheidung</th></tr></thead><tbody>
  <?php foreach ($rows as $d):
      $o = (int)$d['charge_id'] ? erp_inv_objekt((int)$d['gebinde_id'] ? 'g' : 'c', (int)$d['gebinde_id'] ?: (int)$d['charge_id']) : null;
      $it = erp_inv_item((int)$d['item_id']);
      $z = (int)$d['zaehlung_id'] ? one("SELECT * FROM lg_inventur_zaehlung WHERE id=?", [(int)$d['zaehlung_id']]) : null;
      $fertig = in_array($d['status'], ['gebucht', 'offen_gelassen'], true);
      $passend = array_filter($U, fn($u) => in_array($d['art'], $u[2], true)); ?>
    <tr id="d<?= (int)$d['id'] ?>" style="vertical-align:top">
      <td style="white-space:normal;min-width:220px"><?= h((string)($it['name'] ?? '')) ?>
        <div class="muted" style="font-size:12px"><?php if ($o): ?>Charge <?= h((string)($o['charge_nr'] ?: '#' . $o['charge_id'])) ?><?= $o['obj'] === 'g' ? ' · Gebinde ' . (int)$o['g_nr'] . '/' . (int)$o['g_von'] : '' ?><?= !empty($o['fremd_kunde_id']) ? ' · Lager 2' : '' ?><?php endif; ?>
        <?php if ($z && $z['obj'] === 'k'): ?>ohne Etikett · Inv <?= h((string)$z['kf_inventar_nr']) ?> · Charge <?= h((string)($z['kf_charge_nr'] ?: '–')) ?> · MHD <?= $z['kf_mhd'] ? h(date('d.m.Y', strtotime((string)$z['kf_mhd']))) : '–' ?><?php if ($z['kf_foto']): ?> · <a href="?p=bild&f=<?= h(rawurlencode((string)$z['kf_foto'])) ?>" target="_blank" rel="noopener">Foto</a><?php endif; ?><?php endif; ?>
        <?php if ($z): ?> · <?= h(['stk' => 'gezählt', 'voll' => 'voll, original verschlossen', 'wiegen' => 'gewogen', 'kapsel' => 'gewogen (Kapseln)', 'leer' => 'Gebinde leer'][$z['art']] ?? $z['art']) ?><?= $z['art'] === 'kapsel' ? ' ' . inv_mz($z['kapsel_mg']) . ' mg' : '' ?> · <?= h(erp_inv_benutzer_name((int)$z['zaehler_id'])) ?><?php endif; ?>
        <?= $d['notiz'] ? '<br>' . h((string)$d['notiz']) : '' ?></div></td>
      <td><?= h($arten[$d['art']] ?? $d['art']) ?><?= $d['status'] === 'nachzaehlen' ? '<br><span class="badge badge-warn">Nachzählen</span>' : '' ?></td>
      <td class="ia-n"><?= $d['soll'] !== null ? h(inv_mz($d['soll']) . ' ' . $d['soll_einheit']) : '' ?></td>
      <td class="ia-n"><?= $d['ist'] !== null ? h(inv_mz($d['ist']) . ' ' . $d['einheit']) : '' ?></td>
      <td class="ia-n"><?= $d['art'] !== 'falscher_platz' ? h(($d['diff'] > 0 ? '+' : '') . inv_mz($d['diff'])) . ($d['diff_prozent'] !== null ? ' <span class="muted">(' . h(inv_mz($d['diff_prozent'])) . ' %)</span>' : '') : '' ?></td>
      <td class="ia-n"><?= h($eur($d['wert_eur'])) ?></td>
      <td style="white-space:normal"><?= $d['soll_ort'] ? 'gebucht ' . h(inv_ort_name((string)$d['soll_ort'])) : '<span class="muted">ohne Platz</span>' ?><?= $d['ist_ort'] ? '<br>gezählt ' . h(inv_ort_name((string)$d['ist_ort'])) : '' ?></td>
      <td style="white-space:normal;min-width:300px">
      <?php if ($fertig): ?><span class="badge badge-ok"><?= h($U[$d['ursache']][0] ?? $d['ursache']) ?></span><div style="font-size:13px"><?= h((string)$d['ergebnis']) ?></div><div class="muted" style="font-size:12px"><?= h(erp_inv_benutzer_name((int)$d['entschieden_von'])) ?> · <?= h(fmt_zeit((string)$d['entschieden_am'])) ?></div>
      <?php elseif ((string)$inv['status'] === 'abgeschlossen'): ?><span class="muted">–</span>
      <?php else: ?>
        <form method="post" class="ia-form" onsubmit="return confirm('Ursache übernehmen und buchen?')">
          <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="entscheiden"><input type="hidden" name="diff" value="<?= (int)$d['id'] ?>">
          <select name="ursache" class="ia-u" required><option value="">Ursache …</option><?php foreach ($passend as $k => $u): ?><option value="<?= h($k) ?>" title="<?= h($u[1]) ?>"><?= h($u[0]) ?> – <?= h($u[1]) ?></option><?php endforeach; ?></select>
          <?php if (isset($passend['einheit'])):
              $q = (float)$d['ist'] > 0 ? (float)$d['soll'] / (float)$d['ist'] : 0; $vor = $q >= 900 && $q <= 1100 ? '0,001' : ($q > 0 && $q <= 1 / 900 ? '1000' : '1'); ?>
            <span class="ia-p" data-u="einheit">Faktor <input type="text" name="faktor" value="<?= h($vor) ?>" style="width:80px"> Einheit <input type="text" name="einheit" value="<?= h((string)($o['item_einheit'] ?? $d['einheit'])) ?>" style="width:60px"></span>
          <?php endif; ?>
          <?php if (isset($passend['doppelt']) && $o): ?>
            <span class="ia-p" data-u="doppelt">doppelt zu <select name="andere_charge"><option value="">Charge …</option><?php foreach (erp_inv_chargen_gleicher_artikel((int)$o['item_id'], (int)$o['charge_id']) as $c2): ?><option value="<?= (int)$c2['id'] ?>"><?= h(($c2['charge_nr'] ?: '#' . $c2['id']) . ' · ' . inv_mz($c2['menge_verfuegbar']) . ' ' . $c2['einheit']) ?></option><?php endforeach; ?></select></span>
          <?php endif; ?>
          <?php if (isset($passend['dublette']) && $o): ?>
            <span class="ia-p" data-u="dublette">richtiger Artikel <select name="ziel_item" style="max-width:260px"><option value="">Artikel …</option><?php foreach (erp_inv_artikel_gleicher_art((int)$o['item_id']) as $a2): $sp = erp_inv_umbuchen_sperren((int)$o['item_id'], (int)$a2['id']); ?><option value="<?= (int)$a2['id'] ?>"<?= $sp ? ' disabled' : '' ?>><?= h($a2['name'] . ($sp ? ' (gesperrt: ' . implode(', ', $sp) . ')' : '')) ?></option><?php endforeach; ?></select></span>
          <?php endif; ?>
          <input type="text" name="notiz" placeholder="Notiz" style="width:140px">
          <button class="btn btn-primary btn-sm" type="submit">Buchen</button>
        </form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<script>
document.querySelectorAll('.ia-form').forEach(function(f){ var s=f.querySelector('.ia-u'); function z(){ f.querySelectorAll('.ia-p').forEach(function(p){ p.classList.toggle('an', p.dataset.u===s.value); }); } s.addEventListener('change', z); z(); });
</script>
<?php fuss();
