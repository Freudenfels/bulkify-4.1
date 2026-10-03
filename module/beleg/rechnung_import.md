# beleg/rechnung_import.php – Alt-Rechnungen importieren (KI)

**Zweck:** Ältere Original-Rechnungen (die es nicht als v4-Beleg gibt) **für einen Kunden** bulk-
hochladen. Die KI liest je Datei **Nummer, Datum, Betrag und – falls erkennbar – den Bezahlstatus**
aus; daraus entsteht je Rechnung ein **Beleg** (`typ='rechnung'`, ohne Auftrag, `kunde_sichtbar=1`) mit
dem **Original-PDF** als Download. So erscheinen auch Alt-Rechnungen im Kundenportal unter „Rechnungen".

## Ablauf
1. Kunde wählen, **mehrere** Rechnungen (PDF/Bild) hochladen, optional „alle als **bezahlt** markieren".
2. Pro Datei: Datei in `data/uploads` speichern → `rechnung_import_ki()` (KI, `core/ki.php`) liest die
   Kopfdaten → `rechnung_alt_anlegen()` legt den Beleg an (Netto/USt/Brutto konsistent gerechnet,
   Status `bezahlt`/`offen`, `original_datei`/`original_orig` gesetzt). Kann die KI nichts lesen, wird
   der Beleg **ohne Betrag** angelegt (Datei bleibt erhalten) – Betrag später über „Rechnungskopf
   bearbeiten" nachtragen.
3. Ergebnis-Tabelle (Datei · Nummer · Datum · Betrag · Status · Öffnen) zum Gegenprüfen.

## Backend (`core/schema.php`)
- `rechnung_import_ki($pfad)` – KI-Auslesung, Rückgabe `nummer/datum/netto/ust_prozent/brutto/bezahlt/bezahlt_am` (wirft nie).
- `rechnung_alt_anlegen($kunde_id, $felder, $datei, $orig)` – Beleg (Rechnung, ohne Auftrag) + Original-Datei; `kunde_sichtbar=1`.
- Spalten `beleg.original_datei` / `beleg.original_orig` (via `ensure_column`).

## Anzeige
- **Portal:** Liste „Rechnungen" (`module/portal/kunde.php`, `$rechnungen`); Download über `?v=rechnung_datei&id=<beleg_id>` (nur eigene, freigegebene Rechnung).
- **Team:** Rechnung öffnen → Knopf **„Original-Rechnung"** (`?p=rechnung&id=…&original=1`) statt der generierten PDF. Korrekturen über „Rechnungskopf bearbeiten" / Zahlung erfassen ([detail.md](detail.md)).

## Route & Rechte
`?p=rechnung_import` → `public/index.php`; Rolle **finance** (`core/auth.php`). Verlinkt aus der Rechnungen-Liste.
