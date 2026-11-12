# schema.php – Datenbank-Aufbau, Migrationen & Protokoll

**Zweck:** Legt alle Tabellen an und pflegt sie. Enthält außerdem die zentrale Aktivitäts-Protokollierung.

**Was passiert hier:**
- `init_schema()` – erstellt alle Tabellen, falls sie fehlen (bei jedem Seitenaufruf harmlos aufgerufen). Aktuell:
  - `app_meta` – zentrale Schlüssel/Wert-Einstellungen (eine Quelle für Einstellungen).
  - `users` – interne Mitarbeiter + Portal-Logins.
  - `kunden` – der Kunden-Stamm (schlank, geordnet; Adressen strukturiert wegen DHL).
  - `kunde_marke` – mehrere Marken + Webseiten je Kunde (White-Label).
  - `lieferanten` – eigener Lieferanten-Stamm (getrennt von Kunden; Sprache, Kategorien, Währung, Lieferzeit).
  - `partner` – Hybrid-Stamm (Kunden- + Lieferanten-Konditionen in einem Datensatz).
  - `partner_subkunde` – die Kunden **des** Partners (Name + Kürzel), für die Unterscheidung in der Produktion.
  - `item` – Warenlager-Artikel (Rohstoffe **und Verpackungen**); `kategorie` unterscheidet Rohstoff/Verpackung/…. Verpackungen nutzen die Zusatzfelder verpackungsart/material/volumen_ml/farbe.
  - `rezeptur_anfrage` + `rezeptur_anfrage_wunsch` – Kundenwunsch (Laiensprache) + unsere Zuordnung zu Rohstoffen; wird zu einer Rezeptur. Helfer `anfrage_auto_item()` (Wunschname→Rohstoff).
  - **Etikett automatisch je Behälter:** Ist am Produkt kein Etikett hinterlegt, wird das passende **Etikett** automatisch aus dem Behälter abgeleitet (`etikett_id_fuer_behaelter()` → `passende_etiketten_fuer()`, Endformat/Maße am Behälter `item.etikett_final`) – **zentral in `produkt_verpackung_items()`** (greift damit im Auto-/Matrix-Weg `angebot_gruppe_positionen()` **und** in der Verpackungs-Preisberechnung `verpackung_cent_je_pack()`), zusätzlich in `angebot_rezeptur_zeilen()` (Rezeptur-Weg, über die übergebenen Behälter). So erscheint das Etikett als eigene Position + Preis auf Angebot **und** Rechnung (wie v3), ohne es am Produkt zu pflegen. Die Behälter→Etikett-Formate kommen aus `seed_etikett_formate()` (einmalig über Verpackungen/Einstellungen). **Bestehende Angebote** mit eingefrorenen Positionen zeigen es erst nach „Positionen zurücksetzen"/Neubau.
