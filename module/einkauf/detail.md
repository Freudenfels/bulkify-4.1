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
## KI-Erfassung aus Dokument (mehrere Positionen)
Bei einer **neuen** Bestellung und eingerichteter KI (nur beta) erscheint oben das Panel **„KI-Erfassung aus Dokument"**: ein Lieferanten-Dokument (Bestellbestätigung/Angebot/Rechnung als PDF, Foto oder CSV/Excel) hochladen → `aktion=ki_lesen` speichert es kurz, schickt es via `ki_datei_frage()` an die KI und erwartet JSON `{lieferant, waehrung, positionen:[{bezeichnung, menge, einheit, ek_preis}]}`. Ergebnis landet in `$_SESSION['ki_best']`, Redirect auf `?p=bestellung&id=neu&ki=1`. Beim Rendern wird der **Lieferant** (normierter Firmenname) und **jede Position** auf einen Lagerartikel abgebildet (normierter Name, längster Überlapp gewinnt); das **Positionsformular wird vorbelegt** und ein grünes **Prüf-Panel** listet „erkannt → zugeordnet / Menge / EK". Nicht zugeordnete Zeilen sind markiert und werden von Hand gewählt. Nichts wird automatisch gespeichert – erst der normale „Speichern"-Knopf legt die Bestellung + Positionen an.

## E-Mail an den Lieferanten
„Als bestellt markieren" (`aktion=bestellt`) schickt dem Lieferanten die Bestell-Mail (`mail_lieferant_bestellung()`), sofern der Versand eingerichtet ist. Ein Mailfehler stoppt nichts, er wird oben auf der Seite gezeigt.
## Rückfragen an den Lieferanten
Unter dem Ablauf-Panel steht **Rückfragen** (`core/nachricht.php`, Bezug `bestellung`): Fragen an den Lieferanten und seine Antworten zu genau dieser Bestellung, POST `aktion=nachricht`. Der Lieferant sieht dasselbe Panel im Portal; bei eingerichtetem Versand geht je Nachricht eine Mail an die andere Seite.
