<?php
// Werkzeug: D3K2-Tropfen-Rechner (Schnellberechnung). Reine Lookup-/Rechenseite, keine DB.
// Gib vor, wie viel D3 (IE) und K2 (µg) in einem Tropfen bzw. pro ml sein soll -> der Rechner
// zeigt, wie viel ml D3-Rohstoff, K2-Rohstoff und Trägeröl für die Charge gebraucht werden.
// Rechnet vollständig im Browser (JS), damit es sofort reagiert. Zahlen in de-DE-Format.
require_once BX_ROOT . '/core/ui.php';

render_header('d3k2_rechner', 'D3K2-Rechner');
bx_head('D3K2-Tropfen-Rechner',
        'Vorgabe je Tropfen oder je ml -> benötigte Mengen D3-Rohstoff, K2-Rohstoff und Trägeröl',
        bx_btn('Zurück', '?p=einstellungen&tab=produktion', 'ghost'));
?>
<style>
/* Seiten-lokal, nur Theme-Variablen -> Dark Mode greift automatisch. */
.d3k2-cols{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,7fr);gap:var(--sp-5);align-items:start}
@media (max-width:860px){.d3k2-cols{grid-template-columns:1fr}}
.d3k2-stack{display:grid;gap:var(--sp-5);min-width:0}
.d3k2-h2{font-size:var(--fs-xs);font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--muted);margin:0 0 var(--sp-3)}
.d3k2-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--sp-3)}
.d3k2-unit{color:var(--muted);font-size:var(--fs-sm)}
.d3k2-fieldhint{color:var(--muted);font-size:var(--fs-sm);margin-top:4px}
.d3k2-batch{color:var(--muted);margin:-4px 0 var(--sp-3)}
.d3k2-msg{padding:9px 12px;border-radius:var(--r-sm);font-size:var(--fs);margin-bottom:var(--sp-2)}
.d3k2-msg.warn{background:rgba(201,133,43,.14);color:var(--warn);border:1px solid rgba(201,133,43,.35)}
.d3k2-msg.err{background:rgba(192,57,43,.12);color:var(--err);border:1px solid rgba(192,57,43,.35)}
#d3k2-msgs:empty{display:none}
.d3k2-big td:nth-child(2){font-size:var(--fs-lg);font-weight:500;color:var(--gruen)}
.d3k2-sum td{font-weight:500}
.d3k2-sub{display:block;color:var(--muted);font-size:var(--fs-sm);font-weight:400}
details.d3k2-ov > summary{cursor:pointer;color:var(--gruen);font-weight:500;list-style:none}
details.d3k2-ov > summary::-webkit-details-marker{display:none}
details.d3k2-ov > summary::after{content:" \25BE";color:var(--muted)}
details.d3k2-ov[open] > summary{margin-bottom:var(--sp-3)}
</style>

