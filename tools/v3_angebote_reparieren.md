# tools/v3_angebote_reparieren.php – v3-importierte Angebote reparieren

Behebt die zwei häufigsten Folgen des v3-Imports bei **Annapurna/Pure Health** (und allen v3-Angeboten):
1. **„Preise nicht hinterlegt"** – `angebot_staffel.vk_stueck` bzw. `angebot_position.preis_cent` = 0, obwohl in **`produkt_kundenpreis`** (aus v3-Import Stufe 2) ein echter Preis für dieselbe Konfiguration steht.
2. **„Mengen falsch/fehlend"** – `angebot_staffel.stueck` = 0 (Stück je Packung fehlt → Menge wird falsch/leer angezeigt), obwohl `produkt_kundenpreis` für dieselbe Bestellmenge ein `menge_pro_vpe` hat.

**Wichtig:** Es wird **nichts erfunden** – Preis und Stück je Packung kommen ausschließlich aus den v3-eigenen Kundenpreisen (`produkt_kundenpreis`, mit `v3_id`). Läuft nur auf der **v4-DB**, braucht **keine** v3-Quelle.

## Aufruf
```
php tools/v3_angebote_reparieren.php [kundenId] [--write]
```
- ohne `kundenId` = alle v3-importierten Angebote (`angebot.v3_id IS NOT NULL`); mit Kunden-Id nur dieser Kunde (z. B. Pure Health).
- **ohne `--write`** = Trockenlauf (zeigt nur, was passieren würde).
- **mit `--write`** = schreibt. Idempotent (füllt nur, was 0 ist), beliebig oft wiederholbar.

## Matching (Genauigkeit absteigend)
Für eine Staffel/Position (Bestellmenge = `menge`, Stück je Packung = `stueck`) wird der beste passende `produkt_kundenpreis` desselben **Kunde + Produkt** genommen:
exakt (Menge UND Stück) → nur Bestellmenge → nur Stück je Packung → irgendein Preis des Produkts. `stueck` wird über die passende Bestellmenge (`anzahl_vpe`) nachgetragen.

## Ausgabe
Zählt nachgetragene Staffel-Preise / Positions-Preise / Stück-je-Packung und listet die Angebote, die **auch danach keinen v3-Preis** haben (echte v3-Lücke → in v4 **manuell bepreisen**, z. B. über die Preismatrix / den Reiter „Preise" in der Kundenansicht).

## Grenzen
- Platzhalter-Positionen staffel-basierter Angebote (`menge=0`) bleiben unberührt – deren Preise stehen in `angebot_staffel`.
- Wo v3 selbst **nie** einen Preis hatte (z. B. „vollbedruckt"-Verpackungsvarianten), kann das Tool nichts füllen. Diese stehen in der „Ohne v3-Preis"-Liste und müssen manuell bepreist werden.
- Der **eigentliche** Fix bleibt ein **frischer Re-Import** aus der Live-v3 (`tools/v3_import.php` gegen einen aktuellen MySQL-Dump) – dieses Tool schließt die Lücken, die ein alter/unvollständiger Export hinterlassen hat.

Getestet: Preis einer Staffel auf 0 gesetzt → Trockenlauf meldet 1 → `--write` stellt exakt den v3-Kundenpreis (10,63 €) wieder her.
