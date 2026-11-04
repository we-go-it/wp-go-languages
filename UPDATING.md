# Updating WP-Go Languages

What WP-Go Languages relies on that its dependencies don't promise to keep, and the versions it was
last checked against. After upgrading any of them, go through the list and run
`wp languages check`: most of these fail silently.

## Last checked against

<!-- #region versions -->
Checked on 2026-10-17.

| Dependency | Version |
|---|---|
| WordPress | 7.1.3 |
| ACF Pro | 6.8.10 |
| Yoast SEO | 28.7 |
| Contact Form 7 | 6.2.2, checked 2026-10-19 |
| PHP | 8.5.11 (requires 8.1) |
| Vite (the build) | 8.3.4 |
| WPML, String Translation (Migrating) | 5.1.0, checked 2026-10-18 |
<!-- #endregion versions -->

## What it relies on

By part of the plugin, as the docs are: each part's Updating page shows its own list.

### Languages and URLs

The current language, the addresses and the queries (`src/Core/request.php`, `links.php`, `query.php`).

<!-- #region languages -->
- **`WP::parse_request()` reads its home path from `home_url()`** (`class-wp.php`, `$home_path`).
  The `home_url` filter adds the current language's segment on site and REST requests, and that's
  what makes WordPress strip `/en` before matching its rewrite rules. If core starts reading
  `get_option('home')` there, every `/en/…` address is a 404.
- **`redirect_canonical()` reads the raw `REQUEST_URI`**, so it sees `/en/…` and agrees with the
  permalinks. If it starts building the requested URL from `home_url()`, it may redirect in loops.
- **`parse_query()` sets the queried object before `pre_get_posts`**: `resolve_pagename()` swaps
  `pagename` for the language's `page_id` and resets it. If the order changes, `/en/<slug>` can show
  the default language's page with the same slug.
- **`get_pages()` runs its `WP_Query` with filters** in WordPress 7.1: `get_pages_query_args`
  passes the `lang` asked for to the query (so `posts_where` keeps to it, or stands down for
  `all`) and adds it as a `tax_query`. If `get_pages()` suppresses filters again, the `tax_query`
  alone keeps the language.
- **`posts_where` runs only without `suppress_filters`**, which is what `lang => all` and code
  that wants every language rely on, as with WPML.
- **`wp.apiFetch` sends `_locale=user`** on the block editor's REST calls: that's how a REST
  request is known to be the admin's, in the admin's language, not the site's.
- **`parse_query()` sets the posts page's flags (`is_home`, `is_page`) from `get_page_by_path()`'s
  page**, which may be another language's when translations share a slug: `resolve_pagename()`
  sets them again from the language's page. If `parse_query()`'s posts-page logic changes, check
  `/en/<posts page>/`.
- **The default text domain is loaded before the theme's `functions.php`**: the config (and the
  locale) is read before then, so `drop_late_filters()` removes a `wp_go_languages/config` filter
  added later, with a notice. Add it from a mu-plugin.
- **`slug_reserved()` copies `wp_unique_post_slug()`'s other reasons for a suffix** (feed names,
  `embed`, numeric slugs, the hierarchical and flat checks): compare the two after each upgrade.
- **ACF fires `acf/init` on `init` at 5**, after `resolve_admin()` at 1: ACF's language in the admin
  is set when ACF is ready, not when the request's language is found.
<!-- #endregion languages -->

### Translations

Linking and creating translations, field rules, ACF options (`src/Core/translate.php`, `policies.php`, `integrations.php`).

<!-- #region translations -->
- **ACF keeps options per language** through `acf_update_setting('current_language')` and
  `default_language`: a language's options are `options_en_…`. If ACF drops the setting (ACFML
  sets the same one), the options are one set for every language.
- **A field's `type`, read off the field array**, decides its rule (`TEXT_TYPES`), and `__key` holds
  a clone's field's own key for `wp_go_languages/field_policy`. `acf/load_value` lets a non-default
  language's options read the default's copy fields.
