<?php

/**
 * Fixed blocks with free fields (`structure`): the original's blocks, their
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

$en = (int) WpGoLanguages::create_translation($it, 'en', 'structure');

same('structure', WpGoLanguages::mode($en), 'the translation is fixed blocks with free fields');
check('its blocks are the original\'s: is_fixed()', Core\is_fixed($en));
check('its fields are free', Core\fields_free($en) && !Core\fields_free($it));

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

// the hero's title tag, a select: a fixed translation by the rules hides it
$select = acf_get_field((string) ($data($it, 1)['_title_tag'] ?? ''));
$fields = $select && Core\block_field_policy($select) === 'copy' ? [$select] : [];
check('the hero has a field a fixed translation hides', $fields !== []);
$field = Admin\prepare_fixed_field(array_merge($fields[0], ['prefix' => 'acf-block_x', 'value' => '']));
check('on fixed blocks with free fields it is shown', !str_contains((string) ($field['wrapper']['class'] ?? ''), 'wgl-locked'));
check('no copied field is hidden on the page either', !Core\locks_copied_fields());

$fixed = (int) WpGoLanguages::create_translation(wgt_page('Rules test', serialize_blocks([$hero])), 'en', 'fixed');
$_GET['post'] = (string) $fixed;
$field = Admin\prepare_fixed_field(array_merge($fields[0], ['prefix' => 'acf-block_x', 'value' => '']));
check('on a fixed translation by the rules it is hidden', str_contains((string) ($field['wrapper']['class'] ?? ''), 'wgl-locked'));

State::$context = $context;
unset($_GET['post']);

section('Switching');

$dup = (int) WpGoLanguages::create_translation(wgt_page('Switch test', serialize_blocks([$hero])), 'en');
$list = wgt_blocks($dup);
$list[0]['attrs']['data']['title_tag'] = 'h5';
wgt_save($dup, $list);

check('set_mode() turns a duplicate structure', Core\set_mode($dup, 'structure'));
same('h5', $data($dup, 0)['title_tag'] ?? null, 'its select is kept');
check('and fixed', Core\set_mode($dup, 'fixed'));
same('h1', $data($dup, 0)['title_tag'] ?? null, 'by the rules, the select is the original\'s');
same(true, Admin\panel_data($dup)['can_structure'], 'the panel offers it for a page');

section('Not for a form');

$form = WPCF7_ContactForm::get_template(['title' => 'Structure form']);
$form_id = (int) $form->save();
WpGoLanguages::set_language($form_id, 'it');
$form_en = (int) WpGoLanguages::create_translation($form_id, 'en', 'structure');
same('fixed', WpGoLanguages::mode($form_en), 'asked structure, a form\'s translation is fixed: its fields are its structure');
check('set_mode() refuses structure for a form', !Core\set_mode($form_en, 'structure'));
same(false, Admin\panel_data($form_en)['can_structure'], 'the panel doesn\'t offer it');

ob_start();
Admin\column('wgl_languages', wgt_page('Plus test', serialize_blocks([$hero])));
$cell = (string) ob_get_clean();
check('a page\'s "+" carries its link', (bool) preg_match('/data-wgl-structure="[^"]+mode=structure/', str_replace(['&#038;', '&amp;'], '&', $cell)), $cell);
