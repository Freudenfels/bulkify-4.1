# produktion/detail.php – Produktionsauftrag (Stationen/Gates)

**Zweck:** Ein Produktionsauftrag wird hier Station für Station abgearbeitet – bis zu den beiden Freigabe-Gates.

**Stationen (je Darreichungsform):** Rohstoffe bereitstellen · Mischen · **Herstellung** (Verkapselung / Tablettierung / Abfüllung … je Form) · Verpacken · Etikettieren · Qualitätsprüfung · **Produktions-Freigabe** (Gate) · **Versand-Freigabe** (Gate). Definiert in `produktionsschritte_fuer()`.

**Was passiert hier:**
- **POST `aktion=erledigen`:** markiert die **nächste offene** Station als erledigt (Reihenfolge erzwungen – nur die erste offene Station hat einen Button). Danach wird der Status neu berechnet (offen / läuft / fertig). Ist alles erledigt (auch die Versand-Freigabe), wird der zugehörige **Auftrag auf „erledigt"** gesetzt und ein Verlaufseintrag am Kunden geschrieben.
- **Anzeige:** Kennzahlen (Status, Fortschritt X/N, Menge, Produkt) und die Stationen als Ablaufliste: erledigte mit Häkchen + Zeit, die aktuelle mit Button, kommende ausgegraut. Freigaben sind als **Gate** markiert.

**Herkunft:** Wird von `auftrag_aus_angebot()` zusammen mit Auftrag + Rechnung automatisch erzeugt (Stationen aus der Darreichungsform der Rezeptur).

