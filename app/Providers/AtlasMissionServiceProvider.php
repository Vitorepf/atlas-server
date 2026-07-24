<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\RealExecution\AtlasMissionOutcomeRecorder;
use App\Services\Ai\RealExecution\AtlasMissionService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionDetector;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionLoopService;
use App\Services\Ai\SelfConstruction\Support\AtlasSelfImprovementAdversarialRecheck;
use App\Services\Ai\SelfConstruction\Support\AtlasSelfImprovementMetaMetricService;
use App\Services\Ai\SelfConstruction\Support\AtlasSelfImprovementReceiptLog;
use App\Services\Ai\SelfConstruction\Support\AtlasSelfImprovementRelevanceGate;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Ai\RealExecution\MissionDeliveryOrchestrator;
use Illuminate\Support\ServiceProvider;

/**
 * Closed mission loop + self-construction loop + ADML feedback peel (full-pass ASP).
 */
final class AtlasMissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // S2.F1 · CLOSED MISSION LOOP wiring. The orchestrator's brain deps are
        // nullable constructor params (so `new` in tests stays 2-arg), which means
        // Laravel's auto-resolution leaves them NULL. Bind explicitly so the live
        // atlas:mission:deliver path gets the brain query (provider-bound context
        // in) AND the ingestion (outcome recorded back out) — the loop only closes
        // when both are present. Both bridges remain flag-gated + fail-open inside
        // the orchestrator, so this binding is safe even with the flag off.
        // S2.F2 · EXECUTION feeds the BRAIN. The recorder's single ingestion param
        // is nullable (so `new` in tests stays 0/1-arg), which means Laravel's
        // auto-resolution would leave it NULL. Bind explicitly so the live loop gets
        // a recorder that can actually write the outcome back. Flag-gated + fail-open
        // inside the recorder, so this binding is safe even with the flag off.
        $this->app->bind(AtlasMissionOutcomeRecorder::class, function ($app) {
            return new AtlasMissionOutcomeRecorder(
                $app->make(AtlasRealityGraphIngestionService::class),
            );
        });

        $this->app->bind(MissionDeliveryOrchestrator::class, function ($app) {
            return new MissionDeliveryOrchestrator(
                $app->make(AtlasLiveCodeDeliveryService::class),
                $app->make(GovernedBranchMaterializationService::class),
                $app->make(AtlasRealityGraphQueryService::class),
                $app->make(AtlasRealityGraphIngestionService::class),
                $app->make(AtlasMissionOutcomeRecorder::class),
            );
        });

        // S2.F4 · the LOOP COMPOUNDS + TEMPORAL. AtlasMissionService's temporal params
        // are nullable (so `new AtlasMissionService($orchestrator)` in tests stays
        // 1-arg), which means Laravel's auto-resolution would leave them NULL on the
        // live CLI path. Bind explicitly so atlas:mission:deliver gets the ingestion
        // (real graph-state snapshot) AND the temporal service (the 4D chain it ticks
        // into) — each delivered mission's accrual is then timestamped. Flag-gated +
        // fail-open inside the service, so this binding is safe even with the flag off.
        $this->app->bind(AtlasMissionService::class, function ($app) {
            return new AtlasMissionService(
                $app->make(MissionDeliveryOrchestrator::class),
                $app->make(AtlasRealityGraphIngestionService::class),
                $app->make(AtlasUnifiedRealityGraphTemporalService::class),
            );
        });

        // S3.F4 · the RECURSIVE GOVERNED SELF-IMPROVEMENT LOOP, fully governed. Like
        // AtlasMissionService above, the loop's F3/F4 collaborators are nullable (so the
        // F1-F3 test constructions stay byte-identical), which means auto-resolution would
        // leave the meta-metric / adversarial re-check / receipt log NULL on the live CLI
        // path. Bind explicitly so atlas:self-construct ALWAYS gets the full safe floor:
        //  - the HONEST meta-metric (F3 history),
        //  - the ADVERSARIAL RE-CHECK (F4 out-of-process Goodhart guard) — gated by
        //    atlas.self_construction.adversarial_recheck_enabled (default ON): when OFF the
        //    operator gets the F1-F3 behaviour (a gate PASS surfaces directly),
        //  - the EVIDENCE / RECEIPT LOG (F4 audit trail — no silent action).
        $this->app->bind(AtlasSelfConstructionLoopService::class, function ($app) {
            $recheckEnabled = (bool) config('atlas.self_construction.adversarial_recheck_enabled', true);

            return new AtlasSelfConstructionLoopService(
                $app->make(AtlasSelfConstructionDetector::class),
                $app->make(AtlasMissionService::class),
                $app->make(AtlasSelfImprovementRelevanceGate::class),
                $app->make(GovernedBranchMaterializationService::class),
                $app->make(AtlasSelfImprovementMetaMetricService::class),
                $recheckEnabled ? $app->make(AtlasSelfImprovementAdversarialRecheck::class) : null,
                $app->make(AtlasSelfImprovementReceiptLog::class),
            );
        });

        // Patamar 4 · ADML closed feedback loop. When the live outcome feedback
        // service is bound, ADML can call autoDeactivateOnDegradation() to drop
        // active routes whose live success rate falls below threshold.
        $this->app->resolving(AtlasDecideMetaLearningService::class, function ($svc, $app) {
            if ($svc instanceof AtlasDecideMetaLearningService) {
                try {
                    $svc->setLiveOutcomeFeedback($app->make(AtlasDecideLiveOutcomeFeedbackService::class));
                } catch (\Throwable $e) {
                    // Defensive — service is always resolvable but unit tests may bypass.
                }
            }
        });
    }
}
