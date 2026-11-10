# D3K2-Tropfen-Rechner (`?p=d3k2_rechner`)

Werkzeug zur **Schnellberechnung** einer D3K2-Tropfenmischung. Reine Rechen-/Lookup-Seite – **keine Datenbank**, speichert nichts.

## Was es macht
Man gibt vor, wie viel Vitamin D3 (in IE) und Vitamin K2 (in µg) in **einem Tropfen** oder **pro ml** sein soll. Der Rechner zeigt daraus:
- **Das brauchst du:** benötigte Menge D3-Rohstoff, K2-Rohstoff und Trägeröl (gesamt, pro Flasche, Anteil %).
- **Gehalt im Produkt:** D3 (IE/µg/% NRV) und K2 (µg/% NRV) je Tropfen, je ml, je Flasche.
- **Vergleich:** beide D3-Rohstoffe (100.000 IE vs. 1.000.000 IE) nebeneinander.

## Eingaben
- **Vorgabe bezieht sich auf:** pro Tropfen / pro ml (beim Umschalten werden die Werte automatisch umgerechnet).
- **D3 (IE), K2 (µg)** je Einheit.
- **Tropfen pro ml, Flaschengröße (ml), Anzahl Flaschen.**
- **Rohstoff-Gehalt angegeben pro ml / pro g** (bei „pro g" zusätzlich Dichte in g/ml).
- **D3-Rohstoff:** 100.000 oder 1.000.000 IE. **K2-Rohstoff:** % MK-7.
- **Überdosierung** D3/K2 in % (optional, eingeklappt).

## Rechenbasis
- 1 µg D3 = 40 IE.
- 1 % MK-7 = 10.000 µg je ml bzw. je g Rohstoff (je nach Auswahl).
- Trägeröl füllt das Restvolumen auf.
- NRV nach LMIV Anhang XIII: Vitamin D 5 µg, Vitamin K 75 µg.
- „Gehalt im Produkt" ist **ohne** Überdosierung angegeben. Maßgeblich bleibt das Analysenzertifikat der Charge.

## Technik
- Ganze Rechnung läuft **im Browser** (Inline-JS), damit es ohne Reload sofort reagiert; Zahlen im de-DE-Format.
- Styling über die Haus-Klassen (`bx-panel`, `bx-table`, `bx-field`, `bx-num`) + wenige seiten-lokale `.d3k2-*`-Regeln,
  die nur Theme-Variablen nutzen -> Dark Mode greift automatisch.
- Registriert in `public/index.php` (Route `d3k2_rechner`) und `core/auth.php` → `route_rollen_map()`
  (Rollen: production, labor, sales; admin immer). Im Ausführlich-Menü unter **Produkt** und im
  **Einfach-Menü** als eigener Punkt mit Taschenrechner-Icon (`bx_nav_einfach()` + `bx_nav_icon()`);
  zusätzlich verlinkt in **Einstellungen → Produktion** neben dem Nachschlagewerk Kapselgrößen.
- Herkunft: aus dem eigenständigen `d3k2-rechner/index.html` ins Dashboard übernommen und auf den bulkify-Look umgebaut.
