# core/etikett_erinnerung.php – Etikett fehlt: tägliche Erinnerung an den Kunden (Paket 13.11.2026, Teil 4)

- **Erste Bitte:** steht in der Mail nach der PIB-Freigabe (`pib_mail_kunde`) und zählt als erste Erinnerung (`etikett_erinnerung_vermerken`).
- **Täglich:** `etikett_erinnerungen_cron()` (aufgerufen von `public/einkauf_cron.php`) schickt je Kunde höchstens **eine** Mail am Tag, mit allen Aufträgen ohne Etikett und je einem Upload-Link ins Portal. DE/EN nach dem Land des Kunden (`pib_kunde_sprache`). Absender, Kunden-Filter und Testumleitung wie bei allen Kundenmails (`mail_senden`).
- **Wer bekommt keine:**
  - Stornierte, versendete oder fertige Aufträge.
  - Dienstleistungen.
  - Aufträge, die kein Etikett brauchen oder schon ein Design haben (`etikett_vorhanden`).
  - Altaufträge ohne erste Mail (`auftrag.pib_mail_am` leer).
  - Pausierte Aufträge.
- **Auftragsseite** (Pop-up Etikett): „Etikett-Erinnerung: 3 × gesendet, zuletzt 15.11.“ und der Knopf „Erinnerung pausieren / wieder aktivieren“ (`etikett_erinnerung_pause`). Spalten `auftrag.etikett_erinnert_anzahl`, `etikett_erinnert_am`, `etikett_erinnerung_pause`.
- **Merker letzter Lauf:** `cron_lauf_merken()` schreibt `app_meta cron_letzter_lauf` (UTC) und eine Kurzinfo. `cron_lauf_hinweis()` zeigt sie auf „Bestellt“ (Admin) und in den Einstellungen; ist der Lauf nie oder vor mehr als 36 h gewesen, ist der Hinweis rot.
