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
