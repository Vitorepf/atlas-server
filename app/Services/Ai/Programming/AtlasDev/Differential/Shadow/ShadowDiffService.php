<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Differential\Shadow;

use Symfony\Component\Process\Process;

/**
 * E4 -- Shadow-diff service for pure functions.
 *
 * The orchestrator that, for a patch's touched PHP files:
 *   1. Extracts the OLD function set from `git show HEAD:<path>` and the
 *      NEW function set from the workspace file (post-patch).
 *   2. For each function that exists in BOTH (MODIFIED), conservatively
 *      classifies BOTH bodies via {@see PureFunctionDetector} (VAL-E4-007:
 *      impure => skip, no false flag).
 *   3. For each pure MODIFIED symbol, generates probe inputs via
 *      {@see ShadowDiffInputProvider} and runs the {@see ShadowDiffHarness}
 *      to capture old vs new outputs.
 *   4. Compares outputs; divergence => the symbol contributes to the
 *      divergence set (VAL-E4-005).
 *
 * Skip paths (never a false flag, never an exception):
 *   - No PHP files in the diff => SKIP with reason.
 *   - No functions in either old or new => SKIP with reason.
 *   - A symbol present ONLY in new => newly-added, SKIP with reason per
 *     symbol (VAL-E4-011: no baseline to diff, no crash, no fabricated
 *     divergence).
 *   - A symbol impure in old OR new => SKIP that symbol with reason
 *     (VAL-E4-007).
 *   - Harness execution failure (parse error, runtime error, timeout) =>
 *     SKIP that symbol with reason (honest ceiling: never fabricate a
 *     divergence).
 *   - git/workspace unreadable => SKIP the whole run with reason.
 *
 * Agreement path (VAL-E4-008): all evaluated symbols' old outputs equal
 * their new outputs across every probe input => no flag, stays green on
 * this axis (a behavior-preserving pure refactor).
 *
 * Structural, model-irrelevant: the service is deterministic for the same
 * (workspace state, file list, harness). The harness is injectable so tests
 * script outputs without spawning real subprocesses; production wires a
 * subprocess harness.
 *
 * Canonical: mission architecture.md (Atlas Dev Elevation v2, M5 / E4,
 * e4-shadow-diff-pure-functions feature).
 */
final class ShadowDiffService
{
    public function __construct(
        private readonly ShadowDiffHarness $harness,
        private readonly ShadowDiffSymbolExtractor $extractor = new ShadowDiffSymbolExtractor,
        private readonly PureFunctionDetector $purityDetector = new PureFunctionDetector,
        private readonly ShadowDiffInputProvider $inputProvider = new ShadowDiffInputProvider,
        private readonly ?string $phpBinary = null,
    ) {}

