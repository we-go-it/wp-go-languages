<?php

/**
 * The texts themselves: where they are stored, how a key is read, written
 * and removed, and what a key may look like.
 *
 * Everything is kept in one option, `wp_go_site_texts`:
 *
 *   [
 *     'footer.contact_us' => ['it' => 'Contattaci', 'en' => 'Contact us'],
 *     'events.load_more'  => ['it' => 'Carica altri'],
 *   ]
 *
 * A language with no value for a key simply has no entry: the site then
 * prints the key itself, so the gap is visible.
 *
 * The option isn't autoloaded: a page prints its texts from its language's
 * compiled file (compiled.php), and only the admin reads them all.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The admin screen's slug: `admin.php?page=wg-site-texts`. */
const WP_GO_SITE_TEXTS_PAGE = 'wg-site-texts';

/** The option the keys and their values are stored in. */
const WP_GO_SITE_TEXTS_OPTION = 'wp_go_site_texts';

/**
 * The "text domain" the keys belong to, as far as Cache Tags is concerned.
 *
 * Each key is its own context in it: a page that prints `footer.contact_us`
 * in English is tagged `text-{hash(site-texts|footer.contact_us)}--en`, and
 * saving that key in English purges exactly those pages.
 */
const WP_GO_SITE_TEXTS_DOMAIN = 'site-texts';

/**
 * What a key looks like: lowercase letters, digits and underscores, in at
 * least two parts joined by dots. The first part is the group the screen
 * lists it under (`footer.contact_us` is in "Footer").
 */
const WP_GO_SITE_TEXTS_KEY = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/';

/**
 * Every key with its values, as stored.
 *
 * Read from the database once per request. It's returned by reference so
 * wp_go_site_texts_store() can refresh this copy after writing.
 *
 * @return array<string,array<string,string>>
 */
function &wp_go_site_texts(): array
{
    static $texts = null;

    if ($texts === null) {
        $texts = array_filter((array) get_option(WP_GO_SITE_TEXTS_OPTION, []), 'is_array');
    }

    return $texts;
}

/**
 * Write every key with its values, sorted by key so the option stays easy to
 * read.
 *
 * @param array<string,array<string,string>> $texts
 */
function wp_go_site_texts_store(array $texts): void
{
    ksort($texts, SORT_STRING);
    update_option(WP_GO_SITE_TEXTS_OPTION, $texts, false);

    $cached = &wp_go_site_texts();
    $cached = $texts;
}

/**
 * The text to print for a key: its value in `$language` (the current
 * language when null), or the key itself when there is none. Read from the
 * language's compiled file (wp_go_site_texts_for()), not the option.
 *
 * The result goes through the `wp_go_site_text` filter ($value, $key,
 * $language), which is where Cache Tags notes that the page printed this
 * key.
 */
function wp_go_site_text(string $key, ?string $language = null): string
{
    $language ??= wp_go_site_texts_language();
    $value = (string) (wp_go_site_texts_for($language)[ $key ] ?? '');

    return (string) apply_filters('wp_go_site_text', $value !== '' ? $value : $key, $key, $language);
}

/**
 * Remove keys, with their values in every language. Fires
 * `wp_go_site_texts_removed` once for each key that was there.
 */
function wp_go_site_texts_remove(string ...$keys): void
{
    $texts = wp_go_site_texts();
    $removed = array_values(array_filter($keys, fn ($key) => isset($texts[ $key ])));

    foreach ($removed as $key) {
        unset($texts[ $key ]);
    }

    wp_go_site_texts_store($texts);

    // their domains go with them
    wp_go_site_texts_set_domains([]);

    foreach ($removed as $key) {
        /**
         * A key was removed, with its values in every language.
         *
         * @param string $key
         */
        do_action('wp_go_site_texts_removed', $key);
    }
}

/**
 * Why a key can't be added to the list, or null when it can:
 *
 *   invalid  it doesn't look like a key (WP_GO_SITE_TEXTS_KEY)
 *   exists   it's already in the list
 *   nested   it's the start of another key, or another is the start of it
 *            (`footer.title` and `footer.title.short`): the REST route nests
 *            keys by their dots, and one can't be both a text and a group
 *
 * @param array<string,mixed> $texts The keys it would be added to.
 */
function wp_go_site_texts_key_error(string $key, array $texts): ?string
{
    if (!preg_match(WP_GO_SITE_TEXTS_KEY, $key)) {
        return 'invalid';
    }

    if (isset($texts[ $key ])) {
        return 'exists';
    }

    foreach (array_keys($texts) as $other) {
        if (str_starts_with($other, "$key.") || str_starts_with($key, "$other.")) {
            return 'nested';
        }
    }

    return null;
}

