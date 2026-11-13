# lager/core/erp.php – die Naht zum Dashboard

Die **einzige** Datei im Lager, die Tabellen des Dashboards kennt. Benennt jemand im Dashboard eine Spalte um, muss er nur diese Datei prüfen. Das gilt auch beim Umstieg auf v5: Dann wird nur diese Datei angepasst.

Stand heute wird hier nur gelesen, und zwar die Logins aus der Tabelle `benutzer`. Wenn später Buchungen dazukommen (zum Beispiel eine Entnahme für einen Produktionsauftrag), stehen sie hier als eigene, klar benannte Funktion.

**Einlagern (Produktion → Lager):** `erp_einlager_aufgaben()` liest die offenen Übergaben
(`aufgabe.ref_typ='einlagern'`). `erp_einlager_buchen($pa_id)` ruft die **kanonische** Dashboard-Funktion
`einlager_buchen()` per **Loopback** auf (`?p=api_einlager`, Token in `app_meta['einlager_api_token']`,
`erp_einlager_token()`) – die komplexe Fertigwaren-Buchung (BSKU/Lager 2) wird NICHT im Lager nachgebaut.

**Erwartete Lieferungen (read-only):** `erp_erwartete_lieferungen()` liest offene Bestellungen (`bestellung.status='bestellt'`, `angekommen_am IS NULL`) inkl. Positionen. Jede Position erhält eine **`warenart`** für die Kategorie-Reiter der Tabelle (`?p=erwartet`): `item.kategorie` → sonst `fertig` bei auftragsgebundenem Zukauf → sonst `erp_warenart_raten($name)` (grobe Heuristik aus dem Freitext-Namen: Verpackungs-Begriffe → `verpackung`, Darreichungsformen/Dosierungen → `fertig`, sonst '' = Sonstiges). Die echte Warenart wird erst beim Einbuchen festgelegt.

**Lager-2-Katalog (read-only):** `erp_kunde_verkaufsfertig($kunde_id)` – Verkaufsfertig-Items (Bestand) eines Kunden für die optionale Bestand-Verknüpfung im Artikelkatalog (`?p=l2_artikel_edit`).

