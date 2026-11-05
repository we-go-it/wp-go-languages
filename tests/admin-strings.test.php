<?php

/**
 * Admin strings: reading a domain's code for its _x() strings (and what it
 * can't read), the scan keeping what the code has and the strings of a
 * source it couldn't read, the translations forgotten with their strings,
 * compiled to .l10n.php files and loaded in the right locale (or, with no
 * folder to write in, applied by the gettext filter), and the tab's save.
 * The sources are the test's own, in a temporary folder, in place of the
 * site's.
 */

require_once __DIR__ . '/fixtures/content.php';

/** A string's translation in a locale, or '' when there is none. */
function wgt_string_translation(string $id, string $locale): string
{
    $string = wp_go_admin_strings()[$id] ?? null;

    return $string ? (string) (wp_go_admin_strings_translations()[$locale][$string['domain']][wp_go_admin_strings_lookup_key($string['context'], $string['text'])] ?? '') : '';
}

/** Run one of the tab's forms, as a POST with its nonce: where it went back to, its query read. */
function wgas_post(string $action, string $handler, array $post): array
{
    $_POST    = wp_slash($post);
    $_REQUEST = $_POST + ['_wpnonce' => wp_create_nonce($action)];

    $stop = wgt_stopped($handler);
    parse_str((string) parse_url($stop['target'], PHP_URL_QUERY), $query);

    $_POST = $_REQUEST = [];

    return $stop + ['query' => $query];
}

$id  = fn (string $domain, string $context, string $text) => wp_go_admin_strings_id($domain, $context, $text);
$key = fn (string $context, string $text) => wp_go_admin_strings_lookup_key($context, $text);
$has = fn (array $warnings, string $part) => (bool) array_filter($warnings, fn ($warning) => str_contains($warning, $part));

// The code: a domain's folder, with a path that's there and one that isn't
$root = wgt_temp_dir('admin-strings');
wp_mkdir_p("$root/src/node_modules");
file_put_contents("$root/src/a.php", <<<'PHP'
<?php
_x('Save', 'src/a.php', 'wgt-domain');
esc_html_x("Don\"t go", 'src/a.php', 'wgt-domain');
esc_attr_x('It\'s here', 'src/a.php', 'wgt-domain');
_x('Wrong context', 'src/other.php', 'wgt-domain');
__('Not with a context', 'wgt-domain');
_x ('A space before its parenthesis', 'src/a.php', 'wgt-domain');
// _x('In a comment', 'src/a.php', 'wgt-domain');
_x('Another domain', 'src/a.php', 'wgt-other');
PHP);
file_put_contents("$root/src/view.twig", "{{ _x('In Twig', 'src/view.twig', 'wgt-domain') }}");
file_put_contents("$root/src/node_modules/skipped.php", "<?php _x('Skipped', 'src/node_modules/skipped.php', 'wgt-domain');");

$sources = [
    'wgt-domain'  => ['root' => $root, 'paths' => ['src', 'missing'], 'extensions' => ['php', 'twig']],
    'wgt-noroot'  => ['root' => "$root/not-a-folder"],
    'wgt-nopaths' => ['root' => $root, 'paths' => ['nothing-here']],
];
add_filter('wp_go_languages/admin_strings', function () use (&$sources) {
    return $sources;
}, PHP_INT_MAX);

// The last scan, before this one: a string the code no longer has, one it
// still has, and one of each domain it won't read
$last = [
    $id('wgt-domain', 'src/old.php', 'Gone')        => ['domain' => 'wgt-domain', 'context' => 'src/old.php', 'text' => 'Gone'],
    $id('wgt-domain', 'src/a.php', 'Save')          => ['domain' => 'wgt-domain', 'context' => 'src/a.php', 'text' => 'Save'],
    $id('wgt-noroot', 'x.php', 'Kept A')            => ['domain' => 'wgt-noroot', 'context' => 'x.php', 'text' => 'Kept A'],
    $id('wgt-nopaths', 'y.php', 'Kept B')           => ['domain' => 'wgt-nopaths', 'context' => 'y.php', 'text' => 'Kept B'],
    $id('wgt-unlisted', 'z.php', 'Kept C')          => ['domain' => 'wgt-unlisted', 'context' => 'z.php', 'text' => 'Kept C'],
];
update_option(WP_GO_ADMIN_STRINGS_OPTION, ['scanned' => 1, 'strings' => $last, 'warnings' => []], false);
wp_go_admin_strings_store([
    'it_IT' => ['wgt-domain' => [$key('src/old.php', 'Gone') => 'Andato', $key('src/a.php', 'Save') => 'Salva']],
    'en_GB' => ['wgt-domain' => [$key('src/old.php', 'Gone') => 'Gone for good'], 'wgt-empty' => []],
]);

