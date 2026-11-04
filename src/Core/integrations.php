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
