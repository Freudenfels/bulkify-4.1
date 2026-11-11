# lager/core/inventur_etikett.php – Etiketten der Inventur

Seit 11.11.2026. Etiketten im Format 100 × 70 mm, Druck über die Druckbrücke (`lg_druckjob`).
- `inv_platz_etikett_pdf($ids)`: Platz-Etikett fürs Regal, mit Platzname groß sowie QR und Strichcode `LP-00012`. Drucktyp `platz` mit `ids=`.
- `inv_klaerfall_etikett_pdf($zaehlung_id)`: Etikett für ein Gebinde, das beim Zählen ohne Etikett gefunden wurde. Es trägt die neue Inventarnummer und ist ab dann scanbar. Drucktyp `inventur`.
