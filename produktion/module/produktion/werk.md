# produktion/module/produktion/werk.php – Mitarbeiter-Vollbild-App (`?p=werk`)

Kiosk-/Tablet-App für die **Mitarbeiter**, rein **schrittweise**. Vollbild, dunkles Theme, große Touch-Buttons, **ohne Sidebar** und ohne die Leiter-Abkürzungen (keine „Teilmenge produzieren"). Eigener Einstieg (Route in `public/produktion/index.php`, von der Normal-Login-Weiche **ausgenommen**).

## Login per PIN
Eigener **PIN-Login** (kein Team-Login). Die PIN setzt der Admin im Dashboard je Mitarbeiter (`benutzer.pin_hash`, siehe `system/benutzer_detail.php`). On-Screen-Ziffernblock (JS) → `aktion=werk_login` → `erp_benutzer_per_pin()` (sucht aktive Benutzer mit production/admin-Rolle + passendem Hash). Treffer setzt `$_SESSION['werk_uid']`. „Abmelden" = `werk_logout`. Der angemeldete Name wird als **Akteur** bei „erledigt" protokolliert.

## Drei Ansichten
1. **PIN-Login** (keine `werk_uid`): Ziffernblock, 4–8 Stellen, OK.
2. **Kachel-Liste** (`?p=werk`): große Karten der **produzierbaren** (`erp_pa_bereitschaft`=bereit) + **laufenden** Aufträge; laufend zuerst, dann nach geplantem Termin. Karte zeigt Nr., Produkt, Menge, Kunde, Status-Badge, Fortschrittsbalken + „Schritt X / Y". Tippen → Schritt-Ansicht.
3. **Schritt-Ansicht** (`?p=werk&id=<pa>`): Kopf (Nr./Produkt/Menge/Schritt), der **aktuelle Schritt** groß (Station + Anleitung via `station_anleitung_text`), Material **„Aus dem Lager holen"** (`erp_schritt_material`: Material/Menge/Bestand, Knappheit rot), großer **„Erledigt – nächster Schritt"**-Button und darunter der **Ablauf** (erledigt ✓ / aktuell / offen). Alle Schritte fertig → **„Fertig ✓"** + zurück zur Liste.

## Pflicht-Gates (schlank)
„Erledigt" postet `aktion=werk_erledigt` → **`erp_schritt_abschliessen($schritt_id, $name)`** – dieselbe Logik wie `?p=run`: Reihenfolge-Prüfung, Vorbereitungs-Sperre, **Material-FEFO-Abbuchung mit Mangel-Schutz** (fehlt Pflicht-Material, ist der Button gesperrt + Hinweis). Bewusst **ohne** die Leiter-Extras (Maschinenauswahl/Reinigung/Klima/Mischer-Plan/FEFO-Gebinde) – die laufen weiter über `?p=run` (Produktionsleiter). *Offen/nächster Ausbau:* Maschinen-Reinigungs-Gate auch in die Mitarbeiter-App holen, falls gewünscht.

Die Fertigware wird beim **letzten Schritt** eingebucht (durch `erp_schritt_abschliessen`).
