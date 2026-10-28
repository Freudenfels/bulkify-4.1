# public/einkauf_cron.php – Automatische Bestell-Erinnerungen (B4)

Token-gesicherter Cron-Endpunkt (kein Login), analog `public/crm/mail_cron.php`.

**Aufruf (Server-Cron, z. B. 1×/Tag):** `<host>/einkauf_cron.php?token=<einkauf_cron_token>`
Den Token zeigt die „Bestellt"-Liste (`?p=einkauf`) im Admin-Aufklappkasten; er wird beim ersten `einkauf_cron_token()` in `app_meta` angelegt. Optional `&tage=N` (Überfälligkeits-Schwelle, Default 3).

Ruft `einkauf_erinnerungen_cron()` (`core/schema.php`) auf: erinnert alle **überfälligen, unbestätigten Portal-Bestellungen** (`bestellung_ueberfaellig()`), je Bestellung höchstens **1×/24 h** (`bestellung.erinnert_am`). Antwort JSON `{ok, gesendet, geprueft}`. Mailversand über Dashboard-SMTP (`mail_lieferant_erinnerung()`), nicht `mail()`.
