<?php

/**
 * A group's link (Core/synced.php): a group's or a clone's mark taking precedence
 * over its fields', in blocks and page fields, and what a mark replaced coming back
 * when it goes, unless the value changed since.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;

acf_add_local_field_group([
    'key'      => 'group_wgt_gl_src',
    'title'    => 'Cloned',
    'fields'   => [
        ['key' => 'field_wgt_gl_src_image', 'name' => 'image', 'label' => 'Image', 'type' => 'text'],
        ['key' => 'field_wgt_gl_src_alt', 'name' => 'alt', 'label' => 'Alt', 'type' => 'text'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'wgt_none']]],
]);

$fields = [
    ['key' => 'field_wgt_gl_title', 'name' => 'gl_title', 'label' => 'Title', 'type' => 'text'],
    ['key' => 'field_wgt_gl_cta', 'name' => 'gl_cta', 'label' => 'CTA', 'type' => 'link'],
    ['key' => 'field_wgt_gl_size', 'name' => 'gl_size', 'label' => 'Size', 'type' => 'select', 'choices' => ['s' => 'S', 'l' => 'L']],
    ['key' => 'field_wgt_gl_rows', 'name' => 'gl_rows', 'label' => 'Rows', 'type' => 'repeater', 'sub_fields' => [
        ['key' => 'field_wgt_gl_rows_text', 'name' => 'text', 'label' => 'Text', 'type' => 'text'],
    ]],
    ['key' => 'field_wgt_gl_group', 'name' => 'gl_group', 'label' => 'Group', 'type' => 'group', 'sub_fields' => [
        ['key' => 'field_wgt_gl_group_note', 'name' => 'note', 'label' => 'Note', 'type' => 'text'],
        ['key' => 'field_wgt_gl_group_tab', 'name' => '', 'label' => 'Tab', 'type' => 'tab'],
        ['key' => 'field_wgt_gl_group_cta', 'name' => 'cta', 'label' => 'CTA', 'type' => 'link'],
        ['key' => 'field_wgt_gl_group_inner', 'name' => 'inner', 'label' => 'Inner', 'type' => 'group', 'sub_fields' => [
            ['key' => 'field_wgt_gl_group_inner_deep', 'name' => 'deep', 'label' => 'Deep', 'type' => 'text'],
        ]],
    ]],
    ['key' => 'field_wgt_gl_media', 'name' => 'gl_media', 'label' => 'Media', 'type' => 'clone', 'display' => 'group', 'prefix_name' => 1, 'clone' => ['group_wgt_gl_src']],
    ['key' => 'field_wgt_gl_plain', 'name' => 'gl_plain', 'label' => 'Plain', 'type' => 'clone', 'display' => 'group', 'prefix_name' => 0, 'clone' => ['group_wgt_gl_src']],
    ['key' => 'field_wgt_gl_loose', 'name' => 'gl_loose', 'label' => 'Loose', 'type' => 'clone', 'display' => 'seamless', 'prefix_name' => 1, 'clone' => ['group_wgt_gl_src']],
];

acf_add_local_field_group([
    'key'      => 'group_wgt_gl',
    'title'    => 'Group links',
    'fields'   => $fields,
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);

$refs = [
    'gl_title' => 'title', 'gl_cta' => 'cta', 'gl_size' => 'size', 'gl_rows' => 'rows', 'gl_rows_0_text' => 'rows_text', 'gl_rows_1_text' => 'rows_text',
    'gl_group' => 'group', 'gl_group_note' => 'group_note', 'gl_group_cta' => 'group_cta', 'gl_group_inner_deep' => 'group_inner_deep',
];
// a clone's values point at the field they clone, as ACF stores them
$pointer = function (string $name) use ($refs) {
    return match (true) {
        isset($refs[$name])                 => 'field_wgt_gl_' . $refs[$name],
        $name === 'gl_media'                => 'field_wgt_gl_media',
        str_starts_with($name, 'gl_media_') => 'field_wgt_gl_src_' . substr($name, 9),
        default                             => 'field_wgt_gl_src_' . $name,
    };
};
$flat = function (array $values) use ($pointer) {
    $data = [];

    foreach ($values as $name => $value) {
        $data[$name]       = $value;
        $data['_' . $name] = $pointer((string) $name);
    }

    return $data;
};
$block = fn (string $id, array $values, array $marks = []) => [
    'blockName'    => 'acf/wgt-gl',
    'attrs'        => ['name' => 'acf/wgt-gl', 'data' => $flat($values), 'metadata' => ['wgId' => $id] + ($marks ? [Core\SYNCED_BLOCK => $marks] : [])],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
];
$values = fn (int $id, int $i = 0) => wgt_blocks($id)[$i]['attrs']['data'] ?? [];
$edit   = function (int $id, array $changes, ?array $marks = null, int $i = 0) use ($flat) {
    $list = wgt_blocks($id);
    $list[$i]['attrs']['data'] = $flat($changes) + $list[$i]['attrs']['data'];

    if ($marks !== null) {
        if ($marks) {
            $list[$i]['attrs']['metadata'][Core\SYNCED_BLOCK] = $marks;
        } else {
            unset($list[$i]['attrs']['metadata'][Core\SYNCED_BLOCK]);
        }
    }

    wgt_save($id, $list);
};
$group_mark = 'gl_group' . Core\SYNCED_GROUP . 'field_wgt_gl_group';
$media_mark = 'gl_media' . Core\SYNCED_GROUP . 'field_wgt_gl_media';
$plain_mark = 'gl_plain' . Core\SYNCED_GROUP . 'field_wgt_gl_plain';

$start = [
    'gl_title'            => 'Titolo',
    'gl_cta'              => ['title' => 'Scopri', 'url' => 'https://example.test/it', 'target' => ''],
    'gl_size'             => 's',
    'gl_group'            => '',
    'gl_group_note'       => 'Nota',
    'gl_group_cta'        => ['title' => 'Vai', 'url' => 'https://example.test/vai', 'target' => ''],
    'gl_group_inner_deep' => 'Profondo',
    'gl_media'            => '',
    'gl_media_image'      => '11',
    'gl_media_alt'        => 'Alt italiano',
    'gl_rows'             => 2,
    'gl_rows_0_text'      => 'Uno',
    'gl_rows_1_text'      => 'Due',
];


section('What a group\'s mark comes to');

same(
    ['gl_group_note', 'gl_group_cta', 'gl_group_cta#title', 'gl_group_inner_deep'],
    Core\expand_marks([$group_mark], [], false),
    'a group: its fields at any depth, a link\'s two parts, a tab left out'
);
same(['gl_media_image', 'gl_media_alt'], Core\expand_marks([$media_mark], [], false), 'a clone with a prefix: under its name');
same(['image', 'alt'], Core\expand_marks([$plain_mark], [], false), 'a clone without one: not');
same(['gl_title', 'gl_group_note', 'gl_group_cta', 'gl_group_cta#title', 'gl_group_inner_deep'], Core\expand_marks(['gl_title', $group_mark, 'gl_group_note'], [], false), 'with others, each once');
same([], Core\expand_marks(['gl_gone' . Core\SYNCED_GROUP . 'field_wgt_gone'], [], false), 'a group that\'s gone: nothing');

add_filter('wp_go_languages/field_policy', $ignore = fn ($policy, $field) => ($field['key'] ?? '') === 'field_wgt_gl_group_note' ? 'ignore' : $policy, 10, 2);
check('a field that\'s never synced stays out', !in_array('gl_group_note', Core\expand_marks([$group_mark], [], false), true));
remove_filter('wp_go_languages/field_policy', $ignore, 10);


section('In a block: the group takes precedence');

$it = wgt_page('Gruppo blocco', serialize_blocks([$block('gl-a', $start)]));
$en = (int) WpGoLanguages::create_translation($it, 'en', 'independent');

$edit($en, ['gl_group_note' => 'Note', 'gl_group_inner_deep' => 'Deep', 'gl_title' => 'Title']);
same('Note', $values($en)['gl_group_note'], 'nothing linked: its own');

$edit($en, [], [$group_mark]);
same('Nota', $values($en)['gl_group_note'], 'the group linked: its field follows');
same('Profondo', $values($en)['gl_group_inner_deep'], 'and one in a group inside it');
same('Title', $values($en)['gl_title'], 'one outside it doesn\'t');

$edit($en, ['gl_group_note' => 'Typed anyway'], [$group_mark]);
same('Nota', $values($en)['gl_group_note'], 'a value sent for one of its fields is the original\'s on save');

$edit($en, ['gl_media_image' => '22', 'gl_media_alt' => 'English alt']);
$edit($en, [], [$group_mark, $media_mark]);
same('11', $values($en)['gl_media_image'], 'a clone linked: its field follows, under its prefix');
same('Alt italiano', $values($en)['gl_media_alt'], 'all of them');
$edit($en, [], [$group_mark]);
same('English alt', $values($en)['gl_media_alt'], 'unlinked: back');

$itb = wgt_blocks($it);
$itb[0]['attrs']['data']['gl_group_note'] = 'Nota nuova';
wgt_save($it, $itb);
same('Nota nuova', $values($en)['gl_group_note'], 'the original changes: it follows');


section('In a block: what a mark replaced comes back');

$edit($en, [], []);
same('Note', $values($en)['gl_group_note'], 'the group unlinked: its field\'s value is back');
same('Deep', $values($en)['gl_group_inner_deep'], 'all of them');
same([], Core\synced_memory($en), 'and nothing\'s kept any more');

$edit($en, ['gl_title' => 'Own title'], ['gl_title']);
same('Titolo', $values($en)['gl_title'], 'a field linked: the original\'s');
$edit($en, ['gl_title' => 'Titolo'], []);
same('Title', $values($en)['gl_title'], 'unlinked, as the mark left it: what it was when linked was stored, not what the form sent');

$edit($en, [], ['gl_title']);
$edit($en, ['gl_title' => 'Edited after'], []);
same('Edited after', $values($en)['gl_title'], 'unlinked and edited: the edit wins');

$edit($en, [], ['gl_size']);
$edit($en, [], []);
same('s', $values($en)['gl_size'], 'a type a form can\'t show comes back too, from the save');

$edit($en, ['gl_cta' => ['title' => 'Discover', 'url' => 'https://example.test/en', 'target' => '']], []);
$edit($en, [], ['gl_cta']);
same(['url' => 'https://example.test/it', 'target' => '', 'title' => 'Discover'], $values($en)['gl_cta'], 'a link\'s page linked, its label its own');
$edit($en, [], []);
same('https://example.test/en', $values($en)['gl_cta']['url'], 'unlinked: its own page back');
same('Discover', $values($en)['gl_cta']['title'], 'and the label as it was');

$edit($en, ['gl_rows' => 1, 'gl_rows_0_text' => 'One'], []);
$list = wgt_blocks($en);
unset($list[0]['attrs']['data']['gl_rows_1_text'], $list[0]['attrs']['data']['_gl_rows_1_text']);
wgt_save($en, $list);
$edit($en, [], ['gl_rows']);
same(2, (int) $values($en)['gl_rows'], 'a repeater linked: the original\'s rows');
$edit($en, [], []);
same(1, (int) $values($en)['gl_rows'], 'unlinked: its own rows');
same('One', $values($en)['gl_rows_0_text'], 'and their values');
check('and none of the original\'s left', !isset($values($en)['gl_rows_1_text']));


section('In a block: both a group and a field');

$edit($en, ['gl_group_note' => 'Note', 'gl_group_inner_deep' => 'Deep'], []);
$edit($en, [], ['gl_group_note']);
$edit($en, [], ['gl_group_note', $group_mark]);
same('Nota nuova', $values($en)['gl_group_note'], 'linked by both');
$edit($en, [], ['gl_group_note']);
same('Nota nuova', $values($en)['gl_group_note'], 'the group unlinked: its own mark still follows');
same('Deep', $values($en)['gl_group_inner_deep'], 'the rest back');
$edit($en, [], []);
same('Note', $values($en)['gl_group_note'], 'its own unlinked too: back to the first value');


section('A block\'s link changing');

$two = wgt_page('Due blocchi', serialize_blocks([$block('gl-1', $start), $block('gl-2', ['gl_title' => 'Secondo'] + $start)]));
$tr  = (int) WpGoLanguages::create_translation($two, 'en', 'independent');
$edit($tr, ['gl_title' => 'Mine']);
$edit($tr, [], ['gl_title']);
same('Titolo', $values($tr)['gl_title'], 'linked to the first');

$list = wgt_blocks($tr);
$list[0]['attrs']['metadata'] = ['wgId' => 'gl-2', Core\LINKED_FROM => 'gl-1'];
unset($list[1]);
wgt_save($tr, array_values($list));
same('Mine', $values($tr)['gl_title'], 'linked elsewhere: its marks gone, its value back');
check('the ID it had isn\'t saved', !str_contains(get_post_field('post_content', $tr), Core\LINKED_FROM));

$list = wgt_blocks($tr);
$list[0]['attrs']['data']['gl_title'] = 'Secondo';
$list[0]['attrs']['metadata'] = ['wgId' => 'gl-3', Core\LINKED_FROM => 'gl-2', Core\SYNCED_BLOCK => ['gl_title']];
wgt_save($tr, $list);
same(['gl_title' => 'Mine', '_gl_title' => 'field_wgt_gl_title'], Core\synced_memory($tr)['block:gl-3']['gl_title'] ?? null, 'relinked and ticked in one save: the value it had is what\'s kept');

$list = wgt_blocks($tr);
$list[0]['attrs']['metadata'] = ['wgId' => 'gl-4'];
$list[0]['attrs']['data']['gl_size'] = 'l';
wgt_save($tr, $list);
same([], Core\synced_memory($tr), 'a block no longer on the page: forgotten');


section('Page fields');

$post_page = wgt_page('Gruppo pagina', '');
foreach (['gl_title' => 'Titolo', 'gl_group_note' => 'Nota', 'gl_size' => 's', 'gl_media_alt' => 'Alt it'] as $name => $value) {
    update_post_meta($post_page, $name, $value);
    update_post_meta($post_page, '_' . $name, $pointer($name));
}
$page_en = (int) WpGoLanguages::create_translation($post_page, 'en', 'independent');
wp_update_post(['ID' => $page_en, 'post_status' => 'publish']);
update_post_meta($page_en, 'gl_title', 'Title');
update_post_meta($page_en, 'gl_group_note', 'Note');
update_post_meta($page_en, 'gl_size', 'l');
update_post_meta($page_en, 'gl_media_alt', 'Alt en');

$post_form = function (array $shown, array $ticked, array $written = []) use ($page_en) {
    $_POST['wgl_synced_shown'] = $shown;
    $_POST['wgl_synced']       = $ticked;
    Admin\save_synced_marks($page_en);

    // what ACF does between the two, at 10
    foreach ($written as $name => $value) {
        update_post_meta($page_en, $name, $value);
    }

    Core\restore_fields();
    Core\pull_fields($page_en, Core\original($page_en));
    unset($_POST['wgl_synced_shown'], $_POST['wgl_synced']);
};
$shown = ['gl_title', 'gl_size', $group_mark, $media_mark];

$post_form($shown, [$group_mark]);
same([$group_mark], Core\synced_marks($page_en), 'a group\'s mark passes the form\'s check');
same('Nota', get_post_meta($page_en, 'gl_group_note', true), 'the group linked: its field follows');
same('Title', get_post_meta($page_en, 'gl_title', true), 'one outside doesn\'t');

$post_form($shown, []);
same('Note', get_post_meta($page_en, 'gl_group_note', true), 'unlinked: back');
same('', get_post_meta($page_en, Core\SYNCED_BEFORE, true), 'nothing kept');

$post_form($shown, [$media_mark]);
same('Alt it', get_post_meta($page_en, 'gl_media_alt', true), 'a clone linked: under its prefix');
$post_form($shown, []);
same('Alt en', get_post_meta($page_en, 'gl_media_alt', true), 'unlinked: back');

$post_form($shown, ['gl_title']);
same('Titolo', get_post_meta($page_en, 'gl_title', true), 'a field linked');
$post_form($shown, [], ['gl_title' => 'Edited after']);
same('Edited after', get_post_meta($page_en, 'gl_title', true), 'unlinked and edited: the edit wins');

$post_form($shown, ['gl_size']);
same('s', get_post_meta($page_en, 'gl_size', true), 'a select linked');
$post_form($shown, []);
same('l', get_post_meta($page_en, 'gl_size', true), 'unlinked: back, from the save');

$post_form($shown, ['gl_title']);
Core\forget_synced($page_en);
same('', get_post_meta($page_en, Core\SYNCED_BEFORE, true), 'an item that\'s its group\'s original now remembers nothing');


section('Never copied to a new translation');

check('its key is its own', Core\own_meta_key(Core\SYNCED_BEFORE));


section('The boxes');

$page_form = function (array $field, int $id) {
    $_REQUEST['post_id'] = $id;
    $boxes = Core\sync_boxes(acf_get_field($field['key']) + ['prefix' => 'acf']);
    unset($_REQUEST['post_id']);

    return $boxes;
};
$boxes = $page_form($fields[4], $page_en);
same('group', $boxes['type'] ?? '', 'a group has a box');
same($group_mark, $boxes['parts'][0]['mark'] ?? '', 'for its mark');
same('group', $page_form($fields[5], $page_en)['type'] ?? '', 'a clone shown as a group too');
same(null, $page_form($fields[7], $page_en), 'a seamless clone has none: no title for it');

update_post_meta($page_en, 'gl_title', 'Title');
Core\set_synced_memory($page_en, ['gl_title' => ['gl_title' => 'Title', '_gl_title' => 'field_wgt_gl_title']]);
Core\set_synced_marks($page_en, ['gl_title']);
same('Title', $page_form($fields[0], $page_en)['parts'][0]['before'] ?? '', 'a linked field says what it replaced');
check('a select doesn\'t: it can\'t be shown', !isset($page_form($fields[2], $page_en)['parts'][0]['before']));