/** A key's group: its first part (`footer.contact_us` → `footer`). */
function wp_go_site_texts_group_of(string $key): string
{
    return explode('.', $key, 2)[0];
}

/**
 * A group's heading on the screen: `contact_page` reads "Contact page",
 * unless the theme names it otherwise with `wp_go_site_texts_group`.
 */
function wp_go_site_texts_group(string $group): string
{
    $name = ucfirst(str_replace('_', ' ', $group));

    return (string) apply_filters('wp_go_site_texts_group', $name, $group);
}

/** The language being printed: the current one. */
function wp_go_site_texts_language(): string
{
    return \WpGo\Languages\Core\current();
}

/** The site's default language. */
function wp_go_site_texts_default_language(): string
{
    return \WpGo\Languages\Core\default_language();
}

/**
 * The site's languages, code => name, with the default one first: the
 * screen's columns, in order.
 *
 * @return array<string,string>
 */
function wp_go_site_texts_languages(): array
{
    $languages = array_map(fn (array $language) => $language['name'], \WpGo\Languages\Core\languages());
    $default = wp_go_site_texts_default_language();

    return isset($languages[ $default ]) ? [$default => $languages[ $default ]] + $languages : $languages;
}

/**
 * The capability that opens the Site texts screen: given to anyone who may
 * edit at least one domain (wp_go_site_texts_grant()).
 *
 * Adding and removing keys, and moving one to another domain, is always for
 * administrators only: a key is something the code prints.
 */
function wp_go_site_texts_capability(): string
{
    return 'wgl_site_texts';
}

/**
 * ---------------------------------------------------------------------------
 * Domains
 * ---------------------------------------------------------------------------
 *
 * A domain is a label on a key, saying whose text it is (`shop`, `emails`),
 * so each role edits only the texts that are theirs. Keys stay unique across
 * domains: the domain changes who sees a key, not how it's printed.
 *
 * Where a key's domain comes from, first found wins:
 *
 *   1. the code, when it says so: `t('checkout.pay', 'shop')`, as of the
 *      last scan (scan.php keeps it with where each key is printed)
 *   2. an administrator, on the screen: for keys no code declares (another
 *      front end's), kept in `wp_go_site_texts_domains` ([key => domain])
 *   3. none: the key is in "General", a domain like the others
 *
 * Which roles edit which domain is set on the Languages screen
 * (wp_go_site_texts_domains()). Administrators edit every domain.
 */

/** The option keeping the domains administrators gave keys: [key => domain]. */
const WP_GO_SITE_TEXTS_DOMAINS_OPTION = 'wp_go_site_texts_domains';

/** The domain of a key no one gave one. */
const WP_GO_SITE_TEXTS_GENERAL = 'general';

/**
 * Every key's domain, General left out: [key => domain], the code's over the
 * administrators'.
 *
 * @return array<string,string>
 */
function wp_go_site_texts_key_domains(): array
{
    return array_filter(
        array_replace(wp_go_site_texts_given_domains(), wp_go_site_texts_used()['domains']),
        fn ($domain) => $domain !== '' && $domain !== WP_GO_SITE_TEXTS_GENERAL
    );
}

/**
 * The domains administrators gave keys on the screen: [key => domain].
 *
 * @return array<string,string>
 */
function wp_go_site_texts_given_domains(): array
{
    $domains = get_option(WP_GO_SITE_TEXTS_DOMAINS_OPTION, []);

    return is_array($domains) ? array_map('strval', $domains) : [];
}

/** Whether the code declares a key's domain (so the screen can't change it). */
function wp_go_site_texts_domain_from_code(string $key): bool
{
    return isset(wp_go_site_texts_used()['domains'][ $key ]);
}

/** A key's domain: General when it has none. */
function wp_go_site_texts_domain_of(string $key): string
{
    return wp_go_site_texts_key_domains()[ $key ] ?? WP_GO_SITE_TEXTS_GENERAL;
}

/**
 * Give keys a domain, as an administrator: [key => domain], General (or '')
 * to take one back. Keys no longer listed lose theirs.
 *
 * @param array<string,string> $changes
 */
