<?php

/**
 * What the screen's forms do, through admin-post.php:
 *
 *   wp_go_site_texts_save       save the texts changed; remove keys
 *   wp_go_site_texts_add        add a key written in the "Add a key" dialog
 *   wp_go_site_texts_add_found  add the keys the code prints (Scan code)
 *
 * Each goes back to the screen as it was (its search and filters), with the
 * outcome in the address for the screen's notices: `saved`, `added`,
 * `removed`, `found`, `skipped`.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_post_wp_go_site_texts_save', 'wp_go_site_texts_save_action');
add_action('admin_post_wp_go_site_texts_add', 'wp_go_site_texts_add_action');
add_action('admin_post_wp_go_site_texts_add_found', 'wp_go_site_texts_add_found_action');

/**
 * The query arguments an action leaves for the screen's notices, dropped
 * once they've been shown.
 */
const WP_GO_SITE_TEXTS_NOTICES = ['saved', 'added', 'removed', 'moved', 'key', 'found', 'skipped'];

/**
 * The screen to go back to, as it was filtered, without the previous
 * action's notice.
 */
function wp_go_site_texts_back(): string
{
    $back = wp_get_referer() ?: admin_url('admin.php?page=' . WP_GO_SITE_TEXTS_PAGE);

    return remove_query_arg(WP_GO_SITE_TEXTS_NOTICES, $back);
}

/**
 * One field of the address or of the form posted (`$_GET`, `$_POST`),
 * unslashed: '' when it's missing, or isn't a plain value (`?s[]=x`).
 *
 * @param array<string,mixed> $from
 */
function wp_go_site_texts_param(array $from, string $name): string
{
    return isset($from[ $name ]) && is_string($from[ $name ]) ? wp_unslash($from[ $name ]) : '';
}

/**
 * Save the texts.
 *
 * Only the fields opened on the screen are posted (wp_go_site_texts_screen()),
 * and only the values that changed are written, in the site's languages (a
 * removed language's values stay as they were). An emptied field removes
 * that language's value. Then `wp_go_site_texts_saved` is fired with what
 * changed: [key => [language, …]].
 *
 * Only keys in the user's domains are written: a key outside them is left
 * as it was, whatever was posted.
 *
 * The same form also, for administrators, removes keys (a row's trash
 * button, and "Remove selected" for the unused keys ticked, each checked
 * again against the code first) and moves keys to another domain (those the
 * code doesn't give one).
 */
function wp_go_site_texts_save_action(): void
{
    if (!current_user_can(wp_go_site_texts_capability())) {
        wp_die('', 403);
    }

    check_admin_referer('wp_go_site_texts_save');

    $changed = wp_go_site_texts_save_posted((array) wp_unslash($_POST['texts'] ?? []));
    $saved = array_sum(array_map('count', $changed));
    $remove = current_user_can('manage_options') ? wp_go_site_texts_posted_removals() : [];

    $moved = current_user_can('manage_options') ? wp_go_site_texts_save_posted_domains((array) wp_unslash($_POST['domains'] ?? []), (array) wp_unslash($_POST['domains_was'] ?? [])) : 0;

    if ($remove) {
        wp_go_site_texts_remove(...$remove);
    }

    // a removal or a move alone says only that, not also "Nothing had changed."
    $outcome = array_filter(['saved' => $saved, 'removed' => count($remove), 'moved' => $moved]) ?: ['saved' => 0];

    wp_safe_redirect(add_query_arg($outcome, wp_go_site_texts_back()));
    exit;
}

/**
 * Write the posted values that changed, and say so with
 * `wp_go_site_texts_saved`.
 *
 * @param array<string,mixed> $posted [key => [language => value]]
 * @return array<string,string[]> Each key's languages written.
 */
function wp_go_site_texts_save_posted(array $posted): array
{
    $texts = wp_go_site_texts();
    $languages = wp_go_site_texts_languages();
    $changed = [];

    foreach ($posted as $key => $values) {
        $key = (string) $key;

        // a key removed since the screen was loaded, or not the user's to edit
        if (!isset($texts[ $key ]) || !is_array($values) || !wp_go_site_texts_can_edit($key)) {
            continue;
        }

        foreach ($values as $language => $value) {
            if (!isset($languages[ $language ]) || !is_string($value)) {
                continue;
            }

            $value = trim(str_replace("\r\n", "\n", $value));
            $value = current_user_can('unfiltered_html') ? $value : wp_kses_post($value);

            if ($value === (string) ($texts[ $key ][ $language ] ?? '')) {
                continue;
            }

            if ($value === '') {
                unset($texts[ $key ][ $language ]);
            } else {
                $texts[ $key ][ $language ] = $value;
            }

            $changed[ $key ][] = $language;
        }
    }

    if ($changed) {
        wp_go_site_texts_store($texts);

        /**
         * Texts were saved on the screen.
         *
         * @param array<string,string[]> $changed Each key's languages saved.
         */
        do_action('wp_go_site_texts_saved', $changed);
    }

    return $changed;
}

