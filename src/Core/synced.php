<?php

namespace WpGo\Languages\Core;

/**
 * Synced fields: a translation's field ticked to follow its group's original,
 * in any mode. The value is stored in the translation as any other, so nothing
 * changes as a page renders; every save of the original or of the translation
 * pulls it again (pull_data()), what it points at in the translation's language
 * (localize_value()).
 *
 * A tick is a mark, the name the value is stored under (`title`, `items_0_title`
 * for a repeater's cell):
 *
 *   - a block's are in its `metadata` (SYNCED_BLOCK), beside its ID;
 *   - a page's or a term's in their meta (SYNCED_META), never copied
 *     (own_meta_key());
 *   - an options page's in an option, by language (synced_option()).
 *
 * A link has two parts, marked apart: `cta` its page (address and target),
 * `cta#title` its label. Ticked for its page alone, the label is the
 * translation's to write.
 *
 * What may be marked goes by whether the item's rows follow the original's
 * (rows_follow()):
 *
 *   following      a fixed translation's blocks: a cell can be marked, and its
 *                  mark moves with its row (moved_marks()). A repeater as a whole
 *                  can't: its rows are the original's already.
 *   not following  an independent translation, any page's fields, a term's, an
 *                  options page: rows don't match up, so a repeater is marked
 *                  whole (pull_data() replaces its rows), its cells can't be.
 *
 * A mark that doesn't apply in the item's mode now (after a switch) is kept, and
 * does nothing until it applies again (mark_applies()).
 *
 * A group, or a clone shown as one, has a mark of its own: `media#group:<field
 * key>`. It takes precedence over its fields' marks: while it's there, all the
 * fields it holds follow, those added to it later too, as its field says what it
 * holds (expand_marks()). Their own marks are kept for when it goes.
 *
 * What a mark replaces is remembered (synced_memory()): taken away, the field goes
 * back to it, unless it was changed since (restored_values()). Not a repeater's
 * cell: its row can move, and the value with it. A cell's mark just goes.
 *
 * A post's featured image has a mark too, under its meta key (THUMBNAIL_MARK), set
 * from the editor's own panel with the post's save (set_thumbnail_link()). It's no
 * field: pull_fields() follows it itself.
 */

/** A page's or a term's marks. */
const SYNCED_META = '_wgl_synced';

/** A block's marks, in its `metadata`. */
const SYNCED_BLOCK = 'wgSynced';

/** A link's label, after its name in a mark. */
const SYNCED_LABEL = '#title';

/** Between a group's name and its field's key, in its mark. */
const SYNCED_GROUP = '#group:';

/** What a page's, a term's or a block's marks replaced (synced_memory()). */
const SYNCED_BEFORE = '_wgl_synced_before';

/** The ID a block had before its link changed, until the page is saved (`metadata`). */
const LINKED_FROM = 'wgFrom';

/** A post's featured image, among its marks. */
const THUMBNAIL_MARK = '_thumbnail_id';

/** Field types holding rows. */
const ROW_TYPES = ['repeater', 'flexible_content'];

/** The option holding a language's options page marks. */
function synced_option(string $code): string
{
    return 'wgl_synced_options_' . $code;
}

/**
 * What an item's marks replaced: `mark => flat values`, a block's under
 * `block:<its ID>`. An options page's in an option, by language.
 *
 * @return array<string,array<string,mixed>>
 */
function synced_memory(int|string $id, string $kind = 'post'): array
{
    $memory = $kind === 'option' ? get_option(synced_option((string) $id) . '_before', []) : get_metadata($kind, (int) $id, SYNCED_BEFORE, true);

    return is_array($memory) ? $memory : [];
}

/**
 * @param array<string,array<string,mixed>> $memory
 */
function set_synced_memory(int|string $id, array $memory, string $kind = 'post'): void
{
    if ($kind === 'option') {
        $memory ? update_option(synced_option((string) $id) . '_before', $memory, false) : delete_option(synced_option((string) $id) . '_before');
    } elseif ($memory) {
        update_metadata($kind, (int) $id, SYNCED_BEFORE, wp_slash($memory));
    } else {
        delete_metadata($kind, (int) $id, SYNCED_BEFORE);
    }
}

/**
 * A group, or a clone shown as one: what has a title to put a box on. A clone
 * shown as its fields, seamless, is just them.
 *
 * @param array<string,mixed> $field
 */
function is_group_field(array $field): bool
{
    $type = (string) ($field['type'] ?? '');

    return $type === 'group' || ($type === 'clone' && ($field['display'] ?? '') === 'group');
}

/**
 * The fields a group holds, at any depth, by the names they're stored under: a
 * group's and a prefixing clone's names start with theirs, another clone's don't.
 *
 * @param array<string,mixed> $group
 * @return array<string,array<string,mixed>>
 */
function group_leaves(array $group, string $name): array
{
    $own  = (string) ($group['_name'] ?? $group['name'] ?? '');
    $base = ($group['type'] ?? '') !== 'clone' || !empty($group['prefix_name'])
        ? $name . '_'
        : substr($name, 0, max(0, strlen($name) - strlen($own)));
    $found = [];

    foreach ((array) ($group['sub_fields'] ?? []) as $sub) {
        $type  = (string) ($sub['type'] ?? '');
        $child = $base . (string) ($sub['_name'] ?? $sub['name'] ?? '');

        if ($type === 'group' || $type === 'clone') {
            $found += group_leaves($sub, $child);
        } elseif (!in_array($type, ['', 'tab', 'accordion', 'message'], true)) {
            $found[$child] = $sub;
        }
    }

    return $found;
}

/**
 * Marks with each group's in place of the marks of the fields it holds, the
 * group's taking precedence as these are only added to. A field that's never
 * synced stays out. Rows that follow have their cells marked, read off `$data`.
 *
 * @param list<string>        $marks
 * @param array<string,mixed> $data  Flat: `name => value`, `_name => field key`.
 * @return list<string>
 */
function expand_marks(array $marks, array $data, bool $follow): array
{
    $out = [];

    foreach ($marks as $mark) {
        $at = strpos($mark, SYNCED_GROUP);

        if ($at === false) {
            $out[] = $mark;
            continue;
        }

        $group = block_field(substr($mark, $at + strlen(SYNCED_GROUP)));

        if (!$group || !is_group_field($group)) {
            continue;
        }

        foreach (group_leaves($group, substr($mark, 0, $at)) as $name => $field) {
            if (block_field_policy($field) !== 'ignore') {
                array_push($out, ...leaf_marks((string) $name, $field, $data, $follow));
            }
        }
    }

    return array_values(array_unique($out));
}

