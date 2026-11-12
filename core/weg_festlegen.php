<?php
// Weg festlegen – EINE Oberfläche (Einkauf gerade ziehen, Teil 6, 10.11.2026). Die Karten des Reiters Beschaffungsweg am Auftrag
// (Haben wir schon im Lager · Zukauf fertig · Premix · Einzelrohstoffe) als Funktion: der Reiter und das Pop-up „Weg festlegen“
// (Bestellen-Seite, Beschaffungsweg offen, später Zu erledigen) zeigen dieselbe Ausgabe. Formulare posten an den Auftrag;
// $zurueck führt danach zurück auf die aufrufende Seite.

// Karten für einen Auftrag. $zurueck = interner Rücksprung nach dem Speichern (leer = Reiter am Auftrag); $popup = Links in neuem Tab.
function beschaffungsweg_karten(int $auftrag_id, string $zurueck = '', bool $popup = false): string {
    $pa = auftrag_aktiver_pa($auftrag_id);
    if (!$pa) return '';
    $bw = auftrag_beschaffungswege($auftrag_id); $bwWahl = $bw['gewaehlt']; $bwAdmin = has_role('admin');
    $bwAuftrag = $auftrag_id;
    $bwAct = '?p=auftrag&id=' . $auftrag_id;
    $bwZur = preg_match('/^\?p=[a-z_]+(&[A-Za-z0-9_=%.-]*)*$/', $zurueck) ? '<input type="hidden" name="zurueck" value="' . h($zurueck) . '">' : '';
    $bwTgt = $popup ? ' target="_blank" rel="noopener"' : '';
    $bwN = fn($x, $d = 0) => number_format((float)$x, $d, ',', '.');
    $bwM = fn($x, $e) => in_array($e, ['Stk'], true) ? $bwN(round((float)$x)) . ' Stk' : rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',') . ' ' . $e;
    $bwE = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
    $bwLink = fn(array $r) => !empty($r['item_id']) ? '<a href="?p=rohstoff&id=' . (int)$r['item_id'] . '&tab=ek" title="Rohstoff öffnen (Preise, Preis anfragen)"' . $bwTgt . '>' . h((string)$r['name']) . '</a>'
        : (!empty($r['link']) ? '<a href="' . h((string)$r['link']) . '"' . $bwTgt . '>' . h((string)$r['name']) . '</a>' . (!empty($r['hinweis']) ? ' <span class="muted" style="font-size:12px">(' . h((string)$r['hinweis']) . ')</span>' : '') : h((string)$r['name']));
    $bwPreis = fn($x) => preis_zahl($x) . ' €';   // zentrale Preis-Anzeige (13.11.2026)
    $bwKost = [];
    foreach ($bw['wege'] as $k => $w) {
        if ($k === 'rohstoffe') { if (!$w['luecke'] && $w['gesamt'] > 0) $bwKost[$k] = $w['gesamt']; }   // abgelaufen: Summe zählt, steht aber als „unsicher" da
        elseif (!empty($w['angebote'])) $bwKost[$k] = $w['angebote'][0]['gesamt'] + (($w['zusatz']['gesamt'] ?? 0) ?: 0);
    }
    $bwBest = $bwKost ? array_search(min($bwKost), $bwKost, true) : '';
    $dk = $bw['deckung'] ?? []; $dkE = (string)($dk['einheit'] ?? 'Stk');
    $dkM = fn($x) => $dkE === 'Stk' ? $bwM($x, 'Stk') : $bwM((float)$x / 1000, $dkE === 'g' ? 'kg' : 'L');
    ob_start(); ?>
  <?php if (!$bw['rezeptur_id']): ?><div class="muted">Zum Produkt gibt es keine Rezeptur – kein Beschaffungsweg nötig.</div>
  <?php else: if (!empty($dk['ok'])):
      $dkFrei = $bwAdmin && (int)$dk['schritte_erledigt'] === 0;
      $gedeckt = $dk['rest'] <= 0.0001; ?>
  <?php // Der Stand „bereits zugeordnet / frei / unterwegs / Rest“ steht in der Karte „Haben wir schon im Lager“ (Teil 6, 10.11.2026). ?>
  <?php if ($gedeckt): ?>
  <div class="badge-ok" style="padding:10px 14px;border-radius:8px;margin-bottom:14px">Vollständig über Lager und Bestellung gedeckt – kein weiterer Bedarf, keine neue Bestellung nötig.</div>
  <?php endif; ?>
  <?php if ($dk['summe_gekoppelt'] > 0 && $dk['pa_status'] === 'vorbereitung' && $dkFrei): $dkWeg = in_array('premix', array_column($dk['gekoppelt'], 'art'), true) && !in_array('bulk', array_column($dk['gekoppelt'], 'art'), true) ? 'premix' : 'kapsel'; ?>
  <form method="post" action="<?= h($bwAct) ?>" class="bx-panel" style="border-left:3px solid var(--warn);padding:10px 14px;margin:0 0 14px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="aktion" value="deckung_festlegen"><?= $bwZur ?><input type="hidden" name="weg" value="<?= $dkWeg ?>">
    <span>Ware ist zugeordnet, der Beschaffungsweg steht aber noch offen.</span>
    <button class="btn btn-primary btn-sm" type="submit">Weg festlegen: <?= h(beschaffungswege()[$bwWahl !== '' ? $bwWahl : $dkWeg]) ?></button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px">
  <?php // Teil 6 (10.11.2026): vierte Karte „Haben wir schon im Lager“ – Stand der Zuordnung + passende freie Ware, Zuordnen im Reiter Material.
        $lq = auftrag_lagerware_quellen($bwAuftrag); $lqCh = array_slice((array)($lq['chargen'] ?? []), 0, 4); $lqWeitere = count((array)($lq['weitere'] ?? [])); ?>
    <div style="border:1px solid var(--line);border-radius:10px;padding:14px 16px">
      <div class="bx-row" style="justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px">
        <div style="font-weight:600">Haben wir schon im Lager</div>
        <div><?= (!empty($dk['ok']) && $dk['rest'] <= 0.0001) ? bx_badge('gedeckt', 'ok') : '' ?></div>
      </div>
      <?php if (!empty($dk['ok'])): ?>
      <div class="muted" style="font-size:12px;margin-bottom:8px">Bereits zugeordnet: <span style="color:var(--text)"><?= h($dkM($dk['summe_gekoppelt'])) ?></span> · im Lager frei <?= h($dkM($dk['summe_lager'])) ?> · unterwegs <?= h($dkM($dk['summe_bestellt'])) ?> · Rest <span style="color:var(--text)"><?= h($dkM($dk['rest'])) ?></span></div>
      <?php endif; ?>
      <?php if ($lqCh): ?>
        <table class="bx-table" style="font-size:13px"><tbody>
        <?php foreach ($lqCh as $lc): ?>
          <tr><td style="white-space:normal"><?= h((string)($lc['charge_nr'] ?: '#' . $lc['id'])) ?><?= $lc['kapselgroesse'] !== '' ? ' <span class="muted" style="font-size:11px">' . h((string)$lc['kapselgroesse']) . '</span>' : '' ?><?= $lc['passt'] === false ? ' <span style="color:#8f231b;font-size:11px">andere Kapselgröße</span>' : '' ?></td>
              <td class="bx-num"><?= h($bwM((float)$lc['menge_verfuegbar'], (string)$lc['einheit'])) ?></td>
              <td class="muted" style="font-size:12px"><?= !empty($lc['mhd']) ? 'MHD ' . h(date('d.m.Y', strtotime((string)$lc['mhd']))) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php else: ?><div class="muted" style="font-size:13px">Keine freie passende Ware (Bulk/Premix dieser Rezeptur) im Lager.</div><?php endif; ?>
      <?php if ($lqWeitere): ?><div class="muted" style="font-size:12px;margin-top:6px"><?= (int)$lqWeitere ?> weitere Charge(n) unter anderem Namen – Suche über den ganzen Bestand im Reiter Material.</div><?php endif; ?>
      <div style="margin-top:12px"><a class="btn btn-ghost btn-sm" href="?p=auftrag&id=<?= (int)$bwAuftrag ?>&tab=material"<?= $bwTgt ?>>Im Lager suchen und zuordnen</a></div>
    </div>
  <?php foreach ($bw['wege'] as $k => $w): $ist = $bwWahl === $k; ?>
    <div style="border:1px solid <?= $ist ? 'var(--gruen,#1D9E75)' : 'var(--line)' ?>;border-radius:10px;padding:14px 16px;<?= $ist ? 'box-shadow:0 0 0 1px var(--gruen,#1D9E75) inset;' : '' ?>">
      <div class="bx-row" style="justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px">
        <div style="font-weight:600"><?= h($w['label']) ?></div>
        <div><?= $ist ? bx_badge('festgelegt', 'ok') : '' ?> <?= $bwBest === $k ? bx_badge('günstigster', 'info') : '' ?></div>
      </div>
      <?php $bwVoll = (float)($w['menge_voll'] ?? $w['menge']); $bwTeil = $bwVoll - (float)$w['menge'] > 0.0005; ?>
      <div class="muted" style="font-size:12px;margin-bottom:8px">Bedarf für diesen Auftrag: <span style="color:var(--text)"><?= h($bwM($bwVoll, $w['einheit'])) ?></span><?php if ($bwTeil): ?> · noch zu beschaffen (Rest): <span style="color:var(--text)"><?= h($bwM($w['menge'], $w['einheit'])) ?></span><?php endif; ?></div>
      <?php if ($k === 'rohstoffe'): ?>
        <table class="bx-table" style="font-size:13px"><tbody>
        <?php foreach ($w['positionen'] as $ps): ?>
          <tr><td style="white-space:normal"><?= $bwLink($ps) ?></td>
              <td class="bx-num"><?php if ($bwTeil && $ps['voll'] !== null): ?><?= h($bwM($ps['voll'], $ps['einheit'])) ?><div class="muted" style="font-size:11px">Rest <?= h($bwM($ps['menge'], $ps['einheit'])) ?></div><?php else: ?><?= h($bwM($ps['menge'], $ps['einheit'])) ?><?php endif; ?></td>
              <td class="bx-num"><?= $ps['gesamt'] !== null ? h($bwE($ps['gesamt'])) : '<span class="muted">kein Preis</span>' ?><?php if (!empty($ps['mindest'])): ?><div class="muted" style="font-size:11px">Mindestmenge <?= h($bwM($ps['mindest'], $ps['bezug'] ?? $ps['einheit'])) ?></div><?php endif; ?><?php if (!empty($ps['abgelaufen'])): ?><div style="font-size:11px;color:#8f231b">Preis abgelaufen seit <?= h(date('d.m.Y', strtotime((string)$ps['abgelaufen']))) ?> - <a href="?p=rohstoff&id=<?= (int)$ps['item_id'] ?>&tab=ek" target="_blank" rel="noopener">neu anfragen</a></div><?php elseif (!empty($ps['gueltig_bis']) && function_exists('pg_anzeige')): ?><div><?= pg_anzeige($ps['gueltig_bis']) ?></div><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <div style="margin-top:8px"><?= $w['gesamt'] > 0 ? 'Summe ' . h($bwE($w['gesamt'])) . ($bwTeil ? ' <span class="muted" style="font-size:12px">für den Rest</span>' : '') : '' ?><?= $w['luecke'] ? ' <span class="muted" style="font-size:12px">(nicht vollständig: Preise fehlen)</span>' : '' ?><?= !empty($w['unsicher']) ? ' <span style="font-size:12px;color:#8f231b">(unsicher: Preis abgelaufen)</span>' : '' ?></div>
        <div class="muted" style="font-size:12px;margin-top:4px">Beschaffung über die Bedarfsliste (meist mehrere Lieferanten).</div>
      <?php else: ?>
        <?php if (!$w['angebote']): ?>
          <div class="muted">Kein Lieferantenpreis. <a href="?p=rezeptur_detail&id=<?= (int)$bw['rezeptur_id'] ?>&<?= $k === 'premix' ? 'premix_anfrage' : 'fertig_anfrage' ?>=1"<?= $bwTgt ?>><?= $k === 'premix' ? 'Premix anfragen' : 'Angebot anfragen' ?></a></div>
        <?php else: ?>
          <table class="bx-table" style="font-size:13px"><tbody>
          <?php foreach ($w['angebote'] as $ag): ?>
            <tr><td style="white-space:normal"><?= h($ag['lieferant']) ?><?php if (is_file(BX_ROOT . '/core/lieferant_unterlagen.php')) { require_once BX_ROOT . '/core/lieferant_unterlagen.php'; echo lu_badge((int)($ag['lieferant_id'] ?? 0), $k === 'premix' ? rezeptur_premixitem((int)$bw['rezeptur_id'], false) : 0, $k === 'premix' ? 0 : (int)$bw['rezeptur_id']); } ?></td><td class="bx-num"><?= h($bwPreis($ag['preis'])) ?>/<?= h($w['einheit']) ?></td><td class="bx-num"><?= h($bwE($ag['gesamt'])) ?></td>
              <td style="text-align:right"><?php if ($ist && $bwAdmin && $w['menge'] > 0.0001): ?>
                <form method="post" action="<?= h($bwAct) ?>" style="margin:0" onsubmit="return confirm('Bestellung bei <?= h(addslashes($ag['lieferant'])) ?> als Entwurf anlegen (<?= h($bwM($w['menge'], $w['einheit'])) ?>)?')">
                  <input type="hidden" name="aktion" value="beschaffung_bestellen"><?= $bwZur ?><input type="hidden" name="weg" value="<?= h($k) ?>"><input type="hidden" name="lieferant_id" value="<?= (int)$ag['lieferant_id'] ?>">
                  <button class="btn btn-primary btn-sm" type="submit" title="Als Entwurf anlegen – auf der Bestellseite über das Portal senden">Bestellen</button>
                  <button class="btn btn-ghost btn-sm" type="submit" name="modus" value="direkt" title="Schon per Telefon/Mail bestellt: sofort bestellt + bestätigt, keine Mail">Direkt bestellt</button></form>
              <?php endif; ?></td></tr>
          <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
        <?php if (!empty($w['zusatz'])): $zs = $w['zusatz']; ?>
          <div class="muted" style="font-size:12px;margin-top:6px">dazu <?= $bwLink($zs) ?> <?= h($bwM($zs['menge'], $zs['einheit'])) ?>: <?= $zs['gesamt'] !== null ? h($bwE($zs['gesamt'])) : 'kein Preis' ?></div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="bx-row" style="gap:8px;margin-top:12px;flex-wrap:wrap">
        <?php if ($bwAdmin && !$ist && $bw['schritte_erledigt'] === 0): ?>
          <form method="post" action="<?= h($bwAct) ?>" style="margin:0"><input type="hidden" name="aktion" value="beschaffungsweg_setzen"><?= $bwZur ?><input type="hidden" name="weg" value="<?= h($k) ?>">
            <button class="btn <?= $bwWahl === '' ? 'btn-primary' : 'btn-ghost' ?> btn-sm" type="submit">Diesen Weg festlegen</button></form>
        <?php endif; ?>
        <?php if ($ist && $bwAdmin && !(!empty($dk['ok']) && $dk['rest'] <= 0.0001)): ?>
          <?php if (empty($bw['pa']['bedarf_gemeldet'])): ?>
            <form method="post" action="<?= h($bwAct) ?>" style="margin:0"><input type="hidden" name="aktion" value="beschaffung_bedarf"><?= $bwZur ?>
              <button class="btn btn-ghost btn-sm" type="submit">An Bedarfsliste senden</button></form>
          <?php else: ?><span class="muted" style="font-size:12px">an die Bedarfsliste gemeldet am <?= h(fmt_zeit((string)$bw['pa']['bedarf_gemeldet'], 'd.m.Y')) ?> · <a href="?p=einkaufsliste"<?= $bwTgt ?>>zur Bedarfsliste</a></span><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?php if ($bw['schritte_erledigt'] > 0): ?><div class="muted" style="font-size:12px;margin-top:10px">Die Produktion hat begonnen – der Weg lässt sich nicht mehr ändern.</div><?php endif; ?>
  <?php if (($bw['pa']['status'] ?? '') === 'vorbereitung' && $bwAdmin): ?><div style="margin-top:10px;font-size:12px"><a href="?p=produktion_vorbereitung&id=<?= (int)$bw['pa']['id'] ?>&voll=1"<?= $bwTgt ?>>Auf Quellen aufteilen (Lager / Fremd / Eigen)</a> <span class="muted">– Mischung aus Bestand, Zukauf und Eigenproduktion</span></div><?php endif; ?>
  <?php endif; ?>
