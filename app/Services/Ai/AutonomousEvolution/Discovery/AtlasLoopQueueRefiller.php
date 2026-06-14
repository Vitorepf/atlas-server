<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTarget;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFrameworkRefactorSynthesizer;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The self-feeding seam: turns discovered TARGETS into durable, metric-shaped TASKS.
 *
 * For each top-ranked candidate it builds a scoped base workspace from the campaign's
 * repo, asks {@see AtlasEvolutionTaskGenerator} to write ONE RED-verified frozen test +
 * objective (the only provider call in discovery — the honest "is this real work" gate),
 * then SNAPSHOTS the target content + frozen test bodies into a durable task payload so
 * the queued task is fully reproducible across restarts, and enqueues it. A target whose
 * generator output is not genuinely RED is routed through loop-back (quarantined as
 * un-grindable), never enqueued — so the queue stays honest.
 */
final class AtlasLoopQueueRefiller
{
    public function __construct(
        private readonly AtlasLoopTargetDiscoveryService $discovery,
        private readonly AtlasLoopTargetRepository $repository,
        private readonly AtlasEvolutionTaskGenerator $generator,
        private readonly AtlasLoopBackService $loopBack,
        private readonly AtlasLoopStore $store,
        private readonly ?AtlasLoopRefactorObjectiveSynthesizer $refactorSynthesizer = null,
        private readonly ?AtlasLoopHarnessGuard $harnessGuard = null,
        private readonly ?AtlasLoopFrameworkRefactorSynthesizer $frameworkRefactorSynthesizer = null,
        private readonly ?AtlasLoopObraClusterDetectorService $obraClusterDetector = null,
        private readonly ?AtlasLoopWorkShapeRouter $workShapeRouter = null,
        private readonly ?AtlasLoopMultiFileRefactorSynthesizer $multiFileRefactorSynthesizer = null,
    ) {}

    /**
     * Discover + generate + enqueue up to $want new tasks for a campaign.
     *
     * @return array{discovered:int, claimed:int, enqueued:int, quarantined:int, deferred:int, obra_candidates:int}
     */
    public function refill(AtlasLoopCampaign $campaign, int $want): array
    {
        $repoRoot = rtrim((string) $campaign->base_workspace, '/');
        $provider = (string) $campaign->provider; // '' => loop default (provider-agnostic)

        $disc = $this->discovery->discover($repoRoot, $campaign->id, ['limit' => max($want * 2, $want + 4)]);
        $targets = $this->repository->claimTop($campaign->id, $want);

        $enqueued = 0;
        $quarantined = 0;
        $deferred = 0;

        foreach ($targets as $target) {
            $outcome = $this->generateAndEnqueue($campaign, $target, $provider);
            if ($outcome === 'enqueued') {
                $enqueued++;
            } elseif ($outcome === 'quarantined') {
                $quarantined++;
            } else {
                $deferred++;
            }
            // Liveness during a long refill: generating a batch can take many minutes (a
            // provider call per self-contained target), but the supervisor only beats AFTER
            // refill() RETURNS. Without this, a cold-start refill freezes the campaign
            // heartbeat for 10-20min, so the keepalive watchdog and operator monitoring
            // cannot tell "working" from "hung". Refresh the heartbeat per target (liveness
            // only — never touches elapsed_seconds, so the wall-clock budget is unaffected).
            $this->touchHeartbeat($campaign);
        }

        // OBRA CANDIDATE PRODUCER (slice-1, default-OFF, fail-open): after discovery+enqueue,
        // scan the SAME claimed targets for high-leverage multi-file HUB clusters and park them
        // as operator-review obra CANDIDATES. It enqueues NO loop task, calls NO provider,
        // mutates NOTHING, and never changes discovered/claimed/enqueued/quarantined/deferred.
        // Flag OFF or detector unresolved => this block is inert and the counters are unchanged.
        $obraCandidates = 0;
        if ((bool) config('atlas.loop.obra_cluster_detection_enabled', false) && $this->obraClusterDetector !== null) {
            try {
                $obraCandidates = count($this->obraClusterDetector->detect($campaign, $targets));
            } catch (Throwable) {
                $obraCandidates = 0; // fail-open: the producer can never break a refill
            }
        }

        return [
            'discovered' => (int) $disc['upserted'],
            'claimed' => count($targets),
            'enqueued' => $enqueued,
            'quarantined' => $quarantined,
            'deferred' => $deferred,
            'obra_candidates' => $obraCandidates,
        ];
    }

