<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;

/**
 * A block's link to its original, in the Advanced section of its settings
 * (block-link.js): on an independent translation, a select of the original's
 * blocks of the same type, and the ID by hand; on an original, its ID to read.
 *
 * A link is the block's ID (`metadata.wgId`) being the original block's, as a
 * translation made as a copy has it from the start. So a block made by hand, or
 * on a page that started empty, can follow its original (Core/synced.php), and
 * stays linked when it's moved. Nothing is written here: the block's attribute
 * is saved with the page.
 *
 * A fixed translation has no section: its blocks are the original's already.
 */

function block_link_boot(): void
{
    add_action('enqueue_block_editor_assets', __NAMESPACE__ . '\\block_link_assets');
}

function block_link_assets(): void
{
    $data = block_link_data(Core\edited_post_id());

    if ($data === null) {
        return;
    }

    wp_enqueue_script(
        'wgl-block-link',
        Core\asset_url('Admin/block-link.js'),
        ['wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-data', 'wp-element', 'wp-hooks'],
        Core\asset_version('Admin/block-link.js'),
        true
    );

    wp_add_inline_script('wgl-block-link', 'window.wglBlockLink = ' . wp_json_encode($data + ['texts' => block_link_texts()]) . ';', 'before');
}

/**
 * What the section needs on this post's editor; null where it doesn't show: a
 * fixed translation, a form, a page with no translations.
 *
 * @return array{role:string,language?:string,blocks:list<array<string,mixed>>}|null
 */
function block_link_data(int $id): ?array
{
    if ($id <= 0 || Core\is_fixed($id) || get_post_type($id) === Core\CF7_TYPE) {
        return null;
    }

    if (Core\is_translation($id)) {
        $original = Core\original($id);
        $code     = (string) Core\language_of($original);

        return [
            'role'     => 'translation',
            'language' => (string) (Core\language($code)['name'] ?? $code),
            'blocks'   => linkable_blocks($original),
        ];
    }

    return count(Core\translations($id)) > 1 ? ['role' => 'original', 'blocks' => []] : null;
}

/**
 * The original's blocks, inner ones after their parent, numbered as they come:
 * those a block can link to, so those with an ID. One that doesn't have its ID
 * yet gets it on its page's next save.
 *
 * @return list<array{id:string,type:string,name:string,position:int,preview:string}>
 */
function linkable_blocks(int $original): array
{
    $found    = [];
    $seen     = [];
    $position = 0;

    $walk = function (array $blocks) use (&$walk, &$found, &$seen, &$position) {
        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }

            $position++;
            $id = (string) ($block['attrs']['metadata'][Core\BLOCK_ID] ?? '');

            if ($id !== '' && !isset($seen[$id])) {
                $seen[$id] = true;
                $found[]   = [
                    'id'       => $id,
                    'type'     => (string) $block['blockName'],
                    'name'     => (string) ($block['attrs']['metadata']['name'] ?? ''),
                    'position' => $position,
                    'preview'  => block_preview($block),
                ];
            }

            if (!empty($block['innerBlocks'])) {
                $walk($block['innerBlocks']);
            }
        }
    };

    $walk(parse_blocks((string) get_post_field('post_content', $original)));

    return $found;
}

/**
 * Words to tell a block from others of its type: an ACF block's first value
 * holding more than one word (not a name, a choice or an address), else its HTML's.
 *
 * @param array<string,mixed> $block
 */
function block_preview(array $block): string
{
    $clean = fn ($text) => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8')));
    $data  = $block['attrs']['data'] ?? null;
    $text  = '';

    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_string($value) && !str_starts_with((string) $key, '_') && !preg_match('#^(https?:|/|field_)#', $value) && str_contains($clean($value), ' ')) {
                $text = $clean($value);
                break;
            }
        }
    } else {
        $text = $clean($block['innerHTML'] ?? '');
    }

    return mb_strlen($text) > 50 ? rtrim(mb_substr($text, 0, 49)) . '…' : $text;
}

/**
 * The section's texts, translated here so the script needs no translation files.
 *
 * @return array<string,string>
 */
function block_link_texts(): array
{
    return [
        /* translators: %s: the original's language */
        'linkedTo'    => _x('Linked to original block (%s)', 'src/Admin/block-link.php', 'wp-go-languages'),
        'notLinked'   => _x('Not linked', 'src/Admin/block-link.php', 'wp-go-languages'),
        'taken'       => _x('already linked', 'src/Admin/block-link.php', 'wp-go-languages'),
        'noneOfType'  => _x('The original has no block of this type.', 'src/Admin/block-link.php', 'wp-go-languages'),
        'blockId'     => _x('Block ID', 'src/Admin/block-link.php', 'wp-go-languages'),
        'useId'       => _x('Use this ID', 'src/Admin/block-link.php', 'wp-go-languages'),
        'idHelp'      => _x('To link by hand: the ID shown on the block in the original.', 'src/Admin/block-link.php', 'wp-go-languages'),
        'idOriginal'  => _x('A translation\'s block with this ID follows this block.', 'src/Admin/block-link.php', 'wp-go-languages'),
        'idOnSave'    => _x('This block gets its ID when the page is saved.', 'src/Admin/block-link.php', 'wp-go-languages'),
        'badId'       => _x('Only letters, numbers, hyphens and underscores.', 'src/Admin/block-link.php', 'wp-go-languages'),
        'usedId'      => _x('Another block on this page already has this ID.', 'src/Admin/block-link.php', 'wp-go-languages'),
        'otherType'   => _x('This ID belongs to a block of another type in the original.', 'src/Admin/block-link.php', 'wp-go-languages'),
        'unknownId'   => _x('No block in the original has this ID, so this block isn\'t linked.', 'src/Admin/block-link.php', 'wp-go-languages'),
        'marksGo'     => _x('Changing the link stops this block\'s synced fields from following.', 'src/Admin/block-link.php', 'wp-go-languages'),
    ];
}
