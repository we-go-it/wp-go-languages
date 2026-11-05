<?php

/**
 * A Contact Form 7 form's fixed translation (src/Core/cf7.php): the
 * original's fields (cf7-acf-form's rows), its texts kept with them by the
 * fields' names as the original changes; its mails' settings, MJML switch
 * and additional settings the original's on each save of either, the
 * subject and body its own; its conditions' values following its options'
 * labels. A duplicate is all its own: nothing synced, nothing locked. On
 * its screen, a fixed form's fields that aren't texts are hidden.
 */

require_once __DIR__ . '/fixtures/content.php';

use WpGo\Languages\Admin;
use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

$mail = fn (string $subject, string $body, string $recipient = 'info@example.com') => [
    'active' => true, 'subject' => $subject, 'sender' => 'Sito <wordpress@example.com>', 'body' => $body,
    'recipient' => $recipient, 'additional_headers' => 'Reply-To: [email]', 'attachments' => '', 'use_html' => false, 'exclude_blank' => false,
];

/** A text row of the builder. */
function wgt_text_row(string $name, string $label, array $extra = []): array
{
    return array_merge(['acf_fc_layout' => 'text', 'name' => $name, 'label' => $label, 'placeholder' => '', 'help' => '', 'width' => 'full', 'input' => 'text', 'required' => 0, 'maxlength' => '', 'conditions_action' => '', 'conditions_logic' => 'all', 'conditions' => []], $extra);
}

/** A builder's field, as ACF hands it to acf/prepare_field: its input's prefix, its value. */
function wgt_field(string $key, array $with): array
{
    return array_merge(acf_get_field($key), $with);
}

/** A CF7 form with the builder's rows, saved as CF7 saves it, in Italian. */
function wgt_built_form(string $title, array $rows, array $mail): int
{
    $form = WPCF7_ContactForm::get_template(['title' => $title]);
    $form->set_properties(['form' => '', 'mail' => $mail, 'additional_settings' => 'skip_mail: off']);
    $id = (int) $form->save();
    WpGoLanguages::set_language($id, 'it');
    update_field('field_wg_cf7_fields', $rows, $id);

    return $id;
}

/** Save a form through CF7, as its screen does: its post, then its meta, then wpcf7_after_save. */
function wgt_cf7_save(int $id, array $properties = []): void
{
    $form = wpcf7_contact_form($id);
    $form->set_properties($properties);
    $form->save();
    clean_post_cache($id);
}

$rows = [
    wgt_text_row('nome', 'Nome', ['placeholder' => 'Il tuo nome']),
    ['acf_fc_layout' => 'select', 'name' => 'tipo', 'label' => 'Tipo', 'placeholder' => '', 'help' => '', 'width' => 'full', 'source' => '', 'required' => 0,
     'options' => [['value' => '', 'label' => 'Privato'], ['value' => '', 'label' => 'Azienda'], ['value' => 'altro', 'label' => 'Altro']],
     'conditions_action' => '', 'conditions_logic' => 'all', 'conditions' => []],
    wgt_text_row('piva', 'Partita IVA', ['conditions_action' => 'show', 'conditions' => [['field' => 'tipo', 'operator' => '==', 'value' => 'Azienda']]]),
    wgt_text_row('note', 'Note', ['conditions_action' => 'show', 'conditions' => [['field' => 'tipo', 'operator' => '==', 'value' => 'altro']]]),
];

section('A form may have fixed translations');

check('CF7\'s forms support fixed translations', Core\supports_text_only('wpcf7_contact_form'));
check('but not by blocks', !Core\fixed_by_blocks('wpcf7_contact_form') && Core\fixed_by_blocks('page'));

$it = wgt_built_form('Contatti fissi', $rows, $mail('Nuovo messaggio', 'Da: [nome]'));
$en = WpGoLanguages::create_translation($it, 'en', 'text');

