# detail.php – ein Kontakt (Cockpit)

Route `?p=kontakt&id=…`, zwei Reiter (wie die Kundenseite):
- **Übersicht** – die Akte: Wiedervorlage, To-Dos, **Notiz/Aktivität** (Verlauf mit Typen Telefonat,
  Kontaktversuch, Besprechung, WhatsApp, E-Mail …), KI-Auswertung, KI-Rezepturvorschlag, Fragenkatalog,
  Antwort/Senden, Verlauf, Stammdaten (Name/Firma/Phase/Kontakt/Zuständig/Notiz), Verkauf, Dokumente,
  **Zum Kunden machen**.
- **Profil** (`&tab=profil`, `tun=profil_speichern` → `kontakt_profil_speichern`) – die gleichen Felder
  wie beim Kunden: Qualifizierung (`crm_segfelder()`), Land/Website/Möglichkeiten/Besonderheiten, freies
  **Infos**-Feld und die strukturierte Anfrage. Die Daten liegen an `crm_kontakt`.

Stammdaten (Übersicht) und Profil speichern getrennt (`kontakt_speichern` vs. `kontakt_profil_speichern`),
damit die beiden Formulare sich nicht gegenseitig leer schreiben.

**Kontakt** = jemand, der uns kontaktiert hat, aber noch keinen Dashboard-/Portalzugang hat; wird er
Besteller, wird er zum Kunden (eigene Seite). Beim Öffnen steht die Akte im Vordergrund, nicht die Adresse.

**KI-Auswertung der Anfrage** (`tun=ki_auswerten` → `core/lead_ki.php`) ordnet die Anfrage aus der Notiz ein: Zusammenfassung, Produktform, Menge, grober Wert, nächster Schritt. Setzt den geschätzten Wert (falls leer) und eine Wiedervorlage, Ergebnis landet im Verlauf. Website-Leads werden beim Eingang automatisch ausgewertet; der Knopf dient zum Wiederholen oder für Kontakte, die ohne KI hereinkamen. Nur sichtbar, wenn die KI eingerichtet ist und eine Notiz vorhanden ist.

**To-Dos** (Karte oben, `tun=todo_add`/`todo_erledigt` → `core/todo.php`) listet die offenen Aufgaben dieses Kontakts und legt neue an (Titel, Kategorie, Fälligkeit). Erscheinen auch in der zentralen To-Do-Liste (`?p=todos`).

**KI-Rezepturvorschlag** (`tun=rezeptvorschlag` → `core/rezeptur_ki.php`) entwickelt aus der Anfrage (strukturierte Felder + Notiz) einen herstellbaren Vorschlag: Zutaten + Mengen, Novel Food, Höchstmengen, Health Claims, Machbarkeit und die passende Kapselgröße. Wird am Kontakt gespeichert (`crm_rezeptur_ki`), „Neu entwickeln" überschreibt, „Verwerfen" löscht. Entwurf fürs Team – keine Freigabe; eine echte Rezeptur entsteht über „Rezeptur anlegen" im Dashboard.

**Antwort vorschlagen** erzeugt über `core/antwort_ki.php` einen Entwurf aus Notiz und Verlauf. Er steht in der Sitzung, nicht in der Datenbank; verschickt wird nichts. Wer ihn wirklich abgeschickt hat, drückt auf „Als gesendet im Verlauf vermerken“.

**Verkäufer-Workflow:** Die „Daten"-Karte pflegt zusätzlich **Zuständig**, die **Segmentierung** (Qualifizierung, aufklappbar) und die **strukturierten Anfrage-Felder**. Die Karte **Verkauf** legt per Knopf ein Angebot oder eine Rezeptur im Dashboard an (Kunde wird – falls nötig – zuvor über die `erp.php`-Naht angelegt, dann Sprung ins Dashboard; `erp_dashboard_link()`) und zeigt die bestehenden Angebote/Rezepturen des Kunden (nur gelesen). Die Karte **Dokumente** legt Angebote/Abschlüsse/Rechnungen am Kontakt ab (`crm_kontakt_datei`, Download über `kontakt_doc.php`).

**Zum Kunden machen** ist – neben dem Kunden-Anlegen hinter „Angebot/Rezeptur" – die Stelle, die ins Dashboard schreibt. Beides geht ausschließlich über `core/erp.php` (`erp_kunde_anlegen()`); Angebot/Rezeptur selbst erstellt das Dashboard. Deshalb steht eine Rückfrage davor.
