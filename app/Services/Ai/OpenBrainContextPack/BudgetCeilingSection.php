<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Verbatim budgetceiling family extracted from the AOBG context-pack
 * façade; behavior-preserving (private helpers -> Support; public API stays on the façade).
 */
final class BudgetCeilingSection
{
    public function __construct(
        private readonly Support $support,
    ) {}

    /**
     * Enforce the total char ceiling on the MEASURED, assembled pack (not just the
     * reported metadata). Trims trailing (lowest-ranked) entries from whichever section
     * currently contributes the largest char footprint, until the summed section chars
     * fit $totalBudget. Each non-empty section retains at least its top hit (never
     * starves). When $totalBudget <= 0 (uncapped), returns the sections
     * unchanged. The trimmed sections' `chars`, `present`, and item lists are kept
     * consistent so `budget.estimated_chars` and `counts` reflect the real output.
     *
     * @param  array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $code
     * @param  array{present:bool, paths:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $reality
     * @param  array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $memory
     * @return array{0:array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}, 1:array{present:bool, paths:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}, 2:array{present:bool, items:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}}
     */
    public function enforceTotalCeiling(int $totalBudget, array $code, array $reality, array $memory): array
    {
        if ($totalBudget <= 0) {
            return [$code, $reality, $memory];
        }

        $trimmed = ['code' => 0, 'reality' => 0, 'memory' => 0];
        while (((int) $code['chars'] + (int) $reality['chars'] + (int) $memory['chars']) > $totalBudget) {
            $candidates = [];
            if (count($code['items']) > 1) {
                $candidates['code'] = (int) $code['chars'];
            }
            if (count($reality['paths']) > 1) {
                $candidates['reality'] = (int) $reality['chars'];
            }
            if (count($memory['items']) > 1) {
                $candidates['memory'] = (int) $memory['chars'];
            }
            if ($candidates === []) {
                break;
            }

            arsort($candidates);
            $section = (string) array_key_first($candidates);
            if ($section === 'code') {
                array_pop($code['items']);
                $trimmed['code']++;
                $code['items'] = array_values($code['items']);
                $code['chars'] = $this->support->codeItemsChars($code['items']);

                continue;
            }
            if ($section === 'reality') {
                array_pop($reality['paths']);
                $trimmed['reality']++;
                $reality['paths'] = array_values($reality['paths']);
                $reality['chars'] = $this->support->realityPathsChars($reality['paths']);

                continue;
            }

            array_pop($memory['items']);
            $trimmed['memory']++;
            $memory['items'] = array_values($memory['items']);
            $memory['chars'] = $this->support->memoryItemsChars($memory['items']);
        }

        $code['present'] = $code['items'] !== [];
        $reality['present'] = $reality['paths'] !== [];
        $memory['present'] = $memory['items'] !== [];
        if ($trimmed['code'] > 0) {
            $code['provenance']['total_ceiling_trimmed_count'] = $trimmed['code'];
        }
        if ($trimmed['reality'] > 0) {
            $reality['provenance']['total_ceiling_trimmed_count'] = $trimmed['reality'];
        }
        if ($trimmed['memory'] > 0) {
            $memory['provenance']['total_ceiling_trimmed_count'] = $trimmed['memory'];
        }

        return [$code, $reality, $memory];
    }

    public function scaledBudget(int $budget, float $multiplier): int
    {
        if ($budget <= 0) {
            return $budget;
        }

        return max(1, min($budget, (int) floor($budget * $multiplier)));
    }

    /**
     * @param  array{present:bool, paths:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $reality
     * @param  array<string,mixed>  $sourceSelectionPolicy
     * @return array{present:bool, paths:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    public function applyRealitySourceSelection(array $reality, array $sourceSelectionPolicy): array
    {
        if (! (bool) ($sourceSelectionPolicy['applied_to_initial_pack'] ?? false)) {
            return $reality;
        }

        $multiplier = $this->support->floatMapValue((array) ($sourceSelectionPolicy['budget_multipliers'] ?? []), 'graph', 1.0);
        $paths = (array) ($reality['paths'] ?? []);
        if ($multiplier >= 1.0 || count($paths) <= 1) {
            return $reality;
        }

        $originalCount = count($paths);
        $limit = max(1, (int) floor($originalCount * $multiplier));
        if ($limit >= $originalCount) {
            return $reality;
        }

        $reality['paths'] = array_slice($paths, 0, $limit);
        $reality['chars'] = $this->support->realityPathsChars($reality['paths']);
        $reality['present'] = $reality['paths'] !== [];
        $reality['provenance'] = array_merge((array) ($reality['provenance'] ?? []), [
            'source_selection_applied' => 'graph',
            'source_selection_multiplier' => $multiplier,
            'source_selection_original_count' => $originalCount,
        ]);

        return $reality;
    }
}
