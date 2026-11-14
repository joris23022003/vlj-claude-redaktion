=== VLJ Claude Redaktion ===
Stable tag: 1.4.1
Requires at least: 6.0
Requires PHP: 7.4
License: GPLv2 or later

Konnektor für Claude (MCP-Server im WordPress-Plugin) mit Anmeldung über Microsoft Entra.

== Zugang ==
Nur Konten aus "Erlaubte Konten" (Werkzeuge > Claude Redaktion) im eingestellten Microsoft-Mandanten.
Standard: stietenroth@julis-niedersachsen.de (geprüft über die unveränderliche Objekt-ID).
Ablauf: Claude registriert sich (OAuth 2.1, PKCE), die Person meldet sich bei Microsoft an, das Plugin
prüft Mandant, Objekt-ID und Nonce und gibt erst dann ein Token aus (1 Stunde, Erneuerung 90 Tage).

== Einrichtung ==
1. Entra-App (dieselbe wie bei den Mail-Konnektoren): Umleitungs-URI (Web) von der Einstellungsseite eintragen, neuen geheimen Clientschlüssel erzeugen.
2. In WordPress unter Werkzeuge > Claude Redaktion: Anwendungs-ID und Schlüsselwert eintragen, speichern.
3. In Claude: Eigenen Konnektor hinzufügen mit der "Adresse für Claude", verbinden, mit stietenroth@ anmelden.

== Aktualisierung ==
Über GitHub-Releases von joris23022003/vlj-claude-redaktion (Tag vX.Y.Z, Anhang vlj-claude-redaktion-X.Y.Z.zip,
im Release-Text "sha256: <prüfsumme>"). Automatische Aktualisierung ist nach der Installation eingeschaltet.

== Werkzeuge ==
website_info, inhalte_liste, inhalt_lesen, menues_lesen, medien_liste, protokoll_lesen, entwurf_anlegen,
inhalt_aendern, text_ersetzen, status_setzen, bild_hochladen, beitragsbild_setzen, zuruecksetzen, menue_aendern,
optionen_suchen, option_lesen, option_text_ersetzen, option_zuruecksetzen, plugin_selbst_aktualisieren,
baukasten_lesen, baukasten_aendern (ab 1.4.0: Brizy-Elemente strukturiert lesen, kopieren und ändern).

== Sicherheit ==
- Ausschalten: Häkchen "Konnektor eingeschaltet" entfernen oder "Alle Anmeldungen widerrufen".
- Vor jeder Änderung eine Sicherung (bis 20 je Seite), dazu WordPress-Revisionen und ein Protokoll.
- Kein Zugriff auf Benutzer, Plugins, Themes oder Einstellungen.
