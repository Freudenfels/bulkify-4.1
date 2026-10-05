# produktion/module/produktion/kalender.php
Produktionskalender (`?p=kalender&m=YYYY-MM`): Monatsraster mit terminierten Produktionsaufträgen (nach `geplant_am`), Vormonat/Heute/Folgemonat-Navigation; darunter die aktiven Aufträge **ohne Termin**. Nur lesend über `erp_produktionsauftraege('alle')`. Termin (`geplant_am`) wird im Dashboard am Auftrag gesetzt.
