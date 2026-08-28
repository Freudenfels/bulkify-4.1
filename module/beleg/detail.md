# beleg/detail.php – Rechnung (Ansicht + Zahlungen + Status)

**Zweck:** Zeigt eine Rechnung, erfasst **Zahlungseingänge (auch Teilzahlungen)** und leitet daraus den Zahlstatus ab (offen / teilbezahlt / bezahlt / storniert).

**Zahlungen (Teilzahlung):** Formular „Zahlung erfassen" mit **Betrag · Überweisungsdatum (Valuta, das echte Datum – nicht die Erfassungszeit) · Konto · Art · Anmerkung**. Konto = Auswahl der in den Einstellungen hinterlegten Konten (`bank_konten()`: Deutschland/International), sonst Freitext. Jeder Eingang landet in Tabelle `zahlung` (betrag, datum=Valuta, konto, art, notiz, akteur, angelegt=UTC). Der **Status wird automatisch** aus Summe der Eingänge vs. Brutto gesetzt (`beleg_zahlstatus()`): 0 = offen, >0 und <Brutto = **teilbezahlt**, ≥Brutto = **bezahlt**; `storniert` bleibt erhalten. Kacheln zeigen Status · Brutto · **Bezahlt** · **Offener Rest**. Tabelle „Zahlungseingänge" mit Summe/Restzeile. Betrag im Formular ist mit dem offenen Rest vorbelegt. `zahlung_erfassen()` schreibt zusätzlich einen Statusverlauf-Eintrag (inkl. Valuta + Konto + Restbetrag) und bei Vollzahlung einen Kundeneintrag. Badge „teilbezahlt" (info) auch in `rechnungen_liste.php` und im Kundenportal (`reBadge`).

**Was passiert hier:**
- **POST `aktion=zahlung`:** bucht einen Zahlungseingang (`zahlung_erfassen()`), zieht den Status automatisch nach.
- **POST `aktion=status`:** manuelles Setzen des Status (Override, z. B. storniert) – hinter „Status manuell setzen" eingeklappt; speichert den Status **nur bei echter Änderung** und schreibt einen **Statusverlauf-Eintrag** (Wer/Wann/Status/Anmerkung) über `beleg_status_log_add()`. Optionales Feld „Anmerkung" (z. B. „Zahlungseingang per Überweisung"); bei gleichem Status wird eine reine Anmerkung ebenfalls protokolliert. Beim Umstellen auf „bezahlt" zusätzlich ein Verlaufseintrag am Kunden. `akteur` = eingeloggter Benutzername (sonst „team").
- **Anzeige:** Kennzahlen (Status, Netto, USt mit Satz, Brutto), Details mit Verlinkung zum **Auftrag** und ein **Statusverlauf** (Tabelle Wann/Status/Wer/Anmerkung, neueste zuerst).
- **Statusverlauf:** eigene Tabelle `beleg_status_log` (beleg_id, status, notiz, akteur, angelegt UTC). `beleg_status_verlauf()` liest den Verlauf und legt bei Altbelegen einmalig einen **„erstellt"-Eintrag** aus `beleg.angelegt` an (Backfill). Zeitanzeige immer via `fmt_zeit()` (Europe/Berlin).

**Herkunft:** Wird zusammen mit dem Auftrag von `auftrag_aus_angebot()` erzeugt. USt: 19 % (DE) bzw. 0 % (EU-Ausland). GoBD-Ausbau (Jahresnummern, Storno nur per Gutschrift) folgt später.
