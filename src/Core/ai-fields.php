<?php

namespace WpGo\Languages\Core;

/**
 * A translation's texts, as AI translation sees them: each field it has, its
 * text in the original (what's translated) and its own (what's written), and
 * the writing back.
 *
 * A field is addressed by a key that says where it is:
 *
 *   post:title, post:excerpt   the post's own fields
 *   post:content               a content with no blocks (the classic editor)
 *   block:<wgId>               a core block's HTML, whole (a paragraph, a
 *                              heading, a list, an image's caption and alt)
 *   acf:<wgId>/<name>          an ACF block's field (`items_0_title` for a
 *                              repeater's cell); `#title` after it for a
 *                              link's title
 *   meta:<key>                 a page field (ACF's, Yoast's title and
 *                              description); `#title` for a link's title
 *   term:name, term:description
 *   cf7:<mail>/<part>          a contact form's mail (`mail`, `mail_2`): its
 *                              `subject`, its `body`, or its `mjml` while
 *                              wp-go's cf7-mjml writes the body
 *   cf7:messages/<key>         a contact form's message
 *
 * Only the texts a translation has of its own are offered: an ACF field set
 * "Per language" (`translate`) and of a type that's text (not a URL or an
 * e-mail address, which stay as they are), and a core block with text in it.
 * A block is matched with the original's by its ID (`metadata.wgId`): a
 * fixed translation's blocks are the original's, and a duplicate's start as
 * copies of them.
 *
 * Its source is the original's text for the same key when the original has
 * it, else the translation's own (a block the translation added). A field
 * whose text is still the source's (or empty) is `untranslated`, ticked to
 * start with in the dialog.
 */

/**
 * A block's address: its ID (`metadata.wgId`), or, for a block with none yet
 * (a page not saved since IDs were given, which happens on any save), its
 * place: `@` and its index at each level, `@2` the third block, `@2.0` the
 * first inside it. Counted as the editor counts them, without the
 * whitespace between blocks (blockName null, nothing in it): the editor's
 * script finds the same block by it (_apply.js).
 *
 * @param array<string,mixed> $block
 */
function ai_block_address(array $block, string $place): string
{
    $id = (string) ($block['attrs']['metadata'][BLOCK_ID] ?? '');

    return $id !== '' ? $id : '@' . $place;
}

/**
 * Blocks as the editor counts them: the whitespace between them left out.
 *
 * @param array<int,array<string,mixed>> $blocks
 * @return array<int,array<string,mixed>> Keyed by their original indexes.
 */
function ai_counted_blocks(array $blocks): array
{
    return array_filter($blocks, fn ($block) => ($block['blockName'] ?? null) !== null || trim((string) ($block['innerHTML'] ?? '')) !== '');
}

/**
 * Blocks by their address, inner blocks included: [address => block].
 *
 * @param array<int,array<string,mixed>> $blocks
 * @return array<string,array<string,mixed>>
 */
function ai_blocks_by_address(array $blocks, string $prefix = ''): array
{
    $found = [];

    foreach (array_values(ai_counted_blocks($blocks)) as $n => $block) {
        $place = $prefix === '' ? (string) $n : "$prefix.$n";
        $found[ai_block_address($block, $place)] = $block;
        $found += ai_blocks_by_address((array) ($block['innerBlocks'] ?? []), $place);
    }

    return $found;
}

/** Core blocks never sent: code, markup, embeds. */
const AI_SKIPPED_BLOCKS = ['core/html', 'core/code', 'core/shortcode', 'core/embed', 'core/preformatted', 'core/spacer', 'core/separator', 'core/more', 'core/nextpage'];

/** ACF field types whose text is translated (a link's title only). */
const AI_TEXT_TYPES = ['text', 'textarea', 'wysiwyg', 'link'];

/**
 * Yoast SEO's fields a translation translates (as its wpml-config.xml has
 * them), when their policy is `translate`: their labels.
 *
 * @return array<string,string>
 */
