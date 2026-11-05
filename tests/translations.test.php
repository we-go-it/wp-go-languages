<?php

// @require fixtures/third-language.php

/**
 * Translations: making one (what's refused, what's copied, kses left off,
 * the parent, the terms, the hooks), each meta key's rule and where it comes
 * from (the config files, the theme's winning, ACF's settings, the filter),
 * the copy on an original's save, ACF's options per language, the menus per
 * language, and the heir of a deleted original. A third language (German)
 * gives groups of three.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

// before any rule is read: config_policies() reads the files once per request
$plugin_file = wgt_temp_dir('translations') . '/wpml-config.xml';
file_put_contents($plugin_file, '<wpml-config><custom-fields>'
    . '<custom-field action="copy">_form</custom-field>'
    . '<custom-field action="copy">wgt_file_copy</custom-field>'
    . '<custom-field action="copy">wgt_acf_ignore</custom-field>'
    . '<custom-field action="copy-once">wgt_once</custom-field>'
    . '<custom-field action="ignore">wgt_ignored</custom-field>'
    . '<custom-field action="nonsense">wgt_nonsense</custom-field>'
    . '</custom-fields></wpml-config>');

// as a plugin's file: first, before the theme's
add_filter('wp_go_languages/config_files', fn ($files) => array_merge([$plugin_file, '/no/such/wpml-config.xml'], $files));

acf_add_local_field_group([
    'key'    => 'group_wgt_rules',
    'title'  => 'Rules test',
    'fields' => [
        ['key' => 'field_wgt_acf_copy', 'name' => 'wgt_acf_copy', 'type' => 'text'],
        ['key' => 'field_wgt_acf_ignore', 'name' => 'wgt_acf_ignore', 'type' => 'text'],
        ['key' => 'field_wgt_acf_empty', 'name' => 'wgt_acf_empty', 'type' => 'text'],
        ['key' => 'field_wgt_acf_once', 'name' => 'wgt_acf_once', 'type' => 'text'],
        ['key' => 'field_wgt_acf_select', 'name' => 'wgt_acf_select', 'type' => 'select', 'choices' => ['a' => 'a']],
        ['key' => 'field_wgt_acf_url', 'name' => 'wgt_acf_url', 'type' => 'url'],
        ['key' => 'field_wgt_acf_email', 'name' => 'wgt_acf_email', 'type' => 'email'],
        ['key' => 'field_wgt_acf_link', 'name' => 'wgt_acf_link', 'type' => 'link'],
        ['key' => 'field_wgt_acf_wys', 'name' => 'wgt_acf_wys', 'type' => 'wysiwyg'],
        ['key' => 'field_wgt_acf_old', 'name' => 'wgt_acf_old', 'type' => 'text', 'wpml_cf_preferences' => 1, 'wgl_translation' => 'copy'],
        ['key' => 'field_wgt_acf_old_img', 'name' => 'wgt_acf_old_img', 'type' => 'image', 'wpml_cf_preferences' => 2, 'wgl_translation' => 'translate'],
        ['key' => 'field_wgt_acf_rows', 'name' => 'wgt_acf_rows', 'type' => 'repeater', 'sub_fields' => [
            ['key' => 'field_wgt_acf_rows_title', 'name' => 'title', 'type' => 'text'],
        ]],
        ['key' => 'field_wgt_option', 'name' => 'wgt_option', 'type' => 'text', 'default_value' => 'Default'],
        ['key' => 'field_wgt_option_own', 'name' => 'wgt_option_own', 'type' => 'text'],
        ['key' => 'field_wgt_option_once', 'name' => 'wgt_option_once', 'type' => 'image'],
        ['key' => 'field_wgt_option_words', 'name' => 'wgt_option_words', 'type' => 'text'],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);

// texts that hold no words, named in code
add_filter('wp_go_languages/field_policy', fn ($policy, $field) => in_array($field['key'], ['field_wgt_acf_copy', 'field_wgt_option'], true) ? 'copy' : $policy, 10, 2);

// the filter is last, and says anything
add_filter('wp_go_languages/meta_policy', function ($policy, $key) {
    return match ($key) {
        'wgt_filtered'    => 'copy',
        'wgt_bad_policy'  => 'everything',
        '_edit_last'      => 'copy',
        default           => $policy,
    };
}, 10, 2);

/** An ACF value and its reference, on a post. */
function wgt_acf_meta(int $id, string $name, mixed $value, string $field): void
{
    update_post_meta($id, $name, $value);
    update_post_meta($id, "_$name", $field);
}

