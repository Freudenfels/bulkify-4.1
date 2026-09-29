# bulkify offline nutzen (z. B. im Flugzeug)

bulkify läuft komplett **lokal auf dem Laptop** – ohne Internet. Nur die **KI-Funktionen**
(Fastaction-Auswertung, KI-Vorschläge) brauchen Internet und sind offline aus. Alles andere
– Angebote, Aufträge, Produktion, Lager, Kundenportal, Rechnungen, Etiketten … – funktioniert.

## Starten (Doppelklick)
1. **`start-bulkify.bat`** doppelklicken.
2. Der Browser öffnet automatisch **http://127.0.0.1:8741** und loggt dich als Admin ein.
   - Falls kein Auto-Login: einfach **admin@bulkify.local** / **admin** eingeben.
3. Losspielen.

Der Server läuft im (minimierten) Fenster **„bulkify-server"**. Lässt du es offen, bleibt bulkify erreichbar.

## Beenden
- **`stop-bulkify.bat`** doppelklicken – oder das Fenster „bulkify-server" schließen.
- MariaDB (die Datenbank) läuft als Windows-Dienst weiter; das ist normal und stört nicht.

## Gut zu wissen
- **Adresse:** http://127.0.0.1:8741 (nur auf diesem Laptop erreichbar).
- **Daten:** die lokale Datenbank `bulkify41`. Änderungen bleiben lokal – sie gehen **nicht** auf beta/Live.
- **KI offline:** Fastaction erfasst deine Nachricht als Aufgabe/Notiz, macht aber offline keine KI-Auswertung.
  Sobald wieder Internet da ist, funktioniert die KI wieder.
- **Voraussetzungen** (auf diesem Laptop bereits eingerichtet): PHP unter `C:\php`, MariaDB-Dienst „MariaDB",
  Datenbank `bulkify41`.
