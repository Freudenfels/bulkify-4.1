# module/produkt/novelfood_verlauf.php – Novel-Food Aktualisierungs-Verlauf

Zeigt je **Abgleichslauf** (Button oder Monatsroutine), was sich geändert hat – gruppiert pro Lauf,
neuester oben. Route `novelfood_verlauf` (Rollen: production, sales, labor; Admin sowieso).

## Was man sieht
- Pro Lauf: Zeitpunkt (Europe/Berlin via `fmt_zeit`), Auslöser (Manuell/Automatisch/Zeitplan), Zähler
  (im Katalog · übersetzt · Dauer) und vier Kacheln (Neu · Geändert · davon Statuswechsel · Entfernt).
- Darunter, nach Relevanz: **Statuswechsel zuerst** (rechtlich am wichtigsten, Status alt → neu),
  dann „Neu im Katalog", „Sonstige Änderungen ohne Statuswechsel", „Aus dem Katalog entfernt".
- Fehlgeschlagene Läufe werden rot mit Fehlermeldung gezeigt.

## Datenquelle
`novelfood_lauf` (Kopf je Lauf) + `novelfood_change` (eine Zeile je Position: art = neu|geaendert|entfernt,
mit `status_wechsel`, `status_alt`/`status_neu`, `felder`). Befüllt von `novelfood_sync_lauf()`.
Rein lesend – legt nichts an.
