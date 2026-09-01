# angebot/detail.php – Angebot bearbeiten (Hybrid)

## Wozu
Interner Editor für ein Angebot. **Hybrid-Modell** (wie in v3 `buchhaltung_beleg.php`,
aber schlanker): die Positionen werden **automatisch** aus Produkt + Preismatrix + Verpackung
erzeugt, sind aber **überschreibbar**. Dazu die interne Marge (VK vs. EK, nur intern).

## Kopfdaten (Formular `aktion=kopf_save`)

**Kein Produkt in den Kopfdaten:** Das Produkt ergibt sich aus den **Positionen** (Rezeptur × Menge + Verpackung) und ist deshalb kein Eingabefeld mehr. Entsteht das Angebot aus einer Produktanfrage, ist `angebot.produkt_id` automatisch gesetzt – dann steht es als Text im Kopf und reist beim Speichern unsichtbar mit (Hidden-Feld), damit die Preismatrix im Portal erhalten bleibt.

**Status: `offen` = Entwurf, `gesendet` = beim Kunden.** Das Kundenportal zeigt **nur** Angebote ab `gesendet` – ein Entwurf ist unsichtbar. Vorher fehlte dieser Filter (seit dem ersten Commit): Sobald über „Im Angebots-Editor bauen" ein Angebot mit Status `offen` entstand, sah der Kunde es sofort als „Angebot liegt vor – bitte wählen", noch bevor eine einzige Position drin stand. **Zurückziehen** setzt den Status wieder auf `offen` – dasselbe Angebot, beim Kunden verschwunden, hier weiter bearbeitbar. Einen eigenen Status „zurückgezogen" gibt es bewusst nicht.

**„An Kunden senden" ist ein eigener Knopf.** Das Speichern der Kopfdaten ändert den Status **nie** – sonst wäre ein Angebot versehentlich beim Kunden. Ablauf: Angebot anlegen → Positionen bauen → **An Kunden senden** (setzt `gesendet`, sichert die Produkte via `angebot_produkte_sichern()`, setzt die Anfrage auf „beantwortet") → bei Bedarf **Zurückziehen** (zurück auf `offen`). Ein Angebot **ohne Positionen** wird nicht gesendet. Der Status ist im Kopf nur noch eine Anzeige; `bestätigt`/`abgelehnt` setzt der Kunde im Portal.

**Wunsch aus der Anfrage vorbelegt:** Hängt am Angebot eine `anfrage_id`, füllt „Position hinzufügen" die Felder vor: Rezeptur, Menge je Packung (Stück bzw. Füllmenge), Anzahl Packungen und ein zum Wunsch-Verpackungstyp passender Behälter (über `passende_behaelter_fuer()` + `verpackung_passt_zu_typ()`). Darüber steht, was übernommen wurde. Vorher musste das Team alles aus der Anfrage abtippen.

**Angebot zurückziehen:** Knopf oben rechts, nur solange der Status `offen` oder `gesendet` ist – ein bestätigtes Angebot hängt bereits an einem Auftrag. Setzt den Status auf `zurueckgezogen` und schreibt einen Verlaufseintrag am Kunden. Im Portal fällt das Angebot damit automatisch aus der Annehmen-Logik (die prüft `status IN ('offen','gesendet')`) und wird als „zurückgezogen" angezeigt.
Kunde, Status, Gültig bis, **Marge (%)** (`angebot.marge_override`),
**Produktionszeit (Wochen)** (`angebot.produktionszeit_wochen`), Notiz. Beim Speichern wird
für das Produkt bei Bedarf die Preismatrix erzeugt. Marge/Produktionszeit wirken auf die
**automatischen** Positionen (bei Overrides bleibt die gespeicherte Position stehen).

## Positionen (Formular `aktion=pos_save`)
Editierbare Tabelle: Bezeichnung (+ Beschreibung), Menge, Einheit, Preis/Einh, MwSt; dazu
je Zeile EK/Marge und Gesamt (live per JS). Positionen hinzufügen/entfernen.
- **Speichern friert ein**: schreibt `angebot_position` (Overrides). Ab dann haben diese
  Zeilen Vorrang vor der Automatik – im Editor, im Angebots-PDF und überall über
  `angebot_positionen()`.
