<?php

namespace WpGo\Languages\Migrate;

use WpGo\Languages\Core;

/**
 * A site on this plugin moved onto WPML: `wp languages migrate to-wpml`, run
 * with WPML (and String Translation) active. This plugin stands down while
 * WPML is active: only its settings (config.php), storage's names
 * (storage.php) and this are loaded, and its data is read as stored:
 *
 *   the `wp_go_languages` option   the languages, the default, the URL
 *                                  setting, the translated types
 *   `wgl_language`, `wgl_group`    a post's language and group (taxonomies,
 *                                  read in the tables: nothing registers
 *                                  them now)
 *   `_wgl_source`                  a translation's original (post or term meta)
 *   `_wgl_language`, `_wgl_group`  a term's language and group (term meta)
 *   the theme's menu locations     `main` the base language's, `main___en`…
 *   `wp_go_languages_admin_translations`  the admin strings' translations
 *
 * What is written, through WPML's own functions:
 *
 *   - the languages: WPML's setup finished if it wasn't (the default, the
 *     active languages), else the active languages and the default set; each
 *     language's locale and URL segment (`display_code`) this plugin's; the
 *     language in the path, the default's directory as `default_in_url`;
 *     the translated post types and taxonomies made translatable;
 *   - each post's and term's language and group (`trid`), the original's row
 *     with no source; a group already as planned is left alone;
 *   - a post or term with no language here gets the default;
 *   - the menus: the menus of each location's languages one group, the
 *     location given the default language's (WPML finds the others), each
 *     menu's items in its language; a menu in no location the default's;
 *   - the admin strings, registered as `wp base strings` does (the domain,
 *     the context apart, named md5(text)), with their translations.
 *
 * A language must be one WPML knows by the same code (WPML → Languages →
 * Edit languages adds one): nothing is written until every one is.
 *
 * Fixed translations are plain translations in WPML: their content stays
 * as it is, and following the original stops. Their mode is left in their
 * meta, unread by WPML.
 */

/**
 * Whether synced fields hold values as their original has them: anything marked, with
 * `wp languages migrate synced-links --resolve` not run. Read as stored, as all here:
 * marks are in a translation's meta or its blocks' JSON.
 */
function synced_fields_unresolved(): bool
{
    global $wpdb;

    if (get_option('wp_go_languages_synced_resolved')) {
        return false;
    }

    return (bool) $wpdb->get_var("SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_wgl_synced' LIMIT 1")
        || (bool) $wpdb->get_var("SELECT 1 FROM {$wpdb->posts} WHERE post_content LIKE '%wgSynced%' AND post_type <> 'revision' LIMIT 1");
}

/**
 * What a move to WPML would do, read only.
 *
 * @return array{
 *     languages: list<array{code:string,locale:string,name:string,slug:string}>,
 *     default_in_url: bool,
 *     post_types: list<string>,
 *     taxonomies: list<string>,
 *     posts: list<array{type:string, original:int, items:array<string,int>}>,
 *     terms: list<array{type:string, original:int, items:array<string,int>}>,
 *     unassigned: array{post: list<int>, term: list<int>},
 *     menus: array{locations: array<string,int>, groups: list<array{type:string, original:int, items:array<string,int>}>},
 *     strings: array<string,array<string,array<string,string>>>,
 *     missing: list<string>,
 *     warnings: list<string>
 * }
 */
function plan_to_wpml(): array
{
    $config    = Core\config();
    $languages = $config['languages'];
    $codes     = array_column($languages, 'code');
    $default   = $codes[0];
    $warnings  = [];

    if (synced_fields_unresolved()) {
        $warnings[] = 'Synced fields hold their original\'s links, worked out as pages render, which stops under WPML: with WPML off, run `wp languages migrate synced-links --resolve` first, then come back.';
    }

    $post_types = array_values(array_filter($config['post_types'], 'post_type_exists'));
    $taxonomies = array_values(array_filter($config['taxonomies'], 'taxonomy_exists'));

    foreach (array_merge(array_diff($config['post_types'], $post_types), array_diff($config['taxonomies'], $taxonomies)) as $name) {
        $warnings[] = sprintf('%s is translated here but isn\'t registered now (its plugin off?): its items not moved.', $name);
    }

    return [
        'languages'      => $languages,
        'default_in_url' => $config['default_in_url'],
        'post_types'     => $post_types,
        'taxonomies'     => $taxonomies,
        'posts'          => our_groups('post', $post_types, $codes, $default, $warnings),
        'terms'          => our_groups('term', $taxonomies, $codes, $default, $warnings),
        'unassigned'     => [
            'post' => our_unassigned('post', $post_types),
            'term' => our_unassigned('term', $taxonomies),
        ],
        'menus'          => our_menus($codes, $default, $warnings),
        'strings'        => our_strings($languages),
        'missing'        => missing_in_wpml($codes),
        'warnings'       => $warnings,
    ];
}

