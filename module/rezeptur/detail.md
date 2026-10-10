# rezeptur/detail.php – Rezeptur anlegen & bearbeiten (mit Live-Deklaration)

**Zweck:** Der Rezeptur-Baukasten – Kopf + Zutatenliste + automatische Nährwert-Deklaration und Kostenkalkulation. Herzstück der Produktentstehung.

**Was passiert hier:**
- **Speichern (POST):** Kopf (Name, Kunde, Darreichungsform, Status, Notiz) in `rezeptur` (neu = INSERT + RZ-Nummer). Zutaten werden in `rezeptur_zutat` synchronisiert (item_id + Name-Snapshot + Menge mg).
- **Anzeige (GET):** lädt Rezeptur, Zutaten, Kundenliste und alle Rohstoffe **inkl. ihrer Wirkstoffe** (für die Berechnung).

**Kopf-Felder:** Name, Kunde, Darreichungsform (Kapsel/Tablette/Softgel/Stick/Pulver/Flüssig), Status, Notiz. **Kunde gewählt = eigene Rezeptur DIESES Kunden** (`kunde_id` + `exklusiv=1`, erscheint beim Kunden, nur für ihn). **Leer = Hausrezeptur** (Katalog, `exklusiv=0`).

**Zutaten:** Zeilen aus Rohstoff + Menge (mg je Einheit). Das Rohstoff-Feld ist ein **Tippfeld mit Filter** (`<datalist>`): einfach lostippen, die Vorschläge grenzen sich auf das Getippte ein; die Auswahl setzt ein verstecktes `z_item[]` (die Rohstoff-id), Anzeige = Label „Name · Form · Art.-Nr". Beim Speichern zählt nur eine echte Auswahl (leere/unpassende Zeilen werden übersprungen). „+" fügt eine Zeile hinzu.

**Live-Deklaration & Kalkulation (rechnet im Browser mit, pro Einheit):**
- **Gesamtgewicht** (Summe der Mengen).
- **Kapselgröße** (nur bei Kapsel/Softgel): das System bestimmt live die kleinste Kapselgröße (aus den Einstellungen), in die das Gesamtgewicht passt – grün bei Treffer, rot „passt in keine (aufteilen)", wenn es die größte Kapsel übersteigt. **Bei Pulver/Flüssig/Stick/Tablette erscheint keine Kapselgröße** (dort zählt die Portion) – vermeidet die falsche v3-Warnung „Kapselgröße nicht bestimmbar" bei Nicht-Kapseln.
- **Kosten je Einheit** und **je 1.000 Stück** (Menge × EK-Preis des Rohstoffs).
- **Inhaltsstoffe (Etikett-Stil):** pro Zutat die Menge, darunter je Wirkstoff eine „**– davon [Wirkstoff]**"-Zeile mit Menge (mg/µg) und **% NRV** – genau die Deklarationsform vom Etikett. Rechnung: Menge × Gehalt % = mg Wirkstoff; ÷ NRV = % NRV.
- **Summe je Nährstoff:** gleiche Nährstoffe aus mehreren Rohstoffen **addiert** (z. B. Magnesium aus zwei Magnesium-Formen → Gesamt-Magnesium). Nährstoffe ohne NRV (z. B. Curcumin) zeigen die Menge ohne %.
- Hinweis: Das **Gesamtgewicht** zählt JEDE Zutat (auch ohne zugeordneten Rohstoff), Kosten/Nährstoffe nur bei zugeordnetem Rohstoff.

**Serverseitiges Panel „Kosten & Einwaage" (pro Einheit · aus Lieferanten-Kilopreisen):** unter dem Haupt-Formular (nur bei bestehender Rezeptur). Zeigt robust (auch bei freigegebener/gesperrter Rezeptur, unabhängig vom JS) die **Gesamt-Einwaage je Einheit** (Summe aller Zutaten-Mengen in mg + g = Füllmenge pro Portion) und die **Materialkosten je Einheit + je 1.000 Stück**. Kosten je Rohstoff = `EK/kg × Menge`, wobei der EK aus den **Lieferanten-Staffelpreisen** kommt (`rezeptur_materialkosten()` → `rohstoff_ek_bei_menge()`/`rohstoff_bester_lieferant()`, günstigste passende Staffel bei der eingestellten Batchgröße; Fallback `item.ek_preis`). Batchgröße über das Feld „Staffel bei … Stück" (GET `kalk_stueck`, Default 1000) umstellbar. Je Zutat werden Menge/Einheit, EK €/Bezug + Lieferant und Kosten/Einheit gelistet; Zutaten ohne hinterlegten Lieferantenpreis werden markiert und fehlen in der Summe. Ergänzt die browserseitige Live-Kalkulation (die weiter das statische `item.ek_preis` als Richtwert nutzt).