- **Automatik wiederherstellen** (`aktion=pos_reset`): löscht die Overrides → wieder
  automatisch berechnet.
- **Interne Marge**: Summe VK vs. Summe EK (aus `ek_cent` je Position), nur intern.

## Rezeptur in der Position
Die automatische Herstellungsposition enthält in der **Beschreibung** die Rezeptur
(Zutaten je Einheit), z. B. „Herstellung · 90 Kapseln / Rezeptur je Kapsel: Zutat X 400 mg · …".
Das Beschreibungsfeld ist eine **mehrzeilige Textarea** (editierbar). Im PDF werden die Zeilen
umgebrochen. Spalte „Bezeichnung" 400 px breit; `angebot_position.beschreibung` = VARCHAR(1000).

## Position hinzufügen (Typ zuerst)
Panel über den Positionen: erst **Typ** wählen (`#addTyp`), dann kommt der passende Katalog. Ein festes
Produkt wählt man NICHT mehr (das ergab keinen Sinn – man will die Stückzahl frei bestimmen). Jede
Position wird als eigene **Gruppe A/B/C** angehängt (`angebot_gruppe_anhaengen()` – friert Automatik ein,
vergibt den nächsten Buchstaben, normalisiert die A)/B)-Präfixe).
- **Rezeptur** (`add_rezeptur`) → Rezeptur + Stückzahl + Anzahl Packungen + Verpackung/Deckel/Etikett →
  `angebot_rezeptur_zeilen()`: Herstellung (Rezepturkosten je Einheit × Stück + Leerkapsel nach
  Kapselgröße, × Marge) + gewählte Verpackungen als eigene Positionen. Preis kommt aus der Rezeptur, ohne
  Produkt-SKU.
- **Rohstoff** (`add_rohstoff`) → Rohstoff + Menge + Einheit → `angebot_rohstoff_zeile()` (EK-Staffel ×
  Verpackungs-/Rohstoff-Aufschlag, Kundenrabatt).
- **Dienstleistung** (`add_dienstleistung`) → Bezeichnung + Menge + Preis (freie Position).

„**+ freie Position**" in der Positionstabelle bleibt für schnelle manuelle Zeilen/Zuschläge.

Hinweis: Das frühere „Produkt zusammenstellen" (Produkt-Auswahl) ist ersetzt. Für Alt-Angebote ohne
Anfrage nutzt die Automatik weiter die Produkt-Standardmenge `einheiten_pro_packung` (nicht die kleinste
Matrixstufe). Die PDF-Staffel „Preis je fertiges Produkt" erscheint nur bei rein automatischer
Kalkulation (nicht bei manuell zusammengestellten Angeboten).

## Multiprodukt (Gruppen A–Z)
Fragt der Kunde mehrere Produkte/Konfigurationen an (aus `portal_anfrage_pos`), erzeugt die
Automatik je Konfiguration (Produkt·Stück/Füllmenge·Verpackung) eine **Gruppe** mit Buchstaben
A, B, C … (Präfix in der Bezeichnung + `angebot_position.gruppe`). Mehrere angefragte Mengen
derselben Konfiguration erscheinen als **Staffel untereinander** in „Preis je fertiges Produkt".
Der Gruppen-Buchstabe wird im Editor als Hidden-Feld erhalten.

## Datenquelle (zentral)
`core/schema.php`:
- `angebot_positionen($angebot_id)` – gespeicherte Overrides ODER automatisch.
- `angebot_positionen_auto($angebot)` – Herstellung (angefragte Konfiguration aus
  `angebot.anfrage_id`) + Verpackung (Dose/Deckel/Etikett **extra**).
- `angebot_matrix()`, `angebot_ust_satz()`, `angebot_hat_positionen()`.
Dieselbe Quelle nutzt das Angebots-PDF (`module/portal/kunde.php` `?v=angebot_pdf`).

## Hinweis
Die Kunden-Portalansicht (Matrix mit „Zelle annehmen") ist weiterhin die explorative Sicht;
das Angebots-PDF ist das verbindliche Dokument und respektiert die Overrides. „PDF ansehen"
verlinkt auf die Portal-PDF-Route mit dem Kunden-Token.
Das alte Staffelpreis-Modell (`angebot_staffel`) wird hier nicht mehr bearbeitet.
