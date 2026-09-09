# mehr.php – der Rest

Route `?p=mehr`. Alles, was nicht in die untere Leiste passt:

- **Termine** und **Kunden** – die beiden Seiten ohne eigenen Menüpunkt.
- **Adresse des Dashboards** – damit jede Zeile der Liste dorthin verlinkt. Steht in `crm_meta`, nicht im Dashboard.
- **Website-Eingang** – zeigt die Endpunkt-URL (`<host>/crm/lead_intake.php`) und den **Token** für das bulkify.pro-Formular, plus „Neuen Token erzeugen" (`tun=intake_token_neu` → schreibt `crm_meta.lead_intake_token` neu). Details: `public/crm/lead_intake.md`.
- **Dunkler Modus** – gemerkt im Browser (`localStorage`), nicht in der Datenbank.
- **Aufs Handy legen** – kurze Anleitung.
- **Abmelden**.
