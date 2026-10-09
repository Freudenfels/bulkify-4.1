# beleg_positionen.php — Rechnungspositionen aus dem Angebot / manuell

- `beleg_positionen_aus_angebot($beleg_id)`: liest über die Naht (`erp_auftrag`/`erp_angebot_positionen`) die
  hinterlegten Angebotspositionen, wählt die Konfigurations-Gruppe, deren Packungspreis × Auftragsmenge
  dem Auftrags-Netto entspricht (bei nur einer Gruppe diese), und schreibt sie als `beleg_position`
  (Menge = Auftragsmenge). Mirror der Dashboard-Logik `beleg_positionen_aus_auftrag`, aber schreibend.
  Rückgabe `['ok','anzahl','grund']`. Ändert NICHT die Kopfsummen (GoBD) – Positionen sind die
  Aufschlüsselung des bestehenden Betrags; Abweichungen zeigt die Detailseite als Hinweis.
  **Jahresvertrag-Abruf:** Hat der Auftrag ein `kontingent_id`, schlüsselt `be_kontingent_positionen()`
  die gewählte JV-Option (aus `kontingent.angebot_id`/`gruppe`) auf und **skaliert** sie auf den Festpreis
  je Packung (`kontingent.vk_stueck`) → getrennte Zeilen (Produkt + Glas + Etikett), die in Summe genau den
  Festpreis ergeben (Rundungsdrift auf die größte Zeile). Zugriff auf die geteilten Tabellen nur über die
  Naht (`erp_kontingent`, `erp_angebot_positionen`). Spiegelt `kontingent_abruf_positionen()` im Dashboard.
  **Fallback:** Hat der Auftrag kein verknüpftes Angebot/Kontingent (oder ist die Option nicht eindeutig) oder
  das Angebot nur Staffelpreise (keine Einzelpositionen), wird **eine Sammelposition** aus dem Auftrag geschrieben:
  Produktname, Auftragsmenge, Netto/Menge als Einzelpreis (reproduziert das Rechnungs-Netto). Spiegelt den
  Fallback des Dashboard-Materializers `beleg_positionen_materialisieren`. Dadurch ist der Button auch für
  Abruf-Rechnungen ein Ein-Klick-Fix, wenn eine Rechnung nur mit Kopf-Netto (ohne Positionen) existiert.
- `beleg_positionen_manuell_setzen($beleg_id, $zeilen)`: ersetzt alle Positionen aus einem Formular
  (bezeichnung/menge/einheit/preis €/ust). Leere Zeilen werden übersprungen. Kopfsummen bleiben.

Automatisch: `rechnung_aus_auftrag()` (finanz.php) ruft `beleg_positionen_aus_angebot()` nach dem Anlegen
auf – neue Rechnungen aus einem Auftrag bekommen die Aufschlüsselung also direkt (falls im Angebot vorhanden).
UI: Buttons + manueller Editor in `module/beleg/detail.php` (Panel „Positionen").