**Lager-2-Einbuchen:** `erp_l2_typ_defs()` (Typ → item.kategorie + Verpackungs-Rolle + **Verpackungsart** + ob „neu" erlaubt) und `erp_items_l2()` (buchbare Artikel aller Kategorien inkl. Karton/Sonstiges + `rolle` + `art`, fürs Filtern je Typ). Typen: Verkaufsprodukt, Rohstoff, Etikett, Beipackzettel, **Pouchbag**, **Rollenware (Stick)**, Karton, Sonstiges. Pouchbag/Rollenware sind beide `verpackung`/`primaer` und werden über die Verpackungsart (`beutel`/`stick`) getrennt. `erp_item_anlegen($name,$kat,$einheit,$rolle='',$art='')` kann `karton`/`sonstiges`, die Verpackungs-Rolle und die Verpackungsart setzen.

**Kunden & Adressen (Versand, read-only):** `erp_kunden_liste()` (alle Kunden für die Auswahl) und `erp_kunde_adressen($kunde_id)` – baut die Adress-Auswahl aus den `kunden`-Spalten: **Lieferadresse (bevorzugt)**, Hauptadresse, Rechnungsadresse; jede mit `land` (ISO, weltweit). Genutzt beim Warenausgang (`?p=versand_detail`).

**Rezeptur ↔ Bulk-Item (read-only):** `erp_rezeptur_liste()` liefert die Rezepturen (≠ Entwurf) inkl. ihres kanonischen Bulk-Items für den Picker beim Einbuchen fertiger Kapseln; `erp_rezeptur_bulkitem($rezeptur_id)` löst das Bulk-Item auf (`item.rezeptur_id` + `kategorie='fertig'`). Die **Anlage** des Bulk-Items bleibt bewusst im Dashboard (`rezeptur_bulkitem()`), weil ein `require` der Dashboard-`core/schema.php` an doppelten `db()`-Definitionen scheitern würde. Details/Begründung: `ANTWORT-DASHBOARD-REZEPTUR-BULKITEM.md`.

**Rezepturnummer-Aufkleber scannen (Spec 5.2, read-only):** `erp_rezeptur_per_nummer($scan)` – bestimmt aus einer gescannten Rezepturnummer (tolerant: `R12345`, `RZ-12345`, nackte Ziffern) die Rezeptur und gibt eine fertige **Fertigware/Bulk-Position** zurück (warenart `fertig`, rezeptur_id/-name, koppelbares Bulk-Item). Genutzt vom Wareneingang (`?p=we`, AJAX `aktion=rsticker`) → kein Tippen, keine Fehlzuordnung.

**Wareneingang-Einheit = Artikel-Basiseinheit (Gewicht/Volumen):** `erp_wareneingang_buchen()` / `erp_wareneingang_buchen_fremd()` rechnen die eingegebene Menge über `erp_menge_in_item_einheit($menge,$von,$item_einheit)` in die **Basiseinheit des Artikels** um, wenn Eingabe- und Artikel-Einheit gewichts- (mg↔g↔kg) bzw. volumen-verwandt (ml↔L) sind: „25.000 g" bei einem kg-Artikel wird als **25 kg** gebucht. Grund: `item_bestand()` (Dashboard) summiert `charge.menge_verfuegbar` **ohne** Umrechnung – eine g-Charge auf einem kg-Artikel zählte sonst 1000× zu hoch (→ falsch „kein Bedarf"). Kategoriale Einheiten (Stk/Karton/Beutel/…) bleiben wie eingegeben (z. B. Kapseln = Stk). Konsequenz für Pulver: effektiv immer in kg.

**Material-Standort (Spec 6.2):** Die Spalte `charge.standort` (`lager1`|`produktion`|`lager2`) gehört dem **Dashboard** (`core/schema.php`) – wird hier NIE angelegt. `erp_charge_standort_spalte()` prüft (gecacht), ob die Spalte existiert; fehlt sie, verhält sich alles wie `lager1` und nichts bricht. `erp_charge_standort($id)` liest den Standort, `erp_charge_standort_setzen($id,$ziel)` setzt ihn (Entnahme in die Produktion / Rückgabe ins Lager 1 – **der Blinker bleibt dran**, nur der Standort wandert). `erp_standort_sel()`/`erp_standort_label()` als Helfer; der Standort ist in `erp_charge_select`/`erp_bestand` mit ausgewählt (guarded) → Bestandsliste und Versand-Picker zeigen „in Produktion" (nicht doppelt verplanen).

## erp_versandkatalog(kunde_id, q)
Produkt-Katalog eines Kunden für den Lieferschein-Generator: je Produkt `produkt_id`, verknüpftes
verkaufsfertiges `item_id`, Name, `ek_cent`, `vk_cent` (aus `produkt_preis`), `gewicht_g`. Ohne Kunde: Suche über
alle Produkte. Grundlage für Versicherungswert (EK, sonst VK/2) und Gewicht.

## Teil 4: Gebinde je Charge (charge_gebinde)
`erp_charge_gebinde_liste($charge_id)`, `erp_charge_gebinde($id)` (mit Charge/Item), `erp_charge_gebinde_anlegen($charge_id,$anzahl)` – Raw-SQL über die geteilte Dashboard-Tabelle `charge_gebinde` (Bestand je Karton: Gewicht/Restmenge/Ort). Anlegen ist idempotent (nur wenn noch keine existieren), teilt die Chargenmenge gleichmäßig (letzter Karton = Rest; Stück ganzzahlig). Fehlt die Tabelle (nur Dashboard legt sie an), no-op.

