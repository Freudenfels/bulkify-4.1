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

Charge und Lager sind nicht bearbeitbar (reine Anzeige).

**Änderungen (Protokoll):** Unter den Kacheln steht eine Tabelle „Änderungen" (Zeitpunkt via `fmt_zeit()`, Benutzer, Feld, vorher → nachher). Jeder der obigen Edits schreibt über `lg_charge_log_add()` einen Eintrag; gelesen wird mit `lg_charge_log_liste($id)`. Unveränderte Werte werden nicht protokolliert.

**Charge löschen:** eigenes Panel ganz unten – in den Mülleimer (`loeschen`), 30 Tage wiederherstellbar; blendet die Charge nur aus (das Dashboard behält sie).

Der **Produktname ist überall ein Link** (Bestand-Liste, Such-Popup) und führt hierher.

Liest über `erp_charge_voll()`, `erp_item_voll()`, `erp_item_wirkstoffe()`, `erp_item_dokumente()`, `erp_item_chargen()` aus dem geteilten Dashboard-Bestand. Bindet/löst Blinker über `core/leiste.php`.
