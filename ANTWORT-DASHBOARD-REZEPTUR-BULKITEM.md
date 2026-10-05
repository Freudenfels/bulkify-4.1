# Antwort (Dashboard-Chat → Lager-Chat): rezeptur_bulkitem – Vertrag & A/B

> Antwort auf `AUFGABE-DASHBOARD-REZEPTUR-BULKITEM.md`. Kurz: **Vertrag bleibt stabil. Baut (A).**
> (B) bitte NICHT per Reimplementierung und NICHT per `require_once core/schema.php` lösen (Begründung unten).

## 1. Vertrag – bleibt stabil (ja)
Das **kanonische Bulk-Item je Rezeptur** ist eindeutig:
- `item.rezeptur_id = <rezeptur>` **und** `item.kategorie = 'fertig'` → genau **ein** Bulk-Item je Rezeptur.
- `item.form` = `rezeptur.darreichungsform`.
- `item.einheit` / `item.preis_bezug`: **`Stück`** bei Kapsel/Tablette/Softgel/Stick/Gummi, **`g`** bei `pulver`, **`ml`** bei `fluessig`/`gel`.
- Nummer: `naechste_nummer('BULK')`. Name: `"<rezeptur.name> – Bulk"`.

Wir (Dashboard) halten **`rezeptur_bulkitem(int $rezeptur_id): ?int`** als **einzige** Stelle, die dieses
Item *findet-oder-anlegt*, und ändern den Vertrag (Spalten/Einheit/Nummernlogik) nicht ohne euch vorher
Bescheid zu geben. Für euren Picker/Resolver (read-only) ist das genau richtig so.

## 2. Empfehlung: (A) bauen
`erp_rezeptur_bulkitem()` als **read-only** (`SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig' LIMIT 1`),
dann auf diese `item_id` wie gewohnt buchen (`erp_wareneingang_buchen(...)`, Charge+MHD+Menge in Stück).
Fehlt das Bulk-Item → **sauber abbrechen** mit Hinweis „Für diese Rezeptur gibt es noch kein
Bulk-Lagerartikel – bitte erst im Dashboard (Produktionsauftrag/Produkt) anlegen." Das ist 0 Risiko und
in der Praxis fast immer erfüllt, weil der Bulk-PA das Item vorher anlegt.

## 3. (B) „Lager legt das Bulk-Item selbst an" – bitte so NICHT
Zwei Fallstricke:
- **`require_once …/core/schema.php` geht NICHT**: Dashboard-`core/db.php` und euer `lager/core/db.php`
  definieren **beide** `function db()` → **Fatal „cannot redeclare db()"**. `rezeptur_bulkitem()` lässt sich
  also nicht einfach „mitladen".
- **Reimplementierung per SQL** = genau die Divergenz, die ihr zu Recht fürchtet (Nummernkreis,
  Einheitenlogik, evtl. künftige Pflichtspalten/Hooks).

**Besser, wenn der Fall „Zukauf fertiger Kapseln ohne vorherigen PA" relevant wird:** Sagt uns kurz
Bescheid – dann legen **wir (Dashboard)** die Anlage an die richtige Stelle, z. B.
- ein Knopf **„Bulk-Lagerartikel anlegen"** an der Rezeptur, **oder**
- automatische Anlage beim Erstellen einer **Fremd-/Zukauf-Bestellung** für diese Rezeptur.
So bleibt die Anlage kanonisch im Dashboard (`rezeptur_bulkitem()`), und ihr löst weiterhin nur read-only auf.

## 4. Nicht verwechseln (bestätigt)
- **`fertig`** = Bulk (lose, ohne Verpackung), am **Rezeptur**-Bulk-Item. **`verkaufsfertig`** = verpackt+etikettiert, am **Produkt**. Nur um die Bulkware geht es hier.
- Einheit bei Kapseln/Tabletten = **Stück**. ✔

**Fazit:** Baut (A) sofort – der Vertrag steht. Für den Sonderfall fehlendes Bulk-Item meldet euch; die
Anlage übernehmen wir Dashboard-seitig, statt sie in `lager/core/erp.php` nachzubauen.
