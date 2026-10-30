<?php

// @require fixtures/third-language.php

/**
 * Terms in the admin, as pages are: a term's translation made (a copy, the
 * parent's translation as parent, into the default language the original),
 * two terms' groups joined and left, the list's Languages cell and its "+",
 * the edit screen's panel data, and the routes under wgl/v1/terms/<id>. A
 * third language (German) gives groups of three.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;

register_taxonomy('wgt_topic', 'page', ['public' => true, 'hierarchical' => true, 'show_ui' => true, 'label' => 'Topics']);
$topics = fn ($config) => array_replace($config, ['taxonomies' => [...$config['taxonomies'], 'wgt_topic']]);
add_filter('wp_go_languages/config', $topics);
Core\config(true);

$term = fn (string $name, string $code, int $parent = 0) => (int) WpGoLanguages::in($code, fn () => wp_insert_term($name, 'wgt_topic', ['parent' => $parent])['term_id']);
$ids  = fn (array $found) => array_column($found, 'id');

section('A term\'s translation');

$parent_it = $term('Notizie wgt', 'it');
$parent_en = Core\create_term_translation($parent_it, 'en');
$child_it  = $term('Eventi wgt', 'it', $parent_it);
wp_update_term($child_it, 'wgt_topic', ['description' => 'Gli eventi']);
$child_en  = Core\create_term_translation($child_it, 'en');

check('a term\'s translation is made', is_int($child_en) && $child_en > 0);
same('en', Core\language_of($child_en, 'term'), 'in the language asked');
same($child_it, Core\original($child_en, 'term'), 'linked to its original');
same(['Eventi wgt', 'Gli eventi'], [get_term($child_en)->name, get_term($child_en)->description], 'a copy of its name and description, to translate');
same($parent_en, get_term($child_en)->parent, 'its parent the parent\'s translation');
same('eventi-wgt-en', get_term($child_en)->slug, 'its slug the original\'s with the language\'s code: not the original handed back');
check('a second one in a language is refused', is_wp_error(Core\create_term_translation($child_it, 'en')));
check('nor in its own language', is_wp_error(Core\create_term_translation($child_it, 'it')));

// into the default language: the copy is the original
$solo_en = $term('Only English wgt', 'en');
$solo_de = Core\create_term_translation($solo_en, 'de');
$solo_it = Core\create_term_translation($solo_en, 'it');
same($solo_it, Core\original($solo_en, 'term'), 'a term made in the default language is the original at once');
same($solo_it, Core\original($solo_de, 'term'), 'and the others name it');

section('Joining and leaving');

$alone_en = $term('Joined wgt', 'en');
$alone_it = $term('Unito wgt', 'it');
same(true, Core\connect($alone_en, $alone_it, 'term'), 'an English term made on its own is linked to the Italian one');
same($alone_it, Core\original($alone_en, 'term'), 'the default language\'s is the original');
check('a language twice is refused', is_wp_error(Core\connect($term('Altro wgt', 'it'), $alone_en, 'term')));
check('as are terms of two taxonomies', is_wp_error(Core\connect($alone_en, (int) wp_insert_term('Cat wgt', 'category')['term_id'], 'term')));

Core\leave_group($alone_it, 'term');
same(['it' => $alone_it], Core\translations($alone_it, 'term'), 'the original out: on its own');
same(['en' => $alone_en], Core\translations($alone_en, 'term'), 'its only translation alone too');

$three_it = $term('Tre wgt', 'it');
$three_en = Core\create_term_translation($three_it, 'en');
$three_de = Core\create_term_translation($three_it, 'de');
Core\leave_group($three_it, 'term');
same(['en' => $three_en, 'de' => $three_de], Core\translations($three_de, 'term'), 'an original leaving a group of three: the two stay together');
same($three_en, Core\original($three_de, 'term'), 'the first is their original');

section('The list\'s cell');

$cell = Admin\languages_cell($child_it, 'term');
check('a term\'s row has its flag ringed, and its translation linked', str_contains($cell, 'wgl-lang--own') && str_contains($cell, 'href="' . esc_url(Admin\term_edit_url($child_en)) . '"'));
check('the original\'s flag has the star', preg_match('#alt="IT"[^>]*><span class="wgl-lang__original#', $cell) === 1);
check('a missing language has a "+" for the term, with a fixed link and no fixed blocks', str_contains($cell, 'data-wgl-kind="terms"') && str_contains($cell, 'data-wgl-lang="de"') && (bool) preg_match('/data-wgl-fixed="[^"]*mode=fixed/', str_replace(['&#038;', '&amp;'], '&', $cell)) && str_contains($cell, 'data-wgl-structure=""'));
check('which makes the translation without the dialog', str_contains($cell, 'action=wgl_create_term_translation'));
same($cell, Admin\term_column('', 'wgl_languages', $child_it), 'the taxonomy\'s column filter gives it');
same('kept', Admin\term_column('kept', 'name', $child_it), 'and leaves the other columns alone');

section('The edit screen\'s panel');

$data = Admin\term_panel_data($child_en);
same('en', $data['language'], 'the term\'s language');
same($child_it, $data['original']['id'] ?? null, 'what it translates');
same([true, false], [$data['can_fix'], $data['can_structure']], 'a term\'s modes: duplicate or fixed, no fixed blocks');
same('duplicate', $data['mode'], 'made without one, a duplicate');
same(['it', 'en', 'de'], array_column($data['languages'], 'code'), 'a row per language');
same([true, false, false], array_column($data['languages'], 'is_original'), 'the original marked');

section('The routes');

$rest = function (string $method, string $route, array $params = []) {
    $request = new WP_REST_Request($method, $route);

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    return rest_do_request($request);
};

$free_de = $term('Frei wgt', 'de');
$answer  = $rest('GET', "/wgl/v1/terms/$alone_it/candidates", ['search' => 'Frei']);
same([200, [$free_de]], [$answer->get_status(), $ids($answer->get_data())], 'candidates: the groups a term may join');
same([], $ids($rest('GET', "/wgl/v1/terms/$child_it/candidates", ['search' => 'Eventi'])->get_data()), 'never its own group');

same(200, $rest('POST', "/wgl/v1/terms/$alone_it/link", ['other' => $free_de])->get_status(), 'link: done');
same($alone_it, Core\original($free_de, 'term'), 'the Italian term the original');
same(200, $rest('POST', "/wgl/v1/terms/$alone_it/unlink", ['target' => $free_de])->get_status(), 'unlink: done');
same(['de' => $free_de], Core\translations($free_de, 'term'), 'the German term alone again');
same(400, $rest('POST', "/wgl/v1/terms/$alone_it/unlink")->get_status(), 'a term alone has nothing to unlink');

same(200, $rest('POST', "/wgl/v1/terms/$three_de/original")->get_status(), 'original: a group with no Italian term may choose');
same($three_de, Core\original($three_en, 'term'), 'the German term leads it');
same(400, $rest('POST', "/wgl/v1/terms/$child_en/original")->get_status(), 'one with an Italian term may not');

same(200, $rest('POST', "/wgl/v1/terms/$free_de/language", ['language' => 'en'])->get_status(), 'language: a term alone moves');
same('en', Core\language_of($free_de, 'term'), 'to English');
same(400, $rest('POST', "/wgl/v1/terms/$child_it/language", ['language' => 'de'])->get_status(), 'one with translations keeps its language');

$admin      = get_current_user_id();
$subscriber = wp_insert_user(['user_login' => 'wgt_term_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
wp_set_current_user($subscriber);
same(403, $rest('POST', "/wgl/v1/terms/$child_it/unlink")->get_status(), 'a user who can\'t edit terms is refused');
wp_set_current_user($admin);

section('A term\'s fields');

acf_add_local_field_group([
    'key'    => 'group_wgt_topic_fields',
    'title'  => 'Topic fields',
    'fields' => [
        ['key' => 'field_wgt_topic_icon', 'name' => 'wgt_icon', 'type' => 'text', 'wgl_translation' => 'copy'],
        ['key' => 'field_wgt_topic_intro', 'name' => 'wgt_intro', 'type' => 'text', 'wgl_translation' => 'translate'],
        ['key' => 'field_wgt_topic_note', 'name' => 'wgt_note', 'type' => 'text', 'wgl_translation' => 'ignore'],
    ],
    'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'wgt_topic']]],
]);

$field_it = $term('Campi wgt', 'it');
update_field('field_wgt_topic_icon', 'star', 'term_' . $field_it);
update_field('field_wgt_topic_intro', 'Ciao', 'term_' . $field_it);
update_field('field_wgt_topic_note', 'Nota', 'term_' . $field_it);

$fixed_en = Core\create_term_translation($field_it, 'en', 'fixed');
$dup_de   = Core\create_term_translation($field_it, 'de');

same('fixed', Core\mode_of($fixed_en, 'term'), 'a term\'s translation made fixed');
same(['star', 'Ciao', ''], [get_term_meta($fixed_en, 'wgt_icon', true), get_term_meta($fixed_en, 'wgt_intro', true), get_term_meta($fixed_en, 'wgt_note', true)], 'its fields copied as it is made, but an ignored one');
same('star', get_term_meta($dup_de, 'wgt_icon', true), 'a duplicate\'s too');
same('copy', Core\meta_policy('wgt_icon', $field_it, null, 'term'), 'a term\'s meta key has its field\'s rule, through its reference');
check('a bad mode is refused', is_wp_error(Core\create_term_translation($term('Modo wgt', 'it'), 'en', 'structure')));

update_term_meta($fixed_en, 'wgt_intro', 'Hello');
update_term_meta($dup_de, 'wgt_icon', 'mine');
update_field('field_wgt_topic_icon', 'moon', 'term_' . $field_it);
wp_update_term($field_it, 'wgt_topic', ['description' => 'saved']);

same('moon', get_term_meta($fixed_en, 'wgt_icon', true), 'the original\'s save brings its copy field to a fixed translation');
same('Hello', get_term_meta($fixed_en, 'wgt_intro', true), 'its translated one stays its own');
same('mine', get_term_meta($dup_de, 'wgt_icon', true), 'a duplicate keeps its own');

$context = Core\State::$context;
Core\State::$context = 'admin';
$_GET['tag_ID'] = (string) $fixed_en;
$icon = Core\lock_copied_field(array_merge(acf_get_field('field_wgt_topic_icon'), ['prefix' => 'acf', 'value' => 'moon']));
$intro = Core\lock_copied_field(array_merge(acf_get_field('field_wgt_topic_intro'), ['prefix' => 'acf', 'value' => 'Hello']));
check('on a fixed term\'s screen its copy field is hidden, not posted', str_contains((string) $icon['wrapper']['class'], 'wgl-copied') && !empty($icon['disabled']));
check('its translatable one shown', !str_contains((string) $intro['wrapper']['class'], 'wgl-copied'));
$_GET['tag_ID'] = (string) $dup_de;
$icon = Core\lock_copied_field(array_merge(acf_get_field('field_wgt_topic_icon'), ['prefix' => 'acf', 'value' => 'mine']));
check('on a duplicate\'s, nothing hidden', !str_contains((string) $icon['wrapper']['class'], 'wgl-copied'));
unset($_GET['tag_ID']);
Core\State::$context = $context;

same(200, $rest('POST', "/wgl/v1/terms/$dup_de/mode", ['mode' => 'fixed'])->get_status(), 'mode: a duplicate term turns fixed');
same('moon', get_term_meta($dup_de, 'wgt_icon', true), 'its copy field the original\'s at once');
same(400, $rest('POST', "/wgl/v1/terms/$field_it/mode", ['mode' => 'fixed'])->get_status(), 'an original has no mode');

remove_filter('wp_go_languages/config', $topics);
Core\config(true);
