# intern/portal_anfragen.php – Portal-Anfragen (intern)

**Zweck:** Eingangsliste aller Kundenanfragen aus dem Portal – **Produkt / Rohstoff / Dienstleistung** (die Rezepturanfrage läuft separat über `anfrage`). Rollen sales/production/einkauf.

**Anzeige:** Filter nach Typ; Ansicht-Tabs Offen/Beantwortet/Abgelehnt/Alle; Tabelle Nr. · Kunde · Typ · Anfrage · Status. Bei Produktanfragen wird der Text aus Produkt + Stück/Packung + Verpackung + Menge gebaut, sonst aus Betreff/Notiz. Neue Anfragen stehen oben.

## Offen/Beantwortet aus dem ECHTEN Zustand (nicht nur `pa.status`)
Die Ansicht-Buckets richten sich danach, ob der Kunde wirklich ein Angebot sieht – nicht nur nach `portal_anfrage.status`. Das SQL-Prädikat `$ANG` prüft, ob ein **sichtbares, preislich freigegebenes** Angebot existiert (`angebot` mit `anfrage_id`+`kunde_id`, `kunde_ausgeblendet=0`, `status<>'offen'`, `preise_kunde=1`) – genau die Bedingung, unter der das Kundenportal ein Angebot zeigt.
- **Offen** = `status<>'abgelehnt' AND NOT $ANG` → wir müssen ran (deckt sich mit Kundensicht „in Prüfung"). Enthält auch `status='beantwortet'`, dessen Angebot zurückgezogen/preisgesperrt wurde.
- **Beantwortet** = `status<>'abgelehnt' AND $ANG` → Angebot liegt beim Kunden.
- **Abgelehnt** = `status='abgelehnt'`.
Zähler (Tabs) und Liste nutzen dieselben Bedingungen. Der **Status-Badge** (`$stBadge($r)`) kommt ebenfalls aus Status + `hat_angebot`: „Angebot abgegeben", „Angebot zurückgezogen – offen", „in Bearbeitung", „neu", „abgelehnt". (Analog zur Rezeptur-Anfragenliste `anfrage/liste.php`.)

**Aktion:** Die ganze Zeile ist anklickbar (auch die Nr. und der „öffnen"-Button) und führt zur Detailseite `?p=portal_anfrage&id=…` (`portal_anfrage_detail.php`). Dort sieht man den genauen Wunsch und kann **Preise zurücksenden** (Angebot abgeben). Der Status-Badge zeigt „Angebot abgegeben", sobald ein Angebot draußen ist.

Hinweis: Der frühere Inline-Status-Dropdown in der Liste ist entfallen – Status wird jetzt auf der Detailseite gesetzt (der POST-Handler `aktion=status` bleibt zur Sicherheit erhalten).

**Quelle:** Tabelle `portal_anfrage` (befüllt vom Kundenportal). Nummernkreis PAF-.

## Notiz als Popup (Stand 2026-10-08)
Die Kunden-Notiz steht nicht mehr als Volltext in der Spalte „Anfrage" (Tabelle wurde zu breit), sondern als klickbarer Link „Notiz ansehen" → `<dialog>` mit der vollen Notiz (stopPropagation, damit die Zeilen-Navigation nicht ausgelöst wird).

## Produktname bei Dienstleistungs-Anfragen (Laboranalyse)
Bei einer Dienstleistungs-Anfrage (z. B. Laboranalyse, vom Kunden bei der Bestellung dazugebucht) steht in der
Spalte „Anfrage" jetzt der **Produktname** dahinter, zu dem die Dienstleistung gehört (z. B. „Labortest / Analyse ·
Magnesium Komplex 5-Fach"). Das Produkt wird aufgelöst aus `pa.produkt_id` **oder** – falls dort nicht gesetzt
(ältere Labor-Upsell-Anfragen, die das Produkt nur in der Positionszeile tragen) – aus der ersten
`portal_anfrage_pos.produkt_id`. So sieht man ohne Öffnen, zu welchem Produkt die Laboranalyse gehört.
