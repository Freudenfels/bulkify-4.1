# system/druck_job.php – Druckauftrag in die Warteschlange (`?p=druck_job`)

Legt einen Druckjob in `lg_druckjob` ab; die Brücke auf dem Lager-PC holt ihn und druckt lautlos
(SumatraPDF) auf den je Dokument eingestellten Drucker. Per `fetch` (POST) aufgerufen.

Parameter `typ`:
- **etikett** (Standard): `ids=<charge-ids>` → Karton-Etikett (100×150), Drucker `drucker_name`.
- **lieferschein**: `id=<versand-id>` → Lieferschein A4, Drucker `drucker_lieferschein`.
- **label**: `id=<versand-id>` → vom Carrier erzeugtes Versand-Label, Drucker `drucker_versandlabel`.
- **zoll**: `id=<versand-id>` → CN23-Zollpapier (A4, Nicht-EU), Drucker `drucker_lieferschein`.

Antwort JSON `{ok,id,meldung}` (meldet, ob die Brücke gerade läuft). Die PDF-Erzeugung + Druckerwahl je
Typ passiert in `public/lager/bruecke.php`.
