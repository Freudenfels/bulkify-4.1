# core/charge_korrektur.php – Charge: Menge korrigieren

Abendpaket Block B (11.11.2026). Eine Funktion für alle Wege: Chargenseite im Dashboard, Lager-App (Charge), Artikel „Bestand korrigieren“ und „Menge korrigieren und verknüpfen“ (Bestellseite, Datenprüfung). Vorher gab es zwei Wege, die sich unterschiedlich verhielten. Das Dashboard überschrieb die Ursprungsmenge ohne Bewegung, und die Lager-App hatte keine Admin-Prüfung.

**Zwei Arten**
- **Ursprungsmenge** (Einbuchfehler, Lieferschein abweichend): `charge.menge` = neu. Die verfügbare Menge verschiebt sich um dieselbe Differenz, nie unter 0. Beispiel 260908: eingebucht 300.000, laut Lieferschein 240.000.
- **nur verfügbar** (Schwund, Bruch): nur `menge_verfuegbar` = neu, der Ursprung bleibt.

**Was gespeichert wird:** Nichts wird spurlos überschrieben.
- Datensatz in `charge_korrektur`: alt, neu, Differenz, Grund, Notiz, Beleg (Dokument-ID), Gebinde-Hinweis, Bestellung, wer, wann.
- Bewegung in `lg_bewegung`: typ `korrektur`, Menge mit Vorzeichen. Erscheint in der Lager-App unter Bewegungen.
- Verlauf der Charge: `lg_charge_log` und `aktivitaet`.

**Gebinde:** Die Differenz geht auf das letzte Gebinde, bei einem Abgang notfalls weiter nach vorne. Die Meldung nennt die betroffenen Gebinde. Einzelne Gebinde lassen sich in der Lager-App nachwiegen.

**Bestellung:** Hängt die Charge an einer Bestellposition, rechnet `webe_position_neu_rechnen()` sofort neu. `webe_bestellung_stand(..., oeffnen=true)` schließt die Bestellung oder öffnet sie wieder.

**Funktionen**
- `charge_menge_korrigieren($id, $was, $neu, $grund, $notiz, $von, $beleg)` – bucht die Korrektur.
- `charge_korr_gruende()` – Einbuchfehler, Lieferschein abweichend, Schwund, Bruch, Sonstiges (bei Sonstiges ist die Notiz Pflicht).
- `charge_korr_liste($id)` – die bisherigen Korrekturen.
- `charge_korr_beleg_speichern($_FILES[...], $id)` – legt den Beleg als `dokument` an der Charge ab, Download über `?p=dokument&id=`.
- `charge_korr_dialog($charge, $dlgId, $hidden, $vorbelegt, $was, $titel, $knopf)` – das Pop-up.

Nur Admin, die Prüfung macht der Aufrufer (`has_role('admin')` bzw. `lg_ist_admin()`).
