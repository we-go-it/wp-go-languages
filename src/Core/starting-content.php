<?php

namespace WpGo\Languages\Core;

/**
 * What a new translation starts with: the original's content, or nothing.
 *
 *   Copied   its fields start filled in, and what points at a page of the site
 *            points at this language's: a page picked in a field, a link typed
 *            in a text, a link or URL field, a form picked. A page with no
 *            published translation yet stays as it is, and the panel warns
 *            (link_warnings()).
 *   Empty    independent: an empty page. Fixed layout: the original's blocks,
 *            every field empty, or at its default. A core block keeps its markup
 *            with no text in it.
 *
 * A form or a term always starts as a copy: a form's fields are what make it
 * work, and a term must have a name. So does a page made in the default language,
 * which becomes its group's original.
 */

/** The ACF field types holding a post's ID. */
const PICKER_TYPES = ['post_object', 'page_link', 'relationship'];

/** A link's address in HTML: an `<a>`'s own `href`, not a `data-href`. */
const LINK_IN_HTML = '/(<a\s(?:[^>]*\s)?href=)(["\'])([^"\']+)\2/i';

/**
 * Whether a translation of this type, in this language, may start empty.
 */
function can_start_empty(string $post_type, string $code): bool
{
    return $post_type !== CF7_TYPE && $code !== default_language();
}

/**
 * A URL of the site in a language: its page's published translation, keeping the
 * query and the anchor. Anything else, as it is. With `$tag`, for a page rendering
 * it: the page is filed under its target, whichever way that goes (synced-links.php).
 */
function localize_url(string $url, string $code, bool $tag = false): string
{
    $absolute = str_starts_with($url, '/') && !str_starts_with($url, '//') ? untrailingslashit((string) get_option('home')) . $url : $url;
    $parts    = preg_split('/(?=[?#])/', $absolute, 2);
    $target   = url_to_post_id((string) $parts[0]);
    $id       = $target > 0 ? published_translation($target, $code) : null;

    if ($tag) {
        tag_link_target($target, $code);
    }

    if (!$id || $id === $target) {
        return $url;
    }

    return (string) get_permalink($id) . ($parts[1] ?? '');
}

/**
 * The links typed in a text, in a language. Only one that goes to another page is
 * rewritten: anything else (an anchor, a relative address, a phone, an app's link, a
 * placeholder) is the text's own, and stays as it was written, character for character.
 */
function localize_html(string $html, string $code, bool $tag = false): string
{
    return (string) preg_replace_callback(LINK_IN_HTML, function (array $match) use ($code, $tag) {
        if (str_starts_with($match[3], '#')) {
            return $match[0];
        }

        $url   = html_entity_decode($match[3]);
        $local = localize_url($url, $code, $tag);

        return $local === $url ? $match[0] : $match[1] . $match[2] . esc_attr(esc_url_raw($local)) . $match[2];
    }, $html);
}

/**
 * A field's value in a language, by its type. A picked page goes to its
 * translation even in draft (what's rendered decides what to do with a draft); a
 * form only to a published one, as its shortcode does. With `$tag`, for a page
 * rendering it: the page is filed under what it points at (synced-links.php).
 *
 * @param array<string,mixed>|null $field
 */
function localize_value(?array $field, mixed $value, string $code, bool $tag = false): mixed
{
    $type = (string) ($field['type'] ?? '');

    if (in_array($type, PICKER_TYPES, true)) {
        $one = function ($id) use ($code, $tag) {
            if (!is_numeric($id)) {
                return $id;
            }

            if ($tag) {
                tag_link_target((int) $id, $code);
            }

            return (string) (translation((int) $id, $code) ?? $id);
        };

        return is_array($value) ? array_map($one, $value) : $one($value);
    }

    // a term picked: its translation, where its taxonomy has them
    if ($type === 'taxonomy') {
        $one = fn ($id) => is_numeric($id) ? (string) (translation((int) $id, $code, false, 'term') ?? $id) : $id;

        return is_array($value) ? array_map($one, $value) : $one($value);
    }

    if ($type === 'acf_cf7') {
        if ($tag && is_numeric($value)) {
            tag_link_target((int) $value, $code);
        }

        return is_numeric($value) ? (string) (published_translation((int) $value, $code) ?? $value) : $value;
    }

    if ($type === 'url' && is_string($value) && $value !== '') {
        return localize_url($value, $code, $tag);
    }

    if ($type === 'link' && is_array($value) && !empty($value['url'])) {
        return ['url' => localize_url((string) $value['url'], $code, $tag)] + $value;
    }

    return is_string($value) && in_array($type, ['text', 'textarea', 'wysiwyg'], true) ? localize_html($value, $code, $tag) : $value;
}

