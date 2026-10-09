# V4 Produktionsmodul – Build-Plan & Bereichsaufteilung (für parallele Chats)

**Grundlage:** `.claude/uploads/.../abd48380-V4-Produktionsmodul-Spezifikation.md` (Lastenheft, 09.10.2026).
**Zweck dieses Dokuments:** Die Umsetzung so aufteilen, dass **mehrere Chats parallel** an ihrem Bereich arbeiten können, **ohne sich zu brechen oder zu kollidieren**. Jeder Chat liest zuerst diesen Plan + das Lastenheft + die co-located `.md` seines Bereichs.

> Stand: wird über Nacht aufgebaut; morgen mit Nico durchgehen. Die per-Bereich-Tasklisten werden nach der Code-Bestandsaufnahme konkretisiert (unten „Status Bestandsaufnahme").

---

## 0. Eiserne Regeln (für ALLE Chats)
1. **Nur additiv.** Neue Tabellen (`CREATE TABLE IF NOT EXISTS`), neue Spalten (`ensure_column`), neue Routen/Dateien. **Nichts Bestehendes brechen** – jeder Push geht per Auto-Deploy LIVE (app + beta).
2. **Nie pauschale DELETEs/Mass-Updates in der DB.** Schema-DDL in try/catch (best-effort), sonst 500.
3. **`php -l`** auf jede geänderte Datei + kurzer Render-/curl-Check, bevor committet wird.
4. **Rebase-Ampel:** vor jedem Push `git pull --rebase`, dann `git push`. Niemals `--force`.
5. **Co-located `.md`** zu jeder `.php` mitpflegen (einfaches Deutsch).
6. **Keine Logins mit Nico-Credentials**, keine Secrets committen (`data/`, `secrets.php` gitignored).
7. **Commit-Attribution:** `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
8. **Keine Emojis in der UI**, Labels nicht fett, großzügige Abstände, Zeit UTC speichern + `fmt_zeit()` anzeigen, `LIKE … ESCAPE '='`.

## 1. Konflikt-Naht: `core/schema.php`
- **`core/schema.php` (Dashboard) besitzt EIN Owner** (die Orchestrierungs-Session). Andere Chats fassen sie **nicht** an – sie bekommen die fertigen Tabellen/Helfer-Namen genannt und nutzen sie.
- **Sub-Apps haben eigene Schemata** und kollidieren daher NICHT:
  - `produktion/core/schema.php` (+ `produktion/core/erp.php` als EINZIGE Naht zu Dashboard-Tabellen)
  - `lager/core/schema.php` (+ `lager/core/erp.php`)
  - `crm/core/*`, `buchhaltung/core/*`
- **Geteilte Dashboard-Tabellen** (charge, maschine, prod_charge …) liegen in `core/schema.php`; Sub-Apps greifen nur über ihr eigenes `erp.php` darauf zu (eine Datei, dort Spaltennamen prüfen).

## 2. Bereichsaufteilung (ein Chat je Paket)
| Paket | Bereich/Ordner | Konflikt-Risiko | Abhängig von |
|---|---|---|---|
| **A – Schema-Fundament** | `core/schema.php` (Owner) | – (Single-Owner) | – (zuerst) |
| **B – Produktions-App (Herzstück)** | `produktion/` Sub-App | niedrig (eigene Schema+erp) | A |
| **C – Chargen-Menü + CH/CHE** | Dashboard `module/charge/` (neu) + core | teilt core/schema (A liefert) | A |
| **D – Maschinenfuhrpark** | Dashboard `module/system/` (Einstellungen-Reiter) | einstellungen.php (ein Owner) | A |
| **E – Rezeptur-Zuordnung beim Anlegen** | `module/rezeptur/` + anfrage | niedrig | A (rezeptur.kunde_id/exklusiv) |
| **F – Lager: Wareneingang-Scan + QR je Gebinde + Blinker** | `lager/` Sub-App | niedrig (eigene Schema+erp) | A |
| **G – Kundentransparenz: Live-Mengenfortschritt** | `module/portal/` + produktion-Daten | niedrig | B |

**Reihenfolge:** A zuerst (Fundament). Danach B/C/D/E/F/G parallel. G nach B (braucht Mengen-Events).

## 3. Scope-Realität (ehrlich)
Das Lastenheft ist **mehrere Wochen** Arbeit. Über Nacht entsteht ein **tragfähiges Fundament + erste vollständige Bausteine**, nicht das ganze Modul. Reihenfolge nach Nutzen/Risiko:
1. Schema-Fundament (A) – entsperrt alles.
2. Rezeptur-Zuordnung beim Anlegen (E) – klein, hoher Nutzen, Spec 3.4.
3. Maschinenfuhrpark (D) – Einstellungen, Spec 9.3 (gut abgegrenzt).
4. Chargen-Menü + CH/CHE-Entität (C) – Spec 7.5/16 (Fundament für Rückverfolgung).
5. Produktions-App: Startbildschirm je Auftrag + Step-Gerüst + Scan-Kontrollen (B) – erste vertikale Scheibe, Spec 7.
6. Lager QR je Gebinde (F) – Spec 5.6.
7. Live-Mengenfortschritt (G) – Spec 15.3.
🔵 bewusst SPÄTER (nicht verbauen): Sichtbarkeitssteuerung intern/extern (15.4), Kunden-Live-Mengen, Auto-Sensoren (10).

## 4. Offene Punkte für Nico (morgen klären)
1. **Automatischer Laborversand** der 2 Proben direkt aus der Produktion (Spec 14.3 / 19.1): existiert der Automatik-Fluss oder neu? (zu prüfen)
2. **Chargennummer-Format** CH/CHE: Stellen/Jahresanteil? Vorschlag: `CH` + JJ + laufende Nr, Unterchargen `-A/-B` (analog bestehender Charge-Logik). (Spec 19.2)
3. **Verhältnis CH/CHE-Produktionscharge ↔ bestehende charge-Tabelle** (Fertigprodukt-Chargennummer bleibt separat, Spec 7.5): bestätigen, dass CH/CHE eine ZUSÄTZLICHE Entität ist.
4. Maschinentypen-Startliste (Spec 9.3) – Vorschlag übernehmen, Nico ergänzt.

---

## Status Bestandsaufnahme (code-geankert)
| Spec | Thema | Stand im Code |
|---|---|---|
| 3.4 | Rezeptur Kunde/Katalog beim Anlegen | 🟢 **fertig** (`module/rezeptur/detail.php`, `rezeptur.exklusiv`/`kunde_id`) – kein Baubedarf |
| 11 | Energetisierung | 🟢 **fertig** (kundengebunden, `charge.energetisiert_*`) |
| 7 | Step-by-Step | 🟡 Basis da (`produktion/.../run.php`, `produktion/core/erp.php`), aber ohne echte Scans |
| 7.1 | Produktionswege/Formgebung | 🟡 `produktionsschritte_fuer`/`produktion_wege_aufloesen` + `weg_*`-Flags; **Bulk-Weiterverkauf-Abfang fehlt** |
| 7.5/16 | CH/CHE-Chargen + Unterchargen + Rohstoff-Batch | 🟢 **Fundament gebaut** (Paket A, s. u.) |
| 8 | Proben 3-stufig + Rückstellmuster-Regel | 🟢 **fertig** – Datenmodell (`prod_probe`) + 3-stufige QS-UI (Rohstoff/Gebinde/Endprodukt) in `produktion/.../qs.php` über die Naht (`erp_proben_fuer_pa`/`_probe_anlegen`/`_probe_loeschen`/`_rueckstell_soll`), Soll max(5, Gebinde). Labor-Ebene: bestehender Laborproben-Flow bleibt (pr_daten). |
| 9 | Maschinen (QR/Scan/Reinigung-Sperre/Typen) | 🟡 `pr_raum`/`pr_maschine`+Reinigung (intervall) da; QR/Scan/Typen/harte Sperre NEU → **Agent Produktion** |
| 7.13/10/12.2 | Gewicht/Schwund, Umgebung, Zeit | 🟡 Zeitmessung da (`erp_produktionszeit_schnitt`); Schwund+Umgebung NEU → **Agent Produktion** |
| 5/6.2 | Lager WE+Blinker+QR/Gebinde+Standort | 🟡 Blinker+WE-Scan+QR-Encoder da; `charge.standort` gebaut (A); Gebinde-QR (5.6)/Standort-Flow NEU → **Agent Lager** |
| 15.3 | Live-Mengenfortschritt | 🟢 **fertig** – `auftrag_mengenfortschritt()` (core/schema.php) + Balken „Produziert X von Y Packungen" im Dashboard-Auftrag (immer) und im Kundenportal (ab Produktionsstart). 15.4 (Sichtbarkeit intern/extern) weiter offen. |
| 13.1 | Abschlussfotos | 🔴 fehlt → Agent Produktion/Paket E |

## Fortschritt (Nacht 09.→10.10.2026)
**Gebaut & gepusht (main):**
- **Paket A – Chargen-Fundament** (`core/schema.php`): `prod_charge` (CH/CHE + Unterchargen parent_id/sub_kennung), `prod_charge_rohstoff` (Batch-Verknüpfung, beide Richtungen), `charge.standort`, Helfer `prod_charge_anlegen/_sub_anlegen/_rohstoff_verknuepfen/_rohstoffe/_vorwaerts/_voll`. Chargen-Menü `module/charge/` (durchsuchbar). Helfer end-to-end getestet.
- **Proben-Datenmodell** (`core/schema.php`): `prod_probe` (rohstoff|gebinde|endprodukt|labor) + `rueckstellmuster_sollzahl()` (max(5, Gebinde)).

**Agent Lager (Paket D): ✅ GEMERGT & LIVE auf beta/main** — `charge.standort`-Flow (an Produktion übergeben / wieder einlagern + Ist-Gewicht, „in Produktion"-Badge in Bestand+Versand), eigener **Gebinde-QR** pro Karton (`lg_gebinde`, GB-Nummernkreis, PDF, Scan→Charge/Wareneingang/Lieferant für Regress), **Rezepturnummer-Aufkleber-Scan** im Wareneingang. Geprüft: nur `lager/`, additiv, php -l sauber.

**Agent Produktion (Paket B/C): ✅ GEMERGT & LIVE auf beta/main** — Maschinenfuhrpark (`pr_maschine`+typ/qr, 10 Typen, Typ↔Step-Kopplung, QR auto `MA-<id>`), Maschinen-Scan je Step in `run.php` (maschine_id an prod_charge), **Produktionscharge CH/CHE** beim Mischen/Bereitstellen über `produktion/core/erp.php` (Raw-SQL, Rohstoff-Batches aus `produktion_verbrauch`), **Mischer-Kapazität/Umrechnung** je Gebinde + Unterchargen, **Umgebungsdaten** (Temp/Feuchte) je Step, **Reinigung ereignisgesteuert + harte Sperre** bei „nicht sauber" (end-to-end getestet). Geprüft: nur `produktion/`, additiv, php -l sauber.

## Noch offen (Paket E + Rest – für die nächsten Etappen)
- ✅ **Live-Mengenfortschritt (15.3)** in Auftrag/Portal – ERLEDIGT (10.10.2026, `auftrag_mengenfortschritt()`).
- ✅ **Proben-UI 3-stufig** – ERLEDIGT (10.10.2026): Rohstoff-/Gebinde-/Endprodukt-Proben in `produktion/.../qs.php` über `prod_probe`, Soll max(5, Gebinde).
- **FIFO/Gebinde-Durchziehen beim Abfüllen (7.8)** + Unterchargen beim Griff zum nächsten Gebinde (bisher nur beim Mischen).
- **Produktionsbericht (17)** gekürzt/ausführlich aus den neuen Daten (Maschine/Klima/Reinigung/prod_charge/Proben).
- **Abschlussfotos (13.1)**, **Pausen nur an cleanen Punkten/Schichtwechsel (7.11/7.12)**.
- **Bulk-Weiterverkauf-Abfang (7.1)** + CH/CHE-Nummernformat → mit Nico klären.

**Wichtig (Architektur):** Sub-Apps requiren NICHT `core/schema.php` (db()-Kollision) → Zugriff auf `prod_charge`/`charge.standort` nur per Raw-SQL in der jeweiligen `*/core/erp.php`.

## Für Nico morgen (Entscheidungen)
- Chargennummer-Format CH/CHE (aktuell `CH-2690`-Stil, 4 Stellen; Jahresanteil? → leicht anpassbar).
- Bulk-Weiterverkauf-Abfang (7.1): die PA-Erstellung sitzt an ~7 Stellen in `core/schema.php` – sauber zentralisieren vs. Flag am Produkt; **bewusst nicht blind über Nacht geändert**.
- Maschinen: als Dashboard-Tabelle (alle) ODER in der Produktions-Sub-App (`pr_maschine`) – aktuell baut der Agent auf `pr_maschine` auf.
- Auto-Laborversand der 2 Proben aus der Produktion (14.3) – Status bestätigen.
