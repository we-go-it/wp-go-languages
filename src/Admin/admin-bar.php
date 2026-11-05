<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;
use WpGo\Languages\Core\State;

/**
 * The admin bar's language menu:
 *
 *   - on the site: this page in the other languages;
 *   - editing a post: its translations (or the link that makes one);
 *   - anywhere else in the admin: the language the lists show, or all.
 */
function admin_bar(\WP_Admin_Bar $bar): void
{
    if (!is_user_logged_in() || count(Core\languages()) < 2) {
        return;
    }

    $languages = Core\languages();

    if (!is_admin()) {
        $bar->add_node([
            'id'    => 'wgl-language',
            'title' => bar_title(Core\current()),
            'meta'  => ['title' => _x('Language', 'src/Admin/admin-bar.php', 'wp-go-languages')],
        ]);

        foreach (Core\switcher() as $code => $item) {
            $bar->add_node([
                'parent' => 'wgl-language',
                'id'     => 'wgl-language-' . $code,
                'title'  => bar_label($code, esc_html(menu_name($code, $item['native_name']) . ($item['missing'] ? ' ' . _x('(not translated)', 'src/Admin/admin-bar.php', 'wp-go-languages') : ''))),
                'href'   => $item['url'],
                'meta'   => $item['active'] ? ['class' => 'wgl-current'] : [],
            ]);
        }

        return;
    }

    $post_id = Core\edited_post_id();

    if ($post_id) {
        $own          = Core\language_of($post_id);
        $translations = Core\translations($post_id);

        $bar->add_node([
            'id'    => 'wgl-language',
            'title' => $own !== null ? bar_title($own) . bar_lock($post_id) : '',
            'meta'  => ['title' => _x('This page\'s language', 'src/Admin/admin-bar.php', 'wp-go-languages')],
        ]);

        foreach ($languages as $code => $language) {
            if ($code === $own) {
                continue;
            }

            $id = $translations[$code] ?? null;

            $bar->add_node([
                'parent' => 'wgl-language',
                'id'     => 'wgl-language-' . $code,
                'title'  => bar_label($code, esc_html($id
                    ? sprintf(_x('%s: edit the translation', 'src/Admin/admin-bar.php', 'wp-go-languages'), menu_name($code, $language['name']))
                    : sprintf(_x('%s: translate (a copy)', 'src/Admin/admin-bar.php', 'wp-go-languages'), menu_name($code, $language['name'])))) . ($id ? bar_lock($id) : ''),
                'href'   => $id ? edit_url($id) : create_url($post_id, $code),
            ]);
        }

        return;
    }

    $chosen = State::$filter;

    $bar->add_node([
        'id'    => 'wgl-language',
        'title' => $chosen ? bar_title($chosen) : esc_html_x('All languages', 'src/Admin/admin-bar.php', 'wp-go-languages'),
        'meta'  => ['title' => _x('The language the lists show', 'src/Admin/admin-bar.php', 'wp-go-languages')],
    ]);

    $here = remove_query_arg(['lang', 'paged']);

    foreach ($languages as $code => $language) {
        $bar->add_node([
            'parent' => 'wgl-language',
            'id'     => 'wgl-language-' . $code,
            'title'  => bar_label($code, esc_html(menu_name($code, $language['name']))),
            'href'   => add_query_arg('lang', $code, $here),
            'meta'   => $chosen === $code ? ['class' => 'wgl-current'] : [],
        ]);
    }

    $bar->add_node([
        'parent' => 'wgl-language',
        'id'     => 'wgl-language-all',
        'title'  => esc_html_x('All languages', 'src/Admin/admin-bar.php', 'wp-go-languages'),
        'href'   => add_query_arg('lang', 'all', $here),
        'meta'   => $chosen === null ? ['class' => 'wgl-current'] : [],
    ]);
}

/**
 * A fixed translation's lock, after its language in a menu item's title.
 */
function bar_lock(int $post_id): string
{
    if (Core\mode_of($post_id) !== 'blocks') {
        return '';
    }

    return sprintf('<span class="wgl-bar__lock dashicons dashicons-lock" title="%1$s" aria-label="%1$s"></span>', esc_attr_x('Fixed layout', 'src/Admin/admin-bar.php', 'wp-go-languages'));
}

/**
 * A menu item's title: a language's flag beside a text (escaped), aligned by
 * flags.css.
 */
function bar_label(string $code, string $text): string
{
    return '<span class="wgl-bar">' . flag($code, false) . '<span>' . $text . '</span></span>';
}

/**
 * A language's name in the menu's list, "(default)" after the default's.
 */
function menu_name(string $code, string $name): string
{
    return $code === Core\default_language()
        ? sprintf(_x('%s (default)', 'src/Admin/admin-bar.php', 'wp-go-languages'), $name)
        : $name;
}

/**
 * The menu's own title: a language's flag and its name.
 */
function bar_title(string $code): string
{
    return bar_label($code, esc_html(Core\language($code)['name'] ?? strtoupper($code)));
}
