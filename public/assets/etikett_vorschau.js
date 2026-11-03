// Etikett-Design als Vorschau zeigen – im Dashboard-Wareneingang und in der Lager-App (Etiketten einbuchen).
// PDF: Seite 1 wird mit pdf.js als Bild gezeichnet (Handy/PWA zeigt eingebettete PDFs sonst nicht an).
// Bild: direkt. Lädt pdf.js nicht oder ist die Datei kaputt: Hinweis + Link zum Öffnen.
// Aufruf: bxEtikettVorschau(element, url[, istPdf]).
(function(){
  var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  var laden = null;
  function pdfjs(){
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (laden) return laden;
    laden = new Promise(function(ok, fehler){
      var s = document.createElement('script');
      s.src = PDFJS + 'pdf.min.js';
      s.onload = function(){
        if (!window.pdfjsLib) { fehler(new Error('pdf.js fehlt')); return; }
        window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js';
        ok(window.pdfjsLib);
      };
      s.onerror = function(){ laden = null; fehler(new Error('pdf.js nicht geladen')); };
      document.head.appendChild(s);
    });
    return laden;
  }
  function link(url){
    return '<a class="btn btn-ghost btn-sm" href="' + url + '" target="_blank" rel="noopener">Design öffnen</a>';
  }
  window.bxEtikettVorschau = function(el, url, istPdf){
    if (!el) return;
    if (istPdf === undefined) istPdf = true;
    el.innerHTML = '<div class="muted" style="font-size:13px">Design wird geladen …</div>';
    if (!istPdf) {
      el.innerHTML = '<img src="' + url + '" alt="Etikett-Design" style="max-width:100%;max-height:420px;border:1px solid var(--line);border-radius:8px;background:#fff">'
        + '<div style="margin-top:6px">' + link(url) + '</div>';
      return;
    }
    pdfjs().then(function(lib){
      return lib.getDocument({url: url, withCredentials: true}).promise;
    }).then(function(doc){
      return doc.getPage(1).then(function(seite){
        var breite = Math.max(240, Math.min(el.clientWidth || 440, 560));
        var v1 = seite.getViewport({scale: 1});
        var dpr = window.devicePixelRatio || 1;
        var vp = seite.getViewport({scale: (breite / v1.width) * dpr});
        var c = document.createElement('canvas');
        c.width = Math.floor(vp.width); c.height = Math.floor(vp.height);
        c.style.cssText = 'width:' + Math.floor(vp.width / dpr) + 'px;max-width:100%;height:auto;border:1px solid var(--line);border-radius:8px;background:#fff;display:block';
        return seite.render({canvasContext: c.getContext('2d'), viewport: vp}).promise.then(function(){
          el.innerHTML = '';
          el.appendChild(c);
          var u = document.createElement('div'); u.style.marginTop = '6px';
          u.innerHTML = (doc.numPages > 1 ? '<span class="muted" style="font-size:12px;margin-right:8px">Seite 1 von ' + doc.numPages + '</span>' : '') + link(url);
          el.appendChild(u);
        });
      });
    }).catch(function(){
      el.innerHTML = '<div class="muted" style="font-size:13px;margin-bottom:6px">Vorschau konnte nicht geladen werden.</div>' + link(url);
    });
  };
})();