/**
 * The marks of one of a group's fields.
 *
 * @param array<string,mixed> $field
 * @param array<string,mixed> $data
 * @return list<string>
 */
function leaf_marks(string $name, array $field, array $data, bool $follow): array
{
    $parts = fn (string $key, array $of) => ($of['type'] ?? '') === 'link' ? [$key, $key . SYNCED_LABEL] : [$key];

    if (!in_array($field['type'] ?? '', ROW_TYPES, true)) {
        return $parts($name, $field);
    }

    if (!$follow) {
        return [$name];
    }

    $cells = [];

    foreach (array_keys($data) as $key) {
        $key  = (string) $key;
        $cell = !str_starts_with($key, '_') && $key !== $name && is_row_key($key, $name) ? block_field((string) ($data['_' . $key] ?? '')) : null;

        if ($cell && mark_applies($key, $cell, true)) {
            array_push($cells, ...$parts($key, $cell));
        }
    }

    return $cells;
}

/**
 * The marks whose value is remembered: not a repeater's cell's (the file's comment).
 *
 * @param list<string> $marks
 * @return list<string>
 */
function remembered_marks(array $marks): array
{
    return array_values(array_filter($marks, fn ($mark) => !is_cell_name(mark_name($mark))));
}

/**
 * A field's stored values, flat: a repeater's its count and cells.
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function flat_values(array $data, string $name): array
{
    return array_filter($data, fn ($key) => is_row_key((string) $key, $name), ARRAY_FILTER_USE_KEY);
}

/**
 * Values with a mark just taken away: what it replaced comes back, unless the value
 * isn't the one the mark left, as it was changed once the mark went. A link goes
 * back by the part marked.
 *
 * @param array<string,mixed> $now    All the values, flat.
 * @param array<string,mixed> $saved  This field's, as the mark left them.
 * @param array<string,mixed> $before This field's, as the mark found them.
 * @return array<string,mixed>
 */
function restored_values(array $now, string $mark, array $saved, array $before): array
{
    $name  = mark_name($mark);
    $field = block_field((string) ($now['_' . $name] ?? $saved['_' . $name] ?? $before['_' . $name] ?? ''));

    if (($field['type'] ?? '') === 'link') {
        $label = $mark !== $name;
        $part  = fn ($link) => pull_link([], $link, $label);

        if ($part($now[$name] ?? null) != $part($saved[$name] ?? null)) {
            return $now;
        }

        $now[$name] = pull_link($now[$name] ?? null, $before[$name] ?? null, $label);

        return $now;
    }

    if (flat_values($now, $name) != $saved) {
        return $now;
    }

    return $before + array_diff_key($now, $saved);
}

/**
 * A page's, a term's or the options' marks changing, before ACF writes the form's
 * values over the stored ones: what a new mark replaces is remembered, and what a
 * mark gone had replaced is put back once they're written (restore_fields()).
 *
 * @param list<string> $old
 * @param list<string> $new
 */
function remember_fields(int|string $id, string $kind, array $old, array $new): void
{
    $values = synced_values($id, array_merge($old, $new), $kind);
    $follow = $kind !== 'option' && rows_follow((int) $id, $kind);
    $was    = remembered_marks(expand_marks($old, $values, $follow));
    $now    = remembered_marks(expand_marks($new, $values, $follow));
    $memory = synced_memory($id, $kind);

    foreach (array_diff($now, $was) as $mark) {
        $memory[$mark] ??= flat_values($values, mark_name($mark));
    }

    foreach (array_diff($was, $now) as $mark) {
        if (isset($memory[$mark])) {
            pending_restores([$id, $kind, $mark, flat_values($values, mark_name($mark)), $memory[$mark]]);
            unset($memory[$mark]);
        }
    }

    set_synced_memory($id, $memory, $kind);
}

/**
 * The fields to put back on this request: one added, or, with none, those so far,
 * which are forgotten.
 *
 * @param array<int,mixed>|null $add
 * @return list<array<int,mixed>>
 */
function pending_restores(?array $add = null): array
{
    static $pending = [];

    if ($add !== null) {
        $pending[] = $add;

        return [];
    }

    $taken   = $pending;
    $pending = [];

    return $taken;
}

/**
 * ACF has written the form's values (`acf/save_post`, 10): those whose mark went
 * have what it replaced back.
 */
function restore_fields(): void
{
    foreach (pending_restores() as [$id, $kind, $mark, $saved, $before]) {
        $now = synced_values($id, [$mark], $kind);

        write_flat_values($id, $kind, $now, restored_values($now, $mark, $saved, $before));
    }
}

/**
 * A post's blocks as it's being saved, against what's stored: what a new mark
 * replaces is remembered, as stored, as the form may have shown the original's
 * value by now; what a mark gone had replaced comes back. Those of a block no longer
 * on the page are forgotten. A block whose link changed is found by the ID it had
 * (LINKED_FROM), which goes with this save.
 */
function remember_blocks(int $id, string $content): string
{
    $stored = (string) get_post_field('post_content', $id);

    if (!str_contains($content, SYNCED_BLOCK) && !str_contains($stored, SYNCED_BLOCK) && !str_contains($content, LINKED_FROM)) {
        return $content;
    }

    $memory = synced_memory($id);
    $old    = blocks_by_id(parse_blocks($stored));
    $follow = is_fixed($id);
    $next   = array_filter($memory, fn ($key) => !str_starts_with((string) $key, 'block:'), ARRAY_FILTER_USE_KEY);

    $walk = function (array $blocks) use (&$walk, &$next, $memory, $old, $follow) {
        foreach ($blocks as $i => $block) {
            if (empty($block['blockName'])) {
                continue;
            }

            $own  = (string) ($block['attrs']['metadata'][BLOCK_ID] ?? '');
            $from = (string) ($block['attrs']['metadata'][LINKED_FROM] ?? '') ?: $own;
            unset($blocks[$i]['attrs']['metadata'][LINKED_FROM]);

            if (empty($blocks[$i]['attrs']['metadata'])) {
                unset($blocks[$i]['attrs']['metadata']);
            }

            $was  = $old[$from] ?? null;
            $data = $block['attrs']['data'] ?? null;

            if (is_array($data)) {
                $saved = is_array($was['attrs']['data'] ?? null) ? $was['attrs']['data'] : null;
                $then  = $was ? remembered_marks(expand_marks(block_marks($was), $saved ?? [], $follow)) : [];
                $now   = remembered_marks(expand_marks(block_marks($block), $data, $follow));
                $kept  = $memory['block:' . $from] ?? [];

                foreach (array_diff($then, $now) as $mark) {
                    if (isset($kept[$mark])) {
                        $data = restored_values($data, $mark, flat_values($saved ?? [], mark_name($mark)), $kept[$mark]);
                    }
                }

                foreach (array_diff($now, $then) as $mark) {
                    $kept[$mark] ??= flat_values($saved ?? $data, mark_name($mark));
                }

                $kept = array_intersect_key($kept, array_flip($now));

                if ($kept && $own !== '') {
                    $next['block:' . $own] = $kept;
                }

                $blocks[$i]['attrs']['data'] = $data;
            }

            if (!empty($block['innerBlocks'])) {
                $blocks[$i]['innerBlocks'] = $walk($block['innerBlocks']);
            }
        }

        return $blocks;
    };

    $blocks = parse_blocks($content);
    $done   = $walk($blocks);

    if ($next !== $memory) {
        set_synced_memory($id, $next);
    }

    return $done === $blocks ? $content : serialize_blocks($done);
}
/**
 * An item's marks: a post's, a term's, or a language's options (`option`, `$id`
 * its code).
 *
 * @return list<string>
 */
