<?php

namespace WpGo\Languages\Core;

/**
 * Making a translation, and the slugs a language may share with another.
 *
 * A translation starts as a copy of its original (create_translation()):
 * title, slug, content, excerpt, template, order, terms, featured image, and
 * the meta a policy lets through. It is a draft in the other language, in the
 * original's group, and is then edited on its own (`independent`) or kept to
 * the original's structure (`blocks`, fixed.php).
 */

const MODE_META = '_wgl_mode';

/**
 * A translation's modes: `independent`, a copy all its own (its blocks and
 * every field); `blocks`, the fixed layout: the original's blocks, every field
 * its own. Either way, any field can be linked to follow the original (synced.php).
 */
const MODES = ['independent', 'blocks'];

/** The modes whose blocks are the original's (fixed.php). */
const FIXED_MODES = ['blocks'];

/** The modes' names in 1.0, still taken from a caller (upgrade.php rewrites the stored ones). */
const MODE_ALIASES = ['duplicate' => 'independent', 'fixed' => 'blocks', 'structure' => 'blocks', 'text' => 'blocks'];

/**
 * A mode by its name now, given an old one.
 */
function normalize_mode(string $mode): string
{
    return MODE_ALIASES[$mode] ?? $mode;
}

/**
 * Every name a caller may give a mode: for a REST route's `enum`.
 *
 * @return list<string>
 */
function mode_names(): array
{
    return array_merge(MODES, array_keys(MODE_ALIASES));
}

/**
 * The nearest mode a post type has to the one asked: for a caller acting on
 * items of several types (the bulk "Translate"). A type without blocks, but a
 * form, is independent. A single item asked for what its type can't do gets an
 * error instead (create_translation()).
 */
function best_mode(string $post_type, string $mode): string
{
    $mode = normalize_mode($mode);

    return $mode === 'blocks' && !can_be_fixed($post_type) ? 'independent' : $mode;
}

/**
 * A translation's mode (MODES). An original has none.
 */
function mode_of(int $id, string $kind = 'post'): ?string
{
    $mode = normalize_mode((string) get_metadata($kind, $id, MODE_META, true));

    // a term has no layout to fix
    if (in_array($mode, $kind === 'term' ? ['independent'] : MODES, true)) {
        return $mode;
    }

    return is_translation($id, $kind) ? 'independent' : null;
}

/**
 * An original being deleted hands its group to one of its translations: the
 * default language's, else the first published, else the first. It becomes
 * the original (no source, no mode), the others name it.
 *
 * Its fixed translations stay fixed where they have its blocks; the others
 * become independent, their content kept (after_new_original()).
 *
 * Without this, original() would stand the default language's in, if any
 * (else each would be its own original, syncing its copy fields to the
 * others).
 */
function replace_deleted_original(int $post_id): void
{
    if (!is_translatable($post_id) || is_translation($post_id)) {
        return;
    }

    $others = array_values(array_diff(translations($post_id), [$post_id]));

    if (!$others) {
        return;
    }

    $published = array_values(array_filter($others, fn ($id) => get_post_status($id) === 'publish'));
    $new       = translation($post_id, default_language()) ?? $published[0] ?? $others[0];

    if ($new === $post_id) {
        $new = $published[0] ?? $others[0];
    }

    delete_post_meta($new, SOURCE_META);
    delete_post_meta($new, MODE_META);
    forget_synced($new);

    $rest = array_values(array_diff($others, [$new]));

    foreach ($rest as $id) {
        update_post_meta($id, SOURCE_META, $new);
    }

    after_new_original($new, $rest);
    forget_translations();
}

/**
 * A group has a new original: each fixed translation that doesn't have its
 * blocks becomes independent, its content kept, or its next save would drop
 * its own blocks and bring in the new original's, in another language. The
 * rows each remembers were another original's: they go by their place now.
 *
 * @param list<int> $ids The group's other posts.
 */
function after_new_original(int $new, array $ids): void
{
    foreach ($ids as $id) {
        if (is_fixed($id) && !same_structure($new, $id)) {
            update_post_meta($id, MODE_META, 'independent');
        }
    }
}

/**
 * Make an item its group's original: it loses its source (and mode), the
 * others name it. The old original becomes an independent translation, its content kept;
 * fixed translations with this one's blocks stay fixed, now following it, and
 * the others become independent (after_new_original()). False if it already
 * was the original.
 */