function ai_seo_keys(): array
{
    return [
        '_yoast_wpseo_title'                 => _x('SEO title', 'src/Core/ai-fields.php', 'wp-go-languages'),
        '_yoast_wpseo_metadesc'              => _x('Meta description', 'src/Core/ai-fields.php', 'wp-go-languages'),
        '_yoast_wpseo_bctitle'               => _x('Breadcrumbs title', 'src/Core/ai-fields.php', 'wp-go-languages'),
        '_yoast_wpseo_opengraph-title'       => _x('Social title', 'src/Core/ai-fields.php', 'wp-go-languages'),
        '_yoast_wpseo_opengraph-description' => _x('Social description', 'src/Core/ai-fields.php', 'wp-go-languages'),
        '_yoast_wpseo_twitter-title'         => _x('X title', 'src/Core/ai-fields.php', 'wp-go-languages'),
        '_yoast_wpseo_twitter-description'   => _x('X description', 'src/Core/ai-fields.php', 'wp-go-languages'),
    ];
}

/** The meta wp-go's cf7-mjml keeps a form's MJML in, and the theme's before it. */
const AI_CF7_MJML_META = '_wp_go_mail_mjml';
const AI_CF7_MJML_OLD_META = '_wg_mail_mjml';

/**
 * A translation's fields, in the order the editor shows them: the post's,
 * the blocks', the page fields', the SEO's, a contact form's mails and
 * messages.
 *
 * @return array<int,array{key: string, group: string, label: string, hint: string, source: string, value: string, html: bool, untranslated: bool}>
 */
function ai_fields(int $post_id): array
{
    $post = get_post($post_id);

    if (!$post instanceof \WP_Post) {
        return [];
    }

    $original = get_post(original($post_id)) ?: $post;
    $fields = [];

    // `field` and `nth`: an ACF field's key, and which of the fields with
    // that key it is in the page's form (a repeater's rows, in order): where
    // the editor finds it to put a translation in (editor-panel.js)
    $add = function (string $key, string $group, string $label, string $hint, string $source, string $value, bool $html = false, string $field = '', int $nth = 0) use (&$fields) {
        if (trim(wp_strip_all_tags($source)) === '' && !ai_has_attribute_text($source)) {
            return;
        }

        $fields[] = [
            'key'          => $key,
            'group'        => $group,
            'label'        => $label,
            'hint'         => $hint,
            'source'       => $source,
            'value'        => $value,
            'html'         => $html,
            'untranslated' => trim($value) === '' || trim($value) === trim($source),
            'field'        => $field,
            'nth'          => $nth,
        ];
    };

    $add('post:title', 'page', _x('Title', 'src/Core/ai-fields.php', 'wp-go-languages'), '', $original->post_title, $post->post_title);
    $add('post:excerpt', 'page', _x('Excerpt', 'src/Core/ai-fields.php', 'wp-go-languages'), '', $original->post_excerpt, $post->post_excerpt);

    $blocks = parse_blocks($post->post_content);

    if (!has_blocks($post->post_content)) {
        if ($post->post_type !== CF7_TYPE) {
            $add('post:content', 'page', _x('Content', 'src/Core/ai-fields.php', 'wp-go-languages'), '', $original->post_content, $post->post_content, true);
        }
    } else {
        $theirs = parse_blocks($original->post_content);
        $their_blocks = ai_blocks_by_address($theirs);
        $their_texts = ai_block_texts($theirs, false);
        $mine = ai_blocks_by_address($blocks);

        foreach (ai_block_texts($blocks) as $key => $text) {
            [$kind, $rest] = explode(':', $key, 2);
            $id = strtok($rest, '/');
            // the original's at the same address (by place, only the same kind of block there)
            $same = ($their_blocks[$id]['blockName'] ?? null) === ($mine[$id]['blockName'] ?? '');
            $source = $same && isset($their_texts[$key]) ? $their_texts[$key]['value'] : $text['value'];

            $add($key, 'blocks', $text['label'], $text['hint'], $source, $text['value'], $text['html'], $text['field'] ?? '');
        }
    }

    foreach (ai_meta_texts($post_id) as $key => $text) {
        $source = ai_meta_value($original->ID, $text['meta'], $text['part']);

        $add($key, $text['group'], $text['label'], $text['hint'], $source !== '' ? $source : $text['value'], $text['value'], $text['html'], $text['field'], $text['nth']);
    }

    if ($post->post_type === CF7_TYPE) {
        foreach (ai_cf7_texts($post_id, $original->ID) as $key => $text) {
            $add($key, $text['group'], $text['label'], $text['hint'], $text['source'], $text['value'], $text['html']);
        }
    }

    return $fields;
}

