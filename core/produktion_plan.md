# core/produktion_plan.php – Produktionsplan

Seit 11.11.2026, Teil 2 von `plan-produktion-gefuehrt.md`.

- **Plan:** Reihenfolge per Ziehen (`produktionsauftrag.plan_sort`). Je Zeile Datum (`geplant_am`), Linie (`linie`, Liste in `app_meta produktion_linien`, Standard „Linie 1,Linie 2“) und Mitarbeiter (`mitarbeiter_id`).
  - `produktion_plan_speichern()` schreibt das, Änderungen gehen ins Änderungsprotokoll.
  - `produktion_plan_sortieren()` sortiert: zuerst gesetzte Reihenfolge, dann Datum, Priorität, älteste.
- **Machbar je Schritt:** `produktion_machbar_schritte($pa)` ist **eine** Rechnung (auf Basis von `produktion_bereitschaft` + `produktion_etikett_sperre`). Ergebnis:
  - `startklar`
  - `bis_etikett`: Fehlt nur das Etikett bzw. ist es nicht freigegeben, geht das Abfüllen, nur das Etikettieren ist gesperrt.
  - `gesperrt`: mit Grund, z. B. „unterwegs bis 14.11.: Leerkapsel … (fehlt 36.000)“.
  - `laeuft` / `fertig`
- **Werk-App:** Das Ergebnis steht zusätzlich in `pa_machbar`. So liest die Werk-App (Sub-App ohne `core/schema.php`) dieselbe Antwort.
- **Schema:** `produktion_plan_schema()` arbeitet best-effort, Version in `app_meta produktion_plan_schema`.