- **`_yoast_wpseo_*` meta rules** come from Yoast's `wpml-config.xml` (`copy_once` included).
- **`wp_unique_post_slug` passes `$original_slug` as its sixth argument**: slugs are kept per
  language by going back to it when WordPress added a suffix only for another language's post.
- **ACF adds the language only for the post id `options`** (`acf_get_valid_post_id()`): options
  pages registered with another `post_id` are one set for every language.
- **ACF's field-group screen drops a setting it doesn't know** on a save: why no rule is kept on a
  field, and the texts that hold no words are named by key in code. It drops `wpml_cf_preferences`
  too without ACFML, which matters only to a site going back to WPML.
- **`wp_get_nav_menu_items` is the seam for a copying menu**: core passes its parsed query, `tax_query`
  naming the menu asked for, and a menu's items are read through it by `wp_nav_menu()`, Timber and wp-go-blocks.
  On the Menus screen, core deletes a menu's items that aren't posted, reads `nav_menu_recently_edited` to open
  one, and prints footer scripts before `admin_footer-nav-menus.php`.
- **ACF stores options as `options_<field>` and `options_<code>_<field>`**, each with a `_`-reference
  beside it: `swap_options()` rewrites those rows when the default changes.
- **The form picker `acf_cf7`** (a plugin of its own) lists forms with `WPCF7_ContactForm::find()`, a
  `WP_Query`, and selects the value among them: `cf7_picker_value()` and the list's `lang` keep the value.
  Its class hooks its input on `acf/render_field/type=acf_cf7` at 9, between our 8 and 10.
- **ACF's type loaders run on `acf/load_value` at 10**: the options' copy fields read the default
  language's stored value at 5, before them.
- **ACF's sub-field inputs are named `acf[field_…][row-N]…`** (`acfcloneindex` for a new row): the
  locks match a cell to its meta key (`rows_1_kind`) by them. They're read-only, not disabled: the
  repeater's `update_value` deletes a row that posts no inputs.
- **`acf_get_metadata_by_field()`** reads a copied option's stored value in the default language.
- **ACF hands a clone's fields to its hooks with `_clone`, the clone's key** (`acf_clone_field()`),
  and stores them under the clone's name with the cloned field's key as reference
  (`_logo_image` → the image field): a clone's switch reaches its fields by both. Without
  `_clone`, a copied clone's fields show and read by their own rule.
- **ACF's tabs**: a tab field's `class` goes on its button, which ACF puts in an `<li>` of
  `.acf-tab-wrap`; a tab group is `acf.getInstances(jQuery('.acf-tab-wrap'))`, with `tabs`,
  `hasActive()`, `getActive()`, `closeActive()` and `refresh()`, and skips an `<li>` with
  `acf-hidden` when it opens one. A hidden tab is marked so after ACF's `ready`
  (`copied_fields_styles()`); if that changes, a screen can open on a hidden tab, its fields
  hidden too.
- **WordPress fires `saved_term` after `edited_term` and `created_term`**, where ACF saves a
  term's fields: a fixed term translation's `copy` fields are synced there. If ACF moved its save
  later, the sync would copy the original's old values.
- **ACF stores a term's fields as term meta** (`get_term_meta()`), each beside its reference
  (`_icon` → `field_…`), as a post's: the rules read a term's keys through `get_metadata('term',
  …)`.
<!-- #endregion translations -->

### Fixed translations

The merge and the locked editor (`src/Core/fixed.php`, `src/Admin/fixed-editor.php`).

<!-- #region fixed-translations -->
- **`wp_insert_post_data`, `post_updated` and `wp_after_insert_post`** run in that order on a save,
  the last after meta and terms are written: the fixed translation's merge, the original's
  previous content and the copy fields' sync depend on it.
