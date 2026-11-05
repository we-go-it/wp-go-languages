<?php

namespace WpGo\Languages\Core;

/**
 * AI translation: texts sent to an OpenAI-compatible endpoint (OpenAI, or a
 * model on this machine: llama.cpp's llama-server, Ollama, LM Studio), the
 * translations checked before anything is written.
 *
 * Set on the Languages screen, in `wp_go_languages_ai` (not the plugin's
 * main option: changing it empties no cache, and the key stays out of what
 * the theme reads):
 *
 *   endpoint      the API's base, `http://127.0.0.1:8080/v1`: requests go to
 *                 `{endpoint}/chat/completions`
 *   model         the model's name, as the endpoint knows it (`qwen`)
 *   key           sent as a Bearer token, if any (a local model needs none)
 *   instructions  added to the prompt: tone, words never translated
 *
 * A project sets them per environment with `wp_go_languages/ai` (a local
 * model's address is no use on the live site).
 *
 * The texts go as one JSON object, `{ key: text }`, and come back as one with
 * the same keys (ai_translate()). A translation is kept only when it's a
 * string, isn't empty unless its source is, keeps the source's HTML tags and
 * attributes (but alt, title, aria-label and placeholder, translated), and
 * its placeholders (`%s`, `%1$s`, `%%title%%`, `{name}`, a contact form's
 * mail tags: `[your-name]`, `[_site_title]`). What fails is asked
 * once more, alone with the others that failed, then reported.
 */

/** The option the endpoint, the model, the key and the instructions are kept in. */
const AI_OPTION = 'wp_go_languages_ai';

/**
 * The AI settings as stored, the four of them trimmed, whatever else the
 * option holds: what the Languages screen shows and saves.
 *
 * @return array{endpoint: string, model: string, key: string, instructions: string}
 */
function ai_stored(): array
{
    $stored = get_option(AI_OPTION, []);
    $stored = array_replace(['endpoint' => '', 'model' => '', 'key' => '', 'instructions' => ''], is_array($stored) ? $stored : []);

    return array_map(fn ($value) => trim((string) $value), array_intersect_key($stored, array_flip(['endpoint', 'model', 'key', 'instructions'])));
}

/** Whether the code sets the AI settings (`wp_go_languages/ai`): they aren't the stored ones. */
function ai_set_in_code(): bool
{
    return ai_stored() !== ai_settings();
}

/**
 * The AI settings, as stored and as the code sets them.
 *
 * @return array{endpoint: string, model: string, key: string, instructions: string}
 */
function ai_settings(): array
{
    /**
     * The AI settings, from the code: an endpoint per environment.
     *
     * @param array{endpoint: string, model: string, key: string, instructions: string} $settings
     */
    $settings = (array) apply_filters('wp_go_languages/ai', ai_stored());
    $settings += ['endpoint' => '', 'model' => '', 'key' => '', 'instructions' => ''];

    return array_map(fn ($value) => trim((string) $value), array_intersect_key($settings, array_flip(['endpoint', 'model', 'key', 'instructions'])));
}

/** Whether AI translation is set up: an endpoint and a model. */
function ai_enabled(): bool
{
    $settings = ai_settings();

    return $settings['endpoint'] !== '' && $settings['model'] !== '';
}

/**
 * A language as the prompt names it: its name and locale (`English
 * (en_GB)`), from a site language's code; English for `en` when the site
 * has none (the admin strings' source); else the code itself.
 */
function ai_language_name(string $code): string
{
    $language = languages()[$code] ?? null;

    if ($language) {
        return sprintf('%s (%s)', $language['name'], $language['locale']);
    }

    return $code === 'en' ? 'English' : $code;
}

/**
 * Translate texts, `[key => text]`, from one language to another (site
 * language codes, see ai_language_name()). `$context` tells the model where
 * they're from ("the page “Chi siamo”").
 *
 * @param array<string,string> $texts
 * @param array{endpoint?: string, model?: string, key?: string, instructions?: string} $settings Instead of the stored ones (the screen's test).
 * @return array{translated: array<string,string>, failed: array<string,string>}|\WP_Error A WP_Error when the endpoint can't be reached or answers nothing usable.
 */
function ai_translate(array $texts, string $from, string $to, string $context = '', array $settings = []): array|\WP_Error
{
    $done = ['translated' => [], 'failed' => []];

    // nothing to ask for an empty text
    foreach ($texts as $key => $text) {
        if (trim((string) $text) === '') {
            $done['translated'][(string) $key] = (string) $text;
            unset($texts[$key]);
        }
    }

    for ($try = 1; $texts && $try <= 2; $try++) {
        $answer = ai_request(ai_messages($texts, $from, $to, $context, $settings), $settings);

        if (is_wp_error($answer)) {
            // on the second try, the first's translations are kept: only what's left failed
            if ($try === 1) {
                return $answer;
            }

            foreach (array_keys($texts) as $key) {
                $done['failed'][(string) $key] = $answer->get_error_message();
            }

            break;
        }

        $values = ai_json($answer);

        if ($values === null) {
            // a reply that isn't JSON at all: once more, then every text failed
            if ($try === 2) {
                foreach (array_keys($texts) as $key) {
                    $done['failed'][(string) $key] = 'The answer wasn\'t JSON.';
                }
            }

            continue;
        }

        $again = [];

        foreach ($texts as $key => $text) {
            $key = (string) $key;
            $problem = ai_check((string) $text, $values[$key] ?? null);

            if ($problem === null) {
                $done['translated'][$key] = (string) $values[$key];
                unset($done['failed'][$key]);
            } else {
                $done['failed'][$key] = $problem;
                $again[$key] = $text;
            }
        }

        $texts = $again;
    }

    return $done;
}

