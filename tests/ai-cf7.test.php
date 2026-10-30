<?php

/**
 * AI translation of a Contact Form 7 form (src/Core/ai-fields.php): its
 * mails' subjects and bodies (a mail's MJML instead while wp-go's cf7-mjml
 * writes the body; Mail (2) only while it's on), its messages, their source
 * the original form's; a condition's value left out; the writing back into
 * CF7's meta, an MJML source marked `stale`; mail tags kept as placeholders;
 * and the prompt naming the form.
 *
 * The endpoint is faked (`pre_http_request`), as in ai.test.php.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Core;

$settings = ['endpoint' => 'http://ai.test/v1', 'model' => 'qwen', 'key' => '', 'instructions' => ''];
add_filter('pre_option_' . Core\AI_OPTION, function () use (&$settings) {
    return $settings;
});

$requests = [];
$reply = fn (array $texts) => wp_json_encode(array_map(fn ($text) => "EN:$text", $texts));
add_filter('pre_http_request', function ($pre, $args, $url) use (&$requests, &$reply) {
    if (!str_starts_with($url, 'http://ai.test/')) {
        return $pre;
    }

    $body = json_decode($args['body'], true);
    $requests[] = ['body' => $body];
    $content = $reply(json_decode(end($body['messages'])['content'], true), $body);

    return ['response' => ['code' => 200, 'message' => 'OK'], 'headers' => [], 'body' => wp_json_encode(['choices' => [['message' => ['content' => $content]]]]), 'cookies' => []];
}, 10, 3);

$rest = function (string $method, string $route, array $params = []) {
    $request = new WP_REST_Request($method, $route);
    $method === 'GET' ? $request->set_query_params($params) : $request->set_body_params($params);
    $response = rest_do_request($request);

    return [$response->get_status(), json_decode(wp_json_encode($response->get_data()), true)];
};

/** A CF7 form, its mails and messages, in a language. */
function wgt_ai_cf7_form(string $title, string $code, array $mail, array $mail_2, array $messages): int
{
    $form = WPCF7_ContactForm::get_template(['title' => $title]);
    $form->set_properties(['form' => '[text* your-name] [submit "Invia"]', 'mail' => $mail, 'mail_2' => $mail_2, 'messages' => $messages]);
    $id = (int) $form->save();
    WpGoLanguages::set_language($id, $code);

    return $id;
}

$mail = fn (string $subject, string $body, bool $html = false) => ['subject' => $subject, 'sender' => '[_site_title] <wordpress@example.com>', 'body' => $body, 'recipient' => '[_site_admin_email]', 'additional_headers' => 'Reply-To: [your-email]', 'attachments' => '', 'use_html' => $html, 'exclude_blank' => false];

$it = wgt_ai_cf7_form(
    'Contatti AI',
    'it',
    $mail('[_site_title] — nuovo contatto: [your-name]', "Da: [your-name] <[your-email]>\n\nMessaggio:\n[your-message]"),
    ['active' => true] + $mail('Grazie, [your-name]', '<html>grazie</html>', true),
    ['mail_sent_ok' => 'Grazie per il messaggio.', 'wg_required' => 'Campo obbligatorio.', 'empty_one' => '']
);
update_post_meta($it, '_wp_go_mail_mjml', ['mail' => ['enabled' => false, 'source' => ''], 'mail_2' => ['enabled' => true, 'source' => '<mjml><mj-body><mj-text>Grazie, [your-name]</mj-text></mj-body></mjml>']]);

$en = WpGoLanguages::create_translation($it, 'en');
check('the form\'s translation is made', is_int($en));

// as copied, then the translation's own: Mail (2) and its MJML on, one message translated
update_post_meta($en, '_mail_2', ['active' => true] + $mail('Grazie, [your-name]', '<html>grazie</html>', true));
update_post_meta($en, '_messages', ['mail_sent_ok' => 'Grazie per il messaggio.', 'wg_required' => 'Required field.', 'empty_one' => '']);
update_post_meta($en, '_wp_go_mail_mjml', ['mail' => ['enabled' => false, 'source' => ''], 'mail_2' => ['enabled' => true, 'source' => '<mjml><mj-body><mj-text>Grazie, [your-name]</mj-text></mj-body></mjml>']]);

