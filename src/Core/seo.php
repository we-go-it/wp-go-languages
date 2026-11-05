<?php

namespace WpGo\Languages\Core;

/**
 * What search engines read of the languages:
 *
 *   - `<link rel="alternate" hreflang>` in the head of a translated page, one
 *     per published translation, itself included, and `x-default` for the
 *     default language's;
 *   - the same alternates in Yoast's sitemap, `<xhtml:link>` in each `<url>`
 *     (one sitemap holds every language: Yoast reads the posts with its own
 *     SQL, not a WP_Query, so the language clause never applies to it).
 *
 * Canonicals and `og:locale` need nothing here: the permalinks are the
 * page's own language's (links.php), the locale the request's.
 */

/**
 * A language's hreflang: its locale as BCP 47 (`it-IT`, `en-GB`).
 */
function hreflang_code(string $code): string
{
    $locale = language($code)['locale'] ?? $code;

    /**
     * A language's hreflang value.
     *
     * @param string $hreflang `it-IT`, from the locale.
     * @param string $code     The language.
     */
    return (string) apply_filters('wp_go_languages/hreflang', str_replace('_', '-', $locale), $code);
}

/**
 * The alternates of a page, or of a term's archive: `[hreflang => url]`, its published
 * translations and `x-default`. Empty when it has none but itself: a page's own link
 * alone tells a search engine nothing.
 *
 * @return array<string,string>
 */
function alternates(int $id, string $kind = 'post'): array
{
    $out   = [];
    $count = 0;

    foreach (translations($id, $kind) as $code => $translation) {
        // Only what a search engine can read: published, with no password, in
        // a language that isn't hidden.
        if (is_hidden_language($code) || ($kind === 'post' && (string) get_post_field('post_password', $translation) !== '')) {
            continue;
        }

        $url = translated_url($translation, $kind);

        if ($url === null) {
            continue;
        }

        $out[hreflang_code($code)] = $url;
        $count++;

        if ($code === default_language()) {
            $out['x-default'] = $url;
        }
    }

    // counted by language: x-default is a second entry, not a second page
    return $count > 1 ? $out : [];
}

/**
 * The alternates of the page being shown: a translated post (the posts page too), a
 * translated term's archive, or, with no static front page, the blog's home in every
 * language.
 *
 * @return array<string,string>
 */
function current_alternates(): array
{
    $item = queried_item();

    if ($item) {
        return alternates($item[0], $item[1]);
    }

    if (is_front_page() && get_option('show_on_front') !== 'page') {
        $out = [];

        foreach (codes() as $code) {
            if (!is_hidden_language($code)) {
                $out[hreflang_code($code)] = home_url_for($code);
            }
        }

        $out['x-default'] = home_url_for(default_language());

        return $out;
    }

    return [];
}

/**
 * The head's hreflang links, and an `og:locale:alternate` for each other
 * language the page is in (Yoast prints the page's own `og:locale`).
 */
