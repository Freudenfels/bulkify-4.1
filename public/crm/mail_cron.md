# public/crm/mail_cron.php – Hintergrund-Abruf (Cron)

Ruft `mail_abholen()` auf, damit sich der E-Mail-Eingang automatisch füllt. **Kein Login,
token-gesichert** (`mail_cron_token()` aus `crm_meta`; Feld `token` oder Header `X-Cron-Token`,
falscher/fehlender Token → 403).

Aufruf per Cronjob, z. B. alle 5 Minuten:
`<host>/crm/mail_cron.php?token=<mail_cron_token>` (optional `&max=30`).

Holt **nur ab** – angelegt wird nichts automatisch; das macht ein Mensch mit einem Klick auf der
Eingang-Seite. Antwort: JSON `{ok, abgeholt, fehler}`. URL + Token stehen im CRM unter „Mehr".
