# core/nachbestellung.php – Einfache Nachbestellung aus dem Kundenportal

Zweck (Nico): der schnellste Weg für Wiederholungskäufe. Der Kunde tippt am schon bestellten Produkt nur die **Menge** ein und bestellt **verbindlich** – **kein** neuer Etikett-Upload, **keine** neue Preisanfrage, **kein** neues Angebot für den Kunden. bulkify nimmt die Nachbestellung nur zur **kurzen Preisprüfung** an; in 98 % bleibt der Preis gleich.

## Datenmodell
Tabelle `nachbestellung` (self-schema, best-effort, Once-Guard): `kunde_id`, `quelle_angebot_id`, `quelle_auftrag_id`, `produkt_id`, `rezeptur_id`, `stueck` (Stück/Pkg), `menge` (Pkg), `vk_stueck` (zuletzt vereinbarter Preis, kopiert), `vk_neu` (bei Anpassung), `status` (`pruefung`|`preis_neu`|`bestaetigt`|`abgelehnt`), `angebot_id`/`auftrag_id` (erzeugt), `grund`, Zeitstempel.

## Preis nach Menge (aus früheren Angeboten)
`nachbestellung_preis_fuer()` bestimmt den Preis/Pkg **passend zur Nachbestellmenge** – ausschließlich aus Staffeln, die der Kunde für dieses Produkt schon gesehen hat (`nachbestellung_staffel()`: alle `angebot_staffel` seiner Angebote mit Status `gesendet`/`bestaetigt`, auf die Packungsgröße `stueck` eingeschränkt). Regel (Entscheidung Nico):
- **Echte Staffel in beide Richtungen:** Preis = Staffel mit der größten `menge ≤ Bestellmenge` (der Tarif, in den die Menge fällt). Liegt die Menge unter der kleinsten Staffel, gilt die kleinste (teuerste) Staffel – eine kleinere Nachbestellung kann also teurer/Pkg sein.
- **Über dem Maximum:** ist die Bestellmenge größer als jede je angebotene Staffel, gilt der **beste** (niedrigste) angebotene Preis und die Nachbestellung wird mit Hinweis „Preis bitte prüfen" markiert (`grund`), damit das Team bei großen Mengen bewusst entscheidet. Kein erfundener Rabatt.
- **Kein Produkt/keine Staffel gefunden:** Fallback = zuletzt vereinbarter Preis.
Der Preis wird beim Anlegen serverseitig berechnet und gespeichert; im Portal zeigt die Box ihn zusätzlich **live** je eingetippter Menge (gleiche Regel in JS, nur Anzeige – der Server bleibt maßgeblich).

## Ablauf
1. **Kunde** (`nachbestellung_anlegen`): kopiert Produkt/Verpackung/Paket und bestimmt den Preis per `nachbestellung_preis_fuer()` (Fallback: zuletzt vereinbarter Preis aus `nachbestellung_quelle()`). Status `pruefung` (verbindlich, wartet auf Preisprüfung) + Team-Benachrichtigung.
2. **bulkify** (`nachbestellung_bestaetigen`): erzeugt über den vorhandenen, idempotenten Weg `auftrag_aus_angebot()` den Auftrag + Rechnung + Produktionsauftrag. Dafür wird ein **minimales bestätigtes Angebot** (+ eine bestätigte Staffel mit Menge/Preis/Paket) angelegt. Die **Etikett-Freigabe** wird aus dem Vorauftrag übernommen (`auftrag.etikett_freigegeben/_am/_von`) – keine erneute Freigabe nötig.
3. **Ausnahme 2 %** (`nachbestellung_preis_anpassen`): Status `preis_neu` + `vk_neu`; der Kunde gibt den neuen Preis im Portal frei (`nachbestellung_kunde_preis_ok`), dann entsteht der Auftrag zum neuen Preis.

## Auftragsbasierte Quelle (ab 2026-11)
`nachbestellung_quelle_auftrag()` / `nachbestellung_anlegen_auftrag()` leiten Produkt, Packungsgröße, Menge und Preis
**direkt aus dem Auftrag** ab (`vk_stueck`) – funktioniert auch für importierte/gebündelte Aufträge **ohne** bestätigtes
Angebot mit Staffel. Genutzt vom Portal-Reiter „Nachbestellen" und der Nachbestell-Box an abgeschlossenen Bestellungen
(`quelle_auftrag_id`). Schutz: nur eigene, **nicht stornierte** Produkt-Aufträge; Menge ≤ 100 Mio. (INT-Spalte);
**Doppelbestellschutz** (offene Nachbestellung zu demselben Produkt+Packungsgröße blockiert die nächste). Der Preis-Fallback
ist der **Auftragspreis ohne zweiten Kundenrabatt** (UI und Server stimmen überein).
`nachbestellung_order_erstellen()` übernimmt zusätzlich die **Verpackung des Vorauftrags** (`auftrag.verpackung_id`),
weil `auftrag_aus_angebot()` für das Minimal-Angebot sonst nur die Produkt-Standardverpackung nähme (kundenspezifische/
geänderte Verpackung ginge verloren); danach `bedarf_bump()`.