function make_original(int $id, string $kind = 'post'): bool
{
    $old = original($id, $kind);

    if ($old === $id) {
        return false;
    }

    $update = $kind === 'term' ? 'update_term_meta' : 'update_post_meta';
    $delete = $kind === 'term' ? 'delete_term_meta' : 'delete_post_meta';

    foreach (translations($id, $kind) as $member) {
        if ($member !== $id) {
            $update($member, SOURCE_META, $id);
        }
    }

    $delete($id, SOURCE_META);

    $delete($id, MODE_META);
    forget_synced($id, $kind);
    $update($old, MODE_META, 'independent');
    $delete($old, CHOSEN_META);

    if ($kind !== 'term') {
        after_new_original($id, array_values(array_diff(translations($id), [$id, $old])));
    }

    forget_translations();

    /**
     * An item became its group's original.
     *
     * @param int    $id   The new original.
     * @param int    $old  The one it replaced, now a translation of it.
     * @param string $kind `post` or `term`.
     */
    do_action('wp_go_languages/original_changed', $id, $old, $kind);

    return true;
}

/**
 * Someone picked this item as its group's original (the panels' "Make this the
 * original"): make_original(), in any language, and the choice is kept. A new
 * default language, a link or a new default-language translation leave the
 * group's original alone then (original_is_chosen()). Choosing the default
 * language's item ends that: it's what the group would have by itself, so the
 * next default language applies to it again. False if it already was the
 * original.
 */
function choose_original(int $id, string $kind = 'post'): bool
{
    $changed = make_original($id, $kind);

    if (language_of($id, $kind) === default_language()) {
        delete_metadata($kind, $id, CHOSEN_META);
    } else {
        update_metadata($kind, $id, CHOSEN_META, '1');
    }

    return $changed;
}

/**
 * Whether an item's group has an original chosen by hand (choose_original()).
 */
function original_is_chosen(int $id, string $kind = 'post'): bool
{
    return (bool) get_metadata($kind, original($id, $kind), CHOSEN_META, true);
}

/**
 * A new default language: every group with an item in `$code` that is a
 * translation gets that item as its original (make_original()), posts and
 * terms, so translations are made from, and fixed ones follow, the default
 * language. Groups with nothing in `$code` keep theirs, and so do groups whose
 * original was chosen by hand (original_is_chosen()). How many changed.
 */
function make_originals(string $code): int
{
    global $wpdb;

    $count = 0;

    $posts = $wpdb->get_col($wpdb->prepare(
        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = pm.post_id AND tr.term_taxonomy_id = %d
         WHERE pm.meta_key = %s",
        language_tt_id($code),
        SOURCE_META
    ));

    foreach ($posts as $id) {
        if (!original_is_chosen((int) $id)) {
            $count += (int) make_original((int) $id);
        }
    }

    $terms = $wpdb->get_col($wpdb->prepare(
        "SELECT s.term_id FROM {$wpdb->termmeta} s
         INNER JOIN {$wpdb->termmeta} l ON l.term_id = s.term_id AND l.meta_key = %s AND l.meta_value = %s
         WHERE s.meta_key = %s",
        TERM_LANGUAGE_META,
        $code,
        SOURCE_META
    ));

    foreach ($terms as $id) {
        if (!original_is_chosen((int) $id, 'term')) {
            $count += (int) make_original((int) $id, 'term');
        }
    }

    return $count;
}

/**
 * Join two items' translations into one group: a page made on its own in
 * another language, linked to its original after (the editor's "Link to a
 * translation…"), or two terms. Refused if the two have an item in the same
 * language, or are of different types (taxonomies, for terms).
 *
 * A group whose original was chosen by hand stays, with that original; else
 * the group with the default language's item (else `$id`'s), which is then the
 * original (make_original()). With both chosen, the same goes and the other's
 * choice ends. The other's items join it, posts as independent translations,
 * their content kept: a fixed one would follow an original its blocks have
 * nothing in common with.
 */