- **The block editor**: core's `metadata` attribute exists on every block type (the block IDs,
  `metadata.wgId`, live in it), and `block_editor_settings_all`'s `templateLock` is what
  `getTemplateLock()` returns for the root list (a fixed translation's lock).
- **The original's text under a field**: ACF draws a field's input on `acf/render_field`'s
  variations at 0 (the line comes after it, at 20); `acf/ajax/fetch-block` sends the block's
  attributes in `$_REQUEST['block']`, `metadata` among them, which is how a form knows its block; and
  the setting `preload_blocks` stops the forms rendered with the page's REST preload, where nothing
  says so. If it's renamed, a block's form has no `Original:` lines on first open.
- **A page field's rows are flat meta with a reference beside each** (`items_0_title`,
  `_items_0_title` = `field_…`), the same shape as a block's data: `sync_rows()` reads them with the
  block's row matching. If ACF changes how it stores rows, texts stay at their index.
- **A block's fields have a `prefix` starting `acf-block`** in `acf/prepare_field`, which is how
  a fixed translation's editor tells them from the post's own, and how copy fields' locks skip them.
- **The block-data shape**: each value beside a `_name` pointer to its field key, repeaters flat
  (`items` the row count, `items_0_title`), groups as `name_sub`. The merge reads every key's rule
  through its pointer (`acf_get_field()`), and moves repeater rows by rewriting the index. If ACF
  changes the shape, a fixed translation keeps the original's texts.
- **The editor's markup**: `.acf-field`, `.acf-actions`, `.acf-row-handle`, `.acf-fc-layout-handle`
  and `.acf-fc-layout-controls`, hidden or made inert in a fixed translation. If ACF renames them,
  rows can be added or moved there again (the merge undoes it on save).
- **ACF's block form requests send `post_id`**, which `edited_post_id()` reads to know a fixed
  translation's block form is being drawn: without it, its fields aren't hidden.
- **Repeaters and flexible content honour `min` and `max` set in `acf/prepare_field`**: that's how
  a fixed translation's rows are fixed at their count.
- **An `<InnerBlocks />` with no lock of its own inherits the root `templateLock`**: that's what
  locks the blocks inside a container too.
- **WordPress saves a revision on an update, not on an insert**: a fixed translation finds the
  original's version it was merged on (`metadata.wgRows`, a hash) among its revisions, to move rows
  that were reordered since. Without revisions, rows match by position.
- **The block editor keeps keys it doesn't know in `metadata`** (`wgRows`, `wgId`).
- **REST writes the featured image and template after `wp_update_post()`, before
  `wp_after_insert_post`**: a fixed translation takes its original's when the switch to fixed is
  saved.
<!-- #endregion fixed-translations -->

### SEO

hreflang, the sitemap and Yoast (`src/Core/seo.php`).

<!-- #region seo -->
- **The sitemap filters** `wpseo_sitemap_urlset` (the `xmlns:xhtml` namespace),
  `wpseo_sitemap_entry` and `wpseo_sitemap_url` (the alternates). An entry with no post, the home,
  is matched by its `loc`.
- **`YoastSEO()->classes->get(Indexable_Post_Watcher::class)->build_indexable()`** and
  **`WPSEO_Sitemaps_Cache::invalidate_post()`**, after a post's language or links change: Yoast
  stores a post's permalink when it's saved. If either is renamed, nothing fails, but the canonical
  and the sitemap keep the old address until the post is saved again.
- **Yoast's canonical, `og:url` and `og:locale`** come from the permalink and the locale, which the
  plugin sets: nothing of Yoast's own is changed.
- **Yoast's sitemap providers read the posts with their own SQL**, not through `WP_Query`, so the
  root sitemap lists every language's pages. If Yoast moves to `WP_Query`, the sitemap keeps to
  one language.
- **Yoast drops a URL outside `home_url()` as external**: `/en/sitemap_index.xml`, where the home is
  the English one, lists only English pages. The root sitemap is the one to submit.
- **Yoast's indexables are built only in production** (and with the site indexable): on a
  development copy there's no canonical to check.
<!-- #endregion seo -->

### Contact Form 7

