# kunde_neutral.php – Der Kunde sieht nie, wer wirklich produziert (Paket 12.11.2026 Teil 3)

Zentrale Schutz-Stelle für alles, was an den Kunden geht (Kundenportal, Downloads, Belege, Mails an Kunden).
Neue Kundenseiten benutzen diese Funktionen, statt selbst Dateien oder Texte auszugeben (Handbuch, Grundprinzip 10a).

| Funktion | Wirkung |
|---|---|
| `kunde_dok_gesperrt($dok)` / `kunde_dok_sql('d')` | Unterlagen eines Herstellers/Lieferanten gehen nie an den Kunden: Original-Spec/CoA/Analyse am Rohstoff, alles in Ablagen des Lieferanten (lieferant, bestellung, nachricht, rezeptur, lieferant_anfrage), alles vom Lieferanten hochgeladen, alles mit `lieferant_id` eines Herstellers/Händlers. Reine Labore (Kategorie nur „labor“) sind erlaubt (externe Laborberichte). |
| `kunde_dateiname($dok)` | neutraler Dateiname `<Typ>_<Produkt>_<Charge>.pdf`, nie der Original-Dateiname |
| `kunde_dok_titel($dok)` | Anzeige-Titel ohne Original-Dateinamen |
| `kunde_pdf_neutral($bytes)` | Autor, Ersteller, Programm, Titel (Info-Wörterbuch und XMP) leeren, ohne Längen zu ändern; komprimierte Metadaten bleiben (selten) |
| `kunde_text_neutral($text)` | Firmennamen der Lieferanten (mit und ohne Rechtsform, ab 4 Zeichen, ohne bulkify/Labore) durch „bulkify“ ersetzen |
| `kunde_position_text($text)` | wie oben, zusätzlich „Handelsware“ entfernen (Belege, Angebotskarte) |
| `kunde_ist_mailadresse($email)` | Kunde? Dann neutralisiert `mail_senden()` Betreff und Text |
| `kunde_datei_ausliefern($pfad, $name)` | eine Stelle für Downloads an Kunden (Header, neutraler Name, PDF-Metadaten leer) |

Angeschlossen: `module/portal/dokument_download.php` (auch Zugriff nur auf eigene Objekte), `module/portal/kunde.php`
(Laboranalyse, Auftrags-Dokumente, Zertifikate, Druckvorlagen, Rohstoff-Dokumente, DL-Ergebnis, Rückfrage-Chat,
DL-Schritte, KI-Info), `core/dokument_ui.php` (Liste + Freigabe-Schalter), `core/laboranalyse.php`,
`module/lager/laboranalysen.php`, `core/pdf_beleg.php`, `module/portal/_angebot_karte.php`, `core/mail.php`,
`core/pdf_spec.php` (CoA: Lieferant nur Nummer, Methode), `core/coa_uebersicht.php`, `core/echtheit.php`,
`module/produktion/_bericht_inhalt.php` (Notiz in der Kundenversion), `core/pdf_pib.php` (hochgeladenes PIB).

Zusatz: Lieferanten, die zugleich Kunde sind (Partner, mit Kunde verknüpft, gleicher Firmenname), werden in Texten nie ersetzt.

## Kunden-Mail erkennen (13.11.2026)
`kunde_ist_mailadresse()` prüft jede Adresse, die zu einem Kunden gehört: Firmen-Adresse, Portal-Benutzer (benutzer, kunde_portal_user), CRM-Kontakte mit Kunde. Mehrere Adressen in einem Feld und „Name <mail>“ werden zerlegt; trifft eine, läuft die ganze Mail durch den Lieferanten-Filter.

**Grundcharge (13.11.2026, Fable-3 T3.2):** `kunde_text_neutral()` kürzt am Ende auch eigene Chargen auf die Grundcharge (`kunde_charge_text()`) – gilt damit für Dokument-Titel im Portal, Mails an Kunden und Beleg-Texte. Der Download-Dateiname nimmt `kunde_charge_anzeige()`.

**Mail-Filter nur voller Name/Alias (13.11.2026, Fable-3 T3.4):** `kunde_lieferanten_namen()` liefert nur noch den vollen Firmennamen (mit Rechtsform, oder ein Name aus mindestens zwei Wörtern) und die ausdrücklich gepflegten Aliase (`lieferanten.kunden_alias`, Komma getrennt). Der Name ohne Rechtsform als einzelnes Wort („Vita“ aus „Vita GmbH“) wird nicht mehr ersetzt. Paketdienste und Spediteure (`kunde_filter_paketdienst()`: DHL, UPS, DPD, GLS, FedEx, TNT, Hermes, Deutsche Post, Schenker, Dachser, Kühne, Rhenus …) werden nie ersetzt.

**CRM-Mails gefiltert (13.11.2026, Fable-3 T3.5):** Ohne Dashboard-Helfer lauffähig: `kn_tabelle_da()`/`kn_spalte_da()` fallen auf information_schema zurück, damit das CRM denselben Filter laden kann.


**Lieferantennummern nie ersetzen (Regel Nico 13.11.2026):** `kunde_text_neutral()` (und damit der Kunden-Mail-Filter, Belege, Portal) maskiert Lieferantennummern (L-2841) vor dem Ersetzen und setzt sie danach unverändert wieder ein. Ein Lieferant, dessen Firma wie eine Nummer aussieht („L-2841“), steht nicht in der Ersetzungsliste.

**Ein-Wort-Lieferanten (Fable-4 T4.1, 13.11.2026):** Eine Regel `kunde_filter_name_grund()`: ersetzt wird der volle Firmenname ab 4 Zeichen mit Rechtsform oder aus mindestens zwei Wörtern, dazu ein Ein-Wort-Name ab 6 Zeichen, der nicht in der Liste allgemeiner Wörter steht (`kunde_filter_woerter()`, pflegbar in den Einstellungen Reiter Firma, app_meta `kunde_filter_woerter`). Paketdienste nur echte (DHL, UPS, DPD, GLS, Hermes, FedEx, TNT – Name beginnt damit, sonst nur Versand-Wörter, `kunde_filter_paketdienst()`). `kunde_filter_bericht()` liefert je Lieferant „wird ersetzt“ bzw. den Grund (Einstellungen, Reiter Firma).

**Ungültiges UTF-8 (Fable-4 T4.2, 13.11.2026):** Scheitert ein `preg_*` mit `/u` (Latin-1-Zeichen, abgeschnittener Umlaut), gibt `kunde_text_neutral()` / `kunde_position_text()` den Text unverändert weiter – nie leer.

**Artikelnamen mit Lieferantennamen (Fable-4 T4.4, 13.11.2026):** `kunde_filter_artikel_treffer()` – Liste aller Artikel, deren Name einen Lieferantennamen enthält (voller Name, Weiterer Name oder erstes Wort ab 4 Zeichen), mit Vorschlag ohne den Namen. Nur Anzeige in den Einstellungen (Reiter Firma), umbenannt wird nichts.

**Lieferant = Kunde (live gesehen 13.11.2026):** Beginnt ein Kundenname mit dem Lieferantennamen oder umgekehrt (ohne Rechtsform, ab 6 Zeichen), gilt der Lieferant als dieselbe Firma und wird nie ersetzt (Lieferant „PURE HEALTH ALLIANCE INC.“ = Kunde „Pure Health Alliance INC. Zweigniederlassung“ – vorher stand in dessen Mails „bulkify Zweigniederlassung“). Die Artikel-Liste lässt solche Lieferanten aus.
