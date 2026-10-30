<?php

namespace WpGo\Languages\Core;

/**
 * What else follows the language: WordPress's locale, the menus, ACF.
 */

/**
 * The site's locale in the request's language, on the site and in its REST
 * and AJAX calls. The admin keeps its own (the user's): get_user_locale()
 * is not filtered here.
 */
function filter_locale(string $locale): string
{
    if (!State::$front) {
        return $locale;
    }

    return language(current())['locale'] ?? $locale;
}

/**
 * Menus per language: each menu location the theme registers (`main`, the
 * base_language()'s) gets one per other language (`main___en`), "Main menu
 * (English)" in Appearance → Menus. A language's menu is then whatever is
 * given its location.
 *
 * Only off the site (the admin, the CLI): on a page and in REST answers the
 * theme's location is the language's (filter_menu_locations()), and code that
 * lists the locations (the content API's menus) sees only the theme's.
 */
function register_menu_locations(): void
{
    if (State::$front) {
        return;
    }

    $locations = get_registered_nav_menus();

    foreach (languages() as $code => $language) {
        if ($code === base_language()) {
            continue;
        }

        $extra = [];

        foreach ($locations as $location => $description) {
            if (str_contains((string) $location, '___')) {
                continue;
            }

            $extra[menu_location($location, $code)] = sprintf('%s (%s)', $description, $language['name']);
        }

        if ($extra) {
            register_nav_menus($extra);
        }
    }
}

/**
 * A location's name in a language: itself in base_language(), the first
 * default (the default changed, the menus stay where they are).
 */
function menu_location(string $location, string $code): string
{
    return $code === base_language() ? $location : $location . '___' . $code;
}

/**
 * On the site, a location is the current language's: `main` reads what
 * `main___en` is given. No menu given, none shown, as a page with no
 * translation: an Italian menu on an English page would lead to Italian
 * pages.
 *
 * @param mixed $locations
 * @return mixed
 */
function filter_menu_locations(mixed $locations): mixed
{
    if (!is_array($locations) || !State::$front) {
        return $locations;
    }

    $code = current();

    if ($code === base_language()) {
        return $locations;
    }

    foreach (array_keys(get_registered_nav_menus()) as $location) {
        if (str_contains((string) $location, '___')) {
            continue;
        }

        $locations[$location] = (int) ($locations[menu_location((string) $location, $code)] ?? 0);
    }

    return $locations;
}
