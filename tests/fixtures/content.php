<?php

/**
 * What the tests build their content with: pages in a language, blocks with
 * the theme's real names and field keys (WpGoBlocks::field_data()), saves as
 * the editor makes them, and a way through the redirects and exits of the
 * admin's actions. Required by a test (`require_once __DIR__ .
 * '/fixtures/content.php'`), inside WordPress: not an `@require`.
 */

use WpGo\Languages\Core\State;

/**
 * A published page in a language. Made "in" it (State::$inserting), as
 * create_translation() makes one, so its slug is checked in its language.
 *
 * @param array<string,mixed> $extra More post fields.
 */
function wgt_page(string $title, string $content = '', string $code = 'it', array $extra = []): int
{
    State::$inserting = $code;

    try {
        $id = wp_insert_post(wp_slash($extra + [
            'post_type'    => 'page',
            'post_title'   => $title,
            'post_status'  => 'publish',
            'post_content' => $content,
        ]), true);
    } finally {
        State::$inserting = null;
    }

    if (is_wp_error($id)) {
        throw new RuntimeException('wgt_page: ' . $id->get_error_message());
    }

    WpGoLanguages::set_language($id, $code);

    return (int) $id;
}

/** A post's content, fresh from the database. */
function wgt_content(int $id): string
{
    clean_post_cache($id);

    return (string) get_post_field('post_content', $id);
}

/**
 * A post's blocks, the empty chunks between them left out.
 *
 * @return list<array<string,mixed>>
 */
function wgt_blocks(int|string $from): array
{
    $content = is_int($from) ? wgt_content($from) : $from;

    return array_values(array_filter(parse_blocks($content), fn ($block) => !empty($block['blockName'])));
}

/**
 * Save a post's content as the editor does: wp_update_post(), slashed.
 *
 * @param string|list<array<string,mixed>> $content
 */
function wgt_save(int $id, string|array $content, array $extra = []): void
{
    wp_update_post(wp_slash(['ID' => $id, 'post_content' => is_array($content) ? serialize_blocks($content) : $content] + $extra));
    clean_post_cache($id);
}

/** Write a post's content straight into the table: no hook, no ID stamped. */
function wgt_raw_content(int $id, string $content): void
{
    global $wpdb;

    $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $id]);
    clean_post_cache($id);
}

/**
 * An ACF block of the theme, its data from WpGoBlocks::field_data().
 *
 * @param array<string,mixed>             $data
 * @param list<array<string,mixed>>       $inner
 * @return array<string,mixed>
 */
function wgt_acf(string $slug, array $data, array $inner = [], string $id = ''): array
{
    $attrs = ['name' => "acf/$slug", 'mode' => 'preview', 'data' => WpGoBlocks::field_data($slug, $data)];

    if ($id !== '') {
        $attrs['metadata'] = ['wgId' => $id];
    }

    return [
        'blockName'    => "acf/$slug",
        'attrs'        => $attrs,
        'innerBlocks'  => $inner,
        'innerHTML'    => '',
        'innerContent' => array_fill(0, count($inner), null),
    ];
}

/**
 * A core paragraph.
 *
 * @return array<string,mixed>
 */
function wgt_para(string $text, string $id = ''): array
{
    return [
        'blockName'    => 'core/paragraph',
        'attrs'        => $id !== '' ? ['metadata' => ['wgId' => $id]] : [],
        'innerBlocks'  => [],
        'innerHTML'    => "<p>$text</p>",
        'innerContent' => ["<p>$text</p>"],
    ];
}

/** A block's ID (`metadata.wgId`), '' if it has none. */
function wgt_id(array $block): string
{
    return (string) ($block['attrs']['metadata']['wgId'] ?? '');
}

/** What a callback prints. */
function wgt_output(callable $callback): string
{
    ob_start();

    try {
        $callback();
    } finally {
        $output = (string) ob_get_clean();
    }

    return $output;
}

/** Thrown in place of a redirect's exit, or of wp_die(). */
final class WgtStop extends RuntimeException
{
    public function __construct(public string $kind, public string $target, public int $status = 0)
    {
        parent::__construct("$kind: $target");
    }
}

/**
 * Run an admin action that ends in wp_safe_redirect() + exit, or wp_die():
 * the redirect's location (or the die's message) instead of the exit.
 *
 * @return array{kind:string, target:string, status:int}
 */
function wgt_stopped(callable $callback): array
{
    $redirect = function ($location, $status) {
        throw new WgtStop('redirect', (string) $location, (int) $status);
    };
    $die = fn () => function ($message, $title = '', $args = []) {
        $message = $message instanceof WP_Error ? $message->get_error_message() : (string) $message;
        $status  = is_array($args) ? (int) ($args['response'] ?? 0) : (int) $args;

        throw new WgtStop('die', $message, $status);
    };

    // wp-cli's own warning on a redirect, with a backtrace: not one here
    $cli = remove_filter('wp_redirect', 'WP_CLI\\Utils\\wp_redirect_handler');

    add_filter('wp_redirect', $redirect, PHP_INT_MAX, 2);
    add_filter('wp_die_handler', $die, PHP_INT_MAX);

    try {
        $callback();
    } catch (WgtStop $stop) {
        return ['kind' => $stop->kind, 'target' => $stop->target, 'status' => $stop->status];
    } finally {
        remove_filter('wp_redirect', $redirect, PHP_INT_MAX);
        remove_filter('wp_die_handler', $die, PHP_INT_MAX);

        if ($cli) {
            add_filter('wp_redirect', 'WP_CLI\\Utils\\wp_redirect_handler');
        }
    }

    return ['kind' => 'none', 'target' => '', 'status' => 0];
}

/**
 * Run a callback as the site (State::$front true: home_url() and the locale
 * follow the current language), in a language: what a page of the site in it
 * would see.
 *
 * @template T
 * @param callable(): T $callback
 * @return T
 */
function wgt_as_site(string $code, callable $callback): mixed
{
    $front   = State::$front;
    $context = State::$context;

    State::$front   = true;
    State::$context = 'site';

    try {
        return WpGoLanguages::in($code, $callback);
    } finally {
        State::$front   = $front;
        State::$context = $context;
    }
}

/** A temporary folder of the test's, removed by wgt_cleanup(). */
function wgt_temp_dir(string $name): string
{
    $dir = sys_get_temp_dir() . '/wgl-test-' . $name . '-' . getmypid();
    wp_mkdir_p($dir);
    $GLOBALS['wgt_temp'][] = $dir;

    return $dir;
}

/** Remove the temporary folders made, at the end of the test. */
function wgt_cleanup(): void
{
    foreach ((array) ($GLOBALS['wgt_temp'] ?? []) as $dir) {
        if (!is_dir($dir)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }

    $GLOBALS['wgt_temp'] = [];
}

register_shutdown_function('wgt_cleanup');
