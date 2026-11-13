# einkauf/detail.php – Bestellung anlegen & bearbeiten

## In Einzelbestellungen aufteilen
Aktion `aufteilen` (`bestellung_aufteilen()` in `core/schema.php`): räumt **Alt-Bündel** auf, in denen mehrere Bulk-Produkte in einer Bestellung stecken. Erzeugt **je Bulk-Produkt eine eigene Bestellung** (Nicht-Bulk-Positionen in einen Sammelbeleg), sichert den Preis je Position als Snapshot (`bestellposition_ek`), **storniert** das Original und **informiert den Lieferanten** (Portal-Chat + Mail). Button erscheint im Status-Panel nur bei **≥ 2 verschiedenen Bulk-Produkten** und solange nichts geliefert/angekommen ist. Neu angelegte Bestellungen sind durch die geänderte Einkaufs-Logik ohnehin schon je Produkt getrennt – das Tool ist für Altbestand.

**Annahme-Stand wird übernommen (2026-10-28):** War das Original schon **bestätigt** (Lieferant hat zugesagt, erkennbar an `bestaetigt=1`, Status `bestaetigt` oder gesetzter `station`), erbt **jede** neue Einzelbestellung diesen Stand: `status`, `bestaetigt/_am/_von`, zugesagter Termin `eta_geplant`, `produktion_geplant`, `station` und die Versanddaten. Dann ist **keine erneute Bestätigung** nötig und die Lieferanten-Nachricht sagt das auch so. War es nur ein **Entwurf** (`offen`), bleiben die Teile Entwurf und der Lieferant wird nicht benachrichtigt. Nur bei noch **nicht** bestätigten, aber schon gesendeten Bestellungen gehen die Teile auf `gesendet` (einzeln zu bestätigen). So sind Bündel **immer** trennbar – egal ob bestätigt oder nicht –, ohne dass ein bereits erteilter Zuschlag verloren geht.

## Online-Einkauf
Ist die Bestellung über **„Online bestellt"** entstanden (`bestellart='online'`, ohne Lieferant), steht direkt unter dem Status ein Panel **Online-Einkauf** mit Karten **Händler/Quelle · Zahlungsart · Preis (gesamt) · Rechnung** (`online_haendler/_zahlart/_preis/_waehrung`). Die **Rechnung** (B3) ist die beim Bestellen hochgeladene Datei (`dokument` objekt_typ='bestellung', typ='rechnung') und als Download verlinkt. Read-only – erfasst beim Bestellen in der Einkaufsliste, dient der Buchhaltung zum Gegenrechnen. „Wareneingang buchen" funktioniert wie bei jeder Bestellung (so werden z. B. gelieferte Etiketten eingebucht).

## Ablauf beim Lieferanten
Unter dem Status steht das Panel **Ablauf** (`core/bestellung_ui.php`) – dasselbe, das der Lieferant in seinem Portal sieht: bestätigen mit geplantem Liefertermin, danach die Stationen (angenommen · Produktion · Qualität · versandbereit · versendet) und die Versanddaten (Anbieter, Versandart, Sendungsnummer). **„versendet" geht erst mit vollständigen Versanddaten.** In der Kopfzeile liegt **⇩ PDF** – der Beleg für den Lieferanten (`core/pdf_bestellung.md`).

**Zweck:** Eine Einkaufsbestellung (BE-) beim Lieferanten mit Positionen; beim Liefern werden daraus Lager-Chargen.

**Was passiert hier:**
- **Speichern:** Kopf (Lieferant, Notiz) in `bestellung` (neu = INSERT + BE-Nummer), Positionen in `bestellung_position` (Item + Menge + EK).
- **„als bestellt markieren":** Status → bestellt.
- **„Wareneingang buchen":** `bestellung_wareneingang()` legt für jede Position eine **Charge** an (`wareneingang_buchen`, Rohstoff → Quarantäne), Status → geliefert. Danach ist die Bestellung schreibgeschützt (`fieldset disabled`).

**Positionen (Tabelle, live):** Artikel-Auswahl (Rohstoff/Verpackung/Verbrauch) + Menge + **EK/Einheit** (wird beim Wählen aus dem Artikel vorbefüllt) → Summe je Zeile und Gesamt.

