<?php

namespace WpGo\Languages\Admin;

use WpGo\Languages\Core;

/**
 * AI translation in the admin: the routes the screens call (Core\ai_*()
 * does the work).
 *
 *   GET  wgl/v1/{posts|terms}/<id>/ai-fields     a translation's fields, to tick
 *   POST wgl/v1/{posts|terms}/<id>/ai-translate  `keys`: those translated and written; with
 *                                                `write` false, translated only (the editor
 *                                                puts them in, for its own save)
 *   POST wgl/v1/ai/texts                         `texts`, `from`, `to`: translated, not written (Translation strings)
 *   POST wgl/v1/ai/test                          the Languages screen's "Test"
 *
 * The screens send a translation's fields a chunk at a time (a local model
 * takes seconds per text), each written as it comes back: a run stopped
 * halfway keeps what was done.
 */

function ai_boot(): void
{
    add_action('rest_api_init', __NAMESPACE__ . '\\ai_rest_routes');
}

/**
 * What a translation is translated from and into, or why it can't be: AI
 * not set up, or the item its group's original (nothing to translate from).
 *
 * @return array{from: string, to: string}|\WP_Error
 */
function ai_languages(int $id, string $kind): array|\WP_Error
{
    if (!Core\ai_enabled()) {
        return new \WP_Error('wgl_ai_off', 'AI translation isn\'t set up (the Languages screen).', ['status' => 400]);
    }

    $original = Core\original($id, $kind);
    $from = Core\language_of($original, $kind);
    $to = Core\language_of($id, $kind);

    if ($original === $id || $from === null || $to === null || $from === $to) {
        return new \WP_Error('wgl_ai_original', 'This is its translations\' original: there\'s nothing to translate it from.', ['status' => 400]);
    }

    return ['from' => $from, 'to' => $to];
}

/** What the prompt calls the item its texts are from: a term, a contact form, a page. */
function ai_context_kind(int $id, string $kind): string
{
    if ($kind === 'term') {
        return 'term';
    }

    return get_post_type($id) === Core\CF7_TYPE ? 'contact form' : 'page';
}

/**
 * The routes. A translation's are for who can edit it; the texts' for who
 * edits Translation strings; the test for administrators.
 */
