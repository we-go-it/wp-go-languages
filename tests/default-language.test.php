<?php

// @require fixtures/third-language.php

/**
 * The default language: in the URL too (`default_in_url`, its URLs under its
 * slug, a URL without one redirected there), and made another (the
 * Languages screen's radio: its items become their groups' originals, fixed
 * translations following them, while what is stored bare, ACF's options and
 * the menus, stays the first default's). A third language (German) gives
 * groups of three.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

$home = untrailingslashit((string) get_option('home'));

/** Run with the config changed by `$change`, read again before and after. */
function wgt_with_config(callable $change, callable $callback): mixed
{
    add_filter('wp_go_languages/config', $change, 20);
    Core\config(true);

    try {
        return $callback();
    } finally {
        remove_filter('wp_go_languages/config', $change, 20);
        Core\config(true);
    }
}

$in_url = fn ($config) => array_replace($config, ['default_in_url' => true]);

section('The default language at the root');

same(false, Core\default_in_url(), 'by default, the default language has no segment');
same($home, Core\language_base('it'), 'its base is the home');
same(null, Core\language_by_slug('it'), 'and its slug names nothing');

section('The default language in the URL');

$page    = wgt_page('Chi siamo wgt', '', 'it', ['post_name' => 'wgt-chi-siamo']);
$page_en = wgt_page('About wgt', '', 'en', ['post_name' => 'wgt-about']);
WpGoLanguages::link($page_en, $page);

wgt_with_config($in_url, function () use ($home, $page, $page_en) {
    same(true, Core\default_in_url(), 'the setting is read');
    same("$home/it", Core\language_base('it'), 'the default language\'s base has its slug');
    same("$home/it/", Core\home_url_for('it'), 'and its home');
    same('it', Core\language_by_slug('it'), 'its slug names it');
    same('it', Core\path_language('/it/wgt-chi-siamo/'), 'a path under it is in it');
    same(null, Core\path_language('/wgt-chi-siamo/'), 'a path with no segment names none');
    same('it', Core\url_language("$home/wgt-chi-siamo/"), 'but is the default language\'s');

    same("$home/it/wgt-chi-siamo/", Core\url_in("$home/wgt-chi-siamo/", 'it'), 'a URL with no segment gets the default\'s');
    same("$home/en/wgt-chi-siamo/", Core\url_in("$home/wgt-chi-siamo/", 'en'), 'or another language\'s');
    same("$home/en/x/", Core\url_in("$home/it/x/", 'en'), 'the default\'s segment is swapped for another\'s');
    same("$home/it/x/?a=1", Core\url_in("$home/en/x/?a=1", 'it'), 'and back, the query kept');
    same("$home/it/x/", Core\url_in("$home/it/x/", 'it'), 'a URL already in the language is left as it is');
    same("$home/x/", Core\url_unprefixed("$home/it/x/"), 'url_unprefixed() takes the segment off');

    same("$home/it/wgt-chi-siamo/", get_permalink($page), 'the default language\'s page links under its slug');
    same("$home/en/wgt-about/", get_permalink($page_en), 'the other\'s as before');
    same($page, Core\url_to_post_id("$home/it/wgt-chi-siamo/"), 'its URL is found');
    same($page, Core\url_to_post_id("$home/wgt-chi-siamo/"), 'the old URL with no segment too');
    same($page_en, Core\url_to_post_id("$home/en/wgt-about/"), 'and the other language\'s');
    same("$home/it/", wgt_as_site('it', fn () => home_url('/')), 'on the site, home_url() is the default\'s base');

    $switcher = WpGoLanguages::switcher($page);
    same("$home/it/wgt-chi-siamo/", $switcher['it']['url'], 'the switcher links it under its slug');

    $rest = Core\is_rest_path('/it/wp-json/wp/v2/pages');
    check('/it/wp-json/ is a REST call', $rest);
});

same("$home/wgt-chi-siamo/", get_permalink($page), 'the setting off, the default language is at the root again');

section('Old URLs redirected');

$redirect = function (string $uri, string $method = 'GET') {
    $saved = [$_SERVER['REQUEST_URI'] ?? null, $_SERVER['REQUEST_METHOD'] ?? null, State::$context];

    $_SERVER['REQUEST_URI']    = $uri;
    $_SERVER['REQUEST_METHOD'] = $method;
    State::$context            = 'site';

    try {
        return wgt_stopped('WpGo\\Languages\\Core\\redirect_unprefixed');
    } finally {
        [$_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], State::$context] = $saved;
    }
};

same('none', $redirect('/wgt-chi-siamo/')['kind'], 'the default language at the root: nothing is redirected');