## Etikettenrollen: Inventaraufkleber statt Blinker
Etiketten (`item.kategorie='verpackung'` + `verpackung_rolle='etikett'`, Warenart `etikett`) bekommen beim
Wareneingang je Rolle einen **Inventaraufkleber** (8 Ziffern) statt eines Blinkers. Eine Rolle = ein
`charge_gebinde`-Datensatz mit der Spalte `inventar_nr` (+ `auftrag_id`/`pa_id`/`verbraucht_am`, vom Dashboard-Schema
angelegt). Die Spaltenprüfung `erp_gebinde_spalte_da()` degradiert sauber, solange das Dashboard-Schema noch nicht lief.
**Eingabe = nur Gesamtmenge + Anzahl Rollen** (Nico): keine Menge je Rolle, keine Summenprüfung – die Menge wird
gleichmäßig verteilt (Rest auf die letzte Rolle). Aufkleber je Rolle optional scannen, leer = automatisch.
- `erp_inventar_norm($s)` – nur Ziffern, genau 7 Stellen, sonst `''`.
- `erp_inventar_frei($nr,$ausser=0)` – frei, wenn keine aktive (status≠'leer') Rolle die Nummer trägt.
- `erp_inventar_naechste($benutzt=[])` – nächste freie **Auto-Inventarnummer** im Band 90000000–99999999 (physische
  Aufkleber liegen darunter); `$benutzt` = in diesem Durchlauf schon vergebene Nummern.
- `erp_etikett_rollen_verteilen($charge_id,$gesamt,$anzahl,$nummern=[])` – verteilt `$gesamt` gleichmäßig auf
  `$anzahl` Rollen (Rest auf die letzte), füllt die Inventarnummer je Rolle aus `$nummern` (gescannt) oder
  automatisch, ruft dann `erp_etikett_rollen_anlegen`. **Blockiert nie** (gescannte Dublette → Auto-Nr. + Hinweis).
- `erp_etikett_rollen_anlegen($charge_id,$rollen)` – `$rollen=[['nr'=>…,'menge'=>…],…]`, Menge je Rolle frei (Low-Level).
- `erp_charge_rollen($charge_id)` – Rollen einer Charge (mit Auftragsnummer) für die Anzeige.
- `erp_rolle_per_inventar($nr)` – eine Rolle per Inventarnummer (Produkt/Charge/Lieferant/Kunde/Auftrag).
- `erp_etikett_rollen_fuer_item($item_id,$auftrag_id=0)` – freie Rollen eines Etiketts (Produktions-Auswahl).
- `erp_rolle_auftrag_zuordnen($gebinde_id,$auftrag_id,$pa_id=0)` / `erp_rolle_freigeben($gebinde_id)` – Rolle einem
  Auftrag zuordnen (Standort→Produktion, Rückverfolgung) bzw. Zuordnung lösen.
Warenart-Matrix: `erp_warenart_regeln($kat,$form,$rolle)` liefert für Etikett `inventar_pflicht=true` (kein MHD/Charge,
sofort frei). `erp_item_warenart_setzen($id,'etikett')` setzt `kategorie=verpackung`+`verpackung_rolle=etikett`.

**Wareneingang „Aus Liste wählen" (S-21)**: `erp_rezeptur_zu_position()` liefert keine Rezeptur mehr, wenn die
Position einen eigenen Artikel hat, der nicht `kategorie='fertig'` ist (Verpackung/Etikett/Rohstoff).
`erp_lieferung_positionen()` gibt solchen Positionen ihren eigenen `item_id`/`item_name`, `verpackung_rolle`,
`form` und die passende Warenart (`erp_item_warenart`) mit. `erp_position_zuordnen()` bevorzugt den Artikel der
Position vor dem Namenstreffer.

`erp_bestellung_angekommen($id)` setzt nicht nur `angekommen_am`, sondern schließt die Bestellung (`status='geliefert'`, wenn sie noch offen/gesendet/bestellt war) – sonst Doppelzählung im Bedarf und doppeltes Einbuchen über „liefern" im Dashboard (S-06).

