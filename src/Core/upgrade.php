<?php

namespace WpGo\Languages\Core;

/**
 * What the stored data needs when the plugin changes its shape: run once, as
 * the plugin boots, the version kept in an option. Plain SQL, so no save hook
 * runs and nothing is purged but what's rewritten.
 *
 *   2  the modes' keys: `duplicate`, `fixed`, `structure` became `independent`,
 *      `text`, `blocks`
 *   3  `text` went: what's stored so becomes the fixed layout with its non-text
 *      fields linked, or independent (convert_text_only()), by the next request that
 *      may (run_conversions()), as it reads ACF's fields
 *   4  synced values stored as their original has them, what they point at worked
 *      out on rendering (convert_synced_links()), the same way
 *   5  core's cached term trees (`{taxonomy}_children`) dropped: a translated taxonomy's
 *      held only the language that first built it (terms_clauses())
 *
 * And the storage that follows the default language: ACF's options (`options_…`,
 * and `options_en_…` for the others) keep the default's with no suffix, and a menu
 * location holds the default's menu. When the default changes, in the Languages
 * screen or in code, both follow (sync_stored_default()): there's no other "main"
 * language to know about than the default.
 */

const SCHEMA_OPTION = 'wp_go_languages_schema';

const SCHEMA = 5;

/** Set while what's stored as `text` waits for ACF's fields: run_conversions(). */
const TEXT_ONLY_OPTION = 'wp_go_languages_convert_text_only';

/** Set while the synced values wait for ACF's fields: run_conversions(). */
const SYNCED_LINKS_OPTION = 'wp_go_languages_convert_synced_links';

/** What a text-only translation kept of its page's rows: gone with the mode. */
const LEGACY_ROWS_META = '_wgl_rows';

/** Whether the menus are linked, not given a location per language. */
const MENUS_OPTION = 'wp_go_languages_menus_linked';

/** The language that has the suffixless options, and the locations' menus, now. */
const STORED_DEFAULT_OPTION = 'wp_go_languages_stored_default';

/** The new default's language, while its items are becoming their groups' originals. */
const NEW_ORIGINALS_OPTION = 'wp_go_languages_new_originals';

/** When a conversion started, while it runs: another request leaves it to it. */
const CONVERTING_OPTION = 'wp_go_languages_converting';

/** After so long, a conversion that said it was running is taken not to be. */
const CONVERTING_TIMEOUT = 1800;

function upgrade(): void
{
    $version = (int) get_option(SCHEMA_OPTION, 0);

    if ($version >= SCHEMA) {
        return;
    }

    if ($version < 2) {
        upgrade_mode_keys();
    }

    if ($version < 3) {
        update_option(TEXT_ONLY_OPTION, 1, true);
    }

    if ($version < 4) {
        update_option(SYNCED_LINKS_OPTION, 1, true);
    }

    if ($version < 5) {
        foreach (translated_taxonomies() as $taxonomy) {
            delete_option("{$taxonomy}_children");
        }

        // asked on every request: autoloaded, and there even with nothing to do, as an
        // option that isn't there costs a query each time
        foreach ([TEXT_ONLY_OPTION, SYNCED_LINKS_OPTION, NEW_ORIGINALS_OPTION] as $option) {
            add_option($option, 0, '', true) || wp_set_option_autoload($option, true);
        }
    }

    update_option(SCHEMA_OPTION, SCHEMA, true);
}

/**
 * The stored modes under their new keys, posts and terms.
 */
function upgrade_mode_keys(): void
{
    global $wpdb;

    $changed = 0;

    foreach ([$wpdb->postmeta, $wpdb->termmeta] as $table) {
        foreach (['duplicate' => 'independent', 'fixed' => 'text', 'structure' => 'blocks'] as $old => $new) {
            $changed += (int) $wpdb->update($table, ['meta_value' => $new], ['meta_key' => MODE_META, 'meta_value' => $old]);
        }
    }

    if ($changed > 0) {
        wp_cache_flush();
    }
}

/**
 * Whether a conversion waits. In a `wp` command, which may be the one reading it first
 * (`--dry-run`): it's left to its own command. In a visitor's request or the API's, which
 * shouldn't be the one to go through every page of a site, nor hold a transaction open
 * meanwhile: until it's done, what's stored reads as it did. An administrator's request
 * or a cron run does it.
 */
function conversion_waits(): bool
{
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }

    return !wp_doing_cron() && (State::$context !== 'admin' || !current_user_can('manage_options'));
}

/** Whether a conversion is left to do. */
function conversions_left(): bool
{
    return get_option(TEXT_ONLY_OPTION) || get_option(SYNCED_LINKS_OPTION) || get_option(NEW_ORIGINALS_OPTION);
}

/**
 * A conversion that waits gets its own cron run, for a site where no administrator comes
 * by soon.
 */
function schedule_conversions(): void
{
    if (conversions_left() && !(defined('WP_CLI') && WP_CLI) && !wp_next_scheduled('wp_go_languages/convert')) {
        wp_schedule_single_event(time() + 60, 'wp_go_languages/convert');
    }
}

/**
 * What's left of the conversions, as a request that may reaches `init`'s end, with ACF's
 * fields there. Each option goes when its conversion has succeeded: one that fails, or
 * dies with its request, is tried again. CONVERTING_OPTION leaves it to one request at a
 * time.
 */
