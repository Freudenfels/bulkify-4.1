# assets/etikett_vorschau.js – Etikett-Design als Vorschau

`bxEtikettVorschau(element, url, istPdf)` zeigt ein Etikett-Design im Element an:
- **PDF:** Seite 1 wird mit pdf.js (cdnjs, Version 3.11.174, wird erst bei Bedarf geladen) als Bild gezeichnet. So ist es auch am Handy/PWA sichtbar, wo eingebettete PDFs nicht angezeigt werden.
- **Bild:** wird direkt gezeigt.
- Darunter steht immer ein Knopf „Design öffnen“. Lädt die Vorschau nicht (pdf.js nicht erreichbar oder Datei kaputt), erscheint ein Hinweis mit diesem Knopf.

Genutzt in der Lager-App (Wareneingang → „Etiketten einbuchen“, Quelle `?p=dok&id=`) und im Dashboard-Wareneingang (`?p=dokument&id=`).
