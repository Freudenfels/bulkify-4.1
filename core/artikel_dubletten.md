# core/artikel_dubletten.php – Artikel-Dubletten erkennen und zusammenführen

**Stand 10.11.2026 (Einkauf gerade ziehen, Teil 4/5).**

**Regel (Nico):**
- Gleiche Qualität von verschiedenen Lieferanten ist **ein** Artikel mit mehreren Lieferantenpreisen.
- Andere Spezifikation ist ein eigener Artikel, z. B. 13 % vs. 20 % Mg, Bio vs. konventionell oder ein anderer Extraktgehalt.
- Bulk-Kapseln, Premixe und Fertigware mit anderer **Kapselgröße**, anderem **Füllgewicht** oder anderem **Kapselmaterial** sind **immer** eigene Artikel.

## Funktionen
- `artikel_norm_key($name)`: Normalform für Gruppen. Kleinschreibung, Umlaute umgeschrieben, ohne Zahlen/%/Bio und ohne Füllwörter (Pulver, Bulk, Kapseln …). Synonyme werden vereinheitlicht: Ascorbinsäure = Vitamin C, (Bis-/Di-)Glycinat = Glycinat, Creatin = Kreatin, Zinc = Zink. Bei Verpackung bleiben die Zahlen drin (Größen).
- `artikel_spec($name)`: Gehalt in % und Bio aus dem Namen.
- `artikel_info($id)`: Vergleichsdaten:
  - Spec, Kapselgröße/Füllgewicht/Material,
  - Lieferanten (Preise, Bestellungen, Chargen), Bestand,
  - Anzahl Chargen, Rezepturen, Bestellungen, Produkte.
- `artikel_merge_sperren($haupt, $quelle)`: Gründe gegen das Zusammenführen:
  - andere Einheit oder Warenart,
  - andere Kapselgröße, anderes Füllgewicht oder anderes Material,
  - Bulk/Premix verschiedener Rezepturen,
  - anderer Gehalt, Bio und konventionell,
  - eine Rezeptur enthält beide Artikel.
- `artikel_dubletten_gruppen()`: aktive Artikel mit gleicher Normalform (ab 2).
- `artikel_aehnliche($id)`: ähnliche Artikel, inklusive zusammengeführter (für die Vorschläge im Altlasten-Abgleich).
- `artikel_dubletten_treffer($name)`: „Meintest du …?“ beim Anlegen.
- `artikel_zusammenfuehren_voll($quelle, $ziel)`: nur ohne Sperre, in einer Transaktion.
  - **Zieht um:** Chargen, Reservierungen, Bestellpositionen, Rezepturzeilen, Lieferantenpreise/Katalog/Anfragen, Verbrauch, Herstellprotokoll, Proben, Dokumente, Produkt-Slots und weitere Bezüge.
  - Wirkstoffe, Grenzwerte und Kennwerte nur, wenn der Hauptartikel keine hat.
  - Der alte Artikel wird gesperrt (`zusammengefuehrt_in`, Vermerk), sein Name wandert in `item.synonyme` des Hauptartikels.
  - Protokoll in `aenderung_log` und in den Aktivitäten beider Artikel. Gelöscht wird nichts.

- `artikel_dubletten_gruppen()` gruppiert je **Warenart**. Bulk-Kapseln Größe 0 und 00 stehen so in einer Gruppe, gesperrt mit „andere Kapselgröße“. Rohstoffe stehen getrennt.
