# bestand/wareneingang.php – Vollwertiger Wareneingang (Route `we`)

Ein Bereich für alles, was reinkommt. Ablauf:
1. **Ziel**: Lager 1 (eigener Bestand) oder Lager 2 (Kundenware → Kunde). Lieferant optional.
2. **Lieferschein scannen** (optional): Webcam-Foto(s) oder Datei/PDF → `POST aktion=scan` →
   `lg_lieferschein_lesen()` ([../../core/ki.md](../../core/ki.md)) → KI liest Lieferant + Positionen.
   Positionen werden per `erp_position_zuordnen()` einem bestehenden Artikel zugeordnet (exakter
   Name = sichere Zuordnung, sonst Vorschlag + Warenart-Vermutung). Antwort als JSON, Tabelle füllt sich.
3. **Positionen prüfen**: je Zeile Artikel (bestehend/neu), Warenart, Menge, Einheit, Charge, MHD,
   **Blinker (immer Pflicht)**, Pakete. Pflichtfelder je Warenart aus `erp_warenart_regeln()`:
   Rohstoff/Fertig/Kapsel → MHD+Charge Pflicht + Quarantäne; Verpackung/Verbrauch → frei.
4. **Alle buchen** (`POST aktion=buchen`): je Position eine Charge (`erp_wareneingang_buchen` für L1,
   `erp_wareneingang_buchen_fremd` für L2), Blinker anhängen (grün), Pakete + Bewegungslog. Unbekannte
   Artikel werden via `erp_item_anlegen` angelegt. Ungültige Zeilen werden übersprungen und gemeldet
   (Teil-Buchung, kein Totalverlust). Danach Erfolgspanel mit **Sammel-Etikett** (`?p=etikett&ids=…`).

Ersetzt im Menü die alten getrennten Seiten `eingang.php` (L1) und `l2_eingang.php` (L2); deren Routen
bleiben für Altlinks (z. B. Erwartete Lieferungen → Einbuchen) bestehen.

**Kiste (optional):** Oben lässt sich eine **Kiste** (`kiste_id`) + **Fach** wählen (aus `kiste_alle()`).
Ist eine Kiste gewählt, wird jede gebuchte Charge per `kiste_charge_zuordnen()` hineingelegt und der
**Blinker je Position ist optional** (die Kiste blinkt beim Finden). Ohne Kiste bleibt der Blinker Pflicht.

**Lieferschein-Scan** erfasst zusätzlich Lieferanten-**Art.-Nr.** je Position und die **Auftragsnummer**
(beides in die Charge-Notiz), legt den **Lieferanten** an/verknüpft ihn und erfindet **keine Charge** mehr.
**Sendungs-/Paketnummer** (Tracking) wird je Charge gespeichert (`lg_tracking_set`).

**Status** wird beim Einbuchen gewählt (`status`: Freigegeben = Standard / Quarantäne / Gesperrt) und an
`erp_wareneingang_buchen(..., $status)` übergeben – Quarantäne ist nur noch der Sonderfall. Nachträglich
änderbar auf der Charge-Detailseite (`?p=charge`, Aktion `status` → `erp_charge_status_setzen()`).
