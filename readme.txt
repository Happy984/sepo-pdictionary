=== Sepo Dictionary ===
Contributors: euneolink
Tags: dictionary, glossary, community, voting, words
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Community dictionaries: members suggest words and corrections, the community votes, and every accepted change is kept in the entry's history.

== Description ==

* Every entry has its own page at /dictionary/<word>/ with the headword, pronunciation, a Listen button that plays a recorded pronunciation (or the browser's own speech when there is none), part of speech, numbered meanings with examples, synonyms, antonyms, "see also" links, origin, usage notes, source, tags and contributors.
* The dictionary page has search with live suggestions, an A to Z, language and category filters, a word of the day, and pages of results.
* Several languages in one dictionary, or one dictionary per language with [sepo_dictionary language="bemba"].

= Several dictionaries =

* Add as many dictionaries as you need under Dictionary → Dictionaries. Each has its own page at /dictionary/<dictionary>/ and its own words at /dictionary/<dictionary>/<word>/, so the same word can be in two dictionaries.
* With two or more, the dictionary page lists them and searches across all of them; with one, it works as before.
* Each dictionary can change any site setting for itself: accent colour, words per page, word of the day, parts of speech, whether suggestions are open, the voting rules and who is emailed. Anything it leaves alone follows Dictionary → Settings.
* Name editors for one dictionary: they add and change its words, record pronunciations, settle its suggestions and restore its history, from its pages on the site, without rights anywhere else.
* Earlier addresses keep working: /dictionary/<word>/ from before 1.3.0, a dictionary's old slug after a rename, and a word that moved to another dictionary all redirect.

= Community review =

* Signed-in members suggest a new word, a new meaning, a better wording of a meaning, the removal of a wrong meaning, or a correction to the other details.
* Other members vote "accurate" or "not accurate", with an optional note. Authors cannot vote on their own suggestions.
* A suggestion is accepted when enough members find it accurate and it is far enough ahead (both set in Settings). It is declined when it falls too far behind.
* Suggestions for the same thing compete: two meanings for the same new word, or two rewrites of the same meaning. Only the leader can win; the others are superseded, and a new word that lost can be put forward again as an extra meaning.
* When voting closes, the leader is accepted if more members found it accurate than not; the rest close.
* If the entry changed after a suggestion was made, a winning suggestion goes to an editor instead of overwriting the newer text. Settings can also send every winning suggestion to an editor first.
* Editors can accept, decline or delete any suggestion, on the site or under Dictionary → Suggestions.

= History =

* Every change is recorded: entries created, suggestions merged (with the votes), editor changes, imports, trash and restore.
* The History tab shows each change with the words added and removed. Editors can restore any earlier version; the restore is itself recorded.

= Pronunciation recordings =

* Editors record the word with the microphone, right on the entry page or in the dashboard editor, or upload a sound file (MP3, M4A, OGG, WAV or WebM).
* Recordings are kept in the media library. Adding, replacing or removing one is recorded in the entry history, with a player for each version.

= More =

* Shortcodes: [sepo_dictionary], [sepo_word term="…"], [sepo_word_of_day], and [sepo_define term="…"]text[/sepo_define] for a hover definition in any post. Each takes dictionary="slug" to use one dictionary.
* Blocks and Elementor widgets for the dictionary, one word and the word of the day.
* Import and export as JSON or CSV.
* Email to members when their suggestion is settled, and to editors when one needs them.
* Schema.org DefinedTerm data on entry pages.
* WordPress personal data export and erase.
* Dark mode follows the theme. No outside services.

== Screenshots ==

1. The dictionary home with search and recent words.
2. A word entry with its meanings and history.

== Changelog ==

= 1.3.4 =
* The plugin is by Euneolink Solutions. Its plugin page and author page are edu.sepodesk.com.

= 1.3.3 =
* Readme: screenshots and their captions for the plugin directory.

= 1.3.2 =
* Admin screens: settings shown as cards, lists in a rounded frame, rounded fields and buttons, pill-shaped tabs and filters, and notices on tinted backgrounds.

= 1.3.1 =
* Imports of JSON lists no longer depend on array_is_list().
* Export and import read their options as sanitised values.
* Passes the WordPress Plugin Check: translator comments on every string with placeholders, escaped output, sanitised request data and documented database access to the plugin's own tables.
* Tested up to WordPress 7.1.

= 1.3.0 =
* New: several dictionaries on one site, managed under Dictionary → Dictionaries. Existing words are moved into a first dictionary, "Main dictionary", which you can rename.
* New: addresses are /dictionary/<dictionary>/ and /dictionary/<dictionary>/<word>/. A word's address only has to be unique within its dictionary. Old addresses redirect.
* New: each dictionary can change the site settings for itself, including the voting rules and accent colour, and can have editors of its own.
* New: the dictionary page lists the dictionaries and searches all of them when there are two or more; community review, My suggestions and Contributors cover all dictionaries there and one dictionary on its own page.
* New: a dictionary option for the shortcodes, blocks and Elementor widgets; a dictionary filter on the words list, the suggestions screen and export; a dictionary column in CSV import and export.

= 1.2.0 =
* The plugin's classes, colour tokens and data attributes now use the sdict- prefix, so themes that use sd- class names (such as the SepoDesk theme) no longer restyle or hide parts of the dictionary.
* Dark mode: brighter text, headwords and meanings, lighter labels and hints, and light-on-dark filled buttons, for readable contrast.
* Theme form, label and heading styles no longer change the dictionary's forms.

= 1.1.2 =
* Form error messages are added only when needed, and word suggestion lists sit outside the form, so nothing hidden takes a place in the form layout under any theme.

= 1.1.1 =
* The suggestion and editor forms keep compact rows when a theme gives forms a set height or a flex layout.

= 1.1.0 =
* Pronunciation recordings: record with the microphone or upload a sound file, from the entry page or the dashboard. The Listen button plays the recording.
* Steadier spacing in the suggestion and editor forms under themes that space paragraphs, labels and inputs.

= 1.0.0 =
* First release.
