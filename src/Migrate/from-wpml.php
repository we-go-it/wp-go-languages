<?php

namespace WpGo\Languages\Migrate;

use WpGo\Languages\Admin;
use WpGo\Languages\Core;

/**
 * A site on WPML moved onto this plugin: `wp languages migrate from-wpml`,
 * run with WPML deactivated and this plugin active. WPML's tables and
 * settings stay when it is deactivated, and are read from there:
 *
 *   icl_sitepress_settings   the default language, whether its URLs have its
 *                            directory, the translated post types and
 *                            taxonomies, the languages' order
 *   icl_languages            the active languages; `display_code` (WPML 5)
 *                            their URL segment, the code if empty
 *   icl_locale_map           their WordPress locale (else `default_locale`)
 *   icl_languages_translations  their own names
 *   icl_translations         each post's and term's language, its group
 *                            (`trid`) and the group's original (the row
 *                            with no `source_language_code`); a term's
 *                            element is its term_taxonomy_id
 *   icl_strings, icl_string_translations  the admin strings' translations
 *
 * What is written, through this plugin's own functions:
 *
 *   - the settings (the `wp_go_languages` option): the languages with
 *     WPML's codes, so what is stored by code stays where it is (ACF's
 *     `options_en`, the site texts), the default, the base language (the
 *     default: WPML's options and menus are the default's when bare), the
 *     post types and taxonomies;
 *   - each post's and term's language, and its group: the translations
 *     linked to the original as independent translations, their content as it is;
 *   - a post or term WPML has no row for gets the default language;
 *   - the menus: each location's menu stays the default language's, its
 *     translations go to the other languages' locations (`main___en`);
 *   - the admin strings' translations, for the domains this plugin lists,
 *     after a scan of the code (only strings the code still has).
 *
 * Run again, it puts right what changed and leaves alone what is already
 * so. Site texts need nothing: WP-Go's copy on WPML and this plugin's keep
 * them in the same option.
 */

/** URL segments a language may not have (Admin\save_settings() keeps them out too). */
const RESERVED_SLUGS = ['wp-json', 'wp-admin', 'wp-content', 'wp-includes', 'app', 'wp', 'feed'];

/**
 * What a move from WPML would do, read only.
 *
 * @return array{
 *     languages: list<array{code:string,locale:string,name:string,slug:string}>,
 *     default_in_url: bool,
 *     post_types: list<string>,
 *     taxonomies: list<string>,
 *     posts: list<array{type:string, original:int, items:array<string,int>}>,
 *     terms: list<array{type:string, original:int, items:array<string,int>}>,
 *     unassigned: array{post: list<int>, term: list<int>},
 *     menus: array<string,int>,
 *     strings: array<string,array<string,array<string,string>>>,
 *     warnings: list<string>
 * }
 */
