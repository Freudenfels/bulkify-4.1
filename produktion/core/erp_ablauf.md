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