section('What is refused');

$it = wgt_page('Originale', '<!-- wp:paragraph --><p>Ciao</p><!-- /wp:paragraph -->');
$post = wp_insert_post(['post_type' => 'post', 'post_title' => 'Untranslated', 'post_status' => 'publish']);
$code = fn ($result) => is_wp_error($result) ? $result->get_error_code() : $result;

same('wgl_not_translatable', $code(WpGoLanguages::create_translation($post, 'en')), 'a post of an untranslated type can\'t be translated');
same('wgl_bad_language', $code(WpGoLanguages::create_translation($it, 'fr')), 'nor into a language the site hasn\'t');
same('wgl_bad_mode', $code(WpGoLanguages::create_translation($it, 'en', 'copy')), 'nor in a mode that isn\'t independent or fixed');
same('wgl_same_language', $code(WpGoLanguages::create_translation($it, 'it')), 'nor into its own language');

$en = WpGoLanguages::create_translation($it, 'en');
same('wgl_exists', $code(WpGoLanguages::create_translation($it, 'en')), 'nor twice into one language');

// translating a translation translates its original
$de = WpGoLanguages::create_translation($en, 'de');
same($it, WpGoLanguages::original($de), 'a translation\'s translation names the original');
same(['it' => $it, 'en' => $en, 'de' => $de], WpGoLanguages::translations($de), 'and is in its group');

$no_language = wp_insert_post(['post_type' => 'page', 'post_title' => 'Prima del plugin', 'post_status' => 'publish']);
wp_delete_object_term_relationships($no_language, Core\LANGUAGE_TAXONOMY);
clean_object_term_cache($no_language, 'page');
$made = WpGoLanguages::create_translation($no_language, 'en');
check('a page with no language is given the default one, then translated', is_int($made) && WpGoLanguages::language_of($no_language) === 'it');

section('What a translation starts with');

$parent_it = wgt_page('Genitore', '');
$parent_en = WpGoLanguages::create_translation($parent_it, 'en');
$lonely    = wgt_page('Genitore solo', '');

$source = wgt_page('Copiata', '<!-- wp:paragraph --><p>Testo</p><!-- /wp:paragraph -->', 'it', [
    'post_name'      => 'wgt-copied',
    'post_excerpt'   => 'Riassunto',
    'menu_order'     => 7,
    'post_password'  => 'segreto',
    'comment_status' => 'open',
    'ping_status'    => 'closed',
    'post_parent'    => $parent_it,
]);
$orphan = wgt_page('Orfana', '', 'it', ['post_parent' => $lonely]);

$created = [];
add_action('wp_go_languages/translation_created', function (...$args) use (&$created) {
    $created[] = $args;
}, 10, 4);
$set = [];
add_action('wp_go_languages/language_set', function ($id, $code) use (&$set) {
    $set[] = [$id, $code];
}, 10, 2);

$copy = WpGoLanguages::create_translation($source, 'en', 'blocks');
$new  = get_post($copy);

check(
    'the title, slug, excerpt, order, password and comment settings are the original\'s',
    $new->post_title === 'Copiata' && $new->post_name === 'wgt-copied' && $new->post_excerpt === 'Riassunto' && $new->menu_order === 7
    && $new->post_password === 'segreto' && $new->comment_status === 'open' && $new->ping_status === 'closed'
);
same('draft', $new->post_status, 'the translation is a draft');
same(get_current_user_id(), (int) $new->post_author, 'its author is the user who made it');
same($parent_en, $new->post_parent, 'its parent is the parent\'s translation');
same(0, get_post(WpGoLanguages::create_translation($orphan, 'en'))->post_parent, 'with no translation of the parent, it is top-level');
same(wgt_content($source), $new->post_content, 'its content is the original\'s, with the IDs the original was given');
same([[$copy, $source, 'en', 'blocks']], array_slice($created, -2, 1), 'wp_go_languages/translation_created fires with the translation, its original, language and mode');
same(2, count(array_filter($set, fn ($entry) => $entry === [$copy, 'en'])), 'language_set fires twice for a new translation: as inserted, then set');

$retitle = function ($data, $original, $code, $mode) {
    $data['post_title'] = "{$original->post_title} ($code, $mode)";

    return $data;
};
add_filter('wp_go_languages/translation_data', $retitle, 10, 4);
same('Copiata (de, independent)', get_post_field('post_title', WpGoLanguages::create_translation($source, 'de')), 'wp_go_languages/translation_data changes the new post\'s fields');
remove_filter('wp_go_languages/translation_data', $retitle, 10);

