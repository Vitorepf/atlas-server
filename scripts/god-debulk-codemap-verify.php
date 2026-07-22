<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$codemap = $root.'/app/Services/Ai/CODEMAP.md';

function fail(string $reason): never
{
    fwrite(STDERR, "GOD_DEBULK_CODEMAP_FAIL {$reason}\n");
    exit(1);
}

if (! is_file($codemap)) {
    fail('missing_codemap');
}

$contents = file_get_contents($codemap);
if ($contents === false) {
    fail('unreadable_codemap');
}

if (! str_contains($contents, '<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->')) {
    fail('missing_incompleteness_marker');
}

preg_match_all('/`(?<target>App(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+::[A-Za-z_][A-Za-z0-9_]*)`/', $contents, $matches);
$targets = $matches['target'] ?? [];
if ($targets === []) {
    fail('missing_navigation_row');
}

foreach ($targets as $target) {
    [$class, $method] = explode('::', $target, 2);
    $relative = 'app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';
    $path = $root.'/'.$relative;

    if (! is_file($path)) {
        fail("missing_class={$class}");
    }

    $source = file_get_contents($path);
    if ($source === false || ! preg_match('/\\b(?:final\\s+|abstract\\s+)?class\\s+'.preg_quote(basename($relative, '.php'), '/').'\\b/', $source)) {
        fail("missing_class={$class}");
    }

    if (! preg_match('/\\bfunction\\s+&?\\s*'.preg_quote($method, '/').'\\s*\\(/', $source)) {
        fail("missing_method={$target}");
    }
}

echo 'GOD_DEBULK_CODEMAP_OK targets='.count($targets)."\n";
