<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;

/**
 * The Menus screen (Appearance → Menus).
 *
 *   - Its menu select lists the language picked in the admin bar's menus, as the
 *     lists of pages do. All languages: every menu, each saying its language.
 *   - A menu's language is picked when it's made, and stays.
 *   - A menu in another language than the default says which of the default's it
 *     translates (**Translation of**), and then whether it copies it. The default's
 *     has nothing to choose: it lists its translations.
 *
 * The panel is printed in the footer, and menus.js moves it into the menu's form,
 * so core's own save posts it. A following menu's items are hidden, not taken off
 * the form: core deletes a menu's items that aren't posted, and turning it off
 * again brings them back. Only the default language's menus go in a location.
 */

const MENU_NONCE = 'wgl_menu_nonce';

function menus_boot(): void
{
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\menu_panel_assets');
    // not admin_footer-nav-menus.php: that comes after the footer's scripts
    add_action('admin_footer', __NAMESPACE__ . '\\menu_panel');
    add_action('wp_update_nav_menu', __NAMESPACE__ . '\\save_menu_panel');
    add_action('wp_create_nav_menu', __NAMESPACE__ . '\\new_menu');
    add_filter('wp_get_nav_menus', __NAMESPACE__ . '\\locations_tab_menus');
    add_filter('wp_get_nav_menus', __NAMESPACE__ . '\\edit_tab_menus');
    add_filter('get_user_option_nav_menu_recently_edited', __NAMESPACE__ . '\\recently_edited_menu');
    add_action('load-nav-menus.php', __NAMESPACE__ . '\\menu_of_another_language');
    add_action('admin_notices', __NAMESPACE__ . '\\locations_tab_notice');
}

function on_locations_tab(): bool
{
    return ($GLOBALS['pagenow'] ?? '') === 'nav-menus.php' && ($_GET['action'] ?? '') === 'locations';
}

/**
 * Manage Locations offers the default language's menus: the others come through
 * their links.
 *
 * @param mixed $menus
 * @return mixed
 */
function locations_tab_menus($menus)
{
    if (!is_array($menus) || !on_locations_tab()) {
        return $menus;
    }

    return array_values(array_filter($menus, function ($menu) {
        $code = $menu instanceof \WP_Term ? Core\language_of($menu->term_id, 'term') : null;

        return $code === null || $code === Core\default_language();
    }));
}

/**
 * The menu select keeps to the language picked in the admin bar, and any menu asked
 * for by its ID, a link to it being able to come from anywhere.
 *
 * @param mixed $menus
 * @return mixed
 */
function edit_tab_menus($menus)
{
    $code = Core\filter_language();

    if (!is_array($menus) || ($GLOBALS['pagenow'] ?? '') !== 'nav-menus.php' || on_locations_tab() || $code === null) {
        return $menus;
    }

    $asked = (int) ($_REQUEST['menu'] ?? 0);

    return array_values(array_filter($menus, function ($menu) use ($code, $asked) {
        if (!$menu instanceof \WP_Term) {
            return false;
        }

        $own = Core\language_of($menu->term_id, 'term');

        return $own === $code || $own === null || $menu->term_id === $asked;
    }));
}

/**
 * Core opens the menu last edited: not one of another language than the one picked,
 * which the select wouldn't list.
 *
 * @param mixed $menu_id
 * @return mixed
 */
function recently_edited_menu($menu_id)
{
    $code = Core\filter_language();
    $own  = (int) $menu_id > 0 ? Core\language_of((int) $menu_id, 'term') : null;

    return $code !== null && $own !== null && $own !== $code ? 0 : $menu_id;
}

/**
 * Another language picked in the admin bar, its link keeping the address: the menu
 * open goes, that language's opening instead.
 */
function menu_of_another_language(): void
{
    $code = sanitize_key((string) ($_GET['lang'] ?? ''));
    $menu = (int) ($_GET['menu'] ?? 0);

    if ($code !== '' && $menu > 0 && empty($_POST) && Core\is_language($code) && Core\language_of($menu, 'term') !== $code) {
        wp_safe_redirect(add_query_arg('lang', $code, admin_url('nav-menus.php')));
        exit;
    }
}

