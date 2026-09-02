# einkauf/detail.php – Bestellung anlegen & bearbeiten

## Ablauf beim Lieferanten
Unter dem Status steht das Panel **Ablauf** (`core/bestellung_ui.php`) – dasselbe, das der Lieferant in seinem Portal sieht: bestätigen mit geplantem Liefertermin, danach die Stationen (angenommen · Produktion · Qualität · versandbereit · versendet) und die Versanddaten (Anbieter, Versandart, Sendungsnummer). **„versendet" geht erst mit vollständigen Versanddaten.** In der Kopfzeile liegt **⇩ PDF** – der Beleg für den Lieferanten (`core/pdf_bestellung.md`).

**Zweck:** Eine Einkaufsbestellung (BE-) beim Lieferanten mit Positionen; beim Liefern werden daraus Lager-Chargen.

**Was passiert hier:**
- **Speichern:** Kopf (Lieferant, Notiz) in `bestellung` (neu = INSERT + BE-Nummer), Positionen in `bestellung_position` (Item + Menge + EK).
- **„als bestellt markieren":** Status → bestellt.
- **„Wareneingang buchen":** `bestellung_wareneingang()` legt für jede Position eine **Charge** an (`wareneingang_buchen`, Rohstoff → Quarantäne), Status → geliefert. Danach ist die Bestellung schreibgeschützt (`fieldset disabled`).

**Positionen (Tabelle, live):** Artikel-Auswahl (Rohstoff/Verpackung/Verbrauch) + Menge + **EK/Einheit** (wird beim Wählen aus dem Artikel vorbefüllt) → Summe je Zeile und Gesamt.

**Zusammenhang:** schließt die Beschaffungsseite – Bestellung → Lieferung → Bestand im Warenlager. Die Bestellungen erscheinen auch im **Lieferanten-Cockpit** (Einkauf gesamt + Reiter Bestellungen).

**Auftragsbezug (Baustein 4):** Je Bestellposition kann ein **offener Kundenauftrag** gewählt werden („Für Auftrag", sonst „Lager / allgemein"). Gespeichert in `bestellung_position.auftrag_id`. Beim Wareneingang (`bestellung_wareneingang()`) erbt die erzeugte **Charge** diesen Auftragsbezug (`charge.auftrag_id` + `charge.bestellung_position_id`). So weiß das System, wofür welche Ware gekommen ist – Grundlage für „Wareneingänge für diesen Auftrag" (Produktion/Cockpit) und später die automatische Produktionsweg-Ableitung.
## E-Mail an den Lieferanten
„Als bestellt markieren" (`aktion=bestellt`) schickt dem Lieferanten die Bestell-Mail (`mail_lieferant_bestellung()`), sofern der Versand eingerichtet ist. Ein Mailfehler stoppt nichts, er wird oben auf der Seite gezeigt.
## Rückfragen an den Lieferanten
Unter dem Ablauf-Panel steht **Rückfragen** (`core/nachricht.php`, Bezug `bestellung`): Fragen an den Lieferanten und seine Antworten zu genau dieser Bestellung, POST `aktion=nachricht`. Der Lieferant sieht dasselbe Panel im Portal; bei eingerichtetem Versand geht je Nachricht eine Mail an die andere Seite.
