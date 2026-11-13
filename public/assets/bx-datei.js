/* bx-datei.js – Datei-Knopf im bulkify-Stil und Karten-Beschriftung am Handy (Nachbesserung Fable-5, Teil 4, 13.11.2026).
   Wird von bx-ui.js geladen. Läuft auch auf später eingefügten Inhalten (Pop-ups).
   1) Jedes rohe <input type="file"> bekommt einen Knopf „Datei wählen“ (bzw. „Dateien wählen“) und den gewählten Namen daneben.
      Das echte Feld bleibt im Formular (unsichtbar, aber fokussierbar) – required, accept, multiple und Pflichtfelder bleiben gültig.
      Ausnahme: data-roh="1" am Feld.
   2) Tabellen mit Klasse bx-karten-handy: jede Zelle bekommt data-label = Spaltenüberschrift (für die Karten-Ansicht am Handy). */
(function () {
  if (window.bxDateiInit) return; window.bxDateiInit = true;
  function datei(inp) {
    if (inp.__bxDatei || inp.dataset.roh === '1' || inp.closest('.bx-datei')) return;
    inp.__bxDatei = true;
    var w = document.createElement('label');
    w.className = 'bx-datei';
    var b = document.createElement('span');
    b.className = 'btn btn-ghost btn-sm';
    var T = window.bxTx || function (k) { return {datei: 'Datei wählen', dateien: 'Dateien wählen', keine: 'keine gewählt', ndateien: 'Dateien'}[k]; };
    b.textContent = inp.multiple ? T('dateien') : T('datei');
    b.setAttribute('role', 'button'); b.tabIndex = 0;
    var n = document.createElement('span'); n.className = 'bx-datei-name'; n.textContent = T('keine');
    inp.parentNode.insertBefore(w, inp);
    w.appendChild(inp); w.appendChild(b); w.appendChild(n);
    b.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); inp.click(); } });
    inp.addEventListener('change', function () {
      var f = inp.files ? Array.prototype.map.call(inp.files, function (x) { return x.name; }) : [];
      n.textContent = f.length ? (f.length > 1 ? f.length + ' ' + T('ndateien') : f[0]) : T('keine');
      n.title = f.join(', ');
    });
  }
  function karten(t) {
    if (t.__bxKarten) return; t.__bxKarten = true;
    var kopf = [];
    var th = t.querySelectorAll('thead tr:last-child th');
    Array.prototype.forEach.call(th, function (h) { var s = parseInt(h.getAttribute('colspan') || '1', 10); for (var i = 0; i < s; i++) kopf.push((h.innerText || '').trim()); });
    Array.prototype.forEach.call(t.querySelectorAll('tbody tr'), function (tr) {
      var i = 0;
      Array.prototype.forEach.call(tr.children, function (td) { if (!td.hasAttribute('data-label')) td.setAttribute('data-label', kopf[i] || ''); i += parseInt(td.getAttribute('colspan') || '1', 10); });
    });
  }
  function alle(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('input[type=file]'), datei);
    Array.prototype.forEach.call((root || document).querySelectorAll('table.bx-karten-handy'), karten);
  }
  window.bxDateiAlle = alle;
  alle(document);
  if (window.MutationObserver) new MutationObserver(function (ms) {
    ms.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (x) { if (x.nodeType === 1 && x.querySelector) alle(x); }); });
  }).observe(document.body, { childList: true, subtree: true });
})();