function synced_marks(int|string $id, string $kind = 'post'): array
{
    $marks = $kind === 'option' ? get_option(synced_option((string) $id), []) : get_metadata($kind, (int) $id, SYNCED_META, true);

    return is_array($marks) ? array_values(array_filter($marks, 'is_string')) : [];
}

/**
 * @param list<string> $marks
 */
function set_synced_marks(int|string $id, array $marks, string $kind = 'post'): void
{
    $marks = array_values(array_unique($marks));

    if ($kind === 'option') {
        $marks ? update_option(synced_option((string) $id), $marks, false) : delete_option(synced_option((string) $id));
    } elseif ($marks) {
        update_metadata($kind, (int) $id, SYNCED_META, wp_slash($marks));
    } else {
        delete_metadata($kind, (int) $id, SYNCED_META);
    }
}

/** A block's marks. @param array<string,mixed> $block @return list<string> */
function block_marks(array $block): array
{
    $marks = $block['attrs']['metadata'][SYNCED_BLOCK] ?? [];

    return is_array($marks) ? array_values(array_filter($marks, 'is_string')) : [];
}

/**
 * Whether a translation's rows follow its original's: its blocks' on a fixed
 * translation, never a page's fields.
 */
function rows_follow(int $id, string $kind = 'post', bool $block = false): bool
{
    return $block && is_fixed($id);
}

/**
 * Whether a name is a repeater's cell: `items_0_title`. As row_fields() tells.
 */
function is_cell_name(string $name): bool
{
    return (bool) preg_match('/_\d+_/', $name);
}

/**
 * Whether a field can be marked, or a mark on it does anything: see the file's
 * comment. A tab, a group, a clone hold nothing of their own.
 *
 * @param array<string,mixed>|null $field
 */
function mark_applies(string $name, ?array $field, bool $follow): bool
{
    $type = (string) ($field['type'] ?? '');

    if ($type === '') {
        return false;
    }

    if (in_array($type, ROW_TYPES, true)) {
        return !$follow && !is_cell_name($name);
    }

    if (in_array($type, CONTAINER_TYPES, true)) {
        return false;
    }

    return $follow || !is_cell_name($name);
}

/**
 * The marks that do something on these values: a name `$data` has a field for,
 * applying in this mode, a group's as the fields it holds.
 *
 * @param list<string>        $marks
 * @param array<string,mixed> $data  Flat: `name => value`, `_name => field key`.
 * @return list<string>
 */
function live_marks(array $marks, array $data, bool $follow): array
{
    return array_values(array_filter(expand_marks($marks, $data, $follow), function ($mark) use ($data, $follow) {
        $name = mark_name($mark);

        return mark_applies($name, block_field((string) ($data['_' . $name] ?? '')), $follow);
    }));
}

/** A mark's name, without its part. */
function mark_name(string $mark): string
{
    return str_ends_with($mark, SYNCED_LABEL) ? substr($mark, 0, -strlen(SYNCED_LABEL)) : $mark;
}

/**
 * Whether a value is followed, by a mark on it or on the repeater it is in.
 * `$part` `SYNCED_LABEL` for a link's label.
 *
 * @param list<string> $marks Live ones (live_marks()).
 */
function is_marked(string $name, array $marks, string $part = ''): bool
{
    if (in_array($name . $part, $marks, true)) {
        return true;
    }

    foreach ($marks as $mark) {
        if (preg_match('/^' . preg_quote($mark, '/') . '_\d+_/', $name)) {
            return true;
        }
    }

    return false;
}

/**
 * A repeater's stored names: its count, its cells, their references.
 */
function is_row_key(string $key, string $name): bool
{
    return $key === $name || $key === '_' . $name || preg_match('/^_?' . preg_quote($name, '/') . '_\d+_/', $key);
}

/**
 * A translation's values with what its marks follow brought in from its
 * original's, in its language. A mark's value the original doesn't have stays as
 * it is: there's nothing to follow. Flat values on both sides: a block's data, or
 * what synced_values() reads.
 *
 * @param array<string,mixed> $own
 * @param array<string,mixed> $source
 * @param list<string>        $marks
 * @return array<string,mixed>
 */
function pull_data(array $own, array $source, array $marks, string $code, bool $follow): array
{
    foreach ($marks as $mark) {
        $name  = mark_name($mark);
        $field = block_field((string) ($source['_' . $name] ?? ''));

        if (!array_key_exists($name, $source) || !mark_applies($name, $field, $follow)) {
            continue;
        }

        if (in_array($field['type'], ROW_TYPES, true)) {
            $own = pull_rows($own, $source, $name, $code);
        } elseif ($field['type'] === 'link') {
            $own[$name] = pull_link($own[$name] ?? null, localize_value($field, $source[$name], $code), $mark !== $name);
        } else {
            $own[$name] = localize_value($field, $source[$name], $code);
        }

        $own['_' . $name] = $source['_' . $name];
    }

    return $own;
}

/**
 * A repeater, whole: the translation's rows gone, the original's in their place.
 *
 * @param array<string,mixed> $own
 * @param array<string,mixed> $source
 * @return array<string,mixed>
 */
function pull_rows(array $own, array $source, string $name, string $code): array
{
    foreach (array_keys($own) as $key) {
        if (is_row_key((string) $key, $name)) {
            unset($own[$key]);
        }
    }

    foreach ($source as $key => $value) {
        $key = (string) $key;

        if (is_row_key($key, $name)) {
            $own[$key] = str_starts_with($key, '_') ? $value : localize_value(block_field((string) ($source['_' . $key] ?? '')), $value, $code);
        }
    }

    return $own;
}

