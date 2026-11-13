/* bx-etikett.js – Etikett hochladen, prüfen und freigeben in einem Pop-up (13.11.2026, plan-etikett-upload.md).
 * Kundenportal und Dashboard. Server: core/etikett_pruefung.php (etp_ajax). Start: window.bxEtikettStart = {basis, auto}.
 * - bxEtikett.oeffnen(aid)  öffnet das Pop-up für einen Auftrag
 * - [data-etp-oeffnen="AID"] und [data-etp-liste="[…]"] (Band) öffnen per Klick
 * Ablauf im Pop-up: 1 Hochladen (nur PDF) · 2 Prüfen (pdf.js-Vorschau mit Rahmen, Maß-Ampel, Druck-Check, KI-Etikettencheck) · 3 Freigeben.
 */
(function () {
  if (window.bxEtikett) return;
  var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  var START = window.bxEtikettStart || {};
  var MM = 72 / 25.4;
  var laden = null;
  function pdfjs() {
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (laden) return laden;
    laden = new Promise(function (ok, fehler) {
      var s = document.createElement('script'); s.src = PDFJS + 'pdf.min.js';
      s.onload = function () { if (!window.pdfjsLib) return fehler(new Error('pdf.js')); window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js'; ok(window.pdfjsLib); };
      s.onerror = function () { laden = null; fehler(new Error('pdf.js')); };
      document.head.appendChild(s);
    });
    return laden;
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function api(a, aid, body, form) {
    var url = (START.basis || '') + '&a=' + a + '&aid=' + (aid || 0);
    var o = { credentials: 'same-origin', method: body || form ? 'POST' : 'GET' };
    if (body && body.__signal) { o.signal = body.__signal; delete body.__signal; }
    // JSON als Formularfeld „daten“ (ein reiner JSON-Body sähe für den Server wie ein zu großer Upload aus)
    if (form) o.body = form; else if (body) { var fd = new FormData(); fd.append('daten', JSON.stringify(body)); o.body = fd; }
    return fetch(url, o).then(function (r) { return r.json(); });
  }
  // SVG-Symbole der Ampel (keine Schriftzeichen/Emojis)
  var ICO = {
    gruen: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="currentColor"/><path d="M5.5 10.3l3 3 6-6.3" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    gelb: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 1.8l8.6 15.4H1.4z" fill="currentColor"/><path d="M10 7.2v4.6" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/><circle cx="10" cy="14.6" r="1.3" fill="#fff"/></svg>',
    rot: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="currentColor"/><path d="M6.6 6.6l6.8 6.8M13.4 6.6l-6.8 6.8" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/></svg>',
    info: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="currentColor"/><path d="M10 9v5.2" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/><circle cx="10" cy="6" r="1.3" fill="#fff"/></svg>',
    haken: '<svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="22" fill="currentColor"/><path d="M13 24.5l7.5 7.5L35 17" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    upload: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4m0 0l-4.5 4.5M12 4l4.5 4.5M4 15v3.5A1.5 1.5 0 005.5 20h13a1.5 1.5 0 001.5-1.5V15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'
  };
  function zeile(z) {
    return '<div class="bx-et-amp bx-et-' + esc(z.ampel) + '"><span class="bx-et-ico">' + (ICO[z.ampel] || ICO.info) + '</span><div><div class="bx-et-amp-t">' + esc(z.titel) + '</div><div class="bx-et-amp-x">' + esc(z.text) + '</div></div></div>';
  }

  var D = null, S = null, DOC = null, SEITE = null, KI_LAEUFT = false, POLL = null;

  function dialog() {
    if (D && document.body.contains(D)) return D;
    D = document.createElement('dialog');
    D.className = 'bx-dialog bx-et';
    D.innerHTML = '<div class="bx-pop-kopf"><h2></h2><button type="button" class="bx-pop-x" aria-label="Schließen">&times;</button></div><div class="bx-et-inhalt"></div><div class="bx-pop-fuss"></div>';
    D.querySelector('.bx-pop-x').onclick = function () { if (window.bxPopup) bxPopup.close(D); else D.close(); };
    document.body.appendChild(D);
    D.addEventListener('close', function () { if (POLL) { clearInterval(POLL); POLL = null; } });
    return D;
  }
  function offen() { var d = dialog(); if (!d.open) { if (window.bxPopup) bxPopup.open(d); else d.showModal(); } return d; }
  function schliessen() { if (window.bxPopup) bxPopup.close(D, true); else D.close(); }

  function oeffnen(aid) {
    var d = offen(); d.__dirty = false;
    d.querySelector('.bx-pop-kopf h2').textContent = '…';
    d.querySelector('.bx-et-inhalt').innerHTML = '<div class="muted" style="padding:24px 0">…</div>';
    d.querySelector('.bx-pop-fuss').innerHTML = '';
    DOC = null; SEITE = null;
    api('status', aid).then(function (s) {
      if (!s || !s.ok) { d.querySelector('.bx-et-inhalt').innerHTML = '<div class="bx-et-fehler">' + esc((s && s.fehler) || 'Fehler') + '</div>'; return; }
      zeigen(s, true);
    }).catch(function () { d.querySelector('.bx-et-inhalt').innerHTML = '<div class="bx-et-fehler">Keine Verbindung.</div>'; });
  }

  // ------------------------------------------------------------------ Aufbau
  function zeigen(s, neuRendern) {
    S = s; var t = s.txt, d = dialog(), akt = s.aktuell;
    d.querySelector('.bx-pop-kopf h2').textContent = t.titel + ' · ' + s.nummer + (s.produkt ? ' · ' + s.produkt : '');
    var schritt = !akt ? 1 : (s.freigegeben ? 4 : 3);
    var steps = '<ol class="bx-et-steps">'
      + [t.schritt1, t.schritt2, t.schritt3].map(function (x, i) { var n = i + 1; return '<li class="' + (n < schritt ? 'fertig' : (n === schritt || (n === 2 && schritt === 3) ? 'jetzt' : '')) + '"><span>' + n + '</span>' + esc(x) + '</li>'; }).join('')
      + '</ol>';
    var h = steps;
    if (s.soll && s.soll.label) h += '<div class="bx-et-soll">' + esc(t.soll) + ': ' + esc(s.soll.label) + (s.art === 'beutel' && s.soll.item_name ? ' · ' + esc(s.soll.item_name) : '') + '</div>';
    else if (s.art === 'beutel' && s.soll.item_name) h += '<div class="bx-et-soll">' + esc(t.soll) + ': ' + esc(s.soll.item_name) + '</div>';
    // Abschnitt 1: Hochladen
    if (s.gesperrt) h += '<div class="bx-et-hinweis">' + esc(t.gesperrt) + '</div>';
    else h += '<label class="bx-et-drop' + (akt ? ' klein' : '') + '"><input type="file" accept="application/pdf,.pdf" data-roh="1" hidden>'
      + '<span class="bx-et-drop-ico">' + ICO.upload + '</span><span>' + (akt ? esc(t.ersetzen) + ' – ' : '') + esc(t.ablage) + ' <u>' + esc(t.waehlen) + '</u> <span class="muted">(' + esc(t.nurpdf) + ')</span></span></label>'
      + '<div class="bx-et-upmsg" role="status"></div>';
    if (akt) {
      // Abschnitt 2: Vorschau und Prüfung
      h += '<div class="bx-et-grid"><div class="bx-et-links">'
        + '<div class="bx-et-vorschau" title="' + esc(t.zoom) + '"><div class="muted" style="padding:30px 0;text-align:center">' + esc(t.vorschau_lade) + '</div></div>'
        + '<div class="bx-et-legende"><span><i class="l-end"></i>' + esc(t.leg_end) + '</span><span><i class="l-besch"></i>' + esc(t.leg_besch) + '</span><span><i class="l-sicher"></i>' + esc(t.leg_sicher) + '</span>'
        + (s.art === 'beutel' && s.soll.vorlage ? '<span><i class="l-zone"></i>' + esc(t.leg_zone) + '</span>' : '') + '</div>'
        + '<div class="bx-et-flaechen"></div>'
        + '<div class="muted bx-et-datei">' + esc(t.version) + ' ' + akt.version + ' · <a href="' + esc(akt.url) + '" target="_blank" rel="noopener">' + esc(akt.datei_orig) + '</a></div>'
        + '</div><div class="bx-et-rechts"><div class="bx-et-tkopf"><h3>' + esc(t.technik) + '</h3><button type="button" class="btn btn-ghost btn-sm" data-et-neu>' + esc(t.neu_pruefen) + '</button></div><div class="bx-et-technik">' + akt.technik.map(zeile).join('') + '</div></div></div>';
      // KI
      h += '<section class="bx-et-ki"><h3>' + esc(t.ki) + '</h3><div class="bx-et-kihinweis">' + esc(t.ki_hinweis) + '</div><div class="bx-et-kiinhalt">' + kiHtml(akt.ki) + '</div>'
        + '<div class="bx-et-verantw">' + esc(t.verantwortlich) + '</div>'
        + (s.wer === 'kunde' ? '<div class="bx-et-buchen"><a href="#" data-et-buchen>' + esc(t.buchen) + '</a></div>' : '') + '</section>';
      // Abschnitt 3: Freigeben
      if (akt.freigabe || s.freigegeben) {
        var f = akt.freigabe;
        h += '<section class="bx-et-frei bx-et-frei-ok"><span class="bx-et-ico">' + ICO.gruen + '</span><div>' + esc(t.freigegeben)
          + (f ? ' ' + esc(t.am) + ' ' + esc(f.am) + ' ' + esc(t.von) + ' ' + esc(f.name) + (f.akteur === 'team' && f.grund ? ' (Team: ' + esc(f.grund) + ')' : '') + (f.trotzdem ? ' – ' + esc(t.trotzdem) : '') : ' – ' + esc(s.freigabe_info)) + '</div></section>';
      } else {
        h += '<section class="bx-et-frei"><h3>' + esc(t.schritt3) + '</h3>'
          + (akt.mass && akt.mass.ampel === 'rot' ? '<label class="bx-et-check bx-et-trotzdem"><input type="checkbox" name="trotzdem"> <span>' + esc(t.trotzdem) + '</span></label>' : '')
          + '<label class="bx-et-check"><input type="checkbox" name="haken"> <span>' + esc(t.haken) + '</span></label>'
          + '<details class="bx-et-haftung"><summary>' + esc(t.haftung_mehr) + '</summary><div>' + esc(s.haftung) + '</div></details>'
          + '<div class="bx-et-felder"><label class="bx-et-feld"><span>' + esc(t.name) + '</span><input type="text" name="name" autocomplete="name" value="' + esc(s.name_vor) + '"></label>'
          + (s.wer === 'team' ? '<label class="bx-et-feld"><span>' + esc(t.grund) + '</span><input type="text" name="grund"></label>' : '') + '</div>'
          + '<div class="bx-et-freimsg" role="status"></div></section>';
      }
    }
    // Versionen
    if (s.verlauf && s.verlauf.length > 1) {
      h += '<details class="bx-et-verlauf"><summary>' + esc(t.verlauf) + ' (' + s.verlauf.length + ')</summary><ul>' + s.verlauf.map(function (v) {
        var st = v.status === 'freigegeben' ? t.freigegeben + (v.freigabe ? ': ' + v.freigabe : '') : (v.status === 'ersetzt' ? t.ersetzt : t.wartet);
        return '<li><b>' + esc(t.version) + ' ' + v.version + '</b> · ' + esc(v.angelegt) + ' · <a href="' + esc(v.url) + '" target="_blank" rel="noopener">' + esc(v.datei_orig) + '</a><br><span class="muted">' + esc(st) + (v.ki ? ' · ' + esc(t.ki) + ': ' + esc(v.ki) : '') + '</span></li>';
      }).join('') + '</ul></details>';
    }
    d.querySelector('.bx-et-inhalt').innerHTML = h;
    // Team-Seite: Zusatz der Auftragsseite (Download für die Druckerei, Entfernen, Etikett-Erinnerung) unten anhängen
    var extra = s.wer === 'team' && document.getElementById('etpTeamExtra');
    if (extra) { var k = extra.cloneNode(true); k.removeAttribute('id'); k.hidden = false; d.querySelector('.bx-et-inhalt').appendChild(k); }
    // Knopfzeile
    var fuss = d.querySelector('.bx-pop-fuss');
    if (akt && !akt.freigabe && !s.freigegeben) fuss.innerHTML = '<button type="button" class="btn btn-ghost" data-et-spaeter>' + esc(t.spaeter) + '</button><button type="button" class="btn btn-primary bx-et-frei-btn" data-et-frei>' + esc(t.freigeben) + '</button>';
    else fuss.innerHTML = '<button type="button" class="btn btn-ghost" data-et-zu>' + esc(t.schliessen) + '</button>';
    binden();
    if (akt && akt.ist_pdf) vorschau(neuRendern);
    else if (akt) d.querySelector('.bx-et-vorschau').innerHTML = '<a class="btn btn-ghost btn-sm" href="' + esc(akt.url) + '" target="_blank" rel="noopener">' + esc(t.oeffnen) + '</a>';
  }
  function kiHtml(ki) {
    var t = S.txt;
    if (!ki || !ki.status || ki.status === 'laeuft' || KI_LAEUFT) return '<div class="bx-et-laeuft"><span class="bx-et-spin"></span>' + esc(t.ki_laeuft) + '</div>';
    if (ki.status === 'nicht_moeglich') return '<div class="bx-et-amp bx-et-info"><span class="bx-et-ico">' + ICO.info + '</span><div><div class="bx-et-amp-x">' + esc(t.ki_nicht) + '</div></div></div><button type="button" class="btn btn-ghost btn-sm" data-et-kineu>' + esc(t.ki_nochmal) + '</button>';
    return '<div class="bx-et-kizus">' + esc(ki.zusammenfassung) + (ki.test ? ' <span class="muted">(' + esc(t.ki_test) + ')</span>' : '') + '</div>'
      + (ki.punkte || []).map(function (p) { return zeile({ ampel: p.ampel, titel: p.titel, text: p.text }); }).join('');
  }
  function binden() {
    var d = D, t = S.txt, akt = S.aktuell;
    var inp = d.querySelector('.bx-et-drop input'), drop = d.querySelector('.bx-et-drop');
    if (inp) {
      inp.addEventListener('change', function () { if (inp.files && inp.files[0]) hochladen(inp.files[0]); });
      ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('ueber'); }); });
      ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('ueber'); }); });
      drop.addEventListener('drop', function (e) { var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]; if (f) hochladen(f); });
    }
    var b;
    if ((b = d.querySelector('[data-et-zu]'))) b.onclick = function () { schliessen(); };
    if ((b = d.querySelector('[data-et-spaeter]'))) b.onclick = function () { schliessen(); bandNeu(); };
    if ((b = d.querySelector('[data-et-frei]'))) b.onclick = freigeben;
    if ((b = d.querySelector('[data-et-kineu]'))) b.onclick = function () { kiStarten(true); };
    // Sofortfix 13.11.2026: Druck-Check und KI-Check für die aktuelle Datei neu (ohne neuen Upload)
    if ((b = d.querySelector('[data-et-neu]'))) b.onclick = function () {
      var knopf = this; knopf.disabled = true;
      api('neu_pruefen', S.aid, { pid: S.aktuell.id }).then(function (r) { knopf.disabled = false; if (r && r.ok) { KI_LAEUFT = false; zeigen(r, true); } })
        .catch(function () { knopf.disabled = false; });
    };
    if ((b = d.querySelector('[data-et-buchen]'))) b.onclick = function (e) {
      e.preventDefault(); var box = b.parentNode;
      box.innerHTML = '<div>' + esc(t.buchen_frage) + '</div><button type="button" class="btn btn-ghost btn-sm" style="margin-top:6px">' + esc(t.buchen) + '</button>';
      box.querySelector('button').onclick = function () { api('buchen', S.aid, { pid: akt ? akt.id : 0 }).then(function (r) { box.textContent = r && r.ok ? t.buchen_ok : t.fehler; }); };
    };
  }

  // ------------------------------------------------------------------ 1 Hochladen
  function hochladen(f) {
    var t = S.txt, msg = D.querySelector('.bx-et-upmsg');
    if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') { msg.className = 'bx-et-upmsg fehler'; msg.textContent = t.keinpdf; return; }
    msg.className = 'bx-et-upmsg'; msg.innerHTML = '<span class="bx-et-spin"></span>' + esc(t.lade);
    var fd = new FormData(); fd.append('datei', f);
    api('upload', S.aid, null, fd).then(function (r) {
      if (!r || !r.ok) { msg.className = 'bx-et-upmsg fehler'; msg.textContent = (r && r.fehler) || t.fehler; return; }
      KI_LAEUFT = false; zeigen(r, true); bandNeu();
    }).catch(function () { msg.className = 'bx-et-upmsg fehler'; msg.textContent = t.fehler; });
  }

  // ------------------------------------------------------------------ 2 Vorschau, Messung, KI
  function trimBox(akt) {
    var b = akt.boxen || {};
    return b.TrimBox || b.ArtBox || b.CropBox || b.MediaBox || null;
  }
  function vorschau(neu) {
    var akt = S.aktuell, box = D.querySelector('.bx-et-vorschau'), t = S.txt;
    var url = akt.url;
    pdfjs().then(function (lib) {
      if (DOC && DOC.__url === url) return DOC;
      return lib.getDocument({ url: url, withCredentials: true }).promise.then(function (d) { d.__url = url; DOC = d; return d; });
    }).then(function (doc) { return doc.getPage(1); }).then(function (seite) {
      SEITE = seite;
      zeichnen(box, seite);
      flaechenKnoepfe(seite);
      if (!akt.client_da) return messen(seite).then(function (c) { return api('technik', S.aid, { pid: akt.id, client: c }); }).then(function (s) { if (s && s.ok) { S.aktuell.technik = s.aktuell.technik; S.aktuell.client_da = true; D.querySelector('.bx-et-technik').innerHTML = s.aktuell.technik.map(zeile).join(''); } });
    }).then(function () {
      var ki = S.aktuell.ki || {};
      if (!ki.status && !KI_LAEUFT) kiStarten(false);
      else if (ki.status === 'laeuft' && !POLL) pollen();
    }).catch(function () {
      box.innerHTML = '<div class="muted" style="padding:12px 0">' + esc(t.vorschau_fehler) + '</div><a class="btn btn-ghost btn-sm" href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(t.oeffnen) + '</a>';
      if (!S.aktuell.client_da) api('technik', S.aid, { pid: akt.id, client: { pdfjs: false } }).then(function (s) { if (s && s.ok) D.querySelector('.bx-et-technik').innerHTML = s.aktuell.technik.map(zeile).join(''); });
      if (!(S.aktuell.ki || {}).status) kiAnzeigen({ status: 'nicht_moeglich' });
    });
  }
  // Seite + Rahmen auf ein Canvas: Endformat durchgezogen, Beschnitt rot gestrichelt außen, Sicherheitsabstand blau gestrichelt innen,
  // Beutel: Flächen beschriftet, Siegelnaht/Zipper schraffiert („hier kein Text“).
  function zeichnen(box, seite) {
    var akt = S.aktuell, soll = S.soll, v1 = seite.getViewport({ scale: 1 });
    var breite = Math.max(220, box.clientWidth || 420);
    var maxH = Math.max(280, Math.min(window.innerHeight * 0.62, 640));
    var randPt = (Number(soll.beschnitt) || 2) * MM + 4 * MM;          // Platz rund um die Seite für den Beschnitt-Rahmen
    var gesW = v1.width + 2 * randPt, gesH = v1.height + 2 * randPt;
    var anzeigeW = Math.min(breite, maxH * gesW / gesH);
    var dpr = window.devicePixelRatio || 1;
    var scale = Math.min(anzeigeW * dpr * 2.6 / gesW, 3600 / Math.max(gesW, gesH));
    var vp = seite.getViewport({ scale: scale });
    var P = randPt * scale;
    var c = document.createElement('canvas');
    c.width = Math.floor(vp.width + 2 * P); c.height = Math.floor(vp.height + 2 * P);
    c.style.width = Math.floor(anzeigeW) + 'px';
    var ctx = c.getContext('2d');
    ctx.fillStyle = '#e6e9e4'; ctx.fillRect(0, 0, c.width, c.height);
    ctx.fillStyle = '#ffffff'; ctx.fillRect(P, P, vp.width, vp.height);
    box.innerHTML = '<div class="muted" style="padding:30px 0;text-align:center">' + esc(S.txt.vorschau_lade) + '</div>';
    return seite.render({ canvasContext: ctx, viewport: vp, transform: [1, 0, 0, 1, P, P], background: 'rgba(0,0,0,0)' }).promise.then(function () {
      var pt = function (x, y) { var q = vp.convertToViewportPoint(x, y); return [q[0] + P, q[1] + P]; };
      var rect = function (r) { var a = pt(r[0], r[1]), b = pt(r[2], r[3]); return [Math.min(a[0], b[0]), Math.min(a[1], b[1]), Math.abs(b[0] - a[0]), Math.abs(b[1] - a[1])]; };
      var lw = Math.max(1.5, scale * 0.9);
      var bx = akt.boxen || {}, tr = trimBox(akt), b = Number(soll.beschnitt) || 2, si = Number(soll.sicher) || 2;
      if (tr) {
        var aussen = bx.BleedBox && (tr[0] - bx.BleedBox[0] > 0.3 * MM) ? bx.BleedBox : ((bx.MediaBox && tr[0] - bx.MediaBox[0] > 0.3 * MM) ? bx.MediaBox : [tr[0] - b * MM, tr[1] - b * MM, tr[2] + b * MM, tr[3] + b * MM]);
        var linie = function (r, farbe, strich) { var q = rect(r); ctx.save(); ctx.strokeStyle = farbe; ctx.lineWidth = lw; ctx.setLineDash(strich ? [lw * 4, lw * 3] : []); ctx.strokeRect(q[0], q[1], q[2], q[3]); ctx.restore(); };
        // Beutel-Vorlage (nur ohne Seitendrehung, Lage in mm von oben links des Endformats)
        var vl = S.art === 'beutel' ? soll.vorlage : null;
        if (vl && !akt.rotate) {
          var mm2 = function (x, y, w, h) { return [tr[0] + x * MM, tr[3] - (y + h) * MM, tr[0] + (x + w) * MM, tr[3] - y * MM]; };
          var muster = (function () { var m = document.createElement('canvas'), s2 = Math.max(8, Math.round(lw * 6)); m.width = m.height = s2; var g = m.getContext('2d'); g.strokeStyle = 'rgba(192,57,43,.55)'; g.lineWidth = Math.max(1, lw * 0.8); g.beginPath(); g.moveTo(0, s2); g.lineTo(s2, 0); g.stroke(); return ctx.createPattern(m, 'repeat'); })();
          (vl.zonen || []).forEach(function (z) {
            var q = rect(mm2(z.x, z.y, z.w, z.h)); ctx.save(); ctx.fillStyle = muster; ctx.fillRect(q[0], q[1], q[2], q[3]); ctx.restore();
            beschrift(ctx, z.name + ' – ' + S.txt.leg_zone, q[0] + lw * 3, q[1] + q[3] / 2 + lw * 2, lw, '#8f231b');
          });
          (vl.flaechen || []).forEach(function (f) {
            var q = rect(mm2(f.x, f.y, f.w, f.h)); ctx.save(); ctx.strokeStyle = '#1D9E75'; ctx.lineWidth = lw; ctx.setLineDash([lw * 1.5, lw * 2]); ctx.strokeRect(q[0], q[1], q[2], q[3]); ctx.restore();
            beschrift(ctx, f.name + (f.drehung === 180 ? ' (180°)' : ''), q[0] + lw * 3, q[1] + lw * 12, lw, '#0f6f50');
          });
        }
        linie(aussen, '#c0392b', true);
        linie(tr, '#1b241a', false);
        linie([tr[0] + si * MM, tr[1] + si * MM, tr[2] - si * MM, tr[3] - si * MM], '#2b7bbf', true);
      }
      box.innerHTML = ''; box.appendChild(c);
      // Zoom per Klick: Canvas 2,6-fach, an die Klickstelle scrollen; erneuter Klick zurück
      c.addEventListener('click', function (e) {
        var zoom = !box.classList.contains('zoom');
        var rx = e.offsetX / c.clientWidth, ry = e.offsetY / c.clientHeight;
        box.classList.toggle('zoom', zoom);
        c.style.width = zoom ? Math.floor(anzeigeW * 2.6) + 'px' : Math.floor(anzeigeW) + 'px';
        if (zoom) { box.scrollLeft = rx * c.clientWidth - box.clientWidth / 2; box.scrollTop = ry * c.clientHeight - box.clientHeight / 2; }
      });
    });
  }
  function beschrift(ctx, text, x, y, lw, farbe) {
    ctx.save(); ctx.font = '600 ' + Math.round(lw * 9) + 'px Segoe UI, Arial, sans-serif';
    var w = ctx.measureText(text).width; ctx.fillStyle = 'rgba(255,255,255,.85)'; ctx.fillRect(x - lw * 2, y - lw * 9, w + lw * 4, lw * 12);
    ctx.fillStyle = farbe; ctx.fillText(text, x, y); ctx.restore();
  }
  // Flächen der Beutel-Vorlage einzeln ansehen (gedrehte Fläche richtig herum)
  function flaechenKnoepfe(seite) {
    var box = D.querySelector('.bx-et-flaechen'), vl = S.art === 'beutel' ? S.soll.vorlage : null;
    if (!box || !vl || !(vl.flaechen || []).length || S.aktuell.rotate) return;
    box.innerHTML = vl.flaechen.map(function (f, i) { return '<button type="button" class="btn btn-ghost btn-sm" data-fl="' + i + '">' + esc(f.name) + (f.drehung === 180 ? ' – ' + esc(S.txt.drehen) : '') + '</button>'; }).join('') + '<div class="bx-et-flaeche"></div>';
    Array.prototype.forEach.call(box.querySelectorAll('[data-fl]'), function (b) {
      b.onclick = function () {
        var f = vl.flaechen[+b.getAttribute('data-fl')], ziel = box.querySelector('.bx-et-flaeche');
        if (ziel.__fl === b.getAttribute('data-fl')) { ziel.innerHTML = ''; ziel.__fl = null; return; }
        ziel.__fl = b.getAttribute('data-fl'); ziel.innerHTML = '<div class="muted">…</div>';
        ausschnitt(seite, f, 1100).then(function (cv) { cv.style.width = '100%'; cv.style.height = 'auto'; ziel.innerHTML = ''; ziel.appendChild(cv); });
      };
    });
  }
  // Ausschnitt einer Fläche (mm ab oben links des Endformats) als Canvas, lange Seite = px, gedreht wie in der Vorlage.
  function ausschnitt(seite, f, px) {
    var tr = trimBox(S.aktuell); if (!tr) return Promise.reject();
    var wPt = f.w * MM, hPt = f.h * MM, s = px / Math.max(wPt, hPt);
    var vp = seite.getViewport({ scale: s });
    var p0 = vp.convertToViewportPoint(tr[0] + f.x * MM, tr[3] - f.y * MM);
    var c = document.createElement('canvas'); c.width = Math.round(wPt * s); c.height = Math.round(hPt * s);
    var ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
    return seite.render({ canvasContext: ctx, viewport: vp, transform: [1, 0, 0, 1, -p0[0], -p0[1]] }).promise.then(function () {
      if (f.drehung !== 180) return c;
      var r = document.createElement('canvas'); r.width = c.width; r.height = c.height;
      var g = r.getContext('2d'); g.translate(r.width, r.height); g.rotate(Math.PI); g.drawImage(c, 0, 0); return r;
    });
  }
  // Messung im Browser: Bilder in Druckgröße (dpi) über die Operatorliste, Text in Siegel-/Zipper-Zonen über die Textlage.
  function messen(seite) {
    var lib = window.pdfjsLib, O = lib.OPS, tr = trimBox(S.aktuell) || [0, 0, 0, 0];
    var mul = function (m, c) { return [m[0] * c[0] + m[1] * c[2], m[0] * c[1] + m[1] * c[3], m[2] * c[0] + m[3] * c[2], m[2] * c[1] + m[3] * c[3], m[4] * c[0] + m[5] * c[2] + c[4], m[4] * c[1] + m[5] * c[3] + c[5]]; };
    return seite.getOperatorList().then(function (ol) {
      var ctm = [1, 0, 0, 1, 0, 0], stapel = [], bilder = [], n = 0;
      for (var i = 0; i < ol.fnArray.length; i++) {
        var fn = ol.fnArray[i], a = ol.argsArray[i];
        if (fn === O.save) stapel.push(ctm.slice());
        else if (fn === O.restore) ctm = stapel.pop() || ctm;
        else if (fn === O.transform) ctm = mul(a, ctm);
        else if (fn === O.paintFormXObjectBegin) { stapel.push(ctm.slice()); if (a && a[0] && a[0].length === 6) ctm = mul(a[0], ctm); }
        else if (fn === O.paintFormXObjectEnd) ctm = stapel.pop() || ctm;
        else if (fn === O.paintImageXObject || fn === O.paintInlineImageXObject || fn === O.paintJpegXObject) {
          var w = 0, h = 0;
          if (fn === O.paintInlineImageXObject) { w = a[0] && a[0].width; h = a[0] && a[0].height; }
          else { w = a[1]; h = a[2]; if (!(w > 0)) { try { var im = seite.objs.get(a[0]); w = im.width || (im.bitmap && im.bitmap.width); h = im.height || (im.bitmap && im.bitmap.height); } catch (e) {} } }
          if (!(w > 0 && h > 0)) continue;
          var bw = Math.hypot(ctm[0], ctm[1]), bh = Math.hypot(ctm[2], ctm[3]);
          if (bw < 1 || bh < 1) continue;
          var xs = [ctm[4], ctm[4] + ctm[0], ctm[4] + ctm[2], ctm[4] + ctm[0] + ctm[2]], ys = [ctm[5], ctm[5] + ctm[1], ctm[5] + ctm[3], ctm[5] + ctm[1] + ctm[3]];
          n++;
          bilder.push({ n: n, w: w, h: h, dpi: Math.min(w / (bw / 72), h / (bh / 72)), b_mm: bw / MM, h_mm: bh / MM,
                        x_mm: (Math.min.apply(null, xs) - tr[0]) / MM, y_mm: (tr[3] - Math.max.apply(null, ys)) / MM });
        }
      }
      var vl = S.art === 'beutel' ? S.soll.vorlage : null;
      if (!vl || !(vl.zonen || []).length || S.aktuell.rotate) return { pdfjs: true, bilder: bilder, zonen: [] };
      return seite.getTextContent().then(function (tc) {
        var treffer = [];
        tc.items.forEach(function (it) {
          if (!it.str || !it.str.trim()) return;
          var x = it.transform[4], y = it.transform[5], w = it.width || 0, hh = it.height || Math.hypot(it.transform[2], it.transform[3]);
          var x0 = (x - tr[0]) / MM, x1 = (x + w - tr[0]) / MM, yo = (tr[3] - (y + hh)) / MM, yu = (tr[3] - y) / MM;
          vl.zonen.forEach(function (z) {
            if (x1 > z.x && x0 < z.x + z.w && yu > z.y && yo < z.y + z.h) treffer.push({ zone: z.name, text: it.str.trim() });
          });
        });
        return { pdfjs: true, bilder: bilder, zonen: treffer };
      });
    }).catch(function () { return { pdfjs: false }; });
  }
  // Bilder für die KI: je Fläche (Beutel-Vorlage) bzw. das ganze Etikett, lange Flächen in zwei Hälften mit Überlappung,
  // lange Seite 1568 px (die KI verkleinert größere Bilder), gedrehte Flächen richtig herum.
  function kiBilder(seite) {
    var tr = trimBox(S.aktuell); if (!tr) return Promise.resolve([]);
    var W = (tr[2] - tr[0]) / MM, H = (tr[3] - tr[1]) / MM;
    var vl = S.art === 'beutel' && !S.aktuell.rotate ? S.soll.vorlage : null;
    var fl = vl && (vl.flaechen || []).length ? vl.flaechen : [{ name: S.art === 'beutel' ? 'Druckdatei' : 'Etikett', x: 0, y: 0, w: W, h: H, drehung: 0 }];
    var teile = [];
    fl.forEach(function (f) {
      var lang = Math.max(f.w, f.h);
      if (lang <= 150) { teile.push({ titel: f.name + (f.drehung === 180 ? ' (gedreht, richtig herum)' : ''), f: f }); return; }
      var hoch = f.h >= f.w, ue = 8, anz = Math.ceil(lang / 170), stueck = (lang + (anz - 1) * ue) / anz;
      for (var k = 0; k < anz; k++) {
        var ab = k * (stueck - ue);
        var g = hoch ? { x: f.x, w: f.w, y: f.y + ab, h: stueck } : { y: f.y, h: f.h, x: f.x + ab, w: stueck };
        g.drehung = f.drehung; g.name = f.name;
        // bei gedrehter Fläche ist der obere Teil des Drucks das Ende des Textes
        var nr = f.drehung === 180 ? anz - k : k + 1;
        teile.push({ titel: f.name + ' (' + nr + '/' + anz + (f.drehung === 180 ? ', gedreht, richtig herum' : '') + ')', f: g });
      }
    });
    var out = [];
    return teile.reduce(function (p, tl) {
      return p.then(function () { return ausschnitt(seite, tl.f, 1568).then(function (cv) { out.push({ titel: tl.titel, daten: cv.toDataURL('image/jpeg', 0.86) }); }); });
    }, Promise.resolve()).then(function () { return out.slice(0, 12); });
  }
  function kiAnzeigen(ki) {
    var box = D && D.querySelector('.bx-et-kiinhalt'); if (!box) return;
    box.innerHTML = kiHtml(ki); binden();
  }
  function kiStarten(nochmal) {
    if (!SEITE || KI_LAEUFT) return;
    KI_LAEUFT = true; kiAnzeigen({ status: 'laeuft' });
    var pid = S.aktuell.id, aid = S.aid;
    var ab = window.AbortController ? new AbortController() : null;
    var uhr = setTimeout(function () { if (ab) ab.abort(); }, 95000);
    kiBilder(SEITE).then(function (bilder) {
      var body = { pid: pid, bilder: bilder }; if (ab) body.__signal = ab.signal;
      return api('ki', aid, body);
    }).then(function (s) {
      clearTimeout(uhr); KI_LAEUFT = false;
      if (!D.open || !S || S.aid !== aid || !s || !s.ok || !s.aktuell || s.aktuell.id !== pid) return;
      S.aktuell.ki = s.aktuell.ki; S.verlauf = s.verlauf; kiAnzeigen(s.aktuell.ki);
    }).catch(function () {
      clearTimeout(uhr); KI_LAEUFT = false;
      if (D.open && S && S.aid === aid) { S.aktuell.ki = { status: 'nicht_moeglich' }; kiAnzeigen(S.aktuell.ki); }
    });
  }
  function pollen() {
    var aid = S.aid, n = 0;
    POLL = setInterval(function () {
      if (++n > 40 || !D.open) { clearInterval(POLL); POLL = null; return; }
      api('status', aid).then(function (s) {
        if (!s || !s.ok || !s.aktuell || !S || S.aid !== aid) return;
        if (s.aktuell.ki && s.aktuell.ki.status !== 'laeuft') { clearInterval(POLL); POLL = null; S.aktuell.ki = s.aktuell.ki; kiAnzeigen(s.aktuell.ki); }
      });
    }, 5000);
  }

  // ------------------------------------------------------------------ 3 Freigeben
  function freigeben() {
    var t = S.txt, d = D, msg = d.querySelector('.bx-et-freimsg');
    var wert = function (n) { var e = d.querySelector('[name="' + n + '"]'); return e ? (e.type === 'checkbox' ? e.checked : e.value.trim()) : ''; };
    var fehlt = function (n, text) { var e = d.querySelector('[name="' + n + '"]'); if (e) { (e.closest('label') || e).classList.add('bx-et-markiert'); e.focus(); } msg.className = 'bx-et-freimsg fehler'; msg.textContent = text; };
    Array.prototype.forEach.call(d.querySelectorAll('.bx-et-markiert'), function (e) { e.classList.remove('bx-et-markiert'); });
    if (d.querySelector('[name=trotzdem]') && !wert('trotzdem')) return fehlt('trotzdem', t.trotzdem);
    if (!wert('haken')) return fehlt('haken', t.pflicht);
    if (!wert('name')) return fehlt('name', t.name);
    if (S.wer === 'team' && !wert('grund')) return fehlt('grund', t.grund);
    var b = d.querySelector('[data-et-frei]'); b.disabled = true;
    api('freigeben', S.aid, { pid: S.aktuell.id, haken: 1, name: wert('name'), grund: wert('grund'), trotzdem: wert('trotzdem') ? 1 : 0 }).then(function (r) {
      b.disabled = false;
      if (!r || !r.ok) { msg.className = 'bx-et-freimsg fehler'; msg.textContent = (r && r.fehler) || t.fehler; return; }
      d.__dirty = false;
      d.querySelector('.bx-et-inhalt').innerHTML = '<div class="bx-et-danke"><span>' + ICO.haken + '</span><div>' + esc(t.danke) + '</div></div>';
      d.querySelector('.bx-pop-fuss').innerHTML = '';
      setTimeout(function () { schliessen(); nachFreigabe(); }, 1500);
    }).catch(function () { b.disabled = false; msg.className = 'bx-et-freimsg fehler'; msg.textContent = t.fehler; });
  }
  function nachFreigabe() {
    if (S && S.wer === 'team') { location.reload(); return; }
    bandNeu();
    if (document.querySelector('[data-etp-neuladen]')) location.reload();
  }
  function bandNeu() {
    var alt = document.getElementById('bxEtBand'); if (!alt || !S || S.wer !== 'kunde') return;
    api('band', 0).then(function (r) {
      if (!r || !r.ok) return;
      var tmp = document.createElement('div'); tmp.innerHTML = r.html; var neu = tmp.firstElementChild;
      var jetzt = document.getElementById('bxEtBand'); if (neu && jetzt) jetzt.replaceWith(neu);
    });
  }
  // Auswahl bei mehreren Aufträgen (Band „Ansehen“)
  function liste(eintraege, titel) {
    var d = document.createElement('dialog'); d.className = 'bx-dialog bx-et-liste'; d.setAttribute('data-pop-temp', '1');
    d.innerHTML = '<div class="bx-pop-kopf"><h2></h2><button type="button" class="bx-pop-x" aria-label="Schließen">&times;</button></div><div class="bx-et-wahl"></div>';
    d.querySelector('.bx-pop-x').onclick = function () { if (window.bxPopup) bxPopup.close(d, true); else d.close(); };
    d.querySelector('h2').textContent = titel || '';
    d.querySelector('.bx-et-wahl').innerHTML = eintraege.map(function (e) {
      return '<button type="button" class="bx-et-wahl-z" data-aid="' + e.id + '"><span><b>' + esc(e.nummer) + '</b> ' + esc(e.produkt) + '</span><span class="bx-et-wahl-s ' + (e.zustand === 'fehlt' ? 'rot' : 'gelb') + '">' + esc(e.zustand_text) + '</span></button>';
    }).join('');
    document.body.appendChild(d);
    if (window.bxPopup) bxPopup.open(d); else d.showModal();
    Array.prototype.forEach.call(d.querySelectorAll('[data-aid]'), function (b) { b.onclick = function () { if (window.bxPopup) bxPopup.close(d, true); else d.close(); oeffnen(+b.getAttribute('data-aid')); }; });
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-etp-oeffnen],[data-etp-liste]');
    if (!a) return;
    e.preventDefault();
    if (a.hasAttribute('data-etp-oeffnen')) { oeffnen(+a.getAttribute('data-etp-oeffnen')); return; }
    var l = []; try { l = JSON.parse(a.getAttribute('data-etp-liste')); } catch (x) {}
    var txt = START.txt || {};
    l.forEach(function (x) { x.zustand_text = x.zustand === 'fehlt' ? (txt.fehlt || 'Etikett fehlt') : (txt.freigabe_fehlt || 'Freigabe fehlt'); });
    liste(l, txt.liste_titel || '');
  });
  window.bxEtikett = { oeffnen: oeffnen };
  function auto() {
    if (!START.auto) return;
    oeffnen(+START.auto);
    // &etikett=AID aus der Adresse nehmen, damit ein Neuladen (z. B. nach der Freigabe) das Pop-up nicht wieder öffnet
    try { var u = new URL(location.href); u.searchParams.delete('etikett'); history.replaceState(null, '', u.toString()); } catch (e) {}
  }
  if (document.readyState !== 'loading') auto(); else document.addEventListener('DOMContentLoaded', auto);
})();
