<?php

/**
 * Languages and URLs: the settings read and cleaned up, the current
 * language and its switches, a post's or a term's language and group (link,
 * unlink, the pruning), the addresses in each language (permalinks, url_in,
 * url_language, url_to_post_id, home_url, the front page, the switcher), the
 * locale, the queries (WP_Query, get_posts, get_pages, get_terms, a page by
 * its path) and the slugs a language may share with another.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

$home = untrailingslashit((string) get_option('home'));

section('The settings');

same(['it', 'en'], array_keys(WpGoLanguages::languages()), 'the languages are listed by code, in order');
same('it', WpGoLanguages::default(), 'the first is the default');
check('only the first is flagged default', WpGoLanguages::languages()['it']['default'] === true && WpGoLanguages::languages()['en']['default'] === false);
check('is_language() knows the codes, not null, \'\' or another', WpGoLanguages::is_language('en') && !WpGoLanguages::is_language(null) && !WpGoLanguages::is_language('') && !WpGoLanguages::is_language('de'));
same('en', Core\language_by_slug('en'), 'a segment names its language');
same(null, Core\language_by_slug('it'), 'the default language has no segment');
same('en', Core\language_by_locale('EN_gb'), 'a locale names its language, whatever its case');

$cleaned = Core\normalize_languages([
    ['code' => 'IT', 'locale' => 'it_IT', 'name' => ' Italiano ', 'slug' => ''],
    ['code' => 'it', 'locale' => 'xx', 'name' => 'Again'],
    ['code' => '', 'locale' => 'en_GB'],
    'not a row',
    ['code' => 'en', 'locale' => 'en-GB!', 'name' => '', 'slug' => 'In English'],
]);
same([
    ['code' => 'it', 'locale' => 'it_IT', 'name' => 'Italiano', 'slug' => 'it'],
    ['code' => 'en', 'locale' => 'enGB', 'name' => 'en', 'slug' => 'in-english'],
], $cleaned, 'the languages are cleaned up: codes lowercase and once, empty ones dropped, slug, locale and name filled in');

// the option emptied: the site's own language alone, pages and forms translated
$stored = get_option(Core\OPTION);
delete_option(Core\OPTION);
$defaults = Core\config(true);
same([['code' => 'it', 'locale' => 'it_IT', 'name' => 'it', 'slug' => 'it']], $defaults['languages'], 'with no settings saved, the site has its own language alone');
same(['page', 'wpcf7_contact_form'], $defaults['post_types'], 'with no settings saved, pages and forms are translated');
update_option(Core\OPTION, $stored);
Core\config(true);
same(['it', 'en'], Core\codes(), 'the settings read again after a save');

// flags: the locale's country, else the language's usual one, else the code's
same(['it', 'gb'], [Core\flag('it'), Core\flag('en')], 'a language\'s flag is its locale\'s country: it_IT, en_GB');
same(null, Core\flag('de'), 'a code that isn\'t a language has none');
check('its URL is the plugin\'s file', str_ends_with((string) Core\flag_url('en'), '/flags/gb.svg') && is_file(dirname(WP_GO_LANGUAGES_FILE) . '/flags/gb.svg'));
$flags = fn ($config) => array_replace($config, ['languages' => [
    ['code' => 'it', 'locale' => 'it_IT', 'name' => 'Italiano'],
    ['code' => 'en', 'locale' => 'en_US', 'name' => 'English'],
    ['code' => 'ja', 'locale' => 'ja', 'name' => '日本語'],
    ['code' => 'ca', 'locale' => 'ca', 'name' => 'Català'],
    ['code' => 'qz', 'locale' => 'qz', 'name' => 'Nowhere'],
]]);
add_filter('wp_go_languages/config', $flags);
Core\config(true);
same('us', Core\flag('en'), 'en_US: the American flag');
same('jp', Core\flag('ja'), 'a locale with no country: the language\'s usual flag');
same('es-ct', Core\flag('ca'), 'Catalan: the Catalan one');
same(null, Core\flag('qz'), 'none found: null');
same(null, Core\flag_url('qz'), 'and no URL');
same('<span class="wgl-flag wgl-flag--code">QZ</span>', \WpGo\Languages\Admin\flag('qz'), 'the admin shows its code instead');
same('', \WpGo\Languages\Admin\flag('qz', false), 'or nothing, where a name is beside it');
remove_filter('wp_go_languages/config', $flags);
Core\config(true);

section('A config filter added late');

$late = fn ($config) => $config;
add_filter('wp_go_languages/config', $late);
$notices = [];
$notice  = function ($function, $message) use (&$notices) {
    $notices[] = $message;
};
add_action('doing_it_wrong_run', $notice, 10, 2);
// heard, not printed
add_filter('doing_it_wrong_trigger_error', '__return_false');
// what wp_loaded ran already: a filter added since is too late
Core\drop_late_filters();
remove_filter('doing_it_wrong_trigger_error', '__return_false');
remove_action('doing_it_wrong_run', $notice, 10);

check('a config filter added after the first read is taken off', has_filter('wp_go_languages/config', $late) === false);
check('with a "doing it wrong" notice naming the filter', (bool) array_filter($notices, fn ($message) => str_contains($message, 'wp_go_languages/config')));

section('The current language');

$switched = [];
$listener = function ($code) use (&$switched) {
    $switched[] = $code;
};
add_action('wp_go_languages/switched', $listener);

same('it', WpGoLanguages::current(), 'WP-CLI runs in the default language');
same('it', WpGoLanguages::filter(), 'and its queries keep to it');

WpGoLanguages::switch('en');
same('en', WpGoLanguages::current(), 'switch() makes a language current');
same('en', WpGoLanguages::filter(), 'the queries follow the switch');
same('en', acf_get_setting('current_language'), 'ACF\'s language follows the switch');

WpGoLanguages::switch('xx');
same('it', WpGoLanguages::current(), 'a switch to a code that isn\'t a language is to the default');
WpGoLanguages::restore();
same('en', WpGoLanguages::current(), 'restore() goes back one switch');
WpGoLanguages::restore();
same('it', WpGoLanguages::current(), 'and another back to the request\'s');
WpGoLanguages::restore();
same('it', WpGoLanguages::current(), 'a restore with no switch left changes nothing');
same(['en', 'it', 'en', 'it'], $switched, 'wp_go_languages/switched fires on each switch and restore, with the language now current');

try {
    WpGoLanguages::in('en', function () {
        throw new RuntimeException('inside');
    });
} catch (RuntimeException $error) {
}

same('it', WpGoLanguages::current(), 'in() switches back when its callback throws');
same('en', WpGoLanguages::in('en', fn () => WpGoLanguages::current()), 'in() returns what its callback did, in the language');
remove_action('wp_go_languages/switched', $listener);

section('A post\'s language and its group');

$set = [];
add_action('wp_go_languages/language_set', function ($id, $code) use (&$set) {
    $set[] = [$id, $code];
}, 10, 2);

$a_it = wgt_page('Gruppo', '', 'it');
$a_en = wgt_page('Group', '', 'en');
$post = wp_insert_post(['post_type' => 'post', 'post_title' => 'An untranslated post', 'post_status' => 'publish']);

same([$a_en, 'en'], end($set), 'wp_go_languages/language_set fires with the post and its language');
check('set_language() refuses a type that isn\'t translated, or a code that isn\'t a language', !WpGoLanguages::set_language($post, 'en') && !WpGoLanguages::set_language($a_it, 'xx'));
same(null, WpGoLanguages::language_of($post), 'a post of an untranslated type has no language');
same(null, WpGoLanguages::language_of(0), 'nor has ID 0');
same(['it' => $a_it], WpGoLanguages::translations($a_it), 'a page in no group is its own only translation');
same([], WpGoLanguages::translations($post), 'an item with no language has no translations');
same(null, WpGoLanguages::translation($a_it, 'en'), 'translation() is null with none in the language');
same($a_it, WpGoLanguages::translation($a_it, 'en', true), 'translation() with fallback gives the page itself');
same(null, WpGoLanguages::mode($a_it), 'an original has no mode');

$self = WpGoLanguages::link($a_it, $a_it);
same('wgl_link_self', is_wp_error($self) ? $self->get_error_code() : $self, 'a page can\'t be its own translation');

$no_language = wp_insert_post(['post_type' => 'page', 'post_title' => 'No language', 'post_status' => 'draft']);
wp_delete_object_term_relationships($no_language, Core\LANGUAGE_TAXONOMY);
$refused = WpGoLanguages::link($no_language, $a_it);
same('wgl_link_no_language', is_wp_error($refused) ? $refused->get_error_code() : $refused, 'linking needs both to have a language');

same(true, WpGoLanguages::link($a_en, $a_it), 'link() makes a page another\'s translation');
same(['it' => $a_it, 'en' => $a_en], WpGoLanguages::translations($a_en), 'both are in one group, in the languages\' order');
same($a_it, WpGoLanguages::original($a_en), 'the translation names its original');
same('independent', WpGoLanguages::mode($a_en), 'a linked page counts as a duplicate');

$b_en = wgt_page('Another group', '', 'en');
$taken = WpGoLanguages::link($b_en, $a_it);
same('wgl_link_taken', is_wp_error($taken) ? $taken->get_error_code() : $taken, 'a second page in a language the group has is refused');

// linking to a translation links to its original
$c_it = wgt_page('Terzo', '', 'it');
$c_en = wgt_page('Third', '', 'en');
WpGoLanguages::link($c_it, $c_en);
same($c_en, WpGoLanguages::original($c_it), 'linked the other way, the English page is the original');

$group = Core\group_of($a_en);
same(true, WpGoLanguages::link($a_en, $a_it), 'linking again into its own group is fine');
same($group, Core\group_of($a_en), 'and keeps the group as it was, not pruned and remade');

// an original moved into another group goes alone: the group it left, of two, is pruned
WpGoLanguages::link($a_it, $b_en);
same(['it' => $a_it, 'en' => $b_en], WpGoLanguages::translations($b_en), 'an original linked into another group moves there');
same(['en' => $a_en], WpGoLanguages::translations($a_en), 'its translation left behind is on its own');
check('and is an original now, its group term gone', !Core\is_translation($a_en) && get_term($group, Core\GROUP_TAXONOMY) === null);

// unlink in a group of three: the others stay
$d_it = wgt_page('Quattro', '', 'it');
$d_en = WpGoLanguages::create_translation($d_it, 'en', 'text');
$e_post = wgt_page('Quattro bis', '', 'it');
Core\unlink($d_en);
same(['it' => $d_it], WpGoLanguages::translations($d_it), 'an unlinked translation leaves the group');
same(null, WpGoLanguages::mode($d_en), 'and is an original with no mode');
check('a group left with one page is removed', Core\group_of($d_it) === null);

// a source gone without the hooks (SQL): the group's default-language page stands in
$f_it = wgt_page('Sorgente', '', 'it');
$f_en = WpGoLanguages::create_translation($f_it, 'en');
$f_de = wgt_page('Spare', '', 'it');
update_post_meta($f_en, Core\SOURCE_META, 999999999);
same($f_it, WpGoLanguages::original($f_en), 'a source that is gone: the group\'s default-language page is the original');

// a broken group: two pages in one language
$g_it  = wgt_page('Rotto', '', 'it');
$g_en  = WpGoLanguages::create_translation($g_it, 'en');
$g_it2 = wgt_page('Rotto 2', '', 'it');
wp_set_object_terms($g_it2, [Core\group_of($g_it)], Core\GROUP_TAXONOMY);
Core\forget_translations();
same($g_it2, WpGoLanguages::translations($g_it2)['it'], 'in a broken group the page asked about wins its own language');
same(1, count(array_filter(WpGoLanguages::translations($g_en), fn ($id) => in_array($id, [$g_it, $g_it2], true))), 'and one page per language is listed');

section('A new post\'s language');

// the admin's "All languages": no filter, the current language
State::$filter = null;
$all = wp_insert_post(['post_type' => 'page', 'post_title' => 'All languages', 'post_status' => 'draft']);
State::$filter = 'en';
$english = wp_insert_post(['post_type' => 'page', 'post_title' => 'The admin in English', 'post_status' => 'draft']);
State::$filter = 'it';

same('it', WpGoLanguages::language_of($all), 'a new page with All languages picked is in the default language');
same('en', WpGoLanguages::language_of($english), 'a new page gets the language the admin keeps to');

$revision = wp_save_post_revision($english);
check('a revision is given no language', !$revision || !has_term('', Core\LANGUAGE_TAXONOMY, $revision));

section('Terms');

add_filter('wp_go_languages/taxonomies', fn ($taxonomies) => array_merge($taxonomies, ['category']));

$t_it = wp_insert_term('Notizie wgt', 'category')['term_id'];
$t_en = WpGoLanguages::in('en', fn () => wp_insert_term('News wgt', 'category')['term_id']);
State::$filter = null;
$t_all = wp_insert_term('Tutte wgt', 'category')['term_id'];
State::$filter = 'it';

same('it', WpGoLanguages::language_of($t_it, 'term'), 'a new term gets the current language');
same('en', WpGoLanguages::language_of($t_en, 'term'), 'a term made in a switch gets that language');
same('it', WpGoLanguages::language_of($t_all, 'term'), 'a term made with All languages gets the default');

$names = fn (array $args) => wp_list_pluck(get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'name__like' => 'wgt'] + $args), 'name');
same(['Notizie wgt', 'Tutte wgt'], $names([]), 'get_terms() lists the current language\'s terms');
same(['News wgt'], $names(['lang' => 'en']), 'get_terms() with lang lists that language\'s');
same(3, count($names(['lang' => 'all'])), 'get_terms() with lang all lists every language\'s');
check('a term is found by its slug only in its own language', get_term_by('slug', 'news-wgt', 'category') === false && WpGoLanguages::in('en', fn () => get_term_by('slug', 'news-wgt', 'category')) instanceof WP_Term);

// an object's own terms, in any language: an English post's link built on an
// Italian page reads them (necchio's /{area}/{service}/)
$termed = WpGoLanguages::in('en', fn () => wp_insert_post(['post_title' => 'Termed wgt', 'post_status' => 'publish']));
WpGoLanguages::in('en', fn () => wp_set_object_terms($termed, [$t_en], 'category'));
same([$t_en], wp_get_object_terms($termed, 'category', ['fields' => 'ids']), 'an English post\'s terms in Italian: its own, English');
same([$t_en], wp_list_pluck((array) get_the_terms($termed, 'category'), 'term_id'), 'get_the_terms() too');

WpGoLanguages::link($t_en, $t_it, 'term');
same(['it' => $t_it, 'en' => $t_en], WpGoLanguages::translations($t_en, 'term'), 'terms link into a group as posts do');

section('Addresses');

same("$home/en/", WpGoLanguages::home_url('en'), 'a language\'s home is under its segment');
same("$home/en/contact/", WpGoLanguages::home_url('en', '/contact/'), 'with a path after it');
same("$home/", WpGoLanguages::home_url('it'), 'the default language\'s home has no segment');

same('it', WpGoLanguages::url_language("$home/chi-siamo/"), 'an address with no segment is in the default language');
same('en', WpGoLanguages::url_language("$home/en/about/"), 'an address under /en/ is in English');
same('en', WpGoLanguages::url_language(str_replace('http:', 'https:', $home) . '/en/'), 'whatever its scheme');
same('it', WpGoLanguages::url_language("$home/enterprise/"), '/enterprise is not /en/');
same(null, WpGoLanguages::url_language('https://example.org/en/'), 'an address off the site has no language');

same("$home/en/contatti/", WpGoLanguages::url_in("$home/contatti/", 'en'), 'url_in() adds the segment');
same("$home/contatti/?x=1#y", WpGoLanguages::url_in("$home/en/contatti/?x=1#y", 'it'), 'url_in() takes it off, the query and fragment kept');
same("$home?x=1", WpGoLanguages::url_in("$home/en?x=1", 'it'), 'url_in() on a bare /en with a query');
same('https://example.org/x/', WpGoLanguages::url_in('https://example.org/x/', 'en'), 'url_in() leaves an address off the site as it is');
same(str_replace('http:', 'https:', $home) . '/en/x/', WpGoLanguages::url_in(str_replace('http:', 'https:', $home) . '/x/', 'en'), 'url_in() keeps the scheme');

// the same slug in two languages, and a child page
$same_it = wgt_page('Pagina condivisa', '', 'it', ['post_name' => 'wgt-shared']);
$same_en = WpGoLanguages::create_translation($same_it, 'en');
wp_update_post(['ID' => $same_en, 'post_status' => 'publish']);
$child_it = wgt_page('Figlia', '', 'it', ['post_name' => 'wgt-child', 'post_parent' => $same_it]);
$child_en = WpGoLanguages::create_translation($child_it, 'en');
wp_update_post(['ID' => $child_en, 'post_status' => 'publish']);

same('wgt-shared', get_post_field('post_name', $same_en), 'the translation keeps the original\'s slug');
same($same_en, (int) get_post_field('post_parent', $child_en), 'a child\'s translation has the parent\'s translation as its parent');
same("$home/en/wgt-shared/", get_permalink($same_en), 'an English page\'s permalink is under /en/, from the default language');
same("$home/en/wgt-shared/", WpGoLanguages::in('en', fn () => get_permalink($same_en)), 'and the same while in English');
same("$home/wgt-shared/", wgt_as_site('en', fn () => get_permalink($same_it)), 'an Italian page linked from an English one is at its own address');
same("$home/en/wgt-shared/wgt-child/", WpGoLanguages::permalink($child_it, 'en'), 'permalink() in a language is the translation\'s');
same(null, WpGoLanguages::permalink($a_en, 'it'), 'permalink() is null with no translation in the language');

same("$home/x/", home_url('/x/'), 'home_url() off the site is the site\'s own');
same("$home/x/", WpGoLanguages::in('en', fn () => home_url('/x/')), 'and stays so in a switch');
same("$home/en/x/", wgt_as_site('en', fn () => home_url('/x/')), 'on the site, home_url() carries the current language');
same("$home/x/", wgt_as_site('it', fn () => home_url('/x/')), 'with no segment for the default language');

same($same_it, WpGoLanguages::url_to_post_id("$home/wgt-shared/"), 'url_to_post_id() finds the Italian page at its address');
same($same_en, WpGoLanguages::url_to_post_id("$home/en/wgt-shared/"), 'and the English page with the same slug at its own');
same($child_en, WpGoLanguages::url_to_post_id("$home/en/wgt-shared/wgt-child/"), 'a child page by its path, in the language');
same($same_en, url_to_postid("$home/en/wgt-shared/"), 'url_to_postid() answers the same');
same(0, WpGoLanguages::url_to_post_id("$home/en/wgt-child/"), 'a child\'s slug alone, without its parent, is no page');
same(0, WpGoLanguages::url_to_post_id('https://example.org/wgt-shared/'), 'an address off the site is no post');

$only_it = wgt_page('Solo italiano', '', 'it', ['post_name' => 'wgt-solo']);
same(0, WpGoLanguages::url_to_post_id("$home/en/wgt-solo/"), 'an Italian page at an English address is no post');
same($post, url_to_postid(get_permalink($post)), 'a post of an untranslated type is found by its address');

section('The front page and the posts page');

$front_it = wgt_page('Casa', '', 'it');
$front_en = WpGoLanguages::create_translation($front_it, 'en');
wp_update_post(['ID' => $front_en, 'post_status' => 'publish']);
$blog_it = wgt_page('Blog', '', 'it');
$blog_en = WpGoLanguages::create_translation($blog_it, 'en');
update_option('show_on_front', 'page');
update_option('page_on_front', $front_it);
update_option('page_for_posts', $blog_it);

same("$home/en/", get_permalink($front_en), 'the front page\'s translation is its language\'s home');
same($front_en, WpGoLanguages::url_to_post_id("$home/en/"), 'a language\'s home is the front page\'s translation');
same($front_en, wgt_as_site('en', fn () => (int) get_option('page_on_front')), 'on the site in English, the front page is the English one');
same($blog_en, wgt_as_site('en', fn () => (int) get_option('page_for_posts')), 'and so is the posts page');
same($front_it, (int) get_option('page_on_front'), 'off the site, the option is as stored');
same($front_it, wgt_as_site('en', fn () => Core\raw_front_page()), 'raw_front_page() is the stored one, whatever the language');

section('The switcher');

$draft_it = wgt_page('Bozza', '', 'it');
$draft_en = WpGoLanguages::create_translation($draft_it, 'en');

$switcher = WpGoLanguages::switcher($same_it);
same(['it', 'en'], array_keys($switcher), 'the switcher has an entry per language, in order');
same("$home/en/wgt-shared/", $switcher['en']['url'], 'it links to the published translation');
check('the current language is active, the other not', $switcher['it']['active'] === 1 && $switcher['en']['active'] === 0);
check('with WPML\'s keys', $switcher['en']['native_name'] === 'English' && $switcher['en']['default_locale'] === 'en_GB' && $switcher['en']['id'] === 2 && $switcher['en']['language_code'] === 'en');
same(Core\flag_url('en'), $switcher['en']['country_flag_url'], 'its flag too, as WPML\'s country_flag_url');

$switcher = WpGoLanguages::switcher($draft_it);
same("$home/en/", $switcher['en']['url'], 'a draft translation: the switcher links to the language\'s home');
same(1, $switcher['en']['missing'], 'and says it is missing');
same("$home/en/", WpGoLanguages::switcher($front_it)['en']['url'], 'the front page\'s translation is linked as the language\'s home');

section('The locale');

same('en_GB', wgt_as_site('en', fn () => apply_filters('locale', 'it_IT')), 'on the site, the locale is the current language\'s');
same('it_IT', WpGoLanguages::in('en', fn () => apply_filters('locale', 'it_IT')), 'off the site, a switch leaves the locale alone');

section('Queries');

$q_it = wgt_page('Query wgt', '', 'it');
$q_en = wgt_page('Query wgt', '', 'en');
$ids  = fn (array $args) => array_map('intval', wp_list_pluck((new WP_Query($args + ['post_type' => 'page', 'title' => 'Query wgt', 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'ASC']))->posts, 'ID'));

same([$q_it], $ids([]), 'a WP_Query lists the current language\'s pages');
same([$q_en], $ids(['lang' => 'en']), 'with lang, that language\'s');
same([$q_it, $q_en], $ids(['lang' => 'all']), 'with lang all, every language\'s');
same([$q_it, $q_en], $ids(['suppress_filters' => true]), 'with its filters suppressed, every language\'s');
same([$q_en], WpGoLanguages::in('en', fn () => $ids([])), 'in a switch, the language switched to');

$posts = fn (array $args) => array_map('intval', wp_list_pluck(get_posts($args + ['post_type' => 'page', 'title' => 'Query wgt', 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'ASC']), 'ID'));
same([$q_it, $q_en], $posts([]), 'get_posts() lists every language, as with WPML');
same([$q_it], $posts(['suppress_filters' => false]), 'get_posts() with its filters keeps to the current language');
same([$q_en], $posts(['suppress_filters' => false, 'lang' => 'en']), 'and with lang to that one');

$mixed = array_map('intval', wp_list_pluck((new WP_Query(['post_type' => ['page', 'post'], 'post__in' => [$q_en, $post], 'lang' => 'it', 'orderby' => 'ID']))->posts, 'ID'));
same([$post], $mixed, 'a query over several types keeps the untranslated ones, whatever the language');
same([$q_it], array_map('intval', wp_list_pluck((new WP_Query(['post_type' => 'any', 'post__in' => [$q_it, $q_en], 'orderby' => 'ID', 'order' => 'ASC']))->posts, 'ID')), 'a query for any type keeps to the language too');

$no_lang = wp_insert_post(['post_type' => 'page', 'post_title' => 'Query wgt', 'post_status' => 'publish']);
wp_delete_object_term_relationships($no_lang, Core\LANGUAGE_TAXONOMY);
clean_object_term_cache($no_lang, 'page');
check('a page with no language is listed in no language', !in_array($no_lang, $ids([]), true) && !in_array($no_lang, $ids(['lang' => 'en']), true));

$pages = fn (array $args) => array_map('intval', wp_list_pluck(get_pages($args + ['include' => [$q_it, $q_en]]), 'ID'));
same([$q_it], $pages([]), 'get_pages() keeps to the current language');
same([$q_en], $pages(['lang' => 'en']), 'get_pages() with lang, to that one');
$every = $pages(['lang' => 'all']);
sort($every);
$both = [$q_it, $q_en];
sort($both);
same($both, $every, 'get_pages() with lang all lists every language');
same([$q_en], WpGoLanguages::in('en', fn () => $pages([])), 'get_pages() in a switch, the language switched to');

// the list's counts, "All (8) | Mine (3) | Published (8)": in the language
$drafts   = fn () => (int) (wp_count_posts('page', 'readable')->draft ?? 0);
$mine     = fn () => Core\count_user_posts_in_language('page', get_current_user_id());
$it_count = [$drafts(), $mine()];
$en_count = WpGoLanguages::in('en', fn () => [$drafts(), $mine()]);
wgt_page('Count wgt', '', 'en', ['post_status' => 'draft', 'post_author' => get_current_user_id()]);
same($it_count, [$drafts(), $mine()], 'an English draft is not counted in Italian, nor among the user\'s posts');
same([$en_count[0] + 1, $en_count[1] + 1], WpGoLanguages::in('en', fn () => [$drafts(), $mine()]), 'it is counted in English, and as the user\'s');

$unfiltered = (object) ['publish' => '8', 'draft' => '2'];
$saved      = State::$filter;
State::$filter = null;
check('with every language listed, WordPress\'s counts pass as they are', Core\count_posts($unfiltered, 'page', 'readable') === $unfiltered);
check('and "Mine" is left to WordPress', $mine() === null);
State::$filter = $saved;
check('an untranslated type\'s counts pass as they are', Core\count_posts($unfiltered, 'post', 'readable') === $unfiltered);
$recounted = get_object_vars(Core\count_posts((object) ['wgt_none' => '3'], 'page', 'readable'));
same(0, $recounted['wgt_none'] ?? null, 'every status WordPress counted is kept, at 0 if none in the language');

// "Mine" on the list: its number put right, or dropped where WordPress would
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen('edit-page');
$mine_link = fn (string $count) => '<a href="edit.php?post_type=page&author=1">Mine <span class="count">(' . $count . ')</span></a>';
$views     = Admin\list_views(['all' => 'All', 'mine' => $mine_link('99')]);
$expected  = $mine();
$all       = array_sum((array) wp_count_posts('page', 'readable')) - array_sum(array_map(fn ($state) => (int) (wp_count_posts('page', 'readable')->$state ?? 0), get_post_stati(['show_in_admin_all_list' => false])));

if ($expected === 0 || $expected === $all) {
    check('"Mine" is dropped: no post of the user\'s, or all of them', !isset($views['mine']));
} else {
    same($mine_link(number_format_i18n($expected)), $views['mine'] ?? null, '"Mine" counts the user\'s posts in the language');
}

same(['all' => 'All'], Admin\list_views(['all' => 'All']), 'no "Mine" (WordPress showed none): none added');
$GLOBALS['current_screen'] = null;

// a page by its path, in the query's language
$by_path = fn (array $args) => array_map('intval', wp_list_pluck((new WP_Query($args + ['pagename' => 'wgt-shared']))->posts, 'ID'));
same([$same_it], $by_path([]), 'a pagename query finds the current language\'s page');
same([$same_en], $by_path(['lang' => 'en']), 'and the English one with the same slug in English');
same([$child_en], array_map('intval', wp_list_pluck((new WP_Query(['pagename' => 'wgt-shared/wgt-child', 'lang' => 'en']))->posts, 'ID')), 'a child by its full path');

section('Slugs');

$s_it = wgt_page('Slug wgt', '', 'it', ['post_name' => 'wgt-slug']);
$s_en = wgt_page('Slug wgt', '', 'en', ['post_name' => 'wgt-slug']);
$s_it2 = wgt_page('Slug wgt again', '', 'it', ['post_name' => 'wgt-slug']);

same('wgt-slug', get_post_field('post_name', $s_en), 'a slug only another language has is kept');
same('wgt-slug-2', get_post_field('post_name', $s_it2), 'a slug the same language has gets a number');

$segment = wgt_page('English', '', 'it', ['post_name' => 'en']);
same('en-2', get_post_field('post_name', $segment), 'a top-level page may not take a language\'s segment');
$child_segment = wgt_page('English child', '', 'it', ['post_name' => 'en', 'post_parent' => $s_it]);
same('en', get_post_field('post_name', $child_segment), 'a child page may');

// WordPress's own reasons for a suffix stay, though no page of the language has the slug
same('feed-2', get_post_field('post_name', wgt_page('Feed', '', 'en', ['post_name' => 'feed'])), 'a feed\'s name keeps WordPress\'s suffix');
same('embed-2', get_post_field('post_name', wgt_page('Embed', '', 'en', ['post_name' => 'embed'])), 'embed keeps it');
same('2024-2', get_post_field('post_name', wgt_page('Anno', '', 'en', ['post_name' => '2024'])), 'a number keeps it');
same('page2-2', get_post_field('post_name', wgt_page('Pagina', '', 'en', ['post_name' => 'page2'])), 'a pagination slug keeps it');

$attachment = wp_insert_attachment(['post_title' => 'wgt-attached', 'post_name' => 'wgt-attached', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], false);
same('wgt-attached', get_post_field('post_name', $attachment), 'the attachment has the slug');
same('wgt-attached-2', get_post_field('post_name', wgt_page('Allegato', '', 'en', ['post_name' => 'wgt-attached'])), 'a slug an attachment has is taken in every language');
