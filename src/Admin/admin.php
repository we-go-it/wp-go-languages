<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;

/**
 * The admin: the lists' language column, making a translation, and the
 * editor's Language panel.
 */

function boot(): void
{
    add_action('admin_menu', __NAMESPACE__ . '\\add_settings_page');
    add_action('admin_post_wgl_save_settings', __NAMESPACE__ . '\\save_settings');
    add_action('admin_post_wgl_create_translation', __NAMESPACE__ . '\\create_translation_action');
    add_action('admin_init', __NAMESPACE__ . '\\list_columns');
    add_action('rest_api_init', __NAMESPACE__ . '\\rest_fields');
    add_action('rest_api_init', __NAMESPACE__ . '\\rest_routes');
    add_action('rest_api_init', __NAMESPACE__ . '\\rest_write_routes');
    add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\editor_panel');
    add_action('admin_notices', __NAMESPACE__ . '\\notices');
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\flag_styles');
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\settings_assets', 20);
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\list_assets', 20);
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\cf7_assets', 20);
    add_action('wpcf7_admin_footer', __NAMESPACE__ . '\\cf7_panel_box');
    add_filter('wpcf7_editor_panels', __NAMESPACE__ . '\\cf7_fixed_panels', 20);
    add_filter('admin_body_class', __NAMESPACE__ . '\\cf7_body_class');
    add_filter('wp_go_cf7/translation_note', __NAMESPACE__ . '\\cf7_builder_note', 10, 2);
    add_action('add_meta_boxes', __NAMESPACE__ . '\\classic_box', 10, 2);
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\classic_assets', 20);
    add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\flag_styles');
    add_filter('quick_edit_dropdown_pages_args', __NAMESPACE__ . '\\quick_edit_parents');
    add_filter('wp_dropdown_pages', __NAMESPACE__ . '\\quick_edit_parents_note', 10, 2);
}

/** How many published pages Quick Edit's parent dropdown lists, at most, before it goes. */
const MAX_PARENT_DROPDOWN = 1000;

/**
 * A list's Quick Edit and Bulk Edit print a dropdown of every page of the language, twice
 * on each view of the list: past a thousand pages, megabytes of options that take longer
 * than the list. Left out then, core keeps the parent as it is when none is posted; the
 * page's editor, which searches, changes it.
 *
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
function quick_edit_parents(array $args): array
{
    $count = (int) (wp_count_posts((string) ($args['post_type'] ?? 'page'))->publish ?? 0);

    /**
     * How many published pages a list's Quick Edit lists as parents, at most.
     *
     * @param int $max
     */
    if ($count > (int) apply_filters('wp_go_languages/max_parent_dropdown', MAX_PARENT_DROPDOWN)) {
        // an ID no page has: it finds none, and prints no select
        $args['include']        = [0];
        $args['wgl_no_parents'] = true;
    }

    return $args;
}

/**
 * Where the parent dropdown went, a word why.
 *
 * @param array<string,mixed> $args
 */
function quick_edit_parents_note(string $output, array $args): string
{
    return empty($args['wgl_no_parents'])
        ? $output
        : '<em class="wgl-no-parents">' . esc_html_x('Too many pages to list: changed in the page\'s editor.', 'src/Admin/admin.php', 'wp-go-languages') . '</em>';
}

/**
 * The link that makes a translation: a nonce'd admin-post URL.
 */
function create_url(int $post_id, string $code, string $mode = 'independent'): string
{
    return wp_nonce_url(add_query_arg([
        'action' => 'wgl_create_translation',
        'post'   => $post_id,
        'lang'   => $code,
        'mode'   => $mode,
    ], admin_url('admin-post.php')), 'wgl_create_translation_' . $post_id . '_' . $code);
}

/**
 * A post's edit screen. CF7 registers its forms with no edit link of
 * WordPress's (get_edit_post_link() gives ''): a form opens in CF7's screen.
 */
function edit_url(int $post_id): string
{
    if (get_post_type($post_id) === Core\CF7_TYPE) {
        return current_user_can('edit_post', $post_id)
            ? add_query_arg(['page' => 'wpcf7', 'post' => $post_id, 'action' => 'edit'], admin_url('admin.php'))
            : '';
    }

    return (string) get_edit_post_link($post_id, 'raw');
}

/**
 * Make a translation, then open it in the editor.
 */
function create_translation_action(): void
{
    $post_id = (int) ($_GET['post'] ?? 0);
    $code    = sanitize_key((string) ($_GET['lang'] ?? ''));
    $mode    = sanitize_key((string) ($_GET['mode'] ?? 'independent'));

    check_admin_referer('wgl_create_translation_' . $post_id . '_' . $code);

    $type = get_post_type_object((string) get_post_type($post_id));

    if (!$type || !current_user_can('edit_post', $post_id) || !current_user_can($type->cap->create_posts)) {
        wp_die(esc_html_x('You cannot translate this item.', 'src/Admin/admin.php', 'wp-go-languages'), 403);
    }

    // the "+" dialog's toggle: on unless it says off
    $new = Core\create_translation($post_id, $code, $mode, ($_GET['copy'] ?? '1') !== '0');

    if (is_wp_error($new)) {
        wp_die(esc_html($new->get_error_message()), 400);
    }

    // the dialog's other flag: its non-text fields follow the original
    if (($_GET['link'] ?? '') === '1') {
        Core\link_non_text($new);
    }

    wp_safe_redirect(add_query_arg('wgl_created', 1, edit_url($new)));
    exit;
}

/**
 * A notice on a translation just made.
 */
function notices(): void
{
    if (!isset($_GET['wgl_created'])) {
        return;
    }

    // a term has no draft: it's there as soon as it's made
    echo '<div class="notice notice-success is-dismissible"><p>'
        . ($_GET['wgl_created'] === 'term'
            ? esc_html_x('Translation created: translate its name and description.', 'src/Admin/admin.php', 'wp-go-languages')
            : esc_html_x('Translation created as a draft: translate it, then publish it.', 'src/Admin/admin.php', 'wp-go-languages'))
        . '</p></div>';
}

/**
 * The language column, on every translated type's list, and its counts.
 */
function list_columns(): void
{
    foreach (Core\translated_post_types() as $type) {
        add_filter("manage_{$type}_posts_columns", __NAMESPACE__ . '\\add_column');
        add_action("manage_{$type}_posts_custom_column", __NAMESPACE__ . '\\column', 10, 2);
        add_filter("views_edit-{$type}", __NAMESPACE__ . '\\list_views');
    }

    // CF7's own list: the header only, after CF7's (which ignores what it's
    // given); its table has no hook for a cell, filled by list.js
    if (Core\is_translated_post_type(Core\CF7_TYPE)) {
        add_filter('manage_' . Core\cf7_screen_id() . '_columns', __NAMESPACE__ . '\\add_column', 20);
    }
}

