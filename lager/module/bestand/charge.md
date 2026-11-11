# bestand/charge.php – Charge-Detail (`?p=charge&id=`)

Alle Angaben zu einer Charge an einem Ort:

- **Kennzahlen:** Bestand, MHD (Ampel), Status, Chargennummer.
- **Blinker:** ist einer gebunden, gibt es Finden / Aus / Lösen (X). Ist keiner dran, ein Feld zum Scannen und Binden (bestätigt kurz blau, ohne Ton).
- **Angaben:** Rohstoff/Produkt, Kategorie, Artikelnummer, eingegangene und verfügbare Menge, MHD, Lieferant, Wareneingang, Sendungsnummer(n), Notiz.

Zusätzlich, wie im Dashboard, aber auf diese Charge bezogen:
- **Charge und Lieferung:** Menge, MHD, Status, Lieferant, Wareneingang, Sendungsnummer, Notiz, und die **Dokumente** (Lieferschein/CoA/Spec) als anklickbare Links (`?p=dok`).
- **Produkt:** Stammdaten (Name, Englisch/Botanisch, CAS, Form, Dichte, Allergene, Herkunft, Kunde) und **Wirkstoffe**.
- **Weitere Chargen dieses Produkts:** anklickbar.

**Bearbeiten direkt an der Kachel:** Jede Kennzahl-Kachel hat oben rechts einen Stift. Ein Klick klappt ein kleines Formular in der Kachel auf (Abbrechen schließt wieder). Bearbeitbar sind:
- **Bestand** (`menge_korr`, neue Menge + optional Grund; schreibt eine Lagerbewegung über die Differenz),
- **MHD** (`mhd_korr`, Datumsfeld, leer = kein MHD),
- **Status** (`status` → `erp_charge_status_setzen`; Freigegeben/Quarantäne/Gesperrt; deckt „Aus Quarantäne freigeben" ab),
- **Warenart** (`warenart` → `erp_charge_warenart_setzen`, setzt die Kategorie/Form des Artikels, z. B. fälschlich „Rohstoff" → „Fertigware"),
- **Einheit** (`einheit` → `erp_charge_einheit_setzen`, z. B. Pulver „Stk" → „kg"),
- **Lieferant** (`lieferant` → `erp_charge_lieferant_setzen`, tippen, neue werden angelegt; nur Lager 1),
- **Sendung / Paket** (`tracking` → `lg_tracking_set`, Paketlabel scannen/eintippen).
- **Rezeptur / Bulk-Artikel** (nur Bulk/Fertigware; `rezeptur_zuordnen` → `erp_charge_rezeptur_zuordnen`): hängt eine im
  Lager ad-hoc angelegte Charge (Artikelnummer leer = „lose – bitte zuordnen", gelb markiert) an das kanonische
  Bulk-Item der gewählten Rezeptur (setzt `charge.item_id`). So läuft verstreuter Fertigware-Bestand je Variante
  zusammen. Das Dropdown (aus `erp_rezeptur_liste()`, nur Rezepturen mit Bulk-Item) zeigt die **Kapselgröße**
  (`rezeptur.kapselgroesse_id` → `kapselgroesse.name`), damit sich gleichnamige Rezepturen je Größe (0/00)
  unterscheiden lassen – Voraussetzung: die Kapselgröße ist am Rezeptur gesetzt.

Charge und Lager sind nicht bearbeitbar (reine Anzeige).

**Änderungen (Protokoll):** Unter den Kacheln steht eine Tabelle „Änderungen" (Zeitpunkt via `fmt_zeit()`, Benutzer, Feld, vorher → nachher). Jeder der obigen Edits schreibt über `lg_charge_log_add()` einen Eintrag; gelesen wird mit `lg_charge_log_liste($id)`. Unveränderte Werte werden nicht protokolliert.

**Standort (Spec 6.2, nur Lager 1):** eigenes Panel – zeigt den aktuellen Standort (`erp_charge_standort`) und schaltet ihn um (`standort` → `erp_charge_standort_setzen`): „**An Produktion übergeben**" (→ `produktion`) bzw. „**Wieder einlagern (Lager 1)**" (→ `lager1`). Der Blinker bleibt dran, nur der Standort wandert. Beim Wiedereinlagern lässt sich optional das **Ist-Gewicht/-Menge** erfassen (Spec 7.13; schreibt Bestandskorrektur + Lagerbewegung). Fehlt die Dashboard-Spalte `charge.standort`, zeigt das Panel einen Hinweis statt zu buchen.

**Gebinde-Aufkleber (Spec 5.6):** Panel mit Link zu `?p=gebinde&charge=` (eigene QR-Codes je Gebinde erzeugen/verwalten) und – sobald welche existieren – zum PDF (`?p=gebinde_etikett`).

**Charge löschen:** eigenes Panel ganz unten – in den Mülleimer (`loeschen`), 30 Tage wiederherstellbar; blendet die Charge nur aus (das Dashboard behält sie).

Der **Produktname ist überall ein Link** (Bestand-Liste, Such-Popup) und führt hierher.

Liest über `erp_charge_voll()`, `erp_item_voll()`, `erp_item_wirkstoffe()`, `erp_item_dokumente()`, `erp_item_chargen()` aus dem geteilten Dashboard-Bestand. Bindet/löst Blinker über `core/leiste.php`.

**Etikettenrollen:** Hat die Charge Etikettenrollen (`erp_charge_rollen()` → `charge_gebinde` mit `inventar_nr`),
zeigt ein eigenes Panel je Rolle Inventaraufkleber, Rolle X/N, Menge, Status (+„in Produktion") und den
zugeordneten Auftrag; „Details" führt auf `?p=gebinde&inv=<nr>`.

## QM-Gate (St-11)
Quarantäne-Ware freigeben (Status auf „frei") darf nur QM (Rolle `labor`) oder Admin – Dashboard `darf_qm_freigeben()`, Lager-App `lg_darf_qm_freigeben()`. Andere Rollen sehen „Freigabe durch QM" statt des Knopfs; ein direkter POST wird abgewiesen. Sperren oder zurück in Quarantäne darf weiterhin jeder.

## Inventarnummern der Rollen korrigieren (03.11.2026)
In der Tabelle „Etikettenrollen“ ist die Nummer je Rolle ein Eingabefeld. „Ab Nummer aufsteigend“ (z. B. 11111111) füllt die Rollen in ihrer Reihenfolge mit Start, Start+1, … „Nummern speichern“ (POST `aktion=rollen_nummern`) ruft `erp_rollen_inventar_korrigieren()` auf:
- genau 8 Ziffern, keine Doppelnummer (auch nicht bei einer anderen aktiven Rolle; Tausch innerhalb der Eingabe erlaubt),
- jede Änderung im Feld-Protokoll (`aenderung_log`, Route `lager_rollen`) und in der Lager-Bewegung.

Leere Rollen sind gesperrt. Rollen, die nicht 8-stellig sind, tragen den Hinweis „nicht 8-stellig“. Enter springt zur nächsten Rolle (Handscanner).

Kachel **Kapselgröße** (Soll-Kette 2d, 04.11.2026), wenn an der Charge gespeichert.

Kachel **Für Auftrag** (Auftrag + Kunde, darunter Reservierungen), Soll-Kette 3 (04.11.2026).

- 08.11.2026: „Nächstes Einbuchen" zeigt auf `?p=we` (Schnellmaske stillgelegt).

## Dokumente aus der Bestellung (08.11.2026)
Unter „Dokumente“ stehen zuerst CoA und Spezifikation aus der Bestellposition der Charge (`erp_charge_bestell_dokumente`, Zusatz „aus der Bestellung“), danach die des Artikels/Produkts.

**Bewegungen (11.11.2026):** Neuer Block „Bewegungen“ mit den letzten 30 Bewegungen dieser Charge aus `lg_bewegung`, darunter die Inventur-Korrekturen mit Grund. Platz-Änderungen stehen unter „Änderungen“ (Feld `platz`).

**Menge korrigieren (Block B, 11.11.2026):** nur Admin (Stift nur für Admin). Formular mit Art (verfügbar / Ursprung), neuer Menge, Pflicht-Grund, Notiz und Beleg. Bucht über dieselbe Funktion wie das Dashboard (`core/charge_korrektur.php`): Korrekturbewegung, Verlauf, Gebinde, die Bestellung rechnet neu.
