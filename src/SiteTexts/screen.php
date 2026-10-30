<?php

/**
 * The Translation strings screen (Site texts): every key, grouped, with a
 * field per language. Its menu is right under Languages in the sidebar.
 *
 * How it reads, top to bottom:
 *
 *   - the title, with "Add a key" and "Scan code" for administrators
 *   - the notices of what was just done
 *   - while scanning, the "In the code" panel: keys to add, keys unused
 *   - the search and the filters (incomplete only, unused only)
 *   - the table: a row per key, a column per language. Each user hides the
 *     languages they don't need in Screen Options ("Columns", WordPress's
 *     own, saved per user); a click on a column's header sorts the rows by
 *     it, within each group; past two languages shown, the table scrolls
 *     sideways, the key column staying in place
 *   - the "Add a key" dialog, and a key's details dialog
 *
 * A text shows as text and turns into a field when clicked; only the fields
 * opened are posted, so a save writes nothing else. The styles and the
 * behaviour are in site-texts.css and site-texts.js.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function (): void {
    $hook = add_menu_page(
        _x('Translation strings', 'src/SiteTexts/screen.php', 'wp-go-languages'),
        _x('Translation strings', 'src/SiteTexts/screen.php', 'wp-go-languages'),
        wp_go_site_texts_capability(),
        WP_GO_SITE_TEXTS_PAGE,
        'wp_go_site_texts_screen',
        'dashicons-translation',
        // right under Languages (82)
        83
    );

    // the language columns, in Screen Options
    add_filter("manage_{$hook}_columns", 'wp_go_site_texts_columns');
});

/**
 * The table's columns Screen Options lists, to hide: a column per site
 * language, `lang-{code}`, on both tabs (the Admin strings tab's locales are
 * the same languages). Not the key, which can't be hidden.
 *
 * @return array<string,string>
 */
function wp_go_site_texts_columns(): array
{
    $columns = [];

    foreach (wp_go_site_texts_languages() as $code => $name) {
        $columns[ "lang-$code" ] = $name;
    }

    return $columns;
}

/**
 * The languages the user hid in Screen Options: [code, …]. None outside the
 * screen (wp-cli, a test).
 *
 * @return string[]
 */
function wp_go_site_texts_hidden_languages(): array
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    if (!$screen || !function_exists('get_hidden_columns')) {
        return [];
    }

    $hidden = [];

    foreach ((array) get_hidden_columns($screen) as $column) {
        if (str_starts_with((string) $column, 'lang-')) {
            $hidden[] = substr((string) $column, strlen('lang-'));
        }
    }

    return $hidden;
}

/**
 * A language's cells' classes: its Screen Options column (WordPress shows
 * and hides `.column-{name}`), `hidden` when the user hid it.
 *
 * @param string[] $hidden
 */
function wp_go_site_texts_column_class(string $language, array $hidden): string
{
    return "column-lang-$language" . (in_array($language, $hidden, true) ? ' hidden' : '');
}

/**
 * The box the table scrolls in: sideways once more than two languages are
 * shown (`is-wide`), the number shown in `--wg-site-texts-langs` for the
 * table's width. site-texts.js keeps both right as columns are hidden.
 *
 * @param string[] $languages The languages' codes, in the table.
 * @param string[] $hidden
 */
function wp_go_site_texts_scroll_open(array $languages, array $hidden): void
{
    $shown = count(array_diff($languages, $hidden));

    printf('<div class="wg-site-texts__scroll%s" style="--wg-site-texts-langs: %d">', $shown > 2 ? ' is-wide' : '', (int) $shown);
}

add_action('admin_enqueue_scripts', 'wp_go_site_texts_assets');

/**
 * The screen's styles and script, on the screen only, with the texts and
 * addresses the script needs.
 */
function wp_go_site_texts_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_' . WP_GO_SITE_TEXTS_PAGE) {
        return;
    }

    $css = 'SiteTexts/site-texts.css';
    $js  = 'SiteTexts/site-texts.js';

    wp_enqueue_style('wgl-site-texts', \WpGo\Languages\Core\asset_url($css), [], \WpGo\Languages\Core\asset_version($css));
    wp_enqueue_script('wgl-site-texts', \WpGo\Languages\Core\asset_url($js), [], \WpGo\Languages\Core\asset_version($js), true);
    wp_add_inline_script('wgl-site-texts', 'window.wgSiteTexts = ' . wp_json_encode(wp_go_site_texts_script_data()) . ';', 'before');

    // "Translate empty with AI": this plugin's, with AI set up
    if (wp_go_site_texts_ai_available()) {
        $ai = 'SiteTexts/site-texts-ai.js';

        wp_enqueue_script('wgl-site-texts-ai', \WpGo\Languages\Core\asset_url($ai), ['wgl-site-texts'], \WpGo\Languages\Core\asset_version($ai), true);
        wp_add_inline_script('wgl-site-texts-ai', 'window.wgSiteTextsAi = ' . wp_json_encode(wp_go_site_texts_ai_script_data()) . ';', 'before');
    }
}

/**
 * Whether the screen offers "Translate empty with AI": AI set up on the
 * Languages screen (Core\ai_enabled()), for who uses the screen. The route
 * (wgl/v1/ai/texts) asks the same.
 */
function wp_go_site_texts_ai_available(): bool
{
    return function_exists('WpGo\\Languages\\Core\\ai_enabled') && \WpGo\Languages\Core\ai_enabled()
        && (current_user_can('manage_options') || current_user_can(wp_go_site_texts_capability()));
}

/**
 * What site-texts-ai.js is given: its texts, the route and its nonce, and
 * the default language (Site strings' source).
 *
 * @return array<string,mixed>
 */
