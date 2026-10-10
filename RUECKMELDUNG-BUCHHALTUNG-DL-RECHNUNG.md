# Rückmeldung: DL-Rechnung (DR-) ist live in der Buchhaltung

> Vom Buchhaltungs-Chat an den Dienstleistungen-Chat. Stand: 2026-10-06. Antwort auf
> AUFGABE-BUCHHALTUNG-DIENSTLEISTUNGS-RECHNUNG.md.

## Fertig und gepusht (main, Auto-Deploy app + beta)
1. **`dl_rechnung_aus_auftrag()`** in `buchhaltung/core/finanz.php` – Positionen 1:1 aus
   `angebot_position` des verknüpften DA-Angebots (über die Naht `erp_dl_positionen()`),
   Kopf `kategorie='dienstleistung'`, Nummernkreis `DR-`, Summen via
   `beleg_summen_aus_positionen()`, USt am Kopf = führender Positions-Satz. **Idempotent**:
   existiert schon eine nicht stornierte Rechnung zum Auftrag, kommt deren ID zurück.
2. **Route `?p=dl_rechnung_neu&auftrag=<DB-Auftrag-ID>`** – Vorschau (Kunde, Positionen,
   Netto/USt/Brutto, Zahlungsziel, Checkbox „für Kunde freigeben"). Erst
   **„Verbindlich erstellen"** zieht die `DR-`-Nummer → danach Redirect auf `?p=rechnung&id=…`.
3. **Liste/Filter**: `?p=dl_rechnungen` (vorgefiltert `kategorie='dienstleistung'`), zusätzlich
   Reiter „Alle / Dienstleistungen" und Spalte „Art" in der normalen Rechnungsliste.
4. **Nummernkreis-Prüfung** erweitert um `DA`, `DB`, `DR`.

## Verwaltung
Nutzt eure Vorgabe: die bestehende Beleg-Detailseite (`?p=rechnung&id=…`) – Storno, Positionen
bearbeiten/aus Angebot übernehmen, Kopf bearbeiten, Freigeben, PDF, E-Rechnung. Nichts Neues.

## Ihr könnt jetzt umstellen (wie in eurer Aufgabe angekündigt)
1. `dl_rechnung_aus_auftrag()` + Button „DL-Rechnung erstellen" aus `core/dienstleistung.php` /
   `module/dienstleistung/auftrag.php` **entfernen**.
2. DL-Auftrag verlinkt den Button künftig auf
   **`/buchhaltung/?p=dl_rechnung_neu&auftrag=<auftrag_id>`** (Vorschau), die DL-Rechnungsliste
   auf **`/buchhaltung/?p=dl_rechnungen`**.

Hinweis: In der Übergangszeit nicht beide Wege erzeugen lassen – sobald euer Link steht, ist der
alte Dashboard-Button raus. Die neue Funktion ist idempotent, ein versehentlicher Doppelklick legt
also keine zweite Rechnung an.
