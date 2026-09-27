# lager/core/layout.php – Seitenrahmen

Übernimmt das Aussehen des Dashboards: `/assets/app.css` plus `assets/lager.css`, dazu dasselbe Menü, den Burger fürs Tablet und den dunklen Modus. Im Menü stehen Lagerplätze, Leisten zuordnen und (nur für Admins) Sender und Brücke.

`leucht_knopf()` erzeugt einen Knopf „Leuchten“ für einen Platz. Er arbeitet über `assets/lager.js` per fetch, mit Spinner, ohne die Seite neu zu laden.