same('text', WpGoLanguages::mode((int) $en), 'asked fixed, a form\'s translation is fixed');
same(get_post_meta($it, 'cf7_fields', true), get_post_meta($en, 'cf7_fields', true), 'it has the original\'s rows');
same(true, Admin\panel_data((int) $en)['can_blocks'], 'the panel offers the fixed layout');
same('blocks', Admin\panel_data((int) $en)['mode'], 'and shows it as that');
$asked = wgt_built_form('Contatti chiesti', $rows, $mail('S', 'B'));
same('text', WpGoLanguages::mode((int) WpGoLanguages::create_translation($asked, 'en', 'blocks')), 'asked the fixed layout, a form is fixed its own way');

ob_start();
Admin\column('wgl_languages', wgt_built_form('Con link fisso', [wgt_text_row('a', 'A')], $mail('S', 'B')));
$cell = (string) ob_get_clean();
check('a form\'s "+" carries a fixed link for the dialog', (bool) preg_match('/data-wgl-blocks="[^"]+mode=blocks/', $cell));

section('Its texts translated, its settings the original\'s');

update_post_meta($en, 'cf7_fields_0_label', 'Name');
update_post_meta($en, 'cf7_fields_0_placeholder', 'Your name');
update_post_meta($en, 'cf7_fields_1_options_0_label', 'Private');
update_post_meta($en, 'cf7_fields_1_options_1_label', 'Company');
update_post_meta($en, 'cf7_fields_1_options_2_label', 'Other');
update_post_meta($en, 'cf7_fields_2_label', 'VAT number');


// its own save, its screen posting a recipient (hidden there) and its texts
wgt_cf7_save((int) $en, ['mail' => ['recipient' => 'hacked@example.com', 'subject' => 'New message', 'body' => 'From: [nome]'] + $mail('', ''), 'additional_settings' => 'demo_mode: on']);

$en_mail = get_post_meta($en, '_mail', true);
same('info@example.com', $en_mail['recipient'], 'its recipient is the original\'s again, whatever was posted');
same('Sito <wordpress@example.com>', $en_mail['sender'], 'its sender too');
same(['New message', 'From: [nome]'], [$en_mail['subject'], $en_mail['body']], 'its subject and body are its own');
same('skip_mail: off', get_post_meta($en, '_additional_settings', true), 'its additional settings are the original\'s');
same('Company', get_post_meta($en, 'cf7_fields_2_conditions_0_value', true), 'a condition on an option without a value reads its translated label');
same('altro', get_post_meta($en, 'cf7_fields_3_conditions_0_value', true), 'one on an option with a value of its own keeps the value');

section('The original changes');

// a field added at the top, the select moved, the recipient changed
$changed = [wgt_text_row('email', 'Email'), $rows[1], $rows[0], $rows[2], $rows[3]];
update_field('field_wg_cf7_fields', $changed, $it);
wgt_cf7_save($it, ['mail' => $mail('Nuovo messaggio', 'Da: [nome]', 'sales@example.com'), 'additional_settings' => 'skip_mail: on']);

same(get_post_meta($it, 'cf7_fields', true), get_post_meta($en, 'cf7_fields', true), 'the translation has the new rows');
same(['email', 'tipo', 'nome', 'piva'], array_map(fn ($i) => get_post_meta($en, "cf7_fields_{$i}_name", true), [0, 1, 2, 3]), 'in the original\'s order');
same('Email', get_post_meta($en, 'cf7_fields_0_label', true), 'the new field has the original\'s label, to translate');
same(['Name', 'Your name'], [get_post_meta($en, 'cf7_fields_2_label', true), get_post_meta($en, 'cf7_fields_2_placeholder', true)], 'the moved field keeps its translation, found by its name');
same(['Private', 'Company', 'Other'], array_map(fn ($i) => get_post_meta($en, "cf7_fields_1_options_{$i}_label", true), [0, 1, 2]), 'and the select its options\' labels');
same('VAT number', get_post_meta($en, 'cf7_fields_3_label', true), 'and the conditioned field its own');
same('Company', get_post_meta($en, 'cf7_fields_3_conditions_0_value', true), 'its condition follows the option, at its new place');
same('sales@example.com', get_post_meta($en, '_mail', true)['recipient'], 'the new recipient reaches it');
same('New message', get_post_meta($en, '_mail', true)['subject'], 'its subject stays its own');
same('skip_mail: on', get_post_meta($en, '_additional_settings', true), 'the new additional settings too');

