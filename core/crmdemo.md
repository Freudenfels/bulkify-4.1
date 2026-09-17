# core/crmdemo.php – CRM-Demo (Lieferanten-Beta)

Eigenständiges, **isoliertes** Mini-System zum Vorführen: CRM + KI-Produktentwickler + Angebote +
Rechnungen + Produktion + Finanzen. Greift AUSSCHLIESSLICH auf eigene Tabellen `crmdemo_*` zu
(kein Mix mit echten bulkify-Daten). Deutsch/中文 umschaltbar. Vollständig löschbar.

- `crmdemo_schema()` – legt die crmdemo_*-Tabellen an (idempotent, 1× je Request).
- `cd_lang()` / `cd_t($key)` – Sprache (de/zh, in crmdemo_meta) + Übersetzungen.
- `cd_head()/cd_shell_start()/cd_shell_ende()` – eigenes Layout (nutzt app.css, eigenes Menü + Sprachumschalter).
- `cd_meta_get/set`, `cd_nummer($prefix)` – Meta + Nummernkreise (AN-/RE-) auf crmdemo_meta.
- `crmdemo_kennzahlen()` – Dashboard-Zahlen.
- `crmdemo_seed()` – Beispieldaten (nur wenn leer). `crmdemo_reset()` – Daten leeren. `crmdemo_loeschen()` – ALLE crmdemo_-Tabellen droppen.

Einstieg: versteckter Reiter „CRM-Demo" in den Einstellungen (`?p=einstellungen&tab=crmdemo`) → Button „CRM-Demo öffnen" → `?p=crmdemo`.
Route/Rechte: `crmdemo` ist nicht im Rollen-Map → nur Admin. KI nur auf beta aktiv (sonst wird die Idee ohne Konzept gespeichert).
