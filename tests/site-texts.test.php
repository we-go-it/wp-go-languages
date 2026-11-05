<?php

/**
 * Site texts: a key's text per language and its fallback, what a key may be,
 * the screen's save (values, domains, removals) and who may write what, the
 * code scan on a folder of its own, unused and protected keys, and the REST
 * route's nesting. The texts, the domains and the scan's record are the
 * test's own, written over the site's (the rollback puts them back).
 */

use WpGo\Languages\Core;

require_once __DIR__ . '/fixtures/content.php';

/** The site texts' settings on the Languages screen, as code reads them. */
function wgst_settings(array $site_texts): void
{
    $stored = (array) get_option(Core\OPTION, []);
    $stored['site_texts'] = $site_texts;
    update_option(Core\OPTION, $stored);
    Core\config(true);
}

/** Run a handler of the screen's forms, as a POST with its nonce: where it went back to, its query read. */
function wgst_post(string $action, string $handler, array $post): array
{
    $_POST    = wp_slash($post);
    $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce($action)];

    $stop = wgt_stopped($handler);
    parse_str((string) parse_url($stop['target'], PHP_URL_QUERY), $query);

    $_POST = $_REQUEST = [];

    return $stop + ['query' => $query];
}

$admin = get_current_user_id();

// The scan reads this folder only: set up before anything scans, as the
// scan's result is kept for the request.
$code = wgt_temp_dir('site-texts');
file_put_contents("$code/calls.php", <<<'PHP'
<?php
echo t('a.b');
echo 'x'.base_text('c.d');
echo \base_text('e.f');
echo $obj->t('no.method');
echo Foo::t('no.static');
echo $t('no.variable');
echo my_t('no.prefixed');
// t('no.comment')
/* base_text('no.block_comment') */
function t($key) {}
t();
t('Not A Key');
t($dynamic);
t('i.j', 'shop');
t('p.q', 'shop'); t('p.q', 'mail');
t('k.l', 'Shop');
t('m.n', $domain);
t('r.s',);
Ns\t('no.namespaced');
PHP);
file_put_contents("$code/view.twig", <<<'TWIG'
{{ t('g.h') }}
{{ foo.t('no.twig_method') }}
{# t('no.twig_comment') #}
{{ base_text('u.v', 'mail') }}
TWIG);
wp_mkdir_p("$code/node_modules");
file_put_contents("$code/node_modules/skipped.php", "<?php t('no.skipped');");
file_put_contents("$code/script.js", "t('no.js');");

add_filter('wp_go_site_texts_scan', fn () => ['paths' => [$code], 'functions' => ['t', 'base_text']], PHP_INT_MAX);

section('A key\'s text');

wp_go_site_texts_store([
    'a.b'     => ['it' => 'Ciao', 'en' => 'Hello'],
    'only.it' => ['it' => 'Solo italiano'],
]);

same('Ciao', wp_go_site_text('a.b'), 'a key prints the current language\'s text');
same('Hello', WpGoLanguages::in('en', fn () => wp_go_site_text('a.b')), 'switched to English, it prints the English text');
same('Hello', wp_go_site_text('a.b', 'en'), 'a language asked for prints that language\'s text');
same('only.it', wp_go_site_text('only.it', 'en'), 'a key with no text in the language prints itself');
same('missing.key', wp_go_site_text('missing.key'), 'a key not in the list prints itself');

$seen = [];
$filter = function ($value, $key, $language) use (&$seen) {
    $seen[] = [$value, $key, $language];

    return $value;
};
add_filter('wp_go_site_text', $filter, 10, 3);
WpGoLanguages::in('en', fn () => wp_go_site_text('a.b'));
remove_filter('wp_go_site_text', $filter, 10);
same([['Hello', 'a.b', 'en']], $seen, 'the wp_go_site_text filter is given the value, the key and the language');

same(['it' => 'Italiano', 'en' => 'English'], wp_go_site_texts_languages(), 'the screen\'s languages are the site\'s, the default first');
same('footer', wp_go_site_texts_group_of('footer.contact_us'), 'a key\'s group is its first part');
same('Contact page', wp_go_site_texts_group('contact_page'), 'a group\'s heading is its name in words');

section('What a key may be');

$list = ['a.b' => [], 'x.y.z' => []];
same('invalid', wp_go_site_texts_key_error('Not A Key', $list), 'a key with capitals and spaces is invalid');
same('invalid', wp_go_site_texts_key_error('single', $list), 'a key of one part is invalid');
same('exists', wp_go_site_texts_key_error('a.b', $list), 'a key already listed exists');
same('nested', wp_go_site_texts_key_error('a.b.c', $list), 'a key starting with a listed key is nested');
same('nested', wp_go_site_texts_key_error('x.y', $list), 'a key another listed key starts with is nested');
same(null, wp_go_site_texts_key_error('a.bc', $list), 'a key sharing only the letters of another\'s start is fine');

section('Scan code');

$scan = wp_go_site_texts_scan();
$where = fn (string $file, int $line) => "$code/$file:$line";

same(['a.b', 'c.d', 'e.f', 'g.h', 'i.j', 'k.l', 'm.n', 'p.q', 'r.s', 'u.v'], array_keys($scan['keys']), 'the scan finds the keys of every plain call, PHP and Twig, sorted');
same([$where('calls.php', 2)], $scan['keys']['a.b'], 'a key is found with its file and line');
check('a call after a PHP concatenation counts (\'x\'.base_text(\'c.d\'))', isset($scan['keys']['c.d']));
check('the global function written with its backslash counts (\\base_text(\'e.f\'))', isset($scan['keys']['e.f']));
same([], array_values(array_filter(array_keys($scan['keys']), fn ($key) => str_starts_with($key, 'no.'))), 'methods, variables, longer names, namespaced names, comments, Twig\'s .t(, skipped folders and other file types count for nothing');
same(['Not A Key' => [$where('calls.php', 13)]], $scan['invalid'], 'a plain string that isn\'t a key is reported as invalid');
same([$where('calls.php', 14)], $scan['dynamic'], 'a call with a variable is reported once; the definition and the empty call are not');
$domains = $scan['domains'];
ksort($domains); // in the order the files are read, which the folder decides
same(['i.j' => 'shop', 'p.q' => 'shop', 'u.v' => 'mail'], $domains, 'a plain lowercase second argument is the key\'s domain, the first one found counting');
same(['p.q' => ['shop', 'mail']], $scan['clashes'], 'a key given two domains is a clash, with both, the first first');
same([$where('calls.php', 17), $where('calls.php', 18)], $scan['unread_domains'], 'a second argument in capitals or a variable is reported as an unread domain; a trailing comma is not');

$used = wp_go_site_texts_used();
check('the scan records where each key is printed', $used['keys'] === $scan['keys'] && $used['scanned'] > 0);
same($scan['domains'], $used['domains'], 'the scan records the domains the code gives');
check('a domain from the code is known as such', wp_go_site_texts_domain_from_code('i.j') && !wp_go_site_texts_domain_from_code('a.b'));

section('Unused and protected keys');

wp_go_site_texts_store([
    'a.b'             => ['it' => 'Ciao', 'en' => 'Hello'],
    'old.key'         => ['it' => 'Vecchio'],
    'nuxt.cart.title' => ['it' => 'Carrello'],
    'app.menu'        => ['it' => 'Menu'],
    'u.v.w'           => [],
]);
wgst_settings(['domains' => ['general' => ['name' => '', 'roles' => []]], 'protected' => ' nuxt , ']);
$protect = fn ($prefixes) => [...$prefixes, 'app.'];
add_filter('wp_go_site_texts_protected', $protect);

same(['nuxt', 'app'], wp_go_site_texts_protected_prefixes(), 'protected prefixes come from the setting and the filter, trimmed of spaces and dots');
check('a key under a protected prefix is protected, one sharing only its letters is not', wp_go_site_texts_is_protected('nuxt.cart.title') && !wp_go_site_texts_is_protected('nuxtx.a'));
same(['old.key', 'u.v.w'], wp_go_site_texts_unused(), 'the unused keys are those the code doesn\'t print, protected ones aside');
remove_filter('wp_go_site_texts_protected', $protect);

$found = wp_go_site_texts_add_found();
same(['u.v' => 'nested'], $found['skipped'], 'a key the code prints that another listed key starts with is skipped, with why');
same(['c.d', 'e.f', 'g.h', 'i.j', 'k.l', 'm.n', 'p.q', 'r.s'], $found['added'], 'the other keys the code prints are added');
same([], wp_go_site_texts()['g.h'], 'a key added from the code has no values yet');

section('Domains');

// Before the Languages screen has domains: General is whoever had the old capability
wgst_settings(['capability' => 'edit_pages']);
check('before domains, General is edited by the roles with edit_pages', in_array('editor', wp_go_site_texts_domains()['general']['roles'], true) && !in_array('administrator', wp_go_site_texts_domains()['general']['roles'], true));
wgst_settings(['capability' => 'manage_options']);
same([], wp_go_site_texts_domains()['general']['roles'], 'before domains, with manage_options, General is for administrators only');

wgst_settings(['domains' => [
    'general' => ['name' => '', 'roles' => []],
    'shop'    => ['name' => 'Shop', 'roles' => ['editor']],
    'mail'    => ['name' => '', 'roles' => []],
]]);
same(['general', 'shop', 'mail'], array_keys(wp_go_site_texts_domains()), 'the domains are General first, then those set up');
same('mail', wp_go_site_texts_domains()['mail']['name'], 'a domain with no name is named by its slug');
same('shop', wp_go_site_texts_domain_of('i.j'), 'a key the code gives a domain is in it');
same('general', wp_go_site_texts_domain_of('a.b'), 'a key no one gave a domain is in General');

wp_go_site_texts_set_domains(['old.key' => 'mail', 'ghost.key' => 'shop']);
same(['old.key' => 'mail'], wp_go_site_texts_given_domains(), 'an administrator\'s domain is kept for a listed key only');

// The code's domain wins over an administrator's, which stays hidden
wp_go_site_texts_set_domains(['i.j' => 'mail']);
same('shop', wp_go_site_texts_domain_of('i.j'), 'the code\'s domain wins over an administrator\'s');
wp_go_site_texts_set_domains(['i.j' => '']);

section('Moving keys to another domain');

$moved = wp_go_site_texts_save_posted_domains([
    'a.b'       => 'mail',       // general to mail: moved
    'old.key'   => 'general',    // shown general on load: the screen is stale, not a choice
    'i.j'       => 'mail',       // the code gives it shop
    'r.s'       => 'nowhere',    // no such domain
    'ghost.key' => 'shop',       // not listed
    'k.l'       => ['shop'],     // not a string
    'm.n'       => 'general',    // already there
], [
    'a.b'     => 'general',
    'old.key' => 'general',
]);
same(1, $moved, 'only a choice changed on the screen, to a domain there is, of a key the code leaves free, moves');
same('mail', wp_go_site_texts_domain_of('a.b'), 'the key moved is in its new domain');
same('mail', wp_go_site_texts_domain_of('old.key'), 'a key whose choice still shows what it showed on load stays where another administrator put it');
same('shop', wp_go_site_texts_domain_of('i.j'), 'a key the code gives a domain doesn\'t move');

same(1, wp_go_site_texts_save_posted_domains(['a.b' => 'general'], ['a.b' => 'mail']), 'a move back to General counts');
check('a key moved to General has no domain stored', !isset(wp_go_site_texts_given_domains()['a.b']));

section('Saving values');

$saved = [];
$on_saved = function ($changed) use (&$saved) {
    $saved[] = $changed;
};
add_action('wp_go_site_texts_saved', $on_saved);

$changed = wp_go_site_texts_save_posted([
    'a.b'       => ['it' => 'Ciao', 'en' => 'Hi there', 'de' => 'Hallo'],
    'g.h'       => ['it' => "  Riga uno\r\nRiga due  ", 'en' => 'Line'],
    'ghost.key' => ['it' => 'Fantasma'],
    'k.l'       => 'not an array',
]);
same(['a.b' => ['en'], 'g.h' => ['it', 'en']], $changed, 'only changed values in the site\'s languages are written, keys not listed left out');
same(['it' => 'Ciao', 'en' => 'Hi there'], wp_go_site_texts()['a.b'], 'a value in a language not the site\'s is not stored');
same("Riga uno\nRiga due", wp_go_site_texts()['g.h']['it'], 'a value is trimmed, its line ends made \\n');
check('a key not in the list is not added by a save', !isset(wp_go_site_texts()['ghost.key']));
same([$changed], $saved, 'wp_go_site_texts_saved fires once, with each key\'s languages saved');

same(['a.b' => ['en']], wp_go_site_texts_save_posted(['a.b' => ['en' => '']]), 'an emptied field counts as a change');
same(['it' => 'Ciao'], wp_go_site_texts()['a.b'], 'an emptied field removes that language\'s value');

$saved = [];
same([], wp_go_site_texts_save_posted(['a.b' => ['it' => 'Ciao']]), 'a value as it was is no change');
same([], $saved, 'nothing saved, nothing fired');
remove_action('wp_go_site_texts_saved', $on_saved);

section('Who edits what');

$editor = wp_insert_user(['user_login' => 'wgst_editor', 'user_pass' => wp_generate_password(), 'user_email' => 'wgst_editor@example.test', 'role' => 'editor']);
$author = wp_insert_user(['user_login' => 'wgst_author', 'user_pass' => wp_generate_password(), 'user_email' => 'wgst_author@example.test', 'role' => 'author']);

same(['shop'], wp_go_site_texts_user_domains($editor), 'a user edits the domains that list one of their roles');
same(['general', 'shop', 'mail'], wp_go_site_texts_user_domains($admin), 'an administrator edits every domain');
same([], wp_go_site_texts_user_domains(999999), 'a user that doesn\'t exist edits none');
check('a role in a domain is granted the screen\'s capability', user_can($editor, wp_go_site_texts_capability()));
check('a role in no domain is not', !user_can($author, wp_go_site_texts_capability()));
check('an administrator is', user_can($admin, wp_go_site_texts_capability()));

wp_set_current_user($editor);
check('an editor can edit a key of their domain, not one of General', wp_go_site_texts_can_edit('i.j') && !wp_go_site_texts_can_edit('a.b'));
same(['i.j' => ['it']], wp_go_site_texts_save_posted(['i.j' => ['it' => 'Paga'], 'a.b' => ['it' => 'Rubato']]), 'a save by an editor writes only the keys of their domains');
same('Ciao', wp_go_site_texts()['a.b']['it'], 'a key outside the editor\'s domains is left as it was');

wp_set_current_user($author);
same(['kind' => 'die', 'status' => 403], array_intersect_key(wgst_post('wp_go_site_texts_save', 'wp_go_site_texts_save_action', ['texts' => []]), ['kind' => 1, 'status' => 1]), 'the save form refuses a user with no domain');
wp_set_current_user($admin);

section('Removing keys');

wp_go_site_texts_set_domains(['old.key' => 'mail', 'r.s' => 'shop']);
$removed = [];
$on_removed = function ($key) use (&$removed) {
    $removed[] = $key;
};
add_action('wp_go_site_texts_removed', $on_removed);
wp_go_site_texts_remove('old.key', 'never.there');
remove_action('wp_go_site_texts_removed', $on_removed);

same(['old.key'], $removed, 'wp_go_site_texts_removed fires for each key that was there');
check('a removed key is gone from the list', !isset(wp_go_site_texts()['old.key']));
same(['r.s' => 'shop'], wp_go_site_texts_given_domains(), 'a removed key\'s domain goes with it');

section('The save form');

same('', wp_go_site_texts_param(['s' => ['x']], 's'), 'a parameter that isn\'t a plain value reads as empty');
same('', wp_go_site_texts_param([], 's'), 'a missing parameter reads as empty');
same("it's", wp_go_site_texts_param(['s' => wp_slash("it's")], 's'), 'a parameter is unslashed');

$done = wgst_post('wp_go_site_texts_save', 'wp_go_site_texts_save_action', ['texts' => ['a.b' => ['en' => 'Hello again']]]);
same(['saved' => '1'], array_intersect_key($done['query'], array_flip(WP_GO_SITE_TEXTS_NOTICES)), 'a save goes back saying how many values it saved');

$done = wgst_post('wp_go_site_texts_save', 'wp_go_site_texts_save_action', ['texts' => [], 'remove' => 'm.n']);
same(['removed' => '1'], array_intersect_key($done['query'], array_flip(WP_GO_SITE_TEXTS_NOTICES)), 'a removal alone says only that, not also saved=0');
check('the row\'s remove button removed its key', !isset(wp_go_site_texts()['m.n']));

$done = wgst_post('wp_go_site_texts_save', 'wp_go_site_texts_save_action', ['texts' => [], 'domains' => ['k.l' => 'mail'], 'domains_was' => ['k.l' => 'general']]);
same(['moved' => '1'], array_intersect_key($done['query'], array_flip(WP_GO_SITE_TEXTS_NOTICES)), 'a move alone says only that');

// "Remove selected": only the ticked keys the code really doesn't print
$done = wgst_post('wp_go_site_texts_save', 'wp_go_site_texts_save_action', ['texts' => [], 'remove_selected' => '1', 'unused' => ['u.v.w', 'a.b']]);
check('Remove selected removes a ticked unused key, not a ticked key the code prints', !isset(wp_go_site_texts()['u.v.w']) && isset(wp_go_site_texts()['a.b']));

$done = wgst_post('wp_go_site_texts_save', 'wp_go_site_texts_save_action', ['texts' => []]);
same(['saved' => '0'], array_intersect_key($done['query'], array_flip(WP_GO_SITE_TEXTS_NOTICES)), 'nothing changed says saved=0');

$done = wgst_post('wp_go_site_texts_add', 'wp_go_site_texts_add_action', ['key' => 'New.Key', 'domain' => 'shop']);
check('Add a key takes the key in lowercase, in the domain chosen', isset(wp_go_site_texts()['new.key']) && wp_go_site_texts_domain_of('new.key') === 'shop' && ($done['query']['added'] ?? '') === '1');
$done = wgst_post('wp_go_site_texts_add', 'wp_go_site_texts_add_action', ['key' => 'new.key.short']);
same('nested', $done['query']['added'] ?? null, 'Add a key refuses a nested key, saying why');

$_GET = ['moved' => '1', 'removed' => '3'];
$notices = wgt_output('wp_go_site_texts_render_notices');
$_GET = [];
check('the notices count in words: one for 1, the number otherwise', str_contains($notices, '1 key moved to another domain.') && str_contains($notices, '3 keys removed.'));

section('The table: the language picked in the admin bar');

wp_go_site_texts_store(['zt.one' => ['it' => 'Uno', 'en' => 'One'], 'zt.two' => ['it' => 'Due'], 'zt.three' => ['en' => 'Three alone']]);

$in = fn (string $code, callable $render) => wgt_output(fn () => WpGo\Languages\Core\in_language($code, $render));
$read = function (string $html) {
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);

    return new DOMXPath($dom);
};
$attrs = fn (DOMXPath $xpath, string $query, string $attr) => array_map(fn ($node) => $node->getAttribute($attr), iterator_to_array($xpath->query($query)));
$site = fn (string $code) => $in($code, fn () => wp_go_site_texts_render_table(wp_go_site_texts_view()));

