# core/eingangsrechnung.php – Lieferanten-Rechnung direkt in die Buchhaltung (13.11.2026)

Lädt ein Lieferant im Portal eine Rechnung zur Bestellung hoch (`bestell_dok_upload`, Typ „rechnung“) oder trägt das Team sie an der Bestellung ein (`bestellung_team_rechnung_hochladen`), legt `er_entwurf_aus_dokument($dok_id, $quelle)` nach der Antwort an den Browser einen **Entwurf** in `lieferant_rechnung` an (`status='entwurf'`, verknüpft mit Bestellung, Lieferant, Dokument).
- KI (`er_ki_lesen`, `ki_datei_frage`): Rechnungsnummer, Datum, Netto, USt, Brutto, Währung, Fälligkeit/Zahlungsziel, Bankverbindung; unsichere Felder in `ki_unsicher` (gelb in der Prüfliste). Vom Team eingetippte Nummer/Datum/Betrag haben Vorrang.
- Bankverbindung nur vergleichen (`er_bank_vergleich`) – Abweichung = rote Warnung „Bankverbindung weicht ab“ in `bank_warnung`, nie übernehmen.
- Doppelt (gleiche Nummer, gleicher Lieferant, nicht storniert): kein Entwurf, Vermerk `dokument.befund = 'doppelt: ER-…'`.
- Aufgabe „Eingangsrechnung prüfen: L-…, RE-Nr., Betrag“ (normal; dringend, wenn in unter 7 Tagen fällig) mit Ziel `/buchhaltung/?p=lief_rechnung_pruefen&id=…`.
- Für das Lieferantenportal: `er_status_bestellung($bestellung_id)` + `er_status_text($r, $sprache)` („bezahlt am …“, teilbezahlt mit Rest, „eingegangen, wird geprüft“).
- Keine Zahlung, keine Mail an den Lieferanten. Testkopie ohne KI-Schlüssel: eingespielte Antwort `data/er_ki_test.json`.
- Tabelle gehört der Buchhaltung (`kreditor_init`); hier nur der Entwurf und additive Spalten (`dokument_id`, `quelle`, `ki_json`, `ki_unsicher`, `bank_re`, `bank_warnung`, `geprueft_am`, `geprueft_von`).

**Fable-6 P10/T4 (13.11.2026) – Rechnung ein Weg:**
- Jede Lieferanten-Rechnung wird zur Eingangsrechnung (Entwurf): Portal, Team-Upload an der Bestellung und jetzt auch das Bestell-Pop-up. Für Altfälle gibt es an der Bestellung „Eingangsrechnung anlegen“ (`aktion=er_anlegen`).
- „bezahlt“ gibt es nur in der Buchhaltung. `er_link_bestellung()` liefert den Link „In der Buchhaltung bezahlen“: bei einem Entwurf auf „prüfen“, sonst auf die Eingangsrechnung.
- **Ersatz-Upload** (`dokument.ersatz_fuer`) legt keinen zweiten Entwurf an. `er_ersatz_anhaengen()` hängt die neue Datei an die vorhandene Eingangsrechnung; die alte Datei bleibt gespeichert (Vermerk in der Notiz). Ist die Rechnung schon erfasst, bekommt die Buchhaltung die Aufgabe „Ersatz-Rechnung prüfen“.
- **Doppel-Erkennung:** Die Rechnungsnummer wird normalisiert verglichen (`er_nr_norm`: „inv 2026-0815“ = „INV-2026-0815“). Bei einem noch offenen Entwurf ersetzt die neue Datei die alte.
