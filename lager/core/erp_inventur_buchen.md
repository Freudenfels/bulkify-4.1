# lager/core/erp_inventur_buchen.php – Inventur bucht ins Dashboard

Seit 11.11.2026. Schreibende Naht. Sie wird **nur nach Nicos Entscheidung** in der Auswertung aufgerufen.

**Nichts wird gelöscht.** Ausgebucht heißt Menge 0 und Status „leer“, mit Verweis in der Notiz. Jede Feldänderung steht in `aenderung_log` (route `lager_inventur`).

- `erp_inv_korrektur($charge, $gebinde, $delta)` ändert den Bestand um die Differenz, bei Gebinden Gebinde und Charge. Der Bestand geht nie unter 0.
- `erp_inv_ausbuchen()` bucht auf 0 aus, mit Verweis.
- `erp_inv_einheit_korrigieren($charge, $faktor, $einheit)` rechnet Menge (gebucht und verfügbar, auch Gebinde) mit dem Faktor um und setzt die Einheit.
- `erp_inv_umbuchen_sperren()` / `erp_inv_artikel_umbuchen()` buchen eine Charge auf den richtigen Artikel um.
  - Gesperrt bei anderer Warenart, Einheit, Kapselgröße, Füllgewicht, anderem Material oder anderer Rezeptur.
  - Das sind dieselben Regeln wie beim „Artikel zusammenführen“.
- `erp_inv_klaerfall_buchen()` legt für Ware ohne Etikett eine Charge an.
  - Wareneingang ohne Bestellung, `we_grund='inventur'`.
  - Lager 2 wird Kundenware.
  - Dazu kommt ein Gebinde mit der beim Zählen gedruckten Inventarnummer.
  - Eine Bestellung lässt sich danach an der Bestellung („Wareneingang zuordnen“) bzw. in der Datenprüfung zuordnen.
- `erp_inv_benachrichtigen()` erzeugt eine Dashboard-Benachrichtigung, z. B. bei einer Korrektur an Kundenware in Lager 2.
