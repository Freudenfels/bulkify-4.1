# datenpruefung.php – Datenprüfung (nur Admin)

Route `?p=datenpruefung`, Menü System → Datenprüfung. Nur Admin (`route_rollen_map()` leer, zusätzlich `has_role('admin')` in der Seite).

Die Seite führt die Prüf-Abfragen aus der Liste „Nicos manuelle Live-DB-Aufgaben" (`nico-live-aufgaben-selects.md`) direkt im Dashboard aus. Oben steht eine Übersicht mit der Trefferzahl je Prüfung. Darunter folgt je Prüfung ein Block mit Überschrift, Erklärung, Treffer-Tabelle (mit „Öffnen"-Link, wo es eine Seite gibt) und der dokumentierten Korrektur als Text.

**Die Seite liest nur.** Beim Aufruf wird nichts geändert.

## Prüfungen
| Prüfung | Beheben |
|---|---|
| S-02 exklusive Produkte im Katalog sichtbar | ja: `p.exklusiv=1, p.kunde_id=r.kunde_id` |
| S-10 Bulk-Bestellpositionen in g/ml mit €/kg-Preis | ja: Menge (und bereits gelieferte Menge) / 1000, Einheit kg bzw. l |
| S-10 Pulver-Verbrauch als „Stk" gebucht | nein (keine Korrektur dokumentiert) |
| S-10 Pulver-Fremdpreise mit Einheit „Stk" | ja: `einheit='kg'` |
| Fertigware-Altchargen ohne Auftrag | ja: `auftrag_id` aus dem Produktionsauftrag (nur Zeilen mit Vorschlag) |
| S-16 Fremdlager-Altbestand: Chargen ohne Besitzer | ja (Freigabe Nico 03.11.): `fremd_kunde_id` = Kunde des Produkts, `standort='lager2'` |
| S-16 Aufträge „ins Fremdlager" ohne Lieferschein | ja (Freigabe Nico 03.11.): die **eine** Fertigware-Charge am Auftrag (direkt oder über dessen PA, gleiches Produkt) bekommt `fremd_kunde_id` = Kunde des Auftrags und `standort='lager2'`. Keine Buchung, kein Lieferschein. Keine oder mehrere Chargen bzw. Charge eines anderen Kunden = „nicht automatisch behebbar" (kein Haken). Aufträge, deren Chargen schon dem Kunden gehören, fallen aus der Liste (idempotent). |
| Zukauf „fertig" mit alten Schritten | nein (am Auftrag Zukauf-Art neu speichern) |
| Wer hat die Rolle QM (labor)? | nein (Warnung, wenn niemand) |
| Doppelte Nummern | nein (Einzelentscheidung, freigegebene Rechnungen nie umnummerieren) |
| Kundenetiketten ohne Artikelnummer | ja (Freigabe Nico 03.11.): Artikelnummer wie bei neuen Kundenetiketten, `naechste_nummer(item_prefix('verpackung'))` = VP-… unter der Nummern-Sperre, in ID-Reihenfolge; schon in `item` vergebene Nummern werden übersprungen (`dp_neue_vp_nummer()`); nummerierte Etiketten fallen aus der Liste |
| Protokoll der Kundenetikett-Migration | Anzeige von `app_meta migr_kundenetikett_protokoll` |
| Blocker 1 A: Katalogprodukt mit bedrucktem Kundenbeutel im Behälter-Slot | vorbereitet, **Knopf erst nach Freigabe** (`$bl1Frei`): `produkt.verpackung_id` = neutraler Basis-Behälter (Spalten „neutral“, „Herleitung“: `basis_item_id` / Notiz des Beutels / Angebot der Aufträge, „Aenderung“ von -> auf). Nicht eindeutig = nicht anwählbar. Nur der Slot, der Beutel bleibt am Auftrag. |
| Blocker 1 B: bedruckter Beutel ohne Besitzer | vorbereitet, **Knopf erst nach Freigabe** (`$bl1Frei`): `item.kunde_id` = Kunde der Aufträge mit diesem Beutel (`auftrag.verpackung_id`); mehrere Kunden oder kein Auftrag = nicht anwählbar. |
| Blocker 1 C: Katalogprodukt mit Kundenetikett im Etikett-Slot | nein, Kontrolle (Vorschlag `etikett_id = NULL`, Knopf erst nach Freigabe) |
| Etikett: Maß weicht vom Behälter-Endformat ab | ja: Maß + `etikett_vorlage_id` auf das Maß-Etikett des tatsächlichen Behälters ziehen (nur Zeilen mit passendem Maß-Etikett) |

