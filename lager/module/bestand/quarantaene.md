# quarantaene.php (Lager)

Liste **aller Chargen in Quarantäne** (eigenes Lager, ohne Fremdlager) mit Knopf „Freigeben". Optional `?item=<ID>` zeigt nur einen Artikel.
Ziel der Links „in Quarantäne – freigeben" aus Auftrag, Produktionsauftrag, Einkaufsliste und Werk-App (St-07/St-12) – die Freigabe ist damit
nicht mehr nur über „die letzten 25 Chargen" erreichbar.

Freigeben ruft `erp_charge_status_setzen($id,'frei')` (setzt auch den Bedarf-Cache zurück). Ab dann zählt die Ware
als verfügbar. Die Seite ist in `public/lager/index.php` als Route `quarantaene` eingetragen und im Menü unter „Lager 1".