**Lebenszyklus / Freigabe:** oben ein Status-Baustein: **Entwurf → Vorschlag → eingefroren** (verbindlich). Aktionen: „Als Vorschlag senden", „Freigeben & einfrieren", bei einer eingefrorenen Rezeptur „Neue Version" (Kopie als Entwurf) und „Bearbeitung öffnen" (zurück zu Entwurf). **Eingefroren/freigegeben = schreibgeschützt**: die Bearbeitung ist über ein `<fieldset disabled>` gesperrt (kein Speichern), die Deklaration bleibt sichtbar; serverseitig wird ein Edit-Speichern bei gesperrtem Status abgewiesen.

**Löschen (nur Admin):** Knopf „Löschen" (nur `has_role('admin')`, in jedem Status) → `aktion=loeschen` → `rezeptur_loeschen($id)`. **Blockiert**, solange die Rezeptur verwendet wird (Produkt, Angebotsposition, Produktionsauftrag, Bulk-/Fertig-Lagerartikel) – mit Hinweis, was sie noch hält. Sonst werden die eigenen Nebendaten gelöscht (Zutaten, Kundenpreise, Lieferanten-Angebote) und Verweise aus Anfragen/Scans auf NULL gesetzt (die Anfrage bleibt bestehen), dann die Rezeptur entfernt (Transaktion). Fehlertext läuft über `$_SESSION['rez_del_fehler']`, Erfolg → zurück zur Liste (`?p=rezeptur&geloescht=1`).

**Kapselgröße (nur Kapsel/Softgel):** Feld `kapselgroesse_id` an der Rezeptur. Auswahl „automatisch (nach Füllgewicht)" oder feste Größe. Die Kapselgröße **gehört zur Rezeptur** und wird vererbt: `rezeptur_kapselgroesse()` bevorzugt die gespeicherte Größe (sonst kleinste passende nach Füllgewicht) → bestimmt die Leerkapsel-Kandidaten des Produkts (`produkt_leerkapsel_kandidaten`/`produkt_leerkapsel_id`) → und damit die **Packungsgröße** (wie viele Kapseln je Gebinde, `pack_kapazitaet` je `kapselgroesse_id`). Produkt-Live-Rechnung und Portal (Rezeptur-Detail + Vorschlag) zeigen dieselbe Größe.

**Wichtig:** Mengen gelten **pro Einheit** (Kapsel/Portion) – der Kunde bestimmt die Einnahme/Tag selbst.

**I.E./Einheiten-Formen:** Rohstoffe, deren Wirkstärke in **Internationalen Einheiten** angegeben ist (z. B. Vitamin D3 100.000 I.E./g), fließen jetzt korrekt in die Deklaration ein. Am Rohstoff wählt man die Gehalt-Einheit `I.E./g` (bzw. `I.E./kg`), am Nährstoff steht der Umrechnungsfaktor `ie_mg` (mg je 1 I.E.). Die zentrale Funktion `wirkstoff_mg_je_mg()` normalisiert jeden Gehalt (%, mg/g, µg/g, I.E./g, I.E./kg) auf mg Nährstoff je mg Rohstoff; die Deklaration zeigt zusätzlich die I.E. und – wo hinterlegt – die Etiketteinheit (µg RE, mg α-TE, mg NE …).

**Grenzen aktuell:** Vitamin E: der I.E.-Faktor unterscheidet sich zwischen natürlicher und synthetischer Form – Standard ist die natürliche (0,67 mg/I.E.), synthetisch am Nährstoff überschreiben. Flüssige EK-Kosten werden über die Dichte angenähert.

