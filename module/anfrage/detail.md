# anfrage/detail.php – Rezepturanfrage bearbeiten

**Zweck:** Der Anfrage-Loop – wir übersetzen den **Kundenwunsch (Laiensprache)** in eine **produzierbare Rezeptur**.

**Aufbau:**
- **Kopf:** Kunde, Darreichungsform, Status, Rezepturname (für die Erstellung), Kundenwunsch/Notiz.
- **Wunsch → Zuordnung** (Tabelle, zweigeteilt):
  - links der **Wunsch des Kunden** (Bezeichnung + Menge + Einheit),
  - rechts **unsere Zuordnung**: echter **Rohstoff** (Auswahl mit **CAS**) + **finale Menge (mg)** je Einheit/Portion.
  - **Papiere-Status in der Auswahl:** In der durchsuchbaren Rohstoff-Liste steht je Eintrag **„mit Papieren" / „ohne Papiere"** (grün/grau) – ob am Rohstoff mindestens ein Dokument (CoA/Spec/…) hängt (`EXISTS dokument`, Feld `hat_dok`/JSON `d`). So sieht man schon bei der Zuordnung, ob Unterlagen vorliegen. Ist ein Rohstoff gewählt, erscheint darunter **„Rohstoff im neuen Tab öffnen"** (`.rs-open` → `?p=rohstoff&id=`), per JS auf die Auswahl aktualisiert (`updOpen()`).
  - **Auto-Zuordnung:** `anfrage_auto_item()` schlägt beim Öffnen den passenden Rohstoff vor (über Nährstoff- oder Item-Name) – z. B. „Vitamin C" → Ascorbinsäure (CAS 50-81-7).
  - **Nicht im Katalog → beim Lieferanten anfragen:** Findet die durchsuchbare Rohstoff-Auswahl keinen Treffer („Kein Rohstoff gefunden"), erscheint dort der Knopf **„Beim Lieferanten anfragen"**. Er öffnet das gemeinsame Anfrage-Popup (`anfrage_modal()` aus `core/anfrage_ui.php`) im **Dokumente-Modus**: Fokus **CoA / Spezifikation** (Häkchen an), der **Kilopreis-Block** (Menge/Incoterm/Versandart) ist ausgeblendet und nur über „Auch Kilopreis anfragen (optional)" dazuschaltbar. Beim Absenden (`?p=preis_anfragen`, Feld `neu_rohstoff`) wird der Rohstoff als **Entwurf** angelegt (`item.anfrage_entwurf=1`, `gesperrt=1` → noch **nicht** im Katalog/Rohstoff-Picker) und je gewähltem Lieferanten eine Anfrage gestellt (`lieferant_anfrage_stellen()`, CoA inklusive). Der Entwurf existiert nur, damit der Lieferant CoA/Spezifikation/Preis daran hängen kann. **Antwortet der Lieferant** (Preisangebot oder CoA/Spec-Upload in `lieferant/anfrage.php`), hebt `rohstoff_entwurf_aktivieren()` ihn in den **Katalog** (entsperrt, Marker weg); Doku hängt am Rohstoff, Preis wird zur EK-Staffel. Ohne Antwort bleibt der Entwurf verborgen (kein Katalog-Müll). Aus anderen Kontexten (Rohstoffseite/Rezeptur/Angebot) öffnet dasselbe Popup weiterhin im **Preis-Modus** (Block sichtbar).
  - Zeilen hinzufügbar (z. B. Füllstoff).
- **Kapsel-Check** (bei Kapsel/Tablette/Softgel): Tagesdosis gesamt vs. Füllmenge der Zielgröße. Die **Zielgröße ist nur ein Vorschlag** (die Anfrage speichert keine Kapselgröße): solange das Team sie nicht selbst wählt, stellt der Check automatisch auf die **kleinste Größe, in die die Tagesdosis als eine Kapsel passt** (z. B. 800 mg → Größe 00) – passt gar nichts, die größte. Ändert das Team die Größe manuell, bleibt sie stehen. Die Menge je Zeile kommt aus der finalen Zuordnung (Spalte „Menge (mg)"); ist die noch leer, rechnet der Check ersatzweise mit der **Wunschmenge des Kunden** (links), umgerechnet nach Einheit (mg/g/µg; IE/ml zählen nicht). So funktioniert Summe + Aufteilung schon vor dem Ausfüllen der finalen Menge. passt nicht → **automatische Aufteilung** (z. B. 1000 mg, Größe 0 = 600 mg → 2 Kapseln/Tag). **Aufteilung anzeigen** blendet eine Tabelle ein (je Rohstoff: Tagesdosis, je Kapsel = Tagesdosis/Anzahl, Kapseln/Tag; Fußzeile: Füllgewicht je Kapsel vs. Kapazität). Zusätzlich **Alternativ-Hinweis**: kleinste Einzelgröße, in die die gesamte Tagesdosis ohne Aufteilung passen würde. Reine Anzeige – Rezeptur/Preis bleiben unverändert. **Mengen je Kapsel übernehmen** (Button) setzt die finale Menge (mg) jeder Zutat auf die Menge JE KAPSEL (Tagesmenge geteilt durch die benötigte Kapselzahl); Zeilen ohne mg (z. B. µg/IE) bleiben unverändert. Danach muss noch gespeichert werden. Nach dem Übernehmen passt die Summe in die Zielgröße. Bei Pulver/Stick/Flüssig: Hinweis „pro Portion", kein Kapsel-Limit.

**Ähnliche bestehende Rezepturen (Aufgabe 4, Stufe A – intern):** Unter der Zuordnung erscheint (sobald zugeordnete Rohstoffe gespeichert sind) ein Panel mit bis zu 3 bestehenden Rezepturen derselben Darreichungsform, die in der Zusammensetzung ähneln (`rezeptur_aehnliche()`, Mengen-Anteils-Überlappung). Je Treffer: Prozent-Ähnlichkeit, gemeinsame Zutaten und Klartext-Abweichungen (Mengen ±%, zusätzlich/fehlend). Rein zur Orientierung (Doppelarbeit vermeiden, Bewährtes wiederverwenden) – ändert nichts. Intern sieht das Team **alle** Rezepturen; die Kundensicht (Stufe B) ist bewusst noch nicht aktiv (nur Haus-/eigene Rezepturen erlaubt).

**Wunsch-Produktname:** Feld `rezeptur_anfrage.produktname` – vom Kunden im Portal angegebener Wunschname; dient beim „Rezeptur erstellen" als Default-Name der neuen Rezeptur.

**Kunde anlegen aus der Anfrage:** Hat die Anfrage **noch keinen Kunden** (`kunde_id` leer), erscheint über dem Formular ein grünes Panel „Kunde anlegen & verknüpfen". Die Kundendaten (Firma – Pflicht, Ansprechpartner, E-Mail, Telefon) lassen sich dort **prüfen/ergänzen**, dann legt `aktion=kunde_anlegen` in einem Schritt einen Kunden an (Kundennummer automatisch `K-…`, `portal_rezeptur` an, übrige Felder per DB-Default) und verknüpft ihn mit der Anfrage. So deckt der Weg beides ab: **direkt anlegen** oder **erst prüfen**. Alternativ unten weiterhin einen **bestehenden** Kunden aus der Auswahl wählen.

**Aktionen:**
- **Speichern:** Kopf (inkl. Wunsch-Produktname) + Wunsch-/Zuordnungs-Zeilen (`rezeptur_anfrage_wunsch`).
- **Vorschlag erstellen & senden:** legt aus den zugeordneten Zeilen (Rohstoff + finale Menge) eine **Rezeptur** an (`rezeptur` + `rezeptur_zutat`, Status direkt **`vorschlag`** = an den Kunden gesendet), verknüpft sie mit der Anfrage (`rezeptur_id`), setzt die Anfrage auf **beantwortet**, schreibt einen Verlaufseintrag und öffnet die neue Rezeptur. Nur sichtbar, wenn noch keine Rezeptur verknüpft ist.

**Anfrage und Vorschlag sind ZWEI getrennte Objekte (wichtig!):** Die Anfrage trägt die **Wunsch-Zuordnung** (`rezeptur_anfrage_wunsch`); der Vorschlag ist eine eigene **Rezeptur** (`rezeptur` + `rezeptur_zutat`) – und **nur die** sieht der Kunde im Portal (`?v=rezeptur&rid=`). „Nur speichern" schreibt ausschließlich die Anfrage; es ändert die verknüpfte Rezeptur **nicht**. Sonst sieht der Kunde weiter die alte Fassung.

**Buttons richten sich nach `rezeptur.status`** (nicht bloß danach, ob `rezeptur_id` gefüllt ist):
- **Syncbar** (`entwurf`/`abgelehnt`/`vorschlag` – noch nicht verbindlich): **Zuordnung in den Vorschlag übernehmen & senden** (`aktion=vorschlag_aktualisieren`). Baut `rezeptur_zutat` aus der aktuellen Zuordnung neu auf (in einer Transaktion), setzt `rezeptur.status='vorschlag'` + `rezeptur_anfrage.status='beantwortet'` und öffnet die Rezeptur. **So erreichen Änderungen den Kunden.** Dazu **Vorschlag/Entwurf öffnen** und ein Hinweis, dass Anfrage ≠ Vorschlag.
- **Verbindlich** (`freigegeben`/`eingefroren` – vom Kunden angenommen): nur **Vorschlag öffnen**; wird hier **nicht** überschrieben. Änderungen laufen über die Rezeptur.
- **Keine Rezeptur:** **Vorschlag erstellen & senden** (`aktion=rezeptur_erstellen`, siehe oben).

## Original-Anfrage & Verlauf (unveränderlich, nur intern)
Über dem KI-Panel steht ein grünes Panel **„Original-Anfrage & Verlauf"** – nur für uns, der Kunde sieht es nicht.
Bei **jeder inhaltlichen Änderung** einer Anfrage (Portal-Einreichung/-Bearbeitung durch den Kunden, Team-Speichern,
KI-Zeilen-Übernahme) wird der damalige Stand (Produktname, Form, Notiz + alle Wunschzeilen) **unveränderlich mit
Zeitstempel eingefroren** – Tabelle `rezeptur_anfrage_snapshot`, geschrieben per `rezeptur_anfrage_snapshot($id,$quelle)`
(`$quelle` = `kunde`|`team`; identische Folge-Snapshots per `inhalt_hash` übersprungen). So ist lückenlos belegt, was
ursprünglich gewünscht war und wie es sich geändert hat. Angezeigt werden alle Stände **ältester zuerst** (erster =
„Original – so eingereicht", als `<details>` offen), je Eintrag Zeitpunkt (`fmt_zeit`) + Quelle (Kunde/Team).
Snapshots werden nur geschrieben, nie geändert/gelöscht. Für Alt-Anfragen ohne Snapshots beginnt die Historie ab jetzt.

## Rezeptur entwickeln (KI)
Über dem Formular steht der Rezepturvorschlag aus der Kundenidee (`core/rezeptur_ki.md`): Zutaten mit Mengen, Novel-Food-Einschätzung, Höchstmengen, Health Claims, Machbarkeit und das **selbst nachgerechnete Füllgewicht** samt passender Kapselgröße. `aktion=ki_entwickeln` erzeugt ihn, `aktion=ki_zeilen` übernimmt die Zutaten in die Wunschzeilen. Ein vorhandener Vorschlag wird immer angezeigt, auch wenn gerade kein Schlüssel hinterlegt ist. Alles ist ein Entwurf – gesendet wird erst über den bestehenden Weg „Rezeptur erstellen".

## Anfrage löschen (nur Admin)
Im Kopf der Detailseite steht für **Admins** ein roter Knopf **„Anfrage löschen"** (`aktion=anfrage_loeschen`, mit Sicherheitsabfrage). Ruft `rezeptur_anfrage_loeschen($id)` (core/schema.php): entfernt die Rezepturanfrage + ihre Wunsch-Zeilen (`rezeptur_anfrage_wunsch`). Eine **daraus erstellte Rezeptur bleibt bestehen** (nur die Anfrage verschwindet). Danach zurück zur Liste mit Hinweis „Rezepturanfrage gelöscht". Nicht-Admins werden abgewiesen (`loeschfehler`).
