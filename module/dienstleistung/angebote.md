# module/dienstleistung/angebote.php - DL-Angebote (Liste)

Route `?p=dl_angebote`. Liste aller Dienstleistungs-Angebote (angebot.kategorie='dienstleistung'), getrennt von den Produkt-Angeboten. Eigener Nummernkreis `DA-`.

- Oben: "Neues DL-Angebot" (Kunde optional) -> legt ein leeres DA-Angebot an (`dl_angebot_neu`) und springt in den Editor.
- Tabelle: Nummer, Kunde, Positionen, Netto, Status, Auftrag. Zeile -> `?p=dl_angebot`.
- Untermenue via `dl_subtabs()` (Katalog/Angebote/Aufträge/Rechnungen).
