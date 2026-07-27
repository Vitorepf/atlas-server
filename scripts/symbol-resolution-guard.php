<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

/*
| Symbol-resolution guard for app/.
|
| Catches the defect class that mass-deletion campaigns leave behind: code that
| names a type PHP cannot resolve, which is fatal at class load.
|
| BLOCKING check — `use SomeTrait;` inside a class body with no matching import.
| PHP resolves it in the file's OWN namespace, so when the trait lives elsewhere
| the class simply cannot load. This is how AtlasSelfConstructionHumanCompletion
| Receipt{Endgame,PreSubmission}VerifierService died silently after cd018c6b3f —
| the trait had moved to SelfConstruction\Support. Purely static and exact:
| a trait `use` either resolves in its own namespace or it was imported.
|
| ADVISORY check — `use App\...\X;` importing a class declared nowhere on disk.
| This one is NOT blocking, and deliberately so: several such imports resolve
| perfectly at runtime through the alias autoloaders registered in
| Compat/RootSinglesLegacyAliases, AcosMaxNamespaceAlias and
| CognitiveNamespaceAlias (class_alias). A purely static check calls those false
| positives — so the advisory list is confirmed against the REAL autoloader
| before anything is reported. What survives is a genuinely absent symbol, which
| still may be harmless when every consumer guards it with ?nullable or
| try/catch (see the Núcleo Essencial D8 lesson). Hence: report, never fail.
|
| BLOCKING check — a registered `atlas:*` command whose handle() dependencies the
| container cannot build. Laravel injects handle() arguments BEFORE the body
| runs, so such a command is dead on arrival: the operator sees it in
| `artisan list` and it throws the moment they invoke it — even when the command
| would have returned early on its own feature flag. Five were found this way,
| each dead since a different mass-deletion campaign: aael:parallel, aael:trace,
| aael:rollback, code:deadcode-check, memory:maintain.
|
| Run standalone — never through phpunit (needs -d memory_limit=1G, since it
| holds the corpus statically and then boots the app):
|   php -d memory_limit=1G scripts/symbol-resolution-guard.php
|
| Exit 0 = every trait resolves and every command can be built.
| Exit 1 = at least one real fatal.
*/

$root = dirname(__DIR__);

$sources = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
}

/** @var array<string,string> $declared FQCN => file */
$declared = [];
foreach ($sources as $path => $source) {
    preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $ns);
    $namespace = $ns[1] ?? '';
    preg_match_all(
        '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_]\w*)/m',
        $source,
        $names,
    );
    foreach ($names[1] ?? [] as $name) {
        $declared[$namespace === '' ? $name : $namespace.'\\'.$name] = $path;
    }
}

$fatals = [];
$candidateImports = [];
$candidateRefs = [];

foreach ($sources as $path => $source) {
    if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $ns) !== 1) {
        continue;
    }
    $namespace = $ns[1];
    $relative = str_replace($root.'/', '', $path);

    preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)\s*(?:as\s+(\w+))?\s*;/m', $source, $imports, PREG_SET_ORDER);
    $importedShort = [];
    foreach ($imports as $import) {
        $short = $import[2] ?? substr((string) strrchr('\\'.$import[1], '\\'), 1);
        $importedShort[$short] = true;

        // `use App\X\Y;` where Y is a NAMESPACE, not a class, is legal PHP: the
        // file then writes Y\Z to mean App\X\Y\Z. Those are not absent symbols.
        $asDirectory = $root.'/app/'.str_replace('\\', '/', substr($import[1], strlen('App\\')));

        if (str_starts_with($import[1], 'App\\')
            && ! isset($declared[$import[1]])
            && ! is_dir($asDirectory)) {
            $candidateImports[$import[1]][] = $relative;
        }
    }

    // Indented `use X;` is a trait use inside the class body. Three shapes reach
    // here: bare (`use Foo;`), fully qualified (`use \App\...\Foo;`) and relative
    // (`use Concerns\Foo;`). All three have been seen broken in this corpus.
    preg_match_all('/^[ \t]+use\s+(\\\\?[A-Z][A-Za-z0-9_\\\\]*)\s*;/m', $source, $traits);
    foreach (array_unique($traits[1] ?? []) as $traitRef) {
        $trait = ltrim($traitRef, '\\');

        // qualified — resolve absolutely, or relative to this namespace
        if (str_contains($trait, '\\')) {
            if (isset($declared[$trait]) || isset($declared[$namespace.'\\'.$trait])) {
                continue;
            }
            $fatals[] = "{$relative}: uses trait [{$traitRef}] which is declared nowhere under app/";

            continue;
        }

        if (isset($importedShort[$trait]) || isset($declared[$namespace.'\\'.$trait])) {
            continue;
        }

        $elsewhere = [];
        foreach ($declared as $fqcn => $_) {
            if (str_ends_with($fqcn, '\\'.$trait)) {
                $elsewhere[] = $fqcn;
            }
        }

        $fatals[] = "{$relative}: uses trait [{$trait}] with no import; "
            .($elsewhere === []
                ? 'declared nowhere under app/'
                : 'lives at ['.implode(', ', array_slice($elsewhere, 0, 2)).'] — add the import');
    }

    // An unqualified `Foo::`, `new Foo`, `instanceof Foo`, `extends Foo` or
    // `implements Foo` resolves to Namespace\Foo. When a file is split out of
    // its host — a trait peeled from a façade, a class rehomed a level deeper —
    // the imports stay behind and every such name silently starts pointing at a
    // class that does not exist. The file itself still loads; the fatal only
    // fires when the method runs, which is why this survived every gate.
    // Strip comments and string literals FIRST. A class name inside a docblock
    // or a quoted string is not a code reference — that is the D6 trap that
    // made an earlier draft of this check report 300+ phantom fatals.
    $code = preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;
    $code = preg_replace('#(^|\s)//[^\n]*#', '$1', $code) ?? $code;
    $code = preg_replace('#(^|\s)\#[^\n]*#', '$1', $code) ?? $code;
    $code = preg_replace("#'(?:\\\\.|[^'\\\\])*'#", "''", $code) ?? $code;
    $code = preg_replace('#"(?:\\\\.|[^"\\\\])*"#', '""', $code) ?? $code;

    preg_match_all('/(?<![\\\\$>\w])([A-Z][A-Za-z0-9_]{2,})::/', $code, $r1);
    preg_match_all('/\bnew\s+([A-Z][A-Za-z0-9_]{2,})\s*[(;]/', $code, $r2);
    preg_match_all('/\b(?:instanceof|extends|implements)\s+([A-Z][A-Za-z0-9_]{2,})\b/', $code, $r3);

    foreach (array_unique(array_merge($r1[1] ?? [], $r2[1] ?? [], $r3[1] ?? [])) as $ref) {
        if (isset($importedShort[$ref]) || isset($declared[$namespace.'\\'.$ref])) {
            continue;
        }
        // global / vendor / builtin — a bare name also falls back to the global
        // scope for interfaces like Throwable, and vendor classes are imported
        // elsewhere in the file; only flag names nothing anywhere declares.
        // Only flag names WE declare somewhere else under app/. A bare name we
        // never declare is a vendor class or PHP builtin reached through the
        // global fallback — not our bug, and guessing there is how a guard
        // starts crying wolf.
        $where = [];
        foreach ($declared as $fqcn => $_) {
            if (str_ends_with($fqcn, '\\'.$ref)) {
                $where[] = $fqcn;
            }
        }
        if ($where === []) {
            continue;
        }

        // Confirmed against the REAL autoloader further down — the class_alias
        // shims make several of these resolve fine at runtime.
        $candidateRefs[$namespace.'\\'.$ref][] = "{$relative}: references [{$ref}] with no import — "
            ."resolves to [{$namespace}\\{$ref}]; real class at ["
            .implode(', ', array_slice($where, 0, 2)).']';
    }
}

