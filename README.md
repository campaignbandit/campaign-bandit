# Campaign Bandit

Conversion campaigns — popups and inline forms — built in the WordPress block editor,
with every lead stored in your own WordPress.

This repository holds the **free plugin**, exactly as it is published to the
[WordPress.org plugin directory](https://wordpress.org/plugins/campaign-bandit/). Each
commit is one release.

## What is here

| Path | What it is |
|---|---|
| `src/` | The PHP, autoloaded by namespace |
| `js/` | The JavaScript as it was written |
| `build/` | The same JavaScript, compiled and minified — what actually runs |
| `patterns/` | The starter patterns |
| `assets/` | Admin styles and scripts |
| `readme.txt` | The directory listing |

`build/` is generated from `js/`. Both ship so that every line of the plugin can be
read as it was written.

## Reporting something

Issues and pull requests are welcome here. The plugin is developed in a private
repository alongside its paid add-on, so changes are brought across by hand rather than
merged directly — but they are read, and credited.

## Licence

GPL-2.0-or-later. See the headers in `campaign-bandit.php`.

The paid add-on, Campaign Bandit Pro, is a separate plugin and is not in this
repository. Nothing here is a trial, nothing expires, and no feature is locked behind a
key.