/** A contact form's meta that CF7 keeps as an array (`_mail`, `_messages`), or none. */
function ai_cf7_meta(int $post_id, string $meta): array
{
    $value = get_post_meta($post_id, $meta, true);

    return is_array($value) ? $value : [];
}

/**
 * A contact form's MJML, as wp-go's cf7-mjml keeps it: `[mail => [enabled,
 * source, stale]]`, from the theme's old key for a form not saved since.
 *
 * @return array<string,array<string,mixed>>
 */
function ai_cf7_mjml(int $post_id): array
{
    foreach ([AI_CF7_MJML_META, AI_CF7_MJML_OLD_META] as $meta) {
        if (metadata_exists('post', $post_id, $meta)) {
            return ai_cf7_meta($post_id, $meta);
        }
    }

    return [];
}

/**
 * A contact form's own texts: each mail's subject and body — its MJML
 * instead, while MJML writes the body (the body is what it compiles to) —
 * Mail (2)'s only while it's on, and the messages. Their source is the
 * original form's, the translation's own where the original has none.
 *
 * @return array<string,array{group: string, label: string, hint: string, source: string, value: string, html: bool}>
 */
function ai_cf7_texts(int $post_id, int $original_id): array
{
    $texts = [];
    $mjml = ai_cf7_mjml($post_id);
    $their_mjml = ai_cf7_mjml($original_id);
    $mails = [
        'mail'   => _x('Mail', 'src/Core/ai-fields.php', 'wp-go-languages'),
        'mail_2' => _x('Mail (2)', 'src/Core/ai-fields.php', 'wp-go-languages'),
    ];

    foreach ($mails as $which => $title) {
        $mail = ai_cf7_meta($post_id, "_$which");
        $theirs = ai_cf7_meta($original_id, "_$which");

        if (!$mail || ($which === 'mail_2' && empty($mail['active']))) {
            continue;
        }

        $parts = ['subject' => [_x('Subject', 'src/Core/ai-fields.php', 'wp-go-languages'), (string) ($mail['subject'] ?? ''), (string) ($theirs['subject'] ?? ''), false]];

        if (!empty($mjml[$which]['enabled'])) {
            $own = (string) ($mjml[$which]['source'] ?? '');
            $their_source = !empty($their_mjml[$which]['enabled']) ? (string) ($their_mjml[$which]['source'] ?? '') : '';
            $parts['mjml'] = ['MJML', $own, $their_source, true];
        } else {
            $parts['body'] = [_x('Body', 'src/Core/ai-fields.php', 'wp-go-languages'), (string) ($mail['body'] ?? ''), (string) ($theirs['body'] ?? ''), !empty($mail['use_html'])];
        }

        foreach ($parts as $part => [$label, $value, $source, $html]) {
            $texts["cf7:$which/$part"] = [
                'group'  => 'mail',
                'label'  => "$title › $label",
                'hint'   => '',
                'source' => $source !== '' ? $source : $value,
                'value'  => $value,
                'html'   => $html,
            ];
        }
    }

    $messages = ai_cf7_meta($post_id, '_messages');
    $theirs = ai_cf7_meta($original_id, '_messages');
    // CF7's own descriptions ("Sender's message was sent successfully"), and
    // those added with its filter
    $described = function_exists('wpcf7_messages') ? (array) wpcf7_messages() : [];

    foreach ($messages + $theirs as $key => $value) {
        $key = (string) $key;
        $value = is_string($messages[$key] ?? null) ? $messages[$key] : '';
        $source = is_string($theirs[$key] ?? null) && $theirs[$key] !== '' ? $theirs[$key] : $value;
        $label = (string) ($described[$key]['description'] ?? '');

        $texts["cf7:messages/$key"] = [
            'group'  => 'messages',
            'label'  => $label !== '' ? $label : ucfirst(str_replace('_', ' ', (string) preg_replace('#^wg_#', '', $key))),
            'hint'   => $key,
            'source' => $source,
            'value'  => $value,
            'html'   => false,
        ];
    }

    return $texts;
}

