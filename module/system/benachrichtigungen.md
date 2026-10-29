# benachrichtigungen.php – Team-Postfach (Benachrichtigungen)

Route `?p=benachrichtigungen` (eingeloggt). Zentrales Postfach für Team-Ereignisse mit **als-gelesen-Markierung
je Benutzer**. Erreichbar über das Feld „Benachrichtigungen" in der Sidebar-Userbox (zeigt die Anzahl ungelesener).

- Quelle: `mail_team()` (core/mail.php) legt bei JEDEM Team-Ereignis zusätzlich eine Benachrichtigung an
  (`benachrichtigung_add()`), unabhängig vom Mailversand, und zieht den Deep-Link aus dem Mailtext. Automatisch
  abgedeckt: Lieferanten-Rückfragen, beantwortete Preisanfragen, angenommene Angebote, neue Bestellungen,
  Etikett-Uploads. Weitere Ereignisse lassen sich mit `benachrichtigung_add($betreff,$text,$link)` ergänzen.
- Tabellen: `benachrichtigung` (betreff/text/link/erstellt) + `benachrichtigung_gelesen` (benachrichtigung_id,
  benutzer_id, gelesen_am). Ungelesen = kein Lese-Eintrag für diesen Benutzer.
- Helfer (core/schema.php): `benachrichtigung_add`, `benachrichtigungen_ungelesen_count`,
  `benachrichtigungen_liste`, `benachrichtigung_gelesen_setzen` (einzeln oder alle).
- Seite: neueste zuerst, Filter Alle/Nur ungelesen, je Eintrag „gelesen" + „Alle als gelesen markieren",
  Deep-Link „öffnen".

Unterschied zum Protokoll (`?p=protokoll`): Das Protokoll ist die vollständige Historie (wer/wann/was, Admin).
Die Benachrichtigungen sind das kuratierte Postfach, das jeder Benutzer aktiv abarbeitet (als gelesen markiert).

## Erweiterung 2026-10-30 (Teil 5): Art, Wichtig, Filter, Auto-Hinweise, Menüpunkt
- **Art + Wichtig:** `benachrichtigung.art` (VARCHAR, Default 'sonstiges') + `benachrichtigung.wichtig` (TINYINT). `benachrichtigung_add($betreff,$text,$link,$art,$wichtig)` – alte 3-Argument-Aufrufe (inkl. `mail_team()`) bleiben gültig (Art 'sonstiges'). Arten-Labels: `benachrichtigung_arten()`.
- **Nur einmal je Schlüssel:** `benachrichtigung_add_once($schluessel, …)` + UNIQUE-Index auf `benachrichtigung.schluessel` – für „einmal je Charge/Bestellung".
- **Automatische Hinweise:** `benachrichtigungen_auto_pruefen()` (core/schema.php), einmal je Tag gedrosselt (app_meta `benach_autocheck_am`), aufgerufen vom Dashboard:
  - MHD-Ablauf: Charge mit Bestand, MHD in ≤ `mhd_warn_tage` (Einstellung, Default 30) → Art 'mhd', wichtig, Schlüssel `mhd:<id>`.
  - Überfällige Bestellung: `status='bestellt'`, nicht angekommen, Bestelldatum ≥ 3 Tage alt → Art 'ueberfaellig', wichtig, Schlüssel `overdue:<id>`.
- **Seite** (`?p=benachrichtigungen`): zusätzlich Filter nach Art (Buttons, nur vorkommende Arten), Art-Chip je Zeile, **wichtige** Einträge rot hervorgehoben. Bestehend: Alle/Nur ungelesen, einzeln/alle als gelesen.
- **Menüpunkt** in der System-Gruppe mit ungelesen-Zähler (Badge via `$anfCount['benachrichtigungen']`). Zugriff admin-only (Route-Default).
- **Admin-Mails gespiegelt:** alle über `mail_team()` versendeten Ereignisse (Angebot angenommen, Bestellung, Preisanfrage, Etikett hochgeladen …) erzeugen schon automatisch eine Benachrichtigung (core/mail.php). Die Wiege-Abweichungen (Teil 4) nutzen `benachrichtigung_add(..., 'wiegen', true)`.
- **Einstellungen:** `mhd_warn_tage` (30) und `schwund_grenze_kg` (1) im Reiter Produktion (Panel „Chargennummer & MHD").
