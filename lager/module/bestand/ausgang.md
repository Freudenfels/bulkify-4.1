# bestand/ausgang.php – Warenausgang (Warenlager-Manager)

**Was geht raus.** Charge suchen (Rohstoff/Artikelnummer/Charge, nur eigener nicht-leerer Bestand über `erp_bestand`), je Zeile eine Menge eintragen und **Abbuchen**. Route `?p=ausgang`.

**Ablauf:** `erp_charge_entnehmen($charge_id,$menge)` bucht von `charge.menge_verfuegbar` ab (Guard: nicht mehr als verfügbar; Fremdlager-Chargen sind tabu). Wird die Charge **leer**, setzt sie Status `leer`; dann wird der **Blinker automatisch gelöst** (`leiste_aus` + `leiste_loesen`) und die Charge **aus der Kiste genommen** (`kiste_charge_entfernen`). Jede Entnahme landet als Bewegung (`lg_bewegung_log`, Typ `aus`) mit optionalem Grund.

**Naht:** Das Abbuchen (Dashboard-`charge`) steckt in `erp_charge_entnehmen()` in `core/erp.php`; `erp_bedarf_bump()` hält den Bedarfs-Cache aktuell.

Unten: „Zuletzt bewegt". Vollständige Historie unter `?p=bewegungen`.
