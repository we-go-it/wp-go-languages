<?php

namespace WpGo\Languages\Core;

/**
 * Where a post's language and its translations are kept.
 *
 *   - its **language**: a term of the hidden taxonomy `wgl_language`, one per
 *     code. A query then keeps to a language with a term relationship, which
 *     WordPress indexes and caches (query.php);
 *   - its **translations**: a term of the hidden taxonomy `wgl_group`, one per
 *     page and its translations. The group's posts are the translations, each
 *     in its own language;
 *   - its **original**, on a translation: post meta `_wgl_source`, the post it
 *     was made from. An original has none.
 *
 * A term (of a translated taxonomy) keeps the same in term meta:
 * `_wgl_language` and `_wgl_group`.
 *
 * Nothing here knows of the request: current() is state.php's.
 */

const LANGUAGE_TAXONOMY = 'wgl_language';
const GROUP_TAXONOMY    = 'wgl_group';
const SOURCE_META       = '_wgl_source';
const TERM_LANGUAGE_META = '_wgl_language';

/** On an original someone chose by hand (choose_original(), translate.php). */
const CHOSEN_META = '_wgl_chosen';

/** A translated menu showing its original's structure (menus.php). */
const MENU_FOLLOW_META = '_wgl_menu_follow';
const TERM_GROUP_META    = '_wgl_group';

/**
 * The two taxonomies, on every translated post type. Nothing shows them: they
 * are read and written only here.
 */
function register_taxonomies(): void
{
    $args = [
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => false,
        'show_in_menu'       => false,
        'show_in_nav_menus'  => false,
        'show_in_rest'       => false,
        'show_tagcloud'      => false,
        'show_admin_column'  => false,
        'query_var'          => false,
        'rewrite'            => false,
        'hierarchical'       => false,
    ];

    register_taxonomy(LANGUAGE_TAXONOMY, translated_post_types(), $args + ['label' => 'Language']);
    register_taxonomy(GROUP_TAXONOMY, translated_post_types(), $args + ['label' => 'Translations']);
}

/**
 * The `wgl_language` term of a language, made the first time it is needed.
 */
function language_term_id(string $code): int
{
    static $ids = [];

    if (isset($ids[$code])) {
        return $ids[$code];
    }

    $term = get_term_by('slug', $code, LANGUAGE_TAXONOMY);

    if (!$term instanceof \WP_Term) {
        $made = wp_insert_term($code, LANGUAGE_TAXONOMY, ['slug' => $code]);
        $term = is_wp_error($made) ? null : get_term((int) $made['term_id'], LANGUAGE_TAXONOMY);
    }

    return $ids[$code] = $term instanceof \WP_Term ? (int) $term->term_taxonomy_id : 0;
}

/**
 * The term_taxonomy_id of a language's term, as a query's JOIN needs it, or 0
 * if no post has had it yet.
 */
function language_tt_id(string $code): int
{
    $term = get_term_by('slug', $code, LANGUAGE_TAXONOMY);

    return $term instanceof \WP_Term ? (int) $term->term_taxonomy_id : 0;
}

/**
 * Whether an item has a language at all: a post of a translated type, a term
 * of a translated taxonomy.
 */
function is_translatable(int $id, string $kind = 'post'): bool
{
    if ($kind === 'term') {
        $term = get_term($id);

        // a menu has a language and translations too (menus.php), though no
        // list of them keeps to one
        return $term instanceof \WP_Term && (is_translated_taxonomy($term->taxonomy) || $term->taxonomy === 'nav_menu');
    }

    $type = get_post_type($id);

    return $type !== false && is_translated_post_type($type);
}

/**
 * An item's language, or null: not translated, or never given one.
 */
function language_of(int $id, string $kind = 'post'): ?string
{
    if ($id <= 0) {
        return null;
    }

    if ($kind === 'term') {
        $code = (string) get_term_meta($id, TERM_LANGUAGE_META, true);

        return is_language($code) ? $code : null;
    }

    if (!is_translatable($id)) {
        return null;
    }

    // get_the_terms() reads the object term cache, primed for every post of
    // a query (update_post_term_cache).
    $terms = get_the_terms($id, LANGUAGE_TAXONOMY);

    if (!is_array($terms) || !$terms) {
        return null;
    }

    $code = $terms[0]->slug;

    return is_language($code) ? $code : null;
}

