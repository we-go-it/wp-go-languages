<?php

namespace WpGo\Languages\Core;

/**
 * Fixed translations (`blocks`, "Fixed layout"): the original's blocks, in its order,
 * every field in them the translation's own, any of them linkable to follow the
 * original (synced.php). A form has a fixed layout too, its own way (cf7.php).
 *
 * A fixed translation keeps its whole content in post_content, as any page does
 * (so its rendering, the cache, search and Yoast are the same), but its structure
 * is the original's:
 *
 *   - every block of a translated post carries an ID of its own,
 *     `attrs.metadata.wgId` (stamp_ids()), kept through the editor in core's
 *     `metadata` attribute; a Duplicate in the editor copies it, so a repeat
 *     gets a new one;
 *   - merge() rebuilds a translation from its original: the original's
 *     blocks, in its order, each with the translation's texts where it has
 *     them. A block is matched by its ID, else (a block saved before IDs) by
 *     its place and name;
 *   - in an ACF block, every value is the translation's, even empty, the repeater
 *     row counts the original's; a core block is the translation's, whole, if
 *     matched; a core container takes the original's settings, and the markup
 *     that goes with them;
 *   - the merge runs when the original is saved, for each of its fixed
 *     translations (sync_fixed()), and when a fixed translation is saved,
 *     on what the editor sent (prepare_content()), so the structure holds
 *     whatever the editor did. The template follows on both saves, and on a
 *     switch into the mode;
 *   - an original without blocks (the classic editor) has no structure to
 *     follow: the translation's content is left as it is.
 *
 * Repeater rows follow their texts: a fixed translation is always in step
 * with the original's previous version, row for row, so when the original
 * is saved each of its rows is matched to the row it was (the same values,
 * else the closest, row_maps()), and takes that row's translated texts. A
 * row moved keeps them; a new one starts with the original's. Without the
 * previous version (a mode switch) rows match by index, which is then right.
 * Blocks or rows the translation alone has are dropped.
 *
 * A translation's editor may have loaded before the original last changed:
 * its rows are then in step with an older version. Each ACF block with rows
 * carries the version it was merged on (`metadata.wgRows`, a hash of the
 * original block's data); on the translation's save a block whose version
 * isn't the original's current one is matched against that version, found
 * in the original's revisions (stale_blocks()). Not found: by index.
 */

const BLOCK_ID = 'wgId';

/** The original block's version a translation's block was merged on (`metadata`). */
const ROWS_VERSION = 'wgRows';

/**
 * ACF field types that hold words to translate.
 * Not a URL or an email: an address, the same in every language. A link has
 * its text, and its address beside it.
 */
const TEXT_TYPES = ['text', 'textarea', 'wysiwyg', 'link'];

/** ACF field types that only hold others: never compared. */
const CONTAINER_TYPES = ['clone', 'group', 'repeater', 'flexible_content', 'tab', 'accordion', 'message'];

/**
 * A block field's policy: by its type, as a page field's (field_policy()); a value
 * with no field is `copy`.
 *
 * @param array<string,mixed>|null $field
 */
function block_field_policy(?array $field): string
{
    return $field ? field_policy($field) : 'copy';
}

/**
 * A block's value's rule, read off its data: its own field's, through its
 * pointer (`_name`).
 *
 * @param array<string,mixed> $data
 */
function block_data_policy(array $data, string $name): string
{
    return block_field_policy(block_field((string) ($data['_' . $name] ?? '')));
}

/**
 * A field by its key, cached for the request.
 *
 * @return array<string,mixed>|null
 */
function block_field(string $key): ?array
{
    static $fields = [];

    if (!array_key_exists($key, $fields)) {
        $field        = function_exists('acf_get_field') && str_starts_with($key, 'field_') ? acf_get_field($key) : null;
        $fields[$key] = is_array($field) ? $field : null;
    }

    return $fields[$key];
}

/**
 * Give every block without an ID one, and a new one to a block repeating an
 * ID already seen: what the editor's Duplicate makes.
 *
 * @param list<array<string,mixed>> $blocks
 * @param array<string,true>        $seen
 * @return list<array<string,mixed>>
 */