/**
 * The list's "Mine" in the language. Its other counts come from
 * wp_count_posts(), in the language already (Core\count_posts()); "Mine"
 * WordPress counts with a query of its own, so its number is put right here,
 * and the link dropped where WordPress would not show it: no post, or all.
 *
 * @param array<string,string> $views
 * @return array<string,string>
 */
function list_views(array $views): array
{
    $type = get_current_screen()->post_type ?? '';

    if (!isset($views['mine']) || $type === '') {
        return $views;
    }

    $mine = Core\count_user_posts_in_language($type, get_current_user_id());

    if ($mine === null) {
        return $views;
    }

    $counts = wp_count_posts($type, 'readable');
    $all    = array_sum((array) $counts);

    foreach (get_post_stati(['show_in_admin_all_list' => false]) as $state) {
        $all -= $counts->$state ?? 0;
    }

    if ($mine === 0 || $mine === $all) {
        unset($views['mine']);

        return $views;
    }

    $views['mine'] = (string) preg_replace(
        '/(<span class="count">\()[^)]*(\)<\/span>)/',
        '${1}' . number_format_i18n($mine) . '${2}',
        $views['mine'],
        1
    );

    return $views;
}

/**
 * @param array<string,string> $columns
 * @return array<string,string>
 */
function add_column(array $columns): array
{
    $out = [];

    foreach ($columns as $key => $label) {
        $out[$key] = $label;

        // after the title, a term's name
        if ($key === 'title' || $key === 'name') {
            $out['wgl_languages'] = _x('Languages', 'src/Admin/admin.php', 'wp-go-languages');
        }
    }

    if (!isset($out['wgl_languages'])) {
        $out['wgl_languages'] = _x('Languages', 'src/Admin/admin.php', 'wp-go-languages');
    }

    return $out;
}

/**
 * A row's languages, each by its flag: its own (ringed), then each other's
 * translation, a link to edit it (its status in the title), or a faded flag
 * with a "+" that makes it. A lock on a flag's corner marks a fixed
 * translation, the row's own or another; a star on the top left corner, the
 * group's original, the page the others are translated from. Styled by
 * flags.css.
 */
function column(string $column, int $post_id): void
{
    if ($column === 'wgl_languages') {
        prime_list_rows();

        echo languages_cell($post_id); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in it
    }
}

/**
 * Every row's translations at once, as the first cell asks, not row by row. A
 * hierarchical type's list holds the IDs of all its posts, of which it shows a page: not
 * those, which would read every one, nor anything it can't tell apart from them.
 */
function prime_list_rows(): void
{
    static $primed = false;

    if ($primed) {
        return;
    }

    $primed = true;
    $posts  = $GLOBALS['wp_query']->posts ?? [];

    if ($posts && count($posts) <= 200 && !is_post_type_hierarchical((string) (get_current_screen()->post_type ?? ''))) {
        Core\prime_translations(array_column($posts, 'ID'), true);
    }
}

/**
 * The Languages cell of a post, or of a term (`$kind`): see column().
 */
function languages_cell(int $id, string $kind = 'post'): string
{
    $term = $kind === 'term';

    // its group, and each one's status and mode, at once: a list has many rows
    if (!$term) {
        Core\prime_translations([$id], true);
    }

    $own          = Core\language_of($id, $kind);
    $translations = Core\translations($id, $kind);
    $original     = count($translations) > 1 ? Core\original($id, $kind) : 0;
    $title        = fn (int $item) => $term ? term_title($item) : page_title($item);
    $out          = '<span class="wgl-langs">';

    foreach (Core\languages() as $code => $language) {
        $star = $original && ($translations[$code] ?? 0) === $original ? original_star() : '';

        if ($code === $own) {
            $out .= sprintf(
                '<span class="wgl-lang wgl-lang--own" title="%s">%s%s%s</span>',
                /* translators: 1: language name, 2: page title */
                esc_attr(sprintf(_x('%1$s: %2$s', 'src/Admin/admin.php', 'wp-go-languages'), $language['name'], $title($id))),
                flag($code),
                $star,
                $term ? '' : fixed_lock($id)
            );

            continue;
        }

        $other = $translations[$code] ?? null;

        if ($other) {
            if ($term) {
                $tooltip = sprintf(_x('%1$s: %2$s', 'src/Admin/admin.php', 'wp-go-languages'), $language['name'], $title($other));
            } else {
                $status  = get_post_status_object((string) get_post_status($other));
                $tooltip = sprintf(
                    /* translators: 1: language name, 2: page title, 3: post status, 4: " · fixed" or nothing */
                    _x('%1$s: %2$s (%3$s%4$s)', 'src/Admin/admin.php', 'wp-go-languages'),
                    $language['name'],
                    $title($other),
                    $status ? $status->label : '',
                    Core\mode_of($other) === 'blocks' ? ' · ' . _x('fixed layout', 'src/Admin/admin.php', 'wp-go-languages') : ''
                );
            }

            // its ID, language and status: what the Trash's question lists (list.js)
            $out .= sprintf(
                '<a class="wgl-lang" href="%s" title="%s" data-wgl-translation="%d" data-wgl-lang-name="%s" data-wgl-status="%s">%s%s%s</a>',
                esc_url($term ? term_edit_url($other) : edit_url($other)),
                esc_attr($tooltip),
                $other,
                esc_attr($language['name']),
                esc_attr($term ? '' : (string) get_post_status($other)),
                flag($code),
                $star,
                fixed_lock($other, $term ? 'term' : 'post')
            );
        } elseif ($own !== null && current_user_can($term ? 'edit_term' : 'edit_post', $id)) {
            // without the script (list.js), an independent translation; with it, the dialog
            // a fixed layout: a type with blocks, or a form, its own way; not a term
            $structure = !$term && Core\can_be_fixed((string) get_post_type($id)) ? create_url($id, $code, 'blocks') : '';

            $out .= sprintf(
                '<a class="wgl-lang wgl-lang--missing" href="%s" title="%s" data-wgl-id="%d" data-wgl-kind="%s" data-wgl-lang="%s" data-wgl-lang-name="%s" data-wgl-title="%s" data-wgl-blocks="%s" data-wgl-default="%d" data-wgl-chosen="%d" data-wgl-copy="%d">%s%s<span class="wgl-lang__badge dashicons dashicons-plus" aria-hidden="true"></span></a>',
                esc_url($term ? term_create_url($id, $code) : create_url($id, $code)),
                esc_attr(sprintf(
                    /* translators: %s: language name */
                    _x('Translate into %s', 'src/Admin/admin.php', 'wp-go-languages'),
                    $language['name']
                )),
                $id,
                $term ? 'terms' : 'posts',
                esc_attr($code),
                esc_attr($language['name']),
                esc_attr($title($id)),
                esc_url($structure),
                $language['default'] ? 1 : 0,
                Core\original_is_chosen($id, $term ? 'term' : 'post') ? 1 : 0,
                !$term && Core\can_start_empty((string) get_post_type($id), $code) ? 1 : 0,
                flag($code),
                $star
            );
        }
    }

    return $out . '</span>';
}