function connect(int $id, int $other, string $kind = 'post'): bool|\WP_Error
{
    if ($kind === 'term') {
        $a = get_term($id);
        $b = get_term($other);

        if (!$a instanceof \WP_Term || !$b instanceof \WP_Term || $a->term_id === $b->term_id) {
            return new \WP_Error('wgl_connect_posts', 'Two different terms are needed.');
        }

        if ($a->taxonomy !== $b->taxonomy || !is_translatable($a->term_id, 'term')) {
            return new \WP_Error('wgl_connect_type', 'Only terms of one translated taxonomy are linked.');
        }
    } else {
        $a = get_post($id);
        $b = get_post($other);

        if (!$a instanceof \WP_Post || !$b instanceof \WP_Post || $a->ID === $b->ID) {
            return new \WP_Error('wgl_connect_posts', 'Two different posts are needed.');
        }

        if ($a->post_type !== $b->post_type || !is_translated_post_type($a->post_type)) {
            return new \WP_Error('wgl_connect_type', 'Only posts of one translated type are linked.');
        }
    }

    $mine   = translations($id, $kind);
    $theirs = translations($other, $kind);

    if (!$mine || !$theirs) {
        return new \WP_Error('wgl_link_no_language', 'Both items need a language before they are linked.');
    }

    $both = array_intersect_key($mine, $theirs);

    if ($both) {
        return new \WP_Error('wgl_connect_taken', sprintf('Both already have an item in %s.', implode(', ', array_keys($both))));
    }

    $default      = default_language();
    $mine_chosen  = original_is_chosen($id, $kind);
    $their_chosen = original_is_chosen($other, $kind);

    [$keep, $move] = $mine_chosen !== $their_chosen
        ? ($their_chosen ? [$theirs, $mine] : [$mine, $theirs])
        : (isset($theirs[$default]) ? [$theirs, $mine] : [$mine, $theirs]);
    $original = original((int) reset($keep), $kind);
    $chosen   = original_is_chosen($original, $kind);

    foreach ($move as $member) {
        $linked = link($member, $original, $kind);

        if (is_wp_error($linked)) {
            return $linked;
        }

        if ($kind === 'post') {
            update_post_meta($member, MODE_META, 'independent');
        }
    }

    if (isset($keep[$default]) && !$chosen) {
        make_original($keep[$default], $kind);
    }

    return true;
}

/**
 * Take an item out of its translations (the editor's "Unlink"): it keeps its
 * content and language, alone. An original hands its group to another first:
 * a post as a deleted one does (replace_deleted_original()), a term to the
 * default language's, else the first.
 */
function leave_group(int $id, string $kind = 'post'): void
{
    forget_synced($id, $kind);

    if ($kind === 'term') {
        $others = array_diff(translations($id, 'term'), [$id]);

        if ($others && original($id, 'term') === $id) {
            make_original((int) ($others[default_language()] ?? reset($others)), 'term');
        }

        unlink($id, 'term');

        return;
    }

    replace_deleted_original($id);
    unlink($id);
}

/**
 * A slug for a term's translation: the original's with the language's code
 * (`eventi-en`), and a number if a term of the taxonomy has it, in any
 * language. Given, not left to WordPress: its uniqueness check queries the
 * terms in the current language only, finds the original's slug free, and
 * its last check (raw SQL) then takes the new term for an independent translation of the
 * original and hands that back.
 */
function term_translation_slug(\WP_Term $original, string $code): string
{
    global $wpdb;

    $base = $original->slug . '-' . $code;
    $slug = $base;

    for ($n = 2; $wpdb->get_var($wpdb->prepare(
        "SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s LIMIT 1",
        $slug,
        $original->taxonomy
    )); $n++) {
        $slug = $base . '-' . $n;
    }

    return $slug;
}

/**
 * Translate a term into `$code`: a copy of its group's original (name,
 * description, the parent's translation as parent), in the original's group.
 * Into the default language it becomes the group's original, as a post's
 * does, unless one was chosen by hand. Its slug is term_translation_slug()'s (`eventi-en`).
 *
 * @return int|\WP_Error The translation's term ID.
 */
