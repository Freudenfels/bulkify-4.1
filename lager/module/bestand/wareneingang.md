# bestand/wareneingang.php – Vollwertiger Wareneingang (Route `we`)

Ein Bereich für alles, was reinkommt. Ablauf:
1. **Ziel**: Lager 1 (eigener Bestand) oder Lager 2 (Kundenware → Kunde). Lieferant optional.
2. **Lieferschein scannen** (optional): Webcam-Foto(s) oder Datei/PDF → `POST aktion=scan` →
   `lg_lieferschein_lesen()` ([../../core/ki.md](../../core/ki.md)) → KI liest Lieferant + Positionen.
   Positionen werden per `erp_position_zuordnen()` einem bestehenden Artikel zugeordnet (exakter
   Name = sichere Zuordnung, sonst Vorschlag + Warenart-Vermutung). Antwort als JSON, Tabelle füllt sich.
3. **Positionen prüfen**: je Zeile Artikel (bestehend/neu), Warenart, Menge, Einheit, Charge, MHD,
   **Blinker (immer Pflicht)**, Pakete, **Freigegeben-Haken**. Pflichtfelder je Warenart aus
   `erp_warenart_regeln()`: Rohstoff/Fertig/Kapsel → MHD+Charge Pflicht; Verpackung/Verbrauch → frei.

   **Fertige Kapseln (Warenart „Fertigware / Bulk"):** Dann erscheint zusätzlich ein **Rezeptur-Picker**
   (Textsuche, `erp_rezeptur_liste()`, nur Rezepturen ≠ Entwurf). Beim Buchen wird die Position aufs
   **kanonische Bulk-Item der Rezeptur** gebucht (`item.rezeptur_id` + `kategorie='fertig'`), aufgelöst per
   `erp_rezeptur_bulkitem()` (read-only). Gibt es noch kein Bulk-Item, wird die Zeile **nicht** gebucht,
   sondern gemeldet („bitte erst im Dashboard anlegen") – die Anlage bleibt kanonisch im Dashboard
   (`rezeptur_bulkitem()`); siehe `ANTWORT-DASHBOARD-REZEPTUR-BULKITEM.md`, Variante A.
4. **Alle buchen** (`POST aktion=buchen`): je Position eine Charge (`erp_wareneingang_buchen` für L1,
   `erp_wareneingang_buchen_fremd` für L2), Blinker anhängen (grün), Pakete + Bewegungslog. Unbekannte
   Artikel werden via `erp_item_anlegen` angelegt. Ungültige Zeilen werden übersprungen und gemeldet
   (Teil-Buchung, kein Totalverlust). Danach Erfolgspanel mit **Sammel-Etikett** (`?p=etikett&ids=…`).

Ersetzt im Menü die alten getrennten Seiten `eingang.php` (L1) und `l2_eingang.php` (L2); deren Routen
bleiben für Altlinks (z. B. Erwartete Lieferungen → Einbuchen) bestehen.

**Rezepturnummer-Aufkleber scannen (Spec 5.2):** fünfte Start-Kachel „Rezepturnummer-Aufkleber". Ein
Feld nimmt den gescannten R-Code auf (Enter) → `POST aktion=rsticker` → `erp_rezeptur_per_nummer()` →
die erkannte Rezeptur wird als **Fertigware/Bulk-Position** eingefügt (Rezeptur + koppelbares Bulk-Item
vorausgewählt), Fokus springt auf den Blinker. Kein Tippen von Nummern = keine Fehlzuordnung durch Tippfehler.

**Kiste (optional):** Oben lässt sich eine **Kiste** (`kiste_id`) wählen (aus `kiste_alle()`, Name oder
Barcode). Ist eine Kiste gewählt, wird jede gebuchte Charge per `kiste_charge_zuordnen()` hineingelegt und der
**Blinker je Position ist optional** (die Kiste blinkt beim Finden). Ohne Kiste bleibt der Blinker Pflicht.
Hat die Kiste bereits einen **Blinker** (`kiste_alle().blinker`), wird dessen Code beim Wählen der Kiste
**automatisch in die Blinker-Felder eingetragen** (auch in neu hinzugefügte Zeilen); manuell getippte Blinker
bleiben erhalten, beim Entfernen der Kiste wird nur der Kisten-Code wieder gelöscht.

**Lieferschein-Scan** erfasst zusätzlich Lieferanten-**Art.-Nr.** je Position und die **Auftragsnummer**
(beides in die Charge-Notiz), legt den **Lieferanten** an/verknüpft ihn und erfindet **keine Charge** mehr. Das Lieferant-Feld ist eine
Eingabe mit Vorschlagsliste (datalist): bestehende wählen ODER **neuen Namen tippen** → wird beim Buchen über
`erp_lieferant_finden_oder_anlegen()` gefunden (exakt/fuzzy) oder **neu angelegt** (mit Lieferantennummer).
**Sendungs-/Paketnummer** (Tracking) wird je Charge gespeichert (`lg_tracking_set`).

**Paketnummern je Position** (`p_paketnummern[]`): Die **Anzahl der Scan-Felder folgt dem Feld „Pakete"** –
tippt man z. B. 11, erscheinen sofort 11 nummerierte Felder (Paket 1…11), kein „+ weiteres Paket"-Klicken,
kein Zählen. Vorhandene Scans bleiben beim Ändern der Anzahl erhalten; Scan+Enter springt ins nächste Feld,
und ein Scan im letzten Feld lässt die Paketzahl mitwachsen. Die gefüllten Nummern landen zeilenweise im
versteckten `p_paketnummern[]`; beim Buchen gilt `pakete = max(Pakete-Zahl, Anzahl Nummern)` und die Nummern
werden je Charge als Tracking gespeichert.

**Status je Position** über den **Freigegeben-Haken** (`p_frei[]`): angehakt (Standard) = `frei`,
nicht angehakt = `quarantaene`. So lassen sich in einer Lieferung Produkte mischen, ohne getrennt
einzubuchen. Wird an `erp_wareneingang_buchen(..., $status)` übergeben. „Gesperrt" gibt es hier nicht –
der volle Status (inkl. Gesperrt) ist nachträglich auf der Charge-Detailseite änderbar
(`?p=charge`, Aktion `status` → `erp_charge_status_setzen()`).
