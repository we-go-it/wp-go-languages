<?php

namespace WpGo\Languages\Core;

/**
 * Contact Form 7, one form per language: what CF7 Multilingual did on WPML.
 *
 *   - A form's translation is a form of its own (wpcf7_contact_form, a
 *     translated type), made by create_translation(): its `_form`, `_mail`…
 *     copied once (the theme's wpml-config.xml), then its own.
 *   - It gets its language's `_locale`, so CF7 validates in it, and a `_hash`
 *     of its own: both are `ignore` (wpml-config.xml), and CF7 adds the hash
 *     only when a form is saved through it.
 *   - `[contact-form-7 id="…"]`, by hash or ID, shows the current language's
 *     published translation of the form, else the form itself:
 *     shortcode_atts_wpcf7, which CF7's own handler and WP-Go's built forms
 *     (cf7-acf-form) both run.
 *
 * A form's translation is a duplicate or fixed, as a page's:
 *
 *   - a duplicate is a form of its own from then on: its fields, its mails,
 *     everything, nothing synced nor locked (policies.php);
 *   - a fixed translation is the original's form with its texts translated:
 *     the builder's fields (cf7-acf-form) `copy` on each save of the
 *     original, as a page's copied fields, and hidden on its screen rather
 *     than locked, only the texts shown (labels, placeholders, help, the
 *     options' labels, the submit button's); its mails' settings (recipient,
 *     sender, headers, attachments, HTML, Mail (2) on or off), its MJML
 *     switch and its additional settings the original's too, on each save of
 *     either (cf7_follow_original()), their texts (subject, body or MJML)
 *     its own; its conditions' values follow its options' labels
 *     (cf7_follow_conditions()).
 */

const CF7_TYPE = 'wpcf7_contact_form';

/**
 * The screen ID of CF7's forms page (its list and a form's edit screen):
 * `toplevel_page_wpcf7` up to CF7 6.1; from 6.2 the page is a submenu of
 * CF7's Dashboard (`wpcf7-dashboard`), named after that menu's title, as
 * translated (`contact_page_wpcf7`). Before the admin menu is registered
 * (wp-cli, REST), the first.
 */
function cf7_screen_id(): string
{
    global $admin_page_hooks;

    if (isset($admin_page_hooks['wpcf7']) || !isset($admin_page_hooks['wpcf7-dashboard'])) {
        return 'toplevel_page_wpcf7';
    }

    return $admin_page_hooks['wpcf7-dashboard'] . '_page_wpcf7';
}

/** The builder's rows (cf7-acf-form): a flexible content field, groups of rows inside it. */
const CF7_ROWS = 'cf7_fields';

/** What of a mail is its text, a fixed translation's own: the rest is the original's. */
const CF7_MAIL_TEXTS = ['subject', 'body'];

/** WP-Go's MJML of the mails (cf7-mjml): `['mail' => ['enabled', 'source'], 'mail_2' => …]`. */
const CF7_MJML_META = '_wp_go_mail_mjml';

/**
 * A new translation of a form: its language's locale, and a hash.
 */
function cf7_translation_created(int $new_id, int $original_id, string $code): void
{
    if (get_post_type($new_id) !== CF7_TYPE) {
        return;
    }

    $locale = language($code)['locale'] ?? '';

    if ($locale !== '' && (!function_exists('wpcf7_is_valid_locale') || wpcf7_is_valid_locale($locale))) {
        update_post_meta($new_id, '_locale', $locale);
    }

    if (function_exists('wpcf7_generate_contact_form_hash')) {
        add_post_meta($new_id, '_hash', wpcf7_generate_contact_form_hash($new_id), true);
    }
}

/**
 * `[contact-form-7]`'s form, in the current language: its published
 * translation's hash (or ID) in place of the one given. A form with none
 * gets its numeric ID: CF7 finds a hash only in the current language, an ID
 * in any, so the form shows as it is.
 *
 * @param array<string,mixed> $out
 * @return array<string,mixed>
 */
