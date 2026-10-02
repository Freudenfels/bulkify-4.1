# bestand/eingang.php – Wareneingang (einbuchen)

**Zweck:** Alles, was ins Lager kommt, in einem Schritt einbuchen: Artikel **wählen oder neu anlegen**,
Menge + MHD, **Blinker (Pflicht)**, Kartons, Lieferant, Charge-Nr. Danach ist die Charge angelegt und der
Blinker hängt dran. Scanner-freundlich (Barcode in Artikel- bzw. Blinker-Feld).

**Ablauf (POST `aktion=buchen`):**
1. **Blinker zuerst prüfen** (`led_leiste_normalisieren`, 6 Hex-Zeichen, „…XD" wird abgeschnitten) – ungültig → Abbruch, damit kein verwaister Artikel entsteht.
2. **Menge** > 0 prüfen.
3. **Artikel:** ist ein bestehender gewählt (`item_id`), wird der genommen; sonst wird aus dem getippten Namen ein **neuer Artikel** angelegt (`erp_item_anlegen(name, kategorie, einheit)` in der Naht `erp.php`; Kategorie + Einheit kommen aus dem eingeblendeten „neuer Artikel"-Block).
4. **Buchen:** `erp_wareneingang_buchen()` legt die Charge an (Rohstoff/Fertigware → Quarantäne, Verpackung/Verbrauch → frei), `lg_pakete_set()` speichert die Paketzahl, `lg_bewegung_log()` protokolliert den Eingang.
5. **Blinker anhängen:** `leiste_binden(code, charge_id)` (legt den Blinker bei Bedarf an) und `leiste_finden()` lässt ihn kurz **grün** leuchten. Dann weiter zur Charge-Detailseite.

**Formular:**
- **Artikel** – Such-Combo (`weArtSuche`, `name="art_text"`): tippen/scannen → bestehende Treffer **oder** „+ Neuen Artikel anlegen". Bei neuem Artikel erscheint der Block **Kategorie** (Rohstoff/Verpackung/Verbrauch/Fertigware) + **Einheit**.
- **Menge** (+ Einheit-Anzeige), **Blinker (Pflicht)**, **MHD**, **Charge-Nr. (Lieferant)**, **Anzahl Pakete/Kartons** (→ je Karton ein Etikett), **Lieferant**, **Notiz**.
- **Vorbefüllung per GET:** `&item=&menge=&lieferant=&charge=` – genutzt von „Erwartete Lieferungen" ([erwartet.md](erwartet.md)) über den „Einbuchen"-Knopf.

**Neue Artikel** werden ohne Artikelnummer angelegt (die Nummernkreise des Dashboards sind im Lager nicht
geladen) und tragen die Notiz „Im Lager beim Wareneingang angelegt" – das Team ergänzt Details später im
Dashboard. Darunter steht **„Zuletzt bewegt"** (aus `lg_bewegung`).
