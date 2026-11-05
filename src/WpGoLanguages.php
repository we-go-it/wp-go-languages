<?php

namespace WpGo\Languages;

use WpGo\Languages\Core;

/**
 * The plugin as a theme or another plugin calls it: static, and global,
 * aliased to `WpGoLanguages` (boot.php), so no `use` is needed.
 *
 *   WpGoLanguages::current()                  // 'en'
 *   WpGoLanguages::translation($id, 'it')     // the Italian page, or null
 *   WpGoLanguages::in('en', fn () => …)       // run in English
 *
 * Everything under WpGo\Languages\Core is the plugin's own: this is what it
 * promises to keep.
 */
final class WpGoLanguages
{
    /**
     * The languages, in order, by code: `{ code, locale, name, slug, default }`.
     *
     * @return array<string,array{code:string,locale:string,name:string,slug:string,default:bool}>
     */
    public static function languages(): array
    {
        return Core\languages();
    }

    /**
     * The default language's code.
     */
    public static function default(): string
    {
        return Core\default_language();
    }

    /**
     * The current language's code.
     */
    public static function current(): string
    {
        return Core\current();
    }

    /**
     * The language queries keep to, or null for every language: the current
     * one, but in the admin with "All languages" picked.
     */
    public static function filter(): ?string
    {
        return Core\filter_language();
    }

    /**
     * Whether a code is one of the site's languages.
     */
    public static function is_language(?string $code): bool
    {
        return Core\is_language($code);
    }

    /**
     * Make a language current until restore().
     */
    public static function switch(string $code): void
    {
        Core\switch_language($code);
    }

    /**
     * Back to the language before the last switch().
     */
    public static function restore(): void
    {
        Core\restore_language();
    }

    /**
     * Run a callback in a language, and switch back.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function in(string $code, callable $callback): mixed
    {
        return Core\in_language($code, $callback);
    }

    /**
     * Whether posts of a type have a language.
     */
    public static function is_translated(string $post_type): bool
    {
        return Core\is_translated_post_type($post_type);
    }

    /**
     * Whether terms of a taxonomy have a language.
     */
    public static function is_translated_taxonomy(string $taxonomy): bool
    {
        return Core\is_translated_taxonomy($taxonomy);
    }

    /**
     * A post's (or term's, `$kind = 'term'`) language, or null.
     */
    public static function language_of(int $id, string $kind = 'post'): ?string
    {
        return Core\language_of($id, $kind);
    }

    /**
     * Give a post (or term) a language.
     */
    public static function set_language(int $id, string $code, string $kind = 'post'): bool
    {
        return Core\set_language($id, $code, $kind);
    }

    /**
     * A post's translations, itself included: `[code => id]`.
     *
     * @return array<string,int>
     */
    public static function translations(int $id, string $kind = 'post'): array
    {
        return Core\translations($id, $kind);
    }

    /**
     * A post in another language, or null (the post itself, with
     * `$fallback`).
     */
    public static function translation(int $id, string $code, bool $fallback = false, string $kind = 'post'): ?int
    {
        return Core\translation($id, $code, $fallback, $kind);
    }

    /**
     * The original a post was translated from: itself, for an original.
     */
    public static function original(int $id, string $kind = 'post'): int
    {
        return Core\original($id, $kind);
    }

    /**
     * Make a post a translation of another: true, or why it can't be.
     * (bool rather than true, which PHP 8.1 can't declare on its own.)
     */
    public static function link(int $id, int $original_id, string $kind = 'post'): bool|\WP_Error
    {
        return Core\link($id, $original_id, $kind);
    }

    /**
     * A post's translation visitors can reach, or null: published, in `$code`.
     * translation() finds a draft too.
     */
    public static function published_translation(int $id, string $code): ?int
    {
        return Core\published_translation($id, $code);
    }

    /**
     * A translation's mode, `independent` or `blocks`; null for an original.
     */
    public static function mode(int $id): ?string
    {
        return Core\mode_of($id);
    }

    /**
     * Translate a post: a draft copy in `$code`, `independent` or `blocks`.
     */
    public static function create_translation(int $id, string $code, string $mode = 'independent', bool $copy = true): int|\WP_Error
    {
        return Core\create_translation($id, $code, $mode, $copy);
    }

    /**
     * A language's home URL, `$path` after it.
     */
    public static function home_url(string $code, string $path = '/'): string
    {
        return Core\home_url_for($code, $path);
    }

    /**
     * A post's URL: its own, or its translation's in `$code` (null if it has
     * none).
     */
    public static function permalink(int|\WP_Post $post, ?string $code = null): ?string
    {
        return Core\permalink($post, $code);
    }

    /**
     * A URL of the site in another language: its language segment swapped.
     */
    public static function url_in(string $url, string $code): string
    {
        return Core\url_in($url, $code);
    }

    /**
     * The post a URL of the site is, in the URL's language, or 0.
     * url_to_postid() answers the same, with the plugin on.
     */
    public static function url_to_post_id(string $url): int
    {
        return Core\url_to_post_id($url);
    }

    /**
     * The language a URL of the site is in, by its segment, or null for a
     * URL off the site.
     */
    public static function url_language(string $url): ?string
    {
        return Core\url_language($url);
    }

    /**
     * The URL of a language's flag (`flags/gb.svg`), or null if it has none.
     */
    public static function flag_url(string $code): ?string
    {
        return Core\flag_url($code);
    }

    /**
     * The language switcher's entries for a page (the queried one by
     * default), by code: the keys WPML's `wpml_active_languages` had.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function switcher(int|\WP_Post|null $post = null): array
    {
        return Core\switcher($post);
    }
}