function plan_from_wpml(): array
{
    $settings = get_option('icl_sitepress_settings', []);
    $settings = is_array($settings) ? $settings : [];
    $warnings = [];

    $languages = wpml_languages($settings);
    $codes     = array_column($languages, 'code');
    $default   = $codes[0] ?? '';

    $negotiation = (int) ($settings['language_negotiation_type'] ?? 1);

    if ($negotiation !== 1) {
        $warnings[] = sprintf('WPML put the language %s; this plugin puts it in the path (/en/…): URLs change.', $negotiation === 2 ? 'in the domain' : 'in a parameter (?lang=en)');
    }

    if (!empty($settings['hidden_languages'])) {
        $warnings[] = sprintf('Hidden languages in WPML (%s) are languages like the others here.', implode(', ', (array) $settings['hidden_languages']));
    }

    $post_types = wpml_translated($settings['custom_posts_sync_option'] ?? [], ['post', 'page'], array_keys(Admin\translatable_post_types()), 'post type', $warnings);
    $taxonomies = wpml_translated($settings['taxonomies_sync_option'] ?? [], ['category', 'post_tag'], array_keys(Admin\translatable_taxonomies()), 'taxonomy', $warnings);

    $fallback = array_keys(array_filter((array) ($settings['custom_posts_sync_option'] ?? []), fn ($mode) => (int) $mode === 2));

    if (array_intersect($fallback, $post_types)) {
        $warnings[] = sprintf('WPML showed the default language\'s items where a translation was missing (%s): here a language shows only its own.', implode(', ', array_intersect($fallback, $post_types)));
    }

    return [
        'languages'      => $languages,
        'default_in_url' => !empty($settings['urls']['directory_for_default_language']),
        'post_types'     => $post_types,
        'taxonomies'     => $taxonomies,
        'posts'          => wpml_groups('post', $post_types, $codes, $default, $warnings),
        'terms'          => wpml_groups('term', $taxonomies, $codes, $default, $warnings),
        'unassigned'     => [
            'post' => wpml_unassigned('post', $post_types),
            'term' => wpml_unassigned('term', $taxonomies),
        ],
        'menus'          => wpml_menus($codes, $default, $warnings),
        'strings'        => wpml_strings($languages, $warnings),
        'warnings'       => $warnings,
    ];
}

/**
 * WPML's active languages as this plugin's entries, the default first, then
 * in WPML's order.
 *
 * @param array<string,mixed> $settings
 * @return list<array{code:string,locale:string,name:string,slug:string}>
 */
function wpml_languages(array $settings): array
{
    global $wpdb;

    $table   = wpml_table('languages');
    $display = (bool) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", 'display_code'));
    $rows    = $wpdb->get_results("SELECT code, english_name, default_locale" . ($display ? ', display_code' : '') . " FROM `$table` WHERE active = 1", ARRAY_A);

    $locales = [];

    foreach ($wpdb->get_results('SELECT code, locale FROM ' . wpml_table('locale_map'), ARRAY_A) as $row) {
        $locales[$row['code']] ??= $row['locale'];
    }

    $names = [];

    foreach ($wpdb->get_results('SELECT language_code, name FROM ' . wpml_table('languages_translations') . ' WHERE language_code = display_language_code', ARRAY_A) as $row) {
        $names[$row['language_code']] = $row['name'];
    }

    $default = (string) ($settings['default_language'] ?? '');
    $order   = array_flip(array_values((array) ($settings['languages_order'] ?? [])));

    usort($rows, fn ($a, $b) => [$a['code'] !== $default, $order[$a['code']] ?? PHP_INT_MAX, $a['code']] <=> [$b['code'] !== $default, $order[$b['code']] ?? PHP_INT_MAX, $b['code']]);

    $languages = [];
    $slugs     = [];

    foreach ($rows as $row) {
        $code = (string) $row['code'];
        $slug = sanitize_title((string) ($row['display_code'] ?? '')) ?: $code;

        // as the Languages screen: a reserved or taken segment is the code
        if (in_array($slug, RESERVED_SLUGS, true) || in_array($slug, $slugs, true)) {
            $slug = $code;
        }

        $slugs[]     = $slug;
        $languages[] = [
            'code'   => $code,
            'locale' => (string) ($locales[$code] ?? $row['default_locale'] ?: $code),
            'name'   => (string) ($names[$code] ?? $row['english_name']),
            'slug'   => $slug,
        ];
    }

    return Core\normalize_languages($languages);
}

/**
 * The post types (or taxonomies) WPML translated, of those this plugin can:
 * WPML's always-translated ones and those set to 1 (translatable) or 2
 * (translatable, the default's shown where missing).
 *
 * @param mixed        $sync    WPML's setting: name => 0, 1 or 2
 * @param list<string> $always
 * @param list<string> $offered what the Languages screen offers
 * @param list<string> $warnings
 * @return list<string>
 */