    /**
     * Evaluate the shadow-diff for the touched PHP files.
     *
     * @param  string  $workspace  absolute path to the git workspace (post-patch state).
     * @param  list<string>  $changedPhpFiles  repo-relative PHP file paths that changed.
     * @return ShadowDiffResult
     */
    public function evaluate(string $workspace, array $changedPhpFiles): ShadowDiffResult
    {
        $changedPhpFiles = array_values(array_filter(
            array_map(static fn ($p): string => is_string($p) ? trim($p) : '', $changedPhpFiles),
            static fn (string $p): bool => $p !== '' && str_ends_with($p, '.php'),
        ));

        if ($changedPhpFiles === []) {
            return ShadowDiffResult::skipped('no PHP files in the diff');
        }

        $divergentSymbols = [];
        $evaluatedSymbols = [];
        $skippedSymbols = [];
        $anyEvaluated = false;
        $anyCandidateSeen = false;

        foreach ($changedPhpFiles as $relativePath) {
            $oldSource = $this->readOldSource($workspace, $relativePath);
            $newSource = $this->readNewSource($workspace, $relativePath);

            if ($newSource === null) {
                // Workspace file unreadable: skip the file (honest, no crash).
                $skippedSymbols[] = [
                    'symbol' => '(file:'.basename($relativePath).')',
                    'file' => $relativePath,
                    'reason' => 'workspace file unreadable',
                ];
                continue;
            }

            $oldSymbols = $oldSource !== null ? $this->extractor->extract($oldSource) : [];
            $newSymbols = $this->extractor->extract($newSource);

            foreach ($newSymbols as $name => $newSymbol) {
                $anyCandidateSeen = true;

                // VAL-E4-011: symbol only in NEW => newly-added, no baseline.
                if (! isset($oldSymbols[$name])) {
                    $skippedSymbols[] = [
                        'symbol' => $name,
                        'file' => $relativePath,
                        'reason' => 'newly-added: no prior implementation to diff',
                    ];
                    continue;
                }

                $oldSymbol = $oldSymbols[$name];

                // VAL-E4-007: conservative purity — BOTH bodies must be pure.
                $oldImpure = $this->purityDetector->impureIndicator($oldSymbol->body);
                if ($oldImpure !== null) {
                    $skippedSymbols[] = [
                        'symbol' => $name,
                        'file' => $relativePath,
                        'reason' => 'old version impure: '.$oldImpure,
                    ];
                    continue;
                }
                $newImpure = $this->purityDetector->impureIndicator($newSymbol->body);
                if ($newImpure !== null) {
                    $skippedSymbols[] = [
                        'symbol' => $name,
                        'file' => $relativePath,
                        'reason' => 'new version impure: '.$newImpure,
                    ];
                    continue;
                }

                // Both bodies pure: build the callable source fragments and
                // the probe inputs, then ask the harness.
                $probeInputs = $this->inputProvider->inputsForSignature(
                    $this->extractParameterList($newSymbol->signature),
                );

                $oldCallable = $this->wrapAsCallable('shadowdiff_old', $newSymbol->signature, $oldSymbol->body);
                $newCallable = $this->wrapAsCallable('shadowdiff_new', $newSymbol->signature, $newSymbol->body);

                $harnessResult = $this->harness->shadowDiff($oldCallable, $newCallable, $probeInputs);

                if (! $harnessResult->executed) {
                    // Honest ceiling: harness failure => skip, never fabricate.
                    $skippedSymbols[] = [
                        'symbol' => $name,
                        'file' => $relativePath,
                        'reason' => 'harness: '.$harnessResult->error,
                    ];
                    continue;
                }

                $anyEvaluated = true;

                // Compare outputs. Divergence => record the FIRST divergent
                // probe input with both outputs as evidence.
                $divergence = $this->findFirstDivergence($harnessResult->oldOutputs, $harnessResult->newOutputs, $probeInputs);
                if ($divergence !== null) {
                    $divergentSymbols[] = [
                        'symbol' => $name,
                        'file' => $relativePath,
                        'input' => $divergence['input'],
                        'oldOutput' => $divergence['oldOutput'],
                        'newOutput' => $divergence['newOutput'],
                    ];
                } else {
                    $evaluatedSymbols[] = [
                        'symbol' => $name,
                        'file' => $relativePath,
                    ];
                }
            }
        }

        // No symbol was even a candidate (e.g. all files had no functions).
        if (! $anyCandidateSeen) {
            return ShadowDiffResult::skipped('no function definitions found in changed PHP files');
        }

        if ($divergentSymbols !== []) {
            return ShadowDiffResult::divergence($divergentSymbols, $evaluatedSymbols, $skippedSymbols);
        }

        if ($anyEvaluated) {
            return ShadowDiffResult::agreement($evaluatedSymbols, $skippedSymbols);
        }

        // Candidates existed but none could be evaluated (all impure / newly-
        // added / harness failures). Report a skip with an aggregated reason.
        $reasons = array_unique(array_map(
            static fn (array $s): string => $s['reason'],
            $skippedSymbols,
        ));

        return ShadowDiffResult::skipped(
            'no symbol could be evaluated: '.implode('; ', $reasons),
        );
    }

