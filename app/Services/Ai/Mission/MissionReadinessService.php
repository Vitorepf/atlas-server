<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiMissionCertification;
use App\Models\AiMissionEvent;
use App\Models\AiMissionEvidenceRef;
use App\Models\AiObjective;
use App\Models\AiWorkOrder;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;

class MissionReadinessService
{
    private const REQUIRED_TABLES = [
        'ai_missions',
        'ai_objectives',
        'ai_work_orders',
        'ai_mission_events',
        'ai_mission_evidence_refs',
        'ai_mission_certifications',
    ];

    private const REQUIRED_MODELS = [
        AiMission::class,
        AiObjective::class,
        AiWorkOrder::class,
        AiMissionEvent::class,
        AiMissionEvidenceRef::class,
        AiMissionCertification::class,
    ];

    private const REQUIRED_SERVICES = [
        MissionFactoryService::class,
        ObjectiveDecomposerService::class,
        WorkOrderFactoryService::class,
        MissionLifecycleService::class,
        MissionEvidenceService::class,
        MissionCertificationService::class,
        MissionControlPlaneService::class,
    ];

    private const REQUIRED_TEST_FILES = [
        'tests/Feature/Ai/Mission/MissionFoundationReadinessTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationSmokeTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationLifecycleGuardTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationObjectiveDecomposerTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationWorkOrderFactoryTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationEvidenceServiceTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationCertificationServiceTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationControlPlaneServiceTest.php',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $exists = DatabaseTableAvailability::has($table);
            $checks[] = [
                'name' => "table:{$table}",
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'exists' : 'missing - run php artisan migrate',
            ];
        }

        foreach (self::REQUIRED_MODELS as $model) {
            $exists = class_exists($model);
            $checks[] = [
                'name' => 'model:'.class_basename($model),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'class exists' : "missing class [{$model}]",
            ];
        }

        foreach (self::REQUIRED_SERVICES as $service) {
            $resolvable = false;
            $detail = 'not resolvable';
            try {
                $resolved = $this->container->make($service);
                $resolvable = $resolved instanceof $service;
                $detail = $resolvable ? 'resolved' : 'not an instance';
            } catch (\Throwable $e) {
                $detail = 'exception: '.$e->getMessage();
            }
            $checks[] = [
                'name' => 'service:'.class_basename($service),
                'status' => $resolvable ? 'passed' : 'failed',
                'detail' => $detail,
            ];
        }

        $checks[] = $this->checkLifecycleGuard();
        $checks[] = $this->checkCertificationGuard();

        foreach (self::REQUIRED_TEST_FILES as $relativePath) {
            $exists = file_exists(base_path($relativePath));
            $checks[] = [
                'name' => 'test:'.basename($relativePath),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'present' : "missing [{$relativePath}]",
            ];
        }

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => 'atlas.ai.mission.readiness.v1',
            'summary' => [
                'total' => count($checks),
                'passed' => $passed,
                'failed' => $failed,
            ],
            'checks' => $checks,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkLifecycleGuard(): array
    {
        $lifecycle = $this->container->make(MissionLifecycleService::class);
        $allowedFromDraft = $lifecycle->allowedNext(MissionLifecycleService::STATUS_DRAFT);
        $allowedFromBlocked = $lifecycle->allowedNext(MissionLifecycleService::STATUS_BLOCKED);

        $draftAllowsPlanned = in_array(MissionLifecycleService::STATUS_PLANNED, $allowedFromDraft, true);
        $blockedRejectsRunning = ! in_array(MissionLifecycleService::STATUS_RUNNING, $allowedFromBlocked, true);
        $passes = $draftAllowsPlanned && $blockedRejectsRunning;

        return [
            'name' => 'guard:lifecycle_transitions',
            'status' => $passes ? 'passed' : 'failed',
            'detail' => $passes
                ? 'draft->planned allowed; blocked->running forbidden'
                : 'lifecycle transition table is inconsistent with canon',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCertificationGuard(): array
    {
        $reflection = new \ReflectionClass(MissionLifecycleService::class);
        $hasGuard = $reflection->hasMethod('transition')
            && str_contains((string) file_get_contents((string) $reflection->getFileName()), 'guardCompletion');

        return [
            'name' => 'guard:completion_requires_certification',
            'status' => $hasGuard ? 'passed' : 'failed',
            'detail' => $hasGuard ? 'guardCompletion present' : 'guardCompletion missing from MissionLifecycleService',
        ];
    }
}
