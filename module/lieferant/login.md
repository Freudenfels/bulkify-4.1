# lieferant/login.php – Anmeldung für Lieferanten

Route: `?p=lieferant_login` (öffentlich)

Eigene Login-Seite, damit ein Lieferant nie auf dem Team-Login landet. Angemeldet wird mit E-Mail und Passwort; akzeptiert werden **nur** Benutzer mit gesetztem `benutzer.lieferant_id`. Die Fehlermeldung ist bewusst unspezifisch und zweisprachig – sie verrät nicht, ob es die Adresse gibt.

Wer noch keinen Zugang hat, braucht den Einladungslink (siehe `einladung.md`).

**Sperre (11.11.2026):** Ist der Lieferant gesperrt, kommt bei richtigem Passwort „Zugang gesperrt, bitte bulkify kontaktieren“ statt des Portals. Derselbe Hinweis erscheint, wenn eine laufende Sitzung wegen Sperre beendet wurde.
