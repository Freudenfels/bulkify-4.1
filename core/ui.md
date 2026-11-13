# ui.php – Wiederverwendbare Bausteine

## status_text()
Wandelt einen Statuswert aus der Datenbank in lesbares Deutsch **mit Umlauten**: in der DB stehen die Werte bewusst ohne (`bestaetigt`, `zurueckgezogen`, `quarantaene`), auf dem Bildschirm gehören sie richtig geschrieben. Wird überall dort genutzt, wo ein Status ohne eigene Übersetzung ausgegeben wird (die `default`-Zweige der Status-Badges). Unbekannte Werte werden nur aufgehübscht (Unterstrich raus, erster Buchstabe groß) statt verschluckt.

**Zweck:** Fertige Funktionen für immer gleiche UI-Teile (Listen, Reiter, Buttons, Chat). Eine neue Seite baut damit ihr Aussehen zusammen, statt HTML von Hand – so bleibt alles einheitlich.

**Was drin ist:**
- `bx_head($titel, $sub, $aktionen)` – Seitenkopf mit Titel und optionalen Buttons rechts.
- `bx_btn($label, $href, $variante)` – ein Button (primary / accent / ghost / danger).
- `bx_badge($text, $art)` – ein Status-Etikett (ok / warn / err / info).
- `pdf_btn($href, $label='PDF', $stopRow=false, $title='')` – PDF-Download-Button (Dokument-Icon + Label, Klasse `.pdfbtn`), klar als PDF erkennbar statt nacktem Pfeil. `$stopRow=true` in klickbaren Listenzeilen (verhindert den Zeilen-Klick). Genutzt in Angebote-Liste/-Portal, Bestellungen-Liste, Portal-Anfrage-Detail.
- `kunde_link($kunde_id, $firma)` – Kundenname als Link zum Kunden-Cockpit (`?p=kunde&id=…`), Klasse `.kundenlink` (in `app.css`: **immer unterstrichen**, damit die direkte Verbindung zum Kunden sichtbar ist; Textfarbe bleibt, Hover grün). In klickbaren Listenzeilen (`rowUrl`) verhindert `event.stopPropagation()`, dass der Zeilen-Klick zusätzlich auslöst. Ohne Firma „–", ohne id nur Text. **Überall** verwendet, wo ein Kundenname erscheint: alle Listen (Angebote/Anfragen/Aufträge/Produkte/Produktion/Versand/Rechnungen), Detailseiten (Auftrag/Rechnung/Produktion), Werk-Cockpit, Chargen und Dashboard.
  **Internes Kürzel (08.11.2026):** hat der Kunde `kunden.kuerzel`, zeigt der Link das Kürzel, voller Firmenname als Tooltip.
- `kunde_kuerzel($kunde_id)` – internes Kürzel ('' = keins); alle Kürzel einmal je Aufruf geladen (eine Abfrage). **Nur interne
  Tabellen** – nie in PDFs, Mails oder im Kundenportal verwenden.
- `kunde_anzeige($kunde_id, $firma, $kurz=false)` – wie `kunde_link`, aber ohne Link (Kürzel mit Tooltip, sonst Firma;
  `$kurz=true` = ohne Kürzel `firma_kurz()` für schmale Spalten).
- `kunde_link(..., $voll=true)` – immer voller Name: für Detailseiten-Köpfe (Auftrag, PA, DL-Auftrag, Kontingent) – Kürzel nur in Tabellen.
- `kunde_kuerzel_html($kz, $firma)` – Kürzel + voller Name in `<span hidden>`, damit Tabellen-Suchfelder (textContent)
  den Kunden weiter über den Firmennamen finden.