function create_term_translation(int $term_id, string $code): int|\WP_Error
{
    $term = get_term($term_id);

    if (!$term instanceof \WP_Term || !is_translated_taxonomy($term->taxonomy)) {
        return new \WP_Error('wgl_not_translatable', 'This item is not of a translated taxonomy.');
    }

    if (!is_language($code)) {
        return new \WP_Error('wgl_bad_language', sprintf('%s is not one of the site\'s languages.', $code));
    }

    if (language_of($term->term_id, 'term') === null) {
        set_language($term->term_id, default_language(), 'term');
    }

    if (language_of($term->term_id, 'term') === $code) {
        return new \WP_Error('wgl_same_language', 'The translation must be in another language.');
    }

    $original = get_term(original($term->term_id, 'term')) ?: $term;
    $existing = translations($original->term_id, 'term')[$code] ?? null;

    if ($existing) {
        return new \WP_Error('wgl_exists', sprintf('This term already has a translation in %s (%d).', $code, $existing));
    }

    $parent = $original->parent ? (translation($original->parent, $code, false, 'term') ?? 0) : 0;

    // made in the language: its name is checked against that language's terms
    $made = in_language($code, fn () => wp_insert_term($original->name, $original->taxonomy, [
        'description' => localize_html($original->description, $code),
        'parent'      => $parent,
        'slug'        => term_translation_slug($original, $code),
    ]));

    if (is_wp_error($made)) {
        return $made;
    }

    $new_id = (int) $made['term_id'];

    set_language($new_id, $code, 'term');

    $linked = link($new_id, $original->term_id, 'term');

    if (is_wp_error($linked)) {
        wp_delete_term($new_id, $original->taxonomy);

        return $linked;
    }

    // its fields by their rules, as a post's: all but `ignore` copied
    update_term_meta($new_id, MODE_META, 'independent');
    copy_meta($original->term_id, $new_id, 'create', 'term');
    localize_meta($new_id, $code, 'term');

    /**
     * A term's translation was made.
     *
     * @param int    $new_id      The translation.
     * @param int    $original_id Its original.
     * @param string $code        Its language.
     */
    do_action('wp_go_languages/term_translation_created', $new_id, $original->term_id, $code);

    if ($code === default_language() && !original_is_chosen($new_id, 'term')) {
        make_original($new_id, 'term');
    }

    return $new_id;
}

/**
 * Translate a post into `$code`: a draft copy, in the original's group.
 *
 * Into the default language, the copy becomes the group's original
 * (make_original()): the default language's page is the original, made
 * last or not. It is a plain copy then, never fixed; the old original turns
 * an independent translation, and the fixed translations follow the copy, whose blocks are
 * the old original's. In a group whose original was chosen by hand
 * (original_is_chosen()), that one stays, and the copy is an independent
 * translation. A type that can't be fixed (can_be_fixed()) makes independent
 * translations only.
 *
 * @return int|\WP_Error The translation's ID.
 */
