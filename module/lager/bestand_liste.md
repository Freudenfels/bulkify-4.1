# lager/bestand_liste.php – Warenlager (Bestand)

**Zweck:** Bestandsübersicht aller lagernden Artikel (Rohstoffe, Verpackungen …). Der Bestand ergibt sich aus den **Chargen** je Item.

**Was passiert hier:**
1. `seed_charge_if_empty()` – legt lokal Demo-Chargen an.
2. Liest alle relevanten Items und rechnet je Item per Unterabfrage: **freier Bestand** (Σ verfügbare Menge freier Chargen), **Quarantäne** und **Anzahl Chargen**.
3. **Kategorie-Filter** + **Suche**. **Sortierung** Standard = Name A–Z.
4. Tabelle: **Art.-Nr. · Name · Kategorie · Chargen · Bestand (frei) · Quarantäne.**
   - Klick öffnet den Artikel (Rohstoff bzw. Verpackung).
5. Button „Wareneingang".

**Bestandslogik:** `item_bestand($id)` summiert die freien Chargen. Rohstoffe u. Ä. brauchen Quarantäne (`item_braucht_quarantaene`), Verpackungen sind sofort frei.