**Bulk-Zukauf – eine Zeile je Produkt:** Das Panel „Bulk-Zukauf (Fremdproduktion)" fasst die Bulk-Positionen desselben Produkts („Bulk: X (AB-…)" je Auftrag + die alte separate Puffer-/Lager-Zeile „Bulk: X") zur **Gesamtmenge je Produkt** zusammen. Gilt auch für Alt-Bestellungen (reine Anzeige-Gruppierung; die einzelnen Positionen bleiben in der DB mit Auftragsbezug für den Wareneingang). Dieselbe Zusammenfassung zeigen Lieferantenportal (`module/lieferant/bestellung.php`) und PDF (`core/pdf_bestellung.php`).

**Zusammenhang:** schließt die Beschaffungsseite – Bestellung → Lieferung → Bestand im Warenlager. Die Bestellungen erscheinen auch im **Lieferanten-Cockpit** (Einkauf gesamt + Reiter Bestellungen).

**Auftragsbezug (Baustein 4):** Je Bestellposition kann ein **offener Kundenauftrag** gewählt werden („Für Auftrag", sonst „Lager / allgemein"). Gespeichert in `bestellung_position.auftrag_id`. Beim Wareneingang (`bestellung_wareneingang()`) erbt die erzeugte **Charge** diesen Auftragsbezug (`charge.auftrag_id` + `charge.bestellung_position_id`). So weiß das System, wofür welche Ware gekommen ist – Grundlage für „Wareneingänge für diesen Auftrag" (Produktion/Cockpit) und später die automatische Produktionsweg-Ableitung.
## KI-Erfassung aus Dokument (mehrere Positionen)
Bei einer **neuen** Bestellung und eingerichteter KI (nur beta) erscheint oben das Panel **„KI-Erfassung aus Dokument"**: ein Lieferanten-Dokument (Bestellbestätigung/Angebot/Rechnung als PDF, Foto oder CSV/Excel) hochladen → `aktion=ki_lesen` speichert es kurz, schickt es via `ki_datei_frage()` an die KI und erwartet JSON `{lieferant, waehrung, positionen:[{bezeichnung, menge, einheit, ek_preis}]}`. Ergebnis landet in `$_SESSION['ki_best']`, Redirect auf `?p=bestellung&id=neu&ki=1`. Beim Rendern wird der **Lieferant** (normierter Firmenname) und **jede Position** auf einen Lagerartikel abgebildet (normierter Name, längster Überlapp gewinnt); das **Positionsformular wird vorbelegt** und ein grünes **Prüf-Panel** listet „erkannt → zugeordnet / Menge / EK". Nicht zugeordnete Zeilen sind markiert und werden von Hand gewählt. Nichts wird automatisch gespeichert – erst der normale „Speichern"-Knopf legt die Bestellung + Positionen an.

## E-Mail an den Lieferanten
„Als bestellt markieren" (`aktion=bestellt`) schickt dem Lieferanten die Bestell-Mail (`mail_lieferant_bestellung()`), sofern der Versand eingerichtet ist. Ein Mailfehler stoppt nichts, er wird oben auf der Seite gezeigt.
## Rückfragen an den Lieferanten
Unter dem Ablauf-Panel steht **Rückfragen** (`core/nachricht.php`, Bezug `bestellung`): Fragen an den Lieferanten und seine Antworten zu genau dieser Bestellung, POST `aktion=nachricht`. Der Lieferant sieht dasselbe Panel im Portal; bei eingerichtetem Versand geht je Nachricht eine Mail an die andere Seite.

## Erinnern & Stornieren (B4, 2026-10-28)
Im Status-/Aktionspanel: **Erinnern** (nur Portal-Lieferant, noch nicht bestätigt, Status nicht offen/geliefert/storniert) → `aktion=erinnern` → `bestellung_erinnerung_senden()` schickt die Erinnerungsmail (`mail_lieferant_erinnerung()`) und setzt `erinnert_am`; bei Überfälligkeit (≥3 Tage) ist der Button hervorgehoben und zeigt „T+N". **Stornieren** (solange nicht geliefert/angekommen) → `aktion=storno` → `bestellung_stornieren()` setzt Status `storniert` und informiert einen Portal-Lieferanten (Chat+Mail). Beides ist vom Mitarbeiter ausgelöst (kein automatischer Versand).

