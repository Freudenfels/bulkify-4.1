// Finden-Knoepfe im Lager-Programm.
//
// Jeder Knopf mit data-klingeln="<leiste_id>" laesst den Blinker an einer Charge/Palette per fetch()
// klingeln - die Seite bleibt stehen. Waehrend der Anfrage dreht sich ein Spinner im Knopf (Pflicht:
// sonst wird doppelt geklickt), danach steht daneben kurz das Ergebnis.
//
// Optionale Attribute: data-farbe, data-sek, data-piep, data-aktion="aus".
(function () {
  function meldung(btn, text, art) {
    var m = btn.nextElementSibling;
    if (!m || !m.classList || !m.classList.contains('lg-meldung')) {
      m = document.createElement('span');
      m.className = 'lg-meldung';
      btn.parentNode.insertBefore(m, btn.nextSibling);
    }
    m.textContent = text;
    m.className = 'lg-meldung ' + art;
    clearTimeout(m.__t);
    m.__t = setTimeout(function () { m.textContent = ''; }, 6000);
  }

  // Capture-Phase (true): feuert BEVOR ein onclick="event.stopPropagation()" am Knopf greift
  // (z. B. „Finden" in Listenzeilen, die stopPropagation nutzen, damit die Zeile nicht navigiert).
  document.addEventListener('click', function (e) {
    // Blinker an einer Charge/Palette klingeln lassen.
    var btn = e.target.closest('[data-klingeln]');
    if (!btn || btn.disabled) return;
    e.preventDefault();

    var ziel = '?p=klingeln';
    var d = new FormData();
    d.append('leiste_id', btn.getAttribute('data-klingeln'));
    d.append('aktion', btn.getAttribute('data-aktion') || 'an');
    var quelle = btn.getAttribute('data-aus-feldern');
    var f = quelle ? document.getElementById(quelle) : null;
    d.append('farbe', f ? f.elements.farbe.value : (btn.getAttribute('data-farbe') || 'gruen'));
    d.append('sek', f ? f.elements.sek.value : (btn.getAttribute('data-sek') || '40'));
    d.append('piep', f ? (f.elements.piep.checked ? '1' : '0') : (btn.getAttribute('data-piep') || '1'));

    var vorher = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="sp-klein" aria-hidden="true"></span>' + vorher;

    fetch(ziel, { method: 'POST', body: d, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { meldung(btn, j.meldung || (j.ok ? 'OK.' : 'Fehler'), j.ok ? 'ok' : 'fehler'); })
      .catch(function () { meldung(btn, 'Keine Verbindung zum Server.', 'fehler'); })
      .finally(function () { btn.disabled = false; btn.innerHTML = vorher; });
  }, true);

  // Charge-Combobox: Feld mit data-ziel="<hidden-id>" sucht Chargen live und setzt die charge_id.
  document.querySelectorAll('[data-ziel]').forEach(function (inp) {
    var ziel = document.getElementById(inp.getAttribute('data-ziel'));
    var anz = document.getElementById(inp.getAttribute('data-anzeige'));
    var box = document.createElement('div');
    box.className = 'lg-combo';
    inp.parentNode.insertBefore(box, inp.nextSibling);
    var t;
    function schliessen() { box.innerHTML = ''; box.style.display = 'none'; }
    inp.addEventListener('input', function () {
      if (ziel) ziel.value = '';
      if (anz) anz.textContent = '';
      clearTimeout(t);
      var q = inp.value.trim();
      if (!q) { schliessen(); return; }
      t = setTimeout(function () {
        fetch('?p=suche&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            var tr = j.treffer || [];
            if (!tr.length) { schliessen(); return; }
            box.innerHTML = tr.map(function (x) {
              return '<div class="lg-combo-z" data-id="' + x.charge_id + '" data-txt="' +
                (x.name + (x.charge_nr ? ' · ' + x.charge_nr : '')).replace(/"/g, '&quot;') + '">' +
                '<strong>' + escHtml(x.name) + '</strong>' + (x.charge_nr ? ' <span class="muted">Ch. ' + escHtml(x.charge_nr) + '</span>' : '') +
                ' <span class="muted">' + escHtml(x.menge) + ' ' + escHtml(x.einheit) + '</span></div>';
            }).join('');
            box.style.display = 'block';
            box.querySelectorAll('.lg-combo-z').forEach(function (z) {
              z.addEventListener('click', function () {
                if (ziel) ziel.value = z.getAttribute('data-id');
                inp.value = z.getAttribute('data-txt');
                if (anz) anz.textContent = 'Ausgewählt: ' + z.getAttribute('data-txt');
                schliessen();
              });
            });
          }).catch(schliessen);
      }, 250);
    });
    document.addEventListener('click', function (e) { if (!box.contains(e.target) && e.target !== inp) schliessen(); });
  });
  function escHtml(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  // Live-Suche: Feld mit data-filter="<tabellen-id>" blendet Zeilen aus, die nicht passen.
  document.querySelectorAll('[data-filter]').forEach(function (inp) {
    var t = document.getElementById(inp.getAttribute('data-filter'));
    if (!t) return;
    inp.addEventListener('input', function () {
      var w = inp.value.toLowerCase().trim().split(/\s+/);
      t.querySelectorAll('tbody tr').forEach(function (tr) {
        var txt = tr.textContent.toLowerCase();
        tr.style.display = w.every(function (x) { return txt.indexOf(x) !== -1; }) ? '' : 'none';
      });
    });
  });
})();
