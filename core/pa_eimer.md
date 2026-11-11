# core/pa_eimer.php – Eimerplanung beim Mischen

Seit 11.11.2026, Teil 8 von `plan-produktion-gefuehrt.md` (Handbuch 4.8).

Das **System gibt die Eimer vor**, Größe und Reihenfolge. Der Mitarbeiter tippt keine Eimergröße mehr ein.

- **Regel:**
  - Je Rohstoff wird zuerst die ältere Charge verbraucht.
  - Geht bei irgendeinem Rohstoff eine Charge zu Ende, endet der Eimer, und der nächste beginnt mit neuem Buchstaben. Es gibt dann eine kleine Restmenge.
  - Dazwischen werden normale Eimer bis zur Standardgröße gebildet (`app_meta eimer_kg_standard`, Standard 25 kg).
- **Grundlage:** die für den PA bereitgestellten Rohstoff-Chargen (`produktion_verbrauch`), sonst der freie Bestand (FEFO).
- **`pa_eimerplan_berechnen()`** liefert je Eimer:
  - Buchstabe und kg
  - Grund (z. B. „endet, weil Charge X von Y dann aufgebraucht ist“)
  - je Rohstoff kg und Charge
- **`pa_eimerplan_festschreiben()`:** Mit dem ersten fertigen Eimer wird der Plan in `pa_eimerplan` festgeschrieben, damit sich die Eimer nicht mehr verschieben.
- **Werk-App:** `erp_mischer_plan($pa, 0)` nutzt diesen Plan. Eine früher eingegebene Behältergröße gilt nur für schon laufende PAs.
- **Abfüllen:** Eimer müssen der Reihe nach kommen. Ein anderer Eimer als der nächste noch nicht abgefüllte ergibt **STOPP** (`werk_ablauf_post.php`).
