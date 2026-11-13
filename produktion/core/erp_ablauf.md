# produktion/core/erp_ablauf.php – Werk-App: geführter Ablauf (Naht)

Seit 11.11.2026, Teil 4 von `plan-produktion-gefuehrt.md`.

- `erp_wa_stationen()` / `erp_wa_aktiv($pa)`: Der geführte Ablauf gilt, sobald der nächste offene Schritt Abfüllen, Verpacken oder Etikettieren ist. Mischen und Verkapseln bleiben eigene Schritte.
- `erp_wa_rollen($pa)`: Material-Rollen des PA (Bulk, Glas/Dose, Deckel, Etikett, Leerkapsel, Karton, Beipack). Was nicht dazugehört, wird beim Scan gesperrt.
- `erp_wa_scan($code)`: löst einen Code auf.
  - Gebinde-QR, Charge-QR, Inventaraufkleber, Chargennummer
  - Eimer/Sack (`prod_charge`, QR = Nummer der Unter-Charge)
- `erp_wa_aeltere_bulk()`: Gibt es eine ältere freie Charge desselben Bulk-Artikels, kommt beim Start ein Stopp (ältere zuerst, Handbuch 4.8). Ein Admin kann ihn übergehen.
- `erp_wa_machbar($pa)`: liest `pa_machbar` (die eine Rechnung aus dem Dashboard). Fehlt der Eintrag, gilt die eigene Bereitschaft.
- `erp_wa_tagesplan()`: **genau die Reihenfolge aus dem Reiter Plan**.
  - Laufende stehen zuerst, nicht machbare grau mit Grund. So rückt der nächste machbare nach vorne.
  - PAs, bei denen nur noch QM offen ist, erscheinen nicht.
- `erp_wa_tara()`: Gebindetypen aus `lg_tara`. `erp_wa_kapsel_info()`: Kapseln je Packung.

**Werk-App nur Machbares (13.11.2026, Teil 1):** `erp_wa_machbar_alle()` holt den Machbar-Stand aller offenen PAs frisch per Loopback (`?p=api_pa_machbar`, gleiche Rechnung wie der Plan), Rückfall `pa_machbar`. `erp_wa_sichtbar_grund()` / `erp_wa_pa_sichtbar()`: eine Regel für Liste, Direktaufruf und Buchungs-Sperre ('status', 'fertig', 'nicht_machbar', 'datum'). `erp_wa_tagesplan()` liefert nur sichtbare PAs in Plan-Reihenfolge.

**Reihenfolge laufend zuerst (13.11.2026):** `pa_reihenfolge_laufend_zuerst($rows, $laeuft)` (core/pa_ablauf.php) – laufende PAs oben (untereinander `plan_sort`, dann Startzeit aus `pa_startzeiten()` = erste Unter-Charge bzw. erster erledigter Schritt), danach die übrigen in Plan-Reihenfolge (`plan_sort`, `geplant_am`, id). Grund: laufende PAs stehen nicht im Reiter „Plan“ und lassen sich dort nicht ziehen; sie rutschten in der Werk-App nach unten.

## Nachbesserung Fable-5 (13.11.2026, Teil 2)
- **Laufend immer sichtbar (T2.1):** `erp_wa_sichtbar_grund()` – eine laufende Unter-Charge (`uc_laeuft`) bzw. ein laufender PA (`pa_ist_laufend`: Status laufend oder schon produziert) ist nie gesperrt; „machbar“ entscheidet nur über den Start. Nur voll produzierte (ohne laufende Unter-Charge) fallen raus.
- **produziert (T2.2):** über `pa_produziert_sql()` (nur Fertigware in Stück).
- **Eine Regel laufend (T2.4):** `_lauf` und die Reihenfolge nutzen `pa_ist_laufend()` wie der Dashboard-Reiter „Läuft“.
- **Loopback (T2.5):** Zeitgrenze 8 s; Ausfall = gespeicherter Stand mit Hinweis „Stand von HH:MM“ (`erp_wa_stand_text`) und Protokolleintrag (`erp_wa_loopback_protokoll`, höchstens alle 10 Minuten, Tabelle aktivitaet). `erp_wa_machbar($id, false)` / `erp_wa_pa_sichtbar($id, false)` lesen nur den gespeicherten Stand – so rechnet eine Buchung höchstens einmal neu (die Seite danach).
