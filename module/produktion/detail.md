# produktion/detail.php – Produktionsauftrag (Stationen/Gates)

**Zweck:** Ein Produktionsauftrag wird hier Station für Station abgearbeitet – bis zu den beiden Freigabe-Gates.

**Produktionsbericht:** Kopf-Button **„Produktionsbericht"** (`?p=produktion_bericht`) öffnet das druckbare Herstellprotokoll mit allen Infos, das sich auch für den Kunden freigeben lässt (siehe [bericht.md](bericht.md)). Wer einen Schritt abschließt, wird in `produktion_schritt.erledigt_von` festgehalten.

## Seitenaufbau (Reihenfolge – „erst das Wichtige")
Die Seite ist bewusst **von oben nach unten nach Relevanz** sortiert, damit ein Mitarbeiter nicht durch Einkaufs-/Material-Panels scrollen muss, bevor er sieht, was zu tun ist. Alle Panels werden im Code in Puffer (`ob_start`/`ob_get_clean`) gesammelt und dann geordnet ausgegeben:
1. **Kennzahl-Kacheln** (Zustand · Produkt · Menge · Planung/Charge) + Charge/MHD-Hinweis.
2. **Weg-Hinweis** (verkürzter Weg bei Zukauf / voller Weg) – nur solange nichts erledigt.
3. **Wartet auf Material** – Alarm-Panel, nur wenn Bestand fehlt.
4. **Jetzt dran** – der eine aktuelle Schritt groß mit Button (und Scan-Feld).
5. **Ablauf** – die komplette Stationsliste als Checkliste (erledigt/jetzt/wartet).
6. **Fertigware eingebucht** + **Teilmenge einbuchen** (wenn relevant).
7. **Zusammensetzung je Einheit** (Rezeptur).
8. Einklappbar `<details class="bx-sek">` **„Material, Einkauf & Beschaffung"** (Materialbedarf/entnommen · Einkaufsbedarf · Bestellungen) – bei Zukauf meist leer, deshalb zugeklappt und aus dem Weg.
9. Einklappbar `<details class="bx-sek">` **„Wareneingänge, Etikett & Details"**. Das **Etikett-Panel** zeigt die **Kundenfreigabe**-Pflicht: freigegeben (ok) bzw. „nicht freigegeben – gesperrt" / „Design fehlt – gesperrt". Ohne Freigabe sind Etiketten nicht bestellbar und die Produktion nicht machbar. Team kann eine extern (Mail/Telefon) erteilte Freigabe manuell bestätigen (`aktion=etikett_freigeben_team` → `etikett_freigabe_setzen(..., 'team')`). Upload/Löschen eines Designs setzt die Freigabe zurück.

Die eingeklappten Sektionen (`.bx-sek`) haben einen eigenen seitenlokalen `<style>` (Dreieck-Marker, grüne Zusammenfassung). So bleibt die Seite ruhig, ohne dass Funktionen verloren gehen – jedes alte Panel (inkl. aller POST-Handler) steckt weiterhin drin, nur neu angeordnet.

**Alle Infos direkt auf der Detailseite:** Die Seite zeigt zusätzlich zur Ablaufliste ein **Zusammensetzung**-Panel (Rezeptur-Zutaten je Einheit + Füllgewicht), bei **Entnommene Materialien** je Zeile **Lieferant + Datum**, und in der Stationsliste je erledigtem Schritt **Zeit · Bearbeiter · gescannte Charge**. So sieht man beim Klick auf den Auftrag alles, nicht nur im Bericht.

**Stationen (je Darreichungsform):** Rohstoffe bereitstellen · Mischen · **Herstellung** (Verkapselung / Tablettierung / Abfüllung … je Form) · Verpacken · Etikettieren · Qualitätsprüfung · **Produktions-Freigabe** (Gate) · **Versand-Freigabe** (Gate). Definiert in `produktionsschritte_fuer()`.