- `bx_hint($text)` – das kleine **ⓘ**-Symbol mit Tooltip für Hinweise.
- `bx_tabs($tabs, $aktiv, $baseUrl)` – Reiter, die per Link umschalten.
- `bx_table($cols, $rows, $opts)` – **die Listen-Tabelle**: definiert Spalten (Label, sortierbar, eigene Darstellung), macht Kopfzeilen anklickbar zum Sortieren, Zeilen anklickbar zum Öffnen. Optional `group` (fn($row) => HTML): Zwischenüberschrift je Gruppe, sobald sich der Wert ändert (Zeilen vorher nach der Gruppe sortieren) – z. B. Produkte je Rezeptur.
- `bx_sort_rows($rows, $key, $dir)` – sortiert Daten für die Tabelle.
- `bx_chat($eintraege, $gegenName)` – der **Aktivitätsverlauf als Tabelle** (seit 03.11.2026, vorher Chat). Neueste zuerst; Spalten Datum · Ereignis (verlinkt über `aktivitaet_link()`) · Produkt · Von (bulkify · Benutzer / Gegenstelle / System) · Bereich, darüber ein Suchfeld (filtert im Browser). Produkt über `aktivitaet_produkt()` aus dem Bezug (Auftrag, Angebot, Rezeptur, Produkt, Rechnung, Bestellung, Artikel, Dokument) oder einer Belegnummer im Text. Angezeigt kompakt als „Rezeptur · Größe“, der volle Name als Tooltip. Genutzt bei Kunde, Lieferant, Partner, Bestellung und Verpackung.
- `aktivitaet_link($ref_typ, $ref_id)` – wandelt einen Bezug (z. B. `angebot` 1001) in eine Ziel-URL um. Sobald das Modul existiert, springt der Link automatisch dorthin.

**Wichtig / Regel:**
- Neue Listen und Formulare **immer über diese Bausteine** bauen, nie eigenes HTML pro Seite. Änderungen am Aussehen passieren zentral hier bzw. in `assets/app.css`.

## Quarantäne statt „fehlt" (Nacht 1)
Liegt die fehlende Menge in Quarantäne, steht dort gelb „in Quarantäne … – freigeben" (wartet auf QM) statt rot „fehlt". Reicht die Quarantäne nicht, bleibt „fehlt" mit Hinweis auf den Quarantäne-Teil. Dashboard-Helfer `bx_fehlt_quar()` (core/ui.php). Produktion bleibt bis zur Freigabe blockiert.

`kunde_nachricht_panel($kunde_id, $auftrag_id, $angebot_id, $dataPanel)`: Verlauf der Kunden-Nachrichten + Antwortformular (aktion=kunde_nachricht) für Auftrag/Angebot.

Dark Mode (03.11.2026): Die Chat-Blasen von `bx_chat()` sind im Dark Mode dunkel getönt (Team grün, Gegenstelle lime) mit heller Schrift. Auch verlinkte Einträge (↗) und das Datum sind lesbar (`public/assets/app.css`). Vorher blieben die Flächen hell, Links nutzten die helle Dark-Mode-Schrift.

**Schalter (Block C, 11.11.2026):** `bx_inaktiv_an()` / `bx_inaktiv_schalter($anzahl)` – Listen blenden Gesperrte/Inaktive standardmäßig aus, `?inaktiv=1` zeigt sie (Knopf im Seitenkopf mit Anzahl).

**Lange Namen (13.11.2026):** `bx_table()` setzt Namens-Spalten (name, produkt_name, produkt, artikel, rohstoff, bezeichnung, titel, item_name) automatisch einzeilig mit „…“ (`.bx-lauf`, max. 320 px), beim Darüberfahren Lauftext. Je Spalte `'lauf' => px` oder `'lauf' => false`.

**Pop-up je Zeile (13.11.2026):**
- `rowPop` (Funktion → Adresse): Ein Klick öffnet die Seite als Pop-up (`data-pop-url`).
- `rowPopTitel`: Titel des Pop-ups.
- `rowId` (Funktion → id): id der Zeile. Nach dem Speichern im Pop-up wird genau diese Zeile neu geladen.

## Nachbesserung Fable-5 (13.11.2026)
`bx_sym($art, $titel)` – Symbole als SVG statt Schriftzeichen: `ok` (Haken), `nein` (Kreuz), `weiter` (Dreieck), `offen` (Kreis), `oeffnen` (Pfeil). Farbe über currentColor.
