# datenpruefung.php – Datenprüfung (nur Admin)

Route `?p=datenpruefung`, Menü System → Datenprüfung. Nur Admin (`route_rollen_map()` leer, zusätzlich `has_role('admin')` in der Seite).

Die Seite führt die Prüf-Abfragen aus der Liste „Nicos manuelle Live-DB-Aufgaben" (`nico-live-aufgaben-selects.md`) direkt im Dashboard aus. Oben steht eine Übersicht mit der Trefferzahl je Prüfung. Darunter folgt je Prüfung ein Block mit Überschrift, Erklärung, Treffer-Tabelle (mit „Öffnen"-Link, wo es eine Seite gibt) und der dokumentierten Korrektur als Text.

**Die Seite liest nur.** Beim Aufruf wird nichts geändert.

## Prüfungen
| Prüfung | Beheben |
|---|---|
| S-02 exklusive Produkte im Katalog sichtbar | ja: `p.exklusiv=1, p.kunde_id=r.kunde_id` |
| S-10 Bulk-Bestellpositionen in g/ml mit €/kg-Preis | ja: Menge (und bereits gelieferte Menge) / 1000, Einheit kg bzw. l |
| S-10 Pulver-Verbrauch als „Stk" gebucht | nein (keine Korrektur dokumentiert) |
| S-10 Pulver-Fremdpreise mit Einheit „Stk" | ja: `einheit='kg'` |
| Fertigware-Altchargen ohne Auftrag | ja: `auftrag_id` aus dem Produktionsauftrag (nur Zeilen mit Vorschlag) |
| S-16 Fremdlager-Altbestand: Chargen ohne Besitzer | ja (Freigabe Nico 03.11.): `fremd_kunde_id` = Kunde des Produkts, `standort='lager2'` |
| S-16 Aufträge „ins Fremdlager" ohne Lieferschein | **nein, bewusst**: wird separat geklärt |
| Zukauf „fertig" mit alten Schritten | nein (am Auftrag Zukauf-Art neu speichern) |
| Wer hat die Rolle QM (labor)? | nein (Warnung, wenn niemand) |
| Doppelte Nummern | nein (Einzelentscheidung, freigegebene Rechnungen nie umnummerieren) |
| Kundenetiketten ohne Artikelnummer | nein |
| Protokoll der Kundenetikett-Migration | Anzeige von `app_meta migr_kundenetikett_protokoll` |

## Beheben
- Je Prüfung mit dokumentierter Korrektur gibt es einen Haken je Zeile (plus „alle") und den Knopf „Angehakte beheben" mit Bestätigung.
- Ausgeführt wird genau die angezeigte Korrektur, nur für die angehakten Zeilen. Die Bedingungen der Prüfung stehen immer mit in der WHERE, und die IDs werden vor dem Ausführen gegen die aktuelle Trefferliste geprüft. Eine veraltete Auswahl ändert also nichts Falsches.
- Alles läuft in einer Transaktion. Jede geänderte Spalte wird mit alt/neu, Person und Route `datenpruefung` ins Feld-Änderungsprotokoll (`aenderung_log`) geschrieben. Ansicht: Protokoll → Feld-Änderungen.
- Fehlt auf einer Datenbank eine Spalte, zeigt die jeweilige Prüfung den Fehler an, statt die Seite abzubrechen.