function wpml_translated(mixed $sync, array $always, array $offered, string $what, array &$warnings): array
{
    $names = $always;

    foreach ((array) $sync as $name => $mode) {
        if ((int) $mode >= 1) {
            $names[] = (string) $name;
        }
    }

    $names = array_values(array_unique($names));
    $kept  = [];

    foreach ($names as $name) {
        if (in_array($name, $offered, true)) {
            $kept[] = $name;

            continue;
        }

        // one this plugin never translates (media, WPML's own) goes unsaid
        $registered = $what === 'post type' ? post_type_exists($name) : taxonomy_exists($name);

        if (!$registered && !in_array($name, $always, true) && $name !== 'translation_priority') {
            $warnings[] = sprintf('The %s %s was translated in WPML but isn\'t registered now (its plugin off?): not translated here, its items not moved.', $what, $name);
        }
    }

    return $kept;
}

/**
 * WPML's groups of the given post types (or taxonomies), for the languages
 * kept: an item in another language is left out, with a warning. (A group
 * has one item per language: WPML's table has a unique key on it.)
 *
 * @param list<string> $types
 * @param list<string> $codes
 * @param list<string> $warnings
 * @return list<array{type:string, original:int, items:array<string,int>}>
 */
function wpml_groups(string $kind, array $types, array $codes, string $default, array &$warnings): array
{
    global $wpdb;

    if (!$types || !$codes) {
        return [];
    }

    $prefix        = $kind === 'term' ? 'tax_' : 'post_';
    $element_types = array_map(fn ($type) => $prefix . $type, $types);
    $table         = wpml_table('translations');

    // joined on the id alone, the type compared here: WPML's table and
    // WordPress's may not share a collation
    $sql = $kind === 'term'
        ? "SELECT t.trid, t.language_code, t.source_language_code, t.element_type, tt.term_id AS id, tt.taxonomy AS type
           FROM `$table` t JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = t.element_id
           WHERE t.element_type IN (" . placeholders($element_types) . ') ORDER BY t.trid, t.translation_id'
        : "SELECT t.trid, t.language_code, t.source_language_code, t.element_type, p.ID AS id, p.post_type AS type
           FROM `$table` t JOIN {$wpdb->posts} p ON p.ID = t.element_id
           WHERE t.element_type IN (" . placeholders($element_types) . ") AND p.post_status <> 'auto-draft' ORDER BY t.trid, t.translation_id";

    $rows    = $wpdb->get_results($wpdb->prepare($sql, ...$element_types), ARRAY_A);
    $by_trid = [];
    $skipped = [];

    foreach ($rows as $row) {
        if ($row['element_type'] !== $prefix . $row['type']) {
            continue;
        }

        $id   = (int) $row['id'];
        $code = (string) $row['language_code'];

        if (!in_array($code, $codes, true)) {
            $skipped[$code] = ($skipped[$code] ?? 0) + 1;
            continue;
        }

        $trid = (int) $row['trid'];

        $by_trid[$trid]['type']          ??= (string) $row['type'];
        $by_trid[$trid]['items'][$code]    = $id;
        $by_trid[$trid]['sourced'][$id]    = $row['source_language_code'] !== null && $row['source_language_code'] !== '';
    }

    foreach ($skipped as $code => $count) {
        $warnings[] = sprintf('%d %s in %s, not an active language in WPML: left with no language.', $count, $kind === 'term' ? 'terms' : 'posts', $code);
    }

    $groups = [];

    foreach ($by_trid as $group) {
        // in the languages' order, as this plugin lists a group
        uksort($group['items'], fn ($a, $b) => array_search($a, $codes, true) <=> array_search($b, $codes, true));

        $groups[] = [
            'type'     => $group['type'],
            'original' => pick_original($group['items'], $group['sourced'], $default),
            'items'    => $group['items'],
        ];
    }

    return $groups;
}

/**
 * The posts (or terms) of the translated types WPML has no row for.
 *
 * @param list<string> $types
 * @return list<int>
 */
function wpml_unassigned(string $kind, array $types): array
{
    global $wpdb;

    if (!$types) {
        return [];
    }

    $table = wpml_table('translations');

    if ($kind === 'term') {
        $element_types = array_map(fn ($type) => 'tax_' . $type, $types);

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
             WHERE tt.taxonomy IN (" . placeholders($types) . ")
               AND tt.term_taxonomy_id NOT IN (SELECT element_id FROM `$table` WHERE element_type IN (" . placeholders($element_types) . ') AND element_id IS NOT NULL)',
            ...$types,
            ...$element_types
        )));
    }

    $element_types = array_map(fn ($type) => 'post_' . $type, $types);

    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         WHERE p.post_type IN (" . placeholders($types) . ") AND p.post_status <> 'auto-draft'
           AND p.ID NOT IN (SELECT element_id FROM `$table` WHERE element_type IN (" . placeholders($element_types) . ') AND element_id IS NOT NULL)',
        ...$types,
        ...$element_types
    )));
}

