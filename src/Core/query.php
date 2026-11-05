<?php

namespace WpGo\Languages\Core;

/**
 * Queries keep to one language.
 *
 * A WP_Query that runs its filters (`suppress_filters` false: the main
 * query, a theme's own) lists the posts of translated types in the language
 * filter_language() names: the request's, or the admin's choice. Posts of
 * other types pass as they are, so a query for several types, or `any`,
 * still lists them.
 *
 * `'lang' => 'en'` in a query asks for one language, `'lang' => 'all'` for
 * every one. get_posts(), which suppresses filters by default, lists every
 * language unless it passes `suppress_filters => false`: as with WPML, which
 * cache-tags counts on (its `includes/tags.php`, "one language").
 */

/**
 * The language a query keeps to, or null for every language.
 */
function query_language(\WP_Query $query): ?string
{
    $asked = $query->get('lang');

    if ($asked === 'all') {
        return null;
    }

    if (is_string($asked) && is_language($asked)) {
        return $asked;
    }

    return filter_language();
}

/**
 * A page by its path, in the query's language: `pagename`.
 *
 * WordPress finds a page by its path with get_page_by_path(), across every
 * language, and takes the first: two pages with one slug, `/chi-siamo` and
 * `/en/chi-siamo`, would both find the same, and the language's clause then
 * leave the query empty. The page is found here instead, in the language,
 * and given to the query by ID. The main query too: it is how a page's URL
 * is resolved.
 */
function resolve_pagename(\WP_Query $query): void
{
    $path = (string) $query->get('pagename');

    if ($path === '' || $query->get('suppress_filters') || !is_translated_post_type('page')) {
        return;
    }

    $code = query_language($query);

    if ($code === null) {
        return;
    }

    $id = page_by_path($path, $code);

    if ($id) {
        $query->set('page_id', $id);
        $query->set('pagename', '');

        // parse_query() has already taken get_page_by_path()'s page as the
        // queried object: unset, get_queried_object() finds it by page_id.
        $query->queried_object    = null;
        $query->queried_object_id = null;

        // It also told the posts page by that page: told again by this one,
        // as parse_query() does for a page_id.
        $posts_page = get_option('show_on_front') === 'page' && (int) get_option('page_for_posts') === $id;

        if ($posts_page !== $query->is_posts_page) {
            $query->is_posts_page = $posts_page;
            $query->is_home       = $posts_page;
            $query->is_page       = !$posts_page;
            $query->is_singular   = $query->is_single || $query->is_page || $query->is_attachment;
        }
    }
}

/**
 * The clause: a translated post is in the language, any other passes.
 */
function posts_where(string $where, \WP_Query $query): string
{
    global $wpdb;

    $types = translated_post_types();

    if (!$types || !queries_translated_type($query, $types)) {
        return $where;
    }

    $in = implode(',', array_map(fn ($type) => $wpdb->prepare('%s', $type), $types));

    // no language to keep to, and every one shown: nothing to add, nor to join
    $hidden = hidden_from_rest();
    $code   = query_language($query);

    if ($hidden) {
        $where .= " AND ({$wpdb->posts}.post_type NOT IN ($in) OR {$wpdb->posts}.ID NOT IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (" . implode(',', $hidden) . ')))';
    }

    if ($code === null) {
        return $where;
    }

    // A query for translated types alone needs no `OR`, and the database can then start
    // from the language: a search goes through a fifth of the pages, not all of them.
    $language = $wpdb->prepare("{$wpdb->posts}.ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d)", language_tt_id($code));
    $asked    = $query->get('post_type');

    return $asked && $asked !== 'any' && !array_diff((array) $asked, $types)
        ? "$where AND $language"
        : "$where AND ({$wpdb->posts}.post_type NOT IN ($in) OR $language)";
}

/**
 * The terms of the languages a REST answer may not list for this visitor
 * (is_hidden_language()): `?lang=all` or `?lang=` a hidden one lists no page of them.
 * The site's own hidden language answers 404 (hide_language()); this is its API.
 *
 * @return list<int>
 */
