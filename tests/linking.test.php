<?php

// @require fixtures/third-language.php

/**
 * Linking pages made on their own (the editor's "Link…", Core\connect()):
 * the default language's page the original, the pages that join made
 * duplicates, a language twice refused; unlinking (Core\leave_group()), an
 * original handing its group over; the pages a page may be linked to
 * (link_candidates()); and the REST routes the panel calls. A third language
 * (German) gives groups of three.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;

section('Linking two pages');

$it = wgt_page('Servizi wgt', '', 'it', ['post_name' => 'wgt-servizi']);
$en = wgt_page('Services wgt', '', 'en', ['post_name' => 'wgt-services']);

same(true, Core\connect($en, $it), 'an English page made on its own is linked to the Italian one');
same(['it' => $it, 'en' => $en], Core\translations($en), 'they are one group');
same($it, Core\original($en), 'the default language\'s page is the original, though linked from the English one');
same('independent', Core\mode_of($en), 'the English page is a duplicate, its content kept');
same(null, Core\mode_of($it), 'the Italian one has no mode');

$it2 = wgt_page('Contatti wgt', '', 'it', ['post_name' => 'wgt-contatti-link']);
$en2 = wgt_page('Contact wgt', '', 'en', ['post_name' => 'wgt-contact-link']);
same(true, Core\connect($it2, $en2), 'linked from the Italian side');
same($it2, Core\original($en2), 'the Italian page is the original all the same');

section('Groups that meet');

// an English original with a fixed German translation, then the Italian page
$en3 = wgt_page('Team wgt', serialize_blocks([wgt_acf('rich-text', ['text' => '<p>Team</p>'])]), 'en', ['post_name' => 'wgt-team']);
$de3 = WpGoLanguages::create_translation($en3, 'de', 'text');
$it3 = wgt_page('Squadra wgt', '', 'it', ['post_name' => 'wgt-squadra']);

same(true, Core\connect($en3, $it3), 'an English group meets a lone Italian page');
same(['it' => $it3, 'en' => $en3, 'de' => $de3], Core\translations($de3), 'all three in one group');
same($it3, Core\original($en3), 'the Italian page is the original');
same($it3, Core\original($de3), 'the German page names it too');
same('independent', Core\mode_of($en3), 'the old English original is a duplicate');
same('independent', Core\mode_of($de3), 'the fixed German page too: its blocks were the English page\'s, not the new original\'s');

// two groups with no default language's page: the page's own stays
$en4 = wgt_page('Prezzi EN wgt', '', 'en', ['post_name' => 'wgt-prices']);
$de4 = wgt_page('Preise wgt', '', 'de', ['post_name' => 'wgt-preise']);
same(true, Core\connect($de4, $en4), 'no Italian page on either side');
same($de4, Core\original($en4), 'the page linked from keeps its group, and is the original');

section('What is refused');

$it5 = wgt_page('Lavora con noi wgt', '', 'it', ['post_name' => 'wgt-lavora']);
$en5 = WpGoLanguages::create_translation($it5, 'en');
$en6 = wgt_page('Careers wgt', '', 'en', ['post_name' => 'wgt-careers']);
$refused = Core\connect($en6, $it5);
check('a page whose group has English already can\'t take another English one', is_wp_error($refused) && $refused->get_error_code() === 'wgl_connect_taken');
same(['it' => $it5, 'en' => $en5], Core\translations($it5), 'and its group is as it was');
check('nor two pages of one language', is_wp_error(Core\connect($en6, $en5)));
check('nor a page with itself', is_wp_error(Core\connect($en6, $en6)));

$post = wp_insert_post(['post_type' => 'post', 'post_title' => 'Articolo wgt', 'post_status' => 'publish']);
check('nor posts of different types', is_wp_error(Core\connect($en6, $post)));

section('Unlinking');

// a translation out of a group of three
$it7 = wgt_page('Storia wgt', '', 'it', ['post_name' => 'wgt-storia']);
$en7 = WpGoLanguages::create_translation($it7, 'en', 'text');
$de7 = WpGoLanguages::create_translation($it7, 'de');
Core\leave_group($en7);
same(['en' => $en7], Core\translations($en7), 'an unlinked translation is on its own');
same(null, Core\mode_of($en7), 'with no mode: it is an original now');
same(['it' => $it7, 'de' => $de7], Core\translations($it7), 'the others stay together');

// the original out: another takes the group
Core\leave_group($it7);
same(['it' => $it7], Core\translations($it7), 'an unlinked original is on its own');
same(['de' => $de7], Core\translations($de7), 'its last translation alone too');
same(null, Core\mode_of($de7), 'and an original, with no mode');

$it8 = wgt_page('Sede wgt', '', 'it', ['post_name' => 'wgt-sede']);
$en8 = WpGoLanguages::create_translation($it8, 'en');
$de8 = WpGoLanguages::create_translation($it8, 'de');
wp_publish_post($en8);
Core\leave_group($it8);
same(['en' => $en8, 'de' => $de8], Core\translations($de8), 'an original leaving a group of three: the two stay together');
same($en8, Core\original($de8), 'the published one is their original');

section('The pages a page may be linked to');

$it9  = wgt_page('Clienti wgt', '', 'it', ['post_name' => 'wgt-clienti']);
$free = wgt_page('Clients free wgt', '', 'en', ['post_name' => 'wgt-clients-free']);
$ids  = fn (array $found) => array_column($found, 'id');

$found = Admin\link_candidates($it9, 'en', 'wgt');
check('an English page on its own is offered', in_array($free, $ids($found), true));
check('one in a group with an Italian page is not', !in_array($en, $ids($found), true) && !in_array($en5, $ids($found), true));
check('nor a page of another language', !in_array($it, $ids($found), true));
same([$free], $ids(Admin\link_candidates($it9, 'en', 'Clients free')), 'the search keeps to the titles that match');
same([], Admin\link_candidates($it9, 'it'), 'nothing in the page\'s own language');
same(['id', 'title', 'status', 'edit', 'language', 'language_name', 'flag', 'languages'], array_keys($found[0] ?? []), 'each with its title, status, edit link, language and the group\'s languages');

$de_free = wgt_page('Kunden frei wgt', '', 'de', ['post_name' => 'wgt-kunden-frei']);
$any     = Admin\link_candidates($it9, '', 'frei');
same([$de_free], $ids($any), 'no language asked: every other language\'s free pages');
same('Deutsch', $any[0]['language_name'] ?? null, 'named by their language');
check('never the page\'s own language', !in_array('it', array_column(Admin\link_candidates($it9, '', 'wgt'), 'language'), true));

section('The REST routes');

$rest = function (string $method, string $route, array $params = []) {
    $request = new WP_REST_Request($method, $route);

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    return rest_do_request($request);
};

$answer = $rest('GET', "/wgl/v1/posts/$it9/candidates", ['lang' => 'en', 'search' => 'Clients free']);
same([200, [$free]], [$answer->get_status(), $ids($answer->get_data())], 'candidates: the free pages');
same(400, $rest('GET', "/wgl/v1/posts/$it9/candidates", ['lang' => 'xx'])->get_status(), 'a language the site hasn\'t is a 400');

$answer = $rest('POST', "/wgl/v1/posts/$it9/link", ['other' => $free]);
same(200, $answer->get_status(), 'link: done');
same($free, $answer->get_data()['languages'][1]['id'] ?? null, 'it answers the panel\'s data, the English row the linked page');
same(400, $rest('POST', "/wgl/v1/posts/$it9/link", ['other' => $en6])->get_status(), 'a second English page: a 400');

same(400, $rest('POST', "/wgl/v1/posts/$it9/unlink", ['target' => $en6])->get_status(), 'unlink a page not in the group: a 400');
$answer = $rest('POST', "/wgl/v1/posts/$it9/unlink", ['target' => $free]);
$row = $answer->get_data()['languages'][1] ?? [];
same([200, 'en', null], [$answer->get_status(), $row['code'] ?? '', $row['id'] ?? null], 'unlink: the English row is empty again');
same(400, $rest('POST', "/wgl/v1/posts/$it9/unlink")->get_status(), 'a page alone has nothing to unlink');

$admin      = get_current_user_id();
$subscriber = wp_insert_user(['user_login' => 'wgt_link_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
wp_set_current_user($subscriber);
same(403, $rest('POST', "/wgl/v1/posts/$it9/link", ['other' => $free])->get_status(), 'a user who can\'t edit the pages is refused');
same(403, $rest('GET', "/wgl/v1/posts/$it9/candidates", ['lang' => 'en'])->get_status(), 'even the search');
wp_set_current_user($admin);

section('One entry a group');

$grp_en = wgt_page('Gruppo unico EN wgt', '', 'en', ['post_name' => 'wgt-gruppo-unico-en']);
$grp_de = WpGoLanguages::create_translation($grp_en, 'de');
wp_update_post(['ID' => $grp_de, 'post_title' => 'Gruppo unico DE wgt']);
$alone_it = wgt_page('Da collegare wgt', '', 'it', ['post_name' => 'wgt-da-collegare']);
$groups   = Admin\link_candidates($alone_it, '', 'Gruppo unico');
same([$grp_en], $ids($groups), 'a group with two matching pages is offered once, by its original');
same(['en', 'de'], array_column($groups[0]['languages'] ?? [], 'code'), 'with every language it has');
same([], $ids(Admin\link_candidates($grp_de, '', 'Gruppo unico')), 'a page is never offered its own group');

section('Another original, by hand');

// no Italian page: any translation may lead the group
$en10 = wgt_page('Lead EN wgt', serialize_blocks([wgt_acf('rich-text', ['text' => '<p>Lead</p>'])]), 'en', ['post_name' => 'wgt-lead-en']);
$de10 = WpGoLanguages::create_translation($en10, 'de');
check('a translation in a group with no Italian page may become the original', Admin\can_be_original($de10));
check('not the original itself', !Admin\can_be_original($en10));
same(true, Admin\panel_data($en10)['languages'][2]['can_original'] ?? null, 'the panel offers it on the German row');

$answer = $rest('POST', "/wgl/v1/posts/$en10/original", ['target' => $de10]);
same(200, $answer->get_status(), 'original: done');
same($de10, Core\original($en10), 'the German page is the original now');
same('independent', Core\mode_of($en10), 'the English one a duplicate');
same(true, Admin\panel_data($de10)['languages'][1]['can_original'] ?? null, 'and could lead again');

// with an Italian page, it is always the original
same(false, Admin\can_be_original($en5), 'a group with an Italian page: its translations can\'t');
same(400, $rest('POST', "/wgl/v1/posts/$it5/original", ['target' => $en5])->get_status(), 'the route refuses it');
same(400, $rest('POST', "/wgl/v1/posts/$it9/original", ['target' => $de10])->get_status(), 'and a page of another group');
