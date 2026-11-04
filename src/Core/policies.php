<?php

namespace WpGo\Languages\Core;

/**
 * What happens to a post's meta across its translations:
 *
 *   - a key the `wpml-config.xml` of an active plugin or the theme names
 *     (`<custom-fields><custom-field action="copy-once">_form</custom-field>`):
 *     the theme's CF7 keys, WP-Go's, Yoast's own. The theme's wins a key a
 *     plugin's names too;
 *   - an ACF field goes by its type alone (field_policy()): a text is
 *     translated, anything else follows the original. No setting on the field:
 *     an exception (a text that holds no words, a form field's name) is named
 *     in code, on `wp_go_languages/field_policy`.
 *
 * The policies (translate.php's meta_policy()), three:
 *
 *   translate  per language: copied when the translation is made, then its
 *              own (on a text-only translation, translated there); an option
 *              not set in a language reads the default's if it isn't a text
 *   copy       the same in every language: the original's, copied when it is
 *              made, and on each save of it or of the translation to a
 *              text-only translation (`text`), where it isn't shown
 *   ignore     starts empty: never copied, then its own
 *
 * WPML's fourth, copy once (`copy-once`), is `translate` here: a translation
 * starts as a copy of everything, so the two do the same.
 *
 * A key no rule names is `translate`: a translation starts as a copy.
 */

/** On a text-only translation: its original's page-field rows, as it last followed them (sync_rows()). */
const ROWS_META = '_wgl_rows';

/** WPML's custom-field actions, in the plugin's words (Yoast's file writes `copy_once`); copy once is `translate`. */
const WPML_ACTIONS = ['copy' => 'copy', 'copy-once' => 'translate', 'copy_once' => 'translate', 'translate' => 'translate', 'ignore' => 'ignore'];

/**
 * The `wpml-config.xml` files read, at their root, as WPML reads them: each
 * active plugin's, then the theme's (parent, then child). A later file wins
 * (config_policies()), so the theme overrides a plugin: a site naming a
 * plugin's key means to change what the plugin says.
 *
 * @return list<string>
 */
function config_files(): array
{
    $files = [];

    foreach ((array) get_option('active_plugins', []) as $plugin) {
        $files[] = WP_PLUGIN_DIR . '/' . dirname((string) $plugin) . '/wpml-config.xml';
    }

    $files[] = get_template_directory() . '/wpml-config.xml';
    $files[] = get_stylesheet_directory() . '/wpml-config.xml';

    /**
     * The wpml-config.xml files whose custom-field rules are read, the last
     * one winning a key.
     *
     * @param list<string> $files
     */
    $files = (array) apply_filters('wp_go_languages/config_files', $files);

    return array_values(array_unique(array_filter($files, 'is_readable')));
}

/**
 * The custom-field rules of every config file: `[meta key => policy]`. A
 * later file in config_files()'s order wins: the theme over a plugin, a
 * child theme over its parent.
 *
 * Kept in the object cache by the files' paths and times.
 *
 * @return array<string,string>
 */
function config_policies(): array
{
    static $policies = null;

    if ($policies !== null) {
        return $policies;
    }

    $files = config_files();
    $key   = 'wgl_policies_' . md5(implode('|', array_map(fn ($file) => $file . ':' . filemtime($file), $files)));

    $cached = wp_cache_get($key, 'wp_go_languages');

    if (is_array($cached)) {
        return $policies = $cached;
    }

    $policies = [];

    foreach ($files as $file) {
        $xml = @simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);

        if (!$xml || !isset($xml->{'custom-fields'})) {
            continue;
        }

        foreach ($xml->{'custom-fields'}->{'custom-field'} as $field) {
            $name   = trim((string) $field);
            $action = (string) ($field['action'] ?? '');

            if ($name !== '' && isset(WPML_ACTIONS[$action])) {
                $policies[$name] = WPML_ACTIONS[$action];
            }
        }
    }

    wp_cache_set($key, $policies, 'wp_go_languages', HOUR_IN_SECONDS);

    return $policies;
}

