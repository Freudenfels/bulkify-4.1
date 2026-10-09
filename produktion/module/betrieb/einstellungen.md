# produktion/module/betrieb/einstellungen.php
Einstellungen (`?p=einstellungen`): **nur Ansicht** von **Maschinen** und **Räumen** (zwei Reiter, .settabs). Gepflegt
werden sie jetzt **zentral im Dashboard** (Maschinenfuhrpark, `/?p=maschinen`) – ein Banner oben verlinkt dorthin.
POST-Aktionen sind hier neutralisiert (Hinweis + Redirect); die Produktion liest die Stammdaten weiter (QR-Scan je
Step), und die **Reinigungspläne** (`?p=reinigung`) bleiben hier. Gleiche Tabellen `pr_raum`/`pr_maschine` (geteilte DB).

**Maschinenfuhrpark (Spec 9.3):** Jede Maschine hat einen **Typ** (koppelt sie an den Produktionsschritt – Mischer beim Mischen, Kapselmaschine beim Verkapseln usw.) und einen **QR-Code** (leer = automatisch `MA-<id>`), der im Produktionsmodus je Schritt gescannt/gewählt wird. In der Maschinenliste lassen sich Typ und QR je Zeile direkt speichern (`maschine_typ`).
