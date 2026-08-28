# Übergabe – bulkify Dashboard 4.1

> Kurzer Stand zum Weiterarbeiten am nächsten PC (Laptop). Ergänzt `CLAUDE.md` (dort stehen die Dauer-Regeln).
> **Stand: 2026-08-29.**

## Zuerst am Laptop tun
1. **Git holen:** `git pull` (Branch `main`). Nur an EINEM PC gleichzeitig arbeiten.
2. **Lokale DB:** MariaDB/MySQL, DB `bulkify41`, User/Pass `bulkify`/`bulkify` (siehe `core/config.php`). Das Schema baut sich per `init_schema()` beim ersten Seitenaufruf selbst auf.
   - **Achtung:** Die **Daten** wandern NICHT über Git (nur Code). Der Laptop hat eine eigene, leere/andere DB. Demodaten bei Bedarf neu einspielen: **Einstellungen → Werkzeuge → „Demo-Testdaten einspielen"** (nicht-löschend, idempotent).
3. **Starten:** `php -S 127.0.0.1:8741 -t public` → http://127.0.0.1:8741
4. **Login:** admin@bulkify.local / admin (lokal). Live-Beta: siehe unten.

## Was heute gebaut wurde (alles gepusht, läuft nach Push auf beta)
- **Chargennummer + MHD (Standard):** Fertigware bekommt automatisch `PR-Nummer + Tagesbuchstabe` (z. B. `2696.A`, Teilproduktionen `.B/.C`) und **MHD = Produktionsdatum + 18 Monate** (Einstellungen → Produktion änderbar). Steht schon vor der Buchung im Produktions-Detail (für die Geräte-Eingabe). Rückverfolgung über neue Spalte `charge.pa_id`.
- **Fremdproduktion = Standard:** neue Aufträge laufen auf dem verkürzten Weg (bereitstellen → verpacken → etikettieren → Prüfung/Freigaben). Im Produktions-Detail auf Eigenproduktion umstellbar.
- **Produktions-Liste in Reitern:** „Produktionsbereit" (Standard), „Wartet auf Material" (eigener Reiter), „Abgeschlossen".
- **Fix EK-Mengenstaffel:** „+ Staffel" fügte die Lieferant-Spalte nicht ein (Zeile verrutschte) → behoben.
- **Portal-Anfrage → „Im Angebots-Editor bauen":** Button legt ein verknüpftes Angebot an und springt in den Editor – funktioniert auch ohne berechenbare Preismatrix (Positionen manuell).
- **Pulver/Granulat nach Füllgewicht:** werden nach Gramm angeboten (Standard 150/300/500/1000 g, in Einstellungen → Preise & Margen), nicht mehr nach Kapsel-Stückzahl. Behob die leere Pulver-Matrix.

Frühere Sessions (bereits live): Login neu gestaltet + bulkify-Logo, Etikettenpreise (Labelisten) je Gebinde, Demo-Testset (Kunden/Produkte/Angebote/Aufträge inkl. Zukauf).

## Offene Punkte / als Nächstes
- **Behälter-EK-Staffeln** sind noch leer (nur Etikettenpreise sind hinterlegt). EK je Gebinde (PET/Glas) je Menge eintragen – Nico liefert Zahlen; dann wie bei den Etiketten einpflegen.
- **Demo-Rezepturen ohne Rohstoffpreise** → Preis-Matrix zeigt 0 €. Für echte Kalkulation Rohstoff-EK an den Zutaten hinterlegen.
- **Tablette/Flüssig:** noch keine automatische Preisberechnung (nur „auf Anfrage" bzw. Angebots-Editor manuell).
- **Admin-Passwort auf beta** ändern (steht noch auf admin/admin – öffentliche URL).
- **Anthropic-API-Key** (aus altem v3-secrets) rotieren.
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
