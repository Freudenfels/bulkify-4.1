# system/durchlaufzeiten.php – Durchlaufzeiten-Tracker

**Zweck:** Interne Auswertung (nur Admin), wie lange ein Kundenvorgang von der **Rezepturanfrage** bis zur **fertigen Produktion** braucht – je Meilenstein die Dauer und die Gesamtzeit, plus Durchschnitte. Route: `?p=durchlaufzeiten` (Menü System → Durchlaufzeiten).

## Meilensteine (m1…m7) und Quelle
Daten kommen aus `durchlaufzeiten_rows($kunde_id, $von, $bis)` in `core/schema.php`.
- **m1** Rezepturanfrage ein — `rezeptur_anfrage.angelegt`
- **m2** Vorschlag gesendet — `rezeptur.angelegt`
- **m3** Kunde nimmt Rezeptur an — `rezeptur.freigabe_am`
- **m4** Produkt angefragt — `portal_anfrage.angelegt` (typ='produkt'), früheste je Produkt der Rezeptur
- **m5** Angebot erstellt — `angebot.angelegt`
- **m6** Angebot bestätigt — `angebot.freigabe_am`
- **m7** Produktion fertig — `MAX(produktion_schritt.erledigt_at)` eines **erledigten** `produktionsauftrag`, früheste über die Produkte der Rezeptur

## Verkettung (ein Vorgang)
Anker ist die **Rezepturanfrage mit gesetzter `rezeptur_id`** (gesetzt beim Versenden des Vorschlags, `module/anfrage/detail.php`). Kette: `rezeptur_anfrage.rezeptur_id = rezeptur.id = produkt.rezeptur_id` → `portal_anfrage.produkt_id` / `angebot.produkt_id` → `auftrag.produkt_id` → `produktionsauftrag` → `produktion_schritt`. Je Folge-Meilenstein wird der **früheste** Treffer genommen („Zeit bis zum ersten X"). Nur Vorgänge, die als Rezepturanfrage begonnen haben, erscheinen.

## Anzeige
- **Segmente** (Dauer bis zum nächsten Meilenstein): Reaktion (1→2), Kundenentscheid (2→3), bis Produktanfrage (3→4), Angebotszeit (4→5), Kundenbestätigung (5→6), Produktion (6→7). **Gesamt** = 1→7.
- Oben **Ø-Kacheln** je Segment + Gesamt (zählt nur Vorgänge, bei denen der Schritt vorliegt).
- Tabelle: ein Vorgang je Zeile (Rezeptur verlinkt), Start-Datum, Segment-Dauern, Gesamt, Stand (`fertig` bzw. „läuft seit …"). Fehlt ein Schritt (oder nicht sauber datierbar / negative Differenz) → „–".
- Filter: Kunde, Zeitraum (von/bis auf m1).
- Dauer-Format lokal im Modul (T / Std / Min).

## Hinweise
- Zeiten gemischt: `*.angelegt` sind `DEFAULT CURRENT_TIMESTAMP` (Server-Zeit), `freigabe_am`/`erledigt_at` sind UTC (`gmdate`/`UTC_TIMESTAMP`). Solange die DB-Serverzeit UTC ist (Projektkonvention), sind die Differenzen konsistent.
- Verwandt, aber nur Teilstrecke: `erp_durchlaufzeit_schnitt()` (`produktion/core/erp.php`) misst nur Auftrag → fertig. Dieser Tracker deckt die ganze Reise ab Rezepturanfrage ab.
