# Lieferantenportal – Über uns (ueber_uns.php)

Route `?p=lieferant_ueber` (in `$LIEF_ROUTEN`, Menüpunkt „Über uns"). Statische, mehrsprachige Info-Seite (de/en/zh) für eingeloggte Lieferanten: wer bulkify ist, was wir tun, unsere Vision und was uns in der Zusammenarbeit wichtig ist.

Inhalt liegt als Array `$C[sprache]` direkt in der Datei (Fallback auf Deutsch), gerendert über das Portal-Layout (`lp_head`/`lp_shell_start`/`lp_foot`), Sprache über `lp_sprache()`. Reiner Lesetext, keine Formulare. Texte bei Bedarf hier anpassen.
