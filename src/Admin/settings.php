<?php

namespace WpGo\Languages\Admin;

use function WpGo\Languages\Core\config;
use function WpGo\Languages\Core\default_language;
use function WpGo\Languages\Core\default_entry;
use function WpGo\Languages\Core\normalize_languages;

use const WpGo\Languages\Core\OPTION;

/**
 * the Languages screen: the languages, in order (the first the default), and
 * what is translated.
 */

const SETTINGS_PAGE = 'wgl-languages';

function add_settings_page(): void
{
    // A menu of its own in the admin's sidebar, under Settings (and WP-Go's),
    // with Translation strings (Site texts, 83) right under it.
    add_menu_page(
        _x('Languages', 'src/Admin/settings.php', 'wp-go-languages'),
        _x('Languages', 'src/Admin/settings.php', 'wp-go-languages'),
        'manage_options',
        SETTINGS_PAGE,
        __NAMESPACE__ . '\\settings_screen',
        'dashicons-admin-site-alt3',
        82
    );
}

/**
 * The post types a site could translate: the ones with a UI, CF7's forms
 * included (they have no public URL, but a form per language).
 *
 * @return array<string,string> name => label
 */
function translatable_post_types(): array
{
    $out = [];

    foreach (get_post_types(['show_ui' => true], 'objects') as $type) {
        if (in_array($type->name, ['attachment', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page'], true)) {
            continue;
        }

        $out[$type->name] = (string) $type->labels->name;
    }

    // CF7's forms have no UI of WordPress's (CF7 has its own screen), and
    // what is already translated stays offered: unoffered, a save would drop
    // it from the list.
    foreach (array_merge(['wpcf7_contact_form'], config()['post_types']) as $name) {
        $type = get_post_type_object($name);

        if ($type && !isset($out[$name])) {
            $out[$name] = (string) $type->labels->name;
        }
    }

    return $out;
}

/**
 * The taxonomies a site could translate: the ones with a UI.
 *
 * @return array<string,string> name => label
 */
function translatable_taxonomies(): array
{
    $out = [];

    foreach (get_taxonomies(['show_ui' => true], 'objects') as $taxonomy) {
        if (in_array($taxonomy->name, ['nav_menu', 'link_category', 'post_format', 'wp_pattern_category'], true)) {
            continue;
        }

        $out[$taxonomy->name] = (string) $taxonomy->labels->name;
    }

    return $out;
}

/**
 * The settings before `wp_go_languages/config`: the option with config()'s
 * defaults, caught as the filter starts, so the defaults stay config()'s.
 *
 * @return array<string,mixed>
 */
function unfiltered_config(): array
{
    if (!has_filter('wp_go_languages/config')) {
        return config();
    }

    $base    = [];
    $capture = function ($config) use (&$base) {
        $base = (array) $config;

        return $config;
    };

    add_filter('wp_go_languages/config', $capture, PHP_INT_MIN);
    config(true);
    remove_filter('wp_go_languages/config', $capture, PHP_INT_MIN);

    $base['languages']      = normalize_languages((array) ($base['languages'] ?? [])) ?: [default_entry()];
    $base['default_in_url'] = (bool) ($base['default_in_url'] ?? false);
    $base['post_types'] = array_values(array_map('strval', (array) ($base['post_types'] ?? [])));
    $base['taxonomies'] = array_values(array_map('strval', (array) ($base['taxonomies'] ?? [])));

    return $base;
}

/**
 * The settings the code changes with `wp_go_languages/config`: the screen
 * shows them greyed out, and a save leaves them as stored.
 *
 * @return list<string>
 */
function code_set_keys(): array
{
    if (!has_filter('wp_go_languages/config')) {
        return [];
    }

    $base   = unfiltered_config();
    $config = config();
    $keys   = [];

    foreach (['languages', 'default_in_url', 'post_types', 'taxonomies', 'site_texts'] as $key) {
        if (($base[$key] ?? null) !== ($config[$key] ?? null)) {
            $keys[] = $key;
        }
    }

    return $keys;
}

/**
 * The screen's script and styles: the "Add a language" picker, with every
 * locale and its flag.
 */
function settings_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_' . SETTINGS_PAGE) {
        return;
    }

    require_once __DIR__ . '/locales.php';

    $items = [];

    foreach (locales() as [$locale, $english, $native, $code]) {
        $flag = \WpGo\Languages\Core\flag_for($code, $locale);

        $items[] = [
            'locale'  => $locale,
            'english' => $english,
            'native'  => $native,
            'code'    => $code,
            'flag'    => $flag !== null ? plugins_url("flags/$flag.svg", WP_GO_LANGUAGES_FILE) : '',
        ];
    }

    wp_enqueue_style('wgl-settings', \WpGo\Languages\Core\asset_url('Admin/settings.css'), ['wgl-flags'], \WpGo\Languages\Core\asset_version('Admin/settings.css'));
    wp_enqueue_script('wgl-settings', \WpGo\Languages\Core\asset_url('Admin/settings.js'), [], \WpGo\Languages\Core\asset_version('Admin/settings.js'), true);
    wp_add_inline_script('wgl-settings', 'window.wglSettings = ' . wp_json_encode([
        'locales' => $items,
        'texts'   => [
            'none'  => _x('No language found.', 'src/Admin/settings.php', 'wp-go-languages'),
            'added' => _x('added', 'src/Admin/settings.php', 'wp-go-languages'),
            // AI translation's "Test the connection"
            'testing' => _x('Asking the model…', 'src/Admin/settings.php', 'wp-go-languages'),
            /* translators: 1: seconds, 2: the test sentence, 3: its translation */
            'tested'  => _x('It answered in %1$s s: «%2$s» → «%3$s»', 'src/Admin/settings.php', 'wp-go-languages'),
            'testFailed' => _x('It didn\'t work:', 'src/Admin/settings.php', 'wp-go-languages'),
        ],
        'rest'    => ['url' => esc_url_raw(rest_url('wgl/v1/ai/test')), 'nonce' => wp_create_nonce('wp_rest')],
    ]) . ';', 'before');
}

