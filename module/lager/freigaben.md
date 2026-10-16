# lager/freigaben.php – Offene Kundenfreigaben

Route: `?p=freigaben` (Rollen production, einkauf, labor; Admin sowieso). Menü: Lager → **Freigaben**.

**Zweck:** Damit keine Kundendokumente unfreigegeben liegen bleiben. Zeigt zwei Listen:

- **Spezifikationen zur Freigabe** – Rohstoffe mit Spec-Inhalt (Wirkstoffe, Kennwerte, Grenzwerte oder Spec-PDF), deren bulkify-Spezifikation **noch nicht** für den Kunden freigegeben ist (`item.spec_freigegeben=0`). Link führt auf `?p=rohstoff&id=…&tab=spec` (dort Vorschau + Freigabe).
- **Analysenzertifikate (CoA) zur Freigabe** – Chargen mit Analysewerten (`charge_analyse`), deren CoA **noch nicht** freigegeben ist (`charge.coa_freigegeben=0`). Zeigt Rohstoff, Charge, MHD, Wareneingang (bzw. „CoA vorab", wenn noch keine Ware). Link „CoA ansehen" (`?p=coa_bulkify`) zum Prüfen + „Zur Freigabe" auf `?p=rohstoff&id=…&tab=lager`.

Oben zwei Zähler (offene Specs / offene CoAs). Einzeln freigegeben wird auf der Detailseite (damit man das Dokument vorher sieht). Zusätzlich gibt es je Abschnitt einen **„Alle freigeben (N)"**-Knopf (Sammel-Freigabe mit Bestätigungsdialog): `aktion=spec_alle_frei` bzw. `coa_alle_frei` setzen bei allen aktuell offenen Posten `spec_freigegeben`/`coa_freigegeben=1` + Datum/Von (gleiche Bedingung wie die Listen), schreiben einen Sammel-Protokolleintrag und zeigen die Anzahl zurück. Siehe `module/lager/rohstoff_detail.md` (Kundenfreigabe Spec/CoA) und `core/spec_ki.md`.
