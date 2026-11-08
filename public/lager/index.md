# public/lager/index.php – Einstieg des Lager-Programms

Front-Controller unter `/lager/`. Er lässt nur Routen aus der Whitelist zu:

- `plaetze`, `platz`, `zuordnen`, `leuchten`
- `sender` und `bruecke_skript`, nur für Admins
- `login` und `logout`
- `autologin`, nur lokal und mit dem `login_token` aus dem Dashboard

Route `versand_bereit` → `versand/bereit.php` (Soll-Kette 7).

Route `umlagern` → `bestand/umlagern.php` (Nach Lager 2, 04.11.2026).

- 08.11.2026: `?p=eingang` (alte Schnellmaske) leitet auf `?p=we` um (Soll-Handbuch 4.5).
