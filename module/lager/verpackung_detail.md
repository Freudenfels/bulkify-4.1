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
- **Füllmengen** (nur Primär, bestehende Verpackung; eigenes Formular aktion=fuell_save) – „wie viel passt rein" je Form: **Pulver max. Füllgewicht (g)** (`max_fuellgewicht_g`), **Flüssig Füllvolumen (ml)** (`volumen_ml`) und die **Kapsel-Tabelle** je Kapselgröße (`pack_kapazitaet`). Ersetzt den früheren Reiter „Kapsel-Fassung". Eigener Speichern-Button; Haupt-Speichern ausgeblendet.
- **Dokumente (PPWR)** – Nachweise je Verpackung: Upload (Kategorie PPWR-Nachweis / Konformität (DoC) / Spezifikation / Etikett-Druckdatei / Sonstiges, Titel, Datei) + Liste mit Download und Löschen. Dateien in `data/uploads`, Tabelle `verpackung_dokument`; Route `verpackung_dok`.
- **Verwendung** / **Verlauf**.

**Kennzahlen-Kacheln:** Status · Art · Volumen · EK-Preis.

**Die drei Zuordnungs-Kennzahlen** (Reiter Füllmengen; je nach Darreichungsform greift im Produkt genau eine):
- **Kapsel/Softgel** → Kapsel-Fassung (Stück je Kapselgröße, `pack_kapazitaet`).
- **Pulver/Granulat/Stick** → max. Füllgewicht (g).
- **Flüssig** → Volumen (ml).

**Verkauf-Sicht (Rollen):** Der Reiter **Verkauf** (VK-Aufschlag + VK-Staffel) wird nur angezeigt, wenn `darf_verkauf()` (core/auth.php) wahr ist – Produktionsmitarbeiter (Werk-Bereich) sehen ihn NICHT (EK/Einkauf bleibt). Beim Speichern durch eine Produktionsrolle wird `vk_aufschlag_prozent` NICHT auf null gesetzt, sondern der vorhandene Wert erhalten.
