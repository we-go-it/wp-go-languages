<?php

namespace WpGo\Languages\Core;

/**
 * The site's languages and what is translated, from the Languages screen.
 *
 * Stored in one option, `wp_go_languages`:
 *
 *   languages   [{ code, locale, name, slug }], in order, the first the default
 *   default_in_url  whether the default language's URLs have its slug too
 *               (`/it/…`); else they are at the root
 *               (the others `options_en`), the menu locations (`main`, the
 *               others `main___en`). The first default; changing the default
 *               leaves it, so nothing stored moves
 *   post_types  the translated post types
 *   taxonomies  the translated taxonomies
 *   site_texts  { domains, protected }: the Site texts screen's domains (who
 *               edits each), and the prefixes a scan never marks
 *               (src/SiteTexts/scan.php)
 *
 * `code` is what the plugin stores and compares (`it`, `en`), `locale` the
 * WordPress locale the language prints in (`it_IT`, `en_GB`), `name` its own
 * name (`Italiano`, `English`), `slug` its URL segment (`/en/`), the code if
 * empty. The default language's is used only with `default_in_url`. Its
 * flag is its locale's (flag()).
 *
 * A project can set any of it in code instead, with `wp_go_languages/config`,
 * from a must-use plugin: config() reads it once per request, as the plugin
 * loads, to find the request's language (detect()), and config(true) again,
 * after a save. The theme comes too late, after the locale has loaded: a
 * filter added after that first read is taken off, with a notice
 * (drop_late_filters()), so that what the code sets always wins.
 */

const OPTION = 'wp_go_languages';

/**
 * The settings, as stored and filtered, with their defaults.
 *
 * @return array{languages: list<array{code:string,locale:string,name:string,slug:string}>, default_in_url: bool, post_types: list<string>, taxonomies: list<string>}
 */
function config(bool $fresh = false): array
{
    static $config = null;

    if ($config !== null && !$fresh) {
        return $config;
    }

    $stored = get_option(OPTION, []);
    $stored = is_array($stored) ? $stored : [];

    $defaults = [
        // Until the settings are saved, the site's own language alone.
        'languages'      => [default_entry()],
        'default_in_url' => false,
        'post_types'     => ['page', 'wpcf7_contact_form'],
        'taxonomies'     => [],
    ];

    // The callbacks the first read ran: any other is too late.
    config_callbacks(true);

    /**
     * The settings: languages, post types, taxonomies and Site texts', as
     * the Languages screen stores them. A project can fix them in code here,
     * from a must-use plugin: the theme is too late.
     *
     * @param array<string,mixed> $config
     */
    $config = (array) apply_filters('wp_go_languages/config', array_replace($defaults, $stored));

    $config['languages']  = normalize_languages((array) ($config['languages'] ?? []));
    $config['post_types'] = array_values(array_map('strval', (array) ($config['post_types'] ?? [])));
    $config['taxonomies'] = array_values(array_map('strval', (array) ($config['taxonomies'] ?? [])));

    if (!$config['languages']) {
        $config['languages'] = [default_entry()];
    }

    $codes = array_column($config['languages'], 'code');

    $config['default_in_url'] = (bool) ($config['default_in_url'] ?? false);

    return $config;
}

/**
 * The callbacks on `wp_go_languages/config`, as `id => priority`: those of
 * the first read, or with `$first` true, those there now if none was kept.
 *
 * @return array<string,int>
 */
function config_callbacks(bool $first = false): array
{
    static $kept = null;

    if ($kept !== null) {
        return $kept;
    }

    $now = [];
    $hook = $GLOBALS['wp_filter']['wp_go_languages/config'] ?? null;

    if ($hook instanceof \WP_Hook) {
        foreach ($hook->callbacks as $priority => $callbacks) {
            foreach (array_keys($callbacks) as $id) {
                $now[(string) $id] = (int) $priority;
            }
        }
    }

    if ($first) {
        $kept = $now;
    }

    return $now;
}

/**
 * Take off a `wp_go_languages/config` filter added after the settings were
 * read (the theme's, a later plugin's), on `wp_loaded`. The request's
 * language and locale were found without it, so it never applied: left on,
 * a config(true) (a save) would apply it halfway, and the Languages screen
 * would say the code sets what it doesn't.
 */
