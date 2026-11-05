<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;

/**
 * The box beside a translation's field that makes it follow the original
 * (Core/synced.php), and what's posted of it.
 *
 * It shows as a link with the original's flag on its corner: faded, in full when ticked, and
 * its title says what it does. The checkbox itself is there, out of sight.
 *
 * It's drawn after the field's input, and a script moves it beside the title
 * (Admin/_synced.js): ACF's label is escaped, so it can't be put in from here.
 *
 * A box has no name: ACF's `field.val()` writes to the first named input in a
 * field, which would be the box, in the label. In a page's form the script keeps
 * hidden inputs for them, in the form, out of any field (`wgl_synced[]`, and each
 * one shown in `wgl_synced_shown[]`, as an unticked box posts nothing), so WP-Go's
 * list of changes, which reads a field's inputs, shows the values they change. A
 * block's form isn't posted: the script writes its marks in the block's `metadata`.
 */

function synced_fields_boot(): void
{
    add_action('acf/render_field', __NAMESPACE__ . '\\enter_field', PHP_INT_MIN);
    add_action('acf/render_field', __NAMESPACE__ . '\\render_sync_boxes', 20);
    add_action('acf/save_post', __NAMESPACE__ . '\\save_synced_marks', 5);
    add_action('acf/save_post', 'WpGo\\Languages\\Core\\restore_fields', 15);
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\synced_fields_assets');
    add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\synced_fields_assets');
    add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\featured_link_assets');
}

/**
 * The link on the editor's featured image (featured-link.js), on a translation.
 */
function featured_link_assets(): void
{
    $id = Core\edited_post_id();

    if ($id <= 0 || !Core\is_translation($id) || !post_type_supports((string) get_post_type($id), 'thumbnail')) {
        return;
    }

    $source = (string) Core\language_of(Core\original($id));

    wp_enqueue_script('wgl-featured-link', Core\asset_url('Admin/featured-link.js'), ['wp-data', 'wp-editor', 'wp-element', 'wp-hooks'], Core\asset_version('Admin/featured-link.js'), true);
    wp_add_inline_script('wgl-featured-link', 'window.wglFeaturedLink = ' . wp_json_encode([
        'flag'  => (string) Core\flag_url($source),
        /* translators: %s: the original's language */
        'title' => sprintf(_x('Sync the featured image with the original (%s)', 'src/Admin/synced-fields.php', 'wp-go-languages'), (string) (Core\language($source)['name'] ?? $source)),
    ]) . ';', 'before');
}

/**
 * The names of the fields being drawn: one entered, or, with none, the last left,
 * saying whether it's being drawn inside itself. A post object, a page link, a user
 * and a taxonomy field draw themselves as a select of the same name, through this
 * same hook. A group's fields have names of their own.
 *
 * @param array<string,mixed> $field
 */
function enter_field(array $field): void
{
    fields_being_drawn((string) ($field['name'] ?? ''));
}

function fields_being_drawn(?string $enter = null): bool
{
    static $names = [];

    if ($enter !== null) {
        $names[] = $enter;

        return false;
    }

    return in_array(array_pop($names), $names, true);
}

/**
 * @param array<string,mixed> $field
 */
