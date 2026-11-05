<?php

namespace WpGo\Languages;

use WpGo\Languages\Core;

// `WpGoLanguages::current()` anywhere, with no `use`, as WpGoBlocks is.
if (!class_exists('WpGoLanguages', false)) {
    class_alias(WpGoLanguages::class, 'WpGoLanguages');
}

/**
 * Hook the plugin up. Called once, from the plugin's main file, as WordPress
 * loads plugins: the request's language is read right away (detect()), before
 * any text domain or URL depends on it.
 */
function boot(): void
{
    static $booted = false;

    if ($booted) {
        return;
    }

    Core\upgrade();

    $booted = true;

    Core\detect();

    // Settings: a config filter added too late is taken off.
    add_action('wp_loaded', 'WpGo\\Languages\\Core\\drop_late_filters', 0);

    // Storage.
    add_action('init', 'WpGo\\Languages\\Core\\register_taxonomies', 0);
    add_action('init', 'WpGo\\Languages\\Core\\ensure_language_terms', 1);
    add_action('init', 'WpGo\\Languages\\Core\\resolve_admin', 1);
    add_action('wp_insert_post', 'WpGo\\Languages\\Core\\assign_language', 0, 2);
    add_action('created_term', 'WpGo\\Languages\\Core\\assign_term_language', 0, 3);
    // a deleted original's group gets a new one first, then loses it
    add_action('before_delete_post', 'WpGo\\Languages\\Core\\replace_deleted_original', 5);
    add_action('before_delete_post', 'WpGo\\Languages\\Core\\forget_deleted_post');

    // URLs and routing.
    add_filter('home_url', 'WpGo\\Languages\\Core\\filter_home_url', 10, 3);
    add_filter('page_link', 'WpGo\\Languages\\Core\\filter_post_link', 20, 2);
    add_filter('post_link', 'WpGo\\Languages\\Core\\filter_post_link', 20, 2);
    add_filter('post_type_link', 'WpGo\\Languages\\Core\\filter_post_link', 20, 2);
    add_filter('term_link', 'WpGo\\Languages\\Core\\filter_term_link', 20, 2);
    add_filter('option_page_on_front', 'WpGo\\Languages\\Core\\front_page_option');
    add_filter('option_page_for_posts', 'WpGo\\Languages\\Core\\front_page_option');
    add_filter('wp_unique_post_slug', 'WpGo\\Languages\\Core\\unique_post_slug', 10, 6);
    add_filter('url_to_postid', 'WpGo\\Languages\\Core\\filter_url_to_postid');
    // before redirect_canonical() (10), which would redirect within the root
    add_action('template_redirect', 'WpGo\\Languages\\Core\\redirect_unprefixed', 0);
    add_action('template_redirect', 'WpGo\\Languages\\Core\\hide_language', 1);

    // Queries.
    add_action('pre_get_posts', 'WpGo\\Languages\\Core\\resolve_pagename', 0);
    add_filter('posts_where', 'WpGo\\Languages\\Core\\posts_where', 10, 2);
    add_filter('wp_count_posts', 'WpGo\\Languages\\Core\\count_posts', 10, 3);
    add_filter('get_pages_query_args', 'WpGo\\Languages\\Core\\get_pages_args', 10, 2);
    add_filter('terms_clauses', 'WpGo\\Languages\\Core\\terms_clauses', 10, 3);

    // Locale, menus, ACF.
    add_filter('locale', 'WpGo\\Languages\\Core\\filter_locale');
    add_filter('theme_mod_nav_menu_locations', 'WpGo\\Languages\\Core\\filter_menu_locations');
    add_filter('wp_get_nav_menu_items', 'WpGo\\Languages\\Core\\followed_menu_items', 10, 3);
    // a term or a menu deleted hands its group to another
    add_action('pre_delete_term', function ($term_id) {
        if (Core\is_translatable((int) $term_id, 'term')) {
            Core\leave_group((int) $term_id, 'term');
        }
    });
    add_action('acf/init', 'WpGo\\Languages\\Core\\sync_acf', 0);
    // late: ACF's fields and the theme's rules are there
    add_action('init', 'WpGo\\Languages\\Core\\upgrade_menus', 98);
    add_action('init', 'WpGo\\Languages\\Core\\sync_stored_default', 99);

    // What a translation copies, and when.
    add_action('wp_after_insert_post', 'WpGo\\Languages\\Core\\sync_translations', 20, 2);
    // a term's, once ACF has saved its fields (on edited_term)
    add_action('saved_term', fn ($term_id) => Core\sync_term_translations((int) $term_id), 20);
    add_filter('acf/prepare_field', 'WpGo\\Languages\\Core\\lock_copied_field', 20);
    // before the field type's (10): the stored value is swapped
    add_filter('acf/load_value', 'WpGo\\Languages\\Core\\load_copied_option', 5, 3);
    // and listed where the language has no row for them (get_fields())
    add_filter('acf/load_meta', 'WpGo\\Languages\\Core\\list_copied_options', 10, 2);
    add_filter('acf/load_reference', 'WpGo\\Languages\\Core\\copied_option_reference', 10, 3);

    // A page or a term translated after those that hang on it: they reconnect.
    add_action('wp_go_languages/translation_created', 'WpGo\\Languages\\Core\\reconnect_children', 10, 3);
    add_action('wp_go_languages/term_translation_created', 'WpGo\\Languages\\Core\\reconnect_term', 10, 3);

    // Fixed translations: every block's ID, the structure the original's.
    add_filter('wp_insert_post_data', 'WpGo\\Languages\\Core\\prepare_content', 20, 2);
    add_action('post_updated', 'WpGo\\Languages\\Core\\remember_previous', 10, 3);
    add_action('wp_after_insert_post', 'WpGo\\Languages\\Core\\sync_fixed', 30, 2);

    // Synced fields: an options page's once ACF has written it, and those
    // whose link's target has just come or gone.
    add_action('acf/save_post', 'WpGo\\Languages\\Core\\sync_synced_options', 20);
    add_action('wp_after_insert_post', 'WpGo\\Languages\\Core\\resync_language', 40, 4);
    add_action('pre_post_update', 'WpGo\\Languages\\Core\\remember_found_thumbnail');

    // Contact Form 7, a form per language.
    add_action('wp_go_languages/translation_created', 'WpGo\\Languages\\Core\\cf7_translation_created', 10, 3);
    add_filter('shortcode_atts_wpcf7', 'WpGo\\Languages\\Core\\cf7_shortcode_atts');
    // its picker, in the language being edited, keeps the form it stores
    add_filter('acf/prepare_field/type=acf_cf7', 'WpGo\\Languages\\Core\\cf7_picker_value');
    add_action('acf/render_field/type=acf_cf7', 'WpGo\\Languages\\Core\\cf7_picker_list_start', 8);
    add_action('acf/render_field/type=acf_cf7', 'WpGo\\Languages\\Core\\cf7_picker_list_end', 10);
    // a fixed translation follows its original's mails (after they're written)
    add_action('wpcf7_after_save', 'WpGo\\Languages\\Core\\cf7_saved', 20);

    // hreflang, and Yoast's sitemap.
    add_action('wp_head', 'WpGo\\Languages\\Core\\print_hreflang', 2);
    add_filter('wpseo_sitemap_urlset', 'WpGo\\Languages\\Core\\sitemap_urlset');
    add_filter('wpseo_sitemap_entry', 'WpGo\\Languages\\Core\\sitemap_entry', 10, 3);
    add_filter('wpseo_sitemap_url', 'WpGo\\Languages\\Core\\sitemap_url', 10, 2);
    // a post's URL or translations changed without a save (terms aside)
    add_action('wp_go_languages/language_set', fn ($id) => Core\refresh_seo((int) $id), 20);
    add_action('wp_go_languages/linked', fn ($id, $original, $kind) => $kind === 'post' && Core\refresh_seo((int) $id), 20, 3);
    add_action('wp_go_languages/unlinked', fn ($id, $kind) => $kind === 'post' && Core\refresh_seo((int) $id), 20, 2);

    // The admin's hooks, on every request: the editor reads and writes its
    // Language panel through REST (`wgl`), which is not is_admin().
    require_once dirname(__DIR__) . '/src/Admin/settings.php';
    require_once dirname(__DIR__) . '/src/Admin/admin.php';
    require_once dirname(__DIR__) . '/src/Admin/fixed-editor.php';
    require_once dirname(__DIR__) . '/src/Admin/synced-fields.php';
    require_once dirname(__DIR__) . '/src/Admin/block-link.php';
    require_once dirname(__DIR__) . '/src/Admin/menus.php';
    require_once dirname(__DIR__) . '/src/Admin/terms.php';
    require_once dirname(__DIR__) . '/src/Admin/bulk.php';
    require_once dirname(__DIR__) . '/src/Admin/trash.php';
    require_once dirname(__DIR__) . '/src/Admin/ai.php';

    Admin\boot();
    Admin\terms_boot();
    Admin\bulk_boot();
    Admin\trash_boot();
    Admin\ai_boot();
    Admin\fixed_editor_boot();
    Admin\synced_fields_boot();
    Admin\block_link_boot();
    Admin\menus_boot();

    // The admin bar shows on the site too.
    require_once dirname(__DIR__) . '/src/Admin/admin-bar.php';
    add_action('admin_bar_menu', 'WpGo\\Languages\\Admin\\admin_bar', 80);

    if (defined('WP_CLI') && WP_CLI) {
        require_once dirname(__DIR__) . '/src/Cli/command.php';
        require_once dirname(__DIR__) . '/src/Migrate/shared.php';
        require_once dirname(__DIR__) . '/src/Migrate/from-wpml.php';
        require_once dirname(__DIR__) . '/src/Migrate/to-wpml.php';
        require_once dirname(__DIR__) . '/src/Cli/migrate.php';

        \WP_CLI::add_command('languages', Cli\Command::class);
        \WP_CLI::add_command('languages migrate', Cli\MigrateCommand::class);
    }
}
