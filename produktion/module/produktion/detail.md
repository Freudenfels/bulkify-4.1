# produktion/module/produktion/detail.php
Übersicht + Schrittliste eines Produktionsauftrags über `erp_pa()`/`erp_pa_schritte()`.

**Eine Übersichtskarte**: alle Felder in EINEM gleichmäßigen Spaltenraster (`auto-fit`, nutzt die volle Breite), Label über Wert – keine Gruppen-Überschriften, keine Trennlinien, keine Kacheln. Leere Felder fallen raus. Felder:
- **Auftrag:** Status, Produzierbar? (`erp_pa_bereitschaft`), Auftragseingang, Kunde, Herstellung (eigen/fremd).
- **Produkt:** Produkt, Rezeptur, Kapselgröße, Verpackung (Name · Typ · Volumen · Material, ohne Dopplung zum Namen).
- **Menge:** Packungen, Stück/Kapseln je VPE + gesamt.
- **Charge:** Chargennummer (gebucht oder geplant `.A`) + MHD (`erp_pa_charge_info`).

Verpackung wird immer gezeigt (bei fehlender Angabe „–"); Produktionstyp = Eigen-/Fremdproduktion. Darunter die Tabelle **„Rezeptur & Rohstoffbedarf"** (`erp_materialbedarf()`): je Rohstoff mg je Einheit, benötigte Gesamtmenge, Verfügbar, Status + Füllgewicht; ohne Rohstoff-Items fällt sie auf eine reine Zutatenliste zurück (`erp_pa_zutaten()`). Fehlt Material, erscheint zusätzlich das Panel **„Wartet auf Material"**.

**Produktionsweg (nur Admin):** Panel mit vier Schaltern – Abfüllen/Verpacken, Etikettieren, Karton/Umverpackung, Beipackzettel. `aktion=weg` → `erp_weg_anwenden()` erzeugt die Schrittfolge für diesen Auftrag neu (Grundweg Zukauf/Eigen bleibt automatisch; alles aus = nur Bulkware). Nur änderbar, solange kein Schritt erledigt ist; Bulk-Aufträge haben einen festen Weg (kein Panel).

**Etikett (Kunde):** Vorschau des hochgeladenen Kunden-Etiketts (Dokument am Auftrag, `erp_etikett_datei`) – Bild inline bzw. PDF im iframe, ausgeliefert über die eigene Route `?p=etikett&id=<pa>` (`etikett.php`, aus `BX_UPLOADS`). Zeigt Dateiname, „in neuem Tab öffnen" und ob dasselbe Etikett schon bei anderen Aufträgen vorkam (`erp_etikett_schon_verwendet`, über `datei_hash`). Dazu der physische Etikett-Status (`erp_etikett_status`): angekommen/Quarantäne/bestellt/noch nicht da. Panel nur, wenn Etikett-Design oder Produkt-Etikett vorhanden.

**Rohstoffbedarf** nur bei Eigen-/Bulk-Produktion; bei **Zukauf** (fertige Bulkware) ausgeblendet.

**Produktionsfortschritt:** Balken „produziert X von Y" (+ Badge teilweise/vollständig) und ein Feld **„Teilmenge produzieren"** (`aktion=teilmenge` → `erp_teilmenge_produzieren()`): bucht eine Teilmenge sofort ein (Rohstoffe anteilig abgebucht), Auftrag bleibt offen bis voll.

Oben rechts der Button in den Produktionsmodus (`?p=run&id=…`); Beschriftung je Status: „Produktion starten" (offen), „Produktion fortsetzen" (laufend), „Produktionsmodus" (erledigt). Bei Status **`vorbereitung`** ist der Button **ausgegraut/deaktiviert** (kein Run-Link, `pointer-events:none`, Tooltip + Hinweis „noch nicht freigegeben – Vor-Produktion im Dashboard"), weil die Run-Ansicht dann ohnehin gesperrt wäre.

**Schritte:** der jeweils erste offene Schritt ist „als Nächstes" und trägt den **Abschließen**-Button. Der POST (`aktion=schritt_ab`, `schritt_id`) ruft `erp_schritt_abschliessen()` auf (FEFO-Entnahme, Mangel-Guard; letzter Schritt bucht Fertigware ein) und leitet danach um (PRG, Flash). Zeiten via `fmt_zeit()`.