## Seitenaufbau neu sortiert (B6, 2026-10-28)
Die Bestelldetailseite ist von oben nach unten neu geordnet:
1. **Status groß** als Kopf (`bestellung_stufe()`-Label, eingefärbt) mit **Lieferant**, Bestelldatum, zugesagtem Termin, Ankunft.
2. **Positionen (bestellt / geliefert)** – read-only Überblick je Position bzw. je Bulk-Produkt: bestellte Menge vs. bereits **gelieferte** Menge (Summe der eingebuchten Chargen über `charge.bestellung_position_id`), mit **Auftragsbezug** (Links zum Auftrag). Ersetzt die alte separate „Bulk-Zukauf"-Tabelle.
3. **Online-Einkauf** (falls `bestellart='online'`) / **Termine & Tracking** = Ablauf-Panel (`bestellung_ablauf_panel`) / **Rückfragen** / **Wofür wird bestellt** (Auftrag/Produkt/Kunde/Prio).
4. Darunter das **Bearbeiten-Formular** (Lieferant, Notiz, Positionen editierbar) und die **Dokumente vom Lieferanten**.
5. **Aktionen GANZ UNTEN**: der Aktions-/Status-Block (als bestellt markieren, Wareneingang buchen, Aufteilen, Erinnern, Stornieren, zurück in den Bedarf) wird oben per `ob_start()` gepuffert und erst am Seitenende unter „Aktionen" ausgegeben – plus der **Verlauf** (`verlauf_fuer('bestellung', id)`).

## Teillieferung (Nacht 1c)
`bestellung_position.geliefert_menge` (neu, additiv) zählt, was je Position schon eingebucht ist. Der Wareneingang mit Bestellbezug ruft `bestellung_lieferung_erfassen($bestellung_id, $charge_ids)` (Lager-App: `erp_bestellung_lieferung_erfassen`): die Chargen werden auf die Positionen verteilt (gleicher Artikel, sonst Freitext-Position; g/kg und ml/l werden umgerechnet). **Geschlossen** (angekommen_am, Status geliefert) wird die Bestellung erst, wenn alle Positionen zu mind. 98 % geliefert sind. Vorher bleibt sie offen, und „unterwegs" zählt nur den Rest (`bp_rest_sql()`, auch in `bulk_position_basis_sql()`), es entsteht also kein doppelter Bedarf. Der Knopf „liefern" (`bestellung_wareneingang`) setzt alle Positionen voll.

Knopf „liefern“ (04.11.2026): Enthält die Bestellung Bulkware ohne Lagerartikel (Freitext), wird nichts gebucht und die Bestellung bleibt offen. Hinweis: in der Lager-App unter Wareneingang einbuchen (Charge, MHD, Auftrag).
- Kunde in Tabelle zeigt internes Kürzel (kunde_link/kunde_anzeige), 08.11.2026

## Telefonisch bestätigt (08.11.2026)
Bei Status `gesendet` und noch nicht bestätigt: Knopf „Telefonisch bestätigt“ → `bestellung_telefonisch_bestaetigen()`: Status bestellt, bestätigt, Notiz-Zusatz, ohne Mail.

## Chargen & Dokumente, Rechnung durch das Team (08.11.2026)
- Nach dem Positionen-Überblick steht der Block **„Chargen & Dokumente“** (`bestellung_chargen_panel`, `core/bestellung_chargen.php`): Charge, MHD, Menge und CoA je Position, Spezifikation, KI-Vorschlag aus dem CoA. POST `bch_*` → `bestellung_chargen_post`, Rücksprung auf `#chargen`.
- Block **Zahlung**: solange keine Rechnung vorliegt, Formular „Rechnung hochladen“ (PDF, Rechnungsnummer, Datum, Betrag, Währung = Lieferantenwährung) → `bestellung_team_rechnung_hochladen()` (`core/lieferant_dateien.php`). Gleicher Platz wie der Portal-Upload; es gibt nur eine Rechnung je Bestellung. Anzeige mit Nr./Datum/Betrag und wer sie hochgeladen hat. Leertext: „noch keine hochgeladen - Lieferant im Portal oder hier hochladen“.
- Die Liste unten heißt „Weitere Dokumente zur Bestellung“ und zeigt nur Dokumente ohne Position (`bestell_dokumente($id, true)`).

