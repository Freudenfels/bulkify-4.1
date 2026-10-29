# lager/core/erp.php – die Naht zum Dashboard

Die **einzige** Datei im Lager, die Tabellen des Dashboards kennt. Benennt jemand im Dashboard eine Spalte um, muss er nur diese Datei prüfen. Das gilt auch beim Umstieg auf v5: Dann wird nur diese Datei angepasst.

Stand heute wird hier nur gelesen, und zwar die Logins aus der Tabelle `benutzer`. Wenn später Buchungen dazukommen (zum Beispiel eine Entnahme für einen Produktionsauftrag), stehen sie hier als eigene, klar benannte Funktion.

**Einlagern (Produktion → Lager):** `erp_einlager_aufgaben()` liest die offenen Übergaben
(`aufgabe.ref_typ='einlagern'`). `erp_einlager_buchen($pa_id)` ruft die **kanonische** Dashboard-Funktion
`einlager_buchen()` per **Loopback** auf (`?p=api_einlager`, Token in `app_meta['einlager_api_token']`,
`erp_einlager_token()`) – die komplexe Fertigwaren-Buchung (BSKU/Lager 2) wird NICHT im Lager nachgebaut.

**Erwartete Lieferungen (read-only):** `erp_erwartete_lieferungen()` liest offene Bestellungen (`bestellung.status='bestellt'`, `angekommen_am IS NULL`) inkl. Positionen. Jede Position erhält eine **`warenart`** für die Kategorie-Reiter der Tabelle (`?p=erwartet`): `item.kategorie` → sonst `fertig` bei auftragsgebundenem Zukauf → sonst `erp_warenart_raten($name)` (grobe Heuristik aus dem Freitext-Namen: Verpackungs-Begriffe → `verpackung`, Darreichungsformen/Dosierungen → `fertig`, sonst '' = Sonstiges). Die echte Warenart wird erst beim Einbuchen festgelegt.

**Lager-2-Katalog (read-only):** `erp_kunde_verkaufsfertig($kunde_id)` – Verkaufsfertig-Items (Bestand) eines Kunden für die optionale Bestand-Verknüpfung im Artikelkatalog (`?p=l2_artikel_edit`).

