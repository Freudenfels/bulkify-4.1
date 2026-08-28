<?php
// Portal-Anfrage – Detail & Bearbeitung. Kernaktion: aus einer Produktanfrage ein Angebot abgeben (Preise zurück).
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id = (int)($_GET['id'] ?? 0);
$TYP = ['produkt'=>'Produktanfrage', 'rohstoff'=>'Rohstoffanfrage', 'dienstleistung'=>'Dienstleistungsanfrage'];
$VTYPEN = ['glas'=>'Glas', 'pet'=>'PET-Dose', 'pla'=>'PLA-Becher', 'beutel'=>'Standbodenbeutel', 'stick'=>'Stick', 'blister'=>'Blister'];

// Status setzen
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'status') {
    $st = in_array($_POST['status'] ?? '', ['neu','in_bearbeitung','beantwortet','abgelehnt'], true) ? $_POST['status'] : 'neu';
    q("UPDATE portal_anfrage SET status=? WHERE id=?", [$st, $id]);
    header('Location: ?p=portal_anfrage&id=' . $id . '&ok=1'); exit;
}
// Rohstoff einer Rohstoffanfrage zuordnen (für die Preisberechnung)
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'rohstoff_zuordnen') {
    $rid = ($_POST['rohstoff_id'] ?? '') !== '' ? (int)$_POST['rohstoff_id'] : null;
    if ($rid && !one("SELECT id FROM item WHERE id=? AND kategorie='rohstoff'", [$rid])) $rid = null;
    q("UPDATE portal_anfrage SET rohstoff_id=? WHERE id=?", [$rid, $id]);
    header('Location: ?p=portal_anfrage&id=' . $id . '&ok=1'); exit;
}
// Angebot abgeben (Preise zurück) – aus einer Produktanfrage
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'angebot_abgeben') {
    $pa = one("SELECT * FROM portal_anfrage WHERE id=?", [$id]);
    if ($pa && $pa['typ'] === 'produkt' && $pa['produkt_id'] && $pa['kunde_id']) {
        if ((int) scalar("SELECT COUNT(*) FROM produkt_preis WHERE produkt_id=?", [(int)$pa['produkt_id']]) === 0)
            produkt_matrix_generieren((int)$pa['produkt_id']);   // Preismatrix sicherstellen
        $marge = trim($_POST['marge'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['marge']) : null;
        $pz    = trim($_POST['produktionszeit'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['produktionszeit']) : null;
        $notiz = trim($_POST['notiz'] ?? '');
        $notizFull = 'Aus Anfrage ' . $pa['nummer'] . ($notiz !== '' ? ' — ' . $notiz : '');
        q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,notiz,marge_override,produktionszeit_wochen,anfrage_id) VALUES (?,?,?,?,?,?,?,?)",
          [naechste_nummer('AN'), (int)$pa['kunde_id'], (int)$pa['produkt_id'], 'gesendet', $notizFull, $marge, $pz, $id]);
        $angid = insert_id();
        q("UPDATE portal_anfrage SET status='beantwortet' WHERE id=?", [$id]);
        log_aktivitaet('kunde', (int)$pa['kunde_id'], 'team', 'Angebot ' . scalar("SELECT nummer FROM angebot WHERE id=?", [$angid]) . ' zur Anfrage ' . $pa['nummer'] . ' abgegeben.', 'angebot', 'angebot', $angid);
        header('Location: ?p=portal_anfrage&id=' . $id . '&angebot=' . $angid); exit;
    }
    header('Location: ?p=portal_anfrage&id=' . $id); exit;
}
// Im Angebots-Editor bauen: verknüpftes Angebot anlegen (oder vorhandenes öffnen) und in den Editor springen.
// Funktioniert AUCH ohne berechnete Preismatrix (Positionen dort manuell möglich).
if ($id && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aktion'] ?? '') === 'angebot_bauen') {
    $pa = one("SELECT * FROM portal_anfrage WHERE id=?", [$id]);
    if ($pa && $pa['typ'] === 'produkt' && $pa['produkt_id'] && $pa['kunde_id']) {
        $vorhanden = (int) scalar("SELECT id FROM angebot WHERE anfrage_id=? ORDER BY id DESC LIMIT 1", [$id]);
        if ($vorhanden) { header('Location: ?p=angebot&id=' . $vorhanden); exit; }   // nicht doppelt anlegen
        if ((int) scalar("SELECT COUNT(*) FROM produkt_preis WHERE produkt_id=?", [(int)$pa['produkt_id']]) === 0)
            produkt_matrix_generieren((int)$pa['produkt_id']);   // Matrix versuchen (leer ist ok – Positionen manuell)
        q("INSERT INTO angebot (nummer,kunde_id,produkt_id,status,notiz,anfrage_id) VALUES (?,?,?,?,?,?)",
          [naechste_nummer('AN'), (int)$pa['kunde_id'], (int)$pa['produkt_id'], 'offen', 'Aus Anfrage ' . $pa['nummer'], $id]);
        $angid = insert_id();
        q("UPDATE portal_anfrage SET status='in_bearbeitung' WHERE id=?", [$id]);
        log_aktivitaet('kunde', (int)$pa['kunde_id'], 'team', 'Angebot ' . scalar("SELECT nummer FROM angebot WHERE id=?", [$angid]) . ' aus Anfrage ' . $pa['nummer'] . ' im Editor angelegt.', 'angebot', 'angebot', $angid);
        header('Location: ?p=angebot&id=' . $angid); exit;
    }
    header('Location: ?p=portal_anfrage&id=' . $id); exit;
}