/**
 * A post's title for a tooltip: plain text, or `#id` if it has none.
 */
function page_title(int $post_id): string
{
    return wp_strip_all_tags(html_entity_decode(get_the_title($post_id), ENT_QUOTES)) ?: '#' . $post_id;
}

/**
 * A language's flag, as an <img> (the code its alt), or its code in capitals
 * when it has none (nothing, with `$fallback` false). Escaped.
 */
function flag(string $code, bool $fallback = true): string
{
    $url = Core\flag_url($code);

    if ($url === null) {
        return $fallback ? '<span class="wgl-flag wgl-flag--code">' . esc_html(strtoupper($code)) . '</span>' : '';
    }

    return sprintf('<img class="wgl-flag" src="%s" alt="%s" width="20" height="15">', esc_url($url), esc_attr(strtoupper($code)));
}

/**
 * The star on the flag of a group's original: the page the others are
 * translated from.
 */
function original_star(): string
{
    return sprintf(
        '<span class="wgl-lang__original dashicons dashicons-star-filled" title="%1$s" aria-label="%1$s"></span>',
        esc_attr_x('Original', 'src/Admin/admin.php', 'wp-go-languages')
    );
}

/**
 * The lock on a fixed translation's flag; nothing for any other post.
 */
function fixed_lock(int $post_id, string $kind = 'post'): string
{
    if (Core\mode_of($post_id, $kind) !== 'blocks') {
        return '';
    }

    return sprintf(
        '<span class="wgl-lang__badge dashicons dashicons-lock" title="%1$s" aria-label="%1$s"></span>',
        esc_attr_x('Fixed layout', 'src/Admin/admin.php', 'wp-go-languages')
    );
}

/**
 * The lists' "+" dialog (list.js): an independent translation, a fixed translation, or a
 * page of the language already made, linked. On a translated type's list.
 */
function list_assets(string $hook): void
{
    $screen = get_current_screen();

    if ($hook !== 'edit.php' || !$screen || !Core\is_translated_post_type((string) $screen->post_type)) {
        return;
    }

    list_scripts(Core\has_block_editor((string) $screen->post_type) ? 'blocks' : 'fields');
}

/**
 * The "+" dialog's script, styles and texts: on a list of a translated type,
 * and CF7's.
 */
function list_scripts(string $as = 'blocks'): void
{
    wp_enqueue_style('wgl-list', Core\asset_url('Admin/list.css'), ['wgl-flags'], Core\asset_version('Admin/list.css'));
    wp_enqueue_script('wgl-list', Core\asset_url('Admin/list.js'), ['wp-api-fetch', 'wp-url'], Core\asset_version('Admin/list.js'), true);
    wp_add_inline_script('wgl-list', 'window.wglList = ' . wp_json_encode([
        'texts' => [
            /* translators: 1: language name, 2: page title */
            'title'        => _x('%1$s translation of "%2$s"', 'src/Admin/admin.php', 'wp-go-languages'),
            'independent'    => _x('Independent', 'src/Admin/admin.php', 'wp-go-languages'),
            'independentHelp' => _x('A page of its own: its blocks and every field are free to change.', 'src/Admin/admin.php', 'wp-go-languages'),
            'blocks'    => _x('Fixed layout', 'src/Admin/admin.php', 'wp-go-languages'),
            'blocksHelp' => _x('The original\'s blocks, in its order. Everything in them is its own, and any field can be linked to follow the original.', 'src/Admin/admin.php', 'wp-go-languages'),
            'linkFieldsOption' => _x('Link non-text fields to the original', 'src/Admin/admin.php', 'wp-go-languages'),
            'linkFieldsHelp'   => _x('Images, settings and other non-text fields start linked, and follow the original. Each can be unlinked in the editor.', 'src/Admin/admin.php', 'wp-go-languages'),
            'copyOption'   => _x('Start from the original\'s content', 'src/Admin/admin.php', 'wp-go-languages'),
            'copyHelp'     => _x('On: its fields start filled in, and internal links lead to this language\'s pages. Off: everything starts empty, and an independent translation is an empty page.', 'src/Admin/admin.php', 'wp-go-languages'),
            /* translators: %s: language name */
            'original'     => _x('Create it in %s', 'src/Admin/admin.php', 'wp-go-languages'),
            'originalHelp' => _x('A copy that becomes the original, as the default language\'s is unless one was chosen by hand: this one turns independent, and fixed translations follow the new one.', 'src/Admin/admin.php', 'wp-go-languages'),
            'originalKept' => _x('A copy, as an independent translation. This group\'s original was chosen by hand, and stays.', 'src/Admin/admin.php', 'wp-go-languages'),
            /* translators: %s: language name */
            'linkCard'     => _x('Link one in %s', 'src/Admin/admin.php', 'wp-go-languages'),
            'search'       => _x('Search by title…', 'src/Admin/admin.php', 'wp-go-languages'),
            'noCandidates' => _x('Nothing of this language to link: each is in a group with one in this language already.', 'src/Admin/admin.php', 'wp-go-languages'),
            'linkHelp'     => _x('One made already joins these translations, its content kept.', 'src/Admin/admin.php', 'wp-go-languages'),
            'draft'        => _x('draft', 'src/Admin/admin.php', 'wp-go-languages'),
            'cancel'       => _x('Cancel', 'src/Admin/admin.php', 'wp-go-languages'),
            'failed'       => _x('That didn\'t work. Reload the page and try again.', 'src/Admin/admin.php', 'wp-go-languages'),
            // "Translate" and "Translate…" (bulk.php)
            /* translators: %s: the item's title */
            'translateOne' => _x('Translate “%s”', 'src/Admin/bulk.php', 'wp-go-languages'),
            /* translators: %s: how many items */
            'translateMany' => _x('Translate %s items', 'src/Admin/bulk.php', 'wp-go-languages'),
            'into'         => _x('Into', 'src/Admin/bulk.php', 'wp-go-languages'),
            /* translators: 1: how many of the items lack the language, 2: how many items */
            'lacking'      => _x('%1$s of %2$s lack it', 'src/Admin/bulk.php', 'wp-go-languages'),
            'as'           => _x('As', 'src/Admin/bulk.php', 'wp-go-languages'),
            /* translators: %s: the default language's name */
            'intoDefault'  => _x('In %s, the default language, it\'s always a copy, which becomes the original unless one was chosen by hand.', 'src/Admin/bulk.php', 'wp-go-languages'),
            'noText'      => _x('Items of a type without blocks get an independent translation.', 'src/Admin/bulk.php', 'wp-go-languages'),
            'create'       => _x('Create translations', 'src/Admin/bulk.php', 'wp-go-languages'),
            'noLanguage'   => _x('Tick a language first.', 'src/Admin/bulk.php', 'wp-go-languages'),
            /* translators: 1: the item being translated, 2: how many */
            'creating'     => _x('Creating translations: %1$s of %2$s…', 'src/Admin/bulk.php', 'wp-go-languages'),
            /* translators: 1: translations created, 2: translations that failed */
            'done'         => _x('%1$s created, %2$s couldn\'t be:', 'src/Admin/bulk.php', 'wp-go-languages'),
            'close'        => _x('Close', 'src/Admin/bulk.php', 'wp-go-languages'),
            'nothingToTranslate' => _x('None of the items ticked lacks a language.', 'src/Admin/bulk.php', 'wp-go-languages'),
            // the Trash's question (trash.php)
            /* translators: %s: the item's title */
            'trashOne'       => _x('Move “%s” to the Trash', 'src/Admin/trash.php', 'wp-go-languages'),
            /* translators: %s: how many items */
            'trashMany'      => _x('Move %s items to the Trash', 'src/Admin/trash.php', 'wp-go-languages'),
            'trashOnly'      => _x('Only this one', 'src/Admin/trash.php', 'wp-go-languages'),
            'trashOnlyThese' => _x('Only these', 'src/Admin/trash.php', 'wp-go-languages'),
            'trashOnlyHelp'  => _x('Its translations stay where they are.', 'src/Admin/trash.php', 'wp-go-languages'),
            'trashAll'       => _x('With its translations', 'src/Admin/trash.php', 'wp-go-languages'),
            /* translators: 1: how many translations, 2: their languages */
            'trashAllHelp'   => _x('%1$s more to the Trash: %2$s. Undo brings them all back.', 'src/Admin/trash.php', 'wp-go-languages'),
        ] + ai_texts(),
        // the "+" dialog offers to translate its texts with AI
        'ai'    => Core\ai_enabled(),
    ]) . ';', 'before');

    // what its translations are in a form's words (CF7's list), or a type's
    // without blocks (a term's)
    $over = match ($as) {
        'form'   => cf7_mode_texts(),
        'fields' => fields_mode_texts(),
        default  => [],
    };

    if ($over) {
        wp_add_inline_script('wgl-list', 'Object.assign(window.wglList.texts, ' . wp_json_encode($over) . ');', 'before');
    }
}

