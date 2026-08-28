# rezeptur/detail.php – Rezeptur anlegen & bearbeiten (mit Live-Deklaration)

**Zweck:** Der Rezeptur-Baukasten – Kopf + Zutatenliste + automatische Nährwert-Deklaration und Kostenkalkulation. Herzstück der Produktentstehung.

**Was passiert hier:**
- **Speichern (POST):** Kopf (Name, Kunde, Darreichungsform, Status, Notiz) in `rezeptur` (neu = INSERT + RZ-Nummer). Zutaten werden in `rezeptur_zutat` synchronisiert (item_id + Name-Snapshot + Menge mg).
- **Anzeige (GET):** lädt Rezeptur, Zutaten, Kundenliste und alle Rohstoffe **inkl. ihrer Wirkstoffe** (für die Berechnung).

**Kopf-Felder:** Name, Kunde (leer = Hausrezeptur), Darreichungsform (Kapsel/Tablette/Softgel/Stick/Pulver/Flüssig), Status, Notiz.

**Zutaten:** Zeilen aus Rohstoff-Auswahl + Menge (mg je Einheit). „+" fügt hinzu. Die Rohstoff-Auswahl ist **nach Form vorsortiert** – bei einer flüssigen Rezeptur stehen flüssige/öl-Rohstoffe oben.

**Live-Deklaration & Kalkulation (rechnet im Browser mit, pro Einheit):**
- **Gesamtgewicht** (Summe der Mengen).
- **Kapselgröße** (nur bei Kapsel/Softgel): das System bestimmt live die kleinste Kapselgröße (aus den Einstellungen), in die das Gesamtgewicht passt – grün bei Treffer, rot „passt in keine (aufteilen)", wenn es die größte Kapsel übersteigt. **Bei Pulver/Flüssig/Stick/Tablette erscheint keine Kapselgröße** (dort zählt die Portion) – vermeidet die falsche v3-Warnung „Kapselgröße nicht bestimmbar" bei Nicht-Kapseln.
- **Kosten je Einheit** und **je 1.000 Stück** (Menge × EK-Preis des Rohstoffs).
- **Inhaltsstoffe (Etikett-Stil):** pro Zutat die Menge, darunter je Wirkstoff eine „**– davon [Wirkstoff]**"-Zeile mit Menge (mg/µg) und **% NRV** – genau die Deklarationsform vom Etikett. Rechnung: Menge × Gehalt % = mg Wirkstoff; ÷ NRV = % NRV.
- **Summe je Nährstoff:** gleiche Nährstoffe aus mehreren Rohstoffen **addiert** (z. B. Magnesium aus zwei Magnesium-Formen → Gesamt-Magnesium). Nährstoffe ohne NRV (z. B. Curcumin) zeigen die Menge ohne %.

**Lebenszyklus / Freigabe:** oben ein Status-Baustein: **Entwurf → Vorschlag → eingefroren** (verbindlich). Aktionen: „Als Vorschlag senden", „Freigeben & einfrieren", bei einer eingefrorenen Rezeptur „Neue Version" (Kopie als Entwurf) und „Bearbeitung öffnen" (zurück zu Entwurf). **Eingefroren/freigegeben = schreibgeschützt**: die Bearbeitung ist über ein `<fieldset disabled>` gesperrt (kein Speichern), die Deklaration bleibt sichtbar; serverseitig wird ein Edit-Speichern bei gesperrtem Status abgewiesen.

**Kapselgröße (nur Kapsel/Softgel):** Feld `kapselgroesse_id` an der Rezeptur. Auswahl „automatisch (nach Füllgewicht)" oder feste Größe. Die Kapselgröße **gehört zur Rezeptur** und wird vererbt: `rezeptur_kapselgroesse()` bevorzugt die gespeicherte Größe (sonst kleinste passende nach Füllgewicht) → bestimmt die Leerkapsel-Kandidaten des Produkts (`produkt_leerkapsel_kandidaten`/`produkt_leerkapsel_id`) → und damit die **Packungsgröße** (wie viele Kapseln je Gebinde, `pack_kapazitaet` je `kapselgroesse_id`). Produkt-Live-Rechnung und Portal (Rezeptur-Detail + Vorschlag) zeigen dieselbe Größe.

**Wichtig:** Mengen gelten **pro Einheit** (Kapsel/Portion) – der Kunde bestimmt die Einnahme/Tag selbst.

**Grenzen aktuell:** Wirkstoffe ohne Gehalt-% (z. B. IE-basiertes D3) fließen noch nicht in die mg-Rechnung ein; flüssige EK-Kosten werden über die Dichte angenähert.
