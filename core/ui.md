# ui.php – Wiederverwendbare Bausteine

## status_text()
Wandelt einen Statuswert aus der Datenbank in lesbares Deutsch **mit Umlauten**: in der DB stehen die Werte bewusst ohne (`bestaetigt`, `zurueckgezogen`, `quarantaene`), auf dem Bildschirm gehören sie richtig geschrieben. Wird überall dort genutzt, wo ein Status ohne eigene Übersetzung ausgegeben wird (die `default`-Zweige der Status-Badges). Unbekannte Werte werden nur aufgehübscht (Unterstrich raus, erster Buchstabe groß) statt verschluckt.

**Zweck:** Fertige Funktionen für immer gleiche UI-Teile (Listen, Reiter, Buttons, Chat). Eine neue Seite baut damit ihr Aussehen zusammen, statt HTML von Hand – so bleibt alles einheitlich.

**Was drin ist:**
- `bx_head($titel, $sub, $aktionen)` – Seitenkopf mit Titel und optionalen Buttons rechts.
- `bx_btn($label, $href, $variante)` – ein Button (primary / accent / ghost / danger).
- `bx_badge($text, $art)` – ein Status-Etikett (ok / warn / err / info).
- `kunde_link($kunde_id, $firma)` – Kundenname als Link zum Kunden-Cockpit (`?p=kunde&id=…`), Klasse `.kundenlink` (in `app.css`: **immer unterstrichen**, damit die direkte Verbindung zum Kunden sichtbar ist; Textfarbe bleibt, Hover grün). In klickbaren Listenzeilen (`rowUrl`) verhindert `event.stopPropagation()`, dass der Zeilen-Klick zusätzlich auslöst. Ohne Firma „–", ohne id nur Text. **Überall** verwendet, wo ein Kundenname erscheint: alle Listen (Angebote/Anfragen/Aufträge/Produkte/Produktion/Versand/Rechnungen), Detailseiten (Auftrag/Rechnung/Produktion), Werk-Cockpit, Chargen und Dashboard.
- `bx_hint($text)` – das kleine **ⓘ**-Symbol mit Tooltip für Hinweise.
- `bx_tabs($tabs, $aktiv, $baseUrl)` – Reiter, die per Link umschalten.
- `bx_table($cols, $rows, $opts)` – **die Listen-Tabelle**: definiert Spalten (Label, sortierbar, eigene Darstellung), macht Kopfzeilen anklickbar zum Sortieren, Zeilen anklickbar zum Öffnen.
- `bx_sort_rows($rows, $key, $dir)` – sortiert Daten für die Tabelle.
- `bx_chat($eintraege, $gegenName)` – der **Aktivitäts-Chat** (links = wir, rechts = Gegenstelle: Kunde **oder** Lieferant). Verknüpfte Einträge werden über `aktivitaet_link()` anklickbar.
- `aktivitaet_link($ref_typ, $ref_id)` – wandelt einen Bezug (z. B. `angebot` 1001) in eine Ziel-URL um. Sobald das Modul existiert, springt der Link automatisch dorthin.

**Wichtig / Regel:**
- Neue Listen und Formulare **immer über diese Bausteine** bauen, nie eigenes HTML pro Seite. Änderungen am Aussehen passieren zentral hier bzw. in `assets/app.css`.