wgt_with_config($in_url, function () use ($redirect, $home) {
    $stop = $redirect('/wgt-chi-siamo/?a=1');
    same(['redirect', "$home/it/wgt-chi-siamo/?a=1", 301], [$stop['kind'], $stop['target'], $stop['status']], 'a URL with no segment moves under the default\'s, query kept, for good (301)');
    same("$home/it/", $redirect('/')['target'], 'the root moves to the default language\'s home');
    same('none', $redirect('/it/wgt-chi-siamo/')['kind'], 'a URL with the default\'s segment stays');
    same('none', $redirect('/en/wgt-about/')['kind'], 'and with another\'s');
    same('none', $redirect('/wgt-chi-siamo/', 'POST')['kind'], 'a POST is left alone');

    set_query_var('sitemap', 'index');
    same('none', $redirect('/wp-sitemap.xml')['kind'], 'WordPress\'s sitemap stays at the root');
    set_query_var('sitemap', '');
});

section('Another default language');

// a group of three: Italian original, English fixed, German duplicate
$original = wgt_page('Gruppo wgt', serialize_blocks([wgt_acf('rich-text', ['text' => '<p>Ciao</p>'])]), 'it', ['post_name' => 'wgt-gruppo']);
$fixed_en = WpGoLanguages::create_translation($original, 'en', 'text');
$dup_de   = WpGoLanguages::create_translation($original, 'de');
$only_it  = wgt_page('Solo wgt', '', 'it', ['post_name' => 'wgt-solo']);
$only_de  = wgt_page('Nur wgt', '', 'de', ['post_name' => 'wgt-nur']);
WpGoLanguages::link($only_de, $only_it);

$heard = [];
$listen = function ($id, $old, $kind) use (&$heard) {
    $heard[] = [$id, $old, $kind];
};
add_action('wp_go_languages/original_changed', $listen, 10, 3);

same(false, Core\make_original($original), 'an original made original: nothing to do');

$changed = Core\make_originals('en');

check('make_originals() counts the groups it changed', $changed >= 1);
same($fixed_en, Core\original($original), 'the English page is the group\'s original now');
same($fixed_en, Core\original($dup_de), 'the German one names it');
same(null, Core\mode_of($fixed_en), 'the new original has no mode');
same('independent', Core\mode_of($original), 'the old original is a duplicate, its content kept');
same('independent', Core\mode_of($dup_de), 'a duplicate stays one');
same(['it' => $original, 'en' => $fixed_en, 'de' => $dup_de], Core\translations($original), 'the group is the same three');
same($only_it, Core\original($only_de), 'a group with nothing in English keeps its original');
check('original_changed is heard for the group, not for the others', in_array([$fixed_en, $original, 'post'], $heard, true) && !in_array($only_it, array_column($heard, 1), true));

// a fixed translation follows the new original
$only_en = WpGoLanguages::create_translation($only_it, 'en', 'text');
Core\make_originals('de');
same($only_de, Core\original($only_en), 'a fixed translation names the new original');
same('text', Core\mode_of($only_en), 'and stays fixed: it follows it now');

// terms
$area_it = wp_insert_term('Zona wgt', 'category')['term_id'];
$area_en = WpGoLanguages::in('en', fn () => wp_insert_term('Area wgt', 'category')['term_id']);
$terms   = fn ($config) => array_replace($config, ['taxonomies' => ['category']]);

wgt_with_config($terms, function () use ($area_it, $area_en) {
    WpGoLanguages::set_language($area_it, 'it', 'term');
    WpGoLanguages::set_language($area_en, 'en', 'term');
    WpGoLanguages::link($area_en, $area_it, 'term');
    same($area_it, Core\original($area_en, 'term'), 'a term group, Italian first');

    Core\make_originals('en');
    same($area_en, Core\original($area_it, 'term'), 'the English term is its original now');
    same($area_en, Core\original($area_en, 'term'), 'and its own');
});

remove_action('wp_go_languages/original_changed', $listen, 10);

section('What is stored bare stays');

$english_default = fn ($config) => array_replace($config, [
    'languages'     => [
        ['code' => 'en', 'locale' => 'en_GB', 'name' => 'English', 'slug' => 'en'],
        ['code' => 'it', 'locale' => 'it_IT', 'name' => 'Italiano', 'slug' => 'it'],
        ['code' => 'de', 'locale' => 'de_DE', 'name' => 'Deutsch', 'slug' => 'de'],
    ],
]);

wgt_with_config($english_default, function () {
    same('en', Core\default_language(), 'English is the default');
});
