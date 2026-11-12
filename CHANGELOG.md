# Changelog

What changed in each version of we-go/wp-go-languages, newest first. Versions follow semver and match the git tags (`vX.Y.Z`).

## 1.10.1 — 2026-11-12

- Licensed under MIT (`LICENSE`); it was proprietary.

## 1.10.0 — 2026-11-05

- A fixed layout's block has a box for its whole repeater: every row follows, texts included, and rows added to the original later too. Unticked, what it replaced comes back by its place.
- A group in a row has a box too; its mark moves with its row.
- "Link non-text fields" marks a repeater holding no text whole, instead of one mark per cell.
- A tick or an untick shows in a block's preview at once, for any field type; nothing is written.

## 1.9.0 — 2026-11-05

- Translation strings: both tabs show one language at a time, the one picked in the admin bar, with the original under each field.
- Every field can be typed in, and only what's changed is posted, so a long list doesn't pass `max_input_vars`.
- Screen Options' language columns, the sideways scrolling and the fixed key column go.
- The star on a group's original moves to its flag's bottom right corner.

## 1.8.0 — 2026-11-05

- A synced link no longer points at the wrong language once its marks go; its target is written in as the mark goes.
- Links typed in a text are rewritten only if they go to another page; joining groups forgets what the moved items followed.
- The posts page and a translated term's archive have hreflang and a switcher link; a hidden language answers 404 over REST too.
- Fast at 10,000 pages in 5 languages and at 50,000 posts: a new default language goes a batch of groups at a time, conversions run in an administrator's request or cron, language terms are read in one query.
- Removed: `rows_follow()`, `can_be_original()`, `term_can_be_original()`, `language_by_locale()`, `links_follow_current()`, `wp_go_admin_strings_translation()`, `upgrade_text_only()` and `upgrade_synced_links()` (`run_conversions()` now).

## 1.7.0 — 2026-11-05

- A synced field's target is worked out as a page renders, from what is published now; the admin sees what's stored.
- A cached page holding one is filed under `wgl-link-<target>--<language>`, and publishing purges those tags in its language instead of searching the site (about 0.45 s at 100,000 posts, from about 70 s).
- Stored values are converted once, by the upgrade to schema 4 or `wp languages migrate synced-links`; `resync_language()` goes.
- Conversions wait in a `wp` command and a failed one is tried again; `wp languages link --mode` says so when the mode is refused.
- Publishing a page reads again only what mentions it.

## 1.6.0 — 2026-11-05

- A translation is independent or has the fixed layout: the original's blocks, in its order, every field its own, any of them linked to follow the original.
- The text-only rules go, with `set_term_mode()`, the terms' mode route and the `wp_go_languages/supports_text_only` filter.
- What's stored as `text` is converted once on the first request after the update, as `wp languages migrate text-to-fixed` does.

## 1.5.0 — 2026-11-05

- One fixed layout: the text-only mode goes from the admin, with the lock in the lists, the panel and the admin bar.
- `wp languages migrate text-to-fixed [--dry-run]` turns text-only translations into the fixed layout.
- A group, or a clone shown as one, has a box whose mark takes precedence over its fields' marks.
- A linked field's old value is remembered and comes back when the mark goes.
- A linked featured image, from the editor's own panel.

## 1.4.0 — 2026-11-05

- A block's Advanced section links it to its original: a select of the original's blocks of the same type, and the ID by hand.
- An ID is refused when it can't be one, another block on the page has it, or it's another type's block.

## 1.3.0 — 2026-11-05

- Synced fields: a link icon with the flag of the original's language beside a translation's field, in every mode. Clicked, the field takes the original's value on every save of either side.
- A link whose target has no published translation yet goes to the original's page, and moves by itself when one is published.
- AI translation skips synced fields; translations' block forms aren't preloaded in any mode.

## 1.2.0 — 2026-11-04

- "Make this the original" is on every translation, posts and terms, in any language.
- The choice is kept in `_wgl_chosen` on the original (`choose_original()`) and survives a new default language and linking.
- Tests run on any site, and a skipped migration doesn't count as a pass.

## 1.1.0 — 2026-11-04

- Translation modes by field type: Independent, Fixed blocks, and Fixed blocks, text only; stored as `independent`, `blocks` and `text`. `upgrade.php` rewrites the stored old names once.
- "Start from the original's content", on by default, and the rule by field type with exceptions on `wp_go_languages/field_policy`.
- Safe syncs: a draft original keeps its changes from published translations; a new original hands over the same way on delete, unlink and default change.
- Linked menus: a translated menu says which menu it translates and can copy it; locations hold the default's menu and resolve through the links.
- A language can be hidden from visitors; `published_translation()` answers what visitors can reach.

## 1.0.0 — 2026-10-30

- First release as a package of its own, split out of wp-base; installs leave out the tests and the build tooling.