/** Whether HTML has a text in an attribute that's translated (an image's alt). */
function ai_has_attribute_text(string $html): bool
{
    return (bool) preg_match('#\s(alt|title|aria-label|placeholder)=("[^"]+"|\'[^\']+\')#i', $html);
}

/**
 * The texts of blocks, inner blocks included: `[key => [label, hint, value,
 * html]]`. A core block with no inner blocks gives its HTML; an ACF block,
 * each field it translates. Blocks without an ID can't be written back, and
 * are left out.
 *
 * @param array<int,array<string,mixed>> $blocks
 * @param bool $labels Whether to work out the labels (not for the original's).
 * @return array<string,array{label: string, hint: string, value: string, html: bool}>
 */
function ai_block_texts(array $blocks, bool $labels = true, string $prefix = ''): array
{
    $texts = [];

    foreach (array_values(ai_counted_blocks($blocks)) as $n => $block) {
        $place = $prefix === '' ? (string) $n : "$prefix.$n";
        $id = ai_block_address($block, $place);
        $name = (string) ($block['blockName'] ?? '');
        $title = $labels ? ai_block_title($name) : '';

        if ($id !== '' && is_array($block['attrs']['data'] ?? null)) {
            foreach ($block['attrs']['data'] as $data_key => $value) {
                $data_key = (string) $data_key;

                if (str_starts_with($data_key, '_')) {
                    continue;
                }

                $field = block_field((string) ($block['attrs']['data']['_' . $data_key] ?? ''));

                if (!$field || !in_array($field['type'] ?? '', AI_TEXT_TYPES, true) || block_data_policy($block['attrs']['data'], $data_key) !== 'translate') {
                    continue;
                }

                $label = $labels ? $title . ' › ' . ($field['label'] ?: $field['name']) : '';

                if (($field['type'] ?? '') === 'link') {
                    if (is_array($value) && is_string($value['title'] ?? null)) {
                        $texts["acf:$id/$data_key#title"] = ['label' => $label, 'hint' => $data_key, 'value' => $value['title'], 'html' => false, 'field' => (string) $field['key']];
                    }
                } elseif (is_string($value)) {
                    $texts["acf:$id/$data_key"] = ['label' => $label, 'hint' => $data_key, 'value' => $value, 'html' => ($field['type'] ?? '') === 'wysiwyg', 'field' => (string) $field['key']];
                }
            }
        } elseif ($id !== '' && $name !== '' && !str_starts_with($name, 'acf/') && empty($block['innerBlocks']) && !in_array($name, AI_SKIPPED_BLOCKS, true)) {
            $html = trim((string) ($block['innerHTML'] ?? ''));

            if ($html !== '') {
                $preview = $labels ? wp_html_excerpt(wp_strip_all_tags($html), 60, '…') : '';
                $texts["block:$id"] = ['label' => $title, 'hint' => $preview, 'value' => $html, 'html' => true];
            }
        }

        $texts += ai_block_texts((array) ($block['innerBlocks'] ?? []), $labels, $place);
    }

    return $texts;
}

