<?php

/**
 * The admin strings' translations, and how they reach the admin.
 *
 * Kept in `wp_go_languages_admin_translations`, by locale (the admin's
 * language is a WordPress locale: a user's Italian admin is `it_IT`), then
 * domain, then the string as gettext finds it, its context and text joined
 * by gettext's own separator (EOT):
 *
 *   ['it_IT' => ['wp-go' => ["includes/settings.php\x04Save" => 'Salva']]]
 *
 * That option is the screen's, and what the files are written from: the
 * admin doesn't read it. Each locale's translations of each domain are
 * written to a file in WordPress's own format, the .l10n.php its translation
 * loader reads (since 6.5), kept in memory by PHP's opcache:
 *
 *   wp-content/cache/wp-go-admin-strings/{version}/wp-go-it_IT.l10n.php
 *
 *   <?php return ['domain' => 'wp-go', 'language' => 'it_IT',
 *                 'messages' => ["includes/settings.php\x04Save" => 'Salva']];
 *
 * and loaded with load_textdomain(): gettext translates them as it does any
 * plugin's, with no filter on each string and no query. Which locale has
 * which domains, and the version (a hash of the option), are in the
 * autoloaded `wp_go_languages_admin_translations_index`, worked out again
 * whenever the option is written. The rest is as for site texts' files
 * (src/SiteTexts/compiled.php, whose writer this uses): a folder per
 * version, the others removed as one is written, a missing file written by
 * the first request that needs it, and the folder moved with
 * `wp_go_languages/admin_strings_cache_dir` ('' for no files).
 *
 * They're loaded on `after_setup_theme` at 0 (not earlier: WordPress warns of
 * translations loaded before it), for the request's locale, and on
 * `change_locale` for the locale switched to (an email in its recipient's
 * language). A domain's own translations (a .mo file) are loaded before
 * them, so they win.
 *
 * Where a file can't be written, or load_textdomain() won't take it (a
 * `translation_file_format` of 'mo'), the domain falls back to a filter on
 * `gettext_with_context_{domain}`, which reads the option.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The option the translations are kept in. */
const WP_GO_ADMIN_STRINGS_TRANSLATIONS = 'wp_go_languages_admin_translations';

/** The autoloaded option saying the translations' version, and each locale's domains. */
const WP_GO_ADMIN_STRINGS_INDEX = 'wp_go_languages_admin_translations_index';

// the option written, by the screen or anything else: a new version, loaded
// again if this request had loaded them
add_action('add_option_' . WP_GO_ADMIN_STRINGS_TRANSLATIONS, fn ($option, $value) => wp_go_admin_strings_changed($value), 10, 2);
add_action('update_option_' . WP_GO_ADMIN_STRINGS_TRANSLATIONS, fn ($old, $value) => wp_go_admin_strings_changed($value), 10, 2);
add_action('delete_option_' . WP_GO_ADMIN_STRINGS_TRANSLATIONS, fn () => wp_go_admin_strings_changed([]));

/**
 * Every translation, as kept: [locale => [domain => [context EOT text => value]]].
 *
 * @return array<string,array<string,array<string,string>>>
 */
function &wp_go_admin_strings_translations(): array
{
    static $translations = null;

    if ($translations === null) {
        $translations = array_filter((array) get_option(WP_GO_ADMIN_STRINGS_TRANSLATIONS, []), 'is_array');
    }

    return $translations;
}

/**
 * Write every translation.
 *
 * @param array<string,array<string,array<string,string>>> $translations
 */
function wp_go_admin_strings_store(array $translations): void
{
    // no empty locale or domain left behind
    foreach ($translations as $locale => $domains) {
        $translations[$locale] = array_filter($domains);
    }

    $translations = array_filter($translations);

    update_option(WP_GO_ADMIN_STRINGS_TRANSLATIONS, $translations, false);

    $kept = &wp_go_admin_strings_translations();
    $kept = $translations;
}

/** How a string is found in the translations: its context and text, as gettext joins them. */
function wp_go_admin_strings_lookup_key(string $context, string $text): string
{
    return $context . "\x04" . $text;
}

/** A string's translation in a locale, or '' when there is none. */
function wp_go_admin_strings_translation(string $id, string $locale): string
{
    $string = wp_go_admin_strings()[$id] ?? null;

    if (!$string) {
        return '';
    }

    return (string) (wp_go_admin_strings_translations()[$locale][$string['domain']][wp_go_admin_strings_lookup_key($string['context'], $string['text'])] ?? '');
}

