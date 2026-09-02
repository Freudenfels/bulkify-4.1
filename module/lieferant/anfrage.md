# lieferant/anfrage.php – Preisanfragen beantworten

Route: `?p=lieferant_anfrage[&id=<ID>]`

Der Lieferant sieht seine Anfragen (offen zuerst) und gibt ein **Angebot** ab: Preis je Einheit, Einheit, Mindestmenge (MOQ), Lieferzeit und **Mengenstaffeln** (`lieferant_angebot_staffel`). Erneutes Senden überschreibt das eigene Angebot – die Anfrage geht dabei auf `beantwortet`.

Hängt die Anfrage an einem Artikel, kann der Lieferant hier auch **CoA und Spezifikation hochladen**; die Dateien landen über `dokument_upload()` direkt am Artikel (`objekt_typ='item'`) mit ihm als Lieferant – dort sucht sie das Team.

Nimmt das Team das Angebot an, werden die Staffeln zu EK-Staffeln am Artikel (siehe `core/schema.md`, `lieferant_angebot_annehmen()`).
## E-Mail ans Team
Speichert der Lieferant sein Angebot zur Preisanfrage, bekommen alle Admins eine Mail mit Preis, Mindestmenge und Lieferzeit (`mail_team_preisanfrage()`), sofern der Versand eingerichtet ist.
## Rückfragen und Downloads
Unter dem Angebotsformular steht das Panel **Rückfragen** (`nachricht_panel()` mit Bezug `lieferant_anfrage`): Fragen und Antworten zu genau dieser Preisanfrage, POST `aktion=nachricht`. Die eigenen CoA/Spezifikationen sind jetzt verlinkt (`?p=lieferant_dokument&id=`).
## Produkttyp und Einheit
Die Anfrage sagt, **was** angefragt wird (`art`: Rohstoff, Fertigprodukt, Verpackung, Verbrauch, Sonstiges) und in welcher **Form** (`form`). Beide Arten haben eine Form, damit es überall gleich heißt: „Fertigprodukt · Kapseln" neben „Rohstoff · Pulver". Fertigprodukt: Kapsel, Tablette, Softgel, Stick, Pulver, Granulat, Flüssig. Rohstoff: Pulver, Granulat, Flüssig, Öl, Extrakt (wird aus `item.form` übernommen, wenn nichts gewählt ist). Daraus ergibt sich die **Einheit automatisch** (`anfrage_einheit()` in `core/schema.php`): Kapsel/Tablette/Softgel/Stick werden je Einheit bepreist, Pulver und Granulat je kg, Flüssiges je Liter; sonst zählt die Bezugsgröße am Artikel. Der Lieferant kann die Einheit nicht ändern (das Feld ist gesperrt), damit Angebote vergleichbar bleiben. Angezeigt wird sie in der richtigen Zahlform: „250.000 Kapseln", aber „Preis je Kapsel" (`einheit_wort()` in `core/schema.php`, im Portal über `lp_einheit($e, $menge)`).

## Preisbasis
Weil Kapseln üblicherweise je 1.000 gehandelt werden, wählt der Lieferant, ob sein Preis **je 1** oder **je 1.000** Einheiten gilt (`lieferant_angebot.preis_basis`). Beim Übernehmen der Preise teilt `lieferant_angebot_annehmen()` durch die Basis – am Artikel steht immer der Preis je einer Einheit.

## Sprachen
Alle Texte laufen über `lp_t()`; Zahlen über `lp_num()` (Deutsch 1.234,5 – English und Chinesisch 1,234.5) und Einheiten über `lp_einheit()` (Stück/Kapsel/Tablette … werden übersetzt, kg und L nicht).

## Angebot abgeben
Über dem Preisfeld steht, **für welche Menge** der Preis gilt (die angefragte Menge). Die erste Staffelzeile ist mit genau dieser Menge vorbelegt – der Lieferant trägt nur den Preis daneben ein. Weitere Zeilen sind **freiwillig** und nur dann sinnvoll, wenn andere Mengen günstiger werden. Ein Angebot ganz ohne Staffel ist gültig; dann zählt der Preis oben, und beim Übernehmen entsteht daraus eine Staffel ab der Mindestmenge.
