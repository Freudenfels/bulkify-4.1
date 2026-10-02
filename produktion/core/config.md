# produktion/core/config.php
Grundeinstellungen des Produktions-Programms: DB-Zugang über **dieselbe secrets.php** wie Dashboard/Lager (Reihenfolge: `produktion/secrets.php` → `BULKIFY_SECRETS` → Projektstamm), Sitzung `BXPROD`, Zeitzone UTC, `BX_UPLOADS` zeigt auf die Dashboard-Uploads. Helfer: `ist_lokal()`, `jetzt_utc()`. Siehe [PRODUKTION.md](../PRODUKTION.md).
