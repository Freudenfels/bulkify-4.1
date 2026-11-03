# core/etikett_pdf.php – Karton-Etikett als PDF (zentral)

`lg_etikett_pdf(array $ids, string $format='klein', int $override=0): ?string` baut die Etiketten
und gibt die PDF-Bytes zurück (oder null). Formate: `klein` 100×70 quer, `gross` 100×150 hoch
(`lg_karton_etikett` / `lg_karton_etikett_hoch`). Inhalt: Name, **Lieferant = NUR die Lieferantennummer**
(`lg_lieferant_txt()`, nie der Name – Regel Nico; fehlt die Nummer, bleibt das Feld „–"), Eingangsdatum, Charge,
MHD, Menge, QR zur Charge, „Karton X / N" je Paket. Bei **Aufteilen** wird die Menge je Karton verteilt;
**Stück/Tabletten/Kapseln ganzzahlig** (floor je Karton, letzter bekommt den Rest – keine Nachkommastellen),
Gewicht/Volumen auf 3 Nachkommastellen. Hinweis: `etikett.php` druckt aktuell nur `gross` (100×150).

Genutzt von [../module/bestand/etikett.md](../module/bestand/etikett.md) (Anzeige/Download) und der
Druck-Brücke `public/lager/bruecke.php` (lautloser Druck). Braucht `erp_charge_voll`, `lg_pakete`,
`menge_txt`, `qr_matrix`, MiniPDF.

**Gebinde-Aufkleber (Spec 5.6):** `lg_gebinde_etikett_pdf(int $charge_id, string $format='gross'): ?string`
baut je **Gebinde** (aus `lg_gebinde`) einen eigenen Aufkleber mit **eigenem QR** (führt auf
`?p=gebinde&nr=GB-…`) und der **eigenen Nummer**, dazu Produktname, Lieferanten-Nummer und
Wareneingangsdatum (`lg_gebinde_label` / `lg_gebinde_label_hoch`). Ausgabe über `?p=gebinde_etikett`.

## Teil 4: eigener QR je Gebinde
`lg_etikett_pdf()` prüft je Charge, ob Gebinde-Datensätze existieren (`erp_charge_gebinde_liste`). Wenn ja: ein Etikett je Gebinde mit EIGENEM QR auf `?p=gebinde&bg=<charge_gebinde.id>` (genau dieses Gebinde, Restmenge/Ort), „Karton X / N" bleibt, Menge = Gebinde-Restmenge. Wenn nein (Alt-Chargen): unverändert ein QR auf die Charge (`?p=charge&id=`).

## Inventarnummer auf dem Rollen-Aufkleber (Paket Etiketten, Teilfix 5)
Eine Etikettenrolle ist ein Gebinde mit 8-stelliger Inventarnummer (`charge_gebinde.inventar_nr`). Bisher stand nur "Karton X / N ... Menge" auf dem Aufkleber; automatisch vergebene Nummern (9000000...) gab es nur im System und konnten nie aufgeklebt oder gescannt werden. Jetzt druckt `lg_etikett_pdf()` bei jeder Rolle die **Inventarnummer gross + als scanbaren Code-128-Barcode** (`lg_inventar_block()`, `lg_barcode128()`, `lg_code128b_module()`; Teilversion B, Pruefsumme, 10 Module Ruhezone). Hochformat 100x150: QR kleiner (36 mm) links, rechts daneben der Inventar-Block, darunter wie bisher Name/Felder. Querformat 100x70: Inventar-Block unter dem QR. Gebinde ohne Inventarnummer (normale Kartons) bleiben unveraendert. Der Barcode wurde mit einem unabhaengigen Decoder (zxing-cpp) gegen 2.507 Nummern geprueft (alle 103 Pruefsummenwerte); der QR fuehrt weiter auf das Gebinde. Die Nummer wird wie der aufgeklebte Maniso-Inventaraufkleber vom Scanner gelesen (`?p=finden`/`scan.php`).
