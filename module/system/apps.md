# module/system/apps.php

Seite „Apps“ (`?p=apps`, Menüpunkt „Apps“; Nico 13.11.2026): alle bulkify-Programme an einer Stelle.

- Je App eine Karte: Name, wofür, Adresse, Knopf „Öffnen“, QR-Code (`qr_svg()`, zum Scannen mit Tablet/Handy) und eine kurze Anleitung „Als App installieren“ (Android/Chrome, iPhone/Safari, Rechner/Chrome-Edge).
- Apps: Dashboard (mit echtem Knopf „Auf diesem Gerät installieren“, sobald der Browser es anbietet), Werk-App (Produktion am Tablet), Lager-App, Produktion (Team), CRM, Buchhaltung. Installierbar mit eigenem Web-Manifest: Dashboard, Werk-App, Lager-App, CRM; Produktion (Team) und Buchhaltung als Verknüpfung.
- Sichtbar je Rolle wie die Unterseiten im Menü: Werk-App/Produktion production, Lager-App production/fulfillment/einkauf/labor, CRM nur Admin, Buchhaltung finance; Admin sieht alles.
- Nur Admin: Download der Lager-Brücke (Windows-Programm für Etikettendruck und Regal-Leuchten) mit Autostart oder als Testversion mit Fenster.
- Route für alle angemeldeten Team-Nutzer (`'*'` in `route_rollen_map()`).

## Nachbesserung Fable-5 (13.11.2026)
CRM-Kachel für jede Team-Rolle, die das CRM öffnen darf (wie `crm/core/auth.php`, nicht Lieferant/Kunde). Adressen und QR-Codes immer `https://` (nur der lokale Testrechner localhost/127.0.0.1 bleibt http).

Karten gleich hoch (Raster), QR-Code mit festem Platz 120 px (am Handy 96 px), nie über den Kartenrand (Fable-5 T4.11).
