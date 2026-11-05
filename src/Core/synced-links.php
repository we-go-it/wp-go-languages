<?php

namespace WpGo\Languages\Core;

/**
 * What a synced field points at: worked out as a page renders, not stored.
 *
 * A synced value is stored as its original has it (pull_data()): an address in the
 * original's language, a picked page's ID. Which page that means in the item's
 * language depends on what's published now (localize_value()), so a marked field is
 * read through it on the site: a block's as it renders (render_synced_block()), a
 * page's, a term's and the options' as ACF reads them (render_synced_value()). Not
 * in the admin, whose forms show and save what's stored. What isn't marked is the
 * translation's own, and left alone.
 *
 * A mark that doesn't apply in the item's mode now (mark_applies()) reads its value
 * through this all the same: its own, which at worst points at a page's translation
 * rather than that page.
 *
 * A cached page holding such a field depends on the target's group: on a translation
 * published, taken down or linked later, which it may not know of yet. As it renders,
 * it's filed under `wgl-link-<target>--<language>`, the target being the page the
 * stored value names. As one of that group's pages changes, the tags of every page in
 * the group are purged, in that page's language: only pages in that language can be
 * showing a link that changes.
 *
 * Tags of their own, as `link-<id>` is dropped from a page listing that type
 * (wp_go_cache_tags_final()) and, for a page in the site's menu, is every page's.
 *
 * Only with Cache Tags on: with no such cache, a page rendered is a page current.
 */

/** Set once the stored values hold what they point at: a move to WPML's first step. */
const SYNCED_RESOLVED_OPTION = 'wp_go_languages_synced_resolved';

/**
 * Flat values with their marked fields read in a language. With `$tag`, for a page
 * rendering them.
 *
 * @param array<string,mixed> $data  Flat: `name => value`, `_name => field key`.
 * @param list<string>        $marks
 * @return array<string,mixed>
 */
function localize_marked(array $data, array $marks, string $code, bool $tag = true): array
{
    // rows by their repeater's mark, so as it was marked whichever way it follows
    $live = expanded_marks($marks);

    foreach ($data as $name => $value) {
        $name = (string) $name;

        if (!str_starts_with($name, '_') && is_marked($name, $live) && ($field = block_field((string) ($data['_' . $name] ?? '')))) {
            $data[$name] = localize_value($field, $value, $code, $tag);
        }
    }

    return $data;
}

/**
 * A block about to render, on the site (`render_block_data`): its marked fields in
 * the request's language.
 *
 * @param array<string,mixed> $block
 * @return array<string,mixed>
 */
function render_synced_block(array $block): array
{
    $marks = State::$front ? block_marks($block) : [];

    if ($marks && is_array($block['attrs']['data'] ?? null)) {
        $block['attrs']['data'] = localize_marked($block['attrs']['data'], $marks, current());
    }

    return $block;
}

/**
 * A field of a page, a term or the options as ACF reads it, on the site
 * (`acf/load_value`): if marked, in the item's language. A block's fields have a
 * post ID of their own, and came through render_synced_block().
 *
 * @param mixed               $value
 * @param int|string          $post_id
 * @param array<string,mixed> $field
 * @return mixed
 */
function render_synced_value($value, $post_id, array $field)
{
    if (!State::$front || $value === null || $value === '' || $value === []) {
        return $value;
    }

    if (is_numeric($post_id)) {
        [$id, $kind] = [(int) $post_id, 'post'];
    } elseif (preg_match('/^term_(\d+)$/', (string) $post_id, $match)) {
        [$id, $kind] = [(int) $match[1], 'term'];
    } elseif (preg_match('/^options?_([a-z0-9_-]+)$/', (string) $post_id, $match) && is_language($match[1])) {
        [$id, $kind] = [$match[1], 'option'];
    } else {
        return $value;
    }

    $marks = synced_marks($id, $kind);

    if (!$marks || !is_marked((string) ($field['name'] ?? ''), expanded_marks($marks))) {
        return $value;
    }

    $code = $kind === 'option' ? (string) $id : language_of((int) $id, $kind);

    return $code === null ? $value : localize_value($field, $value, $code, true);
}

