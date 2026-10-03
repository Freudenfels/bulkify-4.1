# produktion/module/produktion/run.php
Produktionsmodus (`?p=run&id=…`) – tablettauglich, zum Schritt-für-Schritt-Abarbeiten eines Auftrags. Button dorthin steht auf der Detailseite („In den Produktionsmodus").

- **Kopf:** Menge, Charge + MHD (vom System vergeben, nur Anzeige), Fortschritt.
- **Jetzt dran:** der nächste offene Schritt groß mit Arbeitsanweisung (`station_anleitung_text`), der konkreten Material-/Produktliste **„Aus dem Lager holen"** (was + Menge + Bestand, via `erp_schritt_material()`; bei Fertigware mit Charge und „benötigt gesamt") und einem großen Button „Erledigt"/„Freigeben". Spalte „Blinker folgt" = Platzhalter für den Pick-to-Light-Auslöser (Lager-Integration offen). Der POST (`aktion=erledigen`) ruft `erp_schritt_abschliessen()` – protokolliert WER (angemeldeter Benutzer) WANN, bucht Material nach FEFO ab, letzter Schritt bucht Fertigware ein. Danach Redirect (PRG) + Flash.
- **Ablauf:** alle Schritte mit Status, **Erledigt von** und **Wann**.
- **Admin:** je Schritt „Abhaken"/„Zurücksetzen" (`aktion=admin_done`/`admin_undo` → `erp_schritt_status_setzen()`), auch außer der Reihe. Reine Statuskorrektur, KEINE Lager-/Chargenbewegung. Nur für Rolle admin (`pr_ist_admin()`).