/**
 * The menu locations as this plugin has them: WPML keeps each location's
 * default-language menu and finds the others through the menus'
 * translations; here each language has its location (`main___en`).
 *
 * @param list<string> $codes
 * @param list<string> $warnings
 * @return array<string,int> location => menu term_id, every location
 */
function wpml_menus(array $codes, string $default, array &$warnings): array
{
    global $wpdb;

    $locations = (array) (get_theme_mods()['nav_menu_locations'] ?? []);

    if (!$locations || !$codes) {
        return $locations;
    }

    $rows = $wpdb->get_results(
        'SELECT t.trid, t.language_code, tt.term_id FROM `' . wpml_table('translations') . "` t
         JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = t.element_id
         WHERE t.element_type = 'tax_nav_menu'",
        ARRAY_A
    );

    $trid_of = [];
    $members = [];

    foreach ($rows as $row) {
        $trid_of[(int) $row['term_id']]                      = (int) $row['trid'];
        $members[(int) $row['trid']][$row['language_code']] = (int) $row['term_id'];
    }

    foreach ($locations as $location => $menu) {
        $location = (string) $location;

        if (str_contains($location, '___') || !$menu || !isset($trid_of[(int) $menu])) {
            continue;
        }

        $translations = $members[$trid_of[(int) $menu]];

        foreach ($codes as $code) {
            $key = $code === $default ? $location : $location . '___' . $code;

            if (isset($translations[$code])) {
                $locations[$key] = $translations[$code];
            } elseif ($code !== $default) {
                $warnings[] = sprintf('The menu in "%s" has no %s translation: the %s pages show none there (WPML showed the default\'s).', $location, $code, $code);
            }
        }
    }

    return $locations;
}

/**
 * WPML's translations of the admin strings of the domains this plugin lists:
 * [locale => [domain => [context EOT text => value]]], completed ones only.
 *
 * The translations of other domains (the site's options, a theme's own
 * strings, the blocks' texts WPML kept) aren't: a warning says which.
 *
 * @param list<array{code:string,locale:string,name:string,slug:string}> $languages
 * @param list<string> $warnings
 * @return array<string,array<string,array<string,string>>>
 */
