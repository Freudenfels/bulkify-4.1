# lager/rohstoffe_liste.php – Rohstoff-/Item-Liste

**Zweck:** Übersicht der Warenlager-Artikel, Start-Fokus **Rohstoffe** (die Zutaten für Rezepturen). Dieselbe Tabelle `item` nimmt später auch Verpackung, Verbrauch, Fertigware usw. auf.

**Was passiert hier:**
1. `seed_item_if_empty()` – legt lokal Demo-Rohstoffe an, falls leer.
2. **Kategorie-Filter** (`?kat=`): Standard „Rohstoffe (Wirkstoffe)"; dazu eigene Sicht **„Leerkapseln"** (`kat=leerkapsel` = Rohstoffe mit Form `kapselhuelle`), andere Kategorien und „Alle". Die normale Rohstoff-Sicht **blendet Leerkapseln aus**, damit Wirkstoffe und Kapseln getrennt bleiben. Die Leerkapsel-Sicht zeigt eigene Spalten: **Größe · Material · Farbe · Leergewicht · EK-Preis** und legt neue Kapseln direkt mit vorbelegter Form an.
3. **Suche:** Name, englischer/lateinischer Name, Artikelnummer, Form.
4. **Sortierung:** Standard = Name A–Z.
5. Tabelle über `bx_table()` mit Spalten:
   **Art.-Nr. · Name · Preis ab · Form · Wirkstoffe · Unterlagen/Lieferant · Status** (bei „Alle" zusätzlich Kategorie).
   - **Preis ab** (statt lat. Name): günstigster bekannter EK, „ab X,YZ €/Bezug" – Minimum aus eigenem EK und id-verknüpften Lieferantenpreisen (`lieferant_preis`). Ohne Preis „–". Sub-Cent mit 4 Nachkommastellen.
   - **Wirkstoffe** zeigt alle Wirkstoffe des Rohstoffs (aus `item_wirkstoff`).
   - **Unterlagen/Lieferant**: drei kompakte Marker – **Spec · CoA · Lief.** – grün = vorhanden, grau = nicht. Spec = Spec-PDF, strukturierte Spec-Inhalte (`item_kennwert/-wirkstoff/-grenzwert`) oder Spec-Dokument; CoA = Charge mit Analysewerten oder CoA-Dokument; Lief. = id-verknüpfter Lieferantenpreis vorhanden. Ermittelt über wenige Sammelabfragen auf die angezeigten Item-IDs.
   - Klick auf eine Zeile öffnet den Rohstoff (`?p=rohstoff&id=...`).
6. Button „Neuer Rohstoff".

**Leerkapsel-Sicht** behält ihre eigenen Spalten inkl. **EK-Preis**.
Die **Suche** durchsucht weiterhin auch den lateinischen Namen, auch wenn er nicht mehr als Spalte steht.

**Lücken-Filter (`?fehlt=`):** Dropdown in der Leiste – „alle / etwas fehlt / ohne Lieferant / ohne Preis / ohne Spec / ohne CoA". Zeigt gezielt die Rohstoffe, bei denen etwas fehlt, damit man sie anfragen/hinterlegen kann. Grundlage sind dieselben Sets wie die Marker (id-verknüpfte Lieferantenpreise, Spec/CoA-Inhalte). Die v3-`lieferant_preisliste` fließt bewusst NICHT ein: 636 Freitext-Zeilen (Menge/Verpackung im Namen, „5HTP 25Kg"), nur ~30 exakte Namenstreffer und keine Lieferantennamen – das würde die Marker verfälschen.
