<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Consolidation;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComplexTargetDecomposer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDedupSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDocGapSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFrameworkRefactorSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopOrphanWiringSupplyLane;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopRefactorObjectiveSynthesizer;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionQuery;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWorkShapeRouter;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Closure;
use Throwable;

final class AtlasLoopRefillerSupplyLaneCoordinator
{
    /**
     * @param  Closure(string, AtlasLoopCampaign): list<string>  $discoveryScopeFiles
     * @param  Closure(string, string): bool  $fileHasInflightTask
     * @param  Closure(AtlasLoopCampaign): void  $touchHeartbeat
     * @param  Closure(AtlasLoopCampaign, AtlasLoopTarget, array<string, mixed>, string, string): array{priority:int, receipt:array<string,mixed>}  $decidedPriority
     * @param  Closure(array<string,mixed>, array<string,mixed>): array<string,mixed>  $withSelfImprovementMarker
     * @param  Closure(AtlasLoopTarget, string): void  $stampLastObjective
     * @param  Closure(AtlasLoopTarget, ?AtlasLoopTask, string): string  $completeTargetEnqueue
     * @param  Closure(AtlasLoopCampaign): list<string>  $effectiveDiscoveryRoots
     * @param  Closure(string, array{docs_roots?:list<string>, max_files?:int}): AtlasLoopScopeComprehensionQuery  $comprehensionQuery
     */
    public function __construct(
        private readonly AtlasLoopTargetRepository $repository,
        private readonly AtlasLoopStore $store,
        private readonly ?AtlasLoopRefactorObjectiveSynthesizer $refactorSynthesizer,
        private readonly ?AtlasLoopHarnessGuard $harnessGuard,
        private readonly ?AtlasLoopFrameworkRefactorSynthesizer $frameworkRefactorSynthesizer,
        private readonly ?AtlasLoopComplexTargetDecomposer $complexTargetDecomposer,
        private readonly Closure $discoveryScopeFiles,
        private readonly Closure $fileHasInflightTask,
        private readonly Closure $touchHeartbeat,
        private readonly Closure $decidedPriority,
        private readonly Closure $withSelfImprovementMarker,
        private readonly Closure $stampLastObjective,
        private readonly Closure $completeTargetEnqueue,
        private readonly Closure $effectiveDiscoveryRoots,
        private readonly Closure $comprehensionQuery,
    ) {}

