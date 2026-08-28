# bulkify Dashboard 4.1 – Konzept & Aufbauplan

**Stand:** 2026-08-13 · **Modus:** Clean Slate (Neuaufbau von Null) · **Phase:** Offline bauen & testen

Dieses Dokument ist unsere gemeinsame Arbeitsgrundlage. Es legt fest, **warum** wir 4.1 neu bauen, **wie** die Architektur aussieht, **was** alles rein muss und **in welcher Reihenfolge**. Erst wenn das steht, schreiben wir Code.

---

## 1. Warum 4.1? (Ausgangslage)

Das heutige v3 (`bulkify-fundament`) kann fachlich extrem viel – aber die Struktur ist mitgewachsen und bremst:

- **255 PHP-Dateien flach nebeneinander**, kein Framework, keine Ordnung nach Bereichen.
- **Dual-Treiber** SQLite (lokal) ↔ MySQL (live) übersetzt jede SQL-Anweisung – fehleranfällig (z. B. LIKE-Escape-Crash).
- **Mehrere Deploy-Kopien** des Dashboards nebeneinander – unklar, was live ist.
- Wiederkehrende Struktur-Bugs (z. B. `<form>` in `<form>`, verwaiste Uploads durch getrennte Ordner).
- Gewachsene Logik ist wertvoll, aber schwer zu überblicken.

**4.1 ist die Chance, die bewährte Fachlogik zu behalten, aber auf ein sauberes, wartbares Fundament zu stellen.** Kein Feature-Verlust – bessere Ordnung.

---

## 2. Zielbild 4.1

- **Eine klare Ordnerstruktur** nach Bereichen statt 255 loser Dateien.
- **Eine Datenquelle, ein Weg** – kein Dual-Treiber-Wirrwarr.
- **Ein Login-System** für alle vier Rollen mit sauber getrennten Sichten.
- **Wiederverwendbare Bausteine** (Layout, Tabellen, Formulare, PDF) statt Copy-Paste.
- **Design bleibt bulkify** – gleiche Marke, gleiche Bedien-Regeln (siehe §6).
- **Offline lauffähig** zum Bauen & Testen, sauberer Weg nach live über GitHub.

---

## 3. Architektur-Vorschlag (zu bestätigen)

> Das sind die tragenden Grundentscheidungen. Meine Empfehlung steht jeweils dabei – bitte bestätigen oder ändern.

### 3.1 Sprache & Grundgerüst
**Empfehlung: PHP behalten, aber strukturiert.**
Ein einziger Einstiegspunkt (`index.php`) als „Front Controller" mit einem einfachen Router. Jede Seite ist weiter eine überschaubare Datei – aber **in Bereichs-Ordnern** statt flach.

```
bulkify-4.1/
├─ public/            ← einziger Web-Einstieg (index.php, assets)
├─ core/              ← db, auth, layout, helpers, i18n, pdf, mail
├─ module/
│  ├─ intern/         ← Team-Dashboard, Suche, Aufgaben (ops)
│  ├─ crm/
│  ├─ rezeptur/
│  ├─ angebot/
│  ├─ buchhaltung/
│  ├─ produktion/
│  ├─ lager/
│  ├─ portal_kunde/
│  ├─ portal_lieferant/
│  └─ portal_partner/
├─ templates/         ← wiederverwendbare Layout-Bausteine
├─ data/              ← liegt AUSSERHALB von public (DB, Uploads)
└─ docs/
```

Vorteil: klassisches, für dich/Claude Code vertrautes PHP – nur endlich aufgeräumt.

### 3.2 Datenbank
**Zwei Optionen:**
- **(A) Nur MySQL/MariaDB, auch lokal.** Live ist MySQL – wenn wir lokal dasselbe fahren, verschwindet der Dual-Treiber komplett. Braucht eine lokale MariaDB-Installation.
- **(B) SQLite lokal, MySQL live (wie heute), aber mit sauberer Abstraktion.** Null Setup lokal, aber der Doppel-Weg bleibt.

**Meine Empfehlung: (A) MySQL überall.** Ein Weg, keine Übersetzungs-Bugs mehr. Den kleinen Setup-Aufwand (lokale MariaDB) holen wir zehnfach wieder rein.

### 3.3 Schema & Migrationen
Ein einziger, zentraler Ort fürs Schema. Änderungen **additiv** (Spalten nur dazu, nie löschen), damit Updates gefahrlos sind. Frisch-installierbar bleibt Pflicht.

### 3.4 Geld & Zahlen
Bewährtes übernehmen: **Geld intern als Ganzzahl** (Cent bzw. e4 = 1/10000 € für Sub-Cent-Kapselpreise). Zeit **UTC speichern, Anzeige immer Europe/Berlin**.

### 3.5 Fachlogik: portieren, nicht neu erfinden
Die harten, teuer erlernten Regeln aus v3 werden **1:1 übernommen**, nur sauber verpackt:
- Preislogik-Reihenfolge (fester Kundenpreis → Staffel → Grund-VK → Margen-Rabatt → VK2-Untergrenze)
- Chargennummern-Schema (BF/FB + JJMM + lfd. Nr.)
- GoBD-Regeln (Nummer erst beim Festschreiben, Storno nur per Gutschrift)
- Auto-Ablauf „Angebot steuert Produktion"

---

## 4. Funktions-Landkarte (was 4.1 abdecken muss)

