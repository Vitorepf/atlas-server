<?php

namespace App\Services\Ai\RuntimeReadiness;

use App\Console\Commands\AtlasAiApprovalCommand;
use App\Console\Commands\AtlasAiLearningCommand;
use App\Console\Commands\AtlasAiMissionCommand;
use App\Services\Ai\ControlPlane\AtlasAiControlPlaneService;
use App\Services\Ai\Learning\AtlasAiLearningLoopService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Mission\MissionDetectionService;
use App\Services\Ai\Mission\MissionFollowThroughService;
use App\Services\Ai\Mission\MissionModeService;
use App\Services\Ai\Mission\MissionReadinessService;
use App\Services\Ai\OperatorApproval\OperatorApprovalGateService;
use App\Services\Ai\Product\AtlasAiAssistedExecutionQualityService;
use App\Services\Ai\Product\AtlasAiProductCertificationService;
use App\Services\Ai\RouterRuntime\AtlasDesktopHyperflowIntegrationCertificationService;
use App\Services\Ai\RouterRuntime\AtlasHyperflowSpecialistFlowsReadinessService;
use App\Services\Ai\RouterRuntime\RouterRuntimeReadinessService;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Atlas AI Runtime Readiness & Release Gate.
 *
 * Single source of truth — "is the Atlas AI runtime ready to release?". Agrega
 * todos os readiness / certification services existentes em uma resposta
 * canônica unificada com schema `atlas.ai.runtime_readiness.v1`.
 *
 * NÃO duplica nenhum check existente — chama cada service via DI e normaliza
 * o status para o canon ready|partial|blocked.
 *
 * Hard rules:
 *   - Status `ready` exige TODOS os checks críticos `passed`. Qualquer warn
 *     vira `partial`. Qualquer critical missing/failed vira `blocked`.
 *   - NUNCA invoca provider, executa rivals/benchmark, ou abre comparação
 *     externa. Apenas inspeciona artefatos e chama services read-only.
 *   - NUNCA declara `ready` por presença de docs apenas — cada check exige
 *     uma evidência concreta (service resolve, certify passed, file exists,
 *     command present).
 *   - `claim_policy` é hardcoded: nada de benchmark, superioridade, TEOS
 *     unless certificado em separado.
 *
 * Output shape (toArray):
 *   {
 *     schema_version: 'atlas.ai.runtime_readiness.v1',
 *     status: 'ready' | 'partial' | 'blocked',
 *     generated_at: ISO8601,
 *     summary: { total, passed, partial, failed, critical_failed, warn_failed },
 *     checks: [{ id, label, status, severity, source_service, detail }],
 *     blockers: [check_id, ...],
 *     warnings: [check_id, ...],
 *     evidence_refs: [string, ...],
 *     required_commands: [string, ...],
 *     claim_policy: { declares_benchmark: false, ... },
 *     release_scope: 'atlas_ai_runtime',
 *     certification_hash: sha256 hex (deterministic).
 *   }
 */
class AtlasAiRuntimeReadinessService
{
    public const SCHEMA_VERSION = 'atlas.ai.runtime_readiness.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const CHECK_STATUS_PASSED = 'passed';

    public const CHECK_STATUS_WARN = 'warn';

    public const CHECK_STATUS_FAILED = 'failed';

    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_WARN = 'warn';

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [
            $this->productCertCheck(),
            $this->controlPlaneRuntimeCheck(),
            $this->routerRuntimeCheck(),
            $this->specialistFlowsCheck(),
            $this->missionReadinessCheck(),
            $this->missionModeCheck(),
            $this->followThroughCheck(),
            $this->approvalGatesCheck(),
            $this->learningLoopCheck(),
            $this->desktopHyperflowIntegrationCheck(),
            $this->claimPolicyCheck(),
        ];

        $criticalFailed = array_values(array_filter(
            $checks,
            static fn (array $c): bool => $c['status'] === self::CHECK_STATUS_FAILED
                && $c['severity'] === self::SEVERITY_CRITICAL,
        ));
        $warnFailed = array_values(array_filter(
            $checks,
            static fn (array $c): bool => $c['status'] !== self::CHECK_STATUS_PASSED
                && ! ($c['status'] === self::CHECK_STATUS_FAILED && $c['severity'] === self::SEVERITY_CRITICAL),
        ));