/**
 * The modes' texts for a type without blocks, or a term, over the pages':
 * there's no layout to fix there.
 *
 * @return array<string,string>
 */
function fields_mode_texts(): array
{
    return [
        'independentHelp'    => _x('Its own: every field is free to change.', 'src/Admin/admin.php', 'wp-go-languages'),
        'independentShort'   => _x('Every field is its own.', 'src/Admin/admin.php', 'wp-go-languages'),
        'confirmIndependent' => _x('It stops following the original: its fields stay as they are and become its own to change. Nothing is deleted.', 'src/Admin/admin.php', 'wp-go-languages'),
    ];
}

/**
 * A form's translation modes, in a form's words, over the pages' (the "+"
 * dialog's, the Language panel's).
 *
 * @return array<string,string>
 */
function cf7_mode_texts(): array
{
    return [
        /* translators: %d: how many fields */
        'droppedCount'     => _x('%d field(s) will be deleted.', 'src/Admin/admin.php', 'wp-go-languages'),
        'blocks'          => _x('Fixed layout', 'src/Admin/admin.php', 'wp-go-languages'),
        'blocksToggle'    => _x('Fixed layout', 'src/Admin/admin.php', 'wp-go-languages'),
        'independentHelp'    => _x('A form of its own: its fields, mails and settings.', 'src/Admin/admin.php', 'wp-go-languages'),
        'blocksHelp'       => _x('The original\'s form, only its texts translated: labels, the mails\' subject and body, messages. It follows the original\'s fields and mail settings.', 'src/Admin/admin.php', 'wp-go-languages'),
        'independentShort'   => _x('Its fields and mails are its own to change.', 'src/Admin/admin.php', 'wp-go-languages'),
        'blocksShort'      => _x('The original\'s fields and mail settings; only the texts are translated.', 'src/Admin/admin.php', 'wp-go-languages'),
        'confirmBlocks'   => _x('Content can be deleted: its fields, conditions and mail settings become the original\'s, its texts kept row by row. Fields the original doesn\'t have are removed.', 'src/Admin/admin.php', 'wp-go-languages'),
        'confirmIndependent' => _x('It stops following the original: its fields and mails stay as they are and become its own to change. Nothing is deleted.', 'src/Admin/admin.php', 'wp-go-languages'),
    ];
}

/**
 * flags.css, wherever a flag shows: the admin, and the site with the admin
 * bar.
 */
function flag_styles(): void
{
    if (is_admin() || is_admin_bar_showing()) {
        wp_enqueue_style('wgl-flags', Core\asset_url('Admin/flags.css'), [], Core\asset_version('Admin/flags.css'));
    }
}

/**
 * `wgl` on every translated type's REST object: the post's language, its
 * translations and its mode, for the editor's panel. Writing `language`
 * moves a post into another language, while it has no translations.
 */
function rest_fields(): void
{
    foreach (Core\translated_post_types() as $type) {
        add_filter("rest_prepare_{$type}", 'WpGo\\Languages\\Core\\hide_rest_post', 10, 2);

        register_rest_field($type, 'wgl', [
            // the panel's, for one post being edited; a list asked in the edit context
            // (a dropdown of the site's pages) gets only what's its own, for each of 100
            'get_callback'    => fn (array $post, string $field, \WP_REST_Request $request) => isset($request->get_url_params()['id'])
                ? panel_data((int) $post['id'])
                : ['language' => Core\language_of((int) $post['id']), 'mode' => Core\mode_of((int) $post['id'])],
            'update_callback' => __NAMESPACE__ . '\\rest_update',
            'schema'          => [
                'type'       => 'object',
                'context'    => ['edit'],
                'properties' => [
                    'language' => ['type' => ['string', 'null']],
                    // null on an original: the panel sends its data back whole
                    'mode'     => ['type' => ['string', 'null']],
                ],
            ],
        ]);
    }
}

/**
 * The Language panel's links, under `wgl/v1/posts/<id>`:
 *
 *   GET  candidates?lang=en&search=…  the pages it may be linked to in a
 *                                     language, or in any other with no
 *                                     `lang` (link_candidates())
 *   POST link    { other }            join the two's translations
 *                                     (Core\connect())
 *   POST unlink  { target }           take `target` (one of its group, or
 *                                     itself) out (Core\leave_group())
 *   POST original { target }          make `target` (or itself) the group's
 *                                     original, in any language, and keep
 *                                     that choice (Core\choose_original())
 *
 * Each for who can edit the post, and the other post too. A link or unlink
 * answers the post's panel data, as the `wgl` field.
 */
