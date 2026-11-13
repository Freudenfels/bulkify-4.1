# lager/freigaben.php – Offene Kundenfreigaben

Route: `?p=freigaben` (Rollen production, einkauf, labor; Admin sowieso). Menü: Lager → **Freigaben**.

**Zweck:** Damit keine Kundendokumente unfreigegeben liegen bleiben. **Erst-Uploads geben automatisch frei** (`core/spec_ki.md`), hier stehen nur noch Fälle, die eine Freigabe-**Entscheidung** brauchen. Zwei Listen:

- **Spezifikationen zur Freigabe** – Rohstoffe, die entweder **geändert** wurden (`item.spec_aenderung_offen=1` – der Kunde sieht noch die alte, freigegebene Version, bis die Änderung freigegeben wird) **oder** als Alt-Rückstand **noch nie** freigegeben wurden (`spec_freigegeben=0` mit Spec-Inhalt). Status-Spalte: „geändert" (warn) / „neu" (info). Link auf `?p=rohstoff&id=…&tab=spec` (dort Vorschau, **Diff** alt→neu und Freigabe).
- **Analysenzertifikate (CoA) zur Freigabe** – Chargen mit Analysewerten (`charge_analyse`), deren CoA **noch nicht** freigegeben ist (`charge.coa_freigegeben=0`). Zeigt Rohstoff, Charge, MHD, Wareneingang (bzw. „CoA vorab"). Link „CoA ansehen" (`?p=coa_bulkify`) + „Zur Freigabe" auf `?p=rohstoff&id=…&tab=lager`.

Oben zwei Zähler. Einzeln freigegeben wird auf der Detailseite (Dokument + Diff vorher sichtbar). Je Abschnitt ein **„Alle freigeben (N)"**-Knopf: `aktion=spec_alle_frei` ruft je betroffenem Rohstoff **`spec_snapshot_setzen()`** auf (friert den aktuellen Stand als neue freigegebene Version ein, Flag weg); `coa_alle_frei` setzt `coa_freigegeben=1` + Datum/Von. Sammel-Protokolleintrag, Anzahl zurück. Siehe `module/lager/rohstoff_detail.md` (Kundenfreigabe Spec/CoA mit Diff) und `core/spec_ki.md`.

## Ware in Quarantäne (04.11.2026)
Oberster Block: alle Chargen in Quarantäne (Lager 1, mit Bestand) mit Artikel, Warenart, Charge, Menge, MHD, Eingang, Herkunft (Lieferant oder Produktion PR-…) und Auftrag. Knopf **freigeben** je Zeile und **Alle freigeben** (Rückfrage), nur QM/Admin (`darf_qm_freigeben`). Logik wie im Wareneingang (`charge_quarantaene_freigeben`). Bei eigener Fertigware gibt die Freigabe auch den Produktionsbericht für den Kunden frei.

## Sofortfix 13.11.2026: Unterlagen-Sperre wird Quarantäne-Sperre
Regel Nico: Unterlagen fehlen = Ware bleibt in Quarantäne. Bestellen und Einbuchen gehen immer. Artikel ohne Unterlagenpflicht: „kein Dokument nötig“ (Details `core/lieferant_unterlagen.md`).

Ware in Quarantäne: je Charge der Grund „Quarantäne: CoA/Spec des Lieferanten fehlt“ bzw. „Unterlage nachgereicht – bitte prüfen und freigeben“. Gesperrte Chargen haben statt „freigeben“ den Knopf „Unterlage hochladen“ (Pop-up) und für Admin „trotzdem freigeben“ mit Pflicht-Grund (aktion `q_frei_admin`). „Alle freigeben“ überspringt gesperrte Chargen und meldet sie.
