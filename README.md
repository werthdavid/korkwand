# NZ Pinnwand

Pinnwand (Korkwand) für WordPress: Angemeldete Mitglieder heften Post-its und Fotos an, Gäste sehen die Wand nur an.
Voraussetzungen: WordPress ab 6.5, PHP ab 8.1.

## Einbinden

- **Pinnwand anlegen:** im Admin unter *Pinnwand → Pinnwände* (Taxonomie). Pro Pinnwand: Lebensdauer in Tagen (0 = nie), Höhe, maximale Zettelzahl, E-Mail-Benachrichtigung.
- **Shortcode:** `[korkwand board="slug"]` in eine Seite oder einen Beitrag einfügen.
- **Block:** „Pinnwand“ (`korkwand/board`) im Block-Editor einfügen und die Pinnwand auswählen.
- Unbekannte Pinnwand: Gäste sehen nichts, Administratoren einen Hinweis.

## Rollen und Rechte

- **Zettel anheften** (`korkwand_post`): Abonnent, Mitarbeiter, Autor, Redakteur, Administrator. Gäste können nur ansehen.
- **Moderieren** (`korkwand_moderate`): Redakteur und Administrator. Sie können fremde Zettel ändern, löschen und anheften („Sticky“, läuft nie ab).
- Jeder Nutzer ändert und löscht nur eigene Zettel. Pro Nutzer sind höchstens 10 neue Zettel pro Stunde möglich.
- Zettel werden nur im Frontend angelegt. Im Admin (*Pinnwand*) lassen sie sich prüfen, bearbeiten, in den Papierkorb legen und löschen.
- Gäste sehen nie Autorennamen.

## Seiten-Cache

Die Zettel stehen im ausgelieferten HTML. Mit einem Seiten-Cache (Plugin, CDN, Hosting) sehen Gäste neue Zettel erst nach dem Leeren des Caches. Seite mit der Pinnwand vom Cache ausnehmen oder eine kurze Lebensdauer (TTL) einstellen. Angemeldete Nutzer aktualisieren die Ansicht nach jeder Aktion selbst.

## Bilder-Upload

- Erlaubt: JPEG, PNG, WebP, höchstens 8 MB. Metadaten (EXIF/GPS) werden entfernt, Dateinamen anonymisiert (`pinnwand-<zufall>.<endung>`).
- Das effektive Limit ist das Minimum aus 8 MB und den Host-Einstellungen. Empfohlen: `upload_max_filesize` und `post_max_size` mindestens 8 MB (besser 10 MB), sonst erscheint „Das Bild ist zu groß (maximal X MB)“.
- Beim endgültigen Löschen eines Zettels wird sein Plugin-Upload mitgelöscht; Mediathek-Bilder bleiben unangetastet.

## Schrift (optional)

Für die Handschrift-Optik die Datei `assets/fonts/patrick-hand.woff2` (Patrick Hand, SIL Open Font License 1.1, Quelle: google/fonts, `ofl/patrickhand`) ablegen. Ohne die Datei wird „Comic Sans MS“ bzw. eine Standard-Schreibschrift verwendet.

## Datenschutz

Die Zettel eines Nutzers sind in den WordPress-Werkzeugen für Datenexport und -löschung enthalten. Beim Löschen eines Nutzers werden dessen Zettel endgültig gelöscht (auch bei Zuweisung der Inhalte an einen anderen Nutzer). Beim Deinstallieren werden alle Daten entfernt, sofern die Option dazu aktiviert ist (Standard).

## Entwicklung und Release

- Tests: `npm run test:php` (benötigt `wp-env`), `npm run test:js`.
- Release-ZIP: `npm run build:zip` erzeugt `dist/korkwand.zip` ohne Entwicklungsdateien (siehe `.distignore`; benötigt `rsync` und `zip`).
