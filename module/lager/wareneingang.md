# lager/wareneingang.php – Wareneingang

**Zweck:** Eingehende Ware als **Charge** buchen und Quarantäne freigeben.

**Was passiert hier:**
- **Buchen (POST `aktion=buchen`):** `wareneingang_buchen()` legt eine Charge an (Menge, Charge-Nr, MHD, Lieferant) und gibt die neue Charge-ID zurück (die ID wird **vor** `bedarf_bump()` gesichert, da `meta_set()` sonst `LAST_INSERT_ID` auf 0 setzt – das hatte vorher die „gebucht/fehler"-Meldung verfälscht). Rohstoffe/Fertigware starten in **Quarantäne**, Verpackungen sofort **frei**. Menge = verfügbare Menge.
- **Tracking-Code(s) Paket/Palette:** Feld zum **Scannen** des Sendungs-/Barcodes vom Paket- oder Palettenetikett (`charge.tracking`, TEXT, je Zeile ein Code). Ein Barcode-Scanner tippt Code + Enter → jeder Scan landet in einer eigenen Zeile (Textarea, Live-Zähler „N Pakete erfasst"). Beim Buchen werden die Codes (getrimmt, dedupliziert) an die Charge gehängt – so ist der **Erhalt belegt und nachverfolgbar**, gerade bei vielen Paketen. Die Chargenliste zeigt den ersten Code + „+N Pakete" (Tooltip mit allen); eine **Suche** (GET `such`) findet Chargen über Tracking-Code, Charge-Nr oder Artikel (bis 100 Treffer) – z. B. „ist Paket X angekommen?".
- **Abgleich mit CoA-Vorab-Charge:** Wurde zu diesem Rohstoff schon eine Charge aus einer CoA vorab angelegt (gleiche Chargen-Nr., noch keine Ware: `wareneingang IS NULL`, Menge 0), bucht `wareneingang_buchen()` **diese** Charge ein (Menge/MHD/Datum, Status) statt eine Dublette anzulegen – die Analysewerte aus der CoA bleiben so an der Charge. Siehe `core/spec_ki.md` (`spec_ki_coa_charge`).
- **Freigeben (POST `aktion=freigeben`):** setzt eine Charge von Quarantäne auf **frei** (damit für Produktion nutzbar).
- **Anzeige:** Buchungsformular (Artikel, Menge, Charge, MHD, Lieferant, Notiz) + Liste der **letzten Chargen** mit Status und Freigabe-Button.
- **Artikel-Feld = durchsuchbares Kombifeld** (nicht mehr ein Dropdown mit >1200 Einträgen): Texteingabe filtert live (Substring im Namen, max. 50 Treffer), Auswahl per Klick oder Enter (Pfeiltasten navigieren). Der eigentliche Wert steht in einem verstecktem `item_id`; ein Submit-Guard verlangt eine echte Auswahl aus der Liste (zusätzlich zur Server-Prüfung). Die Artikel werden einmalig als JSON eingebettet (nur `rohstoff`/`verpackung`/`fertig`/`verkaufsfertig`, `gesperrt=0`). Reines Vanilla-JS, keine Bibliothek.

**Typ zuerst (UI):** Das Buchungsformular startet mit einem **Typ-Selektor „Was kommt rein?"** (`#weTyp`): Rohstoff · Verpackung/Etikett · Fertigware (Bulk, vorhandener Artikel) · **Fertigware zum Auftrag (Fremdproduktion)**. Der Typ steuert die Folge-Eingabe: Bei den ersten drei filtert das Artikel-Kombifeld **auf genau diese Kategorie** (reines JS, `curKat`), bei „Fremdproduktion" erscheint stattdessen ein **Auftrags-Dropdown**. JS schaltet Sichtbarkeit, `required` und `disabled` der jeweils nicht genutzten Felder um und setzt das versteckte `aktion` (`buchen` bzw. `zukauf_buchen`) – so wird je nach Typ nur das passende Feld abgeschickt.

**Etikett-Abgleich beim Buchen:** Wird ein **Etikett-Artikel** (`verpackung_rolle='etikett'`) gewählt, erscheint ein hervorgehobenes Panel **„Etikett prüfen"** mit dem **bestellten Format** (`item.breite_mm × hoehe_mm`, sonst `etikett_format`) – zum Ausmessen gegen das gelieferte Etikett (fängt Fälle wie „Etikett 4 mm kleiner als bestellt" ab). Ist zusätzlich oben **„Für Auftrag"** gewählt und am Auftrag ein freigegebenes Etikett-**Design** hinterlegt (`$aufEtikett`: `dokument` `objekt_typ='auftrag'`, `typ='etikett'`), wird das **Design als Bild** inline angezeigt (`<iframe>` auf `?p=dokument&id=…`, Content-Disposition `inline`) plus „in neuem Tab öffnen". Alles reines JS (Items tragen `r/w/h/f`, Map `aufEtikett`), nachgeladen bei Artikel- bzw. Auftragswahl.

**Zukauf-Fertigware zu einem Auftrag (POST `aktion=zukauf_buchen`):** Für **Fremdproduktion** gibt es die fertig gelieferte Bulkware meist noch **nicht als Katalog-Artikel** (eine Rezeptur ist kein `item`). Über den Typ „Fremdproduktion" wählt man den **Auftrag** → der Bulk-Artikel der Rezeptur des Auftragsprodukts wird über `rezeptur_bulkitem()` **bei Bedarf automatisch angelegt** („<Rezeptur> – Bulk", Kategorie `fertig`), und die Charge wird dem Auftrag zugeordnet (`charge.auftrag_id`). Nach dem **Freigeben** erkennt der Produktionsauftrag den Zukauf selbst (`produktion_ist_zukauf()`) und verkürzt den Weg. Angeboten werden offene Aufträge (`$zukaufAuftraege`), deren Produkt eine Rezeptur hat. Ab dem ersten Buchen existiert der Fertigware-Artikel und steht künftig auch unter Typ „Fertigware" im normalen Artikel-Feld.

