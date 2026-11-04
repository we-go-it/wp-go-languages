<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;

/**
 * Translating from the lists, for one item or many: what a post or term
 * lacks, made in one go.
 *
 *   - "Translate", a row action under each item that lacks a language
 *   - "Translate…", a bulk action, for the rows ticked
 *
 * Both open a dialog (list.js): the languages to make (every missing one
 * ticked), and for a type with blocks, an independent translation or a fixed translation.
 * It then asks `wgl/v1/{posts|terms}/<id>/translate` for each item in turn
 * and reloads the list, with how many were made in the address
 * (`wgl_translated`, `wgl_failed`) for the notice.
 *
 * Without the script, both still work, as the "+" does: the row action's
 * link (admin-post.php, `wgl_translate_missing`) and the bulk action's
 * submit (`handle_bulk_actions-edit-{type|taxonomy}`) make an independent translation (a
 * term, its copy) in every language the item lacks.
 */

function bulk_boot(): void
{
    add_action('admin_init', __NAMESPACE__ . '\\bulk_hooks');
    add_action('admin_post_wgl_translate_missing', __NAMESPACE__ . '\\translate_missing_action');
    add_action('admin_notices', __NAMESPACE__ . '\\bulk_notices');
    add_action('rest_api_init', __NAMESPACE__ . '\\bulk_rest_routes');
    add_filter('removable_query_args', fn (array $args) => [...$args, 'wgl_translated', 'wgl_failed']);
}

/**
 * The row action and the bulk action, on every translated type's list and
 * every translated taxonomy's.
 */
function bulk_hooks(): void
{
    foreach (Core\translated_post_types() as $type) {
        add_filter("bulk_actions-edit-{$type}", __NAMESPACE__ . '\\bulk_action_option');
        add_filter("handle_bulk_actions-edit-{$type}", fn ($back, $action, $ids) => handle_bulk_action((string) $back, (string) $action, (array) $ids, 'post'), 10, 3);
    }

    foreach (Core\translated_taxonomies() as $taxonomy) {
        add_filter("bulk_actions-edit-{$taxonomy}", __NAMESPACE__ . '\\bulk_action_option');
        add_filter("handle_bulk_actions-edit-{$taxonomy}", fn ($back, $action, $ids) => handle_bulk_action((string) $back, (string) $action, (array) $ids, 'term'), 10, 3);
        add_filter("{$taxonomy}_row_actions", fn ($actions, $term) => row_action((array) $actions, (int) $term->term_id, 'term'), 10, 2);
    }

    // hierarchical types' rows go through page_row_actions
    add_filter('post_row_actions', fn ($actions, $post) => row_action((array) $actions, (int) $post->ID, 'post'), 10, 2);
    add_filter('page_row_actions', fn ($actions, $post) => row_action((array) $actions, (int) $post->ID, 'post'), 10, 2);
}

/**
 * "Translate…" among the list's bulk actions.
 *
 * @param array<string,string> $actions
 * @return array<string,string>
 */
function bulk_action_option(array $actions): array
{
    $actions['wgl_translate'] = _x('Translate…', 'src/Admin/bulk.php', 'wp-go-languages');

    return $actions;
}

/**
 * Whether the current user may translate an item: edit it, and make one of
 * its type (or manage its taxonomy's terms).
 */
function can_translate(int $id, string $kind): bool
{
    if ($kind === 'term') {
        $term = get_term($id);
        $taxonomy = $term instanceof \WP_Term ? get_taxonomy($term->taxonomy) : null;

        return $taxonomy && Core\is_translated_taxonomy($term->taxonomy) && current_user_can('edit_term', $id) && current_user_can($taxonomy->cap->manage_terms);
    }

    $type = get_post_type_object((string) get_post_type($id));

    return $type && Core\is_translated_post_type($type->name) && current_user_can('edit_post', $id) && current_user_can($type->cap->create_posts);
}

/**
 * The languages an item's group has no translation in, as its Languages
 * cell offers them: none for an item with no language yet.
 *
 * @return string[]
 */
