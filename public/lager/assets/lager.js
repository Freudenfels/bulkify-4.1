// Leucht-Knoepfe im Lager-Programm.
//
// Jeder Knopf mit data-leuchten="<platz_id>" schickt den Befehl per fetch() - die Seite bleibt
// stehen, man kann gleich den naechsten Platz antippen. Waehrend der Anfrage dreht sich ein
// Spinner im Knopf (Pflicht: sonst wird doppelt geklickt), danach steht daneben kurz das Ergebnis.
//
// Optionale Attribute: data-farbe, data-sek, data-piep, data-aktion="aus".
// Steht data-aus-feldern="<form-id>", werden Farbe/Dauer/Piep aus diesem Formular genommen.
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

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-leuchten]');
    if (!btn || btn.disabled) return;
    e.preventDefault();

    var d = new FormData();
    d.append('platz_id', btn.getAttribute('data-leuchten'));
    d.append('aktion', btn.getAttribute('data-aktion') || 'an');
    var quelle = btn.getAttribute('data-aus-feldern');
    var f = quelle ? document.getElementById(quelle) : null;
    d.append('farbe', f ? f.elements.farbe.value : (btn.getAttribute('data-farbe') || 'gruen'));
    d.append('sek', f ? f.elements.sek.value : (btn.getAttribute('data-sek') || '20'));
    d.append('piep', f ? (f.elements.piep.checked ? '1' : '0') : (btn.getAttribute('data-piep') || '1'));

    var vorher = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="sp-klein" aria-hidden="true"></span>' + vorher;

    fetch('?p=leuchten', { method: 'POST', body: d, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { meldung(btn, j.meldung || (j.ok ? 'Leuchtet.' : 'Fehler'), j.ok ? 'ok' : 'fehler'); })
      .catch(function () { meldung(btn, 'Keine Verbindung zum Server.', 'fehler'); })
      .finally(function () { btn.disabled = false; btn.innerHTML = vorher; });
  });

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
