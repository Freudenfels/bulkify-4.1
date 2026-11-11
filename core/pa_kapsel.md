# core/pa_kapsel.php – Kapselzahl aus dem Gewicht, Gegenprobe

Seit 11.11.2026, Teil 9 von `plan-produktion-gefuehrt.md` (Handbuch 4.8).

- **`pa_kapselprobe($uc, $gramm, 10)`**: Stichprobe wiegen. Mehrere Proben ergeben das **Ø-Gewicht je Kapsel** (`pa_unter_charge.kapsel_mg`). Der Hinweis fordert mindestens 3 Proben.
- **`pa_sackgewicht($uc, $brutto, $tara)`**: Sack wiegen. Die Tara kommt je Sacktyp aus `lg_tara` (Inventur-Gebindetypen). Kapseln = (Sack − Tara) ÷ Ø-Gewicht. `kapseln_bulk` ist die Summe aller Säcke und wird bei jeder neuen Probe neu gerechnet.
- **`pa_gegenprobe($uc)`** läuft beim Schließen der Unter-Charge (Chargenwechsel oder Abschluss).
  - Gerechnet wird: Gläser × Kapseln je Glas gegen die Kapselzahl aus dem Gewicht.
  - Abweichung über der Toleranz (`app_meta kapsel_toleranz_prozent`, Standard 2 %): Für den Mitarbeiter ist das **nur ein Hinweis**. Dazu kommen eine **Aufgabe ans Büro** und ein Eintrag im Chargenprotokoll. Kein Stopp.
