<?php

/**
 * Contact Form 7, a form per language: a form's translation gets its
 * language's locale and a hash of its own, its form and mails copied once;
 * `[contact-form-7]` shows the current language's published translation, by
 * hash or ID, and the form itself (by its numeric ID) when there is none or
 * it is still a draft.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;

/** A CF7 form saved as CF7's editor saves it, in a language. */
function wgt_cf7_form(string $title, string $body, string $code = 'it'): int
{
    $form = WPCF7_ContactForm::get_template(['title' => $title]);
    $form->set_properties(['form' => $body]);
    $id = (int) $form->save();
    WpGoLanguages::set_language($id, $code);

    return $id;
}

/** The shortcode's attributes, as CF7's handler reads them. */
function wgt_cf7_atts(array $given): array
{
    return shortcode_atts(['id' => 0, 'title' => '', 'html_id' => '', 'html_name' => '', 'html_class' => '', 'output' => 'form'], $given, 'wpcf7');
}

$it = wgt_cf7_form('Contatti test', '[text* nome] [submit "Invia"]');
$it_hash = (string) get_post_meta($it, '_hash', true);

check('the form has a hash from CF7', strlen($it_hash) === 64);
same('it', WpGoLanguages::language_of($it), 'the form is Italian');

section('a new translation of a form');

$en = WpGoLanguages::create_translation($it, 'en');
check('the translation is made', is_int($en));
same('wpcf7_contact_form', get_post_type($en), 'it is a form too');
same('draft', get_post_status($en), 'it is a draft');
same('en_GB', get_post_meta($en, '_locale', true), 'its _locale is English\'s locale');
$en_hash = (string) get_post_meta($en, '_hash', true);
check('it has a hash of its own', strlen($en_hash) === 64 && $en_hash !== $it_hash);
same(1, count(get_post_meta($en, '_hash')), 'one hash only (the original\'s was not copied)');
same(get_post_meta($it, '_form', true), get_post_meta($en, '_form', true), 'its form is copied from the original');

// the translation's own form, then a save of the original: copy once, so it stays
update_post_meta($en, '_form', '[text* name] [submit "Send"]');
$form = wpcf7_contact_form($it);
$form->set_properties(['form' => '[text* nome] [email* email] [submit "Invia"]']);
$form->save();
same('[text* name] [submit "Send"]', get_post_meta($en, '_form', true), 'a save of the original leaves the translation\'s form (copy once)');
same('ignore', Core\meta_policy('_locale', $it), '_locale is never copied');
same('ignore', Core\meta_policy('_hash', $it), '_hash is never copied');
same('translate', Core\meta_policy('_mail', $it), '_mail, copy-once in the config, is per language: copied once, then the translation\'s');

section('the shortcode, in the default language');

$atts = wgt_cf7_atts(['id' => substr($it_hash, 0, 7), 'title' => 'Contatti test']);
same((string) $it, $atts['id'], 'in Italian the form named by its hash is given by its ID');
same('', $atts['title'], 'the title is emptied');

section('the shortcode, in English');

// a draft translation isn't ready: the original shows, by its numeric ID
$atts = WpGoLanguages::in('en', fn () => wgt_cf7_atts(['id' => substr($it_hash, 0, 7), 'title' => 'Contatti test']));
same((string) $it, $atts['id'], 'a draft translation is not swapped in: the original, by its ID');
same('', $atts['title'], 'the title is emptied all the same');

wp_update_post(['ID' => $en, 'post_status' => 'publish']);
clean_post_cache($en);

$atts = WpGoLanguages::in('en', fn () => wgt_cf7_atts(['id' => substr($it_hash, 0, 7), 'title' => 'Contatti test']));
same(substr($en_hash, 0, 7), $atts['id'], 'by the original\'s short hash: the published translation\'s hash');
same('', $atts['title'], 'and no title, which would find the original');

$atts = WpGoLanguages::in('en', fn () => wgt_cf7_atts(['id' => $it_hash]));
same(substr($en_hash, 0, 7), $atts['id'], 'by the original\'s whole hash: the same');

$atts = WpGoLanguages::in('en', fn () => wgt_cf7_atts(['id' => (string) $it]));
same(substr($en_hash, 0, 7), $atts['id'], 'by the original\'s numeric ID: the translation\'s hash');

