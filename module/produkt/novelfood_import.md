# produkt/novelfood_import.php – Novel-Food-Katalog aktualisieren (Upload + Diff)

Route `?p=novelfood_import` (Rollen production/labor, Admin). Lädt die aktuelle Novel-Food-Liste hoch,
**vergleicht sie mit der DB** und zeigt VOR dem Übernehmen, was neu ist und was sich geändert hat
(besonders der **Status**), dann Übernehmen.

**Format:** JSON (`{"eintraege":[…]}` oder reines Array) ODER CSV mit Kopfzeile. Felder: code, name, trivial,
syn, status, status_code, teil, beschreibung(_de). CSV-Kopf wird über Synonyme gemappt
(`novelfood_aus_datei` in core/novelfood.php). Abgleich je Eintrag über **code** (sonst Name).

**Ablauf (2 Schritte):** Upload → Datei in `data/uploads/nf_import_<token>.dat` gespeichert, `novelfood_diff()`
zeigt Vorschau (neu / geändert / davon Status-Änderung / unverändert / ungültig; Listen der neuen + geänderten
Einträge mit Status alt→neu). „Jetzt übernehmen" (mit Token) liest die Datei erneut, `novelfood_uebernehmen()`
(Upsert über code/Name), Temp-Datei wird gelöscht, Aktivität protokolliert.

Core-Logik in `core/novelfood.php` (geteilt): `novelfood_aus_datei` (JSON/CSV), `novelfood_normalisieren`,
`novelfood_finden`, `novelfood_diff`, `novelfood_uebernehmen`. Das alte CLI `tools/novelfood_import.php` bleibt
für den Erstimport. Einstieg: Button „Katalog aktualisieren" auf der Novel-Food-Suche.
