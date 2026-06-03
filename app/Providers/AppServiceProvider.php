<?php

namespace App\Providers;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiWorker;
use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use App\Services\Ai\AtlasDecide\AtlasSwarmAutoFailoverService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmParallelDispatchService;
use App\Services\Ai\AiContextPackBuilder;
use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\AtlasDecide\AtlasEngineeringRunConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\AtlasDecideService;
use App\Services\Ai\Compounding\AtlasCompoundingMemoryService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\Programming\Sdd\Compilers\SpecCritic;
use App\Services\Ai\VerifiedExecution\AtlasVerifiedExecutionRuntimeService;
use App\Services\Ai\Cartography\CartographyTruthGuardService;
use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use App\Services\Ai\Gateway\AtlasGatewayPreflightService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Mcp\AtlasMcpTierService;
use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisExecutionGatePort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\AwisHandoffPackPort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\ForgeLiveDecideReceiptPort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ForgeAuthority\ForgeProviderTopologyPort;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ForgeOwnerRuntimeDispatchBridge;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ForgeOwnerRuntimeDispatchPlanner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerQueueConsumptionGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerQueueReleaseGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerRuntimeExecutionAdapter;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerRuntimeResultProjector;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\OwnerSandboxRuntimeRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\RepairValidationRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ShellRepairValidationRunner;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\StewardshipOutcomeProjector;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipPriorityRanker;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOutcomeEvidenceBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultProjector;
use App\Services\Ai\Teos\AtlasTeosI3CounterfactualService;
use App\Services\Ai\Vox\Audit\VoxV3HardeningAuditService;
use App\Services\Ai\Vox\Confirmation\VoxConfirmationService;
use App\Services\Ai\Vox\Execution\VoxClaudeCliExecutor;
use App\Services\Ai\Vox\Execution\VoxCodexCliExecutor;
use App\Services\Ai\Vox\Execution\VoxExecutor;
use App\Services\Ai\Vox\Execution\VoxExecutorRouter;
use App\Services\Ai\Vox\Execution\VoxFilesystemEditExecutor;
use App\Services\Ai\Vox\Execution\VoxNoteCaptureExecutor;
use App\Services\Ai\Vox\Execution\VoxTerminalProposeExecutor;
use App\Services\Ai\Vox\Gate\VoxV3PromotionGateService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\Readiness\VoxReadinessService;
use App\Services\Ai\Vox\VoxActionOutcomeService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceHandoffPackService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SkillBundleStore::class);
        $this->app->bind(AreaFocusBranchSandboxMaterializer::class, AreaFocusBranchSandboxMaterializerService::class);
        $this->app->bind(
            \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CyclePhpTierRunner::class,
            \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ShellCyclePhpTierRunner::class,
        );
        $this->app->bind(StewardshipBranchMergeGovernor::class, StewardshipBranchMergeGovernorService::class);
        $this->app->bind(StewardshipPriorityRanker::class, StewardshipPriorityEngineService::class);
        $this->app->bind(StewardshipRuntimeResultProjector::class, StewardshipRuntimeResultBridgeService::class);

        // AP-786 full owner-runtime flow seams: bind each owner-flow port to its
        // canonical service so AP-786 composes the real AP-747 -> AP-750 chain
        // and never falls back to a direct provider driver.
        $this->app->bind(OwnerQueueReleaseGate::class, AreaFocusDevForgeReleaseService::class);
        $this->app->bind(StewardshipOutcomeProjector::class, StewardshipOutcomeEvidenceBridgeService::class);
        $this->app->bind(OwnerQueueConsumptionGate::class, AreaFocusOwnerQueueConsumptionGateService::class);
        $this->app->bind(OwnerRuntimeExecutionAdapter::class, StewardshipOwnerRuntimeExecutionAdapterService::class);
        $this->app->bind(OwnerSandboxRuntimeRunner::class, StewardshipOwnerSandboxRuntimeRunnerService::class);
        $this->app->bind(OwnerRuntimeResultProjector::class, StewardshipOwnerRuntimeResultBridgeService::class);
        $this->app->bind(Ap786OwnerFlowRunner::class, Ap786OwnerFlowExecutor::class);
        // AP-786 repair-agent pre-return validation gate: run the declared
        // validation command inside the AP-756 worktree before claiming a repair.
        $this->app->bind(RepairValidationRunner::class, ShellRepairValidationRunner::class);
        // AP-787 Forge owner runtime dispatch planner seam.
        $this->app->bind(ForgeOwnerRuntimeDispatchPlanner::class, ForgeOwnerRuntimeDispatchBridge::class);
        // AP-789 Forge live authority bootstrap ports -> REAL services only.
        $this->app->bind(ForgeProviderTopologyPort::class, AtlasForgeProviderTopologyService::class);
        $this->app->bind(ForgeLiveDecideReceiptPort::class, AtlasDecideService::class);
        $this->app->bind(AwisExecutionGatePort::class, AtlasWorkspaceIntelligenceExecutionGateService::class);
        $this->app->bind(AwisHandoffPackPort::class, AtlasWorkspaceHandoffPackService::class);

        // Atlas Evolution Loop execution abstraction: the loop depends on the
        // LoopExecutionDriver interface, never on a concrete engine or a named
        // provider. Default = the proven senior-loop (provider resolved via
        // AiProviderManager / providerLock, config-driven, swappable). Rebind this
        // one line to swap the whole engine; remove any provider and the loop runs.
        $this->app->bind(
            \App\Services\Ai\AutonomousEvolution\LoopExecutionDriver::class,
            \App\Services\Ai\AutonomousEvolution\SeniorLoopExecutionDriver::class,
        );

        // CRITIC GUARD: decorate the bound driver with a per-attempt wall-clock kill so a
        // single hung provider call can never wedge a 24h campaign. Names no provider; the
        // contract and provider-agnosticism are unchanged (it composes with any inner driver).
        $this->app->extend(
            \App\Services\Ai\AutonomousEvolution\LoopExecutionDriver::class,
            static fn (\App\Services\Ai\AutonomousEvolution\LoopExecutionDriver $inner): \App\Services\Ai\AutonomousEvolution\LoopExecutionDriver => new \App\Services\Ai\AutonomousEvolution\TimeBoundedLoopExecutionDriver(
                $inner,
                (int) config('atlas.loop.campaign.attempt_hard_seconds', 900),
            ),
        );

        // Vox V3 confirmation cache: pin the default cache repository so the
        // service stays on the same store across the (intent → execute)
        // round-trip. Laravel does not auto-resolve CacheRepository
        // otherwise, and a per-injection `new Repository()` would lose the
        // confirmation token between requests.
        $this->app->singleton(VoxConfirmationService::class, function ($app) {
            return new VoxConfirmationService($app->make(CacheRepository::class));
        });
        $this->app->bind(CacheRepository::class, fn () => Cache::store());

        // Vox readiness probe · the constructor declares `hardening` as
        // nullable with a `null` default for testability (so unit tests
        // can instantiate it without an audit service). Laravel's
        // container honors the default and would inject `null` in
        // production, leaving the doctor / readiness reporting
        // "unknown" for the hardening audit forever. Bind explicitly so
        // the production resolution always carries the audit service.
        $this->app->singleton(VoxReadinessService::class, function ($app) {
            return new VoxReadinessService(
                $app->make(VoxMetricsService::class),
                $app->make(VoxV3PromotionGateService::class),
                $app->make(VoxV3HardeningAuditService::class),
            );
        });

        // Atlas Dev runtime keeps a nullable constructor for isolated unit
        // tests, but the production/container-resolved runtime must carry
        // AWIS enforcement so mutative Dev execution is workspace-gated.
        $this->app->singleton(AtlasDevRuntimeService::class, function ($app) {
            return new AtlasDevRuntimeService(
                $app->make(AtlasWorkspaceIntelligenceExecutionGateService::class),
            );
        });

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
        $this->app->resolving(AiWorker::class, function ($svc, $app) {
            if ($svc instanceof AiWorker) {
                // A4 · Swarm Auto-Failover wired into AiWorker.
                try {
                    $svc->setSwarmAutoFailover($app->make(AtlasSwarmAutoFailoverService::class));
                } catch (\Throwable $e) {
                    // Defensive: missing failover never breaks worker.
                }
                try {
                    $svc->setLiveOutcomeFeedback($app->make(AtlasDecideLiveOutcomeFeedbackService::class));
                } catch (\Throwable $e) {
                    // Defensive — AiWorker stays functional without the ledger.
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

        // Patamar 4 · F2 Swarm Production Resolver — opt-in via flag. When
        // enabled the executor's resolver becomes a real AiProviderManager
        // bridge with per-provider circuit breaker. Default OFF so tests
        // and stubbed environments keep behaving as before.
        $this->app->singleton(AtlasSwarmProductionResolverService::class, function ($app) {
            $threshold = (int) (config('atlas.patamar4.swarm_circuit_threshold', AtlasSwarmProductionResolverService::DEFAULT_CIRCUIT_THRESHOLD));
            $cooldown = (int) (config('atlas.patamar4.swarm_circuit_cooldown_seconds', AtlasSwarmProductionResolverService::DEFAULT_CIRCUIT_COOLDOWN_SECONDS));

            return new AtlasSwarmProductionResolverService(
                $app->make(AiProviderManager::class),
                $threshold,
                $cooldown,
            );
        });
        // A5 · Default commandBuilder for AtlasSwarmParallelDispatchService.
        // Each arm spawns `php artisan atlas:swarm:execute-arm` carrying its
        // JSON payload; the subprocess delegates to AtlasSwarmProductionResolverService.
        $this->app->resolving(AtlasSwarmParallelDispatchService::class, function ($svc, $app) {
            if (! $svc instanceof AtlasSwarmParallelDispatchService) {
                return;
            }
            $svc->setCommandBuilder(function (array $arm, array $context): array {
                $php = trim((string) shell_exec('which php')) ?: PHP_BINARY;
                $artisan = base_path('artisan');

                return [
                    $php,
                    $artisan,
                    'atlas:swarm:execute-arm',
                    '--arm-json='.json_encode($arm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    '--context-json='.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            });
        });

        $this->app->resolving(AtlasSwarmExecutorService::class, function ($svc, $app) {
            if (! $svc instanceof AtlasSwarmExecutorService) {
                return;
            }
            if (! (bool) config('atlas.patamar4.swarm_production_resolver_enabled', false)) {
                return; // flag OFF — keep stub behaviour.
            }
            try {
                $resolver = $app->make(AtlasSwarmProductionResolverService::class);
                $svc->setResolver($resolver->asClosure());
            } catch (\Throwable $e) {
                // Defensive: failure to wire never breaks executor unit tests.
            }
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

        // Patamar 4 · AiProviderManager consults ADML before provider resolution.
        // Opt-in setter pattern: when consultation service is wired, callers
        // can request a learned route via getRecommended(). Existing get()
        // callers are untouched — zero break.
        $this->app->resolving(AiProviderManager::class, function ($svc, $app) {
            if ($svc instanceof AiProviderManager) {
                try {
                    $svc->setGatewayConsultation($app->make(AtlasDecideGatewayConsultationService::class));
                } catch (\Throwable $e) {
                    // Defensive: consultation service may not be resolvable
                    // in some test envs; manager stays in default mode.
                }
            }
        });

        // Vox V3 governed executors. Order is irrelevant — the router keys
        // them by `id()`. Each executor self-reports availability so the
        // controller can advertise an honest health state.
        $this->app->singleton(VoxExecutorRouter::class, function ($app) {
            /** @var list<VoxExecutor> $executors */
            $executors = [
                $app->make(VoxTerminalProposeExecutor::class),
                $app->make(VoxNoteCaptureExecutor::class),
                $app->make(VoxCodexCliExecutor::class),
                $app->make(VoxClaudeCliExecutor::class),
                $app->make(VoxFilesystemEditExecutor::class),
            ];

            return new VoxExecutorRouter(
                executors: $executors,
                outcomes: $app->make(VoxActionOutcomeService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();
    }
}