function stamp_blocks(array $blocks, array &$seen = []): array
{
    foreach ($blocks as $i => $block) {
        if (empty($block['blockName'])) {
            continue;
        }

        $id = (string) ($block['attrs']['metadata'][BLOCK_ID] ?? '');

        if ($id === '' || isset($seen[$id])) {
            $id = wp_generate_uuid4();
            $blocks[$i]['attrs']['metadata'][BLOCK_ID] = $id;
            // a repeat has no counterpart in the original: nothing to follow
            unset($blocks[$i]['attrs']['metadata'][SYNCED_BLOCK]);
        }

        $seen[$id] = true;

        if (!empty($block['innerBlocks'])) {
            $blocks[$i]['innerBlocks'] = stamp_blocks($block['innerBlocks'], $seen);
        }
    }

    return $blocks;
}

/**
 * A content with every block's ID: the same content, as it came, when every
 * block had one already (so a save changes nothing it needn't).
 */
function stamp_ids(string $content): string
{
    if (!has_blocks($content)) {
        return $content;
    }

    $blocks  = parse_blocks($content);
    $stamped = stamp_blocks($blocks);

    return $stamped === $blocks ? $content : serialize_blocks($stamped);
}

/**
 * A post's content with its blocks' IDs, stamped into the database if they
 * weren't (a page saved before the plugin): what a merge reads an original
 * from, so its IDs are the same from one merge to the next. Only on a write:
 * a read stamps in memory (blocks_dropped()).
 */
function ensure_ids(int $post_id): string
{
    global $wpdb;

    $content = (string) get_post_field('post_content', $post_id);
    $stamped = stamp_ids($content);

    if ($stamped !== $content) {
        // Directly: no revision, no save hooks, nothing else changed.
        $wpdb->update($wpdb->posts, ['post_content' => $stamped], ['ID' => $post_id]);
        clean_post_cache($post_id);
    }

    return $stamped;
}

/**
 * A translation's content rebuilt on its original's: see the file's comment.
 * `$previous` is the original as it was before the save that changed it,
 * which the translation was in step with: it tells where a repeater's rows
 * moved. `$stale`, blocks by ID: the version of each the translation is in
 * step with, when it isn't the previous one (stale_blocks()).
 *
 * An original without blocks leaves the translation as it is: there is no
 * structure to follow, and its text is the translation's own.
 *
 * Every block field the translation has is kept: only the blocks, their order and
 * the rows are the original's.
 *
 * @param array<string,array<string,mixed>> $stale
 */
function merge(string $original, string $translation, ?string $previous = null, array $stale = []): string
{
    if (!has_blocks($original)) {
        return $translation;
    }

    $before = $previous !== null && has_blocks($previous) ? blocks_by_id(parse_blocks($previous)) : [];

    return serialize_blocks(merge_blocks(parse_blocks($original), parse_blocks($translation), $stale + $before));
}

/**
 * Every block with an ID, inner ones too, by its ID.
 *
 * @param list<array<string,mixed>> $blocks
 * @return array<string,array<string,mixed>>
 */
function blocks_by_id(array $blocks): array
{
    $out = [];

    foreach ($blocks as $block) {
        $id = (string) ($block['attrs']['metadata'][BLOCK_ID] ?? '');

        if ($id !== '') {
            $out[$id] ??= $block;
        }

        if (!empty($block['innerBlocks'])) {
            $out += blocks_by_id($block['innerBlocks']);
        }
    }

    return $out;
}

/**
 * @param list<array<string,mixed>>          $originals
 * @param list<array<string,mixed>>          $translated
 * @param array<string,array<string,mixed>> $before     The original's previous blocks, by ID.
 * @return list<array<string,mixed>>
 */
function merge_blocks(array $originals, array $translated, array $before = []): array
{
    $matches = match_blocks($originals, $translated);
    $out     = [];

    foreach ($originals as $i => $block) {
        $id    = (string) ($block['attrs']['metadata'][BLOCK_ID] ?? '');
        $match = $matches[$i] !== null ? $translated[$matches[$i]] : null;

        $out[] = $match ? merge_block($block, $match, $id !== '' ? ($before[$id] ?? null) : null, $before) : $block;
    }

    return $out;
}

/**
 * Which of the translation's blocks each of the original's is: `[original
 * index => translation index|null]`, null for a freeform chunk or a block
 * the translation doesn't have.
 *
 * @param list<array<string,mixed>> $originals
 * @param list<array<string,mixed>> $translated
 * @return array<int,int|null>
 */
