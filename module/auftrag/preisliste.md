# auftrag/preisliste.php – Arbeitsliste „Aufträge ohne Preis"

**Zweck:** Alle Aufträge mit **0,00 € Netto** auf EINER Seite nachpreisen – ohne in jeden Auftrag zu
navigieren. Je Zeile ein Upload: die Rechnung/AB (PDF/Bild) wird per KI in **Einzelpositionen** gelesen
(Herstellung/Kapseln, Dose/Glas, Etiketten …), daraus eine **verknüpfte Rechnung mit Positionen**
angelegt und der **Auftragspreis** (Netto + VK/Stück) gefüllt. So liegen die Preise **aufgeschlüsselt**
beim Kunden.

## Ablauf
- Liste: `auftrag` mit `COALESCE(gesamt_netto,0) <= 0` und `status<>'storniert'` (Kunde/Produkt/Menge).
- POST `aktion=upload` (je Zeile): Datei in `data/uploads`, `rechnung_import_positionen_ki()` liest Kopf +
  Positionszeilen, `auftrag_rechnung_aus_positionen()` legt den Beleg (Rechnung, mit `beleg_position`,
  `auftrag_id`, `kunde_sichtbar=1`, Original-PDF) an und füllt `auftrag.gesamt_netto`/`vk_stueck`
  (nur wenn noch kein Preis). Checkbox „bezahlt" setzt den Rechnungsstatus. Danach ist der Auftrag aus
  der Liste raus (hat jetzt einen Preis).
- Liest die KI nichts, bleibt die Datei als Dokument am Auftrag; Preis dann im Auftrag von Hand.

## Funktionen (`core/schema.php`)
`rechnung_import_positionen_ki($pfad)` (Positionen + Kopf), `auftrag_rechnung_aus_positionen($auftrag_id,
$positionen, $opt, $datei, $orig)` (verknüpfte Rechnung + Preis). Siehe auch [rechnung_import](../beleg/rechnung_import.md)
(neue, nicht verknüpfte Rechnungen) und [auftrag_import](../beleg/auftrag_import.md) (neue Aufträge aus Angebot).

## Route & Rechte
`?p=auftrag_preise` → `public/index.php`; Rollen **sales, finance**. Verlinkt aus der Aufträge-Liste
(Knopf „Aufträge ohne Preis (N)", erscheint nur wenn es welche gibt). Braucht die KI (Einstellungen → KI).
