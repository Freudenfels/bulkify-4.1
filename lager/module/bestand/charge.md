# bestand/charge.php – Charge-Detail (`?p=charge&id=`)

Alle Angaben zu einer Charge an einem Ort:

- **Kennzahlen:** Bestand, MHD (Ampel), Status, Chargennummer.
- **Blinker:** ist einer gebunden, gibt es Finden / Aus / Lösen (X). Ist keiner dran, ein Feld zum Scannen und Binden (bestätigt kurz blau, ohne Ton).
- **Angaben:** Rohstoff/Produkt, Kategorie, Artikelnummer, eingegangene und verfügbare Menge, MHD, Lieferant, Wareneingang, Sendungsnummer(n), Notiz.

Zusätzlich, wie im Dashboard, aber auf diese Charge bezogen:
- **Charge und Lieferung:** Menge, MHD, Status, Lieferant, Wareneingang, Sendungsnummer, Notiz, und die **Dokumente** (Lieferschein/CoA/Spec) als anklickbare Links (`?p=dok`).
- **Produkt:** Stammdaten (Name, Englisch/Botanisch, CAS, Form, Dichte, Allergene, Herkunft, Kunde) und **Wirkstoffe**.
- **Weitere Chargen dieses Produkts:** anklickbar.

**Charge korrigieren / löschen:** MHD korrigieren (Datumsfeld, leer = kein MHD → `aktion=mhd_korr`, direktes `UPDATE charge SET mhd`), Mengenkorrektur (`menge_korr`), in den Mülleimer (`loeschen`).

Der **Produktname ist überall ein Link** (Bestand-Liste, Such-Popup) und führt hierher.

**Bearbeiten an der Charge:** Status (Freigeben/Quarantäne/Sperren → `erp_charge_status_setzen`), **Lieferant** (tippen, neue werden angelegt → `erp_charge_lieferant_setzen`), **Einheit** (`erp_charge_einheit_setzen`, z. B. Pulver „Stk" → „kg" korrigieren), sowie MHD/Bestand korrigieren (`mhd_korr`/`menge_korr`).

Liest über `erp_charge_voll()`, `erp_item_voll()`, `erp_item_wirkstoffe()`, `erp_item_dokumente()`, `erp_item_chargen()` aus dem geteilten Dashboard-Bestand. Bindet/löst Blinker über `core/leiste.php`.
