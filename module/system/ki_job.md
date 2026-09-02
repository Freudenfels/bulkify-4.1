# ki_job.php (Modul) – die Adresse fuer die Hintergrundarbeit

## Wozu
Nimmt den Anstoss aus `core/ki_job.php` entgegen: Schluessel pruefen, sofort "ok" antworten, Verbindung schliessen, danach in Ruhe rechnen.

## Warum ohne Login
Der Aufruf kommt vom Server selbst, nicht von einem Menschen. Statt einer Anmeldung schuetzt ihn der Schluessel `s` (HMAC aus Art + ID + API-Schluessel). Passt er nicht, kommt 403 und sonst nichts.

## Parameter
- `art` – was zu tun ist (heute nur `rezeptur`)
- `id` – welcher Vorgang
- `s` – der Schluessel dazu

Details und Ablauf stehen in `core/ki_job.md`.
