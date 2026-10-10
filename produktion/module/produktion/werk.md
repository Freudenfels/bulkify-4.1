# produktion/module/produktion/werk.php – Mitarbeiter-Vollbild-App (`?p=werk`)

Kiosk-/Tablet-App für die **Mitarbeiter**, rein **schrittweise**. Vollbild, dunkles Theme, große Touch-Buttons, **ohne Sidebar** und ohne die Leiter-Abkürzungen (keine „Teilmenge produzieren"). Eigener Einstieg (Route in `public/produktion/index.php`, von der Normal-Login-Weiche **ausgenommen**).

## Als App installieren (PWA, Vollbild am Tablet)
Die Werk-App ist eine **PWA**: `public/produktion/werk.webmanifest` (`display:"fullscreen"`, `scope:/produktion/`, `start_url:/produktion/?p=werk`, bulkify-Icons aus `/assets/app-icon-*`) + Service Worker `public/produktion/werk-sw.js` (Scope `/produktion/`, nur Icons gecacht, App-Seiten immer frisch). In `werk.php` sind Manifest-Link, `apple-mobile-web-app-*`/`mobile-web-app-capable`-Meta, Apple-Touch-Icon und die SW-Registrierung eingebunden. Auf dem **Login** erscheint ein **„Auf dem Tablet installieren"**-Button, sobald Chrome/Android es anbietet (`beforeinstallprompt`); iOS: Teilen → Zum Startbildschirm. Installiert startet die App **im Vollbild ohne Browser-Leiste** direkt in `?p=werk`.

## Login per PIN
Eigener **PIN-Login** (kein Team-Login). Die PIN setzt der Admin im Dashboard je Mitarbeiter (`benutzer.pin_hash`, siehe `system/benutzer_detail.php`). On-Screen-Ziffernblock (JS) → `aktion=werk_login` → `erp_benutzer_per_pin()` (sucht aktive Benutzer mit production/admin-Rolle + passendem Hash). Treffer setzt `$_SESSION['werk_uid']`. „Abmelden" = `werk_logout`. Der angemeldete Name wird als **Akteur** bei „erledigt" protokolliert.

## Drei Ansichten
1. **PIN-Login** (keine `werk_uid`): Ziffernblock, 4–8 Stellen, OK.
2. **Kachel-Liste** (`?p=werk`): große Karten der **produzierbaren** (`erp_pa_bereitschaft`=bereit) + **laufenden** Aufträge; laufend zuerst, dann nach geplantem Termin. Karte zeigt Nr., Produkt, Menge, Kunde, Status-Badge, Fortschrittsbalken + „Schritt X / Y". Tippen → Schritt-Ansicht.
3. **Schritt-Ansicht** (`?p=werk&id=<pa>`): Kopf (Nr./Produkt/Menge/Schritt), der **aktuelle Schritt** groß (Station + Anleitung via `station_anleitung_text`), Material **„Aus dem Lager holen"** (`erp_schritt_material`: Material/Menge/Bestand, Knappheit rot), großer **„Erledigt – nächster Schritt"**-Button und darunter der **Ablauf** (erledigt ✓ / aktuell / offen). Alle Schritte fertig → **„Fertig ✓"** + zurück zur Liste.

## Mischen: Mischbehälter einzeln
Beim Schritt **Mischen** zeigt die App „Gesamt anzumischen" (kg + Einheiten), ein Feld „kg je Mischbehälter" (+ „Behälter berechnen", GET `cap`) und dann **jeden Behälter einzeln** als Karte „Mischbehälter N / gesamt" mit den **kg je Rohstoff** (`erp_mischer_plan`). Beim „Erledigt" des Mischens werden je Behälter Gebinde-Unterchargen angelegt (`erp_prod_charge_fuer_station` + `erp_mischer_unterchargen_anlegen`), ein Etikett je Behälter (FEFO beim Abfüllen).

## Rohstoff-Rückgabe nach dem Mischen (Teil B)
Nach dem abgeschlossenen **Mischen** schaltet die App einen **Zwischenschritt „Rohstoff zurück ins Lager"** davor (vor dem nächsten echten Schritt): je verbrauchter **Rohstoff-Charge** (`erp_rohstoff_rueckgabe_offen`, aus `produktion_verbrauch`, nur Kategorie rohstoff) trägt der Mitarbeiter das **zurückgelegte Rest-Gewicht** ein. `erp_rohstoff_rueckgabe_speichern` setzt `charge.menge_verfuegbar` = Rückgabe (Bestand = physischer Rest; Verbrauch = vorher − zurück) und ein Flag `rohstoff_rueckgabe_done` (pr_daten), damit nicht erneut gefragt wird. **Blinker/Lagerplatz bleiben gleich** (dieselbe Charge). Modell: ganze Charge geholt, Rest zurück; kleine Reste (< ~500 g) können verworfen werden (0 eintragen).

## Chargenprobe je Rohstoff vor dem Mischen (Teil C)
Solange der aktuelle Schritt **Mischen** ist, schaltet die App einen **Zwischenschritt „Rohstoff-Probe ziehen"** davor: je verwendeter **Rohstoff-Charge** (FEFO vom Schritt „Rohstoffe bereitstellen", `erp_rohstoff_proben_status`) muss eine **Chargenprobe** erfasst sein. Fehlt sie, zeigt die App je Charge einen Button „Probe gezogen" → `erp_rohstoff_probe_ziehen` legt eine `prod_probe` (Ebene `rohstoff`, pa_id + charge_id) an. Erst wenn alle Chargen eine Probe haben, erscheint der Mischen-Schritt. Da die Probe **pro Produktionsauftrag** erfasst wird, verlangt jede neue Produktion automatisch frische Proben – also auch, wenn eine Charge nach dem Zurücklegen wieder aus dem Lager verwendet wird.

## Pflicht-Gates (schlank)
„Erledigt" postet `aktion=werk_erledigt` → **`erp_schritt_abschliessen($schritt_id, $name)`** – dieselbe Logik wie `?p=run`: Reihenfolge-Prüfung, Vorbereitungs-Sperre, **Material-FEFO-Abbuchung mit Mangel-Schutz** (fehlt Pflicht-Material, ist der Button gesperrt + Hinweis). Bewusst **ohne** die Leiter-Extras (Maschinenauswahl/Reinigung/Klima/Mischer-Plan/FEFO-Gebinde) – die laufen weiter über `?p=run` (Produktionsleiter). *Offen/nächster Ausbau:* Maschinen-Reinigungs-Gate auch in die Mitarbeiter-App holen, falls gewünscht.

Die Fertigware wird beim **letzten Schritt** eingebucht (durch `erp_schritt_abschliessen`).
