<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use Symfony\Component\Process\Process;

/**
 * Computes, for a loop cycle's committed product files, which NEW product
 * classes the cycle introduced and which of those are actually CONSUMED by a
 * real runtime path (referenced as CODE by a non-test file under app/ other than
 * the class's own file). Feeds {@see InertNewClassDeliveryGate}.
 *
 * The detection is the load-bearing half of the inert-delivery defense, so it is
 * built to NOT be fooled:
 *   - A NEW class is one whose file does not exist on the baseline (HEAD); an
 *     EDIT to a pre-existing runtime file is not a new-class delivery.
 *   - "Consumed" requires a CODE reference (tokenizer-confirmed), not a mention
 *     in a comment/docblock or a string literal, and not a substring collision
 *     (a new `Port` is NOT marked consumed by `Portfolio`/`Export`). Both the
 *     unqualified short name (`new X`, `X::class`, `: X`) and a qualified-name
 *     reference whose last segment is the class (`use A\B\X;`, `new A\B\X`) count.
 *
 * {@see sourceReferencesClass()} is pure and exhaustively unit-tested; the git
 * orchestration around it (baseline check + candidate listing) is thin.
 */
final class RuntimeClassConsumptionScanner
{
    public const SCHEMA_VERSION = 'atlas.software_company_stewardship.runtime_class_consumption_scan.v1';

    /**
     * @param  list<string>  $changedFiles  the cycle's committed product files
     * @param  string  $baseRef  the pre-cycle baseline (e.g. main); a class is NEW iff absent there
     * @return array{schema_version:string, new_classes:list<string>, runtime_consumed_classes:list<string>, inert_new_classes:list<string>, new_class_files:array<string,string>}
     */
    public function scan(string $repoRoot, string $worktree, array $changedFiles, string $baseRef = 'main'): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $worktree = rtrim($worktree, '/') ?: $repoRoot;
        $baseRef = trim($baseRef) !== '' ? trim($baseRef) : 'main';

        // Fail-safe: if the baseline ref does not resolve (no git repo, missing
        // branch, test fixture), new-ness is undeterminable. Return EMPTY rather
        // than guess — an empty scan makes the gate report runtime_integrated, so
        // a degraded/non-git environment can never produce a FALSE inert block.
        if ($this->git($repoRoot, ['rev-parse', '--verify', '--quiet', $baseRef])['ok'] !== true) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'new_classes' => [],
                'runtime_consumed_classes' => [],
                'inert_new_classes' => [],
                'new_class_files' => [],
                'baseline_resolved' => false,
            ];
        }

        // NEW product classes: a changed product .php that did not exist on the
        // pre-cycle baseline. (The sandbox HEAD already contains the cycle commit,
        // so HEAD would wrongly classify every new file as pre-existing — compare
        // against the baseline ref instead.)
        $newFiles = []; // short name => relpath
        foreach ($changedFiles as $rel) {
            $rel = trim((string) $rel);
            if (! $this->isProductPhpClassPath($rel)) {
                continue;
            }
            // Existed on the baseline => an edit to live code, not a new-class delivery.
            if ($this->git($repoRoot, ['cat-file', '-e', $baseRef.':'.$rel])['ok'] === true) {
                continue;
            }
            $newFiles[basename($rel, '.php')] = $rel;
        }

        $consumed = [];
        foreach ($newFiles as $short => $ownFile) {
            // Word-boundary grep narrows candidate consumers cheaply (so 'Port'
            // does not even list 'Portfolio'); search the WORKTREE so this cycle's
            // own wiring counts. Tokenizer confirmation rejects comment/string hits.
            $grep = $this->git($worktree, ['grep', '-l', '-w', '--', $short, '--', 'app']);
            if ($grep['ok'] !== true) {
                continue; // grep exit 1 = no match anywhere
            }
            foreach (preg_split('/\R/', trim((string) $grep['out'])) ?: [] as $hit) {
                $hit = trim($hit);
                if ($hit === '' || $hit === $ownFile || ! $this->isProductPhpClassPath($hit)) {
                    continue;
                }
                $src = @file_get_contents($worktree.'/'.$hit);
                if (is_string($src) && $this->sourceReferencesClass($src, $short)) {
                    $consumed[] = $short;
                    break;
                }
            }
        }

        $consumed = array_values(array_unique($consumed));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'new_classes' => array_keys($newFiles),
            'runtime_consumed_classes' => $consumed,
            'inert_new_classes' => array_values(array_diff(array_keys($newFiles), $consumed)),
            'new_class_files' => $newFiles,
        ];
    }

    /**
     * PURE: does $source reference the class named $className as CODE? Tokenizer
     * based, so the name inside a comment/docblock or a string literal does NOT
     * count, and a substring (Portfolio vs Port) does NOT count. Matches the
     * unqualified short name (T_STRING) and any qualified name whose last segment
     * is $className (use/new/type-hint on a fully or partially qualified name).
     */
    public function sourceReferencesClass(string $source, string $className): bool
    {
        $className = trim($className);
        if ($className === '' || $source === '') {
            return false;
        }
        if (! str_contains($source, '<?php')) {
            $source = "<?php\n".$source;
        }

        try {
            $tokens = @token_get_all($source, TOKEN_PARSE);
        } catch (\Throwable) {
            $tokens = @token_get_all($source);
        }
        if (! is_array($tokens)) {
            return false;
        }

        $qualifiedIds = array_values(array_filter([
            defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : null,
            defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
            defined('T_NAME_RELATIVE') ? T_NAME_RELATIVE : null,
        ], static fn ($v) => $v !== null));

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }
            [$id, $value] = [$token[0], (string) $token[1]];
            if ($id === T_STRING && $value === $className) {
                return true;
            }
            if (in_array($id, $qualifiedIds, true)) {
                $segments = explode('\\', $value);
                if (end($segments) === $className) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isProductPhpClassPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '' || ! str_ends_with($path, '.php')) {
            return false;
        }
        if (str_ends_with($path, 'Test.php') || str_contains($path, '/tests/') || str_starts_with($path, 'tests/')) {
            return false;
        }

        return str_starts_with($path, 'app/') || str_contains($path, '/app/');
    }

    /**
     * @param  list<string>  $args
     * @return array{ok:bool,out:string,err:string}
     */
    private function git(string $cwd, array $args): array
    {
        $process = new Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(60);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'out' => $process->getOutput(),
            'err' => $process->getErrorOutput(),
        ];
    }
}
