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
  - `rezeptur` – Kopf einer Formulierung (Name, Kunde, Darreichungsform, Status); Mengen gelten pro Einheit.
  - `rezeptur_zutat` – die Zutaten (Rohstoffe) einer Rezeptur mit Menge in mg; verweist auf `item`.
  - `produkt` – SKU = Rezeptur + Verpackung + Kunde (+ Einheiten/Packung, Verzehr/Tag); die verkaufbare Einheit.
  - `angebot` + `angebot_staffel` – Angebot (einzige Preisquelle) mit Mengenstaffeln (Menge + VK/Stück, bestätigte Staffel).
  - `auftrag` – Auftragsbestätigung (AB-), entsteht automatisch aus der bestätigten Staffel.
  - `beleg` – Rechnung/Gutschrift/Lieferschein (typ); RE- entsteht automatisch mit dem Auftrag.
  - `produktionsauftrag` (PR-) + `produktion_schritt` – Produktionsauftrag mit Stationen/Gates, entsteht automatisch mit dem Auftrag.
  - `lieferant_preis` – Staffelpreise je Rohstoff und Lieferant (menge_ab + Preis); günstigster wird im Rohstoff-Einkauf markiert.
  - `bestellung` + `bestellung_position` – Einkaufsbestellung beim Lieferanten (BE-) mit Positionen (Item+Menge+EK). Helfer `bestellung_wareneingang()` (Positionen → Chargen, Status geliefert).
  - `charge` – Bestand je Item als Chargen (Menge, MHD, Lieferant, Status quarantaene/frei/gesperrt). Helfer `item_bestand()`, `wareneingang_buchen()`, `item_braucht_quarantaene()`.
  - `kapselgroesse` – Kapselgrößen mit nomineller Füllmenge (mg), in Einstellungen pflegbar (`seed_kapselgroesse_if_empty`). Basis für „passt das rein?"/Split.
  - `pack_kapazitaet` – Kapsel-Fassung je Primärverpackung: wie viele Kapseln je Kapselgröße in eine Dose/ein Glas passen (item_id + kapselgroesse_id + stück). Helfer `pack_kapazitaet_fuer($item_id)`. `seed_behaelter_kapazitaet()` legt einmalig die Standard-Behälter (PET-Packer 100–250 ml, Flip Packer, PLA Becher, Weithalsglas 100–250 ml) samt Herstellerwerten für #00/#0/#1/#2 an (Marker `app_meta.seed_behaelter_kap`; überschreibt keine Handeingaben).
  - **Verpackungs-Stückliste** (Migrationen): `item.verpackung_rolle` (primaer/verschluss/etikett/karton/beipack), `item.max_fuellgewicht_g` (Pulver), `item.etikett_format`; am Produkt die Slots `verschluss_id`/`etikett_id`/`karton_id`/`beipack_id` (Primär bleibt `verpackung_id`). Rollen-Labels via `verpackung_rollen()`. Zuordnung im Produkt: Kapsel→Kapsel-Fassung, Pulver→Füllgewicht, Flüssig→Volumen.
  - `item.cas` (Migration) – CAS-Nummer am Rohstoff.
  - **Leerkapsel** (Migrationen): Rohstoff-Untertyp `item.form='kapselhuelle'` mit `item.kapselgroesse_id` (FK kapselgroesse) + `item.leergewicht_mg`; Material/Farbe über bestehende `item.material`/`item.farbe`. Operativ ein Rohstoff (Wareneingang/Bestand/FEFO), in der Liste eigene Sicht `kat=leerkapsel`. Fertig befüllte Kapseln vom Lieferanten dagegen = `kategorie=fertig` (Bulkware, kein Verkapseln).
  - **Leerkapsel-Zuordnung/Verbrauch:** `produkt.leerkapsel_id` (optionale manuelle Wahl). Helfer `rezeptur_kapselgroesse()` (Größe aus Füllgewicht), `produkt_leerkapsel_kandidaten()` (Kapseln passender Größe), `produkt_leerkapsel_id()` (manuelle Wahl > eindeutiger Größentreffer, sonst null), `produktion_kapseln_entnehmen()` (Station Verkapselung, FEFO, Gate).
  - `produktion_verbrauch` – welche Charge in welcher Menge für einen Produktionsauftrag entnommen wurde (Rückverfolgung). Helfer `produktion_materialbedarf()` (benötigt vs. verfügbar) und `produktion_rohstoffe_entnehmen()` (FEFO-Entnahme, blockiert bei Mangel).
  - Helfer `rezeptur_kosten_pro_einheit()` / `produkt_ek_pack()` – EK-Kalkulation. `produktionsschritte_fuer($form)` – Stationen je Form. `auftrag_aus_angebot()` – Auto-Kette Angebot→Auftrag+Rechnung+Produktionsauftrag (idempotent, USt 19%/0%). `produktion_fertigware_einbuchen()` – Fertigware als Charge einbuchen. `auftrag_versenden()` – Fertigware (FEFO) ausbuchen + Lieferschein (LS-) + Status „versendet".
  - `naehrstoff` – zentrale Nährstoff-/NRV-Referenz (vorbefüllt mit der EU-NRV-Liste, erweiterbar).
  - `item_wirkstoff` – Wirkstoffe je Rohstoff (mehrere möglich), je mit Gehalt %; verweist auf `naehrstoff`.
  - Helfer `naehrstoff_id_by_name()` findet einen Nährstoff per Name oder legt ihn neu an (für „neuen Wirkstoff eintippen").
  - `nummernkreis` – zentrale Zähler je Präfix (K, L, PA, R, RZ, P, AN, VP, FP, RE, AB …).
  - `benutzer` – interne Mitarbeiter: `name`, `email` (unique, Login), `pass_hash`, `rollen` (CSV-Set, z. B. „finance,einkauf"), `aktiv`, `letzter_login`. `seed_benutzer_if_empty()` legt den ersten Admin an (admin@bulkify.local / admin). Rechte-Logik in `core/auth.php` (Rollen, Route-Rechte). Kundenportale bleiben getrennt (Magic-Link).
  - **Preis-Engine** (Phase A): `produkt.exklusiv` (Katalog vs. exklusiv; `kunde_id` = Besitzer nur wenn exklusiv). `pack_ek_staffel` (Behälter-EK je Bestellmenge). `produkt_preis` (generierte Matrix Stück × Verpackung × Bestellmenge → EK/VK). Helfer: `marge_min_prozent()`, `marge_typ_prozent($form)`, `std_stueckzahlen()`, `std_bestellmengen()` (alle aus app_meta), `pack_ek_bei_menge()`, `produkt_variante_ek/vk()`, `vk_fuer_kunde()` (Kundenrabatt `kunden.rabatt_marge`), `produkt_matrix_generieren($produkt_id)` (Kapselgröße → passende Behälter aus `pack_kapazitaet` → EK+VK je Bestellmenge).
  - **Packungsgröße je Darreichungsform:** Die Spalte `produkt_preis.stueck` bedeutet je nach Form etwas anderes – Stückzahl (Kapsel, Tablette, Softgel, Stick), **Gramm** (Pulver/Granulat) oder **Milliliter** (Flüssig). Raster: `std_stueckzahlen()`, `std_fuellgewichte()` (`std_fuellgewicht_g`), `std_fuellvolumen_ml()` (`std_fuellvolumen_ml`) – gebündelt in `std_groessen_fuer($form)`. Beschriftung/Abfrage einheitlich über `form_groessen_einheit($form)` ('g'/'ml'/''), `form_ist_fuellmenge($form)`, `form_plural($form)`, `form_groessen_label($form,$wert)` („300 g", „250 ml", „120 Kapseln").
  - **Behälter-Zuordnung** (`passende_behaelter_fuer`): Kapsel/Softgel über `pack_kapazitaet`, Pulver/Granulat/Stick/**Tablette** über `item.max_fuellgewicht_g`, **Flüssig** über `item.volumen_ml`. Je Material der kleinste passende Behälter.
  - **Tablette:** kein Leerkapsel-EK, dafür Presshilfsstoffe (Füllstoff/Trennmittel/Überzug) – `tablette_hilfsstoff_prozent()` (app_meta `tablette_hilfsstoff_prozent`, Standard 20 %) und `tablette_hilfsstoff_ek_kg()` (`tablette_hilfsstoff_ek_kg`, Standard 8 EUR/kg). `tablette_gewicht_mg($rid)` = Wirkstoffe + Hilfsstoffe (Basis der Behälter-Auswahl), `tablette_hilfsstoff_ek_stueck($rid)` = EK-Zuschlag je Tablette.
  - **Flüssig:** Die Rezeptur beschreibt eine Portion; `fluessig_portion_ml()` (app_meta `fluessig_portion_ml`, Standard 10 ml) sagt, wie viele Portionen in eine Flasche gehen, `fluessig_basis_ek_l()` (`fluessig_basis_ek_l`, Standard 3 EUR/L) rechnet die Trägerflüssigkeit je ml Füllvolumen dazu.
  - **Angebot als Matrix→Auftrag:** `auftrag_aus_zelle($angebot_id,$stueck,$verp_id,$bestellmenge)` erzeugt aus einer gewählten Preismatrix-Zelle Auftrag+Rechnung+Produktion; VK wird serverseitig aus `produkt_preis` geprüft (+ Kundenrabatt). Gewählte Stückzahl/Verpackung landen in `auftrag.stueck/verpackung_id` und `produktionsauftrag.stueck/verpackung_id` (Produktion nutzt diese, nicht produkt.einheiten). `angebot.kunde_ausgeblendet` = vom Kunden „gelöscht". Produktionszeit-Schätzung: `app_meta.produktionszeit_wochen`.
  - `portal_anfrage` – Kundenanfragen aus dem Portal (typ produkt/rohstoff/dienstleistung, Nr. PAF-); Produktanfrage mit produkt_id/stueck/verpackung_id/menge, sonst betreff/notiz. Portal-Freischaltungen je Kunde: `kunden.portal_rezeptur/produkte/rohstoffe/dienstleistung`.
- **Feste Nummern:** `naechste_nummer('K')` → z. B. „K-0006" (atomar hochgezählt). `item_prefix($kategorie)` wählt für Warenlager-Items das Präfix (Rohstoff=R, Verpackung=VP, Fertigware=FP …). Beim Anlegen vergeben die Detailseiten die Nummer automatisch, wenn das Feld leer ist.
  - `aktivitaet` – das zentrale Ereignis-Protokoll für den Verlauf, für **jedes** Objekt (über `objekt_typ` + `objekt_id`).
- **Migrations-Werkzeug:** `table_exists`, `column_exists`, `ensure_column` – so kommen später neue Felder **additiv** dazu (nur hinzufügen, nie löschen). Alte Daten bleiben unberührt.
- **Einstellungen:** `meta_get` / `meta_set` – lesen/schreiben in `app_meta`.
- **Protokoll:** `log_aktivitaet(objekt_typ, objekt_id, akteur, text, typ, ref_typ, ref_id)` – schreibt einen Verlaufseintrag (Zeit als UTC). **Jedes künftige Modul ruft das auf**, dann erscheint der Eintrag automatisch im Verlauf des Objekts (Kunde/Lieferant/…). `verlauf_fuer(objekt_typ, id)` liest ihn zurück.
- **Testdaten:** `seed_kunden_if_empty` / `seed_lieferanten_if_empty` / `seed_aktivitaet_if_empty` – füllen lokal Demo-Daten, wenn leer.
- **Behälter-Stammdaten (Seeds, je einmalig über einen Marker in `app_meta`):** `seed_behaelter_kapazitaet()` legt die Standard-Gebinde an (PET Packer + Weithalsglas 100/150/200/250 ml, PLA, Flip Packer) inkl. Kapsel-Fassung; `seed_etikett_preise()` die Etiketten-Staffeln je Gebinde; `seed_standbodenbeutel()` die Beutel XS–XXL; `seed_packari_behaelter()` die **EK-Preise + Mengenstaffeln von Packari** für PET-Dosen und Weithalsgläser (100–250 ml) und die vier **Deckel mit Pressure-Seal-Einlage** (38/400 + 45/400, je weiß und schwarz) als eigene Artikel mit `verpackung_rolle='verschluss'`. Der Gebinde-EK ist der Preis **ohne Verschluss**; der Deckel-EK ist die Differenz „Set minus Dose ohne Verschluss" (Bezugsdose steht in der Notiz des Deckels). Alle Seeds sind nicht-überschreibend: sie füllen nur, was leer ist.

**Wichtig / Regel:**
- Reihenfolge in `init_schema`: erst `CREATE` (frisch installierbar), dann `ensure_column`-Migrationen. Nie eine Spalte löschen.
- `akteur` im Protokoll steuert die Chat-Seite: `team` = wir (links), `system` = mittig, alles andere (z. B. `kunde`, `lieferant`) = Gegenstelle (rechts).
