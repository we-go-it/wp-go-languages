<?php

/**
 * The Admin strings tab of the Translation strings screen, for administrators: every
 * admin string the code has, grouped by domain and file, a field for the language
 * picked in the admin bar, its English text under it.
 *
 * It looks and works as the Site texts tab does (src/SiteTexts/site-texts.js): only
 * the fields changed are posted, and "Suggest a translation" asks the browser to
 * translate the English text.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The tab's slug in the address: `admin.php?page=wg-site-texts&tab=admin`. */
const WP_GO_ADMIN_STRINGS_TAB = 'admin';

/** Whether the screen shows the Admin strings tab. */
function wp_go_admin_strings_tab_open(): bool
{
    return current_user_can('manage_options') && wp_go_site_texts_param($_GET, 'tab') === WP_GO_ADMIN_STRINGS_TAB;
}

/** The tabs, above either tab's content: for administrators only. */
function wp_go_admin_strings_render_tabs(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $admin = wp_go_admin_strings_tab_open();
    $base = admin_url('admin.php?page=' . WP_GO_SITE_TEXTS_PAGE);
    ?>
    <nav class="nav-tab-wrapper wg-site-texts__tabs">
        <a href="<?php echo esc_url($base); ?>" class="nav-tab<?php echo $admin ? '' : ' nav-tab-active'; ?>"><?php echo esc_html(_x('Site strings', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?></a>
        <a href="<?php echo esc_url(add_query_arg('tab', WP_GO_ADMIN_STRINGS_TAB, $base)); ?>" class="nav-tab<?php echo $admin ? ' nav-tab-active' : ''; ?>"><?php echo esc_html(_x('Admin strings', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?></a>
    </nav>
    <?php
}

/** The tab. */
function wp_go_admin_strings_render(): void
{
    $view = wp_go_admin_strings_view();
    ?>
    <div class="wrap wg-site-texts">
        <h1 class="wp-heading-inline"><?php echo esc_html(_x('Translation strings', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?></h1>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wg-site-texts__inline-form">
            <input type="hidden" name="action" value="wp_go_admin_strings_scan">
            <?php wp_nonce_field('wp_go_admin_strings_scan'); ?>
            <button type="submit" class="page-title-action" title="<?php echo esc_attr(_x('Reads the code for its admin strings: new ones are added, those it no longer has removed with their translations.', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?>"><?php echo esc_html(_x('Scan code', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?></button>
        </form>

        <?php wp_go_site_texts_render_ai_button(); ?>

        <hr class="wp-header-end">

        <?php wp_go_admin_strings_render_tabs(); ?>

        <p class="description">
            <?php echo esc_html(_x('The admin\'s own words, as the code writes them in English: labels, buttons, notices of the theme and its plugins. These are the language picked in the admin bar: translated here, they show in each user\'s admin language.', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?>
        </p>

        <?php wp_go_site_texts_render_language_note(); ?>
        <?php wp_go_site_texts_render_ai_status(); ?>

        <?php
        wp_go_admin_strings_render_notices($view);
        wp_go_admin_strings_render_filters($view);
        wp_go_admin_strings_render_table($view);
        ?>
    </div>
    <?php
}

/**
 * What the tab shows, from the strings, the translations and the address
 * (search, domain, untranslated only). The address is read with Site texts'
 * wp_go_site_texts_param(): `?s[]=x` is no search, not an error.
 *
 * @return array<string,mixed>
 */
function wp_go_admin_strings_view(): array
{
    $state = wp_go_admin_strings_state();
    // the language picked in the admin bar: its locale's translations
    $code = wp_go_site_texts_language();
    $language = \WpGo\Languages\Core\language($code);
    $locale = (string) ($language['locale'] ?? $code);
    $domains = array_values(array_unique(array_column($state['strings'], 'domain')));
    sort($domains);

    $domain = wp_go_site_texts_param($_GET, 'domain');
    $view = [
        'state'     => $state,
        'locale'    => $locale,
        'code'      => $code,
        'language_name' => (string) ($language['name'] ?? $code),
        'domains'   => $domains,
        'domain'    => in_array($domain, $domains, true) ? $domain : '',
        'search'    => trim(wp_go_site_texts_param($_GET, 's')),
        'untranslated_only' => !empty($_GET['untranslated']),
    ];

    // grouped by domain, then file
    $view['groups'] = [];
    $view['untranslated'] = 0;

    $translations = wp_go_admin_strings_translations();

    foreach ($state['strings'] as $id => $string) {
        $key = wp_go_admin_strings_lookup_key($string['context'], $string['text']);
        $value = (string) ($translations[$locale][$string['domain']][$key] ?? '');
        $missing = $value === '';

        $view['untranslated'] += $missing ? 1 : 0;

        if ($view['domain'] !== '' && $string['domain'] !== $view['domain']) {
            continue;
        }

        if ($view['untranslated_only'] && !$missing) {
            continue;
        }

        if ($view['search'] !== '' && mb_stripos(implode("\n", [$string['text'], $string['context'], $value]), $view['search']) === false) {
            continue;
        }

        $view['groups'][$string['domain'] . ' · ' . $string['context']][(string) $id] = $string + ['value' => $value];
    }

    ksort($view['groups'], SORT_NATURAL);

    return $view;
}

/**
 * What was just done, and what the last scan warned about.
 *
 * @param array<string,mixed> $view
 */
function wp_go_admin_strings_render_notices(array $view): void
{
    $notice = fn (string $type, string $message) => printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($message));

    // counts in words, as on the Site texts tab (wp_go_site_texts_count())
    $count = fn (string $name, string $one, string $many) => wp_go_site_texts_count((int) wp_go_site_texts_param($_GET, $name), $one, $many);

    if (isset($_GET['saved'])) {
        $notice('success', (int) wp_go_site_texts_param($_GET, 'saved')
            ? $count('saved', _x('1 translation saved.', 'src/AdminStrings/screen.php', 'wp-go-languages'), _x('%s translations saved.', 'src/AdminStrings/screen.php', 'wp-go-languages'))
            : _x('Nothing had changed.', 'src/AdminStrings/screen.php', 'wp-go-languages'));
    }

    if (isset($_GET['found'])) {
        $notice('success', sprintf(
            /* how many strings in the code, how many of them new, how many removed */
            _x('%1$s: %2$s, %3$s.', 'src/AdminStrings/screen.php', 'wp-go-languages'),
            $count('found', _x('1 string in the code', 'src/AdminStrings/screen.php', 'wp-go-languages'), _x('%s strings in the code', 'src/AdminStrings/screen.php', 'wp-go-languages')),
            $count('added', _x('1 new', 'src/AdminStrings/screen.php', 'wp-go-languages'), _x('%s new', 'src/AdminStrings/screen.php', 'wp-go-languages')),
            $count('removed', _x('1 no longer there, removed with its translations', 'src/AdminStrings/screen.php', 'wp-go-languages'), _x('%s no longer there, removed with their translations', 'src/AdminStrings/screen.php', 'wp-go-languages'))
        ));
    }

    if (!$view['state']['scanned']) {
        $notice('info', _x('The code hasn\'t been read yet: Scan code lists its strings.', 'src/AdminStrings/screen.php', 'wp-go-languages'));
    }

    if ($view['state']['warnings']) {
        ?>
        <details class="wg-site-texts__warning">
            <summary><?php echo esc_html(wp_go_site_texts_count(count($view['state']['warnings']), _x('1 thing the last scan couldn\'t read:', 'src/AdminStrings/screen.php', 'wp-go-languages'), _x('%s things the last scan couldn\'t read:', 'src/AdminStrings/screen.php', 'wp-go-languages'))); ?></summary>
            <ul>
                <?php foreach ($view['state']['warnings'] as $warning) : ?>
                    <li><code><?php echo esc_html($warning); ?></code></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php
    }
}

/**
 * The search, the domain, and "Only untranslated".
 *
 * @param array<string,mixed> $view
 */
function wp_go_admin_strings_render_filters(array $view): void
{
    ?>
    <form method="get" class="wg-site-texts__filters">
        <input type="hidden" name="page" value="<?php echo esc_attr(WP_GO_SITE_TEXTS_PAGE); ?>">
        <input type="hidden" name="tab" value="<?php echo esc_attr(WP_GO_ADMIN_STRINGS_TAB); ?>">

        <input type="search" name="s" value="<?php echo esc_attr($view['search']); ?>" placeholder="<?php echo esc_attr(_x('Search a string or its translations', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?>">
        <?php submit_button(_x('Search', 'src/AdminStrings/screen.php', 'wp-go-languages'), 'secondary', '', false); ?>

        <?php if (count($view['domains']) > 1) : ?>
            <label>
                <span class="screen-reader-text"><?php echo esc_html(_x('Domain', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?></span>
                <select name="domain" onchange="this.form.submit()">
                    <option value=""><?php echo esc_html(_x('Every domain', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?></option>
                    <?php foreach ($view['domains'] as $domain) : ?>
                        <option value="<?php echo esc_attr($domain); ?>" <?php selected($view['domain'], $domain); ?>><?php echo esc_html($domain); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <label>
            <input type="checkbox" name="untranslated" value="1" <?php checked($view['untranslated_only']); ?> onchange="this.form.submit()">
            <?php echo esc_html(sprintf(_x('Only untranslated (%d)', 'src/AdminStrings/screen.php', 'wp-go-languages'), $view['untranslated'])); ?>
        </label>
    </form>
    <?php
}

/**
 * The table, inside the save form: a heading per domain and file, a row per
 * string.
 *
 * @param array<string,mixed> $view
 */
function wp_go_admin_strings_render_table(array $view): void
{
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wg-site-texts__form">
        <input type="hidden" name="action" value="wp_go_admin_strings_save">
        <?php wp_nonce_field('wp_go_admin_strings_save'); ?>

        <table class="widefat striped wg-site-texts__table">
            <thead>
                <tr>
                    <th class="wg-site-texts__col-lang" data-column="lang-<?php echo esc_attr($view['code']); ?>" data-name="<?php echo esc_attr($view['language_name']); ?>"><?php echo esc_html($view['language_name']); ?> <code><?php echo esc_html($view['locale']); ?></code></th>
                </tr>
            </thead>

            <?php if (!$view['groups']) : ?>
                <tbody>
                    <tr>
                        <td><?php echo esc_html($view['state']['strings']
                            ? _x('No string matches.', 'src/AdminStrings/screen.php', 'wp-go-languages')
                            : _x('No strings yet.', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?></td>
                    </tr>
                </tbody>
            <?php endif; ?>

            <?php foreach ($view['groups'] as $group => $strings) : ?>
                <tbody>
                    <tr class="wg-site-texts__group">
                        <th><code class="wg-site-texts__group-name"><?php echo esc_html($group); ?></code></th>
                    </tr>

                    <?php foreach ($strings as $id => $string) : ?>
                        <tr>
                            <?php wp_go_admin_strings_render_cell((string) $id, $string, $view['locale'], $view['code'], $view['language_name'], $string['value']); ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            <?php endforeach; ?>
        </table>

        <?php if ($view['groups']) : ?>
            <div class="wg-site-texts__submit">
                <?php submit_button(_x('Save', 'src/AdminStrings/screen.php', 'wp-go-languages'), 'primary', 'submit', false); ?>
                <span class="wg-site-texts__changed" aria-live="polite"></span>
            </div>
        <?php endif; ?>
    </form>
    <?php
}

/**
 * A string's translation in the language picked: its field, the English as its placeholder
 * and under it. `data-value`, `data-from`, `data-source` as on the Site strings tab; the
 * language is `data-code`, as `data-lang` is its locale.
 *
 * @param array{domain:string, context:string, text:string} $string
 */
function wp_go_admin_strings_render_cell(string $id, array $string, string $locale, string $code, string $name, string $value): void
{
    $field = sprintf('strings[%s][%s]', $locale, $id);
    $label = sprintf(/* the English text, the language */ _x('«%1$s» in %2$s', 'src/AdminStrings/screen.php', 'wp-go-languages'), $string['text'], $name);
    $long = mb_strlen($string['text']) > 60 || mb_strlen($value) > 60 || str_contains($value, "\n");
    $lang = \WpGo\Languages\Core\hreflang_code($code);
    ?>
    <td class="wg-site-texts__cell" data-lang="<?php echo esc_attr($locale); ?>" data-code="<?php echo esc_attr($code); ?>" data-value="<?php echo esc_attr($value); ?>" data-from="en" data-source="<?php echo esc_attr($string['text']); ?>">
        <div class="wg-site-texts__field has-suggest">
            <?php if ($long) : ?>
                <textarea name="<?php echo esc_attr($field); ?>" rows="3" class="large-text" lang="<?php echo esc_attr($lang); ?>" placeholder="<?php echo esc_attr($string['text']); ?>" aria-label="<?php echo esc_attr($label); ?>"><?php echo esc_textarea($value); ?></textarea>
            <?php else : ?>
                <input type="text" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($value); ?>" class="large-text" lang="<?php echo esc_attr($lang); ?>" placeholder="<?php echo esc_attr($string['text']); ?>" aria-label="<?php echo esc_attr($label); ?>">
            <?php endif; ?>

            <button type="button" class="button-link wg-site-texts__suggest" data-from="en" data-to="<?php echo esc_attr($locale); ?>"
                title="<?php echo esc_attr(_x('Suggest a translation', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?>"
                aria-label="<?php echo esc_attr(_x('Suggest a translation', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?>" hidden>
                <span class="dashicons dashicons-translation" aria-hidden="true"></span>
            </button>
        </div>

        <p class="wg-site-texts__original" lang="en"><strong><?php echo esc_html(_x('English:', 'src/AdminStrings/screen.php', 'wp-go-languages')); ?></strong> <?php echo nl2br(esc_html($string['text'])); ?></p>

        <span class="description wg-site-texts__note" aria-live="polite"></span>
    </td>
    <?php
}