// a form field's label, and a condition's value (one of a field's options), as ACF keeps them
update_post_meta($en, 'cf7_fields', ['text']);
update_post_meta($en, '_cf7_fields', 'field_wg_cf7_fields');
update_post_meta($en, 'cf7_fields_0_label', 'Nome');
update_post_meta($en, '_cf7_fields_0_label', 'field_wg_cf7_text_label');
update_post_meta($en, 'cf7_fields_0_conditions_0_value', 'Sì');
update_post_meta($en, '_cf7_fields_0_conditions_0_value', 'field_wg_cf7_text_conditions_value');

section('The fields');

$fields = array_column(Core\ai_fields($en), null, 'key');

foreach (['cf7:mail/subject', 'cf7:mail/body', 'cf7:mail_2/subject', 'cf7:mail_2/mjml', 'cf7:messages/mail_sent_ok', 'cf7:messages/wg_required'] as $key) {
    check("offered: $key", isset($fields[$key]));
}

check('Mail (2)\'s body isn\'t: its MJML writes it', !isset($fields['cf7:mail_2/body']));
check('nor an empty message', !isset($fields['cf7:messages/empty_one']));
check('a form field\'s label is: a text of its own', isset($fields['meta:cf7_fields_0_label']));
check('nor a condition\'s value: it follows its field\'s options', !isset($fields['meta:cf7_fields_0_conditions_0_value']));
check('nor the form\'s content, which is CF7\'s own', !isset($fields['post:content']));
same(['mail', 'mail', 'mail'], [$fields['cf7:mail/subject']['group'], $fields['cf7:mail/body']['group'], $fields['cf7:mail_2/mjml']['group']], 'the mails in the Mail group');
same('messages', $fields['cf7:messages/mail_sent_ok']['group'], 'the messages in Messages');
same('Mail › Subject', $fields['cf7:mail/subject']['label'], 'a mail\'s part, labelled by its mail');
same('Mail (2) › MJML', $fields['cf7:mail_2/mjml']['label'], 'Mail (2)\'s MJML');
same([false, true], [$fields['cf7:mail/body']['html'], $fields['cf7:mail_2/mjml']['html']], 'a plain-text body isn\'t HTML; MJML is');
check('a message labelled by CF7\'s description', $fields['cf7:messages/mail_sent_ok']['label'] !== '' && $fields['cf7:messages/mail_sent_ok']['label'] !== 'mail_sent_ok');
same('mail_sent_ok', $fields['cf7:messages/mail_sent_ok']['hint'], 'its key as the hint');
same([true, false], [$fields['cf7:messages/mail_sent_ok']['untranslated'], $fields['cf7:messages/wg_required']['untranslated']], 'a message still the original\'s is untranslated; one of its own isn\'t');
same('Campo obbligatorio.', $fields['cf7:messages/wg_required']['source'], 'the source is the original\'s');

$off = WpGoLanguages::create_translation($it, 'de');

if (is_int($off)) {
    update_post_meta($off, '_mail_2', ['active' => false] + $mail('Danke', 'danke'));
    check('Mail (2) off: none of its fields', !array_filter(array_column(Core\ai_fields($off), 'key'), fn ($key) => str_starts_with($key, 'cf7:mail_2/')));
}

section('Mail tags are placeholders');

same(['[_site_title]', '[your-name]'], Core\ai_placeholders('[_site_title] — nuovo contatto: [your-name]'), 'a mail\'s tags are its placeholders');
same(null, Core\ai_check('[_site_title] — nuovo contatto: [your-name]', '[_site_title] — new contact: [your-name]'), 'kept: the translation passes');
same('Its placeholders changed.', Core\ai_check('Grazie, [your-name]', 'Thanks, [tuo-nome]'), 'a tag translated: refused');
same(null, Core\ai_check("Da: [your-name] <[your-email]>\n[your-message]", "From: [your-name] <[your-email]>\n[your-message]"), 'a plain-text body\'s <[your-email]> is no HTML tag');
same(null, Core\ai_check('<html><body><p>Grazie, [your-name]</p></body></html>', '<html><body><p>Thanks, [your-name]</p></body></html>'), 'an HTML body\'s markup checks pass');
same(null, Core\ai_check('<mjml><mj-body><mj-text font-size="15px">Grazie</mj-text></mj-body></mjml>', '<mjml><mj-body><mj-text font-size="15px">Thanks</mj-text></mj-body></mjml>'), 'and an MJML source\'s');