function cf7_shortcode_atts(array $out): array
{
    if (!function_exists('wpcf7_contact_form') || !is_translated_post_type(CF7_TYPE)) {
        return $out;
    }

    $given = trim((string) ($out['id'] ?? ''));

    if ($given === '') {
        return $out;
    }

    $form_id = cf7_form_id($given);

    if (!$form_id) {
        return $out;
    }

    $id = translation($form_id, current());

    // A draft translation isn't ready to be shown: the form it translates is.
    $translated = $id && $id !== $form_id && get_post_status($id) === 'publish' ? wpcf7_contact_form($id) : null;

    if ($translated) {
        $out['id'] = method_exists($translated, 'hash') && $translated->hash() ? $translated->hash() : (string) $id;
    } else {
        $out['id'] = (string) $form_id;
    }

    // the title would find the original again, if the id didn't
    $out['title'] = '';

    return $out;
}

/**
 * A form's ID from what a shortcode gives, in any language: its hash (or the
 * hash's first 7+ characters, as CF7 writes it) or its ID.
 *
 * Not wpcf7_get_contact_form_by_hash(): it runs a WP_Query, which keeps to
 * the current language, and an English page would not find the Italian form
 * its content names.
 */
function cf7_form_id(string $given): int
{
    global $wpdb;

    if (preg_match('/^[0-9a-f]{7,}$/', $given)) {
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = '_hash' AND m.meta_value LIKE %s AND p.post_type = %s LIMIT 1",
            $wpdb->esc_like($given) . '%',
            CF7_TYPE
        ));

        if ($id) {
            return $id;
        }
    }

    return ctype_digit($given) && get_post_type((int) $given) === CF7_TYPE ? (int) $given : 0;
}

/**
 * A CF7 save of a form (`wpcf7_after_save`, after its mails are written):
 * an original's fixed translations follow it; a fixed translation follows
 * its original again, whatever its screen posted.
 */
function cf7_saved($form): void
{
    if (!is_object($form) || !method_exists($form, 'id')) {
        return;
    }

    $id = (int) $form->id();

    if ($id <= 0 || get_post_type($id) !== CF7_TYPE || !is_translated_post_type(CF7_TYPE)) {
        return;
    }

    if (is_translation($id)) {
        if (is_fixed($id)) {
            cf7_follow_original($id, original($id));
        }

        return;
    }

    foreach (translations($id) as $translation) {
        if ($translation !== $id && is_fixed($translation)) {
            cf7_follow_original($translation, $id);

            do_action('wp_go_languages/synced', $translation, $id);
        }
    }
}

/**
 * A fixed translation of a form takes its original's mail settings, MJML
 * switch and additional settings, its texts kept; then its conditions'
 * values follow its options (cf7_follow_conditions()). Its fields are the
 * original's by their policy (`copy`), synced on the original's save
 * (sync_translations()).
 */
function cf7_follow_original(int $id, int $original_id): void
{
    if ($id === $original_id || get_post_type($id) !== CF7_TYPE) {
        return;
    }

    $mjml_from = get_post_meta($original_id, CF7_MJML_META, true);
    $mjml      = get_post_meta($id, CF7_MJML_META, true);
    $mjml_from = is_array($mjml_from) ? $mjml_from : [];
    $mjml      = is_array($mjml) ? $mjml : [];
    $had_mjml  = $mjml;

    foreach (['_mail', '_mail_2'] as $key) {
        $from = get_post_meta($original_id, $key, true);

        if (!is_array($from)) {
            continue;
        }

        $mail   = get_post_meta($id, $key, true);
        $mail   = is_array($mail) ? $mail : [];
        $merged = array_merge($from, array_intersect_key($mail, array_flip(CF7_MAIL_TEXTS)));
        $name   = ltrim($key, '_');

        // MJML turned on in the original: the translation's, if it has
        // none, starts as the original's, its body with it (to translate)
        $on = !empty($mjml_from[$name]['enabled']);

        if ($on && trim((string) ($mjml[$name]['source'] ?? '')) === '') {
            $mjml[$name]    = (array) ($mjml_from[$name] ?? []);
            $merged['body'] = (string) ($from['body'] ?? '');
        }

        if ($mjml_from || $mjml) {
            $mjml[$name] = ['enabled' => $on, 'source' => (string) ($mjml[$name]['source'] ?? '')] + (array) ($mjml[$name] ?? []);
        }

        if ($merged !== $mail) {
            update_post_meta($id, $key, wp_slash($merged));
        }
    }

    if ($mjml && $mjml !== $had_mjml) {
        update_post_meta($id, CF7_MJML_META, wp_slash($mjml));
    }

    $settings = (string) get_post_meta($original_id, '_additional_settings', true);

    if ($settings !== (string) get_post_meta($id, '_additional_settings', true)) {
        update_post_meta($id, '_additional_settings', wp_slash($settings));
    }

    cf7_follow_conditions($id);
}

