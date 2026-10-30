<?php

/**
 * The texts over REST, for a front end that prints them itself (a Nuxt
 * site's i18n, say):
 *
 *   GET /wp-json/we-go-site-texts/v1/strings?lang=en
 *
 *   { "footer": { "contact_us": "Contact us", … }, "events": { … } }
 *
 * Every key with a value in that language, nested by its dots. A key with
 * no value is left out, so the front end's own fallback decides what shows.
 * `lang` is one of the site's language codes (`it`, `en`); left out, it's
 * the current language. `domain`, optional, keeps to one domain's keys
 * (`?domain=shop`; `general` for the keys with none). Public: these are texts
 * the site shows to anyone.
 */

if (!defined('ABSPATH')) {
    exit;
}

const WP_GO_SITE_TEXTS_REST_NAMESPACE = 'we-go-site-texts/v1';

add_action('rest_api_init', function (): void {
    register_rest_route(WP_GO_SITE_TEXTS_REST_NAMESPACE, '/strings', [
        'methods'             => WP_REST_Server::READABLE,
        'permission_callback' => '__return_true',
        'callback'            => 'wp_go_site_texts_rest_strings',
        'args'                => [
            'lang'   => ['type' => 'string', 'default' => ''],
            'domain' => ['type' => 'string', 'default' => ''],
        ],
    ]);
});

/**
 * @return WP_REST_Response|WP_Error
 */
function wp_go_site_texts_rest_strings(WP_REST_Request $request)
{
    $languages = array_keys(wp_go_site_texts_languages());
    $lang = sanitize_key((string) $request['lang']) ?: wp_go_site_texts_language();

    if (!in_array($lang, $languages, true)) {
        return new WP_Error('wp_go_site_texts_bad_lang', "No active language `$lang`: " . implode(', ', $languages) . '.', ['status' => 400]);
    }

    $domain = sanitize_key((string) $request['domain']);
    $key_domains = $domain !== '' ? wp_go_site_texts_key_domains() : [];
    $messages = [];

    // the language's compiled file: its keys with a value, in the option's order
    foreach (wp_go_site_texts_for($lang) as $key => $value) {
        if ($domain !== '' && ($key_domains[ $key ] ?? WP_GO_SITE_TEXTS_GENERAL) !== $domain) {
            continue;
        }

        // `footer.contact_us` → ['footer']['contact_us']
        $parts = explode('.', (string) $key);
        $last = array_pop($parts);
        $node = &$messages;

        foreach ($parts as $part) {
            // A key can't be both a text and a group: `footer.title` and
            // `footer.title.short` (only an option written by other means
            // than the screen, which refuses them, has both). The shorter,
            // the text, wins: the longer is left out, and a group built
            // already from it is replaced.
            if (isset($node[ $part ]) && !is_array($node[ $part ])) {
                unset($node);
                continue 2;
            }

            $node = &$node[ $part ];
        }

        $node[ $last ] = $value;
        unset($node);
    }

    return rest_ensure_response($messages ?: new stdClass());
}
