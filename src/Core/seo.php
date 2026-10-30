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
 * The alternates of a page: `[hreflang => url]`, its published translations
 * and `x-default`. Empty when it has no published translation but itself:
 * a page's own link alone tells a search engine nothing.
 *
 * @return array<string,string>
 */
function alternates(int $post_id): array
{
    $front = get_option('show_on_front') === 'page' ? raw_front_page() : 0;
    $out   = [];
    $count = 0;

    foreach (translations($post_id) as $code => $id) {
        // Only what a search engine can read: published, with no password.
        if (get_post_status($id) !== 'publish' || (string) get_post_field('post_password', $id) !== '') {
            continue;
        }

        $url = $front && translation($front, $code) === $id ? home_url_for($code) : (string) get_permalink($id);

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
 * The alternates of the page being shown: a translated post, or, with no
 * static front page, the blog's home in every language.
 *
 * @return array<string,string>
 */
function current_alternates(): array
{
    if (is_singular()) {
        $post = get_queried_object();

        return $post instanceof \WP_Post && is_translated_post_type($post->post_type) ? alternates($post->ID) : [];
    }

    if (is_front_page() && get_option('show_on_front') !== 'page') {
        $out = [];

        foreach (codes() as $code) {
            $out[hreflang_code($code)] = home_url_for($code);
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
    $post = is_singular() ? get_queried_object() : null;

    if (!$post instanceof \WP_Post || !is_translated_post_type($post->post_type)) {
        return [];
    }

    $locales = [];

    foreach (translations($post->ID) as $code => $id) {
        if ($code !== current() && get_post_status($id) === 'publish' && (string) get_post_field('post_password', $id) === '') {
            $locales[] = (string) (language($code)['locale'] ?? '');
        }
    }

    return array_values(array_filter($locales));
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
    if (!is_array($url) || $type !== 'post' || !$post instanceof \WP_Post || !is_translated_post_type($post->post_type)) {
        return $url;
    }

    $alternates = alternates($post->ID);

    if ($alternates) {
        $url['wgl_alternates'] = $alternates;
    }

    return $url;
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

    if (!is_array($alternates) && !empty($url['loc'])) {
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

    // before the closing tag, on lines of their own like Yoast's
    return (string) preg_replace('#\s*</url>#', "\n" . rtrim($links) . "\n\t</url>", $output, 1);
}
