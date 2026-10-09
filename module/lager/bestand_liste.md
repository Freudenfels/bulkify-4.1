# lager/bestand_liste.php – Warenlager (Bestand)

**Zweck:** Bestandsübersicht aller lagernden Artikel (Rohstoffe, Verpackungen …). Der Bestand ergibt sich aus den **Chargen** je Item.

**Was passiert hier:**
1. `seed_charge_if_empty()` – legt lokal Demo-Chargen an.
2. Liest alle relevanten Items und rechnet je Item per Unterabfrage: **freier Bestand** (Σ verfügbare Menge freier Chargen), **Quarantäne**, **gesperrt** und **Anzahl Chargen**. Daraus: `frei` (frei verfügbar, Anzeige) und `gesamt` (physisch vorhanden = frei + Quarantäne + gesperrt).
3. **Kategorie-Filter** + **Suche**. **Sortierung** Standard = Name A–Z.
4. Tabelle: **Art.-Nr. · Name · Kategorie · Chargen · Bestand (frei) · Quarantäne · Unterwegs.** In der Bestand-Zelle (Reiter „Alle"/Betriebsmittel) stehen hinter dem freien Bestand kleine Badges für **Quarantäne**/**gesperrt**, damit Ware, die nur in Quarantäne liegt, nicht als „0/leer" wirkt.
   - Klick öffnet den Artikel (Rohstoff bzw. Verpackung).
5. Button „Wareneingang".

**Unterwegs (bestellt, noch nicht da):** Spalte **„Unterwegs"** je Artikel = Σ `bestellung_position.menge` aus Bestellungen mit `status='bestellt'` und **ohne** `angekommen_am` (bestellt, noch nicht eingegangen; geliefert/storniert zählen nicht). Als Info-Badge „… unterwegs". So sieht man im Lager, wieviel noch kommt (z. B. große Bulk-Bestellungen, siehe Bulk-Reservierung am Auftrag). Nicht bei Betriebsmitteln.

**Nullbestände ausblenden (Standard):** Ausgeblendet wird nur, was **physisch nichts** mehr hat **und nichts unterwegs** ist (`gesamt <= 0` UND `unterwegs <= 0`). Ware in **Quarantäne oder gesperrt** ist vorhanden (wartet auf Freigabe) und bleibt sichtbar, auch wenn `frei = 0`; ebenso **bestellte, noch nicht gelieferte** Ware (`unterwegs > 0`), auch wenn aktuell kein Bestand da ist. Betriebsmittel (Maschinen/Inventar) sind Anlagegüter und immer sichtbar. `?leer=1` zeigt alles. **Wichtig:** Der Lager-App-Wareneingang (`/lager/`) bucht viele Rohstoffe in **Quarantäne** ein – ohne diese Logik verschwänden sie hier.

**Bestandslogik:** `item_bestand($id)` summiert die freien Chargen. Rohstoffe u. Ä. brauchen Quarantäne (`item_braucht_quarantaene`), Verpackungen sind sofort frei.
