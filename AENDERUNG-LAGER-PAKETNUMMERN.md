# Änderung: Paket-/Sendungsnummern beim Wareneingang

**Stand:** 05.10.2026 · betrifft die **Lager-App v4** (`app.bulkify.pro/lager/`, auch als
installierte Mini-App/PWA) · Bereich **Wareneingang**

## Worum geht es?

Wenn eine Lieferung in mehreren Paketen ankommt (z. B. 11 Kartons per UPS), klebt auf jedem
Karton eine eigene Sendungs-/Tracking-Nummer. Diese Nummern können jetzt beim Einbuchen
**mit erfasst** werden. So ist später nachvollziehbar, welche Pakete zu einer Lieferung
gehören – und wenn z. B. von 12 Paketen eines fehlt, sieht man sofort welches.

Die Nummern dienen nur der **Vollständigkeits-Kontrolle**. Sie werden **nicht** auf die
Etiketten gedruckt.

## „Ankommende Sendung wählen" zeigt jetzt den Inhalt

In der Liste der ankommenden Sendungen steht jetzt nicht mehr nur „Wellgreen · BE-2867",
sondern auch die **Produkt-/Rezepturnamen** der Lieferung (z. B. „Coenzym Q10 (Premium)").
So ist auf einen Blick klar, welche Lieferung das ist.

## So funktioniert es (Wareneingang)

1. Wareneingang wie gewohnt öffnen (z. B. aus „Ankommende Sendung wählen" die Lieferung
   wählen oder Lieferschein scannen).
2. In der Position gibt es jetzt unter **„Pakete"** den Bereich **„Paketnummern (je Karton
   scannen)"** mit einem Scan-Feld.
3. Einfach **Karton für Karton durchscannen**: Nummer scannen → mit Enter springt der Cursor
   automatisch ins nächste Feld → nächsten Karton scannen usw. Kein Komma, keine Zeilen von
   Hand. (Per „+ weiteres Paket" kann man auch manuell ein Feld ergänzen.)
4. Die **Anzahl der gescannten Pakete setzt automatisch das Feld „Pakete"** (11 Scans = 11
   Pakete). Das Feld „Pakete" muss also nicht mehr von Hand gesetzt werden.
5. Die Pakete gehören zu **einer** Charge mit **einem** Blinker – nur die Paketnummern sind
   verschieden.
6. Das Erfassen ist **optional** – leer lassen geht weiterhin, dann zählt wie bisher nur „Pakete".
7. Normal buchen. Fertig.

## Wo sehe ich die Nummern später?

In der **Charge-Detailansicht** unter **„Sendung / Paket"**:
- Die hinterlegten Nummern stehen dort – eine je Zeile – mit Anzahl in Klammern
  (z. B. „Sendung / Paket (11)").
- Über das Stift-Symbol lassen sie sich dort auch nachträglich korrigieren/ergänzen
  (mehrzeilig, eine Nummer je Zeile).
- Auch in der unteren Infotabelle als „Sendungsnummer(n)".

## Was sich NICHT geändert hat

- Der Ablauf beim Einbuchen bleibt gleich (Artikel, Menge, Charge, MHD, Blinker …).
- Etiketten unverändert (Karton X / Y), **ohne** Sendungsnummer.
- Buchung, Blinker, Quarantäne/Freigabe: unverändert.

---

### Technischer Hinweis (für die Entwicklung)

Geänderte Dateien (v4-Repo `bulkify-4.1`):
- `lager/module/bestand/wareneingang.php` – „Ankommende Sendung wählen" zeigt die Produkt-/
  Rezepturnamen; Paketnummern als einzelne Scan-Felder (Enter springt weiter), gesammelt in
  ein verstecktes `p_paketnummern[]`; Anzahl Scans steuert die Kartonanzahl; Speichern via
  `lg_tracking_set()`.
- `lager/core/schema.php` – `lg_charge_info.tracking` von `VARCHAR(255)` auf `TEXT`
  erweitert (viele Nummern); neue Hilfsfunktion `lg_tracking_liste()`; `lg_tracking_set()`
  nimmt jetzt mehrzeilige Eingaben.
- `lager/module/bestand/charge.php` – „Sendung / Paket" mehrzeilig anzeigen/bearbeiten + Anzahl.

Hinweis: Das alte Desktop-Einbuchprogramm (Tkinter, v3/`lager_api.php`) ist davon **nicht**
betroffen – diese Änderung ist ausschließlich in der v4-Lager-App.
