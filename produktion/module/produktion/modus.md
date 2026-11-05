# produktion/module/produktion/modus.php
Produktionsmodus-Auswahl (`?p=modus`): listet nur **startbare** Aufträge – laufende (`status=laufend`) und **produzierbare** (`erp_pa_bereitschaft`='bereit'); wartende/abgeschlossene erscheinen nicht. Sortiert nach geplantem Datum (dann Prio). Klick auf eine Zeile öffnet den geführten Produktionsmodus (`?p=run`). Spalte „Wann dran" = `geplant_am` (Planung aus dem Dashboard).

## Teilmengen (05.11.2026)
Zusätzlich zu den produzierbaren und laufenden Aufträgen stehen offene Aufträge in der Liste, für die jetzt eine **Teilmenge** machbar ist (`erp_teilmenge_machbar`: komplett oder bis vor dem Etikettieren > 0). Sie haben das gelbe Kennzeichen „Teilmenge machbar: N Pkg“ (bzw. „… bis vor Etikett“). Beispiel: 9.000 von 450.000 Q10-Kapseln sind da, also 100 Pkg jetzt, der Rest später. Der Klick öffnet den Produktionsmodus mit der Box „Teilmenge produzieren“.
