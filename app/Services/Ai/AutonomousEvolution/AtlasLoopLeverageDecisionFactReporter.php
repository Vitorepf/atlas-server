<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;
use Throwable;

/**
 * DECIDIR-ALAVANCAGEM (phase 3 of the canonical 8-phase live cycle) materialized as a deterministic FACT
 * envelope: WHAT the leverage selector picked and the grounded symbols it rests on — never a score or a
 * 'leverage_rank' scalar (the no-score contract is inherited from AtlasLoopLeverageSelector).
 *
 * WRITER ≠ JUDGE: the writer is the model pick already made inside AtlasLoopLeverageSelector; this reporter's
 * JUDGE re-validates the picked candidate's cited_symbols against the comprehension inventory via
 * {@see AtlasLoopComprehensionGroundingGate::groundAgainstInventory} and FAIL-CLOSES on ANY refuted citation
 * (stricter than the gate's anchored-majority: a decision fact must rest on a FULLY grounded citation set, or
 * nothing is recorded). Flag atlas.loop.leverage_decision_reporter_enabled default OFF ⇒ null no-op, no write.
 * When ON it appends ONE line (idempotent by content) to the decisions ndjson the LearningAppendService reads.
 */
final class AtlasLoopLeverageDecisionFactReporter
{
    public const SCHEMA = 'atlas.loop.leverage_decision.v1';

    private readonly string $decisionsPath;

    public function __construct(
        private readonly ?AtlasLoopComprehensionGroundingGate $gate = null,
        ?string $decisionsPath = null,
    ) {
        $this->decisionsPath = $decisionsPath ?? storage_path('atlas-loop/decisions/leverage-decisions.ndjson');
    }

    /**
     * @param  list<array<string,mixed>>  $candidates  the AtlasLoopLeverageSelector::rank() candidate list
     * @return array<string,mixed>|null  the decision fact envelope, a refusal, or null (flag OFF)
     */
    public function report(array $candidates, int $pickedIndex, AtlasLoopScopeComprehensionModel $model): ?array
    {
        if (! (bool) config('atlas.loop.leverage_decision_reporter_enabled', false)) {
            return null; // flag OFF ⇒ byte-identical no-op, writes nothing
        }

        $picked = $candidates[$pickedIndex] ?? null;
        if (! is_array($picked)) {
            return ['reported' => false, 'refuted' => [], 'reason' => 'invalid_pick'];
        }

        $objective = trim((string) ($picked['objective'] ?? $picked['summary'] ?? ''));
        $citedSymbols = array_values(array_filter((array) ($picked['cited_symbols'] ?? []), 'is_string'));

        // JUDGE re-validation against the brain's own inventory. ANY refuted citation ⇒ fail-closed.
        $grounding = ($this->gate ?? new AtlasLoopComprehensionGroundingGate)
            ->groundAgainstInventory($objective, $citedSymbols, $model->inventory);
        $refuted = array_values((array) ($grounding['refuted'] ?? []));
        if ($refuted !== []) {
            return ['reported' => false, 'refuted' => $refuted, 'reason' => 'ungrounded_citation']; // writes NOTHING
        }

        $sortedCited = $citedSymbols;
        sort($sortedCited, SORT_STRING);

        $envelope = [
            'schema_version' => self::SCHEMA,
            'reported' => true,
            'selected_index' => $pickedIndex,
            'selected_objective' => $objective,
            'cited_symbols' => $sortedCited,
            'candidate_summaries' => array_map(
                static fn ($c): string => is_array($c) ? trim((string) ($c['summary'] ?? ($c['objective'] ?? ''))) : '',
                array_values($candidates),
            ),
            'refusal_reason_or_null' => null,
        ];
        ksort($envelope);

        $this->appendIdempotent($envelope);

        return $envelope;
    }

    /**
     * Append ONE ndjson line, idempotent by content: if an identical envelope line already exists, skip.
     * Best-effort + fail-closed — a logging failure never breaks the caller (the envelope is still returned).
     *
     * @param  array<string,mixed>  $envelope
     */
    private function appendIdempotent(array $envelope): void
    {
        $line = (string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $dir = dirname($this->decisionsPath);
            if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
                return;
            }
            if (is_file($this->decisionsPath)) {
                foreach (preg_split('/\R/', (string) @file_get_contents($this->decisionsPath)) ?: [] as $existing) {
                    if (trim($existing) === $line) {
                        return; // already recorded — idempotent
                    }
                }
            }
            @file_put_contents($this->decisionsPath, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // decision-fact log is best-effort; the returned envelope is the source of truth
        }
    }
}
