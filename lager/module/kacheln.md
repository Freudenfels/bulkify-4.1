# kacheln.php – Kachel-Seiten der Lager-App

**Routen:** `umbuchen`, `versand_start`, `lager1`, `lager2` (alle auf diese Datei, `public/lager/index.php`). Stand 08.11.2026.
Gleiche Optik wie „Einbuchen“ (`?p=we`): Titel, kurzer Satz, Kacheln mit Titel + Untertitel (CSS `.lg-kacheln/.lg-kachel` in `public/lager/assets/lager.css`).
Jede Kachel führt auf eine **vorhandene** Seite – hier wird nichts neu gebaut, nur zusammengeführt.

| Seite | Kacheln |
|---|---|
| Umbuchen | Einlagern (`einlagern`) · Nach Lager 2 (`umlagern`) · Kisten (`kisten`) |
| Versand | Versandbereit (`versand_bereit`, Zähler = versandbereite Aufträge) · Warenausgang (`versand`) · Lieferschein erstellen (`lieferschein_neu`) |
| Lager 1 | Bestand (`bestand`) · Quarantäne (`quarantaene`, Zähler = `lg_quarantaene_zahl()`) |
| Lager 2 | Fremdlager-Bestand (`l2_bestand`) · Artikel (`l2_artikel`) |

Zähler erscheinen nur, wenn die Zahl > 0 ist (grüner Kreis am Titel, Text im Untertitel).