/**
 * The prompt: what to do and what to keep, the settings' instructions, the
 * context, then the texts as JSON.
 *
 * @param array<string,string> $texts
 * @param array<string,string> $settings
 * @return array<int,array{role: string, content: string}>
 */
function ai_messages(array $texts, string $from, string $to, string $context, array $settings = []): array
{
    $from = ai_language_name($from);
    $to = ai_language_name($to);
    $instructions = trim((string) ($settings['instructions'] ?? ai_settings()['instructions']));

    $system = implode("\n\n", array_filter([
        "You translate a website's texts from $from to $to.",
        'The user sends a JSON object. Answer with a JSON object only, with exactly the same keys, each value translated. No comments, no Markdown.',
        'Keep every HTML tag and attribute exactly as it is: translate only the text between tags, and the alt, title, aria-label and placeholder attributes. Keep placeholders such as %s, %1$s, %%title%%, {name} and [your-name] as they are, and keep line breaks.',
        "Don't translate URLs, e-mail addresses, code or brand names. A value already in $to, or with nothing to translate, comes back as it is.",
        $instructions !== '' ? "The site's own instructions:\n$instructions" : '',
        $context !== '' ? "The texts are from $context." : '',
    ]));

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => (string) wp_json_encode((object) $texts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
    ];
}

/**
 * Ask the endpoint: the model's reply, or why there is none.
 *
 * @param array<int,array{role: string, content: string}> $messages
 * @param array<string,string> $settings Instead of the stored ones.
 */
function ai_request(array $messages, array $settings = []): string|\WP_Error
{
    $settings = array_replace(ai_settings(), array_filter($settings, fn ($value) => (string) $value !== ''));

    if ($settings['endpoint'] === '' || $settings['model'] === '') {
        return new \WP_Error('wgl_ai_off', 'AI translation isn\'t set up: no endpoint or model.');
    }

    // a local model takes its time: a long page's chunk can take minutes
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }

    $response = wp_remote_post(untrailingslashit($settings['endpoint']) . '/chat/completions', [
        'timeout' => 300,
        'headers' => array_filter([
            'Content-Type'  => 'application/json',
            'Authorization' => $settings['key'] !== '' ? 'Bearer ' . $settings['key'] : null,
        ]),
        'body'    => (string) wp_json_encode([
            'model'           => $settings['model'],
            'temperature'     => 0.2,
            'response_format' => ['type' => 'json_object'],
            'messages'        => $messages,
        ]),
    ]);

    if (is_wp_error($response)) {
        return new \WP_Error('wgl_ai_unreachable', sprintf('The AI endpoint doesn\'t answer: %s', $response->get_error_message()));
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);

    if ($status !== 200) {
        $message = is_array($body) ? (string) ($body['error']['message'] ?? $body['error'] ?? '') : '';

        return new \WP_Error('wgl_ai_error', trim(sprintf('The AI endpoint answered %d. %s', $status, $message)));
    }

    $content = $body['choices'][0]['message']['content'] ?? null;

    if (!is_string($content) || trim($content) === '') {
        return new \WP_Error('wgl_ai_empty', 'The AI endpoint answered nothing.');
    }

    return $content;
}

/**
 * The JSON object in a reply, or null: a thinking model's `<think>…</think>`
 * and a Markdown fence around it are dropped.
 *
 * @return array<string,mixed>|null
 */
function ai_json(string $reply): ?array
{
    $reply = trim((string) preg_replace('#<think>.*?</think>#s', '', $reply));
    $start = strpos($reply, '{');
    $end = strrpos($reply, '}');

    if ($start === false || $end === false || $end < $start) {
        return null;
    }

    $values = json_decode(substr($reply, $start, $end - $start + 1), true);

    return is_array($values) ? $values : null;
}

/**
 * Why a translation can't be kept, or null when it can: not a string, empty
 * for a text that isn't, its HTML or its placeholders not the source's.
 */
function ai_check(string $source, mixed $translation): ?string
{
    if (!is_string($translation)) {
        return 'Missing from the answer.';
    }

    if (trim($translation) === '' && trim($source) !== '') {
        return 'Empty.';
    }

    if (ai_markup($source) !== ai_markup($translation)) {
        return 'Its HTML changed.';
    }

    if (ai_placeholders($source) !== ai_placeholders($translation)) {
        return 'Its placeholders changed.';
    }

    return null;
}

/**
 * A text's HTML without its text: every tag, its attributes as they are but
 * those translated (alt, title, aria-label, placeholder) emptied. How a tag
 * is written doesn't count: `<br>` is `<br />`, spaces between attributes
 * are one.
 *
 * @return string[]
 */
function ai_markup(string $html): array
{
    preg_match_all('#</?[a-zA-Z][^>]*>#', $html, $tags);

    return array_map(function ($tag) {
        $tag = (string) preg_replace('#\s(alt|title|aria-label|placeholder)=("[^"]*"|\'[^\']*\')#i', ' $1=""', $tag);
        $tag = (string) preg_replace('#\s*/?\s*>$#', '>', $tag);

        return (string) preg_replace('#\s+#', ' ', $tag);
    }, $tags[0]);
}

/**
 * A text's placeholders, sorted: `%s`, `%1$s`, `%d`, `%%title%%`, `{name}`,
 * and a contact form's mail tags (`[your-name]`, `[_site_title]`,
 * `[_raw_your-menu]`; a shortcode with nothing after its name, too).
 *
 * @return string[]
 */
function ai_placeholders(string $text): array
{
    preg_match_all('#%%[a-z0-9_]+%%|%(?:\d+\$)?[sd]|\{[a-z0-9_]+\}|\[_?[a-z][a-z0-9_:.-]*\]#i', $text, $found);

    $found = $found[0];
    sort($found);

    return $found;
}