function wpml_strings(array $languages, array &$warnings): array
{
    global $wpdb;

    $domains = function_exists('wp_go_admin_strings_listed') ? array_keys(wp_go_admin_strings_listed()) : [];
    $table   = wpml_table('strings');

    if (!$domains || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
        return [];
    }

    $locales = array_column($languages, 'locale', 'code');
    $rows    = $wpdb->get_results($wpdb->prepare(
        "SELECT s.context, s.gettext_context, s.value AS text, st.language, st.value
         FROM `$table` s JOIN `" . wpml_table('string_translations') . "` st ON st.string_id = s.id
         WHERE s.context IN (" . placeholders($domains) . ") AND st.status = 10 AND st.value IS NOT NULL AND st.value <> ''",
        ...$domains
    ), ARRAY_A);

    $strings = [];

    foreach ($rows as $row) {
        $locale = $locales[$row['language']] ?? null;

        if ($locale === null) {
            continue;
        }

        $key = $row['gettext_context'] !== '' ? $row['gettext_context'] . "\x04" . $row['text'] : $row['text'];

        $strings[$locale][$row['context']][$key] = (string) $row['value'];
    }

    $others = $wpdb->get_results($wpdb->prepare(
        "SELECT s.context, COUNT(*) AS n FROM `$table` s JOIN `" . wpml_table('string_translations') . "` st ON st.string_id = s.id
         WHERE s.context NOT IN (" . placeholders($domains) . ") AND st.status = 10 AND st.value IS NOT NULL AND st.value <> ''
         GROUP BY s.context ORDER BY s.context",
        ...$domains
    ), ARRAY_A);

    if ($others) {
        $warnings[] = sprintf(
            'String Translation has %d translations of other domains, not moved: %s. Site texts and per-language fields take their place here.',
            array_sum(array_column($others, 'n')),
            implode(', ', array_map(fn ($row) => "{$row['context']} ({$row['n']})", $others))
        );
    }

    return $strings;
}

/**
 * Carry a plan out. `$log` is told each step.
 *
 * @param array<string,mixed> $plan plan_from_wpml()'s
 * @param callable(string):void $log
 * @return list<string> warnings found while writing
 */
function apply_from_wpml(array $plan, callable $log): array
{
    $warnings = [];

    write_settings($plan, $warnings);
    $log(sprintf('Settings: %s, %s default.', implode(', ', array_column($plan['languages'], 'code')), Core\default_language()));

    wp_defer_term_counting(true);

    foreach (['post' => $plan['posts'], 'term' => $plan['terms']] as $kind => $groups) {
        $changed = apply_groups($groups, $kind, $warnings);
        $default = Core\default_language();
        $given   = 0;

        foreach ($plan['unassigned'][$kind] as $id) {
            if (Core\language_of($id, $kind) === null && Core\set_language($id, $default, $kind)) {
                $given++;
            }
        }

        $log(sprintf('%s: %d groups changed, %d given the default language.', $kind === 'term' ? 'Terms' : 'Posts', $changed, $given));
    }

    wp_defer_term_counting(false);

    // WPML's menu groups, from what the plan put in the languages' locations
    set_theme_mod('nav_menu_locations', $plan['menus']);
    Core\link_menus_from_locations($plan['languages'][0]['code']);
    update_option(Core\MENUS_OPTION, 1, true);
    $log('Menus: ' . count(array_filter(array_keys($plan['menus']), fn ($location) => str_contains((string) $location, '___'))) . ' language locations set.');

    if ($plan['strings'] && function_exists('wp_go_admin_strings_scan')) {
        $log(sprintf('Admin strings: %d imported.', import_strings($plan['strings'])));
    }

    flush_rewrite_rules(false);

    /** The Languages screen's settings were saved: here by a migration. */
    do_action('wp_go_languages/settings_saved');

    return $warnings;
}

/**
 * The plan's settings into the option, read again at once: the groups'
 * languages need them. What a project sets in code (`wp_go_languages/config`)
 * still wins, with a warning when it differs.
 *
 * @param array<string,mixed> $plan
 * @param list<string>        $warnings
 */
function write_settings(array $plan, array &$warnings): void
{
    $stored = get_option(Core\OPTION, []);
    $stored = is_array($stored) ? $stored : [];

    update_option(Core\OPTION, array_replace($stored, [
        'languages'      => $plan['languages'],
        'default_in_url' => $plan['default_in_url'],
        'post_types'     => $plan['post_types'],
        'taxonomies'     => $plan['taxonomies'],
    ]));

    // WPML keeps the default's options with no suffix too: nothing to swap
    update_option(Core\STORED_DEFAULT_OPTION, $plan['languages'][0]['code'], true);

    $config = Core\config(true);

    if (array_column($config['languages'], 'code') !== array_column($plan['languages'], 'code')) {
        $warnings[] = 'The languages are set in code (wp_go_languages/config): those win over WPML\'s. Change the code, then run this again.';
    }

    // registered on init with the types of before
    Core\register_taxonomies();
    Core\ensure_language_terms();
}