A form per language (`src/Core/cf7.php`).

<!-- #region contact-form-7 -->
- **`shortcode_atts_wpcf7`** runs for CF7's own shortcode and for CF7 ACF Form, with the form's
  `id`. The form is swapped for the current language's there.
- **A form's `_hash` meta** (CF7 5.8+ finds a form by it): a translation gets its own from
  `wpcf7_generate_contact_form_hash()`, and the hash in a shortcode is looked up with SQL.
  `wpcf7_get_contact_form_by_hash()` isn't used: it runs a `WP_Query`, which keeps to the current
  language.
- **`_locale` meta**, checked with `wpcf7_is_valid_locale()`: CF7's messages and validation follow it.
- **`WPCF7_ContactForm::hash()` gives the first seven characters of `_hash`**: the shortcode is
  written with that, and the lookup matches it as a prefix (`LIKE 'abc1234%'`).
- **`wpcf7_contact_form()` loads any form by its numeric id**: a form with no published
  translation is handed to CF7 by id, since CF7's own lookup by hash is a `WP_Query`, which keeps to
  the current language.
- **CF7's forms page is `admin.php?page=wpcf7`, its screen ID `cf7_screen_id()`**:
  `toplevel_page_wpcf7` up to 6.1; from 6.2 a submenu of CF7's Dashboard (`wpcf7-dashboard`), so
  `<that menu's title, sanitised and translated>_page_wpcf7`, read from `$admin_page_hooks`. The
  list's column (`manage_{screen}_columns`), its scripts and the Language box all key on it: if CF7
  moves the page again, none of them shows.
- **A form's edit link is built by the plugin** (`admin.php?page=wpcf7&post=ID&action=edit`): CF7's
  type keeps an empty `_edit_link`, so `get_edit_post_link()` gives `''`.
- **`WPCF7_ContactForm::save()` writes the meta, then fires `wpcf7_after_save`**: a fixed
  translation follows its original's `_mail`, `_mail_2` and `_additional_settings` there. If CF7
  fired it before writing them, the original's old settings would be copied, and a translation's
  posted ones would win over the original's.
- **`_mail` and `_mail_2` are arrays with `subject` and `body`** beside the settings (`recipient`,
  `sender`, `additional_headers`, `attachments`, `use_html`, `exclude_blank`, `active`): a fixed
  translation keeps the first two and takes the rest. A new key CF7 adds is a setting, so the
  original's.
- **CF7's editor panels are `mail-panel` and `additional-settings-panel`** (`wpcf7_editor_panels`),
  **its mail fields' ids end in `-recipient`, `-sender`, `-additional-headers`, `-attachments`,
  `-exclude-blank`, `-use-html`, `-active`**, in a `.contact-form-editor-box-mail` box, Mail (2)'s
  being `#wpcf7-mail-2`: a fixed form's screen drops the one and hides the others
  (`fixed-editor.css`). Renamed, they show again, and saving changes nothing: the original's
  settings come back on save.
- **CF7 ACF Form's rows** are the flexible content `cf7_fields`, a group's rows its `fields`; each
  row has a `name`, options an `options` repeater of `value` and `label`, conditions a
  `conditions` repeater whose value's field key ends in `_conditions_value`. A fixed form's rows
  are matched by `name`, and its conditions follow its options by these keys (`cf7.php`).
- **ACF copies a tab field's `class` to its button**, which its script moves into the tab bar: a
  fixed form's tabs with nothing to translate are hidden by it (`li:has(> .wgl-locked)`).
<!-- #endregion contact-form-7 -->

### Site texts

The Site texts screen (`src/SiteTexts/`).

<!-- #region site-texts -->
- **Chrome's Translator API** (`self.Translator`, Chrome 138+ on desktop) for **Suggest a
  translation** on the Site texts screen. Without it, the button isn't shown.
- **`user_has_cap`** grants `wgl_site_texts` to anyone whose role edits a domain: the screen's
  menu and its form handlers ask for it.
