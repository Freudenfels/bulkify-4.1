# lieferant_katalog.php – der Katalog eines Lieferanten

## Wozu
Bisher entstand ein Artikel nur über eine Preisanfrage. Ein Lieferant kann jetzt selbst zeigen, **was er anbietet** – als Liste im Portal oder indem er seine Preisliste hochlädt und die KI sie ausliest.

**Der Lieferant verändert damit nie unsere Stammdaten.** Seine Angaben stehen in `lieferant_katalog`, nicht in `item`. Erst wenn jemand aus dem Team eine Zeile übernimmt, entsteht ein Artikel – auf Wunsch samt EK-Preis.

## Tabelle `lieferant_katalog`
`lieferant_id`, `dokument_id` (aus welcher hochgeladenen Liste), `name`, `name_en`, `name_lat`, `art` (rohstoff|fertigprodukt), `form`, `cas`, `spezifikation`, `herkunft`, `preis`, `waehrung`, `einheit`, `menge_ab` (MOQ), `notiz`, `status` (neu|uebernommen|abgelehnt), `item_id`, `angelegt`, `entschieden`.

## Funktionen
- `katalog_einlesen($lieferant_id, $pfad, $dokument_id)` – Liste mit der KI auslesen und als Zeilen speichern. Gibt Anzahl und Hinweise zurück.
- `katalog_zeilen($lieferant_id, $status)` / `katalog_offen()` / `katalog_offen_gesamt()` – lesen.
- `katalog_treffer($zeile)` – **gibt es den Artikel schon?** Sucht über CAS-Nummer und Namen, damit derselbe Rohstoff nicht dreimal angelegt wird.
- `katalog_uebernehmen($zeile_id, $item_id, $preis_uebernehmen)` – Artikel anlegen **oder** mit einem vorhandenen verknüpfen, Preis als `lieferant_preis`-Staffel schreiben, Zeile auf „übernommen" setzen.
- `katalog_ablehnen()` / `katalog_loeschen()` / `katalog_speichern()`.

Ein Fertigprodukt landet in der Kategorie `fertig`, alles andere als `rohstoff`; die Einheit kommt aus der Zeile, sonst kg (Rohstoff) bzw. Stück (Fertigprodukt).

## Wo es hängt
- Portal: `module/lieferant/katalog.php` (`?p=lieferant_katalog`, Menüpunkt „Mein Katalog") – Liste hochladen, Zeilen von Hand pflegen, eigene Zeilen löschen solange sie offen sind.
- Intern: `module/lieferant/detail.php`, Reiter **Katalog** mit der Zahl offener Zeilen. Je Zeile „Anlegen" bzw. „Preis dorthin" (wenn es den Artikel schon gibt) oder „ablehnen", dazu „Alle übernehmen" für lange Kataloge.

## Grenzen
Die KI liest, was in der Liste steht – sie prüft nicht, ob der Lieferant den Artikel wirklich liefern kann. Deshalb entscheidet immer ein Mensch. Bei sehr langen Katalogen kann das Auslesen an der Antwortgrenze enden; dann kommen die ersten Zeilen an, der Rest fehlt. In dem Fall die Liste geteilt hochladen.
