# bestand/charge.php – Charge-Detail (`?p=charge&id=`)

Alle Angaben zu einer Charge an einem Ort:

- **Kennzahlen:** Bestand, MHD (Ampel), Status, Chargennummer.
- **Blinker:** ist einer gebunden, gibt es Finden / Aus / Lösen (X). Ist keiner dran, ein Feld zum Scannen und Binden (bestätigt kurz blau, ohne Ton).
- **Angaben:** Rohstoff/Produkt, Kategorie, Artikelnummer, eingegangene und verfügbare Menge, MHD, Lieferant, Wareneingang, Sendungsnummer(n), Notiz.

Liest über `erp_charge_voll()` aus dem geteilten Dashboard-Bestand. Bindet/löst Blinker über `core/leiste.php`.