/**
 * A link with one part followed: its label (`$label`), or its page, the address
 * and where it opens.
 */
function pull_link(mixed $own, mixed $source, bool $label): array
{
    $own    = is_array($own) ? $own : [];
    $source = is_array($source) ? $source : [];

    return $label
        ? ['title' => (string) ($source['title'] ?? '')] + $own + ['url' => '', 'target' => '']
        : ['url' => (string) ($source['url'] ?? ''), 'target' => (string) ($source['target'] ?? '')] + $own + ['title' => ''];
}

/**
 * Marks, after the original's rows moved: each cell's goes where its row went, as
 * merge_data() does its value (row_maps(), moved_key()). A cell of a row that's
 * gone loses its mark; a new row has none. Those not in a moved repeater stay.
 *
 * @param list<string>             $marks
 * @param array<string,mixed>      $original The original's values now.
 * @param array<string,mixed>|null $previous As they were.
 * @return list<string>
 */
function moved_marks(array $marks, array $original, ?array $previous): array
{
    $maps = $marks && $previous !== null ? row_maps($original, $previous) : [];

    if (!$maps) {
        return $marks;
    }

    $moved = [];

    foreach ($marks as $mark) {
        if (!array_filter(array_keys($maps), fn ($prefix) => preg_match('/^' . preg_quote($prefix, '/') . '\d+_/', $mark))) {
            $moved[] = $mark;
        }
    }

    foreach (array_keys($original) as $name) {
        $name   = (string) $name;
        $source = str_starts_with($name, '_') ? null : moved_key($name, $maps);

        foreach ($source !== null ? ['', SYNCED_LABEL] : [] as $part) {
            if (is_cell_name($name) && in_array($source . $part, $marks, true)) {
                $moved[] = $name . $part;
            }
        }
    }

    return array_values(array_unique($moved));
}

/**
 * A content's blocks with their marks followed: each from the original's block
 * with its ID. One with no such block keeps what it has.
 *
 * @param list<array<string,mixed>>         $blocks
 * @param array<string,array<string,mixed>> $originals By ID (blocks_by_id()).
 * @return list<array<string,mixed>>
 */
function pull_blocks(array $blocks, array $originals, string $code, bool $follow): array
{
    foreach ($blocks as $i => $block) {
        $marks = block_marks($block);
        $id    = (string) ($block['attrs']['metadata'][BLOCK_ID] ?? '');
        $data  = $originals[$id]['attrs']['data'] ?? null;

        if ($marks && is_array($data) && is_array($block['attrs']['data'] ?? null)) {
            $blocks[$i]['attrs']['data'] = pull_data($block['attrs']['data'], $data, expand_marks($marks, $data, $follow), $code, $follow);
        }

        if (!empty($block['innerBlocks'])) {
            $blocks[$i]['innerBlocks'] = pull_blocks($block['innerBlocks'], $originals, $code, $follow);
        }
    }

    return $blocks;
}

/**
 * A translation's content with its blocks' marks followed: the same string, as it
 * came, when that changes nothing.
 */
function pull_content(string $content, string $original, string $code, bool $follow): string
{
    if (!str_contains($content, SYNCED_BLOCK) || !has_blocks($original)) {
        return $content;
    }

    $blocks = parse_blocks($content);
    $pulled = pull_blocks($blocks, blocks_by_id(parse_blocks($original)), $code, $follow);

    return $pulled === $blocks ? $content : serialize_blocks($pulled);
}

/**
 * What a post's marks follow, as prepare_content() is writing it: `$status`, the
 * one being saved. Not while it waits for its original (held_back()).
 */
function pull_own_content(int $id, string $content, string $status): string
{
    if (!is_translation($id)) {
        return $content;
    }

    $content = remember_blocks($id, $content);

    if (!str_contains($content, SYNCED_BLOCK) || held_back($id, $status)) {
        return $content;
    }

    return pull_content($content, ensure_ids(original($id)), (string) language_of($id), is_fixed($id));
}

/**
 * The names an options page's values are stored under, in a language: `options_`
 * for the default's (default_language()), `options_en_` for another's.
 */
function option_prefix(string $code): string
{
    return $code === default_language() ? 'options_' : 'options_' . $code . '_';
}

/**
 * The stored values marks read, flat, as a block's data is: `name => value`,
 * `_name => field key`, a marked repeater's cells too.
 *
 * @param list<string> $marks
 * @return array<string,mixed>
 */
function synced_values(int|string $id, array $marks, string $kind = 'post'): array
{
    global $wpdb;

    // a group's fields, its repeaters whole: their cells are under their names
    $names = array_unique(array_map(__NAMESPACE__ . '\\mark_name', expand_marks($marks, [], false)));
    $all   = [];

    if ($kind === 'option') {
        $prefix = option_prefix((string) $id);

        foreach ($names as $name) {
            $like = $wpdb->esc_like($prefix . $name) . '%';
            $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, '\\_' . $like));

            foreach ($rows as $row) {
                $hidden = str_starts_with($row->option_name, '_');
                $key    = ($hidden ? '_' : '') . substr(ltrim($row->option_name, '_'), strlen($prefix));

                $all[$key] = maybe_unserialize($row->option_value);
            }
        }
    } else {
        foreach ((array) get_metadata($kind, (int) $id) as $key => $values) {
            $all[(string) $key] = maybe_unserialize($values[0] ?? '');
        }
    }

    return array_filter($all, function ($key) use ($names) {
        foreach ($names as $name) {
            if (is_row_key((string) $key, $name)) {
                return true;
            }
        }

        return false;
    }, ARRAY_FILTER_USE_KEY);
}

/**
 * Write one stored value, a page's, a term's or a language's options; null to
 * delete it. For an option, `$key` as synced_values() gives it.
 */
function write_synced_value(int|string $id, string $kind, string $key, mixed $value): void
{
    if ($kind !== 'option') {
        $value === null ? delete_metadata($kind, (int) $id, $key) : update_metadata($kind, (int) $id, $key, wp_slash($value));

        return;
    }

    $hidden = str_starts_with($key, '_');
    $name   = ($hidden ? '_' : '') . option_prefix((string) $id) . ($hidden ? substr($key, 1) : $key);

    $value === null ? delete_option($name) : update_option($name, $value, false);
}

/**
 * An item's page fields with their marks followed: a post's, a term's, or a
 * language's options (`$to` its code, `$from` the default's).
 *
 * @return bool Whether anything changed.
 */