function render_sync_boxes(array $field): void
{
    // the select inside a picker: the picker's own box comes as it ends
    if (fields_being_drawn()) {
        return;
    }

    $boxes = Core\sync_boxes($field);

    if ($boxes === null) {
        return;
    }

    $name = (string) (Core\language($boxes['source'])['name'] ?? $boxes['source']);

    $titles = [
        /* translators: %s: the original's language */
        'whole' => sprintf(_x('Sync with the original (%s)', 'src/Admin/synced-fields.php', 'wp-go-languages'), $name),
        /* translators: %s: the original's language */
        'rows'  => sprintf(_x('Sync every row with the original (%s), its texts too, and rows added later', 'src/Admin/synced-fields.php', 'wp-go-languages'), $name),
        /* translators: %s: the original's language */
        'page'  => sprintf(_x('Sync the page with the original (%s): the link goes to its translation', 'src/Admin/synced-fields.php', 'wp-go-languages'), $name),
        /* translators: %s: the original's language */
        'label' => sprintf(_x('Sync the label with the original (%s)', 'src/Admin/synced-fields.php', 'wp-go-languages'), $name),
        /* translators: %s: the original's language */
        'group' => sprintf(_x('Sync the whole group with the original (%s), whatever its fields say', 'src/Admin/synced-fields.php', 'wp-go-languages'), $name),
    ];

    // a link has two: what tells them apart, beside the flag
    $glyphs = ['page' => 'dashicons-admin-page', 'label' => 'dashicons-editor-textcolor'];

    printf(
        '<span class="wgl-sync" data-type="%s" data-block="%d" data-live="%d" data-value="%s" data-label="%s">',
        esc_attr($boxes['type']),
        $boxes['block'] ? 1 : 0,
        $boxes['live'] ? 1 : 0,
        esc_attr((string) wp_json_encode($boxes['value'])),
        esc_attr_x('Label', 'src/Admin/synced-fields.php', 'wp-go-languages')
    );

    foreach ($boxes['parts'] as $part) {
        $title = $titles[in_array($boxes['type'], Core\ROW_TYPES, true) ? 'rows' : $part['part']];

        // what a tick can't show at once (an image, a select, a repeater)
        if (!$boxes['live']) {
            $title .= '. ' . ($boxes['block']
                ? _x('The preview shows it at once, this form when saved', 'src/Admin/synced-fields.php', 'wp-go-languages')
                : _x('Updates when saved', 'src/Admin/synced-fields.php', 'wp-go-languages'));
        }

        printf(
            '<label class="wgl-sync__part" title="%1$s"><input type="checkbox" class="wgl-sync__box" aria-label="%1$s" data-mark="%2$s" data-part="%3$s"%4$s%7$s><span class="wgl-sync__icon"><span class="wgl-sync__link dashicons dashicons-admin-links" aria-hidden="true"></span>%5$s</span>%6$s</label>',
            esc_attr($title),
            esc_attr($part['mark']),
            esc_attr($part['part']),
            checked($part['on'], true, false),
            flag($boxes['source']),
            isset($glyphs[$part['part']]) ? '<span class="wgl-sync__what dashicons ' . esc_attr($glyphs[$part['part']]) . '" aria-hidden="true"></span>' : '',
            array_key_exists('before', $part) ? ' data-before="' . esc_attr((string) wp_json_encode($part['before'])) . '"' : ''
        );
    }

    echo '</span>';
}

/**
 * A page's, a term's or the options' marks, as their form posted them: those it
 * showed are as ticked; any other (one that doesn't apply in this mode) stays.
 * Before ACF writes the values (`acf/save_post`, 10), and what follows pulls over
 * them. ACF has checked its nonce by now.
 *
 * @param int|string $post_id
 */
function save_synced_marks($post_id): void
{
    if (!isset($_POST['wgl_synced_shown']) || !is_array($_POST['wgl_synced_shown'])) {
        return;
    }

    if (is_numeric($post_id)) {
        $id   = (int) $post_id;
        $kind = 'post';

        if (wp_is_post_revision($id) || !Core\is_translation($id)) {
            return;
        }
    } elseif (preg_match('/^term_(\d+)$/', (string) $post_id, $match)) {
        $id   = (int) $match[1];
        $kind = 'term';

        if (!Core\is_translation($id, 'term')) {
            return;
        }
    } elseif (preg_match('/^options?_([a-z0-9_-]+)$/', (string) $post_id, $match) && Core\is_language($match[1]) && $match[1] !== Core\default_language()) {
        $id   = $match[1];
        $kind = 'option';
    } else {
        return;
    }

    $clean = fn ($list) => array_values(array_filter(array_map(
        fn ($mark) => is_string($mark) ? wp_unslash($mark) : '',
        (array) $list
    ), fn ($mark) => (bool) preg_match('/^[\w-]+(#title|#group:\w+)?$/', $mark)));

    $shown  = $clean($_POST['wgl_synced_shown']);
    $ticked = array_intersect($clean($_POST['wgl_synced'] ?? []), $shown);
    $before = Core\synced_marks($id, $kind);
    $marks  = array_merge(array_diff($before, $shown), $ticked);

    Core\remember_fields($id, $kind, $before, $marks);
    Core\set_synced_marks($id, $marks, $kind);
}

function synced_fields_assets(): void
{
    if (!Core\sync_boxes_here()) {
        return;
    }

    wp_enqueue_style('wgl-synced-fields', Core\asset_url('Admin/synced-fields.css'), [], Core\asset_version('Admin/synced-fields.css'));
    wp_enqueue_script('wgl-synced-fields', Core\asset_url('Admin/synced-fields.js'), ['acf-input', 'jquery'], Core\asset_version('Admin/synced-fields.js'), true);
}
