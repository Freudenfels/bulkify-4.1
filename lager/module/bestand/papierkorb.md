# bestand/papierkorb.php – Mülleimer (`?p=papierkorb`)

Im Lager „gelöschte" Chargen. **Wichtig:** Löschen entfernt die Charge NICHT aus dem Dashboard –
sie wird nur lager-seitig ausgeblendet (Eintrag in `lg_papierkorb`). So geht nichts kaputt und man
kann **30 Tage** wiederherstellen (`restore` → `lg_papierkorb_raus`). Ältere Einträge bleiben
gelistet, sind aber nicht mehr wiederherstellbar (nur noch Info).

Gelöscht wird auf der Charge-Seite ([charge.md](charge.md), Aktion `loeschen` → `lg_papierkorb_rein`).
Alle Bestands-/Such-/Kennzahl-Listen blenden Papierkorb-Chargen über `erp_pk_wo()` aus.
