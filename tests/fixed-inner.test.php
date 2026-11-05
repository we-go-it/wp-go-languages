<?php

/**
 * Fixed translations, inner blocks (the theme's Sticky columns, with a Rich
 * text and an Accordion inside): IDs on every level, the translator's texts
 * kept inside and out, an extra inner block dropped, the original's inner
 * blocks swapped and added to followed.
 */

require_once __DIR__ . '/fixtures/content.php';


$outer = fn (int $id) => wgt_blocks($id)[0];

$block = wgt_acf('sticky-columns', [
    'title' => ['text' => 'Il nostro metodo', 'tag' => 'h2'],
    'text'  => '<p>Testo fermo</p>',
    'side'  => 'left',
], [
    wgt_acf('rich-text', ['title' => ['text' => 'Primo'], 'text' => '<p>Uno</p>']),
    wgt_acf('accordion', ['title' => ['text' => 'Domande'], 'items' => [['title' => 'D1', 'text' => '<p>R1</p>'], ['title' => 'D2', 'text' => '<p>R2</p>']]]),
]);

$it = wgt_page('Colonne', serialize_blocks([$block]), 'it', ['post_name' => 'wgt-colonne']);
$b  = $outer($it);
check('the outer block and the inner ones get IDs', wgt_id($b) !== '' && wgt_id($b['innerBlocks'][0]) !== '' && wgt_id($b['innerBlocks'][1]) !== '');

$en = WpGoLanguages::create_translation($it, 'en', 'blocks');
wp_update_post(['ID' => $en, 'post_status' => 'publish', 'post_name' => 'wgt-columns']);

section('The translator edits');

// the outer and inner texts translated; a select and an extra inner block tried
$t = $outer($en);
$t['attrs']['data']['title_text'] = 'Our method';
$t['attrs']['data']['text']       = '<p>Sticky text</p>';
$t['attrs']['data']['side']       = 'right';
$t['innerBlocks'][0]['attrs']['data']['title_text'] = 'First';
$t['innerBlocks'][0]['attrs']['data']['text']       = '<p>One</p>';
$t['innerBlocks'][1]['attrs']['data']['items_1_title'] = 'Q2';
$t['innerBlocks'][]  = wgt_acf('rich-text', ['text' => '<p>extra</p>']);
$t['innerContent'][] = null;
wgt_save($en, [$t]);

$e = $outer($en);
check('the outer texts are translated', $e['attrs']['data']['title_text'] === 'Our method' && $e['attrs']['data']['text'] === '<p>Sticky text</p>');
same('right', $e['attrs']['data']['side'], 'the outer select stays its own');
check('the inner texts are translated', $e['innerBlocks'][0]['attrs']['data']['title_text'] === 'First' && $e['innerBlocks'][1]['attrs']['data']['items_1_title'] === 'Q2');
same(2, count($e['innerBlocks']), 'the extra inner block is dropped');

section('The original edits');

// inner blocks swapped, a new one added, the side changed
$o = $outer($it);
$o['innerBlocks']  = [$o['innerBlocks'][1], $o['innerBlocks'][0], wgt_acf('rich-text', ['text' => '<p>Nuovo</p>'])];
$o['innerContent'] = [null, null, null];
$o['attrs']['data']['side'] = 'right';
wgt_save($it, [$o]);

$e = $outer($en);
same(['acf/accordion', 'acf/rich-text', 'acf/rich-text'], array_column($e['innerBlocks'], 'blockName'), 'the inner order follows the original');
check('the moved inner blocks keep their translations', $e['innerBlocks'][0]['attrs']['data']['items_1_title'] === 'Q2' && $e['innerBlocks'][1]['attrs']['data']['title_text'] === 'First');
same('<p>Nuovo</p>', $e['innerBlocks'][2]['attrs']['data']['text'], 'the new inner block has the original\'s text');
same('right', $e['attrs']['data']['side'], 'the side follows the original');
same('Our method', $e['attrs']['data']['title_text'], 'the outer translation is kept');


// the slug as WordPress kept it: the dev site may have one already
same(home_url('/en/' . get_post_field('post_name', $en) . '/'), WpGoLanguages::permalink($en), 'the translation is at its own slug, under /en/');