/**
 * A language's row in the table. `$i` is its key in the posted
 * `languages[]`, and the `default` radio's value; `__i__` in the template
 * the picker fills (settings.js).
 *
 * @param array{code:string,locale:string,name:string,slug:string} $language
 */
function language_row(int|string $i, array $language, bool $default = false): void
{
    $name = fn (string $field) => esc_attr("languages[$i][$field]");
    ?>
    <tr<?php echo $default ? ' class="is-default"' : ''; ?>>
        <td class="wgl-default-cell"><input type="radio" name="default" value="<?php echo esc_attr((string) $i); ?>" <?php checked($default); ?> aria-label="<?php echo esc_attr_x('Default language', 'src/Admin/settings.php', 'wp-go-languages'); ?>"></td>
        <td><input type="text" name="<?php echo $name('code'); ?>" value="<?php echo esc_attr($language['code']); ?>" class="regular-text" style="width: 6em" placeholder="en" required></td>
        <td>
            <div class="wgl-flag-cell">
                <?php echo ($language['code'] !== '' ? flag($language['code'], false) : '') ?: '<span class="wgl-flag wgl-flag--blank"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by flag() ?>
                <input type="text" name="<?php echo $name('locale'); ?>" value="<?php echo esc_attr($language['locale']); ?>" style="width: 8em" placeholder="en_GB" list="wgl-locales">
            </div>
        </td>
        <td><input type="text" name="<?php echo $name('name'); ?>" value="<?php echo esc_attr($language['name']); ?>" placeholder="English"></td>
        <td>
            <span class="wgl-slug"><span class="wgl-slug__slash">/</span><input type="text" name="<?php echo $name('slug'); ?>" value="<?php echo esc_attr($language['slug']); ?>" style="width: 6em" placeholder="en"><span class="wgl-slug__slash">/</span></span>
            <em class="wgl-slug__root"><?php echo esc_html_x('the root', 'src/Admin/settings.php', 'wp-go-languages'); ?></em>
        </td>
        <td class="wgl-hidden-cell"><input type="checkbox" name="<?php echo $name('hidden'); ?>" value="1" <?php checked(!empty($language['hidden'])); ?> aria-label="<?php echo esc_attr_x('Hidden from visitors', 'src/Admin/settings.php', 'wp-go-languages'); ?>"></td>
        <td>
            <button type="button" class="button-link wgl-remove" aria-label="<?php echo esc_attr_x('Remove this language', 'src/Admin/settings.php', 'wp-go-languages'); ?>" title="<?php echo esc_attr_x('Remove this language', 'src/Admin/settings.php', 'wp-go-languages'); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
        </td>
    </tr>
    <?php
}

