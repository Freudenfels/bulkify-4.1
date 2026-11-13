# kunde_charge.php - Charge beim Kunden

`kunde_charge_anzeige($nr)`: Grundcharge ohne Buchstaben (2775.A -> 2775). `kunde_charge_gleich($a, $b)`: gleiche Grundcharge?

Regel (Nico 12.11.2026): Der Kunde sieht nur die Grundcharge und nie, an welchem Tag wir was produziert haben. Die Buchstaben sind
unsere Produktionstage. Genutzt von: Lieferschein und Palettenetikett (Lager-App), Informationsdokument zum Produkt
(`core/coa_uebersicht.php`), Kundenportal (Bestellung, Charge und Qualität, Laborprüfung, Lager 2), Echtheitsseite.
Ohne Abhängigkeiten, auch in der Lager-App ladbar. Geladen von `core/schema.php`.

**Nur eigene Chargen (13.11.2026, Fable-3 T3.1):** `kunde_charge_anzeige($nr, $eigen = null)` kürzt nur, wenn die Charge aus einem eigenen Produktionsauftrag stammt (`kunde_charge_eigen()`: Charge mit `pa_id` oder Unter-Charge mit dieser Nummer). Fremd- und Lieferantenchargen („AB-12.C“, „LOT.EU“) bleiben unverändert, zwei Fremd-Lose werden im Portal nicht mehr zu einem zusammengefasst. Kleinbuchstaben („2775.a“) werden bei eigenen Chargen auch gekürzt. Weiß der Aufrufer es schon, gibt er `$eigen` mit.

**Grundcharge (13.11.2026, Fable-3 T3.2):** Neu `kunde_charge_text($t)`: kürzt eigene Chargen in Freitext („Laboranalyse 2775.A“ → „Laboranalyse 2775“), Fremdchargen bleiben.

**Ungültiges UTF-8 (Fable-4 T4.2, 13.11.2026):** `kunde_charge_text()` gibt bei einem preg-Fehler den Text unverändert zurück, nie leer.