function hidden_from_rest(): array
{
    if (State::$context !== 'rest' || !State::$front) {
        return [];
    }

    $hidden = array_filter(codes(), fn ($code) => !shows_language($code));

    return array_values(array_filter(array_map(__NAMESPACE__ . '\\language_tt_id', $hidden)));
}

/**
 * A translated type's REST answer for one post, in a language hidden from this visitor:
 * not there, as on the site. A list has them left out by its query (posts_where()).
 *
 * @param mixed $response
 * @return mixed
 */
function hide_rest_post($response, \WP_Post $post)
{
    return hidden_from_rest() && !shows_language(language_of($post->ID))
        ? new \WP_Error('rest_post_invalid_id', 'Invalid post ID.', ['status' => 404])
        : $response;
}

/**
 * A value worth keeping until posts or terms change, in the object cache: a request's
 * lists ask for the same counts several times, and with Redis so do the next requests.
 */
function cached_count(string $key, callable $count): mixed
{
    $key   = $key . ':' . wp_cache_get_last_changed('posts') . ':' . wp_cache_get_last_changed('terms');
    $value = wp_cache_get($key, 'wp_go_languages_counts');

    if ($value === false) {
        $value = $count();
        wp_cache_set($key, $value, 'wp_go_languages_counts', HOUR_IN_SECONDS);
    }

    return $value;
}

/**
 * wp_count_posts() in the language: the list's "All (8) | Published (8)",
 * the menu's and the dashboard's counts. WordPress counts with its own query,
 * no WP_Query, so posts_where() is not there: the counts are taken again,
 * of the posts in filter_language(), as WordPress takes them.
 */
function count_posts(object $counts, string $type, string $perm): object
{
    global $wpdb;

    $code = filter_language();

    if ($code === null || !is_translated_post_type($type)) {
        return $counts;
    }

    $object = get_post_type_object($type);
    $own    = $perm === 'readable' && is_user_logged_in() && $object && !current_user_can($object->cap->read_private_posts);
    $user   = $own ? get_current_user_id() : 0;

    $found = cached_count("posts:$type:$code:$user", function () use ($wpdb, $type, $code, $user) {
        $in_language = $wpdb->prepare(
            "ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d)",
            language_tt_id($code)
        );

        return (array) $wpdb->get_results($user
            ? $wpdb->prepare(
                "SELECT post_status, COUNT(*) AS num_posts FROM {$wpdb->posts}
                 WHERE post_type = %s AND $in_language AND (post_status != 'private' OR post_author = %d)
                 GROUP BY post_status",
                $type,
                $user
            )
            : $wpdb->prepare(
                "SELECT post_status, COUNT(*) AS num_posts FROM {$wpdb->posts}
                 WHERE post_type = %s AND $in_language
                 GROUP BY post_status",
                $type
            ), ARRAY_A);
    });

    $out = array_fill_keys(array_keys(get_object_vars($counts)), 0);

    foreach ($found as $row) {
        $out[$row['post_status']] = (int) $row['num_posts'];
    }

    return (object) $out;
}

/**
 * The current user's posts of a type, in filter_language(): the list's
 * "Mine", counted as WordPress counts it (every status the "All" view shows).
 * Null when no language is filtered.
 */
function count_user_posts_in_language(string $type, int $user_id): ?int
{
    global $wpdb;

    $code = filter_language();

    if ($code === null || !is_translated_post_type($type)) {
        return null;
    }

    $hidden = get_post_stati(['show_in_admin_all_list' => false]);
    $not_in = $hidden ? implode(',', array_map(fn ($status) => $wpdb->prepare('%s', $status), $hidden)) : "''";

    return (int) cached_count("mine:$type:$code:$user_id", fn () => (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(1) FROM {$wpdb->posts}
         WHERE post_type = %s AND post_status NOT IN ($not_in) AND post_author = %d
         AND ID IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d)",
        $type,
        $user_id,
        language_tt_id($code)
    )));
}