/**
 * An ACF field's policy, in a page and in a block: a text is translated,
 * anything else (an image, a select, a repeater's rows) is the original's.
 *
 * @param array<string,mixed> $field
 */
function field_policy(array $field): string
{
    /**
     * An ACF field's rule, wherever it is: on a page, an options page, a term or
     * in a block. `copy` for a text that holds no words (a form field's name, a
     * phone number); `translate` for what isn't a text but differs per language;
     * `ignore` for what each translation has of its own, never copied. By the
     * field's key: a setting on the field would be dropped by ACF's field-group
     * screen on a save. A meta key that's no field: a wpml-config.xml, or
     * `wp_go_languages/meta_policy`.
     *
     * @param string              $policy `translate` for a text, else `copy`.
     * @param array<string,mixed> $field  A clone's field has its own key in `__key`.
     */
    $policy = apply_filters('wp_go_languages/field_policy', in_array($field['type'] ?? '', TEXT_TYPES, true) ? 'translate' : 'copy', $field);

    return in_array($policy, ['copy', 'ignore'], true) ? $policy : 'translate';
}

/**
 * Whether an ACF field holds words to translate: a text type the rule keeps so.
 *
 * @param array<string,mixed> $field
 */
function is_text_field(array $field): bool
{
    return in_array($field['type'] ?? '', TEXT_TYPES, true) && field_policy($field) === 'translate';
}

/**
 * The ACF field a post's (or a term's) meta key holds, or null: ACF stores
 * each value beside its field's key (`title` ⟷ `_title` = `field_…`), its
 * reference key alike (`_title`).
 *
 * @return array<string,mixed>|null
 */
function acf_field_of(string $key, int $post_id, string $kind = 'post'): ?array
{
    if (!function_exists('acf_get_field')) {
        return null;
    }

    $reference = get_metadata($kind, $post_id, str_starts_with($key, '_') ? $key : '_' . $key, true);

    if (!is_string($reference) || !str_starts_with($reference, 'field_')) {
        return null;
    }

    $field = acf_get_field($reference);

    return is_array($field) ? $field : null;
}

/**
 * The policy the config files and ACF give a key, or null: what
 * meta_policy() starts from. `$field` is the key's ACF field when the caller
 * has it (a field being rendered, which may have no value yet); else it is
 * found through the post's reference.
 *
 * @param array<string,mixed>|null $field
 */
function stored_policy(string $key, int $post_id, ?array $field = null, string $kind = 'post'): ?string
{
    $config = config_policies();

    if (isset($config[$key])) {
        return $config[$key];
    }

    $field ??= acf_field_of($key, $post_id, $kind);

    return $field ? field_policy($field) : null;
}

/**
 * On a save of an original, its `copy` meta to each of its text-only
 * translations (`text`; an independent one's fields, and a `blocks` one's,
 * are all its own), as WPML does: the same request, through update_post_meta(), so what listens
 * to a translation's meta (Cache Tags) sees it changed.
 *
 * On a text-only translation's own save, the same from its original: the
 * editor posts its page's fields after the switch into the mode, from the
 * screen as it was before it, and they'd stay until the original's next save.
 */
