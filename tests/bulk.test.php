<?php

// @require fixtures/third-language.php

/**
 * Translating from the lists (src/Admin/bulk.php): what an item lacks, its
 * translations made in the languages and mode asked (a duplicate, a fixed
 * one, the default language's a copy that becomes the original), the row
 * action and the bulk action, their fallbacks without the script, the
 * route the dialog calls, and the notice. A third language (German) gives
 * items two languages to lack.
 */

require_once __DIR__ . '/fixtures/content.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;

register_taxonomy('wgt_topic', 'page', ['public' => true, 'hierarchical' => true, 'show_ui' => true, 'label' => 'Topics']);
add_filter('wp_go_languages/config', fn ($config) => array_replace($config, ['taxonomies' => [...$config['taxonomies'], 'wgt_topic']]));
Core\config(true);

$term = fn (string $name, string $code) => (int) WpGoLanguages::in($code, fn () => wp_insert_term($name, 'wgt_topic')['term_id']);
$admin = get_current_user_id();

$rest = function (string $route, array $params = []) {
    $request = new WP_REST_Request('POST', $route);
    $request->set_body_params($params);

    return rest_do_request($request);
};

section('What an item lacks');

$alone = wgt_page('Sola wgt', serialize_block(wgt_para('Ciao')));
same(['en', 'de'], Admin\missing_languages($alone, 'post'), 'a page alone lacks every other language');

$pair = wgt_page('Coppia wgt', serialize_block(wgt_para('Ciao')));
$pair_en = WpGoLanguages::create_translation($pair, 'en');
same(['de'], Admin\missing_languages($pair, 'post'), 'a page with its English lacks German');
same(['de'], Admin\missing_languages($pair_en, 'post'), 'as does its translation: the group is what counts');

$nolang = wp_insert_post(['post_type' => 'page', 'post_title' => 'Senza wgt', 'post_status' => 'publish']);
wp_delete_object_term_relationships($nolang, Core\LANGUAGE_TAXONOMY);
same([], Admin\missing_languages($nolang, 'post'), 'a page with no language yet lacks nothing (its cell offers no "+")');

section('Making them');

$done = Admin\translate_missing($alone, 'post');
same(['en', 'de'], array_keys($done['created']), 'every missing language, with no languages asked');
same([], $done['failed'], 'nothing failed');
same(['it', 'en', 'de'], array_keys(Core\translations($alone)), 'the group has all three');
same('draft', get_post_status($done['created']['de']), 'each a draft');
same('independent', Core\mode_of($done['created']['en']), 'a duplicate by default');

$fixed = wgt_page('Fissa wgt', serialize_block(wgt_para('Ciao')));
$done = Admin\translate_missing($fixed, 'post', ['en'], 'blocks');
same(['en'], array_keys($done['created']), 'only the languages asked');
same('blocks', Core\mode_of($done['created']['en']), 'as the mode asked');
same(['de'], Admin\missing_languages($fixed, 'post'), 'German still to make');

same(['created' => [], 'failed' => []], Admin\translate_missing($fixed, 'post', ['en']), 'a language there already is skipped, not failed');

// an English page lacks the default language: its copy becomes the original
$english = wgt_page('English wgt', serialize_block(wgt_para('Hello')), 'en');
$done = Admin\translate_missing($english, 'post', ['it'], 'text');
same('independent', Core\mode_of($english), 'into the default language, whatever the mode: the English page turns a duplicate');
same($done['created']['it'], Core\original($english), 'and the Italian copy is the original');

$topic = $term('Argomento wgt', 'it');
$done = Admin\translate_missing($topic, 'term');
same(['en', 'de'], array_keys($done['created']), 'a term\'s translations, every missing language');
same('Argomento wgt', get_term($done['created']['de'])->name, 'each a copy of it');

section('The row action');

$lonely = wgt_page('Riga wgt', serialize_block(wgt_para('Ciao')));
$actions = Admin\row_action(['edit' => 'Edit'], $lonely, 'post');
check('a page lacking a language gets "Translate"', isset($actions['wgl_translate']) && str_contains($actions['wgl_translate'], 'class="wgl-translate" data-wgl-id="' . $lonely . '"'));
check('its link, without the script, makes them all', str_contains(html_entity_decode($actions['wgl_translate']), 'action=wgl_translate_missing&kind=post&id=' . $lonely) && str_contains($actions['wgl_translate'], '_wpnonce='));
same(['edit' => 'Edit'], Admin\row_action(['edit' => 'Edit'], $alone, 'post'), 'a page with every language gets nothing');
same(['edit' => 'Edit'], Admin\row_action(['edit' => 'Edit'], $nolang, 'post'), 'nor one with no language yet');

$post = wp_insert_post(['post_type' => 'wgt_untranslated', 'post_title' => 'x', 'post_status' => 'publish']);
same([], Admin\row_action([], (int) $post, 'post'), 'nor an item of a type that isn\'t translated');

check('a term lacking a language gets it too', isset(Admin\row_action([], $term('Riga termine wgt', 'it'), 'term')['wgl_translate']));

