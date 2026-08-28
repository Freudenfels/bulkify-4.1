# lager/rohstoffe_liste.php – Rohstoff-/Item-Liste

**Zweck:** Übersicht der Warenlager-Artikel, Start-Fokus **Rohstoffe** (die Zutaten für Rezepturen). Dieselbe Tabelle `item` nimmt später auch Verpackung, Verbrauch, Fertigware usw. auf.

**Was passiert hier:**
1. `seed_item_if_empty()` – legt lokal Demo-Rohstoffe an, falls leer.
2. **Kategorie-Filter** (`?kat=`): Standard „Rohstoffe (Wirkstoffe)"; dazu eigene Sicht **„Leerkapseln"** (`kat=leerkapsel` = Rohstoffe mit Form `kapselhuelle`), andere Kategorien und „Alle". Die normale Rohstoff-Sicht **blendet Leerkapseln aus**, damit Wirkstoffe und Kapseln getrennt bleiben. Die Leerkapsel-Sicht zeigt eigene Spalten: **Größe · Material · Farbe · Leergewicht · EK-Preis** und legt neue Kapseln direkt mit vorbelegter Form an.
3. **Suche:** Name, englischer/lateinischer Name, Artikelnummer, Form.
4. **Sortierung:** Standard = Name A–Z.
5. Tabelle über `bx_table()` mit Spalten:
   **Art.-Nr. · Name · lat. Name · Form · Wirkstoffe · EK-Preis · Status** (bei „Alle" zusätzlich Kategorie).
   - Spalte **Wirkstoffe** zeigt alle Wirkstoffe des Rohstoffs (aus `item_wirkstoff`), z. B. „95 % Curcumin" oder „Vitamin D · Vitamin K".
   - Klick auf eine Zeile öffnet den Rohstoff (`?p=rohstoff&id=...`).
6. Button „Neuer Rohstoff".

**Preis-Anzeige:** EK-Preis je Bezugseinheit (z. B. 42,00 €/kg); Sub-Cent-Preise mit 4 Nachkommastellen.