**Was passiert hier:**
- **POST `aktion=erledigen`:** markiert die **nächste offene** Station als erledigt (Reihenfolge erzwungen – nur die erste offene Station hat einen Button). Danach wird der Status neu berechnet (offen / läuft / fertig). Ist alles erledigt (auch die Versand-Freigabe), wird der zugehörige **Auftrag auf „erledigt"** gesetzt und ein Verlaufseintrag am Kunden geschrieben.
- **God-Mode – Produktion auf Stufe setzen (nur Admin, POST `aktion=godmode_stufe`):** ein Panel mit je einer Schaltfläche pro Schritt (+ „Anfang (alles offen)"). Ruft `produktion_godmode_stufe_setzen($pa_id, $schritt_id)` → setzt alle Schritte **bis einschließlich** der gewählten Stufe auf erledigt, alle danach auf offen, und leitet den **PA-Status** ab (offen/laufend/erledigt). Überspringt die `vorbereitung`-Weiche und **jeden Gate/„Zaun"** (z. B. „kein Glas da"), **ohne** Bestandsprüfung, **ohne** Material-Abbuchung, **ohne** Lager-Übergabe und **ohne** den Auftrag/die Fertigware anzufassen. Zweck: eine Produktion auf den richtigen Stand bringen, wenn man den echten Bestand (Gläser/Kapseln) nicht verbrauchen will. `schritt=0` = alles wieder offen. Admin-Check in der Funktion. (Ersetzt den früheren „Alles durchlaufen"-God-Mode.)
- **Anzeige:** Kennzahlen (Status, Fortschritt X/N, **Packungen gesamt** = `menge`, **… je Packung** = Stück/Kapseln je Packung via `produktion_stueck_je_packung()` (Produkt `einheiten_pro_packung`, sonst Rückfall auf `auftrag.stueck` – v3-Importe tragen die Zahl oft nur am Auftrag), **… gesamt** = Packungen × je Packung; das Wort ist „Kapseln"/„Tabletten"/„Stück" je Darreichungsform, Karten ohne Wert entfallen), Produkt) und die Stationen als Ablaufliste:
  Alle Kennzahl-Werte (`.bx-cards .v`) laufen auf einer einheitlichen, ruhigen Größe (15px, wie „Produkt") – ein seitenlokaler `<style>` überschreibt das globale große `--fs-xl` nur hier, damit die Zeile sauber wirkt; Badges behalten ihre eigene Größe. Ist ein Auftrag verknüpft (`pa.auftrag_id`), führt ein **Kopf-Button „Zum Auftrag <Nr.>"** und eine **Kachel „Auftrag"** (neben „Kunde") direkt auf `?p=auftrag&id=…` – immer sichtbar, nicht nur im Mengen-Abgleich-Warnpanel. Die Kacheln sind sinnvoll gruppiert: **Zustand** (Status, Bereitschaft, Fortschritt, Priorität) → **Produkt** (Produkt – bei v3-Aufträgen ohne verknüpftes Produkt der Fallback-Name `auftrag.produkt_bezeichnung` mit Zusatz „(aus v3)"; Kapsel/Tablette, Art) → **Menge** (Packungen/… je Packung/… gesamt) → **Planung/Charge** (Geplant am, Charge, MHD). erledigte mit Häkchen + Zeit, die aktuelle mit Button, kommende ausgegraut. Freigaben sind als **Gate** markiert.

**Bulk-Produktionsaufträge (nur Kapseln, ohne Verpackung):** hängen an einer **Rezeptur** statt an einem Produkt (`produkt_id` NULL, `rezeptur_id` gesetzt, `pa_ist_bulk()`). Die Karte heißt dann **„Rezeptur (Bulk)"** und zeigt den Rezepturnamen mit „· Bulk"; Form/Stück-Wort kommen aus der Rezeptur. Stationen ohne Verpacken/Etikettieren, Materialbedarf nur Rohstoffe + Leerkapseln, Fertigware wird über `produktion_teilmenge_einbuchen()` auf das `fertig`-Bulk-Item der Rezeptur (`rezeptur_bulkitem`) gebucht. Angelegt über den Button „+ Neuer Produktionsauftrag" auf der Liste (Modus „Nur Kapseln").

**Herkunft:** Kundenaufträge werden von `auftrag_aus_angebot()` zusammen mit Auftrag + Rechnung automatisch erzeugt (Stationen aus der Darreichungsform der Rezeptur); Lager-/Bulk-Aufträge legt man von Hand auf der Produktions-Liste an.

**Materialbedarf & Bestand (FEFO):**
- Panel **Materialbedarf** zeigt je Rohstoff benötigte vs. verfügbare Menge. Kapsel/Tablette: benötigt = Zutat-mg × Einheiten/Packung × Menge. **Pulver/Granulat: nach Gewicht** – benötigt = Gesamt-Füllgewicht (Packungen × g/Packung) × Massenanteil der Zutat (Zutat-mg ÷ Summe der Rezeptur-mg). „fehlt X" wenn zu wenig. Siehe Abschnitt „Pulver nach Gewicht".
- **Rohstoffbedarf nur auf dem vollen Weg:** Der Panel „Materialbedarf" (Rezeptur-Rohstoffe) erscheint nur, wenn der Auftrag die Herstellungsschritte enthält (`$istVollerWeg`). Bei **Fremdproduktion** (zugekaufte Bulkware) werden die Rohstoffe nie angefasst – sie dort als „fehlt" auszuweisen führte zu unnötigen Bestellungen und ließ den Auftrag blockierter aussehen, als er ist. Bereits **entnommene** Chargen bleiben immer sichtbar (Rückverfolgung), auch wenn der Weg später umgestellt wurde.
- Die Station **„Rohstoffe bereitstellen" ist ein Gate**: Abschluss blockiert, solange ein Rohstoff fehlt (Warnung „Nicht genug Bestand"). Erst Wareneingang buchen.
- **Kundeneigene Verpackung (Beistellung):** wird am Auftrag als „vom Kunden beigestellt" markiert (Reiter Verpackung), entsteht ein **auftragseigener Lagerartikel** (`item.kundeneigen=1`, `auftrag_id` gesetzt, nicht einkaufbar). Er verhält sich hier **wie jeder andere Verpackungsartikel**: blockiert als Fehlbestand, bis er im Wareneingang gebucht ist – taucht aber nicht unter „zu bestellen"/in den auto-angelegten Bestellungen auf (`auftrag_fehlbedarf()` überspringt `kundeneigen`). Siehe `module/auftrag/detail.md`.
- Beim erfolgreichen Abschluss werden die Rohstoffe **nach FEFO** (älteste MHD zuerst) aus den freien Chargen entnommen (`produktion_rohstoffe_entnehmen`), der Bestand sinkt, leere Chargen werden „leer". Jede Entnahme ist chargengenau in `produktion_verbrauch` protokolliert und erscheint als **„Entnommene Materialien"**.
- Die Station **„Verkapselung"** (nur Hartkapsel-Produkte) bucht die **Leerkapseln** ab (Menge × Einheiten je Packung = Gesamt-Kapseln, `produktion_kapseln_entnehmen`, FEFO, Gate bei zu wenig Bestand). Welche Leerkapsel: `produkt_leerkapsel_id()` – manuelle Wahl am Produkt hat Vorrang, sonst automatisch die eindeutige Kapsel der passenden Größe. Der Bedarf erscheint im Materialbedarf als Extra-Zeile mit Badge „Kapselhülle" (separat berechnet, damit nicht doppelt bei „Rohstoffe bereitstellen" abgebucht wird).
- Die Station **„Verpacken"** bucht analog die **Verpackung** ab (1 Gebinde je Packung, `produktion_verpackung_entnehmen`, FEFO, ebenfalls Gate bei zu wenig Bestand).
- **Abbuchung Etikett + Deckel (Paket Etiketten, Teilfix 4):** „Verpacken" bucht jetzt **Behälter UND Deckel** (`produkt.verschluss_id`) ab, „Etikettieren" das **Kundenetikett des Auftrags** (`produktion_etikett_entnehmen`) – vorher blieben beide „zur Info", nach 4.000 Packungen standen weiter 5.000 Etiketten im Bestand und alle Rollen „voll". Der Behälter wird PA → Auftrag → Produkt aufgelöst (bedruckter Kundenbehälter). **Alles-oder-nichts** je Schritt (fehlt irgendein Artikel, wird nichts gebucht und der Schritt meldet den Mangel), **idempotent** je (PA, Artikel), FEFO, 1 je Packung. Etikettenrollen (`charge_gebinde` mit Inventarnummer) werden um die entnommene Menge verringert (dem PA/Auftrag zugeordnete zuerst, dann angebrochene, dann volle; Status voll → angebrochen → leer). **Ersatzartikel:** hat das Kundenetikett (noch) keinen Bestand, das generische Maß-Etikett des Behälters aber genug (Altbestand aus der Zeit vor dem Kundenetikett-Fix), wird davon gebucht und im Verlauf als „Ersatzartikel" vermerkt – damit laufende Aufträge nicht stehen bleiben. Pro Abbuchung ein Verlaufseintrag am Auftrag. Gleiche Logik in der Werk-App (`erp_verpackung_entnehmen`/`erp_etikett_entnehmen`, `produktion/core/erp.php`). Offen: Beipackzettel („Beipackzettel beilegen") und Umkarton werden weiterhin nicht abgebucht; die Rollenwahl in der Werk-App („welche Rolle nimmst du") und die anteilige Abbuchung bei Teilmengen gehören zu den Paketen Rollen/Teilproduktion.
- Ist der Auftrag **fertig** (Versand-Freigabe erledigt), wird die **Fertigware als Bestand eingebucht** (`produktion_fertigware_einbuchen`): ein Lager-Artikel der Kategorie „Verkaufsfertig" zum Produkt (`produkt_lageritem`) bekommt eine Charge (Charge-Nr. = PR-Basis + Buchstabe, z. B. `2696.A`; Menge = der noch **offene Rest** der Produktionsmenge). Panel „Fertigware eingebucht" mit allen Chargen und Link zum Lager. Der Auftrag geht auf „erledigt".

## Adaptiver Produktionsweg (Baustein 5)

Der Weg richtet sich nach der **Beschaffung**: Ist für den Auftrag **fertige Bulkware** eingegangen (Charge eines Items der Kategorie `fertig`, `produktion_ist_zukauf($auftrag_id)`), entfallen **Rohstoffe bereitstellen, Mischen und Verkapseln** – die Route ist dann `Fertigware bereitstellen · Verpacken · Etikettieren · Qualitätsprüfung · Produktions-Freigabe · Versand-Freigabe` (aus `produktionsschritte_fuer($form, $zukauf=true)`).

Auf der PA-Detailseite erscheint bei erkanntem Zukauf ein **Banner „Fertige Bulkware erkannt – verkürzter Weg möglich"** mit Button **Verkürzten Weg anwenden** (`aktion=weg_zukauf`). Umgekehrt kann auf den vollen Weg zurückgestellt werden (`aktion=weg_voll`). Beides über `produktion_schritte_regenerieren($pa_id,$zukauf)` – nur solange **kein Schritt erledigt** ist (sonst Hinweis). Kein manuelles Make-or-Buy-Flag nötig; die Info steckt in Kategorie/Form der eingegangenen Ware.

### Nachbuchen: komplett versendet (06.11.2026)
Für den Fall PR-2746: Die Ware ist längst beim Kunden, im System wurde beim Abhaken (God-Mode/Fortschritt) aber nichts gebucht. Der Rest war nie eingebucht, die Fertigware stand noch frei im Lager, und das Material war nie abgebucht.
Nur für **Admin**. Das Panel **„Schon an den Kunden versendet? Bestand nachbuchen“** erscheint, wenn der Auftrag auf „versendet“ steht oder die Produktion fertig abgehakt ist und noch ein Rest offen ist (auch wenn der Auftrag wieder auf „In Produktion“ gestellt wurde). Es kommt nur dann, wenn wirklich etwas fehlt.
Knopf **„Nachbuchen: komplett versendet“** (`aktion=nachbuchen_versendet`, optional Haken „Lieferschein nachträglich anlegen“) ruft `produktion_nachbuchen_versendet()`:
1. Der offene Rest wird als Charge (.B …) eingebucht.
2. Abgebucht werden fertige Kapseln, Gläser/Deckel und Etiketten, **keine Rohstoffe**. Es wird gebucht, was im Lager ist. Was fehlt, steht in der Meldung und im Verlauf und blockiert nichts.
3. Die Fertigware dieses Auftrags wird ausgebucht, `versendet_menge` = Menge, der Status wird „versendet“ (letzte Stufe).
4. Optional entsteht ein Lieferschein, ohne Mail.
Keine Kunden-Mail, die Rechnung bleibt unverändert. Ein zweiter Klick meldet „Es ist schon alles nachgebucht.“ Nicht für Fulfillment-Kunden (Lager 2) und nicht für Bulk-Produktion.

### Nachträglich auf fertige Kapseln umstellen (06.11.2026)
Wurde mit fertigen Kapseln produziert (aus dem Lager oder zugekauft), der Auftrag steht aber noch auf dem Eigen-Weg, verlangt die Teilmenge sonst Rohstoffe. Solange noch Herstellungsschritte offen sind (Rohstoffe bereitstellen, Mischen, Verkapselung …), steht oben das Feld **„Auf fertige Kapseln umstellen“** – auch wenn schon ein Schritt erledigt oder für den Auftrag bestellt wurde. Es nutzt dieselbe Aktion `weg_zukauf`: zuerst wie bisher `produktionsauftrag_art_setzen()`; geht das nicht, ruft die Seite `produktion_weg_fertigkapseln()` (`core/schema.php`):
- löscht nur die **offenen** Herstellungsschritte, erledigte bleiben,
- fügt an ihrer Stelle **„Fertigware bereitstellen“** ein (falls noch nicht da),
- setzt `produktionsart='fremd'` – Bedarf und Teilmenge rechnen dann mit der fertigen Bulkware statt mit Rohstoffen,
- lässt Bestellungen unangetastet und schreibt einen Verlaufseintrag am Auftrag.
Nicht bei Bulk-Produktion und nicht bei fertigen/stornierten Aufträgen. Meldung `wegfk` bzw. `wegfkfehler`.

### Rückabwicklung: Fremd → Eigen („Doch selbst produzieren")
Wenn der Lieferant die Fremdfertigung doch nicht macht: Panel **„Doch selbst produzieren (Rückabwicklung)"** (nur bei `produktionsart='fremd'`, offener **Bulk-Bestellung** für den Auftrag und `done=0`). `aktion=rueckabwicklung` ruft `produktion_rueckabwicklung_eigen($pa_id, $wer)` (`core/schema.php`):
- storniert **nur die Fremd-Bulk-Bestellung(en)** des Auftrags (Position `item_id IS NULL`, Bezeichnung `Bulk…`) – Verpackung/Etikett-Bestellungen bleiben,
- informiert den Lieferanten per `nachricht_senden()` (Portal-Chat + Mail),
- stellt per `produktion_schritte_regenerieren($pa_id, false)` auf den **vollen (Eigen-)Weg** um und setzt `produktionsart='eigen'` (umgeht bewusst die coarse Bestell-Sperre aus `produktionsauftrag_art_setzen`, behält aber den „kein Schritt erledigt"-Schutz),
- `bedarf_bump()` → der Rohstoffbedarf erscheint im Einkauf.

**Blockiert**, wenn schon ein Schritt erledigt ist oder **Fertigware (Bulk) eingebucht** / eine Bulk-Bestellung bereits (teil)geliefert/angekommen ist (Flash `rueckfehler`). Erfolg: Flash `rueck`. Derselbe Knopf steht auch im Auftrag-Detail (Reiter Produktion, nur Admin/Fremd) und leitet das Ergebnis auf die PA-Seite.

## Produktionsbereitschaft (Baustein 3)

`produktion_bereitschaft($pa_id)` prüft, ob das Material komplett da ist: bereit | wartet | laeuft | fertig. Rohstoffe (`produktion_materialbedarf`) + Leerkapseln, bei Zukauf stattdessen die freie fertig-Charge. Kachel „Bereitschaft" + Panel „Wartet auf Material" (Fehlliste benötigt/verfügbar/fehlt). Auch in Liste/Cockpit als Badge (`bereitschaft_badge()`) + Filter „Nur produktionsbereite".

## Einfacher Abhak-Modus (ohne Scan) – Detailseite

Die Detailseite läuft bewusst **ohne Charge-Scan** (es gibt noch keine Etiketten/Barcodes zum Scannen). Zwei Wege, einen Schritt abzuschließen – beide gleich:
- Panel **„Jetzt dran – Schritt x von n"** mit Klartext-Anweisung (`station_anleitung()`, der Scan-Satz wird für die Anzeige herausgefiltert) und einem Knopf **„Erledigt"** (bzw. **„Freigeben"** an den Gates).
- In der **Ablauf**-Liste hat der aktuell fällige Schritt direkt in der Zeile einen **„Erledigt"/„Freigeben"**-Knopf – so hakt man wie in v3 einfach ab, was fertig ist.

Beide senden `aktion=erledigen` und rufen `produktion_schritt_erledigen($id,$schritt,'',ohneScan=true)`. Der Parameter **`$ohneScan`** überspringt nur die Scan-PRÜFUNG; **Material wird weiterhin nach FEFO abgebucht** und der **Mangel-Guard** bleibt aktiv (fehlt Bestand, meldet die Seite „Nicht genug Bestand"). Die Reihenfolge bleibt erzwungen (nur der erste offene Schritt hat einen Knopf).

Die **geführte Produktion** (`run.php`, Touch/App) nutzt dieselbe Funktion **mit** Scan (Default `$ohneScan=false`) – dort bleibt das Scannen erhalten. Siehe [run.md](run.md).

## Zukauf-Entnahme (Feinschliff Baustein 5)

Station „Fertigware bereitstellen" bucht die zugekaufte fertige Bulkware FEFO ab (`produktion_fertigware_entnehmen`, Chargen der Kategorie `fertig` für den Auftrag) und schreibt `produktion_verbrauch`.

## Geplantes Datum (Baustein 2)

Kachel „Geplant am" (`aktion=geplant`) setzt `produktionsauftrag.geplant_am`; siehe [kalender.md](kalender.md).

## Teilproduktion (.A / .B / .C)

Wird an mehreren Tagen produziert, bucht das Panel **„Teilmenge einbuchen"** (`aktion=teilmenge`, Menge, MHD, Notiz) jede fertige Teilmenge sofort als eigene Charge ins Fertigwarenlager – `produktion_teilmenge_einbuchen()` in `core/schema.php`. Die Chargennummer ist die PR-Basis mit dem nächsten Buchstaben (`charge_naechste_nr()`), das MHD standardmäßig heute + Standardmonate. Es kann nie mehr gebucht werden, als noch offen ist (`produktion_rest()` = Produktionsmenge minus Summe der Chargen).

Das Panel erscheint, solange noch etwas offen ist. Beim Abschluss des Auftrags (letzte Station) bucht `produktion_fertigware_einbuchen()` nur noch den Rest; ist schon alles gebucht, passiert nichts. Das Panel „Fertigware eingebucht" zeigt alle Chargen mit gebuchter Menge, Lagerbestand, MHD und Datum, die Kachel „Chargen" die erste Nummer plus die Anzahl weiterer.

Die Kachel **„Kapsel/Tablette"** zeigt die Größe (`produktion_groesse_label()`): bei Kapsel/Softgel die gepflegte oder aus dem Füllgewicht berechnete Kapselgröße, bei Tablette das Füllgewicht in mg. Bei Formen ohne Größe (Pulver/Stick/Flüssig) wird die Kachel weggelassen.

## Kopf-Button: Fehlende Rohstoffe → Einkaufsliste
Fehlen laut `auftrag_offener_bedarf()` wirklich noch Rohstoffe (nach Bestand UND bereits Bestelltem), erscheint oben der Knopf **„Fehlende Rohstoffe → Einkaufsliste"** (`aktion=auf_einkaufsliste`). Er meldet den Bedarf in einem Klick (setzt `bedarf_gemeldet`) und springt zur **Einkaufsliste** – der Umweg über die Bedarf-Seite entfällt. Es landen **nur die wirklich fehlenden** Rohstoffe dort; Bestand und bereits Bestelltes rechnet die Einkaufsliste (`zu_bestellen`) automatisch raus. Ist nichts offen (alles auf Lager/bestellt) oder der Auftrag fertig, erscheint der Knopf nicht (bzw. bei schon gemeldetem Bedarf steht „Auf der Einkaufsliste").

## Chargennummer korrigieren
Im Panel „Fertigware eingebucht" ist die Chargennummer je Charge inline editierbar (Feld + ✓). Aktion
`charge_edit` aktualisiert `charge.charge_nr` – nur für Chargen DIESES Produktionsauftrags (WHERE pa_id).
Für den Fall, dass eine Charge nachträglich eine andere Nummer bekommen hat. Der Pfeil ↗ öffnet die Charge.

## Material-Panel prominent (2026-10-24)
Das Komponenten-Panel (früher „Einkaufsbedarf", eingeklappt unter „Material, Einkauf & Beschaffung") heißt jetzt **„Material"** und steht **prominent sichtbar** direkt nach „Jetzt dran". Es listet ALLE Komponenten aus `auftrag_bedarf($id)` – Rohstoffe, Leerkapsel, **Verpackung**, Deckel, **Etikett**, Karton, Beipack – mit Benötigt/Verfügbar/Reserviert. Pro Zeile klare **Grün/Rot-Kennzeichnung**: ✓ + grüner Hintergrund + Badge „vorhanden" wenn auf Lager, ✗ + roter Hintergrund + Badge „fehlt X" sonst. Header-Buttons bei Fehlbedarf: **„Zur Bestellseite"** (→ `?p=einkaufsliste`) und „Bestellung(en) anlegen" (Entwürfe je Hauptlieferant). Eingeklappt bleiben nur noch Materialverbrauch (entnommen/FEFO) & Bestellungen.

## Menge weicht vom Auftrag ab – Abgleich (2026-10-27)
Der Material-/Rohstoffbedarf (`produktion_materialbedarf`, `auftrag_bedarf`) rechnet mit der **Menge des Produktionsauftrags** (`produktionsauftrag.menge`), nicht mit der des Auftrags. Wird Auftrag/Angebot **nach** dem Anlegen des PA in der Menge geändert, läuft die Zahl auf Angebot+Auftrag mit, der schon bestehende PA bleibt aber auf dem alten Wert – dann ist der Bedarf falsch (z. B. PR-2761: PA 3.500 vs. Auftrag 1.000 → Bedarf 3,5× zu hoch).
- **Warnpanel (nur Admin):** Erscheint oben, wenn ein Auftrag verknüpft ist, dieser nicht storniert ist und `pa.menge != auftrag.menge`. Zeigt beide Mengen, den Faktor und einen Button **„Menge an Auftrag angleichen"**.
- **POST `aktion=menge_abgleich` (nur Admin):** Übernimmt `menge` (sowie `stueck`, `verpackung_id`) vom verknüpften Auftrag, ruft `bedarf_bump()` (Bedarf/Einkaufsliste neu) und schreibt einen Verlaufseintrag am Kunden. Eine geplante **Überproduktion** (`menge_produktion`) bleibt unberührt (separates Feld). Gedacht als gezielter Fix + wiederkehrender Abgleich, solange noch nichts eingebucht ist.

## Pulver nach Gewicht (2026-10-27)
Bei **Pulver/Granulat** (`form_groessen_einheit()` = 'g') ist die Packungsgröße (`produktion_stueck_je_packung()` = `produkt.einheiten_pro_packung` bzw. `auftrag.stueck`) die **Füllmenge in Gramm je Packung**, NICHT eine Portionszahl (so wird sie schon bei der Anfrage über `anfrage_groesse()`/`fuellmenge_g` gespeichert). Der Rohstoffbedarf wird deshalb **nach Gewicht** gerechnet:
`Gesamt-Füllgewicht (g) = Packungen × g/Packung`, je Zutat `× Massenanteil (Zutat-mg ÷ Summe aller Rezeptur-mg je Einheit)`.
Beispiel Carnosin 100 %: 3.000 Gläser × 75 g = 225 kg (vorher fälschlich 3.000 × 75 × 2,5 g = 562,5 kg, weil die 75 g als 75 Portionen interpretiert wurden). Logik in `produktion_materialbedarf()` (`$fuellGesamtG`); greift auch in `auftrag_bedarf()` und damit in „Wartet auf Material", „Material/Komponenten", Lieferanten-Preise und Einkaufsbedarf. Die Kacheln zeigen bei Pulver „Füllmenge je Packung" (g) und „Gesamt-Füllmenge" (kg) statt Stück. Flüssig/Gel (ml) bleiben vorerst auf der alten Rechnung (offener Punkt).

**Füllmenge korrigieren (nur Admin, POST `aktion=pulver_fuellmenge`):** Bei Pulver-PAs erscheint ein Panel „Füllmenge je Packung (Pulver)" mit der aktuellen Füllmenge (g) und – zur Kontrolle – wie vielen Portionen das entspricht (Füllmenge ÷ Summe der Rezeptur-mg je Einheit). Das Setzen schreibt die Gramm je Packung auf **Produkt** (`einheiten_pro_packung`), **Auftrag** (`stueck`) und diesen **PA** (`stueck`) und ruft `bedarf_bump()`. Gedacht für **Alt-/v3-Pulveraufträge**, bei denen dort noch eine **Portionszahl** statt Gramm steht (dann rechnet der Bedarf zu niedrig) – z. B. Detox: 30 Portionen × 2,2 g = **66 g**.

## Mix-Produktion (Teil selbst, Teil zugekauft) (2026-10-24)
Ein Auftrag kann teils selbst produziert, teils als fertige Bulkware zugekauft werden. Weg: Auftrag bleibt **Eigenproduktion**; den Zukauf-Teil über „Reservieren · aus bereits bestellter/vorhandener Bulkware" (Auftrag-Detailseite) reservieren, den Rest selbst produzieren (ggf. als Teilmenge .A/.B). Eine Teil-**Reservierung** kippt den PA NICHT auf den Kurzweg (nur echte Fertigware-Chargen am Auftrag tun das, `produktion_ist_zukauf`). Der **Rohstoff- & Leerkapselbedarf** (`produktion_materialbedarf` / `auftrag_bedarf`) zieht die reservierte Zukauf-Menge ab (`auftrag_bulk_reserviert_stueck`), **Verpackung & Etikett** bleiben für die volle Menge. Das Material-Panel zeigt dazu eine Hinweiszeile. Hinweis/Grenze: Wird die zugekaufte Bulkware als Fertigware-Charge am Auftrag eingebucht BEVOR ein Eigen-Schritt erledigt ist, kann der Auto-Kurzweg greifen – dann erst einen Eigen-Schritt starten oder den Weg manuell auf „voll" lassen.

## Materialbedarf spiegelt Verbrauch (A3, 2026-10-28)
`produktion_materialbedarf($pa_id)` liefert jetzt zusätzlich **entnommen** (Summe `produktion_verbrauch` für diesen PA+Artikel) und **offen** (= benötigt − entnommen); **fehlt** = max(0, offen − verfügbar). Dadurch steht nach Verbrauch/Fertigstellung nicht weiter „fehlt 150.000". Ist bereits Material entnommen, zeigt das Panel ohnehin „Entnommene Materialien (FEFO)". Für Aufträge, die **ohne Buchung** fertig wurden (God-Mode/Alt-Import), zeigt der Materialbedarf-Zweig einen Hinweis und einen neutralen Badge „nicht gebucht" statt rotem „fehlt". Abgebucht wird FEFO an den Stationsschritten (`Rohstoffe bereitstellen`, `Verkapselung`, `Fertigware bereitstellen`, `Verpacken`) in `produktion_schritt_erledigen()`.

## Material = Restbedarf (2026-11-02)
Die Material-Tabelle nutzt jetzt `auftrag_bedarf_rest($id)` statt `auftrag_bedarf()` – schon produzierte
Packungen sind abgezogen (Benötigt/Verfügbar/Status + „zu bestellen"). Oben erscheint bei begonnener
Produktion ein Hinweis „Restbedarf: X von Y schon produziert – Material nur für die Z offenen Packungen".
Gleiche Zahl wie Material-Übersicht (Auftragsseite) und `produktion_bereitschaft()`.

**Quarantäne (St-07):** „Wartet auf Material", Materialbedarf und Komponenten-Tabelle zeigen „in Quarantäne – freigeben" (Link `?p=wareneingang&quarantaene=1&item=ID`).

**Teilmenge (S-12):** „Teilmenge einbuchen" ruft `produktion_teilmenge_produzieren()` (Material-/Etikett-Prüfung, anteilige Abbuchung). Über dem Formular steht der Rechner „Jetzt machbar" bzw. die Etikett-Sperre. Schritt-Fehler (Etikett nicht freigegeben, Vorbereitung, Reihenfolge) erscheinen als Meldung statt still als „ok".

## Quarantäne statt „fehlt" (Nacht 1)
Liegt die fehlende Menge in Quarantäne, steht dort gelb „in Quarantäne … – freigeben" (wartet auf QM) statt rot „fehlt". Reicht die Quarantäne nicht, bleibt „fehlt" mit Hinweis auf den Quarantäne-Teil. Dashboard-Helfer `bx_fehlt_quar()` (core/ui.php). Produktion bleibt bis zur Freigabe blockiert.

Pulver-Panel (03.11.2026): Die Kontrollzeile heißt jetzt „≈ N Tagesdosen à x g (Rezeptur = 1 Tagesdosis)“ (`tagesdosen_txt()`) statt Portionen. Sie ist nur Info, gerechnet wird mit der Füllmenge in g.

## Material-Kopf (05.11.2026)
Das Kennzeichen heißt nur noch „alles vorhanden“, wenn wirklich nichts fehlt. Ist nichts mehr zu bestellen, aber noch Ware unterwegs (z. B. Bulk-Bestellung deckt den Rest), steht „nichts zu bestellen · N noch unterwegs“ (gelb). Vorher stand „alles vorhanden“ neben einer roten Zeile „fehlt“.
- Kopfbereich zeigt den Kunden immer mit vollem Firmennamen (nicht das interne Kürzel – das gilt nur in Tabellen), 08.11.2026

## Kopf-Kacheln aufgeräumt (08.11.2026)
- **Reihe 1 – was wird produziert:** Produkt (groß, verlinkt) · Kapsel/Tablette · Packungen · Kapseln/Tabletten je Packung
  und gesamt bzw. Füllmenge (Pulver) · ggf. Produktionsmenge (geplant).
- **Reihe 2 – Stand:** „Stand" ersetzt Status/Bereitschaft/Fortschritt: fertig / Beschaffungsweg offen / Material fehlt /
  „X von N Schritten · als Nächstes: <Station>" · Art · Priorität · Geplant am · Charge · MHD (in einer Kachel).
- **Nebenzeile:** Kunde · Auftrag · erstellt (klein, grau).
- **Fehler behoben:** Die God-Mode-Schleife überschrieb `$done` (Anzahl erledigter Schritte) – dadurch stand „Fortschritt / 6"
  ohne Zahl und „Jetzt dran – Schritt 1 von 6" falsch. Schleifenvariable heißt jetzt `$gsDone`.

## Direkt bestellt (08.11.2026)
Im Block Material neben „Bestellung(en) anlegen“ der Knopf „Direkt bestellt“: Entwürfe je Hauptlieferant werden sofort als bestellt + bestätigt abgeschlossen (ohne Lieferant bleibt Entwurf), keine Mail.

## 10.11.2026 (Teil 6)
Vor Produktionsbeginn öffnen „Verkürzter Weg“ und „voller Produktionsweg“ das Pop-up **„Weg festlegen“**. Nach Beginn (erledigte Schritte) bleiben „Auf fertige Kapseln umstellen“ bzw. „Auf vollen Produktionsweg umstellen“: Das ist ein Sonderfall mitten in der Produktion, den das Pop-up nicht abdeckt. Die Rückabwicklung bleibt.

## Ein Weg (11.11.2026, plan-produktion-gefuehrt.md)
- Oben kommt der Block aus `_ablauf.php`: Kopf, Unter-Chargen/Teilmengen/Kartons, Chargenprotokoll, „Teilmenge nachtragen“ (`aktion=nachtragen`).
- **Schritt-Knöpfe ohne Menge** gibt es nur noch für Admin: „Ohne Menge abhaken (Admin)“ mit Rückfrage und Grund, der Grund landet im Protokoll (`pa_ereignis` abweichung). Die QM-Stationen (Qualitätsprüfung, Freigaben) bleiben normal.
- Die alte Box „Teilmenge einbuchen“ (eigene Charge je Buchung) entfällt.
- Der Kopf-Knopf heißt jetzt „In der Werk-App produzieren“.

**Doppelbestellung (11.11.2026):** Vor dem Anlegen prüft `core/bestellung_doppelt.php` offene Bestellungen desselben Artikels bzw. Auftrags. Treffer: Pop-up mit Link, weiter nur mit „Trotzdem bestellen“ (steht im Verlauf).

## Material anklickbar (12.11.2026)
Alle Materialnamen auf der Seite führen per Klick zum Artikel, und zwar in den Reiter **Lager** (`core/item_link.php`). Das gilt für „Material“ (Komponenten), „Wartet auf Material“, „Materialbedarf“ inkl. Kapselhülle und „Entnommene Materialien“. Bei „Teilmenge nicht gebucht“ hängen die fehlenden Artikel als `&teilitems=` an der Umleitung (`pa_teilitems_q()`). Unter der Meldung steht dann „Material öffnen: …“ mit Link und aktuellem freiem Bestand.

## Unter-Charge abschließen, Packungen produziert / Soll (12.11.2026)
- **Kachel „Packungen“** zeigt jetzt *produziert / Soll*, z. B. **902** / 9.000 (`produktion_gebucht()`). Vorher stand nur das Soll da.
- **Unter-Charge abschließen / wieder öffnen** (Tabelle „Unter-Chargen und Teilmengen“, `_ablauf.php`): Knopf je Zeile, solange der PA nicht erledigt ist. Nico: „Status läuft – keine Ahnung, wie ich den Status ändern kann.“
  - **Abschließen** macht dasselbe wie Chargenwechsel oder Ende in der Werk-App: `pa_unter_charge_schliessen()` mit Gegenprobe, Chargenprotokoll-PDF und Ereignis. Eine weitere Teilmenge bekommt danach einen neuen Buchstaben.
  - **Wieder öffnen** geht nur, wenn keine andere Unter-Charge läuft. Das Ereignis „wiederanlauf“ wird vermerkt.
  - Aktionen `uc_schliessen` und `uc_oeffnen`.
- **Buchstabe je Produktionstag (12.11.2026):** Beim Nachtragen gilt das Produktionsdatum. Gleicher Bulk und gleicher Tag wie die laufende Unter-Charge ergibt denselben Buchstaben. Ein anderer Tag oder ein anderer Bulk ergibt den nächsten Buchstaben, die laufende Unter-Charge wird dabei abgeschlossen („neuer Produktionstag“). Ein unveränderter Charge-Vorschlag (`charge_vorschlag`) gilt als „System vergibt“.

## Material: Produkt öffnen und Bestellungen sehen (12.11.2026)
Nico: „Ich will dann auf das Produkt kommen und sehen, wann es bestellt wurde.“
- Die **Zukauf-Zeile** („… – Kapseln (Zukauf)“, ohne Lagerartikel) verlinkt auf den Bulk-Artikel der Rezeptur (`pa_material_bulk_item()`, Kategorie `fertig`).
- Unter jedem Material stehen bis zu drei **Bestellungen** (`pa_material_bestellungen()` / `pa_material_bestellung_html()`): Nummer als Link, Lieferant, „bestellt am“, Stand und „erwartet am“. Der Stand ist Entwurf, Station, bestätigt oder geliefert.
  - Artikel: Bestellungen dieses Auftrags und offene Bestellungen des Artikels.
  - Zukauf-Bulk: Bulk-Bestellungen des Auftrags (Freitext-Position bzw. Fertigware-Artikel des Produkts).

## Ein Weg für Teilmengen (13.11.2026)
Die alten Teilmenge-Boxen (Dashboard „Teilmenge einbuchen“, Produktions-App, Werk-App außerhalb Abfüllen/Verpacken) sind entfernt; ihre POST-Aktionen buchen nichts mehr und verweisen auf den geführten Ablauf. Gebucht wird nur über `pa_teilmenge_buchen()` (Werk-App Abfüllen/Verpacken, Dashboard „Teilmenge nachtragen“).

**Teilmenge nachtragen (13.11.2026, Fable-3 T1.4):** Das Formular ruft nur noch `pa_nachtrag_buchen()` (core/pa_ablauf.php). Ein Nachtrag für gestern, während heute eine Unter-Charge läuft, legt eine eigene Unter-Charge mit dem gewählten Tag an und schließt die laufende nicht. Schlägt die Buchung fehl, bleibt nichts zurück (kein Buchstabe verbraucht).

**Produktion per KI melden (13.11.2026, Werk-App Teil 4):** Feld „Produktion melden (KI)“ über dem Ablauf (PA vorgegeben). „Teilmenge nachtragen“ nimmt zusätzlich `umfang` (komplett / bis vor dem Etikettieren) und – von der KI-Karte – `ki_text`/`ki_von`: nach erfolgreicher Buchung Vermerk „per KI gemeldet von …, bestätigt von …: „Originaltext““ im Chargenprotokoll und Auftragsverlauf.

**Vorbelegung ohne Tausenderpunkt (Fable-4 T2.4, 13.11.2026):** Mengen-/Preisfelder werden mit `zahl_feld()` (Komma, kein Tausenderpunkt) vorbelegt, damit Speichern ohne Änderung den Wert nicht verändert.

**Reserviert (Sofortfix 13.11.2026):** Spalte zeigt im Lager / unterwegs / ungedeckt; bei „fehlt“ der Grund („reserviert, aber nicht im Lager“ / „reserviert, aber ungedeckt - Wareneingang fehlt?“) und Links „BE-…: Charge eingetragen, nicht eingebucht – jetzt einbuchen“.

## Sofortfix 13.11.2026: Unterlagen-Sperre wird Quarantäne-Sperre
Regel Nico: Unterlagen fehlen = Ware bleibt in Quarantäne. Bestellen und Einbuchen gehen immer. Artikel ohne Unterlagenpflicht: „kein Dokument nötig“ (Details `core/lieferant_unterlagen.md`).

„Bedarf bestellen“ mit „Direkt bestellt“: keine Unterlagen-Sperre mehr (nur Aufgabe „Unterlagen nachfordern“).

## Nachbesserung Fable-5 (13.11.2026): Buchen einer KI-Karte
`aktion=nachtragen` mit `ki_karte`: Karte aus der Sitzung, `pki_buchen_pruefen()` (sonst Rückweisung mit Grund), KI-Vermerk geht als Option `ki_vermerk` in `pa_nachtrag_buchen()` (steht damit im abgelegten Chargenprotokoll), Verlauf am Auftrag, Karte gelöscht. „Unveränderter Vorschlag“ wird tolerant verglichen (`pa_charge_abweichend`).

## Nachbesserung Fable-5 (13.11.2026, Design)
Symbole ✓/✗/▶/○/↗ als SVG (`bx_sym`); God-Mode-Kasten eingeklappt (`details`) und ganz unten auf der Seite; Laufzettel-Knopf gleich hoch wie die anderen.

## aktion=uc_menge (13.11.2026)
Menge einer laufenden Unter-Charge korrigieren – Buchung in `pa_uc_menge_korrigieren()` (core/pa_ablauf.php), Rückmeldung oben über `nachok`/`teilfehler`, Anker `#unterchargen`.

## Reiter (13.11.2026, Nico: „maximal unübersichtlich“)
Oben bleiben nur Kopf, Meldungen, Kacheln, „Fertige Bulkware erkannt“ (Entscheidung), „Wartet auf Material“ und „Jetzt dran“.
Darunter Reiter (bs_reiter_html aus core/bestellung_seite.php, Umschalten in bx-ui.js):
- Produktion (Standard): Unter-Chargen und Teilmengen, Teilmenge nachtragen, Produktion melden (KI), Ablauf, Fertigware eingebucht
- Material (Zähler = fehlende Komponenten; Standard bei Status vorbereitung oder wenn noch nichts erledigt ist und Material fehlt): Material, Entnommene Materialien, Zusammensetzung
- Bestellungen & Wareneingang
- Etikett (Punkt, wenn noch nicht freigegeben)
- Details: Weg-Hinweise, Details, Füllmenge (Pulver, Admin), God-Mode
Anker wie #unterchargen, #nachtragen, #kimelden öffnen den Reiter, in dem das Element steht. Ohne Anker kommt der zuletzt gewählte Reiter dieser Seite zurück (sessionStorage, gilt auch für die Bestellseite).
Die doppelte Kopfzeile in _ablauf.php (Kunde, Auftrag, Soll …) ist entfallen – steht in den Kacheln.

**Fable-6 P8 (13.11.2026):** `aktion=uc_menge` nimmt `menge` (komplett) und optional `menge_vor` (bis vor Etikett). Nur Produktionsleitung und Admin dürfen das; leere oder ungültige Eingaben werden abgelehnt. Die Buchung macht `pa_uc_menge_korrigieren()` (`core/pa_ablauf.md`).