function rest_routes(): void
{
    $id   = ['id' => ['type' => 'integer', 'required' => true]];
    $edit = fn (\WP_REST_Request $request) => current_user_can('edit_post', (int) $request['id']);

    register_rest_route('wgl/v1', '/posts/(?P<id>\d+)/candidates', [
        'methods'             => 'GET',
        'permission_callback' => $edit,
        'args'                => $id + [
            'lang'   => ['type' => 'string', 'default' => ''],
            'search' => ['type' => 'string', 'default' => ''],
        ],
        'callback'            => function (\WP_REST_Request $request) {
            if ((string) $request['lang'] !== '' && !Core\is_language((string) $request['lang'])) {
                return new \WP_Error('wgl_bad_language', 'Not one of the site\'s languages.', ['status' => 400]);
            }

            return link_candidates((int) $request['id'], (string) $request['lang'], (string) $request['search']);
        },
    ]);

    register_rest_route('wgl/v1', '/posts/(?P<id>\d+)/link', [
        'methods'             => 'POST',
        'permission_callback' => fn (\WP_REST_Request $request) => $edit($request) && current_user_can('edit_post', (int) $request['other']),
        'args'                => $id + ['other' => ['type' => 'integer', 'required' => true]],
        'callback'            => function (\WP_REST_Request $request) {
            $linked = Core\connect((int) $request['id'], (int) $request['other']);

            if (is_wp_error($linked)) {
                $linked->add_data(['status' => 400]);

                return $linked;
            }

            return panel_data((int) $request['id']);
        },
    ]);

    register_rest_route('wgl/v1', '/posts/(?P<id>\d+)/unlink', [
        'methods'             => 'POST',
        'permission_callback' => fn (\WP_REST_Request $request) => $edit($request) && current_user_can('edit_post', (int) ($request['target'] ?: $request['id'])),
        'args'                => $id + ['target' => ['type' => 'integer', 'default' => 0]],
        'callback'            => function (\WP_REST_Request $request) {
            $id     = (int) $request['id'];
            $target = (int) $request['target'] ?: $id;

            if (!in_array($target, Core\translations($id), true) || count(Core\translations($id)) < 2) {
                return new \WP_Error('wgl_not_in_group', 'Not one of this post\'s translations.', ['status' => 400]);
            }

            Core\leave_group($target);

            return panel_data($id);
        },
    ]);

    register_rest_route('wgl/v1', '/posts/(?P<id>\d+)/original', [
        'methods'             => 'POST',
        'permission_callback' => fn (\WP_REST_Request $request) => $edit($request) && current_user_can('edit_post', (int) ($request['target'] ?: $request['id'])),
        'args'                => $id + ['target' => ['type' => 'integer', 'default' => 0]],
        'callback'            => function (\WP_REST_Request $request) {
            $id     = (int) $request['id'];
            $target = (int) $request['target'] ?: $id;

            if (!Core\is_translation($target) || !in_array($target, Core\translations($id), true)) {
                return new \WP_Error('wgl_not_original', 'Only a translation in this post\'s group can become its original.', ['status' => 400]);
            }

            Core\choose_original($target);

            return panel_data($id);
        },
    ]);
}

/**
 * The panel's warnings about a post's links, in words.
 *
 * @return list<string>
 */
