# lieferant/onboarding.php – Pflicht-Erstlogin-Assistent (Lieferant)

**Zweck:** Ein **neu** angelegter Lieferant (Bewerbung/Einladung/manuell) muss beim ersten Login einen
**mehrstufigen Assistenten** durchlaufen, der **Stammdaten & Anschrift** einsammelt, bevor das Portal nutzbar ist.

**Gating:** `lieferanten.onboarding_fertig` (TINYINT, Default 0). In `index.php` wird ein echter Lieferant
(`ist_echter_lieferant()`, NICHT die Admin-Vorschau) mit `onboarding_fertig<>1` auf `?p=lieferant_onboarding`
umgeleitet – außer bei `lieferant_onboarding` selbst und `logout`. Ist es fertig, führt der Aufruf der
Onboarding-Route zurück ins Portal. Route steht in `$LIEF_ROUTEN`.

**Einmal-Migration:** Beim Hinzufügen der Spalte setzt `init_schema()` alle **bestehenden** Lieferanten einmalig
auf `onboarding_fertig=1` (Marker `app_meta.lief_onboarding_init`) – niemand wird nachträglich ausgesperrt. Nur
künftig neu angelegte starten mit 0.

**Ablauf:** 2 Schritte (Client-JS, ein POST): (1) Firma (read-only) + Ansprechpartner* + E-Mail + Telefon +
Webseite, (2) Straße*/Nr./PLZ*/Ort*/Land + USt-IdNr. Pflicht: Ansprechpartner, Straße, PLZ, Ort (serverseitig
geprüft). Speichern setzt die Felder auf `lieferanten` + `onboarding_fertig=1` → `?p=lieferant_portal&onboarded=1`.

**Rahmen/Sprache:** `lp_head()`/`lp_foot()` (bewusst OHNE Menü-Shell – Portal noch nicht frei), Sprachwahl via
`lp_sprachwahl()`. Texte de/en/zh über ein lokales `$T` + `lp_t()` für geteilte Labels.
