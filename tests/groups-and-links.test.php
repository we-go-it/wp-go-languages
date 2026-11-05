<?php

/**
 * What a group's changes do to what's in it, and what a text's links are left as: a
 * synced link once its marks go, a group joined, an original moved, a default language
 * changed (a batch at a time, to the same result as one by one), the term tree core
 * caches, the alternates of what isn't a page, a hidden language in the API. And what
 * they cost, in queries, where a site's size would show.
 */

// @require fixtures/third-language.php

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

acf_add_local_field_group([
    'key'      => 'group_wgt_gl',
    'title'    => 'Groups and links',
    'fields'   => [
        ['key' => 'field_wgt_gl_cta', 'name' => 'gl_cta', 'label' => 'CTA', 'type' => 'link', 'return_format' => 'array'],
        ['key' => 'field_wgt_gl_number', 'name' => 'gl_number', 'label' => 'Number', 'type' => 'number'],
        ['key' => 'field_wgt_gl_term', 'name' => 'gl_term', 'label' => 'Term', 'type' => 'taxonomy', 'taxonomy' => 'category', 'field_type' => 'select', 'return_format' => 'id'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);

$target    = wgt_page('Contatti gl', '');
$target_en = (int) WpGoLanguages::create_translation($target, 'en', 'independent');
wp_update_post(['ID' => $target_en, 'post_status' => 'publish', 'post_title' => 'Contact gl', 'post_name' => 'contact-gl']);
$it_url = (string) get_permalink($target);
$en_url = (string) get_permalink($target_en);

$pair = function (string $title) use ($it_url) {
    $original = wgt_page($title, '');
    update_post_meta($original, 'gl_cta', ['title' => 'Contatti', 'url' => $it_url, 'target' => '']);
    update_post_meta($original, '_gl_cta', 'field_wgt_gl_cta');

    $translation = (int) WpGoLanguages::create_translation($original, 'en', 'independent');
    wp_update_post(['ID' => $translation, 'post_status' => 'publish']);
    Core\set_synced_marks($translation, ['gl_cta']);
    Core\pull_fields($translation, $original);

    return [$original, $translation];
};

$block_content = fn (string $url, array $marks) => '<!-- wp:acf/gl ' . wp_json_encode([
    'name'     => 'acf/gl',
    'data'     => ['gl_cta' => ['title' => 'x', 'url' => $url, 'target' => ''], '_gl_cta' => 'field_wgt_gl_cta'],
    'metadata' => ['wgId' => 'glblock'] + ($marks ? ['wgSynced' => $marks] : []),
], JSON_UNESCAPED_SLASHES) . ' /-->';


section('A synced link, once its marks go');

[$original, $translation] = $pair('Origine gl uno');
same($it_url, get_post_meta($translation, 'gl_cta', true)['url'], 'while it follows, stored as the original has it');

Core\make_original($translation);
same([], Core\synced_marks($translation), 'made the original: it follows nothing');
same($en_url, get_post_meta($translation, 'gl_cta', true)['url'], 'and links to the page in its own language, with nothing left to work it out');

[$original, $translation] = $pair('Origine gl due');
Core\leave_group($translation);
same($en_url, get_post_meta($translation, 'gl_cta', true)['url'], 'taken out of its group: the same');

$with_block = wgt_page('Origine gl blocco', $block_content($it_url, []));
$block_en   = (int) WpGoLanguages::create_translation($with_block, 'en', 'independent');
wgt_raw_content($block_en, $block_content($it_url, ['gl_cta']));
Core\make_original($block_en);
$parsed = parse_blocks(wgt_content($block_en))[0];
same($en_url, $parsed['attrs']['data']['gl_cta']['url'], 'a block\'s: the same');
check('and its mark is gone', !isset($parsed['attrs']['metadata']['wgSynced']));

[$original, $translation] = $pair('Origine gl tre');
Core\make_originals('en');
same($en_url, get_post_meta($translation, 'gl_cta', true)['url'], 'a new default language: the same, in the batch');
same('', get_post_meta($translation, Core\SYNCED_META, true), 'with its marks gone');
Core\make_originals('it');


section('What a text\'s links are left as');

foreach ([
    '<a href="contatti">x</a>'                          => 'a relative address',
    '<a href="foo.html">x</a>'                          => 'a file',
    '<a href="whatsapp://send?text=ciao">x</a>'         => 'an app\'s link',
    '<a href="javascript:void(0)">x</a>'                => 'a script',
    '<a href="[site_url]/pagina">x</a>'                 => 'a placeholder',
    '<a href="mailto:a@b.it">x</a>'                     => 'an address',
    '<a href="#qui">x</a>'                              => 'an anchor',
    '<a href="https://example.invalid/?a=1&amp;b=2">x</a>' => 'another site, its entities too',
] as $html => $what) {
    same($html, Core\localize_html($html, 'en'), "$what stays as it's written");
}

same('<a href="' . $en_url . '">x</a>', Core\localize_html('<a href="' . $it_url . '">x</a>', 'en'), 'a page of the site goes to its translation');
same('<a href="' . $en_url . '?a=1&amp;b=2#c">x</a>', Core\localize_html('<a href="' . $it_url . '?a=1&amp;b=2#c">x</a>', 'en'), 'its query and anchor kept, as HTML');
same('<a href="' . $en_url . '" data-href="' . $it_url . '">x</a>', Core\localize_html('<a href="' . $it_url . '" data-href="' . $it_url . '">x</a>', 'en'), 'the link\'s own address, not an attribute ending the same');


section('An independent form, translated by AI');

$form = wgt_page('Modulo gl', '', 'it', ['post_type' => Core\CF7_TYPE]);
update_post_meta($form, 'cf7_fields_0_conditions_0_value', 'Originale');
update_post_meta($form, 'cf7_fields_0_conditions_0_field', 'campo');
$form_en = (int) WpGoLanguages::create_translation($form, 'en', 'independent');
update_post_meta($form_en, 'cf7_fields_0_conditions_0_value', 'Its own');
Core\ai_write($form_en, ['post:title' => 'Form gl']);
same('Its own', get_post_meta($form_en, 'cf7_fields_0_conditions_0_value', true), 'its own conditions stay');


section('An original moved to another group');

$a = wgt_page('Gruppo gl A', '');
$b = (int) WpGoLanguages::create_translation($a, 'en', 'independent');
$c = (int) WpGoLanguages::create_translation($a, 'de', 'independent');
$x = wgt_page('Group gl X', '', 'en');
same(true, WpGoLanguages::link($a, $x), 'it links');
same($x, Core\original($a), 'it follows its new original');
check('those left behind name an original of their own group', in_array(Core\original($b), Core\translations($b), true) && Core\original($b) === Core\original($c));
same(2, count(Core\translations($b)), 'and they stay together');


section('Groups joined');

$p = wgt_page('Group gl P', '', 'en');
update_post_meta($p, 'gl_number', '11');
update_post_meta($p, '_gl_number', 'field_wgt_gl_number');
$q = (int) WpGoLanguages::create_translation($p, 'de', 'independent');
Core\set_synced_marks($q, ['gl_number']);
$r = wgt_page('Gruppo gl R', '');
update_post_meta($r, 'gl_number', '99');
update_post_meta($r, '_gl_number', 'field_wgt_gl_number');
same(true, Core\connect($p, $r), 'they join');
wp_update_post(['ID' => $r, 'post_title' => 'Gruppo gl R 2']);
same('11', (string) get_post_meta($q, 'gl_number', true), 'what follows another page keeps its value: "their content kept"');
same([], Core\synced_marks($q), 'and follows nothing it didn\'t choose');


section('A new default language, a batch at a time');

$structure = fn (string $id) => serialize_blocks([wgt_para('Testo', $id)]);

$batched_it = wgt_page('Batch gl', $structure('gl-same'));
$batched_en = (int) WpGoLanguages::create_translation($batched_it, 'en', 'independent');
$batched_de = (int) WpGoLanguages::create_translation($batched_it, 'de', 'blocks');

$other_it = wgt_page('Batch gl altro', $structure('gl-other'));
$other_en = (int) WpGoLanguages::create_translation($other_it, 'en', 'independent');
$other_de = (int) WpGoLanguages::create_translation($other_it, 'de', 'blocks');
$other_de_content = wgt_content($other_de);
wgt_raw_content($other_en, '<!-- wp:heading {"metadata":{"wgId":"gl-different"}} --><h2>Nuovo</h2><!-- /wp:heading -->');

$kept_it = wgt_page('Batch gl scelto', '');
$kept_en = (int) WpGoLanguages::create_translation($kept_it, 'en', 'independent');
$kept_de = (int) WpGoLanguages::create_translation($kept_it, 'de', 'independent');
Core\choose_original($kept_de);

$alone = wgt_page('Batch gl sola', '');

$counted = Core\make_post_originals('en');
check('it counts those it changed', $counted >= 2, (string) $counted);

same($batched_en, Core\original($batched_en), 'the language\'s page leads');
same(null, Core\mode_of($batched_en), 'with no mode');
same($batched_en, Core\original($batched_it), 'the old original follows it');
same('independent', Core\mode_of($batched_it), 'an independent translation');
same($batched_en, Core\original($batched_de), 'the other translation follows it');
same('blocks', Core\mode_of($batched_de), 'fixed still: the new original has its blocks');
same('independent', Core\mode_of($other_de), 'fixed on blocks the new original hasn\'t: independent');
same($other_de_content, wgt_content($other_de), 'its content kept');
same($kept_de, Core\original($kept_en), 'an original chosen by hand stays');
same($alone, Core\original($alone), 'a page alone stays alone');
same([$batched_en, $batched_it, $batched_de], array_values(array_intersect([$batched_en, $batched_it, $batched_de], Core\translations($batched_it))), 'nobody left the group');
same(0, Core\make_post_originals('en'), 'run again: nothing left to do');

Core\make_originals('it');
same($batched_it, Core\original($batched_en), 'and back');

update_option(Core\NEW_ORIGINALS_OPTION, 'en', true);
check('a request that died leaves it said', Core\conversions_left());
Core\finish_new_originals();
same($batched_en, Core\original($batched_it), 'the next one finishes it');
check('and it isn\'t left to do', !Core\conversions_left());
Core\make_originals('it');


section('The term tree core caches');

add_filter('wp_go_languages/taxonomies', fn ($list) => [...$list, 'category']);

$made = function (string $name, string $code, int $parent = 0) {
    $term = Core\in_language($code, fn () => wp_insert_term($name, 'category', ['parent' => $parent, 'slug' => sanitize_title($name)]));
    Core\set_language((int) $term['term_id'], $code, 'term');

    return (int) $term['term_id'];
};

$parent_it = $made('Padre gl', 'it');
$child_it  = $made('Figlio gl', 'it', $parent_it);
$parent_en = $made('Parent gl', 'en');
$child_en  = $made('Child gl', 'en', $parent_en);

delete_option('category_children');
Core\in_language('en', fn () => _get_term_hierarchy('category'));
same([$child_it], array_values(Core\in_language('it', fn () => get_term_children($parent_it, 'category'))), 'built on an English request, an Italian one finds its children');
same([$child_en], array_values(Core\in_language('en', fn () => get_term_children($parent_en, 'category'))), 'and an English one its own');
same([$parent_en, $child_en], array_values(array_intersect([$parent_en, $child_en], Core\in_language('en', fn () => get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids'])))), 'a language\'s lists still keep to it');
same([], array_values(array_intersect([$parent_it, $child_it], Core\in_language('en', fn () => get_terms(['taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids'])))), 'with none of another\'s');


section('A term picked in a field');

$translated_term = (int) Core\translation($parent_it, 'en', false, 'term') ?: (int) Core\create_term_translation($parent_it, 'en');
same((string) $translated_term, Core\localize_value(['type' => 'taxonomy'], [(string) $parent_it], 'en')[0], 'it goes to its translation');
same((string) $translated_term, Core\localize_value(['type' => 'taxonomy'], (string) $parent_it, 'en'), 'one alone too');
same('5', Core\localize_value(['type' => 'taxonomy'], '5', 'en'), 'one with none stays');


section('The posts page and a term\'s archive');

$posts_it = wgt_page('Notizie gl', '');
$posts_en = (int) WpGoLanguages::create_translation($posts_it, 'en', 'independent');
wp_update_post(['ID' => $posts_en, 'post_status' => 'publish', 'post_title' => 'News gl', 'post_name' => 'news-gl']);
update_option('show_on_front', 'page');
update_option('page_for_posts', $posts_it);

$shown = function (WP_Query $query) {
    $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $query;
};

$shown(new WP_Query(['page_id' => $posts_it]));
check('the posts page isn\'t singular', is_home() && !is_singular());
same((string) get_permalink($posts_en), Core\current_alternates()['en-GB'] ?? '', 'its hreflang names its translation');
same((string) get_permalink($posts_en), Core\switcher()['en']['url'], 'and so does the switcher');
same(0, Core\switcher()['en']['missing'], 'which doesn\'t call it missing');
update_option('show_on_front', 'posts');
delete_option('page_for_posts');

same(null, Core\translated_url($translated_term, 'term'), 'a term with no posts has no archive to lead to');
$GLOBALS['wpdb']->update($GLOBALS['wpdb']->term_taxonomy, ['count' => 2], ['term_id' => $translated_term]);
$GLOBALS['wpdb']->update($GLOBALS['wpdb']->term_taxonomy, ['count' => 2], ['term_id' => $parent_it]);
clean_term_cache([$translated_term, $parent_it], 'category');
same(2, count(array_diff_key(Core\alternates($parent_it, 'term'), ['x-default' => 1])), 'with posts, a term\'s archive has alternates');


section('A hidden language');

$hide = function ($config) {
    foreach ($config['languages'] as $i => $language) {
        if ($language['code'] === 'en') {
            $config['languages'][$i]['hidden'] = true;
        }
    }

    return $config;
};
add_filter('wp_go_languages/config', $hide);
Core\config(true);

$page_it = wgt_page('Pagina gl', '');
$page_de = (int) WpGoLanguages::create_translation($page_it, 'de', 'independent');
$page_en = (int) WpGoLanguages::create_translation($page_it, 'en', 'independent');
wp_update_post(['ID' => $page_en, 'post_status' => 'publish']);
wp_update_post(['ID' => $page_de, 'post_status' => 'publish']);

$shown(new WP_Query(['page_id' => $page_it]));
same(['de_DE'], Core\current_alternate_locales(), 'no og:locale:alternate for it');
same(false, Core\sitemap_entry(['loc' => 'x'], 'term', get_term($child_en, 'category')), 'nor a sitemap entry for its archives');

$visitor = get_current_user_id();
wp_set_current_user(0);
[$context, $front, $filter] = [State::$context, State::$front, State::$filter];
State::$context = 'rest';
State::$front   = true;
State::$filter  = null;

$listed = (new WP_Query(['post_type' => 'page', 'post__in' => [$page_it, $page_en, $page_de], 'lang' => 'all', 'fields' => 'ids']))->posts;
same([$page_it, $page_de], array_values(array_intersect([$page_it, $page_en, $page_de], $listed)), 'a visitor\'s API lists every language but it');
check('and answers it\'s not there for one of its pages', is_wp_error(Core\hide_rest_post('answer', get_post($page_en))));
same('answer', Core\hide_rest_post('answer', get_post($page_de)), 'another language\'s is');

wp_set_current_user($visitor);
same([], Core\hidden_from_rest(), 'who edits pages sees it');

[State::$context, State::$front, State::$filter] = [$context, $front, $filter];
remove_filter('wp_go_languages/config', $hide);
Core\config(true);


section('A slug changing');

$queue = &wp_go_cache_tags_purge_queue();
$queue = [];
$child = wgt_page('Figlia gl', '', 'en', ['post_parent' => $target_en, 'post_name' => 'figlia-gl']);
$queue = [];
wp_update_post(['ID' => $target_en, 'post_name' => 'contact-gl-new']);
check('the pages holding a synced link to it go', isset($queue[Core\link_tag($target, 'en')]));
check('and those holding one to a page under it, whose address moved too', isset($queue[Core\link_tag($child, 'en')]));
$queue = [];
wp_update_post(['ID' => $target_en, 'post_title' => 'Contact gl again']);
check('its title changing moves nothing', !isset($queue[Core\link_tag($target, 'en')]));
$queue = [];


section('A translation\'s AI, its retry failing');

$ai_settings = fn () => ['endpoint' => 'http://ai.test/v1', 'model' => 'm', 'key' => '', 'instructions' => ''];
$calls       = 0;
$endpoint    = function ($pre, $args, $url) use (&$calls) {
    $calls++;

    return $calls === 1
        ? ['response' => ['code' => 200], 'body' => wp_json_encode(['choices' => [['message' => ['content' => wp_json_encode(['a' => 'Hello', 'b' => 'no tags'])]]]])]
        : new WP_Error('down', 'gone');
};
add_filter('pre_option_' . Core\AI_OPTION, $ai_settings);
add_filter('pre_http_request', $endpoint, 10, 3);

$done = Core\ai_translate(['a' => 'Ciao', 'b' => '<b>grassetto</b>'], 'it', 'en');
check('the first answer\'s translation is kept', is_array($done) && ($done['translated']['a'] ?? '') === 'Hello');
check('and what\'s left says why', is_array($done) && str_contains((string) ($done['failed']['b'] ?? ''), 'gone'));

$calls = 1;
check('with nothing yet, the endpoint\'s error is the answer', is_wp_error(Core\ai_translate(['a' => 'Ciao'], 'it', 'en')));

remove_filter('pre_http_request', $endpoint, 10);
remove_filter('pre_option_' . Core\AI_OPTION, $ai_settings);


section('Smaller things');

$lone = wgt_page('Sola gl', '');
update_post_meta($lone, Core\MODE_META, 'text');
$report = Core\conversion_report();
Core\convert_text_post($lone, $report);
same('', get_post_meta($lone, Core\MODE_META, true), 'an original stored as text only has no mode any more');
update_post_meta($lone, Core\MODE_META, 'independent');
same(null, Core\mode_of($lone), 'and one with a stale mode reads as an original');

$policy = fn ($value, $key) => $key === '_hash' ? 'translate' : $value;
add_filter('wp_go_languages/meta_policy', $policy, 10, 2);
$hashed = wgt_page('Modulo hash gl', '', 'it', ['post_type' => Core\CF7_TYPE]);
update_post_meta($hashed, '_hash', 'aaaaaaa1111111111111111111111111');
$hashed_en = (int) WpGoLanguages::create_translation($hashed, 'en', 'independent');
check('a form\'s translation has a hash of its own, whatever a config lets be copied', get_post_meta($hashed_en, '_hash', true) !== 'aaaaaaa1111111111111111111111111');
remove_filter('wp_go_languages/meta_policy', $policy, 10);

[$context, $get] = [State::$context, $_GET];
State::$context = 'admin';
$_GET['post']   = [$lone, $hashed];
same(0, Core\edited_post_id(), 'the rows of a bulk action are no post edited');
$_GET['post'] = (string) $lone;
same($lone, Core\edited_post_id(), 'one post is');
State::$context = $context;
$_GET = $get;


section('What it costs');

$queries = function (callable $run) {
    global $wpdb;

    Core\forget_translations();
    wp_cache_flush();

    $before = $wpdb->num_queries;
    $run();

    return $wpdb->num_queries - $before;
};

$pages = [];

for ($i = 0; $i < 12; $i++) {
    $page = wgt_page("Voce gl $i", '');
    $done = (int) WpGoLanguages::create_translation($page, 'en', 'independent');
    wp_update_post(['ID' => $done, 'post_status' => 'publish']);
    $pages[] = (object) ['db_id' => $i + 1, 'menu_item_parent' => 0, 'type' => 'post_type', 'object' => 'page', 'object_id' => (string) $page, 'post_title' => '', 'title' => 'x', 'url' => ''];
}

$cost_twelve = $queries(fn () => Core\localize_menu_items($pages, 'en'));
$cost_three  = $queries(fn () => Core\localize_menu_items(array_slice($pages, 0, 3), 'en'));
check('a followed menu reads its pages at once: four times the items isn\'t four times the queries', $cost_twelve < $cost_three * 2, "$cost_three for 3, $cost_twelve for 12");

same(1, $queries(function () {
    Core\language_terms(true);

    foreach (Core\codes() as $code) {
        Core\language_tt_id($code);
    }
}), 'every language\'s term is one query');

$primed = array_map(fn ($item) => (int) $item->object_id, $pages);
$alone_reads = array_map(fn ($id) => Core\translations($id), $primed);
Core\forget_translations();
wp_cache_flush();
Core\prime_translations($primed);
same($alone_reads, array_map(fn ($id) => Core\translations($id), $primed), 'read at once, a group is what it is read alone');

$counting = [];
$listen   = function ($sql) use (&$counting) {
    if (stripos($sql, 'COUNT(*)') !== false && str_contains($sql, 'term_taxonomy_id = ' . Core\language_tt_id('it'))) {
        $counting[] = $sql;
    }

    return $sql;
};
add_filter('query', $listen);
wp_update_post(['ID' => (int) $primed[0], 'post_title' => 'Voce gl nuova']);
remove_filter('query', $listen);
same([], $counting, 'a save doesn\'t count a language\'s pages: nothing reads that count');

$request = new WP_REST_Request('GET', '/wp/v2/pages');
$field   = $GLOBALS['wp_rest_additional_fields']['page']['wgl']['get_callback'] ?? null;

if (!$field) {
    do_action('rest_api_init');
    $field = $GLOBALS['wp_rest_additional_fields']['page']['wgl']['get_callback'] ?? null;
}

$answer = $field(['id' => $primed[0]], 'wgl', $request, 'page');
same(['language', 'mode'], array_keys($answer), 'a list asked in the edit context gets a post\'s own, not its whole panel');
$request->set_url_params(['id' => $primed[0]]);
check('one post asked gets the panel', isset($field(['id' => $primed[0]], 'wgl', $request, 'page')['languages']));

set_query_var('sitemap', 'page');
Core\forget_translations();
wp_cache_flush();
update_meta_cache('post', $primed);
$before = $GLOBALS['wpdb']->num_queries;
array_map(fn ($id) => Core\translations($id), $primed);
same(0, $GLOBALS['wpdb']->num_queries - $before, 'as Yoast reads a sitemap\'s batch, their groups are read with it');
set_query_var('sitemap', '');

$cap = fn () => 2;
add_filter('wp_go_languages/max_parent_dropdown', $cap);
$dropdown = wgt_output(fn () => wp_dropdown_pages(Admin\quick_edit_parents(['post_type' => 'page', 'name' => 'post_parent'])));
check('past as many pages as it can list, Quick Edit prints no parents, and no select that would post one', !str_contains($dropdown, '<select') && str_contains($dropdown, 'wgl-no-parents'));
remove_filter('wp_go_languages/max_parent_dropdown', $cap);
check('under it, it lists them', str_contains(wgt_output(fn () => wp_dropdown_pages(Admin\quick_edit_parents(['post_type' => 'page', 'name' => 'post_parent']))), '<select'));

$sitemap = Core\sitemap_entry(['loc' => 'http://base.test/non-una-pagina-gl/'], 'user', (object) []);
same('<url></url>', Core\sitemap_url('<url></url>', $sitemap), 'a sitemap entry that\'s no page gets nothing');
same(0, $queries(fn () => Core\sitemap_url('<url></url>', $sitemap)), 'and isn\'t looked up by its address: no query');

$count_first  = wp_count_posts('page')->publish;
$before_count = $GLOBALS['wpdb']->num_queries;
wp_count_posts('page');
same(0, $GLOBALS['wpdb']->num_queries - $before_count, 'asked again in a request, no query');
wgt_page('Conteggio gl', '');
same((int) $count_first + 1, (int) wp_count_posts('page')->publish, 'a new page counts');
