# core/wareneingang_bestellung.php – Wareneingang gegen eine Bestellung

**Stand 10.11.2026 (Einkauf gerade ziehen, Teil 1-3).** Eine Stelle für alle Einbuchwege: Dashboard (`?p=wareneingang`, Bestellseite) und Lager-App (`?p=we`). Die Datei braucht `core/schema.php` nicht (Sub-App-Regel), nur `q/one/all/scalar/insert_id`. Alle Funktionen heißen `webe_…`.

## Regeln
- Ware hängt an einer **Bestellposition**: `charge.bestellung_position_id`. Jede Charge gehört zu genau einer Position, mehrere Chargen je Position sind möglich (Teillieferung, zwei Chargen).
- **Gelieferte Menge** einer Position = Summe der verknüpften Chargen (umgerechnet in die Einheit der Position, g/kg, ml/l). `bestellung_position.geliefert_menge` ist nur der Zwischenspeicher dafür. Damit rechnen Bestellliste, Material-Reiter und Beschaffungsweg „unterwegs“ unverändert als Menge minus geliefert. Altwerte ohne verknüpfte Charge bleiben stehen.
- **Alles da** (mind. 98 % je Position, oder Rest storniert): Die Bestellung schließt sich selbst (`status=geliefert`, `angekommen_am`, Portal-Station „eingegangen“). Das gilt egal, welchen Stand der Lieferant im Portal gesetzt hat.
- **Teillieferung:** Die Bestellung bleibt offen, der Rest zählt als unterwegs. **„Rest storniert“** (`webe_rest_stornieren`) schließt sie, der Rest zählt nicht mehr. Ist gar nichts geliefert, wird sie storniert.
- **Mehrlieferung:** nur Hinweis, der Überschuss ist freier Bestand.
- **Ohne Bestellung** nur mit Grund (`webe_gruende()`):
  - **Alte Bestellung, nicht im System:** `webe_nachtraegliche_bestellung()` legt eine Bestellung an (Bestellweg direkt bestellt, Status geliefert, Menge = Eingang, `nachtraeglich_grund`) und verknüpft die Charge. Dazu kommt eine wichtige Benachrichtigung „Nachträgliche Bestellung … prüfen“.
  - **Muster / Sonstiges:** Pflichtnotiz. Lager-App: auch Kundenware (Lager 2). Grund und Notiz stehen an der Charge (`charge.we_grund`, `we_grund_notiz`), dazu kommt eine Benachrichtigung.

## Funktionen
| Funktion | Zweck |
|---|---|
| `webe_spalten()` | Neue Spalten best-effort anlegen: `bestellung_position.rest_storniert_am`, `bestellung.nachtraeglich_grund/_geprueft_am`, `charge.we_grund/_notiz` |
| `webe_charge_verknuepfen(charge, bp)` | Charge an Position hängen (Lieferant/Auftrag der Position übernehmen), Bestellung neu bewerten |
| `webe_position_neu_rechnen(bp)` | Gelieferte Menge aus den Chargen |
| `webe_bestellung_stand(bid)` | Prüfen und ggf. schließen; Rückgabe komplett/offen/mehr |
| `webe_rest_stornieren(bid)` | Rest einer Teillieferung stornieren |
| `webe_nachtraegliche_bestellung(charge, lieferant)` | Wareneingang ohne Bestellung, Grund „alte Bestellung“ |
| `webe_ohne_bestellung_vermerken(charge, grund, notiz)` | Muster/Kundenware/Sonstiges |
| `webe_offene_positionen(such)` | Erwartete Lieferungen je Position (Suche: BE-Nr., Lieferant, Artikel, Synonyme, Auftrag, Sendungsnummer) |
| `webe_kapsel_label(item)` | „Größe 0 · 450 mg“ zur Anzeige bei jedem Schritt |

Aufrufer: `bestellung_lieferung_erfassen()` (core/schema.php), `erp_bestellung_lieferung_erfassen()` (lager/core/erp.php), `module/lager/wareneingang.php`, `lager/module/bestand/wareneingang.php`, `module/einkauf/detail.php`.

## Als geliefert schließen (Teil 4)
`webe_als_geliefert_schliessen($bid)`: Die Ware kam, ist aber nicht einzeln eingebucht. Offene Positionen bekommen `rest_storniert_am`, die Bestellung wird geliefert/angekommen, dazu ein Vermerk in der Notiz. Es wird keine Charge angelegt.
