<?php
// Eine Inventur (11.11.2026): Fortschritt (Plätze gezählt x von y, gescannt, Klärfälle), Bereiche, Knöpfe.
// Zähler sehen nur ihren Fortschritt (keine Soll-Mengen). Admin: Zählung beenden, auswerten, abschließen, Protokoll.
$inv = inv_get((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$inv) { flash('Inventur nicht gefunden.', 'warn'); weiter('?p=inventur'); }
$id = (int)$inv['id'];
$admin = lg_ist_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin) {
    $a = (string)($_POST['aktion'] ?? '');
    if ($a === 'beenden' && function_exists('inv_zaehlung_beenden')) { $r = inv_zaehlung_beenden($inv); flash($r['msg'], $r['ok'] ? 'ok' : 'warn'); }
    elseif ($a === 'weiterzaehlen') { q("UPDATE lg_inventur SET status='zaehlen' WHERE id=? AND status='auswerten'", [$id]); flash('Zählung wieder geöffnet.'); }
    elseif ($a === 'bereich_oeffnen') { q("UPDATE lg_inventur_bereich SET status='laeuft', fertig_am=NULL WHERE id=? AND inventur_id=?", [(int)$_POST['bereich'], $id]); flash('Platz zum Weiterzählen geöffnet.'); }
    elseif ($a === 'abschliessen' && function_exists('inv_abschliessen')) { $r = inv_abschliessen($inv); flash($r['msg'], $r['ok'] ? 'ok' : 'warn'); }
    weiter('?p=inventur_lauf&id=' . $id);
}

$f = inv_fortschritt($inv);
$bereiche = inv_bereiche($id);
$zaehlerNamen = array_filter(array_map(fn($u) => erp_inv_benutzer_name((int)$u), explode(',', (string)$inv['zaehler'])));
$knoepfe = '<a class="btn btn-ghost" href="?p=inventur">Alle Inventuren</a>';
if (inv_darf_zaehlen($inv) && $inv['status'] === 'zaehlen') $knoepfe .= ' <a class="btn btn-primary" href="?p=inventur_zaehlen&id=' . $id . '">Zählen</a>';
if ($admin && $inv['status'] !== 'zaehlen' || $admin && $f['fertig'] > 0) $knoepfe .= ' <a class="btn btn-ghost" href="?p=inventur_auswerten&id=' . $id . '">Auswerten</a>';
if ($admin && !empty($inv['protokoll_pdf'])) $knoepfe .= ' <a class="btn btn-ghost" href="?p=inventur_protokoll&id=' . $id . '&f=pdf">Protokoll (PDF)</a> <a class="btn btn-ghost" href="?p=inventur_protokoll&id=' . $id . '&f=csv">CSV</a>';

kopf((string)$inv['name'], 'inventur');
seitenkopf((string)$inv['name'], 'Stichtag ' . date('d.m.Y', strtotime((string)$inv['stichtag'])) . ' · ' . inv_umfang_text($inv) . ' · ' . inv_status_label((string)$inv['status']), $knoepfe);
flash_zeigen();
?>
<div class="lg-kacheln">
  <div class="lg-kachel"><div class="lg-kachel-t"><?= $f['fertig'] ?> von <?= $f['gesamt'] ?></div><div class="lg-kachel-s">Plätze/Kisten gezählt</div></div>
  <div class="lg-kachel"><div class="lg-kachel-t"><?= $f['gescannt'] ?></div><div class="lg-kachel-s">Gebinde gescannt</div></div>
  <div class="lg-kachel"><div class="lg-kachel-t"><?= $f['klaerfaelle'] ?></div><div class="lg-kachel-s">Klärfälle offen (ohne Etikett)</div></div>
  <?php if ($admin): ?><div class="lg-kachel"><div class="lg-kachel-t"><?= $f['offen'] ?></div><div class="lg-kachel-s">Abweichungen offen<?= $f['nachzaehlen'] ? ', davon ' . $f['nachzaehlen'] . ' Nachzählen' : '' ?></div></div><?php endif; ?>
</div>
<div class="bx-panel">
  <h2>Plätze und Kisten</h2>
  <p class="muted" style="margin-top:0">Zähler: <?= $zaehlerNamen ? h(implode(', ', $zaehlerNamen)) : 'alle aus dem Lager' ?><?= $inv['nachzaehl_prozent'] !== null ? ' · Nachzählen durch zweite Person ab ' . h(inv_mz($inv['nachzaehl_prozent'])) . ' %' : '' ?>.</p>
  <?php if (!$bereiche): ?><p class="muted"><?= (int)$inv['ganz'] ? 'Noch kein Platz gezählt. Beim Zählen den Platz scannen – er kommt dann hier dazu.' : 'Keine Plätze.' ?></p>
  <?php else: ?>
  <div class="bx-tablewrap"><table class="bx-table"><thead><tr><th>Platz / Kiste</th><th>Stand</th><th class="bx-num">gescannt</th><th>Gezählt von</th><th></th></tr></thead><tbody>
  <?php foreach ($bereiche as $b): $n = (int) scalar("SELECT COUNT(*) FROM lg_inventur_zaehlung WHERE bereich_id=? AND ersetzt=0", [(int)$b['id']]); ?>
    <tr><td class="lg-code"><?= h((string)$b['name']) ?></td>
      <td><?= $b['status'] === 'fertig' ? '<span class="badge badge-ok">gezählt</span>' : ($b['status'] === 'laeuft' ? '<span class="badge badge-warn">Zählung läuft</span>' : '<span class="badge">offen</span>') ?></td>
      <td class="bx-num"><?= $n ?></td>
      <td><?= h(erp_inv_benutzer_name((int)($b['fertig_von'] ?: $b['gestartet_von']))) ?><?= $b['fertig_am'] ? ' <span class="muted">' . h(fmt_zeit((string)$b['fertig_am'])) . '</span>' : '' ?></td>
      <td><?php if ($admin && $b['status'] === 'fertig' && $inv['status'] === 'zaehlen'): ?>
        <form method="post" style="margin:0" onsubmit="return confirm('Diesen Platz wieder zum Zählen öffnen?')"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="bereich_oeffnen"><input type="hidden" name="bereich" value="<?= (int)$b['id'] ?>"><button class="btn btn-ghost btn-sm">wieder öffnen</button></form>
      <?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php if ($admin && $inv['status'] !== 'abgeschlossen'): ?>
<div class="bx-panel">
  <h2>Abschluss</h2>
  <div class="bx-row" style="gap:12px;flex-wrap:wrap;align-items:center">
  <?php if ($inv['status'] === 'zaehlen'): ?>
    <form method="post" style="margin:0" onsubmit="return confirm('Zählung beenden? Was im Umfang gebucht ist, aber nirgends gescannt wurde, wird als „nicht gefunden“ gelistet.')"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="beenden"><button class="btn btn-primary">Zählung beenden</button></form>
    <span class="muted">Danach: Abweichungen auswerten und entscheiden, dann abschließen.</span>
  <?php else: ?>
    <form method="post" style="margin:0" onsubmit="return confirm('Inventur abschließen? Geht nur, wenn alle Abweichungen entschieden sind. Danach werden die Lagerplätze aller gezählten Gebinde gesetzt und das Protokoll abgelegt.')"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="abschliessen"><button class="btn btn-primary">Inventur abschließen</button></form>
    <form method="post" style="margin:0"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="aktion" value="weiterzaehlen"><button class="btn btn-ghost">Zählung wieder öffnen</button></form>
  <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php fuss();