/**
 * Give an item a language. It stays in its group: link() is what moves it.
 */
function set_language(int $id, string $code, string $kind = 'post'): bool
{
    if (!is_language($code) || !is_translatable($id, $kind)) {
        return false;
    }

    if ($kind === 'term') {
        update_term_meta($id, TERM_LANGUAGE_META, $code);
        forget_translations();

        return true;
    }

    $term_id = language_term_id($code);

    if (!$term_id) {
        return false;
    }

    $set = wp_set_object_terms($id, [$code], LANGUAGE_TAXONOMY);
    forget_translations();

    if (is_wp_error($set)) {
        return false;
    }

    /**
     * A post was given a language: its URL is that language's from now on.
     *
     * @param int    $id
     * @param string $code
     */
    do_action('wp_go_languages/language_set', $id, $code);

    return true;
}

/**
 * The group an item is in: a `wgl_group` term_id for a post, the
 * `_wgl_group` key for a term. Null if it is in none.
 */
function group_of(int $id, string $kind = 'post'): int|string|null
{
    if ($kind === 'term') {
        $group = (string) get_term_meta($id, TERM_GROUP_META, true);

        return $group !== '' ? $group : null;
    }

    $terms = get_the_terms($id, GROUP_TAXONOMY);

    return is_array($terms) && $terms ? (int) $terms[0]->term_id : null;
}

/**
 * An item's translations, itself included: `[code => id]`, in the languages'
 * order. An item in no group is its own only translation.
 *
 * @return array<string,int>
 */
function translations(int $id, string $kind = 'post'): array
{
    $own = language_of($id, $kind);

    if ($own === null) {
        return [];
    }

    $group = group_of($id, $kind);

    if ($group === null) {
        return [$own => $id];
    }

    $members = group_members($group, $kind);

    if (!in_array($id, $members, true)) {
        $members[] = $id;
    }

    $by_language = [];

    foreach ($members as $member) {
        $code = $member === $id ? $own : language_of($member, $kind);

        // Two in one language would be a broken group: the item asked about
        // wins its own language, the first found the others'.
        if ($code !== null && (!isset($by_language[$code]) || $member === $id)) {
            $by_language[$code] = $member;
        }
    }

    $ordered = [];

    foreach (codes() as $code) {
        if (isset($by_language[$code])) {
            $ordered[$code] = $by_language[$code];
        }
    }

    return $ordered;
}

/**
 * A group's items, cached for the request: forget_translations() clears it.
 *
 * @return list<int>
 */
function group_members(int|string $group, string $kind = 'post', bool $forget = false): array
{
    static $cache = [];

    if ($forget) {
        $cache = [];

        return [];
    }

    $key = $kind . ':' . $group;

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if ($kind === 'term') {
        $ids = get_terms([
            'hide_empty' => false,
            'fields'     => 'ids',
            'meta_key'   => TERM_GROUP_META,
            'meta_value' => (string) $group,
            'wgl_all'    => true,
        ]);
    } else {
        $ids = get_objects_in_term((int) $group, GROUP_TAXONOMY);
    }

    $ids = is_array($ids) ? array_values(array_map('intval', $ids)) : [];

    // Posts sharing one object term cache read: the languages of the group,
    // in one query rather than one per member.
    if ($kind === 'post' && $ids) {
        update_object_term_cache($ids, translated_post_types());
    }

    return $cache[$key] = $ids;
}

/**
 * Forget the groups read: after a change to one.
 */
function forget_translations(): void
{
    group_members(0, 'post', true);
}

/**
 * An item in another language, or null (or the item itself, with
 * `$fallback`, when it has no such translation).
 */
function translation(int $id, string $code, bool $fallback = false, string $kind = 'post'): ?int
{
    $translations = translations($id, $kind);

    if (isset($translations[$code])) {
        return $translations[$code];
    }

    return $fallback ? $id : null;
}