/**
 * A fixed translation takes its original's fields (`copy`), its texts kept
 * with them: a row's texts (`translate`: its label, placeholder, help, its
 * options' labels) are found by its field's name, unique in a form, not by
 * its place, so a field added, removed or moved in the original keeps every
 * other's translation. A new field gets the original's texts, to translate.
 * An option's label stays at its place in its field. Then the conditions
 * follow (cf7_follow_conditions()).
 */
function cf7_sync_rows(int $original_id, int $id): void
{
    $before = cf7_row_texts($id);

    copy_meta($original_id, $id, 'sync');

    foreach (cf7_row_texts($original_id) as $name => $row) {
        $kept = $before[$name]['texts'] ?? [];

        foreach ($row['texts'] as $suffix => $text) {
            $key   = $row['path'] . '_' . $suffix;
            $value = array_key_exists($suffix, $kept) ? $kept[$suffix] : $text;

            if ((string) get_post_meta($id, $key, true) !== $value) {
                update_post_meta($id, $key, wp_slash($value));
            }

            $reference = get_post_meta($original_id, '_' . $key, true);

            if ($reference !== '' && get_post_meta($id, '_' . $key, true) !== $reference) {
                update_post_meta($id, '_' . $key, $reference);
            }
        }
    }

    cf7_follow_conditions($id);
}

/**
 * A form's rows and their texts, by what names them: a field's name, a
 * group's first field's (`group:<name>`). Each its meta path
 * (`cf7_fields_3`, `cf7_fields_1_fields_0`) and its `translate` values by
 * the rest of their key (`label`, `options_2_label`); a group's rows aren't
 * its own.
 *
 * @return array<string,array{path: string, texts: array<string,string>}>
 */
function cf7_row_texts(int $form): array
{
    $meta  = array_map(fn ($values) => is_scalar($value = maybe_unserialize($values[0] ?? '')) ? (string) $value : null, (array) get_post_meta($form));
    $paths = [];

    foreach (array_keys($meta) as $key) {
        if (preg_match('/^(' . CF7_ROWS . '_\d+(?:_fields_\d+)?)_name$/', (string) $key, $match) && (string) $meta[$key] !== '') {
            $paths[$match[1]] = (string) $meta[$key];
        }
    }

    foreach ((array) maybe_unserialize(get_post_meta($form, CF7_ROWS, true)) as $i => $layout) {
        if ($layout === 'group') {
            $first = $paths[CF7_ROWS . "_{$i}_fields_0"] ?? '#' . $i;
            $paths[CF7_ROWS . "_{$i}"] = 'group:' . $first;
        }
    }

    $rows = [];

    foreach ($paths as $path => $name) {
        if (isset($rows[$name])) {
            continue;
        }

        $texts = [];

        foreach ($meta as $key => $value) {
            $key = (string) $key;

            if ($value === null || !str_starts_with($key, $path . '_')) {
                continue;
            }

            $suffix = substr($key, strlen($path) + 1);

            // a group's rows are rows of their own
            if (preg_match('/^fields_\d+_/', $suffix) || $suffix === 'name') {
                continue;
            }

            if (stored_policy($key, $form) === 'translate') {
                $texts[$suffix] = $value;
            }
        }

        $rows[$name] = ['path' => (string) $path, 'texts' => $texts];
    }

    return $rows;
}

/**
 * A fixed translation's conditions, their values: the original's, but for
 * a rule on an option written without a value of its own, whose value is
 * its label (cf7-acf-form's wp_go_cf7_option_map()), which the translation
 * translates: the rule then reads the translation's label of that option.
 * The rows are the original's, so a rule, its field and its options are at
 * the same places in both.
 */
