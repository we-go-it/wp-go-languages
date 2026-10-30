<?php

/**
 * Which admin strings there are: read from the code, kept in an option.
 *
 * A string is a gettext call with a context, written the house way:
 *
 *   _x('Save the page first', 'src/Admin/admin.php', 'wp-go-languages')
 *
 * in English, its context the path of the file it's in (from its theme or
 * plugin), its domain the theme's or plugin's. Comments are skipped, so an
 * example written in one isn't a string. `_x()`, `esc_html_x()` and
 * `esc_attr_x()` are read (all three go through `gettext_with_context`); a
 * string of a listed domain written with another function (`__()`, `_nx()`)
 * is reported, as is a string whose context isn't its file's path.
 *
 * What to read is said with `wp_go_languages/admin_strings`, per domain:
 *
 *   'base-editor' => [
 *       'root'    => get_template_directory(),         // contexts are paths from here
 *       'paths'   => ['blocks', 'form', 'includes', 'views'],  // from the root
 *       'skip'    => ['node_modules', 'vendor', 'dist'],
 *       'extensions' => ['php', 'twig'],
 *   ]
 *
 * This plugin lists its own domain; the theme lists its own and WP-Go's.
 *
 * A domain that can't be read this time (not listed, its root not a folder,
 * none of its paths there) keeps the strings the last scan found, with a
 * warning: a missing source never empties its domain.
 *
 * The strings found are kept in `wp_go_languages_admin_strings`:
 *
 *   ['scanned' => time, 'strings' => [id => {domain, context, text}], 'warnings' => […]]
 *
 * An id is md5(domain|context|text): the same text under two contexts is two
 * strings, as gettext has it.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The option the strings found are kept in. */
const WP_GO_ADMIN_STRINGS_OPTION = 'wp_go_languages_admin_strings';

/**
 * The domains listed with `wp_go_languages/admin_strings`, as the filter
 * gives them: [domain => source].
 *
 * @return array<string,array<string,mixed>>
 */
function wp_go_admin_strings_listed(): array
{
    /**
     * The admin strings' domains and where to read them.
     *
     * @param array<string,array<string,mixed>> $sources
     */
    $sources = (array) apply_filters('wp_go_languages/admin_strings', [
        'wp-go-languages' => [
            'root'       => dirname(WP_GO_LANGUAGES_FILE),
            'paths'      => ['src', 'wp-go-languages.php'],
            'extensions' => ['php'],
        ],
    ]);

    return array_filter($sources, fn ($source, $domain) => is_string($domain) && $domain !== '' && is_array($source), ARRAY_FILTER_USE_BOTH);
}

/**
 * What to read for each domain, as filtered, with the defaults filled in;
 * a domain whose root isn't a folder that can be read is left out.
 *
 * @return array<string,array{root:string, paths:string[], skip:string[], extensions:string[]}>
 */
function wp_go_admin_strings_sources(): array
{
    $out = [];

    foreach (wp_go_admin_strings_listed() as $domain => $source) {
        $root = untrailingslashit((string) ($source['root'] ?? ''));

        if (!is_dir($root) || !is_readable($root)) {
            continue;
        }

        $out[$domain] = [
            'root'       => $root,
            'paths'      => array_values(array_map('strval', (array) ($source['paths'] ?? ['.']))),
            'skip'       => array_values(array_map('strval', (array) ($source['skip'] ?? ['node_modules', 'vendor', 'dist']))),
            'extensions' => array_values(array_map('strval', (array) ($source['extensions'] ?? ['php']))),
        ];
    }

    return $out;
}

/**
 * The strings found by the last scan: [id => {domain, context, text}].
 *
 * @return array<string,array{domain:string, context:string, text:string}>
 */
function wp_go_admin_strings(): array
{
    return (array) (wp_go_admin_strings_state()['strings'] ?? []);
}

/**
 * The last scan, as kept: when, the strings, and what it warned about.
 *
 * @return array{scanned:int, strings:array<string,array{domain:string, context:string, text:string}>, warnings:string[]}
 */
function wp_go_admin_strings_state(): array
{
    $state = (array) get_option(WP_GO_ADMIN_STRINGS_OPTION, []);

    return [
        'scanned'  => (int) ($state['scanned'] ?? 0),
        'strings'  => array_filter((array) ($state['strings'] ?? []), 'is_array'),
        'warnings' => array_values(array_map('strval', (array) ($state['warnings'] ?? []))),
    ];
}

/** A string's id: one per domain, context and text. */
function wp_go_admin_strings_id(string $domain, string $context, string $text): string
{
    return md5($domain . '|' . $context . '|' . $text);
}

/**
 * Read the code: every `_x()`, `esc_html_x()` and `esc_attr_x()` of the
 * listed domains. `read` is the domains actually read: a domain listed whose
 * root isn't a folder, or none of whose paths is there, isn't, and says so
 * among the warnings.
 *
 * @return array{strings:array<string,array{domain:string, context:string, text:string}>, warnings:string[], read:string[]}
 */
