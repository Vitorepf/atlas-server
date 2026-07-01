<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Maps consumers of a simplification candidate (merge/move/delete) into
 * runtime/test/docs/config/command classes and fails closed whenever a
 * consumer is unclassified or an impacted class has zero proof coverage.
 * Pure, deterministic, no I/O.
 */
final class AtlasSelfConstructionSimplificationConsumerImpactAnalyzer
{
    private const KNOWN_CATEGORIES = ['runtime', 'test', 'docs', 'config', 'command'];

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    /** Risk levels at or above this are refused for a plain refactor. */
    private const SAFE_REFACTOR_RISK_FLOOR = self::RISK_HIGH;

    /** Transitive consumer count strictly above this requires explicit transitive proof coverage. */
    private const TRANSITIVE_CONSUMER_RISK_FLOOR = 2;

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function analyze(array $candidate): array
    {
        $consumers = array_values((array) ($candidate['consumers'] ?? []));

        $byCategory = [];
        $blockers = [];
        $directCount = 0;
        $transitiveCount = 0;
        $transitiveProofRefs = [];
        $touchesPublicCommand = false;
        $touchesPublicContract = false;

        foreach ($consumers as $consumer) {
            $name = (string) ($consumer['name'] ?? '');
            $category = (string) ($consumer['category'] ?? '');

            if ((bool) ($consumer['transitive'] ?? false)) {
                $transitiveCount++;
                $transitiveProofRefs = array_merge($transitiveProofRefs, array_values((array) ($consumer['proof_refs'] ?? [])));
            } else {
                $directCount++;
            }

            if ((bool) ($consumer['is_public_command'] ?? false)) {
                $touchesPublicCommand = true;
            }
            if ((bool) ($consumer['is_public_contract'] ?? false)) {
                $touchesPublicContract = true;
            }

            if (! in_array($category, self::KNOWN_CATEGORIES, true)) {
                $blockers[] = "consumer_unclassified:{$name}";

                continue;
            }

            $byCategory[$category][] = $this->sanitizeConsumer($consumer, $name, $category);
        }

        foreach ($byCategory as $category => $categoryConsumers) {
            $hasProof = false;
            foreach ($categoryConsumers as $consumer) {
                if (array_values((array) ($consumer['proof_refs'] ?? [])) !== []) {
                    $hasProof = true;

                    break;
                }
            }
            if (! $hasProof) {
                $blockers[] = "missing_proof_coverage:{$category}";
            }
        }

        $touchedCategories = array_keys($byCategory);
        $onlyTestOrDocs = $touchedCategories !== [] && array_diff($touchedCategories, ['test', 'docs']) === [];

        $riskLevel = match (true) {
            $touchesPublicCommand || $touchesPublicContract => self::RISK_HIGH,
            $onlyTestOrDocs => self::RISK_LOW,
            array_intersect($touchedCategories, ['runtime', 'command']) !== [] => self::RISK_MEDIUM,
            default => self::RISK_LOW,
        };

        if ($transitiveCount > self::TRANSITIVE_CONSUMER_RISK_FLOOR && $transitiveProofRefs === []) {
            $riskLevel = self::RISK_HIGH;
            $blockers[] = 'transitive_consumer_count_exceeds_floor_without_proof';
        }

        if ($riskLevel === self::SAFE_REFACTOR_RISK_FLOOR) {
            $blockers[] = "consumer_risk_exceeds_safe_refactor_floor:{$riskLevel}";
        }

        $failClosed = $blockers !== [];

        // unsafe_consumers: names directly implicated in a blocker (unclassified, or belonging to
        // an under-proven category) — a second pass since missing_proof_coverage is only known
        // once the full category grouping above has completed.
        $unsafeConsumers = [];
        foreach ($consumers as $consumer) {
            $name = (string) ($consumer['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $category = (string) ($consumer['category'] ?? '');
            if (! in_array($category, self::KNOWN_CATEGORIES, true)) {
                $unsafeConsumers[] = $name;

                continue;
            }
            if (in_array("missing_proof_coverage:{$category}", $blockers, true)
                && array_values((array) ($consumer['proof_refs'] ?? [])) === []
            ) {
                $unsafeConsumers[] = $name;
            }
        }
        $unsafeConsumers = array_values(array_unique($unsafeConsumers));

        // required_parity_checks: one behavior-parity check per touched category, plus explicit
        // checks for public-surface touches and unproven transitive fan-out.
        $requiredParityChecks = array_map(static fn (string $c): string => "{$c}_behavior_parity_check", $touchedCategories);
        if ($touchesPublicCommand) {
            $requiredParityChecks[] = 'public_command_signature_parity_check';
        }
        if ($touchesPublicContract) {
            $requiredParityChecks[] = 'public_contract_signature_parity_check';
        }
        if ($transitiveCount > self::TRANSITIVE_CONSUMER_RISK_FLOOR) {
            $requiredParityChecks[] = 'transitive_consumer_proof_parity_check';
        }
        $requiredParityChecks = array_values(array_unique($requiredParityChecks));

        // migration_notes: deterministic guidance strings derived from state — never free text.
        $migrationNotes = [];
        foreach ($blockers as $blocker) {
            if (str_starts_with($blocker, 'missing_proof_coverage:')) {
                $category = substr($blocker, strlen('missing_proof_coverage:'));
                $migrationNotes[] = "attach at least one proof_ref for the {$category} category before promoting";
            }
            if (str_starts_with($blocker, 'consumer_unclassified:')) {
                $migrationNotes[] = 'classify every consumer into a known category before promoting';
            }
        }
        if ($touchesPublicCommand) {
            $migrationNotes[] = 'update CLI help/usage docs alongside the public command signature change';
        }
        if ($touchesPublicContract) {
            $migrationNotes[] = 'bump the contract version or provide a compatibility shim for public contract consumers';
        }
        $migrationNotes = array_values(array_unique($migrationNotes));

        // rollback_hooks: what to run to undo, scaled to touched surface and risk.
        $rollbackHooks = $touchedCategories === [] ? [] : ['git_revert_last_commit'];
        if ($riskLevel !== self::RISK_LOW) {
            $rollbackHooks[] = 'restore_prior_symbol_from_worktree_snapshot';
        }
        if ($transitiveCount > 0) {
            $rollbackHooks[] = 'reindex_transitive_consumer_call_graph';
        }
        $rollbackHooks = array_values(array_unique($rollbackHooks));

        return [
            'impacted_classes' => $touchedCategories,
            'consumers_by_category' => $byCategory,
            'direct_consumer_count' => $directCount,
            'transitive_consumer_count' => $transitiveCount,
            'touches_public_command' => $touchesPublicCommand,
            'touches_public_contract' => $touchesPublicContract,
            'risk_level' => $riskLevel,
            'blockers' => $blockers,
            'blocking_reasons' => $blockers,
            'fail_closed' => $failClosed,
            'safe_to_continue' => ! $failClosed,
            'blast_radius' => [
                'total_consumers' => $directCount + $transitiveCount,
                'direct_consumer_count' => $directCount,
                'transitive_consumer_count' => $transitiveCount,
                'categories_touched' => count($touchedCategories),
                'touches_public_surface' => $touchesPublicCommand || $touchesPublicContract,
            ],
            'required_parity_checks' => $requiredParityChecks,
            'migration_notes' => $migrationNotes,
            'rollback_hooks' => $rollbackHooks,
            'unsafe_consumers' => $unsafeConsumers,
            'promotable' => ! $failClosed,
        ];
    }

    /**
     * Whitelists the fields echoed back into consumers_by_category — the raw $consumer array is
     * caller-supplied and may carry arbitrary extra keys (e.g. an accidental raw provider prompt or
     * secret); only structural facts this analyzer actually reasons about survive.
     *
     * @param  array<string,mixed>  $consumer
     * @return array{name:string, category:string, proof_refs:list<string>, transitive:bool, is_public_command:bool, is_public_contract:bool}
     */
    private function sanitizeConsumer(array $consumer, string $name, string $category): array
    {
        return [
            'name' => $name,
            'category' => $category,
            'proof_refs' => array_values(array_map('strval', (array) ($consumer['proof_refs'] ?? []))),
            'transitive' => (bool) ($consumer['transitive'] ?? false),
            'is_public_command' => (bool) ($consumer['is_public_command'] ?? false),
            'is_public_contract' => (bool) ($consumer['is_public_contract'] ?? false),
        ];
    }
}