/**
 * The locales the strings are translated into: the site's languages', the
 * default first. The admin shows in a user's locale, and these are the ones
 * the site speaks.
 *
 * @return array<string,string> locale => the language's name
 */
function wp_go_admin_strings_locales(): array
{
    $locales = [];

    foreach (\WpGo\Languages\Core\languages() as $language) {
        $locales[$language['locale']] = $language['name'];
    }

    return $locales;
}

/**
 * A string of a listed domain, in the admin's language: gettext's own
 * translation if it has one (a .mo file), else the one written here. The
 * fallback for a domain whose file couldn't be loaded.
 */
function wp_go_admin_strings_translate(string $translation, string $text, string $context, string $domain): string
{
    if ($translation !== $text) {
        return $translation;
    }

    // the locale switched to (an email in its recipient's language), else
    // the admin's (the user's)
    $locale = is_locale_switched() ? get_locale() : determine_locale();
    $value = wp_go_admin_strings_translations()[$locale][$domain][wp_go_admin_strings_lookup_key($context, $text)] ?? '';

    return $value !== '' ? (string) $value : $translation;
}

/**
 * ---------------------------------------------------------------------------
 * The files
 * ---------------------------------------------------------------------------
 */

/** The folder the files are written in (each version a folder of its own), or '' for none. */
function wp_go_admin_strings_cache_dir(): string
{
    /**
     * Where the admin strings' translation files are written. '' writes
     * none: every domain is translated by the filter, from the option.
     *
     * @param string $dir
     */
    return untrailingslashit((string) apply_filters('wp_go_languages/admin_strings_cache_dir', WP_CONTENT_DIR . '/cache/wp-go-admin-strings'));
}

/**
 * The translations' version and each locale's domains, as kept:
 * ['version' => …, 'domains' => [locale => [domain, …]]]. Worked out from
 * the option before it's kept (the first request after this was installed).
 *
 * @return array{version:string, domains:array<string,string[]>}
 */
function wp_go_admin_strings_index(): array
{
    $index = get_option(WP_GO_ADMIN_STRINGS_INDEX);

    if (is_array($index) && is_string($index['version'] ?? null) && is_array($index['domains'] ?? null)) {
        return $index;
    }

    return wp_go_admin_strings_restamp(get_option(WP_GO_ADMIN_STRINGS_TRANSLATIONS, []));
}

/**
 * Keep the version and the domains of the translations the option now
 * holds, which are also the request's copy from then on.
 *
 * @return array{version:string, domains:array<string,string[]>}
 */
function wp_go_admin_strings_restamp(mixed $value): array
{
    $translations = array_filter((array) $value, 'is_array');

    $kept = &wp_go_admin_strings_translations();
    $kept = $translations;

    $index = [
        'version' => wp_go_site_texts_hash($translations),
        'domains' => array_map(fn (array $domains) => array_map('strval', array_keys(array_filter($domains))), $translations),
    ];

    update_option(WP_GO_ADMIN_STRINGS_INDEX, $index, true);

    return $index;
}

/** The option was written: a new version, and this request's translations loaded again. */
function wp_go_admin_strings_changed(mixed $value): void
{
    wp_go_admin_strings_restamp($value);

    if (!did_action('after_setup_theme')) {
        return;
    }

    // the old version's files unloaded first: loaded after them, the new
    // ones would only fill their gaps
    $controller = WP_Translation_Controller::get_instance();
    $loaded = &wp_go_admin_strings_loaded();

    foreach ($loaded as $locale => $domains) {
        foreach ($domains as $domain => $file) {
            $controller->unload_file(substr($file, 0, -strlen('.mo')) . '.l10n.php', $domain, $locale);
        }
    }

    $loaded = [];

    wp_go_admin_strings_load();
}

/**
 * The files this request loaded: [locale => [domain => the path given to
 * load_textdomain()]].
 *
 * @return array<string,array<string,string>>
 */
function &wp_go_admin_strings_loaded(): array
{
    static $loaded = [];

    return $loaded;
}

/**
 * Load a locale's translations: the request's (determine_locale()) on
 * `after_setup_theme`, the one switched to on `change_locale`. Each domain's
 * own translations are loaded first, so they win; a domain whose file can't
 * be had is translated by the filter instead.
 */
