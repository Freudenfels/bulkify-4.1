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