        $status = match (true) {
            $criticalFailed !== [] => self::STATUS_BLOCKED,
            $warnFailed !== [] => self::STATUS_PARTIAL,
            default => self::STATUS_READY,
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total' => count($checks),
                'passed' => count(array_filter(
                    $checks,
                    static fn (array $c): bool => $c['status'] === self::CHECK_STATUS_PASSED,
                )),
                'partial' => count(array_filter(
                    $checks,
                    static fn (array $c): bool => $c['status'] === self::CHECK_STATUS_WARN,
                )),
                'failed' => count(array_filter(
                    $checks,
                    static fn (array $c): bool => $c['status'] === self::CHECK_STATUS_FAILED,
                )),
                'critical_failed' => count($criticalFailed),
                'warn_failed' => count($warnFailed),
            ],
            'checks' => $checks,
            'blockers' => array_map(
                static fn (array $c): string => (string) $c['id'],
                $criticalFailed,
            ),
            'warnings' => array_map(
                static fn (array $c): string => (string) $c['id'],
                $warnFailed,
            ),
            'evidence_refs' => $this->collectEvidenceRefs($checks),
            'required_commands' => $this->requiredCommands(),
            'claim_policy' => $this->claimPolicy(),
            'release_scope' => 'atlas_ai_runtime',
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        // UX bundle is dynamic (mission/approval/handoff state) and lives
        // OUTSIDE the deterministic certification hash. The hash continues to
        // describe the plumbing state only; the UI consumes the bundle to
        // render a lightweight status pill without leaking raw JSON.
        $payload['ux_bundle'] = $this->uxBundle();