function drop_late_filters(): void
{
    $hook = $GLOBALS['wp_filter']['wp_go_languages/config'] ?? null;

    if (!$hook instanceof \WP_Hook) {
        return;
    }

    $first = config_callbacks();

    foreach ($hook->callbacks as $priority => $callbacks) {
        foreach ($callbacks as $id => $callback) {
            if (isset($first[(string) $id])) {
                continue;
            }

            remove_filter('wp_go_languages/config', $callback['function'], $priority);

            _doing_it_wrong(
                'add_filter',
                'wp_go_languages/config was added after the plugin read its settings, so it is ignored: add it from a must-use plugin.',
                '1.1.0'
            );
        }
    }
}

/**
 * One of the other settings the Languages screen keeps beside the languages,
 * by group (`site_texts`) and key (`capability`).
 */
function setting(string $group, string $key, mixed $default = null): mixed
{
    $values = config()[$group] ?? [];

    return is_array($values) && array_key_exists($key, $values) ? $values[$key] : $default;
}

/**
 * The site's own language, as one entry: what config() starts from before the
 * settings are saved.
 *
 * @return array{code:string,locale:string,name:string,slug:string}
 */
function default_entry(): array
{
    $locale = (string) get_option('WPLANG') ?: 'en_US';
    $code   = strtolower(strtok($locale, '_'));

    return ['code' => $code, 'locale' => $locale, 'name' => $code, 'slug' => $code];
}

/**
 * Entries cleaned up: codes lowercase and unique, a slug for each, the ones
 * with no code left out.
 *
 * @param array<int,mixed> $languages
 * @return list<array{code:string,locale:string,name:string,slug:string}>
 */
function normalize_languages(array $languages): array
{
    $out  = [];
    $seen = [];

    foreach ($languages as $language) {
        if (!is_array($language)) {
            continue;
        }

        $code = sanitize_key((string) ($language['code'] ?? ''));

        if ($code === '' || isset($seen[$code])) {
            continue;
        }

        $seen[$code] = true;
        $slug        = sanitize_title((string) ($language['slug'] ?? '')) ?: $code;

        $out[] = [
            'code'   => $code,
            'locale' => preg_replace('/[^A-Za-z0-9_]/', '', (string) ($language['locale'] ?? '')) ?: $code,
            'name'   => trim((string) ($language['name'] ?? '')) ?: $code,
            'slug'   => $slug,
        ] + (!empty($language['hidden']) ? ['hidden' => true] : []);
    }

    return $out;
}

/**
 * The languages by code, in order, each with `default` set on the first.
 *
 * @return array<string,array{code:string,locale:string,name:string,slug:string,default:bool}>
 */
function languages(): array
{
    $out = [];

    foreach (config()['languages'] as $i => $language) {
        $out[$language['code']] = $language + ['default' => $i === 0];
    }

    return $out;
}

/**
 * A language's flag, the name of one of the plugin's (flags/, flag-icons'
 * 4:3 set), from its locale (flag_for()). Null when none is found, or for a
 * code that isn't a language: the code is shown instead.
 */
function flag(string $code): ?string
{
    $language = language($code);

    return $language ? flag_for($code, $language['locale']) : null;
}

/**
 * The flag for a code and locale: the locale's country (`en_GB`: `gb`,
 * `pt_BR`: `br`), else the country a language without one is mostly shown by
 * (`ja`: `jp`, `ca`: `es-ct`), else a flag named as its code (`it`). Null
 * when no such file is found.
 */
