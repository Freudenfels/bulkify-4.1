# system/einstellungen.php – Lager-Einstellungen (`?p=einstellungen`, nur Admin)

Hub für alles rund ums Lager, in **Reitern** (`.settabs`, `?reiter=`): **Drucker · Formate · Zugänge**.

**Drucker** (`reiter=drucker`, Standard):
- Etikettengröße (fest 100×150) + Drucker-Zuordnung je Dokument: Karton-Etikett (`drucker_name`),
  Lieferschein (`drucker_lieferschein`), Versand-Label (`drucker_versandlabel`) – leer = Standarddrucker.
  Druckerliste kommt von der Brücke (`drucker_liste`).
- **Brücke auf dem Lager-PC**: Status (läuft/aus) + Diagnose, Downloads „Brücke einrichten (Hintergrund)"
  (`?p=bruecke_skript&art=hintergrund`) / „mit Fenster" / Reset, SumatraPDF-Link, „Schlüssel neu erzeugen"
  (`aktion=token_neu`). Die Brücke macht BEIDES: Blinker leuchten + Etiketten/Dokumente drucken.
- **Blinker/Sender**: Links zu `?p=sender` und `?p=leisten`.

**Formate** (`reiter=formate`): **Lieferschein-Absender** (`versand_absender`, mehrzeilig), **strukturierter
Absender** (`absender_name/strasse/hausnummer/plz/ort/land/email/telefon`) – Pflicht für die Carrier-Labels –
und Standard-Versandart (`versand_typ_standard`).

**Zugänge** (`reiter=zugaenge`): API-Schlüssel für **DHL – Paket** (`dhl_api_user/_key/_secret`,
`dhl_abrechnungsnummer`, `dhl_sandbox`) und **Cargoboard – Palette/Fracht** (`cargoboard_api_key`,
`cargoboard_sandbox`). Secrets werden nur bei Eingabe überschrieben (leer = unverändert) und nie wieder im
Klartext angezeigt. Liegen in `lg_meta` (DB, nicht im Repo) – fürs scharfe Deployment später nach
`secrets.php` auslagern. **Werden von `core/versand.php` genutzt**: Paket → DHL, Palette → Cargoboard
(Label erstellen auf der Sendungsseite). Sobald die echten Zugänge eingetragen sind, laufen die Labels scharf.

Alle Werte über `lg_meta_lesen/_schreiben`. Druckweg: SumatraPDF, siehe `bruecke/bruecke.ps1`.
