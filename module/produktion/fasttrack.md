# fasttrack.php – Fast Track (schnelles Nachtragen)

Route `?p=fasttrack` (Rollen sales/finance/production/fulfillment), Nav „Vertrieb → Fast Track".

Zweck: viele Aufträge zügig auf den richtigen Stand bringen (Rückstand aufholen), v3-Style.
- Auftrag suchen (Nummer/Kunde/Produkt) → aus der Trefferliste wählen.
- **Status per Klick setzen** (offen / in Produktion / versandbereit / versendet / storniert) – direktes Setzen überspringt Produktionsschritte.
- **Datum** (leer = heute) – wird als `auftrag.status_datum` gespeichert und ist im **Kundenportal** sichtbar („seit TT.MM.JJJJ").
- **Notiz** (optional), auch per **Spracheingabe** (Web Speech API, de-DE, Button „🎤 Sprache" oder Strg+D) – wird in der Kunden-Aktivität protokolliert.

Kern-Logik: `auftrag_status_setzen($id,$status,$datum,$notiz,$akteur)` in core/schema.php (setzt Status+Datum, protokolliert,
storniert bei 'storniert' automatisch offene Rechnungen). Dieselbe Datums-Logik greift auch im normalen Auftrag-Detail.
