# echtheit.php – Echtheits-/Qualitäts-QR je Charge

## Wozu
Auf dem bulkify-CoA/Spec einer Charge steht ein QR-Code. Scannt der Kunde ihn, öffnet sich die öffentliche Prüfseite `public/echt.php` und **bestätigt den Rohstoff dieser Charge** mit genau den Angaben, die auch im Dokument stehen – ein einfacher Vertrauens-/Qualitätsnachweis.

## Mechanik – Token, keine Verschlüsselung
Der Link enthält **kein verschlüsseltes Datenpaket**, sondern ein **zufälliges, nicht erratbares Token** (`charge.echt_token`, 22 Zeichen base62 ≈ 128 Bit), das in der DB auf die Charge zeigt. Vorteile gegenüber „Daten in den Link verschlüsseln":
- **Nicht hochzählbar** – niemand kann Charge-IDs durchprobieren.
- **Widerrufbar** – `charge.echt_gesperrt=1` → Prüfseite zeigt „nicht mehr gültig".
- **Kurz** genug für einen kleinen QR; angezeigte Infos ändern wir serverseitig, ohne neue Codes zu drucken.

## Funktionen
- `echt_token_neu()` – neues 22-Zeichen-base62-Token aus `random_bytes`.
- `charge_echt_token(int $charge_id): string` – Token der Charge; **lazy** (erzeugt + speichert bei Bedarf, kollisionssicher). `''` wenn nicht möglich (Charge fehlt / Spalte fehlt – nie werfend).
- `charge_echt_url(int $charge_id): string` – fertiger Prüf-Link (erzeugt bei Bedarf das Token).
- `echt_url(string $token)` / `echt_basis_url()` – Linkbau; Basis aus `mail_basis_url()` (Einstellung `portal_url`, sonst aktueller Host).
- `charge_echt_daten(string $token): ?array` – **kundensicheres** Bündel für die Prüfseite: `name` (Anzeige-Name), `charge_nr`, `mhd`, `herkunft`, `spec`, `allergene`, `werte[]` (Parameter/Spezifikation/Ergebnis/Methode), `ist_coa`, `firma`. Rückgabe `null` = Token unbekannt, `['gesperrt'=>true]` = widerrufen.

## Produkt-Ebene (QR im PIB)
Das PIB gibt es pro **Produkt** (nicht pro Charge), darum ein eigenes Token auf `produkt.echt_token`:
- `produkt_echt_token(int $produkt_id)` / `produkt_echt_url(int $produkt_id)` – analog zur Charge, eigener Link-Parameter `?p=` (`echt_url_produkt()`).
- `produkt_echt_daten(string $token): ?array` – Produktname (Kundenname bevorzugt), Darreichungsform und die **Liste der ausgestellten Chargen** dieses Produkts: Chargen mit eigenem, nicht gesperrtem Token, verknüpft über Produktionsauftrag (`charge.pa_id`) **oder** Fertigware-Item (`item.produkt_id`). Jede Zeile verlinkt auf die Charge-Prüfseite (`?t=`).

## Sicherheit / Datenschutz
Die Prüfseite zeigt **nur** die kundensicheren Felder (wie das bulkify-CoA) – **nie** Lieferant, EK, Menge, Wareneingang, Quarantäne-/Lagerstatus. Da das Token nur auf einem bereits ausgehändigten Dokument steht, entsteht kein neues Datenleck. `echt_gesperrt` ist der Not-Aus.

## Grenze (bewusst)
Ein QR bestätigt „diese Angaben hat bulkify für diese Charge veröffentlicht" und ist nicht erratbar – er schützt **nicht** gegen das Abfotografieren/Kopieren des Codes auf eine Fälschung. Dafür bräuchte es Seriennummern je Einheit + Scanzähler (möglicher späterer Ausbau).

## Wo es hängt
- QR im Dokument: `core/pdf_spec.php` (`spec_qr()`, `spec_qr_panel()`, `spec_echt_panel()`) in `build_coa_pdf()` (pro Charge) und `core/pdf_pib.php` (pro Produkt).
- Öffentliche Seite: `public/echt.php` (`?t=` Charge, `?p=` Produkt).
- Spalten: `charge.echt_token`/`charge.echt_gesperrt` und `produkt.echt_token`/`produkt.echt_gesperrt` (in `core/schema.php`, Indizes `idx_echt_token`).