$_GET = ['page' => WP_GO_SITE_TEXTS_PAGE, 's' => 'zt.'];
$xpath = $read($site('en'));

same(['key', 'lang-en'], $attrs($xpath, '//thead//th[@data-column]', 'data-column'), 'a column for the key, and one for the language');
same(['texts[zt.one][en]', 'texts[zt.three][en]', 'texts[zt.two][en]'], $attrs($xpath, '//input[contains(@name, "texts[")]', 'name'), 'a field per key, in that language alone');
same(0, $xpath->query('//input[@disabled] | //textarea[@disabled]')->length, 'every one can be typed in');
same('One', $xpath->query('//input[@name="texts[zt.one][en]"]')->item(0)?->getAttribute('value'), 'its value');
same('Uno', $xpath->query('//tr[@id="zt.one"]//td[@data-source]')->item(0)?->getAttribute('data-source'), 'what a translation starts from');
same('Italiano: Uno', trim((string) $xpath->query('//tr[@id="zt.one"]//p[contains(@class, "wg-site-texts__original")]')->item(0)?->textContent), 'and the default language\'s text under it');
same(0, $xpath->query('//tr[@id="zt.three"]//p[contains(@class, "wg-site-texts__original")]')->length, 'nothing there, when it has none');
same('2', $xpath->query('//tr[contains(@class, "wg-site-texts__group")]/th')->item(0)?->getAttribute('colspan'), 'a group\'s heading spans the two');
same(3, $xpath->query('//td[contains(@class, "wg-site-texts__key")]//button[contains(@class, "wg-site-texts__remove")]')->length, 'each key\'s trash button is in its cell');
check('the details still have every language', str_contains((string) $xpath->query('//tr[@id="zt.one"]')->item(0)?->getAttribute('data-details'), 'Uno') && str_contains((string) $xpath->query('//tr[@id="zt.one"]')->item(0)?->getAttribute('data-details'), 'One'));