## Rohstoffpreise-Tabelle: R-Nummer-Link + CoA/Spec-Vorschau
Je Rohstoff: Name **und die Artikelnummer (R-xxxx)** verlinken auf den Rohstoff (`?p=rohstoff&id=`).
Spalte **CoA / Spec** zeigt je vorhandenem Dokument (dokument objekt_typ='item', typ spec/coa/analyse)
einen Knopf → öffnet eine **Popup-Vorschau** (Overlay mit iframe auf `?p=dokument&id=`, inline) samt
**Download**-Link (`bxDocOeffnen()/bxDocZu()`). Originale bleiben teamintern (Route `dokument`).

## Zutaten-Zeilen: R-Nummer-Link + CoA/Spec (auch bei festgesetzter Rezeptur)
Unter jeder Zutat steht eine Aktionszeile (`.zactions`): „↗ Rohstoff R-xxxx" (Link zum Rohstoff) und je
Dokument ein CoA/Spec-Anchor, der die Popup-Vorschau (`bxDocOeffnen`) öffnet. Bewusst **Anchor-Links**
(`<a>`), damit sie auch in einer **festgesetzten (eingefrorenen/freigegebenen) Rezeptur** funktionieren –
dort ist das `<fieldset disabled>`, was nur Formularfelder sperrt, Anchors nicht. Daten: `$ZNR`
(id→Artikelnummer), `$ITEMDOCS` (id→spec/coa/analyse) + JS `zactions(row)` (aktualisiert beim
Rohstoff-Wechsel und für neue Zeilen).

## „Wo wird diese Rezeptur verwendet?" (Verwendungs-Übersicht)
Panel über dem Status (nur bestehende Rezepturen). Helfer `rezeptur_verwendung($id)` (core/schema.php) listet ALLE Verweise klickbar: **Produkt** (Blocker), **Lagerartikel Bulk/Fertigware** (Blocker, kein eigener Link), **Produktionsauftrag** (Blocker), **Angebot-Position** (Blocker), **abgeleitete Rezeptur (Basis)**, sowie die direkten Verknüpfungen **Auftrag/Angebot/Beleg** (rezeptur_id-Override). Mit „Blocker"-Badge markiert sind genau die, die das Löschen (`rezeptur_loeschen`) verhindern – dort zuerst entfernen/ersetzen, dann löschen.