**Einheitliche Quarantäne-Regel (St-12):** `erp_wareneingang_buchen(..., $status='auto')` entscheidet den Status wie das Dashboard: Rohstoff/Bulk/verkaufsfertig -> `quarantaene`, Verpackung/Etikett/Verbrauch -> `frei`. Ein ausdrücklicher Status wird weiter respektiert. `erp_charge_status_setzen()` setzt den Bedarf-Cache zurück. `erp_quarantaene_chargen($item_id=0)` liefert alle Chargen in Quarantäne (Seite `?p=quarantaene`).

## QM-Gate Fertigware (St-11d)
Eigene Fertigware (Einlagern aus der Produktion: `produktion_teilmenge_einbuchen()`, Produktions-App `erp_teilmenge_einbuchen()`, Easy Production außer „schon geliefert") kommt in **Quarantäne** statt frei. Versand, Fremdlager-Übergabe und Weiterverarbeitung nehmen nur freie Chargen, also erst nach der QM-Freigabe (Lager-App „Quarantäne" / Dashboard-Wareneingang, nur QM/Admin). Die Versand-/Fremdlager-Meldung nennt die wartende Menge (`fertigware_qm_hinweis()`), die Bulk-Reservierung einer nicht freien Charge warnt nur, und die Lager-App lässt Fertigware in Quarantäne nicht entnehmen. Zukauf „fertig" behält den Status vom Wareneingang.
## Fremdlager-Wareneingang (S-16)
`erp_wareneingang_buchen_fremd()` setzt zusätzlich `standort='lager2'` (vorher blieb Kundenware mit Besitzer auf `lager1`).

## Quarantäne-Übersicht (Nacht 1b)
Die Übersicht (`?p=wareneingang&quarantaene=1`, Knopf „Nur Quarantäne (Anzahl)") zeigt alle Chargen in Quarantäne mit Bestand: Charge, Artikel, Menge, **Eingang**, MHD, **Lieferant / Herkunft** (bei Fertigware „Produktion PR-…"), Status und „freigeben" (nur QM/Admin), älteste zuerst. Kein Direkt-frei-Haken beim Wareneingang. Lager-App `?p=quarantaene` zeigt die Herkunft ebenso.

## Teillieferung (Nacht 1c)
`bestellung_position.geliefert_menge` (neu, additiv) zählt, was je Position schon eingebucht ist. Der Wareneingang mit Bestellbezug ruft `bestellung_lieferung_erfassen($bestellung_id, $charge_ids)` (Lager-App: `erp_bestellung_lieferung_erfassen`): die Chargen werden auf die Positionen verteilt (gleicher Artikel, sonst Freitext-Position; g/kg und ml/l werden umgerechnet). **Geschlossen** (angekommen_am, Status geliefert) wird die Bestellung erst, wenn alle Positionen zu mind. 98 % geliefert sind. Vorher bleibt sie offen, und „unterwegs" zählt nur den Rest (`bp_rest_sql()`, auch in `bulk_position_basis_sql()`), es entsteht also kein doppelter Bedarf. Der Knopf „liefern" (`bestellung_wareneingang`) setzt alle Positionen voll.

## Wareneingang → Portal-Status (Nacht 4b)
Neue letzte Station **„Wareneingang bei bulkify"** (`eingegangen`, de/en/zh). Sie setzt nur der Wareneingang: beim vollständigen Einbuchen einer Lieferanten-Bestellung (Dashboard, Lager-App, Knopf „liefern"), nicht der Lieferant per Knopf. Bei einer Teillieferung steht im Ablauf „Teillieferung eingegangen: Datum" (`bestellung.teilgeliefert_am`). **Keine Mail an den Lieferanten.** Das Team bekommt eine Dashboard-Benachrichtigung „Lieferung eingebucht: BE-… (Firma)", bei einer Teillieferung mit Anzahl offener Positionen (`bestellung_eingang_melden()`).

