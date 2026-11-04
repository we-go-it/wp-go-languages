<?php

/**
 * SEO: a page's alternates (published translations only, x-default the
 * default language's, none for a page alone), the hreflang values and their
 * filter, what the head prints (hreflang links, og:locale:alternate), the
 * front page's translations as each language's home, and Yoast's sitemap
 * filters on sample entries.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;

/** Make a page the queried one, as on its own URL: is_singular(), get_queried_object(). */
function wgt_seo_query(int $id): void
{
    $GLOBALS['wp_query']     = new WP_Query(['page_id' => $id, 'lang' => 'all']);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
}

$it = wgt_page('Contatti', '<!-- wp:paragraph --><p>Scrivici</p><!-- /wp:paragraph -->', 'it', ['post_name' => 'wgt-contatti']);
$en = WpGoLanguages::create_translation($it, 'en');
wp_update_post(['ID' => $en, 'post_status' => 'publish', 'post_name' => 'wgt-contact', 'post_title' => 'Contact']);
clean_post_cache($en);

$it_url = (string) get_permalink($it);
$en_url = (string) get_permalink($en);

section('hreflang values');

same('it-IT', Core\hreflang_code('it'), 'the default language\'s hreflang is its locale with a hyphen');
same('en-GB', Core\hreflang_code('en'), 'English\'s hreflang is en-GB');
same('xx', Core\hreflang_code('xx'), 'a code with no language comes back as it is');

section('alternates');

same(['it-IT' => $it_url, 'x-default' => $it_url, 'en-GB' => $en_url], Core\alternates($it), 'a translated page lists both languages and x-default, in order');
same(Core\alternates($it), Core\alternates($en), 'its translation lists the same alternates');
check('the English URL is under /en/', str_contains($en_url, '/en/wgt-contact/'));

$alone = wgt_page('Solo qui', '', 'it', ['post_name' => 'wgt-solo']);
same([], Core\alternates($alone), 'a page with no translation has no alternates (x-default is not a second language)');

$alone_en = wgt_page('Only here', '', 'en', ['post_name' => 'wgt-only']);
same([], Core\alternates($alone_en), 'nor has a page alone in another language');

// a draft translation: left out, so the page is alone again
$draft_it = wgt_page('Bozza', '', 'it', ['post_name' => 'wgt-bozza']);
$draft_en = WpGoLanguages::create_translation($draft_it, 'en');
same([], Core\alternates($draft_it), 'a draft translation is left out: no alternates');

// a password: left out too
wp_update_post(['ID' => $draft_en, 'post_status' => 'publish', 'post_password' => 'secret']);
clean_post_cache($draft_en);
same([], Core\alternates($draft_it), 'a translation with a password is left out');

// the default language's page unpublished: the others still point at each other, no x-default
$three_it = wgt_page('Tre', '', 'it', ['post_name' => 'wgt-tre']);
$three_en = WpGoLanguages::create_translation($three_it, 'en');
wp_update_post(['ID' => $three_en, 'post_status' => 'publish']);
wp_update_post(['ID' => $three_it, 'post_status' => 'draft']);
clean_post_cache($three_it);
clean_post_cache($three_en);
same([], Core\alternates($three_en), 'with the default language\'s page a draft, a lone English page has none');

$hreflang = fn ($value, $code) => $code === 'en' ? 'en' : $value;
add_filter('wp_go_languages/hreflang', $hreflang, 10, 2);
same(['it-IT', 'x-default', 'en'], array_keys(Core\alternates($it)), 'the hreflang filter changes the key of the alternates');
remove_filter('wp_go_languages/hreflang', $hreflang, 10);

section('the front page');

$front_it = wgt_page('Home test', '', 'it', ['post_name' => 'wgt-home']);
$front_en = WpGoLanguages::create_translation($front_it, 'en');
wp_update_post(['ID' => $front_en, 'post_status' => 'publish', 'post_name' => 'wgt-home-en']);
clean_post_cache($front_en);
update_option('show_on_front', 'page');
update_option('page_on_front', $front_it);

same(['it-IT' => Core\home_url_for('it'), 'x-default' => Core\home_url_for('it'), 'en-GB' => Core\home_url_for('en')], Core\alternates($front_en), 'the front page\'s translations are each language\'s home, not /en/home/');
$site_home = trailingslashit((string) get_option('home'));
same($site_home . 'en/', Core\home_url_for('en'), 'English\'s home is /en/');

section('the head');

wgt_seo_query($it);
check('the query is the Italian page', is_singular() && get_queried_object_id() === $it);
$head = wgt_output('WpGo\\Languages\\Core\\print_hreflang');
check('the head has the Italian hreflang link', str_contains($head, '<link rel="alternate" hreflang="it-IT" href="' . esc_url($it_url) . '" />'));
check('the head has the English hreflang link', str_contains($head, '<link rel="alternate" hreflang="en-GB" href="' . esc_url($en_url) . '" />'));
check('the head has x-default, the Italian page', str_contains($head, '<link rel="alternate" hreflang="x-default" href="' . esc_url($it_url) . '" />'));
same(1, substr_count($head, 'og:locale:alternate'), 'one og:locale:alternate on the Italian page');
check('it is English\'s locale, with an underscore', str_contains($head, '<meta property="og:locale:alternate" content="en_GB" />'));