function sync_translations(int $post_id, \WP_Post $post): void
{
    static $running = false;

    if ($running || wp_is_post_revision($post_id) || in_array($post->post_status, ['auto-draft', 'trash'], true) || !is_translated_post_type($post->post_type)) {
        return;
    }

    if (is_translation($post_id)) {
        // a form follows its original its own way (cf7.php)
        if ($post->post_type !== CF7_TYPE && mode_of($post_id) === 'text' && !held_back($post_id)) {
            sync_rows(original($post_id), $post_id);
            copy_meta(original($post_id), $post_id, 'sync');
        }

        return;
    }

    $running = true;

    try {
        foreach (translations($post_id) as $id) {
            // only a text-only translation (`text`): an independent one's
            // fields are all its own, as are a `blocks` one's; a form's
            // rows matched by their fields' names (cf7.php)
            if ($id !== $post_id && mode_of($id) === 'text' && !held_back($id)) {
                if ($post->post_type === CF7_TYPE) {
                    cf7_sync_rows($post_id, $id);
                } else {
                    sync_rows($post_id, $id);
                    copy_meta($post_id, $id, 'sync');
                }

                /**
                 * A translation was brought in line with its original.
                 *
                 * @param int $translation_id
                 * @param int $original_id
                 */
                do_action('wp_go_languages/synced', $id, $post_id);
            }
        }
    } finally {
        $running = false;
    }
}

/**
 * On a save of an original term (`saved_term`, after ACF has saved its
 * fields), its `copy` meta to each of its fixed translations, as
 * sync_translations() does a post's. An independent translation's are all its own.
 */
function sync_term_translations(int $term_id): void
{
    static $running = false;

    $term = get_term($term_id);

    if ($running || !$term instanceof \WP_Term || !is_translated_taxonomy($term->taxonomy) || is_translation($term_id, 'term')) {
        return;
    }

    $running = true;

    try {
        foreach (translations($term_id, 'term') as $id) {
            if ($id !== $term_id && mode_of($id, 'term') === 'text') {
                sync_rows($term_id, $id, 'term');
                copy_meta($term_id, $id, 'sync', 'term');

                /**
                 * A term's translation was brought in line with its original.
                 *
                 * @param int $translation_id
                 * @param int $original_id
                 */
                do_action('wp_go_languages/term_synced', $id, $term_id);
            }
        }
    } finally {
        $running = false;
    }
}

/**
 * An item's page-field repeaters and flexible contents, flat, as a block's
 * data is (`items`, `items_0_title`, `_items_0_title`): what row_fields() and
 * row_maps() read.
 *
 * @return array<string,mixed>
 */
function page_rows(int $id, string $kind = 'post'): array
{
    $meta = (array) get_metadata($kind, $id);
    $flat = [];

    foreach ($meta as $key => $values) {
        $key       = (string) $key;
        $reference = $meta['_' . $key][0] ?? null;

        if (!str_starts_with($key, '_') && is_string($reference) && str_starts_with($reference, 'field_')) {
            $flat[$key]       = maybe_unserialize($values[0] ?? '');
            $flat['_' . $key] = $reference;
        }
    }

    $names = row_fields($flat);

    return array_filter($flat, function ($key) use ($names) {
        $key = ltrim((string) $key, '_');

        foreach ($names as $name) {
            if ($key === $name || str_starts_with($key, $name . '_')) {
                return true;
            }
        }

        return false;
    }, ARRAY_FILTER_USE_KEY);
}

/**
 * A text-only translation's page-field rows, brought in line with its
 * original's before their `copy` cells are copied: each text goes where its row
 * went, as in a block (match_rows()), so a text stays beside its image. A new
 * row starts with the original's text; a row that's gone takes its cells with
 * it. ROWS_META keeps the original's rows as this translation last followed
 * them, as the next save's "before". With none (made before it was kept, or
 * just switched to this mode), rows go by their place.
 */