    /**
     * Best-effort liveness ping during a long refill. Updates ONLY heartbeat_at (never
     * elapsed_seconds, so the wall-clock budget is untouched). Never throws — a heartbeat
     * write must not break task generation.
     */
    private function touchHeartbeat(AtlasLoopCampaign $campaign): void
    {
        try {
            \Illuminate\Support\Facades\DB::table('atlas_loop_campaigns')
                ->where('id', $campaign->id)
                ->update(['heartbeat_at' => now()]);
        } catch (\Throwable) {
            // liveness ping is best-effort; ignore failures
        }
    }

    private function generateAndEnqueue(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, string $provider): string
    {
        $repoRoot = rtrim((string) $campaign->base_workspace, '/');
        $source = $repoRoot.'/'.ltrim((string) $target->target_path, '/');
        if (! is_file($source)) {
            $this->loopBack->reflect($campaign->id, ['target_id' => $target->id, 'status' => 'no_winner', 'reason' => 'self_contain_missing_source']);

            return 'quarantined';
        }

        // L2-2 (breadth): target framework-reach NÃO passa pelo gerador self-contained —
        // vai direto para o caminho framework do grinder (worktree real + intent-verifier
        // compilado deterministicamente + certificação adversarial universal). É o
        // caminho pelo qual o Loop melhora serviços REAIS do Atlas.
        $signals = is_array($target->signals) ? $target->signals : [];

        // DECISION ("o quê a seguir"): the work-shape router REASONS the highest-leverage shape
        // from the already-stamped signals (replacing pure flag-routing). Its load-bearing
        // decision HERE is work_skip — a CONFIRMED orphan is DEFERRED, not ground: don't spend a
        // provider call on dead code (the honest decision the static cascade lacked). The
        // refactor/edge lanes below remain the executors and keep every RED-gate. Flag-gated
        // (default ON); fail-open — the router only skips on a measured orphan, never on missing
        // data, so the cascade is byte-identical for every non-orphan target.
        if ((bool) config('atlas.loop.decision_router_enabled', true)) {
            $decision = ($this->workShapeRouter ?? new AtlasLoopWorkShapeRouter())->decideShape($signals);
            if (($decision['shape'] ?? '') === AtlasLoopWorkShapeRouter::SHAPE_SKIP) {
                $this->loopBack->reflect($campaign->id, [
                    'target_id' => $target->id,
                    'status' => 'no_winner',
                    'reason' => 'work_shape_skip:'.(string) ($decision['reason'] ?? 'low_leverage'),
                ]);

                return 'deferred';
            }
        }

        // OPTION 3 (operator-gated autonomous MULTI-FILE refactor): if this target is the HUB of a
        // coherent high-leverage cluster, synthesize a >=2-file `refactor_reduce_complexity` task
        // that the grinder HARD-routes to the obra bridge (operator-review, NEVER auto-merge). It
        // runs BEFORE the single-file framework lane so a real cluster goes to the obra route, not
        // a single-file refactor. CO-GATED (adversary finding): requires BOTH
        // multi_file_refactor_objectives_enabled AND refactor_multi_file_via_obra ON — otherwise a
        // multi-file task would fall through to the single-file grind at route time and a >=2-file
        // diff could reach main with only a single-target canary. With either flag OFF, or any
        // null, the lane is inert and the cascade below is byte-identical.
        if ((bool) config('atlas.loop.multi_file_refactor_objectives_enabled', false)
            && (bool) config('atlas.loop.refactor_multi_file_via_obra', false)
            && $this->obraClusterDetector !== null
            && $this->multiFileRefactorSynthesizer !== null) {
            $cluster = $this->obraClusterDetector->candidateFor($target, $campaign);
            if ($cluster !== null) {
                $synth = $this->multiFileRefactorSynthesizer->synthesizeMultiFileRefactor($cluster, $repoRoot, $signals, $provider);
                if ($synth !== null) {
                    $enq = $this->store->enqueueTask(
                        $campaign->id,
                        $synth['objective'],
                        $synth['payload'],
                        'discovery',
                        (string) $target->target_path,
                        (int) round(((float) $target->score) * 100),
                        true,
                        $synth['acceptance_hash'],
                    );
                    $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'multi_file_refactor_task_synthesized');

                    return $enq !== null ? 'enqueued' : 'deferred';
                }
            }
        }

