# lager/spec_bulkify.php – unsere Spezifikation / unser CoA

Routen:
- `?p=spec_bulkify&id=<item_id>` – Spezifikation des Rohstoffs
- `?p=coa_bulkify&id=<charge_id>` – Analysenzertifikat der Charge

Beides im bulkify-Layout aus `core/pdf_spec.php`. Bewusst **unser** Dokument: die Unterlagen der Vorlieferanten gehen nicht an den Kunden.

Die Analysenwerte einer Charge werden am Rohstoff im Panel **Analysenwerte je Charge** erfasst (Parameter, Spezifikation, Ergebnis, Methode) – übertragen aus dem CoA des Lieferanten. Ohne erfasste Werte erscheint im CoA ein entsprechender Hinweis statt einer leeren Tabelle.
