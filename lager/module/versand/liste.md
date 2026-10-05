# versand/liste.php – Warenausgang / Versand (Liste)

Route `?p=versand` (Nav „Warenausgang", Lager 1). Zeigt alle Sendungen (`lg_versand`) mit Status-Reitern
(Alle · Geplant · Versendet · Storniert, `?status=`). Je Zeile: Nummer (WA-…), Empfänger (+ Land, falls
≠ DE), Versandart (Paket/Palette), Status, Sendungsnummer, Angelegt – anklickbar → `?p=versand_detail`.

- **+ Neue Sendung** (`aktion=neu`): legt über `lg_versand_anlegen()` einen Entwurf an und springt in die
  Detailplanung.
- **Schnell abbuchen**: Link auf die alte `?p=ausgang`-Seite (Bestand ohne Sendung abbuchen, z. B. interner
  Verbrauch).

Phase 1 (manuell). Carrier-Anbindung (DHL-Paket, Cargoboard-Palette) folgt in Phase 2/3.
