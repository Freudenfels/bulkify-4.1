# system/druck_job.php – Etikett-Druckauftrag in die Warteschlange (`?p=druck_job`)

POST (eingeloggt, auch PDA): `ids` (Charge-IDs, komma), `format` (klein|gross) → legt einen Eintrag in
`lg_druckjob` (Status offen). Die kombinierte Brücke auf dem Lager-PC holt den Job über
`bruecke.php` ab und druckt lautlos (SumatraPDF). Antwort JSON `{ok,id,meldung}` – meldet auch, ob die
Brücke gerade läuft (lg_meta `bruecke_zuletzt`). Drucker-Name in lg_meta `drucker_name`.
