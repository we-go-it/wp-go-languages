<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;

/**
 * Terms of a translated taxonomy, in the admin as pages are: the list's
 * Languages column, its "+" and dialog (list.js, kind `terms`), a Language
 * section on the term's edit screen (editor-panel.js, outside the block
 * editor), and the routes they call, under `wgl/v1/terms/<id>`. A term's
 * translation is always a copy: terms have no blocks.
 */

function terms_boot(): void
{
    add_action('admin_init', __NAMESPACE__ . '\\term_columns');
    add_action('admin_post_wgl_create_term_translation', __NAMESPACE__ . '\\create_term_translation_action');
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\term_assets', 20);
    add_action('rest_api_init', __NAMESPACE__ . '\\term_rest_routes');
}

/**
 * The column, and the edit screen's section, on every translated taxonomy.
 */
function term_columns(): void
{
    foreach (Core\translated_taxonomies() as $taxonomy) {
        add_filter("manage_edit-{$taxonomy}_columns", __NAMESPACE__ . '\\add_column');
        add_filter("manage_{$taxonomy}_custom_column", __NAMESPACE__ . '\\term_column', 10, 3);
        add_action("{$taxonomy}_edit_form", __NAMESPACE__ . '\\term_section', 10, 2);
    }
}

/**
 * A term's Languages cell.
 */
function term_column(string $content, string $column, int $term_id): string
{
    return $column === 'wgl_languages' ? languages_cell($term_id, 'term') : $content;
}

/**
 * A term's name for a tooltip: plain text, or `#id` if it has none.
 */
function term_title(int $term_id): string
{
    $term = get_term($term_id);

    return $term instanceof \WP_Term && $term->name !== '' ? wp_strip_all_tags(html_entity_decode($term->name, ENT_QUOTES)) : '#' . $term_id;
}

/**
 * A term's edit screen.
 */
function term_edit_url(int $term_id): string
{
    $term = get_term($term_id);

    return $term instanceof \WP_Term ? (string) get_edit_term_link($term, $term->taxonomy) : '';
}

/**
 * The link that makes a term's translation: a nonce'd admin-post URL.
 */
function term_create_url(int $term_id, string $code): string
{
    return wp_nonce_url(add_query_arg([
        'action' => 'wgl_create_term_translation',
        'term'   => $term_id,
        'lang'   => $code,
    ], admin_url('admin-post.php')), 'wgl_create_term_translation_' . $term_id . '_' . $code);
}

/**
 * `admin-post.php?action=wgl_create_term_translation`: the translation made,
 * then its edit screen.
 */
function create_term_translation_action(): void
{
    $term_id = (int) ($_GET['term'] ?? 0);
    $code    = sanitize_key((string) ($_GET['lang'] ?? ''));

    check_admin_referer('wgl_create_term_translation_' . $term_id . '_' . $code);

    $term     = get_term($term_id);
    $taxonomy = $term instanceof \WP_Term ? get_taxonomy($term->taxonomy) : null;

    if (!$taxonomy || !current_user_can('edit_term', $term_id) || !current_user_can($taxonomy->cap->manage_terms)) {
        wp_die(esc_html_x('You cannot translate this item.', 'src/Admin/admin.php', 'wp-go-languages'), 403);
    }

    $new = Core\create_term_translation($term_id, $code);

    if (is_wp_error($new)) {
        wp_die(esc_html($new->get_error_message()), 400);
    }

    // the dialog's flag: its non-text fields follow the original
    if (($_GET['link'] ?? '') === '1') {
        Core\link_non_text($new, 'term');
    }

    wp_safe_redirect(add_query_arg('wgl_created', 'term', term_edit_url($new)));
    exit;
}

/**
 * What a term's Language section shows: as panel_data() for a post. A term's
 * translation is independent, any of its fields linked.
 *
 * @return array<string,mixed>
 */
