# Übergabe – bulkify Dashboard 4.1

> Kurzer Stand zum Weiterarbeiten am nächsten PC (Laptop). Ergänzt `CLAUDE.md` (dort stehen die Dauer-Regeln).
> **Stand: 2026-09-01.**

## Zuerst am Laptop tun
1. **Git holen:** `git pull` (Branch `main`). Nur an EINEM PC gleichzeitig arbeiten.
2. **Lokale DB:** MariaDB/MySQL, DB `bulkify41`, User/Pass `bulkify`/`bulkify` (siehe `core/config.php`). Das Schema baut sich per `init_schema()` beim ersten Seitenaufruf selbst auf.
   - **Achtung:** Die **Daten** wandern NICHT über Git (nur Code). Der Laptop hat eine eigene, leere/andere DB. Demodaten bei Bedarf neu einspielen: **Einstellungen → Werkzeuge → „Demo-Testdaten einspielen"** (nicht-löschend, idempotent).
3. **Starten:** `php -S 127.0.0.1:8741 -t public` → http://127.0.0.1:8741
4. **Login:** admin@bulkify.local / admin (lokal). Live-Beta: siehe unten.

## Was am 01.09. gebaut wurde

**Kundenweg fertiggestellt**
- **Angebot als Auswahl:** Der Kunde sieht je Variante (Gruppe A, B, C im Editor) eine Zeile mit Größe, Verpackung, Anzahl Packungen und **Preis je Packung** – wie in v3 – und nimmt genau eine an. Beim Annehmen entsteht **erst dann** das Produkt (Rezeptur × Menge + Verpackung), danach Auftrag, Rechnung und Produktionsauftrag.
- **Verbindliche Annahme mit Unterschrift:** Rezeptur und Angebot nur über einen Bestätigungs-Dialog mit Pflicht-Haken, **AGB-Haken** und Namen. Name und Zeitpunkt werden gespeichert (`freigabe_name`, `freigabe_am`, `agb_version`); serverseitig geprüft, nicht nur im Dialog.
- **AGB versioniert** (`core/agb.php`, Einstellungen → AGB, Portalseite `v=agb`). Der mitgelieferte Text ist ein **Entwurf und muss anwaltlich geprüft werden**.
- **Anfrage absagen** („Nicht machbar") mit Pflicht-Begründung; ein noch nicht gesendeter Angebots-Entwurf wird verworfen und die Angebotsnummer freigegeben.
- **Angebots-PDF** über einen Knopf in der Kopfzeile und ein Download-Icon in Liste und Anfrage-Seite (`core/pdf_angebot.php`, Route `?p=angebot_pdf&id=`).
- Editor-Ärgernisse behoben: gelöschte Positionen bleiben gelöscht, die Konfiguration überlebt das Speichern, Kopfdaten eingeklappt, kein Sprung an den Seitenanfang, Gültigkeit = heute + 14 Tage.

**Lieferantenweg neu** (v3-Vorbild, siehe `module/lieferant/*.md`)
- **Bestell-PDF** deutsch/englisch je nach `lieferanten.sprache` (`core/pdf_bestellung.php`).
- **Ablauf an der Bestellung:** bestätigen mit Termin, Stationen (angenommen · Produktion · Qualität · versandbereit · versendet), Versanddaten. „versendet" nur mit Anbieter, Versandart und Sendungsnummer.
- **Lieferantenportal** mit eigenem Login: Einladung per einmaligem Token, Passwort setzt der Lieferant selbst. Er sieht Bestellungen, bestätigt Termine, pflegt den Fortschritt, trägt Tracking ein, beantwortet **Preisanfragen** mit Staffeln und lädt **CoA/Spec** direkt zum Artikel hoch. Alles zweisprachig.
- **Intern:** auf der Lieferantenseite Einladungslink erzeugen, Preisanfragen stellen und Angebote mit einem Klick als **EK-Staffeln** übernehmen.

## Was am 30.08. gebaut wurde
- **Tablette & Flüssig werden automatisch bepreist** (vorher „auf Anfrage"). Tablette: Behälter über das Tablettengewicht (Wirkstoffe + Presshilfsstoffe), EK = Rezeptur + Presshilfsstoffe statt Leerkapsel. Flüssig: Größe = **Füllvolumen in ml**, Behälter über `item.volumen_ml`, EK = Portionen × Rezeptur + Trägerflüssigkeit. Neue Stellschrauben in Einstellungen → Preise & Margen (Presshilfsstoffe % + EK/kg, Portionsvolumen ml, EK Trägerflüssigkeit/L, Flüssig-Füllvolumen-Raster). Die Behälter-Fassung hat jetzt eine **Spalte „Flüssig (ml)"** zum Pflegen.
- **Packari-EK für PET-Dosen und Weithalsgläser (100–250 ml)** eingetragen (Seed `seed_packari_behaelter`: Preise ohne Verschluss + Mengenstaffel) und die vier **Deckel mit Pressure-Seal-Einlage** (38/400 und 45/400, je weiß und schwarz) als eigene Verschluss-Artikel angelegt. Die acht Gebinde haben zusätzlich ein Start-Füllgewicht (~0,55 g/ml) bekommen, damit Pulver und Tabletten darin rechenbar sind.
- Packungsgrößen werden überall einheitlich beschriftet (`form_groessen_label()`): Stück / g / ml. Nebenbei behoben: Sticks wurden im Angebot als „30 g" statt „30 Sticks" ausgewiesen, und die Stick-Anfrage im Portal fragte Gramm, obwohl die Matrix nach Stückzahl rechnet.

## Was am 29.08. gebaut wurde (alles gepusht, läuft nach Push auf beta)
- **Chargennummer + MHD (Standard):** Fertigware bekommt automatisch `PR-Nummer + Tagesbuchstabe` (z. B. `2696.A`, Teilproduktionen `.B/.C`) und **MHD = Produktionsdatum + 18 Monate** (Einstellungen → Produktion änderbar). Steht schon vor der Buchung im Produktions-Detail (für die Geräte-Eingabe). Rückverfolgung über neue Spalte `charge.pa_id`.
- **Fremdproduktion = Standard:** neue Aufträge laufen auf dem verkürzten Weg (bereitstellen → verpacken → etikettieren → Prüfung/Freigaben). Im Produktions-Detail auf Eigenproduktion umstellbar.
- **Produktions-Liste in Reitern:** „Produktionsbereit" (Standard), „Wartet auf Material" (eigener Reiter), „Abgeschlossen".
- **Fix EK-Mengenstaffel:** „+ Staffel" fügte die Lieferant-Spalte nicht ein (Zeile verrutschte) → behoben.
- **Portal-Anfrage → „Im Angebots-Editor bauen":** Button legt ein verknüpftes Angebot an und springt in den Editor – funktioniert auch ohne berechenbare Preismatrix (Positionen manuell).
- **Pulver/Granulat nach Füllgewicht:** werden nach Gramm angeboten (Standard 150/300/500/1000 g, in Einstellungen → Preise & Margen), nicht mehr nach Kapsel-Stückzahl. Behob die leere Pulver-Matrix.

Frühere Sessions (bereits live): Login neu gestaltet + bulkify-Logo, Etikettenpreise (Labelisten) je Gebinde, Demo-Testset (Kunden/Produkte/Angebote/Aufträge inkl. Zukauf).

## Offene Punkte / als Nächstes
- **AGB anwaltlich prüfen lassen** und die geprüfte Fassung unter Einstellungen → AGB als neue Version eintragen.
- **Etiketten:** Es gibt noch keine Etiketten-Artikel. Das Angebot bietet nur Etiketten an, die zum **Endformat am Behälter** (`item.etikett_final`, B×H) passen – dafür fehlen die Behältermaße bzw. Etikettenformate und die Etikettenpreise.
- **Mengenrabatt bei Rezeptur-Angeboten:** Alle Bestellmengen kosten aktuell gleich viel je Packung; die Mengendegression der alten Preismatrix greift dort nicht.
- **Lieferantenportal:** Rückfragen/Chat und eine eigene Dateiablage gibt es (anders als in v3) noch nicht; Chinesisch fehlt (bisher Deutsch/Englisch).
- **Beta-Admin-Passwort ändern.**
- **Behälter-EK-Staffeln:** Standbodenbeutel (XS–XXL) und jetzt auch PET-Dosen + Weithalsgläser (100–250 ml) haben EK-Staffeln (Seeds `seed_standbodenbeutel` / `seed_packari_behaelter`). **Offen:** echte Deckel-Preisliste bei Packari erfragen – Packari verkauft Dose und Deckel nur im Set, deshalb ist der EK der vier Pressure-Seal-Deckel als Differenz „Set minus Dose ohne Verschluss" gerechnet (je nach Bezugsdose 0,26–0,35 €).
- **Demo-Rezepturen ohne Rohstoffpreise** → Preis-Matrix zeigt 0 €. Für echte Kalkulation Rohstoff-EK an den Zutaten hinterlegen.
- **Flaschen/Tuben für Flüssig anlegen** – PET-Dosen und Weithalsgläser haben jetzt Volumen und Füllgewicht, für echte Flüssig-Gebinde (Tropfflasche, Pumpspender) fehlen die Artikel aber noch.
- **Kalkulationsgrundlagen prüfen:** Presshilfsstoffe 20 % / 8 EUR pro kg und Trägerflüssigkeit 3 EUR pro Liter sind gesetzte Startwerte – bitte gegen echte Zahlen tauschen (Einstellungen → Preise & Margen).
- **Admin-Passwort auf beta** ändern (steht noch auf admin/admin – öffentliche URL).
- **Teilproduktion .B/.C:** Chargen-Basis + Nächster-Buchstabe-Logik sind da; ein UI-Weg „Teilmenge einbuchen" könnte später ergänzt werden (heute bucht der Abschluss die volle Menge als `.A`).

## Deploy / GitHub
- Repo: **github.com/Freudenfels/bulkify-4.1** (privat), Branch `main`.
- **Push löst Auto-Deploy aus** (GitHub Actions, SFTP) → **beta.bulkify.pro** (Ordner `/bulkify4.1`, eigene UD-DB via `secrets.php` auf dem Server). v3 = app.bulkify.pro (unberührt).
- `secrets.php` und `data/` sind vom Git/Deploy ausgeschlossen und bleiben es.

## Regeln (Kurzfassung, Details in CLAUDE.md)
- Zu jeder `.php` eine co-located `.md` pflegen. **Keine Emojis** in der UI, Feld-/Spaltenüberschriften nie fett, großzügige Abstände.
- Zeit **UTC** speichern, Anzeige via `fmt_zeit()`.
- **Nie pauschale DELETEs** in der DB (Parallelarbeit) – Testschreiben per Transaktion+Rollback oder exakt per erfasster ID.
- Verifizieren mit `php -l` + kurzem curl-Test (Admin-Autologin nur localhost).
- Nach fertigem Schritt committen + pushen (außer der Nutzer sagt ausdrücklich „lokal lassen").
