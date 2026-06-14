<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * AUTONOMOUS MULTI-FILE OBRA CANDIDATE PRODUCER — the connective tissue between the live 24/7
 * grind loop and the operator-gated big-obra surface.
 *
 * The grind loop only ever produces SINGLE-FILE objectives (the edge-gap + refactor synthesizers
 * each stamp one allowed_file). The big-work surface (scheduled module-hotspot proposals, the
 * obra bridge) was disconnected from it. This detector closes that gap: it reads the leverage
 * signals discovery ALREADY stamped on each target (impact_real_callers, cyclomatic,
 * refactor_leverage), recognizes a WIRED high-leverage HUB, resolves the hub's REAL production
 * callers as actual FILE PATHS, and parks a coherent >=2-file obra CANDIDATE in the SAME
 * operator-review backlog {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopAutoArchitectureProposalService}
 * uses (anti-fragmentation: one queue for both big-work producers).
 *
 * PÉTREO floor — this is PROPOSAL-ONLY and CANNOT cross any line:
 *   - It NEVER enqueues a loop task, NEVER calls a provider, NEVER mutates the repo / writes git,
 *     NEVER creates an obra, NEVER touches the merge path or never-merge default.
 *   - The actual cross-file refactor stays Forge-class behind operator review + L4-10 certification.
 *   - Default-OFF (atlas.loop.obra_cluster_detection_enabled=false) => fully inert.
 *   - Fail-OPEN everywhere: any DB/IO/guard failure yields zero candidates and the loop continues
 *     100% on its discovery targets — the detector can never break a refill.
 *
 * Anti-spam: a deterministic cluster_hash + this service's OWN durable cooldown index (the
 * backlog's createProposal mints a fresh id every call and carries NO hash dedup, so the dedup
 * MUST live here, mirroring the architecture proposer's own index pattern).
 */
final class AtlasLoopObraClusterDetectorService
{
    public const SCHEMA_VERSION = 'atlas.loop.obra_cluster_detector.v1';

    private const INDEX_PATH = 'atlas/loop/obra-cluster/cluster-index.json';

    /** Hard cap on the durable cooldown index so it can never grow unbounded. */
    private const INDEX_CAP = 200;

    public function __construct(
        private readonly AtlasSelfImprovementProposalBacklogService $backlog,
        private readonly ?AtlasLoopWiredCallerService $wiredCallers = null,
        private readonly ?AtlasLoopHarnessGuard $harnessGuard = null,
    ) {}

    /**
     * Scan the claimed discovery targets for high-leverage hubs and park multi-file obra
     * candidates for operator review. Returns a summary list of the candidates parked this cycle.
     *
     * @param  iterable<\App\Models\AtlasLoopTarget>  $targets  the SAME claimed targets the refiller holds (signals already stamped)
     * @return list<array<string,mixed>>
     */
    public function detect(AtlasLoopCampaign $campaign, iterable $targets): array
    {
        // Belt-and-suspenders flag gate (the refiller also gates the call). Default-OFF => inert.
        if (! (bool) config('atlas.loop.obra_cluster_detection_enabled', false)) {
            return [];
        }

        $repoRoot = rtrim((string) $campaign->base_workspace, '/');
        if ($repoRoot === '' || ! is_dir($repoRoot)) {
            return [];
        }

        $minCallers = max(2, (int) config('atlas.loop.obra_cluster_min_callers', 3));
        $minCyclomatic = max(1, (int) config('atlas.loop.obra_cluster_min_cyclomatic', 10));
        $leverageFloor = max(0.0, (float) config('atlas.loop.obra_cluster_leverage_floor', 0.5));
        $cooldownHours = max(1, (int) config('atlas.loop.obra_cluster_cooldown_hours', 168));
        $maxClusterFiles = max(2, (int) config('atlas.loop.obra_cluster_max_files', 8));
        $maxPerCycle = max(1, (int) config('atlas.loop.obra_cluster_max_candidates_per_cycle', 2));

        $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard();
        $wired = $this->wiredCallers ?? new AtlasLoopWiredCallerService($repoRoot);

        $created = [];
        foreach ($targets as $target) {
            if (count($created) >= $maxPerCycle) {
                break; // bound per-cycle backlog writes; cooldown handles the rest next cycle
            }
            try {
                $candidate = $this->candidateForTarget(
                    $target, $repoRoot, $guard, $wired, $minCallers, $minCyclomatic, $leverageFloor, $maxClusterFiles,
                );
                if ($candidate === null) {
                    continue;
                }
                if ($this->onCooldown($candidate->clusterHash, $cooldownHours)) {
                    continue; // already proposed this exact cluster recently — no spam
                }

                $item = $this->backlog->createProposal([
                    'proposal' => $candidate->toBacklogProposalPayload(),
                    'source' => 'postmortem', // recognized SOURCES enum (same as the architecture proposer)
                    'affected_domains' => ['autonomous_evolution', 'programming', 'architecture'],
                    'constraints' => [
                        'proposal_only',
                        'operator_review_required_before_execution',
                        'l4_10_real_execution_receipt_required_before_merge',
                        'no_provider_call',
                        'no_obra_creation',
                        'no_merge',
                    ],
                ]);
                $proposalId = (string) ($item['proposal_id'] ?? '');
                $this->rememberCluster($candidate->clusterHash, $proposalId, $candidate->hubPath);

                $created[] = [
                    'proposal_id' => $proposalId,
                    'hub_path' => $candidate->hubPath,
                    'cluster_files' => $candidate->allowedFiles,
                    'cluster_hash' => $candidate->clusterHash,
                    'routing_rationale' => $candidate->routingRationale,
                ];
            } catch (Throwable) {
                continue; // fail-open per target — a single bad target never aborts the scan
            }
        }

        return $created;
    }