**Zusammenhang:** Der freie Bestand aus den Chargen erscheint im Warenlager und auf dem Rohstoff (Reiter „Lager"). Später zieht die Produktion echte Chargen daraus (FEFO).

## Teil 4 (Schritt 6): klickbare „Letzte Chargen"
Jede Zeile der Liste „Letzte Chargen" ist klickbar und führt zur **Artikel-Detailseite (Warenlager-Detailseite)** – Route je Kategorie wie in `bestand_liste.php` (`betriebsmittel` → `?p=betriebsmittel`, `verpackung` → `?p=verpackung`, sonst `?p=rohstoff`). Die Abfrage liefert dafür `i.kategorie`; der Artikelname ist zusätzlich als Link gesetzt. Klicks auf den „freigeben"-Button/Links werden nicht abgefangen (`tr.we-rowlink` + JS).

## Mengen lesbar (kg/t) – Pulver-Bulk (2026-11-01)
Der Helfer `$mng($menge,$einheit)` (Chargen-Liste) zeigt Gewichts-/Volumen-Chargen jetzt lesbar: Charge-Einheit `g` → ab 1.000 **kg**, ab 1.000.000 **t**; `ml` → **L** (via `gewicht_lesbar()` in `core/schema.php`). So steht „650 kg" statt „650.000 g". Andere Einheiten (Stk/kg) unverändert. Das Eingabefeld „Menge" führt weiter die Einheit des Artikels (bei losem Pulver g) – eine kg-Eingabe hier ist ein möglicher späterer Ausbau.

**Quarantäne-Freigabe + Bestellbezug (St-07/St-12):** `?p=wareneingang&quarantaene=1[&item=ID]` zeigt ALLE Chargen in Quarantäne (nicht nur die letzten 25) mit „freigeben"; Ziel der Links aus Auftrag, Produktionsauftrag und Einkaufsliste. Mengen mit `zahl_lesen(..., true)` („60.000" = 60000). Neues Feld „Zur Bestellung": die gewählte Bestellung gilt danach als angekommen (`bestellung_als_angekommen`).

