# Reiter „Plan“ (`module/produktion/_plan.php`)

Seit 11.11.2026. Wird von `liste.php` eingebunden.

Die Tabelle lässt sich am Griff ziehen. Je Zeile:
- Position
- Produkt (PA, Kunde, Eigen/Fremd)
- Menge (produziert)
- Machbar mit Grund; nicht machbare Zeilen stehen grau
- Datum, Linie, Mitarbeiter

Gespeichert wird sofort per AJAX (`aktion=plan_speichern`). Die Werk-App zeigt genau diese Reihenfolge.

**Noch offen (13.11.2026, Werk-App Teil 2):** Unter dem Status steht „Noch offen: Schritte · Menge“ (`pa_noch_offen_text()`).
