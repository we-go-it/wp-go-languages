<?php

/**
 * Reading the code for the keys it prints.
 *
 * The theme says where to look (`wp_go_site_texts_scan`): which folders,
 * which functions take a key as their first argument (`t`, `base_text`…) and
 * which file types. The scan then finds every call of those functions and
 * sorts what it was given:
 *
 *   - a plain string that is a key: the code prints that key, here; a plain
 *     lowercase string as the second argument is the key's domain
 *     (`t('checkout.pay', 'shop')`), any other second argument (`'Shop'`,
 *     `$domain`) is reported, as its domain can't be read
 *   - a plain string that isn't a key: reported, it can't be listed
 *   - anything else (a variable, a string built from parts): reported, its
 *     key can't be known, so that key may wrongly look unused
 *
 * With that, the screen can offer to add the keys the code prints but the
 * list doesn't have, and point out the listed keys the code no longer
 * prints. Nothing is ever removed without an administrator choosing to, and
 * keys under a protected prefix (another front end's) are never marked.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The option where the last scan's findings are kept, so the screen can say
 * where a key is printed without scanning again (wp_go_site_texts_record_used()).
 */
const WP_GO_SITE_TEXTS_USED = 'wp_go_site_texts_used';

/**
 * Where and how to look, as the theme says with `wp_go_site_texts_scan`:
 *
 *   paths       the folders to read, recursively (none: no scanning at all)
 *   functions   the functions whose first argument is a key (default: `wp_go_site_text`)
 *   extensions  the file types to read (default: `php`, `twig`)
 *   skip        folders to leave out wherever they are (default: `node_modules`, `vendor`, `dist`)
 *
 * @return array{paths:string[], functions:string[], extensions:string[], skip:string[]}
 */
function wp_go_site_texts_scan_config(): array
{
    $config = (array) apply_filters('wp_go_site_texts_scan', []);
    $strings = fn ($value) => array_values(array_filter(array_map('strval', (array) $value)));

    return [
        'paths'      => array_values(array_filter($strings($config['paths'] ?? []), 'is_dir')),
        'functions'  => $strings($config['functions'] ?? ['wp_go_site_text']),
        'extensions' => $strings($config['extensions'] ?? ['php', 'twig']),
        'skip'       => $strings($config['skip'] ?? ['node_modules', 'vendor', 'dist']),
    ];
}

/**
 * Scan the code: every call of the configured functions, sorted by what its
 * first argument is. Done once per request.
 *
 *   keys     a plain string that is a key: [key => ["file:line", …]]
 *   invalid  a plain string that isn't a key: [string => ["file:line", …]]
 *   dynamic  anything else: ["file:line", …]
 *   domains  the domains the calls give their keys: [key => domain]
 *   clashes  keys given more than one domain: [key => [domain, …]]; the
 *            first one found counts
 *   unread_domains  a key's call whose second argument isn't a plain
 *            lowercase string, so its domain can't be read: ["file:line", …]
 *
 * Files are named from wp-content (`themes/my-theme/views/footer.twig`).
 * Comments are skipped (wp_go_site_texts_without_comments()), so a key
 * written in one as an example doesn't count. A function's own definition
 * (`function t(`) and an empty call (`t()`) don't count either.
 *
 * Null when the theme names no folder to read.
 *
 * @return array{keys:array<string,string[]>, invalid:array<string,string[]>, dynamic:string[], domains:array<string,string>, clashes:array<string,string[]>, unread_domains:string[]}|null
 */
