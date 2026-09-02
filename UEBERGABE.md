# Übergabe – bulkify Dashboard 4.1

> Stand zum Weiterarbeiten in einer neuen Session (auch mit einem anderen Modell).
> Ergänzt `CLAUDE.md` (dort stehen die Dauer-Regeln). **Stand: 2026-09-02, alles gepusht und auf beta.**
>
> **Für eine neue Session:** Diese Datei plus `CLAUDE.md` lesen, dann `git pull`. Wer tiefer einsteigt,
> findet zu **jeder** `.php` eine `.md` daneben – die ist die eigentliche Doku.

## Zuerst tun
1. **Git holen:** `git pull` (Branch `main`). Nur an EINEM PC gleichzeitig arbeiten.
2. **Lokale DB:** MariaDB/MySQL, DB `bulkify41`, User/Pass `bulkify`/`bulkify` (siehe `core/config.php`). Das Schema baut sich per `init_schema()` beim ersten Seitenaufruf selbst auf (CREATE IF NOT EXISTS + additive `ensure_column`).
3. **Starten:** `php -S 127.0.0.1:8741 -t public` → http://127.0.0.1:8741
4. **Login:** admin@bulkify.local / admin (lokal). Beta: siehe unten.
5. **Daten wandern NICHT über Git** (nur Code). Demodaten bei Bedarf: Einstellungen → Werkzeuge.

## Die drei Wege im System
| Weg | Wer | Einstieg |
|---|---|---|
| **Intern** | Team | `?p=dashboard` – Angebote, Aufträge, Produktion, Lager, Einkauf, Einstellungen |
| **Kundenportal** | Kunde, Magic-Link ohne Passwort | `?p=portal&token=<kunden.portal_token>` |
| **Lieferantenportal** | Lieferant, **eigener Login mit Passwort** | `?p=lieferant_login`, Einladung per `?p=lieferant_einladung&token=…` |

**Datenmodell in einem Satz:** Rezeptur = Rohstoff × Menge + Form. **Produkt = Rezeptur × Menge je Packung + Verpackung.** Das Produkt ist kundenneutral, nur der Preis ist kundenspezifisch. Ein Produkt entsteht **erst mit dem Auftrag**, nicht beim Angebot.

