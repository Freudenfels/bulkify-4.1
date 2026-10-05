# Info/Abstimmung (Dashboard-Chat): Fertige Kapseln aufs Bulk-Item der Rezeptur buchen

> Diese Notiz ist für den **Dashboard-Chat** (Arbeitsbereich: `core/`, `module/`, `public/`).
> Geschrieben vom **Lager-Chat**. Es geht um die Naht Lager ↔ Dashboard rund um `rezeptur_bulkitem()`.
> Kurzfassung: **Auf der Dashboard-Seite ist voraussichtlich nichts zu bauen.** Wir brauchen nur eure
> Bestätigung, dass der unten beschriebene Vertrag stabil bleibt – oder einen Hinweis, wenn ihr die
> Anlage der Bulk-Items lieber selbst behalten/anders lösen wollt.

## Worum geht es
Im Lager werden beim Wareneingang **fertige Kapseln (Bulk)** eingebucht – Warenart `fertig`
(`item.kategorie='fertig'`, `item.form` z. B. `kapsel`), noch **ohne** Verpackung. Nico möchte:
„bei Kapseln die Rezeptur prüfen/zuordnen" – also dass diese Fertigware **auf das Bulk-Item der
passenden Rezeptur** gebucht wird, statt auf einen losen neuen Artikel. Genau dafür gibt es bei euch
schon `rezeptur_bulkitem()`.

## Das gibt es im Dashboard bereits (wir hängen uns dran)
- Spalte **`item.rezeptur_id`** (`core/schema.php`, Kommentar dort: „Bulk-Item (Kategorie 'fertig')
  <-> Rezeptur (Bulk-Produktion ohne Verpackung)").
- Funktion **`rezeptur_bulkitem(int $rezeptur_id): ?int`** (`core/schema.php`). Verhalten aktuell:
  - sucht `SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig' LIMIT 1`;
  - fehlt es, legt es eines an: `naechste_nummer('BULK')`, Name `"<rezeptur.name> – Bulk"`,
    `kategorie='fertig'`, `form=<rezeptur.darreichungsform>`,
    `einheit`/`preis_bezug` = `g` (pulver) bzw. `ml` (fluessig/gel) bzw. sonst `Stück`.
- Wird bei euch schon beim Bulk-Produktionsauftrag genutzt
  (`produktionsauftrag_... rezeptur_bulkitem((int)$pa['rezeptur_id'])`). **Deshalb existiert das
  Bulk-Item in aller Regel schon, bevor die Fertigware physisch im Lager ankommt.**

## Was der Lager-Chat dazu baut (in `lager/core/erp.php`, eigene Naht – nicht bei euch)
Wir setzen das **in unserer Naht** um und fassen Dashboard-Tabellen nur dort an:
1. `erp_rezeptur_liste()` – `SELECT id, nummer, name, darreichungsform FROM rezeptur …` als Auswahl
   (Rezeptur-Picker, wie das Artikel-Dropdown), nur wenn Warenart `fertig`.
2. `erp_rezeptur_bulkitem(int $rezeptur_id): ?int` – **löst primär auf** (read):
   `SELECT id FROM item WHERE rezeptur_id=? AND kategorie='fertig' LIMIT 1`.
   Danach buchen wir den Wareneingang über unser bestehendes `erp_wareneingang_buchen(...)` auf diese
   `item_id` (Charge + Lieferant + MHD + Menge in Stück).

## Die eine Abstimmungsfrage
Für den **Sonderfall „Bulk-Item existiert noch nicht"** (z. B. Zukauf fertiger Kapseln ganz ohne
vorherigen Produktionsauftrag): Soll das Lager …

- **(A) nur auflösen und sonst abbrechen** mit Hinweis „Für diese Rezeptur gibt es noch kein
  Bulk-Lagerartikel – bitte erst im Dashboard anlegen/Produktionsauftrag" **(unser Default, 0 Risiko)**,
  **oder**
- **(B) das Bulk-Item im Lager anlegen dürfen**, indem wir eure Anlage-Logik 1:1 per SQL
  nachbilden (`erp_naechste_nummer('BULK')`, Name `"… – Bulk"`, `kategorie='fertig'`,
  `form`/`einheit` wie oben)? Das birgt **Divergenz-Risiko**, falls ihr `rezeptur_bulkitem()` später
  ändert (weitere Pflichtspalten, andere Namens-/Einheitenlogik, Hooks).

**Bitte um kurze Rückmeldung:**
1. Bleibt der Vertrag (`item.rezeptur_id` + `kategorie='fertig'` = kanonisches Bulk-Item je Rezeptur,
   Einheit Stück bei Kapseln/Tabletten) **stabil**? Dann baut der Lager-Chat (A) sofort.
2. Wollt ihr (B) erlauben – oder lieber einen schlanken **read-only-Helper** eurerseits bereitstellen
   bzw. die Anlage bewusst beim Dashboard behalten? Dann sagt kurz Bescheid, wie der Lager-Chat die
   fehlende-Item-Situation behandeln soll.

## Nicht verwechseln
- **Verpackte** Ware ist bei uns `verkaufsfertig`, nicht `fertig`. Es geht hier nur um die **Bulkware**.
- Wir fassen `core/schema.php` / eure Funktionen **nicht** an. Alles Lager-seitige passiert in
  `lager/core/erp.php`. Diese Datei ist nur Abstimmung.