function wp_go_admin_strings_read_code(): array
{
    // A PHP or Twig string literal: '…' or "…", backslash-escaped.
    $literal = '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")';
    $strings = [];
    $warnings = [];
    $read = [];
    $sources = wp_go_admin_strings_sources();

    foreach (array_diff_key(wp_go_admin_strings_listed(), $sources) as $domain => $source) {
        $warnings[] = sprintf('%s: its root «%s» isn\'t a folder that can be read: its strings are kept as they were', $domain, (string) ($source['root'] ?? ''));
    }

    foreach ($sources as $domain => $source) {
        $missing = array_filter($source['paths'], fn ($path) => !file_exists(wp_go_admin_strings_path($source, $path)));

        if (count($missing) === count($source['paths'])) {
            $warnings[] = sprintf('%s: none of its paths is in «%s»: its strings are kept as they were', $domain, $source['root']);
            continue;
        }

        foreach ($missing as $path) {
            $warnings[] = sprintf('%s: «%s» isn\'t in «%s»', $domain, $path, $source['root']);
        }

        $read[] = $domain;
        $quoted = preg_quote($domain, '/');
        $call = "/(?<![\\w])(?:_x|esc_html_x|esc_attr_x)\\(\\s*$literal\\s*,\\s*$literal\\s*,\\s*['\"]{$quoted}['\"]\\s*\\)/";
        $any = "/['\"]{$quoted}['\"]\\s*\\)/";

        foreach (wp_go_admin_strings_files($source) as $path) {
            // comments blanked: an example written in one isn't a string
            $code = wp_go_site_texts_without_comments((string) file_get_contents($path), pathinfo($path, PATHINFO_EXTENSION));
            $file = ltrim(substr(wp_normalize_path($path), strlen(wp_normalize_path($source['root']))), '/');

            preg_match_all($call, $code, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $text = wp_go_admin_strings_unquote(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
                $context = wp_go_admin_strings_unquote(($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));

                if ($context !== $file) {
                    $warnings[] = sprintf('%s: the context of «%s» is «%s», not the file\'s path', "$domain/$file", $text, $context);
                }

                $strings[wp_go_admin_strings_id($domain, $context, $text)] = ['domain' => $domain, 'context' => $context, 'text' => $text];
            }

            $calls = preg_match_all($any, $code);

            if ($calls > count($matches)) {
                $warnings[] = sprintf('%s: %d string(s) of its domain not written as _x(\'…\', \'…\', \'%s\') (or esc_html_x, esc_attr_x)', "$domain/$file", $calls - count($matches), $domain);
            }
        }
    }

    ksort($strings);

    return ['strings' => $strings, 'warnings' => $warnings, 'read' => $read];
}

/** A source's path, from its root: `.` or empty is the root itself. */
function wp_go_admin_strings_path(array $source, string $path): string
{
    $path = trim($path, '/');

    return $path === '' || $path === '.' ? $source['root'] : $source['root'] . '/' . $path;
}

/**
 * A domain's files to read.
 *
 * @param array{root:string, paths:string[], skip:string[], extensions:string[]} $source
 * @return iterable<string>
 */
function wp_go_admin_strings_files(array $source): iterable
{
    foreach ($source['paths'] as $path) {
        $full = wp_go_admin_strings_path($source, $path);

        if (is_file($full)) {
            yield $full;

            continue;
        }

        if (!is_dir($full)) {
            continue;
        }

        $tree = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS),
            fn ($file) => !($file->isDir() && in_array($file->getFilename(), $source['skip'], true))
        );

        foreach (new RecursiveIteratorIterator($tree) as $file) {
            if (in_array($file->getExtension(), $source['extensions'], true)) {
                yield $file->getPathname();
            }
        }
    }
}

/** A literal as PHP or Twig reads it: only the quote and the backslash are escaped. */
function wp_go_admin_strings_unquote(string $literal): string
{
    return (string) preg_replace('/\\\\([\'"\\\\])/', '$1', $literal);
}

/**
 * Scan the code and keep what it has: new strings added, strings it no
 * longer has removed, their translations with them (as String Translation
 * did with `wp base strings`). A domain not read this time (see
 * wp_go_admin_strings_read_code()) keeps the last scan's strings: under
 * `wp --skip-themes` the theme lists nothing, and that's no reason to drop
 * its translations. `$dry` only says what would change.
 *
 * `strings` is what the list is, or would be, after the scan; `kept`, the
 * domains not read, with how many strings each kept.
 *
 * @return array{found:int, added:string[], removed:string[], warnings:string[], strings:array<string,array{domain:string, context:string, text:string}>, kept:array<string,int>}
 */
function wp_go_admin_strings_scan(bool $dry = false): array
{
    $before = wp_go_admin_strings();
    $read = wp_go_admin_strings_read_code();
    $warnings = $read['warnings'];

    $unread = array_filter($before, fn ($string) => !in_array($string['domain'], $read['read'], true));
    $kept = array_count_values(array_column($unread, 'domain'));
    $listed = wp_go_admin_strings_listed();

    // a domain listed but not read has its warning already
    foreach (array_diff_key($kept, $listed) as $domain => $count) {
        $warnings[] = sprintf('%s: not listed this time (the theme not loaded, `--skip-themes`?): its %d strings are kept as they were', $domain, $count);
    }

    $strings = $read['strings'] + $unread;
    ksort($strings);

    $added = array_keys(array_diff_key($strings, $before));
    $removed = array_keys(array_diff_key($before, $strings));

    if (!$dry) {
        update_option(WP_GO_ADMIN_STRINGS_OPTION, ['scanned' => time(), 'strings' => $strings, 'warnings' => $warnings], false);

        if ($removed) {
            wp_go_admin_strings_forget(array_intersect_key($before, array_flip($removed)));
        }
    }

    return ['found' => count($strings), 'added' => $added, 'removed' => $removed, 'warnings' => $warnings, 'strings' => $strings, 'kept' => $kept];
}
