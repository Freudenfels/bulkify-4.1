# belegeingang.php — Beleg-Posteingang (KI)

Eigener Beleg-Posteingang fuer den Steuerberater (Tabelle `bu_beleg_eingang`, via `be_init()`), unabhaengig von Eingangsrechnungen/Kreditoren.

## Funktionen
- Dateien: `be_uploads_dir()`, `be_datei_speichern($file)` (PDF/JPG/PNG/WEBP/HEIC/GIF, max 25 MB, Ablage BX_UPLOADS/belege/JJJJ-MM/), `be_pfad($datei)`.
- KI: `be_ki_auslesen($pfad)` (ki_datei_frage → belegart/lieferant/beleg_nummer/datum/netto/ust/brutto/waehrung/kategorie).
- CRUD: `be_anlegen/be_update/be_status_setzen/be_get/be_liste/be_zaehlen`. Status neu→erfasst→verbucht (+verworfen).
- Foto-Token (Handy, ohne Login): `be_token()/be_token_neu()/be_token_ok()` (app_meta belege_upload_token).
- Export Steuerberater: `be_export_csv($von,$bis)` (Excel-CSV UTF-8+BOM) und `be_export_zip()` (CSV + alle Belegdateien).
- `bu_zip($files)`: eigener ZIP-Writer (Store/ohne Kompression) — OHNE ZipArchive-Extension (die fehlt hier). Validiert gegen Python zipfile.
- E-Mail (GERUEST): `be_mail_bereit()/be_mail_abholen()` holen Anhaenge per IMAP (braucht php-imap + app_meta belege_imap_host/user/pass[/port/ssl]). Noch nicht aktiv/getestet.
