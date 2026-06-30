<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Converts opportunity clusters into explicit Atlas evolution theses.
 *
 * A thesis must declare:
 *   1. capability_delta   — the concrete Atlas capability that will exist after the work, stated
 *                           as a measurable before→after claim.
 *   2. acceptance_path    — a falsifiable statement: what evidence, observable in code or runtime,
 *                           proves the thesis succeeded. A vague "will be better" is NOT accepted.
 *
 * The forge REJECTS a thesis (and records it in `rejected`) when either field is absent or empty,
 * because an un-falsifiable or capability-free thesis is indistinguishable from speculation.
 *
 * INPUT clusters:
 *   list<{
 *     cluster_id:string, theme:string, capability_delta:string, acceptance_path:string,
 *     opportunities?:list<array>, urgency?:string, risk?:string
 *   }>
 *
 * OUTPUT:
 *   { schema, theses:list<Thesis>, rejected:list<{cluster_id,reason}> }
 *
 * Thesis:
 *   { thesis_id, cluster_id, title, why_it_matters, structural_leverage, capability_delta,
 *     acceptance_path, evidence_demand:list<string>, risk:string, task_shapes:list<array> }
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainStrategicThesisForge
{
    public const SCHEMA = 'atlas.external_brain.strategic_thesis_forge.v1';

    public const RISK_LOW    = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH   = 'high';

    private const IMPL_SHAPES   = ['implement_capability', 'implement', 'add_implementation', 'implement_service'];
    private const VERIFY_SHAPES = ['verify_acceptance', 'add_tests', 'verify', 'test_acceptance', 'write_tests'];

    private const PREREQUISITE_SIGNALS = [
        'implement_capability' => ['acceptance_path_defined', 'capability_delta_stated'],
        'verify_acceptance'    => ['implement_capability_done'],
        'wire_to_consumers'    => ['verify_acceptance_done'],
    ];
    private const EXPECTED_LEVERAGE = [
        'implement_capability' => 'high',
        'verify_acceptance'    => 'medium',
        'wire_to_consumers'    => 'high',
    ];
    private const PROOF_REQUIRED = [
        'implement_capability' => 'implementation_file_committed_and_tests_pass',
        'verify_acceptance'    => 'automated_test_or_gate_green',
        'wire_to_consumers'    => 'consumer_exercising_new_capability_in_ci',
    ];

    /**
     * @param  list<array{
     *   cluster_id:string, theme:string, capability_delta:string, acceptance_path:string,
     *   opportunities?:list<array<string,mixed>>, urgency?:string, risk?:string,
     *   dependencies?:list<string>
     * }>  $clusters
     * @return array{schema:string, theses:list<array<string,mixed>>, rejected:list<array<string,string>>}
     */
    public function forge(array $clusters): array
    {
        $theses   = [];
        $rejected = [];
        $seen     = []; // md5(theme|capability_delta) → cluster_id, for duplicate detection

        foreach ($clusters as $cluster) {
            $clusterId       = (string) ($cluster['cluster_id'] ?? '');
            $theme           = trim((string) ($cluster['theme'] ?? ''));
            $capabilityDelta = trim((string) ($cluster['capability_delta'] ?? ''));
            $acceptancePath  = trim((string) ($cluster['acceptance_path'] ?? ''));

            // REJECTION: missing capability_delta.
            if ($capabilityDelta === '') {
                $rejected[] = ['cluster_id' => $clusterId, 'reason' => 'capability_delta_missing_or_empty'];
                continue;
            }

            // REJECTION: missing or non-falsifiable acceptance_path.
            if ($acceptancePath === '') {
                $rejected[] = ['cluster_id' => $clusterId, 'reason' => 'acceptance_path_missing_or_empty'];
                continue;
            }

            // Weak vague phrases that make an acceptance_path non-falsifiable.
            if ($this->isVagueAcceptancePath($acceptancePath)) {
                $rejected[] = ['cluster_id' => $clusterId, 'reason' => 'acceptance_path_not_falsifiable:vague_claim'];
                continue;
            }

            // REJECTION: duplicate cluster (same theme + capability_delta already accepted).
            $dedupeKey = md5(strtolower($theme).'|'.strtolower($capabilityDelta));
            if (isset($seen[$dedupeKey])) {
                $rejected[] = ['cluster_id' => $clusterId, 'reason' => 'duplicate_cluster:same_theme_and_capability_delta'];
                continue;
            }
            $seen[$dedupeKey] = $clusterId;

            $opportunities = is_array($cluster['opportunities'] ?? null) ? $cluster['opportunities'] : [];
            $urgency       = strtolower(trim((string) ($cluster['urgency'] ?? 'medium')));
            $risk          = $this->normaliseRisk((string) ($cluster['risk'] ?? ''), $urgency);
            $dependencies  = is_array($cluster['dependencies'] ?? null)
                ? array_values(array_filter(array_map('strval', $cluster['dependencies'])))
                : [];

            // Use caller-supplied task_shapes if provided; otherwise auto-generate.
            $taskShapes = is_array($cluster['task_shapes'] ?? null)
                ? array_values($cluster['task_shapes'])
                : $this->taskShapes($theme, $capabilityDelta);

            // REJECTION: task_shapes cannot form a coherent implementation-plus-test chain.
            $coherence = $this->checkTaskChainCoherence($taskShapes);
            if (! $coherence['coherent']) {
                $rejected[] = ['cluster_id' => $clusterId, 'reason' => 'task_shapes_incoherent:'.$coherence['reason']];
                continue;
            }

            $chain = $this->buildTaskChain($taskShapes);

            $theses[] = [
                'thesis_id'             => 'thesis:'.$clusterId,
                'cluster_id'            => $clusterId,
                'title'                 => $this->title($theme),
                'why_it_matters'        => $this->whyItMatters($theme, $opportunities),
                'structural_leverage'   => $this->structuralLeverage($theme, $capabilityDelta),
                'capability_delta'      => $capabilityDelta,
                'acceptance_path'       => $acceptancePath,
                'evidence_demand'       => $this->evidenceDemand($acceptancePath, $opportunities),
                'evidence_refs'         => $this->evidenceRefs($opportunities),
                'dependencies'          => $dependencies,
                'expected_compound_lift' => $this->expectedCompoundLift($opportunities, $urgency, $risk),
                'risk'                  => $risk,
                'task_shapes'           => $taskShapes,
                'steps'                 => $chain['steps'],
                'task_chain'            => $chain,
            ];
        }

        return [
            'schema'   => self::SCHEMA,
            'theses'   => $theses,
            'rejected' => $rejected,
        ];
    }

    private function isVagueAcceptancePath(string $path): bool
    {
        $vague = ['will be better', 'improve things', 'should help', 'might work', 'could work'];
        $lower = strtolower($path);
        foreach ($vague as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function normaliseRisk(string $risk, string $urgency): string
    {
        $r = strtolower($risk);
        if (in_array($r, [self::RISK_LOW, self::RISK_MEDIUM, self::RISK_HIGH], true)) {
            return $r;
        }

        return match ($urgency) {
            'high' => self::RISK_HIGH,
            'low' => self::RISK_LOW,
            default => self::RISK_MEDIUM,
        };
    }

    private function title(string $theme): string
    {
        return ucfirst(str_replace('_', ' ', $theme)).' evolution thesis';
    }

    /** @param list<array<string,mixed>> $opportunities */
    private function whyItMatters(string $theme, array $opportunities): string
    {
        $count = count($opportunities);
        $countStr = $count > 0 ? " ({$count} opportunities identified)" : '';

        return "The {$theme} direction{$countStr} compounds Atlas capability "
            .'by closing a structural gap that current primitives cannot close incrementally.';
    }

    private function structuralLeverage(string $theme, string $capabilityDelta): string
    {
        return "Advancing {$theme} unlocks: {$capabilityDelta}. "
            .'This is a structural unlock — it compounds future delivery velocity rather than '
            .'producing a one-off artefact.';
    }

    /** @param list<array<string,mixed>> $opportunities @return list<string> */
    private function evidenceDemand(string $acceptancePath, array $opportunities): array
    {
        $demand = [
            'acceptance_path_must_be_verified_by_automated_test_or_runtime_gate',
            'evidence_must_be_grounded_in_observable_code_or_runtime_state',
            'evidence_must_cite_specific_file_or_metric_not_a_description',
        ];

        if ($opportunities !== []) {
            $demand[] = 'all_'.count($opportunities).'_opportunities_addressed_or_explicitly_deferred';
        }

        // Surface any per-opportunity evidence fields.
        foreach ($opportunities as $opp) {
            $ev = (string) ($opp['evidence'] ?? '');
            if ($ev !== '') {
                $demand[] = 'opportunity_evidence:'.$ev;
            }
        }

        return $demand;
    }

    /** @return array{coherent:bool, reason:string} */
    private function checkTaskChainCoherence(array $shapes): array
    {
        $shapeNames = array_map(static fn (array $s): string => strtolower((string) ($s['shape'] ?? '')), $shapes);

        $hasImpl   = array_filter($shapeNames, static fn (string $n): bool => in_array($n, self::IMPL_SHAPES, true)) !== [];
        $hasVerify = array_filter($shapeNames, static fn (string $n): bool => in_array($n, self::VERIFY_SHAPES, true)) !== [];

        if (! $hasImpl) {
            return ['coherent' => false, 'reason' => 'missing_implementation_step'];
        }
        if (! $hasVerify) {
            return ['coherent' => false, 'reason' => 'missing_verification_step'];
        }

        return ['coherent' => true, 'reason' => ''];
    }

    /** @return array{steps:list<array<string,mixed>>} */
    private function buildTaskChain(array $shapes): array
    {
        $steps = [];
        foreach ($shapes as $i => $shape) {
            $name   = (string) ($shape['shape'] ?? "step_{$i}");
            $steps[] = [
                'order'                   => $i + 1,
                'shape'                   => $name,
                'description'             => (string) ($shape['description'] ?? ''),
                'prerequisite_signals'    => self::PREREQUISITE_SIGNALS[$name] ?? ["step_{$i}_done"],
                'expected_leverage_delta' => self::EXPECTED_LEVERAGE[$name] ?? 'medium',
                'proof_required'          => self::PROOF_REQUIRED[$name]   ?? 'output_observable_in_code_or_runtime',
            ];
        }

        return ['steps' => $steps];
    }

    /** @param list<array<string,mixed>> $opportunities @return list<string> */
    private function evidenceRefs(array $opportunities): array
    {
        $refs = [];
        foreach ($opportunities as $opp) {
            $ev = trim((string) ($opp['evidence'] ?? ''));
            if ($ev !== '') {
                $refs[] = $ev;
            }
        }

        return array_values(array_unique($refs));
    }

    private function expectedCompoundLift(array $opportunities, string $urgency, string $risk): float
    {
        $base = min(1.0, count($opportunities) * 0.10);
        $base += match ($urgency) { 'high' => 0.30, 'low' => 0.0, default => 0.15 };
        $base -= match ($risk)    { 'high' => 0.10, 'low' => -0.05, default => 0.0 };

        return round(max(0.0, min(1.0, $base)), 4);
    }

    /** @return list<array{shape:string, description:string}> */
    private function taskShapes(string $theme, string $capabilityDelta): array
    {
        return [
            [
                'shape' => 'implement_capability',
                'description' => "Implement the {$theme} primitive that delivers: {$capabilityDelta}",
            ],
            [
                'shape' => 'verify_acceptance',
                'description' => 'Add automated proof that the acceptance path is satisfied in a running system',
            ],
            [
                'shape' => 'wire_to_consumers',
                'description' => "Wire the new {$theme} capability to at least one live consumer so it compounds",
            ],
        ];
    }
}
