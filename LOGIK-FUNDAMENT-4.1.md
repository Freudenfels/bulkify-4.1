# bulkify 4.1 – Logik-Fundament (Single Source of Truth)

**Stand:** 2026-08-13 · Ergänzt [KONZEPT-4.1.md](KONZEPT-4.1.md)

Ziel dieses Dokuments: **die Logik von vornherein festsetzen**, damit nie wieder mehrere Seiten dieselben Infos halten. Das ist die Landkarte, gegen die jede Seite in 4.1 gebaut wird.

---

## Die 3 eisernen Regeln

1. **Ein Objekt = ein Zuhause.** Jede Datenart wird an genau *einer* Stelle gespeichert und verwaltet. Alle anderen Seiten *zeigen* sie nur oder *verlinken* darauf – niemals eine zweite Kopie.
2. **Eine Seite = eine Aufgabe.** Keine „schnell mal reingebauten" Zusatzfunktionen auf fremden Seiten. Wenn etwas Neues gebraucht wird, bekommt es einen klaren Platz – kein Anflanschen.
3. **Rollen sehen, sie besitzen nicht.** Kunden-, Lieferanten- und Partnerportal sind *Sichten* auf dieselben Objekte, keine eigenen Datentöpfe. Ein Angebot ist ein Angebot – der Kunde sieht seine Sicht darauf, das Team seine.

---

## Die Kern-Objekte (jedes genau einmal)

### Stammdaten (das „Was")

| Objekt | Was es ist | Ein Zuhause in 4.1 |
|---|---|---|
| **Kunde** | Kundenfirma. Enthält den Lebenszyklus **Lead → aktiver Kunde** (CRM ist kein zweiter Topf, nur eine Sicht). Eine Adresse, ein Ansprechpartner, eine Historie | `module/kunde` |
| **Lieferant** | Lieferantenfirma (eigene Gruppe). RFQ, Bestellungen, CoA/Spec hängen dran | `module/lieferant` |
| **Partner** | Anderer Lohnhersteller mit **zwei Seiten**: Kunden-Seite (fragt bei uns an) + Lieferanten-Seite (bearbeitet unsere Anfragen). Eigenes Portal, strikt getrennte Sichten | `module/partner` |
| **Item** | Alles im Lager: Rohstoff, Fertigware, Verkaufsfertig, Verpackung, Verbrauch, Maschine. Kategorie steuert die Strenge (Charge/Quarantäne ja/nein) | `module/lager` (Item-Stammdaten) |
| **Rezeptur** | Eine Formulierung; wird als nummerierter Snapshot eingefroren (read-only, verbindlich) | `module/rezeptur` |
| **Produkt (SKU)** | Rezeptur + Verpackung + Kunde = konkretes verkaufbares Produkt | `module/produkt` |

### Vorgänge (das „Wie es durchläuft")

| Objekt | Was es ist | Ein Zuhause in 4.1 |
|---|---|---|
| **Anfrage** | Produktanfrage des Kunden (Mengenstaffeln + Verpackung) | `module/angebot` |
| **Angebot** | Team setzt Preise – **einzige Preisquelle**; Kunde bestätigt eine Staffel | `module/angebot` |
| **Auftrag** | Aus bestätigtem Angebot: Auftragsbestätigung | `module/auftrag` |
| **Produktionsauftrag** | Steuert Produktion über Stationen/Gates, 2 Freigaben (Produktion + Versand) | `module/produktion` |
| **Charge** | Rückverfolgbare Produktions-/Wareneingangs-Einheit | `module/lager` |
| **RFQ / Lieferanten-Angebot** | Preisanfrage an Lieferanten → EK | `module/einkauf` |
| **Bestellung** | Einkauf beim Lieferanten | `module/einkauf` |
| **Beleg** | Rechnung, Gutschrift, Lieferschein – *ein* Beleg-Typ, gesteuert übers Feld „Art" (GoBD) | `module/buchhaltung` |

### Quer-Objekte (überall angehängt, nie doppelt)