function sync_rows(int $from, int $to, string $kind = 'post'): void
{
    $now    = page_rows($from, $kind);
    $before = get_metadata($kind, $to, ROWS_META, true);

    if ($now === $before) {
        return;
    }

    if (!$now) {
        delete_metadata($kind, $to, ROWS_META);

        return;
    }

    $maps = is_array($before) ? row_maps($now, $before) : [];
    $own  = (array) get_metadata($kind, $to);
    $set  = [];

    foreach ($now as $name => $value) {
        $name = (string) $name;

        if (str_starts_with($name, '_') || in_array(block_field((string) ($now['_' . $name] ?? ''))['type'] ?? '', CONTAINER_TYPES, true) || meta_policy($name, $from, null, $kind) !== 'translate') {
            continue;
        }

        $source = $maps ? moved_key($name, $maps) : $name;

        $set[$name]       = $source !== null && isset($own[$source]) ? maybe_unserialize($own[$source][0]) : $value;
        $set['_' . $name] = $now['_' . $name];
    }

    foreach ($set as $name => $value) {
        if (!isset($own[$name]) || maybe_unserialize($own[$name][0]) !== $value) {
            update_metadata($kind, $to, $name, wp_slash($value));
        }
    }

    // the cells of rows the original no longer has
    foreach (array_keys($own) as $key) {
        foreach (row_fields($now) as $row) {
            if (!array_key_exists($key, $now) && preg_match('/^_?' . preg_quote($row, '/') . '_\d+_/', (string) $key)) {
                delete_metadata($kind, $to, (string) $key);
            }
        }
    }

    update_metadata($kind, $to, ROWS_META, wp_slash($now));
}

/**
 * The fields a translation copies from its original (`copy`), on its edit
 * screen: hidden, as they're changed in the original (the default
 * language's options, for an options page). The page's own fields and their
 * sub fields (a repeater's cells, a group's, a clone's), not a block's,
 * which live in its content. The rule is meta_policy()'s, as the sync's:
 * the config files, the field, the filter.
 *
 *   - a field: disabled too, so it isn't posted and ACF doesn't save it;
 *   - a sub field: read-only, still posted: a repeater row with no input
 *     posted would be deleted. What it posts is the stored copy;
 *   - a repeater or flexible content that's `copy`: its rows fixed (the
 *     count is what the sync copies), each cell by its own rule, shown if
 *     one of them is;
 *   - a group or a clone: shown if one of its fields is; a tab, if a field
 *     after it is (copied_field_shows()).
 *
 * A form's translation (Contact Form 7) is its own case: an independent translation's
 * fields are all its own, a fixed one's conditions' values hidden too
 * (cf7.php).
 *
 * @param array<string,mixed>|false $field
 * @return array<string,mixed>|false
 */
function lock_copied_field($field)
{
    if (!is_array($field) || !locks_copied_fields()) {
        return $field;
    }

    $prefix = (string) ($field['prefix'] ?? '');
    $type   = (string) ($field['type'] ?? '');

    // A block's fields are named `acf-block_…`: they live in its content.
    // A message, an accordion hold nothing of their own.
    if (($prefix !== 'acf' && !str_starts_with($prefix, 'acf[')) || in_array($type, ['accordion', 'message'], true)) {
        return $field;
    }

    [$post_id, $kind] = copied_owner();
    $form             = $kind === 'post' && $post_id > 0 && get_post_type(edited_post_id()) === CF7_TYPE;

    $class = (string) ($field['wrapper']['class'] ?? '');

    if (!copied_field_shows($field, $post_id, $form, true, $kind)) {
        $class .= ' wgl-copied';

        if ($type === 'tab') {
            // a tab's own class is its button's, which ACF moves into its bar
            $field['class'] = trim(($field['class'] ?? '') . ' wgl-copied');
        } elseif (!in_array($type, CONTAINER_TYPES, true)) {
            $field['readonly'] = 1;

            if ($prefix === 'acf') {
                $field['disabled'] = 1;
            }
        }
    }

    if (($type === 'repeater' || $type === 'flexible_content') && meta_policy(field_meta_key($field), $post_id, $field, $kind) === 'copy') {
        $rows         = is_array($field['value'] ?? null) ? count($field['value']) : (int) ($field['value'] ?? 0);
        $field['min'] = $rows;
        $field['max'] = $rows;
        $class       .= ' wgl-fixed-rows';
    }

    $field['wrapper']['class'] = trim($class);

    return $field;
}