section('Reading the code');

same(['it_IT' => 'Italiano', 'en_GB' => 'English'], wp_go_admin_strings_locales(), 'the strings are translated into the site\'s languages\' locales');
same(['it_IT' => ['wgt-domain' => [$key('src/old.php', 'Gone') => 'Andato', $key('src/a.php', 'Save') => 'Salva']], 'en_GB' => ['wgt-domain' => [$key('src/old.php', 'Gone') => 'Gone for good']]], get_option(WP_GO_ADMIN_STRINGS_TRANSLATIONS), 'the translations are stored with no empty domain left behind');

$read = wp_go_admin_strings_read_code();
$texts = array_column($read['strings'], 'text');
sort($texts);

same(['wgt-domain'], $read['read'], 'only the domain whose folder and paths are there is read');
same(['Don"t go', 'In Twig', 'It\'s here', 'Save', 'Wrong context'], $texts, '_x(), esc_html_x() and esc_attr_x() of the domain are read, PHP and Twig, quotes unescaped');
same(['domain' => 'wgt-domain', 'context' => 'src/a.php', 'text' => 'Save'], $read['strings'][$id('wgt-domain', 'src/a.php', 'Save')] ?? null, 'a string is kept by md5(domain|context|text), with its domain, context and text');
check('a string in a comment, another domain\'s and a skipped folder\'s are not read', !array_intersect($texts, ['In a comment', 'Another domain', 'Skipped']));
check('a string whose context isn\'t its file\'s path is kept under the context written, with a warning', isset($read['strings'][$id('wgt-domain', 'src/other.php', 'Wrong context')]) && $has($read['warnings'], 'wgt-domain/src/a.php: the context of «Wrong context» is «src/other.php»'));
check('strings of the domain not written as _x() are counted in a warning', $has($read['warnings'], 'wgt-domain/src/a.php: 2 string(s) of its domain not written as _x('));
check('a path not there is warned about', $has($read['warnings'], 'wgt-domain: «missing» isn\'t in'));
check('a root that isn\'t a folder is warned about', $has($read['warnings'], 'wgt-noroot: its root «' . "$root/not-a-folder" . '» isn\'t a folder that can be read'));
check('a domain with none of its paths there is warned about', $has($read['warnings'], 'wgt-nopaths: none of its paths is in'));

section('A dry run');

$dry = wp_go_admin_strings_scan(true);
same([$id('wgt-domain', 'src/old.php', 'Gone')], $dry['removed'], 'a dry run says what would be removed');
same(4, count($dry['added']), 'a dry run says what would be added');
same($last, wp_go_admin_strings(), 'a dry run leaves the strings as they were');
same('Andato', wp_go_admin_strings_translations()['it_IT']['wgt-domain'][$key('src/old.php', 'Gone')] ?? null, 'a dry run leaves the translations as they were');

section('The scan');

$removed = [];
$on_removed = function ($by_domain) use (&$removed) {
    $removed[] = $by_domain;
};
add_action('wp_go_languages/strings_removed', $on_removed);
$done = wp_go_admin_strings_scan();
remove_action('wp_go_languages/strings_removed', $on_removed);

