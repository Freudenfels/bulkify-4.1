# Aufgabe (Dashboard-Chat): Laborproben-Status am Auftrag + Kundenportal zeigen

> Für den **Dashboard-Chat** (`core/`, `module/`, `public/`). Vorbereitet vom Produktions-Chat.

## Kontext
Die Produktion erfasst QS/Labor je Auftrag (`/produktion/?p=qs`): Rückstellmuster, Laborprobe und den
Button **„An Labor versendet"**. Beim Versand schreibt die Produktion bereits – **vorwärtskompatibel** –
in den Auftrag, sobald die Spalte existiert:
```
UPDATE auftrag SET labor_versendet_am = CURDATE() WHERE id=? AND labor_versendet_am IS NULL
```
Es fehlt nur die **Spalte** und die **Anzeige** im Dashboard/Portal.

## Aufgabe
1. **Spalte anlegen** (additiv): `ensure_column('auftrag', 'labor_versendet_am', "DATE NULL");`
2. **Labortest-Status erweitern:** In `auftrag_labortest_status()` (core/schema.php) den Zwischenstand berücksichtigen:
   - Liegt ein **freigegebener Laborbericht** vor (dokument `typ='analyse'`, `kunde_sichtbar=1`) → „abgeschlossen" (wie bisher).
   - Sonst, wenn `auftrag.labor_versendet_am` gesetzt → **„Probe beim Labor (versendet am …)"**.
   - Sonst wie bisher („läuft" / Probe in Vorbereitung).
3. **Anzeige:** Dieser Zwischenstand soll im **Kundenportal-Verlauf** und in der **Auftrag-Detailseite** (module/auftrag/detail.php, Labortest-Panel) erscheinen – nur für Kunden mit `kunden.labortest_extern=1` (bestehende Logik).

## Hinweise
- Nur **additive** Schemaänderung, keine bestehenden Werte anfassen.
- Die Produktion setzt das Datum selbst (über die Naht); das Dashboard muss es **nur lesen/anzeigen** (nicht selbst schreiben).
- Co-located `.md` pflegen, Rebase-Ampel beim Push.
