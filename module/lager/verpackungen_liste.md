# lager/verpackungen_liste.php – Verpackungen (Liste)

**Zweck:** Übersicht aller Verpackungen (Dosen, Flaschen, Blister, Beutel …). Verpackungen sind **Warenlager-Items** mit `kategorie = verpackung` – dieselbe `item`-Tabelle wie die Rohstoffe, nur eine andere Kategorie.

**Was passiert hier:**
1. `seed_verpackung_if_empty()` – legt lokal Demo-Verpackungen an, falls leer.
2. Liest alle Items mit `kategorie='verpackung'`.
3. **Suche** nach Name, VP-Nummer, Material, Farbe. **Sortierung** Standard = Name A–Z.
4. Tabelle: **VP-Nr. · Name · Art · Material · Volumen · EK-Preis · Status.**
   - Klick öffnet die Verpackung (`?p=verpackung&id=...`).
5. Button „Neue Verpackung" (Nummer VP-… automatisch).

**Zusammenhang:** Ein **Produkt** = Rezeptur + **Verpackung** + Kunde. Diese Liste liefert die Verpackungen dafür.