    /**
     * Build the obra candidate for one target, or null if it does not qualify. Deterministic and
     * provider-free; the ONLY external read is the grep-based caller-path resolution.
     */
    private function candidateForTarget(
        \App\Models\AtlasLoopTarget $target,
        string $repoRoot,
        AtlasLoopHarnessGuard $guard,
        AtlasLoopWiredCallerService $wired,
        int $minCallers,
        int $minCyclomatic,
        float $leverageFloor,
        int $maxClusterFiles,
    ): ?AtlasLoopObraClusterCandidate {
        $hubPath = ltrim((string) $target->target_path, '/');
        if ($hubPath === '') {
            return null;
        }
        // PÉTREO: the loop never proposes work on its own gates/judge/never-merge/harness guard.
        if ($guard->isForbiddenSelfTarget($hubPath)) {
            return null;
        }

        $signals = is_array($target->signals) ? $target->signals : [];

        // HUB qualification: a MEASURED caller count (never the null/unmeasured tri-state),
        // a high per-method cyclomatic, and a high refactor leverage — all already stamped by
        // AtlasLoopTargetDiscoveryService's impact ranking this pass.
        $impactCallers = $signals['impact_real_callers'] ?? null;
        if (! is_int($impactCallers) || $impactCallers < $minCallers) {
            return null;
        }
        $cyclomatic = (int) ($signals['cyclomatic'] ?? 0);
        if ($cyclomatic < $minCyclomatic) {
            return null;
        }
        $leverage = (float) ($signals['refactor_leverage'] ?? 0.0);
        if ($leverage < $leverageFloor) {
            return null;
        }

        // CLUSTER formation from GREP caller PATHS (working-tree truth). Required even though the
        // impact count qualified the hub: callerCounts() takes MAX(grep, code-graph) and the graph
        // can be stale, so a hub can show impact_real_callers>=floor with ZERO real working-tree
        // callers. Requiring >=1 grep caller path here means a stale-graph-only hub produces NO
        // candidate (closes the phantom-cluster hole). Absent key = unmeasured grep => reject.
        $resolved = $wired->callerPaths([$hubPath]);
        if (! array_key_exists($hubPath, $resolved)) {
            return null; // grep unmeasured — cannot honestly assemble a cluster
        }
        $callerPaths = $resolved[$hubPath];

        // PÉTREO: ANY forbidden-self-target member rejects the WHOLE cluster (caller paths were
        // never discovery-admit()-filtered, so they MUST be guarded here).
        foreach ($callerPaths as $caller) {
            if ($guard->isForbiddenSelfTarget((string) $caller)) {
                return null;
            }
        }

        // Bound the obra size deterministically (sorted slice — callerPaths is already sorted).
        $cappedCallers = array_slice($callerPaths, 0, max(1, $maxClusterFiles - 1));

        $routingRationale = [
            'min_callers' => $minCallers,
            'measured_impact_callers' => $impactCallers,
            'measured_grep_caller_paths' => count($callerPaths),
            'min_cyclomatic' => $minCyclomatic,
            'measured_cyclomatic' => $cyclomatic,
            'leverage_floor' => $leverageFloor,
            'measured_refactor_leverage' => round($leverage, 4),
            'cluster_capped_to' => count($cappedCallers) + 1,
        ];
        $leverageSignals = [
            'cyclomatic' => $cyclomatic,
            'cyclomatic_total' => (int) ($signals['cyclomatic_total'] ?? 0),
            'refactor_leverage' => round($leverage, 4),
            'measured_caller_count' => $impactCallers,
        ];

        // fromHub enforces the >=2-file invariant (single source of truth for that floor).
        return AtlasLoopObraClusterCandidate::fromHub($hubPath, $cappedCallers, $leverageSignals, $routingRationale);
    }

    /**
     * Has this exact cluster been proposed within the cooldown window? Fail-open: an unreadable
     * index returns false (the INDEX_CAP still bounds growth, and a duplicate proposal is a
     * tolerable degradation vs blocking real work).
     */
    private function onCooldown(string $clusterHash, int $cooldownHours): bool
    {
        try {
            $index = $this->readIndex();
            $recordedAt = $this->stringOrNull(data_get($index, "clusters.{$clusterHash}.recorded_at"));
            if ($recordedAt === null) {
                return false;
            }

            return Carbon::parse($recordedAt)->greaterThan(Carbon::now()->subHours($cooldownHours));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Record a parked cluster in the durable cooldown index (newest-first, capped). Best-effort.
     */
    private function rememberCluster(string $clusterHash, string $proposalId, string $hubPath): void
    {
        if ($clusterHash === '') {
            return;
        }
        try {
            $index = $this->readIndex();
            $clusters = is_array($index['clusters'] ?? null) ? $index['clusters'] : [];
            // Remove + re-add so the surviving cap keeps the most recent entries.
            unset($clusters[$clusterHash]);
            $clusters[$clusterHash] = [
                'proposal_id' => $proposalId,
                'hub_path' => $hubPath,
                'recorded_at' => Carbon::now()->toIso8601String(),
            ];
            if (count($clusters) > self::INDEX_CAP) {
                $clusters = array_slice($clusters, -self::INDEX_CAP, null, true);
            }
            Storage::disk('local')->put(self::INDEX_PATH, (string) json_encode([
                'schema_version' => self::SCHEMA_VERSION,
                'updated_at' => Carbon::now()->toIso8601String(),
                'clusters' => $clusters,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
            // best-effort: a failed index write must never abort candidate production
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function readIndex(): array
    {
        if (! Storage::disk('local')->exists(self::INDEX_PATH)) {
            return [];
        }
        try {
            $decoded = json_decode((string) Storage::disk('local')->get(self::INDEX_PATH), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
