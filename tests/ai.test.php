<?php

/**
 * AI translation (src/Core/ai.php, ai-fields.php, src/Admin/ai.php): the
 * settings and their save, the prompt, the reply read and checked (its JSON,
 * a thinking model's tags, the HTML and placeholders kept, a second ask for
 * what failed), a translation's fields (the post's, core and ACF blocks',
 * Yoast's, a term's) with their sources, the writing back (a fixed
 * translation's blocks, a draft's slug, a link's title), and the routes.
 *
 * The endpoint is faked (`pre_http_request`): `$reply` answers each request
 * from its texts. No model is called.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;

$settings = ['endpoint' => 'http://ai.test/v1', 'model' => 'qwen', 'key' => '', 'instructions' => 'Never translate “We-Go”.'];
$on = fn () => $settings;
add_filter('pre_option_' . Core\AI_OPTION, function () use (&$settings) {
    return $settings;
});

// the fake endpoint: each request kept, answered by $reply(texts, request)
$requests = [];
$reply = fn (array $texts) => wp_json_encode(array_map(fn ($text) => "EN:$text", $texts));
add_filter('pre_http_request', function ($pre, $args, $url) use (&$requests, &$reply) {
    if (!str_starts_with($url, 'http://ai.test/')) {
        return $pre;
    }

    $body = json_decode($args['body'], true);
    $requests[] = ['url' => $url, 'body' => $body, 'headers' => $args['headers']];
    $content = $reply(json_decode(end($body['messages'])['content'], true), $body);

    if ($content instanceof WP_Error) {
        return $content;
    }

    return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode(['choices' => [['message' => ['content' => $content]]]]), 'cookies' => []];
}, 10, 3);

$rest = function (string $method, string $route, array $params = []) {
    $request = new WP_REST_Request($method, $route);
    $method === 'GET' ? $request->set_query_params($params) : $request->set_body_params($params);
    $response = rest_do_request($request);

    return [$response->get_status(), json_decode(wp_json_encode($response->get_data()), true)];
};

section('Settings');

check('set up: an endpoint and a model', Core\ai_enabled());
$settings['model'] = '';
check('no model: off', !Core\ai_enabled());
$settings['model'] = 'qwen';

add_filter('wp_go_languages/ai', $local = fn ($ai) => ['endpoint' => ' http://127.0.0.1:8080/v1 '] + $ai);
same('http://127.0.0.1:8080/v1', Core\ai_settings()['endpoint'], 'the code sets it per environment, trimmed');
remove_filter('wp_go_languages/ai', $local);

same('Italiano (it_IT)', Core\ai_language_name('it'), 'a language named as the prompt says it');
same('English (en_GB)', Core\ai_language_name('en'), 'English, a site language here, with its locale');
same('xx', Core\ai_language_name('xx'), 'an unknown code as it is');

// the screen's save: in its own option, the key kept when left empty
remove_all_filters('pre_option_' . Core\AI_OPTION);
update_option(Core\AI_OPTION, ['endpoint' => 'http://old', 'model' => 'm', 'key' => 'secret', 'instructions' => '']);
Admin\save_ai_settings(['endpoint' => 'http://127.0.0.1:8080/v1', 'model' => ' qwen ', 'key' => '', 'instructions' => "Tono amichevole.\nNiente."]);
same(['endpoint' => 'http://127.0.0.1:8080/v1', 'model' => 'qwen', 'key' => 'secret', 'instructions' => "Tono amichevole.\nNiente."], get_option(Core\AI_OPTION), 'saved: a key left empty stays');
Admin\save_ai_settings(['endpoint' => 'http://127.0.0.1:8080/v1', 'model' => 'qwen', 'key' => 'new', 'remove_key' => '1']);
same('', get_option(Core\AI_OPTION)['key'], '"Remove the saved key" removes it, whatever is typed');
add_filter('wp_go_languages/ai', $coded = fn ($ai) => ['endpoint' => 'http://staging.test/v1'] + $ai);
Admin\save_ai_settings(['endpoint' => 'http://elsewhere/v1', 'model' => 'x']);
same('qwen', get_option(Core\AI_OPTION)['model'], 'set in code: the screen\'s save leaves the option alone');
remove_filter('wp_go_languages/ai', $coded);
update_option(Core\AI_OPTION, get_option(Core\AI_OPTION) + ['legacy' => 'x']);
check('another key in the option (written by hand) isn\'t taken for the code setting them', !Core\ai_set_in_code());
add_filter('wp_go_languages/ai', $coded);
check('and the section is greyed out, saying so', str_contains(wgt_output('WpGo\\Languages\\Admin\\ai_settings_section'), 'disabled') && str_contains(wgt_output('WpGo\\Languages\\Admin\\ai_settings_section'), 'wp_go_languages/ai'));
remove_filter('wp_go_languages/ai', $coded);
$html = wgt_output('WpGo\\Languages\\Admin\\ai_settings_section');
check('the section shows the endpoint, never the key', str_contains($html, 'value="http://127.0.0.1:8080/v1"') && str_contains($html, 'name="ai[key]" value=""'));
add_filter('pre_option_' . Core\AI_OPTION, function () use (&$settings) {
    return $settings;
});

section('The prompt and the reply');

$messages = Core\ai_messages(['a' => 'Ciao'], 'it', 'en', 'the page “Home”');
check('the system prompt names the languages, what to keep, the instructions and the context', str_contains($messages[0]['content'], 'from Italiano (it_IT) to English (en_GB)') && str_contains($messages[0]['content'], 'Keep every HTML tag') && str_contains($messages[0]['content'], 'Never translate “We-Go”.') && str_contains($messages[0]['content'], 'the page “Home”'));
same('{"a":"Ciao"}', $messages[1]['content'], 'the texts as a JSON object, unicode and slashes as they are');

same(['a' => 'Hi'], Core\ai_json("<think>let me see {x}</think>\n```json\n{\"a\": \"Hi\"}\n```"), 'a thinking model\'s tags and a Markdown fence dropped');
same(null, Core\ai_json('Sorry, I can\'t.'), 'no JSON: null');

same(null, Core\ai_check('<p>Ciao <a href="/x">qui</a></p>', '<p>Hi <a href="/x">here</a></p>'), 'the text between tags translated: kept');
same(null, Core\ai_check('<img src="a.jpg" alt="Gatto">', '<img src="a.jpg" alt="Cat">'), 'an alt translated: kept');
same('Its HTML changed.', Core\ai_check('<p>Ciao <a href="/x">qui</a></p>', '<p>Hi <a href="/y">here</a></p>'), 'a link\'s address changed: refused');
same('Its HTML changed.', Core\ai_check('<p>Ciao <strong>tu</strong></p>', '<p>Hi you</p>'), 'a tag dropped: refused');
same('Its placeholders changed.', Core\ai_check('Ciao %s, %%sitename%%', 'Hi %d, %%sitename%%'), 'a placeholder changed: refused');
same(null, Core\ai_check('Ciao %1$s e {name}', 'Hi {name} and %1$s'), 'placeholders moved: kept');
same(null, Core\ai_check('Riga<br>dopo <img src="a.jpg"  alt="x">', 'Line<br />after <img src="a.jpg" alt="y"/>'), 'how a tag is written doesn\'t count: <br> is <br />');
same('Empty.', Core\ai_check('Ciao', '  '), 'empty for a text that isn\'t: refused');
same('Missing from the answer.', Core\ai_check('Ciao', null), 'missing: refused');

section('Asking');

$done = Core\ai_translate(['a' => 'Ciao', 'b' => '<p>Mondo</p>', 'empty' => ' '], 'it', 'en');
same(['translated' => ['empty' => ' ', 'a' => 'EN:Ciao', 'b' => 'EN:<p>Mondo</p>'], 'failed' => []], $done, 'each text translated; an empty one not asked');
same('http://ai.test/v1/chat/completions', $requests[0]['url'], 'to the endpoint\'s chat/completions');
same(['qwen', 0.2, ['type' => 'json_object']], [$requests[0]['body']['model'], $requests[0]['body']['temperature'], $requests[0]['body']['response_format']], 'the model, a low temperature, a JSON answer');
check('no key, no Authorization', !isset($requests[0]['headers']['Authorization']));

$settings['key'] = 'sk-test';
$requests = [];
Core\ai_translate(['a' => 'Ciao'], 'it', 'en');
same('Bearer sk-test', $requests[0]['headers']['Authorization'] ?? '', 'a key as a Bearer token');
$settings['key'] = '';

// the first answer breaks b's HTML and leaves c out: those two asked again
$requests = [];
$reply = function (array $texts) {
    static $round = 0;
    $round++;

    return wp_json_encode($round === 1 ? ['a' => 'Hi', 'b' => 'World'] : array_map(fn ($text) => $text === '<p>Mondo</p>' ? '<p>World</p>' : 'Too', $texts));
};
$done = Core\ai_translate(['a' => 'Ciao', 'b' => '<p>Mondo</p>', 'c' => 'Troppo'], 'it', 'en');
same(['a' => 'Hi', 'b' => '<p>World</p>', 'c' => 'Too'], $done['translated'], 'what failed is asked once more, and kept the second time');
same(['b', 'c'], array_keys(json_decode(end($requests[1]['body']['messages'])['content'], true)), 'only those');

$reply = fn (array $texts) => wp_json_encode(array_map(fn () => '', $texts));
same(['a' => 'Empty.'], Core\ai_translate(['a' => 'Ciao'], 'it', 'en')['failed'], 'twice wrong: reported, with why');

$reply = fn () => 'No JSON here.';
same(['a' => 'The answer wasn\'t JSON.'], Core\ai_translate(['a' => 'Ciao'], 'it', 'en')['failed'], 'no JSON twice: every text failed');

$reply = fn () => new WP_Error('http_request_failed', 'Connection refused');
$error = Core\ai_translate(['a' => 'Ciao'], 'it', 'en');
check('the endpoint unreachable: an error saying so', is_wp_error($error) && str_contains($error->get_error_message(), 'Connection refused'));
$reply = fn (array $texts) => wp_json_encode(array_map(fn ($text) => "EN:$text", $texts));

section('A translation\'s fields');

$content = serialize_blocks([
    wgt_acf('hero', ['title' => ['text' => 'Cosa sta cambiando?', 'tag' => 'h1'], 'text' => '<p>Una creative company.</p>']),
    wgt_para('Ciao <em>mondo</em>'),
    ['blockName' => 'core/image', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<figure class="wp-block-image"><img src="a.jpg" alt="Gatto"/></figure>', 'innerContent' => ['<figure class="wp-block-image"><img src="a.jpg" alt="Gatto"/></figure>']],
    ['blockName' => 'core/separator', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<hr class="wp-block-separator"/>', 'innerContent' => ['<hr class="wp-block-separator"/>']],
    ['blockName' => 'core/html', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<div>Codice</div>', 'innerContent' => ['<div>Codice</div>']],
]);
$it = wgt_page('Prova AI', $content, 'it', ['post_excerpt' => 'Un riassunto.']);
update_post_meta($it, '_yoast_wpseo_title', 'Prova %%sep%% %%sitename%%');
$en = WpGoLanguages::create_translation($it, 'en', 'blocks');

$fields = array_column(Core\ai_fields($en), null, 'key');
$keys = array_keys($fields);
same(['post:title', 'post:excerpt'], array_slice($keys, 0, 2), 'the post\'s title and excerpt first');
check('an ACF block\'s text fields', (bool) array_filter($keys, fn ($key) => preg_match('#^acf:[^/]+/title_text$#', $key)) && (bool) array_filter($keys, fn ($key) => preg_match('#^acf:[^/]+/text$#', $key)));
same(1, count(array_filter($keys, fn ($key) => str_starts_with($key, 'block:') && str_contains($fields[$key]['source'], '<em>mondo</em>'))), 'a paragraph, its HTML whole');
check('an image, for its alt', (bool) array_filter($fields, fn ($field) => str_contains($field['source'], 'alt="Gatto"')));
check('not a separator (no text), nor a Custom HTML block', !array_filter($fields, fn ($field) => str_contains($field['source'], '<hr') || str_contains($field['source'], 'Codice')));
same('seo', $fields['meta:_yoast_wpseo_title']['group'] ?? '', 'Yoast\'s title, under SEO');
check('a copy\'s fields are all untranslated, their source the original\'s', array_filter($fields, fn ($field) => !$field['untranslated']) === [] && $fields['post:title']['source'] === 'Prova AI');
same([], Core\ai_fields(999999999), 'no post, no fields');

section('Writing them back');

$values = array_map(fn ($field) => str_replace(['Prova AI', 'Cosa sta cambiando?', 'Una creative company.', 'Ciao <em>mondo</em>', 'alt="Gatto"', 'Un riassunto.', 'Prova %%sep%%'], ['AI test', 'What is changing?', 'A creative company.', 'Hello <em>world</em>', 'alt="Cat"', 'A summary.', 'Test %%sep%%'], $field['source']), $fields);
$written = Core\ai_write($en, $values + ['post:nothing' => 'x', 'meta:_not_a_field' => 'x']);
same(count($fields), count($written), 'every field written, and nothing that isn\'t one of the post\'s fields');
$post = get_post($en);
same(['AI test', 'A summary.', 'ai-test'], [$post->post_title, $post->post_excerpt, $post->post_name], 'the title, the excerpt, and the draft\'s slug from its new title');
check('the blocks\' texts in its content', str_contains($post->post_content, 'Hello <em>world</em>') && str_contains($post->post_content, 'alt="Cat"') && str_contains($post->post_content, 'What is changing?'));
same('Test %%sep%% %%sitename%%', get_post_meta($en, '_yoast_wpseo_title', true), 'Yoast\'s title, as meta');
same([], array_keys(array_filter(Core\ai_fields($en), fn ($field) => $field['untranslated'])), 'nothing left untranslated');
check('the original is untouched', get_post_field('post_title', $it) === 'Prova AI' && str_contains(get_post_field('post_content', $it), 'Ciao <em>mondo</em>'));

wp_update_post(['ID' => $en, 'post_status' => 'publish']);
Core\ai_write($en, ['post:title' => 'Another title']);
same('ai-test', get_post_field('post_name', $en), 'a published page keeps its slug');

section('A term\'s');

register_taxonomy('wgt_ai_topic', 'page', ['public' => true, 'show_ui' => true]);
add_filter('wp_go_languages/config', fn ($config) => array_replace($config, ['taxonomies' => [...$config['taxonomies'], 'wgt_ai_topic']]));
Core\config(true);
$term_it = (int) WpGoLanguages::in('it', fn () => wp_insert_term('Notizie', 'wgt_ai_topic', ['description' => 'Le ultime.'])['term_id']);
$term_en = Core\create_term_translation($term_it, 'en');
same(['term:name', 'term:description'], array_column(Core\ai_term_fields($term_en), 'key'), 'its name and description');
same(['term:name', 'term:description'], Core\ai_write_term($term_en, ['term:name' => 'News', 'term:description' => 'The latest.']), 'written');
same(['News', 'The latest.'], [get_term($term_en)->name, get_term($term_en)->description], 'as the term\'s');

section('The routes');

[$status, $data] = $rest('GET', "/wgl/v1/posts/$it/ai-fields");
same([400, 'wgl_ai_original'], [$status, $data['code'] ?? ''], 'an original has nothing to translate from');

$page = wgt_page('Rotta AI', serialize_block(wgt_para('Buongiorno')));
$page_en = WpGoLanguages::create_translation($page, 'en', 'blocks');
[$status, $data] = $rest('GET', "/wgl/v1/posts/$page_en/ai-fields");
same([200, 'it', 'en', 'Italiano', 'English'], [$status, $data['from'], $data['to'], $data['from_name'], $data['to_name']], 'a translation\'s: from the original\'s language to its own');
same(['post:title'], array_slice(array_column($data['fields'], 'key'), 0, 1), 'with its fields');

$requests = [];
[$status, $data] = $rest('POST', "/wgl/v1/posts/$page_en/ai-translate", ['keys' => ['post:title', 'post:nothing']]);
same([200, ['post:title'], []], [$status, $data['written'], $data['failed']], 'the keys asked, translated and written');
same('EN:Rotta AI', get_post_field('post_title', $page_en), 'saved');
check('the prompt says which page', str_contains($requests[0]['body']['messages'][0]['content'], 'the page “Rotta AI”'));

$reply = fn () => new WP_Error('http_request_failed', 'Connection refused');
[$status, $data] = $rest('POST', "/wgl/v1/posts/$page_en/ai-translate", ['keys' => ['post:title']]);
same(502, $status, 'the endpoint unreachable: a 502, with its message');
$reply = fn (array $texts) => wp_json_encode(array_map(fn ($text) => "EN:$text", $texts));

[$status, $data] = $rest('GET', "/wgl/v1/terms/$term_en/ai-fields");
same([200, 2], [$status, count($data['fields'])], 'a term\'s fields');

[$status, $data] = $rest('POST', '/wgl/v1/ai/texts', ['texts' => ['k1' => 'Carica altri'], 'from' => 'it', 'to' => 'en']);
same([200, ['k1' => 'EN:Carica altri']], [$status, $data['translated']], 'texts translated, not written (Translation strings)');

[$status, $data] = $rest('POST', '/wgl/v1/ai/test', ['endpoint' => 'http://ai.test/v1', 'model' => 'qwen']);
same([200, 'EN:Buongiorno! Questa è una prova di traduzione.'], [$status, $data['translation'] ?? ''], 'the test: the screen\'s fields, a sentence translated');

$settings['endpoint'] = '';
[$status, $data] = $rest('GET', "/wgl/v1/posts/$page_en/ai-fields");
same([400, 'wgl_ai_off'], [$status, $data['code'] ?? ''], 'not set up: refused');
$settings['endpoint'] = 'http://ai.test/v1';

$editor = wp_insert_user(['user_login' => 'wgt_ai_contrib', 'user_pass' => wp_generate_password(), 'role' => 'contributor']);
$admin = get_current_user_id();
wp_set_current_user($editor);
same(403, $rest('GET', "/wgl/v1/posts/$page_en/ai-fields")[0], 'a user who can\'t edit the page: refused');
same(403, $rest('POST', '/wgl/v1/ai/test', [])[0], 'and the test is for administrators');
wp_set_current_user($admin);

section('Translated, not written: the editor\'s');

$draft = wgt_page('Bozza AI', serialize_block(wgt_para('Ciao')));
$draft_en = WpGoLanguages::create_translation($draft, 'en', 'blocks');
[$status, $data] = $rest('POST', "/wgl/v1/posts/$draft_en/ai-translate", ['keys' => ['post:title'], 'write' => false]);
same([200, [], ['post:title' => 'EN:Bozza AI']], [$status, $data['written'], $data['translated']], 'write false: the translations answered, none written');
same('Bozza AI', get_post_field('post_title', $draft_en), 'the page as it was: the editor\'s save saves them');

[, $data] = $rest('GET', "/wgl/v1/posts/$draft_en/ai-fields");
check('a draft whose slug is the original\'s: it follows the new title', $data['slug_follows'] === true);
wp_update_post(['ID' => $draft_en, 'post_name' => 'its-own']);
[, $data] = $rest('GET', "/wgl/v1/posts/$draft_en/ai-fields");
check('a slug of its own: it stays', $data['slug_follows'] === false);

// a page's ACF fields, with their keys and their place among those of a key
acf_add_local_field_group([
    'key'      => 'group_wgt_ai',
    'title'    => 'AI test',
    'fields'   => [
        ['key' => 'field_wgt_ai_intro', 'name' => 'wgt_ai_intro', 'type' => 'text'],
        ['key' => 'field_wgt_ai_rows', 'name' => 'wgt_ai_rows', 'type' => 'repeater', 'sub_fields' => [
            ['key' => 'field_wgt_ai_rows_title', 'name' => 'title', 'type' => 'text'],
        ]],
    ],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
]);
$fielded = wgt_page('Campi AI', serialize_block(wgt_para('Ciao')));
update_field('field_wgt_ai_intro', 'Introduzione', $fielded);
update_field('field_wgt_ai_rows', array_map(fn ($i) => ['field_wgt_ai_rows_title' => "Riga $i"], range(0, 11)), $fielded);
$fielded_en = WpGoLanguages::create_translation($fielded, 'en', 'blocks');
$byKey = array_column(Core\ai_fields($fielded_en), null, 'key');
same(['field_wgt_ai_intro', 0], [$byKey['meta:wgt_ai_intro']['field'] ?? '', $byKey['meta:wgt_ai_intro']['nth'] ?? -1], 'a field\'s ACF key, the first with it');
same([2, 10, 11], [$byKey['meta:wgt_ai_rows_2_title']['nth'], $byKey['meta:wgt_ai_rows_10_title']['nth'], $byKey['meta:wgt_ai_rows_11_title']['nth']], 'a repeater\'s cells in their rows\' order, by number (10 after 2)');
same('field_wgt_ai_rows_title', $byKey['meta:wgt_ai_rows_10_title']['field'], 'the sub-field\'s key');

section('Blocks with no ID yet: by their place');

// as a page made before IDs were given, and not saved since (a save stamps them)
$bare = fn (array $texts) => "<!-- wp:paragraph -->\n<p>{$texts[0]}</p>\n<!-- /wp:paragraph -->\n\n"
    . "<!-- wp:group --><div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>{$texts[1]}</p>\n<!-- /wp:paragraph --></div><!-- /wp:group -->";
$old_it = wgt_page('Vecchia AI', serialize_block(wgt_para('x')));
$old_en = WpGoLanguages::create_translation($old_it, 'en');
wgt_raw_content($old_it, $bare(['Primo', 'Secondo']));
wgt_raw_content($old_en, $bare(['Primo', 'Secondo']));
clean_post_cache($old_it);
clean_post_cache($old_en);

$old = array_column(Core\ai_fields($old_en), null, 'key');
same(['post:title', 'block:@0', 'block:@1.0'], array_keys($old), 'each by its place, the whitespace between blocks not counted, a container\'s inner block too');
same('<p>Secondo</p>', $old['block:@1.0']['source'], 'its source the original\'s block at the same place');

[, $data] = $rest('POST', "/wgl/v1/posts/$old_en/ai-translate", ['keys' => ['block:@1.0'], 'write' => false]);
same(['block:@1.0' => 'EN:<p>Secondo</p>'], $data['translated'], 'translated by that key, for the editor to find');

same(['block:@1.0'], Core\ai_write($old_en, ['block:@1.0' => '<p>Second</p>']), 'and written by it');
check('into the right block', str_contains(get_post_field('post_content', $old_en), '<p>Primo</p>') && str_contains(get_post_field('post_content', $old_en), '<p>Second</p>'));

// another kind of block at the original's place: no source from it
wgt_raw_content($old_it, "<!-- wp:heading --><h2>Titolo</h2><!-- /wp:heading -->");
clean_post_cache($old_it);
wgt_raw_content($old_en, "<!-- wp:paragraph --><p>Proprio</p><!-- /wp:paragraph -->");
clean_post_cache($old_en);
same('<p>Proprio</p>', array_column(Core\ai_fields($old_en), null, 'key')['block:@0']['source'], 'a heading where the paragraph is: its own text is the source');

section('The screens');

Admin\list_scripts();
$before = (array) wp_scripts()->get_data('wgl-list', 'before');
check('the lists\' dialog is told AI is there, with its texts', str_contains(end($before), '"ai":true') && str_contains(end($before), '"aiOption"'));

wgt_cleanup();