$subscriber = wp_insert_user(['user_login' => 'wgt_bulk_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
wp_set_current_user($subscriber);
same([], Admin\row_action([], $lonely, 'post'), 'nor a user who can\'t edit it');
wp_set_current_user($admin);

// without the script: its link, then the list
$_REQUEST['_wpnonce'] = wp_create_nonce("wgl_translate_missing_post_{$lonely}");
$_GET = ['kind' => 'post', 'id' => (string) $lonely];
$_SERVER['HTTP_REFERER'] = admin_url('edit.php?post_type=page&wgl_failed=3');
$stop = wgt_stopped('WpGo\\Languages\\Admin\\translate_missing_action');
parse_str((string) parse_url($stop['target'], PHP_URL_QUERY), $query);
same(['post_type' => 'page', 'wgl_translated' => '2'], $query, 'the link makes both, then back to the list with the count (the last one\'s gone)');
same('independent', Core\mode_of(Core\translations($lonely)['de']), 'as duplicates');

$_REQUEST['_wpnonce'] = wp_create_nonce('wgl_translate_missing_post_' . $nolang);
$_GET = ['kind' => 'post', 'id' => (string) $nolang];
wp_set_current_user($subscriber);
same('die', wgt_stopped('WpGo\\Languages\\Admin\\translate_missing_action')['kind'], 'refused to a user who can\'t');
wp_set_current_user($admin);
$_GET = $_REQUEST = [];
unset($_SERVER['HTTP_REFERER']);

section('The bulk action');

same(['trash' => 'Trash', 'wgl_translate' => 'Translate…'], Admin\bulk_action_option(['trash' => 'Trash']), '"Translate…" among the bulk actions');

$back = admin_url('edit.php?post_type=page');
same($back, Admin\handle_bulk_action($back, 'trash', [$lonely], 'post'), 'another action is left alone');

$one = wgt_page('Massa uno wgt', serialize_block(wgt_para('Uno')));
$two = wgt_page('Massa due wgt', serialize_block(wgt_para('Due')));
WpGoLanguages::create_translation($two, 'en');
parse_str((string) parse_url(Admin\handle_bulk_action($back, 'wgl_translate', [$one, $two, $alone], 'post'), PHP_URL_QUERY), $query);
same('3', $query['wgl_translated'] ?? null, 'without the script: each ticked item, every language it lacks (2 + 1 + 0)');
check('and none failed', !isset($query['wgl_failed']));

parse_str((string) parse_url(Admin\handle_bulk_action($back, 'wgl_translate', [$one], 'post'), PHP_URL_QUERY), $query);
same('0', $query['wgl_translated'] ?? null, 'nothing left to make: 0, for the notice to say so');

wp_set_current_user($subscriber);
$three = wgt_page('Massa tre wgt', serialize_block(wgt_para('Tre')));
parse_str((string) parse_url(Admin\handle_bulk_action($back, 'wgl_translate', [$three], 'post'), PHP_URL_QUERY), $query);
wp_set_current_user($admin);
same(['it'], array_keys(Core\translations($three)), 'an item the user can\'t translate is left as it is');

section('The route the dialog calls');

$page = wgt_page('Rotta wgt', serialize_block(wgt_para('Ciao')));
$response = $rest("/wgl/v1/posts/$page/translate", ['langs' => ['de'], 'mode' => 'blocks']);
same(200, $response->get_status(), 'a page\'s translations, made');
same(['de'], array_keys((array) $response->get_data()['created']), 'in the languages asked');
same(['de' => admin_url('post.php?post=' . Core\translations($page)['de'] . '&action=edit')], (array) $response->get_data()['edit'], 'with where each is edited');
same('blocks', Core\mode_of(Core\translations($page)['de']), 'as the mode asked');
same('{"created":{},"failed":{},"edit":{}}', wp_json_encode($rest("/wgl/v1/posts/$page/translate", ['langs' => ['de']])->get_data()), 'one there already: nothing made, as objects');
same(['en'], array_keys((array) $rest("/wgl/v1/posts/$page/translate")->get_data()['created']), 'no languages: every missing one');

same(400, $rest("/wgl/v1/posts/$page/translate", ['langs' => ['xx']])->get_status(), 'a language not the site\'s is refused');
same(400, $rest("/wgl/v1/posts/$page/translate", ['mode' => 'copy'])->get_status(), 'as is a mode that isn\'t one');

$topic = $term('Rotta termine wgt', 'it');
same(['en'], array_keys((array) $rest("/wgl/v1/terms/$topic/translate", ['langs' => ['en']])->get_data()['created']), 'a term\'s, under terms/');

wp_set_current_user($subscriber);
same(403, $rest('/wgl/v1/posts/' . wgt_page('Vietata wgt') . '/translate')->get_status(), 'refused to a user who can\'t edit the page');
wp_set_current_user($admin);

section('The notice');

set_current_screen('edit-page');
$_GET = ['wgl_translated' => '3'];
check('the count, as drafts for posts', str_contains(wgt_output('WpGo\\Languages\\Admin\\bulk_notices'), '3 translations created as drafts'));
$_GET = ['wgl_translated' => '1'];
check('one, in the singular', str_contains(wgt_output('WpGo\\Languages\\Admin\\bulk_notices'), '1 translation created as a draft'));
$_GET = ['wgl_translated' => '0'];
check('none: every language was there', str_contains(wgt_output('WpGo\\Languages\\Admin\\bulk_notices'), 'Nothing to translate'));
$_GET = ['wgl_translated' => '0', 'wgl_failed' => '2'];
$html = wgt_output('WpGo\\Languages\\Admin\\bulk_notices');
check('failures, in an error notice, and no "nothing to translate"', str_contains($html, 'notice-error') && str_contains($html, '2 translations couldn') && !str_contains($html, 'Nothing to translate'));

set_current_screen('edit-wgt_topic');
$_GET = ['wgl_translated' => '2'];
check('for terms, no draft', str_contains(wgt_output('WpGo\\Languages\\Admin\\bulk_notices'), '2 translations created: translate their names'));
$_GET = [];
same('', wgt_output('WpGo\\Languages\\Admin\\bulk_notices'), 'nothing without the count');
check('the counts leave the address once shown', in_array('wgl_translated', wp_removable_query_args(), true) && in_array('wgl_failed', wp_removable_query_args(), true));
$GLOBALS['current_screen'] = null;

wgt_cleanup();
