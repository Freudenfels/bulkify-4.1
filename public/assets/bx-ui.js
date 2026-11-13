/* bx-ui.js – Oberflächen-Standard bulkify (Nico 13.11.2026), EIN Baustein für Dashboard, Portale und Sub-Apps.
 *
 * 1. Pop-up: jedes <dialog class="bx-dialog"> (auch nachträglich eingefügte) bekommt das Standard-Verhalten:
 *    - abgedunkelter Hintergrund, mittig, auf dem Handy fast vollflächig
 *    - Kopf (erste Überschrift) mit Schließen-X bleibt oben stehen, Knopfzeile (.bx-pop-fuss oder die letzte Zeile
 *      mit Absenden-Knopf eines Formulars) bleibt unten stehen, der Inhalt dazwischen scrollt im Pop-up
 *    - Escape und Klick neben das Pop-up schließen es – mit Rückfrage, wenn etwas Ungespeichertes drin ist
 *    - Formulare mit data-pop-ajax speichern ohne Seitenwechsel; danach werden die Elemente aus data-pop-refresh
 *      (CSS-Selektoren, Komma) aus der frisch geladenen Seite ersetzt, sonst die ganze Seite neu geladen
 *    - bxPopup.url(url, titel, refresh): lädt eine Seite und zeigt ihren Inhalt ([data-pop-inhalt] bzw. .bx-main) im Pop-up
 *    - bxPopup.open(id) / bxPopup.close(id)
 * 2. Auswahl mit Suche: steckt in bx_rscombo_script() (core/layout.php) – jedes <select> mit mehr als 10 Einträgen.
 *    Hier nur der Einstieg window.bxRsComboInit für Pop-up-Inhalte.
 * 3. Lauftext: jedes Element mit text-overflow: ellipsis (z. B. Klasse .bx-lauf), dessen Text nicht hineinpasst, läuft beim
 *    Darüberfahren langsam durch; auf dem Handy beim Antippen.
 */