/**
 * expand_marks() with rows not following, which reads no values, as a page reads its
 * marks: kept for the request, as it's asked of each of its fields.
 *
 * @param list<string> $marks
 * @return list<string>
 */
function expanded_marks(array $marks): array
{
    static $kept = [];

    return $kept[implode('|', $marks)] ??= expand_marks($marks, [], false);
}

/** The tag of the pages in a language holding a synced link to a page. */
function link_tag(int $target, string $code): string
{
    return 'wgl-link-' . $target . '--' . $code;
}

/**
 * The page being rendered holds a synced field pointing at this page.
 */
function tag_link_target(int $target, string $code): void
{
    if ($target > 0 && function_exists('wp_go_cache_tag')) {
        wp_go_cache_tag(link_tag($target, $code));
    }
}

/**
 * What holds a synced link into this post's group, in its language, dropped.
 */
function purge_links_to_group(int $post_id): void
{
    $code = language_of($post_id);

    if ($code === null || !function_exists('wp_go_cache_tags_purge')) {
        return;
    }

    $members = array_unique([$post_id, ...array_values(translations($post_id))]);

    wp_go_cache_tags_purge(...array_map(fn ($id) => link_tag((int) $id, $code), $members));
}

/**
 * A published post being deleted, before it leaves its group: one that wasn't is on
 * no page, and nothing links to it that a visitor can follow.
 */
function purge_links_on_delete(int $post_id): void
{
    if (get_post_status($post_id) === 'publish') {
        purge_links_to_group($post_id);
    }
}

/**
 * A post crossing `publish`: where a link to its group goes has changed.
 */
function purge_links_on_status(string $new, string $old, \WP_Post $post): void
{
    if (($new === 'publish') === ($old === 'publish') || wp_is_post_revision($post) || wp_is_post_autosave($post)) {
        return;
    }

    purge_links_to_group($post->ID);
}

/**
 * A published page's address changing, its slug or its parent, there and in every page
 * under it: where a link to its group goes has changed. Cache Tags' own `link-<id>`
 * isn't enough: a page listing that type, or one in the site's menu, drops it.
 */
function purge_links_on_address(int $post_id, \WP_Post $after, \WP_Post $before): void
{
    global $wpdb;

    if (
        $after->post_status !== 'publish' || $before->post_status !== 'publish' || !is_translated_post_type($after->post_type)
        || ($after->post_name === $before->post_name && $after->post_parent === $before->post_parent)
    ) {
        return;
    }

    $level = [$post_id];

    while ($level) {
        foreach ($level as $id) {
            purge_links_to_group($id);
        }

        $level = array_map('intval', $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN (" . implode(',', $level) . ") AND post_status = 'publish'"
        ));
    }
}

/**
 * What a conversion of the stored synced values reports, empty.
 *
 * @return array{posts:int, fields:int, terms:int, options:int, waiting:int}
 */
function synced_links_report(): array
{
    return ['posts' => 0, 'fields' => 0, 'terms' => 0, 'options' => 0, 'waiting' => 0];
}

/**
 * Every synced value as its original has it, as a site stored them, each in its
 * translation's language, before they were worked out on rendering. A pull is what
 * does that: run it again, nothing's left. A translation waiting for its original
 * (held_back()) keeps what it has, and is pulled when that's published: until then
 * its links go to the page they name. Writes in whatever transaction it's in, and
 * opens none.
 *
 * @param array<string,int> $report
 */
function convert_synced_links_now(array &$report): void
{
    global $wpdb;

    foreach (codes() as $code) {
        foreach (synced_posts($code) as $id) {
            if (held_back($id)) {
                $report['waiting']++;

                continue;
            }

            $stored = (string) get_post_field('post_content', $id);
            $pulled = pull_content($stored, ensure_ids(original($id)), is_fixed($id));

            if ($pulled !== $stored) {
                // no revision, no save hooks: this isn't an edit
                $wpdb->update($wpdb->posts, ['post_content' => $pulled], ['ID' => $id]);
                clean_post_cache($id);
                $report['posts']++;
            }

            if (pull_fields($id, original($id))) {
                $report['fields']++;
            }
        }

        foreach (synced_terms($code) as $id) {
            if (pull_fields($id, original($id, 'term'), 'term')) {
                $report['terms']++;
            }
        }

        if ($code !== default_language() && pull_fields($code, default_language(), 'option')) {
            $report['options']++;
        }
    }
}

