<?php
// Kundenkonto (Cockpit) + Bearbeiten – Reiter
// 360°-Sicht: Übersicht/Angebote/Bestellungen/Rezepturen/Dokumente + Stammdaten/Adressen/Zahlung/Portal.
// Die Vorgangs-Reiter sind als Gerüst gebaut; Live-Daten docken an, sobald die Module stehen.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$id  = $_GET['id'] ?? 'neu';
$neu = ($id === 'neu' || !is_numeric($id));

// Speichern
$fehler = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = fn($k) => trim($_POST[$k] ?? '');
    if ($f('firma') === '') {
        $fehler = 'Firma ist ein Pflichtfeld.';
    } else {
        $felder = ['kundennummer','firma','ansprechpartner','email','telefon','gesperrt',
                   'strasse','hausnummer','plz','ort','land','ust_id',
                   'rechnung_firma','rechnung_strasse','rechnung_hausnummer','rechnung_plz','rechnung_ort','rechnung_land',
                   'liefer_strasse','liefer_hausnummer','liefer_plz','liefer_ort','liefer_land',
                   'zahlungsart','zahlungsziel_tage','rabatt_marge','aufschlag_marge',
                   'portal_rezeptur','portal_produkte','portal_rohstoffe','portal_dienstleistung','nutzt_fulfillment','notiz'];
        $vals = array_map($f, $felder);
        $vals[array_search('gesperrt', $felder)] = isset($_POST['gesperrt']) ? 1 : 0;
        foreach (['portal_rezeptur','portal_produkte','portal_rohstoffe','portal_dienstleistung','nutzt_fulfillment'] as $pf)
            $vals[array_search($pf, $felder)] = isset($_POST[$pf]) ? 1 : 0;
        foreach (['zahlungsziel_tage','rabatt_marge','aufschlag_marge'] as $nf) { $ix = array_search($nf, $felder); if (trim((string)$vals[$ix]) === '') $vals[$ix] = 0; }
        if ($neu) {
            if (trim($vals[array_search('kundennummer', $felder)]) === '') $vals[array_search('kundennummer', $felder)] = naechste_nummer('K');
            $ph = implode(',', array_fill(0, count($felder), '?'));
            q("INSERT INTO kunden (" . implode(',', $felder) . ") VALUES ($ph)", $vals);
            $id = insert_id();
            log_aktivitaet('kunde', (int)$id, 'team', 'Kunde angelegt.', 'notiz');
        } else {
            $set = implode(',', array_map(fn($c) => "$c=?", $felder));
            $vals[] = (int)$id;
            q("UPDATE kunden SET $set WHERE id=?", $vals);
        }
        // Marken & Webseiten synchronisieren (löschen + neu einfügen, nur nicht-leere Zeilen)
        q("DELETE FROM kunde_marke WHERE kunde_id=?", [(int)$id]);
        $mnamen = $_POST['marke_name'] ?? [];
        $mwebs  = $_POST['marke_webseite'] ?? [];
        foreach ($mnamen as $i => $nm) {
            $nm = trim($nm); $wb = trim($mwebs[$i] ?? '');
            if ($nm === '' && $wb === '') continue;
            q("INSERT INTO kunde_marke (kunde_id,name,webseite,sort) VALUES (?,?,?,?)", [(int)$id, $nm, $wb, $i]);
        }
        header('Location: ?p=kunde&id=' . $id . '&gespeichert=1'); exit;
    }
}

$k = $neu
    ? ['gesperrt' => 0, 'land' => 'DE', 'zahlungsart' => 'vorkasse', 'zahlungsziel_tage' => 0]
    : one("SELECT * FROM kunden WHERE id=?", [(int)$id]);
if (!$k) { $neu = true; $k = ['gesperrt'=>0,'land'=>'DE','zahlungsart'=>'vorkasse']; }
$v = fn($key) => h((string)($k[$key] ?? ''));
$gesperrt = (int)($k['gesperrt'] ?? 0) === 1;
$marken = $neu ? [] : all("SELECT * FROM kunde_marke WHERE kunde_id=? ORDER BY sort,id", [(int)$id]);
if (!$neu) { seed_aktivitaet_if_empty(); $verlauf = verlauf_fuer('kunde', (int)$id); } else { $verlauf = []; }

