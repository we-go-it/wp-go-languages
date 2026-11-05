<?php

// @require fixtures/third-language.php

/**
 * "Original: …" under a fixed translation's text fields (Core\original_text(),
 * Admin\render_original_text()), through ACF's own renderer: a page's fields,
 * a repeater's rows, a group's, a clone's, a block's. Not on an independent
 * translation or an original, nor under what isn't a text.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core\State;


section('A page\'s fields');

acf_add_local_field_group([
    'key'      => 'group_wgt_ot_src',
    'title'    => 'Original text, cloned',
    'fields'   => [
        ['key' => 'field_wgt_ot_src_alt', 'name' => 'alt', 'type' => 'text'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'wgt_none']]],
]);

$fields = [
    ['key' => 'field_wgt_ot_title', 'name' => 'wgt_ot_title', 'type' => 'text'],
    ['key' => 'field_wgt_ot_body', 'name' => 'wgt_ot_body', 'type' => 'wysiwyg', 'delay' => 1],
    ['key' => 'field_wgt_ot_link', 'name' => 'wgt_ot_link', 'type' => 'link'],
    ['key' => 'field_wgt_ot_url', 'name' => 'wgt_ot_url', 'type' => 'url'],
    ['key' => 'field_wgt_ot_rows', 'name' => 'wgt_ot_rows', 'type' => 'repeater', 'sub_fields' => [
        ['key' => 'field_wgt_ot_rows_title', 'name' => 'title', 'type' => 'text'],
    ]],
    ['key' => 'field_wgt_ot_group', 'name' => 'wgt_ot_group', 'type' => 'group', 'sub_fields' => [
        ['key' => 'field_wgt_ot_group_note', 'name' => 'note', 'type' => 'textarea'],
    ]],
    ['key' => 'field_wgt_ot_clone', 'name' => 'wgt_ot_clone', 'type' => 'clone', 'clone' => ['group_wgt_ot_src'], 'display' => 'group', 'prefix_name' => 1],
];

acf_add_local_field_group([
    'key'      => 'group_wgt_ot',
    'title'    => 'Original text',
    'fields'   => $fields,
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);

$it = wgt_page('Testo originale', '');
update_field('field_wgt_ot_title', 'Titolo <b>&amp;</b> altro', $it);
update_field('field_wgt_ot_body', '<p>Corpo del testo</p>', $it);
update_field('field_wgt_ot_link', ['title' => 'Scopri', 'url' => 'https://example.test/it', 'target' => ''], $it);
update_field('field_wgt_ot_url', 'https://example.test/video', $it);
update_field('field_wgt_ot_rows', [['field_wgt_ot_rows_title' => 'Riga uno'], ['field_wgt_ot_rows_title' => 'Riga due']], $it);
update_field('field_wgt_ot_group', ['field_wgt_ot_group_note' => 'Nota del gruppo'], $it);
update_field('field_wgt_ot_clone', ['field_wgt_ot_src_alt' => 'Testo alternativo'], $it);

$text  = WpGoLanguages::create_translation($it, 'en', 'blocks');
$open  = WpGoLanguages::create_translation($it, 'de', 'blocks');
$other = wgt_page('Testo indipendente', '');
update_field('field_wgt_ot_title', 'Titolo indipendente', $other);
$free  = WpGoLanguages::create_translation($other, 'en');

foreach ([$text, $open] as $translation) {
    update_field('field_wgt_ot_title', 'Translated', $translation);
    update_field('field_wgt_ot_rows', [['field_wgt_ot_rows_title' => 'Row A'], ['field_wgt_ot_rows_title' => 'Row B']], $translation);
    update_field('field_wgt_ot_group', ['field_wgt_ot_group_note' => 'Group note'], $translation);
}

$context        = State::$context;
State::$context = 'admin';

$screen = function (int $id) {
    $_GET['post'] = (string) $id;
    $html         = wgt_output(fn () => acf_render_fields(acf_get_fields('group_wgt_ot'), $id, 'div', 'field'));
    unset($_GET['post']);

    return $html;
};
$line = fn (string $text) => 'Original:</strong> ' . $text . '</p>';

foreach (['text only' => $text, 'fixed blocks' => $open] as $mode => $id) {
    $html = $screen($id);

    check("$mode: a text shows the original's, plain, its entities read", str_contains($html, $line('Titolo &amp; altro')));
    check("$mode: an editor's too, without its tags", str_contains($html, $line('Corpo del testo')));
    check("$mode: a link its text, not its address", str_contains($html, $line('Scopri')) && !str_contains($html, $line('https://example.test/it')));
    check("$mode: each repeater row its own", str_contains($html, $line('Riga uno')) && str_contains($html, $line('Riga due')));
    check("$mode: a group's field", str_contains($html, $line('Nota del gruppo')));
    check("$mode: a clone's field, by the name it stores under", str_contains($html, $line('Testo alternativo')));
    check("$mode: a URL is no text: no line", !str_contains($html, $line('https://example.test/video')));
    same(7, substr_count($html, 'class="wgl-original"'), "$mode: one line per text field with a value (not a repeater's hidden new row)");
}

check('an independent translation shows none', !str_contains($screen((int) $free), 'wgl-original'));
check('nor an original', !str_contains($screen($it), 'wgl-original'));

add_filter('wp_go_languages/field_policy', $none = fn ($policy, $field) => $field['key'] === 'field_wgt_ot_title' ? 'copy' : $policy, 10, 2);
check('a text the code says holds no words shows none', !str_contains($screen($open), $line('Titolo &amp; altro')));
remove_filter('wp_go_languages/field_policy', $none);

update_field('field_wgt_ot_title', '', $it);
check('a text the original has empty shows none', !str_contains($screen($open), 'Titolo'));

section('A block\'s fields');

acf_add_local_field_group([
    'key'      => 'group_wgt_ot_block',
    'title'    => 'Original text, a block',
    'fields'   => [
        ['key' => 'field_wgt_otb_title', 'name' => 'title', 'type' => 'text'],
        ['key' => 'field_wgt_otb_size', 'name' => 'size', 'type' => 'select', 'choices' => ['s' => 's']],
        ['key' => 'field_wgt_otb_items', 'name' => 'items', 'type' => 'repeater', 'sub_fields' => [
            ['key' => 'field_wgt_otb_items_text', 'name' => 'text', 'type' => 'text'],
        ]],
        ['key' => 'field_wgt_otb_pic', 'name' => 'pic', 'type' => 'clone', 'clone' => ['group_wgt_ot_src'], 'display' => 'group', 'prefix_name' => 1],
    ],
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/wgt-test']]],
]);

$data = fn (string $title, string $one, string $two, string $alt) => [
    'title' => $title, '_title' => 'field_wgt_otb_title',
    'size' => 's', '_size' => 'field_wgt_otb_size',
    'items' => 2, '_items' => 'field_wgt_otb_items',
    'items_0_text' => $one, '_items_0_text' => 'field_wgt_otb_items_text',
    'items_1_text' => $two, '_items_1_text' => 'field_wgt_otb_items_text',
    'pic_alt' => $alt, '_pic_alt' => 'field_wgt_ot_src_alt',
    'pic' => '', '_pic' => 'field_wgt_otb_pic',
];
$block = fn (string $id, array $values) => ['blockName' => 'acf/wgt-test', 'attrs' => ['name' => 'acf/wgt-test', 'data' => $values, 'metadata' => ['wgId' => $id]], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];

$page = wgt_page('Blocchi originali', serialize_blocks([
    $block('wgt-first', $data('Primo', 'Uno', 'Due', 'Alt IT')),
    $block('wgt-second', $data('Secondo', 'Tre', 'Quattro', 'Alt IT 2')),
]));
$fixed = WpGoLanguages::create_translation($page, 'en', 'blocks');

State::$context = 'ajax';

// as ACF's block form is asked for: its attributes sent, slashed, `post_id` the translation
$form = function (int $id, string $block_id, array $values) {
    $_REQUEST['post_id'] = $id;
    $_REQUEST['block']   = wp_slash(wp_json_encode(['name' => 'acf/wgt-test', 'data' => $values, 'metadata' => ['wgId' => $block_id]]));

    acf_setup_meta($values, 'block_wgt', true);
    $fields = acf_get_fields('group_wgt_ot_block');
    acf_prefix_fields($fields, 'acf-block_wgt');
    $html = wgt_output(fn () => acf_render_fields($fields, 'block_wgt', 'div', 'field'));
    acf_reset_meta('block_wgt');

    unset($_REQUEST['post_id'], $_REQUEST['block']);

    return $html;
};

$html = $form($fixed, 'wgt-second', $data('Second', 'Three', 'Four', 'Alt EN'));
check('a block\'s text shows its own block\'s original', str_contains($html, $line('Secondo')) && !str_contains($html, $line('Primo')));
check('a repeater row in it, each its own', str_contains($html, $line('Tre')) && str_contains($html, $line('Quattro')));
check('a clone\'s field in it', str_contains($html, $line('Alt IT 2')));
check('a select is no text: no line', substr_count($html, 'class="wgl-original"') === 4);

$html = $form($fixed, 'wgt-first', $data('First', 'One', 'Two', 'Alt EN'));
check('the other block, its own', str_contains($html, $line('Primo')));
check('a block the original doesn\'t have: none', !str_contains($form($fixed, 'wgt-unknown', $data('X', 'Y', 'Z', 'W')), 'wgl-original'));

$dup = WpGoLanguages::create_translation($page, 'de');
check('in an independent translation\'s block: none', !str_contains($form((int) $dup, 'wgt-first', $data('Erst', 'Eins', 'Zwei', 'Alt DE')), 'wgl-original'));

section('ACF\'s preloaded forms');

// the page's REST preload renders forms with no block's ID: asked for one by one instead
State::$context = 'admin';
$_GET['post'] = (string) $fixed;
same(false, acf_get_setting('preload_blocks'), 'a fixed translation\'s block forms aren\'t preloaded');
$_GET['post'] = (string) $page;
same(true, (bool) acf_get_setting('preload_blocks'), 'an original\'s are');
$_GET['post'] = (string) $dup;
same(false, acf_get_setting('preload_blocks'), 'nor an independent translation\'s: its synced fields need the block\'s ID too');
unset($_GET['post']);

State::$context = $context;
