# crm/core/lead_ki.php – Anfrage von der KI auswerten

**Zweck:** Eine hereingekommene Anfrage (roher Freitext in der Notiz eines Kontakts) von der KI
einordnen lassen – damit eine Website-Anfrage sofort nutzbar ist, ohne dass jemand sie erst liest.

**Kernfunktion:** `lead_ki_auswerten(int $kontakt_id, int $uid = 0, string $text = ''): array`
- `$text` leer → es wird die Notiz des Kontakts ausgewertet. Sonst der übergebene Text (z. B. der
  neue Anfragetext bei einer Dublette im Website-Eingang).
- Ruft `ki_json()` (`core/ki.php`) mit dem pflegbaren Prompt und dem **schnellen** Modell
  (`KI_MODELL_SCHNELL`) auf – eine Einordnung braucht kein großes Modell.
- **Wirft nie** (try/catch) und gibt `['ok'=>bool,'daten'=>array,'fehler'=>string]` zurück. Ein Fehler
  darf den Website-Eingang nie stören.

**Was gespeichert wird (nur `crm_`-Tabellen, nie im Dashboard):**
1. Lesbare **„KI-Auswertung der Anfrage"** als Verlaufseintrag (`kontakt_verlauf`).
2. **Geschätzter Wert** (`crm_kontakt.wert_eur`) – nur, wenn noch keiner gesetzt ist (nichts überschreiben).
3. **Wiedervorlage** (`kontakt_wiedervorlage`), damit die Anfrage in „Wer wartet auf mich" auftaucht –
   nur, wenn noch keine offene hängt (kein Dublettenaufbau beim erneuten Auswerten).
4. Zeitstempel **`crm_kontakt.ki_ausgewertet`** – damit die automatische Auswertung nicht doppelt läuft.

Stammdaten (Name, Firma, E-Mail …) werden **nicht** angefasst – die KI fasst nur zusammen und schlägt
einen nächsten Schritt vor.

**Prompt:** liegt in `crm/prompts/lead_auswertung.md` und ist **ohne Code-Änderung pflegbar**
(`lead_ki_prompt()` liest die Datei, mit knappem Ersatztext als Fallback). Erwartetes JSON:
`zusammenfassung, produktform, wirkstoffe, menge, wert_eur, dringlichkeit, naechster_schritt,
frist_tage, offene_punkte`. `lead_ki_saeubern()` prüft/kappt die Felder, `lead_ki_notiz()` baut die
lesbare Verlaufsnotiz (nur gefüllte Zeilen).

**Konstante:** `CRM_LEAD_NACHFASSEN` (Standard 2) – Nachfassfrist in Tagen, wenn die Anfrage keine
eigene Frist nennt.

**Benutzt von:**
- `public/crm/lead_intake.php` – automatisch im Hintergrund nach dem Quittieren jeder Anfrage.
- `crm/module/kontakt/detail.php` – Knopf „Anfrage auswerten" / „Neu auswerten" (manuell/erneut).