<?php return ob_get_clean();
}

// Kopf + Rezeptur des Pop-ups „Weg festlegen“: Auftrag, Angebot, Kunde, Produkt (neuer Tab), Menge, Kapselgröße, Glas,
// letzte Festlegung desselben Produkts; Rezeptur mit mg je Einheit, kg für den Auftrag, frei, unterwegs, Rest; Leerkapseln in Stk.
function weg_festlegen_kopf(int $auftrag_id): string {
    $a = one("SELECT a.id, a.nummer, a.angebot_id, a.kunde_id, a.produkt_id, a.menge, a.verpackung_id, COALESCE(a.rezeptur_id, p.rezeptur_id) AS rid,
                     COALESCE(NULLIF(a.produkt_bezeichnung,''), p.name) AS produkt, k.firma, ang.nummer AS angebot_nr, p.verpackung_id AS p_verp
              FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id LEFT JOIN angebot ang ON ang.id=a.angebot_id
              WHERE a.id=?", [$auftrag_id]);
    if (!$a) return '';
    $pa = auftrag_aktiver_pa($auftrag_id);
    $rid = (int)$a['rid'];
    $rz = $rid ? one("SELECT r.id, r.nummer, r.name, r.darreichungsform, r.einheit_fuellmenge, kg.name AS kg_name FROM rezeptur r LEFT JOIN kapselgroesse kg ON kg.id=r.kapselgroesse_id WHERE r.id=?", [$rid]) : null;
    $glas = (int)($a['verpackung_id'] ?: $a['p_verp']);
    $glasName = $glas ? (string) scalar("SELECT name FROM item WHERE id=?", [$glas]) : '';
    $t = ' target="_blank" rel="noopener"';
    $L = fn(string $href, string $txt) => '<a href="' . h($href) . '"' . $t . '>' . h($txt) . '</a>';
    $n0 = fn($x) => number_format((float)$x, 0, ',', '.');
    $m3 = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');
    $stkJe = $pa ? (float) produktion_stueck_je_packung($pa) : 0.0;
    $schuett = $rz && form_groessen_einheit((string)$rz['darreichungsform']) !== '';
    $h = '<div class="bx-row" style="gap:18px;flex-wrap:wrap;align-items:baseline;margin-bottom:10px">'
       . '<div><span class="muted" style="font-size:12px">Auftrag</span><br>' . $L('?p=auftrag&id=' . (int)$a['id'], (string)$a['nummer']) . '</div>'
       . '<div><span class="muted" style="font-size:12px">Angebot</span><br>' . (!empty($a['angebot_id']) ? $L('?p=angebot&id=' . (int)$a['angebot_id'], (string)($a['angebot_nr'] ?: '#' . $a['angebot_id'])) : '<span class="muted">–</span>') . '</div>'
       . '<div><span class="muted" style="font-size:12px">Kunde</span><br>' . (!empty($a['kunde_id']) ? $L('?p=kunde&id=' . (int)$a['kunde_id'], (string)$a['firma']) : '<span class="muted">–</span>') . '</div>'
       . '<div style="flex:1 1 260px;min-width:0"><span class="muted" style="font-size:12px">Produkt</span><br>' . (!empty($a['produkt_id']) ? $L('?p=produkt&id=' . (int)$a['produkt_id'], (string)$a['produkt']) : h((string)$a['produkt'])) . '</div>'
       . '</div>';
    $info = [];
    if ($pa) $info[] = 'Menge <span style="color:var(--text)">' . $n0($pa['menge']) . ' Pkg</span>' . ($stkJe > 0 ? ' à ' . $m3($stkJe) . ($schuett ? ' g' : ' Stk') . ' = ' . $n0((float)$pa['menge'] * $stkJe) . ($schuett ? ' g' : ' Stk') : '');
    if ($rz && (string)$rz['kg_name'] !== '') $info[] = 'Kapselgröße ' . h((string)$rz['kg_name']) . ((float)$rz['einheit_fuellmenge'] > 0 && !$schuett ? ' · ' . $m3($rz['einheit_fuellmenge']) . ' mg Füllung' : '');
    if ($glasName !== '') $info[] = 'Glas ' . $L('?p=rohstoff&id=' . $glas, $glasName);
    $letzte = $a['produkt_id'] ? produktionsart_letzte((int)$a['produkt_id'], (int)$a['kunde_id'], $pa ? (int)$pa['id'] : 0) : null;
    if ($letzte) {
        $lw = (string) scalar("SELECT COALESCE(beschaffungsweg,'') FROM produktionsauftrag WHERE produkt_id=? AND art_festgelegt_am=? LIMIT 1", [(int)$a['produkt_id'], (string)$letzte['am']]);
        $info[] = 'Letzte Festlegung: <span style="color:var(--text)">' . h((string)(beschaffungswege()[$lw] ?? ($letzte['art'] === 'fremd' ? 'Fremdproduktion' : 'Eigenproduktion'))) . '</span>'
                . ($letzte['auftrag_nr'] ? ' · ' . h((string)$letzte['auftrag_nr']) : '') . ($letzte['am'] ? ' · ' . h(fmt_zeit((string)$letzte['am'], 'd.m.Y')) : '')
                . ((int)$letzte['kunde_id'] === (int)$a['kunde_id'] ? ' · selber Kunde' : '');
    } else $info[] = 'Letzte Festlegung: – (erstmalig)';
    $h .= '<div class="muted" style="font-size:13px;margin-bottom:14px;line-height:1.7">' . implode(' · ', $info) . '</div>';
    // Rezeptur für diesen Auftrag (voller Bedarf je Rohstoff, frei, unterwegs, Rest – dieselbe Netto-Rechnung wie Bestellliste/Material)
    if ($rz) {
        $bw = auftrag_beschaffungswege($auftrag_id);
        $mgJe = [];
        foreach (all("SELECT z.item_id, SUM(z.menge_mg) AS mg FROM rezeptur_zutat z WHERE z.rezeptur_id=? GROUP BY z.item_id", [$rid]) as $z) $mgJe[(int)$z['item_id']] = (float)$z['mg'];
        $h .= '<div class="bx-row" style="justify-content:space-between;align-items:baseline;margin:4px 0 6px"><h3 style="margin:0;font-size:15px">Rezeptur ' . h((string)($rz['nummer'] ?: '')) . ' · ' . h((string)$rz['name']) . '</h3>'
            . $L('?p=rezeptur_detail&id=' . $rid, 'Rezeptur öffnen') . '</div>';
        $h .= '<div class="bx-tablewrap"><table class="bx-table" style="font-size:13px"><thead><tr><th>Rohstoff</th><th class="bx-num">' . ($schuett ? 'mg je Portion' : 'mg je Einheit') . '</th><th class="bx-num">für den Auftrag</th><th class="bx-num">frei</th><th class="bx-num">unterwegs</th><th class="bx-num">Rest</th></tr></thead><tbody>';
        foreach (($bw['wege']['rohstoffe']['positionen'] ?? []) as $ps) {
            $iid = (int)$ps['item_id']; $e = (string)$ps['einheit'];
            $voll = (float)($ps['voll'] ?? $ps['menge']);
            $md = $iid > 0 ? material_deckung($iid, $voll, $auftrag_id) : ['frei' => 0, 'quarantaene' => 0, 'unterwegs' => 0, 'rest' => $voll];
            $fm = fn($x) => $e === 'Stk' ? $n0($x) . ' Stk' : ($e === 'g' ? $m3((float)$x / 1000) . ' kg' : $m3($x) . ' ' . h($e));
            $h .= '<tr><td style="white-space:normal">' . ($iid > 0 ? $L('?p=rohstoff&id=' . $iid, (string)$ps['name']) : h((string)$ps['name']) . (!empty($ps['hinweis']) ? ' <span class="muted" style="font-size:11px">(' . h((string)$ps['hinweis']) . ')</span>' : '')) . '</td>'
                . '<td class="bx-num">' . (isset($mgJe[$iid]) ? $m3($mgJe[$iid]) . ' mg' : ($e === 'Stk' ? '1 Stk' : '<span class="muted">–</span>')) . '</td>'
                . '<td class="bx-num">' . $fm($voll) . '</td><td class="bx-num">' . $fm((float)$md['frei'] + (float)$md['quarantaene']) . '</td><td class="bx-num">' . $fm($md['unterwegs']) . '</td>'
                . '<td class="bx-num">' . ((float)$md['rest'] > 0.0005 ? '<span style="color:#8f231b">' . $fm($md['rest']) . '</span>' : '<span class="muted">–</span>') . '</td></tr>';
        }
        $h .= '</tbody></table></div>';
    }
    return $h;
}

// Pop-up-Gerüst (einmal je Seite einbinden): Knopf ruft bxWegFestlegen(auftragId) auf, der Inhalt kommt von ?p=weg_festlegen.
function weg_festlegen_dialog(): string {
    return '<dialog id="dlgWegFestlegen" class="bx-dialog" style="max-width:1180px;width:96vw">'
         . '<div class="bx-row" style="justify-content:space-between;align-items:center;margin-bottom:10px"><h2 style="margin:0;font-size:17px">Weg festlegen</h2>'
         . '<button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById(\'dlgWegFestlegen\').close()">Schließen</button></div>'
         . '<div id="dlgWegInhalt"><div class="muted">Lade …</div></div></dialog>'
         . '<script>function bxWegFestlegen(aid){ var d=document.getElementById("dlgWegFestlegen"), c=document.getElementById("dlgWegInhalt");'
         . ' c.innerHTML=\'<div class="muted">Lade …</div>\'; d.showModal();'
         . ' fetch("?p=weg_festlegen&auftrag="+encodeURIComponent(aid)+"&zurueck="+encodeURIComponent(location.search.replace(/[?&](bwok|bwfehler)=[^&]*/g,"")))'
         . '.then(function(r){ return r.text(); }).then(function(t){ c.innerHTML=t; })'
         . '.catch(function(){ c.innerHTML=\'<div style="color:#8f231b">Konnte nicht geladen werden.</div>\'; }); }</script>';
}