function locations_tab_notice(): void
{
    if (on_locations_tab()) {
        printf('<div class="notice notice-info"><p>%s</p></div>', esc_html(sprintf(
            /* translators: %s: the default language's name */
            _x('Choose the %s menu for each location. Other languages show the menu linked to it, in the Edit Menus tab.', 'src/Admin/menus.php', 'wp-go-languages'),
            (string) (Core\language(Core\default_language())['name'] ?? '')
        )));
    }
}

function menu_panel_assets(string $hook): void
{
    if ($hook === 'nav-menus.php' && !on_locations_tab()) {
        wp_enqueue_style('wgl-menus', Core\asset_url('Admin/menus.css'), [], Core\asset_version('Admin/menus.css'));
        wp_enqueue_script('wgl-menus', Core\asset_url('Admin/menus.js'), [], Core\asset_version('Admin/menus.js'), true);
    }
}

/**
 * The default language's menus a menu may translate: those with no translation in
 * its language yet, and the one it translates.
 *
 * @return list<\WP_Term>
 */
function menu_choices(int $menu_id): array
{
    $own = (string) Core\language_of($menu_id, 'term');

    return array_values(array_filter(Core\menus_in_language(Core\default_language()), function (\WP_Term $menu) use ($menu_id, $own) {
        $there = Core\translation($menu->term_id, $own, false, 'term');

        return !$there || $there === $menu_id;
    }));
}

/**
 * A link to a menu, in its language: the admin's lists keep to the one picked.
 */
function menu_edit_url(int $menu_id): string
{
    return add_query_arg(['action' => 'edit', 'menu' => $menu_id, 'lang' => (string) Core\language_of($menu_id, 'term')], admin_url('nav-menus.php'));
}