function term_panel_data(int $term_id): array
{
    $own          = Core\language_of($term_id, 'term');
    $translations = Core\translations($term_id, 'term');
    $original     = Core\original($term_id, 'term');
    $languages    = [];

    foreach (Core\languages() as $code => $language) {
        $id = $translations[$code] ?? null;

        $languages[] = [
            'code'         => $code,
            'name'         => $language['name'],
            'flag'         => (string) Core\flag_url($code),
            'own'          => $code === $own,
            'id'           => $id,
            'title'        => $id ? term_title($id) : null,
            'status'       => null,
            'mode'         => $id && $id !== $term_id ? Core\mode_of($id, 'term') : null,
            'edit'         => $id && $id !== $term_id ? term_edit_url($id) : null,
            'default'      => $language['default'],
            'can_original' => $id ? Core\is_translation($id, 'term') : false,
            'is_original'  => $id !== null && count($translations) > 1 && $id === $original,
        ];
    }

    return [
        'language'     => $own,
        'mode'         => Core\mode_of($term_id, 'term'),
        'dropped'      => null,
        'original'     => $original !== $term_id ? [
            'id'    => $original,
            'title' => term_title($original),
            'edit'  => term_edit_url($original),
        ] : null,
        'languages'    => $languages,
        'can_relocate' => count($translations) <= 1,
        'can_original' => Core\is_translation($term_id, 'term'),
        'can_blocks' => false,
    ];
}

/**
 * The Language section, under the term's fields: editor-panel.js mounts the
 * panel in `#wgl-panel-root`.
 */
function term_section(\WP_Term $term, string $taxonomy): void
{
    printf(
        '<h2>%s</h2><div class="wgl-term-panel" style="max-width: 420px; margin-bottom: 24px;"><div id="wgl-panel-root"></div></div>',
        esc_html_x('Language', 'src/Admin/admin.php', 'wp-go-languages')
    );
}

/**
 * The scripts: the panel's on a term's edit screen, the "+" dialog's on its
 * taxonomy's list.
 */
function term_assets(string $hook): void
{
    $taxonomy = sanitize_key((string) ($_GET['taxonomy'] ?? ''));

    if (!in_array($hook, ['term.php', 'edit-tags.php'], true) || !Core\is_translated_taxonomy($taxonomy)) {
        return;
    }

    if ($hook === 'edit-tags.php') {
        list_scripts('fields');

        return;
    }

    $term = get_term((int) ($_GET['tag_ID'] ?? 0), $taxonomy);

    if ($term instanceof \WP_Term) {
        panel_script($term->term_id, 'term');
    }
}

/**
 * The routes a term's panel and the "+" dialog call, as the posts' (see
 * rest_routes(), rest_write_routes()), under `wgl/v1/terms/<id>`:
 * `candidates`, `link`, `unlink`, `original` and `language`, for who can edit
 * the term (and the other, to link or unlink it).
 */
