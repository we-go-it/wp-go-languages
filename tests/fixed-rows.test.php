<?php

/**
 * Fixed translations, a repeater's rows (the Accordion's): the original's
 * rows reordered, moved and edited at once, added, removed; each keeps its
 * translated texts wherever it goes, a new row starts with the original's,
 * and the translation's own save keeps its rows by index.
 */

require_once __DIR__ . '/fixtures/content.php';

$find = function (array $list, string $name): int {
    foreach ($list as $i => $block) {
        if ($block['blockName'] === $name) {
            return $i;
        }
    }

    throw new RuntimeException("no $name block");
};

// the accordion's rows, as [title, text]
$rows = function (int $id) use ($find): array {
    $list = wgt_blocks($id);
    $data = $list[$find($list, 'acf/accordion')]['attrs']['data'];
    $out  = [];

    for ($i = 0; $i < (int) $data['items']; $i++) {
        $out[] = [$data["items_{$i}_title"] ?? null, $data["items_{$i}_text"] ?? null];
    }

    return $out;
};

// rewrite the accordion's rows from [title, text] pairs, as the editor saves them
$set_rows = function (int $id, array $rows) use ($find): void {
    $list = wgt_blocks($id);
    $k    = $find($list, 'acf/accordion');
    $data = $list[$k]['attrs']['data'];

    foreach (array_keys($data) as $key) {
        if (preg_match('/^_?items_\d+_/', $key)) {
            unset($data[$key]);
        }
    }

    $data['items'] = count($rows);

    foreach (array_values($rows) as $i => [$title, $text]) {
        $data["items_{$i}_title"]  = $title;
        $data["_items_{$i}_title"] = 'field_base_accordion_item_title';
        $data["items_{$i}_text"]   = $text;
        $data["_items_{$i}_text"]  = 'field_base_accordion_item_text';
    }

    $list[$k]['attrs']['data'] = $data;
    wgt_save($id, $list);
};

$it = wgt_page('Rows test', serialize_blocks([
    wgt_acf('hero', ['title' => ['text' => 'Domande frequenti', 'tag' => 'h1']]),
    wgt_acf('accordion', ['title' => ['text' => 'Domande'], 'items' => [['title' => 'X', 'text' => '<p>x</p>']]]),
]));
$set_rows($it, [['A', 'testo A'], ['B', 'testo B'], ['C', 'testo C'], ['D', 'testo D']]);
$en = WpGoLanguages::create_translation($it, 'en', 'blocks');

$set_rows($en, [['A en', 'text A en'], ['B en', 'text B en'], ['C en', 'text C en'], ['D en', 'text D en']]);
same(['A en', 'B en', 'C en', 'D en'], array_column($rows($en), 0), 'every row is translated');

section('The original\'s rows change');

$set_rows($it, [['D', 'testo D'], ['C', 'testo C'], ['B', 'testo B'], ['A', 'testo A']]);
same(['D en', 'C en', 'B en', 'A en'], array_column($rows($en), 0), 'a reorder: the translation\'s rows follow, their titles with them');
same(['text D en', 'text C en', 'text B en', 'text A en'], array_column($rows($en), 1), 'a reorder: their texts too');

// C to the top, with a new title: half its cells still the same
$set_rows($it, [['C nuovo', 'testo C'], ['D', 'testo D'], ['B', 'testo B'], ['A', 'testo A']]);
same(['C en', 'D en', 'B en', 'A en'], array_column($rows($en), 0), 'a row moved and edited at once keeps its translation');

$set_rows($it, [['C nuovo', 'testo C'], ['D', 'testo D'], ['E', 'testo E'], ['B', 'testo B'], ['A', 'testo A']]);
same(['C en', 'D en', 'E', 'B en', 'A en'], array_column($rows($en), 0), 'a new row starts with the original\'s text, the others keep theirs');

$set_rows($it, [['C nuovo', 'testo C'], ['E', 'testo E'], ['B', 'testo B'], ['A', 'testo A']]);
same(['C en', 'E', 'B en', 'A en'], array_column($rows($en), 0), 'a removed row is gone, the others keep theirs');

section('The translation saves');

$set_rows($en, [['C en', 'text C en'], ['E en', 'text E en'], ['B en', 'text B en'], ['A en', 'text A en']]);
same(['C en', 'E en', 'B en', 'A en'], array_column($rows($en), 0), 'the translation\'s own save keeps its rows by index');

$set_rows($it, [['B', 'testo B'], ['A', 'testo A'], ['C nuovo', 'testo C'], ['E', 'testo E']]);
same(['B en', 'A en', 'C en', 'E en'], array_column($rows($en), 0), 'a second reorder: still every row translated');
