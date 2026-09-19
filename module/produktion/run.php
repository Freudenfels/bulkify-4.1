<?php
// Geführte Produktion – schlanke, fokussierte Mitarbeiter-Ansicht: EIN Schritt nach dem anderen,
// große Bedienelemente (touch/App-tauglich). Nutzt die zentrale Logik produktion_schritt_erledigen().
// Ohne id: Auswahl der offenen/laufenden Aufträge. Mit id: der geführte Ablauf.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);

// Einen Schritt abschließen (zentrale Logik) und zurück zur geführten Ansicht.
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'erledigen') {
    $r = produktion_schritt_erledigen($id, (int)($_POST['schritt'] ?? 0), (string)($_POST['scan'] ?? ''));
    if (!$r['ok'] && $r['fehler'] === 'scan')   { header('Location: ?p=produktion_run&id=' . $id . '&scanfehler=' . urlencode($r['msg'])); exit; }
    if (!$r['ok'] && $r['fehler'] === 'mangel') { header('Location: ?p=produktion_run&id=' . $id . '&mangel=1'); exit; }
    if ($r['ok'] && $r['fertig'])               { header('Location: ?p=produktion_run&id=' . $id . '&fertig=1'); exit; }
    header('Location: ?p=produktion_run&id=' . $id . '&ok=1'); exit;
}

$name_expr = "COALESCE(NULLIF(p.name,''), a.produkt_bezeichnung, CONCAT(rz.name,' · Bulk'))";