/**
 * Whether a field shows on a translation's screen: one that isn't `copy`,
 * or a container holding one, or a tab before one. The field rendered is
 * asked of meta_policy(), by its key; those it holds, by their own rule (a
 * clone's fields, the clone's). On a fixed form, a condition's value
 * doesn't show: it follows the options.
 *
 * @param array<string,mixed> $field
 */
function copied_field_shows(array $field, int $post_id, bool $form = false, bool $rendered = true, string $kind = 'post'): bool
{
    $type = (string) ($field['type'] ?? '');

    if ($type === 'tab') {
        return tab_shows($field, $post_id, $form, $kind);
    }

    if (in_array($type, ['accordion', 'message'], true)) {
        return true;
    }

    if (in_array($type, CONTAINER_TYPES, true)) {
        $subs = (array) ($field['sub_fields'] ?? []);

        foreach ((array) ($field['layouts'] ?? []) as $layout) {
            $subs = array_merge($subs, (array) ($layout['sub_fields'] ?? []));
        }

        foreach ($subs as $sub) {
            if (is_array($sub) && ($sub['type'] ?? '') !== 'tab' && copied_field_shows($sub, $post_id, $form, false, $kind)) {
                return true;
            }
        }

        return false;
    }

    if ($form && cf7_is_condition_value($field)) {
        return false;
    }

    $policy = $rendered ? meta_policy(field_meta_key($field), $post_id, $field, $kind) : field_policy($field);

    return $policy !== 'copy';
}

/**
 * Whether a tab has a field to show before the next tab: among its
 * siblings, a layout's, a repeater's, or the field group's (whose fields
 * are asked of meta_policy() by their names, as rendered).
 *
 * @param array<string,mixed> $tab
 */
function tab_shows(array $tab, int $post_id, bool $form = false, string $kind = 'post'): bool
{
    if (!function_exists('acf_get_field') || empty($tab['parent'])) {
        return true;
    }

    $parent = acf_get_field($tab['parent']);
    $top    = !is_array($parent);
    $subs   = [];

    if (!$top) {
        foreach ((array) ($parent['layouts'] ?? []) as $layout) {
            if (($layout['key'] ?? '') === ($tab['parent_layout'] ?? null)) {
                $subs = (array) $layout['sub_fields'];
            }
        }

        $subs = $subs ?: (array) ($parent['sub_fields'] ?? []);
    } elseif (function_exists('acf_get_fields')) {
        $subs = (array) acf_get_fields($tab['parent']);
    }

    $after = false;

    foreach ($subs as $sub) {
        if (($sub['key'] ?? '') === ($tab['key'] ?? null)) {
            $after = true;

            continue;
        }

        if (!$after) {
            continue;
        }

        if (($sub['type'] ?? '') === 'tab') {
            break;
        }

        $leaf  = !in_array($sub['type'] ?? '', CONTAINER_TYPES, true);
        $shows = $top && $leaf ? meta_policy((string) $sub['name'], $post_id, $sub, $kind) !== 'copy' : copied_field_shows($sub, $post_id, $form, false, $kind);

        if ($shows) {
            return true;
        }
    }

    // a tab not found where it should be: shown, as it was
    return !$after;
}

/**
 * The meta key a field being rendered saves to: its name for a field of the
 * page, its parents' names and row numbers before it for a sub field
 * (`items_0_title`), read from its input name (`acf[field_…][row-0][field_…]`,
 * in a block `acf-block_…[field_…]…`: the key in the block's data).
 * A new row's template counts as the first. A parent ACF can't find (a
 * clone's copy of a field): the field's own name.
 *
 * @param array<string,mixed> $field
 */