function flag_for(string $code, string $locale): ?string
{
    $region = strtolower((string) (explode('_', $locale)[1] ?? ''));

    $countries = [
        'ar' => 'sa', 'be' => 'by', 'bs' => 'ba', 'ca' => 'es-ct', 'cs' => 'cz', 'cy' => 'gb-wls',
        'da' => 'dk', 'el' => 'gr', 'en' => 'gb', 'et' => 'ee', 'eu' => 'es-pv', 'fa' => 'ir',
        'gl' => 'es-ga', 'he' => 'il', 'hi' => 'in', 'hy' => 'am', 'ja' => 'jp', 'ka' => 'ge',
        'kk' => 'kz', 'ko' => 'kr', 'ms' => 'my', 'nb' => 'no', 'nn' => 'no', 'sl' => 'si',
        'sq' => 'al', 'sr' => 'rs', 'sv' => 'se', 'uk' => 'ua', 'vi' => 'vn', 'zh' => 'cn',
    ];

    foreach ([$region, $countries[$code] ?? '', $code] as $candidate) {
        if ($candidate !== '' && preg_match('/^[a-z-]+$/', $candidate) && is_file(dirname(WP_GO_LANGUAGES_FILE) . "/flags/$candidate.svg")) {
            return $candidate;
        }
    }

    return null;
}

/**
 * The URL of a language's flag (flag()), or null.
 */
function flag_url(string $code): ?string
{
    $flag = flag($code);

    return $flag !== null ? plugins_url("flags/$flag.svg", WP_GO_LANGUAGES_FILE) : null;
}

/**
 * The language codes, in order.
 *
 * @return list<string>
 */
function codes(): array
{
    return array_keys(languages());
}

/**
 * The default language's code: the first.
 */
function default_language(): string
{
    return config()['languages'][0]['code'];
}

/**
 * Whether the default language's URLs carry its slug too (`/it/…`), the root
 * redirecting there (redirect_unprefixed()).
 */
function default_in_url(): bool
{
    return config()['default_in_url'];
}

/**
 * Whether a language isn't ready for visitors: its addresses answer 404 to them,
 * and it's left out of the switcher, hreflang and the sitemap. Those who can edit
 * pages still see it, to check it. The default never is.
 */
function is_hidden_language(?string $code): bool
{
    return $code !== null && $code !== default_language() && !empty(language($code)['hidden']);
}

/**
 * Whether the visitor of this request sees a language.
 */
function shows_language(?string $code): bool
{
    return !is_hidden_language($code) || current_user_can('edit_pages');
}

/**
 * Whether a code is one of the site's languages.
 */
function is_language(?string $code): bool
{
    return $code !== null && $code !== '' && isset(languages()[$code]);
}

/**
 * One language's entry, or null.
 *
 * @return array{code:string,locale:string,name:string,slug:string,default:bool}|null
 */
function language(string $code): ?array
{
    return languages()[$code] ?? null;
}

/**
 * The language a URL segment names, or null: the languages but the default
 * have one, and the default too with default_in_url().
 */
function language_by_slug(string $slug): ?string
{
    foreach (languages() as $code => $language) {
        if ((!$language['default'] || default_in_url()) && $language['slug'] === $slug) {
            return $code;
        }
    }

    return null;
}

/**
 * The language a WordPress locale is printed in, or null.
 */
function language_by_locale(string $locale): ?string
{
    foreach (languages() as $code => $language) {
        if (strcasecmp($language['locale'], $locale) === 0) {
            return $code;
        }
    }

    return null;
}

/**
 * Whether posts of a type have a language.
 */
function is_translated_post_type(string $post_type): bool
{
    return in_array($post_type, translated_post_types(), true);
}

/**
 * The translated post types.
 *
 * @return list<string>
 */
function translated_post_types(): array
{
    /**
     * The translated post types.
     *
     * @param list<string> $post_types
     */
    return array_values(array_filter(
        (array) apply_filters('wp_go_languages/post_types', config()['post_types']),
        fn ($type) => is_string($type) && $type !== ''
    ));
}

/**
 * Whether terms of a taxonomy have a language.
 */
function is_translated_taxonomy(string $taxonomy): bool
{
    return in_array($taxonomy, translated_taxonomies(), true);
}

/**
 * The translated taxonomies.
 *
 * @return list<string>
 */
function translated_taxonomies(): array
{
    /**
     * The translated taxonomies.
     *
     * @param list<string> $taxonomies
     */
    return array_values(array_filter(
        (array) apply_filters('wp_go_languages/taxonomies', config()['taxonomies']),
        fn ($taxonomy) => is_string($taxonomy) && $taxonomy !== ''
    ));
}
