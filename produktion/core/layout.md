# produktion/core/layout.php
Seitenrahmen `kopf()/fuss()` + `pr_nav()` – Aussehen des Dashboards (`/assets/app.css`, `bx-*`-Klassen), Dunkelmodus + Mobil-Menü wie Lager. Menü erweitert der Produktions-Chat in `pr_nav()`.

**Menü (08.11.2026, wie Lager-App):** `pr_nav()` = flache Liste Übersicht (dash) · Produzieren (modus, auch run) · Aufträge (liste, auch pa/qs) · Kalender · Reinigung · Anleitungen, je mit Inline-SVG aus `pr_nav_icon()`; `pr_nav_klein()` = Archiv · Einstellungen · Werk-App, dazu Zum Dashboard und Testdaten (Admin). CSS steht als `<style>` im Kopf (keine eigene CSS-Datei, also kein Browser-Cache-Problem); `.pr-prod/.pr-sub` für die Produkt-Zelle, `.pr-wann/.pr-nur-handy` blendet „Wann dran“ auf dem Handy unter den Stand.

Unter der Gruppe „Dashboard" steht „Zum Dashboard" (`erp_dashboard_url()` = `/`) und – **nur für Admins** (`pr_ist_admin()`) – „Testdaten (Durchspiel)", ein externer Link auf `/?p=testdaten` (die Testdaten-Seite liegt im Dashboard; die Sub-App darf das Dashboard-Schema nicht einbinden). Der Link führt also ins Dashboard, dort greift dessen eigener Login.
