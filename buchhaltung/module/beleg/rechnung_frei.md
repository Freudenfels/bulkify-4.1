# beleg/rechnung_frei.php – Rechnung frei & KI-gestützt erstellen

**Zweck:** Eine Rechnung **ohne Auftrag** erstellen – im Rechnungen-Menü über den Knopf
**„+ Rechnung erstellen"**. Man beschreibt die Rechnung in eigenen Worten, die KI baut daraus
Kunde, Positionen, Zahlungsziel usw.; alles bleibt editierbar, erst „Rechnung erstellen" legt den
Beleg an. Für Fälle, in denen kein Auftrag zugrunde liegt (Dienstleistung, Nachberechnung,
Sonderposten). Rechnungen **aus einem Auftrag** laufen weiter über [rechnung_neu](rechnung_neu.md).

## Ablauf
1. **Beschreiben (KI):** Freitext-Feld „Rechnung in eigenen Worten beschreiben" (z. B. *„Rechnung an
   Pure Health GmbH: 500 Dosen Vitamin D3 à 4,20 €, Zahlungsziel 14 Tage, Leistung September 2026"*),
   optional zusätzlich eine **Datei** (PDF/Bild eines Angebots/Lieferscheins). Knopf **„Rechnung bauen (KI)"**
   (`aktion=ki_bauen`) schickt alles an Claude (`core/ki.php`, `ki_json()` bzw. `ki_datei_frage()`).
   Die KI bekommt die **Kundenliste** als Kontext und gibt JSON zurück: `kunde`, `datum` (=**Rechnungsdatum**),
   `zahlungsziel_tage`, `leistung_datum`, `text`, `positionen[]` (artikelnr, bezeichnung, beschreibung, menge, einheit,
   **einzelpreis = Netto je Einheit**, ust). Damit wird das Formular unten vorbefüllt; der Kunde wird
   über Namensabgleich (exakt, sonst „enthält") vorausgewählt. **Ein genanntes Datum gilt als Rechnungsdatum**
   (nicht als Leistungsdatum) – letzteres nur, wenn der Nutzer ausdrücklich „Leistung/Lieferung am …" sagt.
   Ohne KI-Schlüssel entfällt nur dieser Schritt – das Formular lässt sich direkt manuell ausfüllen.
2. **Prüfen & erstellen:** Kopf (Kunde · Rechnungsdatum · Leistungs-/Lieferdatum · Zahlungsziel in
   Tagen → Fälligkeit · Rechnungstext) und **Positions-Tabelle** (Artikel-Nr., Bezeichnung/Beschreibung,
   Menge, Einheit, Einzelpreis, USt %, Zeile netto). Eine Live-Summe rechnet **Netto/USt/Brutto** schon
   beim Tippen (JS). Positionen lassen sich hinzufügen/entfernen. Checkbox **„im Kundenportal freigeben"**
   (= `kunde_sichtbar=1`, sonst erst intern). **„Rechnung erstellen"** (`aktion=rechnung_save`) →
   `rechnung_frei_erstellen()`.

## Backend (`core/schema.php`)
`rechnung_frei_erstellen(array $positionen, array $opt): ?int` – legt einen Beleg `typ='rechnung'`
(ohne `auftrag_id`) an. Summen aus den Positionen (`beleg_summen_aus_positionen()`), USt-Satz = erster
Positions-Satz > 0. `$opt`: `kunde_id`, `datum` (Standard heute), `zahlungsziel_tage` (→ `faellig`),
`leistung_datum`, `text`, `freigeben` (bool → `kunde_sichtbar`), `ersteller` (Name im Verlauf),
`bearbeiter_id` (Benutzer-ID → `beleg.bearbeiter_id`, erscheint als Bearbeiter auf der PDF).
Nummer aus `naechste_nummer('RE')`. Schreibt `beleg_position`-Zeilen (Preise **positiv**, in Cent),
einen Status-Log-Eintrag (`beleg_status_log_add`) und eine Kunden-Aktivität. Gibt die Beleg-ID zurück,
oder `null` wenn keine Position mit Betrag vorliegt.

Nach dem Erstellen landet man auf der Rechnung ([detail.php](detail.md)); dort laufen Freigeben/
Zurückziehen, Zahlungen und PDF wie bei jeder anderen Rechnung.

## Route & Rechte
`?p=rechnung_frei` → `public/index.php`; Rolle **finance** (`core/auth.php`). Verlinkt aus der
Rechnungen-Liste ([rechnungen_liste.md](rechnungen_liste.md)).

## Dienstleistungs-Rechnung (DR-) + Leistungs-Auswahl (Stand 2026-10-23)
- **DL-Modus:** Aufruf mit `?p=rechnung_frei&art=dienstleistung` (Knopf „+ Dienstleistungs-Rechnung erstellen" in der DL-Rechnungsliste `?p=dl_rechnungen`). `$istDL` wird über ein verstecktes Feld `art` durch beide POSTs (ki_bauen, rechnung_save) mitgeführt. `rechnung_frei_erstellen()` bekommt `['dienstleistung'=>true]` → **Nummernkreis DR-** + `beleg.kategorie='dienstleistung'` (sonst RE-/produkt). So landet eine frei erstellte Fulfillment-/DL-Abrechnung korrekt unter „Dienstleistungs-Rechnungen". Vorher zog der Knopf immer `rechnung_frei` ohne Kategorie → es entstand eine normale Rechnung.
- **Leistung aus Katalog wählen:** Neben „+ Position (frei)" gibt es eine Auswahl **„Leistung aus dem Katalog"** (aktive Dienstleistungen via `bu_dienstleistungen_katalog()` in `buchhaltung/core/erp.php`, nur lesend). „+ als Position" legt eine vorausgefüllte Positionszeile an (Artikelnummer aus `dienstleistung.nummer`, Bezeichnung, Beschreibung, Einheit, VK netto aus `vk_cent`, USt aus `mwst_satz`). So muss man nicht alles frei tippen.
