# lager/bestand_liste.php – Warenlager (Bestand)

**Zweck:** Bestandsübersicht aller lagernden Artikel (Rohstoffe, Verpackungen …). Der Bestand ergibt sich aus den **Chargen** je Item.

**Was passiert hier:**
1. `seed_charge_if_empty()` – legt lokal Demo-Chargen an.
2. Liest alle relevanten Items und rechnet je Item per Unterabfrage: **freier Bestand** (Σ verfügbare Menge freier Chargen), **Quarantäne**, **gesperrt** und **Anzahl Chargen**. Daraus: `frei` (frei verfügbar, Anzeige) und `gesamt` (physisch vorhanden = frei + Quarantäne + gesperrt).
3. **Kategorie-Filter** + **Suche**. **Sortierung** Standard = Name A–Z.
4. Tabelle: **Art.-Nr. · Name · Kategorie · Chargen · Bestand (frei) · Quarantäne.** In der Bestand-Zelle (Reiter „Alle"/Betriebsmittel) stehen hinter dem freien Bestand kleine Badges für **Quarantäne**/**gesperrt**, damit Ware, die nur in Quarantäne liegt, nicht als „0/leer" wirkt.
   - Klick öffnet den Artikel (Rohstoff bzw. Verpackung).
5. Button „Wareneingang".

**Nullbestände ausblenden (Standard):** Ausgeblendet wird nur, was **physisch nichts** mehr hat (`gesamt <= 0`, also auch keine Quarantäne/gesperrt). Ware in **Quarantäne oder gesperrt** ist vorhanden (wartet auf Freigabe) und bleibt sichtbar, auch wenn `frei = 0`. Betriebsmittel (Maschinen/Inventar) sind Anlagegüter und immer sichtbar. `?leer=1` zeigt alles. **Wichtig:** Der Lager-App-Wareneingang (`/lager/`) bucht viele Rohstoffe in **Quarantäne** ein – ohne diese Logik verschwänden sie hier.

**Bestandslogik:** `item_bestand($id)` summiert die freien Chargen. Rohstoffe u. Ä. brauchen Quarantäne (`item_braucht_quarantaene`), Verpackungen sind sofort frei.
