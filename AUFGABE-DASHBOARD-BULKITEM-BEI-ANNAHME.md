# Aufgabe (Dashboard-Chat): Bulk-Lagerartikel schon bei Rezeptur-Annahme anlegen

> Für den **Dashboard-Chat** (`core/`, `module/`, `public/`). Vom **Lager-Chat**. Klein, aber behebt eine echte Reibung.
> Hintergrund-Entscheidung: `ANTWORT-DASHBOARD-REZEPTUR-BULKITEM.md` + `STATUS-LAGER-REZEPTUR-BULKITEM.md`.

## Problem (real aufgetreten)
Nico bucht im Lager (Scanner-App, Route `we`) zugekaufte **fertige Kapseln** zu einer Rezeptur ein
(Beispiel: RZ-3941 „Schilddrüsenkomplex neu"). Es gibt die **Rezeptur** *und* eine **Kundenbestellung**,
trotzdem meldet das Lager: „Noch kein Bulk-Artikel – bitte erst im Dashboard anlegen."

Grund: Der **Bulk-Lagerartikel** (`item.rezeptur_id` + `kategorie='fertig'`) entsteht heute erst
(a) beim Bulk-Produktionsauftrag (`core/schema.php:2451`) oder (b) beim Zukauf-zum-Auftrag
(`module/lager/wareneingang.php:37`). Eine angenommene Rezeptur allein legt ihn **nicht** an. Das Lager
löst den Artikel bewusst nur **read-only** auf (`erp_rezeptur_bulkitem()`) und bricht sonst sauber ab –
die Anlage soll kanonisch im Dashboard bleiben.

## Lösung (Nicos Wunsch)
**Sobald eine Rezeptur angenommen/freigegeben wird, den Bulk-Lagerartikel automatisch anlegen** – auch
wenn der Bestand 0 ist. Dann existiert er immer, bevor Ware kommt, und das Lager bucht ohne Zwischenschritt.

`rezeptur_bulkitem(int $rezeptur_id): ?int` ist bereits **„finden-oder-anlegen" und idempotent** – es
genügt also, es an der Annahme-Stelle **einmal aufzurufen**. Keine neue Logik nötig.

### Einhak-Stelle (bestätigt)
- **`module/portal/kunde.php` ~Zeile 369**, Handler `aktion === 'rezeptur_annehmen'` (Kunde nimmt Rezeptur
  verbindlich an → wird eingefroren). Direkt nachdem die Annahme/der Status gesetzt ist:
  ```php
  if (function_exists('rezeptur_bulkitem')) rezeptur_bulkitem((int)$rezeptur_id);
  ```
- Falls es einen **internen** Freigabe-/Einfrier-Weg gibt (Team setzt `rezeptur.status='freigegeben'`/
  `eingefroren` ohne Kundenportal), bitte dort denselben Einzeiler ergänzen, damit auch Team-Rezepturen
  sofort einen Bulk-Artikel haben.

### Eigenschaften / Sicherheit
- **Idempotent**: Mehrfachaufruf schadet nicht (findet den bestehenden Artikel). Bricht bei ungültiger
  `rezeptur_id` sauber mit `null` ab.
- **Bestand 0** ist gewollt – der Artikel ist nur die „Hülle", Bestand kommt später übers Lager.
- Ändert den **Vertrag nicht** (gleiche Spalten/Einheit/Nummer wie bisher). Die bestehenden Aufrufe bei
  Produktion/Zukauf bleiben (schaden nicht, idempotent).

## Lagerseite
**Nichts zu tun.** Das Lager löst bereits read-only auf (`erp_rezeptur_bulkitem()`); sobald der Artikel
bei Annahme existiert, verschwindet die rote Meldung und das Einbuchen läuft durch.

## Verifikation
- Eine Rezeptur annehmen → prüfen, dass genau **ein** `item` mit `rezeptur_id=<id>` und `kategorie='fertig'`
  existiert (Name „… – Bulk", `naechste_nummer('BULK')`), Bestand/Charge 0.
- Im Lager (`?p=we`, Warenart „Bulk / lose") die Rezeptur wählen → **keine** rote Meldung mehr, Buchen geht.
- Zweimal annehmen/aufrufen → **kein** zweiter Artikel (Idempotenz).
