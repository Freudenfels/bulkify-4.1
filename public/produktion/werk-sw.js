// bulkify Produktion – Service Worker der Mitarbeiter-App (?p=werk).
// Zweck: macht die App am Android-Tablet installierbar (Vollbild, ohne Browser-Leiste). Scope /produktion/.
// Daten-/App-Seiten kommen IMMER frisch aus dem Netz (nie offline gecacht – kein Login-Umgehen);
// nur die App-Icons werden zwischengespeichert.
const VERSION = 'bx-werk-2026-10-10';
const STATIC  = ['/assets/app-icon-192.png', '/assets/app-icon-512.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(STATIC)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((k) => Promise.all(k.filter((x) => x !== VERSION).map((x) => caches.delete(x))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Icons/Assets: aus dem Cache (im Hintergrund erneuern).
  if (url.pathname.startsWith('/assets/')) {
    e.respondWith(
      caches.match(req).then((hit) => {
        const netz = fetch(req).then((r) => {
          if (r && r.ok) { const kopie = r.clone(); caches.open(VERSION).then((c) => c.put(req, kopie)); }
          return r;
        }).catch(() => hit);
        return hit || netz;
      })
    );
    return;
  }
  // Alles andere (die App selbst): immer frisch aus dem Netz.
  e.respondWith(fetch(req).catch(() => caches.match(req)));
});