function term_rest_routes(): void
{
    $id    = ['id' => ['type' => 'integer', 'required' => true]];
    $edit  = fn (\WP_REST_Request $request) => current_user_can('edit_term', (int) $request['id']);
    $other = fn (string $key) => fn (\WP_REST_Request $request) => $edit($request) && current_user_can('edit_term', (int) ($request[$key] ?: $request['id']));

    register_rest_route('wgl/v1', '/terms/(?P<id>\d+)/candidates', [
        'methods'             => 'GET',
        'permission_callback' => $edit,
        'args'                => $id + ['lang' => ['type' => 'string', 'default' => ''], 'search' => ['type' => 'string', 'default' => '']],
        'callback'            => function (\WP_REST_Request $request) {
            if ((string) $request['lang'] !== '' && !Core\is_language((string) $request['lang'])) {
                return new \WP_Error('wgl_bad_language', 'Not one of the site\'s languages.', ['status' => 400]);
            }

            return term_link_candidates((int) $request['id'], (string) $request['lang'], (string) $request['search']);
        },
    ]);

    register_rest_route('wgl/v1', '/terms/(?P<id>\d+)/link', [
        'methods'             => 'POST',
        'permission_callback' => $other('other'),
        'args'                => $id + ['other' => ['type' => 'integer', 'required' => true]],
        'callback'            => function (\WP_REST_Request $request) {
            $linked = Core\connect((int) $request['id'], (int) $request['other'], 'term');

            if (is_wp_error($linked)) {
                $linked->add_data(['status' => 400]);

                return $linked;
            }

            return term_panel_data((int) $request['id']);
        },
    ]);

    register_rest_route('wgl/v1', '/terms/(?P<id>\d+)/unlink', [
        'methods'             => 'POST',
        'permission_callback' => $other('target'),
        'args'                => $id + ['target' => ['type' => 'integer', 'default' => 0]],
        'callback'            => function (\WP_REST_Request $request) {
            $id     = (int) $request['id'];
            $target = (int) $request['target'] ?: $id;

            if (!in_array($target, Core\translations($id, 'term'), true) || count(Core\translations($id, 'term')) < 2) {
                return new \WP_Error('wgl_not_in_group', 'Not one of this term\'s translations.', ['status' => 400]);
            }

            Core\leave_group($target, 'term');

            return term_panel_data($id);
        },
    ]);

    register_rest_route('wgl/v1', '/terms/(?P<id>\d+)/original', [
        'methods'             => 'POST',
        'permission_callback' => $other('target'),
        'args'                => $id + ['target' => ['type' => 'integer', 'default' => 0]],
        'callback'            => function (\WP_REST_Request $request) {
            $id     = (int) $request['id'];
            $target = (int) $request['target'] ?: $id;

            if (!Core\is_translation($target, 'term') || !in_array($target, Core\translations($id, 'term'), true)) {
                return new \WP_Error('wgl_not_original', 'Only a translation in this term\'s group can become its original.', ['status' => 400]);
            }

            Core\choose_original($target, 'term');

            return term_panel_data($id);
        },
    ]);

    register_rest_route('wgl/v1', '/terms/(?P<id>\d+)/language', [
        'methods'             => 'POST',
        'permission_callback' => $edit,
        'args'                => $id + ['language' => ['type' => 'string', 'required' => true]],
        'callback'            => function (\WP_REST_Request $request) {
            $id   = (int) $request['id'];
            $code = (string) $request['language'];

            if (!Core\is_language($code)) {
                return new \WP_Error('wgl_bad_language', 'Not one of the site\'s languages.', ['status' => 400]);
            }

            if (count(Core\translations($id, 'term')) > 1) {
                return new \WP_Error('wgl_has_translations', 'A term with translations keeps its language.', ['status' => 400]);
            }

            Core\set_language($id, $code, 'term');

            return term_panel_data($id);
        },
    ]);
}

/**
 * The translation groups a term may join, as link_candidates() for a post:
 * one entry a group, terms of its taxonomy in a language (any other, with
 * `$code` empty), matching `$search`, whose group has no term in a language
 * of the term's.
 *
 * @return list<array<string,mixed>>
 */
function term_link_candidates(int $term_id, string $code, string $search = ''): array
{
    $term = get_term($term_id);
    $own  = Core\language_of($term_id, 'term');

    if (!$term instanceof \WP_Term || $code === $own) {
        return [];
    }

    $mine  = Core\translations($term_id, 'term');
    $found = get_terms([
        'taxonomy'   => $term->taxonomy,
        'hide_empty' => false,
        'search'     => $search,
        'exclude'    => [$term_id],
        'number'     => 60,
        'lang'       => $code !== '' ? $code : 'all',
        'fields'     => 'ids',
    ]);

    $out  = [];
    $seen = [];

    foreach (is_array($found) ? $found : [] as $candidate) {
        $candidate = (int) $candidate;
        $theirs    = Core\translations($candidate, 'term');
        $original  = Core\original($candidate, 'term');

        if (isset($seen[$original]) || !$theirs || array_intersect_key($mine, $theirs) || !current_user_can('edit_term', $candidate)) {
            continue;
        }

        $seen[$original] = true;
        $language        = (string) Core\language_of($original, 'term');
        $languages       = [];

        foreach ($theirs as $member_code => $member) {
            $languages[] = [
                'code' => $member_code,
                'name' => Core\language($member_code)['name'] ?? $member_code,
                'flag' => (string) Core\flag_url($member_code),
            ];
        }

        $out[] = [
            'id'            => $original,
            'title'         => term_title($original),
            'status'        => 'publish',
            'edit'          => term_edit_url($original),
            'language'      => $language,
            'language_name' => Core\language($language)['name'] ?? $language,
            'flag'          => (string) Core\flag_url($language),
            'languages'     => $languages,
        ];

        if (count($out) === 20) {
            break;
        }
    }

    return $out;
}
