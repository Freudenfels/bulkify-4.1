# kunde_charge.php - Charge beim Kunden

`kunde_charge_anzeige($nr)`: Grundcharge ohne Buchstaben (2775.A -> 2775). `kunde_charge_gleich($a, $b)`: gleiche Grundcharge?

Regel (Nico 12.11.2026): Der Kunde sieht nur die Grundcharge und nie, an welchem Tag wir was produziert haben. Die Buchstaben sind
unsere Produktionstage. Genutzt von: Lieferschein und Palettenetikett (Lager-App), Informationsdokument zum Produkt
(`core/coa_uebersicht.php`), Kundenportal (Bestellung, Charge und Qualität, Laborprüfung, Lager 2), Echtheitsseite.
Ohne Abhängigkeiten, auch in der Lager-App ladbar. Geladen von `core/schema.php`.
