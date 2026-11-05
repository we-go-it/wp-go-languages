<?php

// @require fixtures/third-language.php

/**
 * A block's link to its original (Admin/block-link.php): what the editor's section
 * gets. An independent translation has the original's blocks to pick from, inner
 * ones too, and a block given an original's ID follows it (Core/synced.php), when
 * it was made by hand and wherever it's moved. An original has its IDs to read; a
 * fixed translation and a page alone have no section.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;

$paragraph = fn (string $text, array $metadata = []) => '<!-- wp:paragraph' . ($metadata ? ' ' . wp_json_encode(['metadata' => $metadata]) : '') . ' --><p>' . $text . '</p><!-- /wp:paragraph -->';
$ids       = fn (int $post) => array_map(fn ($block) => $block['attrs']['metadata'][Core\BLOCK_ID] ?? '', array_values(array_filter(parse_blocks((string) get_post_field('post_content', $post)), fn ($block) => !empty($block['blockName']))));


section('What the section gets');

same(null, Admin\block_link_data(0), 'no post: nothing');

$alone = wgt_page('Alone', $paragraph('On its own'));
same(null, Admin\block_link_data($alone), 'a page with no translations: nothing');

$original = wgt_page(
    'Original',
    $paragraph('The first words', ['name' => 'Intro'])
    . '<!-- wp:group --><div class="wp-block-group">' . $paragraph('Words inside') . '</div><!-- /wp:group -->'
    . $paragraph('The second words of a rather long paragraph that goes on for a while')
);

$independent = (int) WpGoLanguages::create_translation($original, 'en', 'independent', true);
$data        = Admin\block_link_data($independent);

same('translation', $data['role'] ?? '', 'an independent translation links');
check('with the original\'s language', ($data['language'] ?? '') !== '');
same(
    ['core/paragraph', 'core/group', 'core/paragraph', 'core/paragraph'],
    array_column($data['blocks'], 'type'),
    'the original\'s blocks, inner ones after their parent'
);
same([1, 2, 3, 4], array_column($data['blocks'], 'position'), 'numbered as they come');
same('Intro', $data['blocks'][0]['name'], 'with the name the list view gives it');
same('The first words', $data['blocks'][0]['preview'], 'and its words');
same('The second words of a rather long paragraph that…', $data['blocks'][3]['preview'], 'cut short');

same(['role' => 'original', 'blocks' => []], Admin\block_link_data($original), 'its original only reads IDs');

update_post_meta($independent, Core\MODE_META, 'text');
same(null, Admin\block_link_data($independent), 'a fixed translation: nothing');
update_post_meta($independent, Core\MODE_META, 'independent');


section('A block\'s words, for an ACF block');

same('Due parole', Admin\block_preview(['attrs' => ['data' => [
    'size'   => 'large',
    '_size'  => 'field_x',
    'image'  => '123',
    'link'   => 'https://example.test/a b',
    'title'  => '<b>Due</b>  parole',
    'other'  => 'Più parole ancora',
]]]), 'its first value of more than one word, plain');
same('', Admin\block_preview(['attrs' => ['data' => ['size' => 'large']]]), 'none: no words');


section('A link made by hand');

$empty  = (int) WpGoLanguages::create_translation($original, 'de', 'independent', false);
$source = $ids($original);

wp_update_post(wp_slash(['ID' => $empty, 'post_content' => $paragraph('Written here')]));
$made = $ids($empty)[0];

check('a block made by hand has its own ID', $made !== '' && !in_array($made, $source, true));

wp_update_post(wp_slash(['ID' => $empty, 'post_content' => $paragraph('Written here', [Core\BLOCK_ID => $source[0]])]));
same($source[0], $ids($empty)[0], 'the original\'s ID, picked, is kept by the save');

wp_update_post(wp_slash(['ID' => $empty, 'post_content' => $paragraph('A new block') . $paragraph('Written here', [Core\BLOCK_ID => $source[0]])]));
same($source[0], $ids($empty)[1], 'and when it\'s moved');

wp_update_post(wp_slash(['ID' => $empty, 'post_content' => $paragraph('Written here')]));
check('unlinked, the save gives it a new one', !in_array($ids($empty)[0], $source, true) && $ids($empty)[0] !== '');
