# Sepo Dictionary

One or more community dictionaries for WordPress. Members suggest words, meanings and corrections, and the community votes on how accurate they are. Suggestions that win the vote are merged into the entry, and every change is kept in the entry's history.

<p align="center">
  <a href="https://edu.sepodesk.com/sponsor/projects-maintenance/">
    <img src="https://img.shields.io/badge/%E2%9D%A4%20SUPPORT%20OUR%20OPEN--SOURCE%20PROJECTS-0b6b45?style=for-the-badge&logo=githubsponsors&logoColor=white" alt="Support Our Open-Source Projects" />
  </a>
</p>

<p align="center">
  Your support helps us maintain, improve and build open-source software for everyone.
  <br />
  <strong>Every contribution makes a difference. Thank you for supporting our work!</strong>
</p>

![Version](https://img.shields.io/badge/version-1.3.0-0b3d33)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

It runs on WordPress alone. It needs no outside service and no other plugin.

## Contents

- [Features](#features)
- [Several dictionaries](#several-dictionaries)
- [Requirements](#requirements)
- [Installation](#installation)
- [Getting started](#getting-started)
- [Shortcodes, blocks and widgets](#shortcodes-blocks-and-widgets)
- [Uninstalling](#uninstalling)
- [Privacy](#privacy)
- [Changelog](#changelog)
- [License](#license)

## Features

### Entries

- Every entry has its own page at `/dictionary/<word>/`. The page shows:
  - the headword and pronunciation, with a **Listen** button;
  - the part of speech and numbered meanings with examples;
  - synonyms, antonyms and "see also" links;
  - origin, usage notes, source, tags and contributors.
- The Listen button plays a recorded pronunciation. When there is no recording, it uses the browser's own speech.
- The dictionary page has:
  - search with live suggestions;
  - an A to Z index;
  - language and category filters;
  - a word of the day;
  - results split into pages.
- Several languages can share one dictionary. You can also show one dictionary per language, for example with `[sepo_dictionary language="bemba"]`.

### Several dictionaries

Run as many dictionaries as you need on one site, for example one per language or one per subject.

- **Addresses.** Each dictionary has its own page at `/dictionary/<dictionary>/`, and its words live at `/dictionary/<dictionary>/<word>/`. The same word can be in two dictionaries.
- **The dictionary page.**
  - With two or more dictionaries, it lists them as cards and searches across all of them.
  - With one dictionary, it shows that dictionary, as before.
- **Settings per dictionary.** Each dictionary can change any of these for itself. Anything it leaves alone follows **Dictionary → Settings**.
  - accent colour, words per page, word of the day and parts of speech;
  - whether members can suggest words;
  - the voting rules: votes needed, lead needed, when to decline, voting period, editor check and open suggestions per member;
  - which address is emailed, and whether editors hear about every new suggestion.
- **Editors per dictionary.** Name members who look after one dictionary. They add and change its words, record pronunciations, settle its suggestions and restore its history, from its pages on the site. They get no rights anywhere else. The site's editors can always manage every dictionary.
- **Old links keep working.** These all redirect to the current address:
  - addresses from before version 1.3.0 (`/dictionary/<word>/`);
  - a dictionary's old slug after a rename;
  - a word that moved to another dictionary.
- **Deleting a dictionary** moves its words into the default dictionary. The default dictionary cannot be deleted.

### Community review

- Signed-in members can suggest:
  - a new word or a new meaning;
  - better wording for a meaning;
  - the removal of a wrong meaning;
  - a correction to the other details.
- Other members vote **accurate** or **not accurate**, with an optional note. Members cannot vote on their own suggestions.
- You set two thresholds in Settings: how many members must find a suggestion accurate, and how far ahead it must be. A suggestion that meets both is accepted. One that falls too far behind is declined.
- Suggestions for the same thing compete. Examples are two meanings for the same new word, or two rewrites of the same meaning.
  - Only the leader can win. The others are superseded.
  - A new word that lost can be put forward again as an extra meaning.
- When voting closes, the leader is accepted if more members found it accurate than not. The rest close.
- If the entry changed after a suggestion was made, a winning suggestion goes to an editor rather than overwriting the newer text. You can also send every winning suggestion to an editor first.
- Editors can accept, decline or delete any suggestion. They can do this on the site or under **Dictionary → Suggestions**.

### History

- Every change is recorded:
  - entries created;
  - suggestions merged, with their votes;
  - editor changes and imports;
  - entries moved to the trash and restored.
- The **History** tab shows each change with the words added and removed.
- Editors can restore any earlier version. The restore is recorded too.

### Pronunciation recordings

- Editors can record a word with the microphone, on the entry page or in the dashboard editor.
- They can also upload a sound file: MP3, M4A, OGG, WAV or WebM.
- Recordings are kept in the media library.
- Adding, replacing or removing a recording is recorded in the entry history, with a player for each version.

### More

- Import and export as JSON or CSV.
- Emails to members when their suggestion is settled, and to editors when a suggestion needs them.
- Schema.org `DefinedTerm` data on entry pages.
- WordPress personal data export and erase.
- Dark mode follows the theme.

## Requirements

|           | Minimum |
| --------- | ------- |
| WordPress | 6.0     |
| PHP       | 8.1     |

## Installation

### Upload the zip (recommended)

1. Download `sepo-dictionary.zip`.
2. In your WordPress dashboard, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the zip file and click **Install Now**.
4. Click **Activate**.

### Upload the folder

1. Unzip the download.
2. Copy the `sepo-dictionary` folder into `wp-content/plugins/` on your server, using FTP or your host's file manager.
3. Go to **Plugins** in the dashboard and activate **Sepo Dictionary**.

> [!NOTE]
> If you downloaded the repository with GitHub's **Code → Download ZIP**, the folder inside is named after the branch, such as `sepo-dictionary-main`. Rename it to `sepo-dictionary` before uploading. That keeps updates and settings tied to the right folder.

## Getting started

1. Open **Dictionary → Dictionaries**. Your first dictionary, **Main dictionary**, is already there: rename it, or add more.
2. Open **Dictionary → Settings**. Set the voting thresholds, the voting period and whether winning suggestions go to an editor first.
3. Add your first entries under **Dictionary → Add word**, or bring in a list under **Dictionary → Import and export**. Choose the dictionary for each word.
4. Visit `/dictionary/` to see the dictionary page. Signed-in members can now suggest and vote.

## Shortcodes, blocks and widgets

| Shortcode                                  | What it shows                                                    |
| ------------------------------------------ | ---------------------------------------------------------------- |
| `[sepo_dictionary]`                        | The full dictionary. Add `language="slug"` to show one language. |
| `[sepo_word term="…"]`                     | One entry.                                                       |
| `[sepo_word_of_day]`                       | The word of the day.                                             |
| `[sepo_define term="…"]text[/sepo_define]` | The text, with the word's definition shown on hover.             |

Each shortcode also takes `dictionary="slug"`. On `[sepo_dictionary]` it shows that one dictionary. On the others it looks the word up in that dictionary first.

Blocks and Elementor widgets are included for the dictionary, one word and the word of the day. With two or more dictionaries, each has a **Dictionary** option.

## Uninstalling

**Deactivating** the plugin (**Plugins → Deactivate**) stops it and keeps all your data. Activate it again and everything is back.

**Deleting** the plugin (**Plugins → Deactivate**, then **Delete**) removes the plugin files. What happens to your data depends on one setting:

- **By default your data is kept.** Entries, suggestions, votes and history stay in the database, so you can reinstall later without losing anything.
- **To remove everything**, first tick **Delete all entries, suggestions, votes and history** under **Dictionary → Settings** and save, then delete the plugin. This removes:
  - every dictionary and every entry, with its languages, categories and tags;
  - the suggestion, vote and history tables;
  - the plugin's settings and cached data.

Pronunciation recordings stay in your media library either way.

## Privacy

Suggestions, votes and history entries are tied to the member who made them. They are included in WordPress's **Tools → Export Personal Data** and **Tools → Erase Personal Data**.

## Changelog

### 1.3.0

- Several dictionaries on one site, managed under **Dictionary → Dictionaries**. Existing words move into a first dictionary, **Main dictionary**, which you can rename.
- New addresses: `/dictionary/<dictionary>/` and `/dictionary/<dictionary>/<word>/`. A word's address only has to be unique within its dictionary. Old addresses redirect.
- Each dictionary can change the site settings for itself, including the voting rules and accent colour, and can have editors of its own.
- With two or more dictionaries, the dictionary page lists them and searches all of them.
  - There, community review, My suggestions and Contributors cover every dictionary.
  - On a dictionary's own page, they cover that dictionary.
- A dictionary option for the shortcodes, blocks and Elementor widgets.
- A dictionary filter on the words list, the suggestions screen and export.
- A `dictionary` column in CSV import and export.

### 1.2.0

- The plugin's classes, colour tokens and data attributes use the `sdict-` prefix. Themes that style `sd-` class names no longer restyle or hide parts of the dictionary.
- Dark mode has brighter text, headwords and meanings, lighter labels and hints, and light-on-dark filled buttons, for readable contrast.
- Theme form, label and heading styles no longer change the dictionary's forms.

### 1.1.2

- Form error messages are added only when needed. Word suggestion lists sit outside the form, so nothing hidden takes up space in the form layout under any theme.

### 1.1.1

- The suggestion and editor forms keep compact rows when a theme gives forms a fixed height or a flex layout.

### 1.1.0

- Pronunciation recordings: record with the microphone or upload a sound file, from the entry page or the dashboard. The Listen button plays the recording.
- Steadier spacing in the suggestion and editor forms under themes that space paragraphs, labels and inputs.

### 1.0.0

- First release.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)
