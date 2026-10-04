# system/angebotsscan.php – Angebotsscan (KI)

**Zweck:** Ein beliebiges Angebot (eigenes oder fremdes, auch alte Angebote) per KI einlesen und daraus
**Rezeptur** (Produkt, Form, Wirkstoffe/mg), **Preise** (aufgeschlüsselt: Herstellung, Kapsel,
Verpackung, Etikett …) **und den Kunden** (Firma, Datum, VK je Packung) erfassen. Die Rezeptur selbst
wird kundenunabhängig geführt; der **Kundenpreis** wird zusätzlich an der Rezeptur hinterlegt, sodass
man auf der Rezeptur die Preise **aller** Kunden sieht.

Ein führendes **„AP"** im Produktnamen (interne Annapurna-Zuordnung) wird ignoriert/entfernt
(`angebotsscan_name_bereinigen()`).

## Ablauf
- Upload (PDF/Bild) → `angebotsscan_verarbeiten()`:
  - `angebotsscan_ki()` liest JSON: `produkt_name`, `darreichungsform`, `stueck_je_packung`,
    `kunde_name`, `kunde_nr`, `datum`, `vk_stueck`, `menge`,
    `zutaten[{name,menge_mg}]`, `preise[{bezeichnung,typ,einzelpreis,menge,einheit}]`
    (typ = herstellung|kapsel|verpackung|etikett|zusatz|gesamt).
  - `rezeptur_finden_oder_anlegen(name, form, zutaten, null)` legt die Rezeptur **kundenunabhängig**
    an (Status *eingefroren*) bzw. ordnet eine bestehende zu.
  - `kunde_finden_oder_anlegen(firma, nr)` – wenn ein Kunde erkannt wird, finden oder **neu anlegen**.
  - Kundenpreis in `rezeptur_kundenpreis` (Rezeptur×Kunde×Datum; Dublette wird ersetzt → idempotent bei Re-Scan).
  - Scan-Zeile in `angebot_scan` (Kunde/Datum/VK + Preise/Zutaten als JSON, Originaldatei, Bemerkung).
- Danach Detailseite: Produkt/Rezeptur, Kunde/Datum/VK, Wirkstoff-Tabelle, Preis-Aufschlüsselung.
- Übersicht: Liste aller Scans (Produkt, Kunde, VK, Rezeptur), Klick → Detail.
- **Rezeptur-Detail** zeigt einen Block „Kundenpreise" (alle Kunden + Datum + VK) – `module/rezeptur/detail.php`.

## Daten
- **`angebot_scan`**: produkt_name, darreichungsform, stueck_je_packung, rezeptur_id, rezeptur_neu,
  kunde_id, kunde_neu, angebot_datum, vk, preise_json, zutaten_json, datei, original_orig, bemerkung,
  angelegt_von, angelegt.
- **`rezeptur_kundenpreis`**: rezeptur_id, kunde_id, datum, vk, stueck_je_packung, menge, preise_json,
  quelle ('angebotsscan'), scan_id, angelegt.

## Funktionen (`core/schema.php`)
`angebotsscan_ki($pfad)` (nur lesen → strukturiertes Array inkl. Kunde/Datum/VK),
`angebotsscan_verarbeiten($pfad, $orig, $benutzer_id, $bemerkung)` (lesen + Rezeptur + Kunde +
Kundenpreis + Scan speichern), `kunde_finden_oder_anlegen($firma, $nr)`,
`angebotsscan_name_bereinigen($name)`. Nutzt `rezeptur_finden_oder_anlegen(..., null)`.

## Route & Rechte
`?p=angebotsscan` → `public/index.php` (System-Gruppe im Menü). Rollen **admin, finance, sales**
(`core/auth.php`). Braucht die KI (Einstellungen → KI); ohne KI ist der Upload deaktiviert.
Original-Angebote werden **nicht** als Beleg/Dokument an einen Kunden gehängt – nur die Datei liegt
in `data/uploads` (für den Scan).