## Kundenweg (fertig)
- **Anfrage → Angebot → Annahme → Auftrag + Rechnung + Produktion.**
- Im Angebots-Editor wird je Rezeptur eine **Gruppe (A, B, C …)** angehängt; jede Gruppe ist im Portal **eine wählbare Zeile** mit Größe, Verpackung, Anzahl Packungen und Preis je Packung (v3-Optik). Der Kunde nimmt genau eine an → `auftrag_aus_positionen($angebot_id, $gruppe)`.
- **Verbindliche Annahme** (Rezeptur und Angebot) nur über einen Dialog mit Pflicht-Haken, **AGB-Haken** und Namen. Gespeichert werden `freigabe_name`, `freigabe_am`, `agb_version` – serverseitig geprüft, nicht nur im Dialog.
- **AGB versioniert** (`core/agb.php`, Einstellungen → AGB, Portalseite `v=agb`). Der Text ist ein **Entwurf und muss anwaltlich geprüft werden.**
- **Anfrage absagen** („Nicht machbar") mit Pflicht-Begründung; ein nicht gesendeter Angebots-Entwurf wird dabei verworfen und die Nummer freigegeben.
- **Angebots-PDF** über `?p=angebot_pdf&id=` (Knopf in der Kopfzeile, Icon in Liste und Anfrage-Seite). Bei mehreren Varianten zeigen die Positionen die erste Variante, die Staffel „Preis je fertiges Produkt" alle – sonst stünde dort eine Summe über Varianten, die niemand zusammen bestellt.

## Lieferantenweg (neu, komplett)
- **Bestell-PDF** `?p=bestellung_pdf&id=` – deutsch oder englisch je `lieferanten.sprache`.
- **Ablauf an der Bestellung** (`core/bestellung_ui.php`, dasselbe Panel intern und im Portal): bestätigen mit Termin → Stationen (angenommen · Produktion · Qualität · versandbereit · versendet) → Versanddaten. **„versendet" nur mit Anbieter, Versandart und Sendungsnummer** – erzwingt die Funktion, nicht die Oberfläche.
- **Portal** (`?p=lieferant_*`): Übersicht, Bestellungen, Preisanfragen mit Staffeln + CoA-Upload, „Meine Daten" (Firmendaten, WeChat, WhatsApp, Logo, Sprache).
- **Preisanfrage → Angebot → „Preise übernehmen"** schreibt die Staffeln als EK-Staffeln nach `lieferant_preis` – dort rechnet die Kalkulation.
- **Drei Sprachen:** Deutsch, English, 中文 (Umschalter im Portal, auch vor dem Login). **PDFs bleiben de/en** – für CJK müsste erst eine Schrift eingebettet werden.

## Dokumente
- **Eigene Spezifikation und eigenes CoA** im bulkify-Layout (`core/pdf_spec.php`, Routen `?p=spec_bulkify&id=<item>` und `?p=coa_bulkify&id=<charge>`). Die Unterlagen der Vorlieferanten gehen **nicht** an den Kunden; sie bleiben intern die Quelle.
- Analysenwerte je Charge am Rohstoff erfassen (`charge_analyse`). **„Werte vorschlagen"** liest ein hochgeladenes Lieferanten-PDF aus (`core/pdf_text.php` + `core/coa_lesen.php`) und füllt das Formular – gespeichert wird erst nach Prüfung. **Scans enthalten keinen Text**, dafür fehlt OCR.

## E-Mail
`core/mail.php` – SMTP über Socket, kein Composer. Eingerichtet unter **Einstellungen → E-Mail** (United-Domains-Daten) mit Testversand; jede Mail zusätzlich in `data/mail.log`.
**Automatisch verschickt wird bisher nur die Lieferanten-Einladung.** Vorlagen für „neue Bestellung" und Team-Benachrichtigung liegen bereit, sind aber noch nirgends angehängt.

## Offene Punkte / als Nächstes
1. **SMTP-Zugangsdaten eintragen** (Einstellungen → E-Mail) und Testmail schicken. Die Ereignisse sind angebunden (Tabelle unter Einstellungen → E-Mail, Details in `core/mail.md`): Bestellung raus, Angebot gesendet/angenommen, Anfrage abgesagt, Lieferanten-Aktionen ans Team.
2. **AGB anwaltlich prüfen** und die geprüfte Fassung als neue Version eintragen.
3. **Etiketten:** Es gibt noch keine Etiketten-Artikel. Das Angebot bietet nur Etiketten an, die zum **Endformat am Behälter** (`item.etikett_final`, B×H) passen – dafür fehlen Behältermaße/Etikettenformate und die Etikettenpreise.
4. ~~Mengenrabatt bei Rezeptur-Angeboten~~ – **erledigt** (2026-09-02): Rohstoffe werden mit der Lieferanten-Staffel zur Gesamtmenge gerechnet (Matrix und Rezeptur-Angebot); im Editor mehrere Mengen mit Komma. Wirkt nur, wenn am Rohstoff Staffeln (`lieferant_preis`) gepflegt sind – ein Rüstkosten-/Fixkostenmodell je Charge gibt es weiterhin nicht.
5. **Rohstoff-Stammdaten füllen** (Herkunft, Haltbarkeit, Lagerung, Allergene, vegan/GVO/bestrahlt/TSE, Zertifikate, Spec-Nr.) – ohne sie steht in der Spezifikation überall „–".
6. **Chinesische Übersetzungen gegenlesen lassen** (die aus v3 sind erprobt, die neuen nicht).
7. **Lieferantenportal:** Rückfragen/Chat und eigene Dateiablage gibt es (anders als v3) noch nicht.
8. ~~Beta-Admin-Passwort ändern~~ – **erledigt** (2026-09-02).
9. **Demo-Rezepturen ohne Rohstoffpreise** → Preis-Matrix zeigt 0 €.
10. **Flaschen/Tuben für Flüssig anlegen** (Tropfflasche, Pumpspender fehlen als Artikel).
11. **Kalkulationsgrundlagen prüfen:** Presshilfsstoffe 20 % / 8 €/kg, Trägerflüssigkeit 3 €/L sind gesetzte Startwerte.
12. **Deckel-Preisliste bei Packari erfragen** – der EK der vier Pressure-Seal-Deckel ist als Differenz „Set minus Dose" gerechnet (0,26–0,35 €).
13. **Teilproduktion .B/.C:** Chargenlogik ist da, ein UI-Weg „Teilmenge einbuchen" fehlt.

## Deploy / GitHub
- Repo **github.com/Freudenfels/bulkify-4.1** (privat), Branch `main`.
- **Push löst Auto-Deploy aus** (GitHub Actions → SFTP) → **beta.bulkify.pro** (Ordner `/bulkify4.1`, eigene UD-DB via `secrets.php` auf dem Server). v3 = app.bulkify.pro (unberührt).
- Deploy dauert ~2–4 Minuten. Status: `https://api.github.com/repos/Freudenfels/bulkify-4.1/actions/runs?per_page=1`.
- `secrets.php` und `data/` sind vom Git/Deploy ausgeschlossen und bleiben es.

## Regeln (Kurzfassung, Details in CLAUDE.md)
- Zu jeder `.php` eine co-located `.md` pflegen. **Keine Emojis** in der UI, Feld-/Spaltenüberschriften nie fett, großzügige Abstände.
- Zeit **UTC** speichern, Anzeige via `fmt_zeit()` → Europe/Berlin.
- **Nie pauschale DELETEs** in der DB (es wird parallel gearbeitet) – nur exakt per erfasster ID.
- Verifizieren mit `php -l` **und** einem curl-Test gegen den laufenden Server (Admin-Autologin nur localhost).
- Nach jedem fertigen Schritt committen + pushen.
- Ansprechpartner: **Nico** (thomalla@freudenfels.de). Stil: direkt, knapp, umsetzungsorientiert.

## Lokale Testdaten (nur auf diesem PC)
- Kundenportal: `?p=portal&token=219ea5238e95c9ef9ce1dad22ffaf6f6` (Testkunde Portal).
- Lieferant „Shandong Health Ingredients" (id 6), Login `wei@example.com` / `geheim12345`; Direktlink lokal über `?p=autologin&token=<benutzer.login_token>`.
- Offene Preisanfrage **LA-DEMO**, Bestellung **BE-TEST** zum Durchspielen.
