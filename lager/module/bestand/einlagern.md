# bestand/einlagern.php – Einlagern (`?p=einlagern`)

Zeigt die offenen **Übergaben der Produktion ans Lager** (`aufgabe` mit `ref_typ='einlagern'`,
`ref_id=produktionsauftrag.id`, Status offen) und bietet je Zeile einen **Ein-Klick „Einlagern"**.

- Liste: `erp_einlager_aufgaben()` (Titel enthält das Ziel „→ Lager 1/2", Beschreibung Auftrag/PR/Kunde/Menge).
- Buchen (`aktion=buchen`, `pa_id`): `erp_einlager_buchen($pa_id)` ruft die **kanonische Dashboard-Funktion
  `einlager_buchen()` per Loopback** auf (`POST {host}/?p=api_einlager` mit `pa_id` + Token aus
  `app_meta['einlager_api_token']`). Die Buchung (Fertigware-Charge, BSKU, Lager 1/2) macht das Dashboard –
  **nicht im Lager nachgebaut** (db()-Kollision + Divergenz). Fehlt der Dashboard-Endpunkt, kommt eine klare
  Meldung.

Nav: Lager 1 (zwischen „Erwartete Lieferungen" und „Suche").
