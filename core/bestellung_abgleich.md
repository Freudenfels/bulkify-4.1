# core/bestellung_abgleich.php – Altlasten Einkauf: Bestellung mit vorhandenen Chargen abgleichen

**Stand 10.11.2026 (Einkauf gerade ziehen, Teil 4).** Für Bestellungen, die vor dem Wareneingang-gegen-Bestellung entstanden sind: Es gibt offene Bestellungen ohne eingebuchte Ware und „eingegangene“ ohne verknüpfte Charge. Es passiert nichts automatisch, alles nur auf Klick.

- `bestellung_abgleich_vorschlaege($bp_id)`: vorhandene Chargen ohne Bestellbezug, ohne eigene Produktion, Eingang ab Bestelldatum (−3 Tage). Punkte für:
  - gleicher Artikel (3) bzw. ähnlicher Artikel (1, gleiche Normalform aus `core/artikel_dubletten.php`, auch zusammengeführte),
  - gleicher Lieferant (3, anderer Lieferant −2),
  - passende Menge (3 bzw. Teilmenge 1),
  - gleicher Auftrag (2).

  Freitext-Positionen (Bulk zum Auftrag) suchen auf dem Bulk- bzw. Fertigartikel des Auftrags und über den Namen.
- `bestellung_abgleich_panel($b)`: Block **„Wareneingang zuordnen“** auf der Bestellseite, je offener Position. Knöpfe:
  - **verknüpfen** (`webe_charge_verknuepfen`, Änderungsprotokoll),
  - **als geliefert schließen** (`webe_als_geliefert_schliessen`: offene Positionen zählen nicht mehr als unterwegs, keine Charge),
  - **stornieren** (solange nicht eingegangen).
- `bestellung_abgleich_treffer($bid)`: Anzahl Positionen mit Vorschlag (Spalte „Vorschläge“ in der Datenprüfung).

**Stand 11.11.2026 (Datenprüfung: Knöpfe in der Zeile).**
- `bestellung_abgleich_positionen($bid)`: offene Positionen (nicht Rest-storniert) mit ihren Vorschlägen (max. 5).
- `bestellung_abgleich_konflikte($zeilen)`: Charge-ID → Bestellnummern, wenn dieselbe Charge bei mehr als einer Bestellung vorgeschlagen wird.
- `bestellung_abgleich_eindeutig($pos, $konflikte)`: der eine Vorschlag, wenn genau einer, gleicher Artikel, Menge passt, kein Konflikt; sonst null. Grundlage für „Alle eindeutigen verknüpfen“.
- `bestellung_abgleich_zelle()`, `bestellung_abgleich_knoepfe()`, `bestellung_abgleich_inhalt()`: HTML der Spalten Vorschläge, Knöpfe, Inhalt.
- `bestellung_abgleich_aktion($aktion, $bid, $bp, $charge)`: verknuepfen / geliefert / rest / storno, Rückgabe `['ok','msg']`. Verknüpfen nur, wenn die Position zur Bestellung gehört und die Charge noch frei ist.

