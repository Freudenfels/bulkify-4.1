# markdown.php – Markdown anzeigen

Klein gehalten, absichtlich. Die KI liefert den Fragenkatalog als Markdown; gebraucht wird nur, was
der Prompt auch verlangt: Überschriften, **fett**, *kursiv*, `code`, Aufzählungen, Striche. Eine
vollständige Markdown-Bibliothek wäre hier mehr Risiko als Nutzen.

## Sicherheit
Der Text wird **zuerst vollständig escaped**, danach werden nur die eigenen Zeichen wieder zu HTML.
Was die KI schreibt, kann also kein HTML einschleusen – geprüft mit einem `<script>` im Text, das
als Text ankommt.