function create_translation(int $id, string $code, string $mode = 'independent', bool $copy = true): int|\WP_Error
{
    $source = get_post($id);

    if (!$source instanceof \WP_Post || !is_translated_post_type($source->post_type)) {
        return new \WP_Error('wgl_not_translatable', 'This item is not of a translated type.');
    }

    if (!is_language($code)) {
        return new \WP_Error('wgl_bad_language', sprintf('%s is not one of the site\'s languages.', $code));
    }

    $mode = normalize_mode($mode);

    if (!in_array($mode, MODES, true)) {
        return new \WP_Error('wgl_bad_mode', sprintf('%s is not a translation mode (%s).', $mode, implode(', ', MODES)));
    }

    if (best_mode($source->post_type, $mode) !== $mode && $code !== default_language()) {
        return new \WP_Error('wgl_unsupported_mode', sprintf('%1$s has no %2$s translations.', $source->post_type, $mode));
    }

    $from = language_of($source->ID);

    if ($from === null) {
        set_language($source->ID, default_language());
        $from = default_language();
    }

    if ($from === $code) {
        return new \WP_Error('wgl_same_language', 'The translation must be in another language.');
    }

    // the default language's page is the original: a copy, whatever was asked
    if ($code === default_language()) {
        $mode = 'independent';
    }

    // Translating a translation translates its original.
    $original = get_post(original($source->ID)) ?: $source;
    $existing = translations($original->ID)[$code] ?? null;

    if ($existing) {
        return new \WP_Error('wgl_exists', sprintf('This page already has a translation in %s (%d).', $code, $existing));
    }

    $parent = $original->post_parent ? (translation($original->post_parent, $code) ?? 0) : 0;

    // starting-content.php: copied, or empty (a form, or the default language's
    // page, is always a copy). Either way a fixed one has the original's blocks,
    // with their IDs, to match them
    $copy    = $copy || !can_start_empty($original->post_type, $code);
    $source_content = ensure_ids($original->ID);

    $data = [
        'post_type'      => $original->post_type,
        'post_status'    => 'draft',
        'post_title'     => $copy ? $original->post_title : '',
        'post_name'      => $copy ? $original->post_name : '',
        'post_content'   => $copy ? localize_content($source_content, $code) : ($mode === 'independent' ? '' : empty_content($source_content)),
        'post_excerpt'   => $copy ? $original->post_excerpt : '',
        'post_parent'    => $parent,
        'menu_order'     => $original->menu_order,
        'post_password'  => $original->post_password,
        'comment_status' => $original->comment_status,
        'ping_status'    => $original->ping_status,
        'post_author'    => get_current_user_id() ?: $original->post_author,
    ];

    /**
     * The new translation's post fields, before it is saved.
     *
     * @param array<string,mixed> $data
     * @param \WP_Post            $original
     * @param string              $code
     * @param string              $mode
     */
    $data = (array) apply_filters('wp_go_languages/translation_data', $data, $original, $code, $mode);

    State::$inserting = $code;

    // The content is copied, not written: kses would rewrite what is already
    // stored, and an editor without unfiltered_html would get a different
    // page than the one translated.
    $kses = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;

    if ($kses) {
        kses_remove_filters();
    }

    // an empty page is what was asked: WordPress refuses one otherwise
    add_filter('wp_insert_post_empty_content', '__return_false');

    try {
        $new_id = wp_insert_post(wp_slash($data), true);
    } finally {
        remove_filter('wp_insert_post_empty_content', '__return_false');
        State::$inserting = null;

        if ($kses) {
            kses_init_filters();
        }
    }

    if (is_wp_error($new_id)) {
        return $new_id;
    }

    $new_id = (int) $new_id;

    set_language($new_id, $code);

    $linked = link($new_id, $original->ID);

    if (is_wp_error($linked)) {
        wp_delete_post($new_id, true);

        return $linked;
    }

    update_post_meta($new_id, MODE_META, $mode);

    if ($copy || $mode !== 'independent') {
        copy_meta($original->ID, $new_id, 'create');
    }

    if ($copy) {
        copy_terms($original->ID, $new_id, $code);
    }

    if ($copy) {
        localize_meta($new_id, $code);
    } elseif ($mode !== 'independent') {
        empty_meta($new_id);
    }

    /**
     * A translation was made.
     *
     * @param int    $new_id      The translation.
     * @param int    $original_id Its original.
     * @param string $code        Its language.
     * @param string $mode        Its mode.
     * @param bool   $copy        Whether it started as a copy, or empty.
     */
    do_action('wp_go_languages/translation_created', $new_id, $original->ID, $code, $mode, $copy);

    if ($code === default_language() && !original_is_chosen($new_id)) {
        make_original($new_id);
    }

    clean_post_cache($new_id);

    return $new_id;
}

/**
 * The meta every translation keeps for itself, never copied: WordPress's own
 * bookkeeping and the plugin's.
 */
function own_meta_key(string $key): bool
{
    static $own = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', '_wp_desired_post_slug'];

    return in_array($key, $own, true)
        || str_starts_with($key, '_wgl_')
        || str_starts_with($key, '_wp_trash_');
}

/**
 * What happens to a meta key across translations (policies.php): translate,
 * copy or ignore, from the wpml-config.xml files and ACF's field
 * settings, then `wp_go_languages/meta_policy`. A key nothing names is
 * `translate`: a translation starts as a copy, then is its own. `$field`, the
 * key's ACF field when the caller has it (stored_policy()).
 *
 * @param array<string,mixed>|null $field
 */
function meta_policy(string $key, int $post_id, ?array $field = null, string $kind = 'post'): string
{
    if (own_meta_key($key)) {
        return 'ignore';
    }

    /**
     * A meta key's policy across translations: translate, copy or ignore
     * (copy_once, as WPML's, is taken as translate). Also what locks a field on a translation's screen, and makes
     * an option the default language's.
     *
     * @param string $policy  What the config files and ACF say, else translate.
     * @param string $key
     * @param int    $post_id The original; 0 for the ACF options.
     */
    $policy = (string) apply_filters('wp_go_languages/meta_policy', stored_policy($key, $post_id, $field, $kind) ?? 'translate', $key, $post_id, $kind);

    return in_array($policy, ['copy', 'ignore'], true) ? $policy : 'translate';
}

