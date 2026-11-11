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