function ai_rest_routes(): void
{
    foreach (['posts' => 'post', 'terms' => 'term'] as $path => $kind) {
        $can = fn (\WP_REST_Request $request) => current_user_can($kind === 'term' ? 'edit_term' : 'edit_post', (int) $request['id']);
        $fields = fn (int $id) => $kind === 'term' ? Core\ai_term_fields($id) : Core\ai_fields($id);

        register_rest_route('wgl/v1', "/$path/(?P<id>\\d+)/ai-fields", [
            'methods'             => 'GET',
            'permission_callback' => $can,
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
            'callback'            => function (\WP_REST_Request $request) use ($kind, $fields) {
                $languages = ai_languages((int) $request['id'], $kind);

                if (is_wp_error($languages)) {
                    return $languages;
                }

                $id = (int) $request['id'];

                return $languages + [
                    'from_name' => Core\languages()[$languages['from']]['name'] ?? $languages['from'],
                    'to_name'   => Core\languages()[$languages['to']]['name'] ?? $languages['to'],
                    'fields'    => $fields($id),
                    // a draft whose slug is still the original's takes its new title's
                    'slug_follows' => $kind === 'post' && get_post_status($id) !== 'publish' && get_post_field('post_name', $id) === get_post_field('post_name', Core\original($id)),
                ];
            },
        ]);

        register_rest_route('wgl/v1', "/$path/(?P<id>\\d+)/ai-translate", [
            'methods'             => 'POST',
            'permission_callback' => $can,
            'args'                => [
                'id'   => ['type' => 'integer', 'required' => true],
                'keys'  => ['type' => 'array', 'items' => ['type' => 'string'], 'required' => true],
                'write' => ['type' => 'boolean', 'default' => true],
            ],
            'callback'            => function (\WP_REST_Request $request) use ($kind, $fields) {
                $id = (int) $request['id'];
                $languages = ai_languages($id, $kind);

                if (is_wp_error($languages)) {
                    return $languages;
                }

                $sources = array_intersect_key(
                    array_column($fields($id), 'source', 'key'),
                    array_flip(array_map('strval', (array) $request['keys']))
                );

                $title = $kind === 'term' ? term_title(Core\original($id, 'term')) : page_title(Core\original($id));
                $done = Core\ai_translate($sources, $languages['from'], $languages['to'], sprintf('the %s “%s”', ai_context_kind($id, $kind), $title));

                if (is_wp_error($done)) {
                    $done->add_data(['status' => 502]);

                    return $done;
                }

                // translated only: the editor puts them in, and its save saves them
                if (!$request['write']) {
                    return ['written' => [], 'translated' => (object) $done['translated'], 'failed' => (object) $done['failed']];
                }

                $written = $kind === 'term' ? Core\ai_write_term($id, $done['translated']) : Core\ai_write($id, $done['translated']);

                return ['written' => $written, 'failed' => (object) $done['failed']];
            },
        ]);
    }

    register_rest_route('wgl/v1', '/ai/texts', [
        'methods'             => 'POST',
        'permission_callback' => fn () => current_user_can('manage_options') || (function_exists('wp_go_site_texts_capability') && current_user_can(wp_go_site_texts_capability())),
        'args'                => [
            'texts' => ['type' => 'object', 'required' => true],
            'from'  => ['type' => 'string', 'required' => true],
            'to'    => ['type' => 'string', 'required' => true],
        ],
        'callback'            => function (\WP_REST_Request $request) {
            if (!Core\ai_enabled()) {
                return new \WP_Error('wgl_ai_off', 'AI translation isn\'t set up (the Languages screen).', ['status' => 400]);
            }

            $texts = array_map('strval', array_filter((array) $request['texts'], 'is_string'));
            $done = Core\ai_translate($texts, (string) $request['from'], (string) $request['to'], 'the website\'s interface: buttons, labels, messages');

            if (is_wp_error($done)) {
                $done->add_data(['status' => 502]);

                return $done;
            }

            return ['translated' => (object) $done['translated'], 'failed' => (object) $done['failed']];
        },
    ]);

    register_rest_route('wgl/v1', '/ai/test', [
        'methods'             => 'POST',
        'permission_callback' => fn () => current_user_can('manage_options'),
        'args'                => [
            'endpoint'     => ['type' => 'string', 'default' => ''],
            'model'        => ['type' => 'string', 'default' => ''],
            'key'          => ['type' => 'string', 'default' => ''],
            'instructions' => ['type' => 'string', 'default' => ''],
        ],
        'callback'            => function (\WP_REST_Request $request) {
            // the screen's fields as they are now, the stored key if left empty
            $settings = array_map('strval', array_intersect_key($request->get_params(), array_flip(['endpoint', 'model', 'key', 'instructions'])));
            $from = Core\default_language();
            $to = (string) (array_key_first(array_diff_key(Core\languages(), [$from => true])) ?? 'en');
            $source = $from === 'it' ? 'Buongiorno! Questa è una prova di traduzione.' : 'Good morning! This is a translation test.';
            $start = microtime(true);

            $done = Core\ai_translate(['test' => $source], $from, $to, 'a test of the settings', $settings);

            if (is_wp_error($done)) {
                $done->add_data(['status' => 502]);

                return $done;
            }

            if (!isset($done['translated']['test'])) {
                return new \WP_Error('wgl_ai_test', 'The endpoint answered, but not with a usable translation: ' . ($done['failed']['test'] ?? ''), ['status' => 502]);
            }

            return [
                'source'      => $source,
                'translation' => $done['translated']['test'],
                'seconds'     => round(microtime(true) - $start, 1),
            ];
        },
    ]);
}