/**
 * A content copied into a language: what its blocks point at, that language's.
 */
function localize_content(string $content, string $code): string
{
    if (!has_blocks($content)) {
        return localize_html($content, $code);
    }

    $walk = function (array $blocks) use (&$walk, $code): array {
        foreach ($blocks as $i => $block) {
            if (empty($block['blockName'])) {
                continue;
            }

            if (is_array($block['attrs']['data'] ?? null)) {
                foreach ($block['attrs']['data'] as $name => $value) {
                    if (!str_starts_with((string) $name, '_')) {
                        $blocks[$i]['attrs']['data'][$name] = localize_value(block_field((string) ($block['attrs']['data']['_' . $name] ?? '')), $value, $code);
                    }
                }
            } else {
                $blocks[$i]['innerHTML']    = localize_html((string) $block['innerHTML'], $code);
                $blocks[$i]['innerContent'] = array_map(fn ($part) => is_string($part) ? localize_html($part, $code) : $part, (array) $block['innerContent']);
            }

            $blocks[$i]['innerBlocks'] = $walk((array) ($block['innerBlocks'] ?? []));
        }

        return $blocks;
    };

    return serialize_blocks($walk(parse_blocks($content)));
}

/**
 * An item's fields, copied into a language: what they point at, that language's.
 */
function localize_meta(int $id, string $code, string $kind = 'post'): void
{
    foreach ((array) get_metadata($kind, $id) as $key => $values) {
        $key = (string) $key;

        if (str_starts_with($key, '_') || !($field = acf_field_of($key, $id, $kind))) {
            continue;
        }

        $value = maybe_unserialize($values[0] ?? '');
        $local = localize_value($field, $value, $code);

        if ($local !== $value) {
            update_metadata($kind, $id, $key, wp_slash($local));
        }
    }
}

/**
 * A field's value in a translation that starts empty, or null to leave it: its
 * default.
 *
 * @param array<string,mixed> $field
 */
function empty_value(array $field): mixed
{
    return in_array($field['type'] ?? '', CONTAINER_TYPES, true) ? null : ($field['default_value'] ?? '');
}

/**
 * The original's blocks with nothing in them, for a translation starting empty:
 * ACF blocks' values as empty_value() says, their rows kept; a core block's markup
 * without its text.
 */
function empty_content(string $content): string
{
    if (!has_blocks($content)) {
        return '';
    }

    $strip = fn (string $html) => (string) preg_replace('/>[^<>]+</', '><', $html);
    $walk  = function (array $blocks) use (&$walk, $strip): array {
        foreach ($blocks as $i => $block) {
            if (empty($block['blockName'])) {
                continue;
            }

            if (is_array($block['attrs']['data'] ?? null)) {
                foreach ($block['attrs']['data'] as $name => $value) {
                    $field = str_starts_with((string) $name, '_') ? null : block_field((string) ($block['attrs']['data']['_' . $name] ?? ''));
                    $blank = $field ? empty_value($field) : null;

                    if ($blank !== null) {
                        $blocks[$i]['attrs']['data'][$name] = $blank;
                    }
                }
            } else {
                $blocks[$i]['innerHTML']    = $strip((string) $block['innerHTML']);
                $blocks[$i]['innerContent'] = array_map(fn ($part) => is_string($part) ? $strip($part) : $part, (array) $block['innerContent']);
            }

            $blocks[$i]['innerBlocks'] = $walk((array) ($block['innerBlocks'] ?? []));
        }

        return $blocks;
    };

    return serialize_blocks($walk(parse_blocks($content)));
}

/**
 * A translation's copied meta, emptied: its fields as empty_value() says, their
 * references kept so ACF still knows them; any other key gone, unless it's the
 * same in every language. The template stays: its blocks are the original's.
 */
function empty_meta(int $id): void
{
    $keep = ['_wp_page_template'];

    foreach (array_keys((array) get_post_meta($id)) as $key) {
        $key = (string) $key;

        if (in_array($key, $keep, true) || own_meta_key($key)) {
            continue;
        }

        $field = acf_field_of($key, $id);

        if ($field) {
            $blank = str_starts_with($key, '_') ? null : empty_value($field);

            if ($blank !== null) {
                update_post_meta($id, $key, wp_slash($blank));
            }
        } elseif (meta_policy($key, $id) !== 'copy') {
            delete_post_meta($id, $key);
        }
    }
}
