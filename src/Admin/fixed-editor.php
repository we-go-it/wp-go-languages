<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;

/**
 * The editor of a fixed translation (Core/fixed.php): the original's blocks,
 * their texts to translate, nothing else to change.
 *
 *   - the block list is locked (`templateLock: 'all'`): no block added,
 *     moved or removed (WP-Go's editor shell honours it too);
 *   - in a block's fields, those that aren't text to translate are hidden,
 *     still in the form (the merge overrides them anyway);
 *   - a repeater keeps its rows: no row added, removed or moved.
 *
 * The page's own fields (beside the content) follow their policy as on any
 * translation (Core/policies.php). The stylesheet serves those locks too, on
 * any screen that shows them.
 */

function fixed_editor_boot(): void
{
    add_filter('block_editor_settings_all', __NAMESPACE__ . '\\lock_fixed_editor', 20, 2);
    add_filter('acf/prepare_field', __NAMESPACE__ . '\\prepare_fixed_field', 30);
    add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\fixed_editor_styles');
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\copied_fields_styles');
    add_action('admin_notices', __NAMESPACE__ . '\\copied_options_notice');
}

/**
 * The editor's block list locked, for a fixed translation.
 *
 * @param array<string,mixed> $settings
 * @return array<string,mixed>
 */
function lock_fixed_editor(array $settings, \WP_Block_Editor_Context $context): array
{
    if ($context->post instanceof \WP_Post && Core\is_fixed($context->post->ID)) {
        $settings['templateLock'] = 'all';
    }

    return $settings;
}

/**
 * A block's field, in a fixed translation: hidden unless it is text to
 * translate (all shown when its fields are free, `structure`); a repeater,
 * its rows as they are.
 *
 * @param array<string,mixed>|false $field
 * @return array<string,mixed>|false
 */
function prepare_fixed_field($field)
{
    if (!is_array($field) || !str_starts_with((string) ($field['prefix'] ?? ''), 'acf-block') || !Core\is_fixed(Core\edited_post_id())) {
        return $field;
    }

    $type  = (string) ($field['type'] ?? '');
    $class = (string) ($field['wrapper']['class'] ?? '');

    if ($type === 'repeater' || $type === 'flexible_content') {
        $rows         = is_array($field['value'] ?? null) ? count($field['value']) : (int) ($field['value'] ?? 0);
        $field['min'] = $rows;
        $field['max'] = $rows;
        $class       .= ' wgl-fixed-rows';
    }

    // its fields all its own (`structure`): nothing hidden; else what has
    // nothing to translate: a field, or a clone, group, repeater, tab or
    // accordion with nothing left in it to show
    if (!Core\fields_free(Core\edited_post_id()) && !fixed_field_shows($field)) {
        $class .= ' wgl-locked';

        // a tab's own class is its button's, which ACF moves into its bar
        if ($type === 'tab') {
            $field['class'] = trim(($field['class'] ?? '') . ' wgl-locked');
        }
    }

    $field['wrapper']['class'] = trim($class);

    return $field;
}

/**
 * Whether a block's field shows in a fixed translation: a text translated
 * here or a field that starts empty (block_field_policy(): a clone's fields
 * by its switch, as ACF hands them with `_clone`); a clone, group, repeater
 * or flexible content holding one; a tab or an accordion with one after it,
 * before the next of its kind. A message shows.
 *
 * @param array<string,mixed> $field
 */
function fixed_field_shows(array $field): bool
{
    $type = (string) ($field['type'] ?? '');

    if ($type === 'message') {
        return true;
    }

    if ($type === 'tab' || $type === 'accordion') {
        return fixed_section_shows($field);
    }

    if (in_array($type, Core\CONTAINER_TYPES, true)) {
        $subs = (array) ($field['sub_fields'] ?? []);

        foreach ((array) ($field['layouts'] ?? []) as $layout) {
            $subs = array_merge($subs, (array) ($layout['sub_fields'] ?? []));
        }

        foreach ($subs as $sub) {
            if (is_array($sub) && !in_array($sub['type'] ?? '', ['tab', 'accordion', 'message'], true) && fixed_field_shows($sub)) {
                return true;
            }
        }

        return false;
    }

    return in_array(Core\block_field_policy($field), ['translate', 'ignore'], true);
}

