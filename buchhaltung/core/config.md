# config.php — Buchhaltungs-Programm
Grundeinstellungen der Sub-App /buchhaltung/: BX_ROOT/BX_MARKE/BX_TITEL, geteilte secrets.php (wie Dashboard/Lager/Produktion), DB_*-Defaults, BX_UPLOADS (Dashboard-data/uploads, geteilt), eigene Sitzung `BX_SESSION=BXBUCH`, `ist_lokal()` (Autologin nur lokal). Muster wie produktion/core/config.php.

## BX_DATA definiert (Stand 2026-10-08)
`BX_DATA` (= `dirname(BX_ROOT).'/data'`, geteilt mit dem Dashboard) wird jetzt in config.php definiert. `ki_log()` in `core/ki.php` schreibt nach `BX_DATA/ki.log`; ohne die Definition stürzte JEDER KI-Aufruf in der Buchhaltung (freie Rechnung, Belege-KI, Alt-Rechnungen) mit „Undefined constant BX_DATA" ab.
