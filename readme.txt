=== Korkwand ===
Contributors: werthdavid
Tags: pinboard, notes, corkboard, community, members
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A cork pinboard for your site: members pin post-it notes and photos, guests just look.

== Description ==

Korkwand adds a cork pinboard to your WordPress site. Logged-in members pin colourful post-it notes and photos to the board, drag them around, and edit or remove their own notes. Guests can look at the board, but cannot post, and they do not see who wrote what.

Typical uses: a community or club notice board, a team wall, a classroom or family pinboard, a "looking for / offering" board for a neighbourhood.

**Features**

* Any number of boards, managed under *Korkwand > Boards* in the admin.
* Post-it notes and photo notes, freely positioned on the board.
* Per board: note lifetime in days (0 = never expires), board height, maximum number of notes, email notification on new notes, and the page that shows the board.
* Embed a board with the block **Korkwand board** or the shortcode `[korkwand board="slug"]`.
* **Latest notes**: show the newest notes of a board (or of all boards) on your home page, in a sidebar or anywhere else, with the block **Korkwand latest notes** or the shortcode `[korkwand_latest board="slug" count="5" show_date="1"]`. Each entry links to the board page and scrolls to the note, which is briefly highlighted.
* Responsive: on phones the board turns into a simple list.
* Moderators can edit or delete any note and mark notes as sticky.
* Expired notes are removed automatically by a daily cleanup.
* Image uploads are re-encoded to strip EXIF/GPS data and stored with neutral file names.
* Rate limit of 10 new notes per user and hour.
* Privacy export and erase tools of WordPress are supported.
* No external services, no tracking, no cookies of its own.
* Translated into German (de_DE).

**Who can do what**

* Guests: view the board and the latest notes list.
* Subscriber, Contributor, Author, Editor, Administrator (capability `korkwand_post`): create notes and edit or delete their own notes.
* Editor and Administrator (capability `korkwand_moderate`): edit and delete any note, mark notes as sticky.

You can grant or remove these capabilities with any role editor plugin.

**Shortcodes**

`[korkwand board="slug"]` shows a board.

`[korkwand_latest board="slug" count="5" show_date="1"]` shows the newest notes. `board` is optional (empty = all boards), `count` is 1 to 20, `show_date` is 1 or 0.

**Board page and deep links**

For each board you can choose the page that shows it (setting "Board page"). If none is set, Korkwand detects the page that embeds the board. Links in the latest notes list use the anchor `#korkwand-note-ID`, so the visitor lands on the board with the note scrolled into view and highlighted.

== Installation ==

1. Upload the `korkwand` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen in WordPress.
2. Activate the plugin through the Plugins screen.
3. Go to *Korkwand > Boards* and create a board. Give it a short slug, for example `club`.
4. Add the block **Korkwand board** to a page (or put `[korkwand board="club"]` on it), and choose that page as "Board page" in the board settings.
5. Optional: add the block **Korkwand latest notes** or `[korkwand_latest board="club"]` to your home page.

== Frequently Asked Questions ==

= How do I show the newest notes on my home page? =

Add the block **Korkwand latest notes** to the home page (or to a widget area / template in the site editor), pick the board, the number of notes and whether to show dates. Or use the shortcode `[korkwand_latest board="slug" count="5" show_date="1"]`. Each entry links to the board page and highlights the note. Set the "Board page" in the board settings, or let Korkwand detect the page that embeds the board.

= Who can post notes? =

Logged-in users with the capability `korkwand_post`. By default these are Subscriber, Contributor, Author, Editor and Administrator. Guests can only look.

= How do I moderate notes? =

Editors and Administrators (capability `korkwand_moderate`) can edit and delete any note directly on the board, and mark notes as sticky. In the admin, notes are listed under *Korkwand > Notes*. You can also enable an email notification per board to be told about new notes.

= Does it work with page caching? =

The board and the latest notes list are public and can be cached. Posting and editing use the WordPress REST API with a nonce, so notes appear for the posting user immediately; other visitors see them after the cache is refreshed. If you use aggressive caching, exclude pages with the board for logged-in users, which most cache plugins do by default.

= What are the upload limits? =

JPEG, PNG and WebP images up to 8 MB, or the PHP/WordPress upload limit of your host if that is lower. The current limit is shown in the add-note dialog.

= Does it work on phones? =

Yes. On narrow screens the board becomes a vertical list of notes and the add-note dialog is full width.

= How long do notes stay on the board? =

That is set per board ("Lifetime in days"). 0 means notes never expire. Expired notes are removed by a daily cleanup, including their uploaded images.

= What happens to my data when I delete the plugin? =

By default, uninstalling removes all notes, uploaded images, boards, options and capabilities created by Korkwand. Enable the option to keep the data if you want to preserve it.

= Can I change the colours or fonts? =

Yes, with custom CSS. The stylesheet uses CSS custom properties prefixed with `--korkwand-`.

== Screenshots ==

1. The board on desktop
2. The board as a list on a phone
3. Adding a note
4. Latest notes on the home page

== Privacy ==

Korkwand stores the following data in your WordPress database and uploads folder:

* The WordPress user ID of the author of each note.
* The note text and its position, colour and creation date.
* Uploaded images, re-encoded to remove EXIF and GPS metadata and stored under neutral, random file names.

Korkwand does not store IP addresses, does not set its own cookies, does not use external services and does not load external fonts or scripts. The rate limit works on user IDs only.

Author names are hidden from guests: only logged-in members see who wrote a note.

Korkwand registers a personal data exporter and eraser with the WordPress privacy tools (*Tools > Export Personal Data* and *Erase Personal Data*). Notes and images of a user are included in the export and are deleted on erasure. If you enable the email notification of a board, an email with the note title and the author's display name is sent to the site administrator address.

== Changelog ==

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
