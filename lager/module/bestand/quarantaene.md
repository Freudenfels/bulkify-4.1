# quarantaene.php (Lager)

Liste **aller Chargen in Quarantäne** (eigenes Lager, ohne Fremdlager) mit Knopf „Freigeben". Optional `?item=<ID>` zeigt nur einen Artikel.
Ziel der Links „in Quarantäne – freigeben" aus Auftrag, Produktionsauftrag, Einkaufsliste und Werk-App (St-07/St-12) – die Freigabe ist damit
nicht mehr nur über „die letzten 25 Chargen" erreichbar.

Freigeben ruft `erp_charge_status_setzen($id,'frei')` (setzt auch den Bedarf-Cache zurück). Ab dann zählt die Ware
als verfügbar. Die Seite ist in `public/lager/index.php` als Route `quarantaene` eingetragen und im Menü unter „Lager 1".

## QM-Gate (St-11)
Quarantäne-Ware freigeben (Status auf „frei") darf nur QM (Rolle `labor`) oder Admin – Dashboard `darf_qm_freigeben()`, Lager-App `lg_darf_qm_freigeben()`. Andere Rollen sehen „Freigabe durch QM" statt des Knopfs; ein direkter POST wird abgewiesen. Sperren oder zurück in Quarantäne darf weiterhin jeder.

## Quarantäne-Übersicht (Nacht 1b)
Die Übersicht (`?p=wareneingang&quarantaene=1`, Knopf „Nur Quarantäne (Anzahl)") zeigt alle Chargen in Quarantäne mit Bestand: Charge, Artikel, Menge, **Eingang**, MHD, **Lieferant / Herkunft** (bei Fertigware „Produktion PR-…"), Status und „freigeben" (nur QM/Admin), älteste zuerst. Kein Direkt-frei-Haken beim Wareneingang. Lager-App `?p=quarantaene` zeigt die Herkunft ebenso.

## Sofortfix 13.11.2026: Unterlagen-Sperre wird Quarantäne-Sperre
Regel Nico: Unterlagen fehlen = Ware bleibt in Quarantäne. Bestellen und Einbuchen gehen immer. Artikel ohne Unterlagenpflicht: „kein Dokument nötig“ (Details `core/lieferant_unterlagen.md`).

Je Charge der Grund „Quarantäne: CoA/Spec des Lieferanten fehlt“; statt „freigeben“ steht dann „Freigabe erst, wenn die CoA/Spec da ist“.