## Kleinigkeiten (Aufräumen 10/11)
- Lager-Lieferschein: Ohne eigenen Versand-Absender gelten die Firmendaten aus dem Dashboard (`erp_firma_absender()`), nicht mehr „Musterstraße 1".
- Wareneingang: die Meldung „Blinker angehängt" entfällt (stimmte nicht bei Etiketten ohne Blinker).

## Inventarnummer unter Sperre (Aufräumen 13)
`erp_etikett_rollen_verteilen()` vergibt die Auto-Inventarnummern (MAX+1) und legt die Rollen unter `GET_LOCK(bx_inventar_nr)` an. So ziehen zwei gleichzeitige Wareneingänge nicht dieselbe Nummer. Ein UNIQUE-Index geht nicht, weil eine Nummer nur je **aktiver** Rolle eindeutig ist (leere Rollen dürfen sie behalten).

`erp_qm_freigabe_melden($charge_id)`: nach einer Freigabe quarantaene -> frei in `erp_charge_status_setzen()` meldet die Lager-App
das per Loopback `?p=api_qm_freigabe` ans Dashboard (Token wie api_einlager) -> dort Produktionsbericht + CoA für den Kunden.

## Etiketten einbuchen (03.11.2026)
- `erp_etikett_produkte()` – je **Produkt + Kunde** ein Eintrag (Kundenetikett `item.produkt_id` oder Slot `produkt.etikett_id`), Text „Nummer · Produkt · Kunde“ (ohne Kunde: „Standard-Etikett“), dazu Etiketten ohne Produkt (`item_id`). Seit 03.11.2026 mit Kundenname, damit man auch über den Kunden sucht.
- `erp_etikett_artikel($item_id)` – ein einzelnes Etikett in derselben Form wie `erp_etikett_zu_produkt` (gemeinsam: `erp_etikett_sql`, `erp_etikett_eintrag`).
- `erp_etikett_anlegen($d)` – Etikett von Hand anlegen (kunde_id, produkt_id optional, bezeichnung, breite, hoehe, material). Mit Produkt: Gibt es für Produkt + Kunde schon ein aktuelles Etikett, wird es zurückgegeben (`vorhanden`), kein Duplikat. Sonst nächste Version (v1), Maße/Material leer → vom Etikett-Slot des Produkts. Ohne Produkt: Bezeichnung Pflicht, Name „Etikett · Bezeichnung · Kunde“, gleicher Name → vorhandener Artikel. Nummer VP-… aus dem gemeinsamen Nummernkreis. `produkt.etikett_id` wird nur gesetzt, wenn der Slot leer ist und kein bzw. der Produkt-Kunde gewählt ist.
- `erp_produkt_auswahl()` – alle Produkte (id, nummer, name) für die Auswahl.
- `erp_etikett_zu_produkt($produkt_id)` – je Kunde die aktuelle (nicht gesperrte) Etikett-Version + Slot. Liefert id, name, kunde, version, kundenetikett, dok (freigegebenes Design), pdf, format, breite, hoehe und material (mit Fallback auf die Maß-Vorlage `etikett_vorlage_id`). Liest nur. **Achtung:** breite/hoehe hier sind das am Etikett-Artikel gespeicherte Maß – die **maßgebliche** Größe kommt aus dem Behälter (s. u.).
- `erp_produkt_etikettmass($produkt_id)` – das **maßgebliche Etikett-Endformat (B×H mm) aus dem Behälter** (Glas/Dose) des Produkts: jüngster Auftrags-Behälter (Vorrang), sonst Produkt-Behälter; Endformat = `item.etikett_final`. Liefert `['breite','hoehe','name','format']` oder null. Read-only Spiegel von `produkt_etikettmass()` (ohne Beutel-Neutralisierung). Der Wareneingang zeigt dieses Maß als „Größe" und warnt, wenn das Etikett-Artikel-Maß abweicht. Mass-Parser: `erp_etikett_masse($text)`.