// ---------- Auswahl (keine id): Tabelle nach Priorität, dann Datum (FIFO) ----------
if (!$id) {
    $GLOBALS['bx_stock_cache'] = [];   // Bestands-/Bedarfsabfragen für die Machbarkeit request-lokal cachen
    $offene = all("SELECT pa.id, pa.nummer, pa.status, pa.prio, pa.angelegt, pa.auftrag_id,
                          a.nummer AS auftrag_nr, k.firma AS kunde, p.etikett_id AS etikett_id,
                          $name_expr AS produkt,
                          (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id) AS n_total,
                          (SELECT COUNT(*) FROM produktion_schritt s WHERE s.pa_id=pa.id AND s.erledigt=1) AS n_done
                   FROM produktionsauftrag pa
                   LEFT JOIN auftrag a ON a.id=pa.auftrag_id
                   LEFT JOIN produkt p ON p.id=pa.produkt_id
                   LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
                   LEFT JOIN kunden k ON k.id=pa.kunde_id
                   WHERE pa.status IN ('offen','laufend')
                   ORDER BY pa.prio, pa.angelegt");   // Prio (1=Hoch zuerst), dann älteste zuerst = FIFO
    render_header('produktion', 'Geführte Produktion');
    bx_head('Geführte Produktion', 'Nach Priorität und Eingang (FIFO). Grau = noch nicht machbar.',
            bx_btn('Zur Produktionsliste', '?p=produktion', 'ghost'));
    if (!$offene) {
        echo '<div class="bx-panel"><div class="muted">Aktuell kein offener oder laufender Produktionsauftrag.</div></div>';
        render_footer(); return;
    }
    ?>
    <div class="bx-panel" style="padding:0;overflow:hidden">
    <div class="bx-tablewrap"><table class="bx-table">
      <thead><tr>
        <th style="width:70px">Prio</th><th>Nummer</th><th>Produkt / Kunde</th>
        <th style="width:110px">Status</th><th style="width:150px">Fortschritt</th>
        <th style="width:190px">Machbar</th><th style="width:110px">Eingang</th><th style="width:130px"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($offene as $o):
          $done = (int)$o['n_done']; $tot = max(1, (int)$o['n_total']);
          $ber  = produktion_bereitschaft((int)$o['id'])['status'];   // bereit|wartet|laeuft|fertig
          // Etikett-Design: Produkt mit Etikett-Slot braucht die Kundendatei, sonst nicht etikettierbar.
          $etFehlt = !empty($o['etikett_id']) && !empty($o['auftrag_id']) && !etikett_vorhanden((int)$o['auftrag_id']);
          $machbar = ($ber !== 'wartet') && !$etFehlt;
          if ($ber === 'wartet')      { $mLbl = bx_badge('wartet auf Material','warn'); }
          elseif ($etFehlt)           { $mLbl = bx_badge('Etikett-Design fehlt','warn'); }
          elseif ($ber === 'laeuft')  { $mLbl = bx_badge('in Produktion','info'); }
          else                        { $mLbl = bx_badge('produzierbar','ok'); }
          $statusLbl = $done > 0 ? bx_badge('läuft','warn') : bx_badge('offen','info');
          $pct = (int) round($done / $tot * 100);
          $rowStyle = $machbar ? '' : 'opacity:.5';
      ?>
        <tr style="<?= $rowStyle ?><?= $machbar ? ';cursor:pointer' : '' ?>"<?= $machbar ? ' onclick="location.href=\'?p=produktion_run&id=' . (int)$o['id'] . '\'"' : '' ?>>
          <td><?= prio_badge((int)($o['prio'] ?? 2)) ?></td>
          <td><strong><?= h($o['auftrag_nr'] ?: $o['nummer']) ?></strong></td>
          <td><?= h($o['produkt'] ?: '–') ?><?= $o['kunde'] ? ' <span class="muted">· ' . h($o['kunde']) . '</span>' : '' ?></td>
          <td><?= $statusLbl ?></td>
          <td><div style="height:7px;border-radius:5px;background:var(--line);overflow:hidden"><div style="height:100%;width:<?= $pct ?>%;background:var(--gruen)"></div></div><div class="muted" style="font-size:11px;margin-top:3px"><?= $done ?> / <?= $tot ?></div></td>
          <td><?= $mLbl ?></td>
          <td class="muted" style="font-size:12px"><?= h(fmt_zeit($o['angelegt'], 'd.m.Y')) ?></td>
          <td style="text-align:right"><?= $machbar
              ? '<a class="btn btn-primary btn-sm" href="?p=produktion_run&id=' . (int)$o['id'] . '" onclick="event.stopPropagation()">' . ($done > 0 ? 'weiter' : 'starten') . '</a>'
              : '<span class="muted" style="font-size:12px">nicht machbar</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    </div>
    <p class="muted" style="font-size:12px;margin-top:10px">Sortiert nach <strong>Priorität</strong>, dann <strong>Eingangsdatum (FIFO)</strong>. Ausgegraut = <strong>noch nicht machbar</strong> (fehlendes Material – inkl. Leerkapseln/Etiketten – oder fehlendes Etikett-Design). Im Auftrag steht, was fehlt.</p>
    <?php
    unset($GLOBALS['bx_stock_cache']);
    render_footer();
    return;
}

// ---------- Geführter Ablauf (mit id) ----------
$pa = one("SELECT pa.*, a.nummer AS auftrag_nr, k.firma AS kunde,
                  COALESCE(NULLIF(p.name,''), a.produkt_bezeichnung, CONCAT(rz.name,' · Bulk')) AS produkt
           FROM produktionsauftrag pa
           LEFT JOIN auftrag a ON a.id=pa.auftrag_id
           LEFT JOIN produkt p ON p.id=pa.produkt_id
           LEFT JOIN rezeptur rz ON rz.id=pa.rezeptur_id
           LEFT JOIN kunden k ON k.id=pa.kunde_id
           WHERE pa.id=?", [$id]);
if (!$pa) { render_header('produktion','Geführte Produktion'); bx_head('Auftrag nicht gefunden','', bx_btn('Zurück','?p=produktion_run','ghost')); render_footer(); return; }

$schritte  = all("SELECT * FROM produktion_schritt WHERE pa_id=? ORDER BY sort, id", [$id]);
$total     = count($schritte);
$done      = 0; foreach ($schritte as $s) if ((int)$s['erledigt'] === 1) $done++;
$firstOpen = null; foreach ($schritte as $s) if ((int)$s['erledigt'] === 0) { $firstOpen = $s; break; }
$pct       = $total ? (int) round($done / $total * 100) : 0;

render_header('produktion', 'Geführte Produktion');
bx_head('Geführte Produktion · ' . h($pa['auftrag_nr'] ?: $pa['nummer']),
        h($pa['produkt'] ?: '–') . ($pa['kunde'] ? ' · ' . h($pa['kunde']) : ''),
        bx_btn('Auftrag-Details', '?p=produktionsauftrag&id=' . $id, 'ghost'));

if (isset($_GET['ok']))         echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Schritt erledigt – weiter zum nächsten.</div>';
if (isset($_GET['scanfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Scan abgelehnt: ' . h($_GET['scanfehler']) . '</div>';
if (isset($_GET['mangel']))     echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b;padding:12px 16px">Nicht genug Bestand für diesen Schritt – bitte Material bereitstellen (Wareneingang).</div>';

// Fortschrittsbalken
echo '<div class="bx-panel" style="padding:14px 16px">'
   . '<div class="bx-row" style="justify-content:space-between;font-size:13px;color:var(--muted)"><span>Fortschritt</span><span>' . $done . ' / ' . $total . ' Schritten</span></div>'
   . '<div style="height:10px;border-radius:6px;background:var(--line);overflow:hidden;margin-top:8px"><div style="height:100%;width:' . $pct . '%;background:var(--gruen)"></div></div>'
   . '</div>';

if (!$firstOpen):
    // ---------- Abschluss ----------
    $fwGebucht = (float) produktion_gebucht($id);
    $chargen = $pa['auftrag_id'] || pa_ist_bulk($pa)
        ? all("SELECT charge_nr, menge FROM charge WHERE pa_id=? ORDER BY id", [$id]) : [];
?>
  <div class="bx-panel" style="border-color:var(--gruen);background:rgba(29,158,117,.06);text-align:center;padding:32px 20px">
    <div style="font-size:44px;line-height:1;color:var(--gruen)">&#10003;</div>
    <h2 style="margin:12px 0 4px">Produktion abgeschlossen</h2>
    <p class="muted" style="margin:0 0 6px"><?= h($pa['produkt'] ?: '–') ?> · <?= number_format((float)$pa['menge'],0,',','.') ?> <?= pa_ist_bulk($pa) ? 'Stück' : 'Packungen' ?></p>
    <?php if ($chargen): ?>
      <p style="margin:0">Fertigware eingebucht:
        <?php foreach ($chargen as $c): ?><strong><?= h($c['charge_nr']) ?></strong> (<?= number_format((float)$c['menge'],0,',','.') ?>) <?php endforeach; ?>
      </p>
    <?php endif; ?>
    <div class="bx-row" style="justify-content:center;gap:10px;margin-top:18px">
      <a class="btn btn-primary" href="?p=produktion_run">Nächsten Auftrag produzieren</a>
      <a class="btn btn-ghost" href="?p=produktionsauftrag&id=<?= $id ?>">Details ansehen</a>
    </div>
  </div>
<?php
else:
    // ---------- Aktueller Schritt ----------
    $anl    = station_anleitung($firstOpen['station']);
    $isGate = str_contains($firstOpen['station'], 'Freigabe');
?>
  <div class="bx-panel" style="border-color:var(--gruen);background:rgba(29,158,117,.05);padding:24px 20px">
    <div class="muted">Schritt <?= $done + 1 ?> von <?= $total ?></div>
    <h2 style="margin:6px 0 10px;font-size:26px"><?= h($firstOpen['station']) ?> <?= $isGate ? bx_badge('Freigabe','info') : '' ?></h2>
    <p style="margin:0 0 18px;font-size:16px;max-width:640px"><?= h($anl['text']) ?></p>
    <form method="post" class="bx-row" style="gap:12px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="aktion" value="erledigen">
      <input type="hidden" name="schritt" value="<?= (int)$firstOpen['id'] ?>">
      <?php if ($anl['scan']): ?>
        <div class="bx-field" style="margin:0;max-width:340px;flex:1 1 260px">
          <label>Charge scannen oder eingeben</label>
          <input type="text" name="scan" autofocus autocomplete="off" placeholder="Charge-Nr. scannen …" style="font-size:18px;padding:14px 14px">
        </div>
      <?php endif; ?>
      <button class="btn btn-primary" type="submit" style="font-size:18px;padding:14px 30px">
        <?= $isGate ? 'Freigeben' : ($anl['scan'] ? 'Scannen &amp; erledigen' : 'Erledigt') ?>
      </button>
    </form>
  </div>
<?php endif; ?>

<div class="bx-panel">
  <h2 style="margin-top:0">Alle Schritte</h2>
  <div class="bx-tablewrap"><table class="bx-table"><tbody>
    <?php foreach ($schritte as $s):
        $isDone = (int)$s['erledigt'] === 1;
        $isNext = $firstOpen && (int)$s['id'] === (int)$firstOpen['id'];
    ?>
      <tr<?= $isNext ? ' style="background:var(--panel-2)"' : '' ?>>
        <td style="width:44px;text-align:center;font-size:18px"><?= $isDone ? '<span class="bx-ok">&#10003;</span>' : ($isNext ? '&#9654;' : '<span class="muted">&#9675;</span>') ?></td>
        <td><strong<?= $isDone || $isNext ? '' : ' class="muted"' ?>><?= h($s['station']) ?></strong></td>
        <td class="muted" style="text-align:right"><?= $isDone && $s['erledigt_at'] ? h(fmt_zeit($s['erledigt_at'])) : ($isNext ? 'jetzt dran' : 'wartet') ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php render_footer(); ?>
