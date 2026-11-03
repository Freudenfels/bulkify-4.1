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
| S-16 Aufträge „ins Fremdlager" ohne Lieferschein | ja (Freigabe Nico 03.11.): die **eine** Fertigware-Charge am Auftrag (direkt oder über dessen PA, gleiches Produkt) bekommt `fremd_kunde_id` = Kunde des Auftrags und `standort='lager2'`. Keine Buchung, kein Lieferschein. Keine oder mehrere Chargen bzw. Charge eines anderen Kunden = „nicht automatisch behebbar" (kein Haken). Aufträge, deren Chargen schon dem Kunden gehören, fallen aus der Liste (idempotent). |
| Zukauf „fertig" mit alten Schritten | nein (am Auftrag Zukauf-Art neu speichern) |
| Wer hat die Rolle QM (labor)? | nein (Warnung, wenn niemand) |
| Doppelte Nummern | nein (Einzelentscheidung, freigegebene Rechnungen nie umnummerieren) |
| Kundenetiketten ohne Artikelnummer | ja (Freigabe Nico 03.11.): Artikelnummer wie bei neuen Kundenetiketten, `naechste_nummer(item_prefix('verpackung'))` = VP-… unter der Nummern-Sperre, in ID-Reihenfolge; schon in `item` vergebene Nummern werden übersprungen (`dp_neue_vp_nummer()`); nummerierte Etiketten fallen aus der Liste |
| Protokoll der Kundenetikett-Migration | Anzeige von `app_meta migr_kundenetikett_protokoll` |

## Links
„Öffnen" zeigt immer auf das Objekt der Zeile: Charge → `?p=chargen&id=` (Chargen-Detail), Produkt → Produkt, Bestellposition → Bestellung, Lieferanten-Preis → Rezeptur, Produktionsauftrag → PA, Benutzer → Benutzer, Kundenetikett → Verpackung. Zusätzlich sind verknüpfte Werte in der Tabelle anklickbar (Auftrags-Nr., PA, Charge-Nr.).

## Beheben
- Je Prüfung mit dokumentierter Korrektur gibt es einen Haken je Zeile (plus „alle") und den Knopf „Angehakte beheben" mit Bestätigung.
- Ausgeführt wird genau die angezeigte Korrektur, nur für die angehakten Zeilen. Die Bedingungen der Prüfung stehen immer mit in der WHERE, und die IDs werden vor dem Ausführen gegen die aktuelle Trefferliste geprüft. Eine veraltete Auswahl ändert also nichts Falsches.
- Technisch: `fix.ziel` = Spalte mit der ID des geänderten Datensatzes (Standard: die Zeile selbst), `fix.params` = Werte je Zeile (z. B. Kunde des Auftrags).
- Alles läuft in einer Transaktion. Jede geänderte Spalte wird mit alt/neu, Person und Route `datenpruefung` ins Feld-Änderungsprotokoll (`aenderung_log`) geschrieben. Ansicht: Protokoll → Feld-Änderungen.
- Fehlt auf einer Datenbank eine Spalte, zeigt die jeweilige Prüfung den Fehler an, statt die Seite abzubrechen.