## Inventaraufkleber 8-stellig (03.11.2026)
Die Aufkleber in den Etikettenrollen sind 8-stellig.
- `erp_inventar_norm()` akzeptiert 8 Ziffern, 7 nur noch für Altdaten. Beim Wareneingang werden 7-stellige Scans abgelehnt und durch eine Auto-Nummer ersetzt.
- `erp_inventar_naechste()` vergibt 8-stellig ab 90000000 (geprüft gegen alle aktiven Rollen). Das System legt die Nummer an, wenn nichts gescannt wird, und druckt sie als Aufkleber mit Barcode für die Rolle.
- `erp_rolle_per_inventar()` findet bei einem 8er-Scan auch Altdaten, die das frühere 7er-Eingabefeld (maxlength 7) auf die ersten 7 Ziffern gekürzt hat.
- DB: `charge_gebinde.inventar_nr` wird von VARCHAR(7) auf VARCHAR(8) verbreitert (core/schema.php, einmal nach dem Deploy).

`erp_rollen_inventar_korrigieren($charge_id, [gebinde_id => Nummer])` (03.11.2026): Nachbessern falscher Rollennummern (Aufkleber 11111111 aufsteigend, im System gekürzt/auto). Prüft 8 Ziffern und Doppelnummern, schreibt `aenderung_log` (Dashboard-Tabelle, nur hier in der Naht) und `lg_bewegung`.

`erp_we_quarantaene()` (04.11.2026): liest `app_meta wareneingang_quarantaene` (Standard aus). `erp_wareneingang_buchen()` mit Status 'auto' bucht dann alles frei, `erp_warenart_regeln()` meldet `quarantaene=false` (Haken „Freigegeben“ in der Lager-App steht auf frei).

Leerkapseln (04.11.2026): `erp_item_warenart_setzen($id, 'rohstoff')` lässt die Form Kapselhülle stehen (vorher gelöscht → Kapselgröße/Material verschwanden auf der Rohstoff-Seite). `erp_items_eingang()` liefert `kapselgroesse_id`, neu `erp_kapselgroessen()` und `erp_kapselgroesse_aus_name()` (Spiegel von `kapselgroesse_aus_name()`).

Soll-Kette 2c (04.11.2026): `erp_rezeptur_stamm()` – Bulk-Artikel hängen an der Stammrezeptur (G5). `erp_rezeptur_bulkitem()`, `erp_rezeptur_zu_position()`, Rezepturliste und Rezeptur-Scan suchen jetzt dort. Vorher fand die Lager-App bei einer Rezeptur-Variante keinen Bulk-Artikel.

Soll-Kette 2d (04.11.2026): `erp_rezeptur_kapselgroesse()` (gleiche Regel wie `rezeptur_kapselgroesse()`), `erp_bulk_kapselgroesse()`, `erp_charge_kapselgroesse_ermitteln()`/`_setzen()`, `erp_spalte_da()`. Einbuchen setzt die Größe, `erp_charge_auftrag_setzen()` rechnet sie mit dem Auftrag neu, Umbuchen einer Teilmenge übernimmt sie. `erp_charge_voll()` liefert `kapselgroesse`.

Soll-Kette 2b (04.11.2026): `erp_hinweis_neuer_artikel()` – jede im Wareneingang neu angelegte Position wird eine Benachrichtigung im Dashboard (Link auf die Charge), damit das Team Dubletten zusammenführt bzw. die Bestellung zuordnet.

Soll-Kette 3 (04.11.2026): `erp_bezug_sel()` / `erp_bezug_text()` – je Charge Kapselgröße, Auftrag mit Kunde (an der Charge oder über die Bestellposition) und aktive Reservierungen. Genutzt von `erp_bestand()` und `erp_charge_voll()`. `erp_erwartete_lieferungen()` liefert je Position `fuer_auftrag`.

