<?php
// Wareneingang – Charge buchen; Quarantäne freigeben
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

$hinweis = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'buchen') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $menge = zahl_lesen((string)($_POST['menge'] ?? '0'), true);   // „60.000" = 60000 (wie ?p=we im Lager)
        $lief = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
        $aid = ($_POST['auftrag_id'] ?? '') !== '' ? (int)$_POST['auftrag_id'] : null;
        $cid = wareneingang_buchen($item_id, $menge, trim($_POST['charge_nr'] ?? ''), trim($_POST['mhd'] ?? '') ?: null, $lief, trim($_POST['notiz'] ?? ''), $aid);
        // Bestellbezug wie im Lager-Wareneingang: gehört die Ware zu einer Bestellung, wird diese geschlossen (kein „unterwegs" + eingebucht).
        // Teillieferung (Nacht 1c): nur die gelieferte Menge quittieren; geschlossen wird erst, wenn alle Positionen da sind.
        if ($cid && (int)($_POST['bestellung_id'] ?? 0) > 0) bestellung_lieferung_erfassen((int)$_POST['bestellung_id'], [(int)$cid]);
        // Tracking-Code(s) der eingegangenen Pakete/Palette an die Charge haengen (je Zeile ein Code, gescannt).
        if ($cid) {
            $codes = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)($_POST['tracking'] ?? ''))))));
            q("UPDATE charge SET tracking=? WHERE id=?", [$codes ? implode("\n", $codes) : null, $cid]);
        }
        if ($cid) nach_antwort(fn() => material_komplett_pruefen(true));   // Team: „Material komplett" für wartende Aufträge
        header('Location: ?p=wareneingang' . ($cid ? '&ok=1' : '&fehler=1')); exit;
    }
    if ($aktion === 'freigeben') {
        if (!darf_qm_freigeben()) { header('Location: ?p=wareneingang&quarantaene=1&nurqm=1'); exit; }   // QM-Gate (St-11)
        $qcid = (int)($_POST['charge_id'] ?? 0);
        $warQ = (string) scalar("SELECT status FROM charge WHERE id=?", [$qcid]) === 'quarantaene';
        q("UPDATE charge SET status='frei' WHERE id=? AND status='quarantaene'", [$qcid]);
        bedarf_bump();   // freigegebene Ware zählt sofort als verfügbar (Bedarf/Einkauf/Produktion)
        if ($warQ) fertigware_qm_freigabe_nach($qcid);   // Fertigware: Produktionsbericht + zusammenfassendes CoA für den Kunden (Nico 03.11.)
        nach_antwort(fn() => material_komplett_pruefen(true));   // Team: „Material komplett" für wartende Aufträge
        header('Location: ?p=wareneingang&frei=1' . (!empty($_POST['quarantaene']) ? '&quarantaene=1' . ((int)($_POST['q_item'] ?? 0) > 0 ? '&item=' . (int)$_POST['q_item'] : '') : '')); exit;
    }
    // Zukauf: fertige Bulkware zu einem Auftrag buchen (Fremdproduktion). Es gibt (noch) keinen Fertigware-
    // Artikel im Katalog – wir leiten ihn aus der Rezeptur des Auftragsprodukts ab (rezeptur_bulkitem, legt
    // bei Bedarf „<Rezeptur> – Bulk", Kategorie 'fertig', an) und buchen die Charge gegen den Auftrag.
    // Der Produktionsauftrag erkennt den Zukauf dann automatisch (produktion_ist_zukauf) und verkürzt den Weg.
    if ($aktion === 'zukauf_buchen') {
        $aid = (int)($_POST['auftrag_id'] ?? 0);
        $menge = zahl_lesen((string)($_POST['menge'] ?? '0'), true);
        $lief = ($_POST['lieferant_id'] ?? '') !== '' ? (int)$_POST['lieferant_id'] : null;
        $au = $aid ? one("SELECT a.produkt_id, p.rezeptur_id FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id WHERE a.id=?", [$aid]) : null;
        $rez = (int)($au['rezeptur_id'] ?? 0);
        // Fertige Ware (Handelsware / Zukauf „fertig", S-15/St-24): in PACKUNGEN auf den verkaufsfertigen Artikel des Produkts,
        // MHD = Lieferanten-MHD aus der Eingabe. Sonst wie bisher lose Bulkware auf den Rezeptur-Bulk-Artikel.
        $zkFertig = $au && auftrag_zukauf_fertig($aid);
        if (!$au || (!$zkFertig && $rez <= 0)) { header('Location: ?p=wareneingang&zkfehler=1'); exit; }
        $item_id = $zkFertig ? produkt_lageritem((int)$au['produkt_id']) : rezeptur_bulkitem($rez);
        if (!$item_id) { header('Location: ?p=wareneingang&zkfehler=1'); exit; }
        $cid = wareneingang_buchen((int)$item_id, $menge, trim($_POST['charge_nr'] ?? ''), trim($_POST['mhd'] ?? '') ?: null, $lief, trim($_POST['notiz'] ?? ''), $aid);
        if ($cid) {
            $codes = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)($_POST['tracking'] ?? ''))))));
            q("UPDATE charge SET tracking=? WHERE id=?", [$codes ? implode("\n", $codes) : null, $cid]);
        }
        if ($cid) nach_antwort(fn() => material_komplett_pruefen(true));
        header('Location: ?p=wareneingang' . ($cid ? '&zkok=1' : '&zkfehler=1')); exit;
    }
}