/**
 * This plugin's groups of the given post types (or taxonomies), read in the
 * tables. An item alone is a group of one; a second item in a language of
 * its group is kept apart, with a warning.
 *
 * @param list<string> $types
 * @param list<string> $codes
 * @param list<string> $warnings
 * @return list<array{type:string, original:int, items:array<string,int>}>
 */
function our_groups(string $kind, array $types, array $codes, string $default, array &$warnings): array
{
    global $wpdb;

    if (!$types) {
        return [];
    }

    if ($kind === 'term') {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id AS id, tt.taxonomy AS type, lang.meta_value AS code, grp.meta_value AS grp, src.meta_value AS source
             FROM {$wpdb->term_taxonomy} tt
             JOIN {$wpdb->termmeta} lang ON lang.term_id = tt.term_id AND lang.meta_key = %s
             LEFT JOIN {$wpdb->termmeta} grp ON grp.term_id = tt.term_id AND grp.meta_key = %s
             LEFT JOIN {$wpdb->termmeta} src ON src.term_id = tt.term_id AND src.meta_key = %s
             WHERE tt.taxonomy IN (" . placeholders($types) . ')
             ORDER BY tt.term_id',
            Core\TERM_LANGUAGE_META,
            Core\TERM_GROUP_META,
            Core\SOURCE_META,
            ...$types
        ), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS id, p.post_type AS type, lt.slug AS code, gtt.term_id AS grp, src.meta_value AS source
             FROM {$wpdb->posts} p
             JOIN {$wpdb->term_relationships} lr ON lr.object_id = p.ID
             JOIN {$wpdb->term_taxonomy} ltt ON ltt.term_taxonomy_id = lr.term_taxonomy_id AND ltt.taxonomy = %s
             JOIN {$wpdb->terms} lt ON lt.term_id = ltt.term_id
             LEFT JOIN ({$wpdb->term_relationships} gr JOIN {$wpdb->term_taxonomy} gtt ON gtt.term_taxonomy_id = gr.term_taxonomy_id AND gtt.taxonomy = %s) ON gr.object_id = p.ID
             LEFT JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = %s
             WHERE p.post_type IN (" . placeholders($types) . ") AND p.post_status <> 'auto-draft'
             ORDER BY p.ID",
            Core\LANGUAGE_TAXONOMY,
            Core\GROUP_TAXONOMY,
            Core\SOURCE_META,
            ...$types
        ), ARRAY_A);
    }

    $by_group = [];
    $alone    = [];
    $skipped  = [];
    $seen     = [];

    foreach ($rows as $row) {
        $id   = (int) $row['id'];
        $code = (string) $row['code'];

        // a post in two groups or two languages (it shouldn't be): its first row
        if (isset($seen[$id])) {
            continue;
        }

        $seen[$id] = true;

        if (!in_array($code, $codes, true)) {
            $skipped[$code] = ($skipped[$code] ?? 0) + 1;
            continue;
        }

        $group = (string) ($row['grp'] ?? '');

        if ($group === '' || isset($by_group[$group]['items'][$code])) {
            if ($group !== '') {
                $warnings[] = sprintf('%s %d: a second %s item in its group, kept apart.', $kind === 'term' ? 'Term' : 'Post', $id, $code);
            }

            $alone[] = ['type' => (string) $row['type'], 'original' => $id, 'items' => [$code => $id]];
            continue;
        }

        $by_group[$group]['type']          ??= (string) $row['type'];
        $by_group[$group]['items'][$code]    = $id;
        $by_group[$group]['source'][$id]     = (int) $row['source'];
    }

    foreach ($skipped as $code => $count) {
        $warnings[] = sprintf('%d %s in %s, not one of the languages: left with none in WPML.', $count, $kind === 'term' ? 'terms' : 'posts', $code);
    }

    $groups = [];

    foreach ($by_group as $group) {
        uksort($group['items'], fn ($a, $b) => array_search($a, $codes, true) <=> array_search($b, $codes, true));

        // naming a source counts only for a source in the group
        $sourced = array_map(fn ($source) => in_array($source, $group['items'], true), $group['source']);

        $groups[] = [
            'type'     => $group['type'],
            'original' => pick_original($group['items'], $sourced, $default),
            'items'    => $group['items'],
        ];
    }

    return array_merge($groups, $alone);
}

