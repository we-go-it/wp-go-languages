<?php

/**
 * Plugin Name: WP-Go Languages
 * Description: The site in several languages: a language per page, translations linked to their original, /en/ URLs, independent and fixed translations, hreflang and the sitemap's alternates, site texts and admin strings.
 * Version: 1.9.0
 * Requires PHP: 8.1
 * Author: we-go
 * Text Domain: wp-go-languages
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_LANGUAGES_FILE = __FILE__;

// WPML active: it gives the languages, and two plugins can't both route the
// URLs. Nothing is loaded, so nothing (`WpGoLanguages` included) is defined,
// and code asking for the languages (WP-Go's includes/i18n.php) reads WPML's.
// WPML loads first: plugins load by folder name, and `sitepress-…` sorts
// before `wp-go-…`.
if (defined('ICL_SITEPRESS_VERSION')) {
    add_action('admin_notices', function () {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html_x('WP-Go Languages is off while WPML is active: WPML gives the site its languages. Deactivate one of the two.', 'wp-go-languages.php', 'wp-go-languages')
                . '</p></div>';
        }
    });

    // The move onto WPML runs here, in WPML (`wp languages migrate
    // to-wpml`): this plugin's data read as stored, with only its settings
    // and storage's names loaded.
    if (defined('WP_CLI') && WP_CLI) {
        foreach (['src/Core/config.php', 'src/Core/storage.php', 'src/Migrate/shared.php', 'src/Migrate/to-wpml.php', 'src/Cli/migrate.php'] as $file) {
            require_once __DIR__ . '/' . $file;
        }

        \WP_CLI::add_command('languages migrate', \WpGo\Languages\Cli\MigrateCommand::class);
    }

    return;
}

// No Composer autoload: the plugin is installed by Composer, but runs from its
// own folder, as WordPress loads it, so it requires its own files.
foreach ([
    'src/Core/config.php',
    'src/Core/assets.php',
    'src/Core/upgrade.php',
    'src/Core/state.php',
    'src/Core/storage.php',
    'src/Core/request.php',
    'src/Core/links.php',
    'src/Core/query.php',
    'src/Core/translate.php',
    'src/Core/integrations.php',
    'src/Core/menus.php',
    'src/Core/seo.php',
    'src/Core/policies.php',
    'src/Core/cf7.php',
    'src/Core/fixed.php',
    'src/Core/ai.php',
    'src/Core/ai-fields.php',
    'src/Core/link-warnings.php',
    'src/Core/synced-links.php',
    'src/Core/starting-content.php',
    'src/Core/synced.php',
    'src/SiteTexts/site-texts.php',
    'src/AdminStrings/admin-strings.php',
    'src/WpGoLanguages.php',
    'src/boot.php',
] as $file) {
    require_once __DIR__ . '/' . $file;
}

\WpGo\Languages\boot();