function cf7_follow_conditions(int $id): void
{
    $original_id = original($id);

    if ($original_id === $id) {
        return;
    }

    // its meta, the strings only: rows' layouts are arrays
    $from = array_map(fn ($values) => is_scalar($value = maybe_unserialize($values[0] ?? '')) ? (string) $value : '', (array) get_post_meta($original_id));
    $rows = [];

    // the rows by their field's name: `cf7_fields_3`, `cf7_fields_1_fields_0`
    foreach ($from as $key => $value) {
        if (preg_match('/^(' . CF7_ROWS . '_\d+(?:_fields_\d+)?)_name$/', (string) $key, $match) && $value !== '') {
            $rows[$value] ??= $match[1];
        }
    }

    foreach ($from as $key => $value) {
        if (!preg_match('/^(' . CF7_ROWS . '_\d+(?:_fields_\d+)?_conditions_\d+)_value$/', (string) $key, $match)) {
            continue;
        }

        $value = trim($value);
        $row   = $rows[trim($from[$match[1] . '_field'] ?? '')] ?? null;
        $next  = $value;

        for ($i = 0; $row !== null && $i < (int) ($from[$row . '_options'] ?? 0); $i++) {
            $own   = trim($from["{$row}_options_{$i}_value"] ?? '');
            $label = trim($from["{$row}_options_{$i}_label"] ?? '');

            if ($own === '' && $label !== '' && $label === $value) {
                $translated = trim((string) get_post_meta($id, "{$row}_options_{$i}_label", true));
                $next       = $translated !== '' ? $translated : $value;

                break;
            }
        }

        if ((string) get_post_meta($id, (string) $key, true) !== $next) {
            update_post_meta($id, (string) $key, wp_slash($next));
        }
    }
}

/**
 * Whether an ACF field is a condition's value (cf7-acf-form's
 * form/lib/conditions.json): in a fixed translation it follows the options
 * (cf7_follow_conditions()), so it isn't shown (copied_field_shows()).
 *
 * @param array<string,mixed> $field
 */
function cf7_is_condition_value(array $field): bool
{
    return (bool) preg_match('/_conditions_value$/', (string) ($field['key'] ?? ''));
}

/**
 * How many of a fixed form's texts are still the original's: its fields'
 * (labels, placeholders… not empty, the same in both), its mails' subject
 * and body (the MJML for one written in it; Mail (2) while it's on), its
 * messages.
 */
function cf7_untranslated_count(int $id): int
{
    $original_id = original($id);

    if ($original_id === $id) {
        return 0;
    }

    $same  = fn ($a, $b) => is_string($a) && trim(wp_strip_all_tags($a)) !== '' && $a === $b;
    $count = 0;

    foreach ((array) get_post_meta($original_id) as $key => $values) {
        $key = (string) $key;

        if (str_starts_with($key, '_') || !$same((string) ($values[0] ?? ''), get_post_meta($id, $key, true))) {
            continue;
        }

        $field = acf_field_of($key, $original_id);

        if ($field && in_array($field['type'] ?? '', TEXT_TYPES, true) && field_policy($field) === 'translate' && !cf7_is_condition_value($field)) {
            $count++;
        }
    }

    $mjml_from = (array) get_post_meta($original_id, CF7_MJML_META, true);
    $mjml      = (array) get_post_meta($id, CF7_MJML_META, true);

    foreach (['mail', 'mail_2'] as $name) {
        $from = (array) get_post_meta($original_id, '_' . $name, true);
        $mail = (array) get_post_meta($id, '_' . $name, true);

        if ($name === 'mail_2' && empty($from['active'])) {
            continue;
        }

        $count += (int) $same($from['subject'] ?? '', $mail['subject'] ?? '');
        $count += !empty($mjml_from[$name]['enabled'])
            ? (int) $same($mjml_from[$name]['source'] ?? '', $mjml[$name]['source'] ?? '')
            : (int) $same($from['body'] ?? '', $mail['body'] ?? '');
    }

    $messages = (array) get_post_meta($id, '_messages', true);

    foreach ((array) get_post_meta($original_id, '_messages', true) as $key => $message) {
        $count += (int) $same($message, $messages[$key] ?? '');
    }

    return $count;
}

/**
 * The fields of a form's duplicate its original doesn't have, by name: lost
 * if it turns fixed, its rows becoming the original's.
 *
 * @return list<string>
 */
function cf7_dropped_fields(int $id): array
{
    $names = function (int $form) {
        $out = [];

        foreach ((array) get_post_meta($form) as $key => $values) {
            if (preg_match('/^' . CF7_ROWS . '_\d+(?:_fields_\d+)?_name$/', (string) $key) && (string) ($values[0] ?? '') !== '') {
                $out[] = (string) $values[0];
            }
        }

        return array_values(array_unique($out));
    };

    return array_values(array_diff($names($id), $names(original($id))));
}