<div class="d3k2-cols">
  <form class="bx-panel" id="d3k2-form" autocomplete="off">
    <div>
      <div class="d3k2-h2">Deine Vorgabe</div>
      <div class="bx-field">
        <label for="d3k2-mode">Eingabe bezieht sich auf</label>
        <select id="d3k2-mode">
          <option value="drop" selected>pro Tropfen</option>
          <option value="ml">pro ml</option>
        </select>
      </div>
      <div class="d3k2-two">
        <div class="bx-field">
          <label for="d3">Vitamin D3 <span class="d3k2-unit">(IE)</span></label>
          <input id="d3" type="number" min="0" step="any" inputmode="decimal" value="1000">
          <div class="d3k2-fieldhint" id="d3hint"></div>
        </div>
        <div class="bx-field">
          <label for="k2">Vitamin K2 <span class="d3k2-unit">(µg)</span></label>
          <input id="k2" type="number" min="0" step="any" inputmode="decimal" value="20">
          <div class="d3k2-fieldhint" id="k2hint"></div>
        </div>
      </div>
    </div>

    <div>
      <div class="d3k2-h2">Menge</div>
      <div class="bx-field">
        <label for="dpm">Tropfen pro ml</label>
        <input id="dpm" type="number" min="0" step="any" inputmode="decimal" value="30">
        <div class="d3k2-fieldhint">Hängt vom Tropfer und Öl ab. Am besten mit dem Tropfer auszählen.</div>
      </div>
      <div class="d3k2-two">
        <div class="bx-field">
          <label for="ml">Flaschengröße <span class="d3k2-unit">(ml)</span></label>
          <input id="ml" type="number" min="0" step="any" inputmode="decimal" value="30">
        </div>
        <div class="bx-field">
          <label for="n">Anzahl Flaschen <span class="d3k2-unit">(Stk)</span></label>
          <input id="n" type="number" min="0" step="any" inputmode="decimal" value="1">
        </div>
      </div>
    </div>

    <div>
      <div class="d3k2-h2">Rohstoffe</div>
      <div class="bx-field">
        <label for="d3k2-basis">Gehalt der Rohstoffe ist angegeben</label>
        <select id="d3k2-basis">
          <option value="ml" selected>pro ml</option>
          <option value="g">pro g</option>
        </select>
        <div class="d3k2-fieldhint">Steht im Analysenzertifikat oder in der Spezifikation des Rohstoffs.</div>
      </div>
      <div class="bx-field" id="rawDensField" hidden>
        <label for="rawDens">Dichte der Rohstoffe <span class="d3k2-unit">(g/ml)</span></label>
        <input id="rawDens" type="number" min="0" step="any" inputmode="decimal" value="0.945">
        <div class="d3k2-fieldhint">Rechnet Gramm in ml um. 0,945 g/ml entspricht ungefähr MCT-Öl.</div>
      </div>
      <div class="d3k2-two">
        <div class="bx-field">
          <label for="d3k2-str">Vitamin D3 Rohstoff</label>
          <select id="d3k2-str">
            <option value="100000">100.000 IE</option>
            <option value="1000000" selected>1.000.000 IE</option>
          </select>
        </div>
        <div class="bx-field">
          <label for="k2pct">Vitamin K2 Rohstoff MK-7 <span class="d3k2-unit">(%)</span></label>
          <input id="k2pct" type="number" min="0" step="any" inputmode="decimal" value="1">
        </div>
      </div>
      <details class="d3k2-ov">
        <summary>Überdosierung</summary>
        <div class="d3k2-two">
          <div class="bx-field">
            <label for="ovD3">Überdosierung D3 <span class="d3k2-unit">(%)</span></label>
            <input id="ovD3" type="number" min="0" step="any" inputmode="decimal" value="0">
          </div>
          <div class="bx-field">
            <label for="ovK2">Überdosierung K2 <span class="d3k2-unit">(%)</span></label>
            <input id="ovK2" type="number" min="0" step="any" inputmode="decimal" value="0">
          </div>
        </div>
      </details>
    </div>
  </form>

  <div class="d3k2-stack" aria-live="polite">
    <section class="bx-panel">
      <div class="d3k2-h2">Das brauchst du</div>
      <div class="d3k2-batch" id="batch"></div>
      <div id="d3k2-msgs"></div>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Rohstoff</th><th class="bx-num">Gesamt</th><th class="bx-num">pro Flasche</th><th class="bx-num">Anteil</th></tr></thead>
        <tbody>
          <tr class="d3k2-big"><td>Vitamin D3<span class="d3k2-sub" id="d3name"></span></td><td class="bx-num" id="gD3"></td><td class="bx-num" id="fD3"></td><td class="bx-num" id="pD3"></td></tr>
          <tr class="d3k2-big"><td>Vitamin K2 MK-7<span class="d3k2-sub" id="k2name"></span></td><td class="bx-num" id="gK2"></td><td class="bx-num" id="fK2"></td><td class="bx-num" id="pK2"></td></tr>
          <tr class="d3k2-big"><td>Trägeröl<span class="d3k2-sub">auffüllen auf Gesamtvolumen</span></td><td class="bx-num" id="gOil"></td><td class="bx-num" id="fOil"></td><td class="bx-num" id="pOil"></td></tr>
          <tr class="d3k2-sum"><td>Summe</td><td class="bx-num" id="gSum"></td><td class="bx-num" id="fSum"></td><td class="bx-num">100,00 %</td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="bx-panel">
      <div class="d3k2-h2">Gehalt im Produkt</div>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>Wirkstoff</th><th class="bx-num">pro Tropfen</th><th class="bx-num">pro ml</th><th class="bx-num">pro Flasche</th></tr></thead>
        <tbody>
          <tr><td>Vitamin D3 in IE</td><td class="bx-num" id="a1"></td><td class="bx-num" id="a2"></td><td class="bx-num" id="a3"></td></tr>
          <tr><td>Vitamin D3 in µg</td><td class="bx-num" id="b1"></td><td class="bx-num" id="b2"></td><td class="bx-num" id="b3"></td></tr>
          <tr><td>Vitamin D3 in % NRV</td><td class="bx-num" id="n1"></td><td class="bx-num" id="n2"></td><td class="bx-num">–</td></tr>
          <tr><td>Vitamin K2 in µg</td><td class="bx-num" id="c1"></td><td class="bx-num" id="c2"></td><td class="bx-num" id="c3"></td></tr>
          <tr><td>Vitamin K2 in % NRV</td><td class="bx-num" id="m1"></td><td class="bx-num" id="m2"></td><td class="bx-num">–</td></tr>
        </tbody>
      </table></div>
      <div class="d3k2-fieldhint" id="drops"></div>
    </section>

    <section class="bx-panel">
      <div class="d3k2-h2">Beide D3-Rohstoffe im Vergleich</div>
      <div class="bx-tablewrap"><table class="bx-table">
        <thead><tr><th>D3-Rohstoff</th><th class="bx-num">D3 Menge</th><th class="bx-num">Trägeröl</th></tr></thead>
        <tbody>
          <tr id="cmp100"><td>100.000 IE</td><td class="bx-num" id="c100d"></td><td class="bx-num" id="c100o"></td></tr>
          <tr id="cmp1m"><td>1.000.000 IE</td><td class="bx-num" id="c1md"></td><td class="bx-num" id="c1mo"></td></tr>
        </tbody>
      </table></div>
    </section>
  </div>
