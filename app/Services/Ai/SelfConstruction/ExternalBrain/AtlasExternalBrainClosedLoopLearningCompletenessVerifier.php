<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure completeness verifier. Checks that every self-construction cycle closes
 * the full chain:
 *
 *   origination_receipt → implementation_result → runnable_evidence
 *   → learning_update → next_batch_constraint
 *
 * A cycle passes only when all five links are present and non-empty.
 * Missing any link emits a named gap in missing_links, blocking the cycle.
 *
 * AC4: output includes complete, missing_links, cycle_receipts, next_repair_task_hint.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainClosedLoopLearningCompletenessVerifier
{
    public const SCHEMA = 'atlas.external_brain.closed_loop_learning_completeness_verifier.v1';

    public const LINK_ORIGINATION_RECEIPT   = 'no_origination_receipt';
    public const LINK_IMPLEMENTATION_RESULT = 'no_implementation_result';
    public const LINK_VALUE_EVIDENCE        = 'no_value_evidence';
    public const LINK_LEARNING_RECORD       = 'no_learning_record';
    public const LINK_NEXT_BATCH_CONSTRAINT = 'no_next_batch_constraint';
    // Outcome-type routing links (AC1/AC3)
    public const LINK_LEARNER_ROUTE         = 'no_learner_route';
    public const LINK_POLICY_ROUTE          = 'no_policy_route';
    public const LINK_MAESTRO_ROUTE         = 'no_maestro_route';

    private const REPAIR_HINT_MAP = [
        self::LINK_ORIGINATION_RECEIPT   => 'emit_origination_receipt_for_cycle',
        self::LINK_IMPLEMENTATION_RESULT => 'ensure_implementation_result_is_committed',
        self::LINK_VALUE_EVIDENCE        => 'attach_runnable_evidence_to_cycle',
        self::LINK_LEARNING_RECORD       => 'run_outcome_learner_for_cycle',
        self::LINK_LEARNER_ROUTE         => 'route_success_outcome_to_outcome_learner',
        self::LINK_POLICY_ROUTE          => 'route_give_back_outcome_to_policy_adjuster',
        self::LINK_MAESTRO_ROUTE         => 'route_quarantine_outcome_to_maestro_adjustment',
        self::LINK_NEXT_BATCH_CONSTRAINT => 'derive_next_batch_constraint_from_learning_update',
    ];

    /**
     * @param  array{cycles?: list<array<string,mixed>>}  $input
     * @return array{schema:string, complete:bool, missing_links:list<string>, cycle_receipts:list<array<string,mixed>>, next_repair_task_hint:string|null}
     */
    public function verify(array $input): array
    {
        $cycles = (array) ($input['cycles'] ?? []);

        $allMissingLinks = [];
        $cycleReceipts   = [];
        $allComplete     = true;

        foreach ($cycles as $idx => $cycle) {
            $cycleId = (string) ($cycle['cycle_id'] ?? "cycle_{$idx}");
            $missing = $this->missingLinks($cycle);

            $cycleReceipts[] = [
                'cycle_id'       => $cycleId,
                'complete'       => $missing === [],
                'missing_links'  => $missing,
                'decision_chain' => $this->buildDecisionChain($cycle),
            ];

            if ($missing !== []) {
                $allComplete = false;
                foreach ($missing as $link) {
                    if (! in_array($link, $allMissingLinks, true)) {
                        $allMissingLinks[] = $link;
                    }
                }
            }
        }

        $hint = $allComplete ? null : $this->topRepairHint($allMissingLinks);

        return [
            'schema'                  => self::SCHEMA,
            'complete'                => $allComplete,
            'missing_links'           => $allMissingLinks,
            'cycle_receipts'          => $cycleReceipts,
            'next_repair_task_hint'   => $hint,
        ];
    }

    /** @return list<string> */
    private function missingLinks(array $cycle): array
    {
        $missing = [];

        if (! $this->hasOrigination($cycle)) {
            $missing[] = self::LINK_ORIGINATION_RECEIPT;
        }

        if (! $this->hasImplementation($cycle)) {
            $missing[] = self::LINK_IMPLEMENTATION_RESULT;
        }

        if (! $this->hasEvidence($cycle)) {
            $missing[] = self::LINK_VALUE_EVIDENCE;
        }

        if (! $this->hasLearning($cycle)) {
            $missing[] = self::LINK_LEARNING_RECORD;
        }

        if (! $this->hasNextConstraint($cycle)) {
            $missing[] = self::LINK_NEXT_BATCH_CONSTRAINT;
        }

        // AC1: outcome-type routing checks — only when outcome_type is declared.
        $outcomeType = strtolower(trim((string) ($cycle['outcome_type'] ?? '')));
        if ($outcomeType !== '') {
            $routeLink = $this->checkOutcomeRoute($cycle, $outcomeType);
            if ($routeLink !== null) {
                $missing[] = $routeLink;
            }
        }

        return $missing;
    }

    private function checkOutcomeRoute(array $cycle, string $outcomeType): ?string
    {
        $learning   = is_array($cycle['learning_update']       ?? null) ? $cycle['learning_update']       : [];
        $constraint = is_array($cycle['next_batch_constraint'] ?? null) ? $cycle['next_batch_constraint'] : [];

        return match ($outcomeType) {
            'success'    => (($learning['routed_to_learner'] ?? false) || array_key_exists('learner_adjustment', $constraint))
                             ? null : self::LINK_LEARNER_ROUTE,
            'give_back'  => (($learning['routed_to_policy'] ?? false) || array_key_exists('policy_change', $constraint))
                             ? null : self::LINK_POLICY_ROUTE,
            'quarantine' => (($learning['routed_to_maestro'] ?? false) || array_key_exists('maestro_adjustment', $constraint))
                             ? null : self::LINK_MAESTRO_ROUTE,
            default      => null,
        };
    }

    private function buildDecisionChain(array $cycle): array
    {
        $learning   = is_array($cycle['learning_update']       ?? null) ? $cycle['learning_update']       : [];
        $constraint = is_array($cycle['next_batch_constraint'] ?? null) ? $cycle['next_batch_constraint'] : [];

        return [
            'outcome_type'            => (string) ($cycle['outcome_type'] ?? ''),
            'learning_pattern_family' => (string) ($learning['pattern_family'] ?? ''),
            'constraint_influences'   => array_values(array_intersect(
                array_keys($constraint),
                self::CONSTRAINT_INFLUENCE_KEYS,
            )),
        ];
    }

    private function hasOrigination(array $cycle): bool
    {
        $r = $cycle['origination_receipt'] ?? null;
        return is_array($r) && ! empty($r['task_packet_id']);
    }

    private function hasImplementation(array $cycle): bool
    {
        $r = $cycle['implementation_result'] ?? null;
        return is_array($r) && ! empty($r['commit_sha']);
    }

    private function hasEvidence(array $cycle): bool
    {
        $r = $cycle['runnable_evidence'] ?? null;
        return is_array($r) && ! empty($r['command']) && ! empty($r['outcome']);
    }

    private function hasLearning(array $cycle): bool
    {
        $r = $cycle['learning_update'] ?? null;
        return is_array($r) && isset($r['pattern_family']) && $r['pattern_family'] !== '';
    }

    /** At least one of these keys must be present to prove learning influenced origination. */
    private const CONSTRAINT_INFLUENCE_KEYS = [
        'promoted_rule', 'blocked_family', 'threshold_change', 'routing_hint', 'retired_pattern',
        'learner_adjustment', 'policy_change', 'maestro_adjustment',
    ];

    private function hasNextConstraint(array $cycle): bool
    {
        $r = $cycle['next_batch_constraint'] ?? null;
        if (! is_array($r) || $r === []) {
            return false;
        }
        foreach (self::CONSTRAINT_INFLUENCE_KEYS as $key) {
            if (array_key_exists($key, $r)) {
                return true;
            }
        }
        return false;
    }

    private function topRepairHint(array $missingLinks): ?string
    {
        // Priority order matches the chain from left to right
        $priority = [
            self::LINK_ORIGINATION_RECEIPT,
            self::LINK_IMPLEMENTATION_RESULT,
            self::LINK_VALUE_EVIDENCE,
            self::LINK_LEARNING_RECORD,
            self::LINK_LEARNER_ROUTE,
            self::LINK_POLICY_ROUTE,
            self::LINK_MAESTRO_ROUTE,
            self::LINK_NEXT_BATCH_CONSTRAINT,
        ];

        foreach ($priority as $link) {
            if (in_array($link, $missingLinks, true)) {
                return self::REPAIR_HINT_MAP[$link] ?? $link;
            }
        }

        return null;
    }
}
