# public/lager/bruecke.php – Schnittstelle für die Brücke

- `GET ?token=...` liefert offene Leuchtbefehle als fertige URLs: `{"befehle":[{"id":..,"url":"http://ip/light?code=..."}]}`. Jeder Befehl wird dabei als `abgeholt` markiert. Befehle, die älter als 30 Sekunden sind, werden verworfen.
- `POST ?token=...` mit `id`, `ok` und `antwort` meldet das Ergebnis zurück.

Der `GET` liefert zusätzlich **Druckjobs** (`"druck":[{id,pdf_b64,drucker}]`): je `lg_druckjob.typ` wird das
richtige PDF erzeugt und der passende Drucker gewählt – **etikett** (`lg_etikett_pdf`, `drucker_name`),
**lieferschein** (`lg_lieferschein_pdf`, `drucker_lieferschein`), **label** (gespeichertes Versand-Label aus
`lg_versand_label`, `drucker_versandlabel`); fehlt der spezielle Drucker, greift der Etikett-Drucker. Die
Rückmeldung eines Druckjobs kommt per `POST` mit `druck_id`, `ok`, `antwort`. Der Druck selbst (SumatraPDF)
ist in `bruecke/bruecke.ps1`.

Jeder Aufruf gilt als Lebenszeichen der Brücke. Geschützt ist das Ganze durch den Schlüssel in `lg_meta.bruecke_token`.

Druckjob-Typ **karton** (Soll-Kette 6) → `lg_produktion_karton_pdf()` auf `drucker_karton` (sonst Standard-Etikettendrucker).

Druckjob-Typ **palette** (Soll-Kette 7b) → `lg_paletten_etikett_pdf()` auf den Etikettendrucker.

**Inventur (11.11.2026):** Druckjobs vom Typ `platz` (Platz-Etiketten) und `inventur` (Klärfall-Etikett) werden über `lager/core/inventur_etikett.php` erzeugt.
