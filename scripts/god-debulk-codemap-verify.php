<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$codemap = $root.'/app/Services/Ai/CODEMAP.md';

function fail(string $reason): never
{
    fwrite(STDERR, "GOD_DEBULK_CODEMAP_FAIL {$reason}\n");
    exit(1);
}

/**
 * @return list<string>|null
 */
function markdownTableCells(string $line): ?array
{
    $line = trim($line);
    if (! str_starts_with($line, '|') || ! str_ends_with($line, '|')) {
        return null;
    }

    return array_map('trim', explode('|', trim($line, '|')));
}

/**
 * @return list<string>
 */
function visibleMarkdownLines(string $contents): array
{
    $lines = preg_split('/\R/', $contents);
    if ($lines === false) {
        fail('unreadable_codemap');
    }

    $visible = [];
    $inFence = false;
    $inComment = false;

    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($inFence) {
            if (preg_match('/^(?:```|~~~)/', $trimmed)) {
                $inFence = false;
            }

            continue;
        }
        if (preg_match('/^(?:```|~~~)/', $trimmed)) {
            $inFence = true;

            continue;
        }

        while (true) {
            if ($inComment) {
                $end = strpos($line, '-->');
                if ($end === false) {
                    continue 2;
                }
                $line = substr($line, $end + 3);
                $inComment = false;
            }

            $start = strpos($line, '<!--');
            if ($start === false) {
                break;
            }

            $end = strpos($line, '-->', $start + 4);
            if ($end === false) {
                $line = substr($line, 0, $start);
                $inComment = true;

                break;
            }
            $line = substr($line, 0, $start).substr($line, $end + 3);
        }

        $visible[] = $line;
    }

    return $visible;
}

/**
 * @return list<string>
 */
function navigationTargets(string $contents): array
{
    $lines = visibleMarkdownLines($contents);

    foreach ($lines as $index => $line) {
        $header = markdownTableCells($line);
        if ($header !== ['Change concern', 'Concrete navigation target']) {
            continue;
        }

        $separator = markdownTableCells($lines[$index + 1] ?? '');
        if ($separator === null || count($separator) !== 2 || ! preg_match('/^:?-{3,}:?$/', $separator[0]) || ! preg_match('/^:?-{3,}:?$/', $separator[1])) {
            fail('invalid_navigation_table');
        }

        $targets = [];
        for ($row = $index + 2; $row < count($lines); $row++) {
            $cells = markdownTableCells($lines[$row]);
            if ($cells === null) {
                break;
            }
            if (count($cells) !== 2 || $cells[0] === '') {
                fail('invalid_navigation_row');
            }

            if (! preg_match('/^`(?<target>App(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+::[A-Za-z_][A-Za-z0-9_]*)`$/', $cells[1], $match)) {
                fail('invalid_navigation_row');
            }
            $targets[] = $match['target'];
        }

        if ($targets === []) {
            fail('missing_navigation_row');
        }

        return $targets;
    }

    fail('missing_navigation_row');
}

/**
 * @param  list<int|string|array{int,string,int}>  $tokens
 */
function nextNamedToken(array $tokens, int $index): ?string
{
    for ($index++; $index < count($tokens); $index++) {
        $token = $tokens[$index];
        if ((is_array($token) && in_array($token[0], [
            T_WHITESPACE,
            T_COMMENT,
            T_DOC_COMMENT,
            T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
            T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
        ], true)) || $token === '&') {
            continue;
        }

        return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
    }

    return null;
}

/**
 * @return string
 */
function namespaceDeclaration(array $tokens, int $index): string
{
    $namespace = '';
    for ($index++; $index < count($tokens); $index++) {
        $token = $tokens[$index];
        if ($token === ';') {
            return trim($namespace, '\\');
        }
        if ($token === '{') {
            return trim($namespace, '\\');
        }
        if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
            $namespace .= $token[1];
        }
    }

    return '';
}

/**
 * @return array<string,array<string,true>>
 */
function declaredClassMethods(string $source): array
{
    $declared = [];
    $classes = [];
    $namespace = '';
    $braceDepth = 0;
    $pendingClass = null;
    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $braceDepth++;

                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = namespaceDeclaration($tokens, $index);

                continue;
            }

            if ($token[0] === T_CLASS) {
                $name = nextNamedToken($tokens, $index);
                $pendingClass = $name === null ? null : ltrim($namespace.'\\'.$name, '\\');

                continue;
            }

            if ($token[0] === T_FUNCTION && $classes !== []) {
                $method = nextNamedToken($tokens, $index);
                $class = $classes[array_key_last($classes)];
                if ($method !== null && $braceDepth === $class['depth']) {
                    $declared[$class['name']][$method] = true;
                }
            }

            continue;
        }

        if ($token === '{') {
            $braceDepth++;
            if ($pendingClass !== null) {
                $classes[] = ['name' => $pendingClass, 'depth' => $braceDepth];
                $declared[$pendingClass] ??= [];
                $pendingClass = null;
            }

            continue;
        }

        if ($token === '}') {
            $class = $classes === [] ? null : $classes[array_key_last($classes)];
            if ($class !== null && $class['depth'] === $braceDepth) {
                array_pop($classes);
            }
            $braceDepth--;
        }
    }

    return $declared;
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

$targets = navigationTargets($contents);

foreach ($targets as $target) {
    [$class, $method] = explode('::', $target, 2);
    $relative = 'app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';
    $path = $root.'/'.$relative;

    if (! is_file($path)) {
        fail("missing_class={$class}");
    }

    $source = file_get_contents($path);
    $declared = $source === false ? [] : declaredClassMethods($source);
    if (! array_key_exists($class, $declared)) {
        fail("missing_class={$class}");
    }
    if (! isset($declared[$class][$method])) {
        fail("missing_method={$target}");
    }
}

echo 'GOD_DEBULK_CODEMAP_OK targets='.count($targets)."\n";
