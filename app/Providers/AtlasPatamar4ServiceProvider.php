<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\Context\AiContextPackBuilder;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiWorker;
use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use App\Services\Ai\Compounding\AtlasCompoundingMemoryService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasEngineeringRunConductorService;
use App\Services\Ai\Gateway\AtlasGatewayPreflightService;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\Mcp\AtlasMcpTierService;
use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService;
use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use App\Services\Ai\AtlasDecide\AtlasSwarmTopologySelector;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;
use App\Services\Ai\Cartography\CartographyTruthGuardService;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use App\Services\Ai\Programming\Sdd\Compilers\SpecCritic;
use Illuminate\Support\ServiceProvider;

/**
 * Patamar 4 wiring peel from AppServiceProvider (full-pass): TEOS/AURG, admission, reconciliation, gateway preflight, cartography, scheduler, auto-rebalance probes, AiWorker live feedback, engineering conductor.
 */
final class AtlasPatamar4ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Patamar 4 · TEOS-I3 × AURG-4D auto-chain.
        // TEOS-I3's `setAurgForChaining()` is an opt-in seam so unit tests can
        // create branches without writing temporal ticks. Production resolution
        // MUST wire the chain so every counterfactual branch emits an AURG-4D
        // tick — closing the Patamar 4 hook between TEOS and Reality Graph.
        $this->app->resolving(AtlasTeosI3CounterfactualService::class, function ($svc, $app) {
            if ($svc instanceof AtlasTeosI3CounterfactualService) {
                $svc->setAurgForChaining($app->make(AtlasUnifiedRealityGraphTemporalService::class));
            }
        });

        // Patamar 4 · Autonomy Admission consults Human Trust Ledger.
        // High operator trust track-record lifts the autonomy cap one tier;
        // low trust lowers it. Trust ledger requires DB — wiring is in the
        // resolving callback so unit tests that bypass the container don't pay
        // the DB cost.
        $this->app->resolving(AtlasAutonomyAdmissionService::class, function ($svc, $app) {
            if ($svc instanceof AtlasAutonomyAdmissionService) {
                try {
                    $svc->setTrustLedger($app->make(AtlasSelfImprovementHumanTrustLedgerService::class));
                } catch (\Throwable $e) {
                    // Defensive: trust ledger may not be available in some
                    // environments; service stays in 'unknown' band gracefully.
                }
                // Self-Construction trust ladder — opt-in (default OFF). Safe even
                // when wired: it defaults to MAX friction and can never exceed the
                // risk cap; the operator flips it on, then sets thresholds.
                if ((bool) config('atlas.ai.trust_ladder.enabled', false)) {
                    try {
                        $svc->setChangeClassLadder($app->make(AtlasChangeClassTrustLadder::class));
                    } catch (\Throwable $e) {
                        // Defensive: the ladder is opt-in and stays unwired on failure.
                    }
                }
            }
        });

        // Patamar 4 · Reconciliation meta-cognition via TEOS-I3.
        // Reconciliation projects expected outcome before firing ASCB.propose().
        // Sub-threshold projections are suppressed (recorded honestly).
        $this->app->resolving(AtlasAutonomousReconciliationRuntimeService::class, function ($svc, $app) {
            if ($svc instanceof AtlasAutonomousReconciliationRuntimeService) {
                $svc->setTeosI3ForMetaProjection($app->make(AtlasTeosI3CounterfactualService::class));
                try {
                    $svc->setKernelForElasticChecks($app->make(AtlasConstitutionalKernelService::class));
                } catch (\Throwable $e) {
                    // Defensive: kernel always resolvable in normal envs.
                }
                try {
                    $svc->setDocHealthService($app->make(EngineeringDocumentationHealthService::class));
                } catch (\Throwable $e) {
                    // Defensive: doc-health probe falls back to honest empty payload.
                }
                // A1 · Auto-trigger F4 rebalance sweep inside every reconciliation tick.
                try {
                    $svc->setAutoRebalanceService($app->make(AtlasSubsystemAutoRebalanceService::class));
                } catch (\Throwable $e) {
                    // Defensive: sweep is opt-in; missing service stays silent.
                }
            }
        });

        // Patamar 4 · AiWorker records every provider call outcome to the
        // Live Outcome Feedback ledger so ADML auto-deactivation sees real
        // online signal (not just offline benchmark battery).
        // Swarm Auto-Failover on AiWorker peeled to AtlasSwarmServiceProvider (full-pass).
        $this->app->resolving(AiWorker::class, function ($svc, $app) {
            if ($svc instanceof AiWorker) {
                try {
                    $svc->setLiveOutcomeFeedback($app->make(AtlasDecideLiveOutcomeFeedbackService::class));
                } catch (\Throwable $e) {
                    // Defensive — AiWorker stays functional without the ledger.
                }
                try {
                    $svc->setEliteExecutorKernel($app->make(EliteExecutorKernel::class));
                } catch (\Throwable $e) {
                    // Defensive — worker proceeds without elite kernel seam.
                }
            }
        });

        // Patamar 4 · TEOS-I4 pre-flight wiring no AiGatewayService.
        // Counterfactual tree projetada ANTES do job ser enqueued em decisões majores.
        $this->app->resolving(AiGatewayService::class, function ($svc, $app) {
            if ($svc instanceof AiGatewayService) {
                try {
                    $svc->setPreflight($app->make(AtlasGatewayPreflightService::class));
                } catch (\Throwable $e) {
                    // Defensive — gateway permanece funcional sem preflight.
                }
                // A2 · Cognitive Function Decomposer auto-wired into gateway.
                try {
                    $svc->setCognitiveFunctionDecomposer($app->make(AtlasCognitiveFunctionDecomposerService::class));
                } catch (\Throwable $e) {
                    // Defensive — decompose stays absent if service missing.
                }
            }
        });

        // Patamar 4 · Cartography Truth Guard.
        // Resolves with kernel + frontmatter parser; default singleton binding
        // is sufficient — no opt-in setter required.
        $this->app->singleton(CartographyTruthGuardService::class);

        // Patamar 4 · Scheduler OS heartbeat health service — singleton so the
        // CLI heartbeat, status command, and state aggregator share a single
        // instance (and any setLogPathForTesting override stays sticky).
        $this->app->singleton(AtlasSchedulerHealthService::class);

        // Patamar 4 · Auto-Rebalance — wire real diagnostic probes for kinds
        // that have a measurable source service. Unwired kinds emit honest
        // observed:null + probe_status=unwired. Operator can extend later.
        $this->app->resolving(AtlasSubsystemAutoRebalanceService::class, function ($svc, $app) {
            if (! $svc instanceof AtlasSubsystemAutoRebalanceService) {
                return;
            }
            // aemor_recompact_advice → AEMOR memory audit (blocked + watch counts).
            $svc->setProbe(
                AtlasSubsystemAutoRebalanceService::KIND_AEMOR_RECOMPACT,
                function () use ($app): array {
                    try {
                        /** @var AtlasAemorRuntimeService $aemor */
                        $aemor = $app->make(AtlasAemorRuntimeService::class);
                        $audit = $aemor->memoryAudit();
                        $total = (int) ($audit['summary']['total'] ?? 0);
                        $watch = (int) ($audit['summary']['watch'] ?? 0);
                        $blocked = (int) ($audit['summary']['blocked'] ?? 0);
                        $redundancy = $total > 0 ? round(($watch + $blocked) / max(1, $total), 4) : 0.0;

                        return [
                            'observed' => $redundancy,
                            'source' => 'AtlasAemorRuntimeService.memoryAudit()',
                            'note' => "candidates total={$total} watch={$watch} blocked={$blocked}",
                        ];
                    } catch (\Throwable $e) {
                        return [
                            'observed' => null,
                            'source' => 'AtlasAemorRuntimeService.memoryAudit()',
                            'note' => 'aemor unreachable: '.substr($e->getMessage(), 0, 90),
                        ];
                    }
                }
            );
            // mcp_pool_warmup_advice → manifest cardinality + tier breakdown.
            $svc->setProbe(
                AtlasSubsystemAutoRebalanceService::KIND_MCP_POOL_WARMUP,
                function () use ($app): array {
                    try {
                        /** @var AtlasMcpTierService $mcp */
                        $mcp = $app->make(AtlasMcpTierService::class);
                        $manifest = $mcp->tierManifest();
                        $total = (int) ($manifest['total_tools'] ?? 0);
                        $detail = isset($manifest['tiers'][3]) ? count($manifest['tiers'][3]) : 0;
                        // Cold proxy: fraction of detail-tier tools that need warmup.
                        $coldFraction = $total > 0 ? round($detail / max(1, $total), 4) : 0.0;

                        return [
                            'observed' => $coldFraction,
                            'source' => 'AtlasMcpTierService.tierManifest()',
                            'note' => "total_tools={$total} detail_tier={$detail}",
                        ];
                    } catch (\Throwable $e) {
                        return [
                            'observed' => null,
                            'source' => 'AtlasMcpTierService.tierManifest()',
                            'note' => 'mcp unreachable: '.substr($e->getMessage(), 0, 90),
                        ];
                    }
                }
            );
            // cache_compact and agrn_reindex remain honestly unwired — the
            // probes will report probe_status=unwired until the underlying
            // services expose canonical size / stale_fraction probes.
        });


        // Patamar 4 · Engineering Run Conductor — bind with ALL governance deps
        // explicitly. The constructor's nullable params stay optional for unit
        // tests, but the LIVE runtime (CLI + HTTP) must have the verify gate,
        // governed memory recall and the SDD scope gate wired — the container
        // would otherwise leave nullable-with-default params as null.
        $this->app->bind(AtlasEngineeringRunConductorService::class, function ($app) {
            return new AtlasEngineeringRunConductorService(
                $app->make(AtlasSwarmConductorService::class),
                $app->make(AtlasSwarmExecutorService::class),
                $app->make(AtlasSwarmProductionResolverService::class),
                $app->make(AtlasVerifiedExecutionRuntimeService::class),
                $app->make(AtlasCompoundingMemoryService::class),
                $app->make(SpecCritic::class),
                $app->make(AiContextPackBuilder::class),
                $app->make(AtlasCompoundingRuntimeService::class),
                $app->make(AtlasLiveCodeDeliveryService::class),
                $app->make(AtlasConductorRoutingMemory::class),
                $app->make(AtlasSwarmTopologySelector::class),
            );
        });
    }
}
