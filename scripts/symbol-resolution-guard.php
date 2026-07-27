<?php

declare(strict_types=1);

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
| Run standalone — never through phpunit:
|   php scripts/symbol-resolution-guard.php
|
| Exit 0 = no unresolvable trait use. Exit 1 = at least one (a real fatal).
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

        if (str_starts_with($import[1], 'App\\') && ! isset($declared[$import[1]])) {
            $candidateImports[$import[1]][] = $relative;
        }
    }

    preg_match_all('/^[ \t]+use\s+([A-Z][A-Za-z0-9_]*)\s*;/m', $source, $traits);
    foreach (array_unique($traits[1] ?? []) as $trait) {
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
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

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
    .' advisory='.count($unresolved)."\n";
exit(0);