/**
 * Whether a tab or an accordion has a field to show after it, before the
 * next of its kind: among its siblings, a layout's, a repeater's or a
 * clone's, or its field group's.
 *
 * @param array<string,mixed> $section
 */
function fixed_section_shows(array $section): bool
{
    $parent = function_exists('acf_get_field') && !empty($section['parent']) ? acf_get_field($section['parent']) : null;
    $subs   = [];

    if (is_array($parent)) {
        foreach ((array) ($parent['layouts'] ?? []) as $layout) {
            if (($layout['key'] ?? '') === ($section['parent_layout'] ?? null)) {
                $subs = (array) $layout['sub_fields'];
            }
        }

        $subs = $subs ?: (array) ($parent['sub_fields'] ?? []);
    } elseif (!empty($section['parent']) && function_exists('acf_get_fields')) {
        $subs = (array) acf_get_fields($section['parent']);
    }

    $after = false;

    foreach ($subs as $sub) {
        $key = (string) ($sub['__key'] ?? $sub['key'] ?? '');

        if ($key === ($section['__key'] ?? $section['key'] ?? null)) {
            $after = true;

            continue;
        }

        if (!$after) {
            continue;
        }

        if (($sub['type'] ?? '') === $section['type']) {
            break;
        }

        if (fixed_field_shows($sub)) {
            return true;
        }
    }

    // not found where it should be: shown, as it was
    return !$after;
}

/**
 * The styles that hide what a fixed translation doesn't change.
 */
function fixed_editor_styles(): void
{
    $post = get_post();

    if (!$post instanceof \WP_Post || !Core\is_fixed($post->ID)) {
        return;
    }

    wp_enqueue_style('wgl-fixed-editor', Core\asset_url('Admin/fixed-editor.css'), [], Core\asset_version('Admin/fixed-editor.css'));
    hidden_tabs_script();
}

/**
 * A tab hidden (its button's class, `wgl-copied` on a page's fields,
 * `wgl-locked` in a fixed translation's blocks) skipped as ACF's are, by
 * `acf-hidden` on its item: one open as a form is drawn (on the screen's
 * `ready`, a block's form on `append`) gives way to the next one shown (ACF
 * opens the first, or the one last open). Once a request.
 */
function hidden_tabs_script(): void
{
    static $added = false;

    if ($added) {
        return;
    }

    $added = true;

    wp_add_inline_script('acf-input', <<<'JS'
        (function () {
          if (!window.acf) return
          var hide = function () {
            acf.getInstances(jQuery('.acf-tab-wrap')).forEach(function (group) {
              (group.tabs || []).forEach(function (tab) {
                tab.$el.toggleClass('acf-hidden', tab.$el.find('.wgl-copied, .wgl-locked').length > 0)
              })
              if (group.hasActive() && !group.getActive().isVisible()) {
                group.closeActive()
                group.refresh()
              }
            })
          }
          acf.addAction('ready', hide)
          // after ACF's tabs, drawn on append at 50
          acf.addAction('append', hide, 60)
        })()
        JS);
}

/**
 * The same styles where a translation's copied fields are locked
 * (Core\lock_copied_field()): its edit screen, a form's, the options in
 * another language.
 */
function copied_fields_styles(): void
{
    if (Core\locks_copied_fields()) {
        wp_enqueue_style('wgl-fixed-editor', Core\asset_url('Admin/fixed-editor.css'), [], Core\asset_version('Admin/fixed-editor.css'));

        hidden_tabs_script();
    }
}

/**
 * An options page in another language than the stored one says why some of
 * its fields aren't there: copied, they're the same in every language
 * (Core\lock_copied_field()).
 */
function copied_options_notice(): void
{
    if (!Core\is_options_screen() || !Core\locks_copied_fields()) {
        return;
    }

    printf(
        '<div class="notice notice-info"><p>%s</p></div>',
        esc_html(sprintf(
            /* translators: %s: the default language's name */
            _x('Fields that are the same in every language aren\'t shown here: they\'re changed in the %s options.', 'src/Admin/fixed-editor.php', 'wp-go-languages'),
            (string) (Core\language(Core\base_language())['name'] ?? Core\base_language())
        ))
    );
}
