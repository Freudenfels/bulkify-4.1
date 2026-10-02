# bestand/l2_eingang.php – Lager 2: Kundenware einbuchen

Wie der Wareneingang ([eingang.md](eingang.md)), aber mit **Kunde (Pflicht)**: wem gehört die Ware. Legt
eine Charge an, die dem Kunden gehört (`erp_wareneingang_buchen_fremd()`, Status **frei**, keine
Quarantäne). Artikel bestehend wählen **oder neu anlegen** (`erp_item_anlegen`, Standard-Kategorie
Fertigware), **Blinker Pflicht** (wird gebunden, leuchtet grün). Kunden = `erp_fulfillment_kunden()`
(kunden.nutzt_fulfillment).