/** A block type's name as the editor shows it ("Paragraph"), or its own name. */
function ai_block_title(string $name): string
{
    $type = \WP_Block_Type_Registry::get_instance()->get_registered($name);

    return $type && $type->title ? (string) $type->title : $name;
}

/**
 * The page fields a translation translates: its ACF fields set "Per
 * language" and of a text type, and Yoast's titles and descriptions. `[key
 * => [meta, part, group, label, hint, value, html]]`, `part` `title` for a
 * link's.
 *
 * @return array<string,array{meta: string, part: string, group: string, label: string, hint: string, value: string, html: bool, field: string, nth: int}>
 */
function ai_meta_texts(int $post_id): array
{
    $texts = [];
    $seo = ai_seo_keys();

    foreach (array_keys((array) get_post_meta($post_id)) as $meta) {
        $meta = (string) $meta;

        if (isset($seo[$meta])) {
            if (meta_policy($meta, $post_id) === 'translate') {
                $texts["meta:$meta"] = ['meta' => $meta, 'part' => '', 'group' => 'seo', 'label' => $seo[$meta], 'hint' => '', 'value' => ai_meta_value($post_id, $meta), 'html' => false, 'field' => '', 'nth' => 0];
            }

            continue;
        }

        // a form condition's value is one of a field's options, and follows
        // their translation (cf7.php): not a text of its own
        if (str_starts_with($meta, '_') || own_meta_key($meta) || preg_match('#^cf7_fields_\d+_conditions_\d+_value$#', $meta)) {
            continue;
        }

        $field = acf_field_of($meta, $post_id);

        if (!$field || !in_array($field['type'] ?? '', AI_TEXT_TYPES, true) || meta_policy($meta, $post_id, $field) !== 'translate') {
            continue;
        }

        $label = (string) ($field['label'] ?: $field['name']);
        $link = ($field['type'] ?? '') === 'link';

        $texts['meta:' . $meta . ($link ? '#title' : '')] = [
            'meta'  => $meta,
            'part'  => $link ? 'title' : '',
            'group' => 'fields',
            'label' => $label,
            'hint'  => $meta,
            'value' => ai_meta_value($post_id, $meta, $link ? 'title' : ''),
            'html'  => ($field['type'] ?? '') === 'wysiwyg',
            'field' => (string) $field['key'],
            'nth'   => 0,
        ];
    }

    // Each ACF field's place among those with its key, as ACF's form has them:
    // a repeater's rows in order (`items_2_title` after `items_10_title`'s
    // string order would put it, so by their numbers)
    $rows = fn (string $meta) => array_map('intval', preg_match_all('#_(\d+)_#', "_{$meta}_", $found) ? $found[1] : []);
    $by_field = [];

    foreach ($texts as $key => $text) {
        if ($text['field'] !== '') {
            $by_field[$text['field']][] = $key;
        }
    }

    foreach ($by_field as $keys) {
        usort($keys, fn ($a, $b) => $rows($texts[$a]['meta']) <=> $rows($texts[$b]['meta']));

        foreach ($keys as $nth => $key) {
            $texts[$key]['nth'] = $nth;
        }
    }

    return $texts;
}

/** A page field's text: the meta's value, or its `$part` (a link's title). */
function ai_meta_value(int $post_id, string $meta, string $part = ''): string
{
    $value = get_post_meta($post_id, $meta, true);

    if ($part !== '') {
        $value = is_array($value) ? ($value[$part] ?? '') : '';
    }

    return is_string($value) ? $value : '';
}

/**
 * Write translations into a translation's fields, `[key => text]`, as a
 * save would: the post's fields and its content in one update (kses aside,
 * as a translation's content is made; a fixed translation's merged onto its
 * original's, as on any save), the page fields as meta, a contact form's
 * mails and messages into CF7's meta (ai_write_cf7()). A draft's slug
 * follows its new title. Keys that aren't the post's fields are skipped.
 *
 * @param array<string,string> $values
 * @return string[] The keys written.
 */
