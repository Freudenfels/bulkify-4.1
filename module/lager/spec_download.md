# lager/spec_download.php – Spec-PDF eines Rohstoffs ausliefern

Route: `?p=spec_pdf&id=<item-id>` (interne Rollen)

Liefert das am Rohstoff hinterlegte Spec-PDF des Lieferanten (`item.spec_pdf`) aus `data/uploads` – dem Ordner außerhalb von `public`, an den man sonst nicht herankommt. 404, wenn kein Spec hinterlegt ist oder die Datei fehlt.

Das ist das **Originaldokument des Lieferanten**. Für das eigene Spec-Blatt im bulkify-Layout siehe `core/pdf_spec.md` (`?p=spec_bulkify`).
