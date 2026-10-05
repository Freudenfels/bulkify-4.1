# layout.php — Buchhaltungs-Programm
HTML-Hülle wie das Dashboard (lädt dasselbe /assets/app.css, gleiche bx-Klassen + JS-Helfer: Busy-Overlay, Dark-Mode, Menü), aber eigene schlanke Buchhaltungs-Navigation (bu_nav) + „Zurück zum Dashboard". render_header($aktiv,$titel)/render_footer(). Asset-/Logo-Pfade absolut (/assets/…), da unter /buchhaltung/ serviert.

## Navigation (gruppiert)
bu_nav() ist in Gruppen unterteilt: Start (Übersicht), **Ausgang (Debitoren)** (Rechnungen, Rechnung erstellen, Storno/Gutschrift, Angebote-Ansicht, Auftrag importieren, Alt-Rechnungen) und **Eingang (Kreditoren)** (Belege (KI), Eingangsrechnung). render_header rendert je Gruppe einen bx-navgroup-Header.
