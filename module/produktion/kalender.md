# produktion/kalender.php – Produktions-Kalender (Baustein 2)

**Zweck:** Planen, **wann** welcher Produktionsauftrag produziert wird. Route `?p=kalender` (Rollen production/labor/fulfillment, admin). Im Menü unter „Produktion".

**Monatsraster:** 7-Spalten-Grid (Mo–So), Navigation `?monat=YYYY-MM` (Vor/Zurück). Heute ist grün umrandet. Je Tag die eingeplanten Produktionsaufträge (Nummer · Produkt, Prio-Punkt farbig, Link zum Auftrag). Datenbasis: `produktionsauftrag.geplant_am` (DATE).

**Einplanen:** Abschnitt „Noch nicht eingeplant" listet alle offenen/laufenden PA ohne Datum – mit Prio, **Produktionsbereitschaft** (Baustein 3) und einem Datumsfeld je Zeile (`aktion=plan`). Ausplanen via `aktion=unplan`. Das Datum ist auch auf der PA-Detailseite als Kachel „Geplant am" setzbar (`aktion=geplant`).

**Cockpit-Anbindung:** Das Werk-Cockpit zeigt ein Panel **„Heute eingeplant"** (alle PA mit `geplant_am = heute`, nicht erledigt) mit Prio + Bereitschaft – der Tagesplan für den Mitarbeiter.

## Drag & Drop + Kapazität + Mitarbeiter (2026-10-05)
Aufträge lassen sich per **Drag & Drop** auf einen Tag ziehen (= `geplant_am` setzen) bzw. auf „Noch nicht eingeplant" (= Termin entfernen). DnD submittet ein verstecktes Formular (voller Reload, kein AJAX) über die bestehenden Aktionen `plan`/`unplan`.

**Kapazität/Tag:** einstellbar (app_meta `prod_kap_tag`, Default 3). Tage mit mehr Aufträgen als Kapazität werden rot markiert; Anzeige „belegt/Kapazität". Mehrere Aufträge am selben Tag = parallel.

**Mitarbeiter:** Spalte `produktionsauftrag.mitarbeiter_id` (FK benutzer). Je Auftrag (im Kalender-Chip und in der ungeplant-Liste) ein Mitarbeiter-Dropdown (aktive `benutzer`), Aktion `mitarbeiter`. Schreibt nur `geplant_am` + `mitarbeiter_id`; die Produktion liest.
