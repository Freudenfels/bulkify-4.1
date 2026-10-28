# einkauf/detail.php – Bestellung anlegen & bearbeiten

## In Einzelbestellungen aufteilen
Aktion `aufteilen` (`bestellung_aufteilen()` in `core/schema.php`): räumt **Alt-Bündel** auf, in denen mehrere Bulk-Produkte in einer Bestellung stecken. Erzeugt **je Bulk-Produkt eine eigene Bestellung** (Nicht-Bulk-Positionen in einen Sammelbeleg), sichert den Preis je Position als Snapshot (`bestellposition_ek`), **storniert** das Original und **informiert den Lieferanten** (Portal-Chat + Mail). Button erscheint im Status-Panel nur bei **≥ 2 verschiedenen Bulk-Produkten** und solange nichts geliefert/angekommen ist. Neu angelegte Bestellungen sind durch die geänderte Einkaufs-Logik ohnehin schon je Produkt getrennt – das Tool ist für Altbestand.

**Annahme-Stand wird übernommen (2026-10-28):** War das Original schon **bestätigt** (Lieferant hat zugesagt, erkennbar an `bestaetigt=1`, Status `bestaetigt` oder gesetzter `station`), erbt **jede** neue Einzelbestellung diesen Stand: `status`, `bestaetigt/_am/_von`, zugesagter Termin `eta_geplant`, `produktion_geplant`, `station` und die Versanddaten. Dann ist **keine erneute Bestätigung** nötig und die Lieferanten-Nachricht sagt das auch so. War es nur ein **Entwurf** (`offen`), bleiben die Teile Entwurf und der Lieferant wird nicht benachrichtigt. Nur bei noch **nicht** bestätigten, aber schon gesendeten Bestellungen gehen die Teile auf `gesendet` (einzeln zu bestätigen). So sind Bündel **immer** trennbar – egal ob bestätigt oder nicht –, ohne dass ein bereits erteilter Zuschlag verloren geht.

## Online-Einkauf
Ist die Bestellung über **„Online bestellt"** entstanden (`bestellart='online'`, ohne Lieferant), steht direkt unter dem Status ein Panel **Online-Einkauf** mit Karten **Händler/Quelle · Zahlungsart · Preis (gesamt) · Rechnung** (`online_haendler/_zahlart/_preis/_waehrung`). Die **Rechnung** (B3) ist die beim Bestellen hochgeladene Datei (`dokument` objekt_typ='bestellung', typ='rechnung') und als Download verlinkt. Read-only – erfasst beim Bestellen in der Einkaufsliste, dient der Buchhaltung zum Gegenrechnen. „Wareneingang buchen" funktioniert wie bei jeder Bestellung (so werden z. B. gelieferte Etiketten eingebucht).

## Ablauf beim Lieferanten
Unter dem Status steht das Panel **Ablauf** (`core/bestellung_ui.php`) – dasselbe, das der Lieferant in seinem Portal sieht: bestätigen mit geplantem Liefertermin, danach die Stationen (angenommen · Produktion · Qualität · versandbereit · versendet) und die Versanddaten (Anbieter, Versandart, Sendungsnummer). **„versendet" geht erst mit vollständigen Versanddaten.** In der Kopfzeile liegt **⇩ PDF** – der Beleg für den Lieferanten (`core/pdf_bestellung.md`).

**Zweck:** Eine Einkaufsbestellung (BE-) beim Lieferanten mit Positionen; beim Liefern werden daraus Lager-Chargen.

**Was passiert hier:**
- **Speichern:** Kopf (Lieferant, Notiz) in `bestellung` (neu = INSERT + BE-Nummer), Positionen in `bestellung_position` (Item + Menge + EK).
- **„als bestellt markieren":** Status → bestellt.
- **„Wareneingang buchen":** `bestellung_wareneingang()` legt für jede Position eine **Charge** an (`wareneingang_buchen`, Rohstoff → Quarantäne), Status → geliefert. Danach ist die Bestellung schreibgeschützt (`fieldset disabled`).