- **Scan code reads PHP with `token_get_all()`** (to leave comments out) and Twig with a regular
  expression: a PHP tokenizer change, or a Twig syntax the expression doesn't know, makes calls go
  unseen.
- **The form's field names** (`texts[key][language]`, `domains[key]`, `unused[]`) are what the
  handlers read; the script relies on them too.
- **`document.execCommand('insertText')`** puts a suggestion into a field so that it can be undone;
  where a browser drops it, the script sets the value instead.
- **Before domains are set up, General goes to the roles with `edit_pages`**, read from
  `wp_roles()->roles[<role>]['capabilities']`: if `WP_Roles` changes that shape, nobody but
  administrators can edit the texts.
- **The compiled files are plain PHP, included**: `var_export()` of an array, written beside its
  place and `rename()`d over it, so no request includes half a file. A host that can't write
  under `WP_CONTENT_DIR/cache/` (or keeps it per server) only costs a query: pages read the
  option instead. PHP's opcache caches the files; a new version is a new folder, so a stale
  opcache can't serve an old text, and `opcache_invalidate()` covers a file written again.
- **`add_option_{option}`, `update_option_{option}` and `delete_option_{option}` fire for every
  write through the options API** (the screen, wp-cli, a seed): that's what keeps the version
  right. A write by SQL (or a migration tool that bypasses the API) leaves the version naming the
  old folder until the next save; emptying the cache folder writes it again from the option and
  puts the version right.
- **`wp_set_option_autoload()`** (WordPress 6.4) turns the texts' option's autoload off the first
  time the version is worked out.
<!-- #endregion site-texts -->

### Admin strings

The admin's own strings (`src/AdminStrings/`).

<!-- #region admin-strings -->
- **WordPress reads `.l10n.php` translation files** (6.5's `WP_Translation_Controller`): an array
  with `domain`, `language` and `messages`, a message keyed by its context, `\x04` and its text
  (`WP_Translation_File_PHP::parse_file()`). The translations are written in that shape. If core
  drops or reshapes the format, `load_textdomain()` refuses the files and every domain falls back
  to the filter (slower, still right).
- **`load_textdomain()` given a `.mo` path tries the `.l10n.php` beside it first**, unless
  `translation_file_format` is `mo`: the files are passed as `{domain}-{locale}.mo`, which doesn't
  exist. It also records the version's folder as the domain's path in `WP_Textdomain_Registry`,
  which is harmless while our domains ship no `.mo` of their own.
- **Of a domain's loaded files, the first that has a string wins**
  (`WP_Translation_Controller::locate_translation()`): the domain's own files are loaded first
  (`get_translations_for_domain()`), so they win, as they did over the filter. A
  `load_plugin_textdomain()` run after ours would come second.
- **Switching the locale keeps each locale's files loaded** (`WP_Locale_Switcher::load_translations()`
  unloads text domains as reloadable, which doesn't unload them since 6.5) and fires `change_locale`,
  where the switched-to locale's files are loaded.
- **`WP_Translation_Controller::unload_file()`** takes a file out of a domain, for a save in the
  same request: the old version's files are unloaded before the new ones load.
- **Translations loaded before `after_setup_theme`** make WordPress warn (6.7): they're loaded on
  it, at 0, so a string printed earlier isn't translated (WordPress's own rule).
- **Where the files can't be had, `gettext_with_context_{domain}`** runs for `_x()`, `esc_html_x()`
  and `esc_attr_x()`, with the translation gettext found (the admin strings show only when it's
  the text itself). The locale is `get_locale()` while a locale is switched, else
  `determine_locale()`. `gettext_with_context` runs before it: a translation another filter gives
  first wins, as a `.mo` file's does.
- **The files are written as site texts' are** (see Site texts): `WP_CONTENT_DIR/cache/`, a folder
  per version, the option's `add_`/`update_`/`delete_option_{option}` hooks keeping the version.