        if ((int) ($signals['framework_reach'] ?? 0) > 0) {
            // FRAMEWORK REFACTOR (heavy, behavior-preserving): BEFORE the edge-gap objective, try
            // to synthesize a `refactor_reduce_complexity` objective STRUCTURALLY (no provider
            // call) for a HIGH-COMPLEXITY framework target that is WIRED (>=1 real caller) AND has
            // real PHPUnit tests. The framework path runs those REAL tests (behavior preserved) and
            // the semantic certifier proves an AST cyclomatic DROP. Default OFF; fail-closed (the
            // synthesizer returns null => fall through to the edge-gap path, byte-identical to
            // today). PETREO: a forbidden self-target is rejected here before enqueue
            // (belt-and-suspenders; discovery's admit() already filters them).
            $outcome = $this->tryFrameworkRefactor($campaign, $target, $signals, $provider, $repoRoot);
            if ($outcome !== null) {
                return $outcome;
            }

            $payload = [
                'materializer' => 'framework',
                'intent_verifier_factory' => true,
                'target_relative_path' => ltrim((string) $target->target_path, '/'),
                'allowed_files' => [ltrim((string) $target->target_path, '/')],
                '_target_id' => $target->id,
            ];
            if ($provider !== '') {
                $payload['provider'] = $provider;
            }
            $enq = $this->store->enqueueTask(
                $campaign->id,
                'Improve '.basename((string) $target->target_path).' guided by its improvement signals (edge gaps, branch density) — framework target.',
                $payload,
                'discovery',
                (string) $target->target_path,
                (int) round(((float) $target->score) * 100),
                true,
                '',
            );
            $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'framework_task_enqueued');

