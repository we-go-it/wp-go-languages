<?php

namespace WpGo\Languages\Core;

/**
 * Which language a request is in, and where it is from.
 *
 * Read once, as the plugin loads, so the locale and every URL built after are
 * the language's:
 *
 *   site   the path's first segment, `/en/…`, or the default language
 *   rest   `?lang=`, the path's segment (`/en/wp-json/…`) or the default;
 *          a call from the block editor (`_locale=user`) is the admin's
 *   ajax   `lang` in the request, else the page it was made from (the
 *          referer's segment, or the post edited in the admin)
 *   admin  the post edited, else `?lang=` (remembered per user from the
 *          admin bar's switch), else the user's choice, with `all`
 *   cli    the default language
 *
 * Never a cookie or the browser's language: a page's language is its URL, so
 * the static cache (one file per path) stays right.
 *
 * What depends on a post (its language, in the admin) waits for `init`, once
 * the taxonomies are there: resolve_admin().
 */

const ADMIN_FILTER_META = 'wgl_admin_language';

/**
 * Read the request: State::$request, $context, $front and $filter.
 */
function detect(): void
{
    $segment = path_language((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    $asked   = asked_language();

    if (defined('WP_CLI') && WP_CLI) {
        State::$context = 'cli';
        State::$front   = false;
        State::$request = default_language();
        State::$filter  = State::$request;

        return;
    }

    if (wp_doing_ajax()) {
        State::$context = 'ajax';

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $from_admin = $referer !== '' && str_contains((string) parse_url($referer, PHP_URL_PATH), '/wp-admin/');

        if ($asked !== null) {
            State::$request = $asked === 'all' ? default_language() : $asked;
            State::$filter  = $asked === 'all' ? null : $asked;
            State::$front   = !$from_admin;

            return;
        }

        if ($from_admin) {
            // The admin's: the post edited, or the filter (resolve_admin()).
            State::$front = false;

            return;
        }

        State::$front   = true;
        State::$request = path_language($referer) ?? default_language();
        State::$filter  = State::$request;

        return;
    }

    if (is_rest_path((string) ($_SERVER['REQUEST_URI'] ?? '/'))) {
        State::$context = 'rest';

        // The block editor's own calls: the admin's language, not the site's.
        if (isset($_GET['_locale']) && $_GET['_locale'] === 'user') {
            State::$front = false;

            if ($asked !== null) {
                State::$request = $asked === 'all' ? default_language() : $asked;
                State::$filter  = $asked === 'all' ? null : $asked;
            }

            return;
        }

        State::$front   = true;
        State::$request = ($asked !== null && $asked !== 'all' ? $asked : null) ?? $segment ?? default_language();
        State::$filter  = $asked === 'all' ? null : State::$request;

        return;
    }

    if (is_admin()) {
        State::$context = 'admin';
        State::$front   = false;

        return;
    }

    State::$context = 'site';
    State::$front   = true;
    State::$request = $segment ?? default_language();
    State::$filter  = State::$request;
}

/**
 * What the admin is in, once a post's language can be read (`init`): the post
 * edited, else `?lang=` (a post being made in a language,
 * `post-new.php?lang=en`), else the language picked in the admin bar,
 * remembered per user.
 *
 * Also for the admin's AJAX and REST calls, which say the post they are for
 * in their referer. ACF's language follows on `acf/init`, which comes after.
 */
function resolve_admin(): void
{
    if (State::$front || State::$context === 'cli') {
        return;
    }

    $asked = asked_language();

    if (State::$context === 'admin' && $asked !== null && is_admin_bar_switch()) {
        update_user_meta(get_current_user_id(), ADMIN_FILTER_META, $asked);
    }

    if (State::$request === null || State::$context === 'admin') {
        $post_id = edited_post_id();
        $own     = $post_id ? language_of($post_id) : null;
        $chosen  = admin_choice();

        if ($own !== null) {
            State::$request = $own;
            State::$filter  = $own;
        } elseif (State::$context === 'admin' && $asked !== null) {
            // For this request only, unless remembered above.
            State::$request = $asked === 'all' ? default_language() : $asked;
            State::$filter  = $asked === 'all' ? null : $asked;
        } else {
            State::$request = $chosen ?? default_language();
            State::$filter  = $chosen;
        }
    }
}

/**
 * Whether `?lang=` here is the admin bar's switch, to remember for the user:
 * a screen opened (GET) with no post edited. Not a new post's `lang` (its
 * language), nor admin-post.php's (an action's, as making a translation).
 */
function is_admin_bar_switch(): bool
{
    if (!get_current_user_id() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return false;
    }

    $screen = (string) ($GLOBALS['pagenow'] ?? '');

    return !in_array($screen, ['post-new.php', 'post.php', 'admin-post.php'], true) && !edited_post_id();
}

/**
 * The language picked in the admin bar: a code, or null for all languages.
 */
function admin_choice(): ?string
{
    $user = get_current_user_id();

    if (!$user) {
        return null;
    }

    $chosen = (string) get_user_meta($user, ADMIN_FILTER_META, true);

    if ($chosen === '') {
        return default_language();
    }

    return is_language($chosen) ? $chosen : null;
}

/**
 * The language `lang` asks for, in the query string or a POST: a code, `all`,
 * or null if it names neither.
 */
function asked_language(): ?string
{
    $asked = $_REQUEST['lang'] ?? null;

    if (!is_string($asked)) {
        return null;
    }

    $asked = sanitize_key($asked);

    return $asked === 'all' || is_language($asked) ? $asked : null;
}

/**
 * The language a URL's path names by its first segment (after the home
 * path), or null for a path with none: the default language's, unless
 * default_in_url().
 */
function path_language(string $url): ?string
{
    $path      = (string) parse_url($url, PHP_URL_PATH);
    $home_path = trim((string) parse_url((string) get_option('home'), PHP_URL_PATH), '/');
    $relative  = ltrim($path, '/');

    if ($home_path !== '') {
        if ($relative !== $home_path && !str_starts_with($relative, $home_path . '/')) {
            return null;
        }

        $relative = ltrim(substr($relative, strlen($home_path)), '/');
    }

    $segment = strtok($relative, '/');

    return is_string($segment) && $segment !== '' ? language_by_slug(rawurldecode($segment)) : null;
}

/**
 * Whether a request is the REST API's: `/wp-json/…`, `/en/wp-json/…` or
 * `?rest_route=`. REST_REQUEST is only defined later, in parse_request.
 */
function is_rest_path(string $uri): bool
{
    if (isset($_GET['rest_route'])) {
        return true;
    }

    $path      = trim((string) parse_url($uri, PHP_URL_PATH), '/');
    $home_path = trim((string) parse_url((string) get_option('home'), PHP_URL_PATH), '/');

    if ($home_path !== '' && str_starts_with($path, $home_path)) {
        $path = ltrim(substr($path, strlen($home_path)), '/');
    }

    $segments = explode('/', $path);

    if ($segments && language_by_slug($segments[0]) !== null) {
        array_shift($segments);
    }

    return ($segments[0] ?? '') === rest_get_url_prefix();
}

/**
 * The post the admin is editing, or the post an admin-ajax or editor REST
 * call is for (its referer's `post=`): 0 if none.
 */
function edited_post_id(): int
{
    if (State::$context === 'admin') {
        $id = (int) ($_GET['post'] ?? $_POST['post_ID'] ?? 0);

        return $id > 0 && is_translatable($id) ? $id : 0;
    }

    // `post_id` is what ACF's field AJAX sends; the referer, the editor's.
    $id = (int) ($_REQUEST['post_id'] ?? 0);

    if ($id <= 0) {
        parse_str((string) parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY), $query);
        $id = (int) ($query['post'] ?? 0);
    }

    return $id > 0 && is_translatable($id) ? $id : 0;
}

/**
 * A page's ID from its path, `parent/child`, among the pages in one language:
 * get_page_by_path(), language by language.
 */
function page_by_path(string $path, string $code, string $post_type = 'page'): int
{
    global $wpdb;

    $parts = array_values(array_filter(array_map(
        fn ($part) => sanitize_title_for_query(rawurldecode($part)),
        explode('/', trim($path, '/'))
    ), 'strlen'));

    if (!$parts) {
        return 0;
    }

    $in    = implode(',', array_fill(0, count($parts), '%s'));
    $pages = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_name, post_parent FROM {$wpdb->posts}
         WHERE post_name IN ($in) AND post_type = %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit')",
        array_merge($parts, [$post_type])
    ), OBJECT_K);

    $leaf = end($parts);

    foreach ($pages as $page) {
        if ($page->post_name !== $leaf || language_of((int) $page->ID) !== $code) {
            continue;
        }

        // Walk up: each parent must be the path's previous part.
        $ancestor = $page;
        $ok       = true;

        for ($i = count($parts) - 2; $i >= 0; $i--) {
            $parent = $pages[$ancestor->post_parent] ?? null;

            if (!$parent || $parent->post_name !== $parts[$i]) {
                $ok = false;

                break;
            }

            $ancestor = $parent;
        }

        if ($ok && (int) $ancestor->post_parent === 0) {
            return (int) $page->ID;
        }
    }

    return 0;
}

/**
 * The front page, and the posts page, in the request's language: WordPress
 * reads them from two options, one page each, the original.
 */
function front_page_option(mixed $value): mixed
{
    $id = (int) $value;

    if ($id <= 0 || !State::$front) {
        return $value;
    }

    return translation($id, current()) ?? $value;
}

/**
 * The front page's original: `page_on_front` as stored, whatever language the
 * request is in.
 */
function raw_front_page(): int
{
    $hooked = remove_filter('option_page_on_front', __NAMESPACE__ . '\\front_page_option');
    $id     = (int) get_option('page_on_front');

    if ($hooked) {
        add_filter('option_page_on_front', __NAMESPACE__ . '\\front_page_option');
    }

    return $id;
}