section('Translated and written');

$requests = [];
$keys = ['cf7:mail/subject', 'cf7:mail/body', 'cf7:mail_2/subject', 'cf7:mail_2/mjml', 'cf7:messages/mail_sent_ok', 'meta:cf7_fields_0_conditions_0_value'];
[$status, $data] = $rest('POST', "/wgl/v1/posts/$en/ai-translate", ['keys' => $keys]);
same(200, $status, 'the route answers');
same([], $data['failed'], 'nothing failed: the tags came back as they were');
same(['cf7:mail/subject', 'cf7:mail/body', 'cf7:mail_2/subject', 'cf7:mail_2/mjml', 'cf7:messages/mail_sent_ok'], $data['written'], 'the form\'s keys written, the condition\'s not');
check('the prompt says which form', str_contains($requests[0]['body']['messages'][0]['content'], 'the contact form “Contatti AI”'));
check('and tells the model to keep mail tags', str_contains($requests[0]['body']['messages'][0]['content'], '[your-name]'));

$written_mail = get_post_meta($en, '_mail', true);
same('EN:[_site_title] — nuovo contatto: [your-name]', $written_mail['subject'], 'Mail\'s subject, in _mail');
same("EN:Da: [your-name] <[your-email]>\n\nMessaggio:\n[your-message]", $written_mail['body'], 'its body, its line breaks kept');
same('[_site_admin_email]', $written_mail['recipient'], 'the rest of the mail as it was');

$written_2 = get_post_meta($en, '_mail_2', true);
same(['EN:Grazie, [your-name]', '<html>grazie</html>', true], [$written_2['subject'], $written_2['body'], $written_2['active']], 'Mail (2)\'s subject; its body left to MJML\'s compile');

$mjml = get_post_meta($en, '_wp_go_mail_mjml', true);
same(['enabled' => true, 'source' => 'EN:<mjml><mj-body><mj-text>Grazie, [your-name]</mj-text></mj-body></mjml>', 'stale' => true], $mjml['mail_2'], 'the MJML source written, stale: compiled when the form is next opened');
same(['enabled' => false, 'source' => ''], $mjml['mail'], 'the other mail\'s MJML as it was');

if (function_exists('wp_go_cf7_mjml_state')) {
    same(true, wp_go_cf7_mjml_state($en)['mail_2']['stale'], 'cf7-mjml reads it as stale');
}

$messages = get_post_meta($en, '_messages', true);
same(['EN:Grazie per il messaggio.', 'Required field.'], [$messages['mail_sent_ok'], $messages['wg_required']], 'the message written, the others as they were');
same('Sì', get_post_meta($en, 'cf7_fields_0_conditions_0_value', true), 'the condition\'s value not written by AI');

section('Into the form, unsaved: the editor\'s');

[$status, $data] = $rest('POST', "/wgl/v1/posts/$en/ai-translate", ['keys' => ['cf7:messages/wg_required'], 'write' => false]);
same([200, [], ['cf7:messages/wg_required' => 'EN:Campo obbligatorio.']], [$status, $data['written'], $data['translated']], 'write false: answered, not written');
same('Required field.', get_post_meta($en, '_messages', true)['wg_required'], 'the form as it was');

section('Writing, alone');

update_post_meta($en, '_wg_mail_mjml', ['mail' => ['enabled' => true, 'source' => '<mjml>theme</mjml>']]);
delete_post_meta($en, '_wp_go_mail_mjml');
same(['cf7:mail/subject'], Core\ai_write_cf7($en, ['cf7:mail/subject' => 'Hello', 'cf7:mail/nothing' => 'x', 'cf7:other/subject' => 'y']), 'only a form\'s own keys are written');
same(['cf7:mail/mjml'], Core\ai_write_cf7($en, ['cf7:mail/mjml' => '<mjml>en</mjml>']), 'an MJML source');
same(['enabled' => true, 'source' => '<mjml>en</mjml>', 'stale' => true], get_post_meta($en, '_wp_go_mail_mjml', true)['mail'], 'read from the theme\'s old key, written to the new one');
