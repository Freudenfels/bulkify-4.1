// bulkify - Service Worker.
//
// Zweck: Damit sich das Dashboard auf dem Handy installieren laesst (Android verlangt dafuer einen
// Service Worker) und damit es ohne Netz nicht mit einer Fehlerseite dasteht.
//
// Wichtig: Es werden NUR Dateien aus /assets/ zwischengespeichert (CSS, Icons). Seiten mit Daten
// - Kunden, Preise, Auftraege - landen NIE im Cache. Sonst haette jeder, der das Geraet in die Hand
// bekommt, Zugriff darauf, auch ohne Anmeldung.
const VERSION = 'bx-2026-09-03';
const STATIC  = ['/assets/app.css', '/assets/app-icon-192.png', '/assets/offline.html'];

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

  if (url.pathname.startsWith('/assets/')) {
    // CSS und Bilder: sofort aus dem Cache, im Hintergrund erneuern.
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
