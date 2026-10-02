# produktion/module/produktion/detail.php
Detail eines Produktionsauftrags: Kennzahlen (Status/Menge/Kunde/Herstellung) + Schrittliste über `erp_pa()`/`erp_pa_schritte()`.

Der jeweils **erste offene** Schritt ist mit „als Nächstes" markiert und trägt einen **Abschließen**-Button. Der POST (`aktion=schritt_ab`, `schritt_id`) ruft `erp_schritt_abschliessen($schritt_id, $benutzername)` auf und leitet danach um (Post/Redirect/Get, damit kein Reload doppelt bucht). Erfolg/Mangel kommt als Flash zurück. Reicht der Bestand für die FEFO-Entnahme nicht, bleibt der Schritt offen und die Meldung zeigt, was fehlt. Zeiten über `fmt_zeit()` (UTC → Berlin).
