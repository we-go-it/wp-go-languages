<?php

namespace WpGo\Languages\Core;

/**
 * The plugin's built scripts and stylesheets, in dist/ (build.js, `yarn
 * build`). A path is the source's without `src/`: 'Admin/editor-panel.js'
 * is the build of src/Admin/editor-panel.js.
 */

function asset_url(string $path): string
{
    return plugins_url('dist/' . $path, WP_GO_LANGUAGES_FILE);
}

// The build's time, so a new build reaches the browser past its cache
function asset_version(string $path): string
{
    $file = dirname(WP_GO_LANGUAGES_FILE) . '/dist/' . $path;

    return is_file($file) ? (string) filemtime($file) : '';
}
