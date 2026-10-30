<?php

namespace WpGo\Languages\Cli;

use WpGo\Languages\Core;

/**
 * The site's languages, from the command line.
 */
final class Command
{
    /**
     * List the languages, or the posts of a type with their language and
     * translations.
     *
     * ## OPTIONS
     *
     * [--post_type=<type>]
     * : List this type's posts instead of the languages.
     *
     * [--format=<format>]
     * : table, csv, json or yaml.
     * ---
     * default: table
     * ---
     *
     * @param list<string>          $args
     * @param array<string,string>  $assoc
     */
    public function list(array $args, array $assoc): void
    {
        $format = $assoc['format'] ?? 'table';

        if (empty($assoc['post_type'])) {
            $rows = array_map(fn (array $language) => [
                'code'    => $language['code'],
                'locale'  => $language['locale'],
                'name'    => $language['name'],
                'slug'    => $language['default'] && !Core\default_in_url() ? '' : $language['slug'],
                'default' => $language['default'] ? 'yes' : '',
                'home'    => Core\home_url_for($language['code']),
            ], array_values(Core\languages()));

            \WP_CLI\Utils\format_items($format, $rows, ['code', 'locale', 'name', 'slug', 'default', 'home']);

            return;
        }

        $posts = get_posts([
            'post_type'      => $assoc['post_type'],
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $rows = [];

        foreach ($posts as $post) {
            $translations = Core\translations($post->ID);
            unset($translations[(string) Core\language_of($post->ID)]);

            $rows[] = [
                'ID'           => $post->ID,
                'title'        => $post->post_title,
                'status'       => $post->post_status,
                'language'     => (string) Core\language_of($post->ID),
                'original'     => Core\is_translation($post->ID) ? Core\original($post->ID) : '',
                'mode'         => (string) Core\mode_of($post->ID),
                'translations' => implode(' ', array_map(fn ($code, $id) => "$code:$id", array_keys($translations), $translations)),
                'url'          => (string) get_permalink($post),
            ];
        }

        \WP_CLI\Utils\format_items($format, $rows, ['ID', 'title', 'status', 'language', 'original', 'mode', 'translations', 'url']);
    }

    /**
     * Give posts a language.
     *
     * ## OPTIONS
     *
     * <lang>
     * : The language's code.
     *
     * <id>...
     * : The posts.
     *
     * @param list<string> $args
     */
    public function set(array $args): void
    {
        $code = (string) array_shift($args);

        if (!Core\is_language($code)) {
            \WP_CLI::error("$code is not one of the site's languages.");
        }

        foreach ($args as $id) {
            if (Core\set_language((int) $id, $code)) {
                \WP_CLI::log("$id: $code");
            } else {
                \WP_CLI::warning("$id: not of a translated type.");
            }
        }
    }

    /**
     * Make a post a translation of another.
     *
     * ## OPTIONS
     *
     * <id>
     * : The translation.
     *
     * <original>
     * : What it translates.
     *
     * [--mode=<mode>]
     * : duplicate, fixed (the original's blocks, the fields by their rules)
     * or structure (the original's blocks, every field its own). Left out, a
     * translation keeps its mode, and a new one is a duplicate.
     *
     * @param list<string>         $args
     * @param array<string,string> $assoc
     */
    public function link(array $args, array $assoc): void
    {
        [$id, $original] = array_map('intval', $args);

        $mode = $assoc['mode'] ?? null;

        if ($mode !== null && !in_array($mode, Core\MODES, true)) {
            \WP_CLI::error("$mode is not a mode: " . implode(', ', Core\MODES) . '.');
        }

        $linked = Core\link($id, $original);

        if (is_wp_error($linked)) {
            \WP_CLI::error($linked->get_error_message());
        }

        // set_mode(), not the meta alone: into fixed, the content is rebuilt
        // on the original's now, not on the next save
        if ($mode !== null) {
            Core\set_mode($id, $mode);
        } elseif (get_post_meta($id, Core\MODE_META, true) === '') {
            update_post_meta($id, Core\MODE_META, 'duplicate');
        }

        \WP_CLI::success("$id is now the " . Core\language_of($id) . ' translation of ' . Core\original($id) . ' (' . Core\mode_of($id) . ').');
    }

    /**
     * Translate a post: a draft copy in another language.
     *
     * ## OPTIONS
     *
     * <id>
     * : The post.
     *
     * <lang>
     * : The language to translate into.
     *
     * [--mode=<mode>]
     * : duplicate, fixed (the original's blocks, the fields by their rules)
     * or structure (the original's blocks, every field its own).
     * ---
     * default: duplicate
     * ---
     *
     * @param list<string>         $args
     * @param array<string,string> $assoc
     */
    public function translate(array $args, array $assoc): void
    {
        $new = Core\create_translation((int) $args[0], (string) $args[1], $assoc['mode'] ?? 'duplicate');

        if (is_wp_error($new)) {
            \WP_CLI::error($new->get_error_message());
        }

        \WP_CLI::success("Created $new.");
    }

    /**
     * The admin's strings (the Site texts screen's Admin strings tab).
     *
     * ## OPTIONS
     *
     * <action>
     * : `scan`: read the code for its strings, add the new ones and remove
     * those it no longer has (their translations with them).
     *
     * [--dry-run]
     * : Say what would change, and change nothing.
     *
     * ## EXAMPLES
     *
     *     wp languages strings scan
     *     wp languages strings scan --dry-run
     *
     * @param list<string>         $args
     * @param array<string,string> $assoc
     */
    public function strings(array $args, array $assoc): void
    {
        if (($args[0] ?? '') !== 'scan') {
            \WP_CLI::error('Usage: wp languages strings scan [--dry-run]');
        }

        $dry = !empty($assoc['dry-run']);
        $before = wp_go_admin_strings();
        $done = wp_go_admin_strings_scan($dry);
        // the list after the scan, dry or not: the domains not read keep theirs
        $strings = $done['strings'];

        foreach ($done['warnings'] as $warning) {
            \WP_CLI::warning($warning);
        }

        foreach ($done['removed'] as $id) {
            $string = $before[$id] ?? null;
            \WP_CLI::log('  removed: ' . ($string ? "[{$string['domain']}] {$string['text']}  ({$string['context']})" : $id));
        }

        \WP_CLI::log(sprintf('%-8s %d', 'Found', $done['found']));
        \WP_CLI::log(sprintf('%-8s %d', 'New', count($done['added'])));
        \WP_CLI::log(sprintf('%-8s %d', 'Removed', count($done['removed'])));

        foreach (array_count_values(array_column($strings, 'domain')) as $domain => $count) {
            \WP_CLI::log(sprintf('  %-18s %d', $domain, $count) . (isset($done['kept'][$domain]) ? '  (not read: kept)' : ''));
        }

        \WP_CLI::success($dry ? 'Dry run: nothing changed.' : 'Admin strings read.');
    }

    /**
     * Fetch every published page of the translated types in its language,
     * and check what it says: the status, `<html lang>` and the hreflang
     * links, each that alternates() gives and none on a page it gives none.
     *
     * ## OPTIONS
     *
     * [--post_type=<type>]
     * : Only this type (default: every translated type with a public URL).
     *
     * @param list<string>         $args
     * @param array<string,string> $assoc
     */
    public function check(array $args, array $assoc): void
    {
        $types = !empty($assoc['post_type'])
            ? [$assoc['post_type']]
            : array_values(array_filter(Core\translated_post_types(), fn ($type) => is_post_type_viewable($type)));

        $posts = get_posts([
            'post_type'      => $types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'lang'           => 'all',
        ]);

        $failed = 0;

        foreach ($posts as $post) {
            $code     = Core\language_of($post->ID);
            $url      = (string) get_permalink($post);
            $response = wp_remote_get($url, ['timeout' => 20, 'redirection' => 0, 'sslverify' => false]);

            if (is_wp_error($response)) {
                \WP_CLI::warning("{$post->ID} $url: " . $response->get_error_message());
                $failed++;

                continue;
            }

            $status   = (int) wp_remote_retrieve_response_code($response);
            $body     = (string) wp_remote_retrieve_body($response);
            $problems = [];

            if ($status !== 200) {
                $problems[] = "status $status";
            }

            $locale = Core\language((string) $code)['locale'] ?? '';
            $html   = str_replace('_', '-', $locale);

            if ($code !== null && !preg_match('/<html[^>]*\blang="' . preg_quote($html, '/') . '"/i', $body)) {
                $problems[] = "no <html lang=\"$html\">";
            }

            // What the head should print: nothing for a page alone, no link
            // to a draft or a page with a password.
            $alternates = Core\alternates($post->ID);

            foreach ($alternates as $hreflang => $href) {
                if (!preg_match('/<link[^>]+hreflang="' . preg_quote((string) $hreflang, '/') . '"[^>]+href="' . preg_quote(esc_url($href), '/') . '"/i', $body)) {
                    $problems[] = "no hreflang $hreflang for $href";
                }
            }

            if (!$alternates && preg_match('/<link[^>]+hreflang=/i', $body)) {
                $problems[] = 'hreflang links on a page with no translation to point at';
            }

            if ($problems) {
                $failed++;
                \WP_CLI::warning("{$post->ID} [$code] $url: " . implode(', ', $problems));
            } else {
                \WP_CLI::log("ok {$post->ID} [$code] $url");
            }
        }

        if ($failed) {
            \WP_CLI::error("$failed of " . count($posts) . ' pages have problems.');
        }

        \WP_CLI::success(count($posts) . ' pages checked.');
    }
}