/**
 * An item's translation visitors can reach, or null: published, in `$code`.
 * translation() finds a draft too, which a link or a form must not lead to.
 */
function published_translation(int $id, string $code): ?int
{
    $found = translation($id, $code);

    return $found && get_post_status($found) === 'publish' ? $found : null;
}

/**
 * The original an item was translated from: itself, for an original or an
 * item in no group.
 *
 * A translation names its original (`_wgl_source`). An original being deleted
 * hands its group to another (replace_deleted_original(), translate.php); for
 * a source that's gone all the same, the group's default-language item stands
 * in for it.
 */
function original(int $id, string $kind = 'post'): int
{
    if ($kind === 'term') {
        $source = (int) get_term_meta($id, SOURCE_META, true);

        return $source > 0 && get_term($source) instanceof \WP_Term ? $source : $id;
    }

    $source = (int) get_post_meta($id, SOURCE_META, true);

    if ($source <= 0) {
        return $id;
    }

    if (get_post($source) instanceof \WP_Post) {
        return $source;
    }

    return translations($id)[default_language()] ?? $id;
}

/**
 * Whether an item is a translation: it was made from another.
 */
function is_translation(int $id, string $kind = 'post'): bool
{
    return original($id, $kind) !== $id;
}

/**
 * Put an item in another item's group, as its translation: `$original_id` is
 * what it was translated from. Its language must be one the group does not
 * have yet.
 *
 * Moving an original into another group takes only it: its translations
 * stay where they are.
 */
function link(int $id, int $original_id, string $kind = 'post'): bool|\WP_Error
{
    if ($id === $original_id) {
        return new \WP_Error('wgl_link_self', 'An item cannot be its own translation.');
    }

    $code = language_of($id, $kind);

    if ($code === null || language_of($original_id, $kind) === null) {
        return new \WP_Error('wgl_link_no_language', 'Both items need a language before they are linked.');
    }

    // The original of the original: a group has one.
    $original_id = original($original_id, $kind);
    $existing    = translations($original_id, $kind)[$code] ?? null;

    if ($existing !== null && $existing !== $id) {
        return new \WP_Error('wgl_link_taken', sprintf('The original already has a translation in %s (%d).', $code, $existing));
    }

    // a translation now: whatever choice it stood for is over
    delete_metadata($kind, $id, CHOSEN_META);

    // Already in the original's group: only its original is set again.
    // Leaving it first would prune the group (left with the original alone)
    // and put it back in a group that no longer is.
    $current = group_of($id, $kind);

    if ($current !== null && $current === group_of($original_id, $kind)) {
        $kind === 'term'
            ? update_term_meta($id, SOURCE_META, $original_id)
            : update_post_meta($id, SOURCE_META, $original_id);

        forget_translations();

        return true;
    }

    // Out of its old group first (which may go), then into the original's.
    unlink($id, $kind);

    $group = group_of($original_id, $kind) ?? new_group($original_id, $kind);

    if ($group === null) {
        return new \WP_Error('wgl_link_group', 'The translation group could not be made.');
    }

    if ($kind === 'term') {
        update_term_meta($id, TERM_GROUP_META, (string) $group);
        update_term_meta($id, SOURCE_META, $original_id);
    } else {
        wp_set_object_terms($id, [(int) $group], GROUP_TAXONOMY);
        update_post_meta($id, SOURCE_META, $original_id);
    }

    forget_translations();

    /**
     * An item became a translation of another.
     *
     * @param int    $id          The translation.
     * @param int    $original_id Its original.
     * @param string $kind        `post` or `term`.
     */
    do_action('wp_go_languages/linked', $id, $original_id, $kind);

    return true;
}

/**
 * A group for an original, with it in it.
 */
function new_group(int $original_id, string $kind = 'post'): int|string|null
{
    if ($kind === 'term') {
        $group = wp_generate_uuid4();

        update_term_meta($original_id, TERM_GROUP_META, $group);

        return $group;
    }

    $made = wp_insert_term('g' . $original_id, GROUP_TAXONOMY, ['slug' => 'g' . $original_id . '-' . wp_generate_password(6, false)]);

    if (is_wp_error($made)) {
        return null;
    }

    wp_set_object_terms($original_id, [(int) $made['term_id']], GROUP_TAXONOMY);
    forget_translations();

    return (int) $made['term_id'];
}