function pull_fields(int|string $to, int|string $from, string $kind = 'post'): bool
{
    $marks = synced_marks($to, $kind);

    if (!$marks) {
        return false;
    }

    $own    = synced_values($to, $marks, $kind);
    $source = synced_values($from, $marks, $kind);
    $code   = $kind === 'option' ? (string) $to : (string) language_of((int) $to, $kind);
    $follow = $kind !== 'option' && rows_follow((int) $to, $kind);

    $change = write_flat_values($to, $kind, $own, pull_data($own, $source, expand_marks($marks, $source, $follow), $code, $follow));

    return ($kind === 'post' && in_array(THUMBNAIL_MARK, $marks, true) && pull_thumbnail((int) $to, (int) $from)) || $change;
}

/**
 * A linked featured image: the original's, or none.
 *
 * @return bool Whether it changed.
 */
function pull_thumbnail(int $to, int $from): bool
{
    $image = get_post_meta($from, THUMBNAIL_MARK, true);

    if ((string) get_post_meta($to, THUMBNAIL_MARK, true) === (string) $image) {
        return false;
    }

    $image === '' || $image === false ? delete_post_meta($to, THUMBNAIL_MARK) : update_post_meta($to, THUMBNAIL_MARK, $image);

    return true;
}

/**
 * A post's featured image as its save found it: remembered as each save starts
 * (`pre_post_update`), before the REST controller writes the one sent, which the
 * editor may have set to the original's as the link was ticked.
 */
function found_thumbnail(int $id, bool $remember = false): string
{
    static $found = [];

    if ($remember) {
        $found[$id] = (string) get_post_meta($id, THUMBNAIL_MARK, true);
    }

    return $found[$id] ?? (string) get_post_meta($id, THUMBNAIL_MARK, true);
}

function remember_found_thumbnail(int $id): void
{
    if (is_translation($id)) {
        found_thumbnail($id, true);
    }
}

/**
 * Link a translation's featured image, or unlink it, as the editor's save says. Linked,
 * what it had is remembered, and pull_fields() brings the original's as the save
 * ends. Unlinked, what it had comes back, unless another one was picked meanwhile.
 * `$before`: the image as the save found it, if it isn't the one stored now.
 */
function set_thumbnail_link(int $id, bool $on, ?string $before = null): void
{
    $marks = synced_marks($id);

    if (in_array(THUMBNAIL_MARK, $marks, true) === $on) {
        return;
    }

    $flat   = fn (string $image) => $image !== '' ? [THUMBNAIL_MARK => $image] : [];
    $memory = synced_memory($id);
    $now    = $flat((string) get_post_meta($id, THUMBNAIL_MARK, true));
    $found  = $before === null ? $now : $flat($before);

    if ($on) {
        $memory[THUMBNAIL_MARK] ??= $found;
        $marks[] = THUMBNAIL_MARK;
    } else {
        $marks = array_values(array_diff($marks, [THUMBNAIL_MARK]));

        if (isset($memory[THUMBNAIL_MARK])) {
            write_flat_values($id, 'post', $now, restored_values($now, THUMBNAIL_MARK, $found, $memory[THUMBNAIL_MARK]));
            unset($memory[THUMBNAIL_MARK]);
        }
    }

    set_synced_memory($id, $memory);
    set_synced_marks($id, $marks);
}

/**
 * Stored values, flat, as they're to be now: those that differ written, those gone
 * deleted.
 *
 * @param array<string,mixed> $own
 * @param array<string,mixed> $new
 * @return bool Whether anything changed.
 */
function write_flat_values(int|string $id, string $kind, array $own, array $new): bool
{
    $change = false;

    foreach ($new as $key => $value) {
        if (!array_key_exists($key, $own) || $own[$key] !== $value) {
            write_synced_value($id, $kind, (string) $key, $value);
            $change = true;
        }
    }

    foreach (array_diff_key($own, $new) as $key => $value) {
        write_synced_value($id, $kind, (string) $key, null);
        $change = true;
    }

    return $change;
}

/**
 * An item is its group's original now: it follows nothing, and what it held is
 * what a new translation copies. Directly, as ensure_ids() writes: no revision,
 * no save hooks.
 */
function forget_synced(int $id, string $kind = 'post'): void
{
    global $wpdb;

    delete_metadata($kind, $id, SYNCED_META);
    delete_metadata($kind, $id, SYNCED_BEFORE);

    if ($kind !== 'post') {
        return;
    }

    $content = (string) get_post_field('post_content', $id);

    if (!str_contains($content, SYNCED_BLOCK)) {
        return;
    }

    $strip = function (array $blocks) use (&$strip) {
        foreach ($blocks as $i => $block) {
            unset($blocks[$i]['attrs']['metadata'][SYNCED_BLOCK]);

            if (!empty($block['innerBlocks'])) {
                $blocks[$i]['innerBlocks'] = $strip($block['innerBlocks']);
            }
        }

        return $blocks;
    };

    $wpdb->update($wpdb->posts, ['post_content' => serialize_blocks($strip(parse_blocks($content)))], ['ID' => $id]);
    clean_post_cache($id);
}

/**
 * The options saved: on the default language's, every language's marks followed;
 * on a language's, its own. After ACF has written them (`acf/save_post`, 10).
 *
 * A term's own save goes through sync_term_translations(), a post's through
 * sync_translations().
 *
 * @param int|string $post_id
 */
function sync_synced_options($post_id): void
{
    if (!is_string($post_id) || !preg_match('/^options?(?:_([a-z0-9_-]+))?$/', $post_id, $match)) {
        return;
    }

    $default = default_language();
    $code    = $match[1] ?? $default;

    foreach ($code === $default ? array_keys(languages()) : [$code] as $language) {
        if ($language !== $default && is_language($language)) {
            pull_fields($language, $default, 'option');
        }
    }
}

/**
 * A page published or taken down: a synced link to it goes to its translation
 * only while that's published (localize_url()), so what holds marks in its
 * language is read again. Only as the status crosses `publish`, so a list of them
 * isn't read on every save, and a post's blocks only if its original mentions the
 * page (group_mentions()), so they aren't parsed for nothing.
 */
function resync_language(int $post_id, \WP_Post $post, bool $update, ?\WP_Post $before): void
{
    if ($before === null || wp_is_post_revision($post_id) || !is_translated_post_type($post->post_type) || ($before->post_status === 'publish') === ($post->post_status === 'publish')) {
        return;
    }

    $code = language_of($post_id);

    if ($code === null) {
        return;
    }

    $mentions = group_mentions($post_id);

    foreach (synced_posts($code) as $id) {
        if ($id !== $post_id && !held_back($id)) {
            $source = (string) get_post_field('post_content', original($id));

            if (mentions_group($source, $mentions)) {
                $content = (string) get_post_field('post_content', $id);
                $pulled  = pull_content($content, $source, $code, is_fixed($id));

                if ($pulled !== $content) {
                    write_content($id, $pulled);
                }
            }

            pull_fields($id, original($id));
        }
    }

    foreach (synced_terms($code) as $id) {
        pull_fields($id, original($id, 'term'), 'term');
    }

    if ($code !== default_language()) {
        pull_fields($code, default_language(), 'option');
    }
}

