# core/eingangsrechnung.php – Lieferanten-Rechnung direkt in die Buchhaltung (13.11.2026)

Lädt ein Lieferant im Portal eine Rechnung zur Bestellung hoch (`bestell_dok_upload`, Typ „rechnung“) oder trägt das Team sie an der Bestellung ein (`bestellung_team_rechnung_hochladen`), legt `er_entwurf_aus_dokument($dok_id, $quelle)` nach der Antwort an den Browser einen **Entwurf** in `lieferant_rechnung` an (`status='entwurf'`, verknüpft mit Bestellung, Lieferant, Dokument).
- KI (`er_ki_lesen`, `ki_datei_frage`): Rechnungsnummer, Datum, Netto, USt, Brutto, Währung, Fälligkeit/Zahlungsziel, Bankverbindung; unsichere Felder in `ki_unsicher` (gelb in der Prüfliste). Vom Team eingetippte Nummer/Datum/Betrag haben Vorrang.
- Bankverbindung nur vergleichen (`er_bank_vergleich`) – Abweichung = rote Warnung „Bankverbindung weicht ab“ in `bank_warnung`, nie übernehmen.
- Doppelt (gleiche Nummer, gleicher Lieferant, nicht storniert): kein Entwurf, Vermerk `dokument.befund = 'doppelt: ER-…'`.
- Aufgabe „Eingangsrechnung prüfen: L-…, RE-Nr., Betrag“ (normal; dringend, wenn in unter 7 Tagen fällig) mit Ziel `/buchhaltung/?p=lief_rechnung_pruefen&id=…`.
- Für das Lieferantenportal: `er_status_bestellung($bestellung_id)` + `er_status_text($r, $sprache)` („bezahlt am …“, teilbezahlt mit Rest, „eingegangen, wird geprüft“).
- Keine Zahlung, keine Mail an den Lieferanten. Testkopie ohne KI-Schlüssel: eingespielte Antwort `data/er_ki_test.json`.
- Tabelle gehört der Buchhaltung (`kreditor_init`); hier nur der Entwurf und additive Spalten (`dokument_id`, `quelle`, `ki_json`, `ki_unsicher`, `bank_re`, `bank_warnung`, `geprueft_am`, `geprueft_von`).