/**
 * The domains an administrator gave keys on the screen: [key => domain],
 * those the code gives a domain left out (the code wins), and only domains
 * there are.
 *
 * Only a choice changed on the screen counts: one still showing the domain
 * it showed on load (`$loaded`, [key => domain]) is left alone, so a screen
 * loaded before another administrator moved the key doesn't move it back.
 *
 * @param array<string,mixed> $posted
 * @param array<string,mixed> $loaded
 * @return int How many keys moved to another domain.
 */
function wp_go_site_texts_save_posted_domains(array $posted, array $loaded = []): int
{
    $domains = wp_go_site_texts_domains();
    $changes = [];

    foreach ($posted as $key => $domain) {
        if (!is_string($domain)) {
            continue;
        }

        $key = (string) $key;
        $domain = sanitize_key($domain);

        if (isset($loaded[ $key ]) && is_string($loaded[ $key ]) && sanitize_key($loaded[ $key ]) === $domain) {
            continue;
        }

        if (isset(wp_go_site_texts()[ $key ], $domains[ $domain ]) && !wp_go_site_texts_domain_from_code($key) && $domain !== wp_go_site_texts_domain_of($key)) {
            $changes[ $key ] = $domain;
        }
    }

    if ($changes) {
        wp_go_site_texts_set_domains($changes);
    }

    return count($changes);
}

/**
 * The keys the save form asks to remove: a row's own trash button, and the
 * unused keys ticked for "Remove selected", those only if the code really
 * doesn't print them.
 *
 * @return string[]
 */
function wp_go_site_texts_posted_removals(): array
{
    $texts = wp_go_site_texts();
    $remove = [];

    $one = wp_go_site_texts_param($_POST, 'remove');

    if ($one !== '' && isset($texts[ $one ])) {
        $remove[] = $one;
    }

    if (isset($_POST['remove_selected'])) {
        $ticked = array_filter((array) wp_unslash($_POST['unused'] ?? []), 'is_string');
        $remove = [...$remove, ...array_intersect($ticked, (array) wp_go_site_texts_unused())];
    }

    return array_values(array_unique($remove));
}

/** Add a key written in the "Add a key" dialog, in the domain chosen, with no values yet. */
function wp_go_site_texts_add_action(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('', 403);
    }

    check_admin_referer('wp_go_site_texts_add');

    $texts = wp_go_site_texts();
    $key = strtolower(trim(wp_go_site_texts_param($_POST, 'key')));
    $error = wp_go_site_texts_key_error($key, $texts);

    // refused: back to the dialog, open again with the key as it was written
    if ($error !== null) {
        wp_safe_redirect(add_query_arg(['added' => $error, 'key' => rawurlencode($key)], wp_go_site_texts_back()));
        exit;
    }

    $texts[ $key ] = [];
    wp_go_site_texts_store($texts);

    $domain = sanitize_key(wp_go_site_texts_param($_POST, 'domain'));

    if ($domain !== '' && isset(wp_go_site_texts_domains()[ $domain ])) {
        wp_go_site_texts_set_domains([$key => $domain]);
    }

    // the new key's row, with no search or filter hiding it
    $back = remove_query_arg(['s', 'missing', 'domain'], wp_go_site_texts_back());

    wp_safe_redirect(add_query_arg('added', '1', $back) . '#' . rawurlencode($key));
    exit;
}

/** Add the keys the code prints that the list doesn't have (Scan code's "Add them"). */
function wp_go_site_texts_add_found_action(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('', 403);
    }

    check_admin_referer('wp_go_site_texts_add_found');

    $done = wp_go_site_texts_add_found();

    wp_safe_redirect(add_query_arg(['found' => count($done['added']), 'skipped' => count($done['skipped'])], wp_go_site_texts_back()));
    exit;
}