## CoA-Prüf-Popup (08.11.2026)
Nach einem CoA-Upload im Block „Chargen & Dokumente“ springt die Seite auf `&coa=<dok>` und öffnet das Prüf-Popup. `GET coa_stand=<dok>` liefert den Stand des Auslesens als JSON (`{"stand":"laeuft|bereit|fehler|geprueft|ohne_ki"}`). Logik in `core/bestellung_chargen.php`.

## Liefertermin bei „direkt bestellt“ (08.11.2026)
Kopf und Ablauf sagen bei `bestellart='direkt'` „erwartet am“ statt „zugesagter Termin“ (kein Lieferant hat zugesagt). Das Team kann den Termin im Ablauf-Block setzen/ändern (Aktion `eta_setzen`, muss nach dem Bestelltag liegen, leer = kein Termin, protokolliert). `bestellung_direkt_abschliessen()` übernimmt „Erwartet am“ nur, wenn es nach dem Bestelltag liegt (Anlass BE-2924: bestellt und „erwartet“ am selben Tag).

## Wareneingang und Rest storniert (10.11.2026, Einkauf gerade ziehen Teil 1/3)
- „Wareneingang buchen“ öffnet den Dashboard-Wareneingang mit der ersten offenen Position (`?p=wareneingang&bp=…`). Ein pauschales Einbuchen „zu BE-…“ gibt es nicht mehr.
- Positionen: bestellt, geliefert (Summe der verknüpften Chargen), offen (Link zum Wareneingang dieser Position), Anzahl Chargen, Hinweis Mehrlieferung, „storniert“ beim Rest.
- Knopf **„Rest storniert“** bei Teillieferung: schließt die Bestellung, der Rest zählt nicht mehr als unterwegs.

## Wareneingang zuordnen (10.11.2026, Teil 4 Altlasten)
Block „Wareneingang zuordnen“ (`#abgleich`, aus `core/bestellung_abgleich.php`), wenn Positionen ohne eingebuchte Charge offen sind. Je Position gibt es Vorschläge vorhandener Chargen mit „verknüpfen“. Darunter stehen „als geliefert schließen“ (ohne Charge, der Rest zählt nicht mehr als unterwegs) und „stornieren“. Aktionen: `abgl_verknuepfen`, `abgl_geliefert`.

## Artikelsuche und deutsche Zahlen (10.11.2026, Teil 8)
- Artikel je Position: Suchfeld (`rscombo`) statt Auswahlliste mit allen Artikeln. Gesucht wird über Name und Synonyme (`item.synonym`, `item.synonyme`), die Kapselgröße bzw. das Füllgewicht steht im Text. Bulk-Artikel (`fertig`) sind auswählbar, vorher fehlten sie und gingen beim Speichern verloren.
- Menge und EK sind Textfelder mit deutscher Eingabe (`zahl_lesen`): „7.000“ = 7000, „0,12“ = 0,12. Die Zeilensumme rechnet genauso.

**Interne Notiz / Hinweis an den Lieferanten (11.11.2026):** Zwei Felder oben an der Bestellung. „Interne Notiz“ sieht der Lieferant nie. „Hinweis an den Lieferanten“ (`hinweis_lieferant`) steht im Lieferantenportal; leer = kein Hinweis.

**Block B (11.11.2026)**
- **Block „Verknüpfte Chargen“** unter den Positionen: jede Charge einzeln mit Link, „Menge korrigieren“ (Chargenseite) und „Verknüpfung lösen“ (nur Admin, mit Rückfrage).
- **„Wareneingang zuordnen“:** zeigt Mengen-Abweichungen und hat zusätzlich „Menge korrigieren und verknüpfen“ (Aktionen `abgl_korr_verknuepfen`, `abgl_loesen`).