**Lager-2-Einbuchen:** `erp_l2_typ_defs()` (Typ → item.kategorie + Verpackungs-Rolle + **Verpackungsart** + ob „neu" erlaubt) und `erp_items_l2()` (buchbare Artikel aller Kategorien inkl. Karton/Sonstiges + `rolle` + `art`, fürs Filtern je Typ). Typen: Verkaufsprodukt, Rohstoff, Etikett, Beipackzettel, **Pouchbag**, **Rollenware (Stick)**, Karton, Sonstiges. Pouchbag/Rollenware sind beide `verpackung`/`primaer` und werden über die Verpackungsart (`beutel`/`stick`) getrennt. `erp_item_anlegen($name,$kat,$einheit,$rolle='',$art='')` kann `karton`/`sonstiges`, die Verpackungs-Rolle und die Verpackungsart setzen.

**Kunden & Adressen (Versand, read-only):** `erp_kunden_liste()` (alle Kunden für die Auswahl) und `erp_kunde_adressen($kunde_id)` – baut die Adress-Auswahl aus den `kunden`-Spalten: **Lieferadresse (bevorzugt)**, Hauptadresse, Rechnungsadresse; jede mit `land` (ISO, weltweit). Genutzt beim Warenausgang (`?p=versand_detail`).

**Rezeptur ↔ Bulk-Item (read-only):** `erp_rezeptur_liste()` liefert die Rezepturen (≠ Entwurf) inkl. ihres kanonischen Bulk-Items für den Picker beim Einbuchen fertiger Kapseln; `erp_rezeptur_bulkitem($rezeptur_id)` löst das Bulk-Item auf (`item.rezeptur_id` + `kategorie='fertig'`). Die **Anlage** des Bulk-Items bleibt bewusst im Dashboard (`rezeptur_bulkitem()`), weil ein `require` der Dashboard-`core/schema.php` an doppelten `db()`-Definitionen scheitern würde. Details/Begründung: `ANTWORT-DASHBOARD-REZEPTUR-BULKITEM.md`.

**Rezepturnummer-Aufkleber scannen (Spec 5.2, read-only):** `erp_rezeptur_per_nummer($scan)` – bestimmt aus einer gescannten Rezepturnummer (tolerant: `R12345`, `RZ-12345`, nackte Ziffern) die Rezeptur und gibt eine fertige **Fertigware/Bulk-Position** zurück (warenart `fertig`, rezeptur_id/-name, koppelbares Bulk-Item). Genutzt vom Wareneingang (`?p=we`, AJAX `aktion=rsticker`) → kein Tippen, keine Fehlzuordnung.

**Wareneingang-Einheit = Artikel-Basiseinheit (Gewicht/Volumen):** `erp_wareneingang_buchen()` / `erp_wareneingang_buchen_fremd()` rechnen die eingegebene Menge über `erp_menge_in_item_einheit($menge,$von,$item_einheit)` in die **Basiseinheit des Artikels** um, wenn Eingabe- und Artikel-Einheit gewichts- (mg↔g↔kg) bzw. volumen-verwandt (ml↔L) sind: „25.000 g" bei einem kg-Artikel wird als **25 kg** gebucht. Grund: `item_bestand()` (Dashboard) summiert `charge.menge_verfuegbar` **ohne** Umrechnung – eine g-Charge auf einem kg-Artikel zählte sonst 1000× zu hoch (→ falsch „kein Bedarf"). Kategoriale Einheiten (Stk/Karton/Beutel/…) bleiben wie eingegeben (z. B. Kapseln = Stk). Konsequenz für Pulver: effektiv immer in kg.

**Material-Standort (Spec 6.2):** Die Spalte `charge.standort` (`lager1`|`produktion`|`lager2`) gehört dem **Dashboard** (`core/schema.php`) – wird hier NIE angelegt. `erp_charge_standort_spalte()` prüft (gecacht), ob die Spalte existiert; fehlt sie, verhält sich alles wie `lager1` und nichts bricht. `erp_charge_standort($id)` liest den Standort, `erp_charge_standort_setzen($id,$ziel)` setzt ihn (Entnahme in die Produktion / Rückgabe ins Lager 1 – **der Blinker bleibt dran**, nur der Standort wandert). `erp_standort_sel()`/`erp_standort_label()` als Helfer; der Standort ist in `erp_charge_select`/`erp_bestand` mit ausgewählt (guarded) → Bestandsliste und Versand-Picker zeigen „in Produktion" (nicht doppelt verplanen).

## erp_versandkatalog(kunde_id, q)
Produkt-Katalog eines Kunden für den Lieferschein-Generator: je Produkt `produkt_id`, verknüpftes
verkaufsfertiges `item_id`, Name, `ek_cent`, `vk_cent` (aus `produkt_preis`), `gewicht_g`. Ohne Kunde: Suche über
alle Produkte. Grundlage für Versicherungswert (EK, sonst VK/2) und Gewicht.

## Teil 4: Gebinde je Charge (charge_gebinde)
`erp_charge_gebinde_liste($charge_id)`, `erp_charge_gebinde($id)` (mit Charge/Item), `erp_charge_gebinde_anlegen($charge_id,$anzahl)` – Raw-SQL über die geteilte Dashboard-Tabelle `charge_gebinde` (Bestand je Karton: Gewicht/Restmenge/Ort). Anlegen ist idempotent (nur wenn noch keine existieren), teilt die Chargenmenge gleichmäßig (letzter Karton = Rest; Stück ganzzahlig). Fehlt die Tabelle (nur Dashboard legt sie an), no-op.
