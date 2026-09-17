# Kapselgrößen – Nachschlagewerk (kapsel_referenz.php)

Reine Info-/Lookup-Seite (Route `?p=kapsel_referenz`, verlinkt aus Einstellungen → Produktion).
Zeigt je Kapselgröße die Standard-Füllgewichte bei Dichte 0,45/0,70/1,00, das Volumen (ml),
die Verschlusslänge, Ø Kappe/Körper und das Leergewicht der Hülle.

Datenquelle = `kapsel_referenz_tabelle()` in core/schema.php (EINE Wahrheit): dieselben Werte
befüllen per `seed_kapsel_referenz()` die Spalten der Tabelle `kapselgroesse`
(volumen_ml, fuell_light_mg, fuell_typ_mg, fuell_heavy_mg, leergewicht_mg) – nur wo noch leer,
damit Team-Anpassungen erhalten bleiben.

Nutzung in der Berechnung: `rezeptur_kapselgroesse()` wählt die Größe dichteabhängig
(`rezeptur_mix_dichte()` × Volumen); fehlt die Dichte, gilt der Backup-Wert `fuellmenge_mg`.
Das PIB zeigt Volumen + Kapsel-Kapazität je Dichte + genutzte Rezeptur-Dichte.