/**
 * The posts (or terms) of the translated types with no language here.
 *
 * @param list<string> $types
 * @return list<int>
 */
function our_unassigned(string $kind, array $types): array
{
    global $wpdb;

    if (!$types) {
        return [];
    }

    if ($kind === 'term') {
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
             WHERE tt.taxonomy IN (" . placeholders($types) . ")
               AND NOT EXISTS (SELECT 1 FROM {$wpdb->termmeta} m WHERE m.term_id = tt.term_id AND m.meta_key = %s)",
            ...[...$types, Core\TERM_LANGUAGE_META]
        )));
    }

    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         WHERE p.post_type IN (" . placeholders($types) . ") AND p.post_status <> 'auto-draft'
           AND NOT EXISTS (
               SELECT 1 FROM {$wpdb->term_relationships} r
               JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = %s
               WHERE r.object_id = p.ID
           )",
        ...[...$types, Core\LANGUAGE_TAXONOMY]
    )));
}

/**
 * The menu of a language in a menu's group, in the tables: nothing of this plugin
 * is loaded to ask. A menu with no language counts as the default's.
 */
function our_menu_member(int $menu, string $code, string $default): int
{
    global $wpdb;

    if ($menu <= 0) {
        return 0;
    }

    $own   = (string) get_term_meta($menu, Core\TERM_LANGUAGE_META, true) ?: $default;
    $group = (string) get_term_meta($menu, Core\TERM_GROUP_META, true);

    if ($group === '') {
        return $own === $code ? $menu : 0;
    }

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT g.term_id FROM {$wpdb->termmeta} g
         JOIN {$wpdb->termmeta} l ON l.term_id = g.term_id AND l.meta_key = %s AND l.meta_value = %s
         WHERE g.meta_key = %s AND g.meta_value = %s LIMIT 1",
        Core\TERM_LANGUAGE_META,
        $code,
        Core\TERM_GROUP_META,
        $group
    ));
}

/**
 * The menus as WPML has them: each location's menus, one per language
 * (`main` the base language's, `main___en`…), one group, and the location
 * given the default language's, the languages' locations dropped. A menu can
 * be in one group only: met again with other menus, it stays in its first,
 * with a warning.
 *
 * @param list<string> $codes
 * @param list<string> $warnings
 * @return array{locations: array<string,int>, groups: list<array{type:string, original:int, items:array<string,int>}>}
 */
function our_menus(array $codes, string $default, array &$warnings): array
{
    global $wpdb;

    $locations = (array) (get_theme_mods()['nav_menu_locations'] ?? []);
    $groups    = [];
    $taken     = [];

    foreach ($wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s", Core\MENU_FOLLOW_META)) as $following) {
        $warnings[] = sprintf('Menu %d copies its original here. WPML has no such thing: there, it shows its own items, which may be none.', (int) $following);
    }

    foreach ($locations as $location => $menu) {
        $location = (string) $location;

        if (str_contains($location, '___')) {
            continue;
        }

        $items = [];

        foreach ($codes as $code) {
            // linked to the location's menu; on a site not upgraded yet, its language's location
            $id = (int) ($code === $default ? 0 : ($locations[$location . '___' . $code] ?? 0)) ?: our_menu_member((int) $menu, $code, $default);

            if ($id <= 0 || !term_taxonomy_id($id, 'nav_menu')) {
                continue;
            }

            if (isset($taken[$id]) && $taken[$id] !== $code) {
                $warnings[] = sprintf('Menu %d is in "%s" for %s and %s: kept in %s.', $id, $location, $taken[$id], $code, $taken[$id]);
                continue;
            }

            $items[$code] = $id;
        }

        if (!$items) {
            continue;
        }

        $original = $items[$default] ?? (int) reset($items);

        // the same menus met again (in another location): one group
        $key = implode(',', $items);

        if (!isset($groups[$key])) {
            foreach ($items as $code => $id) {
                if (isset($taken[$id])) {
                    $warnings[] = sprintf('Menu %d is in "%s" with other menus than in an earlier location: its group there is kept.', $id, $location);
                    unset($items[$code]);
                }
            }

            foreach ($items as $code => $id) {
                $taken[$id] = $code;
            }

            if ($items) {
                $groups[$key] = ['type' => 'nav_menu', 'original' => in_array($original, $items, true) ? $original : (int) reset($items), 'items' => $items];
            }
        }

        $locations[$location] = $original;
    }

    // the menus in no location: the default language's
    foreach ($wpdb->get_col("SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'nav_menu'") as $id) {
        if (!isset($taken[(int) $id])) {
            $groups[] = ['type' => 'nav_menu', 'original' => (int) $id, 'items' => [$default => (int) $id]];
        }
    }

    // WPML keeps a location's default-language menu only, and would write
    // every other language's as that one (on saving the theme mod)
    $locations = array_filter($locations, fn ($location) => !str_contains((string) $location, '___'), ARRAY_FILTER_USE_KEY);

    return ['locations' => $locations, 'groups' => array_values($groups)];
}

