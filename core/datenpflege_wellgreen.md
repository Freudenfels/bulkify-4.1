# datenpflege_wellgreen.php – einmalige Datenpflege Wellgreen (12.11.2026)

Abgleich der Wellgreen-Bestellungen (L-2789) gegen Maggis Liste „Maggi x Nico Order“ (Stand 12.11.2026). Freigabe Nico 12.11.2026, 20:15.

- Läuft **genau einmal** aus dem Schema-Abgleich (`dpf_wg_einmal()`, Merker `app_meta dpf_wg_erledigt`), nie in der Testumgebung.
- **Wellgreen wird nicht informiert:** Stornos mit `$lieferant_informieren = false`, Verknüpfen ohne Meldung. Am Ende zählt der Lauf
  die neuen Portal-Nachrichten an Wellgreen (muss 0 sein).
- Nur **eindeutige Fälle** (feste Liste in `dpf_wg_konfig()`), jede Bedingung wird zur Laufzeit noch einmal geprüft:
  1. Charge an die ursprüngliche Wellgreen-Bestellung hängen (Menge, Eingang und Bestelldatum passen zu Maggi). Ist die Bestellung noch
     ohne Eingangsdatum, wird der Wareneingang der Charge übernommen.
  2. Doppelte Bestellung stornieren (BE-2933).
  3. Doppelte Positionen stornieren – nur, wenn die ursprüngliche Bestellung wirklich mit Charge geliefert ist.
- Jede Änderung bekommt einen Verlaufseintrag „Abgleich Maggi-Liste, Freigabe Nico 12.11.2026“. Nichts gelöscht, keine Charge-Menge geändert.
- Ergebnis: `app_meta dpf_wg_ergebnis` (JSON), sichtbar unter `?p=wellgreen_abgleich`.
- `dpf_wg_ausfuehren($konfig)` kann mit eigener Konfiguration getestet werden (Testkopie).
