# lager/module/system/api_druck.php – interner Druck-Auslöser (Loopback)

Analog zu `led/api_blink.php`, aber für **Druckaufträge**. Lässt **andere Programme** (v. a. die Produktions-/Werk-App) einen Druckjob in die Lager-Warteschlange legen, ohne Lager-Login.

- **Route:** `?p=api_druck` – in `public/lager/index.php` **vor dem Login-Gate** eingehängt (wie `api_blink`).
- **Auth:** gemeinsamer Token `LG_BLINK_TOKEN` **oder** Loopback (`REMOTE_ADDR` 127.0.0.1/::1). Sonst 403.
- **Eingang:** `typ` (`probe` oder `gebinde`), `id` (bei `probe` = `prod_probe.id`, bei `gebinde` = Gebinde-Untercharge `prod_charge.id`). Legt per INSERT einen `lg_druckjob` an (`format='klein'`, `status='offen'`). **Keine** PDF hier – die erzeugt die Brücke beim Poll (`public/lager/bruecke.php`, `elseif ($typ==='probe') → lg_probe_etikett_pdf()`), gedruckt auf `drucker_probe` (lg_meta, in den Lager-Einstellungen wählbar; leer = Standarddrucker).
- **Antwort:** `{ok, job, bruecke_wach, meldung}`. `bruecke_wach` = Brücke-Lebenszeichen < 15 s.

Aufruf aus der Produktion über `pr_lager_druck_probe($probe_id)` in `produktion/core/erp.php` (nutzt dieselbe Loopback-Kette wie `pr_lager_blink`). Die Werk-App ruft das beim „Probe gezogen" auf und markiert die Probe danach als `etikett_gedruckt`.