function wp_go_site_texts_ai_script_data(): array
{
    return [
        'route'    => rest_url('wgl/v1/ai/texts'),
        'nonce'    => wp_create_nonce('wp_rest'),
        'fallback' => wp_go_site_texts_default_language(),
        'texts'    => [
            'nothing' => _x('No empty translation to fill in among the rows shown.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            /* translators: 1: the request being sent, 2: how many */
            'running' => _x('Translating with AI: %1$s of %2$s…', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'done'    => [
                'one'  => _x('1 translation filled in: check it, then Save.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
                'many' => _x('%s translations filled in: check them, then Save.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            ],
            'failed'  => [
                'one'  => _x('1 couldn\'t be translated:', 'src/SiteTexts/screen.php', 'wp-go-languages'),
                'many' => _x('%s couldn\'t be translated:', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            ],
            /* translators: %s: the endpoint's error */
            'error'   => _x('The AI endpoint didn\'t answer: %s', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'missing' => _x('Missing from the answer.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            // the dialog: asked before it starts, then how far it got, and Stop
            'title'    => _x('Translate empty with AI', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'translate' => _x('Translate', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'cancel'   => _x('Cancel', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'stop'     => _x('Stop', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'stopping' => _x('Stopping after this part…', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'stopped'  => [
                'one'  => _x('Stopped: 1 translation filled in. Check it, then Save.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
                'many' => _x('Stopped: %s translations filled in. Check them, then Save.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            ],
            // asked before it starts: how many, into which languages
            'confirm' => [
                /* translators: 1: 1, 2: the languages */
                'one'  => _x('Fill in 1 empty translation (%2$s)? It\'s only filled in: check it, then Save.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
                /* translators: 1: how many, 2: the languages */
                'many' => _x('Fill in %1$s empty translations (%2$s)? They\'re only filled in: check them, then Save.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            ],
        ],
    ];
}

/**
 * "Translate empty with AI", beside the title, on both tabs: the script
 * (site-texts-ai.js) does the rest. Nothing without AI set up.
 */
function wp_go_site_texts_render_ai_button(): void
{
    if (!wp_go_site_texts_ai_available()) {
        return;
    }
    ?>
    <button type="button" class="page-title-action wg-site-texts__ai" title="<?php echo esc_attr(_x('Fills the empty translations of the rows shown with the AI set up on the Languages screen. Nothing is saved until you Save.', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>">
        <?php // the AI's sparkle, as the "+" dialog and the editor have it (src/Admin/_ai.js) ?>
        <svg class="wg-site-texts__ai-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9L12 2zm7 11l.9 2.6 2.6.9-2.6.9L19 20l-.9-2.6-2.6-.9 2.6-.9L19 13zM5 15l.7 1.8 1.8.7-1.8.7L5 20l-.7-1.8-1.8-.7 1.8-.7L5 15z"/></svg>
        <?php echo esc_html(_x('Translate empty with AI', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>
    </button>
    <?php
}

/** Where "Translate empty with AI" says how it's going, and what failed. */
function wp_go_site_texts_render_ai_status(): void
{
    if (!wp_go_site_texts_ai_available()) {
        return;
    }
    ?>
    <div class="wg-site-texts__ai-box">
        <p class="wg-site-texts__ai-status" aria-live="polite" hidden></p>
        <ul class="wg-site-texts__ai-errors"></ul>
    </div>
    <?php
}

/**
 * What site-texts.js is given: its texts, and where to ask Cache Tags
 * which cached pages print a key (null without Cache Tags).
 *
 * @return array<string,mixed>
 */
function wp_go_site_texts_script_data(): array
{
    $used = wp_go_site_texts_used();
    $when = $used['scanned'] ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $used['scanned']) : '';

    return [
        'texts' => [
            // editing
            /* how many texts */
            'changed'  => [
                'one'  => _x('1 changed, not saved yet', 'src/SiteTexts/screen.php', 'wp-go-languages'),
                'many' => _x('%s changed, not saved yet', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            ],
            'removeSelected' => [
                'one'  => _x('Remove the key selected, with its texts in every language? The code doesn\'t print it; another front end might.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
                'many' => _x('Remove the %s keys selected, with their texts in every language? The code doesn\'t print them; another front end might.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            ],

            // the translation suggestion
            'download' => _x('Downloading the language model: %d%%', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'cannot'   => _x('This browser cannot translate between these languages.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'noSource' => _x('Write the default language\'s text first.', 'src/SiteTexts/screen.php', 'wp-go-languages'),

            // a key's details
            'group'    => _x('Group', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'domain'   => _x('Domain', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'printed'  => _x('Printed in', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'nowhere'  => $used['scanned']
                ? sprintf(_x('Nowhere in the code, as of %s', 'src/SiteTexts/screen.php', 'wp-go-languages'), $when)
                : _x('Not known yet: the code hasn\'t been scanned.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'asOf'     => $used['scanned'] ? sprintf(_x('as of %s', 'src/SiteTexts/screen.php', 'wp-go-languages'), $when) : '',
            'empty'    => _x('Empty: shows the key', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'cached'   => _x('Cached on', 'src/SiteTexts/screen.php', 'wp-go-languages'),

            // where a key is cached
            'loading'  => _x('Loading…', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'failed'   => _x('The pages couldn\'t be loaded.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            /* how many cached pages */
            'count'    => [
                'one'  => _x('On 1 cached page, which saving it refreshes:', 'src/SiteTexts/screen.php', 'wp-go-languages'),
                'many' => _x('On %s cached pages, which saving it refreshes:', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            ],
            'none'     => _x('On no cached page now: saving it refreshes nothing.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'global'   => _x('On nearly every cached page (the footer, a menu…): saving it empties the whole cache.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            'unknown'  => _x('No cache here lists its pages.', 'src/SiteTexts/screen.php', 'wp-go-languages'),
            /* how many more pages, past those listed */
            'more'     => _x('and %d more', 'src/SiteTexts/screen.php', 'wp-go-languages'),
        ],

        // Cache Tags answers which cached pages print a key: a key is its own
        // "file" there, in the site-texts domain
        'cache' => function_exists('wp_go_cache_tags_text_tag') ? [
            'endpoint' => rest_url('wg-cache-tags/v1/text'),
            'nonce'    => wp_create_nonce('wp_rest'),
            'domain'   => WP_GO_SITE_TEXTS_DOMAIN,
        ] : null,
    ];
}

/** The screen: the Site texts tab, or for administrators the Admin strings one. */
function wp_go_site_texts_screen(): void
{
    if (function_exists('wp_go_admin_strings_tab_open') && wp_go_admin_strings_tab_open()) {
        wp_go_admin_strings_render();

        return;
    }

    $view = wp_go_site_texts_view();
    ?>
    <div class="wrap wg-site-texts">
        <?php
        wp_go_site_texts_render_header($view);
        wp_go_site_texts_render_notices();

        if ($view['scanning']) {
            wp_go_site_texts_render_code_panel($view);
        }

        wp_go_site_texts_render_filters($view);
        wp_go_site_texts_render_table($view);

        if ($view['can_manage']) {
            wp_go_site_texts_render_add_dialog();
        }

        wp_go_site_texts_render_details_dialog();
        ?>
    </div>
    <?php
}

/**
 * Everything the screen shows, worked out once from the stored texts and the
 * address (search, filters, scan).
 *
 * @return array<string,mixed>
 */
function wp_go_site_texts_view(): array
{
    $languages = wp_go_site_texts_languages();
    $can_manage = current_user_can('manage_options');

    // Only the keys in the user's domains, and of those, the domain picked.
    $domains = wp_go_site_texts_domains();
    $mine = wp_go_site_texts_user_domains();
    $domain = sanitize_key(wp_go_site_texts_param($_GET, 'domain'));
    $domain = in_array($domain, $mine, true) ? $domain : '';
    $key_domains = wp_go_site_texts_key_domains();
    $texts = array_filter(wp_go_site_texts(), fn ($values, $key) => in_array($key_domains[ $key ] ?? WP_GO_SITE_TEXTS_GENERAL, $mine, true), ARRAY_FILTER_USE_BOTH);

    // The code is read only when an administrator asks (Scan code, `scan=1`
    // in the address, which the filters and the saves keep).
    $can_scan = $can_manage && wp_go_site_texts_scan_config()['paths'];
    $scanning = $can_scan && !empty($_GET['scan']);
    $scan = $scanning ? wp_go_site_texts_scan() : null;
    $unused = $scanning ? array_flip((array) wp_go_site_texts_unused()) : [];

    $view = [
        'languages'    => $languages,
        'default'      => array_key_first($languages),
        'texts'        => $texts,
        'search'       => trim(wp_go_site_texts_param($_GET, 's')),
        'missing_only' => !empty($_GET['missing']),
        'unused_only'  => $scanning && !empty($_GET['unused']),
        'can_manage'   => $can_manage,
        'can_scan'     => $can_scan,
        'scanning'     => $scanning,
        'scan'         => $scan,
        'unused'       => $unused,
        'not_listed'   => $scan ? array_diff_key($scan['keys'], wp_go_site_texts()) : [],
        // where each key is printed, as of the code's last reading
        'used'         => wp_go_site_texts_used(),
        // the domains: all of them, the user's, the one picked, each key's
        'domains'      => $domains,
        'mine'         => array_intersect_key($domains, array_flip($mine)),
        'domain'       => $domain,
        'key_domains'  => $key_domains,
        // the languages hidden in Screen Options
        'hidden'       => array_values(array_intersect(wp_go_site_texts_hidden_languages(), array_keys($languages))),
    ];

    $view['missing_count'] = count(array_filter($texts, fn ($values) => wp_go_site_texts_is_incomplete($values, $languages)));
    $view['shown'] = array_filter($texts, fn ($values, $key) => wp_go_site_texts_is_shown((string) $key, $values, $view), ARRAY_FILTER_USE_BOTH);

    // grouped by the key's first part
    $view['groups'] = [];

    foreach ($view['shown'] as $key => $values) {
        $view['groups'][ wp_go_site_texts_group_of($key) ][ $key ] = $values;
    }

    // the checkbox column: while scanning, with unused keys among those shown
    $view['select'] = $can_manage && array_intersect_key($unused, $view['shown']);
    $view['columns'] = count($languages) - count($view['hidden']) + ($view['select'] ? 2 : 1);

    return $view;
}

/**
 * Whether a key lacks a value in one of the languages.
 *
 * @param array<string,string> $values
 * @param array<string,string> $languages
 */
function wp_go_site_texts_is_incomplete(array $values, array $languages): bool
{
    foreach (array_keys($languages) as $language) {
        if (($values[ $language ] ?? '') === '') {
            return true;
        }
    }

    return false;
}

/**
 * Whether a key is shown, by the filters and the search (which looks in the
 * key and its texts).
 *
 * @param array<string,string> $values
 * @param array<string,mixed>  $view
 */
function wp_go_site_texts_is_shown(string $key, array $values, array $view): bool
{
    if ($view['missing_only'] && !wp_go_site_texts_is_incomplete($values, $view['languages'])) {
        return false;
    }

    if ($view['unused_only'] && !isset($view['unused'][ $key ])) {
        return false;
    }

    if ($view['domain'] !== '' && ($view['key_domains'][ $key ] ?? WP_GO_SITE_TEXTS_GENERAL) !== $view['domain']) {
        return false;
    }

    if ($view['search'] === '') {
        return true;
    }

    $haystack = implode("\n", [$key, ...array_values(array_intersect_key($values, $view['languages']))]);

    return mb_stripos($haystack, $view['search']) !== false;
}

/**
 * This screen's address, without the previous action's notice: scanning,
 * or not.
 */
function wp_go_site_texts_url(bool $scanning): string
{
    if ($scanning) {
        return remove_query_arg(WP_GO_SITE_TEXTS_NOTICES, add_query_arg('scan', 1));
    }

    return remove_query_arg([...WP_GO_SITE_TEXTS_NOTICES, 'scan', 'unused']);
}

/**
 * The title, "Add a key" and "Scan code", and what the screen is for.
 *
 * @param array<string,mixed> $view
 */
function wp_go_site_texts_render_header(array $view): void
{
    ?>
    <h1 class="wp-heading-inline"><?php echo esc_html(_x('Translation strings', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></h1>

    <?php if ($view['can_manage']) : ?>
        <button type="button" class="page-title-action wg-site-texts__add-open"><?php echo esc_html(_x('Add a key', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></button>
    <?php endif; ?>

    <?php if ($view['can_scan']) : ?>
        <a href="<?php echo esc_url(wp_go_site_texts_url(true)); ?>" class="page-title-action" title="<?php echo esc_attr(_x('Reads the theme\'s code for the keys it prints: those the list lacks, to add, and those it no longer prints, to review and remove.', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>">
            <?php echo esc_html($view['scanning'] ? _x('Scan again', 'src/SiteTexts/screen.php', 'wp-go-languages') : _x('Scan code', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>
        </a>
    <?php endif; ?>

    <?php wp_go_site_texts_render_ai_button(); ?>

    <hr class="wp-header-end">

    <?php
    // the Admin strings tab beside this one, for administrators
    if (function_exists('wp_go_admin_strings_render_tabs')) {
        wp_go_admin_strings_render_tabs();
    }
    ?>

    <p class="description">
        <?php echo esc_html(_x('The labels the site prints on its own: buttons, headings of fixed sections, messages. Click a text to change it, a key for where it\'s printed. A text with no value in a language shows its key on the site.', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>
    </p>
    <?php
    wp_go_site_texts_render_ai_status();
}

/**
 * A count in words: `$one` for 1, `$many` (its %s the number) otherwise. Two
 * _x() strings rather than _n(), as the admin strings scan reads only _x().
 */
function wp_go_site_texts_count(int $count, string $one, string $many): string
{
    return $count === 1 ? $one : sprintf($many, number_format_i18n($count));
}

/** What was just done: the outcome an action left in the address (actions.php). */
function wp_go_site_texts_render_notices(): void
{
    $notice = fn (string $type, string $message) => printf(
        '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
        esc_attr($type),
        esc_html($message)
    );

    if (isset($_GET['saved'])) {
        $saved = (int) $_GET['saved'];

        $notice('success', $saved
            ? wp_go_site_texts_count($saved, _x('1 value saved.', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('%s values saved.', 'src/SiteTexts/screen.php', 'wp-go-languages'))
            : _x('Nothing had changed.', 'src/SiteTexts/screen.php', 'wp-go-languages'));
    }

    // a key added in the dialog, or why it wasn't
    $added = [
        '1'       => ['success', _x('Key added. Write its values below.', 'src/SiteTexts/screen.php', 'wp-go-languages')],
        'exists'  => ['warning', _x('That key is already in the list.', 'src/SiteTexts/screen.php', 'wp-go-languages')],
        'invalid' => ['error', _x('A key is lowercase letters, digits and underscores, in parts joined by dots, the first its group: footer.contact_us.', 'src/SiteTexts/screen.php', 'wp-go-languages')],
        'nested'  => ['error', _x('A key can\'t be the start of another (footer.title and footer.title.short): the API nests them.', 'src/SiteTexts/screen.php', 'wp-go-languages')],
    ];

    $outcome = wp_go_site_texts_param($_GET, 'added');

    if (isset($added[ $outcome ])) {
        $notice(...$added[ $outcome ]);
    }

    // counts: keys removed or moved, keys the code prints added, or not
    $counted = [
        'removed' => ['success', _x('1 key removed.', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('%s keys removed.', 'src/SiteTexts/screen.php', 'wp-go-languages')],
        'moved'   => ['success', _x('1 key moved to another domain.', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('%s keys moved to another domain.', 'src/SiteTexts/screen.php', 'wp-go-languages')],
        'found'   => ['success', _x('1 key the code prints added. Write its values below.', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('%s keys the code prints added. Write their values below.', 'src/SiteTexts/screen.php', 'wp-go-languages')],
        'skipped' => ['warning', _x('1 key the code prints couldn\'t be added: it is the start of another key, or another is the start of it. Rename one in the code.', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('%s keys the code prints couldn\'t be added: each is the start of another key, or another is the start of it. Rename one in the code.', 'src/SiteTexts/screen.php', 'wp-go-languages')],
    ];

    foreach ($counted as $name => [$type, $one, $many]) {
        if (!empty($_GET[ $name ])) {
            $notice($type, wp_go_site_texts_count((int) $_GET[ $name ], $one, $many));
        }
    }
}

/**
 * While scanning: what the code prints that the list lacks (with "Add
 * them"), how many listed keys it doesn't print, and the calls it couldn't
 * read (their key, or their domain).
 *
 * @param array<string,mixed> $view
 */
function wp_go_site_texts_render_code_panel(array $view): void
{
    $scan = $view['scan'];
    ?>
    <div class="wg-site-texts__code">
        <h2>
            <?php echo esc_html(_x('In the code', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>
            <a class="button-link" href="<?php echo esc_url(wp_go_site_texts_url(false)); ?>"><?php echo esc_html(_x('Close', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></a>
        </h2>

        <?php if ($view['not_listed']) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wp_go_site_texts_add_found">
                <?php wp_nonce_field('wp_go_site_texts_add_found'); ?>
                <p>
                    <?php echo esc_html(wp_go_site_texts_count(count($view['not_listed']), _x('The code prints 1 key the list doesn\'t have, which shows as a key on the site:', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('The code prints %s keys the list doesn\'t have, which show as keys on the site:', 'src/SiteTexts/screen.php', 'wp-go-languages'))); ?>
                    <code><?php echo esc_html(implode(', ', array_keys($view['not_listed']))); ?></code>
                </p>
                <?php submit_button(_x('Add them', 'src/SiteTexts/screen.php', 'wp-go-languages'), 'secondary', '', false); ?>
            </form>
        <?php else : ?>
            <p><?php echo esc_html(_x('Every key the code prints is in the list.', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></p>
        <?php endif; ?>

        <?php if ($view['unused']) : ?>
            <p><?php echo esc_html(wp_go_site_texts_count(count($view['unused']), _x('1 key in the list isn\'t printed by the code: it can be removed, after a look, with "Only unused".', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('%s keys in the list aren\'t printed by the code: they can be removed, after a look, with "Only unused".', 'src/SiteTexts/screen.php', 'wp-go-languages'))); ?></p>
        <?php endif; ?>

        <?php if ($scan['dynamic']) : ?>
            <details class="wg-site-texts__warning">
                <summary><?php echo esc_html(wp_go_site_texts_count(count($scan['dynamic']), _x('1 call whose key isn\'t a plain string: its key can\'t be read, and may show as unused. Write the key in full.', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('%s calls whose key isn\'t a plain string: their keys can\'t be read, and may show as unused. Write each key in full.', 'src/SiteTexts/screen.php', 'wp-go-languages'))); ?></summary>
                <ul>
                    <?php foreach ($scan['dynamic'] as $where) : ?>
                        <li><code><?php echo esc_html($where); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>

        <?php if ($scan['unread_domains']) : ?>
            <details class="wg-site-texts__warning">
                <summary><?php echo esc_html(wp_go_site_texts_count(count($scan['unread_domains']), _x('1 call whose domain isn\'t a plain lowercase string (\'shop\'): it can\'t be read, and the key stays in General or the domain an administrator gave it. Write the domain in full, in lowercase.', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('%s calls whose domain isn\'t a plain lowercase string (\'shop\'): it can\'t be read, and their keys stay in General or the domain an administrator gave them. Write each domain in full, in lowercase.', 'src/SiteTexts/screen.php', 'wp-go-languages'))); ?></summary>
                <ul>
                    <?php foreach ($scan['unread_domains'] as $where) : ?>
                        <li><code><?php echo esc_html($where); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>

        <?php if ($scan['clashes']) : ?>
            <details class="wg-site-texts__warning">
                <summary><?php echo esc_html(wp_go_site_texts_count(count($scan['clashes']), _x('1 key is given more than one domain by the code: the first one found counts. Give it one domain.', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('%s keys are given more than one domain by the code: the first one found counts. Give each key one domain.', 'src/SiteTexts/screen.php', 'wp-go-languages'))); ?></summary>
                <ul>
                    <?php foreach ($scan['clashes'] as $key => $domains) : ?>
                        <li><code><?php echo esc_html($key); ?></code> · <?php echo esc_html(implode(', ', $domains)); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>

        <?php if ($scan['invalid']) : ?>
            <details class="wg-site-texts__warning">
                <summary><?php echo esc_html(wp_go_site_texts_count(count($scan['invalid']), _x('1 string printed as a key isn\'t a key (lowercase, dotted): it can\'t be listed.', 'src/SiteTexts/screen.php', 'wp-go-languages'), _x('%s strings printed as keys aren\'t keys (lowercase, dotted): they can\'t be listed.', 'src/SiteTexts/screen.php', 'wp-go-languages'))); ?></summary>
                <ul>
                    <?php foreach ($scan['invalid'] as $string => $places) : ?>
                        <li><code><?php echo esc_html($string); ?></code> · <?php echo esc_html(implode(', ', $places)); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * The search, and the "Only incomplete" and (while scanning) "Only unused"
 * filters.
 *
 * @param array<string,mixed> $view
 */
function wp_go_site_texts_render_filters(array $view): void
{
    ?>
    <form method="get" class="wg-site-texts__filters">
        <input type="hidden" name="page" value="<?php echo esc_attr(WP_GO_SITE_TEXTS_PAGE); ?>">

        <?php if ($view['scanning']) : ?>
            <input type="hidden" name="scan" value="1">
        <?php endif; ?>

        <input type="search" name="s" value="<?php echo esc_attr($view['search']); ?>" placeholder="<?php echo esc_attr(_x('Search a key or its texts', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>">
        <?php submit_button(_x('Search', 'src/SiteTexts/screen.php', 'wp-go-languages'), 'secondary', '', false); ?>

        <?php if (count($view['mine']) > 1) : ?>
            <label>
                <span class="screen-reader-text"><?php echo esc_html(_x('Domain', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></span>
                <select name="domain" onchange="this.form.submit()">
                    <option value=""><?php echo esc_html(_x('Every domain', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></option>
                    <?php foreach ($view['mine'] as $slug => $domain) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($view['domain'], $slug); ?>><?php echo esc_html($domain['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <label>
            <input type="checkbox" name="missing" value="1" <?php checked($view['missing_only']); ?> onchange="this.form.submit()">
            <?php echo esc_html(sprintf(_x('Only incomplete (%d)', 'src/SiteTexts/screen.php', 'wp-go-languages'), $view['missing_count'])); ?>
        </label>

        <?php if ($view['scanning']) : ?>
            <label>
                <input type="checkbox" name="unused" value="1" <?php checked($view['unused_only']); ?> onchange="this.form.submit()">
                <?php echo esc_html(sprintf(_x('Only unused (%d)', 'src/SiteTexts/screen.php', 'wp-go-languages'), count($view['unused']))); ?>
            </label>
        <?php endif; ?>
    </form>
    <?php
}

/**
 * The table, inside the save form: a group heading, then a row per key.
 *
 * @param array<string,mixed> $view
 */
function wp_go_site_texts_render_table(array $view): void
{
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wg-site-texts__form">
        <input type="hidden" name="action" value="wp_go_site_texts_save">
        <?php wp_nonce_field('wp_go_site_texts_save'); ?>

        <?php wp_go_site_texts_scroll_open(array_keys($view['languages']), $view['hidden']); ?>
        <table class="widefat striped wg-site-texts__table">
            <thead>
                <tr>
                    <?php if ($view['select']) : ?>
                        <td class="manage-column check-column">
                            <label class="label-covers-full-cell" for="wg-site-texts-select-all">
                                <span class="screen-reader-text"><?php echo esc_html(_x('Select all unused', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></span>
                            </label>
                            <input type="checkbox" id="wg-site-texts-select-all" class="wg-site-texts__select-all">
                        </td>
                    <?php endif; ?>

                    <th class="wg-site-texts__col-key" data-column="key"><?php echo esc_html(_x('Key', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></th>

                    <?php foreach ($view['languages'] as $language => $name) : ?>
                        <th class="wg-site-texts__col-lang <?php echo esc_attr(wp_go_site_texts_column_class((string) $language, $view['hidden'])); ?>" data-column="lang-<?php echo esc_attr((string) $language); ?>"><?php echo esc_html($name); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>

            <?php if (!$view['shown']) : ?>
                <tbody>
                    <tr>
                        <td colspan="<?php echo (int) $view['columns']; ?>" class="colspanchange">
                            <?php echo esc_html($view['texts']
                                ? _x('No key matches.', 'src/SiteTexts/screen.php', 'wp-go-languages')
                                : _x('No keys yet.', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>
                        </td>
                    </tr>
                </tbody>
            <?php endif; ?>

            <?php foreach ($view['groups'] as $group => $group_texts) : ?>
                <tbody>
                    <tr class="wg-site-texts__group">
                        <th colspan="<?php echo (int) $view['columns']; ?>" class="colspanchange"><span class="wg-site-texts__group-name"><?php echo esc_html(wp_go_site_texts_group((string) $group)); ?></span></th>
                    </tr>

                    <?php foreach ($group_texts as $key => $values) {
                        wp_go_site_texts_render_row((string) $key, $values, (string) $group, $view);
                    } ?>
                </tbody>
            <?php endforeach; ?>
        </table>
        </div>

        <?php if ($view['shown']) : ?>
            <div class="wg-site-texts__submit">
                <?php submit_button(_x('Save', 'src/SiteTexts/screen.php', 'wp-go-languages'), 'primary', 'submit', false); ?>
                <span class="wg-site-texts__changed" aria-live="polite"></span>

                <?php if ($view['select']) : ?>
                    <button type="submit" name="remove_selected" value="1" class="button button-link-delete wg-site-texts__remove-selected">
                        <?php echo esc_html(_x('Remove selected', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>
                    </button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </form>
    <?php
}

/**
 * A key's row: its checkbox (unused keys, while scanning), the key with its
 * badges and, on its right, its trash button (in the key's column, which
 * stays in place as the table scrolls), then a cell per language.
 *
 * The row carries its details as JSON, for the details dialog.
 *
 * @param array<string,string> $values
 * @param array<string,mixed>  $view
 */
function wp_go_site_texts_render_row(string $key, array $values, string $group, array $view): void
{
    $languages = $view['languages'];
    $is_unused = isset($view['unused'][ $key ]);
    $is_protected = !$is_unused && $view['scan'] !== null && !isset($view['scan']['keys'][ $key ]);

    // the placeholders the default language's text has (%s, %1$d): to keep
    $placeholders = preg_match_all('/%(?:\d+\$)?[sd]/', (string) ($values[ $view['default'] ] ?? ''), $found) ? array_unique($found[0]) : [];

    // a long text (or one on several lines) is edited in a textarea
    $long = (bool) array_filter($values, fn ($value) => mb_strlen($value) > 60 || str_contains($value, "\n"));

    $domain = $view['key_domains'][ $key ] ?? WP_GO_SITE_TEXTS_GENERAL;

    $details = [
        'key'    => $key,
        'group'  => wp_go_site_texts_group($group),
        'domain' => $view['domains'][ $domain ]['name'] ?? $domain,
        'places' => $view['used']['keys'][ $key ] ?? [],
        'texts'  => array_map(fn ($language) => (string) ($values[ $language ] ?? ''), array_combine(array_values($languages), array_keys($languages))),
    ];
    ?>
    <tr id="<?php echo esc_attr($key); ?>" data-details="<?php echo esc_attr(wp_json_encode($details)); ?>">
        <?php if ($view['select']) : ?>
            <th scope="row" class="check-column">
                <?php if ($is_unused) : ?>
                    <label class="label-covers-full-cell" for="wg-site-texts-select-<?php echo esc_attr($key); ?>">
                        <span class="screen-reader-text"><?php echo esc_html(sprintf(_x('Select %s for removal', 'src/SiteTexts/screen.php', 'wp-go-languages'), $key)); ?></span>
                    </label>
                    <input type="checkbox" id="wg-site-texts-select-<?php echo esc_attr($key); ?>" name="unused[]" value="<?php echo esc_attr($key); ?>" class="wg-site-texts__select">
                <?php endif; ?>
            </th>
        <?php endif; ?>

        <td class="wg-site-texts__key">
            <div class="wg-site-texts__key-inner">
            <div class="wg-site-texts__key-body">
            <code><?php echo esc_html($key); ?></code>

            <?php if ($is_unused) : ?>
                <span class="wg-site-texts__badge"><?php echo esc_html(_x('Not in the code', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></span>
            <?php elseif ($is_protected) : ?>
                <span class="wg-site-texts__badge is-protected" title="<?php echo esc_attr(_x('Under a protected prefix: another front end\'s, never marked unused.', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>"><?php echo esc_html(_x('Protected', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></span>
            <?php endif; ?>

            <?php if ($placeholders) : ?>
                <span class="description"><?php echo esc_html(sprintf(_x('Keep %s: it is replaced on the site.', 'src/SiteTexts/screen.php', 'wp-go-languages'), implode(' ', $placeholders))); ?></span>
            <?php endif; ?>

            <?php wp_go_site_texts_render_domain($key, $view); ?>
            </div>

            <?php if ($view['can_manage']) : ?>
                <span class="wg-site-texts__actions">
                    <button type="submit" name="remove" value="<?php echo esc_attr($key); ?>" class="button-link button-link-delete wg-site-texts__remove"
                        title="<?php echo esc_attr(_x('Remove', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>"
                        aria-label="<?php echo esc_attr(sprintf(_x('Remove %s', 'src/SiteTexts/screen.php', 'wp-go-languages'), $key)); ?>"
                        data-confirm="<?php echo esc_attr(wp_go_site_texts_remove_warning($key, $view['used'])); ?>">
                        <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                    </button>
                </span>
            <?php endif; ?>
            </div>
        </td>

        <?php foreach ($languages as $language => $name) {
            wp_go_site_texts_render_cell($key, (string) $language, $name, (string) ($values[ $language ] ?? ''), $long, $view['default'], $view['hidden']);
        } ?>
    </tr>
    <?php
}

/**
 * A text in one language: shown as text, a button that opens its field. The
 * field is disabled until opened, so only the texts opened are posted. Every
 * language but the default has a "Suggest a translation" button, shown only
 * where the browser can translate. Hidden, with its column, when the user
 * hid the language in Screen Options.
 *
 * @param string[] $hidden
 */
function wp_go_site_texts_render_cell(string $key, string $language, string $name, string $value, bool $long, string $default, array $hidden = []): void
{
    $field = sprintf('texts[%s][%s]', $key, $language);
    $label = sprintf(/* the key, the language */ _x('%1$s in %2$s', 'src/SiteTexts/screen.php', 'wp-go-languages'), $key, $name);
    $suggest = $language !== $default;
    ?>
    <td class="wg-site-texts__cell <?php echo esc_attr(wp_go_site_texts_column_class($language, $hidden)); ?>" data-lang="<?php echo esc_attr($language); ?>" data-value="<?php echo esc_attr($value); ?>">
        <button type="button" class="wg-site-texts__view" aria-label="<?php echo esc_attr(sprintf(_x('Change %s', 'src/SiteTexts/screen.php', 'wp-go-languages'), $label)); ?>">
            <?php if ($value === '') : ?>
                <span class="wg-site-texts__empty"><?php echo esc_html(_x('Empty: shows the key', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></span>
            <?php else : ?>
                <?php echo nl2br(esc_html($value)); ?>
            <?php endif; ?>
        </button>

        <div class="wg-site-texts__field<?php echo $suggest ? ' has-suggest' : ''; ?>" hidden>
            <?php if ($long) : ?>
                <textarea name="<?php echo esc_attr($field); ?>" rows="3" class="large-text" aria-label="<?php echo esc_attr($label); ?>" disabled><?php echo esc_textarea($value); ?></textarea>
            <?php else : ?>
                <input type="text" name="<?php echo esc_attr($field); ?>" value="<?php echo esc_attr($value); ?>" class="large-text" aria-label="<?php echo esc_attr($label); ?>" disabled>
            <?php endif; ?>

            <?php if ($suggest) : ?>
                <button type="button" class="button-link wg-site-texts__suggest" data-from="<?php echo esc_attr($default); ?>" data-to="<?php echo esc_attr($language); ?>"
                    title="<?php echo esc_attr(_x('Suggest a translation', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>"
                    aria-label="<?php echo esc_attr(_x('Suggest a translation', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?>" hidden>
                    <span class="dashicons dashicons-translation" aria-hidden="true"></span>
                </button>
            <?php endif; ?>
        </div>

        <span class="description wg-site-texts__note" aria-live="polite"></span>
    </td>
    <?php
}

/**
 * A key's domain, under the key: for an administrator, a choice of domain
 * (posted with the save form), unless the code gives the key its domain;
 * for anyone else, its name, when there is more than one domain.
 *
 * The choice goes with the domain it showed on load, `domains_was[key]`: the
 * save moves only the keys whose choice changed here, so a stale screen
 * doesn't move back a key another administrator moved meanwhile.
 *
 * @param array<string,mixed> $view
 */
function wp_go_site_texts_render_domain(string $key, array $view): void
{
    $slug = $view['key_domains'][ $key ] ?? WP_GO_SITE_TEXTS_GENERAL;
    $name = $view['domains'][ $slug ]['name'] ?? $slug;

    if (count($view['domains']) < 2) {
        return;
    }

    if (!$view['can_manage'] || wp_go_site_texts_domain_from_code($key)) {
        $title = wp_go_site_texts_domain_from_code($key) ? _x('Given by the code: changed there.', 'src/SiteTexts/screen.php', 'wp-go-languages') : '';
        ?>
        <span class="wg-site-texts__domain" title="<?php echo esc_attr($title); ?>"><?php echo esc_html($name); ?></span>
        <?php
        return;
    }
    ?>
    <label class="wg-site-texts__domain">
        <span class="screen-reader-text"><?php echo esc_html(sprintf(_x('Domain of %s', 'src/SiteTexts/screen.php', 'wp-go-languages'), $key)); ?></span>
        <select name="domains[<?php echo esc_attr($key); ?>]">
            <?php foreach ($view['domains'] as $option => $domain) : ?>
                <option value="<?php echo esc_attr($option); ?>" <?php selected($slug, $option); ?>><?php echo esc_html($domain['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="domains_was[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($slug); ?>">
    </label>
    <?php
}

/**
 * What removing a key would do, as far as the code's last scan knows: the
 * question the trash button asks.
 *
 * @param array{scanned:int, keys:array<string,string[]>} $used
 */
function wp_go_site_texts_remove_warning(string $key, array $used): string
{
    if (isset($used['keys'][ $key ])) {
        return _x('The code prints this key: removing it shows the key on the site until it\'s added again, and the next Scan code offers to add it back. Remove it and its texts in every language?', 'src/SiteTexts/screen.php', 'wp-go-languages');
    }

    if ($used['scanned'] > 0) {
        return _x('Remove this key and its texts in every language? The code didn\'t print it when last scanned; another front end might.', 'src/SiteTexts/screen.php', 'wp-go-languages');
    }

    return _x('Remove this key and its texts in every language? Code still printing it will show the key.', 'src/SiteTexts/screen.php', 'wp-go-languages');
}

/**
 * "Add a key": a dialog, opened by the title's button, and again on load
 * when the key written was refused (with it filled in).
 */
function wp_go_site_texts_render_add_dialog(): void
{
    $refused = in_array(wp_go_site_texts_param($_GET, 'added'), ['exists', 'invalid', 'nested'], true);
    $domains = wp_go_site_texts_domains();
    // the domain the list is filtered to, if any
    $chosen = sanitize_key(wp_go_site_texts_param($_GET, 'domain'));
    ?>
    <dialog class="wg-site-texts__modal wg-site-texts__add" aria-labelledby="wg-site-texts-add-title"<?php echo $refused ? ' data-open' : ''; ?>>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wp_go_site_texts_add">
            <?php wp_nonce_field('wp_go_site_texts_add'); ?>

            <h2 id="wg-site-texts-add-title"><?php echo esc_html(_x('Add a key', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></h2>

            <label class="wg-site-texts__label" for="wg-site-texts-key"><?php echo esc_html(_x('Key', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></label>
            <input type="text" id="wg-site-texts-key" name="key" required pattern="[a-z0-9_]+(\.[a-z0-9_]+)+" class="large-text code" value="<?php echo esc_attr(rawurldecode(wp_go_site_texts_param($_GET, 'key'))); ?>" placeholder="footer.contact_us" autocomplete="off">
            <p class="description"><?php echo esc_html(_x('The key the code prints, its first part the group it is listed under. For another front end\'s keys; those this site\'s code prints are added by Scan code.', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></p>

            <?php if (count($domains) > 1) : ?>
                <label class="wg-site-texts__label" for="wg-site-texts-domain"><?php echo esc_html(_x('Domain', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></label>
                <select id="wg-site-texts-domain" name="domain">
                    <?php foreach ($domains as $slug => $domain) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($chosen, $slug); ?>><?php echo esc_html($domain['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <div class="wg-site-texts__modal-actions">
                <button type="button" class="button wg-site-texts__modal-cancel"><?php echo esc_html(_x('Cancel', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></button>
                <?php submit_button(_x('Add key', 'src/SiteTexts/screen.php', 'wp-go-languages'), 'primary', '', false); ?>
            </div>
        </form>
    </dialog>
    <?php
}

/**
 * A key's details, filled in by the script when its key is clicked: its
 * group, where the code prints it, its texts and where it's cached.
 */
function wp_go_site_texts_render_details_dialog(): void
{
    ?>
    <dialog class="wg-site-texts__modal wg-site-texts__details" aria-labelledby="wg-site-texts-details-title">
        <div class="wg-site-texts__details-body">
            <h2 id="wg-site-texts-details-title"></h2>
            <dl class="wg-site-texts__details-list"></dl>
            <div class="wg-site-texts__modal-actions">
                <button type="button" class="button button-primary wg-site-texts__details-close"><?php echo esc_html(_x('Close', 'src/SiteTexts/screen.php', 'wp-go-languages')); ?></button>
            </div>
        </div>
    </dialog>
    <?php
}
