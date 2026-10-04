# detail.php – ein Kontakt

Route `?p=kontakt&id=…`. Von oben nach unten: offene Wiedervorlagen, eine Notiz hinzufügen, **Antwort vorschlagen**, der Verlauf, die Stammdaten, und der Knopf **Zum Kunden machen**.

Die Reihenfolge ist Absicht: Beim Öffnen will man zuerst wissen, was zuletzt war und was ansteht – nicht die Adresse pflegen.

**KI-Auswertung der Anfrage** (`tun=ki_auswerten` → `core/lead_ki.php`) ordnet die Anfrage aus der Notiz ein: Zusammenfassung, Produktform, Menge, grober Wert, nächster Schritt. Setzt den geschätzten Wert (falls leer) und eine Wiedervorlage, Ergebnis landet im Verlauf. Website-Leads werden beim Eingang automatisch ausgewertet; der Knopf dient zum Wiederholen oder für Kontakte, die ohne KI hereinkamen. Nur sichtbar, wenn die KI eingerichtet ist und eine Notiz vorhanden ist.

**Antwort vorschlagen** erzeugt über `core/antwort_ki.php` einen Entwurf aus Notiz und Verlauf. Er steht in der Sitzung, nicht in der Datenbank; verschickt wird nichts. Wer ihn wirklich abgeschickt hat, drückt auf „Als gesendet im Verlauf vermerken“.

**Verkäufer-Workflow:** Die „Daten"-Karte pflegt zusätzlich **Zuständig**, die **Segmentierung** (Qualifizierung, aufklappbar) und die **strukturierten Anfrage-Felder**. Die Karte **Verkauf** legt per Knopf ein Angebot oder eine Rezeptur im Dashboard an (Kunde wird – falls nötig – zuvor über die `erp.php`-Naht angelegt, dann Sprung ins Dashboard; `erp_dashboard_link()`) und zeigt die bestehenden Angebote/Rezepturen des Kunden (nur gelesen). Die Karte **Dokumente** legt Angebote/Abschlüsse/Rechnungen am Kontakt ab (`crm_kontakt_datei`, Download über `kontakt_doc.php`).

**Zum Kunden machen** ist – neben dem Kunden-Anlegen hinter „Angebot/Rezeptur" – die Stelle, die ins Dashboard schreibt. Beides geht ausschließlich über `core/erp.php` (`erp_kunde_anlegen()`); Angebot/Rezeptur selbst erstellt das Dashboard. Deshalb steht eine Rückfrage davor.
