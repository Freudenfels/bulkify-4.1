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
- `BA_POS_OFFEN` (SQL-Bedingung, Alias `bp`) und `bestellung_abgleich_offen_anzahl($bid)`: offene Position = Menge > 0, nicht Rest-storniert, geliefert < 98 %, keine Charge. Gilt für die Datenprüfung und `bestellung_abgleich_positionen()`; „verknuepfen“ liefert zusätzlich `offen`.


**Block B (11.11.2026)**
- **Abweichung sichtbar:** Jeder Vorschlag trägt `offen_charge` (die offene Bestellmenge in der Einheit der Charge) und `abweichung` („Charge 300.000, offen 240.000 Stk“), wenn die Menge um mehr als 2 % abweicht.
- **„Menge korrigieren und verknüpfen“** an jedem Vorschlag (Bestellseite: Pop-up `charge_korr_dialog`, Datenprüfung: JS-Pop-up). Das Pop-up ist mit der offenen Bestellmenge vorbelegt. Erst wird korrigiert (`charge_menge_korrigieren`), dann verknüpft. Aktion `korr_verknuepfen`, nur Admin.
- **„Verknüpfung lösen“:** Aktion `loesen` -> `webe_charge_loesen()`, nur Admin.
- **Mehrere Chargen je Position:** `BA_POS_OFFEN` blendet eine Position nicht mehr aus, sobald irgendeine Charge dranhängt. Sie bleibt offen, solange weniger als 98 % geliefert sind. „verknüpfen“ gibt `pos_voll` zurück.

## Verknüpfte Chargen in der Datenprüfung (13.11.2026)
Die Zelle zeigt schon verknüpfte Chargen der Bestellung mit „Verknüpfung lösen“, auch nach dem Neuladen. Beim Verknüpfen meldet sie eine Mehrlieferung.

**Falle „als geliefert schließen“ entfernt (Sofortfix 13.11.2026):** Block heißt „Wareneingang“; je offener Position Hauptknopf „Ware ist da - einbuchen“ (`?p=wareneingang&bp=…&bch=…`, vorbelegt aus „Chargen & Dokumente“). „ohne Einbuchen schließen (Altlast)“ nur Admin, eingeklappt unter „Altlasten“, mit Pop-up `bestellung_altlast_dialog()` („Ist die Ware im Lager?“: Ja = Wareneingang je Position, Nein = Grund Pflicht). Positionen „ohne Ware geschlossen“ (Rest storniert ohne Positions-Storno, keine Charge) bleiben sichtbar, mit Vorschlägen zum Verknüpfen; Verknüpfen vermerkt `webe_nachtraeglich_eingebucht_vermerken()`. Die Datenprüfung nutzt dasselbe Pop-up; die alte Aktion „geliefert“ ohne Grund ist gesperrt.
