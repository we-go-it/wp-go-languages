# WP-Go Languages

A site in several languages, in place of WPML on new sites: a language per post, translations
linked in groups, `/en/…` URLs, duplicate and fixed translations, hreflang and Yoast's sitemap
alternates, site texts and the admin's own strings. Composer (`we-go/wp-go-languages`), installed
as a plugin at `web/app/plugins/wp-go-languages` (a symlink to this folder).

Its version is in `composer.json` and the plugin header, kept the same: bump both, then
`composer update we-go/wp-go-languages`.

Requires PHP 8.1. Works with ACF Pro 6, Yoast SEO and Contact Form 7 when they're active.

The docs are in ../wp-docs (**Docs** in the admin menu, or `yarn docs:dev`), under **WP-Go
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
| `src/SiteTexts/` | Site texts, a copy of WP-Go's feature with domains: keep the two behaving alike |
| `src/AdminStrings/` | The admin strings: scan, translations, the tab |
| `src/Cli/command.php` | `wp languages` |
| `src/Cli/migrate.php`, `src/Migrate/` | `wp languages migrate`: the moves from WPML and to it |
| `build.js`, `package.json` | The build: the scripts and stylesheets beside the PHP (`src/**/*.js`, `*.css`) into `dist/`, committed. `yarn build`, `yarn watch` |

Admin text is `_x('Text', '<file path>', 'wp-go-languages')`, as in WP-Go.