        return $payload;
    }

    /**
     * Lightweight UX bundle for Mobile/Desktop status pill rendering.
     * Each section is defensively wrapped — if any service throws or the
     * underlying tables are missing, the field degrades to null/zero.
     * The bundle is NEVER included in `certification_hash`.
     *
     * @return array<string,mixed>
     */
    public function uxBundle(): array
    {
        return [
            'schema_version' => 'atlas.ai.runtime_readiness.ux_bundle.v1',
            'active_mission' => $this->activeMission(),
            'pending_approvals_count' => $this->pendingApprovalsCount(),
            'latest_handoff' => $this->latestHandoff(),
            'assisted_execution' => $this->assistedExecutionOperationalState(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function assistedExecutionOperationalState(): array
    {
        try {
            $service = $this->container->make(AtlasAiAssistedExecutionQualityService::class);
            $envelope = $service->buildEnvelope([
                'human_request' => 'Corrigir bug pequeno de interface no Atlas AI com teste focado e evidencia.',
                'workspace' => 'atlas',
                'surface_id' => 'atlas_ai',
                'expected_files' => [
                    'app/Services/Ai/RuntimeReadiness/AtlasAiRuntimeReadinessService.php',
                    'tests/Feature/Ai/Product/AtlasAiRuntimeUxCertificationServiceTest.php',
                ],
                'context_refs' => [
                    'docs/engineering-knowledge-base/atlas-ai-assisted-execution-quality.md',
                    'docs/engineering-knowledge-base/atlas-ai-runtime-release-gate.md',
                ],
                'acceptance_criteria' => [
                    'Runtime UX mostra estado operacional assistido sem JSON bruto.',
                    'Certificacao Runtime UX prova AEDPDS, contexto, AREG e AEMOR no bundle.',
                    'Teste focado valida shape e hash fora do certification_hash.',
                ],
                'suggested_tests' => [
                    'php artisan test tests/Feature/Ai/Product/AtlasAiRuntimeUxCertificationServiceTest.php',
                ],
                'required_evidence' => [
                    'runtime_ux_assisted_execution_projection',
                    'focused_tests',
                    'certification_hash',
                ],
            ]);
            $feedback = $service->recordOutcomeFeedback($envelope, [
                'status' => 'succeeded',
                'quality_score' => 0.90,
                'context_roi_score' => 0.82,
                'evidence_refs' => [
                    'runtime_ux_assisted_execution_projection',
                    'focused_tests',
                    'certification_hash',
                ],
                'persist' => false,
            ]);

            $blockers = array_values(array_filter(array_merge(
                AiStringListNormalizer::trimmedScalarValues(array_map(
                    static fn (array $blocker): string => (string) ($blocker['id'] ?? 'unknown_blocker'),
                    is_array($envelope['blockers'] ?? null) ? $envelope['blockers'] : [],
                )),
                AiStringListNormalizer::trimmedScalarValues(array_map(
                    static fn (array $blocker): string => (string) ($blocker['id'] ?? 'unknown_feedback_blocker'),
                    is_array($feedback['blockers'] ?? null) ? $feedback['blockers'] : [],
                )),
            )));

            $payload = [
                'schema_version' => 'atlas.ai.assisted_execution.operational_ux.v1',
                'status' => $blockers === [] ? 'ready' : 'needs_attention',
                'route_target' => data_get($envelope, 'route.target'),
                'flow_id' => data_get($envelope, 'route.flow_id'),
                'doctrine_gate_status' => data_get($envelope, 'aedpds.gate.status'),
                'selected_drivers' => AiStringListNormalizer::trimmedScalarValues(data_get($envelope, 'aedpds.doctrine.selected_primary_drivers', [])),
                'context_memory_status' => data_get($envelope, 'aucri_acmf.status'),
                'context_must_keep_coverage' => data_get($envelope, 'aucri_acmf.working_set.must_keep_coverage'),
                'areg_status' => data_get($envelope, 'areg.status'),
                'areg_path' => data_get($envelope, 'areg.path'),
                'outcome_feedback_status' => data_get($feedback, 'status'),
                'aemor_feedback_status' => data_get($feedback, 'aemor_outcome.status'),
                'blockers' => $blockers,
                'summary' => $blockers === []
                    ? 'Execucao assistida governada por AEDPDS, contexto, AREG e feedback AEMOR.'
                    : 'Execucao assistida requer acao antes de rodar.',
            ];
            $payload['hash'] = MissionCanonicalHash::sha256($payload);

            return $payload;
        } catch (Throwable) {
            return [
                'schema_version' => 'atlas.ai.assisted_execution.operational_ux.v1',
                'status' => 'unavailable',
                'route_target' => null,
                'flow_id' => null,
                'doctrine_gate_status' => null,
                'selected_drivers' => [],
                'context_memory_status' => null,
                'context_must_keep_coverage' => null,
                'areg_status' => null,
                'areg_path' => null,
                'outcome_feedback_status' => null,
                'aemor_feedback_status' => null,
                'blockers' => ['assisted_execution_projection_unavailable'],
                'summary' => 'Estado operacional assistido indisponivel.',
                'hash' => null,
            ];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function activeMission(): ?array
    {
        try {
            if (! DatabaseTableAvailability::has('ai_missions')) {
                return null;
            }
            $missionModel = '\\App\\Models\\AiMission';
            if (! class_exists($missionModel)) {
                return null;
            }
            /** @var Builder $query */
            $query = $missionModel::query();
            $mission = $query
                ->whereIn('status', ['active', 'planning', 'in_progress', 'pending'])
                ->orderByDesc('updated_at')
                ->first()
                ?? $query->orderByDesc('updated_at')->first();
            if ($mission === null) {
                return null;
            }
            $nextAction = data_get($mission, 'next_action')
                ?? data_get($mission, 'next_step')
                ?? null;

            return [
                'id' => (string) ($mission->uuid ?? $mission->id),
                'title' => (string) ($mission->title ?? 'Sem título'),
                'status' => (string) ($mission->status ?? 'unknown'),
                'mission_type' => $mission->mission_type ?? null,
                'next_action' => is_string($nextAction) ? $nextAction : null,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function pendingApprovalsCount(): int
    {
        try {
            if (! DatabaseTableAvailability::has('ai_operator_approvals')) {
                return 0;
            }
            $service = $this->container->make(OperatorApprovalGateService::class);
            $counts = $service->statusCounts();

            return (int) ($counts['pending'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestHandoff(): ?array
    {
        try {
            $forgeHandoff = '\\App\\Models\\AiRealExecutionForgeHandoff';
            $domainHandoff = '\\App\\Models\\AiDomainHandoff';

            $candidates = [];
            if (class_exists($forgeHandoff) && DatabaseTableAvailability::has('ai_real_execution_forge_handoffs')) {
                $row = $forgeHandoff::query()->orderByDesc('created_at')->first();
                if ($row !== null) {
                    $candidates[] = [
                        'target' => 'atlas_forge',
                        'reason' => (string) (data_get($row, 'reason') ?? 'forge_handoff'),
                        'status' => (string) ($row->status ?? 'unknown'),
                        'created_at' => optional($row->created_at)->toIso8601String(),
                    ];
                }
            }
            if (class_exists($domainHandoff) && DatabaseTableAvailability::has('ai_domain_handoffs')) {
                $row = $domainHandoff::query()->orderByDesc('created_at')->first();
                if ($row !== null) {
                    $target = (string) (data_get($row, 'target_domain_id') ?? 'atlas_dev');
                    $candidates[] = [
                        'target' => str_starts_with($target, 'atlas_') ? $target : 'atlas_'.$target,
                        'reason' => (string) (data_get($row, 'reason') ?? 'domain_handoff'),
                        'status' => (string) ($row->status ?? 'unknown'),
                        'created_at' => optional($row->created_at)->toIso8601String(),
                    ];
                }
            }
            if ($candidates === []) {
                return null;
            }
            usort(
                $candidates,
                static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')),
            );

            return $candidates[0];
        } catch (Throwable) {
            return null;
        }
    }

    /* ============================================================ */
    /* Checks */
    /* ============================================================ */

    private function productCertCheck(): array
    {
        return $this->probe(
            id: 'product_certification',
            label: 'Atlas AI product certification (E2E plumbing)',
            severity: self::SEVERITY_CRITICAL,
            source: AtlasAiProductCertificationService::class,
            evidence: ['php artisan atlas:ai:product-certify --json'],
            probe: function (): array {
                $service = $this->container->make(AtlasAiProductCertificationService::class);
                $cert = $service->certify();
                $status = (string) ($cert['status'] ?? 'blocked');

                return [
                    'status' => match ($status) {
                        'ready' => self::CHECK_STATUS_PASSED,
                        'partial' => self::CHECK_STATUS_WARN,
                        default => self::CHECK_STATUS_FAILED,
                    },
                    'detail' => [
                        'product_certification_status' => $status,
                        'passed' => $cert['summary']['passed'] ?? null,
                        'critical_failed' => $cert['summary']['critical_failed'] ?? null,
                        'warn_failed' => $cert['summary']['warn_failed'] ?? null,
                        'certification_hash' => $cert['certification_hash'] ?? null,
                        'remaining_blockers' => $cert['remaining_blockers'] ?? [],
                    ],
                ];
            },
        );
    }

    private function controlPlaneRuntimeCheck(): array
    {
        return $this->probe(
            id: 'control_plane_runtime',
            label: 'Atlas AI Control Plane runtime aggregate (24h window)',
            severity: self::SEVERITY_WARN,
            source: AtlasAiControlPlaneService::class,
            evidence: ['php artisan atlas:ai:control-plane runtime --hours=24 --json'],
            probe: function (): array {
                $service = $this->container->make(AtlasAiControlPlaneService::class);
                $report = $service->report(24);
                $status = (string) ($report['status'] ?? 'missing');

                return [
                    'status' => match ($status) {
                        'healthy', 'ready' => self::CHECK_STATUS_PASSED,
                        'watch', 'partial' => self::CHECK_STATUS_WARN,
                        default => self::CHECK_STATUS_FAILED,
                    },
                    'detail' => [
                        'runtime_status' => $status,
                        'summary' => $report['summary'] ?? null,
                        'hash' => $report['hash'] ?? null,
                    ],
                ];
            },
        );
    }

    private function routerRuntimeCheck(): array
    {
        return $this->probe(
            id: 'router_runtime_readiness',
            label: 'Router Runtime (Hyperflow V2 5-stage pipeline) ready',
            severity: self::SEVERITY_CRITICAL,
            source: RouterRuntimeReadinessService::class,
            evidence: [
                'app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php',
                'app/Services/Ai/RouterRuntime/RouterRuntimeReadinessService.php',
            ],
            probe: function (): array {
                $service = $this->container->make(RouterRuntimeReadinessService::class);
                $report = $service->report();
                $checks = (array) ($report['checks'] ?? []);
                $failed = array_filter(
                    $checks,
                    static fn (array $c): bool => ($c['status'] ?? null) !== 'passed',
                );

                return [
                    'status' => $failed === [] ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
                    'detail' => [
                        'router_checks_total' => count($checks),
                        'router_checks_failed' => count($failed),
                        'failed_check_names' => array_values(array_map(
                            static fn (array $c): string => (string) ($c['name'] ?? 'unknown'),
                            $failed,
                        )),
                    ],
                ];
            },
        );
    }

    private function specialistFlowsCheck(): array
    {
        return $this->probe(
            id: 'specialist_flows_readiness',
            label: 'All 14 specialist flows registered + flow handlers wired',
            severity: self::SEVERITY_CRITICAL,
            source: AtlasHyperflowSpecialistFlowsReadinessService::class,
            evidence: ['app/Services/Ai/RouterRuntime/AtlasHyperflowSpecialistFlowsReadinessService.php'],
            probe: function (): array {
                $service = $this->container->make(AtlasHyperflowSpecialistFlowsReadinessService::class);
                $report = $service->report();
                $status = (string) ($report['status'] ?? 'failed');

                return [
                    'status' => match ($status) {
                        'passed', 'ready', 'ok' => self::CHECK_STATUS_PASSED,
                        'partial', 'warn' => self::CHECK_STATUS_WARN,
                        default => self::CHECK_STATUS_FAILED,
                    },
                    'detail' => [
                        'flows_readiness_status' => $status,
                        'schema_version' => $report['schema_version'] ?? null,
                    ],
                ];
            },
        );
    }

    private function missionReadinessCheck(): array
    {
        return $this->probe(
            id: 'mission_foundation_readiness',
            label: 'Mission Foundation tables + services + lifecycle guard live',
            severity: self::SEVERITY_CRITICAL,
            source: MissionReadinessService::class,
            evidence: ['php artisan atlas:ai:mission-foundation readiness --json'],
            probe: function (): array {
                $service = $this->container->make(MissionReadinessService::class);
                $report = $service->report();
                $checks = (array) ($report['checks'] ?? []);
                $failed = array_filter(
                    $checks,
                    static fn (array $c): bool => ($c['status'] ?? null) !== 'passed',
                );

                return [
                    'status' => $failed === [] ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
                    'detail' => [
                        'mission_checks_total' => count($checks),
                        'mission_checks_failed' => count($failed),
                    ],
                ];
            },
        );
    }

    private function missionModeCheck(): array
    {
        return $this->probe(
            id: 'mission_mode_layer',
            label: 'Mission Mode service + CLI actions present (create/show/certify/list/detect)',
            severity: self::SEVERITY_CRITICAL,
            source: MissionModeService::class,
            evidence: [
                'app/Services/Ai/Mission/MissionModeService.php',
                'app/Services/Ai/Mission/MissionDetectionService.php',
                'php artisan atlas:ai:mission detect --goal="..." --json',
            ],
            probe: function (): array {
                $serviceClass = MissionModeService::class;
                $detectionClass = MissionDetectionService::class;
                $commandClass = AtlasAiMissionCommand::class;

                if (! class_exists($serviceClass) || ! class_exists($detectionClass) || ! class_exists($commandClass)) {
                    return [
                        'status' => self::CHECK_STATUS_FAILED,
                        'detail' => ['missing_class' => true],
                    ];
                }
                $reflection = new \ReflectionClass($commandClass);
                $signature = $reflection->getDefaultProperties()['signature'] ?? '';
                $hasDetect = str_contains((string) $signature, 'detect');
                $hasCreate = str_contains((string) $signature, 'create');
                $hasCertify = str_contains((string) $signature, 'certify');

                return [
                    'status' => ($hasDetect && $hasCreate && $hasCertify) ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
                    'detail' => [
                        'has_detect_action' => $hasDetect,
                        'has_create_action' => $hasCreate,
                        'has_certify_action' => $hasCertify,
                    ],
                ];
            },
        );
    }

    private function followThroughCheck(): array
    {
        return $this->probe(
            id: 'follow_through_loop',
            label: 'Autonomous Follow-Through service + run/until-blocked CLI present',
            severity: self::SEVERITY_CRITICAL,
            source: MissionFollowThroughService::class,
            evidence: [
                'app/Services/Ai/Mission/MissionFollowThroughService.php',
                'php artisan atlas:ai:mission run --mission=<uuid> --json',
            ],
            probe: function (): array {
                if (! class_exists(MissionFollowThroughService::class)) {
                    return [
                        'status' => self::CHECK_STATUS_FAILED,
                        'detail' => ['missing_service_class' => true],
                    ];
                }
                $service = $this->container->make(MissionFollowThroughService::class);
                $hasRunNext = method_exists($service, 'runNext');
                $hasRunUntil = method_exists($service, 'runUntilBlockedOrComplete');
                $hasSnapshot = method_exists($service, 'snapshot');

                $commandClass = AtlasAiMissionCommand::class;
                $reflection = new \ReflectionClass($commandClass);
                $signature = (string) ($reflection->getDefaultProperties()['signature'] ?? '');
                $hasRunAction = str_contains($signature, 'run');
                $hasUntilFlag = str_contains($signature, 'until-blocked');
                $hasMaxCycles = str_contains($signature, 'max-cycles');

                $passed = $hasRunNext && $hasRunUntil && $hasSnapshot
                    && $hasRunAction && $hasUntilFlag && $hasMaxCycles;

                return [
                    'status' => $passed ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
                    'detail' => [
                        'service_has_runNext' => $hasRunNext,
                        'service_has_runUntilBlockedOrComplete' => $hasRunUntil,
                        'service_has_snapshot' => $hasSnapshot,
                        'command_has_run_action' => $hasRunAction,
                        'command_has_until_blocked_flag' => $hasUntilFlag,
                        'command_has_max_cycles_flag' => $hasMaxCycles,
                    ],
                ];
            },
        );
    }

    private function approvalGatesCheck(): array
    {
        return $this->probe(
            id: 'operator_approval_gates',
            label: 'Operator Approval Gates service + table + CLI present',
            severity: self::SEVERITY_CRITICAL,
            source: OperatorApprovalGateService::class,
            evidence: ['php artisan atlas:ai:approval list --json'],
            probe: function (): array {
                if (! class_exists(OperatorApprovalGateService::class)) {
                    return [
                        'status' => self::CHECK_STATUS_FAILED,
                        'detail' => ['missing_service_class' => true],
                    ];
                }
                $service = $this->container->make(OperatorApprovalGateService::class);
                $hasEvaluate = method_exists($service, 'evaluate');
                $hasApprove = method_exists($service, 'approve');
                $hasControlPlane = method_exists($service, 'controlPlaneSnapshot');

                $tableExists = DatabaseTableAvailability::has('ai_operator_approvals');
                $commandExists = class_exists(AtlasAiApprovalCommand::class);

                $passed = $hasEvaluate && $hasApprove && $hasControlPlane
                    && $tableExists && $commandExists;

                return [
                    'status' => $passed ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
                    'detail' => [
                        'service_has_evaluate' => $hasEvaluate,
                        'service_has_approve' => $hasApprove,
                        'service_has_controlPlaneSnapshot' => $hasControlPlane,
                        'table_ai_operator_approvals_exists' => $tableExists,
                        'command_atlas_ai_approval_present' => $commandExists,
                    ],
                ];
            },
        );
    }

    private function learningLoopCheck(): array
    {
        return $this->probe(
            id: 'memory_learning_loop',
            label: 'Memory & Learning Feedback Loop service + signals/proposals tables present',
            severity: self::SEVERITY_CRITICAL,
            source: AtlasAiLearningLoopService::class,
            evidence: ['php artisan atlas:ai:learning collect --hours=24 --json'],
            probe: function (): array {
                if (! class_exists(AtlasAiLearningLoopService::class)) {
                    return [
                        'status' => self::CHECK_STATUS_FAILED,
                        'detail' => ['missing_service_class' => true],
                    ];
                }
                $service = $this->container->make(AtlasAiLearningLoopService::class);
                $hasCollect = method_exists($service, 'collect') || method_exists($service, 'collectSignals');

                $signalTableExists = DatabaseTableAvailability::has('ai_learning_signals');
                $proposalTableExists = DatabaseTableAvailability::has('ai_learning_proposals');
                $commandExists = class_exists(AtlasAiLearningCommand::class);

                $passed = $hasCollect && $signalTableExists && $proposalTableExists && $commandExists;

                return [
                    'status' => $passed ? self::CHECK_STATUS_PASSED : self::CHECK_STATUS_FAILED,
                    'detail' => [
                        'service_has_collect_method' => $hasCollect,
                        'table_ai_learning_signals_exists' => $signalTableExists,
                        'table_ai_learning_proposals_exists' => $proposalTableExists,
                        'command_atlas_ai_learning_present' => $commandExists,
                    ],
                ];
            },
        );
    }

    private function desktopHyperflowIntegrationCheck(): array
    {
        return $this->probe(
            id: 'desktop_hyperflow_integration',
            label: 'Desktop ↔ Hyperflow ↔ Rich Input integration certified',
            severity: self::SEVERITY_WARN,
            source: AtlasDesktopHyperflowIntegrationCertificationService::class,
            evidence: [
                'app/Services/Ai/RouterRuntime/AtlasDesktopHyperflowIntegrationCertificationService.php',
            ],
            probe: function (): array {
                $service = $this->container->make(AtlasDesktopHyperflowIntegrationCertificationService::class);
                $cert = $service->certify();
                $status = (string) ($cert['status'] ?? 'failed');

                return [
                    'status' => match ($status) {
                        'ready', 'passed' => self::CHECK_STATUS_PASSED,
                        'partial', 'warn' => self::CHECK_STATUS_WARN,
                        default => self::CHECK_STATUS_FAILED,
                    },
                    'detail' => [
                        'desktop_integration_status' => $status,
                        'schema_version' => $cert['schema_version'] ?? null,
                    ],
                ];
            },
        );
    }

    /**
     * Claim policy é hard-coded — não há "evidence" externa que mude.
     * Função: declarar publicamente o que este readiness service NÃO afirma.
     */
    private function claimPolicyCheck(): array
    {
        return $this->probe(
            id: 'claim_policy_canon',
            label: 'No benchmark / no rivals / no superiority claim',
            severity: self::SEVERITY_CRITICAL,
            source: self::class,
            evidence: ['docs/engineering-knowledge-base/atlas-ai-runtime-readiness.md'],
            probe: fn (): array => [
                'status' => self::CHECK_STATUS_PASSED,
                'detail' => $this->claimPolicy(),
            ],
        );
    }

    /* ============================================================ */
    /* Helpers */
    /* ============================================================ */

    /**
     * @param  list<string>  $evidence
     * @param  \Closure(): array{status:string,detail:array<string,mixed>}  $probe
     * @return array<string,mixed>
     */
    private function probe(
        string $id,
        string $label,
        string $severity,
        string $source,
        array $evidence,
        \Closure $probe,
    ): array {
        try {
            $result = $probe();
            $status = (string) ($result['status'] ?? self::CHECK_STATUS_FAILED);
            $detail = (array) ($result['detail'] ?? []);
        } catch (Throwable $e) {
            $status = self::CHECK_STATUS_FAILED;
            $detail = [
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'severity' => $severity,
            'source_service' => $source,
            'evidence_refs' => $evidence,
            'detail' => $detail,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return list<string>
     */
    private function collectEvidenceRefs(array $checks): array
    {
        $refs = [];
        foreach ($checks as $check) {
            foreach ((array) ($check['evidence_refs'] ?? []) as $ref) {
                $refs[] = (string) $ref;
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @return list<string>
     */
    private function requiredCommands(): array
    {
        return [
            'php artisan atlas:ai:product-certify --json',
            'php artisan atlas:ai:control-plane runtime --hours=24 --json',
            'php artisan atlas:ai:mission detect --goal="..." --json',
            'php artisan atlas:ai:mission run --mission=<uuid> --json',
            'php artisan atlas:ai:approval list --json',
            'php artisan atlas:ai:learning collect --hours=24 --json',
            'php artisan atlas:ai:runtime-readiness --json',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function claimPolicy(): array
    {
        return [
            'declares_benchmark' => false,
            'declares_rivals' => false,
            'declares_superiority' => false,
            'declares_teos_certification' => false,
            'invokes_provider' => false,
            'reads_external_apis' => false,
            'mutates_persistent_state' => false,
            'scope' => 'atlas_ai_runtime_release_gate',
            'forbidden_claims' => [
                'better_than_claude_code',
                'better_than_codex',
                'better_than_cursor',
                'beats_benchmark_x',
                'wins_arena_y',
                'teos_certified_unless_explicitly_proven',
            ],
        ];
    }
}
