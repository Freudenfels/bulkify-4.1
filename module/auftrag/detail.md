# auftrag/detail.php – Auftrag (Ansicht + Status)

**Zweck:** Zeigt eine Auftragsbestätigung an. **Der Auftrag ist gesetzt** – Menge/Preis/Verpackung werden hier NICHT mehr bearbeitet (nur noch Anzeige). Änderungen laufen über die zuständige Stelle: **Rezeptur → Rezeptur-Modul**, **Flaschen/Verpackung/Kapselgröße → Vor-Produktion**. Am Auftrag selbst sind nur **Status setzen** (Workflow), **Stornieren** und **Löschen** möglich.

**Was passiert hier:**
- **Keine Mengen-/Preis-/Verpackungs-Bearbeitung mehr.** Das frühere große „Details"-Formular (Status/Menge/VK/Verpackung/Stück/Kapselgröße + Netto-Live-Vorschau) und die zugehörige Catch-all-Speicherroutine (POST ohne Aktion) sind entfernt. Die Werte werden nur noch read-only angezeigt (Kennzahl-Kacheln + Details-Reiter). Glas/Verpackung/Kapselgröße setzt man in der **Vor-Produktion** (`produktion/vorbereitung.php`), die Rezeptur im **Rezeptur-Modul**.
- **Storno:** einzige Werte-Änderung am Auftrag selbst. Button **„Auftrag stornieren"** (Reiter *Details*, nur Admin, wenn nicht bereits storniert/versendet) → `aktion=auftrag_stornieren`: setzt `status='storniert'` + `status_datum`, storniert eine offene Rechnung per Gutschrift (`auftrag_rechnungen_stornieren()`), protokolliert via `log_aktivitaet`. Menge/Preis bleiben zur Nachvollziehbarkeit erhalten.
- **Kopf-Button „Zum Produktionsauftrag <Nr.>":** steht im Kopf neben „PDF / Drucken", sobald ein Produktionsauftrag verknüpft ist (`produktionsauftrag.auftrag_id`), und führt auf `?p=produktionsauftrag&id=…`. Gegenstück zum Kopf-Button „Zum Auftrag" auf der PA-Seite – so springt man in beide Richtungen, ohne den Produktions-Block/Reiter zu suchen.
- **Anzeige:** Kennzahlen (Status, Menge in Packungen, **Stück je Packung** = `einheiten_pro_packung` des Produkts (nur wenn gesetzt), **Gesamtstückzahl** = Packungen × `einheiten_pro_packung`, **Kapsel/Tablette** via `produktion_groesse_label()`, **Herstellung** Eigenproduktion/Zukauf, VK/Stück, Netto gesamt). Alle Werte (`.bx-cards .v`) laufen auf einer einheitlichen, ruhigen Größe (15px) – seitenlokaler `<style>` überschreibt das globale `--fs-xl` nur hier; Badges bleiben unberührt. und Details mit klickbaren Links zur **Rezeptur** (`?p=rezeptur_detail`, aus `produkt.rezeptur_id`), zum **Angebot** und zur **Rechnung** (inkl. deren Zahlstatus).
- **Rechnungs-Zeile read-only:** bei vorhandener Rechnung nur Link + Betrag + Zahlstatus (Hinweis „in der Buchhaltung verwalten"); die frühere Aktion „USt/Adresse neu berechnen" ist entfernt. Hat der Auftrag noch **keine** Rechnung, steht dort ein Link **„Rechnung in Buchhaltung erstellen"** → `?p=rechnung_neu&auftrag=<id>` (Buchhaltung, [beleg/rechnung_neu.md](../beleg/rechnung_neu.md)).
- **Read-only-Hinweis + Vor-Produktion-Link:** unter den Stammdaten steht „Der Auftrag ist gesetzt. Änderungen laufen über:" mit **„Rezeptur bearbeiten"** (`?p=rezeptur_detail&id=…`) und **„Vor-Produktion öffnen (Flaschen & Verpackung)"** – bei PA in `vorbereitung` direkt `?p=produktion_vorbereitung&id=<pa_id>`, sonst die Übersicht `?p=produktion_vorbereitung`.
- **Panel „Produktion & Beschaffung":** zeigt, ob selbst hergestellt (Eigenproduktion aus Rohstoffen) oder zugekauft (Fremdproduktion = fertige Bulkware), den verknüpften **Produktionsauftrag** (`produktionsauftrag.auftrag_id`) mit Status + Material-Bereitschaft (`produktion_bereitschaft()`), und die **Bestellungen zu diesem Auftrag** – bei welchem **Lieferanten** und mit welchem Status. Die Verknüpfung läuft über `bestellung_position.auftrag_id`. Ohne Bestellung: Hinweis „Rohstoffe/Bulkware noch offen" + Link zum Einkaufsbedarf.
  - **Charge & MHD der Fertigware** stehen als eigene Felder im Block: ist schon eine Fertigware-Charge zum PA gebucht, werden Nummer + MHD angezeigt; sonst die **systemseitig geplante** nächste Charge (`charge_naechste_nr()`, z. B. `2739.A`). Das **MHD legen wir selbst fest** – solange noch keine Charge gebucht ist, ist es ein Datumsfeld (`mhd_setzen` → `produktionsauftrag.mhd`); leer = Standard +18 Monate (`mhd_standard()`). Beim Einbuchen der Fertigware zieht `produktion_teilmenge_einbuchen()` das MHD in dieser Reihenfolge: explizit übergeben > `produktionsauftrag.mhd` > Standard.
  - **Zur Produktion freigeben** (`pa_freigeben`, nur Admin): steht ein Produktionsauftrag auf `vorbereitung`, kann er **direkt hier am Auftrag** freigegeben werden (Herstellung Eigen/Fremd + Produktionsmenge) – ohne Umweg über das Modul *Vor-Produktion*. Ruft `produktionsauftrag_freigeben()` (Status → `offen`); Admin-Weiche: offene Punkte (Etikett/Material) sind nur ein Hinweis. Mehrmenge über den Bedarf wird als Bulk gebucht.
  - **Produktionsauftrag anlegen** (inkl. Eigen/Fremd-Wahl) erscheint **nur solange der Auftrag aktiv** ist (Status `offen`/`in_produktion`).
  - **Doch selbst produzieren (Rückabwicklung)** (`rueckabwicklung`, nur Admin, nur bei Fremdproduktion): Panel im Reiter *Produktion*. Ruft `produktion_rueckabwicklung_eigen()` – storniert die Fremd-**Bulk**-Bestellung (Verpackung/Etikett bleiben), informiert den Lieferanten (Portal-Chat + Mail) und stellt auf **Eigenproduktion** um; der Rohstoffbedarf erscheint danach im Einkauf. Das Ergebnis wird auf der **Produktionsauftrag-Seite** angezeigt (Flash `rueck`/`rueckfehler`). Blockiert, wenn schon ein Schritt erledigt oder Bulk (teil)geliefert/eingebucht ist. Siehe [produktion/detail.md](../produktion/detail.md).
  - **Fertige Ware nachtragen & einlagern** (`charge_nachtragen_einlagern`, nur Admin): für bereits produzierte Altaufträge **ohne** Produktionsauftrag (Status `versandbereit`/`versendet`), die nirgends in Vor-Produktion/Produktion auftauchen (z. B. AB-3257). Legt über `produktionsauftrag_aus_auftrag()` einen PA an, markiert ihn samt Schritten sofort als erledigt (reiner Charge-Träger, taucht nicht als To-do auf) und bucht via `einlager_buchen()` die volle Auftragsmenge als Fertigware-Charge ins Lager – **Fulfillment → Lager 2** (+ Auftrag auf `versendet`/Kunden-Archiv), sonst **Lager 1**. **Chargennummer und MHD** sind im Formular optional eingebbar (Altware hat eigene, gedruckte Nummern) – leer = Auto-Charge (`charge_naechste_nr`) bzw. Standard-MHD (+18 Monate); nach dem Buchen wird die Charge per `UPDATE` auf die eingegebenen Werte gesetzt. Bei `storniert`: nur Hinweis, nichts einzulagern.
- **Verpackung/Glas & Kapselgröße:** werden **nicht mehr am Auftrag** gesetzt, sondern in der **Vor-Produktion** (`produktion/vorbereitung.php`, dort `verpackung_id` am Auftrag/Produkt + `rezeptur.kapselgroesse_id`). Der Auftrag zeigt das effektive Glas nur noch read-only in den Kennzahl-Kacheln.
- **Etikett-Kachel (`$etikettBadge`):** ein Produkt im Glas HAT ein Etikett (Typ/Maß wird aus dem Behälter abgeleitet). Darum zeigt die Kachel **„fehlt" (rot) nur noch, wenn gar kein Design existiert** – weder an diesem Auftrag (`etikett_vorhanden`) noch aus einer früheren Bestellung desselben Produkts (`etikett_quelle`). Bei einer Nachbestellung mit vorhandenem Design aus dem Vorauftrag → „nicht freigegeben" (gelb) statt „fehlt". `freigegeben` (grün) = Kunde hat für DIESEN Auftrag freigegeben.
- **Kennzahl-Kacheln stehen jetzt IM Reiter *Details*** (nicht mehr über den Reitern). Sie werden über die Closure `$kachelnRender()` ganz oben im Details-Panel ausgegeben (Status, Menge, Glas, Stück/Packung, Gesamtstück, Kapsel, Herstellung, Etikett, VK, Netto, Erstellt).
- **Etikett-Vorschau (Reiter *Details*):** eigenes Panel „Etikett-Vorschau" (nur wenn `auftrag_braucht_etikett()`). Zeigt das hinterlegte Design via `etikett_quelle()` (eigenes oder aus einer Vorbestellung desselben Produkts/Kunden) in einem Rahmen: **Bild** direkt als `<img>`, **PDF** eingebettet als `<iframe>` (Route `?p=dokument&id=`, liefert inline), sonst „Öffnen"-Link. Mit Freigabe-Badge + Dateiname; ohne Design ein Platzhalter. Verwaltet (hochladen/ersetzen/freigeben) wird das Etikett weiterhin im Reiter *Verpackung* (Links springen dorthin).
- **Reiter-Reihenfolge:** **Details · Produktion · Lager · Preise & Rechnung · Dokumente · Verpackung.**
- **Aufbau wie die Kundenseite – Kennzahlen im Details-Reiter, darüber die Reiter.** Eine `.settabs`-Leiste (`#auftabs`) mit fünf Reitern: **Details · Verpackung · Produktion · Preise & Rechnung · Dokumente**. Jedes Panel ist per `data-panel="…"` einem Reiter zugeordnet, ein kleines JS blendet um. Zuordnung: *Details* = Stammdaten/Rechnungs-Zeile (read-only) + Links zu Rezeptur/Vor-Produktion + Stornieren/Löschen; *Verpackung* = **Etikett-Verwaltung (Team/Admin)** – hochladen/ersetzen (auch Last-Minute), Freigabe im Namen des Kunden, entfernen (nur wenn `auftrag_braucht_etikett()` und Rolle admin/sales); Glas/Kapselgröße selbst gehören zur Produktkonfiguration und stehen im Reiter *Details*; *Produktion* = **Fortschritts-Balken** (dieselben Phasen-Punkte wie beim Kunden, Haken=erledigt/Sanduhr=aktuell – zeigt, welcher Schritt gerade dran ist; via `kunde_auftrag_track()`/`auftrag_track_icon()` aus `core/schema.php`), Produktion & Beschaffung, Energetisierung, Externer Labortest; *Lager* = **Fertigware (Charge)** (Nummer/MHD/verfügbare Menge aus `$paChargen`, sonst geplante Charge), **Einlagern** („In Lager 1 buchen", `einlagern_nachholen`, nur wenn `$einlagerNoetig`) und **Wareneingänge zu diesem Auftrag** (`charge.auftrag_id`) – Einlagern + Wareneingänge wurden aus dem Reiter *Produktion* hierher verschoben; *Preise & Rechnung* = **Kalkulation (Marge)** + **Lieferanten-Preise** (Admin, read-only) + Zu Kontingent – **ohne Rechnung** (die verwaltet die Buchhaltung) und **ohne Alt-Rechnung/Express** (ausgeblendet); *Dokumente* = **Dokumente & Downloads** (AB-PDF, PIB, Laufzettel, Produktionsbericht) + Laboranalyse/Labortest (CoA). Der **aktive Reiter bleibt erhalten** (gemerkt je Auftrag in `sessionStorage`), also auch nach dem Speichern eines Formulars. `&analyse=1` in der URL öffnet direkt den Reiter **Dokumente**.

- **Panel „Kalkulation (Marge)" (nur Admin, Reiter Preise):** zeigt VK je Packung, Menge, **Umsatz netto** (= `auftrag.gesamt_netto`) und – wenn für **alle** benötigten Rohstoffe ein **EUR-**EK-Preis hinterlegt ist – die **Materialkosten (günstigste EK)**, EK/Packung und **Marge (nach Material)** in € und %. Materialkosten = Σ (günstigster `lieferant_preis` × benötigte Menge) über `produktion_materialbedarf()`. Fehlt ein EK oder ist ein Preis in Fremdwährung hinterlegt (kein Kurs), steht ein ehrlicher Hinweis „Marge unvollständig" statt einer falschen Zahl. Richtwert – **nur Material**, ohne Lohn/Verpackung/Gemeinkosten.
- **Panel „Lieferanten-Preise zu diesem Auftrag" (nur Admin, Reiter Preise):** reine Preisübersicht (intern, nie im Kundenportal), **keine Bestell-/Express-Aktionen** mehr. Zwei Tabellen: (1) je benötigtem Rohstoff (`produktion_materialbedarf()`) die benötigte/fehlende Menge + alle Lieferantenpreise aus `lieferant_preis` (Firma, Staffel, EK je Einheit, günstigste zuerst); (2) **Fertigprodukt-Zukaufpreise** des Produkts aus `produkt_lieferant_preis` (günstigste zuerst, mit Größe/Lieferbedingung). Bestellungen laufen über den Einkauf, nicht von hier.
- **Entfernt/ausgeblendet:** die früheren Express-Knöpfe (`express_bestellung`/`express_bulk`), das Anfrage-Modal (`anfrage_produkt_button`/`anfrage_modal`), das Panel **„Zahlung / Alt-Rechnung"** (manuelles „bezahlt am" + Alt-Rechnungs-PDF-Upload, per `if(false)` im Markup belassen) und die **Rechnungs-Anzeige** im Reiter Preise. Die zugehörigen POST-Handler existieren noch (harmlos, da keine UI mehr darauf zeigt).

## Schrittleiste (H0, Teil 3) – der Auftrag als zentrale Steuerseite
Über den Reitern steht eine Schrittleiste mit sechs festen Schritten in fester Reihenfolge und Zustand (offen / läuft / erledigt): **1 Material prüfen · 2 Quelle festlegen · 3 Freigeben · 4 Bestellen · 5 Wareneingang · 6 Produktion & Versand**. Sie erfindet nichts neu, sondern bündelt die vorhandene Logik und verlinkt auf die passende Stelle: Schritte 1/2/3/6 öffnen den Reiter *Produktion* (`data-goto`, ein kleines JS schaltet den Tab + scrollt hoch), Schritt 4 führt in den Einkauf (`?p=einkaufsliste`), Schritt 5 in den Reiter *Lager* (Wareneingänge). Der aktive Schritt ist per Lime-Rahmen hervorgehoben. Zustände werden aus vorhandenen Signalen abgeleitet: `art_festgelegt_am` (Quelle), PA-Status ≠ `vorbereitung` (freigegeben), `produktion_bereitschaft()` = bereit/läuft/fertig (Material gedeckt), Bestellungen (`$best`) / Wareneingänge (`charge.auftrag_id`), Auftragsstatus (in_produktion/versendet). Bei `storniert` wird die Leiste ausgeblendet. **Nur Übersicht/Navigation – keine neue Fachlogik.** Die harte Freigabe-Sperre (Schritt 3) steckt bereits in `bedarf_aggregiert`/`bedarf_bulk` + Produktionsliste (`art_festgelegt_am IS NOT NULL`): nicht freigegebene Aufträge erscheinen weder im Einkauf noch in der Produktion.

## Material-Übersicht (H1, Teil 3) – benötigt / frei / unterwegs
Im Reiter *Produktion* (über „Produktion & Beschaffung") steht – sobald ein Produktionsauftrag existiert – eine kompakte **Material-Übersicht**. Je Bedarfsposition aus `auftrag_bedarf($pa_id)` (Rohstoffe, Leerkapseln, ggf. Fertigware/Bulk) zeigt sie **benötigt** (mit Einheit), **frei** (im Lager verfügbar) und **unterwegs** (bestellt, noch nicht eingegangen – `item_unterwegs()`, Summe der Bestellpositionen über Bestellungen `status='bestellt'` ohne Wareneingang). Die Deckungs-Ampel: **gedeckt** (frei reicht), **mit Lieferung** (frei + unterwegs reicht), **Fehlbestand** (reicht auch mit Lieferung nicht). Reine Übersicht, keine Buchung. Fertigware-Zeilen (Zukauf, `item_id=0`) zeigen nur benötigt/frei.

**Restbedarf statt Bruttomenge (2026-11-01):** die Spalte zeigt **„noch benötigt"**, sobald schon Packungen produziert sind – die bereits produzierte Menge (`auftrag_mengenfortschritt`: `gebucht`/`ziel`) wird **anteilig von jeder Position abgezogen** (`$restFaktor = offen/ziel`). Sonst stünde „Fehlbestand", obwohl der Auftrag fast fertig ist (Beispiel AB-3286: 4.101 von 6.000 produziert + 650 kg Bulk frei = gedeckt, zeigte vorher Fehlbestand „6.000 / 2.097"). `auftrag_bedarf()` selbst (für Reservierung/Einkauf/Bereitschaft) bleibt unverändert die Bruttomenge – netto wird **nur in dieser Anzeige** gerechnet. Ohne Produktion (`$ueProd = 0`) bleibt die Spalte „benötigt" wie bisher.

**Einheitengerechte Anzeige (2026-11-01):** alle drei Zahlenspalten (benötigt/frei/unterwegs) laufen über denselben Helfer `$mng($x, $einheit)` und tragen **jeweils die Einheit** (vorher war „frei"/„unterwegs" eine nackte Zahl ohne Einheit). Formatierung je Einheit: `g`/`ml` werden über `gewicht_lesbar()` als **kg/t** bzw. **L** gezeigt; `Pkg`/`Stk`/`Stück` **ganzzahlig** (keine „2.096,774 Pkg" mehr – bei Pulver-Zukauf rechnet `auftrag_bedarf()` losen Bulk in Packungen à Füllmenge um, das ergibt sonst krumme Nachkommastellen); alles andere (z. B. `kg` am Rohstoff) mit bis zu 3 Nachkommastellen. Siehe Memory `bulkify-4-1-pulver-nach-gewicht`.

## Beschaffung: Herstellen / Einkaufen / Reservieren (Reiter Produktion)
Die drei Beschaffungswege heißen im UI **Herstellen** (Eigenproduktion aus Rohstoffen, `produktionsart='eigen'`), **Einkaufen** (fertige Bulkware beim Lieferanten zukaufen, `produktionsart='fremd'`) und **Reservieren** (aus schon bestellter/vorhandener Bulkware). Solange **kein** Produktionsauftrag existiert (Status offen/in Produktion), stehen im Feld „Produktionsauftrag" drei Buttons: **Herstellen** / **Einkaufen** (legen je den PA an, `pa_anlegen`) und **Reservieren**. **Reservieren ist nur aktiv, wenn Bulk des gleichen Produkts unterwegs (bestellt) oder vorhanden ist** – sonst ein ausgegrauter/deaktivierter Button mit Hinweis. Der aktive Reservieren-Button springt zum Block „Reservieren" (`#bulkreserve`). Dieselben Begriffe stehen im Herstellungs-Badge und in den Produktionsart-Dropdowns (Freigeben/Nachtragen).

### Kundeneigene Verpackung / Beistellung (2026-11-02)
Der Behälter kann **vom Kunden beigestellt** sein (kundeneigen, kein Basic-Lagerartikel): Checkbox **„vom Kunden beigestellt (kundeneigen)"** im Reiter Verpackung, gespeichert über `aktion=verpackung_setzen` (danach `bedarf_bump()`), Flag `auftrag.verpackung_beistellung`. Beim Speichern mit Haken legt `verpackung_kundeneigen_sicherstellen($auftrag_id, $basis_item_id)` (in `core/schema.php`) **einen auftragseigenen Lagerartikel** an – eine Kopie des Basis-Behälters mit Namen „<Name> – <Auftragsnr>", `item.kundeneigen=1`, `item.auftrag_id=<Auftrag>`, `ek_preis=0`, kein Lieferant – und setzt `auftrag.verpackung_id` darauf (idempotent: vorhandene Kopie wird wiederverwendet). Damit ist der Beutel **ein echter, buchbarer Artikel**: er wird ganz normal gegen den Lagerbestand geprüft (blockiert als Fehlbestand), bis er im **Wareneingang** eingebucht ist – aber er ist **nicht einkaufbar** (in `auftrag_fehlbedarf()` übersprungen, erscheint also nicht unter „zu bestellen"; der Kunde liefert ihn). Das Dropdown der Behälterwahl blendet kundeneigene Kopien aus (`kundeneigen=0`). Pro-Auftrag, nicht aus dem Angebot übernommen. (Frühere Variante „Beistellung = gar nicht prüfen/fehlt=0" wurde verworfen – es soll blockieren, bis gebucht.)

### Vorbelegung = Restbedarf (2026-11-02)
Die Mengen-Vorbelegung der drei Felder **Produktionsmenge (Freigeben)**, **Menge (Reservieren)** und **Menge (Zuweisen)** rechnet mit dem **Restbedarf**, nicht mehr mit der vollen Auftragsmenge: `$produziertPkg = produktion_gebucht(pa)`, `$restPkg = menge − produziert`, `$restStk = restPkg × einheiten_pro_packung`. Freigeben = `stueck_je_packung × restPkg`; Reservieren = `restStk − bereits reserviert`; Zuweisen = `restStk − bereits zugewiesen`. Beispiel AB-3286: 4.101 von 6.000 fertig → Rest 1.899 Pkg = **588 kg**; sind 650 kg bereits als Lagerware zugewiesen, steht Zuweisen auf **0** (588 − 650). Die Hilfetexte zeigen dann „Noch benötigt (Rest)" statt „Benötigt". Ohne Produktion bleibt alles wie vorher (voller Bedarf). Nur Vorbelegung/Anzeige – der POST-Handler (`pa_freigeben`/Reservierung/Zuweisung) ist unverändert, jeder Wert bleibt frei eintippbar.

### Reservieren im Detail
Fertige **Bulkware** aus einer **konkreten Quelle** für den Auftrag reservieren – **ohne** neue Lieferantenbestellung und **ohne** Eigenproduktion. Funktioniert auch für **noch nicht gelieferte** Bestellungen (z. B. „1 Mio Shilajit schon bestellt"). Reserviert wird gegen den **Bulk-Artikel** des Produkts (`rezeptur_bulkitem`, Kategorie *fertig*).

Block „**Aus Bulk reservieren**" im Panel *Produktion & Beschaffung* (nur Admin, nur wenn das Produkt einen Bulk-Artikel hat). Quelle-Dropdown mit zwei Gruppen: **Bestellungen** (offene Bestellpositionen dieses Bulk-Artikels, auch „unterwegs", mit frei-Menge) und **vorhandene Chargen** (freier Bestand, FEFO). Menge (Stück Bulk, Tausenderpunkt, vorbelegt mit dem offenen Bedarf = `menge × einheiten_pro_packung − bereits reserviert`). Aktionen: `bulk_reservieren` (legt eine `reservierung`-Zeile an, `bestellung_position_id` ODER `charge_id`, `status=aktiv`), `bulk_res_storno` (setzt `status=storniert`). Liste der aktiven Reservierungen mit Status (unterwegs / geliefert / aus Bestand) + „Aufheben".

Kernfunktionen in `core/schema.php`: `auftrag_bulk_item()`, `bulk_quellen_fuer_auftrag()`, `bulk_reservieren()`, `auftrag_bulk_reservierungen()`, `bulk_reservierung_storno()`, `bestellposition_reserviert()`/`charge_bulk_reserviert()` (verhindern Doppelreservierung derselben Quelle). Schema: `reservierung.bestellung_position_id` + `reservierung.charge_id` (additiv). **Stand MVP:** Reservierung = Earmark + Anzeige + Überbuchungs-Schutz je Quelle; das Abfüllen/Verbrauchen der reservierten Charge in der Produktion läuft über den normalen Fremd-/Abfüll-Weg (noch nicht automatisch an die Reservierung gekoppelt). Siehe [[bulkify-bulk-reservierung]].

## Status setzen am Fortschritt (Reiter Produktion)
Im Reiter **Produktion**, direkt **unter der Fortschrittsleiste** (abgetrennt per Oberlinie), steht **„Status setzen"** mit Dropdown (offen · in Produktion · versandbereit · versendet) + **„Setzen"** (Rollen admin/sales/production). Eigene Aktion `status_schnell`: ändert `auftrag.status` + `status_datum` (Kundensicht). `versendet` schließt den Auftrag ab → Kunden-Archiv. **Setzt man `erledigt` oder `versendet`, wird die Produktion mit abgeschlossen** (`auftrag_produktion_schliessen()` in `core/schema.php`): alle noch offenen Produktionsaufträge des Auftrags → `status='erledigt'`, Schritte abgehakt, `bedarf_bump()`. Dadurch **entsteht kein Rohstoff-Einkaufsbedarf mehr** (der Bedarf hängt am `produktionsauftrag.status`, nicht am Auftrags-Status). **Ohne Lagerbuchung** – die Fertigware wird NICHT eingebucht (dafür „Charge nachtragen & einbuchen"). Gleiches gilt für `phase_setzen`, wenn die Stufe auf erledigt/versendet führt. Früher blieb der Produktionsauftrag offen und erzeugte weiter Bedarf, obwohl der Auftrag „erledigt" aussah. **Stornieren** läuft über den Reiter *Details* (Button „Auftrag stornieren" → `auftrag_stornieren`, dort hängt die Gutschrift-/Storno-Logik). So lässt sich der Status direkt am Fortschritt setzen – auch bei **Altaufträgen ohne Produktionsauftrag** (z. B. AB-3257). Menge/Preis/Verpackung werden hier nicht mehr geändert (Auftrag ist gesetzt).

**Herkunft:** Der Auftrag wird von `auftrag_aus_angebot()` erzeugt, sobald im Angebot eine Staffel bestätigt wird. Menge und VK stammen aus der bestätigten Staffel.

## Zu Kontingent machen
Ein angenommener Auftrag mit Menge + VK (und Kunde/Produkt) kann per Button **„Zu Kontingent machen"**
(nur Admin) in ein **Kontingent** (Rahmen/Abruf) umgewandelt werden: `kontingent_aus_auftrag()` legt ein
aktives Kontingent (gesamt_menge = Auftragsmenge, vk_stueck) an und **storniert** den Ursprungsauftrag –
produziert wird danach über die Abrufe (`kontingent_abruf`, je Abruf ein Auftrag). Idempotent; blockiert,
wenn bereits eine bezahlte Rechnung existiert oder der Auftrag selbst aus einem Kontingent stammt.
Beim Umwandeln werden **offene/vorbereitete Produktionsaufträge** dieses Auftrags abgebrochen (Reservierungen
frei) und eine **noch nicht bezahlte Rechnung storniert** – sonst zöge die Produktion die volle Jahresmenge
(z. B. 45.000 Gläser/Etiketten) auf einmal.

**Warn-Banner oben:** Ist ein Auftrag KEIN Kontingent, nicht storniert und die Menge ≥ Schwelle
(`app_meta['jahresmenge_warnschwelle']`, Standard 10.000), erscheint über den Reitern ein Hinweis mit
Ein-Klick-Button „Zu Kontingent (Jahresvertrag) machen" – damit Glas/Etikett/Rohstoffe nur je Abruf anfallen.

## Dokumente & Downloads (Reiter Dokumente)
Eigenes Panel oben im Reiter *Dokumente* mit Direkt-Links (neuer Tab): **Auftragsbestätigung (PDF)** (`?p=auftrag_pdf&id=`), je bereits erstelltem **Lieferschein** ein **Lieferschein LS-… (PDF)** (`?p=lieferschein_pdf&id=<beleg_id>`, entsteht beim Versand; bei Teilversand mehrere), **Produktinformationsblatt (PIB)** (`?p=produkt_pib&id=<produkt_id>`, nur wenn Produkt hinterlegt) sowie – sobald ein Produktionsauftrag existiert – **Laufzettel** (`?p=produktionsauftrag_pdf&id=<pa_id>`) und **Produktionsbericht** (`?p=produktion_bericht&id=<pa_id>`). Darunter bleibt das Laboranalyse/Labortest-Panel.

## Auftrag stornieren / löschen
Panel **„Auftrag stornieren / löschen"** (Reiter *Details*, nur Admin, nicht bei versendet). Zwei Fälle:
- **Bereits storniert:** Button **„Stornierten Auftrag endgültig löschen"** (`auftrag_hart_loeschen`) → `auftrag_komplett_loeschen()`: entfernt Auftrag + Produktionsauftrag/Schritte/Chargen + (stornierte) Rechnung, entkoppelt Bestellungen (bleiben erhalten). **Das Angebot bleibt unverändert** (wird NICHT wieder geöffnet – anders als „zurück zur Anfrage"). Nur Admin, nur bei Status `storniert`, geblockt bei bezahlter Rechnung. Danach Redirect in die Liste (`?p=auftraege&geloescht=1&nr=…`, dort Flash „Auftrag … gelöscht.").
- **Noch aktiv:** **„Auftrag stornieren"** (`auftrag_stornieren`, Status→storniert + Gutschrift) und **„Löschen & zurück zur Anfrage"**: `auftrag_zurueck_und_loeschen()`
löscht die Auftragsbestätigung samt Produktionsauftrag/Schritten und (unbezahlter) Rechnung
(`auftrag_komplett_loeschen`), setzt das zugehörige Angebot von `bestaetigt` zurück auf `gesendet`
(Staffel-Haken zurück) und springt zur **Anfrage** (`?p=portal_anfrage&id=…`), um sie anzupassen oder
neu zu senden. Blockiert bei bezahlter Rechnung oder bereits versendetem Auftrag.

## Laboranalyse / Labortest (je Bestellung/Charge)
**Energetisierung (nur freigeschalteter Kunde):** Panel erscheint, wenn `kunde_zeigt_energetisierung(kunde_id)` wahr ist. **Startdatum setzen/löschen** (`aktion=energ_start`, `auftrag.energ_start`); Status **läuft/abgeschlossen** + „fertig am" werden aus Start + `energ_tage()` abgeleitet (nichts persistiert). Ist die Energetisierung **abgeschlossen**, steht nur noch der Status offen da; das Startdatum liegt dann hinter einem kleinen Expander **„Startdatum korrigieren"** (nicht mehr direkt editierbar, aber korrigierbar). Der Kunde sieht dieselbe Info im Portal (`module/portal/kunde.php`).

**Externer Labortest (nur freigeschalteter Kunde):** Read-only-Panel, wenn `kunde_will_labortest(kunde_id)` wahr ist (`kunden.labortest_extern=1`). Status kommt **automatisch** aus `auftrag_labortest_status()`: „abgeschlossen" (mit Datum + Link „Bericht ansehen"), sobald ein **freigegebener** Laborbericht zum Auftrag oder dessen Produkt vorliegt (`dokument typ='analyse', kunde_sichtbar=1`); sonst „läuft" (Probe beim Drittlabor). Kein manuelles Setzen – Bericht hochladen/freigeben im Labortest-Panel unten bzw. unter `?p=laboranalysen`. Der Kunde sieht den Punkt parallel im Bestell-Verlauf.

**Wareneingänge zu diesem Auftrag** (jetzt im Reiter *Lager*)**:** Liste der direkt gebuchten Chargen (`charge.auftrag_id`) mit Status „angekommen · frei/Quarantäne" – zeigt zugekaufte Fremdproduktions-Bulkware auch **ohne** System-Bestellung an.

Panel „Laboranalyse / Labortest" (zeigt ggf. die Fertigware-Charge). Admin lädt hier den Labortest/das CoA für **genau diese Bestellung** hoch → `dokument` (`objekt_typ='auftrag'`, `typ='analyse'`, optional `dok_datum`). „im Kundenportal sichtbar" = `kunde_sichtbar=1` → erscheint im Kunden-Reiter „Labortest". Aktionen: `analyse_upload`, `analyse_toggle`, `analyse_del`. Produkt-weite Analysen laufen über `?p=laboranalysen`.

## Externer Labortest – Zwischenstand (2026-10-05)
Das Labortest-Panel zeigt jetzt drei Stufen: freigegebener Bericht = „abgeschlossen"; sonst, wenn `auftrag.labor_versendet_am` gesetzt (von der Produktion über die Naht geschrieben) = „Probe beim Labor · versendet am …"; sonst „läuft". Quelle: `auftrag_labortest_status()` (Feld `versendet_am`). Nur für Kunden mit `kunden.labortest_extern=1`. Dashboard liest nur, schreibt nicht.

## Rezeptur verknüpfen (Auftrag ohne Rezeptur)
Hat der Auftrag keine aufgelöste Rezeptur (COALESCE(`auftrag.rezeptur_id`, Produkt-Rezeptur), z. B. v3-Import/Freitext), zeigt die Zeile „Rezeptur" im Reiter *Details* einen Picker (rscombo, nur Admin). Aktion `rezeptur_verknuepfen` setzt `auftrag.rezeptur_id`; hängt ein Produkt ohne Rezeptur dran, wird auch `produkt.rezeptur_id` gesetzt (Produktion/Specs/PIB kennen sie dann).

## „Rohstoff/Bulk angekommen" (Helfer im Reiter Produktion)
Die frühere Leiste „Kunden-Status Rohstoff angekommen" über den Reitern ist entfernt. Der reine Helfer steht jetzt im Reiter **Produktion**, Block „Produktion & Beschaffung", Feld „Rohstoff/Bulk angekommen": zeigt „angekommen · Datum" oder (nur wenn noch nicht) den Button „Als angekommen markieren". **Nur Setzen, kein Rückgängig** – einmal angekommen bleibt angekommen (`rohstoff_angekommen`-Handler setzt nur, mit `rohstoff_angekommen_am IS NULL`-Guard). Rollen admin/production/einkauf.

## Rezeptur für diesen Auftrag überarbeiten (Auftrags-Kopie)
Im Rezeptur-Feld (nur Admin) der Button **„Für diesen Auftrag überarbeiten"** (Aktion `rezeptur_auftrag_kopie`
→ `rezeptur_fuer_auftrag_kopieren($auftragId)` in core/schema.php). Legt eine eigene Rezeptur-KOPIE an
(Name „… (AB <nr>)", Status `entwurf`, `basis_rezeptur_id` = Quelle), kopiert die Zutaten und verknüpft sie
über `auftrag.rezeptur_id` (Override). Das eingefrorene **Kunden-Original bleibt unangetastet** – nur dieser
Auftrag nutzt die frische Version. Danach Sprung in die Kopie (editierbar) zum Rohstoff-Matchen; dadurch
ziehen automatisch die **aktuellen Spec/CoA** der nun korrekt zugeordneten Rohstoffe. Idempotent: nutzt der
Auftrag bereits eine eigene (vom Produkt abweichende) Rezeptur, wird diese weiterverwendet (Badge
„Auftrags-Kopie", Button „Auftrags-Rezeptur bearbeiten") statt ein zweiter Klon.

## Einlagern/Versand vereinheitlicht (2026-10-08)
Einlagern geht IMMER in **Lager 1** (`einlager_ziel_fuer_pa()` liefert immer lager1; `einlager_buchen()` bucht nur noch die Fertigware + schließt die Aufgabe, setzt NICHT mehr „versendet" bei Fulfillment). Button am Auftrag (Reiter *Lager*): **„In Lager 1 buchen"** -> Auftrag wird **versandbereit** (Status `erledigt`, Label einheitlich „versandbereit", kein „bereit zur Einlagerung" mehr). Erst beim **Versand** entscheidet das Lager: **An Kunden senden** (`auftrag_versenden`, Lieferschein + FEFO-Ausbuchen) ODER **An Lager 2 übergeben** (`auftrag_ins_fremdlager`). `auftrag_versenden()` leitet NICHT mehr automatisch ins Fremdlager um.

## Pulver-Bulk: Gewicht statt „Stück/Einheiten" (Reiter Produktion)
Bei Pulver/Granulat (`$istFuellG`, `form_groessen_einheit()==='g'`) ist die Packungsgröße die **Füllmenge in Gramm**, die Bulk-Menge also ein **Gewicht** (z. B. 6.000 × 310 g = 1.860.000 g). Im Reiter „Produktion" führen die drei Bulk-Felder diese Menge weiter in **Gramm** (wie Charge/Reservierung/Wareneingang), sind aber mit **„g"** beschriftet (nicht „Einheiten/Stück") und zeigen daneben den lesbaren Wert via `gewicht_lesbar()` (z. B. „(1,86 t)"): **Zur Produktion freigeben** (Produktionsmenge), **Reservieren** (Menge Bulk) und **Lagerware zuweisen** (Menge). Die Hinweistexte „Benötigt …" nutzen `$bulkMng()` (→ kg/t). Helfer oben: `$bulkEinh`, `$bulkLes()`, `$bulkMng()`. Stück-Formen (Kapsel/Tablette …) bleiben „Stück". Die **Charge-Tabelle/Dropdowns** (Reservieren-Quelle, „Lagerware zuweisen"-Tabelle + Produkt-/Charge-Auswahl) laufen über den Helfer `$mengeLes($menge, $charge_einheit)`: bei Charge-Einheit `g`/`ml` (bzw. Pulver-Auftrag ohne Charge-Einheit) wird `gewicht_lesbar()` genutzt → **ab 1.000 g in kg, ab 1.000.000 in t** (z. B. „650 kg" statt „650.000 g"); sonst Zahl + Einheit. Gleiche Logik im Einkauf, siehe `module/einkauf/einkaufsliste.md`.

## Mengen-Fortschritt (Teilproduktion/Teilversand)
Ganz oben im Panel „Fortschritt" – noch vor der Phasen-Kette – steht eine **segmentierte Mengen-Leiste**, die zeigt, WIE VIEL schon produziert bzw. versendet ist (die Phasen-Kette zeigt nur das WO). Quellen: `auftrag_mengenfortschritt()` (Ziel = max aus `auftrag.menge` und Summe der PA-Mengen, `gebucht` = als Fertigware gebuchte Packungen) und `auftrag.versendet_menge` (kumuliert versendete Packungen, Teilversand). Segmente: **versendet** (grün `--gruen`), **produziert/im Lager** (lime `--lime`), Rest = **offen** (graue Spur). Kopfzeile: „452 von 500 Packungen produziert · 48 offen · X versendet". Erscheint nur, wenn ein Ziel > 0 und bereits etwas produziert/versendet ist (sonst würde ein leerer Balken nichts aussagen). Ersetzt den früheren einfachen „Produziert (Live)"-Balken, der im Block „Produktion & Beschaffung" vergraben war. Ein echter Teilversand-Workflow existiert noch nicht – das Feld `versendet_menge` wird hier aber schon korrekt dargestellt, sobald es gefüllt ist.

## Fortschritt auf jede Stufe setzen (Admin/Team)
Am Fortschrittsbalken gibt es „Fortschritt setzen" mit **allen** Phasen (nicht nur den groben Status): Bestätigt,
Rohstoff bestellt, Rohstoff angekommen, In Produktion, Etikettiert, Qualitätsprüfung, Versandbereit, Versendet.
**„Eingelagert" ist ausschließlich eine Fulfillment-Phase** – bei Fulfillment-Kunden heißt die Kette am Ende
„Bereit zur Einlagerung" / „Eingelagert" (Ware ins Lager 2 statt Versand); Nicht-Fulfillment endet Versandbereit →
Versendet (die interne Lager-1-Buchung der Fertigware ist KEINE Kundenphase). Die Auswahl kommt aus `auftrag_phasen()`; der Handler
`phase_setzen` schreibt `auftrag.phase_override` (gewählter Phasenindex) und führt den groben `auftrag.status`
passend mit (offen/in_produktion/erledigt/versendet) – auch Zurückstufen ist möglich. „Bestätigt" (Stufe 0) löscht
den Override (phase_override=NULL → wieder automatisch aus den Signalen). Der Kunde sieht die Stufe sofort.

## Verpackung (Behälter) je Auftrag wählbar
Im Reiter „Verpackung" gibt es einen Behälter-Selektor (Admin/Vertrieb), der `auftrag.verpackung_id` setzt (`aktion=verpackung_setzen`). Der Auftrags-Behälter hat Vorrang vor dem Produkt (siehe `auftrag_braucht_etikett()` + PIB/Etikettmaß). Wichtig für alte v3-Aufträge ohne Produkt-Verpackung – dort ließ sich bisher keine Verpackung wählen. Der Hinweis zeigt den aktuellen Behälter und sein Etikett-Endformat (bzw. „nicht hinterlegt").

## Reiter „Rezeptur": Rohstoffe anklickbar + Unterlagen-Status
Der Reiter „Rezeptur" (effektive Rezeptur aus `COALESCE(auftrag.rezeptur_id, Produkt-Rezeptur)`) listet die Zutaten. Jede Zutat mit zugeordnetem Rohstoff (`rezeptur_zutat.item_id`) ist ein **Link auf den Rohstoff** (`?p=rohstoff&id=<item_id>`, neuer Tab). Die Spalte **„Unterlagen"** zeigt je Rohstoff, ob am Item ein CoA/Spec als `dokument` (`objekt_typ='item'`, `typ IN ('coa','spec','beides','analyse')`) hinterlegt ist: **CoA** (grün, bei `coa`/`beides`/`analyse`, ggf. „CoA + Spec"), **nur Spec – CoA fehlt** (gelb, nur `spec`/`beides`), **keine Unterlagen** (gelb). Zutaten-Texte ohne zugeordneten Rohstoff stehen als „nicht zugeordnet". Der Status wird in einer Sammel-Abfrage (`$coaMap`) über alle Zutat-`item_id` geladen (kein Query je Zeile).

## Pulver: Kacheln nach Gewicht (2026-10-27)
Bei Pulver/Granulat (`form_groessen_einheit()` = 'g') zeigen die Kennzahl-Kacheln **„Füllmenge je Packung" (g)** und **„Gesamt-Füllmenge" (kg)** statt „Stück je Packung"/„Gesamtstückzahl" (`$istFuellG`). Grund: Bei diesen Formen ist `auftrag.stueck`/`produkt.einheiten_pro_packung` die Füllmenge in Gramm je Packung, keine Portionszahl. Der Materialbedarf wird entsprechend nach Gewicht gerechnet – Details in [../produktion/detail.md](../produktion/detail.md) Abschnitt „Pulver nach Gewicht".

## G3 – Weiche Bulk-Reservierung (2026-10-28)
`bulk_reservieren()` blockiert nicht mehr, wenn mehr reserviert wird als eine Quelle (Charge/Bestellung) „frei" hat – es wird nur **gewarnt** (`warnung` → Banner `&bulkwarn=`). Reservierungen sind damit weiche, über Aufträge umverteilbare Zuordnungen (auch gegen Mengen, die noch unterwegs sind); bulkify entscheidet (z. B. Kunde out-of-stock überbrücken). Storno/Umhängen über die bestehende Reservierungsliste am Auftrag.

## Lagerware fest einem Auftrag zuweisen (Charge -> Auftrag) (2026-10-28)
Reiter „Produktion", Panel **„Lagerware zuweisen"** (nur Admin, `#lagerzuweisen`). Bindet vorhandene Lagerware (fertige Bulkware = Bulk-Artikel der Rezeptur, Kategorie `fertig`) **hart** an den Auftrag – der Kunde bekommt genau diese Charge. Im Gegensatz zu „Reservieren" (weiche Reservierung, zählt nur gegen den Eigen-Rohstoffbedarf) setzt die Zuweisung `charge.auftrag_id`, wodurch die Charge als **Fertigware** zählt (`auftrag_fertigware_cached`) und bei Fremd-/Zukauf-Aufträgen **„wartet auf Material"** abgeräumt wird – ohne neue Bestellung, ohne Eigenproduktion.

- **Teilmenge**: wird aus der Quell-Charge abgespalten – es entsteht eine Kind-Charge mit gleicher `charge_nr` und eigener `auftrag_id`; der freie Rest bleibt im Lager (`auftrag_id` NULL) und ist weiter zuteilbar.
- **Mehrere Aufträge je Charge**: dadurch kann dieselbe physische Charge (gleiche `charge_nr`) auf mehrere Aufträge/Kunden verteilt werden – jeder Auftrag bekommt seine eigene Kind-Charge.
- **Umkehrbar**: „Lösen" (`charge_auftrag_loesen`) hängt die Charge wieder ab → wird freie, nicht gebundene Lagerware.

Funktionen in [../../core/schema.php](../../core/schema.php): `auftrag_lagerware_quellen()` (freie, noch nicht zugewiesene Chargen des Bulk-Artikels), `auftrag_lagerware_zugewiesen()` (bereits zugewiesene Fertigware-Chargen), `charge_auftrag_zuweisen()` (Zuweisen/Splitten), `charge_auftrag_loesen()`. Handler-Aktionen `charge_zuweisen` / `charge_loesen`. Taucht keine Quell-Charge auf, liegt die Ware unter einem anderen Artikel/Kategorie, in Quarantäne/gesperrt, oder ist schon einem Auftrag zugewiesen.

### Nachtrag (2026-10-28): Lagerware-Zuweisung mit freier Produktwahl
Das Dropdown „Lagerware zuweisen" bietet **alle** freien Fertigware-(Bulk-)Chargen im Lager an, nicht nur die der automatisch aufgelösten Rezeptur-Bulkware: Optgroup **„Passend zur Rezeptur (Bulk)"** zuerst, darunter **„Andere Fertigware im Lager (Bulk)"** (mit Artikelname + Art.-Nr.). So kann der Admin bewusst z. B. eine unter einem anderen Artikel/einer anderen Rezeptur gebuchte Charge wählen (etwa „Shilajit ohne DC – Bulk" für einen Kunden-Shilajit-Auftrag, wenn es inhaltlich dieselbe Rezeptur unter anderem Kundennamen ist). **Es gibt bewusst keine Artikel-/Rezeptur-Prüfung** – der Admin entscheidet, was gekoppelt wird. Gewarnt wird nur, wenn mehr angefordert wird, als die Charge frei hat. `auftrag_lagerware_quellen()` liefert `chargen` (exakt) + `weitere` (alle anderen freien `fertig`-Chargen); `charge_auftrag_zuweisen()` akzeptiert jede freie `fertig`-Charge. Das Formular erscheint, sobald irgendeine freie Fertigware-Charge existiert. Die Liste der zugewiesenen Chargen zeigt den Artikelnamen zur Nachvollziehbarkeit.

**Bedarf-Effekt:** Eine Zuweisung setzt `charge.auftrag_id` → `produktion_ist_zukauf()` wird wahr → `auftrag_bedarf()` schaltet auf den Zukauf-Zweig: Rohstoff-/Leerkapselbedarf fällt weg, stattdessen eine gedeckte „Fertigware (Zukauf)"-Zeile; bei voller Deckung „produktionsbereit" statt „wartet auf Material". Verpackung/Etikett bleiben für die volle Menge nötig. Bereits angelegte Bulk-Bestellungen beim Lieferanten werden NICHT automatisch storniert (ggf. manuell), der Lagerbestand wird nur earmarked (abgebucht erst bei Produktion/Versand).

## Vor-Produktion-Verweis (I3, Teil 3)
Auf der Details-Seite verweist der Änderungs-Block nur noch **pro Produktionsauftrag** auf `?p=produktion_vorbereitung&id=<pa>` („Flaschen, Verpackung & Kapselgröße setzen"). Der frühere Link auf die generelle Vor-Produktions-**Liste** (ohne ID) ist entfernt, seit die Freigabe direkt am Auftrag läuft (Reiter Produktion) und der Nav-Eintrag weg ist. Ohne Produktionsauftrag erscheint kein Vor-Produktions-Link.

## Material-Übersicht = eine Quelle (2026-11-02)
Die Material-Übersicht bezieht die Zeilen jetzt aus `auftrag_bedarf_rest()` (core/schema.php) statt die
Rest-Skalierung selbst zu rechnen. Damit zeigen Übersicht, PA-Material und Bereitschaft garantiert dieselbe
Zahl. „unterwegs" kommt weiter aus `item_unterwegs()`; „mit Lieferung" = frei + unterwegs reicht.

## Kundenspezifischer Behälter statt Beistellung (Punkt 6, 2026-11-02)
Der Haken „vom Kunden beigestellt (kundeneigen)" ist entfernt. Stattdessen: Reiter Verpackung zeigt bei
einem bedruckten, kundenspezifischen Behälter ein Badge „bedruckt · kundenspezifisch"; sonst ein Knopf
„Bedruckten, kundenspezifischen Behälter anlegen" (Aktion `verpackung_kundenspezifisch` →
`verpackung_kundenspezifisch_sicherstellen()` je Produkt + Kunde des Auftrags, bindet den Auftrag). Einkaufbar, getrennt vom Standard.
Blocker 1: das Produkt behält den neutralen Behälter (bei Katalogprodukten); das Behälter-Dropdown zeigt bedruckte
Behälter nur dieses Kunden.

## Reiter Material + „Produktion" (Punkt 1+2, 2026-11-02)
Neuer Reiter **Material**: Material-Übersicht, Verpackung (Behälter) + Etikett (früher eigener Reiter
„Verpackung", entfällt) und **„Material beschaffen & zuweisen"**. Dort sind **Reservieren** und **Lagerware
zuweisen** zu EINEM Schritt verschmolzen: eine Quelle-Auswahl (Bestellung → reservieren / Lager-Charge →
fest zuweisen), eine Mengeneingabe, eine gemeinsame „gebunden"-Tabelle. Dispatcher `aktion=material_binden`
(routet nach `bulk_reservieren` bzw. `charge_auftrag_zuweisen`). Reiter **Produktion** trägt nur noch
Fortschritt + Herstellung/Freigabe + „Doch selbst produzieren" + Energetisierung/Labortest. Deep-Link
`#bulkreserve` und der Schritt „Material prüfen" öffnen jetzt den Material-Reiter.

## Nächster-Schritt-Banner + konkreter Fehlbedarf (Punkt 2+3, 2026-11-02)
Oben im Reiter Produktion steht jetzt ein Satz „Nächster Schritt: …" (farbiger Rand je Lage: rot=blockiert,
gelb=Freigabe offen, grün=bereit/versandbereit). Bei „wartet auf Material" folgt eine konkrete Liste: je
fehlender Position Menge+Einheit+Name und ob bestellt (Bestellnummer verlinkt + erwarteter Termin
`bestellung.eta_geplant`) oder „noch nicht bestellt – zum Einkauf", plus Knopf „Zum Reiter Material".
Quelle: `produktion_bereitschaft()` (netto, Restbedarf). Die farbige Phasenleiste (bx-htrack) bleibt.

## Zukauf-Art umschaltbar (Punkt 5, 2026-11-02)
Bei Herstellung = Einkaufen (Zukauf) zeigt der Reiter Produktion jetzt die **Zukauf-Art** und erlaubt (Admin)
das Umschalten: „Bulk/Pulver zukaufen – selbst abfüllen" (Verpackung zählt) vs. „Fertige Ware zukaufen"
(Verpackung enthalten). Aktion `zukauf_art_setzen` → `produktionsauftrag.zukauf_art` + `bedarf_bump()`.

## Fortschritt als Schritt-Karten (Punkt 2, 2026-11-02)
Der Reiter Produktion zeigt den Fortschritt jetzt als **Schritt-Karten** (eine je Phase aus `auftrag_phasen()`):
Farbe je Status (grün fertig / gelb läuft / grau wartet / rot blockiert bei „wartet auf Material" bis
„In Produktion"), je Karte wer (wir/Lieferant; bei Zukauf Produktion/Etikett/QS = Lieferant) und Datum. Der
AKTUELLE Schritt hat genau EINEN Hauptknopf „Schritt abschließen →" (`phase_setzen` auf idx+1; letzter =
„Auftrag abschließen"). Die horizontale Phasenleiste bleibt oben als Überblick; „Fortschritt manuell auf eine
Stufe setzen" (auch zurück) ist eingeklappt verfügbar.

**Quarantäne im Auftrag (St-07):** Die Material-Übersicht hat die Spalte „in Quarantäne" und die Deckung „in Quarantäne – freigeben" (Link zur Freigabe-Liste). „Nächster Schritt" nennt Quarantäne-Ware mit Freigabe-Link statt „noch nicht bestellt".

Kennzahl „VK / Packung (gesamt)" = `auftrag_vk_gesamt()` (gesamt_netto / menge), nicht mehr nur die Herstellungszeile.

## Handelsware (S-15)
Reiter Produktion: Ohne Produktionsauftrag steht statt „Herstellen/Einkaufen" der Kasten **Beschaffung (Handelsware)** – offen, frei im Lager, Quarantäne, bestellt/unterwegs, noch zu beschaffen – mit Sprüngen in Einkaufsliste, Wareneingang und Versand. Hat ein Handelsware-Auftrag (aus einer älteren Annahme) doch einen Produktionsauftrag, sagt der Hinweis das, statt „es wird kein Produktionsauftrag erzeugt".

## Rechnung neu berechnen (Paket Mails)
Wird die Rechnung dabei erstmals für den Kunden freigegeben, bekommt er `mail_kunde_rechnung_freigegeben()`.

## Quarantäne statt „fehlt" (Nacht 1)
Liegt die fehlende Menge in Quarantäne, steht dort gelb „in Quarantäne … – freigeben" (wartet auf QM) statt rot „fehlt". Reicht die Quarantäne nicht, bleibt „fehlt" mit Hinweis auf den Quarantäne-Teil. Dashboard-Helfer `bx_fehlt_quar()` (core/ui.php). Produktion bleibt bis zur Freigabe blockiert.

## Zukauf „fertig" – kurzer Weg (Nacht 2a)
Bei Zukauf-Art „fertig" hat der Produktionsauftrag nur noch **Fertigware bereitstellen → Qualitätsprüfung → Produktions-Freigabe → Versand-Freigabe** (kein Verpacken/Etikettieren/Beipack/Umkarton, die Ware ist fertig). `produktion_schritte_regenerieren()` beachtet `zukauf_art`, `produktion_zukauf_weg_anpassen()` passt beim Umstellen der Zukauf-Art die Schritte an (noch nichts erledigt: neu erzeugen, Status bleibt; sonst nur offene Ausbau-Schritte entfernen). Prod-App: `erp_weg_stationen()` ebenso.

## Auftrags-Art je Kunde (Aufräumen 9)
„Nachbestellung" heißt: **derselbe Kunde** hat das Produkt schon bestellt. Hat nur ein anderer Kunde es bestellt, steht „Neues Produkt" (für diesen Kunden neu). Gleiche Regel in der Liste (`$erstProdKunde`) und am Auftrag (`auftrag_art()`).

## Kennzahl-Kacheln (Aufräumen 12)
Die Kacheln oben am Auftrag sind ein responsives Raster (`auto-fill, minmax(160px,1fr)`). Sie brechen in neue Zeilen um, statt Beschriftungen mit „…" abzuschneiden. Menge mit Tausenderpunkt.

**Voraussichtliche Lieferung (03.11.2026):** Reiter Details, Zeitraum von/bis (`auftrag.liefertermin_von/_bis`, Aktion
`liefertermin_setzen`, protokolliert). Der Kunde sieht ihn im Portal über dem Fortschritt; leer = geplanter Produktionstermin.

**Nachrichten mit dem Kunden (Report 5):** Kasten oben im Reiter Details (`kunde_nachricht_panel`), Antwort = Aktion `kunde_nachricht` -> gespeichert, Mail an den Kunden (`mail_kunde_einfach`, Link auf die Bestellung), im Portal sichtbar; Kunden-Nachrichten werden beim Öffnen als gelesen markiert.

## Behälter im Auftrags-Namen (03.11.2026)
Der Kopf zeigt den Namens-Snapshot (`produkt_bezeichnung`). Beim Speichern der Verpackung zieht `auftrag_name_behaelter_nachziehen` den Behälter-Teil („· 100 ml Glas“) auf den gewählten Behälter; vorher blieb oben 100 ml stehen, obwohl der Auftrag im 150 ml Glas lief (AB 590).

## Material-Übersicht: Spalte „Bestellt bei“ (03.11.2026)
Je Position die Bestellungen aus `item_bestellungen()`: Nummer (Link), Lieferant, Menge, Status-Badge, Termin, „für diesen Auftrag“. Ohne Bestellung steht „nicht bestellt“. In der Zeile „Fertigware“ erscheinen auch Bulk-Zukäufe, die als Freitext an den Auftrag gebunden sind. Position und „Bestellt bei“ brechen um, damit die Tabelle nicht seitlich scrollt.