### Leere Bulk-Artikel blockieren nicht mehr
`rezeptur_loeschen` blockiert beim Lagerartikel (Bulk/Fertigware, item.rezeptur_id) nur noch, wenn **Bestand** (Chargen) dranhängt. LEERE Bulk-Artikel sind reine Nebenprodukte der Rezeptur und werden beim Löschen **automatisch mitentfernt**. Die Verwendungs-Übersicht markiert sie entsprechend („leer, wird beim Löschen automatisch entfernt" = kein Blocker).

## Nährwerte der Rezeptur: automatisch vs. festgeschrieben (Snapshot + Override)
Panel **„Nährwerte der Rezeptur"** (unter dem Haupt-Formular, eigenes `<form>` – nie verschachtelt). Datenmodell:
Tabelle `rezeptur_naehrwert` (je Einheit: name, menge_mg intern, nrv_wert, einheit mg/µg, quelle auto|manuell) + Flag
`rezeptur.naehrwerte_fixiert`. Zentrale Funktionen in `core/schema.php`:
- `rezeptur_naehrwerte_ableiten($rid)` – Live-Ableitung aus `rezeptur_zutat → item_wirkstoff → naehrstoff` (identisch zur Etikett-Deklaration, nutzt `wirkstoff_mg_je_mg`).
- `rezeptur_naehrwerte($rid)` – **effektiv**: fixiert → gespeicherte Zeilen, sonst Ableitung. Diese Funktion nutzt auch das Kundenportal-Produktdetail.
- `rezeptur_naehrwerte_snapshot($rid)` – schreibt die abgeleiteten Werte fest (quelle=auto), setzt das Flag; überschreibt eine bereits manuelle Pflege NICHT; leere Ableitung → kein Fixieren.
- `rezeptur_naehrwerte_speichern($rid,$rows)` – manuelle Deklaration (quelle=manuell), Eingabe in der gewählten Einheit, intern immer mg.
- `rezeptur_naehrwerte_zuruecksetzen($rid)` – zurück auf automatisch.

**Snapshot-Hooks:** beim Freigeben/Einfrieren im Dashboard (`status_setzen`, Ziel freigegeben|eingefroren) und beim Annehmen im Portal (`rezeptur_annehmen`). So verschiebt sich die Deklaration nicht mehr, wenn später Rohstoffdaten wechseln. Der Override ist bewusst **auch im gesperrten Zustand** erlaubt (genau dafür: eine festgeschriebene Deklaration korrigieren, wenn Rohstoffe keine/falsche Wirkstoffdaten haben). POST-Aktionen: `naehrwerte_speichern`, `naehrwerte_fixieren`, `naehrwerte_auto`.

## Überarbeitungsmodus: eingefrorene Rezeptur entsperren, Rohstoffe neu matchen
Importierte Rezepturen zeigen oft auf nicht gematchte Rohstoffe (Freitext/kein Rohstoff/ohne Wirkstoffe) →
leere Nährwerte, keine CoA/Spec. Admin-Weg ohne Statuswechsel: Button **„Überarbeiten"** (nur Admin, nur
freigegeben/eingefroren) setzt `$_SESSION['rez_unlock'][id]=<status>`. Dann ist `$locked=false` (`$imUmbau`),
die Zutaten sind editierbar, ein Banner weist darauf hin. Beim normalen **Speichern** wird der ursprüngliche
Status wiederhergestellt, die Nährwerte aus den korrigierten Zutaten neu festgeschrieben
(`rezeptur_naehrwerte_zuruecksetzen` + `_snapshot`) und die Entsperrung beendet. Der Kunde sieht währenddessen
nichts (Status bleibt gespeichert). Aktionen: `ueberarbeiten_start`, `ueberarbeiten_abbrechen`. Der
Zutaten-Editor zeigt je Zeile einen Match-Badge (`rezeptur_zutat_match`): „nicht zugeordnet / Rohstoff fehlt /
ohne Wirkstoffdaten"; bei Freitext-Zeilen wird der ursprüngliche `bezeichnung`-Name als Suchhilfe vorbefüllt.
Worklist aller betroffenen Rezepturen: [umbau.md](umbau.md).

## Fix 2026-10-07: Spec/CoA-Popup auch bei neuer Rezeptur
Das Dokument-Popup (`bxDocOverlay` + `bxDocOeffnen()`) lag früher im Block `if ($rzZutaten)` (Panel „Rohstoffpreise“, nur bei gespeicherten Rezepturen). Bei einer NEUEN Rezeptur war die Funktion daher nicht definiert – Klick auf „Spez. (bulkify)“ in der Zutatenliste tat nichts. Overlay + Script jetzt ausserhalb des `if` (immer gerendert).

## Dropdown: Wirkstoffgehalt-Marker (2026-10-07)
Im Rohstoff-Picker (datalist `zutat_dl`) zeigt das Label `· Gehalt ✓`, wenn der Rohstoff einen nutzbaren Wirkstoffgehalt hat (mind. ein item_wirkstoff mit basePerMg>0, `$gehaltSet`). Marker ist Teil des Anzeige-Labels in datalist UND `ZMAP` (muss identisch sein, sonst matcht die Auswahl nicht); beim Speichern irrelevant, da `bezeichnung` aus `item.name` kommt.

## Kundenrezeptur: Annahme kommt vom Kunden (Stand 2026-10-08)
Bei **kundenspezifischen** Rezepturen (kunde_id gesetzt) ist der Hauptweg jetzt „Als Vorschlag an den Kunden senden" (→ status `vorschlag`). Der KUNDE nimmt den Vorschlag im Portal selbst an (`rezeptur_annehmen` → `eingefroren`, mit seinem Namen als Unterzeichner/`freigabe_name`). Die direkte Team-Abkürzung zu `eingefroren` ist nur noch ein klar getrennter, de-emphasierter Admin-Ausnahmebutton „Ohne Kundenannahme verbindlich setzen" (mit Rückfrage; setzt KEIN freigabe_name → keine falsche Kunden-Zuschreibung). **Hausrezepturen** (kunde_id NULL, Katalog) sind unverändert. Grund: „freigeben" durch das Team darf nicht so wirken, als hätte der Kunde angenommen.
