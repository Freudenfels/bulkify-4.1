# bestand/eingang.php – Wareneingang (Warenlager-Manager)

**Was kommt rein.** Artikel (Suchfeld über alle buchbaren Dashboard-Items: Rohstoff/Verpackung/Verbrauch/Fertigware, gesperrte raus) + Menge + optional Charge-Nr (Lieferant/CoA), MHD, Lieferant, **Anzahl Pakete/Kartons**, Notiz. Route `?p=eingang`. Die Paketzahl wird Lager-eigen gespeichert (`lg_pakete_set`) und steuert, wie viele Karton-Etiketten gedruckt werden (je Karton eines, „Karton X / N").

**Ablauf (reibungslos):** Buchen → `erp_wareneingang_buchen()` legt die Charge an (Rohstoff/Fertigware → Quarantäne, sonst frei; gleicht eine vorab aus einer CoA angelegte Charge gleicher Nummer ab statt Dublette) → Bewegung in `lg_bewegung` (`lg_bewegung_log`, Typ `ein`) → **Weiterleitung direkt auf die Charge-Detailseite** (`?p=charge&id=…&neu=1`), wo man **sofort einen Blinker anhängt oder in eine Kiste legt**. So sind Einbuchen und Einlagern ein Fluss.

**Naht:** Alles Schreibende ins Dashboard (`charge`) steckt in `erp_wareneingang_buchen()` in `core/erp.php` (spiegelt die Dashboard-Logik `wareneingang_buchen`). `erp_bedarf_bump()` macht den Dashboard-Bedarfs-Cache ungültig, damit Einkauf/Bedarf sofort stimmen.

Das Artikel-Feld ist ein kleines JS-Combo (Suche + Auswahl, zeigt Einheit je Artikel). Unten: „Zuletzt bewegt" (letzte `lg_bewegung`). Charge-Nr ist bewusst frei/optional (Lieferanten-Charge) – keine Auto-Nummer (die gibt es nur für Produktions-Output).
