# gebinde.php – eigene Gebinde-/Karton-Aufkleber (Spec 5.6)

Verpackungsmaterial (Gläser, Deckel) hat herstellerseitig **keine Charge**. Damit es trotzdem
rückverfolgbar ist, bekommt **jedes Gebinde/jeder Karton** beim Wareneingang einen **eigenen
Aufkleber mit eigenem QR-Code und eigener Nummer** (`GB-00001`, …). Das ist unsere „Ersatz-Charge".

## Zwei Modi in einer Seite
- **Scan-Auflösung** `?p=gebinde&nr=GB-00012` – nach dem Scan (QR führt genau hierher): zeigt zur
  Nummer die **Charge**, das **Produkt/Material**, die **Lieferanten-Charge**, den **Lieferanten**
  und das **Wareneingangsdatum**. Daraus lässt sich bei Problemen (Glasbruch, Deckelmängel) die
  betroffene Lieferung dem Lieferanten zuordnen → **Regress**.
- **Verwaltung** `?p=gebinde&charge=<id>` – zu einer Charge die vorhandenen Gebinde auflisten,
  **N neue erzeugen** (Vorschlag = Kartonzahl der Lieferung, `lg_pakete`) und die **Etiketten als
  PDF** öffnen (`?p=gebinde_etikett`).

## Aktionen (POST)
- `erzeugen` – legt N Gebinde (`lg_gebinde_anlegen`) für die Charge an; Lieferant/Ref kommen aus der Charge.
- `loeschen` – entfernt einen einzelnen Gebinde-Aufkleber.

## Daten
Eigene Tabelle `lg_gebinde` (siehe `core/schema.php`). Dashboard-Charge bleibt unberührt – die
Verknüpfung läuft nur über `lg_gebinde.charge_id`. Erreicht von der Charge-Detailseite aus.

## Teil 4: Bestands-Gebinde-Resolver (?bg=)
Zusätzlicher Modus `?p=gebinde&bg=<charge_gebinde.id>`: Scan-Auflösung des Wareneingangs-Etiketts auf GENAU dieses Bestands-Gebinde (Restmenge, Ort, Status + Charge/Lieferant/MHD). Getrennt vom GB-Identitäts-System (`?nr=`, lg_gebinde).

## Etikettenrolle per Inventaraufkleber (?inv=)
Modus `?p=gebinde&inv=<8 Ziffern>`: löst eine **Etikettenrolle** über ihren Maniso-Inventaraufkleber auf
(`erp_rolle_per_inventar()`): Etikett, Menge auf der Rolle, Status, Charge, Lieferant, Kunde und **zugeordneter
Auftrag**. Die Rolle ist ein `charge_gebinde`-Datensatz mit `inventar_nr` (siehe Wareneingang). Aktionen (POST):
`rolle_zuordnen` → `erp_rolle_auftrag_zuordnen()` (ordnet die Rolle dem Auftrag der Charge zu, Standort→Produktion),
`rolle_freigeben` → `erp_rolle_freigeben()` (Zuordnung lösen, zurück nach Lager 1). Die 8-stellige Nummer wird aus
Suche (`?p=suche`/`?p=finden`) und Scan-Endpunkt (`public/lager/scan.php`) hierher geleitet.

Scan eines Bestands-Gebindes `?bg=` (04.11.2026): zeigt aktuelles Gewicht (gewogen/gleichmäßig verteilt), ursprüngliche Menge, Ort mit Kiste/Blinker, Artikel, Charge, MHD. Darunter (nicht bei Etikettenrollen) „Entnehmen“ (`geb_entnehmen`: nur dieses Gebinde) und „Rückgabe aus der Produktion“ bzw. „Nachwiegen“ (`geb_rueckgabe`: Ist-Gewicht + Pflicht-Haken „ordentlich verschlossen“; Differenz als Verbrauch/Schwund, Änderungslog).

**Ist-Gewicht (Fable-4 T1.4, 13.11.2026):** Nachwiegen/Rückgabe liest über `erp_menge_einheit_lesen()` in der Einheit des Gebindes; Feld mit `data-gewicht` (Rückfrage bei „1.250“). Liegt das Ist über der Gebindemenge, kommt eine gelbe Warnung.
