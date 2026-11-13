# einkauf/mobil.php – Einkauf-Schnellansicht (mobil)

**Zweck:** Am Handy **schnell den Status von Bestellungen ändern** – ein Tap je Schritt. Route `?p=einkauf_mobil`, Menü **Einkauf → Schnell (mobil)** (und Button auf der Bestellliste).

**Was man tut:** Große, tippfreundliche Karten (max. 640px, volle Kartenbreite, große Buttons) in zwei Abschnitten:
- **Zu bestellen** (`status='offen'`, Entwürfe): Button **„Als bestellt markieren"** → `status='bestellt'` (setzt `bestelldatum` falls leer) und schickt – wenn eingerichtet – die Bestell-Mail an den Lieferanten (`mail_lieferant_bestellung`).
- **Unterwegs – auf Wareneingang wartend** (`status='bestellt'`): Button **„Ware eingegangen"** (grün, mit Rückfrage) → `bestellung_wareneingang($id)` bucht die Ware als Bestand ein und setzt `geliefert`.

Beides sind **dieselben Aktionen wie auf der Detailseite** (`einkauf/detail.php`), nur gross und mobil. Jede Karte hat einen dezenten Link „Details öffnen" bzw. „Details / Charge & MHD anpassen" zur vollen Bestellung (`?p=bestellung&id=…`), falls man Charge/MHD/Menge beim Wareneingang genauer erfassen will.

**Filter:** Suchfeld oben (Lieferant oder Bestellnummer). Gelieferte Bestellungen erscheinen nicht (die sind erledigt – im Bestellarchiv der Desktop-Liste).

**Technik:** POST-Handler prüfen den aktuellen Status serverseitig (nur `offen`→bestellt bzw. `bestellt`→geliefert), danach Redirect zurück auf `?p=einkauf_mobil&ok=…#b<id>` mit kurzer Erfolgsmeldung. Rechte: `einkauf` (core/auth.php), Route in `public/index.php`. Der Wareneingang bucht echten Bestand – deshalb die Sicherheits-Rückfrage.

„liefern“ (04.11.2026): Bestellungen mit Bulk-Freitext-Positionen werden nicht gebucht, Meldung „bitte in der Lager-App einbuchen“ (`ok=lager`).

## 10.11.2026
„Geliefert“ öffnet den Wareneingang mit der Position (`?p=wareneingang&bp=…`). Ein pauschales Einbuchen ohne Charge/MHD gibt es nicht mehr.

## Paket 12.11.2026 Teil 4 (Unterlagen-Regel)
„bestellt“ erst mit vollständigen Unterlagen.

**Knopf „Ware ist da - einbuchen“ (Sofortfix 13.11.2026):** führt in den Wareneingang (kein Bestätigen ohne Charge).
