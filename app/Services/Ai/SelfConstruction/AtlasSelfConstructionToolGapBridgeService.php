<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\AutonomousEvolution\AtlasLoopLossObserverService;
use Illuminate\Support\Carbon;

/**
 * L5-4 · Self-construction of tools — the recurrent-capability-gap bridge.
 *
 * THE keystone the item asks for: "Quando o loss-observer detectar um gap
 * RECORRENTE de capacidade (ex: falta um fixture builder, um linter de contrato),
 * o Atlas constrói a própria ferramenta — obra gated, parked para revisão, nunca
 * silencioso."
 *
 * Mechanism (no parallel runtime — reuses the existing spine):
 *   1. Read the Loop loss-observer's dominant loss patterns (evidence-only).
 *   2. Classify which patterns signal a MISSING TOOL / CAPABILITY (a fixture
 *      builder, a contract linter, a worktree/framework-gate helper, …) rather
 *      than an ordinary code bug in an existing file. The classifier is
 *      deterministic and evidence-grounded: a recurrent loss qualifies only when
 *      its reason family matches a known capability-gap signal AND it recurs at
 *      or above the threshold.
 *   3. Route each qualifying gap into the canonical self-construction corridor
 *      ({@see AtlasSelfConstructionSubsystemBuilderService::propose}) as a
 *      `recurrent_capability` gap. The proposal is ALWAYS parked
 *      (`requires_human_approval=true`), never silent, never auto-applied, never
 *      promoted, never merged. Promotion stays the separate operator-gated G4.
 *
 * Hard invariants:
 *   - default-safe: dry-run unless explicitly told to write;
 *   - provider-safe: NO provider call, NO workspace mutation, NO merge;
 *   - idempotent: the builder's deterministic proposal hash means re-running the
 *     same gap re-emits the same proposal id/hash (no duplicate cunhagem);
 *   - flag-gated: the recurring-bridge is governed by config + an explicit flag.
 */
