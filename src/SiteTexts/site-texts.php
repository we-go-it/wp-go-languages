<?php

/**
 * Site texts: the words the site prints on its own, written per language.
 *
 * Buttons ("Load more"), headings of fixed sections, form messages: texts
 * that aren't content of any page, but still need a value in every
 * language. The code prints a key instead of the text
 * (`wp_go_site_text('events.load_more')`, or the theme's Twig `t()`), and
 * editors write each key's text, per language, on the Site texts screen.
 *
 * In short:
 *
 *   - a key is dotted, `footer.contact_us`: its first part is the group the
 *     screen lists it under ("Footer"), and how the REST route nests it
 *   - a key with no text in the language being printed prints itself, so a
 *     gap shows on the site
 *   - administrators add and remove keys; "Scan code" reads the theme's code
 *     to offer the keys it prints and to point out the ones it doesn't
 *     print any more (nothing is ever removed on its own)
 *   - each text can be given a translation suggestion by the browser
 *     (Chrome's built-in Translator, on-device): typed into the field, never
 *     saved without the editor saving
 *   - the site's languages come from this plugin; a language removed from
 *     the Languages screen keeps its texts, and has them back if added again
 *
 * Where things are:
 *
 *   texts.php    the stored texts: reading, writing, removing, what a key is
 *   compiled.php the texts a page prints: a PHP file per language, from the option
 *   scan.php     reading the code for the keys it prints
 *   actions.php  what the screen's forms do (save, add a key, add those found)
 *   screen.php   the screen, a language at a time; its styles and script are site-texts.css and site-texts.js, beside it
 *   rest.php     the texts over REST
 *
 * The same feature is in WP-Go (features/site-texts), for sites on WPML, with
 * the same functions, hooks, option and REST route, so the theme and Cache
 * Tags work with either. WP-Go's stands down while this plugin is active.
 * Its files are laid out the same way (in its includes/), function for
 * function, so a diff between the two shows only what really differs: the
 * domains, which are this plugin's, and the screen, here a language at a time, the
 * one picked in the admin bar. What one does, the other must do too, but for those.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/texts.php';
require_once __DIR__ . '/compiled.php';
require_once __DIR__ . '/scan.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/screen.php';
require_once __DIR__ . '/rest.php';