- `rezeptur_aehnliche($zutaten, $form, $exclRezeptId, $nurKundeId, $limit)` – findet **ähnliche bestehende Rezepturen** (gleiche Darreichungsform) über die Mengen-Anteils-Überlappung (Summe der `min(AnteilA,AnteilB)` → 0..1). Liefert je Treffer Prozent, gemeinsame Zutaten und Klartext-Abweichungen. `$nurKundeId=null` = **Stufe A (intern, sieht alle)**; ist `$nurKundeId` gesetzt = **Stufe B (Kundensicht)**: nur Hausrezepturen (`kunde_id NULL`) + eigene des Kunden – **niemals fremde Kunden**.
  - `rezeptur` – Kopf einer Formulierung (Name, Kunde, Darreichungsform, Status); Mengen gelten pro Einheit.
  - `rezeptur_zutat` – die Zutaten (Rohstoffe) einer Rezeptur mit Menge in mg; verweist auf `item`.
  - **Kundenspezifischer Produktname:** Tabelle `produkt_kunde_name` (produkt_id, kunde_id, name) – dasselbe Produkt heißt bei jedem Kunden anders. Resolver `produkt_kundenname($pid,$kid)` = Mapping → `produkt.kundenname` (Alt-Feld, Fallback) → `produkt.name` (intern). `produkt_kundenname_setzen()`/`produkt_kundennamen()`. Migration (`migr_produkt_kundenname`) übernimmt das Alt-Feld als Startwert je bisherigem Kunden. Kunden-Queries: `LEFT JOIN produkt_kunde_name pkn ... COALESCE(NULLIF(pkn.name,''), ...)`. Siehe `bulkify-kundenspezifischer-produktname`.
  - `produkt` – das verkaufsfertige Endprodukt (P-): **Rezeptur × Einheiten je Packung + Verpackung**. **Kundenneutral** – `kunde_id` gilt nur als Besitzer, wenn `exklusiv=1` (eigene Rezeptur/White-Label); Katalogprodukte sind für alle Kunden sichtbar. Kundenspezifisch ist der **Preis**, nicht das Produkt. Nicht zu verwechseln mit `item` (dem Lager-**Artikel**: Rohstoff, Verpackung, Fertigware); die Brücke ist ein `item` der Kategorie `verkaufsfertig` mit `item.produkt_id`, auf das die Fertigwaren-Charge gebucht wird.
  - `angebot` + `angebot_staffel` – Angebot (einzige Preisquelle) mit Mengenstaffeln (Menge + VK/Stück, bestätigte Staffel).
  - `auftrag` – Auftragsbestätigung (AB-), entsteht automatisch aus der bestätigten Staffel.
  - `beleg` – Rechnung/Gutschrift/Lieferschein (typ); RE- entsteht automatisch mit dem Auftrag.
  - `produktionsauftrag` (PR-) + `produktion_schritt` – Produktionsauftrag mit Stationen/Gates, entsteht automatisch mit dem Auftrag. Zusätzlich von Hand anlegbar (Produktions-Liste): **Lagerproduktion** ohne Kunde (`produktionsauftrag_lager_erstellen`) und **Bulk** „nur Kapseln" auf Basis einer Rezeptur (`produktionsauftrag_bulk_erstellen`; `produkt_id` NULL, `rezeptur_id` gesetzt, `pa_ist_bulk()`). Bulk: Stationen ohne Verpacken/Etikettieren (`produktionsschritte_fuer($form,false,true)`), Material nur Rohstoffe + Leerkapseln, Fertigware auf ein `fertig`-Bulk-Item der Rezeptur (`rezeptur_bulkitem`, Spalte `item.rezeptur_id`).
  - `lieferant_preis` – Staffelpreise je Rohstoff und Lieferant (menge_ab + Preis); günstigster wird im Rohstoff-Einkauf markiert.
  - `bestellung` + `bestellung_position` – Einkaufsbestellung beim Lieferanten (BE-) mit Positionen (Item+Menge+EK). Helfer `bestellung_wareneingang()` (Positionen → Chargen, Status geliefert).
  - `charge` – Bestand je Item als Chargen (Menge, MHD, Lieferant, Status quarantaene/frei/gesperrt). Helfer `item_bestand()`, `wareneingang_buchen()`, `item_braucht_quarantaene()`.
  - `kapselgroesse` – Kapselgrößen mit nomineller Füllmenge (mg), in Einstellungen pflegbar (`seed_kapselgroesse_if_empty`). Basis für „passt das rein?"/Split. `produktion_groesse_label($produkt_id)` liefert daraus die Anzeige-Größe für die Produktion (gepflegte oder aus dem Füllgewicht berechnete Kapselgröße; bei Tablette das Füllgewicht in mg).
  - `pack_kapazitaet` – Kapsel-Fassung je Primärverpackung: wie viele Kapseln je Kapselgröße in eine Dose/ein Glas passen (item_id + kapselgroesse_id + stück). Helfer `pack_kapazitaet_fuer($item_id)`. `seed_behaelter_kapazitaet()` legt einmalig die Standard-Behälter (PET-Packer 100–250 ml, Flip Packer, PLA Becher, Weithalsglas 100–250 ml) samt Herstellerwerten für #00/#0/#1/#2 an (Marker `app_meta.seed_behaelter_kap`; überschreibt keine Handeingaben).
  - **Verpackungs-Stückliste** (Migrationen): `item.verpackung_rolle` (primaer/verschluss/etikett/karton/beipack), `item.max_fuellgewicht_g` (Pulver), `item.etikett_format`; am Produkt die Slots `verschluss_id`/`etikett_id`/`karton_id`/`beipack_id` (Primär bleibt `verpackung_id`). Rollen-Labels via `verpackung_rollen()`. Zuordnung im Produkt: Kapsel→Kapsel-Fassung, Pulver→Füllgewicht, Flüssig→Volumen.
  - `item.cas` (Migration) – CAS-Nummer am Rohstoff.
  - **Leerkapsel** (Migrationen): Rohstoff-Untertyp `item.form='kapselhuelle'` mit `item.kapselgroesse_id` (FK kapselgroesse) + `item.leergewicht_mg`; Material/Farbe über bestehende `item.material`/`item.farbe`. Operativ ein Rohstoff (Wareneingang/Bestand/FEFO), in der Liste eigene Sicht `kat=leerkapsel`. Fertig befüllte Kapseln vom Lieferanten dagegen = `kategorie=fertig` (Bulkware, kein Verkapseln).
  - **Leerkapsel-Zuordnung/Verbrauch:** `produkt.leerkapsel_id` (optionale manuelle Wahl). Helfer `rezeptur_kapselgroesse()` (Größe aus Füllgewicht), `produkt_leerkapsel_kandidaten()` (Kapseln passender Größe), `produkt_leerkapsel_id()` (manuelle Wahl > eindeutiger Größentreffer, sonst null), `produktion_kapseln_entnehmen()` (Station Verkapselung, FEFO, Gate).
  - `produktion_verbrauch` – welche Charge in welcher Menge für einen Produktionsauftrag entnommen wurde (Rückverfolgung). Helfer `produktion_materialbedarf()` (benötigt vs. verfügbar) und `produktion_rohstoffe_entnehmen()` (FEFO-Entnahme, blockiert bei Mangel).
  - Helfer `rezeptur_kosten_pro_einheit()` / `produkt_ek_pack()` – EK-Kalkulation. `produktionsschritte_fuer($form)` – Stationen je Form. `auftrag_aus_angebot()` – Auto-Kette Angebot→Auftrag+Rechnung+Produktionsauftrag (idempotent, USt 19%/0%). `angebot_ust_satz($kunde_id)` – Regelsatz je Kunde (Inland `ust_inland`=19, Export/Kleinunternehmer 0). `mwst_saetze()` = [0,7,19] (einzig zulässige Sätze), `mwst_normalisieren($v)` zieht Fremdwerte darauf (<3,5→0, <8,5→7, sonst 19); Altbestand einmalig via `fix_mwst_saetze` in `init_schema()`. `produktion_stueck_je_packung($pa)` – Stück/Kapseln je Packung: Produkt `einheiten_pro_packung`, sonst Rückfall auf `auftrag.stueck` (v3-Importe tragen die Zahl nur am Auftrag) → fixt Einkaufsbedarf/Mengen, wenn kein Produkt verknüpft ist. `produkt_bulk_info($produkt_id,$fbName,$fbForm)` – Bulk-Bezeichnung/Einheit für den Zukauf, mit Fallback-Name/-Form aus dem Auftrag (`auftrag.produkt_bezeichnung`/`produkt_form`, gesetzt für 4 v3-Aufträge ohne Produkt via `fix_auftrag_produkt_v3`). `auftrag_komplett_loeschen($id)` – Auftrag samt Produktionsauftrag/-schritte/-verbrauch, dessen Chargen, Rechnung/Lieferschein und Verlauf löschen; Lieferantenbestellungen werden nur gelöst (auftrag_id=NULL). Einmal-Migration `del_auftrag_ohne_produkt_v3` löscht damit die 4 offenen Annapurna-Aufträge ohne Produkt (AB-3213/3214/3215/3217, nur solange ohne Produkt). `produktion_fertigware_einbuchen()` – den noch offenen Rest der Fertigware als Charge einbuchen; `produktion_teilmenge_einbuchen($pa_id,$menge,$mhd,$notiz)` – Teilmenge als eigene Charge (.A/.B/.C), `produktion_gebucht()` / `produktion_rest()` – schon gebucht / noch offen. `auftrag_versenden()` – Fertigware (FEFO) ausbuchen + Lieferschein (LS-) + Status „versendet". Bei einem **Fulfillment-Kunden** übernimmt stattdessen `auftrag_ins_fremdlager()`: kein Ausbuchen, kein Lieferschein – die Ware bleibt als Bestand in Lager 2, bis der Endkunde bestellt (`lager2_verbrauch`). Wer der Kunde ist, sagt der **Auftrag**, nicht das Produkt: Produkte aus dem Weg Rezeptur → Angebot → Auftrag sind kundenneutral. Dieselbe Regel gilt in `lager2_produkte()` und bei der BSKU-Vergabe in `produktion_teilmenge_einbuchen()`.
  - `naehrstoff` – zentrale Nährstoff-/NRV-Referenz (vorbefüllt mit der EU-NRV-Liste, erweiterbar). Zusätzlich `ie_mg` (mg je 1 I.E. – Umrechnung I.E.↔Masse für Vitamin D/A/E) und `einheit_anzeige` (abweichende Etiketteinheit wie „µg RE", „mg α-TE", „mg NE"; rein kosmetisch).
  - `item_wirkstoff` – Wirkstoffe je Rohstoff (mehrere möglich); Gehalt zukunftssicher als `gehalt_wert` + `gehalt_einheit` (`prozent`|`ie_g`|`ie_kg`|`mg_g`|`ug_g`). Altspalte `gehalt_prozent` bleibt (Rückwärtskompat.) und wird beim Speichern mit `%`-Werten mitgeführt. Verweist auf `naehrstoff`.
  - **Spec-Freigabe als Version:** `item.spec_freigabe_daten` (JSON-Snapshot der freigegebenen wirkstoffe/kennwerte/grenzwerte – **das** sieht der Kunde, nie die Live-Tabellen) + `item.spec_aenderung_offen` (1 = Live weicht vom Snapshot ab → erneute Freigabe nötig, alte Version bleibt live). Erst-Upload gibt automatisch frei. Migration in `init_schema` zieht **alle** Rohstoffe mit Spec-Daten ohne Snapshot als freigegebene Version nach (`spec_freigegeben=1`) – stellt den vor dem Umbau (live) sichtbaren Stand wieder her, statt dass der Alt-Bestand beim Kunden verschwindet. Bewusste Rücknahmen (Snapshot vorhanden, `spec_freigegeben=0`) bleiben verborgen (NULL-Snapshot-Filter überspringt sie). Best-effort, inline, idempotent. Logik: `core/spec_ki.md` (`spec_nach_datenaenderung`/`spec_snapshot_setzen`/`spec_diff`/`spec_anzeige_payload`).
  - Helfer `naehrstoff_id_by_name()` findet einen Nährstoff per Name oder legt ihn neu an (für „neuen Wirkstoff eintippen").
  - `wirkstoff_mg_je_mg($wert,$einheit,$ie_mg)` – **zentrale Umrechnung** auf „mg Nährstoff je mg Rohstoff"; einzige Rechenwahrheit für Deklaration/Kalkulation (Rezeptur, Produkt, PIB, Portal). `seed_naehrstoff_ie_faktoren()` stampft die Standard-I.E.-Faktoren + Anzeige-Einheiten (idempotent, überschreibt keine Team-Eingaben).
  - `nummernkreis` – zentrale Zähler je Präfix (K, L, PA, R, RZ, P, AN, VP, FP, RE, AB …).
  - `benutzer` – interne Mitarbeiter: `name`, `email` (unique, Login), `pass_hash`, `rollen` (CSV-Set, z. B. „finance,einkauf"), `aktiv`, `letzter_login`. `seed_benutzer_if_empty()` legt den ersten Admin an (admin@bulkify.local / admin). Rechte-Logik in `core/auth.php` (Rollen, Route-Rechte). Kundenportale bleiben getrennt (Magic-Link).
  - **Preis-Engine** (Phase A): `produkt.exklusiv` (Katalog vs. exklusiv; `kunde_id` = Besitzer nur wenn exklusiv). `pack_ek_staffel` (Behälter-EK je Bestellmenge). `produkt_preis` (generierte Matrix Stück × Verpackung × Bestellmenge → EK/VK). Helfer: `marge_min_prozent()`, `marge_typ_prozent($form)`, `std_stueckzahlen()`, `std_bestellmengen()` (alle aus app_meta), `pack_ek_bei_menge()`, `produkt_variante_ek/vk()`, `vk_fuer_kunde()` (Kundenrabatt `kunden.rabatt_marge`), `produkt_matrix_generieren($produkt_id)` (Kapselgröße → passende Behälter aus `pack_kapazitaet` → EK+VK je Bestellmenge).
  - **Mengenrabatt (Rohstoff-Staffeln):** `rezeptur_kosten_pro_einheit($rid, $einheiten)` rechnet je Zutat mit der Lieferanten-Staffel (`lieferant_preis`), die zur GESAMTMENGE passt (`rohstoff_ek_bei_menge`), sonst mit dem flachen `item.ek_preis`. `produkt_variante_ek()` gibt die Bestellmenge weiter (Preismatrix), `angebot_rezeptur_zeilen()` die Anzahl Packungen (Rezeptur-Angebot). Darum ist dieselbe Konfiguration bei mehr Packungen je Packung günstiger – vorausgesetzt, am Rohstoff sind Staffeln gepflegt.
  - **Herstell-EK je Packung (eine Quelle):** `rezeptur_herstell_ek_pro_packung($rid, $stueck, $menge)` liefert die Herstellungskosten einer Packung – je Form Gramm/ml/Stück, bei Kapsel/Tablette/Gummi plus Hülle/Presshilfe/Basis. Hat die Rezeptur **keine** Rohstoffpreise, zählt der **Fertigprodukt-Preis des Lieferanten** je Stück (`rezeptur_fremd_ek_pro_einheit()`) als kompletter EK – nichts aufschlagen. Wird von `angebot_rezeptur_zeilen()` genutzt.
  - **Fremd-Fertigpreis, drei Quellen:** `rezeptur_fremd_ek_pro_einheit($rid, $einheiten)` nimmt den günstigsten je Stück aus **A** `rezeptur_lief_angebot` (Lieferantenportal „Rezepturpreise"/v3), **B** `produkt_lieferant_preis` (**angenommene** Fertigprodukt-Angebote je Produkt) und **C** `lieferant_angebot` (**beantwortetes** Angebot zur Fertigprodukt-Anfrage – Button „Fertigprodukt anfragen"). **Quelle C ist entscheidend**, weil sie schon **vor dem Annehmen** und **ohne Produkt** zur Rezeptur greift: Das Produkt entsteht erst beim Annehmen des Angebots, und `lieferant_fertig_angebot_annehmen()` schreibt `produkt_lieferant_preis` nur, wenn es schon ein Produkt gibt – sonst käme der Lieferantenpreis nie im Angebots-EK an. Preisbasis (je 1 / je 1000) wird auf „je Einheit" normiert, Mengenstaffel (`lieferant_angebot_staffel`) beachtet.
  - **Eingefrorene Positions-EK/VK nachziehen:** `angebot_herstellung_ek_auffrischen($angebot_id)` füllt Herstellungs-Positionen, deren `ek_cent` **0** ist, aus `rezeptur_herstell_ek_pro_packung()` nach. Den Kunden-VK (`preis_cent`/`preis_e4`) berechnet es nur, wenn er **ebenfalls 0** ist (Zeile wurde nie bepreist) – Marge aus `angebot.marge_override`, sonst Form-/Mindestmarge; ein vorhandener EK **oder** Preis bleibt immer unangetastet (die Produkt-Matrix staffelt anders). So erscheint ein **erst später vom Lieferanten eingetragener** Fertig-/Rohstoffpreis im Angebots-Editor automatisch (EK **und** Preis), statt als 0 stehen zu bleiben. Aufruf beim Öffnen des Editors (`module/angebot/detail.php`); danach `angebot_staffel_cache_verwerfen()`.
  - **Packungsgröße je Darreichungsform:** Die Spalte `produkt_preis.stueck` bedeutet je nach Form etwas anderes – Stückzahl (Kapsel, Tablette, Softgel, Stick), **Gramm** (Pulver/Granulat) oder **Milliliter** (Flüssig). Raster: `std_stueckzahlen()`, `std_fuellgewichte()` (`std_fuellgewicht_g`), `std_fuellvolumen_ml()` (`std_fuellvolumen_ml`) – gebündelt in `std_groessen_fuer($form)`. Beschriftung/Abfrage einheitlich über `form_groessen_einheit($form)` ('g'/'ml'/''), `form_ist_fuellmenge($form)`, `form_plural($form)`, `form_groessen_label($form,$wert)` („300 g", „250 ml", „120 Kapseln").
  - **Behälter-Zuordnung** (`passende_behaelter_fuer`): Kapsel/Softgel über `pack_kapazitaet`, Pulver/Granulat/Stick/**Tablette** über `item.max_fuellgewicht_g`, **Flüssig** über `item.volumen_ml`. Je Material der kleinste passende Behälter.
- **Freie Packungsgrößen:** `produkt_preis_fuer_groesse($produkt_id,$stueck)` rechnet die Preiszeilen für EINE Größe nach – auch außerhalb des Standardrasters – und ist idempotent (0 = kein Behälter passt). `produkt_matrix_generieren()` nutzt das je Rastergröße; `angebot_matrix_fuer_gruppe($pid,$gruppe,$form,$marge)` stellt vor jedem Matrix-Zugriff sicher, dass die vom Kunden **gewünschte** Größe enthalten ist. So bekommt eine frei eingetippte Menge einen echten Preis, statt still auf die nächste Rastergröße zu rutschen.
  - **Produkt = Rezeptur × Menge + Verpackung:** Eine Anfrage über mehrere Konfigurationen (z. B. 90/120/180 Stück im Glas) wird beim **Senden des Angebots** zu ebenso vielen **Produkten** – auch zu denen, die der Kunde am Ende nicht nimmt. Dadurch kennt das System deren Preise beim nächsten Mal. Helfer: `produkt_variante_id($vorlage_produkt_id,$stueck,$verp_id)` (findet die Kombination oder legt sie als **Katalogprodukt** an – `exklusiv=0`, ohne Kunde, Deckel/Etikett/Karton/Beipack aus der Vorlage, eigene Preismatrix), `behaelter_fuer_groesse()` (wählt den Behälter zum Wunsch-Typ; **kein stilles Ausweichen** auf ein anderes Material – passt keiner, gilt die Konfiguration als nicht machbar), `angebot_produkte_sichern($angebot_id)` (idempotent, schreibt nach `angebot_produkt`). `auftrag_aus_zelle()` löst die gewählte Zelle ebenfalls in ein Produkt auf – **deshalb** rechnen Produktion und Einkauf mit der bestellten Packungsgröße und Verpackung statt mit denen des Vorlage-Produkts.
  - **Preise sind kundenspezifisch, Produkte nicht:** `kunde_produkt_preise($kunde_id)` liefert die Produkte, für die dieser Kunde ein Angebot hat. Nur die zeigen im Portal einen Preis, alle anderen „auf Anfrage" – sonst wandert die Kalkulation eines Kunden zum nächsten. Der EK (`produkt_preis`, mit `stand`-Datum) gilt dagegen für alle, weil die Lieferantenpreise dieselben sind.
  - **Tablette:** kein Leerkapsel-EK, dafür Presshilfsstoffe (Füllstoff/Trennmittel/Überzug) – `tablette_hilfsstoff_prozent()` (app_meta `tablette_hilfsstoff_prozent`, Standard 20 %) und `tablette_hilfsstoff_ek_kg()` (`tablette_hilfsstoff_ek_kg`, Standard 8 EUR/kg). `tablette_gewicht_mg($rid)` = Wirkstoffe + Hilfsstoffe (Basis der Behälter-Auswahl), `tablette_hilfsstoff_ek_stueck($rid)` = EK-Zuschlag je Tablette.
  - **Flüssig:** Die Rezeptur beschreibt eine Portion; `fluessig_portion_ml()` (app_meta `fluessig_portion_ml`, Standard 10 ml) sagt, wie viele Portionen in eine Flasche gehen, `fluessig_basis_ek_l()` (`fluessig_basis_ek_l`, Standard 3 EUR/L) rechnet die Trägerflüssigkeit je ml Füllvolumen dazu.
  - **Staffel aus Optionen:** `angebot_staffel_aus_optionen()` bringt die Optionen in die Form, die das Angebots-PDF für „Preis je fertiges Produkt" nutzt (Name, Stück je Packung, Zeilen je Bestellmenge) – dieselbe Darstellung wie bei Matrix-Angeboten.
  - **Angebot aus Positionen als AUSWAHL:** `angebot_optionen($angebot_id)` zerlegt ein Positions-Angebot in wählbare Optionen – je Gruppe (A, B, C …) eine Konfiguration mit Anzahl Packungen, Gesamtpreis und **Preis je Packung**. Damit sieht der Kunde eine Zeile je Größe mit eigenem Knopf, genau wie bei der Preismatrix. Positionen ohne Gruppe sind Zuschläge und gelten unabhängig von der Wahl.
  - **Angebot aus Positionen→Auftrag:** `auftrag_aus_positionen($angebot_id, $gruppe)` ist der Weg für Angebote, die im Angebots-Editor aus einer **Rezeptur** gebaut wurden (kein Produkt im Kopf, keine Preismatrix). Die Konfiguration steht an der Herstellungsposition (`angebot_position.rezeptur_id/stueck/verpackung_id`, gesetzt von `angebot_rezeptur_zeilen()`). Nimmt der Kunde an, entsteht **erst dann** das Produkt (`produkt_aus_rezeptur()` – kundenneutral, mit eigener Preismatrix; **Ausnahme exklusive Rezeptur** (`rezeptur.exklusiv=1` mit Kunde): das Produkt wird `exklusiv=1` und an diesen Kunden gebunden, steht nicht im Katalog, und die Suche nach einem vorhandenen Produkt sucht nur unter den Produkten dieses Kunden – dadurch entsteht kein zweites kundenloses Produkt; `produkt_variante_id()` erbt Kunde+Exklusivität des Vorlage-Produkts, S-02), danach Auftrag + Rechnung + Produktionsauftrag. Ist eine Gruppe angegeben, zählen nur deren Positionen plus die gruppenlosen Zuschläge – der Kunde bekommt also genau die Größe, die er gewählt hat. Nur Angebote im Status `gesendet` sind annehmbar; ein zweiter Klick legt keinen zweiten Auftrag an (Prüfung auf vorhandenen `auftrag.angebot_id`). Ältere Angebote, die noch ohne diese Angaben gebaut wurden, reparieren sich beim Annehmen selbst: `angebot_positionen_konfig_nachtragen()` holt Rezeptur + Menge je Packung aus der zugehörigen Anfrage und den Behälter aus der Verpackungsposition derselben Gruppe (über `angebot_position.artikelnr` → `item.artikelnummer`). Ohne Herstellungsposition mit Rezeptur+Stückzahl passiert nichts – dann fehlt die Angabe, **was** hergestellt werden soll.
  - **Kundenpreis-Automatik bei Bestätigung:** Alle drei Bestätigungswege (`auftrag_aus_positionen`, `auftrag_aus_zelle`, `auftrag_aus_angebot`) rufen `kundenpreis_setzen($produkt_id,$kunde_id,$stueck,$anzahl,$preis,$quelle)` mit dem **Herstellungspreis je Packung (ohne Verpackung)**. Upsert je (Produkt,Kunde,Stück) in `produkt_kundenpreis` → der Kunde sieht im Portal „ab xy €" statt „auf Anfrage" (siehe `module/portal/kunde.md`). Staffel-Weg pflegt nur, wenn eine Herstellungs-Position dieser Größe existiert (sonst bleibt der gerechnete Matrix-„ab").
  - **Angebot als Matrix→Auftrag:** `auftrag_aus_zelle($angebot_id,$stueck,$verp_id,$bestellmenge)` erzeugt aus einer gewählten Preismatrix-Zelle Auftrag+Rechnung+Produktion; VK wird serverseitig aus `produkt_preis` geprüft (+ Kundenrabatt). Gewählte Stückzahl/Verpackung landen in `auftrag.stueck/verpackung_id` und `produktionsauftrag.stueck/verpackung_id` (Produktion nutzt diese, nicht produkt.einheiten). `angebot.kunde_ausgeblendet` = vom Kunden „gelöscht". Produktionszeit-Schätzung: `app_meta.produktionszeit_wochen`.
  - `portal_anfrage` – Kundenanfragen aus dem Portal (typ produkt/rohstoff/dienstleistung, Nr. PAF-); Produktanfrage mit produkt_id/stueck/verpackung_id/menge, sonst betreff/notiz. Portal-Freischaltungen je Kunde: `kunden.portal_rezeptur/produkte/rohstoffe/dienstleistung`.
- **Feste Nummern:** `naechste_nummer('K')` → z. B. „K-0006" (atomar hochgezählt). `item_prefix($kategorie)` wählt für Warenlager-Items das Präfix (Rohstoff=R, Verpackung=VP, Fertigware=FP …). Beim Anlegen vergeben die Detailseiten die Nummer automatisch, wenn das Feld leer ist.
  - `aktivitaet` – das zentrale Ereignis-Protokoll für den Verlauf, für **jedes** Objekt (über `objekt_typ` + `objekt_id`).
  - `lieferant_anfrage` – zusätzlich `art` (rohstoff|fertigprodukt|verpackung|verbrauch|sonstiges), `form` (Darreichungsform bei Fertigprodukt), `stueck_je_packung`, `kapselgroesse_id`, `rezeptur_id`; `lieferant_angebot.preis_basis` (Preis je 1 oder je 1.000). Helfer: `anfrage_arten()`, `anfrage_formen()` / `anfrage_formen_fuer_art()` (Fertigprodukt und Rohstoff haben je eigene Formen), `anfrage_form_fuer_item()`, `anfrage_einheit_fuer_form()`, `anfrage_einheit($item_id,$art,$form)` (bei Stückware zählt die Form, bei Schüttgut die Bezugsgröße des Artikels), `anfrage_art_fuer_item()`, `anfrage_art_label($art,$form,$sprache)` (de/en/zh), `einheit_wort($e,$menge,$sprache)` (Ein-/Mehrzahl, de/en/zh), `zahl_lesen($roh,$mengenfeld,$sprache)` – liest eingetippte Zahlen in jeder Schreibweise: „12,50" und „12.50" sind 12,5; stehen Punkt und Komma zusammen, trennt das hintere die Nachkommastellen. Bei **Mengenfeldern** gilt zusätzlich der Tausenderpunkt der jeweiligen Sprache (deutsch „250.000", englisch/chinesisch „250,000" = 250000); eine Gruppe beginnt nie mit Null, deshalb bleibt „0.045" ein Wert. Preise werden nie als Tausender gelesen. Die Einheit wird nie eingetippt, sondern abgeleitet – Kapsel/Tablette/Softgel/Stick je Einheit, Pulver/Granulat je kg, Flüssig je Liter.
  - `nachricht` – Rückfragen zwischen Team und Lieferant (`lieferant_id`, optional `bezug_typ`/`bezug_id` = Bestellung oder Preisanfrage, `akteur`, `autor`, `text`, Gelesen-Flags je Seite). Logik in `core/nachricht.php`.
  - **„Geht nicht" – Anfrage absagen:** `portal_anfrage.absage_grund` hält die Begründung, die der Kunde im Portal liest; der Status wird `abgelehnt`. Ein noch nicht gesendeter Angebots-Entwurf wird dabei mit `angebot_entwurf_verwerfen()` gelöscht (Positionen, Staffeln, `angebot_produkt` mit) – nur bei Status `offen` und ohne Auftrag. `nummer_zurueckgeben()` setzt den Nummernkreis zurück, wenn es die zuletzt vergebene Nummer war: kein Loch in der Angebotsnummerierung, wenn ein Entwurf sofort wieder verworfen wird.
  - **Etikett hängt am Behälter:** Maßgeblich ist `item.etikett_final` (Endformat B x H) am **Behälter**. `passende_etiketten_fuer($verpackung_id)` liefert die Etiketten, deren Breite/Höhe (aus `breite_mm`/`hoehe_mm`, sonst `etikett_format`) bis auf 2 mm passen; `etikett_masse()` zerlegt „56 x 143". `etikett_zuordnung()` gibt die ganze Karte Behälter → Etiketten für die Auswahl im Angebots-Editor. **Ohne Endformat am Behälter passt nichts** – bewusst leer statt wahllos alle Etiketten, damit kein unpassendes Etikett ins Angebot rutscht. Die generischen Behälter-Etiketten haben `produkt_id IS NULL`; `passende_etiketten_fuer()`/`etikett_zuordnung()` filtern darauf, damit die **kundenspezifischen** Etikett-Artikel (s. u.) die Maß-Vorlage nicht verfälschen.
  - **Kundenetikett-Artikel (pro Produkt, versioniert):** die gedruckten Kundenetiketten werden **kundenrein** verbucht, nicht im generischen Glas-Topf. Bei **Auftragsbestätigung** (`auftrag_aus_angebot`) legt `kundenetikett_sicherstellen($produkt_id)` einen eigenen Etikett-Lagerartikel an (`item` mit `verpackung_rolle='etikett'`, `produkt_id`, `etikett_version=1`, Maße aus der Glas-Vorlage via `kundenetikett_vorlage()`), setzt `produkt.etikett_id` darauf und bindet den Auftrag (`auftrag.etikett_item_id`). **`kundenetikett_vorlage()` richtet das Maß am TATSÄCHLICHEN Behälter aus** (`produkt_behaelter_id()`: jüngster Auftrags-Behälter mit Vorrang, sonst Produkt-Behälter) – gleiche Quelle wie `produkt_etikettmass()`/`erp_produkt_etikettmass()`. Vorher las sie den rohen `produkt.verpackung_id`, wodurch v1 das Maß eines anderen Glases übernehmen konnte (z. B. 200-ml-Endformat „67 x 188" bei einem 150-ml-Auftrag „60 x 172"). Altbestand mit falschem Maß behebt die Datenprüfung (`etikett_mass_abweichung`). Lädt der Kunde ein **neues Design** hoch und **gibt es frei**, legt `kundenetikett_nach_freigabe()` (Hook in `etikett_freigabe_setzen`) eine **neue Version** (v2, v3 …) an – erkannt über die Datei-Signatur `etikett_datei_sig` (`sha1_file`); gleiche Datei (Nachbestellung/Re-Freigabe) = **kein** Bump. Die alte Version wird `gesperrt=1` (Restbestand bleibt historisch sichtbar, **nie gelöscht**). `etikett_item_fuer_auftrag()` löst je Auftrag die gebundene Version auf (sonst der aktuelle Produkt-Etikett-Artikel), `etikett_item_fuer_produkt()` den aktuellen. Im Stücklisten-Dropdown (Produkt/Angebot) erscheinen nur generische + die **eigenen** Etiketten, nicht fremde Kundenetiketten.
  - **Kundenetikett je PRODUKT + KUNDE (Paket Etiketten, Teilfix 2):** neue Spalte `item.kunde_id` am Kundenetikett-Artikel. Die Versionskette (v1/v2/…) gilt **je Produkt+Kunde**; eine neue Freigabe sperrt nur die Version desselben Kunden (vorher sperrte jede Freigabe das Etikett des vorigen Kunden desselben Katalogprodukts, und alle Kunden etikettierten/sahen das Design des Letzten). `kundenetikett_aktuell($pid,$kid)`, `kundenetikett_sicherstellen($pid,$kid,$verpackung_id)`, `kundenetikett_version_anlegen(…,$kid)`, `kundenetikett_nach_freigabe()`, `etikett_item_fuer_produkt($pid,$kid)`, `etikett_bestand_info($pid,$kid)` kennen den Kunden (mit Kunde nur dessen Artikel, nie ein fremder; ohne Kunde = interne Produktsicht über alle). Name „Etikett · Produkt · Firma · vN". `produkt.etikett_id` wird bei Katalogprodukten nicht mehr mit einem Kundenetikett belegt (nur wenn das Produkt dem Kunden gehört); der Bedarf nutzt den Slot nur noch, wenn er ein generisches Etikett ist. Gemeinsamer Annahme-Hook `auftrag_annahme_etikett_verpackung()`. **Altdaten:** `kundenetikett_je_kunde_migrieren()` (einmalig, Meta-Flag `migr_kundenetikett_je_kunde`, löscht nichts): A) Besitzer = Kunde des frühesten gebundenen Auftrags, sonst Produkteigentümer; B) Namen um die Firma ergänzen; C) höchste Version je (Produkt+Kunde) wieder entsperren; D) noch nicht produzierte Aufträge (status offen) an fremdem Kundenetikett auf die Kette ihres Kunden umbinden (Rückgabe = Zähler). Werk-App: `erp_etikett_item_id()` (produktion/core/erp.php) = gebundenes Etikett des Auftrags.
  - **Etikett-Maß aus dem Endformat der PDF (Sofort-Fix 08.11.2026):** `pdf_boxen($pfad)` liest die Boxen der ersten Seite (TrimBox/ArtBox/MediaBox in mm, auch aus komprimierten Objekt-Streams), `pdf_etikett_mass($pfad)` nimmt die **TrimBox**, ohne sie die ArtBox, sonst die MediaBox. `kundenetikett_nach_freigabe()` ruft `etikett_mass_aus_pdf_setzen($item, $dok)`, wenn ein **neues** Design übernommen wird (v1 ohne Design oder neue Version): `breite_mm`/`hoehe_mm`/`etikett_format`/`etikett_final` = Endformat (Ausrichtung wie das bisher gespeicherte Maß), `etikett_druck` = MediaBox; protokolliert in `aenderung_log` (route `etikett_pdf_trimbox` …). Gleiches Design erneut freigegeben = keine Änderung; bestehende Etiketten werden **nicht** rückwirkend geändert (Liste: Datenprüfung `etikett_mass_trimbox`). `pdf_masse()` (Einkauf „Etiketten (Druckdateien)") zeigt ebenfalls das Endformat mit Zusatz „(Endformat)". Maß-Abweichungen zum Glas sind nur Hinweis, nie Sperre (Soll-Handbuch 4.5).
  - **Chargen & Dokumente an der Bestellung (08.11.2026):** Tabelle `bestellung_charge` (Charge/MHD/Menge des Lieferanten je Bestellposition, mehrere je Position), `dokument.bestellung_position_id`, `dokument.rechnung_nr/betrag/waehrung`. `bestellung_lieferung_erfassen()` setzt `charge.bestellung_position_id`. Logik in `core/bestellung_chargen.php`.
  - **Kundenetiketten netto (Sofort-Fix 08.11.2026):** `bedarf_aggregiert()` rechnet Etikett-Zeilen (je Etikett + Auftrag) netto gegen freien Bestand, Quarantäne und unterwegs des Etikett-Artikels – vorher `stock=0` (immer voller Auftragsbedarf → Doppelbestellung). Eine Rechnung: `material_deckung($item,$benoetigt,$auftrag)` = frei (`item_verfuegbar_fuer`) / Quarantäne / unterwegs / Rest; genutzt von Bestellliste (ein Auftrag je Etikett-Artikel), Material-Reiter am Auftrag und dem Etiketten-Stand im Fortschritt. Mehrere Aufträge je Etikett-Artikel: Bestand in Auftragsreihenfolge verteilt. `item_unterwegs()` = jede nicht eingegangene, nicht stornierte Bestellung (eine Definition für Bestellliste und Material-Reiter).
  - **Bedarf nimmt das KUNDENETIKETT (Paket Etiketten, Teilfix 1):** `auftrag_bedarf()` bestimmt das Etikett zuerst über `etikett_item_fuer_auftrag_kunde()` (gebundene Version `auftrag.etikett_item_id`, auch wenn gesperrt; sonst aktuelles Kundenetikett des Produkts). Die Ableitung aus dem Behälter (`etikett_id_fuer_behaelter()` = generisches Maß-Etikett) ist nur noch **Fallback**, wenn es gar kein Kundenetikett gibt. Vorher überschrieb sie das Kundenetikett, sobald der Auftrag eine eigene `verpackung_id` hatte (setzt der Annahmeweg immer) → Einkauf/Wareneingang/Bestand liefen über das generische Etikett („physisch 0, fehlen 4.000"). `kundenetikett_nach_freigabe()` ruft jetzt `bedarf_bump()` (sonst meldete die Bereitschaft nach dem Wechsel auf v2 weiter „Etikett vorrätig" mit den alten v1-Etiketten).
  - **Gültigkeit:** `angebot_gueltig_bis_default()` = heute + `angebot_gueltig_tage` (Standard 14). Wird beim Anlegen gesetzt und beim **Senden** aufgefrischt, falls das Datum leer oder schon abgelaufen ist – ein wochenalter Entwurf ginge sonst bereits abgelaufen raus. Ein selbst gesetztes Datum in der Zukunft bleibt unangetastet.
  - `angebot_positionen()` gibt **`rezeptur_id`, `stueck` und `verpackung_id` mit heraus** – ohne sie verlor der Editor beim Speichern, *was* hergestellt werden soll, und der Kunde konnte nicht mehr annehmen. In der Optionszeile stehen alle Verpackungsteile (Behälter, Deckel, Etikett), weil sie im Preis stecken.
  - **Verbindliche Freigabe:** `rezeptur.freigabe_name` / `freigabe_am` und `angebot.freigabe_name` / `freigabe_am` halten fest, **wer wann** angenommen hat. Der Name gilt als Unterschrift; ohne gesetzten Haken und Namen wird im Portal nichts angenommen (serverseitig geprüft, nicht nur im Dialog).
  - **AGB versioniert:** Tabelle `agb` (eine Fassung aktiv, ältere bleiben als Beleg) plus `rezeptur.agb_version` / `angebot.agb_version` – die beim Annehmen geltende Fassung wird am Vorgang festgehalten. Funktionen in `core/agb.php`.
  - **Lieferantenzugang:** `benutzer.lieferant_id` macht aus einem Konto ein Lieferanten-Login (kein Team-Zugriff). Eingeladen wird per Token (`lieferant_einladung`, einmalig); `lieferant_einladung()`, `lieferant_hat_zugang()` und `lieferant_zugang_anlegen()` erledigen den Rest. Anders als beim Kunden **mit Passwort** – Lieferanten arbeiten laufend im Tool.
  - **Bestellablauf:** `bestellung_bestaetigen()` (Termin + Name, setzt die erste Station und zieht den Status auf `bestellt`), `bestellung_station_setzen()` (kumulativ; „versendet" nur mit Anbieter, Versandart und Sendungsnummer), `bestellung_stationen()` / `bestellung_stationen_en()`, `versandarten()`. Meldungen zweisprachig.
  - **Preisanfragen an Lieferanten:** `lieferant_anfrage` (optional zu einem Artikel), Antwort in `lieferant_angebot` + `lieferant_angebot_staffel`. `lieferant_anfrage_stellen()`, `lieferant_angebot_speichern()` (erneutes Senden überschreibt) und `lieferant_angebot_annehmen()` – letzteres schreibt die Staffeln als **EK-Staffeln** nach `lieferant_preis`, also genau dorthin, wo die Kalkulation rechnet.
  - **Eigene Spezifikation / eigenes CoA:** `charge_analyse` hält die Analysenwerte je Charge (Parameter, Spezifikation, Ergebnis, Methode). Daraus und aus den Artikel-Stammdaten entstehen unsere Dokumente im bulkify-Layout (`core/pdf_spec.md`) – die Unterlagen der Vorlieferanten bleiben intern.
 
- **Migrations-Werkzeug:** `table_exists`, `column_exists`, `ensure_column` – so kommen später neue Felder **additiv** dazu (nur hinzufügen, nie löschen). Alte Daten bleiben unberührt.
- **Einstellungen:** `meta_get` / `meta_set` – lesen/schreiben in `app_meta`.
- **v3-Anfragen: fehlende Inhaltsstoffe nachgetragen** (`fix_anfrage_wunsch_v3`): Bei aus v3 übernommenen Rezepturanfragen fehlten die vom Kunden gewünschten Zutaten (`rezeptur_anfrage_wunsch`). Die Migration hat die Zutaten je Anfrage-Nummer (RZA…) aus der v3-Datenbank fest hinterlegt und trägt sie nach – **nur** wenn die Anfrage existiert und noch keine Wunschzeilen hat (idempotent, überschreibt nichts).
- **Protokoll:** `log_aktivitaet(objekt_typ, objekt_id, akteur, text, typ, ref_typ, ref_id)` – schreibt einen Verlaufseintrag (Zeit als UTC). **Jedes künftige Modul ruft das auf**, dann erscheint der Eintrag automatisch im Verlauf des Objekts (Kunde/Lieferant/…). `verlauf_fuer(objekt_typ, id)` liest ihn zurück.
- **Testdaten:** `seed_kunden_if_empty` / `seed_lieferanten_if_empty` / `seed_aktivitaet_if_empty` – füllen lokal Demo-Daten, wenn leer.
- **Dokument-Freigabe:** `dokument.kunde_sichtbar` (Migration, Standard 0 = intern) steuert, ob ein CoA/Spec im Kundenportal erscheint. Helfer in `core/dokument_ui.php`: `dokumente_fuer_kunde()`, `dokument_freigabe_toggle()`. Ausgeliefert wird im Portal über die eigene öffentliche Route `portal_dok`, die Token, Freigabe und Bereichs-Freischaltung prüft.
- **Daten zurücksetzen / Startset:** `daten_zuruecksetzen($mitRezepturen,$mitKunden)` leert die Vorgangstabellen in FK-sicherer Reihenfolge und setzt `seed_demo_off=1`; Stammdaten (item, naehrstoff, kapselgroesse, benutzer, nummernkreis) bleiben unangetastet. `seed_startset()` legt danach saubere Rezepturen + Produkte nach dem Modell an. Beides steckt in Einstellungen → Werkzeuge, das Löschen hinter einer Sicherheitsabfrage.
- **Demo-Seeds abschaltbar:** `seed_kunden_if_empty()`, `seed_lieferanten_if_empty()` und `seed_partner_if_empty()` prüfen jetzt ebenfalls `app_meta.seed_demo_off` – sonst legen sie gelöschte Demo-Geschäftspartner beim nächsten Seitenaufruf wieder an, sobald die Tabelle leer ist. **Nicht** abgeschaltet werden Seeds für echte Stammdaten (Benutzer/Admin, Kapselgrößen, Nährstoffe, Verpackungen, Rohstoffe) – die müssen sich auf einer frischen Installation aufbauen können.
- **Live-Schutz:** `system_ist_live()` liest `app_meta.system_live`; `loeschen_erlaubt($wort)` gibt nur dann frei, wenn das System nicht live ist **oder** „LÖSCHEN" (bzw. „LOESCHEN") eingetippt wurde. Beide löschenden Werkzeuge in den Einstellungen prüfen das serverseitig, nicht nur im Formular.
- **Behälter-Stammdaten (Seeds, je einmalig über einen Marker in `app_meta`):** `seed_behaelter_kapazitaet()` legt die Standard-Gebinde an (PET Packer + Weithalsglas 100/150/200/250 ml, PLA, Flip Packer) inkl. Kapsel-Fassung; `seed_etikett_preise()` die Etiketten-Staffeln je Gebinde; `seed_standbodenbeutel()` die Beutel XS–XXL; `seed_etikett_formate()` die **Etikettenformate je Gebinde** (Druckdatei- und Endmaß aus dem Herstellerblatt „Kapselgrößen/Etikettengrößen", 09/2026) an PET Packer, Weithalsglas 100–250 ml und Braunglas 10/30 ml (die beiden Braungläser werden dabei als Artikel angelegt, ohne EK), dazu je Endformat einen **Etiketten-Artikel** (`verpackung_rolle='etikett'`, Breite/Höhe) mit EK-Staffel aus den Etikettenpreisen des Gebindes – so findet `passende_etiketten_fuer()` zu jedem Behälter ein Etikett mit Preis; `seed_packari_behaelter()` die **EK-Preise + Mengenstaffeln von Packari** für PET-Dosen und Weithalsgläser (100–250 ml) und die vier **Deckel mit Pressure-Seal-Einlage** (38/400 + 45/400, je weiß und schwarz) als eigene Artikel mit `verpackung_rolle='verschluss'`. Der Gebinde-EK ist der Preis **ohne Verschluss**; der Deckel-EK ist die Differenz „Set minus Dose ohne Verschluss" (Bezugsdose steht in der Notiz des Deckels). Alle Seeds sind nicht-überschreibend: sie füllen nur, was leer ist.

- **Rezepturanfrage-Historie (nur intern):** `rezeptur_anfrage_snapshot` friert bei **jeder inhaltlichen Änderung** einer Anfrage den damaligen Stand (Produktname/Form/Notiz + Wunschzeilen als JSON) unveränderlich mit Zeitstempel ein. Helfer `rezeptur_anfrage_snapshot($anfrage_id,$quelle)` (nach jedem Schreiben aufgerufen aus Portal `kunde.php` und `anfrage/detail.php`/`rezeptur_ki.php`; identische Folge-Snapshots werden per `inhalt_hash` übersprungen) und `rezeptur_anfrage_snapshots($anfrage_id)` (ältester zuerst, JSON dekodiert). So ist lückenlos belegt, **was der Kunde angefragt hat**. Anzeige read-only auf der Admin-Anfrageseite; der Kunde sieht es nicht.
- **Etikettenrollen (Inventaraufkleber):** `charge_gebinde` bekommt additiv `inventar_nr VARCHAR(7)` (Maniso-Inventaraufkleber je Etikettenrolle, Index `idx_inventar`), `auftrag_id`/`pa_id` (Produktion: welcher Auftrag nimmt die Rolle) und `verbraucht_am`. Geschrieben/gelesen wird das Lager-seitig über die Naht `lager/core/erp.php` (`erp_etikett_rollen_anlegen`, `erp_rolle_per_inventar`, `erp_rolle_auftrag_zuordnen`, …). Eine Etikettenrolle = ein `charge_gebinde`-Datensatz mit gesetzter `inventar_nr`.

**Wichtig / Regel:**
- Reihenfolge in `init_schema`: erst `CREATE` (frisch installierbar), dann `ensure_column`-Migrationen. Nie eine Spalte löschen.
- `akteur` im Protokoll steuert die Chat-Seite: `team` = wir (links), `system` = mittig, alles andere (z. B. `kunde`, `lieferant`) = Gegenstelle (rechts).

  - **Rohstoffkosten fürs Angebot:** `rohstoff_bester_lieferant($item_id,$menge)` gibt den günstigsten Lieferanten (Firma + Preis) für eine Menge, mit Rückfall auf die kleinste Staffel unter MOQ. `angebot_rohstoffkosten($angebot_id)` summiert die Rohstoffkosten der Angebots-Rezeptur bei der größten Bestellmenge und zählt Zutaten ohne Lieferantenpreis – Grundlage für das gleichnamige Panel im Angebots-Editor. Die Menge je Rezeptur kommt aus `rezeptur_einheiten_gesamt($rid,$stueck,$packungen)`: bei Stück-Formen stueck × Packungen, bei Pulver/Granulat (stueck = g je Packung) die Portionen aus dem Füllgewicht (g × 1000 / mg je Portion) – vorher wurde g als Portionszahl gerechnet (5-fach zu hoch bei 5-g-Portionen, St-13). Fehlt jeder Lieferantenpreis in EUR, aber am Rohstoff ist ein EK-Preis gepflegt, nimmt `angebot_rohstoffkosten` diesen (Lieferant = Hauptlieferant, `ek_quelle='ek'`) – vorher stand dort „kein Preis“, obwohl die Rohstoffseite einen EK zeigte (05.11.2026).
  - **Angebot-Positionen (Robustheit gegen v3-Import):** `angebot_positionen()` ignoriert reine **Null-Positionen** (menge=0 & preis=0, aus v3-Import) und leitet den Inhalt dann aus der **Mengen-Staffel** (`angebot_staffel`) ab (`angebot_positionen_aus_staffel()` – je Staffel eine Gruppe A/B/C, Preis je Packung, MwSt je Kunde), sonst automatisch aus der Anfrage (`angebot_positionen_auto()`). So zeigen **PDF und Admin-Detail** dieselben Staffeln wie die **Kundenkarte** (die die Staffel liest). `angebot_hat_positionen()` zählt ebenfalls nur echte Positionen.
  - **Bulk-Zukauf Preise/Bestand (Stand 2026-10-08):** `produkt_lieferant_preise($produkt_id,$stueck)` → [lieferant_id ⇒ Preis je Einheit] aus `produkt_lieferant_preis` (nur EUR, passende Staffel, gleiche Logik wie `item_lieferant_preise`); `produkt_zukauf_preis($produkt_id,$lieferant_id,$stueck)` → Preis für EINEN Lieferanten oder null. `bedarf_bulk()` liefert jetzt zusätzlich `stock` (freier, nicht kundengebundener Fertigware-Bestand `item_bestand(produkt_lageritem)`; Item wird NICHT angelegt) und rechnet ihn in `zu_bestellen = max(0, need − stock − bestellt)` ein. `bestellung_bulk_anlegen()` verrechnet den Bestand FIFO über die Aufträge und schreibt den Zukaufpreis des gewählten Lieferanten in `ek_preis` (vorher 0).
  - **Angebot annehmen (Rohstoff vs. Fertigprodukt):** `lieferant_angebot_annehmen()` schreibt bei Artikel-Anfragen (item_id) die Staffeln nach `lieferant_preis`. Bei **Fertigprodukt-Anfragen** (per Rezeptur, kein item_id) übernimmt `lieferant_fertig_angebot_annehmen()` den Preis als **Zukaufpreis an alle Produkte der Rezeptur** (`produkt_lieferant_preis`, Preisbasis je 1/1000 auf „je Einheit" normiert) – so erscheinen angefragte/angenommene Fertigprodukt-Preise in „Lieferanten-Preise" (Reiter Fertigprodukt), am Produkt und am Auftrag.
  - **Schüttgut-Bedarf (Pulver/Granulat/Gel/Flüssig/Öl) – Kaufeinheit je Produktionsart:** `auftrag_bedarf()` führt Schüttgut bewusst NICHT in Portionen. Bei **Zukauf (fremd)** kaufen wir fertige **Packungen**: die Fertigware-Zeile steht in `Pkg` (`benoetigt = Packungsmenge`, Bestand Portionen ÷ Stück-je-Packung), nicht in 300.000 Portionen. Bei **Eigenproduktion** liefert `produktion_materialbedarf()` den Rohstoff in seiner Einkaufseinheit, für Pulver in **kg** (mg × Einheiten ÷ 1e6). Das Verhältnis `benoetigt/menge` bleibt im Zukauf 1 je Packung, daher rechnen Teilproduktion/Bereitschaft unverändert korrekt. `pa_vorbereitung_checks()` hängt an den Material-Check die Einheit an, wenn alle fehlenden Posten des Bündels dieselbe haben (z. B. „fehlt noch 6.000 Pkg" / „1.500 kg"). Zählbare Formen (Kapsel/Tablette/Softgel/Gummi/Stick) bleiben in Stück. Die Vor-Produktion zeigt passend dazu Füllmenge/Gesamtmenge statt Stückzahl und das Freigabe-Feld in Packungen (siehe `module/produktion/vorbereitung.md`).
  - **Kundeneigene Verpackung / Beistellung (2026-11-02):** `verpackung_kundeneigen_sicherstellen($auftrag_id, $basis_item_id)` legt beim Haken „vom Kunden beigestellt" (Auftrag, Reiter Verpackung) einen **auftragseigenen Verpackungs-Artikel** an (Kopie des Basis-Behälters, `item.kundeneigen=1`, `item.auftrag_id`, `ek_preis=0`, kein Lieferant; idempotent) und hängt ihn an `auftrag.verpackung_id`. Der Artikel ist **normal lagerführend/buchbar** (Wareneingang) und zählt im Material-Check/`produktion_bereitschaft()` → blockiert als Fehlbestand bis gebucht. **Nicht einkaufbar:** `auftrag_fehlbedarf()` überspringt Items mit `kundeneigen=1` (kein „zu bestellen", keine Auto-Bestellung). Die frühere „fehlt=0/nicht prüfen"-Variante wurde verworfen.
  - **Kontingente / Jahresverträge:** Tabelle `kontingent` (Kunde, Produkt, `gesamt_menge`, `abgerufen`, `vk_stueck`, Laufzeit, Status) + `auftrag.kontingent_id`. `kontingent_abruf($kontingent_id,$menge)` erzeugt aus einem Abruf Auftrag + Rechnung + Produktionsauftrag zum vereinbarten Preis und schreibt `abgerufen` fort (Details: `module/kontingent/liste.md`).
  - **Versandart (eine Wahrheit):** `versandart_liste()` ist die kanonische Liste (luft/see/bahn/lkw/express/standard) für Anfrage, Angebot UND Bestellung. `versandart_label($key,$sprache)` beschriftet mehrsprachig (de/en/zh) und deckt die Alt-Werte kurier/spedition/post aus früheren Bestellungen mit ab. Die alte `versandarten()` (nur deutsch) wird nicht mehr verwendet.
  - **Novel Food (EU-Abgleich):** `novelfood_katalog` bekommt additiv (per `ensure_column`) die Spalten `beschreibung` (englisches Original), `pub`, `erstellt`, `geaendert`. Zwei neue Tabellen für die Lauf-Historie: `novelfood_lauf` (Kopf je Abgleichslauf – Zeit, Auslöser, Zähler neu/geändert/Statuswechsel/entfernt, Dauer, Meldung) und `novelfood_change` (eine Zeile je Position: art neu|geaendert|entfernt, `felder`, `status_wechsel`, `status_alt`/`status_neu`). Befüllt von `novelfood_sync_lauf()` in `core/novelfood_sync.php`, angezeigt unter `?p=novelfood_verlauf`.

## Fremdfertigungs-Preise im Bestellvorgang (Stand 2026-10-08)
`rezeptur_fremd_lieferant_preise($rezeptur_id,$stueck)` → [lieferant_id ⇒ Preis je Stück] aus `rezeptur_lief_angebot` (passende Mengenstaffel). `produkt_fremd_lieferant_preise($produkt_id,$stueck)` macht dasselbe über die Rezeptur des Produkts. So erscheinen die Fremdfertiger (z. B. Wellgreen, Rainwood) im Bulk-Bestellvorgang. `produkt_zukauf_preis()` nimmt jetzt erst den Zukaufpreis (produkt_lieferant_preis), sonst den Fremdfertigungspreis.

## KI-Info je Rohstoff (Stand 2026-10-08)
`item.ki_info` (TEXT) + `item.ki_info_am` (DATETIME, UTC). `item_ki_info_erzeugen($item_id)` ruft die KI (ki_frage, aufwand=low), erzeugt eine neutrale, Health-Claims-konforme Kurzinfo (3–5 Sätze, DE) aus Name/lat/CAS/Form/Wirkstoffen und speichert sie. Nur auf Knopfdruck (kein Automatismus). Rückgabe ['ok','text','fehler']; ohne ANTHROPIC_API_KEY sauberer Fehler. Wird Team (Rohstoff-Liste/-Detail) + Kunde (Portal) als „KI-Info" gezeigt.

## Kunden zusammenführen / Dublette (Stand 2026-10-08)
`kunde_zusammenfuehren($quelle_id, $ziel_id)` hängt ALLE Verweise des doppelten Quell-Kunden auf den Ziel-Kunden um und löscht die Quelle. Dynamisch über alle Spalten, deren Name auf `kunde_id` endet (information_schema), in einer Transaktion (bei Fehler komplett Rollback). **Import-/Staging-Tabellen** (`v3imp_*`, `bu_imp_*`) sind ausgeschlossen – die haben einen eigenen ID-Raum und würden sonst korrumpiert (Ausschluss per `NOT LIKE ... ESCAPE '='`, kein Backslash wegen Live-MySQL). Keine UNIQUE-Constraints auf Kunden-Spalten → kollisionsfrei. Rückgabe ['ok','moved'(Tabelle.Spalte⇒Anzahl),'quelle','ziel'|'fehler'].

## Lieferanten zusammenführen / Dublette (Stand 2026-10-08)
`lieferant_zusammenfuehren($quelle_id, $ziel_id)` – analog zu `kunde_zusammenfuehren`: hängt ALLE Verweise des doppelten Lieferanten auf den Ziel-Lieferanten um (alle Spalten mit Namen auf `lieferant_id`, inkl. `item.haupt_lieferant_id`), in einer Transaktion (Rollback bei Fehler), Import-/Staging-Tabellen (v3imp_*, bu_imp_*) ausgeschlossen (ESCAPE '='). UNIQUE-Keys auf Lieferant-Spalten gibt es nur in Staging → Live kollisionsfrei. Löscht die Quelle. Rückgabe wie der Kunden-Merge.

## buxtrade-Nummer je Rohstoff (Stand 2026-10-08)
`item.bx_nummer` (VARCHAR) = buxtrade-Handelskennung eines Rohstoffs = Artikelnummer (R-Nummer) + „BX" (z. B. R-12345BX). Die R-Nummer bleibt unverändert. Befüllung idempotent im `init_schema()` (nach dem ersten Lauf 0 Zeilen; neue Rohstoffe werden beim nächsten Request nachgetragen). Helfer `rohstoff_bx_nummer($item)` liefert die gespeicherte bx_nummer, sonst abgeleitet aus der Artikelnummer (immer korrekt, auch vor dem Backfill). Angezeigt read-only auf der Rohstoff-Detailseite (Feld „buxtrade-Nr.").

## Lieferanten-Kürzel + Rohstoff-Kennung je Lieferant (Stand 2026-10-08, ersetzt die fixe „BX"-Nummer)
Jeder Lieferant hat ein `lieferanten.kuerzel` (aus dem Namen, editierbar; Vorschlag `lieferant_kuerzel_vorschlag()` = erste 2 Buchstaben GROSS, Backfill idempotent per REGEXP_REPLACE). Die Rohstoff-Kennung JE LIEFERANT = Artikelnummer (R-Nummer) + Kürzel (`rohstoff_lief_kennung($artikelnummer,$kuerzel)`, z. B. R-12345BX für Buxtrade, R-12345WG für Wellgreen). So lässt sich derselbe Rohstoff je Lieferant unterscheiden. Angezeigt: Rohstoff-Stammdaten (Hauptlieferant-Kennung) + je Zeile im EK-Reiter „Lieferantenpreise" (Spalte „Kennung"); Kürzel-Feld in den Lieferanten-Stammdaten. (Die frühere globale `item.bx_nummer` = R+„BX" war falsch und wird nicht mehr genutzt.)

## Varianten-Gruppen reparieren (`angebot_gruppen_normalisieren`)
Eine **Variante** eines Angebots = genau EINE Herstellungszeile + die darauf folgenden Verpackungs-/Zusatzzeilen,
gekennzeichnet durch einen Gruppenbuchstaben (A, B, C …). Landen mehrere Mengen-Varianten faelschlich in
**derselben** Gruppe, wirft `angebot_optionen()` / die PDF-Zusammenfassung „Preis je fertiges Produkt" sie
zusammen: Packungspreis = **Summe ALLER Varianten ÷ letzte Menge** (z. B. 24.390 € ÷ 5.000 = 4,88 € statt der
echten 2,75 €), und der Kunde koennte nicht eine einzelne Variante annehmen. `angebot_gruppen_normalisieren($id)`
vergibt jeder Herstellungszeile wieder einen eigenen Buchstaben (Zeilen vor der ersten Herstellungszeile bleiben
gruppenlos = echte Zuschläge). Idempotent (saubere Angebote: je Gruppe hoechstens eine Herstellungszeile → keine
Aenderung). Aufruf an den Schreib-/Lese-Einstiegen: Angebots-Editor (Laden **und** `pos_save`) und
`angebot_pdf_bauen()` – so repariert sich ein betroffenes Angebot beim naechsten Oeffnen/Drucken selbst.

## Auftrags-Phasen (`auftrag_phasen()` / `kunde_auftrag_phase()`)
8 Phasen (Index 0..7): Bestätigt · Rohstoff bestellt · Rohstoff angekommen · In Produktion · Etikettiert · Qualitätsprüfung · 6 **Versandbereit** · 7 **Versendet**. Bei **Fulfillment-Kunden** (`kunden.nutzt_fulfillment=1`) heißen die letzten beiden stattdessen „Bereit zur Einlagerung" / „Eingelagert" (Ware geht ins Fremdlager/Lager 2 statt in den Versand). **„Eingelagert" ist also ausschließlich eine Fulfillment-Phase** – für normale Kunden gibt es sie nicht, auch wenn die Fertigware intern in Lager 1 gebucht wird (`produktion_gebucht()` wird für Nicht-Fulfillment gar nicht geprüft).

## Versand & Teilversand (`auftrag_versenden_menge()`)
`auftrag_versenden_menge($auftrag_id, $menge=null)` liefert Fertigware an den Kunden: FEFO-Ausbuchen der Verkaufsfertig-Charge + Lieferschein (`beleg` typ=lieferschein, `beleg.menge` = gelieferte Packungen, netto anteilig). `$menge=null` = kompletter offener Rest; ein kleinerer Wert ist **Teilversand**. `auftrag.versendet_menge` zählt kumuliert mit; der Status geht erst auf **`versendet`**, wenn `versendet_menge >= menge` – vorher bleibt der Auftrag **`erledigt`** (versandbereit) und der Rest ist später lieferbar. `auftrag_versenden()` = Rest komplett (Rückwärtskompatibilität). Mehr fertig als offen kann nie geliefert werden; mehr offen als fertig → nur der fertige Teil, Rest nach weiterer Produktion.

## Fortschritt-Override (`auftrag.phase_override`)
`kunde_auftrag_phase()` leitet die aktuelle Phase aus Signalen ab (Bestellung, Charge/Wareneingang,
Produktionsschritte, Status). Für Stufen ohne Systemsignal (z. B. „Rohstoff bestellt" bei Alt-/Zukauf-Aufträgen)
gibt es `auftrag.phase_override` (Phasenindex): er hebt den Balken **mindestens** auf die gewählte Stufe (Floor,
echte Signale können weiter schieben); fehlende Zwischendaten werden mit dem Status-Datum gefüllt. Gesetzt im
Auftrag-Reiter Produktion („Fortschritt setzen", Handler `phase_setzen`), der auch den groben `auftrag.status`
mitführt. Gilt in Team- und Kundenansicht (beide lesen `auftrag.*`, inkl. der Portal-Fallback-Variante von
`kunde_auftrag_track`).

## Lagerware -> Auftrag zuweisen (2026-10-28)
`auftrag_lagerware_quellen($aid)` / `auftrag_lagerware_zugewiesen($aid)` / `charge_auftrag_zuweisen($chargeId,$aid,$menge)` / `charge_auftrag_loesen($chargeId,$aid)`: binden vorhandene Lager-Chargen des Bulk-Artikels (`auftrag_bulk_item`, Kategorie `fertig`) **hart** an einen Auftrag über `charge.auftrag_id`. Teilmengen werden als Kind-Charge mit gleicher `charge_nr` abgespalten (freier Rest bleibt zuteilbar → eine Charge kann mehreren Aufträgen zugeteilt werden). Zählt dann als Fertigware (`auftrag_fertigware_cached`) und räumt „wartet auf Material" bei Fremd/Zukauf ab. Unterschied zu `bulk_reservieren` (weiche Reservierung, nur Eigen-Rohstoffbedarf). UI: Auftrag → Reiter Produktion, Panel „Lagerware zuweisen" (Admin).

### Nachtrag (2026-10-28): freie Produktwahl
`auftrag_lagerware_quellen()` liefert `chargen` (exakter Rezeptur-Bulk) + `weitere` (ALLE anderen freien `fertig`-Chargen im Lager, mit `item_name`/`artikelnummer`). `charge_auftrag_zuweisen()` nimmt jede freie `fertig`-Charge (nicht nur den Rezeptur-Bulk); **keine Artikel-/Rezeptur-Prüfung** (der Admin entscheidet), `warnung` nur bei Übermenge. Weil `auftrag_fertigware_cached` nur `kategorie='fertig'` + `charge.auftrag_id` zählt, räumt auch eine artikelfremde Fertigware-Zuweisung „wartet auf Material" ab; zusätzlich wird `produktion_ist_zukauf` wahr → `auftrag_bedarf` schaltet auf Zukauf, Rohstoffbedarf entfällt.

## Teil 3: item_unterwegs() + kategorie_labels()
- `item_unterwegs(int $item_id): float` – offen bestellte, noch nicht eingegangene Menge eines Items (Summe `bestellung_position` über Bestellungen `status='bestellt'` ohne Wareneingang). Für die Material-Übersicht am Auftrag (Block H1, Spalte „unterwegs").
- `kategorie_labels(): array` / `kategorie_label(string $k): string` – EINE Quelle für Warenart-Bezeichnungen im Dashboard (Block K). DB-Werte unverändert: `fertig` = **Bulkware**, `verkaufsfertig` = **Fertigprodukt**.

## Etikett änderbar bis bestellt
- `etikett_bestellt_fuer_auftrag(int $auftrag_id): bool` – true, wenn der (versionierte) Etikett-Artikel des Auftrags physischen Bestand hat ODER eine Bestellposition mit Status `bestellt`/`geliefert`. Regel (Nico): das Etikett-Design ist **änderbar, solange noch nicht bestellt**; danach fix. Portal nutzt es, um auch bei bereits freigegebenem Etikett noch „Etikett ändern" anzubieten (bzw. zu sperren); der Upload-Handler blockt Änderungen nach dem Bestellen serverseitig.

## Fremdprodukt (Warenart, Lager 2)
- `item.fremdprodukt` / `item.fremd_besitzer_id` – Artikel ist Kundenware (fertiges Produkt von außerhalb), gehört dem Kunden, Lager 2. Dahinter Kategorie `verkaufsfertig`.
- `item_warenart_label(array $it)` – zeigt „Fremdprodukt", sonst `kategorie_label()`.
- `fremdprodukt_bestand_zuordnen(int $item_id, int $kunde_id)` – schreibt den eigenen Bestand des Artikels dem Besitzer-Kunden ins Fremdlager (`charge.fremd_kunde_id` + `standort='lager2'`), Rückgabe Anzahl umgestellter Chargen. Dadurch Ausschluss aus unserem Bestand/Produktion/Angeboten (die filtern `fremd_kunde_id IS NULL`).

## auftrag_bedarf_rest() – EINE Quelle für den Restbedarf (2026-11-02)
`auftrag_bedarf_rest($pa_id)` = `auftrag_bedarf_cached()`, aber jede Position auf den **noch offenen Rest**
skaliert: Faktor = (PA-Menge − schon als Charge gebuchte Packungen) / PA-Menge. Ist nichts produziert,
ist es identisch zum Bruttobedarf. Das ist die **einzige** Quelle für (1) `produktion_bereitschaft()`,
(2) die Material-Übersicht der Auftragsseite und (3) die Material-Tabelle des Produktionsauftrags – so steht
überall dieselbe Zahl (kein „oben gedeckt / unten fehlt"-Widerspruch mehr). Auch `auftrag_fehlbedarf()`
(Bestellen) rechnet jetzt über den Rest. Der **Brutto**-`auftrag_bedarf_cached()` bleibt unverändert.
Beispiel AB-3286/PR-2796: 6.000 bestellt, 4.101 gebucht → Material nur noch für 1.899 Packungen.

## Bedruckte, kundenspezifische Verpackung (Punkt 6, 2026-11-02)
`verpackung_kundenspezifisch_sicherstellen($produkt_id,$basis_item_id)` legt – analog zum Kundenetikett –
einen eigenen Behälter-Lagerartikel AM PRODUKT an (Kopie des Standard-Behälters, `item.kundenspezifisch=1`,
`item.produkt_id`), setzt `produkt.verpackung_id` darauf und ist idempotent (bei Nachbestellung wiederverwendet).
Marker im Angebot: `angebot_position.kundenspezifisch` (Checkbox „bedruckt/kundenspezifisch" an der
Verpackungs-Position). Hook: `auftrag_aus_angebot()` erzeugt den Artikel erst **bei Annahme**.

**Annahme-Hook für ALLE Annahmewege (Paket Etiketten, Teilfix 3):** `auftrag_annahme_etikett_verpackung($auftrag_id,$angebot_id,$produkt_id,$kunde_id,$verp_id[,$gruppe])` wird von `auftrag_aus_angebot`, `auftrag_aus_positionen` (Rezeptur-Weg) und `auftrag_aus_zelle` aufgerufen. Er legt das Kundenetikett v1 (je Produkt+Kunde) bei der **Annahme** an und bindet es – vorher entstand es auf dem Positions-Weg erst bei der Freigabe, weil dort der Hook fehlte. Ist die Verpackungs-Position „bedruckt, kundenspezifisch" markiert, entsteht der bedruckte Behälter über `auftrag_verpackung_kundenspezifisch_binden()`: Artikel anlegen **und** an Auftrag **und** Produktionsauftrag binden (sonst blieben PA, Laufzettel und Werk-App auf dem neutralen Beutel). `verpackung_kundenspezifisch_sicherstellen()` legt den Artikel jetzt sauber an: **Name einmalig** („Beutel … bedruckt · Produkt“; „neutral“ und der im Produktnamen schon enthaltene Behältername entfallen), **Artikelnummer** (VP-…), **Hauptlieferant** und **EK-Staffel** (`pack_ek_staffel` + `lieferant_preis`) vom neutralen Behälter als **Startwert** (Hinweis in der Notiz: auf den Druckpreis anpassen). Der manuelle Knopf am Auftrag („Bedruckten, kundenspezifischen Behälter anlegen“) nutzt dieselbe Funktion (idempotent). Erledigt mit Blocker 1 (siehe unten): der bedruckte Behälter gehört jetzt je (Produkt + Kunde).

**Blocker 1 (2026-11-03) – Eigentümer-Modell Rezeptur / Produkt / Druck:** Rezeptur gehört niemandem (außer `exklusiv=1`),
das Produkt (Rezeptur x Menge x neutraler Behältertyp) ist kundenneutral und teilbar, der **Druck** (bedruckter Beutel,
Etikettdesign) gehört dem Kunden. Darum:
- `verpackung_kundenspezifisch_sicherstellen($produkt_id, $basis_item_id, $kunde_id)` legt den bedruckten Behälter je
  (Produkt + **Kunde**) an: `item.kunde_id`, `item.basis_item_id` (= neutraler Standard-Behälter), Name mit Firma.
  „Vorhanden“-Suche über `verpackung_kundenspezifisch_aktuell($produkt_id, $kunde_id)` – nie der Beutel eines anderen
  Kunden; Altbestand ohne `kunde_id` zählt nur, wenn ihn ausschließlich Aufträge dieses Kunden nutzen.
- **Kein** `produkt.verpackung_id` mehr auf einem Katalogprodukt; nur ein exklusives Produkt desselben Kunden bekommt den
  Beutel in den Slot. Gebunden wird am Auftrag + PA (`auftrag_verpackung_kundenspezifisch_binden()` reicht `auftrag.kunde_id` durch).
- `verpackung_neutrale_basis($item_id)`: neutraler Behälter zu einem bedruckten (basis_item_id, sonst Artikelnummer aus der
  Notiz „vom neutralen Behälter VP-…“, sonst der eindeutige neutrale Behälter aus den Angeboten der Aufträge mit
  diesem Beutel), 0 = nicht eindeutig (nicht raten).
- Gürtel: `produkt_slots_neutral()` in `produkt_aus_rezeptur()` / `produkt_variante_id()` (nur kundenneutraler Zweig) –
  bedruckter Behälter -> neutraler, Kundenetikett/kundenspezifischer Deckel/Karton/Beipack -> leer. Gleiche Regel in der
  Buchhaltungs-Kopie (`erp_produkt_slots_neutral()`).
- `produkt_behaelter_id()` (PIB/Etikettmaß) nimmt beim Auftrags-Behälter den neutralen Basis-Behälter.
- Produktion: `erp_pa_fehlbedarf()` und `erp_pa()` lösen den Behälter PA -> Auftrag -> Produkt auf; Auftrag-Dropdown zeigt
  bedruckte Behälter nur des eigenen Kunden.
- Rezepturanfrage bleibt unverändert: Rezeptur mit `kunde_id`, ohne `exklusiv` (Kunden hängen sich bewusst an).
Der frühere „kundeneigen/Beistellung"-Weg (`item.kundeneigen`, `auftrag.verpackung_beistellung`,
`verpackung_kundeneigen_sicherstellen()`) wurde **entfernt**.

## Zwei Zukauf-Arten (zukauf_art) (Punkt 5, 2026-11-02)
`produktionsauftrag.zukauf_art` ('bulk' Standard | 'fertig') unterscheidet beim Zukauf (produktionsart=fremd):
- **bulk** = Bulk/Pulver zukaufen und SELBST abfüllen (= CollaGlow): die Fertigware-Zeile zeigt das **Pulver in
  Gramm/kg** (Menge × Füllgewicht), die Verpackung (Beutel/Etikett) bleibt **eigener Materialbedarf** und wird
  separat bestellt.
- **fertig** = fertige Ware zukaufen (abgefüllt + verpackt): Fertigware in **Packungen (Stk)**; Verpackung/
  Etikett/Deckel/Karton/Beipack werden **NICHT** als Bedarf gezählt (sind enthalten).
`auftrag_bedarf()` richtet sich danach. `auftrag_express_bulk_bestellung()` bestellt entsprechend: fertig →
Packungen (Stk), bulk+Pulver → kg (Menge×Füllgewicht/1000), bulk+Kapseln → lose Stück. Kein „Gramm als Stk"
mehr. Umschaltbar am Auftrag (Reiter Produktion, Herstellung; `aktion=zukauf_art_setzen`).

**Bulk-Bestellung Schüttgut in kg/L (S-10):** Der Bulk-Bedarf von Pulver/Granulat/Flüssig/Gel rechnet in g bzw. ml, die Lieferantenpreise gelten je kg bzw. L. `bestellung_erstellen()` und `bestellung_bulk_anlegen()` legen die Position deshalb über `bulk_bestell_menge()` in **kg/L** an (600.000 g -> 600 kg × 14,90 = 8.940 €); die Staffel wird gegen diese kg/L-Menge gelesen. Die Summen „schon bestellt" (`bedarf_bulk`, Bestell-Netting, `auftrag_offener_bedarf`) zählen Positionen in kg/L über `bulk_position_basis_sql()` zurück in g/ml, damit der Bedarf nicht doppelt bestellt wird. Stück-Formen (Kapseln) bleiben unverändert in Stk. Einkaufsliste, Bestell-PDF und Lieferantenportal zeigen damit dieselbe, richtige Summe.

**Portal-AB: USt-Satz (S-04):** `beleg_positionen_aus_auftrag()` und die Sammelposition im Kundenportal (`module/portal/kunde.php`, `v=ab_pdf`) liefern die Position jetzt mit `mwst_satz` – `build_beleg_pdf()` liest dieses Feld (vorher nur `ust_satz` -> „USt. 0,00 % (steuerfrei)" bei deutschen Kunden).

**Portal-Bestellung -> „bestellt" (S-03):** Die Einkaufsliste setzt eine an den Lieferanten-Account gesendete Bestellung auf `gesendet`. `bestellung_bestaetigen()` und `bestellung_station_setzen()` heben jetzt `offen` **und** `gesendet` auf `bestellt` – erst damit greifen „bestellt/erwartet" am Auftrag, „unterwegs" und die Lager-Liste „Erwartete Lieferungen" (filtert `bestellt`). Bereits bestätigte Altbestellungen, die auf `gesendet` hängen, werden dadurch NICHT rückwirkend geändert (Datenkorrektur durch Innendienst).

**item.ean (S-17):** EAN/GTIN eines Lagerartikels (VARCHAR(40)); gefüllt beim Anmelden eines Fremdprodukts im Kundenportal. Getrennt von `item.bsku` (interne Lager-2-Nummer).

**Wareneingang schließt die Bestellung (S-06):** Eine Bestellung gilt als „offen/unterwegs" nur, solange sie weder `geliefert`/`storniert` ist noch `angekommen_am` trägt. Alle „schon bestellt"-Summen (Einkaufsliste/Bedarf, Netting, Bulk, Produktion) filtern jetzt `b.status NOT IN ('geliefert','storniert') AND b.angekommen_am IS NULL` – so zählt dieselbe Ware nicht zugleich als eingebucht UND bestellt (auch Altbestellungen, die im Lager angekommen, aber noch auf `bestellt` stehen). `bestellung_wareneingang()` (Dashboard „liefern", Einkauf mobil) bucht nichts mehr, wenn die Bestellung schon `angekommen_am` hat oder geliefert ist (idempotent), und setzt beim Buchen auch `angekommen_am`.

**Quarantäne im Bedarfs-Netting (St-07):** Ware in Quarantäne ist eine eigene Kategorie: `item_quarantaene($item_id)`. `auftrag_bedarf()` führt je Position `quarantaene` und `zu_bestellen` (= `fehlt` minus Quarantäne); `fehlt` bleibt, weil Quarantäne-Ware erst nach der Freigabe nutzbar ist (Produktionsstart bleibt gesperrt). `bedarf_aggregiert()` (Einkaufsliste), `auftrag_fehlbedarf()`/Bestell-Knopf und `produktion_materialbedarf()` (Express-Bestellung) rechnen Quarantäne vom „zu bestellen" ab – dieselbe Ware wird nicht ein zweites Mal bestellt. Die Oberflächen zeigen sie als „in Quarantäne – freigeben" mit Link `quarantaene_freigabe_url($item_id)` (-> `?p=wareneingang&quarantaene=1&item=ID`, alle Chargen in Quarantäne, nicht nur die letzten 25).

**Wareneingang einheitlich (St-12):** Quarantäne-Regel überall `item_braucht_quarantaene()` (Rohstoff, Bulk/fertig, verkaufsfertig -> Quarantäne; Verpackung/Etikett/Verbrauch -> frei). Mengen deutsch-sicher (`zahl_lesen(..., true)` im Dashboard, `erp_menge_parse()` im Lager). Bestellbezug: `bestellung_als_angekommen($id)` schließt eine Bestellung, deren Ware ohne „liefern" eingebucht wurde (Dashboard-Maske „Zur Bestellung", Lager-Wareneingang, Lager-Schnellmaske).

**Restbuchung statt Abbruch (S-13):** Die Material-Entnahmen der Produktion (`produktion_rohstoffe_entnehmen`, `_kapseln_`, `_fertigware_`, `_verpackung_`, `_etikett_entnehmen`) buchen jetzt immer „Soll bis hierhin − bereits für dieses PA verbraucht". Zentral: `produktion_posten_entnehmen($pa_id, $posten, $trocken, $log)` (Posten tragen die kumulierte Soll-Menge; alles-oder-nichts; Ersatzartikel; `$trocken` = nur prüfen). Parameter `$bis` = bis zu wie vielen Packungen kumuliert Material verbraucht sein soll (null = ganzer Auftrag, `produktion_ziel_pakete()`). Vorher brach `produktion_rohstoffe_entnehmen()` ab, sobald irgendein Verbrauch existierte – nach einer Teilmenge wurde der Rest nie abgebucht (Phantombestand). Der Aufruf bleibt idempotent (zweiter Aufruf bucht nichts).

**Teilmenge mit Material- und Etikett-Prüfung (S-12):** `produktion_teilmenge_produzieren($pa_id,$menge,$mhd,$notiz)` ist der Weg für Teilmengen: prüft die Etikett-Freigabe (`produktion_etikett_sperre()`), prüft das Material ALLER Stationen des Auftrags per Trockenlauf (`produktion_station_entnahme(..., $trocken=true)`: Rohstoffe, Leerkapseln, Behälter, Deckel, Etikett), bucht es anteilig ab (Restbuchung) und bucht erst dann die Fertigware-Charge (`produktion_teilmenge_einbuchen`). Alles-oder-nichts mit Meldung, was fehlt. `produktion_teilmenge_machbar()` (Rechner „jetzt machbar") wird auf der Produktionsauftrag-Seite angezeigt. **Etikett-Freigabe = echte Sperre:** `produktion_schritt_erledigen()` lehnt jeden Schritt ohne Kundenfreigabe ab (Direkt-URL, Detailseite; Meldung statt stillem „ok"). God-Mode (nur Admin) bleibt bewusst der Override.

**Restbedarf – eine Wahrheit (St-08):** `auftrag_bedarf_rest($pa_id)` ist die Quelle für Auftrag (Material-Übersicht), Produktionsauftrag, `produktion_bereitschaft()`, `produktion_materialbedarf()` UND die Einkaufsliste (`bedarf_aggregiert()` rechnet jetzt mit dem Rest, nicht mehr mit dem Vollbedarf). Rest einer Position = das Kleinere aus (a) Vollbedarf × noch nicht produzierte Packungen/Gesamtmenge und (b) Vollbedarf − für dieses PA bereits verbrauchtes Material – eine Station, die ihr Material schon für den ganzen Auftrag abgebucht hat, wird nicht ein zweites Mal als Bedarf gerechnet. Die Sub-App spiegelt dieselbe Regel (`erp_materialbedarf`, `erp_rest_faktor`, `erp_pa_fehlbedarf`).

**Teilversand ab eingebuchter Teilcharge (S-14):** `auftrag_versenden_menge()` verschickt jetzt auch, solange die Produktion noch läuft (Auftragsstatus `offen`/`in_produktion`, nicht nur `erledigt`). Versendbar ist dann nur Fertigware aus der Produktion DIESES Auftrags (`auftrag_produzierte_fertigware()`: Chargen mit `pa_id` des Auftrags, frei, abzüglich schon Versendetem); die Ausbuchung läuft FEFO nur über diese Chargen, es entsteht ein Lieferschein (anteiliges Netto) und `versendet_menge` zählt mit – der Auftrag bleibt verknüpft. Der Status bleibt unverändert, bis die Produktion fertig ist; `auftrag_nach_produktion_status()` setzt ihn beim Abschluss auf `erledigt` – oder direkt auf `versendet`, wenn per Teilversand schon alles raus ist (Dashboard und Sub-App). Fulfillment-Übergabe an Lager 2 bleibt erst nach Produktionsabschluss.

**Fertigware gehört zum Auftrag (S-09):** `produktion_teilmenge_einbuchen()` (und `erp_teilmenge_einbuchen()` der Sub-App) setzen an der Fertigcharge `auftrag_id` zusätzlich zu `pa_id` – der Kunde ergibt sich über den Auftrag (`charge.fremd_kunde_id` bleibt dem Fremdlager vorbehalten). `auftrag_fertigware_chargen($aid, $nurFrei)` liefert die Fertigware-Chargen (Artikel `verkaufsfertig`) eines Auftrags per `auftrag_id` ODER – bei älteren Chargen ohne `auftrag_id` – über die `pa_id` seiner Produktionsaufträge. `auftrag_versenden_menge()`, `auftrag_ins_fremdlager()` und die Versand-Liste arbeiten NUR mit diesen Chargen, sobald der Auftrag eine Produktion/gebundene Charge hat (`auftrag_hat_fertigware_bindung()`); der gemeinsame Artikel-Topf bleibt nur für Aufträge ohne Produktion (Handelsware, Alt-Import). Fertigware-Chargen zählen nicht mehr als „Rohstoff angekommen".

**Gesamtpreis je Packung (S-04 intern, S-19):** `auftrag.vk_stueck` ist jetzt bei Positions- (`auftrag_aus_positionen`) und Zellen-Aufträgen (`auftrag_aus_zelle`) der GESAMTpreis je Packung (Herstellung + Glas + Deckel + Etikett = `gesamt_netto / menge`), nicht mehr nur die Herstellungszeile. Die Kundenpreisliste (`kundenpreis_setzen`) führt weiter den Herstellungspreis ohne Verpackung. Für ältere Aufträge liefert `auftrag_vk_gesamt($auftrag)` den Gesamtpreis aus `gesamt_netto/menge` (Rückfall `vk_stueck`) – überall dort verwenden, wo ein „Preis je Packung" angezeigt oder für die Nachbestellung gebraucht wird.

**Auto-Rechnung mit Positionen, Fälligkeit, Festschreiben bei Lieferung (St-04):** `auftrag_rechnung_automatisch()` (genutzt von `auftrag_aus_positionen` und `auftrag_aus_zelle`) erzeugt die Rechnung mit Produkt-USt (`produkt_ust_satz`), Zahlungsziel + Fälligkeit des Kunden (`kunde_zahlungsziel_tage`) und materialisierten POSITIONEN (Herstellung + Glas + Deckel + Etikett …) – vorher stand dort nur eine Kopfsumme, die PDF antwortete mit HTTP 409. Sie bleibt Entwurf (`kunde_sichtbar=0`). `auftrag_rechnung_bei_lieferung($aid, $voll)` (aufgerufen von `auftrag_versenden_menge` und `auftrag_ins_fremdlager`) schreibt bei Lieferung fest: Leistungsdatum = Lieferdatum, für den Kunden freigegeben (mit Rechnungsadresse), fehlende Positionen/Zahlungsziel/Fälligkeit werden bei älteren Rechnungen nachgezogen; Teillieferungen setzen nur ein fehlendes Leistungsdatum. Bezahlte/stornierte Rechnungen bleiben unberührt. `auftrag_aus_angebot` setzt zusätzlich Zahlungsziel/Fälligkeit.

## Pulver-Bulk in g statt Stk (St-13)
`produktion_fertigware_einheit($pa)` gibt die Einheit der zugekauften Bulkware nach Darreichungsform (Pulver/Granulat `g`, Flüssig/Gel `ml`, sonst `Stk`). `produktion_fertigware_entnehmen()` meldet Fehlmengen in dieser Einheit und bucht den Verbrauch in der Einheit der Charge (vorher immer „Stk", z. B. 600.000 Stk für 600 kg Pulver).

## Fremdfertigungspreis Pulver in kg mit Staffel (St-13)
`lieferant_angebot_speichern()` spiegelt den Preis einer Fremdfertigungs-Anfrage an einer Rezeptur nach `rezeptur_lief_angebot`. Bei Pulver/Granulat ist die Einheit jetzt **kg** (Flüssig/Gel **L**) statt „Stk"; ein Angebot je g/ml wird auf kg/L umgerechnet. Die **Staffel** wird mitgespiegelt: Grundzeile ohne Menge + je Staffel eine Zeile mit `menge` = ab-Menge (z. B. 600 kg → 14,90 €, 1.500 kg → 13,50 €). So greift die Staffel in `rezeptur_fremd_lieferant_preise()` (Bulk-Bestellung fragt in kg ab) und erscheint in der Einkaufs-Preisliste. Freigabe-Regel je Zeile wie bisher: neue Zeile sofort freigegeben, gleicher Preis nur „Stand", geänderter Preis = Vorschlag zur Freigabe. Gibt es (Altdaten) keine Grundzeile ohne Menge, wird wie bisher die erste Zeile des Lieferanten verglichen.

## Inhalt je Packung formgerecht (St-13)
`inhalt_je_packung_txt($form, $wert)` liefert „300 g je Packung" (Pulver), „250 ml je Packung", „60 Kapseln je Packung", sonst „60 Stk je Packung". `auftrag_darreichungsform($a)` liefert die Form eines Auftrags (Feld, sonst Rezeptur des Produkts, sonst `produkt_form`). Genutzt auf Lieferschein, interner AB (Rückfall-Position), Laufzettel, Kundenportal (Bestellung, Nachbestell-Karte, Rechnungs-Rückfall) und in der internen Nachbestell-Liste – vorher stand bei Pulver „300 Stück je Packung".

## Handelsware im Positions-Weg (S-15)
`auftrag_aus_positionen()` hat einen Handelsware-Zweig: Ist das Kopf-Produkt Handelsware, reicht eine Produkt-/Herstellungszeile mit Menge – eine Rezeptur ist nicht nötig. Produkt = Kopf-Produkt (bei passender Konfiguration), sonst eine Variante über `produkt_aus_rezeptur()`. Es entsteht Auftrag + Rechnung, **kein Produktionsauftrag** (auch wenn die Position eine Rezeptur trägt). `produkt_aus_rezeptur()` vererbt das Flag `handelsware` der Vorlage und verwendet nur Produkte mit gleichem Flag wieder.

## Handelsware ohne Produktionsauftrag: Beschaffung, Wareneingang, Versand (S-15)
- `bedarf_bulk()` nimmt Handelsware-Aufträge ohne PA (Status offen/in Produktion) als **Fertigware in Packungen** auf (`art='fertig'`, Bedarf = Menge − versendet, Bestand = verkaufsfertiger Artikel).
- `bestellung_bulk_anlegen()` und der Einkaufslisten-Weg legen für `art='fertig'` die Position **auf den verkaufsfertigen Artikel** an (`item_id` = `produkt_lageritem()`, Menge in Packungen, Bezeichnung „Fertigware: …", `auftrag_id`) – so bucht der Wareneingang (Dashboard und Lager-App) die Ware richtig und bindet sie an den Auftrag. EK je Packung über `produkt_lieferant_preise_pkg()` (Zukaufpreise je Packung; Zeilen je Kapsel/Stück × Einheiten je Packung).
- `auftrag_handelsware_ohne_pa($aid)`: Handelsware-Auftrag ohne PA in offen/in Produktion ist versandbereit, sobald Ware frei im Lager liegt – `auftrag_versenden_menge()` und `auftrag_ins_fremdlager()` akzeptieren ihn.
- `auftrag_zukauf_fertig($aid)`: Handelsware ODER PA mit Zukauf-Art „fertig".

## Zukauf-Art „fertig" wirkt (St-24)
Bei `produktionsauftrag.zukauf_art='fertig'` (fertige, abgefüllte Ware) – und bei Handelsware – gilt durchgängig **Packungen**:
- Bedarf: `bedarf_bulk()` (Einkaufsliste) und `auftrag_bedarf()` (Material am Auftrag) rechnen in Packungen auf dem verkaufsfertigen Artikel (vorher lose Einheiten, z. B. 30.000 Softgels statt 500 Pkg); Verpackung/Etikett zählen nicht.
- Preis: Zukaufpreise können **je Packung** (`einheit='packung'`) oder je Kapsel/Stück gepflegt werden. `produkt_lieferant_preise_pkg()` liefert € je Packung (Stückpreise × Einheiten je Packung), `produkt_lieferant_preise()` rechnet Packungspreise zurück auf € je Einheit. Bestellungen (Einkaufsliste, Express am Auftrag) bestellen Packungen auf den verkaufsfertigen Artikel zum Packungspreis.
- Produktion: „Fertigware bereitstellen" prüft bei „fertig" nur die am Auftrag gebundenen verkaufsfertigen Packungen (kein Verbrauch, `produktion_zukauf_fertig_chargen()`), „Verpacken" bucht keine Verpackung ab. **Einlagern** (`produktion_teilmenge_einbuchen()`) legt keine neue Charge an, sondern ordnet die Lieferanten-Chargen dem PA zu – **Charge-Nr. und MHD des Lieferanten bleiben** (vorher neue Charge mit MHD heute + 18 M. und doppeltem Bestand).
- Angebot: Handelsware-Position (`angebot_gruppe_positionen()`) mit EK = günstigster Zukaufpreis je Packung und VK = vereinbarter Kundenpreis (sonst EK + Marge), Artikelnummer = Produktnummer, ohne eigene Verpackungszeilen (vorher Preis 0, „~0mg").

## Versand-Mails (Paket Mails)
`auftrag_versenden_menge()` schickt nach jeder (Teil-)Lieferung `mail_kunde_versand()` (LS-Nummer, Menge, offener Rest, freigegebene Rechnung), `auftrag_ins_fremdlager()` schickt `mail_kunde_fremdlager()`. Beide nach der Antwort, nur wenn der Mailversand eingerichtet ist.

## Material komplett / Fehlbestand (Paket Mails)
- `material_komplett_pruefen($sofort)`: merkt sich in `app_meta material_wartet` die Produktionsaufträge, die auf Material warten; ist einer davon beim nächsten Lauf bereit, geht EINMAL „Material komplett" ans Team. Beim ersten Lauf wird nur gemerkt (keine Meldung für schon bereite). Läuft nach Wareneingang/Quarantäne-Freigabe im Dashboard (`$sofort`) und gedrosselt (alle 5 Min.) beim Öffnen der Produktionsübersicht – so werden auch Buchungen aus der Lager-App erfasst.
- `produktion_schritt_erledigen()`: scheitert ein Schritt an Material, geht `mail_team_fehlbestand()` raus.
- `lieferant_bewerbung_anlegen()` gibt zusätzlich `id` zurück.

## Ersteller der Preisanfrage (Paket Mails)
`lieferant_anfrage.angelegt_von_id` (neu, additiv) speichert, wer die Preisanfrage gestellt hat – die Antwort-Mail des Lieferanten geht an diese Person.

## Neuinstallation (Paket Neuinstallation & Rechte)
- St-01: Der CH/CHE-Seed (Nummernkreise Produktionscharge) steht direkt hinter `CREATE TABLE nummernkreis` – vorher lief er auf einer frischen DB gegen die fehlende Tabelle, `init_schema()` brach ab und setzte den Marker nie. `seed_etikett_formate()` läuft als letzter Schritt vor dem Marker (braucht alle Tabellen).
- St-02: `ensure_column()` auf eine (noch) fehlende Tabelle merkt sich die Lücke (`$GLOBALS[bx_schema_luecken]`); `init_schema()` läuft dann EINMAL sofort erneut statt den Marker zu setzen – so kommen auf einer frischen DB auch die Spalten dazu, deren Migration vor dem CREATE TABLE steht (vorher 27 fehlende Spalten, u. a. dokument.kunde_sichtbar, portal_anfrage.rezeptur_id, kapselgroesse.fuell_*_mg). Was danach noch fehlt, steht in `app_meta schema_luecken`. `nachbestellung_schema()` läuft jetzt in `init_schema()` mit (das Portal las die Tabelle, bevor sie angelegt war).

## Zeitzone (St-03)
Alle `*/core/db.php` setzen nach dem Verbindungsaufbau `SET time_zone = '+00:00'` – NOW()/CURRENT_TIMESTAMP schreiben damit UTC wie PHP. Gedruckte Zeiten (Laufzettel „gedruckt", Lager-Lieferschein „Erstellt am", KI-Entwurf in der Anfrage, Kontoauszug-Import) laufen über `fmt_zeit()` (Europe/Berlin) statt `date()` (UTC).

## QM-Gate Fertigware (St-11d)
Eigene Fertigware (Einlagern aus der Produktion: `produktion_teilmenge_einbuchen()`, Produktions-App `erp_teilmenge_einbuchen()`, Easy Production außer „schon geliefert") kommt in **Quarantäne** statt frei. Versand, Fremdlager-Übergabe und Weiterverarbeitung nehmen nur freie Chargen, also erst nach der QM-Freigabe (Lager-App „Quarantäne" / Dashboard-Wareneingang, nur QM/Admin). Die Versand-/Fremdlager-Meldung nennt die wartende Menge (`fertigware_qm_hinweis()`), die Bulk-Reservierung einer nicht freien Charge warnt nur, und die Lager-App lässt Fertigware in Quarantäne nicht entnehmen. Zukauf „fertig" behält den Status vom Wareneingang.
## Fremdlager: Kundenware gehört dem Kunden (S-16)
- `lager2_besitzer_item($item)`: Besitzer eines Lager-2-Artikels (Kunde am Produkt, sonst Besitzer vorhandener Fremdlager-Chargen, sonst Fulfillment-Kunde des jüngsten Auftrags).
- `lager2_einbuchen()` und die ds_api-Retoure (`lager2_retoure()`) setzen `fremd_kunde_id` = Besitzer und `standort='lager2'` (vorher eigener Bestand in Lager 1 und gleichzeitig im Portal = doppelt). Ohne Besitzer bucht `lager2_einbuchen()` nicht.
- `lager2_verbrauch()` (Shop-Abbau) bucht FEFO **nach Besitzer**: zuerst die Chargen des Kunden, nur danach Altbestand ohne Besitzer; Ware anderer Kunden nie.
- `auftrag_ins_fremdlager()` überträgt die Fertigware des Auftrags per `fertigware_an_kunde_uebertragen()` an den Kunden (je Quell-Charge Fremdlager-Charge mit gleicher Charge-Nr./MHD/PA, Lager 2) – vorher blieb sie unser Lager-1-Bestand und zählte zusätzlich im Lager 2; unser Versand hätte sie ausliefern können.
- `fremdlager_umbuchen()`: nur für Fulfillment-Kunden, Standort Lager 2.
- Eigener Versand (`auftrag_versenden_menge`, FEFO `fremd_kunde_id IS NULL`) greift damit nie auf Kundenware zu.
- Übergang: `lager2_bestand()` zählt weiter alle freien Chargen des Artikels (auch Altbestand ohne Besitzer), damit „Mein Lager" für bestehende Kunden nicht einbricht. Altbestand ohne Besitzer bitte per Migration zuordnen (SQL in der Rückmeldung).

## Kette-Nachtrag (Live-Gang Etiketten/Teilproduktion/Fertigware)
- `auftrag_annahme_etikett_verpackung()` lässt **Handelsware** aus (Ware ist schon etikettiert): kein Kundenetikett, kein bedruckter Behälter.
- Zukauf „fertig": Verpacken und Etikettieren buchen nichts ab (`produktion_ist_zukauf_fertig()` im Dispatcher `produktion_station_entnahme()`, Spiegel in der Sub-App).
- Versand-Meldung: liegt die Fertigware des Auftrags noch in Quarantäne, heißt es „Die Fertigware ist noch nicht freigegeben" plus wartende Menge.
- Rechnung: Teilversand während der Produktion schreibt die Rechnung erst beim Produktionsabschluss als komplett fest (`auftrag_nach_produktion_status()`).
- Migration Kundenetiketten schreibt ein Protokoll jeder Änderung nach `app_meta migr_kundenetikett_protokoll` (JSON, max. 300 Zeilen).

## Teillieferung (Nacht 1c)
`bestellung_position.geliefert_menge` (neu, additiv) zählt, was je Position schon eingebucht ist. Der Wareneingang mit Bestellbezug ruft `bestellung_lieferung_erfassen($bestellung_id, $charge_ids)` (Lager-App: `erp_bestellung_lieferung_erfassen`): die Chargen werden auf die Positionen verteilt (gleicher Artikel, sonst Freitext-Position; g/kg und ml/l werden umgerechnet). **Geschlossen** (angekommen_am, Status geliefert) wird die Bestellung erst, wenn alle Positionen zu mind. 98 % geliefert sind. Vorher bleibt sie offen, und „unterwegs" zählt nur den Rest (`bp_rest_sql()`, auch in `bulk_position_basis_sql()`), es entsteht also kein doppelter Bedarf. Der Knopf „liefern" (`bestellung_wareneingang`) setzt alle Positionen voll.

## Wareneingang → Portal-Status (Nacht 4b)
Neue letzte Station **„Wareneingang bei bulkify"** (`eingegangen`, de/en/zh). Sie setzt nur der Wareneingang: beim vollständigen Einbuchen einer Lieferanten-Bestellung (Dashboard, Lager-App, Knopf „liefern"), nicht der Lieferant per Knopf. Bei einer Teillieferung steht im Ablauf „Teillieferung eingegangen: Datum" (`bestellung.teilgeliefert_am`). **Keine Mail an den Lieferanten.** Das Team bekommt eine Dashboard-Benachrichtigung „Lieferung eingebucht: BE-… (Firma)", bei einer Teillieferung mit Anzahl offener Positionen (`bestellung_eingang_melden()`).

## Zukauf „fertig" – kurzer Weg (Nacht 2a)
Bei Zukauf-Art „fertig" hat der Produktionsauftrag nur noch **Fertigware bereitstellen → Qualitätsprüfung → Produktions-Freigabe → Versand-Freigabe** (kein Verpacken/Etikettieren/Beipack/Umkarton, die Ware ist fertig). `produktion_schritte_regenerieren()` beachtet `zukauf_art`, `produktion_zukauf_weg_anpassen()` passt beim Umstellen der Zukauf-Art die Schritte an (noch nichts erledigt: neu erzeugen, Status bleibt; sonst nur offene Ausbau-Schritte entfernen). Prod-App: `erp_weg_stationen()` ebenso.

## EK aus dem Zukaufpreis (Nacht 2b/3, 13a)
- `rezeptur_zukauf_ek_packung($rid, $stueck, $packungen)`: Liegt ein Bulk-/Zukaufpreis vor, ist er der EK der Füllung. Pulver/Granulat/Flüssig/Gel nehmen `rezeptur_lief_angebot` in €/kg bzw. €/l, z. B. 14,90 €/kg × 300 g = 4,47 €/Pkg (Altdaten mit Einheit „Stk" gelten bei Pulver als €/kg). Stück-Formen nehmen den Fremdfertigungspreis je Stück. Er geht in `produkt_variante_ek()` (Preismatrix) und `rezeptur_herstell_ek_pro_packung()` (Rezeptur-Position) **vor** der Rohstoff-Summe.
- `rezeptur_ek_quelle()`: „zukauf" = EK aus dem Zukaufpreis. „schaetzung" = die Ware soll zugekauft werden (Handelsware-Produkt zur Rezeptur oder offene Preisanfrage an einen Lieferanten), es gibt aber noch keinen Zukaufpreis: Das Angebot markiert die Position rot „EK = Rohstoff-Schätzung, Lieferant-Bulkpreis fehlt" und zeigt einen Hinweis, den VK erst nach dem Lieferantenpreis festzulegen. „rohstoff" = Eigenproduktion, unverändert.
- Handelsware über eine Rezeptur-Position (`angebot_rezeptur_zeilen`): Gibt es zu Rezeptur + Inhalt ein Handelsware-Produkt mit Zukaufpreis je Packung, ist das der EK, und es gibt keine eigenen Verpackungszeilen (wie beim Produkt-Weg).

## QM-Gate Schritte (St-11a)
Die Schritte **Qualitätsprüfung, Produktions-Freigabe, Versand-Freigabe** schließt nur QM (Rolle `labor`) oder Admin ab: Dashboard `produktion_schritt_erledigen()` (`produktion_qm_stationen()`, `darf_qm_freigeben()`), Prod-App/Werk-App `erp_schritt_abschliessen($id, $akteur, $uid)` (`erp_darf_qm()`). Die Produktion bekommt „… schließt QM ab (Vier-Augen-Prinzip)", in der Werk-App ist der Knopf gesperrt. Auch die QS-Freigabe in der Prod-App (`?p=qs`) und ihr Zurückziehen sind nur für QM/Admin. Admin-Overrides/God-Mode bleiben. Noch nicht gebaut: echtes Personen-Vier-Augen (b).

## Feld-Änderungsprotokoll (Aufräumen 8)
Tabelle `aenderung_log` (objekt_typ, objekt_id, feld, alt, neu, benutzer, route, erstellt). `public/index.php` ruft bei jedem POST `aenderung_beobachten($route, $id)` auf: Der Hauptdatensatz der Seite (`aenderung_routen()`: Auftrag, Angebot, Produkt, Rezeptur, Kunde, Lieferant, Bestellung, Artikel, Produktionsauftrag, Kontingent, Dienstleistung, Benutzer) wird vorher gelesen und nach der Antwort (auch nach Redirect) verglichen. Jedes geänderte Feld wird mit alt/neu, Person und Zeit protokolliert (`aenderung_protokollieren()`). Technische und geheime Felder (Zeitstempel, Token, Passwort-Hash, Caches) sind ausgenommen. Anzeige: `?p=protokoll&ansicht=felder`, filterbar nach Bereich, Objekt-ID und Text. Positionen und Untertabellen werden nicht erfasst.

## Nummernvergabe atomar (Aufräumen 16)
`naechste_nummer()` zählt hoch und liest unter `GET_LOCK(bx_nr_<Präfix>)`. Vorher konnten parallele Aufrufe dieselbe Nummer bekommen: Test mit 3 Prozessen × 40 Nummern ergab 93 von 120 eindeutig, nachher 120 von 120. Gilt für alle Nummernkreise (AN, AB, RE, LS, BE, VP …). Die Kopien in Buchhaltung, Lager und Produktion nutzen dieselbe Sperre.

## Kundenetikett mit Artikelnummer (Aufräumen 17)
`kundenetikett_version_anlegen()` vergibt eine Artikelnummer (VP-…) wie jeder Verpackungsartikel. Vorher blieb das Kundenetikett ohne Nummer. Bestehende Kundenetiketten ohne Nummer stehen in der SELECT-Liste für Nico.

**Beutel-Typen (Nico 03.11.2026):** Standbodenbeutel **vollbedruckt = Standard** (eigener bedruckter Beutel je Produkt + Kunde,
**kein** Etikett), **Blanko + Etikett = Ausnahme** (`angebot_position.blanko_etikett=1`; neutraler Beutel + Kundenetikett).
`annahme_vollbedruckt()` entscheidet im Annahme-Hook: Beutel -> vollbedruckt außer blanko gewählt; ohne Verpackungs-Position
(Matrix, Nachbestellung) wie der letzte Auftrag des Kunden für das Produkt, sonst Standard; andere Behälter nur bei
`kundenspezifisch=1`. Bedruckter Behälter = der Druck ersetzt das Etikett: `verpackung_ist_bedruckt()` / `auftrag_vollbedruckt()`
-> kein Kundenetikett, `auftrag_braucht_etikett()` = nein (keine Freigabe-Sperre, kein Etikett im Portal), kein Etikett im
Bedarf/Einkauf, `produktion_etikett_entnehmen()` bucht nichts. Der Schritt „Etikettieren“ bleibt (Charge/MHD-Kennzeichnung).

**Beutel-Design-Freigabe (Nico 03.11.2026):** Der vollbedruckte Beutel braucht wie das Etikett die Design-Freigabe des
Kunden VOR der Produktion. `auftrag_braucht_etikett()` = „braucht Design-Freigabe“ (auch vollbedruckt), derselbe Ablauf
(Upload + Freigabe am Auftrag/Portal, `produktion_etikett_sperre()`, Teilmenge 0 bis zur Freigabe). Material bleibt getrennt:
vollbedruckt = kein Kundenetikett/kein Etikett im Bedarf (`auftrag_vollbedruckt()`). `auftrag_design_label()` liefert
„Etikett“ bzw. „Beutel-Design“ für die Texte. `kundenetikett_nach_freigabe()` hängt das freigegebene Design bei vollbedruckt
an den Beutel (`item.etikett_dokument_id`/`etikett_datei_sig`) statt eine Etikett-Version anzulegen. Einkauf: der bedruckte
Beutel (`bedarf_aggregiert()['druck']`) ist erst bestellbar, wenn alle Aufträge mit ihm freigegeben sind.

`produkt_verpackung_items()`: bei bedrucktem Behälter (vollbedruckter Beutel) keine Etikett-Position – auch nicht automatisch aus dem Behälter abgeleitet (03.11.2026).

`produkt_verpackung_items()`: steht im Etikett-Slot ein **Kundenetikett** (Lagerartikel je Produkt + Kunde, ohne eigenen Preis – evtl. eines anderen Kunden), wird für Preis, Angebotspositionen und PPWR das neutrale **Maß-Etikett** genommen (`etikett_vorlage_id`, sonst `etikett_id_fuer_behaelter()`). Vorher wurde das Etikett dann mit 0 € gerechnet (03.11.2026).

**QM-Freigabe Fertigware -> Dokumente für den Kunden (Nico 03.11.2026):** `fertigware_qm_freigabe_nach($charge_id, $wer)`
läuft, wenn QM eine verkaufsfertige Charge (mit pa_id) aus der Quarantäne freigibt: setzt `charge.qm_freigabe_am/_von`, gibt den
Produktionsbericht frei (`produktionsauftrag.bericht_freigegeben_*`, außer `bericht_zurueckgehalten=1`) und erzeugt – wenn
`app_meta coa_uebersicht_auto=1` – das zusammenfassende CoA (`core/coa_uebersicht.php`, einmal je Charge, nicht bei gemessenem CoA).
Aufrufer: module/lager/wareneingang.php (Dashboard-Quarantäne) und `?p=api_qm_freigabe` (Lager-App). Wirft nie.

**Versand für den Kunden (Report 4, 03.11.2026):** `beleg.versand_tracking`/`versand_dienst` (Lieferschein), `versand_dienste()`,
`versand_tracking_url()` (DHL/DPD/UPS/GLS; Spedition ohne Link), `auftrag_sendungen($aid)` = Nummern am Lieferschein + Sendungen
der Lager-App (`lg_versand` über Positionen mit Fertigware-Chargen des Auftrags). `auftrag.liefertermin_von/_bis` +
`auftrag_liefertermin_text()` (Zeitraum, sonst „Produktion geplant ab …“ aus `produktionsauftrag.geplant_am`).
`auftrag_versenden_menge()` gibt zusätzlich `ls_id` zurück.

`portal_liefertermin_aktiv()`: zentraler Schalter (app_meta portal_liefertermin, Standard 0) für die Termin-Anzeige im Portal.

`produkt_kapselhuelle_mg($produkt_id)`: Hüllengewicht wie im PIB (Leerkapsel-Artikel am Produkt, sonst Standard der Kapselgröße).

`zertifikat_arten()`, `zertifikate($nurSichtbar)`: globale Herstellerzertifikate (dokument objekt_typ='firma', typ='zertifikat').

**Rückfragen + Ansprechpartner (Report 5, 03.11.2026):** Tabelle `kunde_nachricht` (Selbst-Schema `kunde_nachricht_schema()`;
kunde_id, auftrag_id ODER angebot_id, akteur kunde|team, text, benutzer, erstellt UTC, gelesen_team/_kunde). `kunde_nachricht_senden()`,
`kunde_nachrichten()`, `kunde_nachricht_gelesen()`. Ansprechpartner: `kunden.betreuer_benutzer_id` (je Kunde) sonst
`app_meta portal_ansprechpartner_id` (Standard) -> `portal_ansprechpartner($k)` liefert name/position/telefon/email aus `benutzer`.

## Pulver: Tagesdosen nur als Info (03.11.2026)
`form_ist_tagesdosis($form)` (Pulver/Granulat), `tagesdosen_je_packung($g, $dosis_mg)` (abgerundet) und `tagesdosen_txt($g, $dosis_mg, $ca='≈')` („≈ 60 Tagesdosen à 5 g“). Regel (Nico): Die Rezeptur ist eine Tagesdosis, der Kunde gibt die g je Packung an. Die Tagesdosen werden nur angezeigt; gerechnet wird nach Gewicht (Füllmenge × Massenanteil).

`pulver_ist_portionszahl($rid, $alt, $neu)` und `pulver_auftrag_fuellmenge_korrigieren($auftrag_id, $alt, $neu, $route)`: Pulver-Altdaten (v3, Portionen statt g) erkennen bzw. Auftrag + PA umstellen (guarded, Protokoll, `bedarf_bump`). Genutzt von Kundenpreis-Liste und Datenprüfung.

`kundenpreis_setzen()` (03.11.2026): Schlüssel = Produkt + Kunde + Menge je Packung + Bestellmenge (`COALESCE(anzahl_vpe,0)`), also Staffelpreise. 0/leer = Preis für alle Mengen.

## Kundenetikett je Auftrag sicherstellen (03.11.2026)
`auftrag_kundenetikett_sicherstellen($auftrag_id)` legt für Alt-/v3-/Import-/Kontingent-Aufträge ohne Annahme-Hook das Kundenetikett (je Produkt + Kunde) an:
- v1 mit Maßen aus dem Glas; ein freigegebenes Design des Auftrags geht mit (`kundenetikett_nach_freigabe`).
- Offene Aufträge werden gebunden (`etikett_item_id`, protokolliert). Gelieferte nur, wenn sie noch gar kein Kundenetikett tragen.
- Nicht bei Handelsware, Bulk ohne Behälter oder vollbedrucktem Behälter.

Aufgerufen in `auftrag_aus_import()` und `kontingent_abruf()` (dort fehlte der Hook) sowie über die Datenprüfung `kundenetikett_fehlt`. Die Kopie `auftrag_aus_import()` in `buchhaltung/core/erp.php` darf core/schema.php nicht laden; diese Aufträge fängt die Datenprüfung.

## Produktname zieht den Behälter mit (03.11.2026)
`produkt_name_behaelter_nachziehen($pid, $alt_vp, $neu_vp, $route)`: Der Name trägt den Behälter seit dem Anlegen („… · 100 ml Glas“). Wechselt der Behälter-Slot, wird der Namensteil „ · <alter Behälter>“ durch den neuen ersetzt.
- Die Version wird neu bestimmt; der alte Name wird Synonym; Feld-Protokoll.
- Nicht bei bedrucktem Kundenbehälter (Blocker 1).
- Aufgerufen in der Produktseite (Behälter geändert, Name nicht von Hand geändert), in der Vorbereitung „als Standard“ und in der Datenprüfung `produkt_name_behaelter`.
- Zieht danach die Auftrags-Namen dieses Produkts mit, die im neuen Behälter laufen (Auftrag ohne eigenen Behälter oder mit dem neuen).

`auftrag_name_behaelter_nachziehen($aid, $route)` (03.11.2026, AB 590): Der Auftrag speichert den Produktnamen als Snapshot (`produkt_bezeichnung`). Nennt er einen anderen Primärbehälter als der Behälter des Auftrags (`auftrag.verpackung_id`, sonst Produkt), wird nur der Behälter-Teil getauscht (längster Treffer, ggf. vor „ vN“) und im aenderung_log protokolliert. Bedruckte Kundenbehälter: nichts. Aufgerufen in der Auftragsseite (Verpackung speichern), Vorbereitung (Glas nur für diesen Auftrag), `produkt_name_behaelter_nachziehen` und der Datenprüfung `auftrag_name_behaelter`.

`item_bestellungen($item_id, $auftrag_id, $freitext)` (03.11.2026): Bestellungen zu einem Artikel für die Material-Übersicht am Auftrag. Enthalten sind laufende Bestellungen (Entwurf, gesendet, bestellt, noch nicht eingegangen) und eingegangene, die an diesen Auftrag gebunden sind. Mit `$freitext` kommen Positionen ohne Artikel mit `auftrag_id` dazu (Bulk-Zukauf). Liefert Nummer, Lieferant oder Online-Händler, offene Menge, `bestellung_stufe`, Termin bzw. Eingangsdatum und `fuer_auftrag`. Nur lesend.

## Storno raus aus Produktion/Einkauf (03.11.2026)
- `pa_aktiv_sql()`: SQL-Bedingung (Alias `pa`). Nicht storniert und Auftrag nicht storniert. Eingesetzt in `bedarf_aggregiert`, der Fremd-Bulk-Gruppierung, `material_komplett_pruefen` und `vorbereitung_liste`, außerdem in Produktionsliste, Cockpit, Startseite, Planung, Run, Kalender, Einkauf-Bedarf und Auftragsliste („wartet auf Freigabe“).
- `pa_storno_nachziehen($pa_id, $route)`: Produktionsauftrag eines stornierten Auftrags stornieren, nur ohne erledigten Schritt. Reservierungen werden frei, die Änderung wird protokolliert und der Bedarf neu gerechnet. Bestellungen beim Lieferanten bleiben unverändert. Wird von der Datenprüfung `pa_auftrag_storniert` genutzt.
- `produktion_material_bestellt($fehlend, $auftrag_id)`: true, wenn jede fehlende Position eine laufende Bestellung hat (über `item_bestellungen`, Bulk auch als Freitext). Liefert dazu den spätesten Termin. `bereitschaft_badge('bestellt')` zeigt „Material bestellt“.

## Bulk-Zukauf: Quarantäne und „liefern“ (04.11.2026, AB 542)
- `auftrag_fertigware_cached()` liefert zusätzlich `quar` (Bulk-Chargen am Auftrag in Quarantäne). `auftrag_bedarf()` gibt Fertigware-Zeilen ohne item_id damit `quarantaene` mit (bei Packungen umgerechnet); `zu_bestellen` rechnet sie ab.
- `bestellung_freitext_positionen($id)`: Anzahl Positionen ohne Lagerartikel. `bestellung_wareneingang()` (Knopf „liefern“) tut bei solchen Bestellungen nichts mehr. Früher übersprang es die Bulk-Freitext-Position und schloss die Bestellung trotzdem als geliefert, dann stand sie auf „angekommen“ ohne Charge am Auftrag.

## Wareneingang ohne Quarantäne (04.11.2026)
- `wareneingang_mit_quarantaene()`: Schalter `app_meta wareneingang_quarantaene` (Standard 0). Aus = `wareneingang_buchen()` bucht alles sofort frei, an = Rohstoffe/Bulk/Fertigware in Quarantäne wie bisher.
- `charge_quarantaene_freigeben($id)`: eine Charge aus der Quarantäne freigeben, inklusive `fertigware_qm_freigabe_nach()`. Genutzt von Wareneingang (Dashboard) und Lager → Freigaben. `bedarf_bump()` und `material_komplett_pruefen()` macht der Aufrufer.
- Eigene Fertigware aus der Produktion kommt weiter immer in Quarantäne (Produktionsbericht und CoA hängen an der QM-Freigabe).

## Ware da, aber nicht zugeordnet (04.11.2026, PA 54)
- `auftrag_bulk_diagnose($aid)`: Bei Bulk-Zukauf (Fertigware ohne item_id fehlt) sucht sie Ware, die da ist, aber nicht zählt: als Rohstoff gebuchte Chargen am Auftrag oder mit der Auftragsnummer in der Notiz (`falsche_art`), Bulk-Chargen am Auftrag in Quarantäne, freie Chargen des Bulk-Artikels ohne Auftrag (`ohne_auftrag`). Liefert dazu `fehlt` (in Charge-Einheit) und `bulk_item`.
- `auftrag_bulk_diagnose_aktion($aid, 'umhaengen'|'binden', $charge_id)`: Umhängen setzt `item_id` auf den Bulk-Artikel und `auftrag_id`, nur bei gleicher Einheit (`einheit_gleich`), protokolliert (Route bulk_diagnose). Binden nutzt `charge_auftrag_zuweisen()` mit dem Restbedarf; der Rest der Charge bleibt frei.
- `auftrag_bulk_diagnose_block($aid, $zurueck)`: HTML-Block „Ware da, aber nicht zugeordnet“, Formulare an `?p=auftrag&id=…` (aktion `bulk_diag`), nur Admin.

## Bulkware im Lager = da (04.11.2026, Nico)
Ablauf: bestellen → Ware kommt an = eingebucht, sofort frei → im System als vorhanden geführt → im Auftrag (Reiter Material, „Bulkware binden“) aus dem Bestand zuordnen.
- `auftrag_bulk_lager_frei($aid)`: freie, keinem Auftrag zugeordnete Chargen des Rezeptur-Bulk-Artikels minus Reservierungen anderer Aufträge (gecacht `bl:`).
- `auftrag_bedarf()` Bulk-Zukauf: verfügbar = zugeordnete Bulk-Chargen + `auftrag_bulk_lager_frei()`; Zeile trägt `zugeordnet`. Vorher zählte nur Zugeordnetes, freie Bulkware im Lager stand als „Fehlbestand“. Gilt damit auch für Produktionsfreigabe, Produktionsliste und Bereitschaft. Die Werk-App nahm schon vorher den Bestand des Rezeptur-Bulk-Artikels.
- `bedarf_bulk()` (Einkauf): Bestand für lose Bulkware = zugeordnete Bulk-Chargen der Aufträge + freie Chargen des Bulk-Artikels minus Reservierungen anderer Aufträge. Vorher zählte nur verkaufsfertige Ware, der Einkauf schlug vorhandene Bulkware zum Bestellen vor.

## Leerkapseln erkennen (04.11.2026)
- `kapselgroesse_aus_name($name)`: „Gr. 0“, „Größe 00“, „Grösse 1“, „Size 2“, „#000“ → `kapselgroesse.id` (Stammdaten „Größe X“), sonst 0.
- `name_ist_leerkapsel($name)`: Leerkapsel/Kapselhülle, Kapsel mit HPMC/Gelatine/Pullulan oder Kapsel/Capsule mit Größenangabe. Genutzt von Rohstoff-Seite und Datenprüfung.

## Soll-Kette 2a: Wareneingang frei (04.11.2026)
`wareneingang_buchen(..., bool $problem = false)`: Quarantäne nur bei `$problem` (bewusst gewählt: Beschädigung, Abweichung) oder wenn der Schalter `wareneingang_quarantaene` an ist.

## Soll-Kette 2d: Kapselgröße an der Charge (04.11.2026)
- Spalte `charge.kapselgroesse_id`. Abgeleitet, nicht im Lager gemessen: Leerkapsel → Artikel; Bulkware → Rezeptur des Auftrags, sonst die eine Größe aller Rezepturen am Bulk-Artikel (Stamm + Varianten). Nicht eindeutig → leer (Wareneingang fragt, Datenprüfung listet).
- `rezeptur_kapselgroesse_id()`, `auftrag_kapselgroesse_id()`, `charge_kapselgroesse_ermitteln()`, `charge_kapselgroesse_setzen()`, `kapselgroesse_name()`. `wareneingang_buchen()` setzt die Größe.
- `auftrag_lagerware_quellen()` liefert je Charge `kapselgroesse` + `passt`, passende zuerst (Rohdaten: `auftrag_lagerware_quellen_roh()`). `charge_auftrag_zuweisen()` warnt bei anderer Größe; die abgespaltene Teilmenge erbt die Größe.

Soll-Kette 2e (04.11.2026): `produktionsschritte_fuer()` ohne Schritt „Produktions-Freigabe“ (Zukauf und Eigen). Startbar = Material da, gesteuert über den Auftrag. Alte offene Schritte: Datenprüfung `pa_schritt_produktionsfreigabe`.

`item_zusammenfuehren($quelle, $ziel)` (04.11.2026): Chargen + Reservierungen aufs Ziel, Quelle deaktiviert (nicht gelöscht), gleiche Einheit Pflicht. Genutzt von Rohstoff-Seite und Datenprüfung `bulk_artikel_lose`.

## Soll-Kette 1: Beschaffungsweg (04.11.2026)
- `produktionsauftrag.beschaffungsweg`: kapsel | premix | rohstoffe, festgelegt am Auftrag. `beschaffungswege()`, `pa_beschaffungsweg()` (alt: aus Eigen/Fremd abgeleitet), `auftrag_aktiver_pa()`.
- `auftrag_beschaffungswege($aid)`: Mengen und Kosten je Weg für diesen Auftrag. Fertige Kapseln: Fremdfertigungs-Angebote je Stk (`rezeptur_lief_angebot`, Einheit nicht kg) + Zukaufpreise am Produkt. Premix: Angebote je kg (`rezeptur_lief_angebot` mit Einheit kg oder `lieferant_preis` am Premix-Artikel). Einzelrohstoffe: Rezeptur × Menge mit Staffelpreis. Bei Premix und Rohstoffen kommen die Leerkapseln dazu. Kein Preis → nur die Menge. Jede Position (auch die Leerkapsel-Zeile, über `produkt_leerkapsel_id`) trägt `item_id` für den Link zur Rohstoffseite. Ist die Leerkapsel nicht eindeutig, bekommt die Zeile `hinweis` + `link`: mehrere Kapseln der Größe → Produkt (dort wählen), keine Kapsel der Größe → Liste Leerkapseln, Kapselgröße fehlt → Rezeptur.
- `auftrag_beschaffungsweg_setzen()`: Eigen/Fremd + Schritte; PA in Vorbereitung wird dabei freigegeben (Teil 4). Nur ohne erledigten Schritt.
- `auftrag_beschaffung_bestellen()`: Bestellung als Entwurf beim gewählten Lieferanten (Kapsel: Freitext-Bulk-Position; Premix: Premix-Artikel in kg), Position am Auftrag, Bedarf gemeldet.
- Premix: `rezeptur_premixitem()` (Rohstoff, Form premix, an der Stammrezeptur, kg). `produktion_materialbedarf()` ersetzt bei Weg premix die Zutaten durch den Premix-Artikel (Parameter `$ohnePremix` für die Kostenrechnung).

Soll-Kette 4a (04.11.2026): Spalte `produktionsauftrag.menge_vor_etikett`. `produktion_schritt_erledigen()`: Etikett-Freigabe sperrt nur noch das Etikettieren; Vorbereitung meldet „kein Beschaffungsweg festgelegt“.

## Soll-Kette 4b: Anweisung „Teilmenge produzieren“ (04.11.2026)
`aufgabe.menge` / `aufgabe.umfang` (komplett | vor_etikett). `pa_teilmenge_anweisen()` legt die Aufgabe (ref_typ teilmenge) an und hebt die PA-Priorität; `pa_teilmenge_anweisungen()`; `teilmenge_anweisung_verrechnen()` zieht gemeldete Mengen ab („komplett“ deckt auch „bis vor dem Etikett“) – auch aus `produktion_teilmenge_produzieren()`.

## Soll-Kette 5: Fertigware sofort frei (04.11.2026, Nico „ok“)
`produktion_teilmenge_einbuchen()` bucht eigene Fertigware **frei** (kein QM-Gate mehr) und ruft `fertigware_qm_freigabe_nach($cid, '', $mitBericht, 'Produktionsabschluss')`: CoA-Stempel/Informationsdokument je Charge sofort, Produktionsbericht erst, wenn die ganze Menge produziert ist. `fertigware_qm_freigabe_nach()` hat dafür `$mitBericht` und `$anlass`.

Soll-Kette 6 (04.11.2026): Spalten `produktionsauftrag.karton_stueck`, `karton_menge`, `karton_charge`, `karton_mhd` (Werte des letzten Kartonetiketten-Drucks).

Soll-Kette 7 (04.11.2026): Spalte `auftrag.versandart` (paket | palette | abholung | lager2) – legt das Lager fest, wenn der Auftrag versandbereit ist.

Gewicht & Bestand pro Gebinde (04.11.2026): `charge_gebinde.gewogen` (1 = echtes Gewicht erfasst, 0 = gleichmäßig verteilt/Altbestand) und `gewogen_am`. `produktion_rollen_abbuchen()` bucht jetzt alle Gebinde (nicht nur Etikettenrollen) mit ab – in die Produktion gescannte zuerst. Neu `charge_gebinde_abgleich($charge_id)`: zieht die Gebinde an `charge.menge_verfuegbar` an (weniger: Produktion, angebrochen, dann voll; mehr: aufs erste davon) – Grundsatz Charge = Summe der Gebinde.

## Labor-Auftragsformular (05.11.2026)
`lieferanten` bekommt `labor_formular`, `labor_standorte`, `labor_angebotsnr`, `labor_erste_proben`, `labor_bericht_an` (bulkify|kunde) und `labor_umfang`. Neue Tabelle `labor_versand` mit einer Zeile je erzeugtem Formular: Labor, Standort, Kunde, Datum, Bemerkung, Proben als JSON, Datei. Logik: [labor_auftrag.md](labor_auftrag.md).

## Beschaffungsweg: Bestand & offene Bestellungen koppeln (05.11.2026)
- `auftrag_deckung($aid)` stellt den Bedarf (Stück bzw. g/ml) gegen das, was schon da ist.
  - **gekoppelt:**
    - fest zugeordnete Bulk-Chargen (`charge.auftrag_id`),
    - aktive Reservierungen auf Bestellposition/Charge des Bulk- oder Premix-Artikels (nach dem Wareneingang nur, soweit die Ware noch frei ohne Auftrag liegt),
    - Bestellpositionen genau für diesen Auftrag (Bulk-Freitext bzw. Artikel ohne Reservierung, noch nicht geliefert).
  - **lager:** freie Chargen ohne Auftrag, abzüglich Reservierungen.
  - **bestellt:** offene, nicht gelieferte Positionen des Bulk-/Premix-Artikels ohne Auftrag, abzüglich Reservierungen.
  - Premix in kg wird über das Rezepturgewicht in Einheiten umgerechnet.
  - `rest` = Bedarf − gekoppelt, `rest_offen` = Rest − Lager − bestellt.
- `auftrag_deckung_koppeln($aid, 'charge'|'bestellung', $id, $menge)`:
  - Bulk-Charge → `charge_auftrag_zuweisen` (Teilmenge = Kind-Charge).
  - Bestellposition → `bulk_reservieren`. Geht die ganze offene Position an den Auftrag, wird auch `bestellung_position.auftrag_id` gesetzt, damit beim Wareneingang gegen die Bestellung (`bestellung_wareneingang` bzw. Lager-WE) die Charge direkt am Auftrag hängt.
  - Premix → Reservierung.
  - Ist noch kein Weg festgelegt, setzt sie ihn per `auftrag_beschaffungsweg_setzen`: Bulk → `kapsel`, Premix → `premix`. Ein PA in Vorbereitung wird damit freigegeben.
  - Nur bis zum ersten erledigten Produktionsschritt.
- `auftrag_deckung_loesen($aid, 'charge_fest'|'res', $id)`: Charge wieder frei bzw. Reservierung storniert. Eine nur durch die Kopplung gesetzte `bestellung_position.auftrag_id` (Artikel-Position) wird wieder leer.
- `auftrag_beschaffungswege()` rechnet die drei Karten nur noch auf `rest`. Den Rohstoffbedarf aus `produktion_materialbedarf` (zieht reservierten Bulk schon ab) skaliert sie auf den Rest, ohne doppelt abzuziehen. Dazu kommen `deckung` und `bedarf_gesamt`.
- **Keine Doppelbestellung:** `bedarf_bulk()` zählt dem Auftrag reservierte, noch nicht gelieferte Bestellpositionen des Bulk-Artikels als bestellt. Freitext-Zeilen der Aufträge zählten schon vorher, sie werden nicht doppelt gezählt.
- `auftrag_deckung_weg_festlegen($aid, $wegQuelle)` (05.11.2026, Nachbesserung): Nach einer Zuordnung wird nicht nur ein fehlender Weg gesetzt, sondern auch ein PA, der noch in **Vorbereitung** steht („Beschaffungsweg offen“), freigegeben. Dafür nimmt sie den schon gespeicherten Weg, sonst den aus der Quelle. Vorher blieb der Auftrag auf „offen“, wenn am PA schon ein Weg stand. `auftrag_deckung()` liefert dazu `pa_status`.

## Bulkware aus dem freien Lager entnehmen (05.11.2026)
`produktion_fertigware_entnehmen()` (Station „Fertigware bereitstellen“, Teilmenge, Machbar-Rechner) nimmt zuerst die dem Auftrag zugeordneten Bulk-Chargen. Danach nimmt sie freie Chargen des Rezeptur-Bulk-Artikels **ohne Auftrag** nach FEFO, höchstens bis zum Netto-Bestand `auftrag_bulk_lager_frei()` und je Charge abzüglich der Reservierungen anderer Aufträge.

Vorher galt nur die zugeordnete Ware. Die Material-Übersicht zeigte z. B. 9.000 Stk verfügbar, die Teilproduktion aber 0 machbar (PR zu AB Q10). Chargen anderer Aufträge werden nie genommen.

## Kunden-Selbstregistrierung (05.11.2026)
`kunden.quelle` ('registrierung') und `kunden.registriert_am` (UTC). `kunde_registrierung_anlegen($d)` legt einen gesperrten Kunden mit Passwort-Hash an. Abgelehnt wird eine schon vorhandene E-Mail, eine gleichnamige Firma kommt als `dublette` zurück. `kunde_registrierung_freigeben($id, $wer)` entsperrt den Kunden (nur selbst registrierte und gesperrte) und schreibt Änderungslog und Verlauf. `kunde_registrierung_offen($k)` sagt, ob er noch wartet. Seite: `module/portal/registrieren.php`.

## Weg nachträglich auf fertige Kapseln (06.11.2026)
`produktion_weg_fertigkapseln($pa_id, $wer)` stellt einen Produktionsauftrag auch nach erledigten Schritten oder trotz Bestellung auf den Weg mit fertigen Kapseln um. Nur die offenen Herstellungsschritte (Unterschied zwischen vollem und kurzem Weg aus `produktionsschritte_fuer()`) werden gelöscht, "Fertigware bereitstellen" kommt an ihre Stelle, `produktionsart='fremd'`. Erledigte Schritte und Bestellungen bleiben. Nicht für Bulk-Produktion. Aufruf über die Produktionsseite (Aktion `weg_zukauf`, wenn `produktionsauftrag_art_setzen()` nicht geht).

## Nachbuchen „komplett versendet" (06.11.2026)
`produktion_nachbuchen_versendet($pa_id, $lieferschein, $wer)` holt für einen schon versendeten Auftrag die Buchungen nach: Rest einbuchen (`produktion_teilmenge_einbuchen`), Material tolerant abbuchen über `_nachbuchen_tolerant()` (ruft `produktion_fertigware_entnehmen`, `produktion_verpackung_entnehmen` und `produktion_etikett_entnehmen` erst trocken, dann voll oder bis zur machbaren Packungszahl), Fertigware des Auftrags ausbuchen, `versendet_menge`, Status „versendet“ mit `phase_override` = letzte Stufe, optional Lieferschein ohne Mail. Rohstoffe werden nie gebucht. Gesperrt, wenn schon alles nachgebucht ist, bei Fulfillment und bei Bulk-PA.

## EK aus Lieferantenpreis (06.11.2026)

`item_ek_aus_lieferantenpreis(int $item_id)`: setzt `item.ek_preis` auf den günstigsten Einstiegspreis aus `lieferant_preis` (kleinste Staffel je Lieferant, nur EUR) und `ek_auto=1`, wenn der EK leer ist oder schon automatisch kam. Von Hand gepflegter EK (`ek_auto=0`, > 0) bleibt. Haupt-Lieferant per COALESCE. Ohne Lieferantenpreis fällt ein automatischer EK auf 0. Nicht für Verpackung (eigene `pack_ek_staffel`). Aufgerufen in `lieferant_angebot_annehmen`, `lieferant_katalog` (Preis übernehmen), `ek_lieferant_preis_schreiben` und auf der Rohstoffseite. Neue Spalte `item.ek_auto`.

## Staffelpreis unter der Mindestmenge (06.11.2026)

`rohstoff_ek_bei_menge()` nimmt je Lieferant die größte passende Staffel; liegt die Menge **unter** seiner kleinsten Staffel (240 kg bei „ab 250 kg“), gilt deren Preis – wie `staffel_je_lieferant()`. Vorher kam dann gar kein Lieferantenpreis (nur der flache EK, oft 0) und der Beschaffungsweg zeigte „kein Preis“. `rohstoff_ek_detail()` liefert zusätzlich die Mindestmenge des gewählten Lieferanten; der Beschaffungsweg zeigt sie als „Mindestmenge 250 kg“ unter dem Betrag.

## Fortschritt neu (08.11.2026) – `auftrag_fortschritt()`
Neue Fortschrittskette – Dashboard (Auftrag + Auftragsliste) und **seit 08.11.2026 auch Kundenportal** (über
`portal_auftrag_track()` in module/portal/kunde.php, Kundensicht `auftrag_fortschritt_kunde()`). `kunde_auftrag_track()` bleibt
als Altfunktion bestehen. **Vollbedruckter Beutel** (`auftrag_vollbedruckt`): kein Etikett-Punkt; fehlt das Beutel-Design,
roter Punkt „Beutel Daten fehlen". Je Schritt Status-Text solange offen, Abschluss-Text wenn erledigt:
Bestätigt · Rohstoff bestellt (Rohstoff angekommen) · In Produktion (Produziert) · Etiketten-Stand (Etikettiert) ·
[Verpacken (Verpackt), nur mit Umkarton/Beipackzettel] · Qualitätsprüfung (… abgeschlossen) bzw. Laboranalyse statt QC ·
Abgeschlossen · Versenden (Versendet) bzw. Einlagern (Eingelagert) bei Fulfillment.
- **Nur vorhandene Daten**: `kunde_auftrag_phase()` (inkl. `phase_override`) ist Untergrenze; Feinschritte aus `produktion_schritt`
  (Herstellung ohne „Verpacken"=Abfüllen; zugekaufte Bulk-Charge `item.kategorie='fertig'` = produziert).
- **Etiketten-Stand**: rot „Etiketten Daten fehlen" (braucht Etikett, keine Datei am Auftrag/Kundenetikett-Artikel; nie bei v3-Aufträgen)
  → „Etiketten nicht bestellt" → „Etiketten bestellt" (offene Bestellung, ggf. „erwartet …") → „Etiketten auf Lager" → „Etikettiert".
  Seit 08.11.2026 rechnet der Etiketten-Stand über `material_deckung()` gegen den **noch offenen** Bedarf (`auftrag_bedarf_rest`): „auf Lager", sobald der freie Bestand des Etikett-Artikels reicht – rein berechnet, nichts gespeichert, keine Bestellung nötig, wenn die Etiketten schon da sind.
  Ohne PA-Schritte entscheiden `produkt.weg_*` und `auftrag_braucht_etikett()`, ob es Etikett-/Verpacken-Punkte gibt.
- **Teilmengen**: Zustand `teil` (halber Punkt) mit „X von Y Stk" aus `auftrag_mengenfortschritt()` bzw. `versendet_menge`.
- **Energetisierung** nicht in der Kette, sondern `['energ']` (separates Kästchen).
- `$leicht=true` (Listen) spart die Bedarfsrechnung. `auftrag_fortschritt_stand()` = Text für Listen (rot vor laufend vor zuletzt erledigt).
- `auftrag_fortschritt_kunde()` = Kundensicht (Einkaufsstände der Etiketten → „Etikettierung", Untertexte weg) – fürs Portal vorbereitet.
- `auftrag_track_icon('err')` = „!".
- Jeder Schritt trägt im 6. Feld die bisherige Phasen-Stufe, die ihn erledigt (für „Schritt abschließen“); `phase_override` wirkt als Untergrenze auch auf Produziert (≥4), Etikettiert/Verpackt (≥5) und Qualitätsprüfung (≥6).

## „Rohstoff bestellt“ nur bei echter Bestellung (08.11.2026)
`kunde_auftrag_phase()`: Phase 1 nur, wenn eine Bestellung zum Auftrag den Status gesendet/bestellt/geliefert hat oder angekommen ist
(Position mit auftrag_id oder Verlaufseintrag ref_typ=bestellung). Entwürfe (status offen), stornierte Bestellungen, „Weg festlegen“
und „An Bedarfsliste senden“ zählen nicht. Die artikelweite Ableitung `auftrag_rohstoffe_beschafft()` wird dafür nicht mehr genutzt.
Manuell gesetzte Stufe (`phase_override=1`) ohne Bestellung: Datenprüfung `rohstoff_bestellt_ohne`.

## Zuordnen je Materialzeile (08.11.2026)
`auftrag_material_quellen($item)` (freie Chargen abzgl. Charge-Reservierungen, offene Bestellpositionen abzgl. Reservierungen), `auftrag_material_zugeordnet($aid)`, `auftrag_material_zuordnen()`, `auftrag_material_loesen()` – Reservierung als einzige Schreibstelle für Nicht-Bulk-Material.

## Direkt bestellt (08.11.2026)
`bestellung_direkt_abschliessen($bid, $datum, $eta, $notiz)` – dritter Bestellweg (bestellt + bestätigt, bestellart=direkt, keine Mail); `bestellung_telefonisch_bestaetigen($bid)` – gesendet → bestellt ohne Mail. Statustext „direkt bestellt“.
- 08.11.2026 (Handbuch 4.9): Meldetexte der Etikett-Sperre (`produktion_etikett_sperre`) heißen jetzt „Etikett nicht freigegeben – Produktion nur bis vor dem Etikettieren" (Beutel entsprechend). Die Sperre selbst gilt unverändert nur für den Schritt Etikettieren. `kontingent_preis_aus_auftrag()`: voller Packungspreis fürs Kontingent (gesamt_netto / Menge), gemeinsam für Erklärtext und `kontingent_aus_auftrag()`.
- 08.11.2026 (Handbuch 4.12): `auftrag_labortest_status()` zählt nur Berichte DIESES Auftrags (nicht mehr am Produkt), nie das Auto-CoA (`hochgeladen_von=coa_ueb`); ist der neueste Bericht „auffällig", Status `auffaellig` (nicht abgeschlossen; Fortschritt intern rot, Kunde sieht „in Prüfung"). `labor_befund_melden($dok_id)`: wichtige Benachrichtigung „Laborbefund auffällig" einmal je Bericht. Lädt `core/charge_norm.php` (toleranter Chargen-Vergleich).

## Beschaffungsweg: voller Bedarf neben dem Rest (10.11.2026)
`auftrag_beschaffungswege()` liefert je Weg zusätzlich `menge_voll` (voller Bedarf des Auftrags ohne Abzug zugeordneter Ware), je Rohstoff-Position `voll` und gesamt `kg_voll`. Gerechnet direkt aus der Rezeptur (Pulver nach Massenanteil am Füllgewicht, sonst mg × Einheiten). Kosten bleiben auf dem Rest.

## Premix-Preise an Stück-Rezepturen (10.11.2026)
- `lieferant_angebot_speichern()`: Antwort auf eine Anfrage mit Form Pulver/Granulat an einer Kapsel-/Tabletten-Rezeptur wird an der Rezeptur als Preis je kg gespiegelt (`rezeptur_lief_angebot.einheit='kg'`). Der Beschaffungsweg liest ihn unter „Premix“.
- `rezeptur_fremd_lieferant_preise()`: bei Stück-Rezepturen werden kg/g/L-Zeilen (Premix) nicht mehr als Stückpreis gelesen.

## Wareneingang gegen Bestellung (10.11.2026, Einkauf gerade ziehen Teil 1-3)
- `bestellung_lieferung_erfassen($bid, $charge_ids, $bp_je_charge = [])`: Jede Charge hängt an genau einer Position. Das ist die gewählte, sonst die erste offene mit demselben Artikel, sonst eine Freitext-Position, die dabei den Artikel bekommt. Abschluss über `webe_bestellung_stand()` in `core/wareneingang_bestellung.php`, gemeinsam mit der Lager-App.
- Neue Spalten: `bestellung_position.rest_storniert_am`, `bestellung.nachtraeglich_grund`, `bestellung.nachtraeglich_geprueft_am`, `charge.we_grund`, `charge.we_grund_notiz`.

- `item.synonyme` (TEXT): frühere bzw. alternative Namen, z. B. zusammengeführte Doppel-Artikel. Sie werden überall mitgesucht (Teil 5).

- `auftraege_ohne_festlegung()` liefert zusätzlich `angebot_id`, `angebot_nr`, `rezeptur_id` und `rezeptur_nr` (Links im Block auf der Bestellen-Seite, Teil 6).

## Premix-Anfrage (10.11.2026, Einkauf gerade ziehen Teil 7)
- `anfrage_ist_premix($a)`: Eine Fertigprodukt-Anfrage an einer Stück-Rezeptur mit Form Pulver/Granulat ist eine Premix-Anfrage (lose Mischung je kg).
- `lieferant_angebot_speichern()`: Die Antwort auf eine Premix-Anfrage wird als **Lieferantenpreis am Premix-Artikel** der Rezeptur gespeichert (`lieferant_preis`, je kg, Grundpreis + Staffeln, ersetzt die Zeilen dieses Lieferanten). Sie wird nicht mehr an der Rezeptur gespiegelt (`rezeptur_lief_angebot` = nur Stückpreise). Der Behelf von heute früh (Pulver-Anfrage als kg-Zeile an der Rezeptur) entfällt.
- `rezeptur_premixitem()`: Neue Premix-Artikel heißen „Premix · <Rezepturname>“ (Einheit kg). Bestehende behalten ihren Namen.

## Alternative zu Rohstoff-Anfragen (10.11.2026)
- Neue Spalten an `portal_anfrage`: `lieferstatus`, `lieferstatus_hinweis`, `alternativen_gesendet_am`, `alternativen_entschieden_am`, `original_rohstoff_id`, `original_betreff`
- Neue Tabelle `portal_anfrage_alternative`
- Die Logik steht in `core/rohstoff_alternative.php`; schema.php bindet die Datei ein.

## auftrag_fortschritt(): wer hat abgeschlossen (10.11.2026)
Jeder Schritt hat ein 7. Feld mit dem Namen der Person, die ihn abgeschlossen hat, oder null. Es wird nur auf der Auftragsseite gefüllt, in Listen (`$leicht`) nicht. Quellen, nur vorhandene Daten:
- **Produziert, Etikettiert, Verpackt, Qualitätsprüfung:** `produktion_schritt.erledigt_von` der passenden Produktionsschritte. Bei Produziert sind das die Herstellung, sonst das Abfüllen.
- **Laboranalyse:** `dokument.hochgeladen_von` des Laborberichts, wenn dort ein Name steht. Allgemeine Werte wie „team“ zählen nicht.
- **Sonst:** War die Stufe über „Fortschritt manuell auf eine Stufe setzen“ (`phase_override`) gesetzt und deckt sie den Schritt ab, steht die Person aus dem letzten Eintrag „Fortschritt gesetzt“ in `aktivitaet` mit dem Zusatz „(Fortschritt von Hand gesetzt)“.

## zahl_lesen(): chinesische Eingabe (11.11.2026)
- Vollbreiten-Zeichen werden vorher in normale umgewandelt (`mb_convert_kana 'a'`), „。“ zählt als Punkt, „，“ und „、“ als Komma.
- Danach zählt nur die erste Zahl im Text. Beispiele: „€0.05“ ergibt 0,05, „0.05/粒“ ergibt 0,05, „ca. 5“ ergibt 5.
- Die Regeln für Tausender und Dezimalzeichen sind unverändert.

## item: Steckbrief-Spalten (11.11.2026)
`steckbrief` (TEXT), `steckbrief_am`, `steckbrief_von`, `steckbrief_bearb_am`, `steckbrief_bearb_von`. Gehören zum internen KI-Steckbrief, siehe `core/rohstoff_steckbrief.md`. Nie im Portal ausgeben.

## item: MOQ-Spalten (11.11.2026)
`moq_menge` (DECIMAL 12,3), `moq_einheit`, `moq_notiz`: unsere Mindestmenge je Rohstoff, siehe `core/rohstoff_moq.md`.

## Preis-Gültigkeit (11.11.2026)
- `gueltig_bis` (DATE) an `lieferant_preis`, `lieferant_angebot`, `rezeptur_lief_angebot`, `lieferant_preisliste`, `produkt_lieferant_preis` und `lieferant_katalog`.
- `item.preis_schwankt` und `item.preis_gueltig_tage`.
- `lieferant_angebot_speichern()` nimmt `$opt['gueltig_bis']` und gibt das Datum an Premix- und Fremdfertigungspreise weiter. Gleiche Staffelmengen werden nur einmal gespeichert.
- `lieferant_angebot_annehmen()` und `lieferant_fertig_angebot_annehmen()` übernehmen das Datum.
Siehe `core/preis_gueltig.md`.

## Preis-Gültigkeit in der Kalkulation (11.11.2026)
- `lp_gueltig_auswahl()` bevorzugt gültige Preise.
- `lp_verlauf_sichern()` schreibt in die Tabelle `lieferant_preis_verlauf`.
- Die Rückgaben von `rohstoff_bester_lieferant()`, `rohstoff_ek_detail()`, `angebot_rohstoffkosten()` und `auftrag_beschaffungswege()` sind erweitert.
Siehe `core/preis_gueltig.md`.

## Angebotsgültigkeit (11.11.2026)
`angebot_gueltig_bis_default(?int $angebot_id)`, `angebot_ek_gueltig_bis()` und `angebot_abgelaufen()`, siehe `core/preis_gueltig.md` Teil 4.

## Bestellliste und Gültigkeit (11.11.2026)
- `lp_je_lieferant_gueltig($rows, &$abgelaufen)` wählt je Lieferant die gültigen Zeilen.
- `item_lieferant_preise()`, `rezeptur_fremd_lieferant_preise()` und `produkt_fremd_lieferant_preise()` haben den optionalen Parameter `&$abgelaufen`.

## Fortschritt: echte Mengen schlagen manuelles Abhaken (11.11.2026)
- **`auftrag_mengenfortschritt()`:** Neben den Chargen der Produktionsaufträge zählen auch Fertigware-Chargen, die nur am Auftrag hängen (`charge.auftrag_id`, Artikel `verkaufsfertig`, z. B. Zukauf fertig). Jede Charge zählt einmal. Ohne Produktionsauftrag ist das Ziel die Auftragsmenge.
- **`auftrag_fortschritt()`:** Bei 0 < gebucht < Ziel stehen „Produziert“ und „Etikettiert“ immer auf „teilweise, x von y Stk“, auch wenn sie von Hand (`phase_override`/Status) erledigt sind. „Abgeschlossen“ bleibt offen, „Versenden“ ist nicht aktiv.
  - Ziel ist die Auftragsmenge bzw. `auftrag.fortschritt_ziel`, wenn der Rest storniert ist.
  - Bei `auftrag.rest_erwartet` steht „Rest erwartet am …“ beim Produziert-Schritt, auch für den Kunden.
  - Ohne gebuchte Menge gilt der bisherige Stand (Altaufträge).
  - Rückgabe `menge.offen`.
- **`auftrag_menge_offen($a)`:** `['ziel','geb','rest']` oder null.
- **Neue Spalten am Auftrag:** `fortschritt_ziel`, `rest_status` (kommt/storniert/teillieferung), `rest_erwartet`, `fortschritt_geprueft`.

## Fortschritt: „Abgeschlossen“ nur, wenn alles fertig ist (11.11.2026)
- **`auftrag_fortschritt()`:** „Abgeschlossen“ ist nur erledigt, wenn der Auftrag tatsächlich versendet ist oder versandbereit ist und Folgendes gilt:
  - bei Labor: die Laboranalyse ist gebucht und nicht auffällig
  - die Mengen sind vollständig (siehe Teil 1)
  Das gilt auch, wenn der Stand von Hand weiter gesetzt ist. „Versenden“ bzw. „Einlagern“ wird erst danach aktiv.
- **`auftrag_fortschritt_fertig($a)`:** Ist „Abgeschlossen“ erledigt? Ergebnis wird je Request gemerkt.
- **`kunde_bestell_status_label()`:** zeigt bei Status `erledigt` nur dann „versandbereit“ bzw. „wird eingelagert“, wenn der Fortschritt fertig ist, sonst „in Produktion“.

## Fortschritt: Datum nie vor dem Vorgänger (11.11.2026)
- **Intern:** `auftrag_fortschritt()` setzt je Schritt ein 8. Feld (`true`), wenn ein erledigter Schritt früher datiert ist als ein erledigter Schritt davor. Die Auftragsseite zeigt dann „Datum unlogisch“.
- **Kunde:** `auftrag_fortschritt_kunde()` behandelt „Bestätigt“ als frühestes Datum.
  - Ein früheres Ereignis zeigt das Bestätigungsdatum.
  - Ein Datum vor dem des vorigen Schritts wird nicht gezeigt.

## Fortschritt: Zukauf fertig vollständig (11.11.2026)
Ist die volle Menge (Ziel bzw. `fortschritt_ziel`) als Fertigware-Chargen eingegangen, gilt „Produziert“ als erledigt. Das Datum ist der letzte Wareneingang.

## auftrag_status_kunde() (Sofortfix 11.11.2026)
`auftrag_status_kunde($a)` gibt den Status zurück, wie Kunde und Lager ihn sehen: „erledigt“ bleibt nur, wenn `auftrag_fortschritt_fertig()` sagt, dass „Abgeschlossen“ wirklich erledigt ist (alle Mengen da, Labor da). Sonst 'in_produktion'. Dienstleistungen unverändert. Fehlende Felder lädt die Funktion selbst nach. Eine Stelle für Portal-Startseite, „Meine Anfragen“, Etiketten-Seite und (per Loopback) die Lager-App.

`auftrag_versendet_offen_text($a)` (11.11.2026): '' oder „Labor läuft noch / Menge 175 von 500“ – Anlass für die Pflicht-Begründung beim Hand-„versendet“. In `auftrag_fortschritt()` gilt „Abgeschlossen“ bei versendet mit offenem Labor als **nicht** erledigt.

## Preise mit 6 Nachkommastellen (Sofortfix 11.11.2026)
- `preis_spalten_sechs_stellen()` (in der Migration): `lieferant_preis.preis`, `lieferant_angebot(.._staffel).preis`, `lieferant_katalog.preis`, `rezeptur_lief_angebot.preis/preis_vorschlag`, `rezeptur_lief_angebot_staffel.preis`, `lieferant_preisliste.eur_kg`, `lieferant_preis_verlauf.preis`, `item.ek_preis`, `bestellung_position.ek_preis`, `pack_ek_staffel.ek_preis` -> `DECIMAL(14,6)` per `ALTER ... MODIFY` (Werte bleiben, NULL/Default bleiben). Vorher rundete die DB 2,95 €/1.000 auf 0,0030.
- `preis_anzeige($p, $waehrung, $bezug)`: unter 0,01 als „2,95 € / 1.000 Stk“, sonst 2 bzw. 4 Stellen.
- `preis_eingabe_lesen($roh, $basis)`: Textfeld über `zahl_lesen()`, `$basis='1000'` teilt durch 1.000.
- Bereits falsch gespeicherte Werte (z. B. GS Capsules 0,0030 statt 0,00295) werden **nicht** automatisch korrigiert – neu eintragen.

`auftrag_beschaffung_bestellen()` legt bei Rest 0 nichts an (11.11.2026).

## Gesperrt/inaktiv (Abendpaket Block C, 11.11.2026)
- **Preise gesperrter Lieferanten** zählen nicht mehr bei „günstigster Lieferant“, Kalkulation und Auto-EK. Betroffen sind `item_lieferant_preise`, `produkt_lieferant_preise`, `rezeptur_fremd_lieferant_preise`, `item_ek_aus_lieferantenpreis` und `rohstoff_ek_detail`. Bedingung: Lieferant nicht `gesperrt=1`; Preise ohne Lieferanten-ID bleiben.
- **`rezeptur_inaktiv_setzen($rid, $inaktiv, $wer)`:** setzt den Status „inaktiv“ und merkt sich den alten Status in `rezeptur.status_vor_inaktiv`. Der eigene Bulk-Artikel und der Premix-Artikel werden mit gesperrt (`item.gesperrt_durch_rezeptur`). Beim Wieder-Aktivieren kommt alles zurück.
- **`rezeptur_testdaten_inaktiv_einmal()`:** setzt einmalig RZ-4023, RZ-4033 und RZ-4034 auf inaktiv (app_meta `rz_test_inaktiv_1111`, von Nico ausdrücklich erlaubt).

`item_zusammenfuehren()` ist seit 11.11.2026 nur noch eine Hülle für `artikel_zusammenfuehren_voll()` (eine Logik mit Sperre bei anderer Kapselgröße, Füllgewicht, Material, Einheit, Warenart).

`auftrag_material_loesen()` (11.11.2026): Lief die Zuordnung über eine ganze Bestellposition, wird deren `auftrag_id` beim Lösen zurückgesetzt (wie `auftrag_deckung_loesen()`).

**Abgelaufene Preise (Block D, 11.11.2026, Fable 14.c):** `lp_gueltig_auswahl()` und `lp_je_lieferant_gueltig()` geben einen Kontext [typ, id] weiter. Betroffen sind: Beschaffungsweg Zukauf/Premix, `item_lieferant_preise`, `produkt_lieferant_preise(_pkg)`, `rezeptur_fremd_lieferant_preise`, `rohstoff_ek_detail` (VK-Rechnung), Express-Bestellung, Leerkapsel-EK (`produkt_kapsel_ek`) und der flache EK (`rohstoff_ek_bei_menge`). Der **Auto-EK** wird nicht mehr aus einem abgelaufenen Preis gesetzt: Der alte EK bleibt, `item.ek_abgelaufen_seit` ist das Kennzeichen.

`etikett_bestand_info()` (Block D, 11.11.2026): **verbraucht** = versendete Aufträge voll + bei laufenden/erledigten die wirklich produzierte (gebuchte) Menge. Vorher zählte „Status erledigt“ als ganze Auftragsmenge, deshalb zeigte das Portal bei AB-3237 (175 von 500, 825 Kundenetiketten im Lager) „Ihre Etiketten im Lager: 0 von 500“.

**Kein Etikett-Ersatz mehr (Block E, 11.11.2026, Entscheidung Nico):** `produktion_etikett_entnehmen()` nimmt bei fehlendem Kundenetikett-Bestand nicht mehr still das generische Maß-Etikett. Etikettieren ist dann blockiert, wie in der Werk-App. Produkte ohne Kundenetikett (neutral) ziehen weiter das generische Etikett. Früher schon über den Ersatz gebuchte generische Etiketten eines PA zählen weiter als verbraucht.

## Stornieren ohne Nachricht, Position stornieren (12.11.2026)
- `bestellung_stornieren($id, $wer, $grund, $lieferant_informieren = true)`: mit `false` keine Portal-Nachricht und keine Mail, Grund Pflicht; Verlaufseintrag immer. `false` nur intern (Datenpflege-Läufe), kein Formularfeld setzt es (13.11.2026).
- `bestellung_position_stornieren($bp_id, $wer, $grund, $lieferant_informieren = true)`: Position bleibt (rest_storniert_am + pos_storno_grund), nichts gelöscht; nicht bei eingebuchter Ware; Bestellung rechnet neu.
- Offene Menge (unterwegs) überall nur noch für nicht stornierte Positionen: `IF(bp.rest_storniert_am IS NULL, GREATEST(menge - geliefert, 0), 0)` (item_unterwegs, bp_rest_sql, Material/Bestand/Doppelt-Prüfung, Lager-App).

## Bestellwege mit Unterlagen-Sperre (13.11.2026)
`bestellung_direkt_abschliessen()` gibt jetzt einen Text zurück ('' = abgeschlossen, sonst Grund der Sperre). Neu `bestellung_als_bestellt_markieren()` für „als bestellt markieren“ (Sperre + Mail).
