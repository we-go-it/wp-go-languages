<?php

namespace WpGo\Languages\Core;

/**
 * The site's URLs, per language: the default language at the root, every
 * other under its segment (`/en/…`). With default_in_url() the default is
 * under its own too (`/it/…`), and a URL without one is redirected there
 * (redirect_unprefixed()).
 *
 * Two rules:
 *
 *   - **home_url() carries the current language**, on the site and in REST
 *     answers (State::$front). WordPress reads its home path from home_url()
 *     to route a request (WP::parse_request()), so `/en/` is taken off a path
 *     before the rewrite rules see it: every rule (pages, feeds, search,
 *     `/en/wp-json/`) works in every language, and none is added. The admin's
 *     home_url() is never touched.
 *   - **an item's link is in its own language**, whatever the current one: a
 *     page's permalink is moved from the language home_url() gave it to the
 *     page's (url_in()). An English page linked from an Italian one is at
 *     `/en/…`.
 *
 * The raw home, `get_option('home')`, is the default language's, and what
 * every URL here is built from.
 */

/**
 * home_url() with the current language's segment, where the site's links
 * follow it.
 */
function filter_home_url(string $url, string $path = '', ?string $scheme = null): string
{
    // on the site and in REST answers, not in the admin: every link there is the item's own
    if (!State::$front) {
        return $url;
    }

    return url_in($url, current());
}

/**
 * The root of a language's URLs, no slash at the end: the raw home, plus the
 * segment for any language but the default (and the default's own, with
 * default_in_url()).
 */
function language_base(string $code, ?string $scheme = null): string
{
    $home = untrailingslashit(set_url_scheme((string) get_option('home'), $scheme));
    $info = language($code);

    if ($info === null || ($info['default'] && !default_in_url())) {
        return $home;
    }

    return $home . '/' . $info['slug'];
}

/**
 * A language's home page URL, with a slash at the end, and `$path` after it.
 */
function home_url_for(string $code, string $path = '/'): string
{
    return language_base($code) . '/' . ltrim($path, '/');
}

/**
 * The language a URL of the site is in, by its segment: the default language
 * for a URL with none, null for a URL off the site.
 */
function url_language(string $url): ?string
{
    $home = untrailingslashit((string) get_option('home'));
    $bare = preg_replace('#^https?:#', '', $url);
    $root = preg_replace('#^https?:#', '', $home);

    if ($bare === null || $root === null || !str_starts_with($bare, $root)) {
        return null;
    }

    return path_language($url) ?? default_language();
}

/**
 * The same URL in another language: its segment swapped for `$code`'s (one
 * added to a URL with none, the raw home's). A URL off the site comes back
 * as it is.
 */
function url_in(string $url, string $code): string
{
    if (url_language($url) === null) {
        return $url;
    }

    $scheme   = parse_url($url, PHP_URL_SCHEME) ?: null;
    $from_url = url_base($url);
    $to_url   = language_base($code, $scheme);

    if ($from_url === $to_url) {
        return $url;
    }

    $rest = substr($url, strlen($from_url));

    // `/en` on its own, or followed by a path, a query or a fragment: not
    // `/enterprise`.
    if ($rest !== '' && !in_array($rest[0], ['/', '?', '#'], true)) {
        return $url;
    }

    return $to_url . $rest;
}

/**
 * The root a URL of the site is under, as it is written: its language's
 * base, or the raw home for a URL with no segment.
 */
function url_base(string $url): string
{
    $scheme  = parse_url($url, PHP_URL_SCHEME) ?: null;
    $segment = path_language($url);

    return $segment !== null ? language_base($segment, $scheme) : untrailingslashit(set_url_scheme((string) get_option('home'), $scheme));
}

/**
 * A URL of the site with no language segment: where WordPress's own routing
 * finds it, from the raw home.
 */
function url_unprefixed(string $url): string
{
    $from_url = url_base($url);
    $home     = untrailingslashit(set_url_scheme((string) get_option('home'), parse_url($url, PHP_URL_SCHEME) ?: null));

    return $home . substr($url, strlen($from_url));
}

