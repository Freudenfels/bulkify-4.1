# kontakt.php – Leute ohne Kundenkonto

## Wozu
Wer auf der Messe, per WhatsApp oder am Telefon etwas will, ist noch kein Kunde. Solche Leute stehen in `crm_kontakt` – mit Quelle, Phase, geschätztem Wert und Notiz.

## Phasen
`neu` → `im Gespräch` → `Angebot draußen` → `gewonnen` / `verloren`. Bewusst wenige, damit sie gepflegt werden.

## Verlauf
Jede Berührung eine Zeile in `crm_verlauf` (Notiz, Anruf, WhatsApp, Mail, Treffen, Angebot). Damit sieht man beim nächsten Kontakt in drei Sekunden, was zuletzt war.

## Der Weg ins Dashboard
`kontakt_zu_kunde()` legt im Dashboard einen Kunden an – über `core/erp.php`, die einzige Schreibstelle. Der Kontakt **bleibt bestehen** und zeigt danach per `kunde_id` dorthin, damit der Verlauf nicht verloren geht.

Die Kundennummer vergibt das Dashboard selbst beim ersten Speichern; das CRM fasst dessen Nummernkreis nicht an.