**Gesperrt/inaktiv (Block C, 11.11.2026):** Gesperrte Lieferanten/Kunden bzw. inaktive Produkte/Rezepturen stehen nicht mehr in den Auswahlfeldern. Ein schon gewählter Eintrag bleibt am Beleg sichtbar.

## Paket 12.11.2026 Teil 4 (Unterlagen-Regel)
„als bestellt markieren“ erst mit vollständigen Unterlagen (sonst grau mit Grund + Link). Admin: „trotzdem bestellen“ mit Pflicht-Grund (Verlauf). Kasten „Unterlagen des Lieferanten fehlen“ mit Upload im Namen des Lieferanten (aktion lu_upload).

## Paket 12.11.2026 Teil 5 (Gebindeaufkleber)
Bereich „Labels / Aufkleber“ wie im Portal (Direkt-Bestellungen, Nachdruck); Versanddaten mit Anzahl Pakete.

## Stornieren mit Grund, ohne Nachricht, Position stornieren (12.11.2026)
- **Stornieren** klappt ein kleines Formular auf: Grund ist Pflicht. Ein Portal-Lieferant wird immer informiert (Nachricht + Mail).
  Den Schalter „Lieferant informieren“ gibt es seit 13.11.2026 nicht mehr in der Oberfläche; „ohne Nachricht“ geht nur intern
  (Parameter `$lieferant_informieren` für Datenpflege-Läufe).
- **Position stornieren** (nur Admin) in der Tabelle „Positionen“, je Zeile mit genau einer Position, solange dazu keine Ware
  eingebucht ist: Grund Pflicht; läuft die Bestellung beim Portal-Lieferanten noch, wird er informiert.
  Die Position bleibt stehen (`rest_storniert_am`, `pos_storno_grund`), ihre Menge zählt nicht mehr als unterwegs.
  Geht auch an einer schon eingegangenen Bestellung. Danach rechnet die Bestellung neu: alle Positionen storniert und nichts
  geliefert = Bestellung storniert, sonst schließt sie sich ggf.
- Aktionen: `storno` (Feld `grund`), `pos_storno` (Felder `bp_id`, `grund`).

## Doppelbestell-Warnung (13.11.2026)
Auch bei „Neue Bestellung“ von Hand: offene Bestellungen der Positions-Artikel zeigen das Pop-up (`bestellung_doppelt_treffer()`), weiter nur mit Bestätigung, die im Verlauf steht.

**Bestätigen mit Unterlagen-Sperre, Portal ohne Entwürfe (13.11.2026, Fable-3 T2.2):** Team-Bestätigung ruft `bestellung_bestaetigen(…, 'team', lu_grund)`; das Formular zeigt bei fehlenden Unterlagen dem Admin ein Pflicht-Feld „Grund“, sonst ist der Knopf gesperrt.

**Ausnahme nach Telefonbestellung (13.11.2026, Fable-3 T2.3):** „trotzdem bestellen“ (Admin, Unterlagen fehlen) hat eine Auswahl: „an Lieferanten senden“ (wie bisher, `bestellung_als_bestellt_markieren()`, Mail „Neue Bestellung“) oder „direkt bestellt (ohne Nachricht)“ (`bestellung_direkt_abschliessen()` mit dem Grund: bestellt + bestätigt, keine Mail). So bekommt der Lieferant nach dem Telefonat keine zweite Bestellung per Mail.

**Bearbeiten speichert ohne Neuschreiben (13.11.2026, Fable-3 T2.6):** „Speichern“ aktualisiert die Positionen über ihre ID (`p_id[]`, `bestellung_positionen_speichern()`) statt alle zu löschen und neu anzulegen. Positions-Storno, Liefermenge und Chargen-Verknüpfung bleiben erhalten. Stornierte oder gelieferte Positionen (`bestellung_position_gesperrt()`) stehen nur zur Anzeige im Formular und werden nie geändert oder gelöscht. Eine entfernte Position, an der Lieferanten-Chargen oder Dokumente hängen, bleibt (Hinweis).