function field_meta_key(array $field): string
{
    $own    = (string) ($field['_name'] ?? $field['name'] ?? '');
    $prefix = (string) ($field['prefix'] ?? '');

    // a clone's field: under the clone's name, if the clone adds it
    $clone = !empty($field['_clone']) && function_exists('acf_get_field') ? acf_get_field($field['_clone']) : null;

    if (is_array($clone) && !empty($clone['prefix_name'])) {
        $own = $clone['name'] . '_' . $own;
    }

    if (!str_starts_with($prefix, 'acf[') && !str_starts_with($prefix, 'acf-block')) {
        return $own;
    }

    if (!function_exists('acf_get_field') || !preg_match_all('/\[([^\]]*)\]/', $prefix, $segments)) {
        return $own;
    }

    $parts = [];

    foreach ($segments[1] as $segment) {
        if (preg_match('/^row-(\d+)$/', $segment, $row)) {
            $parts[] = $row[1];

            continue;
        }

        if ($segment === 'acfcloneindex') {
            $parts[] = '0';

            continue;
        }

        $parent = str_starts_with($segment, 'field_') ? acf_get_field($segment) : null;

        if (!is_array($parent)) {
            return $own;
        }

        // a clone adds no level: its name, if any, is in its field's above
        if (($parent['type'] ?? '') !== 'clone') {
            $parts[] = (string) $parent['name'];
        }
    }

    $parts[] = $own;

    return implode('_', $parts);
}

/**
 * Whether the screen hides a translation's copied fields: a fixed
 * text-only translation (`text`) being edited, or an options page in
 * another language than the one stored as `options` (default_language()).
 */
function locks_copied_fields(): bool
{
    if (State::$context !== 'admin') {
        return false;
    }

    if (is_options_screen()) {
        return current() !== default_language();
    }

    $term_id = edited_term_id();

    if ($term_id > 0) {
        return is_translation($term_id, 'term') && mode_of($term_id, 'term') === 'text';
    }

    $post_id = edited_post_id();

    // a text-only translation: an independent one's fields, and a
    // `blocks` one's, are all its own
    return $post_id > 0 && is_translation($post_id) && mode_of($post_id) === 'text';
}

/**
 * What a translation's copied fields are read from, on the screen: `[the
 * original, 'post']`, `[the original term, 'term']`, or `[0, 'post']` on an
 * options page.
 *
 * @return array{int, string}
 */
function copied_owner(): array
{
    if (is_options_screen()) {
        return [0, 'post'];
    }

    $term_id = edited_term_id();

    return $term_id > 0 ? [original($term_id, 'term'), 'term'] : [original(edited_post_id()), 'post'];
}

/** The term a term's edit screen (`term.php?tag_ID=`) edits, or 0. */
function edited_term_id(): int
{
    $id = (int) ($_GET['tag_ID'] ?? $_POST['tag_ID'] ?? 0);

    return $id > 0 && ($GLOBALS['pagenow'] ?? '') !== 'post.php' && is_translatable($id, 'term') ? $id : 0;
}

/** Whether the screen is an ACF options page. */
function is_options_screen(): bool
{
    $page = (string) ($_GET['page'] ?? '');

    return $page !== '' && function_exists('acf_get_options_page') && acf_get_options_page($page);
}

/**
 * Whether an option falls back to the stored one in another language: a
 * `copy` field always (one value for every language), a `translate` one that
 * isn't a text while the language has none of its own (an image, a file, a
 * link to a page: the same in every language until one is changed; a text
 * stays empty until translated, rather than show another language).
 *
 * @param array<string,mixed> $field
 */
function falls_back(string $policy, array $field): bool
{
    return $policy === 'copy' || ($policy === 'translate' && !in_array($field['type'] ?? '', TEXT_TYPES, true) && !in_array($field['type'] ?? '', CONTAINER_TYPES, true));
}

/**
 * An option in another language than default_language(), read as falls_back()
 * says: a `copy` field the value stored as `options`, as the options are one
 * set with the texts translated; a `translate` field that isn't a text the
 * same while the language has none of its own (options are never made as a
 * translation, so nothing is copied at a creation: a logo saved in Italian
 * shows in English until English saves its own). The rule is
 * meta_policy()'s, the post 0.
 *
 * `options_en` is ACF's name for the English options; `options`, read as it
 * is, default_language()'s. A new default swaps them (upgrade.php).
 *
 * Before the field type's own load_value (10): what is swapped is the stored
 * value, so a `copy` repeater takes the default's rows and its cells are
 * each read again, by their own rule, in the language asked for.
 *
 * @param mixed               $value
 * @param int|string          $post_id
 * @param array<string,mixed> $field
 * @return mixed
 */
