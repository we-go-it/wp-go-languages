<?php

namespace WpGo\Languages\Core;

/**
 * Menus per language. A menu has a language, picked when it's made, and a menu in
 * another language than the default may be a translation of one in the default.
 * They're a group as terms are (storage.php), the default language's its original.
 *
 *   - A menu location holds one menu, as the theme declares it. On the site it
 *     gives that menu's translation in the current language; a language with none
 *     shows no menu, rather than one leading to another language's pages.
 *   - A translated menu is its own, with its own items, unless it follows its
 *     original (MENU_FOLLOW_META): it then has none of its own to edit, and on the
 *     site shows the original's, each link in its language. A page or term with no
 *     published translation is left out, its children moving up. A custom link is
 *     left as it is: it's the editor's job to have a followed menu translate well.
 *
 * nav_menu isn't one of the translated taxonomies: its terms have a language, but
 * no list of them keeps to one.
 */

/**
 * Every menu: not wp_get_nav_menus(), which the Menus screen narrows to a language.
 *
 * @return list<\WP_Term>
 */
function all_menus(): array
{
    $menus = get_terms(['taxonomy' => 'nav_menu', 'hide_empty' => false, 'orderby' => 'name']);

    return is_array($menus) ? array_values($menus) : [];
}

/**
 * The menus of a language, whatever the current one.
 *
 * @return list<\WP_Term>
 */
function menus_in_language(string $code): array
{
    return array_values(array_filter(all_menus(), fn (\WP_Term $menu) => language_of($menu->term_id, 'term') === $code));
}

/**
 * Whether a menu shows its original's structure instead of its own.
 */
function menu_follows(int $menu_id): bool
{
    return (bool) get_term_meta($menu_id, MENU_FOLLOW_META, true) && is_translation($menu_id, 'term');
}

/**
 * On the site, a location is its menu's translation in the current language. A
 * menu with no language is shown as it is.
 *
 * @param mixed $locations
 * @return mixed
 */
function filter_menu_locations(mixed $locations): mixed
{
    if (!is_array($locations) || !State::$front) {
        return $locations;
    }

    foreach ($locations as $location => $menu) {
        $menu = (int) $menu;

        if ($menu > 0 && language_of($menu, 'term') !== null) {
            $locations[$location] = (int) (translation($menu, current(), false, 'term') ?? 0);
        }
    }

    return $locations;
}

/**
 * A following menu's items: its original's, in its language. Not on the Menus
 * screen, where a menu's own items stay what they are.
 *
 * @param mixed               $items
 * @param mixed               $menu
 * @param array<string,mixed> $args
 * @return mixed
 */
function followed_menu_items($items, $menu, array $args = [])
{
    if (!$menu instanceof \WP_Term || ($GLOBALS['pagenow'] ?? '') === 'nav-menus.php' || !menu_follows($menu->term_id)) {
        return $items;
    }

    $code     = (string) language_of($menu->term_id, 'term');
    // without what names this menu's own items: core hands its parsed query here
    $original = wp_get_nav_menu_items(original($menu->term_id, 'term'), array_diff_key($args, ['tax_query' => 1, 'include' => 1]));

    return localize_menu_items(is_array($original) ? $original : [], $code);
}

/**
 * A menu's items in a language: what's left out leaves its children to its own
 * parent.
 *
 * @param list<object> $items
 * @return list<object>
 */
function localize_menu_items(array $items, string $code): array
{
    $parents = [];
    $out     = [];

    foreach ($items as $item) {
        $parents[(int) $item->db_id] = (int) $item->menu_item_parent;
    }

    $left = [];

    foreach ($items as $item) {
        $local = localize_menu_item($item, $code);

        if ($local) {
            $out[] = $local;
        } else {
            $left[(int) $item->db_id] = true;
        }
    }

    foreach ($out as $item) {
        $parent = (int) $item->menu_item_parent;

        while (isset($left[$parent])) {
            $parent = $parents[$parent] ?? 0;
        }

        $item->menu_item_parent = (string) $parent;
    }

    return $out;
}

/**
 * One item in a language, a copy: a page's or a term's link to its published
 * translation, or null with none. Its label is the page's title there, unless the
 * item has one of its own. A custom link, and what isn't translated, as it is.
 */
function localize_menu_item(object $item, string $code): ?object
{
    $item   = clone $item;
    $own    = (string) ($item->post_title ?? '') !== '';
    $object = (string) ($item->object ?? '');

    if (($item->type ?? '') === 'post_type' && is_translated_post_type($object)) {
        $id = published_translation((int) $item->object_id, $code);

        if (!$id) {
            return null;
        }

        $front = get_option('show_on_front') === 'page' ? raw_front_page() : 0;

        $item->object_id = (string) $id;
        $item->url       = $front && translation($front, $code) === $id ? home_url_for($code) : (string) get_permalink($id);
        $item->title     = $own ? $item->title : get_the_title($id);
    } elseif (($item->type ?? '') === 'taxonomy' && is_translated_taxonomy($object)) {
        $id   = translation((int) $item->object_id, $code, false, 'term');
        $link = $id ? get_term_link($id, $object) : null;

        if (!is_string($link)) {
            return null;
        }

        $item->object_id = (string) $id;
        $item->url       = $link;
        $item->title     = $own ? $item->title : (string) get_term($id)->name;
    }

    return $item;
}

/**
 * The per-language locations of before (`main___en`) as links: `main` holds the
 * language that had the suffixless menus, each `main___en` becomes its English
 * translation, and the extra locations go. Any other menu gets the default
 * language. Run once (upgrade.php), and after a move from WPML.
 */
function link_menus_from_locations(?string $bare = null): void
{
    $bare      = $bare !== null && is_language($bare) ? $bare : default_language();
    $locations = (array) (get_theme_mods()['nav_menu_locations'] ?? []);

    foreach ($locations as $location => $menu) {
        $location = (string) $location;

        if (str_contains($location, '___')) {
            continue;
        }

        $first = (int) $menu;

        if ($first > 0 && language_of($first, 'term') === null) {
            set_language($first, $bare, 'term');
        }

        foreach (codes() as $code) {
            $other = (int) ($locations[$location . '___' . $code] ?? 0);

            if ($other <= 0) {
                continue;
            }

            // the location says its language, whatever a menu was given by default
            set_language($other, $code, 'term');

            if ($first > 0) {
                link($other, $first, 'term');
            } else {
                $locations[$location] = $first = $other;
            }
        }
    }

    foreach (all_menus() as $menu) {
        if (language_of($menu->term_id, 'term') === null) {
            set_language($menu->term_id, default_language(), 'term');
        }
    }

    set_theme_mod('nav_menu_locations', array_filter($locations, fn ($location) => !str_contains((string) $location, '___'), ARRAY_FILTER_USE_KEY));
}