/**
 * Whether a query may list posts of a translated type: it names one, `any`,
 * or none (then WordPress decides: `post`, or `page` for a page's path).
 *
 * @param list<string> $types
 */
function queries_translated_type(\WP_Query $query, array $types): bool
{
    $asked = $query->get('post_type');

    if ($asked === '' || $asked === null || $asked === 'any' || $asked === []) {
        return true;
    }

    return (bool) array_intersect((array) $asked, $types);
}

/**
 * get_pages() in the language, `lang` in its arguments or filter_language().
 *
 * Its WP_Query runs its filters (WordPress 7.1), so posts_where() adds its
 * clause too: an asked `lang` is passed on to the query, or that clause would
 * keep to the current language. A tax_query keeps it right if the filters are
 * suppressed.
 *
 * @param array<string,mixed> $query_args
 * @param array<string,mixed> $parsed_args
 * @return array<string,mixed>
 */
function get_pages_args(array $query_args, array $parsed_args): array
{
    $asked = $parsed_args['lang'] ?? null;
    $asked = $asked === 'all' || (is_string($asked) && is_language($asked)) ? $asked : null;
    $code  = $asked === 'all' ? null : ($asked ?? filter_language());

    $types = (array) ($query_args['post_type'] ?? 'page');

    if (!array_intersect($types, translated_post_types())) {
        return $query_args;
    }

    if ($asked !== null) {
        $query_args['lang'] = $asked;
    }

    // with its filters running, posts_where() keeps to the language: a join on top of
    // that only makes every page of it slower to read
    if ($code === null || empty($query_args['suppress_filters'])) {
        return $query_args;
    }

    $query_args['tax_query']   = (array) ($query_args['tax_query'] ?? []);
    $query_args['tax_query'][] = [
        'taxonomy' => LANGUAGE_TAXONOMY,
        'field'    => 'slug',
        'terms'    => [$code],
    ];

    return $query_args;
}

/**
 * Terms of translated taxonomies in the language, in a get_terms() not asked
 * for every language (`'wgl_all' => true`, `'lang' => 'all'`).
 *
 * Not an object's own terms (`object_ids`: wp_get_object_terms(),
 * get_the_terms()), as WPML leaves them: an English post's terms are its
 * terms on an Italian page too, as when a link to it is built there.
 *
 * Not the whole tree core keeps of a hierarchical taxonomy either
 * (_get_term_hierarchy(), `get => all`, `id=>parent`): it's one option for every
 * language, and whichever language built it would hide the others' children.
 *
 * @param array<string,string> $clauses
 * @param list<string>         $taxonomies
 * @param array<string,mixed>  $args
 * @return array<string,string>
 */
function terms_clauses(array $clauses, array $taxonomies, array $args): array
{
    global $wpdb;

    if (!empty($args['wgl_all']) || ($args['lang'] ?? null) === 'all' || !empty($args['object_ids']) || !$taxonomies) {
        return $clauses;
    }

    if (($args['get'] ?? '') === 'all' && ($args['fields'] ?? '') === 'id=>parent') {
        return $clauses;
    }

    $translated = array_filter($taxonomies, fn ($taxonomy) => is_translated_taxonomy((string) $taxonomy));

    if (!$translated) {
        return $clauses;
    }

    $asked = $args['lang'] ?? null;
    $code  = is_string($asked) && is_language($asked) ? $asked : filter_language();

    if ($code === null) {
        return $clauses;
    }

    $in = implode(',', array_map(fn ($taxonomy) => $wpdb->prepare('%s', $taxonomy), $translated));

    $clauses['where'] .= $wpdb->prepare(
        " AND (tt.taxonomy NOT IN ($in) OR t.term_id IN (SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s))",
        TERM_LANGUAGE_META,
        $code
    );

    return $clauses;
}
