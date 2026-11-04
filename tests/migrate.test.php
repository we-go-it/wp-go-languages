<?php

/**
 * Moving a site between WPML and this plugin (src/Migrate/): WPML's tables
 * filled by hand (rolled back with the rest), read into a plan, carried out,
 * carried out again with nothing left to do; then this plugin's data read
 * into the plan a move to WPML would carry out. Writing into WPML needs WPML
 * running, which this plugin doesn't run beside: that half is checked on a
 * copy of the database (the docs' Migrating page says how).
 *
 * WPML's tables must be in the database: a test can't create them (a CREATE
 * TABLE would end the transaction).
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;
use WpGo\Languages\Migrate;

global $wpdb;

if (!Migrate\wpml_tables_exist()) {
    check('WPML\'s tables are in the database (skipped: they aren\'t)', true);

    return;
}

$icl = fn (string $name) => Migrate\wpml_table($name);

// WPML's state, nothing of the site's leftovers
foreach (['translations', 'strings', 'string_translations'] as $table) {
    $wpdb->query('DELETE FROM `' . $icl($table) . '`');
}

$wpdb->query('UPDATE `' . $icl('languages') . "` SET active = IF(code IN ('it', 'en'), 1, 0)");
$wpdb->query('UPDATE `' . $icl('languages') . "` SET display_code = 'english' WHERE code = 'en'");
$wpdb->query('DELETE FROM `' . $icl('locale_map') . "` WHERE code IN ('it', 'en')");
$wpdb->insert($icl('locale_map'), ['code' => 'it', 'locale' => 'it_IT']);
$wpdb->insert($icl('locale_map'), ['code' => 'en', 'locale' => 'en_GB']);

register_taxonomy('wgt_mig_topic', 'page', ['label' => 'Topics', 'show_ui' => true, 'public' => true]);

update_option('icl_sitepress_settings', [
    'default_language'          => 'it',
    'languages_order'           => ['it', 'en'],
    'language_negotiation_type' => 1,
    'urls'                      => ['directory_for_default_language' => 1],
    'custom_posts_sync_option'  => ['page' => 1],
    'taxonomies_sync_option'    => ['wgt_mig_topic' => 1],
]);

$row = function (string $type, int $element, int $trid, string $code, ?string $source = null) use ($wpdb, $icl) {
    $wpdb->insert($icl('translations'), ['element_type' => $type, 'element_id' => $element, 'trid' => $trid, 'language_code' => $code, 'source_language_code' => $source]);
};

section('From WPML: the plan');

// a pair, both Italian here and unlinked: WPML says the second is English
$a_it = wgt_page('Migrazione A wgt', '', 'it', ['post_name' => 'wgt-mig-a']);
$a_en = wgt_page('Migration A wgt', '', 'it', ['post_name' => 'wgt-mig-a-en']);
$row('post_page', $a_it, 900001, 'it');
$row('post_page', $a_en, 900001, 'en', 'it');

// translated from English: the English page is the original
$b_it = wgt_page('Migrazione B wgt', '', 'it', ['post_name' => 'wgt-mig-b']);
$b_en = wgt_page('Migration B wgt', '', 'en', ['post_name' => 'wgt-mig-b-en']);
$row('post_page', $b_en, 900002, 'en');
$row('post_page', $b_it, 900002, 'it', 'en');

// linked here, each alone in WPML
$c_it = wgt_page('Migrazione C wgt', '', 'it', ['post_name' => 'wgt-mig-c']);
$c_en = WpGoLanguages::create_translation($c_it, 'en', 'text');
$row('post_page', $c_it, 900003, 'it');
$row('post_page', $c_en, 900004, 'en');

// French, not an active language
$e_fr = wgt_page('Migration E wgt', '', 'it', ['post_name' => 'wgt-mig-e']);
$row('post_page', $e_fr, 900006, 'fr');

// no row in WPML, and no language here
$f = wgt_page('Migrazione F wgt', '', 'it', ['post_name' => 'wgt-mig-f']);
wp_delete_object_term_relationships($f, Core\LANGUAGE_TAXONOMY);

// a row of the wrong type: not this page's
$row('post_post', $f, 900007, 'en');

// terms, by term_taxonomy_id
$t_it = wp_insert_term('Argomento wgt', 'wgt_mig_topic');
$t_en = wp_insert_term('Topic wgt', 'wgt_mig_topic');
$row('tax_wgt_mig_topic', (int) $t_it['term_taxonomy_id'], 900101, 'it');
$row('tax_wgt_mig_topic', (int) $t_en['term_taxonomy_id'], 900101, 'en', 'it');

// menus: the location the default's, the English one its translation
$m_it = wp_create_nav_menu('Principale wgt');
$m_en = wp_create_nav_menu('Main wgt');
$row('tax_nav_menu', (int) get_term($m_it)->term_taxonomy_id, 900201, 'it');
$row('tax_nav_menu', (int) get_term($m_en)->term_taxonomy_id, 900201, 'en', 'it');
set_theme_mod('nav_menu_locations', ['wgt_main' => $m_it]);

// admin strings: one the code has, one it doesn't, one not complete
$known = null;

foreach (wp_go_admin_strings_scan(true)['strings'] as $string) {
    if ($string['domain'] === 'wp-go-languages') {
        $known = $string;
        break;
    }
}

$string_row = function (string $domain, string $context, string $text, string $code, string $value, int $status) use ($wpdb, $icl) {
    $wpdb->insert($icl('strings'), ['language' => 'en', 'context' => $domain, 'name' => md5($text), 'value' => $text, 'gettext_context' => $context, 'status' => 0, 'domain_name_context_md5' => md5($domain . $text . $context . wp_rand())]);
    $wpdb->insert($icl('string_translations'), ['string_id' => $wpdb->insert_id, 'language' => $code, 'status' => $status, 'value' => $value]);
};

$string_row('wp-go-languages', $known['context'], $known['text'], 'it', 'Tradotto wgt', 10);
$string_row('wp-go-languages', 'nowhere.php', 'Not in the code wgt', 'it', 'Non nel codice wgt', 10);
$string_row('wp-go-languages', 'nowhere.php', 'Unfinished wgt', 'it', 'Da finire wgt', 3);
$string_row('admin_texts_blogname', '', 'base', 'en', 'base EN', 10);

$plan = Migrate\plan_from_wpml();

same(['it', 'en'], array_column($plan['languages'], 'code'), 'the active languages, the default first');
same('en_GB', $plan['languages'][1]['locale'], 'the locale is the locale map\'s');
same('english', $plan['languages'][1]['slug'], 'the URL segment is WPML\'s display_code');
same(true, $plan['default_in_url'], 'the default language\'s directory');
check('pages are translated', in_array('page', $plan['post_types'], true));
check('the topics are translated', in_array('wgt_mig_topic', $plan['taxonomies'], true));

$group_of = function (array $groups, int $id): ?array {
    foreach ($groups as $group) {
        if (in_array($id, $group['items'], true)) {
            return $group;
        }
    }

    return null;
};

same(['it' => $a_it, 'en' => $a_en], $group_of($plan['posts'], $a_it)['items'] ?? null, 'a WPML group is a group');
same($a_it, $group_of($plan['posts'], $a_it)['original'] ?? null, 'its original the row with no source');
same($b_en, $group_of($plan['posts'], $b_it)['original'] ?? null, 'an English original stays the original');
same(['it' => $c_it], $group_of($plan['posts'], $c_it)['items'] ?? null, 'a page alone in WPML is alone');
same(null, $group_of($plan['posts'], $e_fr), 'a page in an inactive language isn\'t planned');
same([$f], array_values(array_intersect($plan['unassigned']['post'], [$f])), 'a page with no WPML row is unassigned (the row of another type isn\'t its)');
same(['it' => (int) $t_it['term_id'], 'en' => (int) $t_en['term_id']], $group_of($plan['terms'], (int) $t_it['term_id'])['items'] ?? null, 'a term group, by term_id');
same(['wgt_main' => $m_it, 'wgt_main___en' => $m_en], $plan['menus'], 'the menu\'s translation goes to the English location');
same(['it_IT' => ['wp-go-languages' => [
    $known['context'] . "\x04" . $known['text'] => 'Tradotto wgt',
    "nowhere.php\x04Not in the code wgt"        => 'Non nel codice wgt',
]]], $plan['strings'], 'the complete translations of a listed domain, by locale');

$warnings = implode("\n", $plan['warnings']);
check('a warning for the French page', str_contains($warnings, '1 posts in fr'));
check('a warning for the other domains\' strings', str_contains($warnings, 'admin_texts_blogname (1)'));

section('From WPML: carried out');

$log   = [];
$apply = Migrate\apply_from_wpml($plan, function ($line) use (&$log) {
    $log[] = $line;
});

same([], $apply, 'no warnings while writing');
same(['it', 'en'], Core\codes(), 'the settings are WPML\'s');
same(true, Core\default_in_url(), 'with the default\'s directory');
same('it', get_option(Core\STORED_DEFAULT_OPTION), 'WPML\'s default has the suffixless options, as it has them in WPML');

same(['it' => $a_it, 'en' => $a_en], Core\translations($a_it), 'the pair is linked');
same('en', Core\language_of($a_en), 'the English page is English now');
same('independent', Core\mode_of($a_en), 'a duplicate');
same($b_en, Core\original($b_it), 'the English original');
same(['it' => $c_it], Core\translations($c_it), 'the pages alone in WPML are unlinked');
same(null, Core\mode_of($c_en), 'the old fixed translation has no mode left');
same('it', Core\language_of($e_fr), 'the French page keeps its language here');
same('it', Core\language_of($f), 'the unassigned page has the default language');
same(['it' => (int) $t_it['term_id'], 'en' => (int) $t_en['term_id']], Core\translations((int) $t_en['term_id'], 'term'), 'the terms are linked');
same($m_en, Core\translation($m_it, 'en', false, 'term'), 'the menus are linked, as WPML had them');
same(['wgt_main' => $m_it], get_theme_mods()['nav_menu_locations'], 'the location holds the default\'s, no other');
same('Tradotto wgt', wp_go_admin_strings_translations()['it_IT']['wp-go-languages'][$known['context'] . "\x04" . $known['text']] ?? null, 'the admin string\'s translation is imported');
same(null, wp_go_admin_strings_translations()['it_IT']['wp-go-languages']["nowhere.php\x04Not in the code wgt"] ?? null, 'a string the code doesn\'t have isn\'t');

section('From WPML: again');

$log = [];
Migrate\apply_from_wpml(Migrate\plan_from_wpml(), function ($line) use (&$log) {
    $log[] = $line;
});

check('no group changes the second time', in_array('Posts: 0 groups changed, 0 given the default language.', $log, true), implode(' | ', $log));
check('nor term', in_array('Terms: 0 groups changed, 0 given the default language.', $log, true));
same(['it' => $a_it, 'en' => $a_en], Core\translations($a_it), 'the pair is as it was');

section('To WPML: the plan');

// one more page, with no language here
$g = wgt_page('Migrazione G wgt', '', 'it', ['post_name' => 'wgt-mig-g']);
wp_delete_object_term_relationships($g, Core\LANGUAGE_TAXONOMY);

// a fixed translation, made here
$h_it = wgt_page('Migrazione H wgt', '', 'it', ['post_name' => 'wgt-mig-h']);
$h_en = WpGoLanguages::create_translation($h_it, 'en', 'text');

// a broken group: a second English page in the pair's group
$a_en2 = wgt_page('Migration A2 wgt', '', 'en', ['post_name' => 'wgt-mig-a2-en']);
wp_set_object_terms($a_en2, [(int) Core\group_of($a_it)], Core\GROUP_TAXONOMY);

$back = Migrate\plan_to_wpml();

same(['it' => $a_it, 'en' => $a_en], $group_of($back['posts'], $a_it)['items'] ?? null, 'the group read back');
same($a_it, $group_of($back['posts'], $a_it)['original'] ?? null, 'its original');
same(['en' => $a_en2], $group_of($back['posts'], $a_en2)['items'] ?? null, 'a second English page in the group is kept apart');
check('with a warning', str_contains(implode("\n", $back['warnings']), "Post $a_en2: a second en item"));
same($b_en, $group_of($back['posts'], $b_it)['original'] ?? null, 'the English original read back');
same(['it' => $c_it], $group_of($back['posts'], $c_it)['items'] ?? null, 'a page alone read back');
same(['it' => $h_it, 'en' => $h_en], $group_of($back['posts'], $h_en)['items'] ?? null, 'a fixed translation is a translation');
check('a page with no language is unassigned', in_array($g, $back['unassigned']['post'], true));
same(['it' => (int) $t_it['term_id'], 'en' => (int) $t_en['term_id']], $group_of($back['terms'], (int) $t_it['term_id'])['items'] ?? null, 'the term group read back');

$menus = $group_of($back['menus']['groups'], $m_en);
same(['it' => $m_it, 'en' => $m_en], $menus['items'] ?? null, 'the location\'s menus are one group');
same($m_it, $menus['original'] ?? null, 'the default language\'s menu the original');
same($m_it, $back['menus']['locations']['wgt_main'] ?? null, 'the location given the default\'s menu');
check('the languages\' locations aren\'t written to WPML', !array_filter(array_keys($back['menus']['locations']), fn ($location) => str_contains((string) $location, '___')));

same('Tradotto wgt', $back['strings']['it']['wp-go-languages'][$known['context'] . "\x04" . $known['text']] ?? null, 'the admin strings by language code');
same([], $back['missing'], 'WPML knows both languages');
same(['xx'], Migrate\missing_in_wpml(['it', 'xx']), 'a code WPML doesn\'t know is missing');