$after = wp_go_admin_strings();
same([$id('wgt-domain', 'src/old.php', 'Gone')], $done['removed'], 'a string the code no longer has is removed');
check('a string the code still has stays', isset($after[$id('wgt-domain', 'src/a.php', 'Save')]));
check('the code\'s new strings are added', isset($after[$id('wgt-domain', 'src/view.twig', 'In Twig')]) && count($done['added']) === 4);
same(['wgt-noroot' => 1, 'wgt-nopaths' => 1, 'wgt-unlisted' => 1], $done['kept'], 'the domains not read keep their strings, each counted');
check('the kept strings are still listed', isset($after[$id('wgt-noroot', 'x.php', 'Kept A')], $after[$id('wgt-nopaths', 'y.php', 'Kept B')], $after[$id('wgt-unlisted', 'z.php', 'Kept C')]));
check('a domain not listed this time is warned about, with its count', $has($done['warnings'], 'wgt-unlisted: not listed this time (the theme not loaded, `--skip-themes`?): its 1 strings are kept'));
same(8, $done['found'], 'found counts the list after the scan, kept strings included');
same($after, $done['strings'], 'the scan gives back the list it stored');
same($done['warnings'], wp_go_admin_strings_state()['warnings'], 'the scan\'s warnings are kept for the tab');

check('a removed string\'s translations go, in every locale', !isset(wp_go_admin_strings_translations()['it_IT']['wgt-domain'][$key('src/old.php', 'Gone')]) && !isset(wp_go_admin_strings_translations()['en_GB']));
same('Salva', wgt_string_translation($id('wgt-domain', 'src/a.php', 'Save'), 'it_IT'), 'a string still there keeps its translation');
same([['wgt-domain' => ['src/old.php']]], $removed, 'wp_go_languages/strings_removed fires once, with the contexts that lost a translation');

$again = wp_go_admin_strings_scan();
check('a second scan finds nothing new and removes nothing', $again['added'] === [] && $again['removed'] === []);

section('Applying the translations');

wp_go_admin_strings_store([
    'it_IT' => ['wgt-domain' => [$key('src/a.php', 'Save') => 'Salva', $key('src/a.php', 'It\'s here') => 'È qui']],
    'en_GB' => ['wgt-domain' => [$key('src/a.php', 'Save') => 'Store']],
]);

// the files are the bootstrap's folder's (wp_go_languages/admin_strings_cache_dir)
$index = wp_go_admin_strings_index();
$folder = wp_go_admin_strings_cache_dir() . '/' . $index['version'];
same(['it_IT' => ['wgt-domain'], 'en_GB' => ['wgt-domain']], $index['domains'], 'the index lists each locale\'s domains, and none without translations');
same(wp_go_site_texts_hash(wp_go_admin_strings_translations()), $index['version'], 'its version is the translations\' hash');
check('saving wrote the request locale\'s file, in WordPress\'s .l10n.php format', is_file("$folder/wgt-domain-" . determine_locale() . '.l10n.php'));
$data = include "$folder/wgt-domain-it_IT.l10n.php";
same(['domain' => 'wgt-domain', 'language' => 'it_IT', 'messages' => [$key('src/a.php', 'Save') => 'Salva', $key('src/a.php', 'It\'s here') => 'È qui']], $data, 'a file has the domain, the locale and its messages, keyed as gettext looks them up');
check('the domain is loaded from its file, with no gettext filter', has_filter('gettext_with_context_wgt-domain', 'wp_go_admin_strings_translate') === false && isset(wp_go_admin_strings_loaded()[determine_locale()]['wgt-domain']));

same('Salva', _x('Save', 'src/a.php', 'wgt-domain'), 'a string is translated in the admin\'s locale');
same(esc_html('È qui'), esc_html_x('It\'s here', 'src/a.php', 'wgt-domain'), 'esc_html_x() goes through the same translation');
same('Save', _x('Save', 'src/other.php', 'wgt-domain'), 'the same text under another context isn\'t translated');
same('Save', _x('Save', 'src/a.php', 'wgt-other'), 'the same string of another domain isn\'t translated');

