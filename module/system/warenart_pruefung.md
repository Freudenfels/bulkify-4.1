# system/warenart_pruefung.php – Warenarten prüfen (Bulkware vs. Fertigprodukt)

Route `?p=warenart_pruefung` (nur **Admin**; Stammdaten-Pflege). Gehört zu **Teil 3 Block K**: den früher mehrdeutigen „Fertigware"-Topf sauber in **Bulkware** und **Fertigprodukte** trennen.

## Warum keine neuen DB-Werte
Das Datenmodell trennt die beiden Töpfe längst über `item.kategorie`:
- `fertig` = **Bulkware** (lose Kapseln/Pulver/Tabletten, mit `item.rezeptur_id`),
- `verkaufsfertig` = **Fertigprodukt** (abgefüllt + etikettiert, mit `item.produkt_id`).

Der Bruch war nur die **uneinheitliche Beschriftung** quer durch die UI. Deshalb wurden die Werte **nicht** umbenannt (das hieße ~50 Query-Stellen inkl. aller `*/core/erp.php`-Nähte gleichzeitig umbauen – hohes Konfliktrisiko, kein Gewinn). Stattdessen gibt es jetzt **eine** Label-Quelle `kategorie_labels()`/`kategorie_label()` in `core/schema.php` (`fertig`→„Bulkware", `verkaufsfertig`→„Fertigprodukt"), die die Dashboard-Seiten (`suche`, `lager/bestand_liste`, `lager/chargen`, `intern/werk_cockpit`, `produktion/_bericht_inhalt`) nutzen. Die Bestandsliste hat zusätzlich getrennte Reiter **Bulkware** und **Fertigprodukte**.

## Auto-Zuordnung (diese Seite)
Klassifiziert alle Artikel aus `fertig`/`verkaufsfertig` anhand **echter Signale**:
- genau `produkt_id` gesetzt (keine Rezeptur) → **Fertigprodukt** (`verkaufsfertig`),
- genau `rezeptur_id` gesetzt (kein Produkt) → **Bulkware** (`fertig`),
- beides oder nichts verknüpft → **unklar** (keine Automatik).

Anzeige: Kennzahlen (ok Bulkware / ok Fertigprodukte / Korrekturvorschläge / unklar), eine Tabelle **Korrekturvorschläge** (eindeutige Fälle, deren Signal der aktuellen Warenart widerspricht) und eine Tabelle **Unklar** (manuelle Entscheidung mit zwei Buttons).

## Buchungslogik / Sicherheit
- Angewendet wird **nur auf Klick**, je Artikel ein **gezieltes** `UPDATE item SET kategorie=? WHERE id=? AND kategorie IN ('fertig','verkaufsfertig')` – nie ein pauschales Update, keine andere Kategorie betroffen.
- „Alle Vorschläge übernehmen" rechnet die Vorschläge **serverseitig neu** (die POST-Liste wird nicht vertraut) und wendet nur die eindeutigen Korrekturen an; unklare Fälle bleiben unangetastet.
- Erreichbar über einen dezenten Admin-Link in der Bestandsliste („Warenarten prüfen") – bewusst kein prominenter Dauer-Button.
