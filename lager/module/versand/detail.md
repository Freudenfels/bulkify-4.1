# versand/detail.php – Sendung planen

Route `?p=versand_detail&id=<versand_id>`. Plant eine Sendung: Empfänger, Versandart, Positionen,
Lieferschein, Abschluss.

**Empfänger:** Kunde wählen (optional) → lädt dessen Adressen per AJAX (`aktion=adressen` →
`erp_kunde_adressen()`), **Lieferadresse ist vorausgewählt**; alle Felder (Firma, Name, Straße, PLZ, Ort,
**Land/ISO-Code – weltweit**, E-Mail, Telefon) bleiben frei editierbar. Snapshot wird am Versand
gespeichert (`lg_versand_kopf_speichern`), bleibt also stabil, auch wenn der Kunde seine Adresse später ändert.

**Versandart:** Paket / Palette, Pakete, Gewicht (kg), Notiz (erscheint auf dem Lieferschein).

**Positionen:** Bestand durchsuchen (`erp_bestand`) und per `aktion=pos_add` mit Menge übernehmen
(Charge-Snapshot in `lg_versand_pos`); freie Position auch möglich. Entfernen per `aktion=pos_del`.

**Versandart:** Bei **Palette/Fracht** erscheinen zusätzlich Maße je Packstück (Länge/Breite/Höhe cm,
`masse_l/b/h`) – für Cargoboard (Standard Europalette, falls leer).

**Versand-Label & Tracking:** Aktion „label" (`versand_label_erstellen()`): **Paket → DHL**,
**Palette → Cargoboard**. Erzeugt Label (PDF, `?p=versand_label`) + Sendungsnummer, speichert den Carrier.
Fehlt ein Zugang/Absender → klare Meldung. Zugänge/Absender in den Einstellungen.

**Dokumente drucken:** **Lieferschein drucken** und **Label drucken** schicken einen Druckjob an die
Brücke (`?p=druck_job` mit `typ=lieferschein`/`label`) → lautloser Druck auf den jeweils eingestellten
Drucker. „Öffnen (PDF)" zeigt das Dokument im Browser (`?p=lieferschein` / `?p=versand_label`).

**Abschluss:**
- **Als versendet markieren & abbuchen** (`aktion=versenden`): bucht je Position den Bestand über
  `erp_charge_entnehmen()` ab (nur eigener Bestand, nur noch nicht abgebuchte), löst leere Blinker/Kisten,
  schreibt `lg_bewegung`, setzt Status `versendet`. Hinweise (z. B. Fremdlager) werden gemeldet.
- **Stornieren** (`aktion=stornieren`).

Bearbeiten nur solange Status `geplant`. Carrier-Label/Tracking: Phase 2/3.
