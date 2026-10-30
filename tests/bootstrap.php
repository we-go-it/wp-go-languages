<?php

/**
 * One test file, inside WordPress (run.php runs `wp eval-file bootstrap.php
 * <test file>`): the database in a transaction rolled back at the end, mail
 * not sent, an administrator as the current user, and the helpers below.
 *
 * Every table is InnoDB, and the site has no persistent object cache: a
 * rollback leaves the database as it was. What WordPress wrote while it
 * started, before this file runs (a language's term the plugin creates, a
 * cron run), isn't in the transaction. Cache Tags' purges have no listener:
 * a test that checks them adds its own. What a test writes on disk (the
 * static cache's copy, uploads) isn't rolled back: a test that writes files
 * removes them itself.
 *
 * The helpers:
 *
 *   check('what is checked', $condition)        a check, passed or failed
 *   same($expected, $actual, 'what')            the two equal (===), the difference shown when not
 *   section('a heading')                        a line in the output
 *   restore_purges()                            Cache Tags' purges reach their adapters again
 *
 * A check that fails doesn't stop the file; an exception or a fatal error
 * does, and the file is reported as not finished.
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

$GLOBALS['wg_test'] = ['ok' => 0, 'fail' => 0, 'done' => false];

function check(string $what, bool $pass, string $detail = ''): bool
{
    echo ($pass ? 'ok   ' : 'FAIL ') . $what . (!$pass && $detail !== '' ? " ($detail)" : '') . PHP_EOL;
    $GLOBALS['wg_test'][$pass ? 'ok' : 'fail']++;

    return $pass;
}

function same(mixed $expected, mixed $actual, string $what): bool
{
    $short = fn (mixed $value) => strlen($export = var_export($value, true)) > 300 ? substr($export, 0, 300) . '…' : $export;

    return check($what, $expected === $actual, 'expected ' . $short($expected) . ', got ' . $short($actual));
}

function section(string $title): void
{
    echo PHP_EOL . "# $title" . PHP_EOL;
}

// autocommit off as well: a deadlock rolls the transaction back, and with
// autocommit on, what the test wrote after it would then be committed
$wpdb->query('SET autocommit = 0');
$wpdb->query('START TRANSACTION');

// The site's languages, for every test: Italian, the default, and English,
// whatever the site's Languages screen has saved (a third language there
// would change every list, count and tag the tests expect). Inside the
// transaction: rolled back with the rest. A test that needs a third adds it
// (fixtures/third-language.php).
// Not in a process a test starts (a request of its own, as dev-tools'): its
// parent's transaction holds the option's row, and the write would wait for
// it until MySQL gives up (50 s). The mark is in the environment it inherits.
if (function_exists('WpGo\\Languages\\Core\\config') && !getenv('WG_TEST_LANGUAGES_PINNED')) {
    putenv('WG_TEST_LANGUAGES_PINNED=1');
    $wg_languages = get_option('wp_go_languages');
    $wg_languages = is_array($wg_languages) ? $wg_languages : [];
    $wg_languages['languages'] = [
        ['code' => 'it', 'locale' => 'it_IT', 'name' => 'Italiano', 'slug' => 'it'],
        ['code' => 'en', 'locale' => 'en_GB', 'name' => 'English', 'slug' => 'en'],
    ];
    unset($wg_languages['default_in_url'], $wg_languages['base_language']);
    update_option('wp_go_languages', $wg_languages);
    WpGo\Languages\Core\config(true);
    unset($wg_languages);
}

// WordPress's own shutdown work (cache tags' purges among it) is registered
// before this, so it runs first, inside the transaction too
register_shutdown_function(function () {
    global $wpdb;

    $wpdb->query('ROLLBACK');

    // only a file that ran to its end reports: run.php takes no summary as
    // a file that didn't finish
    if (!empty($GLOBALS['wg_test']['done'])) {
        echo PHP_EOL . sprintf('WGTEST %d %d', $GLOBALS['wg_test']['ok'], $GLOBALS['wg_test']['fail']) . PHP_EOL;
    }
});

add_filter('pre_wp_mail', '__return_false');

// The compiled translations (site texts' and admin strings' files) go to a
// folder of the test's own, removed at its end: the site's are left as they
// are, and the rollback puts back the versions naming them.
$GLOBALS['wg_test']['compiled'] = sys_get_temp_dir() . '/wg-test-compiled-' . getmypid();
add_filter('wp_go_site_texts_cache_dir', fn () => $GLOBALS['wg_test']['compiled'] . '/site-texts');
add_filter('wp_go_languages/admin_strings_cache_dir', fn () => $GLOBALS['wg_test']['compiled'] . '/admin-strings');
register_shutdown_function(function () {
    $dir = $GLOBALS['wg_test']['compiled'];

    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
});

// Cache Tags' purges reach no adapter: a test's saves would otherwise drop
// the static cache's real copies at its end. A test of the purges hooks its
// own listener; a test of the adapter itself (Static Cache) puts its
// listeners back with restore_purges().
foreach (['wp_go_cache_tags/purge', 'wp_go_cache_tags/purge_all'] as $wg_hook) {
    // a copy: remove_all_actions() empties the hook object itself
    $GLOBALS['wg_test']['purges'][ $wg_hook ] = isset($GLOBALS['wp_filter'][ $wg_hook ]) ? clone $GLOBALS['wp_filter'][ $wg_hook ] : null;
    remove_all_actions($wg_hook);
}
unset($wg_hook);

function restore_purges(): void
{
    foreach ($GLOBALS['wg_test']['purges'] as $hook => $listeners) {
        if ($listeners) {
            $GLOBALS['wp_filter'][ $hook ] = $listeners;
        }
    }
}

$administrators = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($administrators[0] ?? 0));

require $args[0];

$GLOBALS['wg_test']['done'] = true;
