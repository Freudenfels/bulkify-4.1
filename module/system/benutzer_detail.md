# system/benutzer_detail.php – Benutzer anlegen & bearbeiten (Admin)

**Zweck:** Ein Mitarbeiter-Konto pflegen. Nur **admin**.

**Felder:** Name, E-Mail (Login, eindeutig), Passwort (neu = Pflicht; beim Bearbeiten leer = unverändert), **Konto aktiv**, **Rollen als Mehrfachauswahl** (Checkboxen aus `rollen_liste()`) – jemand kann z. B. Finance **und** Einkauf sein.

**Speichern (POST):**
- Validierung: Name/E-Mail Pflicht, E-Mail eindeutig, neues Konto braucht Passwort.
- Rollen werden gegen `rollen_liste()` gefiltert und als CSV in `benutzer.rollen` gespeichert.
- Passwort via `password_hash()` (nur bei neuem Konto oder wenn ein neues eingegeben wurde).
- **Lockout-Schutz:** Änderungen werden abgelehnt, wenn danach **kein aktiver Admin** mehr übrig wäre.
