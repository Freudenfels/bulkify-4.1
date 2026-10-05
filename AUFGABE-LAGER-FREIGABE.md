# Aufgabe (Lager-Chat): Charge aus Quarantäne freigeben – Button auf der Charge-Seite

> Für den **Lager-Chat** (nur `lager/`, `public/lager/`).

## Problem
Rohstoffe/Fertigware gehen beim Wareneingang in **Quarantäne** (`charge.status='quarantaene'`). Die Produktion
zählt nur **freigegebenen** Bestand (`status='frei'`), zeigt also für Ware in Quarantäne „Bestand 0".
Der Wareneingang-Hinweis sagt „auf der Charge-Seite freigeben", aber `lager/module/bestand/charge.php`
hat **keinen** Freigeben-Button. Im Dashboard gibt es ihn (`module/lager/wareneingang.php`:
`UPDATE charge SET status='frei' WHERE id=? AND status='quarantaene'`).

## Aufgabe
In `lager/module/bestand/charge.php` eine Aktion **freigeben** ergänzen (Muster wie die bestehenden Aktionen binden/umbuchen/menge_korr):

```php
if ($aktion === 'freigeben') {
    q("UPDATE charge SET status='frei' WHERE id=? AND status='quarantaene'", [$id]);
    flash('Charge freigegeben – steht jetzt als Bestand zur Verfügung.');
    weiter('?p=charge&id=' . $id);
}
```

Und im Status-Bereich der Seite (wo `status_badge($c['status'])` steht) einen Button zeigen, **nur wenn** `$c['status'] === 'quarantaene'`:

```php
<?php if ($c['status'] === 'quarantaene'): ?>
  <form method="post" style="margin-top:8px" onsubmit="return confirm('Diese Charge aus der Quarantäne freigeben? Danach zählt sie als verfügbarer Bestand.');">
    <input type="hidden" name="aktion" value="freigeben">
    <button type="submit" class="btn btn-primary btn-sm">Aus Quarantäne freigeben</button>
  </form>
<?php endif; ?>
```

## Hinweise
- Optional (wie bei menge_korr) eine Bewegung/Protokollzeile schreiben, muss aber nicht.
- Rechte: wie die anderen Korrektur-Aktionen der Seite (aktuell für alle Lager-Nutzer). Falls ihr QS-Freigabe nur für bestimmte Rollen wollt, dort gaten.
- Co-located `charge.md` ergänzen. Rebase-Ampel beim Push.
- Danach zählt die Ware sofort im Produktions-Programm (Rohstoffbedarf/„produzierbar").
