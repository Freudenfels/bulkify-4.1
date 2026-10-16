# module/dienstleistung/angebot.php - Redirect-Stub

**DL-Angebote werden jetzt in der regulären Angebotsmaske bearbeitet** (`module/angebot/detail.php`),
damit Dienstleistung **und** Produkte (Gläser, Etikett, …) in **einem** regulären Angebot zusammengeführt
werden können. Die Route `?p=dl_angebot` zeigt in `public/index.php` direkt auf `angebot/detail.php`.

Diese Datei ist nur noch ein **Stub**: Sie fängt alte Direkt-/Bookmark-Aufrufe von `?p=dl_angebot&id=<ID>`
ab und leitet auf `?p=angebot&id=<ID>` um (bzw. ohne ID auf die DL-Angebotsliste `?p=dl_angebote`).

DL-Angebote sind weiterhin `angebot`-Zeilen mit `kategorie='dienstleistung'` (Nummernkreis DA-) und erscheinen
in der getrennten DL-Liste `?p=dl_angebote`. Die DL-spezifischen Teile (Kundenwunsch-Panel, DL-Katalog beim
Position-Hinzufügen mit `dl_position_add` inkl. `dienstleistung_id` + Kundenpreis) sind in `angebot/detail.php`
eingebaut. Summen/Logik bleiben in `core/dienstleistung.php`.
