# index.php – Der einzige Web-Einstieg (Front Controller)

**Zweck:** Jede Anfrage an bulkify 4.1 läuft durch diese eine Datei. Sie entscheidet, welche Seite (Modul) angezeigt wird. So gibt es keine 255 einzeln aufrufbaren Dateien mehr.

**Was passiert hier – Schritt für Schritt:**
1. Lädt `core/schema.php` und `core/layout.php` (damit DB, Bausteine und Layout bereitstehen).
2. Ruft `init_schema()` auf – stellt sicher, dass alle Tabellen existieren (harmlos, wenn sie schon da sind).
3. **Router:** Es gibt eine feste Liste erlaubter Seiten (`$routes`), z. B. `kunden` → `module/kunde/liste.php`. Der Parameter `?p=...` wählt die Seite. Unbekannte Werte landen automatisch auf dem Dashboard.
4. Bindet die gewählte Modul-Datei ein.

**Wichtig / Regel:**
- Neue Seiten werden **hier in `$routes` eingetragen** – sonst sind sie nicht erreichbar. Das ist die zentrale Sicherheits- und Ordnungsstelle (kein direkter Dateizugriff von außen).
- Der Web-Server zeigt nur den Ordner `public/` – alles andere (core, module, data) liegt geschützt dahinter.

## Feld-Änderungsprotokoll (Aufräumen 8)
Tabelle `aenderung_log` (objekt_typ, objekt_id, feld, alt, neu, benutzer, route, erstellt). `public/index.php` ruft bei jedem POST `aenderung_beobachten($route, $id)` auf: Der Hauptdatensatz der Seite (`aenderung_routen()`: Auftrag, Angebot, Produkt, Rezeptur, Kunde, Lieferant, Bestellung, Artikel, Produktionsauftrag, Kontingent, Dienstleistung, Benutzer) wird vorher gelesen und nach der Antwort (auch nach Redirect) verglichen. Jedes geänderte Feld wird mit alt/neu, Person und Zeit protokolliert (`aenderung_protokollieren()`). Technische und geheime Felder (Zeitstempel, Token, Passwort-Hash, Caches) sind ausgenommen. Anzeige: `?p=protokoll&ansicht=felder`, filterbar nach Bereich, Objekt-ID und Text. Positionen und Untertabellen werden nicht erfasst.

`?p=api_qm_freigabe` (POST charge_id, token, wer): Loopback der Lager-App nach einer QM-Freigabe -> `fertigware_qm_freigabe_nach()`.

`api_qm_freigabe` (04.11.2026): zusätzlich `bericht` (1/0) und `anlass` (`produktion` = Produktionsabschluss) – die Werk-App meldet frisch produzierte Fertigware.

`api_versand` (Soll-Kette 7, 04.11.2026): Loopback für die Lager-App (Token wie Einlagern). `aktion=versenden` (auftrag_id, menge) → `auftrag_versenden_menge()` (Fertigware FEFO, Lieferschein-Beleg, Status, Rechnung, Kundenmail), Antwort mit `ls_nummer`; `aktion=fremdlager` → `auftrag_ins_fremdlager()`.

- `labor_senden` → `module/lager/labor_senden.php`: Proben ans Labor, Auftragsformular (PDF) automatisch ausfüllen (05.11.2026). Rollen wie `laboranalysen`.

- `portal_registrieren` → `module/portal/registrieren.php`: öffentliche Kunden-Registrierung, Kunde gesperrt bis zur Freigabe (05.11.2026). Steht in `$PUBLIC`.

## produktion_neu (08.11.2026)
Route `produktion_neu` → `module/produktion/neu.php` (Produktion (neu), im Aufbau), nur Admin.

## Menü-Umschalter (08.11.2026)
Nach dem Login-Check und der Routen-Auflösung: POST mit `bx_menu_modus` (`einfach`/`ausfuehrlich`) speichert `benutzer.menu_modus` des
angemeldeten Nutzers und leitet auf dieselbe URL (GET) zurück. Ändert keine Rechte. Details: `core/layout.md`.

- `weg_festlegen` → `module/auftrag/weg_festlegen.php` (Pop-up „Weg festlegen“, 10.11.2026).

- `artikel_zusammenfuehren` → `module/lager/artikel_zusammenfuehren.php` (Dubletten zusammenführen, 10.11.2026).

## rohstoff_steckbrief (11.11.2026)
`?p=rohstoff_steckbrief` -> `module/lager/rohstoff_steckbrief.php`: KI-Steckbrief erstellen oder bearbeiten (POST, nur intern).

**api_versand aktion=fertig (11.11.2026):** `ids` (Komma-Liste) -> `{ok, fertig:[ids]}`, welche Aufträge wirklich versandbereit sind (`auftrag_status_kunde`). Für die Lager-App.
