<?php

namespace WpGo\Languages\Migrate;

/**
 * What both ways of a migration share (from-wpml.php, to-wpml.php).
 *
 * A migration reads one plugin's data and writes the other's, run in the
 * plugin being moved to: WPML's tables are read while this plugin runs, this
 * plugin's taxonomies and meta while WPML runs. Neither ever removes what it
 * read, so a site can be moved again, either way, and a mistake undone by
 * moving it back.
 *
 * Both first make a plan (read only, what `--dry-run` prints), then carry it
 * out. A plan's groups are the same in both:
 *
 *   ['type' => 'page', 'original' => 12, 'items' => ['it' => 12, 'en' => 34]]
 *
 * one per page (or term) and its translations, an item alone a group of one.
 */

/** A WPML table's full name: `icl_translations` for 'translations'. */
function wpml_table(string $name): string
{
    global $wpdb;

    return $wpdb->prefix . 'icl_' . $name;
}

/** Whether WPML's tables are there: WPML was installed once, active or not. */
function wpml_tables_exist(): bool
{
    global $wpdb;

    $table = wpml_table('translations');

    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
}

/**
 * A group's original: the one item that names no source, else the default
 * language's, else the first.
 *
 * @param array<string,int>  $items   code => id
 * @param array<int,bool>    $sourced id => whether it names a source (WPML's source_language_code, this plugin's `_wgl_source`)
 */
function pick_original(array $items, array $sourced, string $default): int
{
    $free = array_values(array_filter($items, fn ($id) => empty($sourced[$id])));

    if (count($free) === 1) {
        return $free[0];
    }

    return $items[$default] ?? (int) reset($items);
}

/**
 * `IN (%s, %s, …)` for $wpdb->prepare(), for a list that isn't empty.
 *
 * @param list<mixed> $values
 */
function placeholders(array $values, string $format = '%s'): string
{
    return implode(', ', array_fill(0, count($values), $format));
}

/**
 * The plan's counts, as the command prints them: groups, items in them, and
 * the items that are translations.
 *
 * @param list<array{type:string, original:int, items:array<string,int>}> $groups
 * @return array{groups:int, items:int, translations:int}
 */
function count_groups(array $groups): array
{
    $items = array_sum(array_map(fn ($group) => count($group['items']), $groups));
    $linked = array_filter($groups, fn ($group) => count($group['items']) > 1);

    return [
        'groups'       => count($linked),
        'items'        => $items,
        'translations' => array_sum(array_map(fn ($group) => count($group['items']) - 1, $linked)),
    ];
}