    /**
     * Read the OLD (git HEAD) version of a file. Returns null when the file
     * is not tracked at HEAD (newly-added file) or git is unavailable.
     */
    private function readOldSource(string $workspace, string $relativePath): ?string
    {
        if (! is_dir($workspace)) {
            return null;
        }
        $process = new Process(
            ['git', 'show', 'HEAD:'.$relativePath],
            $workspace,
            null,
            null,
            10.0,
        );
        $process->run();
        if (! $process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();

        return $output !== '' ? $output : null;
    }

    /**
     * Read the NEW (workspace, post-patch) version of a file. Returns null
     * when the workspace file is absent or unreadable.
     */
    private function readNewSource(string $workspace, string $relativePath): ?string
    {
        $absolute = rtrim($workspace, '/').'/'.$relativePath;
        if (! is_file($absolute)) {
            return null;
        }
        $contents = @file_get_contents($absolute);

        return $contents !== false ? $contents : null;
    }

    /**
     * Extract the parameter-list fragment from a signature string of the
     * form `function name(params)` (returns the substring inside the parens).
     */
    private function extractParameterList(string $signature): string
    {
        $open = strpos($signature, '(');
        $close = strrpos($signature, ')');
        if ($open === false || $close === false || $close <= $open) {
            return '';
        }

        return substr($signature, $open + 1, $close - $open - 1);
    }

    /**
     * Wrap an extracted body back into a self-contained callable source
     * fragment defining a named function. The harness receives this so it
     * can define and invoke the function in isolation.
     *
     * The wrapper re-uses the original signature (params + return type) so
     * the function's type contract is preserved, and emits the body between
     * braces. The function is named $functionName so old and new can coexist
     * in the same execution scope.
     */
    private function wrapAsCallable(string $functionName, string $originalSignature, string $body): string
    {
        // Rebuild: function <name>(<params>)[: <return>] { <body> }
        $params = '';
        $returnType = '';
        if (preg_match('/^function\s+\w+\((.*?)\)(?:\s*:\s*(.+))?$/s', $originalSignature, $m)) {
            $params = $m[1];
            $returnType = isset($m[2]) ? trim($m[2]) : '';
        }
        $header = 'function '.$functionName.'('.$params.')';
        if ($returnType !== '') {
            $header .= ': '.$returnType;
        }

        return $header."\n{\n".$body."\n}";
    }

    /**
     * Find the first index where old and new serialized outputs differ.
     * Returns null when they all agree. The returned record carries the
     * serialized probe input (for evidence) and both outputs.
     *
     * @param  list<string>  $oldOutputs
     * @param  list<string>  $newOutputs
     * @param  list<list<mixed>>  $probeInputs
     * @return ?array{input: string, oldOutput: string, newOutput: string}
     */
    private function findFirstDivergence(array $oldOutputs, array $newOutputs, array $probeInputs): ?array
    {
        $count = min(count($oldOutputs), count($newOutputs));
        for ($i = 0; $i < $count; $i++) {
            if ($oldOutputs[$i] !== $newOutputs[$i]) {
                return [
                    'input' => $this->serializeInput($probeInputs[$i] ?? []),
                    'oldOutput' => $oldOutputs[$i],
                    'newOutput' => $newOutputs[$i],
                ];
            }
        }

        return null;
    }

    /**
     * Serialize a probe input tuple to a stable string for evidence.
     *
     * @param  list<mixed>  $input
     */
    private function serializeInput(array $input): string
    {
        $parts = array_map(
            static function ($v): string {
                return is_scalar($v) || $v === null
                    ? var_export($v, true)
                    : gettype($v);
            },
            $input,
        );

        return '['.implode(', ', $parts).']';
    }
}
