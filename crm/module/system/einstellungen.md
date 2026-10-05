# einstellungen.php – Einstellungen (nach Reitern)

Route `?p=einstellungen&tab=…`, Menüpunkt „Einstellungen". Löst das alte „Mehr" ab; die Route `mehr`
zeigt weiterhin hierher (Alt-Links). Aufbau wie die Dashboard-Einstellungen: oben eine Reiterleiste
(`.crm-reiter`), darunter der gewählte Reiter.

Reiter:
- **Allgemein** – Adresse des Dashboards (`crm_meta.dashboard_url`) + Schnelllinks zu Termine/Kunden.
- **Website-Eingang** – Endpunkt-URL + Token für das bulkify.pro-Formular, „Neuen Token erzeugen". Siehe `public/crm/lead_intake.md`.
- **E-Mail-Eingang** – IMAP-Zugang (`imap_*` in `crm_meta`, Passwort nur bei Eingabe überschrieben) + Cron-URL. Siehe `crm/core/mail_abruf.md`.
- **Darstellung** – dunkler Modus (im Browser gemerkt), „Aufs Handy legen".
- **Info** – Datenbank, Zugangsdatei, KI-Status, aktiver E-Mail-Abrufweg.

Weitere Reiter kommen dazu (z. B. „Mein Mailkonto" für den eigenen SMTP-Versand je Mitarbeiter).
Alle Speichern-Aktionen leiten per Redirect auf den passenden Reiter zurück (`&ok=1`).