$xpath = $read($site('it'));
same(['texts[zt.one][it]', 'texts[zt.three][it]', 'texts[zt.two][it]'], $attrs($xpath, '//input[contains(@name, "texts[")]', 'name'), 'another language picked: its fields');
same(0, $xpath->query('//p[contains(@class, "wg-site-texts__original")] | //td[@data-source] | //button[contains(@class, "wg-site-texts__suggest")]')->length, 'in the default language there\'s nothing to write it from');

$_GET = ['page' => WP_GO_SITE_TEXTS_PAGE, 's' => 'zt.', 'missing' => '1'];
same(['texts[zt.two][en]'], $attrs($read($site('en')), '//input[contains(@name, "texts[")]', 'name'), '"Only empty" goes by that language');
same(['texts[zt.three][it]'], $attrs($read($site('it')), '//input[contains(@name, "texts[")]', 'name'), 'in each');

$_GET = ['page' => WP_GO_SITE_TEXTS_PAGE, 's' => 'Due'];
same(['texts[zt.two][en]'], $attrs($read($site('en')), '//input[contains(@name, "texts[")]', 'name'), 'a search finds the text under the field');
$_GET = [];

update_option(WP_GO_ADMIN_STRINGS_OPTION, ['scanned' => 1, 'strings' => [wp_go_admin_strings_id('wgt-cols', 'a.php', 'Save') => ['domain' => 'wgt-cols', 'context' => 'a.php', 'text' => 'Save']], 'warnings' => []]);
$save_id = wp_go_admin_strings_id('wgt-cols', 'a.php', 'Save');
wp_go_admin_strings_store(['it_IT' => ['wgt-cols' => ["a.php\x04Save" => 'Salva']]]);
$xpath = $read($in('it', fn () => wp_go_admin_strings_render_table(wp_go_admin_strings_view())));