/**
 * With default_in_url(), a page's URL without a language segment, the root
 * included, moves to the default language's (301): `/chi-siamo/` →
 * `/it/chi-siamo/`. Not robots.txt, the favicon or WordPress's sitemap
 * (`/wp-sitemap.xml`, served later on this hook), which live at the root;
 * Yoast's sitemap is answered before, on `pre_get_posts`.
 */
function redirect_unprefixed(): void
{
    if (!default_in_url() || State::$context !== 'site' || is_robots() || is_favicon() || get_query_var('sitemap')) {
        return;
    }

    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        return;
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

    if (path_language($uri) !== null) {
        return;
    }

    $home_path = untrailingslashit((string) parse_url((string) get_option('home'), PHP_URL_PATH));
    $relative  = $home_path !== '' && str_starts_with($uri, $home_path) ? substr($uri, strlen($home_path)) : $uri;

    wp_safe_redirect(language_base(default_language()) . '/' . ltrim($relative, '/'), 301, 'WP-Go Languages');
    exit;
}

/**
 * A hidden language (is_hidden_language()) answers 404 to a visitor who can't
 * edit pages: no page of it is reachable until it's ready.
 */
function hide_language(): void
{
    if (State::$context !== 'site' || shows_language(current()) || is_admin()) {
        return;
    }

    global $wp_query;

    $wp_query->set_404();
    status_header(404);
    nocache_headers();
}

/**
 * A post's link in its own language: page_link, post_link, post_type_link.
 *
 * The front page's translations are each language's home, as the front page
 * is the root: `/en/`, not `/en/home/`.
 */
function filter_post_link(string $link, int|\WP_Post $post): string
{
    $post = get_post($post);

    if (!$post instanceof \WP_Post || !is_translated_post_type($post->post_type)) {
        return $link;
    }

    $code = language_of($post->ID) ?? State::$inserting;

    if ($code === null) {
        return $link;
    }

    if ($post->post_type === 'page' && get_option('show_on_front') === 'page') {
        $front = raw_front_page();

        if ($front && $post->post_status === 'publish' && translation($front, $code) === $post->ID) {
            return home_url_for($code);
        }
    }

    return url_in($link, $code);
}

/**
 * A term's link in its own language, for a translated taxonomy.
 */
function filter_term_link(string $link, \WP_Term $term): string
{
    if (!is_translated_taxonomy($term->taxonomy)) {
        return $link;
    }

    $code = language_of($term->term_id, 'term');

    return $code === null ? $link : url_in($link, $code);
}

/**
 * The post a URL of the site is, in the URL's language: url_to_postid(), which
 * knows neither the language's segment nor that a slug can repeat across
 * languages.
 */
function url_to_post_id(string $url): int
{
    return $GLOBALS['wgl_url_posts'][$url] ??= find_url_post($url);
}

/** What the addresses read so far lead to is forgotten as a post changes. */
function forget_url_posts(): void
{
    $GLOBALS['wgl_url_posts'] = [];
}

function find_url_post(string $url): int
{
    $code = url_language($url);

    if ($code === null) {
        return 0;
    }

    // The path after the language's base: `chi-siamo`, `parent/child`.
    $path = trim((string) parse_url(substr($url, strlen(url_base($url))), PHP_URL_PATH), '/');

    if ($path === '') {
        $front = get_option('show_on_front') === 'page' ? raw_front_page() : 0;

        return $front ? (int) (translation($front, $code) ?? 0) : 0;
    }

    if (is_translated_post_type('page')) {
        $id = page_by_path($path, $code);

        if ($id) {
            return $id;
        }
    }

    // Anything else, as WordPress finds it from the URL with no segment,
    // kept only if it is in the URL's language (or has none).
    $id = (int) resolving(fn () => url_to_postid(url_unprefixed($url)));

    if ($id && is_translatable($id) && language_of($id) !== $code) {
        return 0;
    }

    return $id;
}

