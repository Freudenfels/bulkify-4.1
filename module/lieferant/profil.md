# lieferant/profil.php – eigene Daten

Route: `?p=lieferant_profil`

## Was der Lieferant selbst pflegt
- **Firmendaten:** Firma (Pflicht), Straße + Hausnummer, PLZ, Ort, Land (Kürzel), USt-IdNr./Steuernummer, Webseite. Er weiß am besten, wie seine Firma heißt und wo sie sitzt.
- **Kontakt:** Ansprechpartner, E-Mail, Telefon, **WeChat** und **WhatsApp** – die Wege, über die im Asiengeschäft tatsächlich gesprochen wird.
- **Sprache:** Deutsch oder English; wirkt sofort auf das ganze Portal.
- **Firmenlogo:** PNG, JPG oder WebP, höchstens 2 MB. Der Dateityp wird über den Bildinhalt geprüft, nicht über die Endung; das alte Logo wird ersetzt statt angesammelt. Ausgeliefert wird es über `?p=lieferant_logo` (die Datei liegt außerhalb von `public`).

**Nicht** pflegbar: Konditionen, Zahlungsziele, Preise, Sperrung – das bleibt beim Team.

## Intern
Auf der Lieferantenseite erscheint ein Panel **„Vom Lieferanten gepflegt"** mit Logo, WeChat und WhatsApp, sobald etwas davon hinterlegt ist.
