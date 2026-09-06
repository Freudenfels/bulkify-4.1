# mail_ki.php – E-Mail einfügen, Rest erledigt sich

## Wozu
Mail markieren, kopieren, einfügen. Die KI liest heraus, wer schreibt, worum es geht und bis wann;
das Programm sucht die Person in Kunden und Kontakten und legt Notiz und Wiedervorlage an.

Kopfzeilen, Signatur, Haftungsausschluss und der zitierte Verlauf dürfen mit rein – die Anweisung
sagt ausdrücklich, dass sich die KI auf die **neueste** Nachricht bezieht.

## Nicht jede Mail ist ein Vorgang
`art` ordnet ein: Anfrage, Antwort, Nachfrage, Bestellung, **Rechnung**, **Newsletter**, Sonstiges.
Bei Rechnung und Newsletter wird **kein Kontakt angelegt** und keine Erinnerung gesetzt
(`mail_ki_ohne_vorgang()`). Ohne diese Regel hätte man nach einer Woche dreißig Karteileichen.

## Wer ist das?
Die Zuordnung läuft **ohne KI** über `dublette.php` – Namen vergleichen ist Rechenarbeit. Der
verlässlichste Treffer gewinnt: gleiche E-Mail vor gleicher Telefonnummer vor gleicher Firma vor
gleichem Namen vor ähnlicher Firma. Ein bestehender **Kunde** hat Vorrang vor einem Kontakt.

## Wann wird erinnert?
Nennt die Mail eine Frist („bis Freitag"), wird sie in Tage umgerechnet. Sonst drei Tage – aber nur,
wenn die Mail überhaupt eine Antwort erwartet (`antwort_noetig`).

## Die eine Regel
**Es wird nichts ohne Bestätigung gespeichert.** `mail_ki_plan()` beschreibt vorher, was passieren
würde; die Seite zeigt es, und erst ein Klick führt es aus. Zwei Sekunden Lesen verhindern, dass
eine falsch verstandene Mail still im falschen Kundenkonto landet.
