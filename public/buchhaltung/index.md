# public/buchhaltung/index.php — Front Controller
Einziger Web-Einstieg der Buchhaltung (/buchhaltung/). Whitelist-Routen → buchhaltung/module/*. Session BXBUCH, bu_schema() (Finanz-DDL), Login-Gate, Autologin nur lokal. Default-Route buchhaltung (Finanz-Hub). Muster wie public/produktion/index.php.

13.11.2026: Route `lief_rechnung_pruefen` (Eingangsrechnungen zu prüfen).
