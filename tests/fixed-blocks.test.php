<?php

/**
 * Fixed translations, a page's blocks: an original saved before the plugin
 * (no IDs), its fixed translation made, then each side edited. The
 * translator's texts stay, the rest is the original's; the original's
 * moves, new blocks and Duplicates are followed; a mode switch back to fixed
 * drops what the duplicate added.
 */

require_once __DIR__ . '/fixtures/content.php';

use function WpGo\Languages\Core\untranslated_count;
use function WpGo\Languages\Core\is_fixed;
use function WpGo\Languages\Core\set_mode;
use function WpGo\Languages\Core\blocks_dropped;

$hero = wgt_acf('hero', ['title' => ['text' => 'Cosa sta cambiando?', 'tag' => 'h1'], 'text' => '<p>Una creative company.</p>']);
$text_image = wgt_acf('text-image', ['title' => ['text' => 'Chi siamo', 'tag' => 'h2'], 'eyebrow' => 'Occhiello', 'text' => '<p>Leggiamo quello che cambia.</p>', 'image_position' => 'right']);
$accordion = wgt_acf('accordion', ['title' => ['text' => 'Domande'], 'items' => [['title' => 'D1', 'text' => '<p>R1</p>'], ['title' => 'D2', 'text' => '<p>R2</p>']]]);
$source = serialize_blocks([$hero, $text_image, $accordion]);

// as a page from before the plugin: its blocks without IDs
$it = wgt_page('Fixed test', $source);
wgt_raw_content($it, $source);

section('Made fixed');

$en = WpGoLanguages::create_translation($it, 'en', 'fixed');
$ids = fn (int $id) => array_map('wgt_id', wgt_blocks($id));

check('the translation is made fixed', is_fixed($en));
check('the original got its IDs as the translation was made', wgt_id(wgt_blocks($it)[0]) !== '');
same($ids($it), $ids($en), 'the translation has the same IDs as the original');

$count0 = untranslated_count(wgt_content($it), wgt_content($en));
check("the untranslated count is above 0 before translating ($count0)", $count0 > 0);

section('The translator edits');

// translates the hero's texts, tries to change a select, and adds a block
$list = wgt_blocks($en);
$list[0]['attrs']['data']['title_text'] = 'Hello EN';
$list[0]['attrs']['data']['text'] = '<p>English text</p>';
$list[0]['attrs']['data']['title_tag'] = 'h6';
$list[] = wgt_para('extra');
wgt_save($en, $list);

$data = fn (int $id, int $i) => wgt_blocks($id)[$i]['attrs']['data'] ?? [];

same('Hello EN', $data($en, 0)['title_text'] ?? null, 'the translation keeps its translated title');
same('<p>English text</p>', $data($en, 0)['text'] ?? null, 'the translation keeps its translated text');
same($data($it, 0)['title_tag'] ?? null, $data($en, 0)['title_tag'] ?? null, 'a select changed on the translation goes back to the original\'s');
same(count(wgt_blocks($it)), count(wgt_blocks($en)), 'a block added to the translation is dropped');

$count1 = untranslated_count(wgt_content($it), wgt_content($en));
check("the untranslated count went down ($count1 < $count0)", $count1 < $count0);

section('The original edits');

// a select changed, a block moved to the end, a new block first
$list = wgt_blocks($it);
$list[0]['attrs']['data']['title_tag'] = 'h2';
$moved = array_splice($list, 1, 1);
$list[] = $moved[0];
array_unshift($list, wgt_acf('rich-text', ['text' => '<p>Nuovo blocco</p>']));
wgt_save($it, $list);

$itb = wgt_blocks($it);
$enb = wgt_blocks($en);

same(count($itb), count($enb), 'the translation follows: the same number of blocks');
same(array_column($itb, 'blockName'), array_column($enb, 'blockName'), 'the translation follows: the same order');
same('<p>Nuovo blocco</p>', $enb[0]['attrs']['data']['text'] ?? null, 'the new block comes with the original\'s text, to translate');
same('Hello EN', $enb[1]['attrs']['data']['title_text'] ?? null, 'the hero keeps its translation after the move');
same('h2', $enb[1]['attrs']['data']['title_tag'] ?? null, 'the hero\'s select follows the original (h2)');
check('the original\'s new block got an ID', wgt_id($itb[0]) !== '');

section('A Duplicate in the editor');

// the copy repeats the ID it was made from: it gets its own
$list = wgt_blocks($it);
$list[] = $list[1];
wgt_save($it, $list);

$all = $ids($it);
same(count($all), count(array_unique($all)), 'a duplicated block gets a new ID');
same(count(wgt_blocks($it)), count(wgt_blocks($en)), 'the translation still has the original\'s structure after the duplicate');

section('Modes');

set_mode($en, 'duplicate');
$list = wgt_blocks($en);
$list[] = wgt_para('own');
wgt_save($en, $list);

same(count(wgt_blocks($it)) + 1, count(wgt_blocks($en)), 'a duplicate keeps a block of its own');
same(['core/paragraph'], blocks_dropped($en), 'blocks_dropped() names the block switching to fixed would drop');

set_mode($en, 'fixed');

same(count(wgt_blocks($it)), count(wgt_blocks($en)), 'back to fixed, the extra block is dropped');
same('Hello EN', wgt_blocks($en)[1]['attrs']['data']['title_text'] ?? null, 'back to fixed, the translation is kept');
