# system/druck_job.php – Druckauftrag in die Warteschlange (`?p=druck_job`)

Legt einen Druckjob in `lg_druckjob` ab; die Brücke auf dem Lager-PC holt ihn und druckt lautlos
(SumatraPDF) auf den je Dokument eingestellten Drucker. Per `fetch` (POST) aufgerufen.

Parameter `typ`:
- **etikett** (Standard): `ids=<charge-ids>` → Karton-Etikett (100×150), Drucker `drucker_name`.
- **lieferschein**: `id=<versand-id>` → Lieferschein A4, Drucker `drucker_lieferschein`.
- **label**: `id=<versand-id>` → vom Carrier erzeugtes Versand-Label, Drucker `drucker_versandlabel`.
- **zoll**: `id=<versand-id>` → CN23-Zollpapier (A4, Nicht-EU), Drucker `drucker_lieferschein`.
- **palette**: `id=<versand-id>` → Paletten-Etiketten 100×150 (eins je Palette), Etikettendrucker im Format groß (Soll-Kette 7b).

Antwort JSON `{ok,id,meldung}` (meldet, ob die Brücke gerade läuft). Die PDF-Erzeugung + Druckerwahl je
Typ passiert in `public/lager/bruecke.php`.

**Inventur (11.11.2026):**
- `typ=platz` mit `ids=<platz-ids>`: Platz-Etiketten 100 × 70 (`inv_platz_etikett_pdf`).
- `typ=inventur` mit `id=<zaehlung-id>`: Etikett für ein Gebinde ohne Etikett (Klärfall, `inv_klaerfall_etikett_pdf`). Es geht auf den Gebinde-Drucker.
