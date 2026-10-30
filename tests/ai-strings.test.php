<?php

/**
 * "Translate empty with AI" on the Translation strings screen: its button,
 * status and script only with AI set up (and for who uses the screen), on
 * both tabs, and the route it calls, wgl/v1/ai/texts, answered by a faked
 * endpoint (pre_http_request): never the real model.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;

$ai = [];
add_filter('pre_option_' . Core\AI_OPTION, function () use (&$ai) {
    return $ai;
});

// the endpoint: each text back as "[to] text", or what $reply says
$reply = null;
$asked = [];
add_filter('pre_http_request', function ($pre, $args, $url) use (&$reply, &$asked) {
    if (!str_contains($url, '/chat/completions')) {
        return $pre;
    }

    $body = json_decode($args['body'], true);
    $asked[] = ['url' => $url, 'body' => $body];

    if ($reply instanceof WP_Error) {
        return $reply;
    }

    $texts = json_decode($body['messages'][1]['content'], true);
    // "(en)", not "[en]": a word in brackets is a mail tag, kept as a placeholder
    $content = $reply ?? wp_json_encode(array_map(fn ($text) => "(en) $text", $texts));

    return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode(['choices' => [['message' => ['content' => $content]]]])];
}, 10, 3);

$route = function (array $params) {
    $request = new WP_REST_Request('POST', '/wgl/v1/ai/texts');
    $request->set_body_params($params);

    return rest_do_request($request);
};

$admin = get_current_user_id();
$hook = 'toplevel_page_' . WP_GO_SITE_TEXTS_PAGE;

section('Without AI set up');

check('not offered', !wp_go_site_texts_ai_available());
same('', wgt_output('wp_go_site_texts_render_ai_button'), 'no button');
same('', wgt_output('wp_go_site_texts_render_ai_status'), 'no status');
wp_go_site_texts_assets($hook);
check('no script', !wp_script_is('wgl-site-texts-ai', 'enqueued'));
same(400, $route(['texts' => ['a' => 'Ciao'], 'from' => 'it', 'to' => 'en'])->get_status(), 'the route says it isn\'t set up');

$ai = ['endpoint' => 'http://ai.test/v1', 'model' => 'qwen'];

section('With AI set up');

check('offered', wp_go_site_texts_ai_available());
check('the button, beside the title', str_contains(wgt_output('wp_go_site_texts_render_ai_button'), 'class="page-title-action wg-site-texts__ai"'));
check('and where it says how it\'s going', str_contains(wgt_output('wp_go_site_texts_render_ai_status'), 'wg-site-texts__ai-status'));

$_GET = ['page' => WP_GO_SITE_TEXTS_PAGE];
check('on the Site strings tab', str_contains(wgt_output(fn () => wp_go_site_texts_render_header(wp_go_site_texts_view())), 'wg-site-texts__ai-status'));
$_GET = ['page' => WP_GO_SITE_TEXTS_PAGE, 'tab' => 'admin'];
$html = wgt_output('wp_go_admin_strings_render');
check('and on the Admin strings tab', str_contains($html, 'wg-site-texts__ai"') && str_contains($html, 'wg-site-texts__ai-status'));
$_GET = [];

wp_go_site_texts_assets($hook);
check('its script, after the screen\'s', wp_script_is('wgl-site-texts-ai', 'enqueued') && in_array('wgl-site-texts', wp_scripts()->registered['wgl-site-texts-ai']->deps, true));
$data = implode('', (array) wp_scripts()->get_data('wgl-site-texts-ai', 'before'));
check('with the route, a nonce and the default language', str_contains($data, 'wgl\\/v1\\/ai\\/texts') && str_contains($data, '"nonce"') && str_contains($data, '"fallback":"it"'));

$ai = ['endpoint' => 'http://ai.test/v1'];
check('an endpoint without a model isn\'t set up', !wp_go_site_texts_ai_available());
$ai = ['endpoint' => 'http://ai.test/v1', 'model' => 'qwen'];

$subscriber = wp_insert_user(['user_login' => 'wgt_ai_strings_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
wp_set_current_user($subscriber);
check('not for a user who can\'t use the screen', !wp_go_site_texts_ai_available());
same(403, $route(['texts' => ['a' => 'Ciao'], 'from' => 'it', 'to' => 'en'])->get_status(), 'nor the route');
wp_set_current_user($admin);

section('The route');

$asked = [];
$response = $route(['texts' => ['a.one' => 'Ciao', 'a.two' => 'Due <strong>volte</strong>'], 'from' => 'it', 'to' => 'en']);
same(200, $response->get_status(), 'answers');
same(['a.one' => '(en) Ciao', 'a.two' => '(en) Due <strong>volte</strong>'], (array) $response->get_data()['translated'], 'each text, translated');
same('http://ai.test/v1/chat/completions', $asked[0]['url'] ?? '', 'asked of the endpoint set up');
same('qwen', $asked[0]['body']['model'] ?? '', 'with its model');

$reply = wp_json_encode(['a' => 'Hi', 'b' => 'Twice']);
$response = $route(['texts' => ['a' => 'Ciao', 'b' => 'Due <strong>volte</strong>'], 'from' => 'it', 'to' => 'en'])->get_data();
same(['a' => 'Hi'], (array) $response['translated'], 'a translation that lost its HTML isn\'t kept');
check('and says why', str_contains((string) (((array) $response['failed'])['b'] ?? ''), 'HTML'));
$reply = null;

$reply = new WP_Error('http_request_failed', 'Connection refused');
same(502, $route(['texts' => ['a' => 'Ciao'], 'from' => 'it', 'to' => 'en'])->get_status(), 'an endpoint that doesn\'t answer: 502');
$reply = null;

$asked = [];
$route(['texts' => ['a' => 'Save'], 'from' => 'en', 'to' => 'it']);
check('from English, the admin strings\' language', (bool) preg_match('/from English( \(en_GB\))? to Italiano \(it_IT\)/', (string) ($asked[0]['body']['messages'][0]['content'] ?? '')));

wgt_cleanup();
