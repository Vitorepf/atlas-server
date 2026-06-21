<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Verify;

/**
 * DOC-CLAIM ANALYZER — the reality oracle behind the P3 "fake-implemented" verifier.
 *
 * It answers ONE question per canonical doc: which concrete, unambiguous "this
 * exists" claims does the doc make that the code does NOT honor? A canonical module
 * doc is supposed to describe what RUNS; when it references an App-namespaced class
 * that has no file, or tells the operator to `php artisan <cmd>` a command that is
 * not registered, the doc is documenting a fake implementation — drift the P3 mode
 * exists to close.
 *
 * Soundness over reach (the same discipline as the dead-code oracle): we extract
 * ONLY the two claim types that are decisive and low-ambiguity —
 *   1. fully-qualified `App\…` class/interface/trait/enum references  → the PSR-4
 *      file must exist (and declare the symbol);
 *   2. `php artisan <name>` command invocations                       → the command
 *      must be registered (its $signature must exist in the codebase).
 * Prose mentions, illustrative method calls, and non-App symbols are deliberately
 * NOT flagged — we never assert a phantom we cannot prove. Everything is resolved
 * against the WORKSPACE root, so the check is correct inside a throwaway scenario
 * copy where the loop edits only the doc and the code is frozen. No database, no
 * heavy read-model (which OOMs), no autoloading side effects: pure read of files.
 */
final class AtlasDocClaimAnalyzer
{
    public const SCHEMA = 'atlas.loop.doc_claim.v1';

    /** @var array<string,bool>|null cache of registered command names for the active workspace */
    private ?array $commandIndex = null;

    private string $indexedRoot = '';

    /** @var array<string,bool>|null authoritative registered-command set (injected; ground truth) */
    private ?array $registeredCommands = null;

    /**
     * Inject the AUTHORITATIVE set of registered artisan command names (from the booted
     * console application). When present this is the ground truth for command existence —
     * a static signature grep cannot see commands registered via $name, a base class, a
     * service provider, or a non-literal signature (that gap once produced 252 false
     * phantoms from one real command). Pass null to fall back to the grep index.
     *
     * @param  list<string>|null  $names
     */
    public function useRegisteredCommands(?array $names): void
    {
        if ($names === null) {
            $this->registeredCommands = null;

            return;
        }
        $set = [];
        foreach ($names as $name) {
            $set[strtolower(trim((string) $name))] = true;
        }
        $this->registeredCommands = $set;
    }