**Stornierte Bestellung (13.11.2026, Fable-3 T2.8):** Die Spalte „offen“ zeigt 0 (nichts mehr unterwegs).

**Doppel-Warnung (13.11.2026, Fable-3 T2.10):** Nach der Warnung steht das Formular „Neue Bestellung“ wieder mit den Eingaben da (`bestellung_doppelt_vorbelegung()`); „Abbrechen“ leert nichts.

**Preis-Anzeige (13.11.2026, Fable-3 T4.7):** Preise laufen durch die zentrale `preis_zahl()` (core/schema.php): bis 6 Nachkommastellen, Nullen am Ende weg, mindestens 2 – Leerkapseln 0,00295 stehen nicht mehr als 0,0030. Nur Anzeige, gerechnet wird mit dem gespeicherten Wert.

**Neue Bestellung (Paket 13.11.2026, Teil 6.3):**
- **Erst die Art:** Rohstoff, Verpackung, Etikett, Bulkware oder Fertigprodukt. Die Artikelsuche zeigt danach nur Artikel dieser Art.
  - Bei „Etikett“ wird zuerst der Kunde gewählt; die Suche zeigt dann nur dessen Kundenetiketten.
  - Fertigprodukte (`verkaufsfertig`) sind jetzt bestellbar.
- **Eine Position:** Eine neue Bestellung hat genau eine Position. Es gibt kein „+ Position“ mehr, und der Server lehnt mehrere ab.
- **Ohne Art-Auswahl:** Die KI-Vorbelegung und die Rückkehr von der Doppel-Warnung überspringen diesen Schritt.

**Speichern und Zurückziehen abgesichert (Fable-4 T3.3–T3.6, 13.11.2026):**
- Positionen mit aktiver Reservierung (`bestellung_position_reserviert()`) werden nicht gelöscht und behalten ihren Artikel.
- Vorhandene Zeile mit leerem Artikel (Artikel nicht in der Auswahl) wird nie gelöscht; `pos_options()` nimmt den vorhandenen Artikel immer in die Auswahl.
- Server prüft beim Speichern den Status (`bestellung_bearbeitbar_grund()`): nur Entwurf, gesendet, bestellt.
- „Bestellung zurückziehen“ nur ohne Liefermenge, Charge, Lieferanten-Charge und Reservierung (`bestellung_zurueckziehen_grund()`), sonst grauer Knopf mit Grund „bitte stornieren“.

**Spalte offen bei stornierter Position (Fable-4 T3.7, 13.11.2026):** zeigt 0, der stornierte Rest steht klein daneben („(30 kg storniert)“).

**Altlast nur mit Grund (Sofortfix 13.11.2026):** `abgl_geliefert` nur Admin und nur mit Grund (Pop-up „Ist die Ware im Lager?“), optional Rücksprung `zurueck`.

## Sofortfix 13.11.2026: Unterlagen-Sperre wird Quarantäne-Sperre
Regel Nico: Unterlagen fehlen = Ware bleibt in Quarantäne. Bestellen und Einbuchen gehen immer. Artikel ohne Unterlagenpflicht: „kein Dokument nötig“ (Details `core/lieferant_unterlagen.md`).

Bestellseite: „als bestellt markieren“ immer möglich (kein grauer Knopf, kein „trotzdem bestellen“ mehr). Kasten „Unterlagen des Lieferanten fehlen“ mit gelbem Hinweis, Upload und Knopf „kein Dokument nötig“ (Admin/QM); auch an gelieferten Bestellungen (Charge wartet in Quarantäne). Alte Sperr-Entwürfe (`unterlagen_wartet` = 1): Knopf „Schon bestellt am …“ (Datum, Standard heute) = Abschluss wie „heute bestellt“ ohne Mail, Kennzeichen fällt weg (aktion `schon_bestellt`).

## Nachbesserung Fable-5 (13.11.2026, Design)
Positionen als `bx-karten-handy` (am Handy Karten), Name mit Lauftext, Badge „storniert“ an der Position, Spalte „offen“ bei Storno 0 (Menge und Grund im Tooltip). Datei-Felder bekommen den Knopf im bulkify-Stil (bx-datei.js).

