# lager/verpackung_detail.php – Verpackung anlegen & bearbeiten

**Zweck:** Detailseite einer Verpackung. Schreibt in die `item`-Tabelle mit fest `kategorie='verpackung'` (einheit/preis_bezug = Stück).

**Was passiert hier:**
- **Speichern (POST):** Pflichtfeld Name; schreibt die Verpackungs-Felder ins `item` (neu = INSERT + VP-Nummer + Verlaufseintrag, sonst UPDATE). Leeres Volumen/Füllgewicht → NULL, leerer EK → 0, leere Rolle → `primaer`.
- **Kapsel-Fassung speichern (POST, aktion=kapsel_save):** eigenes Formular (außerhalb des Haupt-Formulars, um verschachtelte `<form>` zu vermeiden). Schreibt je Kapselgröße die Stückzahl in `pack_kapazitaet` (erst DELETE für dieses Item, dann INSERT der Zeilen mit Stück > 0).
- **Anzeige (GET):** lädt das Item (nur wenn kategorie=verpackung), Lieferantenliste, Rollen, Kapselgrößen und die gespeicherte Kapsel-Fassung.

**Rolle in der Stückliste (`verpackung_rolle`):** jede Verpackung hat eine Funktion – **Primärverpackung** (hält das Produkt direkt: Dose/Glas/Beutel), **Verschluss/Deckel**, **Etikett**, **Faltschachtel/Karton**, **Beipackzettel**. Die Rolle steuert per JS, welche Felder/Reiter sichtbar sind (Art, Volumen, Füllgewicht, Kapsel-Fassung nur bei Primär; Etikett-Format nur bei Etikett).

**Die Reiter:**
- **Stammdaten** – Artikelnummer (VP-, automatisch), Name, **Rolle**, Art, Material, **Etikett-Format**, Farbe, **Maße** (Breite/Höhe/Durchmesser/Tiefe in mm) + **Leergewicht (g)**, Sperr-Schalter, Notiz. Bei Etikett-Rolle = Etikettenmaße (Breite × Höhe); Durchmesser/Tiefe nur bei Primärverpackung. Leergewicht ist Basis für die PPWR-Meldung.
- **Einkauf** – Haupt-Lieferant + **Mengenstaffel (EK)** (`pack_ek_staffel`: **Lieferant je Stufe** `lieferant_id` + ab-Menge + EK; eigenes Formular ekstaffel_save). Flacher `item.ek_preis` = niedrigste Stufe (auto). Effektiver EK je Menge via `pack_ek_bei_menge()`.
- **Verkauf** – **VK-Aufschlag (%)** (`item.vk_aufschlag_prozent`, leer = globaler `aufschlag_verpackung`), **Effektiver-VK-Tabelle** (Quelle: „fester VK" oder „Aufschlag X %") und **„Verkaufspreis von Hand" (VK-Staffel)** = direkter VK je Bestellmenge in `pack_vk_staffel` (Formular vkstaffel_save). `verpackung_vk_bei_menge()` nimmt zuerst den festen VK, sonst EK × Aufschlag.
- **Füllmengen** (nur Primär, bestehende Verpackung; eigenes Formular aktion=fuell_save) – „wie viel passt rein" je Form: **Pulver max. Füllgewicht (g)** (`max_fuellgewicht_g`), **Flüssig Füllvolumen (ml)** (`volumen_ml`) und die **Kapsel-Tabelle** je Kapselgröße (`pack_kapazitaet`). Ersetzt den früheren Reiter „Kapsel-Fassung". Eigener Speichern-Button; Haupt-Speichern ausgeblendet. **Umkarton (je 1 Glas):** zweites Formular (aktion=umkarton_save) im selben Reiter – Umkarton-Artikel (Rolle `karton`) auswählen → wird als `item.karton_item_id` am Behälter gespeichert; Maße (B×T×H) des gewählten Umkartons werden angezeigt; **Druckvorlage-PDF** direkt hochladen (landet als `verpackung_dokument kategorie='druckvorlage'` am Umkarton-Artikel). Der Kunde lädt diese Vorlage im Portal neben dem PIB mit herunter (`?v=umkarton_vorlage`).
- **Dokumente (PPWR)** – Nachweise je Verpackung: Upload (Kategorie PPWR-Nachweis / Konformität (DoC) / Spezifikation / Etikett-Druckdatei / Sonstiges, Titel, Datei) + Liste mit Download und Löschen. Dateien in `data/uploads`, Tabelle `verpackung_dokument`; Route `verpackung_dok`.
- **Verwendung** / **Verlauf**.

**Kennzahlen-Kacheln:** Status · Art · Volumen · EK-Preis.

