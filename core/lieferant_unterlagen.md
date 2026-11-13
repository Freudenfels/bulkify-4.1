# lieferant_unterlagen.php – Unterlagen-Regel Lieferanten

**Neue Regel (Nico 13.11.2026): Unterlagen fehlen = Quarantäne-Sperre, keine Bestell-Sperre.**
Bestellen und Einbuchen gehen immer. Die Ware bleibt nach dem Eingang in Quarantäne und darf nicht in die Produktion,
bis die Unterlage da ist. Artikel ohne Unterlagenpflicht: „kein Dokument nötig“. Ersetzt die Bestell-Sperre aus Paket 12.11. (Teil 4)
und Fable-4 T3.1.

1. **Preis abgeben ohne Unterlagen geht.** Der Preis trägt dann das Kennzeichen „Unterlagen fehlen“ (`lu_badge`).
2. **Aufgabenliste** „Diese Unterlagen fehlen noch“ (`lu_panel`, Portal `?p=lieferant_unterlagen`, an der Anfrage, intern an der Bestellung) mit Upload in der Zeile (`lu_upload_post`). Seit 13.11.2026 stehen dort auch die Bestellungen des Lieferanten (laufende und gelieferte mit gesperrter Charge). Text im Portal: „Bitte fehlende Unterlagen hochladen, sonst bleibt die Ware bei uns gesperrt.“
3. **Bestellen geht immer** (`lu_bestellen_sperre()` liefert nur noch '', `lu_bestellen_pruefen()` sperrt nicht). Fehlende Unterlagen sind ein gelber Hinweis (`lu_hinweis_text`, `lu_bestellen_hinweis`, `lu_hinweis_panel`): „Beim Lieferanten fehlen noch: CoA oder Spezifikation (Artikel). Die Ware bleibt nach dem Eingang in Quarantäne, bis die Unterlage da ist.“ Das Badge „Unterlagen fehlen“ bleibt an der Bestellung und in den Listen.
4. **Aufgabe „Unterlagen nachfordern: L-xxxx, Artikel, BE-Nr.“** (`lu_nachfordern_aufgabe`): Team-Aufgabe beim Bestellen, höchstens eine offene je Bestellung, erledigt sich in `lu_abgleich()` selbst. Keine Mail.
5. **Quarantäne-Sperre beim Wareneingang** (`lu_charge_nach_eingang`, aufgerufen aus `wareneingang_buchen()` und `erp_wareneingang_buchen()`): fehlen Unterlagen (`lu_charge_fehlt`), geht die Charge immer in Quarantäne (`charge.unterlagen_fehlt` = 1), auch wenn sie sonst frei wäre. Grund: `lu_charge_grund()` = „Quarantäne: CoA/Spec des Lieferanten fehlt“ (Dashboard, Lager-App, Lageretikett).
6. **Freigabe gesperrt** (`lu_freigabe_sperre`): `charge_quarantaene_freigeben()` und `erp_charge_status_setzen()` lehnen ab. Ausnahme nur Admin mit Pflicht-Grund (`charge_quarantaene_freigeben($cid, $grund)`, Vermerk an der Charge und im Protokoll). In den Freigabe-Listen zeigt `lu_freigabe_zelle()` den Grund, den Knopf „Unterlage hochladen“ (Pop-up) und für Admin „trotzdem freigeben“; die Seite verarbeitet beides mit `lu_freigabe_post()`.
7. **Unterlage nachgereicht** (`lu_abgleich`, nach jedem Upload und auf den Einkaufs-/Freigabe-Seiten): Aufgabe Einkauf erledigt; die Charge bleibt in Quarantäne (`unterlagen_fehlt` = 2) und die QM bekommt die Aufgabe „Unterlage da, Charge freigeben: …“ (einmalig). Die QM gibt normal frei.
8. **„kein Dokument nötig“** (`item.kein_dokument`, `kein_dokument_grund`, `kein_dokument_von`): `lu_item_pflichtig()` gibt dann false – kein Badge, keine Aufgabe, kein Portal-Punkt, keine Quarantäne-Sperre, nicht in der Datenprüfung. Setzen nur Admin/QM (`lu_darf_kein_dokument`), protokolliert (`lu_kein_dokument_setzen`). Haken am Artikel (Reiter Dokumente) und Knopf in den Listen fehlender Unterlagen (`lu_kein_dokument_knopf` + Pop-up `lu_kein_dokument_dialog`, Route `?p=kein_dokument`).
9. „versendet“ nur mit CoA je Charge: `bestellung_versand_coa_fehlt()` in `bestellung_chargen.php`.
10. **Alte Sperr-Entwürfe** (`bestellung.unterlagen_wartet` = 1, bis 13.11.2026): neue entstehen nicht mehr; `lu_entwurf_markieren()` löscht das Kennzeichen nur noch. An der Bestellung „Schon bestellt am …“ (Abschluss wie „heute bestellt“, ohne Mail).

