<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionControlPlaneService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionFactoryService;
use App\Services\Ai\Mission\MissionLifecycleException;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mission\MissionReadinessService;
use App\Services\Ai\Mission\ObjectiveDecomposerService;
use App\Services\Ai\Mission\WorkOrderFactoryService;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

class AtlasAiMissionFoundationCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:mission-foundation
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, create, decompose, plan, evidence, certify, control-plane, smoke}
        {--prompt= : Raw prompt for create / smoke}
        {--mission= : Mission uuid for decompose, plan, evidence, certify, control-plane}
        {--type= : Evidence type for evidence action (doc, command, test, artifact, receipt, source, screenshot, diff, blocker, certification)}
        {--ref= : Evidence reference for evidence action}
        {--mission-type= : Optional mission_type override for create}
        {--autonomy-level= : Optional autonomy_level override for create}
        {--risk-level= : Optional risk_level override for create}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Kernel Mission Foundation: readiness, mission/objective/work_order lifecycle, evidence and certification.';

    public function handle(
        MissionReadinessService $readiness,
        MissionFactoryService $factory,
        ObjectiveDecomposerService $decomposer,
        WorkOrderFactoryService $workOrders,
        MissionLifecycleService $lifecycle,
        MissionEvidenceService $evidence,
        MissionCertificationService $certification,
        MissionControlPlaneService $controlPlane,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'create' => $this->renderCreate($factory),
                'decompose' => $this->renderDecompose($decomposer),
                'plan' => $this->renderPlan($workOrders),
                'evidence' => $this->renderEvidence($evidence),
                'certify' => $this->renderCertify($certification),
                'control-plane' => $this->renderControlPlane($controlPlane),
                'smoke' => $this->renderSmoke(
                    $readiness,
                    $factory,
                    $decomposer,
                    $workOrders,
                    $lifecycle,
                    $evidence,
                    $certification,
                    $controlPlane,
                ),
                default => $this->invalidAction($action),
            };
        } catch (MissionLifecycleException $e) {
            $payload = [
                'ok' => false,
                'error' => 'lifecycle_exception',
                'message' => $e->getMessage(),
            ];

            $this->line($this->encodeOrEmptyObject($payload));

            return self::FAILURE;
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
                'type' => $e::class,
            ];
            $this->line($this->encodeOrEmptyObject($payload));

            return self::FAILURE;
        }
    }

    private function renderReadiness(MissionReadinessService $readiness): int
    {
        $payload = $readiness->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', YesNo::trueFalse($payload['ok']));
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
            foreach ($payload['checks'] as $check) {
                $this->components->twoColumnDetail((string) $check['name'], (string) $check['status']);
            }
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderCreate(MissionFactoryService $factory): int
    {
        $prompt = $this->stringOption('prompt');
        if ($prompt === null) {
            return $this->failWith('create requires --prompt="..."');
        }

        $options = array_filter([
            'mission_type' => $this->stringOption('mission-type'),
            'autonomy_level' => $this->stringOption('autonomy-level'),
            'risk_level' => $this->stringOption('risk-level'),
        ]);

        $mission = $factory->create($prompt, $options);
        $payload = [
            'ok' => true,
            'action' => 'create',
            'mission' => $this->serializeMission($mission),
        ];
        $this->emit($payload, fn () => $this->printMissionSummary($mission));

        return self::SUCCESS;
    }

    private function renderDecompose(ObjectiveDecomposerService $decomposer): int
    {
        $mission = $this->resolveMissionFromOption();
        if ($mission === null) {
            return self::FAILURE;
        }
        $objectives = $decomposer->decompose($mission);
        $payload = [
            'ok' => true,
            'action' => 'decompose',
            'mission_id' => $mission->id,
            'mission_uuid' => $mission->uuid,
            'objectives' => $objectives->map(static fn ($o): array => [
                'id' => $o->id,
                'uuid' => $o->uuid,
                'title' => $o->title,
                'priority' => $o->priority,
                'success_criteria_count' => count((array) $o->success_criteria),
            ])->all(),
        ];
        $this->emit($payload, function () use ($objectives): void {
            $this->components->twoColumnDetail('objectives', (string) $objectives->count());
        });

        return self::SUCCESS;
    }

    private function renderPlan(WorkOrderFactoryService $workOrders): int
    {
        $mission = $this->resolveMissionFromOption();
        if ($mission === null) {
            return self::FAILURE;
        }
        $created = $workOrders->plan($mission);
        $payload = [
            'ok' => true,
            'action' => 'plan',
            'mission_id' => $mission->id,
            'mission_uuid' => $mission->uuid,
            'work_orders' => $created->map(static fn ($wo): array => [
                'id' => $wo->id,
                'uuid' => $wo->uuid,
                'objective_id' => $wo->objective_id,
                'status' => $wo->status,
                'receipt_hash' => $wo->receipt_hash,
            ])->all(),
        ];
        $this->emit($payload, function () use ($created): void {
            $this->components->twoColumnDetail('work_orders', (string) $created->count());
        });

        return self::SUCCESS;
    }

    private function renderEvidence(MissionEvidenceService $evidence): int
    {
        $mission = $this->resolveMissionFromOption();
        if ($mission === null) {
            return self::FAILURE;
        }

        $type = $this->stringOption('type');
        $ref = $this->stringOption('ref');
        if ($type === null || $ref === null) {
            return $this->failWith('evidence requires --type=<type> and --ref=<reference>');
        }

        $created = $evidence->attach($mission, [
            'evidence_type' => $type,
            'evidence_ref' => $ref,
        ]);

        $payload = [
            'ok' => true,
            'action' => 'evidence',
            'mission_id' => $mission->id,
            'mission_uuid' => $mission->uuid,
            'evidence' => [
                'id' => $created->id,
                'uuid' => $created->uuid,
                'evidence_type' => $created->evidence_type,
                'evidence_hash' => $created->evidence_hash,
            ],
        ];
        $this->emit($payload, function () use ($created): void {
            $this->components->twoColumnDetail('evidence_type', (string) $created->evidence_type);
            $this->components->twoColumnDetail('evidence_hash', (string) $created->evidence_hash);
        });

        return self::SUCCESS;
    }

    private function renderCertify(MissionCertificationService $certification): int
    {
        $mission = $this->resolveMissionFromOption();
        if ($mission === null) {
            return self::FAILURE;
        }
        $record = $certification->certify($mission);
        $payload = [
            'ok' => $record->status === MissionCertificationService::STATUS_PASSED,
            'action' => 'certify',
            'mission_id' => $mission->id,
            'mission_uuid' => $mission->uuid,
            'certification' => [
                'id' => $record->id,
                'uuid' => $record->uuid,
                'status' => $record->status,
                'certification_hash' => $record->certification_hash,
                'missing_requirements' => $record->missing_requirements,
            ],
        ];
        $this->emit($payload, function () use ($record): void {
            $this->components->twoColumnDetail('certification_status', (string) $record->status);
            $this->components->twoColumnDetail('certification_hash', (string) $record->certification_hash);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderControlPlane(MissionControlPlaneService $controlPlane): int
    {
        $mission = $this->resolveMissionFromOption();
        if ($mission === null) {
            return self::FAILURE;
        }
        $payload = $controlPlane->snapshot($mission);
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('mission_status', (string) $payload['mission']['status']);
            $this->components->twoColumnDetail('next_action', (string) ($payload['next_action'] ?? 'none'));
        });

        return self::SUCCESS;
    }

    private function renderSmoke(
        MissionReadinessService $readiness,
        MissionFactoryService $factory,
        ObjectiveDecomposerService $decomposer,
        WorkOrderFactoryService $workOrders,
        MissionLifecycleService $lifecycle,
        MissionEvidenceService $evidence,
        MissionCertificationService $certification,
        MissionControlPlaneService $controlPlane,
    ): int {
        $tableRepair = $readiness->ensureKernelTablesReady();
        if (! $tableRepair['ok']) {
            $payload = [
                'ok' => false,
                'action' => 'smoke',
                'error' => 'kernel_tables_unavailable',
                'table_repair' => $tableRepair,
            ];
            $this->line($this->encodeOrEmptyObject($payload));

            return self::FAILURE;
        }

        $prompt = $this->stringOption('prompt')
            ?? 'Atlas Kernel Mission Foundation smoke: validate end-to-end mission lifecycle and certification.';

        $mission = $factory->create($prompt, [
            'mission_type' => MissionFactoryService::TYPE_TASK,
            'autonomy_level' => MissionFactoryService::AUTONOMY_EXECUTE_WITH_APPROVAL,
            'risk_level' => MissionFactoryService::RISK_LOW,
        ]);
        $objectives = $decomposer->decompose($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, ['actor_type' => 'system']);
        $createdWorkOrders = $workOrders->plan($mission);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, ['actor_type' => 'system']);
        $evidenceRef = $evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_TEST,
            'evidence_ref' => 'mission-foundation:smoke:phpunit',
            'metadata' => ['suite' => 'MissionFoundationSmokeTest'],
            'work_order_id' => $createdWorkOrders->first()?->id,
        ]);
        $lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING, ['actor_type' => 'system']);
        $certificationRecord = $certification->certify($mission);
        $completedEvent = null;
        if ($certificationRecord->status === MissionCertificationService::STATUS_PASSED) {
            $completedEvent = $lifecycle->transition(
                $mission,
                MissionLifecycleService::STATUS_COMPLETED,
                ['actor_type' => 'system'],
            );
        }

        $snapshot = $controlPlane->snapshot($mission);

        $payload = [
            'ok' => $certificationRecord->status === MissionCertificationService::STATUS_PASSED
                && $mission->refresh()->status === MissionLifecycleService::STATUS_COMPLETED,
            'action' => 'smoke',
            'table_repair' => $tableRepair['repaired'] ? $tableRepair : null,
            'mission' => $this->serializeMission($mission),
            'objective_count' => $objectives->count(),
            'work_order_count' => $createdWorkOrders->count(),
            'evidence_ref_id' => $evidenceRef->id,
            'certification' => [
                'id' => $certificationRecord->id,
                'status' => $certificationRecord->status,
                'certification_hash' => $certificationRecord->certification_hash,
            ],
            'completed_event_id' => $completedEvent?->id,
            'snapshot' => $snapshot,
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('mission_uuid', (string) $payload['mission']['uuid']);
            $this->components->twoColumnDetail('mission_status', (string) $payload['mission']['status']);
            $this->components->twoColumnDetail('objectives', (string) $payload['objective_count']);
            $this->components->twoColumnDetail('work_orders', (string) $payload['work_order_count']);
            $this->components->twoColumnDetail('certification_status', (string) $payload['certification']['status']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveMissionFromOption(): ?AiMission
    {
        $uuid = $this->stringOption('mission');
        if ($uuid === null) {
            $this->failWith('missing --mission=<uuid>');

            return null;
        }
        $mission = AiMission::query()->where('uuid', $uuid)->orWhere('id', $uuid)->first();
        if (! $mission) {
            $this->failWith("mission not found for uuid [{$uuid}]");

            return null;
        }

        return $mission;
    }

    private function failWith(string $message): int
    {
        $payload = ['ok' => false, 'error' => 'invalid_arguments', 'message' => $message];
        $this->line($this->encodeOrEmptyObject($payload));

        return self::FAILURE;
    }

    private function invalidAction(string $action): int
    {
        return $this->failWith("invalid action [{$action}] for atlas:ai:mission-foundation");
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeMission(AiMission $mission): array
    {
        return [
            'id' => $mission->id,
            'uuid' => $mission->uuid,
            'title' => $mission->title,
            'mission_type' => $mission->mission_type,
            'status' => $mission->status,
            'autonomy_level' => $mission->autonomy_level,
            'risk_level' => $mission->risk_level,
            'certification_hash' => $mission->certification_hash,
            'evidence_pack_hash' => $mission->evidence_pack_hash,
            'completed_at' => optional($mission->completed_at)->toJSON(),
        ];
    }

    private function printMissionSummary(AiMission $mission): void
    {
        $this->components->twoColumnDetail('mission_uuid', (string) $mission->uuid);
        $this->components->twoColumnDetail('mission_type', (string) $mission->mission_type);
        $this->components->twoColumnDetail('status', (string) $mission->status);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

            return;
        }
        $human();
    }


    private function json(): bool
    {
        return (bool) $this->option('json');
    }

}
