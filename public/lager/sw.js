// bulkify Lager - Service Worker. Nur damit sich das Lager als App installieren laesst (Handscanner).
// Zwischengespeichert werden NUR Icons - Seiten mit Bestands-/Chargendaten landen NIE im Cache,
// damit nach dem Auto-Deploy nie eine veraltete Seite ausgeliefert wird.
const VERSION = 'lager-2026-10-03';
const STATIC  = ['/assets/icons/favicon-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(STATIC)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys()
    .then((k) => Promise.all(k.filter((x) => x !== VERSION).map((x) => caches.delete(x))))
    .then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  // Nur Icons aus dem Cache bedienen; alles andere immer frisch aus dem Netz.
  if (url.pathname.startsWith('/assets/icons/')) {
    e.respondWith(caches.match(req).then((t) => t || fetch(req)));
  }
});