function wp_go_admin_strings_load(mixed $locale = null): void
{
    $locale = is_string($locale) && $locale !== '' ? $locale : determine_locale();
    $index = wp_go_admin_strings_index();
    $domains = $index['domains'][$locale] ?? [];

    if (!$domains) {
        return;
    }

    $files = wp_go_admin_strings_compiled($locale, $index);
    $loaded = &wp_go_admin_strings_loaded();

    foreach ($domains as $domain) {
        // the domain's own files, if it has any and they aren't loaded yet
        get_translations_for_domain($domain);

        $file = $files[$domain] ?? '';

        if ($file !== '' && load_textdomain($domain, $file, $locale)) {
            $loaded[$locale][$domain] = $file;
        } else {
            add_filter("gettext_with_context_{$domain}", 'wp_go_admin_strings_translate', 10, 4);
        }
    }
}

/**
 * The files of a locale's domains, [domain => path]: written first when one
 * is missing, [] when they can't be. A path is the .mo name
 * load_textdomain() is given; it reads the .l10n.php beside it.
 *
 * @param array{version:string, domains:array<string,string[]>} $index
 * @return array<string,string>
 */
function wp_go_admin_strings_compiled(string $locale, array $index): array
{
    $dir = wp_go_admin_strings_cache_dir();

    if ($dir === '' || !preg_match('/^[A-Za-z0-9_@-]+$/', $locale)) {
        return [];
    }

    $files = [];

    foreach ($index['domains'][$locale] ?? [] as $domain) {
        // a domain that can't be a file's name has none (the filter's): not missing
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $domain)) {
            $files[$domain] = "$dir/{$index['version']}/$domain-$locale";
        }
    }

    foreach ($files as $file) {
        if (!is_file("$file.l10n.php")) {
            return wp_go_admin_strings_compile($locale);
        }
    }

    return array_map(fn (string $file) => "$file.mo", $files);
}

/**
 * Write a locale's files from the option, in its version's folder, and
 * remove the other versions' folders. The version kept is put right if it
 * wasn't the option's (the option written where no hook saw it).
 *
 * @return array<string,string> [domain => path], as wp_go_admin_strings_compiled().
 */
function wp_go_admin_strings_compile(string $locale): array
{
    $translations = wp_go_admin_strings_translations();
    $dir = wp_go_admin_strings_cache_dir();
    $version = wp_go_site_texts_hash($translations);
    $files = [];

    foreach ($translations[$locale] ?? [] as $domain => $messages) {
        $domain = (string) $domain;

        // a domain that can't be a file's name: the filter's
        if (!is_array($messages) || !$messages || !preg_match('/^[A-Za-z0-9_.-]+$/', $domain)) {
            continue;
        }

        $file = "$dir/$version/$domain-$locale";
        $data = ['domain' => $domain, 'language' => $locale, 'messages' => array_map('strval', $messages)];

        if (!wp_go_site_texts_write_php("$file.l10n.php", $data)) {
            return [];
        }

        $files[$domain] = "$file.mo";
    }

    $kept = wp_go_admin_strings_index()['version'];

    // as for site texts (wp_go_site_texts_compile()): a version kept with a
    // folder of its own is a newer save's, and stays
    if ($files && ($kept === $version || !is_dir("$dir/$kept"))) {
        wp_go_site_texts_prune($dir, $version);

        if ($kept !== $version) {
            wp_go_admin_strings_restamp($translations);
        }
    }

    return $files;
}

/**
 * Forget the translations of strings the code no longer has, and say so with
 * `wp_go_languages/strings_removed`: [domain => [context, …]].
 *
 * @param array<string,array{domain:string, context:string, text:string}> $strings
 */
function wp_go_admin_strings_forget(array $strings): void
{
    $translations = wp_go_admin_strings_translations();
    $removed = [];

    foreach ($strings as $string) {
        $key = wp_go_admin_strings_lookup_key($string['context'], $string['text']);

        foreach (array_keys($translations) as $locale) {
            if (isset($translations[$locale][$string['domain']][$key])) {
                unset($translations[$locale][$string['domain']][$key]);
                $removed[$string['domain']][$string['context']] = true;
            }
        }
    }

    if (!$removed) {
        return;
    }

    wp_go_admin_strings_store($translations);

    /**
     * Admin strings' translations were removed, their strings gone from the
     * code.
     *
     * @param array<string,string[]> $removed [domain => [context, …]]
     */
    do_action('wp_go_languages/strings_removed', array_map('array_keys', $removed));
}
