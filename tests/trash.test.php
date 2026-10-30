<?php

// @require fixtures/third-language.php

/**
 * Moving a page to the Trash with its translations (src/Admin/trash.php):
 * only when asked (`wgl_all=1` on WordPress's trash), from a row's link and
 * from the bulk action, the rows ticked left to WordPress, a translation
 * the user can't delete or already in the Trash left where it is, and
 * WordPress's count and "Undo" with them all.
 */

require_once __DIR__ . '/fixtures/content.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;

// the admin's list, where WordPress trashes
set_current_screen('edit-page');

$group = function (string $title): array {
    $it = wgt_page($title, serialize_block(wgt_para('Ciao')));

    return ['it' => $it, 'en' => WpGoLanguages::create_translation($it, 'en'), 'de' => WpGoLanguages::create_translation($it, 'de')];
};

// WordPress's trash, as its request asks: the request's args, then each post
$trash = function (array $request, array $ids): array {
    $_REQUEST = $request;
    $moved = &Admin\trashed_translations();
    $moved = [];

    foreach ($ids as $id) {
        wp_trash_post($id);
    }

    $_REQUEST = [];

    return Admin\trashed_translations();
};

$status = fn (array $ids) => array_map('get_post_status', $ids);

section('Asked or not');

$page = $group('Cestino wgt');
same([], $trash(['action' => 'trash', 'post' => $page['it']], [$page['it']]), 'without wgl_all: only the page');
same(['it' => 'trash', 'en' => 'draft', 'de' => 'draft'], $status($page), 'its translations stay');
wp_untrash_post($page['it']);

same([], $trash(['action' => 'edit', 'wgl_all' => '1', 'post' => $page['it']], [$page['it']]), 'another action than trash: nothing taken along');
wp_untrash_post($page['it']);

section('A row\'s "Trash"');

$moved = $trash(['action' => 'trash', 'wgl_all' => '1', 'post' => (string) $page['it']], [$page['it']]);
same([$page['en'], $page['de']], $moved, 'with wgl_all: its translations too');
same(['it' => 'trash', 'en' => 'trash', 'de' => 'trash'], $status($page), 'all three in the Trash');

$back = admin_url('edit.php?post_type=page&trashed=1&ids=' . $page['it']);
parse_str((string) parse_url(Admin\trashed_redirect($back), PHP_URL_QUERY), $query);
same('3', $query['trashed'], 'WordPress\'s count says all three');
same(implode(',', [$page['it'], $page['en'], $page['de']]), $query['ids'], 'and its Undo brings them all back');

same(admin_url('edit.php?post_type=page'), Admin\trashed_redirect(admin_url('edit.php?post_type=page')), 'an address that isn\'t the trash\'s is left alone');

// a translation, trashed with its group: the original goes too
$other = $group('Cestino traduzione wgt');
same([$other['it'], $other['de']], $trash(['action' => 'trash', 'wgl_all' => '1', 'post' => $other['en']], [$other['en']]), 'from a translation: the original and the others');

section('"Move to Trash" for the rows ticked');

$bulk = $group('Cestino massa wgt');
$moved = $trash(['action' => '-1', 'action2' => 'trash', 'wgl_all' => '1', 'post' => [$bulk['it'], $bulk['en']]], [$bulk['it'], $bulk['en']]);
same([$bulk['de']], $moved, 'the rows ticked are left to WordPress\'s loop, which trashes them itself (the bottom select\'s action2 read too)');
same(['it' => 'trash', 'en' => 'trash', 'de' => 'trash'], $status($bulk), 'and none is trashed twice, which WordPress stops on');

section('Left where they are');

$kept = $group('Cestino resta wgt');
wp_trash_post($kept['de']);
same([$kept['en']], $trash(['action' => 'trash', 'wgl_all' => '1', 'post' => $kept['it']], [$kept['it']]), 'a translation in the Trash already is skipped');

$admin = get_current_user_id();
$author = wp_insert_user(['user_login' => 'wgt_trash_author', 'user_pass' => wp_generate_password(), 'role' => 'author']);
// an author's page, its translations (drafts) the administrator's: theirs to
// trash, not the translations
$theirs = $group('Cestino altrui wgt');
wp_update_post(['ID' => $theirs['it'], 'post_author' => $author]);
wp_set_current_user($author);
same([], $trash(['action' => 'trash', 'wgl_all' => '1', 'post' => $theirs['it']], [$theirs['it']]), 'a translation the user can\'t delete is left');
same(['en' => 'draft', 'de' => 'draft'], $status(['en' => $theirs['en'], 'de' => $theirs['de']]), 'as it was');
wp_set_current_user($admin);

$untranslated = wp_insert_post(['post_type' => 'wgt_untranslated', 'post_title' => 'x', 'post_status' => 'publish']);
same([], $trash(['action' => 'trash', 'wgl_all' => '1', 'post' => $untranslated], [(int) $untranslated]), 'a post of a type not translated: nothing');

$GLOBALS['current_screen'] = null;
same([], $trash(['action' => 'trash', 'wgl_all' => '1', 'post' => $kept['en']], [$kept['en']]), 'outside the admin: nothing taken along');

wgt_cleanup();
