# crm/core/rezeptur_ki.php – KI-Rezepturvorschlag im CRM

**Zweck:** Aus dem Anfrage-Text eines Leads (oder einer Freitext-Idee am Kunden) einen herstellbaren,
verkehrsfähigen **Rezepturvorschlag** entwickeln – direkt im CRM nutzbar: Zutaten mit Mengen,
Novel-Food- und Höchstmengen-Einschätzung, Health Claims, Machbarkeit und die passende **Kapselgröße**
(aus unserer eigenen Rechnung, nicht aus der KI).

Übernimmt Prompt und Logik aus der Dashboard-Version (`core/rezeptur_ki.php`), liest Rohstoffkatalog,
Rohstoff-Zuordnung und Kapselgrößen aber über die **eine Naht** `crm/core/erp.php`
(`erp_rohstoff_katalog`, `erp_rohstoff_finden`, `erp_item_info`, `erp_kapsel_passend`) – das CRM fasst
Dashboard-Tabellen nirgends direkt an. Prompt **pflegbar** in `crm/prompts/rezepturvorschlag.md`.

## Funktionen
- `rezeptur_ki_entwickeln($text, $form='kapsel')` – entwickelt den Vorschlag (großes Modell, `denken`),
  bildet Zutaten auf unseren Katalog ab, rechnet die Kapselgröße selbst. Wirft nicht.
- `rezeptur_ki_merken($typ,$id,$vorschlag)` / `rezeptur_ki_vorschlag($typ,$id)` / `rezeptur_ki_loeschen(...)`
  – Vorschlag am Vorgang merken/holen/löschen (`typ` = `kontakt`|`kunde`, Tabelle `crm_rezeptur_ki`, JSON).
- `rezeptur_ki_html($v)` – Anzeige (Zutaten, Ampel-Bewertungen, Kapselgröße, Hinweise), ohne Emojis.
- `rezeptur_ki_formen()` – Darreichungsformen für die Auswahl.

## Wichtig
Entwurf fürs Team, **keine Freigabe**: Novel Food, Höchstmengen und Claims muss ein Mensch prüfen. Die
Kapselgröße ist eine Tatsache aus unseren Größen – der KI ist verboten, selbst eine zu nennen. Eine echte
Rezeptur entsteht weiterhin im Dashboard (Knopf „Rezeptur anlegen" auf der Kontaktseite). Eingebunden in
`crm/module/kontakt/detail.php` (aus der Anfrage) und `crm/module/kunde/detail.php` (Freitext-Idee).