/**
 * The admin strings' translations, by language code instead of locale:
 * [code => [domain => [context EOT text => value]]]. A locale two languages
 * share goes to both.
 *
 * @param list<array{code:string,locale:string,name:string,slug:string}> $languages
 * @return array<string,array<string,array<string,string>>>
 */
function our_strings(array $languages): array
{
    $stored  = array_filter((array) get_option('wp_go_languages_admin_translations', []), 'is_array');
    $strings = [];

    foreach ($languages as $language) {
        if (isset($stored[$language['locale']])) {
            $strings[$language['code']] = $stored[$language['locale']];
        }
    }

    return $strings;
}

/**
 * The codes WPML doesn't know.
 *
 * @param list<string> $codes
 * @return list<string>
 */
function missing_in_wpml(array $codes): array
{
    global $wpdb;

    if (!wpml_tables_exist()) {
        return $codes;
    }

    $known = $wpdb->get_col('SELECT code FROM ' . wpml_table('languages'));

    return array_values(array_diff($codes, $known));
}

/**
 * Carry a plan out. `$log` is told each step.
 *
 * @param array<string,mixed>   $plan plan_to_wpml()'s
 * @param callable(string):void $log
 * @return list<string> warnings found while writing
 */
function apply_to_wpml(array $plan, callable $log): array
{
    $warnings = [];

    // WPML's setup completing has ACFML save every field group again (with
    // its translation mode), and ACF write each one to the theme's acf-json,
    // a clone field written out as the fields it clones: the files stay as
    // they are, the groups in the database get the mode
    add_filter('acf/json/save_paths', '__return_empty_array', PHP_INT_MAX);

    wpml_settings_from($plan);
    $log(sprintf('Settings: %s, %s default.', implode(', ', array_column($plan['languages'], 'code')), $plan['languages'][0]['code']));

    $suspend = class_exists('WPML_Post_Translation_Metadata_Initializer') && method_exists('WPML_Post_Translation_Metadata_Initializer', 'suspend');

    // the content as it is: WPML copies the original's copy fields into a
    // translation whose source changes, unless told not to
    if ($suspend) {
        \WPML_Post_Translation_Metadata_Initializer::suspend();
    }

    $run = fn (callable $flow) => method_exists('WPML_Set_Language', 'run_exempt_core_flow') ? \WPML_Set_Language::run_exempt_core_flow($flow) : $flow();

    $run(function () use ($plan, $log, &$warnings) {
        $default = $plan['languages'][0]['code'];

        foreach (['post' => $plan['posts'], 'term' => $plan['terms']] as $kind => $groups) {
            $changed = 0;

            foreach ($groups as $group) {
                $changed += (int) wpml_write_group($group, $kind, $warnings);
            }

            $given = 0;

            // with no language here: the default, unless WPML gave one already
            foreach ($plan['unassigned'][$kind] as $id) {
                $type = $kind === 'term' ? taxonomy_of($id) : (string) get_post_type($id);
                $given += (int) wpml_write_group(['type' => $type, 'original' => $id, 'items' => [$default => $id]], $kind, $warnings, 'row');
            }

            $log(sprintf('%s: %d groups written, %d given the default language.', $kind === 'term' ? 'Terms' : 'Posts', $changed, $given));
        }

        $items = 0;

        foreach ($plan['menus']['groups'] as $group) {
            // a menu alone (in one language's location, or in none) that
            // WPML has already stays as WPML has it
            wpml_write_group($group, 'term', $warnings, count($group['items']) === 1 ? 'row' : '');

            foreach ($group['items'] as $menu) {
                // the menu's language as WPML has it now: a menu left alone kept its own
                $code = (string) $GLOBALS['sitepress']->get_language_for_element(term_taxonomy_id($menu, 'nav_menu'), 'tax_nav_menu');

                foreach ($code !== '' ? get_objects_in_term($menu, 'nav_menu') ?: [] : [] as $item) {
                    $items += (int) wpml_write_group(['type' => 'nav_menu_item', 'original' => (int) $item, 'items' => [$code => (int) $item]], 'post', $warnings, 'language');
                }
            }
        }

        $log(sprintf('Menus: %d, %d items given their menu\'s language.', count($plan['menus']['groups']), $items));
    });

    if ($suspend) {
        \WPML_Post_Translation_Metadata_Initializer::resume();
    }

    set_theme_mod('nav_menu_locations', $plan['menus']['locations']);

    if ($plan['strings']) {
        if (function_exists('icl_register_string') && function_exists('icl_add_string_translation')) {
            $log(sprintf('Admin strings: %d translations.', wpml_write_strings($plan['strings'])));
        } else {
            $warnings[] = 'WPML String Translation isn\'t active: the admin strings weren\'t moved. Activate it and run this again.';
        }
    }

    if (function_exists('icl_cache_clear')) {
        icl_cache_clear();
    }

    wp_cache_flush();
    flush_rewrite_rules(false);

    remove_filter('acf/json/save_paths', '__return_empty_array', PHP_INT_MAX);

    return $warnings;
}