/**
 * What gives away that a text points at a post's group, as localize_value() would
 * find it: a URL has a member's slug, or its ID (`?p=`), and a picked page its ID.
 * Null when it can't be told: the front page's URL is the language's home, with
 * neither.
 *
 * @return array{ids:string, slugs:list<string>}|null
 */
function group_mentions(int $post_id): ?array
{
    $members = translations($post_id);
    $front   = get_option('show_on_front') === 'page' ? raw_front_page() : 0;

    if (!$members || ($front && in_array($post_id, translations($front), true))) {
        return null;
    }

    $slugs = [];

    foreach ($members as $id) {
        $slug = (string) get_post_field('post_name', $id);

        // as stored, as a URL may spell it, and as JSON may escape that
        if ($slug !== '') {
            $plain = rawurldecode($slug);

            array_push($slugs, $slug, $plain, trim((string) json_encode($plain), '"'));
        }
    }

    return ['ids' => implode('|', array_map('intval', $members)), 'slugs' => array_values(array_unique($slugs))];
}

/**
 * Whether a text may point at a group: never wrongly no, as what that decides is
 * whether it's read at all.
 *
 * @param array{ids:string, slugs:list<string>}|null $mentions group_mentions()'s.
 */
function mentions_group(string $text, ?array $mentions): bool
{
    if ($mentions === null) {
        return true;
    }

    foreach ($mentions['slugs'] as $slug) {
        if (stripos($text, $slug) !== false) {
            return true;
        }
    }

    return (bool) preg_match('/(?<!\d)(?:' . $mentions['ids'] . ')(?!\d)/', $text);
}

/**
 * The translated posts in a language that hold marks. A mark is only on a
 * translation, so only those of the language have their content searched, which has
 * no index.
 *
 * @return list<int>
 */
function synced_posts(string $code): array
{
    global $wpdb;

    $types = translated_post_types();
    $tt_id = language_tt_id($code);

    if (!$types || !$tt_id) {
        return [];
    }

    $in = implode(',', array_map(fn ($type) => $wpdb->prepare('%s', $type), $types));

    $ids = array_merge(
        $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->term_relationships} l ON l.object_id = p.ID AND l.term_taxonomy_id = %d
             JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
             WHERE p.post_type IN ($in) AND p.post_status NOT IN ('trash', 'auto-draft') AND p.post_content LIKE %s",
            $tt_id,
            SOURCE_META,
            '%' . $wpdb->esc_like(SYNCED_BLOCK) . '%'
        )),
        $wpdb->get_col($wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m
             JOIN {$wpdb->term_relationships} l ON l.object_id = m.post_id AND l.term_taxonomy_id = %d
             JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type IN ($in)
             WHERE m.meta_key = %s",
            $tt_id,
            SYNCED_META
        ))
    );

    $ids = array_values(array_unique(array_map('intval', $ids)));

    // their originals are read one by one from here: their own meta, all at once
    update_meta_cache('post', $ids);

    return array_values(array_filter($ids, fn ($id) => is_translation($id)));
}

/**
 * The translated terms in a language that hold marks.
 *
 * @return list<int>
 */
function synced_terms(string $code): array
{
    global $wpdb;

    $ids = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s", SYNCED_META)));

    return array_values(array_filter($ids, fn ($id) => language_of($id, 'term') === $code && is_translation($id, 'term')));
}

/**
 * Whether a field holding others holds anything that's translated: a repeater
 * that does can't follow whole, or it would take the original's words.
 *
 * @param array<string,mixed> $field
 */
function holds_text(array $field): bool
{
    $subs = (array) ($field['sub_fields'] ?? []);

    foreach ((array) ($field['layouts'] ?? []) as $layout) {
        $subs = array_merge($subs, (array) ($layout['sub_fields'] ?? []));
    }

    foreach ($subs as $sub) {
        if (in_array($sub['type'] ?? '', CONTAINER_TYPES, true) ? holds_text($sub) : block_field_policy($sub) !== 'copy') {
            return true;
        }
    }

    return false;
}

/**
 * The marks that make these values' non-text fields follow: what the rules say is
 * the same in every language (field_policy()). Not a link, which holds words.
 *
 * @param array<string,mixed> $data Flat: `name => value`, `_name => field key`.
 * @return list<string>
 */
function non_text_marks(array $data, bool $follow): array
{
    $marks = [];

    foreach (array_keys($data) as $key) {
        $key   = (string) $key;
        $field = str_starts_with($key, '_') ? null : block_field((string) ($data['_' . $key] ?? ''));

        if (!$field || !mark_applies($key, $field, $follow) || block_field_policy($field) !== 'copy') {
            continue;
        }

        if (!in_array($field['type'], ROW_TYPES, true) || !holds_text($field)) {
            $marks[] = $key;
        }
    }

    return $marks;
}

/**
 * Link a translation's non-text fields to its original: the "+" dialog's flag, and
 * what a text-only translation becomes (convert_text_only()). Its blocks' that the
 * original has, and its page's or term's fields. Adds to what's marked. Directly,
 * as ensure_ids() writes: no revision, no save hooks. With `$pull`, the values
 * follow at once, unless it waits for its original.
 *
 * @return array{blocks:int,fields:int} How many marks were added.
 */
