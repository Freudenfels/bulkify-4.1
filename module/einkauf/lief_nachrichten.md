# lief_nachrichten.php – Lieferanten-Nachrichten (Team-Übersicht)

## Wozu
Ein Ort fürs Team, an dem **alle Chat-Verläufe mit Lieferanten** zusammenlaufen – statt verstreut an einzelnen Anfragen und Bestellungen. Ungelesenes zuerst, mit Filtern und Volltextsuche. Route: `?p=lief_nachrichten` (Rechte: `einkauf`, `finance`).

## Aufbau
Zwei Spalten:
- **Links** die Verläufe, gruppiert nach Lieferant **und** Vorgang (Preisanfrage / Bestellung / allgemeines Gespräch). Ungelesene oben, dann nach letzter Aktivität. Jede Zeile: Lieferant (Name + Nummer), Vorgang + Produkt/Rohstoff, letzter Text-Ausschnitt, Datum, Ungelesen-Zähler.
- **Rechts** der ausgewählte Verlauf (eigene Nachrichten rechts/„bulkify", die des Lieferanten links), mit Anhängen je Nachricht und einem Antwortfeld inkl. Datei-Upload (`anhang[]`, mehrere möglich). „zum Vorgang" verlinkt die passende Detailseite.

## Filter & Suche
- **Lieferant** und **Produkt** als Dropdown (gefüllt aus den vorhandenen Verläufen).
- **Volltextsuche** über Nachrichtentext, Lieferant (Name/Nummer), Produkt/Rohstoff und Anfrage-/Bestellnummer. Die Textsuche nutzt `LIKE ESCAPE '='` (Backslash crasht MySQL live). Treffer werden per `<mark>` hervorgehoben; der Thread-Link springt per `#m<id>` direkt zur gefundenen Nachricht.

## Lesen / Schreiben
- Beim Öffnen eines Verlaufs gilt er als vom Team gelesen (`nachrichten_thread_gelesen()`), noch vor dem Header, damit der Nav-Zähler sofort stimmt.
- Antworten laufen über `nachricht_post_verarbeiten($lief,'team',…,$bezug_typ,$bezug_id)` – also denselben Weg wie überall, inkl. Mail + Anhang-Ablage.

## Technik
Threads kommen aus einem `GROUP BY lieferant_id, bezug_typ, bezug_id` über `nachricht`; Stammdaten (Lieferant, letzter Text) werden einmal vorgeladen, der Rest je Thread über die Helfer aus `core/nachricht.php` angereichert. Produkt/Rohstoff über `nachricht_bezug_produkt()`. Gerendert wird der Verlauf hier selbst (nicht über `nachricht_panel`), damit der bezuglose „Allgemein"-Thread sauber nur seine eigenen Nachrichten zeigt.

## Nav
Menüpunkt **Einkauf → Nachrichten** mit Badge (ungelesene gesamt, `nachrichten_ungelesen_gesamt()`). Benachrichtigungen vom Typ „Nachricht vom Lieferanten" verlinken direkt hierher.