    /**
     * @return array{
     *     schema_version:string,
     *     path:string,
     *     readable:bool,
     *     phantoms:list<array{kind:string,symbol:string,reason:string}>,
     *     checked:array{classes:int,commands:int},
     *     note?:string
     * }
     */
    public function analyzeFile(string $absDocPath, ?string $workspaceRoot = null): array
    {
        $root = rtrim($workspaceRoot ?? (string) getcwd(), '/');
        $text = @file_get_contents($absDocPath);
        if ($text === false) {
            return [
                'schema_version' => self::SCHEMA,
                'path' => $absDocPath,
                'readable' => false,
                'phantoms' => [],
                'checked' => ['classes' => 0, 'commands' => 0],
                'note' => 'unreadable',
            ];
        }

        $classes = $this->extractClassClaims($text);
        $commands = $this->extractCommandClaims($text);

        $phantoms = [];
        foreach ($classes as $fqcn) {
            if (! $this->classExists($root, $fqcn)) {
                $phantoms[] = ['kind' => 'class', 'symbol' => $fqcn, 'reason' => 'no_psr4_file_declares_symbol'];
            }
        }
        foreach ($commands as $cmd) {
            if (! $this->commandExists($root, $cmd)) {
                $phantoms[] = ['kind' => 'command', 'symbol' => $cmd, 'reason' => 'not_a_registered_artisan_command'];
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'path' => $absDocPath,
            'readable' => true,
            'phantoms' => $phantoms,
            'checked' => ['classes' => count($classes), 'commands' => count($commands)],
        ];
    }

    /**
     * Fully-qualified App\… symbol references (optionally with a leading backslash and an
     * optional ::member tail we strip). De-duplicated.
     *
     * @return list<string>
     */
    private function extractClassClaims(string $text): array
    {
        $out = [];
        if (preg_match_all('/\\\\?\bApp(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $text, $m) > 0) {
            foreach ($m[0] as $raw) {
                $fqcn = ltrim($raw, '\\');
                // strip a ::member or trailing punctuation that regex word-classes already exclude
                $fqcn = preg_replace('/::.*$/', '', $fqcn) ?? $fqcn;
                if (substr_count($fqcn, '\\') >= 1) {
                    $out[$fqcn] = true;
                }
            }
        }

        return array_keys($out);
    }

    /**
     * `php artisan <name>` invocations — the unambiguous "run this command" claim.
     * Names are namespace:action tokens; we ignore option/argument tails.
     *
     * @return list<string>
     */
    private function extractCommandClaims(string $text): array
    {
        $out = [];
        if (preg_match_all('/php\s+artisan\s+([a-z][a-z0-9]*(?::[a-z0-9][a-z0-9-]*)+)/i', $text, $m) > 0) {
            foreach ($m[1] as $name) {
                $out[strtolower($name)] = true;
            }
        }

        return array_keys($out);
    }

    private function classExists(string $root, string $fqcn): bool
    {
        // PSR-4: App\  ->  app/   (the repo's autoload map). Class\Sub\Name -> app/Sub/Name.php
        $relative = substr($fqcn, strlen('App\\'));
        $base = $root.'/app/'.str_replace('\\', '/', $relative);

        // A reference like App\Services\Ai\AutonomousEvolution is frequently a NAMESPACE, not a
        // class. If a directory exists at that path the reference resolves to a real namespace —
        // not a phantom. Only when neither a declaring file NOR a namespace directory exists is
        // the symbol genuinely fake.
        if (is_dir($base)) {
            return true;
        }
        $leaf = $this->leaf($fqcn);
        $abs = $base.'.php';
        if (is_file($abs)) {
            $code = (string) @file_get_contents($abs);
            if (preg_match('/\b(class|interface|trait|enum)\s+'.preg_quote($leaf, '/').'\b/', $code) === 1) {
                return true;
            }
        }

        // PSR-4 names a file after its PRIMARY type, but a file may legally declare additional
        // public types under the SAME namespace (e.g. a sibling exception or value object). Scan
        // the FQCN's parent-namespace directory for any sibling that declares the leaf. This stays
        // PSR-4-faithful (a secondary type lives in the same namespace dir) and keeps soundness: a
        // genuinely fake leaf is declared in no sibling, so it is still flagged.
        $namespaceDir = \dirname($base);
        if (is_dir($namespaceDir)) {
            foreach (glob($namespaceDir.'/*.php') ?: [] as $sibling) {
                $code = (string) @file_get_contents($sibling);
                if (preg_match('/\b(class|interface|trait|enum)\s+'.preg_quote($leaf, '/').'\b/', $code) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function commandExists(string $root, string $name): bool
    {
        // Authoritative path: the booted console registry (ground truth).
        $index = $this->registeredCommands;
        if ($index === null) {
            $this->buildCommandIndex($root);
            $index = $this->commandIndex ?? [];
        }
        if (isset($index[$name])) {
            return true;
        }
        // Many docs use the `parent:action` colon shorthand for a command whose action is a
        // positional argument (e.g. `atlas:workspace-artifacts:brief` for the real command
        // `atlas:workspace-artifacts {action}`). The capability EXISTS; the colon is notation.
        // A command claim is a phantom only when NO colon-prefix is a registered command.
        $segments = explode(':', $name);
        for ($i = count($segments) - 1; $i >= 2; $i--) {
            $prefix = implode(':', array_slice($segments, 0, $i));
            if (isset($index[$prefix])) {
                return true;
            }
        }

        return false;
    }

    /**
     * One-pass index of registered command names in the workspace: every $signature's
     * first token under app/ plus Artisan::command('name' closures in routes/console.php.
     */
    private function buildCommandIndex(string $root): void
    {
        if ($this->hasCommandIndexForRoot($root)) {
            return;
        }
        $this->commandIndex = [];
        $this->indexedRoot = $root;

        $scanFiles = [];
        $appConsole = $root.'/app';
        if (is_dir($appConsole)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appConsole, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($this->isPhpFile($file)) {
                    $scanFiles[] = $file->getPathname();
                }
            }
        }
        $routesConsole = $root.'/routes/console.php';
        if (is_file($routesConsole)) {
            $scanFiles[] = $routesConsole;
        }

        foreach ($scanFiles as $abs) {
            $code = (string) @file_get_contents($abs);
            $this->indexCommandNamesFromCode($code);
        }
    }

    private function hasCommandIndexForRoot(string $root): bool
    {
        return $this->commandIndex !== null && $this->indexedRoot === $root;
    }

    private function isPhpFile(mixed $file): bool
    {
        return $file instanceof \SplFileInfo
            && $file->isFile()
            && $file->getExtension() === 'php';
    }

    private function indexCommandNamesFromCode(string $code): void
    {
        if (preg_match_all('/\$signature\s*=\s*[\'"]([^\s\'"]+)/', $code, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $this->commandIndex[strtolower($name)] = true;
            }
        }
        if (preg_match_all('/Artisan::command\(\s*[\'"]([^\s\'"]+)/', $code, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $this->commandIndex[strtolower($name)] = true;
            }
        }
    }

    private function leaf(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return (string) end($parts);
    }
}