function load_copied_option($value, $post_id, array $field)
{
    if (!is_string($post_id) || !preg_match('/^options?_([a-z0-9_-]+)$/', $post_id, $match) || !is_language($match[1]) || $match[1] === default_language()) {
        return $value;
    }

    if (!function_exists('acf_get_metadata_by_field')) {
        return $value;
    }

    // with no reference row ACF hands a bare field, no key nor type: the
    // real one by its name, else no fallback (a text would show another
    // language)
    if (($field['type'] ?? '') === '') {
        $found = function_exists('acf_get_field') ? acf_get_field((string) ($field['name'] ?? '')) : null;

        if (!$found) {
            return $value;
        }

        $field = $found;
    }

    $policy = meta_policy((string) ($field['name'] ?? ''), 0, $field);

    if (!falls_back($policy, $field)) {
        return $value;
    }

    // per language: the language's own once it has one
    if ($policy === 'translate' && !in_array($value, [null, '', false, []], true)) {
        return $value;
    }

    return acf_get_metadata_by_field('options', $field) ?? ($field['default_value'] ?? null);
}

/**
 * The options' fields a language lists (get_fields('options'), its
 * `acf/load_meta`): with the ones that fall back (falls_back()) it has no
 * row of its own for, taken from the stored `options`. ACF lists only the fields a
 * language has rows for, so one never saved in it (a logo, saved in Italian
 * only) would never be read there, and load_copied_option() never asked.
 *
 * @param mixed      $meta
 * @param int|string $post_id
 * @return mixed
 */
function list_copied_options($meta, $post_id)
{
    if (!is_array($meta) || !is_string($post_id) || !preg_match('/^options?_([a-z0-9_-]+)$/', $post_id, $match) || !is_language($match[1]) || $match[1] === default_language()) {
        return $meta;
    }

    if (!function_exists('acf_get_meta') || !function_exists('acf_get_field')) {
        return $meta;
    }

    // as ACF lists them, flat (this filter leaves `options` itself alone)
    $stored = (array) acf_get_meta('options');

    foreach ($stored as $key => $value) {
        $reference = $stored["_$key"] ?? null;

        if (str_starts_with((string) $key, '_') || isset($meta[$key]) || !is_string($reference)) {
            continue;
        }

        $field = acf_get_field($reference);

        // a top-level field only: its sub fields are read through it
        if (!$field || $field['name'] !== $key || !falls_back(meta_policy($key, 0, $field), $field)) {
            continue;
        }

        $meta[$key]    = $value;
        $meta["_$key"] = $reference;
    }

    return $meta;
}

/**
 * A field's reference in a language's options it has no row for, by name
 * (get_field('logo', 'option'), its `acf/load_reference`): the stored
 * `options`' one, for a field that falls back (falls_back()), so it's found
 * and load_copied_option() asked. ACF finds a field by its name only
 * through that row.
 *
 * @param mixed      $reference
 * @param string     $name
 * @param int|string $post_id
 * @return mixed
 */
function copied_option_reference($reference, $name, $post_id)
{
    if ($reference || !is_string($post_id) || !preg_match('/^options?_([a-z0-9_-]+)$/', $post_id, $match) || !is_language($match[1]) || $match[1] === default_language()) {
        return $reference;
    }

    $stored = function_exists('acf_get_reference') ? acf_get_reference((string) $name, 'options') : null;
    $field  = $stored && function_exists('acf_get_field') ? acf_get_field($stored) : null;

    return $field && falls_back(meta_policy((string) $name, 0, $field), $field) ? $stored : $reference;
}