// Advisory: only symbols the REAL autoloader (aliases included) cannot resolve.
$unresolved = [];
$scannedFiles = count($sources);
$scannedSymbols = count($declared);
// Booting the app discovers every command; holding the whole corpus in memory
// at the same time exhausts the default limit. The static pass is done — drop it.
unset($sources, $declared);
gc_collect_cycles();

if ($candidateImports !== []) {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    foreach ($candidateImports as $fqcn => $consumers) {
        try {
            $resolves = class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn);
        } catch (Throwable) {
            $resolves = false;
        }
        if (! $resolves) {
            $unresolved[$fqcn] = $consumers;
        }
    }
}

// Confirm the unimported references against the REAL autoloader: the
// class_alias shims resolve several namespaces that look wrong statically.
if ($candidateRefs !== []) {
    if (! isset($app)) {
        require $root.'/vendor/autoload.php';
        $app = require $root.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }
    foreach ($candidateRefs as $fqcn => $messages) {
        try {
            $resolves = class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn);
        } catch (Throwable) {
            $resolves = false;
        }
        if (! $resolves) {
            foreach ($messages as $message) {
                $fatals[] = $message;
            }
        }
    }
}

// BLOCKING: a registered command whose handle() dependencies the container
// cannot build is dead on arrival — the operator sees it in `artisan list` and
// it throws the moment they run it. Five such commands were found this way
// (aael:parallel, aael:trace, aael:rollback, code:deadcode-check,
// memory:maintain), each dead since a different mass-deletion campaign.
if (! isset($app)) {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
}

$commandCount = 0;
foreach (Artisan::all() as $name => $command) {
    if (! str_starts_with($name, 'atlas:')) {
        continue;
    }
    $commandCount++;

    try {
        $reflection = new ReflectionClass($command);
        if (! $reflection->hasMethod('handle')) {
            continue;
        }
        foreach ($reflection->getMethod('handle')->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || $parameter->isOptional()) {
                continue;
            }
            try {
                $app->make($type->getName());
            } catch (Throwable $e) {
                $fatals[] = "command [{$name}]: cannot resolve handle() dependency ["
                    .$type->getName().'] — '.strtok($e->getMessage(), "\n");
                break;
            }
        }
    } catch (Throwable $e) {
        $fatals[] = "command [{$name}]: not reflectable — ".strtok($e->getMessage(), "\n");
    }
}

if ($unresolved !== []) {
    echo 'ADVISORY absent_symbols='.count($unresolved)." (not blocking — consumers may guard with ?nullable/try-catch)\n";
    foreach ($unresolved as $fqcn => $consumers) {
        echo '  '.$fqcn.'  <- '.count($consumers)." consumer(s)\n";
        foreach (array_slice($consumers, 0, 3) as $consumer) {
            echo '      '.$consumer."\n";
        }
    }
    echo "\n";
}

if ($fatals !== []) {
    fwrite(STDERR, 'ATLAS_SYMBOL_RESOLUTION_FAIL count='.count($fatals)."\n");
    foreach ($fatals as $fatal) {
        fwrite(STDERR, '  '.$fatal."\n");
    }
    exit(1);
}

echo 'ATLAS_SYMBOL_RESOLUTION_OK files='.$scannedFiles.' symbols='.$scannedSymbols
    .' commands='.$commandCount.' advisory='.count($unresolved)."\n";
exit(0);
