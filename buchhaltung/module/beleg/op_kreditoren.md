# op_kreditoren.php — Offene Posten (Kreditoren)

Zeilenebene-Liste aller offenen/teilbezahlten Eingangsrechnungen (`?p=op_kreditoren`). Je Rechnung:
Erfassungsnr., Lieferant, Lief.-Rechnungsnr., Datum, Fällig, Tage überfällig, Brutto, Bezahlt, Offen.
Filter „Alle offenen / Nur überfällig", druckbar, CSV über `?p=beleg_export&art=vop`.

Daten: `kr_liste('offen')` in core/kreditor.php (lieferant_rechnung − Summe lieferant_zahlung).
