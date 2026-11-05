<?php

/**
 * The admin screens: edit links (CF7's screen for a form), the nonce'd link
 * that makes a translation and its action, the editor panel's data and
 * writes (a mode switch, a language change moving the parent and the slug),
 * the lists' lock on a fixed translation, the Languages screen's save
 * (unregistered types kept, reserved slugs, the domains, what the code sets
 * left as stored), `wp languages link`, and the
 * lists' language remembered only from the admin bar's switch.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Cli\Command;
use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

/** WP-CLI's messages, kept instead of printed. */
final class WgtAdminLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function info($message)
    {
        $this->lines[] = "info: $message";
    }

    public function success($message)
    {
        $this->lines[] = "success: $message";
    }

    public function warning($message)
    {
        $this->lines[] = "warning: $message";
    }

    public function error($message)
    {
        $this->lines[] = "error: $message";
    }

    public function error_multi_line($lines)
    {
        $this->lines[] = 'error: ' . implode("\n", (array) $lines);
    }

    public function debug($message, $group = false)
    {
    }
}

/**
 * Run a `wp languages` subcommand: what it logged, and whether it stopped
 * with an error.
 *
 * @return array{lines:list<string>, error:bool}
 */
function wgt_admin_cli(callable $callback): array
{
    $logger  = new WgtAdminLogger();
    $regular = WP_CLI::get_logger();
    // WP_CLI::error() throws instead of exiting (private: what wp-cli's own tests set)
    $capture = new ReflectionProperty('WP_CLI', 'capture_exit');
    $capture->setValue(null, true);
    WP_CLI::set_logger($logger);
    $error = false;

    try {
        $callback(new Command());
    } catch (WP_CLI\ExitException $exit) {
        $error = true;
    } finally {
        $capture->setValue(null, false);
        WP_CLI::set_logger($regular);
    }

    return ['lines' => $logger->lines, 'error' => $error];
}

