<?php

// @require fixtures/third-language.php

/**
 * What a translation must not lose, or show too early: a new original handing
 * over its group; a draft original's changes kept from what's live; the
 * password; a page field's rows keeping their texts; a block row rewritten
 * where it was.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;

$block = fn (string $id, string $title) => serialize_block(['blockName' => 'acf/hero', 'attrs' => ['name' => 'acf/hero', 'data' => [
    'title_text' => $title, '_title_text' => 'field_wg_heading_text',
    'title_tag' => 'h1', '_title_tag' => 'field_wg_heading_tag',
], 'metadata' => ['wgId' => $id]], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
$para   = fn (string $text) => "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
$read   = function (int $id) {
    $out = [];

    foreach (parse_blocks((string) get_post_field('post_content', $id)) as $one) {
        if (!empty($one['blockName'])) {
            $out[] = $one['attrs']['data']['title_text'] ?? trim(wp_strip_all_tags((string) $one['innerHTML']));
        }
    }

    return $out;
};
$write  = fn (int $id, string $content) => wp_update_post(wp_slash(['ID' => $id, 'post_content' => $content]));

section('A new original');

$group = function () use ($block, $write, $para) {
    $it = wgt_page('Consegna', $block('a', 'Titolo A') . "\n\n" . $block('b', 'Titolo B'));
    $de = (int) WpGoLanguages::create_translation($it, 'de', 'text');
    $en = (int) WpGoLanguages::create_translation($it, 'en');
    $write($de, $block('a', 'Titel A') . "\n\n" . $block('b', 'Titel B'));

    return [$it, $de, $en];
};

[$it, $de, $en] = $group();
$write($en, $para('English only layout'));
Core\make_original($en);
same('independent', Core\mode_of($de), 'made original with other blocks: the fixed one turns independent');
wp_update_post(['ID' => $de, 'post_title' => 'Saved']);
same(['Titel A', 'Titel B'], $read($de), 'its next save keeps its blocks');
wp_update_post(['ID' => $en, 'post_title' => 'Saved too']);
same(['Titel A', 'Titel B'], $read($de), 'and so does the new original\'s');

[$it, $de, $en] = $group();
$write($en, $block('a', 'Title A') . "\n\n" . $block('b', 'Title B'));
Core\make_original($en);
same('text', Core\mode_of($de), 'made original with the same blocks: the fixed one stays fixed');
same(['Titel A', 'Titel B'], $read($de), 'its texts kept');

[$it, $de, $en] = $group();
$write($en, $para('English only layout'));
wp_delete_post($it, true);
same('independent', Core\mode_of($de), 'deleting the original: the same rule');
same(['Titel A', 'Titel B'], $read($de), 'its blocks kept');

[$it, $de, $en] = $group();
$write($en, $block('a', 'Title A') . "\n\n" . $block('b', 'Title B'));
wp_delete_post($it, true);
same('text', Core\mode_of($de), 'deleting the original, an heir with the same blocks: it stays fixed');

$en_first = wgt_page('Prima EN', $block('a', 'First'), 'en');
$de_first = (int) WpGoLanguages::create_translation($en_first, 'de', 'text');
$write($de_first, $block('a', 'Erste'));
$it_first = (int) WpGoLanguages::create_translation($en_first, 'it');
same('text', Core\mode_of($de_first), 'a page made in the default language, a copy: the fixed one stays fixed');
same($it_first, Core\original($de_first), 'following it');

section('A draft original and what\'s live');

$it = wgt_page('Bozza', $block('a', 'Titolo'));
update_post_meta($it, 'wgt_copied', 'one');
add_filter('wp_go_languages/meta_policy', $copied = fn ($policy, $key) => $key === 'wgt_copied' ? 'copy' : $policy, 10, 2);
$live  = (int) WpGoLanguages::create_translation($it, 'en', 'text');
$draft = (int) WpGoLanguages::create_translation($it, 'de', 'text');
wp_update_post(wp_slash(['ID' => $live, 'post_status' => 'publish', 'post_content' => $block('a', 'Title')]));
update_post_meta($it, 'wgt_copied', 'two');
wp_update_post(wp_slash(['ID' => $it, 'post_status' => 'draft', 'post_content' => $block('a', 'Titolo') . "\n\n" . $block('new', 'Non pubblicato')]));

same(['Title'], $read($live), 'a draft original\'s blocks don\'t reach a published translation');
same('one', get_post_meta($live, 'wgt_copied', true), 'nor its copied fields');
check('the panel says so', WpGo\Languages\Admin\panel_data($live)['held'] === true);
same(['Titolo', 'Non pubblicato'], $read($draft), 'a translation in draft follows it');
same('two', get_post_meta($draft, 'wgt_copied', true), 'its copied fields too');
check('and isn\'t held', WpGo\Languages\Admin\panel_data($draft)['held'] === false);

$write($live, $block('a', 'Title edited'));
same(['Title edited'], $read($live), 'the published one\'s own save keeps its text, and no draft block');

check('switching mode meanwhile', Core\set_mode($live, 'blocks'));
same(['Title edited'], $read($live), 'brings no draft block either');
Core\set_mode($live, 'text');

wp_update_post(['ID' => $it, 'post_status' => 'publish']);
same(['Title edited', 'Non pubblicato'], $read($live), 'published, the changes arrive, its text kept');
same('two', get_post_meta($live, 'wgt_copied', true), 'and the copied fields');
check('no longer held', WpGo\Languages\Admin\panel_data($live)['held'] === false);
remove_filter('wp_go_languages/meta_policy', $copied);

section('The password');

$it   = wgt_page('Protetta', $block('a', 'Titolo'));
$text = (int) WpGoLanguages::create_translation($it, 'en', 'text');
$open = (int) WpGoLanguages::create_translation($it, 'de', 'blocks');
$page = wgt_page('Protetta sola', $block('a', 'Titolo'));
$free = (int) WpGoLanguages::create_translation($page, 'en');
foreach ([$text, $open, $free] as $id) {
    wp_update_post(['ID' => $id, 'post_status' => 'publish']);
}

wp_update_post(['ID' => $it, 'post_password' => 'segreta']);
same('segreta', get_post_field('post_password', $text), 'a password on the original protects a text-only translation');
same('segreta', get_post_field('post_password', $open), 'and a fixed blocks one');
wp_update_post(['ID' => $text, 'post_password' => 'mine']);
same('segreta', get_post_field('post_password', $text), 'its own save puts it back');
wp_update_post(['ID' => $it, 'post_password' => '']);
same('', get_post_field('post_password', $text), 'taken off the original, off the translation');
wp_update_post(['ID' => $page, 'post_password' => 'segreta']);
same('', get_post_field('post_password', $free), 'an independent translation keeps its own');

section('A page field\'s rows');

acf_add_local_field_group([
    'key'      => 'group_wgt_safe',
    'title'    => 'Rows',
    'fields'   => [
        ['key' => 'field_wgt_safe_rows', 'name' => 'wgt_safe_rows', 'type' => 'repeater', 'sub_fields' => [
            ['key' => 'field_wgt_safe_title', 'name' => 'title', 'type' => 'text'],
            ['key' => 'field_wgt_safe_img', 'name' => 'img', 'type' => 'number'],
        ]],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);
$set  = fn (int $id, array $rows) => update_field('field_wgt_safe_rows', array_map(fn ($row) => ['field_wgt_safe_title' => $row[0], 'field_wgt_safe_img' => $row[1]], $rows), $id);
$rows = fn (int $id) => array_map(fn ($i) => [get_post_meta($id, "wgt_safe_rows_{$i}_title", true), (int) get_post_meta($id, "wgt_safe_rows_{$i}_img", true)], range(0, (int) get_post_meta($id, 'wgt_safe_rows', true) - 1));
$save = fn (int $id) => wp_update_post(['ID' => $id, 'post_title' => 'Saved ' . wp_generate_uuid4()]);

$it = wgt_page('Righe');
$set($it, [['Uno', 11], ['Due', 22]]);
$en = (int) WpGoLanguages::create_translation($it, 'en', 'text');
update_post_meta($en, 'wgt_safe_rows_0_title', 'One');
update_post_meta($en, 'wgt_safe_rows_1_title', 'Two');

$set($it, [['Due', 22], ['Uno', 11], ['Tre', 33]]);
$save($it);
same([['Two', 22], ['One', 11], ['Tre', 33]], $rows($en), 'reordered: each text stays beside its image; a new row starts with the original\'s text');

$save($en);
same([['Two', 22], ['One', 11], ['Tre', 33]], $rows($en), 'the translation\'s own save changes nothing');

update_post_meta($en, 'wgt_safe_rows_2_title', 'Three');
$set($it, [['Tre riscritto', 33], ['Due', 22]]);
$save($it);
same([['Three', 33], ['Two', 22]], $rows($en), 'a row removed, another edited and moved: their texts follow');
check('the removed row\'s cells are gone', !metadata_exists('post', $en, 'wgt_safe_rows_2_title') && !metadata_exists('post', $en, '_wgt_safe_rows_2_title'));

update_post_meta($en, 'wgt_safe_rows_0_title', '');
$save($it);
same('', get_post_meta($en, 'wgt_safe_rows_0_title', true), 'an emptied text stays empty');

$old = wgt_page('Righe di prima');
$set($old, [['Uno', 11], ['Due', 22]]);
$old_en = (int) WpGoLanguages::create_translation($old, 'en', 'text');
update_post_meta($old_en, 'wgt_safe_rows_0_title', 'One');
delete_post_meta($old_en, Core\ROWS_META);
$set($old, [['Uno', 99], ['Due', 22]]);
$save($old);
same('One', get_post_meta($old_en, 'wgt_safe_rows_0_title', true), 'a translation with no record of the rows: by their place');
check('it has one now', is_array(get_post_meta($old_en, Core\ROWS_META, true)));

$free_it = wgt_page('Righe libere');
$set($free_it, [['Uno', 11], ['Due', 22]]);
$free_en = (int) WpGoLanguages::create_translation($free_it, 'en');
$set($free_en, [['Only', 77]]);
$set($free_it, [['Due', 22], ['Uno', 11]]);
$save($free_it);
same([['Only', 77]], $rows($free_en), 'an independent translation\'s rows are its own');
same('', get_post_meta($free_en, Core\ROWS_META, true), 'it keeps no record of them');

check('the record is never copied', Core\own_meta_key(Core\ROWS_META));

section('A block row rewritten where it was');

acf_add_local_field_group([
    'key'      => 'group_wgt_safe_block',
    'title'    => 'Block rows',
    'fields'   => [
        ['key' => 'field_wgt_sb_items', 'name' => 'items', 'type' => 'repeater', 'sub_fields' => [
            ['key' => 'field_wgt_sb_q', 'name' => 'q', 'type' => 'text'],
            ['key' => 'field_wgt_sb_a', 'name' => 'a', 'type' => 'text'],
        ]],
    ],
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/wgt-none']]],
]);
$data = fn (array $rows) => array_reduce(array_keys($rows), fn ($out, $i) => $out + [
    "items_{$i}_q" => $rows[$i][0], "_items_{$i}_q" => 'field_wgt_sb_q',
    "items_{$i}_a" => $rows[$i][1], "_items_{$i}_a" => 'field_wgt_sb_a',
], ['items' => count($rows), '_items' => 'field_wgt_sb_items']);
$before = $data([['Domanda 1', 'Risposta 1'], ['Domanda 2', 'Risposta 2']]);
$own    = $data([['Question 1', 'Answer 1'], ['Question 2', 'Answer 2']]);

$merged = Core\merge_data($data([['Riscritta', 'Tutta'], ['Domanda 2', 'Risposta 2']]), $own, $before);
same(['Question 1', 'Answer 1'], [$merged['items_0_q'], $merged['items_0_a']], 'every cell rewritten: its translation stays');
$merged = Core\merge_data($data([['Domanda 2', 'Risposta 2'], ['Domanda 1', 'Risposta 1'], ['Nuova', 'Riga']]), $own, $before);
same(['Question 2', 'Question 1', 'Nuova'], [$merged['items_0_q'], $merged['items_1_q'], $merged['items_2_q']], 'moved rows follow; a new one starts with the original\'s');

section('Rows through several draft saves');

$it = wgt_page('Bozze', serialize_blocks([['blockName' => 'acf/wgt-none', 'attrs' => ['name' => 'acf/wgt-none', 'data' => $before, 'metadata' => ['wgId' => 'r']], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]]));
$with = fn (array $values) => serialize_blocks([['blockName' => 'acf/wgt-none', 'attrs' => ['name' => 'acf/wgt-none', 'data' => $values, 'metadata' => ['wgId' => 'r']], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]]);
// as a page published from the editor: this version is among its revisions
wp_update_post(wp_slash(['ID' => $it, 'post_content' => $with($before)]));
$en = (int) WpGoLanguages::create_translation($it, 'en', 'text');
wp_update_post(wp_slash(['ID' => $en, 'post_status' => 'publish', 'post_content' => $with($own)]));
$cell = fn (int $id, string $key) => parse_blocks((string) get_post_field('post_content', $id))[0]['attrs']['data'][$key] ?? null;

wp_update_post(wp_slash(['ID' => $it, 'post_status' => 'draft', 'post_content' => $with($data([['Domanda 2', 'Risposta 2'], ['Domanda 1', 'Risposta 1']]))]));
wp_update_post(wp_slash(['ID' => $it, 'post_content' => $with($data([['Domanda 2', 'Risposta 2'], ['Domanda 1', 'Risposta 1 bis']]))]));
same('Question 1', $cell($en, 'items_0_q'), 'held: the live rows don\'t move');
wp_update_post(['ID' => $it, 'post_status' => 'publish']);
same(['Question 2', 'Question 1'], [$cell($en, 'items_0_q'), $cell($en, 'items_1_q')], 'published after two draft saves: its rows moved from the version it knew');

section('The modes\' names');

$it = wgt_page('Nomi');
same('text', Core\mode_of((int) WpGoLanguages::create_translation($it, 'en', 'fixed')), 'an old name is still taken: fixed is text');
same('blocks', Core\normalize_mode('structure'), 'structure is blocks');
same('independent', Core\normalize_mode('duplicate'), 'duplicate is independent');
check('a name that\'s neither is refused', is_wp_error(WpGoLanguages::create_translation($it, 'de', 'nonsense')));

// a value stored before 1.2, as the upgrade finds it
$old = (int) WpGoLanguages::create_translation(wgt_page('Vecchio'), 'en', 'text');
update_post_meta($old, Core\MODE_META, 'structure');
same('blocks', Core\mode_of($old), 'read with an old name, it\'s still understood');
Core\upgrade_mode_keys();
same('blocks', get_post_meta($old, Core\MODE_META, true), 'the upgrade rewrites it');

section('The form picker');

acf_add_local_field_group([
    'key'      => 'group_wgt_picker',
    'title'    => 'Picker',
    'fields'   => [['key' => 'field_wgt_picker', 'name' => 'wgt_picker', 'type' => 'acf_cf7']],
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/wgt-none']]],
]);
$form_it = (int) WPCF7_ContactForm::get_template(['title' => 'Modulo'])->save();
WpGoLanguages::set_language($form_it, 'it');
$form_en = (int) WpGoLanguages::create_translation($form_it, 'en', 'text');
wp_update_post(['ID' => $form_en, 'post_status' => 'publish', 'post_title' => 'Form']);

$draw = function (string $code, int $value) {
    return WpGoLanguages::in($code, function () use ($value) {
        $field = acf_prepare_field(array_merge(acf_get_field('field_wgt_picker'), ['value' => $value, 'prefix' => 'acf-block_x']));

        return [(int) $field['value'], wgt_output(fn () => acf_render_field($field))];
    });
};
$selected = fn (string $html, int $id) => (bool) preg_match('#<option value="' . $id . '"[^>]*selected#', $html);

[$value, $html] = $draw('en', $form_it);
same($form_en, $value, 'the Italian form, edited in English: its English translation');
check('it is selected, so ACF\'s rebuild of the block\'s data keeps it', $selected($html, $form_en));

[$value, $html] = $draw('it', $form_it);
same($form_it, $value, 'in its own language, as it is');
check('selected', $selected($html, $form_it));

wp_update_post(['ID' => $form_en, 'post_status' => 'draft']);
[$value, $html] = $draw('en', $form_it);
same($form_it, $value, 'a translation in draft: the stored form stays');
check('listed and selected, though of another language', $selected($html, $form_it));
check('the listing is back to one language after', !has_action('pre_get_posts', 'WpGo\\Languages\\Core\\cf7_list_every_language'));

section('One rule, on a page and in a block');

acf_add_local_field_group([
    'key'      => 'group_wgt_rule',
    'title'    => 'Rule',
    'fields'   => [
        ['key' => 'field_wgt_rule_code', 'name' => 'code', 'type' => 'text'],
        ['key' => 'field_wgt_rule_pic', 'name' => 'pic', 'type' => 'image'],
        ['key' => 'field_wgt_rule_own', 'name' => 'own', 'type' => 'number'],
    ],
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/wgt-none']]],
]);
add_filter('wp_go_languages/field_policy', $rule = fn ($policy, $field) => ['field_wgt_rule_code' => 'copy', 'field_wgt_rule_pic' => 'translate', 'field_wgt_rule_own' => 'ignore'][$field['key']] ?? $policy, 10, 2);
$values = fn ($code, $pic, $own) => ['code' => $code, '_code' => 'field_wgt_rule_code', 'pic' => $pic, '_pic' => 'field_wgt_rule_pic', 'own' => $own, '_own' => 'field_wgt_rule_own'];
$merged = Core\merge_data($values('IT', 1, 1), $values('EN', 2, ''));
same(['IT', 2, ''], [$merged['code'], $merged['pic'], $merged['own']], 'in a block: a text made copy follows, an image made translate is its own, an ignored field stays as it is');
same('copy', Core\field_policy(acf_get_field('field_wgt_rule_code')), 'on a page: the same');
check('a text made copy is no text: no count, no "Original:" line', !Core\is_text_field(acf_get_field('field_wgt_rule_code')));
check('in the block\'s form, what isn\'t copy is shown', WpGo\Languages\Admin\fixed_field_shows(acf_get_field('field_wgt_rule_pic')) && !WpGo\Languages\Admin\fixed_field_shows(acf_get_field('field_wgt_rule_code')));
remove_filter('wp_go_languages/field_policy', $rule);

section('A new default: options and menus go with it');

acf_add_local_field_group([
    'key'      => 'group_wgt_swap',
    'title'    => 'Swap',
    'fields'   => [
        ['key' => 'field_wgt_swap_text', 'name' => 'wgt_swap_text', 'type' => 'text'],
        ['key' => 'field_wgt_swap_logo', 'name' => 'wgt_swap_logo', 'type' => 'image'],
        ['key' => 'field_wgt_swap_only', 'name' => 'wgt_swap_only', 'type' => 'text'],
        ['key' => 'field_wgt_swap_rows', 'name' => 'wgt_swap_rows', 'type' => 'repeater', 'sub_fields' => [['key' => 'field_wgt_swap_cell', 'name' => 'cell', 'type' => 'text']]],
    ],
    'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => 'wgt-none']]],
]);
foreach ([
    'options_wgt_swap_text' => 'Ciao', '_options_wgt_swap_text' => 'field_wgt_swap_text',
    'options_de_wgt_swap_text' => 'Hallo', '_options_de_wgt_swap_text' => 'field_wgt_swap_text',
    'options_en_wgt_swap_text' => 'Hello', '_options_en_wgt_swap_text' => 'field_wgt_swap_text',
    'options_wgt_swap_logo' => '17', '_options_wgt_swap_logo' => 'field_wgt_swap_logo',
    'options_wgt_swap_only' => 'Solo italiano', '_options_wgt_swap_only' => 'field_wgt_swap_only',
    'options_wgt_swap_rows' => '1', '_options_wgt_swap_rows' => 'field_wgt_swap_rows',
    'options_wgt_swap_rows_0_cell' => 'Riga', '_options_wgt_swap_rows_0_cell' => 'field_wgt_swap_cell',
    'options_de_wgt_swap_rows_0_cell' => 'Zeile', '_options_de_wgt_swap_rows_0_cell' => 'field_wgt_swap_cell',
    'options_page_other_plugin' => 'untouched',
] as $name => $value) {
    update_option($name, $value);
}
$opt = fn (string $name) => get_option($name, null);

Core\swap_options('it', 'de');
same('Hallo', $opt('options_wgt_swap_text'), 'the new default\'s text has no suffix now');
same('Ciao', $opt('options_it_wgt_swap_text'), 'the old default\'s has its language');
same(null, $opt('options_de_wgt_swap_text'), 'no row left under the new default\'s code');
same('Hello', $opt('options_en_wgt_swap_text'), 'another language\'s stays');
same('17', $opt('options_wgt_swap_logo'), 'what\'s the same in every language stays where all read it');
same(null, $opt('options_it_wgt_swap_logo'), 'with no copy under the old default');
same(null, $opt('options_wgt_swap_only'), 'a text the new default hasn\'t translated is empty there');
same('Solo italiano', $opt('options_it_wgt_swap_only'), 'kept in Italian');
same(['1', 'Zeile', 'Riga'], [$opt('options_wgt_swap_rows'), $opt('options_wgt_swap_rows_0_cell'), $opt('options_it_wgt_swap_rows_0_cell')], 'a repeater: its rows stay, each cell\'s text swaps');
same('field_wgt_swap_text', $opt('_options_it_wgt_swap_text'), 'the references go along');
same('untouched', $opt('options_page_other_plugin'), 'what isn\'t a field is left alone');

// a site saved before this: its language kept in the settings
$settings = get_option(Core\OPTION);
$stored   = get_option(Core\STORED_DEFAULT_OPTION);
update_option(Core\OPTION, array_replace((array) $settings, ['base_language' => Core\default_language()]));
delete_option(Core\STORED_DEFAULT_OPTION);
Core\sync_stored_default();
same(Core\default_language(), get_option(Core\STORED_DEFAULT_OPTION), 'in step with the default: nothing to swap, it\'s noted');
check('the old key is gone', !array_key_exists('base_language', (array) get_option(Core\OPTION)));
update_option(Core\OPTION, $settings);

section('A translation visitors can reach');

$it = wgt_page('Raggiungibile');
$en = (int) WpGoLanguages::create_translation($it, 'en');
same(null, WpGoLanguages::published_translation($it, 'en'), 'a draft isn\'t');
same($en, WpGoLanguages::translation($it, 'en'), 'though translation() finds it');
wp_update_post(['ID' => $en, 'post_status' => 'publish']);
same($en, WpGoLanguages::published_translation($it, 'en'), 'published, it is');
same(null, WpGoLanguages::published_translation($it, 'de'), 'none: none');

section('Links that don\'t lead where they should');

acf_add_local_field_group([
    'key'      => 'group_wgt_links',
    'title'    => 'Links',
    'fields'   => [
        ['key' => 'field_wgt_link_page', 'name' => 'target', 'type' => 'post_object', 'post_type' => ['page']],
        ['key' => 'field_wgt_link_text', 'name' => 'body', 'type' => 'wysiwyg'],
    ],
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/wgt-none']]],
]);
$link_block = fn (int $target, string $body = '') => serialize_blocks([['blockName' => 'acf/wgt-none', 'attrs' => ['name' => 'acf/wgt-none', 'data' => ['target' => $target, '_target' => 'field_wgt_link_page', 'body' => $body, '_body' => 'field_wgt_link_text']], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]]);
$kinds = fn (int $id) => array_column(Core\link_warnings($id), 'kind', 'title');

$target_it = wgt_page('Destinazione', '', 'it', ['post_name' => 'wgt-destinazione']);
$from_en   = wgt_page('From', $link_block($target_it), 'en', ['post_name' => 'wgt-from']);
same(['Destinazione' => 'missing'], $kinds($from_en), 'no translation in its language: leads to another');
$target_en = (int) WpGoLanguages::create_translation($target_it, 'en');
same(['Destinazione' => 'unpublished'], $kinds($from_en), 'its translation in draft: doesn\'t show');
check('the panel says it in words', str_contains(WpGo\Languages\Admin\panel_data($from_en)['link_warnings'][0], 'Destinazione'));
wp_update_post(['ID' => $target_en, 'post_status' => 'publish']);
same([], $kinds($from_en), 'published: nothing to say');

$typed = wgt_page('Typed', $link_block(0, '<p><a href="' . get_permalink($target_it) . '">Vai</a></p>'), 'en', ['post_name' => 'wgt-typed']);
same(['Destinazione' => 'language'], $kinds($typed), 'a link typed in a text, to a page in another language');
$typed_en = wgt_page('Typed well', $link_block(0, '<p><a href="' . get_permalink($target_en) . '">Go</a></p>'), 'en', ['post_name' => 'wgt-typed-well']);
same([], $kinds($typed_en), 'to its own language\'s: nothing');
$outside = wgt_page('Outside', $link_block(0, '<a href="https://example.test/x">Out</a>'), 'en', ['post_name' => 'wgt-outside']);
same([], $kinds($outside), 'outside the site: nothing');

section('Reconnecting what was translated late');

$parent = wgt_page('Genitore');
$child  = wgt_page('Figlio', '', 'it', ['post_parent' => $parent]);
$child_en = (int) WpGoLanguages::create_translation($child, 'en');
same(0, (int) get_post_field('post_parent', $child_en), 'a child translated first has no parent');
$parent_en = (int) WpGoLanguages::create_translation($parent, 'en');
same($parent_en, (int) get_post_field('post_parent', $child_en), 'the parent translated: it goes under it');

$moved = wgt_page('Spostato', '', 'it', ['post_parent' => $parent]);
$other_it = wgt_page('Altro');
$other_en = (int) WpGoLanguages::create_translation($other_it, 'en');
$moved_de = wgt_page('Anderswo', '', 'en', ['post_parent' => $other_en]);
WpGoLanguages::link($moved_de, $moved);
$parent2 = wgt_page('Genitore due');
wp_update_post(['ID' => $moved, 'post_parent' => $parent2]);
WpGoLanguages::create_translation($parent2, 'en');
same($other_en, (int) get_post_field('post_parent', $moved_de), 'one given a parent of its own stays there');

register_taxonomy('wgt_late', 'page', ['hierarchical' => true]);
add_filter('wp_go_languages/config', $late = fn ($config) => array_replace($config, ['taxonomies' => array_merge((array) $config['taxonomies'], ['wgt_late'])]));
Core\config(true);
Core\register_taxonomies();
$term = Core\in_language('it', fn () => wp_insert_term('Tardi', 'wgt_late'));
$term_id = (int) $term['term_id'];
Core\set_language($term_id, 'it', 'term');
$tagged = wgt_page('Con termine');
Core\in_language('it', fn () => wp_set_object_terms($tagged, [$term_id], 'wgt_late'));
$tagged_en = (int) WpGoLanguages::create_translation($tagged, 'en');
same([], Core\in_language('en', fn () => wp_get_object_terms($tagged_en, 'wgt_late', ['fields' => 'ids'])), 'made before the term\'s translation: no term');
$term_en = (int) Core\create_term_translation($term_id, 'en');
same([$term_en], array_map('intval', Core\in_language('en', fn () => wp_get_object_terms($tagged_en, 'wgt_late', ['fields' => 'ids']))), 'the term translated: the page gets it');
remove_filter('wp_go_languages/config', $late);
Core\config(true);

section('A hidden language');

$hide = fn ($config) => array_replace($config, ['languages' => array_map(fn ($language) => $language['code'] === 'de' ? $language + ['hidden' => true] : $language, $config['languages'])]);
add_filter('wp_go_languages/config', $hide);
Core\config(true);
$hidden_it = wgt_page('Nascosta');
$hidden_de = (int) WpGoLanguages::create_translation($hidden_it, 'de');
$hidden_en = (int) WpGoLanguages::create_translation($hidden_it, 'en');
wp_update_post(['ID' => $hidden_de, 'post_status' => 'publish']);
wp_update_post(['ID' => $hidden_en, 'post_status' => 'publish']);

check('it is hidden', Core\is_hidden_language('de'));
check('the default never is', !Core\is_hidden_language(Core\default_language()));
wp_set_current_user(0);
check('a visitor doesn\'t see it', !Core\shows_language('de'));
check('not in the switcher', !isset(Core\switcher($hidden_it)['de']) && isset(Core\switcher($hidden_it)['en']));
check('not in hreflang', !isset(Core\alternates($hidden_it)['de-DE']) && isset(Core\alternates($hidden_it)['en-GB']));
same(false, Core\sitemap_entry(['loc' => 'x'], 'post', get_post($hidden_de)), 'not in the sitemap');
check('another\'s entry stays', is_array(Core\sitemap_entry(['loc' => 'x'], 'post', get_post($hidden_en))));
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids']);
wp_set_current_user((int) $admins[0]);
check('those who can edit pages still see it', Core\shows_language('de') && isset(Core\switcher($hidden_it)['de']));
wp_set_current_user(0);
remove_filter('wp_go_languages/config', $hide);
Core\config(true);
same(false, array_key_exists('hidden', Core\language('en') ?? []), 'a language not hidden has no such key');

section('Starting from the original, or empty');

acf_add_local_field_group([
    'key'      => 'group_wgt_start',
    'title'    => 'Start',
    'fields'   => [
        ['key' => 'field_wgt_start_title', 'name' => 'title', 'type' => 'text'],
        ['key' => 'field_wgt_start_body', 'name' => 'body', 'type' => 'wysiwyg'],
        ['key' => 'field_wgt_start_pic', 'name' => 'pic', 'type' => 'image'],
        ['key' => 'field_wgt_start_size', 'name' => 'size', 'type' => 'select', 'choices' => ['s' => 's', 'l' => 'l'], 'default_value' => 's'],
        ['key' => 'field_wgt_start_page', 'name' => 'page', 'type' => 'post_object', 'post_type' => ['page']],
        ['key' => 'field_wgt_start_link', 'name' => 'link', 'type' => 'link'],
        ['key' => 'field_wgt_start_url', 'name' => 'url', 'type' => 'url'],
    ],
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/wgt-start']]],
]);
acf_add_local_field_group([
    'key'      => 'group_wgt_start_page',
    'title'    => 'Start page',
    'fields'   => [
        ['key' => 'field_wgt_sp_text', 'name' => 'wgt_sp_text', 'type' => 'text'],
        ['key' => 'field_wgt_sp_pic', 'name' => 'wgt_sp_pic', 'type' => 'number'],
        ['key' => 'field_wgt_sp_page', 'name' => 'wgt_sp_page', 'type' => 'post_object', 'post_type' => ['page']],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);

$linked_it = wgt_page('Collegata', '', 'it', ['post_name' => 'wgt-collegata']);
$linked_en = (int) WpGoLanguages::create_translation($linked_it, 'en');
wp_update_post(['ID' => $linked_en, 'post_status' => 'publish', 'post_name' => 'wgt-linked']);
$alone_it  = wgt_page('Sola', '', 'it', ['post_name' => 'wgt-sola']);
$url_it    = get_permalink($linked_it);
$url_en    = get_permalink($linked_en);
$url_alone = get_permalink($alone_it);

$starter = serialize_blocks([
    ['blockName' => 'acf/wgt-start', 'attrs' => ['name' => 'acf/wgt-start', 'metadata' => ['wgId' => 'start'], 'data' => [
        'title' => 'Titolo', '_title' => 'field_wgt_start_title',
        'body' => '<p>Vai a <a href="' . $url_it . '#qui">questa</a> o <a href="' . $url_alone . '">quella</a> o <a href="https://example.test/x">fuori</a></p>', '_body' => 'field_wgt_start_body',
        'pic' => 17, '_pic' => 'field_wgt_start_pic',
        'size' => 'l', '_size' => 'field_wgt_start_size',
        'page' => $linked_it, '_page' => 'field_wgt_start_page',
        'link' => ['title' => 'Scopri', 'url' => $url_it, 'target' => ''], '_link' => 'field_wgt_start_link',
        'url' => $url_it, '_url' => 'field_wgt_start_url',
    ]], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []],
    ['blockName' => 'core/paragraph', 'attrs' => ['metadata' => ['wgId' => 'core']], 'innerBlocks' => [], 'innerHTML' => '<p>Testo <a href="' . $url_it . '">link</a></p>', 'innerContent' => ['<p>Testo <a href="' . $url_it . '">link</a></p>']],
]);
$start_page = function () use ($starter, $linked_it) {
    $it = wgt_page('Partenza', $starter, 'it', ['post_excerpt' => 'Riassunto']);

    update_post_meta($it, 'wgt_sp_text', 'Testo pagina');
    update_post_meta($it, '_wgt_sp_text', 'field_wgt_sp_text');
    update_post_meta($it, 'wgt_sp_pic', '5');
    update_post_meta($it, '_wgt_sp_pic', 'field_wgt_sp_pic');
    update_post_meta($it, 'wgt_sp_page', (string) $linked_it);
    update_post_meta($it, '_wgt_sp_page', 'field_wgt_sp_page');
    update_post_meta($it, '_thumbnail_id', '9');
    update_post_meta($it, '_wp_page_template', 'page-wide.php');
    update_post_meta($it, '_yoast_wpseo_title', 'SEO');

    return $it;
};
$acf = fn (int $id, string $key) => parse_blocks((string) get_post_field('post_content', $id))[0]['attrs']['data'][$key] ?? null;
$core = fn (int $id) => (string) (array_values(array_filter(parse_blocks((string) get_post_field('post_content', $id)), fn ($b) => $b['blockName'] === 'core/paragraph'))[0]['innerHTML'] ?? '');

// copied: its fields filled in, what points at the site's pages this language's
$it = $start_page();
$en = (int) WpGoLanguages::create_translation($it, 'en', 'independent');
same('Partenza', get_the_title($en), 'copied: the title');
same('Titolo', $acf($en, 'title'), 'a text');
same(17, (int) $acf($en, 'pic'), 'an image');
same((string) $linked_en, (string) $acf($en, 'page'), 'a page picked: this language\'s');
same($url_en, $acf($en, 'link')['url'] ?? null, 'a link field\'s address too');
same('Scopri', $acf($en, 'link')['title'] ?? null, 'its text as it was');
same($url_en, $acf($en, 'url'), 'a URL field\'s too, when it\'s the site\'s');
check('a link typed in a text, its anchor kept', str_contains((string) $acf($en, 'body'), 'href="' . $url_en . '#qui"'));
check('one with no translation stays as it is', str_contains((string) $acf($en, 'body'), 'href="' . $url_alone . '"'));
check('one outside the site too', str_contains((string) $acf($en, 'body'), 'https://example.test/x'));
check('a core block\'s link too', str_contains($core($en), 'href="' . $url_en . '"'));
same((string) $linked_en, (string) get_post_meta($en, 'wgt_sp_page', true), 'a page field\'s page too');
same('Riassunto', get_post_field('post_excerpt', $en), 'the excerpt');
same($url_it, $acf($it, 'url'), 'the original stays as it is');

// the same, in the fixed modes
$en = (int) WpGoLanguages::create_translation($start_page(), 'en', 'blocks');
same((string) $linked_en, (string) $acf($en, 'page'), 'fixed blocks, copied: its page this language\'s');
$en = (int) WpGoLanguages::create_translation($start_page(), 'en', 'text');
check('text only, copied: a typed link this language\'s', str_contains((string) $acf($en, 'body'), 'href="' . $url_en . '#qui"'));

// empty: independent
$it = $start_page();
$en = (int) WpGoLanguages::create_translation($it, 'en', 'independent', false);
check('empty, independent: it\'s made', !is_wp_error($en) && $en > 0);
same(['', '', ''], [get_post_field('post_title', $en), get_post_field('post_content', $en), get_post_field('post_excerpt', $en)], 'an empty page');
same('', get_post_field('post_name', $en), 'with no slug: it takes one from its title');
same('', get_post_meta($en, 'wgt_sp_text', true), 'no field');
same('', get_post_meta($en, '_wp_page_template', true), 'no template');
same('', get_post_meta($en, '_thumbnail_id', true), 'no featured image');
same('en', Core\language_of($en), 'in its language');
same($it, Core\original($en), 'linked to its original');
same('independent', Core\mode_of($en), 'independent');

// empty: fixed blocks
$it = $start_page();
$en = (int) WpGoLanguages::create_translation($it, 'en', 'blocks', false);
same('', get_post_field('post_title', $en), 'empty, fixed blocks: no title');
same(['start', 'core'], array_values(array_filter(array_map(fn ($b) => $b['attrs']['metadata']['wgId'] ?? null, parse_blocks((string) get_post_field('post_content', $en))))), 'the original\'s blocks');
same(['', '', '', '', '', ''], [$acf($en, 'title'), $acf($en, 'body'), $acf($en, 'pic'), $acf($en, 'page'), $acf($en, 'link'), $acf($en, 'url')], 'every field empty');
same('s', $acf($en, 'size'), 'or at its default');
same('field_wgt_start_title', $acf($en, '_title'), 'ACF still knows them');
check('a core block keeps its markup, not its text', !str_contains($core($en), 'Testo') && str_contains($core($en), '<p>'));
same(['', '', ''], [get_post_meta($en, 'wgt_sp_text', true), get_post_meta($en, 'wgt_sp_pic', true), get_post_meta($en, 'wgt_sp_page', true)], 'the page\'s fields empty');
same('field_wgt_sp_text', get_post_meta($en, '_wgt_sp_text', true), 'their references kept');
same('', get_post_meta($en, '_thumbnail_id', true), 'no featured image: its own');
same('page-wide.php', get_post_meta($en, '_wp_page_template', true), 'the template: its blocks are the original\'s');
same('', get_post_meta($en, '_yoast_wpseo_title', true), 'no SEO');
wp_update_post(['ID' => $en, 'post_title' => 'Own title']);
same('', $acf($en, 'title'), 'its save keeps them empty');
wp_update_post(['ID' => $it, 'post_title' => 'Partenza salvata']);
same('', $acf($en, 'title'), 'and so does the original\'s');
same('', $acf($en, 'pic'), 'its image too');

// empty: text only
$it = $start_page();
$en = (int) WpGoLanguages::create_translation($it, 'en', 'text', false);
same(['', ''], [$acf($en, 'title'), $acf($en, 'body')], 'empty, text only: the texts empty');
same([17, 'l', (string) $linked_it], [(int) $acf($en, 'pic'), $acf($en, 'size'), (string) $acf($en, 'page')], 'the rest the original\'s');
same('', get_post_meta($en, 'wgt_sp_text', true), 'a page field\'s text empty');
same('5', get_post_meta($en, 'wgt_sp_pic', true), 'what isn\'t a text the original\'s');
same('9', get_post_meta($en, '_thumbnail_id', true), 'the featured image');
wp_update_post(['ID' => $en, 'post_title' => 'Own title']);
same(['', 17], [$acf($en, 'title'), (int) $acf($en, 'pic')], 'its save: the texts still empty, the rest still the original\'s');

// always a copy
$it_form = (int) WPCF7_ContactForm::get_template(['title' => 'Modulo copia'])->save();
WpGoLanguages::set_language($it_form, 'it');
$en_form = (int) WpGoLanguages::create_translation($it_form, 'en', 'text', false);
same('Modulo copia', get_the_title($en_form), 'a form is always a copy: its fields are what make it work');
$en_first = wgt_page('Prima', $para('First'), 'en', ['post_name' => 'wgt-prima-en']);
$it_first = (int) WpGoLanguages::create_translation($en_first, 'it', 'independent', false);
same('Prima', get_the_title($it_first), 'into the default language too: it becomes the original');
same(false, Core\can_start_empty('page', Core\default_language()), 'can_start_empty() says so');
same(true, Core\can_start_empty('page', 'en'), 'a page into another language can');
same(false, Core\can_start_empty(Core\CF7_TYPE, 'en'), 'a form can\'t');


section('No status is kept');

$plain = (int) WpGoLanguages::create_translation(wgt_page('Niente stato', $block('a', 'Titolo')), 'en', 'text');
wp_update_post(wp_slash(['ID' => $plain, 'post_content' => $block('a', 'Title')]));
same('', get_post_meta($plain, '_wgl_texts', true), 'a save records nothing about its texts');
check('the panel has no count', !array_key_exists('untranslated', WpGo\Languages\Admin\panel_data($plain)) && !array_key_exists('outdated', WpGo\Languages\Admin\panel_data($plain)));

section('Menus: linked, and following');

$saved_locations = get_theme_mods()['nav_menu_locations'] ?? [];
$menu_of = function (string $name, string $code) {
    $id = (int) wp_create_nav_menu($name . ' ' . wp_generate_uuid4());
    Core\set_language($id, $code, 'term');

    return $id;
};
$menu_it = $menu_of('Principale', 'it');
$menu_en = $menu_of('Main', 'en');
$menu_de = $menu_of('Haupt', 'de');
$lonely  = $menu_of('Sola', 'en');

check('a menu has a language, though nav_menu isn\'t a translated taxonomy', Core\language_of($menu_it, 'term') === 'it' && !Core\is_translated_taxonomy('nav_menu'));
check('a language\'s menus are only its own', !in_array($menu_it, array_map(fn ($m) => $m->term_id, Core\menus_in_language('en')), true) && in_array($menu_en, array_map(fn ($m) => $m->term_id, Core\menus_in_language('en')), true));

// **Translation of**, on the translation
WpGo\Languages\Admin\save_menu_original($menu_en, $menu_it);
WpGo\Languages\Admin\save_menu_original($menu_de, $menu_it);
same(['it' => $menu_it, 'en' => $menu_en, 'de' => $menu_de], Core\translations($menu_it, 'term'), 'linked from the translations: one group');
same($menu_it, Core\original($menu_en, 'term'), 'the default language\'s the original');

$other_it = $menu_of('Altro', 'it');
same([$menu_it, $other_it], array_values(array_intersect([$menu_it, $other_it], array_map(fn ($m) => $m->term_id, WpGo\Languages\Admin\menu_choices($menu_en)))), 'Translation of offers the default language\'s: the one it translates, and those free');
check('never one of another language', !array_filter(WpGo\Languages\Admin\menu_choices($menu_en), fn ($m) => Core\language_of($m->term_id, 'term') !== Core\default_language()));
$other_en = $menu_of('Other', 'en');
check('not one with a translation in its language already', !in_array($menu_it, array_map(fn ($m) => $m->term_id, WpGo\Languages\Admin\menu_choices($other_en)), true));
WpGo\Languages\Admin\save_menu_original($other_en, $menu_it);
same($menu_en, Core\translation($menu_it, 'en', false, 'term'), 'and a forged post can\'t take its place');
WpGo\Languages\Admin\save_menu_original($menu_it, $other_it);
same($menu_it, Core\original($menu_it, 'term'), 'the default language\'s menu translates none');

WpGo\Languages\Admin\save_menu_original($menu_en, $other_it);
same($other_it, Core\original($menu_en, 'term'), 'another chosen moves it');
same(['it' => $menu_it, 'de' => $menu_de], Core\translations($menu_it, 'term'), 'out of the one before');
WpGo\Languages\Admin\save_menu_original($menu_en, 0);
same(false, Core\is_translation($menu_en, 'term'), 'none: it\'s on its own');
WpGo\Languages\Admin\save_menu_original($menu_en, $menu_it);

// the menu select
$GLOBALS['pagenow'] = 'nav-menus.php';
$state = [Core\State::$filter, Core\State::$context];
Core\State::$filter = 'en';
$listed = array_map(fn ($m) => $m->term_id, wp_get_nav_menus());
check('the menu select lists the language picked', in_array($menu_en, $listed, true) && !in_array($menu_it, $listed, true));
same(0, WpGo\Languages\Admin\recently_edited_menu($menu_it), 'core doesn\'t open the last edited, in another language');
same($menu_en, WpGo\Languages\Admin\recently_edited_menu($menu_en), 'it does in this one');
$_REQUEST['menu'] = (string) $menu_it;
check('a menu asked for by its ID is listed, whatever its language', in_array($menu_it, array_map(fn ($m) => $m->term_id, wp_get_nav_menus()), true));
unset($_REQUEST['menu']);
Core\State::$filter = null;
check('all languages: every menu', in_array($menu_it, array_map(fn ($m) => $m->term_id, wp_get_nav_menus()), true) && in_array($menu_en, array_map(fn ($m) => $m->term_id, wp_get_nav_menus()), true));
$_GET['action'] = 'locations';
Core\State::$filter = 'en';
$listed = array_map(fn ($m) => $m->term_id, wp_get_nav_menus());
check('Manage Locations lists the default language\'s, whatever is picked', in_array($menu_it, array_map(fn ($m) => $m->term_id, array_filter(get_terms(['taxonomy' => 'nav_menu', 'hide_empty' => false]), fn ($m) => in_array($m->term_id, $listed, true))), true) && !in_array($menu_en, $listed, true));
unset($_GET['action']);
Core\State::$filter = $state[0];
check('the panel is printed before the footer\'s scripts', has_action('admin_footer', 'WpGo\\Languages\\Admin\\menu_panel') !== false);
$GLOBALS['pagenow'] = '';

// a location holds the default's: other languages through the links
set_theme_mod('nav_menu_locations', ['main' => $menu_it, 'wgt_none' => 0]);
$main = fn () => get_nav_menu_locations()['main'] ?? null;
same($menu_it, wgt_as_site('it', $main), 'a location, on the site: in the default language, its menu');
same($menu_en, wgt_as_site('en', $main), 'in English, the one linked');
same($menu_it, $main(), 'off the site, as stored');
WpGo\Languages\Admin\save_menu_original($menu_de, 0);
same(0, wgt_as_site('de', $main), 'a language with none linked shows none');
WpGo\Languages\Admin\save_menu_original($menu_de, $menu_it);

// following
$p_it  = wgt_page('Pagina menu', '', 'it', ['post_name' => 'wgt-menu-it']);
$p_en  = (int) WpGoLanguages::create_translation($p_it, 'en');
wp_update_post(['ID' => $p_en, 'post_status' => 'publish', 'post_title' => 'Menu page', 'post_name' => 'wgt-menu-en']);
$kid_it = wgt_page('Figlia menu', '', 'it', ['post_name' => 'wgt-kid-it']);
$kid_en = (int) WpGoLanguages::create_translation($kid_it, 'en');
wp_update_post(['ID' => $kid_en, 'post_status' => 'publish', 'post_title' => 'Child page', 'post_name' => 'wgt-kid-en']);
$no_en  = wgt_page('Senza inglese', '', 'it', ['post_name' => 'wgt-no-en']);
$label_it = wgt_page('Con etichetta', '', 'it', ['post_name' => 'wgt-label-it']);
$label_en = (int) WpGoLanguages::create_translation($label_it, 'en');
wp_update_post(['ID' => $label_en, 'post_status' => 'publish', 'post_name' => 'wgt-label-en']);

$add = fn (int $menu, array $fields) => wp_update_nav_menu_item($menu, 0, $fields + ['menu-item-status' => 'publish']);
$page_item = fn (int $menu, int $page, int $parent = 0, string $title = '') => $add($menu, ['menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $page, 'menu-item-parent-id' => $parent, 'menu-item-title' => $title]);
$top    = $page_item($menu_it, $p_it);
$gone   = $page_item($menu_it, $no_en, $top);
$under  = $page_item($menu_it, $kid_it, $gone);
$labeled = $page_item($menu_it, $label_it, 0, 'Etichetta propria');
$custom = $add($menu_it, ['menu-item-type' => 'custom', 'menu-item-url' => 'https://example.test/it', 'menu-item-title' => 'Esterno']);
$own_en = $page_item($menu_en, $p_en, 0, 'Own English item');

$items = fn (int $menu) => array_map(fn ($item) => [$item->title, $item->url, (int) $item->db_id], wp_get_nav_menu_items($menu));
same(['Own English item'], array_column($items($menu_en), 0), 'not following: its own items');

update_term_meta($menu_en, Core\MENU_FOLLOW_META, 1);
check('following', Core\menu_follows($menu_en));
$shown = wp_get_nav_menu_items($menu_en);
$by = [];
foreach ($shown as $item) {
    $by[(int) $item->db_id] = $item;
}
same([$top, $under, $labeled, $custom], array_map(fn ($item) => (int) $item->db_id, $shown), 'following: the original\'s items, one with no translation left out');
same(['Menu page', get_permalink($p_en), $p_en], [$by[$top]->title, $by[$top]->url, (int) $by[$top]->object_id], 'a page: its translation\'s title, link and id');
same(['Etichetta propria', get_permalink($label_en)], [$by[$labeled]->title, $by[$labeled]->url], 'a label of its own stays, its link translated');
same(['Esterno', 'https://example.test/it'], [$by[$custom]->title, $by[$custom]->url], 'a custom link as it is');
same((string) $top, (string) $by[$under]->menu_item_parent, 'the child of one left out moves up');
same(get_permalink($p_it), wp_get_nav_menu_items($menu_it)[0]->url, 'the original itself as it is');
same($own_en, (int) get_posts(['post_type' => 'nav_menu_item', 'post__in' => [$own_en], 'fields' => 'ids', 'post_status' => 'any'])[0], 'its own items are kept, hidden');

$GLOBALS['pagenow'] = 'nav-menus.php';
same(['Own English item'], array_column($items($menu_en), 0), 'on the Menus screen, its own');
$GLOBALS['pagenow'] = '';

delete_term_meta($menu_en, Core\MENU_FOLLOW_META);
same(['Own English item'], array_column($items($menu_en), 0), 'turned off, they\'re back');

update_term_meta($other_it, Core\MENU_FOLLOW_META, 1);
check('an original can\'t follow', !Core\menu_follows($other_it));

// the form's save
$_POST[WpGo\Languages\Admin\MENU_NONCE] = wp_create_nonce('wgl_menu');
wp_set_current_user((int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids'])[0]);
$_POST[WpGo\Languages\Admin\MENU_NONCE] = wp_create_nonce('wgl_menu');
$_POST['wgl_menu'] = ['follow' => '1', 'links' => []];
WpGo\Languages\Admin\save_menu_panel($menu_en);
check('the form turns it on', Core\menu_follows($menu_en));
$_POST['wgl_menu'] = ['links' => []];
WpGo\Languages\Admin\save_menu_panel($menu_en);
check('and off', !Core\menu_follows($menu_en));
$_POST['wgl_menu'] = ['language' => 'de'];
$fresh = (int) wp_create_nav_menu('Nuovo ' . wp_generate_uuid4());
same('de', Core\language_of($fresh, 'term'), 'a new menu takes the language its form says');
$_POST['wgl_menu'] = ['language' => 'en'];
WpGo\Languages\Admin\save_menu_panel($fresh);
same('de', Core\language_of($fresh, 'term'), 'then keeps it');
unset($_POST['wgl_menu']);
$state = Core\State::$filter;
Core\State::$filter = 'en';
unset($_POST[WpGo\Languages\Admin\MENU_NONCE]);
same('en', Core\language_of((int) wp_create_nav_menu('Dalla barra ' . wp_generate_uuid4()), 'term'), 'with no form, the language picked in the admin bar');
Core\State::$filter = $state;
wp_set_current_user(0);

// the locations of before
$legacy_it = $menu_of('Vecchio', 'it');
$legacy_en = (int) wp_create_nav_menu('Old ' . wp_generate_uuid4());
$bare_it   = (int) wp_create_nav_menu('Senza lingua ' . wp_generate_uuid4());
delete_term_meta($legacy_it, Core\TERM_LANGUAGE_META);
set_theme_mod('nav_menu_locations', ['main' => $legacy_it, 'main___en' => $legacy_en]);
Core\link_menus_from_locations('it');
same(['main' => $legacy_it], get_theme_mods()['nav_menu_locations'], 'the locations of before: one left');
same(['it' => $legacy_it, 'en' => $legacy_en], Core\translations($legacy_it, 'term'), 'the menus linked');
same(Core\default_language(), Core\language_of($bare_it, 'term'), 'any other menu the default\'s');

// a new default
set_theme_mod('nav_menu_locations', ['main' => $menu_it, 'other' => $other_it]);
Core\swap_menus('it', 'en');
same(['main' => $menu_en, 'other' => $other_it], get_theme_mods()['nav_menu_locations'], 'a new default: the location holds its menu; one with none stays');
same($menu_en, Core\original($menu_it, 'term'), 'its menu the original');

// deleted
wp_delete_nav_menu($menu_en);
same(false, in_array($menu_en, Core\translations($menu_it, 'term'), true), 'a menu deleted leaves its group');
check('its group has an original', !Core\is_translation(array_values(Core\translations($menu_it, 'term'))[0], 'term'));

set_theme_mod('nav_menu_locations', $saved_locations);

section('Terms: deleting an original, and what a copy points at');

register_taxonomy('wgt_ds', 'page', ['hierarchical' => true]);
add_filter('wp_go_languages/config', $with_ds = fn ($c) => array_replace($c, ['taxonomies' => array_merge((array) $c['taxonomies'], ['wgt_ds'])]));
Core\config(true);
Core\register_taxonomies();
$ds_term = function (string $name, string $code, array $args = []) {
    $made = Core\in_language($code, fn () => wp_insert_term($name, 'wgt_ds', $args));
    Core\set_language((int) $made['term_id'], $code, 'term');

    return (int) $made['term_id'];
};

$gone_it = $ds_term('Da cancellare', 'it');
$gone_en = (int) Core\create_term_translation($gone_it, 'en', 'text');
$gone_de = (int) Core\create_term_translation($gone_it, 'de', 'text');
wp_delete_term($gone_it, 'wgt_ds');
Core\forget_translations();
same(['en' => $gone_en, 'de' => $gone_de], Core\translations($gone_en, 'term'), 'the original term deleted: the others stay one group');
same($gone_en, Core\original($gone_de, 'term'), 'with one original');
same('', get_term_meta($gone_en, Core\SOURCE_META, true), 'it names none');
same((string) $gone_en, get_term_meta($gone_de, Core\SOURCE_META, true), 'and the other names it, not the one deleted');

$alone = $ds_term('Sola', 'it');
check('a term alone deletes as ever', wp_delete_term($alone, 'wgt_ds') === true);

acf_add_local_field_group(['key' => 'group_wgt_ds', 'title' => 'DS', 'fields' => [
    ['key' => 'field_wgt_ds_page', 'name' => 'wgt_ds_page', 'type' => 'post_object', 'post_type' => ['page']],
    ['key' => 'field_wgt_ds_text', 'name' => 'wgt_ds_text', 'type' => 'wysiwyg'],
], 'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'wgt_ds']]]]);
$ds_it = wgt_page('Pagina termine', '', 'it', ['post_name' => 'wgt-ds-it']);
$ds_en = (int) WpGoLanguages::create_translation($ds_it, 'en');
wp_update_post(['ID' => $ds_en, 'post_status' => 'publish', 'post_name' => 'wgt-ds-en']);
$link = '<a href="' . get_permalink($ds_it) . '">vai</a>';
$linked_term = $ds_term('Con link', 'it', ['description' => $link]);
update_field('field_wgt_ds_page', $ds_it, 'term_' . $linked_term);
update_field('field_wgt_ds_text', $link, 'term_' . $linked_term);
$linked_en_term = (int) Core\create_term_translation($linked_term, 'en');
same($ds_en, (int) get_term_meta($linked_en_term, 'wgt_ds_page', true), 'a term copied: its page field this language\'s');
check('a link in its text field', str_contains((string) get_term_meta($linked_en_term, 'wgt_ds_text', true), get_permalink($ds_en)));
check('and in its description', str_contains(get_term($linked_en_term)->description, get_permalink($ds_en)));
remove_filter('wp_go_languages/config', $with_ds);
Core\config(true);

section('A form: a field renamed in the original');

$rows_of = fn (array $rows) => $rows;
$form_rows = function (int $id, array $rows) {
    update_post_meta($id, 'cf7_fields', array_map(fn ($row) => $row[3] ?? 'text', $rows));
    update_post_meta($id, '_cf7_fields', 'field_wg_cf7_fields');

    foreach ($rows as $i => $row) {
        $layout = $row[3] ?? 'text';

        foreach (['label' => $row[0], 'name' => $row[1], 'help' => $row[2]] as $key => $value) {
            update_post_meta($id, "cf7_fields_{$i}_$key", $value);
            update_post_meta($id, "_cf7_fields_{$i}_$key", "field_wg_cf7_{$layout}_$key");
        }
    }
};
$labels = fn (int $id, int $i) => [get_post_meta($id, "cf7_fields_{$i}_label", true), get_post_meta($id, "cf7_fields_{$i}_name", true), get_post_meta($id, "cf7_fields_{$i}_help", true)];

$rf = (int) WPCF7_ContactForm::get_template(['title' => 'Modulo rinomina'])->save();
WpGoLanguages::set_language($rf, 'it');
$form_rows($rf, [['Nome', 'nome', 'Il nome'], ['Mail', 'mail', 'La mail']]);
$rf_en = (int) WpGoLanguages::create_translation($rf, 'en', 'text');
$form_rows($rf_en, [['Name', 'nome', ''], ['Email', 'mail', 'Your email']]);

$form_rows($rf, [['Nome', 'nome_completo', 'Il nome'], ['Mail', 'mail', 'La mail']]);
Core\cf7_sync_rows($rf, $rf_en);
same(['Name', 'nome_completo', ''], $labels($rf_en, 0), 'a field renamed: the new name, its texts kept, an emptied one too');
same(['Email', 'mail', 'Your email'], $labels($rf_en, 1), 'the other as it was');

$form_rows($rf, [['Mail', 'mail', 'La mail'], ['Nome', 'nome_completo', 'Il nome']]);
Core\cf7_sync_rows($rf, $rf_en);
same(['Name', 'nome_completo', ''], $labels($rf_en, 1), 'moved after that, by its name, as ever');

$form_rows($rf, [['Mail', 'mail', 'La mail'], ['Nome', 'nome_completo', 'Il nome'], ['Nuovo', 'nuovo', 'Altro']]);
Core\cf7_sync_rows($rf, $rf_en);
same(['Nuovo', 'nuovo', 'Altro'], $labels($rf_en, 2), 'a new one at the end starts with the original\'s');

$form_rows($rf, [['Mail', 'mail', 'La mail'], ['Scelta', 'scelta', 'Scegli', 'select'], ['Nuovo', 'nuovo', 'Altro']]);
Core\cf7_sync_rows($rf, $rf_en);
same(['Scelta', 'scelta', 'Scegli'], $labels($rf_en, 1), 'a field of another type at its place is a new one, not the old one renamed');

check('the wording has no page in it on a term or a form', !str_contains(WpGo\Languages\Admin\fields_mode_texts()['independentHelp'], 'page'));
