# datenpflege_1211.php – einmalige Datenpflege live (Paket 12.11.2026 Teil 1)

Von Nico am 11.11.2026 abends ausdrücklich freigegeben. Läuft **genau einmal** beim Schema-Abgleich
(`dpf_1211_einmal()` aus `core/schema.php`, Merker `app_meta dpf_1211_erledigt`). Das Ergebnis steht als JSON in
`app_meta dpf_1211_ergebnis` und in den Verläufen der betroffenen Objekte („Freigabe Nico 11.11.2026“).

## Was passiert
1. **Alte Entwürfe stornieren:** BE-2871, BE-2872, BE-2875, BE-2884. Vor dem Stornieren wird die Bedingung neu geprüft
   (Status Entwurf, älter als 14 Tage, ohne Position oder ohne Lieferant). Passt sie nicht mehr, bleibt die Bestellung.
   Weg: `bestellung_stornieren()` (nicht löschen).
2. **Als geliefert schließen:** BE-2863 (Brain B Woman) und BE-2868 (Ackerbohne), Weg `webe_als_geliefert_schliessen()`,
   Notiz „laut Lieferübersicht Wellgreen vollständig geliefert, Rest war Einbuchfehler“.
3. **Lieferanten-Dublette:** GS Capsule (L-2839) → GS Capsules (L-2841). Alles mit `…lieferant_id` (Charge, offene
   Bestellungen, Preise, Login, Nachrichten usw.) und die Lieferanten-Dokumente ziehen um. Bleiben stehen: Kreditoren-
   Rechnungen und gelieferte/stornierte Bestellungen. Leere Kontaktfelder am Ziel werden ergänzt (Malte bleibt).
   Der alte Name wird Lieferanten-Alias. L-2839 wird **gesperrt, nicht gelöscht**.
4. **Tippfehler HMPC → HPMC** in allen Artikelnamen (Groß-/Kleinschreibung genau). Der alte Name kommt in `item.synonyme`,
   damit Suchen weiter treffen.

## Testen
`dpf_1211_ausfuehren($konfig)` nimmt beliebige Nummern; in der Testkopie mit eigenen Testdaten geprüft und danach
nur die eigenen Zeilen entfernt.
