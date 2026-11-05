<?php

/**
 * What a synced field points at, worked out as a page renders (Core/synced-links.php):
 * through a real block's render, and ACF's reads of a page's fields and the options. A
 * cached page holding one, filed under its target and dropped when a page of that
 * group, in its language, is published, taken down, linked, unlinked or deleted: read
 * from Cache Tags' own collecting and purge queue, never handed to a cache. The stored
 * values converted, either way, and what the panel warns of.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

if (!function_exists('wp_go_cache_tag') || !function_exists('wp_go_static_cache_is_tag')) {
    check('Cache Tags and Static Cache are loaded', false);
    return;
}

$rendered = function (callable $render): array {
    wp_go_cache_tags_reset();
    $render();

    return wp_go_cache_tags_final();
};

$purged = function (callable $change): array {
    $queue = &wp_go_cache_tags_purge_queue();
    $queue = [];
    $change();
    $tags  = array_keys($queue);
    $queue = [];

    return $tags;
};

$target = wgt_page('Contatti tag', '');
$url    = (string) get_permalink($target);
$tag_en = Core\link_tag($target, 'en');
$tag_it = Core\link_tag($target, 'it');


section('The tag');

same("wgl-link-$target--en", $tag_en, 'the target and the language');
check('the static cache takes it as a tag', wp_go_static_cache_is_tag($tag_en));


section('A page rendering a link with no translation yet');

$tags = $rendered(fn () => same($url, Core\localize_url($url, 'en', true), 'it goes to the original\'s page'));
check('the page is filed under its target, in its language', in_array($tag_en, $tags, true));

$tags = $rendered(fn () => Core\localize_url($url, 'en'));
check('asked with no tag, as a save does, it isn\'t', !in_array($tag_en, $tags, true));

$tags = $rendered(function () use ($url) {
    Core\localize_url('https://example.invalid/altrove/', 'en', true);
    Core\localize_url(home_url('/zz-no-such-page/'), 'en', true);
});
check('an address that isn\'t a post of the site has no tag', !array_filter($tags, fn ($tag) => str_starts_with($tag, 'wgl-link-')));

// a page listing its own type drops its link-<id> tags: this one has to stay
$tags = $rendered(function () use ($url) {
    Core\localize_url($url, 'en', true);
    wp_go_cache_tag('type-page');
    wp_go_cache_tag('type-page--it');
});
check('a page listing pages keeps it', in_array($tag_en, $tags, true));


section('Its translation made, then published');

$translation = (int) WpGoLanguages::create_translation($target, 'en', 'independent');
same('draft', get_post_status($translation), 'a new translation is a draft');

$tags = $purged(fn () => wp_update_post(['ID' => $translation, 'post_title' => 'Contact tag draft']));
check('a draft saved purges nothing of it', !in_array($tag_en, $tags, true));

$tags = $purged(fn () => wp_update_post(['ID' => $translation, 'post_status' => 'publish', 'post_title' => 'Contact tag', 'post_name' => 'contact-tag']));
check('published: the pages holding a link to its original go', in_array($tag_en, $tags, true));
check('and those holding one to it', in_array(Core\link_tag($translation, 'en'), $tags, true));
check('not those in the original\'s language, whose link goes nowhere new', !in_array($tag_it, $tags, true));

$link = (string) get_permalink($translation);
$tags = $rendered(fn () => same($link, Core\localize_url($url, 'en', true), 'rendered again, the link goes to it'));
check('and it\'s under the same tag, for when it\'s taken down', in_array($tag_en, $tags, true));


section('Taken down');

$tags = $purged(fn () => wp_update_post(['ID' => $translation, 'post_status' => 'draft']));
check('the pages holding the link go', in_array($tag_en, $tags, true));
$rendered(fn () => same($url, Core\localize_url($url, 'en', true), 'rendered again, it goes back to the original\'s page'));

$tags = $purged(fn () => wp_update_post(['ID' => $translation, 'post_status' => 'publish']));
check('published again: they go again', in_array($tag_en, $tags, true));

$tags = $purged(fn () => wp_update_post(['ID' => $translation, 'post_title' => 'Contact tag again']));
check('saved while published changes no link: they stay', !in_array($tag_en, $tags, true));

$tags = $purged(fn () => wp_trash_post($translation));
check('trashed: they go', in_array($tag_en, $tags, true));
$rendered(fn () => same($url, Core\localize_url($url, 'en', true), 'rendered again, the original\'s page'));
wp_untrash_post($translation);


section('A page linked as a translation later');

$loose = wgt_page('Contatti sciolto', '');
$loose_url = (string) get_permalink($loose);
$loose_tag = Core\link_tag($loose, 'en');
$rendered(fn () => same($loose_url, Core\localize_url($loose_url, 'en', true), 'no translation: the original\'s page'));

$english = wgt_page('Loose contact', '', 'en');
$tags = $purged(fn () => WpGoLanguages::link($english, $loose));
check('an English page linked: the pages holding the link go', in_array($loose_tag, $tags, true));
$rendered(fn () => same((string) get_permalink($english), Core\localize_url($loose_url, 'en', true), 'rendered again, the link goes to it'));


section('The site\'s other pages');

$other = wgt_page('Altra pagina tag', '');
$other_url = (string) get_permalink($other);
$tags = $rendered(fn () => Core\localize_url($other_url, 'en', true));
check('a link to another page isn\'t under this one', !in_array($tag_en, $tags, true));
$tags = $purged(fn () => wp_update_post(['ID' => $translation, 'post_status' => 'draft']));
check('nor does this one\'s change reach it', !in_array(Core\link_tag($other, 'en'), $tags, true));


section('Unlinked, or deleted');

$tags = $purged(fn () => wp_update_post(['ID' => $translation, 'post_status' => 'publish']));
$tags = $purged(fn () => Core\unlink($translation));
check('a translation leaving: the pages holding the link go', in_array($tag_en, $tags, true));
WpGoLanguages::link($translation, $target);

$tags = $purged(fn () => wp_delete_post($translation, true));
check('deleted: they go', in_array($tag_en, $tags, true));
$translation = (int) WpGoLanguages::create_translation($target, 'en', 'independent');
wp_update_post(['ID' => $translation, 'post_status' => 'publish', 'post_title' => 'Contact tag', 'post_name' => 'contact-tag']);
$link = (string) get_permalink($translation);


section('A real block\'s render');

$fields = [
    ['key' => 'field_wgt_sl_cta', 'name' => 'sl_cta', 'label' => 'CTA', 'type' => 'link', 'return_format' => 'array'],
    ['key' => 'field_wgt_sl_own', 'name' => 'sl_own', 'label' => 'Own', 'type' => 'link', 'return_format' => 'array'],
    ['key' => 'field_wgt_sl_address', 'name' => 'sl_address', 'label' => 'Address', 'type' => 'url'],
    ['key' => 'field_wgt_sl_text', 'name' => 'sl_text', 'label' => 'Text', 'type' => 'wysiwyg'],
    ['key' => 'field_wgt_sl_page', 'name' => 'sl_page', 'label' => 'Page', 'type' => 'post_object', 'post_type' => ['page'], 'return_format' => 'id'],
    ['key' => 'field_wgt_sl_rows', 'name' => 'sl_rows', 'label' => 'Rows', 'type' => 'repeater', 'sub_fields' => [
        ['key' => 'field_wgt_sl_rows_url', 'name' => 'url', 'label' => 'URL', 'type' => 'url'],
    ]],
];

acf_add_local_field_group([
    'key'      => 'group_wgt_sl',
    'title'    => 'Synced links',
    'fields'   => $fields,
    'location' => [
        [['param' => 'block', 'operator' => '==', 'value' => 'acf/wgt-sl']],
        [['param' => 'post_type', 'operator' => '==', 'value' => 'page']],
        [['param' => 'options_page', 'operator' => '==', 'value' => 'wgt-sl-options']],
    ],
]);

$GLOBALS['wgt_sl_seen'] = [];
acf_register_block_type([
    'name'            => 'wgt-sl',
    'title'           => 'Synced links',
    'render_callback' => function () {
        $GLOBALS['wgt_sl_seen'] = get_fields();
    },
]);

$values = [
    'sl_cta'     => ['title' => 'Vai', 'url' => $url, 'target' => ''],
    '_sl_cta'    => 'field_wgt_sl_cta',
    'sl_own'     => ['title' => 'Mio', 'url' => $url, 'target' => ''],
    '_sl_own'    => 'field_wgt_sl_own',
    'sl_address' => $url,
    '_sl_address' => 'field_wgt_sl_address',
    'sl_text'    => '<p>Qui <a href="' . $url . '">contatti</a>.</p>',
    '_sl_text'   => 'field_wgt_sl_text',
    'sl_page'    => (string) $target,
    '_sl_page'   => 'field_wgt_sl_page',
    'sl_rows'    => 1,
    '_sl_rows'   => 'field_wgt_sl_rows',
    'sl_rows_0_url'  => $url,
    '_sl_rows_0_url' => 'field_wgt_sl_rows_url',
];
$marks  = ['sl_cta', 'sl_address', 'sl_text', 'sl_page', 'sl_rows'];
$parsed = fn (array $data, array $marks) => [
    'blockName'    => 'acf/wgt-sl',
    'attrs'        => ['name' => 'acf/wgt-sl', 'mode' => 'preview', 'data' => $data, 'metadata' => ['wgId' => 'sl-' . wp_generate_password(6, false), Core\SYNCED_BLOCK => $marks]],
    'innerBlocks'  => [],
    'innerHTML'    => '',
    'innerContent' => [],
];
$render = function (array $block) {
    $GLOBALS['wgt_sl_seen'] = [];
    acf_get_store('values')->reset();
    \render_block($block);

    return $GLOBALS['wgt_sl_seen'];
};
$on_site = function (callable $read) {
    $front = State::$front;
    State::$front = true;
    acf_get_store('values')->reset();

    try {
        return WpGoLanguages::in('en', $read);
    } finally {
        State::$front = $front;
    }
};

$seen = $on_site(fn () => $render($parsed($values, $marks)));
same($link, $seen['sl_cta']['url'] ?? null, 'a synced link: the translation');
same('Vai', $seen['sl_cta']['title'] ?? null, 'its label as it is');
same($url, $seen['sl_own']['url'] ?? null, 'a field not synced is left as it is');
same($link, $seen['sl_address'] ?? null, 'a URL field: the translation');
check('a link typed in a text: the translation', str_contains((string) ($seen['sl_text'] ?? ''), $link));
same($translation, (int) ($seen['sl_page'] ?? 0), 'a picked page: the translation');
same($link, $seen['sl_rows'][0]['url'] ?? null, 'a repeater marked whole: its cell');

$tags = $on_site(fn () => $rendered(fn () => $render($parsed($values, $marks))));
check('the page rendering it is filed under its target', in_array($tag_en, $tags, true));

$seen = $on_site(fn () => $render($parsed($values, ['sl_cta#title'])));
same($url, $seen['sl_cta']['url'] ?? null, 'only its label synced: its page is the translation\'s own');

$seen = WpGoLanguages::in('en', fn () => $render($parsed($values, $marks)));
same($url, $seen['sl_cta']['url'] ?? null, 'in the admin, what\'s stored');

wp_update_post(['ID' => $translation, 'post_status' => 'draft']);
$seen = $on_site(fn () => $render($parsed($values, $marks)));
same($url, $seen['sl_cta']['url'] ?? null, 'its translation taken down: the original\'s page, nothing saved');
same($translation, (int) ($seen['sl_page'] ?? 0), 'a picked page goes to a draft too, as what renders it decides');
wp_update_post(['ID' => $translation, 'post_status' => 'publish']);


section('ACF\'s reads: a page\'s fields, the options');

$page_it = wgt_page('Campi collegati', '');
update_field('field_wgt_sl_cta', ['title' => 'Vai', 'url' => $url, 'target' => ''], $page_it);
update_field('field_wgt_sl_own', ['title' => 'Mio', 'url' => $url, 'target' => ''], $page_it);
$page_en = (int) WpGoLanguages::create_translation($page_it, 'en', 'independent');
update_field('field_wgt_sl_own', ['title' => 'Mine', 'url' => $url, 'target' => ''], $page_en);
Core\set_synced_marks($page_en, ['sl_cta']);
Core\pull_fields($page_en, $page_it);
same($url, get_post_meta($page_en, 'sl_cta', true)['url'] ?? null, 'stored as the original has it');
same($link, $on_site(fn () => get_field('sl_cta', $page_en))['url'] ?? null, 'get_field() on the site: the translation');
same($url, $on_site(fn () => get_field('sl_own', $page_en))['url'] ?? null, 'a field not synced is left as it is');
same($url, $on_site(fn () => get_field('sl_cta', $page_it))['url'] ?? null, 'nor is the original\'s touched');
acf_get_store('values')->reset();
same($url, get_field('sl_cta', $page_en)['url'] ?? null, 'in the admin, what\'s stored');

update_option('options_sl_cta', ['title' => 'Vai', 'url' => $url, 'target' => '']);
update_option('_options_sl_cta', 'field_wgt_sl_cta');
Core\set_synced_marks('en', ['sl_cta'], 'option');
Core\pull_fields('en', Core\default_language(), 'option');
same($url, get_option('options_en_sl_cta')['url'] ?? null, 'the options: stored as the default\'s has it');
same($link, $on_site(fn () => get_field('sl_cta', 'options'))['url'] ?? null, 'and read in English: the translation');
Core\set_synced_marks('en', [], 'option');
delete_option('options_en_sl_cta');
delete_option('_options_en_sl_cta');
delete_option('options_sl_cta');
delete_option('_options_sl_cta');


section('What the panel warns of');

$panel_it = wgt_page('Pannello', serialize_blocks([$parsed(['sl_cta' => $values['sl_cta'], '_sl_cta' => 'field_wgt_sl_cta'], [])]));
$panel_en = (int) WpGoLanguages::create_translation($panel_it, 'en', 'independent');
$panel = (array) parse_blocks((string) get_post_field('post_content', $panel_en))[0];
$save_panel = function (array $data, array $marks) use ($panel, $panel_en) {
    global $wpdb;
    $panel['attrs']['data'] = $data;
    $panel['attrs']['metadata'][Core\SYNCED_BLOCK] = $marks;
    $wpdb->update($wpdb->posts, ['post_content' => serialize_blocks([$panel])], ['ID' => $panel_en]);
    clean_post_cache($panel_en);
};
$kinds = fn () => array_column(Core\link_warnings($panel_en), 'kind');

$save_panel(['sl_cta' => $values['sl_cta'], '_sl_cta' => 'field_wgt_sl_cta'], ['sl_cta']);
same([], $kinds(), 'a synced link whose translation is published: nothing to say');
$save_panel(['sl_cta' => $values['sl_cta'], '_sl_cta' => 'field_wgt_sl_cta'], []);
same(['language'], $kinds(), 'the same address typed: in another language, as before');
wp_update_post(['ID' => $translation, 'post_status' => 'draft']);
$save_panel(['sl_cta' => $values['sl_cta'], '_sl_cta' => 'field_wgt_sl_cta'], ['sl_cta']);
same(['unpublished'], $kinds(), 'a synced link whose translation isn\'t published: told so');
wp_update_post(['ID' => $translation, 'post_status' => 'publish']);
$lone = wgt_page('Senza traduzione', '');
$save_panel(['sl_cta' => ['title' => 'Vai', 'url' => (string) get_permalink($lone), 'target' => ''], '_sl_cta' => 'field_wgt_sl_cta'], ['sl_cta']);
same(['missing'], $kinds(), 'a synced link with no translation: told so');


section('The stored values converted');

$old_it = wgt_page('Vecchio', serialize_blocks([$parsed(['sl_cta' => $values['sl_cta'], '_sl_cta' => 'field_wgt_sl_cta', 'sl_own' => $values['sl_own'], '_sl_own' => 'field_wgt_sl_own'], [])]));
$old_en = (int) WpGoLanguages::create_translation($old_it, 'en', 'independent');
wp_update_post(['ID' => $old_en, 'post_status' => 'publish']);
update_field('field_wgt_sl_cta', ['title' => 'Vai', 'url' => $url, 'target' => ''], $old_it);

// as a site stored them: the translation's address written in
$stored = function (int $id): array {
    return parse_blocks((string) get_post_field('post_content', $id))[0]['attrs']['data'] ?? [];
};
$as_before = (array) parse_blocks((string) get_post_field('post_content', $old_en))[0];
$as_before['attrs']['data']['sl_cta']['url'] = $link;
$as_before['attrs']['data']['sl_own']['url'] = 'https://example.invalid/own/';
$as_before['attrs']['metadata'][Core\SYNCED_BLOCK] = ['sl_cta'];
global $wpdb;
$wpdb->update($wpdb->posts, ['post_content' => serialize_blocks([$as_before])], ['ID' => $old_en]);
clean_post_cache($old_en);
update_post_meta($old_en, 'sl_cta', ['title' => 'Go', 'url' => $link, 'target' => '']);
update_post_meta($old_en, '_sl_cta', 'field_wgt_sl_cta');
Core\set_synced_marks($old_en, ['sl_cta']);

$revisions = count(wp_get_post_revisions($old_en));
$modified  = get_post_field('post_modified', $old_en);
$report    = Core\synced_links_report();
Core\convert_synced_links_now($report);
same($url, $stored($old_en)['sl_cta']['url'] ?? null, 'a block\'s: as its original has it');
same('https://example.invalid/own/', $stored($old_en)['sl_own']['url'] ?? null, 'what isn\'t synced stays');
same($url, get_post_meta($old_en, 'sl_cta', true)['url'] ?? null, 'a page\'s too');
check('counted', $report['posts'] >= 1 && $report['fields'] >= 1);
same($revisions, count(wp_get_post_revisions($old_en)), 'no revision: it isn\'t an edit');
same($modified, get_post_field('post_modified', $old_en), 'nor a new modified date');

$again = Core\synced_links_report();
Core\convert_synced_links_now($again);
same(0, $again['posts'] + $again['fields'] + $again['terms'] + $again['options'], 'run again: nothing\'s left');

$held_it = wgt_page('In attesa', serialize_blocks([$parsed(['sl_cta' => $values['sl_cta'], '_sl_cta' => 'field_wgt_sl_cta'], [])]));
$held_en = (int) WpGoLanguages::create_translation($held_it, 'en', 'independent');
wp_update_post(['ID' => $held_en, 'post_status' => 'publish']);
$as_held = (array) parse_blocks((string) get_post_field('post_content', $held_en))[0];
$as_held['attrs']['data']['sl_cta']['url'] = $link;
$as_held['attrs']['metadata'][Core\SYNCED_BLOCK] = ['sl_cta'];
$wpdb->update($wpdb->posts, ['post_content' => serialize_blocks([$as_held])], ['ID' => $held_en]);
clean_post_cache($held_en);
$wpdb->update($wpdb->posts, ['post_status' => 'draft'], ['ID' => $held_it]);
clean_post_cache($held_it);
$waiting = Core\synced_links_report();
Core\convert_synced_links_now($waiting);
same(1, $waiting['waiting'], 'a translation waiting for its original is counted');
same($link, $stored($held_en)['sl_cta']['url'] ?? null, 'and keeps what it has');


section('Resolved, for a move to WPML');

$report = Core\synced_links_report();
Core\resolve_synced_links_now($report);
same($link, $stored($old_en)['sl_cta']['url'] ?? null, 'a block\'s: what it points at');
same('https://example.invalid/own/', $stored($old_en)['sl_own']['url'] ?? null, 'what isn\'t synced stays');
same($link, get_post_meta($old_en, 'sl_cta', true)['url'] ?? null, 'a page\'s too');
check('counted', $report['posts'] >= 1 && $report['fields'] >= 1);

$again = Core\synced_links_report();
Core\resolve_synced_links_now($again);
same(0, $again['posts'] + $again['fields'] + $again['terms'] + $again['options'], 'run again: nothing\'s left');

// a move to WPML says so first
check('a plan warns while they aren\'t resolved', WpGo\Languages\Migrate\synced_fields_unresolved());
update_option(Core\SYNCED_RESOLVED_OPTION, 1, false);
check('and doesn\'t once they are', !WpGo\Languages\Migrate\synced_fields_unresolved());
delete_option(Core\SYNCED_RESOLVED_OPTION);


section('The conversions, in a wp command');

foreach (['synced links' => Core\SYNCED_LINKS_OPTION, 'text only' => Core\TEXT_ONLY_OPTION] as $name => $option) {
    update_option($option, 1, true);
    Core\run_conversions();
    same('1', (string) get_option($option), "$name: it waits, for its own command or the next request that may");
    update_option($option, 0, true);
}

check('this is a wp command', Core\conversion_waits());
