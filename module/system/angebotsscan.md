# system/angebotsscan.php – Angebotsscan (KI)

**Zweck:** Ein beliebiges Angebot (eigenes oder fremdes, auch alte Angebote) per KI einlesen und daraus
**Rezeptur** (Produkt, Form, Wirkstoffe/mg) **und Preise** (aufgeschlüsselt: Herstellung, Kapsel,
Verpackung, Etikett …) erfassen. Der **Kunde ist bewusst uninteressant** und wird nicht ausgewertet –
es geht nur um Rezeptur und Preise. Reines System-/Erfassungswerkzeug.

## Ablauf
- Upload (PDF/Bild) → `angebotsscan_verarbeiten()`:
  - `angebotsscan_ki()` liest JSON: `produkt_name`, `darreichungsform`, `stueck_je_packung`,
    `zutaten[{name,menge_mg}]`, `preise[{bezeichnung,typ,einzelpreis,menge,einheit}]`
    (typ = herstellung|kapsel|verpackung|etikett|zusatz|gesamt).
  - `rezeptur_finden_oder_anlegen(name, form, zutaten, null)` legt die Rezeptur **kundenunabhängig**
    an (Status *eingefroren*) bzw. ordnet eine bestehende zu.
  - Scan-Zeile in `angebot_scan` (Preise/Zutaten als JSON, Originaldatei, Bemerkung).
- Danach Detailseite: Produkt/Rezeptur, Wirkstoff-Tabelle, Preis-Aufschlüsselung, Link zur Rezeptur.
- Übersicht: Liste aller Scans (neueste zuerst), Klick → Detail.

## Daten
Tabelle **`angebot_scan`** (`core/schema.php`, in `init_schema()`): produkt_name, darreichungsform,
stueck_je_packung, rezeptur_id, rezeptur_neu, preise_json, zutaten_json, datei, original_orig,
bemerkung, angelegt_von, angelegt.

## Funktionen (`core/schema.php`)
`angebotsscan_ki($pfad)` (nur lesen → strukturiertes Array), `angebotsscan_verarbeiten($pfad, $orig,
$benutzer_id, $bemerkung)` (lesen + Rezeptur + Scan speichern). Nutzt
`rezeptur_finden_oder_anlegen(..., null)`.

## Route & Rechte
`?p=angebotsscan` → `public/index.php` (System-Gruppe im Menü). Rollen **admin, finance, sales**
(`core/auth.php`). Braucht die KI (Einstellungen → KI); ohne KI ist der Upload deaktiviert.
Original-Angebote werden **nicht** als Beleg/Dokument an einen Kunden gehängt – nur die Datei liegt
in `data/uploads` (für den Scan).