function settings_screen(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $config    = config();
    $languages = $config['languages'];
    $code_set  = code_set_keys();

    $installed = array_merge(['en_US'], get_available_languages());
    ?>
    <div class="wrap">
        <h1><?php echo esc_html_x('Languages', 'src/Admin/settings.php', 'wp-go-languages'); ?></h1>

        <?php if (isset($_GET['saved'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html_x('Settings saved.', 'src/Admin/settings.php', 'wp-go-languages'); ?></p></div>
        <?php endif; ?>

        <?php if ($code_set) : ?>
            <div class="notice notice-info"><p><?php echo esc_html_x('The code sets the settings greyed out here (wp_go_languages/config): they show what it sets, and saving this screen leaves them as they are.', 'src/Admin/settings.php', 'wp-go-languages'); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wgl_save_settings">
            <?php wp_nonce_field('wgl_save_settings'); ?>

            <fieldset <?php disabled(in_array('languages', $code_set, true)); ?>>
            <h2><?php echo esc_html_x('Languages', 'src/Admin/settings.php', 'wp-go-languages'); ?></h2>
            <p class="description"><?php echo esc_html_x('Every language is under its slug in the URL (/en/), but the default, at the root unless set below. Add one from the list below; a row\'s ✕ removes it.', 'src/Admin/settings.php', 'wp-go-languages'); ?></p>

            <table class="widefat striped wgl-languages<?php echo $config['default_in_url'] ? '' : ' wgl-default-at-root'; ?>" style="max-width: 820px; margin-top: 12px;">
                <thead>
                    <tr>
                        <th class="wgl-default-cell"><?php echo esc_html_x('Default', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
                        <th><?php echo esc_html_x('Code', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
                        <th><?php echo esc_html_x('Locale', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
                        <th><?php echo esc_html_x('Name', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
                        <th><?php echo esc_html_x('URL slug', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
                        <th class="wgl-hidden-cell" title="<?php echo esc_attr_x('Not ready for visitors: its pages answer 404, and it isn\'t in the switcher, hreflang or the sitemap. Those who can edit pages still see it. The default is never hidden.', 'src/Admin/settings.php', 'wp-go-languages'); ?>"><?php echo esc_html_x('Hidden', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
                        <th><span class="screen-reader-text"><?php echo esc_html_x('Remove', 'src/Admin/settings.php', 'wp-go-languages'); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($languages as $i => $language) : ?>
                        <?php language_row($i, $language, $i === 0); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <label>
                    <input type="checkbox" name="default_in_url" value="1" id="wgl-default-in-url" <?php checked($config['default_in_url']); ?> <?php disabled(in_array('default_in_url', $code_set, true)); ?>>
                    <?php echo esc_html_x('The default language in the URL too (/it/…): the root and the old addresses redirect there', 'src/Admin/settings.php', 'wp-go-languages'); ?>
                </label>
            </p>

            <div class="notice notice-warning inline wgl-default-warning" hidden>
                <p><?php echo esc_html_x('Saving makes this the default language: in every group of translations, unless its original was chosen by hand, its page becomes the original, the old original an independent translation, and fixed translations with its blocks follow the new one. Its addresses and the old default\'s move, and its options and menus become the ones with no suffix.', 'src/Admin/settings.php', 'wp-go-languages'); ?></p>
            </div>

            <template id="wgl-language-row"><?php language_row('__i__', ['code' => '', 'locale' => '', 'name' => '', 'slug' => '']); ?></template>

            <div class="wgl-picker" id="wgl-add-language">
                <div class="wgl-picker__field">
                    <span class="dashicons dashicons-search" aria-hidden="true"></span>
                    <input type="search" role="combobox" aria-expanded="false" aria-controls="wgl-add-language-list" aria-autocomplete="list" autocomplete="off" placeholder="<?php echo esc_attr_x('Add a language…', 'src/Admin/settings.php', 'wp-go-languages'); ?>" aria-label="<?php echo esc_attr_x('Add a language', 'src/Admin/settings.php', 'wp-go-languages'); ?>">
                    <ul id="wgl-add-language-list" role="listbox" hidden></ul>
                </div>
                <p class="description wgl-picker__note" hidden><?php echo esc_html_x('The table changed: save to keep it.', 'src/Admin/settings.php', 'wp-go-languages'); ?></p>
            </div>

            <datalist id="wgl-locales">
                <?php foreach (array_unique($installed) as $locale) : ?>
                    <option value="<?php echo esc_attr($locale); ?>"></option>
                <?php endforeach; ?>
            </datalist>
            </fieldset>

            <h2><?php echo esc_html_x('Translated content', 'src/Admin/settings.php', 'wp-go-languages'); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html_x('Post types', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
                    <td><fieldset <?php disabled(in_array('post_types', $code_set, true)); ?>>
                        <?php foreach (translatable_post_types() as $name => $label) : ?>
                            <label style="display: block; margin-bottom: 4px;">
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($name); ?>" <?php checked(in_array($name, $config['post_types'], true)); ?>>
                                <?php echo esc_html($label); ?> <code><?php echo esc_html($name); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </fieldset></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html_x('Taxonomies', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
                    <td><fieldset <?php disabled(in_array('taxonomies', $code_set, true)); ?>>
                        <?php $taxonomies = translatable_taxonomies(); ?>
                        <?php if (!$taxonomies) : ?>
                            <em><?php echo esc_html_x('None registered.', 'src/Admin/settings.php', 'wp-go-languages'); ?></em>
                        <?php endif; ?>
                        <?php foreach ($taxonomies as $name => $label) : ?>
                            <label style="display: block; margin-bottom: 4px;">
                                <input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr($name); ?>" <?php checked(in_array($name, $config['taxonomies'], true)); ?>>
                                <?php echo esc_html($label); ?> <code><?php echo esc_html($name); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </fieldset></td>
                </tr>
            </table>

            <?php $site_texts = (array) ($config['site_texts'] ?? []); ?>
            <fieldset <?php disabled(in_array('site_texts', $code_set, true)); ?>>
            <h2><?php echo esc_html_x('Site strings', 'src/Admin/settings.php', 'wp-go-languages'); ?></h2>
            <?php site_texts_domains_table(); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="wgl-site-texts-protected"><?php echo esc_html_x('Protected prefixes', 'src/Admin/settings.php', 'wp-go-languages'); ?></label></th>
                    <td>
                        <input type="text" id="wgl-site-texts-protected" name="site_texts[protected]" value="<?php echo esc_attr((string) ($site_texts['protected'] ?? '')); ?>" class="regular-text" placeholder="nuxt">
                        <p class="description"><?php echo esc_html_x('Keys this site\'s code doesn\'t print but another front end does, comma-separated groups or prefixes: reading the code never marks them unused. The theme can add its own in code (wp_go_site_texts_protected).', 'src/Admin/settings.php', 'wp-go-languages'); ?></p>
                    </td>
                </tr>
            </table>
            </fieldset>

            <?php ai_settings_section(); ?>

            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

/**
 * Site texts' domains: a row per domain, its name and the roles that edit
 * it, and an empty row to add one. General is always there; a domain the
 * code gives keys but no one set up yet is listed, for its roles to be
 * chosen. Administrators edit every domain, so they aren't listed as a role.
 */
function site_texts_domains_table(): void
{
    if (!function_exists('wp_go_site_texts_domains')) {
        return;
    }

    $roles = wp_roles()->get_names();
    unset($roles['administrator']);

    $rows = wp_go_site_texts_domains();
    $rows[''] = ['name' => '', 'roles' => []];
    ?>
    <p class="description"><?php echo esc_html_x('Who writes which texts: each key is in a domain (the code gives it, as t(\'checkout.pay\', \'shop\'), or an administrator on the Translation strings screen; else General). Each role sees and edits only the texts of its domains. Administrators edit every domain, and add and remove keys. Leave a domain\'s slug empty to remove it.', 'src/Admin/settings.php', 'wp-go-languages'); ?></p>

    <table class="widefat striped" style="max-width: 760px; margin-top: 12px;">
        <thead>
            <tr>
                <th><?php echo esc_html_x('Domain', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
                <th><?php echo esc_html_x('Name', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
                <th><?php echo esc_html_x('Roles that edit it', 'src/Admin/settings.php', 'wp-go-languages'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 0; ?>
            <?php foreach ($rows as $slug => $domain) : ?>
                <tr>
                    <td>
                        <?php if ($slug === WP_GO_SITE_TEXTS_GENERAL) : ?>
                            <code><?php echo esc_html($slug); ?></code>
                            <input type="hidden" name="site_texts[domains][<?php echo (int) $i; ?>][slug]" value="<?php echo esc_attr($slug); ?>">
                        <?php else : ?>
                            <input type="text" name="site_texts[domains][<?php echo (int) $i; ?>][slug]" value="<?php echo esc_attr((string) $slug); ?>" style="width: 9em" placeholder="shop">
                        <?php endif; ?>
                    </td>
                    <td><input type="text" name="site_texts[domains][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr($slug === '' ? '' : $domain['name']); ?>" placeholder="<?php echo esc_attr_x('Shop', 'src/Admin/settings.php', 'wp-go-languages'); ?>"></td>
                    <td>
                        <?php foreach ($roles as $role => $label) : ?>
                            <label style="display: inline-block; margin: 0 12px 4px 0;">
                                <input type="checkbox" name="site_texts[domains][<?php echo (int) $i; ?>][roles][]" value="<?php echo esc_attr($role); ?>" <?php checked(in_array($role, $domain['roles'], true)); ?>>
                                <?php echo esc_html(translate_user_role($label)); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php $i++; ?>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * The domains posted: [slug => ['name' => …, 'roles' => […]]], General
 * always among them, roles only real ones (administrators aside).
 *
 * @param array<int,mixed> $posted
 * @return array<string,array{name:string, roles:string[]}>
 */
function posted_site_text_domains(array $posted): array
{
    $roles = array_keys(wp_roles()->get_names());
    $domains = [];

    foreach ($posted as $row) {
        $slug = sanitize_key((string) ($row['slug'] ?? ''));

        if ($slug === '' || isset($domains[ $slug ])) {
            continue;
        }

        $name = sanitize_text_field((string) ($row['name'] ?? ''));

        // General's own label is kept unsaved, so it shows in each admin's language
        if ($slug === WP_GO_SITE_TEXTS_GENERAL && $name === wp_go_site_texts_general_name()) {
            $name = '';
        }

        $domains[ $slug ] = [
            'name'  => $name,
            'roles' => array_values(array_diff(array_intersect(array_map('sanitize_key', (array) ($row['roles'] ?? [])), $roles), ['administrator'])),
        ];
    }

    $domains[ WP_GO_SITE_TEXTS_GENERAL ] ??= ['name' => '', 'roles' => []];

    return $domains;
}

/**
 * Save the Languages screen.
 */
function save_settings(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html_x('You cannot change these settings.', 'src/Admin/settings.php', 'wp-go-languages'), 403);
    }

    check_admin_referer('wgl_save_settings');

    $posted = wp_unslash($_POST);
    $stored = get_option(OPTION, []);
    $stored = is_array($stored) ? $stored : [];
    $base   = unfiltered_config();

    // The default first: the row its radio names.
    $rows    = (array) ($posted['languages'] ?? []);
    $default = (string) ($posted['default'] ?? '');

    if (isset($rows[$default])) {
        $rows = [$default => $rows[$default]] + $rows;
    }

    $languages = normalize_languages(array_values($rows));
    $previous  = default_language();

    // A slug may not be another language's, nor `wp-json`, `wp-admin`…
    $slugs = [];

    foreach ($languages as $i => $language) {
        if (in_array($language['slug'], \WpGo\Languages\Core\RESERVED_SLUGS, true) || in_array($language['slug'], $slugs, true)) {
            $languages[$i]['slug'] = $language['code'];
        }

        $slugs[] = $languages[$i]['slug'];
    }

    // A type or taxonomy not registered right now (its plugin off) isn't
    // offered, so it can't be posted: it stays translated.
    $post_types = array_values(array_unique(array_merge(
        array_intersect(array_map('sanitize_key', (array) ($posted['post_types'] ?? [])), array_keys(translatable_post_types())),
        array_filter($base['post_types'], fn ($type) => !post_type_exists($type))
    )));

    $taxonomies = array_values(array_unique(array_merge(
        array_intersect(array_map('sanitize_key', (array) ($posted['taxonomies'] ?? [])), array_keys(translatable_taxonomies())),
        array_filter($base['taxonomies'], fn ($taxonomy) => !taxonomy_exists($taxonomy))
    )));

    $site_texts = (array) ($posted['site_texts'] ?? []);

    $saved = [
        'post_types' => $post_types,
        'taxonomies' => $taxonomies,
        'site_texts' => array_replace((array) ($stored['site_texts'] ?? []), [
            'domains'   => posted_site_text_domains((array) ($site_texts['domains'] ?? [])),
            'protected' => sanitize_text_field((string) ($site_texts['protected'] ?? '')),
        ]),
    ];

    // every code emptied: the languages stay, a site has one at least
    if ($languages) {
        $saved['languages'] = $languages;
    }

    $saved['default_in_url'] = !empty($posted['default_in_url']);

    // What the code sets is shown but not posted: the stored value stays.
    foreach (code_set_keys() as $key) {
        unset($saved[$key]);
    }

    // a change but to Site texts' settings empties the cache, every page
    // showing the languages: WP-Go's Cache Tags watches the option
    update_option(OPTION, array_replace($stored, $saved));

    config(true);

    save_ai_settings((array) ($posted['ai'] ?? []));

    // A new default: its items become their groups' originals, but for
    // originals chosen by hand.
    if (default_language() !== $previous) {
        \WpGo\Languages\Core\sync_stored_default();
        \WpGo\Languages\Core\start_new_originals(default_language());

        /**
         * Another language was made the default; its items are their groups'
         * originals now, but for originals chosen by hand.
         *
         * @param string $code     The new default.
         * @param string $previous The one before.
         */
        do_action('wp_go_languages/default_changed', default_language(), $previous);
    }

    /**
     * the Languages screen was saved.
     */
    do_action('wp_go_languages/settings_saved');

    wp_safe_redirect(add_query_arg(['page' => SETTINGS_PAGE, 'saved' => 1], admin_url('admin.php')));
    exit;
}

/**
 * AI translation's section: the endpoint, the model, the key (never shown
 * again once saved: left empty, it stays), the instructions, and "Test the
 * connection" (settings.js, `wgl/v1/ai/test`). Greyed out when the code sets
 * them (`wp_go_languages/ai`).
 */
function ai_settings_section(): void
{
    $stored = \WpGo\Languages\Core\ai_stored();
    $ai = \WpGo\Languages\Core\ai_settings();
    $coded = \WpGo\Languages\Core\ai_set_in_code();
    ?>
    <fieldset class="wgl-ai" <?php disabled($coded); ?>>
    <h2><?php echo esc_html_x('AI translation', 'src/Admin/settings.php', 'wp-go-languages'); ?></h2>
    <p class="description">
        <?php echo esc_html_x('An endpoint that speaks OpenAI\'s API: OpenAI itself, or a model on your computer (llama.cpp\'s llama-server, Ollama, LM Studio). Once set, the "+" dialog, the editor\'s Language panel and Translation strings can translate with it.', 'src/Admin/settings.php', 'wp-go-languages'); ?>
        <?php if ($coded) : ?>
            <?php echo esc_html_x('The code sets these (wp_go_languages/ai).', 'src/Admin/settings.php', 'wp-go-languages'); ?>
        <?php endif; ?>
    </p>

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="wgl-ai-endpoint"><?php echo esc_html_x('Endpoint', 'src/Admin/settings.php', 'wp-go-languages'); ?></label></th>
            <td>
                <input type="url" id="wgl-ai-endpoint" name="ai[endpoint]" value="<?php echo esc_attr($ai['endpoint']); ?>" class="regular-text" placeholder="http://127.0.0.1:8080/v1">
                <p class="description"><?php echo esc_html_x('The API\'s base: requests go to …/chat/completions. Empty turns AI translation off.', 'src/Admin/settings.php', 'wp-go-languages'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="wgl-ai-model"><?php echo esc_html_x('Model', 'src/Admin/settings.php', 'wp-go-languages'); ?></label></th>
            <td><input type="text" id="wgl-ai-model" name="ai[model]" value="<?php echo esc_attr($ai['model']); ?>" class="regular-text" placeholder="qwen"></td>
        </tr>
        <tr>
            <th scope="row"><label for="wgl-ai-key"><?php echo esc_html_x('API key', 'src/Admin/settings.php', 'wp-go-languages'); ?></label></th>
            <td>
                <input type="password" id="wgl-ai-key" name="ai[key]" value="" class="regular-text" autocomplete="new-password"
                    placeholder="<?php echo esc_attr($ai['key'] !== '' ? _x('Saved: left empty, it stays', 'src/Admin/settings.php', 'wp-go-languages') : _x('None (a local model needs none)', 'src/Admin/settings.php', 'wp-go-languages')); ?>">
                <?php if ($stored['key'] !== '') : ?>
                    <label class="wgl-ai__remove"><input type="checkbox" name="ai[remove_key]" value="1"> <?php echo esc_html_x('Remove the saved key', 'src/Admin/settings.php', 'wp-go-languages'); ?></label>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="wgl-ai-instructions"><?php echo esc_html_x('Instructions', 'src/Admin/settings.php', 'wp-go-languages'); ?></label></th>
            <td>
                <textarea id="wgl-ai-instructions" name="ai[instructions]" rows="3" class="large-text" placeholder="<?php echo esc_attr_x('Use a friendly tone. Never translate “We-Go”.', 'src/Admin/settings.php', 'wp-go-languages'); ?>"><?php echo esc_textarea($ai['instructions']); ?></textarea>
                <p class="description"><?php echo esc_html_x('Added to every request: the tone, the words never translated (brand names, products).', 'src/Admin/settings.php', 'wp-go-languages'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <button type="button" class="button wgl-ai__test"><?php echo esc_html_x('Test the connection', 'src/Admin/settings.php', 'wp-go-languages'); ?></button>
                <span class="wgl-ai__result" aria-live="polite"></span>
            </td>
        </tr>
    </table>
    </fieldset>
    <?php
}

/**
 * Save AI translation's settings, in their own option: a key left empty
 * keeps the saved one, unless "Remove the saved key" is ticked. Not while
 * the code sets them.
 *
 * @param array<string,mixed> $posted
 */
function save_ai_settings(array $posted): void
{
    $stored = \WpGo\Languages\Core\ai_stored();

    // the code sets them: the fields were greyed out, and aren't posted
    if (!$posted || \WpGo\Languages\Core\ai_set_in_code()) {
        return;
    }

    $key = trim((string) ($posted['key'] ?? ''));

    update_option(\WpGo\Languages\Core\AI_OPTION, [
        'endpoint'     => esc_url_raw(trim((string) ($posted['endpoint'] ?? ''))),
        'model'        => sanitize_text_field((string) ($posted['model'] ?? '')),
        'key'          => !empty($posted['remove_key']) ? '' : ($key !== '' ? sanitize_text_field($key) : $stored['key']),
        'instructions' => sanitize_textarea_field((string) ($posted['instructions'] ?? '')),
    ], false);
}
