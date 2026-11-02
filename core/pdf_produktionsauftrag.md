# core/pdf_produktionsauftrag.php – Produktions-Laufzettel (PDF)

`produktionsauftrag_pdf_bauen($pa_id)` baut den internen Laufzettel (eigenes MiniPDF-Layout, KEIN
Beleg mit Preis/USt). Inhalt: PR-Nummer, Produktionsart, Status · prominente **Charge + MHD**-Box ·
Produkt, Kunde, Darreichung + Größe (`produktion_groesse_label`), Menge (Packungen · Stück je Packung ·
Gesamt), Verpackung, Etikett, geplant am · **Rezeptur**-Tabelle (Zutat · mg je Einheit · Gesamt
benötigt aus `produktion_materialbedarf`) · **Schritt-Checkliste** (`produktion_schritt`, Kästchen,
erledigte mit x). Charge/MHD wie Detailseite (gebuchte Charge, sonst `charge_naechste_nr`/`mhd_standard`).
`produktionsauftrag_pdf_ausliefern()` gibt inline aus.

Route `?p=produktionsauftrag_pdf&id=<ID>` (`module/produktion/pdf.php`), Knopf „Laufzettel drucken" im
PA-Kopf. Rollen: production/labor/fulfillment.

## Pulver (St-13)
Bei Pulver/Granulat steht „300 g je Packung" (über `inhalt_je_packung_txt()`) statt „300 Stück je Packung". Der Laufzettel zeigt zusätzlich „gesamt 600 kg" statt „600.000 Stk", die Spalte heißt „mg je Portion" und die Rückfall-Gesamtmengen der Zutaten rechnen mit Portionen (Füllgewicht / mg je Portion).