switch_to_locale('en_GB');
same('Store', _x('Save', 'src/a.php', 'wgt-domain'), 'a locale switched to gives its translation (its file written then)');
check('and its file is there', is_file("$folder/wgt-domain-en_GB.l10n.php"));
restore_previous_locale();
same('Salva', _x('Save', 'src/a.php', 'wgt-domain'), 'back from the switch, the admin\'s locale again');
switch_to_locale('en_US');
same('Save', _x('Save', 'src/a.php', 'wgt-domain'), 'a locale not the site\'s gives the English');
restore_previous_locale();

wp_go_admin_strings_store([
    'it_IT' => ['wgt-domain' => [$key('src/a.php', 'Save') => 'Salva ora', $key('src/a.php', 'It\'s here') => 'È qui']],
    'en_GB' => ['wgt-domain' => [$key('src/a.php', 'Save') => 'Store']],
]);
same('Salva ora', _x('Save', 'src/a.php', 'wgt-domain'), 'a translation saved shows in the same request, the old file unloaded');
check('the old version\'s folder is removed as the new one is written', !is_dir($folder) && is_dir(wp_go_admin_strings_cache_dir() . '/' . wp_go_admin_strings_index()['version']));

// the version kept isn't the option's (the option written by SQL): the
// files it names are missing, so they're written, and the version put right
$version = wp_go_admin_strings_index()['version'];
update_option(WP_GO_ADMIN_STRINGS_INDEX, ['version' => 'aaaaaaaaaaaa', 'domains' => ['it_IT' => ['wgt-domain']]], true);
wp_go_admin_strings_load('it_IT');
same($version, wp_go_admin_strings_index()['version'], 'a stale version is put right by the first load');

// The domain's own translations, in a file of its own (a .mo would do the
// same): loaded first, they win; a string they lack is still the tab's
$own = wgt_temp_dir('admin-strings-own');
file_put_contents("$own/wgt-domain-it_IT.l10n.php", '<?php return ' . var_export(['domain' => 'wgt-domain', 'language' => 'it_IT', 'messages' => [$key('src/a.php', 'Save') => 'Dal file .mo']], true) . ';');
$controller = WP_Translation_Controller::get_instance();
$controller->unload_textdomain('wgt-domain', 'it_IT');
$loaded = &wp_go_admin_strings_loaded();
$loaded = [];
load_textdomain('wgt-domain', "$own/wgt-domain-it_IT.mo", 'it_IT');
wp_go_admin_strings_load('it_IT');
same('Dal file .mo', _x('Save', 'src/a.php', 'wgt-domain'), 'the domain\'s own translation wins');
same(esc_html('È qui'), esc_html_x('It\'s here', 'src/a.php', 'wgt-domain'), 'one it lacks is the tab\'s');
$controller->unload_textdomain('wgt-domain', 'it_IT');
$loaded = [];
unset($loaded);

// No folder to write in: the domain falls back to the filter, which reads the option
$cache_off = fn () => '';
add_filter('wp_go_languages/admin_strings_cache_dir', $cache_off, PHP_INT_MAX);
wp_go_admin_strings_load('it_IT');
check('with no folder, the domain is on its gettext filter instead', has_filter('gettext_with_context_wgt-domain', 'wp_go_admin_strings_translate') !== false);
same('Salva ora', _x('Save', 'src/a.php', 'wgt-domain'), 'and is translated the same');
$mo = fn ($translation, $text, $context, $domain) => $domain === 'wgt-domain' && $text === 'Save' ? 'Dal file .mo' : $translation;
add_filter('gettext_with_context', $mo, 10, 4);
same('Dal file .mo', _x('Save', 'src/a.php', 'wgt-domain'), 'the filter too leaves gettext\'s own translation alone');
remove_filter('gettext_with_context', $mo, 10);
remove_filter('wp_go_languages/admin_strings_cache_dir', $cache_off, PHP_INT_MAX);
remove_filter('gettext_with_context_wgt-domain', 'wp_go_admin_strings_translate', 10);

// the translations as they were, for the tab's save below
wp_go_admin_strings_store([
    'it_IT' => ['wgt-domain' => [$key('src/a.php', 'Save') => 'Salva', $key('src/a.php', 'It\'s here') => 'È qui']],
    'en_GB' => ['wgt-domain' => [$key('src/a.php', 'Save') => 'Store']],
]);