Soll-Kette 6 (04.11.2026): `erp_karton_etikett_daten($pa)` – Produkt, Auftrag, Kunde und die Kartonwerte des PA.

Soll-Kette 7 (04.11.2026): `erp_versandarten()`, `erp_versandbereite_auftraege()` (Status erledigt, nicht alles versendet, freie Fertigware), `erp_auftrag_versandart_setzen()`, `erp_auftrag_fertig_chargen()`, `erp_dashboard_versand()` (Loopback `?p=api_versand`).

Gewicht & Bestand pro Gebinde (04.11.2026): `erp_charge_gebinde_mit_mengen($cid, $mengen)` legt je Karton ein Gebinde mit seiner echten Menge an (gewogen=1, Summe muss zur Charge passen). `erp_gebinde_entnehmen($gid, $menge, $grund)` bucht genau dieses Gebinde und die Charge um dieselbe Menge ab. `erp_gebinde_rueckgabe($gid, $ist, $anlass)` setzt das Gebinde auf das gewogene Ist-Gewicht (zurück in Lager 1, gewogen=1), die Charge um die Differenz (Verbrauch/Schwund) – Änderungslog + Bewegung. `erp_gebinde_abgleich()` zieht die Gebinde nach, wenn nur die Charge geändert wird: aufgerufen in `erp_charge_entnehmen` (abschaltbar per 3. Parameter), `erp_charge_menge_setzen`, `erp_charge_umbuchen`. `erp_charge_gebinde()` liefert zusätzlich `charge_status`, `charge_verfuegbar`, `fremd_kunde_id`.

Umlagern Lager 1 -> Lager 2 (04.11.2026): `erp_umlagern_kandidaten()` (freie Fertigware in Lager 1 mit Besitzer aus Auftrag/PA/Produkt, Anzeigename kundenspezifisch), `erp_umlagern_lager2($charge_id, $menge, $kunde_id)` (prüft frei/verfügbar/Fulfillment-Kunde, bucht über `erp_charge_umbuchen`, Bewegung Ausgang + Eingang). `erp_charge_umbuchen()` setzt jetzt auch `standort` passend zum Besitzer (lager2 bzw. lager1) – vorher blieb umgebuchte Ware auf Standort lager1.
- 08.11.2026 (Handbuch 4.5): `erp_erwartete_lieferungen()` zeigt auch Bestellungen mit Status `gesendet` (Portal, vom Lieferanten noch nicht bestätigt), nicht nur `bestellt`.

## Bestellung ↔ Charge (08.11.2026)
`erp_bestellung_lieferung_erfassen()` setzt `charge.bestellung_position_id` (über `erp_position_fuer_charge()`, Chargennummer tolerant über `core/charge_norm.php`). `erp_charge_bestell_dokumente($charge_id)` liest CoA/Spezifikation der Bestellposition – Spiegel von `charge_bestell_dokumente()` im Dashboard.

`erp_charge_werte_aus_bestellung($charge_id)`: Analysewerte des im Popup bestätigten CoA (`dokument.ki_daten.werte_bestaetigt`) beim Wareneingang an die Charge (`charge_analyse`), nur wenn sie noch keine hat.

## Wareneingang gegen Bestellung (10.11.2026)
- `erp_bestellung_lieferung_erfassen($bid, $charge_ids, $bp_je_charge)` nutzt dieselbe Stelle wie das Dashboard (`core/wareneingang_bestellung.php`, ohne core/schema.php).
- `erp_lieferung_positionen()` liefert je Position `bp_id`, `bp_nummer`, `bp_rest`, `kapsel`. Erledigte bzw. „Rest storniert“-Positionen fehlen. `erp_lieferung_per_tracking()` gibt dieselben Positionen.
- `erp_offene_bp_fuer_item($item, $lieferant)`: offene Bestellposition zu einem Artikel (Lieferschein-Scan, Rezeptur-Aufkleber, Etiketten).
- `erp_items_eingang()` gibt je Artikel Kapselgröße/Füllgewicht (`kapsel`) und Synonyme (`syn`) für die Auswahl mit.

