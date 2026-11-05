<?php

/**
 * Fixed blocks with free fields (`blocks`): the original's blocks, their
 * order and their rows, every field in them the translation's own whatever
 * its rule; its page fields not synced nor locked, its featured image its
 * own, its template the original's. Not for a form, nor without blocks.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

$hero = wgt_acf('hero', ['title' => ['text' => 'Cosa sta cambiando?', 'tag' => 'h1'], 'text' => '<p>Una creative company.</p>']);
$accordion = wgt_acf('accordion', ['title' => ['text' => 'Domande'], 'items' => [['title' => 'D1', 'text' => '<p>R1</p>'], ['title' => 'D2', 'text' => '<p>R2</p>']]]);

$it = wgt_page('Structure test', serialize_blocks([$hero, $accordion]));
update_post_meta($it, '_thumbnail_id', 11);
update_post_meta($it, '_wp_page_template', 'default');

section('Made');

$en = (int) WpGoLanguages::create_translation($it, 'en', 'blocks');

same('blocks', WpGoLanguages::mode($en), 'the translation is fixed blocks with free fields');
check('its blocks are the original\'s: is_fixed()', Core\is_fixed($en));

section('The translator edits');

$data = fn (int $id, int $i) => wgt_blocks($id)[$i]['attrs']['data'] ?? [];

$list = wgt_blocks($en);
$list[0]['attrs']['data']['title_text'] = 'Hello EN';
$list[0]['attrs']['data']['title_tag'] = 'h6';
$list[0]['attrs']['data']['text'] = '';
$list[] = wgt_para('extra');
wgt_save($en, $list);

same('Hello EN', $data($en, 0)['title_text'] ?? null, 'a text is its own');
same('h6', $data($en, 0)['title_tag'] ?? null, 'a select is its own too, which a fixed translation would put back');
same('', $data($en, 0)['text'] ?? null, 'an emptied field stays empty');
same(2, count(wgt_blocks($en)), 'a block added is dropped: the blocks are the original\'s');

update_post_meta($en, '_thumbnail_id', 22);
wp_update_post(['ID' => $en, 'post_title' => 'Structure test EN']);
same('22', (string) get_post_meta($en, '_thumbnail_id', true), 'its featured image is its own');

section('The original edits');

$list = wgt_blocks($it);
$list[0]['attrs']['data']['title_tag'] = 'h2';
array_unshift($list, wgt_acf('rich-text', ['text' => '<p>Nuovo blocco</p>']));
wgt_save($it, $list);
wp_update_post(['ID' => $it, 'post_title' => 'Structure test']);

// the theme has no page templates: a save would reset one to `default`, so
// set after it, and followed on the translation's own save
update_post_meta($it, '_wp_page_template', 'page-wide.php');
wp_update_post(['ID' => $en, 'post_title' => 'Structure test EN']);

$enb = wgt_blocks($en);
same(array_column(wgt_blocks($it), 'blockName'), array_column($enb, 'blockName'), 'the translation follows the original\'s blocks');
same('<p>Nuovo blocco</p>', $enb[0]['attrs']['data']['text'] ?? null, 'a new block comes with the original\'s values');
same('h6', $enb[1]['attrs']['data']['title_tag'] ?? null, 'a select changed in the original keeps the translation\'s');
same('Hello EN', $enb[1]['attrs']['data']['title_text'] ?? null, 'and its text');
same('22', (string) get_post_meta($en, '_thumbnail_id', true), 'its featured image stays its own');
same('page-wide.php', (string) get_post_meta($en, '_wp_page_template', true), 'its template is the original\'s');

section('Its editor');

$_GET['post'] = (string) $en;
$context = State::$context;
State::$context = 'admin';

// the hero's title tag, a select: a text-only translation hides it
$select = acf_get_field((string) ($data($it, 1)['_title_tag'] ?? ''));
$fields = $select && Core\block_field_policy($select) === 'copy' ? [$select] : [];
check('the hero has a field a fixed translation hides', $fields !== []);
$field = Admin\prepare_fixed_field(array_merge($fields[0], ['prefix' => 'acf-block_x', 'value' => '']));
check('on fixed blocks with free fields it is shown', !str_contains((string) ($field['wrapper']['class'] ?? ''), 'wgl-locked'));
check('no copied field is hidden on the page either', !Core\locks_copied_fields());

$fixed = (int) WpGoLanguages::create_translation(wgt_page('Rules test', serialize_blocks([$hero])), 'en', 'blocks');
$_GET['post'] = (string) $fixed;
$field = Admin\prepare_fixed_field(array_merge($fields[0], ['prefix' => 'acf-block_x', 'value' => '']));
check('on a fixed layout, it isn\'t hidden either', !str_contains((string) ($field['wrapper']['class'] ?? ''), 'wgl-copied'));

State::$context = $context;
unset($_GET['post']);

section('Switching');

$dup = (int) WpGoLanguages::create_translation(wgt_page('Switch test', serialize_blocks([$hero])), 'en');
$list = wgt_blocks($dup);
$list[0]['attrs']['data']['title_tag'] = 'h5';
wgt_save($dup, $list);

check('set_mode() turns an independent translation structure', Core\set_mode($dup, 'blocks'));
same('h5', $data($dup, 0)['title_tag'] ?? null, 'its select is kept');

// the page's fields: into text only, what isn't a text is the original's at once
acf_add_local_field_group([
    'key'      => 'group_wgt_switch',
    'title'    => 'Switch test',
    'fields'   => [
        ['key' => 'field_wgt_sw_img', 'name' => 'wgt_sw_img', 'type' => 'image'],
        ['key' => 'field_wgt_sw_text', 'name' => 'wgt_sw_text', 'type' => 'text'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);
$dup_it = Core\original($dup);
update_field('field_wgt_sw_img', 11, $dup_it);
update_field('field_wgt_sw_text', 'Ciao', $dup_it);
update_field('field_wgt_sw_img', 22, $dup);
update_field('field_wgt_sw_text', 'Hello', $dup);
Core\set_mode($dup, 'blocks');
same(22, (int) get_post_meta($dup, 'wgt_sw_img', true), 'into fixed blocks, a page field\'s image stays its own');
// as the editor does after the switch: the page's fields posted from the screen as it was
update_post_meta($dup, 'wgt_sw_img', 23);
wp_update_post(['ID' => $dup, 'post_title' => 'Switch test EN']);
same(23, (int) get_post_meta($dup, 'wgt_sw_img', true), 'and what\'s posted stays: only a linked field follows');
same('Hello', get_post_meta($dup, 'wgt_sw_text', true), 'its text too');
check('out to independent', Core\set_mode($dup, 'independent'));
update_post_meta($dup, 'wgt_sw_img', 33);
wp_update_post(['ID' => $dup, 'post_title' => 'Switch test EN again']);
same(33, (int) get_post_meta($dup, 'wgt_sw_img', true), 'independent: its image is its own again');
same(true, Admin\panel_data($dup)['can_blocks'], 'the panel offers it for a page');

section('Not for a form');

$form = WPCF7_ContactForm::get_template(['title' => 'Structure form']);
$form_id = (int) $form->save();
WpGoLanguages::set_language($form_id, 'it');
same('blocks', Core\best_mode(Core\CF7_TYPE, 'blocks'), 'best_mode(): a form has a fixed layout, its own way');
$form_en = (int) WpGoLanguages::create_translation($form_id, 'en', 'blocks');
same('blocks', WpGoLanguages::mode($form_en), 'asked the fixed layout, it has it');
check('set_mode() takes it too', Core\set_mode($form_en, 'blocks') && WpGoLanguages::mode($form_en) === 'blocks');
same(true, Admin\panel_data($form_en)['can_blocks'], 'the panel offers it');

ob_start();
Admin\column('wgl_languages', wgt_page('Plus test', serialize_blocks([$hero])));
$cell = (string) ob_get_clean();
check('a page\'s "+" carries its link', (bool) preg_match('/data-wgl-blocks="[^"]+mode=blocks/', str_replace(['&#038;', '&amp;'], '&', $cell)), $cell);