// the content is copied, not filtered again: a user without unfiltered_html
$script = '<!-- wp:html --><script>wgt()</script><!-- /wp:html -->';
$raw    = wgt_page('Con script', '');
wgt_raw_content($raw, $script);
$deny = fn ($caps) => ['unfiltered_html' => false] + $caps;
add_filter('user_has_cap', $deny);
kses_init();
check('kses runs for this user', has_filter('content_save_pre', 'wp_filter_post_kses') !== false);
$filtered = WpGoLanguages::create_translation($raw, 'en');
check('the copy keeps what kses would have taken out', str_contains(wgt_content($filtered), '<script>wgt()</script>') && wgt_content($filtered) === wgt_content($raw));
check('kses is on again after the copy', has_filter('content_save_pre', 'wp_filter_post_kses') !== false);
remove_filter('user_has_cap', $deny);
kses_remove_filters();

section('Terms');

register_taxonomy('wgt_topic', 'page', ['public' => false]);
register_taxonomy('wgt_area', 'page', ['public' => false]);
add_filter('wp_go_languages/taxonomies', fn ($taxonomies) => array_merge($taxonomies, ['wgt_area']));

$topic   = wp_insert_term('Argomento', 'wgt_topic')['term_id'];
$area_it = wp_insert_term('Zona', 'wgt_area')['term_id'];
$area_en = WpGoLanguages::in('en', fn () => wp_insert_term('Area', 'wgt_area')['term_id']);
$alone   = wp_insert_term('Senza traduzione', 'wgt_area')['term_id'];
WpGoLanguages::link($area_en, $area_it, 'term');

$termed = wgt_page('Con termini', '');
wp_set_object_terms($termed, [$topic], 'wgt_topic');
wp_set_object_terms($termed, [$area_it, $alone], 'wgt_area');
$termed_en = WpGoLanguages::create_translation($termed, 'en');

same([$topic], wp_get_object_terms($termed_en, 'wgt_topic', ['fields' => 'ids', 'lang' => 'all']), 'an untranslated taxonomy\'s terms are copied as they are');
// set in the translation's language: in the current one (Italian),
// term_exists() finds no English term and wp_set_object_terms() skips it
same([$area_en], wp_get_object_terms($termed_en, 'wgt_area', ['fields' => 'ids', 'lang' => 'all']), 'a translated one\'s become their translations, one without dropped');
same('en', WpGoLanguages::language_of($termed_en), 'the plugin\'s own taxonomies aren\'t copied: the language is the translation\'s');

section('Where a rule comes from');

$rules = wgt_page('Regole', '');
foreach (['wgt_acf_copy', 'wgt_acf_ignore', 'wgt_acf_empty', 'wgt_acf_once', 'wgt_acf_select', 'wgt_acf_url', 'wgt_acf_email', 'wgt_acf_link', 'wgt_acf_wys', 'wgt_acf_old', 'wgt_acf_old_img'] as $name) {
    wgt_acf_meta($rules, $name, "valore $name", "field_$name");
}
wgt_acf_meta($rules, 'wgt_acf_rows', 1, 'field_wgt_acf_rows');
wgt_acf_meta($rules, 'wgt_acf_rows_0_title', 'Titolo', 'field_wgt_acf_rows_title');

$policy = fn (string $key) => Core\meta_policy($key, $rules);

