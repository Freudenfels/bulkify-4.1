# tools/produktionsauftraege_aus_auftraegen.php

Legt aus den (aus v3 importierten) **offenen Kundenaufträgen** je einen
**Produktionsauftrag (PR)** mit Stationen an, damit die Produktions-Seite gefüllt ist.

## Warum
In v3 war EIN Auftrag zugleich Kundenauftrag und Produktionsboard. In v4 ist das
getrennt: `auftrag` (Kundenbestellung, schon importiert) und `produktionsauftrag`
(was `?p=produktion` mit Stationen/Schritten zeigt). Ohne diesen Schritt bleibt die
Produktion leer, obwohl die Aufträge da sind.

## Was es tut
- Kandidaten: `auftrag` mit `v3_id` und Status `offen`, `in_produktion` oder `erledigt`
  (= noch nicht versendet, „offen + kürzlich fertig").
- Je Auftrag ohne bestehenden PR: Produktionsauftrag (`naechste_nummer('PR')`,
  `produktionsart='fremd'` wie die Auto-Kette) + Stationen (`produktionsschritte_fuer`).
- Status/Fortschritt aus dem Auftragsstatus:
  - `offen` → PR `offen`, kein Schritt erledigt
  - `in_produktion` → PR `laufend`, erster Schritt erledigt
  - `erledigt` (v3 verpackt) → PR `laufend`, alle Schritte außer Versand-Freigabe erledigt

## Idempotent / rücksetzbar
- Legt nie zwei PR zum selben Auftrag an (Prüfung über `auftrag_id`).
- Selbst erzeugte PR sind über `produktionsauftrag.v3_id` (= Auftrags-v3_id) erkennbar.
- `--reset` löscht nur diese selbst erzeugten PR (+ deren Schritte) für einen sauberen
  Neulauf; manuell/auto erzeugte PR bleiben unberührt.

## Aufruf
```
php tools/produktionsauftraege_aus_auftraegen.php            # Trockenlauf
php tools/produktionsauftraege_aus_auftraegen.php --write     # anlegen
php tools/produktionsauftraege_aus_auftraegen.php --reset --write
```

Arbeitet rein auf v4-Daten (kein v3-Zugang nötig) – läuft daher auch direkt auf beta.
Lokal verifiziert: 35 PR aus 35 offenen Aufträgen; 2. Lauf legt 0 an (idempotent).