</div>

<p class="muted" style="font-size:var(--fs-sm);max-width:70ch;margin-top:var(--sp-5)">
  Rechenbasis: 1 µg D3 = 40 IE, 1 % MK-7 = 10.000 µg pro ml bzw. pro g Rohstoff (je nach Auswahl).
  Das Trägeröl füllt das restliche Volumen auf. NRV nach LMIV Anhang XIII: Vitamin D 5 µg, Vitamin K 75 µg.
  Der Gehalt im Produkt ist ohne Überdosierung angegeben. Maßgeblich ist der Gehalt im Analysenzertifikat der Charge.
</p>

<script>
(function(){
  var $ = function(id){ return document.getElementById(id); };
  var IU_PER_UG = 40, NRV_D_UG = 5, NRV_K_UG = 75;

  function calc(i){
    var f = i.mode === 'drop' ? i.dpm : 1;
    var d3Ml = i.d3 * f, k2Ml = i.k2 * f;
    var vol = i.ml * i.n;
    var toMl = function(v){ return i.basis === 'g' ? v / i.rawDens : v; };
    var mlD3For = function(s){ return toMl(d3Ml * vol * (1 + i.ovD3 / 100) / s); };
    var k2PerUnit = i.k2Pct / 100 * 1e6;
    var mlD3 = mlD3For(i.strength);
    var mlK2 = toMl(k2Ml * vol * (1 + i.ovK2 / 100) / k2PerUnit);
    return { d3Ml: d3Ml, k2Ml: k2Ml, vol: vol, mlD3: mlD3, mlK2: mlK2, mlOil: vol - mlD3 - mlK2,
      alt: function(s){ var d = mlD3For(s); return { d: d, oil: vol - d - mlK2 }; } };
  }

  var nf = function(v, min, max){ return v.toLocaleString('de-DE', { minimumFractionDigits: min, maximumFractionDigits: max }); };
  var auto = function(v){ return nf(v, 0, Math.abs(v) >= 100 ? 0 : Math.abs(v) >= 1 ? 2 : 4); };
  var mlFmt = function(v){ var d = Math.abs(v) >= 100 ? 1 : 3; return nf(v, d, d) + ' ml'; };
  var nrvPct = function(ug, ref){ var p = ug / ref * 100; return nf(p, 0, p >= 100 ? 0 : 1) + ' %'; };

  var inputs = function(){ return {
    mode: $('d3k2-mode').value,
    strength: +$('d3k2-str').value,
    basis: $('d3k2-basis').value,
    d3: parseFloat($('d3').value), k2: parseFloat($('k2').value), dpm: parseFloat($('dpm').value),
    ml: parseFloat($('ml').value), n: parseFloat($('n').value), k2Pct: parseFloat($('k2pct').value),
    ovD3: parseFloat($('ovD3').value), ovK2: parseFloat($('ovK2').value), rawDens: parseFloat($('rawDens').value)
  }; };

  var IDS = ['gD3','fD3','pD3','gK2','fK2','pK2','gOil','fOil','pOil','gSum','fSum','a1','a2','a3','b1','b2','b3','n1','n2','c1','c2','c3','m1','m2','c100d','c100o','c1md','c1mo'];

  function render(){
    var i = inputs();
    $('rawDensField').hidden = i.basis !== 'g';
    var unit = i.mode === 'drop' ? 'Tropfen' : 'ml';
    document.querySelector('label[for=d3]').innerHTML = 'Vitamin D3 pro ' + unit + ' <span class="d3k2-unit">(IE)</span>';
    document.querySelector('label[for=k2]').innerHTML = 'Vitamin K2 pro ' + unit + ' <span class="d3k2-unit">(µg)</span>';

    var nonNeg = [i.d3, i.k2, i.ovD3, i.ovK2], pos = [i.dpm, i.ml, i.n, i.k2Pct, i.rawDens];
    var msgs = $('d3k2-msgs');
    msgs.textContent = '';
    var add = function(kind, text){ var d = document.createElement('div'); d.className = 'd3k2-msg ' + kind; d.textContent = text; msgs.appendChild(d); };
    var bad = function(v){ return !(v >= 0); }, nonpos = function(v){ return !(v > 0); };

    if (nonNeg.some(bad) || pos.some(nonpos)) {
      IDS.forEach(function(id){ $(id).textContent = '–'; });
      $('batch').textContent = ''; $('drops').textContent = ''; $('d3hint').textContent = ''; $('k2hint').textContent = '';
      add('err', 'Bitte alle Felder ausfüllen. Tropfen, Flaschen, Gehalt und Dichte müssen größer als 0 sein.');
      return;
    }

    var r = calc(i);
    $('d3hint').textContent = '= ' + auto(i.d3 / IU_PER_UG) + ' µg, ' + nrvPct(i.d3 / IU_PER_UG, NRV_D_UG) + ' NRV';
    $('k2hint').textContent = '= ' + nrvPct(i.k2, NRV_K_UG) + ' NRV';
    $('batch').textContent = auto(i.n) + ' × ' + auto(i.ml) + ' ml = ' + mlFmt(r.vol) + ' Gesamtvolumen';
    $('d3name').textContent = i.strength.toLocaleString('de-DE') + ' IE/' + i.basis;
    $('k2name').textContent = auto(i.k2Pct) + ' % MK-7';
    [['gD3','pD3',r.mlD3],['gK2','pK2',r.mlK2],['gOil','pOil',r.mlOil]].forEach(function(row){
      $(row[0]).textContent = mlFmt(row[2]); $(row[1]).textContent = nf(row[2] / r.vol * 100, 2, 2) + ' %';
    });
    $('gSum').textContent = mlFmt(r.vol);
    [['fD3',r.mlD3],['fK2',r.mlK2],['fOil',r.mlOil],['fSum',r.vol]].forEach(function(row){ $(row[0]).textContent = mlFmt(row[1] / i.n); });

    var cols = [1 / i.dpm, 1, i.ml];
    cols.forEach(function(m, c){
      $('a' + (c + 1)).textContent = auto(r.d3Ml * m);
      $('b' + (c + 1)).textContent = auto(r.d3Ml * m / IU_PER_UG);
      $('c' + (c + 1)).textContent = auto(r.k2Ml * m);
      if (c < 2) {
        $('n' + (c + 1)).textContent = nrvPct(r.d3Ml * m / IU_PER_UG, NRV_D_UG);
        $('m' + (c + 1)).textContent = nrvPct(r.k2Ml * m, NRV_K_UG);
      }
    });
    $('drops').textContent = 'Eine Flasche enthält etwa ' + auto(i.ml * i.dpm) + ' Tropfen.';

    var a = r.alt(100000), b = r.alt(1000000);
    $('c100d').textContent = mlFmt(a.d); $('c100o').textContent = a.oil < 0 ? 'passt nicht' : mlFmt(a.oil);
    $('c1md').textContent = mlFmt(b.d);  $('c1mo').textContent = b.oil < 0 ? 'passt nicht' : mlFmt(b.oil);
    $('cmp100').classList.toggle('sel', i.strength === 100000);
    $('cmp1m').classList.toggle('sel', i.strength === 1000000);

    if (r.mlOil < 0) add('err', 'Die Rohstoffe brauchen mehr Volumen als die fertige Mischung hat. Senke die Dosierung oder nimm einen stärkeren Rohstoff.');
    if (r.mlD3 > 0 && r.mlD3 < 0.1) add('warn', 'Die D3-Menge liegt unter 0,1 ml. Nimm den 100.000 IE Rohstoff oder arbeite mit einer Vorverdünnung.');
    if (r.mlK2 > 0 && r.mlK2 < 0.1) add('warn', 'Die K2-Menge liegt unter 0,1 ml. Arbeite besser mit einer Vorverdünnung.');
  }

  function switchMode(){
    var i = inputs();
    if (i.dpm > 0) {
      var f = i.mode === 'drop' ? 1 / i.dpm : i.dpm;
      ['d3', 'k2'].forEach(function(id){ var v = parseFloat($(id).value); if (v >= 0) $(id).value = +(v * f).toFixed(4); });
    }
    render();
  }

  $('d3k2-form').addEventListener('submit', function(e){ e.preventDefault(); });
  $('d3k2-form').addEventListener('input', function(e){ if (e.target.id !== 'd3k2-mode') render(); });
  $('d3k2-str').addEventListener('change', render);
  $('d3k2-basis').addEventListener('change', render);
  $('d3k2-mode').addEventListener('change', switchMode);
  render();
})();
</script>
<?php render_footer(); ?>