// the English page, as the site shows it in English
$head_en = wgt_as_site('en', function () use ($en) {
    wgt_seo_query($en);

    return wgt_output('WpGo\\Languages\\Core\\print_hreflang');
});
check('the English page prints the same three links', substr_count($head_en, 'hreflang=') === 3 && str_contains($head_en, 'href="' . esc_url($en_url) . '"'));
check('its og:locale:alternate is Italian\'s', str_contains($head_en, 'content="it_IT"') && !str_contains($head_en, 'content="en_GB"'));

wgt_seo_query($alone);
same('', wgt_output('WpGo\\Languages\\Core\\print_hreflang'), 'an untranslated page prints nothing in the head');

wgt_seo_query($draft_it);
same('', wgt_output('WpGo\\Languages\\Core\\print_hreflang'), 'a page whose translation has a password prints nothing either');

// the blog's home with no static front page: every language's home, no og:locale:alternate
update_option('show_on_front', 'posts');
$GLOBALS['wp_query'] = new WP_Query([]);
$GLOBALS['wp_query']->is_home = true;
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$blog = wgt_output('WpGo\\Languages\\Core\\print_hreflang');
check('the blog\'s home lists every language\'s home', str_contains($blog, 'hreflang="en-GB" href="' . $site_home . 'en/"') && str_contains($blog, 'hreflang="x-default" href="' . $site_home . '"'));
check('the blog\'s home has no og:locale:alternate', !str_contains($blog, 'og:locale:alternate'));
update_option('show_on_front', 'page');

section('Yoast\'s sitemap');

$urlset = '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
$once   = Core\sitemap_urlset($urlset);
check('the urlset gets the xhtml namespace', str_contains($once, 'xmlns:xhtml="http://www.w3.org/1999/xhtml"'));
same($once, Core\sitemap_urlset($once), 'a urlset that has it already is left as it is');

$entry = Core\sitemap_entry(['loc' => $it_url, 'mod' => '2026-10-17'], 'post', get_post($it));
same(Core\alternates($it), $entry['wgl_alternates'] ?? null, 'a translated page\'s entry carries its alternates');

$alone_entry = Core\sitemap_entry(['loc' => get_permalink($alone)], 'post', get_post($alone));
check('an untranslated page\'s entry gets no alternates', !isset($alone_entry['wgl_alternates']));

$plain = wp_insert_post(['post_type' => 'post', 'post_title' => 'Un articolo', 'post_status' => 'publish']);
$plain_entry = Core\sitemap_entry(['loc' => get_permalink($plain)], 'post', get_post($plain));
check('a post of a type not translated is left as it is', !isset($plain_entry['wgl_alternates']));

$term_entry = Core\sitemap_entry(['loc' => $it_url], 'term', get_post($it));
check('a term entry is left as it is', !isset($term_entry['wgl_alternates']));

$xml = "\t<url>\n\t\t<loc>" . $it_url . "</loc>\n\t\t<lastmod>2026-10-17T10:00:00+00:00</lastmod>\n\t</url>\n";
$out = Core\sitemap_url($xml, $entry);
same(3, substr_count($out, '<xhtml:link rel="alternate"'), 'the url gets one xhtml:link per alternate');
check('the links come before </url>, which stays once', strpos($out, 'xhtml:link') < strpos($out, '</url>') && substr_count($out, '</url>') === 1);
check('the English link is there', str_contains($out, 'hreflang="en-GB" href="' . esc_url($en_url) . '"'));

// an entry Yoast adds itself (no post, no wgl_alternates): looked up by its loc
$en_xml = "\t<url>\n\t\t<loc>" . $en_url . "</loc>\n\t</url>\n";
same(3, substr_count(Core\sitemap_url($en_xml, ['loc' => $en_url]), 'xhtml:link'), 'an entry without alternates is looked up by its address');

$home_xml = "\t<url>\n\t\t<loc>{$site_home}</loc>\n\t</url>\n";
check('the home\'s entry gets the front page\'s alternates', str_contains(Core\sitemap_url($home_xml, ['loc' => $site_home]), 'hreflang="en-GB" href="' . $site_home . 'en/"'));

$lone_xml = "\t<url>\n\t\t<loc>" . get_permalink($alone) . "</loc>\n\t</url>\n";
same($lone_xml, Core\sitemap_url($lone_xml, ['loc' => get_permalink($alone)]), 'an untranslated page\'s url is left as it is');

$off_xml = "\t<url>\n\t\t<loc>https://elsewhere.example/x/</loc>\n\t</url>\n";
same($off_xml, Core\sitemap_url($off_xml, ['loc' => 'https://elsewhere.example/x/']), 'a url off the site is left as it is');