function ai_write(int $post_id, array $values): array
{
    $post = get_post($post_id);

    if (!$post instanceof \WP_Post) {
        return [];
    }

    $known = array_column(ai_fields($post_id), null, 'key');
    $values = array_intersect_key($values, $known);
    $written = [];
    $update = ['ID' => $post_id];

    foreach (['title', 'excerpt', 'content'] as $part) {
        if (isset($values["post:$part"])) {
            $update["post_$part"] = $values["post:$part"];
            $written[] = "post:$part";
        }
    }

    // a draft's slug, still the original's, from its new title
    if (isset($values['post:title']) && $post->post_status !== 'publish' && $post->post_name === get_post_field('post_name', original($post_id))) {
        $update['post_name'] = sanitize_title($values['post:title']);
    }

    $in_blocks = array_filter($values, fn ($key) => str_starts_with($key, 'block:') || str_starts_with($key, 'acf:'), ARRAY_FILTER_USE_KEY);

    if ($in_blocks) {
        $blocks = ai_write_blocks(parse_blocks($post->post_content), $in_blocks, $written);
        $update['post_content'] = serialize_blocks($blocks);
    }

    if (count($update) > 1) {
        $kses = has_filter('content_save_pre', 'wp_filter_post_kses') !== false;

        if ($kses) {
            kses_remove_filters();
        }

        try {
            wp_update_post(wp_slash($update));
        } finally {
            if ($kses) {
                kses_init_filters();
            }
        }
    }

    foreach ($values as $key => $text) {
        if (!str_starts_with($key, 'meta:')) {
            continue;
        }

        [$meta, $part] = array_pad(explode('#', substr($key, strlen('meta:')), 2), 2, '');

        if ($part !== '') {
            $value = get_post_meta($post_id, $meta, true);
            $value = is_array($value) ? $value : [];
            $value[$part] = $text;
        } else {
            $value = $text;
        }

        update_post_meta($post_id, $meta, wp_slash($value));
        $written[] = $key;
    }

    if ($post->post_type === CF7_TYPE) {
        $written = array_merge($written, ai_write_cf7($post_id, array_filter($values, fn ($key) => str_starts_with($key, 'cf7:'), ARRAY_FILTER_USE_KEY)));

        // its conditions' values, after the options they're among (cf7.php)
        if ($written && function_exists(__NAMESPACE__ . '\\cf7_follow_conditions')) {
            cf7_follow_conditions($post_id);
        }
    }

    return $written;
}

/**
 * A contact form's mails and messages written: the subject and body in
 * `_mail` / `_mail_2`, a message in `_messages`. An MJML source goes to
 * cf7-mjml's meta, `stale`: nothing compiles MJML here, so the body keeps
 * its old HTML until the form's editor opens, compiles it, and its save
 * stores the new body (and drops `stale`).
 *
 * @param array<string,string> $values `cf7:` keys only.
 * @return string[] The keys written.
 */
function ai_write_cf7(int $post_id, array $values): array
{
    $written = [];
    $metas = [];
    $mjml = null;

    foreach ($values as $key => $text) {
        [$where, $part] = array_pad(explode('/', substr($key, strlen('cf7:')), 2), 2, '');
        $mail = in_array($where, ['mail', 'mail_2'], true);

        if ($where === 'messages' && $part !== '') {
            $metas['_messages'] ??= ai_cf7_meta($post_id, '_messages');
            $metas['_messages'][$part] = $text;
        } elseif ($mail && in_array($part, ['subject', 'body'], true)) {
            $metas["_$where"] ??= ai_cf7_meta($post_id, "_$where");
            $metas["_$where"][$part] = $text;
        } elseif ($mail && $part === 'mjml') {
            $mjml ??= ai_cf7_mjml($post_id);
            $mjml[$where] = array_merge((array) ($mjml[$where] ?? []), ['source' => $text, 'stale' => true]);
        } else {
            continue;
        }

        $written[] = $key;
    }

    foreach ($metas as $meta => $value) {
        update_post_meta($post_id, $meta, wp_slash($value));
    }

    if ($mjml !== null) {
        update_post_meta($post_id, AI_CF7_MJML_META, wp_slash($mjml));
    }

    return $written;
}

