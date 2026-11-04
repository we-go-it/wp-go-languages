<?php

/**
 * Runs this package's tests: each `*.test.php` under tests/, in a WordPress
 * of its own (`wp eval-file`, through bootstrap.php), inside a database
 * transaction rolled back at its end, so nothing a test writes stays.
 *
 *   php ../wp-go-languages/tests/run.php            every test
 *   php ../wp-go-languages/tests/run.php fixed      the files whose name has "text"
 *   php ../wp-go-languages/tests/run.php js         the JS tests alone
 *
 * With every test, the JS tests run after the PHP ones: tests/js/, with
 * Node's test runner and jsdom (`yarn test` in the package, which needs its
 * `yarn install` first).
 *
 * Run from anywhere inside the project: the WordPress is the one whose
 * wp-cli.yml is found going up from the current folder. `composer
 * test:wp-go-languages` does it from the root. Exits with 1 when a check
 * fails or a file doesn't finish.
 *
 * A test file can name files to load before WordPress, for what has to be
 * there before the plugins read their settings (a third language):
 *
 *   // @require fixtures/third-language.php
 *
 * The path is the test file's folder's. wp-cli's own binary is `wp`, or the
 * WP_CLI_BIN environment variable.
 */

$tests = __DIR__;
$filter = $argv[1] ?? '';

// the project's root: where wp-cli.yml is
$root = getcwd();
while ($root !== dirname($root) && !is_file($root . '/wp-cli.yml')) {
    $root = dirname($root);
}
if (!is_file($root . '/wp-cli.yml')) {
    fwrite(STDERR, "No wp-cli.yml found from " . getcwd() . ": run inside the project.\n");
    exit(1);
}

$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tests, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (str_ends_with($file->getFilename(), '.test.php') && ($filter === '' || str_contains($file->getFilename(), $filter))) {
        $files[] = $file->getPathname();
    }
}
sort($files);

$js = is_dir($tests . '/js') && ($filter === '' || $filter === 'js');
if ($filter === 'js') {
    $files = [];
}

if (!$files && !$js) {
    fwrite(STDERR, "No tests" . ($filter !== '' ? " matching \"$filter\"" : '') . ".\n");
    exit(1);
}

$wp = getenv('WP_CLI_BIN') ?: 'wp';
$color = stream_isatty(STDOUT);
$paint = fn (string $text, string $code) => $color ? "\033[{$code}m$text\033[0m" : $text;
$totals = ['ok' => 0, 'fail' => 0];
$broken = [];

foreach ($files as $file) {
    $name = substr($file, strlen($tests) + 1);
    echo $paint($name, '1') . PHP_EOL;

    // `// @require …` lines: loaded by wp-cli before WordPress
    $requires = [];
    if (preg_match_all('#^//\s*@require\s+(\S+)#m', (string) file_get_contents($file), $found)) {
        foreach ($found[1] as $path) {
            $requires[] = '--require=' . escapeshellarg(dirname($file) . '/' . $path);
        }
    }

    $command = sprintf('%s eval-file %s %s %s 2>&1', $wp, escapeshellarg($tests . '/bootstrap.php'), escapeshellarg($file), implode(' ', $requires));
    $output = [];
    $started = microtime(true);
    exec('cd ' . escapeshellarg($root) . ' && ' . $command, $output);
    $seconds = microtime(true) - $started;

    $summary = null;
    foreach ($output as $line) {
        // PHP's deprecation notices from wp-cli and plugins, not the tests',
        // and a fatal error's stack trace (its message is enough)
        if (preg_match('/^(PHP )?Deprecated:|^Stack trace:|^#\d+ |^\s+thrown in /', $line) || trim($line) === '') {
            continue;
        }
        if (preg_match('/^WGTEST (\d+) (\d+)$/', $line, $match)) {
            $summary = [(int) $match[1], (int) $match[2]];
            continue;
        }
        echo '  ' . (str_starts_with($line, 'FAIL') ? $paint($line, '31') : $line) . PHP_EOL;
    }

    // how long the file took, WordPress's boot included: where a slow run goes
    echo '  ' . $paint(sprintf('%.1f s', $seconds), '2') . PHP_EOL;

    if ($summary === null) {
        $broken[] = $name;
        echo '  ' . $paint('did not finish: a fatal error, or an exit, before the end', '31') . PHP_EOL;
        continue;
    }

    $totals['ok'] += $summary[0];
    $totals['fail'] += $summary[1];
}

// The JS tests: Node's own report, its pass and fail counts in the totals
if ($js) {
    echo $paint('js/', '1') . PHP_EOL;
    $output = [];
    exec('cd ' . escapeshellarg(dirname($tests)) . ' && yarn --silent test 2>&1', $output, $code);
    $counted = false;
    foreach ($output as $line) {
        // its summary, as the spec reporter (ℹ) or as TAP (#), which it uses when piped
        if (preg_match('/^(?:ℹ|#) (pass|fail) (\d+)$/u', $line, $match)) {
            $totals[$match[1] === 'pass' ? 'ok' : 'fail'] += (int) $match[2];
            $counted = true;
        }
        if (preg_match('/^(not ok|✖)/u', ltrim($line))) {
            echo '  ' . $paint(trim($line), '31') . PHP_EOL;
        }
    }
    echo '  ' . ($code === 0 ? 'node --test: passed' : 'node --test: exit ' . $code) . PHP_EOL;
    if (!$counted || $code !== 0 && !$totals['fail']) {
        $broken[] = 'js/';
    }
}

echo PHP_EOL . sprintf('%d ok, %d failed', $totals['ok'], $totals['fail']) . ($broken ? ', ' . count($broken) . ' file(s) did not finish: ' . implode(', ', $broken) : '') . PHP_EOL;

exit($totals['fail'] || $broken ? 1 : 0);
