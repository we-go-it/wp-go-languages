<?php

namespace WpGo\Languages\Core;

/**
 * The links in a post's blocks that don't lead where they should in its language:
 *
 *   unpublished  a picked page whose translation here isn't published yet
 *   missing      a picked page with no translation here: it leads to another language
 *   language     a link typed in a text or put in a link or URL field, to a page
 *                in another language
 *
 * A picked page (a CTA's) is the original's on a translation, and resolved when
 * the page renders; a typed one is left as written, so this is the only thing that
 * tells. Only blocks: what a page's own fields link to isn't read.
 *
 * @return list<array{kind:string, id:int, title:string}>
 */
function link_warnings(int $post_id): array
{
    $code = language_of($post_id);

    if ($code === null) {
        return [];
    }

    $found = [];
    $add   = function (string $kind, int $target) use (&$found) {
        $found["$kind:$target"] = ['kind' => $kind, 'id' => $target, 'title' => get_the_title($target)];
    };

    $picked = function (int $target) use ($code, $add) {
        if ($target <= 0 || !is_translatable($target) || language_of($target) === null) {
            return;
        }

        if (!translation($target, $code)) {
            $add('missing', $target);
        } elseif (!published_translation($target, $code)) {
            $add('unpublished', $target);
        }
    };

    $address = function (string $url) use ($code, $add) {
        $url    = str_starts_with($url, '/') ? untrailingslashit((string) get_option('home')) . $url : $url;
        $target = url_to_post_id($url);

        if ($target > 0 && language_of($target) !== null && language_of($target) !== $code) {
            $add('language', $target);
        }
    };

    $typed = function (string $html) use ($address) {
        if (preg_match_all('/<a\s[^>]*href=["\']([^"\'#]+)/i', $html, $matches)) {
            array_map($address, $matches[1]);
        }
    };

    $walk = function (array $blocks) use (&$walk, $picked, $typed, $address) {
        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }

            $data = (array) ($block['attrs']['data'] ?? []);

            foreach ($data as $name => $value) {
                $type = (string) (block_field((string) ($data['_' . $name] ?? ''))['type'] ?? '');

                if (in_array($type, ['post_object', 'page_link', 'relationship'], true)) {
                    foreach ((array) $value as $target) {
                        $picked((int) $target);
                    }
                } elseif ($type === 'link' && !empty($value['url']) && is_string($value['url'])) {
                    $address($value['url']);
                } elseif ($type === 'url' && is_string($value) && $value !== '') {
                    $address($value);
                } elseif (is_string($value) && !str_starts_with((string) $name, '_')) {
                    $typed($value);
                }
            }

            if (!$data) {
                $typed((string) ($block['innerHTML'] ?? ''));
            }

            $walk((array) ($block['innerBlocks'] ?? []));
        }
    };

    $walk(parse_blocks((string) get_post_field('post_content', $post_id)));

    return array_values($found);
}
