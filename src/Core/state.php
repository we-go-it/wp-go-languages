<?php

namespace WpGo\Languages\Core;

/**
 * The current language: what the request is in, and what switch() makes it
 * for a while.
 *
 * request.php sets it once, as the plugin loads, from the URL (or, in the
 * admin, the post being edited and the language picked in the admin bar).
 * switch() and restore() then move it, stacked, for code that works in
 * another language for a moment: an export writing each language's sheet, a
 * REST answer for `?lang=en`.
 *
 * What follows it: the links (home_url() carries its segment, on the site),
 * the queries (only its posts), ACF's options (`options_en`) and the site's
 * texts. The locale does not: switching does not reload translations, as
 * WPML's switch did not either.
 */
final class State
{
    /** The language the request is in. */
    public static ?string $request = null;

    /**
     * Where the request is: `site` (a page, a feed, the sitemap), `rest`,
     * `ajax` (admin-ajax.php), `admin` or `cli`.
     */
    public static string $context = 'site';

    /**
     * Whether the request is the site's: a page, a REST call or an AJAX call
     * from one. The site's URLs then carry the language's segment.
     */
    public static bool $front = true;

    /**
     * The language queries keep to, or null for all of them: the request's,
     * but for the admin's lists with "All languages" picked.
     */
    public static ?string $filter = null;

    /** @var list<string> What switch() replaced, last first out. */
    public static array $stack = [];

    /** The current language, while switched. */
    public static ?string $switched = null;

    /**
     * The language a post being inserted will have, before it has one:
     * wp_unique_post_slug() runs inside wp_insert_post(), before any term
     * can be set (translate.php).
     */
    public static ?string $inserting = null;
}

/**
 * The current language's code.
 */
function current(): string
{
    $code = State::$switched ?? State::$request;

    return is_language($code) ? $code : default_language();
}

/**
 * The language queries keep to, or null for every language: the current one,
 * but for the admin with "All languages" picked.
 */
function filter_language(): ?string
{
    if (State::$switched !== null) {
        return current();
    }

    return is_language(State::$filter) ? State::$filter : null;
}

/**
 * Make `$code` the current language until restore().
 */
function switch_language(string $code): void
{
    State::$stack[] = State::$switched ?? '';
    State::$switched = is_language($code) ? $code : default_language();

    sync_acf();

    /**
     * The current language changed (switch or restore).
     *
     * @param string $code The language now current.
     */
    do_action('wp_go_languages/switched', current());
}

/**
 * Back to the language before the last switch_language().
 */
function restore_language(): void
{
    if (!State::$stack) {
        return;
    }

    $previous        = array_pop(State::$stack);
    State::$switched = $previous === '' ? null : $previous;

    sync_acf();

    do_action('wp_go_languages/switched', current());
}

/**
 * Run `$callback` in `$code`, and switch back whatever it does.
 *
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function in_language(string $code, callable $callback): mixed
{
    switch_language($code);

    try {
        return $callback();
    } finally {
        restore_language();
    }
}

/**
 * ACF's language, for the options it stores per language (`options_en`).
 *
 * ACF appends `_<current_language>` to `options` when it differs from
 * `default_language` (acf_get_valid_post_id()): the two settings are what
 * ACFML used to set. Set rather than filtered, as the theme switches them
 * itself for a moment (`wg_option_page()` falling back to the default
 * language) and a filter would override that.
 */
function sync_acf(): void
{
    if (!function_exists('acf_update_setting') || !did_action('acf/init')) {
        return;
    }

    // ACF suffixes `options` for any other language
    acf_update_setting('default_language', default_language());
    acf_update_setting('current_language', current());
}