same('translate', $policy('_form'), 'the theme\'s wpml-config.xml wins a key a plugin\'s names too');
same('copy', $policy('wgt_file_copy'), 'a config file names a key\'s rule');
same('translate', $policy('wgt_once'), 'WPML\'s copy-once is per language (translate): on a translation the two do the same');
same('ignore', $policy('_yoast_wpseo_canonical'), 'a plugin\'s own file is read (Yoast\'s)');
same('translate', $policy('wgt_nonsense'), 'an action the file can\'t mean is no rule');
same('copy', $policy('wgt_acf_ignore'), 'a config file wins over a field\'s type');
same('copy', $policy('wgt_acf_copy'), 'a text the code says holds no words is copy (wp_go_languages/field_policy)');
same('copy', $policy('_wgt_acf_copy'), 'its reference key follows the field');
same('translate', $policy('wgt_acf_empty'), 'a text is translated');
same('translate', $policy('wgt_acf_wys'), 'an editor\'s text too');
same('translate', $policy('wgt_acf_link'), 'and a link, its text with its address');
same('copy', $policy('wgt_acf_select'), 'what isn\'t a text is copy');
same('copy', $policy('wgt_acf_url'), 'a URL is an address, not a text');
same('copy', $policy('wgt_acf_email'), 'an email too');
same('translate', $policy('wgt_acf_old'), 'WPML\'s number and our old switch mean nothing on a text');
same('copy', $policy('wgt_acf_old_img'), 'nor on an image');
same('copy', $policy('wgt_acf_rows'), 'a repeater\'s row count follows the repeater');
same('translate', $policy('wgt_acf_rows_0_title'), 'its cells follow their sub field');
same('translate', $policy('wgt_unnamed'), 'a key nothing names is translate');
same('copy', $policy('wgt_filtered'), 'the filter has the last word');
same('translate', $policy('wgt_bad_policy'), 'a filter\'s answer that is no rule is translate');
same('ignore', $policy('_edit_last'), 'WordPress\'s bookkeeping is ignore, whatever the filter says');
same('ignore', $policy('_wgl_mode'), 'and so is the plugin\'s own');
same('copy', Core\meta_policy('wgt_acf_copy', 0, acf_get_field('field_wgt_acf_copy')), 'a field given by the caller needs no saved reference');

section('The copy on creation and on the original\'s save');

$synced = [];
add_action('wp_go_languages/synced', function ($translation, $original) use (&$synced) {
    $synced[] = [$translation, $original];
}, 10, 2);

$page = wgt_page('Copie', '');
update_post_meta($page, 'wgt_file_copy', 'copy IT');
update_post_meta($page, 'wgt_once', 'once IT');
update_post_meta($page, 'wgt_ignored', 'ignored IT');
update_post_meta($page, 'wgt_translated', 'translate IT');
update_post_meta($page, 'wgt_slashed', wp_slash('C:\\path "quoted"'));
add_post_meta($page, 'wgt_many', 'one');
add_post_meta($page, 'wgt_many', 'two');
update_post_meta($page, '_edit_lock', '123:1');

// a translation by the rules: only those take the copy keys on a save
$copy = WpGoLanguages::create_translation($page, 'en', 'blocks');
$meta = fn (string $key) => get_post_meta($copy, $key, true);
$free = (int) WpGoLanguages::create_translation($page, 'de');
$page2 = wgt_page('Copie libere', '');
update_post_meta($page2, 'wgt_file_copy', 'copy IT');
$open = (int) WpGoLanguages::create_translation($page2, 'en', 'blocks');

check('on creation every key but the ignored ones is copied', $meta('wgt_file_copy') === 'copy IT' && $meta('wgt_once') === 'once IT' && $meta('wgt_translated') === 'translate IT');
same('', $meta('wgt_ignored'), 'an ignored key isn\'t copied');
same('', $meta('_edit_lock'), 'nor is WordPress\'s bookkeeping');
same('C:\\path "quoted"', $meta('wgt_slashed'), 'a value\'s slashes survive the copy');
same(['one', 'two'], get_post_meta($copy, 'wgt_many'), 'a key with several values comes whole');
same(null, WpGoLanguages::mode($page), 'the original has no mode');

update_post_meta($copy, 'wgt_file_copy', 'changed in code');
update_post_meta($copy, 'wgt_once', 'once EN');
update_post_meta($copy, 'wgt_translated', 'translate EN');
update_post_meta($page, 'wgt_once', 'once IT 2');
update_post_meta($page, 'wgt_translated', 'translate IT 2');
update_post_meta($page, 'wgt_file_copy', 'copy IT 2');

update_post_meta($free, 'wgt_file_copy', 'DE own');
update_post_meta($open, 'wgt_file_copy', 'EN own');
update_post_meta($page2, 'wgt_file_copy', 'copy IT 2');
wp_update_post(['ID' => $page2, 'post_title' => 'Copie libere salvate']);

$synced = [];
wp_update_post(['ID' => $page, 'post_title' => 'Copie salvate']);

same('changed in code', $meta('wgt_file_copy'), 'the original\'s save copies no copy key: only what\'s linked follows');
same('DE own', get_post_meta($free, 'wgt_file_copy', true), 'not to an independent translation: its fields are all its own');
same('EN own', get_post_meta($open, 'wgt_file_copy', true), 'nor to fixed blocks with free fields (structure)');
same('once EN', $meta('wgt_once'), 'a copy-once key stays the translation\'s');
same('translate EN', $meta('wgt_translated'), 'a translate key stays the translation\'s');
// a fixed one is synced twice: its copy fields, then its blocks (fixed.php)
same([[$copy, $page]], array_values(array_unique($synced, SORT_REGULAR)), 'wp_go_languages/synced fires for each translation by the rules, not an independent translation');

