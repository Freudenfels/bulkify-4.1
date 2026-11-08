# lager/core/layout.php – Seitenrahmen

Übernimmt das Aussehen des Dashboards: `/assets/app.css` plus `assets/lager.css`, dazu dasselbe Menü, den Burger fürs Tablet und den dunklen Modus. Im Menü stehen Lagerplätze, Blinker zuordnen und (nur für Admins) Sender und Brücke.

`leucht_knopf()` erzeugt einen Knopf „Leuchten“ für einen Platz. Er arbeitet über `assets/lager.js` per fetch, mit Spinner, ohne die Seite neu zu laden.

Navigation Lager 1: „Versandbereit“ vor „Warenausgang“ (Soll-Kette 7, 04.11.2026).

Menüpunkt „Nach Lager 2“ (`umlagern`) in der Gruppe Lager 1 (04.11.2026).

- 08.11.2026: Menüpunkt **„Lieferschein erstellen“** (`lieferschein_neu`) in der Gruppe Lager 1 nach „Warenausgang“.

## Menü neu (08.11.2026)
**Flach, ohne Gruppenüberschriften, größer, mit Icons** (wie das Dashboard-Menü „Einfach“):
Übersicht · Suchen (`finden`) · Einbuchen (`we`) · Umbuchen (`umbuchen`) · Versand (`versand_start`) · Lager 1 (`lager1`, Zähler = Chargen in Quarantäne) · Lager 2 (`lager2`).
Darunter klein und abgesetzt (`lg_nav_klein`): Bewegungen · Mülleimer · Einstellungen (nur Admin) · Zum Dashboard; dann Nutzer/Abmelden/Modus wie bisher.
- `lg_nav()` = Hauptpunkte, `lg_nav_icon()` = Linien-Icons (Inline-SVG, currentColor; truck/warehouse wie im Dashboard), `lg_nav_aktiv()` ordnet Unterseiten ihrem Menüpunkt zu (z. B. `kisten` → Umbuchen, `quarantaene` → Lager 1, `erwartet`/`l2_eingang` → Einbuchen, `l2_finden`/`leisten` → Suchen).
- Nicht mehr im Menü, aber erreichbar: Blinker (`leisten`), Erwartete Lieferungen (`erwartet`), Kundenware einbuchen (`l2_eingang`), Lager-2-Suche (`l2_finden`).
- CSS in `public/lager/assets/lager.css` (`.lg-navflat`, `.lg-navklein`): 16 px am PC, am Handy 18 px für die Hauptpunkte, kleine Punkte 13–14 px.