/**
 * WPML's languages and settings, as this plugin had them.
 *
 * @param array<string,mixed> $plan
 */
function wpml_settings_from(array $plan): void
{
    global $sitepress, $wpdb;

    $codes        = array_column($plan['languages'], 'code');
    $default      = $codes[0];
    $installation = wpml_get_setup_instance();

    if (!$sitepress->is_setup_complete()) {
        // WPML's setup, as its wizard takes it: the default, then the others
        $installation->finish_step1($default);
        $installation->finish_step2($codes);
        $installation->finish_installation();
    } else {
        $installation->set_active_languages($codes);

        if ($sitepress->get_default_language() !== $default) {
            $sitepress->set_default_language($default);
        }
    }

    $languages = wpml_table('languages');
    $display   = (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$languages` LIKE %s", 'display_code'));

    foreach ($plan['languages'] as $language) {
        $wpdb->delete(wpml_table('locale_map'), ['code' => $language['code']]);
        $wpdb->insert(wpml_table('locale_map'), ['code' => $language['code'], 'locale' => $language['locale']]);

        if ($display) {
            $wpdb->update($languages, ['display_code' => $language['slug']], ['code' => $language['code']]);
        }
    }

    $urls = (array) $sitepress->get_setting('urls', []);
    $urls['directory_for_default_language'] = $plan['default_in_url'] ? 1 : 0;

    $sitepress->set_setting('language_negotiation_type', 1);
    $sitepress->set_setting('urls', $urls);
    $sitepress->set_setting('languages_order', $codes);
    $sitepress->set_setting('existing_content_language_verified', 1);
    $sitepress->set_setting('custom_posts_sync_option', array_replace((array) $sitepress->get_setting('custom_posts_sync_option', []), array_fill_keys($plan['post_types'], 1)));
    $sitepress->set_setting('taxonomies_sync_option', array_replace((array) $sitepress->get_setting('taxonomies_sync_option', []), array_fill_keys($plan['taxonomies'], 1)));
    $sitepress->save_settings();

    if (function_exists('icl_cache_clear')) {
        icl_cache_clear();
    }

    wp_cache_flush();
}

/**
 * A group into WPML: the original's row first (no source), then each
 * translation's in its trid. Left alone when WPML has it so already. A group
 * of one is left alone too, its trid kept, with `$keep` 'language' when WPML
 * has it in that language (a menu item linked to its translations in WPML),
 * with 'row' when WPML has it at all (an item with no language here).
 *
 * @param array{type:string, original:int, items:array<string,int>} $group
 * @param ''|'language'|'row' $keep
 * @param list<string> $warnings
 * @return bool whether anything was written
 */
function wpml_write_group(array $group, string $kind, array &$warnings, string $keep = ''): bool
{
    global $sitepress;

    if ($group['type'] === '') {
        return false;
    }

    $type     = ($kind === 'term' ? 'tax_' : 'post_') . $group['type'];
    $elements = [];

    foreach ($group['items'] as $code => $id) {
        $element = $kind === 'term' ? term_taxonomy_id($id, $group['type']) : $id;

        if ($element <= 0) {
            continue;
        }

        $elements[$code] = $element;
    }

    $original = $kind === 'term' ? term_taxonomy_id($group['original'], $group['type']) : $group['original'];
    $source   = array_search($original, $elements, true);

    if ($source === false || wpml_group_matches($elements, $original, $type, $keep)) {
        return false;
    }

    if (!$sitepress->set_element_language_details($original, $type, null, $source)) {
        $warnings[] = sprintf('%s %d: WPML refused its language (%s).', $kind, $group['original'], $source);

        return false;
    }

    $trid = (int) $sitepress->get_element_trid($original, $type);

    foreach ($elements as $code => $element) {
        if ($element !== $original && !$sitepress->set_element_language_details($element, $type, $trid, $code, $source)) {
            $warnings[] = sprintf('%s %d: WPML refused it as the %s translation of %d.', $kind, $group['items'][$code], $code, $group['original']);
        }
    }

    return true;
}

/**
 * Whether WPML already has a group as planned: every element in one trid in
 * its language, nothing else in that trid, the original with no source and
 * the others with its language. With `$keep`, for a group of one, a row in
 * its language ('language') or any row ('row').
 *
 * @param array<string,int> $elements code => element id
 */
function wpml_group_matches(array $elements, int $original, string $type, string $keep): bool
{
    global $wpdb;

    $table = wpml_table('translations');
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT element_id, trid, language_code, source_language_code FROM `$table` WHERE element_type = %s AND element_id IN (" . placeholders(array_values($elements), '%d') . ')',
        $type,
        ...array_values($elements)
    ), ARRAY_A);

    $by_element = array_column($rows, null, 'element_id');
    $source     = array_search($original, $elements, true);

    if ($keep !== '' && count($elements) === 1) {
        $language = $by_element[$original]['language_code'] ?? null;

        return $keep === 'row' ? $language !== null : $language === $source;
    }

    $trids = array_unique(array_column($rows, 'trid'));

    if (count($rows) !== count($elements) || count($trids) !== 1) {
        return false;
    }

    foreach ($elements as $code => $element) {
        $row = $by_element[$element] ?? null;

        if (!$row || $row['language_code'] !== $code || ($element === $original ? $row['source_language_code'] !== null : $row['source_language_code'] !== $source)) {
            return false;
        }
    }

    $members = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE trid = %d AND element_id IS NOT NULL", reset($trids)));

    return $members === count($elements);
}

/**
 * The admin strings into String Translation: each string registered as
 * `wp base strings` registers it, then its translation, complete.
 *
 * @param array<string,array<string,array<string,string>>> $strings code => domain => key => value
 * @return int the translations written
 */
function wpml_write_strings(array $strings): int
{
    $written = 0;
    $ids     = [];

    foreach ($strings as $code => $domains) {
        foreach ($domains as $domain => $values) {
            foreach ($values as $key => $value) {
                [$context, $text] = str_contains($key, "\x04") ? explode("\x04", $key, 2) : ['', $key];

                $ids[$domain][$key] ??= (int) icl_register_string(['domain' => $domain, 'context' => $context], md5($text), $text);

                if ($ids[$domain][$key] > 0 && icl_add_string_translation($ids[$domain][$key], $code, $value, ICL_TM_COMPLETE)) {
                    $written++;
                }
            }
        }
    }

    return $written;
}

/**
 * A term's term_taxonomy_id, read in the table: WPML's filters on get_term()
 * may hand back the current language's translation instead. 0 if none.
 */
function term_taxonomy_id(int $term_id, string $taxonomy): int
{
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $term_id, $taxonomy));
}

/** A term's taxonomy, read in the table, as term_taxonomy_id() is. */
function taxonomy_of(int $term_id): string
{
    global $wpdb;

    return (string) $wpdb->get_var($wpdb->prepare("SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $term_id));
}