function match_blocks(array $originals, array $translated): array
{
    // The translation's blocks by ID, and in order, for those with none.
    $by_id   = [];
    $ordered = [];

    foreach ($translated as $j => $block) {
        if (empty($block['blockName'])) {
            continue;
        }

        $id = (string) ($block['attrs']['metadata'][BLOCK_ID] ?? '');

        if ($id !== '') {
            $by_id[$id] ??= $j;
        }

        $ordered[] = $j;
    }

    // The IDs the original has: a translation block with another is stale.
    $known = [];

    foreach ($originals as $block) {
        $known[(string) ($block['attrs']['metadata'][BLOCK_ID] ?? '')] = true;
    }

    $matches  = [];
    $position = 0;

    foreach ($originals as $i => $block) {
        $matches[$i] = null;

        if (empty($block['blockName'])) {
            continue;
        }

        $id    = (string) ($block['attrs']['metadata'][BLOCK_ID] ?? '');
        $match = $id !== '' ? ($by_id[$id] ?? null) : null;

        // Before IDs: the block at the same place, if it is the same block
        // and its ID (if any) is none of the original's.
        if ($match === null && isset($ordered[$position])) {
            $candidate = $translated[$ordered[$position]];
            $other     = (string) ($candidate['attrs']['metadata'][BLOCK_ID] ?? '');

            if ($candidate['blockName'] === $block['blockName'] && ($other === '' || !isset($known[$other]))) {
                $match = $ordered[$position];
            }
        }

        $position++;
        $matches[$i] = $match;
    }

    return $matches;
}

/**
 * One block: the original's, with the translation's texts.
 *
 * @param array<string,mixed>               $original
 * @param array<string,mixed>               $translated
 * @param array<string,mixed>|null          $previous   The same block in the original's previous version.
 * @param array<string,array<string,mixed>> $before     The original's previous blocks, by ID.
 * @return array<string,mixed>
 */
function merge_block(array $original, array $translated, ?array $previous = null, array $before = []): array
{
    // An ACF block: its values field by field, and its inner blocks (a
    // container's <InnerBlocks />) matched in turn. Its own markup is the
    // original's: its inner blocks are the original's, in number and order.
    if (isset($original['attrs']['data']) && is_array($original['attrs']['data'])) {
        $merged                  = $original;
        $merged['attrs']['data'] = merge_data(
            $original['attrs']['data'],
            (array) ($translated['attrs']['data'] ?? []),
            is_array($previous['attrs']['data'] ?? null) ? $previous['attrs']['data'] : null
        );

        // The version its rows are now in step with: the original's.
        unset($merged['attrs']['metadata'][ROWS_VERSION]);

        if (row_fields($original['attrs']['data'])) {
            $merged['attrs']['metadata'][ROWS_VERSION] = rows_version($original['attrs']['data']);
        }

        // Its synced fields are the translation's choice, not the original's;
        // a repeater's cell's goes where its row went.
        unset($merged['attrs']['metadata'][SYNCED_BLOCK]);

        $marks = moved_marks(block_marks($translated), $original['attrs']['data'], is_array($previous['attrs']['data'] ?? null) ? $previous['attrs']['data'] : null);

        if ($marks) {
            $merged['attrs']['metadata'][SYNCED_BLOCK] = $marks;
        }

        if (!empty($original['innerBlocks'])) {
            $merged['innerBlocks'] = merge_blocks($original['innerBlocks'], (array) ($translated['innerBlocks'] ?? []), $before);
        }

        return $merged;
    }

    // A core container (a Group, Columns): the original's settings, its inner
    // blocks matched in turn. Its own markup must agree with its settings
    // (a color is in both): the translation's, which may hold texts (a
    // Details' summary, a Quote's citation), while its settings are the
    // original's and it has a slot per inner block; else the original's.
    if (!empty($original['innerBlocks'])) {
        $merged                = $translated;
        $merged['attrs']       = $original['attrs'];
        $merged['innerBlocks'] = merge_blocks($original['innerBlocks'], (array) ($translated['innerBlocks'] ?? []), $before);

        $settings = fn (array $block) => array_diff_key((array) ($block['attrs'] ?? []), ['metadata' => true]);
        $same     = $settings($translated) == $settings($original)
            && count(array_filter($translated['innerContent'] ?? [], 'is_null')) === count($merged['innerBlocks']);

        if (!$same) {
            $merged['innerHTML']    = $original['innerHTML'];
            $merged['innerContent'] = $original['innerContent'];
        }

        return $merged;
    }

    // A core block (a paragraph, a heading, an image): its markup is its
    // text, and agrees with its own settings, so both are the translation's.
    $merged          = $translated;
    $merged['attrs'] = array_replace((array) $translated['attrs'], ['metadata' => $original['attrs']['metadata'] ?? []]);

    return $merged;
}

