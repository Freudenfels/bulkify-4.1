# mahnung.php — Mahnwesen (Debitoren)

3-stufiges Mahnwesen mit Gebühren + optionalen Verzugszinsen, gefahren als Mahnlauf über die offenen Posten.
Eigene Tabellen `mahnlauf` + `mahnung`; Mahnstufe/letzte_mahnung stehen additiv am Beleg. Kein Eingriff in
Zahlungen (die laufen weiter über zahlung_erfassen in finanz.php).

- `mahn_config()`: Stufen/Fristen/Gebühren/Texte + Zins% + neue Frist aus app_meta (Defaults 7/14/28 Tage,
  0/5/10 €). Frist = Tage überfällig, ab denen die Stufe greift.
- `mahn_kandidaten()`: überfällige OP (bh_op_rechnungen) mit nächster fälliger Stufe + Gebühr/Zins/Summe.
- `mahn_naechste_stufe()`: eskaliert je Lauf um eine Stufe (mahnstufe+1, wenn Tage überfällig >= Frist).
- `mahn_erzeugen($beleg_id,$stufe,$opt)`: legt eine Mahnung an (Nummer MA-…), bumpt beleg.mahnstufe +
  letzte_mahnung, loggt. Rechnungsbetrag bleibt unverändert; Gebühr/Zins nur auf der Mahnung.
- `mahn_lauf($posten,$opt)`: mehrere Posten in einem Lauf; schreibt mahnlauf + Mahnungen.
- Lesen: mahn_laeufe/mahn_lauf_get/mahn_eintraege/mahn_get/mahn_fuer_beleg.

Seiten: `?p=mahnlauf` (Kandidaten + Lauf + Historie), `?p=mahnung&id=` (druckbarer Mahnbrief), Einstellungen
unter `?p=einstellungen&tab=mahnwesen`.
