# Betriebsmittel (betriebsmittel_detail.php)

Anlegen und Bearbeiten von Warenlager-Dingen **ohne Chargen/MHD**:
Kartons, Verbrauchsgüter, Inventar, Maschinen, Sonstiges.

- Route: `?p=betriebsmittel&id=<n>` (oder `&id=neu&kat=<kategorie>` für neu).
- Kategorien kommen aus `betriebsmittel_kategorien()`; Artikelnummer automatisch je Kategorie
  (KA/VB/IN/MA/SO über `item_prefix()` + `naechste_nummer()`).
- Bestand ist ein **einfacher Zähler** in `item.bestand_menge` (kein Wareneingang nötig),
  dazu optionaler Mindestbestand.

## Geräteprüfung (DGUV V3)
- Häkchen „Elektronisches Gerät" setzt `item.elektrisch=1`.
- `pruef_intervall_monate` (Standard 12) + `letzte_pruefung` → nächster Termin
  über `pruefung_naechste()`. Status (`ok`/`bald`/`faellig`/`offen`) über `pruefung_status()`.
- Button „Prüfung heute erledigt" setzt die letzte Prüfung auf heute.
- Überfällige/bald fällige Geräte werden im Warenlager (Reiter Maschinen/Inventar/Sonstiges)
  farbig markiert; Liste aller fälligen über `pruefungen_faellig()`.

Angezeigt wird das im **Warenlager** (`bestand_liste.php`) über die Typ-Reiter.
