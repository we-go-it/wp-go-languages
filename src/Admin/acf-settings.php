<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;

/**
 * The "Translation" switch in an ACF field's settings (its General tab):
 * per language, the same in every language, or starts empty (the policies
 * translate, copy, ignore: policies.php), saved as the field's own
 * `wgl_translation` (Core\field_policy() reads it first). ACFML draws its
 * own for `wpml_cf_preferences`, but only with ACFML on: here the plugin
 * draws one, shows a field set for WPML alone with that value picked, and
 * on save writes WPML's number too, so the field means the same under WPML.
 */

/** Field types that hold no value: no switch. */
const ACF_NO_VALUE_TYPES = ['tab', 'message', 'accordion', 'endpoint'];

function acf_settings_boot(): void
{
    add_action('acf/render_field_general_settings', __NAMESPACE__ . '\\render_translation_setting');
    add_filter('acf/update_field', __NAMESPACE__ . '\\sync_wpml_preference');
}

/**
 * The switch, under the field's name.
 *
 * @param array<string,mixed> $field
 */
function render_translation_setting(array $field): void
{
    if (in_array($field['type'] ?? '', ACF_NO_VALUE_TYPES, true) || !function_exists('acf_render_field_setting')) {
        return;
    }

    // set for WPML only: shown as that, saved as ours from then on
    if (($field['wgl_translation'] ?? '') === '') {
        $field['wgl_translation'] = (string) Core\field_policy($field);
    }

    acf_render_field_setting($field, [
        'label'        => _x('Translation', 'src/Admin/acf-settings.php', 'wp-go-languages'),
        'instructions' => _x('Per language: each language has its own value; a new translation starts with the original\'s. Same in every language: kept equal to the original\'s, locked on translations. Starts empty: a new translation starts without it, then has its own. On the options page, a per-language field that isn\'t a text shows the default language\'s value until a language sets its own. None picked: per language, unless a rule elsewhere says otherwise.', 'src/Admin/acf-settings.php', 'wp-go-languages'),
        'name'         => 'wgl_translation',
        'type'         => 'button_group',
        'allow_null'   => 1,
        'choices'      => [
            'translate' => _x('Per language', 'src/Admin/acf-settings.php', 'wp-go-languages'),
            'copy'      => _x('Same in every language', 'src/Admin/acf-settings.php', 'wp-go-languages'),
            'ignore'    => _x('Starts empty', 'src/Admin/acf-settings.php', 'wp-go-languages'),
        ],
    ]);
}

/**
 * On save, WPML's number beside ours (or neither), so a theme that runs
 * under WPML too reads the same rule from its acf-json.
 *
 * @param array<string,mixed> $field
 * @return array<string,mixed>
 */
function sync_wpml_preference(array $field): array
{
    if (!array_key_exists('wgl_translation', $field)) {
        return $field;
    }

    $words   = (string) $field['wgl_translation'];
    $numbers = ['ignore' => 0, 'copy' => 1, 'translate' => 2];

    if (!isset($numbers[$words])) {
        unset($field['wgl_translation'], $field['wpml_cf_preferences']);

        return $field;
    }

    // per language: WPML's copy once (3) stays so, which under WPML still
    // copies an image once; else its translate (2)
    $field['wpml_cf_preferences'] = $words === 'translate' && (int) ($field['wpml_cf_preferences'] ?? -1) === 3 ? 3 : $numbers[$words];

    return $field;
}
