<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

/**
 * Turns a large, multi-layer slice into an ordered tree of atomic FIRST steps.
 *
 * A "large slice" is one that, scored as a single unit, would trip
 * {@see RiskLevelScorer} above R3 because it touches >=3 real layers or >=6
 * files. This decomposer never asks a model. It splits the slice along the
 * canonical progression — contract -> skeleton -> one behavior -> wiring ->
 * test — emitting one step per cycle. Each step carries a pre-resolved
 * allowed_files scope (single real layer, optional tests/docs, <=5 files),
 * validation and acceptance, so that re-scoring the step keeps it <= R3.
 *
 * Files-per-step is capped strictly below RiskLevelScorer's R4 thresholds
 * (>=6 files OR >=3 real layers), so any emitted step is guaranteed <= R3.
 */
final class AtomicSemanticDecomposer
{
    /**
     * Hard cap on files per emitted step. RiskLevelScorer raises to R4 at >=6
     * explicit allowed_files, so 5 is the safe ceiling. We split larger layer
     * groups across multiple steps.
     */
    public const MAX_FILES_PER_STEP = 5;

    /**
     * Real layer buckets, mirrored from RiskLevelScorer. A step is restricted
     * to ONE of these (plus optional tests/docs) to stay single-layer.
     *
     * @var array<string, list<string>>
     */
    private const LAYER_BUCKETS = [
        'db' => ['app/models/', 'database/migrations/', '/migrations/'],
        'api' => ['app/http/controllers/', 'routes/', '/controllers/'],
        'ui' => ['atlas-desktop/', 'atlas-app/', 'resources/views/', 'resources/js/'],
        'service' => ['app/services/'],
    ];

    /**
     * Ordered layer progression for the contract -> ... -> wiring sequence.
     * Contracts/skeletons live in services first, then db, then api, then ui
     * for wiring. Tests are emitted last as their own step.
     *
     * @var list<string>
     */
    private const LAYER_ORDER = ['service', 'db', 'api', 'ui'];

    /**
     * Decompose a slice into ordered atomic steps.
     *
     * @param  string  $sliceIntent  the large slice's natural-language intent
     * @param  list<string>  $candidateFiles  workspace-relative implementation files
     *                                         the slice would touch (non-test)
     * @param  list<string>  $testFiles  workspace-relative test files for the slice
     * @return list<AtomicStep>
     */
    public function decompose(string $sliceIntent, array $candidateFiles, array $testFiles = []): array
    {
        $intent = trim($sliceIntent);
        $byLayer = $this->groupByLayer($candidateFiles);

        $steps = [];
        $order = 0;

        foreach (self::LAYER_ORDER as $layer) {
            $files = $byLayer[$layer] ?? [];
            if ($files === []) {
                continue;
            }

            foreach (array_chunk($files, self::MAX_FILES_PER_STEP) as $chunkIndex => $chunk) {
                $isFirstChunkOfLayer = $chunkIndex === 0;
                $kind = $this->kindForLayer($layer, $steps === []);
                $label = $this->layerLabel($layer);

                $steps[] = new AtomicStep(
                    order: $order++,
                    kind: $kind,
                    intent: $this->stepIntent($kind, $label, $intent, $isFirstChunkOfLayer),
                    allowedFiles: array_values($chunk),
                    validation: $this->validationFor($chunk),
                    acceptance: $this->acceptanceFor($kind, $label),
                );
            }
        }

        if ($testFiles !== []) {
            foreach (array_chunk($this->dedupe($testFiles), self::MAX_FILES_PER_STEP) as $chunk) {
                $steps[] = new AtomicStep(
                    order: $order++,
                    kind: AtomicStep::KIND_TEST,
                    intent: 'Add a focused test proving the behavior of this slice: '.$intent,
                    allowedFiles: array_values($chunk),
                    validation: $this->validationFor($chunk),
                    acceptance: ['New test fails before the behavior step and passes after.'],
                );
            }
        }

        return $steps;
    }

    /**
     * @param  list<string>  $candidateFiles
     * @return array<string, list<string>>
     */
    private function groupByLayer(array $candidateFiles): array
    {
        $grouped = [];
        foreach ($this->dedupe($candidateFiles) as $path) {
            $layer = $this->layerOf($path);
            if ($layer === null) {
                // Unbucketed implementation files attach to the service layer:
                // they are still single-layer for risk purposes (no real bucket
                // match means they cannot inflate the layer count).
                $layer = 'service';
            }
            $grouped[$layer][] = $path;
        }

        return $grouped;
    }

    private function layerOf(string $path): ?string
    {
        $needle = strtolower($path);
        foreach (self::LAYER_BUCKETS as $layer => $needles) {
            foreach ($needles as $fragment) {
                if (str_contains($needle, $fragment)) {
                    return $layer;
                }
            }
        }

        return null;
    }

    private function kindForLayer(string $layer, bool $isFirstStepOverall): string
    {
        if ($isFirstStepOverall) {
            return AtomicStep::KIND_CONTRACT;
        }

        return match ($layer) {
            'service' => AtomicStep::KIND_BEHAVIOR,
            'db' => AtomicStep::KIND_SKELETON,
            'api', 'ui' => AtomicStep::KIND_WIRING,
            default => AtomicStep::KIND_BEHAVIOR,
        };
    }

    private function layerLabel(string $layer): string
    {
        return match ($layer) {
            'db' => 'data layer',
            'api' => 'API layer',
            'ui' => 'UI layer',
            'service' => 'service layer',
            default => $layer,
        };
    }

    private function stepIntent(string $kind, string $label, string $sliceIntent, bool $firstOfLayer): string
    {
        return match ($kind) {
            AtomicStep::KIND_CONTRACT => 'Define the contract/interface in the '.$label.' for: '.$sliceIntent,
            AtomicStep::KIND_SKELETON => 'Add the '.$label.' skeleton (types/migrations only, no behavior) for: '.$sliceIntent,
            AtomicStep::KIND_BEHAVIOR => 'Implement one behavior in the '.$label.' for: '.$sliceIntent,
            AtomicStep::KIND_WIRING => 'Wire the '.$label.' to the implemented behavior for: '.$sliceIntent,
            default => $sliceIntent,
        };
    }

    /**
     * @param  list<string>  $chunk
     * @return list<string>
     */
    private function validationFor(array $chunk): array
    {
        $validation = ['php -l for each changed PHP file'];
        $hasTests = false;
        foreach ($chunk as $path) {
            if (str_contains(strtolower($path), 'tests/')) {
                $hasTests = true;
                break;
            }
        }
        if ($hasTests) {
            $validation[] = 'php artisan test for the touched test files';
        } else {
            $validation[] = 'php artisan test --filter for the touched area';
        }

        return $validation;
    }

    /**
     * @return list<string>
     */
    private function acceptanceFor(string $kind, string $label): array
    {
        return [
            'Step touches only the '.$label.' (single real layer).',
            ucfirst($kind).' is self-contained and compiles in isolation.',
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function dedupe(array $paths): array
    {
        $clean = [];
        foreach ($paths as $path) {
            $trimmed = trim($path);
            if ($trimmed !== '') {
                $clean[] = $trimmed;
            }
        }

        return array_values(array_unique($clean));
    }
}
