=== Korkwand ===
Contributors: werthdavid
Tags: pinboard, notes, corkboard, community, members
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A cork pinboard for your site: board editors pin post-it notes and photos, everyone else just looks.

== Description ==

Korkwand adds a cork pinboard to your WordPress site. Users with the Board editor role (and editors and administrators) pin post-it notes and photos to the board, drag them around, and edit or remove their own notes. Visitors can look at the board, but cannot post, and guests do not see who wrote what.

Typical uses: a community or club notice board, a team wall, a classroom or family pinboard, a "looking for / offering" board for a neighbourhood.

**Features**

* Any number of boards, managed under *Korkwand > Boards* in the admin.
* Post-it notes and photo notes, freely positioned on the board.
* Per board: note lifetime in days (1 to 3650), board height, maximum number of notes, email notification on new notes, and the page that shows the board.
* Embed a board with the block **Korkwand board** or the shortcode `[korkwand board="slug"]`.
* **Latest notes**: show the newest notes of a board (or of all boards) on your home page, in a sidebar or anywhere else, with the block **Korkwand latest notes** or the shortcode `[korkwand_latest board="slug" count="5" show_date="1"]`. Each entry links to the board page and scrolls to the note, which is briefly highlighted.
* Responsive: on phones the board turns into a simple list.
* Editors and administrators can edit or delete any note and mark notes as sticky. Sticky notes never expire.
* Every board has a lifetime: expired notes are hidden immediately and cleaned up automatically, including their images.
* Image uploads are re-encoded to strip EXIF/GPS data and stored with neutral file names.
* Rate limit of 10 new notes per user and hour.
* Privacy export and erase tools of WordPress are supported.
* No external services, no tracking, no cookies of its own.
* Translated into German (de_DE).

**Who can do what**

* Everyone, including guests: view the board and the latest notes list.
* Board editor (a role added by Korkwand), Editor, Administrator (capability `korkwand_post`): create notes and edit or delete their own notes.
* Editor and Administrator (capability `korkwand_moderate`): edit and delete any note, mark notes as sticky.
* Administrator: create and configure boards.

Subscribers, Contributors and Authors cannot post. To let someone post, give them the Board editor role (*Users > Edit user > Role*). The role has no other rights; in particular it cannot access the media library.

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

Only users with the capability `korkwand_post`: the **Board editor** role that Korkwand adds, Editors and Administrators. Everyone else, including guests and all other logged-in users, can only look. To let someone post, open *Users*, edit the user and set the role to *Board editor* (or choose it when adding a new user). The Board editor role can only create notes and edit or delete its own notes. Editors and Administrators can in addition edit and delete any note.

= How do I moderate notes? =

Editors and Administrators (capability `korkwand_moderate`) can edit and delete any note directly on the board, and mark notes as sticky. In the admin, notes are listed under *Korkwand > All Notes*. You can also enable an email notification per board to be told about new notes.

= Does it work with page caching? =

The board and the latest notes list are public and can be cached. Posting and editing use the WordPress REST API with a nonce, so notes appear for the posting user immediately; other visitors see them after the cache is refreshed. If you use aggressive caching, exclude pages with the board for logged-in users, which most cache plugins do by default.

= What are the upload limits? =

JPEG, PNG and WebP images up to 8 MB, or the PHP/WordPress upload limit of your host if that is lower. The current limit is shown in the add-note dialog.

= Does it work on phones? =

Yes. On narrow screens the board becomes a vertical list of notes and the add-note dialog is full width.

= Are author pages available? =

Author archives (`/author/name/`) answer 404 for every user who has no published public content (posts, pages or other public post types), so the names of note authors cannot be looked up. Users with published posts keep their author page.

= How long do notes stay on the board? =

That is set per board ("Lifetime in days", 1 to 3650, default 100). Sticky notes never expire. Expired notes disappear from the board immediately; a daily job moves them to the trash, and they are permanently deleted together with their uploaded images when WordPress empties the trash (after 30 days by default).

= What happens to my data when I delete the plugin? =

By default, deleting the plugin removes all notes, uploaded images, boards, options and capabilities created by Korkwand. To keep your data, untick *Remove all notes and images when the plugin is deleted* under *Korkwand > Settings* before deleting the plugin.

= Does it work on multisite? =

Yes. Data is per site: boards, notes and settings of each site are separate. The uninstall cleanup runs once when the plugin is deleted and only handles the site it runs on (the main site), so other sites of the network keep their data; remove it there manually if needed.

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
* The note text, its position and tilt on the board, the sticky flag and its creation date.
* Uploaded images, re-encoded to remove EXIF and GPS metadata and stored under neutral, random file names. They are stored as media attachments with the uploader's user ID as author.

Korkwand does not store IP addresses, does not set its own cookies, does not use external services and does not load external fonts or scripts. The rate limit works on user IDs only.

Author names are never shown to guests: only logged-in users see who wrote a note. Author archives answer 404 for every user without published public content (posts, pages or other public post types), and `?author=ID` does not redirect to them, so note authors cannot be looked up from outside. Side effect: such users, for example subscribers, have no author page on your site; users with published posts keep theirs. Only Board editors, Editors and Administrators can post. Notes expire after the lifetime of their board unless they are sticky. Deleting a board permanently deletes all of its notes and their images; a note that has no board any more is permanently deleted by the daily cleanup.

Korkwand registers a personal data exporter and eraser with the WordPress privacy tools (*Tools > Export Personal Data* and *Erase Personal Data*). Notes and images of a user are included in the export and are deleted on erasure. If you enable the email notification of a board, an email with the note title and the author's display name is sent to the site administrator address.

== Changelog ==

= 0.2.0 =
* Posting restricted to the Board editor role, editors and administrators; boards always expire.
* Deleting a board deletes its notes and images; a daily job also removes notes that belong to no board.
* Author archives answer 404 for every user without published public content, and ?author=ID no longer reveals user names.
* The Board editor role name follows the site language.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