function run_conversions(): void
{
    if (!conversions_left() || conversion_waits()) {
        return;
    }

    $since = (int) get_option(CONVERTING_OPTION, 0);

    if ($since > time() - CONVERTING_TIMEOUT || ($since && !delete_option(CONVERTING_OPTION)) || !add_option(CONVERTING_OPTION, time(), '', false)) {
        return;
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    ignore_user_abort(true);

    foreach ([
        TEXT_ONLY_OPTION      => fn () => convert_text_only(true),
        SYNCED_LINKS_OPTION   => fn () => convert_synced_links(true),
        NEW_ORIGINALS_OPTION  => fn () => finish_new_originals(),
    ] as $option => $convert) {
        if (!get_option($option)) {
            continue;
        }

        try {
            $convert();
        } catch (\Throwable $error) {
            error_log(sprintf('WP-Go Languages: %s not finished, to be tried again: %s', $option, $error->getMessage()));
        }
    }

    delete_option(CONVERTING_OPTION);
}

/**
 * A new default language's items made their groups' originals, as far as it goes: said
 * first, so a request that dies leaves the rest to the next one (run_conversions()).
 */
function start_new_originals(string $code): void
{
    update_option(NEW_ORIGINALS_OPTION, $code, true);
    finish_new_originals();
}

function finish_new_originals(): void
{
    $code = (string) get_option(NEW_ORIGINALS_OPTION);

    if ($code !== '' && is_language($code)) {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        ignore_user_abort(true);
        make_originals($code);
    }

    update_option(NEW_ORIGINALS_OPTION, 0, true);
}

/**
 * The options and menus brought in line with the default language: on `init`,
 * after ACF's fields and the theme's rules are there, and on the Languages
 * screen's save. A site saved before this keeps the language in the settings'
 * `base_language`. A language gone from the site has nothing to swap with.
 */
function sync_stored_default(): void
{
    $now      = default_language();
    $stored   = (string) get_option(STORED_DEFAULT_OPTION, '');
    $settings = get_option(OPTION, []);

    if ($stored === '' && is_array($settings)) {
        $stored = (string) ($settings['base_language'] ?? '');
    }

    if ($stored !== '' && $stored !== $now && is_language($stored)) {
        swap_options($stored, $now);
        swap_menus($now);
    }

    if ((string) get_option(STORED_DEFAULT_OPTION, '') !== $now) {
        update_option(STORED_DEFAULT_OPTION, $now, true);
    }

    if (is_array($settings) && array_key_exists('base_language', $settings)) {
        unset($settings['base_language']);
        update_option(OPTION, $settings);
    }
}

/**
 * The options of two languages swapped: the old default's texts go to
 * `options_<old>_…`, the new default's come to `options_…`. What's the same in
 * every language (`copy`) stays where it is: there's one, read by all. A text the
 * new default hasn't translated is empty there, as any language's.
 */
function swap_options(string $old, string $new): void
{
    global $wpdb;

    $rows = [];

    foreach ((array) $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'options\\_%' OR option_name LIKE '\\_options\\_%'") as $row) {
        $rows[(string) $row->option_name] = $row->option_value;
    }

    $others = array_map(fn ($code) => 'options_' . $code . '_', codes());
    $names  = [];

    foreach (array_keys($rows) as $key) {
        $key = ltrim($key, '_');

        if (str_starts_with($key, "options_{$new}_")) {
            $names[substr($key, strlen("options_{$new}_"))] = true;
        } elseif (!array_filter($others, fn ($prefix) => str_starts_with($key, $prefix))) {
            $names[substr($key, strlen('options_'))] = true;
        }
    }

    $write = function (string $name, $value) use ($wpdb) {
        if ($value === null) {
            $wpdb->delete($wpdb->options, ['option_name' => $name]);

            return;
        }

        if ($wpdb->update($wpdb->options, ['option_value' => $value], ['option_name' => $name]) === 0 && $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $name)) === '0') {
            $wpdb->insert($wpdb->options, ['option_name' => $name, 'option_value' => $value, 'autoload' => 'off']);
        }
    };

    foreach (array_keys($names) as $name) {
        $reference = $rows["_options_{$name}"] ?? $rows["_options_{$new}_{$name}"] ?? null;
        $field     = is_string($reference) && function_exists('acf_get_field') ? acf_get_field($reference) : null;

        if (!is_array($field) || field_policy($field) === 'copy' || in_array($field['type'] ?? '', CONTAINER_TYPES, true)) {
            continue;
        }

        foreach (['', '_'] as $mark) {
            $write("{$mark}options_{$old}_{$name}", $rows["{$mark}options_{$name}"] ?? null);
            $write("{$mark}options_{$name}", $rows["{$mark}options_{$new}_{$name}"] ?? null);
            $write("{$mark}options_{$new}_{$name}", null);
        }
    }

    wp_cache_flush();

    if (function_exists('acf_get_store')) {
        acf_get_store('values')->reset();
    }
}

/**
 * The locations with the new default: each holds its menu's translation in it,
 * which becomes its group's original. A menu with none stays, for the other
 * languages to reach their menus through it.
 */
function swap_menus(string $new): void
{
    $locations = get_theme_mods()['nav_menu_locations'] ?? null;

    if (!is_array($locations)) {
        return;
    }

    foreach ($locations as $location => $menu) {
        $to = (int) $menu > 0 ? translation((int) $menu, $new, false, 'term') : null;

        if ($to) {
            $locations[$location] = $to;
            make_original($to, 'term');
        }
    }

    set_theme_mod('nav_menu_locations', $locations);
}

/**
 * A site from before linked menus: its per-language locations become links, once.
 * On `init`, once the menus' taxonomy is there, and before the default is checked.
 */
function upgrade_menus(): void
{
    if (get_option(MENUS_OPTION)) {
        return;
    }

    $settings = get_option(OPTION, []);
    $stored   = (string) get_option(STORED_DEFAULT_OPTION, '') ?: (is_array($settings) ? (string) ($settings['base_language'] ?? '') : '');

    link_menus_from_locations($stored);
    update_option(MENUS_OPTION, 1, true);
}
