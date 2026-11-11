# Versandbereit (Lager-App, `?p=versand_bereit`)

Soll-Kette 7 (Nico/Dustin 04.11.2026). Zeigt alle Aufträge, deren Produktion abgeschlossen ist (Status erledigt) und die noch nicht komplett versendet sind – je Kunde gruppiert, mit offener Menge und freier Fertigware.

- **Versandart** je Auftrag: Paket an die Kundenadresse, Palette, Selbstabholung, Einbuchen in Lager 2 (nur bei Fulfillment-Kunden). Gespeichert am Auftrag (`auftrag.versandart`).
- **Sendung aus angehakten Aufträgen**: nur Aufträge EINES Kunden mit derselben Versandart. Legt eine Sendung (`lg_versand`) mit der Lieferadresse des Kunden an; Positionen = freie Fertigware-Chargen der Aufträge (FEFO, `lg_versand_pos.auftrag_id`). Danach geht es auf der Sendung weiter (Packstücke, Lieferschein, Label, versenden).
- **Ins Lager 2 buchen**: Umbuchung statt Versand, über das Dashboard (`auftrag_ins_fremdlager`).
- Gebucht wird im Dashboard über `erp_dashboard_versand()` (Loopback `?p=api_versand`).

Seit 11.11.2026 nur Aufträge, die laut Dashboard wirklich fertig sind (alle Mengen, Labor). Ist das Dashboard nicht erreichbar: Hinweis oben, Liste ungefiltert.