/**
 * Take an item out of its group: it is then an original of its own, with no
 * mode. A group left with one item is removed, and that item too is an
 * original then.
 */
function unlink(int $id, string $kind = 'post'): void
{
    $group = group_of($id, $kind);

    if ($group === null) {
        return;
    }

    $others = array_diff(group_members($group, $kind), [$id]);

    if ($kind === 'term') {
        delete_term_meta($id, TERM_GROUP_META);
        delete_term_meta($id, SOURCE_META);
    } else {
        wp_delete_object_term_relationships($id, GROUP_TAXONOMY);
        delete_post_meta($id, SOURCE_META);
        // a mode is a translation's: mode() is null for an original
        delete_post_meta($id, MODE_META);
    }

    delete_metadata($kind, $id, CHOSEN_META);

    forget_translations();
    prune_group($group, $kind);

    // A group of two is pruned: the other one is an original now too.
    if ($kind === 'post' && count($others) === 1) {
        delete_post_meta((int) reset($others), MODE_META);
    }

    /**
     * An item left its translation group.
     *
     * @param int    $id
     * @param string $kind `post` or `term`.
     */
    do_action('wp_go_languages/unlinked', $id, $kind);
}

/**
 * Remove a group left with one item, or none.
 */
function prune_group(int|string $group, string $kind = 'post'): void
{
    $members = group_members($group, $kind);

    if (count($members) > 1) {
        return;
    }

    foreach ($members as $member) {
        // alone, there's nothing to lead
        delete_metadata($kind, $member, CHOSEN_META);

        if ($kind === 'term') {
            delete_term_meta($member, TERM_GROUP_META);
            delete_term_meta($member, SOURCE_META);
        } else {
            wp_delete_object_term_relationships($member, GROUP_TAXONOMY);
            delete_post_meta($member, SOURCE_META);
            // alone, an original: no mode
            delete_post_meta($member, MODE_META);
        }
    }

    if ($kind === 'post') {
        wp_delete_term((int) $group, GROUP_TAXONOMY);
    }

    forget_translations();
}

/**
 * A post being deleted leaves its group: the group goes once one is left. An
 * original's translations get a new original first
 * (replace_deleted_original(), at an earlier priority of the same hook).
 */
function forget_deleted_post(int $post_id): void
{
    if (!is_translatable($post_id)) {
        return;
    }

    $group = group_of($post_id);

    if ($group === null) {
        return;
    }

    wp_delete_object_term_relationships($post_id, GROUP_TAXONOMY);
    forget_translations();
    prune_group($group);
}

/**
 * A new post of a translated type, with no language yet, gets one: the one
 * it is being made in (create_translation()), the admin's filter, or the
 * current one.
 */
function assign_language(int $post_id, \WP_Post $post): void
{
    if ($post->post_type === 'revision' || !is_translated_post_type($post->post_type) || language_of($post_id) !== null) {
        return;
    }

    $code = State::$inserting ?? State::$filter ?? current();

    set_language($post_id, is_language($code) ? $code : default_language());
}

/**
 * A new term of a translated taxonomy gets a language, as a post does: the
 * one queries keep to (the admin's choice, the post edited, the request's),
 * the default with **All languages**. With none, every get_terms() that
 * keeps to a language would leave it out.
 */
function assign_term_language(int $term_id, int $tt_id, string $taxonomy): void
{
    if (!is_translated_taxonomy($taxonomy) || language_of($term_id, 'term') !== null) {
        return;
    }

    set_language($term_id, filter_language() ?? default_language(), 'term');
}

/**
 * Hand the languages' terms out the first time: before the site has any
 * post in a language, a query for it would join on nothing.
 */
function ensure_language_terms(): void
{
    foreach (codes() as $code) {
        language_term_id($code);
    }
}
