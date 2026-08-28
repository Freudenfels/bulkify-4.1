# angebot/liste.php – Angebots-Liste

**Zweck:** Übersicht aller Angebote. Das Angebot ist die **einzige Preisquelle** – hier setzt das Team die Verkaufspreise.

**Was passiert hier:**
1. `seed_angebot_if_empty()` – legt lokal ein Demo-Angebot mit Staffeln an.
2. Liest alle Angebote inkl. Kunde, Produktname (Joins) und Staffel-Anzahl.
3. **Suche** nach Nummer, Kunde, Produkt. **Sortierung** Standard = zuletzt geändert.
4. Tabelle: **Nummer · Kunde · Produkt · Staffeln · Status.**
   - Status-Badges: offen / gesendet / bestätigt / abgelehnt.
   - Klick öffnet das Angebot (`?p=angebot&id=...`).
5. Button „Neues Angebot" (Nummer AN-… automatisch).
