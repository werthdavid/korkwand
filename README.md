# Korkwand

A cork pinboard for WordPress: board editors pin post-it notes and photos to a board, everyone else can only look.
Requires WordPress 6.5+ and PHP 8.1+. License: GPL-2.0-or-later.

This file is the developer README. End-user documentation lives in `readme.txt` (WordPress.org format).

## Features at a glance

- Boards are managed in the admin under *Korkwand → Boards* (taxonomy `korkwand_board`). Per board: lifetime in days (1 to 3650; sticky notes never expire), height, maximum number of notes, email notification.
- Embed a board with the shortcode `[korkwand board="slug"]` or the block `korkwand/board`.
- Show the newest notes (e.g. on the home page or in a sidebar) with `[korkwand_latest board="slug" count="5" show_date="1"]` or the block `korkwand/latest`. Entries link to the board page (set per board, otherwise auto-detected from the page embedding the board) and highlight the note there.
- Permissions: viewing is public. `korkwand_post` (create notes, edit/delete own) belongs to the `korkwand_editor` role ("Board editor", capabilities `read` + `korkwand_post` only, no `upload_files`), editors and administrators. `korkwand_moderate` (edit/delete any note, mark notes sticky) belongs to editors and administrators. Boards are created and configured with `manage_options`. The role and capabilities are installed on activation and removed on uninstall (Board editors become subscribers).
- Notes are a custom post type (`korkwand_pin`), created only through the REST API (`korkwand/v1`) from the frontend.
- Image uploads: JPEG, PNG, WebP up to 8 MB (or the host limit if lower), re-encoded to strip EXIF/GPS, file names anonymised (`korkwand-<random>.<ext>`).
- Rate limit: 10 new notes per user per hour. Privacy export/erase hooks and uninstall cleanup are included.

## Development setup

The host needs Node.js and Docker; PHP is not required locally because the tests run inside [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).

```sh
npm install
./node_modules/.bin/wp-env start
./node_modules/.bin/wp-env run tests-cli --env-cwd=wp-content/plugins/korkwand composer install
```

wp-env mounts the repository folder under its folder name, so the checkout directory must be called `korkwand`
(otherwise adjust the `--env-cwd` path in the `test:php` script in `package.json`).
The development site runs on http://localhost:8888, the test site on http://localhost:8889.

## Tests

```sh
npm run test:php   # PHPUnit inside wp-env (tests/test-*.php)
npm run test:js    # node --test for the pure geometry helpers (tests/js)
```

## Build

```sh
npm run build:zip
```

Creates `dist/korkwand.zip` with the top-level folder `korkwand/`, excluding development files listed in `.distignore`
(requires `rsync` and `zip`).

## Internationalisation

All user-facing source strings are English with the text domain `korkwand` (`Domain Path: /languages`).
Frontend script modules cannot use `wp_set_script_translations()`, so the PHP side translates all JavaScript UI strings
and passes them as `window.Korkwand.i18n` (see `Render::js_strings()`). The block editor script uses the regular
`wp.i18n` functions. German translations live in `languages/korkwand-de_DE.po` (compiled `.mo` and JSON files are committed).

## Code layout

- `korkwand.php`: plugin header, constants, autoloader (`Korkwand\` namespace, `includes/class-*.php`)
- `includes/`: model, REST API, rendering, permissions, upload handling, cleanup, privacy, admin
- `assets/js/`: ES modules (registered as script modules with versioned import map), `assets/css/korkwand.css`
- `blocks/board/`: block.json, editor script, server-side render
- `uninstall.php`: removes data unless the option to keep it is set