final class AtlasSelfConstructionToolGapBridgeService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.tool_gap_bridge.v1';

    /**
     * Reason-family signals that mean "a CAPABILITY/TOOL is missing", not a bug
     * in an existing target file. Matched case-insensitively as substrings of the
     * normalized loss reason. Deliberately conservative: only failures that point
     * at missing scaffolding/tooling, never generic verification failures.
     *
     * @var list<string>
     */
    private const CAPABILITY_GAP_SIGNALS = [
        'fixture',           // missing/failed fixture builder
        'builder_missing',
        'builder_failed',
        'linter',            // missing/failed contract linter
        'lint_contract',
        'contract_gate_missing',
        'scaffold_missing',
        'worktree_add_failed',   // framework gate could not stage a worktree → missing helper tool
        'framework_gate_worktree',
        'tooling_missing',
        'tool_missing',
        'missing_tool',
        'no_such_command',
        'command_not_found',
        'generator_missing',
        'harness_missing',
    ];

    public function __construct(
        private readonly AtlasLoopLossObserverService $lossObserver,
        private readonly AtlasSelfConstructionSubsystemBuilderService $builder,
    ) {}

    /**
     * Detect recurrent capability gaps from the loss-observer and route the
     * qualifying ones into the self-construction corridor.
     *
     * @param  array{campaign_id?:string|null,window_hours?:int,min_occurrences?:int,write?:bool,max_proposals?:int}  $options
     * @return array<string,mixed>
     */
    public function detectAndRoute(array $options = []): array
    {
        $cfg = (array) config('atlas.ai.self_construction.tool_gap_bridge', []);
        $enabled = (bool) ($cfg['enabled'] ?? true);

        $campaignId = $options['campaign_id'] ?? null;
        $windowHours = max(1, (int) ($options['window_hours'] ?? $cfg['window_hours'] ?? 24));
        $minOccurrences = max(2, (int) ($options['min_occurrences'] ?? $cfg['min_occurrences'] ?? 3));
        $write = (bool) ($options['write'] ?? false);
        $maxProposals = max(1, (int) ($options['max_proposals'] ?? $cfg['max_proposals'] ?? 5));

        // Observe in dry-run: we only want the dominant patterns, never the
        // loss-observer's own backlog-intent writes here.
        $observed = $this->lossObserver->observe($campaignId, [
            'window_hours' => $windowHours,
            'min_occurrences' => $minOccurrences,
            'write' => false,
        ]);

        $patterns = is_array($observed['dominant_patterns'] ?? null) ? $observed['dominant_patterns'] : [];

        $capabilityGaps = [];
        $skipped = [];
        foreach ($patterns as $pattern) {
            $reason = (string) ($pattern['reason'] ?? '');
            $occurrences = (int) ($pattern['occurrences'] ?? 0);
            $targetPath = trim((string) ($pattern['target_path'] ?? ''));
            $signal = $this->capabilityGapSignal($reason, $targetPath);

            if ($signal === null || $occurrences < $minOccurrences) {
                $skipped[] = [
                    'reason' => $reason,
                    'occurrences' => $occurrences,
                    'classification' => 'code_fix_intent',
                    'why' => $signal === null
                        ? 'no_capability_gap_signal'
                        : 'below_min_occurrences',
                ];

                continue;
            }

            $capabilityGaps[] = [
                'reason' => $reason,
                'reason_family' => (string) ($pattern['reason_family'] ?? ''),
                'occurrences' => $occurrences,
                'observed_target_path' => $targetPath,
                'signal' => $signal,
                'tool_acronym' => $this->toolAcronym($signal, $reason),
                'tool_name' => $this->toolName($signal),
            ];
        }

        // Deterministic order: most recurrent first, then a stable tie-break by
        // acronym so re-runs route the same set when capped.
        usort(
            $capabilityGaps,
            static fn (array $a, array $b): int => [$b['occurrences'], $a['tool_acronym']] <=> [$a['occurrences'], $b['tool_acronym']],
        );

        $proposals = [];
        foreach (array_slice($capabilityGaps, 0, $maxProposals) as $gap) {
            $proposals[] = $this->routeToSelfConstruction($gap, $windowHours, $write && $enabled);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($enabled, $capabilityGaps, $proposals, $write),
            'enabled' => $enabled,
            'window_hours' => $windowHours,
            'min_occurrences' => $minOccurrences,
            'campaign_id' => $campaignId,
            'observed_pattern_count' => count($patterns),
            'capability_gap_count' => count($capabilityGaps),
            'capability_gaps' => $capabilityGaps,
            'skipped_non_capability' => $skipped,
            'proposals' => $proposals,
            'proposal_count' => count($proposals),
            // claim policy — load-bearing anti-over-claim envelope.
            'claim_policy' => [
                'provider_calls_made' => false,
                'workspace_mutated' => false,
                'merged_to_main' => false,
                'auto_approved' => false,
                'auto_promoted' => false,
                'never_silent' => true,
                'requires_human_approval' => true,
            ],
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $gap
     * @return array<string,mixed>
     */
    private function routeToSelfConstruction(array $gap, int $windowHours, bool $persist): array
    {
        $rationale = sprintf(
            'Recurrent capability gap from Loop loss-observer: "%s" recurred %dx in the last %dh with no addressable single-file fix; the Atlas needs a tool/capability (%s) it does not yet have.',
            (string) $gap['reason'],
            (int) $gap['occurrences'],
            $windowHours,
            (string) $gap['tool_name'],
        );

        // The builder always persists the canonical proposal jsonl when invoked.
        // To honour dry-run we ONLY call propose() when persisting; otherwise we
        // build the would-be proposal envelope deterministically without writing.
        if ($persist) {
            $proposal = $this->builder->propose([
                'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_RECURRENT_CAPABILITY,
                'subsystem_acronym' => (string) $gap['tool_acronym'],
                'subsystem_name' => (string) $gap['tool_name'],
                'group' => 'self_construction',
                'rationale' => $rationale,
            ]);
        } else {
            $proposal = $this->builder->buildProposalEnvelope([
                'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_RECURRENT_CAPABILITY,
                'subsystem_acronym' => (string) $gap['tool_acronym'],
                'subsystem_name' => (string) $gap['tool_name'],
                'group' => 'self_construction',
                'rationale' => $rationale,
            ]);
        }

        return [
            'persisted' => $persist,
            'gap_kind' => AtlasSelfConstructionSubsystemBuilderService::GAP_RECURRENT_CAPABILITY,
            'source_reason' => (string) $gap['reason'],
            'occurrences' => (int) $gap['occurrences'],
            'tool_acronym' => (string) $gap['tool_acronym'],
            'tool_name' => (string) $gap['tool_name'],
            'proposal_id' => (string) ($proposal['proposal_id'] ?? ''),
            'proposal_hash' => (string) ($proposal['proposal_hash'] ?? ''),
            'requires_human_approval' => (bool) ($proposal['requires_human_approval'] ?? true),
            'service_class' => (string) ($proposal['proposed_subsystem']['service_class'] ?? ''),
            'doc_path' => (string) ($proposal['proposed_subsystem']['doc_path'] ?? ''),
        ];
    }

    /**
     * Return the matched capability-gap signal, or null if this loss is an
     * ordinary code-fix intent (a bug in an existing file).
     */
    private function capabilityGapSignal(string $reason, string $targetPath): ?string
    {
        $needle = mb_strtolower($reason);
        if ($needle === '') {
            return null;
        }
        foreach (self::CAPABILITY_GAP_SIGNALS as $signal) {
            if (str_contains($needle, $signal)) {
                return $signal;
            }
        }

        return null;
    }

    private function toolAcronym(string $signal, string $reason): string
    {
        // Stable, deterministic acronym derived from the signal family so the
        // proposal hash is stable across re-runs of the same recurrent gap.
        return match (true) {
            str_contains($signal, 'fixture') => 'FIXTUREBUILDER',
            str_contains($signal, 'lint') || str_contains($signal, 'contract') => 'CONTRACTLINTER',
            str_contains($signal, 'worktree') || str_contains($signal, 'framework_gate') => 'WORKTREEHELPER',
            str_contains($signal, 'scaffold') || str_contains($signal, 'generator') => 'SCAFFOLDGEN',
            str_contains($signal, 'harness') => 'HARNESSTOOL',
            default => 'CAPABILITYTOOL',
        };
    }

    private function toolName(string $signal): string
    {
        return match (true) {
            str_contains($signal, 'fixture') => 'Fixture Builder',
            str_contains($signal, 'lint') || str_contains($signal, 'contract') => 'Contract Linter',
            str_contains($signal, 'worktree') || str_contains($signal, 'framework_gate') => 'Worktree Staging Helper',
            str_contains($signal, 'scaffold') || str_contains($signal, 'generator') => 'Scaffold Generator',
            str_contains($signal, 'harness') => 'Harness Tool',
            default => 'Missing Capability Tool',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $capabilityGaps
     * @param  list<array<string,mixed>>  $proposals
     */
    private function status(bool $enabled, array $capabilityGaps, array $proposals, bool $write): string
    {
        if (! $enabled) {
            return 'disabled';
        }
        if ($capabilityGaps === []) {
            return 'no_capability_gap';
        }
        if (! $write) {
            return 'dry_run';
        }

        return $proposals !== [] ? 'proposed_for_review' : 'no_capability_gap';
    }
}
