# produktion/core/layout.php
Seitenrahmen `kopf()/fuss()` + `pr_nav()` – Aussehen des Dashboards (`/assets/app.css`, `bx-*`-Klassen), Dunkelmodus + Mobil-Menü wie Lager. Menü erweitert der Produktions-Chat in `pr_nav()`.

Unter der Gruppe „Dashboard" steht „Zum Dashboard" (`erp_dashboard_url()` = `/`) und – **nur für Admins** (`pr_ist_admin()`) – „Testdaten (Durchspiel)", ein externer Link auf `/?p=testdaten` (die Testdaten-Seite liegt im Dashboard; die Sub-App darf das Dashboard-Schema nicht einbinden). Der Link führt also ins Dashboard, dort greift dessen eigener Login.
