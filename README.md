# WP-Go Languages

A site in several languages, in place of WPML on new sites: a language per post, translations
linked in groups, `/en/…` URLs, independent and fixed translations, hreflang and Yoast's sitemap
alternates, site texts and the admin's own strings. Composer (`we-go/wp-go-languages`), installed
as a plugin at `web/app/plugins/wp-go-languages`, from GitHub at a tagged version.

Its version is in `composer.json` and the plugin header, kept the same: bump both, add the
version's entry to `CHANGELOG.md`, tag it (`v1.10.1`) and push, then `composer update
we-go/wp-go-languages` in the project.

Requires PHP 8.1. Works with ACF Pro 6, Yoast SEO and Contact Form 7 when they're active.

The docs are in ../wp-docs (`yarn dev` there), under **WP-Go
Languages**: its Overview, Setup and Updating, then its parts side by side (Languages and URLs,
Translations, Fixed translations, SEO, Contact Form 7, Site texts, Admin strings, Admin screens,
AI translation), each with Usage, API and Internals. What it relies on in WordPress and the plugins is in
[UPDATING.md](UPDATING.md), by part.

## WPML

It **stands down when WPML is active** (`ICL_SITEPRESS_VERSION`), with a notice: a site runs on
one or the other. WP-Go, the theme and wp-go-blocks work with either, through WP-Go's
`includes/i18n.php` and wp-go-blocks' `Core\I18n`.

A site moves between the two, either way, with `wp languages migrate from-wpml` (WPML
deactivated) and `wp languages migrate to-wpml` (WPML active: the plugin keeps only that
command). The docs' **Migrating** page.

## A large site

Measured at 10,000 pages in 5 languages (50,000 posts, 1.2M meta rows):

- a page renders in 80-125 ms, with the plugin's part a few queries;
- what asks for many items' translations reads them at once (`Core\prime_translations()`): a followed
  menu, a list, a sitemap, the link dialogs;
- a new default language goes a batch of groups at a time (`Core\make_post_originals()`), in seconds.
  Stopped halfway it's said in an option, and the next request that may finishes it
  (`Core\run_conversions()`, an administrator's or a cron run's, never a visitor's);
- past 1,000 pages, a list's Quick Edit doesn't list parents (`wp_go_languages/max_parent_dropdown`).

What stays slow isn't the plugin's: a search of every type goes through every page's content (use
Relevanssi or similar past a few thousand), core reads all a hierarchical type's IDs for its list, and
Yoast's sitemaps sort the type's pages for each batch. Time things with `WP_ENV=production`: outside it,
Yoast rebuilds its author data on every call. After a move from WPML, `wp yoast index --reindex`.

## Tests

`tests/`: a `*.test.php` per area, each run in the project's WordPress inside a database
transaction rolled back at its end. `composer test:wp-go-languages` at the
project's root, or `php ../wp-go-languages/tests/run.php <filter>`. How to write one: the docs'
**Testing** page (`../wp-docs/guide/testing.md`).

## Files

| | |
|---|---|
| `wp-go-languages.php` | The plugin header; loads the files below and calls `boot()` |
| `src/boot.php` | Every hook |
| `src/WpGoLanguages.php` | The facade other code calls |
| `src/Core/` | Settings, the current language, storage, the request, links, queries, translating, field rules, fixed translations, menus, CF7, SEO, AI translation (`ai.php`, `ai-fields.php`), its links' warnings (`link-warnings.php`), what a synced field points at as a page renders (`synced-links.php`), what a new one starts with (`starting-content.php`), what the stored data needs when it changes shape (`upgrade.php`) |
| `src/Admin/` | The Languages screen, list columns, the admin bar, the editor panel, the Menus screen's panel and a fixed translation's editor |
| `src/SiteTexts/` | Site texts, a copy of WP-Go's feature with domains and a screen a language at a time: keep the rest behaving alike |
| `src/AdminStrings/` | The admin strings: scan, translations, the tab |
| `src/Cli/command.php` | `wp languages` |
| `src/Cli/migrate.php`, `src/Migrate/` | `wp languages migrate`: the moves from WPML and to it |
| `build.js`, `package.json` | The build: the scripts and stylesheets beside the PHP (`src/**/*.js`, `*.css`) into `dist/`, committed. `yarn build`, `yarn watch` |

Admin text is `_x('Text', '<file path>', 'wp-go-languages')`, as in WP-Go.
