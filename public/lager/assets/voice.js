// Sprachbedienung fuers grosse Lager.
//
// Ablauf: Mikrofon-Knopf antippen -> Rohstoff sagen ("Ashwagandha KSM 66") -> die Suche laeuft
// per fetch (?p=suche), ein Popup zeigt die Treffer, der beste oben und aktiv. Danach hoert es
// weiter zu und versteht Befehle: "blinke/finden/leuchte" (aktive Blinker klingeln), "aus",
// "weiter/naechste", "schliessen/fertig".
//
// Nutzt die Web Speech API (Chrome/Android, Deutsch). Auf iPhone/Safari eingeschraenkt.
(function () {
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;

  // ---- Popup -------------------------------------------------------------------------------
  var pop, liste, kopf, statusZeile, feld, hilfe, aktiv = 0, treffer = [], suchTimer;

  function baue() {
    if (pop) return;
    pop = document.createElement('div');
    pop.id = 'lgVoice';
    pop.innerHTML =
      '<div class="lgv-box" role="dialog" aria-label="Suche">' +
      '<div class="lgv-kopf"><span class="lgv-mic">Suche</span>' +
      '<button type="button" class="lgv-zu" aria-label="Schließen">×</button></div>' +
      '<div class="lgv-suchzeile">' +
      '<input type="search" class="lgv-feld" placeholder="Rohstoff, Artikelnummer oder Charge" autocomplete="off">' +
      '<button type="button" class="lgv-micbtn" aria-label="Per Sprache suchen" title="Sprache (Strg+D)">' +
      '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"></rect><path d="M5 11a7 7 0 0 0 14 0"></path><line x1="12" y1="18" x2="12" y2="22"></line></svg>' +
      '</button></div>' +
      '<div class="lgv-status"></div>' +
      '<div class="lgv-liste"></div>' +
      '<div class="lgv-hilfe"></div>' +
      '</div>';
    document.body.appendChild(pop);
    kopf = pop.querySelector('.lgv-mic');
    statusZeile = pop.querySelector('.lgv-status');
    liste = pop.querySelector('.lgv-liste');
    feld = pop.querySelector('.lgv-feld');
    hilfe = pop.querySelector('.lgv-hilfe');
    pop.querySelector('.lgv-zu').addEventListener('click', zu);
    pop.querySelector('.lgv-micbtn').addEventListener('click', frischStarten);
    pop.addEventListener('click', function (e) { if (e.target === pop) zu(); });
    // Tippen sucht live (kurz entprellt).
    feld.addEventListener('input', function () {
      clearTimeout(suchTimer);
      var t = feld.value.trim();
      suchTimer = setTimeout(function () { t ? sucheLaufen(t) : leereListe(); }, 250);
    });
  }

  function auf() { baue(); pop.classList.add('an'); }
  function zu() { if (pop) pop.classList.remove('an'); stopHoeren(); still(); leere(); }

  // Popup komplett zuruecksetzen, damit beim naechsten Oeffnen nichts Altes stehen bleibt.
  // NUR beim frischen Start (Mikrofon-Klick) und beim Schliessen aufrufen - nicht in der
  // Befehlsphase, sonst verschwinden die gerade gezeigten Treffer.
  function leere() {
    baue();
    treffer = []; aktiv = 0; anId = null;
    statusZeile.textContent = '';
    liste.innerHTML = '';
    feld.value = '';
    kopf.textContent = 'Suche'; kopf.classList.remove('live');
    hilfe.textContent = 'Tippen zum Suchen, Eintrag antippen lässt den Blinker blinken.';
  }
  function leereListe() { treffer = []; anId = null; statusZeile.textContent = ''; liste.innerHTML = ''; }

  function zeigeStatus(t) { baue(); statusZeile.textContent = t; }

  // Reine Suche (Tippen ODER Sprache): holt Treffer und zeigt sie, ohne Spracherkennung.
  function sucheLaufen(text) {
    if (!text) { leereListe(); return; }
    zeigeStatus('Suche „' + text + '" …');
    return fetch('?p=suche&q=' + encodeURIComponent(text), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { zeigeTreffer(text, j.treffer || []); return treffer.length; })
      .catch(function () { zeigeStatus('Suche fehlgeschlagen.'); return 0; });
  }

  // Popup im Tipp-Modus oeffnen (kein Mikrofon). Optional mit Startwort.
  function oeffneText(start) {
    auf(); leere();
    if (start) { feld.value = start; sucheLaufen(start); }
    setTimeout(function () { feld.focus(); }, 60);
  }

  function zeigeTreffer(q, tr) {
    baue(); treffer = tr; aktiv = 0; anId = null;
    statusZeile.innerHTML = 'Gesucht: <strong>' + esc(q) + '</strong>';
    if (!tr.length) { liste.innerHTML = '<div class="lgv-leer">Nichts gefunden für „' + esc(q) + '".</div>'; return; }
    liste.innerHTML = tr.map(function (t, i) {
      var ort = t.ort ? '<span class="lgv-ort">' + esc(t.ort) + '</span> · ' : '';
      var leiste = t.leiste ? '<span class="lgv-leiste">Blinker ' + esc(t.leiste) + '</span>'
                            : '<span class="lgv-keine">kein Blinker</span>';
      return '<div class="lgv-zeile' + (i === 0 ? ' aktiv' : '') + '" data-i="' + i + '">' +
        '<div class="lgv-name"><a class="lg-namelink" href="?p=charge&id=' + t.charge_id + '">' + esc(t.name) + '</a></div>' +
        '<div class="lgv-sub">' + (t.charge_nr ? 'Ch. ' + esc(t.charge_nr) + ' · ' : '') +
        esc(t.menge) + ' ' + esc(t.einheit) + ' · ' + ort + leiste + '</div></div>';
    }).join('');
    liste.querySelectorAll('.lgv-zeile').forEach(function (z) {
      // Klick schaltet um: blinkt dieser Blinker schon, geht er aus, sonst an.
      z.addEventListener('click', function (e) {
        if (e.target.closest('a')) return;   // Klick auf den Produktnamen -> zur Produktseite
        var i = +z.getAttribute('data-i');
        aktiv = i;
        var t = treffer[i];
        (t && t.leiste_id && anId === t.leiste_id) ? still() : blinke();
      });
    });
  }

  function markiere() {
    liste.querySelectorAll('.lgv-zeile').forEach(function (z, i) {
      z.classList.toggle('aktiv', i === aktiv);
      z.classList.toggle('an', !!(treffer[i] && treffer[i].leiste_id && treffer[i].leiste_id === anId));
    });
  }

  // ---- Blinker ansteuern --------------------------------------------------------------------
  var anId = null;   // leiste_id, die gerade blinkt (null = keiner)
  function post(ziel, daten) {
    var d = new FormData(); Object.keys(daten).forEach(function (k) { d.append(k, daten[k]); });
    return fetch(ziel, { method: 'POST', body: d, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function ausSenden(id) { if (id) post('?p=klingeln', { leiste_id: id, aktion: 'aus' }); }
  function blinke() {
    var t = treffer[aktiv]; if (!t) return;
    if (!t.leiste_id) { zeigeStatus('An „' + t.name + '" hängt noch kein Blinker.'); return; }
    if (anId && anId !== t.leiste_id) ausSenden(anId);   // anderen zuerst ausschalten
    anId = t.leiste_id; markiere();
    var wo = t.ort ? ' – ' + t.ort : '';
    zeigeStatus('Blinker ' + t.leiste + ' blinkt …' + wo);
    post('?p=klingeln', { leiste_id: t.leiste_id, farbe: 'gruen', sek: 180 })
      .then(function (j) { zeigeStatus((j.meldung || 'Blinker ' + t.leiste + ' blinkt.') + wo); })
      .catch(function () { zeigeStatus('Keine Verbindung zum Server.'); });
  }
  function still() {
    var id = anId;
    anId = null; markiere();
    if (id) { zeigeStatus('Aus.'); ausSenden(id); }
  }

  // ---- Sprache -----------------------------------------------------------------------------
  var erk, modus = 'suche';   // 'suche' oder 'befehl'

  function starte(m) {
    if (!SR) { auf(); zeigeStatus('Dieser Browser kann keine Sprache. Bitte Chrome benutzen.'); return; }
    modus = m; stopHoeren();
    erk = new SR();
    erk.lang = 'de-DE'; erk.interimResults = false; erk.maxAlternatives = 3;
    erk.onstart = function () { auf(); kopf.textContent = 'Zuhören …'; kopf.classList.add('live');
      hilfe.textContent = 'Sag „blinke“, „aus“, „weiter“ oder „schließen“.'; };
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
    if (feld) feld.value = text;
    sucheLaufen(text).then(function (n) { n ? starte('befehl') : starte('suche'); });
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

  // ---- Knopf + Tastenkuerzel ---------------------------------------------------------------
  function frischStarten() { auf(); leere(); starte('suche'); }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-mic]');
    if (b) { e.preventDefault(); frischStarten(); return; }
    // Tipp-Suche: oeffnet dasselbe Popup, aber ohne Mikrofon.
    var s = e.target.closest('[data-suche]');
    if (s) { e.preventDefault(); oeffneText(s.getAttribute('data-suche-start') || ''); }
  });
  // Fokus auf ein data-suche-Feld oeffnet ebenfalls das Popup.
  document.addEventListener('focusin', function (e) {
    var s = e.target.closest('[data-suche-feld]');
    if (s && !(pop && pop.classList.contains('an'))) { s.blur(); oeffneText(''); }
  });

  // Strg+D (bzw. Cmd+D) startet das Mikrofon. Ueberschreibt das Lesezeichen-Kuerzel des Browsers.
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 'd' || e.key === 'D')) {
      e.preventDefault();
      frischStarten();
    }
  });
})();
