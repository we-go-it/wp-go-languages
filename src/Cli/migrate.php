<?php

namespace WpGo\Languages\Cli;

use WpGo\Languages\Core;
use WpGo\Languages\Migrate;

/**
 * Move a site between WPML and WP-Go Languages, either way, and text-only
 * translations onto the fixed layout.
 *
 * Each way runs in the plugin it moves to: from-wpml with WPML deactivated,
 * to-wpml with WPML active (WP-Go Languages stands down then, and has only
 * this command). Neither removes what it reads, so a move can be run again,
 * or undone by moving back. The docs: WP-Go Languages → Migrating.
 */
final class MigrateCommand
{
    /**
     * Move a site from WPML onto WP-Go Languages.
     *
     * Run with WPML deactivated and WP-Go Languages active. WPML's tables
     * and settings, which stay in the database, are read; nothing of WPML's
     * is changed. The languages keep WPML's codes, the translations become
     * independent translations linked to their originals, the menus go to each language's
     * location, the admin strings keep their translations.
     *
     * Run it again after a change: what is already so is left alone.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Say what would be done, and change nothing.
     *
     * [--yes]
     * : Don't ask before writing.
     *
     * ## EXAMPLES
     *
     *     wp plugin deactivate sitepress-multilingual-cms wpml-string-translation acfml contact-form-7-multilingual wp-seo-multilingual
     *     wp plugin activate wp-go-languages
     *     wp languages migrate from-wpml --dry-run
     *     wp languages migrate from-wpml
     *
     * @subcommand from-wpml
     *
     * @param list<string>         $args
     * @param array<string,string> $assoc
     */
    public function fromWpml(array $args, array $assoc): void
    {
        if (defined('ICL_SITEPRESS_VERSION')) {
            \WP_CLI::error('WPML is active, and WP-Go Languages stands down while it is: deactivate WPML first (its data stays in the database).');
        }

        if (!Migrate\wpml_tables_exist()) {
            \WP_CLI::error('No WPML tables in this database: nothing to move.');
        }

        $plan = Migrate\plan_from_wpml();

        if (!$plan['languages']) {
            \WP_CLI::error('WPML has no active languages: nothing to move.');
        }

        $this->report($plan);
        $this->finish($plan, $assoc, 'Move the site onto WP-Go Languages?', fn () => Migrate\apply_from_wpml($plan, [\WP_CLI::class, 'log']));

        \WP_CLI::success('Moved from WPML. Check the site, then `wp languages check`; the WPML plugins can be removed once you\'re sure.');
    }

    /**
     * Move a site from WP-Go Languages onto WPML.
     *
     * Run with WPML and WPML String Translation active: WP-Go Languages
     * stands down, and its data, which stays in the database, is read and
     * written into WPML through WPML's own functions. Every language must be
     * one WPML knows by the same code. Fixed translations become plain
     * translations, their content as it is.
     *
     * Run it again after a change: what is already so is left alone.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Say what would be done, and change nothing.
     *
     * [--yes]
     * : Don't ask before writing.
     *
     * ## EXAMPLES
     *
     *     wp plugin activate sitepress-multilingual-cms wpml-string-translation acfml contact-form-7-multilingual wp-seo-multilingual
     *     wp languages migrate to-wpml --dry-run
     *     wp languages migrate to-wpml
     *     wp plugin deactivate wp-go-languages
     *
     * @subcommand to-wpml
     *
     * @param list<string>         $args
     * @param array<string,string> $assoc
     */
    public function toWpml(array $args, array $assoc): void
    {
        if (!defined('ICL_SITEPRESS_VERSION') || !isset($GLOBALS['sitepress']) || !function_exists('wpml_get_setup_instance')) {
            \WP_CLI::error('WPML isn\'t active: activate it (and String Translation) first. WP-Go Languages stands down meanwhile; its data stays in the database.');
        }

        $plan = Migrate\plan_to_wpml();

        if ($plan['missing']) {
            \WP_CLI::error(sprintf('WPML has no language with the code %s. Add it in WPML → Languages → Edit languages, with that code, then run this again.', implode(', ', $plan['missing'])));
        }

        $this->report($plan);
        $this->finish($plan, $assoc, 'Move the site onto WPML?', fn () => Migrate\apply_to_wpml($plan, [\WP_CLI::class, 'log']));

        \WP_CLI::success('Moved to WPML. Deactivate WP-Go Languages (`wp plugin deactivate wp-go-languages`), then check the site.');
    }