function missing_languages(int $id, string $kind): array
{
    if (Core\language_of($id, $kind) === null) {
        return [];
    }

    return array_values(array_diff(array_keys(Core\languages()), array_keys(Core\translations($id, $kind))));
}

/**
 * Make an item's missing translations, in `$codes` (every missing language
 * when empty), as `$mode` (a post's: `independent` or `text`; Core gives the
 * default language's an independent translation, and a type without blocks too). A
 * language the item has meanwhile is skipped, not failed.
 *
 * @param string[] $codes
 * @return array{created: array<string,int>, failed: array<string,string>} By language: the new item's ID, or why it wasn't made.
 */
function translate_missing(int $id, string $kind, array $codes = [], string $mode = 'independent', bool $copy = true): array
{
    $done = ['created' => [], 'failed' => []];
    $missing = missing_languages($id, $kind);

    foreach ($codes ? array_intersect($codes, $missing) : $missing as $code) {
        // a term has no fixed blocks: all its own, an independent translation
        $new = $kind === 'term' ? Core\create_term_translation($id, $code, Core\normalize_mode($mode) === 'text' ? 'text' : 'independent') : Core\create_translation($id, $code, Core\best_mode((string) get_post_type($id), $mode), $copy);

        if (is_wp_error($new)) {
            $done['failed'][$code] = $new->get_error_message();
        } else {
            $done['created'][$code] = (int) $new;
        }
    }

    return $done;
}

/**
 * A row's "Translate", for an item that lacks a language and a user who may
 * make it. Its link, without the script, makes them all as independent translations.
 *
 * @param array<string,string> $actions
 * @return array<string,string>
 */
function row_action(array $actions, int $id, string $kind): array
{
    if (($kind === 'post' && !Core\is_translated_post_type((string) get_post_type($id))) || !missing_languages($id, $kind) || !can_translate($id, $kind)) {
        return $actions;
    }

    $url = wp_nonce_url(add_query_arg([
        'action' => 'wgl_translate_missing',
        'kind'   => $kind,
        'id'     => $id,
    ], admin_url('admin-post.php')), "wgl_translate_missing_{$kind}_{$id}");

    $actions['wgl_translate'] = sprintf(
        '<a href="%s" class="wgl-translate" data-wgl-id="%d" aria-label="%s">%s</a>',
        esc_url($url),
        $id,
        esc_attr(sprintf(
            /* translators: %s: the item's title */
            _x('Translate “%s”', 'src/Admin/bulk.php', 'wp-go-languages'),
            $kind === 'term' ? term_title($id) : page_title($id)
        )),
        esc_html_x('Translate', 'src/Admin/bulk.php', 'wp-go-languages')
    );

    return $actions;
}

/**
 * The row action's link, without the script: every missing language as a
 * duplicate, then back to the list with the notice.
 */
function translate_missing_action(): void
{
    $kind = ($_GET['kind'] ?? '') === 'term' ? 'term' : 'post';
    $id   = (int) ($_GET['id'] ?? 0);

    check_admin_referer("wgl_translate_missing_{$kind}_{$id}");

    if (!can_translate($id, $kind)) {
        wp_die(esc_html_x('You cannot translate this item.', 'src/Admin/admin.php', 'wp-go-languages'), 403);
    }

    $done = translate_missing($id, $kind);

    wp_safe_redirect(translated_url(wp_get_referer() ?: admin_url(), count($done['created']), count($done['failed'])));
    exit;
}

/**
 * The bulk action submitted without the script: each item ticked that the
 * user may translate, into every language it lacks, as independent translations.
 *
 * @param array<int|string> $ids
 */
function handle_bulk_action(string $back, string $action, array $ids, string $kind): string
{
    if ($action !== 'wgl_translate') {
        return $back;
    }

    $created = $failed = 0;

    foreach (array_map('intval', $ids) as $id) {
        if (!can_translate($id, $kind)) {
            continue;
        }

        $done = translate_missing($id, $kind);
        $created += count($done['created']);
        $failed += count($done['failed']);
    }

    return translated_url($back, $created, $failed);
}