function wp_go_site_texts_scan(): ?array
{
    static $result = false;

    if ($result !== false) {
        return $result;
    }

    $config = wp_go_site_texts_scan_config();

    if (!$config['paths'] || !$config['functions']) {
        return $result = null;
    }

    $names = implode('|', array_map(fn ($name) => preg_quote($name, '/'), $config['functions']));

    // A call of one of the functions, by its own name: not the end of a
    // longer name (`my_t(`, `$t(`, `Ns\t(`), nor a method (`->t(`, `::t(`,
    // Twig's `.t(`). In PHP a dot is a concatenation (`'x'.t(`), and `\t(`
    // the global function itself.
    $before = '(?<![\\w$\\\\])(?<!->)(?<!::)|(?<![\\w\\\\])\\\\';
    $call = [
        'php'   => "/(?:$before)(?:$names)\\s*\\(/",
        'other' => "/(?:$before)(?<!\\.)(?:$names)\\s*\\(/",
    ];

    // Its first argument as a plain string, then, maybe, a second one (the
    // key's domain), followed by `,` or `)`.
    $literal = '/\G\s*([\'"])([^\'"\\\\]*)\1\s*(?:,\s*([\'"])([a-z0-9_-]+)\3\s*)?[,)]/';

    $content = wp_normalize_path(WP_CONTENT_DIR) . '/';
    $result = ['keys' => [], 'invalid' => [], 'dynamic' => [], 'domains' => [], 'clashes' => [], 'unread_domains' => []];

    foreach (wp_go_site_texts_scan_files($config) as $path => $extension) {
        $source = wp_go_site_texts_without_comments((string) file_get_contents($path), $extension);

        if (!preg_match_all($call[ $extension === 'php' ? 'php' : 'other' ], $source, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $file = str_replace($content, '', wp_normalize_path($path));

        foreach ($matches[0] as [$match, $offset]) {
            $after = $offset + strlen($match);

            $is_definition = (bool) preg_match('/\bfunction\s+$/', substr($source, max(0, $offset - 20), min(20, $offset)));
            $is_empty = (bool) preg_match('/\G\s*\)/', $source, $nothing, 0, $after);

            if ($is_definition || $is_empty) {
                continue;
            }

            $where = $file . ':' . (substr_count($source, "\n", 0, $offset) + 1);

            if (!preg_match($literal, $source, $found, 0, $after)) {
                $result['dynamic'][] = $where;
            } elseif (preg_match(WP_GO_SITE_TEXTS_KEY, $found[2])) {
                $result['keys'][ $found[2] ][] = $where;

                if (($found[4] ?? '') !== '') {
                    wp_go_site_texts_note_domain($result, $found[2], $found[4]);
                } elseif (str_ends_with($found[0], ',') && !preg_match('/\G\s*\)/', $source, $nothing, 0, $after + strlen($found[0]))) {
                    // a second argument that isn't a plain lowercase string
                    // (`'Shop'`, `$domain`): said, not silently dropped
                    $result['unread_domains'][] = $where;
                }
            } else {
                $result['invalid'][ $found[2] ][] = $where;
            }
        }
    }

    ksort($result['keys'], SORT_STRING);
    wp_go_site_texts_record_used($result['keys'], $result['domains']);

    return $result;
}

/**
 * A domain a call gives its key: the first one counts, a different one
 * later is a clash, reported on the screen.
 *
 * @param array<string,mixed> $result
 */
function wp_go_site_texts_note_domain(array &$result, string $key, string $domain): void
{
    $first = $result['domains'][ $key ] ?? null;

    if ($first === null) {
        $result['domains'][ $key ] = $domain;
    } elseif ($first !== $domain) {
        $result['clashes'][ $key ] = array_values(array_unique([...($result['clashes'][ $key ] ?? [$first]), $domain]));
    }
}

/**
 * The files a scan reads: path => extension, under the configured folders,
 * the skipped ones left out.
 *
 * @param array{paths:string[], extensions:string[], skip:string[]} $config
 * @return iterable<string,string>
 */
function wp_go_site_texts_scan_files(array $config): iterable
{
    foreach ($config['paths'] as $folder) {
        $tree = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
            fn ($file) => !($file->isDir() && in_array($file->getFilename(), $config['skip'], true))
        );

        foreach (new RecursiveIteratorIterator($tree) as $file) {
            if (in_array($file->getExtension(), $config['extensions'], true)) {
                yield $file->getPathname() => $file->getExtension();
            }
        }
    }
}

/**
 * A file's source with its comments blanked out: PHP's (`//`, `#`, `/* *\/`,
 * docblocks, found by PHP's own tokenizer) and Twig's (`{# #}`).
 *
 * Each character of a comment becomes a space (newlines stay), so offsets
 * and line numbers don't move. HTML comments are kept: Twig still runs what's
 * inside them.
 */
function wp_go_site_texts_without_comments(string $source, string $extension): string
{
    $blank = fn (string $text) => (string) preg_replace('/[^\n]/', ' ', $text);

    if ($extension === 'php') {
        $out = '';

        foreach (token_get_all($source) as $token) {
            $is_comment = is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true);
            $out .= is_array($token) ? ($is_comment ? $blank($token[1]) : $token[1]) : $token;
        }

        return $out;
    }

    if ($extension === 'twig') {
        return (string) preg_replace_callback('/\{#.*?#\}/s', fn ($comment) => $blank($comment[0]), $source);
    }

    return $source;
}