/**
 * Copy an original's meta to a translation: on `create`, every key but the
 * ignored ones; on `sync` (a save of the original), only the `copy` ones.
 */
function copy_meta(int $from, int $to, string $when, string $kind = 'post'): void
{
    $meta = get_metadata($kind, $from);

    foreach ((array) $meta as $key => $values) {
        $key    = (string) $key;
        $policy = meta_policy($key, $from, null, $kind);

        if ($policy === 'ignore' || ($when === 'sync' && $policy !== 'copy')) {
            continue;
        }

        $values = array_map('maybe_unserialize', (array) $values);

        if ($when === 'sync') {
            $current = array_map('maybe_unserialize', (array) get_metadata($kind, $to, $key));

            if ($current == $values) {
                continue;
            }
        }

        delete_metadata($kind, $to, $key);

        foreach ($values as $value) {
            add_metadata($kind, $to, $key, wp_slash($value));
        }
    }
}

/**
 * A page translated after its children: their translations in this language,
 * made while it had none and so without a parent, go under it, as the original's
 * are. One given another parent since stays where it is.
 */
function reconnect_children(int $new_id, int $original_id, string $code): void
{
    $children = get_posts([
        'post_type'      => (string) get_post_type($original_id),
        'post_parent'    => $original_id,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'lang'           => 'all',
    ]);

    foreach ($children as $child) {
        $translated = translation((int) $child, $code);

        if ($translated && !(int) get_post_field('post_parent', $translated)) {
            wp_update_post(['ID' => $translated, 'post_parent' => $new_id]);
        }
    }
}

/**
 * A term translated after the posts that have it: each post's translation in this
 * language, made while the term had none and so without it, gets it, as the
 * original has it. A term's translated children, made without a parent, go under
 * it too.
 */
function reconnect_term(int $new_id, int $original_id, string $code): void
{
    $term = get_term($original_id);

    if (!$term instanceof \WP_Term) {
        return;
    }

    $objects = get_objects_in_term($original_id, $term->taxonomy);

    in_language($code, function () use ($objects, $code, $new_id, $term) {
        foreach (is_array($objects) ? $objects : [] as $object) {
            $translated = translation((int) $object, $code);

            if ($translated) {
                wp_set_object_terms($translated, [$new_id], $term->taxonomy, true);
            }
        }
    });

    if (!is_taxonomy_hierarchical($term->taxonomy)) {
        return;
    }

    foreach (in_language((string) language_of($original_id, 'term'), fn () => get_term_children($original_id, $term->taxonomy)) as $child) {
        $translated = translation((int) $child, $code, false, 'term');

        if ($translated && !get_term($translated)->parent) {
            wp_update_term($translated, $term->taxonomy, ['parent' => $new_id]);
        }
    }
}

/**
 * Give a translation its original's terms: as they are for a taxonomy that is
 * not translated, the terms' translations for one that is. The plugin's own
 * taxonomies are left alone.
 */
function copy_terms(int $from, int $to, string $code): void
{
    $type = get_post_type($from);

    if (!$type) {
        return;
    }

    foreach (get_object_taxonomies($type) as $taxonomy) {
        if (in_array($taxonomy, [LANGUAGE_TAXONOMY, GROUP_TAXONOMY], true)) {
            continue;
        }

        // Terms of a translated taxonomy are found in a language only
        // (terms_clauses): the original's read in its own, the translation's
        // set in its own, or wp_set_object_terms()'s term_exists() misses them
        $translated = is_translated_taxonomy($taxonomy);
        $read = fn () => wp_get_object_terms($from, $taxonomy, ['fields' => 'ids']);
        $ids = $translated ? in_language((string) language_of($from), $read) : $read();

        if (is_wp_error($ids) || !$ids) {
            continue;
        }

        if ($translated) {
            $ids = array_values(array_filter(array_map(
                fn ($term_id) => translation((int) $term_id, $code, false, 'term'),
                $ids
            )));
        }

        $write = fn () => wp_set_object_terms($to, array_map('intval', $ids), $taxonomy);
        $translated ? in_language($code, $write) : $write();
    }
}

