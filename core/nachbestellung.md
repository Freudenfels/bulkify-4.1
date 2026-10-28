# core/nachbestellung.php – Einfache Nachbestellung aus dem Kundenportal

Zweck (Nico): der schnellste Weg für Wiederholungskäufe. Der Kunde tippt am schon bestellten Produkt nur die **Menge** ein und bestellt **verbindlich** – **kein** neuer Etikett-Upload, **keine** neue Preisanfrage, **kein** neues Angebot für den Kunden. bulkify nimmt die Nachbestellung nur zur **kurzen Preisprüfung** an; in 98 % bleibt der Preis gleich.

## Datenmodell
Tabelle `nachbestellung` (self-schema, best-effort, Once-Guard): `kunde_id`, `quelle_angebot_id`, `quelle_auftrag_id`, `produkt_id`, `rezeptur_id`, `stueck` (Stück/Pkg), `menge` (Pkg), `vk_stueck` (zuletzt vereinbarter Preis, kopiert), `vk_neu` (bei Anpassung), `status` (`pruefung`|`preis_neu`|`bestaetigt`|`abgelehnt`), `angebot_id`/`auftrag_id` (erzeugt), `grund`, Zeitstempel.

## Ablauf
1. **Kunde** (`nachbestellung_anlegen`): kopiert Produkt/Verpackung/Paket und den **zuletzt vereinbarten Preis** aus dem letzten **bestätigten Angebot** (`nachbestellung_quelle()` liest `angebot` + bestätigte `angebot_staffel`). Status `pruefung` (verbindlich, wartet auf Preisprüfung) + Team-Benachrichtigung.
2. **bulkify** (`nachbestellung_bestaetigen`): erzeugt über den vorhandenen, idempotenten Weg `auftrag_aus_angebot()` den Auftrag + Rechnung + Produktionsauftrag. Dafür wird ein **minimales bestätigtes Angebot** (+ eine bestätigte Staffel mit Menge/Preis/Paket) angelegt. Die **Etikett-Freigabe** wird aus dem Vorauftrag übernommen (`auftrag.etikett_freigegeben/_am/_von`) – keine erneute Freigabe nötig.
3. **Ausnahme 2 %** (`nachbestellung_preis_anpassen`): Status `preis_neu` + `vk_neu`; der Kunde gibt den neuen Preis im Portal frei (`nachbestellung_kunde_preis_ok`), dann entsteht der Auftrag zum neuen Preis.

## Wiederverwendete Bausteine / bewusste Entscheidungen
- **Auftrag/Rechnung/PA:** ausschließlich `auftrag_aus_angebot()` (schema.php) – nichts doppelt gebaut. PA landet wie üblich in `vorbereitung` und läuft durch die normale Produktions-Freigabe (Teil 3).
- **Bindung:** der Kunden-Klick ist verbindlich; die bulkify-Bestätigung ist nur Preisprüfung (Entscheidung Nico).
- **Etikett:** alte Freigabe wird automatisch übernommen (Entscheidung Nico), weicht bewusst von der sonst harten „Freigabe je Auftrag"-Regel ab – weil Design und Version unverändert sind.
- Helfer `nachbestellung_offen_count()` (Badge/Übersicht) und `nachbestellung_offen_fuer()` (Portal-Anzeige je Quell-Angebot).

Siehe auch: Portal-Box in [../module/portal/_angebot_karte.php](../module/portal/_angebot_karte.php) (bestätigter Zweig), Team-Eingang [../module/system/nachbestellungen.php](../module/system/nachbestellungen.php).
