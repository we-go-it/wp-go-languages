<?php

// @require fixtures/third-language.php

/**
 * One fixed layout (Core/synced.php): the marks that make non-text fields follow, the
 * "+" dialog's flag making them on a new translation, and a text-only translation
 * stored in the mode that was converted, going on as it did. convert_text_only() isn't called here: its own
 * transaction would end this file's, and it reads every translation of the site.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;

acf_add_local_field_group([
    'key'      => 'group_wgt_tf',
    'title'    => 'Fixed layout',
    'fields'   => [
        ['key' => 'field_wgt_tf_title', 'name' => 'tf_title', 'label' => 'Title', 'type' => 'text'],
        ['key' => 'field_wgt_tf_size', 'name' => 'tf_size', 'label' => 'Size', 'type' => 'select', 'choices' => ['s' => 'S', 'l' => 'L']],
        ['key' => 'field_wgt_tf_cta', 'name' => 'tf_cta', 'label' => 'CTA', 'type' => 'link'],
        ['key' => 'field_wgt_tf_words', 'name' => 'tf_words', 'label' => 'Words', 'type' => 'repeater', 'sub_fields' => [
            ['key' => 'field_wgt_tf_words_text', 'name' => 'text', 'label' => 'Text', 'type' => 'text'],
            ['key' => 'field_wgt_tf_words_size', 'name' => 'size', 'label' => 'Size', 'type' => 'select', 'choices' => ['s' => 'S', 'l' => 'L']],
        ]],
        ['key' => 'field_wgt_tf_sizes', 'name' => 'tf_sizes', 'label' => 'Sizes', 'type' => 'repeater', 'sub_fields' => [
            ['key' => 'field_wgt_tf_sizes_size', 'name' => 'size', 'label' => 'Size', 'type' => 'select', 'choices' => ['s' => 'S', 'l' => 'L']],
        ]],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);

$refs = ['tf_title' => 'title', 'tf_size' => 'size', 'tf_cta' => 'cta', 'tf_words' => 'words', 'tf_sizes' => 'sizes'];
$flat = function (array $values) use ($refs) {
    $data = [];

    foreach ($values as $name => $value) {
        $data[$name] = $value;

        if (preg_match('/^tf_(words|sizes)_\d+_(\w+)$/', $name, $match)) {
            $data['_' . $name] = 'field_wgt_tf_' . $match[1] . '_' . $match[2];
        } else {
            $data['_' . $name] = 'field_wgt_tf_' . $refs[$name];
        }
    }

    return $data;
};
$block = fn (string $id, array $values) => [
    'blockName'    => 'acf/wgt-tf',
    'attrs'        => ['name' => 'acf/wgt-tf', 'data' => $flat($values), 'metadata' => ['wgId' => $id]],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
];
$values = fn (int $id) => wgt_blocks($id)[0]['attrs']['data'] ?? [];
$marks  = fn (int $id) => wgt_blocks($id)[0]['attrs']['metadata'][Core\SYNCED_BLOCK] ?? [];
$start  = [
    'tf_title' => 'Titolo',
    'tf_size'  => 's',
    'tf_cta'   => ['title' => 'Scopri', 'url' => 'https://example.test/it', 'target' => ''],
    'tf_words' => 1,
    'tf_words_0_text' => 'Uno',
    'tf_words_0_size' => 's',
    'tf_sizes' => 1,
    'tf_sizes_0_size' => 's',
];
$set_original = function (int $id, array $changes) use ($flat) {
    $list = wgt_blocks($id);
    $list[0]['attrs']['data'] = $flat($changes) + $list[0]['attrs']['data'];
    wgt_save($id, $list);
};
$page_fields = function (int $id, array $fields) {
    foreach ($fields as $name => $value) {
        update_post_meta($id, $name, $value);
        update_post_meta($id, '_' . $name, 'field_wgt_tf_' . substr($name, 3));
    }
};


section('What makes non-text fields follow');

same(['tf_size', 'tf_sizes'], Core\non_text_marks($flat($start), false), 'rows that don\'t follow: a repeater with no words whole, not one with them; no text, no link');
same(['tf_size', 'tf_words_0_size', 'tf_sizes'], Core\non_text_marks($flat($start), true), 'rows that follow: a cell of those holding text, the others whole');
check('a repeater holding words holds text', Core\holds_text(Core\block_field('field_wgt_tf_words')));
check('one of settings doesn\'t', !Core\holds_text(Core\block_field('field_wgt_tf_sizes')));


section('The flag, on an independent translation');

$it = wgt_page('Bandiera', serialize_blocks([$block('tf-a', $start)]));
$page_fields($it, ['tf_size' => 's', 'tf_title' => 'Titolo pagina']);
$en = (int) WpGoLanguages::create_translation($it, 'en', 'independent');
same([], $marks($en), 'without it: nothing linked');
same('', get_post_meta($en, Core\SYNCED_META, true), 'in its page\'s fields either');

$added = Core\link_non_text($en);
same(['tf_size', 'tf_sizes'], $marks($en), 'with it: its block\'s non-text fields');
same(['tf_size', Core\THUMBNAIL_MARK], get_post_meta($en, Core\SYNCED_META, true), 'and its page\'s, and its featured image');
same(['blocks' => 2, 'fields' => 2], $added, 'and it says so');

$set_original($it, ['tf_size' => 'l', 'tf_title' => 'Titolo nuovo']);
same('l', $values($en)['tf_size'], 'they follow the original');
same('Titolo', $values($en)['tf_title'], 'its text doesn\'t');

same(['blocks' => 0, 'fields' => 0], Core\link_non_text($en), 'again: nothing to add');
same(['blocks' => 0, 'fields' => 0], Core\link_non_text($it), 'an original follows nothing');

$list = wgt_blocks($en);
unset($list[0]['attrs']['metadata'][Core\SYNCED_BLOCK]);
wgt_save($en, $list);
same('l', $values($en)['tf_size'], 'unlinked: it keeps what it has, as it never had a value of its own to go back to');


section('The flag, on a fixed layout, starting empty');

$it2 = wgt_page('Bandiera vuota', serialize_blocks([$block('tf-b', $start)]));
$de  = (int) WpGoLanguages::create_translation($it2, 'de', 'blocks', false);
Core\link_non_text($de);
same(['tf_size', 'tf_words_0_size', 'tf_sizes'], $marks($de), 'its rows follow: a cell of those holding text, the others whole');
same('s', $values($de)['tf_size'], 'linked, it has the original\'s at once');


section('A text-only translation converted');

$it3 = wgt_page('Conversione', serialize_blocks([$block('tf-c', $start)]));
$page_fields($it3, ['tf_size' => 's', 'tf_title' => 'Titolo pagina']);
$old = (int) WpGoLanguages::create_translation($it3, 'en', 'blocks');
wp_update_post(['ID' => $old, 'post_status' => 'publish']);
update_post_meta($old, Core\MODE_META, 'text');

$list = wgt_blocks($old);
$list[0]['attrs']['data']['tf_title'] = 'Title';
$list[0]['attrs']['data']['tf_cta']   = ['title' => 'Discover', 'url' => 'https://example.test/en', 'target' => ''];
$list[0]['attrs']['data']['tf_words_0_text'] = 'One';
wgt_save($old, $list);
update_post_meta($old, 'tf_title', 'Page title');

same('text', get_post_meta($old, Core\MODE_META, true), 'to start with: text only, as stored');
same('blocks', Admin\panel_data($old)['mode'], 'read as the fixed layout even so');
same('blocks', Core\normalize_mode('text'), 'asked for by its old name too');
$before = $values($old);

update_post_meta($old, Core\LEGACY_ROWS_META, ['x' => 1]);
$report = Core\conversion_report();
Core\convert_text_post($old, $report);

same('blocks', get_post_meta($old, Core\MODE_META, true), 'converted: the fixed layout');
same(['page' => ['blocks' => 1]], $report['types'], 'and the report says so');
same(['tf_size', 'tf_words_0_size', 'tf_sizes'], $marks($old), 'its non-text fields linked, those of its rows too');
same(['tf_size', Core\THUMBNAIL_MARK], get_post_meta($old, Core\SYNCED_META, true), 'its page\'s too, and its featured image');
same($before, $values($old), 'nothing else in it changed');
same([], $report['changes'], 'nor would its next save change anything');
same('', get_post_meta($old, Core\LEGACY_ROWS_META, true), 'what its rows last followed is gone');

wgt_save($old, wgt_blocks($old));
same($before, $values($old), 'saved, it\'s the same');

$set_original($it3, ['tf_size' => 'l', 'tf_title' => 'Titolo nuovo', 'tf_words_0_size' => 'l', 'tf_words_0_text' => 'Uno nuovo']);
same('l', $values($old)['tf_size'], 'the original changes: its setting follows, as text-only did');
same('l', $values($old)['tf_words_0_size'], 'in a row too');
same('Title', $values($old)['tf_title'], 'its text stays its own');
same('One', $values($old)['tf_words_0_text'], 'in a row too');
same('Discover', $values($old)['tf_cta']['title'], 'and a link');

$list = wgt_blocks($old);
$list[0]['attrs']['metadata'][Core\SYNCED_BLOCK] = ['tf_words_0_size', 'tf_sizes_0_size'];
wgt_save($old, $list);
same('l', $values($old)['tf_size'], 'a field unlinked: it keeps what it has, and is its own to change');

$report = Core\conversion_report();
Core\convert_text_post($old, $report);
same(['page' => ['blocks' => 1]], $report['types'], 'run on one again it only adds what\'s missing');


section('The featured image');

$image = fn (string $name) => wp_insert_attachment(['post_title' => $name, 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg'], false);
$one = (int) $image('uno');
$two = (int) $image('due');
$own = (int) $image('proprio');
$thumb = fn (int $id) => (string) get_post_meta($id, Core\THUMBNAIL_MARK, true);

$fi = wgt_page('Immagine', serialize_blocks([$block('tf-d', $start)]));
update_post_meta($fi, Core\THUMBNAIL_MARK, (string) $one);
$fe = (int) WpGoLanguages::create_translation($fi, 'en', 'blocks');
wp_update_post(['ID' => $fe, 'post_status' => 'publish']);
update_post_meta($fe, Core\THUMBNAIL_MARK, (string) $own);

same(false, Admin\panel_data($fe)['thumbnail']['linked'], 'not linked to start with');
same($one, Admin\panel_data($fe)['thumbnail']['original'], 'the panel knows the original\'s');

Core\set_thumbnail_link($fe, true);
same([Core\THUMBNAIL_MARK], Core\synced_marks($fe), 'linked: a mark');
Core\pull_fields($fe, $fi);
same((string) $one, $thumb($fe), 'it follows the original\'s');
same((int) $own, Admin\panel_data($fe)['thumbnail']['before'], 'what it had is kept');

update_post_meta($fi, Core\THUMBNAIL_MARK, (string) $two);
wp_update_post(['ID' => $fi, 'post_title' => 'Immagine due']);
same((string) $two, $thumb($fe), 'the original\'s changes: so does it');

delete_post_meta($fi, Core\THUMBNAIL_MARK);
wp_update_post(['ID' => $fi, 'post_title' => 'Immagine senza']);
same('', $thumb($fe), 'the original\'s goes: so does it');

// a save of the editor, its image sent with it: the one it found is what's kept
$fi4 = wgt_page('Immagine salvata', serialize_blocks([$block('tf-g', $start)]));
update_post_meta($fi4, Core\THUMBNAIL_MARK, (string) $one);
$fe4 = (int) WpGoLanguages::create_translation($fi4, 'en', 'blocks');
wp_update_post(['ID' => $fe4, 'post_status' => 'publish']);
update_post_meta($fe4, Core\THUMBNAIL_MARK, (string) $own);
wp_update_post(['ID' => $fe4, 'post_title' => 'Saved']);
update_post_meta($fe4, Core\THUMBNAIL_MARK, (string) $one);
Core\set_thumbnail_link($fe4, true, Core\found_thumbnail($fe4));
same($own, Admin\panel_data($fe4)['thumbnail']['before'], 'ticked in the editor, which sent the original\'s: what it had is kept');

wp_update_post(['ID' => $fe4, 'post_title' => 'Saved again']);
Core\set_thumbnail_link($fe4, false, Core\found_thumbnail($fe4));
same((string) $own, $thumb($fe4), 'unticked, untouched: it goes back');
same('', get_post_meta($fe4, Core\SYNCED_BEFORE, true), 'and nothing\'s kept');

Core\set_thumbnail_link($fe4, true);
Core\pull_fields($fe4, $fi4);
wp_update_post(['ID' => $fe4, 'post_title' => 'Saved a third time']);
update_post_meta($fe4, Core\THUMBNAIL_MARK, (string) $two);
Core\set_thumbnail_link($fe4, false, Core\found_thumbnail($fe4));
same((string) $two, $thumb($fe4), 'unticked, another picked with it: that one stays');

$fi2 = wgt_page('Immagine flag', serialize_blocks([$block('tf-e', $start)]));
update_post_meta($fi2, Core\THUMBNAIL_MARK, (string) $one);
$fe2 = (int) WpGoLanguages::create_translation($fi2, 'en', 'independent');
Core\link_non_text($fe2);
check('the flag links it, as an image', in_array(Core\THUMBNAIL_MARK, Core\synced_marks($fe2), true));
same((string) $one, $thumb($fe2), 'with the original\'s');

$fi3 = wgt_page('Immagine testo', serialize_blocks([$block('tf-f', $start)]));
update_post_meta($fi3, Core\THUMBNAIL_MARK, (string) $one);
$fe3 = (int) WpGoLanguages::create_translation($fi3, 'en', 'blocks');
wp_update_post(['ID' => $fe3, 'post_status' => 'publish']);
update_post_meta($fe3, Core\MODE_META, 'text');
$report = Core\conversion_report();
Core\convert_text_post($fe3, $report);
same(1, $report['thumbnails'], 'converted, it\'s reported');
check('and linked', in_array(Core\THUMBNAIL_MARK, Core\synced_marks($fe3), true));
same([], $report['changes'], 'with nothing changed');
update_post_meta($fi3, Core\THUMBNAIL_MARK, (string) $two);
wp_update_post(['ID' => $fi3, 'post_title' => 'Immagine testo due']);
same((string) $two, $thumb($fe3), 'and goes on following, as text-only did');


section('What isn\'t a page');

$form = (int) WPCF7_ContactForm::get_template(['title' => 'Modulo'])->save();
WpGoLanguages::set_language($form, 'it');
$form_en = (int) WpGoLanguages::create_translation($form, 'en', 'blocks');
update_post_meta($form_en, Core\MODE_META, 'text');
check('stored as text, the mode that was, it still counts as fixed', Core\is_fixed($form_en));
$report = Core\conversion_report();
Core\convert_text_post($form_en, $report);
same('blocks', get_post_meta($form_en, Core\MODE_META, true), 'a form is just fixed, its own way');
same(1, $report['forms'], 'and is counted so');

register_taxonomy('wgt_tf_topic', 'page', ['public' => true, 'show_in_rest' => true]);
add_filter('wp_go_languages/config', fn ($config) => array_replace($config, ['taxonomies' => [...$config['taxonomies'], 'wgt_tf_topic']]));
Core\config(true);
$term_it = (int) WpGoLanguages::in('it', fn () => wp_insert_term('Categoria conversione', 'wgt_tf_topic')['term_id']);
$term_en = (int) Core\create_term_translation($term_it, 'en');
update_term_meta($term_en, Core\MODE_META, 'text');
same('independent', Core\mode_of($term_en, 'term'), 'a term stored as text only reads as independent');
$report = Core\conversion_report();
Core\convert_text_term($term_en, $report);
same('independent', get_term_meta($term_en, Core\MODE_META, true), 'converted: independent, having no layout');
same(1, $report['terms'], 'and counted');