$pa = $id ? one("SELECT pa.*, k.firma, p.name AS produkt_name, r.darreichungsform,
                        ri.name AS rohstoff_name, ri.preis_bezug AS rohstoff_bezug
                 FROM portal_anfrage pa
                 LEFT JOIN kunden k ON k.id=pa.kunde_id
                 LEFT JOIN produkt p ON p.id=pa.produkt_id
                 LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                 LEFT JOIN item ri ON ri.id=pa.rohstoff_id
                 WHERE pa.id=?", [$id]) : null;
if (!$pa) { render_header('portal_anfragen','Anfrage'); bx_head('Anfrage nicht gefunden','', bx_btn('Zurück','?p=portal_anfragen','ghost')); render_footer(); exit; }

$mg = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', '.'), '0'), ',');
$stBadge = fn($s) => match ($s) { 'neu'=>bx_badge('neu','info'),'in_bearbeitung'=>bx_badge('in Bearbeitung','warn'),'beantwortet'=>bx_badge('Angebot abgegeben','ok'),'abgelehnt'=>bx_badge('abgelehnt','err'),default=>bx_badge($s) };
// Bereits abgegebene Angebote zu dieser Anfrage (Verknüpfung über Notiz-Präfix "Aus Anfrage <Nr>")
$angebote = $pa['typ'] === 'produkt' ? all("SELECT id, nummer, status, marge_override FROM angebot WHERE notiz LIKE ? ORDER BY id DESC", ['Aus Anfrage ' . $pa['nummer'] . '%']) : [];

render_header('portal_anfragen', $pa['nummer']);
bx_head($pa['nummer'], $TYP[$pa['typ']] ?? $pa['typ'], bx_btn('Zurück zur Liste', '?p=portal_anfragen', 'ghost'));
if (isset($_GET['ok'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if (isset($_GET['angebot'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Angebot abgegeben – der Kunde sieht jetzt die Preise im Portal.</div>';
?>
<div class="bx-cards">
  <div class="bx-card"><div class="k">Kunde</div><div class="v" style="font-size:16px"><?php if ($pa['kunde_id']): ?><a href="?p=kunde&id=<?= (int)$pa['kunde_id'] ?>"><?= h($pa['firma'] ?: '–') ?></a><?php else: ?>–<?php endif; ?></div></div>
  <div class="bx-card"><div class="k">Typ</div><div class="v" style="font-size:16px"><?= h($TYP[$pa['typ']] ?? $pa['typ']) ?></div></div>
  <div class="bx-card"><div class="k">Status</div><div class="v"><?= $stBadge($pa['status']) ?></div></div>
  <div class="bx-card"><div class="k">Eingegangen</div><div class="v" style="font-size:15px"><?= h(fmt_zeit($pa['angelegt'])) ?></div></div>
</div>

<div class="bx-panel">
  <h2>Wunsch des Kunden</h2>
  <div class="bx-tablewrap"><table class="bx-table"><tbody>
    <?php if ($pa['typ'] === 'produkt'): ?>
      <tr><td style="width:220px">Produkt</td><td><?php if ($pa['produkt_id']): ?><a href="?p=produkt&id=<?= (int)$pa['produkt_id'] ?>"><?= h($pa['produkt_name'] ?: '–') ?></a><?php else: ?><?= h($pa['produkt_name'] ?: '–') ?><?php endif; ?></td></tr>
      <tr><td>Größe je Packung</td><td><?= $pa['fuellmenge_g'] ? $mg($pa['fuellmenge_g']) . ' g' : ($pa['stueck'] ? (int)$pa['stueck'] . ' Stück' : '–') ?></td></tr>
      <tr><td>Verpackungstyp</td><td><?= h($pa['verpackung_typ'] ? ($VTYPEN[$pa['verpackung_typ']] ?? $pa['verpackung_typ']) : '– (bitte empfehlen)') ?></td></tr>
      <tr><td>Anzahl Packungen</td><td><?= $pa['menge'] ? number_format((int)$pa['menge'], 0, ',', '.') : '–' ?></td></tr>
    <?php else: ?>
      <tr><td style="width:220px">Betreff</td><td><?= h($pa['betreff'] ?: '–') ?></td></tr>
      <?php if ($pa['wunsch_menge']): ?><tr><td>Gewünschte Menge</td><td><?= $mg($pa['wunsch_menge']) . ' ' . h($pa['wunsch_einheit'] ?: '') ?></td></tr><?php endif; ?>
    <?php endif; ?>
    <?php if ($pa['notiz']): ?><tr><td>Notiz</td><td><?= h($pa['notiz']) ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<?php if ($pa['typ'] === 'rohstoff'):
    $rid   = (int)($pa['rohstoff_id'] ?? 0);
    $menge = (float)($pa['wunsch_menge'] ?? 0);
    $bezug = $pa['rohstoff_bezug'] ?: ($pa['wunsch_einheit'] ?: 'Einheit');
    $rohliste = all("SELECT id, name, artikelnummer FROM item WHERE kategorie='rohstoff' AND gesperrt=0 ORDER BY name");
    $prz = fn($x) => $x === null ? '–' : number_format((float)$x, ((float)$x < 1 ? 4 : 2), ',', '.') . ' EUR';
?>
<div class="bx-panel">
  <h2>Rohstoff-Preis berechnen</h2>
  <p class="muted" style="margin-top:0">Verkaufspreis = günstigster Lieferanten-EK (gestaffelt) × (1 + Aufschlag), danach Kundenrabatt. Denselben Rechenweg wie bei Produkt-Angeboten.</p>
  <form method="post" class="bx-row" style="align-items:flex-end;gap:10px;margin-bottom:6px">
    <input type="hidden" name="aktion" value="rohstoff_zuordnen">
    <div class="bx-field" style="margin:0;min-width:320px"><label>Rohstoff (für die Berechnung) <?= bx_hint('Aus dem Katalog vorbelegt; hier korrigierbar, falls der Kunde nur Text geschickt hat.') ?></label>
      <select name="rohstoff_id" onchange="this.form.submit()">
        <option value="">– kein Rohstoff zugeordnet –</option>
        <?php foreach ($rohliste as $ri): ?>
          <option value="<?= (int)$ri['id'] ?>" <?= $rid===(int)$ri['id']?'selected':'' ?>><?= h($ri['name']) ?><?= $ri['artikelnummer'] ? ' · '.h($ri['artikelnummer']) : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-ghost btn-sm" type="submit">Zuordnen</button></noscript>
  </form>
  <?php if ($rid):
      $auf   = rohstoff_aufschlag_prozent($rid);
      $refM  = $menge > 0 ? $menge : 1;
      $ekR   = rohstoff_ek_bei_menge($rid, $refM);
      $vkR   = rohstoff_vk_bei_menge($rid, $refM);
      $vkK   = $vkR !== null ? vk_fuer_kunde($vkR, (int)$pa['kunde_id']) : null;
      // Staffel: vorhandene Lieferanten-Mengenstufen + Wunschmenge
      $tiers = array_map('floatval', array_column(all("SELECT DISTINCT menge_ab FROM lieferant_preis WHERE item_id=? AND (waehrung IS NULL OR waehrung='EUR') AND menge_ab>0 ORDER BY menge_ab", [$rid]), 'menge_ab'));
      if ($menge > 0 && !in_array($menge, $tiers, true)) { $tiers[] = $menge; sort($tiers); }
      if (!$tiers) $tiers = [$refM];
  ?>
    <?php if ($ekR === null): ?>
      <div class="bx-panel badge-warn" style="padding:12px 16px;margin-top:8px">Für diesen Rohstoff ist kein EK hinterlegt (weder Lieferantenpreis noch Stamm-EK). Bitte im <a href="?p=rohstoff&id=<?= $rid ?>">Rohstoff</a> einen EK erfassen.</div>
    <?php else: ?>
      <div class="bx-cards" style="margin-top:8px">
        <div class="bx-card"><div class="k">EK je <?= h($bezug) ?><?= $menge>0?' (bei '.$mg($menge).' '.h($bezug).')':'' ?></div><div class="v" style="font-size:16px"><?= $prz($ekR) ?></div></div>
        <div class="bx-card"><div class="k">Aufschlag</div><div class="v" style="font-size:16px"><?= rtrim(rtrim(number_format($auf,2,',','.'),'0'),',') ?> %</div></div>
        <div class="bx-card"><div class="k">VK je <?= h($bezug) ?></div><div class="v" style="font-size:16px"><?= $prz($vkR) ?></div></div>
        <div class="bx-card" style="border-color:var(--gruen)"><div class="k">VK für Kunde je <?= h($bezug) ?></div><div class="v" style="font-size:16px"><?= $prz($vkK) ?></div></div>
      </div>
      <?php if ($menge > 0 && $vkK !== null): ?>
        <div style="margin-top:8px;font-size:15px">Gesamt für <strong><?= $mg($menge) ?> <?= h($bezug) ?></strong>: <strong><?= number_format($vkK*$menge,2,',','.') ?> EUR</strong> <span class="muted">netto</span></div>
      <?php endif; ?>
      <div class="bx-tablewrap" style="margin-top:12px"><table class="bx-table">
        <thead><tr><th>ab Menge (<?= h($bezug) ?>)</th><th class="bx-num">EK je <?= h($bezug) ?></th><th class="bx-num">VK je <?= h($bezug) ?></th><th class="bx-num">VK Kunde je <?= h($bezug) ?></th></tr></thead>
        <tbody>
          <?php foreach ($tiers as $t):
              $ekT = rohstoff_ek_bei_menge($rid, $t); $vkT = rohstoff_vk_bei_menge($rid, $t);
              $vkKT = $vkT !== null ? vk_fuer_kunde($vkT, (int)$pa['kunde_id']) : null;
              $ist = ($menge > 0 && abs($t-$menge) < 0.0005);
          ?>
            <tr<?= $ist?' style="outline:2px solid var(--gruen);outline-offset:-2px"':'' ?>>
              <td><?= $mg($t) ?><?= $ist?' <span class="muted">(angefragt)</span>':'' ?></td>
              <td class="bx-num"><?= $prz($ekT) ?></td>
              <td class="bx-num"><?= $prz($vkT) ?></td>
              <td class="bx-num"><?= $prz($vkKT) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="muted" style="margin-top:8px;font-size:12px">Preise je Bezugseinheit des Rohstoffs. Fremdwährungs-Lieferantenpreise werden noch nicht umgerechnet.</p>
    <?php endif; ?>
  <?php else: ?>
    <div class="muted">Ordne oben einen Rohstoff zu, dann rechnet das System EK, Aufschlag und VK aus.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($pa['typ'] === 'produkt' && $pa['produkt_id']):
    $pid = (int)$pa['produkt_id'];
    if ((int) scalar("SELECT COUNT(*) FROM produkt_preis WHERE produkt_id=?", [$pid]) === 0) produkt_matrix_generieren($pid);
    $form     = $pa['darreichungsform'] ?: 'kapsel';
    $istPulver= in_array($form, ['pulver','granulat','stick'], true);
    $defMarge = max(marge_typ_prozent($form), marge_min_prozent());
    $defPz    = (float) meta_get('produktionszeit_wochen', 7);
    $vorschau = ($_POST['aktion'] ?? '') === 'angebot_vorschau';
    $vMarge   = $vorschau && trim($_POST['marge'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['marge']) : $defMarge;
    $vPz      = $vorschau && trim($_POST['produktionszeit'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['produktionszeit']) : $defPz;
    $vNotiz   = $vorschau ? trim($_POST['notiz'] ?? '') : '';
    $rabatt   = (float) scalar("SELECT rabatt_marge FROM kunden WHERE id=?", [(int)$pa['kunde_id']]);
    $stueckA  = std_groessen_fuer($form); $mengenA = std_bestellmengen();
    // Vorschau-Zellen aus produkt_preis (EK) mit gewählter Marge + Kundenrabatt
    $prev = [];
    foreach (all("SELECT stueck,bestellmenge,ek_preis FROM produkt_preis WHERE produkt_id=? ORDER BY ek_preis ASC", [$pid]) as $r) {
        $s=(int)$r['stueck']; $bm=(int)$r['bestellmenge'];
        if (!isset($prev[$s][$bm])) $prev[$s][$bm] = vk_fuer_kunde((float)$r['ek_preis'] * (1 + $vMarge/100), (int)$pa['kunde_id']);
    }
    $formPl = ['kapsel'=>'Kapseln','tablette'=>'Tabletten','softgel'=>'Softgels','stick'=>'Sticks','pulver'=>'g','granulat'=>'g','fluessig'=>'ml'][$form] ?? 'Stück';
    $formLbl = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','granulat'=>'Granulat','fluessig'=>'Flüssig'][$form] ?? $form;
    $eur = fn($x)=>number_format((float)$x,2,',','.').' €';
    // Rechnet die Preis-Engine diese Darreichungsform? (Kapsel/Softgel/Pulver/Granulat/Stick ja; Tablette/Flüssig noch nicht)
    $engineForm = in_array($form, ['kapsel','softgel','pulver','granulat','stick'], true);
    $hatPreise = false; foreach ($prev as $zr) { foreach ($zr as $vv) if ($vv !== null) { $hatPreise = true; break 2; } }
    // Info-Text, wenn (noch) keine automatische Preisberechnung möglich ist.
    $preisInfo = $hatPreise ? '' : (!$engineForm
        ? 'Für die Darreichungsform <strong>' . h($formLbl) . '</strong> gibt es noch keine automatische Preisberechnung. Bitte das Angebot manuell kalkulieren bzw. den Preis auf Anfrage angeben.'
        : 'Für <strong>' . h($formLbl) . '</strong> wurde keine passende Verpackung/Füllmenge gefunden. '
          . '<a href="?p=einstellungen&tab=produktion">Behälter-Fassung / Füllgewichte einstellen</a>'
          . ' oder je Behälter im <a href="?p=verpackungen">Verpackungen</a>-Reiter „Füllmengen".');
?>
<div class="bx-panel">
  <h2>Angebot abgeben</h2>
  <?php if ($angebote): ?>
    <p class="muted" style="margin-top:0">Bereits abgegeben:</p>
    <?php foreach ($angebote as $ag): ?>
      <div class="bx-row" style="gap:10px;align-items:center;margin-bottom:6px"><a href="?p=angebot&id=<?= (int)$ag['id'] ?>"><?= h($ag['nummer']) ?></a> <?= bx_badge($ag['status']) ?><?php if (($ag['marge_override'] ?? '')!=='' && $ag['marge_override']!==null): ?> <span class="muted">Marge <?= rtrim(rtrim(number_format((float)$ag['marge_override'],2,',','.'),'0'),',') ?> %</span><?php endif; ?></div>
    <?php endforeach; ?>
    <div class="muted" style="margin-top:8px">Der Kunde sieht die Preismatrix im Portal und wählt eine Menge.</div>
  <?php else: ?>
    <p class="muted" style="margin-top:0">Du gibst keine Einzelpreise ein – das System rechnet die ganze Matrix (Stückzahl × Bestellmenge). Stell hier <strong>Marge, Produktionszeit und einen Hinweis</strong> ein, sieh die Preise in der Vorschau und sende dann.</p>
    <form method="post">
      <div class="bx-grid">
        <div class="bx-field"><label>Marge (%) <?= bx_hint('VK = EK × (1 + Marge). Standard = Marge je Form ('.rtrim(rtrim(number_format($defMarge,2,',','.'),'0'),',').' %). Kundenrabatt kommt automatisch dazu.') ?></label><input type="number" step="0.1" name="marge" value="<?= h(rtrim(rtrim(number_format($vMarge,2,'.',''),'0'),'.')) ?>"></div>
        <div class="bx-field"><label>Produktionszeit (Wochen)</label><input type="number" step="0.5" name="produktionszeit" value="<?= h(rtrim(rtrim(number_format($vPz,1,'.',''),'0'),'.')) ?>"></div>
      </div>
      <div class="bx-field"><label>Hinweis an den Kunden (optional)</label><input type="text" name="notiz" value="<?= h($vNotiz) ?>" placeholder="z. B. Preise gültig 30 Tage, zzgl. Versand"></div>
      <?php if ($rabatt != 0): ?><div class="muted" style="margin:-4px 0 10px">Kundenrabatt: <?= rtrim(rtrim(number_format($rabatt,2,',','.'),'0'),',') ?> % ist in der Vorschau bereits berücksichtigt.</div><?php endif; ?>

      <?php if ($preisInfo): ?>
        <div class="bx-panel" style="border-color:var(--gruen);background:var(--panel-2);padding:12px 14px;margin-bottom:12px">
          <strong>Hinweis:</strong> <?= $preisInfo ?>
        </div>
      <?php else: ?>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Bestellmenge</th><?php foreach ($stueckA as $s): ?><th class="bx-num"><?= (int)$s ?> <?= h($formPl) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($mengenA as $bm): ?>
            <tr><td><?= number_format((int)$bm,0,',','.') ?> Pkg.</td>
              <?php foreach ($stueckA as $s): $v = $prev[$s][$bm] ?? null; ?>
                <td class="bx-num"><?= $v!==null ? $eur($v) : '<span class="muted" title="Für diese Stückzahl/Füllmenge ist keine passende Verpackung hinterlegt">auf Anfrage</span>' ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="muted" style="font-size:12px;margin:6px 0 12px">Vorschau je Packung (netto), inkl. Kundenrabatt. „auf Anfrage" = für diese Stückzahl/Füllmenge ist (noch) keine passende Verpackung hinterlegt.</p>
      <?php endif; ?>

      <div class="bx-row" style="gap:10px">
        <button class="btn btn-ghost" type="submit" name="aktion" value="angebot_vorschau">Vorschau aktualisieren</button>
        <button class="btn btn-primary" type="submit" name="aktion" value="angebot_abgeben"<?= $hatPreise ? '' : ' disabled title="Keine berechenbaren Preise – nutze „Im Angebots-Editor bauen“"' ?>>Angebot senden</button>
        <button class="btn <?= $hatPreise ? 'btn-ghost' : 'btn-primary' ?>" type="submit" name="aktion" value="angebot_bauen">Im Angebots-Editor bauen</button>
      </div>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="bx-panel">
  <h2>Bearbeitungsstatus <?= bx_hint('interner Fortschritt; der Kunde sieht: neu = eingegangen, in Bearbeitung, Angebot abgegeben') ?></h2>
  <form method="post" class="bx-row" style="align-items:flex-end;gap:10px">
    <input type="hidden" name="aktion" value="status">
    <div class="bx-field" style="margin:0"><label>Status</label>
      <select name="status">
        <?php foreach (['neu'=>'neu','in_bearbeitung'=>'in Bearbeitung','beantwortet'=>'Angebot abgegeben','abgelehnt'=>'abgelehnt'] as $sk=>$sl): ?>
          <option value="<?= $sk ?>" <?= $pa['status']===$sk?'selected':'' ?>><?= $sl ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-ghost btn-sm" type="submit">Status speichern</button>
  </form>
</div>
<?php render_footer(); ?>