function link_non_text(int $id, string $kind = 'post', bool $pull = true): array
{
    global $wpdb;

    $owner = original($id, $kind);
    $added = ['blocks' => 0, 'fields' => 0];

    if ($owner === $id) {
        return $added;
    }

    if ($kind === 'post') {
        $content   = (string) get_post_field('post_content', $id);
        $originals = blocks_by_id(parse_blocks((string) get_post_field('post_content', $owner)));
        $follow    = is_fixed($id);

        $walk = function (array $blocks) use (&$walk, &$added, $originals, $follow) {
            foreach ($blocks as $i => $block) {
                $data = $originals[(string) ($block['attrs']['metadata'][BLOCK_ID] ?? '')]['attrs']['data'] ?? null;

                if (is_array($data) && is_array($block['attrs']['data'] ?? null)) {
                    $marks = block_marks($block);
                    $new   = array_values(array_unique(array_merge($marks, non_text_marks($data, $follow))));

                    if ($new !== $marks) {
                        $added['blocks'] += count($new) - count($marks);
                        $blocks[$i]['attrs']['metadata'][SYNCED_BLOCK] = $new;
                    }
                }

                if (!empty($block['innerBlocks'])) {
                    $blocks[$i]['innerBlocks'] = $walk($block['innerBlocks']);
                }
            }

            return $blocks;
        };

        $blocks = parse_blocks($content);
        $done   = $walk($blocks);

        if ($done !== $blocks) {
            $content = serialize_blocks($done);
            $wpdb->update($wpdb->posts, ['post_content' => $content], ['ID' => $id]);
            clean_post_cache($id);
        }
    }

    $values = [];

    foreach ((array) get_metadata($kind, $owner) as $key => $list) {
        $values[(string) $key] = maybe_unserialize($list[0] ?? '');
    }

    $marks = synced_marks($id, $kind);
    $new   = array_filter(
        non_text_marks($values, rows_follow($id, $kind)),
        fn ($mark) => meta_policy($mark, $owner, block_field((string) ($values['_' . $mark] ?? '')), $kind) === 'copy'
    );

    // the featured image is an image too
    if ($kind === 'post' && post_type_supports((string) get_post_type($id), 'thumbnail')) {
        $new[] = THUMBNAIL_MARK;
    }

    $new = array_values(array_unique(array_merge($marks, $new)));

    if ($new !== $marks) {
        $added['fields'] = count($new) - count($marks);
        set_synced_marks($id, $new, $kind);
    }

    if ($pull && !($kind === 'post' && held_back($id))) {
        if ($kind === 'post') {
            $pulled = pull_content((string) get_post_field('post_content', $id), (string) get_post_field('post_content', $owner), (string) language_of($id), is_fixed($id));

            if ($pulled !== get_post_field('post_content', $id)) {
                $wpdb->update($wpdb->posts, ['post_content' => $pulled], ['ID' => $id]);
                clean_post_cache($id);
            }
        }

        pull_fields($id, $owner, $kind);
    }

    return $added;
}

/**
 * What a stored translation's next save would change in it, read and written
 * nowhere: each `block ID/name` or field name, for convert_text_only()'s report.
 *
 * @return list<string>
 */
function next_save_changes(int $id, string $kind = 'post'): array
{
    $owner   = original($id, $kind);
    $changes = [];

    if ($kind === 'post' && !held_back($id)) {
        $content = (string) get_post_field('post_content', $id);
        $source  = (string) get_post_field('post_content', $owner);
        $fixed   = is_fixed($id);
        $next    = $fixed ? merge($source, $content) : $content;
        $next    = pull_content($next, $source, (string) language_of($id), $fixed);
        $now     = blocks_by_id(parse_blocks($content));

        foreach (blocks_by_id(parse_blocks($next)) as $block_id => $block) {
            $a = is_array($now[$block_id]['attrs']['data'] ?? null) ? $now[$block_id]['attrs']['data'] : [];
            $b = is_array($block['attrs']['data'] ?? null) ? $block['attrs']['data'] : [];

            foreach ($a + $b as $name => $_) {
                if (!str_starts_with((string) $name, '_') && ($a[$name] ?? null) != ($b[$name] ?? null)) {
                    $changes[] = $block_id . '/' . $name;
                }
            }
        }
    }

    if ($kind !== 'post' || !held_back($id)) {
        $marks = synced_marks($id, $kind);

        if ($marks) {
            $own    = synced_values($id, $marks, $kind);
            $source = synced_values($owner, $marks, $kind);
            $follow = rows_follow($id, $kind);
            $pulled = pull_data($own, $source, expand_marks($marks, $source, $follow), (string) language_of($id, $kind), $follow);

            foreach ($own + $pulled as $key => $_) {
                if (!str_starts_with((string) $key, '_') && ($own[$key] ?? null) != ($pulled[$key] ?? null)) {
                    $changes[] = (string) $key;
                }
            }

            if ($kind === 'post' && in_array(THUMBNAIL_MARK, $marks, true) && (string) get_post_meta($id, THUMBNAIL_MARK, true) !== (string) get_post_meta($owner, THUMBNAIL_MARK, true)) {
                $changes[] = 'featured image';
            }
        }
    }

    return $changes;
}

/**
 * What convert_text_only() reports, empty.
 *
 * @return array<string,mixed>
 */
function conversion_report(): array
{
    return [
        'types' => [], 'terms' => 0, 'forms' => 0, 'originals' => 0, 'block_marks' => 0, 'field_marks' => 0, 'bytes' => 0,
        'changes' => [], 'lost_meta' => [], 'thumbnails' => 0,
    ];
}

/**
 * One post stored as text-only, the mode that was: the fixed layout, or independent
 * where its type has no blocks, with its non-text fields linked, so it goes on as it
 * did. A form is just fixed. Writes in whatever transaction it's in, and opens none.
 *
 * @param array<string,mixed> $report
 */
function convert_text_post(int $id, array &$report): void
{
    $type = (string) get_post_type($id);

    if ($type === CF7_TYPE) {
        update_post_meta($id, MODE_META, is_translation($id) ? 'blocks' : 'independent');
        $report['forms']++;

        return;
    }

    if (!is_translation($id)) {
        update_post_meta($id, MODE_META, 'independent');
        $report['originals']++;

        return;
    }

    $owner  = original($id);
    $before = strlen((string) get_post_field('post_content', $id));

    // what its text-only rules kept the same, that no mark can name
    foreach ((array) get_post_meta($owner) as $key => $values) {
        $key = (string) $key;

        if (!own_meta_key($key) && !str_starts_with((string) ($values[0] ?? ''), 'field_') && !metadata_exists('post', $owner, '_' . $key) && meta_policy($key, $owner, null, 'post') === 'copy') {
            $report['lost_meta'][$key] = ($report['lost_meta'][$key] ?? 0) + 1;
        }
    }

    if (has_post_thumbnail($owner)) {
        $report['thumbnails']++;
    }

    $to = supports_fixed_blocks($type) ? 'blocks' : 'independent';
    update_post_meta($id, MODE_META, $to);
    delete_post_meta($id, LEGACY_ROWS_META);

    $added = link_non_text($id, 'post', false);

    $report['types'][$type][$to] = ($report['types'][$type][$to] ?? 0) + 1;
    $report['block_marks'] += $added['blocks'];
    $report['field_marks'] += $added['fields'];
    $report['bytes']       += strlen((string) get_post_field('post_content', $id)) - $before;

    foreach (next_save_changes($id) as $change) {
        $report['changes'][$id][] = $change;
    }
}