/**
 * Blocks with translations written in: a core block's HTML (`innerHTML` and
 * its one `innerContent`), an ACF block's field (a link's title). `$written`
 * gets the keys written.
 *
 * @param array<int,array<string,mixed>> $blocks
 * @param array<string,string>           $values
 * @param string[]                       $written
 * @return array<int,array<string,mixed>>
 */
function ai_write_blocks(array $blocks, array $values, array &$written, string $prefix = ''): array
{
    $n = 0;

    foreach ($blocks as $i => $block) {
        if (!ai_counted_blocks([$block])) {
            continue;
        }

        $place = $prefix === '' ? (string) $n : "$prefix.$n";
        $n++;
        $id = ai_block_address($block, $place);

        if ($id !== '' && isset($values["block:$id"]) && empty($block['innerBlocks'])) {
            $blocks[$i]['innerHTML'] = $values["block:$id"];
            $blocks[$i]['innerContent'] = [$values["block:$id"]];
            $written[] = "block:$id";
        }

        foreach ($values as $key => $text) {
            if (!str_starts_with($key, "acf:$id/") || $id === '' || !is_array($block['attrs']['data'] ?? null)) {
                continue;
            }

            [$data_key, $part] = array_pad(explode('#', substr($key, strlen("acf:$id/")), 2), 2, '');

            if (!array_key_exists($data_key, $block['attrs']['data'])) {
                continue;
            }

            if ($part !== '') {
                $link = is_array($blocks[$i]['attrs']['data'][$data_key]) ? $blocks[$i]['attrs']['data'][$data_key] : [];
                $link[$part] = $text;
                $blocks[$i]['attrs']['data'][$data_key] = $link;
            } else {
                $blocks[$i]['attrs']['data'][$data_key] = $text;
            }

            $written[] = $key;
        }

        if (!empty($block['innerBlocks'])) {
            $blocks[$i]['innerBlocks'] = ai_write_blocks($block['innerBlocks'], $values, $written, $place);
        }
    }

    return $blocks;
}

/**
 * A term translation's fields: its name and description, their source the
 * original term's.
 *
 * @return array<int,array{key: string, group: string, label: string, hint: string, source: string, value: string, html: bool, untranslated: bool}>
 */
function ai_term_fields(int $term_id): array
{
    $term = get_term($term_id);

    if (!$term instanceof \WP_Term) {
        return [];
    }

    $original = get_term(original($term_id, 'term')) ?: $term;
    $fields = [];

    foreach (['name' => _x('Name', 'src/Core/ai-fields.php', 'wp-go-languages'), 'description' => _x('Description', 'src/Core/ai-fields.php', 'wp-go-languages')] as $part => $label) {
        $source = (string) $original->$part;

        if (trim($source) === '') {
            continue;
        }

        $fields[] = [
            'key'          => "term:$part",
            'group'        => 'page',
            'label'        => $label,
            'hint'         => '',
            'source'       => $source,
            'value'        => (string) $term->$part,
            'html'         => $part === 'description',
            'untranslated' => trim((string) $term->$part) === '' || trim((string) $term->$part) === trim($source),
        ];
    }

    return $fields;
}

/**
 * Write a term translation's name and description.
 *
 * @param array<string,string> $values
 * @return string[] The keys written.
 */
function ai_write_term(int $term_id, array $values): array
{
    $term = get_term($term_id);
    $args = [];

    foreach (['name', 'description'] as $part) {
        if (isset($values["term:$part"])) {
            $args[$part] = $values["term:$part"];
        }
    }

    if (!$term instanceof \WP_Term || !$args || is_wp_error(wp_update_term($term_id, $term->taxonomy, $args))) {
        return [];
    }

    return array_map(fn ($part) => "term:$part", array_keys($args));
}
