<?php

declare(strict_types=1);

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

$root = dirname(__DIR__);
$codemap = $root.'/app/Services/Ai/CODEMAP.md';

function fail(string $reason): never
{
    fwrite(STDERR, "GOD_DEBULK_CODEMAP_FAIL {$reason}\n");
    exit(1);
}

$autoload = $root.'/vendor/autoload.php';
if (! is_file($autoload)) {
    fail('missing_autoload');
}

require_once $autoload;

function renderedText(Node $node): string
{
    if ($node instanceof Text) {
        return $node->getLiteral();
    }

    $text = '';
    foreach ($node->children() as $child) {
        $text .= renderedText($child);
    }

    return $text;
}

function exactHeaderText(TableCell $cell): ?string
{
    $text = '';
    foreach ($cell->children() as $child) {
        if (! $child instanceof Text) {
            return null;
        }

        $text .= $child->getLiteral();
    }

    return $text;
}

/**
 * @return list<TableCell>
 */
function tableCells(TableRow $row): array
{
    $cells = [];
    foreach ($row->children() as $child) {
        if ($child instanceof TableCell) {
            $cells[] = $child;
        }
    }

    return $cells;
}

function exactCodeTarget(TableCell $cell): ?string
{
    $children = [];
    foreach ($cell->children() as $child) {
        $children[] = $child;
    }

    if (count($children) !== 1 || ! $children[0] instanceof Code) {
        return null;
    }

    $target = $children[0]->getLiteral();

    return preg_match('/^App(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+::[A-Za-z_][A-Za-z0-9_]*$/', $target) === 1
        ? $target
        : null;
}

/**
 * @return list<string>
 */
function navigationTargets(string $contents): array
{
    $environment = new Environment();
    $environment->addExtension(new CommonMarkCoreExtension());
    $environment->addExtension(new GithubFlavoredMarkdownExtension());
    $document = (new MarkdownParser($environment))->parse($contents);

    foreach ($document->iterator() as $table) {
        if (! $table instanceof Table) {
            continue;
        }

        $rows = [];
        foreach ($table->iterator() as $node) {
            if ($node instanceof TableRow) {
                $rows[] = $node;
            }
        }

        $header = array_shift($rows);
        if (! $header instanceof TableRow) {
            continue;
        }

        $headerCells = tableCells($header);
        $changeConcern = $headerCells[0] ?? null;
        $navigationTarget = $headerCells[1] ?? null;
        if (count($headerCells) !== 2
            || ! $changeConcern instanceof TableCell
            || ! $navigationTarget instanceof TableCell
            || $changeConcern->getType() !== TableCell::TYPE_HEADER
            || $navigationTarget->getType() !== TableCell::TYPE_HEADER
            || exactHeaderText($changeConcern) !== 'Change concern'
            || exactHeaderText($navigationTarget) !== 'Concrete navigation target') {
            continue;
        }

        $targets = [];
        foreach ($rows as $row) {
            $cells = tableCells($row);
            if (count($cells) !== 2 || trim(renderedText($cells[0])) === '') {
                fail('invalid_navigation_row');
            }

            $target = exactCodeTarget($cells[1]);
            if ($target === null) {
                fail('invalid_navigation_row');
            }
            $targets[] = $target;
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
