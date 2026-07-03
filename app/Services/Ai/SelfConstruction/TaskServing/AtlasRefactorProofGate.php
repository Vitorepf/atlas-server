<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Resolves the before/after content of a task's allowed_files at report time
 * (before = git HEAD, i.e. the tree the worker STARTED from on shared main;
 * after = the worker's in-tree delivery) and delegates the verdict to the pure
 * {@see AtlasRefactorDeltaProver}. The serving report path consults it for
 * refactor/optimize objectives under the policy plane's refactor_proof_mode.
 *
 * Fail-open by contract: any resolution error returns null and the report
 * proceeds exactly as before this gate existed — infra can never wedge a
 * worker over a proof it cannot compute.
 */
final class AtlasRefactorProofGate
{
    public function __construct(
        private readonly ?string $repoRootOverride = null,
        private readonly ?AtlasRefactorDeltaProver $prover = null,
    ) {}

    /**
     * A task packet is a refactor/optimize delivery when its objective says so.
     * Packets carry no `kind` field; the objective is the packet's one honest
     * intent signal (PT-BR + EN vocabulary, same doctrine as the intent kernel).
     */
    public static function appliesTo(string $objective): bool
    {
        return preg_match(
            '/\b(refactor\w*|refator\w*|simplif\w*|otimiz\w*|optimi[sz]\w*|deduplic\w*|desduplic\w*|consolid\w*|extra(ia|ct)\w*|enxug\w*)/iu',
            $objective,
        ) === 1;
    }

    /**
     * @param  list<string>  $allowedFiles  repo-relative paths (the packet's server-truth scope)
     * @return array<string,mixed>|null the prover's proof, or null when it cannot be computed
     */
    public function prove(array $allowedFiles): ?array
    {
        try {
            $repo = $this->repoRootOverride ?? base_path();
            $before = [];
            $after = [];
            foreach ($allowedFiles as $path) {
                $path = trim((string) $path);
                if ($path === '' || ! str_ends_with($path, '.php')) {
                    continue; // metric heuristics are PHP-shaped; non-PHP scope files are out of proof scope
                }
                // The delta judges PRODUCTION code. A proof stage that AUTHORS
                // tests grows the tree on purpose — new tests are a gain, never
                // a "no measurable improvement" fake.
                if (str_starts_with($path, 'tests/') || str_contains($path, '/tests/') || str_ends_with($path, 'Test.php')) {
                    continue;
                }
                $show = new Process(['git', 'show', 'HEAD:'.$path], $repo, null, null, 20.0);
                $show->run();
                if ($show->isSuccessful()) {
                    $before[$path] = $show->getOutput();
                }
                if (is_file($repo.'/'.$path)) {
                    $after[$path] = (string) file_get_contents($repo.'/'.$path);
                }
            }
            if ($before === [] && $after === []) {
                return null; // nothing provable in scope (e.g. non-PHP task)
            }

            return ($this->prover ?? new AtlasRefactorDeltaProver)->prove($before, $after);
        } catch (Throwable) {
            return null;
        }
    }
}
