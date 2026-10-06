# Aufgabe für das Buchhaltungs-Modul: Dienstleistungs-Rechnungen (DR-)

> Arbeitsanweisung an den Buchhaltungs-Chat (`/buchhaltung/`). Erstellt vom Dienstleistungen-Chat.
> Stand: 2026-10-06.

## Entscheidung (Nico)
Klare Trennung, damit nichts doppelt gebaut/ausgeführt wird:
- **Dienstleistungen-Modul** (`module/dienstleistung/`, `core/dienstleistung.php`) macht die **Dienstleistungsprodukte**: Katalog, DL-Angebot (`DA-`) und DL-Auftrag (`DB-`).
- **Buchhaltung** macht die **Rechnung**: Erstellung, Verwaltung, Storno, PDF/E-Rechnung der DL-Rechnung (`DR-`).

Die DL-Rechnung ist ein **ganz normaler Beleg** – nur mit Marker `beleg.kategorie='dienstleistung'` und eigenem Nummernkreis `DR-`. Sie läuft damit automatisch ins zentrale Kassenbuch, DATEV, USt, E-Rechnung.

## Ist-Stand (schon gebaut, NICHT nochmal bauen)
- Tabelle `dienstleistung` (Katalog) + Logik in `core/dienstleistung.php`.
- **DL-Angebot**: `angebot.kategorie='dienstleistung'`, Nummernkreis `DA-`; Positionen in `angebot_position` (mit `dienstleistung_id`, `quelle='dienstleistung'`, `bezeichnung`, `menge`, `einheit`, `preis_cent`, `mwst_satz`).
- **DL-Auftrag**: `auftrag.kategorie='dienstleistung'`, Nummernkreis `DB-`, `auftrag.angebot_id` zeigt aufs DA-Angebot, `gesamt_netto` trägt die Summe (`menge/vk_stueck` = 0, weil Mehrpositions-Vorgang).
- Marker-Spalten additiv vorhanden: `angebot.kategorie`, `auftrag.kategorie`, `beleg.kategorie` (Default `'produkt'`).
- Kunden-PDF der DL-Rechnung ist bereits korrekt: das Portal rendert DL-Rechnungen aus den echten `beleg_position`-Zeilen (Fix erledigt). **Hier ist nichts weiter nötig.**

### Wird auf Dashboard-Seite entfernt (Abstimmung)
Aktuell erzeugt das Dienstleistungen-Modul die DR-Rechnung noch selbst:
`dl_rechnung_aus_auftrag()` in `core/dienstleistung.php` + Button „DL-Rechnung erstellen" in `module/dienstleistung/auftrag.php`.
**Das wird entfernt**, sobald euer Buchhaltungs-Flow steht – dann verlinkt der DL-Auftrag nur noch zu euch. Bitte kurz Bescheid geben, wenn eure Route live ist, dann stelle ich die Dashboard-Seite um (kein Doppel-Erstellen in der Übergangszeit: solange euer Flow fehlt, bleibt der alte Button bestehen).

## Was die Buchhaltung bauen soll

### 1. DL-Rechnung aus DL-Auftrag erzeugen (in `buchhaltung/core/finanz.php`)
Neue Funktion analog zu `rechnung_aus_auftrag()`, aber:
- Nur für `auftrag.kategorie='dienstleistung'`.
- **Positionen 1:1** aus `angebot_position` des verknüpften DA-Angebots (`auftrag.angebot_id`) übernehmen – NICHT wie beim Produkt eine Sammelzeile bauen. Felder: `artikelnr`, `bezeichnung`, `beschreibung`, `menge`, `einheit`, `preis_cent`, `mwst_satz` → in `beleg_position`.
- Beleg-Kopf: `nummer=naechste_nummer('DR')`, `typ='rechnung'`, **`kategorie='dienstleistung'`**, `auftrag_id`, `kunde_id`.
- Summen mit `beleg_summen_aus_positionen()` (gibt es schon in finanz.php). USt-Satz am Kopf = führender Positions-`mwst_satz` (wie bei `rechnung_frei_erstellen`).
- `datum`, `zahlungsziel_tage`, `faellig`, `text`, `kunde_sichtbar` wie bei `rechnung_aus_auftrag`.
- **Idempotent**: existiert schon eine nicht stornierte DR-Rechnung zum Auftrag → deren ID zurückgeben.