/** The list's address, with how many translations were made, and failed, for the notice. */
function translated_url(string $back, int $created, int $failed): string
{
    return add_query_arg(array_filter(['wgl_translated' => $created, 'wgl_failed' => $failed]) ?: ['wgl_translated' => 0], remove_query_arg(['wgl_translated', 'wgl_failed'], $back));
}

/**
 * How many translations were made, on the list: as drafts for posts, done
 * for terms (which have no draft). And how many couldn't be.
 */
function bulk_notices(): void
{
    if (!isset($_GET['wgl_translated']) && !isset($_GET['wgl_failed'])) {
        return;
    }

    $created = (int) ($_GET['wgl_translated'] ?? 0);
    $failed  = (int) ($_GET['wgl_failed'] ?? 0);
    $terms   = (get_current_screen()->base ?? '') === 'edit-tags';

    $count = fn (int $n, string $one, string $many) => $n === 1 ? $one : sprintf($many, number_format_i18n($n));

    if ($created || !$failed) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(match (true) {
            $created === 0 => _x('Nothing to translate: every language was there already.', 'src/Admin/bulk.php', 'wp-go-languages'),
            $terms         => $count($created, _x('1 translation created: translate its name and description.', 'src/Admin/bulk.php', 'wp-go-languages'), _x('%s translations created: translate their names and descriptions.', 'src/Admin/bulk.php', 'wp-go-languages')),
            default        => $count($created, _x('1 translation created as a draft: translate it, then publish it.', 'src/Admin/bulk.php', 'wp-go-languages'), _x('%s translations created as drafts: translate them, then publish them.', 'src/Admin/bulk.php', 'wp-go-languages')),
        }) . '</p></div>';
    }

    if ($failed) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($count($failed, _x('1 translation couldn\'t be created.', 'src/Admin/bulk.php', 'wp-go-languages'), _x('%s translations couldn\'t be created.', 'src/Admin/bulk.php', 'wp-go-languages'))) . '</p></div>';
    }
}

/**
 * `wgl/v1/posts/<id>/translate` and `wgl/v1/terms/<id>/translate`, POST, for
 * the dialog: `langs`, the languages to make (every missing one when empty),
 * and for a post `mode`. Answers what was made and what wasn't, by
 * language, and where each new one is edited:
 * `{ created: { en: 123 }, failed: { de: "…" }, edit: { en: "…post.php?post=123&action=edit" } }`.
 */
function bulk_rest_routes(): void
{
    foreach (['posts' => 'post', 'terms' => 'term'] as $path => $kind) {
        register_rest_route('wgl/v1', "/$path/(?P<id>\\d+)/translate", [
            'methods'             => 'POST',
            'permission_callback' => fn (\WP_REST_Request $request) => can_translate((int) $request['id'], $kind),
            'args'                => [
                'id'    => ['type' => 'integer', 'required' => true],
                'langs' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => []],
                'mode'  => ['type' => 'string', 'enum' => Core\mode_names(), 'default' => 'independent'],
                'copy'  => ['type' => 'boolean', 'default' => true],
            ],
            'callback'            => function (\WP_REST_Request $request) use ($kind) {
                $codes = array_map('strval', (array) $request['langs']);
                $bad = array_filter($codes, fn ($code) => !Core\is_language($code));

                if ($bad) {
                    return new \WP_Error('wgl_bad_language', sprintf('Not one of the site\'s languages: %s.', implode(', ', $bad)), ['status' => 400]);
                }

                $done = translate_missing((int) $request['id'], $kind, $codes, (string) $request['mode'], (bool) $request['copy']);

                // empty as objects, not lists; each new one's edit screen (the
                // "+" dialog with AI opens it once translated)
                return [
                    'created' => (object) $done['created'],
                    'failed'  => (object) $done['failed'],
                    'edit'    => (object) array_map(fn (int $new) => $kind === 'term' ? term_edit_url($new) : edit_url($new), $done['created']),
                ];
            },
        ]);
    }
}
