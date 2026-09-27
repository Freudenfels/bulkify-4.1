# public/lager/index.php – Einstieg des Lager-Programms

Front-Controller unter `/lager/`. Er lässt nur Routen aus der Whitelist zu:

- `plaetze`, `platz`, `zuordnen`, `leuchten`
- `sender` und `bruecke_skript`, nur für Admins
- `login` und `logout`
- `autologin`, nur lokal und mit dem `login_token` aus dem Dashboard