/**
 * One text-only term: independent, with its non-text fields linked. As
 * convert_text_post(), it opens no transaction.
 *
 * @param array<string,mixed> $report
 */
function convert_text_term(int $id, array &$report): void
{
    update_term_meta($id, MODE_META, 'independent');
    delete_term_meta($id, LEGACY_ROWS_META);

    $added = link_non_text($id, 'term', false);

    $report['terms']++;
    $report['field_marks'] += $added['fields'];

    foreach (next_save_changes($id, 'term') as $change) {
        $report['changes']['term ' . $id][] = $change;
    }
}

/**
 * Every text-only translation converted: all at once in a transaction, rolled back
 * unless `$write`, what's said about the next save read off the converted data
 * either way. Run it again: nothing's left. Not from a test, whose own transaction
 * this would end: a test uses the two above.
 *
 * @return array<string,mixed>
 */
function convert_text_only(bool $write): array
{
    global $wpdb;

    $report = conversion_report();
    $posts  = array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'text' WHERE p.post_type <> 'revision' ORDER BY p.ID",
        MODE_META
    )));
    $terms  = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = 'text'", MODE_META)));

    $wpdb->query('START TRANSACTION');

    try {
        foreach ($posts as $id) {
            convert_text_post($id, $report);
        }

        foreach ($terms as $id) {
            convert_text_term($id, $report);
        }
    } catch (\Throwable $error) {
        $wpdb->query('ROLLBACK');
        wp_cache_flush();

        throw $error;
    }

    $wpdb->query($write ? 'COMMIT' : 'ROLLBACK');
    wp_cache_flush();

    return $report;
}

/** Field types whose value a tick can show at once, in the form: the rest, on save. */
const SYNC_LIVE_TYPES = ['text', 'textarea', 'wysiwyg', 'email', 'url', 'number', 'link'];

/**
 * Whether this screen can show a synced field's box: a post's or a term's
 * translation, or the options in another language than the default. Not a form:
 * a Contact Form 7 translation follows its original its own way (cf7.php).
 */
function sync_boxes_here(): bool
{
    if (is_options_screen()) {
        return current() !== default_language();
    }

    $term = edited_term_id();

    if ($term > 0) {
        return is_translation($term, 'term');
    }

    $post = edited_post_id();

    return $post > 0 && is_translation($post) && get_post_type($post) !== CF7_TYPE;
}

/**
 * The boxes a field being rendered gets (Admin\render_sync_boxes()), or null: not
 * on an original, a field that's hidden here, a repeater's new-row template, or
 * what mark_applies() leaves out; a block only if the original has it.
 *
 * @param array<string,mixed> $field As ACF renders it, with its `prefix`.
 * @return array{key:string, type:string, block:bool, live:bool, value:mixed, source:string, parts:list<array{mark:string, part:string, on:bool}>}|null
 */
function sync_boxes(array $field): ?array
{
    $prefix = (string) ($field['prefix'] ?? '');
    $block  = str_starts_with($prefix, 'acf-block');
    $class  = (string) ($field['wrapper']['class'] ?? '');

    if ((!$block && $prefix !== 'acf' && !str_starts_with($prefix, 'acf[')) || str_contains($prefix, 'acfcloneindex') || str_contains($class, 'wgl-copied')) {
        return null;
    }

    $key     = field_meta_key($field);
    $term    = $block ? 0 : edited_term_id();
    $options = !$block && is_options_screen();

    if ($options) {
        $kind  = 'option';
        $id    = current();
        $owner = default_language();

        if ($id === $owner) {
            return null;
        }
    } else {
        $kind = $term > 0 ? 'term' : 'post';
        $id   = $term > 0 ? $term : edited_post_id();

        if ($id <= 0 || !is_translation($id, $kind) || ($kind === 'post' && get_post_type($id) === CF7_TYPE)) {
            return null;
        }

        $owner = original($id, $kind);
    }

    if ($block) {
        $data = original_block_data((int) $owner);

        if (!$data) {
            return null;
        }

        $value  = $data[$key] ?? null;
        $marks  = sent_block()['metadata'][SYNCED_BLOCK] ?? [];
        $marks  = is_array($marks) ? $marks : [];
        $follow = rows_follow((int) $id, 'post', true);
        $policy = block_field_policy($field);
        $memory = synced_memory((int) $id)['block:' . (string) (sent_block()['metadata'][BLOCK_ID] ?? '')] ?? [];
    } else {
        $value  = $kind === 'option' ? get_option(option_prefix((string) $owner) . $key) : get_metadata($kind, (int) $owner, $key, true);
        $marks  = synced_marks($id, $kind);
        $follow = $kind !== 'option' && rows_follow((int) $id, $kind);
        $policy = meta_policy($key, $kind === 'option' ? 0 : (int) $owner, $field, $kind === 'option' ? 'post' : $kind);
        $memory = synced_memory($id, $kind);
    }

    $source = $kind === 'option' ? (string) $owner : (string) language_of((int) $owner, $kind);

    // a group: one box for what it holds, not on a row's, whose mark would have to move
    if (is_group_field($field)) {
        if (is_cell_name($key) || !group_leaves($field, $key)) {
            return null;
        }

        $mark = $key . SYNCED_GROUP . $field['key'];

        return [
            'key'    => $key,
            'type'   => 'group',
            'block'  => $block,
            'live'   => false,
            'value'  => null,
            'source' => $source,
            'parts'  => [['mark' => $mark, 'part' => 'group', 'on' => in_array($mark, $marks, true)]],
        ];
    }

    if ($policy === 'ignore' || !mark_applies($key, $field, $follow)) {
        return null;
    }

    $type  = (string) $field['type'];
    $code  = $kind === 'option' ? (string) $id : (string) language_of((int) $id, $kind);
    $parts = $type === 'link'
        ? [['mark' => $key, 'part' => 'page'], ['mark' => $key . SYNCED_LABEL, 'part' => 'label']]
        : [['mark' => $key, 'part' => 'whole']];
    $live  = in_array($type, SYNC_LIVE_TYPES, true);

    foreach ($parts as $i => $part) {
        $parts[$i]['on'] = in_array($part['mark'], $marks, true);

        // what the box's untick shows at once; what can't be shown comes back on save
        if ($live && isset($memory[$part['mark']])) {
            $parts[$i]['before'] = $memory[$part['mark']][$key] ?? null;
        }
    }

    return [
        'key'   => $key,
        'type'  => $type,
        'block' => $block,
        'live'  => $live,
        'value' => $live ? localize_value($field, $value, $code) : null,
        // the original's language: its flag is the box
        'source' => $source,
        'parts' => $parts,
    ];
}
