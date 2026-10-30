<?php

/**
 * What the Admin strings tab's forms do, through admin-post.php, for
 * administrators:
 *
 *   wp_go_admin_strings_save  save the translations changed
 *   wp_go_admin_strings_scan  read the code again (Scan code)
 *
 * Each goes back to the tab as it was, with the outcome in the address.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_post_wp_go_admin_strings_save', 'wp_go_admin_strings_save_action');
add_action('admin_post_wp_go_admin_strings_scan', 'wp_go_admin_strings_scan_action');

/** The tab to go back to, without the previous action's notice. */
function wp_go_admin_strings_back(): string
{
    $back = wp_get_referer() ?: admin_url('admin.php?page=' . WP_GO_SITE_TEXTS_PAGE . '&tab=admin');

    return remove_query_arg(['saved', 'found', 'added', 'removed'], $back);
}

/**
 * Save the translations: only the fields opened are posted, and only those
 * that changed are written; an emptied field removes that translation. Then
 * `wp_go_languages/strings_saved` says what changed:
 * [domain => [context => [locale, …]]].
 */
function wp_go_admin_strings_save_action(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('', 403);
    }

    check_admin_referer('wp_go_admin_strings_save');

    $strings = wp_go_admin_strings();
    $locales = wp_go_admin_strings_locales();
    $translations = wp_go_admin_strings_translations();
    $changed = [];
    $saved = 0;

    foreach ((array) wp_unslash($_POST['strings'] ?? []) as $locale => $values) {
        if (!isset($locales[$locale]) || !is_array($values)) {
            continue;
        }

        foreach ($values as $id => $value) {
            $string = $strings[(string) $id] ?? null;

            // a string the code no longer has, or a value that isn't text (`strings[it_IT][id][]`)
            if (!$string || !is_string($value)) {
                continue;
            }

            $key = wp_go_admin_strings_lookup_key($string['context'], $string['text']);
            $value = trim(str_replace("\r\n", "\n", $value));
            $value = current_user_can('unfiltered_html') ? $value : wp_kses_post($value);

            if ($value === (string) ($translations[$locale][$string['domain']][$key] ?? '')) {
                continue;
            }

            if ($value === '') {
                unset($translations[$locale][$string['domain']][$key]);
            } else {
                $translations[$locale][$string['domain']][$key] = $value;
            }

            $changed[$string['domain']][$string['context']][$locale] = true;
            $saved++;
        }
    }

    if ($changed) {
        wp_go_admin_strings_store($translations);

        /**
         * Admin strings' translations were saved.
         *
         * @param array<string,array<string,string[]>> $changed [domain => [context => [locale, …]]]
         */
        do_action('wp_go_languages/strings_saved', array_map(fn ($contexts) => array_map('array_keys', $contexts), $changed));
    }

    wp_safe_redirect(add_query_arg('saved', $saved, wp_go_admin_strings_back()));
    exit;
}

/** Read the code again: new strings added, those it no longer has removed. */
function wp_go_admin_strings_scan_action(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('', 403);
    }

    check_admin_referer('wp_go_admin_strings_scan');

    $done = wp_go_admin_strings_scan();

    wp_safe_redirect(add_query_arg([
        'found'   => $done['found'],
        'added'   => count($done['added']),
        'removed' => count($done['removed']),
    ], wp_go_admin_strings_back()));
    exit;
}
