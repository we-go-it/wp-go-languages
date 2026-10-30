<?php

/**
 * The texts as the site prints them: one language at a time, from a PHP file
 * PHP's opcache keeps in memory, not from the database.
 *
 * The option (texts.php) has every key in every language: it's what the
 * screen, the saves and the scan work on, and what the files are written
 * from. A page prints one language, so that's all it reads:
 *
 *   wp-content/cache/wp-go-site-texts/{version}/en.php
 *
 *   <?php return ['footer.contact_us' => 'Contact us', …];
 *
 * The version is a hash of the texts, kept in `wp_go_site_texts_version`
 * (autoloaded: no query) and worked out again whenever the option is written,
 * by the screen or by anything else (wp-cli, a seeding command). A change is a
 * folder of its own, so no server and no opcache can serve an old file for
 * it, and the folders of other versions are removed as one is written. A
 * file missing (a new version, another server, the folder emptied) is
 * written from the option by the first request that needs it.
 *
 * Where the folder can't be written, a page reads the option instead: one
 * query, as before. `wp_go_site_texts_cache_dir` moves the folder; '' turns
 * the files off.
 *
 * WP-Go's copy (features/site-texts) has this file too, with the same
 * folder, option and version: either copy reads the other's files.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The option keeping the texts' version, autoloaded: a request knows it for free. */
const WP_GO_SITE_TEXTS_VERSION = 'wp_go_site_texts_version';

add_action('add_option_' . WP_GO_SITE_TEXTS_OPTION, fn ($option, $value) => wp_go_site_texts_restamp($value), 10, 2);
add_action('update_option_' . WP_GO_SITE_TEXTS_OPTION, fn ($old, $value) => wp_go_site_texts_restamp($value), 10, 2);
add_action('delete_option_' . WP_GO_SITE_TEXTS_OPTION, fn () => wp_go_site_texts_restamp([]));

/** The folder the files are written in (each version a folder of its own), or '' for none. */
function wp_go_site_texts_cache_dir(): string
{
    /**
     * Where the texts' files are written. '' writes none: every page reads
     * the option.
     *
     * @param string $dir
     */
    return untrailingslashit((string) apply_filters('wp_go_site_texts_cache_dir', WP_CONTENT_DIR . '/cache/wp-go-site-texts'));
}

/**
 * The version of some texts, as stored: what names their folder.
 *
 * @param array<string,mixed> $texts
 */
function wp_go_site_texts_hash(array $texts): string
{
    return substr(md5(serialize($texts)), 0, 12);
}

/**
 * The texts' version: as kept, or, before it's kept (the first request after
 * this was installed), worked out from the option, which then stops being
 * autoloaded: only the admin reads it whole.
 */
function wp_go_site_texts_version(): string
{
    $version = get_option(WP_GO_SITE_TEXTS_VERSION);

    if (is_string($version) && $version !== '') {
        return $version;
    }

    wp_set_option_autoload(WP_GO_SITE_TEXTS_OPTION, false);

    return wp_go_site_texts_restamp(get_option(WP_GO_SITE_TEXTS_OPTION, []));
}

/**
 * Keep the version of the texts the option now holds. On the option's
 * add, update and delete, with its new value, which is also the request's
 * copy from then on: a file written later in the request is of these texts,
 * whoever wrote the option.
 */
function wp_go_site_texts_restamp(mixed $value): string
{
    $texts = array_filter((array) $value, 'is_array');
    $version = wp_go_site_texts_hash($texts);

    $cached = &wp_go_site_texts();
    $cached = $texts;

    update_option(WP_GO_SITE_TEXTS_VERSION, $version, true);

    return $version;
}

/**
 * One language's texts, [key => value], those with a value only: what a
 * page prints. From the language's file, written first when it's missing.
 *
 * @return array<string,string>
 */
function wp_go_site_texts_for(string $language): array
{
    static $read = [];

    // a code that can't be a file's name reads nothing
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $language)) {
        return [];
    }

    $version = wp_go_site_texts_version();

    if (isset($read[ $version ][ $language ])) {
        return $read[ $version ][ $language ];
    }

    $dir = wp_go_site_texts_cache_dir();
    $file = "$dir/$version/$language.php";
    // removed meanwhile by a newer version's request: written again
    $values = $dir !== '' && is_file($file) ? @include $file : null;

    if (!is_array($values)) {
        $values = wp_go_site_texts_compile($language);
    }

    // a new version leaves the old one's behind
    $read = [$version => [$language => $values] + ($read[ $version ] ?? [])];

    return $values;
}

/**
 * Write a language's file from the option, in its version's folder, and
 * remove the other versions' folders. The version kept is put right if it
 * wasn't the option's (the option written where no hook saw it, by SQL).
 *
 * @return array<string,string> The language's texts, written or not.
 */
function wp_go_site_texts_compile(string $language): array
{
    $texts = wp_go_site_texts();
    $values = [];

    foreach ($texts as $key => $by_language) {
        $value = (string) ($by_language[ $language ] ?? '');

        if ($value !== '') {
            $values[ (string) $key ] = $value;
        }
    }

    $dir = wp_go_site_texts_cache_dir();

    if ($dir === '') {
        return $values;
    }

    $version = wp_go_site_texts_hash($texts);

    if (wp_go_site_texts_write_php("$dir/$version/$language.php", $values)) {
        $kept = (string) get_option(WP_GO_SITE_TEXTS_VERSION);

        // A version kept with a folder of its own is a newer save's, when
        // this request read the texts before it: that one stays, and the
        // version too. Else these texts are the option's.
        if ($kept === $version || $kept === '' || !is_dir("$dir/$kept")) {
            wp_go_site_texts_prune($dir, $version);

            if ($kept !== $version) {
                update_option(WP_GO_SITE_TEXTS_VERSION, $version, true);
            }
        }
    }

    return $values;
}

/**
 * Write a PHP file returning `$data`, whole: written beside it first, then
 * renamed over it, so no request ever includes half a file.
 *
 * @param array<mixed> $data
 */
function wp_go_site_texts_write_php(string $file, array $data): bool
{
    if (!wp_mkdir_p(dirname($file))) {
        return false;
    }

    $php = "<?php\n\n// Written from the database: changed here, it's overwritten.\n\nreturn " . var_export($data, true) . ";\n";
    $temp = $file . '.' . uniqid('', true) . '.tmp';

    // a folder that can't be written is a fallback, not an error to log
    if (@file_put_contents($temp, $php) === false) {
        return false;
    }

    if (!@rename($temp, $file)) {
        @unlink($temp);

        return false;
    }

    // the same file written again (its folder emptied, say)
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($file, true);
    }

    return true;
}

/** Remove the folders of every version but one, under `$dir`. */
function wp_go_site_texts_prune(string $dir, string $keep): void
{
    foreach ((array) glob("$dir/*", GLOB_ONLYDIR) as $folder) {
        $name = basename((string) $folder);

        if ($name === $keep || !preg_match('/^[0-9a-f]{12}$/', $name)) {
            continue;
        }

        foreach ((array) glob("$folder/*") as $file) {
            @unlink((string) $file);
        }

        @rmdir((string) $folder);
    }
}
