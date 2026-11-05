<?php

// @require fixtures/third-language.php

/**
 * Synced fields (Core/synced.php, Admin/synced-fields.php): a translation's field
 * ticked to follow its group's original, in every mode; a link's page and label
 * apart, its page always the translated one; a repeater whole where rows don't
 * follow, by cell where they do. Blocks, page fields, terms, options. Whoever
 * saves, the value is the original's; AI translation leaves it alone.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

$fields = [
    ['key' => 'field_wgt_sy_title', 'name' => 'sy_title', 'label' => 'Title', 'type' => 'text'],
    ['key' => 'field_wgt_sy_free', 'name' => 'sy_free', 'label' => 'Free', 'type' => 'text'],
    ['key' => 'field_wgt_sy_size', 'name' => 'sy_size', 'label' => 'Size', 'type' => 'select', 'choices' => ['s' => 'S', 'l' => 'L']],
    ['key' => 'field_wgt_sy_cta', 'name' => 'sy_cta', 'label' => 'CTA', 'type' => 'link'],
    ['key' => 'field_wgt_sy_page', 'name' => 'sy_page', 'label' => 'Page', 'type' => 'post_object', 'post_type' => ['page']],
    ['key' => 'field_wgt_sy_rows', 'name' => 'sy_rows', 'label' => 'Rows', 'type' => 'repeater', 'sub_fields' => [
        ['key' => 'field_wgt_sy_rows_text', 'name' => 'text', 'label' => 'Text', 'type' => 'text'],
        ['key' => 'field_wgt_sy_rows_size', 'name' => 'size', 'label' => 'Size', 'type' => 'select', 'choices' => ['s' => 'S', 'l' => 'L']],
    ]],
    ['key' => 'field_wgt_sy_group', 'name' => 'sy_group', 'label' => 'Group', 'type' => 'group', 'sub_fields' => [
        ['key' => 'field_wgt_sy_group_note', 'name' => 'note', 'label' => 'Note', 'type' => 'text'],
    ]],
];

acf_add_local_field_group([
    'key'      => 'group_wgt_sy',
    'title'    => 'Synced fields',
    'fields'   => $fields,
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);

add_filter('wp_go_languages/field_policy', fn ($policy, $field) => ($field['key'] ?? '') === 'field_wgt_sy_ignored' ? 'ignore' : $policy, 10, 2);

// a block's flat data, its references beside its values
$refs = ['sy_title' => 'title', 'sy_free' => 'free', 'sy_size' => 'size', 'sy_cta' => 'cta', 'sy_page' => 'page', 'sy_rows' => 'rows', 'sy_group_note' => 'group_note'];
$flat = function (array $values) use ($refs) {
    $data = [];

    foreach ($values as $name => $value) {
        $data[$name] = $value;

        if (preg_match('/^sy_rows_\d+_(text|size)$/', $name, $match)) {
            $data['_' . $name] = 'field_wgt_sy_rows_' . $match[1];
        } else {
            $data['_' . $name] = 'field_wgt_sy_' . $refs[$name];
        }
    }

    return $data;
};
$block = fn (string $id, array $values, array $marks = []) => [
    'blockName'    => 'acf/wgt-sy',
    'attrs'        => ['name' => 'acf/wgt-sy', 'data' => $flat($values), 'metadata' => ['wgId' => $id] + ($marks ? [Core\SYNCED_BLOCK => $marks] : [])],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
];
$values = fn (int $id, int $i = 0) => wgt_blocks($id)[$i]['attrs']['data'] ?? [];
$marks_of = fn (int $id, int $i = 0) => wgt_blocks($id)[$i]['attrs']['metadata'][Core\SYNCED_BLOCK] ?? [];
// a save of what the editor holds, with these values changed, these marks on it
$edit = function (int $id, array $changes, ?array $marks = null, int $i = 0) {
    $list = wgt_blocks($id);
    $list[$i]['attrs']['data'] = $changes + $list[$i]['attrs']['data'];

    if ($marks !== null) {
        $list[$i]['attrs']['metadata'][Core\SYNCED_BLOCK] = $marks;
    }

    wgt_save($id, $list);
};

// pages to link to: one translated, its translation's URL what a link should become
$target = wgt_page('Destinazione', '');
$target_en = WpGoLanguages::create_translation($target, 'en');
wp_update_post(['ID' => $target_en, 'post_status' => 'publish', 'post_title' => 'Destination', 'post_name' => 'destination']);
$target_url = get_permalink($target);
$target_en_url = get_permalink($target_en);

$start = [
    'sy_title' => 'Titolo',
    'sy_free'  => 'Libero',
    'sy_size'  => 's',
    'sy_cta'   => ['title' => 'Scopri', 'url' => $target_url, 'target' => ''],
    'sy_page'  => (string) $target,
    'sy_rows'  => 2,
    'sy_rows_0_text' => 'Uno',
    'sy_rows_0_size' => 's',
    'sy_rows_1_text' => 'Due',
    'sy_rows_1_size' => 'l',
    'sy_group_note'  => 'Nota',
];


section('In a block, independent');

$it = wgt_page('Sync indipendente', serialize_blocks([$block('sy-a', $start)]));
$en = (int) WpGoLanguages::create_translation($it, 'en', 'independent');
same($target_en_url, $values($en)['sy_cta']['url'] ?? null, 'to start with, a copy points at the translated page');

$edit($en, ['sy_title' => 'Title', 'sy_free' => 'Free', 'sy_cta' => ['title' => 'Discover', 'url' => 'https://example.test/own', 'target' => ''], 'sy_rows_0_text' => 'One', 'sy_size' => 'l']);
same('Title', $values($en)['sy_title'], 'nothing ticked: its own');

$edit($en, [], ['sy_title', 'sy_cta', 'sy_size']);
same('Titolo', $values($en)['sy_title'], 'ticked: the original\'s, on the translation\'s own save');
same('s', $values($en)['sy_size'], 'a select too: every field type, not only text');
same('Free', $values($en)['sy_free'], 'one not ticked stays its own');
same(['sy_title', 'sy_cta', 'sy_size'], $marks_of($en), 'the marks stay on the block');

$link = $values($en)['sy_cta'];
same($target_en_url, $link['url'], 'a link\'s page ticked: the translated page');
same('Discover', $link['title'], 'its label stays free');

$edit($en, ['sy_cta' => ['title' => 'Find out', 'url' => 'https://example.test/typed', 'target' => '_blank']]);
same('Find out', $values($en)['sy_cta']['title'], 'the label can still be changed');
same($target_en_url, $values($en)['sy_cta']['url'], 'the page can\'t');
same('', $values($en)['sy_cta']['target'], 'nor where it opens');

$edit($en, [], ['sy_title', 'sy_cta', 'sy_cta#title', 'sy_size']);
same('Scopri', $values($en)['sy_cta']['title'], 'its label ticked too: the original\'s');

$edit($en, [], ['sy_cta#title']);
$edit($en, ['sy_cta' => ['title' => 'x', 'url' => 'https://example.test/own', 'target' => '']]);
same('Scopri', $values($en)['sy_cta']['title'], 'its label alone ticked: the label is the original\'s');
same('https://example.test/own', $values($en)['sy_cta']['url'], 'and the page its own');

$edit($en, [], ['sy_title', 'sy_page', 'sy_cta']);
same((string) $target_en, $values($en)['sy_page'], 'a picked page: the translated one');

$edit($it, ['sy_title' => 'Titolo nuovo', 'sy_size' => 'l', 'sy_free' => 'Libero nuovo']);
same('Titolo nuovo', $values($en)['sy_title'], 'the original saved: a ticked field follows, on an independent translation');
same('Free', $values($en)['sy_free'], 'one not ticked doesn\'t');

$edit($en, ['sy_title' => 'Typed anyway']);
same('Titolo nuovo', $values($en)['sy_title'], 'a stale editor\'s value doesn\'t stick');

$edit($en, [], []);
$edit($en, ['sy_title' => 'Title again']);
same('Title again', $values($en)['sy_title'], 'unticked: its own again');
$edit($it, ['sy_title' => 'Titolo terzo']);
same('Title again', $values($en)['sy_title'], 'and the original no longer changes it');


section('A repeater, whole, where rows don\'t follow');

$edit($en, ['sy_rows' => 1, 'sy_rows_0_text' => 'Own row'], ['sy_rows']);
same(2, (int) $values($en)['sy_rows'], 'a repeater ticked: the original\'s rows');
same('Uno', $values($en)['sy_rows_0_text'], 'and their texts');
same('Due', $values($en)['sy_rows_1_text'], 'each of them');

$rows_it = $values($it);
$edit($it, ['sy_rows' => 1]);
$list = wgt_blocks($it);
unset($list[0]['attrs']['data']['sy_rows_1_text'], $list[0]['attrs']['data']['_sy_rows_1_text'], $list[0]['attrs']['data']['sy_rows_1_size'], $list[0]['attrs']['data']['_sy_rows_1_size']);
wgt_save($it, $list);
same(1, (int) $values($en)['sy_rows'], 'a row gone from the original goes');
check('with its cells', !array_key_exists('sy_rows_1_text', $values($en)));

$edit($en, ['sy_rows' => 3, 'sy_rows_2_text' => 'x', '_sy_rows_2_text' => 'field_wgt_sy_rows_text'], ['sy_rows_0_text']);
same('Uno', $values($en)['sy_rows_0_text'], 'unticked, the rows are its own again, as they were');
check('a cell\'s mark does nothing here: rows don\'t line up', Core\live_marks(['sy_rows_0_text'], $values($en), false) === []);


section('A saved twice, nothing changes');

$before = wgt_content($en);
wgt_save($en, wgt_blocks($en));
same($before, wgt_content($en), 'the translation\'s save is stable');
$edit($en, ['sy_title' => 'Stable'], ['sy_title']);
$before = wgt_content($en);
wgt_save($it, wgt_blocks($it));
same($before, wgt_content($en), 'so is the original\'s, with marks on');


section('A repeat of a block');

$list = wgt_blocks($en);
$list[] = $list[0];
wgt_save($en, $list);
$repeat = wgt_blocks($en)[1] ?? [];
check('a Duplicate gets a new ID', wgt_id($repeat) !== 'sy-a' && wgt_id($repeat) !== '');
same([], $repeat['attrs']['metadata'][Core\SYNCED_BLOCK] ?? [], 'and no marks: the original has no such block');


section('In a block, fixed');

$fixed_it = wgt_page('Sync fisso', serialize_blocks([$block('sy-b', $start)]));
$text = (int) WpGoLanguages::create_translation($fixed_it, 'en', 'text');
$edit($text, ['sy_title' => 'Title', 'sy_free' => 'Free', 'sy_rows_0_text' => 'One', 'sy_rows_1_text' => 'Two']);
$edit($text, [], ['sy_rows_1_text']);
same('Due', $values($text)['sy_rows_1_text'], 'text only: a row\'s cell ticked follows');
same('One', $values($text)['sy_rows_0_text'], 'the other cell doesn\'t');

// the original's rows swapped: the mark goes with its row
$edit($fixed_it, ['sy_rows_0_text' => 'Due', 'sy_rows_0_size' => 'l', 'sy_rows_1_text' => 'Uno', 'sy_rows_1_size' => 's']);
same(['sy_rows_0_text'], $marks_of($text), 'rows moved in the original: the mark moves with its row');
same('Due', $values($text)['sy_rows_0_text'], 'still following');
same('One', $values($text)['sy_rows_1_text'], 'and the translated cell moved too');

// its row taken out, the other one left: all in one save, as the editor makes it
$list = wgt_blocks($fixed_it);
$list[0]['attrs']['data']['sy_rows'] = 1;
$list[0]['attrs']['data']['sy_rows_0_text'] = 'Uno';
$list[0]['attrs']['data']['sy_rows_0_size'] = 's';
unset($list[0]['attrs']['data']['sy_rows_1_text'], $list[0]['attrs']['data']['_sy_rows_1_text'], $list[0]['attrs']['data']['sy_rows_1_size'], $list[0]['attrs']['data']['_sy_rows_1_size']);
wgt_save($fixed_it, $list);
same([], $marks_of($text), 'its row gone: the mark goes');

$blocks = (int) WpGoLanguages::create_translation($fixed_it, 'de', 'blocks');
$edit($blocks, ['sy_size' => 'l', 'sy_free' => 'Frei', 'sy_title' => 'Titel']);
same('l', $values($blocks)['sy_size'], 'fixed blocks: a select is its own');
$edit($blocks, [], ['sy_size']);
same('s', $values($blocks)['sy_size'], 'ticked: the original\'s');
same('Frei', $values($blocks)['sy_free'], 'the rest stays');


section('Waiting for a draft original');

$draft_it = wgt_page('Sync bozza', serialize_blocks([$block('sy-c', $start)]));
$draft_en = (int) WpGoLanguages::create_translation($draft_it, 'en', 'independent');
wp_update_post(['ID' => $draft_en, 'post_status' => 'publish']);
$edit($draft_en, [], ['sy_title']);
wp_update_post(['ID' => $draft_it, 'post_status' => 'draft']);
$edit($draft_it, ['sy_title' => 'Non ancora']);
same('Titolo', $values($draft_en)['sy_title'], 'a published translation doesn\'t take a draft\'s changes');
wp_update_post(['ID' => $draft_it, 'post_status' => 'publish']);
same('Non ancora', $values($draft_en)['sy_title'], 'until it\'s published');


section('A link whose target isn\'t translated yet');

$later = wgt_page('Pagina dopo', '');
$later_url = get_permalink($later);
$link_it = wgt_page('Sync link', serialize_blocks([$block('sy-d', ['sy_cta' => ['title' => 'Vai', 'url' => $later_url, 'target' => '']])]));
$link_en = (int) WpGoLanguages::create_translation($link_it, 'en', 'independent');
wp_update_post(['ID' => $link_en, 'post_status' => 'publish']);
$edit($link_en, [], ['sy_cta']);
same($later_url, $values($link_en)['sy_cta']['url'], 'no translation yet: the original\'s page');
same(1, count(array_filter(Core\link_warnings($link_en), fn ($warning) => $warning['id'] === $later)), 'and the panel warns');

$later_en = (int) WpGoLanguages::create_translation($later, 'en', 'independent');
same($later_url, $values($link_en)['sy_cta']['url'], 'a draft translation changes nothing');
wp_update_post(['ID' => $later_en, 'post_status' => 'publish', 'post_title' => 'Page later', 'post_name' => 'page-later']);
same(get_permalink($later_en), $values($link_en)['sy_cta']['url'], 'published: the link goes to it, nothing else saved');
wp_update_post(['ID' => $later_en, 'post_status' => 'draft']);
same($later_url, $values($link_en)['sy_cta']['url'], 'taken down: back to the original\'s page');


section('A new original');

$new_it = wgt_page('Sync originale', serialize_blocks([$block('sy-e', $start)]));
$new_en = (int) WpGoLanguages::create_translation($new_it, 'en', 'independent');
$edit($new_en, [], ['sy_title']);
update_post_meta($new_en, Core\SYNCED_META, ['sy_free']);
Core\make_original($new_en);
same([], $marks_of($new_en), 'made the original: it follows nothing');
same('', get_post_meta($new_en, Core\SYNCED_META, true), 'in its fields either');


section('A page\'s fields, independent');

$page_it = wgt_page('Campi pagina', '');
update_field('field_wgt_sy_title', 'Titolo', $page_it);
update_field('field_wgt_sy_free', 'Libero', $page_it);
update_field('field_wgt_sy_cta', ['title' => 'Scopri', 'url' => $target_url, 'target' => ''], $page_it);
update_field('field_wgt_sy_rows', [['field_wgt_sy_rows_text' => 'Uno', 'field_wgt_sy_rows_size' => 's'], ['field_wgt_sy_rows_text' => 'Due', 'field_wgt_sy_rows_size' => 'l']], $page_it);
$page_en = (int) WpGoLanguages::create_translation($page_it, 'en', 'independent');
update_field('field_wgt_sy_title', 'Title', $page_en);
update_field('field_wgt_sy_free', 'Free', $page_en);
update_field('field_wgt_sy_cta', ['title' => 'Discover', 'url' => 'https://example.test/own', 'target' => ''], $page_en);
update_field('field_wgt_sy_rows', [['field_wgt_sy_rows_text' => 'Own', 'field_wgt_sy_rows_size' => 's']], $page_en);

$meta = fn (int $id, string $key) => get_post_meta($id, $key, true);

Core\set_synced_marks($page_en, ['sy_title', 'sy_cta', 'sy_rows']);
wp_update_post(['ID' => $page_en]);
same('Titolo', $meta($page_en, 'sy_title'), 'ticked: the original\'s, on its own save');
same('Free', $meta($page_en, 'sy_free'), 'one not ticked stays');
same($target_en_url, $meta($page_en, 'sy_cta')['url'], 'a link: the translated page');
same('Discover', $meta($page_en, 'sy_cta')['title'], 'its label free');
same('2', (string) $meta($page_en, 'sy_rows'), 'a repeater, whole');
same('Due', $meta($page_en, 'sy_rows_1_text'), 'its cells');
same('field_wgt_sy_rows_text', $meta($page_en, '_sy_rows_1_text'), 'their references, so ACF reads them');
same('Due', get_field('field_wgt_sy_rows', $page_en)[1]['text'] ?? null, 'as ACF reads it');

update_post_meta($page_it, 'sy_title', 'Titolo nuovo');
wp_update_post(['ID' => $page_it]);
same('Titolo nuovo', $meta($page_en, 'sy_title'), 'the original saved: it follows');

update_field('field_wgt_sy_rows', [['field_wgt_sy_rows_text' => 'Solo', 'field_wgt_sy_rows_size' => 's']], $page_it);
wp_update_post(['ID' => $page_it]);
same('1', (string) $meta($page_en, 'sy_rows'), 'a row gone from the original goes');
same('', $meta($page_en, 'sy_rows_1_text'), 'with its cells');


section('A page\'s fields, text only');

$rows_it = wgt_page('Righe pagina', '');
update_field('field_wgt_sy_rows', [['field_wgt_sy_rows_text' => 'Uno', 'field_wgt_sy_rows_size' => 's'], ['field_wgt_sy_rows_text' => 'Due', 'field_wgt_sy_rows_size' => 'l']], $rows_it);
$rows_en = (int) WpGoLanguages::create_translation($rows_it, 'en', 'text');
update_post_meta($rows_en, 'sy_rows_0_text', 'One');
update_post_meta($rows_en, 'sy_rows_1_text', 'Two');
Core\set_synced_marks($rows_en, ['sy_rows_1_text']);
wp_update_post(['ID' => $rows_en]);
same('Due', $meta($rows_en, 'sy_rows_1_text'), 'a cell ticked follows');
same('One', $meta($rows_en, 'sy_rows_0_text'), 'the other doesn\'t');

update_field('field_wgt_sy_rows', [['field_wgt_sy_rows_text' => 'Due', 'field_wgt_sy_rows_size' => 'l'], ['field_wgt_sy_rows_text' => 'Uno', 'field_wgt_sy_rows_size' => 's']], $rows_it);
wp_update_post(['ID' => $rows_it]);
same(['sy_rows_0_text'], Core\synced_marks($rows_en), 'rows moved: the mark goes with its row');
same('Due', $meta($rows_en, 'sy_rows_0_text'), 'still following');
same('One', $meta($rows_en, 'sy_rows_1_text'), 'the translated one moved too');


section('A term\'s fields');

register_taxonomy('wgt_sy_topic', 'page', ['public' => true, 'show_in_rest' => true]);
add_filter('wp_go_languages/config', fn ($config) => array_replace($config, ['taxonomies' => [...$config['taxonomies'], 'wgt_sy_topic']]));
Core\config(true);
$term_it = (int) WpGoLanguages::in('it', fn () => wp_insert_term('Sync termine', 'wgt_sy_topic')['term_id']);
$term_en = Core\create_term_translation($term_it, 'en', 'independent');
check('the term and its translation are there', $term_it > 0 && is_int($term_en));
update_term_meta($term_it, 'sy_title', 'Titolo');
update_term_meta($term_it, '_sy_title', 'field_wgt_sy_title');
update_term_meta($term_en, 'sy_title', 'Title');
update_term_meta($term_en, '_sy_title', 'field_wgt_sy_title');
Core\set_synced_marks($term_en, ['sy_title'], 'term');
Core\sync_term_translations($term_en);
same('Titolo', get_term_meta($term_en, 'sy_title', true), 'its own save: the original\'s');
update_term_meta($term_it, 'sy_title', 'Titolo nuovo');
Core\sync_term_translations($term_it);
same('Titolo nuovo', get_term_meta($term_en, 'sy_title', true), 'the original saved: it follows');
Core\make_original($term_en, 'term');
same([], Core\synced_marks($term_en, 'term'), 'made the original: it follows nothing');


section('An options page');

update_option('options_sy_title', 'Titolo');
update_option('_options_sy_title', 'field_wgt_sy_title');
update_option('options_sy_cta', ['title' => 'Scopri', 'url' => $target_url, 'target' => '']);
update_option('_options_sy_cta', 'field_wgt_sy_cta');
update_option('options_sy_rows', 1);
update_option('_options_sy_rows', 'field_wgt_sy_rows');
update_option('options_sy_rows_0_text', 'Uno');
update_option('_options_sy_rows_0_text', 'field_wgt_sy_rows_text');
update_option('options_en_sy_title', 'Title');
update_option('_options_en_sy_title', 'field_wgt_sy_title');
update_option('options_en_sy_cta', ['title' => 'Discover', 'url' => 'https://example.test/own', 'target' => '']);
update_option('_options_en_sy_cta', 'field_wgt_sy_cta');
Core\set_synced_marks('en', ['sy_title', 'sy_cta', 'sy_rows'], 'option');
do_action('acf/save_post', 'options_en');
same('Titolo', get_option('options_en_sy_title'), 'the language\'s own save: the default\'s');
same($target_en_url, get_option('options_en_sy_cta')['url'], 'a link: the translated page');
same('Discover', get_option('options_en_sy_cta')['title'], 'its label free');
same('Uno', get_option('options_en_sy_rows_0_text'), 'a repeater, whole');
same('field_wgt_sy_rows_text', get_option('_options_en_sy_rows_0_text'), 'its references');

update_option('options_sy_title', 'Titolo nuovo');
do_action('acf/save_post', 'options');
same('Titolo nuovo', get_option('options_en_sy_title'), 'the default\'s saved: it follows');


section('AI translation');

$ai_it = wgt_page('Sync AI', serialize_blocks([$block('sy-f', $start)]));
$ai_en = (int) WpGoLanguages::create_translation($ai_it, 'en', 'text');
$edit($ai_en, [], ['sy_title', 'sy_cta#title', 'sy_rows_0_text']);
$keys = array_column(Core\ai_fields($ai_en), 'key');
check('a block\'s synced field isn\'t offered', !in_array('acf:sy-f/sy_title', $keys, true));
check('nor a link\'s synced label', !in_array('acf:sy-f/sy_cta#title', $keys, true));
check('nor a synced cell', !in_array('acf:sy-f/sy_rows_0_text', $keys, true));
check('another field is', in_array('acf:sy-f/sy_free', $keys, true));
check('another cell is', in_array('acf:sy-f/sy_rows_1_text', $keys, true));
same([], array_keys(Core\ai_write($ai_en, ['acf:sy-f/sy_title' => 'From AI'])['written'] ?? []), 'and it can\'t be written');
same('Titolo', $values($ai_en)['sy_title'], 'its value stays');

$edit($ai_en, [], ['sy_cta']);
check('a link with only its page synced: its label still is', in_array('acf:sy-f/sy_cta#title', array_column(Core\ai_fields($ai_en), 'key'), true));

$ai_page_it = wgt_page('Sync AI campi', '');
update_field('field_wgt_sy_rows', [['field_wgt_sy_rows_text' => 'Uno', 'field_wgt_sy_rows_size' => 's'], ['field_wgt_sy_rows_text' => 'Due', 'field_wgt_sy_rows_size' => 'l']], $ai_page_it);
update_field('field_wgt_sy_title', 'Titolo', $ai_page_it);
$ai_page_en = (int) WpGoLanguages::create_translation($ai_page_it, 'en', 'text');
Core\set_synced_marks($ai_page_en, ['sy_title', 'sy_rows_0_text']);
$by_key = array_column(Core\ai_fields($ai_page_en), null, 'key');
check('a page\'s synced field isn\'t offered', !isset($by_key['meta:sy_title']));
check('nor its synced cell', !isset($by_key['meta:sy_rows_0_text']));
same(1, $by_key['meta:sy_rows_1_text']['nth'] ?? null, 'the next cell keeps its place in the form: it\'s the second there');


section('The boxes, on a page\'s form');

$saved = State::$context;
State::$context = 'admin';

$form = function (int $id) {
    $_GET['post'] = (string) $id;
    $fields = acf_get_fields('group_wgt_sy');
    acf_prefix_fields($fields, 'acf');
    $html = wgt_output(fn () => acf_render_fields($fields, $id, 'div', 'label'));
    unset($_GET['post']);

    return $html;
};
$has = fn (string $html, string $mark) => str_contains($html, 'data-mark="' . $mark . '"');

$html = $form($page_en);
check('a translation\'s field has its box', $has($html, 'sy_free'));
check('a link has two', $has($html, 'sy_cta') && $has($html, 'sy_cta#title'));
same(1, substr_count($html, 'data-mark="sy_page"'), 'a picker, which draws itself as a select, has one');
same(1, substr_count($html, 'data-mark="sy_size"'), 'a select too');
same(1, substr_count($html, 'data-mark="sy_group_note"'), 'and a group\'s field, drawn inside its group, keeps its own');
check('independent: a repeater has one', $has($html, 'sy_rows'));
check('and its cells none', !$has($html, 'sy_rows_0_text'));
check('a group\'s field has its own', $has($html, 'sy_group_note'));
check('ticked as marked', (bool) preg_match('/data-mark="sy_title"[^>]*checked/', $html));
check('not as not', !preg_match('/data-mark="sy_free"[^>]*checked/', $html));
check('the new-row template has none', !$has($html, 'sy_rows_0_size'));

$html = $form($rows_en);
check('text only: a repeater has none', !$has($html, 'sy_rows'));
check('its text cell has one', $has($html, 'sy_rows_0_text'));
check('nor the new-row template', substr_count($html, 'data-mark="sy_rows_') === 2);
check('text only: what follows anyway is hidden, and has none', !$has($html, 'sy_size') && !$has($html, 'sy_rows_0_size'));

check('an original has none', !str_contains($form($page_it), 'wgl-sync'));

$html = $form($page_en);
preg_match('/<span class="wgl-sync".*?<\/label><\/span>/s', $html, $box);
check('a box shows the original\'s flag', str_contains($box[0] ?? '', Core\flag_url('it')));
check('on the corner of a link', str_contains($box[0] ?? '', 'wgl-sync__link dashicons dashicons-admin-links'));
same('', trim(wp_strip_all_tags($box[0] ?? 'x')), 'and no text');
check('its title says what it does, the language too', (bool) preg_match('/<label class="wgl-sync__part" title="[^"]*' . preg_quote((string) Core\language('it')['name'], '/') . '/', $html));
check('as does its aria-label, for a screen reader', (bool) preg_match('/aria-label="[^"]*' . preg_quote((string) Core\language('it')['name'], '/') . '/', $html));
check('what shows only once saved says so in its title', (bool) preg_match('/title="[^"]*Updates when saved[^"]*"><input[^>]*data-mark="sy_rows"/', $html));
check('what shows at once doesn\'t', !preg_match('/title="[^"]*Updates when saved[^"]*"><input[^>]*data-mark="sy_title"/', $html));
check('a link\'s two boxes have a glyph each', str_contains($html, 'dashicons-admin-page') && str_contains($html, 'dashicons-editor-textcolor'));

$blocks_page = (int) WpGoLanguages::create_translation($rows_it, 'de', 'blocks');
$html = $form($blocks_page);
check('fixed blocks: its page fields\' rows don\'t follow, so a repeater has one', $has($html, 'sy_rows') && !$has($html, 'sy_rows_0_text'));
check('and a select has one', $has($html, 'sy_size'));


section('The boxes, on a block\'s form');

State::$context = 'ajax';

$block_form = function (int $id, string $block_id, array $data, array $marks = []) {
    $_REQUEST['post_id'] = $id;
    $_REQUEST['block']   = wp_slash(wp_json_encode(['name' => 'acf/wgt-sy', 'data' => $data, 'metadata' => ['wgId' => $block_id] + ($marks ? [Core\SYNCED_BLOCK => $marks] : [])]));

    acf_setup_meta($data, 'block_wgt', true);
    $fields = acf_get_fields('group_wgt_sy');
    acf_prefix_fields($fields, 'acf-block_wgt');
    $html = wgt_output(fn () => acf_render_fields($fields, 'block_wgt', 'div', 'field'));
    acf_reset_meta('block_wgt');

    unset($_REQUEST['post_id'], $_REQUEST['block']);

    return $html;
};

$html = $block_form($en, 'sy-a', $values($en), ['sy_title']);
check('an independent translation\'s block has its boxes', $has($html, 'sy_title') && $has($html, 'sy_free'));
check('ticked as its attributes say', (bool) preg_match('/data-mark="sy_title"[^>]*checked/', $html));
check('a box has no name: ACF writes a field\'s value to its first named input', !preg_match('/class="wgl-sync__box"[^>]* name=/', $html));
check('what the original has, for the script', str_contains($html, 'data-value="') && str_contains($html, 'Titolo'));
check('independent: a repeater, no cells', $has($html, 'sy_rows') && !$has($html, 'sy_rows_0_text'));
check('a block the original doesn\'t have: none', !str_contains($block_form($en, 'sy-unknown', $values($en)), 'wgl-sync'));
check('an original\'s block: none', !str_contains($block_form($it, 'sy-a', $values($it)), 'wgl-sync'));

$html = $block_form($text, 'sy-b', $values($text));
check('text only: a text has its box', $has($html, 'sy_title'));
check('what follows anyway has none', !$has($html, 'sy_size'));

State::$context = 'admin';
$_GET['post'] = (string) $en;
same(false, acf_get_setting('preload_blocks'), 'a translation\'s block forms aren\'t preloaded');
unset($_GET['post']);


section('What the form posts');

$_POST['wgl_synced_shown'] = ['sy_title', 'sy_free', "bad'mark"];
$_POST['wgl_synced']       = ['sy_free', 'sy_not_shown'];
Core\set_synced_marks($page_en, ['sy_title', 'sy_other']);
Admin\save_synced_marks($page_en);
same(['sy_other', 'sy_free'], Core\synced_marks($page_en), 'as ticked; one not shown stays, one not offered isn\'t taken');

$_POST['wgl_synced_shown'] = ['sy_title'];
unset($_POST['wgl_synced']);
Core\set_synced_marks($page_en, ['sy_title']);
Admin\save_synced_marks($page_en);
same('', get_post_meta($page_en, Core\SYNCED_META, true), 'the last unticked: none left');

$_POST['wgl_synced_shown'] = ['sy_title'];
$_POST['wgl_synced']       = ['sy_title'];
Admin\save_synced_marks($page_it);
same('', get_post_meta($page_it, Core\SYNCED_META, true), 'an original takes none');
Admin\save_synced_marks('options');
same([], Core\synced_marks(Core\default_language(), 'option'), 'nor the default language\'s options');
Admin\save_synced_marks('options_en');
same(['sy_cta', 'sy_rows', 'sy_title'], Core\synced_marks('en', 'option'), 'another language\'s do, those it didn\'t show staying');
unset($_POST['wgl_synced_shown'], $_POST['wgl_synced']);

State::$context = $saved;
