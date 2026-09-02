# lieferant_dateien.php – Dateiablage je Lieferant

## Wozu
Zertifikate (ISO, HACCP, Bio), Spezifikationen, CoA und sonstige Unterlagen zu einem Lieferanten liegen an **einer** Stelle, die beide Seiten sehen: das Team im Lieferantenkonto (Reiter „Dokumente"), der Lieferant im Portal (Menüpunkt „Dateien"). Beide können hochladen.

## Datenmodell
Keine neue Tabelle: die vorhandene `dokument` mit `objekt_typ='lieferant'`, `objekt_id=lieferant_id`, dazu die Spalte `hochgeladen_von` (`team` | `lieferant`, per `ensure_column`). Die Liste zeigt zusätzlich die CoA/Spezifikationen, die der Lieferant an **Artikeln** abgelegt hat (Preisanfrage: `objekt_typ='item'`, `lieferant_id`). Dateien liegen in `BX_UPLOADS` (außerhalb von `public`), Name `lieferant_<id>_<zufall>.<ext>`.

## Funktionen
- `lieferant_datei_upload($lieferant_id, $von, $sprache)` – verarbeitet `$_FILES['dok']` + `dok_typ`, `dok_titel`. Erlaubt: PDF, Bilder, Office, CSV, TXT, ZIP, bis 15 MB. Rückgabe `''` oder Fehlertext.
- `lieferant_dateien($lieferant_id)` – die Liste (Ablage + Artikel-Dokumente des Lieferanten).
- `lieferant_darf_datei($lieferant_id, $dok_id)` – Zugriffsprüfung für die Download-Route im Portal (`module/lieferant/dokument.php`).
- `lieferant_datei_loeschen($lieferant_id, $dok_id, $von, $sprache)` – das Team darf alles aus der Ablage löschen, der Lieferant nur eigene Uploads.
- `lieferant_dateien_panel($lieferant_id, $wer, $sprache)` – Liste + Upload-Formular als HTML (de/en/zh). Download intern über `?p=dokument&id=`, im Portal über `?p=lieferant_dokument&id=`.

## Dokumenttypen
`dokument_typen()` in `core/dokument_ui.php` kennt jetzt zusätzlich `zertifikat`. Die englischen/chinesischen Beschriftungen stehen in `lieferant_datei_typ_label()`.

## Wo es hängt
Intern `module/lieferant/detail.php` (Reiter „Dokumente"), Portal `module/lieferant/dateien.php`. Jeder Upload schreibt einen Eintrag in den Aktivitätsverlauf des Lieferanten.
