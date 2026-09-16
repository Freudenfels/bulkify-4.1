// Lade-Rueckmeldung im CRM.
//
// Beim Absenden eines Formulars wird der geklickte Knopf zum Spinner und die Seite legt sich grau
// darueber. Ohne das klickt jeder zweimal - und die KI-Knoepfe hier laufen bis zu einer Minute.
//
// Verhalten wie im Dashboard (bx_busy_script in core/layout.php), hier bewusst als eigene Datei:
// Der Rahmen des CRM kann die Funktion des Dashboards nicht einbinden, ohne dessen layout.php zu
// laden - und die bringt Funktionen mit, die es hier schon gibt.
//
// Ausnahmen: Formulare mit data-no-busy, und alles mit target=_blank (Download/neuer Tab
// navigiert nicht weg, sonst haengt der Spinner ewig). Eigener Text je Knopf ueber data-busy.
(function () {
  if (!document.getElementById('crmBusyStyle')) {
    var st = document.createElement('style');
    st.id = 'crmBusyStyle';
    st.textContent =
      '#crmOverlay{position:fixed;inset:0;z-index:99998;background:rgba(18,20,23,.45);display:flex;' +
      'align-items:center;justify-content:center;opacity:0;transition:opacity .15s ease}' +
      '#crmOverlay.an{opacity:1}' +
      '#crmOverlay .sp{width:46px;height:46px;border:4px solid rgba(255,255,255,.55);border-top-color:#fff;' +
      'border-radius:50%;animation:crmsp .7s linear infinite}' +
      '#crmBalken{position:fixed;top:0;left:0;height:3px;width:0;background:var(--gruen,#1D9E75);' +
      'z-index:99999;transition:width 8s ease-out}#crmBalken.an{width:92%}' +
      '.btn .sp-klein{width:13px;height:13px;border:2px solid currentColor;border-right-color:transparent;' +
      'border-radius:50%;display:inline-block;animation:crmsp .7s linear infinite;margin-right:6px;vertical-align:-2px}' +
      '@keyframes crmsp{to{transform:rotate(360deg)}}';
    document.head.appendChild(st);
  }

  function balken() {
    if (document.getElementById('crmBalken')) return;
    var b = document.createElement('div');
    b.id = 'crmBalken';
    document.body.appendChild(b);
    requestAnimationFrame(function () { b.className = 'an'; });
  }

  function overlay() {
    if (document.getElementById('crmOverlay')) return;
    var o = document.createElement('div');
    o.id = 'crmOverlay';
    o.innerHTML = '<div class="sp" aria-hidden="true"></div>';
    document.body.appendChild(o);
    requestAnimationFrame(function () { o.className = 'an'; });
    // Failsafe: Wird die Navigation doch abgebrochen, verschwindet das Overlay von selbst.
    setTimeout(function () { var x = document.getElementById('crmOverlay'); if (x) x.remove(); }, 300000);
  }

  function weg() {
    ['crmOverlay', 'crmBalken'].forEach(function (id) {
      var e = document.getElementById(id); if (e) e.remove();
    });
  }

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.hasAttribute('data-no-busy')) return;
    var b = e.submitter || f.querySelector('button[type=submit],button:not([type])');
    var ziel = (b && b.getAttribute('formtarget')) || f.getAttribute('target');
    if (ziel === '_blank') return;
    if (f.__busy) return;
    f.__busy = true;
    balken();
    if (b && !b.dataset.busyOn) {
      b.dataset.busyOn = '1';
      if (b.tagName === 'BUTTON') {
        b.dataset.busyLabel = b.innerHTML;
        var t = b.getAttribute('data-busy');
        b.innerHTML = '<span class="sp-klein" aria-hidden="true"></span>' + (t !== null ? t : b.dataset.busyLabel);
      }
      b.classList.add('is-busy');
      b.setAttribute('aria-busy', 'true');
      setTimeout(function () { b.disabled = true; }, 0);
    }
  }, true);

  window.addEventListener('beforeunload', function () { overlay(); balken(); });

  // Zurueck-Navigation: alles zuruecksetzen, sonst haengt der Spinner.
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;
    weg();
    var b = document.querySelector('.btn.is-busy');
    if (b) {
      b.disabled = false;
      b.classList.remove('is-busy');
      b.removeAttribute('aria-busy');
      if (b.dataset.busyLabel !== undefined) { b.innerHTML = b.dataset.busyLabel; delete b.dataset.busyLabel; }
      delete b.dataset.busyOn;
    }
    document.querySelectorAll('form').forEach(function (f) { f.__busy = false; });
  });
})();