**Positionen (Tabelle, live):** Artikel-Auswahl (Rohstoff/Verpackung/Verbrauch) + Menge + **EK/Einheit** (wird beim Wählen aus dem Artikel vorbefüllt) → Summe je Zeile und Gesamt.

**Bulk-Zukauf – eine Zeile je Produkt:** Das Panel „Bulk-Zukauf (Fremdproduktion)" fasst die Bulk-Positionen desselben Produkts („Bulk: X (AB-…)" je Auftrag + die alte separate Puffer-/Lager-Zeile „Bulk: X") zur **Gesamtmenge je Produkt** zusammen. Gilt auch für Alt-Bestellungen (reine Anzeige-Gruppierung; die einzelnen Positionen bleiben in der DB mit Auftragsbezug für den Wareneingang). Dieselbe Zusammenfassung zeigen Lieferantenportal (`module/lieferant/bestellung.php`) und PDF (`core/pdf_bestellung.php`).

**Zusammenhang:** schließt die Beschaffungsseite – Bestellung → Lieferung → Bestand im Warenlager. Die Bestellungen erscheinen auch im **Lieferanten-Cockpit** (Einkauf gesamt + Reiter Bestellungen).

**Auftragsbezug (Baustein 4):** Je Bestellposition kann ein **offener Kundenauftrag** gewählt werden („Für Auftrag", sonst „Lager / allgemein"). Gespeichert in `bestellung_position.auftrag_id`. Beim Wareneingang (`bestellung_wareneingang()`) erbt die erzeugte **Charge** diesen Auftragsbezug (`charge.auftrag_id` + `charge.bestellung_position_id`). So weiß das System, wofür welche Ware gekommen ist – Grundlage für „Wareneingänge für diesen Auftrag" (Produktion/Cockpit) und später die automatische Produktionsweg-Ableitung.
## KI-Erfassung aus Dokument (mehrere Positionen)
Bei einer **neuen** Bestellung und eingerichteter KI (nur beta) erscheint oben das Panel **„KI-Erfassung aus Dokument"**: ein Lieferanten-Dokument (Bestellbestätigung/Angebot/Rechnung als PDF, Foto oder CSV/Excel) hochladen → `aktion=ki_lesen` speichert es kurz, schickt es via `ki_datei_frage()` an die KI und erwartet JSON `{lieferant, waehrung, positionen:[{bezeichnung, menge, einheit, ek_preis}]}`. Ergebnis landet in `$_SESSION['ki_best']`, Redirect auf `?p=bestellung&id=neu&ki=1`. Beim Rendern wird der **Lieferant** (normierter Firmenname) und **jede Position** auf einen Lagerartikel abgebildet (normierter Name, längster Überlapp gewinnt); das **Positionsformular wird vorbelegt** und ein grünes **Prüf-Panel** listet „erkannt → zugeordnet / Menge / EK". Nicht zugeordnete Zeilen sind markiert und werden von Hand gewählt. Nichts wird automatisch gespeichert – erst der normale „Speichern"-Knopf legt die Bestellung + Positionen an.

## E-Mail an den Lieferanten
„Als bestellt markieren" (`aktion=bestellt`) schickt dem Lieferanten die Bestell-Mail (`mail_lieferant_bestellung()`), sofern der Versand eingerichtet ist. Ein Mailfehler stoppt nichts, er wird oben auf der Seite gezeigt.
## Rückfragen an den Lieferanten
Unter dem Ablauf-Panel steht **Rückfragen** (`core/nachricht.php`, Bezug `bestellung`): Fragen an den Lieferanten und seine Antworten zu genau dieser Bestellung, POST `aktion=nachricht`. Der Lieferant sieht dasselbe Panel im Portal; bei eingerichtetem Versand geht je Nachricht eine Mail an die andere Seite.