$items = all("SELECT id, name, kategorie, einheit, verpackung_rolle, breite_mm, hoehe_mm, etikett_format FROM item WHERE kategorie IN ('rohstoff','verpackung','fertig','verkaufsfertig') AND gesperrt=0 ORDER BY name");
$lieferanten = all("SELECT id, firma FROM lieferanten ORDER BY firma");
// Erwartete Lieferungen (bestellt, noch nicht angekommen) – optionaler Bestellbezug beim Einbuchen.
$offeneBestellungen = all("SELECT b.id, b.nummer, COALESCE(l.firma,'') AS lieferant FROM bestellung b LEFT JOIN lieferanten l ON l.id=b.lieferant_id
                           WHERE b.status='bestellt' AND b.angekommen_am IS NULL ORDER BY b.id DESC LIMIT 200");
$offeneAuftraege = all("SELECT a.id, a.nummer, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma
                        FROM auftrag a LEFT JOIN produkt p ON p.id=a.produkt_id LEFT JOIN kunden k ON k.id=a.kunde_id
                        WHERE a.status <> 'versendet' ORDER BY a.angelegt DESC");
// Aufträge, für die zugekaufte Fertigware (Bulk) eingebucht werden kann: offen + Produkt mit Rezeptur.
// Handelsware (ohne Rezeptur, S-15) kommt hier ebenfalls – als fertige Ware in Packungen.
$zukaufAuftraege = all("SELECT a.id, a.nummer, COALESCE(NULLIF(p.kundenname,''), p.name) AS produkt, k.firma, r.name AS rezeptur, p.handelsware
                        FROM auftrag a JOIN produkt p ON p.id=a.produkt_id LEFT JOIN rezeptur r ON r.id=p.rezeptur_id
                        LEFT JOIN kunden k ON k.id=a.kunde_id
                        WHERE a.status NOT IN ('versendet','erledigt') AND (r.id IS NOT NULL OR p.handelsware=1) ORDER BY a.angelegt DESC");
// Etikett-Design je offenem Auftrag (dokument typ='etikett') – fürs Abgleichen beim Etikett-Wareneingang:
// Bild + Maße zeigen, damit man sieht, ob das gelieferte Etikett dem bestellten entspricht.
$aufEtikett = [];   // auftrag_id => dokument_id
$aufIdsE = array_values(array_filter(array_map(fn($a) => (int)$a['id'], $offeneAuftraege)));
if ($aufIdsE)
    foreach (all("SELECT objekt_id, MAX(id) AS did FROM dokument WHERE objekt_typ='auftrag' AND typ='etikett' AND objekt_id IN (" . implode(',', $aufIdsE) . ") GROUP BY objekt_id") as $d)
        $aufEtikett[(int)$d['objekt_id']] = (int)$d['did'];
$such = trim((string)($_GET['such'] ?? ''));   // Suche nach Tracking-Code / Charge / Artikel (Paket wiederfinden)
// Quarantäne-Freigabe: ALLE Chargen in Quarantäne (nicht nur die letzten 25), optional nur ein Artikel – Ziel der Links
// „freigeben" aus Auftrag, Produktionsauftrag und Einkaufsliste (St-07/St-12).
$nurQuar = !empty($_GET['quarantaene']);
$quarItem = (int)($_GET['item'] ?? 0);
if ($nurQuar) {
    // Übersicht (Nacht 1b): nur Chargen mit Bestand; Herkunft = Lieferant ODER Produktionsauftrag (Fertigware wartet seit St-11d auf QM).
    $charges = all("SELECT c.*, i.name AS item_name, i.kategorie AS item_kategorie, l.firma AS lieferant_firma, pa.nummer AS pa_nummer
                    FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
                    LEFT JOIN produktionsauftrag pa ON pa.id=c.pa_id
                    WHERE c.status='quarantaene' AND c.fremd_kunde_id IS NULL AND c.menge_verfuegbar>0" . ($quarItem > 0 ? " AND c.item_id=" . $quarItem : "") . "
                    ORDER BY COALESCE(c.wareneingang, DATE(c.angelegt)) ASC, i.name");
} elseif ($such !== '') {
    $like = '%' . $such . '%';
    $charges = all("SELECT c.*, i.name AS item_name, i.kategorie AS item_kategorie, l.firma AS lieferant_firma
                    FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
                    WHERE c.tracking LIKE ? OR c.charge_nr LIKE ? OR i.name LIKE ?
                    ORDER BY c.angelegt DESC LIMIT 100", [$like, $like, $like]);
} else {
    $charges = all("SELECT c.*, i.name AS item_name, i.kategorie AS item_kategorie, l.firma AS lieferant_firma
                    FROM charge c LEFT JOIN item i ON i.id=c.item_id LEFT JOIN lieferanten l ON l.id=c.lieferant_id
                    ORDER BY c.angelegt DESC LIMIT 25");
}

$statusBadge = fn($s) => match ($s) {
    'frei'        => bx_badge('frei','ok'),
    'quarantaene' => bx_badge('Quarantäne','warn'),
    'gesperrt'    => bx_badge('gesperrt','err'),
    'leer'        => bx_badge('leer'),
    default       => bx_badge($s),
};
// Menge lesbar: Gewicht/Volumen (g/ml) ab 1.000 in kg/L, ab 1.000.000 in t (via gewicht_lesbar); sonst Zahl + Einheit.
$mng = fn($x,$e) => (function_exists('gewicht_lesbar') && in_array((string)$e, ['g','ml'], true))
    ? gewicht_lesbar((float)$x, (string)$e)
    : rtrim(rtrim(number_format((float)$x,3,',','.'),'0'),',') . ' ' . h($e ?: '');
// Teil 4 (Schritt 6): Artikel-Detailseite je Kategorie (Warenlager-Detailseite) – wie in bestand_liste.php.
$artikelUrl = function (array $c): string {
    $iid = (int)($c['item_id'] ?? 0);
    if ($iid <= 0) return '';
    $kat = (string)($c['item_kategorie'] ?? '');
    if (function_exists('ist_betriebsmittel_kat') && ist_betriebsmittel_kat($kat)) return '?p=betriebsmittel&id=' . $iid;
    return $kat === 'verpackung' ? '?p=verpackung&id=' . $iid : '?p=rohstoff&id=' . $iid;
};

render_header('wareneingang', 'Wareneingang');
bx_head('Wareneingang', 'Charge buchen', bx_btn('Zum Bestand', '?p=lager', 'ghost'));
if (isset($_GET['ok']))     echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Wareneingang gebucht.</div>';
if (isset($_GET['frei']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Charge freigegeben.</div>';
if (isset($_GET['fehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">Menge oder Artikel fehlt.</div>';
if (isset($_GET['zkok']))   echo '<div class="bx-panel badge-ok" style="padding:12px 16px">Zukauf-Fertigware gebucht und dem Auftrag zugeordnet. Nach dem Freigeben (unten) erkennt der Produktionsauftrag den Zukauf automatisch.</div>';
if (isset($_GET['zkfehler'])) echo '<div class="bx-panel" style="border-color:#e6c4c0;color:#8f231b">Zukauf nicht gebucht – Auftrag ohne Rezeptur/Produkt oder Menge fehlt.</div>';
?>
<form method="post" class="bx-form">
  <input type="hidden" name="aktion" id="weAktion" value="buchen">
  <div class="bx-panel">
    <style>
      .bx-combo{position:relative}
      .bx-combo-list{position:absolute;left:0;right:0;top:100%;z-index:30;max-height:300px;overflow:auto;
        background:var(--panel,#fff);border:1px solid var(--line,#ddd);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);margin-top:3px}
      .bx-combo-list .opt{padding:8px 12px;cursor:pointer;font-size:14px}
      .bx-combo-list .opt:hover,.bx-combo-list .opt.hl{background:var(--panel-2,#f2f2f0)}
      .bx-combo-list .opt .muted{font-size:12px}
      .bx-combo-empty{padding:8px 12px;color:var(--muted);font-size:13px}
    </style>
    <!-- 1) Typ zuerst – er steuert, was danach eingegeben wird -->
    <div class="bx-field" style="max-width:360px"><label>Was kommt rein? (Typ)</label>
      <select id="weTyp">
        <option value="rohstoff">Rohstoff</option>
        <option value="verpackung">Verpackung / Etikett</option>
        <option value="fertig">Fertigware (Bulk) – vorhandener Artikel</option>
        <option value="zukauf">Fertigware zum Auftrag (Fremdproduktion, Kapseln vom Lieferant)</option>
      </select>
    </div>
    <div class="bx-grid">
      <!-- 2a) Artikel-Feld (Rohstoff/Verpackung/Fertigware) -->
      <div class="bx-field bx-combo" id="weBlockArt"><label>Artikel</label>
        <input type="text" id="weArtSuche" autocomplete="off" placeholder="Artikel suchen oder wählen…" required aria-expanded="false">
        <input type="hidden" name="item_id" id="weArtId">
        <div id="weArtList" class="bx-combo-list" hidden></div>
      </div>
      <!-- 2b) Auftrag (nur Zukauf) – Fertigware-Artikel der Rezeptur wird automatisch angelegt -->
      <div class="bx-field" id="weBlockAuf" style="display:none"><label>Auftrag (Fremdproduktion)</label>
        <select name="auftrag_id" id="weZukaufAuf" disabled>
          <option value="">– Auftrag wählen –</option>
          <?php foreach ($zukaufAuftraege as $a): $zkF = auftrag_zukauf_fertig((int)$a['id']); ?><option value="<?= (int)$a['id'] ?>"><?= h($a['nummer'] . ' · ' . $a['produkt'] . ($a['firma'] ? ' · ' . $a['firma'] : '') . ($zkF ? ' · fertige Ware in Packungen' : '')) ?></option><?php endforeach; ?>
        </select>
        <div class="muted" style="font-size:12px;margin-top:4px">Der Fertigware-Artikel der Rezeptur wird automatisch angelegt und die Charge dem Auftrag zugeordnet. Bei Handelsware / fertiger Ware: Menge in Packungen, MHD des Lieferanten eintragen.</div>
      </div>
      <div class="bx-field"><label>Menge</label>
        <div class="bx-row" style="gap:8px;align-items:center;margin:0">
          <input type="number" step="0.001" name="menge" required style="flex:1;min-width:0">
          <span id="weEinheit" style="min-width:44px;font-weight:600;color:var(--muted)">–</span>
        </div>
        <div class="muted" id="weEinheitHint" style="font-size:12px;margin-top:4px">Erst Artikel wählen – die Einheit erscheint hier.</div>
      </div>
      <div class="bx-field"><label>Charge (Lieferant) <?= bx_hint('Chargennummer laut Lieferant/CoA') ?></label><input type="text" name="charge_nr"></div>
      <div class="bx-field"><label>MHD</label><input type="date" name="mhd"></div>
      <div class="bx-field"><label>Lieferant</label>
        <select name="lieferant_id">
          <option value="">– keiner –</option>
          <?php foreach ($lieferanten as $lf): ?><option value="<?= $lf['id'] ?>"><?= h($lf['firma']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="bx-field"><label>Zur Bestellung <?= bx_hint('Optional: gehört diese Ware zu einer Bestellung beim Lieferanten? Dann gilt die Bestellung als angekommen und zählt nicht mehr als „unterwegs".') ?></label>
        <select name="bestellung_id">
          <option value="">– keine –</option>
          <?php foreach ($offeneBestellungen as $ob): ?><option value="<?= (int)$ob['id'] ?>"><?= h($ob['nummer'] . ($ob['lieferant'] !== '' ? ' · ' . $ob['lieferant'] : '')) ?></option><?php endforeach; ?>
        </select>
      </div>
      <!-- 2c) „Für Auftrag" (optional) – nur bei Rohstoff/Verpackung/Fertigware -->
      <div class="bx-field" id="weBlockFuerAuf"><label>Für Auftrag <?= bx_hint('Optional: gehört diese Lieferung zu einem Kundenauftrag? Dann wird die Charge dem Auftrag zugeordnet. Sonst „Lager / allgemein".') ?></label>
        <select name="auftrag_id" id="weNormalAuf">
          <option value="">Lager / allgemein</option>
          <?php foreach ($offeneAuftraege as $a): ?><option value="<?= (int)$a['id'] ?>"><?= h($a['nummer'] . ($a['produkt'] ? ' · ' . $a['produkt'] : '') . ($a['firma'] ? ' · ' . $a['firma'] : '')) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <!-- Etikett-Abgleich: bestelltes Format + Design-Bild, damit die Lieferung gegen die Bestellung geprüft werden kann -->
    <div class="bx-panel" id="weEtikettBox" style="display:none;margin:0 0 12px;border-color:#C0F24E">
      <div style="font-weight:600;margin-bottom:6px">Etikett prüfen – stimmt das Gelieferte mit der Bestellung?</div>
      <div id="weEtikettMass" class="muted" style="font-size:13px;margin-bottom:8px"></div>
      <div id="weEtikettDesign"></div>
    </div>
    <div class="bx-field"><label>Tracking-Code(s) Paket / Palette <?= bx_hint('Barcode/Sendungsnummer vom Paket- oder Palettenetikett scannen. Mehrere Pakete: einfach nacheinander scannen – jeder Scan landet automatisch in einer eigenen Zeile.') ?></label>
      <textarea name="tracking" id="weTracking" rows="2" placeholder="Etikett scannen … (je Paket eine Zeile)" style="font-variant-numeric:tabular-nums"></textarea>
      <div class="muted" id="weTrackCount" style="font-size:12px;margin-top:4px"></div>
    </div>
    <div class="bx-field"><label>Notiz</label><input type="text" name="notiz"></div>
    <div class="muted" style="margin-bottom:8px">Rohstoffe und Fertigware gehen zunächst in <strong>Quarantäne</strong> und müssen unten freigegeben werden. Verpackungen sind sofort frei.</div>
    <button class="btn btn-primary" type="submit">Wareneingang buchen</button>
  </div>
</form>
<script>
(function(){
  var items = <?= json_encode(array_map(fn($it)=>['id'=>(int)$it['id'],'n'=>(string)$it['name'],'e'=>(string)$it['einheit'],'k'=>(string)$it['kategorie'],'r'=>(string)($it['verpackung_rolle'] ?? ''),'w'=>$it['breite_mm']!==null?(float)$it['breite_mm']:null,'h'=>$it['hoehe_mm']!==null?(float)$it['hoehe_mm']:null,'f'=>(string)($it['etikett_format'] ?? '')], $items), JSON_UNESCAPED_UNICODE) ?>;
  var aufEtikett = <?= json_encode($aufEtikett, JSON_FORCE_OBJECT) ?>;   // auftrag_id => dokument_id (Etikett-Design)
  var box=document.getElementById('weArtSuche'), hid=document.getElementById('weArtId'), list=document.getElementById('weArtList');
  if(!box||!hid||!list) return;
  function itemById(id){ id=+id; for(var i=0;i<items.length;i++) if(items[i].id===id) return items[i]; return null; }
  function nz(x){ return (Math.round(Number(x)*100)/100).toString(); }   // 172.00 -> 172, 60 bleibt 60
  function etMass(it){ return (it.w&&it.h) ? (nz(it.w)+' × '+nz(it.h)+' mm') : (it.f||''); }
  // Etikett-Abgleich: bestelltes Format + (falls Auftrag gewählt) das freigegebene Design als Bild.
  function showEtikett(){
    var boxEl=document.getElementById('weEtikettBox'); if(!boxEl) return;
    var it=itemById(hid.value);
    if(!it || it.r!=='etikett'){ boxEl.style.display='none'; return; }
    var mass=etMass(it);
    document.getElementById('weEtikettMass').innerHTML = mass
      ? ('Bestelltes Etikettenformat: <strong>'+esc(mass)+'</strong> &nbsp;·&nbsp; bitte das gelieferte Etikett ausmessen und vergleichen.')
      : 'Für diesen Etikett-Artikel ist kein Format hinterlegt.';
    var aid = (typeof normalAuf!=='undefined' && normalAuf) ? normalAuf.value : '';
    var dok = (aid && aufEtikett[aid]) ? aufEtikett[aid] : 0;
    var dz=document.getElementById('weEtikettDesign');
    if(dok){
      dz.innerHTML = '<div class="muted" style="font-size:12px;margin-bottom:6px">Design laut Auftrag:</div>'
        + '<iframe src="?p=dokument&id='+dok+'" style="width:100%;max-width:440px;height:320px;border:1px solid var(--line);border-radius:8px;background:#fff" title="Etikett-Design"></iframe>'
        + '<div style="margin-top:6px"><a class="btn btn-ghost btn-sm" target="_blank" href="?p=dokument&id='+dok+'">Design in neuem Tab öffnen</a></div>';
    } else {
      dz.innerHTML = '<div class="muted" style="font-size:12px">Design-Bild: oben bei „Für Auftrag" den passenden Auftrag wählen – dann erscheint hier das freigegebene Etikett-Design zum Vergleich.</div>';
    }
    boxEl.style.display='';
  }
  var katLbl={rohstoff:'Rohstoff',verpackung:'Verpackung',fertig:'Fertigware',verkaufsfertig:'Verkaufsfertig'};
  var hl=-1, shown=[], curKat='rohstoff';   // aktive Typ-Kategorie fuers Filtern
  function esc(s){ return String(s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function render(q){
    q=(q||'').trim().toLowerCase();
    shown = items.filter(function(it){ return (!curKat || it.k===curKat) && (!q || it.n.toLowerCase().indexOf(q)>=0); }).slice(0,50);
    if(!shown.length){ list.innerHTML='<div class="bx-combo-empty">Kein Artikel gefunden.</div>'; }
    else list.innerHTML = shown.map(function(it,i){
      return '<div class="opt" data-i="'+i+'">'+esc(it.n)+' <span class="muted">('+esc(it.e||'')+(it.k&&katLbl[it.k]?' · '+katLbl[it.k]:'')+')</span></div>';
    }).join('');
    hl=-1; list.hidden=false; box.setAttribute('aria-expanded','true');
  }
  function paint(){ Array.prototype.forEach.call(list.querySelectorAll('.opt'),function(o){ o.classList.toggle('hl', +o.dataset.i===hl); }); var el=list.querySelector('.opt.hl'); if(el) el.scrollIntoView({block:'nearest'}); }
  var einhEl=document.getElementById('weEinheit'), einhHint=document.getElementById('weEinheitHint');
  function setEinheit(e){
    if(einhEl) einhEl.textContent = e || '–';
    if(einhHint) einhHint.textContent = e ? ('Menge in ' + e + ' eingeben.') : 'Erst Artikel wählen – die Einheit erscheint hier.';
  }
  function choose(i){ var it=shown[i]; if(!it) return; hid.value=it.id; box.value=it.n; box.setCustomValidity(''); setEinheit(it.e); showEtikett(); close(); }
  function close(){ list.hidden=true; box.setAttribute('aria-expanded','false'); }
  box.addEventListener('input', function(){ hid.value=''; setEinheit(''); showEtikett(); render(box.value); });
  box.addEventListener('focus', function(){ render(box.value); });
  box.addEventListener('keydown', function(e){
    if(list.hidden){ if(e.key==='ArrowDown') render(box.value); return; }
    if(e.key==='ArrowDown'){ e.preventDefault(); hl=Math.min(hl+1,shown.length-1); paint(); }
    else if(e.key==='ArrowUp'){ e.preventDefault(); hl=Math.max(hl-1,0); paint(); }
    else if(e.key==='Enter'){ if(hl>=0){ e.preventDefault(); choose(hl); } }
    else if(e.key==='Escape'){ close(); }
  });
  list.addEventListener('mousedown', function(e){ var o=e.target.closest('.opt'); if(o){ e.preventDefault(); choose(+o.dataset.i); } });
  document.addEventListener('click', function(e){ if(!e.target.closest('#weArtSuche')&&!e.target.closest('#weArtList')) close(); });

  // ---- Typ-Steuerung: Typ zuerst -> passende Eingabe (Artikel ODER Auftrag) ----
  var typSel=document.getElementById('weTyp'), aktionEl=document.getElementById('weAktion');
  var blockArt=document.getElementById('weBlockArt'), blockAuf=document.getElementById('weBlockAuf'), blockFuerAuf=document.getElementById('weBlockFuerAuf');
  var normalAuf=document.getElementById('weNormalAuf'), zukaufAuf=document.getElementById('weZukaufAuf');
  var platzhalter={rohstoff:'Rohstoff suchen…',verpackung:'Verpackung / Etikett suchen…',fertig:'Fertigware (Bulk) suchen…'};
  function applyTyp(){
    var t=typSel.value;
    if(t==='zukauf'){
      curKat='';
      blockArt.style.display='none'; blockFuerAuf.style.display='none'; blockAuf.style.display='';
      box.required=false; box.disabled=true; hid.disabled=true;
      if(normalAuf) normalAuf.disabled=true;
      if(zukaufAuf){ zukaufAuf.disabled=false; zukaufAuf.required=true; }
      aktionEl.value='zukauf_buchen';
      setEinheit(''); if(einhHint) einhHint.textContent='Menge in Stk (Bulk / Kapseln) eingeben.';
    } else {
      curKat=t;
      blockArt.style.display=''; blockFuerAuf.style.display=''; blockAuf.style.display='none';
      box.disabled=false; box.required=true; hid.disabled=false;
      if(normalAuf) normalAuf.disabled=false;
      if(zukaufAuf){ zukaufAuf.disabled=true; zukaufAuf.required=false; }
      aktionEl.value='buchen';
      box.placeholder=platzhalter[t]||'Artikel suchen…';
      // Auswahl zuruecksetzen, damit kein Artikel der falschen Kategorie haengen bleibt
      box.value=''; hid.value=''; setEinheit('');
    }
  }
  if(typSel){ typSel.addEventListener('change', function(){ applyTyp(); showEtikett(); }); applyTyp(); }
  if(normalAuf) normalAuf.addEventListener('change', showEtikett);   // Auftrag gewählt -> Design-Bild nachladen

  if(box.form) box.form.addEventListener('submit', function(e){
    if(typSel && typSel.value==='zukauf') return;   // Zukauf: Auftrag-Select greift (required)
    if((box.value||'').trim()==='') return;   // leer -> HTML5 required greift
    if(!hid.value){ e.preventDefault(); box.setCustomValidity('Bitte einen Artikel aus der Liste wählen.'); box.reportValidity(); }
  });
})();
</script>

<div class="bx-panel">
  <div class="bx-row" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h2 style="margin:0"><?= $nurQuar ? 'Ware in Quarantäne – freigeben' : ($such !== '' ? 'Suchergebnis' : 'Letzte Chargen') ?></h2>
    <form method="get" class="bx-row" style="gap:6px;margin:0" role="search">
      <input type="hidden" name="p" value="wareneingang">
      <input type="text" name="such" value="<?= h($such) ?>" placeholder="Tracking-Code / Charge / Artikel suchen" style="width:280px">
      <button class="btn btn-ghost btn-sm" type="submit">Suchen</button>
      <?php if ($such !== ''): ?><a class="btn btn-ghost btn-sm" href="?p=wareneingang">×</a><?php endif; ?>
      <?php $nQuarAlle = (int) scalar("SELECT COUNT(*) FROM charge WHERE status='quarantaene' AND fremd_kunde_id IS NULL AND menge_verfuegbar>0"); ?>
      <?php if (!$nurQuar): ?><a class="btn btn-ghost btn-sm" href="?p=wareneingang&quarantaene=1">Nur Quarantäne (<?= $nQuarAlle ?>)</a><?php else: ?><a class="btn btn-ghost btn-sm" href="?p=wareneingang">Alle Chargen</a><?php endif; ?>
    </form>
  </div>
  <?php if ($nurQuar): ?><div class="muted" style="font-size:13px;margin-top:6px">Alle Chargen, die noch auf die Freigabe warten<?= $quarItem > 0 ? ' (nur dieser Artikel)' : '' ?>. Nach „freigeben" zählt die Ware sofort als verfügbar. Freigeben darf nur QM oder Admin.<?= !empty($_GET['nurqm']) ? ' <strong style="color:#8f231b">Deine Rolle darf nicht freigeben.</strong>' : '' ?><?= $quarItem > 0 ? ' <a href="?p=wareneingang&quarantaene=1">alle Artikel zeigen</a>' : '' ?></div><?php endif; ?>
  <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
    <thead><tr><th>Charge</th><th>Artikel</th><th class="bx-num">Menge</th><th>Eingang</th><th>MHD</th><th>Lieferant / Herkunft</th><th>Tracking</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$charges): ?><tr><td colspan="9" class="muted"><?= $nurQuar ? 'Keine Ware in Quarantäne.' : ($such !== '' ? 'Kein Treffer zu „' . h($such) . '".' : 'Noch keine Chargen gebucht.') ?></td></tr><?php endif; ?>
    <?php foreach ($charges as $c): $tc = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)($c['tracking'] ?? ''))))); $au = $artikelUrl($c); ?>
      <tr<?= $au !== '' ? ' class="we-rowlink" data-href="' . h($au) . '" style="cursor:pointer"' : '' ?>>
        <td><?= h($c['charge_nr'] ?: '–') ?></td>
        <td><?php if ($au !== '' && ($c['item_name'] ?? '') !== ''): ?><a href="<?= h($au) ?>"><?= h($c['item_name']) ?></a><?php else: ?><?= h($c['item_name'] ?: '–') ?><?php endif; ?></td>
        <td class="bx-num"><?= $mng($c['menge_verfuegbar'], $c['einheit']) ?></td>
        <td><?= !empty($c['wareneingang']) ? h(date('d.m.Y', strtotime((string)$c['wareneingang']))) : '<span class="muted">–</span>' ?></td>
        <td><?= $c['mhd'] ? h(date('d.m.Y', strtotime($c['mhd']))) : '<span class="muted">–</span>' ?></td>
        <td><?= $c['lieferant_firma'] ? h($c['lieferant_firma']) : (!empty($c['pa_nummer']) ? 'Produktion ' . h((string)$c['pa_nummer']) : '<span class="muted">–</span>') ?></td>
        <td><?php if (!$tc): ?><span class="muted">–</span><?php else: ?><span style="font-variant-numeric:tabular-nums"><?= h($tc[0]) ?></span><?php if (count($tc) > 1): ?> <span class="muted" style="font-size:11px" title="<?= h(implode(', ', $tc)) ?>">+<?= count($tc)-1 ?> Pakete</span><?php endif; ?><?php endif; ?></td>
        <td><?= $statusBadge($c['status']) ?></td>
        <td style="text-align:right">
          <?php if ($c['status']==='quarantaene' && !darf_qm_freigeben()): ?>
            <span class="muted" style="font-size:12px">Freigabe durch QM</span>
          <?php elseif ($c['status']==='quarantaene'): ?>
            <form method="post" style="display:inline"><input type="hidden" name="aktion" value="freigeben"><input type="hidden" name="charge_id" value="<?= (int)$c['id'] ?>"><?php if ($nurQuar): ?><input type="hidden" name="quarantaene" value="1"><input type="hidden" name="q_item" value="<?= (int)$quarItem ?>"><?php endif; ?><button class="btn btn-primary btn-sm" type="submit">freigeben</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<script>
(function(){
  var ta=document.getElementById('weTracking'), out=document.getElementById('weTrackCount'); if(!ta||!out) return;
  function upd(){
    var codes=ta.value.split(/[\r\n]+/).map(function(s){return s.trim();}).filter(Boolean);
    out.textContent = codes.length ? codes.length + (codes.length===1?' Paket erfasst':' Pakete erfasst') : '';
  }
  ta.addEventListener('input', upd); upd();
})();
(function(){
  // Teil 4 (Schritt 6): ganze Chargen-Zeile klickbar -> Artikel-Detailseite. Klicks auf Buttons/Links/Formulare ignorieren.
  document.querySelectorAll('tr.we-rowlink').forEach(function(tr){
    tr.addEventListener('click', function(e){
      if (e.target.closest('a,button,input,select,textarea,form,label')) return;
      var href = tr.getAttribute('data-href'); if (href) window.location.href = href;
    });
  });
})();
</script>
<?php render_footer(); ?>
