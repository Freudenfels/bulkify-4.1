# kein_dokument.php – „kein Dokument nötig“ (Sofortfix 13.11.2026)

Route `?p=kein_dokument`, nur POST. Setzt oder entfernt am Artikel den Haken **„kein Dokument nötig“**
(`item.kein_dokument`, dazu `kein_dokument_grund` und `kein_dokument_von`).

- Für Artikel, die nie eine CoA oder Spezifikation brauchen (Schrauben, Masken, Handschuhe usw.).
- Nur **Admin und QM** (Rolle `labor`) dürfen das. Jede Änderung steht im Protokoll des Artikels.
- Danach gilt der Artikel überall als „nichts fehlt“: kein Badge „Unterlagen fehlen“, keine Aufgabe „Unterlagen nachfordern“,
  kein Punkt im Lieferantenportal unter „Fehlende Unterlagen“, keine Quarantäne-Sperre beim Wareneingang, nicht in der Datenprüfung.
- Aufgerufen aus dem Pop-up der Listen „Fehlende Unterlagen“ (Bestellung, Datenprüfung) und vom Haken am Artikel.
- Felder: `item_id`, `an` (1 = setzen, 0 = entfernen), `grund` (optional), `zurueck` (interne Seite, nur `?…`).

Logik: `lu_kein_dokument_setzen()` in `core/lieferant_unterlagen.php`.