section('MJML switched on in the original');

update_post_meta($it, Core\CF7_MJML_META, ['mail' => ['enabled' => true, 'source' => '<mjml>it</mjml>'], 'mail_2' => ['enabled' => false, 'source' => '']]);
wgt_cf7_save($it, ['mail' => $mail('Nuovo messaggio', '<p>compilato</p>', 'sales@example.com')]);
$mjml = get_post_meta($en, Core\CF7_MJML_META, true);
same([true, '<mjml>it</mjml>'], [$mjml['mail']['enabled'], $mjml['mail']['source']], 'the translation, without one, starts from the original\'s MJML');
same('<p>compilato</p>', get_post_meta($en, '_mail', true)['body'], 'its body with it');

update_post_meta($en, Core\CF7_MJML_META, ['mail' => ['enabled' => true, 'source' => '<mjml>en</mjml>'], 'mail_2' => ['enabled' => false, 'source' => '']]);
update_post_meta($it, Core\CF7_MJML_META, ['mail' => ['enabled' => false, 'source' => '<mjml>it</mjml>'], 'mail_2' => ['enabled' => false, 'source' => '']]);
wgt_cf7_save($it);
$mjml = get_post_meta($en, Core\CF7_MJML_META, true);
same([false, '<mjml>en</mjml>'], [$mjml['mail']['enabled'], $mjml['mail']['source']], 'switched off: off in the translation, its source kept');

section('A duplicate is its own');

$it2 = wgt_built_form('Contatti liberi', $rows, $mail('Oggetto', 'Corpo'));
$dup = (int) WpGoLanguages::create_translation($it2, 'en');
same('independent', WpGoLanguages::mode($dup), 'a duplicate');
update_field('field_wg_cf7_fields', [wgt_text_row('solo', 'Solo')], $dup);
wgt_cf7_save($dup, ['mail' => $mail('Subject', 'Body', 'mine@example.com')]);

update_field('field_wg_cf7_fields', $changed, $it2);
wgt_cf7_save($it2, ['mail' => $mail('Oggetto', 'Corpo', 'other@example.com')]);

same(['text'], get_post_meta($dup, 'cf7_fields', true), 'the original\'s rows don\'t reach it');
same('solo', get_post_meta($dup, 'cf7_fields_0_name', true), 'its field\'s name is its own');
same('mine@example.com', get_post_meta($dup, '_mail', true)['recipient'], 'nor its mail settings');

same(['solo'], Core\cf7_dropped_fields($dup), 'turning fixed, it would lose the fields the original lacks');
same(1, Admin\panel_data($dup)['dropped'], 'the panel says how many');

section('The screen');

$_GET['post'] = (string) $dup;
$context = State::$context;
State::$context = 'admin';

$name  = wgt_field('field_wg_cf7_text_name', ['prefix' => 'acf[field_wg_cf7_fields][row-0]', 'value' => 'solo']);
$field = Core\lock_copied_field($name);
check('a duplicate\'s field name can be changed', empty($field['readonly']) && !str_contains((string) $field['wrapper']['class'], 'wgl-'));

$rows_field = Core\lock_copied_field(wgt_field('field_wg_cf7_fields', ['prefix' => 'acf', 'value' => ['x']]));
check('and its rows added or removed', !str_contains((string) $rows_field['wrapper']['class'], 'wgl-fixed-rows'));

$_GET['post'] = (string) $en;