## Bestellseite aufgeräumt (13.11.2026)
Anlass BE-2956 („die Übersicht ist komplette Grütze“). Eine vorhandene Bestellung zeigt jetzt (Bausteine aus `core/bestellung_seite.php`):
1. **Kopf**: Nummer, großer Status, Lieferant (Link), bestellt am, erwartet am, Badges; rechts Betrag gesamt, EIN Hauptknopf für den nächsten Schritt und das Menü „⋯“ mit allen übrigen Aktionen (Bearbeiten, PDF, Rechnung erfassen/Als bezahlt markieren, wenn nicht Hauptknopf, Zurück in den Einkaufsbedarf, Leere Bestellung löschen, Zurückziehen, Schon bestellt am …, Telefonisch bestätigt, Lieferanten erinnern, In Einzelbestellungen aufteilen, Rest stornieren, Stornieren mit Grund im Pop-up, Altlasten nur Admin).
2. **Unterlagen-Zeile** (gelb, schmal) direkt unter dem Kopf, „Hochladen“ öffnet `lu_panel` im Pop-up.
3. **Position**: eine Karte; Stift öffnet das bisherige Formular (Lieferant, Notizen, Positionen) im Pop-up `dlgBearbeiten` – das Formular unten gibt es nur noch bei neuen Bestellungen. „Wofür wird bestellt“ und Etiketten-Druckdateien eingeklappt in der Karte. „Position stornieren“ entfällt.
4. **Reiter**: Wareneingang & Chargen (Schon im Lager? eingeklappt, Chargen & Dokumente, Aufkleber nur mit Charge, eingebuchte Chargen, weitere Dokumente) · Versand (Termin; Stationen-Leiste nur bei Portal-Lieferanten; Sendung/Tracking, „Versand / Sendung erfassen“ im Pop-up) · Zahlung (eine Statuszeile, Formulare nur im Pop-up `dlgRechnung`/`dlgBezahlt`) · Kosten (Warenwert, Rechnung, Nebenkosten wie Fracht/Zoll, Gesamtkosten und Einstandspreis je Position; `core/bestellung_kosten.php`, 13.11.2026) · Nachrichten (Zähler ungelesen) · Verlauf (interne Notiz + Verlauf). Standard-Reiter: Zahlung, wenn Rechnung erfassen/bezahlen der nächste Schritt ist, sonst Wareneingang & Chargen. Anker `#unterlagen`, `#wareneingang`, `#versand`, `#zahlung`, `#rechnung`, `#bezahlt`, `#kosten`, `#nachrichten`, `#verlauf` (bzw. `&tab=`) öffnen den passenden Reiter.
Doppeltes entfernt: Einbuchen nur Hauptknopf und je vorgemerkter Charge „einbuchen“; Stornieren nur im Menü; Positionen-Tabelle nur einmal; PDF-Knopf im Seitenkopf und der Block „Aktionen“ unten entfallen. Alle POST-Aktionen oben in der Datei unverändert.

**Fable-6 P3 (13.11.2026):** Die Seite endet wieder mit `render_footer()`. Ohne den Seitenfuß fehlten Handy-Menü, Hell/Dunkel-Schalter und Doppelklick-Schutz.

**Fable-6 P11 (13.11.2026) – Statusprüfung am Server je Aktion:**
- An einer **stornierten** Bestellung lehnt der Server diese Aktionen ab und schreibt einen Verlaufseintrag:
  - Rechnung, bezahlt, Versand, Station, Bestätigen, Termin, Erinnern, Bestellt, Rest stornieren, Altlasten schließen, Aufteilen
  - alle `bch_*` (z. B. „Charge vormerken“) und `la_*` (Gebindeaufkleber)
- **Stornieren** geht nicht mehr, sobald Ware eingegangen ist (auch in `bestellung_stornieren()`). Dann gibt es nur „Rest stornieren“.
- **Rest stornieren** geht nur bei echter Teillieferung (schon geliefert, Rest offen), nur mit Grund (Pop-up `dlgRestStorno`) und nur für Einkauf und Admin.
- **bezahlt** geht nur einmal und nie mit einem Datum in der Zukunft (auch in `bestellung_bezahlt_markieren()`).
