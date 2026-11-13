/* bx-pflicht.js – Pflichtfelder (Oberflächen-Standard, Nico 13.11.2026). Geladen von bx-ui.js.
 *
 * Ein Feld mit data-pflicht ist Pflicht:
 *   data-pflicht=""                       immer
 *   data-pflicht="#weGrund=alt_bestellung" nur wenn das Feld #weGrund diesen Wert hat (mehrere Werte mit Komma)
 *   data-pflicht="#weOhne:checked"         nur wenn die Checkbox #weOhne angehakt ist
 * Optional: data-pflicht-text="Lieferant fehlt" (sonst „<Beschriftung> fehlt“), data-pflicht-zeige="#weArtSuche"
 * (bei versteckten Feldern, z. B. item_id hinter einer Suche: dieses Feld wird rot markiert und fokussiert).
 *
 * - Die Beschriftung bekommt ein Sternchen, das sofort mit der Bedingung wechselt.
 * - Vor dem Absenden wird geprüft: fehlt etwas, wird NICHT gesendet; das Feld wird rot, darunter steht „… fehlt“,
 *   der Cursor springt hinein.
 * - Server-Fehler: Felder mit class="bx-pflicht-fehlt" und data-pflicht-msg="…" zeigt der Baustein beim Laden genauso an
 *   (die Seite kommt mit allen Eingaben zurück, nie ein Redirect ohne Daten).
 */
(function () {
  if (window.bxPflichtInit) return;
  window.bxPflichtInit = true;
  var st = document.createElement('style');
  st.textContent = '.bx-pflicht-fehlt{border-color:#c0392b !important;box-shadow:0 0 0 2px rgba(192,57,43,.18) !important}'
    + '.bx-pflicht-msg{color:#c0392b;font-size:13px;font-weight:600;margin-top:4px}.bx-stern{color:#c0392b;margin-left:3px}';
  document.head.appendChild(st);

  function bedingung(el) {
    var b = (el.getAttribute('data-pflicht') || '').trim();
    if (b === '') return true;
    var f = el.form || document;
    var m = /^(#[\w-]+)\s*:\s*checked$/.exec(b);
    if (m) { var c = document.querySelector(m[1]); return !!(c && c.checked); }
    m = /^(#[\w-]+)\s*=\s*(.*)$/.exec(b);
    if (m) { var s = document.querySelector(m[1]); if (!s || !sichtbar(s)) return false; return m[2].split(',').map(function (x) { return x.trim(); }).indexOf(String(s.value)) >= 0; }
    return true;
  }
  function sichtbar(el) { var z = zeigeEl(el); return !!(z && (z.offsetParent !== null || z.getClientRects().length)); }
  // Sichtbares Gegenstück: data-pflicht-zeige, sonst bei einer Auswahl mit Suche (rs-combo) das Suchfeld daneben.
  function zeigeEl(el) {
    var z = el.getAttribute('data-pflicht-zeige'); if (z) return document.querySelector(z) || el;
    if (el.tagName === 'SELECT' && el.parentNode && el.parentNode.classList && el.parentNode.classList.contains('rs-combo')) return el.parentNode.querySelector('.rs-input') || el;
    return el;
  }
  function beschriftung(el) {
    var z = zeigeEl(el), l = (z.labels && z.labels[0]) || (el.labels && el.labels[0]);
    if (!l) { var c = z.closest && z.closest('.bx-field'); l = c && c.querySelector('label'); }
    return l;
  }
  function labelText(el) {
    var l = beschriftung(el); if (!l) return el.getAttribute('placeholder') || el.name || 'Feld';
    var t = ''; l.childNodes.forEach(function (n) { if (n.nodeType === 3) t += n.textContent; });
    return (t || l.textContent).replace(/\*/g, '').replace(/\(.*?\)/g, '').trim();
  }
  function sterne(root) {
    (root || document).querySelectorAll('[data-pflicht]').forEach(function (el) {
      var l = beschriftung(el); if (!l) return;
      var s = l.querySelector('.bx-stern');
      if (!s) { s = document.createElement('span'); s.className = 'bx-stern'; s.textContent = '*'; l.appendChild(s); }
      s.style.display = bedingung(el) ? '' : 'none';
    });
  }
  function markieren(el, text) {
    var z = zeigeEl(el);
    z.classList.add('bx-pflicht-fehlt');
    var box = z.closest('.bx-field') || z.parentNode, m = box.querySelector('.bx-pflicht-msg');
    if (!m) { m = document.createElement('div'); m.className = 'bx-pflicht-msg'; box.appendChild(m); }
    m.textContent = text;
  }
  function entmarkieren(z) {
    z.classList.remove('bx-pflicht-fehlt');
    var box = z.closest && (z.closest('.bx-field') || z.parentNode), m = box && box.querySelector('.bx-pflicht-msg');
    if (m) m.remove();
  }
  window.bxPflichtPruefen = function (form) {
    var erstes = null;
    form.querySelectorAll('[data-pflicht]').forEach(function (el) {
      if (el.disabled || !bedingung(el) || !sichtbar(el)) return;
      if (String(el.value || '').trim() !== '') return;
      markieren(el, el.getAttribute('data-pflicht-text') || (labelText(el) + ' fehlt'));
      if (!erstes) erstes = zeigeEl(el);
    });
    if (erstes) { try { erstes.focus(); erstes.scrollIntoView({ block: 'center' }); } catch (e) {} return false; }
    return true;
  };
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM' || !f.querySelector('[data-pflicht]')) return;
    if (window.bxPflichtPruefen(f)) return;
    e.preventDefault(); e.stopImmediatePropagation();
  }, true);
  document.addEventListener('input', function (e) { if (e.target.classList && e.target.classList.contains('bx-pflicht-fehlt') && String(e.target.value || '').trim() !== '') entmarkieren(e.target); }, true);
  document.addEventListener('change', function (e) { sterne(); var t = e.target; if (t.hasAttribute && t.hasAttribute('data-pflicht') && String(t.value || '').trim() !== '') { entmarkieren(t); entmarkieren(zeigeEl(t)); } }, true);
  function start() {
    sterne();
    // Vom Server markierte Felder (Seite kam mit den Eingaben zurück)
    var erstes = null;
    document.querySelectorAll('.bx-pflicht-fehlt[data-pflicht-msg]').forEach(function (el) { markieren(el, el.getAttribute('data-pflicht-msg')); if (!erstes) erstes = el; });
    if (erstes) try { erstes.focus(); erstes.scrollIntoView({ block: 'center' }); } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