/**
 * Keep where each key was printed, the domains the code gave keys, and when
 * the code was read. Written whenever the code is scanned (Scan code, Add
 * them, a seeding command); not autoloaded, as only the admin reads it.
 *
 * @param array<string,string[]> $keys
 * @param array<string,string>   $domains
 */
function wp_go_site_texts_record_used(array $keys, array $domains = []): void
{
    update_option(WP_GO_SITE_TEXTS_USED, ['scanned' => time(), 'keys' => $keys, 'domains' => $domains], false);
}

/**
 * What the code's last scan found: where it printed each key, the domains it
 * gave keys, and when that was (0 when it never was).
 *
 * @return array{scanned:int, keys:array<string,string[]>, domains:array<string,string>}
 */
function wp_go_site_texts_used(): array
{
    $used = (array) get_option(WP_GO_SITE_TEXTS_USED, []);

    return [
        'scanned' => (int) ($used['scanned'] ?? 0),
        'keys'    => array_filter((array) ($used['keys'] ?? []), 'is_array'),
        'domains' => array_filter((array) ($used['domains'] ?? []), 'is_string'),
    ];
}

/**
 * The prefixes of keys this site's code doesn't print but another front end
 * does (read over REST): a scan never marks them unused. They come from the
 * Languages screen, then the `wp_go_site_texts_protected` filter. A prefix is
 * a group (`nuxt`) or any start of a key that ends on a dot (`nuxt.cart`).
 *
 * @return string[]
 */
function wp_go_site_texts_protected_prefixes(): array
{
    $setting = explode(',', (string) \WpGo\Languages\Core\setting('site_texts', 'protected', ''));
    $prefixes = (array) apply_filters('wp_go_site_texts_protected', $setting);

    return array_values(array_unique(array_filter(array_map(fn ($prefix) => trim((string) $prefix, " .\t\n"), $prefixes))));
}

/**
 * Whether a key is under a protected prefix: one of `$prefixes`, read from
 * the setting when not given (pass them in when asking for many keys).
 *
 * @param string[]|null $prefixes
 */
function wp_go_site_texts_is_protected(string $key, ?array $prefixes = null): bool
{
    foreach ($prefixes ?? wp_go_site_texts_protected_prefixes() as $prefix) {
        if ($key === $prefix || str_starts_with($key, "$prefix.")) {
            return true;
        }
    }

    return false;
}

/**
 * The listed keys the code doesn't print (protected ones aside): what a scan
 * offers to remove. Null when there's nothing to scan.
 *
 * @return string[]|null
 */
function wp_go_site_texts_unused(): ?array
{
    $scan = wp_go_site_texts_scan();

    if ($scan === null) {
        return null;
    }

    $protected = wp_go_site_texts_protected_prefixes();

    return array_values(array_filter(
        array_keys(wp_go_site_texts()),
        fn ($key) => !isset($scan['keys'][ $key ]) && !wp_go_site_texts_is_protected((string) $key, $protected)
    ));
}

/**
 * Add the keys the code prints that the list doesn't have, with no values
 * yet. A key that can't be added (it's nested in another, see
 * wp_go_site_texts_key_error()) is returned under `skipped`, with why.
 *
 * @return array{added:string[], skipped:array<string,string>}
 */
function wp_go_site_texts_add_found(): array
{
    $scan = wp_go_site_texts_scan();
    $texts = wp_go_site_texts();
    $done = ['added' => [], 'skipped' => []];

    foreach (array_keys($scan['keys'] ?? []) as $key) {
        if (isset($texts[ $key ])) {
            continue;
        }

        $error = wp_go_site_texts_key_error($key, $texts);

        if ($error !== null) {
            $done['skipped'][ $key ] = $error;
            continue;
        }

        $texts[ $key ] = [];
        $done['added'][] = $key;
    }

    if ($done['added']) {
        wp_go_site_texts_store($texts);
    }

    return $done;
}
