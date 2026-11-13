# pa_produziert.php – „produziert“ eines Produktionsauftrags (13.11.2026)

Eine Regel für das ganze System (Nachbesserung Fable-5, Teil 2.2): **produziert = Fertigware des PA in Stück.**

- Gezählt werden Lager-Chargen mit `pa_id` des Auftrags.
- **Nicht** gezählt: Mengen in kg, g, mg, t, l, ml (z. B. Restmischung oder Bulk-Reste aus der Werk-App).
- Je Chargennummer zählt nur die **erste** Charge des Auftrags. Teilmengen-Kopien (Zuweisung an einen Auftrag, Übergabe ins Fremdlager)
  tragen dieselbe Nummer, während die Quelle ihre Menge behält – sie würden sonst doppelt zählen.

Funktionen:
- `pa_produziert_sql($pa)` – Unterabfrage für SQL-Listen (`$pa` = Spalte wie `pa.id` oder `?`).
- `pa_produziert($pa_id)` – Zahl für einen Auftrag.

Benutzt von `produktion_gebucht()` (Dashboard), `erp_produktion_gebucht()` (Produktions-App), `core/pa_ablauf.php`
(Noch offen, voll produziert, Teilmenge, Abschluss), Werk-App-Liste, KI-Meldung und Datenprüfung.
Ohne Abhängigkeiten, darf von Sub-Apps geladen werden.