/** Post the Languages screen, as its form does: where it redirected. */
function wgt_admin_save(array $posted): array
{
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST                     = wp_slash($posted + ['action' => 'wgl_save_settings']);
    $_REQUEST['_wpnonce']      = wp_create_nonce('wgl_save_settings');
    $_POST['_wpnonce']         = $_REQUEST['_wpnonce'];

    try {
        return wgt_stopped('WpGo\\Languages\\Admin\\save_settings');
    } finally {
        $_POST = [];
        unset($_REQUEST['_wpnonce']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }
}

$stored_config = get_option(Core\OPTION);

section('edit links');

$form = WPCF7_ContactForm::get_template(['title' => 'Form test']);
$form_id = (int) $form->save();
WpGoLanguages::set_language($form_id, 'it');

same(add_query_arg(['page' => 'wpcf7', 'post' => $form_id, 'action' => 'edit'], admin_url('admin.php')), Admin\edit_url($form_id), 'a CF7 form opens in CF7\'s screen');
same('', (string) get_edit_post_link($form_id, 'raw'), 'WordPress has no edit link for a form (why edit_url() makes one)');

$page = wgt_page('Pagina admin', serialize_blocks([wgt_acf('rich-text', ['text' => '<p>Uno</p>'])]), 'it', ['post_name' => 'wgt-admin']);
same(admin_url('post.php?post=' . $page . '&action=edit'), Admin\edit_url($page), 'a page opens in its edit screen');

$subscriber = wp_insert_user(['user_login' => 'wgt_sub_' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
$admin = get_current_user_id();
wp_set_current_user($subscriber);
same('', Admin\edit_url($form_id), 'a user who can\'t edit the form gets no link to it');
wp_set_current_user($admin);

$create = Admin\create_url($page, 'en', 'text');
// wp_nonce_url() escapes it for HTML
parse_str((string) parse_url(html_entity_decode($create), PHP_URL_QUERY), $query);
same(['wgl_create_translation', (string) $page, 'en', 'text'], [$query['action'] ?? '', $query['post'] ?? '', $query['lang'] ?? '', $query['mode'] ?? ''], 'the link that makes a translation names the post, the language and the mode');
check('its nonce is the post\'s and the language\'s', (bool) wp_verify_nonce($query['_wpnonce'] ?? '', 'wgl_create_translation_' . $page . '_en'));
check('it doesn\'t do for another language', !wp_verify_nonce($query['_wpnonce'] ?? '', 'wgl_create_translation_' . $page . '_it'));

section('making a translation from the link');

// a form's translation, made as the admin bar's link makes it: off to CF7's screen
$_GET = ['action' => 'wgl_create_translation', 'post' => $form_id, 'lang' => 'en', 'mode' => 'independent'];
$_REQUEST['_wpnonce'] = wp_create_nonce('wgl_create_translation_' . $form_id . '_en');
$stop = wgt_stopped('WpGo\\Languages\\Admin\\create_translation_action');
$form_en = WpGoLanguages::translation($form_id, 'en');
check('the action made the form\'s translation', is_int($form_en) && $form_en !== $form_id);
same('redirect', $stop['kind'], 'then it redirects');
same(add_query_arg('wgl_created', 1, Admin\edit_url((int) $form_en)), $stop['target'], 'to the new form in CF7\'s screen');

// again: the translation exists, so it dies with the reason
$stop = wgt_stopped('WpGo\\Languages\\Admin\\create_translation_action');
check('a second time it stops with a 400', $stop['kind'] === 'die' && $stop['status'] === 400);
$_GET = [];
unset($_REQUEST['_wpnonce']);

section('the editor panel\'s data');

$fixed = WpGoLanguages::create_translation($page, 'en', 'text');
$data  = Admin\panel_data($page);
same('it', $data['language'], 'the original\'s panel: its language');
same(null, $data['mode'], 'no mode on an original');
same(null, $data['original'], 'no original either');
same(false, $data['can_relocate'], 'a page with translations can\'t change language');
same(['it', 'en'], array_column($data['languages'], 'code'), 'a row per language, in order');
same([true, false], array_column($data['languages'], 'own'), 'its own language marked');
same($fixed, $data['languages'][1]['id'], 'the English row is the translation');
same('blocks', $data['languages'][1]['mode'], 'with its mode, shown as the fixed layout while it isn\'t converted');
same(Admin\edit_url($fixed), $data['languages'][1]['edit'], 'and its edit link');
same(null, $data['languages'][1]['independent'], 'no link to make what exists');

$data = Admin\panel_data($fixed);
same('blocks', $data['mode'], 'the translation\'s panel: its mode');
same($page, $data['original']['id'] ?? null, 'and its original');
same(null, $data['dropped'], 'and has no dropped count');

$lone = wgt_page('Sola', '', 'it', ['post_name' => 'wgt-sola']);
$data = Admin\panel_data($lone);
same(true, $data['can_relocate'], 'a page alone can change language');
check('a missing language offers both kinds of translation', is_string($data['languages'][1]['independent']) && str_contains((string) $data['languages'][1]['blocks'], 'mode=blocks'));

$dup_source = wgt_page('Con duplicato', serialize_blocks([wgt_acf('rich-text', ['text' => '<p>A</p>'])]), 'it', ['post_name' => 'wgt-dup']);
$dup = WpGoLanguages::create_translation($dup_source, 'en');
wgt_save($dup, [...wgt_blocks($dup), wgt_para('own')]);
$data = Admin\panel_data($dup);
same('independent', $data['mode'], 'a duplicate\'s panel: its mode');
same(1, $data['dropped'], 'it counts the block it would lose turning fixed');

section('the lists\' lock on a fixed translation');

$cell  = fn (int $id) => wgt_output(fn () => Admin\column('wgl_languages', $id));
$locks = fn (int $id) => substr_count($cell($id), 'dashicons-lock');
check('a fixed translation\'s row: a lock on its own language\'s flag', $locks($fixed) === 1 && preg_match('#<span class="wgl-lang wgl-lang--own"[^>]*><img class="wgl-flag"[^>]*alt="EN"[^>]*><span class="wgl-lang__badge dashicons dashicons-lock"#', $cell($fixed)) === 1);
check('its original\'s row: a lock on the English link\'s flag', $locks($page) === 1 && preg_match('#<a class="wgl-lang" href="[^"]*" title="[^"]*"[^>]*><img class="wgl-flag"[^>]*alt="EN"[^>]*><span class="wgl-lang__badge dashicons dashicons-lock"#', $cell($page)) === 1);
same(0, $locks($dup), 'a duplicate\'s row: none');
same(0, $locks($dup_source), 'nor its original\'s');
same('', Admin\fixed_lock($lone), 'a page alone has no lock');
check('the original\'s flag has a star, once a row', substr_count($cell($page), 'wgl-lang__original') === 1 && preg_match('#alt="IT"[^>]*><span class="wgl-lang__original#', $cell($page)) === 1);
check('on a translation\'s row too, on its original\'s flag', substr_count($cell($fixed), 'wgl-lang__original') === 1 && preg_match('#alt="IT"[^>]*><span class="wgl-lang__original#', $cell($fixed)) === 1);
$star_en = wgt_page('Origin EN wgt', '', 'en', ['post_name' => 'wgt-origin-en']);
$star_it = wgt_page('Copia IT wgt', '', 'it', ['post_name' => 'wgt-copia-it']);
WpGoLanguages::link($star_it, $star_en);
check('an English original: the star on the English flag, not the default language\'s', preg_match('#alt="EN"[^>]*><span class="wgl-lang__original#', $cell($star_it)) === 1 && substr_count($cell($star_it), 'wgl-lang__original') === 1);
same(0, substr_count($cell($lone), 'wgl-lang__original'), 'a page with no translations has no star');
check('hovering the row\'s own flag names its page', str_contains($cell($page), 'title="' . esc_attr('Italiano: ' . Admin\page_title($page)) . '"'));
check('and a translation\'s, its page too', str_contains($cell($page), 'title="' . esc_attr('English: ' . Admin\page_title($fixed)) . ' ('));
$plus = $cell($lone);
check('the "+" makes a duplicate without the dialog', str_contains($plus, 'href="' . esc_url(Admin\create_url($lone, 'en')) . '"'));
$plus_it = $cell(wgt_page('Only EN wgt', '', 'en', ['post_name' => 'wgt-only-en']));
check('the default language\'s "+" says so, for the dialog\'s one choice', str_contains($plus_it, 'data-wgl-lang="it"') && str_contains($plus_it, 'data-wgl-default="1"'));
$panel_en = Admin\panel_data(wgt_page('Panel EN wgt', '', 'en', ['post_name' => 'wgt-panel-en']))['languages'][0];
check('the panel offers no fixed page in the default language, a copy only', $panel_en['code'] === 'it' && $panel_en['blocks'] === null && $panel_en['default'] === true && is_string($panel_en['independent']));
check('and carries what the dialog needs: the post, the language, the title, the fixed link', str_contains($plus, 'data-wgl-id="' . $lone . '"') && str_contains($plus, 'data-wgl-lang="en"') && str_contains($plus, 'data-wgl-title="Sola"') && str_contains($plus, 'data-wgl-blocks="' . esc_url(Admin\create_url($lone, 'en', 'blocks')) . '"'));
same('Italiano (default)', Admin\menu_name('it', 'Italiano'), 'the admin bar\'s menu says which is the default');
same('English', Admin\menu_name('en', 'English'), 'and nothing after the others');

section('a type without the block editor');

// data referenced from pages: a title, no content editor, so the classic screen
register_post_type('wgt_data', ['label' => 'Data', 'public' => false, 'show_ui' => true, 'show_in_rest' => true, 'supports' => ['title']]);
$data_type = fn ($config) => array_replace($config, ['post_types' => [...$config['post_types'], 'wgt_data']]);
add_filter('wp_go_languages/config', $data_type);
Core\config(true);

$datum = wp_insert_post(['post_type' => 'wgt_data', 'post_title' => 'Dato wgt', 'post_status' => 'publish']);
check('it is edited on the classic screen', Admin\uses_classic_screen(get_post($datum)));
check('a page is not', !Admin\uses_classic_screen(get_post($page)));
check('nor a CF7 form, which has its own screen', !Admin\uses_classic_screen(get_post($form_id)));
check('its translations are independent: no blocks to fix', !Core\can_be_fixed('wgt_data') && Admin\panel_data($datum)['can_blocks'] === false);
$data_item = wp_insert_post(['post_type' => 'wgt_data', 'post_title' => 'Dato due wgt', 'post_status' => 'publish']);
check('asked fixed blocks, a type without them gets an error, not another mode', is_wp_error(WpGoLanguages::create_translation($data_item, 'en', 'blocks')));
same('independent', Core\best_mode('wgt_data', 'blocks'), 'best_mode(): the nearest it has, for a caller acting on several types');

require_once ABSPATH . 'wp-admin/includes/template.php';
$GLOBALS['wp_meta_boxes'] = [];
Admin\classic_box('wgt_data', get_post($datum));
check('the classic screen gets the Language box, in the sidebar, first', isset($GLOBALS['wp_meta_boxes']['wgt_data']['side']['high']['wgl-language']));
$GLOBALS['wp_meta_boxes'] = [];
Admin\classic_box('page', get_post($page));
check('the block editor\'s screen doesn\'t: it has the panel', empty($GLOBALS['wp_meta_boxes']['page']));

wp_dequeue_script('wgl-editor-panel');
wp_deregister_script('wgl-editor-panel');
Admin\panel_script($datum);
$printed = (string) wp_scripts()->get_inline_script_data('wgl-editor-panel', 'before');
check('the box\'s script comes with the post\'s data', wp_script_is('wgl-editor-panel', 'enqueued') && str_contains($printed, '"id":' . $datum) && str_contains($printed, '"can_blocks":false') && str_contains($printed, 'Every field is its own'));
check('and without the block editor\'s scripts', !in_array('wp-editor', wp_scripts()->registered['wgl-editor-panel']->deps, true));

remove_filter('wp_go_languages/config', $data_type);
Core\config(true);

section('ACF fields have no translation setting');

check('nothing draws a switch in a field\'s settings', !has_action('acf/render_field_general_settings', 'WpGo\\Languages\\Admin\\render_translation_setting'));
check('nothing writes WPML\'s number on save', !has_filter('acf/update_field', 'WpGo\\Languages\\Admin\\sync_wpml_preference'));

section('The mode dialog\'s words');

$texts = Admin\editor_texts();
check('into fixed blocks: says content can be deleted', str_contains($texts['confirmBlocks'], 'can be deleted'));
check('into independent: says nothing is', str_contains($texts['confirmIndependent'], 'Nothing is deleted'));
same('Independent', $texts['independentTitle'], 'the freest is Independent');
same('Fixed layout', $texts['blocksToggle'], 'then the fixed layout');
same('Fixed layout', Admin\cf7_mode_texts()['blocksToggle'], 'a form too');
check('a form\'s says what it means there', str_contains(Admin\cf7_mode_texts()['blocksShort'], 'mail settings'));

section('the editor panel\'s writes');

same(true, Admin\rest_update(['mode' => 'text', 'language' => 'en'], get_post($dup)), 'a mode switch is accepted');
same('text', WpGoLanguages::mode($dup), 'the duplicate is fixed now');
same(['acf/rich-text'], array_column(wgt_blocks($dup), 'blockName'), 'and its content was rebuilt on the original\'s');

check('a mode that isn\'t one is refused', is_wp_error(Admin\rest_update(['mode' => 'bogus'], get_post($dup))));
same(true, Admin\rest_update(['mode' => 'text', 'language' => 'it'], get_post($dup_source)), 'a mode sent for an original is ignored');
same(null, WpGoLanguages::mode($dup_source), 'the original still has none');

$error = Admin\rest_update(['language' => 'en'], get_post($page));
same('wgl_has_translations', is_wp_error($error) ? $error->get_error_code() : '', 'a page with translations keeps its language');
same('it', WpGoLanguages::language_of($page), 'it is still Italian');

$error = Admin\rest_update(['language' => 'xx'], get_post($lone));
same('wgl_bad_language', is_wp_error($error) ? $error->get_error_code() : '', 'a language that isn\'t the site\'s is refused');
same(true, Admin\rest_update(['language' => 'it', 'mode' => null], get_post($lone)), 'its own language again is nothing to do');

// a child page moved to English: its parent becomes the parent's translation, its slug checked again
$parent    = wgt_page('Genitore', '', 'it', ['post_name' => 'wgt-genitore']);
$parent_en = WpGoLanguages::create_translation($parent, 'en');
wp_update_post(['ID' => $parent_en, 'post_status' => 'publish', 'post_name' => 'wgt-parent']);
$taken     = wgt_page('Figlio EN', '', 'en', ['post_name' => 'wgt-figlio', 'post_parent' => $parent_en]);
$child     = wgt_page('Figlio', '', 'it', ['post_name' => 'wgt-figlio', 'post_parent' => $parent]);
same('wgt-figlio', get_post_field('post_name', $child), 'the Italian child has the slug an English page has');

$object = get_post($child);
same(true, Admin\rest_update(['language' => 'en'], $object), 'a page alone moves to English');
clean_post_cache($child);
same('en', WpGoLanguages::language_of($child), 'it is English');
same($parent_en, (int) get_post_field('post_parent', $child), 'its parent is the parent\'s English translation');
same('wgt-figlio-2', get_post_field('post_name', $child), 'its slug, taken in English under that parent, gets a number');
same([$parent_en, 'wgt-figlio-2'], [(int) $object->post_parent, $object->post_name], 'the object the REST answer is built from shows both');

$orphan = wgt_page('Orfano', '', 'it', ['post_name' => 'wgt-orfano', 'post_parent' => $lone]);
Admin\rest_update(['language' => 'en'], get_post($orphan));
same($lone, (int) get_post_field('post_parent', $orphan), 'a parent with no translation stays the parent');

section('wp languages link');

$original = wgt_page('Originale CLI', serialize_blocks([wgt_acf('rich-text', ['text' => '<p>Testo</p>']), wgt_acf('accordion', ['title' => ['text' => 'Domande'], 'items' => [['title' => 'D1', 'text' => '<p>R1</p>']]])]), 'it', ['post_name' => 'wgt-cli']);
$other    = wgt_page('Other CLI', serialize_blocks([wgt_para('Mine')]), 'en', ['post_name' => 'wgt-cli-en']);

$run = wgt_admin_cli(fn (Command $cli) => $cli->link([(string) $other, (string) $original], ['mode' => 'text']));
check('link --mode=text succeeds', !$run['error'] && str_contains(implode("\n", $run['lines']), "success: $other is now the en translation of $original (text)."));
same('text', WpGoLanguages::mode($other), 'the page is a fixed translation');
same(['acf/rich-text', 'acf/accordion'], array_column(wgt_blocks($other), 'blockName'), 'its content was rebuilt on the original\'s right away');
same(array_map('wgt_id', wgt_blocks($original)), array_map('wgt_id', wgt_blocks($other)), 'with the original\'s block IDs');

$run = wgt_admin_cli(fn (Command $cli) => $cli->link([(string) $other, (string) $original], []));
same('text', WpGoLanguages::mode($other), 'linked again without --mode, it keeps its mode');

$new_en = wgt_page('New CLI', '', 'en', ['post_name' => 'wgt-cli-new']);
$lone_it = wgt_page('Altro CLI', '', 'it', ['post_name' => 'wgt-cli-altro']);
$run = wgt_admin_cli(fn (Command $cli) => $cli->link([(string) $new_en, (string) $lone_it], []));
same('independent', get_post_meta($new_en, Core\MODE_META, true), 'a new link without --mode is a duplicate');

$run = wgt_admin_cli(fn (Command $cli) => $cli->link([(string) $new_en, (string) $lone_it], ['mode' => 'copy']));
check('a mode that isn\'t one stops with an error', $run['error'] && str_contains(implode("\n", $run['lines']), 'copy is not a mode'));

$third = wgt_page('Third CLI', '', 'en', ['post_name' => 'wgt-cli-third']);
$run = wgt_admin_cli(fn (Command $cli) => $cli->link([(string) $third, (string) $lone_it], []));
check('a second English translation is refused', $run['error'] && str_contains(implode("\n", $run['lines']), 'already has a translation in en'));

section('wp languages check, with the pages served from here');

// each page's head as print_hreflang() prints it, served through pre_http_request (no HTTP)
$served = [];
$serve = function ($pre, $args, $url) use (&$served) {
    $id   = Core\url_to_post_id($url);
    $code = (string) Core\language_of($id);
    $served[] = $id;
    $head = $id ? wgt_as_site($code, function () use ($id) {
        $GLOBALS['wp_query']     = new WP_Query(['page_id' => $id, 'lang' => 'all']);
        $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

        return wgt_output('WpGo\\Languages\\Core\\print_hreflang');
    }) : '';
    $html = '<html lang="' . str_replace('_', '-', (string) (Core\language($code)['locale'] ?? '')) . '"><head>' . $head . '</head></html>';

    return ['headers' => [], 'body' => $html, 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
};
add_filter('pre_http_request', $serve, 10, 3);

// the site's own pages in a language this test leaves out (it and en only): out of the way
foreach (get_posts(['post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'lang' => 'all', 'fields' => 'ids']) as $page_id) {
    if (!in_array(Core\language_of((int) $page_id), Core\codes(), true)) {
        $GLOBALS['wpdb']->update($GLOBALS['wpdb']->posts, ['post_status' => 'draft'], ['ID' => $page_id]);
        clean_post_cache((int) $page_id);
    }
}

// a page alone, and one whose translation has a password: no hreflang expected on either
$protected_it = wgt_page('Protetta', '', 'it', ['post_name' => 'wgt-protetta']);
$protected_en = WpGoLanguages::create_translation($protected_it, 'en');
wp_update_post(['ID' => $protected_en, 'post_status' => 'publish', 'post_password' => 'x']);

$run = wgt_admin_cli(fn (Command $cli) => $cli->check([], ['post_type' => 'page']));
$log = implode("\n", $run['lines']);
check('check finds nothing wrong when each head is what print_hreflang prints', !$run['error'] && !str_contains($log, 'warning:'), $log);
check('it checked the page alone', in_array($lone, $served, true) && str_contains($log, "ok $lone [it]"));
check('and the page whose translation has a password', str_contains($log, "ok $protected_it [it]"));

// a head missing its links: reported
remove_filter('pre_http_request', $serve, 10);
$bare = fn ($pre, $args, $url) => ['headers' => [], 'body' => '<html lang="it-IT"><head></head></html>', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
add_filter('pre_http_request', $bare, 10, 3);
$run = wgt_admin_cli(fn (Command $cli) => $cli->check([], ['post_type' => 'page']));
$log = implode("\n", $run['lines']);
check('a translated page with no hreflang in its head is reported', $run['error'] && str_contains($log, "warning: $parent [it]") && str_contains($log, 'no hreflang en-GB'));
check('an English page served with the Italian lang is reported', str_contains($log, 'no <html lang="en-GB">'));
remove_filter('pre_http_request', $bare, 10);

section('the lists\' language, remembered from the admin bar');

$state = [State::$context, State::$front, State::$request, State::$filter, $GLOBALS['pagenow'] ?? null];
$admin_lang = fn () => get_user_meta($admin, Core\ADMIN_FILTER_META, true);
$resolve = function (string $screen, ?string $lang, string $method = 'GET', ?int $post = null) {
    State::$context = 'admin';
    State::$front   = false;
    State::$request = null;
    $GLOBALS['pagenow']        = $screen;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_REQUEST = $lang !== null ? ['lang' => $lang] : [];
    $_GET     = $post !== null ? ['post' => $post] : [];
    Core\resolve_admin();
};

delete_user_meta($admin, Core\ADMIN_FILTER_META);
$resolve('edit.php', null);
same(['it', 'it'], [State::$request, State::$filter], 'with nothing picked, the lists are in the default language');

$resolve('edit.php', 'en');
same('en', $admin_lang(), 'the admin bar\'s switch (a GET of a list) is remembered');
same(['en', 'en'], [State::$request, State::$filter], 'and applies');

$resolve('post-new.php', 'it');
same('en', $admin_lang(), 'a new post\'s lang is not remembered');
same('it', State::$request, 'though the new post is in it');

$resolve('admin-post.php', 'it');
same('en', $admin_lang(), 'an admin-post action\'s lang is not remembered');

$resolve('edit.php', 'it', 'POST');
same('en', $admin_lang(), 'nor is a POST\'s');

$resolve('post.php', null, 'GET', $fixed);
same(['en', 'en'], [State::$request, State::$filter], 'editing a post: its language');

$resolve('edit.php', 'all');
same('all', $admin_lang(), 'All languages is remembered too');
same(null, State::$filter, 'and lists every language');
$resolve('edit.php', null);
same([null, 'it'], [State::$filter, State::$request], 'remembered, All languages stays on the next screen');

[State::$context, State::$front, State::$request, State::$filter, $GLOBALS['pagenow']] = $state;
$_REQUEST = [];
$_GET     = [];

section('the Languages screen\'s save');

// the theme hides the categories' UI: a taxonomy of the test's to offer
register_taxonomy('wgt_topic', 'page', ['show_ui' => true, 'label' => 'Topics']);

// a type and a taxonomy translated before, their plugin off now
$stored = array_replace((array) $stored_config, [
    'post_types' => ['page', 'wpcf7_contact_form', 'wgt_gone_type'],
    'taxonomies' => ['wgt_gone_tax'],
]);
update_option(Core\OPTION, $stored);
Core\config(true);

$languages = [
    ['code' => 'it', 'locale' => 'it_IT', 'name' => 'Italiano', 'slug' => 'it'],
    ['code' => 'en', 'locale' => 'en_GB', 'name' => 'English', 'slug' => 'wp-json'],
    ['code' => 'de', 'locale' => 'de_DE', 'name' => 'Deutsch', 'slug' => 'de'],
    ['code' => 'fr', 'locale' => 'fr_FR', 'name' => 'Français', 'slug' => 'de'],
    ['code' => '', 'locale' => '', 'name' => '', 'slug' => ''],
];
$domains = [
    ['slug' => 'general', 'name' => wp_go_site_texts_general_name(), 'roles' => ['editor']],
    ['slug' => 'Shop', 'name' => ' Shop <b>x</b> ', 'roles' => ['editor', 'administrator', 'no_such_role']],
    ['slug' => 'shop', 'name' => 'Again', 'roles' => []],
    ['slug' => '', 'name' => 'Empty', 'roles' => ['editor']],
];

$stop = wgt_admin_save([
    'languages'  => $languages,
    'post_types' => ['page', 'not_offered_type'],
    'taxonomies' => ['wgt_topic'],
    'site_texts' => ['domains' => $domains, 'protected' => ' nuxt, app '],
]);
$saved = (array) get_option(Core\OPTION);

check('the save goes back to the screen, saved', $stop['kind'] === 'redirect' && str_contains($stop['target'], 'page=wgl-languages') && str_contains($stop['target'], 'saved=1'));
same(['page', 'wgt_gone_type'], $saved['post_types'] ?? null, 'a type not registered now stays translated; one not offered isn\'t added');
same(['wgt_topic', 'wgt_gone_tax'], $saved['taxonomies'] ?? null, 'the same for a taxonomy');
same(['it', 'en', 'de', 'fr'], array_column($saved['languages'] ?? [], 'code'), 'the four languages are saved, the empty row dropped');
same('en', $saved['languages'][1]['slug'] ?? null, 'a reserved slug (wp-json) becomes the code');
same('fr', $saved['languages'][3]['slug'] ?? null, 'a slug another language has becomes the code');
same(['general' => ['name' => '', 'roles' => ['editor']], 'shop' => ['name' => 'Shop x', 'roles' => ['editor']]], $saved['site_texts']['domains'] ?? null, 'the domains: General\'s label unsaved, the first of a slug kept, only real roles, no administrator');
same('nuxt, app', $saved['site_texts']['protected'] ?? null, 'the protected prefixes are saved trimmed');
same('edit_pages', $saved['site_texts']['capability'] ?? null, 'a site_texts key the screen doesn\'t post stays');
same(4, count(Core\languages()), 'the settings are read again after the save');

// every code emptied: the languages stay
update_option(Core\OPTION, $stored);
Core\config(true);
wgt_admin_save(['languages' => [['code' => '', 'slug' => ''], ['code' => '  ', 'slug' => 'x']], 'post_types' => ['page']]);
same(array_column($stored['languages'], 'code'), array_column(((array) get_option(Core\OPTION))['languages'] ?? [], 'code'), 'every code emptied: the languages stay as they were');

// another default, picked by its radio: its items become the originals
update_option(Core\OPTION, $stored);
Core\config(true);
$group_it = wgt_page('Predefinita wgt', '', 'it', ['post_name' => 'wgt-predefinita']);
$group_en = WpGoLanguages::create_translation($group_it, 'en', 'text');
$rows     = array_combine([3, 7], array_slice($stored['languages'], 0, 2));
$changed  = [];
$listen   = function ($code, $previous) use (&$changed) {
    $changed[] = [$code, $previous];
};
add_action('wp_go_languages/default_changed', $listen, 10, 2);
wgt_admin_save(['languages' => $rows, 'default' => '7', 'post_types' => ['page']]);
$saved = (array) get_option(Core\OPTION);
same(['en', 'it'], array_column($saved['languages'] ?? [], 'code'), 'the row the radio names is saved first: the default');
check('no language is kept apart as stored bare', !array_key_exists('base_language', $saved));
same('en', get_option(Core\STORED_DEFAULT_OPTION), 'the options and menus went with the default');
same([['en', 'it']], $changed, 'default_changed is heard once, with the new and the old');
same($group_en, Core\original($group_it), 'the English page is its group\'s original now');
same('independent', Core\mode_of($group_it), 'the Italian one a duplicate');

wgt_admin_save(['languages' => $rows, 'default' => '7', 'post_types' => ['page'], 'default_in_url' => '1']);
same(true, ((array) get_option(Core\OPTION))['default_in_url'] ?? null, 'the default language in the URL is saved');
wgt_admin_save(['languages' => $rows, 'default' => '7', 'post_types' => ['page']]);
same(false, ((array) get_option(Core\OPTION))['default_in_url'] ?? null, 'and off, unchecked');
same([['en', 'it']], $changed, 'the default the same: nothing heard again');
remove_action('wp_go_languages/default_changed', $listen, 10);

same(['general' => ['name' => '', 'roles' => []]], Admin\posted_site_text_domains([]), 'no domain posted: General alone');

// what the code sets (a wp_go_languages/config filter) is shown, not saved
update_option(Core\OPTION, $stored);
Core\config(true);
$code = fn ($config) => array_replace($config, ['post_types' => ['page']]);
add_filter('wp_go_languages/config', $code);
Core\config(true);
same(['post_types'], Admin\code_set_keys(), 'a filter setting the post types: the screen knows the code sets them');
same($stored['post_types'], Admin\unfiltered_config()['post_types'], 'the settings before the filter are the stored ones');
wgt_admin_save(['languages' => $stored['languages'], 'post_types' => ['page', 'wpcf7_contact_form'], 'taxonomies' => ['wgt_topic']]);
$saved = (array) get_option(Core\OPTION);
same($stored['post_types'], $saved['post_types'] ?? null, 'the post types the code sets are left as stored');
same(['wgt_topic', 'wgt_gone_tax'], $saved['taxonomies'] ?? null, 'the rest is saved');
remove_filter('wp_go_languages/config', $code);

$subscriber_save = function () use ($subscriber, $admin) {
    wp_set_current_user($subscriber);

    try {
        return wgt_admin_save(['post_types' => ['page']]);
    } finally {
        wp_set_current_user($admin);
    }
};
$stop = $subscriber_save();
check('a user without manage_options is refused with a 403', $stop['kind'] === 'die' && $stop['status'] === 403);

update_option(Core\OPTION, $stored_config);
Core\config(true);