            return $enq !== null ? 'enqueued' : 'deferred';
        }

        // GOVERNED REFACTOR (Phase 1 · within-file): BEFORE the provider-call generator, try
        // to synthesize a `refactor_reduce_complexity` objective STRUCTURALLY (no provider
        // call) for a high-complexity self-contained file that already has a real sibling
        // test. The frozen judge certifies it ONLY when the frozen sibling test stays GREEN
        // (behavior preserved — the loop can never edit it) AND a real AST cyclomatic measure
        // drops. Default OFF; fail-closed (synthesizer returns null => fall through to the
        // normal generator). PETREO: a forbidden self-target is rejected here before enqueue
        // (belt-and-suspenders; discovery's admit() already filters them).
        if ((bool) config('atlas.loop.refactor_objectives_enabled', false) && $this->refactorSynthesizer !== null) {
            $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard();
            if (! $guard->isForbiddenSelfTarget((string) $target->target_path)) {
                $refactor = $this->refactorSynthesizer->synthesize(
                    $repoRoot,
                    ltrim((string) $target->target_path, '/'),
                    $signals,
                    $provider,
                    $target->id,
                );
                if ($refactor !== null) {
                    $enq = $this->store->enqueueTask(
                        $campaign->id,
                        $refactor['objective'],
                        $refactor['payload'],
                        'discovery',
                        (string) $target->target_path,
                        (int) round(((float) $target->score) * 100),
                        true,
                        $refactor['acceptance_hash'],
                    );
                    $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'refactor_task_synthesized');

                    return $enq !== null ? 'enqueued' : 'deferred';
                }
            }
        }

        // PHPUnit-sibling refactor fallback: the Phase-1 plain-`php` synthesizer above admits ONLY
        // the rare require-style sibling. The COMMON case is a pure-logic self-contained service
        // (framework_reach==0) whose only honest behavior anchor is a PHPUnit sibling — which the
        // plain-`php` harness cannot run. Route it through the FRAMEWORK materializer (worktree +
        // real autoload + ./vendor/bin/phpunit), the right tool for ANY file with a PHPUnit anchor.
        // framework_reach==0 only means NEW-behavior grind needs no framework boot; a behavior-
        // PRESERVING refactor still wants the worktree. Same complexity_proof certification.
        // (Without this, the heavy-refactor lane was structurally dead for the whole real codebase.)
        $outcome = $this->tryFrameworkRefactor($campaign, $target, $signals, $provider, $repoRoot);
        if ($outcome !== null) {
            return $outcome;
        }

        $base = sys_get_temp_dir().'/atlas-loop-gen-'.bin2hex(random_bytes(5));
        $targetRel = 'src/'.basename((string) $target->target_path);
        $cleanup = static function () use ($base): void {
            if (is_dir($base)) {
                (new Process(['rm', '-rf', $base]))->run();
            }
        };

        try {
            @mkdir($base.'/src', 0o755, true);
            @mkdir($base.'/tests', 0o755, true);
            file_put_contents($base.'/composer.json', "{}\n");
            copy($source, $base.'/'.$targetRel);

            $gen = $this->generator->generateForTarget($base, $targetRel, ['provider' => $provider, 'index' => 0]);
            if (! (bool) ($gen['generated'] ?? false)) {
                // Not genuinely RED / no real work -> loop-back classifies (quarantine vs requeue).
                $this->loopBack->reflect($campaign->id, ['target_id' => $target->id, 'status' => 'no_winner', 'reason' => (string) ($gen['reason'] ?? 'not_red')]);
                $cleanup();

                return 'quarantined';
            }

            $task = $gen['task'];
            $payload = $this->snapshotPayload($base, $task, $targetRel, $provider, $target->id);
            $enq = $this->store->enqueueTask(
                $campaign->id,
                (string) $task['objective'],
                $payload,
                AtlasLoopTarget::ORIGIN_DISCOVERY === ($target->lineage['origin'] ?? AtlasLoopTarget::ORIGIN_DISCOVERY) ? 'discovery' : 'loopback',
                (string) $target->target_path,
                (int) round(((float) $target->score) * 100),
                true,
                (string) ($task['acceptance']['acceptance_hash'] ?? ''),
            );

            $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'task_generated');
            $cleanup();

            return $enq !== null ? 'enqueued' : 'deferred';
        } catch (Throwable $e) {
            $cleanup();
            $this->loopBack->reflect($campaign->id, ['target_id' => $target->id, 'status' => 'no_winner', 'reason' => 'materialize_'.mb_substr($e->getMessage(), 0, 60)]);

            return 'quarantined';
        }
    }

    /**
     * Snapshot the generated scoped workspace into a DURABLE, restart-safe payload: the
     * unchanged target content + the frozen test bodies + acceptance. The materializer
     * rebuilds an identical workspace at grind time, with no dependency on this temp dir.
     *
     * @param  array<string,mixed>  $task
     * @return array<string,mixed>
     */
    /**
     * HEAVY REFACTOR attempt (behavior-preserving, AST complexity-drop certified) for a
     * high-complexity WIRED target whose behavior anchor is a real PHPUnit sibling. Routes
     * through the FRAMEWORK materializer (worktree + real autoload + ./vendor/bin/phpunit),
     * which runs PHPUnit correctly — so it is the right tool for BOTH framework-reach files AND
     * pure-logic self-contained files (the common case: framework_reach==0 but the only honest
     * behavior anchor is a PHPUnit test, which the plain-`php` Phase-1 synthesizer cannot run).
     * Flag-gated by `framework_refactor_enabled`, fail-closed: returns the enqueue outcome
     * ('enqueued'|'deferred') when a refactor task was synthesized, or null when the target is
     * not refactor-eligible (the caller falls through to its normal path). PETREO: a forbidden
     * self-target is rejected here before enqueue (belt-and-suspenders; discovery already filters).
     *
     * @param  array<string,mixed>  $signals
     */
    private function tryFrameworkRefactor(AtlasLoopCampaign $campaign, AtlasLoopTarget $target, array $signals, string $provider, string $repoRoot): ?string
    {
        if (! (bool) config('atlas.loop.framework_refactor_enabled', false)) {
            return null;
        }
        $guard = $this->harnessGuard ?? new AtlasLoopHarnessGuard();
        if ($guard->isForbiddenSelfTarget((string) $target->target_path)) {
            return null;
        }
        $synth = $this->frameworkRefactorSynthesizer ?? new AtlasLoopFrameworkRefactorSynthesizer();
        $refactor = $synth->synthesizeFrameworkRefactor(
            $repoRoot,
            ltrim((string) $target->target_path, '/'),
            $signals,
            $provider,
            $target->id,
        );
        if ($refactor === null) {
            return null;
        }
        $enq = $this->store->enqueueTask(
            $campaign->id,
            $refactor['objective'],
            $refactor['payload'],
            'discovery',
            (string) $target->target_path,
            (int) round(((float) $target->score) * 100),
            true,
            $refactor['acceptance_hash'],
        );
        $this->repository->markStatus($target->id, AtlasLoopTarget::STATUS_QUEUED, 'framework_refactor_task_synthesized');

        return $enq !== null ? 'enqueued' : 'deferred';
    }

    private function snapshotPayload(string $base, array $task, string $targetRel, string $provider, string $targetId): array
    {
        $frozenTests = [];
        foreach (glob($base.'/tests/*') ?: [] as $file) {
            if (is_file($file)) {
                $frozenTests[] = ['path' => 'tests/'.basename($file), 'content' => (string) file_get_contents($file)];
            }
        }

        $payload = [
            'target_repo_path' => (string) ($task['allowed_files'][0] ?? $targetRel),
            'target_relative_path' => $targetRel,
            'target_content' => (string) file_get_contents($base.'/'.$targetRel),
            'frozen_tests' => $frozenTests,
            'acceptance' => is_array($task['acceptance'] ?? null) ? $task['acceptance'] : [],
            'allowed_files' => is_array($task['allowed_files'] ?? null) ? $task['allowed_files'] : [$targetRel],
            'validation_commands' => is_array($task['validation_commands'] ?? null) ? $task['validation_commands'] : [],
            '_target_id' => $targetId, // loop-back maps the Result back to its Source
        ];
        if ($provider !== '') {
            $payload['provider'] = $provider;
        }

        return $payload;
    }
}