**Versandbereit (Sofortfix 11.11.2026):** `erp_versandbereite_auftraege()` filtert die Aufträge mit Status „erledigt“ zusätzlich über das Dashboard (Loopback `?p=api_versand`, `aktion=fertig`, `ids=`): nur die, deren „Abgeschlossen“ wirklich erledigt ist (dieselbe Regel wie Dashboard und Kundenportal). Ist das Dashboard nicht erreichbar, bleibt die Liste ungefiltert und `$GLOBALS['erp_versand_ungeprueft']` zeigt einen Hinweis. `erp_dashboard_versand()` nimmt dafür ein viertes Argument `$extra` (zusätzliche POST-Felder).

**Gesperrt/inaktiv (Block C, 11.11.2026):** Lieferanten- und Kunden-Auswahl (`erp_lieferanten`, `erp_kunden_liste`, `erp_fulfillment_kunden`) ohne gesperrte.

**Zahlen (Block D, 11.11.2026):** `erp_menge_parse()`: Eine Tausendergruppe beginnt nie mit 0 – „0.045“ bleibt 0,045 (vorher 45).

## Paket 12.11.2026 Teil 5 (Gebindeaufkleber)
erp_la_scan() / erp_la_abschluss() / erp_charge_gebinde_anhaengen() / erp_la_aufgabe(): Scan der Lieferanten-Gebindeaufkleber (ein Scan = ein Gebinde, Charge beim ersten Scan, Chargen-Abgleich gegen CoA -> Quarantäne + Aufgabe, Abschluss mit Meldung an Nico und Aufgabe für Dustin).

## Aufkleber-Scan sicher (13.11.2026)
`erp_la_scan()` belegt den Aufkleber zuerst (`UPDATE ... WHERE status='aktiv'`, genau eine Zeile) und bucht erst dann: gleichzeitige Scans buchen nie doppelt. Scheitert das Buchen, wird der Aufkleber wieder frei. Gesperrt sind nur stornierte Bestellungen und stornierte Aufkleber (Meldung, nichts gebucht). Eine schon „geliefert“ geschlossene Bestellung (98-%-Regel) nimmt aktive Aufkleber weiter an: das letzte Gebinde und jede Überlieferung werden gebucht (13.11.2026, Fable-3 T1.6).

**Gesperrt/inaktiv (13.11.2026, Fable-3 T4.3):** `erp_rezeptur_liste()` ohne inaktive Rezepturen.

**Paket 13.11.2026:**
- `erp_lieferung_per_tracking()` sucht zusätzlich in den Paketnummern (`lieferung_paket`, erfasst im Bestell-Pop-up oder im Lieferantenportal). Das klappt auch, wenn der Scanner einen längeren Code liefert (ab 10 Zeichen reicht es, wenn die Paketnummer darin enthalten ist). So öffnet der Paket-Barcode direkt den Wareneingang der Bestellung.
- `erp_erwartete_lieferungen()` liefert je Lieferung `sendung` (über `erp_sendung_text()`, eine Kopie der Dashboard-Regel) und `pakete`.

**Ist-Gewicht nach Einheit (Fable-4 T1.4, 13.11.2026):** `erp_menge_einheit_lesen($s, $einheit)` – kg/g/l = `gewicht_lesen()` („24.850“ kg = 24,85 wie in der Werk-App), sonst `erp_menge_parse()`. `erp_gebinde_rueckgabe()` meldet `warnung` = true, wenn das Ist über der ursprünglichen Gebindemenge liegt (gebucht wird trotzdem, Meldung gelb).