    public function trySupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want, string $laneKey): int
    {
        return match ($laneKey) {
            'decompose' => $this->tryDecomposeMaterialSupply($campaign, $provider, $repoRoot, $want),
            'dedup' => $this->tryDedupSupply($campaign, $provider, $repoRoot, $want),
            'orphan_wiring' => $this->tryOrphanWiringSupply($campaign, $provider, $repoRoot, $want),
            'doc_gap' => $this->tryDocGapSupply($campaign, $provider, $repoRoot, $want),
            default => 0,
        };
    }

    public function mintDecomposeRefactorTask(AtlasLoopCampaign $campaign, string $relPath, string $worstMethod, int $cyclomatic, string $provider, string $repoRoot): bool
    {
        $signals = [
            'cyclomatic' => $cyclomatic,
            'worst_method' => $worstMethod,
            'decompose_supply' => true,
        ];

        $contentHash = hash('sha256', (string) @file_get_contents($repoRoot.'/'.$relPath));
        $target = $this->repository->upsert(
            (string) $campaign->id,
            $relPath,
            $contentHash,
            [
                'score' => 0.5,
                'self_contained' => 1.0,
                'improvement' => 1.0,
                'novelty' => 0.5,
                'signals' => $signals,
            ],
            ['origin' => AtlasLoopTarget::ORIGIN_DISCOVERY],
        );
        $priorStatus = (string) $target->status;
        $target->forceFill([
            'status' => AtlasLoopTarget::STATUS_CLAIMED,
            'claimed_by' => 'decompose_supply',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(600),
        ])->save();

        $synth = ($this->frameworkRefactorSynthesizer ?? new AtlasLoopFrameworkRefactorSynthesizer)
            ->synthesizeFrameworkRefactor($repoRoot, $relPath, $signals, $provider, (string) $target->id);
        if ($synth === null && $this->refactorSynthesizer !== null) {
            $synth = $this->refactorSynthesizer->synthesize($repoRoot, $relPath, $signals, $provider, (string) $target->id);
        }
        if ($synth === null) {
            $target->forceFill([
                'status' => $priorStatus,
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ])->save();

            return false;
        }

        $dp = ($this->decidedPriority)($campaign, $target, $signals, $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
        $payload = $synth['payload'];
        $payload['decompose_supply'] = true;
        if ($dp['receipt'] !== []) {
            $payload['_decision'] = $dp['receipt'];
        }
        $payload = ($this->withSelfImprovementMarker)($payload, $signals);
        $enq = $this->store->enqueueTask(
            (string) $campaign->id,
            (string) $synth['objective'],
            $payload,
            'decompose',
            $relPath,
            $dp['priority'],
            true,
            (string) $synth['acceptance_hash'],
        );
        ($this->stampLastObjective)($target, (string) $synth['objective']);

        return ($this->completeTargetEnqueue)($target, $enq, 'decompose_supply_refactor_synthesized') === 'enqueued';
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    public function mintDedupTask(AtlasLoopCampaign $campaign, array $spec, string $repoRoot): bool
    {
        $members = array_values(array_filter((array) ($spec['members'] ?? []), 'is_string'));
        $anchor = $members[0] ?? '';
        if ($anchor === '') {
            return false;
        }

        $contentHash = hash('sha256', (string) @file_get_contents($repoRoot.'/'.$anchor));
        $target = $this->repository->upsert(
            (string) $campaign->id,
            $anchor,
            $contentHash,
            [
                'score' => 0.5,
                'self_contained' => 0.0,
                'improvement' => 1.0,
                'novelty' => 0.5,
                'signals' => ['dedup_supply' => true, 'clone_members' => $members],
            ],
            ['origin' => AtlasLoopTarget::ORIGIN_DISCOVERY],
        );
        $priorStatus = (string) $target->status;
        $target->forceFill([
            'status' => AtlasLoopTarget::STATUS_CLAIMED,
            'claimed_by' => 'dedup_supply',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(600),
        ])->save();

        $payload = is_array($spec['payload'] ?? null) ? $spec['payload'] : [];
        $payload['_target_id'] = (string) $target->id;
        $dp = ($this->decidedPriority)($campaign, $target, ['dedup_supply' => true], $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
        if ($dp['receipt'] !== []) {
            $payload['_decision'] = $dp['receipt'];
        }

        $enq = $this->store->enqueueTask(
            (string) $campaign->id,
            (string) ($spec['objective'] ?? ''),
            $payload,
            'dedup',
            $anchor,
            $dp['priority'],
            false,
            (string) ($spec['acceptance_hash'] ?? ''),
        );
        ($this->stampLastObjective)($target, (string) ($spec['objective'] ?? ''));

        $ok = ($this->completeTargetEnqueue)($target, $enq, 'dedup_supply_unification') === 'enqueued';
        if (! $ok) {
            $target->forceFill([
                'status' => $priorStatus,
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ])->save();
        }

        return $ok;
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    public function mintOrphanWiringTask(AtlasLoopCampaign $campaign, array $spec, string $repoRoot): bool
    {
        $anchor = (string) (array_values(array_filter((array) ($spec['members'] ?? []), 'is_string'))[0] ?? '');
        if ($anchor === '') {
            return false;
        }

        $contentHash = hash('sha256', (string) @file_get_contents($repoRoot.'/'.$anchor));
        $target = $this->repository->upsert(
            (string) $campaign->id,
            $anchor,
            $contentHash,
            [
                'score' => 0.5,
                'self_contained' => 0.0,
                'improvement' => 1.0,
                'novelty' => 0.5,
                'signals' => ['orphan_wiring_supply' => true, 'orphan_path' => $anchor],
            ],
            ['origin' => AtlasLoopTarget::ORIGIN_DISCOVERY],
        );
        $priorStatus = (string) $target->status;
        $target->forceFill([
            'status' => AtlasLoopTarget::STATUS_CLAIMED,
            'claimed_by' => 'orphan_wiring_supply',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(600),
        ])->save();

        $payload = is_array($spec['payload'] ?? null) ? $spec['payload'] : [];
        $payload['_target_id'] = (string) $target->id;
        $dp = ($this->decidedPriority)($campaign, $target, ['orphan_wiring_supply' => true], $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
        if ($dp['receipt'] !== []) {
            $payload['_decision'] = $dp['receipt'];
        }

        $enq = $this->store->enqueueTask(
            (string) $campaign->id,
            (string) ($spec['objective'] ?? ''),
            $payload,
            'orphan_wiring',
            $anchor,
            $dp['priority'],
            false,
            '',
        );
        ($this->stampLastObjective)($target, (string) ($spec['objective'] ?? ''));

        $ok = ($this->completeTargetEnqueue)($target, $enq, 'orphan_wiring_supply_directive') === 'enqueued';
        if (! $ok) {
            $target->forceFill([
                'status' => $priorStatus,
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ])->save();
        }

        return $ok;
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    public function mintDocGapTask(AtlasLoopCampaign $campaign, array $spec, string $repoRoot): bool
    {
        $payload = is_array($spec['payload'] ?? null) ? $spec['payload'] : [];
        $capability = trim((string) ($payload['capability'] ?? ''));
        if ($capability === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $capability)) {
            return false;
        }
        $anchor = 'app/Services/Ai/AutonomousEvolution/'.$capability.'.php';

        $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
        if ($guard->isForbiddenSelfTarget($anchor) || ($this->fileHasInflightTask)((string) $campaign->id, $anchor)) {
            return false;
        }

        $contentHash = hash('sha256', (string) @file_get_contents($repoRoot.'/'.$anchor));
        $target = $this->repository->upsert(
            (string) $campaign->id,
            $anchor,
            $contentHash,
            [
                'score' => 0.5,
                'self_contained' => 0.0,
                'improvement' => 1.0,
                'novelty' => 0.7,
                'signals' => ['doc_gap_supply' => true, 'capability' => $capability],
            ],
            ['origin' => AtlasLoopTarget::ORIGIN_DISCOVERY],
        );
        $priorStatus = (string) $target->status;
        $target->forceFill([
            'status' => AtlasLoopTarget::STATUS_CLAIMED,
            'claimed_by' => 'doc_gap_supply',
            'claimed_at' => now(),
            'lease_expires_at' => now()->addSeconds(600),
        ])->save();

        $payload['_target_id'] = (string) $target->id;
        if (is_array($spec['payload']['acceptance'] ?? null)) {
            $payload['acceptance'] = $spec['payload']['acceptance'];
        }
        $dp = ($this->decidedPriority)($campaign, $target, ['doc_gap_supply' => true], $repoRoot, AtlasLoopWorkShapeRouter::SHAPE_REFACTOR);
        if ($dp['receipt'] !== []) {
            $payload['_decision'] = $dp['receipt'];
        }

        $enq = $this->store->enqueueTask(
            (string) $campaign->id,
            (string) ($spec['objective'] ?? ''),
            $payload,
            'doc_gap',
            $anchor,
            $dp['priority'],
            false,
            '',
        );
        ($this->stampLastObjective)($target, (string) ($spec['objective'] ?? ''));

        $ok = ($this->completeTargetEnqueue)($target, $enq, 'doc_gap_supply_directive') === 'enqueued';
        if (! $ok) {
            $target->forceFill([
                'status' => $priorStatus,
                'claimed_by' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ])->save();
        }

        return $ok;
    }

    private function tryDecomposeMaterialSupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        if (! (bool) config('atlas.loop.decompose_supply_enabled', false)) {
            return 0;
        }

        try {
            $decomposer = $this->complexTargetDecomposer ?? new AtlasLoopComplexTargetDecomposer;
            $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
            $materialMin = max(1, (int) config('atlas.loop.material_refactor_min_cyclomatic', 12));
            $cap = max(1, min(
                max(1, $want),
                (int) config('atlas.loop.decompose_supply_max_files_per_refill', 8),
            ));

            $minted = 0;
            foreach (($this->discoveryScopeFiles)($repoRoot, $campaign) as $relPath) {
                if ($minted >= $cap) {
                    break;
                }
                if ($guard->isForbiddenSelfTarget($relPath)) {
                    continue;
                }
                if (($this->fileHasInflightTask)((string) $campaign->id, $relPath)) {
                    continue;
                }

                $source = $repoRoot.'/'.$relPath;
                $body = @file_get_contents($source);
                if (! is_string($body) || trim($body) === '') {
                    continue;
                }

                $subs = $decomposer->decompose($relPath, $body, 1);
                if ($subs === []) {
                    continue;
                }
                $worst = $subs[0];
                $worstMethod = (string) ($worst['method'] ?? '');
                $cyclomatic = (int) ($worst['cyclomatic'] ?? 0);
                if ($cyclomatic < $materialMin) {
                    continue;
                }

                if ($this->mintDecomposeRefactorTask($campaign, $relPath, $worstMethod, $cyclomatic, $provider, $repoRoot)) {
                    $minted++;
                }
                ($this->touchHeartbeat)($campaign);
            }

            return $minted;
        } catch (Throwable) {
            return 0;
        }
    }

    private function tryDedupSupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        if (! (bool) config('atlas.loop.dedup_supply_enabled', false)) {
            return 0;
        }

        try {
            $cap = max(1, min(max(1, $want), (int) config('atlas.loop.dedup_supply_max_per_refill', 4)));
            $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
            $query = ($this->comprehensionQuery)($repoRoot, ['docs_roots' => []]);
            $lane = new AtlasLoopDedupSupplyLane;

            $minted = 0;
            foreach (($this->effectiveDiscoveryRoots)($campaign) as $root) {
                if ($minted >= $cap) {
                    break;
                }
                $root = trim(str_replace('\\', '/', (string) $root), '/');
                if ($root === '' || ! is_dir($repoRoot.'/'.$root)) {
                    continue;
                }
                $model = $query->model($root);
                ($this->touchHeartbeat)($campaign);
                foreach ($lane->mint($model, $repoRoot) as $spec) {
                    if ($minted >= $cap) {
                        break;
                    }
                    $members = array_values(array_filter((array) ($spec['members'] ?? []), 'is_string'));
                    $anchor = $members[0] ?? '';
                    if ($anchor === '' || $guard->isForbiddenSelfTarget($anchor)) {
                        continue;
                    }
                    $conflict = false;
                    foreach ($members as $member) {
                        if (($this->fileHasInflightTask)((string) $campaign->id, $member)) {
                            $conflict = true;
                            break;
                        }
                    }
                    if ($conflict) {
                        continue;
                    }
                    if ($this->mintDedupTask($campaign, $spec, $repoRoot)) {
                        $minted++;
                    }
                    ($this->touchHeartbeat)($campaign);
                }
            }

            return $minted;
        } catch (Throwable) {
            return 0;
        }
    }

    private function tryOrphanWiringSupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        if (! (bool) config('atlas.loop.orphan_wiring_supply_enabled', false)) {
            return 0;
        }

        try {
            $cap = max(1, min(max(1, $want), (int) config('atlas.loop.orphan_wiring_supply_max_per_refill', 2)));
            $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard;
            $query = ($this->comprehensionQuery)($repoRoot, ['docs_roots' => []]);
            $lane = new AtlasLoopOrphanWiringSupplyLane;

            $minted = 0;
            foreach (($this->effectiveDiscoveryRoots)($campaign) as $root) {
                if ($minted >= $cap) {
                    break;
                }
                $root = trim(str_replace('\\', '/', (string) $root), '/');
                if ($root === '' || ! is_dir($repoRoot.'/'.$root)) {
                    continue;
                }
                $model = $query->model($root);
                ($this->touchHeartbeat)($campaign);
                foreach ($lane->mint($model, $repoRoot) as $spec) {
                    if ($minted >= $cap) {
                        break;
                    }
                    $anchor = (string) (array_values(array_filter((array) ($spec['members'] ?? []), 'is_string'))[0] ?? '');
                    if ($anchor === '' || $guard->isForbiddenSelfTarget($anchor)) {
                        continue;
                    }
                    if (($this->fileHasInflightTask)((string) $campaign->id, $anchor)) {
                        continue;
                    }
                    if ($this->mintOrphanWiringTask($campaign, $spec, $repoRoot)) {
                        $minted++;
                    }
                    ($this->touchHeartbeat)($campaign);
                }
            }

            return $minted;
        } catch (Throwable) {
            return 0;
        }
    }

    private function tryDocGapSupply(AtlasLoopCampaign $campaign, string $provider, string $repoRoot, int $want): int
    {
        if (! (bool) config('atlas.loop.doc_gap_supply_enabled', false)) {
            return 0;
        }

        try {
            $cap = max(1, min(max(1, $want), (int) config('atlas.loop.doc_gap_supply_max_per_refill', 1)));
            $lane = new AtlasLoopDocGapSupplyLane;
            $docsRoots = array_values(array_filter((array) config('atlas.loop.doc_gap_supply_docs_roots', []), 'is_string'));
            $query = ($this->comprehensionQuery)($repoRoot, ['docs_roots' => $docsRoots]);

            $minted = 0;
            foreach (($this->effectiveDiscoveryRoots)($campaign) as $root) {
                if ($minted >= $cap) {
                    break;
                }
                $root = trim(str_replace('\\', '/', (string) $root), '/');
                if ($root === '' || ! is_dir($repoRoot.'/'.$root)) {
                    continue;
                }
                $model = $query->model($root);
                ($this->touchHeartbeat)($campaign);
                foreach ($lane->mint($model, $repoRoot) as $spec) {
                    if ($minted >= $cap) {
                        break;
                    }
                    if ($this->mintDocGapTask($campaign, $spec, $repoRoot)) {
                        $minted++;
                    }
                    ($this->touchHeartbeat)($campaign);
                }
            }

            return $minted;
        } catch (Throwable) {
            return 0;
        }
    }
}
