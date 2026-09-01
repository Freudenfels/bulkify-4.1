# lieferant/anfrage.php – Preisanfragen beantworten

Route: `?p=lieferant_anfrage[&id=<ID>]`

Der Lieferant sieht seine Anfragen (offen zuerst) und gibt ein **Angebot** ab: Preis je Einheit, Einheit, Mindestmenge (MOQ), Lieferzeit und **Mengenstaffeln** (`lieferant_angebot_staffel`). Erneutes Senden überschreibt das eigene Angebot – die Anfrage geht dabei auf `beantwortet`.

Hängt die Anfrage an einem Artikel, kann der Lieferant hier auch **CoA und Spezifikation hochladen**; die Dateien landen über `dokument_upload()` direkt am Artikel (`objekt_typ='item'`) mit ihm als Lieferant – dort sucht sie das Team.

Nimmt das Team das Angebot an, werden die Staffeln zu EK-Staffeln am Artikel (siehe `core/schema.md`, `lieferant_angebot_annehmen()`).
