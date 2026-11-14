# VLJ Claude Redaktion

WordPress-Plugin für die Website der Vereinigung Liberaler Juristen in Niedersachsen e.V.: Konnektor für Claude (MCP) mit Anmeldung über Microsoft Entra (nur freigeschaltete Konten).

- Plugin-Code: `vlj-claude-redaktion/`
- Neue Version: Versionsnummer im Plugin-Kopf und in `VLJCR_VERSION` erhöhen, committen, Tag `vX.Y.Z` pushen. Der Workflow `Release` baut die Zip mit Prüfsumme; die Website aktualisiert sich dann selbst.
- Keine Geheimnisse in diesem Repository. Client-ID, Geheimnis und erlaubte Konten stehen nur in den WordPress-Einstellungen.