/**
 * An ACF block's data: `name => value` with `_name => field key` beside,
 * flat (a repeater's cells `items_0_title`, a clone's `title_text`). Each
 * value's policy is its field's, through its `_name` pointer.
 *
 * A repeater's cell is the translation's at its row's place before the save
 * (`$previous`, row_maps()), else at the same index.
 *
 * @param array<string,mixed>      $original
 * @param array<string,mixed>      $translated
 * @param array<string,mixed>|null $previous   The original's data before the save.
 * @return array<string,mixed>
 */
function merge_data(array $original, array $translated, ?array $previous = null): array
{
    $maps = $previous !== null ? row_maps($original, $previous) : [];
    $out  = [];

    foreach ($original as $name => $value) {
        $name = (string) $name;

        if (str_starts_with($name, '_')) {
            $out[$name] = $value;

            continue;
        }

        $field  = block_field((string) ($original['_' . $name] ?? ''));
        $source = $maps ? moved_key($name, $maps) : $name;
        // the translation's own, even empty (saving the original never changes
        // it), if it has it. A new row or a new field starts with the original's
        $keep = $source !== null
            && !in_array($field['type'] ?? '', CONTAINER_TYPES, true)
            && array_key_exists($source, $translated);

        $out[$name] = $keep ? $translated[$source] : $value;
    }

    return $out;
}

/**
 * Where each row of a block's repeaters was before the save: `[prefix =>
 * [new index => old index|null]]`, the prefix the repeater's name and `_`
 * (`items_`). Only the block's own repeaters: a repeater in a row moves with
 * its row.
 *
 * A row is matched to the old row with the same values (a row moved), else
 * to the closest one, if at least half its values are the same (moved and
 * edited); else it is new: null.
 *
 * @param array<string,mixed> $original
 * @param array<string,mixed> $previous
 * @return array<string,array<int,int|null>>
 */
function row_maps(array $original, array $previous): array
{
    $maps = [];

    foreach (row_fields($original) as $name) {
        if (!array_key_exists($name, $previous)) {
            continue;
        }

        $prefix = $name . '_';
        $new    = rows_of($original, $prefix, $original[$name]);
        $old    = rows_of($previous, $prefix, $previous[$name]);

        $maps[$prefix] = match_rows($new, $old);
    }

    return $maps;
}

/**
 * A block's own repeaters and flexible contents, by name: not those in a
 * row (`items_0_links`), which move with it.
 *
 * @param array<string,mixed> $data
 * @return list<string>
 */
