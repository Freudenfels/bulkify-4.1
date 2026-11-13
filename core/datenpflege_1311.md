# core/datenpflege_1311.php – einmalige Datenpflege live (Paket 13.11.2026, Teil 6.6)

Freigabe Nico (Arbeitsanweisung 13.11.2026): **BE-2890** (Bündel aus Bulk-Positionen für mehrere Aufträge) wird in Einzelbestellungen aufgeteilt, eine je Position.

- **Ablauf:** Läuft genau einmal aus dem Schema-Abgleich (`dpf_1311_einmal()`, Merker `app_meta dpf_1311_erledigt`).
- **Funktion:** `bestellung_aufteilen_je_position()` (`core/bestellung_einzeln.php`). Die erste Position bleibt in BE-2890, jede weitere bekommt eine neue BE-Nummer.
  - Positionen werden verschoben: Liefermengen, stornierte Positionen, Chargen-Verknüpfungen, Dokumente je Position und Lieferanten-Aufkleber bleiben gültig.
  - Kopfdaten werden übernommen. Es wird kein Lieferant gesetzt und keine Nachricht verschickt.
- **Verlauf** an jeder Bestellung: „aufgeteilt, Freigabe Nico 13.11.2026“.
- **Ergebnis** (neue Nummern): `app_meta dpf_1311_ergebnis` und im Verlauf von BE-2890.
