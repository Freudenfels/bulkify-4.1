# module/lager/artikel_zusammenfuehren.php – Artikel zusammenführen

**Stand 10.11.2026 (Einkauf gerade ziehen, Teil 5).** Route `?p=artikel_zusammenfuehren`, die Seite ist nur für Admins. Menü: System → Artikel zusammenführen. Aus der Datenprüfung führt der Prüfpunkt „ähnliche Artikel (Dubletten-Gruppen)“ hierher.

## Regel (Nico)
- Gleiche Qualität/Spezifikation von verschiedenen Lieferanten = **ein** Artikel mit mehreren Lieferantenpreisen.
- Andere Spezifikation = eigener Artikel, z. B. 13 % vs. 20 % Mg, Bio vs. konventionell, anderer Extraktgehalt.
- Bulk-Kapseln, Premixe und Fertigware mit anderer **Kapselgröße**, anderem **Füllgewicht** oder anderem **Kapselmaterial** sind **nie** zusammenführbar.

## Seite
- Die Gruppen sind je Warenart nach der Normalform des Namens gebildet (mit Synonymen, `core/artikel_dubletten.php`). Filter: Rohstoffe, Bulkware, Fertigprodukte, Verpackung, Alle, dazu eine Suche.
- Je Gruppe ein Vergleich:
  - Warenart/Einheit, Gehalt, Bio,
  - **Kapselgröße/Füllgewicht** (fett), Material,
  - Lieferanten, Bestand,
  - Verwendung in Rezepturen, Bestellungen und Produkten.
- Als Hauptartikel ist der mit der meisten Verwendung vorgeschlagen. Ein anderer Hauptartikel wird per Radioknopf gewählt, dann lädt die Seite neu und prüft die Sperren gegen ihn.
- Zusammenführbare Artikel bekommen einen Haken. Gesperrte zeigen den Grund (`artikel_merge_sperren`).
- „Angehakte in den Hauptartikel zusammenführen“ (mit Rückfrage) ruft `artikel_zusammenfuehren_voll()` auf. Chargen, Bestellpositionen, Rezepturzeilen, Lieferantenpreise, Dokumente, Produkt-Slots usw. ziehen um. Der alte Artikel wird gesperrt (`zusammengefuehrt_in`), sein Name bleibt als Synonym am Hauptartikel. Das Änderungsprotokoll wird geschrieben, gelöscht wird nichts.
- `?p=artikel_zusammenfuehren&treffer=<Name>&kat=…` liefert JSON für „Meintest du …?“ beim Anlegen eines Artikels. Die Rollen production, einkauf und labor dürfen das abfragen.
