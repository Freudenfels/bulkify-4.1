# module/produktion/neu.php – Produktion (neu)

Neue Produktionsübersicht, **im Aufbau**. Route `?p=produktion_neu`, Menü: Produktion → „Produktion (neu)"
(direkt unter „Produktion"). Erst nur für **Admin** sichtbar (`route_rollen_map`).

## Warum eine eigene Seite?
Nico (08.11.2026): Die neue Sicht wird parallel gebaut, ohne die bestehende Produktionsliste
(`module/produktion/liste.php`) zu ändern. Wenn sie fertig ist, ersetzt sie die alte Liste.

## Stand
**Stufe 1 (08.11.2026): Übersicht aller Produktionsaufträge.** Spalten: Nummer, Produkt (kundenspezifischer Name,
sonst Auftrag-/Produktname, Bulk = Rezeptur · Bulk), Kunde (ohne Kunde = „Lager"), Menge (Stk), Seit (Anlagedatum,
Berliner Zeit), Tage (seit Anlage bis heute). Standard: **älteste zuerst**; Spalten sortierbar.
Reiter Offen (alles außer erledigt, auch Beschaffungsweg offen) / Abgeschlossen / Alle. Stornierte PAs und PAs
stornierter Aufträge fehlen (`pa_aktiv_sql()`). Zeilen-Klick öffnet den Produktionsauftrag. Rein lesend.

Geplant: was jetzt machbar ist (z. B. „Etiketten da, noch nicht etikettiert"), Wartezeit je Schritt.
