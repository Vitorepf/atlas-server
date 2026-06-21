<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;

/**
 * NET-NEW MATERIAL SUPPLY — complex-target DECOMPOSITION (the in-scope-depth lever).
 *
 * The loop mints ONE refactor objective per complex file. But a file at cyclomatic 40 typically holds
 * SEVERAL independently-complex methods, each its own legitimate material refactor — so single-objective
 * discovery under-mines the depth the operator insists is there. This decomposer consumes the per-method
 * complexity census {@see AtlasLoopSignalAnalyzer::fileComplexity()} ALREADY computes (but which, per its own
 * docblock, "nothing consumes yet") and emits ONE independent sub-refactor objective per method that clears
 * the loop's OWN material bar (config `atlas.loop.material_refactor_min_cyclomatic`, default 12).
 *
 * The anti-proxy invariant is structural, not trusted: a sub-target is minted ONLY when the method's
 * cyclomatic complexity is >= the IDENTICAL threshold the classifier uses to call a refactor "material", so
 * decomposition can NEVER manufacture a proxy/cosmetic sub-task. It multiplies SUPPLY; it never lowers the
 * bar — each sub-objective still earns its own failing-test-first (RED) proof and the normal frozen-judge
 * cert downstream. Worst-method-first (drive the biggest decision-count down first), capped to avoid
 * flooding a refill, fail-closed (unparseable / nothing qualifying => empty, never a sub-target below bar).
 *
 * Pure + deterministic. Default-OFF + UNWIRED by design: the refiller lane that enqueues these sub-targets
 * is the next slice; this slice delivers the honest, tested capability so the wiring can be proven in
 * isolation. Like the P27 research originator, the contract is fixed HERE before anything goes live.
 */
final class AtlasLoopComplexTargetDecomposer
{
    public function __construct(
        private readonly ?AtlasLoopSignalAnalyzer $analyzer = null,
    ) {
    }

    /**
     * Decompose ONE file's source into independent material sub-refactor objectives — one per method whose
     * cyclomatic complexity clears the loop's material bar, worst first, capped. Empty when the source is
     * unparseable or nothing qualifies (fail-closed: a sub-target below the material bar is never invented).
     *
     * @return list<array{target_path:string, method:string, cyclomatic:int, shape:string, material:bool,
     *                     objective:string, acceptance:array{red_required:bool, worst_method:string}}>
     */
    public function decompose(string $relativePath, string $source, ?int $max = null): array
    {
        $relativePath = ltrim(trim($relativePath), '/');
        if ($relativePath === '' || trim($source) === '') {
            return [];
        }

        $analyzer = $this->analyzer ?? new AtlasLoopSignalAnalyzer;
        $signal = $analyzer->fileComplexity($source);
        if (($signal['measured'] ?? false) !== true) {
            return [];
        }

        $materialMin = max(1, (int) config('atlas.loop.material_refactor_min_cyclomatic', 12));
        $cap = max(1, $max ?? (int) config('atlas.loop.decompose_max_subtargets', 8));

        /** @var array<string,int> $perMethod */
        $perMethod = is_array($signal['per_method'] ?? null) ? $signal['per_method'] : [];

        // Keep ONLY methods at/above the loop's own material bar — material-by-construction, never proxy.
        $qualifying = [];
        foreach ($perMethod as $method => $cyclomatic) {
            if ((int) $cyclomatic >= $materialMin) {
                $qualifying[(string) $method] = (int) $cyclomatic;
            }
        }
        if ($qualifying === []) {
            return [];
        }
        arsort($qualifying); // worst (highest cyclomatic) first; ties keep a stable, deterministic order.

        $out = [];
        foreach ($qualifying as $method => $cyclomatic) {
            if (count($out) >= $cap) {
                break;
            }
            $out[] = [
                'target_path' => $relativePath,
                'method' => $method,
                'cyclomatic' => $cyclomatic,
                'shape' => 'decompose_subrefactor',
                'material' => true, // cyclomatic >= material bar, by construction
                'objective' => "Refactor {$method} in {$relativePath} to reduce its cyclomatic complexity "
                    ."(currently {$cyclomatic}) while preserving behaviour EXACTLY. Pin the unchanged behaviour "
                    ."with a failing-test-first (RED) anchor and let the frozen judge certify the strict "
                    ."per-method AST complexity drop. This is ONE independent sub-refactor of a multi-method "
                    ."complex file — touch only {$method}, not the rest of the file.",
                'acceptance' => [
                    'red_required' => true,
                    'worst_method' => $method,
                ],
            ];
        }

        return $out;
    }
}
