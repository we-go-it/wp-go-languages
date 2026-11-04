<?php

// @require fixtures/third-language.php

/**
 * The fixes of the docs round, each as it was found: the copy rules from
 * any source locking a translation's fields (sub fields and rows too), the
 * options' copy fields, unlink clearing the mode, a deleted original handing
 * its group on (a third language, German, to have a group of three), core
 * containers and classic originals in a merge, the featured image and the
 * template following, blocks_dropped() counting nested blocks without
 * writing, and a stale editor's rows remapped through the revisions.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

global $wpdb;

section('The config files');

$files = Core\config_files();
check('the theme\'s wpml-config.xml is read after the plugins\'', str_contains((string) end($files), '/themes/'));

section('Locks, from every source of a rule');

add_filter('wp_go_languages/config_files', fn ($files) => array_merge($files, [__DIR__ . '/fixtures/copy-config.xml']));

$seen = [];
add_filter('wp_go_languages/meta_policy', function ($policy, $key) use (&$seen) {
    $seen[] = $key;

    return in_array($key, ['wgt_plain', 'wgt_opt'], true) ? 'copy' : $policy;
}, 10, 2);

acf_add_local_field_group([
    'key'    => 'group_wgt_locks',
    'title'  => 'Locks test',
    'fields' => [
        ['key' => 'field_wgt_plain', 'name' => 'wgt_plain', 'type' => 'text'],
        ['key' => 'field_wgt_cfg', 'name' => 'wgt_cfg', 'type' => 'text'],
        ['key' => 'field_wgt_free', 'name' => 'wgt_free', 'type' => 'select', 'choices' => ['a' => 'a']],
        ['key' => 'field_wgt_opt', 'name' => 'wgt_opt', 'type' => 'text'],
        ['key' => 'field_wgt_rows', 'name' => 'wgt_rows', 'type' => 'repeater', 'sub_fields' => [
            ['key' => 'field_wgt_rows_title', 'name' => 'title', 'type' => 'text'],
            ['key' => 'field_wgt_rows_kind', 'name' => 'kind', 'type' => 'select', 'choices' => ['a' => 'a', 'b' => 'b']],
        ]],
        ['key' => 'field_wgt_grp', 'name' => 'wgt_grp', 'type' => 'group', 'sub_fields' => [
            ['key' => 'field_wgt_grp_note', 'name' => 'note', 'type' => 'number'],
        ]],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);

// the filter makes wgt_free translated, though its type says copy
add_filter('wp_go_languages/meta_policy', fn ($policy, $key) => $key === 'wgt_free' ? 'translate' : $policy, 20, 2);

$it = wgt_page('Locks test', '<!-- wp:paragraph --><p>Ciao</p><!-- /wp:paragraph -->');
$en = WpGoLanguages::create_translation($it, 'en', 'text');
$de = WpGoLanguages::create_translation($it, 'de', 'independent');
$fr = WpGoLanguages::create_translation(wgt_page('Locks test free', '<!-- wp:paragraph --><p>Ciao</p><!-- /wp:paragraph -->'), 'en', 'blocks');

$prepare = function (string $key, string $prefix, mixed $value = null): array {
    $field           = acf_get_field($key);
    $field['prefix'] = $prefix;
    $field['value']  = $value;

    return acf_prepare_field($field);
};
$has = fn (array $field, string $class) => str_contains((string) ($field['wrapper']['class'] ?? ''), $class);

// the translation's edit screen
$context        = State::$context;
State::$context = 'admin';
$_GET['post']   = $en;

$field = $prepare('field_wgt_plain', 'acf');
check('a field the filter makes copy is locked (disabled)', !empty($field['disabled']) && $has($field, 'wgl-copied'));

$field = $prepare('field_wgt_cfg', 'acf');
check('a field a wpml-config.xml makes copy is locked', !empty($field['disabled']));

$field = $prepare('field_wgt_free', 'acf');
check('a field the filter makes translate is not locked, though its type says copy', empty($field['disabled']) && !$has($field, 'wgl-copied'));

$field = $prepare('field_wgt_rows', 'acf', [['x'], ['y']]);
check('a copy repeater keeps its rows (min = max = 2), not disabled', ($field['min'] ?? null) === 2 && ($field['max'] ?? null) === 2 && $has($field, 'wgl-fixed-rows') && empty($field['disabled']));

$seen  = [];
$field = $prepare('field_wgt_rows_kind', 'acf[field_wgt_rows][row-1]');
check('a copy sub field is read-only, not disabled', !empty($field['readonly']) && empty($field['disabled']) && $has($field, 'wgl-copied'));
check('the sub field\'s rule is asked for its own key, wgt_rows_1_kind', in_array('wgt_rows_1_kind', $seen, true));

$field = $prepare('field_wgt_rows_title', 'acf[field_wgt_rows][row-1]');
check('a translate sub field stays editable', empty($field['readonly']) && !$has($field, 'wgl-copied'));

$seen  = [];
$field = $prepare('field_wgt_grp_note', 'acf[field_wgt_grp]');
check('a copy sub field of a group is locked, its key wgt_grp_note', !empty($field['readonly']) && in_array('wgt_grp_note', $seen, true));

$field = $prepare('field_wgt_rows_kind', 'acf-block_abc[field_wgt_rows][row-0]');
check('a block\'s field is left alone', empty($field['readonly']));

$field = $prepare('field_wgt_rows', 'acf', [['x'], ['y']]);
check('a copy repeater holding a text to translate is shown', !$has($field, 'wgl-copied'));
$field = $prepare('field_wgt_grp', 'acf');
check('a group with only copied fields is hidden', $has($field, 'wgl-copied'));

section('Copied fields: a clone\'s fields, tabs');

acf_add_local_field_group([
    'key'    => 'group_wgt_clone_src',
    'title'  => 'Clone source',
    'fields' => [
        ['key' => 'field_wgt_src_image', 'name' => 'image', 'type' => 'image'],
        ['key' => 'field_wgt_src_alt', 'name' => 'alt', 'type' => 'text'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'wgt_none']]],
]);
acf_add_local_field_group([
    'key'    => 'group_wgt_clone_img',
    'title'  => 'Clone source, an image alone',
    'fields' => [
        ['key' => 'field_wgt_img_image', 'name' => 'image', 'type' => 'image'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'wgt_none']]],
]);
acf_add_local_field_group([
    'key'    => 'group_wgt_clones',
    'title'  => 'Clones test',
    'fields' => [
        ['key' => 'field_wgt_tab_a', 'name' => '', 'type' => 'tab', 'label' => 'A'],
        ['key' => 'field_wgt_logo', 'name' => 'wgt_logo', 'type' => 'clone', 'clone' => ['group_wgt_clone_img'], 'display' => 'group', 'prefix_name' => 1],
        ['key' => 'field_wgt_tab_b', 'name' => '', 'type' => 'tab', 'label' => 'B'],
        ['key' => 'field_wgt_media', 'name' => 'wgt_media', 'type' => 'clone', 'clone' => ['group_wgt_clone_src'], 'display' => 'group', 'prefix_name' => 1],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);

$media = acf_get_field('field_wgt_media');
$named = fn (string $name) => array_values(array_filter($media['sub_fields'], fn ($f) => $f['_name'] === $name))[0];
same('copy', Core\field_policy($named('image')), 'a clone\'s image follows the original');
same('translate', Core\field_policy($named('alt')), 'its text is translated');

update_field('field_wgt_media', ['field_wgt_src_image' => 0, 'field_wgt_src_alt' => 'Media'], $it);
same('translate', Core\meta_policy('wgt_media_alt', $it), 'a clone\'s stored text is translate, through its reference');
same('copy', Core\meta_policy('wgt_media_image', $it), 'its stored image is copy');

$field = $prepare('field_wgt_logo', 'acf');
check('a clone with nothing to translate is hidden', $has($field, 'wgl-copied'));
$field = $prepare('field_wgt_media', 'acf');
check('one with a text is shown', !$has($field, 'wgl-copied'));
$field = $prepare('field_wgt_tab_a', 'acf');
check('a tab with only copied fields after it is hidden', str_contains((string) ($field['class'] ?? ''), 'wgl-copied'));
$field = $prepare('field_wgt_tab_b', 'acf');
check('one with a field to translate is shown', !str_contains((string) ($field['class'] ?? ''), 'wgl-copied'));

$_GET['post'] = $de;
$field = $prepare('field_wgt_plain', 'acf');
check('on a duplicate nothing is locked: its fields are all its own', empty($field['disabled']) && !$has($field, 'wgl-copied'));
$field = $prepare('field_wgt_rows', 'acf', [['x'], ['y']]);
check('its rows are free', !$has($field, 'wgl-fixed-rows'));

$_GET['post'] = $fr;
$field = $prepare('field_wgt_plain', 'acf');
check('nor on fixed blocks with free fields', empty($field['disabled']) && !$has($field, 'wgl-copied'));

$_GET['post'] = $it;
$field = $prepare('field_wgt_plain', 'acf');
check('on the original nothing is locked', empty($field['disabled']));

unset($_GET['post']);
State::$context = $context;

section('Options');

// a copy field, from the filter; a copy repeater whose cells keep their own rule
$options = ['options_wgt_opt' => 'IT', '_options_wgt_opt' => 'field_wgt_opt', 'options_en_wgt_opt' => 'EN', '_options_en_wgt_opt' => 'field_wgt_opt',
    'options_wgt_rows' => 2, '_options_wgt_rows' => 'field_wgt_rows', 'options_en_wgt_rows' => 1, '_options_en_wgt_rows' => 'field_wgt_rows'];

foreach ([0, 1] as $i) {
    $options += [
        "options_wgt_rows_{$i}_title" => "IT$i", "_options_wgt_rows_{$i}_title" => 'field_wgt_rows_title',
        "options_wgt_rows_{$i}_kind" => 'a', "_options_wgt_rows_{$i}_kind" => 'field_wgt_rows_kind',
        "options_en_wgt_rows_{$i}_title" => "EN$i", "_options_en_wgt_rows_{$i}_title" => 'field_wgt_rows_title',
        "options_en_wgt_rows_{$i}_kind" => 'b', "_options_en_wgt_rows_{$i}_kind" => 'field_wgt_rows_kind',
    ];
}

foreach ($options as $name => $value) {
    update_option($name, $value);
}

acf_get_store('values')->reset();

same('IT', get_field('wgt_opt', 'options_en'), 'a copy option reads the default language\'s value in English');

$rows = get_field('wgt_rows', 'options_en');
check(
    'a copy repeater has the default\'s 2 rows, its own titles, the default\'s kinds: ' . wp_json_encode($rows),
    is_array($rows) && count($rows) === 2 && $rows[0]['title'] === 'EN0' && $rows[1]['title'] === 'EN1' && $rows[0]['kind'] === 'a'
);
same('IT', get_field('wgt_opt', 'options'), 'the default language reads its own');

section('Unlink');

$it3 = wgt_page('Unlink test', '<!-- wp:paragraph --><p>Uno</p><!-- /wp:paragraph -->');
$en3 = WpGoLanguages::create_translation($it3, 'en', 'text');
Core\unlink($en3);
check('an unlinked translation has no mode left: mode() is null', get_post_meta($en3, Core\MODE_META, true) === '' && WpGoLanguages::mode($en3) === null);

$en3b = wp_insert_post(['post_type' => 'page', 'post_title' => 'x', 'post_status' => 'draft']);
WpGoLanguages::set_language($en3b, 'en');
WpGoLanguages::link($en3b, $it3);
update_post_meta($en3b, Core\MODE_META, 'text');

// the original leaves: the group of two is pruned
Core\unlink($it3);
check('the original unlinked, the other loses its mode too', get_post_meta($en3b, Core\MODE_META, true) === '' && !Core\is_translation($en3b));

section('A deleted original');

check('German is a language of this test', Core\is_language('de'));

$it4 = wgt_page('Delete test', '<!-- wp:paragraph --><p>Uno</p><!-- /wp:paragraph -->');
$en4 = WpGoLanguages::create_translation($it4, 'en', 'text');
$de4 = WpGoLanguages::create_translation($it4, 'de', 'text');
wp_update_post(['ID' => $de4, 'post_status' => 'publish']);
wp_delete_post($it4, true);
Core\forget_translations();

check('the first published translation (de) is the new original', !Core\is_translation($de4) && WpGoLanguages::mode($de4) === null);
check('the other names it, still fixed (de was fixed)', Core\original($en4) === $de4 && Core\is_fixed($en4));
same(['en' => $en4, 'de' => $de4], Core\translations($en4), 'the group holds both');

$it5 = wgt_page('Delete test 2', '<!-- wp:paragraph --><p>Uno</p><!-- /wp:paragraph -->');
$en5 = WpGoLanguages::create_translation($it5, 'en', 'independent');
$de5 = WpGoLanguages::create_translation($it5, 'de', 'text');
wp_update_post(wp_slash(['ID' => $en5, 'post_status' => 'publish', 'post_content' => '<!-- wp:heading --><h2>Its own</h2><!-- /wp:heading -->']));
wp_delete_post($it5, true);
Core\forget_translations();

check('an heir with other blocks: the fixed one turns independent', Core\original($de5) === $en5 && WpGoLanguages::mode($de5) === 'independent');
check('the heir is no translation', !Core\is_translation($en5));

section('A classic original');

$it7 = wgt_page('Classic test', '<p>Testo classico</p>');
$en7 = WpGoLanguages::create_translation($it7, 'en', 'text');
wgt_save($en7, '<p>Classic text</p>');
same('<p>Classic text</p>', wgt_content($en7), 'the translation\'s own save keeps its text');
wgt_save($it7, '<p>Testo classico cambiato</p>');
same('<p>Classic text</p>', wgt_content($en7), 'the original\'s save keeps it too');

section('Core containers');

$group = fn (string $color, string $text) =>
    '<!-- wp:group {"backgroundColor":"' . $color . '","metadata":{"wgId":"g1"}} --><div class="wp-block-group has-' . $color . '-background-color has-background">'
    . '<!-- wp:paragraph {"metadata":{"wgId":"p1"}} --><p>' . $text . '</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$merged = Core\merge($group('blue', 'Ciao'), $group('red', 'Hello'));
$b      = parse_blocks($merged)[0];
check('a group has the original\'s color, in its attributes and its markup', $b['attrs']['backgroundColor'] === 'blue' && str_contains($b['innerHTML'], 'has-blue-background-color') && !str_contains($merged, 'red'));
check('the group\'s inner paragraph is the translation\'s', str_contains($merged, '<p>Hello</p>'));

$details = fn (string $summary, string $text) => '<!-- wp:details {"metadata":{"wgId":"d1"}} --><details class="wp-block-details"><summary>' . $summary . '</summary>'
    . '<!-- wp:paragraph {"metadata":{"wgId":"p2"}} --><p>' . $text . '</p><!-- /wp:paragraph --></details><!-- /wp:details -->';
$merged = Core\merge($details('Dettagli', 'Uno'), $details('Details', 'One'));
check('a Details with the same settings keeps its translated summary', str_contains($merged, '<summary>Details</summary>') && str_contains($merged, '<p>One</p>'));

$once = Core\merge($group('blue', 'Ciao'), $group('red', 'Hello'));
same($once, Core\merge($group('blue', 'Ciao'), $once), 'a merge of its own result changes nothing');

$image  = fn (int $media, string $url, string $alt) => '<!-- wp:image {"id":' . $media . ',"metadata":{"wgId":"i1"}} --><figure class="wp-block-image"><img src="' . $url . '" alt="' . $alt . '" class="wp-image-' . $media . '"/></figure><!-- /wp:image -->';
$merged = Core\merge($image(5, 'a.jpg', 'Gatto'), $image(7, 'b.jpg', 'Cat'));
$b      = parse_blocks($merged)[0];
check('a core image is the translation\'s whole: attributes and markup agree', $b['attrs']['id'] === 7 && str_contains($b['innerHTML'], 'wp-image-7') && str_contains($b['innerHTML'], 'b.jpg'));

section('The featured image and the template');

$attachment = wp_insert_attachment(['post_title' => 'wgt test image', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], false);
$it8 = wgt_page('Media test', '<!-- wp:paragraph --><p>Uno</p><!-- /wp:paragraph -->');
$en8 = WpGoLanguages::create_translation($it8, 'en', 'independent');
update_post_meta($it8, '_thumbnail_id', $attachment);
update_post_meta($it8, '_wp_page_template', 'page-wide.php');

Core\set_mode($en8, 'text');
check('switched into fixed, the featured image and the template follow', (int) get_post_meta($en8, '_thumbnail_id', true) === $attachment && get_post_meta($en8, '_wp_page_template', true) === 'page-wide.php');

wp_update_post(['ID' => $en8, 'post_title' => 'Media test EN', 'meta_input' => ['_thumbnail_id' => 999999, '_wp_page_template' => 'other.php']]);
check('a fixed translation\'s own save puts them back', (int) get_post_meta($en8, '_thumbnail_id', true) === $attachment && get_post_meta($en8, '_wp_page_template', true) === 'page-wide.php');

section('blocks_dropped()');

$it9 = wgt_page('Dropped test', '');
$en9 = WpGoLanguages::create_translation($it9, 'en', 'independent');

// the original without IDs; the duplicate with a block of its own inside and one at the top
$original9 = serialize_blocks([wgt_acf('sticky-columns', ['title' => ['text' => 'T'], 'side' => 'left'], [wgt_acf('rich-text', ['text' => '<p>A</p>'])])]);
wgt_raw_content($it9, $original9);

$duplicate = parse_blocks(Core\stamp_ids($original9));
$duplicate[0]['innerBlocks'][]  = wgt_acf('rich-text', ['text' => '<p>own</p>']);
$duplicate[0]['innerContent'][] = null;
$duplicate[] = wgt_para('own top');
wgt_raw_content($en9, serialize_blocks($duplicate));

$dropped = Core\blocks_dropped($en9);
check('blocks_dropped() counts the nested one too: ' . implode(', ', $dropped), count($dropped) === 2);
same($original9, wgt_content($it9), 'blocks_dropped() wrote nothing to the original');

section('A stale editor');

$titles_of = function (int $id): array {
    $data = parse_blocks(wgt_content($id))[0]['attrs']['data'];
    $out  = [];

    for ($i = 0; $i < (int) $data['items']; $i++) {
        $out[] = $data["items_{$i}_title"];
    }

    return $out;
};
$accordion = fn (array $titles) => serialize_blocks([wgt_acf('accordion', [
    'title' => ['text' => 'Domande'],
    'items' => array_map(fn ($title) => ['title' => $title, 'text' => "<p>$title</p>"], $titles),
], [], 'a1')]);

// as the editor makes a page: an auto-draft, then saved, each save a revision
$it10 = wgt_page('Stale test', '');
wp_update_post(['ID' => $it10, 'post_status' => 'auto-draft']);
wgt_save($it10, $accordion(['D1', 'D2', 'D3']));
wp_update_post(['ID' => $it10, 'post_status' => 'publish']);
$en10 = WpGoLanguages::create_translation($it10, 'en', 'text');

$blocks = parse_blocks(wgt_content($en10));

foreach ([0, 1, 2] as $i) {
    $blocks[0]['attrs']['data']["items_{$i}_title"] = 'Q' . ($i + 1);
    $blocks[0]['attrs']['data']["items_{$i}_text"]  = '<p>Q' . ($i + 1) . '</p>';
}

wgt_save($en10, serialize_blocks($blocks));
same(['Q1', 'Q2', 'Q3'], $titles_of($en10), 'the rows are translated: Q1 Q2 Q3');

// the translator's editor loads now
$editor = wgt_content($en10);
check('the translation\'s block carries the version its rows are in step with', str_contains($editor, '"wgRows":"'));

wgt_save($it10, $accordion(['D3', 'D1', 'D2']));
same(['Q3', 'Q1', 'Q2'], $titles_of($en10), 'the original\'s reorder moved the translation\'s rows: Q3 Q1 Q2');

// the stale editor saves, Q1 edited
wgt_save($en10, str_replace('"items_0_title":"Q1"', '"items_0_title":"Q1 edited"', $editor));
same(['Q3', 'Q1 edited', 'Q2'], $titles_of($en10), 'a stale save: the texts follow their rows');
same('<p>Q3</p>', parse_blocks(wgt_content($en10))[0]['attrs']['data']['items_0_text'], 'a stale save: the other cells are in the original\'s order');

wgt_save($en10, wgt_content($en10));
same(['Q3', 'Q1 edited', 'Q2'], $titles_of($en10), 'saved again, in step: unchanged');

// without the version in the revisions: rows by index, as documented
$editor = wgt_content($en10);
$wpdb->delete($wpdb->posts, ['post_parent' => $it10, 'post_type' => 'revision']);
clean_post_cache($it10);
wgt_save($it10, $accordion(['D2', 'D3', 'D1']));
wgt_save($en10, $editor);
same(['Q3', 'Q1 edited', 'Q2'], $titles_of($en10), 'a stale save with no revision to read: rows by index');
