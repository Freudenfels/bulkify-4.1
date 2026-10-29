<?php
// Doppelte Rezepturen (Teil 3) – reiner READ-ONLY-Report: inhaltlich identische Rezepturen (gleiche Zutaten mit
// mg je Einheit, gleiche Kapselgröße, Füllmenge, Darreichungsform UND gleicher Geschmack). Nichts wird automatisch
// zusammengelegt – die Liste dient Nico zur Entscheidung. Zusammenlegen geschieht je Rezeptur über das Panel
// „Interne Stammrezeptur" (rezeptur/detail.php): dort zeigt eine Rezeptur auf eine gemeinsame Stammrezeptur und
// teilt damit EINEN Bulk-Artikel (rezeptur_bulkitem löst über stamm_rezeptur_id auf).
// Geschmack zählt mit (rezeptur_fingerprint): Schoko/Vanille-Varianten sind keine Dubletten mehr.
require_once BX_ROOT . '/core/ui.php';
require_once BX_ROOT . '/core/schema.php';

// Alle Rezepturen mit Kunde + Stamm-Verknüpfung. „Vorschlag" (noch nicht angenommen) bleibt mit drin,
// wird aber als solcher markiert, damit man es sieht.
$rez = all("SELECT r.id, r.nummer, r.name, r.status, r.kunde_id, r.stamm_rezeptur_id,
                   r.darreichungsform, r.kapselgroesse_id, r.einheit_fuellmenge,
                   k.firma AS kunde_firma
            FROM rezeptur r LEFT JOIN kunden k ON k.id=r.kunde_id
            ORDER BY r.id");

// Nach Fingerabdruck gruppieren (leere Rezepturen liefern '' und fallen raus).
$gruppen = [];
foreach ($rez as $r) {
    $fp = rezeptur_fingerprint((int)$r['id']);
    if ($fp === '') continue;
    $gruppen[$fp][] = $r;
}
// Nur echte Dubletten (>= 2 Rezepturen). Nach Gruppengröße absteigend.
$dubletten = array_filter($gruppen, fn($g) => count($g) >= 2);
uasort($dubletten, fn($a, $b) => count($b) <=> count($a));

// Je Gruppe: schon vollständig über EINE Stammrezeptur verknüpft? (dann nur zur Info, kein Handlungsbedarf)
$stammVon = function(array $g): int {
    $stamms = [];
    foreach ($g as $x) {
        $s = (int)($x['stamm_rezeptur_id'] ?? 0);
        $stamms[] = $s > 0 ? $s : (int)$x['id'];
    }
    $stamms = array_values(array_unique($stamms));
    return count($stamms) === 1 ? $stamms[0] : 0;   // 0 = noch nicht (vollständig) verknüpft
};

$dformLbl = ['kapsel'=>'Kapsel','tablette'=>'Tablette','softgel'=>'Softgel','stick'=>'Stick','pulver'=>'Pulver','fluessig'=>'Flüssig','gel'=>'Gel'];
$geschmackLbl = ['schoko'=>'Schoko','vanille'=>'Vanille','erdbeere'=>'Erdbeere','himbeere'=>'Himbeere','heidelbeere'=>'Heidelbeere',
    'banane'=>'Banane','zitrone'=>'Zitrone','orange'=>'Orange','mango'=>'Mango','ananas'=>'Ananas','kokos'=>'Kokos',
    'karamell'=>'Karamell','haselnuss'=>'Haselnuss','cola'=>'Cola','pfirsich'=>'Pfirsich','apfel'=>'Apfel','kirsche'=>'Kirsche',
    'waldfrucht'=>'Waldfrucht','minze'=>'Minze','cassis'=>'Cassis','maracuja'=>'Maracuja','melone'=>'Melone','traube'=>'Traube',
    'cookies'=>'Cookies','tropic'=>'Tropic','neutral'=>'Neutral'];
$geschmackText = function(string $tok) use ($geschmackLbl): string {
    if ($tok === '') return '';
    return implode(' + ', array_map(fn($t) => $geschmackLbl[$t] ?? ucfirst($t), explode('+', $tok)));
};

// Kapselgrößen-Namen (für „#0", „#00").
$kgName = [];
foreach (all("SELECT id, name FROM kapselgroesse") as $k) $kgName[(int)$k['id']] = (string)$k['name'];

$nz = fn($x) => rtrim(rtrim(number_format((float)$x, 3, ',', '.'), '0'), ',');

// Detaildaten je Rezeptur (nur für die Rezepturen in Dubletten-Gruppen) – für Popup + Vergleich, als JSON an JS.
$detail = [];
foreach ($dubletten as $g) foreach ($g as $r) {
    $rid = (int)$r['id'];
    if (isset($detail[$rid])) continue;
    $zut = all("SELECT z.item_id, COALESCE(NULLIF(i.name,''), z.bezeichnung) AS name, z.menge_mg
                FROM rezeptur_zutat z LEFT JOIN item i ON i.id=z.item_id
                WHERE z.rezeptur_id=? ORDER BY z.sort, z.id", [$rid]);
    $prods = all("SELECT p.name, k.firma AS kunde FROM produkt p LEFT JOIN kunden k ON k.id=p.kunde_id
                  WHERE p.rezeptur_id=? ORDER BY p.id", [$rid]);
    // Requirement 1: eigener Bulk-Artikel dieser Rezeptur (NICHT über Stamm aufgelöst, nichts anlegen) + Bestand.
    $bulkId = (int) scalar("SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig' AND gesperrt=0 ORDER BY id LIMIT 1", [$rid]);
    $bulk = null;
    if ($bulkId > 0) {
        $bchargen = all("SELECT charge_nr, menge_verfuegbar, einheit, status, mhd FROM charge
                         WHERE item_id=? AND menge_verfuegbar>0 ORDER BY (mhd IS NULL), mhd, id", [$bulkId]);
        $bres = all("SELECT r.menge, a.nummer AS auftrag_nr, k.firma AS kunde
                     FROM reservierung r LEFT JOIN auftrag a ON a.id=r.auftrag_id LEFT JOIN kunden k ON k.id=a.kunde_id
                     WHERE r.item_id=? AND r.status='aktiv' ORDER BY r.id DESC", [$bulkId]);
        $bmenge = array_sum(array_map(fn($c) => (float)$c['menge_verfuegbar'], $bchargen));
        $beinheit = (string)($bchargen[0]['einheit'] ?? scalar("SELECT einheit FROM item WHERE id=?", [$bulkId]));
        $bulk = [
            'item_id' => $bulkId,
            'menge'   => $bmenge,
            'einheit' => $beinheit,
            'chargen' => array_map(fn($c) => ['nr'=>(string)$c['charge_nr'], 'menge'=>(float)$c['menge_verfuegbar'],
                            'einheit'=>(string)$c['einheit'], 'status'=>(string)$c['status'],
                            'mhd'=>$c['mhd'] ? date('d.m.Y', strtotime((string)$c['mhd'])) : ''], $bchargen),
            'reservierungen' => array_map(fn($x) => ['menge'=>(float)$x['menge'], 'auftrag'=>(string)($x['auftrag_nr'] ?? ''),
                            'kunde'=>(string)($x['kunde'] ?? '')], $bres),
        ];
    }
    $detail[$rid] = [
        'id'        => $rid,
        'titel'     => trim(((string)$r['nummer'] !== '' ? $r['nummer'] . ' · ' : '') . (string)$r['name']),
        'form'      => $dformLbl[$r['darreichungsform']] ?? (string)$r['darreichungsform'],
        'kapsel'    => (int)($r['kapselgroesse_id'] ?? 0) > 0 ? ('#' . ($kgName[(int)$r['kapselgroesse_id']] ?? (string)(int)$r['kapselgroesse_id'])) : '',
        'fuell'     => ($r['einheit_fuellmenge'] !== null && (float)$r['einheit_fuellmenge'] > 0) ? $nz((float)$r['einheit_fuellmenge']) : '',
        'geschmack' => $geschmackText(rezeptur_geschmack($rid)),
        'kunde'     => (string)($r['kunde_firma'] ?? ''),
        'status'    => (string)$r['status'],
        'zutaten'   => array_map(fn($z) => ['name' => (string)$z['name'], 'mg' => (float)$z['menge_mg']], $zut),
        'produkte'  => array_map(fn($p) => ['name' => (string)$p['name'], 'kunde' => (string)($p['kunde'] ?? '')], $prods),
        'bulk'      => $bulk,
    ];
}

// Gruppen als id-Listen für die Vergleichsansicht (Reihenfolge wie angezeigt).
$gruppenIds = [];
$gi = 0; foreach ($dubletten as $g) { $gi++; $gruppenIds[$gi] = array_map(fn($x) => (int)$x['id'], $g); }

render_header('rezeptur_dubletten', 'Doppelte Rezepturen');
bx_head('Doppelte Rezepturen', count($dubletten) . ' Gruppe(n) inhaltlich identischer Rezepturen',
    bx_btn('Zu den Rezepturen', '?p=rezeptur', 'ghost'));
?>
<style>
#rzOverlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:flex-start;justify-content:center;z-index:9000;padding:24px 12px;overflow:auto}
#rzOverlay.on{display:flex}
#rzBox{background:var(--panel,#fff);color:var(--text,#111);border:1px solid var(--line,#ddd);border-radius:12px;max-width:1100px;width:100%;box-shadow:0 12px 40px rgba(0,0,0,.25)}
#rzHead{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--line,#ddd);position:sticky;top:0;background:var(--panel,#fff);border-radius:12px 12px 0 0}
#rzHead h2{margin:0;font-size:18px}
#rzBody{padding:16px 18px}
#rzClose{cursor:pointer;border:1px solid var(--line,#ddd);background:transparent;border-radius:8px;padding:4px 12px;font-size:15px;line-height:1.4}
.rz-facts{display:flex;flex-wrap:wrap;gap:8px 20px;margin:0 0 14px}
.rz-facts div{min-width:120px}
.rz-facts .lbl{font-size:11px;color:var(--muted,#777)}
.rz-facts .val{font-weight:600}
.rz-diff{background:rgba(214,69,69,.14);border-radius:4px}
.rz-open{cursor:pointer}
#rzBody table{border-collapse:collapse;width:100%}
#rzBody th,#rzBody td{border:1px solid var(--line,#e2e2e2);padding:6px 10px;text-align:left;vertical-align:top;font-size:13px}
#rzBody thead th{background:rgba(0,0,0,.03)}
</style>

<div class="bx-panel">
  <p class="muted" style="margin-top:0">Hier stehen Rezepturen, die <strong>inhaltlich identisch</strong> sind: gleiche Zutaten mit mg je Einheit, gleiche Kapselgröße, Füllmenge, Darreichungsform <strong>und gleicher Geschmack</strong> (Schoko/Vanille-Varianten gelten nicht mehr als Dublette). <strong>Es wird nichts automatisch zusammengelegt.</strong> Eine Rezeptur anklicken öffnet die Details; „Vergleichen" stellt die ganze Gruppe nebeneinander. Zum Zusammenlegen im Detail-Popup die Rezeptur öffnen und im Panel <em>„Interne Stammrezeptur"</em> auf eine gemeinsame Stammrezeptur zeigen lassen.</p>
</div>

<?php if (!$dubletten): ?>
  <div class="bx-panel"><div class="muted">Keine inhaltlich identischen Rezepturen gefunden.</div></div>
<?php else: ?>
  <?php $gi = 0; foreach ($dubletten as $fp => $g): $gi++; $stamm = $stammVon($g); $verknuepft = $stamm > 0; ?>
  <div class="bx-panel">
    <div class="bx-row" style="justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <h2 style="margin:0">Gruppe <?= $gi ?> <span class="muted" style="font-weight:400;font-size:13px">(<?= count($g) ?> Rezepturen · <?= h($dformLbl[$g[0]['darreichungsform']] ?? (string)$g[0]['darreichungsform']) ?><?php $gt = $geschmackText(rezeptur_geschmack((int)$g[0]['id'])); if ($gt !== ''): ?> · <?= h($gt) ?><?php endif; ?>)</span></h2>
      <div class="bx-row" style="gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($verknuepft): ?><span class="badge badge-ok">bereits über eine Stammrezeptur verknüpft</span>
        <?php else: ?><span class="badge badge-warn">noch nicht verknüpft</span><?php endif; ?>
        <button type="button" class="btn btn-ghost btn-sm rz-cmp" data-grp="<?= $gi ?>">Vergleichen</button>
      </div>
    </div>
    <div class="bx-tablewrap" style="margin-top:10px"><table class="bx-table">
      <thead><tr><th>Rezeptur</th><th>Geschmack</th><th>Kunde</th><th>Status</th><th class="bx-num">Bulk-Bestand</th><th>Stammrezeptur</th></tr></thead>
      <tbody>
      <?php foreach ($g as $r): $stammId = (int)($r['stamm_rezeptur_id'] ?? 0);
            $gtr = $geschmackText(rezeptur_geschmack((int)$r['id']));
            $bd = $detail[(int)$r['id']]['bulk'] ?? null; ?>
        <tr>
          <td><a href="#" class="rz-open" data-rz="<?= (int)$r['id'] ?>"><?= h(trim(((string)$r['nummer'] !== '' ? $r['nummer'] . ' · ' : '') . $r['name'])) ?></a></td>
          <td><?= $gtr !== '' ? h($gtr) : '<span class="muted">–</span>' ?></td>
          <td><?= $r['kunde_firma'] ? h((string)$r['kunde_firma']) : '<span class="muted">– (kundenübergreifend)</span>' ?></td>
          <td><?= h((string)$r['status']) ?></td>
          <td class="bx-num"><?= $bd ? h($nz($bd['menge']) . ' ' . $bd['einheit']) : '<span class="muted">–</span>' ?></td>
          <td><?= $stammId > 0 ? '&rarr; #' . $stammId : '<span class="muted">eigenständig</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <?php endforeach; ?>

  <div id="rzOverlay"><div id="rzBox">
    <div id="rzHead"><h2 id="rzTitle"></h2><button id="rzClose" type="button">Schließen</button></div>
    <div id="rzBody"></div>
  </div></div>

  <script>
  (function(){
    var REZ = <?= json_encode($detail, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    var GRP = <?= json_encode($gruppenIds, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    var ov = document.getElementById('rzOverlay'), box = document.getElementById('rzBox');
    var title = document.getElementById('rzTitle'), body = document.getElementById('rzBody');
    function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
    function mg(x){ if(x==null||x==='') return ''; var v=Math.round(x*1000)/1000, neg=v<0; v=Math.abs(v); var p=v.toString().split('.'); p[0]=p[0].replace(/\B(?=(\d{3})+(?!\d))/g,'.'); return (neg?'-':'')+p.join(','); }
    function open(){ ov.classList.add('on'); }
    function close(){ ov.classList.remove('on'); }
    document.getElementById('rzClose').addEventListener('click', close);
    ov.addEventListener('click', function(e){ if(e.target===ov) close(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });

    function fact(lbl,val){ if(!val) return ''; return '<div><div class="lbl">'+esc(lbl)+'</div><div class="val">'+esc(val)+'</div></div>'; }

    function detailHtml(d){
      var h = '<div class="rz-facts">';
      h += fact('Form', d.form);
      h += fact('Kapselgröße', d.kapsel);
      h += fact('Füllgewicht je Einheit', d.fuell ? (d.fuell+' g') : '');
      h += fact('Geschmack', d.geschmack);
      h += fact('Status', d.status);
      h += fact('Kunde', d.kunde);
      if (d.bulk) h += fact('Bulk-Bestand', mg(d.bulk.menge) + ' ' + d.bulk.einheit);
      h += '</div>';
      // Requirement 1: Bulk-Bestand (Menge, Chargen, Reservierungen) des eigenen Bulk-Artikels.
      h += '<h3 style="margin:14px 0 6px;font-size:14px">Bulk-Bestand</h3>';
      if (!d.bulk) {
        h += '<div class="muted" style="font-size:13px">Kein eigener Bulk-Artikel – diese Rezeptur teilt den Bulk ggf. über die Stammrezeptur.</div>';
      } else {
        h += '<div style="margin-bottom:6px">Verfügbar: <strong>' + mg(d.bulk.menge) + ' ' + esc(d.bulk.einheit) + '</strong></div>';
        if (d.bulk.chargen.length) {
          h += '<table><thead><tr><th>Charge</th><th style="text-align:right">Menge</th><th>Status</th><th>MHD</th></tr></thead><tbody>';
          d.bulk.chargen.forEach(function(c){ h += '<tr><td>' + esc(c.nr||'–') + '</td><td style="text-align:right">' + mg(c.menge) + ' ' + esc(c.einheit) + '</td><td>' + esc(c.status) + '</td><td>' + esc(c.mhd||'–') + '</td></tr>'; });
          h += '</tbody></table>';
        } else { h += '<div class="muted" style="font-size:13px">Keine Chargen mit Bestand.</div>'; }
        if (d.bulk.reservierungen.length) {
          h += '<div style="margin-top:8px;font-size:13px"><strong>Reservierungen:</strong><ul style="margin:4px 0 0;padding-left:18px">';
          d.bulk.reservierungen.forEach(function(x){ h += '<li>' + mg(x.menge) + ' ' + esc(d.bulk.einheit) + (x.auftrag?(' · ' + esc(x.auftrag)):'') + (x.kunde?(' · ' + esc(x.kunde)):'') + '</li>'; });
          h += '</ul></div>';
        }
      }
      h += '<h3 style="margin:14px 0 6px;font-size:14px">Zutaten je Einheit</h3>';
      if(d.zutaten.length){
        h += '<table><thead><tr><th>Zutat</th><th style="text-align:right">mg je Einheit</th></tr></thead><tbody>';
        d.zutaten.forEach(function(z){ h += '<tr><td>'+esc(z.name)+'</td><td style="text-align:right">'+mg(z.mg)+'</td></tr>'; });
        h += '</tbody></table>';
      } else { h += '<div class="muted">Keine Zutaten hinterlegt.</div>'; }
      if(d.produkte.length){
        h += '<h3 style="margin:14px 0 6px;font-size:14px">Zugehörige Produkte / Kunden</h3><ul style="margin:0;padding-left:18px">';
        d.produkte.forEach(function(p){ h += '<li>'+esc(p.name)+(p.kunde?(' <span class="muted">· '+esc(p.kunde)+'</span>'):'')+'</li>'; });
        h += '</ul>';
      }
      h += '<div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">'
         + '<a class="btn btn-primary btn-sm" href="?p=rezeptur_detail&id='+d.id+'">Rezeptur öffnen</a>'
         + '<a class="btn btn-ghost btn-sm" href="?p=rezeptur_detail&id='+d.id+'#stamm">Stammrezeptur setzen</a></div>';
      return h;
    }

    function openDetail(id){ var d=REZ[id]; if(!d) return; title.textContent=d.titel; body.innerHTML=detailHtml(d); box.scrollTop=0; open(); }

    function compareHtml(ids){
      var ds = ids.map(function(i){return REZ[i];}).filter(Boolean);
      // Zutaten-Union (nach kleingeschriebenem Namen), Reihenfolge der ersten Rezeptur zuerst.
      var order=[], seen={};
      ds.forEach(function(d){ d.zutaten.forEach(function(z){ var k=z.name.toLowerCase(); if(!(k in seen)){seen[k]=z.name; order.push(k);} }); });
      function cell(vals, idx){
        var v=vals[idx]||'', diff=false;
        for(var j=0;j<vals.length;j++){ if((vals[j]||'')!==(vals[0]||'')){ diff=true; break; } }
        return '<td'+(diff && (vals[idx]||'')!==(vals[0]||'')?' class="rz-diff"':'')+'>'+esc(v)+'</td>';
      }
      function row(lbl, vals){
        var h='<tr><th>'+esc(lbl)+'</th>';
        for(var i=0;i<vals.length;i++) h+=cell(vals,i);
        return h+'</tr>';
      }
      var h='<table><thead><tr><th>Merkmal</th>';
      ds.forEach(function(d){ h+='<th>'+esc(d.titel)+'</th>'; });
      h+='</tr></thead><tbody>';
      h+=row('Kunde', ds.map(function(d){return d.kunde||'–';}));
      h+=row('Produkte', ds.map(function(d){return d.produkte.map(function(p){return p.name;}).join(', ')||'–';}));
      h+=row('Status', ds.map(function(d){return d.status;}));
      h+=row('Form', ds.map(function(d){return d.form;}));
      h+=row('Kapselgröße', ds.map(function(d){return d.kapsel||'–';}));
      h+=row('Füllgewicht', ds.map(function(d){return d.fuell?(d.fuell+' g'):'–';}));
      h+=row('Geschmack', ds.map(function(d){return d.geschmack||'–';}));
      h+=row('Bulk-Bestand', ds.map(function(d){return d.bulk ? (mg(d.bulk.menge)+' '+d.bulk.einheit) : '–';}));
      order.forEach(function(k){
        h+=row(seen[k], ds.map(function(d){ var f=''; d.zutaten.forEach(function(z){ if(z.name.toLowerCase()===k) f=mg(z.mg); }); return f||'–'; }));
      });
      h+='</tbody></table>';
      h+='<p class="muted" style="margin-top:10px;font-size:12px">Rot markiert = weicht vom Wert der ersten Rezeptur ab. Zum Öffnen einer einzelnen Rezeptur das Detail-Popup nutzen.</p>';
      return h;
    }

    function openCompare(gi){ var ids=GRP[gi]; if(!ids) return; title.textContent='Gruppe '+gi+' vergleichen ('+ids.length+' Rezepturen)'; body.innerHTML=compareHtml(ids); box.scrollTop=0; open(); }

    document.querySelectorAll('.rz-open').forEach(function(a){ a.addEventListener('click', function(e){ e.preventDefault(); openDetail(this.getAttribute('data-rz')); }); });
    document.querySelectorAll('.rz-cmp').forEach(function(b){ b.addEventListener('click', function(){ openCompare(this.getAttribute('data-grp')); }); });
  })();
  </script>
<?php endif; ?>
<?php render_footer();
