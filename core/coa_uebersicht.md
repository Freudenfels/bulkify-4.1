# core/coa_uebersicht.php – Zusammenfassendes CoA (Fertigware)

**Zweck:** Bei der QM-Freigabe einer Fertigware-Charge entsteht automatisch ein **zusammenfassendes CoA**: eine
Übersicht aus den Analysen der tatsächlich eingesetzten Rohstoff-Chargen und den Produktdaten. Es ist **nicht am
Fertigprodukt gemessen** (Nico 03.11.2026: Rohstoffe werden stichprobenhaft geprüft; eine Fertigprodukt-Laboranalyse
ist ein separater Zusatz, den der Kunde dazubucht).

**Pflicht (fest im Code, nicht konfigurierbar):**
- Kopf-Kasten: „Zusammenfassendes CoA – Übersicht aus den Analysen der eingesetzten Rohstoffe (stichprobenhaft
  geprüft) und den Produktdaten; nicht am Fertigprodukt gemessen. Eine Fertigprodukt-Laboranalyse ist separat erhältlich.“
- Methode je berechneter Zeile: „aus Rohstoff-Analysen (Massenbilanz)“.
- Eingesetzte Rohstoffe mit Quellchargen (Rückverfolgbarkeit) und Datenbasis je Zutat.
- Claims-neutral: keine werblichen/gesundheitsbezogenen Aussagen.

**Rechnung (`coa_uebersicht_daten()`):**
- Schwermetalle Pb, Cd, Hg, As (`coa_uebersicht_kontaminanten()`): Massenbilanz auf das **Füllgut ohne Kapselhülle**
  = Σ (Rohstoffwert × Massenanteil der Zutat). Massenanteil aus `rezeptur_zutat.menge_mg`. Rohstoffwert = Messwert
  (`charge_analyse.ergebnis`) der im PA verbrauchten Charge(n) (`produktion_verbrauch`; mehrere -> der höchste),
  sonst der Rohstoff-Grenzwert (`item_grenzwert`, „Grenzwert/worst case“). „<x“-Werte und Grenzwerte zählen als
  Obergrenze -> Ergebnis „max. …“. Fehlt für eine Zutat beides: **„nicht berechenbar – keine Werte für …“** (kein
  geschönter Teilwert). Einheiten mg/kg, ppm, µg/g, µg/kg, ppb werden auf mg/kg umgerechnet.
- Referenzspalte: Höchstgehalte NEM nach VO (EU) 2023/915 (Pb 3,0 / Cd 1,0 / Hg 0,10 mg/kg; As –).
- Mikrobiologie (`coa_uebersicht_ist_mikro()`): **nie hochgerechnet**, nur als Rohstoff-Stichprobe je Rohstoff-Charge.

**Vorrang:** `coa_fertig_gemessen_vorhanden()` – Analysewerte an der Fertigcharge oder ein hochgeladenes Analyse-Dokument
(nicht `hochgeladen_von='coa_ueb'`) dieser Bestellung/Charge -> keine Übersicht; im Portal verdrängt das gemessene CoA die Übersicht.

**PDF:** `build_coa_uebersicht_pdf($charge_id)` im bulkify-CoA-Layout (Helfer aus `core/pdf_spec.php`), Echtheits-QR der
Fertigcharge, QS-Freigabeblock.

**Ablage:** `dokument` typ='analyse', objekt_typ='auftrag', `hochgeladen_von='coa_ueb'`, `charge_nr`, `kunde_sichtbar=1`
(Datei `coa_uebersicht_<charge>_….pdf` in BX_UPLOADS). Erzeugt von `fertigware_qm_freigabe_nach()` (core/schema.php),
je Charge nur einmal; Schalter `app_meta coa_uebersicht_auto` (Standard 0 = aus, bis QM das Muster gegengelesen hat).

**Umbau nach Nico (03.11.2026):**
- Name **„Informationsdokument zum Produkt“** (Titel INFORMATIONSDOKUMENT, Dokumenttitel „Informationsdokument zum Produkt · Charge …“).
- **Keine** hervorgehobene Pflichtkennzeichnung im Kopf – der Hinweis („fasst die Analysen … zusammen; nicht am Fertigprodukt
  gemessen; Laboranalyse separat erhältlich“) steht im Kleingedruckten am Ende.
- **Novel-Food-Status** je Zutat mit Datum des Abgleichs; beim Erzeugen wird der automatische Abgleich mit dem EU-Katalog frisch
  ausgeführt (`item_novelfood_aktualisieren`, nur wenn der Katalog geladen ist), Hinweis „Automatischer Abgleich … keine Rechtsberatung“.
- Kontaminanten nur für Parameter, die für alle Zutaten berechenbar sind (sonst entfällt die Zeile bzw. der Abschnitt).
- **Kein** Unterschrift-/Stempelblock; die Fußzeile (`spec_fuss`) bringt Haftungsausschluss und „maschinell erstellt, ohne Unterschrift gültig“.
- Abschnitts-Überschriften brechen nie allein am Seitenende um.

**Bewertung „konform“ (Nico 03.11.2026):** Kein „worst case“/„max.“ und kein Zahlenwert auf dem Dokument. Kontaminanten nur noch
Pb, Cd, Hg (mit NEM-Höchstgehalt; Arsen entfällt) mit Bewertung „konform“. Ein Parameter steht nur drin, wenn für **alle** Zutaten
ein Wert vorliegt (Messwert der Charge oder Rohstoff-Spezifikation) – sonst entfällt er ganz. Datenbasis-Spalte: „Analyse der Charge“
bzw. „Spezifikation“. Sicherheitsnetz: liegt die Rechnung über einem Höchstgehalt, steht „Höchstgehalt überschritten“, das Dokument
wird **nicht** für den Kunden sichtbar abgelegt und das Team bekommt einen Verlaufseintrag am Auftrag.

**Nachtrag:** Keine Höchstgehalt-Spalte (verwirrt neben „konform“). Spalten: Parameter · Gehalt (berechnet, mg/kg, ohne „max.“) · Bewertung „konform“ · Grundlage. Die NEM-Höchstgehalte (VO (EU) 2023/915) stehen nur als Bezug im Kleingedruckten.
