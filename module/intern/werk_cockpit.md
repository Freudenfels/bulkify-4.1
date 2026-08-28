# intern/werk_cockpit.php – Werk-Cockpit (Produktionsmitarbeiter)

**Zweck:** Startseite des eigenen **Werk-Bereichs** für Produktionsmitarbeiter. Route `?p=werk`. Zeigt nur Produktion & Warenwirtschaft – **kein Verkauf, keine Umsätze/Preise**. Kundennamen sind bewusst sichtbar (Nutzer-Entscheidung), nur Verkaufszahlen fehlen.

**Wer landet hier:** Benutzer mit Produktionsrolle (`production`/`labor`/`fulfillment`) **ohne** `admin`/`sales`/`finance` – erkannt über `ist_produktionsbereich()` (core/auth.php). Login und `index.php` leiten diese Personen automatisch auf `?p=werk` statt aufs Verkaufs-Dashboard. `render_header()` zeigt für sie das schlanke Menü `bx_nav_werk()` (Marke „bulkify Werk").

**Inhalt:**
- **Kacheln:** Offene Produktionsaufträge (offen+laufend) · davon in Produktion · Chargen in Quarantäne · offene Rezepturanfragen. Kacheln sind verlinkt (Produktion/Wareneingang/Anfragen).
- **Als Nächstes zu produzieren:** offene/laufende `produktionsauftrag` mit nächster Station + Fortschritt (n_done/n_total), Kunde, Produkt, Menge; Zeile klickt zum Produktionsauftrag (geführte Schritte).
- **Letzte Wareneingänge:** letzte `charge`-Einbuchungen (Charge, Artikel, verfügbare Menge, MHD, Status frei/Quarantäne).

**Zusammenhang:** Der Werk-Bereich nutzt dieselben Modulseiten wie der Admin (Produktion, Lager, Rezeptur, Anfragen) – nur Menü + Startseite sind eigen. Siehe [../../core/layout.md](../../core/layout.md) (`bx_nav_werk`) und Chargenverfolgung [../lager/chargen.md](../lager/chargen.md).
