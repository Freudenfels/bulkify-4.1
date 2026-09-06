// bulkify CRM - Service Worker. Nur damit sich die Seite als App installieren laesst und ohne Netz
// nicht mit einer Fehlermeldung dasteht. Zwischengespeichert werden NUR Icons - Seiten mit Kunden-
// und Preisdaten landen nie im Cache.
const VERSION = 'crm-2026-09-05';
const STATIC  = ['/crm/assets/app-icon-192.png'];

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
  if (url.pathname.startsWith('/crm/assets/') && !url.pathname.endsWith('.css')) {
    e.respondWith(caches.match(req).then((t) => t || fetch(req)));
  }
});