function wp_go_site_texts_set_domains(array $changes): void
{
    $domains = wp_go_site_texts_given_domains();

    foreach ($changes as $key => $domain) {
        $domain = sanitize_key((string) $domain);

        if ($domain === '' || $domain === WP_GO_SITE_TEXTS_GENERAL) {
            unset($domains[ $key ]);
        } else {
            $domains[ (string) $key ] = $domain;
        }
    }

    // only keys still in the list
    $domains = array_intersect_key($domains, wp_go_site_texts());
    ksort($domains, SORT_STRING);

    update_option(WP_GO_SITE_TEXTS_DOMAINS_OPTION, $domains, false);
}

/**
 * The domains: General, those set up on the Languages screen, and any a key
 * has that isn't set up yet (found in the code, say), which only
 * administrators edit until it is. [domain => ['name' => …, 'roles' => […]]],
 * General first.
 *
 * Until domains are set up, General keeps what the screen's old "Who can use
 * it" said: anyone with `edit_pages`, as the roles that have it, or
 * administrators only.
 *
 * @return array<string,array{name:string, roles:string[]}>
 */
function wp_go_site_texts_domains(): array
{
    $configured = \WpGo\Languages\Core\setting('site_texts', 'domains', null);

    if (!is_array($configured)) {
        $editors = \WpGo\Languages\Core\setting('site_texts', 'capability') !== 'manage_options';
        $configured = [WP_GO_SITE_TEXTS_GENERAL => ['name' => '', 'roles' => $editors ? wp_go_site_texts_roles_with('edit_pages') : []]];
    }

    $domains = [WP_GO_SITE_TEXTS_GENERAL => [
        'name'  => wp_go_site_texts_general_name(),
        'roles' => [],
    ]];

    foreach ($configured as $slug => $domain) {
        $slug = sanitize_key((string) $slug);

        if ($slug === '') {
            continue;
        }

        $domains[ $slug ] = [
            'name'  => trim((string) ($domain['name'] ?? '')) ?: ($domains[ $slug ]['name'] ?? $slug),
            'roles' => array_values(array_map('strval', (array) ($domain['roles'] ?? []))),
        ];
    }

    foreach (array_unique(wp_go_site_texts_key_domains()) as $slug) {
        $domains[ $slug ] ??= ['name' => $slug, 'roles' => []];
    }

    return $domains;
}

/**
 * The roles that have a capability, administrators left out (they edit every
 * domain anyway), as the Languages screen lists roles.
 *
 * @return string[]
 */
function wp_go_site_texts_roles_with(string $capability): array
{
    $roles = array_filter(wp_roles()->roles, fn (array $role) => !empty($role['capabilities'][ $capability ]));

    return array_values(array_diff(array_keys($roles), ['administrator']));
}

/** General's own name, in the admin's language. */
function wp_go_site_texts_general_name(): string
{
    return _x('General', 'src/SiteTexts/texts.php', 'wp-go-languages');
}

/**
 * The domains a user edits: every one for an administrator, else those
 * giving one of their roles.
 *
 * @return string[]
 */
function wp_go_site_texts_user_domains(?int $user_id = null): array
{
    $user = $user_id === null ? wp_get_current_user() : get_userdata($user_id);

    if (!$user instanceof WP_User || !$user->exists()) {
        return [];
    }

    $domains = wp_go_site_texts_domains();

    if (user_can($user, 'manage_options')) {
        return array_keys($domains);
    }

    return array_keys(array_filter($domains, fn (array $domain) => (bool) array_intersect($domain['roles'], $user->roles)));
}

/** Whether the current user edits a key's texts: its domain is one of theirs. */
function wp_go_site_texts_can_edit(string $key): bool
{
    return in_array(wp_go_site_texts_domain_of($key), wp_go_site_texts_user_domains(), true);
}

/**
 * `wgl_site_texts`, the screen's capability, to whoever edits at least one
 * domain: on `user_has_cap`, so roles need nothing added.
 *
 * @param array<string,bool> $allcaps
 * @param string[]           $caps
 * @param array<int,mixed>   $args
 * @return array<string,bool>
 */
function wp_go_site_texts_grant(array $allcaps, array $caps, array $args, WP_User $user): array
{
    if (in_array(wp_go_site_texts_capability(), $caps, true)) {
        $allcaps[ wp_go_site_texts_capability() ] = !empty($allcaps['manage_options'])
            || (bool) array_filter(wp_go_site_texts_domains(), fn (array $domain) => (bool) array_intersect($domain['roles'], $user->roles));
    }

    return $allcaps;
}

add_filter('user_has_cap', 'wp_go_site_texts_grant', 10, 4);