**Materialbedarf & Bestand (FEFO):**
- Panel **Materialbedarf** zeigt je Rohstoff benötigte vs. verfügbare Menge (benötigt = Zutat-mg × Einheiten/Packung × Menge). „fehlt X" wenn zu wenig.
- **Rohstoffbedarf nur auf dem vollen Weg:** Der Panel „Materialbedarf" (Rezeptur-Rohstoffe) erscheint nur, wenn der Auftrag die Herstellungsschritte enthält (`$istVollerWeg`). Bei **Fremdproduktion** (zugekaufte Bulkware) werden die Rohstoffe nie angefasst – sie dort als „fehlt" auszuweisen führte zu unnötigen Bestellungen und ließ den Auftrag blockierter aussehen, als er ist. Bereits **entnommene** Chargen bleiben immer sichtbar (Rückverfolgung), auch wenn der Weg später umgestellt wurde.
- Die Station **„Rohstoffe bereitstellen" ist ein Gate**: Abschluss blockiert, solange ein Rohstoff fehlt (Warnung „Nicht genug Bestand"). Erst Wareneingang buchen.
- Beim erfolgreichen Abschluss werden die Rohstoffe **nach FEFO** (älteste MHD zuerst) aus den freien Chargen entnommen (`produktion_rohstoffe_entnehmen`), der Bestand sinkt, leere Chargen werden „leer". Jede Entnahme ist chargengenau in `produktion_verbrauch` protokolliert und erscheint als **„Entnommene Materialien"**.
- Die Station **„Verkapselung"** (nur Hartkapsel-Produkte) bucht die **Leerkapseln** ab (Menge × Einheiten je Packung = Gesamt-Kapseln, `produktion_kapseln_entnehmen`, FEFO, Gate bei zu wenig Bestand). Welche Leerkapsel: `produkt_leerkapsel_id()` – manuelle Wahl am Produkt hat Vorrang, sonst automatisch die eindeutige Kapsel der passenden Größe. Der Bedarf erscheint im Materialbedarf als Extra-Zeile mit Badge „Kapselhülle" (separat berechnet, damit nicht doppelt bei „Rohstoffe bereitstellen" abgebucht wird).
- Die Station **„Verpacken"** bucht analog die **Verpackung** ab (1 Gebinde je Packung, `produktion_verpackung_entnehmen`, FEFO, ebenfalls Gate bei zu wenig Bestand).
- Ist der Auftrag **fertig** (Versand-Freigabe erledigt), wird die **Fertigware als Bestand eingebucht** (`produktion_fertigware_einbuchen`): ein Lager-Artikel der Kategorie „Verkaufsfertig" zum Produkt (`produkt_lageritem`) bekommt eine Charge (Charge-Nr. = PR-Basis + Buchstabe, z. B. `2696.A`; Menge = der noch **offene Rest** der Produktionsmenge). Panel „Fertigware eingebucht" mit allen Chargen und Link zum Lager. Der Auftrag geht auf „erledigt".

## Adaptiver Produktionsweg (Baustein 5)

Der Weg richtet sich nach der **Beschaffung**: Ist für den Auftrag **fertige Bulkware** eingegangen (Charge eines Items der Kategorie `fertig`, `produktion_ist_zukauf($auftrag_id)`), entfallen **Rohstoffe bereitstellen, Mischen und Verkapseln** – die Route ist dann `Fertigware bereitstellen · Verpacken · Etikettieren · Qualitätsprüfung · Produktions-Freigabe · Versand-Freigabe` (aus `produktionsschritte_fuer($form, $zukauf=true)`).

Auf der PA-Detailseite erscheint bei erkanntem Zukauf ein **Banner „Fertige Bulkware erkannt – verkürzter Weg möglich"** mit Button **Verkürzten Weg anwenden** (`aktion=weg_zukauf`). Umgekehrt kann auf den vollen Weg zurückgestellt werden (`aktion=weg_voll`). Beides über `produktion_schritte_regenerieren($pa_id,$zukauf)` – nur solange **kein Schritt erledigt** ist (sonst Hinweis). Kein manuelles Make-or-Buy-Flag nötig; die Info steckt in Kategorie/Form der eingegangenen Ware.

## Produktionsbereitschaft (Baustein 3)

`produktion_bereitschaft($pa_id)` prüft, ob das Material komplett da ist: bereit | wartet | laeuft | fertig. Rohstoffe (`produktion_materialbedarf`) + Leerkapseln, bei Zukauf stattdessen die freie fertig-Charge. Kachel „Bereitschaft" + Panel „Wartet auf Material" (Fehlliste benötigt/verfügbar/fehlt). Auch in Liste/Cockpit als Badge (`bereitschaft_badge()`) + Filter „Nur produktionsbereite".

## Geführte Produktion mit Scan (Baustein 6)

Oben ein Panel **„Jetzt dran – Schritt x von n"** mit Klartext-Anweisung (`station_anleitung()`) und – bei Material-Schritten – einem **Scan-Feld** (Charge scannen/eingeben). Beim Erledigen prüft `produktion_scan_pruefen($scan,$kat)` die Charge (existiert, frei, richtige Kategorie/Form: Rohstoffe→rohstoff, Verkapselung→kapselhuelle, Verpacken→verpackung, Fertigware bereitstellen→fertig). Gescannte Charge wird an `produktion_schritt.scan_charge` gespeichert und in der Stationsliste angezeigt. Falsche Charge → Meldung „Scan abgelehnt". Die Stationsliste selbst ist nur noch Übersicht (Aktion läuft über das geführte Panel).

## Zukauf-Entnahme (Feinschliff Baustein 5)

Station „Fertigware bereitstellen" bucht die zugekaufte fertige Bulkware FEFO ab (`produktion_fertigware_entnehmen`, Chargen der Kategorie `fertig` für den Auftrag) und schreibt `produktion_verbrauch`.

## Geplantes Datum (Baustein 2)

Kachel „Geplant am" (`aktion=geplant`) setzt `produktionsauftrag.geplant_am`; siehe [kalender.md](kalender.md).

## Teilproduktion (.A / .B / .C)

Wird an mehreren Tagen produziert, bucht das Panel **„Teilmenge einbuchen"** (`aktion=teilmenge`, Menge, MHD, Notiz) jede fertige Teilmenge sofort als eigene Charge ins Fertigwarenlager – `produktion_teilmenge_einbuchen()` in `core/schema.php`. Die Chargennummer ist die PR-Basis mit dem nächsten Buchstaben (`charge_naechste_nr()`), das MHD standardmäßig heute + Standardmonate. Es kann nie mehr gebucht werden, als noch offen ist (`produktion_rest()` = Produktionsmenge minus Summe der Chargen).

Das Panel erscheint, solange noch etwas offen ist. Beim Abschluss des Auftrags (letzte Station) bucht `produktion_fertigware_einbuchen()` nur noch den Rest; ist schon alles gebucht, passiert nichts. Das Panel „Fertigware eingebucht" zeigt alle Chargen mit gebuchter Menge, Lagerbestand, MHD und Datum, die Kachel „Chargen" die erste Nummer plus die Anzahl weiterer.