function link_warnings_text(int $post_id): array
{
    $name = (string) (Core\language((string) Core\language_of($post_id))['name'] ?? '');

    return array_map(fn (array $link) => sprintf(match ($link['kind']) {
        /* translators: 1: a page's title, 2: a language's name */
        'unpublished' => _x('A link to “%1$s” doesn\'t show: it isn\'t published in %2$s yet.', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: 1: a page's title, 2: a language's name */
        'missing'     => _x('A link leads to “%1$s” in another language: it has no page in %2$s.', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: 1: a page's title, 2: a language's name */
        default       => _x('A link in a text leads to “%1$s”, not to its page in %2$s.', 'src/Admin/admin.php', 'wp-go-languages'),
    }, $link['title'], $name), Core\link_warnings($post_id));
}

/**
 * The panel's own writes where there's no block editor to save them (CF7's
 * screen): a translation's mode, a post's language. As the `wgl` field's
 * (rest_update()), which these call.
 */
function rest_write_routes(): void
{
    $id   = ['id' => ['type' => 'integer', 'required' => true]];
    $edit = fn (\WP_REST_Request $request) => current_user_can('edit_post', (int) $request['id']);

    foreach (['mode', 'language'] as $key) {
        register_rest_route('wgl/v1', "/posts/(?P<id>\\d+)/$key", [
            'methods'             => 'POST',
            'permission_callback' => $edit,
            'args'                => $id + [$key => ['type' => 'string', 'required' => true]],
            'callback'            => function (\WP_REST_Request $request) use ($key) {
                $post    = get_post((int) $request['id']);
                $written = $post instanceof \WP_Post && Core\is_translated_post_type($post->post_type)
                    ? rest_update([$key => (string) $request[$key]], $post)
                    : new \WP_Error('wgl_not_translatable', 'Not a post of a translated type.', ['status' => 400]);

                return is_wp_error($written) ? $written : panel_data($post->ID);
            },
        ]);
    }
}

/**
 * The translation groups a post may join, in a language (every other, with
 * `$code` empty): one entry a group (a page alone is a group of one), the
 * pages of its type, any status but trash, matching `$search` (by title),
 * whose group has no page in a language of the post's (Core\connect()
 * refuses those). The group's original stands for it, with every language
 * it has. The 20 last changed.
 *
 * @return list<array{id:int,title:string,status:string,edit:string,language:string,language_name:string,flag:string,languages:list<array{code:string,name:string,flag:string}>}>
 */
function link_candidates(int $post_id, string $code, string $search = ''): array
{
    $post = get_post($post_id);
    $own  = Core\language_of($post_id);

    if (!$post instanceof \WP_Post || $code === $own) {
        return [];
    }

    $mine  = Core\translations($post_id);
    $query = new \WP_Query([
        'post_type'        => $post->post_type,
        'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
        'lang'             => $code !== '' ? $code : 'all',
        's'                => $search,
        'search_columns'   => ['post_title'],
        'orderby'          => 'modified',
        'order'            => 'DESC',
        'posts_per_page'   => 60,
        'post__not_in'     => [$post_id],
        'suppress_filters' => false,
        'no_found_rows'    => true,
    ]);

    $out  = [];
    $seen = [];

    Core\prime_translations(array_column($query->posts, 'ID'), true);

    foreach ($query->posts as $candidate) {
        $theirs   = Core\translations($candidate->ID);
        $original = Core\original($candidate->ID);

        if (isset($seen[$original]) || !$theirs || array_intersect_key($mine, $theirs) || !current_user_can('edit_post', $candidate->ID)) {
            continue;
        }

        $seen[$original] = true;
        $language        = (string) Core\language_of($original);
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
            'title'         => get_the_title($original) ?: '#' . $original,
            'status'        => (string) get_post_status($original),
            'edit'          => edit_url($original),
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

/**
 * @param mixed $value
 */
function rest_update($value, \WP_Post $post): bool|\WP_Error
{
    $mode = is_array($value) ? Core\normalize_mode((string) ($value['mode'] ?? '')) : '';

    // A translation's mode: into the fixed layout, its content rebuilt on the original's.
    if ($mode !== '' && Core\is_translation($post->ID) && $mode !== Core\mode_of($post->ID)) {
        if ($mode !== 'independent' && !Core\can_be_fixed($post->post_type)) {
            return new \WP_Error('wgl_no_structure', 'A fixed layout needs blocks: this type\'s translations are independent.', ['status' => 400]);
        }

        if (!Core\set_mode($post->ID, $mode)) {
            return new \WP_Error('wgl_bad_mode', 'Not a translation mode.', ['status' => 400]);
        }
    }

    // its featured image's link (featured-link.js): the panel sends its data back whole
    if (is_array($value) && isset($value['thumbnail']['linked']) && Core\is_translation($post->ID)) {
        Core\set_thumbnail_link($post->ID, (bool) $value['thumbnail']['linked'], Core\found_thumbnail($post->ID));
    }

    $code = is_array($value) ? (string) ($value['language'] ?? '') : '';

    if ($code === '' || $code === Core\language_of($post->ID)) {
        return true;
    }

    if (!Core\is_language($code)) {
        return new \WP_Error('wgl_bad_language', 'Not one of the site\'s languages.', ['status' => 400]);
    }

    if (count(Core\translations($post->ID)) > 1) {
        return new \WP_Error('wgl_has_translations', 'A page with translations keeps its language.', ['status' => 400]);
    }

    Core\set_language($post->ID, $code);
    relocated($post, $code);

    return true;
}

/**
 * A post just moved to another language: its parent becomes the parent's
 * translation there, if it has one, and its slug is checked again, as
 * another page of the language may have it. Saved again for both.
 */
function relocated(\WP_Post $post, string $code): void
{
    $parent = (int) get_post_field('post_parent', $post->ID);
    $moved  = $parent > 0 && Core\language_of($parent) !== $code ? Core\translation($parent, $code) : null;

    wp_update_post([
        'ID'          => $post->ID,
        'post_parent' => $moved ?? $parent,
    ]);

    // The REST answer is built from this object, read before the fields
    // were written: the editor would show the old parent and slug.
    $saved = get_post($post->ID);

    if ($saved) {
        $post->post_parent = $saved->post_parent;
        $post->post_name   = $saved->post_name;
    }
}

/**
 * What the Language panel shows of a post.
 *
 * @return array<string,mixed>
 */
function panel_data(int $post_id): array
{
    $own          = Core\language_of($post_id);
    $translations = Core\translations($post_id);
    $original     = Core\original($post_id);
    $fixable      = Core\can_be_fixed((string) get_post_type($post_id));

    $languages = [];

    foreach (Core\languages() as $code => $language) {
        $id = $translations[$code] ?? null;

        $languages[] = [
            'code'      => $code,
            'name'      => $language['name'],
            'flag'      => (string) Core\flag_url($code),
            'own'       => $code === $own,
            'id'        => $id,
            'title'     => $id ? get_the_title($id) : null,
            'status'    => $id ? get_post_status($id) : null,
            'mode'      => $id && $id !== $post_id ? Core\mode_of($id) : null,
            'edit'      => $id && $id !== $post_id ? edit_url($id) : null,
            'independent' => !$id && $own !== null ? create_url($post_id, $code, 'independent') : null,
            // the default language's is the original, a copy; never fixed
            'blocks' => !$id && $own !== null && !$language['default'] && $fixable ? create_url($post_id, $code, 'blocks') : null,
            'default'   => $language['default'],
            'can_original' => $id ? Core\is_translation($id) : false,
            'is_original'  => $id !== null && count($translations) > 1 && $id === $original,
        ];
    }

    $mode = Core\mode_of($post_id);
    $form = get_post_type($post_id) === Core\CF7_TYPE;

    return [
        'language'     => $own,
        'mode'         => $mode,
        'link_warnings' => link_warnings_text($post_id),
        // waiting for its original to be published (Core\held_back())
        'held'         => in_array($mode, Core\FIXED_MODES, true) && Core\held_back($post_id),
        // an independent translation's blocks (a form's fields) the original doesn't have:
        // lost if it turns fixed
        'dropped'      => $mode === 'independent' ? count($form ? Core\cf7_dropped_fields($post_id) : Core\blocks_dropped($post_id)) : null,
        'original'     => $original !== $post_id ? [
            'id'    => $original,
            'title' => get_the_title($original),
            'edit'  => edit_url($original),
        ] : null,
        'languages'    => $languages,
        'can_relocate' => count($translations) <= 1,
        'can_original' => Core\is_translation($post_id),
        // the fixed layout: a type with blocks, or a form, its own way
        'can_blocks' => $fixable,
        // what the featured image's link needs (featured-link.js)
        'thumbnail'  => Core\is_translation($post_id) && post_type_supports((string) get_post_type($post_id), 'thumbnail') && !$form ? [
            'linked'   => in_array(Core\THUMBNAIL_MARK, Core\synced_marks($post_id), true),
            'original' => (int) get_post_meta($original, Core\THUMBNAIL_MARK, true),
            'before'   => isset(Core\synced_memory($post_id)[Core\THUMBNAIL_MARK]) ? (int) (Core\synced_memory($post_id)[Core\THUMBNAIL_MARK][Core\THUMBNAIL_MARK] ?? 0) : null,
        ] : null,
    ];
}

/**
 * The editor's Language panel, on a translated type.
 */
function editor_panel(): void
{
    $screen = get_current_screen();

    if (!$screen || !Core\is_translated_post_type((string) $screen->post_type)) {
        return;
    }

    wp_enqueue_script(
        'wgl-editor-panel',
        Core\asset_url('Admin/editor-panel.js'),
        ['wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-core-data', 'wp-api-fetch', 'wp-url', 'wp-blocks'],
        Core\asset_version('Admin/editor-panel.js'),
        true
    );

    wp_add_inline_script('wgl-editor-panel', 'window.wglEditor = ' . wp_json_encode(['texts' => editor_texts(), 'ai' => Core\ai_enabled()]) . ';', 'before');
}

/**
 * The Language panel's texts, translated here so the script needs no
 * translation files.
 *
 * @return array<string,string>
 */
function editor_texts(): array
{
    return [
        'panel'      => _x('Language', 'src/Admin/admin.php', 'wp-go-languages'),
        'language'   => _x('Language', 'src/Admin/admin.php', 'wp-go-languages'),
        'translations' => _x('Translations', 'src/Admin/admin.php', 'wp-go-languages'),
        'translationOf' => _x('Translation of', 'src/Admin/admin.php', 'wp-go-languages'),
        'independentTitle' => _x('Independent', 'src/Admin/admin.php', 'wp-go-languages'),
        'independentShort' => _x('Its blocks and fields are its own.', 'src/Admin/admin.php', 'wp-go-languages'),
        'blocksToggle' => _x('Fixed layout', 'src/Admin/admin.php', 'wp-go-languages'),
        'blocksShort' => _x('The original\'s blocks, in its order. Everything in them is its own, and any field can be linked.', 'src/Admin/admin.php', 'wp-go-languages'),
        'confirmBlocks' => _x('Content can be deleted: its blocks become the original\'s, and blocks the original doesn\'t have are removed with what\'s in them. The fields of the blocks that stay are kept.', 'src/Admin/admin.php', 'wp-go-languages'),
        'confirmIndependent' => _x('It stops following the original: its blocks and fields stay as they are and become its own to change. Nothing is deleted.', 'src/Admin/admin.php', 'wp-go-languages'),
        'heldBack' => _x('The original isn\'t published: its changes reach this page when it is.', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: %d: how many blocks */
        'droppedCount' => _x('%d block(s) will be deleted.', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: %s: the mode's name */
        'modeTitle' => _x('Switch to “%s”?', 'src/Admin/admin.php', 'wp-go-languages'),
        'modeSwitch' => _x('Switch', 'src/Admin/admin.php', 'wp-go-languages'),
        'modeCancel' => _x('Cancel', 'src/Admin/admin.php', 'wp-go-languages'),
        'draft'      => _x('draft', 'src/Admin/admin.php', 'wp-go-languages'),
        'link'       => _x('Link to a translation…', 'src/Admin/admin.php', 'wp-go-languages'),
        'linkLabel'  => _x('Search the translations by title', 'src/Admin/admin.php', 'wp-go-languages'),
        'linkHelp'   => _x('This joins those translations, its content kept; the default language\'s is the original.', 'src/Admin/admin.php', 'wp-go-languages'),
        'noCandidates' => _x('Nothing to join: every group has one in this language already.', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: 1: page title, 2: language name */
        'confirmLink' => _x('Join the translations of "%1$s" (%2$s)? This keeps its content; the default language\'s is the original.', 'src/Admin/admin.php', 'wp-go-languages'),
        'noPage'     => _x('None yet: made from the "+" in the list, or linked.', 'src/Admin/admin.php', 'wp-go-languages'),
        'originalTag' => _x('Original', 'src/Admin/admin.php', 'wp-go-languages'),
        'defaultTag' => _x('(default)', 'src/Admin/admin.php', 'wp-go-languages'),
        'leave'      => _x('Unlink from its translations', 'src/Admin/admin.php', 'wp-go-languages'),
        'confirmLeave' => _x('Take this out of its translations? It keeps its content and language, on its own; if it is the original, another one becomes it.', 'src/Admin/admin.php', 'wp-go-languages'),
        'makeThisOriginal' => _x('Make this the original', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: %s: page title */
        'confirmOriginal' => _x('Make "%s" the original of these translations? The current original becomes an independent translation, its content kept; fixed translations follow the new original from its next save.', 'src/Admin/admin.php', 'wp-go-languages'),
        'saveFirst'  => _x('Save the page first: the editor reloads after.', 'src/Admin/admin.php', 'wp-go-languages'),
        'failed'     => _x('That didn\'t work. Reload the page and try again.', 'src/Admin/admin.php', 'wp-go-languages'),
        'relocate'   => _x('Saved, the page moves to this language: its address with it. Its slug gets a number if a page of the language has it; its parent becomes the parent\'s translation, and stays as it is if there is none.', 'src/Admin/admin.php', 'wp-go-languages'),
    ] + ai_texts();
}

/**
 * AI translation's texts, for the Language panel's dialog and the "+"
 * dialog (src/Admin/ai.php does the work).
 *
 * @return array<string,string>
 */
function ai_texts(): array
{
    return [
        'aiButton'   => _x('Translate with AI…', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: 1: the original's language, 2: this translation's */
        'aiTitle'    => _x('Translate with AI, from %1$s to %2$s', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiLoading'  => _x('Reading its fields…', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiPage'     => _x('Page', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiBlocks'   => _x('Blocks', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiFields'   => _x('Fields', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiSeo'      => _x('SEO', 'src/Admin/admin.php', 'wp-go-languages'),
        // a contact form's (CF7's)
        'aiMail'     => _x('Mail', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiMessages' => _x('Messages', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiPick'     => _x('Tick:', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiPickUntranslated' => _x('Untranslated', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiPickAll'  => _x('All', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiPickNone' => _x('None', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiOne'      => _x('Translate 1 field', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: %s: how many fields */
        'aiMany'     => _x('Translate %s fields', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiNote'     => _x('Each field is translated from the original and put in, not saved: check them, then save.', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiNothing'  => _x('Nothing to translate: no text field here.', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: 1: the chunk being translated, 2: how many */
        'aiRunning'  => _x('Translating with AI: %1$s of %2$s…', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: 1: fields translated, 2: fields that failed */
        'aiDone'     => _x('%1$s translated, %2$s couldn\'t be:', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiCancel'   => _x('Cancel', 'src/Admin/admin.php', 'wp-go-languages'),
        // Stop: after the part the model is on, what's done kept
        'aiStop'     => _x('Stop', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiStopping' => _x('Stopping after this part…', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: %s: fields translated */
        'aiStopped'  => _x('Stopped: %s translated, the rest left as it was.', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiClose'    => _x('Close', 'src/Admin/admin.php', 'wp-go-languages'),
        // the editor's dialog: put in, not saved
        /* translators: %s: fields translated */
        'aiAllIn'    => _x('%s translated, not saved yet: check them, then save.', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: 1: fields translated, 2: fields that failed */
        'aiDoneIn'   => _x('%1$s translated, not saved yet; %2$s couldn\'t be:', 'src/Admin/admin.php', 'wp-go-languages'),
        /* translators: %s: fields translated */
        'aiStoppedIn' => _x('Stopped: %s translated, not saved yet. Check them, then save.', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiNotHere'  => _x('Not found on this screen: left as it was.', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiOption'   => _x('Translate its texts with AI', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiOptionHelp' => _x('From the original, field by field: it opens translated, as a draft to check.', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiCreating' => _x('Creating the translation…', 'src/Admin/admin.php', 'wp-go-languages'),
        'aiOpen'     => _x('Open it', 'src/Admin/admin.php', 'wp-go-languages'),
        // the "+" dialog's button: makes what's picked
        'createOne'  => _x('Create', 'src/Admin/admin.php', 'wp-go-languages'),
    ];
}

/**
 * The form CF7's edit screen shows (`admin.php?page=wpcf7&post=<id>&action=edit`),
 * or 0 on its list, or on any other screen.
 */
function cf7_edited_form(): int
{
    $screen = get_current_screen();

    if (!$screen || $screen->id !== Core\cf7_screen_id() || ($_GET['action'] ?? '') !== 'edit') {
        return 0;
    }

    $id = (int) ($_GET['post'] ?? 0);

    return get_post_type($id) === Core\CF7_TYPE ? $id : 0;
}

/**
 * CF7's screens, its forms translated: on a form's, the Language panel as a
 * box of CF7's sidebar (editor-panel.js, mounted in `#wgl-cf7-panel`, its
 * data printed with it); on the list, the Languages column's cells, which
 * CF7's list table can't take (list.js puts them in).
 */
function cf7_assets(string $hook): void
{
    if ($hook !== Core\cf7_screen_id() || !Core\is_translated_post_type(Core\CF7_TYPE)) {
        return;
    }

    $form = cf7_edited_form();

    if ($form) {
        panel_script($form);

        return;
    }

    list_scripts('form');

    $cells = [];

    foreach (get_posts(['post_type' => Core\CF7_TYPE, 'posts_per_page' => -1, 'post_status' => 'any', 'fields' => 'ids', 'lang' => 'all']) as $id) {
        $cells[(int) $id] = languages_cell((int) $id);
    }

    // before the script, after its texts (list_scripts())
    wp_add_inline_script('wgl-list', 'window.wglList.cells = ' . wp_json_encode($cells, JSON_FORCE_OBJECT) . ';', 'before');
}

/**
 * The Language panel outside the block editor (CF7's screen, a classic edit
 * screen): editor-panel.js mounts it in `#wgl-panel-root`, from the post's
 * data printed here, and writes through wgl/v1.
 */
function panel_script(int $post_id, string $kind = 'post'): void
{
    wp_enqueue_style('wgl-flags');
    wp_enqueue_script(
        'wgl-editor-panel',
        Core\asset_url('Admin/editor-panel.js'),
        ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-url'],
        Core\asset_version('Admin/editor-panel.js'),
        true
    );
    $type  = $kind === 'term' ? '' : (string) get_post_type($post_id);
    $texts = match (true) {
        $type === Core\CF7_TYPE => cf7_mode_texts() + editor_texts(),
        $kind === 'term' || !Core\has_block_editor($type) => fields_mode_texts() + editor_texts(),
        default => editor_texts(),
    };

    wp_add_inline_script('wgl-editor-panel', 'window.wglEditor = ' . wp_json_encode([
        'texts' => $texts,
        'ai'    => Core\ai_enabled(),
        'post'  => [
            'id'   => $post_id,
            'kind' => $kind === 'term' ? 'terms' : 'posts',
            'data' => $kind === 'term' ? term_panel_data($post_id) : panel_data($post_id),
        ],
    ]) . ';', 'before');
}

/**
 * A translated type edited without the block editor (no `editor` support: a
 * type of data referenced from pages, say): the edit screen's own box.
 */
function uses_classic_screen(\WP_Post $post): bool
{
    if (!Core\is_translated_post_type($post->post_type) || $post->post_type === Core\CF7_TYPE) {
        return false;
    }

    require_once ABSPATH . 'wp-admin/includes/post.php';

    return !use_block_editor_for_post($post);
}

/**
 * The Language box, in the sidebar of a classic edit screen.
 */
function classic_box(string $post_type, $post): void
{
    if (!$post instanceof \WP_Post || !uses_classic_screen($post)) {
        return;
    }

    add_meta_box('wgl-language', _x('Language', 'src/Admin/admin.php', 'wp-go-languages'), fn () => print '<div id="wgl-panel-root"></div>', $post_type, 'side', 'high');
}

/**
 * The Language box's script, on a classic edit screen.
 */
function classic_assets(string $hook): void
{
    $post = $GLOBALS['post'] ?? null;

    if (in_array($hook, ['post.php', 'post-new.php'], true) && $post instanceof \WP_Post && uses_classic_screen($post)) {
        panel_script($post->ID);
    }
}

/**
 * The Language box on CF7's edit screen, after its form: the script moves it
 * into the sidebar, under Status.
 */
function cf7_panel_box(): void
{
    if (!cf7_edited_form()) {
        return;
    }

    printf(
        '<div id="wgl-cf7-box" class="postbox" hidden><div class="postbox-header"><h2 class="hndle">%s</h2></div><div class="inside"><div id="wgl-panel-root"></div></div></div>',
        esc_html_x('Language', 'src/Admin/admin.php', 'wp-go-languages')
    );
}

/**
 * A fixed form's screen (Core/cf7.php): its Additional settings tab gone,
 * as they're the original's; a note over its Mail tab, whose settings are
 * hidden (fixed-editor.css, by cf7_body_class()), its subject and body shown.
 *
 * @param array<string,array<string,mixed>> $panels
 * @return array<string,array<string,mixed>>
 */
function cf7_fixed_panels(array $panels): array
{
    $form = cf7_edited_form();

    if (!$form || !Core\is_fixed($form)) {
        return $panels;
    }

    unset($panels['additional-settings-panel']);

    if (isset($panels['mail-panel']['callback']) && is_callable($panels['mail-panel']['callback'])) {
        $callback = $panels['mail-panel']['callback'];

        $panels['mail-panel']['callback'] = function ($contact_form) use ($callback) {
            printf(
                '<p class="wgl-cf7-note">%s</p>',
                esc_html_x('A fixed translation: only the mails\' subject and body are its own. Their recipient, sender, headers, attachments and the other settings, and the additional settings, are the original form\'s, and are changed there.', 'src/Admin/admin.php', 'wp-go-languages')
            );

            $callback($contact_form);
        };
    }

    return $panels;
}

/**
 * A fixed form's screen gets `wgl-cf7-fixed`: what its styles hide.
 */
function cf7_body_class(string $classes): string
{
    $form = cf7_edited_form();

    return $form && Core\is_fixed($form) ? $classes . ' wgl-cf7-fixed' : $classes;
}

/**
 * The note over a translation's builder (cf7-acf-form): a fixed one shows
 * only its texts; an independent translation's fields are its own, so none.
 */
function cf7_builder_note(string $note, int $post_id): string
{
    if (!Core\is_translation($post_id)) {
        return $note;
    }

    return Core\is_fixed($post_id)
        ? _x('A fixed translation: only its texts are here. Its fields, names, limits and conditions are the original form\'s, and are changed there.', 'src/Admin/admin.php', 'wp-go-languages')
        : '';
}
