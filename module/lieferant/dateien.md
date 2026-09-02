# lieferant/dateien.php – Dateiablage im Lieferantenportal

**Route:** `?p=lieferant_dateien` (nur für angemeldete Lieferanten).

**Was man sieht:** alle Dateien zu diesem Lieferanten – die gemeinsame Ablage (von bulkify oder vom Lieferanten hochgeladen) plus die CoA/Spezifikationen, die er an Preisanfragen abgelegt hat. Jede Datei mit Art, Titel, Bezug, Herkunft und Datum; Download über `?p=lieferant_dokument&id=`. Darunter das Upload-Formular (Art, Titel, Datei; PDF, Bilder, Office, CSV, TXT, ZIP bis 15 MB).

**POST:** `aktion=dok_upload` → `lieferant_datei_upload()`, `aktion=dok_del` → `lieferant_datei_loeschen()` (nur eigene Uploads). Alles aus `core/lieferant_dateien.php`. Die `lieferant_id` kommt aus dem Login.

Das Team sieht dieselbe Liste im Lieferantenkonto unter „Dokumente" (`module/lieferant/detail.php`).
