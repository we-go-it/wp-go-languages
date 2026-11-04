<?php

/**
 * Fixed translations, the merge's own rules (merge() and its helpers, on
 * content built here): matching by ID, else by place and name, never a
 * stale block; a field's rule (text translated, anything else and a value
 * without a pointer the original's, an emptied text stays empty); row
 * counts and rows only in the translation; core blocks and containers;
 * freeform chunks; the IDs stamped and written; the counts; the editor's
 * locks; and the saves that sync or don't.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

$rich = fn (string $text, string $id = '', array $extra = []) => wgt_acf('rich-text', ['text' => $text] + $extra, [], $id);
$data = fn (string $content, int $i = 0) => wgt_blocks($content)[$i]['attrs']['data'] ?? [];

section('Matching blocks');

// blocks saved before IDs: by place and name
$merged = Core\merge(serialize_blocks([$rich('<p>Uno</p>'), wgt_para('Due')]), serialize_blocks([$rich('<p>One</p>'), wgt_para('Two')]));
same('<p>One</p>', $data($merged)['text'], 'a block with no ID is matched by its place and name');
check('a core block so matched is the translation\'s', str_contains($merged, '<p>Two</p>'));

// a block of another name at that place is no match
$merged = Core\merge(serialize_blocks([$rich('<p>Uno</p>')]), serialize_blocks([wgt_acf('accordion', ['title' => ['text' => 'Q']])]));
same('<p>Uno</p>', $data($merged)['text'], 'a block of another name at the same place isn\'t matched: the original\'s, in its language');

// a translation block carrying one of the original's IDs isn't taken by place
$merged = Core\merge(
    serialize_blocks([$rich('<p>Uno</p>'), $rich('<p>Due</p>', 'b2')]),
    serialize_blocks([$rich('<p>Two</p>', 'b2'), $rich('<p>Spare</p>')])
);
same('<p>Uno</p>', $data($merged, 0)['text'], 'a block with no ID doesn\'t take a block another ID claims');
same('<p>Two</p>', $data($merged, 1)['text'], 'the block with the ID gets its own texts');

// two translation blocks with one ID: the first wins
$merged = Core\merge(serialize_blocks([$rich('<p>Uno</p>', 'x1')]), serialize_blocks([$rich('<p>First</p>', 'x1'), $rich('<p>Second</p>', 'x1')]));
same('<p>First</p>', $data($merged)['text'], 'two translation blocks sharing an ID: the first one counts');
same(1, count(wgt_blocks($merged)), 'and the other is dropped');

// whatever is between the blocks is the original's
$merged = Core\merge("<p>Testo libero</p>\n\n" . serialize_block($rich('<p>Uno</p>', 'f1')), "<p>Free text</p>\n\n" . serialize_block($rich('<p>One</p>', 'f1')));
check('a freeform chunk is the original\'s', str_starts_with($merged, '<p>Testo libero</p>') && !str_contains($merged, 'Free text'));

same('<p>Just text</p>', Core\merge('<p>Solo testo</p>', '<p>Just text</p>'), 'an original without blocks leaves the translation as it is');

section('An ACF block\'s values');

$hero = fn (string $title, string $tag, string $text = '<p>T</p>') => wgt_acf('hero', ['title' => ['text' => $title, 'tag' => $tag], 'text' => $text], [], 'h1');
$merged = $data(Core\merge(serialize_blocks([$hero('Titolo', 'h1')]), serialize_blocks([$hero('Title', 'h3', '')])));

same('Title', $merged['title_text'], 'a text is the translation\'s');
same('h1', $merged['title_tag'], 'a select is the original\'s');
same('', $merged['text'], 'an emptied text stays empty');

$merged = $data(Core\merge(serialize_blocks([$hero('Titolo', 'h1', '<p>Nuovo</p>')]), serialize_blocks([$hero('Title', 'h1', '')]), serialize_blocks([$hero('Titolo', 'h1', '')])));
same('', $merged['text'], 'empty here, it stays so when the original fills it');

// the saves themselves: the original's and the translation's
$empty_it = wgt_page('Vuoto', serialize_blocks([$hero('Titolo', 'h1', '')]));
$empty_en = (int) WpGoLanguages::create_translation($empty_it, 'en', 'text');
wp_update_post(wp_slash(['ID' => $empty_it, 'post_content' => serialize_blocks([$hero('Titolo', 'h1', '<p>Nuovo</p>')])]));
same('', $data((string) get_post_field('post_content', $empty_en))['text'], 'saving the original doesn\'t fill a translation\'s empty text');
wp_update_post(['ID' => $empty_en, 'post_title' => 'Empty']);
same('', $data((string) get_post_field('post_content', $empty_en))['text'], 'nor does saving the translation');


same('field_wg_heading_text', $merged['_title_text'], 'a pointer is the original\'s');

// a value with no pointer beside it: copy
$loose = $rich('<p>Uno</p>', 'l1');
$loose['attrs']['data']['loose'] = 'Sciolto';
$translated = $rich('<p>One</p>', 'l1');
$translated['attrs']['data']['loose'] = 'Loose';
same('Sciolto', $data(Core\merge(serialize_blocks([$loose]), serialize_blocks([$translated])))['loose'], 'a value without a pointer is the original\'s');

// an ACF block's settings are the original's
$aligned = $rich('<p>Uno</p>', 'a1');
$aligned['attrs']['align'] = 'wide';
$moved = $rich('<p>One</p>', 'a1');
$moved['attrs']['align'] = 'full';
same('wide', wgt_blocks(Core\merge(serialize_blocks([$aligned]), serialize_blocks([$moved])))[0]['attrs']['align'], 'an ACF block\'s alignment is the original\'s');

section('Rows');

$accordion = fn (array $titles, string $id = 'r1') => wgt_acf('accordion', [
    'title' => ['text' => 'Domande'],
    'items' => array_map(fn ($title) => ['title' => $title, 'text' => "<p>$title</p>"], $titles),
], [], $id);

$merged = $data(Core\merge(serialize_blocks([$accordion(['D1', 'D2'])]), serialize_blocks([$accordion(['Q1', 'Q2', 'Q3'])])));
same(2, $merged['items'], 'the row count is the original\'s');
check('rows only the translation has are never read', !isset($merged['items_2_title']));
same(['Q1', 'Q2'], [$merged['items_0_title'], $merged['items_1_title']], 'the rows both have keep the translation\'s texts');

$merged = $data(Core\merge(serialize_blocks([$accordion(['D1', 'D2', 'D3'])]), serialize_blocks([$accordion(['Q1'])])));
same('D2', $merged['items_1_title'], 'a row only the original has starts with its text');

$block = wgt_blocks(Core\merge(serialize_blocks([$accordion(['D1'])]), serialize_blocks([$accordion(['Q1'])])))[0];
same(Core\rows_version(wgt_blocks(serialize_blocks([$accordion(['D1'])]))[0]['attrs']['data']), $block['attrs']['metadata']['wgRows'] ?? null, 'a block with rows carries the version of the original\'s it was merged on');
check('a block without rows carries none', !isset(wgt_blocks(Core\merge(serialize_blocks([$rich('<p>Uno</p>', 'v1')]), serialize_blocks([$rich('<p>One</p>', 'v1')])))[0]['attrs']['metadata']['wgRows']));

// match_rows(): the same values first, then the closest (half or more), the same index winning a tie
same([0 => 1, 1 => 0], Core\match_rows([['t' => 'B', 'x' => 'b'], ['t' => 'A', 'x' => 'a']], [['t' => 'A', 'x' => 'a'], ['t' => 'B', 'x' => 'b']]), 'swapped rows are matched to where they were');
same([0 => 0], Core\match_rows([['t' => 'A2', 'x' => 'a']], [['t' => 'A', 'x' => 'a']]), 'a row with half its cells the same is the row it was');
same([0 => 0], Core\match_rows([['t' => 'Z', 'x' => 'z', 'y' => 'a']], [['t' => 'A', 'x' => 'a', 'y' => 'a']]), 'a row rewritten where it was is the same row');
same([0 => 0, 1 => null], Core\match_rows([['t' => 'A', 'x' => 'a'], ['t' => 'New', 'x' => 'n']], [['t' => 'A', 'x' => 'a']]), 'one with no old row at its place is new');
same([0 => null, 1 => 0], Core\match_rows([['t' => 'New', 'x' => 'n'], ['t' => 'A', 'x' => 'a']], [['t' => 'A', 'x' => 'a']]), 'and one whose place another row took');
same([0 => 1, 1 => 0], Core\match_rows([['t' => 'C', 'x' => 'same'], ['t' => 'D', 'x' => 'same']], [['t' => 'D', 'x' => 'same'], ['t' => 'C', 'x' => 'same']]), 'equal rows are found before close ones');
same([0 => 0, 1 => 1], Core\match_rows([['t' => 'P', 'x' => 'same'], ['t' => 'Q', 'x' => 'same']], [['t' => 'R', 'x' => 'same'], ['t' => 'S', 'x' => 'same']]), 'a tie goes to the same index');

section('Core blocks and containers');

$heading = '<!-- wp:heading {"level":2,"metadata":{"wgId":"t1"}} --><h2 class="wp-block-heading">Titolo</h2><!-- /wp:heading -->';
$translated_heading = '<!-- wp:heading {"level":3,"metadata":{"wgId":"t1","name":"Theirs"}} --><h3 class="wp-block-heading">Title</h3><!-- /wp:heading -->';
$block = wgt_blocks(Core\merge($heading, $translated_heading))[0];
same(3, $block['attrs']['level'], 'a core block is the translation\'s whole, its settings with its markup');
same(['wgId' => 't1'], $block['attrs']['metadata'], 'with the original\'s metadata');

$details = fn (string $summary, string $text, bool $open = false) => '<!-- wp:details {' . ($open ? '"showContent":true,' : '') . '"metadata":{"wgId":"d1"}} --><details class="wp-block-details"' . ($open ? ' open' : '') . '><summary>' . $summary . '</summary>'
    . '<!-- wp:paragraph {"metadata":{"wgId":"p2"}} --><p>' . $text . '</p><!-- /wp:paragraph --></details><!-- /wp:details -->';
$merged = Core\merge($details('Dettagli', 'Uno', true), $details('Details', 'One'));
check('a container whose settings changed takes the original\'s wrapper: its summary in the original\'s language', str_contains($merged, '<summary>Dettagli</summary>') && str_contains($merged, ' open>'));
check('its inner blocks are still the translation\'s', str_contains($merged, '<p>One</p>'));

// an inner block more in the original: one more slot than the translation's wrapper has
$two = '<!-- wp:group {"metadata":{"wgId":"g2"}} --><div class="wp-block-group">'
    . '<!-- wp:paragraph {"metadata":{"wgId":"q1"}} --><p>Uno</p><!-- /wp:paragraph -->'
    . '<!-- wp:paragraph {"metadata":{"wgId":"q2"}} --><p>Due</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$one = '<!-- wp:group {"metadata":{"wgId":"g2"}} --><section class="wp-block-group translated">'
    . '<!-- wp:paragraph {"metadata":{"wgId":"q1"}} --><p>One</p><!-- /wp:paragraph --></section><!-- /wp:group -->';
$merged = Core\merge($two, $one);
check('a container with fewer slots than the original\'s inner blocks takes the original\'s wrapper', str_contains($merged, '<div class="wp-block-group">') && !str_contains($merged, 'translated'));
check('and has every inner block, the new one in the original\'s language', str_contains($merged, '<p>One</p>') && str_contains($merged, '<p>Due</p>'));

section('The block IDs');

$stamped = Core\stamp_ids(serialize_blocks([$rich('<p>A</p>'), wgt_acf('sticky-columns', ['side' => 'left'], [$rich('<p>B</p>'), $rich('<p>C</p>')])]));
$all = [];
$tree = parse_blocks($stamped);
array_walk_recursive($tree, function ($value, $key) use (&$all) {
    if ($key === 'wgId') {
        $all[] = $value;
    }
});
same(4, count(array_unique($all)), 'every block, inner ones too, gets an ID of its own');
same($stamped, Core\stamp_ids($stamped), 'content whose blocks all have IDs comes back as it was, the same string');

$repeat = serialize_blocks([wgt_acf('sticky-columns', ['side' => 'left'], [$rich('<p>B</p>', 'same')], 'same')]);
$ids = array_map('wgt_id', [wgt_blocks(Core\stamp_ids($repeat))[0], wgt_blocks(Core\stamp_ids($repeat))[0]['innerBlocks'][0]]);
check('an ID repeated inside a block is made unique across the page, the first keeping it', $ids[0] === 'same' && $ids[1] !== 'same' && $ids[1] !== '');

// ensure_ids() writes, but as no edit: no revision, the modified date kept
$plain = wgt_page('Senza ID', '');
$bare  = serialize_blocks([$rich('<p>Vecchio</p>')]);
wgt_raw_content($plain, $bare);
$modified  = get_post_field('post_modified', $plain);
$revisions = count(wp_get_post_revisions($plain));
$written   = Core\ensure_ids($plain);
check('ensure_ids() stamps the original and writes it', $written !== $bare && wgt_content($plain) === $written);
check('with no revision and the modified date as it was', count(wp_get_post_revisions($plain)) === $revisions && get_post_field('post_modified', $plain) === $modified);

section('Modes and saves');

$it = wgt_page('Modi', serialize_blocks([$rich('<p>Uno</p>')]));
$en = WpGoLanguages::create_translation($it, 'en', 'independent');

check('set_mode() refuses an original', !Core\set_mode($it, 'text'));
check('set_mode() refuses a mode that isn\'t one', !Core\set_mode($en, 'copy'));
check('a duplicate is not fixed', !Core\is_fixed($en));
update_post_meta($it, Core\MODE_META, 'text');
check('a mode stored on an original doesn\'t make it fixed', !Core\is_fixed($it));
delete_post_meta($it, Core\MODE_META);

// a duplicate's save only stamps: its own blocks stay
$own = wgt_blocks($en);
$own[0]['attrs']['data']['text'] = '<p>Own</p>';
$own[] = wgt_para('Mine');
wgt_save($en, $own);
check('a duplicate\'s save keeps its own blocks, given IDs', count(wgt_blocks($en)) === 2 && wgt_id(wgt_blocks($en)[1]) !== '');

Core\set_mode($en, 'text');
same(1, count(wgt_blocks($en)), 'turned fixed, the block of its own is dropped');

// the original, saved as a draft: a text changed and a block added
$revisions = count(wp_get_post_revisions($en));
$list = wgt_blocks($it);
$list[0]['attrs']['data']['text'] = '<p>Uno, cambiato</p>';
$list[] = wgt_para('Nuovo');
wgt_save($it, $list, ['post_status' => 'draft']);
same('<p>Own</p>', wgt_blocks($en)[0]['attrs']['data']['text'] ?? null, 'a fixed translation keeps its text when the original\'s changes');
same(2, count(wgt_blocks($en)), 'an original saved as a draft still syncs: the new block is there');
check('and the sync\'s save added a revision of the translation', count(wp_get_post_revisions($en)) > $revisions);

// a block written in without a save, then the original trashed: no sync
$before = wgt_content($en);
wgt_raw_content($it, wgt_content($it) . serialize_block(wgt_para('Cestino')));
wp_trash_post($it);
same($before, wgt_content($en), 'a trashed original doesn\'t sync');
wp_untrash_post($it);

section('The fixed editor');

$fixed = WpGoLanguages::create_translation(wgt_page('Editor', serialize_blocks([$accordion(['D1', 'D2'])])), 'en', 'text');
$settings = fn (int $id) => WpGo\Languages\Admin\lock_fixed_editor([], new WP_Block_Editor_Context(['post' => get_post($id)]));
same('all', $settings($fixed)['templateLock'] ?? null, 'a fixed translation\'s editor is locked');
same(null, $settings($it)['templateLock'] ?? null, 'an original\'s isn\'t');

$context        = State::$context;
State::$context = 'admin';
$_GET['post']   = $fixed;

$prepare = function (string $key, string $prefix, mixed $value = null): array {
    $field           = acf_get_field($key);
    $field['prefix'] = $prefix;
    $field['value']  = $value;

    return WpGo\Languages\Admin\prepare_fixed_field($field);
};
$locked = fn (array $field) => str_contains((string) ($field['wrapper']['class'] ?? ''), 'wgl-locked');

$rows = $prepare('field_base_accordion_items', 'acf-block_1', [[], []]);
check('a block\'s repeater keeps its rows (min = max = 2)', $rows['min'] === 2 && $rows['max'] === 2 && str_contains($rows['wrapper']['class'], 'wgl-fixed-rows'));
check('a block\'s text is shown', !$locked($prepare('field_base_accordion_item_title', 'acf-block_1[field_base_accordion_items][row-0]')));
check('a block\'s select is hidden', $locked($prepare('field_wg_heading_tag', 'acf-block_1[field_base_accordion_title]')));
check('a container is left as it is', !$locked($prepare('field_base_accordion_title', 'acf-block_1')));
check('a page\'s own field is not the fixed editor\'s', !$locked($prepare('field_wg_heading_tag', 'acf')));

$_GET['post'] = $en;
Core\set_mode($en, 'independent');
check('on a duplicate nothing is hidden', !$locked($prepare('field_wg_heading_tag', 'acf-block_1')));

unset($_GET['post']);
State::$context = $context;

section('blocks_dropped()');

$group_original = '<!-- wp:group {"metadata":{"wgId":"go"}} --><div class="wp-block-group"></div><!-- /wp:group -->';
$group_dup      = '<!-- wp:group {"metadata":{"wgId":"go"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>Mine</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
same([], Core\dropped_blocks(parse_blocks($group_original), parse_blocks($group_dup)), 'a core block the original has with no inner blocks keeps the duplicate\'s');

$acf_original = serialize_blocks([$rich('<p>A</p>', 'ra')]);
$acf_dup      = serialize_blocks([wgt_acf('rich-text', ['text' => '<p>A</p>'], [wgt_para('inside')], 'ra')]);
same(['core/paragraph'], Core\dropped_blocks(parse_blocks($acf_original), parse_blocks($acf_dup)), 'an ACF block the original has with no inner blocks drops the duplicate\'s');
