<?php

/**
 * Admin strings: the admin's own words, translated without .po files.
 *
 * The theme and our plugins write what only the admin shows in English,
 * with gettext and a context: `_x('Save', 'includes/settings.php', 'wp-go')`.
 * On a site on WPML they're translated in String Translation (the theme's
 * `wp base strings` puts them there). On a site on this plugin they're
 * translated here, on the Translation strings screen's Admin strings tab, for
 * administrators.
 *
 * In short:
 *
 *   - Scan code reads the code for its strings, the domains and folders
 *     listed with `wp_go_languages/admin_strings` (this plugin lists its
 *     own; the theme lists its own and WP-Go's). New strings are added;
 *     strings the code no longer has go, translations with them
 *   - each string is translated per site language, into that language's
 *     locale: an Italian admin (`it_IT`) shows the `it_IT` translations
 *   - the translations are compiled into WordPress's own .l10n.php files,
 *     a domain and a locale each, and loaded with load_textdomain(): no
 *     query and no filter per string. A .mo file of the domain's own wins
 *   - where a file can't be written, a filter on
 *     `gettext_with_context_{domain}` reads the option instead
 *   - saving says what changed with `wp_go_languages/strings_saved`, which
 *     Cache Tags listens to, as it did to String Translation
 *   - `wp languages strings scan` does Scan code from the command line, and
 *     `wp base strings` hands over to it on a site on this plugin
 *
 * Where things are:
 *
 *   registry.php      which strings there are: read from the code, kept
 *   translations.php  their translations: the option, the files, the fallback filter
 *   actions.php       the tab's forms: save, Scan code
 *   screen.php        the tab, on the Site texts screen
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/translations.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/screen.php';

// The translations' files, for the request's locale, and the one switched to.
// At 0: before the theme's own setup prints anything.
add_action('after_setup_theme', 'wp_go_admin_strings_load', 0);
add_action('change_locale', 'wp_go_admin_strings_load');

// loaded after the theme's setup began (a test, a late include): now
if (did_action('after_setup_theme')) {
    wp_go_admin_strings_load();
}