Alles aus v3, gruppiert. Das ist der Gesamtumfang – wir bauen ihn in Phasen (§5).

| Bereich | Inhalt (aus v3) |
|---|---|
| **Fundament** | Login/Rollen (4 Portale), Layout, Navigation, Suche, Benachrichtigungen, Einstellungen, Nutzer/Mitarbeiter |
| **CRM** | Leads, Pipeline, Kontakte, Kalender, Fragenkatalog, Schnellanfrage, Lead-Intake |
| **Rezeptur & Entwicklung** | Rezepturen (Anlage, Vorschlag, Einfrieren), Entwicklung + KI, Ideen, Novel-Food-Katalog, Laboranalysen, Schüttdichte |
| **Anfragen & Angebote** | Produktanfragen, Angebote, Angebots-Import (KI/PDF), Staffelpreise, Angebot-Loop |
| **Buchhaltung** | Belege, Rechnungen, Katalog, Kunden-Konto, Mahnwesen, E-Rechnung/ZUGFeRD, Offene Posten, Bank-Import, Einkaufsliste, Export |
| **Produktion** | Produktionsaufträge, Produktionswege/Stationen/Gates, Freigaben (Produktion+Versand), Maschinen, Bericht, Plan |
| **Warenlager & Logistik** | Lager 1 (eigen) + Lager 2 (Kunden-Fertigware/White-Label), Rohstoffe/Items, Chargen, Wareneingang (inkl. Sprache/KI), Fremdlager, Dispo, Lagerplätze, Etiketten |
| **Lieferanten** | Lieferantenportal (DE/EN/ZH), RFQ/Angebote, Bestellungen, CoA/Spec-Upload, Wareneingang-Verknüpfung, Handbuch |
| **Kundenportal** | Bestellungen, Angebote bestätigen, Rezepturen einreichen, Dokumente, „Mein Lager", Adressen, Handbuch |
| **Partnerportal** | Hybrid Kunde+Lieferant, strikt getrennte Sichten |
| **Dokumente & PDF** | CoA, Spec, PIB, Rezepturbewertung, Angebots-PDF, Etiketten, Mahnungen – über eigene PDF-Bibliothek |
| **Tools & Stammdaten** | Preisrechner, Rohstoffpreise, Verpackungen/Kapsel-Füllmengen, SKU-Verwaltung, Produktkatalog/Shop |

---

## 5. Aufbau-Reihenfolge (Phasen)

Wir bauen **von unten nach oben** und testen jede Stufe offline, bevor die nächste kommt.

- **Phase 0 – Projekt-Setup:** Ordnerstruktur, lokale DB, `index.php`/Router, ein „Hello Dashboard" läuft im Browser.
- **Phase 1 – Fundament:** DB-Schicht + Schema-Grundstock, Login/Rollen, Layout/Navigation, Einstellungen. → Man kann sich einloggen und navigieren.
- **Phase 2 – Stammdaten:** Kunden, Lieferanten, Rohstoffe/Items, Verpackungen. Die Datenbasis, auf der alles aufbaut.
- **Phase 3 – Rezeptur → Anfrage → Angebot:** der Kern-Wertschöpfungsweg bis zum bestätigten Angebot.
- **Phase 4 – Auftrag → Produktion → Lager:** der automatische Durchlauf nach Bestätigung.
- **Phase 5 – Buchhaltung:** Belege, Rechnungen, GoBD, E-Rechnung.
- **Phase 6 – Portale:** Kunden-, Lieferanten-, Partnerportal auf der fertigen Basis.
- **Phase 7 – Feinschliff:** Dokumente/PDF-Baukasten, Tools, KI-Funktionen, Migration echter Daten.

Nach jeder Phase: **anhalten, im Browser zeigen, du gibst grün fürs Weiter.**

---

## 6. Bedien- & Design-Regeln (gelten von Anfang an)

Aus deinen Vorgaben – von Tag 1 eingebaut, nicht nachträglich:
- **Keine Emojis** in der UI.
- **Labels/Überschriften nie fett**, einheitlich wie normale Labels.
- **Nichts umbrechen/einengen** – volle Breite nutzen, Labels einzeilig.
- **Einstellungs-Seiten mit Reitern** gegliedert.
- Wichtige Hinweise als **Info-Icon (ⓘ)**, nicht als Textwüste.
- **Markenfarben:** Lime #C0F24E, Grün #1D9E75, Dunkelgrün #10210F, Gold.
- Seltene Ein-/Zweimal-Aktionen **nicht prominent** in die Übersicht.

---

## 7. Offene Entscheidungen (bitte festlegen)

1. **DB-Weg:** MySQL überall (A, empfohlen) oder SQLite lokal / MySQL live (B)?
2. **Deploy-Ziel:** Wird 4.1 später der neue Live-Stand (ersetzt v3), oder läuft es erst parallel (eigene Subdomain/Ordner)?
3. **Datenübernahme:** Startet 4.1 mit leerer DB + Testdaten, oder sollen echte v3-Daten übernommen werden?
4. **Umfang zuerst:** Bauen wir die volle Landkarte, oder erstmal ein Kernstück (Fundament + Rezeptur→Angebot) als lauffähigen Prototyp?

---

## 8. Nächster Schritt

Sobald die vier offenen Entscheidungen stehen, starten wir **Phase 0** (Projekt-Setup) und du siehst das erste „bulkify 4.1" im Browser laufen.