- **Timber's `_x` in Twig** is PHP's `_x()`, so Twig strings are translated the same way.
- **`WP_CLI::runcommand()` with `launch => false`** is how the theme's `wp base strings` hands over
  to `wp languages strings scan` in the same process.
- **After an upgrade, `wp languages strings scan --dry-run` should warn of no domain "kept as it
  was"**: one means a source moved (a plugin's folder, the theme's paths), and its strings are only
  being kept, not read.
<!-- #endregion admin-strings -->

### Admin screens

The Languages screen, the lists, the admin bar, the editor panel, the build (`src/Admin/`, `build.js`).

<!-- #region admin -->
- **The editor panel** uses `wp.plugins`, `wp.editor.PluginDocumentSettingPanel`
  (`wp.editPost`'s before 6.6), `wp.data`, `wp.components` and `wp.element`.
- **A REST field's `update_callback` runs after the post is saved**: the panel's mode switch writes
  through it, and the merge of a fixed translation counts on the post being there.
- **Plugins load in their folders' order**: `sitepress-multilingual-cms` before `wp-go-languages`,
  so `ICL_SITEPRESS_VERSION` is defined by the time the plugin decides to stand down. A WPML
  folder renamed to sort after it would load both.
- **`wpcf7_contact_form` has `show_ui` false**: the Languages screen offers it anyway. Its type has
  an empty `_edit_link` too, so `get_edit_post_link()` gives `''` for a form.
- **One classic script per entry**, and a stylesheet entry built as a module, which writes its
  `.css` alone (`build.js`). After a Vite upgrade, check `dist/` holds one file per entry.
- **The REST posts controller builds its answer from the `WP_Post` read before the additional
  fields are written**: after the panel changes a language, the plugin reads the post again, so the
  editor shows the new parent and slug.
- **`wp_go_languages/config` stays a plain filter**: the Languages screen captures the stored
  settings with a callback at `PHP_INT_MIN` to tell which ones the code sets.
<!-- #endregion admin -->

### AI translation

A translation's texts sent to a model (`src/Core/ai.php`, `ai-fields.php`, `src/Admin/ai.php`,
`_ai.js`).

<!-- #region ai-translation -->
- **OpenAI's chat completions shape**: `POST {endpoint}/chat/completions` with `model`,
  `temperature`, `response_format` and `messages`, the answer in `choices[0].message.content`.
  llama.cpp's `llama-server`, Ollama and LM Studio follow it; an endpoint that moves the content
  elsewhere answers "The AI endpoint answered nothing".
- **`response_format: { type: "json_object" }`** is honoured by llama.cpp (with the model's chat
  template, `--jinja`), Ollama and OpenAI. A server that ignores it may wrap the JSON in prose,
  which `ai_json()` still finds (first `{` to last `}`); one that refuses the field answers an
  error, which the screens show.
- **A thinking model's `<think>…</think>`** is dropped before reading the answer. A model that
  marks its reasoning another way, inside the content, makes the JSON unreadable:
  `--reasoning-budget 0` (llama.cpp) turns it off.
- **`wp_remote_post()`'s `timeout`** (300 s) and **`set_time_limit()`** (600 s) are what keep a slow
  chunk alive; the web server's own read timeout isn't ours to set.
- **Yoast SEO's meta keys** (`_yoast_wpseo_title`, `_metadesc`, `_bctitle`, `_opengraph-title`,
  `_opengraph-description`, `_twitter-title`, `_twitter-description`, in `ai_seo_keys()`) and their
  `translate` rule in Yoast's `wpml-config.xml`. A key Yoast renames stops being offered.
- **ACF block data**: a field's value under its name, its key under `_name` (`block_field()`), a
  repeater's cells as `name_0_sub`, a link as an array with `title`. A different layout leaves an
  ACF block's fields out.
- **`parse_blocks()` and `serialize_blocks()`** keep a block without inner blocks as one `innerHTML`
  and a one-item `innerContent`, and serialize `innerContent`: the writing sets both.
