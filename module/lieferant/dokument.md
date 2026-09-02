# lieferant/dokument.php – Datei-Download im Lieferantenportal

**Route:** `?p=lieferant_dokument&id=<dokument.id>` (nur für angemeldete Lieferanten).

Liefert eine Datei aus `BX_UPLOADS` aus – aber nur, wenn sie diesem Lieferanten gehört: entweder aus seiner Ablage (`objekt_typ='lieferant'`) oder ein CoA/eine Spezifikation, die er selbst an einem Artikel abgelegt hat (`objekt_typ='item'` mit seiner `lieferant_id`). Die Prüfung macht `lieferant_darf_datei()` in `core/lieferant_dateien.php`; fremde IDs liefern 404.

Der interne Download für das Team bleibt `?p=dokument&id=` (`module/lager/dokument_download.php`).