update_post_meta($copy, 'wgt_file_copy', 'EN own');
$synced = [];
wp_update_post(['ID' => $copy, 'post_title' => 'A translation saved']);
same('copy IT 2', get_post_meta($page, 'wgt_file_copy', true), 'a translation\'s save copies nothing back');
same('EN own', $meta('wgt_file_copy'), 'nor does its own save take them');
same('once EN', $meta('wgt_once'), 'its translated ones stay');
same([], $synced, 'and syncs nothing');

update_post_meta($free, 'wgt_file_copy', 'DE own');
wp_update_post(['ID' => $free, 'post_title' => 'Independent saved']);
same('DE own', get_post_meta($free, 'wgt_file_copy', true), 'an independent translation\'s own save leaves its fields alone');
update_post_meta($open, 'wgt_file_copy', 'EN own');
wp_update_post(['ID' => $open, 'post_title' => 'Fixed blocks saved']);
same('EN own', get_post_meta($open, 'wgt_file_copy', true), 'so does a fixed blocks one\'s');

delete_post_meta($page, 'wgt_file_copy');
update_post_meta($copy, 'wgt_file_copy', 'EN own');
wp_update_post(['ID' => $page, 'post_title' => 'Copie di nuovo']);
same('EN own', $meta('wgt_file_copy'), 'a copy key removed from the original stays on its translation');

section('Options per language');

foreach ([
    'options_wgt_option' => 'IT', '_options_wgt_option' => 'field_wgt_option',
    'options_en_wgt_option' => 'EN', '_options_en_wgt_option' => 'field_wgt_option',
    'options_wgt_option_own' => 'IT own', '_options_wgt_option_own' => 'field_wgt_option_own',
    'options_en_wgt_option_own' => 'EN own', '_options_en_wgt_option_own' => 'field_wgt_option_own',
] as $name => $value) {
    update_option($name, $value);
}

acf_get_store('values')->reset();

same('IT', WpGoLanguages::in('en', fn () => get_field('wgt_option', 'option')), 'in English, a copy option is the default language\'s');
same('EN own', WpGoLanguages::in('en', fn () => get_field('wgt_option_own', 'option')), 'a translate option is the language\'s own');
same('IT own', get_field('wgt_option_own', 'option'), 'the default language reads its own');
// by the field's key: ACF finds a field by its name only through a stored reference
same('IT', get_field('field_wgt_option', 'option_en'), 'option_en is read as options_en');

// not a text (an image: a logo): the stored one in every language
update_option('options_wgt_option_once', 17);
update_option('_options_wgt_option_once', 'field_wgt_option_once');
acf_get_store('values')->reset();
same(17, (int) WpGoLanguages::in('en', fn () => get_field('wgt_option_once', 'option', false)), 'an image option the language has none of reads the stored one');
same(17, (int) WpGoLanguages::in('de', fn () => get_fields('options', false)['wgt_option_once'] ?? 0), 'get_fields() lists it too, though the language has no row for it');
check('a text option the language has none of is not listed so', !array_key_exists('wgt_option_own', WpGoLanguages::in('de', fn () => get_fields('options') ?: [])));
update_option('options_en_wgt_option_once', 23);
update_option('_options_en_wgt_option_once', 'field_wgt_option_once');
acf_get_store('values')->reset();
same(17, (int) WpGoLanguages::in('en', fn () => get_field('wgt_option_once', 'option', false)), 'a row of its own, from before, is not read: the same in every language');
delete_option('options_en_wgt_option_once');
delete_option('_options_en_wgt_option_once');
acf_get_store('values')->reset();

// the filter can still make one per language: the stored one until it has its own
add_filter('wp_go_languages/meta_policy', $per_language = fn ($policy, $key) => $key === 'wgt_option_once' ? 'translate' : $policy, 20, 2);
update_option('options_en_wgt_option_once', 23);
update_option('_options_en_wgt_option_once', 'field_wgt_option_once');
acf_get_store('values')->reset();
same(23, (int) WpGoLanguages::in('en', fn () => get_field('wgt_option_once', 'option', false)), 'made per language in code, its own');
update_option('options_en_wgt_option_once', '');
acf_get_store('values')->reset();
same(17, (int) WpGoLanguages::in('en', fn () => get_field('wgt_option_once', 'option', false)), 'emptied, the stored one again');
remove_filter('wp_go_languages/meta_policy', $per_language, 20);