| Objekt | Was es ist | Ein Zuhause in 4.1 |
|---|---|---|
| **Dokument/Datei** | CoA, Spec, PIB, PDFs – *ein* Datei-Speicher, an jedes Objekt anhängbar (löst „verwaiste Uploads") | `core/dateien` |
| **Aufgabe** | ops-Task/Projekt (Board) | `module/intern` |
| **Benachrichtigung** | Systemweite Hinweise | `core/benachrichtigung` |

---

## Konsolidierungs-Landkarte: v3 → 4.1

So werden die heutigen Doppelungen zusammengeführt. **Wichtig:** Nicht jede Datei ist Doppelung – die Portale (`portal_`, `lieferant_`, `partner_`) sind *legitime Rollen-Sichten* und bleiben getrennt. Doppelt ist nur, wo **intern dasselbe Objekt mehrfach verwaltet** wird.

| Objekt | v3 heute (verstreut) | 4.1 |
|---|---|---|
| **Kunde** | `kunden`, `buchhaltung_kunden`, `buchhaltung_kontakt`, `crm_kontakte`, `crm_lead`, `kunde_einladung` | **1 Kunden-Stamm** mit Lebenszyklus Lead→Kunde; CRM/Buchhaltung sind Sichten darauf |
| **Lieferant** | `lieferanten`, `lieferant_*`, `admin_lieferant_merge` | **1 Lieferanten-Stamm** (eigene Gruppe) |
| **Partner** | `partner`, `partner_*` | **1 Partner-Objekt** mit Kunden- + Lieferanten-Seite (getrennte Sichten) |
| **Produkt/Katalog** | `produkt`, `produkte`, `produkt_katalog`, `produkt_sku`, `produkt_sku_detail`, `katalog`, `buchhaltung_katalog`, `buchhaltung_katalogprodukt`, `fertigprodukte` | **1 Produkt/SKU-Objekt**; Katalog = Filter darauf |
| **Rezeptur** | `rezept`, `rezepte`, `rezept_*`, `entwicklung_*` | **1 Rezeptur-Objekt**; Entwicklung = Status davor, nicht eigener Topf |
| **Anfrage/Angebot** | `anfrage(n)`, `admin_angebot(e)`, `buchhaltung_anfragen`, `buchhaltung_angebote`, `crm_anfrage`, `schnellanfrage` | **1 Anfrage- + 1 Angebot-Objekt**; „schnell"-Varianten fallen weg |
| **Lager/Item** | `lager`, `lager2`, `rohstoffe`, `chargen`, `charge`, `fertigprodukte`, `fremdlager` | **1 Item-Stamm + 1 Chargen-Logik**; Lager 1/2 = Bestandsort-Flag, kein zweiter Datentopf |
| **Beleg/PDF** | `beleg_build`, `*_pdf`, `*_download`, `mahnung_build`, `pa_build`, `pib_build` | **1 Beleg-Objekt + 1 PDF-Baukasten** |

*(Vorschlag – schärfen wir gemeinsam. Ich habe aus den Dateinamen abgeleitet; beim Bau lese ich den echten Code, bevor etwas zusammengeführt wird.)*

---

## Der eine Prozess-Faden (vom Lead bis zur Rechnung)

Alles hängt an einem durchgehenden Faden – kein Objekt entsteht „nebenbei":

```
Lead ─▶ Rezeptur ─▶ Anfrage ─▶ Angebot ─(Kunde bestätigt)─▶ Auftrag
                                                               │
                              ┌────────────────────────────────┤ (automatisch, idempotent)
                              ▼                ▼                ▼
                      Produktionsauftrag   Rechnung      Lager reserviert
                              │
                     Produktion (Stationen/Gates)
                              │
                    Freigabe Produktion ─▶ Freigabe Versand ─▶ Versand/Lager 2
```

**Preislogik (fix, eine Reihenfolge):** fester Kundenpreis → Staffel-VK → Grund-VK → Margen-Rabatt (nur auf VK−EK) → VK2 als harte Untergrenze.

---

## Kontakt-Modell (entschieden)

- **Kunde** und **Lieferant** bleiben **getrennte Gruppen** – zwei verschiedene Welten.
- **Kunde** enthält den Lebenszyklus **Lead → aktiver Kunde**: CRM ist kein separater Datentopf mehr, sondern die frühe Phase desselben Kunden-Stamms. Das räumt die `kunden`/`crm_kontakte`/`buchhaltung_kunden`-Doppelung ab, ohne Kunde und Lieferant zu vermischen.
- **Partner** = anderer Lohnhersteller mit zwei Seiten (fragt bei uns an **und** bearbeitet unsere Anfragen). Eigenes Objekt, eigenes Portal, strikt getrennte Sichten – kein Weiterreichen von Enddaten.

---

## Danach: Phase 0

Das Fundament steht. Nächster Schritt: **Phase 0** (Ordnerstruktur + lokale MySQL + erstes „bulkify 4.1" im Browser) – strikt gegen diese Landkarte gebaut.