- **`WP_Block_Type_Registry::get_registered()->title`** names a block in the editor's dialog; a
  type without a title shows its name.
- **The REST nonce and `wp.apiFetch`**: the "+" dialog and the editor's call the routes with it, the
  Languages screen's test and Translation strings with `fetch()` and `X-WP-Nonce`.
<!-- #endregion ai-translation -->

### Migrating

The moves to and from WPML (`src/Migrate/`, `src/Cli/migrate.php`). Check them after upgrading
WPML by moving a copy of a site there and back.

<!-- #region migrating -->
- **WPML's tables, read with SQL** by `from-wpml`: `icl_translations` (`element_type` as
  `post_<type>` and `tax_<taxonomy>`, a term's `element_id` its `term_taxonomy_id`, the original's
  `source_language_code` NULL, a unique key on `trid` and language), `icl_languages` (`active`,
  `default_locale`, `english_name`, and `display_code`, WPML 5's URL segment, read only when the
  column is there), `icl_locale_map`, `icl_languages_translations`, `icl_strings` (`context` the
  domain, `gettext_context`, `value`) and `icl_string_translations` (`status` 10 complete). A
  renamed column makes the plan fail with a database error; a new meaning of one makes it wrong
  silently.
- **`icl_sitepress_settings`**: `default_language`, `languages_order`, `language_negotiation_type`
  (1 directories), `urls.directory_for_default_language`, `custom_posts_sync_option` and
  `taxonomies_sync_option` (0, 1, 2), `hidden_languages`. Read by `from-wpml`, written by `to-wpml`
  through `$sitepress->set_setting()` and `save_settings()`.
- **WPML's setup**: `wpml_get_setup_instance()`, and `WPML_Installation::finish_step1()`,
  `finish_step2()`, `finish_installation()` for a site whose setup isn't complete
  (`$sitepress->is_setup_complete()`), else `set_active_languages()` and
  `$sitepress->set_default_language()`. `finish_step1()` also gives every post a row in the
  default language when no translation exists yet; the groups are written over them.
- **`$sitepress->set_element_language_details($id, $type, $trid, $code, $source)`**: no trid
  removes the element's row and makes a new trid; a trid moves an existing row into it.
  `get_element_trid()` and `get_language_for_element()` read them back.
- **`WPML_Set_Language::run_exempt_core_flow()`** lets the writes through WPML's guard on paused
  languages, and **`WPML_Post_Translation_Metadata_Initializer::suspend()`/`resume()`** keep WPML
  from copying the original's copy fields into a translation whose source changes. Both are
  checked with `method_exists()`: without them the move still runs, but may be refused for a
  paused language, or copy fields over.
- **WPML's menu locations**: it keeps a location's default-language menu, and on saving the theme
  mod rewrites every location to it (`WPML_Nav_Menu_Actions`, `pre_update_option_theme_mods_*`).
  That's why `to-wpml` drops the `___<code>` locations. If WPML starts keeping one location per
  language, the menus move differently.
- **`icl_register_string(['domain' => …, 'context' => …], md5($text), $text)` and
  `icl_add_string_translation($id, $code, $value, ICL_TM_COMPLETE)`**, as the theme's
  `wp base strings` registers them (String Translation's gettext lookup finds them so).
- **ACFML's save on `wpml_setup_completed`** (`SetSameFieldsModeAsDefault`): every field group in
  the database saved again with `acfml_field_group_mode`. `to-wpml` stops ACF writing those saves
  to `acf-json` with `acf/json/save_paths` returning `[]` (ACF 6.2+; `save_file()` writes to no
  path then). If ACF renames the filter, or ACFML saves the groups on a later request, the
  theme's acf-json files are rewritten: check `git status` after a move to a copy.
- **ACFML's back-fill**: with String Translation on, ACFML registers every ACF field's label on the
  first loads after the setup, and can run out of memory at 128 MB. Not the move's doing, but it
  shows right after it.
<!-- #endregion migrating -->