## Was gebraucht wird (`lu_fehlt`)
- je Rohstoff (Katalog-Artikel) der Rezeptur bzw. je angefragtem Rohstoff: **CoA oder Spezifikation** am Rohstoff (egal von wem; liegt sie vor, zählt sie) – seit 13.11.2026 zählt auch eine CoA/Spec an einer Bestellposition dieses Artikels;
- bei Fremdfertigung, Premix und Bulk zusätzlich die **Spezifikation des Gesamtprodukts von diesem Lieferanten** (`dokument` objekt_typ `rezeptur`, lieferant_id);
- Verpackung, Etiketten, Verbrauchsmaterial und Artikel mit „kein Dokument nötig“: nichts.
Der Stand wird immer frisch berechnet – erledigte Punkte verschwinden sofort.

Funktionen: `lu_fehlt`, `lu_position_fehlt`, `lu_bestellung_fehlt`, `lu_bestellen_sperre`, `lu_ausnahme`, `lu_ausnahme_setzen`, `lu_badge`, `lu_offen_lieferant`, `lu_panel`, `lu_upload`, `lu_upload_post`, `lu_upload_nachlauf`.

## Upload nur an eigene offene Unterlagen (13.11.2026)
`lu_upload_erlaubt()`: Ein Lieferant darf nur zu Artikeln/Rezepturen hochladen, die in seiner offenen Unterlagen-Liste stehen (Aufgabenliste + fehlende Unterlagen seiner laufenden Bestellungen). Sonst abgelehnt und im Verlauf des Lieferanten protokolliert. Uploads des Teams (intern) bleiben frei.

## Unterlagen-Sperre zentral (13.11.2026, seit dem Sofortfix am selben Tag ohne Sperre – siehe oben)
Historisch: `lu_bestellen_pruefen($bid, $ausnahme_grund, $wer)`: eine Prüfung für alle Bestellwege. Genutzt von `bestellung_als_bestellt_markieren()` (Bestellseite, Einkauf mobil) und `bestellung_direkt_abschliessen()` (Einkaufsliste, Beschaffungsweg am Auftrag, Produktionsauftrag). Fehlen Unterlagen: gesperrt, Bestellung bleibt Entwurf; Admin-Ausnahme mit Pflicht-Grund. Online-Bestellungen ausgenommen.

**Stornierte Positionen (13.11.2026, Fable-3 T2.5):** `lu_bestellung_fehlt()` zählt Positionen mit `rest_storniert_am` (Position storniert bzw. Rest storniert) nicht mit – eine stornierte Position sperrt das Bestellen nicht mehr.

**Sperr-Entwurf (Fable-4 T3.1, 13.11.2026):** `lu_entwurf_markieren($bid)` setzt `bestellung.unterlagen_wartet` = 1, solange ein Entwurf (status offen) wegen fehlender Unterlagen nicht gesendet werden darf (aus `lu_bestellen_pruefen()` und `bestellung_als_entwurf()`), sonst 0; beim ersten Setzen Vermerk an den Aufträgen. `bp_rest_sql($a, $b)` zählt solche Entwürfe nicht als unterwegs. `lu_entwuerfe_pruefen()` löscht das Kennzeichen, wenn Unterlagen nachgereicht sind; `lu_sperr_entwuerfe()` liefert die Zeilen für „Zu bestellen“.

**Storniert zählt nirgends (Fable-4 T3.7, 13.11.2026):** Stornierte Positionen (`rest_storniert_am`) sind keine Quelle mehr für „Material zuordnen“, „Beschaffungsweg koppeln“ und Bulk reservieren; Reservierungen auf stornierten/gelöschten Positionen zählen nicht als Deckung oder bestellt; „Position stornieren“ setzt die Reservierungen der Position auf storniert. Fortschritt „Etiketten bestellt“ nur ab gesendet/bestellt und ohne Storno.

## Design der Liste (13.11.2026, Fable-5 D21/D22)
`lu_panel()` ohne rohe Datei-Felder: oben „Noch N offen“ und Filter nach Art (Rohstoff CoA/Spec, Gesamtprodukt Spec), gruppiert nach der ersten Quelle (Anfrage/Bestellung/Preis, mit Link), je Unterlage eine Zeile (am Handy Karte) mit Knopf „Hochladen“. Der Knopf öffnet EIN Pop-up je Liste (bx-dialog): Art, Datei, „Gültig bis“ (-> `dokument.dok_datum`), Bemerkung (-> `dokument.bemerkung`, Spalte wird bei Bedarf angelegt). Gilt für Portal, Anfrage und die internen Seiten.

13.11.2026: Pop-up „Unterlage hochladen“ – Formular ohne festes margin:0, damit der Pop-up-Baustein den Innenabstand setzt (vorher klebte der Inhalt am Rand und lief rechts über).