/**
 * Each group as the plan has it: languages first, then the group, made again
 * only when it differs. Translations are independent translations: their content is theirs.
 *
 * @param list<array{type:string, original:int, items:array<string,int>}> $groups
 * @param list<string> $warnings
 * @return int the groups changed
 */
function apply_groups(array $groups, string $kind, array &$warnings): int
{
    $changed = 0;

    foreach ($groups as $group) {
        $original = $group['original'];
        $touched  = false;

        foreach ($group['items'] as $code => $id) {
            if (Core\language_of($id, $kind) === $code) {
                continue;
            }

            if (Core\set_language($id, $code, $kind)) {
                $touched = true;
            } else {
                $warnings[] = sprintf('%s %d: its language (%s) could not be set.', $kind, $id, $code);
            }
        }

        if (count($group['items']) === 1) {
            if (Core\group_of($original, $kind) !== null) {
                Core\unlink($original, $kind);
                $touched = true;
            }

            $changed += (int) $touched;

            continue;
        }

        if (!group_matches($group, $kind)) {
            foreach ($group['items'] as $id) {
                Core\unlink($id, $kind);
            }

            delete_metadata($kind, $original, Core\SOURCE_META);

            foreach ($group['items'] as $id) {
                if ($id === $original) {
                    continue;
                }

                $linked = Core\link($id, $original, $kind);

                if (is_wp_error($linked)) {
                    $warnings[] = sprintf('%s %d: not linked to %d (%s).', $kind, $id, $original, $linked->get_error_message());
                }
            }

            $touched = true;
        }

        foreach ($group['items'] as $id) {
            if ($id !== $original && get_metadata($kind, $id, Core\MODE_META, true) !== 'independent') {
                update_metadata($kind, $id, Core\MODE_META, 'independent');
                $touched = true;
            }
        }

        $changed += (int) $touched;
    }

    return $changed;
}

/**
 * Whether a group is already as planned: one group, those items and no
 * other, each translation naming the original, the original naming none.
 *
 * @param array{type:string, original:int, items:array<string,int>} $group
 */
function group_matches(array $group, string $kind): bool
{
    $current = Core\group_of($group['original'], $kind);

    if ($current === null || get_metadata($kind, $group['original'], Core\SOURCE_META, true) !== '') {
        return false;
    }

    $members = Core\group_members($current, $kind);
    $planned = array_values($group['items']);

    sort($members);
    sort($planned);

    if ($members !== $planned) {
        return false;
    }

    foreach ($group['items'] as $id) {
        if ($id !== $group['original'] && (int) get_metadata($kind, $id, Core\SOURCE_META, true) !== $group['original']) {
            return false;
        }
    }

    return true;
}

/**
 * WPML's translations into the admin strings: the code scanned first, then
 * the translations of the strings it has, where the string has none here
 * yet.
 *
 * @param array<string,array<string,array<string,string>>> $strings
 * @return int how many were added
 */
function import_strings(array $strings): int
{
    $scan  = wp_go_admin_strings_scan();
    $known = [];

    foreach ($scan['strings'] as $string) {
        $known[$string['domain']][wp_go_admin_strings_lookup_key($string['context'], $string['text'])] = true;
    }

    $translations = wp_go_admin_strings_translations();
    $added        = 0;

    foreach ($strings as $locale => $domains) {
        foreach ($domains as $domain => $values) {
            foreach ($values as $key => $value) {
                if (isset($known[$domain][$key]) && ($translations[$locale][$domain][$key] ?? '') === '') {
                    $translations[$locale][$domain][$key] = $value;
                    $added++;
                }
            }
        }
    }

    if ($added) {
        wp_go_admin_strings_store($translations);
    }

    return $added;
}
