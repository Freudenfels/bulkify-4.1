# core/bestellung_chargen.php – Chargen & Dokumente an der Bestellposition

**Zweck (08.11.2026, Soll-Handbuch 4.4):** Ein Platz für die Unterlagen zur Lieferung – Charge, MHD, CoA, Spezifikation – je Bestellposition. Ein Baustein für zwei Oberflächen: Bestellseite im Dashboard (`?p=bestellung`, Team) und Bestellung im Lieferantenportal (`?p=lieferant_bestellung`, Lieferant). Beide sehen und pflegen dieselben Daten. Gerade bei „Direkt bestellt" lädt niemand im Portal hoch; dann legt das Team die per Mail erhaltenen Unterlagen hier ab.

**Daten:**
- `bestellung_charge` (neu): Charge des Lieferanten, MHD, Menge, `von` (team/lieferant) je Position – mehrere Zeilen je Position (Teillieferung, zwei Chargen). Vergleichsbasis für den späteren blinden Chargen-Abgleich (4.5, Schritt 6); die Lager-App zeigt sie bewusst noch nicht an.
- Dokumente in der bestehenden Ablage `dokument`: `objekt_typ='bestellung'`, `objekt_id`=Bestellung, neue Spalte `bestellung_position_id`, `charge_nr` (CoA je Charge), `typ` coa/spec. `lieferant_id` ist gesetzt, damit der Lieferant sie im Portal öffnen kann (`lieferant_darf_datei`).

**Funktionen:**
- `bestellung_chargen_panel($b, $von, $sprache)` – der Block (de/en/zh). Je Position: Tabelle der Chargen (CoA-Link bzw. Upload, „Eingebucht" sobald eine Charge mit dieser Nummer gegen die Position gebucht ist), Formular „Charge eintragen" (Charge, MHD, Menge, CoA optional), CoA ohne Chargennummer, Spezifikation (liegt am Rohstoff schon eine vor, wird sie nur angezeigt) und „Weiteres Dokument".
- `bestellung_chargen_post($b, $von, $sprache)` – Aktionen `bch_add`, `bch_coa`, `bch_doc`, `bch_del`, `bch_dok_del`; nur Team: `bch_ki` (KI liest Charge/MHD aus einem CoA ohne Nummer, `spec_ki_lesen`, Ergebnis in `dokument.ki_daten`) und `bch_ki_ok` (Vorschlag bestätigen → Charge-Zeile). Der Lieferant darf nur Eigenes entfernen.
- `charge_bestell_dokumente($charge_id)` – Dokumente einer eingebuchten Charge: über `charge.bestellung_position_id` die Spezifikation(en) der Position und das CoA mit gleicher Chargennummer (tolerant, `charge_gleich`) bzw. ein CoA ohne Nummer. Nichts wird kopiert – ein später hochgeladenes CoA hängt so automatisch an der Charge.
- `bch_position_fuer_charge($charge_id, $kandidaten)` – beim Wareneingang: Position mit derselben gemeldeten Chargennummer, sonst die erste gebuchte.
- `charge_coa_fehlt($charge_id)` – für den Hinweis beim Wareneingang (blockiert nichts).

**Lager-App:** Spiegel in `lager/core/erp.php` (`erp_position_fuer_charge`, `erp_charge_bestell_dokumente`), ohne `core/schema.php`.
