# produktion/module/betrieb/reinigung.php
Reinigungspläne (Werk, `?p=reinigung`): wiederkehrende Reinigungen je Bereich/Maschine anlegen (Titel, Bereich, Intervall, Hinweis), als **gereinigt** markieren (Datum + Bediener) und entfernen. Eigene Tabelle `pr_reinigung` (Helfer `pr_reinigung_*` in core/schema.php). „noch nie"-Badge, wenn letzte_reinigung leer.
