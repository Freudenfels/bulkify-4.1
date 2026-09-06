# wartet.php – „Wer wartet auf mich"

## Wozu
Das Herzstück. Die Liste ist ein **Zusammenzug, kein Speicher**: Sie holt die Vorgänge aus dem Dashboard (`core/erp.php`) und mischt die eigenen Sachen dazu (Wiedervorlagen, Termine, neue Kontakte). Deshalb füllt sie sich vom ersten Tag an von selbst, auch wenn niemand etwas einträgt.

Sortiert wird nach **Wartezeit**, nicht nach Anlagedatum. Oben steht, was am längsten liegt.

## Die Automatik – der eigentliche Zweck
`wartet_automatik()` legt für **jedes gesendete Angebot** eine Wiedervorlage an, sobald das CRM es zum ersten Mal sieht. Fällig ist sie ab **Versand plus `CRM_ANGEBOT_NACHFASSEN` Tagen** (Standard 5) – nicht ab heute. Ein Angebot, das schon zwei Wochen liegt, steht damit sofort in der Liste statt erst in fünf Tagen.

Angelegt wird je Angebot **genau einmal**: geprüft wird, ob es dazu überhaupt schon eine Wiedervorlage gibt, auch eine erledigte. Wer sie abgehakt hat, bekommt sie nicht wieder. Das Dashboard merkt von alldem nichts.

## Wer vertritt wen
Ein Vorgang mit einer **offenen** Wiedervorlage taucht nicht doppelt auf – die Wiedervorlage vertritt ihn. Solange sie nicht fällig ist, ist der Vorgang aus der Liste; ab dem Fälligkeitstag steht er als Wiedervorlage wieder da. Das ist auch der Grund, warum „in 3 Tagen“ ohne einen zweiten Eintrag auskommt.

## Die zwei Knöpfe
- **in 3 Tagen** – legt eine Wiedervorlage an; die Zeile verschwindet bis dahin.
- **erledigt** – bei eigenen Sachen wirklich erledigt; bei Dashboard-Vorgängen nur ausgeblendet.

Wird eine Wiedervorlage erledigt, die an einem Dashboard-Vorgang hängt, gilt **auch der Vorgang als erledigt**. Sonst stünde das Angebot in der nächsten Sekunde wieder in der Liste.

## Warum „nur ausgeblendet"
Einen Dashboard-Vorgang zu erledigen hieße, im Dashboard etwas zu ändern – und genau das soll das CRM nicht. Stattdessen merkt sich `crm_erledigt`, dass **du** die Zeile weggeklickt hast, zusammen mit dem damaligen Stand.

Ändert sich der Vorgang später (neuer Zeitstempel), stimmt der Stand nicht mehr und **die Zeile kommt zurück**. Das ist Absicht: Wer nachfasst und eine Antwort bekommt, soll das wieder sehen.

## Farben
`ruhig` / `warm` / `heiss` sagen etwas über die **Zeit**, nicht über die Wichtigkeit. Die Grenzen stehen in `core/config.php`.
