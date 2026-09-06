# erfassen.php – schnell erfassen

Route `?p=erfassen`. Der Fall: Du stehst auf der Messe oder liest eine WhatsApp-Nachricht und willst in zehn Sekunden festhalten, dass jemand etwas will. Pflicht ist nur Name **oder** Firma.

Die Erinnerung steht standardmäßig auf **in 3 Tagen** – wer nichts ändert, bekommt den Kontakt von selbst wieder vorgelegt. Genau darum geht es in diesem Programm.

## Drei Wege hinein
1. **Von Hand tippen.**
2. **Aus WhatsApp teilen.** Im `manifest.webmanifest` ist das CRM als `share_target` eingetragen; auf Android erscheint es damit im Teilen-Menü. Text kommt als POST (`title`, `text`, `url`) an, ein geteiltes Bild als Datei. **Nur Android** – iOS kennt kein share_target.
3. **Visitenkarte fotografieren.** Das Feld öffnet direkt die Kamera (`capture="environment"`) und schickt sich beim Auswählen selbst ab.

## Dublettenprüfung
Sobald Name oder Firma stehen, prüft `core/dublette.php`, ob es die Firma schon als Kontakt oder Kunde gibt – mit Angabe, warum. Der Kasten steht **über** dem Formular, damit man ihn sieht, bevor man speichert.

## Ohne KI
Ist kein Schlüssel hinterlegt, fehlen der Foto-Kasten und der Knopf „Aus dem Text ausfüllen“ – der Rest funktioniert unverändert.
