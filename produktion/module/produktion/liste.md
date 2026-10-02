# produktion/module/produktion/liste.php
Produktionsaufträge in drei Reitern (`?p=liste&tab=…`):
- **alle** – alle Aufträge mit Spalten **Auftragseingang** (Datum, `auftrag.angelegt`) und **Produzierbar?** (Material komplett da?).
- **laufend** – nur laufende Produktionen (`status=laufend`).
- **fertig** – abgeschlossene Produktionen (`status=erledigt`), zuletzt fertige zuerst.

Spalten: Nr. (+ Auftrag), Produkt (+ Form), Kunde, Menge, Auftragseingang, Produzierbar? (`bereit_badge` aus `erp_pa_bereitschaft()`), Fortschritt, Status (`pa_badge`). Alles lesend über `erp_produktionsauftraege($status)`.
