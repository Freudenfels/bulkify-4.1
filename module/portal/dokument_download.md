# portal/dokument_download.php – Dokument-Download fürs Kundenportal

**Zweck:** Liefert ein CoA/Spec/Analyse-Dokument an einen Portal-Kunden aus. Route `?p=portal_dok&token=<Portal-Token>&id=<Dokument-ID>`.

**Warum eine eigene Route:** Die interne Route `?p=dokument` steckt hinter dem Mitarbeiter-Login und kennt keine Freigaben. Das Portal ist dagegen öffentlich (Token statt Login) – deshalb prüft diese Datei **selbst**, und zwar drei Dinge:

1. **Gültiges Portal-Token** – sonst 403 „Zugang ungültig".
2. **`dokument.kunde_sichtbar = 1`** – nicht freigegebene Dokumente gibt es nicht, auch nicht mit gültigem Token (404 „Dokument nicht verfügbar"). So bleiben Lieferanten-Spezifikationen intern.
3. **Passende Bereichs-Freischaltung** des Kunden (`kunden.portal_*`) – Rohstoff-Dokumente brauchen Rohstoffe, Rezepturen oder Produkte, Produkt-Dokumente den Produkt-Bereich. Sonst 403.

Die Datei selbst liegt in `data/uploads`, also außerhalb des Web-Ordners; ausgeliefert wird sie mit erkanntem MIME-Typ und Originalnamen.

**Verwandt:** `core/dokument_ui.php` (Freigabe setzen), `module/portal/kunde.php` (zeigt die Links im Rohstoff- und Rezeptur-Detail).
