<?php

/**
 * Fixed translations, a flexible content: on a field group registered for
 * the test alone, merge() keeps the original's layouts, its images and
 * selects, matches rows by layout and values (not their index), and a
 * fixed translation's block form locks the rows and hides what isn't text.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core\State;

use function WpGo\Languages\Core\merge;

acf_add_local_field_group([
    'key'    => 'group_wgt_flex',
    'title'  => 'Flex test',
    'fields' => [[
        'key'     => 'field_wgt_flex',
        'name'    => 'sections',
        'type'    => 'flexible_content',
        'wpml_cf_preferences' => 1,
        'layouts' => [
            'layout_text'  => ['key' => 'layout_text', 'name' => 'text', 'label' => 'Text', 'sub_fields' => [
                ['key' => 'field_wgt_flex_body', 'name' => 'body', 'type' => 'textarea'],
            ]],
            'layout_image' => ['key' => 'layout_image', 'name' => 'image', 'label' => 'Image', 'sub_fields' => [
                ['key' => 'field_wgt_flex_img', 'name' => 'img', 'type' => 'image', 'wpml_cf_preferences' => 3],
                ['key' => 'field_wgt_flex_caption', 'name' => 'caption', 'type' => 'text'],
                ['key' => 'field_wgt_flex_size', 'name' => 'size', 'type' => 'select', 'choices' => ['s' => 's', 'l' => 'l']],
            ]],
        ],
    ]],
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/wgt-test']]],
]);

// a block's data from rows: ['text', body] or ['image', img, caption, size]
$data = function (array $rows): array {
    $data = ['sections' => array_column($rows, 0), '_sections' => 'field_wgt_flex'];

    foreach ($rows as $i => $row) {
        if ($row[0] === 'text') {
            $data["sections_{$i}_body"]  = $row[1];
            $data["_sections_{$i}_body"] = 'field_wgt_flex_body';
        } else {
            $data["sections_{$i}_img"]      = $row[1];
            $data["_sections_{$i}_img"]     = 'field_wgt_flex_img';
            $data["sections_{$i}_caption"]  = $row[2];
            $data["_sections_{$i}_caption"] = 'field_wgt_flex_caption';
            $data["sections_{$i}_size"]     = $row[3];
            $data["_sections_{$i}_size"]    = 'field_wgt_flex_size';
        }
    }

    return $data;
};
$content = fn (array $rows) => serialize_block(['blockName' => 'acf/wgt-test', 'attrs' => ['name' => 'acf/wgt-test', 'data' => $data($rows), 'metadata' => ['wgId' => 'block-1']], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []]);
$read = function (string $html): array {
    $data = parse_blocks($html)[0]['attrs']['data'];
    $out  = [];

    foreach ($data['sections'] as $i => $layout) {
        $out[] = $layout === 'text'
            ? ['text', $data["sections_{$i}_body"]]
            : ['image', $data["sections_{$i}_img"], $data["sections_{$i}_caption"], $data["sections_{$i}_size"]];
    }

    return $out;
};

$it_v1 = $content([['text', 'Ciao'], ['image', 11, 'Didascalia', 's'], ['text', 'Fine']]);
$en_v1 = $content([['text', 'Hello'], ['image', 11, 'Caption', 's'], ['text', 'End']]);

section('The translation saves');

// the translator tries to change the layouts' order, an image and a select
$enforced = $read(merge($it_v1, $content([['image', 99, 'Caption', 'l'], ['text', 'Hello'], ['text', 'End']])));
same(['text', 'image', 'text'], array_column($enforced, 0), 'the layouts stay the original\'s');
check('the image and the select go back to the original\'s', $enforced[1][1] === 11 && $enforced[1][3] === 's');

section('The original changes');

// the image row moved first, its size changed, a text row added at the end
$it_v2 = $content([['image', 11, 'Didascalia', 'l'], ['text', 'Ciao'], ['text', 'Fine'], ['text', 'Nuovo']]);
$en_v2 = $read(merge($it_v2, $en_v1, $it_v1));
same(['image', 'text', 'text', 'text'], array_column($en_v2, 0), 'the layouts follow the original');
same('Caption', $en_v2[0][2], 'the moved image row keeps its translated caption');
same('l', $en_v2[0][3], 'its size follows the original (l)');
check('the text rows keep their translations', $en_v2[1][1] === 'Hello' && $en_v2[2][1] === 'End');
same('Nuovo', $en_v2[3][1], 'the new row starts with the original\'s text');

// a text row never takes an image row's place: same index, other layout
$it_v3 = $content([['text', 'Ciao'], ['text', 'Fine'], ['image', 11, 'Didascalia', 's']]);
$en_v3 = $read(merge($it_v3, $en_v1, $it_v1));
check('rows are matched by layout and values, not by index', $en_v3[0][1] === 'Hello' && $en_v3[1][1] === 'End' && $en_v3[2][2] === 'Caption');

section('The block form of a fixed translation');

same('flexible_content', acf_get_field('field_wgt_flex')['type'] ?? null, 'the field resolves as a flexible content');

$it = wgt_page('Flex lock', $it_v1);
$en = WpGoLanguages::create_translation($it, 'en', 'fixed');

// as ACF's block AJAX asks for the form: `post_id` the translation
$context           = State::$context;
State::$context    = 'ajax';
$_REQUEST['post_id'] = $en;

$field           = acf_get_field('field_wgt_flex');
$field['prefix'] = 'acf-block_x';
$field['value']  = [['acf_fc_layout' => 'text'], ['acf_fc_layout' => 'image'], ['acf_fc_layout' => 'text']];
$prepared        = WpGo\Languages\Admin\prepare_fixed_field($field);

check('the flexible content keeps its 3 rows (min = max = 3), marked wgl-fixed-rows', $prepared['min'] === 3 && $prepared['max'] === 3 && str_contains($prepared['wrapper']['class'], 'wgl-fixed-rows'));

$hidden = function (string $key): bool {
    $sub           = acf_get_field($key);
    $sub['prefix'] = 'acf-block_x[field_wgt_flex][row-0]';

    return str_contains((string) (WpGo\Languages\Admin\prepare_fixed_field($sub)['wrapper']['class'] ?? ''), 'wgl-locked');
};

check('a textarea is shown, to translate', !$hidden('field_wgt_flex_body'));
check('a text is shown, to translate', !$hidden('field_wgt_flex_caption'));
check('an image set per language (WPML\'s copy once) is shown: the field\'s rule, as a page field\'s', !$hidden('field_wgt_flex_img'));
check('a select is hidden', $hidden('field_wgt_flex_size'));

$merged = WpGo\Languages\Core\merge_data(
    ['img' => 5, '_img' => 'field_wgt_flex_img', 'size' => 's', '_size' => 'field_wgt_flex_size'],
    ['img' => 7, '_img' => 'field_wgt_flex_img', 'size' => 'l', '_size' => 'field_wgt_flex_size']
);
same([7, 's'], [$merged['img'], $merged['size']], 'on the original\'s save the per-language image stays the translation\'s; the select with no rule is the original\'s');

// a clone set "same in every language": its fields follow it, though their
// own group says per language
acf_add_local_field_group([
    'key'    => 'group_wgt_pic_src',
    'title'  => 'Pic source',
    'fields' => [
        ['key' => 'field_wgt_pic_image', 'name' => 'image', 'type' => 'image', 'wpml_cf_preferences' => 3],
        ['key' => 'field_wgt_pic_alt', 'name' => 'alt', 'type' => 'text', 'wpml_cf_preferences' => 2],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'wgt_none']]],
]);
acf_add_local_field_group([
    'key'    => 'group_wgt_pic_block',
    'title'  => 'Pic block',
    'fields' => [
        ['key' => 'field_wgt_pic_tab', 'name' => '', 'type' => 'tab', 'label' => 'Media'],
        ['key' => 'field_wgt_pic', 'name' => 'pic', 'type' => 'clone', 'clone' => ['group_wgt_pic_src'], 'display' => 'group', 'prefix_name' => 1, 'wgl_translation' => 'copy'],
        ['key' => 'field_wgt_pic_tab_2', 'name' => '', 'type' => 'tab', 'label' => 'Text'],
        ['key' => 'field_wgt_pic_caption', 'name' => 'caption', 'type' => 'text'],
    ],
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/wgt-pic']]],
]);

$pic = fn (int $image, string $alt) => ['pic_image' => $image, '_pic_image' => 'field_wgt_pic_image', 'pic_alt' => $alt, '_pic_alt' => 'field_wgt_pic_alt', 'pic' => '', '_pic' => 'field_wgt_pic'];
$merged = WpGo\Languages\Core\merge_data($pic(5, 'Gatto'), $pic(7, 'Cat'));
same([5, 'Gatto'], [$merged['pic_image'], $merged['pic_alt']], 'a clone\'s switch reaches its fields in the merge: its image and its text the original\'s');
same('copy', WpGo\Languages\Core\block_data_policy($pic(5, 'x'), 'pic_alt'), 'block_data_policy() finds the clone by the name\'s start');

check('the clone with only copied fields is hidden in the editor, its box and all', !WpGo\Languages\Admin\fixed_field_shows(acf_get_field('field_wgt_pic')));
check('a tab with only it is hidden', !WpGo\Languages\Admin\fixed_field_shows(acf_get_field('field_wgt_pic_tab')));
check('a tab with a text to translate is not', WpGo\Languages\Admin\fixed_field_shows(acf_get_field('field_wgt_pic_tab_2')));

unset($_REQUEST['post_id']);
State::$context = $context;