section('Saving on the tab');

$save_id = $id('wgt-domain', 'src/a.php', 'Save');
$here_id = $id('wgt-domain', 'src/a.php', 'It\'s here');
$dont_id = $id('wgt-domain', 'src/a.php', 'Don"t go');

$saved = [];
$on_saved = function ($changed) use (&$saved) {
    $saved[] = $changed;
};
add_action('wp_go_languages/strings_saved', $on_saved);
$done = wgas_post('wp_go_admin_strings_save', 'wp_go_admin_strings_save_action', ['strings' => [
    'it_IT' => [$save_id => "  Salva ora\r\n ", $here_id => '', $dont_id => ['not text'], 'not-a-string-id' => 'x'],
    'de_DE' => [$save_id => 'Speichern'],
    'en_GB' => [$save_id => 'Store', $dont_id => 'Don\'t go away'],
]]);
remove_action('wp_go_languages/strings_saved', $on_saved);

same('redirect', $done['kind'], 'the save goes back to the tab');
same('3', $done['query']['saved'] ?? null, 'it says how many translations changed');
same('Salva ora', wgt_string_translation($save_id, 'it_IT'), 'a translation is trimmed and saved');
same('', wgt_string_translation($here_id, 'it_IT'), 'an emptied field removes the translation');
same('Don\'t go away', wgt_string_translation($dont_id, 'en_GB'), 'a new translation is saved, quotes unslashed');
same('', wgt_string_translation($dont_id, 'it_IT'), 'a value that isn\'t text is ignored');
check('a locale not the site\'s is ignored', !isset(wp_go_admin_strings_translations()['de_DE']));
same([['wgt-domain' => ['src/a.php' => ['it_IT', 'en_GB']]]], $saved, 'wp_go_languages/strings_saved fires once, by domain, context and locale');

$done = wgas_post('wp_go_admin_strings_save', 'wp_go_admin_strings_save_action', ['strings' => ['it_IT' => [$save_id => 'Salva ora']]]);
same('0', $done['query']['saved'] ?? null, 'a save changing nothing says 0');

$done = wgas_post('wp_go_admin_strings_scan', 'wp_go_admin_strings_scan_action', []);
same(['found' => '8', 'added' => '0', 'removed' => '0'], array_intersect_key($done['query'], ['found' => 1, 'added' => 1, 'removed' => 1]), 'Scan code goes back with what it found, added and removed');

$editor = wp_insert_user(['user_login' => 'wgas_editor', 'user_pass' => wp_generate_password(), 'user_email' => 'wgas_editor@example.test', 'role' => 'editor']);
$admin  = get_current_user_id();
wp_set_current_user($editor);
$done = wgas_post('wp_go_admin_strings_save', 'wp_go_admin_strings_save_action', ['strings' => ['it_IT' => [$save_id => 'Rubato']]]);
wp_set_current_user($admin);
check('the save is refused to a user who isn\'t an administrator', $done['kind'] === 'die' && $done['status'] === 403 && wgt_string_translation($save_id, 'it_IT') === 'Salva ora');

section('The tab');

$_GET = ['page' => WP_GO_SITE_TEXTS_PAGE, 'tab' => 'admin', 's' => ['x'], 'domain' => ['wgt-domain']];
$view = wp_go_admin_strings_view();
check('a search or domain that isn\'t a plain value is none, not an error', $view['search'] === '' && $view['domain'] === '');
check('the tab is open for an administrator with tab=admin', wp_go_admin_strings_tab_open());

$_GET = ['saved' => '1', 'found' => '5', 'added' => '1', 'removed' => '2'];
$notices = wgt_output(fn () => wp_go_admin_strings_render_notices(wp_go_admin_strings_view()));
$_GET = [];
check('the notices count in words', str_contains($notices, '1 translation saved.') && str_contains($notices, '5 strings in the code: 1 new, 2 no longer there, removed with their translations.'));