function row_fields(array $data): array
{
    $names = [];

    foreach (array_keys($data) as $name) {
        $name = (string) $name;

        if (str_starts_with($name, '_') || preg_match('/_\d+_/', $name)) {
            continue;
        }

        $type = (string) (block_field((string) ($data['_' . $name] ?? ''))['type'] ?? '');

        if ($type === 'repeater' || $type === 'flexible_content') {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * A short hash of an original block's data: the version a translation's
 * rows were merged on.
 *
 * @param array<string,mixed> $data
 */
function rows_version(array $data): string
{
    return substr(md5((string) wp_json_encode($data)), 0, 12);
}

/**
 * The blocks a fixed translation's editor sent that are in step with an
 * older version of the original's than the current one: an editor loaded
 * before the original's rows changed. Each older version, by ID, is looked
 * for in the original's revisions, newest first; one not found is left out
 * (its rows then match by index).
 *
 * @return array<string,array<string,mixed>>
 */
function stale_blocks(int $original_id, string $original, string $sent): array
{
    $current = blocks_by_id(parse_blocks($original));
    $wanted  = [];

    foreach (blocks_by_id(parse_blocks($sent)) as $id => $block) {
        $version = (string) ($block['attrs']['metadata'][ROWS_VERSION] ?? '');
        $data    = $current[$id]['attrs']['data'] ?? null;

        if ($version !== '' && is_array($data) && $version !== rows_version($data)) {
            $wanted[$id] = $version;
        }
    }

    $found = [];

    foreach ($wanted ? wp_get_post_revisions($original_id) : [] as $revision) {
        foreach (blocks_by_id(parse_blocks($revision->post_content)) as $id => $block) {
            $data = $block['attrs']['data'] ?? null;

            if (isset($wanted[$id]) && is_array($data) && rows_version($data) === $wanted[$id]) {
                $found[$id] = $block;
                unset($wanted[$id]);
            }
        }

        if (!$wanted) {
            break;
        }
    }

    return $found;
}

/**
 * A repeater's rows as `[index => [cell => value]]`, the cells' names after
 * the row's prefix (`items_2_title` → `title`). A flexible content's row
 * carries its layout, so rows of two layouts never match.
 *
 * @param array<string,mixed> $data
 * @param mixed               $count A repeater's row count; a flexible content's layouts.
 * @return list<array<string,mixed>>
 */
function rows_of(array $data, string $prefix, mixed $count): array
{
    $layouts = is_array($count) ? array_values($count) : [];
    $total   = is_array($count) ? count($count) : (int) $count;
    $rows    = array_fill(0, max(0, $total), []);

    foreach ($rows as $i => $row) {
        $start = $prefix . $i . '_';

        foreach ($data as $name => $value) {
            if (str_starts_with((string) $name, $start)) {
                $rows[$i][substr((string) $name, strlen($start))] = $value;
            }
        }

        if (isset($layouts[$i])) {
            $rows[$i]['acf_fc_layout'] = $layouts[$i];
        }
    }

    return $rows;
}

/**
 * Each new row's old index, or null for a new row: the same values first,
 * then the closest (half its values or more), the same index winning a tie,
 * then the row at its own place if no other took it: a row rewritten where it
 * was is the same row, and keeps its translation.
 *
 * @param list<array<string,mixed>> $new
 * @param list<array<string,mixed>> $old
 * @return array<int,int|null>
 */
function match_rows(array $new, array $old): array
{
    $map  = array_fill_keys(array_keys($new), null);
    $used = [];

    foreach ($new as $i => $row) {
        foreach ($old as $j => $candidate) {
            if (!isset($used[$j]) && $candidate == $row) {
                $map[$i]  = $j;
                $used[$j] = true;

                continue 2;
            }
        }
    }

    foreach ($new as $i => $row) {
        if ($map[$i] !== null || !$row) {
            continue;
        }

        $best  = null;
        $score = -1.0;

        foreach ($old as $j => $candidate) {
            if (isset($used[$j]) || ($row['acf_fc_layout'] ?? null) !== ($candidate['acf_fc_layout'] ?? null)) {
                continue;
            }

            $same  = count(array_filter(array_keys($row), fn ($cell) => array_key_exists($cell, $candidate) && $candidate[$cell] == $row[$cell]));
            $ratio = $same / max(count($row), count($candidate));

            // half its values or more; the closest; on a tie, the same index
            if ($ratio >= 0.5 && ($ratio > $score || ($ratio === $score && $j === $i))) {
                $best  = $j;
                $score = $ratio;
            }
        }

        if ($best !== null) {
            $map[$i]     = $best;
            $used[$best] = true;
        }
    }

    foreach ($new as $i => $row) {
        if ($map[$i] === null && $row && isset($old[$i]) && !isset($used[$i]) && ($row['acf_fc_layout'] ?? null) === ($old[$i]['acf_fc_layout'] ?? null)) {
            $map[$i]  = $i;
            $used[$i] = true;
        }
    }

    return $map;
}

/**
 * The name a cell had in the translation before the save: its row's old
 * index in place of the new one; null for a cell of a new row. Not a cell of
 * a repeater: the name as it is.
 *
 * @param array<string,array<int,int|null>> $maps
 */
function moved_key(string $name, array $maps): ?string
{
    foreach ($maps as $prefix => $map) {
        if (!str_starts_with($name, $prefix) || !preg_match('/^(\d+)_(.+)$/s', substr($name, strlen($prefix)), $match)) {
            continue;
        }

        $index = (int) $match[1];

        if (!array_key_exists($index, $map)) {
            return $name;
        }

        return $map[$index] === null ? null : $prefix . $map[$index] . '_' . $match[2];
    }

    return $name;
}

/**
 * Whether a post is a fixed translation: its blocks the original's, its
 * fields by their rules (`text`) or all its own (`blocks`).
 */
function is_fixed(int $post_id): bool
{
    return $post_id > 0 && in_array(normalize_mode((string) get_post_meta($post_id, MODE_META, true)), FIXED_MODES, true) && is_translation($post_id);
}

/**
 * Whether a published translation waits for its original: an original that
 * isn't published (a draft, pending, scheduled, private) keeps its changes from
 * a translation that is, in any save of either, until it's published. A
 * translation that isn't live follows it all along, so a group being built in
 * draft stays in step. `$status`: the translation's, as it's being saved.
 */
function held_back(int $translation, string $status = ''): bool
{
    $status = $status !== '' ? $status : (string) get_post_status($translation);

    return $status === 'publish' && get_post_status(original($translation)) !== 'publish';
}

/**
 * As a translated post is written: every block gets its ID; a fixed
 * translation's content is rebuilt on its original's, whatever was sent,
 * its rows matched to the version its editor had (stale_blocks()), unless it
 * waits for its original (held_back()).
 *
 * @param array<string,mixed> $data    Slashed, as wp_insert_post() hands it.
 * @param array<string,mixed> $postarr
 * @return array<string,mixed>
 */
function prepare_content(array $data, array $postarr): array
{
    $type = (string) ($data['post_type'] ?? '');

    if ($type === 'revision' || !is_translated_post_type($type) || !isset($data['post_content'])) {
        return $data;
    }

    $id      = (int) ($postarr['ID'] ?? 0);
    $content = wp_unslash((string) $data['post_content']);

    if (is_fixed($id) && fixed_by_blocks($type) && !held_back($id, (string) ($data['post_status'] ?? ''))) {
        $original = original($id);
        $source   = ensure_ids($original);
        $content  = merge($source, $content, null, stale_blocks($original, $source, $content));
    } else {
        $content = stamp_ids($content);
    }

    if ($id > 0 && fixed_by_blocks($type)) {
        $content = pull_own_content($id, $content, (string) ($data['post_status'] ?? ''));
    }

    $data['post_content'] = wp_slash($content);

    return $data;
}

/**
 * The original's content before its last update in this request, kept on
 * `post_updated` (remember_previous()): what its fixed translations were in
 * step with. Null when there was none (a new post, another request).
 */
function previous_content(int $post_id, ?string $set = null): ?string
{
    static $previous = [];

    if ($set !== null) {
        $previous[$post_id] = $set;
    }

    return $previous[$post_id] ?? null;
}

/**
 * Keep an original's content as it was, as it is updated: `post_updated`
 * has it, before and after.
 */
function remember_previous(int $post_id, \WP_Post $after, \WP_Post $before): void
{
    if ($before->post_type !== 'revision' && is_translated_post_type($before->post_type) && !is_translation($post_id)) {
        previous_content($post_id, $before->post_content);
    }
}

/**
 * On a save of an original: each of its fixed translations rebuilt on it.
 * Its own IDs are already there (prepare_content()).
 *
 * On a fixed translation's own save, its featured image and template put
 * back: the editor's panels for them aren't locked, and the REST controller
 * writes them after prepare_content(), so only here.
 */
function sync_fixed(int $post_id, \WP_Post $post): void
{
    static $running = false;

    if ($running || wp_is_post_revision($post_id) || in_array($post->post_status, ['auto-draft', 'trash'], true) || !is_translated_post_type($post->post_type) || !fixed_by_blocks($post->post_type)) {
        return;
    }

    if (is_translation($post_id)) {
        if (is_fixed($post_id) && !held_back($post_id)) {
            follow_original($post_id, original($post_id));
        }

        return;
    }

    $running = true;

    try {
        foreach (translations($post_id) as $id) {
            if ($id === $post_id || held_back($id)) {
                continue;
            }

            $current = (string) get_post_field('post_content', $id);

            // an independent translation follows only in its synced fields
            if (!is_fixed($id)) {
                $pulled = pull_content($current, $post->post_content, false);

                if ($pulled !== $current) {
                    write_content($id, $pulled);

                    do_action('wp_go_languages/synced', $id, $post_id);
                }

                continue;
            }

            // its rows may be in step with an older version than the last
            // one: an original that was a draft for several saves
            $merged = merge($post->post_content, $current, previous_content($post_id), stale_blocks($post_id, $post->post_content, $current));
            $merged = pull_content($merged, $post->post_content, true);

            if ($merged !== $current) {
                write_content($id, $merged);
            }

            follow_original($id, $post_id);

            do_action('wp_go_languages/synced', $id, $post_id);
        }
    } finally {
        $running = false;
    }
}

/**
 * A fixed translation's template: the original's, as its blocks are. And its
 * password: a page protected in one language isn't public in another. Its featured
 * image is its own, unless it's linked (set_thumbnail_link()).
 */
function follow_original(int $id, int $original_id): void
{
    $password = (string) get_post_field('post_password', $original_id);

    // through the save, so what listens to it (the static cache) knows
    if ((string) get_post_field('post_password', $id) !== $password) {
        wp_update_post(wp_slash(['ID' => $id, 'post_password' => $password]));
    }

    $template = get_post_meta($original_id, '_wp_page_template', true);

    if ($template === '' || $template === false) {
        delete_post_meta($id, '_wp_page_template');
    } elseif (get_post_meta($id, '_wp_page_template', true) !== $template) {
        update_post_meta($id, '_wp_page_template', $template);
    }
}

/**
 * Write a translation's content: no kses (it is what was already stored,
 * the original's and the translation's). Its wp_update_post() goes through
 * prepare_content(), which merges a fixed translation again: on content
 * already merged, the same result.
 */
function write_content(int $post_id, string $content): void
{
    $kses = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;

    if ($kses) {
        kses_remove_filters();
    }

    try {
        wp_update_post(wp_slash(['ID' => $post_id, 'post_content' => $content]));
    } finally {
        if ($kses) {
            kses_init_filters();
        }
    }
}

/**
 * The original's text of a text field on a fixed translation's screen, plain,
 * for the line under it (Admin\render_original_text()); '' when there's none
 * to show. Only the fixed modes: an independent translation's fields have no
 * counterpart to follow. A page's field is read from the original's meta, a
 * block's from the same block in the original's content.
 *
 * @param array<string,mixed> $field As ACF renders it, with its `prefix`.
 */
function original_text(array $field): string
{
    if (!is_text_field($field)) {
        return '';
    }

    $prefix  = (string) ($field['prefix'] ?? '');
    $in_page = $prefix === 'acf' || str_starts_with($prefix, 'acf[');

    // a repeater's new row, hidden: it has no original
    if ((!$in_page && !str_starts_with($prefix, 'acf-block')) || str_contains($prefix, 'acfcloneindex')) {
        return '';
    }

    $kind = 'post';
    $id   = $in_page && edited_term_id() > 0 ? 0 : edited_post_id();

    if ($id <= 0 || !is_fixed($id)) {
        return '';
    }

    $owner = original($id);
    $key   = field_meta_key($field);

    if ($in_page) {
        $value = meta_policy($key, $owner, $field, $kind) === 'translate' ? get_metadata($kind, $owner, $key, true) : '';
    } else {
        $value = original_block_data($owner)[$key] ?? '';
    }

    // a link: its text, its address beside it
    $text = is_array($value) ? ($value['title'] ?? '') : $value;

    return is_string($text) ? trim(html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8')) : '';
}

/**
 * The data of the block whose form ACF is asked for (`acf/ajax/fetch-block`
 * sends its attributes, the ID among them), as the original has it.
 *
 * @return array<string,mixed>
 */
function original_block_data(int $original_id): array
{
    static $blocks = [];

    $id = (string) (sent_block()['metadata'][BLOCK_ID] ?? '');

    if ($id === '') {
        return [];
    }

    $blocks[$original_id] ??= blocks_by_id(parse_blocks((string) get_post_field('post_content', $original_id)));

    $data = $blocks[$original_id][$id]['attrs']['data'] ?? [];

    return is_array($data) ? $data : [];
}

/**
 * The attributes of the block whose form ACF is asked for.
 *
 * @return array<string,mixed>
 */
function sent_block(): array
{
    $sent = json_decode(wp_unslash((string) ($_REQUEST['block'] ?? '')), true);

    return is_array($sent) ? $sent : [];
}

/**
 * Whether a post type has blocks: edited in the block editor (in REST, with
 * an editor, not turned off by `use_block_editor_for_post_type`). Not CF7's
 * forms.
 */
function has_block_editor(string $post_type): bool
{
    $object = get_post_type_object($post_type);

    return $post_type !== CF7_TYPE && $object && $object->show_in_rest && post_type_supports($post_type, 'editor')
        && apply_filters('use_block_editor_for_post_type', true, $post_type);
}

/**
 * Whether a post type's translations may be fixed blocks with free fields
 * (`blocks`): only a type with blocks (has_block_editor()), as there is
 * no structure to keep otherwise; a type without is an independent translation.
 */
function supports_fixed_blocks(string $post_type): bool
{
    /**
     * Whether a post type's translations may be fixed blocks with free fields.
     *
     * @param bool   $supports
     * @param string $post_type
     */
    return (bool) apply_filters('wp_go_languages/supports_fixed_blocks', has_block_editor($post_type), $post_type);
}

/**
 * Whether a type's translations can have a fixed layout: one with blocks, or a
 * form, which is fixed its own way (cf7.php).
 */
function can_be_fixed(string $post_type): bool
{
    return $post_type === CF7_TYPE || supports_fixed_blocks($post_type);
}

/**
 * Whether a type's fixed translations are its original's blocks, rebuilt on
 * them (prepare_content(), sync_fixed()): all but CF7's forms, which have
 * no blocks and follow their original their own way (cf7_follow_original()).
 */
function fixed_by_blocks(string $post_type): bool
{
    return $post_type !== CF7_TYPE;
}

/**
 * Change a translation's mode. Into the fixed layout, its content is rebuilt on its
 * original's right away, and takes its template; a form takes its original's fields
 * and mail settings. Not for a type that can't be fixed (can_be_fixed()).
 */
function set_mode(int $post_id, string $mode): bool
{
    $mode = normalize_mode($mode);
    $type = (string) get_post_type($post_id);

    if (!in_array($mode, MODES, true) || !is_translation($post_id) || ($mode === 'blocks' && !can_be_fixed($type))) {
        return false;
    }

    update_post_meta($post_id, MODE_META, $mode);

    if ($mode !== 'blocks') {
        return true;
    }

    if (!fixed_by_blocks($type)) {
        cf7_sync_rows(original($post_id), $post_id);
        cf7_follow_original($post_id, original($post_id));
    } elseif (!held_back($post_id)) {
        $current = (string) get_post_field('post_content', $post_id);
        $merged  = merge(ensure_ids(original($post_id)), $current);

        if ($merged !== $current) {
            write_content($post_id, $merged);
        }

        follow_original($post_id, original($post_id));
    }

    return true;
}

/**
 * The blocks a translation would lose turning fixed: those the merge
 * wouldn't match, at any level, each counted once with what it holds. For
 * the panel's warning, on every read of an independent translation: it writes nothing. An
 * original without IDs is stamped in memory: its new IDs match none of the
 * translation's, as those ensure_ids() writes at the switch won't, so its
 * blocks match by place either way.
 *
 * @return list<string> Their names.
 */
function blocks_dropped(int $post_id): array
{
    $original = stamp_ids((string) get_post_field('post_content', original($post_id)));

    return dropped_blocks(parse_blocks($original), parse_blocks((string) get_post_field('post_content', $post_id)));
}

/**
 * Whether two posts have the same structure: the same blocks, each matched in
 * the other, whatever their values; a form's, the same fields by name.
 */
function same_structure(int $a, int $b): bool
{
    if (get_post_type($a) === CF7_TYPE) {
        return !array_diff(cf7_field_names($a), cf7_field_names($b)) && !array_diff(cf7_field_names($b), cf7_field_names($a));
    }

    $one = parse_blocks(stamp_ids((string) get_post_field('post_content', $a)));
    $two = parse_blocks(stamp_ids((string) get_post_field('post_content', $b)));

    return !dropped_blocks($one, $two) && !dropped_blocks($two, $one);
}

/**
 * The translation's blocks merge_blocks() leaves out, as merge_block()
 * walks into them: an original's inner blocks are matched in turn; an ACF
 * block without any drops the translation's; a core block without any keeps
 * the translation's whole.
 *
 * @param list<array<string,mixed>> $originals
 * @param list<array<string,mixed>> $translated
 * @return list<string>
 */
function dropped_blocks(array $originals, array $translated): array
{
    $matches = match_blocks($originals, $translated);
    $dropped = [];

    foreach ($translated as $j => $block) {
        if (!empty($block['blockName']) && !in_array($j, $matches, true)) {
            $dropped[] = (string) $block['blockName'];
        }
    }

    foreach ($matches as $i => $j) {
        if ($j === null) {
            continue;
        }

        $inner = (array) ($translated[$j]['innerBlocks'] ?? []);

        if (!empty($originals[$i]['innerBlocks'])) {
            $dropped = array_merge($dropped, dropped_blocks($originals[$i]['innerBlocks'], $inner));
        } elseif (is_array($originals[$i]['attrs']['data'] ?? null)) {
            $dropped = array_merge($dropped, array_map(fn ($block) => (string) $block['blockName'], array_filter($inner, fn ($block) => !empty($block['blockName']))));
        }
    }

    return $dropped;
}
