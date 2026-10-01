# lager/core/qr.php – QR-Encoder (abhängigkeitsfrei)

Erzeugt QR-Codes **ohne** externe Bibliothek/GD. `qr_matrix($text)` liefert eine Modul-Matrix (Array von Zeilen aus 0/1) oder `null`, wenn der Text zu lang ist. Der PDF-Generator zeichnet die dunklen Module als schwarze Rechtecke (siehe [module/bestand/etikett.php](../module/bestand/etikett.php)).

**Umfang:** Byte-Modus, Fehlerkorrektur-Level **M**, Versionen **1–6** (bis 106 Bytes – reicht für Chargen-URLs locker). Enthält: GF(256)-Reed-Solomon, Blockaufteilung + Interleaving, Finder/Timing/Alignment/Dunkelmodul, Datenplatzierung im Zickzack, alle 8 Masken mit Strafpunkt-Bewertung (beste wird gewählt), Formatinfo (BCH 15,5 + XOR 0x5412). Versionen ≥7 (mit Versionsinfo) sind bewusst ausgespart.

**Validiert:** Ausgabe wurde mit jsQR (echter Decoder) gegengeprüft – Matrix **und** das fertige PDF (über pdf.js gerendert) dekodieren korrekt, nicht gespiegelt. Wer hier etwas ändert, prüft erneut gegen einen echten Decoder.