## Links
„Öffnen" zeigt immer auf das Objekt der Zeile: Charge → `?p=chargen&id=` (Chargen-Detail), Produkt → Produkt, Bestellposition → Bestellung, Lieferanten-Preis → Rezeptur, Produktionsauftrag → PA, Benutzer → Benutzer, Kundenetikett → Verpackung. Zusätzlich sind verknüpfte Werte in der Tabelle anklickbar (Auftrags-Nr., PA, Charge-Nr.).

## Beheben
- Je Prüfung mit dokumentierter Korrektur gibt es einen Haken je Zeile (plus „alle") und den Knopf „Angehakte beheben" mit Bestätigung.
- Ausgeführt wird genau die angezeigte Korrektur, nur für die angehakten Zeilen. Die Bedingungen der Prüfung stehen immer mit in der WHERE, und die IDs werden vor dem Ausführen gegen die aktuelle Trefferliste geprüft. Eine veraltete Auswahl ändert also nichts Falsches.
- Technisch: `fix.ziel` = Spalte mit der ID des geänderten Datensatzes (Standard: die Zeile selbst), `fix.params` = Werte je Zeile (z. B. Kunde des Auftrags).
- Alles läuft in einer Transaktion. Jede geänderte Spalte wird mit alt/neu, Person und Route `datenpruefung` ins Feld-Änderungsprotokoll (`aenderung_log`) geschrieben. Ansicht: Protokoll → Feld-Änderungen.
- Fehlt auf einer Datenbank eine Spalte, zeigt die jeweilige Prüfung den Fehler an, statt die Seite abzubrechen.

## zeilen (Nachbereitung)
Optional `zeilen => fn(array $rows): array` ergänzt Spalten, die sich nicht in SQL rechnen lassen – z. B. der neutrale Basis-Behälter (`dp_neutral_herleitung()` über `verpackung_neutrale_basis()`). Beheben prüft gegen dieselbe nachbereitete Liste.

## Pulver: Portionen statt g je Packung (03.11.2026)
- **pulver_kundenpreis:** `produkt_kundenpreis` bei Pulver/Granulat, deren `menge_pro_vpe` kleiner als die Füllmenge des Produkts ist UND Menge × Portionsgröße (Summe Rezeptur-mg) höchstens 25 % neben der Füllmenge liegt, also eine Portionszahl (v3), z. B. 31 statt 275 g. Beheben je Zeile: `menge_pro_vpe = produkt.einheiten_pro_packung`, nur wenn der alte Wert noch steht. Nicht anwählbar, wenn es die Zielgröße für Produkt + Kunde schon gibt.
- **pulver_auftrag:** dieselbe Regel für `auftrag.stueck`, nur Anzeige. Korrektur über das PA-Panel „Füllmenge je Packung (Pulver)“ (setzt Produkt, Auftrag und PA).

`pulver_auftrag` ist seit 03.11.2026 behebbar: Fix-Typ `aktion` (eigene Korrektur je Zeile über mehrere Tabellen, protokolliert selbst) → `pulver_auftrag_fuellmenge_korrigieren()` setzt auftrag.stueck und die PA-stueck auf die Füllmenge des Produkts, nur wo noch die alte Zahl steht.

**kundenetikett_fehlt** (03.11.2026): je Produkt + Kunde (Zeile = jüngster Auftrag) Aufträge mit Etikett ohne Kundenetikett-Artikel. Beheben (`aktion`) ruft `auftrag_kundenetikett_sicherstellen()` für alle Aufträge dieses Produkts + Kunden auf: v1 wird angelegt, ein freigegebenes Design übernommen, offene Aufträge gebunden. Danach findet die Lager-App „Etiketten einbuchen“ das Produkt für diesen Kunden. Bestand auf dem allgemeinen Maß-Etikett bleibt dort.

**produkt_name_behaelter** (03.11.2026): Produkte, deren Name einen anderen Primärbehälter nennt als im Slot steht (längster Treffer je Produkt; bedruckte Kundenbehälter ausgenommen). Beheben (`aktion`) zieht den Namen auf den hinterlegten Behälter. Ist der Behälter falsch, am Produkt korrigieren.

**auftrag_name_behaelter** (03.11.2026): Aufträge (nicht storniert), deren gespeicherter Name einen anderen Primärbehälter nennt als der Behälter des Auftrags (eigener, sonst vom Produkt). Beheben (`aktion` → `auftrag_name_behaelter_nachziehen`) tauscht nur den Behälter-Teil. Ist der Behälter am Auftrag falsch: im Auftrag unter Verpackung korrigieren, der Name zieht dann selbst mit.

**pa_auftrag_storniert** (03.11.2026): aktive Produktionsaufträge (Vorbereitung, offen, laufend) zu einem stornierten Auftrag, z. B. ein vor dem 07.10. umgewandeltes Kontingent. Die Spalten zeigen Kontingent, erledigte Schritte und offene Bestellungen am Auftrag. Beheben über `aktion` → `pa_storno_nachziehen`, nur ohne erledigten Schritt. Bestellungen beim Lieferanten bitte selbst im Einkauf prüfen.

**etikett_mass_abweichung** (03.11.2026): aktive Kundenetikett-Artikel (`item.verpackung_rolle='etikett'`, `produkt_id` gesetzt, nicht gesperrt), deren eigenes Maß (`breite_mm`/`hoehe_mm`, sonst `etikett_final`) um mehr als 2 mm vom Endformat des **tatsächlichen Produkt-Behälters** abweicht. Vergleich in `zeilen`: `produkt_etikettmass()` (jüngster Auftrags-Behälter mit Vorrang, sonst Produkt-Behälter) gegen das normierte Etikett-Maß (`[groß, klein]`, Orientierung egal), Toleranz 2 mm. Ursache war der frühere `kundenetikett_vorlage()`, der das Maß vom rohen `produkt.verpackung_id` statt vom echten Behälter nahm (z. B. 200-ml-„67 x 188" statt 150-ml-„60 x 172"). Beheben (`aktion`) zieht `breite_mm`/`hoehe_mm`/`etikett_format`/`etikett_final`/`etikett_vorlage_id` auf das passende Maß-Etikett des Behälters (`kundenetikett_vorlage()`), protokolliert selbst; das aufgedruckte Design (`etikett_druck`) bleibt. Zeilen ohne passendes Maß-Etikett zum Behälter sind nicht anwählbar. Aufträge werden nicht angefasst – ist der Behälter falsch, am Produkt/Auftrag korrigieren.

**etikett_mass_trimbox** (08.11.2026, nur Info): aktive Etikett-Artikel mit Design (`etikett_dokument_id`). Vergleicht das gespeicherte Maß (`breite_mm`/`hoehe_mm`, Ausrichtung egal) mit dem Endformat der PDF-Datei laut `pdf_etikett_mass()` (TrimBox, sonst ArtBox, sonst MediaBox), Treffer ab 1 mm Abweichung. Spalten: gespeichert, laut PDF, Quelle (Box), Druckdatei (MediaBox), Abweichung. Kein Beheben – Nico passt die Maße selbst an. Neue Designs übernehmen das Maß bei der Freigabe automatisch (`etikett_mass_aus_pdf_setzen()` in `kundenetikett_nach_freigabe()`).

**bulk_eingegangen_ohne_charge** (04.11.2026): Bestellungen mit Bulk-Freitext für einen Auftrag, die eingegangen/geliefert sind, ohne Bulk-Charge am Auftrag. Nur Anzeige; einbuchen in der Lager-App oder Charge am Auftrag binden.

**rohstoff_leerkapsel_form** (04.11.2026): Rohstoffe, die nach Leerkapseln aussehen (Name/Kapselgröße), ohne Form Kapselhülle. Beheben setzt Form + Kapselgröße aus dem Namen (aenderung_log).

**pa_schritt_produktionsfreigabe** (04.11.2026): offene Schritte „Produktions-Freigabe“ aktiver Produktionsaufträge. Beheben hakt sie als „entfällt (Soll-Kette 2e)“ ab (nichts gelöscht, nichts gebucht), aenderung_log.

## Soll-Kette 8: Einmal-Bereinigungen (04.11.2026)
- **quarantaene_wareneingang**: Chargen aus dem Wareneingang in Quarantäne (Altbestand, ohne eigene Fertigware) mit Artikel, Charge, Menge, Eingang, eingebucht von, Lieferant. Beheben = freigeben (`charge_quarantaene_freigeben`), protokolliert.
- **charge_ohne_kapselgroesse**: Kapsel-Bulk und Leerkapseln ohne Kapselgröße. Anwählbar, wenn ableitbar; Beheben trägt nach. Der Rest ist wirklich unklar (nur diese Kisten prüft das Lager).
- **bulk_artikel_lose**: lose Bulk-Artikel ohne Rezeptur mit Vorschlag (Auftrag der Chargen, sonst Rezepturname). Beheben führt zusammen (`item_zusammenfuehren`), nur bei eindeutigem Ziel und gleicher Einheit.
- Schritt „Produktions-Freigabe“: siehe `pa_schritt_produktionsfreigabe`.

**fertigware_quarantaene** (Soll-Kette 5, 04.11.2026): eigene Fertigware, die noch nach alter Regel in Quarantäne steht. Beheben gibt frei (inkl. Bericht/CoA wie bisher).

Gewicht & Bestand pro Gebinde (04.11.2026): `gebinde_gleich_verteilt` listet Gebinde mit gleichmäßig verteiltem Gewicht (gewogen=0, ohne Etikettenrollen, mehr als ein Gebinde) – Öffnen führt auf die Lager-Scan-Seite zum Nachwiegen, Beheben bestätigt nur das angezeigte Gewicht. `gebinde_summe_abweichung` listet Chargen, deren Bestand nicht zur Summe der Gebinde passt; Beheben gleicht die Gebinde an (`charge_gebinde_abgleich`). Beides im Änderungslog.

## Fortschritt „Rohstoff bestellt“ ohne echte Bestellung (08.11.2026)
Prüfung `rohstoff_bestellt_ohne`: Aufträge mit manuell gesetzter Stufe `phase_override=1` („Rohstoff bestellt“), offen, ohne echte
Bestellung zum Auftrag (Position mit auftrag_id oder Verlaufseintrag ref_typ=bestellung; Bestellstatus gesendet/bestellt/geliefert
oder angekommen). Beheben setzt `phase_override` auf NULL (protokolliert). Hintergrund: Arbeitsanweisung „Auftragsseite entschlacken“ Teil 0.3.
- 08.11.2026 (Handbuch 10.2): neuer Punkt `rohstoff_bestellt_ohne_hoeher` – Aufträge mit von Hand gesetzter Stufe ab 2 (phase_override >= 2) ohne echte Bestellung, **nur Anzeige** (kein Beheben). Der bisherige Punkt `rohstoff_bestellt_ohne` (= 1) behebt wie gehabt.

## Produktseite – Widersprüche (09.11.2026)
- **prod_entwurf_verwendet:** Produkt im Status Entwurf, aber in Angeboten/Aufträgen/Rechnungen. Beheben setzt auf „aktiv" (Auswahl je Zeile, Protokoll).
- **prod_glas_ohne_deckel:** Behälter ohne Verschluss (Beutel/Sachet/Stick/Blister ausgenommen). Nur Anzeige.
- **kundenpreis_packgroesse:** Kundenpreis mit anderer Menge je Packung als das Produkt (z. B. 60 bei 90 Kapseln). Nur Anzeige – Nico entscheidet je Fall (andere Packungsgröße = eigenes Produkt).
- **kundenpreis_abweichung:** Kundenpreis weicht mehr als 25 % vom Betrag je Packung der letzten Rechnung ab (Rechnung inkl. Verpackung). Nur Info.

## Einkauf-Altlasten (10.11.2026, Einkauf gerade ziehen Teil 4)
Neue Prüfpunkte ganz oben:
- **offene Bestellung ohne Wareneingang**: älter als 14 Tage, mit Spalte „Vorschläge“.
- **Bestellung „eingegangen“ ohne verknüpfte Charge**: mit „Vorschläge“.

Bearbeitet werden beide an der Bestellung im Block „Wareneingang zuordnen“.

**Seit 11.11.2026 direkt in der Zeile** (Prüfungs-Schlüssel `'abgleich' => true`, Spalten über `'html'`):
- **Inhalt**: je Position Artikel/Produkt mit Menge, dazu Auftrag (AB verlinkt) und Kunde; die ersten zwei sichtbar, Rest aufklappbar („… und N weitere“). `bestellung_abgleich_inhalt()`.
- **Lieferant** leer → roter Hinweis „Lieferant fehlt“.
- **Vorschläge**: je Position Charge (Link), Menge, Eingang, Gründe und Knopf **verknüpfen**. Bei mehreren Vorschlägen aufklappbar; bei gleichen Mengen „mehrere passende Chargen - bitte wählen“. Wird dieselbe Charge bei mehreren Bestellungen vorgeschlagen (über beide Prüfungen), steht rot „Charge auch bei BE-… vorgeschlagen“.
- **Knöpfe je Bestellung**: als geliefert schließen, Rest storniert, stornieren (nur solange nicht eingegangen), jeweils mit Rückfrage. „Öffnen“ bleibt.
- Die Knöpfe senden per AJAX (`aktion=dp_abgl`, Antwort JSON aus `bestellung_abgleich_aktion()`); bei Erfolg verschwindet die Zeile, die Meldung steht über der Tabelle. Gleiche Funktionen wie an der Bestellung (`webe_*`, `bestellung_stornieren`), Verknüpfen landet im Änderungsprotokoll (Quelle `datenpruefung`) und im Verlauf der Bestellung.
- **„Alle eindeutigen verknüpfen (N)“** über der Tabelle: nur Positionen mit genau einem Vorschlag, gleicher Artikel, Menge passt, ohne Konflikt. Vorher Bestätigung mit der Liste der Paare. Der Server prüft jedes Paar erneut (`aktion=dp_abgl_sammel`) und überspringt, was nicht mehr eindeutig ist.

Weitere Prüfpunkte:
- **nachträglich angelegte Bestellung prüfen**: Beheben = als geprüft markieren.
- **alte Entwürfe**: Beheben = stornieren.
- **ohne Lieferant / „extern“**: nur Anzeige.
- **ohne Position**: Beheben = stornieren.
- **Wareneingang ohne Bestellung** (Muster/Sonstiges/Kundenware): nur Anzeige.

- Prüfpunkt **„Artikel: ähnliche Artikel (Dubletten-Gruppen)“**: Liste der Gruppen. Öffnen führt zur Seite „Artikel zusammenführen“ (Teil 5).

- Prüfpunkt **„Rohstoff: klingt nach Mineral/Vitamin, aber ohne Wirkstoffdaten“** (10.11.2026): in Rezepturen verwendete Rohstoffe mit Salz- bzw. Mineral- oder Vitaminnamen ohne `item_wirkstoff`. Ohne diese Daten fehlen elementare Werte und NRV in Rezeptur, PIB und Portal. Nur Anzeige.
- Prüfpunkt **„Rohstoff: beim Lieferanten angefragt, aber keine Dokumente“** (10.11.2026): Rohstoffe mit Preisanfrage (`lieferant_anfrage.item_id`), aber ohne Spezifikation, CoA oder Analyse am Rohstoff. Spalten:
  - Anfragen, Lieferanten, Antworten (Angebote), letzte Anfrage,
  - CoA angefordert, Rezepturen, CoA an Bestellung.

  Sortiert: in Rezepturen verwendete und beantwortete zuerst. Nur Anzeige.
- Prüfpunkt **„Rohstoff: in Kunden-Anfragen bzw. offenen Rezepturen, aber ohne Lieferanten-Dokumente“** (10.11.2026): Rohstoffe aus Rezeptur-Wunschzeilen, Portal-Rohstoffanfragen und Rezepturen im Entwurf oder Vorschlag ohne Spezifikation, CoA oder Analyse am Rohstoff. Zeigt außerdem, ob schon eine Preisanfrage beim Lieferanten läuft. Nur Anzeige.

## Fortschritt von Hand weiter als die echten Mengen bzw. das Labor (11.11.2026)
Die Prüfung `fortschritt_hand_weiter` läuft **nur auf Klick** (`?p=datenpruefung&laden=fortschritt_hand_weiter`), weil sie je Auftrag den Fortschritt rechnet.
- **Welche Aufträge:** nicht versendet bzw. storniert, keine Dienstleistung, manuelle Stufe ab „Produziert“ oder Status „erledigt“, nicht als „so lassen“ markiert. Davon die, bei denen entweder Menge fehlt (0 < gebucht < Ziel) oder das Labor noch nicht abgeschlossen ist.
- **Spalten:** Auftrag, Kunde, Schritt (von Hand), Ist/Soll Stk, Labor.
- **Knöpfe:**
  - **Zurücksetzen:** manuelle Stufe weg, Status „erledigt“ wird „in_produktion“.
  - **Rest storniert:** `fortschritt_ziel` = gelieferte Menge, Benachrichtigung „Rechnung/Gutschrift prüfen“. Nur bei offener Menge.
  - **So lassen:** `fortschritt_geprueft=1`, die Zeile verschwindet.
  Alles wird protokolliert.

**Technik:**
- Neu sind `fixes` (mehrere Knöpfe je Prüfung, Knopfname `fix`) und `auf_klick` (Prüfung erst auf Anforderung laden).
- Nach dem Beheben wird die Prüfung wieder geladen angezeigt.
