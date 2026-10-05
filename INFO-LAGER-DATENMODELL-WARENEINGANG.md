# Für den Lager-Chat: Datenmodell + Wareneingang – was buche ich wann als was?

> Referenz aus dem Dashboard-Chat. Zweck: Das Lager (`/lager/`) soll wissen, **welche Ware in welche
> Kategorie** gehört, **wohin sie beim Wareneingang gebucht** wird (auf welches `item`/welche Charge),
> was **Pflicht** ist (Charge/MHD/Quarantäne) und wie die **Paket-/Tracking-Logik** gedacht ist.
> Alle Dashboard-Zugriffe laufen über die Naht `lager/core/erp.php` – Kategorien/Spalten hier nicht umbenennen.

## 1. Die Kette (so hängt alles zusammen)
```
Rohstoffe (mg) + Form/Kapselgröße      = REZEPTUR     (Inhalt EINER Einheit, z. B. 1 Kapsel)
Rezeptur + Menge/Packung + Verpackung  = PRODUKT      (verkaufbare SKU)
Produkt × Menge × Preis                = AUFTRAG      (eine Kundenbestellung, AB-…)
Auftrag                                 → PRODUKTIONSAUFTRAG (PR-…) → CHARGE (produzierte Partie, mit MHD)
```
- **Rezeptur** = Wirkstoffe/Rohstoffe mit mg je Einheit + Darreichungsform (Kapsel/Tablette/Pulver/…) + bei Kapsel die **Kapselgröße**. Die Rezeptur legt nur die *Kapselgröße* fest – die konkrete **Leerkapsel ist ein Rohstoff** (s. u.).
- **Produkt** = Rezeptur + **Einheiten je Packung** (z. B. 120 Kapseln) + **Verpackungs-Stückliste** (Primärgebinde Dose/Glas, + Deckel, + Etikett, + Karton, + Beipackzettel) + optional Kunde (nur „exklusiv").

## 2. Lagerartikel-Kategorien (`item.kategorie`)
| Kategorie | Was | Bestand kommt aus | Charge/MHD/Quarantäne beim WE |
|---|---|---|---|
| `rohstoff` | Rohstoffe **inkl. Leerkapseln** (Form `kapselhuelle`) | Chargen | Charge + MHD **Pflicht**, geht in **Quarantäne** |
| `verpackung` | Dose/Glas/Deckel/Etikett/Karton/Beipack | Chargen | keine Charge-/MHD-Pflicht, **sofort frei** |
| `fertig` | **Fertigware/Bulk**: fertige Kapseln/Tabletten/Pulver **ohne** Endverpackung. Hängt an einer **Rezeptur** (das „… – Bulk"-Item) | Chargen | Charge + MHD **Pflicht**, **Quarantäne** |
| `verkaufsfertig` | fertig **verpacktes + etikettiertes** Endprodukt. Hängt am **Produkt** (SKU) | Chargen | Charge + MHD **Pflicht**, **Quarantäne** |
| `karton`,`verbrauch`,`inventar`,`maschine`,`sonstiges` | **Betriebsmittel** (Kartons, Verbrauchsgüter, Geräte …) | manueller `bestand_menge` (keine Chargen) | – |

Regelquelle: `erp_warenart_regeln($kategorie,$form)` und `item_braucht_quarantaene($kategorie)`
(= rohstoff/fertig/verkaufsfertig). **Merke:** echte Ware (Rohstoff/Fertigware/Verkaufsfertig) kommt in
**Quarantäne** und muss **freigegeben** werden; Verpackung/Verbrauch sind sofort frei.

## 3. Wareneingang – Entscheidungshilfe „was ist das?"
| Der Lieferant bringt … | Warenart / Kategorie | Landet auf |
|---|---|---|
| Rohstoff-Pulver/-Extrakt (Wirkstoff) | **Rohstoff** (`rohstoff`) | Charge auf das Rohstoff-`item` |
| **Leerkapseln** (leere Hüllen, Größe 0/1/…) | **Rohstoff**, Form `kapselhuelle` | Charge auf das Leerkapsel-`item` |
| **Fertige Kapseln/Tabletten zugekauft** (lose, ohne Dose/Etikett) | **Fertig / Bulk** (`fertig`) | Charge auf das **Bulk-Item der Rezeptur** (`rezeptur_bulkitem()`) |
| Komplett fertiges Produkt (inkl. Dose + Etikett) | **Verkaufsfertig** (`verkaufsfertig`) | Charge auf das Verkaufsfertig-`item` des **Produkts** |
| Dosen/Gläser/Deckel/Etiketten/Kartons/Beipack | **Verpackung** (`verpackung`) | Charge auf das Verpackungs-`item` (sofort frei) |
| Kartons/Klebeband/Verbrauchsmaterial | **Betriebsmittel** (`verbrauch`/`karton`) | manueller Bestand, keine Charge |

**Faustregel fertige Kapseln:** „fertige Kapseln zukaufen" = **Warenart „Fertig"/Bulk**, NICHT Rohstoff
und NICHT „verkaufsfertig". Erst nach Verpacken+Etikettieren im Haus wird daraus `verkaufsfertig`.

## 4. Buchen – wie (über die Naht `lager/core/erp.php`)
- Normaler Eingang (eigenes Lager = **L1**): `erp_wareneingang_buchen($item_id,$menge,$charge_nr,$mhd,$lieferant_id,$notiz,$status)`
  → schreibt eine Zeile in die geteilte Tabelle **`charge`** (Status standardmäßig `frei`; für echte Ware
  i. d. R. `quarantaene` wählen). Gibt es eine **vorab aus dem CoA angelegte Charge** (gleiche Nummer, noch
  ohne Menge), wird sie aufgefüllt statt doppelt angelegt.
- **Fremdlager (L2, Fulfillment-Kunde):** `erp_wareneingang_buchen_fremd($item_id,$menge,$charge_nr,$mhd,$kunde_id,$notiz)`
  → Charge mit `fremd_kunde_id` (gehört dem Kunden, nicht unserem freien Bestand).
- Neuer, im Lager noch unbekannter Artikel: `erp_item_anlegen($name,$kategorie,$einheit)` (erlaubt
  rohstoff/verpackung/verbrauch/fertig; Details ergänzt das Team später im Dashboard).
- Pflichtfelder je Warenart aus `erp_warenart_regeln()` prüfen (Charge/MHD bei echter Ware Pflicht).

## 5. Charge-Status & Freigabe
- `frei` = verfügbar · `quarantaene` = da, aber gesperrt (wartet auf Freigabe) · `gesperrt` · `leer`.
- Echte Ware startet in **Quarantäne** → Freigabe über das Dashboard (Menü „Freigaben" bzw. an der Charge).
- Das **Dashboard-Warenlager** zählt „vorhanden" = **frei + Quarantäne + gesperrt** (blendet nur wirklich
  leere aus) – quarantäne-Ware bleibt also sichtbar.

## 6. Pakete + Tracking (Erwartete Lieferungen / Scan-Abgleich) – Lager-Teil
Der Lieferant meldet im Portal **Kartons + Tracking-Nummern** → Tabelle **`lieferung_paket`**
(`bestellung_id`, `tracking` UNIQUE, `spediteur`, `angekommen`, `angekommen_am`, `angelegt`);
Anzahl ohne Nummern steht in `bestellung.pakete_angekuendigt`.
**Das Lager baut dazu** (Lesen/Setzen über `lager/core/erp.php`):
- `erp_lieferung_pakete($bestellung_id)` → alle Pakete (tracking, spediteur, angekommen …).
- `erp_paket_per_tracking($tracking)` → Paket + Bestellung/Lieferant (für den Scan).
- `erp_paket_angekommen($tracking)` → setzt `angekommen=1, angekommen_am=UTC` (idempotent); Rückgabe {ok, bestellung_id, offen, gesamt}.
- „Erwartete Lieferungen" zeigt je Lieferung **angekommen/gesamt** (z. B. 24/25) + fehlende Nummern;
  beim Wareneingang Kartons scannen → Haken, Fehlende bleiben sichtbar.

## 7. Regeln (wie immer)
- Nur in `lager/` + `public/lager/` arbeiten; Dashboard-Zugriffe **ausschließlich** über `lager/core/erp.php`.
- Zeit UTC speichern, Anzeige via `fmt_zeit()`. Keine Emojis. Zu jeder `.php` die co-located `.md` pflegen.
- **Dashboard-Schema hat kein `jetzt_utc()`** – dort `gmdate('Y-m-d H:i:s')`. (In `lager/` existiert `jetzt_utc()`.)
