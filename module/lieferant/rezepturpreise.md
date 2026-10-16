# lieferant/rezepturpreise.php – „Fertigprodukt-Preise" (Fremdfertigung, Lieferantenportal)

**Zweck:** Der Lieferant trägt je **Rezeptur** seinen **Fremdfertigungspreis** ein (z. B. je Kapsel). **Kein „Annehmen"** – mehrere Lieferanten stehen nebeneinander und **unterbieten sich**; das Team sieht alle Preise und nimmt den günstigsten (interne Übersicht `?p=rezept_preise`). Route `?p=lieferant_rezepturpreise`, Menüpunkt **„Fertigprodukt-Preise"** (früher „Rezeptur-Preise" – umbenannt zur klaren Abgrenzung von den „Rohstoff-Preisen"). Das Menü zeigt diesen Punkt nur Lohnherstellern (siehe `portal_layout.md`, Menü-Gating).

**Daten:** `rezeptur_lief_angebot` (rezeptur_id · lieferant_id · preis · einheit · **stand**) + Staffeln in `rezeptur_lief_angebot_staffel` (ab_menge · preis). Beide aus dem v3-Import (Stufe 4, Quelle `lieferant_angebot` + `lieferant_angebot_staffel`).

**Auch über Preisanfragen abgegebene Preise (Stand 2026-10):** Preise, die der Lieferant als Antwort auf eine **Preisanfrage** (`lieferant_anfrage` art='fertigprodukt'/rezeptur-verknüpft → `lieferant_angebot`) abgegeben hat, stehen **nicht** in `rezeptur_lief_angebot` und fehlten daher hier. Jetzt zeigt ein **read-only-Abschnitt „Aus Preisanfragen abgegeben"** (`$laRows`) diese Angebote je Rezeptur mit LA-Nummer und Preis je Einheit (Preis-Basis „je 1.000" wird auf Stückpreis umgerechnet). Doppelte (Rezeptur steht schon oben in der editierbaren Liste) werden ausgeblendet. Bearbeitet wird so ein Preis über die Anfrage selbst, nicht hier.

**Nur freigeschaltete Formen:** der Lieferant darf nur Rezepturen der **Darreichungsformen bepreisen, die das Team für ihn freigeschaltet hat** (`lieferanten.fertig_formen`, CSV: kapsel/tablette/softgel/stick/pulver/fluessig; gesetzt auf der Lieferanten-Detailseite unter „Fertige Produkte"). Die Rezeptur-Auswahl („weitere Rezeptur bepreisen") ist auf diese Formen gefiltert; ist keine Form freigeschaltet, erscheint ein Hinweis und es kann nichts eingetragen werden. Oben stehen die für ihn freigeschalteten Formen.

**Bedienung:**
- Tabelle: Rezeptur (Name ist ein Link zu **`?p=lieferant_rezeptur`** – Zusammensetzung ansehen) · Form · **Ihr Preis** (inline editierbar, `flex-wrap:nowrap` → Feld · Einheit · „Aktualisieren" **einzeilig**) · Stand (mit „!" wenn überfällig) · Löschen.
- „**Weitere Rezeptur bepreisen**": tippbare Rezepturauswahl + Preis → neue Zeile (`status='angeboten'`, `stand=heute`). Nach der Auswahl erscheint ein „Rezeptur ansehen"-Button, um vor der Preisangabe die Zusammensetzung zu prüfen.
- **4-Wochen-Regel:** ist der neueste `stand` älter als `lieferanten.preis_intervall_tage` (Standard 28), roter Hinweis + „Alle als aktuell bestätigen" (setzt alle `stand=heute`).

**Intern:** `module/rezeptur/lief_preise.php` (`?p=rezept_preise`, Menü Produkt → Rezeptur-Preise) listet alle Lieferantenpreise je Rezeptur, sortiert nach Preis; der **günstigste** je Rezeptur ist mit Badge „günstigster" markiert. EK (Herstellpreis) + empf. VK (EK × Marge).