(function () {
  if (window.bxUi) return;
  window.bxUi = 1;
  // Texte in der Sprache der Seite (<html lang>): Portale auf Englisch/Chinesisch fragen nicht mehr fest auf Deutsch (13.11.2026).
  var LANG = ((document.documentElement.getAttribute('lang') || 'de').slice(0, 2)).toLowerCase();
  var TX = {
    zu:    {de: 'Schließen', en: 'Close', zh: '关闭', uk: 'Закрити'},
    frage: {de: 'Es gibt ungespeicherte Eingaben. Pop-up trotzdem schließen?', en: 'There are unsaved entries. Close anyway?', zh: '有未保存的输入。仍然关闭吗？', uk: 'Є незбережені дані. Все одно закрити?'},
    speichern: {de: 'Speichern hat nicht geklappt. Bitte erneut versuchen.', en: 'Saving did not work. Please try again.', zh: '保存失败，请重试。', uk: 'Не вдалося зберегти. Спробуйте ще раз.'},
    netz:  {de: 'Keine Verbindung. Bitte erneut versuchen.', en: 'No connection. Please try again.', zh: '无网络连接，请重试。', uk: 'Немає зʼєднання. Спробуйте ще раз.'},
    laedt: {de: 'Lädt …', en: 'Loading …', zh: '加载中…', uk: 'Завантаження …'},
    fehler: {de: 'Konnte nicht geladen werden.', en: 'Could not be loaded.', zh: '无法加载。', uk: 'Не вдалося завантажити.'},
    suchen: {de: 'Suchen…', en: 'Search…', zh: '搜索…', uk: 'Пошук…'},
    liste: {de: 'Bitte aus der Liste auswählen.', en: 'Please choose from the list.', zh: '请从列表中选择。', uk: 'Будь ласка, виберіть зі списку.'},
    datei: {de: 'Datei wählen', en: 'Choose file', zh: '选择文件', uk: 'Вибрати файл'},
    dateien: {de: 'Dateien wählen', en: 'Choose files', zh: '选择文件', uk: 'Вибрати файли'},
    keine: {de: 'keine gewählt', en: 'none chosen', zh: '未选择', uk: 'не вибрано'},
    ndateien: {de: 'Dateien', en: 'files', zh: '个文件', uk: 'файлів'}
  };
  function tx(k) { var e = TX[k] || {}; return e[LANG] || e.en || e.de || k; }
  window.bxTx = tx;

  var css = ''
    + '.bx-dialog{max-height:calc(100vh - 48px);overflow:auto;padding:0 !important;display:none;flex-direction:column}'
    + '.bx-dialog[open]{display:flex}'
    + '.bx-dialog>*:not(.bx-pop-kopf){margin-left:22px;margin-right:22px}'
    + '.bx-dialog>*:last-child{margin-bottom:20px}'
    + '.bx-pop-kopf{position:sticky;top:0;z-index:3;display:flex;align-items:center;gap:12px;justify-content:space-between;background:var(--panel,#fff);padding:14px 16px 12px 22px;border-bottom:1px solid var(--line,#e3e3e3);margin:0 0 14px}'
    + '.bx-pop-kopf>h2,.bx-pop-kopf>h3{margin:0 !important;font-size:17px;line-height:1.3;flex:1;min-width:0}'
    + '.bx-pop-x{flex:none;width:34px;height:34px;border-radius:8px;border:1px solid var(--line,#ddd);background:transparent;color:var(--text,#222);font-size:20px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center}'
    + '.bx-pop-x:hover{background:rgba(127,127,127,.12)}'
    + '.bx-pop-fuss{position:sticky;bottom:0;z-index:3;background:var(--panel,#fff);padding:12px 0 2px;margin-top:14px;border-top:1px solid var(--line,#e3e3e3);display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;align-items:center}'
    + '@media (max-width:640px){.bx-dialog{width:calc(100% - 12px) !important;max-width:none !important;max-height:calc(100vh - 12px)}.bx-dialog>*:not(.bx-pop-kopf){margin-left:14px;margin-right:14px}.bx-pop-kopf{padding-left:14px}}'
    + '.bx-lauf{display:block;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '.bx-lauf-an{text-overflow:clip !important}'
    + '.bx-lauf-in{display:inline-block;will-change:transform}';
  var st = document.createElement('style'); st.id = 'bxUiStyle'; st.textContent = css;
  (document.head || document.documentElement).appendChild(st);

  // ---------------------------------------------------------------- Pop-up
  function kopf(d) {
    if (d.querySelector(':scope > .bx-pop-kopf')) return;
    // Titel = erste Überschrift, vor der kein sichtbares Eingabefeld steht. Steht sie in einer Kopfzeile (Zeile nur mit Überschrift
    // und Knöpfen, z. B. $dlgKopf), wird die ganze Zeile zum Kopf; ein eigener „Schließen“-Knopf darin weicht dem X.
    var h = d.querySelector('h2,h3'), feld = d.querySelector('input:not([type=hidden]),select,textarea');
    if (h && feld && (feld.compareDocumentPosition(h) & Node.DOCUMENT_POSITION_FOLLOWING)) h = null;
    var k, row = h ? h.parentElement : null;
    if (row && row !== d && row.tagName === 'DIV' && row.children.length <= 3 && !row.querySelector('input:not([type=hidden]),select,textarea,table')) {
      k = row; k.classList.add('bx-pop-kopf'); k.removeAttribute('style');
      Array.prototype.forEach.call(k.querySelectorAll('button,a'), function (b) {
        if (/schlie|close|abbrech|×|✕/i.test(b.textContent + ' ' + (b.getAttribute('aria-label') || '')) || /close\(\)/.test(b.getAttribute('onclick') || '')) b.remove();
      });
      d.insertBefore(k, d.firstChild);
    } else {
      k = document.createElement('div'); k.className = 'bx-pop-kopf';
      if (h) k.appendChild(h);
      else { var t = document.createElement('h2'); t.textContent = d.getAttribute('data-titel') || ''; k.appendChild(t); }
      d.insertBefore(k, d.firstChild);
    }
    // Wrapper ohne eigenen Inhalt mehr (z. B. <div style="padding:…">) behalten ihr Padding nicht doppelt
    if (!k.querySelector('.bx-pop-x')) {
      var x = document.createElement('button'); x.type = 'button'; x.className = 'bx-pop-x'; x.setAttribute('aria-label', tx('zu')); x.innerHTML = '&times;';
      x.addEventListener('click', function () { zu(d); });
      k.appendChild(x);
    }
  }
  function fuss(d) {
    // Knopfzeile: ausdrücklich .bx-pop-fuss, sonst je Formular das letzte direkte Kind mit einem Absenden-Knopf
    var formen = d.querySelectorAll('form');
    if (formen.length !== 1) return;   // mehrere Formulare (Abschnitte mit eigenem Speichern): keine feste Knopfzeile
    Array.prototype.forEach.call(formen, function (f) {
      if (f.querySelector('.bx-pop-fuss')) return;
      var kids = f.children, z = null;
      for (var i = kids.length - 1; i >= 0; i--) {
        var c = kids[i];
        if (c.tagName === 'INPUT' && c.type === 'hidden') continue;
        if ((c.tagName === 'BUTTON' && c.type !== 'button') || (c.querySelector && c.querySelector('button[type=submit],button:not([type])') && c.querySelectorAll('input:not([type=hidden]),select,textarea').length === 0)) z = c;
        break;
      }
      if (!z) return;
      if (z.tagName === 'BUTTON') { var w = document.createElement('div'); f.insertBefore(w, z); w.appendChild(z); z = w; }
      z.classList.add('bx-pop-fuss');
    });
  }
  function dirty(d) { return !!d.__dirty; }
  function zu(d, ohneFrage) {
    if (!ohneFrage && dirty(d) && !confirm(tx('frage'))) return false;
    d.__dirty = false;
    try { d.close(); } catch (e) { d.removeAttribute('open'); }
    if (d.hasAttribute('data-pop-temp')) d.remove();
    return true;
  }
  function refresh(sel, html) {
    var doc = new DOMParser().parseFromString(html, 'text/html'), alle = true;
    sel.split(',').forEach(function (s) {
      s = s.trim(); if (!s) return;
      var neu = doc.querySelector(s), alt = document.querySelector(s);
      if (neu && alt) { alt.replaceWith(neu); if (window.bxRsComboInit) window.bxRsComboInit(neu); } else alle = false;
    });
    return alle;
  }
  function ajax(f, d) {
    f.addEventListener('submit', function (e) {
      if (e.defaultPrevented) return;
      e.preventDefault(); e.stopImmediatePropagation();
      var b = e.submitter, fd = new FormData(f);
      if (b && b.name) fd.append(b.name, b.value);
      if (b) { b.disabled = true; b.classList.add('is-busy'); }
      fetch(f.getAttribute('action') || location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.text().then(function (t) { return { ok: r.ok, t: t }; }); })
        .then(function (r) {
          if (b) { b.disabled = false; b.classList.remove('is-busy'); }
          if (!r.ok) { alert(tx('speichern')); return; }
          d.__dirty = false;
          var sel = f.getAttribute('data-pop-refresh') || (d && d.getAttribute('data-pop-refresh')) || '';
          // Antwort ist die Seite nach dem Speichern (Weiterleitung) – Zeilen daraus übernehmen, sonst Seite neu laden
          if (sel && refresh(sel, r.t)) { zu(d, true); return; }
          if (sel) { fetch(location.href, { credentials: 'same-origin' }).then(function (x) { return x.text(); }).then(function (t) { if (refresh(sel, t)) zu(d, true); else location.reload(); }); return; }
          location.reload();
        })
        .catch(function () { if (b) { b.disabled = false; b.classList.remove('is-busy'); } alert(tx('netz')); });
    }, true);
  }
  function aufwerten(d) {
    if (d.__bxPop) return; d.__bxPop = 1;
    kopf(d); fuss(d);
    d.addEventListener('input', function () { d.__dirty = true; });
    d.addEventListener('change', function () { d.__dirty = true; });
    d.addEventListener('submit', function () { d.__dirty = false; });
    d.addEventListener('cancel', function (e) { e.preventDefault(); zu(d); });   // Escape
    d.addEventListener('mousedown', function (e) {   // Klick neben das Pop-up (auf den abgedunkelten Hintergrund)
      if (e.target !== d) return;
      var r = d.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) zu(d);
    });
    Array.prototype.forEach.call(d.querySelectorAll('form[data-pop-ajax]'), function (f) { ajax(f, d); });
    // Ein schon offenes Pop-up (<dialog open>) ist nicht modal – als modales neu öffnen (Hintergrund abgedunkelt)
    if (d.hasAttribute('open') && !d.matches(':modal')) { try { d.removeAttribute('open'); d.showModal(); } catch (e) { d.setAttribute('open', ''); } }
  }
  function alle(root) { Array.prototype.forEach.call((root || document).querySelectorAll('dialog.bx-dialog'), aufwerten); }

  window.bxPopup = {
    open: function (id) { var d = typeof id === 'string' ? document.getElementById(id) : id; if (!d) return; aufwerten(d); d.__dirty = false; if (!d.open) d.showModal(); },
    close: function (id, ohneFrage) { var d = typeof id === 'string' ? document.getElementById(id) : id; if (d) zu(d, ohneFrage); },
    // Seite laden und im Pop-up zeigen. refresh = Selektoren, die nach dem Speichern auf der Seite darunter ersetzt werden.
    url: function (url, titel, refreshSel) {
      var d = document.createElement('dialog'); d.className = 'bx-dialog'; d.setAttribute('data-pop-temp', '1'); d.style.maxWidth = '980px';
      if (refreshSel) d.setAttribute('data-pop-refresh', refreshSel);
      d.innerHTML = '<div class="bx-pop-kopf"><h2></h2></div><div class="muted" style="padding:10px 0 20px">' + tx('laedt') + '</div>';
      d.querySelector('h2').textContent = titel || '';
      document.body.appendChild(d); aufwerten(d); d.showModal();
      fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (t) {
        var doc = new DOMParser().parseFromString(t, 'text/html');
        var inh = doc.querySelector('[data-pop-inhalt]') || doc.querySelector('.bx-main') || doc.body;
        var box = d.children[1]; box.className = ''; box.style.padding = ''; box.innerHTML = inh.innerHTML;
        // Formulare im geladenen Inhalt speichern ohne Seitenwechsel; Ziel = geladene Adresse
        Array.prototype.forEach.call(box.querySelectorAll('form'), function (f) {
          if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
          if (!f.getAttribute('action')) f.setAttribute('action', url);
          f.setAttribute('data-pop-ajax', '1'); ajax(f, d);
        });
        Array.prototype.forEach.call(box.querySelectorAll('script'), function (s) { var n = document.createElement('script'); n.textContent = s.textContent; s.replaceWith(n); });
        fuss(d); if (window.bxRsComboInit) window.bxRsComboInit(box);
      }).catch(function () { d.children[1].textContent = tx('fehler'); });
      return d;
    }
  };
  // Deklarativ: <a data-pop-url="?p=…" data-pop-titel="…" data-pop-refresh="#zeile12">
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-pop-url]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey) return;
    e.preventDefault();
    bxPopup.url(a.getAttribute('data-pop-url'), a.getAttribute('data-pop-titel') || a.textContent.trim(), a.getAttribute('data-pop-refresh') || '');
  });

  // ---------------------------------------------------------------- Reiter (Bestellseite 13.11.2026)
  // <div class="bs-reiter"> mit [data-bs-tab="k"] und [data-bs-panel="k"]; geht auch im nachgeladenen Pop-up (Ereignis am document).
  function reiter(k, root) {
    var r = root || document.querySelector('.bs-reiter'); if (!r) return false;
    if (!r.querySelector('[data-bs-panel="' + k + '"]')) return false;
    Array.prototype.forEach.call(r.querySelectorAll('[data-bs-tab]'), function (t) { t.classList.toggle('on', t.getAttribute('data-bs-tab') === k); });
    Array.prototype.forEach.call(r.querySelectorAll('[data-bs-panel]'), function (p) { p.hidden = p.getAttribute('data-bs-panel') !== k; });
    return true;
  }
  window.bxReiter = reiter;
  document.addEventListener('click', function (e) {
    var t = e.target.closest && e.target.closest('[data-bs-tab]');
    if (t) { reiter(t.getAttribute('data-bs-tab'), t.closest('.bs-reiter')); merk(t.getAttribute('data-bs-tab')); return; }
    // Menü „⋯“ (details.bs-menu) schließt bei Klick daneben
    var mi = e.target.closest && e.target.closest('.bs-menu-liste button[onclick], .bs-menu-liste a');
    Array.prototype.forEach.call(document.querySelectorAll('details.bs-menu[open]'), function (m) { if (!m.contains(e.target) || (mi && m.contains(mi))) m.removeAttribute('open'); });
  });
  // Anker öffnen den passenden Reiter (#unterlagen, #wareneingang, #zahlung …), #rechnung/#bezahlt/#unterlagen zusätzlich das Pop-up
  function reiterAnker() {
    if (!document.querySelector('.bs-reiter')) return;
    var map = { unterlagen: 'wareneingang', wareneingang: 'wareneingang', abgleich: 'wareneingang', chargen: 'wareneingang', chargenbezug: 'wareneingang', aufkleber: 'wareneingang',
                versand: 'versand', zahlung: 'zahlung', rechnung: 'zahlung', bezahlt: 'zahlung', nachrichten: 'nachrichten', rueckfragen: 'nachrichten', verlauf: 'verlauf', kosten: 'kosten', preise: 'kosten' };
    var pop = { unterlagen: 'dlgUnterlagen', rechnung: 'dlgRechnung', bezahlt: 'dlgBezahlt' };
    var h = (location.hash || '').replace('#', ''), q = new URLSearchParams(location.search).get('tab') || '';
    var k = map[h] || map[q];
    if (!k && q && document.querySelector('[data-bs-panel="' + q + '"]')) k = q;
    if (!k && h) {   // 13.11.2026: Anker auf ein Element in einem Reiter (#unterchargen, #nachtragen …) öffnet diesen Reiter
      var el = document.getElementById(h), pn = el && el.closest && el.closest('[data-bs-panel]');
      if (pn) { k = pn.getAttribute('data-bs-panel'); if (reiter(k, pn.closest('.bs-reiter'))) { setTimeout(function () { el.scrollIntoView({ block: 'start' }); }, 50); return; } }
    }
    if (!k && !h) { try { k = sessionStorage.getItem(merkKey()) || ''; } catch (e) { k = ''; } }   // zuletzt gewählter Reiter dieser Seite
    if (k) reiter(k);
    if (pop[h] && document.getElementById(pop[h])) setTimeout(function () { bxPopup.open(pop[h]); }, 150);
  }
  function merkKey() { var u = new URLSearchParams(location.search); return 'bxReiter:' + (u.get('p') || '') + ':' + (u.get('id') || ''); }
  function merk(k) { try { sessionStorage.setItem(merkKey(), k); } catch (e) {} }
  window.addEventListener('hashchange', reiterAnker);

  // ---------------------------------------------------------------- Lauftext
  function lauf(el) {
    if (el.__lauf || el.scrollWidth - el.clientWidth <= 2) return;
    var cs = getComputedStyle(el); if (cs.textOverflow !== 'ellipsis') return;
    var span = el.querySelector(':scope > .bx-lauf-in');
    if (!span) { span = document.createElement('span'); span.className = 'bx-lauf-in'; while (el.firstChild) span.appendChild(el.firstChild); el.appendChild(span); }
    var ov = span.scrollWidth - el.clientWidth; if (ov <= 2) return;
    el.__lauf = 1; el.classList.add('bx-lauf-an');
    var dauer = Math.min(14, Math.max(1.5, ov / 45));   // langsam: etwa 45 px pro Sekunde
    span.style.transition = 'transform ' + dauer + 's linear .3s'; span.style.transform = 'translateX(-' + ov + 'px)';
    if (!el.title) el.title = span.textContent.trim();
  }
  function stopp(el) {
    if (!el.__lauf) return; el.__lauf = 0;
    var span = el.querySelector(':scope > .bx-lauf-in'); if (span) { span.style.transition = 'transform .25s ease'; span.style.transform = ''; }
    setTimeout(function () { if (!el.__lauf) el.classList.remove('bx-lauf-an'); }, 260);
  }
  function lauffaehig(t) {
    for (var i = 0; i < 4 && t && t !== document.body; i++, t = t.parentElement) {
      if (t.nodeType === 1 && !/^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(t.tagName) && t.scrollWidth - t.clientWidth > 2 && getComputedStyle(t).textOverflow === 'ellipsis') return t;
    }
    return null;
  }
  var aktiv = null;
  document.addEventListener('mouseover', function (e) { var el = lauffaehig(e.target); if (el === aktiv) return; if (aktiv) stopp(aktiv); aktiv = el; if (el) lauf(el); });
  document.addEventListener('touchstart', function (e) { var el = lauffaehig(e.target); if (!el) { if (aktiv) { stopp(aktiv); aktiv = null; } return; } if (el.__lauf) { stopp(el); aktiv = null; } else { if (aktiv) stopp(aktiv); aktiv = el; lauf(el); } }, { passive: true });

  // ---------------------------------------------------------------- Einmal-Kennung (Fable-6 T2.6, 13.11.2026)
  // Jedes normal abgeschickte POST-Formular bekommt ein verstecktes Feld _einmal mit einer zufälligen Kennung. Der Server
  // (core/einmal.php) nimmt jede Kennung nur einmal an – ein Doppelklick bucht nie doppelt. Läuft in der Bubble-Phase auf window,
  // also nach allen anderen Prüfungen (confirm, Pflichtfelder): abgebrochene oder per JS (fetch) gesendete Formulare bekommen keine.
  // Der Knopf wird nach dem ersten Klick gesperrt (wo nicht schon der Seitenfuß des Dashboards das tut); Rückfall nach 10 s.
  function einmalId() {
    try { if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, ''); } catch (e) {}
    return Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
  }
  window.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || e.defaultPrevented || (f.getAttribute('method') || '').toLowerCase() !== 'post' || f.hasAttribute('data-kein-einmal')) return;
    var b = e.submitter || null;
    if ((b && b.getAttribute('formtarget') === '_blank') || f.getAttribute('target') === '_blank') return;
    var i = f.querySelector('input[name="_einmal"]');
    if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = '_einmal'; f.appendChild(i); }
    if (!i.value) i.value = einmalId();
    if (b && !b.disabled && !b.dataset.busyOn) {
      b.dataset.einmalAus = '1';
      setTimeout(function () { b.disabled = true; }, 0);   // erst nach dem Absenden (sonst fehlt name/value des Knopfs)
    }
    setTimeout(function () { if (b && b.dataset.einmalAus) { b.disabled = false; delete b.dataset.einmalAus; } i.value = ''; }, 10000);   // z. B. Download ohne Seitenwechsel
  }, false);
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) return;   // Zurück-Navigation: neue Kennungen, Knöpfe frei
    Array.prototype.forEach.call(document.querySelectorAll('input[name="_einmal"]'), function (i) { i.value = ''; });
    Array.prototype.forEach.call(document.querySelectorAll('[data-einmal-aus]'), function (b) { b.disabled = false; delete b.dataset.einmalAus; });
  });
  function einmalHinweis() {
    if (!/[?&]einmal=doppelt(&|$)/.test(location.search)) return;
    var d = document.createElement('div');
    d.setAttribute('role', 'status');
    d.textContent = 'Schon abgeschickt – wurde nur einmal gebucht.';
    d.style.cssText = 'position:fixed;left:50%;top:14px;transform:translateX(-50%);z-index:9999;padding:10px 16px;border-radius:10px;background:var(--panel,#fff);color:var(--text,#10210f);border:1px solid var(--line,#ccc);box-shadow:0 4px 18px rgba(0,0,0,.18);font-size:14px;max-width:calc(100% - 32px)';
    document.body.appendChild(d);
    setTimeout(function () { d.remove(); }, 5000);
    try { var u = new URL(location.href); u.searchParams.delete('einmal'); history.replaceState(null, '', u.toString()); } catch (e) {}
  }

  // ---------------------------------------------------------------- Start + spätere Inhalte
  function start() {
    einmalHinweis();
    // Auswahl mit Suche überall (Portale und Sub-Apps laden sie sonst nicht): einmal nachladen
    if (!window.bxRsComboInit && !document.querySelector('script[src*="bx-rscombo.js"]')) { var sc = document.createElement('script'); sc.src = '/assets/bx-rscombo.js'; document.head.appendChild(sc); }
    // Rückfrage bei mehrdeutigen Gewichten „1.250“ (Fable-4 T1.3): Felder mit data-gewicht.
    if (!window.bxGewichtInit && !document.querySelector('script[src*="bx-gewicht.js"]')) { var sg = document.createElement('script'); sg.src = '/assets/bx-gewicht.js'; document.head.appendChild(sg); }
    // Pflichtfelder (Sofortfix 13.11.2026): data-pflicht – Sternchen, Prüfung vor dem Absenden, Fehler am Feld (bx-pflicht.js).
    if (!window.bxPflichtInit && document.querySelector('[data-pflicht],.bx-pflicht-fehlt') && !document.querySelector('script[src*="bx-pflicht.js"]')) { var sp = document.createElement('script'); sp.src = '/assets/bx-pflicht.js'; document.head.appendChild(sp); }
    // Datei-Knopf im bulkify-Stil + Karten je Zeile am Handy (Nachbesserung Fable-5, Teil 4): bx-datei.js.
    if (!window.bxDateiInit && document.querySelector('input[type=file],table.bx-karten-handy') && !document.querySelector('script[src*="bx-datei.js"]')) { var sd = document.createElement('script'); sd.src = '/assets/bx-datei.js'; document.head.appendChild(sd); }
    alle(document);
    reiterAnker();
    if (window.MutationObserver) new MutationObserver(function (ms) {
      ms.forEach(function (m) { Array.prototype.forEach.call(m.addedNodes, function (n) { if (n.nodeType !== 1) return; if (n.matches && n.matches('dialog.bx-dialog')) aufwerten(n); else if (n.querySelector) alle(n); }); });
    }).observe(document.body, { childList: true, subtree: true });
    // Auch Pop-ups, die per showModal() geöffnet werden, bevor sie aufgewertet sind
    var orig = HTMLDialogElement.prototype.showModal;
    HTMLDialogElement.prototype.showModal = function () { if (this.classList.contains('bx-dialog')) { aufwerten(this); this.__dirty = false; } return orig.apply(this, arguments); };
  }
  if (document.readyState !== 'loading') start(); else document.addEventListener('DOMContentLoaded', start);
})();