function print_hreflang(): void
{
    foreach (current_alternates() as $hreflang => $url) {
        printf('<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr($hreflang), esc_url($url));
    }

    foreach (current_alternate_locales() as $locale) {
        printf('<meta property="og:locale:alternate" content="%s" />' . "\n", esc_attr($locale));
    }
}

/**
 * The locales of the other languages the page shown is published in.
 *
 * @return list<string>
 */
function current_alternate_locales(): array
{
    $item = queried_item();

    if (!$item) {
        return [];
    }

    $locales = [];

    foreach (translations($item[0], $item[1]) as $code => $id) {
        $readable = !is_hidden_language($code) && translated_url($id, $item[1]) !== null && ($item[1] !== 'post' || (string) get_post_field('post_password', $id) === '');

        if ($code !== current() && $readable) {
            $locales[] = (string) (language($code)['locale'] ?? '');
        }
    }

    return array_values(array_filter($locales));
}

/**
 * Yoast told of a post once, as the request ends, however many times its language and
 * group changed in it (a translation made changes both): each telling reads the author's
 * every page. Not at all while `$pause` says so, as a migration of a whole site does,
 * whose every page Yoast is better told of once, with `wp yoast index --reindex`.
 */
function refresh_seo_later(int $post_id, ?bool $pause = null): void
{
    static $ids = [];
    static $paused = false;

    if ($pause !== null) {
        $paused = $pause;

        return;
    }

    if ($paused) {
        return;
    }

    if (!$ids) {
        add_action('shutdown', function () use (&$ids) {
            foreach (array_keys($ids) as $id) {
                refresh_seo((int) $id);
            }

            $ids = [];
        }, 1);
    }

    $ids[$post_id] = true;
}

/**
 * Tell Yoast a post's URL or its translations changed without a save: its
 * language set, linked to an original or unlinked.
 *
 * In production Yoast keeps each post's permalink in its index (its
 * "indexable", built on save), and its sitemaps cached: the index is built
 * again (Indexable_Post_Watcher::build_indexable(), which reads the
 * permalink as it is now: in the post's language), and the post's sitemap
 * dropped from the cache, its alternates with it.
 */
function refresh_seo(int $post_id): void
{
    if ($post_id <= 0 || !function_exists('YoastSEO') || !get_post($post_id)) {
        return;
    }

    try {
        $watcher = YoastSEO()->classes->get(\Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher::class);

        if ($watcher && method_exists($watcher, 'build_indexable')) {
            $watcher->build_indexable($post_id);
        }
    } catch (\Throwable $error) {
        // Yoast's internals moved: its index catches up on the next save
    }

    if (class_exists('WPSEO_Sitemaps_Cache')) {
        \WPSEO_Sitemaps_Cache::invalidate_post($post_id);
    }
}

/**
 * Yoast's `<urlset>` with the xhtml namespace the alternates are in.
 */
function sitemap_urlset(string $urlset): string
{
    if (str_contains($urlset, 'xmlns:xhtml')) {
        return $urlset;
    }

    return str_replace('<urlset ', '<urlset xmlns:xhtml="http://www.w3.org/1999/xhtml" ', $urlset);
}

/**
 * A sitemap entry of a translated post, with its alternates kept for
 * sitemap_url().
 *
 * @param array<string,mixed>|false $url
 * @param mixed                     $post
 * @return array<string,mixed>|false
 */
function sitemap_entry($url, string $type, $post)
{
    if (!is_array($url)) {
        return $url;
    }

    sitemap_entry_seen((string) ($url['loc'] ?? ''));

    if ($type === 'term' && $post instanceof \WP_Term && is_translated_taxonomy($post->taxonomy)) {
        // a hidden language's archives aren't listed, as its pages aren't
        if (is_hidden_language(language_of($post->term_id, 'term'))) {
            return false;
        }

        $alternates = alternates($post->term_id, 'term');
    } elseif ($type !== 'post' || !$post instanceof \WP_Post || !is_translated_post_type($post->post_type)) {
        return $url;
    } elseif (is_hidden_language(language_of($post->ID))) {
        // a hidden language's pages aren't listed: false is how Yoast drops an entry
        return false;
    } else {
        $alternates = alternates($post->ID);
    }

    if ($alternates) {
        $url['wgl_alternates'] = $alternates;
    }

    return $url;
}

/**
 * The addresses sitemap_entry() was asked about: a page alone among them has no
 * alternates, and isn't looked up again by its address (sitemap_url()), a dozen queries
 * for each of a sitemap's thousand. Asked with `$check`, whether one was.
 */
function sitemap_entry_seen(string $loc, bool $check = false): bool
{
    static $seen = [];

    if ($check) {
        return isset($seen[$loc]);
    }

    $seen[$loc] = true;

    return true;
}

/**
 * Yoast reads a sitemap's posts a hundred at a time, and reads their meta at once before
 * looking at each: this is the only moment it says which they are, so their languages
 * and groups are read there too, not one post at a time (sitemap_entry()). It changes
 * nothing of the meta's read.
 *
 * @param mixed      $check
 * @param array<int> $ids
 * @return mixed
 */
function prime_sitemap_posts($check, $ids)
{
    if (get_query_var('sitemap') && is_array($ids) && count($ids) > 1) {
        prime_translations($ids);
    }

    return $check;
}

/**
 * One `<url>` of Yoast's sitemap, with its `<xhtml:link>` alternates.
 *
 * Most entries bring them from sitemap_entry(). The ones Yoast adds itself,
 * with no post (the home page, as its "first link"), are looked up by their
 * address.
 *
 * @param array<string,mixed> $url
 */
function sitemap_url(string $output, array $url): string
{
    $alternates = $url['wgl_alternates'] ?? null;

    if (!is_array($alternates) && !empty($url['loc']) && !sitemap_entry_seen((string) $url['loc'], true)) {
        $id         = url_to_post_id((string) $url['loc']);
        $alternates = $id && is_translatable($id) ? alternates($id) : [];
    }

    if (!$alternates) {
        return $output;
    }

    $links = '';

    foreach ($alternates as $hreflang => $href) {
        $links .= sprintf(
            "\t\t<xhtml:link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n",
            esc_attr((string) $hreflang),
            esc_url((string) $href)
        );
    }

    // before the closing tag, on lines of their own like Yoast's; a callback, as an
    // address may hold a `$`
    return (string) preg_replace_callback('#\s*</url>#', fn () => "\n" . rtrim($links) . "\n\t</url>", $output, 1);
}
