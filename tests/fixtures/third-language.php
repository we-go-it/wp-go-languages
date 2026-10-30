<?php

/**
 * A third language, German, for one test's process: loaded by wp-cli before
 * WordPress (`// @require fixtures/third-language.php`), so the plugin reads
 * it with its settings, as a must-use plugin's filter would be.
 *
 * The plugin hands each language its `wgl_language` term on `init`
 * (ensure_language_terms()), before bootstrap.php's transaction begins: a
 * `de` term made there would be committed and outlive the test. So that
 * step is taken off for this process, and the term is made the first time a
 * post is given German (language_term_id()), inside the transaction.
 */

WP_CLI::add_wp_hook('wp_go_languages/config', function ($config) {
    $config['languages'][] = ['code' => 'de', 'locale' => 'de_DE', 'name' => 'Deutsch', 'slug' => 'de'];

    return $config;
}, 1);

// at init 0, before the plugin's own at 1
WP_CLI::add_wp_hook('init', function () {
    remove_action('init', 'WpGo\\Languages\\Core\\ensure_language_terms', 1);
}, 0);