$atts = WpGoLanguages::in('en', fn () => wgt_cf7_atts(['id' => substr($en_hash, 0, 7)]));
same((string) $en, $atts['id'], 'the translation named by its own hash in English: itself, by its ID');

$atts = WpGoLanguages::in('it', fn () => wgt_cf7_atts(['id' => substr($en_hash, 0, 7)]));
same(substr($it_hash, 0, 7), $atts['id'], 'in Italian, the English form\'s hash gives the Italian form\'s');

// a form with no translation at all, named by hash: its numeric ID, which CF7 finds in any language
$alone = wgt_cf7_form('Solo italiano', '[submit "Invia"]');
$alone_hash = (string) get_post_meta($alone, '_hash', true);
$atts = WpGoLanguages::in('en', fn () => wgt_cf7_atts(['id' => substr($alone_hash, 0, 7)]));
same((string) $alone, $atts['id'], 'a form with no translation, by hash: its numeric ID');

$page = wgt_page('Non un form', '');
$atts = WpGoLanguages::in('en', fn () => wgt_cf7_atts(['id' => (string) $page, 'title' => 'x']));
same((string) $page, $atts['id'], 'an ID that is no form is left as it is');
same('x', $atts['title'], 'and so is its title');

$atts = WpGoLanguages::in('en', fn () => wgt_cf7_atts(['id' => 'abcdef0123', 'title' => 'x']));
same('abcdef0123', $atts['id'], 'a hash no form has is left as it is');

same(0, Core\cf7_form_id('abc12'), 'a hash shorter than 7 characters is not looked up');
same($it, Core\cf7_form_id(substr($it_hash, 0, 7)), 'cf7_form_id finds the form by its short hash');
same($it, Core\cf7_form_id((string) $it), 'cf7_form_id finds the form by its ID');

// CF7's own handler renders the English form on an English page
$html = WpGoLanguages::in('en', fn () => do_shortcode('[contact-form-7 id="' . substr($it_hash, 0, 7) . '" title="Contatti test"]'));
check('the shortcode renders the English form in English', str_contains($html, 'wpcf7-f' . $en . '-'));
$html = do_shortcode('[contact-form-7 id="' . substr($it_hash, 0, 7) . '" title="Contatti test"]');
check('and the Italian form in Italian', str_contains($html, 'wpcf7-f' . $it . '-'));
$html = WpGoLanguages::in('en', fn () => do_shortcode('[contact-form-7 id="' . substr($alone_hash, 0, 7) . '"]'));
check('a form with no translation still renders in English', str_contains($html, 'wpcf7-f' . $alone . '-'));

section('A form\'s mode');

check('pages may have fixed translations', Core\supports_fixed('page'));
check('CF7\'s forms too (cf7-fixed.test.php)', Core\supports_fixed('wpcf7_contact_form'));

$fx = wgt_cf7_form('Senza blocchi', '[text* nome] [submit "Invia"]');
$fx_en = WpGoLanguages::create_translation($fx, 'en');
same('duplicate', WpGoLanguages::mode((int) $fx_en), 'a form\'s translation is a duplicate by default');

$write = function (string $route, array $params) {
    $request = new WP_REST_Request('POST', $route);

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    return rest_do_request($request);
};
same(200, $write("/wgl/v1/posts/$fx_en/mode", ['mode' => 'fixed'])->get_status(), 'the mode route turns a form fixed');
same('fixed', WpGoLanguages::mode((int) $fx_en), 'and it is');

$alone_fx = wgt_cf7_form('Da spostare', '[submit "Invia"]');
$moved = $write("/wgl/v1/posts/$alone_fx/language", ['language' => 'en']);
same([200, 'en'], [$moved->get_status(), WpGoLanguages::language_of($alone_fx)], 'the language route moves a form alone to another language');
same(400, $write("/wgl/v1/posts/$fx/language", ['language' => 'de'])->get_status(), 'one with translations keeps its language');

ob_start();
Admin\column('wgl_languages', $alone_fx);
$cell = (string) ob_get_clean();
check('a form alone has no "+" of its language: it is in it', !str_contains($cell, 'data-wgl-lang="en"'));
