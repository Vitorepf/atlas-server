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

// Fail closed on the guard's OWN death. Laravel's error handler boots partway
// through this script and renders internal errors to stdout with exit 0 — a
// broken guard would report success. The sentinel flips only on the last line.
$guardCompleted = false;
register_shutdown_function(static function () use (&$guardCompleted): void {
    if ($guardCompleted) {
        return;
    }
    fwrite(STDERR, "ATLAS_SYMBOL_RESOLUTION_ABORTED — guard died before reaching its verdict\n");
    exit(1);
});

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
    // Tokenize instead of regex. Comments, strings, heredocs and nowdocs must
    // not count as code references — this corpus embeds JavaScript (`new Chart`)
    // and generated PHP templates (`extends TestCase`) inside them, and a
    // regex draft of this check reported 300+ phantoms because of exactly that.
    // token_get_all is the only thing that gets this right every time.
    $tokens = @token_get_all($source);
    $references = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        $name = $token[1];
        if ($name === '' || ! ctype_upper($name[0]) || strlen($name) < 3) {
            continue;
        }

        // Already qualified (\Foo, Bar\Foo) — resolution is explicit, skip.
        $previous = null;
        for ($p = $i - 1; $p >= 0; $p--) {
            if (is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                continue;
            }
            $previous = $tokens[$p];
            break;
        }
        if ($previous === '\\'
            || (is_array($previous) && in_array($previous[0], [T_NS_SEPARATOR, T_OBJECT_OPERATOR, T_FUNCTION, T_CONST], true))) {
            continue;
        }
        // A declaration of this very symbol, not a reference to another one.
        if (is_array($previous) && in_array($previous[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_USE], true)) {
            continue;
        }

        $next = null;
        for ($n = $i + 1; $n < $count; $n++) {
            if (is_array($tokens[$n]) && $tokens[$n][0] === T_WHITESPACE) {
                continue;
            }
            $next = $tokens[$n];
            break;
        }

        $isStaticAccess = is_array($next) && $next[0] === T_DOUBLE_COLON;
        $isConstructed = is_array($previous) && $previous[0] === T_NEW;
        $isTypeBound = is_array($previous)
            && in_array($previous[0], [T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS], true);

        // A parameter type-hint: `Foo $bar` / `?Foo $bar`. This shape was the gap
        // — I introduced one myself moving a method between classes, and only a
        // runtime TypeError caught it, because the class LOADS fine and the hint
        // is only checked when the method is called. Same defect family as the
        // rest: unimported name resolving into the file's own namespace.
        $isParameterHint = is_array($next) && $next[0] === T_VARIABLE
            && ($previous === '(' || $previous === ',' || $previous === '?'
                || (is_array($previous) && $previous[0] === T_WHITESPACE));

        if ($isStaticAccess || $isConstructed || $isTypeBound || $isParameterHint) {
            $references[$name] = true;
        }
    }

    foreach (array_keys($references) as $ref) {
        if (isset($importedShort[$ref]) || isset($declared[$namespace.'\\'.$ref])) {
            continue;
        }
        // PHP has NO global fallback for class names: inside namespace N, a bare
        // `Foo` always means N\Foo. So an unimported reference either resolves
        // there or it is a fatal — vendor class or not. Two of the four fatals
        // this check was blind to were exactly that: a bare `File::` and a bare
        // `Log::` left behind when a peel dropped the facade imports.
        $where = [];
        foreach ($declared as $fqcn => $_) {
            if (str_ends_with($fqcn, '\\'.$ref)) {
                $where[] = $fqcn;
            }
        }

        // Confirmed against the REAL autoloader further down — the class_alias
        // shims make several of these resolve fine at runtime.
        $candidateRefs[$namespace.'\\'.$ref][] = "{$relative}: references [{$ref}] with no import — "
            ."resolves to [{$namespace}\\{$ref}]"
            .($where === [] ? ' — declared nowhere under app/ either (vendor import lost?)'
                : '; real class at ['.implode(', ', array_slice($where, 0, 2)).']');
    }
}

// Advisory: only symbols the REAL autoloader (aliases included) cannot resolve.
$unresolved = [];
$scannedFiles = count($sources);
$scannedSymbols = count($declared);
// Names only — the autoload check below still needs them after the corpus is
// dropped, and the names are a rounding error next to the file bodies.
$declaredNames = array_keys($declared);
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

// BLOCKING: a class that cannot survive its own autoload. On 2026-07-13 the
// scheduler died on exactly this — AtlasCliCockpitCommand narrowed Command::line()
// to protected, and the resulting fatal in discoverCommands() killed every artisan
// invocation. The Autônomos stayed down 14 days before anyone noticed.
//
// The app boot below only proves the EAGERLY discovered classes load (commands).
// Services autoload lazily, so a service with the same defect stays invisible
// until the path that needs it runs. This loads every declared symbol.
//
// Runs in a subprocess on purpose: a fatal kills the process that hits it, so the
// process that must report it cannot be the same one. memory_limit is raised
// because loading ~6.6k classes in one process exhausts the 128M default — that
// exhaustion looks exactly like a code defect if you don't rule it out.
$symbolListFile = tempnam(sys_get_temp_dir(), 'atlas-symbols-');
file_put_contents($symbolListFile, json_encode($declaredNames));
$loadProbe = <<<'PHP'
$symbols = json_decode(file_get_contents($argv[1]), true);
$progressFile = $argv[2];
foreach ($symbols as $index => $symbol) {
    file_put_contents($progressFile, (string) $index);
    try {
        class_exists($symbol) || interface_exists($symbol) || trait_exists($symbol) || enum_exists($symbol);
    } catch (Throwable $e) {
        // A throwable is recoverable and not what this check is for.
    }
}
file_put_contents($progressFile, 'done');
PHP;
$loadProbeFile = tempnam(sys_get_temp_dir(), 'atlas-loadprobe-');
file_put_contents($loadProbeFile, "<?php\nrequire '".$root."/vendor/autoload.php';\n".$loadProbe);
$progressFile = tempnam(sys_get_temp_dir(), 'atlas-loadprog-');
exec(
    escapeshellarg(PHP_BINARY).' -d memory_limit=2G '.escapeshellarg($loadProbeFile)
        .' '.escapeshellarg($symbolListFile).' '.escapeshellarg($progressFile).' 2>&1',
    $loadOutput,
    $loadExit,
);
if ($loadExit !== 0) {
    $stalled = trim((string) @file_get_contents($progressFile));
    $symbolNames = $declaredNames;
    $culprit = ctype_digit($stalled) ? ($symbolNames[(int) $stalled] ?? '?') : '?';
    $fatals[] = 'unloadable_class '.$culprit.' — fatal during autoload (exit '.$loadExit.'): '
        .trim(implode(' | ', array_slice($loadOutput, 0, 2)));
}
@unlink($symbolListFile);
@unlink($loadProbeFile);
@unlink($progressFile);

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
    $guardCompleted = true;
    exit(1);
}

echo 'ATLAS_SYMBOL_RESOLUTION_OK files='.$scannedFiles.' symbols='.$scannedSymbols
    .' commands='.$commandCount.' advisory='.count($unresolved)."\n";
$guardCompleted = true;
exit(0);
