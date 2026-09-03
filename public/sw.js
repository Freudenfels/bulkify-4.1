// bulkify - Service Worker.
//
// Zweck: Damit sich das Dashboard auf dem Handy installieren laesst (Android verlangt dafuer einen
// Service Worker) und damit es ohne Netz nicht mit einer Fehlerseite dasteht.
//
// Wichtig: Zwischengespeichert werden nur Icons und die Offline-Seite. CSS kommt immer frisch
// vom Server (das Aussehen aendert sich staendig), und Seiten mit Daten
// - Kunden, Preise, Auftraege - landen NIE im Cache. Sonst haette jeder, der das Geraet in die Hand
// bekommt, Zugriff darauf, auch ohne Anmeldung.
const VERSION = 'bx-2026-09-03b';
const STATIC  = ['/assets/app-icon-192.png', '/assets/offline.html'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(STATIC)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  // Alte Ausgaben wegwerfen, damit nach einem Deploy nichts Altes haengen bleibt.
  e.waitUntil(
    caches.keys()
      .then((k) => Promise.all(k.filter((x) => x !== VERSION).map((x) => caches.delete(x))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;                       // nichts abfangen, was etwas veraendert
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Das Aussehen aendert sich staendig - CSS deshalb IMMER frisch holen. Sonst sieht man nach
  // einem Deploy die alte Oberflaeche und haelt sie fuer kaputt.
  if (url.pathname.endsWith('.css')) {
    e.respondWith(fetch(req).catch(() => caches.match(req)));
    return;
  }

  if (url.pathname.startsWith('/assets/')) {
    // Icons und Schriften: sofort aus dem Cache, im Hintergrund erneuern.
    e.respondWith(
      caches.match(req).then((treffer) => {
        const netz = fetch(req).then((r) => {
          if (r && r.ok) { const kopie = r.clone(); caches.open(VERSION).then((c) => c.put(req, kopie)); }
          return r;
        }).catch(() => treffer);
        return treffer || netz;
      })
    );
    return;
  }

  // Alles andere: immer frisch aus dem Netz. Ohne Netz eine ehrliche Hinweisseite.
  e.respondWith(fetch(req).catch(() => caches.match('/assets/offline.html')));
});