## Fertige Ware zum Auftrag (Handelsware / Zukauf „fertig", S-15)
„Fertigware zum Auftrag" bietet auch Handelsware-Aufträge (ohne Rezeptur) an. Bei Handelsware bzw. Zukauf-Art „fertig" wird in **Packungen** auf den verkaufsfertigen Artikel des Produkts gebucht (MHD = Lieferanten-MHD aus der Eingabe) und die Charge dem Auftrag zugeordnet; sonst wie bisher lose Bulkware auf den Rezeptur-Bulk-Artikel.

## Material komplett (Paket Mails)
Nach Buchung, Zukauf-Buchung und Quarantäne-Freigabe läuft `material_komplett_pruefen(true)` (nach der Antwort).

## QM-Gate (St-11)
Quarantäne-Ware freigeben (Status auf „frei") darf nur QM (Rolle `labor`) oder Admin – Dashboard `darf_qm_freigeben()`, Lager-App `lg_darf_qm_freigeben()`. Andere Rollen sehen „Freigabe durch QM" statt des Knopfs; ein direkter POST wird abgewiesen. Sperren oder zurück in Quarantäne darf weiterhin jeder.

## Quarantäne-Übersicht (Nacht 1b)
Die Übersicht (`?p=wareneingang&quarantaene=1`, Knopf „Nur Quarantäne (Anzahl)") zeigt alle Chargen in Quarantäne mit Bestand: Charge, Artikel, Menge, **Eingang**, MHD, **Lieferant / Herkunft** (bei Fertigware „Produktion PR-…"), Status und „freigeben" (nur QM/Admin), älteste zuerst. Kein Direkt-frei-Haken beim Wareneingang. Lager-App `?p=quarantaene` zeigt die Herkunft ebenso.

## Teillieferung (Nacht 1c)
`bestellung_position.geliefert_menge` (neu, additiv) zählt, was je Position schon eingebucht ist. Der Wareneingang mit Bestellbezug ruft `bestellung_lieferung_erfassen($bestellung_id, $charge_ids)` (Lager-App: `erp_bestellung_lieferung_erfassen`): die Chargen werden auf die Positionen verteilt (gleicher Artikel, sonst Freitext-Position; g/kg und ml/l werden umgerechnet). **Geschlossen** (angekommen_am, Status geliefert) wird die Bestellung erst, wenn alle Positionen zu mind. 98 % geliefert sind. Vorher bleibt sie offen, und „unterwegs" zählt nur den Rest (`bp_rest_sql()`, auch in `bulk_position_basis_sql()`), es entsteht also kein doppelter Bedarf. Der Knopf „liefern" (`bestellung_wareneingang`) setzt alle Positionen voll.

Freigeben (QM) einer Fertigware-Charge ruft danach `fertigware_qm_freigabe_nach()` auf (Bericht + CoA für den Kunden).

## Etikett prüfen vor dem Buchen (03.11.2026)
Wählt man ein Etikett (Typ „Verpackung / Etikett“; Kundenetiketten heißen „Etikett · Produkt · Kunde · vN“, Suche per Produktname), erscheint die Prüfbox. Darin:
- links das freigegebene Design des Kundenetiketts (`item.etikett_dokument_id`) als Vorschau (`/assets/etikett_vorschau.js`, PDF Seite 1 als Bild). Ohne eigenes Design greift wie bisher das Design des gewählten Auftrags.
- rechts groß die Maße sowie Material, Version und Kunde. Fehlende Werte kommen von der Maß-Vorlage.

Hat ein Kundenetikett kein freigegebenes Design, steht dort „Kein freigegebenes Etikett-Design hinterlegt“. Das Buchen selbst ist unverändert.

Freigeben aus der Quarantäne läuft über `charge_quarantaene_freigeben()` (04.11.2026). Die Quarantäne-Liste steht jetzt auch unter Lager → Freigaben.

Soll-Kette 2a (04.11.2026): Haken **„Problem (Beschädigung, Abweichung) – in Quarantäne buchen“** im Formular, gilt für Wareneingang und Zukauf. Ohne Haken ist die Ware frei.

Soll-Kette 2c (04.11.2026): Buchen auf einen losen Bulk-Artikel (kategorie fertig ohne Rezeptur) nur mit Auftrag – dann auf dessen Rezeptur-Bulk-Artikel; sonst Meldung `bulkfehler`.