// per language, a text: empty until translated, rather than another language
update_option('options_wgt_option_words', 'Testo');
update_option('_options_wgt_option_words', 'field_wgt_option_words');
acf_get_store('values')->reset();
same(null, WpGoLanguages::in('en', fn () => get_field('wgt_option_words', 'option')), 'a text option the language hasn\'t translated stays empty');

delete_option('options_wgt_option');
acf_get_store('values')->reset();
same('Default', get_field('field_wgt_option', 'options_de'), 'a copy option the default language hasn\'t set is the field\'s default');

section('Into the default language');

// an English page with a fixed German translation, then "+" into Italian
$en_first = wgt_page('Prima EN', serialize_blocks([wgt_acf('rich-text', ['text' => '<p>First</p>'])]), 'en', ['post_name' => 'wgt-first-en']);
$de_first = WpGoLanguages::create_translation($en_first, 'de', 'blocks');
$it_first = WpGoLanguages::create_translation($en_first, 'it', 'blocks');

same($it_first, Core\original($en_first), 'the Italian copy is the original at once');
same($it_first, Core\original($de_first), 'the German page names it');
same(null, Core\mode_of($it_first), 'it has no mode: asked fixed, it is a copy');
same('independent', Core\mode_of($en_first), 'the English page is an independent translation, unlocked');
same('blocks', Core\mode_of($de_first), 'the German one stays fixed, following the Italian page');
$first_id = wgt_id(wgt_blocks($en_first)[0]);
check('whose blocks are the English page\'s, IDs and all', $first_id !== '' && $first_id === wgt_id(wgt_blocks($it_first)[0]));

section('A deleted original');

// the default language's page is the heir, whoever was published (an
// Italian translation of an English original: linked, as "+" into Italian
// makes the copy the original right away)
$en_original = wgt_page('Original EN', '', 'en');
$it_heir     = wgt_page('Erede IT', '', 'it', ['post_status' => 'draft']);
WpGoLanguages::link($it_heir, $en_original);
update_post_meta($it_heir, Core\MODE_META, 'independent');
$de_other    = WpGoLanguages::create_translation($en_original, 'de', 'blocks');
wp_update_post(['ID' => $de_other, 'post_status' => 'publish']);
wp_delete_post($en_original, true);

same(null, WpGoLanguages::mode($it_heir), 'the default language\'s translation is the heir, though a draft');
same($it_heir, WpGoLanguages::original($de_other), 'the other names it');
same('blocks', WpGoLanguages::mode($de_other), 'the heir has the same blocks: the fixed one stays fixed');

// a trashed original stays the original
$trashed = wgt_page('Cestinata', '');
$trashed_en = WpGoLanguages::create_translation($trashed, 'en');
wp_trash_post($trashed);
same($trashed, WpGoLanguages::original($trashed_en), 'a trashed original is still the original');

// a group of two: the other alone, no group left
$pair = wgt_page('Coppia', '');
$pair_en = WpGoLanguages::create_translation($pair, 'en', 'blocks');
$group = Core\group_of($pair);
wp_delete_post($pair, true);
check('a pair\'s original deleted: the other is an original, its group gone', !Core\is_translation($pair_en) && WpGoLanguages::mode($pair_en) === null && get_term($group, Core\GROUP_TAXONOMY) === null);
same(['en' => $pair_en], WpGoLanguages::translations($pair_en), 'and it is its own only translation');

section('Link and unlink');

$heard = [];
add_action('wp_go_languages/linked', function ($id, $original, $kind) use (&$heard) {
    $heard[] = ['linked', $id, $original, $kind];
}, 10, 3);
add_action('wp_go_languages/unlinked', function ($id, $kind) use (&$heard) {
    $heard[] = ['unlinked', $id, $kind];
}, 10, 2);

$one = wgt_page('Uno', '');
$two = wgt_page('Two', '', 'en');
WpGoLanguages::link($two, $one);
WpGoLanguages::link($two, $one);
Core\unlink($two);
Core\unlink($two);

same([['linked', $two, $one, 'post'], ['unlinked', $two, 'post']], $heard, 'linked fires once, not when already in the group; unlinked once, not for a page in no group');
same('independent', (function () use ($one) {
    $three = wgt_page('Drei', '', 'de');
    WpGoLanguages::link($three, $one);

    return WpGoLanguages::mode($three);
})(), 'a translation with no mode stored is an independent translation');