// ---- Echte Vorgangsdaten des Kunden ----
$k_angebote = $k_auftraege = $k_rechnungen = $k_produkte = $k_rezepturen = [];
$umsatz = $offen = 0.0;
if (!$neu) {
    $kid = (int)$id;
    $k_angebote = all("SELECT a.*, p.name AS produkt_name,
                       (SELECT COUNT(*) FROM angebot_staffel s WHERE s.angebot_id=a.id) AS staffel_anzahl
                       FROM angebot a LEFT JOIN produkt p ON p.id=a.produkt_id
                       WHERE a.kunde_id=? ORDER BY a.angelegt DESC", [$kid]);
    $k_auftraege = all("SELECT a.*, p.name AS produkt_name,
                        (SELECT nummer FROM beleg b WHERE b.auftrag_id=a.id AND b.typ='rechnung' LIMIT 1) AS rechnung_nr
                        FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id
                        WHERE a.kunde_id=? ORDER BY a.angelegt DESC", [$kid]);
    $k_rechnungen = all("SELECT * FROM beleg WHERE kunde_id=? AND typ='rechnung' ORDER BY angelegt DESC", [$kid]);
    $k_produkte = all("SELECT p.*, r.name AS rezeptur_name FROM produkt p LEFT JOIN rezeptur r ON r.id=p.rezeptur_id WHERE p.kunde_id=? ORDER BY p.name", [$kid]);
    $k_rezepturen = all("SELECT * FROM rezeptur WHERE kunde_id=? ORDER BY name", [$kid]);
    $umsatz = (float) scalar("SELECT COALESCE(SUM(netto),0) FROM beleg WHERE kunde_id=? AND typ='rechnung'", [$kid]);
    $offen  = (float) scalar("SELECT COALESCE(SUM(brutto),0) FROM beleg WHERE kunde_id=? AND typ='rechnung' AND status='offen'", [$kid]);
}
$eur = fn($x) => number_format((float)$x, 2, ',', '.') . ' €';
$angBadge = fn($s) => match ($s) { 'offen'=>bx_badge('offen','info'),'gesendet'=>bx_badge('gesendet'),'bestaetigt'=>bx_badge('bestätigt','ok'),'abgelehnt'=>bx_badge('abgelehnt','err'),default=>bx_badge($s) };
$aufBadge = fn($s) => match ($s) { 'offen'=>bx_badge('offen','info'),'in_produktion'=>bx_badge('in Produktion','warn'),'erledigt'=>bx_badge('versandbereit','info'),'versendet'=>bx_badge('versendet','ok'),default=>bx_badge($s) };
$reBadge  = fn($s) => match ($s) { 'bezahlt'=>bx_badge('bezahlt','ok'),'offen'=>bx_badge('offen','warn'),'storniert'=>bx_badge('storniert','err'),default=>bx_badge($s) };
$colsAng = [
    'nummer'         => ['label'=>'Nummer'],
    'produkt_name'   => ['label'=>'Produkt','render'=>fn($r)=> $r['produkt_name']?h($r['produkt_name']):'<span class="muted">–</span>'],
    'staffel_anzahl' => ['label'=>'Staffeln','num'=>true],
    'status'         => ['label'=>'Status','render'=>fn($r)=> $angBadge($r['status'])],
];
$colsAuf = [
    'nummer'       => ['label'=>'Nummer'],
    'produkt_name' => ['label'=>'Produkt','render'=>fn($r)=> $r['produkt_name']?h($r['produkt_name']):'<span class="muted">–</span>'],
    'menge'        => ['label'=>'Menge','num'=>true],
    'gesamt_netto' => ['label'=>'Netto','num'=>true,'render'=>fn($r)=> $eur($r['gesamt_netto'])],
    'rechnung_nr'  => ['label'=>'Rechnung','render'=>fn($r)=> $r['rechnung_nr']?h($r['rechnung_nr']):'<span class="muted">–</span>'],
    'status'       => ['label'=>'Status','render'=>fn($r)=> $aufBadge($r['status'])],
];
$colsRe = [
    'nummer' => ['label'=>'Nummer'],
    'datum'  => ['label'=>'Datum','render'=>fn($r)=> $r['datum']?h(date('d.m.Y',strtotime($r['datum']))):''],
    'netto'  => ['label'=>'Netto','num'=>true,'render'=>fn($r)=> $eur($r['netto'])],
    'brutto' => ['label'=>'Brutto','num'=>true,'render'=>fn($r)=> $eur($r['brutto'])],
    'status' => ['label'=>'Status','render'=>fn($r)=> $reBadge($r['status'])],
];

// Platzhalter für Vorgangs-Listen (docken an, sobald das Modul steht)
function bx_bald(string $modul): void {
    echo '<div class="bx-tablewrap"><table class="bx-table"><tbody><tr><td class="muted">'
       . 'Sobald das Modul <strong>' . h($modul) . '</strong> steht, erscheinen hier automatisch alle '
       . h($modul) . ' dieses Kunden.</td></tr></tbody></table></div>';
}

$portalUrl = $neu ? '' : '?p=portal&token=' . kunde_portal_token((int)$id);
$actions = '';
if (!$neu) $actions .= '<a class="btn btn-accent" href="' . h($portalUrl) . '" target="_blank" rel="noopener">Kundenportal öffnen</a> ';
$actions .= bx_btn('Zurück zur Liste', '?p=kunden', 'ghost');

render_header('kunden', $neu ? 'Neuer Kunde' : $k['firma']);
bx_head($neu ? 'Neuer Kunde' : $v('firma'),
        $neu ? 'Stammdaten anlegen' : trim(($v('kundennummer') ? $v('kundennummer') . ' · ' : '') . $v('ort')),
        $actions);

if (isset($_GET['gespeichert'])) echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Gespeichert.</div>';
if ($fehler) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">' . h($fehler) . '</div>';

// ---- Kennzahlen-Kacheln (real, wo Daten da sind; sonst Platzhalter) ----
if (!$neu) {
    $seit = $k['angelegt'] ?? null;
    echo '<div class="bx-cards">';
    echo '<div class="bx-card"><div class="k">Status</div><div class="v">' . ($gesperrt ? bx_badge('gesperrt','err') : bx_badge('aktiv','ok')) . '</div></div>';
    echo '<div class="bx-card"><div class="k">Umsatz gesamt</div><div class="v">' . ($umsatz>0 ? number_format($umsatz,2,',','.').' €' : '<span class="muted">–</span>') . '</div></div>';
    echo '<div class="bx-card"><div class="k">Offene Posten</div><div class="v" style="' . ($offen>0?'color:var(--warn)':'') . '">' . ($offen>0 ? number_format($offen,2,',','.').' €' : '<span class="muted">0 €</span>') . '</div></div>';
    echo '<div class="bx-card"><div class="k">Bestellungen</div><div class="v">' . (count($k_auftraege) ?: '<span class="muted">0</span>') . '</div></div>';
    echo '<div class="bx-card"><div class="k">Kunde seit</div><div class="v">' . ($seit ? h(date('m.Y', strtotime($seit))) : '–') . '</div></div>';
    echo '</div>';
}
?>
<form method="post" class="bx-form">
  <div class="settabs" id="kundtabs">
    <?php if (!$neu): ?>
    <a href="#" class="on" data-tab="ueber">Übersicht</a>
    <a href="#" data-tab="angebote">Angebote</a>
    <a href="#" data-tab="bestell">Bestellungen</a>
    <a href="#" data-tab="rezept">Rezepturen</a>
    <a href="#" data-tab="dok">Dokumente</a>
    <a href="#" data-tab="verlauf">Verlauf</a>
    <a href="#" data-tab="stamm">Stammdaten</a>
    <?php else: ?>
    <a href="#" class="on" data-tab="stamm">Stammdaten</a>
    <?php endif; ?>
    <a href="#" data-tab="adr">Adressen</a>
    <a href="#" data-tab="zahl">Zahlung &amp; Konditionen</a>
    <a href="#" data-tab="portal">Portal-Einstellungen</a>
  </div>

  <?php if (!$neu): ?>
  <section data-panel="ueber">
    <div class="bx-panel">
      <h2>Kontakt</h2>
      <div class="bx-grid">
        <div><div class="k muted">Ansprechpartner</div><div><?= $v('ansprechpartner') ?: '–' ?></div></div>
        <div><div class="k muted">E-Mail</div><div><?= $v('email') ?: '–' ?></div></div>
        <div><div class="k muted">Telefon</div><div><?= $v('telefon') ?: '–' ?></div></div>
        <div><div class="k muted">Zahlungsart</div><div><?= $v('zahlungsart') ?></div></div>
      </div>
      <?php if ($marken): ?>
      <hr class="bx">
      <div class="k muted">Marken &amp; Webseiten</div>
      <div class="bx-row" style="margin-top:6px">
        <?php foreach ($marken as $m): ?>
          <span class="badge">
            <?= h($m['name'] ?: $m['webseite']) ?>
            <?php if ($m['webseite']): $u = preg_match('~^https?://~', $m['webseite']) ? $m['webseite'] : 'https://'.$m['webseite']; ?>
              · <a href="<?= h($u) ?>" target="_blank" rel="noopener"><?= h(preg_replace('~^https?://~','',$m['webseite'])) ?></a>
            <?php endif; ?>
          </span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="bx-panel"><h2>Letzte Angebote</h2>
      <?php bx_table($colsAng, array_slice($k_angebote,0,5), ['rowUrl'=>fn($r)=>'?p=angebot&id='.$r['id'], 'empty'=>'Noch keine Angebote.']); ?>
    </div>
    <div class="bx-panel"><h2>Letzte Bestellungen</h2>
      <?php bx_table($colsAuf, array_slice($k_auftraege,0,5), ['rowUrl'=>fn($r)=>'?p=auftrag&id='.$r['id'], 'empty'=>'Noch keine Aufträge.']); ?>
    </div>
  </section>

  <section data-panel="angebote" hidden><div class="bx-panel"><h2>Angebote (<?= count($k_angebote) ?>)</h2>
    <?php bx_table($colsAng, $k_angebote, ['rowUrl'=>fn($r)=>'?p=angebot&id='.$r['id'], 'empty'=>'Noch keine Angebote.']); ?>
  </div></section>
  <section data-panel="bestell" hidden>
    <div class="bx-panel"><h2>Aufträge (<?= count($k_auftraege) ?>)</h2>
      <?php bx_table($colsAuf, $k_auftraege, ['rowUrl'=>fn($r)=>'?p=auftrag&id='.$r['id'], 'empty'=>'Noch keine Aufträge.']); ?>
    </div>
    <div class="bx-panel"><h2>Rechnungen (<?= count($k_rechnungen) ?>) · offene Posten: <?= $eur($offen) ?></h2>
      <?php bx_table($colsRe, $k_rechnungen, ['rowUrl'=>fn($r)=>'?p=rechnung&id='.$r['id'], 'empty'=>'Noch keine Rechnungen.']); ?>
    </div>
  </section>
  <section data-panel="rezept" hidden>
    <div class="bx-panel"><h2>Produkte (<?= count($k_produkte) ?>)</h2>
      <?php bx_table([
        'name'=>['label'=>'Produkt'],
        'rezeptur_name'=>['label'=>'Rezeptur','render'=>fn($r)=> $r['rezeptur_name']?h($r['rezeptur_name']):'<span class="muted">–</span>'],
        'einheiten_pro_packung'=>['label'=>'Einh./Pack','num'=>true],
      ], $k_produkte, ['rowUrl'=>fn($r)=>'?p=produkt&id='.$r['id'], 'empty'=>'Noch keine Produkte.']); ?>
    </div>
    <div class="bx-panel"><h2>Rezepturen (<?= count($k_rezepturen) ?>)</h2>
      <?php bx_table([
        'nummer'=>['label'=>'Nummer'],
        'name'=>['label'=>'Name'],
        'darreichungsform'=>['label'=>'Form'],
      ], $k_rezepturen, ['rowUrl'=>fn($r)=>'?p=rezeptur_detail&id='.$r['id'], 'empty'=>'Noch keine Rezepturen.']); ?>
    </div>
  </section>
  <section data-panel="dok" hidden><div class="bx-panel"><h2>Dokumente</h2><?php bx_bald('Dokumente'); ?></div></section>
  <section data-panel="verlauf" hidden>
    <div class="bx-panel">
      <h2>Aktivitätsverlauf <?= bx_hint('links = wir (bulkify), rechts = Kunde. Jede Aktion wird automatisch protokolliert') ?></h2>
      <?php bx_chat($verlauf, $v('firma')); ?>
    </div>
  </section>
  <?php endif; ?>

  <section data-panel="stamm" <?= $neu ? '' : 'hidden' ?>>
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Kundennummer <?= bx_hint('leer lassen = wird automatisch vergeben (K-…)') ?></label><input type="text" name="kundennummer" value="<?= $v('kundennummer') ?>" placeholder="<?= $neu ? 'automatisch (K-…)' : '' ?>"></div>
      <div class="bx-field"><label>Firma</label><input type="text" name="firma" value="<?= $v('firma') ?>" required></div>
      <div class="bx-field"><label>Ansprechpartner</label><input type="text" name="ansprechpartner" value="<?= $v('ansprechpartner') ?>"></div>
      <div class="bx-field"><label>E-Mail</label><input type="email" name="email" value="<?= $v('email') ?>"></div>
      <div class="bx-field"><label>Telefon</label><input type="text" name="telefon" value="<?= $v('telefon') ?>"></div>
      <div class="bx-field"><label>Kunde sperren <?= bx_hint('Schutzfunktion: gesperrte Kunden können nicht bestellen und erscheinen markiert in der Liste') ?></label>
        <div class="bx-check" style="padding-top:8px">
          <input type="checkbox" name="gesperrt" id="f_gesperrt" value="1" <?= $gesperrt?'checked':'' ?>>
          <label for="f_gesperrt" style="margin:0">Kunde ist gesperrt</label>
        </div>
      </div>
    </div>
    <div class="bx-field"><label>Notiz (intern)</label><textarea name="notiz"><?= $v('notiz') ?></textarea></div>
    </div>

    <div class="bx-panel">
      <h2>Marken &amp; Webseiten <?= bx_hint('ein Kunde kann mehrere Marken und Webseiten haben (White-Label)') ?></h2>
      <div id="markenrows">
        <?php
        $rows = $marken ?: [['name'=>'','webseite'=>'']];
        foreach ($rows as $m):
        ?>
        <div class="bx-row markenrow" style="flex-wrap:nowrap;margin-bottom:8px">
          <input type="text" name="marke_name[]" value="<?= h($m['name']) ?>" placeholder="Markenname" style="flex:1">
          <input type="text" name="marke_webseite[]" value="<?= h($m['webseite']) ?>" placeholder="https://…" style="flex:1">
          <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.markenrow').remove()">entfernen</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-ghost btn-sm" id="addMarke">+ Marke / Webseite</button>
    </div>
  </section>

  <section data-panel="adr" hidden>
    <div class="bx-panel">
      <h2>Hauptadresse</h2>
      <div class="bx-grid">
        <div class="bx-field"><label>Straße / Hausnummer</label>
          <div class="bx-row" style="flex-wrap:nowrap">
            <input type="text" name="strasse" value="<?= $v('strasse') ?>" style="flex:1" placeholder="Straße">
            <input type="text" name="hausnummer" value="<?= $v('hausnummer') ?>" style="width:100px" placeholder="Nr.">
          </div>
        </div>
        <div class="bx-field"><label>PLZ / Ort</label>
          <div class="bx-row" style="flex-wrap:nowrap">
            <input type="text" name="plz" value="<?= $v('plz') ?>" style="width:110px" placeholder="PLZ">
            <input type="text" name="ort" value="<?= $v('ort') ?>" style="flex:1" placeholder="Ort">
          </div>
        </div>
        <div class="bx-field"><label>Land <?= bx_hint('EU-Ausland: automatisch 0% USt bei gültiger USt-ID') ?></label><input type="text" name="land" value="<?= $v('land') ?>" maxlength="2"></div>
        <div class="bx-field"><label>USt-ID</label><input type="text" name="ust_id" value="<?= $v('ust_id') ?>"></div>
      </div>
    </div>

    <div class="bx-panel">
      <h2>Rechnungsadresse <?= bx_hint('nur ausfüllen, wenn sie von der Hauptadresse abweicht') ?></h2>
      <div class="bx-grid">
        <div class="bx-field"><label>Rechnungs-Firma</label><input type="text" name="rechnung_firma" value="<?= $v('rechnung_firma') ?>"></div>
        <div class="bx-field"><label>Straße / Hausnummer</label>
          <div class="bx-row" style="flex-wrap:nowrap">
            <input type="text" name="rechnung_strasse" value="<?= $v('rechnung_strasse') ?>" style="flex:1" placeholder="Straße">
            <input type="text" name="rechnung_hausnummer" value="<?= $v('rechnung_hausnummer') ?>" style="width:100px" placeholder="Nr.">
          </div>
        </div>
        <div class="bx-field"><label>PLZ / Ort</label>
          <div class="bx-row" style="flex-wrap:nowrap">
            <input type="text" name="rechnung_plz" value="<?= $v('rechnung_plz') ?>" style="width:110px" placeholder="PLZ">
            <input type="text" name="rechnung_ort" value="<?= $v('rechnung_ort') ?>" style="flex:1" placeholder="Ort">
          </div>
        </div>
        <div class="bx-field"><label>Land</label><input type="text" name="rechnung_land" value="<?= $v('rechnung_land') ?>" maxlength="2"></div>
      </div>
    </div>

    <div class="bx-panel">
      <h2>Lieferadresse <?= bx_hint('nur ausfüllen, wenn sie abweicht – Basis für DHL-Etiketten') ?></h2>
      <div class="bx-grid">
        <div class="bx-field"><label>Straße / Hausnummer</label>
          <div class="bx-row" style="flex-wrap:nowrap">
            <input type="text" name="liefer_strasse" value="<?= $v('liefer_strasse') ?>" style="flex:1" placeholder="Straße">
            <input type="text" name="liefer_hausnummer" value="<?= $v('liefer_hausnummer') ?>" style="width:100px" placeholder="Nr.">
          </div>
        </div>
        <div class="bx-field"><label>PLZ / Ort</label>
          <div class="bx-row" style="flex-wrap:nowrap">
            <input type="text" name="liefer_plz" value="<?= $v('liefer_plz') ?>" style="width:110px" placeholder="PLZ">
            <input type="text" name="liefer_ort" value="<?= $v('liefer_ort') ?>" style="flex:1" placeholder="Ort">
          </div>
        </div>
        <div class="bx-field"><label>Land</label><input type="text" name="liefer_land" value="<?= $v('liefer_land') ?>" maxlength="2"></div>
      </div>
    </div>
  </section>

  <section data-panel="zahl" hidden>
    <div class="bx-panel"><div class="bx-grid">
      <div class="bx-field"><label>Zahlungsart</label>
        <select name="zahlungsart">
          <?php foreach (['vorkasse'=>'Vorkasse','rechnung'=>'Rechnung','lastschrift'=>'Lastschrift'] as $s=>$l): ?>
            <option value="<?= $s ?>" <?= ($k['zahlungsart']??'')===$s?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Zahlungsziel (Tage)</label><input type="number" name="zahlungsziel_tage" value="<?= $v('zahlungsziel_tage') ?>"></div>
      <div class="bx-field"><label>Rabatt auf Marge (%) <?= bx_hint('wirkt nur auf die Marge (VK−EK), nie unter EK') ?></label><input type="number" step="0.01" name="rabatt_marge" value="<?= $v('rabatt_marge') ?>"></div>
      <div class="bx-field"><label>Aufschlag auf Marge (%)</label><input type="number" step="0.01" name="aufschlag_marge" value="<?= $v('aufschlag_marge') ?>"></div>
    </div></div>
  </section>

  <section data-panel="portal" hidden>
    <div class="bx-panel">
      <h2 style="margin-top:0">Portal-Freischaltungen <?= bx_hint('welche Anfrage-Bereiche dieser Kunde im Kundenportal sieht') ?></h2>
      <div class="bx-grid">
        <?php foreach ([
            'portal_rezeptur'=>'Rezepturentwicklung (Rezeptur anfragen)',
            'portal_produkte'=>'Produkte anfragen (Katalog)',
            'portal_rohstoffe'=>'Rohstoffe anfragen',
            'portal_dienstleistung'=>'Dienstleistung anfragen',
        ] as $key=>$lbl): ?>
          <div class="bx-check">
            <input type="checkbox" name="<?= $key ?>" id="f_<?= $key ?>" value="1" <?= (int)($k[$key] ?? 0)===1?'checked':'' ?>>
            <label for="f_<?= $key ?>" style="margin:0"><?= h($lbl) ?></label>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line,#e5e5e5)">
        <div class="bx-check">
          <input type="checkbox" name="nutzt_fulfillment" id="f_nutzt_fulfillment" value="1" <?= (int)($k['nutzt_fulfillment'] ?? 0)===1?'checked':'' ?>>
          <label for="f_nutzt_fulfillment" style="margin:0">Nutzt unser Fulfillment (Fremdlager)</label>
        </div>
        <div class="muted" style="font-size:12px;margin-top:4px">Nur dann wird die Fertigware dieses Kunden bei uns eingelagert (Fremdlager) und mit dem Versandsystem gekoppelt. Ohne Haken wird nur produziert und an den Kunden geliefert.</div>
      </div>
      <?php if (!$neu): ?><div class="muted" style="margin-top:12px">Portal-Link: <a href="<?= h($portalUrl) ?>" target="_blank"><?= h($portalUrl) ?></a></div><?php endif; ?>
    </div>
  </section>

  <div class="bx-row" style="margin-top:var(--sp-4)">
    <button class="btn btn-primary" type="submit"><?= $neu ? 'Kunde anlegen' : 'Speichern' ?></button>
    <a class="btn btn-ghost" href="?p=kunden">Abbrechen</a>
  </div>
</form>

<script>
(function(){
  var tabs = document.querySelectorAll('#kundtabs a');
  tabs.forEach(function(t){
    t.addEventListener('click', function(e){
      e.preventDefault();
      tabs.forEach(function(x){ x.classList.remove('on'); });
      t.classList.add('on');
      document.querySelectorAll('[data-panel]').forEach(function(p){
        p.hidden = (p.getAttribute('data-panel') !== t.getAttribute('data-tab'));
      });
    });
  });
  var add = document.getElementById('addMarke');
  if (add) add.addEventListener('click', function(){
    var row = document.createElement('div');
    row.className = 'bx-row markenrow';
    row.style.cssText = 'flex-wrap:nowrap;margin-bottom:8px';
    row.innerHTML = '<input type="text" name="marke_name[]" placeholder="Markenname" style="flex:1">'
      + '<input type="text" name="marke_webseite[]" placeholder="https://…" style="flex:1">'
      + '<button type="button" class="btn btn-ghost btn-sm">entfernen</button>';
    row.querySelector('button').addEventListener('click', function(){ row.remove(); });
    document.getElementById('markenrows').appendChild(row);
  });
})();
</script>
<?php
render_footer();
