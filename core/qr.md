# qr.php – QR-Encoder (abhängigkeitsfrei)

Erzeugt aus einem Text (z. B. einer URL) eine **Modul-Matrix** (Array aus 0/1-Zeilen), die ein PDF-Generator als schwarze Rechtecke zeichnet. Kein GD, keine externe Bibliothek. Byte-Modus, Fehlerkorrektur-Level M, Versionen 1–6 (bis ~106 Bytes – reicht locker für die Echtheits-Links).

- `qr_matrix(string $text): ?array` – Matrix oder `null`, wenn der Text zu lang ist.
- Gezeichnet wird außerhalb (z. B. `spec_qr()` in `core/pdf_spec.php`): weißer Grund + 2 Module Ruhezone, dann je 1-Modul ein kleines Rechteck.

**Wichtig:** Das ist eine **wortgleiche Kopie** von `lager/core/qr.php`. Das Dashboard-`core` darf die Sub-App `lager/` nicht requiren, darum die Kopie. Ein `function_exists('qr_matrix')`-Guard verhindert Doppeldeklaration, falls beide Dateien je im selben Request geladen würden. **Bei Änderungen beide Dateien pflegen.**
