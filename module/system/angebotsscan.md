# system/angebotsscan.php – Angebotsscan (KI)

**Zweck:** Ein beliebiges Angebot (eigenes oder fremdes, auch alte Angebote) per KI einlesen und daraus
**Rezeptur** (Produkt, Form, Wirkstoffe/mg), **Preise** (aufgeschlüsselt: Herstellung, Kapsel,
Verpackung, Etikett …) **und den Kunden** (Firma, Datum, VK je Packung) erfassen. Die Rezeptur selbst
wird kundenunabhängig geführt; der **Kundenpreis** wird zusätzlich an der Rezeptur hinterlegt, sodass
man auf der Rezeptur die Preise **aller** Kunden sieht.

Ein führendes **„AP"** im Produktnamen (interne Annapurna-Zuordnung) wird ignoriert/entfernt
(`angebotsscan_name_bereinigen()`).

## Ablauf (zweistufig: Auslesen → Match → Speichern)
1. **Upload** (PDF/Bild) → `angebotsscan_ki()` liest JSON: `produkt_name`, `darreichungsform`,
   `stueck_je_packung`, `kunde_name`, `kunde_nr`, `datum`, `vk_stueck`, `menge`,
   `staffeln[{menge,vk_stueck}]` (Mengen-Staffeln), `zutaten[{name,menge_mg}]`,
   `preise[{bezeichnung,typ,einzelpreis,menge,einheit}]` (typ = herstellung|kapsel|verpackung|etikett|zusatz|gesamt).
   Ergebnis landet in `$_SESSION['angebotsscan']` → Weiterleitung auf `&schritt=match`.
2. **Match / Vorschau** (`&schritt=match`): zeigt ALLE Infos zum Prüfen/Korrigieren – Produktname,
   Stück/Packung, Angebotsdatum, **Kunde** (Dropdown: neu anlegen / vorhandenen zuordnen / kein Kunde;
   vorbelegt per Treffer über Kundennummer bzw. Firma), **Staffeln** (editierbare Zeilen, + Staffel /
   entfernen), Wirkstoffe + Preis-Aufschlüsselung (Anzeige). „Speichern" → `angebotsscan_speichern()`.
3. **Speichern**: `rezeptur_finden_oder_anlegen(..., null)` (Rezeptur kundenunabhängig, eingefroren).
   Bei gewähltem Kunden `kunde_finden_oder_anlegen()` und **je Staffel** eine Zeile in
   `rezeptur_kundenpreis` (idempotent je Rezeptur×Kunde×Datum). Scan-Zeile in `angebot_scan`
   (Kunde/Datum/VK + staffeln_json/preise_json/zutaten_json, Originaldatei, Bemerkung).
- **Detail** (`&id=`): Produkt/Rezeptur, Kunde/Datum, **Staffel-Tabelle**, Wirkstoffe, Preis-Aufschlüsselung.
- **Übersicht**: Liste aller Scans (Produkt, Kunde, ab-VK, Rezeptur), Klick → Detail.
- **Rezeptur-Detail** zeigt Block „Kundenpreise" – mit Staffeln eine Zeile je Kunde×Datum×Menge
  (`module/rezeptur/detail.php`, Route `rezeptur_detail`).

## Daten
- **`angebot_scan`**: produkt_name, darreichungsform, stueck_je_packung, rezeptur_id, rezeptur_neu,
  kunde_id, kunde_neu, angebot_datum, vk (ab-/kleinste Staffel), staffeln_json, preise_json,
  zutaten_json, datei, original_orig, bemerkung, angelegt_von, angelegt.
- **`rezeptur_kundenpreis`**: rezeptur_id, kunde_id, datum, vk, stueck_je_packung, menge, preise_json,
  quelle ('angebotsscan'), scan_id, angelegt. **Eine Zeile je Staffel.**

## Funktionen (`core/schema.php`)
`angebotsscan_ki($pfad)` (nur lesen → strukturiertes Array inkl. Kunde/Datum/Staffeln),
`angebotsscan_speichern($d, $opt)` (nach dem Match: Rezeptur + Kunde + Kundenpreis je Staffel + Scan;
`$opt`: kunde_id, kunde_neu, datei, orig, benutzer_id, bemerkung),
`kunde_finden_oder_anlegen($firma, $nr)`, `angebotsscan_name_bereinigen($name)`.
Nutzt `rezeptur_finden_oder_anlegen(..., null)`.

## Route & Rechte
`?p=angebotsscan` → `public/index.php` (System-Gruppe im Menü). Rollen **admin, finance, sales**
(`core/auth.php`). Braucht die KI (Einstellungen → KI); ohne KI ist der Upload deaktiviert.
Original-Angebote werden **nicht** als Beleg/Dokument an einen Kunden gehängt – nur die Datei liegt
in `data/uploads` (für den Scan).