same(['lang-it'], $attrs($xpath, '//thead//th[@data-column]', 'data-column'), 'on the Admin strings tab, the language alone');
same(["strings[it_IT][$save_id]"], $attrs($xpath, '//input[contains(@name, "strings[")]', 'name'), 'a field in its locale');
same('Salva', $xpath->query('//input[contains(@name, "strings[")]')->item(0)?->getAttribute('value'), 'its translation');
same('English: Save', trim((string) $xpath->query('//p[contains(@class, "wg-site-texts__original")]')->item(0)?->textContent), 'the English under it');
same('it', $xpath->query('//td[@data-code]')->item(0)?->getAttribute('data-code'), 'and its language\'s code beside its locale, for AI');

$xpath = $read($in('en', fn () => wp_go_admin_strings_render_table(wp_go_admin_strings_view())));
same(["strings[en_GB][$save_id]"], $attrs($xpath, '//input[contains(@name, "strings[")]', 'name'), 'another language picked: its locale');
same('', $xpath->query('//input[contains(@name, "strings[")]')->item(0)?->getAttribute('value'), 'empty there');
same('Save', $xpath->query('//input[contains(@name, "strings[")]')->item(0)?->getAttribute('placeholder'), 'the English as its placeholder');

$filter = WpGo\Languages\Core\State::$filter;
WpGo\Languages\Core\State::$filter = null;
check('with All languages picked, a note says which one this is', str_contains(wgt_output('wp_go_site_texts_render_language_note'), 'Italiano'));
WpGo\Languages\Core\State::$filter = 'en';
same('', wgt_output('wp_go_site_texts_render_language_note'), 'with one picked, none');
WpGo\Languages\Core\State::$filter = $filter;