function menu_panel(): void
{
    if (($GLOBALS['pagenow'] ?? '') !== 'nav-menus.php' || on_locations_tab()) {
        return;
    }

    $menu_id  = (int) ($GLOBALS['nav_menu_selected_id'] ?? 0);
    $own      = $menu_id > 0 ? Core\language_of($menu_id, 'term') : null;
    $default  = Core\default_language();
    $base     = $own === null || $own === $default;
    $original = $menu_id > 0 ? Core\original($menu_id, 'term') : 0;
    $linked   = $menu_id > 0 && $original !== $menu_id;
    $names    = [];

    // All languages picked: the select lists every menu, so each says its own
    if (Core\filter_language() === null) {
        foreach (Core\all_menus() as $menu) {
            $code = Core\language_of($menu->term_id, 'term');

            if ($code !== null) {
                $names[$menu->term_id] = (string) (Core\language($code)['name'] ?? $code);
            }
        }
    }
    ?>
    <div id="wgl-menu-panel" class="wgl-menu-panel" hidden
        data-names="<?php echo esc_attr((string) wp_json_encode((object) $names)); ?>"
        data-default="<?php echo $base ? '1' : '0'; ?>">
        <?php wp_nonce_field('wgl_menu', MENU_NONCE); ?>
        <div class="wgl-menu-panel__row">
            <label for="wgl-menu-language"><?php echo esc_html_x('Language', 'src/Admin/menus.php', 'wp-go-languages'); ?></label>
            <?php if ($menu_id > 0) : ?>
                <strong><?php echo esc_html((string) (Core\language((string) $own)['name'] ?? '')); ?></strong>
            <?php else : ?>
                <select name="wgl_menu[language]" id="wgl-menu-language">
                    <?php foreach (Core\languages() as $code => $language) : ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected(Core\filter_language() ?? $default, $code); ?>><?php echo esc_html($language['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <?php if ($menu_id > 0 && $own !== null && !$base) : ?>
            <div class="wgl-menu-panel__row">
                <label for="wgl-menu-original"><?php echo esc_html_x('Translation of', 'src/Admin/menus.php', 'wp-go-languages'); ?></label>
                <select name="wgl_menu[original]" id="wgl-menu-original">
                    <option value="0"><?php echo esc_html_x('— None —', 'src/Admin/menus.php', 'wp-go-languages'); ?></option>
                    <?php foreach (menu_choices($menu_id) as $menu) : ?>
                        <option value="<?php echo (int) $menu->term_id; ?>" <?php selected($linked ? $original : 0, $menu->term_id); ?>><?php echo esc_html($menu->name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($linked) : ?>
                <div class="wgl-menu-panel__row wgl-menu-panel__follow">
                    <label>
                        <input type="checkbox" name="wgl_menu[follow]" value="1" id="wgl-menu-follow" <?php checked(Core\menu_follows($menu_id)); ?>>
                        <?php echo esc_html(sprintf(
                            /* translators: %s: the original menu's name */
                            _x('Copy “%s”: same structure, each link in this language', 'src/Admin/menus.php', 'wp-go-languages'),
                            (string) get_term($original)->name
                        )); ?>
                    </label>
                    <p class="description" id="wgl-menu-follow-note"><?php
                        printf(
                            /* translators: %s: the original menu's name, a link */
                            esc_html_x('Its structure is changed in %s. A page with no published translation here is left out; a custom link stays as it is there.', 'src/Admin/menus.php', 'wp-go-languages'),
                            '<a href="' . esc_url(menu_edit_url($original)) . '">' . esc_html((string) get_term($original)->name) . '</a>'
                        );
                    ?></p>
                </div>
            <?php endif; ?>
        <?php elseif ($menu_id > 0 && $own !== null) : ?>
            <?php $others = array_diff(Core\translations($menu_id, 'term'), [$menu_id]); ?>
            <?php if ($others) : ?>
                <div class="wgl-menu-panel__row">
                    <span class="wgl-menu-panel__label"><?php echo esc_html_x('Translations', 'src/Admin/menus.php', 'wp-go-languages'); ?></span>
                    <span><?php echo implode(', ', array_map(fn (string $code, int $other) => sprintf(
                        '%s: <a href="%s">%s</a>',
                        esc_html((string) (Core\language($code)['name'] ?? $code)),
                        esc_url(menu_edit_url($other)),
                        esc_html((string) get_term($other)->name)
                    ), array_keys($others), array_values($others))); ?></span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * What the panel posts for a menu made already: which menu it translates, and
 * whether it copies it. Its language was picked when it was made.
 */
function save_menu_panel(int $menu_id): void
{
    if (!isset($_POST[MENU_NONCE]) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST[MENU_NONCE])), 'wgl_menu') || !current_user_can('edit_theme_options')) {
        return;
    }

    $posted = (array) wp_unslash($_POST['wgl_menu'] ?? []);

    if (array_key_exists('original', $posted)) {
        save_menu_original($menu_id, (int) $posted['original']);
    }

    array_key_exists('follow', $posted) && Core\is_translation($menu_id, 'term')
        ? update_term_meta($menu_id, Core\MENU_FOLLOW_META, 1)
        : delete_term_meta($menu_id, Core\MENU_FOLLOW_META);
}

/**
 * **Translation of**: the menu it translates, 0 for none. Only one menu_choices()
 * offers, and only for a menu in another language than the default.
 */
function save_menu_original(int $menu_id, int $chosen): void
{
    $own     = Core\language_of($menu_id, 'term');
    $current = Core\original($menu_id, 'term');

    if ($own === null || $own === Core\default_language() || ($current !== $menu_id ? $current : 0) === $chosen) {
        return;
    }

    if ($chosen > 0 && !in_array($chosen, array_map(fn (\WP_Term $menu) => $menu->term_id, menu_choices($menu_id)), true)) {
        return;
    }

    Core\leave_group($menu_id, 'term');

    if ($chosen > 0) {
        Core\connect($menu_id, $chosen, 'term');
    }
}

/**
 * A new menu: the language its form says, else the one picked in the admin bar. It
 * then stays: nothing else writes a menu's language.
 */
function new_menu(int $menu_id): void
{
    $posted = isset($_POST[MENU_NONCE]) && wp_verify_nonce(sanitize_key(wp_unslash($_POST[MENU_NONCE])), 'wgl_menu')
        ? sanitize_key((string) (((array) wp_unslash($_POST['wgl_menu'] ?? []))['language'] ?? ''))
        : '';

    Core\set_language($menu_id, Core\is_language($posted) ? $posted : (Core\filter_language() ?? Core\default_language()), 'term');
}
