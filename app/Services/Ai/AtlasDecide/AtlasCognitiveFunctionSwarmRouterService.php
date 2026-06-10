<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Atlas Cognitive Function Swarm Router — Patamar 4 · C1.
 *
 * Closes the composition gap promised by the Patamar 4 canon doc §2.6:
 * "conduzir um swarm — provider A para reasoning, B para escrita, C para
 * code, D para visão, tudo num único turn".
 *
 * Flow:
 *   1. Decomposer turns the natural-language input into a 6-axis cognitive
 *      tuple (reasoning, retrieval, generation, code, vision, audit).
 *   2. For every axis above the inclusion threshold, this router calls
 *      AtlasSwarmConductorService.dispatch() with task_category=axis_name —
 *      so the existing ADML routing logic picks the best provider/model
 *      PER FUNCTION instead of per opaque task.
 *   3. The arms from each axis are aggregated into ONE envelope; duplicates
 *      collapsed (provider+model combo seen twice keeps the higher weight).
 *   4. Kernel gate logged once per route; receipt JSONL is the operator's
 *      audit of "qual provider está rodando qual função neste turn".
 *
 * Provider-safe. Local-first. No external claims.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-cognitive-function-swarm-router.md
 *
 * Schemas:
 *   - atlas.cognitive_function_swarm_router.envelope.v1
 *
 * Invariants:
 *   - Axis order is canon: reasoning, retrieval, generation, code, vision, audit.
 *   - Inclusion threshold default 0.15 (operator override per call).
 *   - K arms capped at MAX_AXES (6) — never duplicates beyond the 6 canon axes.
 *   - Kernel validateChange called once per route.
 *   - JSONL append-only.
 *   - claim_policy provider-safe.
 */
class AtlasCognitiveFunctionSwarmRouterService
{
    public const SCHEMA = 'atlas.cognitive_function_swarm_router.envelope.v1';

    public const DEFAULT_INCLUSION_THRESHOLD = 0.15;

    public const MAX_AXES = 6;

    private ?string $logPathOverride = null;

    public function __construct(
        private readonly AtlasCognitiveFunctionDecomposerService $decomposer,
        private readonly AtlasSwarmConductorService $conductor,
        private readonly AtlasConstitutionalKernelService $kernel,
    ) {}

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/atlas_decide')
            : sys_get_temp_dir().'/atlas/atlas_decide';

        return $base.DIRECTORY_SEPARATOR.'cognitive_function_swarm_router.jsonl';
    }

    /**
     * Decompose input and build a multi-axis swarm dispatch envelope.
     *
     * @param  array<string,mixed>  $context  ['role','framework','privacy_class', ...]
     * @return array<string,mixed>
     */
    public function routeAndDispatch(string $input, array $context = [], ?float $inclusionThreshold = null): array
    {
        $threshold = $inclusionThreshold ?? self::DEFAULT_INCLUSION_THRESHOLD;
        $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

        // 1. Decompose.
        $decomposition = $this->decomposer->decompose($input, $context);
        $weights = (array) ($decomposition['weights'] ?? []);
        $dominantAxis = (string) ($decomposition['dominant_function'] ?? 'reasoning');

        // 2. Kernel gate.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'cognitive_function_swarm_route',
            'proposed_effect' => 'multi-axis swarm route dominant='.$dominantAxis,
            'scope' => ['privacy_class' => (string) ($context['privacy_class'] ?? 'normal')],
            'actor' => 'CognitiveFunctionSwarmRouter',
        ]);

        // 3. For every axis above threshold, ask the conductor for arms.
        $axesIncluded = [];
        $rawArms = [];
        $blocked = $kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK;

        if (! $blocked) {
            foreach (AtlasCognitiveFunctionDecomposerService::FUNCTIONS as $axis) {
                $weight = (float) ($weights[$axis] ?? 0.0);
                if ($weight < $threshold) {
                    continue;
                }
                $axesIncluded[] = ['axis' => $axis, 'weight' => $weight];

                try {
                    $dispatch = $this->conductor->dispatch([
                        'task_category' => $axis,
                        'role' => (string) ($context['role'] ?? 'primary'),
                        'framework' => $context['framework'] ?? null,
                        'parallelism' => 2,
                        'scope' => ['privacy_class' => (string) ($context['privacy_class'] ?? 'normal')],
                        'requested_autonomy' => (string) ($context['requested_autonomy'] ?? 'execute_with_approval'),
                    ]);
                } catch (\Throwable $e) {
                    $rawArms[] = [
                        'axis' => $axis,
                        'weight' => $weight,
                        'arm' => null,
                        'reason' => 'conductor_error: '.substr($e->getMessage(), 0, 80),
                    ];

                    continue;
                }

                foreach ((array) ($dispatch['arms'] ?? []) as $arm) {
                    $rawArms[] = [
                        'axis' => $axis,
                        'weight' => $weight,
                        'arm' => $arm,
                    ];
                }
            }
        }

        // 4. Collapse duplicates (provider+model) keeping the highest weight.
        $byProvider = [];
        foreach ($rawArms as $entry) {
            $arm = $entry['arm'] ?? null;
            if ($arm === null) {
                continue;
            }
            $key = (string) ($arm['provider'] ?? '').'|'.(string) ($arm['model'] ?? '');
            if (! isset($byProvider[$key]) || $entry['weight'] > $byProvider[$key]['weight']) {
                $byProvider[$key] = [
                    'arm_id' => (string) ($arm['arm_id'] ?? ''),
                    'rank' => count($byProvider) + 1,
                    'origin' => (string) ($arm['origin'] ?? ''),
                    'provider' => (string) ($arm['provider'] ?? ''),
                    'model' => (string) ($arm['model'] ?? ''),
                    'cognitive_axis' => $entry['axis'],
                    'axis_weight' => round($entry['weight'], 4),
                ];
            }
        }

        $arms = array_values($byProvider);
        // Cap at MAX_AXES providers — never explode beyond canon.
        if (count($arms) > self::MAX_AXES) {
            $arms = array_slice($arms, 0, self::MAX_AXES);
        }
        // Reassign canonical ranks 1..N.
        foreach ($arms as $i => $a) {
            $arms[$i]['rank'] = $i + 1;
        }

        $envelope = [
            'schema_version' => self::SCHEMA,
            'generated_at' => $generatedAt,
            'input_preview' => mb_substr($input, 0, 120),
            'dominant_function' => $dominantAxis,
            'cognitive_vector' => $weights,
            'inclusion_threshold' => $threshold,
            'axes_included' => $axesIncluded,
            'decomposition_hash' => $decomposition['decomposition_hash'] ?? null,
            'arm_count' => count($arms),
            'arms' => $arms,
            'kernel_decision' => $kernelEnv['decision'] ?? null,
            'kernel_hash' => $kernelEnv['kernel_hash'] ?? $this->kernel->kernelHash(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $envelope['router_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA,
            'generated_at' => $generatedAt,
            'dominant' => $dominantAxis,
            'arm_count' => count($arms),
            'axes_included' => $axesIncluded,
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->logPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRoutes(int $tail = 20): array
    {
        $all = AppendOnlyJsonlStore::read($this->logPath());
        if ($tail <= 0) {
            return $all;
        }

        return array_slice($all, -$tail);
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'benchmark_claim_allowed' => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            'external_rivals_certification_touched' => false,
            'cognitive_immune_law_enforced' => true,
            'provider_safe_only_enforced' => true,
            'local_first_only' => true,
        ];
    }

    // ---------- internals ----------
}
