# mehr.php – der Rest

Route `?p=mehr`. Alles, was nicht in die untere Leiste passt:

- **Termine** und **Kunden** – die beiden Seiten ohne eigenen Menüpunkt.
- **Adresse des Dashboards** – damit jede Zeile der Liste dorthin verlinkt. Steht in `crm_meta`, nicht im Dashboard.
- **Website-Eingang** – zeigt die Endpunkt-URL (`<host>/crm/lead_intake.php`) und den **Token** für das bulkify.pro-Formular, plus „Neuen Token erzeugen" (`tun=intake_token_neu` → schreibt `crm_meta.lead_intake_token` neu). Details: `public/crm/lead_intake.md`.
- **E-Mail-Eingang (IMAP)** – Zugang zum Postfach (`imap_host/port/user/pass/ssl/ordner` in `crm_meta`, `tun=imap`). Das Passwort wird nur überschrieben, wenn eins eingegeben wird. Dazu die **Cron-URL** (`mail_cron.php?token=…`) mit „Neuen Cron-Token erzeugen". Zeigt einen Hinweis, falls die PHP-IMAP-Erweiterung auf dem Server fehlt. Details: `crm/core/mail_abruf.md`.
- **Dunkler Modus** – gemerkt im Browser (`localStorage`), nicht in der Datenbank.
- **Aufs Handy legen** – kurze Anleitung.
- **Abmelden**.
