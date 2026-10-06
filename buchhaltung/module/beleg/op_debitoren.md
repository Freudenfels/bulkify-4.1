# op_debitoren.php — Offene Posten (Debitoren)

Zeilenebene-Liste aller offenen/teilbezahlten Kundenrechnungen (`?p=op_debitoren`). Zeigt je Rechnung
Nummer, Kunde, Datum, Fällig, Tage überfällig, Brutto, Bezahlt, Offen und die Mahnstufe. Filter „Alle
offenen / Nur überfällig", optional je Kunde (`&kunde=<id>`). Druckbar (Print-CSS blendet Navigation aus),
CSV über `?p=beleg_export&art=op`, Button direkt in den Mahnlauf (`?p=mahnlauf`).

Daten: `bh_op_rechnungen($filter,$kunde_id)` in core/buchhaltung.php (brutto − Summe zahlung = Offen).