$field = Core\lock_copied_field($name);
check('a fixed form\'s field name is hidden', str_contains((string) $field['wrapper']['class'], 'wgl-copied'));
$label = Core\lock_copied_field(wgt_field('field_wg_cf7_text_label', ['prefix' => 'acf[field_wg_cf7_fields][row-0]', 'value' => 'Name']));
check('its label is shown, to translate', !str_contains((string) $label['wrapper']['class'], 'wgl-copied') && empty($label['readonly']));
$value = Core\lock_copied_field(wgt_field('field_wg_cf7_text_conditions_value', ['prefix' => 'acf[field_wg_cf7_fields][row-3][field_wg_cf7_text_conditions][row-0]', 'value' => 'Company']));
check('a condition\'s value is hidden: it follows the options', str_contains((string) $value['wrapper']['class'], 'wgl-copied'));
$options = Core\lock_copied_field(wgt_field('field_wg_cf7_select_options', ['prefix' => 'acf[field_wg_cf7_fields][row-1]', 'value' => [1, 2, 3]]));
check('a select\'s options are shown, their rows fixed', !str_contains((string) $options['wrapper']['class'], 'wgl-copied') && str_contains((string) $options['wrapper']['class'], 'wgl-fixed-rows') && $options['max'] === 3);
$rows_field = Core\lock_copied_field(wgt_field('field_wg_cf7_fields', ['prefix' => 'acf', 'value' => ['a', 'b']]));
check('the rows are fixed', str_contains((string) $rows_field['wrapper']['class'], 'wgl-fixed-rows') && $rows_field['min'] === 2);

$tab = Core\lock_copied_field(wgt_field('field_wg_cf7_text_tab_validation', ['prefix' => 'acf[field_wg_cf7_fields][row-0]']));
check('a text field\'s validation tab, nothing in it to translate, is hidden', str_contains((string) ($tab['class'] ?? ''), 'wgl-copied'));
$tab = Core\lock_copied_field(wgt_field('field_wg_cf7_text_tab_field', ['prefix' => 'acf[field_wg_cf7_fields][row-0]']));
check('its first tab, with the label, is not', !str_contains((string) ($tab['class'] ?? ''), 'wgl-copied'));

same('', Admin\cf7_builder_note('note', $dup), 'a duplicate\'s builder has no note');
check('a fixed one\'s says only its texts are there', str_contains(Admin\cf7_builder_note('note', (int) $en), 'text'));
same('note', Admin\cf7_builder_note('note', $it), 'an original keeps the note it had');

set_current_screen('toplevel_page_wpcf7');
$_GET['action'] = 'edit';
$panels = Admin\cf7_fixed_panels(['mail-panel' => ['title' => 'Mail', 'callback' => fn () => print 'MAIL'], 'additional-settings-panel' => ['title' => 'Additional', 'callback' => '__return_null']]);
check('a fixed form has no Additional settings tab', !isset($panels['additional-settings-panel']));
ob_start();
$panels['mail-panel']['callback'](null);
check('its Mail tab opens with a note, then CF7\'s', (bool) preg_match('/wgl-cf7-note.*MAIL$/s', (string) ob_get_clean()));
same(' wgl-cf7-fixed', Admin\cf7_body_class(''), 'its screen is marked for the styles');

$_GET['post'] = (string) $dup;
same(['mail-panel', 'additional-settings-panel'], array_keys(Admin\cf7_fixed_panels(['mail-panel' => [], 'additional-settings-panel' => []])), 'a duplicate keeps every tab');
same('', Admin\cf7_body_class(''), 'and isn\'t marked');

State::$context = $context;
unset($_GET['post'], $_GET['action']);

section('A duplicate turned fixed');

check('set_mode() turns it fixed', Core\set_mode($dup, 'text'));
same(get_post_meta($it2, 'cf7_fields', true), get_post_meta($dup, 'cf7_fields', true), 'its rows become the original\'s');
same('Nome', get_post_meta($dup, 'cf7_fields_2_label', true), 'a field it didn\'t have gets the original\'s texts');
same('other@example.com', get_post_meta($dup, '_mail', true)['recipient'], 'and its mail settings the original\'s');
same('Subject', get_post_meta($dup, '_mail', true)['subject'], 'its subject kept');