    /**
     * Turn text-only translations into the fixed layout, their non-text fields linked.
     *
     * A type without blocks, and a term, become independent the same way. A form
     * stays as it is. Run again, nothing's left to do.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Convert in a transaction, say what came of it, and roll it back.
     *
     * [--yes]
     * : Don't ask before writing.
     *
     * ## EXAMPLES
     *
     *     wp languages migrate text-to-fixed --dry-run
     *     wp languages migrate text-to-fixed
     *
     * @subcommand text-to-fixed
     *
     * @param list<string>         $args
     * @param array<string,string> $assoc
     */
    public function textToFixed(array $args, array $assoc): void
    {
        $dry = isset($assoc['dry-run']);

        if (!$dry) {
            \WP_CLI::confirm('Convert the text-only translations? Back up the database first.', $assoc);
        }

        $report = Core\convert_text_only(!$dry);

        foreach ($report['types'] as $type => $modes) {
            foreach ($modes as $mode => $count) {
                \WP_CLI::log(sprintf('%s: %d to %s', $type, $count, $mode));
            }
        }

        \WP_CLI::log(sprintf('terms to independent: %d', $report['terms']));
        \WP_CLI::log(sprintf('forms left as they are: %d', $report['forms']));
        \WP_CLI::log(sprintf('originals marked text only, set independent: %d', $report['originals']));
        \WP_CLI::log(sprintf('links added: %d in blocks, %d in page or term fields', $report['block_marks'], $report['field_marks']));
        \WP_CLI::log(sprintf('content grows by: %s', size_format(max(0, $report['bytes']))));
        \WP_CLI::log(sprintf('featured images linked, of those with one: %d; other meta keys that stop following (no field to link): %d', $report['thumbnails'], count($report['lost_meta'])));

        arsort($report['lost_meta']);

        foreach (array_slice($report['lost_meta'], 0, 25, true) as $key => $count) {
            \WP_CLI::log(sprintf('  %d x %s', $count, $key));
        }

        \WP_CLI::log(sprintf('values the next save would change: %d items', count($report['changes'])));

        foreach (array_slice($report['changes'], 0, 25, true) as $id => $changes) {
            \WP_CLI::log(sprintf('  %s: %s', $id, implode(', ', array_slice($changes, 0, 6))));
        }

        $dry ? \WP_CLI::success('Dry run: nothing was changed.') : \WP_CLI::success('Converted.');
    }

    /**
     * What a plan would do: the settings, then the counts, then its
     * warnings.
     *
     * @param array<string,mixed> $plan
     */
    private function report(array $plan): void
    {
        \WP_CLI\Utils\format_items('table', $plan['languages'], ['code', 'locale', 'name', 'slug']);

        $menus = $plan['menus']['groups'] ?? null;

        $lines = [
            'Default'      => $plan['languages'][0]['code'] . ($plan['default_in_url'] ? ', its URLs with its segment' : ', its URLs at the root'),
            'Post types'   => implode(', ', $plan['post_types']) ?: '—',
            'Taxonomies'   => implode(', ', $plan['taxonomies']) ?: '—',
            'Posts'        => $this->counts($plan['posts'], count($plan['unassigned']['post'])),
            'Terms'        => $this->counts($plan['terms'], count($plan['unassigned']['term'])),
            'Menus'        => $menus !== null
                ? sprintf('%d menus, %d of them linked as translations', array_sum(array_map(fn ($group) => count($group['items']), $menus)), array_sum(array_map(fn ($group) => count($group['items']) > 1 ? count($group['items']) : 0, $menus)))
                : sprintf('%d language locations', count(array_filter(array_keys($plan['menus']), fn ($location) => str_contains((string) $location, '___')))),
            'Admin strings' => sprintf('%d translations', array_sum(array_map(fn ($domains) => array_sum(array_map('count', $domains)), $plan['strings']))),
        ];

        foreach ($lines as $label => $value) {
            \WP_CLI::log(sprintf('%-14s %s', $label, $value));
        }

        foreach ($plan['warnings'] as $warning) {
            \WP_CLI::warning($warning);
        }
    }

    /**
     * @param list<array{type:string, original:int, items:array<string,int>}> $groups
     */
    private function counts(array $groups, int $unassigned): string
    {
        $counts = Migrate\count_groups($groups);

        return sprintf('%d items, %d translations in %d groups, %d with no language (given the default)', $counts['items'], $counts['translations'], $counts['groups'], $unassigned);
    }

    /**
     * Stop at a dry run; else ask (unless `--yes`), write, and say what went
     * wrong while writing.
     *
     * @param array<string,mixed>  $plan
     * @param array<string,string> $assoc
     * @param callable():list<string> $apply
     */
    private function finish(array $plan, array $assoc, string $question, callable $apply): void
    {
        if (!empty($assoc['dry-run'])) {
            \WP_CLI::success('Dry run: nothing changed.');
            \WP_CLI::halt(0);
        }

        \WP_CLI::confirm($question . ' Back up the database first.', $assoc);

        foreach ($apply() as $warning) {
            \WP_CLI::warning($warning);
        }
    }
}
