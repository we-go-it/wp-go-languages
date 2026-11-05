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
 *   following      a fixed translation's blocks, a text-only translation's page
 *                  fields: a cell can be marked, and its mark moves with its row
 *                  (moved_marks()). A repeater as a whole can't: its rows are
 *                  the original's already.
 *   not following  an independent translation, a `blocks` one's page fields, an
 *                  options page: rows don't match up, so a repeater is marked
 *                  whole (pull_data() replaces its rows), its cells can't be.
 *
 * A mark that doesn't apply in the item's mode now (after a switch) is kept, and
 * does nothing until it applies again (mark_applies()).
 */

/** A page's or a term's marks. */
const SYNCED_META = '_wgl_synced';

/** A block's marks, in its `metadata`. */
const SYNCED_BLOCK = 'wgSynced';

/** A link's label, after its name in a mark. */
const SYNCED_LABEL = '#title';

/** Field types holding rows. */
const ROW_TYPES = ['repeater', 'flexible_content'];

/** The option holding a language's options page marks. */
function synced_option(string $code): string
{
    return 'wgl_synced_options_' . $code;
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
 * Whether a translation's rows follow its original's. A page's fields only on a
 * text-only translation; its blocks on either fixed mode.
 */
function rows_follow(int $id, string $kind = 'post', bool $block = false): bool
{
    return $block ? is_fixed($id) : mode_of($id, $kind) === 'text';
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
 * applying in this mode.
 *
 * @param list<string>        $marks
 * @param array<string,mixed> $data  Flat: `name => value`, `_name => field key`.
 * @return list<string>
 */
function live_marks(array $marks, array $data, bool $follow): array
{
    return array_values(array_filter($marks, function ($mark) use ($data, $follow) {
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
            $blocks[$i]['attrs']['data'] = pull_data($block['attrs']['data'], $data, $marks, $code, $follow);
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
    if (!str_contains($content, SYNCED_BLOCK) || !is_translation($id) || held_back($id, $status)) {
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

    $names = array_unique(array_map(__NAMESPACE__ . '\\mark_name', $marks));
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
    $code   = $kind === 'option' ? (string) $to : (string) language_of((int) $to, $kind);
    $follow = $kind !== 'option' && rows_follow((int) $to, $kind);
    $pulled = pull_data($own, synced_values($from, $marks, $kind), $marks, $code, $follow);
    $change = false;

    foreach ($pulled as $key => $value) {
        if (!array_key_exists($key, $own) || $own[$key] !== $value) {
            write_synced_value($to, $kind, (string) $key, $value);
            $change = true;
        }
    }

    foreach (array_diff_key($own, $pulled) as $key => $value) {
        write_synced_value($to, $kind, (string) $key, null);
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
 * isn't read on every save.
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

    foreach (synced_posts($code) as $id) {
        if ($id !== $post_id && !held_back($id)) {
            $content = (string) get_post_field('post_content', $id);
            $pulled  = pull_content($content, (string) get_post_field('post_content', original($id)), $code, is_fixed($id));

            if ($pulled !== $content) {
                write_content($id, $pulled);
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
 * The translated posts in a language that hold marks.
 *
 * @return list<int>
 */
function synced_posts(string $code): array
{
    global $wpdb;

    $ids = array_merge(
        $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft')", '%' . $wpdb->esc_like(SYNCED_BLOCK) . '%')),
        $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", SYNCED_META))
    );

    return array_values(array_filter(array_unique(array_map('intval', $ids)), fn ($id) => language_of($id) === $code && is_translation($id)));
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

    if ((!$block && $prefix !== 'acf' && !str_starts_with($prefix, 'acf[')) || str_contains($prefix, 'acfcloneindex') || str_contains($class, 'wgl-locked') || str_contains($class, 'wgl-copied')) {
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
    } else {
        $value  = $kind === 'option' ? get_option(option_prefix((string) $owner) . $key) : get_metadata($kind, (int) $owner, $key, true);
        $marks  = synced_marks($id, $kind);
        $follow = $kind !== 'option' && rows_follow((int) $id, $kind);
        $policy = meta_policy($key, $kind === 'option' ? 0 : (int) $owner, $field, $kind === 'option' ? 'post' : $kind);
    }

    if ($policy === 'ignore' || !mark_applies($key, $field, $follow)) {
        return null;
    }

    $type  = (string) $field['type'];
    $code  = $kind === 'option' ? (string) $id : (string) language_of((int) $id, $kind);
    $parts = $type === 'link'
        ? [['mark' => $key, 'part' => 'page'], ['mark' => $key . SYNCED_LABEL, 'part' => 'label']]
        : [['mark' => $key, 'part' => 'whole']];

    foreach ($parts as $i => $part) {
        $parts[$i]['on'] = in_array($part['mark'], $marks, true);
    }

    $live = in_array($type, SYNC_LIVE_TYPES, true);

    return [
        'key'   => $key,
        'type'  => $type,
        'block' => $block,
        'live'  => $live,
        'value' => $live ? localize_value($field, $value, $code) : null,
        // the original's language: its flag is the box
        'source' => $kind === 'option' ? (string) $owner : (string) language_of((int) $owner, $kind),
        'parts' => $parts,
    ];
}
