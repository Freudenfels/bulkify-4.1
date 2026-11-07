# module/produktion/neu.php – Produktion (neu)

Neue Produktionsübersicht, **im Aufbau**. Route `?p=produktion_neu`, Menü: Produktion → „Produktion (neu)"
(direkt unter „Produktion"). Erst nur für **Admin** sichtbar (`route_rollen_map`).

## Warum eine eigene Seite?
Nico (08.11.2026): Die neue Sicht wird parallel gebaut, ohne die bestehende Produktionsliste
(`module/produktion/liste.php`) zu ändern. Wenn sie fertig ist, ersetzt sie die alte Liste.

## Stand
**Stufe 1 (08.11.2026): Übersicht aller Produktionsaufträge.** Spalten: Nummer, Produkt (kundenspezifischer Name,
sonst Auftrag-/Produktname, Bulk = Rezeptur · Bulk), Kunde (ohne Kunde = „Lager"), Menge (Stk), Seit (Anlagedatum,
Berliner Zeit), Tage (seit Anlage bis heute), **Produziert** (Summe der Chargen mit `charge.pa_id` = dieser PA; bei
Produkt-PAs nur Fertigware `verkaufsfertig`, bei Bulk-PAs alle; „–" = noch nichts, Teilmenge mit %, komplett grün). Standard: **älteste zuerst**; Spalten sortierbar.
Reiter Offen (alles außer erledigt, auch Beschaffungsweg offen) / Abgeschlossen / Alle. Stornierte PAs und PAs
stornierter Aufträge fehlen (`pa_aktiv_sql()`). Zeilen-Klick öffnet den Produktionsauftrag. Rein lesend.

**Spalte „Wartet auf" (08.11.2026):** was fehlt, damit der PA weiter bzw. fertig wird – aus denselben Quellen wie die alte Liste
(keine eigene Statuslogik), in dieser Reihenfolge:
- erledigt: „QM-Freigabe (X Stk in Quarantäne)" (Fertigware-Chargen des PA in Quarantäne), sonst „nichts – fertig".
- Status vorbereitung: „Beschaffungsweg festlegen" (rot). Eigen/Fremd nicht festgelegt: „Eigen/Fremd festlegen" (rot).
- noch nicht begonnen + Material fehlt (`produktion_bereitschaft`): „Material: Rohstoff, Verpackung, Etikett …" (rot, Tooltip
  mit Artikeln + Fehlmengen); ist alles Fehlende bestellt (`produktion_material_bestellt`): „Lieferung: … (bis Datum)".
- sonst nächster offener Schritt (`produktion_schritt`), vor dem Start mit „Start: …". Beim **Etikettieren** zusätzlich
  „(Etiketten da)" grün bzw. „– Etiketten fehlen" rot (Rolle Etikett aus `auftrag_bedarf_rest`).
Seite ist schreibfrei; `bx_stock_cache` wird für den Aufruf genutzt und am Ende gelöscht.

Geplant: Wartezeit je Schritt (seit wann im aktuellen Schritt), Filter nach „Wartet auf".
