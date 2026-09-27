// Sprachbedienung fuers grosse Lager.
//
// Ablauf: Mikrofon-Knopf antippen -> Rohstoff sagen ("Ashwagandha KSM 66") -> die Suche laeuft
// per fetch (?p=suche), ein Popup zeigt die Treffer, der beste oben und aktiv. Danach hoert es
// weiter zu und versteht Befehle: "blinke/finden/leuchte" (aktive Leiste klingeln), "aus",
// "weiter/naechste", "schliessen/fertig".
//
// Nutzt die Web Speech API (Chrome/Android, Deutsch). Auf iPhone/Safari eingeschraenkt.
(function () {
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;

  // ---- Popup -------------------------------------------------------------------------------
  var pop, liste, kopf, statusZeile, aktiv = 0, treffer = [];

  function baue() {
    if (pop) return;
    pop = document.createElement('div');
    pop.id = 'lgVoice';
    pop.innerHTML =
      '<div class="lgv-box" role="dialog" aria-label="Sprachsuche">' +
      '<div class="lgv-kopf"><span class="lgv-mic">Zuhören …</span>' +
      '<button type="button" class="lgv-zu" aria-label="Schließen">×</button></div>' +
      '<div class="lgv-status"></div>' +
      '<div class="lgv-liste"></div>' +
      '<div class="lgv-hilfe">Sag „blinke“, „aus“, „weiter“ oder „schließen“.</div>' +
      '</div>';
    document.body.appendChild(pop);
    kopf = pop.querySelector('.lgv-mic');
    statusZeile = pop.querySelector('.lgv-status');
    liste = pop.querySelector('.lgv-liste');
    pop.querySelector('.lgv-zu').addEventListener('click', zu);
    pop.addEventListener('click', function (e) { if (e.target === pop) zu(); });
  }

  function auf() { baue(); pop.classList.add('an'); }
  function zu() { if (pop) pop.classList.remove('an'); stopHoeren(); still(); }

  function zeigeStatus(t) { baue(); statusZeile.textContent = t; }

  function zeigeTreffer(q, tr) {
    baue(); treffer = tr; aktiv = 0;
    statusZeile.innerHTML = 'Gesucht: <strong>' + esc(q) + '</strong>';
    if (!tr.length) { liste.innerHTML = '<div class="lgv-leer">Nichts gefunden. Noch einmal antippen und sprechen.</div>'; return; }
    liste.innerHTML = tr.map(function (t, i) {
      var leiste = t.leiste ? '<span class="lgv-leiste">Leiste ' + esc(t.leiste) + '</span>'
                            : '<span class="lgv-keine">keine Leiste</span>';
      return '<div class="lgv-zeile' + (i === 0 ? ' aktiv' : '') + '" data-i="' + i + '">' +
        '<div class="lgv-name">' + esc(t.name) + '</div>' +
        '<div class="lgv-sub">' + (t.charge_nr ? 'Ch. ' + esc(t.charge_nr) + ' · ' : '') +
        esc(t.menge) + ' ' + esc(t.einheit) + ' · ' + leiste + '</div></div>';
    }).join('');
    liste.querySelectorAll('.lgv-zeile').forEach(function (z) {
      z.addEventListener('click', function () { aktiv = +z.getAttribute('data-i'); markiere(); blinke(); });
    });
  }

  function markiere() {
    liste.querySelectorAll('.lgv-zeile').forEach(function (z, i) { z.classList.toggle('aktiv', i === aktiv); });
  }

  // ---- Leiste ansteuern --------------------------------------------------------------------
  function post(ziel, daten) {
    var d = new FormData(); Object.keys(daten).forEach(function (k) { d.append(k, daten[k]); });
    return fetch(ziel, { method: 'POST', body: d, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function blinke() {
    var t = treffer[aktiv]; if (!t) return;
    if (!t.leiste_id) { zeigeStatus('An „' + t.name + '" hängt noch keine Leiste.'); return; }
    zeigeStatus('Leiste ' + t.leiste + ' blinkt …');
    post('?p=klingeln', { leiste_id: t.leiste_id, farbe: 'gruen', sek: 40 })
      .then(function (j) { zeigeStatus(j.meldung || 'Leiste ' + t.leiste + ' blinkt.'); })
      .catch(function () { zeigeStatus('Keine Verbindung zum Server.'); });
  }
  function still() {
    var t = treffer[aktiv]; if (t && t.leiste_id) post('?p=klingeln', { leiste_id: t.leiste_id, aktion: 'aus' });
  }

  // ---- Sprache -----------------------------------------------------------------------------
  var erk, modus = 'suche';   // 'suche' oder 'befehl'

  function starte(m) {
    if (!SR) { auf(); zeigeStatus('Dieser Browser kann keine Sprache. Bitte Chrome benutzen.'); return; }
    modus = m; stopHoeren();
    erk = new SR();
    erk.lang = 'de-DE'; erk.interimResults = false; erk.maxAlternatives = 3;
    erk.onstart = function () { auf(); kopf.textContent = 'Zuhören …'; kopf.classList.add('live'); };
    erk.onerror = function (e) { kopf.classList.remove('live'); if (e.error === 'not-allowed') zeigeStatus('Kein Zugriff aufs Mikrofon. Bitte im Browser erlauben.'); };
    erk.onend = function () { kopf.classList.remove('live'); if (modus === 'befehl' && pop.classList.contains('an')) starte('befehl'); };
    erk.onresult = function (ev) {
      var texte = []; for (var i = 0; i < ev.results[0].length; i++) texte.push(ev.results[0][i].transcript.trim());
      modus === 'suche' ? verarbeiteSuche(texte[0]) : verarbeiteBefehl(texte);
    };
    try { erk.start(); } catch (e) {}
  }
  function stopHoeren() { if (erk) { try { erk.onend = null; erk.stop(); } catch (e) {} erk = null; } }

  function verarbeiteSuche(text) {
    if (!text) { starte('befehl'); return; }
    zeigeStatus('Gesucht: „' + text + '" …');
    fetch('?p=suche&q=' + encodeURIComponent(text), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { zeigeTreffer(text, j.treffer || []); if (treffer.length) starte('befehl'); else starte('suche'); })
      .catch(function () { zeigeStatus('Suche fehlgeschlagen.'); });
  }

  function verarbeiteBefehl(texte) {
    var s = texte.join(' ').toLowerCase();
    if (/(blink|finde|leucht|klingel|zeig)/.test(s)) return blinke();
    if (/\b(aus|stop|stopp|ende)\b/.test(s)) { still(); zeigeStatus('Aus.'); return; }
    if (/(weiter|nächst|naechst|runter)/.test(s)) { if (treffer.length) { aktiv = (aktiv + 1) % treffer.length; markiere(); blinke(); } return; }
    if (/(zurück|zurueck|hoch|vorher)/.test(s)) { if (treffer.length) { aktiv = (aktiv - 1 + treffer.length) % treffer.length; markiere(); blinke(); } return; }
    if (/(schließ|schliess|fertig|zu\b|beenden)/.test(s)) { zu(); return; }
    // Sonst als neue Suche behandeln.
    verarbeiteSuche(texte[0]);
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  // ---- Knopf -------------------------------------------------------------------------------
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-mic]'); if (!b) return;
    e.preventDefault();
    starte('suche');
  });
})();
