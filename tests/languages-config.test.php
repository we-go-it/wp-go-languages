<?php

// @require fixtures/third-language.php

/**
 * Languages set in code: a `wp_go_languages/config` filter there before the
 * plugin loads (a must-use plugin's, here a third language) applies and
 * stays; one added later is taken off on wp_loaded, so a save's config(true)
 * never applies it halfway.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;

$home = untrailingslashit((string) get_option('home'));

same(['it', 'en', 'de'], Core\codes(), 'a language added in code before the plugin loads is one of the site\'s');
same("$home/de/", WpGoLanguages::home_url('de'), 'with its own segment');
same('de', WpGoLanguages::url_language("$home/de/seite/"), 'and its addresses');
same('de', Core\language_by_locale('de_DE'), 'and its locale');

check('the filter of the first read is kept on wp_go_languages/config', (bool) Core\config_callbacks());

$late = function ($config) {
    $config['languages'][] = ['code' => 'fr', 'locale' => 'fr_FR', 'name' => 'Français'];

    return $config;
};
add_filter('wp_go_languages/config', $late);
add_filter('doing_it_wrong_trigger_error', '__return_false');
Core\drop_late_filters();
remove_filter('doing_it_wrong_trigger_error', '__return_false');

check('the late filter is taken off', has_filter('wp_go_languages/config', $late) === false);
check('the first one is still there', has_filter('wp_go_languages/config') !== false);
same(['it', 'en', 'de'], array_column(Core\config(true)['languages'], 'code'), 'read again, as after a save: the code\'s language, not the late filter\'s');
same(['it', 'en'], array_column(get_option(Core\OPTION)['languages'], 'code'), 'the stored settings don\'t have it: only the code does');