Referenz-Implementierung liegt bereits im Dashboard (`core/dienstleistung.php` → `dl_rechnung_aus_auftrag()`); bitte verbatim nach `finanz.php` übernehmen (gleiche Doppelungs-Logik wie bei den anderen Beleg-Funktionen).

### 2. Vorschau/Freigabe VOR der Nummernvergabe (wichtig – das ist der eigentliche Auftrag)
Bisheriger Mangel: ein Klick zieht sofort die `DR-`-Nummer, ohne Review.
- Neue Route, z. B. `?p=dl_rechnung_neu&auftrag=<DB-Auftrag-ID>`: zeigt eine **Vorschau** (Kunde, Positionen, Netto/USt/Brutto, Zahlungsziel, Checkbox „für Kunde freigeben").
- Erst der Button **„Verbindlich erstellen"** ruft die Funktion aus (1) und zieht damit die `DR-`-Nummer.
- Rechtlich: Nummer erst bei Freigabe → lückenlos, keine „verbrannten" Nummern.
- Danach Redirect auf die normale Beleg-Detailseite (`?p=rechnung&id=…`).

### 3. Verwaltung – nutzt die vorhandene Beleg-Detailseite (nichts Neues nötig)
`buchhaltung/module/beleg/detail.php` kann für jede Rechnung – also auch DR – bereits:
Stornieren (`aktion=storno` → `gutschrift_aus_rechnung`), Positionen manuell bearbeiten / aus Angebot übernehmen, Rechnungskopf bearbeiten, für Kunde freigeben/zurückziehen, PDF, E-Rechnung. **Bitte so lassen.**

### 4. Liste/Filter DL-Rechnungen
In `buchhaltung/module/beleg/rechnungen_liste.php` einen Filter/Reiter „Dienstleistungen" ergänzen (`WHERE … AND kategorie='dienstleistung'`) bzw. eine Spalte „Art" (Produkt/Dienstleistung), damit DR- und RE-Rechnungen auseinanderzuhalten sind. Additive WHERE-Erweiterung, bestehende Liste nicht umbauen.

### 5. Nummernkreis-Prüfung erweitern
`bh_nummernkreis_pruefung()` (in `buchhaltung/core/buchhaltung.php`) prüft aktuell nur `['RE','GS']`. Bitte um **`'DA','DB','DR'`** erweitern, damit auch die Dienstleistungs-Kreise auf Lückenlosigkeit/Dubletten geprüft werden.

## Grenzen & Regeln (bitte strikt)
- **Geteilte Dashboard-Tabellen** (`auftrag`, `angebot`, `angebot_position`, `kunden`, `nummernkreis`, `app_meta`, `aktivitaet`) nur über `buchhaltung/core/erp.php` zugreifen – wie bei den bestehenden Beleg-Funktionen. `beleg`/`beleg_position` gehören der Buchhaltung.
- DB nur **additiv** (CREATE IF NOT EXISTS / ensure_column), **keine** pauschalen DELETEs auf der Live-DB (es wird parallel gearbeitet).
- Git: vor jedem Push `git pull --rebase`, dann `git push`, nie `--force`. Nur fertige, getestete Schritte (Auto-Deploy hängt an `main`).
- Lokal testen: `php -l` + kurzer curl-Test.
- Keine Emojis in der UI, Feld-/Spaltenüberschriften nicht fett (globale UI-Regeln).

## Einstieg für den Nutzer (Zielbild)
DL-Auftrag (Dashboard) → Button „Rechnung in der Buchhaltung erstellen" → `/buchhaltung/?p=dl_rechnung_neu&auftrag=<id>` (Vorschau) → „Verbindlich erstellen" → Beleg-Detail. Von der DL-Rechnungsliste (`?p=dl_rechnungen`) führt der Link weiter zur Beleg-Detailseite der Buchhaltung (öffnen/bearbeiten/stornieren).

## Rückmeldung bitte an den Dienstleistungen-Chat
Wenn `dl_rechnung_neu` (Vorschau + Erstellen) live ist: kurz melden, dann
1. entferne ich `dl_rechnung_aus_auftrag()` + den „DL-Rechnung erstellen"-Button aus dem Dashboard,
2. verlinke DL-Auftrag und DL-Rechnungsliste auf eure Buchhaltungs-Routen.