/**
 * url_to_postid() in every language: the URL resolved here first, then given
 * back as `?p=` (or `?page_id=`), which url_to_postid() answers right away.
 */
function filter_url_to_postid(string $url): string
{
    if (resolving() || preg_match('#[?&](p|page_id|attachment_id)=\d+#', $url)) {
        return $url;
    }

    $id = (int) resolving(fn () => url_to_post_id($url));

    if (!$id) {
        return $url;
    }

    $param = get_post_type($id) === 'page' ? 'page_id' : 'p';

    return add_query_arg($param, $id, $url);
}

/**
 * Run `$callback` with url_to_postid() left as WordPress has it, and the
 * site's home unprefixed: without a callback, whether that is running.
 */
function resolving(?callable $callback = null): mixed
{
    static $depth = 0;

    if ($callback === null) {
        return $depth > 0;
    }

    $depth++;
    $front        = State::$front;
    State::$front = false;

    try {
        return $callback();
    } finally {
        State::$front = $front;
        $depth--;
    }
}

/**
 * A post's URL in a language: its translation's permalink, or null if it has
 * none. With no language, its own.
 */
function permalink(int|\WP_Post $post, ?string $code = null): ?string
{
    $post = get_post($post);

    if (!$post instanceof \WP_Post) {
        return null;
    }

    if ($code === null) {
        return (string) get_permalink($post);
    }

    $id = translation($post->ID, $code);

    return $id ? (string) get_permalink($id) : null;
}

/**
 * The language switcher's entries, one per language, in order: what WPML's
 * `wpml_active_languages` gave, with the same keys, so a theme reads them
 * the same way.
 *
 *   code, language_code   the language
 *   url                   this page in that language: its translation, if
 *                         published, else the language's home
 *   active                whether it is the current language
 *   missing               whether this page has no such translation
 *   id                    the language's position, from 1 (WPML sorted by it)
 *   native_name           the language's own name
 *   translated_name       the same: names are not translated
 *   default_locale        its locale
 *   country_flag_url      its flag (flag_url()), empty if it has none
 *
 * The page is the queried one, or the term whose archive it is; pass a post for
 * any other. A term with no posts in a language has an empty archive there: its
 * language's home instead.
 *
 * @return array<string,array<string,mixed>>
 */
function switcher(int|\WP_Post|null $post = null): array
{
    if ($post !== null) {
        $post       = get_post($post);
        $item       = $post instanceof \WP_Post && is_translated_post_type($post->post_type) ? [$post->ID, 'post'] : null;
        $translated = $post instanceof \WP_Post;
    } else {
        $item       = queried_item();
        $translated = $item !== null || is_singular();
    }

    $translations = $item ? translations($item[0], $item[1]) : [];

    $items    = [];
    $position = 0;

    foreach (languages() as $code => $language) {
        $position++;

        if (!shows_language($code)) {
            continue;
        }

        $url = $item ? translated_url($translations[$code] ?? 0, $item[1]) : null;

        $items[$code] = [
            'code'             => $code,
            'language_code'    => $code,
            'url'              => $url ?? home_url_for($code),
            'active'           => $code === current() ? 1 : 0,
            'missing'          => $translated && $url === null ? 1 : 0,
            'id'               => $position,
            'native_name'      => $language['name'],
            'translated_name'  => $language['name'],
            'default_locale'   => $language['locale'],
            'country_flag_url' => (string) flag_url($code),
        ];
    }

    return $items;
}

/**
 * A translation's address, or null if visitors can't reach it there: a post not
 * published, a term with no posts.
 */
function translated_url(int $id, string $kind): ?string
{
    if ($id <= 0) {
        return null;
    }

    if ($kind === 'term') {
        $term = get_term($id);
        $link = $term instanceof \WP_Term && $term->count > 0 ? get_term_link($term) : null;

        return is_string($link) ? $link : null;
    }

    return get_post_status($id) === 'publish' ? (string) get_permalink($id) : null;
}
