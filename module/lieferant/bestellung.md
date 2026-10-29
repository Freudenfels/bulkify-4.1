# lieferant/bestellung.php – Bestellungen im Lieferantenportal

Route: `?p=lieferant_bestellung[&id=<ID>]`

Ohne `id` die Liste, mit `id` die einzelne Bestellung: das **Ablauf-Panel** (`core/bestellung_ui.md`) zum Bestätigen mit Termin, Stationen pflegen und Versanddaten eintragen, darunter die Positionen mit Preisen und Summe sowie das Bestell-PDF.

**Preis je Position:** gezeigt wird `bestellposition_ek($pos, $lieferant_id)` (`core/schema.php`) – der gespeicherte `ek_preis`, und falls der 0 ist (Bulk/Fremdfertigung ohne Snapshot), der **bekannte Lieferantenpreis** aus `rezeptur_lief_angebot`/`produkt_lieferant_preis` (über Rezeptur bzw. Auftrag→Produkt). Neue Bulk-Bestellungen speichern den Fremdfertigungspreis bereits bei der Erstellung (`bestellung_erstellen` → `produkt_zukauf_preis`); der Fallback heilt Alt-Bestellungen in der Anzeige und im PDF (`core/pdf_bestellung.php`). Summe = Σ Menge × effektiver Preis.

**Eine Zeile je Produkt:** Bulk-Positionen desselben Produkts („Bulk: X (AB-…)" je Auftrag + die alte separate Puffer-/Lager-Zeile „Bulk: X") werden in der Anzeige zur **Gesamtmenge je Produkt** zusammengefasst (`$posView`). Der Lieferant braucht nur Produkt + Gesamtmenge, nicht den internen Auftrags-Split. Normale Artikel-Positionen (mit `item_id`) bleiben einzeln. Gilt auch für Alt-Bestellungen – die einzelnen Positionen bleiben in der DB (Auftragsbezug für den Wareneingang). Dieselbe Zusammenfassung macht das PDF (`core/pdf_bestellung.php`) und die interne Ansicht (`module/einkauf/detail.php`).

**Rezeptur je Position:** Bei Fremdfertigung/Bulk sieht der Lieferant, **welche Rezeptur** er herstellen soll. Die Rezeptur wird aufgelöst über `COALESCE(item.rezeptur_id, auftrag.rezeptur_id, produkt.rezeptur_id)` – also auch, wenn die Position kein Bulk-Item hat (Express-Fertigprodukt-Zukauf: `bestellung_position.item_id` NULL, aber `auftrag_id` gesetzt). Angezeigt wird Nummer + Name plus Knopf **„Rezeptur ansehen"** (→ `?p=lieferant_rezeptur`). Die read-only Rezepturansicht (`rezeptur_ansicht.php`) lässt eine Rezeptur zu, wenn ihre Form in `lieferanten.fertig_formen` steht **oder** der Lieferant sie angefragt/als Angebot bepreist/bestellt hat – so sieht er bestellte Rezepturen immer, kann aber keine fremden über die ID aufrufen.

**Zugriff:** Jede Abfrage filtert auf `lieferant_id` des angemeldeten Benutzers – die Prüfung hängt an der Abfrage, nicht an der Oberfläche. Eine fremde ID zeigt einfach die eigene Liste.
## E-Mail ans Team
Bestätigt der Lieferant die Bestellung oder setzt er eine Station, bekommen alle Admins eine Mail (`mail_team_bestellung()`), sofern der Versand eingerichtet ist.
## Rückfragen
Unter dem Ablauf-Panel steht **Rückfragen** (`nachricht_panel()` mit Bezug `bestellung`, POST `aktion=nachricht`) – dieselben Nachrichten sieht das Team an der Bestellung (`module/einkauf/detail.php`) und im Lieferantenkonto.

## Versand / Pakete (Kartons + Tracking-Nummern)
Eigenes Panel in der Bestelldetailansicht: Der Lieferant meldet, **wie viele Pakete/Kartons** er schickt und die zugehörigen **Tracking-/Sendungsnummern** (Textarea, eine pro Zeile – oder nacheinander scannen; optional Spediteur). `aktion=pakete_add` → `lieferung_pakete_hinzufuegen()` legt je Nummer eine Zeile in **`lieferung_paket`** an (global eindeutig über `uniq_tracking`; Duplikate werden übersprungen und gemeldet). Hat der Lieferant noch keine Nummern, kann er nur die **Anzahl** angeben (`bestellung.pakete_angekuendigt`). Liste mit Status **unterwegs / angekommen** (das Lager setzt `angekommen`), einzeln löschbar solange `angekommen=0` (`aktion=paket_del` → `lieferung_paket_loeschen()`). „Anzahl Kartons" = Zeilenanzahl je Bestellung; Übersicht **X / Y angekommen**. Alles weiterhin über `lieferant_id` isoliert.

Das **Lager** liest diese Daten (über `lager/core/erp.php`) für „Erwartete Lieferungen" und den Scan-Abgleich beim Wareneingang – gebaut im Lager-Bereich. Funktionen (Dashboard): `lieferung_pakete()`, `lieferung_pakete_hinzufuegen()`, `lieferung_paket_loeschen()` in `core/schema.php`.

## Dokumente & Rechnung
Eigenes Panel: der Lieferant lädt CoA, Rechnung, Spec etc. direkt zu DIESER Bestellung hoch (`aktion=bestell_dok_upload` → `bestell_dok_upload()`), sieht hochgeladene Dokumente als Liste (einzeln löschbar außer Zahlungsbeleg) und – sobald vom Team gesetzt – den Zahlungsstatus „Bezahlt am …".

## Mehrsprachig (DE/EN/ZH)
Die ganze Seite nutzt `lp_t()` (`portal_layout.php`) – auch die Panels **Versand / Pakete** und **Dokumente & Rechnung** samt der Dokumenttypen (`$BDTYP`). Nur echte Dateninhalte bleiben in Originalsprache (z. B. der Bestell-`notiz`-Text wie „Aus v3 …", Tracking-Nummern, Spediteur-Name, Zahlungsweg). Neue sichtbare Texte immer als `lp_t('<key>')` mit de/en/zh im Wörterbuch anlegen, nie hart eintippen.