/**
 * A slug may repeat across languages: `/chi-siamo` and `/en/chi-siamo` are
 * two pages. WordPress makes every slug unique across the type
 * (`chi-siamo-2`); this keeps the one asked for when only another language
 * has it. A suffix WordPress adds for another reason (a feed's name, `embed`,
 * a number, a slug a filter calls bad) stays.
 *
 * A top-level page may not take a language's segment as its slug (`en`), or
 * its URL would read as that language's home.
 */
function unique_post_slug(string $slug, int $post_id, string $status, string $post_type, int $parent, string $original_slug): string
{
    if (!is_translated_post_type($post_type)) {
        return $slug;
    }

    $code = State::$inserting ?? language_of($post_id) ?? filter_language() ?? default_language();

    $only_other_language = $slug !== $original_slug
        && !slug_reserved($original_slug, $post_id, $post_type, $parent)
        && !slug_taken($original_slug, $post_id, $post_type, $parent, $code);

    if ($only_other_language) {
        $slug = $original_slug;
    }

    if ($parent === 0 && language_by_slug($slug) !== null) {
        $suffix = 2;

        while (slug_taken($slug . '-' . $suffix, $post_id, $post_type, $parent, $code)) {
            $suffix++;
        }

        $slug .= '-' . $suffix;
    }

    return $slug;
}

/**
 * Whether WordPress refuses a slug whoever has it: wp_unique_post_slug()'s
 * other reasons for a suffix, as it tests them (a hierarchical type, or a
 * flat one).
 */
function slug_reserved(string $slug, int $post_id, string $post_type, int $parent): bool
{
    global $wp_rewrite;

    $feeds = is_array($wp_rewrite->feeds ?? null) ? $wp_rewrite->feeds : [];

    if (in_array($slug, $feeds, true) || $slug === 'embed') {
        return true;
    }

    if (is_post_type_hierarchical($post_type)) {
        return (bool) preg_match('@^(' . $wp_rewrite->pagination_base . ')?\d+$@', $slug)
            || apply_filters('wp_unique_post_slug_is_bad_hierarchical_slug', false, $slug, $post_type, $parent);
    }

    // A post's number that a date archive could read as a year, month or day.
    $post = get_post($post_id);

    if ($post_type === 'post' && (!$post || $post->post_name !== $slug) && preg_match('/^[0-9]+$/', $slug) && (int) $slug) {
        $parts    = array_values(array_filter(explode('/', (string) get_option('permalink_structure'))));
        $index    = array_search('%postname%', $parts, true);
        $previous = $index ? $parts[$index - 1] : null;

        if ($index === 0 || ($previous === '%year%' && (int) $slug < 13) || ($previous === '%monthnum%' && (int) $slug < 32)) {
            return true;
        }
    }

    return (bool) apply_filters('wp_unique_post_slug_is_bad_flat_slug', false, $slug, $post_type);
}

/**
 * Whether a slug is taken in a language: by another post of the type (under
 * the same parent, for a hierarchical one), or by an attachment, which has
 * no language.
 */
function slug_taken(string $slug, int $post_id, string $post_type, int $parent, string $code): bool
{
    global $wpdb;

    // As wp_unique_post_slug(): attachments share a hierarchical type's URLs
    // (`/page/attachment`), not a flat one's.
    if (is_post_type_hierarchical($post_type)) {
        $sql = "SELECT ID, post_type FROM {$wpdb->posts}
                WHERE post_name = %s AND ID != %d AND post_status NOT IN ('trash', 'auto-draft')
                AND post_type IN (%s, 'attachment') AND post_parent = %d";
        $args = [$slug, $post_id, $post_type, $parent];
    } else {
        $sql = "SELECT ID, post_type FROM {$wpdb->posts}
                WHERE post_name = %s AND ID != %d AND post_status NOT IN ('trash', 'auto-draft')
                AND post_type = %s";
        $args = [$slug, $post_id, $post_type];
    }

    foreach ((array) $wpdb->get_results($wpdb->prepare($sql, $args)) as $row) {
        if ($row->post_type === 'attachment' || language_of((int) $row->ID) === $code) {
            return true;
        }
    }

    return false;
}
