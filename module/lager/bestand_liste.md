# lager/bestand_liste.php – Warenlager (Bestand)

**Zweck:** Bestandsübersicht aller lagernden Artikel (Rohstoffe, Verpackungen …). Der Bestand ergibt sich aus den **Chargen** je Item.

**Was passiert hier:**
1. `seed_charge_if_empty()` – legt lokal Demo-Chargen an.
2. Liest alle relevanten Items und rechnet je Item per Unterabfrage: **freier Bestand** (Σ verfügbare Menge freier Chargen), **Quarantäne**, **gesperrt** und **Anzahl Chargen**. Daraus: `frei` (frei verfügbar, Anzeige) und `gesamt` (physisch vorhanden = frei + Quarantäne + gesperrt).
3. **Kategorie-Filter** + **Suche**. **Sortierung** Standard = Name A–Z.
4. Tabelle: **Art.-Nr. · Name · Kategorie · Chargen · Bestand (frei) · Quarantäne · Unterwegs.** In der Bestand-Zelle (Reiter „Alle"/Betriebsmittel) stehen hinter dem freien Bestand kleine Badges für **Quarantäne**/**gesperrt**, damit Ware, die nur in Quarantäne liegt, nicht als „0/leer" wirkt.
   - Klick öffnet den Artikel (Rohstoff bzw. Verpackung).
5. Button „Wareneingang".

**Unterwegs (bestellt, noch nicht da):** Spalte **„Unterwegs"** je Artikel = Σ `bestellung_position.menge` aus Bestellungen mit `status='bestellt'` und **ohne** `angekommen_am` (bestellt, noch nicht eingegangen; geliefert/storniert zählen nicht). Als Info-Badge „… unterwegs". So sieht man im Lager, wieviel noch kommt (z. B. große Bulk-Bestellungen, siehe Bulk-Reservierung am Auftrag). Nicht bei Betriebsmitteln.

**Nullbestände ausblenden (Standard):** Ausgeblendet wird nur, was **physisch nichts** mehr hat **und nichts unterwegs** ist (`gesamt <= 0` UND `unterwegs <= 0`). Ware in **Quarantäne oder gesperrt** ist vorhanden (wartet auf Freigabe) und bleibt sichtbar, auch wenn `frei = 0`; ebenso **bestellte, noch nicht gelieferte** Ware (`unterwegs > 0`), auch wenn aktuell kein Bestand da ist. Betriebsmittel (Maschinen/Inventar) sind Anlagegüter und immer sichtbar. `?leer=1` zeigt alles. **Wichtig:** Der Lager-App-Wareneingang (`/lager/`) bucht viele Rohstoffe in **Quarantäne** ein – ohne diese Logik verschwänden sie hier.

**Bestandslogik:** `item_bestand($id)` summiert die freien Chargen. Rohstoffe u. Ä. brauchen Quarantäne (`item_braucht_quarantaene`), Verpackungen sind sofort frei.

**Einheiten nicht vermischen (A1, 2026-10-28):** Hat ein Artikel Chargen in **verschiedenen Einheiten** (z. B. 12 kg Bulk + 1.080.000 Stk), werden diese **nicht** zu einer Summe addiert. Die Bestand-/Quarantäne-Zelle zeigt dann je Einheit getrennt (`12 kg + 1.080.000 Stk`), gespeist aus `charge_bestand_je_einheit($id, ['frei'|'quarantaene'], $ort)` (`core/schema.php`). Erkannt über `n_einheit` (Anzahl verschiedener Einheiten mit Bestand) in der Abfrage. Bei nur einer Einheit bleibt die Anzeige wie bisher.

**Einheit aus der Charge, nicht vom Artikel (2026-10-28):** Auch bei nur einer Einheit kommt die angezeigte Einheit aus den Chargen (Spalte `charge_einheit` in der Abfrage, ort-abhängig über `$ortSql`), nicht aus `item.einheit`. Sonst zeigte z. B. ein als Fremdprodukt gebuchtes Fertigprodukt in der Lager-2-Sicht `384 kg`, obwohl es in Stk gebucht wurde. Nur Betriebsmittel nutzen weiter die Artikel-Einheit (`item.einheit`). `charge_bestand_je_einheit` nimmt jetzt `$ort` (`lager1`/`lager2`/`alle`), damit die Einheiten-Aufschlüsselung im Fremdlager die Kundenware zählt statt leer zu sein.

## Warenart-Labels + Reiter (Teil 3 Block K)
Die Typ-Labels kommen aus der zentralen Quelle `kategorie_label()` (`core/schema.php`): `fertig` zeigt als **Bulkware**, `verkaufsfertig` als **Fertigprodukt**. Die Keys von `$CHARGE_KAT` bleiben unverändert (sie steuern das WHERE/`$ALLE_KAT`), nur die Labels wurden vereinheitlicht. Zusätzlich gibt es getrennte Reiter **Bulkware** (`fertig`) und **Fertigprodukte** (`verkaufsfertig`). Admin sieht in der Leiste den Link „Warenarten prüfen" (`?p=warenart_pruefung`).

## Lager-Sicht: Alle / Lager 1 / Lager 2
Oberhalb der Warenart-Reiter gibt es eine Lager-Sicht (`?ort=`): **Alle Lager** (totale Übersicht = unser Bestand + Fremdlager), **Lager 1** (unser Bestand, `charge.fremd_kunde_id IS NULL`), **Lager 2 (Fremdlager)** (Kundenware, `fremd_kunde_id IS NOT NULL`). Die Lager-Sicht wird als SQL-Fragment (`$ortSql`) in die Charge-Summen eingesetzt; die Zahlen und damit auch das „leere ausblenden" richten sich nach dem gewählten Ort. In Lager 2 zählen Betriebsmittel (manueller Bestand) und „unterwegs" (unsere Bestellungen) nicht. Alle Steuerungen (Wareneingang, Artikelseite, Umbuchen) bleiben im Warenlager – die Sicht filtert nur. So ist direkt sichtbar, wo was liegt. `ort` wird durch alle Links (Reiter, Suche, baseUrl) mitgeführt.

In der Lager-2-Sicht zeigt eine Spalte **Besitzer** die Zuordnung (wem die Kundenware gehört) – aus den Fremd-Chargen (`GROUP_CONCAT` der Kunden), Fallback der am Fremdprodukt hinterlegte Besitzer (`item.fremd_besitzer_id`).

## Mengen lesbar (kg/t) – Pulver-Bulk (2026-11-01)
Der Mengen-Helfer `$mng($x,$e)` zeigt Bestände mit Charge-Einheit `g`/`ml` jetzt lesbar als **kg/t** bzw. **L** (via `gewicht_lesbar()`), z. B. „650 kg" statt „650.000 g". Rohstoffe in `kg` und Stück-Artikel (`Stk`) bleiben unverändert. Lager 2 (Fulfillment) zählt Fertigware in Stück (Beutel/Dosen) – dort ist „Stk" korrekt und wird NICHT in Gewicht gewandelt.

## Reiter Etiketten (08.11.2026)
Eigener Reiter „Etiketten“ (`?p=lager&kat=etikett`): Verpackungs-Artikel mit `verpackung_rolle=etikett`. Der Reiter „Verpackung“ zeigt Etiketten nicht mehr (nur noch Behälter, Deckel, Beutel usw.). „Alle“ unverändert.

**Schalter „Inaktive/gesperrte anzeigen“ (Block C, 11.11.2026):** standardmäßig aus, Gesperrte/Inaktive sind ausgeblendet (Lieferanten: offene Bewerbungen bleiben sichtbar; Kunden: Registrierungen bis Freigabe bleiben sichtbar).

## Klick öffnet den Reiter „Lager“ (12.11.2026)
Ein Klick auf eine Zeile öffnet den Artikel direkt im Reiter **Lager** (`&tab=lager`): Bestand, Quarantäne, Chargen, **Ort je Charge (Blinker/Kiste/Platz)** und **Bestand korrigieren** (Admin, mit Grund). Vorher öffnete sich der Reiter Stammdaten, Bestand und Blinker waren nicht zu sehen. Verpackungen hatten bis dahin gar keinen Reiter „Lager“.
