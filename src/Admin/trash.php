<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;

/**
 * Moving a page to the Trash with its translations, from the lists.
 *
 * A row's "Trash", and "Move to Trash" for the rows ticked, ask first
 * (list.js) when an item has translations: only it, or its translations
 * too. The second adds `wgl_all=1` to WordPress's own request (the link's,
 * or the bulk form's), and WordPress trashes as it always does: each post
 * it moves to the Trash then takes its translations along, here, on
 * `trashed_post`.
 *
 * WordPress's notice then counts them all, and its "Undo" brings them all
 * back: the address it goes back to gets the translations' IDs and count
 * (`trashed`, `ids`).
 *
 * A translation is left where it is when the user can't delete it, when
 * someone is editing it, when it's in the Trash already, or when it's one of
 * the rows ticked: WordPress trashes those itself, and stops with an error
 * on one already trashed.
 */

function trash_boot(): void
{
    add_action('trashed_post', __NAMESPACE__ . '\\trash_translations');
    add_filter('wp_redirect', __NAMESPACE__ . '\\trashed_redirect');
}

/**
 * The translations this request moved to the Trash along with what was
 * asked, by reference.
 *
 * @return int[]
 */
function &trashed_translations(): array
{
    static $trashed = [];

    return $trashed;
}

/**
 * Whether this request asks for translations too: WordPress's trash (a
 * row's link, on post.php, or the bulk action, on edit.php), with
 * `wgl_all=1`.
 */
function trashing_all(): bool
{
    if (($_REQUEST['wgl_all'] ?? '') !== '1' || !is_admin()) {
        return false;
    }

    $action = (string) ($_REQUEST['action'] ?? '');
    $action = $action === '-1' || $action === '' ? (string) ($_REQUEST['action2'] ?? '') : $action;

    return $action === 'trash';
}

/**
 * A post just moved to the Trash: its translations after it, when the
 * request asks (trashing_all()). Not for those it moves itself.
 */
function trash_translations(int $post_id): void
{
    static $running = false;

    if ($running || !trashing_all() || !Core\is_translated_post_type((string) get_post_type($post_id))) {
        return;
    }

    // the rows ticked: WordPress's own loop trashes them
    $asked = array_map('intval', (array) ($_REQUEST['post'] ?? []));
    $trashed = &trashed_translations();

    $running = true;

    try {
        foreach (Core\translations($post_id) as $translation) {
            $left = $translation === $post_id
                || in_array($translation, $asked, true)
                || in_array($translation, $trashed, true)
                || get_post_status($translation) === 'trash'
                || !current_user_can('delete_post', $translation)
                || wp_check_post_lock($translation);

            if ($left) {
                continue;
            }

            if (wp_trash_post($translation)) {
                $trashed[] = $translation;
            }
        }
    } finally {
        $running = false;
    }
}

/**
 * Back to the list after the trash: WordPress's count and "Undo" (`trashed`,
 * `ids`) with the translations moved too.
 */
function trashed_redirect(string $location): string
{
    $trashed = trashed_translations();

    if (!$trashed || !str_contains($location, 'trashed=')) {
        return $location;
    }

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $ids = array_filter(array_map('intval', explode(',', (string) ($query['ids'] ?? ''))));

    return add_query_arg([
        'trashed' => (int) ($query['trashed'] ?? 0) + count($trashed),
        'ids'     => implode(',', array_unique([...$ids, ...$trashed])),
    ], $location);
}