## Wiederverwendete Bausteine / bewusste Entscheidungen
- **Auftrag/Rechnung/PA:** ausschließlich `auftrag_aus_angebot()` (schema.php) – nichts doppelt gebaut. PA landet wie üblich in `vorbereitung` und läuft durch die normale Produktions-Freigabe (Teil 3).
- **Bindung:** der Kunden-Klick ist verbindlich; die bulkify-Bestätigung ist nur Preisprüfung (Entscheidung Nico).
- **Etikett:** alte Freigabe wird automatisch übernommen (Entscheidung Nico), weicht bewusst von der sonst harten „Freigabe je Auftrag"-Regel ab – weil Design und Version unverändert sind.
- Helfer `nachbestellung_offen_count()` (Badge/Übersicht) und `nachbestellung_offen_fuer()` (Portal-Anzeige je Quell-Angebot).

Siehe auch: Portal-Box in [../module/portal/_angebot_karte.php](../module/portal/_angebot_karte.php) (bestätigter Zweig), Team-Eingang [../module/system/nachbestellungen.php](../module/system/nachbestellungen.php).

`nachbestellung_quelle_auftrag()` nimmt als Preis je Packung den GESAMTpreis des Vorauftrags (`auftrag_vk_gesamt`, inkl. Glas/Deckel/Etikett) statt `auftrag.vk_stueck` (nur Herstellung) – S-19.

**Original-Staffel (S-19):** `nachbestellung_staffel()` liest neben `angebot_staffel` (Matrix-Angebote) die ORIGINAL-Positionsangebote des Kunden, aus denen das Produkt entstanden ist (`angebot_produkt`), über `angebot_optionen()`: je wählbare Option Menge = `pakete`, Preis = `pro_pkg` (Herstellung + Glas + Deckel + Etikett). Die kleinen Nachbestell-Angebote (notiz „Nachbestellung #…") fließen nicht mehr in die Staffel ein.

## Mails (Paket Mails)
`nachbestellung_anlegen*()` → `mail_nachbestellung($id,'eingang')` (Kunde: Eingang; Team: bitte Preis bestätigen), `nachbestellung_preis_anpassen()` → `'preis_neu'`, `nachbestellung_bestaetigen()` / `nachbestellung_kunde_preis_ok()` → `'bestaetigt'` (Auftragsbestätigung mit Nummer). Alle nach der Antwort (`nach_antwort`).

## Nachbestellung ohne Preis (09.11.2026)

- `nachbestellung_kundenpreis_vk($kunde, $produkt, $stueck, $menge, $verpackung_id)`: Preis-Fallback, wenn der Vorauftrag keinen Preis hat (Altaufträge mit 0 €). Nimmt den zentralen Kundenpreis (`produkt_kundenpreis`, passende Packungsgröße, Staffel nach Bestellmenge) plus Verpackung/Deckel/Etikett (`verpackung_cent_je_pack`) – gleiche Rechnung wie `?p=kundenpreise`. Kein Kundenpreis = 0.
- Beim Anlegen (Angebot- und Auftrags-Weg) greift der Fallback automatisch; bleibt es bei 0, steht als Hinweis „Kein Preis hinterlegt – bitte Preis anpassen.“
- `nachbestellung_bestaetigen()` lehnt 0 € ab (sonst Auftrag + Rechnung über 0 €).
- `nachbestellung_kundenpreis_uebernehmen($id)`: Team übernimmt den Kundenpreis und gibt in einem Schritt frei.
- `nachbestellung_offen_kunde($kunde)`: offene Nachbestellungen (Prüfung / neuer Preis) für das Portal „Bestellungen“.

## Entscheidungsgrundlage (09.11.2026)
- `nachbestellung_kundenpreis_teile()` liefert den Kundenpreis aufgeteilt (Herstellung, Verpackung, Gesamt, Staffel); `nachbestellung_kundenpreis_vk()` nutzt sie.
- `nachbestellung_entscheidung($nb)` sammelt für die Team-Seite: Vorauftrag, Verpackungsteile, Angebotsstaffel, frühere Aufträge mit Rechnungsbetrag je Pkg, letzte Rechnung, Kundenpreis und EK (Füllung über `produkt_variante_ek()` + Verpackung über `pack_ek_bei_menge()`). Ändert nichts.
