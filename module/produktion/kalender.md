# produktion/kalender.php – Produktions-Kalender (Baustein 2)

**Zweck:** Planen, **wann** welcher Produktionsauftrag produziert wird. Route `?p=kalender` (Rollen production/labor/fulfillment, admin). Im Menü unter „Produktion".

**Monatsraster:** 7-Spalten-Grid (Mo–So), Navigation `?monat=YYYY-MM` (Vor/Zurück). Heute ist grün umrandet. Je Tag die eingeplanten Produktionsaufträge (Nummer · Produkt, Prio-Punkt farbig, Link zum Auftrag). Datenbasis: `produktionsauftrag.geplant_am` (DATE).

**Einplanen:** Abschnitt „Noch nicht eingeplant" listet alle offenen/laufenden PA ohne Datum – mit Prio, **Produktionsbereitschaft** (Baustein 3) und einem Datumsfeld je Zeile (`aktion=plan`). Ausplanen via `aktion=unplan`. Das Datum ist auch auf der PA-Detailseite als Kachel „Geplant am" setzbar (`aktion=geplant`).

**Cockpit-Anbindung:** Das Werk-Cockpit zeigt ein Panel **„Heute eingeplant"** (alle PA mit `geplant_am = heute`, nicht erledigt) mit Prio + Bereitschaft – der Tagesplan für den Mitarbeiter.