/**
 * The stored synced values with what they point at written in them: what a site
 * leaving this plugin needs, as nothing works them out on rendering any more
 * (`wp languages migrate to-wpml`). Writes in whatever transaction it's in, and
 * opens none.
 *
 * @param array<string,int> $report
 */
function resolve_synced_links_now(array &$report): void
{
    global $wpdb;

    foreach (codes() as $code) {
        foreach (synced_posts($code) as $id) {
            $stored = (string) get_post_field('post_content', $id);
            $done   = resolve_marked_content($stored, $code);

            if ($done !== $stored) {
                $wpdb->update($wpdb->posts, ['post_content' => $done], ['ID' => $id]);
                clean_post_cache($id);
                $report['posts']++;
            }

            if (resolve_marked_fields($id, 'post', $code)) {
                $report['fields']++;
            }
        }

        foreach (synced_terms($code) as $id) {
            if (resolve_marked_fields($id, 'term', $code)) {
                $report['terms']++;
            }
        }

        if ($code !== default_language() && resolve_marked_fields($code, 'option', $code)) {
            $report['options']++;
        }
    }
}

/**
 * A content with what its blocks' marks point at written in them: the same string, as
 * it came, when that changes nothing.
 */
function resolve_marked_content(string $content, string $code): string
{
    if (!str_contains($content, SYNCED_BLOCK)) {
        return $content;
    }

    $walk = function (array $blocks) use (&$walk, $code) {
        foreach ($blocks as $i => $block) {
            $marks = block_marks($block);

            if ($marks && is_array($block['attrs']['data'] ?? null)) {
                $blocks[$i]['attrs']['data'] = localize_marked($block['attrs']['data'], $marks, $code, false);
            }

            if (!empty($block['innerBlocks'])) {
                $blocks[$i]['innerBlocks'] = $walk($block['innerBlocks']);
            }
        }

        return $blocks;
    };

    $blocks = parse_blocks($content);
    $done   = $walk($blocks);

    return $done === $blocks ? $content : serialize_blocks($done);
}

/**
 * An item's marked fields with what they point at written in them.
 *
 * @return bool Whether anything changed.
 */
function resolve_marked_fields(int|string $id, string $kind, string $code): bool
{
    $marks = synced_marks($id, $kind);

    if (!$marks) {
        return false;
    }

    $own = synced_values($id, $marks, $kind);

    return write_flat_values($id, $kind, $own, localize_marked($own, $marks, $code, false));
}

/**
 * One of the two above, all at once in a transaction, rolled back unless `$write`.
 * Not from a test, whose own transaction this would end: a test calls them itself.
 *
 * @return array<string,int>
 */
function run_synced_links(callable $step, bool $write): array
{
    global $wpdb;

    $report = synced_links_report();

    $wpdb->query('START TRANSACTION');

    try {
        $step($report);
    } catch (\Throwable $error) {
        $wpdb->query('ROLLBACK');
        wp_cache_flush();

        throw $error;
    }

    $wpdb->query($write ? 'COMMIT' : 'ROLLBACK');
    wp_cache_flush();

    return $report;
}

/**
 * The stored values converted (convert_synced_links_now()). The pages cached before
 * have no tags to find them by: they all go.
 *
 * @return array<string,int>
 */
function convert_synced_links(bool $write): array
{
    $report = run_synced_links(fn (array &$report) => convert_synced_links_now($report), $write);

    if ($write) {
        update_option(SYNCED_LINKS_OPTION, 0, true);
        delete_option(SYNCED_RESOLVED_OPTION);

        if (function_exists('wp_go_cache_tags_purge_all')) {
            wp_go_cache_tags_purge_all();
        }
    }

    return $report;
}

/**
 * The stored values resolved (resolve_synced_links_now()), and noted, as what a move
 * to WPML asks for first.
 *
 * @return array<string,int>
 */
function resolve_synced_links(bool $write): array
{
    $report = run_synced_links(fn (array &$report) => resolve_synced_links_now($report), $write);

    if ($write) {
        update_option(SYNCED_RESOLVED_OPTION, 1, false);
    }

    return $report;
}
