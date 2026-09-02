# anfrage/detail.php – Rezepturanfrage bearbeiten

**Zweck:** Der Anfrage-Loop – wir übersetzen den **Kundenwunsch (Laiensprache)** in eine **produzierbare Rezeptur**.

**Aufbau:**
- **Kopf:** Kunde, Darreichungsform, Status, Rezepturname (für die Erstellung), Kundenwunsch/Notiz.
- **Wunsch → Zuordnung** (Tabelle, zweigeteilt):
  - links der **Wunsch des Kunden** (Bezeichnung + Menge + Einheit),
  - rechts **unsere Zuordnung**: echter **Rohstoff** (Auswahl mit **CAS**) + **finale Menge (mg)** je Einheit/Portion.
  - **Auto-Zuordnung:** `anfrage_auto_item()` schlägt beim Öffnen den passenden Rohstoff vor (über Nährstoff- oder Item-Name) – z. B. „Vitamin C" → Ascorbinsäure (CAS 50-81-7).
  - Zeilen hinzufügbar (z. B. Füllstoff).
- **Kapsel-Check** (bei Kapsel/Tablette/Softgel): Zielgröße wählen → Summe je Kapsel vs. Füllmenge; passt nicht → **Split-Vorschlag** (z. B. 1000 mg → 2 Kapseln/Tag je ~500 mg). Bei Pulver/Stick/Flüssig: Hinweis „pro Portion", kein Kapsel-Limit.

**Wunsch-Produktname:** Feld `rezeptur_anfrage.produktname` – vom Kunden im Portal angegebener Wunschname; dient beim „Rezeptur erstellen" als Default-Name der neuen Rezeptur.

**Aktionen:**
- **Speichern:** Kopf (inkl. Wunsch-Produktname) + Wunsch-/Zuordnungs-Zeilen (`rezeptur_anfrage_wunsch`).
- **Rezeptur erstellen:** legt aus den zugeordneten Zeilen (Rohstoff + finale Menge) eine **Rezeptur** (`rezeptur` + `rezeptur_zutat`, Status Entwurf) an, verknüpft sie mit der Anfrage (`rezeptur_id`), setzt die Anfrage auf **beantwortet**, schreibt einen Verlaufseintrag und öffnet die neue Rezeptur im normalen Baukasten.

## Rezeptur entwickeln (KI)
Über dem Formular steht der Rezepturvorschlag aus der Kundenidee (`core/rezeptur_ki.md`): Zutaten mit Mengen, Novel-Food-Einschätzung, Höchstmengen, Health Claims, Machbarkeit und das **selbst nachgerechnete Füllgewicht** samt passender Kapselgröße. `aktion=ki_entwickeln` erzeugt ihn, `aktion=ki_zeilen` übernimmt die Zutaten in die Wunschzeilen. Ein vorhandener Vorschlag wird immer angezeigt, auch wenn gerade kein Schlüssel hinterlegt ist. Alles ist ein Entwurf – gesendet wird erst über den bestehenden Weg „Rezeptur erstellen".