section('The compiled files');

wp_go_site_texts_store(['zc.one' => ['it' => 'Uno', 'en' => 'One'], 'zc.two' => ['it' => 'Due']]);
$version = wp_go_site_texts_version();
$folder = wp_go_site_texts_cache_dir() . "/$version";
same(wp_go_site_texts_hash(wp_go_site_texts()), $version, 'a save keeps the texts\' version: their hash');
same('Uno', wp_go_site_text('zc.one', 'it'), 'a text is printed from its language\'s file');
same(['zc.one' => 'Uno', 'zc.two' => 'Due'], include "$folder/it.php", 'the file has the language\'s keys with a value');
same('One', wp_go_site_text('zc.one', 'en'), 'another language\'s from its own');
same(['zc.one' => 'One'], include "$folder/en.php", 'with only its keys with a value');
same('off', (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM $wpdb->options WHERE option_name = %s", WP_GO_SITE_TEXTS_OPTION)), 'the option isn\'t autoloaded');
same('on', (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM $wpdb->options WHERE option_name = %s", WP_GO_SITE_TEXTS_VERSION)), 'the version is');

// written by other means than the screen (wp-cli, a seed): a new version all the same
update_option(WP_GO_SITE_TEXTS_OPTION, ['zc.one' => ['it' => 'Uno!', 'en' => 'One']], false);
same('Uno!', wp_go_site_text('zc.one', 'it'), 'the option written, the new text prints in the same request');
check('in a new version\'s folder, the old one removed', wp_go_site_texts_version() !== $version && !is_dir($folder) && is_file(wp_go_site_texts_cache_dir() . '/' . wp_go_site_texts_version() . '/it.php'));

// written where no hook sees it (SQL): the version kept names no file, the
// first read writes the option's, and puts the version right
$version = wp_go_site_texts_version();
update_option(WP_GO_SITE_TEXTS_VERSION, 'aaaaaaaaaaaa', true);
same('Uno!', wp_go_site_text('zc.one', 'it'), 'a version naming no file: the text read from the option');
same($version, get_option(WP_GO_SITE_TEXTS_VERSION), 'and the version put right');

// no version kept yet (the first request after an update): worked out, the option no longer autoloaded
delete_option(WP_GO_SITE_TEXTS_VERSION);
wp_set_option_autoload(WP_GO_SITE_TEXTS_OPTION, true);
same($version, wp_go_site_texts_version(), 'no version kept: worked out from the option');
same('off', (string) $wpdb->get_var($wpdb->prepare("SELECT autoload FROM $wpdb->options WHERE option_name = %s", WP_GO_SITE_TEXTS_OPTION)), 'and the option stops being autoloaded');

// a file that isn't one (cut short, written by hand): written again
file_put_contents(wp_go_site_texts_cache_dir() . "/$version/en.php", '<?php return 1;');
same('One', wp_go_site_text('zc.one', 'en'), 'a file that isn\'t an array is written again');

// no folder: every text read from the option, nothing written
$no_folder = fn () => '';
add_filter('wp_go_site_texts_cache_dir', $no_folder, PHP_INT_MAX);
update_option(WP_GO_SITE_TEXTS_OPTION, ['zc.one' => ['it' => 'Senza file']], false);
same('Senza file', wp_go_site_text('zc.one', 'it'), 'with no folder, the text is read from the option');
remove_filter('wp_go_site_texts_cache_dir', $no_folder, PHP_INT_MAX);
check('and nothing was written', !is_dir(wp_go_site_texts_cache_dir() . '/' . wp_go_site_texts_version()));
same([], wp_go_site_texts_for(''), 'no language: no texts');

section('The REST route');

$rest = function (array $params) {
    $request = new WP_REST_Request('GET', '/' . WP_GO_SITE_TEXTS_REST_NAMESPACE . '/strings');

    foreach ($params as $name => $value) {
        $request->set_param($name, $value);
    }

    $response = wp_go_site_texts_rest_strings($request);

    return $response instanceof WP_REST_Response ? json_decode(wp_json_encode($response->get_data()), true) : $response;
};

wp_go_site_texts_store([
    'footer.contact_us' => ['it' => 'Contattaci', 'en' => 'Contact us'],
    'footer.legal.vat'  => ['it' => 'P. IVA', 'en' => 'VAT'],
    'events.load_more'  => ['it' => 'Carica altri'],
    'checkout.pay'      => ['it' => 'Paga', 'en' => 'Pay'],
]);
wp_go_site_texts_set_domains(['checkout.pay' => 'shop']);

same(['checkout' => ['pay' => 'Pay'], 'footer' => ['contact_us' => 'Contact us', 'legal' => ['vat' => 'VAT']]], $rest(['lang' => 'en']), 'the texts in a language, nested by their dots, those with no value left out');
same('Carica altri', $rest([])['events']['load_more'] ?? null, 'no lang is the current language');
same(['checkout' => ['pay' => 'Pay']], $rest(['lang' => 'en', 'domain' => 'shop']), 'a domain keeps to its keys');
same(['footer' => ['contact_us' => 'Contact us', 'legal' => ['vat' => 'VAT']]], $rest(['lang' => 'en', 'domain' => 'general']), 'general keeps to the keys with no domain');
same([], $rest(['lang' => 'en', 'domain' => 'nobody']), 'a domain no key has gives nothing');

$error = $rest(['lang' => 'xx']);
check('a language not the site\'s is a 400 error', $error instanceof WP_Error && ($error->get_error_data()['status'] ?? 0) === 400);

// A text and a group with one name, as only an option written by hand has
// them: the text wins, in either order
// written as an option by hand, unsorted: the route reads the language's
// compiled file, written from it
update_option(WP_GO_SITE_TEXTS_OPTION, ['footer.title' => ['en' => 'Title'], 'footer.title.short' => ['en' => 'Short']], false);
same(['footer' => ['title' => 'Title']], $rest(['lang' => 'en']), 'a text and a longer key under it: the text wins, the longer left out');
update_option(WP_GO_SITE_TEXTS_OPTION, ['footer.title.short' => ['en' => 'Short'], 'footer.title' => ['en' => 'Title']], false);
same(['footer' => ['title' => 'Title']], $rest(['lang' => 'en']), 'the longer key first: the text still wins, replacing the group');
