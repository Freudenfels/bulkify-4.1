# core/portal_hilfe.php – Anleitung im Kundenportal (13.11.2026)

Texte für „Info & Tools“ und den geführten Rundgang in der Sprache des Kunden (Deutsch für DE/AT/CH/LI/LU, sonst Englisch; `portal_hilfe_sprache()` = `etp_sprache()`).
- `portal_hilfe_ablauf($sp, $L)`: je Ablauf eine Karte (Icon aus `bx_nav_icon`, Titel, 2-4 Sätze) in der Reihenfolge des echten Ablaufs: Anfrage → Rezeptur-Vorschlag → Spezifikation → Angebot annehmen/ablehnen → PIB → Etikett → Fortschritt (wichtigste Schritte) → Labor → Lieferung/Teillieferung → Rechnungen → Nachbestellen (nur wenn der Menüpunkt da ist) → Mein Lager (nur Fulfillment).
- `portal_hilfe_texte($sp)`: feste Texte der Seite, Karten „Schnell zu Ihren Unterlagen“ (Kachel heißt „Angebote & Anfragen“), Rundgang-Schritte (Angebote & Anfragen zielt auf `v=meine_anfragen`, plus Etiketten, Rechnungen, Nachbestellen) und Knöpfe.
- Hinweis: Das übrige Kundenportal ist nur deutsch; die englischen Texte nennen die Menüpunkte englisch.

## 13.11.2026: keine Etikettprüfung durch bulkify
Anleitung und Rundgang sagen nicht mehr „wir prüfen“, sondern „das System prüft Format und Druckdaten automatisch“; dazu der KI-Hinweis (kann Fehler machen, ersetzt keine fachliche oder rechtliche Prüfung – bitte selbst prüfen).
