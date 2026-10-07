# rezeptur/umbau.php – Worklist „Rezepturen überarbeiten"

Route `?p=rezeptur_umbau` (Rollen production/labor, Admin ohnehin). Listet alle Rezepturen, deren Zutaten
**nicht sauber auf die echten Lager-Rohstoffe gematcht** sind – genau die liefern leere Nährwerte und hängen
keine Spec/CoA automatisch dran. Datenquelle: `rezepturen_zu_ueberarbeiten()` (core/schema.php). Je Rezeptur
werden drei Probleme gezählt:

- **nicht zugeordnet** (`frei`): Zutat ohne `item_id` (Freitext aus Import).
- **Rohstoff fehlt** (`tot`): `item_id` zeigt ins Leere oder auf ein Item, das kein Rohstoff ist.
- **ohne Wirkstoffdaten** (`ohne_wirkstoff`): Rohstoff ist zugeordnet, hat aber kein `item_wirkstoff` → keine Nährwerte.

Pro Zeile ein **Überarbeiten**-Button (nur Admin, nur bei freigegeben/eingefroren): POST `ueberarbeiten_start`
an `rezeptur_detail`, das die Rezeptur temporär entsperrt (siehe detail.md → Überarbeitungsmodus). Bei nicht
gesperrten Rezepturen stattdessen ein normaler „Bearbeiten"-Link auf `#zutaten`.

Einstieg zusätzlich aus der Rezeptur-Liste: Button **„Überarbeiten (N)"** im Kopf, sobald N>0
(`liste.php`, zählt über dieselbe Funktion).
