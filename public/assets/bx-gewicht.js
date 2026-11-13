/* bx-gewicht.js – Rückfrage bei mehrdeutigen Gewichten (Fable-4 T1.3, 13.11.2026).
 * Ein Gewichtsfeld trägt data-gewicht="kg" (bzw. "g"). Steht darin genau „1-3 Ziffern, Punkt, 3 Ziffern“ ohne Einheit
 * („1.250“), ist unklar, ob 1,25 kg oder 1.250 kg gemeint ist. Dann wird NICHT abgeschickt, sondern gefragt:
 * „1.250 - gemeint 1,25 kg oder 1.250 kg?“ mit zwei großen Knöpfen. Die Antwort schreibt den Wert eindeutig ins Feld
 * („1,25“ bzw. „1250“) und schickt das Formular ab. Gleiche Regel wie gewicht_mehrdeutig() in core/gewicht.php.
 * Geladen von bx-ui.js (alle Layouts) und direkt in der Werk-App. */
(function () {
  if (window.bxGewichtInit) return;
  window.bxGewichtInit = true;
  var MUSTER = /^\s*(-?\d{1,3})\.(\d{3})\s*$/;
  // Texte: Werk-App setzt window.bxGewichtTexte (DE/EN/UK), sonst Deutsch. %1 = Eingabe, %2/%3 = die beiden Lesarten.
  function tx(k) {
    var d = { frage: '%1 - gemeint %2 oder %3?', hinweis: 'Ein Punkt mit drei Ziffern ist nicht eindeutig. Gebucht wird erst nach der Auswahl.', abbrechen: 'Abbrechen' };
    var w = window.bxGewichtTexte || {};
    return w[k] || d[k];
  }

  function zahlDe(ganz, rest) {   // „1“,„250“ -> „1,25“; „12“,„000“ -> „12“
    var r = rest.replace(/0+$/, '');
    return r ? ganz + ',' + r : ganz;
  }
  function knopf(text, haupt) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = text;
    b.style.cssText = 'flex:1;min-width:150px;min-height:64px;font-size:22px;font-weight:700;border-radius:14px;cursor:pointer;padding:12px 18px;'
      + (haupt ? 'background:var(--lime,#C0F24E);color:#10210f;border:0' : 'background:transparent;color:inherit;border:1px solid rgba(128,128,128,.5)');
    return b;
  }
  function frage(feld, m, fertig) {
    var einheit = feld.getAttribute('data-gewicht') || 'kg';
    var klein = zahlDe(m[1], m[2]), gross = m[1] + m[2];
    var grossTxt = m[1] + '.' + m[2];
    var hg = document.createElement('div');
    hg.className = 'bx-gewfrage';
    hg.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;padding:16px';
    var box = document.createElement('div');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    // Farben wie die Seite (Dashboard hell, Werk-App dunkel): Hintergrund und Schrift vom <body>.
    var cs = window.getComputedStyle ? getComputedStyle(document.body) : null;
    var bg = cs && cs.backgroundColor && cs.backgroundColor !== 'rgba(0, 0, 0, 0)' ? cs.backgroundColor : '#ffffff';
    box.style.cssText = 'background:' + bg + ';color:' + (cs ? cs.color : '#10210f') + ';border:1px solid rgba(128,128,128,.35);border-radius:18px;padding:22px;max-width:560px;width:100%;box-shadow:0 18px 60px rgba(0,0,0,.45)';
    var t = document.createElement('div');
    t.style.cssText = 'font-size:22px;font-weight:700;margin-bottom:6px';
    t.textContent = tx('frage').replace('%1', grossTxt).replace('%2', klein + ' ' + einheit).replace('%3', grossTxt + ' ' + einheit);
    var u = document.createElement('div');
    u.style.cssText = 'font-size:14px;opacity:.75;margin-bottom:16px';
    var c = feld.closest ? feld.closest('.bx-field') : null;
    var l = (feld.labels && feld.labels[0]) || (c && c.querySelector('label'));
    var lab = l ? (l.firstChild && l.firstChild.nodeType === 3 ? l.firstChild.textContent : l.textContent).trim() : (feld.getAttribute('placeholder') || '');
    u.textContent = (lab ? lab + ': ' : '') + tx('hinweis');
    var reihe = document.createElement('div');
    reihe.style.cssText = 'display:flex;gap:12px;flex-wrap:wrap';
    var b1 = knopf(klein + ' ' + einheit, true), b2 = knopf(grossTxt + ' ' + einheit, true), ab = knopf(tx('abbrechen'), false);
    ab.style.minHeight = '48px'; ab.style.fontSize = '16px'; ab.style.flex = '0 0 100%';
    reihe.appendChild(b1); reihe.appendChild(b2); reihe.appendChild(ab);
    box.appendChild(t); box.appendChild(u); box.appendChild(reihe);
    hg.appendChild(box);
    document.body.appendChild(hg);
    function zu() { hg.remove(); document.removeEventListener('keydown', esc, true); }
    function esc(e) { if (e.key === 'Escape') { e.preventDefault(); zu(); feld.focus(); } }
    document.addEventListener('keydown', esc, true);
    b1.onclick = function () { feld.value = klein; zu(); fertig(); };
    b2.onclick = function () { feld.value = gross; zu(); fertig(); };
    ab.onclick = function () { zu(); feld.focus(); };
    b1.focus();
  }
  function erstesMehrdeutig(f) {
    var felder = f.querySelectorAll('[data-gewicht]');
    for (var i = 0; i < felder.length; i++) {
      var el = felder[i];
      if (el.disabled || el.type === 'hidden') continue;
      var m = MUSTER.exec(el.value || '');
      if (m) return { el: el, m: m };
    }
    return null;
  }
  // Prüfen vor dem Absenden (Capture: vor Ladebalken/Doppelklick-Schutz und vor bx-ui-Ajax).
  window.bxGewichtPruefen = function (f, weiter) {
    var x = erstesMehrdeutig(f);
    if (!x) return true;
    frage(x.el, x.m, function () { if (window.bxGewichtPruefen(f, weiter)) weiter(); });
    return false;
  };
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM') return;
    var sub = e.submitter || null;
    if (window.bxGewichtPruefen(f, function () { if (f.requestSubmit) f.requestSubmit(sub && sub.form === f ? sub : undefined); else f.submit(); })) return;
    e.preventDefault();
    e.stopImmediatePropagation();
  }, true);
})();
