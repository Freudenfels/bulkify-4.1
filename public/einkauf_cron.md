# public/einkauf_cron.php – Automatische Bestell-Erinnerungen (B4)

Token-gesicherter Cron-Endpunkt (kein Login), analog `public/crm/mail_cron.php`.

**Aufruf (Server-Cron, z. B. 1×/Tag):** `<host>/einkauf_cron.php?token=<einkauf_cron_token>`
Den Token zeigt die „Bestellt"-Liste (`?p=einkauf`) im Admin-Aufklappkasten; er wird beim ersten `einkauf_cron_token()` in `app_meta` angelegt. Optional `&tage=N` (Überfälligkeits-Schwelle, Default 3).

Ruft `einkauf_erinnerungen_cron()` (`core/schema.php`) auf: erinnert alle **überfälligen, unbestätigten Portal-Bestellungen** (`bestellung_ueberfaellig()`), je Bestellung höchstens **1×/24 h** (`bestellung.erinnert_am`). Antwort JSON `{ok, gesendet, geprueft}`. Mailversand über Dashboard-SMTP (`mail_lieferant_erinnerung()`), nicht `mail()`.

**Seit 11.11.2026 (Block D)** prüft derselbe Cron zusätzlich bald ablaufende Preise (Rohstoff, Zukauf, Fremdfertigung; `pg_bald_pruefen`, einmal am Tag) und kennzeichnet Auto-EKs aus abgelaufenen Preisen (`pg_ek_abgelaufen_pruefen`). Antwort zusätzlich `preise_bald` und `ek_abgelaufen`.

**13.11.2026 (Paket Teil 4):** Gemeinsamer täglicher Einstieg. Zusätzlich `etikett_erinnerungen_cron()` (Etikett fehlt → Kunde erinnern, max. 1 Mail je Kunde und Tag) und `cron_lauf_merken()` (app_meta `cron_letzter_lauf`). Die Antwort enthält jetzt auch `etikett_erinnerung_kunden`, `etikett_erinnerung_auftraege` und `etikett_fehler`.