**Die drei Zuordnungs-Kennzahlen** (Reiter Füllmengen; je nach Darreichungsform greift im Produkt genau eine):
- **Kapsel/Softgel** → Kapsel-Fassung (Stück je Kapselgröße, `pack_kapazitaet`).
- **Pulver/Granulat/Stick** → max. Füllgewicht (g).
- **Flüssig** → Volumen (ml).

**Verkauf-Sicht (Rollen):** Der Reiter **Verkauf** (VK-Aufschlag + VK-Staffel) wird nur angezeigt, wenn `darf_verkauf()` (core/auth.php) wahr ist – Produktionsmitarbeiter (Werk-Bereich) sehen ihn NICHT (EK/Einkauf bleibt). Beim Speichern durch eine Produktionsrolle wird `vk_aufschlag_prozent` NICHT auf null gesetzt, sondern der vorhandene Wert erhalten.

**Gesperrt/inaktiv (Block C, 11.11.2026):** Gesperrte Lieferanten/Kunden bzw. inaktive Produkte/Rezepturen stehen nicht mehr in den Auswahlfeldern. Ein schon gewählter Eintrag bleibt am Beleg sichtbar.

## Reiter Lager (12.11.2026)
Neuer Reiter **Lager** (vorher gab es auf der Verpackungsseite gar keinen Bestand): Kacheln Bestand frei, Quarantäne, Chargen, Blinker; Tabelle der Chargen mit Bestand mit **Ort** (Blinker/Kiste/Platz, `core/lager_ort.php`) und **Bestand korrigieren** (nur Admin, Grund Pflicht, `charge_menge_korrigieren()` wie Rohstoffseite und Lager-App; Aktion `charge_bestand_korr`). Der Reiter steht außerhalb des Hauptformulars (nie Formular im Formular), der Haupt-Speichern-Knopf ist dort ausgeblendet. Aus dem Warenlager öffnet ein Klick direkt diesen Reiter.

## Preise 6 Stellen (13.11.2026)
EK- und VK-Staffel: Textfeld mit Auswahl „je Stk“ / „je 1.000 Stk“ (`preis_eingabe_lesen()`), gespeichert mit 6 Nachkommastellen. 2,95 je 1.000 Stk = 0,00295 je Stk.

**Zahlenfelder des Browsers (13.11.2026, Fable-3 T1.3):** Felder mit `type="number"` senden immer den Punkt als Dezimaltrenner. Sie werden mit `(float)` gelesen, nie mit dem deutschen Mengen-Parser (sonst würde „2,125“ zu 2125). Hier: max. Füllgewicht (g) und Volumen (ml).

**Preis-Anzeige (13.11.2026, Fable-3 T4.7):** Preise laufen durch die zentrale `preis_zahl()` (core/schema.php): bis 6 Nachkommastellen, Nullen am Ende weg, mindestens 2 – Leerkapseln 0,00295 stehen nicht mehr als 0,0030. Nur Anzeige, gerechnet wird mit dem gespeicherten Wert.

**Vorbelegung ohne Tausenderpunkt (Fable-4 T2.4, 13.11.2026):** Mengen-/Preisfelder werden mit `zahl_feld()` (Komma, kein Tausenderpunkt) vorbelegt, damit Speichern ohne Änderung den Wert nicht verändert.

## Druckvorlage für bedruckte Beutel (13.11.2026)
Reiter „Druckvorlage“ (nur Verpackungsart Beutel): Gesamtformat, Beschnitt, Sicherheitsabstand, Flächen (Name, Lage in mm von oben links, Haken „auf dem Kopf“ = 180°), Siegelnähte und Zipper (Zonen ohne Text), optional Stanzkontur-PDF. Speichern über `etp_vorlage_aus_post()` (core/etikett_pruefung.php) in `item.beutel_vorlage`; die Aufgabe „Vorlage für … fehlt“ wird dabei erledigt. Das Etikett-Pop-up zeigt damit die Flächen beschriftet, Siegel/Zipper schraffiert, prüft Text in diesen Zonen und schickt jede Fläche einzeln (gedrehte richtig herum) an die KI.

**Fable-6 Teil 1 (13.11.2026):** Die Etikett-Maße (Breite, Höhe, Format, Endformat, Druckmaß) an Etikett-Artikeln und Behältern ändert nur ein Admin, und nur mit Grund (Feld „Grund für eine Änderung des Etikett-Maßes“). Die Änderung steht im Protokoll (route `etikett_mass_admin`) und im Verlauf. Andere Rollen sehen einen Hinweis; ihre Maß-Änderung wird nicht übernommen.
